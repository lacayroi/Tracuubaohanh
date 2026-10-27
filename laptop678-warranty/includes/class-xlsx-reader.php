<?php
defined( 'ABSPATH' ) || exit;

/**
 * Đọc file .xlsx không cần thư viện ngoài (chỉ dùng ZipArchive + XMLReader có sẵn trong PHP).
 * Đọc sheet đầu tiên, lấy giá trị đã tính của ô công thức.
 */
class L678W_Xlsx_Reader {

	/**
	 * @return array|WP_Error Mảng các dòng, mỗi dòng là mảng giá trị theo chỉ số cột (0 = cột A).
	 */
	public static function read( $path ) {
		if ( ! class_exists( 'ZipArchive' ) || ! class_exists( 'XMLReader' ) ) {
			return new WP_Error( 'l678w_xlsx', 'Máy chủ thiếu extension PHP zip/xml. Hãy dùng file .csv thay thế.' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'l678w_xlsx', 'Không mở được file Excel. File có thể bị hỏng hoặc không phải định dạng .xlsx.' );
		}

		$sheet_path = self::first_sheet_path( $zip );
		$sheet_xml  = $sheet_path ? $zip->getFromName( $sheet_path ) : false;
		if ( false === $sheet_xml ) {
			$zip->close();
			return new WP_Error( 'l678w_xlsx', 'Không tìm thấy sheet dữ liệu trong file Excel.' );
		}

		$shared = self::shared_strings( $zip );
		$zip->close();

		return self::parse_sheet( $sheet_xml, $shared );
	}

	private static function first_sheet_path( ZipArchive $zip ) {
		$workbook = $zip->getFromName( 'xl/workbook.xml' );
		$rels     = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );

		if ( $workbook && $rels ) {
			$rid = null;
			if ( preg_match( '/<(?:\w+:)?sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $m ) ) {
				$rid = $m[1];
			}
			if ( $rid && preg_match_all( '/<Relationship\b[^>]*>/', $rels, $all ) ) {
				foreach ( $all[0] as $tag ) {
					if ( false !== strpos( $tag, 'Id="' . $rid . '"' ) && preg_match( '/Target="([^"]+)"/', $tag, $t ) ) {
						$target = $t[1];
						return 0 === strpos( $target, '/' ) ? ltrim( $target, '/' ) : 'xl/' . $target;
					}
				}
			}
		}

		return false !== $zip->locateName( 'xl/worksheets/sheet1.xml' ) ? 'xl/worksheets/sheet1.xml' : null;
	}

	private static function shared_strings( ZipArchive $zip ) {
		$xml = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $xml ) {
			return array();
		}

		$strings = array();
		$reader  = new XMLReader();
		$reader->XML( $xml, null, LIBXML_NONET | LIBXML_COMPACT );
		// Di chuyển tới thẻ <si> đầu tiên rồi duyệt lần lượt các thẻ anh em.
		while ( $reader->read() && 'si' !== $reader->localName ) {
			continue;
		}
		while ( XMLReader::ELEMENT === $reader->nodeType && 'si' === $reader->localName ) {
			$node = $reader->expand();
			$text = '';
			if ( $node ) {
				foreach ( $node->getElementsByTagNameNS( '*', 't' ) as $t ) {
					// Bỏ qua phiên âm (rPh) – chỉ lấy nội dung chính.
					if ( 'rPh' !== $t->parentNode->localName ) {
						$text .= $t->textContent;
					}
				}
			}
			$strings[] = $text;
			$reader->next( 'si' );
		}
		$reader->close();
		return $strings;
	}

	private static function parse_sheet( $xml, array $shared ) {
		$rows   = array();
		$reader = new XMLReader();
		$reader->XML( $xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );

		$next_row = 1;
		while ( $reader->read() && 'row' !== $reader->localName ) {
			continue;
		}
		while ( XMLReader::ELEMENT === $reader->nodeType && 'row' === $reader->localName ) {

			$row_number = (int) $reader->getAttribute( 'r' );
			if ( $row_number <= 0 ) {
				$row_number = $next_row;
			}
			$next_row = $row_number + 1;

			$node  = $reader->expand();
			$cells = array();
			$col   = 0;
			if ( $node ) {
				foreach ( $node->childNodes as $c ) {
					if ( XML_ELEMENT_NODE !== $c->nodeType || 'c' !== $c->localName ) {
						continue;
					}
					$ref = $c->getAttribute( 'r' );
					if ( $ref ) {
						$col = self::column_index( $ref );
					}
					$cells[ $col ] = self::cell_value( $c, $shared );
					$col++;
				}
			}
			$reader->next( 'row' );

			if ( ! $cells ) {
				continue;
			}
			$max  = max( array_keys( $cells ) );
			$line = array_fill( 0, $max + 1, '' );
			foreach ( $cells as $i => $v ) {
				$line[ $i ] = $v;
			}
			$rows[ $row_number ] = $line;
		}
		$reader->close();
		return $rows;
	}

	private static function cell_value( DOMElement $c, array $shared ) {
		$type  = $c->getAttribute( 't' );
		$value = null;

		foreach ( $c->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				continue;
			}
			if ( 'v' === $child->localName ) {
				$value = $child->textContent;
			} elseif ( 'is' === $child->localName ) {
				$value = '';
				foreach ( $child->getElementsByTagNameNS( '*', 't' ) as $t ) {
					if ( 'rPh' !== $t->parentNode->localName ) {
						$value .= $t->textContent;
					}
				}
			}
		}

		if ( null === $value ) {
			return '';
		}
		switch ( $type ) {
			case 's':
				return isset( $shared[ (int) $value ] ) ? trim( $shared[ (int) $value ] ) : '';
			case 'e':
				return '';
			case 'b':
				return $value ? '1' : '0';
			default:
				return trim( $value );
		}
	}

	/**
	 * "C12" => 2
	 */
	private static function column_index( $ref ) {
		$letters = preg_replace( '/[^A-Z]/', '', strtoupper( $ref ) );
		$index   = 0;
		$len     = strlen( $letters );
		for ( $i = 0; $i < $len; $i++ ) {
			$index = $index * 26 + ( ord( $letters[ $i ] ) - 64 );
		}
		return max( 0, $index - 1 );
	}
}

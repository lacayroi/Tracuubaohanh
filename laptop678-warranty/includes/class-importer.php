<?php
defined( 'ABSPATH' ) || exit;

/**
 * Import dữ liệu từ .xlsx / .csv. Tự nhận diện cột theo tiêu đề nên dùng được cả file mẫu cũ
 * (Tên Sản phẩm, SKU, Ngày mua...) lẫn file mẫu mới (Tên máy, Cấu hình...).
 */
class L678W_Importer {

	/**
	 * Từ khoá nhận diện tiêu đề cột (đã bỏ dấu, viết thường). Khớp khi tiêu đề BẮT ĐẦU bằng từ khoá.
	 */
	public static function header_keywords() {
		return array(
			'customer_name'   => array( 'ten khach hang', 'ten khach', 'khach hang', 'ho va ten', 'ho ten', 'ten kh', 'customer' ),
			'phone'           => array( 'so dien thoai', 'dien thoai', 'sdt', 'so dt', 'phone', 'mobile' ),
			'address'         => array( 'dia chi', 'address' ),
			'product_name'    => array( 'ten may', 'ten san pham', 'san pham', 'model', 'product' ),
			'config'          => array( 'cau hinh', 'sku', 'config', 'thong so' ),
			'purchase_date'   => array( 'ngay mua', 'ngay ban', 'ngay xuat', 'purchase date', 'date' ),
			'warranty_months' => array( 'bao hanh thang', 'thoi gian bao hanh', 'han bao hanh', 'so thang bao hanh', 'warranty' ),
			'note'            => array( 'ghi chu', 'tinh trang', 'note' ),
		);
	}

	/**
	 * @param string $path Đường dẫn file tạm.
	 * @param string $ext  xlsx | csv.
	 * @param array  $opts mode: skip|all ; replace: bool.
	 * @return array|WP_Error
	 */
	public static function import( $path, $ext, array $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'mode'    => 'skip',
				'replace' => false,
			)
		);

		$rows = 'csv' === $ext ? self::read_csv( $path ) : L678W_Xlsx_Reader::read( $path );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! $rows ) {
			return new WP_Error( 'l678w_import', 'File không có dữ liệu.' );
		}

		list( $header_line, $map ) = self::detect_header( $rows );
		if ( ! $map ) {
			return new WP_Error( 'l678w_import', 'Không nhận diện được dòng tiêu đề. File cần có các cột: Tên khách hàng, Số điện thoại, Địa chỉ, Tên máy, Cấu hình, Ngày mua.' );
		}

		$stats = array(
			'total'      => 0,
			'inserted'   => 0,
			'duplicate'  => 0,
			'empty'      => 0,
			'no_phone'   => array(),
			'bad_date'   => array(),
			'mapped'     => $map,
		);

		if ( $opts['replace'] ) {
			L678W_DB::truncate();
		}
		$existing = ( 'skip' === $opts['mode'] && ! $opts['replace'] ) ? L678W_DB::existing_hashes() : array();

		$batch = array();
		foreach ( $rows as $line_number => $cells ) {
			if ( $line_number <= $header_line ) {
				continue;
			}

			$data = array();
			foreach ( $map as $field => $index ) {
				$data[ $field ] = isset( $cells[ $index ] ) ? $cells[ $index ] : '';
			}
			if ( '' === trim( implode( '', array_map( array( 'L678W_Helpers', 'scalar_to_string' ), $data ) ) ) ) {
				$stats['empty']++;
				continue;
			}
			$stats['total']++;

			$row = L678W_DB::prepare_row( $data, 'import' );
			if ( '' === $row['customer_name'] && '' === $row['phone'] && '' === $row['product_name'] ) {
				$stats['empty']++;
				$stats['total']--;
				continue;
			}

			// So với dữ liệu đã có trước khi import (đếm theo số lượng, vì khách có thể mua 2 máy giống nhau cùng ngày).
			if ( ! empty( $existing[ $row['row_hash'] ] ) ) {
				$existing[ $row['row_hash'] ]--;
				$stats['duplicate']++;
				continue;
			}

			if ( '' === $row['phone_index'] ) {
				$stats['no_phone'][] = $line_number;
			}
			if ( null === $row['purchase_date'] && '' !== trim( L678W_Helpers::scalar_to_string( $data['purchase_date'] ?? '' ) ) ) {
				$stats['bad_date'][] = $line_number;
			}

			$batch[] = $row;
			if ( count( $batch ) >= 1000 ) {
				$stats['inserted'] += L678W_DB::insert_many( $batch );
				$batch              = array();
			}
		}
		if ( $batch ) {
			$stats['inserted'] += L678W_DB::insert_many( $batch );
		}

		return $stats;
	}

	/**
	 * Tìm dòng tiêu đề trong 15 dòng đầu: dòng khớp nhiều cột nhất.
	 *
	 * @return array [số dòng tiêu đề, map field => chỉ số cột]
	 */
	public static function detect_header( array $rows ) {
		$best_line = 0;
		$best_map  = array();
		$checked   = 0;

		foreach ( $rows as $line_number => $cells ) {
			if ( ++$checked > 15 ) {
				break;
			}
			$map = self::map_headers( $cells );
			if ( count( $map ) > count( $best_map ) ) {
				$best_map  = $map;
				$best_line = $line_number;
			}
		}

		$usable = count( $best_map ) >= 2 && ( isset( $best_map['phone'] ) || isset( $best_map['customer_name'] ) );
		return $usable ? array( $best_line, $best_map ) : array( 0, array() );
	}

	public static function map_headers( array $cells ) {
		$keywords = self::header_keywords();
		$map      = array();

		foreach ( $cells as $index => $title ) {
			$normalized = L678W_Helpers::normalize_header( $title );
			if ( '' === $normalized ) {
				continue;
			}
			foreach ( $keywords as $field => $words ) {
				if ( isset( $map[ $field ] ) ) {
					continue;
				}
				foreach ( $words as $word ) {
					if ( $normalized === $word || 0 === strpos( $normalized, $word . ' ' ) ) {
						$map[ $field ] = $index;
						continue 3;
					}
				}
			}
		}
		return $map;
	}

	/**
	 * Đọc CSV: tự nhận dấu phân cách (, ; tab) và mã hoá UTF-8 (có/không BOM).
	 */
	public static function read_csv( $path ) {
		$content = file_get_contents( $path );
		if ( false === $content ) {
			return new WP_Error( 'l678w_import', 'Không đọc được file CSV.' );
		}
		if ( 0 === strpos( $content, "\xEF\xBB\xBF" ) ) {
			$content = substr( $content, 3 );
		} elseif ( "\xFF\xFE" === substr( $content, 0, 2 ) && function_exists( 'mb_convert_encoding' ) ) {
			$content = mb_convert_encoding( substr( $content, 2 ), 'UTF-8', 'UTF-16LE' );
		}
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) && function_exists( 'iconv' ) ) {
			$converted = @iconv( 'CP1258', 'UTF-8//IGNORE', $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false !== $converted ) {
				$content = $converted;
			}
		}

		$first_line = strtok( $content, "\n" );
		$delimiter  = ',';
		$best       = 0;
		foreach ( array( ',', ';', "\t" ) as $candidate ) {
			$n = substr_count( (string) $first_line, $candidate );
			if ( $n > $best ) {
				$best      = $n;
				$delimiter = $candidate;
			}
		}

		$handle = fopen( 'php://temp', 'r+' );
		fwrite( $handle, $content );
		rewind( $handle );

		$rows = array();
		$line = 0;
		while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) {
			$line++;
			if ( array( null ) === $cells ) {
				continue;
			}
			$rows[ $line ] = array_map( 'trim', $cells );
		}
		fclose( $handle );
		return $rows;
	}

	/**
	 * Tiêu đề cột cho file mẫu / file export (import lại được).
	 */
	public static function template_headers() {
		return array( 'Tên khách hàng', 'Số điện thoại', 'Địa chỉ', 'Tên máy', 'Cấu hình', 'Ngày mua', 'Bảo hành (tháng)', 'Ghi chú' );
	}
}

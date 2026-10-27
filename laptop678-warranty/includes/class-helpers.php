<?php
defined( 'ABSPATH' ) || exit;

/**
 * Các hàm tiện ích: chuẩn hoá số điện thoại, ngày tháng, tiêu đề cột, cài đặt.
 */
class L678W_Helpers {

	/**
	 * Danh sách trường dữ liệu và nhãn hiển thị.
	 */
	public static function fields() {
		return array(
			'customer_name'   => 'Tên khách hàng',
			'phone'           => 'Số điện thoại',
			'address'         => 'Địa chỉ',
			'product_name'    => 'Tên máy',
			'config'          => 'Cấu hình',
			'purchase_date'   => 'Ngày mua',
			'warranty_months' => 'Bảo hành (tháng)',
			'note'            => 'Ghi chú / Tình trạng',
		);
	}

	public static function default_settings() {
		return array(
			'warranty_months' => 12,
			'address_mode'    => 'full', // full | partial | hide
			'show_warranty'   => 1,
			'title'           => 'Tra cứu thông tin bảo hành',
			'intro'           => '',
			'not_found'       => 'Không tìm thấy thông tin bảo hành cho số điện thoại này. Vui lòng liên hệ Laptop678 để được hỗ trợ.',
			'delete_on_uninstall' => 0,
		);
	}

	public static function settings() {
		$saved = get_option( 'l678w_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
	}

	/**
	 * Tách tất cả số điện thoại trong một chuỗi (VD: "0912 345 678 hoặc 0987.654.321").
	 * Trả về mảng số đã chuẩn hoá dạng 0xxxxxxxxx.
	 */
	public static function extract_phones( $raw ) {
		$raw = self::scalar_to_string( $raw );
		if ( '' === $raw ) {
			return array();
		}
		$raw    = preg_replace( '/\+\s*84/', '0', $raw );
		$groups = preg_split( '/\D+/', $raw, -1, PREG_SPLIT_NO_EMPTY );

		// Ghép các nhóm số bị ngắt bởi khoảng trắng/dấu chấm thành một số hoàn chỉnh.
		$numbers = array();
		$buffer  = '';
		foreach ( $groups as $group ) {
			if ( '' === $buffer ) {
				$buffer = $group;
			} elseif ( strlen( $buffer ) < 9 && strlen( $buffer . $group ) <= 11 ) {
				$buffer .= $group;
			} else {
				$numbers[] = $buffer;
				$buffer    = $group;
			}
		}
		if ( '' !== $buffer ) {
			$numbers[] = $buffer;
		}

		$phones = array();
		foreach ( $numbers as $number ) {
			if ( 11 === strlen( $number ) && 0 === strpos( $number, '84' ) ) {
				$number = '0' . substr( $number, 2 );
			}
			// Excel hay làm mất số 0 ở đầu.
			if ( 9 === strlen( $number ) && '0' !== $number[0] ) {
				$number = '0' . $number;
			}
			if ( strlen( $number ) >= 10 && strlen( $number ) <= 11 && '0' === $number[0] ) {
				$phones[] = $number;
			}
		}
		return array_values( array_unique( $phones ) );
	}

	/**
	 * Chuỗi index dùng để tìm kiếm: "|0912345678|0987654321|".
	 */
	public static function phone_index( $raw ) {
		$phones = self::extract_phones( $raw );
		return $phones ? '|' . implode( '|', $phones ) . '|' : '';
	}

	/**
	 * Chuyển giá trị ngày (dd/mm/yyyy, yyyy-mm-dd, số serial Excel) sang Y-m-d.
	 * Trả về '' nếu không đọc được.
	 */
	public static function parse_date( $value ) {
		$value = trim( self::scalar_to_string( $value ) );
		if ( '' === $value ) {
			return '';
		}

		// Số serial của Excel (VD: 45000 = 15/03/2023).
		if ( is_numeric( $value ) ) {
			$serial = (float) $value;
			if ( $serial > 20000 && $serial < 80000 ) {
				$date = new DateTime( '1899-12-30', new DateTimeZone( 'UTC' ) );
				$date->modify( '+' . (int) floor( $serial ) . ' days' );
				return $date->format( 'Y-m-d' );
			}
			return '';
		}

		// Bỏ phần giờ nếu có.
		$value = preg_replace( '/\s+\d{1,2}:\d{2}(:\d{2})?.*$/', '', $value );

		if ( preg_match( '/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $value, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2}|\d{4})$/', $value, $m ) ) {
			list( , $d, $mo, $y ) = $m;
			if ( strlen( $y ) === 2 ) {
				$y = '20' . $y;
			}
		} else {
			return '';
		}

		$y  = (int) $y;
		$mo = (int) $mo;
		$d  = (int) $d;
		if ( ! checkdate( $mo, $d, $y ) || $y < 1990 || $y > 2100 ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
	}

	public static function format_date( $ymd ) {
		if ( empty( $ymd ) || '0000-00-00' === $ymd ) {
			return '';
		}
		$parts = explode( '-', substr( $ymd, 0, 10 ) );
		return count( $parts ) === 3 ? $parts[2] . '/' . $parts[1] . '/' . $parts[0] : $ymd;
	}

	/**
	 * Bỏ dấu tiếng Việt, viết thường, gộp khoảng trắng – dùng để nhận diện tiêu đề cột.
	 */
	public static function normalize_header( $text ) {
		$text = self::scalar_to_string( $text );
		$text = str_replace( array( 'đ', 'Đ' ), 'd', $text );
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( $text );
	}

	/**
	 * Giá trị ô Excel có thể là số dạng 8.26258159E8 – đổi về chuỗi số đầy đủ.
	 */
	public static function scalar_to_string( $value ) {
		if ( null === $value || is_array( $value ) ) {
			return '';
		}
		if ( is_float( $value ) || ( is_string( $value ) && preg_match( '/^\d+(\.\d+)?E\+?\d+$/i', $value ) ) ) {
			return number_format( (float) $value, 0, '', '' );
		}
		return trim( (string) $value );
	}

	/**
	 * Ẩn bớt địa chỉ: chỉ giữ 2 phần cuối (Quận/Huyện, Tỉnh/Thành).
	 */
	public static function mask_address( $address ) {
		$parts = array_values( array_filter( array_map( 'trim', preg_split( '/[,\-]/', (string) $address ) ) ) );
		if ( count( $parts ) <= 2 ) {
			return '***';
		}
		return '***, ' . implode( ', ', array_slice( $parts, -2 ) );
	}

	/**
	 * Số tháng bảo hành áp dụng cho 1 bản ghi (riêng hoặc mặc định).
	 */
	public static function warranty_months_for( $row ) {
		if ( isset( $row->warranty_months ) && null !== $row->warranty_months && '' !== $row->warranty_months ) {
			return (int) $row->warranty_months;
		}
		$settings = self::settings();
		return (int) $settings['warranty_months'];
	}

	/**
	 * Ngày hết hạn bảo hành (Y-m-d) hoặc '' nếu không xác định.
	 */
	public static function warranty_end( $row ) {
		$months = self::warranty_months_for( $row );
		if ( empty( $row->purchase_date ) || $months <= 0 ) {
			return '';
		}
		$date = DateTime::createFromFormat( '!Y-m-d', substr( $row->purchase_date, 0, 10 ) );
		if ( ! $date ) {
			return '';
		}
		$date->modify( '+' . $months . ' months' );
		return $date->format( 'Y-m-d' );
	}
}

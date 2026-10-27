<?php
defined( 'ABSPATH' ) || exit;

/**
 * Lưu dữ liệu trong bảng riêng (nhanh hơn nhiều so với post + postmeta khi có hàng chục nghìn đơn).
 */
class L678W_DB {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'l678_warranty';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			customer_name varchar(191) NOT NULL DEFAULT '',
			phone varchar(100) NOT NULL DEFAULT '',
			phone_index varchar(191) NOT NULL DEFAULT '',
			address text NULL,
			product_name varchar(255) NOT NULL DEFAULT '',
			config text NULL,
			purchase_date date NULL DEFAULT NULL,
			warranty_months smallint(5) unsigned NULL DEFAULT NULL,
			note text NULL,
			row_hash char(32) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT 'manual',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY phone_index (phone_index),
			KEY row_hash (row_hash),
			KEY purchase_date (purchase_date)
			) {$charset};"
		);

		$previous = get_option( 'l678w_db_version' );
		if ( $previous && version_compare( $previous, '1.0.1', '<' ) ) {
			self::reindex_phones();
		}

		update_option( 'l678w_db_version', L678W_DB_VERSION );
		if ( false === get_option( 'l678w_settings' ) ) {
			add_option( 'l678w_settings', L678W_Helpers::default_settings(), '', 'no' );
		}
	}

	/**
	 * Tính lại chỉ mục SĐT cho dữ liệu cũ sau khi đổi cách chuẩn hoá số điện thoại.
	 */
	public static function reindex_phones() {
		global $wpdb;
		$table = self::table();
		$last  = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, customer_name, phone, phone_index, product_name, purchase_date FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT 1000", $last ) );
			foreach ( $rows as $row ) {
				$last  = (int) $row->id;
				$index = self::clip( L678W_Helpers::phone_index( $row->phone ), 191 );
				if ( $index === $row->phone_index ) {
					continue;
				}
				$data = array(
					'customer_name' => $row->customer_name,
					'phone_index'   => $index,
					'product_name'  => $row->product_name,
					'purchase_date' => $row->purchase_date,
				);
				$wpdb->update(
					$table,
					array(
						'phone_index' => $index,
						'row_hash'    => self::hash( $data ),
					),
					array( 'id' => $last )
				);
			}
		} while ( count( $rows ) === 1000 );
	}

	/**
	 * Chuẩn hoá dữ liệu đầu vào thành 1 dòng sẵn sàng ghi DB.
	 */
	public static function prepare_row( array $data, $source = 'manual' ) {
		$months = isset( $data['warranty_months'] ) ? trim( (string) $data['warranty_months'] ) : '';
		$row    = array(
			'customer_name'   => self::clip( sanitize_text_field( L678W_Helpers::scalar_to_string( $data['customer_name'] ?? '' ) ), 191 ),
			'phone'           => self::clip( sanitize_text_field( L678W_Helpers::scalar_to_string( $data['phone'] ?? '' ) ), 100 ),
			'address'         => sanitize_textarea_field( L678W_Helpers::scalar_to_string( $data['address'] ?? '' ) ),
			'product_name'    => self::clip( sanitize_text_field( L678W_Helpers::scalar_to_string( $data['product_name'] ?? '' ) ), 255 ),
			'config'          => sanitize_textarea_field( L678W_Helpers::scalar_to_string( $data['config'] ?? '' ) ),
			'purchase_date'   => L678W_Helpers::parse_date( $data['purchase_date'] ?? '' ),
			'warranty_months' => is_numeric( $months ) ? max( 0, min( 1200, (int) $months ) ) : null,
			'note'            => sanitize_textarea_field( L678W_Helpers::scalar_to_string( $data['note'] ?? '' ) ),
		);

		$row['phone_index'] = self::clip( L678W_Helpers::phone_index( $row['phone'] ), 191 );
		$row['row_hash']    = self::hash( $row );
		$row['source']      = $source;
		if ( '' === $row['purchase_date'] ) {
			$row['purchase_date'] = null;
		}
		return $row;
	}

	public static function hash( array $row ) {
		$lower = function ( $s ) {
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $s ), 'UTF-8' ) : strtolower( trim( (string) $s ) );
		};
		return md5( $row['phone_index'] . '#' . $lower( $row['customer_name'] ) . '#' . $lower( $row['product_name'] ) . '#' . (string) $row['purchase_date'] );
	}

	private static function clip( $value, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length, 'UTF-8' ) : substr( $value, 0, $length );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	public static function insert( array $row ) {
		global $wpdb;
		$now               = current_time( 'mysql' );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		$row['created_by'] = get_current_user_id();
		$ok                = $wpdb->insert( self::table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update( $id, array $row ) {
		global $wpdb;
		unset( $row['source'] );
		$row['updated_at'] = current_time( 'mysql' );
		return false !== $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	public static function delete( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE id IN (' . implode( ',', $ids ) . ')' );
	}

	public static function truncate() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . self::table() );
	}

	/**
	 * Ghi nhiều dòng bằng câu INSERT gộp – import vài nghìn dòng chỉ mất vài giây.
	 */
	public static function insert_many( array $rows, $chunk = 300 ) {
		global $wpdb;
		$inserted = 0;
		$now      = current_time( 'mysql' );
		$user     = get_current_user_id();
		$columns  = array( 'customer_name', 'phone', 'phone_index', 'address', 'product_name', 'config', 'purchase_date', 'warranty_months', 'note', 'row_hash', 'source', 'created_by', 'created_at', 'updated_at' );

		foreach ( array_chunk( $rows, $chunk ) as $batch ) {
			$values = array();
			foreach ( $batch as $row ) {
				$row['created_by'] = $user;
				$row['created_at'] = $now;
				$row['updated_at'] = $now;

				$parts = array();
				foreach ( $columns as $column ) {
					if ( null === $row[ $column ] ) {
						$parts[] = 'NULL';
					} elseif ( 'warranty_months' === $column || 'created_by' === $column ) {
						$parts[] = $wpdb->prepare( '%d', $row[ $column ] );
					} else {
						$parts[] = $wpdb->prepare( '%s', $row[ $column ] );
					}
				}
				$values[] = '(' . implode( ',', $parts ) . ')';
			}
			$result = $wpdb->query( 'INSERT INTO ' . self::table() . ' (' . implode( ',', $columns ) . ') VALUES ' . implode( ',', $values ) );
			if ( $result ) {
				$inserted += (int) $result;
			}
		}
		return $inserted;
	}

	public static function existing_hashes() {
		global $wpdb;
		$counts = array();
		foreach ( $wpdb->get_col( 'SELECT row_hash FROM ' . self::table() ) as $hash ) {
			$counts[ $hash ] = isset( $counts[ $hash ] ) ? $counts[ $hash ] + 1 : 1;
		}
		return $counts;
	}

	/**
	 * Tra cứu chính xác theo số điện thoại (dùng cho trang tra cứu công khai).
	 */
	public static function find_by_phone( $phone, $limit = 50 ) {
		global $wpdb;
		$phones = L678W_Helpers::extract_phones( $phone );
		if ( ! $phones ) {
			return array();
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE phone_index LIKE %s ORDER BY purchase_date DESC, id DESC LIMIT %d',
				'%|' . $wpdb->esc_like( $phones[0] ) . '|%',
				$limit
			)
		);
	}

	/**
	 * Danh sách trong trang quản trị.
	 */
	public static function query( array $args ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'from'     => '',
				'to'       => '',
				'orderby'  => 'purchase_date',
				'order'    => 'DESC',
				'per_page' => 30,
				'page'     => 1,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$digits = preg_replace( '/\D+/', '', $args['search'] );
			$sub    = array( 'customer_name LIKE %s', 'product_name LIKE %s', 'config LIKE %s', 'address LIKE %s' );
			array_push( $params, $like, $like, $like, $like );
			if ( strlen( $digits ) >= 3 ) {
				$sub[]    = 'phone_index LIKE %s';
				$params[] = '%' . $wpdb->esc_like( ltrim( $digits, '0' ) ) . '%';
			}
			$where[] = '(' . implode( ' OR ', $sub ) . ')';
		}
		if ( $args['from'] ) {
			$where[]  = 'purchase_date >= %s';
			$params[] = $args['from'];
		}
		if ( $args['to'] ) {
			$where[]  = 'purchase_date <= %s';
			$params[] = $args['to'];
		}

		$allowed = array( 'id', 'customer_name', 'product_name', 'purchase_date', 'created_at' );
		$orderby = in_array( $args['orderby'], $allowed, true ) ? $args['orderby'] : 'purchase_date';
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );
		$count_sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$sql      = 'SELECT * FROM ' . self::table() . " WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
		$items    = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( $per_page, $offset ) ) ) );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}
}

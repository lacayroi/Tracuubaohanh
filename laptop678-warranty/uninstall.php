<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$l678w_settings = get_option( 'l678w_settings', array() );

// Mặc định giữ lại dữ liệu khách hàng; chỉ xoá khi đã bật trong Cài đặt.
if ( ! empty( $l678w_settings['delete_on_uninstall'] ) ) {
	global $wpdb;
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'l678_warranty' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	delete_option( 'l678w_settings' );
	delete_option( 'l678w_db_version' );
}

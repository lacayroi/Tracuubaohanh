<?php
/**
 * Plugin Name:       Laptop678 Tra Cứu Bảo Hành
 * Description:       Tra cứu bảo hành theo số điện thoại. Nhập dữ liệu bằng file Excel (.xlsx/.csv) hoặc thêm thủ công. Tối ưu cho theme Flatsome (có element UX Builder).
 * Version:           1.0.1
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Laptop678
 * Text Domain:       l678-warranty
 */

defined( 'ABSPATH' ) || exit;

define( 'L678W_VERSION', '1.0.1' );
define( 'L678W_DB_VERSION', '1.0.1' );
define( 'L678W_FILE', __FILE__ );
define( 'L678W_DIR', plugin_dir_path( __FILE__ ) );
define( 'L678W_URL', plugin_dir_url( __FILE__ ) );

require_once L678W_DIR . 'includes/class-helpers.php';
require_once L678W_DIR . 'includes/class-db.php';
require_once L678W_DIR . 'includes/class-xlsx-reader.php';
require_once L678W_DIR . 'includes/class-importer.php';
require_once L678W_DIR . 'includes/class-frontend.php';

if ( is_admin() ) {
	require_once L678W_DIR . 'includes/class-list-table.php';
	require_once L678W_DIR . 'includes/class-admin.php';
}

register_activation_hook( __FILE__, array( 'L678W_DB', 'install' ) );

add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'l678w_db_version' ) !== L678W_DB_VERSION ) {
			L678W_DB::install();
		}

		L678W_Frontend::init();

		if ( is_admin() ) {
			L678W_Admin::init();
		}
	}
);

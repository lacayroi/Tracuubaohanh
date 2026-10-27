<?php
defined( 'ABSPATH' ) || exit;

/**
 * Trang quản trị: danh sách, thêm/sửa thủ công, import Excel, export, cài đặt.
 */
class L678W_Admin {

	/**
	 * Lỗi và dữ liệu vừa nhập của form Thêm/Sửa, để hiện lại form mà không mất dữ liệu.
	 */
	private static $form_error = '';
	private static $form_data  = null;

	/**
	 * Quyền nhập liệu (mặc định nhân viên Biên tập viên / Quản lý cửa hàng trở lên).
	 */
	public static function cap() {
		return apply_filters( 'l678w_capability', 'edit_posts' );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'set-screen-option', array( __CLASS__, 'set_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_l678w_per_page', array( __CLASS__, 'set_screen_option' ), 10, 3 );
		add_filter( 'plugin_action_links_' . plugin_basename( L678W_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		$hook = add_menu_page( 'Bảo hành', 'Bảo hành', self::cap(), 'l678w', array( __CLASS__, 'page_list' ), 'dashicons-shield', 26 );
		add_submenu_page( 'l678w', 'Danh sách bảo hành', 'Danh sách', self::cap(), 'l678w', array( __CLASS__, 'page_list' ) );
		add_submenu_page( 'l678w', 'Thêm bảo hành', 'Thêm thủ công', self::cap(), 'l678w-edit', array( __CLASS__, 'page_edit' ) );
		add_submenu_page( 'l678w', 'Import / Export', 'Import Excel', self::cap(), 'l678w-import', array( __CLASS__, 'page_import' ) );
		add_submenu_page( 'l678w', 'Cài đặt tra cứu', 'Cài đặt', 'manage_options', 'l678w-settings', array( __CLASS__, 'page_settings' ) );

		add_action(
			'load-' . $hook,
			function () {
				add_screen_option(
					'per_page',
					array(
						'label'   => 'Số dòng mỗi trang',
						'default' => 30,
						'option'  => 'l678w_per_page',
					)
				);
			}
		);
	}

	public static function set_screen_option( $status, $option, $value ) {
		return 'l678w_per_page' === $option ? min( 500, max( 1, (int) $value ) ) : $status;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=l678w-settings' ) ) . '">Cài đặt</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( false !== strpos( $hook, 'l678w' ) ) {
			wp_enqueue_style( 'l678w-admin', L678W_URL . 'assets/css/admin.css', array(), L678W_VERSION );
		}
	}

	private static function redirect( $page, array $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	private static function notice_from_query() {
		// phpcs:disable WordPress.Security.NonceVerification
		$messages = array(
			'saved'    => array( 'success', 'Đã lưu thông tin bảo hành.' ),
			'added'    => array( 'success', 'Đã thêm. Bạn có thể nhập đơn tiếp theo.' ),
			'deleted'  => array( 'success', 'Đã xoá %d đơn.' ),
			'settings' => array( 'success', 'Đã lưu cài đặt.' ),
			'cleared'  => array( 'success', 'Đã xoá toàn bộ dữ liệu bảo hành.' ),
		);
		$key = isset( $_GET['msg'] ) ? sanitize_key( $_GET['msg'] ) : '';
		if ( isset( $messages[ $key ] ) ) {
			$count = isset( $_GET['count'] ) ? (int) $_GET['count'] : 0;
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( sprintf( $messages[ $key ][1], $count ) ) );
		}
		// phpcs:enable
	}

	/**
	 * Xử lý form trước khi xuất HTML (để redirect được).
	 */
	public static function handle_actions() {
		// phpcs:disable WordPress.Security.NonceVerification
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( $_REQUEST['page'] ) : '';
		if ( 0 !== strpos( $page, 'l678w' ) ) {
			return;
		}
		// phpcs:enable

		if ( 'l678w' === $page ) {
			self::handle_delete();
		} elseif ( 'l678w-edit' === $page && isset( $_POST['l678w_save'] ) ) {
			self::handle_save();
		} elseif ( 'l678w-import' === $page && isset( $_GET['l678w_export'] ) ) {
			self::handle_export();
		} elseif ( 'l678w-import' === $page && isset( $_GET['l678w_template'] ) ) {
			self::handle_template();
		} elseif ( 'l678w-settings' === $page && isset( $_POST['l678w_settings_save'] ) ) {
			self::handle_settings();
		} elseif ( 'l678w-settings' === $page && isset( $_POST['l678w_clear_all'] ) ) {
			self::handle_clear_all();
		}
	}

	private static function handle_delete() {
		if ( ! current_user_can( self::cap() ) ) {
			return;
		}
		// Xoá một dòng.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			$id = absint( $_GET['id'] );
			check_admin_referer( 'l678w_delete_' . $id );
			$count = L678W_DB::delete( array( $id ) );
			self::redirect( 'l678w', array( 'msg' => 'deleted', 'count' => $count ) );
		}
		// Xoá hàng loạt.
		$action = '';
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ) {
			$action = sanitize_key( $_REQUEST['action'] );
		} elseif ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ) {
			$action = sanitize_key( $_REQUEST['action2'] );
		}
		if ( 'delete' === $action && ! empty( $_REQUEST['ids'] ) ) {
			check_admin_referer( 'bulk-warranties' );
			$count = L678W_DB::delete( array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) );
			self::redirect( 'l678w', array( 'msg' => 'deleted', 'count' => $count ) );
		}
	}

	private static function handle_save() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( 'Bạn không có quyền.' );
		}
		check_admin_referer( 'l678w_save' );

		$data = array();
		foreach ( array_keys( L678W_Helpers::fields() ) as $field ) {
			$data[ $field ] = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize trong prepare_row().
		}
		$row = L678W_DB::prepare_row( $data, 'manual' );
		$id  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		// Không có SĐT hợp lệ thì khách không tra cứu được, nên không cho lưu.
		if ( '' === $row['phone_index'] ) {
			self::$form_error = '' === $row['phone']
				? 'Vui lòng nhập số điện thoại của khách.'
				: 'Số điện thoại "' . $row['phone'] . '" không hợp lệ. Cần số 10 chữ số, VD: 0912345678 (nhiều số thì cách nhau bằng dấu phẩy).';
			self::$form_data  = $data;
			return;
		}

		if ( $id ) {
			L678W_DB::update( $id, $row );
			self::redirect( 'l678w-edit', array( 'id' => $id, 'msg' => 'saved' ) );
		}

		$new_id = L678W_DB::insert( $row );
		if ( isset( $_POST['save_and_new'] ) ) {
			self::redirect( 'l678w-edit', array( 'msg' => 'added' ) );
		}
		self::redirect( 'l678w-edit', array( 'id' => $new_id, 'msg' => 'saved' ) );
	}

	private static function handle_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền.' );
		}
		check_admin_referer( 'l678w_settings' );

		$mode     = isset( $_POST['address_mode'] ) ? sanitize_key( $_POST['address_mode'] ) : 'full';
		$settings = array(
			'warranty_months'     => isset( $_POST['warranty_months'] ) ? max( 0, (int) $_POST['warranty_months'] ) : 12,
			'address_mode'        => in_array( $mode, array( 'full', 'partial', 'hide' ), true ) ? $mode : 'full',
			'show_warranty'       => empty( $_POST['show_warranty'] ) ? 0 : 1,
			'title'               => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'intro'               => isset( $_POST['intro'] ) ? wp_kses_post( wp_unslash( $_POST['intro'] ) ) : '',
			'not_found'           => isset( $_POST['not_found'] ) ? sanitize_textarea_field( wp_unslash( $_POST['not_found'] ) ) : '',
			'delete_on_uninstall' => empty( $_POST['delete_on_uninstall'] ) ? 0 : 1,
		);
		update_option( 'l678w_settings', $settings, false );
		self::redirect( 'l678w-settings', array( 'msg' => 'settings' ) );
	}

	private static function handle_clear_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Bạn không có quyền.' );
		}
		check_admin_referer( 'l678w_clear_all' );
		if ( isset( $_POST['confirm_text'] ) && 'XOA' === strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['confirm_text'] ) ) ) ) ) {
			L678W_DB::truncate();
			self::redirect( 'l678w-settings', array( 'msg' => 'cleared' ) );
		}
		self::redirect( 'l678w-settings' );
	}

	private static function csv_headers( $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo "\xEF\xBB\xBF"; // BOM để Excel hiển thị đúng tiếng Việt.
	}

	private static function handle_template() {
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( 'Bạn không có quyền.' );
		}
		check_admin_referer( 'l678w_template' );
		self::csv_headers( 'mau-import-bao-hanh.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, L678W_Importer::template_headers() );
		fputcsv( $out, array( 'Nguyễn Văn A', '0912345678', 'Số 1 Cầu Giấy, Hà Nội', 'Dell Latitude 5440', 'i5 4300U, Ram 8GB, SSD 256GB, 14 HD', '25/10/2026', '12', '' ) );
		fclose( $out );
		exit;
	}

	private static function handle_export() {
		global $wpdb;
		if ( ! current_user_can( self::cap() ) ) {
			wp_die( 'Bạn không có quyền.' );
		}
		check_admin_referer( 'l678w_export' );

		$from  = isset( $_GET['from'] ) ? L678W_Helpers::parse_date( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : '';
		$to    = isset( $_GET['to'] ) ? L678W_Helpers::parse_date( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : '';
		$where = '1=1';
		if ( $from ) {
			$where .= $wpdb->prepare( ' AND purchase_date >= %s', $from );
		}
		if ( $to ) {
			$where .= $wpdb->prepare( ' AND purchase_date <= %s', $to );
		}

		self::csv_headers( 'bao-hanh-' . current_time( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, L678W_Importer::template_headers() );

		$offset = 0;
		do {
			$rows = $wpdb->get_results( 'SELECT * FROM ' . L678W_DB::table() . " WHERE {$where} ORDER BY purchase_date ASC, id ASC LIMIT 2000 OFFSET {$offset}" ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( $rows as $row ) {
				fputcsv(
					$out,
					array(
						$row->customer_name,
						// Thêm tab ẩn để Excel không làm mất số 0 đầu.
						"\t" . $row->phone,
						$row->address,
						$row->product_name,
						$row->config,
						L678W_Helpers::format_date( $row->purchase_date ),
						null === $row->warranty_months ? '' : $row->warranty_months,
						$row->note,
					)
				);
			}
			$offset += 2000;
		} while ( count( $rows ) === 2000 );

		fclose( $out );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Các trang                                                           */
	/* ------------------------------------------------------------------ */

	public static function page_list() {
		$table = new L678W_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap l678w-wrap">
			<h1 class="wp-heading-inline">Bảo hành</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=l678w-edit' ) ); ?>" class="page-title-action">Thêm thủ công</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=l678w-import' ) ); ?>" class="page-title-action">Import Excel</a>
			<hr class="wp-header-end">
			<?php self::notice_from_query(); ?>
			<p class="description">Tổng số: <strong><?php echo esc_html( number_format_i18n( L678W_DB::count() ) ); ?></strong> đơn. Shortcode trang tra cứu: <code>[<?php echo esc_html( L678W_Frontend::SHORTCODE ); ?>]</code></p>
			<form method="get">
				<input type="hidden" name="page" value="l678w">
				<?php
				$table->search_box( 'Tìm (tên, SĐT, máy...)', 'l678w-search' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}

	public static function page_edit() {
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$item = $id ? L678W_DB::get( $id ) : null;
		if ( $id && ! $item ) {
			echo '<div class="wrap"><h1>Không tìm thấy đơn bảo hành.</h1></div>';
			return;
		}
		$settings = L678W_Helpers::settings();
		$posted   = self::$form_data;
		$value    = function ( $field ) use ( $item, $posted ) {
			if ( null !== $posted ) {
				return isset( $posted[ $field ] ) ? (string) $posted[ $field ] : '';
			}
			return $item && isset( $item->$field ) ? (string) $item->$field : '';
		};
		if ( null !== $posted ) {
			$date = (string) $posted['purchase_date'];
		} else {
			$date = $item ? (string) $item->purchase_date : current_time( 'Y-m-d' );
		}
		?>
		<div class="wrap l678w-wrap">
			<h1 class="wp-heading-inline"><?php echo $item ? 'Sửa thông tin bảo hành' : 'Thêm bảo hành thủ công'; ?></h1>
			<?php if ( $item ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=l678w-edit' ) ); ?>" class="page-title-action">Thêm mới</a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php self::notice_from_query(); ?>
			<?php if ( self::$form_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( self::$form_error ); ?></p></div>
			<?php endif; ?>

			<form method="post" class="l678w-form-edit">
				<?php wp_nonce_field( 'l678w_save' ); ?>
				<input type="hidden" name="l678w_save" value="1">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">

				<div class="l678w-grid">
					<p>
						<label for="customer_name">Tên khách hàng</label>
						<input type="text" id="customer_name" name="customer_name" value="<?php echo esc_attr( $value( 'customer_name' ) ); ?>" autofocus>
					</p>
					<p>
						<label for="phone">Số điện thoại <span class="required">*</span></label>
						<input type="tel" id="phone" name="phone" value="<?php echo esc_attr( $value( 'phone' ) ); ?>" placeholder="VD: 0912345678" required>
						<span class="description">Nhiều số thì cách nhau bằng dấu phẩy.</span>
					</p>
					<p class="l678w-full">
						<label for="address">Địa chỉ</label>
						<input type="text" id="address" name="address" value="<?php echo esc_attr( $value( 'address' ) ); ?>">
					</p>
					<p>
						<label for="product_name">Tên máy</label>
						<input type="text" id="product_name" name="product_name" value="<?php echo esc_attr( $value( 'product_name' ) ); ?>" placeholder="VD: Dell Latitude 5440">
					</p>
					<p>
						<label for="purchase_date">Ngày mua</label>
						<input type="date" id="purchase_date" name="purchase_date" value="<?php echo esc_attr( $date ); ?>">
					</p>
					<p class="l678w-full">
						<label for="config">Cấu hình</label>
						<textarea id="config" name="config" rows="2" placeholder="VD: i5 8250U, Ram 8GB, SSD 256GB, 15.6 FHD"><?php echo esc_textarea( $value( 'config' ) ); ?></textarea>
					</p>
					<p>
						<label for="warranty_months">Bảo hành (tháng)</label>
						<input type="number" min="0" max="1200" id="warranty_months" name="warranty_months" value="<?php echo esc_attr( $value( 'warranty_months' ) ); ?>" placeholder="Mặc định: <?php echo (int) $settings['warranty_months']; ?>">
						<span class="description">Để trống = dùng mặc định trong Cài đặt.</span>
					</p>
					<p>
						<label for="note">Ghi chú / Tình trạng</label>
						<textarea id="note" name="note" rows="2" placeholder="VD: Đang sửa – thay bàn phím, hẹn trả 30/10"><?php echo esc_textarea( $value( 'note' ) ); ?></textarea>
						<span class="description">Khách sẽ thấy nội dung này khi tra cứu.</span>
					</p>
				</div>

				<p class="submit">
					<?php if ( ! $item ) : ?>
						<button type="submit" name="save_and_new" value="1" class="button button-primary button-large">Lưu &amp; thêm đơn khác</button>
						<button type="submit" class="button button-large">Lưu</button>
					<?php else : ?>
						<button type="submit" class="button button-primary button-large">Cập nhật</button>
						<a class="button button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=l678w' ) ); ?>">Về danh sách</a>
					<?php endif; ?>
				</p>
			</form>
		</div>
		<?php
	}

	public static function page_import() {
		$result = null;
		if ( isset( $_POST['l678w_import'] ) ) {
			$result = self::run_import();
		}
		?>
		<div class="wrap l678w-wrap">
			<h1>Import / Export dữ liệu bảo hành</h1>

			<?php if ( is_wp_error( $result ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $result->get_error_message() ); ?></p></div>
			<?php elseif ( is_array( $result ) ) : ?>
				<?php self::render_import_result( $result ); ?>
			<?php endif; ?>

			<div class="l678w-box">
				<h2>Import từ file Excel (.xlsx) hoặc .csv</h2>
				<p>File cần có dòng tiêu đề. Plugin tự nhận các cột sau (không phân biệt hoa thường, có dấu hay không dấu, thứ tự cột tuỳ ý):</p>
				<table class="widefat striped l678w-cols">
					<thead><tr><th>Thông tin</th><th>Tiêu đề cột được chấp nhận</th></tr></thead>
					<tbody>
						<tr><td>Tên khách hàng</td><td>Tên khách hàng, Khách hàng, Họ tên</td></tr>
						<tr><td>Số điện thoại</td><td>Số điện thoại, SĐT, Điện thoại</td></tr>
						<tr><td>Địa chỉ</td><td>Địa chỉ</td></tr>
						<tr><td>Tên máy</td><td>Tên máy, Tên sản phẩm, Sản phẩm, Model</td></tr>
						<tr><td>Cấu hình</td><td>Cấu hình, SKU, Thông số</td></tr>
						<tr><td>Ngày mua</td><td>Ngày mua, Ngày bán (dạng dd/mm/yyyy hoặc ô ngày của Excel)</td></tr>
						<tr><td>Bảo hành (tháng) – không bắt buộc</td><td>Bảo hành (tháng), Thời gian bảo hành, Hạn bảo hành</td></tr>
						<tr><td>Ghi chú – không bắt buộc</td><td>Ghi chú, Tình trạng</td></tr>
					</tbody>
				</table>
				<p>Các cột khác (STT, Mã bảo hành, Serial, Nhân viên...) sẽ được bỏ qua. File mẫu Excel cũ dùng được luôn.</p>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=l678w-import&l678w_template=1' ), 'l678w_template' ) ); ?>">Tải file mẫu</a></p>

				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'l678w_import' ); ?>
					<input type="hidden" name="l678w_import" value="1">
					<table class="form-table">
						<tr>
							<th><label for="l678w_file">Chọn file</label></th>
							<td><input type="file" id="l678w_file" name="file" accept=".xlsx,.csv" required>
								<p class="description">Dung lượng tối đa máy chủ cho phép: <?php echo esc_html( size_format( wp_max_upload_size() ) ); ?></p></td>
						</tr>
						<tr>
							<th>Khi gặp đơn đã có</th>
							<td>
								<label><input type="radio" name="mode" value="skip" checked> Bỏ qua đơn đã có trên web (cùng SĐT + tên khách + tên máy + ngày mua) – <em>khuyên dùng khi import thêm file mới</em></label><br>
								<label><input type="radio" name="mode" value="all"> Thêm tất cả, không kiểm tra trùng</label>
							</td>
						</tr>
						<tr>
							<th>Thay thế toàn bộ</th>
							<td><label><input type="checkbox" name="replace" value="1"> Xoá hết dữ liệu hiện có rồi import lại từ file này</label>
								<p class="description">Dùng khi file Excel là bản đầy đủ mới nhất. Nên Export sao lưu trước.</p></td>
						</tr>
					</table>
					<?php submit_button( 'Import', 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="l678w-box">
				<h2>Export (sao lưu) ra file CSV</h2>
				<form method="get">
					<input type="hidden" name="page" value="l678w-import">
					<input type="hidden" name="l678w_export" value="1">
					<?php wp_nonce_field( 'l678w_export', '_wpnonce', false ); ?>
					<label>Ngày mua từ <input type="date" name="from"></label>
					<label>đến <input type="date" name="to"></label>
					<?php submit_button( 'Export CSV', 'secondary', '', false ); ?>
					<p class="description">Để trống ngày = xuất toàn bộ. File export có thể import lại.</p>
				</form>
			</div>
		</div>
		<?php
	}

	private static function run_import() {
		if ( ! current_user_can( self::cap() ) ) {
			return new WP_Error( 'l678w', 'Bạn không có quyền.' );
		}
		check_admin_referer( 'l678w_import' );

		if ( empty( $_FILES['file']['tmp_name'] ) || ! empty( $_FILES['file']['error'] ) ) {
			return new WP_Error( 'l678w', 'Tải file lên thất bại. Kiểm tra lại dung lượng file.' );
		}
		$tmp  = $_FILES['file']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$name = sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) );
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'xlsx', 'csv' ), true ) ) {
			return new WP_Error( 'l678w', 'Chỉ hỗ trợ file .xlsx hoặc .csv. Nếu là file .xls cũ, hãy mở bằng Excel và "Lưu thành" .xlsx.' );
		}
		if ( ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'l678w', 'File tải lên không hợp lệ.' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		wp_raise_memory_limit( 'admin' );

		return L678W_Importer::import(
			$tmp,
			$ext,
			array(
				'mode'    => isset( $_POST['mode'] ) && 'all' === $_POST['mode'] ? 'all' : 'skip',
				'replace' => ! empty( $_POST['replace'] ),
			)
		);
	}

	private static function render_import_result( array $r ) {
		$labels = L678W_Helpers::fields();
		$mapped = array();
		foreach ( $r['mapped'] as $field => $index ) {
			$mapped[] = $labels[ $field ] . ' (cột ' . self::column_letter( $index ) . ')';
		}
		$missing = array_diff( array( 'customer_name', 'phone', 'address', 'product_name', 'config', 'purchase_date' ), array_keys( $r['mapped'] ) );
		?>
		<div class="notice notice-success">
			<p><strong>Import xong: thêm mới <?php echo (int) $r['inserted']; ?> đơn</strong>
				<?php if ( $r['duplicate'] ) : ?>
					· bỏ qua <?php echo (int) $r['duplicate']; ?> đơn trùng
				<?php endif; ?>
				<?php if ( $r['empty'] ) : ?>
					· <?php echo (int) $r['empty']; ?> dòng trống
				<?php endif; ?>
			</p>
			<p>Cột đã nhận: <?php echo esc_html( implode( ', ', $mapped ) ); ?></p>
			<?php if ( $missing ) : ?>
				<p>Không thấy cột: <?php echo esc_html( implode( ', ', array_intersect_key( $labels, array_flip( $missing ) ) ) ); ?></p>
			<?php endif; ?>
		</div>
		<?php if ( $r['no_phone'] || $r['bad_date'] ) : ?>
			<div class="notice notice-warning">
				<?php if ( $r['no_phone'] ) : ?>
					<p><?php echo count( $r['no_phone'] ); ?> dòng không có số điện thoại hợp lệ (khách sẽ không tra cứu được): dòng <?php echo esc_html( self::list_lines( $r['no_phone'] ) ); ?></p>
				<?php endif; ?>
				<?php if ( $r['bad_date'] ) : ?>
					<p><?php echo count( $r['bad_date'] ); ?> dòng có ngày mua không đọc được (đã để trống ngày): dòng <?php echo esc_html( self::list_lines( $r['bad_date'] ) ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	private static function list_lines( array $lines ) {
		$shown = array_slice( $lines, 0, 30 );
		return implode( ', ', $shown ) . ( count( $lines ) > 30 ? '...' : '' );
	}

	private static function column_letter( $index ) {
		$letter = '';
		$index++;
		while ( $index > 0 ) {
			$mod    = ( $index - 1 ) % 26;
			$letter = chr( 65 + $mod ) . $letter;
			$index  = (int) ( ( $index - $mod ) / 26 );
		}
		return $letter;
	}

	public static function page_settings() {
		$s = L678W_Helpers::settings();
		?>
		<div class="wrap l678w-wrap">
			<h1>Cài đặt tra cứu bảo hành</h1>
			<?php self::notice_from_query(); ?>

			<div class="l678w-box">
				<h2>Cách dùng</h2>
				<p>Thêm shortcode <code>[<?php echo esc_html( L678W_Frontend::SHORTCODE ); ?>]</code> vào trang tra cứu (hoặc kéo element <strong>"Tra cứu bảo hành"</strong> trong UX Builder của Flatsome).</p>
				<p>Shortcode cũ <code>[shortcode_check_warranty]</code> vẫn hoạt động, nên trang tra cứu hiện tại không cần sửa.</p>
				<p>Link tra cứu nhanh gửi cho khách: <code><?php echo esc_html( home_url( '/tra-ma-bao-hanh/#sdt=0912345678' ) ); ?></code></p>
			</div>

			<form method="post" class="l678w-box">
				<?php wp_nonce_field( 'l678w_settings' ); ?>
				<input type="hidden" name="l678w_settings_save" value="1">
				<table class="form-table">
					<tr>
						<th><label for="warranty_months">Thời gian bảo hành mặc định (tháng)</label></th>
						<td><input type="number" min="0" id="warranty_months" name="warranty_months" value="<?php echo (int) $s['warranty_months']; ?>" class="small-text">
							<p class="description">Áp dụng cho đơn không nhập số tháng riêng. Đặt 0 nếu không muốn tính hạn bảo hành.</p></td>
					</tr>
					<tr>
						<th>Hiển thị hạn bảo hành</th>
						<td><label><input type="checkbox" name="show_warranty" value="1" <?php checked( $s['show_warranty'] ); ?>> Hiện "Bảo hành đến" và nhãn Còn/Hết bảo hành</label></td>
					</tr>
					<tr>
						<th><label for="address_mode">Địa chỉ khi khách tra cứu</label></th>
						<td>
							<select id="address_mode" name="address_mode">
								<option value="full" <?php selected( $s['address_mode'], 'full' ); ?>>Hiện đầy đủ</option>
								<option value="partial" <?php selected( $s['address_mode'], 'partial' ); ?>>Ẩn số nhà/đường, chỉ hiện Quận/Huyện, Tỉnh</option>
								<option value="hide" <?php selected( $s['address_mode'], 'hide' ); ?>>Ẩn hoàn toàn</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="title">Tiêu đề khung tra cứu</label></th>
						<td><input type="text" id="title" name="title" value="<?php echo esc_attr( $s['title'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th><label for="intro">Mô tả dưới tiêu đề</label></th>
						<td><textarea id="intro" name="intro" rows="3" class="large-text"><?php echo esc_textarea( $s['intro'] ); ?></textarea>
							<p class="description">VD: Xem chi tiết chính sách bảo hành &lt;a href="..."&gt;tại đây&lt;/a&gt;.</p></td>
					</tr>
					<tr>
						<th><label for="not_found">Thông báo khi không tìm thấy</label></th>
						<td><textarea id="not_found" name="not_found" rows="2" class="large-text"><?php echo esc_textarea( $s['not_found'] ); ?></textarea></td>
					</tr>
					<tr>
						<th>Khi xoá plugin</th>
						<td><label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> Xoá luôn toàn bộ dữ liệu bảo hành khi gỡ cài đặt plugin</label></td>
					</tr>
				</table>
				<?php submit_button( 'Lưu cài đặt' ); ?>
			</form>

			<form method="post" class="l678w-box l678w-danger" onsubmit="return confirm('Chắc chắn xoá TOÀN BỘ dữ liệu bảo hành?');">
				<h2>Xoá toàn bộ dữ liệu</h2>
				<?php wp_nonce_field( 'l678w_clear_all' ); ?>
				<input type="hidden" name="l678w_clear_all" value="1">
				<p>Gõ <code>XOA</code> để xác nhận: <input type="text" name="confirm_text" class="small-text"> <?php submit_button( 'Xoá toàn bộ', 'delete', '', false ); ?></p>
			</form>
		</div>
		<?php
	}
}

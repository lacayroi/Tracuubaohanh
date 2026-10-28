<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class L678W_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'warranty',
				'plural'   => 'warranties',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'            => '<input type="checkbox" />',
			'customer_name' => 'Khách hàng',
			'phone'         => 'Số điện thoại',
			'product_name'  => 'Tên máy',
			'config'        => 'Cấu hình',
			'purchase_date' => 'Ngày mua',
			'salesperson'   => 'NV bán',
			'warranty'      => 'Bảo hành đến',
			'address'       => 'Địa chỉ',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'customer_name' => array( 'customer_name', false ),
			'product_name'  => array( 'product_name', false ),
			'purchase_date' => array( 'purchase_date', true ),
		);
	}

	protected function get_bulk_actions() {
		return array( 'delete' => 'Xoá' );
	}

	public function no_items() {
		echo 'Chưa có dữ liệu bảo hành.';
	}

	protected function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . (int) $item->id . '" />';
	}

	protected function column_customer_name( $item ) {
		$edit   = admin_url( 'admin.php?page=l678w-edit&id=' . (int) $item->id );
		$delete = wp_nonce_url( admin_url( 'admin.php?page=l678w&action=delete&id=' . (int) $item->id ), 'l678w_delete_' . $item->id );
		$name   = '' !== $item->customer_name ? $item->customer_name : '(không tên)';

		$actions = array(
			'edit'   => '<a href="' . esc_url( $edit ) . '">Sửa</a>',
			'delete' => '<a href="' . esc_url( $delete ) . '" onclick="return confirm(\'Xoá đơn bảo hành này?\');" style="color:#b32d2e">Xoá</a>',
		);
		return '<strong><a class="row-title" href="' . esc_url( $edit ) . '">' . esc_html( $name ) . '</a></strong>' . $this->row_actions( $actions );
	}

	protected function column_purchase_date( $item ) {
		return esc_html( L678W_Helpers::format_date( $item->purchase_date ) );
	}

	protected function column_warranty( $item ) {
		$end = L678W_Helpers::warranty_end( $item );
		if ( ! $end ) {
			return '—';
		}
		$color = $end >= current_time( 'Y-m-d' ) ? '#1f9d55' : '#b32d2e';
		return '<span style="color:' . $color . '">' . esc_html( L678W_Helpers::format_date( $end ) ) . '</span>';
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( wp_trim_words( (string) $item->$column_name, 15 ) ) : '';
	}

	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'l678w_per_page', 30 );
		// phpcs:disable WordPress.Security.NonceVerification
		$result = L678W_DB::query(
			array(
				'search'   => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
				'from'     => isset( $_REQUEST['from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) : '',
				'to'       => isset( $_REQUEST['to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) : '',
				'orderby'  => isset( $_REQUEST['orderby'] ) ? sanitize_key( $_REQUEST['orderby'] ) : 'purchase_date',
				'order'    => isset( $_REQUEST['order'] ) ? sanitize_key( $_REQUEST['order'] ) : 'desc',
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		// phpcs:enable

		$this->items           = $result['items'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$from = isset( $_REQUEST['from'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['from'] ) ) : '';
		$to   = isset( $_REQUEST['to'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['to'] ) ) : '';
		// phpcs:enable
		?>
		<div class="alignleft actions">
			<label>Ngày mua từ <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label>
			<label>đến <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label>
			<?php submit_button( 'Lọc', '', 'filter_action', false ); ?>
		</div>
		<?php
	}
}

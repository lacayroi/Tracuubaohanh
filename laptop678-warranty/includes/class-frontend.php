<?php
defined( 'ABSPATH' ) || exit;

/**
 * Trang tra cứu công khai: shortcode [tra_cuu_bao_hanh] + AJAX + element UX Builder của Flatsome.
 */
class L678W_Frontend {

	const SHORTCODE = 'tra_cuu_bao_hanh';

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_legacy_shortcode' ), 99 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_action( 'wp_ajax_l678w_lookup', array( __CLASS__, 'ajax_lookup' ) );
		add_action( 'wp_ajax_nopriv_l678w_lookup', array( __CLASS__, 'ajax_lookup' ) );
		add_action( 'ux_builder_setup', array( __CLASS__, 'ux_builder' ) );
	}

	/**
	 * Giữ tương thích shortcode của plugin cũ để trang tra cứu hiện tại chạy ngay sau khi đổi plugin.
	 */
	public static function register_legacy_shortcode() {
		if ( ! shortcode_exists( 'shortcode_check_warranty' ) ) {
			add_shortcode( 'shortcode_check_warranty', array( __CLASS__, 'shortcode' ) );
		}
	}

	public static function register_assets() {
		wp_register_style( 'l678w-frontend', L678W_URL . 'assets/css/frontend.css', array(), L678W_VERSION );
		wp_register_script( 'l678w-frontend', L678W_URL . 'assets/js/frontend.js', array(), L678W_VERSION, true );
		wp_localize_script(
			'l678w-frontend',
			'L678W',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'empty'   => 'Vui lòng nhập số điện thoại.',
					'invalid' => 'Số điện thoại không hợp lệ.',
					'error'   => 'Có lỗi xảy ra, vui lòng thử lại.',
				),
			)
		);
	}

	public static function ux_builder() {
		if ( ! function_exists( 'add_ux_builder_shortcode' ) ) {
			return;
		}
		add_ux_builder_shortcode(
			self::SHORTCODE,
			array(
				'name'     => 'Tra cứu bảo hành',
				'category' => 'Content',
				'options'  => array(
					'title'       => array(
						'type'    => 'textfield',
						'heading' => 'Tiêu đề',
						'default' => '',
					),
					'placeholder' => array(
						'type'    => 'textfield',
						'heading' => 'Placeholder ô nhập',
						'default' => 'Nhập số điện thoại mua hàng',
					),
					'button'      => array(
						'type'    => 'textfield',
						'heading' => 'Chữ trên nút',
						'default' => 'Kiểm tra',
					),
				),
			)
		);
	}

	public static function shortcode( $atts ) {
		$settings = L678W_Helpers::settings();
		$atts     = shortcode_atts(
			array(
				'title'       => $settings['title'],
				'placeholder' => 'Nhập số điện thoại mua hàng',
				'button'      => 'Kiểm tra',
			),
			$atts,
			self::SHORTCODE
		);

		wp_enqueue_style( 'l678w-frontend' );
		wp_enqueue_script( 'l678w-frontend' );

		// Kết quả luôn lấy qua AJAX (link ?sdt=... hoặc #phone=... được JS tự tra cứu) để
		// plugin cache trang không bao giờ lưu lại thông tin khách hàng.
		ob_start();
		?>
		<div class="l678w-lookup">
			<?php if ( '' !== trim( $atts['title'] ) ) : ?>
				<h3 class="l678w-title"><?php echo esc_html( $atts['title'] ); ?></h3>
			<?php endif; ?>
			<?php if ( '' !== trim( $settings['intro'] ) ) : ?>
				<div class="l678w-intro"><?php echo wp_kses_post( wpautop( $settings['intro'] ) ); ?></div>
			<?php endif; ?>
			<form class="l678w-form" method="get" action="">
				<div class="l678w-field">
					<input type="tel" name="sdt" inputmode="tel" autocomplete="tel" required
						placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
						aria-label="<?php echo esc_attr( $atts['placeholder'] ); ?>">
					<button type="submit" class="button primary l678w-submit"><?php echo esc_html( $atts['button'] ); ?></button>
				</div>
			</form>
			<div class="l678w-results" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Endpoint tra cứu. Không dùng nonce vì trang thường bị cache (nonce hết hạn sẽ làm hỏng tra cứu);
	 * thay vào đó giới hạn số lần tra cứu theo IP và chỉ trả kết quả khi khớp chính xác số điện thoại.
	 */
	public static function ajax_lookup() {
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( self::rate_limited() ) {
			wp_send_json_error( array( 'message' => 'Bạn tra cứu quá nhiều lần. Vui lòng thử lại sau ít phút.' ), 429 );
		}
		if ( ! L678W_Helpers::extract_phones( $phone ) ) {
			wp_send_json_error( array( 'message' => 'Số điện thoại không hợp lệ.' ) );
		}

		nocache_headers();
		wp_send_json_success( array( 'html' => self::render_results( $phone ) ) );
	}

	private static function rate_limited() {
		$limit  = (int) apply_filters( 'l678w_rate_limit', 30 );
		$window = (int) apply_filters( 'l678w_rate_window', 10 * MINUTE_IN_SECONDS );
		if ( $limit <= 0 || current_user_can( 'edit_posts' ) ) {
			return false;
		}
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip    = apply_filters( 'l678w_client_ip', $ip );
		$key   = 'l678w_rl_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}

	public static function render_results( $phone ) {
		$settings = L678W_Helpers::settings();

		if ( ! L678W_Helpers::extract_phones( $phone ) ) {
			return '<div class="l678w-notice l678w-error">Số điện thoại không hợp lệ.</div>';
		}

		$rows = L678W_DB::find_by_phone( $phone );
		if ( ! $rows ) {
			return '<div class="l678w-notice l678w-empty">' . esc_html( $settings['not_found'] ) . '</div>';
		}

		$today = current_time( 'Y-m-d' );
		$html  = '<div class="l678w-summary">Tìm thấy <strong>' . count( $rows ) . '</strong> đơn hàng.</div>';

		foreach ( $rows as $i => $row ) {
			$lines = array(
				'Tên khách hàng' => $row->customer_name,
				'Số điện thoại'  => $row->phone,
			);
			if ( 'hide' !== $settings['address_mode'] && '' !== (string) $row->address ) {
				$lines['Địa chỉ'] = 'partial' === $settings['address_mode'] ? L678W_Helpers::mask_address( $row->address ) : $row->address;
			}
			$lines['Tên máy']  = $row->product_name;
			$lines['Cấu hình'] = $row->config;
			$lines['Ngày mua'] = L678W_Helpers::format_date( $row->purchase_date );

			$badge = '';
			if ( $settings['show_warranty'] ) {
				$end = L678W_Helpers::warranty_end( $row );
				if ( $end ) {
					$lines['Bảo hành đến'] = L678W_Helpers::format_date( $end );
					if ( $end >= $today ) {
						$days  = (int) floor( ( strtotime( $end ) - strtotime( $today ) ) / DAY_IN_SECONDS );
						$badge = '<span class="l678w-badge l678w-valid">Còn bảo hành · ' . $days . ' ngày</span>';
					} else {
						$badge = '<span class="l678w-badge l678w-expired">Hết bảo hành</span>';
					}
				}
			}
			$lines['Ghi chú'] = $row->note;

			$title = '' !== $row->product_name ? $row->product_name : 'Đơn hàng ' . ( $i + 1 );

			$html .= '<div class="l678w-card">';
			$html .= '<div class="l678w-card-head"><span class="l678w-card-title">' . esc_html( ( $i + 1 ) . '. ' . $title ) . '</span>' . $badge . '</div>';
			$html .= '<table class="l678w-table"><tbody>';
			foreach ( $lines as $label => $value ) {
				if ( '' === trim( (string) $value ) ) {
					continue;
				}
				$html .= '<tr><th>' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
			}
			$html .= '</tbody></table></div>';
		}

		return $html;
	}
}

<?php
/**
 * ApiFlash admin dashboard.
 *
 * Adds a top-level "ApiFlash" menu in wp-admin with:
 *   - a quota viewer (limit / used / remaining / reset countdown),
 *   - access-key management (option-backed, or read-only when pinned by the
 *     PUBLICTECH_APIFLASH_KEY constant),
 *   - a free connection test (hits the quota endpoint — no screenshot cost),
 *   - a live capture test (consumes one screenshot, previews the result),
 *   - a reference card for ApiFlash rate limits and error codes.
 *
 * All ApiFlash traffic is server-side (see ApiFlash); the access key is never
 * emitted to the browser. State-changing actions are nonce-protected and use
 * the post/redirect/get pattern so a refresh never re-submits.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class ApiFlash_Admin {

	private const MENU_SLUG = 'pt-apiflash';
	private const NONCE     = 'pt_apiflash_action';

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'ApiFlash', 'publictech-core' ),
			__( 'ApiFlash', 'publictech-core' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-camera',
			81
		);
	}

	/** Slug of our own admin screen, used to gate notices/redirects. */
	private function base_url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/* ---------- actions (admin_init, PRG) ---------- */

	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only; each branch verifies its own nonce before doing work.
		$action = isset( $_REQUEST['pt_apiflash_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['pt_apiflash_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		if ( 'save_key' === $action ) {
			check_admin_referer( self::NONCE );
			if ( ! ApiFlash::key_is_constant() ) {
				$key = isset( $_POST['pt_apiflash_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pt_apiflash_key'] ) ) : '';
				// Allow clearing; otherwise store the trimmed key.
				update_option( ApiFlash::OPT_KEY, $key, false );
				ApiFlash::flush_quota_cache();
			}
			$this->redirect( 'saved' );
		}

		if ( 'refresh' === $action ) {
			check_admin_referer( self::NONCE );
			ApiFlash::flush_quota_cache();
			$this->redirect( 'refreshed' );
		}

		if ( 'capture_test' === $action ) {
			check_admin_referer( self::NONCE );
			$url = isset( $_POST['pt_apiflash_test_url'] ) ? esc_url_raw( wp_unslash( $_POST['pt_apiflash_test_url'] ) ) : '';
			$this->run_capture_test( $url );
			$this->redirect( 'tested' );
		}
	}

	private function redirect( string $status ): void {
		wp_safe_redirect( add_query_arg( 'pt_status', $status, $this->base_url() ) );
		exit;
	}

	/**
	* Run a capture against ApiFlash in JSON mode (returns a hosted image URL on
	* success, a structured error otherwise) and stash the outcome in a
	* short-lived per-user transient for display after the redirect.
	*/
	private function run_capture_test( string $url ): void {
		if ( '' === $url ) {
			$this->store_test( [ 'ok' => false, 'message' => __( 'Enter a URL to capture.', 'publictech-core' ) ] );
			return;
		}
		$res = ApiFlash::capture( $url, [
			'response_type' => 'json',
			'format'        => 'png',
			'width'         => Chart_Snapshots::WIDTH,
			'height'        => Chart_Snapshots::HEIGHT,
			'fresh'         => 'true',
		] );
		if ( is_wp_error( $res ) ) {
			$this->store_test( [ 'ok' => false, 'message' => $res->get_error_message(), 'url' => $url ] );
			return;
		}
		$data = json_decode( $res['body'], true );
		if ( 200 === $res['code'] && is_array( $data ) && ! empty( $data['url'] ) ) {
			$this->store_test( [
				'ok'        => true,
				'image_url' => (string) $data['url'],
				'url'       => $url,
			] );
			return;
		}
		$this->store_test( [
			'ok'      => false,
			'code'    => $res['code'],
			'message' => is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : ApiFlash::error_message( $res['body'] ),
			'url'     => $url,
		] );
	}

	private function test_transient_key(): string {
		return 'pt_apiflash_test_' . get_current_user_id();
	}

	private function store_test( array $result ): void {
		set_transient( $this->test_transient_key(), $result, 5 * MINUTE_IN_SECONDS );
	}

	private function take_test(): ?array {
		$key = $this->test_transient_key();
		$val = get_transient( $key );
		if ( is_array( $val ) ) {
			delete_transient( $key );
			return $val;
		}
		return null;
	}

	/* ---------- render ---------- */

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$configured = ApiFlash::is_configured();
		$quota      = $configured ? ApiFlash::quota() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag for a notice; no state change.
		$status     = isset( $_GET['pt_status'] ) ? sanitize_key( wp_unslash( $_GET['pt_status'] ) ) : '';
		?>
		<div class="wrap">
			<h1><span class="dashicons dashicons-camera" style="font-size:28px;width:28px;height:28px;vertical-align:-4px"></span> <?php esc_html_e( 'ApiFlash', 'publictech-core' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Screenshot API used to render static social-card images for saved charts.', 'publictech-core' ); ?></p>

			<?php $this->render_notice( $status ); ?>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'No access key is set. Add your ApiFlash access key below to enable the quota viewer and capture tests.', 'publictech-core' ); ?>
				</p></div>
			<?php endif; ?>

			<?php
			$this->render_quota_panel( $quota );
			$this->render_key_panel();
			$this->render_test_panel();
			$this->render_reference();
			?>
		</div>
		<?php
	}

	private function render_notice( string $status ): void {
		$messages = [
			'saved'     => __( 'ApiFlash settings saved.', 'publictech-core' ),
			'refreshed' => __( 'Quota refreshed.', 'publictech-core' ),
			'tested'    => __( 'Capture test complete.', 'publictech-core' ),
		];
		if ( isset( $messages[ $status ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $messages[ $status ] )
			);
		}
	}

	/**
	* @param array{limit:int,remaining:int,reset:int,fetched:int}|\WP_Error|null $quota
	*/
	private function render_quota_panel( $quota ): void {
		?>
		<h2><?php esc_html_e( 'Quota', 'publictech-core' ); ?></h2>
		<?php
		if ( null === $quota ) {
			echo '<p>' . esc_html__( 'Configure an access key to view quota.', 'publictech-core' ) . '</p>';
			return;
		}
		if ( is_wp_error( $quota ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $quota->get_error_message() ) . '</p></div>';
			$this->render_refresh_button();
			return;
		}

		$limit     = max( 0, (int) $quota['limit'] );
		$remaining = max( 0, (int) $quota['remaining'] );
		$used      = max( 0, $limit - $remaining );
		$pct_used  = $limit > 0 ? min( 100, round( $used / $limit * 100 ) ) : 0;
		$bar_color = $pct_used >= 90 ? '#d63638' : ( $pct_used >= 75 ? '#dba617' : '#00a32a' );
		$reset     = (int) $quota['reset'];
		?>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
				<tr><th style="width:200px"><?php esc_html_e( 'Plan limit (per period)', 'publictech-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $limit ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Used', 'publictech-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $used ) ); ?> (<?php echo esc_html( $pct_used ); ?>%)</td></tr>
				<tr><th><?php esc_html_e( 'Remaining', 'publictech-core' ); ?></th><td><strong><?php echo esc_html( number_format_i18n( $remaining ) ); ?></strong></td></tr>
				<tr>
					<th><?php esc_html_e( 'Resets', 'publictech-core' ); ?></th>
					<td>
						<?php
						if ( $reset > 0 ) {
							echo esc_html( wp_date( 'Y-m-d H:i', $reset ) );
							if ( $reset > time() ) {
								/* translators: %s: human-readable time difference, e.g. "3 days" */
								echo ' — ' . esc_html( sprintf( __( 'in %s', 'publictech-core' ), human_time_diff( time(), $reset ) ) );
							}
						} else {
							esc_html_e( '—', 'publictech-core' );
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<div style="max-width:640px;margin:12px 0">
			<div style="background:#e0e0e0;border-radius:4px;height:18px;overflow:hidden">
				<div style="width:<?php echo esc_attr( $pct_used ); ?>%;background:<?php echo esc_attr( $bar_color ); ?>;height:100%"></div>
			</div>
		</div>

		<p class="description">
			<?php
			/* translators: %s: human-readable time difference, e.g. "2 minutes" */
			echo esc_html( sprintf( __( 'Figures cached; last fetched %s ago.', 'publictech-core' ), human_time_diff( (int) $quota['fetched'], time() ) ) );
			?>
		</p>
		<?php
		$this->render_refresh_button();
	}

	private function render_refresh_button(): void {
		?>
		<form method="post" action="<?php echo esc_url( $this->base_url() ); ?>" style="margin:8px 0">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="pt_apiflash_action" value="refresh" />
			<?php submit_button( __( 'Refresh quota', 'publictech-core' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_key_panel(): void {
		$is_const = ApiFlash::key_is_constant();
		$hint     = ApiFlash::key_hint();
		?>
		<h2><?php esc_html_e( 'Access key', 'publictech-core' ); ?></h2>
		<?php if ( $is_const ) : ?>
			<p>
				<?php esc_html_e( 'The access key is defined by the PUBLICTECH_APIFLASH_KEY constant in wp-config.php and cannot be edited here.', 'publictech-core' ); ?>
				<code><?php echo esc_html( $hint ); ?></code>
			</p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $this->base_url() ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="pt_apiflash_action" value="save_key" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pt_apiflash_key"><?php esc_html_e( 'ApiFlash access key', 'publictech-core' ); ?></label></th>
						<td>
							<input id="pt_apiflash_key" name="pt_apiflash_key" type="password" class="regular-text"
							value="<?php echo esc_attr( ApiFlash::access_key() ); ?>" autocomplete="off" spellcheck="false" />
							<?php if ( '' !== $hint ) : ?>
								<p class="description">
									<?php
									/* translators: %s: masked key hint, e.g. "••••75cc" */
									echo esc_html( sprintf( __( 'Current key: %s', 'publictech-core' ), $hint ) );
									?>
								</p>
							<?php endif; ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the ApiFlash dashboard */
									wp_kses( __( 'Find your key in the <a href="%s" target="_blank" rel="noopener">ApiFlash dashboard</a>. Stored server-side; never sent to the browser.', 'publictech-core' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ),
									'https://apiflash.com/dashboard'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save key', 'publictech-core' ) ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	private function render_test_panel(): void {
		if ( ! ApiFlash::is_configured() ) {
			return;
		}
		$result   = $this->take_test();
		$default  = home_url( '/' );
		?>
		<h2><?php esc_html_e( 'Capture test', 'publictech-core' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Renders a URL through ApiFlash to confirm the key works end-to-end. Each run consumes one screenshot from your quota.', 'publictech-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->base_url() ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="pt_apiflash_action" value="capture_test" />
			<input type="url" name="pt_apiflash_test_url" class="regular-text" style="width:420px"
			value="<?php echo esc_attr( $default ); ?>" required />
			<?php submit_button( __( 'Run capture', 'publictech-core' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		if ( null === $result ) {
			return;
		}
		if ( ! empty( $result['ok'] ) ) {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'Capture succeeded.', 'publictech-core' ) . '</p></div>';
			printf(
				'<p><img src="%s" alt="%s" style="max-width:600px;height:auto;border:1px solid #ccd0d4" /></p>',
				esc_url( $result['image_url'] ),
				esc_attr__( 'ApiFlash capture preview', 'publictech-core' )
			);
		} else {
			$detail = isset( $result['code'] ) && $result['code']
				/* translators: 1: HTTP status code, 2: error message */
				? sprintf( __( 'HTTP %1$d — %2$s', 'publictech-core' ), (int) $result['code'], (string) ( $result['message'] ?? '' ) )
				: (string) ( $result['message'] ?? __( 'Capture failed.', 'publictech-core' ) );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $detail ) . '</p></div>';
		}
	}

	private function render_reference(): void {
		$rate_limits = [
			__( 'Request rate', 'publictech-core' )          => __( '20 requests/second (leaky bucket), burst size 400. Excess is delayed, then 429.', 'publictech-core' ),
			__( 'Failed-capture limit', 'publictech-core' )  => __( 'Identical calls that fail to capture (unreachable site, etc.) are limited to 5/hour.', 'publictech-core' ),
		];
		$errors = [
			'400' => __( 'Bad Request — invalid parameters or the target URL cannot be captured.', 'publictech-core' ),
			'401' => __( 'Unauthorized — the access key is invalid or revoked.', 'publictech-core' ),
			'402' => __( 'Payment Required — monthly screenshot quota exceeded for the current plan.', 'publictech-core' ),
			'403' => __( 'Forbidden — the current plan does not support a requested feature.', 'publictech-core' ),
			'429' => __( 'Too Many Requests — rate limit hit; see the response body for the reason.', 'publictech-core' ),
			'500' => __( 'Internal Server Error — ApiFlash failed to handle the capture.', 'publictech-core' ),
		];
		?>
		<h2><?php esc_html_e( 'Reference', 'publictech-core' ); ?></h2>
		<h3 style="margin-bottom:4px"><?php esc_html_e( 'Rate limits', 'publictech-core' ); ?></h3>
		<table class="widefat striped" style="max-width:760px">
			<tbody>
				<?php foreach ( $rate_limits as $label => $desc ) : ?>
					<tr><th style="width:200px"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $desc ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<h3 style="margin-bottom:4px"><?php esc_html_e( 'Status codes', 'publictech-core' ); ?></h3>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr><th style="width:80px"><?php esc_html_e( 'Code', 'publictech-core' ); ?></th><th><?php esc_html_e( 'Meaning', 'publictech-core' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $errors as $code => $desc ) : ?>
					<tr><td><code><?php echo esc_html( $code ); ?></code></td><td><?php echo esc_html( $desc ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

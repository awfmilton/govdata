<?php
/**
 * External Screenshots admin dashboard.
 *
 * Replaces the former ApiFlash-only admin page. Manages a two-provider
 * screenshot chain: ApiFlash (primary quota-limited SaaS) and GCP Cloud Run
 * (self-hosted fallback). Provider routing is controlled by the mode selector
 * at the top of the page.
 *
 * The old `?page=pt-apiflash` URL redirects here automatically so any saved
 * bookmarks keep working.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class External_Screenshots_Admin {

	private const MENU_SLUG = 'pt-external-screenshots';
	private const NONCE     = 'pt_ext_ss_action';

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'handle_actions' ] );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'External Screenshots', 'publictech-core' ),
			__( 'Screenshots', 'publictech-core' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-camera',
			81
		);
	}

	private function base_url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	/* ---------- actions (admin_init, PRG) ---------- */

	public function handle_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Redirect old ApiFlash bookmark to the new page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing.
		if ( isset( $_GET['page'] ) && 'pt-apiflash' === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			wp_safe_redirect( $this->base_url() );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only; each branch verifies its own nonce.
		$action = isset( $_REQUEST['pt_ext_ss_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['pt_ext_ss_action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		if ( 'save_mode' === $action ) {
			check_admin_referer( self::NONCE );
			$mode = isset( $_POST['pt_screenshot_mode'] ) ? sanitize_key( wp_unslash( $_POST['pt_screenshot_mode'] ) ) : 'auto';
			if ( ! in_array( $mode, [ 'auto', 'apiflash_only', 'gcp_only' ], true ) ) {
				$mode = 'auto';
			}
			update_option( External_Screenshots::OPT_MODE, $mode, true );
			$this->redirect( 'saved' );
		}

		if ( 'save_apiflash_key' === $action ) {
			check_admin_referer( self::NONCE );
			if ( ! ApiFlash::key_is_constant() ) {
				$key = isset( $_POST['pt_apiflash_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pt_apiflash_key'] ) ) : '';
				update_option( ApiFlash::OPT_KEY, $key, false );
				ApiFlash::flush_quota_cache();
			}
			$this->redirect( 'saved' );
		}

		if ( 'refresh_quota' === $action ) {
			check_admin_referer( self::NONCE );
			ApiFlash::flush_quota_cache();
			$this->redirect( 'refreshed' );
		}

		if ( 'save_gcp_key' === $action ) {
			check_admin_referer( self::NONCE );
			if ( ! GCP_Screenshot::key_is_constant() ) {
				$key = isset( $_POST['pt_gcp_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pt_gcp_key'] ) ) : '';
				update_option( GCP_Screenshot::OPT_KEY, $key, false );
			}
			$this->redirect( 'saved' );
		}

		if ( 'save_gcp_url' === $action ) {
			check_admin_referer( self::NONCE );
			if ( ! GCP_Screenshot::url_is_constant() ) {
				$url = isset( $_POST['pt_gcp_url'] ) ? esc_url_raw( wp_unslash( $_POST['pt_gcp_url'] ) ) : '';
				update_option( GCP_Screenshot::OPT_URL, $url, false );
			}
			$this->redirect( 'saved' );
		}

		if ( 'capture_test' === $action ) {
			check_admin_referer( self::NONCE );
			$url      = isset( $_POST['pt_test_url'] ) ? esc_url_raw( wp_unslash( $_POST['pt_test_url'] ) ) : '';
			$provider = isset( $_POST['pt_test_provider'] ) ? sanitize_key( wp_unslash( $_POST['pt_test_provider'] ) ) : 'auto';
			$this->run_capture_test( $url, $provider );
			$this->redirect( 'tested' );
		}
	}

	private function redirect( string $status ): void {
		wp_safe_redirect( add_query_arg( 'pt_status', $status, $this->base_url() ) );
		exit;
	}

	/**
	 * Run a capture and stash the outcome in a short-lived per-user transient.
	 */
	private function run_capture_test( string $url, string $provider ): void {
		if ( '' === $url ) {
			$this->store_test( [ 'ok' => false, 'message' => __( 'Enter a URL to capture.', 'publictech-core' ) ] );
			return;
		}

		$base_params = [ 'width' => 1200, 'height' => 630 ];

		if ( 'apiflash' === $provider ) {
			if ( ! ApiFlash::is_configured() ) {
				$this->store_test( [ 'ok' => false, 'message' => __( 'ApiFlash key not configured.', 'publictech-core' ), 'provider' => 'apiflash' ] );
				return;
			}
			// Use response_type=json so ApiFlash returns a hosted URL we can display.
			$res = ApiFlash::capture( $url, array_merge( $base_params, [ 'format' => 'png', 'response_type' => 'json', 'fresh' => 'true' ] ) );
			if ( is_wp_error( $res ) ) {
				$this->store_test( [ 'ok' => false, 'message' => $res->get_error_message(), 'provider' => 'apiflash' ] );
				return;
			}
			$data = json_decode( $res['body'], true );
			if ( 200 === $res['code'] && is_array( $data ) && ! empty( $data['url'] ) ) {
				$this->store_test( [ 'ok' => true, 'image_url' => (string) $data['url'], 'provider' => 'apiflash', 'capture_url' => $url ] );
				return;
			}
			$this->store_test( [
				'ok'          => false,
				'code'        => $res['code'],
				'message'     => is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : ApiFlash::error_message( $res['body'] ),
				'provider'    => 'apiflash',
				'capture_url' => $url,
			] );
			return;
		}

		if ( 'gcp' === $provider ) {
			if ( ! GCP_Screenshot::is_configured() ) {
				$this->store_test( [ 'ok' => false, 'message' => __( 'GCP key not configured.', 'publictech-core' ), 'provider' => 'gcp' ] );
				return;
			}
			$res = GCP_Screenshot::capture( $url, array_merge( $base_params, [ 'type' => 'png' ] ) );
			if ( is_wp_error( $res ) ) {
				$this->store_test( [ 'ok' => false, 'message' => $res->get_error_message(), 'provider' => 'gcp' ] );
				return;
			}
			if ( 200 === $res['code'] && "\x89PNG" === substr( $res['body'], 0, 4 ) ) {
				$this->store_test( [ 'ok' => true, 'image_data' => 'data:image/png;base64,' . base64_encode( $res['body'] ), 'provider' => 'gcp', 'capture_url' => $url ] );
				return;
			}
			$this->store_test( [
				'ok'          => false,
				'code'        => $res['code'],
				'message'     => GCP_Screenshot::error_message( $res['body'] ),
				'provider'    => 'gcp',
				'capture_url' => $url,
			] );
			return;
		}

		// auto: use the orchestrator (binary result — base64 for inline display).
		$res = External_Screenshots::capture( $url, array_merge( $base_params, [ 'format' => 'png', 'fresh' => 'true' ] ) );
		if ( is_wp_error( $res ) ) {
			$this->store_test( [ 'ok' => false, 'message' => $res->get_error_message() ] );
			return;
		}
		if ( 200 === $res['code'] && "\x89PNG" === substr( $res['body'], 0, 4 ) ) {
			$this->store_test( [ 'ok' => true, 'image_data' => 'data:image/png;base64,' . base64_encode( $res['body'] ), 'provider' => $res['provider'] ?? 'auto', 'capture_url' => $url ] );
			return;
		}
		$this->store_test( [ 'ok' => false, 'code' => $res['code'], 'message' => sprintf( 'HTTP %d via %s', $res['code'], $res['provider'] ?? 'unknown' ) ] );
	}

	private function test_transient_key(): string {
		return 'pt_ext_ss_test_' . get_current_user_id();
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag.
		$status = isset( $_GET['pt_status'] ) ? sanitize_key( wp_unslash( $_GET['pt_status'] ) ) : '';
		?>
		<div class="wrap">
			<h1>
				<span class="dashicons dashicons-camera" style="font-size:28px;width:28px;height:28px;vertical-align:-4px"></span>
				<?php esc_html_e( 'External Screenshots', 'publictech-core' ); ?>
			</h1>
			<p class="description"><?php esc_html_e( 'Manages screenshot capture for social card images. ApiFlash is the primary provider (monthly quota); GCP Cloud Run is the self-hosted fallback.', 'publictech-core' ); ?></p>

			<?php $this->render_notice( $status ); ?>

			<?php
			$this->render_mode_panel();
			$this->render_status_panel();
			$this->render_apiflash_panel();
			$this->render_gcp_panel();
			$this->render_test_panel();
			$this->render_reference();
			?>
		</div>
		<?php
	}

	private function render_notice( string $status ): void {
		$messages = [
			'saved'     => __( 'Settings saved.', 'publictech-core' ),
			'refreshed' => __( 'ApiFlash quota refreshed.', 'publictech-core' ),
			'tested'    => __( 'Capture test complete — see results below.', 'publictech-core' ),
		];
		if ( isset( $messages[ $status ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( $messages[ $status ] )
			);
		}
	}

	private function render_mode_panel(): void {
		$mode = External_Screenshots::provider_mode();
		$modes = [
			'auto'          => __( 'Auto — ApiFlash first, GCP Cloud Run fallback when quota is exhausted', 'publictech-core' ),
			'apiflash_only' => __( 'ApiFlash only — errors if quota is exhausted', 'publictech-core' ),
			'gcp_only'      => __( 'GCP Cloud Run only — skips ApiFlash entirely', 'publictech-core' ),
		];
		?>
		<h2><?php esc_html_e( 'Provider Mode', 'publictech-core' ); ?></h2>
		<form method="post" action="<?php echo esc_url( $this->base_url() ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="pt_ext_ss_action" value="save_mode" />
			<fieldset>
				<?php foreach ( $modes as $val => $label ) : ?>
					<label style="display:block;margin-bottom:6px">
						<input type="radio" name="pt_screenshot_mode" value="<?php echo esc_attr( $val ); ?>"
							<?php checked( $mode, $val ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<?php submit_button( __( 'Save mode', 'publictech-core' ), 'secondary', 'submit', false, [ 'style' => 'margin-top:8px' ] ); ?>
		</form>
		<?php
	}

	private function render_status_panel(): void {
		$af_ok    = ApiFlash::is_configured();
		$gcp_ok   = GCP_Screenshot::is_configured();
		$active   = External_Screenshots::active_provider();
		$quota    = $af_ok ? ApiFlash::quota() : null;
		$af_quota = is_array( $quota ) ? (int) $quota['remaining'] . ' remaining' : ( is_wp_error( $quota ) ? 'error' : '—' );

		$status_dot = static function ( bool $ok ): string {
			$color = $ok ? '#00a32a' : '#d63638';
			$label = $ok ? 'Configured' : 'Not configured';
			return '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . $color . ';margin-right:6px"></span>' . esc_html( $label );
		};
		?>
		<h2 style="margin-top:24px"><?php esc_html_e( 'Provider Status', 'publictech-core' ); ?></h2>
		<table class="widefat striped" style="max-width:640px">
			<thead>
				<tr>
					<th style="width:160px"><?php esc_html_e( 'Provider', 'publictech-core' ); ?></th>
					<th><?php esc_html_e( 'Status', 'publictech-core' ); ?></th>
					<th><?php esc_html_e( 'Quota / Notes', 'publictech-core' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td>
						<strong>ApiFlash</strong>
						<?php if ( 'apiflash' === $active ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color:#00a32a;vertical-align:-3px" title="<?php esc_attr_e( 'Active provider', 'publictech-core' ); ?>"></span>
						<?php endif; ?>
					</td>
					<td><?php echo wp_kses_post( $status_dot( $af_ok ) ); ?></td>
					<td><?php echo esc_html( $af_quota ); ?></td>
				</tr>
				<tr>
					<td>
						<strong>GCP Cloud Run</strong>
						<?php if ( 'gcp' === $active ) : ?>
							<span class="dashicons dashicons-yes-alt" style="color:#00a32a;vertical-align:-3px" title="<?php esc_attr_e( 'Active provider', 'publictech-core' ); ?>"></span>
						<?php endif; ?>
					</td>
					<td><?php echo wp_kses_post( $status_dot( $gcp_ok ) ); ?></td>
					<td><?php esc_html_e( 'No quota (self-hosted)', 'publictech-core' ); ?></td>
				</tr>
			</tbody>
		</table>
		<?php if ( 'none' === $active ) : ?>
			<div class="notice notice-warning inline" style="margin-top:8px"><p>
				<?php esc_html_e( 'No screenshot provider is active for the current mode. Configure at least one provider below.', 'publictech-core' ); ?>
			</p></div>
		<?php endif; ?>
		<?php
	}

	private function render_apiflash_panel(): void {
		$af_ok    = ApiFlash::is_configured();
		$quota    = $af_ok ? ApiFlash::quota() : null;
		$is_const = ApiFlash::key_is_constant();
		$hint     = ApiFlash::key_hint();
		?>
		<hr style="margin:28px 0 20px" />
		<h2><?php esc_html_e( 'ApiFlash', 'publictech-core' ); ?></h2>
		<p class="description">
			<?php
			printf(
				/* translators: %s: link to ApiFlash dashboard */
				wp_kses( __( 'Monthly quota SaaS — <a href="%s" target="_blank" rel="noopener">apiflash.com</a>. Free tier: 100 screenshots/month.', 'publictech-core' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ),
				'https://apiflash.com/dashboard'
			);
			?>
		</p>

		<?php $this->render_quota_block( $quota ); ?>

		<?php if ( $is_const ) : ?>
			<p><?php esc_html_e( 'Access key is pinned by PUBLICTECH_APIFLASH_KEY in wp-config.php.', 'publictech-core' ); ?>
			<code><?php echo esc_html( $hint ); ?></code></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $this->base_url() ); ?>" style="margin-top:12px">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="pt_ext_ss_action" value="save_apiflash_key" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row" style="width:180px"><label for="pt_apiflash_key"><?php esc_html_e( 'ApiFlash access key', 'publictech-core' ); ?></label></th>
						<td>
							<input id="pt_apiflash_key" name="pt_apiflash_key" type="password" class="regular-text"
								value="<?php echo esc_attr( ApiFlash::access_key() ); ?>" autocomplete="off" spellcheck="false" />
							<?php if ( '' !== $hint ) : ?>
								<p class="description">
									<?php
									/* translators: %s: masked key hint */
									echo esc_html( sprintf( __( 'Current: %s', 'publictech-core' ), $hint ) );
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save ApiFlash key', 'publictech-core' ), 'secondary' ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array{limit:int,remaining:int,reset:int,fetched:int}|\WP_Error|null $quota
	 */
	private function render_quota_block( $quota ): void {
		if ( null === $quota ) {
			echo '<p>' . esc_html__( 'Configure an ApiFlash access key to view quota.', 'publictech-core' ) . '</p>';
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
		$pct       = $limit > 0 ? min( 100, round( $used / $limit * 100 ) ) : 0;
		$color     = $pct >= 90 ? '#d63638' : ( $pct >= 75 ? '#dba617' : '#00a32a' );
		$reset     = (int) $quota['reset'];
		?>
		<table class="widefat striped" style="max-width:560px;margin-top:8px">
			<tbody>
				<tr><th style="width:160px"><?php esc_html_e( 'Plan limit', 'publictech-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $limit ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Used', 'publictech-core' ); ?></th><td><?php echo esc_html( number_format_i18n( $used ) ); ?> (<?php echo esc_html( $pct ); ?>%)</td></tr>
				<tr><th><?php esc_html_e( 'Remaining', 'publictech-core' ); ?></th><td><strong><?php echo esc_html( number_format_i18n( $remaining ) ); ?></strong></td></tr>
				<tr>
					<th><?php esc_html_e( 'Resets', 'publictech-core' ); ?></th>
					<td>
						<?php
						if ( $reset > 0 ) {
							echo esc_html( wp_date( 'Y-m-d', $reset ) );
							if ( $reset > time() ) {
								/* translators: %s: human-readable time difference */
								echo ' — ' . esc_html( sprintf( __( 'in %s', 'publictech-core' ), human_time_diff( time(), $reset ) ) );
							}
						} else {
							echo '—';
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<div style="max-width:560px;margin:8px 0">
			<div style="background:#e0e0e0;border-radius:4px;height:14px;overflow:hidden">
				<div style="width:<?php echo esc_attr( $pct ); ?>%;background:<?php echo esc_attr( $color ); ?>;height:100%"></div>
			</div>
		</div>
		<p class="description">
			<?php
			/* translators: %s: human-readable time difference */
			echo esc_html( sprintf( __( 'Cached; last fetched %s ago.', 'publictech-core' ), human_time_diff( (int) $quota['fetched'], time() ) ) );
			?>
		</p>
		<?php $this->render_refresh_button(); ?>
		<?php
	}

	private function render_refresh_button(): void {
		?>
		<form method="post" action="<?php echo esc_url( $this->base_url() ); ?>" style="margin:4px 0">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="pt_ext_ss_action" value="refresh_quota" />
			<?php submit_button( __( 'Refresh quota', 'publictech-core' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	private function render_gcp_panel(): void {
		$key_const = GCP_Screenshot::key_is_constant();
		$url_const = GCP_Screenshot::url_is_constant();
		$key_hint  = GCP_Screenshot::key_hint();
		$cur_url   = GCP_Screenshot::base_url();
		?>
		<hr style="margin:28px 0 20px" />
		<h2><?php esc_html_e( 'GCP Cloud Run', 'publictech-core' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Self-hosted Puppeteer screenshot API. No quota limits — usage is bounded only by Cloud Run instance capacity (429 when queue is full).', 'publictech-core' ); ?></p>

		<?php if ( $key_const ) : ?>
			<p><?php esc_html_e( 'API key is pinned by PUBLICTECH_GCP_SCREENSHOT_KEY in wp-config.php.', 'publictech-core' ); ?>
			<code><?php echo esc_html( $key_hint ); ?></code></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $this->base_url() ); ?>" style="margin-top:12px">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="pt_ext_ss_action" value="save_gcp_key" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row" style="width:180px"><label for="pt_gcp_key"><?php esc_html_e( 'GCP API key', 'publictech-core' ); ?></label></th>
						<td>
							<input id="pt_gcp_key" name="pt_gcp_key" type="password" class="regular-text"
								value="<?php echo esc_attr( GCP_Screenshot::access_key() ); ?>" autocomplete="off" spellcheck="false" />
							<?php if ( '' !== $key_hint ) : ?>
								<p class="description">
									<?php
									/* translators: %s: masked key hint */
									echo esc_html( sprintf( __( 'Current: %s', 'publictech-core' ), $key_hint ) );
									?>
								</p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Passed as the api_key query parameter on every capture request. Never sent to the browser.', 'publictech-core' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save GCP key', 'publictech-core' ), 'secondary' ); ?>
			</form>
		<?php endif; ?>

		<?php if ( $url_const ) : ?>
			<p><?php esc_html_e( 'Service URL is pinned by PUBLICTECH_GCP_SCREENSHOT_URL in wp-config.php.', 'publictech-core' ); ?>
			<code><?php echo esc_html( $cur_url ); ?></code></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( $this->base_url() ); ?>" style="margin-top:4px">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="pt_ext_ss_action" value="save_gcp_url" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row" style="width:180px"><label for="pt_gcp_url"><?php esc_html_e( 'Service URL', 'publictech-core' ); ?></label></th>
						<td>
							<input id="pt_gcp_url" name="pt_gcp_url" type="url" class="regular-text"
								value="<?php echo esc_attr( $cur_url ); ?>" />
							<p class="description"><?php esc_html_e( 'Base URL of your Cloud Run service. Leave blank to use the default.', 'publictech-core' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save service URL', 'publictech-core' ), 'secondary' ); ?>
			</form>
		<?php endif; ?>

		<?php if ( GCP_Screenshot::is_configured() ) : ?>
			<?php $health = GCP_Screenshot::health_check(); ?>
			<p>
				<strong><?php esc_html_e( 'Health:', 'publictech-core' ); ?></strong>
				<?php if ( $health['ok'] ) : ?>
					<span style="color:#00a32a">&#10003;</span> <?php echo esc_html( $health['message'] ); ?>
				<?php else : ?>
					<span style="color:#d63638">&#10007;</span> <?php echo esc_html( $health['message'] ); ?>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<?php
	}

	private function render_test_panel(): void {
		$result  = $this->take_test();
		$default = home_url( '/' );
		?>
		<hr style="margin:28px 0 20px" />
		<h2><?php esc_html_e( 'Capture test', 'publictech-core' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Takes a live screenshot to confirm the provider works end-to-end. Each ApiFlash capture consumes one quota unit.', 'publictech-core' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->base_url() ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="pt_ext_ss_action" value="capture_test" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row" style="width:160px"><label for="pt_test_url"><?php esc_html_e( 'URL to capture', 'publictech-core' ); ?></label></th>
					<td><input id="pt_test_url" name="pt_test_url" type="url" class="regular-text" style="width:420px" value="<?php echo esc_attr( $default ); ?>" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="pt_test_provider"><?php esc_html_e( 'Provider', 'publictech-core' ); ?></label></th>
					<td>
						<select id="pt_test_provider" name="pt_test_provider">
							<option value="auto"><?php esc_html_e( 'Auto (follow mode setting)', 'publictech-core' ); ?></option>
							<option value="apiflash"><?php esc_html_e( 'ApiFlash', 'publictech-core' ); ?></option>
							<option value="gcp"><?php esc_html_e( 'GCP Cloud Run', 'publictech-core' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Run capture', 'publictech-core' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
		if ( null === $result ) {
			return;
		}
		$provider_label = isset( $result['provider'] ) ? ' (' . esc_html( $result['provider'] ) . ')' : '';
		if ( ! empty( $result['ok'] ) ) {
			echo '<div class="notice notice-success inline"><p>'
				. esc_html__( 'Capture succeeded', 'publictech-core' )
				. wp_kses_post( $provider_label )
				. '</p></div>';
			if ( ! empty( $result['image_url'] ) ) {
				printf(
					'<p><img src="%s" alt="%s" style="max-width:600px;height:auto;border:1px solid #ccd0d4;display:block;margin-top:8px" /></p>',
					esc_url( $result['image_url'] ),
					esc_attr__( 'Capture preview', 'publictech-core' )
				);
			} elseif ( ! empty( $result['image_data'] ) ) {
				printf(
					'<p><img src="%s" alt="%s" style="max-width:600px;height:auto;border:1px solid #ccd0d4;display:block;margin-top:8px" /></p>',
					esc_attr( $result['image_data'] ),
					esc_attr__( 'Capture preview', 'publictech-core' )
				);
			}
		} else {
			$detail = isset( $result['code'] ) && $result['code']
				/* translators: 1: HTTP status code, 2: error message */
				? sprintf( __( 'HTTP %1$d — %2$s', 'publictech-core' ), (int) $result['code'], (string) ( $result['message'] ?? '' ) )
				: (string) ( $result['message'] ?? __( 'Capture failed.', 'publictech-core' ) );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $detail ) . wp_kses_post( $provider_label ) . '</p></div>';
		}
	}

	private function render_reference(): void {
		?>
		<hr style="margin:28px 0 20px" />
		<h2><?php esc_html_e( 'Reference', 'publictech-core' ); ?></h2>

		<h3><?php esc_html_e( 'ApiFlash', 'publictech-core' ); ?></h3>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr><th style="width:80px"><?php esc_html_e( 'Code', 'publictech-core' ); ?></th><th><?php esc_html_e( 'Meaning', 'publictech-core' ); ?></th></tr></thead>
			<tbody>
				<?php
				$af_errors = [
					'400' => __( 'Bad Request — invalid parameters or target URL.', 'publictech-core' ),
					'401' => __( 'Unauthorized — access key is invalid or revoked.', 'publictech-core' ),
					'402' => __( 'Payment Required — monthly quota exhausted; system falls back to GCP in auto mode.', 'publictech-core' ),
					'403' => __( 'Forbidden — current plan does not support a requested feature.', 'publictech-core' ),
					'429' => __( 'Too Many Requests — rate limit hit (20 req/s, burst 400).', 'publictech-core' ),
					'500' => __( 'Internal Server Error — ApiFlash failed to handle the capture.', 'publictech-core' ),
				];
				foreach ( $af_errors as $code => $desc ) :
					?>
					<tr><td><code><?php echo esc_html( $code ); ?></code></td><td><?php echo esc_html( $desc ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3 style="margin-top:16px"><?php esc_html_e( 'GCP Cloud Run', 'publictech-core' ); ?></h3>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr><th style="width:80px"><?php esc_html_e( 'Code', 'publictech-core' ); ?></th><th><?php esc_html_e( 'Meaning', 'publictech-core' ); ?></th></tr></thead>
			<tbody>
				<?php
				$gcp_errors = [
					'200' => __( 'Success — body is binary PNG/JPEG.', 'publictech-core' ),
					'401' => __( 'Missing or invalid API key.', 'publictech-core' ),
					'403' => __( 'URL blocked by allowlist.', 'publictech-core' ),
					'429' => __( 'Server queue full (max 6 pending) — retry in a few seconds.', 'publictech-core' ),
					'500' => __( 'Capture failed (page crash or timeout) — try adding plainPuppeteer=true.', 'publictech-core' ),
				];
				foreach ( $gcp_errors as $code => $desc ) :
					?>
					<tr><td><code><?php echo esc_html( $code ); ?></code></td><td><?php echo esc_html( $desc ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3 style="margin-top:16px"><?php esc_html_e( 'GCP-specific capture parameters', 'publictech-core' ); ?></h3>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr><th style="width:160px"><?php esc_html_e( 'Parameter', 'publictech-core' ); ?></th><th style="width:80px"><?php esc_html_e( 'Default', 'publictech-core' ); ?></th><th><?php esc_html_e( 'Description', 'publictech-core' ); ?></th></tr></thead>
			<tbody>
				<?php
				$gcp_params = [
					'scaleFactor'    => [ '2', __( 'Device pixel ratio (1 = no retina scaling).', 'publictech-core' ) ],
					'fullPage'       => [ 'false', __( 'Capture the full scrollable page height.', 'publictech-core' ) ],
					'delay'          => [ '0', __( 'Seconds to wait after page load before capturing.', 'publictech-core' ) ],
					'timeout'        => [ '20', __( 'Page load timeout in seconds.', 'publictech-core' ) ],
					'darkMode'       => [ 'false', __( 'Emulate dark colour scheme.', 'publictech-core' ) ],
					'hideElements'   => [ '—', __( 'CSS selector — hide matching elements before capture.', 'publictech-core' ) ],
					'plainPuppeteer' => [ 'false', __( 'Fallback render mode for pages that crash the main renderer.', 'publictech-core' ) ],
				];
				foreach ( $gcp_params as $param => [ $default, $desc ] ) :
					?>
					<tr><td><code><?php echo esc_html( $param ); ?></code></td><td><?php echo esc_html( $default ); ?></td><td><?php echo esc_html( $desc ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}

<?php
/**
 * WordPress admin settings page for reCAPTCHA v3 configuration.
 *
 * Adds Settings → PT Captcha in the WP admin.
 * Stores: site key, secret key, score threshold, test mode toggle.
 * Shows which pages currently use invisible captcha.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Captcha_Admin {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'admin_menu',    [ $this, 'add_menu' ] );
		add_action( 'admin_init',    [ $this, 'register_settings' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_saved_notice' ] );
	}

	// ── Menu ──────────────────────────────────────────────────────────────────

	public function add_menu(): void {
		add_options_page(
			__( 'PT Captcha Settings', 'publictech-core' ),
			__( 'PT Captcha',          'publictech-core' ),
			'manage_options',
			'pt-captcha',
			[ $this, 'render_page' ]
		);
	}

	// ── Settings API ──────────────────────────────────────────────────────────

	public function register_settings(): void {
		$opts = [
			Captcha::OPT_SITE_KEY,
			Captcha::OPT_SECRET_KEY,
			Captcha::OPT_THRESHOLD,
			Captcha::OPT_TEST_MODE,
		];
		foreach ( $opts as $opt ) {
			register_setting( 'pt_captcha_group', $opt, [ 'sanitize_callback' => [ $this, 'sanitize_' . $opt ] ] );
		}
	}

	// Individual sanitizers registered above via dynamic method names.
	public function sanitize_pt_recaptcha_v3_site_key( $v ): string   { return sanitize_text_field( (string) $v ); }
	public function sanitize_pt_recaptcha_v3_secret_key( $v ): string { return sanitize_text_field( (string) $v ); }
	public function sanitize_pt_recaptcha_v3_threshold( $v ): float   { return max( 0.0, min( 1.0, (float) $v ) ); }
	public function sanitize_pt_recaptcha_v3_test_mode( $v ): bool    { return (bool) $v; }

	// ── Admin notices ─────────────────────────────────────────────────────────

	public function maybe_show_saved_notice(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_pt-captcha' !== $screen->id ) {
			return;
		}
		if ( isset( $_GET['settings-updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Captcha settings saved.', 'publictech-core' ) . '</p></div>';
		}
	}

	// ── Page render ──────────────────────────────────────────────────────────

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$site_key   = Captcha::site_key();
		$secret_key = (string) get_option( Captcha::OPT_SECRET_KEY, '' );
		$threshold  = (float)  get_option( Captcha::OPT_THRESHOLD, 0.5 );
		$test_mode  = (bool)   get_option( Captcha::OPT_TEST_MODE, false );
		$configured = '' !== $site_key && '' !== $secret_key;

		// Pages that use invisible captcha — add to this list as new forms are built.
		$protected_pages = [
			'/data-explorer' => __( 'Data Explorer — Run Query / NL Search', 'publictech-core' ),
			'/ai-console'    => __( 'AI Console — Query submission',          'publictech-core' ),
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PT Captcha Settings', 'publictech-core' ); ?></h1>

			<?php if ( $configured ) : ?>
				<div class="notice notice-success inline"><p>
					<strong><?php esc_html_e( 'reCAPTCHA v3 is active.', 'publictech-core' ); ?></strong>
					<?php if ( $test_mode ) : ?>
						<?php esc_html_e( 'Test mode: scores are logged but users are never blocked.', 'publictech-core' ); ?>
					<?php endif; ?>
				</p></div>
			<?php else : ?>
				<div class="notice notice-warning inline"><p>
					<?php esc_html_e( 'reCAPTCHA is not yet configured. Enter your keys below to activate it.', 'publictech-core' ); ?>
					<?php printf(
						/* translators: %s: link to Google reCAPTCHA admin */
						wp_kses( __( 'Get keys at <a href="%s" target="_blank" rel="noopener">Google reCAPTCHA Admin</a>.', 'publictech-core' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ),
						'https://www.google.com/recaptcha/admin/create'
					); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'pt_captcha_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pt_v3_site_key"><?php esc_html_e( 'Site Key (public)', 'publictech-core' ); ?></label></th>
						<td>
							<input id="pt_v3_site_key" name="<?php echo esc_attr( Captcha::OPT_SITE_KEY ); ?>"
							       type="text" class="regular-text"
							       value="<?php echo esc_attr( $site_key ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Embedded in page source. Used by the browser to call reCAPTCHA.', 'publictech-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pt_v3_secret_key"><?php esc_html_e( 'Secret Key (private)', 'publictech-core' ); ?></label></th>
						<td>
							<input id="pt_v3_secret_key" name="<?php echo esc_attr( Captcha::OPT_SECRET_KEY ); ?>"
							       type="password" class="regular-text"
							       value="<?php echo esc_attr( $secret_key ? str_repeat( '•', 20 ) : '' ); ?>"
							       autocomplete="new-password"
							       placeholder="<?php echo $secret_key ? esc_attr__( '(saved — enter new value to change)', 'publictech-core' ) : ''; ?>" />
							<p class="description"><?php esc_html_e( 'Never exposed to the browser. Used server-side to verify tokens.', 'publictech-core' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pt_v3_threshold"><?php esc_html_e( 'Score threshold', 'publictech-core' ); ?></label></th>
						<td>
							<input id="pt_v3_threshold" name="<?php echo esc_attr( Captcha::OPT_THRESHOLD ); ?>"
							       type="number" step="0.05" min="0" max="1" style="width:80px"
							       value="<?php echo esc_attr( number_format( $threshold, 2 ) ); ?>" />
							<p class="description">
								<?php esc_html_e( '0.0–1.0. Requests scoring below this are rejected. Google recommends 0.5. Lower = less strict.', 'publictech-core' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test mode', 'publictech-core' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Captcha::OPT_TEST_MODE ); ?>"
								       value="1" <?php checked( $test_mode ); ?> />
								<?php esc_html_e( 'Log scores but never block users (safe for initial setup)', 'publictech-core' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Captcha Settings', 'publictech-core' ) ); ?>
			</form>

			<!-- Protected pages -->
			<h2><?php esc_html_e( 'Pages using invisible captcha', 'publictech-core' ); ?></h2>
			<table class="widefat striped" style="max-width:600px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Page / Form', 'publictech-core' ); ?></th>
						<th><?php esc_html_e( 'Status', 'publictech-core' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $protected_pages as $path => $label ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( home_url( $path ) ); ?>" target="_blank">
									<?php echo esc_html( $label ); ?>
								</a>
							</td>
							<td>
								<?php if ( $configured ) : ?>
									<span style="color:#00a32a">● <?php esc_html_e( 'Active', 'publictech-core' ); ?></span>
								<?php else : ?>
									<span style="color:#d63638">○ <?php esc_html_e( 'Keys not set', 'publictech-core' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Test -->
			<?php if ( $configured ) : ?>
			<h2><?php esc_html_e( 'Test your keys', 'publictech-core' ); ?></h2>
			<p><?php esc_html_e( 'Click the button below. A test token will be generated and verified server-side. The score will appear here.', 'publictech-core' ); ?></p>
			<button id="pt-captcha-test-btn" class="button button-secondary">
				<?php esc_html_e( 'Run test', 'publictech-core' ); ?>
			</button>
			<span id="pt-captcha-test-result" style="margin-left:12px;font-family:monospace"></span>
			<script>
			(function () {
				var siteKey = <?php echo wp_json_encode( $site_key ); ?>;
				var nonce   = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
				var rest    = <?php echo wp_json_encode( esc_url_raw( rest_url( PUBLICTECH_CORE_REST_NS ) ) ); ?>;
				document.getElementById('pt-captcha-test-btn').addEventListener('click', function () {
					var el = document.getElementById('pt-captcha-test-result');
					el.textContent = '…';
					if (typeof grecaptcha === 'undefined') {
						el.textContent = 'reCAPTCHA script not loaded on this page. Test from the Data Explorer instead.';
						return;
					}
					grecaptcha.ready(function () {
						grecaptcha.execute(siteKey, { action: 'pt_captcha_test' }).then(function (token) {
							fetch(rest + '/captcha/verify', {
								method: 'POST',
								credentials: 'same-origin',
								headers: { 'Content-Type': 'application/json', 'x-wp-nonce': nonce },
								body: JSON.stringify({ token: token, action: 'pt_captcha_test' }),
							}).then(function (r) { return r.json(); }).then(function (d) {
								el.textContent = d.score != null
									? 'Score: ' + d.score + (d.passed ? ' ✓ passed' : ' ✗ failed (below threshold)')
									: ('Error: ' + (d.message || 'unknown'));
							}).catch(function (e) { el.textContent = 'Fetch error: ' + e.message; });
						});
					});
				});
			})();
			</script>
			<?php endif; ?>
		</div>
		<?php
	}
}

<?php
/**
 * Settings page: Settings → GovData Ads Client.
 *
 * Stores hub URL and API key in wp_options. The API key is stored in plain
 * text — operators on sites with a secrets manager or WP encryption plugin
 * can filter gac_get_api_key to decrypt it at read time.
 *
 * @package GovAdsClient
 */

namespace GovAdsClient;

defined( 'ABSPATH' ) || exit;

final class Settings {

	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'admin_menu',            [ $this, 'add_page' ] );
		add_action( 'admin_init',            [ $this, 'register_settings' ] );
		add_action( 'admin_post_gac_test_connection', [ $this, 'test_connection' ] );
	}

	public function add_page(): void {
		add_options_page(
			__( 'GovData Ads Client', 'govdata-ads-client' ),
			__( 'GovData Ads Client', 'govdata-ads-client' ),
			'manage_options',
			'govdata-ads-client',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'gac_settings', 'gac_hub_url',  [ 'sanitize_callback' => 'esc_url_raw' ] );
		register_setting( 'gac_settings', 'gac_api_key',  [ 'sanitize_callback' => 'sanitize_text_field' ] );

		add_settings_section( 'gac_main', __( 'Connection', 'govdata-ads-client' ), '__return_empty_string', 'govdata-ads-client' );

		add_settings_field(
			'gac_hub_url',
			__( 'Hub URL', 'govdata-ads-client' ),
			[ $this, 'field_hub_url' ],
			'govdata-ads-client',
			'gac_main'
		);
		add_settings_field(
			'gac_api_key',
			__( 'API Key', 'govdata-ads-client' ),
			[ $this, 'field_api_key' ],
			'govdata-ads-client',
			'gac_main'
		);
	}

	public function field_hub_url(): void {
		$val = esc_url( (string) get_option( 'gac_hub_url', '' ) );
		echo '<input type="url" name="gac_hub_url" class="regular-text" value="' . esc_attr( $val ) . '" placeholder="https://govdata.ca">';
		echo '<p class="description">' . esc_html__( 'The base URL of your GovData Ad Services hub site.', 'govdata-ads-client' ) . '</p>';
	}

	public function field_api_key(): void {
		$val = (string) get_option( 'gac_api_key', '' );
		echo '<input type="password" name="gac_api_key" class="regular-text" value="' . esc_attr( $val ) . '" autocomplete="new-password">';
		echo '<p class="description">' . esc_html__( 'API key generated in GovData Ads → API Keys on the hub site. Starts with gads_.', 'govdata-ads-client' ) . '</p>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GovData Ads Client Settings', 'govdata-ads-client' ); ?></h1>

			<?php if ( isset( $_GET['connection'] ) ) : // phpcs:ignore WordPress.Security ?>
				<?php if ( 'ok' === $_GET['connection'] ) : // phpcs:ignore WordPress.Security ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connection successful! The hub responded correctly.', 'govdata-ads-client' ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Connection failed. Check the hub URL and API key.', 'govdata-ads-client' ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'gac_settings' );
				do_settings_sections( 'govdata-ads-client' );
				submit_button();
				?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Test Connection', 'govdata-ads-client' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'gac_test_connection' ); ?>
				<input type="hidden" name="action" value="gac_test_connection">
				<?php submit_button( __( 'Test Connection', 'govdata-ads-client' ), 'secondary' ); ?>
			</form>

			<hr>
			<h2><?php esc_html_e( 'Shortcode Usage', 'govdata-ads-client' ); ?></h2>
			<p><?php esc_html_e( 'Place ads anywhere using the shortcode:', 'govdata-ads-client' ); ?></p>
			<code>[govads zone="zone_slug"]</code>
			<p class="description">
				<?php esc_html_e( 'Zone slugs are provided by the hub site administrator. Find them in GovData Ads → API Keys on the hub.', 'govdata-ads-client' ); ?>
			</p>
		</div>
		<?php
	}

	public function test_connection(): void {
		check_admin_referer( 'gac_test_connection' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'govdata-ads-client' ) );
		}

		$hub_url = (string) get_option( 'gac_hub_url', '' );
		$api_key = (string) get_option( 'gac_api_key', '' );

		$ok = false;
		if ( $hub_url && $api_key ) {
			$url      = trailingslashit( $hub_url ) . 'wp-json/govads/v1/serve?zone=__test__';
			$response = wp_remote_get( $url, [ 'timeout' => 5, 'headers' => [ 'X-GovAds-Site-Key' => $api_key ] ] );
			// 200 (ad found) or 204 (no ad in zone) both mean the connection works.
			$code = wp_remote_retrieve_response_code( $response );
			$ok   = ! is_wp_error( $response ) && in_array( (int) $code, [ 200, 204 ], true );
		}

		wp_redirect( admin_url( 'options-general.php?page=govdata-ads-client&connection=' . ( $ok ? 'ok' : 'fail' ) ) );
		exit;
	}
}

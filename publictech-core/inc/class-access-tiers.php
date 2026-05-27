<?php
/**
 * Paid access tier registry.
 *
 * Each tier defines a request budget (requests/minute, requests/day),
 * max row payload per response, allowed schemas, and whether the tier may
 * use the MCP server for long-form AI queries. Tiers map to WooCommerce
 * subscription products via the `pt_tier` user meta key written by the
 * billing layer; visitors with no subscription resolve to the free tier.
 *
 * The Throttle class consults these definitions on every request.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Access_Tiers {
	private static ?self $instance = null;

	public const META_TIER  = 'pt_tier';
	public const META_TOKEN = 'pt_mcp_token';

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_action( 'show_user_profile',        [ $this, 'render_admin_token_box' ] );
		add_action( 'edit_user_profile',        [ $this, 'render_admin_token_box' ] );
		add_action( 'personal_options_update',  [ $this, 'maybe_rotate_token' ] );
		add_action( 'edit_user_profile_update', [ $this, 'maybe_rotate_token' ] );
	}

	/**
	 * Tier catalog. Override at runtime via the `publictech_tiers` filter.
	 *
	 * @return array<string,array{
	 *   label:string, price_monthly:int,
	 *   rpm:int, rpd:int, max_rows:int,
	 *   schemas:array<int,string>|string,
	 *   mcp:bool, concurrent:int
	 * }>
	 */
	public function tiers(): array {
		$defaults = [
			// Unauthenticated visitors. Intentionally tight to discourage
			// drive-by scraping; signed-in free-tier users get the next bucket.
			'anonymous' => [
				'label' => 'Anonymous', 'price_monthly' => 0,
				'rpm' => 10, 'rpd' => 200, 'max_rows' => 100,
				'schemas' => [ 'pt-finance-01' ],
				'mcp' => false, 'concurrent' => 1,
			],
			'free' => [
				'label' => 'Free', 'price_monthly' => 0,
				'rpm' => 20, 'rpd' => 500, 'max_rows' => 500,
				'schemas' => [ 'pt-finance-01', 'pt-grants-02' ],
				'mcp' => false, 'concurrent' => 1,
			],
			'analyst' => [
				'label' => 'Analyst', 'price_monthly' => 19,
				'rpm' => 120, 'rpd' => 10_000, 'max_rows' => 5_000,
				'schemas' => '*', 'mcp' => true, 'concurrent' => 3,
			],
			'newsroom' => [
				'label' => 'Newsroom', 'price_monthly' => 79,
				'rpm' => 600, 'rpd' => 100_000, 'max_rows' => 50_000,
				'schemas' => '*', 'mcp' => true, 'concurrent' => 8,
			],
			'institutional' => [
				'label' => 'Institutional', 'price_monthly' => 499,
				'rpm' => 3_000, 'rpd' => 1_000_000, 'max_rows' => 250_000,
				'schemas' => '*', 'mcp' => true, 'concurrent' => 32,
			],
		];
		return apply_filters( 'publictech_tiers', $defaults );
	}

	/**
	 * Resolve the current tier slug for a user (or the anonymous viewer).
	 */
	public function user_tier( ?int $user_id ): string {
		if ( ! $user_id ) {
			return 'anonymous';
		}
		$slug = (string) get_user_meta( $user_id, self::META_TIER, true );
		return isset( $this->tiers()[ $slug ] ) ? $slug : 'free';
	}

	public function quota( ?int $user_id ): array {
		return $this->tiers()[ $this->user_tier( $user_id ) ];
	}

	/**
	 * Look up the user owning a given MCP bearer token.
	 *
	 * Performance: tokens are reverse-indexed in their own options row
	 * (pt_tok_<short_hash>) so resolution is an O(1) options-table read
	 * instead of a full-table scan over wp_usermeta.meta_value (unindexed).
	 * The full sha256 is also stored as the value to guard against
	 * collisions on the short hash.
	 */
	public function resolve_token( string $token ): ?int {
		if ( strlen( $token ) < 32 || ! ctype_alnum( str_replace( '_', '', $token ) ) ) {
			return null;
		}
		$full  = hash( 'sha256', $token );
		$short = substr( $full, 0, 16 );
		$row   = get_option( 'pt_tok_' . $short, null );
		if ( ! is_array( $row ) || ! hash_equals( (string) ( $row['hash'] ?? '' ), $full ) ) {
			return null;
		}
		return (int) ( $row['uid'] ?? 0 ) ?: null;
	}

	/**
	 * Store/rotate a token reverse-index entry, removing the prior one if any.
	 */
	private function index_token( int $user_id, string $plain ): void {
		$old = (string) get_user_meta( $user_id, self::META_TOKEN, true );
		if ( $old ) {
			delete_option( 'pt_tok_' . substr( $old, 0, 16 ) );
		}
		$full  = hash( 'sha256', $plain );
		$short = substr( $full, 0, 16 );
		update_user_meta( $user_id, self::META_TOKEN, $full );
		update_option( 'pt_tok_' . $short, [ 'uid' => $user_id, 'hash' => $full ], false );
	}

	public function render_admin_token_box( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		$tier   = $this->user_tier( $user->ID );
		$tiers  = $this->tiers();
		$has    = (bool) get_user_meta( $user->ID, self::META_TOKEN, true );
		wp_nonce_field( 'pt_token_' . $user->ID, '_pt_token_nonce' );
		?>
		<h2><?php esc_html_e( 'PublicTech Access', 'publictech-core' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="pt_tier"><?php esc_html_e( 'Tier', 'publictech-core' ); ?></label></th>
				<td>
					<select name="pt_tier" id="pt_tier">
						<?php foreach ( $tiers as $slug => $def ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $tier, $slug ); ?>>
								<?php echo esc_html( $def['label'] . ' — ' . $def['rpm'] . ' rpm / ' . $def['rpd'] . ' rpd' ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'MCP Token', 'publictech-core' ); ?></th>
				<td>
					<label><input type="checkbox" name="pt_rotate_token" value="1" /> <?php echo $has ? esc_html__( 'Rotate token', 'publictech-core' ) : esc_html__( 'Generate token', 'publictech-core' ); ?></label>
					<p class="description"><?php esc_html_e( 'The plaintext token will be shown once after save.', 'publictech-core' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function maybe_rotate_token( int $user_id ): void {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		check_admin_referer( 'pt_token_' . $user_id, '_pt_token_nonce' );

		if ( isset( $_POST['pt_tier'] ) ) {
			$tier = sanitize_key( wp_unslash( $_POST['pt_tier'] ) );
			if ( isset( $this->tiers()[ $tier ] ) ) {
				update_user_meta( $user_id, self::META_TIER, $tier );
			}
		}
		if ( ! empty( $_POST['pt_rotate_token'] ) ) {
			$plain = 'pt_' . bin2hex( random_bytes( 24 ) );
			$this->index_token( $user_id, $plain );
			set_transient( 'pt_token_show_' . $user_id, $plain, 120 );
		}
	}
}

add_action( 'admin_notices', static function () {
	$uid   = get_current_user_id();
	$token = $uid ? get_transient( 'pt_token_show_' . $uid ) : false;
	if ( ! $token ) {
		return;
	}
	delete_transient( 'pt_token_show_' . $uid );
	printf(
		'<div class="notice notice-success"><p><strong>%s</strong> <code>%s</code></p></div>',
		esc_html__( 'New MCP token (copy now; will not be shown again):', 'publictech-core' ),
		esc_html( $token )
	);
} );

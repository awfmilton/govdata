<?php
/**
 * Top status rail + header (mirrors the rail used in Audit Terminal v2.html).
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$pt_health  = \PublicTech\Core\Multi_DB::instance()->aliases();
$pt_user    = wp_get_current_user();
$pt_initial = $pt_user && $pt_user->ID ? strtoupper( substr( $pt_user->display_name, 0, 2 ) ) : '··';
?>
<div class="rail" role="status" aria-label="<?php esc_attr_e( 'System status', 'publictech-terminal' ); ?>">
	<div class="l">
		<span><span class="dot"></span><?php esc_html_e( 'SYSTEM ONLINE', 'publictech-terminal' ); ?></span>
		<?php if ( $pt_health ) : ?>
			<span class="pt-tip-ink"
				data-pt-tip-title="<?php esc_attr_e( 'Connected clusters', 'publictech-terminal' ); ?>"
				data-pt-tip="<?php esc_attr_e( 'Live, read-only audit-data clusters currently online. Each is an isolated MySQL instance kept fully separate from the site database.', 'publictech-terminal' ); ?>"><?php esc_html_e( 'DB-CLUSTER:', 'publictech-terminal' ); ?>
				<span style="color:var(--ink-0)"><?php echo esc_html( implode( ' · ', $pt_health ) ); ?></span>
			</span>
		<?php endif; ?>
		<span><?php esc_html_e( 'JSON STREAM:', 'publictech-terminal' ); ?> <span style="color:var(--pos)"><?php esc_html_e( 'OK', 'publictech-terminal' ); ?></span></span>
	</div>
	<div class="r">
		<span><?php esc_html_e( 'BUILD', 'publictech-terminal' ); ?>
			<span style="color:var(--ink-0)"><?php echo esc_html( PUBLICTECH_TERMINAL_VERSION ); ?></span>
		</span>
		<span><?php echo esc_html( gmdate( 'm.d.Y · H:i:s' ) ); ?> UTC</span>
	</div>
</div>

<header class="shell">
	<div class="brand">
		<div class="mark"><span></span></div>
		<h1><?php bloginfo( 'name' ); ?>
			<small><?php bloginfo( 'description' ); ?></small>
		</h1>
	</div>

	<nav class="tabs" id="tabs" aria-label="<?php esc_attr_e( 'Primary', 'publictech-terminal' ); ?>">
		<?php
		wp_nav_menu( [
			'theme_location' => 'primary',
			'container'      => false,
			'menu_class'     => 'pt-primary-menu',
			'fallback_cb'    => function () {
				$items = [
					'home'      => __( 'Home',       'publictech-terminal' ),
					'directory' => __( 'Directory',  'publictech-terminal' ),
					'profile'   => __( 'Profile',    'publictech-terminal' ),
					'stream'    => __( 'Stream',     'publictech-terminal' ),
					'ai'        => __( 'AI Console', 'publictech-terminal' ),
					'data'      => __( 'Data Store', 'publictech-terminal' ),
					'forum'     => __( 'Forum',      'publictech-terminal' ),
				];
				$i = 0;
				foreach ( $items as $slug => $label ) {
					printf(
						'<a href="%s"><span class="idx">%02d</span> %s</a>',
						esc_url( home_url( '/' . $slug ) ),
						$i++,
						esc_html( $label )
					);
				}
			},
		] );
		?>
	</nav>

	<div class="header-r">
		<?php if ( is_user_logged_in() ) :
			$pt_tiers    = \PublicTech\Core\Access_Tiers::instance();
			$tier        = $pt_tiers->user_tier( get_current_user_id() );
			$pt_quota    = $pt_tiers->quota( get_current_user_id() );
			$pt_tip_tier = sprintf(
				/* translators: 1: requests per minute, 2: requests per day, 3: max rows per call */
				__( '%1$s requests/min · %2$s per day · up to %3$s rows per call.', 'publictech-terminal' ),
				number_format_i18n( (int) ( $pt_quota['rpm'] ?? 0 ) ),
				number_format_i18n( (int) ( $pt_quota['rpd'] ?? 0 ) ),
				number_format_i18n( (int) ( $pt_quota['max_rows'] ?? 0 ) )
			) . ' ' . ( ! empty( $pt_quota['mcp'] )
				? __( 'MCP API access and chart tools are unlocked.', 'publictech-terminal' )
				: __( 'Upgrade to unlock the MCP API and chart tools.', 'publictech-terminal' ) );
			?>
			<div class="pill pt-tip"
				data-pt-tip-title="<?php echo esc_attr( sprintf( /* translators: %s: tier name */ __( '%s tier', 'publictech-terminal' ), ucfirst( $tier ) ) ); ?>"
				data-pt-tip="<?php echo esc_attr( $pt_tip_tier ); ?>"><span class="dot" style="width:6px;height:6px;border-radius:50%;background:var(--pos);display:inline-block"></span>
				<?php echo esc_html( ucfirst( $tier ) ); ?> <b><?php esc_html_e( 'Tier', 'publictech-terminal' ); ?></b>
			</div>
		<?php endif; ?>
		<button class="htool pt-tip" id="pt-customizer-btn"
			aria-label="<?php esc_attr_e( 'Chart customizer', 'publictech-terminal' ); ?>"
			data-pt-tip-title="<?php esc_attr_e( 'Chart customizer', 'publictech-terminal' ); ?>"
			data-pt-tip="<?php esc_attr_e( 'Restyle every chart on the site — accent colour, curve, grid, fonts and motion. Changes preview live and save to your account.', 'publictech-terminal' ); ?>"
			aria-haspopup="dialog">◈</button>
		<button class="htool pt-tip" id="pt-theme-btn"
			aria-label="<?php esc_attr_e( 'Toggle light / dark theme', 'publictech-terminal' ); ?>"
			data-pt-tip-title="<?php esc_attr_e( 'Display theme', 'publictech-terminal' ); ?>"
			data-pt-tip="<?php esc_attr_e( 'Switch between the dark terminal and light reading themes. Your choice is remembered on this device.', 'publictech-terminal' ); ?>"
			aria-pressed="false">◐</button>
		<a class="avatar" href="<?php echo esc_url( get_permalink( get_page_by_path( 'profile' ) ) ?: home_url( '/profile' ) ); ?>"><?php echo esc_html( $pt_initial ); ?></a>
	</div>
</header>

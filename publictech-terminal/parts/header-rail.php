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
			<span><?php esc_html_e( 'DB-CLUSTER:', 'publictech-terminal' ); ?>
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
			$tier = \PublicTech\Core\Access_Tiers::instance()->user_tier( get_current_user_id() );
			?>
			<div class="pill"><span class="dot" style="width:6px;height:6px;border-radius:50%;background:var(--pos);display:inline-block"></span>
				<?php echo esc_html( ucfirst( $tier ) ); ?> <b><?php esc_html_e( 'Tier', 'publictech-terminal' ); ?></b>
			</div>
		<?php endif; ?>
		<button class="htool" id="pt-customizer-btn" title="<?php esc_attr_e( 'Chart customizer', 'publictech-terminal' ); ?>" aria-haspopup="dialog">◈</button>
		<button class="htool" id="pt-theme-btn" title="<?php esc_attr_e( 'Toggle light / dark', 'publictech-terminal' ); ?>" aria-pressed="false">◐</button>
		<a class="avatar" href="<?php echo esc_url( get_permalink( get_page_by_path( 'profile' ) ) ?: home_url( '/profile' ) ); ?>"><?php echo esc_html( $pt_initial ); ?></a>
	</div>
</header>

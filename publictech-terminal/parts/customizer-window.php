<?php
/**
 * In-page chart customizer drawer + scrim. Markup is intentionally close to
 * the prototype HTML so the existing CSS (`.customizer`, `.scrim`) applies
 * as-is. Behaviour lives in assets/js/chart-customizer-ui.js.
 *
 * @package PublicTech\Terminal
 */

defined( 'ABSPATH' ) || exit;

$palette = \PublicTech\Core\Chart_Customizer::PALETTES;
$accent  = get_theme_mod( 'pt_accent', '#00a0d2' );
?>
<div class="scrim" id="pt-scrim" aria-hidden="true"></div>
<aside class="customizer" id="pt-customizer" role="dialog" aria-label="<?php esc_attr_e( 'Chart customizer', 'publictech-terminal' ); ?>">
	<div class="hd">
		<h3><span style="color:var(--accent)">◈</span> <?php esc_html_e( 'Chart Customizer', 'publictech-terminal' ); ?></h3>
		<button class="x" id="pt-customizer-close" aria-label="<?php esc_attr_e( 'Close', 'publictech-terminal' ); ?>">✕</button>
	</div>
	<div class="body">
		<div class="sec">
			<h4><?php esc_html_e( 'Accent Color', 'publictech-terminal' ); ?> <span class="val" id="pt-cust-accent-val"><?php echo esc_html( $accent ); ?></span></h4>
			<div class="swatches">
				<?php foreach ( $palette as $hex ) : ?>
					<div class="swatch pt-swatch <?php echo ( $hex === $accent ) ? 'on' : ''; ?>" data-color="<?php echo esc_attr( $hex ); ?>" style="background:<?php echo esc_attr( $hex ); ?>;color:<?php echo esc_attr( $hex ); ?>"></div>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="sec">
			<h4><?php esc_html_e( 'Curve Style', 'publictech-terminal' ); ?></h4>
			<div class="segctl pt-seg" data-knob="curve">
				<button class="on" data-val="monotone"><?php esc_html_e( 'Smooth',  'publictech-terminal' ); ?></button>
				<button data-val="linear"><?php esc_html_e( 'Sharp',   'publictech-terminal' ); ?></button>
				<button data-val="step"><?php esc_html_e( 'Stepped',  'publictech-terminal' ); ?></button>
			</div>
		</div>

		<div class="sec">
			<h4><?php esc_html_e( 'Line Weight', 'publictech-terminal' ); ?> <span class="val" id="pt-cust-stroke-val">1.8 px</span></h4>
			<div class="slider-row"><input type="range" id="pt-cust-stroke" min="1" max="4" step="0.2" value="1.8" /></div>
		</div>

		<div class="sec">
			<h4><?php esc_html_e( 'Marker Size', 'publictech-terminal' ); ?> <span class="val" id="pt-cust-marker-val">3 px</span></h4>
			<div class="slider-row"><input type="range" id="pt-cust-marker" min="0" max="6" step="0.5" value="3" /></div>
		</div>

		<div class="sec">
			<h4><?php esc_html_e( 'Grid', 'publictech-terminal' ); ?></h4>
			<div class="segctl pt-seg" data-knob="grid">
				<button data-val="off"><?php esc_html_e( 'Off', 'publictech-terminal' ); ?></button>
				<button class="on" data-val="dotted"><?php esc_html_e( 'Dotted', 'publictech-terminal' ); ?></button>
				<button data-val="solid"><?php esc_html_e( 'Solid', 'publictech-terminal' ); ?></button>
			</div>
		</div>

		<div class="sec">
			<h4><?php esc_html_e( 'Area Fill', 'publictech-terminal' ); ?> <span class="val" id="pt-cust-area-val">35%</span></h4>
			<div class="slider-row"><input type="range" id="pt-cust-area" min="0" max="80" step="5" value="35" /></div>
		</div>

		<div class="sec">
			<h4><?php esc_html_e( 'Bar Style', 'publictech-terminal' ); ?></h4>
			<div class="segctl pt-seg" data-knob="bar">
				<button class="on" data-val="solid"><?php esc_html_e( 'Solid', 'publictech-terminal' ); ?></button>
				<button data-val="stripe"><?php esc_html_e( 'Striped', 'publictech-terminal' ); ?></button>
				<button data-val="outline"><?php esc_html_e( 'Outline', 'publictech-terminal' ); ?></button>
			</div>
		</div>

		<div class="sec">
			<h4><?php esc_html_e( 'Typography', 'publictech-terminal' ); ?></h4>
			<div class="segctl pt-seg" data-knob="font">
				<button class="on" data-val="Montserrat">Montserrat</button>
				<button data-val="JetBrains Mono">Mono</button>
				<button data-val="Georgia">Serif</button>
			</div>
		</div>

		<div class="sec">
			<h4><?php esc_html_e( 'Animation', 'publictech-terminal' ); ?></h4>
			<div class="segctl pt-seg" data-knob="anim">
				<button class="on" data-val="on"><?php esc_html_e( 'On', 'publictech-terminal' ); ?></button>
				<button data-val="off"><?php esc_html_e( 'Off', 'publictech-terminal' ); ?></button>
			</div>
		</div>

		<div class="sec" style="background:var(--bg-2)">
			<h4><?php esc_html_e( 'Live Preview', 'publictech-terminal' ); ?></h4>
			<div class="preview-mini" id="pt-customizer-preview" style="height:88px"></div>
		</div>
	</div>
	<div class="cust-actions">
		<button id="pt-customizer-reset"><?php esc_html_e( 'Reset', 'publictech-terminal' ); ?></button>
		<button class="primary" id="pt-customizer-save"><?php esc_html_e( 'Save', 'publictech-terminal' ); ?></button>
	</div>
</aside>

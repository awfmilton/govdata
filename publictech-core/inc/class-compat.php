<?php
/**
 * Compatibility shims for required third-party plugins.
 *
 *  - CSS Hero            — register our public CSS variables so they show up in
 *                          the visual editor; whitelist customizer selectors.
 *  - WP Hide & Security  — keep our REST namespace reachable even when the
 *    Enhancer Pro          plugin renames `wp-json`; cooperate with its
 *                          login-URL rewriter so MCP auth still resolves.
 *  - Ninja Forms         — let Ninja Forms own front-end form submissions
 *                          (forum reply boxes, "report a flag" forms) without
 *                          fighting our REST nonce strategy.
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class Compat {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_filter( 'cssheroplugin_selectors',   [ $this, 'cssh_selectors' ] );
		add_filter( 'cssheroplugin_properties',  [ $this, 'cssh_properties' ] );

		add_filter( 'wph/api_redirect_disallow', [ $this, 'wph_keep_rest_open' ] );
		add_filter( 'wph/replace_in_rest',       [ $this, 'wph_keep_rest_open' ] );

		add_action( 'ninja_forms_loaded',        [ $this, 'nf_register_actions' ] );
		add_filter( 'ninja_forms_field_settings_groups', [ $this, 'nf_field_settings_group' ] );
	}

	public function cssh_selectors( array $selectors ): array {
		return array_merge( $selectors, [
			'.mp-directory-container', '.mp-card', '.mp-portrait',
			'.audit-stream-table', '.audit-stream-table tr.flag',
			'.pt-chart-host', '.pt-customizer', '.pt-rail',
			'.publictech-hero', '.publictech-forum-thread',
		] );
	}

	public function cssh_properties( array $props ): array {
		return array_merge( $props, [
			'--accent', '--accent-soft', '--accent-glow',
			'--chart-font', '--chart-stroke', '--chart-marker', '--chart-area',
		] );
	}

	public function wph_keep_rest_open( $disallow ): array {
		$disallow   = is_array( $disallow ) ? $disallow : [];
		$disallow[] = '/wp-json/' . PUBLICTECH_CORE_REST_NS;
		$disallow[] = '/wp-json/' . PUBLICTECH_CORE_REST_NS . '/';
		return $disallow;
	}

	public function nf_register_actions(): void {
		if ( ! class_exists( '\Ninja_Forms' ) ) {
			return;
		}
		\Ninja_Forms()->actions['publictech_flag'] = new NF_Action_File_Flag();
	}

	public function nf_field_settings_group( array $groups ): array {
		$groups['publictech'] = [
			'id'       => 'publictech',
			'label'    => __( 'PublicTech', 'publictech-core' ),
			'settings' => [],
		];
		return $groups;
	}
}

if ( class_exists( '\NF_Abstracts_Action' ) ) {
	class NF_Action_File_Flag extends \NF_Abstracts_Action {
		protected $_name  = 'publictech_flag';
		protected $_tags  = [ 'publictech' ];
		protected $_timing = 'normal';
		protected $_priority = 10;

		public function process( $action_settings, $form_id, $data ) {
			$post_id = wp_insert_post( [
				'post_type'    => 'audit-flag',
				'post_status'  => 'pending',
				'post_title'   => wp_strip_all_tags( $action_settings['title'] ?? __( 'Untitled flag', 'publictech-core' ) ),
				'post_content' => wp_kses_post( $action_settings['body']  ?? '' ),
				'post_author'  => get_current_user_id(),
			] );
			if ( $post_id && ! is_wp_error( $post_id ) ) {
				update_post_meta( $post_id, 'txn_id',  sanitize_text_field( $action_settings['txn_id']  ?? '' ) );
				update_post_meta( $post_id, 'schema',  sanitize_key(        $action_settings['schema']  ?? '' ) );
			}
			return $data;
		}
	}
}

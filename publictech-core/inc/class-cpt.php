<?php
/**
 * Custom post types and taxonomies.
 *
 *  - member-parliament : one row per MP, indexed by FacetWP.
 *  - audit-flag        : community-filed flags on transactions.
 *  - forum-thread      : forum threads (acts as bbPress fallback if no forum plugin).
 *
 * @package PublicTech\Core
 */

namespace PublicTech\Core;

defined( 'ABSPATH' ) || exit;

final class CPT {
	private static ?self $instance = null;
	public static function instance(): self { return self::$instance ??= new self(); }

	private function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		register_post_type( 'member-parliament', [
			'labels' => [
				'name'          => __( 'Members of Parliament', 'publictech-core' ),
				'singular_name' => __( 'Member', 'publictech-core' ),
				'add_new_item'  => __( 'Add Member', 'publictech-core' ),
			],
			'public'              => true,
			'show_in_rest'        => true,
			'has_archive'         => 'directory',
			'menu_icon'           => 'dashicons-groups',
			'supports'            => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'excerpt' ],
			'rewrite'             => [ 'slug' => 'mp', 'with_front' => false ],
			'capability_type'     => 'post',
			'taxonomies'          => [ 'mp_party', 'mp_region', 'mp_committee' ],
			'show_in_graphql'     => false,
		] );

		register_post_type( 'audit-flag', [
			'labels'       => [ 'name' => __( 'Audit Flags', 'publictech-core' ), 'singular_name' => __( 'Audit Flag', 'publictech-core' ) ],
			'public'       => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-flag',
			'supports'     => [ 'title', 'editor', 'author', 'custom-fields' ],
			'rewrite'      => [ 'slug' => 'flag', 'with_front' => false ],
		] );

		register_post_type( 'forum-thread', [
			'labels'       => [ 'name' => __( 'Forum Threads', 'publictech-core' ), 'singular_name' => __( 'Thread', 'publictech-core' ) ],
			'public'       => true,
			'show_in_rest' => true,
			'has_archive'  => 'forum',
			'menu_icon'    => 'dashicons-format-chat',
			'supports'     => [ 'title', 'editor', 'author', 'comments', 'custom-fields' ],
			'rewrite'      => [ 'slug' => 'thread', 'with_front' => false ],
			'taxonomies'   => [ 'forum_category', 'forum_tag' ],
		] );

		register_taxonomy( 'mp_party',       'member-parliament', $this->tax_args( __( 'Party',     'publictech-core' ), 'party' ) );
		register_taxonomy( 'mp_region',      'member-parliament', $this->tax_args( __( 'Region',    'publictech-core' ), 'region' ) );
		register_taxonomy( 'mp_committee',   'member-parliament', $this->tax_args( __( 'Committee', 'publictech-core' ), 'committee' ) );
		register_taxonomy( 'forum_category', 'forum-thread',      $this->tax_args( __( 'Category',  'publictech-core' ), 'forum-cat', true ) );
		register_taxonomy( 'forum_tag',      'forum-thread',      $this->tax_args( __( 'Tag',       'publictech-core' ), 'forum-tag', false ) );
	}

	private function tax_args( string $label, string $slug, bool $hierarchical = false ): array {
		return [
			'labels'            => [ 'name' => $label ],
			'public'            => true,
			'hierarchical'      => $hierarchical,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => [ 'slug' => $slug ],
		];
	}
}

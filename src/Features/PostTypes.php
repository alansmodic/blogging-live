<?php
/**
 * Blogging Live post types.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;
use BloggingLive\Rest\PostsController;

defined( 'ABSPATH' ) || exit;

final class PostTypes implements Feature {
	public const BLOGGING_LIVE_POST_TYPE = 'blogging_live';
	public const ENTRY_POST_TYPE         = 'blogging_live_entry';

	public function register(): void {
		add_action( 'init', [ self::class, 'register_post_types' ], 5 );
	}

	public static function register_post_types(): void {
		register_post_type(
			self::BLOGGING_LIVE_POST_TYPE,
			[
				'labels'                => self::blogging_live_labels(),
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => true,
				'show_in_menu'          => true,
				'show_in_rest'          => true,
				'rest_controller_class' => PostsController::class,
				'menu_icon'             => 'dashicons-megaphone',
				'menu_position'         => 21,
				'supports'              => [ 'title', 'revisions' ],
				'capability_type'       => [ 'blogging_live', 'blogging_lives' ],
				'capabilities'          => [ 'create_posts' => 'do_not_allow' ],
				'map_meta_cap'          => true,
				'has_archive'           => false,
				'exclude_from_search'   => true,
				'rewrite'               => false,
			]
		);

		register_post_type(
			self::ENTRY_POST_TYPE,
			[
				'labels'                => self::entry_labels(),
				'public'                => false,
				'publicly_queryable'    => false,
				'show_ui'               => true,
				'show_in_menu'          => 'edit.php?post_type=' . self::BLOGGING_LIVE_POST_TYPE,
				'show_in_rest'          => true,
				'rest_controller_class' => PostsController::class,
				'supports'              => [ 'title', 'editor', 'author', 'revisions', 'thumbnail', 'custom-fields' ],
				'capability_type'       => [ 'blogging_live_entry', 'blogging_live_entries' ],
				'map_meta_cap'          => true,
				'has_archive'           => false,
				'exclude_from_search'   => true,
				'rewrite'               => false,
				'template'              => [ [ 'core/paragraph' ] ],
			]
		);
	}

	/**
	 * Return host post types supported by the plugin.
	 *
	 * @return string[]
	 */
	public static function host_post_types(): array {
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		unset( $post_types['attachment'], $post_types[ self::BLOGGING_LIVE_POST_TYPE ], $post_types[ self::ENTRY_POST_TYPE ] );

		/**
		 * Filters post types that can host a Blogging Live.
		 *
		 * @param string[] $post_types Public host post-type names.
		 */
		return array_values( apply_filters( 'blogging_live_host_post_types', $post_types ) );
	}

	/**
	 * @return array<string, string>
	 */
	private static function blogging_live_labels(): array {
		return [
			'name'               => __( 'Blogging Lives', 'blogging-live' ),
			'singular_name'      => __( 'Blogging Live', 'blogging-live' ),
			'add_new'            => __( 'Add Blogging Live', 'blogging-live' ),
			'add_new_item'       => __( 'Add Blogging Live', 'blogging-live' ),
			'edit_item'          => __( 'Edit Blogging Live', 'blogging-live' ),
			'new_item'           => __( 'New Blogging Live', 'blogging-live' ),
			'view_item'          => __( 'View host article', 'blogging-live' ),
			'search_items'       => __( 'Search Blogging Lives', 'blogging-live' ),
			'not_found'          => __( 'No Blogging Lives found.', 'blogging-live' ),
			'not_found_in_trash' => __( 'No Blogging Lives found in Trash.', 'blogging-live' ),
			'all_items'          => __( 'All Blogging Lives', 'blogging-live' ),
			'menu_name'          => __( 'Blogging Live', 'blogging-live' ),
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function entry_labels(): array {
		return [
			'name'               => __( 'Updates', 'blogging-live' ),
			'singular_name'      => __( 'Blogging Live update', 'blogging-live' ),
			'add_new'            => __( 'Add update', 'blogging-live' ),
			'add_new_item'       => __( 'Add Blogging Live update', 'blogging-live' ),
			'edit_item'          => __( 'Edit Blogging Live update', 'blogging-live' ),
			'new_item'           => __( 'New Blogging Live update', 'blogging-live' ),
			'search_items'       => __( 'Search updates', 'blogging-live' ),
			'not_found'          => __( 'No updates found.', 'blogging-live' ),
			'not_found_in_trash' => __( 'No updates found in Trash.', 'blogging-live' ),
			'all_items'          => __( 'All updates', 'blogging-live' ),
		];
	}
}

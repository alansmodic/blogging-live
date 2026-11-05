<?php
/**
 * Block editor integration for hosts and entries.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;
use BloggingLive\Repositories\BloggingLiveRepository;
use stdClass;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

final class Editor implements Feature {
	public function __construct( private readonly BloggingLiveRepository $blogging_lives ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_fields' ] );
		add_filter( 'rest_pre_insert_' . PostTypes::ENTRY_POST_TYPE, [ $this, 'assign_parent' ], 10, 2 );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	public function register_fields(): void {
		register_rest_field(
			PostTypes::host_post_types(),
			'blogging_live_id',
			[
				'get_callback' => function ( array $post ): int {
					$blogging_live = $this->blogging_lives->find_for_host( absint( $post['id'] ) );
					return $blogging_live && current_user_can( 'edit_post', $blogging_live->ID ) ? $blogging_live->ID : 0;
				},
				'schema'       => [
					'description' => __( 'ID of the Blogging Live attached to this post, for users who can edit it.', 'blogging-live' ),
					'type'        => 'integer',
					'context'     => [ 'edit' ],
					'readonly'    => true,
				],
			]
		);

		// Entries are not hierarchical, so core's REST schema has no parent field.
		register_rest_field(
			PostTypes::ENTRY_POST_TYPE,
			'blogging_live_parent',
			[
				'get_callback' => static fn( array $post ): int => absint( get_post( absint( $post['id'] ) )?->post_parent ),
				'schema'       => [
					'description' => __( 'ID of the Blogging Live this update belongs to.', 'blogging-live' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
				],
			]
		);
	}

	/**
	 * Apply blogging_live_parent before the entry is inserted, so the publish
	 * check in Admin::entry_post_data() sees the parent in the same save.
	 */
	public function assign_parent( stdClass $prepared_post, WP_REST_Request $request ): stdClass|WP_Error {
		if ( ! $request->has_param( 'blogging_live_parent' ) ) {
			return $prepared_post;
		}

		$parent_id = absint( $request->get_param( 'blogging_live_parent' ) );

		if ( $parent_id && ! $this->blogging_lives->find( $parent_id ) ) {
			return new WP_Error( 'blogging_live_invalid_parent', __( 'The selected Blogging Live does not exist.', 'blogging-live' ), [ 'status' => 400 ] );
		}

		if ( $parent_id && ! current_user_can( 'edit_post', $parent_id ) ) {
			return new WP_Error( 'blogging_live_cannot_assign_parent', __( 'You are not allowed to add updates to this Blogging Live.', 'blogging-live' ), [ 'status' => rest_authorization_required_code() ] );
		}

		$prepared_post->post_parent = $parent_id;

		return $prepared_post;
	}

	public function enqueue(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_entry = PostTypes::ENTRY_POST_TYPE === $screen->post_type;
		if ( ! $is_entry && ! in_array( $screen->post_type, PostTypes::host_post_types(), true ) ) {
			return;
		}

		wp_enqueue_script(
			'blogging-live-editor',
			BLOGGING_LIVE_URL . 'assets/js/editor.js',
			[ 'wp-components', 'wp-core-data', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-url' ],
			BLOGGING_LIVE_VERSION,
			true
		);
		wp_set_script_translations( 'blogging-live-editor', 'blogging-live', BLOGGING_LIVE_DIR . '/languages' );
		wp_add_inline_script(
			'blogging-live-editor',
			'window.BloggingLiveEditor = ' . wp_json_encode(
				[
					'context'        => $is_entry ? 'entry' : 'host',
					'entryPostType'  => PostTypes::ENTRY_POST_TYPE,
					'containerType'  => PostTypes::BLOGGING_LIVE_POST_TYPE,
					'adminUrl'       => admin_url(),
					'canCreate'      => current_user_can( 'create_blogging_lives' ),
					'containerLimit' => 100,
				]
			) . ';',
			'before'
		);
	}
}

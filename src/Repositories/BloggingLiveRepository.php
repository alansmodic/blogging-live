<?php
/**
 * Blogging Live persistence helpers.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Repositories;

use BloggingLive\Features\PostTypes;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class BloggingLiveRepository {
	public function find( int $blogging_live_id ): ?WP_Post {
		$post = get_post( $blogging_live_id );

		return $post instanceof WP_Post && PostTypes::BLOGGING_LIVE_POST_TYPE === $post->post_type ? $post : null;
	}

	public function find_for_host( int $host_id ): ?WP_Post {
		$blogging_live_id = absint( get_post_meta( $host_id, '_blogging_live_id', true ) );
		$blogging_live    = $this->find( $blogging_live_id );

		if ( $blogging_live ) {
			return $blogging_live;
		}

		$matches = get_posts(
			[
				'post_type'        => PostTypes::BLOGGING_LIVE_POST_TYPE,
				'post_parent'      => $host_id,
				'post_status'      => [ 'publish', 'draft', 'private', 'pending', 'future' ],
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			]
		);

		return $matches[0] ?? null;
	}

	/**
	 * Create a container for a host post.
	 *
	 * @return int|WP_Error
	 */
	public function create_for_host( int $host_id ): int|WP_Error {
		$existing = $this->find_for_host( $host_id );

		if ( $existing ) {
			return $existing->ID;
		}

		$host = get_post( $host_id );

		if ( ! $host instanceof WP_Post ) {
			return new WP_Error( 'blogging_live_invalid_host', __( 'The host post does not exist.', 'blogging-live' ) );
		}

		if ( ! in_array( $host->post_type, PostTypes::host_post_types(), true ) ) {
			return new WP_Error( 'blogging_live_unsupported_host', __( 'This post type cannot host a Blogging Live.', 'blogging-live' ) );
		}

		$blogging_live_id = wp_insert_post(
			[
				'post_type'   => PostTypes::BLOGGING_LIVE_POST_TYPE,
				'post_status' => 'publish' === $host->post_status ? 'publish' : 'draft',
				'post_parent' => $host_id,
				'post_title'  => sprintf(
					/* translators: %s: Host post title. */
					__( 'Blogging Live: %s', 'blogging-live' ),
					$host->post_title ? $host->post_title : __( 'Untitled', 'blogging-live' )
				),
				'meta_input'  => [
					'_blogging_live_status'           => 'scheduled',
					'_blogging_live_order'            => 'desc',
					'_blogging_live_cache_generation' => 1,
				],
			],
			true
		);

		if ( is_wp_error( $blogging_live_id ) ) {
			return $blogging_live_id;
		}

		update_post_meta( $host_id, '_blogging_live_id', $blogging_live_id );
		update_post_meta( $host_id, '_blogging_live_enabled', true );

		do_action( 'blogging_live_created', $blogging_live_id, $host_id );

		return $blogging_live_id;
	}

	public function host_id( int $blogging_live_id ): int {
		$blogging_live = $this->find( $blogging_live_id );

		return $blogging_live ? absint( $blogging_live->post_parent ) : 0;
	}

	public function is_public( int $blogging_live_id ): bool {
		$blogging_live = $this->find( $blogging_live_id );

		if ( ! $blogging_live || 'publish' !== $blogging_live->post_status ) {
			return false;
		}

		$host_id = $this->host_id( $blogging_live_id );

		return $host_id > 0
			&& 'publish' === get_post_status( $host_id )
			&& (bool) get_post_meta( $host_id, '_blogging_live_enabled', true );
	}

	public function status( int $blogging_live_id ): string {
		$status = (string) get_post_meta( $blogging_live_id, '_blogging_live_status', true );
		return array_key_exists( $status, self::status_labels() ) ? $status : 'scheduled';
	}

	/**
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return [
			'scheduled' => __( 'Scheduled', 'blogging-live' ),
			'live'      => __( 'Live', 'blogging-live' ),
			'ended'     => __( 'Ended', 'blogging-live' ),
		];
	}

	/**
	 * Whether the current user may see a Blogging Live and its published entries.
	 */
	public function can_view( int $blogging_live_id ): bool {
		return $this->is_public( $blogging_live_id ) || current_user_can( 'edit_post', $blogging_live_id );
	}
}

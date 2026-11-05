<?php
/**
 * Entry publication and invalidation lifecycle.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;
use BloggingLive\Repositories\EntryRepository;
use BloggingLive\Repositories\BloggingLiveRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Lifecycle implements Feature {
	/**
	 * Containers whose entries changed outside a post save, refreshed once at shutdown.
	 *
	 * @var array<int, WP_Post>
	 */
	private array $pending = [];

	public function __construct(
		private readonly BloggingLiveRepository $blogging_lives,
		private readonly EntryRepository $entries,
		private readonly Cache $cache
	) {}

	public function register(): void {
		add_action( 'wp_after_insert_post', [ $this, 'entry_saved' ], 20, 4 );
		add_action( 'deleted_post', [ $this, 'entry_deleted' ], 10, 2 );
		add_action( 'added_post_meta', [ $this, 'entry_meta_changed' ], 10, 4 );
		add_action( 'updated_post_meta', [ $this, 'entry_meta_changed' ], 10, 4 );
		add_action( 'deleted_post_meta', [ $this, 'entry_meta_changed' ], 10, 4 );
		add_action( 'added_post_meta', [ $this, 'host_meta_changed' ], 20, 4 );
		add_action( 'updated_post_meta', [ $this, 'host_meta_changed' ], 20, 4 );
		add_action( 'transition_post_status', [ $this, 'host_status_changed' ], 20, 3 );
		add_action( 'shutdown', [ $this, 'flush_pending' ] );
	}

	public function entry_saved( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void {
		unset( $update );

		if ( PostTypes::ENTRY_POST_TYPE !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$blogging_live_id = absint( $post->post_parent );
		$previous_id      = $post_before ? absint( $post_before->post_parent ) : 0;
		$was_public       = $post_before && 'publish' === $post_before->post_status;
		$is_public        = 'publish' === $post->post_status;

		// An entry moved to another container leaves the old feed.
		if ( $previous_id && $previous_id !== $blogging_live_id && $this->blogging_lives->find( $previous_id ) ) {
			if ( $was_public ) {
				$this->entries->record_removal( $previous_id, $post_id );
			}
			unset( $this->pending[ $previous_id ] );
			$this->refresh_blogging_live( $previous_id, $post, $was_public, false );
			$was_public = false;
		}

		if ( ! $this->blogging_lives->find( $blogging_live_id ) ) {
			return;
		}

		if ( $is_public ) {
			$this->entries->forget_removal( $blogging_live_id, $post_id );
		} elseif ( $was_public ) {
			$this->entries->record_removal( $blogging_live_id, $post_id );
		}

		// This refresh also covers any meta changes made earlier in the same save.
		unset( $this->pending[ $blogging_live_id ] );
		$this->refresh_blogging_live( $blogging_live_id, $post, $is_public || $was_public, $is_public );
	}

	public function entry_deleted( int $post_id, WP_Post $post ): void {
		if ( PostTypes::ENTRY_POST_TYPE !== $post->post_type ) {
			return;
		}

		$blogging_live_id = absint( $post->post_parent );
		if ( ! $this->blogging_lives->find( $blogging_live_id ) ) {
			return;
		}

		$was_public = 'publish' === $post->post_status;
		if ( $was_public ) {
			$this->entries->record_removal( $blogging_live_id, $post_id );
		}

		unset( $this->pending[ $blogging_live_id ] );
		$this->refresh_blogging_live( $blogging_live_id, $post, $was_public, false );
	}

	/**
	 * @param int|int[] $meta_id Meta ID, or an array of IDs for deleted_post_meta.
	 */
	public function entry_meta_changed( int|array $meta_id, int $post_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id, $meta_value );

		if ( ! in_array( $meta_key, [ '_blogging_live_is_pinned', '_blogging_live_is_key_event', '_blogging_live_author_ids' ], true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || PostTypes::ENTRY_POST_TYPE !== $post->post_type || ! $post->post_parent ) {
			return;
		}

		$this->pending[ absint( $post->post_parent ) ] = $post;
	}

	/**
	 * Refresh containers whose entry metadata changed without a following post save.
	 */
	public function flush_pending(): void {
		$pending       = $this->pending;
		$this->pending = [];

		foreach ( $pending as $blogging_live_id => $entry ) {
			$is_public = 'publish' === get_post_status( $entry );

			// Metadata changes do not update post_modified; touch it so polling
			// readers receive the entry in their list of updated entries.
			if ( $is_public ) {
				$this->touch_modified( $entry->ID, gmdate( 'Y-m-d H:i:s' ) );
			}

			$this->refresh_blogging_live( $blogging_live_id, $entry, $is_public, false );
		}
	}

	public function host_meta_changed( int $meta_id, int $post_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id );

		if ( '_blogging_live_enabled' !== $meta_key || ! rest_sanitize_boolean( $meta_value ) || ! in_array( get_post_type( $post_id ), PostTypes::host_post_types(), true ) ) {
			return;
		}

		if ( current_user_can( 'create_blogging_lives' ) ) {
			$this->blogging_lives->create_for_host( $post_id );
		}
	}

	public function host_status_changed( string $new_status, string $old_status, WP_Post $post ): void {
		unset( $old_status );

		if ( 'publish' !== $new_status || ! in_array( $post->post_type, PostTypes::host_post_types(), true ) || ! get_post_meta( $post->ID, '_blogging_live_enabled', true ) ) {
			return;
		}

		$blogging_live = $this->blogging_lives->find_for_host( $post->ID );
		if ( $blogging_live && 'publish' !== $blogging_live->post_status ) {
			wp_update_post(
				[
					'ID'          => $blogging_live->ID,
					'post_status' => 'publish',
				]
			);
		}
	}

	/**
	 * Invalidate a container's caches and timestamps after one of its entries changed.
	 *
	 * @param bool $public_change Whether the change is visible to readers.
	 */
	private function refresh_blogging_live( int $blogging_live_id, WP_Post $changed_entry, bool $public_change, bool $fire_published_action ): void {
		if ( $blogging_live_id < 1 ) {
			return;
		}

		$this->cache->bump( $blogging_live_id );
		$host_id = $this->blogging_lives->host_id( $blogging_live_id );

		if ( $public_change ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			update_post_meta( $blogging_live_id, '_blogging_live_last_updated_gmt', $now );

			if ( $host_id > 0 ) {
				update_post_meta( $host_id, '_blogging_live_last_updated_gmt', $now );

				if ( (bool) apply_filters( 'blogging_live_touch_host_post', Settings::get( 'touch_host' ), $host_id, $blogging_live_id ) ) {
					$this->touch_modified( $host_id, $now );
				}
			}
		}

		do_action( 'blogging_live_entry_changed', $changed_entry->ID, $blogging_live_id, $host_id );
		if ( $fire_published_action && 'publish' === $changed_entry->post_status ) {
			do_action( 'blogging_live_entry_published', $changed_entry->ID, $blogging_live_id, $host_id );
		}
	}

	/**
	 * Update a post's modified time without re-saving it.
	 *
	 * Calling wp_update_post() would re-run content sanitization on the host
	 * (stripping markup for users without unfiltered_html) and fire every
	 * save_post integration for each entry change. clean_post_cache() still
	 * triggers page-cache purges that listen for it.
	 */
	private function touch_modified( int $post_id, string $modified_gmt ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Single-row timestamp update; the post cache is cleared immediately below.
		$wpdb->update(
			$wpdb->posts,
			[
				'post_modified'     => get_date_from_gmt( $modified_gmt ),
				'post_modified_gmt' => $modified_gmt,
			],
			[ 'ID' => $post_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		clean_post_cache( $post_id );
	}
}

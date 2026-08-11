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
use BloggingLive\Repositories\LiveblogRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Lifecycle implements Feature {
	private bool $updating_host = false;

	public function __construct(
		private readonly LiveblogRepository $liveblogs,
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
	}

	public function entry_saved( int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before ): void {
		unset( $update );

		if ( PostTypes::ENTRY_POST_TYPE !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$blogging_live_id = absint( $post->post_parent );
		if ( ! $this->liveblogs->find( $blogging_live_id ) ) {
			return;
		}

		$timestamp = strtotime( $post->post_date_gmt . ' UTC' );
		if ( $timestamp ) {
			update_post_meta( $post_id, '_blogging_live_sort_timestamp', $timestamp );
		}

		$affects_public_feed = 'publish' === $post->post_status || ( $post_before && 'publish' === $post_before->post_status );
		$this->refresh_liveblog( $blogging_live_id, $post, $affects_public_feed );
	}

	public function entry_deleted( int $post_id, WP_Post $post ): void {
		unset( $post_id );

		if ( PostTypes::ENTRY_POST_TYPE !== $post->post_type ) {
			return;
		}

		$this->refresh_liveblog( absint( $post->post_parent ), $post, 'publish' === $post->post_status, false );
	}

	public function entry_meta_changed( int $meta_id, int $post_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id, $meta_value );

		if ( ! in_array( $meta_key, [ '_blogging_live_is_pinned', '_blogging_live_is_key_event', '_blogging_live_author_ids' ], true ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || PostTypes::ENTRY_POST_TYPE !== $post->post_type ) {
			return;
		}

		$this->refresh_liveblog( absint( $post->post_parent ), $post, 'publish' === $post->post_status, false );
	}

	public function host_meta_changed( int $meta_id, int $post_id, string $meta_key, mixed $meta_value ): void {
		unset( $meta_id );

		if ( '_blogging_live_enabled' !== $meta_key || ! rest_sanitize_boolean( $meta_value ) || ! in_array( get_post_type( $post_id ), PostTypes::host_post_types(), true ) ) {
			return;
		}

		if ( current_user_can( 'create_blogging_lives' ) ) {
			$this->liveblogs->create_for_host( $post_id );
		}
	}

	public function host_status_changed( string $new_status, string $old_status, WP_Post $post ): void {
		unset( $old_status );

		if ( 'publish' !== $new_status || ! in_array( $post->post_type, PostTypes::host_post_types(), true ) || ! get_post_meta( $post->ID, '_blogging_live_enabled', true ) ) {
			return;
		}

		$liveblog = $this->liveblogs->find_for_host( $post->ID );
		if ( $liveblog && 'publish' !== $liveblog->post_status ) {
			wp_update_post(
				[
					'ID'          => $liveblog->ID,
					'post_status' => 'publish',
				]
			);
		}
	}

	private function refresh_liveblog( int $blogging_live_id, WP_Post $changed_entry, bool $touch_host, bool $fire_published_action = true ): void {
		if ( $blogging_live_id < 1 ) {
			return;
		}

		$this->cache->bump( $blogging_live_id );
		$latest       = $this->entries->latest( $blogging_live_id );
		$last_updated = $latest ? $latest->post_modified_gmt : '';
		update_post_meta( $blogging_live_id, '_blogging_live_last_updated_gmt', $last_updated );

		$host_id = $this->liveblogs->host_id( $blogging_live_id );
		if ( $host_id > 0 ) {
			update_post_meta( $host_id, '_blogging_live_last_updated_gmt', $last_updated );
			clean_post_cache( $host_id );

			$should_touch_host = $touch_host && (bool) apply_filters( 'blogging_live_touch_host_post', Settings::get( 'touch_host' ), $host_id, $blogging_live_id );
			if ( $should_touch_host && ! $this->updating_host ) {
				$this->updating_host = true;
				wp_update_post( [ 'ID' => $host_id ] );
				$this->updating_host = false;
			}
		}

		do_action( 'blogging_live_entry_changed', $changed_entry->ID, $blogging_live_id, $host_id );
		if ( $fire_published_action && 'publish' === $changed_entry->post_status ) {
			do_action( 'blogging_live_entry_published', $changed_entry->ID, $blogging_live_id, $host_id );
		}
	}
}

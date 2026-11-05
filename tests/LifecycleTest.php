<?php
/**
 * Entry lifecycle tests.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

use BloggingLive\Features\Cache;
use BloggingLive\Repositories\EntryRepository;
use BloggingLive\Tests\TestCase;

final class LifecycleTest extends TestCase {
	public function test_publishing_an_entry_touches_host_without_resaving_it(): void {
		[ $host_id, $blogging_live_id ] = $this->create_blogging_live();
		$content                        = '<iframe src="https://example.com/embed"></iframe><p>Host body</p>';
		$this->set_post_fields(
			$host_id,
			[
				'post_content'      => $content,
				'post_modified'     => '2020-01-01 00:00:00',
				'post_modified_gmt' => '2020-01-01 00:00:00',
			]
		);

		// A user without unfiltered_html, as editors are on multisite (and WordPress VIP).
		add_filter( 'map_meta_cap', static fn( array $caps, string $cap ): array => 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps, 10, 2 );
		$host_saves = did_action( 'save_post_post' );

		$this->create_entry( $blogging_live_id );

		$host = get_post( $host_id );
		$this->assertSame( $content, $host->post_content );
		$this->assertNotSame( '2020-01-01 00:00:00', $host->post_modified_gmt );
		$this->assertSame( $host_saves, did_action( 'save_post_post' ) );
	}

	public function test_touch_host_can_be_disabled(): void {
		[ $host_id, $blogging_live_id ] = $this->create_blogging_live();
		$this->set_post_fields( $host_id, [ 'post_modified_gmt' => '2020-01-01 00:00:00' ] );
		add_filter( 'blogging_live_touch_host_post', '__return_false' );

		$this->create_entry( $blogging_live_id );

		$this->assertSame( '2020-01-01 00:00:00', get_post( $host_id )->post_modified_gmt );
	}

	public function test_save_with_meta_changes_refreshes_once(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live();
		$entry_id               = $this->create_entry( $blogging_live_id );
		$generation             = ( new Cache() )->generation( $blogging_live_id );
		$changed                = did_action( 'blogging_live_entry_changed' );

		$set_flags = static function ( int $post_id ): void {
			update_post_meta( $post_id, '_blogging_live_is_pinned', true );
			update_post_meta( $post_id, '_blogging_live_is_key_event', true );
		};
		add_action( 'save_post_blogging_live_entry', $set_flags );
		wp_update_post(
			[
				'ID'         => $entry_id,
				'post_title' => 'Edited',
			]
		);
		remove_action( 'save_post_blogging_live_entry', $set_flags );
		$this->flush_pending_refreshes();

		$this->assertSame( $changed + 1, did_action( 'blogging_live_entry_changed' ) );
		$this->assertSame( $generation + 1, ( new Cache() )->generation( $blogging_live_id ) );
	}

	public function test_meta_change_outside_a_save_refreshes_at_shutdown(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live();
		$entry_id               = $this->create_entry( $blogging_live_id );
		$this->set_post_fields( $entry_id, [ 'post_modified_gmt' => '2020-01-01 00:00:00' ] );
		$changed = did_action( 'blogging_live_entry_changed' );

		update_post_meta( $entry_id, '_blogging_live_is_pinned', true );
		$this->assertSame( $changed, did_action( 'blogging_live_entry_changed' ) );

		$this->flush_pending_refreshes();

		$this->assertSame( $changed + 1, did_action( 'blogging_live_entry_changed' ) );
		$this->assertNotSame( '2020-01-01 00:00:00', get_post( $entry_id )->post_modified_gmt );
	}

	public function test_trashed_and_moved_entries_are_recorded_as_removed(): void {
		[ , $first_id ]  = $this->create_blogging_live();
		[ , $second_id ] = $this->create_blogging_live( 'publish', 'Second event' );
		$trashed_id      = $this->create_entry( $first_id );
		$moved_id        = $this->create_entry( $first_id );
		$since           = gmdate( 'Y-m-d H:i:s', time() - 1 );
		$entries         = new EntryRepository( new Cache() );

		wp_trash_post( $trashed_id );
		wp_update_post(
			[
				'ID'          => $moved_id,
				'post_parent' => $second_id,
			]
		);

		$this->assertEqualsCanonicalizing( [ $trashed_id, $moved_id ], $entries->removed_since( $first_id, $since ) );
		$this->assertSame( [], $entries->removed_since( $second_id, $since ) );

		wp_untrash_post( $trashed_id );
		wp_publish_post( $trashed_id );
		$this->assertSame( [ $moved_id ], $entries->removed_since( $first_id, $since ) );
	}

	public function test_deleting_unrelated_post_meta_does_not_error(): void {
		$post_id = self::factory()->post->create();
		add_post_meta( $post_id, '_wp_page_template', 'default' );

		$this->assertTrue( delete_post_meta( $post_id, '_wp_page_template' ) );
	}

	/**
	 * @param array<string, string> $fields Columns to set directly.
	 */
	private function set_post_fields( int $post_id, array $fields ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, $fields, [ 'ID' => $post_id ] );
		clean_post_cache( $post_id );
	}
}

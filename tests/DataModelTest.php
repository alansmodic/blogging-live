<?php
/**
 * Data-model integration tests.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

use BloggingLive\Features\PostTypes;
use BloggingLive\Repositories\EntryRepository;
use BloggingLive\Repositories\LiveblogRepository;
use BloggingLive\Features\Cache;

final class DataModelTest extends WP_UnitTestCase {
	public function test_creates_container_and_child_entry(): void {
		$administrator = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $administrator );

		$host_id = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_status' => 'publish',
				'post_title'  => 'Election night',
			]
		);

		$repository  = new LiveblogRepository();
		$blogging_live_id = $repository->create_for_host( $host_id );

		$this->assertIsInt( $blogging_live_id );
		$this->assertSame( $host_id, wp_get_post_parent_id( $blogging_live_id ) );
		$this->assertSame( $blogging_live_id, (int) get_post_meta( $host_id, '_blogging_live_id', true ) );

		$entry_id = self::factory()->post->create(
			[
				'post_type'    => PostTypes::ENTRY_POST_TYPE,
				'post_parent'  => $blogging_live_id,
				'post_status'  => 'publish',
				'post_title'   => 'Polls close',
				'post_content' => '<!-- wp:paragraph --><p>Polls have closed.</p><!-- /wp:paragraph -->',
			]
		);

		$this->assertSame( $blogging_live_id, wp_get_post_parent_id( $entry_id ) );

		$entries = new EntryRepository( new Cache() );
		$feed    = $entries->feed( $blogging_live_id, [ 'per_page' => 10 ] );
		$this->assertCount( 1, $feed['entries'] );
		$entry = get_post( $entry_id );
		$this->assertInstanceOf( WP_Post::class, $entry );
		$this->assertTrue( $entries->is_valid_cursor( $entries->cursor( $entry ) ) );
	}
}

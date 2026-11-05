<?php
/**
 * Core REST visibility tests for containers and entries.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

use BloggingLive\Features\PostTypes;
use BloggingLive\Tests\TestCase;

final class RestVisibilityTest extends TestCase {
	private function entry_route( int $entry_id = 0 ): string {
		return '/wp/v2/' . PostTypes::ENTRY_POST_TYPE . ( $entry_id ? '/' . $entry_id : '' );
	}

	public function test_entry_under_draft_host_is_hidden_from_readers(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live( 'draft' );
		$entry_id               = $this->create_entry( $blogging_live_id );
		$this->assertSame( 'publish', get_post_status( $entry_id ) );

		wp_set_current_user( 0 );

		$this->assertGreaterThanOrEqual( 400, $this->rest( 'GET', $this->entry_route( $entry_id ) )->get_status() );
		$this->assertNotContains( $entry_id, wp_list_pluck( $this->rest( 'GET', $this->entry_route() )->get_data(), 'id' ) );
		$this->assertSame( 401, $this->rest( 'GET', '/blogging-live/v1/blogging-lives/' . $blogging_live_id . '/entries' )->get_status() );
	}

	public function test_entry_is_visible_once_host_is_public(): void {
		[ $host_id, $blogging_live_id ] = $this->create_blogging_live( 'draft' );
		$entry_id                       = $this->create_entry( $blogging_live_id );

		wp_update_post(
			[
				'ID'          => $host_id,
				'post_status' => 'publish',
			]
		);
		wp_set_current_user( 0 );

		$this->assertSame( 200, $this->rest( 'GET', $this->entry_route( $entry_id ) )->get_status() );
		$this->assertContains( $entry_id, wp_list_pluck( $this->rest( 'GET', $this->entry_route() )->get_data(), 'id' ) );
	}

	public function test_entry_is_hidden_when_host_disables_blogging_live(): void {
		[ $host_id, $blogging_live_id ] = $this->create_blogging_live();
		$entry_id                       = $this->create_entry( $blogging_live_id );
		update_post_meta( $host_id, '_blogging_live_enabled', false );

		wp_set_current_user( 0 );

		$this->assertGreaterThanOrEqual( 400, $this->rest( 'GET', $this->entry_route( $entry_id ) )->get_status() );
		$this->assertGreaterThanOrEqual( 400, $this->rest( 'GET', '/wp/v2/' . PostTypes::BLOGGING_LIVE_POST_TYPE . '/' . $blogging_live_id )->get_status() );
	}

	public function test_editors_can_read_embargoed_entries(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live( 'draft' );
		$entry_id               = $this->create_entry( $blogging_live_id );

		$this->assertSame( 200, $this->rest( 'GET', $this->entry_route( $entry_id ) )->get_status() );
		$this->assertSame( 200, $this->rest( 'GET', '/blogging-live/v1/blogging-lives/' . $blogging_live_id . '/entries' )->get_status() );
	}

	public function test_entry_can_be_published_with_parent_in_one_rest_request(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live();

		$response = $this->rest(
			'POST',
			$this->entry_route(),
			[
				'title'                => 'Polls close',
				'status'               => 'publish',
				'blogging_live_parent' => $blogging_live_id,
			]
		);

		$this->assertSame( 201, $response->get_status() );
		$entry = get_post( $response->get_data()['id'] );
		$this->assertSame( 'publish', $entry->post_status );
		$this->assertSame( $blogging_live_id, $entry->post_parent );
		$this->assertSame( $blogging_live_id, $response->get_data()['blogging_live_parent'] );
	}

	public function test_entry_without_parent_is_kept_as_draft(): void {
		$entry_id = self::factory()->post->create(
			[
				'post_type'   => PostTypes::ENTRY_POST_TYPE,
				'post_status' => 'publish',
			]
		);

		$this->assertSame( 'draft', get_post_status( $entry_id ) );
	}

	public function test_classic_editor_assigns_parent_before_publishing(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live();
		$_POST['blogging_live_entry_nonce'] = wp_create_nonce( 'blogging_live_save_entry' );
		$_POST['blogging_live_parent']      = (string) $blogging_live_id;

		$entry_id = wp_insert_post(
			[
				'post_type'   => PostTypes::ENTRY_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'From the classic editor',
			]
		);
		unset( $_POST['blogging_live_entry_nonce'], $_POST['blogging_live_parent'] );

		$this->assertSame( 'publish', get_post_status( $entry_id ) );
		$this->assertSame( $blogging_live_id, wp_get_post_parent_id( $entry_id ) );
	}

	public function test_host_exposes_blogging_live_id_to_editors_only(): void {
		[ $host_id, $blogging_live_id ] = $this->create_blogging_live();

		$response = $this->rest( 'GET', '/wp/v2/posts/' . $host_id, [ 'context' => 'edit' ] );
		$this->assertSame( $blogging_live_id, $response->get_data()['blogging_live_id'] );
		$this->assertArrayNotHasKey( '_blogging_live_id', $response->get_data()['meta'] );
	}
}

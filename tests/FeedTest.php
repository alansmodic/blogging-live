<?php
/**
 * Feed, REST payload, and rendering tests.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

use BloggingLive\EntryPresenter;
use BloggingLive\Features\Cache;
use BloggingLive\Repositories\EntryRepository;
use BloggingLive\Tests\TestCase;

final class FeedTest extends TestCase {
	public function test_feed_orders_by_gmt_time(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live();

		// Around a daylight-saving change, local time can run backwards while GMT keeps moving forward.
		$earlier = $this->create_entry(
			$blogging_live_id,
			[
				'post_date'     => '2026-11-01 01:30:00',
				'post_date_gmt' => '2026-11-01 05:30:00',
			]
		);
		$later   = $this->create_entry(
			$blogging_live_id,
			[
				'post_date'     => '2026-11-01 01:10:00',
				'post_date_gmt' => '2026-11-01 06:10:00',
			]
		);

		$entries = new EntryRepository( new Cache() );
		$feed    = $entries->feed( $blogging_live_id, [ 'per_page' => 1 ] );
		$this->assertSame( [ $later ], wp_list_pluck( $feed['entries'], 'ID' ) );

		$older = $entries->feed(
			$blogging_live_id,
			[
				'per_page' => 1,
				'before'   => $entries->cursor( $feed['entries'][0] ),
			]
		);
		$this->assertSame( [ $earlier ], wp_list_pluck( $older['entries'], 'ID' ) );
	}

	public function test_rest_feed_reports_status_edits_and_removals(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live();
		update_post_meta( $blogging_live_id, '_blogging_live_status', 'live' );
		$edited_id  = $this->create_entry( $blogging_live_id, [ 'post_date_gmt' => '2026-11-01 05:00:00' ] );
		$removed_id = $this->create_entry( $blogging_live_id, [ 'post_date_gmt' => '2026-11-01 05:01:00' ] );
		$latest_id  = $this->create_entry( $blogging_live_id, [ 'post_date_gmt' => '2026-11-01 05:02:00' ] );
		$entries    = new EntryRepository( new Cache() );
		$cursor     = $entries->cursor( get_post( $latest_id ) );
		$since      = gmdate( 'Y-m-d H:i:s', time() - 1 );

		wp_update_post(
			[
				'ID'           => $edited_id,
				'post_content' => '<!-- wp:paragraph --><p>Corrected.</p><!-- /wp:paragraph -->',
			]
		);
		wp_trash_post( $removed_id );

		wp_set_current_user( 0 );
		$response = $this->rest(
			'GET',
			'/blogging-live/v1/blogging-lives/' . $blogging_live_id . '/entries',
			[
				'after' => $cursor,
				'since' => $since,
			]
		);
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'live', $data['status'] );
		$this->assertSame( 'Live', $data['status_label'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $data['server_time'] );
		$this->assertSame( [], $data['entries'] );
		$updated = array_column( $data['updated'], null, 'id' );
		$this->assertArrayHasKey( $edited_id, $updated );
		$this->assertStringContainsString( 'Corrected.', $updated[ $edited_id ]['html'] );
		$this->assertSame( [ $removed_id ], $data['removed'] );
	}

	public function test_rest_feed_does_not_expose_raw_content(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live();
		$this->create_entry( $blogging_live_id );
		wp_set_current_user( 0 );

		$entry = $this->rest( 'GET', '/blogging-live/v1/blogging-lives/' . $blogging_live_id . '/entries' )->get_data()['entries'][0];

		$this->assertArrayNotHasKey( 'raw', $entry['content'] );
		$this->assertStringContainsString( 'Polls have closed.', $entry['content']['rendered'] );
	}

	public function test_rest_feed_rejects_invalid_since(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live();

		$response = $this->rest( 'GET', '/blogging-live/v1/blogging-lives/' . $blogging_live_id . '/entries', [ 'since' => 'yesterday' ] );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_pinned_entries_render_above_the_feed(): void {
		[ $host_id, $blogging_live_id ] = $this->create_blogging_live();
		$pinned_id                      = $this->create_entry(
			$blogging_live_id,
			[
				'post_title'    => 'Pinned explainer',
				'post_date_gmt' => '2026-11-01 01:00:00',
			]
		);
		update_post_meta( $pinned_id, '_blogging_live_is_pinned', true );
		for ( $i = 0; $i < 12; $i++ ) {
			$this->create_entry( $blogging_live_id, [ 'post_date_gmt' => '2026-11-01 02:' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ) . ':00' ] );
		}

		$html = do_shortcode( '[blogging-live post_id="' . $host_id . '"]' );

		$this->assertMatchesRegularExpression( '/<section class="blogging-live__pinned"[^>]*>\s*<article\s+id="blogging-live-pinned-' . $pinned_id . '"/', $html );
		// The pinned entry is older than the first page, so the feed itself does not include it yet.
		$this->assertStringNotContainsString( 'id="blogging-live-entry-' . $pinned_id . '"', $html );
	}

	public function test_shortcode_outside_a_post_renders_nothing(): void {
		unset( $GLOBALS['post'] );

		$this->assertSame( '', do_shortcode( '[blogging-live]' ) );
	}

	public function test_structured_data_uses_plain_text_without_the_content_filters(): void {
		[ , $blogging_live_id ] = $this->create_blogging_live();
		$entry                  = get_post( $this->create_entry( $blogging_live_id ) );
		$filtered               = 0;
		$counter                = static function ( string $content ) use ( &$filtered ): string {
			++$filtered;
			return $content;
		};
		add_filter( 'the_content', $counter );

		$data = ( new EntryPresenter() )->present( $entry, false );

		$this->assertSame( 0, $filtered );
		$this->assertSame( '', $data['content']['rendered'] );
		$this->assertSame( 'Polls have closed.', $data['content']['text'] );
	}
}

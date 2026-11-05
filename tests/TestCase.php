<?php
/**
 * Shared helpers for Blogging Live tests.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Tests;

use BloggingLive\Features\PostTypes;
use BloggingLive\Repositories\BloggingLiveRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

abstract class TestCase extends WP_UnitTestCase {
	protected int $editor_id;

	public function set_up(): void {
		parent::set_up();
		$this->editor_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Create a host post with an attached Blogging Live.
	 *
	 * @return array{0: int, 1: int} Host ID and Blogging Live ID.
	 */
	protected function create_blogging_live( string $host_status = 'publish', string $title = 'Election night' ): array {
		$host_id = self::factory()->post->create(
			[
				'post_status' => $host_status,
				'post_title'  => $title,
			]
		);

		$blogging_live_id = ( new BloggingLiveRepository() )->create_for_host( $host_id );
		$this->assertIsInt( $blogging_live_id );

		return [ $host_id, $blogging_live_id ];
	}

	/**
	 * @param array<string, mixed> $args Extra post arguments.
	 */
	protected function create_entry( int $blogging_live_id, array $args = [] ): int {
		return self::factory()->post->create(
			array_merge(
				[
					'post_type'    => PostTypes::ENTRY_POST_TYPE,
					'post_parent'  => $blogging_live_id,
					'post_status'  => 'publish',
					'post_title'   => 'Update',
					'post_content' => '<!-- wp:paragraph --><p>Polls have closed.</p><!-- /wp:paragraph -->',
				],
				$args
			)
		);
	}

	/**
	 * @param array<string, mixed> $params Query or body parameters.
	 */
	protected function rest( string $method, string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Run the plugin's shutdown flush without triggering core's shutdown handlers.
	 */
	protected function flush_pending_refreshes(): void {
		foreach ( $GLOBALS['wp_filter']['shutdown']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && 'flush_pending' === $callback['function'][1] ) {
					call_user_func( $callback['function'] );
				}
			}
		}
	}
}

<?php
/**
 * Public liveblog REST feed.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\EntryPresenter;
use BloggingLive\Feature;
use BloggingLive\Repositories\EntryRepository;
use BloggingLive\Repositories\LiveblogRepository;
use BloggingLive\Templates\TemplateLoader;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class RestApi implements Feature {
	private const NAMESPACE = 'blogging-live/v1';

	public function __construct(
		private readonly LiveblogRepository $liveblogs,
		private readonly EntryRepository $entries,
		private readonly TemplateLoader $templates,
		private readonly EntryPresenter $presenter
	) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/liveblogs/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_liveblog' ],
				'permission_callback' => [ $this, 'can_read_liveblog' ],
				'args'                => [
					'id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/liveblogs/(?P<id>\d+)/entries',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_entries' ],
				'permission_callback' => [ $this, 'can_read_liveblog' ],
				'args'                => [
					'id'       => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
					'per_page' => [
						'type'              => 'integer',
						'default'           => Settings::get( 'entries_per_page' ),
						'minimum'           => 1,
						'maximum'           => 50,
						'sanitize_callback' => 'absint',
					],
					'before'   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'after'    => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<post_id>\d+)/liveblog',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_for_post' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'post_id' => [
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					],
				],
			]
		);
	}

	public function can_read_liveblog( WP_REST_Request $request ): bool {
		$blogging_live_id = absint( $request['id'] );
		return $this->liveblogs->is_public( $blogging_live_id ) || current_user_can( 'read_post', $blogging_live_id );
	}

	public function get_for_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id  = absint( $request['post_id'] );
		$liveblog = $this->liveblogs->find_for_host( $post_id );

		if ( ! $liveblog || ( ! $this->liveblogs->is_public( $liveblog->ID ) && ! current_user_can( 'read_post', $liveblog->ID ) ) ) {
			return new WP_Error( 'blogging_live_not_found', __( 'No public liveblog was found for this post.', 'blogging-live' ), [ 'status' => 404 ] );
		}

		return $this->blogging_live_response( $liveblog );
	}

	public function get_liveblog( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$liveblog = $this->liveblogs->find( absint( $request['id'] ) );
		if ( ! $liveblog ) {
			return new WP_Error( 'blogging_live_not_found', __( 'Liveblog not found.', 'blogging-live' ), [ 'status' => 404 ] );
		}

		return $this->blogging_live_response( $liveblog );
	}

	public function get_entries( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( $request->get_param( 'before' ) && $request->get_param( 'after' ) ) {
			return new WP_Error( 'blogging_live_invalid_cursor', __( 'Use either before or after, not both.', 'blogging-live' ), [ 'status' => 400 ] );
		}

		$before = (string) $request->get_param( 'before' );
		$after  = (string) $request->get_param( 'after' );
		if ( ! $this->entries->is_valid_cursor( $before ) || ! $this->entries->is_valid_cursor( $after ) ) {
			return new WP_Error( 'blogging_live_invalid_cursor', __( 'The supplied liveblog cursor is invalid.', 'blogging-live' ), [ 'status' => 400 ] );
		}

		$blogging_live_id = absint( $request['id'] );
		$feed             = $this->entries->feed(
			$blogging_live_id,
			[
				'per_page' => absint( $request->get_param( 'per_page' ) ),
				'before'   => $before,
				'after'    => $after,
			]
		);
		$items            = [];

		foreach ( $feed['entries'] as $entry ) {
			$data           = $this->presenter->present( $entry );
			$data['cursor'] = $this->entries->cursor( $entry );
			$data['html']   = $this->templates->render( 'entry', [ 'entry' => $data ] );
			$items[]        = $data;
		}

		$newest   = $this->newest_entry( $feed['entries'] );
		$oldest   = $this->oldest_entry( $feed['entries'] );
		$data     = [
			'blogging_live_id' => $blogging_live_id,
			'entries'          => $items,
			'has_more'         => $feed['has_more'],
			'order'            => strtolower( $feed['order'] ),
			'newest_cursor'    => $newest ? $this->entries->cursor( $newest ) : '',
			'oldest_cursor'    => $oldest ? $this->entries->cursor( $oldest ) : '',
		];
		$response = new WP_REST_Response( $data );
		$etag     = '"' . md5( (string) wp_json_encode( $data ) ) . '"';

		if ( $request->get_header( 'if-none-match' ) === $etag ) {
			$response->set_status( 304 );
			$response->set_data( null );
		}

		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', $this->liveblogs->is_public( $blogging_live_id ) ? 'public, max-age=5, stale-while-revalidate=30' : 'private, no-store' );

		return $response;
	}

	private function blogging_live_response( WP_Post $liveblog ): WP_REST_Response {
		$host_id  = $this->liveblogs->host_id( $liveblog->ID );
		$status   = (string) get_post_meta( $liveblog->ID, '_blogging_live_status', true );
		$response = new WP_REST_Response(
			[
				'id'                 => $liveblog->ID,
				'host_id'            => $host_id,
				'title'              => get_the_title( $liveblog ),
				'status'             => $status ? $status : 'scheduled',
				'coverage_start_gmt' => (string) get_post_meta( $liveblog->ID, '_blogging_live_start_gmt', true ),
				'coverage_end_gmt'   => (string) get_post_meta( $liveblog->ID, '_blogging_live_end_gmt', true ),
				'last_updated_gmt'   => (string) get_post_meta( $liveblog->ID, '_blogging_live_last_updated_gmt', true ),
				'host_url'           => $host_id ? get_permalink( $host_id ) : '',
				'entries_url'        => rest_url( self::NAMESPACE . '/liveblogs/' . $liveblog->ID . '/entries' ),
			]
		);
		$response->header( 'Cache-Control', $this->liveblogs->is_public( $liveblog->ID ) ? 'public, max-age=10, stale-while-revalidate=60' : 'private, no-store' );
		return $response;
	}

	/**
	 * @param WP_Post[] $posts Entry posts.
	 */
	private function newest_entry( array $posts ): ?WP_Post {
		usort( $posts, [ $this, 'compare_entries' ] );
		return $posts[0] ?? null;
	}

	/**
	 * @param WP_Post[] $posts Entry posts.
	 */
	private function oldest_entry( array $posts ): ?WP_Post {
		usort( $posts, [ $this, 'compare_entries' ] );
		return ! empty( $posts ) ? $posts[ count( $posts ) - 1 ] : null;
	}

	private function compare_entries( WP_Post $left, WP_Post $right ): int {
		$date_comparison = strcmp( $right->post_date_gmt, $left->post_date_gmt );
		return 0 !== $date_comparison ? $date_comparison : $right->ID <=> $left->ID;
	}
}

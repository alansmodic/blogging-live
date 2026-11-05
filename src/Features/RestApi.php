<?php
/**
 * Public Blogging Live REST feed.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\EntryPresenter;
use BloggingLive\Feature;
use BloggingLive\Repositories\EntryRepository;
use BloggingLive\Repositories\BloggingLiveRepository;
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
		private readonly BloggingLiveRepository $blogging_lives,
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
			'/blogging-lives/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_blogging_live' ],
				'permission_callback' => [ $this, 'can_read_blogging_live' ],
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
			'/blogging-lives/(?P<id>\d+)/entries',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_entries' ],
				'permission_callback' => [ $this, 'can_read_blogging_live' ],
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
			'/posts/(?P<post_id>\d+)/blogging-live',
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

	public function can_read_blogging_live( WP_REST_Request $request ): bool {
		$blogging_live_id = absint( $request['id'] );
		return $this->blogging_lives->is_public( $blogging_live_id ) || current_user_can( 'read_post', $blogging_live_id );
	}

	public function get_for_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id       = absint( $request['post_id'] );
		$blogging_live = $this->blogging_lives->find_for_host( $post_id );

		if ( ! $blogging_live || ( ! $this->blogging_lives->is_public( $blogging_live->ID ) && ! current_user_can( 'read_post', $blogging_live->ID ) ) ) {
			return new WP_Error( 'blogging_live_not_found', __( 'No public Blogging Live was found for this post.', 'blogging-live' ), [ 'status' => 404 ] );
		}

		return $this->blogging_live_response( $blogging_live );
	}

	public function get_blogging_live( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$blogging_live = $this->blogging_lives->find( absint( $request['id'] ) );
		if ( ! $blogging_live ) {
			return new WP_Error( 'blogging_live_not_found', __( 'Blogging Live not found.', 'blogging-live' ), [ 'status' => 404 ] );
		}

		return $this->blogging_live_response( $blogging_live );
	}

	public function get_entries( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( $request->get_param( 'before' ) && $request->get_param( 'after' ) ) {
			return new WP_Error( 'blogging_live_invalid_cursor', __( 'Use either before or after, not both.', 'blogging-live' ), [ 'status' => 400 ] );
		}

		$before = (string) $request->get_param( 'before' );
		$after  = (string) $request->get_param( 'after' );
		if ( ! $this->entries->is_valid_cursor( $before ) || ! $this->entries->is_valid_cursor( $after ) ) {
			return new WP_Error( 'blogging_live_invalid_cursor', __( 'The supplied Blogging Live cursor is invalid.', 'blogging-live' ), [ 'status' => 400 ] );
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
		$response->header( 'Cache-Control', $this->blogging_lives->is_public( $blogging_live_id ) ? 'public, max-age=5, stale-while-revalidate=30' : 'private, no-store' );

		return $response;
	}

	private function blogging_live_response( WP_Post $blogging_live ): WP_REST_Response {
		$host_id  = $this->blogging_lives->host_id( $blogging_live->ID );
		$status   = (string) get_post_meta( $blogging_live->ID, '_blogging_live_status', true );
		$response = new WP_REST_Response(
			[
				'id'                 => $blogging_live->ID,
				'host_id'            => $host_id,
				'title'              => get_the_title( $blogging_live ),
				'status'             => $status ? $status : 'scheduled',
				'coverage_start_gmt' => (string) get_post_meta( $blogging_live->ID, '_blogging_live_start_gmt', true ),
				'coverage_end_gmt'   => (string) get_post_meta( $blogging_live->ID, '_blogging_live_end_gmt', true ),
				'last_updated_gmt'   => (string) get_post_meta( $blogging_live->ID, '_blogging_live_last_updated_gmt', true ),
				'host_url'           => $host_id ? get_permalink( $host_id ) : '',
				'entries_url'        => rest_url( self::NAMESPACE . '/blogging-lives/' . $blogging_live->ID . '/entries' ),
			]
		);
		$response->header( 'Cache-Control', $this->blogging_lives->is_public( $blogging_live->ID ) ? 'public, max-age=10, stale-while-revalidate=60' : 'private, no-store' );
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

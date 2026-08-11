<?php
/**
 * Server-rendered liveblog output.
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
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Frontend implements Feature {
	private bool $rendering = false;

	public function __construct(
		private readonly LiveblogRepository $liveblogs,
		private readonly EntryRepository $entries,
		private readonly TemplateLoader $templates,
		private readonly EntryPresenter $presenter = new EntryPresenter()
	) {}

	public function register(): void {
		add_filter( 'the_content', [ $this, 'append_to_content' ], 20 );
		add_shortcode( 'blogging-live', [ $this, 'shortcode' ] );
	}

	public function append_to_content( string $content ): string {
		if ( $this->rendering || ! Settings::get( 'auto_append' ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id || ! in_array( get_post_type( $post_id ), PostTypes::host_post_types(), true ) ) {
			return $content;
		}

		if ( has_block( 'blogging-live/feed', $content ) || ! get_post_meta( $post_id, '_blogging_live_enabled', true ) ) {
			return $content;
		}

		$output = $this->render_for_host( $post_id );
		return $output ? $content . $output : $content;
	}

	/**
	 * @param array<string, mixed> $attributes Shortcode attributes.
	 */
	public function shortcode( array $attributes = [] ): string {
		$attributes       = shortcode_atts(
			[
				'id'          => 0,
				'post_id'     => 0,
				'show_header' => 'true',
			],
			$attributes,
			'blogging-live'
		);
		$blogging_live_id = absint( $attributes['id'] );

		if ( ! $blogging_live_id && absint( $attributes['post_id'] ) ) {
			$liveblog         = $this->liveblogs->find_for_host( absint( $attributes['post_id'] ) );
			$blogging_live_id = $liveblog ? $liveblog->ID : 0;
		}

		if ( ! $blogging_live_id ) {
			$liveblog         = $this->liveblogs->find_for_host( get_the_ID() );
			$blogging_live_id = $liveblog ? $liveblog->ID : 0;
		}

		return $this->render( $blogging_live_id, [ 'show_header' => 'false' !== $attributes['show_header'] ] );
	}

	/**
	 * @param array<string, mixed> $args Rendering arguments.
	 */
	public function render_for_host( int $host_id, array $args = [] ): string {
		$liveblog = $this->liveblogs->find_for_host( $host_id );
		return $liveblog ? $this->render( $liveblog->ID, $args ) : '';
	}

	/**
	 * @param array<string, mixed> $args Rendering arguments.
	 */
	public function render( int $blogging_live_id, array $args = [] ): string {
		$liveblog = $this->liveblogs->find( $blogging_live_id );
		if ( ! $liveblog || ( ! $this->liveblogs->is_public( $blogging_live_id ) && ! current_user_can( 'read_post', $blogging_live_id ) ) ) {
			return '';
		}

		$per_page   = max( 1, min( 50, absint( $args['per_page'] ?? Settings::get( 'entries_per_page' ) ) ) );
		$feed       = $this->entries->feed( $blogging_live_id, [ 'per_page' => $per_page ] );
		$order_meta = (string) get_post_meta( $blogging_live_id, '_blogging_live_order', true );
		$order      = $order_meta ? $order_meta : 'desc';
		$posts      = $feed['entries'];

		if ( 'asc' === $order ) {
			$posts = array_reverse( $posts );
		}

		$this->rendering = true;
		$entry_data      = [];
		foreach ( $posts as $entry ) {
			$presented           = $this->presenter->present( $entry );
			$presented['html']   = $this->templates->render( 'entry', [ 'entry' => $presented ] );
			$presented['cursor'] = $this->entries->cursor( $entry );
			$entry_data[]        = $presented;
		}
		$this->rendering = false;

		$newest       = $feed['entries'][0] ?? null;
		$oldest       = ! empty( $feed['entries'] ) ? $feed['entries'][ count( $feed['entries'] ) - 1 ] : null;
		$refresh_meta = absint( get_post_meta( $blogging_live_id, '_blogging_live_refresh_interval', true ) );
		$refresh      = $refresh_meta ? $refresh_meta : absint( Settings::get( 'poll_interval' ) );
		$status_meta  = (string) get_post_meta( $blogging_live_id, '_blogging_live_status', true );
		$status       = $status_meta ? $status_meta : 'scheduled';

		Assets::enqueue();

		$output = $this->templates->render(
			'blogging-live',
			[
				'liveblog'    => $liveblog,
				'entries'     => $entry_data,
				'status'      => $status,
				'order'       => $order,
				'refresh'     => max( 5, $refresh ),
				'has_more'    => $feed['has_more'],
				'newest'      => $newest ? $this->entries->cursor( $newest ) : '',
				'oldest'      => $oldest ? $this->entries->cursor( $oldest ) : '',
				'show_header' => ! isset( $args['show_header'] ) || (bool) $args['show_header'],
				'endpoint'    => rest_url( 'blogging-live/v1/liveblogs/' . $blogging_live_id . '/entries' ),
			]
		);

		/**
		 * Filters complete liveblog HTML.
		 *
		 * @param string  $output   Rendered output.
		 * @param WP_Post $liveblog Liveblog container post.
		 */
		return (string) apply_filters( 'blogging_live_html', $output, $liveblog );
	}

	public function entry_html( WP_Post $entry ): string {
		$this->rendering = true;
		$data            = $this->presenter->present( $entry );
		$html            = $this->templates->render( 'entry', [ 'entry' => $data ] );
		$this->rendering = false;
		return $html;
	}
}

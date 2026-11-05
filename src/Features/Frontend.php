<?php
/**
 * Server-rendered Blogging Live output.
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
use WP_Post;

defined( 'ABSPATH' ) || exit;

final class Frontend implements Feature {
	private bool $rendering = false;

	public function __construct(
		private readonly BloggingLiveRepository $blogging_lives,
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
	 * @param array<string, mixed>|string $attributes Shortcode attributes.
	 */
	public function shortcode( array|string $attributes = [] ): string {
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
		$host_id          = absint( $attributes['post_id'] );

		if ( ! $blogging_live_id ) {
			// get_the_ID() is false outside a post context, such as a widget on an archive.
			$host_id = $host_id ? $host_id : absint( get_the_ID() );

			if ( $host_id ) {
				$blogging_live    = $this->blogging_lives->find_for_host( $host_id );
				$blogging_live_id = $blogging_live ? $blogging_live->ID : 0;
			}
		}

		return $blogging_live_id ? $this->render( $blogging_live_id, [ 'show_header' => 'false' !== $attributes['show_header'] ] ) : '';
	}

	/**
	 * @param array<string, mixed> $args Rendering arguments.
	 */
	public function render_for_host( int $host_id, array $args = [] ): string {
		$blogging_live = $this->blogging_lives->find_for_host( $host_id );
		return $blogging_live ? $this->render( $blogging_live->ID, $args ) : '';
	}

	/**
	 * @param array<string, mixed> $args Rendering arguments.
	 */
	public function render( int $blogging_live_id, array $args = [] ): string {
		$blogging_live = $this->blogging_lives->find( $blogging_live_id );
		if ( ! $blogging_live || ! $this->blogging_lives->can_view( $blogging_live_id ) ) {
			return '';
		}

		$per_page   = max( 1, min( 50, absint( $args['per_page'] ?? Settings::get( 'entries_per_page' ) ) ) );
		$feed       = $this->entries->feed( $blogging_live_id, [ 'per_page' => $per_page ] );
		$pinned     = self::pinned_limit() ? $this->entries->feed(
			$blogging_live_id,
			[
				'per_page'    => self::pinned_limit(),
				'pinned_only' => true,
			]
		)['entries'] : [];
		$order_meta = (string) get_post_meta( $blogging_live_id, '_blogging_live_order', true );
		$order      = $order_meta ? $order_meta : 'desc';
		$posts      = $feed['entries'];

		if ( 'asc' === $order ) {
			$posts = array_reverse( $posts );
		}

		$entry_data  = array_map( [ $this, 'entry_payload' ], $posts );
		$pinned_data = array_map( [ $this, 'entry_payload' ], $pinned );

		$newest       = $feed['entries'][0] ?? null;
		$oldest       = ! empty( $feed['entries'] ) ? $feed['entries'][ count( $feed['entries'] ) - 1 ] : null;
		$refresh_meta = absint( get_post_meta( $blogging_live_id, '_blogging_live_refresh_interval', true ) );
		$refresh      = $refresh_meta ? $refresh_meta : absint( Settings::get( 'poll_interval' ) );
		$status       = $this->blogging_lives->status( $blogging_live_id );

		Assets::enqueue();

		$output = $this->templates->render(
			'blogging-live',
			[
				'blogging_live' => $blogging_live,
				'entries'       => $entry_data,
				'pinned'        => $pinned_data,
				'pinned_limit'  => self::pinned_limit(),
				'status'        => $status,
				'status_label'  => BloggingLiveRepository::status_labels()[ $status ],
				'order'         => $order,
				'refresh'       => max( 5, $refresh ),
				'has_more'      => $feed['has_more'],
				'newest'        => $newest ? $this->entries->cursor( $newest ) : '',
				'oldest'        => $oldest ? $this->entries->cursor( $oldest ) : '',
				'server_time'   => gmdate( 'Y-m-d H:i:s' ),
				'show_header'   => ! isset( $args['show_header'] ) || (bool) $args['show_header'],
				'endpoint'      => rest_url( 'blogging-live/v1/blogging-lives/' . $blogging_live_id . '/entries' ),
			]
		);

		/**
		 * Filters complete Blogging Live HTML.
		 *
		 * @param string  $output        Rendered output.
		 * @param WP_Post $blogging_live Blogging Live container post.
		 */
		return (string) apply_filters( 'blogging_live_html', $output, $blogging_live );
	}

	/**
	 * Presented entry data with its feed markup, pinned-section markup, and cursor.
	 *
	 * @return array<string, mixed>
	 */
	public function entry_payload( WP_Post $entry ): array {
		$was_rendering   = $this->rendering;
		$this->rendering = true;

		$data                = $this->presenter->present( $entry );
		$data['cursor']      = $this->entries->cursor( $entry );
		$data['html']        = $this->entry_markup( $data, 'feed' );
		$data['pinned_html'] = $data['is_pinned'] ? $this->entry_markup( $data, 'pinned' ) : '';

		$this->rendering = $was_rendering;

		return $data;
	}

	public function entry_html( WP_Post $entry ): string {
		return $this->entry_payload( $entry )['html'];
	}

	/**
	 * Maximum number of pinned entries shown above the feed.
	 */
	public static function pinned_limit(): int {
		/**
		 * Filters how many pinned entries appear above the feed. Return 0 to hide the pinned section.
		 *
		 * @param int $limit Maximum pinned entries.
		 */
		return max( 0, min( 10, (int) apply_filters( 'blogging_live_pinned_limit', 3 ) ) );
	}

	/**
	 * @param array<string, mixed> $data    Presented entry data.
	 * @param string               $context Where the entry appears: 'feed' or 'pinned'.
	 */
	private function entry_markup( array $data, string $context ): string {
		return $this->templates->render(
			'entry',
			[
				'entry'   => $data,
				'context' => $context,
			]
		);
	}
}

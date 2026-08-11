<?php
/**
 * Dynamic liveblog feed block.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;
use WP_Block;

defined( 'ABSPATH' ) || exit;

final class Blocks implements Feature {
	public function __construct( private readonly Frontend $frontend ) {}

	public function register(): void {
		add_action( 'init', [ $this, 'register_block' ], 20 );
	}

	public function register_block(): void {
		$asset = require BLOGGING_LIVE_DIR . '/blocks/feed/index.asset.php';
		wp_register_script(
			'blogging-live-feed-editor',
			BLOGGING_LIVE_URL . 'blocks/feed/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		register_block_type(
			BLOGGING_LIVE_DIR . '/blocks/feed',
			[
				'editor_script'   => 'blogging-live-feed-editor',
				'style'           => 'blogging-live',
				'render_callback' => [ $this, 'render_block' ],
			]
		);
	}

	/**
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_block( array $attributes, string $content, WP_Block $block ): string {
		unset( $content );
		$blogging_live_id = absint( $attributes['bloggingLiveId'] ?? 0 );
		$show_header      = ! isset( $attributes['showHeader'] ) || (bool) $attributes['showHeader'];

		if ( $blogging_live_id ) {
			return $this->frontend->render( $blogging_live_id, [ 'show_header' => $show_header ] );
		}

		$post_id = absint( $block->context['postId'] ?? get_the_ID() );
		return $post_id ? $this->frontend->render_for_host( $post_id, [ 'show_header' => $show_header ] ) : '';
	}
}

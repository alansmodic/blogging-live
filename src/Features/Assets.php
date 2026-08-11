<?php
/**
 * Front-end asset registration.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;

defined( 'ABSPATH' ) || exit;

final class Assets implements Feature {
	private static bool $localized = false;
	public function register(): void {
		add_action( 'init', [ self::class, 'register_assets' ], 5 );
		add_action( 'wp_enqueue_scripts', [ self::class, 'maybe_enqueue' ], 20 );
	}

	public static function register_assets(): void {
		wp_register_style(
			'blogging-live',
			BLOGGING_LIVE_URL . 'assets/css/blogging-live.css',
			[],
			BLOGGING_LIVE_VERSION
		);

		wp_register_script(
			'blogging-live',
			BLOGGING_LIVE_URL . 'assets/js/blogging-live.js',
			[],
			BLOGGING_LIVE_VERSION,
			true
		);
		wp_script_add_data( 'blogging-live', 'strategy', 'defer' );
	}

	public static function maybe_enqueue(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if (
			get_post_meta( $post->ID, '_blogging_live_enabled', true )
			|| has_block( 'blogging-live/feed', $post )
			|| has_shortcode( $post->post_content, 'blogging-live' )
		) {
			self::enqueue();
		}
	}

	public static function enqueue(): void {
		if ( ! wp_style_is( 'blogging-live', 'registered' ) || ! wp_script_is( 'blogging-live', 'registered' ) ) {
			self::register_assets();
		}

		wp_enqueue_style( 'blogging-live' );
		wp_enqueue_script( 'blogging-live' );

		if ( ! self::$localized ) {
			wp_localize_script(
				'blogging-live',
				'BloggingLiveSettings',
				[
					'strings' => [
						'oneNewUpdate'   => __( '1 new update', 'blogging-live' ),
						/* translators: %d: Number of new liveblog updates. */
						'manyNewUpdates' => __( '%d new updates', 'blogging-live' ),
						'loading'        => __( 'Loading updates…', 'blogging-live' ),
						'none'           => __( 'No additional updates.', 'blogging-live' ),
						'error'          => __( 'Updates could not be loaded. Please try again.', 'blogging-live' ),
					],
				]
			);
			self::$localized = true;
		}
	}
}

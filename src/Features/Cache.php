<?php
/**
 * Generation-based liveblog caching.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;

defined( 'ABSPATH' ) || exit;

final class Cache implements Feature {
	public const GROUP = 'blogging_live';

	public function register(): void {
		// Public methods are called by repositories and lifecycle services.
	}

	public function generation( int $blogging_live_id ): int {
		$cache_key = 'generation:' . $blogging_live_id;
		$cached    = wp_cache_get( $cache_key, self::GROUP );

		if ( is_numeric( $cached ) && (int) $cached > 0 ) {
			return (int) $cached;
		}

		$generation = max( 1, absint( get_post_meta( $blogging_live_id, '_blogging_live_cache_generation', true ) ) );
		wp_cache_set( $cache_key, $generation, self::GROUP );

		return $generation;
	}

	public function bump( int $blogging_live_id ): int {
		$generation = $this->generation( $blogging_live_id ) + 1;
		update_post_meta( $blogging_live_id, '_blogging_live_cache_generation', $generation );
		wp_cache_set( 'generation:' . $blogging_live_id, $generation, self::GROUP );

		do_action( 'blogging_live_cache_invalidated', $blogging_live_id, $generation );

		return $generation;
	}

	public function get( string $key, ?bool &$found = null ): mixed {
		return wp_cache_get( $key, self::GROUP, false, $found );
	}

	public function set( string $key, mixed $value, int $ttl = 300 ): bool {
		return wp_cache_set( $key, $value, self::GROUP, $ttl );
	}
}

<?php
/**
 * Registered post metadata.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;

defined( 'ABSPATH' ) || exit;

final class Metadata implements Feature {
	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ], 20 );
	}

	public function register_meta(): void {
		foreach ( PostTypes::host_post_types() as $post_type ) {
			$this->register_scalar( $post_type, '_blogging_live_enabled', 'boolean', false );
			$this->register_scalar( $post_type, '_blogging_live_id', 'integer', 0 );
			$this->register_scalar( $post_type, '_blogging_live_last_updated_gmt', 'string', '' );
		}

		$this->register_scalar( PostTypes::LIVEBLOG_POST_TYPE, '_blogging_live_status', 'string', 'scheduled' );
		$this->register_scalar( PostTypes::LIVEBLOG_POST_TYPE, '_blogging_live_start_gmt', 'string', '' );
		$this->register_scalar( PostTypes::LIVEBLOG_POST_TYPE, '_blogging_live_end_gmt', 'string', '' );
		$this->register_scalar( PostTypes::LIVEBLOG_POST_TYPE, '_blogging_live_last_updated_gmt', 'string', '' );
		$this->register_scalar( PostTypes::LIVEBLOG_POST_TYPE, '_blogging_live_refresh_interval', 'integer', 0 );
		$this->register_scalar( PostTypes::LIVEBLOG_POST_TYPE, '_blogging_live_order', 'string', 'desc' );
		$this->register_scalar( PostTypes::LIVEBLOG_POST_TYPE, '_blogging_live_cache_generation', 'integer', 1, false );

		$this->register_scalar( PostTypes::ENTRY_POST_TYPE, '_blogging_live_is_pinned', 'boolean', false );
		$this->register_scalar( PostTypes::ENTRY_POST_TYPE, '_blogging_live_is_key_event', 'boolean', false );
		$this->register_scalar( PostTypes::ENTRY_POST_TYPE, '_blogging_live_sort_timestamp', 'integer', 0 );

		register_post_meta(
			PostTypes::ENTRY_POST_TYPE,
			'_blogging_live_author_ids',
			[
				'type'              => 'array',
				'single'            => true,
				'default'           => [],
				'show_in_rest'      => [
					'schema' => [
						'type'  => 'array',
						'items' => [ 'type' => 'integer' ],
					],
				],
				'sanitize_callback' => static fn( mixed $value ): array => array_values( array_unique( array_filter( array_map( 'absint', (array) $value ) ) ) ),
				'auth_callback'     => [ $this, 'can_edit_meta' ],
			]
		);
	}

	private function register_scalar( string $post_type, string $key, string $type, mixed $default_value, bool $show_in_rest = true ): void {
		register_post_meta(
			$post_type,
			$key,
			[
				'type'              => $type,
				'single'            => true,
				'default'           => $default_value,
				'show_in_rest'      => $show_in_rest,
				'sanitize_callback' => $this->sanitizer( $type ),
				'auth_callback'     => [ $this, 'can_edit_meta' ],
			]
		);
	}

	private function sanitizer( string $type ): callable {
		return match ( $type ) {
			'boolean' => static fn( mixed $value ): bool => rest_sanitize_boolean( $value ),
			'integer' => static fn( mixed $value ): int => absint( $value ),
			default   => static fn( mixed $value ): string => sanitize_text_field( (string) $value ),
		};
	}

	public function can_edit_meta( mixed $allowed, mixed $key, mixed $post_id ): bool {
		return current_user_can( 'edit_post', absint( $post_id ) );
	}
}

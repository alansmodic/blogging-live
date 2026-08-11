<?php
/**
 * Liveblog entry queries and cursors.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Repositories;

use BloggingLive\Features\Cache;
use BloggingLive\Features\PostTypes;
use DateTimeImmutable;
use DateTimeZone;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

final class EntryRepository {
	public function __construct( private readonly Cache $cache ) {}

	/**
	 * Query published entries using a stable timestamp-and-ID cursor.
	 *
	 * @param array{per_page?: int, before?: string, after?: string, pinned_only?: bool} $args Query arguments.
	 * @return array{entries: WP_Post[], has_more: bool, order: string}
	 */
	public function feed( int $blogging_live_id, array $args = [] ): array {
		$per_page   = max( 1, min( 50, absint( $args['per_page'] ?? 10 ) ) );
		$before     = $this->decode_cursor( (string) ( $args['before'] ?? '' ) );
		$after      = $this->decode_cursor( (string) ( $args['after'] ?? '' ) );
		$pinned     = ! empty( $args['pinned_only'] );
		$order      = $after ? 'ASC' : 'DESC';
		$generation = $this->cache->generation( $blogging_live_id );
		$cache_key  = sprintf(
			'feed:%d:%d:%s',
			$blogging_live_id,
			$generation,
			md5( (string) wp_json_encode( [ $per_page, $before, $after, $pinned, $order ] ) )
		);
		$found      = false;
		$cached     = $this->cache->get( $cache_key, $found );

		if ( $found && is_array( $cached ) ) {
			$posts = array_values( array_filter( array_map( 'get_post', $cached['ids'] ?? [] ) ) );

			return [
				'entries'  => $posts,
				'has_more' => ! empty( $cached['has_more'] ),
				'order'    => $cached['order'] ?? $order,
			];
		}

		$query_args = [
			'post_type'              => PostTypes::ENTRY_POST_TYPE,
			'post_parent'            => $blogging_live_id,
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page + 1,
			'orderby'                => [
				'date' => $order,
				'ID'   => $order,
			],
			'order'                  => $order,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		];

		if ( $pinned ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Optional, bounded child-entry query.
			$query_args['meta_query'] = [
				[
					'key'     => '_blogging_live_is_pinned',
					'value'   => '1',
					'compare' => '=',
				],
			];
		}

		$cursor = $before ? $before : $after;
		$where  = null;

		if ( $cursor ) {
			$operator = $before ? '<' : '>';
			$where    = static function ( string $sql ) use ( $cursor, $operator ): string {
				global $wpdb;

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Operator is constrained to < or > above.
				$cursor_sql = $wpdb->prepare(
					" AND ( {$wpdb->posts}.post_date_gmt {$operator} %s OR ( {$wpdb->posts}.post_date_gmt = %s AND {$wpdb->posts}.ID {$operator} %d ) )",
					$cursor['date'],
					$cursor['date'],
					$cursor['id']
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return $sql . $cursor_sql;
			};
			add_filter( 'posts_where', $where );
		}

		$query = new WP_Query( $query_args );

		if ( $where ) {
			remove_filter( 'posts_where', $where );
		}

		$posts    = array_values( array_filter( $query->posts, static fn( mixed $post ): bool => $post instanceof WP_Post ) );
		$has_more = count( $posts ) > $per_page;
		$posts    = array_slice( $posts, 0, $per_page );

		$this->cache->set(
			$cache_key,
			[
				'ids'      => wp_list_pluck( $posts, 'ID' ),
				'has_more' => $has_more,
				'order'    => $order,
			]
		);

		return [
			'entries'  => $posts,
			'has_more' => $has_more,
			'order'    => $order,
		];
	}

	public function latest( int $blogging_live_id ): ?WP_Post {
		$result = $this->feed( $blogging_live_id, [ 'per_page' => 1 ] );
		return $result['entries'][0] ?? null;
	}

	public function cursor( WP_Post $entry ): string {
		$payload = $entry->post_date_gmt . '|' . $entry->ID;
		return rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque pagination cursor, not executable code.
	}

	public function is_valid_cursor( string $cursor ): bool {
		return '' === $cursor || null !== $this->decode_cursor( $cursor );
	}

	/**
	 * @return array{date: string, id: int}|null
	 */
	private function decode_cursor( string $cursor ): ?array {
		if ( '' === $cursor ) {
			return null;
		}

		$padding = strlen( $cursor ) % 4;
		if ( $padding ) {
			$cursor .= str_repeat( '=', 4 - $padding );
		}

		$decoded = base64_decode( strtr( $cursor, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the plugin's pagination cursor.
		if ( ! is_string( $decoded ) || ! str_contains( $decoded, '|' ) ) {
			return null;
		}

		[ $date, $id ] = explode( '|', $decoded, 2 );
		$parsed        = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date, new DateTimeZone( 'UTC' ) );

		if ( ! $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $date || ! ctype_digit( $id ) ) {
			return null;
		}

		return [
			'date' => $date,
			'id'   => absint( $id ),
		];
	}
}

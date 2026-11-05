<?php
/**
 * Blogging Live entry queries and cursors.
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
	public const REMOVED_META = '_blogging_live_removed';

	private const REMOVED_LIMIT = 100;

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
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		];

		if ( $pinned ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded by post_parent; results are object-cached.
			$query_args['meta_query'] = [
				[
					'key'     => '_blogging_live_is_pinned',
					'value'   => '1',
					'compare' => '=',
				],
			];
		}

		$query = $this->query( $query_args, $order, $before ? $before : $after, $before ? '<' : '>' );

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

	/**
	 * Published entries edited at or after a GMT time, most recent edit first.
	 *
	 * @return WP_Post[]
	 */
	public function modified_since( int $blogging_live_id, string $since_gmt, int $limit = 50 ): array {
		$limit     = max( 1, min( 50, $limit ) );
		$cache_key = sprintf( 'modified:%d:%d:%s:%d', $blogging_live_id, $this->cache->generation( $blogging_live_id ), md5( $since_gmt ), $limit );
		$found     = false;
		$cached    = $this->cache->get( $cache_key, $found );

		if ( $found && is_array( $cached ) ) {
			return array_values( array_filter( array_map( 'get_post', $cached ) ) );
		}

		$query = new WP_Query(
			[
				'post_type'              => PostTypes::ENTRY_POST_TYPE,
				'post_parent'            => $blogging_live_id,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'date_query'             => [
					[
						'column'    => 'post_modified_gmt',
						'after'     => $since_gmt,
						'inclusive' => true,
					],
				],
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			]
		);

		$posts = array_values( array_filter( $query->posts, static fn( mixed $post ): bool => $post instanceof WP_Post ) );
		$this->cache->set( $cache_key, wp_list_pluck( $posts, 'ID' ) );

		return $posts;
	}

	/**
	 * IDs of entries that left the public feed at or after a GMT time.
	 *
	 * @return int[]
	 */
	public function removed_since( int $blogging_live_id, string $since_gmt ): array {
		$since   = strtotime( $since_gmt . ' UTC' );
		$removed = get_post_meta( $blogging_live_id, self::REMOVED_META, true );

		if ( ! $since || ! is_array( $removed ) ) {
			return [];
		}

		return array_map( 'absint', array_keys( array_filter( $removed, static fn( mixed $time ): bool => (int) $time >= $since ) ) );
	}

	/**
	 * Record that an entry left the public feed so polling readers can remove it.
	 */
	public function record_removal( int $blogging_live_id, int $entry_id ): void {
		$removed = get_post_meta( $blogging_live_id, self::REMOVED_META, true );
		$removed = is_array( $removed ) ? $removed : [];

		unset( $removed[ $entry_id ] );
		$removed[ $entry_id ] = time();

		update_post_meta( $blogging_live_id, self::REMOVED_META, array_slice( $removed, -self::REMOVED_LIMIT, null, true ) );
	}

	public function forget_removal( int $blogging_live_id, int $entry_id ): void {
		$removed = get_post_meta( $blogging_live_id, self::REMOVED_META, true );

		if ( is_array( $removed ) && isset( $removed[ $entry_id ] ) ) {
			unset( $removed[ $entry_id ] );
			update_post_meta( $blogging_live_id, self::REMOVED_META, $removed );
		}
	}

	public function is_valid_gmt( string $value ): bool {
		$parsed = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return $parsed && $parsed->format( 'Y-m-d H:i:s' ) === $value;
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

		if ( ! $this->is_valid_gmt( $date ) || ! ctype_digit( $id ) ) {
			return null;
		}

		return [
			'date' => $date,
			'id'   => absint( $id ),
		];
	}

	/**
	 * Run an entry query ordered by GMT publish time and ID, optionally after a cursor.
	 *
	 * Ordering by post_date_gmt keeps the order consistent with the cursor across
	 * daylight-saving changes, which post_date (local time) does not.
	 *
	 * @param array<string, mixed>              $query_args WP_Query arguments.
	 * @param array{date: string, id: int}|null $cursor     Cursor to continue from.
	 */
	private function query( array $query_args, string $order, ?array $cursor, string $operator ): WP_Query {
		$orderby = static function ( string $sql ) use ( $order ): string {
			global $wpdb;
			unset( $sql );
			return "{$wpdb->posts}.post_date_gmt {$order}, {$wpdb->posts}.ID {$order}";
		};
		$where   = static function ( string $sql ) use ( $cursor, $operator ): string {
			global $wpdb;

			if ( ! $cursor ) {
				return $sql;
			}

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Operator is constrained to < or > by the caller.
			$cursor_sql = $wpdb->prepare(
				" AND ( {$wpdb->posts}.post_date_gmt {$operator} %s OR ( {$wpdb->posts}.post_date_gmt = %s AND {$wpdb->posts}.ID {$operator} %d ) )",
				$cursor['date'],
				$cursor['date'],
				$cursor['id']
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return $sql . $cursor_sql;
		};

		add_filter( 'posts_orderby', $orderby );
		add_filter( 'posts_where', $where );
		$query = new WP_Query( $query_args );
		remove_filter( 'posts_orderby', $orderby );
		remove_filter( 'posts_where', $where );

		return $query;
	}
}

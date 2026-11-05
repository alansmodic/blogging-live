<?php
/**
 * Convert entry posts into a stable public view model.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive;

use WP_Post;

defined( 'ABSPATH' ) || exit;

final class EntryPresenter {
	/**
	 * Rendered content keyed by entry ID and modified time, so an entry shown in
	 * several places on one page runs through the_content once.
	 *
	 * @var array<string, string>
	 */
	private array $rendered = [];

	/**
	 * @param bool $render_content Whether to run the content through the_content. When false,
	 *                             content.rendered is empty and content.text holds plain text.
	 * @return array<string, mixed>
	 */
	public function present( WP_Post $entry, bool $render_content = true ): array {
		$blogging_live_id = absint( $entry->post_parent );
		$blogging_live    = get_post( $blogging_live_id );
		$host_id          = $blogging_live instanceof WP_Post ? absint( $blogging_live->post_parent ) : 0;
		$author_ids       = array_values( array_filter( array_map( 'absint', (array) get_post_meta( $entry->ID, '_blogging_live_author_ids', true ) ) ) );

		if ( empty( $author_ids ) && $entry->post_author ) {
			$author_ids = [ absint( $entry->post_author ) ];
		}

		$authors = [];
		foreach ( $author_ids as $author_id ) {
			$user = get_userdata( $author_id );
			if ( ! $user ) {
				continue;
			}

			$authors[] = [
				'id'         => $author_id,
				'name'       => $user->display_name,
				'url'        => get_author_posts_url( $author_id ),
				'avatar_url' => get_avatar_url( $author_id, [ 'size' => 64 ] ),
			];
		}

		$rendered_content = $render_content ? $this->render_content( $entry ) : '';

		$published_time      = strtotime( $entry->post_date_gmt . ' UTC' );
		$modified_time       = strtotime( $entry->post_modified_gmt . ' UTC' );
		$published_timestamp = $published_time ? $published_time : 0;
		$modified_timestamp  = $modified_time ? $modified_time : $published_timestamp;
		$host_url            = $host_id ? get_permalink( $host_id ) : '';
		$data                = [
			'id'               => $entry->ID,
			'blogging_live_id' => $blogging_live_id,
			'host_id'          => $host_id,
			'title'            => get_the_title( $entry ),
			'content'          => [
				'rendered' => $rendered_content,
				'text'     => $render_content ? '' : $this->plain_text( $entry ),
			],
			'published_gmt'    => $published_timestamp ? gmdate( 'c', $published_timestamp ) : '',
			'modified_gmt'     => $modified_timestamp ? gmdate( 'c', $modified_timestamp ) : '',
			'published_text'   => $published_timestamp ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $published_timestamp ) : '',
			'authors'          => $authors,
			'is_pinned'        => (bool) get_post_meta( $entry->ID, '_blogging_live_is_pinned', true ),
			'is_key_event'     => (bool) get_post_meta( $entry->ID, '_blogging_live_is_key_event', true ),
			'permalink'        => $host_url ? $host_url . '#blogging-live-entry-' . $entry->ID : '#blogging-live-entry-' . $entry->ID,
		];

		/**
		 * Filters the public representation of a Blogging Live entry.
		 *
		 * @param array<string, mixed> $data  Entry data.
		 * @param WP_Post              $entry Entry post.
		 */
		return apply_filters( 'blogging_live_entry_data', $data, $entry );
	}

	private function render_content( WP_Post $entry ): string {
		$key = $entry->ID . ':' . $entry->post_modified_gmt;

		if ( isset( $this->rendered[ $key ] ) ) {
			return $this->rendered[ $key ];
		}

		global $post;
		$original_post = $post;
		$post          = $entry; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $entry );
		$rendered = (string) apply_filters( 'the_content', $entry->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Applies the core content pipeline to entry blocks.
		$post     = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $original_post instanceof WP_Post ) {
			setup_postdata( $original_post );
		} else {
			wp_reset_postdata();
		}

		$this->rendered[ $key ] = $rendered;

		return $rendered;
	}

	/**
	 * Plain text for contexts such as structured data, without running the_content
	 * filters that themes and plugins use to inject ads, sharing links, and markup.
	 */
	private function plain_text( WP_Post $entry ): string {
		$text = excerpt_remove_blocks( strip_shortcodes( $entry->post_content ) );
		return trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
	}
}

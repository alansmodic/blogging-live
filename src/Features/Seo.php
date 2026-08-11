<?php
/**
 * LiveBlogPosting JSON-LD output.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\EntryPresenter;
use BloggingLive\Feature;
use BloggingLive\Repositories\EntryRepository;
use BloggingLive\Repositories\LiveblogRepository;

defined( 'ABSPATH' ) || exit;

final class Seo implements Feature {
	public function __construct(
		private readonly LiveblogRepository $liveblogs,
		private readonly EntryRepository $entries,
		private readonly EntryPresenter $presenter
	) {}

	public function register(): void {
		add_action( 'wp_head', [ $this, 'output_schema' ], 30 );
	}

	public function output_schema(): void {
		if ( ! is_singular() ) {
			return;
		}

		$host_id  = get_queried_object_id();
		$liveblog = $this->liveblogs->find_for_host( $host_id );
		if ( ! $liveblog || ! $this->liveblogs->is_public( $liveblog->ID ) ) {
			return;
		}

		$host       = get_post( $host_id );
		$feed       = $this->entries->feed( $liveblog->ID, [ 'per_page' => 20 ] );
		$updates    = [];
		$host_url   = get_permalink( $host_id );
		$modified   = (string) get_post_meta( $liveblog->ID, '_blogging_live_last_updated_gmt', true );
		$date_stamp = static fn( string $value ): string => $value && strtotime( $value . ' UTC' ) ? gmdate( 'c', strtotime( $value . ' UTC' ) ) : '';

		foreach ( $feed['entries'] as $entry ) {
			$data      = $this->presenter->present( $entry );
			$author    = $data['authors'][0] ?? null;
			$updates[] = array_filter(
				[
					'@type'         => 'BlogPosting',
					'@id'           => $data['permalink'],
					'url'           => $data['permalink'],
					'headline'      => $data['title'] ? $data['title'] : wp_trim_words( wp_strip_all_tags( $data['content']['rendered'] ), 12 ),
					'articleBody'   => wp_strip_all_tags( $data['content']['rendered'] ),
					'datePublished' => $data['published_gmt'],
					'dateModified'  => $data['modified_gmt'],
					'author'        => $author ? [
						'@type' => 'Person',
						'name'  => $author['name'],
						'url'   => $author['url'],
					] : null,
				]
			);
		}

		$schema = array_filter(
			[
				'@context'          => 'https://schema.org',
				'@type'             => 'LiveBlogPosting',
				'@id'               => $host_url . '#liveblog',
				'url'               => $host_url,
				'headline'          => get_the_title( $host_id ),
				'description'       => $host ? wp_strip_all_tags( get_the_excerpt( $host ) ) : '',
				'datePublished'     => $host ? $date_stamp( $host->post_date_gmt ) : '',
				'dateModified'      => $date_stamp( $modified ? $modified : ( $host ? $host->post_modified_gmt : '' ) ),
				'coverageStartTime' => $date_stamp( (string) get_post_meta( $liveblog->ID, '_blogging_live_start_gmt', true ) ),
				'coverageEndTime'   => $date_stamp( (string) get_post_meta( $liveblog->ID, '_blogging_live_end_gmt', true ) ),
				'liveBlogUpdate'    => $updates,
			]
		);

		/**
		 * Filters LiveBlogPosting structured data.
		 *
		 * @param array<string, mixed> $schema      Schema data.
		 * @param int                  $blogging_live_id Liveblog ID.
		 * @param int                  $host_id     Host post ID.
		 */
		$schema = apply_filters( 'blogging_live_schema', $schema, $liveblog->ID, $host_id );

		if ( empty( $schema ) ) {
			return;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

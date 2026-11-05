<?php
/**
 * REST controller for Blogging Live containers and entries.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Rest;

use BloggingLive\Features\PostTypes;
use BloggingLive\Repositories\BloggingLiveRepository;
use WP_REST_Posts_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Core treats every published post of a REST-enabled type as public. Containers
 * and entries are only public while their host is, so readers must not see an
 * entry published under a draft, unpublished, or disabled host.
 */
final class PostsController extends WP_REST_Posts_Controller {
	/**
	 * @param \WP_Post $post Post object.
	 */
	public function check_read_permission( $post ): bool {
		if ( ! parent::check_read_permission( $post ) ) {
			return false;
		}

		if ( current_user_can( 'edit_post', $post->ID ) ) {
			return true;
		}

		$blogging_live_id = PostTypes::ENTRY_POST_TYPE === $post->post_type ? absint( $post->post_parent ) : $post->ID;

		return ( new BloggingLiveRepository() )->is_public( $blogging_live_id );
	}
}

<?php
/**
 * Liveblog capabilities.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;

defined( 'ABSPATH' ) || exit;

final class Capabilities implements Feature {
	/**
	 * @var string[]
	 */
	private const CAPS = [
		'edit_blogging_live',
		'read_blogging_live',
		'delete_blogging_live',
		'edit_blogging_lives',
		'edit_others_blogging_lives',
		'publish_blogging_lives',
		'read_private_blogging_lives',
		'delete_blogging_lives',
		'delete_private_blogging_lives',
		'delete_published_blogging_lives',
		'delete_others_blogging_lives',
		'edit_private_blogging_lives',
		'edit_published_blogging_lives',
		'create_blogging_lives',
		'edit_blogging_live_entry',
		'read_blogging_live_entry',
		'delete_blogging_live_entry',
		'edit_blogging_live_entries',
		'edit_others_blogging_live_entries',
		'publish_blogging_live_entries',
		'read_private_blogging_live_entries',
		'delete_blogging_live_entries',
		'delete_private_blogging_live_entries',
		'delete_published_blogging_live_entries',
		'delete_others_blogging_live_entries',
		'edit_private_blogging_live_entries',
		'edit_published_blogging_live_entries',
		'create_blogging_live_entries',
	];

	public function register(): void {
		// Capabilities are installed on activation. This feature is a composition marker.
	}

	public static function add_roles_caps(): void {
		foreach ( [ 'administrator', 'editor' ] as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::CAPS as $capability ) {
				$role->add_cap( $capability );
			}
		}
	}

	public static function remove_roles_caps(): void {
		foreach ( [ 'administrator', 'editor' ] as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::CAPS as $capability ) {
				$role->remove_cap( $capability );
			}
		}
	}
}

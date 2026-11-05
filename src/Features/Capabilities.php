<?php
/**
 * Blogging Live capabilities.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Features;

use BloggingLive\Feature;

defined( 'ABSPATH' ) || exit;

final class Capabilities implements Feature {
	/**
	 * Bump when CAPS changes so existing sites receive the new list.
	 */
	public const VERSION = '1';

	public const VERSION_OPTION = 'blogging_live_caps_version';

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
		// Activation hooks do not run for code-loaded plugins (including on
		// WordPress VIP) or on every site of a network, so install lazily.
		add_action( 'init', [ self::class, 'maybe_add_roles_caps' ], 1 );
	}

	public static function maybe_add_roles_caps(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::add_roles_caps();
		}
	}

	public static function add_roles_caps(): void {
		foreach ( self::roles() as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::CAPS as $capability ) {
				$role->add_cap( $capability );
			}
		}

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	public static function remove_roles_caps(): void {
		foreach ( self::roles() as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::CAPS as $capability ) {
				$role->remove_cap( $capability );
			}
		}

		delete_option( self::VERSION_OPTION );
	}

	/**
	 * @return string[]
	 */
	private static function roles(): array {
		/**
		 * Filters the roles that receive Blogging Live capabilities.
		 *
		 * Changing the list affects new installs; bump VERSION or delete the
		 * blogging_live_caps_version option to reapply it on existing sites.
		 *
		 * @param string[] $roles Role names.
		 */
		return (array) apply_filters( 'blogging_live_capability_roles', [ 'administrator', 'editor' ] );
	}
}

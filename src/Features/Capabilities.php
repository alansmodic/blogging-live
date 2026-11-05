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

	public const ROLES_OPTION = 'blogging_live_caps_roles';

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
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) || self::installed_roles() !== self::roles() ) {
			self::add_roles_caps();
		}
	}

	/**
	 * Grant capabilities to the current roles and revoke them from roles that
	 * were removed from the blogging_live_capability_roles filter since the last install.
	 */
	public static function add_roles_caps(): void {
		$roles = self::roles();

		self::set_caps( array_diff( self::installed_roles(), $roles ), false );
		self::set_caps( $roles, true );

		update_option( self::ROLES_OPTION, $roles );
		update_option( self::VERSION_OPTION, self::VERSION );
	}

	public static function remove_roles_caps(): void {
		self::set_caps( array_unique( array_merge( self::roles(), self::installed_roles() ) ), false );

		delete_option( self::ROLES_OPTION );
		delete_option( self::VERSION_OPTION );
	}

	/**
	 * @param string[] $role_names Role names.
	 */
	private static function set_caps( array $role_names, bool $grant ): void {
		foreach ( $role_names as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role ) {
				continue;
			}

			foreach ( self::CAPS as $capability ) {
				if ( $grant ) {
					$role->add_cap( $capability );
				} else {
					$role->remove_cap( $capability );
				}
			}
		}
	}

	/**
	 * Roles that received capabilities at the last install, sorted.
	 *
	 * @return string[]
	 */
	private static function installed_roles(): array {
		$roles = get_option( self::ROLES_OPTION, [] );
		$roles = is_array( $roles ) ? array_values( array_unique( array_map( 'strval', $roles ) ) ) : [];
		sort( $roles );

		return $roles;
	}

	/**
	 * Roles that should hold capabilities, sorted.
	 *
	 * @return string[]
	 */
	private static function roles(): array {
		/**
		 * Filters the roles that receive Blogging Live capabilities.
		 *
		 * Changes apply on the next page load: added roles gain the capabilities
		 * and removed roles lose them.
		 *
		 * @param string[] $roles Role names.
		 */
		$roles = array_values( array_unique( array_map( 'strval', (array) apply_filters( 'blogging_live_capability_roles', [ 'administrator', 'editor' ] ) ) ) );
		sort( $roles );

		return $roles;
	}
}

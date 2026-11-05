<?php
/**
 * Capability installation tests.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

use BloggingLive\Features\Capabilities;
use BloggingLive\Tests\TestCase;

final class CapabilitiesTest extends TestCase {
	public function test_capabilities_install_without_activation_hook(): void {
		Capabilities::remove_roles_caps();
		$this->assertFalse( get_option( Capabilities::VERSION_OPTION ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'edit_blogging_lives' ) );

		// A code-loaded plugin (for example on WordPress VIP) never runs its activation hook,
		// so installation must happen from init.
		$this->assertNotFalse( has_action( 'init', [ Capabilities::class, 'maybe_add_roles_caps' ] ) );
		Capabilities::maybe_add_roles_caps();

		$this->assertTrue( get_role( 'editor' )->has_cap( 'edit_blogging_lives' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'create_blogging_lives' ) );
		$this->assertSame( Capabilities::VERSION, get_option( Capabilities::VERSION_OPTION ) );
	}

	public function test_changing_capability_roles_grants_and_revokes_without_version_bump(): void {
		Capabilities::add_roles_caps();
		$this->assertTrue( get_role( 'editor' )->has_cap( 'edit_blogging_live_entries' ) );

		$roles = static fn(): array => [ 'administrator', 'author' ];
		add_filter( 'blogging_live_capability_roles', $roles );
		Capabilities::maybe_add_roles_caps();

		$this->assertTrue( get_role( 'author' )->has_cap( 'edit_blogging_live_entries' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'edit_blogging_live_entries' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'edit_blogging_live_entries' ) );

		// Uninstall also removes capabilities from roles that left the list.
		remove_filter( 'blogging_live_capability_roles', $roles );
		Capabilities::remove_roles_caps();
		$this->assertFalse( get_role( 'author' )->has_cap( 'edit_blogging_live_entries' ) );
		$this->assertFalse( get_option( Capabilities::ROLES_OPTION ) );

		Capabilities::add_roles_caps();
	}

	public function test_unchanged_roles_do_not_rewrite_capabilities(): void {
		Capabilities::add_roles_caps();
		$writes = 0;
		$count  = static function () use ( &$writes ): void {
			++$writes;
		};
		add_action( 'update_option_' . Capabilities::ROLES_OPTION, $count );

		Capabilities::maybe_add_roles_caps();

		$this->assertSame( 0, $writes );
	}
}

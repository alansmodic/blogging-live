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

	public function test_capability_roles_are_filterable(): void {
		Capabilities::remove_roles_caps();
		add_filter( 'blogging_live_capability_roles', static fn(): array => [ 'author' ] );

		Capabilities::maybe_add_roles_caps();

		$this->assertTrue( get_role( 'author' )->has_cap( 'edit_blogging_live_entries' ) );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'edit_blogging_live_entries' ) );

		Capabilities::remove_roles_caps();
		remove_all_filters( 'blogging_live_capability_roles' );
		Capabilities::add_roles_caps();
	}
}

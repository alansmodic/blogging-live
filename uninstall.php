<?php
/**
 * Blogging Live uninstall routine.
 *
 * Editorial posts and metadata are deliberately preserved.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/blogging-live.php';

$blogging_live_uninstall = static function (): void {
	BloggingLive\Features\Capabilities::remove_roles_caps();
	delete_option( BloggingLive\Features\Settings::OPTION );
};

if ( is_multisite() ) {
	// Roles and options are stored per site.
	foreach ( get_sites(
		[
			'fields' => 'ids',
			'number' => 10000,
		] 
	) as $blogging_live_site_id ) {
		switch_to_blog( (int) $blogging_live_site_id );
		$blogging_live_uninstall();
		restore_current_blog();
	}
} else {
	$blogging_live_uninstall();
}

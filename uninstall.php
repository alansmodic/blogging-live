<?php
/**
 * Liveblog uninstall routine.
 *
 * Editorial posts and metadata are deliberately preserved.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/blogging-live.php';

BloggingLive\Features\Capabilities::remove_roles_caps();
delete_option( BloggingLive\Features\Settings::OPTION );

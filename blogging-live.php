<?php
/**
 * Plugin Name:       Blogging Live
 * Description:       A portable, extensible liveblog system built on WordPress posts, blocks, REST, and progressive enhancement.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Blogging Live Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blogging-live
 * Domain Path:       /languages
 *
 * @package BloggingLive
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'BLOGGING_LIVE_VERSION', '1.0.0' );
define( 'BLOGGING_LIVE_FILE', __FILE__ );
define( 'BLOGGING_LIVE_DIR', __DIR__ );
define( 'BLOGGING_LIVE_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'BloggingLive\\';

		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = BLOGGING_LIVE_DIR . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, [ BloggingLive\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ BloggingLive\Plugin::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ BloggingLive\Plugin::class, 'instance' ] );

<?php
/**
 * Main plugin coordinator.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive;

use BloggingLive\Features\Admin;
use BloggingLive\Features\Assets;
use BloggingLive\Features\Blocks;
use BloggingLive\Features\Cache;
use BloggingLive\Features\Capabilities;
use BloggingLive\Features\Frontend;
use BloggingLive\Features\Lifecycle;
use BloggingLive\Features\Metadata;
use BloggingLive\Features\PostTypes;
use BloggingLive\Features\RestApi;
use BloggingLive\Features\Seo;
use BloggingLive\Features\Settings;
use BloggingLive\Repositories\EntryRepository;
use BloggingLive\Repositories\LiveblogRepository;
use BloggingLive\Templates\TemplateLoader;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static ?self $instance = null;

	/**
	 * Registered features.
	 *
	 * @var Feature[]
	 */
	private array $features = [];

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}

		return self::$instance;
	}

	private function boot(): void {
		load_plugin_textdomain( 'blogging-live', false, dirname( plugin_basename( BLOGGING_LIVE_FILE ) ) . '/languages' );

		$cache          = new Cache();
		$liveblogs      = new LiveblogRepository();
		$entries        = new EntryRepository( $cache );
		$templates      = new TemplateLoader();
		$presenter      = new EntryPresenter();
		$frontend       = new Frontend( $liveblogs, $entries, $templates, $presenter );
		$this->features = [
			new PostTypes(),
			new Metadata(),
			new Capabilities(),
			new Settings(),
			$cache,
			new Lifecycle( $liveblogs, $entries, $cache ),
			new Admin( $liveblogs ),
			new Assets(),
			$frontend,
			new Blocks( $frontend ),
			new RestApi( $liveblogs, $entries, $templates, $presenter ),
			new Seo( $liveblogs, $entries, $presenter ),
		];

		foreach ( $this->features as $feature ) {
			$feature->register();
		}

		do_action( 'blogging_live_loaded', $this );
	}

	public static function activate(): void {
		PostTypes::register_post_types();
		Capabilities::add_roles_caps();
		Settings::add_defaults();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}

<?php
/**
 * Feature contract.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive;

defined( 'ABSPATH' ) || exit;

interface Feature {
	/**
	 * Register the feature's WordPress hooks.
	 */
	public function register(): void;
}

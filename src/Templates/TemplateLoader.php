<?php
/**
 * Theme-overridable template loading.
 *
 * @package BloggingLive
 */

declare(strict_types=1);

namespace BloggingLive\Templates;

defined( 'ABSPATH' ) || exit;

final class TemplateLoader {
	/**
	 * Render a template from the theme's Blogging Live directory or the plugin.
	 *
	 * @param array<string, mixed> $data Template variables.
	 */
	public function render( string $template, array $data = [] ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $data is exposed to the included template.
		$template = sanitize_file_name( $template );
		$path     = locate_template( [ 'blogging-live/' . $template . '.php' ], false, false );

		if ( ! $path ) {
			$path = BLOGGING_LIVE_DIR . '/templates/' . $template . '.php';
		}

		if ( ! is_readable( $path ) ) {
			return '';
		}

		ob_start();
		include $path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
		return (string) ob_get_clean();
	}
}

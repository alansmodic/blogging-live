<?php
/**
 * Feed block editor asset metadata.
 *
 * @package BloggingLive
 */

return [
	'dependencies' => [ 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', 'wp-block-editor', 'wp-server-side-render' ],
	'version'      => (string) filemtime( __DIR__ . '/index.js' ),
];

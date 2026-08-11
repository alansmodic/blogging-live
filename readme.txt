=== Blogging Live ===
Contributors: blogging-live-contributors
Tags: liveblog, publishing, gutenberg, rest-api, newsroom
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A portable liveblog architecture using WordPress posts, blocks, REST, server rendering, and progressive enhancement.

== Description ==

Blogging Live turns a post, page, or public custom post type into a live event. It stores the event in a liveblog container and every update as an individual child post. The plugin includes an editorial workflow, a cursor-based REST feed, a dynamic block, a shortcode, server-rendered templates, polling, object-cache invalidation, and LiveBlogPosting schema.

The base plugin deliberately contains no advertising-network, analytics, CDN, sports-data, or publisher-specific code. Those systems can integrate through documented WordPress hooks and template overrides.

== Installation ==

1. Upload the `blogging-live` folder to `/wp-content/plugins/`.
2. Activate Blogging Live.
3. Edit a post and enable liveblogging in the Liveblog panel.
4. Save the post, then manage the generated liveblog and add updates.

== Frequently Asked Questions ==

= Does it require JavaScript? =

No. Initial entries are rendered in PHP. JavaScript adds polling and pagination.

= Does it create custom database tables? =

No. It uses registered post types and post meta.

= Can a theme control the markup? =

Yes. Copy the templates to `your-theme/blogging-live/`.

= Does uninstall delete published coverage? =

No. Editorial content is preserved by default.

== Changelog ==

= 1.0.0 =
* Initial standalone architecture.

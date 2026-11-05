# Changelog

## Unreleased

- Fixed: capabilities are now installed on `init` (once per site, versioned), so the plugin works when loaded from code on WordPress VIP or network-activated, where activation hooks never run. Changes to `blogging_live_capability_roles` apply on the next load, revoking capabilities from roles removed from the list.
- Fixed: published entries and containers under a draft, unpublished, or disabled host were readable by anyone through the core `wp/v2` REST endpoints. A custom REST controller now applies the plugin's visibility rules.
- Fixed: touching the host's modified time re-saved the host with `wp_update_post()`, which re-sanitized its content for users without `unfiltered_html` and fired `save_post` integrations on every entry change. The column is now updated directly.
- Fixed: `[blogging-live]` caused a fatal error outside a post context, such as in a widget on an archive.
- Fixed: deleting any post meta caused a fatal error because the `deleted_post_meta` handler expected a single meta ID.
- Fixed: feeds now sort by GMT publish time to match pagination cursors across daylight-saving changes. The unused `_blogging_live_sort_timestamp` meta is no longer written.
- Fixed: saving an entry with flag changes refreshed caches and fired `blogging_live_entry_changed` up to four times; it now happens once.
- Added: pinned entries appear in a pinned section above the feed (`blogging_live_pinned_limit`).
- Added: polling starts when a scheduled event goes live, stops when it ends, pauses in hidden tabs, applies edits and removals in place, and runs scripts in new entries so embeds load. REST responses include `status`, `status_label`, `server_time`, and with `since`, `updated` and `removed`.
- Added: block-editor sidebar panels for hosts and entries. Entries can be assigned and published in one save; entries without a Blogging Live are saved as drafts. An invalid or unauthorized `blogging_live_parent` returns a REST error.
- Added: logged-in readers send a REST nonce, so editors can preview draft coverage.
- Added: `WordPress-VIP-Go` and PHP compatibility rulesets in `phpcs.xml.dist`, and tests for capabilities, REST visibility, lifecycle, feeds, and rendering.
- Changed: structured data uses plain text and no longer runs `the_content` in `<head>`; rendered entry content is reused within a request.
- Changed: public REST entries no longer include `content.raw`.
- Changed: `_blogging_live_id` and `_blogging_live_last_updated_gmt` are no longer exposed through core REST meta; hosts expose a read-only `blogging_live_id` field to editors instead.
- Changed: uninstall cleans up every site on a multisite network.
- Renamed remaining "Liveblog" identifiers, labels, and REST routes to "Blogging Live". REST routes are now `/blogging-lives/{id}`, `/blogging-lives/{id}/entries`, and `/posts/{post_id}/blogging-live`; the main template receives `$data['blogging_live']`; `LiveblogRepository` and `PostTypes::LIVEBLOG_POST_TYPE` are now `BloggingLiveRepository` and `PostTypes::BLOGGING_LIVE_POST_TYPE`.

## 1.0.0

- Added host → container → entry post architecture.
- Added editorial meta boxes, capabilities, settings, and block-editor entry support.
- Added server-rendered output, theme template overrides, shortcode, and dynamic block.
- Added cursor-based REST feed and progressively enhanced reader updates.
- Added generation-based object caching and generic invalidation hooks.
- Added `LiveBlogPosting` structured data.

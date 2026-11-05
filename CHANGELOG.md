# Changelog

## Unreleased

- Renamed remaining "Liveblog" identifiers, labels, and REST routes to "Blogging Live". REST routes are now `/blogging-lives/{id}`, `/blogging-lives/{id}/entries`, and `/posts/{post_id}/blogging-live`; the main template receives `$data['blogging_live']`; `LiveblogRepository` and `PostTypes::LIVEBLOG_POST_TYPE` are now `BloggingLiveRepository` and `PostTypes::BLOGGING_LIVE_POST_TYPE`.

## 1.0.0

- Added host → container → entry post architecture.
- Added editorial meta boxes, capabilities, settings, and block-editor entry support.
- Added server-rendered output, theme template overrides, shortcode, and dynamic block.
- Added cursor-based REST feed and progressively enhanced reader updates.
- Added generation-based object caching and generic invalidation hooks.
- Added `LiveBlogPosting` structured data.

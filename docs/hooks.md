# Blogging Live extension hooks

## Lifecycle actions

### `blogging_live_loaded`

Runs after all plugin features have registered their WordPress hooks.

### `blogging_live_created`

```php
do_action( 'blogging_live_created', int $blogging_live_id, int $host_id );
```

### `blogging_live_entry_changed`

Runs after an entry is saved, unpublished, restored, or deleted and the parent timestamps and caches have been refreshed.

```php
do_action( 'blogging_live_entry_changed', int $entry_id, int $blogging_live_id, int $host_id );
```

### `blogging_live_entry_published`

Runs for a published entry after cache invalidation. Integrations should make their handlers idempotent because a published entry can be edited more than once.

```php
do_action( 'blogging_live_entry_published', int $entry_id, int $blogging_live_id, int $host_id );
```

### `blogging_live_cache_invalidated`

```php
do_action( 'blogging_live_cache_invalidated', int $blogging_live_id, int $generation );
```

Use this for a CDN or full-page-cache purge. The base plugin does not depend on a hosting provider.

## Data filters

### `blogging_live_capability_roles`

Filters the roles that receive Blogging Live capabilities. Changes apply on the next page load of each site: roles added to the list gain the capabilities and roles removed from it lose them.

```php
add_filter(
	'blogging_live_capability_roles',
	static fn( array $roles ): array => [ 'administrator', 'editor', 'author' ]
);
```

### `blogging_live_pinned_limit`

Filters how many pinned entries appear above the feed (default 3, maximum 10). Return `0` to hide the pinned section.

### `blogging_live_host_post_types`

Filters public post types that can host a Blogging Live.

```php
add_filter(
	'blogging_live_host_post_types',
	static fn( array $post_types ): array => [ 'post', 'article' ]
);
```

### `blogging_live_entry_data`

Filters the normalized entry representation used by REST, templates, and schema. `content.rendered` holds the rendered HTML. For structured data, the plugin skips `the_content`, so `content.rendered` is empty and `content.text` holds plain text instead.

```php
add_filter(
	'blogging_live_entry_data',
	static function ( array $data, WP_Post $entry ): array {
		$data['sponsor'] = get_post_meta( $entry->ID, '_sponsor', true );
		return $data;
	},
	10,
	2
);
```

### `blogging_live_touch_host_post`

Controls whether a published entry change updates the host post's `post_modified` value. The plugin updates the column directly and calls `clean_post_cache()`; it does not re-save the host, so `save_post` does not fire for the host.

```php
add_filter( 'blogging_live_touch_host_post', '__return_false' );
```

### `blogging_live_entry_content_html`

Filters trusted rendered block content immediately before an entry template outputs it.

### `blogging_live_html`

Filters the complete server-rendered Blogging Live container.

### `blogging_live_schema`

Filters the complete `LiveBlogPosting` array. Return an empty array to suppress the plugin's JSON-LD.

## Template integration

Ads, related links, or other site-specific components can usually be inserted cleanly by overriding `blogging-live/entry.php` in the theme. For behavior that should travel with a separate plugin, filter `blogging_live_html` or attach browser listeners to the container's events:

- `blogging-live:entries-added` — `detail.ids` of entries added to the feed;
- `blogging-live:entries-updated` — `detail.ids` of entries replaced after an edit;
- `blogging-live:entries-removed` — `detail.ids` of entries removed after being unpublished, moved, or deleted;
- `blogging-live:status-changed` — `detail.status` is the new event status;
- `blogging-live:error` — `detail` is the request error.

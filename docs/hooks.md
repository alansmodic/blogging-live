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

### `blogging_live_host_post_types`

Filters public post types that can host a liveblog.

```php
add_filter(
	'blogging_live_host_post_types',
	static fn( array $post_types ): array => [ 'post', 'article' ]
);
```

### `blogging_live_entry_data`

Filters the normalized entry representation used by REST, templates, and schema.

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

Controls whether saving a published entry updates the host post's `post_modified` value.

```php
add_filter( 'blogging_live_touch_host_post', '__return_false' );
```

### `blogging_live_entry_content_html`

Filters trusted rendered block content immediately before an entry template outputs it.

### `blogging_live_html`

Filters the complete server-rendered liveblog container.

### `blogging_live_schema`

Filters the complete `LiveBlogPosting` array. Return an empty array to suppress the plugin's JSON-LD.

## Template integration

Ads, related links, or other site-specific components can usually be inserted cleanly by overriding `blogging-live/entry.php` in the theme. For behavior that should travel with a separate plugin, filter `blogging_live_html` or attach a browser listener to `blogging-live:entries-added`.

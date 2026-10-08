# Media metadata storage

`MediaMetadata` owns routing for `_wp_attached_file` and `_wp_attachment_metadata`. The plugin entry point bootstraps it independently of the Database module so these values remain accessible in every request context. `wpopt_media_metadata_enabled` records the selected mode: absent means unconfigured, `1` means optimized storage, and `0` means WordPress postmeta storage managed by the plugin.

## Storage and migration

The blog-prefixed `wpopt_media_postmeta` table preserves metadata IDs, duplicate keys and raw values. The blog-prefixed `media_metadata` projection contains `meta_id`, unique `object_id`, `_wp_attached_file` and `_wp_attachment_metadata`; filenames have a 255-character limit. Existing compatible projections are merged without overwriting conflicting values or replacing their schema.

Mode changes run under a database lock and an InnoDB transaction. Enabling copies media keys to the journal, validates conflicts and filename limits, updates the projection and removes only copied postmeta rows. Disabling restores the journal to postmeta and clears optimized storage. Errors roll back the transaction. Object and option caches are invalidated during cleanup.

## Integration ownership

The plugin publishes `wpopt_media_metadata_state_changed(bool $enabled)` after saving a mode successfully, and `wpopt_media_metadata_invalidated(int $attachment_id)` after invalidating its caches. Consumers own their adapters, competing handlers and derived cache groups. Invalidation may also occur during failed-write cleanup. See [the external API](../EXTERNAL-API.md#media-metadata-lifecycle) for the hook contracts.

## Verification

From the CMS root, run `php mini-test/media-metadata.php` and repeat with `--managed-before-theme`. The tests use the real WordPress hook dispatcher and production metadata classes with isolated database, option and cache fakes. They check standalone plugin startup, successful and failed mode changes, cache invalidation and consumer handler ownership. No site bootstrap, database connection or live migration is performed.

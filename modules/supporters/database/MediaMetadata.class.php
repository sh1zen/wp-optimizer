<?php
/** Reversible storage for the two WordPress media metadata keys. */
namespace WPOptimizer\modules\supporters;

class MediaMetadata
{
    const STATE_OPTION = 'wpopt_media_metadata_enabled';
    const META_TYPE = 'wpopt_media';
    const KEYS = ['_wp_attached_file', '_wp_attachment_metadata'];

    private static ?self $instance = null;
    private int $lock_depth = 0;
    private bool $passthrough = false;

    public static function bootstrap(): self
    {
        if (self::$instance) {
            return self::$instance;
        }
        $self = self::$instance = new self();
        // Register independently of the Database module: stored media must remain
        // available in REST, cron, AJAX and when that module is hidden/disabled.
        add_filter('get_post_metadata', [$self, 'get'], 1, 5);
        add_filter('add_post_metadata', [$self, 'add'], 1, 5);
        add_filter('update_post_metadata', [$self, 'update'], 1, 5);
        add_filter('delete_post_metadata', [$self, 'delete'], 1, 5);
        add_filter('get_post_metadata_by_mid', [$self, 'get_by_mid'], 1, 2);
        add_filter('update_post_metadata_by_mid', [$self, 'update_by_mid'], 1, 4);
        add_filter('delete_post_metadata_by_mid', [$self, 'delete_by_mid'], 1, 2);
        add_filter('xmli_fast_add_postmeta', [$self, 'import'], 1, 2);
        add_filter('pre_attachment_url_to_postid', [$self, 'attachment_url'], 20, 2);
        add_filter('attachment_url_to_postid', [$self, 'attachment_url'], 20, 2);
        add_filter('attachment_path_to_postid', [$self, 'attachment_path'], 20, 2);
        add_action('before_delete_post', [$self, 'delete_object']);
        add_action('switch_blog', [$self, 'register_table']);
        add_filter('add_wpopt_media_metadata', [$self, 'allocate_id'], 10, 5);
        foreach (['add', 'added', 'update', 'updated', 'delete', 'deleted'] as $event) {
            add_action("{$event}_wpopt_media_meta", function (...$args) use ($event) {
                do_action("{$event}_post_meta", ...$args);
                if ($event === 'update' || $event === 'updated') {
                    $args[3] = maybe_serialize($args[3]);
                    do_action("{$event}_postmeta", ...$args);
                }
                if ($event === 'delete' || $event === 'deleted') {
                    do_action("{$event}_postmeta", $args[0]);
                }
            }, 10, 4);
        }
        $self->register_table();
        return $self;
    }

    private function table(string $suffix): string
    {
        global $wpdb;
        $name = $wpdb->prefix . $suffix;
        // MySQL identifiers are limited to 64 characters. Keep journal names
        // distinct even when a valid site prefix leaves little room for a suffix.
        if ($suffix === 'wpopt_media_postmeta' && strlen($name) > 64) {
            return substr($wpdb->prefix, 0, 64 - 17 - strlen($suffix)) . substr(md5($wpdb->prefix), 0, 16) . '_' . $suffix;
        }
        return $name;
    }

    private function quote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    public function register_table(): void
    {
        global $wpdb;
        $wpdb->wpopt_mediameta = $this->table('wpopt_media_postmeta');
    }

    public function enabled(): bool
    {
        return (bool)get_option(self::STATE_OPTION, false);
    }

    public function managed(): bool
    {
        // Existing Flex-and-Go installations keep their media readable until
        // their first explicit optimization setting is saved.
        return get_option(self::STATE_OPTION, null) !== null;
    }

    private function detach_theme(): void
    {
        if (!class_exists('ScaledMeta', false)) { return; }
        try { $legacy = \ScaledMeta::getInstance('media_meta'); }
        catch (\Throwable $error) { return; }
        foreach (['add_post_metadata' => 'filter_set_postmeta', 'update_post_metadata' => 'filter_set_postmeta',
                  'delete_post_metadata' => 'filter_delete_postmeta', 'get_post_metadata' => 'filter_get_meta',
                  'xmli_fast_add_postmeta' => 'filter_xmli_add_meta'] as $hook => $method) {
            remove_filter($hook, [$legacy, $method], 10);
        }
    }

    private function database_enabled(): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            'SELECT option_value FROM ' . $this->quote($wpdb->options) . ' WHERE option_name = %s', self::STATE_OPTION
        )) === '1';
    }

    private function query(string $sql): int
    {
        global $wpdb;
        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new \RuntimeException(__('Media metadata operation failed; no data was moved.', 'wpopt'));
        }
        return (int)$result;
    }

    private function locked(callable $callback)
    {
        global $wpdb;
        if ($this->lock_depth) {
            return $callback();
        }
        $name = 'wpopt_media_' . md5(DB_NAME . ':' . $wpdb->prefix);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 30)', $name)) !== 1) {
            throw new \RuntimeException(__('Another media migration is running. Please try again when it finishes.', 'wpopt'));
        }
        $this->lock_depth++;
        try {
            return $callback();
        }
        finally {
            $this->lock_depth--;
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    private function exists(string $table): bool
    {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table
        ));
    }

    private function prepare_tables(): void
    {
        global $wpdb;
        $media = $this->quote($this->table('media_metadata'));
        // Do not use dbDelta: it can alter an existing table's columns/indexes.
        if (!$this->exists($this->table('media_metadata'))) {
            $this->query("CREATE TABLE $media (
                meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                object_id BIGINT UNSIGNED NOT NULL,
                _wp_attached_file VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                _wp_attachment_metadata LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                PRIMARY KEY (meta_id), UNIQUE KEY idx_meta (object_id) USING BTREE
            ) ENGINE=InnoDB " . $wpdb->get_charset_collate());
        }
        $columns = $wpdb->get_results("SHOW COLUMNS FROM $media", OBJECT_K);
        $indexes = $wpdb->get_results("SHOW INDEX FROM $media", ARRAY_A);
        $unique = [];
        foreach ($indexes as $index) {
            if (!(int)$index['Non_unique']) {
                $unique[$index['Key_name']][] = $index['Column_name'];
            }
        }
        if (!isset($columns['meta_id'], $columns['object_id'], $columns[self::KEYS[0]], $columns[self::KEYS[1]]) ||
            !in_array(['object_id'], $unique, true)) {
            throw new \RuntimeException(__('The existing media metadata table has an incompatible schema. Its structure was left unchanged.', 'wpopt'));
        }
        $journal = $this->quote($this->table('wpopt_media_postmeta'));
        // A separate journal preserves duplicate keys and original postmeta IDs;
        // those cannot be represented by the existing wide media table alone.
        $this->query("CREATE TABLE IF NOT EXISTS $journal (
            meta_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            wpopt_media_id BIGINT UNSIGNED NOT NULL,
            meta_key VARCHAR(255) DEFAULT NULL,
            meta_value LONGTEXT DEFAULT NULL,
            PRIMARY KEY (meta_id), KEY object_key (wpopt_media_id, meta_key(191))
        ) ENGINE=InnoDB " . $wpdb->get_charset_collate());
        foreach ([$wpdb->postmeta, $wpdb->options, $this->table('media_metadata'), $this->table('wpopt_media_postmeta')] as $table) {
            $engine = $wpdb->get_var($wpdb->prepare(
                'SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $table
            ));
            if (strcasecmp((string)$engine, 'InnoDB') !== 0) {
                throw new \RuntimeException(__('Media optimization requires InnoDB tables for safe rollback. No existing table was changed.', 'wpopt'));
            }
        }
    }

    public function set_enabled(bool $enabled): void
    {
        $this->locked(function () use ($enabled) {
            global $wpdb;
            $was_enabled = $this->database_enabled();
            if ($was_enabled === $enabled && $this->managed()) {
                return;
            }
            if (!$enabled && !$was_enabled && !$this->exists($this->table('media_metadata'))) {
                update_option(self::STATE_OPTION, '0', false);
                if (!$this->managed()) {
                    throw new \RuntimeException(__('Could not save the media optimization state.', 'wpopt'));
                }
                $this->detach_theme();
                return;
            }
            $this->register_table();
            $this->prepare_tables();
            // This may be a large migration; the setting warns the administrator.
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            $postmeta = $this->quote($wpdb->postmeta);
            $journal = $this->quote($wpdb->wpopt_mediameta);
            $media = $this->quote($this->table('media_metadata'));
            $ids = [];
            $this->query('START TRANSACTION');
            try {
                if (!$was_enabled) {
                    if ((int)$wpdb->get_var("SELECT COUNT(*) FROM $journal")) {
                        throw new \RuntimeException(__('A previous media rollback journal still contains data. Migration stopped to protect it.', 'wpopt'));
                    }
                    // Lock source rows against updates while making the exact copy.
                    $this->query("INSERT INTO $journal (meta_id, wpopt_media_id, meta_key, meta_value)
                        SELECT meta_id, post_id, meta_key, meta_value FROM $postmeta
                        WHERE BINARY meta_key IN ('_wp_attached_file', '_wp_attachment_metadata') ORDER BY meta_id FOR UPDATE");
                    // Adopt data already stored by Flex-and-Go, without replacing
                    // postmeta values or allocating colliding metadata IDs.
                    foreach (self::KEYS as $key) {
                        $conflict = $wpdb->get_var("SELECT m.object_id FROM $media m INNER JOIN $journal j
                            ON j.meta_id = (SELECT MIN(existing.meta_id) FROM $journal existing
                                WHERE existing.wpopt_media_id = m.object_id AND existing.meta_key = '$key')
                            WHERE m.`$key` IS NOT NULL AND NOT (BINARY m.`$key` <=> BINARY j.meta_value) LIMIT 1");
                        if ($conflict !== null) {
                            throw new \RuntimeException(__('Conflicting media metadata exists in both tables. Migration stopped without overwriting either copy.', 'wpopt'));
                        }
                        // Reserve IDs in bulk through postmeta's own sequence.
                        // Copy raw strings in SQL: no unserialization or unbounded
                        // PHP array of attachment metadata is needed.
                        $this->query("INSERT INTO $postmeta (post_id, meta_key, meta_value)
                            SELECT m.object_id, '$key', m.`$key` FROM $media m
                            WHERE m.`$key` IS NOT NULL AND NOT EXISTS (SELECT 1 FROM $journal j
                                WHERE j.wpopt_media_id = m.object_id AND j.meta_key = '$key') FOR UPDATE");
                        $this->query("INSERT INTO $journal (meta_id, wpopt_media_id, meta_key, meta_value)
                            SELECT p.meta_id, p.post_id, p.meta_key, p.meta_value FROM $postmeta p
                            WHERE BINARY p.meta_key = '$key' AND NOT EXISTS
                                (SELECT 1 FROM $journal j WHERE j.meta_id = p.meta_id)");
                    }
                    $ids = $wpdb->get_col("SELECT DISTINCT wpopt_media_id FROM $journal");
                    if ($wpdb->get_var("SELECT meta_id FROM $journal WHERE meta_key = '_wp_attached_file' AND CHAR_LENGTH(meta_value) > 255 LIMIT 1")) {
                        throw new \RuntimeException(__('A media filename exceeds the existing table limit of 255 characters. No data was moved.', 'wpopt'));
                    }
                    // Populate the unchanged wide schema in SQL instead of loading
                    // and unserializing every attachment in PHP during migration.
                    $this->query("INSERT INTO $media (object_id, _wp_attached_file, _wp_attachment_metadata)
                        SELECT objects.wpopt_media_id, files.meta_value, metadata.meta_value
                        FROM (SELECT DISTINCT wpopt_media_id FROM $journal) objects
                        LEFT JOIN $journal files ON files.meta_id = (SELECT MIN(f.meta_id) FROM $journal f
                            WHERE f.wpopt_media_id = objects.wpopt_media_id AND f.meta_key = '_wp_attached_file')
                        LEFT JOIN $journal metadata ON metadata.meta_id = (SELECT MIN(a.meta_id) FROM $journal a
                            WHERE a.wpopt_media_id = objects.wpopt_media_id AND a.meta_key = '_wp_attachment_metadata')
                        ON DUPLICATE KEY UPDATE _wp_attached_file = VALUES(_wp_attached_file),
                            _wp_attachment_metadata = VALUES(_wp_attachment_metadata)");
                    // Delete only rows whose exact copies have been stored.
                    $this->query("DELETE p FROM $postmeta p INNER JOIN $journal j ON p.meta_id = j.meta_id
                        AND p.post_id = j.wpopt_media_id AND BINARY p.meta_key = BINARY j.meta_key
                        AND BINARY p.meta_value <=> BINARY j.meta_value");
                }
                if (!$enabled) {
                    $ids = $wpdb->get_col("SELECT DISTINCT wpopt_media_id FROM $journal");
                    // A collision is a hard failure, never INSERT IGNORE/REPLACE.
                    $this->query("INSERT INTO $postmeta (meta_id, post_id, meta_key, meta_value)
                        SELECT meta_id, wpopt_media_id, meta_key, meta_value FROM $journal ORDER BY meta_id");
                    $this->query("DELETE FROM $journal");
                    $this->query("DELETE FROM $media");
                }
                $this->query($wpdb->prepare('INSERT INTO ' . $this->quote($wpdb->options) .
                    ' (option_name, option_value, autoload) VALUES (%s, %s, %s)
                     ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)', self::STATE_OPTION, $enabled ? '1' : '0', 'no'));
                $this->query('COMMIT');
            }
            catch (\Throwable $error) {
                $wpdb->query('ROLLBACK');
                throw $error;
            }
            finally {
                foreach ($ids as $id) {
                    $this->invalidate((int)$id);
                }
                wp_cache_delete(self::STATE_OPTION, 'options');
                wp_cache_delete('notoptions', 'options');
                wp_cache_delete('alloptions', 'options');
            }
            $this->detach_theme();
        });
    }

    private function invalidate(int $id): void
    {
        wp_cache_delete($id, self::META_TYPE . '_meta');
        wp_cache_delete($id, 'post_meta');
        wp_cache_delete($id, 'scaledMeta:media_meta');
        wp_cache_delete($id, 'wpopt_media_row');
    }

    private function project(int $id): void
    {
        global $wpdb;
        wp_cache_delete($id, self::META_TYPE . '_meta');
        $values = get_metadata_raw(self::META_TYPE, $id) ?: [];
        if (!$values) {
            if ($wpdb->delete($this->table('media_metadata'), ['object_id' => $id]) === false) {
                throw new \RuntimeException(__('Could not remove media metadata. The operation was rolled back.', 'wpopt'));
            }
            $this->invalidate($id);
            return;
        }
        $fields = [];
        foreach (self::KEYS as $key) {
            $fields[$key] = $values[$key][0] ?? null;
        }
        if ($fields['_wp_attached_file'] !== null && preg_match_all('/./us', $fields['_wp_attached_file']) > 255) {
            // Never silently truncate a filename to fit the theme's schema.
            throw new \RuntimeException(__('A media filename exceeds the existing table limit of 255 characters. No data was moved.', 'wpopt'));
        }
        $media = $this->table('media_metadata');
        if ($wpdb->get_var($wpdb->prepare('SELECT meta_id FROM ' . $this->quote($media) . ' WHERE object_id = %d', $id))) {
            $result = $wpdb->update($media, $fields, ['object_id' => $id]);
        }
        else {
            $result = $wpdb->insert($media, ['object_id' => $id] + $fields);
        }
        if ($result === false) {
            throw new \RuntimeException(__('Could not store media metadata. The operation was rolled back.', 'wpopt'));
        }
        $this->invalidate($id);
    }

    public function get($raw, $id, $key, $single, $type = 'post')
    {
        if ($raw !== null || $this->passthrough || !$this->enabled() || ($key !== '' && !in_array($key, self::KEYS, true))) {
            return $raw;
        }
        $this->register_table();
        if ($key !== '' && $single) {
            global $wpdb;
            $row = wp_cache_get($id, 'wpopt_media_row');
            if ($row === false) {
                $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $this->quote($this->table('media_metadata')) . ' WHERE object_id = %d', $id), ARRAY_A) ?: [];
                wp_cache_set($id, $row, 'wpopt_media_row');
            }
            // Core unwraps index zero, including arrays, false and empty strings.
            return isset($row[$key]) ? [maybe_unserialize($row[$key])] : null;
        }
        $values = get_metadata_raw(self::META_TYPE, $id, $key, false);
        if ($key !== '') {
            return $values;
        }
        $this->passthrough = true;
        try {
            $original = get_metadata_raw('post', $id) ?: [];
        }
        finally {
            $this->passthrough = false;
        }
        return array_merge($original, $values ?: []);
    }

    private function write($raw, string $key, callable $callback, callable $fallback)
    {
        if ($raw !== null || $this->passthrough || !$this->managed() || !in_array($key, self::KEYS, true)) {
            return $raw;
        }
        try {
            return $this->locked(function () use ($callback, $fallback) {
                global $wpdb;
                if (!$this->database_enabled()) {
                    $this->passthrough = true;
                    try { return $fallback(); }
                    finally { $this->passthrough = false; }
                }
                $this->register_table();
                $this->query('START TRANSACTION');
                $ids = [];
                try {
                    // Callback returns affected objects even for delete-all.
                    [$result, $ids] = $callback();
                    foreach ($ids as $id) {
                        $this->project((int)$id);
                    }
                    $this->query('COMMIT');
                    return $result;
                }
                catch (\Throwable $error) {
                    $wpdb->query('ROLLBACK');
                    throw $error;
                }
                finally {
                    foreach ($ids as $id) { $this->invalidate((int)$id); }
                }
            });
        }
        catch (\Throwable $error) {
            // False short-circuits Core; never fall back to splitting the data.
            return false;
        }
    }

    public function allocate_id($raw, $id, $key, $value, $unique)
    {
        if ($raw !== null) { return $raw; }
        if ($unique && metadata_exists(self::META_TYPE, $id, $key)) { return false; }
        do_action('add_wpopt_media_meta', $id, $key, $value);
        $mid = $this->store_raw((int)$id, $key, maybe_serialize($value));
        do_action('added_wpopt_media_meta', $mid, $id, $key, $value);
        return $mid;
    }

    private function store_raw(int $id, string $key, $value): int
    {
        global $wpdb;
        // Reserve IDs from postmeta's sequence, so unrelated new post metadata
        // cannot collide with IDs retained in the reversible journal.
        if ($wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $key, 'meta_value' => $value]) === false) {
            throw new \RuntimeException('Could not reserve a metadata ID.');
        }
        $mid = (int)$wpdb->insert_id;
        if ($wpdb->insert($wpdb->wpopt_mediameta, ['meta_id' => $mid, 'wpopt_media_id' => $id, 'meta_key' => $key, 'meta_value' => $value]) === false ||
            $wpdb->delete($wpdb->postmeta, ['meta_id' => $mid]) === false) {
            throw new \RuntimeException('Could not preserve a metadata ID.');
        }
        $this->invalidate((int)$id);
        return $mid;
    }

    public function add($raw, $id, $key, $value, $unique = false)
    {
        return $this->write($raw, $key, function () use ($id, $key, $value, $unique) {
            return [add_metadata(self::META_TYPE, $id, $key, wp_slash($value), $unique), [$id]];
        }, function () use ($id, $key, $value, $unique) {
            return add_metadata('post', $id, $key, wp_slash($value), $unique);
        });
    }

    public function update($raw, $id, $key, $value, $previous = '')
    {
        return $this->write($raw, $key, function () use ($id, $key, $value, $previous) {
            return [update_metadata(self::META_TYPE, $id, $key, wp_slash($value), $previous), [$id]];
        }, function () use ($id, $key, $value, $previous) {
            return update_metadata('post', $id, $key, wp_slash($value), $previous);
        });
    }

    public function delete($raw, $id, $key, $value = '', $all = false)
    {
        return $this->write($raw, $key, function () use ($id, $key, $value, $all) {
            global $wpdb;
            $ids = $all ? $wpdb->get_col($wpdb->prepare('SELECT DISTINCT wpopt_media_id FROM ' . $this->quote($wpdb->wpopt_mediameta) . ' WHERE meta_key = %s', $key)) : [$id];
            return [delete_metadata(self::META_TYPE, $id, $key, $value, $all), $ids];
        }, function () use ($id, $key, $value, $all) {
            return delete_metadata('post', $id, $key, $value, $all);
        });
    }

    public function get_by_mid($raw, $mid)
    {
        if ($raw !== null || !$this->enabled()) { return $raw; }
        $this->register_table();
        $meta = get_metadata_by_mid(self::META_TYPE, $mid);
        if (!$meta) { return $raw; }
        $meta->post_id = $meta->wpopt_media_id;
        unset($meta->wpopt_media_id);
        return $meta;
    }

    public function update_by_mid($raw, $mid, $value, $key = false)
    {
        $meta = $this->get_by_mid(null, $mid);
        if (!$meta || $raw !== null) { return $raw; }
        // Renaming out of the optimized keys must return that row to postmeta.
        return $this->write($raw, $meta->meta_key, function () use ($meta, $mid, $value, $key) {
            global $wpdb;
            $new_key = $key === false ? $meta->meta_key : $key;
            if (!is_string($new_key)) { return [false, []]; }
            $value = sanitize_meta($new_key, $value, 'post', get_object_subtype('post', $meta->post_id));
            $result = update_metadata_by_mid(self::META_TYPE, $mid, $value, $key);
            if ($result && !in_array($new_key, self::KEYS, true)) {
                $this->query($wpdb->prepare('INSERT INTO ' . $this->quote($wpdb->postmeta) .
                    ' (meta_id, post_id, meta_key, meta_value) VALUES (%d, %d, %s, %s)', $mid, $meta->post_id, $new_key, maybe_serialize($value)));
                $this->query($wpdb->prepare('DELETE FROM ' . $this->quote($wpdb->wpopt_mediameta) . ' WHERE meta_id = %d', $mid));
            }
            return [$result, [$meta->post_id]];
        }, function () use ($mid, $value, $key) { return update_metadata_by_mid('post', $mid, $value, $key); });
    }

    public function delete_by_mid($raw, $mid)
    {
        $meta = $this->get_by_mid(null, $mid);
        if (!$meta || $raw !== null) { return $raw; }
        return $this->write($raw, $meta->meta_key, function () use ($meta, $mid) {
            return [delete_metadata_by_mid(self::META_TYPE, $mid), [$meta->post_id]];
        }, function () use ($mid) { return delete_metadata_by_mid('post', $mid); });
    }

    public function import($metadata, $id)
    {
        if (!$this->enabled()) { return $metadata; }
        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $metadata)) {
                if ($this->update(null, $id, $key, $metadata[$key]) === false &&
                    get_metadata('post', $id, $key, true) !== $metadata[$key]) {
                    throw new \RuntimeException('Could not import media metadata.');
                }
                unset($metadata[$key]);
            }
        }
        return $metadata;
    }

    public function attachment_url($raw, $url)
    {
        if ($raw || !$this->enabled()) { return $raw; }
        $uploads = wp_get_upload_dir();
        $base = wp_parse_url($uploads['baseurl']);
        $target = wp_parse_url($url);
        if (!$base || !$target || ($base['host'] ?? '') !== ($target['host'] ?? '')) { return $raw; }
        $base_path = rtrim($base['path'] ?? '', '/') . '/';
        $path = $target['path'] ?? '';
        if (strpos($path, $base_path) !== 0) { return $raw; }
        return $this->find_file(substr($path, strlen($base_path))) ?: $raw;
    }

    public function attachment_path($raw, $path)
    {
        if ($raw || !$this->enabled()) { return $raw; }
        $uploads = wp_get_upload_dir();
        $base = rtrim(wp_normalize_path($uploads['basedir']), '/') . '/';
        $path = wp_normalize_path($path);
        if (strpos($path, $base) !== 0) { return $raw; }
        return $this->find_file(substr($path, strlen($base))) ?: $raw;
    }

    private function find_file(string $path): int
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT object_id, _wp_attached_file FROM ' .
            $this->quote($this->table('media_metadata')) . ' WHERE _wp_attached_file = %s', $path), ARRAY_A);
        foreach ($rows as $row) {
            if ($row['_wp_attached_file'] === $path) { return (int)$row['object_id']; }
        }
        return (int)($rows[0]['object_id'] ?? 0);
    }

    public function delete_object($id): void
    {
        if (!$this->enabled()) { return; }
        $result = $this->write(null, self::KEYS[0], function () use ($id) {
            foreach (self::KEYS as $key) {
                if (metadata_exists(self::META_TYPE, $id, $key) && !delete_metadata(self::META_TYPE, $id, $key)) {
                    throw new \RuntimeException('Could not delete media metadata.');
                }
            }
            return [true, [$id]];
        }, static function () { return true; });
        if ($result === false) {
            throw new \RuntimeException(__('Post deletion stopped because its media metadata could not be removed.', 'wpopt'));
        }
    }
}

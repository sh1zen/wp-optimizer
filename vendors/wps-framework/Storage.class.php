<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Storage
{
    private ?Cache $cache;

    private string $storage_name;

    private bool $is_multisite;

    private string $blog_prefix;

    private bool $active;
    private bool $persist_zero_expiry;

    /**
     * to disable autosave set this to false
     */
    private ?bool $autosave_action = null;

    public function __construct($context, $active = true, $use_cache = true, $use_multisite_prefix = true, $persist_zero_expiry = false)
    {
        // Storage owns its multisite path, so the inner cache must not prefix it again.
        // Its storage fallback is disabled to avoid recursively creating Storage.
        $this->persist_zero_expiry = (bool)$persist_zero_expiry;
        $this->cache = $use_cache ? new Cache('wps-storage', $active, false, false, $this->persist_zero_expiry) : null;

        $this->is_multisite = $use_multisite_prefix && is_multisite();

        $this->active = $active;

        $this->switch_to_blog();

        $this->storage_name = $context;
    }

    public function switch_to_blog($blog_id = 0): bool
    {
        if ($blog_id === 0) {
            $blog_id = get_current_blog_id();
        }

        $this->blog_prefix = $this->is_multisite ? "#$blog_id" : '';

        return $this->is_multisite;
    }

    public function autosave()
    {
        if (!$this->cache) {
            return;
        }

        $this->cache->iterate(function ($data, $key, $context) {

            if (empty($data['write'])) {
                return;
            }

            if ($data['expire'] and $data['expire'] < time()) {
                return;
            }

            $this->save($data, $key, $context, $data['force']);
        });
    }

    /** Publish one complete serialized record without exposing in-progress writes. */
    private function save($data, $key, $context, $force = false): bool
    {
        if (!$this->active) {
            return false;
        }

        if (isset($data['write']) and !$data['write']) {
            return true;
        }

        $path = $this->generate_path($context);
        $target = $path . $key;

        if (file_exists($target) and !$force) {
            return false;
        }

        unset($data['write']);

        if (!($cached = serialize($data)) or !Disk::make_path($path)) {
            return false;
        }

        // Each writer owns a separate file on the destination filesystem. Opening
        // the final entry with truncation would let competing writers mix records.
        $temporary = dirname($target) . '/.wps-' . bin2hex(random_bytes(16)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            return false;
        }

        try {
            $complete = @fwrite($handle, $cached) === strlen($cached) && @fflush($handle);
            $closed = @fclose($handle);
            $handle = null;
            if (!$complete || !$closed) {
                return false;
            }

            return $this->mutate(static function () use ($target, $temporary, $force): bool {
                // Check and publication must be ordered with other writers and purges.
                clearstatcache(true, $target);
                if (!$force && file_exists($target)) {
                    return false;
                }

                return @rename($temporary, $target);
            });
        } finally {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** Serialize filesystem mutations using a stable lock outside all storage groups. */
    private function mutate(callable $mutation)
    {
        $directory = WP_CONTENT_DIR . '/.wps-storage-locks';
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('WPS Storage cannot create mutation lock directory: ' . $directory);
        }

        $path = $directory . '/' . hash('sha256', UtilEnv::normalize_path($this->generate_path(''))) . '.lock';
        $lock = @fopen($path, 'c+b');
        if ($lock === false) {
            throw new \RuntimeException('WPS Storage cannot open mutation lock: ' . $path);
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('WPS Storage cannot acquire mutation lock: ' . $path);
            }

            return $mutation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            // Keep the lock file so that all requests continue to lock the same inode.
        }
    }

    private function generate_path($context, $key = ''): string
    {
        $context = str_replace('default', '', $context);

        return WP_CONTENT_DIR . "/$this->storage_name/$context/$key";
    }

    public function delete_old($context = 'default', $lifetime = 0, $blog_id = 0): int
    {
        $path = $this->get_path($context, '', $blog_id);
        return $this->mutate(static function () use ($path, $lifetime): int {
            return Disk::delete($path, $lifetime);
        });
    }

    public function delete($context = 'default', $key = '', $blog_id = 0): int
    {
        $context = $this->filter_context($context, $blog_id);
        $path = $this->generate_path($context, $key);
        $res = $this->mutate(static function () use ($path): int {
            return Disk::delete($path, 0, '*');
        });

        if ($this->cache) {
            if (empty($key)) {
                $this->cache->flush_group($context);
            }
            else {
                $this->cache->delete($key, $context);
            }
        }

        return $res;
    }

    /** Detach a group into an existing directory within this storage namespace. */
    public function detach($context, string $destination, $blog_id = 0): bool
    {
        $context = $this->filter_context($context, $blog_id);
        $path = $this->generate_path($context);
        $detached = $this->mutate(function () use ($path, $destination): bool {
            clearstatcache();
            $source = realpath($path);
            if (!$source || !is_dir($source)) {
                return false;
            }

            $root = realpath($this->generate_path(''));
            $parent = realpath(dirname($destination));
            if (!$root || !$parent) {
                return false;
            }
            $root = trailingslashit(UtilEnv::normalize_path($root));
            $source = UtilEnv::normalize_path($source);
            $parent = trailingslashit(UtilEnv::normalize_path($parent));
            if (strpos($source . '/', $root) !== 0 || strpos($parent, $root) !== 0
                || strpos($parent, $source . '/') === 0
                || file_exists($destination) || is_link($destination)) {
                return false;
            }

            return @rename($source, $destination);
        });
        if ($detached && $this->cache) {
            $this->cache->flush_group($context);
        }
        return $detached;
    }

    public function get_path($context = 'default', $key = '', $blog_id = 0): string
    {
        return $this->generate_path($this->filter_context($context, $blog_id), $key);
    }

    private function filter_context($context, $blog_id = 0)
    {
        $this->switch_to_blog($blog_id);

        if (empty($context)) {
            $context = 'default';
        }

        if ($this->is_multisite) {
            $context = "$context/$this->blog_prefix";
        }

        return $context;
    }

    public function get($key = '', $context = 'default', $blog_id = 0, &$expires_at = null)
    {
        $res = $this->cache ? $this->cache->get($key, $this->filter_context($context, $blog_id), null) : null;

        if (is_array($res) && array_key_exists('data', $res)) {
            $expires_at = (int)($res['expire'] ?? 0);
            return $res['data'];
        }

        return $this->load($key, $context, true, $blog_id, $expires_at);
    }

    public function load($key, $context = 'default', $cache = false, $blog_id = 0, &$expires_at = null)
    {
        $context = $this->filter_context($context, $blog_id);

        $path = $this->generate_path($context, $key);

        if (!$this->active) {
            return false;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        $data = @unserialize($contents);

        if (empty($data) or ($data['expire'] and $data['expire'] < time())) {
            $this->mutate(static function () use ($path, $contents): void {
                // Decoding can outlive this version. Compare and unlink under the
                // publication lock so a stale snapshot cannot remove its replacement.
                if (@file_get_contents($path) === $contents) {
                    @unlink($path);
                }
            });
            return false;
        }

        $expires_at = (int)($data['expire'] ?? 0);

        $data['write'] = false;

        if ($cache and $this->cache) {
            $this->mutate(function () use ($path, $contents, $key, $data, $context): void {
                // A decoded snapshot remains a valid response to its in-flight read,
                // but must not populate memory after a replacement or purge.
                if (@file_get_contents($path) !== $contents || ($data['expire'] && $data['expire'] < time())) {
                    return;
                }
                $remaining_lifetime = !empty($data['expire']) ? max(1, (int)$data['expire'] - time()) : 0;
                $this->cache->set($key, $data, $context, true, $remaining_lifetime);
            });
        }

        return $data['data'];
    }

    public function set($data, $key = 'main', $context = 'default', $expire = false, $force = false, $blog_id = 0): bool
    {
        if (empty($key)) {
            return false;
        }

        $context = $this->filter_context($context, $blog_id);

        $retention = max(0, (int)$expire);
        $expires_at = $retention > 0 ? time() + $retention : 0;
        $persist = $retention > 0 || $this->persist_zero_expiry;

        $args = array(
            'expire' => $expires_at,
            'data'   => $data,
            'force'  => $force,
            'write'  => $persist
        );

        $cached = true;
        if ($this->cache) {
            $cached = $this->cache->set($key, $args, $context, $force, $retention);
        }

        if (!$cached) {
            return false;
        }

        // Ordinary WPS storage keeps zero retention local; WordPress opts into durability.
        if (!$persist || !$this->active) {
            if (!$persist) {
                // Prevent a previous durable version of this key resurfacing later.
                $path = $this->generate_path($context, $key);
                $this->mutate(static function () use ($path): void { @unlink($path); });
            }

            return $cached;
        }

        return $this->handle_autosave($args, $key, $context, $force);
    }

    private function handle_autosave($content, $key, $context, $force): bool
    {
        if (!$this->cache) {
            return $this->save($content, $key, $context, $force);
        }

        if (is_null($this->autosave_action)) {
            $this->autosave_action = add_action('shutdown', array($this, 'autosave'));
        }

        if (!$this->autosave_action) {
            return $this->save($content, $key, $context, $force);
        }

        return true;
    }

    public function get_size_bytes($contexts = '', $blog_id = 0): int
    {
        $size = 0;

        foreach ((array)$contexts as $context) {

            $context = $this->filter_context($context, $blog_id);

            $path = $this->generate_path($context);

            if (!file_exists($path)) {
                continue;
            }

            $size += Disk::calc_size($path);
        }

        return $size;
    }

    public function get_size($contexts = '', $blog_id = 0): string
    {
        $size = $this->get_size_bytes($contexts, $blog_id);

        return (string)size_format($size);
    }

    public function status(): bool
    {
        return $this->active and is_writeable($this->generate_path(''));
    }
}


<?php
/**
 * Centralized filesystem logger for the WPS framework.
 */

namespace WPS\core;

class Debug
{
    /** Relative to WP_CONTENT_DIR, which is the project's cms/data directory. */
    private const ROOT_DIRECTORY = 'flex-and-go/debug';

    private const CONTEXTS = array(
        'application',
        'api',
        'cron',
        'database',
        'framework',
        'geolocation',
        'mail',
        'requests',
        'security',
        'wordpress',
    );

    private const LEVELS = array('debug', 'info', 'notice', 'warning', 'error', 'critical');

    public static function initialize(): bool
    {
        $ready = self::ensure_directory(self::get_root_path(), true);

        foreach (self::CONTEXTS as $context) {
            $ready = self::ensure_directory(self::get_context_path($context)) && $ready;
        }

        return $ready;
    }

    public static function log($message, string $context = 'application', string $level = 'debug'): bool
    {
        $context = self::normalize_context($context);
        $level = self::normalize_level($level);
        $path = self::get_log_path($context);

        if (!self::ensure_directory(dirname($path))) {
            return false;
        }

        $record = array(
            'timestamp' => function_exists('wps_time') ? wps_time('c') : date('c'),
            'level'     => $level,
            'context'   => $context,
            'message'   => self::normalize_message($message),
            'request'   => (string)($_SERVER['REQUEST_URI'] ?? ''),
            'source'    => self::caller_source(),
        );

        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded)
            && false !== file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function get_root_path(): string
    {
        return rtrim(WP_CONTENT_DIR, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::ROOT_DIRECTORY) . DIRECTORY_SEPARATOR;
    }

    public static function get_context_path(string $context): string
    {
        return self::get_root_path() . self::normalize_context($context) . DIRECTORY_SEPARATOR;
    }

    public static function get_log_path(string $context = 'application'): string
    {
        return self::get_context_path($context) . 'events.log';
    }

    public static function get_wordpress_log_path(): string
    {
        return self::get_context_path('wordpress') . 'debug.log';
    }

    public static function get_log_files(int $limit = 100): array
    {
        $root = realpath(self::get_root_path());

        if (!$root || !is_dir($root)) {
            return array();
        }

        $root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $files = array();

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'log') {
                    continue;
                }

                $path = $file->getPathname();
                $relative_path = str_replace('\\', '/', substr($path, strlen($root)));
                $context = strtok($relative_path, '/') ?: 'application';

                $files[] = array(
                    'path'          => $path,
                    'name'          => basename($path),
                    'context'       => $context,
                    'relative_path' => $relative_path,
                    'bytes'         => (int)$file->getSize(),
                    'mtime'         => (int)$file->getMTime(),
                    'readable'      => is_readable($path),
                );
            }
        }
        catch (\UnexpectedValueException $exception) {
            return array();
        }

        usort($files, static function (array $first, array $second): int {
            return $second['mtime'] <=> $first['mtime'];
        });

        return array_slice($files, 0, max(1, $limit));
    }

    public static function read_tail(string $path, int $lines = 200): array
    {
        $path = realpath($path);
        $root = realpath(self::get_root_path());

        if (!$path || !$root || !self::path_is_inside($path, $root) || !is_readable($path)) {
            return array();
        }

        $size = (int)filesize($path);
        if ($size <= 0) {
            return array();
        }

        $chunk_size = min($size, 524288);
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return array();
        }

        if ($size > $chunk_size) {
            fseek($handle, -$chunk_size, SEEK_END);
        }

        $raw = stream_get_contents($handle);
        fclose($handle);

        if (!is_string($raw) || $raw === '') {
            return array();
        }

        $content = preg_split("/\r\n|\n|\r/", $raw) ?: array();

        return array_values(array_filter(
            array_slice($content, -max(1, $lines)),
            static fn($line): bool => $line !== ''
        ));
    }

    public static function clear(?string $context = null): array
    {
        $target = $context === null ? self::get_root_path() : self::get_context_path($context);
        $cleared = 0;
        $failed = 0;

        if (!is_dir($target)) {
            return compact('cleared', 'failed');
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'log') {
                    continue;
                }

                if (false === file_put_contents($file->getPathname(), '', LOCK_EX)) {
                    $failed++;
                }
                else {
                    $cleared++;
                }
            }
        }
        catch (\UnexpectedValueException $exception) {
            $failed++;
        }

        return compact('cleared', 'failed');
    }

    private static function normalize_context(string $context): string
    {
        $context = function_exists('sanitize_key') ? sanitize_key($context) : strtolower(preg_replace('/[^a-z0-9_-]/i', '', $context));

        return in_array($context, self::CONTEXTS, true) ? $context : 'application';
    }

    private static function normalize_level(string $level): string
    {
        $level = function_exists('sanitize_key') ? sanitize_key($level) : strtolower(preg_replace('/[^a-z]/i', '', $level));

        return in_array($level, self::LEVELS, true) ? $level : 'debug';
    }

    private static function normalize_message($message)
    {
        if ($message instanceof \Throwable) {
            return array(
                'exception' => get_class($message),
                'message'   => $message->getMessage(),
                'file'      => $message->getFile(),
                'line'      => $message->getLine(),
                'trace'     => $message->getTraceAsString(),
            );
        }

        if (is_resource($message)) {
            return sprintf('resource(%s)', get_resource_type($message));
        }

        return $message;
    }

    private static function caller_source(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $caller = $trace[3] ?? $trace[2] ?? array();
        $file = (string)($caller['file'] ?? '');
        $line = (int)($caller['line'] ?? 0);

        if (defined('ABSPATH') && $file !== '') {
            $file = str_replace(
                UtilEnv::normalize_path(ABSPATH),
                '',
                UtilEnv::normalize_path($file)
            );
        }

        return $file . ($line > 0 ? ':' . $line : '');
    }

    private static function path_is_inside(string $path, string $root): bool
    {
        $path = strtolower(str_replace('\\', '/', rtrim($path, '/\\')));
        $root = strtolower(str_replace('\\', '/', rtrim($root, '/\\')));

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function ensure_directory(string $path, bool $private = false): bool
    {
        if (is_dir($path)) {
            return is_writable($path);
        }

        return Disk::make_path($path, $private);
    }
}

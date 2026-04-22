<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

use WPS\core\CronActions;
use WPOptimizer\core\PluginInit;
use WPOptimizer\modules\supporters\ImagesProcessor;

function wpopt_optimize_image(string $path, bool $replace = true, array $settings = [])
{
    if (!wps('wpopt')) {
        return false;
    }

    require_once WPOPT_SUPPORTERS . '/media/ImagesProcessor.class.php';

    $settings = array_merge(wps('wpopt')->settings->get('media'), $settings);

    $imageProcessor = ImagesProcessor::getInstance($settings);

    if ($imageProcessor->optimize_image($path, $replace, false) === \WPOptimizer\modules\supporters\IPC_SUCCESS) {
        return $imageProcessor->get_metadata('file');
    }

    return false;
}

/**
 * wpopt utility useful to scan a directory and optimize images present
 */
function wpopt_optimize_media_path(string $path, array $settings = []): bool
{
    if (!wps('wpopt')) {
        return false;
    }

    require_once WPOPT_SUPPORTERS . '/media/ImagesProcessor.class.php';

    $settings = array_merge(wps('wpopt')->settings->get('media'), $settings);

    wps('wpopt')->options->update("status", 'optimization', 'running', "media");

    $scan_res = ImagesProcessor::getInstance($settings)->scan_dir($path);

    if ($scan_res === \WPOptimizer\modules\supporters\IPC_TIME_LIMIT) {
        CronActions::schedule_function('wpopt_optimize_media_path', 'wpopt_optimize_media_path', time() + 30, [$path, $settings]);
    }
    else {
        CronActions::unschedule_function('wpopt_optimize_media_path');
        wps('wpopt')->options->update("status", 'optimization', 'paused', "media");
    }

    return true;
}

function wpopt_minify_html($html, $options = [])
{
    require_once WPOPT_SUPPORTERS . '/minifier/Minify.class.php';
    require_once WPOPT_SUPPORTERS . '/minifier/Minify_HTML.class.php';
    require_once WPOPT_SUPPORTERS . '/minifier/Minify_CSS.class.php';
    require_once WPOPT_SUPPORTERS . '/minifier/Minify_JS.class.php';

    return \WPOptimizer\modules\supporters\Minify_HTML::minify($html, $options);
}

function wpopt_minify_css($css, $options = [])
{
    require_once WPOPT_SUPPORTERS . '/minifier/Minify.class.php';
    require_once WPOPT_SUPPORTERS . '/minifier/Minify_CSS.class.php';

    return \WPOptimizer\modules\supporters\Minify_CSS::minify($css, $options);
}

function wpopt_minify_javascript($css, $options = [])
{
    require_once WPOPT_SUPPORTERS . '/minifier/Minify.class.php';
    require_once WPOPT_SUPPORTERS . '/minifier/Minify_JS.class.php';

    return \WPOptimizer\modules\supporters\Minify_JS::minify($css, $options);
}

function wpopt_record_cache_metric(string $layer, string $outcome, int $count = 1): void
{
    if ($count < 1) {
        return;
    }

    if (!isset($GLOBALS['wpopt_request_cache_metrics']) || !is_array($GLOBALS['wpopt_request_cache_metrics'])) {
        $GLOBALS['wpopt_request_cache_metrics'] = array(
            'cache_hits'         => 0,
            'cache_misses'       => 0,
            'db_cache_hits'      => 0,
            'db_cache_misses'    => 0,
            'query_cache_hits'   => 0,
            'query_cache_misses' => 0,
        );
    }

    $layer = $layer === 'query' ? 'query' : 'db';
    $outcome = $outcome === 'miss' ? 'miss' : 'hit';

    $GLOBALS['wpopt_request_cache_metrics'][$outcome === 'hit' ? 'cache_hits' : 'cache_misses'] += $count;

    if ($layer === 'query') {
        $GLOBALS['wpopt_request_cache_metrics'][$outcome === 'hit' ? 'query_cache_hits' : 'query_cache_misses'] += $count;
        return;
    }

    $GLOBALS['wpopt_request_cache_metrics'][$outcome === 'hit' ? 'db_cache_hits' : 'db_cache_misses'] += $count;
}

function wpopt_get_request_cache_metrics(): array
{
    $metrics = isset($GLOBALS['wpopt_request_cache_metrics']) && is_array($GLOBALS['wpopt_request_cache_metrics'])
        ? $GLOBALS['wpopt_request_cache_metrics']
        : array();

    $metrics = array_merge(
        array(
            'cache_hits'         => 0,
            'cache_misses'       => 0,
            'db_cache_hits'      => 0,
            'db_cache_misses'    => 0,
            'query_cache_hits'   => 0,
            'query_cache_misses' => 0,
        ),
        $metrics
    );

    $metrics['db_cache_hits'] = max(0, absint($metrics['db_cache_hits']));
    $metrics['db_cache_misses'] = max(0, absint($metrics['db_cache_misses']));
    $metrics['query_cache_hits'] = max(0, absint($metrics['query_cache_hits']));
    $metrics['query_cache_misses'] = max(0, absint($metrics['query_cache_misses']));
    $metrics['cache_hits'] = $metrics['db_cache_hits'] + $metrics['query_cache_hits'];
    $metrics['cache_misses'] = $metrics['db_cache_misses'] + $metrics['query_cache_misses'];

    return $metrics;
}

function wpopt_get_activity_log_password_encryption_key(): string
{
    static $key = null;

    if (is_string($key)) {
        return $key;
    }

    $key_material = implode('|', [
        wp_salt('auth'),
        wp_salt('secure_auth'),
        wp_salt('logged_in'),
        'wpopt-activity-log-password',
    ]);

    $key = hash('sha256', $key_material, true);

    return $key;
}

function wpopt_encrypt_activity_log_password(string $password): array
{
    $payload = [
        'password_encrypted' => '',
        'password_present'   => '' !== $password,
        'enc_version'        => 1,
    ];

    if ('' === $password || !function_exists('openssl_encrypt')) {
        return $payload;
    }

    $cipher = 'aes-256-gcm';
    $iv_length = openssl_cipher_iv_length($cipher);

    if (!is_int($iv_length) || $iv_length <= 0) {
        return $payload;
    }

    try {
        $iv = random_bytes($iv_length);
    }
    catch (\Exception $exception) {
        return $payload;
    }

    $tag = '';
    $ciphertext = openssl_encrypt(
        $password,
        $cipher,
        wpopt_get_activity_log_password_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'wpopt-activity-log-password-v1'
    );

    if (!is_string($ciphertext) || '' === $ciphertext || !is_string($tag) || '' === $tag) {
        return $payload;
    }

    $encoded_payload = wp_json_encode([
        'v'   => 1,
        'iv'  => base64_encode($iv),
        'tag' => base64_encode($tag),
        'ct'  => base64_encode($ciphertext),
    ]);

    if (!is_string($encoded_payload) || '' === $encoded_payload) {
        return $payload;
    }

    $payload['password_encrypted'] = base64_encode($encoded_payload);

    return $payload;
}

function wpopt_decrypt_activity_log_password($payload): string
{
    if (!is_array($payload)) {
        return '';
    }

    $encrypted_payload = $payload['password_encrypted'] ?? '';

    if (!is_string($encrypted_payload) || '' === $encrypted_payload || !function_exists('openssl_decrypt')) {
        return '';
    }

    $decoded_payload = base64_decode($encrypted_payload, true);

    if (!is_string($decoded_payload) || '' === $decoded_payload) {
        return '';
    }

    $decoded_payload = json_decode($decoded_payload, true);

    if (!is_array($decoded_payload) || (int)($decoded_payload['v'] ?? 0) !== 1) {
        return '';
    }

    $iv = base64_decode((string)($decoded_payload['iv'] ?? ''), true);
    $tag = base64_decode((string)($decoded_payload['tag'] ?? ''), true);
    $ciphertext = base64_decode((string)($decoded_payload['ct'] ?? ''), true);

    if (!is_string($iv) || !is_string($tag) || !is_string($ciphertext) || '' === $tag) {
        return '';
    }

    $decrypted = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        wpopt_get_activity_log_password_encryption_key(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        'wpopt-activity-log-password-v1'
    );

    return is_string($decrypted) ? $decrypted : '';
}


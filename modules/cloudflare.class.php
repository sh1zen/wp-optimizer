<?php
/**
 * Cloudflare cache purge integration.
 */

namespace WPOptimizer\modules;

use WPS\core\Ajax;
use WPS\core\RequestActions;
use WPS\core\UtilEnv;
use WPS\modules\Module;

class Mod_Cloudflare extends Module
{
    public static ?string $name = 'Cloudflare';

    public array $scopes = array('core-settings', 'admin', 'ajax');

    protected string $context = 'wpopt';

    private const API_BASE = 'https://api.cloudflare.com/client/v4';

    public function restricted_access($context = ''): bool
    {
        switch ($context) {
            case 'settings':
            case 'render-admin':
            case 'ajax':
                return !current_user_can('manage_options');

            default:
                return false;
        }
    }

    public function enqueue_scripts(): void
    {
        parent::enqueue_scripts();

        $script_asset = UtilEnv::resolve_asset(WPOPT_ABSPATH, 'modules/supporters/cloudflare/cloudflare.js', wps_core()->online);
        wp_enqueue_script('wpopt-cloudflare-page', $script_asset['url'], array('vendor-wps-js'), $script_asset['version'] ?: WPOPT_VERSION, true);
    }

    public function ajax_handler($args = array()): void
    {
        $this->save_ajax_settings($args);

        $response = false;

        switch ($args['action'] ?? '') {
            case 'test_connection':
                $response = $this->test_connection();
                break;

            case 'purge_now':
                $response = $this->purge_cache();
                break;

            default:
                Ajax::response([
                    'text' => __('Invalid Cloudflare action.', 'wpopt'),
                ], 'error');
        }

        Ajax::response([
            'text' => $response
                ? __('Cloudflare action was correctly executed.', 'wpopt')
                : __('Cloudflare action failed. Check the API token, Zone ID and account permissions.', 'wpopt'),
        ], $response ? 'success' : 'error');
    }

    private function save_ajax_settings(array $args): void
    {
        if (empty($args['form_data'])) {
            return;
        }

        parse_str((string)$args['form_data'], $form_data);

        $context = wps($this->context)->settings->get_context();
        $payload = $form_data[$context] ?? [];

        if (!is_array($payload) || (($payload['change'] ?? '') !== $this->slug)) {
            return;
        }

        $settings = wps($this->context)->settings->get('', []);
        $settings[$this->slug] = $this->validate_settings($payload);

        wps($this->context)->settings->reset($settings);
    }

    public function actions(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        RequestActions::request($this->action_hook, function ($action) {
            $response = false;

            if ($action === 'test_connection') {
                $response = $this->test_connection();
            }
            elseif ($action === 'purge_now') {
                $response = $this->purge_cache();
            }

            if ($response) {
                $this->add_notices('success', __('Cloudflare action was correctly executed.', 'wpopt'));
            }
            else {
                $this->add_notices('warning', __('Cloudflare action failed. Check the API token, Zone ID and account permissions.', 'wpopt'));
            }
        });
    }

    public function validate_settings($input, $filtering = false): array
    {
        $strategy = sanitize_key((string)($input['purge_strategy'] ?? 'host'));

        if (!array_key_exists($strategy, self::purge_strategy_options())) {
            $strategy = 'host';
        }

        return array(
            'enabled'        => !empty($input['enabled']),
            'api_token'      => $this->sanitize_api_token((string)($input['api_token'] ?? '')),
            'zone_id'        => sanitize_text_field((string)($input['zone_id'] ?? '')),
            'purge_strategy' => $strategy,
        );
    }

    public function render_settings($filter = ''): string
    {
        if ($this->restricted_access('settings')) {
            return '<block><h2>' . esc_html__('This Module is disabled for you or for your settings.', 'wpopt') . '</h2></block>';
        }

        $this->enqueue_scripts();

        $option_name = wps($this->context)->settings->get_context();
        $settings = $this->settings();
        $strategies = self::purge_strategy_options();
        $nonce = wp_create_nonce('wpopt-ajax-nonce');

        ob_start();
        ?>
        <form action="options.php" method="post" autocomplete="off" autocapitalize="off" class="wpopt-cloudflare-form">
            <?php settings_fields("{$this->context}-settings"); ?>
            <input type="hidden" name="option_panel" value="settings-<?php echo esc_attr($this->slug); ?>">
            <input type="hidden" name="<?php echo esc_attr("{$option_name}[change]"); ?>" value="<?php echo esc_attr($this->slug); ?>">

            <section class="wpopt-cloudflare-card">
                <div class="wpopt-cloudflare-panel">
                    <div class="wpopt-cloudflare-head">
                        <h2><?php esc_html_e('Cloudflare', 'wpopt'); ?></h2>
                        <p><?php esc_html_e('When page cache is cleared, also tell Cloudflare to drop its edge copy.', 'wpopt'); ?></p>
                    </div>

                    <label class="wpopt-cloudflare-toggle">
                        <input class="wps-apple-switch wpopt-cloudflare-enabled" type="checkbox" name="<?php echo esc_attr("{$option_name}[enabled]"); ?>" value="1" <?php checked($settings['enabled']); ?>>
                        <strong><?php esc_html_e('Enable Cloudflare integration', 'wpopt'); ?></strong>
                    </label>

                    <details class="wpopt-cloudflare-help">
                        <summary><?php esc_html_e('How to get your API Token & Zone ID', 'wpopt'); ?></summary>
                        <div>
                            <p><?php esc_html_e('Create a Cloudflare API token with Zone:Cache Purge:Edit and Zone:Zone:Read permissions for the target zone. Copy the Zone ID from the website overview page in Cloudflare.', 'wpopt'); ?></p>
                        </div>
                    </details>

                    <div class="wpopt-cloudflare-fields" <?php echo $settings['enabled'] ? '' : 'hidden'; ?>>
                        <label class="wpopt-cloudflare-field" for="wpopt-cf-api-token">
                            <span><?php esc_html_e('API Token', 'wpopt'); ?></span>
                            <input id="wpopt-cf-api-token" class="regular-text" type="password" name="<?php echo esc_attr("{$option_name}[api_token]"); ?>" value="<?php echo esc_attr($settings['api_token']); ?>" autocomplete="new-password" spellcheck="false">
                        </label>

                        <label class="wpopt-cloudflare-field" for="wpopt-cf-zone-id">
                            <span><?php esc_html_e('Zone ID', 'wpopt'); ?></span>
                            <input id="wpopt-cf-zone-id" class="regular-text" type="text" name="<?php echo esc_attr("{$option_name}[zone_id]"); ?>" value="<?php echo esc_attr($settings['zone_id']); ?>" spellcheck="false">
                        </label>

                        <label class="wpopt-cloudflare-field" for="wpopt-cf-purge-strategy">
                            <span><?php esc_html_e('Purge Strategy', 'wpopt'); ?></span>
                            <select id="wpopt-cf-purge-strategy" name="<?php echo esc_attr("{$option_name}[purge_strategy]"); ?>">
                                <?php foreach ($strategies as $value => $label) : ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($settings['purge_strategy'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </div>
            </section>

            <section class="wpopt-cloudflare-submit" <?php echo $settings['enabled'] ? '' : 'hidden'; ?>>
                <button class="button button-secondary wpopt-cloudflare-action" type="button" data-action="test_connection" data-nonce="<?php echo esc_attr($nonce); ?>" <?php disabled(!$settings['enabled']); ?>>
                    <?php esc_html_e('Test Connection', 'wpopt'); ?>
                </button>
                <button class="button button-secondary wpopt-cloudflare-action" type="button" data-action="purge_now" data-nonce="<?php echo esc_attr($nonce); ?>" <?php disabled(!$settings['enabled']); ?>>
                    <?php esc_html_e('Purge Cloudflare Now', 'wpopt'); ?>
                </button>
            </section>

            <section class="wps-submit" hidden></section>
        </form>
        <?php

        return ob_get_clean();
    }

    public function purge_cache(): bool
    {
        $settings = $this->settings();

        if (empty($settings['enabled']) || !$this->has_credentials($settings)) {
            return false;
        }

        $response = $this->send_purge_request($settings, $this->build_purge_body($settings['purge_strategy']));

        if ($this->response_is_success($response)) {
            return true;
        }

        if ($settings['purge_strategy'] === 'tags') {
            return $this->response_is_success($this->send_purge_request($settings, $this->build_purge_body('everything')));
        }

        return false;
    }

    private function test_connection(): bool
    {
        $settings = $this->settings();

        if (!$this->has_credentials($settings)) {
            return false;
        }

        $response = wp_remote_get(
            self::API_BASE . '/zones/' . rawurlencode($settings['zone_id']),
            array(
                'timeout' => 15,
                'headers' => $this->request_headers($settings['api_token']),
            )
        );

        return $this->response_is_success($response);
    }

    private function send_purge_request(array $settings, array $body)
    {
        return wp_remote_post(
            self::API_BASE . '/zones/' . rawurlencode($settings['zone_id']) . '/purge_cache',
            array(
                'timeout' => 15,
                'headers' => $this->request_headers($settings['api_token']),
                'body'    => wp_json_encode($body),
            )
        );
    }

    private function build_purge_body(string $strategy): array
    {
        if ($strategy === 'tags') {
            $host = wp_parse_url(home_url(), PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return array('tags' => array($host));
            }
        }

        if ($strategy === 'host') {
            $host = wp_parse_url(home_url(), PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return array('hosts' => array($host));
            }
        }

        return array('purge_everything' => true);
    }

    private function response_is_success($response): bool
    {
        if (is_wp_error($response)) {
            return false;
        }

        $status = (int)wp_remote_retrieve_response_code($response);
        $body = json_decode((string)wp_remote_retrieve_body($response), true);

        return $status >= 200 && $status < 300 && is_array($body) && !empty($body['success']);
    }

    private function request_headers(string $token): array
    {
        return array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type'  => 'application/json',
        );
    }

    private function has_credentials(array $settings): bool
    {
        return $settings['api_token'] !== '' && $settings['zone_id'] !== '';
    }

    private function settings(): array
    {
        return wp_parse_args(
            (array)wps($this->context)->settings->get($this->slug, array()),
            array(
                'enabled'        => false,
                'api_token'      => '',
                'zone_id'        => '',
                'purge_strategy' => 'host',
            )
        );
    }

    private function sanitize_api_token(string $token): string
    {
        return preg_replace('/[^A-Za-z0-9_\\-.]/', '', sanitize_text_field(wp_unslash($token)));
    }

    private static function purge_strategy_options(): array
    {
        return array(
            'tags'       => __('By cache tag (Enterprise) - fall back to everything', 'wpopt'),
            'host'       => __('By host', 'wpopt'),
            'everything' => __('Purge everything', 'wpopt'),
        );
    }
}

return __NAMESPACE__;

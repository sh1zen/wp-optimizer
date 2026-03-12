<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\modules;

use PHPMailer\PHPMailer\PHPMailer;
use WPOptimizer\modules\supporters\WPMails;
use WPS\core\addon\Exporter;
use WPS\core\CronActions;
use WPS\core\Query;
use WPS\core\RequestActions;
use WPS\core\Rewriter;
use WPS\core\StringHelper;
use WPS\modules\Module;

class Mod_WP_Mail extends Module
{
    public static ?string $name = 'WP Mail';

    public array $scopes = array('settings', 'autoload', 'admin-page');

    protected string $context = 'wpopt';

    public function actions(): void
    {
        if ($this->option('auto_clear', false)) {
            CronActions::schedule("WPOPT-WP-Mails", DAY_IN_SECONDS, function () {
                 Query::getInstance()->delete(['sent_date' => wps_time('mysql', WEEK_IN_SECONDS), 'compare' => '<'], WPOPT_TABLE_LOG_MAILS)->query();
            }, '08:00');
        }

        RequestActions::request($this->action_hook, function ($action) {

            require_once WPS_ADDON_PATH . 'Exporter.class.php';

            switch ($action) {

                case 'export':

                    require_once WPOPT_SUPPORTERS . 'wp-mails/WPMails_Table.class.php';

                    $format = $_REQUEST['export-format'] ?? 'csv';

                    $table = new WPMails(['action_hook' => $this->action_hook, 'settings' => $this->option()]);

                    $exporter = new Exporter();

                    $exporter->format($format)->set_data($table->get_items())->prepare()->download('wp-mails');
                    break;

                case 'reset':

                    Query::getInstance()->tables(WPOPT_TABLE_LOG_MAILS)->action('TRUNCATE')->query();

                    Rewriter::getInstance(admin_url('admin.php'))->add_query_args(array(
                        'page'    => 'wpopt-wp_mail',
                        'message' => 'wpopt-wpmails-data-erased',
                    ))->redirect();
                    break;
            }
        });
    }

    public function mail_logger($mail_info)
    {
        global $wpdb;

        $original_mail_info = $mail_info;

        $mail_to = is_array($mail_info['to']) ? $mail_info['to'] : explode(',', $mail_info['to']);
        $mail_to = array_map('sanitize_email', $mail_to);

        $attachedFiles = [];
        if (!empty($mail_info['attachments'])) {
            $files = $mail_info['attachments'];

            foreach ($files as $value) {
                $attachedFiles[] = substr($value, strpos($value, '/uploads/') + strlen('/uploads/') - 1, strlen($value));
            }
        }

        $allowed_html = wp_kses_allowed_html('post');
        $allowed_html['style'] = [];

        $wpdb->insert(
            WPOPT_TABLE_LOG_MAILS,
            [
                'to_email'         => implode(', ', $mail_to),
                'subject'          => StringHelper::sanitize_text($mail_info['subject']),
                'message'          => wp_kses($mail_info['message'], $allowed_html),
                'headers'          => implode(',', (array)$mail_info['headers']),
                'sent_date'        => current_time('mysql', 0),
                'sent_date_gmt'    => current_time('mysql', 1),
                'attachments_file' => implode(',', $attachedFiles),
            ]
        );

        return $original_mail_info;
    }

    public function set_up_phpmailer(PHPMailer $phpmailer): void
    {
        $phpmailer->Host = $this->option('server.host', '');
        $phpmailer->Port = $this->option('server.port', 465);

        if ($this->option('smtp.active', false)) {

            $phpmailer->isSMTP();

            if (!empty($this->option('smtp.username', '')) or !empty($this->option('smtp.password', ''))) {
                $phpmailer->SMTPAuth = true;

                // Check if user has disabled SMTPAutoTLS.
                $phpmailer->SMTPAutoTLS = $this->option('smtp.autotls', false);
                // Set the SMTPSecure value, if set to none, leave this blank. Possible values: 'ssl', 'tls', ''.
                $phpmailer->SMTPSecure = $this->option('smtp.encryption', 'ssl');

                $phpmailer->Username = $this->option('smtp.username', '');
                $phpmailer->Password = $this->option('smtp.password', '');
            }
        }

        // Insecure SSL option.
        if ($this->option('ssl.self-signed', false)) {
            $phpmailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $phpmailer->Timeout = $this->option('server.timeout', 10);
    }

    protected function init(): void
    {
        if ($this->option('active', false)) {
            add_filter('phpmailer_init', [$this, 'set_up_phpmailer']);
        }

        if ($this->option('log-mail', false)) {
            add_filter('wp_mail', [$this, 'mail_logger']);
        }


        if (filter_input(INPUT_GET, 'message') == 'wpopt-wpmails-data-erased') {
            $this->add_notices('success', __('All mails have been successfully deleted.', 'wpopt'));
        }
    }

    public function render_sub_modules(): void
    {
        require_once WPOPT_SUPPORTERS . 'wp-mails/WPMails_Table.class.php';

        $table = new WPMails(['action_hook' => $this->action_hook, 'settings' => $this->option()]);
        $mail_series = $this->get_mail_daily_series(30);

        $table->prepare_items();
        ?>
        <section class="wps-wrap">
            <block class="wps">
                <section class="wps-header"><h1>WP Mails Log</h1></section>
                <?php echo $this->render_mail_daily_chart($mail_series); ?>
                <section class='wps'>
                    <form method="GET" autocapitalize="off" autocomplete="off">
                        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>"/>
                        <?php $table->display(); ?>
                        <?php RequestActions::nonce_field($this->action_hook); ?>
                    </form>
                </section>
            </block>
            <block class="wps">
                <row class="wps-inline">
                    <strong>Actions:</strong>
                    <a href="<?php RequestActions::get_url($this->action_hook, 'reset', false, true); ?>"
                       class="wps wps-button wpopt-btn is-danger">
                        <?php _e('Reset Log', 'wpopt') ?>
                    </a>
                </row>
            </block>
        </section>
        <?php
    }

    private function get_mail_daily_series(int $days = 30): array
    {
        global $wpdb;

        $days = max(1, $days);
        $start_timestamp = strtotime('-' . ($days - 1) . ' days', current_time('timestamp'));
        $start_date = wp_date('Y-m-d 00:00:00', $start_timestamp);

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DATE(sent_date) AS bucket, COUNT(*) AS total
                 FROM ' . WPOPT_TABLE_LOG_MAILS . '
                 WHERE sent_date >= %s
                 GROUP BY DATE(sent_date)
                 ORDER BY bucket ASC',
                $start_date
            ),
            ARRAY_A
        );

        $indexed = array();
        foreach ((array)$rows as $row) {
            $indexed[$row['bucket']] = (int)$row['total'];
        }

        $series = array();
        for ($i = 0; $i < $days; $i++) {
            $timestamp = strtotime('+' . $i . ' days', $start_timestamp);
            $bucket = wp_date('Y-m-d', $timestamp);
            $series[] = array(
                'bucket' => $bucket,
                'label'  => wp_date('d M', $timestamp),
                'total'  => $indexed[$bucket] ?? 0,
            );
        }

        return $series;
    }

    private function render_mail_daily_chart(array $series): string
    {
        if (empty($series)) {
            return '<div class="wpopt-perf-empty">' . esc_html__('No mail activity available yet.', 'wpopt') . '</div>';
        }

        $max_value = 1;
        $total_sent = 0;
        foreach ($series as $point) {
            $max_value = max($max_value, (int)$point['total']);
            $total_sent += (int)$point['total'];
        }

        $width = 960;
        $height = 260;
        $padding_top = 24;
        $padding_right = 20;
        $padding_bottom = 42;
        $padding_left = 42;
        $plot_width = $width - $padding_left - $padding_right;
        $plot_height = $height - $padding_top - $padding_bottom;
        $point_count = max(1, count($series) - 1);
        $points = array();

        foreach ($series as $index => $point) {
            $x = $padding_left + (($plot_width / $point_count) * $index);
            $y = $padding_top + $plot_height - (($plot_height * ((int)$point['total'] / $max_value)));
            $points[] = round($x, 2) . ',' . round($y, 2);
        }

        ob_start();
        ?>
        <style>
            .wpopt-mails-chart-wrap { margin: 4px 0 18px; }
            .wpopt-mails-chart-summary {
                display:inline-flex;
                align-items:center;
                gap:14px;
                margin:0 0 14px;
                padding:14px 18px;
                border:1px solid rgba(15, 23, 42, 0.08);
                border-radius:16px;
                background:linear-gradient(135deg, #ecfeff 0%, #f8fafc 100%);
                color:#0f172a;
            }
            .wpopt-mails-chart-total {
                display:flex;
                flex-direction:column;
                min-width:92px;
            }
            .wpopt-mails-chart-total strong {
                font-size:28px;
                line-height:1;
                color:#0f766e;
            }
            .wpopt-mails-chart-total span {
                margin-top:4px;
                font-size:11px;
                letter-spacing:.08em;
                text-transform:uppercase;
                color:#0f766e;
            }
            .wpopt-mails-chart-copy {
                max-width:420px;
                font-size:13px;
                color:#334155;
            }
            .wpopt-mails-chart-copy b { color:#0f172a; }
        </style>
        <div class="wpopt-mails-chart-wrap">
            <div class="wpopt-mails-chart-summary">
                <div class="wpopt-mails-chart-total">
                    <strong><?php echo number_format_i18n($total_sent); ?></strong>
                    <span><?php _e('Total sent', 'wpopt'); ?></span>
                </div>
                <div class="wpopt-mails-chart-copy">
                    <b><?php _e('Daily email volume', 'wpopt'); ?></b><br>
                    <?php echo esc_html(sprintf(_n('Mail trend for the last %s day.', 'Mail trend for the last %s days.', count($series), 'wpopt'), number_format_i18n(count($series)))); ?>
                </div>
            </div>
            <svg viewBox="0 0 <?php echo $width; ?> <?php echo $height; ?>" width="100%" height="260" role="img" aria-label="<?php esc_attr_e('Mails sent per day', 'wpopt'); ?>">
                <line x1="<?php echo $padding_left; ?>" y1="<?php echo $padding_top; ?>" x2="<?php echo $padding_left; ?>" y2="<?php echo $padding_top + $plot_height; ?>" stroke="#cbd5e1" stroke-width="1"/>
                <line x1="<?php echo $padding_left; ?>" y1="<?php echo $padding_top + $plot_height; ?>" x2="<?php echo $padding_left + $plot_width; ?>" y2="<?php echo $padding_top + $plot_height; ?>" stroke="#cbd5e1" stroke-width="1"/>
                <text x="10" y="<?php echo $padding_top + 8; ?>" fill="#64748b" font-size="11"><?php echo number_format_i18n($max_value); ?></text>
                <text x="14" y="<?php echo $padding_top + $plot_height; ?>" fill="#64748b" font-size="11">0</text>
                <polyline fill="none" stroke="#0f766e" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" points="<?php echo esc_attr(implode(' ', $points)); ?>"></polyline>
                <?php foreach ($series as $index => $point): ?>
                    <?php
                    $x = $padding_left + (($plot_width / $point_count) * $index);
                    $y = $padding_top + $plot_height - (($plot_height * ((int)$point['total'] / $max_value)));
                    ?>
                    <circle cx="<?php echo round($x, 2); ?>" cy="<?php echo round($y, 2); ?>" r="3.5" fill="#0f766e"></circle>
                    <?php if ($index % max(1, (int)floor(count($series) / 8)) === 0 || $index === count($series) - 1): ?>
                        <text x="<?php echo round($x, 2); ?>" y="<?php echo $padding_top + $plot_height + 18; ?>" text-anchor="middle" fill="#64748b" font-size="10"><?php echo esc_html($point['label']); ?></text>
                    <?php endif; ?>
                <?php endforeach; ?>
            </svg>
        </div>
        <?php
        return ob_get_clean();
    }

    protected function setting_fields($filter = ''): array
    {
        return $this->group_setting_fields(

            $this->group_setting_fields(
                $this->setting_field(__('General', 'wpopt'), false, "separator"),
                $this->setting_field(__('Configuration Override', 'wpopt'), "active", "checkbox"),
                $this->setting_field(__('Mails logging', 'wpopt'), "log-mail", "checkbox", ['default_value' => true]),
                $this->setting_field(__('Delete mails older then a week', 'wpopt'), "auto_clear", "checkbox"),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('Server Conf', 'wpopt'), false, "separator"),
                $this->setting_field(__('Host', 'wpopt'), "server.host", "text"),
                $this->setting_field(__('Port', 'wpopt'), "server.port", "numeric", ['default_value' => 465]),
                $this->setting_field(__('Port', 'wpopt'), "server.timeout", "numeric", ['default_value' => 10]),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('SMTP', 'wpopt'), false, "separator"),
                $this->setting_field(__('Enable', 'wpopt'), "smtp.active", "checkbox"),
                $this->setting_field(__('Username', 'wpopt'), "smtp.username", "text", ['parent' => 'smtp.active']),
                $this->setting_field(__('Password', 'wpopt'), "smtp.password", "text", ['parent' => 'smtp.active']),
                $this->setting_field(__('Encryption', 'wpopt'), "smtp.encryption", "dropdown", ['parent' => 'smtp.active', 'list' => ['ssl', 'tls', ''], 'default_value' => 'ssl']),
                $this->setting_field(__('AutoTLS', 'wpopt'), "smtp.autotls", "text", ['parent' => 'smtp.active', 'default_value' => false]),
            ),

            $this->group_setting_fields(
                $this->setting_field(__('SSL', 'wpopt'), false, "separator"),
                $this->setting_field(__('Allow self signed ssl certificate', 'wpopt'), "ssl.self-signed", "checkbox", ['default_value' => false]),
            )
        );
    }

    protected function infos(): array
    {
        return [
            'active' => __('If enabled allow to inject the below connection specification into phpmailer of WordPress', 'wpopt'),
        ];
    }
}

return __NAMESPACE__;


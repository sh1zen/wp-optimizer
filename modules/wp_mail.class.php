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

        $table->prepare_items();
        ?>
        <section class="wps-wrap">
            <block class="wps">
                <section class="wps-header"><h1>WP Mails Log</h1></section>
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
                       class="button button-primary">
                        <?php _e('Reset Log', 'wpopt') ?>
                    </a>
                </row>
            </block>
        </section>
        <?php
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

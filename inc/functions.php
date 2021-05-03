<?php


function wpopt_is_on_screen($slug)
{
    return isset($_GET['page']) and $_GET['page'] == $slug;
}

function wpopt_timestr2seconds($time = '')
{
    if (!$time)
        return 0;

    list($hour, $minute) = explode(':', $time);

    return $hour * HOUR_IN_SECONDS + $minute * MINUTE_IN_SECONDS;
}

function wpopt_add_timezone($timestamp = false)
{
    if (!$timestamp)
        $timestamp = time();

    $timezone = get_option('gmt_offset') * HOUR_IN_SECONDS;

    return $timestamp - $timezone;
}


/**
 * Renders for panel-tabs
 * support specif tabs with $limit_ids arg
 *
 * @param $fields
 * @param array $limit_ids
 * @return false|string
 */
function wpopt_generateHTML_tabs_panels($fields, $limit_ids = array())
{
    if (!is_array($limit_ids))
        $limit_ids = array($limit_ids);

    ob_start();
    ?>
    <div class="ar-tabs" id="ar-tabs">
        <ul class="ar-tablist" aria-label="wpopt-menu">
            <?php
            foreach ($fields as $field) {
                ?>
                <li class="ar-tab">
                    <a id="lbl_<?php echo $field['id']; ?>" class="ar-tab_link"
                       href="#<?php echo $field['id']; ?>"><?php echo $field['tab-title']; ?></a>
                </li>
                <?php
            }
            ?>
        </ul><?php
        foreach ($fields as $field) {
            /**
             * Support for limiting the rendering to only specific tab
             */
            if ($limit_ids) {
                if (!in_array($field['id'], $limit_ids))
                    continue;
            }
            ?>
            <panel id="<?php echo $field['id']; ?>" class="ar-tabcontent" aria-hidden="true"
                <?php echo isset($field['ajax-callback']) ? "aria-ajax='" . json_encode($field['ajax-callback']) . "'" : '' ?>>
                <?php
                if (isset($field['panel-title'])) echo "<h2>{$field['panel-title']}</h2>";
                if (isset($field['callback'])) {
                    $args = isset($field['args']) ? $field['args'] : array();

                    if (is_callable($field['callback']))
                        echo call_user_func_array($field['callback'], $args);
                }
                ?>
            </panel>
            <?php
        }
        ?></div>
    <?php
    return ob_get_clean();
}


/**
 * @return string
 */
function wpopt_get_calling_function()
{
    $caller = debug_backtrace();
    $caller = $caller[2];
    $r = $caller['function'] . '()';
    if (isset($caller['class'])) {
        $r .= ' in ' . $caller['class'];
    }
    if (isset($caller['object'])) {
        $r .= ' (' . get_class($caller['object']) . ')';
    }
    return $r;
}

function wpopt_generate_fields($fields_args, $args, $display = true)
{
    $output = '';
    $levels = array();

    foreach ($fields_args as $field_args) {

        if (isset($args['name_prefix']))
            $field_args['name_prefix'] = $args['name_prefix'];

        if (isset($field_args['id'])) {

            $levels[$field_args['id']] = 0;

            if (isset($field_args['parent'])) {
                $levels[$field_args['id']] = $levels[$field_args['parent']] + 1;

                $field_args['nexted_level'] = $levels[$field_args['id']];
            }
        }

        $output .= wpopt_generate_field($field_args, false);
    }

    if ($display)
        echo $output;

    return $output;
}

function wpopt_generate_field($args, $display = true)
{
    if (is_callable($args)) {
        return call_user_func($args);
    }

    if (is_string($args)) {
        return "<block class='wpopt-options--before'>$args</block>";
    }

    $args = array_merge(array(
        'parent'       => false,
        'nexted_level' => 0,
        'before'       => false,
        'after'        => false,
        'id'           => '',
        'name'         => '',
        'value'        => '',
        'note'         => '',
        'type'         => '',
        'classes'      => '',
        'context'      => 'table',
        'name_prefix'  => false
    ), $args);

    $data_values = array();

    $context = $args['context'];

    $output = $_oBefore = $_oAfter = $_field_html_args = '';;

    if ($args['before']) {
        $output .= wpopt_generate_field($args['before'], false);
    }

    $input_name = $args['id'];

    if ($args['name_prefix']) {
        $input_name = "{$args['name_prefix']}[{$input_name}]";
    }

    if ($context === 'action') {
        $output .= "<input name='action' type='hidden' value='{$args['id']}'>";

        if (empty($args['type']))
            $args['type'] = 'submit';
    }
    elseif ($context === 'table') {

        $padding_left = 30 * $args['nexted_level'];

        $row_class = $padding_left !== 0 ? 'wpopt-child' : '';

        $_oBefore = "<tr class='{$row_class}'><td class='option' style='padding-left: {$padding_left}px'><b>{$args['name']}:</b></td><td class='value'><label for='{$args['id']}'></label>";
        $_oAfter = "</td></tr>";
    }
    else {
        $args['classes'] .= " wpopt-{$context}";
    }

    if (!empty($args['classes'])) {
        $_field_html_args = " class='" . trim($args['classes']) . "' ";
    }

    if ($args['parent']) {
        $data_values['parent'] = $args['parent'];
    }

    $jquery_data = '';
    foreach ($data_values as $key => $value) {
        $jquery_data .= " data-{$key}='{$value}'";
    }

    switch ($args['type']) {

        case 'divide':
            $_oAfter = $_oBefore = '';
            if ($context === 'table') {
                $output .= "<tr class='blank-row'></tr>";
            }

            break;

        case 'separator':
            $_oAfter = $_oBefore = '';
            if ($context === 'table') {
                $output .= "</tbody></table><br>";

                if (isset($args['name']))
                    $output .= "<h3 class='wpopt-setting-header'>{$args['name']}</h3>";

                $output .= "<table class='wpopt wpopt-settings'><tbody>";
            }
            break;

        case "time":
        case 'hidden':
        case "text":
        case "checkbox":
        case "numeric":
        case "number":
        case "button":
        case "submit":

            if ($args['type'] === 'checkbox') {
                $_field_html_args = "class='wpopt-apple-switch' ";
                $_field_html_args .= checked(1, $args['value'], false);
            }

            $output .= "<input {$_field_html_args} type='{$args['type']}' name='{$input_name}' id='{$args['id']}' value='{$args['value']}' {$jquery_data}/>";
            break;

        case "textarea":
            $output .= "<textarea {$_field_html_args} rows='4' cols='80' type='{$args['type']}' name='{$input_name}' id='{$args['id']}' {$jquery_data}/>{$args['value']}</textarea>";
            break;
    }

    $output = "{$_oBefore}{$output}{$_oAfter}";

    if ($args['after']) {
        $output .= wpopt_generate_field($args['after'], false);
    }

    if ($display)
        echo $output;

    return $output;
}


function wpopt_module_panel_url($module = '', $panel = '')
{
    return admin_url("admin.php?page={$module}#{$panel}");
}

function wpopt_module_setting_url($panel = '')
{
    return admin_url("admin.php?page=wpopt-modules-settings#settings-{$panel}");
}

function wpopt_setting_panel_url($panel = '')
{
    return admin_url("admin.php?page=wpopt-settings#settings-{$panel}");
}

function wpopt_get_mysqlDump_command_path($mysqldump_locations = '')
{
    // Check shell_exec is available
    if (!WPOptimizer\core\UtilEnv::is_shell_exec_available())
        return false;

    if (!empty($mysqldump_locations)) {

        return @is_executable(WPOptimizer\core\UtilEnv::normalize_path($mysqldump_locations));
    }

    // check mysqldump command
    if (is_null(shell_exec('hash mysqldump 2>&1'))) {

        return 'mysqldump';
    }

    $mysqldump_locations = array(
        '/usr/local/bin/mysqldump',
        '/usr/local/mysql/bin/mysqldump',
        '/usr/mysql/bin/mysqldump',
        '/usr/bin/mysqldump',
        '/opt/local/lib/mysql6/bin/mysqldump',
        '/opt/local/lib/mysql5/bin/mysqldump',
        '/opt/local/lib/mysql4/bin/mysqldump',
        '/xampp/mysql/bin/mysqldump',
        '/Program Files/xampp/mysql/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 8.0/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 6.0/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 5.5/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 5.4/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 5.1/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 5.0/bin/mysqldump',
        '/Program Files/MySQL/MySQL Server 4.1/bin/mysqldump'
    );

    $mysqldump_command_path = '';

    // Find the one which works
    foreach ((array)$mysqldump_locations as $location) {
        if (@is_executable(WPOptimizer\core\UtilEnv::normalize_path($location)))
            $mysqldump_command_path = $location;
    }

    return empty($mysqldump_command_path) ? false : $mysqldump_command_path;
}


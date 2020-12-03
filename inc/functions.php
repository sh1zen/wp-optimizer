<?php

function wpopt_is_on_screen($slug)
{
    return isset($_GET['page']) ? $_GET['page'] == $slug : false;
}

function wpopt_verify_nonce($name = 'wpopt', $nonce = false)
{
    if (!$nonce)
        $nonce = trim($_REQUEST['_wpnonce']);

    return wp_verify_nonce($nonce, $name);
}

function wpopt_delete_files($target, $identifier = '')
{
    $identifier .= '*';

    if (is_dir($target)) {
        $files = glob($target . $identifier, GLOB_MARK); //GLOB_MARK adds a slash to directories returned

        foreach ($files as $file) {
            wpopt_delete_files($file);
        }

        rmdir($target);
    }
    elseif (is_file($target)) {
        unlink($target);
    }
}

/**
 * @param $directory
 * @return false|int|mixed
 */
function wpopt_calc_folder_size($directory)
{
    $totalSize = 0;
    $directoryArray = scandir($directory);

    foreach ($directoryArray as $key => $fileName) {
        if ($fileName != ".." and $fileName != ".") {
            if (is_dir($directory . "/" . $fileName)) {
                $totalSize = $totalSize + wpopt_calc_folder_size($directory . "/" . $fileName);
            }
            else if (is_file($directory . "/" . $fileName)) {
                $totalSize = $totalSize + filesize($directory . "/" . $fileName);
            }
        }
    }
    return $totalSize;
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
 * @param $size
 * @return string
 */
function wpopt_bytes2size($size)
{
    if ($size == 0)
        return '0 B';

    $unit = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');

    $i = floor(log($size, 1024));

    return @round($size / pow(1024, $i), 2) . ' ' . $unit[$i];
}

function wpopt_size2bytes($val)
{
    $val = trim($val);

    if (empty($val))
        return 0;

    $last = strtolower($val[strlen($val) - 1]);

    $val = substr($val, 0, -1);

    switch ($last) {
        case 'g':
            $val *= 1024;
        case 'm':
            $val *= 1024;
        case 'k':
            $val *= 1024;
    }

    return $val;
}

/**
 *
 * @param int $current Current number.
 * @param int $total Total number.
 * @return string Number in percentage
 *
 * @access public
 */
function wpopt_format_percentage($current, $total)
{
    if ($total == 0)
        return 0;

    return ($total > 0 ? round(($current / $total) * 100, 2) : 0) . '%';
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
            if (!empty($limit_ids)) {
                if (!in_array($field['id'], $limit_ids))
                    continue;
            }
            ?>
            <div id="<?php echo $field['id']; ?>" class="ar-tabcontent" aria-hidden="true"
                <?php echo isset($field['ajax-callback']) ? "aria-ajax='" . json_encode($field['ajax-callback']) . "'" : '' ?>>
                <p><br></p><?php
                if (isset($field['panel-title'])) echo "<h2>{$field['panel-title']}</h2>";
                if (isset($field['callback'])) {
                    $args = isset($field['args']) ? $field['args'] : array();

                    if (is_callable($field['callback']))
                        echo call_user_func_array($field['callback'], $args);
                }
                ?>
            </div>
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


/**
 * @param $log
 */
function wpopt_write_log($log)
{
    if (true === WP_DEBUG) {
        if (is_array($log) || is_object($log)) {
            error_log(print_r($log, true));
        }
        else {
            error_log($log);
        }
    }
}

/**
 * @param $data
 * @return string
 */
function wpopt_generate_report($data)
{
    global $wo_meter;

    $report = PHP_EOL . PHP_EOL;
    $report .= ' ' . __('Report', 'wpopt') . ': ' . PHP_EOL . PHP_EOL;

    if (!is_null($wo_meter)) {
        $report .= ' ' . __('Time elapsed', 'wpopt') . ': ' . $wo_meter->get_time() . PHP_EOL;
        $report .= ' ' . __('Memory used', 'wpopt') . ': ' . $wo_meter->get_memory() . PHP_EOL;
    }

    $report .= ' ' . __('Images cleaned inserted', 'wpopt') . ': ' . count($data['images']) . PHP_EOL;
    $report .= ' ' . $data['db'] . PHP_EOL;
    $report .= ' ' . __('Errors', 'wpopt') . ': ' . PHP_EOL;
    $report .= ' --------------------------------- ' . PHP_EOL;

    if (isset($data['errors']))
        $report .= ' ' . __('Number', 'wpopt') . ': ' . count($data['errors']) . PHP_EOL;

    foreach ($data['errors'] as $error)
        $report .= ' - ' . $error . PHP_EOL;

    return $report;
}


function wpopt_is_function_disabled($function_name)
{
    return in_array($function_name, array_map('trim', explode(',', ini_get('disable_functions'))), true);
}


function wpopt_create_folder($path = WP_CONTENT_DIR . '/backup-db', $private = true)
{
    global $is_IIS;

    $plugin_path = __DIR__;

    // Create Backup Folder
    $res = wp_mkdir_p($path);

    if ($private and is_dir($path) and wp_is_writable($path)) {

        if ($is_IIS) {
            if (!is_file($path . '/Web.config')) {
                copy($plugin_path . '/Web.config.txt', $path . '/Web.config');
            }
        }
        else {
            if (!is_file($path . '/.htaccess')) {
                copy($plugin_path . '/htaccess.txt', $path . '/.htaccess');
            }
        }
        if (!is_file($path . '/index.php')) {
            file_put_contents($path . '/index.php', '<?php');
        }

        chmod($path, 0750);
    }

    return $res;
}


function wpopt_module_panel_url($module = '', $panel = '')
{
    return admin_url("admin.php?page={$module}#{$panel}");
}

function wpopt_setting_panel_url($panel = '')
{
    return admin_url("admin.php?page=wpopt-settings#settings-{$panel}");
}

function wpopt_download_file($file_path)
{
    $file_path = trim($file_path);

    if (!file_exists($file_path) or headers_sent())
        return false;

    header('Expires: 0');
    header("Cache-Control: public");
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Content-Description: File Transfer');
    header('Content-Type: application/force-download');
    header('Content-Type: application/octet-stream');
    header('Content-Type: application/download');
    header('Content-Disposition: attachment; filename=' . basename($file_path) . ';');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . filesize($file_path));

    ob_clean();
    flush();
    //readfile($file_path);

    $chunkSize = 1024 * 1024;
    $handle = fopen($file_path, 'rb');

    while (!feof($handle)) {
        $buffer = fread($handle, $chunkSize);
        echo $buffer;
        ob_flush();
        flush();
    }
    fclose($handle);

    exit();
}

function wpopt_conform_dir($dir, $recursive = false)
{
    // Assume empty dir is root
    if (!$dir)
        $dir = '/';

    // Replace single forward slash (looks like double slash because we have to escape it)
    $dir = str_replace('\\', '/', $dir);
    $dir = str_replace('//', '/', $dir);

    // Remove the trailing slash
    if ($dir !== '/')
        $dir = untrailingslashit($dir);

    // Carry on until completely normalized
    if (!$recursive and wpopt_conform_dir($dir, true) != $dir)
        return wpopt_conform_dir($dir);

    return (string)$dir;
}

function wpopt_is_safe_mode_active($ini_get_callback = 'ini_get')
{
    if (($safe_mode = @call_user_func($ini_get_callback, 'safe_mode')) && strtolower($safe_mode) != 'off')
        return true;

    return false;
}


function wpopt_is_shell_exec_available()
{
    if (wpopt_is_safe_mode_active())
        return false;

    // Is shell_exec or escapeshellcmd or escapeshellarg disabled?
    if (array_intersect(array('shell_exec', 'escapeshellarg', 'escapeshellcmd'), array_map('trim', explode(',', @ini_get('disable_functions')))))
        return false;

    // Can we issue a simple echo command?
    if (!@shell_exec('echo WP Backup'))
        return false;

    return true;
}


function wpopt_get_mysqlDump_command_path($mysqldump_locations = '')
{
    // Check shell_exec is available
    if (!wpopt_is_shell_exec_available())
        return false;

    if (!empty($mysqldump_locations)) {

        return @is_executable(wpopt_conform_dir($mysqldump_locations));
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
        if (@is_executable(wpopt_conform_dir($location)))
            $mysqldump_command_path = $location;
    }

    return empty($mysqldump_command_path) ? false : $mysqldump_command_path;
}

function wpopt_flatMultiDA(&$mData)
{
    if (is_array($mData)) {

        if (isset($mData[0]) and count($mData) == 1) {

            $mData = $mData[0];
            if (is_array($mData)) {

                foreach ($mData as &$aSub) {
                    wpopt_flatMultiDA($aSub);
                }
            }
        }
        else {

            foreach ($mData as &$aSub) {
                wpopt_flatMultiDA($aSub);
            }
        }
    }
}

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

/**
 * @param $size
 * @return string
 */
function wpopt_bytes2size($size)
{
    if($size == 0)
        return '0 B';

    $unit = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB');

    $i = floor(log($size, 1024));

    return @round($size / pow(1024, $i), 2) . ' ' . $unit[$i];
}

function wpopt_size2bytes($val) {

    $val = trim($val);

    if(empty($val))
        return 0;

    $last = strtolower($val[strlen($val)-1]);
    switch($last) {
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
 * @since 1.0.2
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
 * @param $fields
 * @return false|string
 */
function wpopt_generateHTML_tabs_panels($fields)
{
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
            ?>
            <div id="<?php echo $field['id']; ?>" class="ar-tabcontent" aria-hidden="true">
                <h2><?php if (isset($field['panel-title'])) echo $field['panel-title']; ?></h2>
                <p></p><?php
                if (isset($field['callback']))
                {
                    $args = isset($field['args']) ? $field['args'] : array();

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
 * @param WOMeter|null $timer
 * @return string
 */
function wpopt_generate_report($data, WOMeter $timer = null)
{
    $report = PHP_EOL . PHP_EOL;
    $report .= ' ' . __('Report', 'wpopt') . ': ' . PHP_EOL . PHP_EOL;

    if (!is_null($timer)) {
        $report .= ' ' . __('Time elapsed', 'wpopt') . ': ' . $timer->get_time() . PHP_EOL;
        $report .= ' ' . __('Memory used', 'wpopt') . ': ' . $timer->get_memory() . PHP_EOL;
    }

    $report .= ' ' . __('Images cleaned inserted', 'wpopt') . ': ' . count($data['images']) . PHP_EOL;
    $report .= ' ' . $data['db'] . PHP_EOL;
    $report .= ' ' . __('Errors', 'wpopt') . ': ' . PHP_EOL;
    $report .= ' --------------------------------- ' . PHP_EOL;
    $report .= ' ' . __('Number', 'wpopt') . ': ' . count($data['errors']) . PHP_EOL;

    foreach ($data['errors'] as $error)
        $report .= ' - ' . $error . PHP_EOL;

    return $report;
}


function wpopt_is_function_disabled( $function_name ) {
    return in_array( $function_name, array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true );
}



function wpopt_create_folder($path = WP_CONTENT_DIR . '/backup-db', $private = true) {

    global $is_IIS;
    $plugin_path =  __DIR__ ;

    // Create Backup Folder
    $res = wp_mkdir_p( $path );

    if( $private and is_dir( $path ) and wp_is_writable( $path ) ) {

        if( $is_IIS ) {
            if ( ! is_file( $path . '/Web.config' ) ) {
                copy( $plugin_path . '/Web.config.txt', $path . '/Web.config' );
            }
        } else {
            if( ! is_file( $path . '/.htaccess' ) ) {
                copy( $plugin_path . '/htaccess.txt', $path . '/.htaccess' );
            }
        }
        if( ! is_file( $path . '/index.php' ) ) {
            file_put_contents( $path . '/index.php' , '<?php');
        }

        chmod( $path, 0750 );
    }

    return $res;
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

function wpopt_flatMultiDA(&$mData)
{
    if (is_array($mData)) {

        if (isset($mData[0]) && count($mData) == 1) {

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

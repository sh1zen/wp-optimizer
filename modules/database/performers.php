<?php

function wpopt_db_process_sql($sql)
{
    if (empty($sql))
        return '<p style="color: red;">' . __('Empty Query', 'wpopt') . '</p>';

    $sql = trim($sql);

    $sql_queries = array();

    foreach (explode(PHP_EOL, $sql) as $sql_query) {

        $sql_query = trim(stripslashes($sql_query));

        $sql_query = preg_replace("/[\r\n]+/", '', $sql_query);

        if (!empty($sql_query)) {
            $sql_queries[] = $sql_query;
        }
    }

    if (empty($sql_queries))
        return '<p style="color: red;">' . __('Empty Query', 'wpopt') . '</p>';

    $text = '';
    $total_query = $success_query = 0;

    foreach ($sql_queries as $sql_query) {

        if (WOPerformer::execute_sql($sql_query)) {
            $success_query++;
            $text .= "<p style='color: #019e01;'>$sql_query</p>";
        }
        else {
            $text .= "<p style=\"color: red;\">$sql_query</p>";
        }

        $total_query++;
    }

    $text .= '<p style="color: #0055ff;">' . number_format_i18n($success_query) . '/' . number_format_i18n($total_query) . ' ' . __('Query(s) Executed Successfully', 'wpopt') . '</p>';

    return $text;
}

function wpopt_get_mysqldump_command_path($mysqldump_locations = '')
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

function wpopt_db_mysqldump($SQLfilename, $_excluded_tables = array(), $mysqldump_locations = '')
{
    $host = explode(':', DB_HOST);

    $host = reset($host);
    $port = strpos(DB_HOST, ':') ? end($host) : '';

    // Path to the mysqldump executable
    $cmd = escapeshellarg(wpopt_get_mysqldump_command_path($mysqldump_locations));

    // We don't want to create a new DB
    $cmd .= ' --no-create-db --hex-blob';

    // Username
    $cmd .= ' -u ' . escapeshellarg(DB_USER);

    // Don't pass the password if it's blank
    if (DB_PASSWORD)
        $cmd .= ' -p' . escapeshellarg(DB_PASSWORD);

    // Set the host
    $cmd .= ' -h ' . escapeshellarg($host);

    // Set the port if it was set
    if (!empty($port) && is_numeric($port))
        $cmd .= ' -P ' . $port;

    // The file we're saving too
    $cmd .= ' -r ' . escapeshellarg($SQLfilename);

    if (!empty($_excluded_tables)) {
        $cmd .= implode(' --ignore-table=' . DB_NAME . '.', $_excluded_tables);
    }

    // The database we're dumping
    $cmd .= ' ' . escapeshellarg(DB_NAME);

    // Pipe STDERR to STDOUT
    $cmd .= ' 2>&1';

    $stderr = shell_exec($cmd);

    // Skip the new password warning that is output in mysql > 5.6
    if (trim($stderr) === 'Warning: Using a password on the command line interface can be insecure.') {
        $stderr = '';
    }

    if ($stderr) {
        wpopt_write_log($stderr);

        if (file_exists($SQLfilename))
            unlink($SQLfilename);

        return false;
    }

    // If we have an empty file delete it
    if (@filesize($SQLfilename) === 0) {
        unlink($SQLfilename);
        return false;
    }

    return true;
}

function wpopt_db_querydump($SQLfilename, $_excluded_tables = array())
{
    global $wpdb;

    $tables = $wpdb->get_col('SHOW TABLES');
    $output = '';

    if (file_exists($SQLfilename))
        unlink($SQLfilename);

    $file_descriptor = fopen($SQLfilename, 'w');

    $available_memory = wpopt_size2bytes(@ini_get('memory_limit'));

    foreach ($tables as $table) {
        if (empty($_excluded_tables) or (!(in_array($table, $_excluded_tables)))) {

            $iter = 0;

            while ($result = $wpdb->get_results("SELECT * FROM {$table} LIMIT 1000 OFFSET " . ($iter * 1000), ARRAY_N)) {

                $iter++;

                if ($iter === 1) {
                    $row2 = $wpdb->get_row('SHOW CREATE TABLE ' . $table, ARRAY_N);
                    $output .= PHP_EOL . PHP_EOL . $row2[1] . ";" . PHP_EOL . PHP_EOL;
                }

                for ($i = 0; $i < count($result); $i++) {
                    $row = $result[$i];
                    $output .= 'INSERT INTO ' . $table . ' VALUES(';
                    for ($j = 0; $j < count($result[0]); $j++) {
                        $row[$j] = $wpdb->_real_escape($row[$j]);
                        $output .= (isset($row[$j])) ? '"' . $row[$j] . '"' : '""';
                        if ($j < (count($result[0]) - 1)) {
                            $output .= ',';
                        }
                    }
                    $output .= ");" . PHP_EOL;

                }
                $output .= PHP_EOL;

                if ($available_memory - memory_get_usage() < 10485760) {
                    fwrite($file_descriptor, $output);
                    $output = '';
                    $wpdb->flush();
                }

            }
            fwrite($file_descriptor, $output);
            $output = '';
            $wpdb->flush();
        }
    }

    $wpdb->flush();

    fclose($file_descriptor);

    return $output;
}

function wpopt_db_make_backup(array $options)
{
    if (empty($options))
        return false;

    $current_date = gmdate('Y-m-d@H-i-s', current_time('timestamp'));

    set_time_limit(120);

    $backup_path = trailingslashit($options['backup_path']) . $current_date . '.sql';

    if (wpopt_get_mysqldump_command_path($options['mysqldump_path'])) {

        return wpopt_db_mysqldump($backup_path, $options['excluded_tables'], $options['mysqldump_path']);
    }

    return wpopt_db_querydump($backup_path, $options['excluded_tables']);
}


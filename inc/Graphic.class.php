<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2021
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPOptimizer\core;


class Graphic
{

    public static function generate_field($args, $display = true)
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
            $output .= self::generate_field($args['before'], false);
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
                        $output .= "<h3 class='wpopt-setting-header' {$jquery_data}>{$args['name']}</h3>";

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
            $output .= self::generate_field($args['after'], false);
        }

        if ($display)
            echo $output;

        return $output;
    }

    public static function generate_fields($fields_args, $args, $display = true)
    {
        $output = '';
        $levels = array();

        foreach ($fields_args as $field_args) {

            if (!empty($args['name_prefix']))
                $field_args['name_prefix'] = $args['name_prefix'];

            if ($field_args['id']) {

                $levels[$field_args['id']] = 0;

                if (isset($field_args['parent'])) {
                    $levels[$field_args['id']] = $levels[$field_args['parent']] + 1;

                    $field_args['nexted_level'] = $levels[$field_args['id']];
                }
            }

            $output .= Graphic::generate_field($field_args, false);
        }

        if ($display)
            echo $output;

        return $output;
    }

    /**
     * Renders for panel-tabs
     * support specif tabs with $limit_ids arg
     *
     * @param $fields
     * @param array $limit_ids
     * @return false|string
     */
    public static function generateHTML_tabs_panels($fields, $limit_ids = array())
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

    public static function is_on_screen($slug)
    {
        return isset($_GET['page']) ? $_GET['page'] == $slug : false;
    }
}
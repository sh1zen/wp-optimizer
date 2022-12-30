<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C)  2022
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Graphic
{
    public static function generate_fields($fields_args, $args = [], $display = true)
    {
        $output = '';
        $levels = array();

        foreach ($fields_args as $field_args) {

            if (!empty($args['name_prefix'])) {
                $field_args['name_prefix'] = $args['name_prefix'];
            }

            if (isset($field_args['id']) and $field_args['id']) {

                $levels[$field_args['id']] = 0;

                if (!empty($field_args['parent'])) {
                    $levels[$field_args['id']] = $levels[ltrim($field_args['parent'], '!')] + 1;

                    $field_args['nexted_level'] = $levels[$field_args['id']];
                }
            }

            $output .= self::generate_field($field_args, false);
        }

        if ($display) {
            echo $output;
        }

        return $output;
    }

    public static function generate_field($args, $display = true)
    {
        if (is_callable($args)) {
            return call_user_func($args);
        }

        if (is_string($args)) {
            return "<block class='shzn-options--before'>$args</block>";
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
            'placeholder'  => '',
            'type'         => '',
            'classes'      => '',
            'context'      => 'table',
            'name_prefix'  => false,
            'data-values'  => [],
            'depend'       => false
        ), $args);

        $context = $args['context'];

        $_oInner = $_oBefore = $_oAfter = '';;

        if ($args['before']) {
            $_oInner .= self::generate_field($args['before'], false);
        }

        $args['input_name'] = $args['name_prefix'] ? "{$args['name_prefix']}[{$args['id']}]" : $args['id'];

        if (!is_array($args['classes'])) {
            $args['classes'] = [$args['classes']];
        }

        if ($context === 'action') {
            $_oInner .= "<input name='action' type='hidden' value='{$args['id']}'>";

            if (empty($args['type'])) {
                $args['type'] = 'submit';
            }
        }
        elseif ($context === 'table') {

            $padding_left = 30 * $args['nexted_level'];

            $row_class = $padding_left !== 0 ? 'shzn-child' : '';

            $_style = $padding_left ? "style='padding-left: {$padding_left}px'" : '';

            $_oBefore = "<tr class='{$row_class}'><td class='option' {$_style}><strong>{$args['name']}:</strong></td><td class='value'><label for='{$args['id']}'></label>";
            $_oAfter = "</td></tr>";
        }
        else {
            $args['classes'][] = " shzn-{$context}";
        }

        if ($args['parent'] or $args['depend']) {

            $args['data-values']['parent'] = trim(implode(':', array_merge((array)$args['parent'], (array)$args['depend'])), ' :');
        }

        $dataValues = '';
        foreach ($args['data-values'] as $key => $value) {
            $dataValues .= " data-{$key}='{$value}'";
        }

        $dataValues = trim($dataValues, " \n\t\r");

        switch (strtolower($args['type'])) {

            case 'divide':
                $_oAfter = $_oBefore = '';
                if ($context === 'table') {
                    $_oInner .= "<tr class='blank-row'></tr>";
                }
                break;

            case 'separator':
                $_oAfter = $_oBefore = '';
                if ($context === 'table') {
                    $_oInner .= "</tbody></table><br>";

                    $args['classes'][] = 'shzn-separator';

                    if (isset($args['name']))
                        $_oInner .= "<h3 class='" . self::classes($args['classes']) . "' {$dataValues}>{$args['name']}</h3>";

                    $_oInner .= "<table class='shzn shzn-settings'><tbody>";
                }
                break;

            case "time":
            case 'hidden':
            case "text":
            case "numeric":
            case "number":
            case "button":
            case "submit":

                $args['classes'][] = 'shzn';
                $args['classes'][] = 'shzn-' . strtolower($args['type']);

                $_oInner .= "<input " . self::buildProps([
                        'class'        => self::classes($args['classes']),
                        'autocomplete' => 'off',
                        'type'         => $args['type'],
                        'name'         => $args['input_name'],
                        'id'           => $args['id'],
                        'placeholder'  => $args['placeholder'],
                        'spellcheck'   => 'false',
                        'value'        => (string)$args['value']
                    ]) . " {$dataValues}/>";
                break;

            case "upload-input":

                $args['classes'][] = 'shzn-input';
                $args['classes'][] = 'shzn-input-upload';
                $args['classes'][] = 'shzn-input__wrapper';

                $_oInner .= "<div class='" . self::classes($args['classes']) . "'>";

                $_oInner .= "<input " . self::buildProps([
                        'autocomplete' => 'off',
                        'type'         => 'text',
                        'name'         => $args['input_name'],
                        'id'           => $args['id'],
                        'placeholder'  => $args['placeholder'],
                        'value'        => (string)$args['value']
                    ]) . " {$dataValues}/>";

                $_oInner .= "<div class='shzn-uploader__init' data-type='image'>Upload Image</div>";

                $_oInner .= "</div>";

                break;

            case "checkbox":

                $args['classes'][] = "shzn-apple-switch";

                $_oInner .= "<input " . self::buildProps([
                        'class' => self::classes($args['classes']),
                        'type'  => 'checkbox',
                        'name'  => $args['input_name'],
                        'id'    => $args['id'],
                        'value' => (bool)$args['value']
                    ]) . " " . checked(1, $args['value'], false) . "  {$dataValues}/>";
                break;

            case "textarea":

                $args['classes'][] = "shzn";

                $_oInner .= "<textarea " . self::buildProps([
                        'class'      => self::classes($args['classes']),
                        'rows'       => '4',
                        'cols'       => '80',
                        'type'       => 'textarea',
                        'name'       => $args['input_name'],
                        'id'         => $args['id'],
                        'spellcheck' => 'false'
                    ]) . " {$dataValues}/>{$args['value']}</textarea>";
                break;

            case "label":
                $_oInner .= "<span " . self::buildProps([
                        'class' => self::classes($args['classes']),
                    ]) . " {$dataValues}>{$args['value']}</span>";
                break;

            case "link":
                $_oInner .= "<a " . self::buildProps([
                        'class' => self::classes($args['classes']),
                    ]) . " {$dataValues} href='{$args['value']['href']}'>{$args['value']['text']}</a>";
                break;

            case "action":

                $args['classes'][] = 'shzn';
                $args['classes'][] = 'button';

                $_oInner .= "<button " . self::buildProps([
                        'class' => self::classes($args['classes']),
                    ]) . " {$dataValues}>{$args['value']}</button>";
                break;

            case "dropdown":

                $args['classes'][] = 'shzn';

                $_oInner .= "<div " . self::buildProps([
                        'class' => self::classes($args['classes']),
                    ]) . " {$dataValues}>" . self::buildDropdown($args) . "</div>";
                break;

            case 'raw':
            case 'html':
                $_oInner .= $args['value'];
                break;
        }

        $_oInner = "{$_oBefore}{$_oInner}{$_oAfter}";

        if ($args['after']) {
            $_oInner .= self::generate_field($args['after'], false);
        }

        if ($display) {
            echo $_oInner;
        }

        return $_oInner;
    }

    private static function classes($classes = [])
    {
        return trim(implode(' ', array_filter(array_unique($classes))));
    }

    public static function buildProps($props = [], $strip_empty = false)
    {
        $_props = '';
        /*
                foreach ($props as $key => $value) {

                    if (is_array($value))
                        $_props .= self::buildProps($value, $strip_empty);
                    else {
                        if (is_string($value))
                            $_props .= $key . '="' . $value . '" ';
                        else {
                            if ($strip_empty and $value)
                                $_props .= $key . ' ';
                            else if (!$strip_empty)
                                $_props .= $key . ' ';
                        }
                    }
                }
        */
        foreach ($props as $key => $value) {

            if (is_array($value)) {
                $_props .= self::buildProps($value, $strip_empty);
            }
            else {
                if (is_string($value)) {
                    if (!$strip_empty or !empty($value)) {
                        $_props .= $key . '="' . $value . '" ';
                    }
                }
                else {
                    if ($strip_empty) {
                        if (empty($value) and $value !== 0) {
                            $_props .= $key . ' ';
                        }
                    }
                    else {
                        $_props .= $key . ' ';
                    }
                }
            }
        }

        return trim($_props);
    }

    private static function buildDropdown($args)
    {
        ob_start();

        $args = array_merge([
            'id'         => '',
            'list'       => [],
            'value'      => '',
            'input_name' => ''
        ], $args);

        $items = $args['list'];

        ?>
        <div class="shzn-dropdown">
            <div class="shzn-input__wrapper">
                <input name="<?php echo $args['input_name'] ?>" id="<?php echo $args['id'] ?>" type="text"
                       value="<?php echo $args['value'] ?>" autocomplete="off"
                       placeholder="<?php _e("Choose a type or enter one manually.", 'shzn'); ?>">
                <div class="shzn-dropdown__opener">
                    <svg class="shzn-icon shzn-icon__arrow" viewBox="0 0 16 16" width="16" height="16">
                        <path d="M11.293 8L4.646 1.354l.708-.708L12.707 8l-7.353 7.354-.708-.708z"></path>
                    </svg>
                </div>
            </div>
            <div class="shzn-multiselect__wrapper">
                <ul class='shzn-multiselect'>
                    <?php
                    foreach ($items as $key => $value) {

                        echo "<li data-value='{$value}' class='shzn-multiselect__element'><span>{$value}</span></li>";
                    }
                    ?>
                </ul>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function buildField($type, $props = [], $content = '')
    {
        return "<{$type} " . self::buildProps($props) . ">{$content}</{$type}>";
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
        if (!is_array($limit_ids)) {
            $limit_ids = array($limit_ids);
        }

        if (!is_array($fields)) {
            return '';
        }

        if (count($fields) < 2) {
            return self::generatePanelContent($fields[0]);
        }

        ob_start();

        ?>
        <div class="shzn-ar-tabs" id="ar-tabs">
            <ul class="shzn-ar-tablist" aria-label="shzn-menu">
                <?php
                foreach ($fields as $field) {
                    $tab_title = empty($field['tab-title']) ? $field['panel-title'] : $field['tab-title'];
                    ?>
                    <li class="shzn-ar-tab">
                        <a id="lbl_<?php echo $field['id']; ?>" class="shzn-ar-tab_link"
                           href="#<?php echo $field['id']; ?>"><?php echo $tab_title; ?></a>
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
                <panel id="<?php echo $field['id']; ?>" class="shzn-ar-tabcontent" aria-hidden="true"
                    <?php echo isset($field['ajax-callback']) ? "aria-ajax='" . json_encode($field['ajax-callback']) . "'" : '' ?>>
                    <?php echo self::generatePanelContent($field); ?>
                </panel>
                <?php
            }
            ?></div>
        <?php
        return ob_get_clean();
    }

    private static function generatePanelContent($field)
    {
        if (!is_array($field))
            return '';

        $HTML = '';
        if (!empty($field['panel-title'])) {
            $HTML .= "<h2>{$field['panel-title']}</h2>";
        }

        if (isset($field['callback'])) {
            $args = $field['args'] ?? array();

            if (is_callable($field['callback'])) {
                $HTML .= call_user_func_array($field['callback'], $args);
            }
        }

        return $HTML;
    }

    public static function newField($name, $id = false, $type = 'text', $args = [])
    {
        if (!is_array($args)) {
            $args = ['value' => $args];
        }

        $args = array_merge([
            'value'         => false,
            'default_value' => '',
            'allow_empty'   => true
        ], $args);

        if ($id) {
            $value = ($args['value'] === false) ? '' : $args['value'];
        }
        else {
            $value = $args['value'];
        }

        if (empty($value) and !$args['allow_empty']) {
            $value = $args['default_value'];
        }

        return array_merge($args, [
            'type'  => $type,
            'name'  => $name,
            'id'    => $id,
            'value' => $value,
        ]);
    }

    public static function is_on_screen($slug)
    {
        return isset($_GET['page']) and str_contains($_GET['page'], trim($slug));
    }
}
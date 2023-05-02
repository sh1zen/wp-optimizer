<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Graphic
{
    public static function generate_fields($fields_args, $infos, $args = [], $display = true)
    {
        $output = '';
        $levels = array();

        foreach ($fields_args as $field_args) {

            $field_args['name_prefix'] = $args['name_prefix'] ?: false;

            if (!empty($field_args['id'])) {

                $field_args['label'] = $infos[$field_args['id']] ?? '';

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
        if (empty($args)) {
            return '';
        }

        if (is_callable($args)) {
            return call_user_func($args);
        }

        if (is_string($args)) {
            return $args;
        }

        $args = array_merge(array(
            'before'       => false,
            'after'        => false,
            'id'           => '',
            'name'         => '',
            'value'        => '',
            'placeholder'  => '',
            'type'         => '',
            'classes'      => '',
            'label'        => '',
            'context'      => 'table',
            'name_prefix'  => false,
            'attr'         => [],
            'parent'       => false,
            'depend'       => false,
            'nexted_level' => 0
        ), $args);

        $o_inner = $p_open_wrapper = $o_close_wrapper = '';

        if ($args['before']) {
            $o_inner .= self::generate_field($args['before'], false);
        }

        if (!is_array($args['classes'])) {
            $args['classes'] = [$args['classes']];
        }

        $args['classes'] = array_filter($args['classes']);

        $label = $args['label'] ? "<label class='shzn-option-info' for='{$args['id']}'>{$args['label']}</label>" : '';
        $label_icon = $args['label'] ? '<icon class="shzn-option-info-icon"><span>i</span></icon>' : '';

        if ($args['context'] === 'action') {

            $o_inner .= "<input name='action' type='hidden' value='{$args['id']}'>";

            if (empty($args['type'])) {
                $args['type'] = 'submit';
            }
        }
        elseif ($args['context'] === 'table') {

            $padding_left = 30 * $args['nexted_level'];

            $row_class = $padding_left ? 'shzn-child' : '';

            $_style = $padding_left ? "style='padding-left: {$padding_left}px'" : '';

            $p_open_wrapper = "<row class='shzn-row {$row_class}'><div class='shzn-option' {$_style}><strong>{$args['name']}</strong>{$label_icon}</div><div class='shzn-value'>";
            $o_close_wrapper = "</div>{$label}</row>";
        }

        $args['input_name'] = empty($args['name_prefix']) ? $args['id'] : "{$args['name_prefix']}[{$args['id']}]";

        if ($args['parent'] or $args['depend']) {
            $args['attr']['data-parent'] = trim(implode(':', array_merge((array)$args['parent'], (array)$args['depend'])), ' :');
        }

        $dataValues = '';
        if (!empty($args['attr'])) {

            foreach ($args['attr'] as $key => $value) {
                $dataValues .= " {$key}='{$value}'";
            }

            $dataValues = trim($dataValues);
        }

        switch (strtolower($args['type'])) {

            case 'divide':
                $p_open_wrapper = $o_close_wrapper = '';
                $o_inner .= "<br>";
                break;

            case 'separator':
                $p_open_wrapper = $o_close_wrapper = '';
                $o_inner .= "<row class='shzn-row-title'><h3><strong>{$args['name']}</strong>{$label_icon}</h3>{$label}</row>";
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

                $o_inner .= self::buildField(
                    "input",
                    [
                        'class'        => self::classes($args['classes']),
                        'autocomplete' => 'off',
                        'type'         => $args['type'],
                        'name'         => $args['input_name'],
                        'id'           => $args['id'],
                        'placeholder'  => $args['placeholder'],
                        'spellcheck'   => 'false',
                        'value'        => (string)$args['value'],
                        ...$args['attr']
                    ]
                );
                break;

            case "upload-input":

                $args['classes'][] = 'shzn-input';
                $args['classes'][] = 'shzn-input-upload';
                $args['classes'][] = 'shzn-input__wrapper';

                $o_inner .= "<div class='" . self::classes($args['classes']) . "'>";

                $o_inner .= "<input " . self::buildProps([
                        'autocomplete' => 'off',
                        'type'         => 'text',
                        'name'         => $args['input_name'],
                        'id'           => $args['id'],
                        'placeholder'  => $args['placeholder'],
                        'value'        => (string)$args['value']
                    ]) . " {$dataValues}/>";

                $o_inner .= "<div class='shzn-uploader__init' data-type='image'>Upload Image</div>";

                $o_inner .= "</div>";

                break;

            case "checkbox":

                $args['classes'][] = "shzn-apple-switch";

                $o_inner .= self::buildField(
                    "input",
                    [
                        'class'   => self::classes($args['classes']),
                        'type'    => 'checkbox',
                        'name'    => $args['input_name'],
                        'id'      => $args['id'],
                        'value'   => (bool)$args['value'],
                        'checked' => UtilEnv::to_boolean($args['value']) ? 'checked' : '',
                        ...$args['attr']
                    ]);
                break;

            case "textarea":

                $args['classes'][] = "shzn";

                $o_inner .= self:: buildField(
                    "textarea",
                    [
                        'class'      => self::classes($args['classes']),
                        'rows'       => '4',
                        'cols'       => '80',
                        'type'       => 'textarea',
                        'name'       => $args['input_name'],
                        'id'         => $args['id'],
                        'spellcheck' => 'false',
                        ...$args['attr']
                    ],
                    $args['value']
                );
                break;

            case "label":
                $o_inner .= self::buildField(
                    'span',
                    [
                        'class' => self::classes($args['classes']),
                        ...$args['attr']
                    ],
                    $args['value']
                );
                break;

            case "link":

                $o_inner .= self::buildField(
                    'a',
                    [
                        'class' => self::classes($args['classes']),
                        'href'  => $args['value']['href'],
                        ...$args['attr']
                    ],
                    $args['value']['text']
                );

                break;

            case "dropdown":
                $args['classes'][] = 'dropdown';
                $o_inner .= self::buildDropdown($args, ['class' => self::classes($args['classes']), ...$args['attr']]);
                break;

            case 'raw':
            case 'html':
                $o_inner .= $args['value'];
                break;
        }

        $o_inner = "{$p_open_wrapper}{$o_inner}{$o_close_wrapper}";

        if ($args['after']) {
            $o_inner .= self::generate_field($args['after'], false);
        }

        if ($display) {
            echo $o_inner;
        }

        return $o_inner;
    }

    private static function classes($classes = [])
    {
        return trim(implode(' ', array_unique(array_filter($classes))));
    }

    public static function buildProps($props = [], $strip_empty = false)
    {
        $_props = '';

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

    private static function buildDropdown($args, $props = [])
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
        <dropdown class="shzn-dropdown" <?php self::buildProps($props) ?>>
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
        </dropdown>
        <?php
        return ob_get_clean();
    }

    public static function buildField($type, $props = [], $content = '')
    {
        if (!$content and str_contains('input.br.hr.link.meta.img.source', $type)) {
            return "<{$type} " . self::buildProps($props, true) . "/>";
        }

        return "<{$type} " . self::buildProps($props, true) . ">{$content}</{$type}>";
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
        <section class="shzn-ar-tabs" id="ar-tabs">
            <ul class="shzn-ar-tablist">
                <?php
                $panels = '';
                foreach ($fields as $field) {
                    $tab_title = empty($field['tab-title']) ? $field['panel-title'] : $field['tab-title'];
                    ?>
                    <li class="shzn-ar-tab" aria-controls="<?php echo $field['id']; ?>"
                        aria-selected="false"><?php echo $tab_title; ?></li>
                    <?php

                    // Support for limiting the rendering to only specific tab
                    if ($limit_ids) {
                        if (!in_array($field['id'], $limit_ids))
                            continue;
                    }

                    $aria_ajax = isset($field['ajax-callback']) ? "aria-ajax='" . json_encode($field['ajax-callback']) . "'" : '';

                    $panels .= "<panel id='{$field['id']}' class='shzn-ar-tabcontent' aria-hidden='true' {$aria_ajax}>" . self::generatePanelContent($field) . "</panel>";
                }
                ?>
            </ul>
            <?php echo $panels; ?>
        </section>
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
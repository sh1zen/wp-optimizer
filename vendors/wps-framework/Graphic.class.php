<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Graphic
{
    public static function generate_fields($fields_args, $infos = [], $args = [], $display = true): string
    {
        $output = '';
        $levels = array();

        foreach ($fields_args as $field_args) {

            if (isset($field_args[0])) {
                $output .= "<block class='wps-boxed--light'>" . self::generate_fields($field_args, $infos, $args, false) . "</block>";
                continue;
            }

            $field_args['name_prefix'] = $args['name_prefix'] ?? false;

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
            'type'         => '',
            'id'           => '',
            'name'         => '',
            'value'        => '',
            'placeholder'  => '',
            'label'        => '',
            'classes'      => '',
            'name_prefix'  => false,
            'props'        => [],
            'parent'       => false,
            'depend'       => false,
            'nexted_level' => 0,
            'context'      => 'table'
        ), $args);

        $o_inner = $p_open_wrapper = $o_close_wrapper = '';

        if ($args['before']) {
            $o_inner .= self::generate_field($args['before'], false);
        }

        $args['type'] = strtolower($args['type']);

        if (!is_array($args['classes'])) {
            $args['classes'] = explode(' ', $args['classes']) ?: [];
        }

        $args['classes'] = array_filter($args['classes']);

        $label = $args['label'] ? "<label class='wps-option-info' for='{$args['id']}'>{$args['label']}</label>" : '';
        $label_icon = $args['label'] ? '<icon class="wps-option-info-icon"><span>i</span></icon>' : '';

        switch ($args['context']) {

            case 'button':

                if (empty($args['type'])) {
                    $args['type'] = 'button';
                }

                break;

            case 'action':
                $o_inner .= "<input name='action' type='hidden' value='{$args['id']}'>";

                if (empty($args['type'])) {
                    $args['type'] = 'submit';
                }
                break;

            case 'table':
                $padding_left = 30 * $args['nexted_level'];

                $row_class = $padding_left ? 'wps-child' : '';

                $_style = $padding_left ? "style='padding-left: {$padding_left}px'" : '';

                $p_open_wrapper = "<row class='wps-row $row_class' $_style><div class='wps-option'><strong>{$args['name']}</strong>$label_icon</div><div class='wps-value'>";
                $o_close_wrapper = "</div>$label</row>";
                break;
        }

        $args['input_name'] = empty($args['name_prefix']) ? $args['id'] : "{$args['name_prefix']}[{$args['id']}]";

        if ($args['parent'] or $args['depend']) {
            $args['props']['data-parent'] = trim(implode(':', array_merge((array)$args['parent'], (array)$args['depend'])), ' :');
        }

        $dataValues = '';
        if (!empty($args['props'])) {

            foreach ($args['props'] as $key => $value) {
                $dataValues .= " $key='$value'";
            }

            $dataValues = trim($dataValues);
        }

        switch ($args['type']) {

            case 'divide':
                $p_open_wrapper = $o_close_wrapper = '';
                $o_inner .= "<br><br>";
                break;

            case 'separator':
                $p_open_wrapper = $o_close_wrapper = '';
                $o_inner .= "<row class='wps-row-title' $dataValues><h3><strong>{$args['name']}</strong>$label_icon</h3>$label</row>";
                break;

            case "time":
            case 'hidden':
            case "text":
            case "numeric":
            case "number":
            case "submit":

                $args['classes'][] = 'wps';
                $args['classes'][] = "wps-{$args['type']}";

                $o_inner .= self::buildField(
                    "input",
                    array_merge(
                        [
                            'class'        => self::parse_classes($args['classes']),
                            'autocomplete' => 'off',
                            'type'         => $args['type'],
                            'name'         => $args['input_name'],
                            'id'           => $args['id'],
                            'placeholder'  => $args['placeholder'],
                            'spellcheck'   => 'false',
                            'value'        => esc_attr((string)$args['value']),
                        ],
                        $args['props']
                    )
                );
                break;

            case 'button':
                $args['classes'][] = 'wps';
                $args['classes'][] = "wps-{$args['type']}";

                $o_inner .= self::buildField(
                    "button",
                    array_merge(
                        [
                            'id'    => $args['id'],
                            'class' => self::parse_classes($args['classes']),
                            'type'  => 'submit',
                            'name'  => $args['input_name'],
                            'value' => (string)$args['value'],
                        ],
                        $args['props']
                    ),
                    $args['name']
                );
                break;

            case "upload-input":

                $args['classes'][] = 'wps-input';
                $args['classes'][] = 'wps-input-upload';
                $args['classes'][] = 'wps-input__wrapper';

                $o_inner .= "<div class='" . self::parse_classes($args['classes']) . "'>";

                $o_inner .= "<input " . self::buildProps([
                        'autocomplete' => 'off',
                        'type'         => 'text',
                        'name'         => $args['input_name'],
                        'id'           => $args['id'],
                        'placeholder'  => $args['placeholder'],
                        'value'        => (string)$args['value']
                    ]) . " $dataValues/>";

                $o_inner .= "<div class='wps-uploader__init' data-type='image'>Upload Image</div>";

                $o_inner .= "</div>";

                break;

            case "checkbox":

                $args['classes'][] = "wps-apple-switch";

                $o_inner .= self::buildField(
                    "input",
                    array_merge(
                        [
                            'class'   => self::parse_classes($args['classes']),
                            'type'    => 'checkbox',
                            'name'    => $args['input_name'],
                            'id'      => $args['id'],
                            'value'   => (bool)$args['value'],
                            'checked' => UtilEnv::to_boolean($args['value']) ? 'checked' : '',
                        ],
                        $args['props']
                    )
                );
                break;

            case "textarea":
            case "textarea_array":

                $args['classes'][] = "wps";

                $o_inner .= self:: buildField(
                    "textarea",
                    array_merge(
                        [
                            'class'      => self::parse_classes($args['classes']),
                            'rows'       => '4',
                            'cols'       => '80',
                            'type'       => 'textarea',
                            'name'       => $args['input_name'],
                            'id'         => $args['id'],
                            'spellcheck' => 'false',
                        ],
                        $args['props']
                    ),
                    $args['value']
                );
                break;

            case "label":
                $o_inner .= self::buildField(
                    'span',
                    array_merge(
                        [
                            'class' => self::parse_classes($args['classes']),
                        ],
                        $args['props']
                    ),
                    $args['value']
                );
                break;

            case "link":

                $o_inner .= self::buildField(
                    'a',
                    array_merge(
                        [
                            'class' => self::parse_classes($args['classes']),
                            'href'  => esc_url($args['value']['href']),
                        ],
                        $args['props']
                    ),
                    $args['value']['text']
                );

                break;

            case "dropdown":
                $args['classes'][] = 'dropdown';

                $parent = $args['parent'] ?: $args['depend'] ?: '';

                $o_inner .= self::buildDropdown(
                    $args,
                    array_merge(
                        ['class' => self::parse_classes($args['classes'])],
                        $args['props']
                    ),
                    $parent
                );
                break;

            case 'raw':
            case 'html':
                $o_inner .= $args['value'];
                break;
        }

        $o_inner = $p_open_wrapper . $o_inner . $o_close_wrapper;

        if ($args['after']) {
            $o_inner .= self::generate_field($args['after'], false);
        }

        if ($display) {
            echo $o_inner;
        }

        return $o_inner;
    }

    public static function newField($name, $id = false, $type = 'text', $args = []): array
    {
        if (!is_array($args)) {
            $args = ['value' => $args];
        }

        $args = array_merge([
            'value'         => null,
            'default_value' => '',
            'allow_empty'   => true,
            'parent'        => false,
            'depend'        => false,
            'placeholder'   => '',
            'list'          => ''
        ], $args);

        if ($id or $type === 'link') {
            $value = is_null($args['value']) ? $args['default_value'] : $args['value'];
        }
        else {
            $value = $args['value'] ?: '';
        }

        if (empty($value) and !$args['allow_empty']) {
            $value = $args['default_value'];
        }

        return [
            'type'        => $type,
            'name'        => $name,
            'id'          => $id,
            'value'       => $value,
            'parent'      => $args['parent'],
            'depend'      => $args['depend'],
            'placeholder' => $args['placeholder'],
            'list'        => $args['list']
        ];
    }

    public static function buildField($type, $props = [], $content = ''): string
    {
        if (!$content and str_contains('input.br.hr.link.meta.img.source', $type)) {
            return "<$type " . self::buildProps($props, true) . "/>";
        }

        return "<$type " . self::buildProps($props, true) . ">$content</$type>";
    }

    public static function buildProps($props = [], $strip_empty = false): string
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

    private static function parse_classes($classes = []): string
    {
        return trim(implode(' ', array_unique(array_filter($classes))));
    }

    private static function buildDropdown($args, $props = [], $parent = '')
    {
        ob_start();

        $args = array_merge([
            'id'         => '',
            'list'       => [],
            'value'      => '',
            'input_name' => '',
            'hide_input' => false
        ], $args);

        $items = $args['list'];
        $editable = (isset($items[0]) and !$args['hide_input']);

        if ($parent) {
            $parent = "data-parent='$parent'";
        }

        if (!$editable and is_array($args['value'])) {
            $args['valueE'] = key($args['value']);
            $args['value'] = $args['value'][$args['valueE']];
        }

        ?>
        <dropdown class="wps-dropdown" <?php self::buildProps($props) ?>>
            <row class="wps-input__wrapper">
                <input name="<?php echo $args['input_name']; ?>" id="<?php echo $args['id']; ?>"
                       type="<?php echo $editable ? 'text' : 'hidden' ?>"
                       value="<?php echo $args['value']; ?>" autocomplete="off" <?php echo $parent; ?>
                       placeholder="<?php _e("Choose a type or enter one manually.", 'wps'); ?>">
                <?php if (!$editable) : ?>
                    <strong class="width100 wps-input"
                            data-input="<?php echo $args['id']; ?>"><?php echo $args['valueE'] ?? $args['value']; ?></strong>
                <?php endif; ?>
                <div class="wps-dropdown__opener">
                    <svg class="wps-icon wps-icon__arrow" viewBox="0 0 16 16" width="16" height="16">
                        <path d="M11.293 8L4.646 1.354l.708-.708L12.707 8l-7.353 7.354-.708-.708z"></path>
                    </svg>
                </div>
            </row>
            <div class="wps-multiselect__wrapper">
                <ul class='wps-multiselect'>
                    <?php
                    foreach ($items as $text => $value) {
                        $text = is_numeric($text) ? $value : $text;
                        echo "<li data-value='$value' class='wps-multiselect__element'><span>$text</span></li>";
                    }
                    ?>
                </ul>
            </div>
        </dropdown>
        <?php
        return ob_get_clean();
    }

    /**
     * Renders for panel-tabs
     * support specif tabs with $limit_ids arg
     *
     * @param $fields
     * @param array $limit_ids
     * @return false|string
     */
    public static function generateHTML_tabs_panels($fields, array $limit_ids = array())
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
        <section class="wps-ar-tabs" id="ar-tabs">
            <ul class="wps-ar-tablist">
                <?php
                $panels = '';
                foreach ($fields as $field) {
                    $tab_title = $field['tab-title'] ?? $field['panel-title'];
                    ?>
                    <li class="wps-ar-tab" aria-controls="<?php echo $field['id']; ?>"
                        aria-selected="false"><?php echo $tab_title; ?></li>
                    <?php

                    // Support for limiting the rendering to only specific tab
                    if ($limit_ids) {
                        if (!in_array($field['id'], $limit_ids))
                            continue;
                    }

                    $aria_ajax = isset($field['ajax-callback']) ? "aria-ajax='" . json_encode($field['ajax-callback']) . "'" : '';

                    $panels .= "<panel id='{$field['id']}' class='wps-ar-tabcontent' aria-hidden='true' $aria_ajax>" . self::generatePanelContent($field) . "</panel>";
                }
                ?>
            </ul>
            <?php echo $panels; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    private static function generatePanelContent($field): string
    {
        if (!is_array($field)) {
            return '';
        }

        $HTML = '';

        if (!empty($field['panel-title'])) {
            $HTML .= "<h2>{$field['panel-title']}</h2>";
        }

        if (isset($field['callback'])) {
            $args = $field['args'] ?? array();

            if (is_callable($field['callback'])) {
                $content = call_user_func_array($field['callback'], $args);
            }
            elseif (is_string($field['callback']) and is_file($field['callback'])) {
                if (isset($field['context'])) {
                    extract(['class' => $field['context']], EXTR_OVERWRITE);
                }
                ob_start();
                include $field['callback'];
                $content = ob_get_clean();
            }
            else {
                $content = '';
            }

            $HTML .= $content;
        }

        return $HTML;
    }

    public static function is_on_screen($slug): bool
    {
        return isset($_GET['page']) and str_contains($_GET['page'], trim($slug));
    }
}
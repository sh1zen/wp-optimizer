<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Graphic
{
    public static function generate_fields($fields_args, $infos = [], $args = [], $display = true): string
    {
        $output = '';
        $loose_fields = '';
        $levels = array();
        $wrap_loose_fields = $args['wrap_loose_fields'] ?? true;

        $flush_loose_fields = function () use (&$output, &$loose_fields, $wrap_loose_fields) {
            if ($loose_fields === '') {
                return;
            }

            if ($wrap_loose_fields) {
                $output .= "<wps-block class='wps-boxed--light wps-settings-section wps-settings-section-plain'>{$loose_fields}</wps-block>";
            }
            else {
                $output .= $loose_fields;
            }

            $loose_fields = '';
        };

        foreach ($fields_args as $field_args) {

            if (is_array($field_args) and isset($field_args[0])) {
                $flush_loose_fields();
                $output .= self::generate_field_group($field_args, $infos, $args);
                continue;
            }

            if (is_array($field_args) and strtolower((string)($field_args['type'] ?? '')) === 'separator') {
                $flush_loose_fields();
                $field_args['name_prefix'] = $args['name_prefix'] ?? false;
                $output .= self::generate_field($field_args, false);
                continue;
            }

            if (!is_array($field_args)) {
                $loose_fields .= self::generate_field($field_args, false);
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

            $loose_fields .= self::generate_field($field_args, false);
        }

        $flush_loose_fields();

        if ($display) {
            echo $output;
        }

        return $output;
    }

    private static function generate_field_group(array $field_args, $infos = [], $args = []): string
    {
        $section = null;

        if (isset($field_args[0]) and is_array($field_args[0]) and strtolower((string)($field_args[0]['type'] ?? '')) === 'separator') {
            $section = array_shift($field_args);
        }

        $group_args = $args;
        $group_args['wrap_loose_fields'] = false;
        $fields = self::generate_fields($field_args, $infos, $group_args, false);

        if (!$section) {
            return "<wps-block class='wps-boxed--light wps-settings-section wps-settings-section-plain'>{$fields}</wps-block>";
        }

        $name = (string)($section['name'] ?? '');
        $label = $section['label'] ?? '';
        $icon = self::icon(($section['icon'] ?? '') ?: self::separator_icon($name), 'wps-section-icon');
        $description = $label ? "<p class='wps-section-description'>{$label}</p>" : '';
        $props = !empty($section['props']) && is_array($section['props']) ? ' ' . self::buildProps($section['props'], true) : '';

        return "<wps-block class='wps-boxed--light wps-settings-section'{$props}><div class='wps-section-head'>{$icon}<div class='wps-section-title'><h3>{$name}</h3>{$description}</div></div><div class='wps-section-body'>{$fields}</div></wps-block>";
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
            'icon'         => '',
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
        $description = $args['label'] ? "<span class='wps-option-description'>{$args['label']}</span>" : '';
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
                $row_type_class = sanitize_html_class('wps-row-type-' . str_replace('_', '-', $args['type']));
                $row_id_class = $args['id'] ? sanitize_html_class('wps-row-' . str_replace(['.', '_'], '-', $args['id'])) : '';
                $row_icon = self::icon($args['icon'] ?: self::field_icon($args['id'], $args['type']), 'wps-row-icon');

                $_style = $padding_left ? "style='padding-left: {$padding_left}px'" : '';

                $p_open_wrapper = "<row class='wps-row $row_class $row_type_class $row_id_class' $_style>{$row_icon}<div class='wps-option'><strong>{$args['name']}</strong>$description$label_icon</div><div class='wps-value'>";
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
                $is_notice = self::is_separator_notice($args['name']);
                $notice_class = $is_notice ? ' wps-row-notice' : '';
                $separator_icon = self::icon($args['icon'] ?: ($is_notice ? 'info' : self::separator_icon($args['name'])), 'wps-row-icon');
                $o_inner .= "<row class='wps-row-title{$notice_class}' $dataValues>{$separator_icon}<h3><strong>{$args['name']}</strong>$label_icon</h3>$label</row>";
                break;

            case "time":
            case 'hidden':
            case "text":
            case "numeric":
            case "number":
            case "range":
            case "submit":

                $args['classes'][] = 'wps';
                $args['classes'][] = "wps-{$args['type']}";

                $input_type = $args['type'] === 'range' ? 'range' : $args['type'];

                if ($args['type'] === 'range') {
                    $args['props']['min'] = $args['props']['min'] ?? '1';
                    $args['props']['max'] = $args['props']['max'] ?? '10';
                    $args['props']['step'] = $args['props']['step'] ?? '1';
                    $args['props']['oninput'] = trim(($args['props']['oninput'] ?? '') . " this.closest('.wps-range-field').querySelector('.wps-range-value').textContent=this.value;");
                    $o_inner .= "<div class='wps-range-field'>";
                }

                $o_inner .= self::buildField(
                    "input",
                    array_merge(
                        [
                            'class'        => self::parse_classes($args['classes']),
                            'autocomplete' => 'off',
                            'type'         => $input_type,
                            'name'         => $args['input_name'],
                            'id'           => $args['id'],
                            'placeholder'  => $args['placeholder'],
                            'spellcheck'   => 'false',
                            'value'        => esc_attr((string)$args['value']),
                        ],
                        $args['props']
                    )
                );

                if ($args['type'] === 'range') {
                    $o_inner .= "<span class='wps-range-value'>" . esc_html((string)$args['value']) . "</span>";
                    $o_inner .= "</div>";
                }
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
            'list'        => $args['list'],
            'icon'        => $args['icon'] ?? '',
            'classes'     => $args['classes'] ?? '',
            'props'       => $args['props'] ?? [],
            'before'      => $args['before'] ?? false,
            'after'       => $args['after'] ?? false,
            'context'     => $args['context'] ?? 'table',
        ];
    }

    public static function buildField($type, $props = [], $content = ''): string
    {
        if (!$content and str_contains('input.br.hr.link.meta.img.source', $type)) {
            return "<$type " . self::buildProps($props, true) . "/>";
        }

        return "<$type " . self::buildProps($props, true) . ">$content</$type>";
    }

    public static function icon(string $icon, string $class = '', string $label = ''): string
    {
        $icon = sanitize_key($icon);

        if ($icon === '') {
            return '';
        }

        $class = trim('wps-svg-icon ' . $class);
        $href = esc_url(UtilEnv::path_to_url(__DIR__) . 'assets/icons/wps-icons.svg#wps-icon-' . $icon);
        $aria = $label === '' ? 'aria-hidden="true" focusable="false"' : 'role="img" aria-label="' . esc_attr($label) . '"';

        return '<svg class="' . esc_attr($class) . '" ' . $aria . '><use href="' . $href . '"></use></svg>';
    }

    private static function field_icon(string $id, string $type): string
    {
        $icons = array(
            'active'          => 'check',
            'execution-time'  => 'clock',
            'recurrence'      => 'repeat',
            'database.active' => 'database',
            'media.active'    => 'image',
        );

        if (isset($icons[$id])) {
            return $icons[$id];
        }

        if ($type === 'checkbox') {
            return 'check';
        }

        if ($type === 'time') {
            return 'clock';
        }

        if ($type === 'range') {
            return 'sliders';
        }

        if ($type === 'textarea_array' || $type === 'textarea') {
            return 'list';
        }

        if ($type === 'link') {
            return 'external';
        }

        if ($type === 'dropdown') {
            return 'repeat';
        }

        return 'settings';
    }

    private static function separator_icon(string $name): string
    {
        $name = strtolower(wp_strip_all_tags($name));

        if (str_contains($name, 'sweep') || str_contains($name, 'clean')) {
            return 'broom';
        }

        if (str_contains($name, 'backup') || str_contains($name, 'database') || str_contains($name, 'sql')) {
            return 'database';
        }

        if (str_contains($name, 'image') || str_contains($name, 'media') || str_contains($name, 'format')) {
            return 'image';
        }

        if (str_contains($name, 'security') || str_contains($name, 'ssl') || str_contains($name, 'api')) {
            return 'shield';
        }

        if (str_contains($name, 'server') || str_contains($name, 'cache')) {
            return 'server';
        }

        if (str_contains($name, 'mail') || str_contains($name, 'smtp')) {
            return 'mail';
        }

        if (str_contains($name, 'speed') || str_contains($name, 'performance') || str_contains($name, 'optimization')) {
            return 'gauge';
        }

        return 'settings';
    }

    private static function is_separator_notice(string $name): bool
    {
        $name = strtolower(wp_strip_all_tags($name));

        return str_contains($name, 'available only')
            || str_contains($name, 'only in')
            || str_contains($name, 'up to now')
            || str_contains($name, 'apache only')
            || str_contains($name, 'warning')
            || str_contains($name, 'notice');
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
                       placeholder="Choose a type or enter one manually.">
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
    public static function generateHTML_tabs_panels($fields, array $limit_ids = array(), string $after_tablist = '')
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
                    $tab_icon = !empty($field['tab-icon']) ? self::icon($field['tab-icon'], 'wps-tab-icon') : '';
                    ?>
                    <li class="wps-ar-tab" aria-controls="<?php echo $field['id']; ?>"
                        aria-selected="false"><?php echo $tab_icon; ?><span><?php echo $tab_title; ?></span></li>
                    <?php

                    // Support for limiting the rendering to only specific tab
                    if ($limit_ids) {
                        if (!in_array($field['id'], $limit_ids))
                            continue;
                    }

                    $aria_ajax = isset($field['ajax-callback']) ? "aria-ajax='" . esc_attr(wp_json_encode($field['ajax-callback'])) . "'" : '';
                    $panel_classes = array('wps-ar-tabcontent');

                    if (!empty($field['panel-flush'])) {
                        $panel_classes[] = 'wps-ar-tabcontent-flush';
                    }

                    if (!empty($field['panel-class'])) {
                        foreach (preg_split('/\s+/', (string)$field['panel-class']) as $panel_class) {
                            $panel_class = sanitize_html_class($panel_class);
                            if ($panel_class) {
                                $panel_classes[] = $panel_class;
                            }
                        }
                    }

                    $panel_id = esc_attr((string)$field['id']);
                    $panel_class_attr = esc_attr(implode(' ', array_unique($panel_classes)));

                    $panels .= "<panel id='{$panel_id}' class='{$panel_class_attr}' aria-hidden='true' $aria_ajax>" . self::generatePanelContent($field) . "</panel>";
                }
                ?>
            </ul>
            <?php echo $after_tablist; ?>
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
            $panel_icon = !empty($field['panel-icon']) ? self::icon($field['panel-icon'], 'wps-panel-icon') : '';
            $panel_description = !empty($field['panel-description']) ? "<p>{$field['panel-description']}</p>" : '';
            $panel_status = $field['panel-status'] ?? '';

            if ($panel_icon || $panel_description || $panel_status) {
                $HTML .= "<div class='wps-panel-head'>{$panel_icon}<div class='wps-panel-title'><h2>{$field['panel-title']}</h2>{$panel_description}</div>{$panel_status}</div>";
            }
            else {
                $HTML .= "<h2>{$field['panel-title']}</h2>";
            }
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

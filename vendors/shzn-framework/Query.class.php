<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2023.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace SHZN\core;

class Query
{
    private $output = OBJECT;

    private string $sql;

    private array $args;

    private array $tables_map = [];

    public function __construct($output = OBJECT)
    {
        $this->reset();

        $this->output($output);
    }

    public function reset()
    {
        $this->tables_map = [];

        $this->args = [
            'action'  => 'SELECT',
            'fields'  => '*',
            'tables'  => '',
            'join'    => [],
            'where'   => [],
            'orderby' => [],
            'groupby' => '',
            'limit'   => '',
            'offset'  => ''
        ];
    }

    public function output($output): Query
    {
        $this->output = $output;
        return $this;
    }

    public static function getInstance($output = OBJECT): Query
    {
        return new self($output);
    }

    public function alter($table, $todo)
    {
        return $this->wpdb()->query("ALTER TABLE {$table} {$todo};");
    }

    public function query($single = false)
    {
        global $wpdb;

        if (empty($this->sql)) {
            $this->compile();
        }

        if ($single) {

            $res = $wpdb->get_row($this->sql, $this->output);

            $as_array = (array)$res;

            if (count($as_array) === 1) {
                return reset($as_array);
            }

            return $res;
        }
        else {

            return $wpdb->get_results($this->sql, $this->output);
        }
    }

    public function compile(): Query
    {
        $action = $this->args['action'] ?: 'SELECT';

        $fields = $this->args['fields'] ?: '*';

        $tables = $this->args['tables'] ?: '';

        $join = implode(' ', $this->args['join']);

        $where = implode(' AND ', $this->args['where']);

        $orderby = implode(', ', $this->args['orderby']) ?: '';

        if (!empty($orderby)) {
            $orderby = "ORDER BY $orderby";
        }

        $groupby = $this->args['groupby'] ?: '';

        $limits = $this->args['limit'] ?: '';

        $offset = $this->args['offset'] ?: '';

        if (!empty($where)) {
            $where = "WHERE 1=1 AND $where";
        }

        switch ($action) {
            case 'SELECT':
                $this->sql = "$action $fields FROM $tables $join $where $groupby $orderby $limits $offset";
                break;

            case 'DELETE':
                $this->sql = "$action FROM $tables $where";
                break;

            case 'TRUNCATE':
                $this->sql = "$action $tables";
                break;
        }

        $this->sql = trim($this->sql, ' ');

        return $this;
    }

    public function wpdb()
    {
        global $wpdb;
        return $wpdb;
    }

    public function export(): string
    {
        return $this->sql;
    }

    public function orderby($field, $order = ''): Query
    {
        $this->args['orderby'][] = trim($field . ' ' . $this->parse_order($order), ' ');

        return $this;
    }

    private function parse_order(string $order): string
    {
        if (in_array(strtolower($order), array('desc', 'asc'))) {
            return $order;
        }

        return '';
    }

    public function limit($limit): Query
    {
        if (!is_numeric($limit) or $limit == 0) {
            $limit = false;
        }

        $this->args['limit'] = $limit ? "LIMIT $limit" : '';

        return $this;
    }

    public function groupby($groupby): Query
    {
        $this->args['groupby'] = $groupby ? "GROUP BY " . $groupby : '';

        return $this;
    }

    /**
     * $joins = [ 'table' => ['id' => 'wp_id'];
     * $joins = ['table' => ['key' => '', 'value' => '', 'compare' => '']]
     * $joins = ''
     */
    public function join($joins, $type = 'INNER JOIN'): Query
    {
        if (is_array($joins)) {

            $_joins = '';
            foreach ($joins as $table => $constraints) {

                if (is_array($constraints)) {
                    $constraints = implode(' ', self::parse_fields($constraints));
                }

                $_joins = "{$_joins} {$type} {$table} ON ({$constraints}) ";
            }

            $joins = $_joins;
        }

        $this->args['join'][] = $joins;

        return $this;
    }

    /**
     * [
     *      ['key' => '', 'value' => '', 'compare' => ''],
     *      ['key' => 'value', 'compare' => ''],
     *      ['key' => 'value']
     * ]
     */
    public static function parse_fields($fields, $prefix = '', $nullable = false): array
    {
        $parsed = [];

        // one level field: ['key' => 'value', 'compare' => '']
        if (isset($fields['compare']) and !isset($fields[0])) {
            $fields = [$fields];
        }

        foreach ($fields as $key => $field) {

            if (!is_array($field)) {
                $field = ['key' => $key, 'compare' => '=', 'value' => $field];
            }

            if (!isset($field['key'], $field['value'])) {

                if (!isset($field['compare'])) {
                    continue;
                }

                $compare = $field['compare'];
                unset($field['compare']);

                $key = key($field);
                $value = $field[$key];

                $field = ['key' => $key, 'compare' => $compare, 'value' => $value];
            }

            if (is_null($field['value']) and !$nullable) {
                continue;
            }

            if (is_array($field['value'])) {

                $field['value'] = array_filter($field['value']);

                if (empty($field['value'])) {
                    continue;
                }

                $field['value'] = "(" . implode(',', $field['value']) . ")";
            }
            elseif (is_bool($field['value'])) {
                $field['value'] = $field['value'] ? 1 : 0;
            }
            else {
                $field['value'] = "'{$field['value']}'";
            }

            $key = $prefix ? "$prefix.{$field['key']}" : $field['key'];

            if ($field['compare'] === 'LIKE') {
                $field['value'] = "%" . trim($field['value'], " %") . "%";
            }

            $parsed[] = "$key {$field['compare']} {$field['value']}";
        }

        return $parsed;
    }

    public function offset($offset): Query
    {
        if (!is_numeric($offset) or $offset == 0) {
            $offset = false;
        }

        $this->args['offset'] = $offset ? "OFFSET {$offset}" : '';

        return $this;
    }

    public function select($fields, $as = false): Query
    {
        return $this->action('SELECT')->fields($fields, $as);
    }

    /**
     * $fields = [ 'field1', 'field2' ];
     * $fields = [ 'referenceTable1'=> 'field1', 'referenceTable1' => 'field2' ];
     * $fields = ''
     */
    public function fields($fields, $as = false): Query
    {
        if (is_array($fields)) {
            if ($as) {
                foreach ($fields as $reference => $field) {
                    $as_table = is_numeric($reference) ? "t{$reference}" : $reference;
                    $fields[$reference] = "{$as_table}.{$field}";
                }

            }
            $fields = implode(', ', $fields);
        }

        $this->args['fields'] = $fields;

        return $this;
    }

    public function action($action): Query
    {
        $this->args['action'] = strtoupper($action);

        return $this;
    }

    public function delete($table, $conditions = [], $relation = 'AND')
    {
        return $this->action('DELETE')->tables([$table], false)->where($conditions, $relation)->query();
    }

    /**
     * $where = ['T1' => 'constraints', 'T2' => ['key' => '', 'value' => '', 'compare' => '']]
     * $where = ''
     */
    public function where($wheres, $relation = 'AND'): Query
    {
        if (!empty($wheres)) {

            $relation = strtoupper($relation);

            if (is_array($wheres)) {
                $wheres = implode(" $relation ", self::parse_fields($wheres));
            }

            $this->args['where'][] = "($wheres)";
        }

        return $this;
    }

    /**
     * $from = [ 'table1', 'table2' ]
     * $from = ['T1' => 'table1', 'T2' => 'table2' ]
     */
    public function tables(array $tables, $as = false): Query
    {
        global $wpdb;

        foreach ($tables as $key => $table) {
            if ($as) {
                $as_table = is_numeric($key) ? "T$key" : $key;
                $tables[$key] = "$table AS $as_table";

                $this->tables_map[$table] = $as_table;
            }

            if (!str_starts_with($table, $wpdb->prefix)) {
                $tables[$key] = $wpdb->prefix . $table;
            }
        }

        $tables = implode(', ', $tables);

        $this->args['tables'] = $tables;

        return $this;
    }
}
<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2024.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Query
{
    private $output = OBJECT;

    private string $sql;

    private string $action = 'SELECT';
    private array $tables = [];
    private array $columns = [];
    private array $where = [];
    private array $values = [];
    private array $orderby = [];
    private array $groupby = [];
    private array $having = [];
    private array $joins = [];
    private int $limit = 0;
    private int $offset = 0;

    private array $table_aliases = [];

    private array $references_map = [];

    private array $reference_count = [];

    private bool $debug = false;

    private bool $use_reference;

    private array $joining_tables = [];

    private function __construct($output, $useReference)
    {
        $this->reset();

        $this->use_reference = $useReference;

        $this->output($output);
    }

    public function reset(): void
    {
        $this->joining_tables = [];

        $this->table_aliases = [];
        $this->references_map = [];
        $this->reference_count = [];

        $this->sql = '';
    }

    public function output($output): Query
    {
        $this->output = $output;
        return $this;
    }

    public static function getInstance($output = OBJECT, $useReference = false): Query
    {
        return new self($output, $useReference);
    }

    public function alter($table, $action)
    {
        return $this->wpdb()->query("ALTER TABLE $table $action;");
    }

    public function query($single = false, $use_col = false)
    {
        global $wpdb;

        if (empty($this->sql)) {
            $this->compile();
        }

        if ($this->debug) {
            print_r($this->sql);
        }

        if (!str_starts_with($this->sql, 'SELECT')) {
            return $wpdb->query($this->sql);
        }
        else {

            $field_count = preg_match("#^SELECT\s+\*\s+FROM#", $this->sql) ? 1000 : count($this->columns);

            if ($single) {

                if ($field_count <= 1) {
                    return $wpdb->get_var($this->sql);
                }

                return $wpdb->get_row($this->sql, $this->output);
            }
            else {

                if ($field_count <= 1 and $use_col) {
                    return $wpdb->get_col($this->sql);
                }

                return $wpdb->get_results($this->sql, $this->output);
            }
        }
    }

    public function compile($debug = false): string
    {
        $columns = $tables = $values = [];

        $this->action = $this->action ?: 'SELECT';

        $orderby = $this->parse_statement('orderby');

        $groupby = $this->parse_statement('groupby');

        $having = $this->parse_statement('having');

        $limit = $this->limit ? "LIMIT $this->limit" : '';

        $offset = $this->offset ? "OFFSET $this->offset" : '';

        foreach ($this->columns as $field) {

            if ($field['aggregate']) {
                $column = "{$field['aggregate']}(" . ($field['name'] === '*' ? '' : $this->get_table_alias($field['table'], true)) . "{$field['name']})";
            }
            else {
                $column = $this->get_table_alias($field['table'], true) . "{$field['name']} ";
            }

            if (!empty($field['as'])) {
                $field['as'] = "AS {$field['as']}";
            }

            $columns[] = rtrim("$column {$field['extra']} {$field['as']}", ' ');
        }

        foreach ($this->tables as $table) {
            $tables[] = ($this->use_reference ? "$table AS " . $this->get_table_alias($table) : $table) . ' ' . ($this->joins[$table] ?? '');
        }
        $tables = implode(', ', $tables);

        foreach ($this->joining_tables as $conditions) {

            $compare = $conditions['compare'] ?? '=';
            unset($conditions['compare']);

            $tab1 = wps_array_key_next($conditions);
            $field1 = $conditions[$tab1];
            $tab1_reference = $this->get_table_alias($tab1);

            $tab2 = wps_array_key_next($conditions);
            $field2 = $conditions[$tab2];
            $tab2_reference = $this->get_table_alias($tab2);

            if ($this->reference_count[$tab1] and $this->reference_count[$tab2]) {
                $this->where[] = "$tab1_reference.$field1 $compare $tab2_reference.$field2";
            }
        }

        $where = implode(' AND ', $this->where);

        if (!empty($where)) {

            $where = "WHERE 1=1 AND $where";

            if ($this->action === 'INSERT') {
                $this->action = 'UPDATE';
                if (isset($this->values[0])) {
                    $values = implode(', ', $this->parse_fields($this->values[0], false, '', true));
                }
            }
        }

        if ($this->action === 'INSERT') {
            foreach ($this->values as $tuple) {
                $values[] = implode(", ", wps_array_sort($tuple, $columns));
            }
            $values = implode('), (', $values);
        }

        $columns_list = implode(', ', $columns) ?: '*';

        $sql = match ($this->action) {

            'SELECT' => "SELECT $columns_list FROM $tables $where $groupby $having $orderby $limit $offset",

            'DELETE' => "DELETE FROM $tables $where",

            'TRUNCATE' => "TRUNCATE $tables",

            'INSERT' => "INSERT INTO $tables ($columns_list) VALUES ($values)",

            'UPDATE' => "UPDATE $tables SET {$values} $where",

            default => '',
        };

        $sql = trim($sql, ' ');

        if (!$debug) {
            $this->sql = $sql;
        }

        return $sql;
    }

    private function parse_statement(string $statement): string
    {
        $query_conditions = $this->$statement;

        if (empty($query_conditions)) {
            return '';
        }

        switch ($statement) {

            case 'groupby':
                $sql_statement = 'GROUP BY';
                $joiner = ', ';
                break;

            case 'orderby':
                $sql_statement = 'ORDER BY';
                $joiner = ', ';
                break;

            default:
                $sql_statement = strtoupper($statement);
                $joiner = ' ';
        }

        if (!is_array($query_conditions)) {
            return $sql_statement . " " . $query_conditions;
        }

        $states = [];
        foreach ($query_conditions as $statements) {

            foreach ($statements as $maybe_table => $state) {
                if ($this->use_reference) {
                    $maybe_table = $this->get_table_alias(is_numeric($maybe_table) ? '' : $maybe_table);
                    $states[] = trim("$maybe_table.$state", '.');
                }
                else {
                    $states[] = $state;
                }
            }
        }

        return $sql_statement . " " . implode($joiner, $states);
    }

    private function get_table_alias($table, $dot = false, $auto_set = false)
    {
        if (empty($table)) {
            return '';
        }

        if ($this->use_reference) {

            if ($auto_set and !isset($this->table_aliases[$table])) {
                $this->set_table_alias([$table => 0]);
            }

            if (isset($this->table_aliases[$table])) {

                $reference = $this->table_aliases[$table];
                if ($dot) {
                    $reference .= ".";
                }
                $this->reference_count[$table]++;
                return $reference;
            }
        }
        return '';
    }

    /**
     * Change table reference,
     * Works only before where method call
     */
    public function set_table_alias(array $tables): Query
    {
        foreach ($tables as $table => $reference) {

            $reference = is_numeric($reference) ? "T" . count($this->table_aliases) : $reference;

            $this->table_aliases[$table] = $reference;
            $this->references_map[$reference] = $table;
            $this->reference_count[$table] = 0;
        }

        return $this;
    }

    /**
     * [
     *      ['key' => '', 'value' => '', 'compare' => ''],
     *      ['key' => 'value', 'compare' => ''],
     *      ['key' => 'value']
     * ]
     * ['key' => '', 'value' => '', 'compare' => ''],
     * ['key' => 'value', 'compare' => ''],
     * ['key' => 'value']
     *
     * (column => [table2 => column], table1)
     */
    public function parse_fields($fields, $escape = true, $prefix = '', $unquoted = false): array
    {
        $parsed = [];

        // convert one level array into standard two level ones
        if (isset($fields['compare'])) {
            $fields = [$fields];
        }

        foreach ($fields as $maybe_table => $field) {

            if (is_null($field)) {
                continue;
            }

            $compare = $field['compare'] ?? false;
            $key = $maybe_table;
            $iter_unquoted = $unquoted;

            if (is_array($field)) {

                if ($prx = $this->get_table_alias($maybe_table)) {
                    // handles table_name => [...]
                    $parsed = array_merge($parsed, $this->parse_fields($field, true, $prx, $unquoted));
                    continue;
                }

                if ($prx = $this->get_table_alias(key($field))) {

                    // handles ([column => [table2 => column]], table1, ...)
                    $field = "$prx." . $field[key($field)];
                    // allowed to unquote because is prefixed by a table (alias)
                    $iter_unquoted = true;
                }
                else {
                    unset($field['compare']);

                    if (isset($field['key'], $field['value'])) {
                        $key = $field['key'];
                        $field = $field['value'];
                    }
                    elseif (is_numeric($key)) {
                        // handles 1 => [key => value, 'compare'? => '=']
                        $key = key($field);
                        $field = $field[$key];
                    }
                    // handles one level array [key => value]
                }
            }

            list($key, $compare, $field) = self::parse_key_compare_field($key, $compare, $field, $escape, $iter_unquoted, $prefix);

            if (is_null($field)) {
                continue;
            }

            $parsed[] = "$key $compare $field";
        }

        return $parsed;
    }

    private static function parse_key_compare_field($key, $compare, $value, $escape, $unquoted, $prefix = ''): array
    {
        global $wpdb;

        if (!$compare) {
            $compare = (is_array($value) ? 'IN' : '=');
        }
        else {
            $compare = strtoupper($compare);
        }

        if (str_contains($compare, 'BETWEEN')) {
            $value = "'$value[0]' AND '$value[1]'";
        }
        elseif (is_array($value)) {

            $value = array_filter($value);

            if (empty($value)) {
                return [$key, $compare, null];
            }

            if ($escape) {
                $value = array_map('esc_sql', $value);
            }

            if ($unquoted) {
                $value = "(" . implode(',', $value) . ")";
            }
            else {
                $value = "('" . implode("','", $value) . "')";
            }
        }
        elseif (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        elseif (preg_match('#^[(\s]*SELECT\s+#i', $value)) {
            $value = "($value)";
        }
        else {
            if ($escape) {
                $value = $compare === 'LIKE' ? "%" . esc_sql($wpdb->esc_like(trim($value, "' %"))) . "%" : esc_sql($value);
            }
            else {
                $value = $compare === 'LIKE' ? "%" . trim($value, "' %") . "%" : $value;
            }

            if (!$unquoted) {
                $value = "'$value'";
            }
        }

        $key = empty($prefix) ? $key : "$prefix.$key";

        return [$key, $compare, $value];
    }

    public function wpdb()
    {
        global $wpdb;
        return $wpdb;
    }

    public function query_one()
    {
        return $this->query(true);
    }

    public function query_multi(): array
    {
        return (array)$this->query(false, true) ?: [];
    }

    public function select($columns, $table = ''): Query
    {
        return $this->action('SELECT')->tables($table)->columns($columns, $table);
    }

    /**
     * $columns = [ 'field1', 'field2' ];
     * $columns = [ 'table1'=> 'field1', 'table1' => 'field2' ];
     * $columns = 'field'
     */
    public function columns($columns, $table = '', $append = false): Query
    {
        if (!is_array($columns)) {
            $columns = [$columns];
        }

        $reference_ALL = $this->get_table_alias($table);

        $_columns = [];

        foreach ($columns as $reference => $field) {

            if ($append) {
                foreach ($this->columns as $item) {
                    if ($item['name'] === $field) {
                        continue 2;
                    }
                }
            }

            $field = trim($field);

            if ($field != '*') {
                $as_table = $reference_ALL ?: (is_numeric($reference) ? "T$reference" : $this->get_table_alias($reference));
            }
            else {
                $as_table = '';
            }

            preg_match("#^(([^(]+)\((.+)\)([^()]+)?|^(^\S+)\s+(.*))(\s+AS\s+(\w+))?$#iU", $field, $matches);

            $_columns[] = [
                'table'     => $this->get_table($as_table),
                'aggregate' => trim((isset($matches[5]) && $matches[5]) ? $matches[5] : ((isset($matches[2]) && $matches[2]) ? $matches[2] : ''), ' '),
                'name'      => trim((isset($matches[6]) && $matches[6]) ? $matches[6] : ((isset($matches[3]) && $matches[3]) ? $matches[3] : $field), ' '),
                'extra'     => $matches[4] ?? '',
                'as'        => $matches[8] ?? '',
            ];
        }

        $this->columns = $append ? array_merge($this->columns, $_columns) : $_columns;

        return $this;
    }

    private function get_table($reference)
    {
        return $this->references_map[$reference] ?? $reference;
    }

    /**
     * $from = [ 'table1', 'table2' ]
     * $from = ['T1' => 'table1', 'T2' => 'table2' ]
     * $from = 'table'
     */
    public function tables($tables, array $joining_tables = []): Query
    {
        if (!empty($tables)) {

            if (!is_array($tables)) {
                $tables = [$tables];
            }

            foreach ($tables as $key => $table) {

                if (str_starts_with($table, 'SELECT')) {
                    $table = "($table)";
                    $this->use_reference = true;
                }

                $this->tables[] = $table;

                $this->set_table_alias([$table => $key]);
            }

            $this->merge_tables($joining_tables);
        }

        return $this;
    }

    public function merge_tables(array $joining_tables = []): Query
    {
        if (!empty($joining_tables)) {

            // must be an array of arrays
            if (!isset($joining_tables[0])) {
                $joining_tables = [$joining_tables];
            }

            foreach ($joining_tables as $joining_table) {
                $this->joining_tables[] = $joining_table;
            }
        }

        return $this;
    }

    public function action($action): Query
    {
        $this->action = strtoupper($action);

        return $this;
    }

    public function export(): string
    {
        return $this->sql;
    }

    /**
     * 'field'
     * [table2 => 'field']
     */
    public function orderby($field, $order = 'ASC', $table = ''): Query
    {
        if (!empty($field)) {
            if (is_array($field)) {
                $table = key($field);
                $field = $field[$table];
            }

            $this->orderby[] = [$table => $field . ' ' . $this->parse_order($order)];
        }

        return $this;
    }

    private function parse_order(string $order): string
    {
        if (preg_match("#^\s*(asc|desc)\s*$#i", $order)) {
            return strtoupper($order);
        }

        return '';
    }

    public function limit($limit): Query
    {
        if (is_numeric($limit)) {
            $this->limit = absint($limit);
        }

        return $this;
    }

    public function offset($offset): Query
    {
        if (is_numeric($offset)) {
            $this->offset = absint($offset);
        }

        return $this;
    }

    /**
     * [table => condition],
     * condition
     */
    public function having($condition): Query
    {
        if (!empty($condition)) {
            $this->having[] = is_array($condition) ? $condition : [$condition];
        }

        return $this;
    }

    /**
     * [table => field]
     * [field1, field2]
     */
    public function groupby($groupby): Query
    {
        if (empty($groupby)) {
            return $this;
        }

        if (!is_array($groupby)) {
            $groupby = [$groupby];
        }

        $this->groupby[] = $groupby;

        return $this;
    }

    /**
     * join( tb1, tb2, [id => user_id]... )
     * join( tb1, join sql, []... )
     */
    public function join(string $table1, string $table2, array $on = [], $type = 'INNER JOIN'): Query
    {
        if (empty($on)) {
            $this->joins[$table1] = $table2;
        }
        else {
            // get reference of tab 1
            $_table1 = $this->get_table_alias($table1, true);

            $need_ref = !$this->has_reference($table2);

            // get reference of tab 2
            $_table2 = $this->get_table_alias($table2, true, true);

            if ($need_ref) {
                $table2 = rtrim("$table2 AS $_table2", '.');
            }

            $tab1_key = key($on);
            $tab2_key = $on[$tab1_key];
            $this->joins[$table1] = "$type $table2 ON {$_table1}{$tab1_key} = {$_table2}{$tab2_key}";
        }

        return $this;
    }

    private function has_reference($table): bool
    {
        return (!$this->use_reference or isset($this->table_aliases[$table]));
    }

    public function debug(): Query
    {
        $this->debug = true;
        return $this;
    }

    public function delete($conditions = [], $table = '', $relation = 'AND'): Query
    {
        return $this->action('DELETE')->tables($table)->where($conditions, $relation, $table);
    }

    /**
     * $where = ['table1' => 'constraints', 'table2' => ['key' => '', 'value' => '', 'compare' => '']]
     * $where = ''
     * $where = [key => value] only if not using reference
     */
    public function where($wheres, $relation = 'AND', $table = '', $unquoted = false): Query
    {
        if (!empty($wheres)) {

            if (is_array($wheres)) {

                if ($this->use_reference) {
                    $_wheres = $this->parse_fields($wheres, true, $this->get_table_alias($table), $unquoted);
                }
                else {
                    $_wheres = $this->parse_fields($wheres, true, '', $unquoted);
                }

                $wheres = implode(
                    " " . strtoupper($relation) . " ",
                    $_wheres
                );
            }

            $this->where[] = "($wheres)";
        }

        return $this;
    }

    /**
     * works only over last where set
     */
    public function placeholders($placeholders): Query
    {
        global $wpdb;

        $lastIndex = count($this->where) - 1;

        $this->where[$lastIndex] = $wpdb->prepare($this->where[$lastIndex], $placeholders);

        return $this;
    }

    public function recompile(): Query
    {
        $this->sql = '';
        return $this;
    }

    public function insert_multi(array $columns, array $values = []): Query
    {
        $this->use_reference = false;
        $this->action('insert');

        foreach ($columns as $column) {
            if (!in_array($column, $this->columns)) {
                $this->columns[] = $column;
            }
        }

        $this->values[] = array_map('esc_sql', array_map('maybe_serialize', $values));

        return $this;
    }

    public function update(array $items, array $wheres): Query
    {
        $this->where($wheres);
        foreach ($items as $column => $value) {
            $this->insert([$column => $value]);
        }
        return $this;
    }

    /**
     * [[key => value]]
     * [key => value]
     */
    public function insert(array $fields, $quoted = true): Query
    {
        $this->use_reference = false;
        $this->action('insert');

        $elements = count($this->values);

        $lastEmpty = $elements - 1;

        foreach ($fields as $column => $value) {

            if (!in_array($column, $this->columns)) {
                $this->columns($column, '', true);
            }

            // fixes for multi insertion not sequential
            while (!isset($this->values[$lastEmpty][$column]) and $lastEmpty >= 0) {
                $lastEmpty--;
            }
            $lastEmpty++;

            if (!isset($this->values[$lastEmpty])) {
                $this->values[$lastEmpty] = [];
            }

            $value = maybe_serialize($value);

            if ($quoted) {
                $value = "'$value'";
            }

            $this->values[$lastEmpty][$column] = $value;
        }

        return $this;
    }

    public function begin_transaction(): Query
    {
        $this->wpdb()->query('START TRANSACTION');
        return $this;
    }

    public function commit(): Query
    {
        $this->wpdb()->query('COMMIT');
        return $this;
    }

    public function rollback(): Query
    {
        $this->wpdb()->query('ROLLBACK');
        return $this;
    }

    public function where_unquoted($wheres, $relation = 'AND', $table = ''): Query
    {
        if (!empty($wheres)) {

            $wheres = implode(
                " " . strtoupper($relation) . " ",
                $this->parse_fields($wheres, true, $this->use_reference ? $this->get_table_alias($table) : '', true)
            );

            $this->where[] = "($wheres)";
        }

        return $this;
    }
}

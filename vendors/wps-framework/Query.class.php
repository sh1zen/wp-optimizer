<?php
/**
 * @author    sh1zen
 * @copyright Copyright (C) 2025.
 * @license   http://www.gnu.org/licenses/gpl.html GNU/GPL
 */

namespace WPS\core;

class Query
{
    private $output = OBJECT;

    private string $sql = '';

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

    private array $merging_tables = [];

    private function invalidate_sql(): void
    {
        $this->sql = '';
    }

    private function __construct($output, $useReference)
    {
        $this->use_reference = $useReference;

        $this->output($output);
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

    public static function withSQL(string $sql): Query
    {
        $q = new self(OBJECT, false);
        $q->sql = trim($sql);
        return $q;
    }

    public static function recursive($table, $s_id, $p_alias, $start_id, $parent_stop = 0)
    {
        global $wpdb;

        // Creiamo l'istanza della query
        $sq = Query::getInstance()
            ->select([$s_id, $p_alias], $table)
            ->where([$s_id => $start_id])->compile();

        $mq = Query::getInstance(OBJECT, true)
            ->select(["$s_id", "$p_alias"], $table)   // colonne e tabella principale
            ->join(
                $table,              // tabella base (tt)
                't_hierarchy',       // tabella da joinare (th)
                [$s_id => $p_alias], // condizione: tt.id = th.alias
                'INNER JOIN'         // tipo di join
            )
            ->compile();


        $rq = Query::getInstance()
            ->select([$s_id], "t_hierarchy")
            ->where([$p_alias => $parent_stop])->compile();

        $res = "WITH RECURSIVE t_hierarchy AS ($sq UNION ALL $mq) $rq;";

        return $wpdb->get_var($res);
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

        if ($this->sql === '') {
            return false;
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
        global $wpdb;

        $columns = $tables = $values = [];
        $bindings = [];
        $where = '';

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

        $merge_where = $this->parse_merging_tables();

        $where_clause = self::join_clauses(array_merge($this->where, $merge_where), ' AND ');
        $where_bindings = self::clause_bindings($where_clause);
        $where = self::clause_sql($where_clause);

        if ($where !== '') {

            $where = "WHERE 1=1 AND $where";

            if ($this->action === 'INSERT') {
                $this->action = 'UPDATE';
            }
        }

        if ($this->action === 'INSERT') {
            $values_clause = $this->build_insert_values_clause($columns);
            $values = self::clause_sql($values_clause);
            $bindings = array_merge($bindings, self::clause_bindings($values_clause));
        }
        elseif ($this->action === 'UPDATE') {
            $values_clause = $this->build_update_values_clause();
            $values = self::clause_sql($values_clause);
            $bindings = array_merge($bindings, self::clause_bindings($values_clause));
        }

        $columns_list = implode(', ', $columns) ?: '*';

        if (in_array($this->action, ['SELECT', 'DELETE', 'TRUNCATE', 'INSERT', 'UPDATE'], true) && $tables === '') {
            $sql = '';
            if (!$debug) {
                $this->sql = $sql;
            }
            return $sql;
        }

        if ($this->action === 'INSERT' && ($columns_list === '*' || $values === '')) {
            $sql = '';
            if (!$debug) {
                $this->sql = $sql;
            }
            return $sql;
        }

        if ($this->action === 'UPDATE' && $values === '') {
            $sql = '';
            if (!$debug) {
                $this->sql = $sql;
            }
            return $sql;
        }

        switch ($this->action) {
            case 'SELECT':
                $sql = "SELECT $columns_list FROM $tables $where $groupby $having $orderby $limit $offset";
                $bindings = array_merge($bindings, $where_bindings);
                break;

            case 'DELETE':
                $sql = "DELETE FROM $tables $where";
                $bindings = array_merge($bindings, $where_bindings);
                break;

            case 'TRUNCATE':
                $sql = "TRUNCATE $tables";
                break;

            case 'INSERT':
                $sql = "INSERT INTO $tables ($columns_list) VALUES ($values)";
                break;

            case 'UPDATE':
                $sql = "UPDATE $tables SET {$values} $where";
                $bindings = array_merge($bindings, $where_bindings);
                break;

            default:
                $sql = '';
                break;
        }

        $sql = trim($sql, ' ');

        if ($sql !== '' && !empty($bindings)) {
            $sql = $wpdb->prepare($sql, $bindings);
        }

        if (!$debug) {
            $this->sql = $sql;
        }

        return $sql;
    }

    private function build_insert_values_clause(array $columns): array
    {
        $rows = [];
        $bindings = [];

        if (empty($columns) || empty($this->values)) {
            return self::new_clause('');
        }

        foreach ($this->values as $tuple) {
            $ordered = wps_array_sort($tuple, $columns, self::new_clause(''));
            $row_sql = implode(', ', array_map([self::class, 'clause_sql'], $ordered));

            if ($row_sql === '') {
                continue;
            }

            $rows[] = $row_sql;

            foreach ($ordered as $clause) {
                $bindings = array_merge($bindings, self::clause_bindings($clause));
            }
        }

        return self::new_clause(implode('), (', $rows), $bindings);
    }

    private function build_update_values_clause(): array
    {
        $assignments = [];
        $bindings = [];
        $values = $this->values[0] ?? [];

        foreach ($this->columns as $column) {
            $column_name = trim((string)(is_array($column) ? ($column['name'] ?? '') : $column));

            if ($column_name === '' || !array_key_exists($column_name, $values)) {
                continue;
            }

            $clause = $values[$column_name];
            $assignments[] = $column_name . ' = ' . self::clause_sql($clause);
            $bindings = array_merge($bindings, self::clause_bindings($clause));
        }

        return self::new_clause(implode(', ', $assignments), $bindings);
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
                if (!is_scalar($state) || trim((string)$state) === '') {
                    continue;
                }

                if ($this->use_reference) {
                    $maybe_table = $this->get_table_alias(is_numeric($maybe_table) ? '' : $maybe_table);
                    $states[] = trim("$maybe_table.$state", '.');
                }
                else {
                    $states[] = $state;
                }
            }
        }

        if (empty($states)) {
            return '';
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
        $this->invalidate_sql();

        foreach ($tables as $table => $reference) {
            $table = trim((string)$table);

            if ($table === '') {
                continue;
            }

            $reference = is_numeric($reference) ? "T" . count($this->table_aliases) : $reference;

            $this->table_aliases[$table] = $reference;
            $this->references_map[$reference] = $table;
            $this->reference_count[$table] = 0;
        }

        return $this;
    }

    private function parse_merging_tables(): array
    {
        $clauses = [];

        foreach ($this->merging_tables as $conditions) {
            if (!is_array($conditions) || count($conditions) < 2) {
                continue;
            }

            $compare = $conditions['compare'] ?? '=';
            unset($conditions['compare']);

            $tab1 = wps_array_key_next($conditions);
            if ($tab1 === null || !isset($conditions[$tab1])) {
                continue;
            }
            $field1 = $conditions[$tab1];
            $tab1_reference = $this->get_table_alias($tab1);

            $tab2 = wps_array_key_next($conditions);
            if ($tab2 === null || !isset($conditions[$tab2])) {
                continue;
            }
            $field2 = $conditions[$tab2];
            $tab2_reference = $this->get_table_alias($tab2);

            if ($this->reference_count[$tab1] and $this->reference_count[$tab2]) {
                $clauses[] = self::new_clause("$tab1_reference.$field1 $compare $tab2_reference.$field2", [], false, true);
            }
        }

        return $clauses;
    }

    private static function new_clause(string $sql, array $bindings = [], bool $prepared = false, bool $raw = false): array
    {
        return [
            'sql' => $sql,
            'bindings' => array_values($bindings),
            'prepared' => $prepared,
            'raw' => $raw,
        ];
    }

    private static function clause_sql($clause): string
    {
        return is_array($clause) ? (string)($clause['sql'] ?? '') : (string)$clause;
    }

    private static function clause_bindings($clause): array
    {
        return is_array($clause) ? array_values((array)($clause['bindings'] ?? [])) : [];
    }

    private static function clause_is_raw($clause): bool
    {
        return is_array($clause) ? !empty($clause['raw']) : true;
    }

    private static function join_clauses(array $clauses, string $joiner): array
    {
        $sql = [];
        $bindings = [];

        foreach ($clauses as $clause) {
            $fragment = self::clause_sql($clause);

            if ($fragment === '') {
                continue;
            }

            $sql[] = $fragment;
            $bindings = array_merge($bindings, self::clause_bindings($clause));
        }

        return self::new_clause(implode($joiner, $sql), $bindings);
    }

    private static function render_clause($clause): string
    {
        return self::prepare_sql(self::clause_sql($clause), self::clause_bindings($clause));
    }

    private static function prepare_sql(string $sql, array $bindings): string
    {
        global $wpdb;

        if ($sql === '' || empty($bindings)) {
            return $sql;
        }

        return $wpdb->prepare($sql, $bindings);
    }

    private static function placeholder_for_value($value): array
    {
        if (is_bool($value)) {
            $value = $value ? 1 : 0;
        }
        elseif (is_null($value)) {
            $value = '';
        }

        if (is_int($value)) {
            return ['%d', $value];
        }

        if (is_float($value)) {
            return ['%f', $value];
        }

        return ['%s', $value];
    }

    private static function raw_scalar($value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_null($value)) {
            return '';
        }

        return (string)$value;
    }

    private static function build_list_clause(array $value, bool $unquoted): ?array
    {
        $value = array_values(array_filter($value, static function ($item) {
            return !is_null($item) && $item !== '';
        }));

        if (empty($value)) {
            return null;
        }

        if ($unquoted) {
            return self::new_clause('(' . implode(',', array_map([self::class, 'raw_scalar'], $value)) . ')', [], false, true);
        }

        $placeholders = [];
        $bindings = [];

        foreach ($value as $item) {
            list($placeholder, $binding) = self::placeholder_for_value($item);
            $placeholders[] = $placeholder;
            $bindings[] = $binding;
        }

        return self::new_clause('(' . implode(',', $placeholders) . ')', $bindings);
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
        return array_map(
            static function ($clause) {
                return self::render_clause($clause);
            },
            $this->build_fields($fields, $escape, $prefix, $unquoted)
        );
    }

    private function build_fields($fields, $escape = true, $prefix = '', $unquoted = false): array
    {
        $parsed = [];

        if (!is_array($fields)) {
            return $parsed;
        }

        // convert one level array into standard two level ones
        if (isset($fields['compare'])) {
            $fields = [$fields];
        }

        foreach ($fields as $maybe_table => $field) {

            if (is_null($field)) {
                continue;
            }

            $compare = is_array($field) ? ($field['compare'] ?? false) : false;
            $key = $maybe_table;
            $iter_unquoted = $unquoted;

            if (is_array($field)) {

                if ($prx = $this->get_table_alias($maybe_table)) {
                    // handles table_name => [...]
                    $parsed = array_merge($parsed, $this->build_fields($field, true, $prx, $unquoted));
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

            list($key, $compare, $value_clause) = self::parse_key_compare_field($key, $compare, $field, $escape, $iter_unquoted, $prefix);

            if (is_null($value_clause)) {
                continue;
            }

            $parsed[] = self::new_clause("$key $compare " . self::clause_sql($value_clause), self::clause_bindings($value_clause), false, $iter_unquoted);
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

        $value_clause = null;

        if (str_contains($compare, 'BETWEEN')) {
            $range = array_values((array)$value);
            $start = $range[0] ?? '';
            $end = $range[1] ?? '';

            if ($unquoted) {
                $value_clause = self::new_clause(self::raw_scalar($start) . ' AND ' . self::raw_scalar($end), [], false, true);
            }
            else {
                list($start_placeholder, $start_binding) = self::placeholder_for_value($start);
                list($end_placeholder, $end_binding) = self::placeholder_for_value($end);
                $value_clause = self::new_clause("$start_placeholder AND $end_placeholder", [$start_binding, $end_binding]);
            }
        }
        elseif (is_array($value)) {
            $value_clause = self::build_list_clause($value, $unquoted);

            if (is_null($value_clause)) {
                return [$key, $compare, null];
            }
        }
        elseif (is_bool($value)) {
            if ($unquoted) {
                $value_clause = self::new_clause((string)($value ? 1 : 0), [], false, true);
            }
            else {
                $value_clause = self::new_clause('%d', [$value ? 1 : 0]);
            }
        }
        elseif (is_string($value) && $unquoted && 1 === preg_match('#^[(\s]*SELECT\s+#i', $value)) {
            $value = trim($value);

            if (!str_starts_with($value, '(')) {
                $value = "($value)";
            }

            $value_clause = self::new_clause($value, [], false, true);
        }
        else {
            $is_like_compare = str_contains($compare, 'LIKE');

            if ($unquoted) {
                $raw_value = $is_like_compare ? "%" . trim(self::raw_scalar($value), "' %") . "%" : self::raw_scalar($value);
                $value_clause = self::new_clause($raw_value, [], false, true);
            }
            else {
                if ($is_like_compare) {
                    $string_value = trim((string)$value, "' %");
                    $value = $escape ? "%" . $wpdb->esc_like($string_value) . "%" : "%" . $string_value . "%";
                }

                list($placeholder, $binding) = self::placeholder_for_value($value);
                $value_clause = self::new_clause($placeholder, [$binding]);
            }
        }

        $key = empty($prefix) ? $key : "$prefix.$key";

        return [$key, $compare, $value_clause];
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

    public function get_row()
    {
        return $this->query(true);
    }

    public function get_results()
    {
        return $this->query(false);
    }

    public function get_var()
    {
        return $this->query(true);
    }

    public function get_col(): array
    {
        return (array)$this->query(false, true) ?: [];
    }

    public function first()
    {
        return $this->limit(1)->query(true);
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
        $this->invalidate_sql();

        if (!is_array($columns)) {
            $columns = [$columns];
        }

        $reference_ALL = $this->get_table_alias($table);

        $_columns = [];

        foreach ($columns as $reference => $field) {
            if (!is_scalar($field)) {
                continue;
            }

            if ($append) {
                foreach ($this->columns as $item) {
                    if ($item['name'] === $field) {
                        continue 2;
                    }
                }
            }

            $field = trim((string)$field);

            if ($field === '') {
                continue;
            }

            if ($field != '*') {
                $as_table = $reference_ALL ?: (is_numeric($reference) ? "T$reference" : $this->get_table_alias($reference));
            }
            else {
                $as_table = '';
            }

            // tablename/Function(column)/ (as count)?
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
    public function tables($tables, array ...$joining_tables): Query
    {
        $this->invalidate_sql();

        if (!empty($tables)) {

            if (!is_array($tables)) {
                $tables = [$tables];
            }

            foreach ($tables as $key => $table) {
                if (!is_scalar($table)) {
                    continue;
                }

                $table = trim((string)$table);

                if ($table === '') {
                    continue;
                }

                if (str_starts_with($table, 'SELECT')) {
                    $table = "($table)";
                    $this->use_reference = true;
                }

                $this->tables[] = $table;

                $this->set_table_alias([$table => $key]);
            }

            $this->merge_tables(...$joining_tables);
        }

        return $this;
    }

    public function merge_tables(array ...$joining_tables): Query
    {
        $this->invalidate_sql();

        if (!empty($joining_tables)) {

            foreach ($joining_tables as $joining_table) {
                $this->merging_tables[] = $joining_table;
            }
        }

        return $this;
    }

    public function action($action): Query
    {
        $this->invalidate_sql();
        $this->action = strtoupper($action);

        return $this;
    }

    public function export(): string
    {
        if ($this->sql === '') {
            $this->compile();
        }

        return $this->sql;
    }

    /**
     * 'field'
     * [table2 => 'field']
     */
    public function orderby($field, $order = 'ASC', $table = ''): Query
    {
        $this->invalidate_sql();

        if (!empty($field)) {
            if (is_array($field)) {
                $table = key($field);
                $field = $field[$table];
            }

            if (!is_scalar($field)) {
                return $this;
            }

            $field = trim((string)$field);

            if ($field === '') {
                return $this;
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

    private function qualify_field_name(string $field, string $table = ''): string
    {
        if ($field === '' || preg_match('#[.\s()]#', $field)) {
            return $field;
        }

        if ($this->use_reference && $table) {
            $prefix = $this->get_table_alias($table);

            if ($prefix) {
                return "$prefix.$field";
            }
        }

        return $field;
    }

    public function latest($field = 'id', $table = ''): Query
    {
        return $this->orderby($field, 'DESC', $table);
    }

    public function oldest($field = 'id', $table = ''): Query
    {
        return $this->orderby($field, 'ASC', $table);
    }

    public function limit($limit): Query
    {
        $this->invalidate_sql();
        if (is_numeric($limit)) {
            $this->limit = absint($limit);
        }

        return $this;
    }

    public function offset($offset): Query
    {
        $this->invalidate_sql();
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
        $this->invalidate_sql();
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
        $this->invalidate_sql();
        if (empty($groupby)) {
            return $this;
        }

        if (!is_array($groupby)) {
            $groupby = [$groupby];
        }

        $groupby = array_values(array_filter($groupby, static function ($field) {
            return is_scalar($field) && trim((string)$field) !== '';
        }));

        if (empty($groupby)) {
            return $this;
        }

        $this->groupby[] = $groupby;

        return $this;
    }

    public function join_sql(string $base_table, string $join_sql): Query
    {
        $this->invalidate_sql();
        $this->joins[$base_table] = $join_sql;
        return $this;
    }

    /**
     * join( tb1, tb2, [id => user_id]... )
     * join( tb1, join sql, []... )
     */
    public function join(string $base_table, string $joining_table, array $on, $type = 'INNER JOIN'): Query
    {
        $this->invalidate_sql();

        if ($base_table === '' || $joining_table === '' || empty($on)) {
            return $this;
        }

        // get reference of tab 1
        $_table1 = $this->get_table_alias($base_table, true);

        $need_ref = !$this->has_reference($joining_table);

        // get reference of tab 2
        $_table2 = $this->get_table_alias($joining_table, true, true);

        if ($need_ref) {
            $joining_table = rtrim("$joining_table AS $_table2", '.');
        }

        $tab1_key = key($on);

        if ($tab1_key === null || !isset($on[$tab1_key])) {
            return $this;
        }

        $tab2_key = $on[$tab1_key];

        $join_statement = " $type $joining_table ON {$_table1}{$tab1_key} = {$_table2}{$tab2_key} ";

        $this->joins[$base_table] = trim(($this->joins[$base_table] ?? '') . $join_statement, ' ');

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

    public function where_compare(string $key, $value, string $compare = '=', string $table = ''): Query
    {
        return $this->where([
            [
                'key' => $key,
                'value' => $value,
                'compare' => $compare,
            ]
        ], 'AND', $table);
    }

    public function where_like(string $key, $value, string $table = ''): Query
    {
        return $this->where_compare($key, $value, 'LIKE', $table);
    }

    public function where_not_like(string $key, $value, string $table = ''): Query
    {
        return $this->where_compare($key, $value, 'NOT LIKE', $table);
    }

    public function where_in(string $key, array $value, string $table = ''): Query
    {
        if (empty($value)) {
            return $this->where('0=1');
        }

        return $this->where_compare($key, $value, 'IN', $table);
    }

    public function where_not_in(string $key, array $value, string $table = ''): Query
    {
        if (empty($value)) {
            return $this;
        }

        return $this->where_compare($key, $value, 'NOT IN', $table);
    }

    public function where_between(string $key, array $value, string $table = ''): Query
    {
        if (count($value) < 2) {
            return $this;
        }

        return $this->where_compare($key, $value, 'BETWEEN', $table);
    }

    public function where_not_between(string $key, array $value, string $table = ''): Query
    {
        if (count($value) < 2) {
            return $this;
        }

        return $this->where_compare($key, $value, 'NOT BETWEEN', $table);
    }

    public function where_null(string $key, string $table = ''): Query
    {
        return $this->where($this->qualify_field_name($key, $table) . ' IS NULL');
    }

    public function where_not_null(string $key, string $table = ''): Query
    {
        return $this->where($this->qualify_field_name($key, $table) . ' IS NOT NULL');
    }

    /**
     * $where = ['table1' => 'constraints', 'table2' => ['key' => '', 'value' => '', 'compare' => '']]
     * $where = ''
     * $where = [key => value] only if not using reference
     */
    public function where($wheres, $relation = 'AND', $table = '', $unquoted = false): Query
    {
        $this->invalidate_sql();

        if (!empty($wheres)) {

            if (is_array($wheres)) {

                if ($this->use_reference) {
                    $_wheres = $this->build_fields($wheres, true, $this->get_table_alias($table), $unquoted);
                }
                else {
                    $_wheres = $this->build_fields($wheres, true, '', $unquoted);
                }

                $wheres = self::join_clauses($_wheres, " " . strtoupper($relation) . " ");

                if (self::clause_sql($wheres) === '') {
                    return $this;
                }

                $wheres = self::new_clause('(' . self::clause_sql($wheres) . ')', self::clause_bindings($wheres));
            }
            else {
                $wheres = self::new_clause("($wheres)", [], false, true);
            }

            $this->where[] = $wheres;
        }

        return $this;
    }

    /**
     * works only over last where set
     */
    public function placeholders($placeholders): Query
    {
        global $wpdb;

        $this->invalidate_sql();

        $lastIndex = count($this->where) - 1;

        if ($lastIndex < 0) {
            return $this;
        }

        $clause = $this->where[$lastIndex];

        if (!self::clause_is_raw($clause) || !empty(self::clause_bindings($clause))) {
            return $this;
        }

        $this->where[$lastIndex] = self::new_clause($wpdb->prepare(self::clause_sql($clause), $placeholders), [], true, true);

        return $this;
    }

    public function recompile(): Query
    {
        $this->invalidate_sql();
        return $this;
    }

    private function has_column(string $column): bool
    {
        foreach ($this->columns as $item) {
            $name = is_array($item) ? (string)($item['name'] ?? '') : (string)$item;

            if ($name === $column) {
                return true;
            }
        }

        return false;
    }

    private static function build_insert_value_clause($value, bool $quoted = true): array
    {
        $value = maybe_serialize($value);

        if (!$quoted) {
            return self::new_clause((string)$value, [], false, true);
        }

        list($placeholder, $binding) = self::placeholder_for_value($value);

        return self::new_clause($placeholder, [$binding]);
    }

    public function insert_multi(array $columns, array $values = []): Query
    {
        $this->invalidate_sql();
        $this->use_reference = false;
        $this->action('insert');

        if (empty($columns)) {
            return $this;
        }

        $row = [];
        $indexed_values = array_values($values);

        foreach (array_values($columns) as $index => $column) {
            $column = trim((string)$column);

            if ($column === '') {
                continue;
            }

            if (!$this->has_column($column)) {
                $this->columns($column, '', true);
            }

            $row[$column] = self::build_insert_value_clause($values[$column] ?? ($indexed_values[$index] ?? ''), true);
        }

        if (empty($row)) {
            return $this;
        }

        $this->values[] = $row;

        return $this;
    }

    public function update(array $items, array $wheres): Query
    {
        $this->invalidate_sql();
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
        $this->invalidate_sql();
        $this->use_reference = false;
        $this->action('insert');

        if (empty($fields)) {
            return $this;
        }

        $elements = count($this->values);

        $lastEmpty = $elements - 1;

        foreach ($fields as $column => $value) {
            $column = trim((string)$column);

            if ($column === '') {
                continue;
            }

            if (!$this->has_column($column)) {
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

            $this->values[$lastEmpty][$column] = self::build_insert_value_clause($value, $quoted);
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
        $this->invalidate_sql();

        if (!empty($wheres)) {

            if (is_array($wheres)) {
                $wheres = self::join_clauses(
                    $this->build_fields($wheres, true, $this->use_reference ? $this->get_table_alias($table) : '', true),
                    " " . strtoupper($relation) . " "
                );

                if (self::clause_sql($wheres) === '') {
                    return $this;
                }

                $wheres = self::new_clause('(' . self::clause_sql($wheres) . ')', self::clause_bindings($wheres), false, true);
            }
            else {
                $wheres = self::new_clause("($wheres)", [], false, true);
            }

            $this->where[] = $wheres;
        }

        return $this;
    }
}

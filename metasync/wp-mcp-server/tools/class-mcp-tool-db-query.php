<?php
/**
 * MCP Tools for Read-Only Database Inspection
 *
 * Provides safe, read-only database access for AI-assisted analysis.
 * Only SELECT, SHOW, DESCRIBE, and EXPLAIN statements are permitted.
 * All tools require manage_options capability (admin).
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(dirname(__FILE__)) . 'class-mcp-tool-base.php';

/**
 * DB Tables Tool
 *
 * Lists all database tables with row counts and size estimates.
 */
class MCP_Tool_DB_Tables extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_db_tables';
    }

    public function get_description() {
        return 'List all database tables with row counts and size (MB). Use this to understand the database structure before running targeted queries.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'prefix_only' => [
                    'type'        => 'boolean',
                    'description' => 'If true (default), only show tables matching the WordPress table prefix',
                ],
            ],
        ];
    }

    public function execute($params) {
        $this->require_capability('manage_options');

        global $wpdb;

        $prefix_only = !isset($params['prefix_only']) || $params['prefix_only'] !== false;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT
                    table_name        AS `table`,
                    table_rows        AS `rows_approx`,
                    ROUND((data_length + index_length) / 1024 / 1024, 4) AS `size_mb`,
                    data_length       AS `data_bytes`,
                    index_length      AS `index_bytes`,
                    create_time       AS `created`,
                    update_time       AS `last_updated`
                FROM information_schema.tables
                WHERE table_schema = %s
                ORDER BY (data_length + index_length) DESC',
                DB_NAME
            ),
            ARRAY_A
        );

        if ($prefix_only) {
            $prefix = $wpdb->prefix;
            $rows   = array_values(array_filter($rows, function($r) use ($prefix) {
                return strpos($r['table'], $prefix) === 0;
            }));
        }

        // Cast numeric columns
        foreach ($rows as &$r) {
            $r['rows_approx'] = (int) $r['rows_approx'];
            $r['size_mb']     = (float) $r['size_mb'];
        }
        unset($r);

        return $this->success([
            'total'  => count($rows),
            'tables' => $rows,
        ]);
    }
}

/**
 * DB Describe Tool
 *
 * Returns the column definitions for a specific table.
 */
class MCP_Tool_DB_Describe extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_db_describe';
    }

    public function get_description() {
        return 'Describe a database table: column names, types, nullability, keys, and defaults. Use before writing a SELECT query to know the exact column names.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'table' => [
                    'type'        => 'string',
                    'description' => 'Table name (with or without prefix, e.g. "posts" or "wp_posts")',
                ],
            ],
            'required'   => ['table'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        global $wpdb;

        $table = $this->resolve_table_name(sanitize_text_field($params['table']));
        $this->assert_table_exists($table);

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name validated above
        $columns = $wpdb->get_results("DESCRIBE `{$table}`", ARRAY_A);

        $indexes = $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A);
        $index_map = [];
        foreach ($indexes as $idx) {
            $col = $idx['Column_name'];
            $index_map[$col][] = [
                'key_name'   => $idx['Key_name'],
                'non_unique'  => (bool) $idx['Non_unique'],
                'index_type'  => $idx['Index_type'],
            ];
        }

        $result = [];
        foreach ($columns as $col) {
            $result[] = [
                'column'   => $col['Field'],
                'type'     => $col['Type'],
                'null'     => $col['Null'] === 'YES',
                'key'      => $col['Key'],
                'default'  => $col['Default'],
                'extra'    => $col['Extra'],
                'indexes'  => $index_map[$col['Field']] ?? [],
            ];
        }

        return $this->success([
            'table'   => $table,
            'columns' => $result,
        ]);
    }

    private function resolve_table_name($table) {
        global $wpdb;
        // If already prefixed, use as-is; otherwise prepend prefix
        if (strpos($table, $wpdb->prefix) === 0) {
            return $table;
        }
        return $wpdb->prefix . $table;
    }

    private function assert_table_exists($table) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
            DB_NAME,
            $table
        ));
        if (!$exists) {
            throw new Exception("Table '{$table}' does not exist");
        }
    }
}

/**
 * DB Select Tool
 *
 * Executes a read-only SQL query (SELECT / SHOW / DESCRIBE / EXPLAIN only).
 * Blocks all write operations. Results capped at 500 rows.
 */
class MCP_Tool_DB_Select extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_db_select';
    }

    public function get_description() {
        return 'Run a read-only SQL query against the WordPress database. Only SELECT, SHOW, DESCRIBE, and EXPLAIN are allowed — all write operations are blocked. Results are capped at 500 rows. Use wordpress_db_tables and wordpress_db_describe first to understand the schema.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'sql' => [
                    'type'        => 'string',
                    'description' => 'SQL query to execute. Must start with SELECT, SHOW, DESCRIBE, or EXPLAIN.',
                ],
                'limit' => [
                    'type'        => 'integer',
                    'description' => 'Max rows to return (1–500, default 100)',
                    'minimum'     => 1,
                    'maximum'     => 500,
                ],
            ],
            'required'   => ['sql'],
        ];
    }

    // Keywords that must never appear at statement start
    private $blocked_first_keywords = [
        'INSERT', 'UPDATE', 'DELETE', 'REPLACE', 'DROP', 'CREATE',
        'ALTER', 'TRUNCATE', 'RENAME', 'GRANT', 'REVOKE', 'CALL',
        'EXEC', 'EXECUTE', 'LOAD', 'IMPORT',
    ];

    // Keywords that should never appear anywhere in the query
    private $blocked_anywhere = [
        'INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE',
        'SLEEP(', 'BENCHMARK(',
    ];

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        global $wpdb;

        $sql   = trim($params['sql']);
        $limit = isset($params['limit']) ? intval($params['limit']) : 100;
        $limit = max(1, min(500, $limit));

        // ── Security checks ──────────────────────────────────────────

        // 1. Strip leading SQL block/line comments to find the real first keyword
        $stripped = preg_replace('/\A(\s*(\/\*.*?\*\/\s*|--[^\n]*\n?\s*))+/s', '', $sql);
        $first_keyword = strtoupper(preg_replace('/[\s(;].*/s', '', $stripped));

        $allowed_starts = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];
        if (!in_array($first_keyword, $allowed_starts, true)) {
            throw new Exception(
                "Query blocked: only SELECT, SHOW, DESCRIBE, and EXPLAIN are allowed. " .
                "Got: '{$first_keyword}'"
            );
        }

        // 2. Block write-operation keywords at statement start (covers stacked queries)
        foreach ($this->blocked_first_keywords as $kw) {
            if (preg_match('/;\s*' . preg_quote($kw, '/') . '\s/i', $sql)) {
                throw new Exception("Query blocked: stacked write statement detected ({$kw})");
            }
        }

        // 3. Block dangerous function / file-access patterns
        $sql_upper = strtoupper($sql);
        foreach ($this->blocked_anywhere as $pattern) {
            if (strpos($sql_upper, $pattern) !== false) {
                throw new Exception("Query blocked: forbidden pattern detected ({$pattern})");
            }
        }

        // ── Execute ──────────────────────────────────────────────────
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- user-provided query, validated above
        $results = $wpdb->get_results($sql, ARRAY_A);

        if ($wpdb->last_error) {
            throw new Exception('Database error: ' . $wpdb->last_error);
        }

        $total   = is_array($results) ? count($results) : 0;
        $trimmed = is_array($results) ? array_slice($results, 0, $limit) : [];

        return $this->success([
            'rows_returned'  => count($trimmed),
            'rows_total'     => $total,
            'limit_applied'  => $total > $limit,
            'results'        => $trimmed,
        ]);
    }
}

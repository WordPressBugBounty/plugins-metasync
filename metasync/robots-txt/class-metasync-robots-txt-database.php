<?php

/**
 * Robots.txt Database Class
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @since       2.5.6
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Metasync_Robots_Txt_Database
{
    /**
     * Instance of this class
     *
     * @var Metasync_Robots_Txt_Database
     */
    private static $instance = null;

    /**
     * Table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'metasync_robots_txt_backups';
    }

    /**
     * Create database table
     */
    public function create_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            content longtext NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            created_by bigint(20) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Create a backup
     *
     * @param string $content Content to backup
     * @return int|bool Backup ID on success, false on failure
     */
    public function create_backup($content)
    {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table_name,
            array(
                'content' => $content,
                'created_by' => get_current_user_id()
            ),
            array('%s', '%d')
        );

        if (false === $result) {
            return false;
        }

        // Keep only last 50 backups
        $this->cleanup_old_backups(50);

        return $wpdb->insert_id;
    }

    /**
     * Get backups
     *
     * @param int $limit Number of backups to retrieve
     * @return array Array of backups
     */
    public function get_backups($limit = 10)
    {
        global $wpdb;

        $limit = absint($limit);

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT b.*, u.display_name as created_by_name
                FROM {$this->table_name} b
                LEFT JOIN {$wpdb->users} u ON b.created_by = u.ID
                ORDER BY b.created_at DESC
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return $results ? $results : array();
    }

    /**
     * Get a single backup
     *
     * @param int $backup_id Backup ID
     * @return array|null Backup data or null
     */
    public function get_backup($backup_id)
    {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                $backup_id
            ),
            ARRAY_A
        );

        return $result;
    }

    /**
     * Delete a backup
     *
     * @param int $backup_id Backup ID
     * @return bool True on success, false on failure
     */
    public function delete_backup($backup_id)
    {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table_name,
            array('id' => $backup_id),
            array('%d')
        );

        return false !== $result;
    }

    /**
     * Cleanup old backups
     *
     * @param int $keep_count Number of backups to keep
     * @return bool True on success, false on failure
     */
    private function cleanup_old_backups($keep_count = 50)
    {
        global $wpdb;

        // First, get the IDs to keep (avoids MySQL subquery deadlock)
        $ids_to_keep = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT id FROM {$this->table_name}
                ORDER BY created_at DESC
                LIMIT %d",
                $keep_count
            )
        );

        if (empty($ids_to_keep)) {
            return true;
        }

        // Delete all records NOT in the keep list
        $placeholders = implode(',', array_fill(0, count($ids_to_keep), '%d'));
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name}
                WHERE id NOT IN ($placeholders)",
                $ids_to_keep
            )
        );

        return true;
    }

    /**
     * Drop table (for uninstall)
     */
    public function drop_table()
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
    }
}

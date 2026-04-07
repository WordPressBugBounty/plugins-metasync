<?php
/**
 * Zapier Subscriptions Database
 *
 * Manages the persistent storage of Zapier REST Hook subscriptions.
 *
 * @package    Metasync
 * @subpackage Metasync/zapier
 * @since      2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Zapier_Database {

    /** @var string */
    public static $table_name = 'metasync_zapier_subscriptions';

    /** Valid event slugs */
    public const EVENTS = ['post_published', 'post_updated', 'post_deleted'];

    // ------------------------------------------------------------------
    // Schema
    // ------------------------------------------------------------------

    /**
     * Create or upgrade the subscriptions table.
     */
    public static function create_table() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id VARCHAR(64) NOT NULL,
            target_url TEXT NOT NULL,
            event VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            last_fired_at DATETIME NULL DEFAULT NULL,
            fire_count BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY subscription_id (subscription_id),
            KEY event (event)
        ) {$collate};";

        dbDelta($sql);
    }

    // ------------------------------------------------------------------
    // CRUD
    // ------------------------------------------------------------------

    /**
     * Insert a new subscription. Returns the generated subscription_id or WP_Error.
     *
     * @param string $target_url
     * @param string $event
     * @return string|WP_Error
     */
    public static function insert(string $target_url, string $event) {
        global $wpdb;

        if (!in_array($event, self::EVENTS, true)) {
            return new WP_Error('invalid_event', 'Invalid event: ' . $event);
        }

        $subscription_id = wp_generate_uuid4();

        $inserted = $wpdb->insert(
            self::table(),
            [
                'subscription_id' => $subscription_id,
                'target_url'      => esc_url_raw($target_url),
                'event'           => $event,
                'created_at'      => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new WP_Error('db_error', 'Failed to insert subscription: ' . $wpdb->last_error);
        }

        return $subscription_id;
    }

    /**
     * Delete a subscription by subscription_id.
     *
     * @param string $subscription_id
     * @return bool
     */
    public static function delete(string $subscription_id): bool {
        global $wpdb;

        $deleted = $wpdb->delete(
            self::table(),
            ['subscription_id' => $subscription_id],
            ['%s']
        );

        return $deleted !== false && $deleted > 0;
    }

    /**
     * Get all subscriptions for a given event.
     *
     * @param string $event
     * @return array
     */
    public static function get_by_event(string $event): array {
        global $wpdb;

        $table = self::table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE event = %s ORDER BY id ASC",
                $event
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get all subscriptions (for admin listing).
     *
     * @return array
     */
    public static function get_all(): array {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT * FROM " . self::table() . " ORDER BY created_at DESC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Get a single subscription by subscription_id.
     *
     * @param string $subscription_id
     * @return array|null
     */
    public static function get(string $subscription_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE subscription_id = %s LIMIT 1",
                $subscription_id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    /**
     * Update last_fired_at and increment fire_count after delivery.
     *
     * @param string $subscription_id
     */
    public static function record_fire(string $subscription_id): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::table() . "
                 SET last_fired_at = %s, fire_count = fire_count + 1
                 WHERE subscription_id = %s",
                current_time('mysql', true),
                $subscription_id
            )
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . self::$table_name;
    }
}

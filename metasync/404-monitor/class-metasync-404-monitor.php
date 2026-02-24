<?php

/**
 * The 404 error monitor functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/404-monitor
 * @author     Engineering Team <support@searchatlas.com>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Error_Monitor
{
    private $database;

    public function __construct(&$database)
    {
        $this->database = $database;
    }

   

    public function create_admin_plugin_interface()
    {
        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
        require dirname(__FILE__, 2) . '/404-monitor/class-metasync-404-monitor-list-table.php';

        $Metasync404Monitor = new Metasync_Error_Monitor_List_Table();
        $Metasync404Monitor->setDatabaseResource($this->database);
        $Metasync404Monitor->prepare_items();

        // Include the enhanced view markup.
        include dirname(__FILE__, 2) . '/views/metasync-404-monitor.php';
    }

    public function get_current_page_url($ignore_qs = false)
    {
        $server_data =  sanitize_post($_SERVER);
        $link = '://' . $server_data['HTTP_HOST'] . $server_data['REQUEST_URI'];
        $link = (is_ssl() ? 'https' : 'http') . $link;
        if ($ignore_qs) {
            $link = explode('?', sanitize_url($link));
            $link = $link[0];
        }
        return sanitize_url($link);
    }

    /**
     * Suggest redirections from 404 errors
     */
    public function suggest_redirections_from_404($limit = 10)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'metasync_404_logs';
        
        // Get most frequent 404 errors
        $query = $wpdb->prepare("
            SELECT uri, hits_count, date_time 
            FROM {$table_name} 
            ORDER BY hits_count DESC, date_time DESC 
            LIMIT %d
        ", $limit);
        
        $errors = $wpdb->get_results($query);
        $suggestions = [];
        
        foreach ($errors as $error) {
            $suggestions[] = [
                'uri' => $error->uri,
                'hits' => $error->hits_count,
                'last_seen' => $error->date_time,
                'suggested_redirect' => $this->generate_redirect_suggestion($error->uri)
            ];
        }
        
        return $suggestions;
    }

    /**
     * Generate redirect suggestion for a 404 URI
     */
    private function generate_redirect_suggestion($uri)
    {
        // Remove leading slash and query string
        $clean_uri = ltrim($uri, '/');
        $clean_uri = strtok($clean_uri, '?');
        
        // Try to find similar existing content
        $suggestions = [];
        
        // Check for similar post/page titles
        $posts = get_posts([
            'post_type' => ['post', 'page'],
            'post_status' => 'publish',
            'numberposts' => 5,
            's' => str_replace(['-', '_'], ' ', $clean_uri ?? '')
        ]);
        
        foreach ($posts as $post) {
            $suggestions[] = [
                'url' => get_permalink($post->ID),
                'title' => $post->post_title,
                'type' => 'Similar Content',
                'confidence' => $this->calculate_similarity($clean_uri, $post->post_title)
            ];
        }
        
        // Check for category/tag pages
        $terms = get_terms([
            'taxonomy' => ['category', 'post_tag'],
            'name__like' => str_replace(['-', '_'], ' ', $clean_uri ?? ''),
            'number' => 3
        ]);
        
        foreach ($terms as $term) {
            $suggestions[] = [
                'url' => get_term_link($term),
                'title' => $term->name,
                'type' => 'Category/Tag',
                'confidence' => $this->calculate_similarity($clean_uri, $term->name)
            ];
        }
        
        // Sort by confidence
        usort($suggestions, function($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });
        
        return array_slice($suggestions, 0, 3);
    }

    /**
     * Calculate similarity between two strings
     */
    private function calculate_similarity($str1, $str2)
    {
        $str1 = strtolower(str_replace(['-', '_'], ' ', $str1 ?? ''));
        $str2 = strtolower($str2);
        
        similar_text($str1, $str2, $percent);
        return $percent;
    }

    /**
     * Create redirection from 404 suggestion
     */
    public function create_redirection_from_404($uri, $redirect_to, $description = '')
    {
        if (empty($uri) || empty($redirect_to)) {
            return false;
        }
        
        // Initialize redirection database
        require_once dirname(__FILE__, 2) . '/redirections/class-metasync-redirection-database.php';
        $db_redirection = new Metasync_Redirection_Database();
        
        $data = [
            'sources_from' => serialize([$uri => 'exact']),
            'url_redirect_to' => $redirect_to,
            'http_code' => 301,
            'status' => 'active',
            'pattern_type' => 'exact',
            'description' => $description ?: 'Created from 404 suggestion'
        ];
        
        return $db_redirection->add($data);
    }
}

<?php
/**
 * OTTO Persistence Handler
 * 
 * Handles persisting OTTO data to native WordPress fields
 * so changes survive plugin uninstallation.
 * 
 * @package MetaSync
 * @since 2.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Otto_Persistence_Handler {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Initialize
    }

    /**
     * Log persistence operation to sync history
     *
     * @param string $content_type Type of persistence (image_alt_text, link_corrections, heading_changes)
     * @param int $post_id Optional post ID
     * @param array $details Details about what was updated
     * @return bool Whether logging succeeded
     */
    private static function log_to_sync_history($content_type, $post_id = null, $details = []) {
        // Ensure sync history class is loaded
        if (!class_exists('Metasync_Sync_History_Database')) {
            return false;
        }

        try {
            $sync_history = new Metasync_Sync_History_Database();

            // Build title based on content type and details
            $title = self::build_log_title($content_type, $details);

            // Get post URL if post_id provided
            $url = null;
            if ($post_id) {
                $url = get_permalink($post_id);
                if (!$url) {
                    $url = get_edit_post_link($post_id, 'raw');
                }
            }

            // Add to sync history
            $sync_history->add([
                'title' => $title,
                'source' => 'OTTO Persistence',
                'status' => 'published',
                'content_type' => $content_type,
                'url' => $url,
                'meta_data' => json_encode($details),
                'created_at' => current_time('mysql'),
            ]);

            return true;
        } catch (Exception $e) {
            // Silently fail - don't break persistence if logging fails
            return false;
        }
    }

    /**
     * Build a descriptive title for the sync log entry
     *
     * @param string $content_type
     * @param array $details
     * @return string
     */
    private static function build_log_title($content_type, $details) {
        switch ($content_type) {
            case 'image_alt_text':
                $count = isset($details['updated_count']) ? $details['updated_count'] : 0;
                return sprintf('Updated %d image alt text%s', $count, $count === 1 ? '' : 's');

            case 'link_corrections':
                $count = isset($details['updated_count']) ? $details['updated_count'] : 0;
                return sprintf('Corrected %d link%s in post content', $count, $count === 1 ? '' : 's');

            case 'heading_changes':
                $count = isset($details['updated_count']) ? $details['updated_count'] : 0;
                return sprintf('Updated %d heading%s in post content', $count, $count === 1 ? '' : 's');

            default:
                return 'OTTO persistence operation';
        }
    }

    /**
     * Check if a specific data type should be persisted
     * 
     * @param string $key
     * @return bool
     */
    public static function should_persist($key) {
        return Metasync_Otto_Persistence_Settings::should_persist($key);
    }

    /**
     * Persist image alt text to WordPress Media Library
     * Updates the _wp_attachment_image_alt meta field on attachment posts
     * 
     * @param array $image_data Array of image_url => alt_text
     * @return array Results with counts and details
     */
    public static function persist_image_alt_text($image_data) {
        if (empty($image_data) || !is_array($image_data)) {
            return [
                'success' => false,
                'updated_count' => 0,
                'skipped_count' => 0,
                'message' => 'No image data provided',
            ];
        }

        # Check if persistence is enabled
        if (!self::should_persist('image_alt_text')) {
            return [
                'success' => false,
                'updated_count' => 0,
                'skipped_count' => count($image_data),
                'message' => 'Image alt text persistence is disabled',
            ];
        }

        $updated_count = 0;
        $skipped_count = 0;
        $updated_images = [];
        $skipped_images = [];

        foreach ($image_data as $image_url => $alt_text) {
            # Sanitize inputs
            $image_url = esc_url_raw($image_url);
            $alt_text = sanitize_text_field($alt_text);

            if (empty($image_url) || empty($alt_text)) {
                $skipped_count++;
                $skipped_images[] = [
                    'url' => $image_url,
                    'reason' => 'Empty URL or alt text',
                ];
                continue;
            }

            # Find attachment ID by URL
            $attachment_id = self::get_attachment_id_by_url($image_url);

            if (!$attachment_id) {
                $skipped_count++;
                $skipped_images[] = [
                    'url' => $image_url,
                    'reason' => 'Attachment not found in Media Library',
                ];
                continue;
            }

            # Get current alt text
            $current_alt = get_post_meta($attachment_id, '_wp_attachment_image_alt', true);

            # Only update if different
            if ($current_alt !== $alt_text) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
                $updated_count++;
                $updated_images[] = [
                    'attachment_id' => $attachment_id,
                    'url' => $image_url,
                    'old_alt' => $current_alt,
                    'new_alt' => $alt_text,
                ];
            } else {
                $skipped_count++;
                $skipped_images[] = [
                    'url' => $image_url,
                    'reason' => 'Alt text already matches',
                ];
            }
        }

        $result = [
            'success' => $updated_count > 0,
            'updated_count' => $updated_count,
            'skipped_count' => $skipped_count,
            'updated_images' => $updated_images,
            'message' => sprintf('Updated %d image alt text(s) in Media Library', $updated_count),
        ];

        # Log to sync history if any images were updated
        if ($updated_count > 0) {
            self::log_to_sync_history('image_alt_text', null, [
                'updated_count' => $updated_count,
                'skipped_count' => $skipped_count,
                'updated_images' => $updated_images,
            ]);
        }

        return $result;
    }

    /**
     * Get attachment ID by URL
     * Uses multiple methods to find the attachment
     * 
     * @param string $url Image URL
     * @return int|false Attachment ID or false if not found
     */
    private static function get_attachment_id_by_url($url) {
        # Method 1: Use WordPress core function
        $attachment_id = attachment_url_to_postid($url);
        if ($attachment_id) {
            return $attachment_id;
        }

        # Method 2: Try without size suffix (e.g., -258x172)
        $url_without_size = preg_replace('/-\d+x\d+(?=\.[a-z]{3,4}$)/i', '', $url);
        if ($url_without_size !== $url) {
            $attachment_id = attachment_url_to_postid($url_without_size);
            if ($attachment_id) {
                return $attachment_id;
            }
        }

        # Method 3: Search by filename in database
        global $wpdb;
        $filename = basename(parse_url($url, PHP_URL_PATH));
        # Remove size suffix from filename
        $filename_base = preg_replace('/-\d+x\d+(?=\.[a-z]{3,4}$)/i', '', $filename);
        
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_wp_attached_file' 
             AND meta_value LIKE %s 
             LIMIT 1",
            '%' . $wpdb->esc_like($filename_base) . '%'
        ));

        return $attachment_id ? (int) $attachment_id : false;
    }

    /**
     * Persist link corrections to post content
     * Updates href attributes in the actual post_content
     * 
     * @param int $post_id WordPress post ID
     * @param array $link_data Array of old_url => new_url
     * @return array Results with counts and details
     */
    public static function persist_link_corrections($post_id, $link_data) {
        if (empty($link_data) || !is_array($link_data)) {
            return [
                'success' => false,
                'updated_count' => 0,
                'message' => 'No link data provided',
            ];
        }

        # Check if persistence is enabled
        if (!self::should_persist('link_corrections')) {
            return [
                'success' => false,
                'updated_count' => 0,
                'message' => 'Link corrections persistence is disabled',
            ];
        }

        $post = get_post($post_id);
        if (!$post) {
            return [
                'success' => false,
                'updated_count' => 0,
                'message' => 'Post not found',
            ];
        }

        $content = $post->post_content;
        $original_content = $content;
        $updated_count = 0;
        $updated_links = [];

        foreach ($link_data as $old_url => $new_url) {
            # Sanitize URLs
            $old_url = esc_url_raw($old_url);
            $new_url = esc_url_raw($new_url);

            if (empty($old_url) || empty($new_url) || $old_url === $new_url) {
                continue;
            }

            # Count occurrences before replacement
            $count_before = substr_count($content, $old_url);
            
            if ($count_before > 0) {
                # Replace in content (handles both href="..." and href='...')
                $content = str_replace($old_url, $new_url, $content);
                $updated_count += $count_before;
                $updated_links[] = [
                    'old_url' => $old_url,
                    'new_url' => $new_url,
                    'occurrences' => $count_before,
                ];
            }
        }

        # Only update if content changed
        if ($content !== $original_content) {
            $result = wp_update_post([
                'ID' => $post_id,
                'post_content' => $content,
            ], true);

            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'updated_count' => 0,
                    'message' => 'Failed to update post: ' . $result->get_error_message(),
                ];
            }

            # Clear post cache
            clean_post_cache($post_id);

            $result = [
                'success' => true,
                'updated_count' => $updated_count,
                'updated_links' => $updated_links,
                'message' => sprintf('Updated %d link(s) in post content', $updated_count),
            ];

            # Log to sync history
            self::log_to_sync_history('link_corrections', $post_id, [
                'updated_count' => $updated_count,
                'updated_links' => $updated_links,
            ]);

            return $result;
        }

        return [
            'success' => false,
            'updated_count' => 0,
            'message' => 'No matching links found in post content',
        ];
    }

    /**
     * Persist heading changes to post content
     * Updates heading text in the actual post_content
     * 
     * @param int $post_id WordPress post ID
     * @param array $heading_data Array of heading changes
     * @return array Results with counts and details
     */
    public static function persist_heading_changes($post_id, $heading_data) {
        if (empty($heading_data) || !is_array($heading_data)) {
            return [
                'success' => false,
                'updated_count' => 0,
                'message' => 'No heading data provided',
            ];
        }

        # Check if persistence is enabled
        if (!self::should_persist('heading_changes')) {
            return [
                'success' => false,
                'updated_count' => 0,
                'message' => 'Heading changes persistence is disabled',
            ];
        }

        $post = get_post($post_id);
        if (!$post) {
            return [
                'success' => false,
                'updated_count' => 0,
                'message' => 'Post not found',
            ];
        }

        $content = $post->post_content;
        $original_content = $content;
        $updated_count = 0;
        $updated_headings = [];

        foreach ($heading_data as $heading) {
            if (empty($heading['type']) || empty($heading['current_value']) || empty($heading['recommended_value'])) {
                continue;
            }

            $tag = strtolower(sanitize_text_field($heading['type'])); # h1, h2, h3, etc.
            $current_value = $heading['current_value'];
            $recommended_value = sanitize_text_field($heading['recommended_value']);

            if ($current_value === $recommended_value) {
                continue;
            }

            # Build regex to match the heading tag with the current value
            # Matches: <h1>current value</h1>, <h1 class="...">current value</h1>, etc.
            $pattern = '/(<' . preg_quote($tag, '/') . '[^>]*>)\s*' . preg_quote($current_value, '/') . '\s*(<\/' . preg_quote($tag, '/') . '>)/i';
            
            $replacement = '$1' . $recommended_value . '$2';
            
            $new_content = preg_replace($pattern, $replacement, $content, -1, $count);
            
            if ($count > 0 && $new_content !== null) {
                $content = $new_content;
                $updated_count += $count;
                $updated_headings[] = [
                    'type' => $tag,
                    'old_value' => $current_value,
                    'new_value' => $recommended_value,
                    'occurrences' => $count,
                ];
            }
        }

        # Only update if content changed
        if ($content !== $original_content) {
            $result = wp_update_post([
                'ID' => $post_id,
                'post_content' => $content,
            ], true);

            if (is_wp_error($result)) {
                return [
                    'success' => false,
                    'updated_count' => 0,
                    'message' => 'Failed to update post: ' . $result->get_error_message(),
                ];
            }

            # Clear post cache
            clean_post_cache($post_id);

            $result = [
                'success' => true,
                'updated_count' => $updated_count,
                'updated_headings' => $updated_headings,
                'message' => sprintf('Updated %d heading(s) in post content', $updated_count),
            ];

            # Log to sync history
            self::log_to_sync_history('heading_changes', $post_id, [
                'updated_count' => $updated_count,
                'updated_headings' => $updated_headings,
            ]);

            return $result;
        }

        return [
            'success' => false,
            'updated_count' => 0,
            'message' => 'No matching headings found in post content',
        ];
    }

    /**
     * Process all persistence for a post based on OTTO SEO data
     * This is the main entry point called from metasync_update_comprehensive_seo_fields
     * 
     * @param int $post_id WordPress post ID
     * @param array $seo_data Complete SEO data from OTTO
     * @return array Results for all persistence operations
     */
    public static function process_persistence($post_id, $seo_data) {
        $results = [
            'image_alt_text' => null,
            'link_corrections' => null,
            'heading_changes' => null,
        ];

        # Process image alt text persistence
        if (!empty($seo_data['body_substitutions']['images'])) {
            $results['image_alt_text'] = self::persist_image_alt_text($seo_data['body_substitutions']['images']);
        }

        # Process link corrections persistence
        if (!empty($seo_data['body_substitutions']['links'])) {
            $results['link_corrections'] = self::persist_link_corrections($post_id, $seo_data['body_substitutions']['links']);
        }

        # Process heading changes persistence
        if (!empty($seo_data['body_substitutions']['headings'])) {
            $results['heading_changes'] = self::persist_heading_changes($post_id, $seo_data['body_substitutions']['headings']);
        }

        return $results;
    }

    /**
     * Extract link corrections data from OTTO SEO data
     * 
     * @param array $seo_data The SEO data from OTTO API
     * @return array Array of old_url => new_url
     */
    public static function extract_link_corrections($seo_data) {
        if (empty($seo_data['body_substitutions']['links']) || !is_array($seo_data['body_substitutions']['links'])) {
            return [];
        }

        $link_data = [];
        foreach ($seo_data['body_substitutions']['links'] as $old_url => $new_url) {
            if (!empty($old_url) && !empty($new_url)) {
                $link_data[esc_url_raw($old_url)] = esc_url_raw($new_url);
            }
        }

        return $link_data;
    }
}





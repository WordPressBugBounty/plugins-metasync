<?php

/**
 * The Open Graph functionality of the plugin.
 *
 * @package    MetaSync
 * @subpackage MetaSync/includes
 * @since      1.0.0
 */

# Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Open Graph Tags Generator Class
 * 
 * This class handles the generation and management of Open Graph and Twitter Card tags
 * for WordPress posts and pages.
 */
class Metasync_OpenGraph {

    /**
     * The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     */
    private $version;

    /**
     * Meta box ID
     */
    const META_BOX_ID = 'metasync_opengraph_meta_box';

    /**
     * Supported post types
     */
    private $supported_post_types = ['post', 'page'];

    /**
     * Initialize the class and set its properties.
     */
    public function __construct($plugin_name, $version) {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    /**
     * Register all hooks for this class
     */
    public function init() {
        # Admin hooks
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('save_post', [$this, 'save_meta_box_data']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        # Alternative script loading for post edit screens
        add_action('admin_print_scripts-post.php', [$this, 'force_enqueue_scripts']);
        add_action('admin_print_scripts-post-new.php', [$this, 'force_enqueue_scripts']);

        # Frontend hooks
        add_action('wp_head', [$this, 'output_opengraph_tags'], 5);

        # Update OpenGraph URL when post is published/updated
        add_action('save_post', [$this, 'update_opengraph_url'], 20);
        
        # Update OpenGraph URL when post permalink changes
        add_action('post_updated', [$this, 'check_permalink_change'], 10, 3);
        
        # Also check on transition_post_status for status changes
        add_action('transition_post_status', [$this, 'check_status_change'], 10, 3);
        
        # Check when post slug is updated via edit slug functionality
        add_action('wp_ajax_sample-permalink', [$this, 'check_slug_change'], 5);

        # AJAX hooks for preview
        add_action('wp_ajax_metasync_og_preview', [$this, 'ajax_generate_preview']);
        add_action('wp_ajax_nopriv_metasync_og_preview', [$this, 'ajax_generate_preview']);
    }

    /**
     * Add the Open Graph meta box to post and page editors
     */
    public function add_meta_box() {
        # Don't show meta box if user's role doesn't have plugin access
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }

        # Check if user has permission to edit posts
        if (!current_user_can('edit_posts')) {
            return;
        }

        # Meta title and description are always enabled by default
        $general_settings = Metasync::get_option('general', []);

        # Check if Social Media & Open Graph meta box is disabled
        if (!empty($general_settings['disable_social_opengraph_metabox'])) {
            return;
        }

        # Get supported post types (allow filtering)
        $post_types = $this->get_supported_post_types();
        $plugin_name = Metasync::get_effective_plugin_name();

        foreach ($post_types as $post_type) {
            add_meta_box(
                self::META_BOX_ID,
                sprintf(esc_html__('Social Media & Open Graph by %s', 'metasync'), $plugin_name),
                [$this, 'render_meta_box'],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    /**
     * Render the meta box content
     */
    public function render_meta_box($post) {
        # Add nonce for security
        wp_nonce_field('metasync_opengraph_nonce', 'metasync_opengraph_nonce');

        # Get existing values
        $og_enabled = get_post_meta($post->ID, '_metasync_og_enabled', true);
        $og_title = get_post_meta($post->ID, '_metasync_og_title', true);
        $og_description = get_post_meta($post->ID, '_metasync_og_description', true);
        $og_image = get_post_meta($post->ID, '_metasync_og_image', true);
        $og_url = get_post_meta($post->ID, '_metasync_og_url', true);
        $og_type = get_post_meta($post->ID, '_metasync_og_type', true);
        
        # Twitter Card fields
        $twitter_card = get_post_meta($post->ID, '_metasync_twitter_card', true);
        $twitter_site = get_post_meta($post->ID, '_metasync_twitter_site', true);
        $twitter_title = get_post_meta($post->ID, '_metasync_twitter_title', true);
        $twitter_description = get_post_meta($post->ID, '_metasync_twitter_description', true);
        $twitter_image = get_post_meta($post->ID, '_metasync_twitter_image', true);
        $twitter_image_alt = get_post_meta($post->ID, '_metasync_twitter_image_alt', true);
        
        # Twitter App Card fields
        $twitter_app_id_iphone = get_post_meta($post->ID, '_metasync_twitter_app_id_iphone', true);
        $twitter_app_id_ipad = get_post_meta($post->ID, '_metasync_twitter_app_id_ipad', true);
        $twitter_app_id_googleplay = get_post_meta($post->ID, '_metasync_twitter_app_id_googleplay', true);
        $twitter_app_url_iphone = get_post_meta($post->ID, '_metasync_twitter_app_url_iphone', true);
        $twitter_app_url_ipad = get_post_meta($post->ID, '_metasync_twitter_app_url_ipad', true);
        $twitter_app_url_googleplay = get_post_meta($post->ID, '_metasync_twitter_app_url_googleplay', true);
        $twitter_app_country = get_post_meta($post->ID, '_metasync_twitter_app_country', true);
        
        # Twitter Player Card fields
        $twitter_player = get_post_meta($post->ID, '_metasync_twitter_player', true);
        $twitter_player_width = get_post_meta($post->ID, '_metasync_twitter_player_width', true);
        $twitter_player_height = get_post_meta($post->ID, '_metasync_twitter_player_height', true);

        # Set default values
        # Note: Check for empty string specifically, not just empty(), since '0' is a valid value
        if ($og_enabled === '') {
            # For new posts, default to enabled
            $og_enabled = '1';
        }
        if (empty($og_title)) {
            $og_title = $post->post_title;
        }
        if (empty($og_description)) {
            $og_description = $this->get_post_excerpt($post);
        }
        if (empty($og_url)) {
            $og_url = $this->get_canonical_url($post);
        }
        if (empty($og_type)) {
            $og_type = 'article';
        }
        if (empty($og_image)) {
            $og_image = $this->get_featured_image_url($post->ID);
        }
        
        # Twitter defaults
        if (empty($twitter_card)) {
            $twitter_card = 'summary_large_image';
        }
        if (empty($twitter_title)) {
            $twitter_title = $og_title;
        }
        if (empty($twitter_description)) {
            $twitter_description = $og_description;
        }
        if (empty($twitter_image)) {
            $twitter_image = $og_image;
        }

        # Include the meta box template
        include plugin_dir_path(__FILE__) . '../admin/partials/metasync-opengraph-meta-box.php';
    }

    /**
     * Save meta box data
     */
    public function save_meta_box_data($post_id) {
        # Check if nonce is valid
        if (!isset($_POST['metasync_opengraph_nonce']) || 
            !wp_verify_nonce($_POST['metasync_opengraph_nonce'], 'metasync_opengraph_nonce')) {
            return;
        }

        # Check if user has permission to edit
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        # Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        # Handle the checkbox field separately (unchecked checkboxes don't send POST data)
        # Meta title and description are always enabled by default
        if (isset($_POST['_metasync_og_enabled'])) {
            # User checked the box
            update_post_meta($post_id, '_metasync_og_enabled', '1');
        } else {
            # User unchecked the box
            update_post_meta($post_id, '_metasync_og_enabled', '0');
        }

        # Save Open Graph data (excluding the enabled field which is handled above)
        $og_fields = [
            '_metasync_og_title' => 'sanitize_text_field',
            '_metasync_og_description' => 'sanitize_textarea_field',
            '_metasync_og_image' => 'esc_url_raw',
            '_metasync_og_url' => 'esc_url_raw',
            '_metasync_og_type' => 'sanitize_text_field',
        ];

        # Save Twitter Card data
        $twitter_fields = [
            '_metasync_twitter_card' => 'sanitize_text_field',
            '_metasync_twitter_site' => 'sanitize_text_field',
            '_metasync_twitter_title' => 'sanitize_text_field',
            '_metasync_twitter_description' => 'sanitize_textarea_field',
            '_metasync_twitter_image' => 'esc_url_raw',
            '_metasync_twitter_image_alt' => 'sanitize_text_field',
        ];

        # Save Twitter App Card data
        $twitter_app_fields = [
            '_metasync_twitter_app_id_iphone' => 'sanitize_text_field',
            '_metasync_twitter_app_id_ipad' => 'sanitize_text_field',
            '_metasync_twitter_app_id_googleplay' => 'sanitize_text_field',
            '_metasync_twitter_app_url_iphone' => 'esc_url_raw',
            '_metasync_twitter_app_url_ipad' => 'esc_url_raw',
            '_metasync_twitter_app_url_googleplay' => 'esc_url_raw',
            '_metasync_twitter_app_country' => 'sanitize_text_field',
        ];

        # Save Twitter Player Card data
        $twitter_player_fields = [
            '_metasync_twitter_player' => 'esc_url_raw',
            '_metasync_twitter_player_width' => 'absint',
            '_metasync_twitter_player_height' => 'absint',
        ];

        $all_fields = array_merge($og_fields, $twitter_fields, $twitter_app_fields, $twitter_player_fields);

        foreach ($all_fields as $field => $sanitize_callback) {
            if (isset($_POST[$field])) {
                $value = call_user_func($sanitize_callback, $_POST[$field]);
                update_post_meta($post_id, $field, $value);
            }
        }
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        global $post_type;

        # Only load on post edit screens for supported post types
        if (!in_array($hook, ['post.php', 'post-new.php']) || 
            !in_array($post_type, $this->supported_post_types)) {
            return;
        }

        wp_enqueue_media();
        
        wp_enqueue_script(
            'metasync-opengraph-admin',
            plugin_dir_url(__FILE__) . '../admin/js/metasync-opengraph.js',
            ['jquery', 'wp-util'],
            $this->version,
            true
        );

        wp_enqueue_style(
            'metasync-opengraph-admin',
            plugin_dir_url(__FILE__) . '../admin/css/metasync-opengraph.css',
            [],
            $this->version
        );

        # Get the current post permalink for preview
        global $post;
        $current_permalink = '';
        if ($post && $post->ID) {
            $current_permalink = $this->get_canonical_url($post);
        }
        
        # Localize script for AJAX
        wp_localize_script('metasync-opengraph-admin', 'metasync_og', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('metasync_og_preview_nonce'),
            'current_permalink' => $current_permalink,
            'strings' => [
                'select_image' => esc_html__('Select Image', 'metasync'),
                'use_image' => esc_html__('Use This Image', 'metasync'),
                'remove_image' => esc_html__('Remove Image', 'metasync'),
            ]
        ]);
    }
    
    /**
     * Force enqueue scripts for post edit screens (backup method)
     */
    public function force_enqueue_scripts() {
        global $post_type;
        
        if (!in_array($post_type, $this->supported_post_types)) {
            return;
        }
        
        # Check if already enqueued
        if (wp_script_is('metasync-opengraph-admin', 'enqueued')) {
            return;
        }
        
        wp_enqueue_media();
        wp_enqueue_script(
            'metasync-opengraph-admin',
            plugin_dir_url(__FILE__) . '../admin/js/metasync-opengraph.js',
            ['jquery', 'wp-util'],
            $this->version,
            true
        );
        
        wp_enqueue_style(
            'metasync-opengraph-admin',
            plugin_dir_url(__FILE__) . '../admin/css/metasync-opengraph.css',
            [],
            $this->version
        );
        
        # Get the current post permalink for preview
        global $post;
        $current_permalink = '';
        if ($post && $post->ID) {
            $current_permalink = $this->get_canonical_url($post);
        }
        
        wp_localize_script('metasync-opengraph-admin', 'metasync_og', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('metasync_og_preview_nonce'),
            'current_permalink' => $current_permalink,
            'strings' => [
                'select_image' => esc_html__('Select Image', 'metasync'),
                'use_image' => esc_html__('Use This Image', 'metasync'),
                'remove_image' => esc_html__('Remove Image', 'metasync'),
            ]
        ]);
    }

    /**
     * Output Open Graph and Twitter Card tags in wp_head
     */
    public function output_opengraph_tags() {
        if (!is_singular($this->supported_post_types)) {
            return;
        }

        global $post;

        # Check if Open Graph is enabled for this post
        $og_enabled = get_post_meta($post->ID, '_metasync_og_enabled', true);
        if (empty($og_enabled) || $og_enabled !== '1') {
            return;
        }

        # Check for conflicts with other SEO plugins (allow override via filter)
        if (apply_filters('metasync_opengraph_check_conflicts', true) && $this->has_seo_plugin_conflicts()) {
            return;
        }

        # Get Open Graph data
        $og_title = get_post_meta($post->ID, '_metasync_og_title', true) ?: $post->post_title;
        $og_description = get_post_meta($post->ID, '_metasync_og_description', true) ?: $this->get_post_excerpt($post);
        $og_image = get_post_meta($post->ID, '_metasync_og_image', true) ?: $this->get_featured_image_url($post->ID);
        $og_url = get_post_meta($post->ID, '_metasync_og_url', true) ?: $this->get_canonical_url($post);
        $og_type = get_post_meta($post->ID, '_metasync_og_type', true) ?: 'article';

        # Get Twitter Card data
        $twitter_card = get_post_meta($post->ID, '_metasync_twitter_card', true) ?: 'summary_large_image';
        $twitter_site = get_post_meta($post->ID, '_metasync_twitter_site', true);
        $twitter_title = get_post_meta($post->ID, '_metasync_twitter_title', true) ?: $og_title;
        $twitter_description = get_post_meta($post->ID, '_metasync_twitter_description', true) ?: $og_description;
        $twitter_image = get_post_meta($post->ID, '_metasync_twitter_image', true) ?: $og_image;
        $twitter_image_alt = get_post_meta($post->ID, '_metasync_twitter_image_alt', true);
        
        # Get Twitter App Card data
        $twitter_app_id_iphone = get_post_meta($post->ID, '_metasync_twitter_app_id_iphone', true);
        $twitter_app_id_ipad = get_post_meta($post->ID, '_metasync_twitter_app_id_ipad', true);
        $twitter_app_id_googleplay = get_post_meta($post->ID, '_metasync_twitter_app_id_googleplay', true);
        $twitter_app_url_iphone = get_post_meta($post->ID, '_metasync_twitter_app_url_iphone', true);
        $twitter_app_url_ipad = get_post_meta($post->ID, '_metasync_twitter_app_url_ipad', true);
        $twitter_app_url_googleplay = get_post_meta($post->ID, '_metasync_twitter_app_url_googleplay', true);
        $twitter_app_country = get_post_meta($post->ID, '_metasync_twitter_app_country', true);
        
        # Get Twitter Player Card data
        $twitter_player = get_post_meta($post->ID, '_metasync_twitter_player', true);
        $twitter_player_width = get_post_meta($post->ID, '_metasync_twitter_player_width', true);
        $twitter_player_height = get_post_meta($post->ID, '_metasync_twitter_player_height', true);

        # Output Open Graph tags
        echo "\n<!-- MetaSync Open Graph Tags -->\n";
        if ($og_title) {
            echo '<meta property="og:title" content="' . esc_attr($og_title) . '">' . "\n";
        }
        if ($og_description) {
            echo '<meta property="og:description" content="' . esc_attr($og_description) . '">' . "\n";
        }
        if ($og_image) {
            echo '<meta property="og:image" content="' . esc_url($og_image) . '">' . "\n";
        }
        if ($og_url) {
            echo '<meta property="og:url" content="' . esc_url($og_url) . '">' . "\n";
        }
        if ($og_type) {
            echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";
        }

        # Output Twitter Card tags
        echo "<!-- MetaSync Twitter Card Tags -->\n";
        if ($twitter_card) {
            echo '<meta name="twitter:card" content="' . esc_attr($twitter_card) . '">' . "\n";
        }
        if ($twitter_site) {
            echo '<meta name="twitter:site" content="' . esc_attr($twitter_site) . '">' . "\n";
        }
        if ($twitter_title) {
            echo '<meta name="twitter:title" content="' . esc_attr($twitter_title) . '">' . "\n";
        }
        if ($twitter_description) {
            echo '<meta name="twitter:description" content="' . esc_attr($twitter_description) . '">' . "\n";
        }
        if ($twitter_image) {
            echo '<meta name="twitter:image" content="' . esc_url($twitter_image) . '">' . "\n";
        }
        if ($twitter_image_alt) {
            echo '<meta name="twitter:image:alt" content="' . esc_attr($twitter_image_alt) . '">' . "\n";
        }
        
        # Output Twitter App Card tags (only if card type is 'app')
        if ($twitter_card === 'app') {
            if ($twitter_app_id_iphone) {
                echo '<meta name="twitter:app:id:iphone" content="' . esc_attr($twitter_app_id_iphone) . '">' . "\n";
            }
            if ($twitter_app_id_ipad) {
                echo '<meta name="twitter:app:id:ipad" content="' . esc_attr($twitter_app_id_ipad) . '">' . "\n";
            }
            if ($twitter_app_id_googleplay) {
                echo '<meta name="twitter:app:id:googleplay" content="' . esc_attr($twitter_app_id_googleplay) . '">' . "\n";
            }
            if ($twitter_app_url_iphone) {
                echo '<meta name="twitter:app:url:iphone" content="' . esc_url($twitter_app_url_iphone) . '">' . "\n";
            }
            if ($twitter_app_url_ipad) {
                echo '<meta name="twitter:app:url:ipad" content="' . esc_url($twitter_app_url_ipad) . '">' . "\n";
            }
            if ($twitter_app_url_googleplay) {
                echo '<meta name="twitter:app:url:googleplay" content="' . esc_url($twitter_app_url_googleplay) . '">' . "\n";
            }
            if ($twitter_app_country) {
                echo '<meta name="twitter:app:country" content="' . esc_attr($twitter_app_country) . '">' . "\n";
            }
        }
        
        # Output Twitter Player Card tags (only if card type is 'player')
        if ($twitter_card === 'player') {
            if ($twitter_player) {
                echo '<meta name="twitter:player" content="' . esc_url($twitter_player) . '">' . "\n";
            }
            if ($twitter_player_width) {
                echo '<meta name="twitter:player:width" content="' . esc_attr($twitter_player_width) . '">' . "\n";
            }
            if ($twitter_player_height) {
                echo '<meta name="twitter:player:height" content="' . esc_attr($twitter_player_height) . '">' . "\n";
            }
        }
        
        echo "<!-- End MetaSync Social Media Tags -->\n\n";
    }

    /**
     * AJAX handler for generating social media preview
     */
    public function ajax_generate_preview() {
        
        try {
            # Check nonce
            if (!check_ajax_referer('metasync_og_preview_nonce', 'nonce', false)) {
                wp_send_json_error(['message' => 'Security check failed']);
                return;
            }

            # Get and sanitize data
            $title = sanitize_text_field($_POST['title'] ?? '');
            $description = sanitize_textarea_field($_POST['description'] ?? '');
            $image = esc_url_raw($_POST['image'] ?? '');
            $url = esc_url_raw($_POST['url'] ?? '');
            
            # Get Twitter Card data
            $twitter_title = sanitize_text_field($_POST['twitter_title'] ?? '');
            $twitter_description = sanitize_textarea_field($_POST['twitter_description'] ?? '');
            $twitter_image = esc_url_raw($_POST['twitter_image'] ?? '');

            # Generate preview HTML
            $preview_html = $this->generate_preview_html($title, $description, $image, $url, $twitter_title, $twitter_description, $twitter_image);
            
            if (empty($preview_html)) {
                wp_send_json_error(['message' => 'Failed to generate preview HTML']);
                return;
            }

            wp_send_json_success(['preview' => $preview_html]);
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Server error: ' . $e->getMessage()]);
        }
    }

    /**
     * Generate HTML for social media preview
     */
    private function generate_preview_html($title, $description, $image, $url, $twitter_title = '', $twitter_description = '', $twitter_image = '') {
        # Parse domain from URL
        $domain = '';
        if (!empty($url)) {
            $parsed = parse_url($url);
            $domain = $parsed['host'] ?? '';
        }
        
        # Fallback to site URL if no domain found
        if (empty($domain)) {
            $site_url = get_site_url();
            $parsed = parse_url($site_url);
            $domain = $parsed['host'] ?? 'your-site.com';
        }
        
        # Provide fallbacks for empty values
        if (empty($title)) {
            $title = 'Your Post Title';
        }
        if (empty($description)) {
            $description = 'Your post description will appear here when shared on social media platforms.';
        }
        
        # Use Twitter Card data for Twitter preview, fallback to Open Graph
        $twitter_display_title = !empty($twitter_title) ? $twitter_title : $title;
        $twitter_display_description = !empty($twitter_description) ? $twitter_description : $description;
        $twitter_display_image = !empty($twitter_image) ? $twitter_image : $image;

        # Get site name for avatars
        $site_name = get_bloginfo('name') ?: 'Your Site';
        $site_initial = strtoupper(substr($site_name, 0, 1));
        
        ob_start();
        ?>
        <div class="metasync-preview-tabs">
            <button class="metasync-preview-tab facebook active" data-platform="facebook">
                Facebook
            </button>
            <button class="metasync-preview-tab twitter" data-platform="twitter">
                Twitter/X
            </button>
            <button class="metasync-preview-tab linkedin" data-platform="linkedin">
                LinkedIn
            </button>
        </div>

        <div class="metasync-preview-content">
            <!-- Facebook Preview -->
            <div class="metasync-preview-panel facebook active" data-platform="facebook">
                <div class="facebook-preview">
                    <div class="facebook-post-header">
                        <div class="facebook-avatar"><?php echo esc_html($site_initial); ?></div>
                        <div class="facebook-post-info">
                            <h4><?php echo esc_html($site_name); ?></h4>
                            <p>2 hours ago • 🌍</p>
                        </div>
                    </div>
                    <div class="facebook-link-preview">
                        <?php if (!empty($image)): ?>
                            <div class="facebook-preview-image">
                                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <div class="preview-placeholder" style="display: none;">
                                    <span>📷</span>
                                    <p>Image failed to load</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="facebook-preview-image preview-no-image">
                                <div class="preview-placeholder">
                                    <span>📷</span>
                                    <p>No image selected</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="facebook-preview-content">
                            <div class="facebook-preview-domain"><?php echo esc_html(strtoupper($domain)); ?></div>
                            <div class="facebook-preview-title"><?php echo esc_html($title); ?></div>
                            <div class="facebook-preview-description"><?php echo esc_html($description); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Twitter Preview -->
            <div class="metasync-preview-panel twitter" data-platform="twitter">
                <div class="twitter-preview">
                    <div class="twitter-post-header">
                        <div class="twitter-avatar"><?php echo esc_html($site_initial); ?></div>
                        <div class="twitter-user-info">
                            <h4><?php echo esc_html($site_name); ?></h4>
                            <p>@<?php echo esc_html(strtolower(str_replace(' ', '', $site_name ?? ''))); ?> • 2h</p>
                        </div>
                    </div>
                    <div class="twitter-post-text">
                        Check out this amazing content! 🚀
                    </div>
                    <div class="twitter-card">
                        <?php if (!empty($twitter_display_image)): ?>
                            <div class="twitter-card-image">
                                <img src="<?php echo esc_url($twitter_display_image); ?>" alt="<?php echo esc_attr($twitter_display_title); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <div class="preview-placeholder" style="display: none;">
                                    <span>📷</span>
                                    <p>Image failed to load</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="twitter-card-image preview-no-image">
                                <div class="preview-placeholder">
                                    <span>📷</span>
                                    <p>No image selected</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="twitter-card-content">
                            <div class="twitter-card-domain"><?php echo esc_html($domain); ?></div>
                            <div class="twitter-card-title"><?php echo esc_html($twitter_display_title); ?></div>
                            <div class="twitter-card-description"><?php echo esc_html($twitter_display_description); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- LinkedIn Preview -->
            <div class="metasync-preview-panel linkedin" data-platform="linkedin">
                <div class="linkedin-preview">
                    <div class="linkedin-post-header">
                        <div class="linkedin-avatar"><?php echo esc_html($site_initial); ?></div>
                        <div class="linkedin-user-info">
                            <h4><?php echo esc_html($site_name); ?></h4>
                            <p>2 hours ago</p>
                        </div>
                    </div>
                    <div class="linkedin-link-preview">
                        <?php if (!empty($image)): ?>
                            <div class="linkedin-preview-image">
                                <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <div class="preview-placeholder" style="display: none;">
                                    <span>📷</span>
                                    <p>Image failed to load</p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="linkedin-preview-image preview-no-image">
                                <div class="preview-placeholder">
                                    <span>📷</span>
                                    <p>No image selected</p>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="linkedin-preview-content">
                            <div class="linkedin-preview-title"><?php echo esc_html($title); ?></div>
                            <div class="linkedin-preview-description"><?php echo esc_html($description); ?></div>
                            <div class="linkedin-preview-domain"><?php echo esc_html($domain); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get post excerpt for Open Graph description
     */
    private function get_post_excerpt($post) {
        if (!empty($post->post_excerpt)) {
            return $post->post_excerpt;
        }

        # Generate excerpt from content
        $content = $post->post_content;

        # Process shortcodes to get the actual rendered content (what users see on frontend)
        # This converts page builder shortcodes into their actual HTML output
        $content = do_shortcode($content);

        # Apply WordPress content filters (same filters used on the frontend)
        # This ensures we get the exact same content as displayed on the page
        $content = apply_filters('the_content', $content);

        # Remove HTML tags to get clean text
        $content = wp_strip_all_tags($content);

        # Remove extra whitespace, line breaks, and special characters
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);

        # If content is still empty or too short, fallback to post title
        if (empty($content) || strlen($content) < 20) {
            $content = $post->post_title;
        }

        # Generate excerpt
        $excerpt = wp_trim_words($content, 30, '...');

        return $excerpt;
    }

    /**
     * Get featured image URL
     */
    private function get_featured_image_url($post_id) {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) {
            $image_url = wp_get_attachment_image_url($thumbnail_id, 'large');
            return $image_url;
        }
        return '';
    }

    /**
     * Get supported post types
     */
    public function get_supported_post_types() {
        return apply_filters('metasync_opengraph_post_types', $this->supported_post_types);
    }

    /**
     * Add debug menu for testing
     */
    private function has_seo_plugin_conflicts() {
        # List of SEO plugins that might output Open Graph tags
        $seo_plugins = [
            'wordpress-seo/wp-seo.php', # Yoast SEO
            'seo-by-rank-math/rank-math.php', # RankMath
            'all-in-one-seo-pack/all_in_one_seo_pack.php', # AIOSEO
            'seopress/seopress.php', # SEOPress
            'the-seo-framework/autodescription.php', # The SEO Framework
        ];

        foreach ($seo_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a specific SEO plugin is handling Open Graph for current post
     */
    private function seo_plugin_has_og_data($post_id) {
        # Check if Yoast SEO has Open Graph data
        if (is_plugin_active('wordpress-seo/wp-seo.php')) {
            $yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
            $yoast_desc = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            if (!empty($yoast_title) || !empty($yoast_desc)) {
                return true;
            }
        }

        # Check if RankMath has Open Graph data
        if (is_plugin_active('seo-by-rank-math/rank-math.php') || is_plugin_active('seo-by-rankmath/rank-math.php')) {
            $rm_title = get_post_meta($post_id, 'rank_math_title', true);
            $rm_desc = get_post_meta($post_id, 'rank_math_description', true);
            if (!empty($rm_title) || !empty($rm_desc)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the canonical URL for a post
     */
    public function get_canonical_url($post) {
        # Try to get the permalink using WordPress function
        $permalink = get_permalink($post->ID);

        # If permalink is not available or is the default query URL, try alternative methods
        if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
            # Force WordPress to generate the proper permalink by temporarily setting post status
            $original_status = $post->post_status;
            if ($post->post_status === 'auto-draft') {
                $post->post_status = 'publish';
            }

            # Try get_permalink again with the updated status
            $permalink = get_permalink($post->ID);

            # Restore original status
            $post->post_status = $original_status;
        }

        # If still not working, use WordPress core functions to build proper permalink
        if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
            # Use WordPress core function that respects permalink structure
            # This properly handles custom structures, hierarchies, and post types
            # Load admin function if not already available
            if (!function_exists('get_sample_permalink')) {
                require_once ABSPATH . 'wp-admin/includes/post.php';
            }
            $permalink = get_sample_permalink($post->ID);

            if (is_array($permalink)) {
                # get_sample_permalink returns array with template and slug
                # Replace %postname% or %pagename% with actual slug
                $permalink = str_replace(
                    array('%pagename%', '%postname%'),
                    $post->post_name,
                    $permalink[0]
                );
            }

            # Final fallback: if still problematic, construct URL respecting post type structure
            if (!$permalink || strpos($permalink, '?p=') !== false || strpos($permalink, '?page_id=') !== false) {
                if (!empty($post->post_name)) {
                    # For pages, check if there's a parent hierarchy
                    if ($post->post_type === 'page' && $post->post_parent) {
                        # Get parent page path for proper hierarchy
                        $parent = get_post($post->post_parent);
                        $parent_path = '';

                        # Build full path including all parent pages
                        while ($parent) {
                            $parent_path = $parent->post_name . '/' . $parent_path;
                            $parent = $parent->post_parent ? get_post($parent->post_parent) : null;
                        }

                        $permalink = home_url('/' . $parent_path . $post->post_name . '/');
                    } else {
                        # For posts and pages without parents, use post type archive base
                        $post_type_obj = get_post_type_object($post->post_type);
                        $slug = $post_type_obj->rewrite['slug'] ?? '';

                        if ($slug && $post->post_type !== 'page') {
                            $permalink = home_url('/' . $slug . '/' . $post->post_name . '/');
                        } else {
                            $permalink = home_url('/' . $post->post_name . '/');
                        }
                    }
                } else {
                    # Fallback to post ID format if no slug available
                    $permalink = home_url('/?p=' . $post->ID);
                }
            }
        }

        return $permalink;
    }

    /**
     * Update OpenGraph URL when post is saved
     */
    public function update_opengraph_url($post_id) {
        # Only update for supported post types
        if (!in_array(get_post_type($post_id), $this->get_supported_post_types())) {
            return;
        }

        # Skip autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        # Get the post object
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        # Check if OpenGraph is enabled
        $og_enabled = get_post_meta($post_id, '_metasync_og_enabled', true);
        if (empty($og_enabled) || $og_enabled !== '1') {
            return;
        }

        # Get the current OpenGraph URL
        $current_og_url = get_post_meta($post_id, '_metasync_og_url', true);
        
        # Generate the proper canonical URL
        $canonical_url = $this->get_canonical_url($post);
        
        # Update the OpenGraph URL for new posts or if it's empty/incorrect
        # This ensures the URL is populated after first save (even as draft)
        if (empty($current_og_url) || 
            strpos($current_og_url, '?p=') !== false) {
            
            update_post_meta($post_id, '_metasync_og_url', $canonical_url);
        }
    }

    /**
     * Check if post permalink changed and update og:url if needed
     */
    public function check_permalink_change($post_id, $post_after, $post_before) {
        # Only check for supported post types
        if (!in_array(get_post_type($post_id), $this->get_supported_post_types())) {
            return;
        }

        # Skip autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        # Check if OpenGraph is enabled
        $og_enabled = get_post_meta($post_id, '_metasync_og_enabled', true);
        if (empty($og_enabled) || $og_enabled !== '1') {
            return;
        }

        # Get current og:url
        $current_og_url = get_post_meta($post_id, '_metasync_og_url', true);
        if (empty($current_og_url)) {
            return;
        }

        # Check if the permalink actually changed by comparing post_name (slug)
        if ($post_before->post_name === $post_after->post_name) {
            return;
        }

        # Generate the old and new permalinks
        $old_permalink = $this->get_canonical_url($post_before);
        $new_permalink = $this->get_canonical_url($post_after);
        
        # If permalinks are the same, no need to update
        if ($old_permalink === $new_permalink) {
            return;
        }

        # Check if the current og:url matches the old permalink
        # This means the og:url was set to the post permalink (not a custom URL)
        if ($current_og_url === $old_permalink) {
            # Update og:url to the new permalink
            update_post_meta($post_id, '_metasync_og_url', $new_permalink);
        }
    }

    /**
     * Check if post status changed and update og:url if needed
     */
    public function check_status_change($new_status, $old_status, $post) {
        # Only check for supported post types
        if (!in_array(get_post_type($post->ID), $this->get_supported_post_types())) {
            return;
        }

        # Skip autosaves and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post->ID)) {
            return;
        }

        # Only check when transitioning to published status
        if ($new_status !== 'publish' || $old_status === 'publish') {
            return;
        }

        # Check if OpenGraph is enabled
        $og_enabled = get_post_meta($post->ID, '_metasync_og_enabled', true);
        if (empty($og_enabled) || $og_enabled !== '1') {
            return;
        }

        # Get current og:url
        $current_og_url = get_post_meta($post->ID, '_metasync_og_url', true);
        
        # Generate the current permalink
        $current_permalink = $this->get_canonical_url($post);
        
        # If og:url is empty or matches the old format, update it
        if (empty($current_og_url) || strpos($current_og_url, '?p=') !== false) {
            update_post_meta($post->ID, '_metasync_og_url', $current_permalink);
        }
    }

    /**
     * Check if post slug changed via edit slug functionality
     */
    public function check_slug_change() {
        # Get the post ID from the request
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            return;
        }

        # Only check for supported post types
        if (!in_array(get_post_type($post_id), $this->get_supported_post_types())) {
            return;
        }

        # Check if OpenGraph is enabled
        $og_enabled = get_post_meta($post_id, '_metasync_og_enabled', true);
        if (empty($og_enabled) || $og_enabled !== '1') {
            return;
        }

        # Get current og:url
        $current_og_url = get_post_meta($post_id, '_metasync_og_url', true);
        if (empty($current_og_url)) {
            return;
        }

        # Get the post object
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        # Generate the current permalink
        $current_permalink = $this->get_canonical_url($post);
        
        # Check if the current og:url matches the old permalink format
        # This means the og:url was set to the post permalink (not a custom URL)
        if ($current_og_url !== $current_permalink && strpos($current_og_url, '?p=') === false) {
            # Check if the og:url was the old permalink by comparing with a generated old permalink
            $old_post = clone $post;
            $old_slug = isset($_POST['new_slug']) ? sanitize_title($_POST['new_slug']) : $post->post_name;
            
            # If the og:url doesn't match the current permalink, it might be the old one
            # We'll update it to the new permalink
            if ($current_og_url !== $current_permalink) {
                update_post_meta($post_id, '_metasync_og_url', $current_permalink);
            }
        }
    }
}

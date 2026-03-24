<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Settings Registration manager.
 *
 * Extracted from Metasync_Admin to keep the admin class focused on UI concerns.
 * Handles register_setting / add_settings_section / add_settings_field calls
 * (settings_page_init) and the sanitize callback for the main option group.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Settings_Registration
{
    /** @var self|null */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return self
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /**
     * Helper – replicate Metasync_Admin::get_effective_menu_title() without
     * needing an admin instance.
     */
    private function get_effective_menu_title()
    {
        $whitelabel_company_name = Metasync::get_whitelabel_company_name();
        if ($whitelabel_company_name) {
            return $whitelabel_company_name . ' SEO';
        }
        return Metasync::get_effective_plugin_name();
    }

    /**
     * Handle saving plugin access roles from Advanced Settings.
     * Moved from Metasync_Admin – only called from settings_page_init().
     */
    private function handle_plugin_access_roles_save()
    {
        if (isset($_POST['save_plugin_access_roles']) && $_POST['save_plugin_access_roles'] === 'yes') {
            if (isset($_POST['plugin_access_roles_nonce']) && wp_verify_nonce($_POST['plugin_access_roles_nonce'], 'metasync_plugin_access_roles_nonce')) {

                if (!Metasync::current_user_has_plugin_access()) {
                    wp_redirect(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '&tab=advanced&access_roles_error=1&message=' . urlencode('Insufficient permissions')));
                    exit;
                }

                $metasync_options = Metasync::get_option();
                if (!is_array($metasync_options)) {
                    $metasync_options = array();
                }
                if (!isset($metasync_options['general']) || !is_array($metasync_options['general'])) {
                    $metasync_options['general'] = array();
                }

                if (isset($_POST['plugin_access_roles']) && is_array($_POST['plugin_access_roles'])) {
                    $metasync_options['general']['plugin_access_roles'] = array_map('sanitize_text_field', $_POST['plugin_access_roles']);
                } else {
                    $metasync_options['general']['plugin_access_roles'] = array();
                }

                Metasync::set_option($metasync_options);

                $redirect_url = admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '&tab=advanced&access_roles_saved=1');
                wp_redirect($redirect_url);
                exit;
            } else {
                $redirect_url = admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '&tab=advanced&access_roles_error=1&message=' . urlencode('Invalid security token'));
                wp_redirect($redirect_url);
                exit;
            }
        }
    }

    /**
     * Register and add settings – formerly Metasync_Admin::settings_page_init().
     */
    public function settings_page_init()
    {
        Metasync_Connect_Manager::instance()->handle_session_management_early();
        Metasync_Connect_Manager::instance()->handle_whitelabel_password_early();

        Metasync_Debug_Manager::instance()->handle_debug_mode_operations();
        Metasync_Debug_Manager::instance()->handle_error_log_operations();
        Metasync_Debug_Manager::instance()->handle_clear_all_settings();

        $this->handle_plugin_access_roles_save();

        $SECTION_FEATURES               = Metasync_Admin::SECTION_FEATURES;
        $SECTION_METASYNC               = Metasync_Admin::SECTION_METASYNC;
        $SECTION_SEARCHENGINE           = Metasync_Admin::SECTION_SEARCHENGINE;
        $SECTION_LOCALSEO               = Metasync_Admin::SECTION_LOCALSEO;
        $SECTION_CODESNIPPETS           = Metasync_Admin::SECTION_CODESNIPPETS;
        $SECTION_OPTIMAL_SETTINGS       = Metasync_Admin::SECTION_OPTIMAL_SETTINGS;
        $SECTION_SITE_SETTINGS          = Metasync_Admin::SECTION_SITE_SETTINGS;
        $SECTION_COMMON_SETTINGS        = Metasync_Admin::SECTION_COMMON_SETTINGS;
        $SECTION_COMMON_META_SETTINGS   = Metasync_Admin::SECTION_COMMON_META_SETTINGS;
        $SECTION_SOCIAL_META            = Metasync_Admin::SECTION_SOCIAL_META;
        $SECTION_SEO_CONTROLS           = Metasync_Admin::SECTION_SEO_CONTROLS;
        $SECTION_SEO_CONTROLS_ADVANCED  = Metasync_Admin::SECTION_SEO_CONTROLS_ADVANCED;
        $SECTION_SEO_CONTROLS_INSTANT_INDEX = Metasync_Admin::SECTION_SEO_CONTROLS_INSTANT_INDEX;
        $SECTION_PLUGIN_VISIBILITY      = Metasync_Admin::SECTION_PLUGIN_VISIBILITY;

        $option_key = Metasync_Admin::option_key;
        $page_slug  = Metasync_Admin::$page_slug;

        # Use whitelabel OTTO name if configured, fallback to 'OTTO'
        $whitelabel_otto_name = Metasync::get_whitelabel_otto_name();

        register_setting(
            Metasync_Admin::option_group,
            Metasync_Admin::option_key,
            array(self::instance(), 'sanitize')
        );

       
        add_settings_section(
            $SECTION_METASYNC, // ID
            '', // Title - removed to prevent duplication with dashboard card
            function(){}, // Callback
            $page_slug . '_general' // Page
        );

        add_settings_section(
            $SECTION_METASYNC, // ID
            '', // Title - removed to prevent duplication with dashboard card
            function(){}, // Callback
            $page_slug . '_branding' // Page
        );

        add_settings_section(
            $SECTION_METASYNC, // ID
           $this->get_effective_menu_title() . ' Caching Settings:', // Title
            function(){}, // Callback
            $page_slug . '_otto_cache' // Page
        );

        add_settings_section(
            $SECTION_SEO_CONTROLS, // ID
            '', // Title - removed to prevent duplication with dashboard card
            function(){}, // Callback
            $page_slug . '_seo-controls' // Page
        );

        // Archive Indexation Control Fields
        add_settings_field(
            'noindex_empty_archives',
            'Disallow Empty Archives',
            function() use ($option_key) {
                $noindex_empty_archives = Metasync::get_option('seo_controls')['noindex_empty_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="noindex_empty_archives" name="' . $option_key . '[seo_controls][noindex_empty_archives]" value="true" %s />',
                    isset($noindex_empty_archives) && $noindex_empty_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Empty Archives:</strong> When checked, automatically adds noindex to category, tag, author, and format archive pages that have no posts. Once posts are added to these archives, they will automatically be allowed for indexing. This prevents thin content pages from being indexed.</span>');
            },
            $page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'index_date_archives',
            'Disallow Date Archives',
            function() use ($option_key) {
                $index_date_archives = Metasync::get_option('seo_controls')['index_date_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="index_date_archives" name="' . $option_key . '[seo_controls][index_date_archives]" value="true" %s />',
                    isset($index_date_archives) && $index_date_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Date Archives:</strong> When checked, prevents search engines from indexing date-based archive pages (e.g., /2024/01/, /2024/01/15/). These pages often have thin content and can dilute your site\'s SEO value. Recommended for most sites.</span>');
            },
            $page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'index_tag_archives',
            'Disallow Tag Archives',
            function() use ($option_key) {
                $index_tag_archives = Metasync::get_option('seo_controls')['index_tag_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="index_tag_archives" name="' . $option_key . '[seo_controls][index_tag_archives]" value="true" %s />',
                    isset($index_tag_archives) && $index_tag_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Tag Archives:</strong> When checked, prevents search engines from indexing tag archive pages (e.g., /tag/technology/). Useful if you have many low-quality tag pages or want to focus on category-based organization instead.</span>');
            },
            $page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'index_author_archives',
            'Disallow Author Archives',
            function() use ($option_key) {
                $index_author_archives = Metasync::get_option('seo_controls')['index_author_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="index_author_archives" name="' . $option_key . '[seo_controls][index_author_archives]" value="true" %s />',
                    isset($index_author_archives) && $index_author_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Author Archives:</strong> When checked, prevents search engines from indexing author archive pages (e.g., /author/john-doe/). Recommended for single-author sites or when author pages don\'t provide unique value. Multi-author sites may want to keep this unchecked.</span>');
            },
            $page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'index_format_archives',
            'Disallow Format Archives',
            function() use ($option_key) {
                $index_format_archives = Metasync::get_option('seo_controls')['index_format_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="index_format_archives" name="' . $option_key . '[seo_controls][index_format_archives]" value="true" %s />',
                    isset($index_format_archives) && $index_format_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Format Archives:</strong> When checked, prevents search engines from indexing post format archive pages (e.g., /type/aside/, /type/gallery/). These are rarely useful for SEO and can create duplicate content issues. Recommended for most sites.</span>');
            },
            $page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'index_category_archives',
            'Disallow Category Archives',
            function() use ($option_key) {
                $index_category_archives = Metasync::get_option('seo_controls')['index_category_archives'] ?? 'false';
                printf(
                    '<input type="checkbox" id="index_category_archives" name="' . $option_key . '[seo_controls][index_category_archives]" value="true" %s />',
                    isset($index_category_archives) && $index_category_archives == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Category Archives:</strong> When checked, prevents search engines from indexing category archive pages (e.g., /category/news/). This may be useful if your category pages have thin content or if you want to consolidate SEO value on main pages instead. Use with caution as category pages can be valuable for site organization.</span>');
            },
            $page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        add_settings_field(
            'add_nofollow_to_external_links',
            'Add No-follow to External Links',
            function() use ($option_key) {
                $add_nofollow_to_external_links = Metasync::get_option('seo_controls')['add_nofollow_to_external_links'] ?? 'false';
                printf(
                    '<input type="checkbox" id="add_nofollow_to_external_links" name="' . $option_key . '[seo_controls][add_nofollow_to_external_links]" value="true" %s />',
                    isset($add_nofollow_to_external_links) && $add_nofollow_to_external_links == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>No-follow External Links:</strong> When checked, automatically adds <code>rel="nofollow"</code> attribute to all external links appearing in posts, pages, and other post types when rendered by Otto. This tells search engines not to follow these links, which can help preserve your site\'s SEO value and prevent passing link juice to external sites.</span>');
            },
            $page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS
        );

        // Advanced SEO Controls Section
        add_settings_section(
            $SECTION_SEO_CONTROLS_ADVANCED, // ID
            '', // Title - will be handled by custom rendering
            function(){
                echo '</div>'; // Close main Indexation Control card
                echo '<div class="dashboard-card" style="margin-top: 20px;">';
                echo '<h2>⚙️ Advanced Settings</h2>';
                echo '<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure how ' . esc_html(Metasync_Settings_Registration::instance()->get_effective_menu_title()) . ' interacts with other SEO plugins.</p>';
            },
            $page_slug . '_seo-controls' // Page
        );

        add_settings_field(
            'override_robots_tags',
            'Override Other Plugins\' Robots Tags',
            function() use ($option_key) {
                $override_robots_tags = Metasync::get_option('seo_controls')['override_robots_tags'] ?? 'false';
                printf(
                    '<input type="checkbox" id="override_robots_tags" name="' . $option_key . '[seo_controls][override_robots_tags]" value="true" %s />',
                    isset($override_robots_tags) && $override_robots_tags == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Override Robots Tags:</strong> When checked, ' . Metasync_Settings_Registration::instance()->get_effective_menu_title() . ' will take precedence over robots meta tags from other SEO plugins (Yoast, Rank Math, All in One SEO, etc.). This removes their noindex tags when you want to allow indexing on archive pages. Only enable this if other plugins are conflicting with your indexation settings.</span>');
            },
            $page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS_ADVANCED
        );

        // Google Instant Indexing Section
        add_settings_section(
            $SECTION_SEO_CONTROLS_INSTANT_INDEX, // ID
            '', // Title - will be handled by custom rendering
            function(){
                echo '</div>'; // Close Advanced Settings card
                echo '<div class="dashboard-card" style="margin-top: 20px;">';
                echo '<h2>🔗 Google Instant Indexing</h2>';
                echo '<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure Google Indexing API for faster URL indexing. Enable this feature to access the Instant Indexing page.</p>';
            },
            $page_slug . '_seo-controls' // Page
        );

        add_settings_field(
            'enable_googleinstantindex',
            'Enable Google Instant Indexing',
            function() use ($option_key) {
                $enable_googleinstantindex = Metasync::get_option('seo_controls')['enable_googleinstantindex'] ?? 'false';
                printf(
                    '<input type="checkbox" id="enable_googleinstantindex" name="' . $option_key . '[seo_controls][enable_googleinstantindex]" value="true" %s />',
                    isset($enable_googleinstantindex) && $enable_googleinstantindex == 'true' ? 'checked' : ''
                );
                printf('<span class="description"><strong>Enable Instant Indexing:</strong> When checked, enables the Google Instant Indexing feature which allows you to submit URLs directly to Google for faster indexing. A new "Instant Indexing" menu item will appear in the navigation.</span>');
            },
            $page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS_INSTANT_INDEX
        );

        add_settings_field(
            'google_index_api_config',
            'Google Index API Configuration',
            array(Metasync_Settings_Fields::instance(), 'render_google_index_section'),
            $page_slug . '_seo-controls',
            $SECTION_SEO_CONTROLS_INSTANT_INDEX
        );

        // Bing Instant Indexing Section
        add_settings_section(
            'seo_controls_bing_instant_index', // ID
            '', // Title - will be handled by custom rendering
            function(){
                echo '</div>'; // Close Google Instant Indexing card
                echo '<div class="dashboard-card" style="margin-top: 20px;">';
                echo '<h2>🔗 Bing Instant Indexing (IndexNow)</h2>';
                echo '<p style="color: var(--dashboard-text-secondary); margin-bottom: 20px;">Configure IndexNow API for instant URL submission to Bing, Yandex, and other search engines that support the IndexNow protocol.</p>';
            },
            $page_slug . '_seo-controls' // Page
        );

        add_settings_field(
            'enable_binginstantindex',
            'Enable Bing Instant Indexing',
            function() use ($option_key) {
                // Auto-enable if API key is configured
                $bing_settings = get_option('metasync_options_bing_instant_indexing', []);
                $has_api_key = !empty($bing_settings['api_key']);

                $enable_binginstantindex = Metasync::get_option('seo_controls')['enable_binginstantindex'] ?? 'false';

                // Auto-check if API key exists
                if ($has_api_key && $enable_binginstantindex !== 'true') {
                    $enable_binginstantindex = 'true';
                }

                printf(
                    '<input type="checkbox" id="enable_binginstantindex" name="' . $option_key . '[seo_controls][enable_binginstantindex]" value="true" %s %s />',
                    isset($enable_binginstantindex) && $enable_binginstantindex == 'true' ? 'checked' : '',
                    $has_api_key ? 'data-auto-enabled="true"' : ''
                );
                printf('<span class="description"><strong>Enable Bing Instant Indexing:</strong> Automatically enabled when you configure an API key below. When enabled, this activates the IndexNow protocol to instantly notify Bing, Yandex, and other search engines about URL changes.</span>');
            },
            $page_slug . '_seo-controls',
            'seo_controls_bing_instant_index'
        );

        // Bing Index API configuration
        add_settings_field(
            'bing_index_api_config',
            'IndexNow API Configuration',
            array(Metasync_Settings_Fields::instance(), 'render_bing_index_section'),
            $page_slug . '_seo-controls',
            'seo_controls_bing_instant_index'
        );

        add_settings_field(
            'searchatlas_api_key',
            $this->get_effective_menu_title() .  ' API Key',
            array(Metasync_Settings_Fields::instance(), 'searchatlas_api_key_callback'),
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'apikey', // ID
            'Plugin Auth Token', // Title
            array(Metasync_Settings_Fields::instance(), 'metasync_settings_genkey_callback'), // Callback
            $page_slug . '_general', // Page
            $SECTION_METASYNC // Section
        );

        /**
         * SERVER SIDE RENDERING SETTING OPTIONS 
         * @see SSR functionality
         * Note: OTTO SSR is always enabled by default when connected
         */

        # field to accept the otto pixel
        add_settings_field(
            'otto_pixel_uuid',
            $whitelabel_otto_name . ' Pixel UUID',
            function() use ($option_key) {
                $value = Metasync::get_option('general')['otto_pixel_uuid'] ?? '';   
                printf('<input type="text" size="40" value = "'.esc_attr($value).'" name="' . $option_key . '[general][otto_pixel_uuid]"/>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );


        # check box to toggle on and off disabling OTTO for logged in users
        add_settings_field(
            'otto_disable_on_loggedin',
            'Disable ' . $whitelabel_otto_name . ' for Logged in Users',
            function() use ($whitelabel_otto_name, $option_key) {
                $otto_disable_on_loggedin = Metasync::get_option('general')['otto_disable_on_loggedin'] ?? '';
                printf(
                    '<input type="checkbox" id="otto_disable_on_loggedin" name="' . $option_key . '[general][otto_disable_on_loggedin]" value="true" %s />',
                    isset($otto_disable_on_loggedin) && $otto_disable_on_loggedin == 'true' ? 'checked' : ''
                );
                printf('<span class="description"> This disables '.$whitelabel_otto_name.' when logged in to allow editing original page contents</span>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        # check box to disable OTTO frontend toolbar
        add_settings_field(
            'otto_disable_preview_button',
            'Disable ' . $whitelabel_otto_name . ' Frontend Toolbar',
            function() use ($whitelabel_otto_name, $option_key) {
                $otto_disable_toolbar = Metasync::get_option('general')['otto_disable_preview_button'] ?? false;
                printf(
                    '<input type="checkbox" id="otto_disable_preview_button" name="' . $option_key . '[general][otto_disable_preview_button]" value="true" %s />',
                    filter_var($otto_disable_toolbar, FILTER_VALIDATE_BOOLEAN) ? 'checked' : ''
                );
                printf('<span class="description"> Hide the entire frontend toolbar (status indicator, preview button, and debug button) on the frontend. %s functionality will still work, but the toolbar controls will be hidden.</span>', esc_html($whitelabel_otto_name));
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        # WP Rocket compatibility mode setting
        add_settings_field(
            'otto_wp_rocket_compat',
            'WP Rocket Compatibility',
            function() use ($whitelabel_otto_name, $option_key) {
                $value = Metasync::get_option('general')['otto_wp_rocket_compat'] ?? 'auto';
                $wp_rocket_active = class_exists('WP_Rocket');

                echo '<select id="otto_wp_rocket_compat" name="' . $option_key . '[general][otto_wp_rocket_compat]">';
                echo '<option value="auto"' . selected($value, 'auto', false) . '>Auto (Recommended)</option>';
                echo '<option value="buffer"' . selected($value, 'buffer', false) . '>Buffer Mode (Faster)</option>';
                echo '<option value="http"' . selected($value, 'http', false) . '>HTTP Mode (Safer)</option>';
                echo '<option value="disable_otto"' . selected($value, 'disable_otto', false) . '>Disable ' . esc_html($whitelabel_otto_name) . ' when WP Rocket is active</option>';
                echo '</select>';

                if ($wp_rocket_active) {
                    echo '<p class="description" style="color: #0073aa; margin-top: 8px;">✓ WP Rocket detected - compatibility mode active</p>';
                }

                echo '<p class="description" style="margin-top: 8px;">';
                echo '<strong>Auto (Recommended):</strong> Allows both ' . esc_html($whitelabel_otto_name) . ' and WP Rocket to work together. Does not set DONOTCACHEPAGE unless required (Brizy pages, SG Optimizer conflicts).<br>';
                echo '<strong>Buffer Mode:</strong> Forces output buffering method. Fastest but may conflict with some configurations.<br>';
                echo '<strong>HTTP Mode:</strong> Forces internal HTTP fetch method. Slower but more compatible.<br>';
                echo '<strong>Disable ' . esc_html($whitelabel_otto_name) . ':</strong> Completely disables ' . esc_html($whitelabel_otto_name) . ' when WP Rocket is detected. Use if issues persist.';
                echo '</p>';
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        /**
         * BOT DETECTION SETTINGS FOR OTTO
         * @since 1.0.0
         */

        # Checkbox to disable OTTO for bot traffic
        add_settings_field(
            'otto_disable_for_bots',
            'Disable ' . $whitelabel_otto_name . ' for Bot Traffic',
            function() use ($whitelabel_otto_name, $option_key) {
                $otto_disable_for_bots = Metasync::get_option('general')['otto_disable_for_bots'] ?? false;
                printf(
                    '<input type="checkbox" id="otto_disable_for_bots" name="' . $option_key . '[general][otto_disable_for_bots]" value="true" %s />',
                    filter_var($otto_disable_for_bots, FILTER_VALIDATE_BOOLEAN) ? 'checked' : ''
                );
                printf('<span class="description"> Skip %s processing for detected bots (search engines, crawlers, SEO tools) to reduce unnecessary API calls. View bot statistics in the Bot Statistics page under the SEO dropdown.</span>', esc_html($whitelabel_otto_name));
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        # Textarea for bot whitelist
        add_settings_field(
            'otto_bot_whitelist',
            'Bot Whitelist',
            function() use ($whitelabel_otto_name, $option_key) {
                $otto_bot_whitelist = Metasync::get_option('general')['otto_bot_whitelist'] ?? '';
                printf(
                    '<textarea id="otto_bot_whitelist" name="' . $option_key . '[general][otto_bot_whitelist]" rows="5" cols="50" style="width: 100%%; max-width: 500px; font-family: monospace;">%s</textarea>',
                    esc_textarea($otto_bot_whitelist)
                );
                echo '<p class="description">';
                echo 'Enter bot names or user-agent patterns (one per line) that should <strong>always</strong> be processed by ' . esc_html($whitelabel_otto_name) . ', even when "Disable for Bots" is enabled.<br>';
                echo '<strong>Example:</strong><br>';
                echo 'Googlebot<br>';
                echo 'Bingbot';
                echo '</p>';
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        # Textarea for bot blacklist
        add_settings_field(
            'otto_bot_blacklist',
            'Bot Blacklist',
            function() use ($whitelabel_otto_name, $option_key) {
                $otto_bot_blacklist = Metasync::get_option('general')['otto_bot_blacklist'] ?? '';
                printf(
                    '<textarea id="otto_bot_blacklist" name="' . $option_key . '[general][otto_bot_blacklist]" rows="5" cols="50" style="width: 100%%; max-width: 500px; font-family: monospace;">%s</textarea>',
                    esc_textarea($otto_bot_blacklist)
                );
                echo '<p class="description">';
                echo 'Enter bot names or user-agent patterns (one per line) that should <strong>always</strong> be blocked from ' . esc_html($whitelabel_otto_name) . ' processing, regardless of other settings.<br>';
                echo '<strong>Example:</strong><br>';
                echo 'BadBot<br>';
                echo 'MaliciousCrawler';
                echo '</p>';
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );


        # Link button to Bot Statistics page
        add_settings_field(
            'otto_bot_statistics_link',
            'Bot Detection Statistics',
            function() use ($page_slug) {
                printf(
                    '<a href="%s" class="button button-secondary"><span>🤖</span> View Bot Statistics</a>',
                    esc_url(admin_url('admin.php?page=' . $page_slug . '-bot-statistics'))
                );
                printf('<p class="description">View detailed bot detection statistics, breakdown by bot type, and unique bot entries with hit counts.</p>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        # END BOT DETECTION SETTINGS

        add_settings_field(
            'periodic_clear_ottopage_cache',
            'Clear Page Cache',
            function() use ($option_key) {
                $periodic_clear_ottopage_cache = Metasync::get_option('general')['periodic_clear_ottopage_cache'] ?? 'default';
                printf('<select style = "width : 250px" name="' . $option_key . '[general][periodic_clear_ottopage_cache]" id="heading_style">');
                printf('<option value="24" '. selected($periodic_clear_ottopage_cache, '24', false) . '>Clear Daily</option>');
                printf('<option value="36" '. selected($periodic_clear_ottopage_cache, '36', false) . '>Clear Every 2 days</option>');
                printf('<option value="40" '. selected($periodic_clear_ottopage_cache, '40', false) . '>Clear Weekly</option>');
                printf('<option value="0"'.selected($periodic_clear_ottopage_cache, '0', false).'>Clear Monthly (Default)</option>');
                printf('</select>'); 

                # get last cleared timestamp
                $timestamp = get_option('metasync_refresh_all_caches')['pages'] ?? false;
                
                if($timestamp > 0){
                    printf(
                        '<p class="descriptionValue">last Cleared : '.date('y-m-d H:i:s', $timestamp).'</p>'
                    );
                }

            },
            $page_slug . '_otto_cache',
            $SECTION_METASYNC
        );

        add_settings_field(
            'periodic_clear_ottopost_cache',
            'Clear Post Cache',
            function() use ($option_key) {
                $periodic_clear_ottopost_cache = Metasync::get_option('general')['periodic_clear_ottopost_cache'] ?? 'default';
                printf('<select style = "width : 250px" name="' . $option_key . '[general][periodic_clear_ottopost_cache]" id="heading_style">');
                printf('<option value="24" '. selected($periodic_clear_ottopost_cache, '24', false) . '>Clear Daily</option>');
                printf('<option value="36" '. selected($periodic_clear_ottopost_cache, '36', false) . '>Clear Every 2 days</option>');
                printf('<option value="40" '. selected($periodic_clear_ottopost_cache, '40', false) . '>Clear Weekly</option>');
                printf('<option value="0"'.selected($periodic_clear_ottopost_cache, '0', false).'>Clear Monthly (Default)</option>');
                printf('</select>'); 

                # get last cleared timestamp
                $timestamp = get_option('metasync_refresh_all_caches')['posts'] ?? false;
                
                if($timestamp > 0){
                    printf(
                        '<p class="descriptionValue">last Cleared : '.date('y-m-d H:i:s', $timestamp).'</p>'
                    );
                }
            },
            $page_slug . '_otto_cache',
            $SECTION_METASYNC
        );

        add_settings_field(
            'periodic_clear_otto_cache',
            'Clear all cache',
            function() use ($option_key) {
                $periodic_clear_otto_cache = Metasync::get_option('general')['periodic_clear_otto_cache'] ?? 'default';
                printf('<select style = "width : 250px" name="' . $option_key . '[general][periodic_clear_otto_cache]" id="heading_style">');
                printf('<option value="24" '. selected($periodic_clear_otto_cache, '24', false) . '>Clear Daily</option>');
                printf('<option value="36" '. selected($periodic_clear_otto_cache, '36', false) . '>Clear Every 2 days</option>');
                printf('<option value="40" '. selected($periodic_clear_otto_cache, '40', false) . '>Clear Weekly</option>');
                printf('<option value="0"'.selected($periodic_clear_otto_cache, '0', false).'>Clear Monthly (Default)</option>');
                printf('</select>'); 

                # get last cleared timestamp
                $timestamp = get_option('metasync_refresh_all_caches')['general'] ?? false;
                
                if($timestamp > 0){
                    printf(
                        '<p class="descriptionValue">last Cleared : '.date('y-m-d H:i:s', $timestamp).'</p>'
                    );
                }

            },
            $page_slug . '_otto_cache',
            $SECTION_METASYNC
        );

        # END SERVER SIDE RENDERING SETTINGS

        // Meta Box Visibility Controls
        add_settings_field(
            'disable_common_robots_metabox',
            'Disable Common Robots Meta Box',
            function() use ($option_key) {
                $disabled = Metasync::get_option('general')['disable_common_robots_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_common_robots_metabox" name="' . $option_key . '[general][disable_common_robots_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Common Robots Meta box on post/page edit screens</span>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'disable_advance_robots_metabox',
            'Disable Advance Robots Meta Box',
            function() use ($option_key) {
                $disabled = Metasync::get_option('general')['disable_advance_robots_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_advance_robots_metabox" name="' . $option_key . '[general][disable_advance_robots_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Advance Robots Meta box on post/page edit screens</span>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'disable_redirection_metabox',
            'Disable Redirection Meta Box',
            function() use ($option_key) {
                $disabled = Metasync::get_option('general')['disable_redirection_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_redirection_metabox" name="' . $option_key . '[general][disable_redirection_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Redirection meta box on post/page edit screens</span>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'disable_canonical_metabox',
            'Disable Canonical Meta Box',
            function() use ($option_key) {
                $disabled = Metasync::get_option('general')['disable_canonical_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_canonical_metabox" name="' . $option_key . '[general][disable_canonical_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Canonical meta box on post/page edit screens</span>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'disable_social_opengraph_metabox',
            'Disable Social Media & Open Graph Meta Box',
            function() use ($option_key) {
                $disabled = Metasync::get_option('general')['disable_social_opengraph_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_social_opengraph_metabox" name="' . $option_key . '[general][disable_social_opengraph_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Social Media & Open Graph meta box on post/page edit screens</span>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'disable_schema_markup_metabox',
            'Disable Schema Markup Meta Box',
            function() use ($option_key) {
                $disabled = Metasync::get_option('general')['disable_schema_markup_metabox'] ?? false;
                printf(
                    '<input type="checkbox" id="disable_schema_markup_metabox" name="' . $option_key . '[general][disable_schema_markup_metabox]" value="1" %s />',
                    $disabled ? 'checked' : ''
                );
                printf('<span class="description"> Hide the Schema Markup meta box on post/page edit screens</span>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'open_external_links',
            'Open External Links in New Tab/Window',
            function() use ($option_key) {
                $enabled = Metasync::get_option('general')['open_external_links'] ?? false;
                printf(
                    '<input type="checkbox" id="open_external_links" name="' . $option_key . '[general][open_external_links]" value="1" %s />',
                    $enabled ? 'checked' : ''
                );
                printf('<span class="description"> Automatically add <code>target="_blank"</code> attribute to external links appearing in your posts, pages, and other post types when rendered by Otto. The attribute is applied when the url is displayed.</span>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        add_settings_field(
            'permalink_structure',
            'The Permalink setting of website',
            function() {
                
                $current_permalink_structure = get_option('permalink_structure');
                $current_rewrite_rules = get_option('rewrite_rules');
                // Check if the current permalink structure is set to "Plain"
                if (($current_permalink_structure == '/%post_id%/' || $current_permalink_structure == '') && $current_rewrite_rules == '') {
                    printf('<span class="description" style="color:#ff0000;opacity:1;">To ensure compatibility, Please Update your Permalink structure to any option other than "plain. For any Inquiries contact support <a href="' . get_admin_url() . 'options-permalink.php">Check Setting</a> </span>');
                } else {
                    printf('<span class="description" style="color:#008000;opacity:1;">Permalink is Okay </span>');
                }
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        # Add Hide Dashboard Framework setting
        add_settings_field(
            'hide_dashboard_framework',
            'Hide Dashboard',
            function() use ($option_key) {
                $hide_dashboard = Metasync::get_option('general')['hide_dashboard_framework'] ?? '';
                printf(
                    '<input type="checkbox" id="hide_dashboard_framework" name="' . $option_key . '[general][hide_dashboard_framework]" value="true" %s />',
                    isset($hide_dashboard) && $hide_dashboard == 'true' ? 'checked' : ''
                );
                printf('<span class="description"> Hide the %s dashboard</span>', esc_html(Metasync_Settings_Registration::instance()->get_effective_menu_title()));
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        // REMOVED (CVE-2025-14386): disable_single_signup_login setting was removed

        # Adding the "Show Admin Bar Status" setting
        add_settings_field(
            'show_admin_bar_status',
            'Show ' . $this->get_effective_menu_title() . ' Status in Admin Bar',
            function() use ($option_key) {
                $show_admin_bar = Metasync::get_option('general')['show_admin_bar_status'] ?? true;
                printf(
                    '<input type="checkbox" id="show_admin_bar_status" name="' . $option_key . '[general][show_admin_bar_status]" value="true" %s />',
                    $show_admin_bar ? 'checked' : ''
                );
                printf('<span class="description">Show the %s status indicator in the WordPress admin bar.</span>', esc_html(Metasync::get_effective_plugin_name()));
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        # Adding the "Enable Auto-updates" setting
        add_settings_field(
            'enable_auto_updates',
            'Enable Automatic Updates',
            function() use ($option_key) {
                $enable_auto_updates = Metasync::get_option('general')['enable_auto_updates'] ?? false;
                printf(
                    '<input type="checkbox" id="enable_auto_updates" name="' . $option_key . '[general][enable_auto_updates]" value="true" %s />',
                    $enable_auto_updates ? 'checked' : ''
                );
                printf('<span class="description">Allow WordPress to automatically update this plugin when new versions are available.</span>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );

        # Add User Role Sync Setting
        add_settings_field(
            'content_genius_sync_roles',
            'Content Genius User Roles to Sync',
            function() use ($option_key) {
                $selected_roles = Metasync::get_option('general')['content_genius_sync_roles'] ?? array();
                
                # If it's a string (single role from old version), convert to array
                if (!is_array($selected_roles)) {
                    $selected_roles = array($selected_roles);
                }
                
                # Get all WordPress user roles safely
                if (!function_exists('wp_roles')) {
                    require_once(ABSPATH . 'wp-admin/includes/user.php');
                }
                global $wp_roles;
                
                # Safety check for $wp_roles
                if (!isset($wp_roles) || !is_object($wp_roles)) {
                    $wp_roles = new WP_Roles();
                }
                
                $all_roles = $wp_roles->roles;
                
                # Start container
                ?>
                <div class="metasync-role-selector-container">
                    
                    <!-- All Roles Option -->
                    <label class="metasync-role-option-all">
                        <input type="checkbox" 
                               name="<?php echo esc_attr($option_key); ?>[general][content_genius_sync_roles][]" 
                               value="all" 
                               <?php checked(empty($selected_roles) || in_array('all', $selected_roles), true); ?> />
                        <strong>All Roles</strong>
                    </label>
                    
                    <!-- Divider -->
                    <hr class="metasync-role-divider">
                    
                    <?php
                    # Display each role as a checkbox
                    if (!empty($all_roles) && is_array($all_roles)) {
                        foreach ($all_roles as $role_key => $role_details) {
                            # Safety check for role data
                            if (!isset($role_details['name'])) {
                                continue;
                            }
                            
                            $is_checked = in_array($role_key, $selected_roles);
                            $role_name = translate_user_role($role_details['name']);
                            ?>
                            <label class="metasync-role-option">
                                <input type="checkbox" 
                                       name="<?php echo esc_attr($option_key); ?>[general][content_genius_sync_roles][]" 
                                       value="<?php echo esc_attr($role_key); ?>" 
                                       <?php checked($is_checked, true); ?> />
                                <span class="metasync-role-label"><?php echo esc_html($role_name); ?></span>
                            </label>
                            <?php
                        }
                    }
                    ?>
                    
                </div>
                
                <!-- Description -->
                <p class="description" style="margin-top: 10px;">
                    <strong>ℹ️ How it works:</strong> 
                    Select which user roles should be synced with Content Genius. 
                    If <strong>"All Roles"</strong> is selected or none are selected, all users will be synced. 
                    Otherwise, only users with the selected roles will be included in the sync.
                </p>
                <?php
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );
        add_settings_field(
            'import_external_data',
            'Import settings and data from SEO Plugins',
            function() {
                printf(
                    '<a href="%s" class="button button-secondary"><span>📥</span> Import from SEO Plugins</a>',
                    esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-import-external'))
                );
                printf('<p class="description">Import settings and data from other SEO plugins (Yoast, Rank Math, AIOSEO, etc).</p>');
            },
            $page_slug . '_general',
            $SECTION_METASYNC
        );



        /*
        add field to save color for elementor and assign custom color to the heading
        */
        if(is_admin()){
        add_settings_field(
            'white_label_plugin_name',
            'Plugin Name',
           function() use ($option_key) {
            $value = Metasync::get_option('general')['white_label_plugin_name'] ?? '';   
            printf('<input type="text" name="' . $option_key . '[general][white_label_plugin_name]" value="' . esc_attr($value) . '" maxlength="16" />');
            printf('<p class="description">This name will be used for general plugin branding (WordPress menus, page titles, and system messages). Maximum 16 characters.</p>');
           },
           $page_slug . '_branding',
                $SECTION_METASYNC
        );
        
        add_settings_field(
            'whitelabel_otto_name',
                'OTTO Name',
            function() use ($option_key) {
                $value = Metasync::get_option('general')['whitelabel_otto_name'] ?? '';
                printf('<input type="text" name="' . $option_key . '[general][whitelabel_otto_name]" value="' . esc_attr($value) . '" />');
                $example_name = !empty($value) ? $value : 'OTTO';
                printf('<p class="description">This name will be used for OTTO feature references (e.g., "Enable %s Server Side Rendering").</p>', esc_html($example_name));
            },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );
        
        add_settings_field(
            'whitelabel_logo_url',
            'Logo URL',
            function() use ($option_key) {
                $whitelabel_settings = Metasync::get_whitelabel_settings();
                $value = $whitelabel_settings['logo'] ?? '';   
                printf('<input type="url" name="' . $option_key . '[whitelabel][logo]" value="' . esc_attr($value) . '" size="60" />');
                printf('<p class="description">Enter the URL of your logo image. Leave blank to use the default %s logo.</p>', esc_html(Metasync::get_effective_plugin_name()));
            },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );
        
                add_settings_field(
            'whitelabel_domain_url',
            'Dashboard URL',
            function() use ($option_key) {
                $whitelabel_settings = Metasync::get_whitelabel_settings();
                $value = $whitelabel_settings['domain'] ?? '';   
                printf('<input type="url" name="' . $option_key . '[whitelabel][domain]" value="' . esc_attr($value) . '" size="60" />');
                printf('<p class="description">Enter your whitelabel dashboard URL (e.g., https://yourdashboard.com). Used for branding purposes.</p>');
           },
           $page_slug . '_branding',
                $SECTION_METASYNC
        );
        add_settings_field(
            'white_label_plugin_description',
            'Plugin Description',
            function() use ($option_key) {
                $value = Metasync::get_option('general')['white_label_plugin_description'] ?? '';   
                printf('<input type="text" name="' . $option_key . '[general][white_label_plugin_description]" value="' . esc_attr($value) . '" />');      
               },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );
    
        add_settings_field(
            'white_label_plugin_author',
            'Author',
           function() use ($option_key) {
            $value = Metasync::get_option('general')['white_label_plugin_author'] ?? '';   
            printf('<input type="text" name="' . $option_key . '[general][white_label_plugin_author]" value="' . esc_attr($value) . '" />');  
           },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );
            
        add_settings_field(
            'white_label_plugin_author_uri',
            'Author URI',
            function() use ($option_key) {
                $value = Metasync::get_option('general')['white_label_plugin_author_uri'] ?? '';   
               # printf('<input type="text" name="' . $option_key . '[general][white_label_plugin_author_uri]" value="' . esc_attr($value) . '" />');
               # Fixed printf usage
                printf('<input type="text" name="%s" value="%s" />',  esc_attr($option_key . '[general][white_label_plugin_author_uri]'),  esc_attr($value) );
              
            },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );
        add_settings_field(
            'white_label_plugin_uri',
            'Plugin URI',
            function() use ($option_key) {
                $value = Metasync::get_option('general')['white_label_plugin_uri'] ?? ''; // New option for Plugin URI
                printf('<input type="text" name="' . $option_key . '[general][white_label_plugin_uri]" value="' . esc_attr($value) . '" />');
            },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );
            register_setting(Metasync_Admin::option_group,
            Metasync_Admin::option_key,
            array(self::instance(), 'sanitize')
            );  

            // DEPRECATED: Menu Name and Menu Title fields removed
            add_settings_field(
                'white_label_plugin_menu_slug',
                'Menu Slug',
                function() use ($option_key) {
                    $value = Metasync::get_option('general')['white_label_plugin_menu_slug'] ?? '';   
                    printf('<input type="text" name="' . $option_key . '[general][white_label_plugin_menu_slug]" value="' . esc_attr($value) . '" />');        
                },
                $page_slug . '_branding',
                $SECTION_METASYNC
            );
            add_settings_field(
                'white_label_plugin_menu_icon',
                'Menu Icon',
                function() use ($option_key) {
                    $value = Metasync::get_option('general')['white_label_plugin_menu_icon'] ?? '';   
                    printf('<input type="text" name="' . $option_key . '[general][white_label_plugin_menu_icon]" value="' . esc_attr($value) . '" />');
                },
                $page_slug . '_branding',
                $SECTION_METASYNC
            );
        }
        // HIDDEN: Choose Style Option setting
        /*
        add_settings_field(
            'enabled_plugin_css',
            'Choose Style Option',
            function() use ($option_key) {
                $enabled_plugin_css = Metasync::get_option('general')['enabled_plugin_css'] ?? '';                
                
                // Output radio button for Default Style.css active
              
                    printf(
                        '<input type="radio" id="enable_default" name="' . $option_key . '[general][enabled_plugin_css]" value="default" %s />',
                        ($enabled_plugin_css == 'default'||$enabled_plugin_css =='') ? 'checked' : ''
                    );
                    printf('<label for="enable_default">Default</label><br>');
                
        
                // Output radio button for Metasync Style
                printf(
                    '<input type="radio" id="enable_metasync" name="' . $option_key . '[general][enabled_plugin_css]" value="metasync" %s  />',
                    ($enabled_plugin_css == 'metasync') ? 'checked' : ''
                );
                printf('<label for="enable_metasync">Metasync Style</label>');
        
                printf('<p class="description"> Choose the default page Style Sheet: Default or MetaSync.</p>');
            },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );
        */

        add_settings_field(
            'enabled_elementor_plugin_css',
            'Choose Elementor Font Style',
            function() use ($option_key) {
                $enabled_elementor_plugin_css = Metasync::get_option('general')['enabled_elementor_plugin_css'] ?? 'default';
                printf('<select name="' . $option_key . '[general][enabled_elementor_plugin_css]" id="heading_style">');
                printf('<option value="default"'.selected($enabled_elementor_plugin_css, 'default', false).'>Default</option>');
                printf('<option value="custom" '. selected($enabled_elementor_plugin_css, 'custom', false) . '>Custom</option>');
                printf('</select>'); 
            },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );
        add_settings_field(
            'enabled_elementor_plugin_css_color',
            'Choose Elementor Font Color',
            function() use ($option_key) {
                $enabled_elementor_plugin_css_color = Metasync::get_option('general')['enabled_elementor_plugin_css_color'] ?? '#000000';                          
                printf('<input type="color" id="elementor_default_color_metasync" name="' . $option_key . '[general][enabled_elementor_plugin_css_color]" value="'.$enabled_elementor_plugin_css_color.'">');       
            },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );

        add_settings_field(
            'whitelabel_settings_password',
            'Settings Password',
            function() use ($option_key) {
                $whitelabel_settings = Metasync::get_whitelabel_settings();
                $value = $whitelabel_settings['settings_password'] ?? '';
                printf('<input type="password" name="' . $option_key . '[whitelabel][settings_password]" value="' . esc_attr($value) . '" size="30" autocomplete="new-password" />');
                printf('<p class="description">Set a custom password to protect the branding settings section.</p>');
            },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );

        add_settings_field(
            'whitelabel_recovery_email',
            'Recovery Email',
            function() use ($option_key) {
                $whitelabel_settings = Metasync::get_whitelabel_settings();
                $value = $whitelabel_settings['recovery_email'] ?? '';
                $has_password = !empty($whitelabel_settings['settings_password']);
                printf('<input type="email" name="' . $option_key . '[whitelabel][recovery_email]" value="' . esc_attr($value) . '" size="30" autocomplete="email" %s />', $has_password ? 'required' : '');
                printf('<p class="description">Email address to receive password recovery. <strong>Required when password is set.</strong></p>');
            },
            $page_slug . '_branding',
            $SECTION_METASYNC
        );


    }

    /**
     * Sanitize each setting field as needed.
     *
     * Formerly Metasync_Admin::sanitize().
     *
     * @param array $input Contains all settings fields as array keys
     * @return array Sanitized settings
     */
    public function sanitize($input)
    {
        $new_input = Metasync::get_option();
        $whitelabel_validation_failed = false;

        # Determine which tab is being submitted (same logic as AJAX handler)
        # Check POST first (from AJAX), then GET (from form action URL)
        $active_tab = 'general';
        if (isset($_POST['active_tab'])) {
            $active_tab = sanitize_text_field($_POST['active_tab']);
        } elseif (isset($_GET['tab'])) {
            $active_tab = sanitize_text_field($_GET['tab']);
        } elseif (isset($_POST['_wp_http_referer'])) {
            # Parse referer URL to get tab parameter
            $referer = wp_parse_url($_POST['_wp_http_referer']);
            if (isset($referer['query'])) {
                parse_str($referer['query'], $referer_params);
                if (isset($referer_params['tab'])) {
                    $active_tab = sanitize_text_field($referer_params['tab']);
                }
            }
        }
        $general_tab_submitted = ($active_tab === 'general');

        // General Settings
        if (isset($input['general']['apikey'])) {
            $new_input['general']['apikey'] = sanitize_text_field($input['general']['apikey']);
        }

        // Meta Box Visibility Settings
        if (isset($input['general']) && $general_tab_submitted) {
            $checkbox_fields = [
                'disable_common_robots_metabox',
                'disable_advance_robots_metabox',
                'disable_redirection_metabox',
                'disable_canonical_metabox',
                'disable_social_opengraph_metabox',
                'disable_schema_markup_metabox',
                'open_external_links'
            ];

            foreach ($checkbox_fields as $field) {
                if (isset($input['general'][$field])) {
                    $new_input['general'][$field] = filter_var($input['general'][$field], FILTER_VALIDATE_BOOLEAN);
                } else {
                    $new_input['general'][$field] = false;
                }
            }
        }

        // Site Verification Settings
        if (isset($input['searchengines']['bing_site_verification'])) {
            $new_input['searchengines']['bing_site_verification'] = sanitize_text_field($input['searchengines']['bing_site_verification']);
        }
        if (isset($input['searchengines']['baidu_site_verification'])) {
            $new_input['searchengines']['baidu_site_verification'] = sanitize_text_field($input['searchengines']['baidu_site_verification']);
        }
        if (isset($input['searchengines']['alexa_site_verification'])) {
            $new_input['searchengines']['alexa_site_verification'] = sanitize_text_field($input['searchengines']['alexa_site_verification']);
        }
        if (isset($input['searchengines']['yandex_site_verification'])) {
            $new_input['searchengines']['yandex_site_verification'] = sanitize_text_field($input['searchengines']['yandex_site_verification']);
        }
        if (isset($input['searchengines']['google_site_verification'])) {
            $new_input['searchengines']['google_site_verification'] = sanitize_text_field($input['searchengines']['google_site_verification']);
        }
        if (isset($input['searchengines']['pinterest_site_verification'])) {
            $new_input['searchengines']['pinterest_site_verification'] = sanitize_text_field($input['searchengines']['pinterest_site_verification']);
        }
        if (isset($input['searchengines']['norton_save_site_verification'])) {
            $new_input['searchengines']['norton_save_site_verification'] = sanitize_text_field($input['searchengines']['norton_save_site_verification']);
        }

        // Local Business SEO Settings
        if (isset($input['localseo']['local_seo_person_organization'])) {
            $new_input['localseo']['local_seo_person_organization'] = sanitize_text_field($input['localseo']['local_seo_person_organization']);
        }
        if (isset($input['localseo']['local_seo_name'])) {
            $new_input['localseo']['local_seo_name'] = sanitize_text_field($input['localseo']['local_seo_name']);
        }
        if (isset($input['localseo']['local_seo_logo'])) {
            $new_input['localseo']['local_seo_logo'] = sanitize_url($input['localseo']['local_seo_logo']);
        }
        if (isset($input['localseo']['local_seo_url'])) {
            $new_input['localseo']['local_seo_url'] = sanitize_url($input['localseo']['local_seo_url']);
        }
        if (isset($input['localseo']['local_seo_email'])) {
            $new_input['localseo']['local_seo_email'] = sanitize_email($input['localseo']['local_seo_email']);
        }
        if (isset($input['localseo']['local_seo_phone'])) {
            $new_input['localseo']['local_seo_phone'] = sanitize_text_field($input['localseo']['local_seo_phone']);
        }
        if (isset($input['localseo']['address']['street'])) {
            $new_input['localseo']['address']['street'] = sanitize_text_field($input['localseo']['address']['street']);
        }
        if (isset($input['localseo']['address']['locality'])) {
            $new_input['localseo']['address']['locality'] = sanitize_text_field($input['localseo']['address']['locality']);
        }
        if (isset($input['localseo']['address']['region'])) {
            $new_input['localseo']['address']['region'] = sanitize_text_field($input['localseo']['address']['region']);
        }
        if (isset($input['localseo']['address']['postancode'])) {
            $new_input['localseo']['address']['postancode'] = sanitize_text_field($input['localseo']['address']['postancode']);
        }
        if (isset($input['localseo']['address']['country'])) {
            $new_input['localseo']['address']['country'] = sanitize_text_field($input['localseo']['address']['country']);
        }
        if (isset($input['localseo']['local_seo_business_type'])) {
            $new_input['localseo']['local_seo_business_type'] = sanitize_text_field($input['localseo']['local_seo_business_type']);
        }
        if (isset($input['localseo']['local_seo_hours_format'])) {
            $new_input['localseo']['local_seo_hours_format'] = sanitize_text_field($input['localseo']['local_seo_hours_format']);
        }
        if (isset($input['localseo']['days'])) {
            $new_input['localseo']['days'] = sanitize_text_field($input['localseo']['days']);
        }
        if (isset($input['localseo']['times'])) {
            $new_input['localseo']['times'] = sanitize_text_field($input['localseo']['times']);
        }
        if (isset($input['localseo']['phonetype'])) {
            $new_input['localseo']['phonetype'] = sanitize_text_field($input['localseo']['phonetype']);
        }
        if (isset($input['localseo']['phonenumber'])) {
            $new_input['localseo']['phonenumber'] = sanitize_text_field($input['localseo']['phonenumber']);
        }
        if (isset($input['localseo']['local_seo_price_range'])) {
            $new_input['localseo']['local_seo_price_range'] = sanitize_text_field($input['localseo']['local_seo_price_range']);
        }
        if (isset($input['localseo']['local_seo_about_page'])) {
            $new_input['localseo']['local_seo_about_page'] = sanitize_text_field($input['localseo']['local_seo_about_page']);
        }
        if (isset($input['localseo']['local_seo_contact_page'])) {
            $new_input['localseo']['local_seo_contact_page'] = sanitize_text_field($input['localseo']['local_seo_contact_page']);
        }
        if (isset($input['localseo']['local_seo_map_key'])) {
            $new_input['localseo']['local_seo_map_key'] = sanitize_text_field($input['localseo']['local_seo_map_key']);
        }
        if (isset($input['localseo']['local_seo_geo_coordinates'])) {
            $new_input['localseo']['local_seo_geo_coordinates'] = sanitize_text_field($input['localseo']['local_seo_geo_coordinates']);
        }

        // Code Snippets Settings
        if (isset($input['codesnippets']['header_snippet'])) {
            $new_input['codesnippets']['header_snippet'] = sanitize_text_field($input['codesnippets']['header_snippet']);
        }
        if (isset($input['codesnippets']['footer_snippet'])) {
            $new_input['codesnippets']['footer_snippet'] = sanitize_text_field($input['codesnippets']['footer_snippet']);
        }


        // Optimal Settings
        if (isset($input['optimal_settings']['no_index_posts'])) {
            $new_input['optimal_settings']['no_index_posts'] = boolval($input['optimal_settings']['no_index_posts']);
        }
        if (isset($input['optimal_settings']['no_follow_links'])) {
            $new_input['optimal_settings']['no_follow_links'] = boolval($input['optimal_settings']['no_follow_links']);
        }
        if (isset($input['optimal_settings']['open_external_links'])) {
            $new_input['optimal_settings']['open_external_links'] = boolval($input['optimal_settings']['open_external_links']);
        }
        if (isset($input['optimal_settings']['add_alt_image_tags'])) {
            $new_input['optimal_settings']['add_alt_image_tags'] = boolval($input['optimal_settings']['add_alt_image_tags']);
        }
        if (isset($input['optimal_settings']['add_title_image_tags'])) {
            $new_input['optimal_settings']['add_title_image_tags'] = boolval($input['optimal_settings']['add_title_image_tags']);
        }

        // Site Information - Optimal Settings
        if (isset($input['optimal_settings']['site_info']['type'])) {
            $new_input['optimal_settings']['site_info']['type'] = sanitize_text_field($input['optimal_settings']['site_info']['type']);
        }
        if (isset($input['optimal_settings']['site_info']['business_type'])) {
            $new_input['optimal_settings']['site_info']['business_type'] = sanitize_text_field($input['optimal_settings']['site_info']['business_type']);
        }
        if (isset($input['optimal_settings']['site_info']['company_name'])) {
            $new_input['optimal_settings']['site_info']['company_name'] = sanitize_text_field($input['optimal_settings']['site_info']['company_name']);
        }
        if (isset($input['optimal_settings']['site_info']['google_logo'])) {
            $new_input['optimal_settings']['site_info']['google_logo'] = sanitize_url($input['optimal_settings']['site_info']['google_logo']);
        }
        if (isset($input['optimal_settings']['site_info']['social_share_image'])) {
            $new_input['optimal_settings']['site_info']['social_share_image'] = sanitize_url($input['optimal_settings']['site_info']['social_share_image']);
        }

        // Common Setting - Global Settings
        $common_robots_field = isset($input['common_robots_meta']) ? 'common_robots_meta' : 'common_robots_mata';

        if (isset($input[$common_robots_field]['index'])) {
            $new_input['common_robots_meta']['index'] = boolval($input[$common_robots_field]['index']);
        }
        if (isset($input[$common_robots_field]['noindex'])) {
            $new_input['common_robots_meta']['noindex'] = boolval($input[$common_robots_field]['noindex']);
        }
        if (isset($input[$common_robots_field]['nofollow'])) {
            $new_input['common_robots_meta']['nofollow'] = boolval($input[$common_robots_field]['nofollow']);
        }
        if (isset($input[$common_robots_field]['noarchive'])) {
            $new_input['common_robots_meta']['noarchive'] = boolval($input[$common_robots_field]['noarchive']);
        }
        if (isset($input[$common_robots_field]['noimageindex'])) {
            $new_input['common_robots_meta']['noimageindex'] = boolval($input[$common_robots_field]['noimageindex']);
        }
        if (isset($input[$common_robots_field]['nosnippet'])) {
            $new_input['common_robots_meta']['nosnippet'] = boolval($input[$common_robots_field]['nosnippet']);
        }

        // Advance Setting - Global Settings
        $advance_robots_field = isset($input['advance_robots_meta']) ? 'advance_robots_meta' : 'advance_robots_mata';

        if (isset($input[$advance_robots_field]['max-snippet']['enable'])) {
            $new_input['advance_robots_meta']['max-snippet']['enable'] = boolval($input[$advance_robots_field]['max-snippet']['enable']);
        }
        if (isset($input[$advance_robots_field]['max-snippet']['length'])) {
            $new_input['advance_robots_meta']['max-snippet']['length'] = sanitize_text_field($input[$advance_robots_field]['max-snippet']['length']);
        }
        if (isset($input[$advance_robots_field]['max-video-preview']['enable'])) {
            $new_input['advance_robots_meta']['max-video-preview']['enable'] = boolval($input[$advance_robots_field]['max-video-preview']['enable']);
        }
        if (isset($input[$advance_robots_field]['max-video-preview']['length'])) {
            $new_input['advance_robots_meta']['max-video-preview']['length'] = sanitize_text_field($input[$advance_robots_field]['max-video-preview']['length']);
        }
        if (isset($input[$advance_robots_field]['max-image-preview']['enable'])) {
            $new_input['advance_robots_meta']['max-image-preview']['enable'] = boolval($input[$advance_robots_field]['max-image-preview']['enable']);
        }
        if (isset($input[$advance_robots_field]['max-image-preview']['length'])) {
            $new_input['advance_robots_meta']['max-image-preview']['length'] = sanitize_text_field($input[$advance_robots_field]['max-image-preview']['length']);
        }

        // Social meta settings
        if (isset($input['social_meta']['facebook_page_url'])) {
            $new_input['social_meta']['facebook_page_url'] = sanitize_text_field($input['social_meta']['facebook_page_url']);
        }
        if (isset($input['social_meta']['facebook_authorship'])) {
            $new_input['social_meta']['facebook_authorship'] = sanitize_text_field($input['social_meta']['facebook_authorship']);
        }
        if (isset($input['social_meta']['facebook_admin'])) {
            $new_input['social_meta']['facebook_admin'] = sanitize_text_field($input['social_meta']['facebook_admin']);
        }
        if (isset($input['social_meta']['facebook_app'])) {
            $new_input['social_meta']['facebook_app'] = sanitize_text_field($input['social_meta']['facebook_app']);
        }
        if (isset($input['social_meta']['facebook_secret'])) {
            $new_input['social_meta']['facebook_secret'] = sanitize_text_field($input['social_meta']['facebook_secret']);
        }
        if (isset($input['social_meta']['twitter_username'])) {
            $new_input['social_meta']['twitter_username'] = sanitize_text_field($input['social_meta']['twitter_username']);
        }

        # Handle whitelabel URL fields with improved empty value handling
        if (isset($input['whitelabel'])) {
            $existing_whitelabel = Metasync::get_option()['whitelabel'] ?? [];

            $general_input = $input['general'] ?? [];
            $plugin_name = isset($general_input['white_label_plugin_name']) ? trim((string) $general_input['white_label_plugin_name']) : '';
            $logo = isset($input['whitelabel']['logo']) ? trim((string) $input['whitelabel']['logo']) : '';
            $author = isset($general_input['white_label_plugin_author']) ? trim((string) $general_input['white_label_plugin_author']) : '';
            $author_uri = isset($general_input['white_label_plugin_author_uri']) ? trim((string) $general_input['white_label_plugin_author_uri']) : '';
            $plugin_uri = isset($general_input['white_label_plugin_uri']) ? trim((string) $general_input['white_label_plugin_uri']) : '';
            $domain = isset($input['whitelabel']['domain']) ? trim((string) $input['whitelabel']['domain']) : '';
            $description = isset($general_input['white_label_plugin_description']) ? trim((string) $general_input['white_label_plugin_description']) : '';

            $has_core_field = (!empty($plugin_name) || (!empty($logo) && filter_var($logo, FILTER_VALIDATE_URL)) || !empty($author));
            $has_optional_only = (!empty($author_uri) || !empty($plugin_uri) || (!empty($domain) && filter_var($domain, FILTER_VALIDATE_URL)) || !empty($description));

            if ($has_optional_only && !$has_core_field) {
                add_settings_error(
                    'metasync_options',
                    'whitelabel_partial',
                    'Add at least one of: Plugin Name, Logo URL, or Author to save whitelabel settings.',
                    'error'
                );
                $new_input['whitelabel'] = $existing_whitelabel;
                $whitelabel_validation_failed = true;
            } else {
            $new_input['whitelabel'] = $existing_whitelabel;

            if (isset($input['whitelabel']['logo'])) {
                $logo_value = trim($input['whitelabel']['logo']);
                
                if (!empty($logo_value) && filter_var($logo_value, FILTER_VALIDATE_URL)) {
                    $new_input['whitelabel']['logo'] = esc_url_raw($logo_value);
                
                } else {
                    $new_input['whitelabel']['logo'] = '';
                
                }
            }
            
            if (isset($input['whitelabel']['domain'])) {
                $domain_value = trim($input['whitelabel']['domain']);
                
                if (!empty($domain_value) && filter_var($domain_value, FILTER_VALIDATE_URL)) {
                    $new_input['whitelabel']['domain'] = esc_url_raw($domain_value);
                    
                } else {
                    $old_domain = $existing_whitelabel['domain'] ?? '';
                    $new_input['whitelabel']['domain'] = '';
                    
                    if (!empty($old_domain)) {
                        $new_input['_trigger_heartbeat_after_save'] = 'Domain cleared from: ' . $old_domain;
                    }
                }
            }
            
            if (isset($input['whitelabel']['settings_password'])) {
                $password_value = trim($input['whitelabel']['settings_password']);
                $new_input['whitelabel']['settings_password'] = sanitize_text_field($password_value);
            } else {
                if (isset($existing_whitelabel['settings_password'])) {
                    $new_input['whitelabel']['settings_password'] = $existing_whitelabel['settings_password'];
                }
            }

            if (isset($input['whitelabel']['recovery_email'])) {
                $recovery_email = trim($input['whitelabel']['recovery_email']);
                if (!empty($recovery_email)) {
                    $sanitized_email = sanitize_email($recovery_email);
                    if (is_email($sanitized_email)) {
                        $new_input['whitelabel']['recovery_email'] = $sanitized_email;
                    } else {
                        add_settings_error(
                            'metasync_messages',
                            'metasync_message',
                            __('Invalid recovery email address.', 'metasync'),
                            'error'
                        );
                    }
                } else {
                    $new_input['whitelabel']['recovery_email'] = '';
                }
            } else {
                if (isset($existing_whitelabel['recovery_email'])) {
                    $new_input['whitelabel']['recovery_email'] = $existing_whitelabel['recovery_email'];
                }
            }

            $has_password = !empty($new_input['whitelabel']['settings_password']);
            $has_recovery_email = !empty($new_input['whitelabel']['recovery_email']);
            if ($has_password && !$has_recovery_email) {
                add_settings_error(
                    'metasync_messages',
                    'metasync_message',
                    __('Password Recovery Email is required when a password is set.', 'metasync'),
                    'error'
                );
                $new_input['whitelabel']['settings_password'] = '';
            }

            if (isset($input['whitelabel']['access_control'])) {
                $new_input['whitelabel']['access_control'] = Metasync_Access_Control::sanitize_access_control($input['whitelabel']['access_control']);
            }

            $new_input['whitelabel']['updated_at'] = time();
            }
        } else {
            $existing_whitelabel = Metasync::get_option()['whitelabel'] ?? [];
            $has_existing_whitelabel = !empty($existing_whitelabel['domain']) || !empty($existing_whitelabel['logo']);
            
            if ($has_existing_whitelabel) {
                $new_input['whitelabel'] = [
                    'is_whitelabel' => false,
                    'domain' => '',
                    'logo' => '', 
                    'company_name' => '',
                    'updated_at' => time()
                ];
                
                if (!empty($existing_whitelabel['domain'])) {
                    delete_transient('metasync_heartbeat_status_cache');
                    do_action('metasync_trigger_immediate_heartbeat', 'Whitelabel settings cleared - domain changed to default');
                }
            }
        }

        // Indexation Control Settings
        if (isset($input['seo_controls']['index_date_archives'])) {
            $new_input['seo_controls']['index_date_archives'] = boolval($input['seo_controls']['index_date_archives']);
        }
        if (isset($input['seo_controls']['index_tag_archives'])) {
            $new_input['seo_controls']['index_tag_archives'] = boolval($input['seo_controls']['index_tag_archives']);
        }
        if (isset($input['seo_controls']['index_author_archives'])) {
            $new_input['seo_controls']['index_author_archives'] = boolval($input['seo_controls']['index_author_archives']);
        }
        if (isset($input['seo_controls']['index_format_archives'])) {
            $new_input['seo_controls']['index_format_archives'] = boolval($input['seo_controls']['index_format_archives']);
        }

        // Handle post-save heartbeat trigger for domain changes
        if (isset($new_input['_trigger_heartbeat_after_save'])) {
            $context = $new_input['_trigger_heartbeat_after_save'];
            unset($new_input['_trigger_heartbeat_after_save']);
            
            add_action('updated_option_metasync_options', function() use ($context) {
                delete_transient('metasync_heartbeat_status_cache');
                do_action('metasync_trigger_immediate_heartbeat', 'Whitelabel domain change - ' . $context);
            });
        }

        $result = array_merge($new_input, $input);

        if ($whitelabel_validation_failed) {
            $result['whitelabel'] = Metasync::get_option()['whitelabel'] ?? [];
        }

        return $result;
    }

    /**
     * AJAX handler for saving general/whitelabel/advanced settings.
     *
     * Moved from Metasync_Admin – the admin class now delegates here.
     */
    public function meta_sync_save_settings()
    {
        if (!isset($_POST['meta_sync_nonce']) || !wp_verify_nonce($_POST['meta_sync_nonce'], 'meta_sync_general_setting_nonce')) {
            wp_send_json_error(array('message' => 'Invalid nonce'));
            return;
        }

        $text_fields = [
            'searchatlas_api_key', 'apikey',
            'white_label_plugin_name', 'white_label_plugin_description',
            'white_label_plugin_author', 'white_label_plugin_menu_slug',
            'white_label_plugin_menu_icon', 'enabled_plugin_css',
            'enabled_elementor_plugin_css_color','enabled_elementor_plugin_css',
            'otto_pixel_uuid','periodic_clear_otto_cache','periodic_clear_ottopage_cache',
            'periodic_clear_ottopost_cache', 'whitelabel_otto_name', 'otto_wp_rocket_compat'
        ];

        $url_fields = [
            'white_label_plugin_author_uri', 'white_label_plugin_uri'
        ];

        $bool_fields = ['otto_disable_on_loggedin', 'otto_disable_preview_button', 'otto_disable_for_bots' , 'hide_dashboard_framework', 'show_admin_bar_status', 'enable_auto_updates', 'disable_common_robots_metabox', 'disable_advance_robots_metabox', 'disable_redirection_metabox', 'disable_canonical_metabox', 'disable_social_opengraph_metabox', 'disable_schema_markup_metabox', 'open_external_links'];

        $url_fields = ['white_label_plugin_author_uri', 'white_label_plugin_uri'];

        $metasync_options = Metasync::get_option();
        if (!is_array($metasync_options)) {
            $metasync_options = array();
        }
        if (!isset($metasync_options['general']) || !is_array($metasync_options['general'])) {
            $metasync_options['general'] = array();
        }

        $validation_errors = [];

        $active_tab = isset($_POST['active_tab']) ? sanitize_text_field($_POST['active_tab']) : 'general';
        $general_tab_submitted = ($active_tab === 'general');
        $whitelabel_tab_submitted = ($active_tab === 'whitelabel');

        foreach ($text_fields as $field) {

            if (isset($_POST['metasync_options']['general'][$field])) {

                $value = trim($_POST['metasync_options']['general'][$field]);

                $whitelabel_clearable_fields = [
                    'white_label_plugin_name',
                    'white_label_plugin_description',
                    'white_label_plugin_author',
                    'white_label_plugin_menu_slug',
                    'white_label_plugin_menu_icon',
                    'whitelabel_otto_name',
                    'otto_pixel_uuid'
                ];

                if ($value === '' && !in_array($field, $whitelabel_clearable_fields)) {
                    continue;
                }

                if ($field === 'white_label_plugin_name') {
                    if (strlen($value) > 16) {
                        $validation_errors[$field] = 'Plugin name must not exceed 16 characters';
                        continue;
                    }
                }

                if ($field === 'white_label_plugin_menu_icon') {

                    if ($value === '') {
                        $metasync_options['general'][$field] = '';
                    } elseif (filter_var($value, FILTER_VALIDATE_URL)) {

                        $image_extensions = ['png', 'svg'];

                        $path = parse_url($value, PHP_URL_PATH);
                        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                        if (in_array($extension, $image_extensions, true)) {
                            $metasync_options['general'][$field] = esc_url_raw($value);
                        } else {
                            $validation_errors[] = 'Invalid Menu icon format. Only PNG and SVG are allowed.';
                        }
                    } else {
                        $validation_errors[] = 'Invalid Menu icon URL format.';
                    }
                } else {
                    $metasync_options['general'][$field] = sanitize_text_field($_POST['metasync_options']['general'][$field]);
                }
            }
        }

        $textarea_fields = ['otto_bot_whitelist', 'otto_bot_blacklist'];
        if ($general_tab_submitted) {
            foreach ($textarea_fields as $field) {
                if (isset($_POST['metasync_options']['general'][$field])) {
                    $metasync_options['general'][$field] = sanitize_textarea_field($_POST['metasync_options']['general'][$field]);
                } else {
                    $metasync_options['general'][$field] = '';
                }
            }
        }

        if ($general_tab_submitted) {
            foreach ($bool_fields as $field) {
                if (isset($_POST['metasync_options']['general'][$field])) {
                    $metasync_options['general'][$field] = filter_var($_POST['metasync_options']['general'][$field], FILTER_VALIDATE_BOOLEAN);
                }else {
                    $metasync_options['general'][$field] = false;
                }
            }
        }

        if ($general_tab_submitted || $whitelabel_tab_submitted) {
            foreach ($url_fields as $field) {
            if (isset($_POST['metasync_options']['general'][$field])) {

                $value = trim($_POST['metasync_options']['general'][$field]);

                $whitelabel_clearable_url_fields = [
                    'white_label_plugin_author_uri',
                    'white_label_plugin_uri'
                ];

                if ($value === '' && !in_array($field, $whitelabel_clearable_url_fields)) {
                    continue;
                }

                if ($value === '' && in_array($field, $whitelabel_clearable_url_fields)) {
                    $metasync_options['general'][$field] = '';
                    continue;
                }

                if (filter_var($value, FILTER_VALIDATE_URL)) {

                    $parsed_url = parse_url($value);
                    $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';

                    if (strpos($host, '.') !== false || filter_var($host, FILTER_VALIDATE_IP)) {
                        $metasync_options['general'][$field] = esc_url_raw($value);
                    } else {
                        $field_name = ($field === 'white_label_plugin_author_uri') ? 'Author URL' : 'Plugin URL';
                        $validation_errors[] = 'Invalid ' . $field_name . ' format. Please use a proper domain name (e.g., example.com).';
                    }
                } else {
                    $field_name = ($field === 'white_label_plugin_author_uri') ? 'Author URL' : 'Plugin URL';
                    $validation_errors[] = 'Invalid ' . $field_name . ' format.';
                }
            }
            }
        }

        if ($general_tab_submitted) {
            if (isset($_POST['metasync_options']['general']['content_genius_sync_roles']) && is_array($_POST['metasync_options']['general']['content_genius_sync_roles'])) {
                $metasync_options['general']['content_genius_sync_roles'] = array_map('sanitize_text_field', $_POST['metasync_options']['general']['content_genius_sync_roles']);
            } else {
                $metasync_options['general']['content_genius_sync_roles'] = array();
            }
        }

        if ($whitelabel_tab_submitted && isset($_POST['metasync_options']['whitelabel'])) {
            $gp = isset($_POST['metasync_options']['general']) && is_array($_POST['metasync_options']['general']) ? $_POST['metasync_options']['general'] : [];
            $wp = isset($_POST['metasync_options']['whitelabel']) && is_array($_POST['metasync_options']['whitelabel']) ? $_POST['metasync_options']['whitelabel'] : [];
            $plugin_name = isset($gp['white_label_plugin_name']) ? trim((string) $gp['white_label_plugin_name']) : '';
            $logo = isset($wp['logo']) ? trim((string) $wp['logo']) : '';
            $author = isset($gp['white_label_plugin_author']) ? trim((string) $gp['white_label_plugin_author']) : '';
            $author_uri = isset($gp['white_label_plugin_author_uri']) ? trim((string) $gp['white_label_plugin_author_uri']) : '';
            $plugin_uri = isset($gp['white_label_plugin_uri']) ? trim((string) $gp['white_label_plugin_uri']) : '';
            $domain = isset($wp['domain']) ? trim((string) $wp['domain']) : '';
            $description = isset($gp['white_label_plugin_description']) ? trim((string) $gp['white_label_plugin_description']) : '';

            $has_core_field = (!empty($plugin_name) || (!empty($logo) && filter_var($logo, FILTER_VALIDATE_URL)) || !empty($author));
            $has_optional_only = (!empty($author_uri) || !empty($plugin_uri) || (!empty($domain) && filter_var($domain, FILTER_VALIDATE_URL)) || !empty($description));

            if ($has_optional_only && !$has_core_field) {
                $validation_errors[] = 'Add at least one of: Plugin Name, Logo URL, or Author to save whitelabel settings.';
            }
        }

        if (!empty($validation_errors)) {
            wp_send_json_error([
                'errors' => $validation_errors
            ]);
            return;
        }

        $old_options = Metasync::get_option('general') ?? [];
        $old_api_key = $old_options['searchatlas_api_key'] ?? '';

        Metasync::set_option($metasync_options);

        $data = Metasync::get_option('general');
        $new_api_key = $data['searchatlas_api_key'] ?? '';

        $api_key_changed = $old_api_key !== $new_api_key;
        $api_key_added = empty($old_api_key) && !empty($new_api_key);
        $api_key_removed = !empty($old_api_key) && empty($new_api_key);

        $sync_request = new Metasync_Sync_Requests();
        $response = $sync_request->SyncCustomerParams();
        $responseCode = wp_remote_retrieve_response_code($response);

        if ($responseCode == 200) {
            $dt = new DateTime();
            $send_auth_token_timestamp = Metasync::get_option();
            $send_auth_token_timestamp['general']['send_auth_token_timestamp'] = $dt->format('M d, Y  h:i:s A');
            Metasync::set_option($send_auth_token_timestamp);

            if ($api_key_added) {
                Metasync_Heartbeat_Manager::instance()->maybe_schedule_heartbeat_cron();
                do_action('metasync_trigger_immediate_heartbeat', 'Manual API key update - new key added');
            } elseif ($api_key_removed) {
                Metasync_Heartbeat_Manager::instance()->unschedule_heartbeat_cron();
                delete_transient('metasync_heartbeat_status_cache');
            } elseif ($api_key_changed && !empty($new_api_key)) {
                do_action('metasync_trigger_immediate_heartbeat', 'Manual API key update - key changed');
            }
        }

        if (isset($_POST['metasync_options']['whitelabel'])) {

            $whitelabel_data = $_POST['metasync_options']['whitelabel'];
            $existing_whitelabel = $metasync_options['whitelabel'] ?? [];

            if (isset($whitelabel_data['logo'])) {
                $logo_value = trim($whitelabel_data['logo']);

                if (!empty($logo_value) && filter_var($logo_value, FILTER_VALIDATE_URL)) {
                    $metasync_options['whitelabel']['logo'] = esc_url_raw($logo_value);
                } else {
                    $metasync_options['whitelabel']['logo'] = '';
                }
            }

            if (isset($whitelabel_data['domain'])) {
                $domain_value = trim($whitelabel_data['domain']);
                if (!empty($domain_value) && filter_var($domain_value, FILTER_VALIDATE_URL)) {
                    $metasync_options['whitelabel']['domain'] = esc_url_raw($domain_value);
                } else {
                    $metasync_options['whitelabel']['domain'] = '';
                }
            }

            if (isset($whitelabel_data['settings_password'])) {
                $password_value = trim($whitelabel_data['settings_password']);

                $hide_settings_enabled = isset($whitelabel_data['hide_settings']) && $whitelabel_data['hide_settings'] == '1';

                if ($hide_settings_enabled && empty($password_value)) {
                    if (isset($existing_whitelabel['settings_password'])) {
                        $metasync_options['whitelabel']['settings_password'] = $existing_whitelabel['settings_password'];
                    }
                } else {
                    $metasync_options['whitelabel']['settings_password'] = sanitize_text_field($password_value);
                }
            } else {
                if (isset($existing_whitelabel['settings_password'])) {
                    $metasync_options['whitelabel']['settings_password'] = $existing_whitelabel['settings_password'];
                }
            }

            if (isset($whitelabel_data['hide_settings'])) {
                $metasync_options['whitelabel']['hide_settings'] = filter_var($whitelabel_data['hide_settings'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            } else {
                $metasync_options['whitelabel']['hide_settings'] = 0;
            }

            if (isset($whitelabel_data['recovery_email'])) {
                $recovery_email = trim($whitelabel_data['recovery_email']);
                if (!empty($recovery_email)) {
                    $sanitized_email = sanitize_email($recovery_email);
                    if (is_email($sanitized_email)) {
                        $metasync_options['whitelabel']['recovery_email'] = $sanitized_email;
                    }
                } else {
                    $metasync_options['whitelabel']['recovery_email'] = '';
                }
            } else {
                if (isset($existing_whitelabel['recovery_email'])) {
                    $metasync_options['whitelabel']['recovery_email'] = $existing_whitelabel['recovery_email'];
                }
            }

            $has_password = !empty($metasync_options['whitelabel']['settings_password']);
            $has_recovery_email = !empty($metasync_options['whitelabel']['recovery_email']);
            if ($has_password && !$has_recovery_email) {
                $metasync_options['whitelabel']['settings_password'] = '';
            }

            if (isset($whitelabel_data['access_control'])) {
                $metasync_options['whitelabel']['access_control'] = Metasync_Access_Control::sanitize_access_control($whitelabel_data['access_control']);
            }

            if (!empty($metasync_options['whitelabel']['hide_settings']) && empty($metasync_options['whitelabel']['settings_password'])) {
                $metasync_options['whitelabel']['hide_settings'] = 0;
            }

            $metasync_options['whitelabel']['updated_at'] = time();

            Metasync::set_option($metasync_options);

        }

        $redirect_url = isset($_GET['tab']) ?
                        admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-settings&tab='.$_GET['tab']) :
                        admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-settings');

        // Also save edge cache settings if present in the payload (section renders inside this form)
        if (class_exists('Metasync_Edge_Cache_Settings')) {
            Metasync_Edge_Cache_Settings::save_from_post();
        }

        wp_send_json_success(array(
            'message' => 'Settings saved successfully!',
            'redirect_url' => $redirect_url
        ));
    }

    /**
     * AJAX handler for saving execution settings.
     *
     * Moved from Metasync_Admin – the admin class now delegates here.
     */
    public function ajax_save_execution_settings()
    {
        if (!isset($_POST['execution_settings_nonce']) || !wp_verify_nonce($_POST['execution_settings_nonce'], 'metasync_execution_settings_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token. Please refresh the page and try again.'));
            return;
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions to save settings.'));
            return;
        }

        $settings = array(
            'max_execution_time' => isset($_POST['max_execution_time']) ? absint($_POST['max_execution_time']) : 30,
            'max_memory_limit' => isset($_POST['max_memory_limit']) ? absint($_POST['max_memory_limit']) : 256,
            'log_batch_size' => isset($_POST['log_batch_size']) ? absint($_POST['log_batch_size']) : 1000,
            'action_scheduler_batches' => isset($_POST['action_scheduler_batches']) ? absint($_POST['action_scheduler_batches']) : 1,
            'otto_rate_limit' => isset($_POST['otto_rate_limit']) ? absint($_POST['otto_rate_limit']) : 10,
            'queue_cleanup_days' => isset($_POST['queue_cleanup_days']) ? absint($_POST['queue_cleanup_days']) : 31
        );

        $server_limits = Metasync_Settings_Fields::instance()->get_server_limits();

        if ($settings['max_execution_time'] < 1 || $settings['max_execution_time'] > 300) {
            wp_send_json_error(array('message' => 'Max Execution Time must be between 1 and 300 seconds.'));
            return;
        }

        if ($server_limits['max_execution_time_raw'] != -1 && $settings['max_execution_time'] > $server_limits['max_execution_time_raw']) {
            wp_send_json_error(array('message' => sprintf('Max Execution Time exceeds server limit of %d seconds. Please reduce the value.', $server_limits['max_execution_time_raw'])));
            return;
        }

        $can_change_memory = Metasync_Settings_Fields::instance()->can_change_memory_limit();
        if ($can_change_memory && ($settings['max_memory_limit'] < 64 || $settings['max_memory_limit'] > 512)) {
            wp_send_json_error(array('message' => 'Max Memory Limit must be between 64 and 512 MB.'));
            return;
        }

        if ($can_change_memory && $server_limits['memory_limit_raw'] != -1 && $settings['max_memory_limit'] > $server_limits['memory_limit_raw']) {
            wp_send_json_error(array('message' => sprintf('Max Memory Limit exceeds server limit of %d MB. Please reduce the value.', $server_limits['memory_limit_raw'])));
            return;
        }

        if (!$can_change_memory) {
            $existing_settings = get_option('metasync_execution_settings', array());
            $settings['max_memory_limit'] = isset($existing_settings['max_memory_limit'])
                ? $existing_settings['max_memory_limit']
                : 256;
        }

        if ($settings['log_batch_size'] < 100 || $settings['log_batch_size'] > 5000) {
            wp_send_json_error(array('message' => 'Log Batch Size must be between 100 and 5000 lines.'));
            return;
        }

        if ($settings['action_scheduler_batches'] < 1 || $settings['action_scheduler_batches'] > 10) {
            wp_send_json_error(array('message' => 'Action Scheduler Concurrent Batches must be between 1 and 10.'));
            return;
        }

        if ($settings['otto_rate_limit'] < 1 || $settings['otto_rate_limit'] > 60) {
            wp_send_json_error(array('message' => 'OTTO API Calls Per Minute must be between 1 and 60.'));
            return;
        }

        if ($settings['queue_cleanup_days'] < 7 || $settings['queue_cleanup_days'] > 90) {
            wp_send_json_error(array('message' => 'Queue Auto-Cleanup Days must be between 7 and 90 days.'));
            return;
        }

        $saved = update_option('metasync_execution_settings', $settings);

        if ($saved !== false) {
            wp_send_json_success(array(
                'message' => 'Execution settings saved successfully!'
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to save settings. Please try again.'));
        }
    }

    /**
     * AJAX handler for saving SEO / Indexation Control settings.
     *
     * Moved from Metasync_Admin – the admin class now delegates here.
     */
    public function meta_sync_save_seo_controls()
    {
        try {
            if (!isset($_POST['meta_sync_seo_controls_nonce']) || !wp_verify_nonce($_POST['meta_sync_seo_controls_nonce'], 'meta_sync_seo_controls_nonce')) {
                wp_send_json_error(array('message' => 'Invalid nonce'));
                return;
            }

            $current_options = Metasync::get_option();

            $original_options = json_decode(json_encode($current_options), true);

        if (!isset($current_options['seo_controls'])) {
            $current_options['seo_controls'] = [];
        }

         $seo_control_fields = [
            'index_date_archives',
            'index_tag_archives',
            'index_author_archives',
            'index_category_archives',
            'index_format_archives',
            'override_robots_tags',
            'add_nofollow_to_external_links',
            'enable_googleinstantindex',
            'enable_binginstantindex'
            ];

        foreach ($seo_control_fields as $field) {
            if (isset($_POST['metasync_options']['seo_controls'][$field]) && $_POST['metasync_options']['seo_controls'][$field] === 'true') {
                $current_options['seo_controls'][$field] = 'true';
            } else {
                $current_options['seo_controls'][$field] = 'false';
            }
        }

        $bing_save_result = true;
        if (isset($_POST['metasync_bing_api_key_inline'])) {
            $bing_save_result = $this->save_bing_inline_settings_ajax();
        }

        $options_changed = (json_encode($original_options) !== json_encode($current_options));

        $result = Metasync::set_option($current_options);

        if (!$result && !$options_changed) {
            $result = true;
        }

            if ($result && $bing_save_result) {
                $success_message = 'Indexation Control settings saved successfully!';
                if (isset($_POST['metasync_bing_api_key_inline'])) {
                    $success_message = 'Indexation Control and Bing Instant Indexing settings saved successfully!';
                }
                wp_send_json_success(array(
                    'message' => $success_message,
                    'saved_data' => $current_options['seo_controls']
                ));
            } else {
                $error_message = 'Failed to save Indexation Control settings';
                if (!$bing_save_result) {
                    $error_message = 'Failed to save Bing Instant Indexing settings';
                }
                wp_send_json_error(array('message' => $error_message));
            }
        } catch (Exception $e) {
            error_log('Indexation Control AJAX Exception: ' . $e->getMessage());
            error_log('Indexation Control AJAX Stack trace: ' . $e->getTraceAsString());
            wp_send_json_error(array('message' => 'Error saving settings: ' . $e->getMessage()));
        }
    }

    /**
     * Save Bing IndexNow inline settings from SEO Controls page.
     *
     * Moved from Metasync_Admin – the admin class now delegates here.
     *
     * @return bool True on success, false on failure
     */
    public function save_bing_inline_settings_ajax()
    {
        $api_key = isset($_POST['metasync_bing_api_key_inline']) ? sanitize_text_field(wp_unslash($_POST['metasync_bing_api_key_inline'])) : '';
        $endpoint = isset($_POST['metasync_bing_endpoint_inline']) ? sanitize_text_field(wp_unslash($_POST['metasync_bing_endpoint_inline'])) : 'indexnow';
        $post_types = isset($_POST['metasync_bing_post_types_inline']) && is_array($_POST['metasync_bing_post_types_inline']) ? array_map('sanitize_title', wp_unslash($_POST['metasync_bing_post_types_inline'])) : [];
        $disable_other_plugins = isset($_POST['metasync_bing_disable_other_plugins_inline']) ? true : false;

        $existing_settings = get_option('metasync_options_bing_instant_indexing', []);

        $new_settings = [
            'api_key'    => $api_key,
            'endpoint'   => $endpoint,
            'post_types' => array_values($post_types),
            'disable_other_plugins' => $disable_other_plugins,
        ];

        $settings = array_merge($existing_settings, $new_settings);

        $settings_changed = (json_encode($existing_settings) !== json_encode($settings));

        $result = update_option('metasync_options_bing_instant_indexing', $settings);

        if (!$result && !$settings_changed) {
            $result = true;
        }

        if (!empty($api_key)) {
            $file_path = ABSPATH . $api_key . '.txt';
            $file_result = file_put_contents($file_path, $api_key);

            if ($file_result === false) {
                error_log('Bing IndexNow: Failed to create API key verification file at ' . $file_path);
                return false;
            }

            $current_options = Metasync::get_option();
            if (!isset($current_options['seo_controls']['enable_binginstantindex']) || $current_options['seo_controls']['enable_binginstantindex'] !== 'true') {
                $current_options['seo_controls']['enable_binginstantindex'] = 'true';
                Metasync::set_option($current_options);
            }
        }

        return $result;
    }
}

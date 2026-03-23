<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Admin navigation header, nav tabs, and admin-bar status indicator.
 *
 * Extracted from Metasync_Admin to keep the admin class focused on
 * non-UI concerns. All header/nav rendering and admin-bar badge
 * logic lives here.
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */
class Metasync_Admin_Navigation
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

    // ------------------------------------------------------------------
    //  Header + Nav rendering
    // ------------------------------------------------------------------

    /**
     * Render the standard header followed by the navigation tabs.
     */
    public function render_standard_header_nav($page_title = null, $current_page = null)
    {
        $this->render_static_header($page_title);
        $this->render_static_navigation($current_page);
    }

    /**
     * Render the page header (logo, connection badge, theme toggle).
     */
    public function render_static_header($page_title = null)
    {
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        $whitelabel_logo = Metasync::get_whitelabel_logo();
        $is_whitelabel = isset($whitelabel_settings['is_whitelabel']) ? $whitelabel_settings['is_whitelabel'] : false;
        $effective_plugin_name = Metasync::get_effective_plugin_name();
        $display_title = $page_title ?: $effective_plugin_name;
        
        $show_logo = false;
        $logo_url = '';
        
        if (!empty($whitelabel_logo) && filter_var($whitelabel_logo, FILTER_VALIDATE_URL)) {
            $show_logo = true;
            $logo_url = esc_url($whitelabel_logo);
        } elseif (!$is_whitelabel) {
            $show_logo = true;
            $logo_url = Metasync::HOMEPAGE_DOMAIN . '/wp-content/uploads/2023/12/white.svg';
        }
        
        $current_theme = get_option('metasync_theme', 'dark');
        $general_settings = Metasync::get_option('general');
        $searchatlas_api_key = isset($general_settings['searchatlas_api_key']) ? $general_settings['searchatlas_api_key'] : '';
        $is_integrated = !empty($searchatlas_api_key);
        ?>
        <div class="metasync-header" data-current-theme="<?php echo esc_attr($current_theme); ?>">
            <div class="metasync-header-left">
                <?php if ($show_logo && !empty($logo_url)): ?>
                    <div class="metasync-logo-container">
                        <img src="<?php echo $logo_url; ?>" alt="Logo" class="metasync-logo" />
                    </div>
                <?php endif; ?>
            </div>
            <div class="metasync-header-right">
                <div class="metasync-status <?php echo $is_integrated ? 'connected' : 'disconnected'; ?>">
                    <span class="status-dot"></span>
                    <span class="status-text"><?php echo $is_integrated ? 'Connected' : 'Not Connected'; ?></span>
                </div>
                <button type="button" class="metasync-theme-toggle" onclick="toggleMetasyncTheme()" title="Toggle theme">
                    <span class="theme-icon-light">☀️</span>
                    <span class="theme-icon-dark">🌙</span>
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Render the horizontal navigation tabs.
     */
    public function render_static_navigation($current_page = null)
    {
        $general_options = Metasync::get_option('general') ?? [];
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        $page_slug = Metasync_Admin::$page_slug;
        
        $menu_items = [];
        $menu_icons = [
            'dashboard' => '📊',
            'seo_controls' => '🔍',
            'optimal_settings' => '🚀',
            'instant_index' => '🔗',
            'google_console' => '📊',
            'compatibility' => '🔧',
            'sync_log' => '📋',
            'redirections' => '↩️',
            'robots_txt' => '🤖',
            'xml_sitemap' => '🗺️',
            'custom_pages' => '📝',
            'report_issue' => '📝',
            'general' => '⚙️'
        ];
        
        if (empty($whitelabel_settings['hide_dashboard'])) {
            $menu_items['dashboard'] = ['title' => 'Dashboard', 'slug_suffix' => '-dashboard'];
        }
        
        if (empty($whitelabel_settings['hide_indexation_control'])) {
            $menu_items['seo_controls'] = ['title' => 'Indexation Control', 'slug_suffix' => '-seo-controls'];
        }
        
        if ($general_options['enable_optimal_settings'] ?? false) {
            $menu_items['optimal_settings'] = ['title' => 'Optimal Settings', 'slug_suffix' => '-optimal-settings'];
        }
        
        if ($general_options['enable_googleinstantindex'] ?? false) {
            $menu_items['instant_index'] = ['title' => 'Instant Indexing', 'slug_suffix' => '-instant-index'];
        }
        
        if ($general_options['enable_google_console'] ?? false) {
            $menu_items['google_console'] = ['title' => 'Google Console', 'slug_suffix' => '-google-console'];
        }
        
        if (empty($whitelabel_settings['hide_compatibility'])) {
            $menu_items['compatibility'] = ['title' => 'Compatibility', 'slug_suffix' => '-compatibility'];
        }
        
        if (empty($whitelabel_settings['hide_sync_log'])) {
            $menu_items['sync_log'] = ['title' => 'Sync Log', 'slug_suffix' => '-sync-log'];
        }
        
        if (empty($whitelabel_settings['hide_redirections'])) {
            $menu_items['redirections'] = ['title' => 'Redirections', 'slug_suffix' => '-redirections'];
        }
        
        if (empty($whitelabel_settings['hide_robots'])) {
            $menu_items['robots_txt'] = ['title' => 'Robots.txt', 'slug_suffix' => '-robots-txt'];
        }
        
        $menu_items['xml_sitemap'] = ['title' => 'XML Sitemap', 'slug_suffix' => '-xml-sitemap'];
        ?>
        <div class="metasync-nav-wrapper">
            <div class="metasync-nav-tabs">
                <div class="metasync-nav-left">
                <?php foreach ($menu_items as $key => $menu_item): 
                    $is_active = ($current_page === $key);
                    $icon = $menu_icons[$key] ?? '📄';
                    $page_url = '?page=' . $page_slug . $menu_item['slug_suffix'];
                ?>
                    <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-tab <?php echo $is_active ? 'active' : ''; ?>">
                        <span class="tab-icon"><?php echo $icon; ?></span>
                        <span class="tab-text"><?php echo esc_html($menu_item['title']); ?></span>
                    </a>
                <?php endforeach; ?>
                </div>
                <div class="metasync-nav-right">
                    <a href="?page=<?php echo $page_slug; ?>-custom-pages" class="metasync-nav-tab <?php echo $current_page === 'custom_pages' ? 'active' : ''; ?>" style="margin-right: 10px;">
                        <span class="tab-icon">📝</span>
                        <span class="tab-text">Custom Pages</span>
                    </a>
                    <a href="?page=<?php echo $page_slug; ?>-report-issue" class="metasync-nav-tab <?php echo $current_page === 'report_issue' ? 'active' : ''; ?>">
                        <span class="tab-icon">📝</span>
                        <span class="tab-text">Report Issue</span>
                    </a>
                    <div class="metasync-simple-dropdown">
                        <button type="button" class="metasync-seo-btn" id="metasync-seo-btn" onclick="toggleSeoMenuPortal(event)">
                            <span class="tab-icon">🔍</span>
                            <span class="tab-text">SEO</span>
                            <span class="dropdown-arrow">▼</span>
                        </button>
                    </div>
                    <div class="metasync-simple-dropdown">
                        <button type="button" class="metasync-settings-btn" id="metasync-settings-btn" onclick="toggleSettingsMenuPortal(event)" aria-expanded="false">
                            <span class="tab-icon">⚙️</span>
                            <span class="tab-text">Settings</span>
                            <span class="dropdown-arrow">▼</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        function toggleSeoMenuPortal(event) {
            event.preventDefault();
            event.stopPropagation();
            var button = event.currentTarget;
            var existingMenu = document.getElementById('metasync-seo-portal-menu');
            if (existingMenu) {
                existingMenu.remove();
                button.classList.remove('active');
                return;
            }
            var menu = document.createElement('div');
            menu.id = 'metasync-seo-portal-menu';
            menu.className = 'metasync-portal-menu';

            var rect = button.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 8) + 'px';
            menu.style.right = (window.innerWidth - rect.right) + 'px';
            menu.style.zIndex = '999999999';
            document.body.appendChild(menu);
            button.classList.add('active');
        }

        function toggleSettingsMenuPortal(event) {
            event.preventDefault();
            event.stopPropagation();
            var button = event.currentTarget;
            var existingMenu = document.getElementById('metasync-portal-menu');
            if (existingMenu) {
                existingMenu.remove();
                button.classList.remove('active');
                return;
            }
            var menu = document.createElement('div');
            menu.id = 'metasync-portal-menu';
            menu.className = 'metasync-portal-menu';

            var hideAdvanced = <?php echo !empty($whitelabel_settings['hide_advanced']) ? 'true' : 'false'; ?>;
            var showGeneral = <?php echo Metasync_Access_Control::user_can_access('hide_settings') ? 'true' : 'false'; ?>;
            
            if (showGeneral) {
                var generalLink = document.createElement('a');
                generalLink.href = '?page=<?php echo $page_slug; ?>&tab=general';
                generalLink.className = 'metasync-portal-item';
                generalLink.textContent = 'General';
                menu.appendChild(generalLink);
            }

            var whitelabelLink = document.createElement('a');
            whitelabelLink.href = '?page=<?php echo $page_slug; ?>&tab=whitelabel';
            whitelabelLink.className = 'metasync-portal-item';
            whitelabelLink.textContent = 'White Label';
            menu.appendChild(whitelabelLink);

            if (!hideAdvanced) {
                var advancedLink = document.createElement('a');
                advancedLink.href = '?page=<?php echo $page_slug; ?>&tab=advanced';
                advancedLink.className = 'metasync-portal-item';
                advancedLink.textContent = 'Advanced';
                menu.appendChild(advancedLink);
            }


            var rect = button.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 8) + 'px';
            menu.style.right = (window.innerWidth - rect.right) + 'px';
            menu.style.zIndex = '999999999';
            document.body.appendChild(menu);
            button.classList.add('active');
        }
        document.addEventListener('click', function(event) {
            var seoButton = document.getElementById('metasync-seo-btn');
            var seoMenu = document.getElementById('metasync-seo-portal-menu');
            if (seoMenu && seoButton && !seoButton.contains(event.target) && !seoMenu.contains(event.target)) {
                seoMenu.remove();
                seoButton.classList.remove('active');
            }

            var button = document.getElementById('metasync-settings-btn');
            var menu = document.getElementById('metasync-portal-menu');
            if (menu && button && !button.contains(event.target) && !menu.contains(event.target)) {
                menu.remove();
                button.classList.remove('active');
            }
        });
        </script>
        <?php
    }

    // ------------------------------------------------------------------
    //  Menu registration + enhanced navigation (moved from Metasync_Admin)
    // ------------------------------------------------------------------

    /**
     * Helper method to get available menu items based on configuration.
     * Items are grouped into 'seo' (SEO Features) and 'plugin' (Plugin) categories.
     */
    public function get_available_menu_items()
    {
        $general_options = Metasync::get_option('general') ?? [];
        $has_api_key = !empty($general_options['searchatlas_api_key'] ?? '');
        $has_uuid = !empty($general_options['otto_pixel_uuid'] ?? '');
        $is_fully_connected = Metasync_Heartbeat_Manager::instance()->is_heartbeat_connected($general_options);

        $menu_items = [];

        // === SEO FEATURES GROUP ===
        
        // Dashboard (check access control)
        if (Metasync_Access_Control::user_can_access('hide_dashboard')) {
            $menu_items['dashboard'] = [
                'title' => 'Dashboard',
                'slug_suffix' => '-dashboard',
                'callback' => 'create_admin_dashboard_iframe',
                'internal_nav' => 'Dashboard',
                'group' => 'seo'
            ];
        }
        
        // Indexation Control (check access control)
        if (Metasync_Access_Control::user_can_access('hide_indexation_control')) {
            $menu_items['seo_controls'] = [
                'title' => 'Indexation',
                'slug_suffix' => '-seo-controls',
                'callback' => 'create_admin_seo_controls_page',
                'internal_nav' => 'Indexation Control',
                'group' => 'seo'
            ];
        }
        
        // Instant Indexing - setting now stored in seo_controls (moved from Settings to Indexation Control)
        $seo_controls = Metasync::get_option('seo_controls');
        if ($seo_controls['enable_googleinstantindex'] ?? false) {
            $menu_items['instant_index'] = [
                'title' => 'Instant Indexing',
                'slug_suffix' => '-instant-index',
                'callback' => 'create_admin_google_instant_index_page',
                'internal_nav' => 'Instant Indexing',
                'group' => 'seo'
            ];
        }
        
        if ($general_options['enable_google_console'] ?? false) {
            $menu_items['google_console'] = [
                'title' => 'Google Console',
                'slug_suffix' => '-google-console',
                'callback' => 'create_admin_google_console_page',
                'internal_nav' => 'Google Console',
                'group' => 'seo'
            ];
        }

        if ($general_options['enable_bing_console'] ?? false) {
            $menu_items['bing_console'] = [
                'title' => 'Bing Console',
                'slug_suffix' => '-bing-console',
                'callback' => 'create_admin_bing_console_page',
                'internal_nav' => 'Bing Console',
                'group' => 'seo'
            ];
        }

        // Redirections page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_redirections')) {
            $menu_items['redirections'] = [
                'title' => 'Redirections',
                'slug_suffix' => '-redirections',
                'callback' => 'create_admin_redirections_page',
                'internal_nav' => 'Redirections',
                'group' => 'seo'
            ];
        }

        // Robots.txt page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_robots')) {
            $menu_items['robots_txt'] = [
                'title' => 'Robots.txt',
                'slug_suffix' => '-robots-txt',
                'callback' => 'create_admin_robots_txt_page',
                'internal_nav' => 'Robots.txt',
                'group' => 'seo'
            ];
        }

        // XML Sitemap page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_xml_sitemap')) {
            $menu_items['xml_sitemap'] = [
                'title' => 'XML Sitemap',
                'slug_suffix' => '-xml-sitemap',
                'callback' => 'create_admin_xml_sitemap_page',
                'internal_nav' => 'XML Sitemap',
                'group' => 'seo'
            ];
        }
        
        // Import SEO Data page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_import_seo')) {
            $menu_items['import_seo'] = [
                'title' => 'Import SEO Data',
                'slug_suffix' => '-import-external',
                'callback' => 'render_import_external_data_page',
                'internal_nav' => 'Import SEO Data',
                'group' => 'seo'
            ];
        }

        // === PLUGIN GROUP ===

        // Settings (renamed from General and moved after Dashboard) (check access control)
        if (Metasync_Access_Control::user_can_access('hide_settings')) {
            $menu_items['general'] = [
                'title' => 'Settings',
                'slug_suffix' => '',
                'callback' => 'create_admin_settings_page',
                'internal_nav' => 'General Settings',
                'group' => 'plugin'
            ];
        }
        
        if ($general_options['enable_optimal_settings'] ?? false) {
            $menu_items['optimal_settings'] = [
                'title' => 'Optimal Settings',
                'slug_suffix' => '-optimal-settings',
                'callback' => 'create_admin_optimal_settings_page',
                'internal_nav' => 'Optimal Settings',
                'group' => 'plugin'
            ];
        }

        // Compatibility page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_compatibility')) {
            $menu_items['compatibility'] = [
                'title' => 'Compatibility',
                'slug_suffix' => '-compatibility',
                'callback' => 'create_admin_compatibility_page',
                'internal_nav' => 'Compatibility',
                'group' => 'plugin'
            ];
        }

        // Sync Log page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_sync_log')) {
            $menu_items['sync_log'] = [
                'title' => 'Sync Log',
                'slug_suffix' => '-sync-log',
                'callback' => 'create_admin_sync_log_page',
                'internal_nav' => 'Sync Log',
                'group' => 'plugin'
            ];
        }

        // Custom HTML Pages (check access control)
        if (Metasync_Access_Control::user_can_access('hide_custom_pages')) {
            $menu_items['custom_pages'] = [
                'title' => 'Custom Pages',
                'slug_suffix' => '-custom-pages',
                'callback' => 'create_admin_custom_pages_page',
                'internal_nav' => 'Custom HTML Pages',
                'group' => 'plugin'
            ];
        }

        // Bot Statistics (always available)
        $menu_items['bot_statistics'] = [
            'title' => 'Bot Statistics',
            'slug_suffix' => '-bot-statistics',
            'callback' => 'create_admin_bot_statistics_page',
            'internal_nav' => 'Bot Statistics',
            'group' => 'plugin'
        ];

        // Report Issue page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_report_issue')) {
            $menu_items['report_issue'] = [
                'title' => 'Report Issue',
                'slug_suffix' => '-report-issue',
                'callback' => 'create_admin_report_issue_page',
                'internal_nav' => 'Report Issue',
                'group' => 'plugin'
            ];
        }

        return $menu_items;
    }

    /**
     * Register all WordPress admin menu / submenu pages.
     *
     * @param Metasync_Admin $admin  The admin instance whose callbacks WordPress will invoke.
     */
    public function add_plugin_settings_page($admin)
    {
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }

        $data = Metasync::get_option('general');
        $plugin_name = Metasync::get_effective_plugin_name();
        $menu_name = $plugin_name;
        $menu_title = $plugin_name;
        $menu_slug = !isset($data['white_label_plugin_menu_slug']) || $data['white_label_plugin_menu_slug'] == "" ? Metasync_Admin::$page_slug : $data['white_label_plugin_menu_slug'];
        $menu_icon = !isset($data['white_label_plugin_menu_icon']) || $data['white_label_plugin_menu_icon'] == "" ? 'dashicons-searchatlas' : $data['white_label_plugin_menu_icon'];
       
        // Use 'read' capability since actual access is controlled by current_user_has_plugin_access() check above
        $menu_capability = 'read';
        
        // Main menu page - Settings (default)
        add_menu_page(
            $menu_name,
            $menu_title,
            $menu_capability,
            $menu_slug,
            array($admin, 'create_admin_settings_page'),
            $menu_icon
        );

        // Check connection status for submenu availability
        $general_options = Metasync::get_option('general');
        $has_api_key = !empty($general_options['searchatlas_api_key']);
        $has_uuid = !empty($general_options['otto_pixel_uuid']);
        $is_fully_connected = Metasync_Heartbeat_Manager::instance()->is_heartbeat_connected($general_options);

        // Add Dashboard submenu (check access control)
        if (Metasync_Access_Control::user_can_access('hide_dashboard')) {
            add_submenu_page(
                $menu_slug,
                'Dashboard',
                'Dashboard',
                $menu_capability,
                $menu_slug . '-dashboard',
                array($admin, 'create_admin_dashboard_iframe')
            );
        }

        // Add Compatibility submenu (check access control)
        if (Metasync_Access_Control::user_can_access('hide_compatibility')) {
            add_submenu_page(
                $menu_slug,
                'Compatibility',
                'Compatibility',
                $menu_capability,
                $menu_slug . '-compatibility',
                array($admin, 'create_admin_compatibility_page')
            );
        }

        // Sync Log page (check access control)
        if (Metasync_Access_Control::user_can_access('hide_sync_log')) {
            add_submenu_page(
                $menu_slug,
                'Sync Log',
                'Sync Log',
                $menu_capability,
                $menu_slug . '-sync-log',
                array($admin, 'create_admin_sync_log_page')
            );
        }

        // Indexation Control (check access control)
        if (Metasync_Access_Control::user_can_access('hide_indexation_control')) {
            add_submenu_page(
                $menu_slug,
                'Indexation Control',
                'Indexation Control',
                $menu_capability,
                $menu_slug . '-seo-controls',
                array($admin, 'create_admin_seo_controls_page')
            );
        }


        // Rename the auto-generated first submenu item from plugin name to "Settings"
        add_action('admin_menu', function() use ($menu_slug) {
            global $submenu;
            if (isset($submenu[$menu_slug])) {
                foreach ($submenu[$menu_slug] as $key => $item) {
                    if ($item[2] === $menu_slug) {
                        if (!Metasync_Access_Control::user_can_access('hide_settings')) {
                            unset($submenu[$menu_slug][$key]);
                        } else {
                            $submenu[$menu_slug][$key][0] = 'Settings';
                        }
                        break;
                    }
                }

                // Ensure proper ordering: Dashboard first, then Settings
                if (count($submenu[$menu_slug]) > 1) {
                    usort($submenu[$menu_slug], function($a, $b) {
                        if (strpos($a[2], '-dashboard') !== false) return -1;
                        if (strpos($b[2], '-dashboard') !== false) return 1;
                        return 0;
                    });
                }
            }
        }, 999);

        // Additional conditional features (commented out for now - can be enabled based on settings)
        // if(@Metasync::get_option('general')['enable_404monitor'])
        // add_submenu_page($menu_slug, '404 Monitor', '404 Monitor', 'manage_options', $menu_slug . '-404-monitor', array($admin, 'create_admin_404_monitor_page'));

        // if(@Metasync::get_option('general')['enable_siteverification'])
        // add_submenu_page($menu_slug, 'Site Verification', 'Site Verification', 'manage_options', $menu_slug . '-search-engine-verify', array($admin, 'create_admin_search_engine_verification_page'));

        // if(@Metasync::get_option('general')['enable_localbusiness'])
        // add_submenu_page($menu_slug, 'Local Business', 'Local Business', 'manage_options', $menu_slug . '-local-business', array($admin, 'create_admin_local_business_page'));

        // if(@Metasync::get_option('general')['enable_codesnippets'])
        // add_submenu_page($menu_slug, 'Code Snippets', 'Code Snippets', 'manage_options', $menu_slug . '-code-snippets', array($admin, 'create_admin_code_snippets_page'));

        // if(@Metasync::get_option('general')['enable_globalsettings'])
        // add_submenu_page($menu_slug, 'Global Settings', 'Global Settings', 'manage_options', $menu_slug . '-common-settings', array($admin, 'create_admin_global_settings_page'));

        // if(@Metasync::get_option('general')['enable_commonmetastatus'])
        // add_submenu_page($menu_slug, 'Common Meta Status', 'Common Meta Status', 'manage_options', $menu_slug . '-common-meta-settings', array($admin, 'create_admin_common_meta_settings_page'));

        // if(@Metasync::get_option('general')['enable_socialmeta'])
        // add_submenu_page($menu_slug, 'Social Meta', 'Social Meta', 'manage_options', $menu_slug . '-social-meta', array($admin, 'create_admin_social_meta_page'));

        // Redirections (check access control)
        if (Metasync_Access_Control::user_can_access('hide_redirections')) {
            add_submenu_page($menu_slug, 'Redirections', 'Redirections', $menu_capability, $menu_slug . '-redirections', array($admin, 'create_admin_redirections_page'));
        }

        // XML Sitemap (check access control)
        if (Metasync_Access_Control::user_can_access('hide_xml_sitemap')) {
            add_submenu_page($menu_slug, 'XML Sitemap', 'XML Sitemap', $menu_capability, $menu_slug . '-xml-sitemap', array($admin, 'create_admin_xml_sitemap_page'));
        }

        // Robots.txt (check access control)
        if (Metasync_Access_Control::user_can_access('hide_robots')) {
            add_submenu_page($menu_slug, 'Robots.txt', 'Robots.txt', $menu_capability, $menu_slug . '-robots-txt', array($admin, 'create_admin_robots_txt_page'));
        }

        // Bot Statistics (hidden from sidebar - accessible via Plugin dropdown in grouped nav)
        add_submenu_page(null, 'Bot Statistics', 'Bot Statistics', $menu_capability, $menu_slug . '-bot-statistics', array($admin, 'create_admin_bot_statistics_page'));

        // Import SEO Data (check access control)
        if (Metasync_Access_Control::user_can_access('hide_import_seo')) {
            add_submenu_page($menu_slug, 'Import SEO Data', 'Import SEO Data', $menu_capability, $menu_slug . '-import-external', array($admin, 'render_import_external_data_page'));
        }

        // Custom Pages (check access control)
        if (Metasync_Access_Control::user_can_access('hide_custom_pages')) {
            add_submenu_page($menu_slug, 'Custom Pages', 'Custom Pages', $menu_capability, $menu_slug . '-custom-pages', array($admin, 'create_admin_custom_pages_page'));
        }

        // Report Issue (check access control)
        if (Metasync_Access_Control::user_can_access('hide_report_issue')) {
            add_submenu_page($menu_slug, 'Report Issue', 'Report Issue', $menu_capability, $menu_slug . '-report-issue', array($admin, 'create_admin_report_issue_page'));
        }

        // Setup Wizard as a hidden page (accessible via dashboard card only)
        add_submenu_page('', 'Setup Wizard', 'Setup Wizard', $menu_capability, $menu_slug . '-setup-wizard', array($admin->setup_wizard, 'render_wizard_page'));

        // 404 Monitor as a direct page (not submenu)
        add_submenu_page('', '404 Monitor', '404 Monitor', $menu_capability, $menu_slug . '-404-monitor', array($admin, 'create_admin_404_monitor_page'));

        // Google Instant Indexing (conditional - check if enabled)
        $seo_controls = Metasync::get_option('seo_controls');
        if ($seo_controls['enable_googleinstantindex'] ?? false) {
            add_submenu_page($menu_slug, 'Instant Indexing', 'Instant Indexing', $menu_capability, $menu_slug . '-instant-index', array($admin, 'create_admin_google_instant_index_page'));
        }

        // Google Console (conditional - check if enabled)
        if ($general_options['enable_google_console'] ?? false) {
            add_submenu_page($menu_slug, 'Google Console', 'Google Console', $menu_capability, $menu_slug . '-google-console', array($admin, 'create_admin_google_console_page'));
        }

        // Bing Console (conditional - hidden page, only accessible via direct link)
        if ($seo_controls['enable_binginstantindex'] ?? false) {
            add_submenu_page('', 'Bing Console', 'Bing Console', $menu_capability, $menu_slug . '-bing-console', array($admin, 'create_admin_bing_console_page'));
        }

    }

    /**
     * Render the grouped navigation menu with Dashboard + SEO/Plugin dropdowns.
     */
    public function render_navigation_menu($current_page = null)
    {
        $page_slug = Metasync_Admin::$page_slug;
        $available_menu_items = $this->get_available_menu_items();
        
        $menu_icons = [
            'general' => '⚙️',
            'dashboard' => '📊',
            'compatibility' => '🔧',
            'sync_log' => '📋',
            'seo_controls' => '🔍',
            'optimal_settings' => '🚀',
            'instant_index' => '🔗',
            'google_console' => '📊',
            'bing_console' => '📊',
            'redirections' => '↩️',
            'robots_txt' => '🤖',
            'xml_sitemap' => '🗺️',
            'import_seo' => '📥',
            'custom_pages' => '📝',
            'bot_statistics' => '🤖',
            'report_issue' => '📝',
            'error_log' => '⚠️'
        ];

        $seo_items = [];
        $plugin_items = [];
        
        foreach ($available_menu_items as $key => $menu_item) {
            $group = $menu_item['group'] ?? 'plugin';
            if ($group === 'seo') {
                $seo_items[$key] = $menu_item;
            } else {
                $plugin_items[$key] = $menu_item;
            }
        }
        ?>
        <!-- Plugin Navigation Menu with Dashboard + Grouped Dropdowns -->
        <div class="metasync-nav-wrapper">
            <div class="metasync-nav-tabs metasync-nav-grouped">
                <?php
                // Dashboard tab (standalone)
                if (isset($seo_items['dashboard'])) {
                    $is_active = ($current_page === 'dashboard');
                    $icon = $menu_icons['dashboard'] ?? '📊';
                    $page_url = '?page=' . $page_slug . $seo_items['dashboard']['slug_suffix'];
                    ?>
                    <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-tab <?php echo $is_active ? 'active' : ''; ?>">
                        <span class="tab-icon"><?php echo $icon; ?></span>
                        <span class="tab-text"><?php echo esc_html($seo_items['dashboard']['title']); ?></span>
                    </a>
                    <?php
                }
                ?>
                
                <!-- SEO Features Dropdown (excluding Dashboard) -->
                <div class="metasync-nav-dropdown">
                    <?php
                    $has_active_seo = false;
                    foreach ($seo_items as $key => $menu_item) {
                        if ($key !== 'dashboard' && $current_page === $key) {
                            $has_active_seo = true;
                            break;
                        }
                    }
                    ?>
                    <button type="button" class="metasync-nav-dropdown-btn <?php echo $has_active_seo ? 'active' : ''; ?>" aria-haspopup="true" aria-expanded="false">
                        <span class="tab-icon">🔍</span>
                        <span class="tab-text">SEO</span>
                        <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="metasync-nav-dropdown-menu">
                        <?php
                        foreach ($seo_items as $key => $menu_item) {
                            if ($key === 'dashboard') {
                                continue;
                            }
                            
                            $is_active = ($current_page === $key);
                            $icon = $menu_icons[$key] ?? '📄';
                            $page_url = '?page=' . $page_slug . $menu_item['slug_suffix'];
                            ?>
                            <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-dropdown-item <?php echo $is_active ? 'active' : ''; ?>">
                                <span class="tab-icon"><?php echo $icon; ?></span>
                                <span class="tab-text"><?php echo esc_html($menu_item['title']); ?></span>
                            </a>
                            <?php
                        }
                        ?>
                    </div>
                </div>

                <!-- Plugin Dropdown -->
                <div class="metasync-nav-dropdown">
                    <?php
                    $has_active_plugin = false;
                    foreach ($plugin_items as $key => $menu_item) {
                        if ($key !== 'report_issue' && $current_page === $key) {
                            $has_active_plugin = true;
                            break;
                        }
                    }
                    ?>
                    <button type="button" class="metasync-nav-dropdown-btn <?php echo $has_active_plugin ? 'active' : ''; ?>" aria-haspopup="true" aria-expanded="false">
                        <span class="tab-icon">⚙️</span>
                        <span class="tab-text">Plugin</span>
                        <span class="dropdown-arrow">▼</span>
                    </button>
                    <div class="metasync-nav-dropdown-menu">
                        <?php
                        foreach ($plugin_items as $key => $menu_item) {
                            if ($key === 'report_issue') {
                                continue;
                            }

                            $is_active = ($current_page === $key);
                            $icon = $menu_icons[$key] ?? '📄';
                            $page_url = '?page=' . $page_slug . $menu_item['slug_suffix'];
                            ?>
                            <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-dropdown-item <?php echo $is_active ? 'active' : ''; ?>">
                                <span class="tab-icon"><?php echo $icon; ?></span>
                                <span class="tab-text"><?php echo esc_html($menu_item['title']); ?></span>
                            </a>
                            <?php
                        }
                        ?>
                    </div>
                </div>

                <!-- Right side - Report Issue and Settings dropdown -->
                <div class="metasync-nav-right">
                    <?php
                    if (isset($available_menu_items['report_issue'])) {
                        $is_active = ($current_page === 'report_issue');
                        $page_url = '?page=' . $page_slug . $available_menu_items['report_issue']['slug_suffix'];
                        ?>
                        <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-tab <?php echo $is_active ? 'active' : ''; ?>">
                            <span class="tab-icon">📝</span>
                            <span class="tab-text">Report Issue</span>
                        </a>
                    <?php } ?>
                    <div class="metasync-simple-dropdown">
                        <button type="button" class="metasync-settings-btn" id="metasync-settings-btn" onclick="toggleSettingsMenuPortal(event)" aria-expanded="false">
                            <span class="tab-icon">⚙️</span>
                            <span class="tab-text">Settings</span>
                            <span class="dropdown-arrow">▼</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        // Handle navigation dropdowns using portal pattern for reliable positioning
        (function() {
            var dropdowns = document.querySelectorAll('.metasync-nav-dropdown');
            var activePortal = null;
            var activeButton = null;
            var activeDropdown = null;
            var scrollHandler = null;
            var resizeHandler = null;

            function positionPortalMenu(button, portalMenu) {
                var rect = button.getBoundingClientRect();
                var menuRect = portalMenu.getBoundingClientRect();
                var viewportWidth = window.innerWidth;
                var viewportHeight = window.innerHeight;

                var left = rect.left;
                if (left + menuRect.width > viewportWidth - 10) {
                    left = Math.max(10, viewportWidth - menuRect.width - 10);
                }

                var top = rect.bottom + 8;
                if (top + menuRect.height > viewportHeight - 10 && rect.top > menuRect.height + 10) {
                    top = rect.top - menuRect.height - 8;
                }

                portalMenu.style.position = 'fixed';
                portalMenu.style.top = top + 'px';
                portalMenu.style.left = left + 'px';
                portalMenu.style.zIndex = '999999999';
            }

            function closeActivePortal() {
                if (activePortal && activePortal.parentNode) {
                    activePortal.parentNode.removeChild(activePortal);
                }
                if (activeButton) {
                    activeButton.setAttribute('aria-expanded', 'false');
                }
                if (activeDropdown) {
                    activeDropdown.classList.remove('active');
                }
                if (scrollHandler) {
                    window.removeEventListener('scroll', scrollHandler, true);
                    scrollHandler = null;
                }
                if (resizeHandler) {
                    window.removeEventListener('resize', resizeHandler);
                    resizeHandler = null;
                }
                activePortal = null;
                activeButton = null;
                activeDropdown = null;
            }

            function openAsPortal(dropdown, button, menu) {
                closeActivePortal();

                var portalMenu = menu.cloneNode(true);
                portalMenu.id = 'metasync-nav-portal-menu';
                portalMenu.style.opacity = '1';
                portalMenu.style.visibility = 'visible';
                portalMenu.style.transform = 'none';

                document.body.appendChild(portalMenu);

                positionPortalMenu(button, portalMenu);

                activePortal = portalMenu;
                activeButton = button;
                activeDropdown = dropdown;
                dropdown.classList.add('active');
                button.setAttribute('aria-expanded', 'true');

                scrollHandler = function() {
                    if (activePortal && activeButton) {
                        positionPortalMenu(activeButton, activePortal);
                    }
                };
                resizeHandler = scrollHandler;

                window.addEventListener('scroll', scrollHandler, true);
                window.addEventListener('resize', resizeHandler);

                portalMenu.addEventListener('click', function(e) {
                    var link = e.target.closest('a');
                    if (link) {
                        setTimeout(closeActivePortal, 0);
                    }
                });
            }

            dropdowns.forEach(function(dropdown) {
                var button = dropdown.querySelector('.metasync-nav-dropdown-btn');
                var menu = dropdown.querySelector('.metasync-nav-dropdown-menu');

                if (!button || !menu) return;

                button.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var isExpanded = button.getAttribute('aria-expanded') === 'true';

                    if (isExpanded) {
                        closeActivePortal();
                    } else {
                        openAsPortal(dropdown, button, menu);
                    }
                });
            });

            document.addEventListener('click', function(e) {
                if (activePortal && activeButton) {
                    if (!activePortal.contains(e.target) && !activeButton.contains(e.target)) {
                        closeActivePortal();
                    }
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && activePortal) {
                    closeActivePortal();
                    if (activeButton) {
                        activeButton.focus();
                    }
                }
            });
        })();
        
        // Portal-style dropdown that bypasses stacking contexts
        function toggleSettingsMenuPortal(event) {
            event.preventDefault();
            event.stopPropagation();

            var button = event.currentTarget;
            var existingMenu = document.getElementById('metasync-portal-menu');

            if (existingMenu) {
                existingMenu.remove();
                button.classList.remove('active');
                button.setAttribute('aria-expanded', 'false');
                return;
            }

            var menu = document.createElement('div');
            menu.id = 'metasync-portal-menu';
            menu.className = 'metasync-portal-menu';

            var currentUrl = window.location.href;
            var isGeneralActive = currentUrl.indexOf('tab=general') > -1 || currentUrl.indexOf('tab=') === -1;
            var isAdvancedActive = currentUrl.indexOf('tab=advanced') > -1;
            var isWhitelabelActive = currentUrl.indexOf('tab=whitelabel') > -1;

            menu.textContent = '';

            var hideAdvanced = <?php
                echo !Metasync_Access_Control::user_can_access('hide_advanced') ? 'true' : 'false';
            ?>;
            var showGeneral = <?php
                echo Metasync_Access_Control::user_can_access('hide_settings') ? 'true' : 'false';
            ?>;

            if (showGeneral) {
                const generalLink = document.createElement('a');
                generalLink.href = '?page=<?php echo $page_slug; ?>&tab=general';
                generalLink.className = 'metasync-portal-item' + (isGeneralActive ? ' active' : '');
                generalLink.textContent = 'General';
                menu.appendChild(generalLink);
            }

            const whitelabelLink = document.createElement('a');
            whitelabelLink.href = '?page=<?php echo $page_slug; ?>&tab=whitelabel';
            whitelabelLink.className = 'metasync-portal-item' + (isWhitelabelActive ? ' active' : '');
            whitelabelLink.textContent = 'White label';
            menu.appendChild(whitelabelLink);

            if (!hideAdvanced) {
                const advancedLink = document.createElement('a');
                advancedLink.href = '?page=<?php echo $page_slug; ?>&tab=advanced';
                advancedLink.className = 'metasync-portal-item' + (isAdvancedActive ? ' active' : '');
                advancedLink.textContent = 'Advanced';
                menu.appendChild(advancedLink);
            }


            var rect = button.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 8) + 'px';
            menu.style.right = (window.innerWidth - rect.right) + 'px';
            menu.style.zIndex = '999999999';
            
            document.body.appendChild(menu);
            
            button.classList.add('active');
            button.setAttribute('aria-expanded', 'true');
        }
        
        document.addEventListener('click', function(event) {
            var button = document.getElementById('metasync-settings-btn');
            var menu = document.getElementById('metasync-portal-menu');
            
            if (menu && button && !button.contains(event.target) && !menu.contains(event.target)) {
                menu.remove();
                button.classList.remove('active');
                button.setAttribute('aria-expanded', 'false');
            }
        });
        </script>
    <?php
    }

    /**
     * Render the plugin header with logo, theme toggle, and connection badge.
     */
    public function render_plugin_header($page_title = null)
    {
        $general_settings = Metasync::get_option('general');
        $whitelabel_settings = Metasync::get_whitelabel_settings();
        
        $whitelabel_logo = Metasync::get_whitelabel_logo();
        $is_whitelabel = isset($whitelabel_settings['is_whitelabel']) ? $whitelabel_settings['is_whitelabel'] : false;
        
        $effective_plugin_name = Metasync::get_effective_plugin_name();
        
        $display_title = $page_title ?: $effective_plugin_name;
        
        $show_logo = false;
        $logo_url = '';
        
        if (!empty($whitelabel_logo) && filter_var($whitelabel_logo, FILTER_VALIDATE_URL)) {
            $show_logo = true;
            $logo_url = esc_url($whitelabel_logo);
        } elseif (!$is_whitelabel) {
            $show_logo = true;
            $logo_url = Metasync::HOMEPAGE_DOMAIN . '/wp-content/uploads/2023/12/white.svg';
        } else {
            $show_logo = false;
            $logo_url = '';
        }
        
        $searchatlas_api_key = isset($general_settings['searchatlas_api_key']) ? $general_settings['searchatlas_api_key'] : '';
        $otto_pixel_uuid = isset($general_settings['otto_pixel_uuid']) ? $general_settings['otto_pixel_uuid'] : '';
        
        $is_integrated = Metasync_Heartbeat_Manager::instance()->is_heartbeat_connected($general_settings);

        $current_theme = get_option('metasync_theme', 'dark');
        ?>
        
        <!-- Plugin Header with Logo -->
        <div class="metasync-header" data-current-theme="<?php echo esc_attr($current_theme); ?>">
            <div class="metasync-header-left">
                <?php if ($show_logo && !empty($logo_url)): ?>
                    <div class="metasync-logo-container">
                        <img src="<?php echo $logo_url; ?>" alt="Logo" class="metasync-logo" />
        </div>
                <?php endif; ?>
            </div>
            
                         <div class="metasync-header-right">
                             <!-- Theme Toggle -->
                        <div class="metasync-theme-toggle" role="group" aria-label="Theme Selector">
                            <button class="metasync-theme-option <?php echo ($current_theme === 'light') ? 'active' : ''; ?>" data-theme="light" aria-label="Light Theme" type="button">
                                <span class="metasync-theme-icon">☀️</span>
                                <span class="theme-label">Light</span>
                            </button>
                            <button class="metasync-theme-option <?php echo ($current_theme === 'dark') ? 'active' : ''; ?>" data-theme="dark" aria-label="Dark Theme" type="button">
                                <span class="metasync-theme-icon">🌙</span>
                                <span class="theme-label">Dark</span>
                            </button>
                        </div>
                        
                        <!-- Integration Status -->
                 <?php
                 if ($is_integrated && !empty($otto_pixel_uuid)) {
                     $status_class_header = 'integrated';
                     $status_title_header = 'Synced - Heartbeat API connectivity verified';
                     $status_text_header = 'Synced';
                 } elseif ($is_integrated && empty($otto_pixel_uuid)) {
                     $status_class_header = 'warning';
                     $status_title_header = 'Connected but OTTO UUID is missing — deploys will not work. Please reconnect.';
                     $status_text_header = 'Warning';
                 } else {
                     $status_class_header = 'not-integrated';
                     $status_title_header = 'Not Synced - Heartbeat API not responding or unreachable';
                     $status_text_header = 'Not Synced';
                 }
                 ?>
                 <div class="metasync-integration-status <?php echo $status_class_header; ?>"
                      title="<?php echo esc_attr($status_title_header); ?>">
                     <span class="status-indicator"></span>
                     <span class="status-text"><?php echo esc_html($status_text_header); ?></span>
                 </div>
             </div>
        </div>
        
        <!-- Page Title Below Header -->
        <div class="metasync-page-title">
            <h1><?php echo esc_html($display_title); ?></h1>
        </div>
        
    <?php
    }

    // ------------------------------------------------------------------
    //  Admin bar status indicator
    // ------------------------------------------------------------------

    /**
     * Output inline CSS (and optional JS) for the admin-bar status node.
     */
    public function metasync_admin_bar_style()
    {
        if (!is_admin_bar_showing()) {
            return;
        }

        # For backward compatibility, constant takes precedence.
        if (defined('METASYNC_SHOW_ADMIN_BAR_STATUS') && !METASYNC_SHOW_ADMIN_BAR_STATUS) {
            return;
        }


        # Check if admin bar status is enabled via setting
        $general_settings = Metasync::get_option('general');
        $show_admin_bar = $general_settings['show_admin_bar_status'] ?? true;
        if (!$show_admin_bar) {
            return;
        }
        ?>
        <style type="text/css">
        #wp-admin-bar-searchatlas-status .ab-item {
            font-weight: 500 !important;
            transition: all 0.2s ease !important;
        }
        
        #wp-admin-bar-searchatlas-status:hover .ab-item {
            background-color: rgba(255, 255, 255, 0.1) !important;
        }
        
        #wp-admin-bar-searchatlas-status.searchatlas-synced .ab-item {
            color: #46b450 !important; /* WordPress green for text */
        }
        
        #wp-admin-bar-searchatlas-status.searchatlas-not-synced .ab-item {
            color: #dc3232 !important; /* WordPress red for text */
        }

        #wp-admin-bar-searchatlas-status.searchatlas-warning .ab-item {
            color: #ffb900 !important; /* WordPress yellow for warning */
        }

        /* Ensure emojis maintain their natural colors */
        #wp-admin-bar-searchatlas-status .ab-item {
            filter: none !important;
        }
        
        /* Make status visible but subtle */
        #wp-admin-bar-searchatlas-status .ab-item {
            opacity: 0.9;
        }
        
        #wp-admin-bar-searchatlas-status:hover .ab-item {
            opacity: 1;
        }
        </style>
        
        <?php 
        $page_slug = Metasync_Admin::$page_slug;
        $is_plugin_page = (
            (isset($_GET['page']) && strpos($_GET['page'], $page_slug) !== false) ||
            (isset($_GET['page']) && strpos($_GET['page'], 'searchatlas') !== false)
        );
        if ($is_plugin_page): 
        ?>
        <script type="text/javascript">
        // Pass PHP variables to JavaScript
        window.MetasyncConfig = {
            pluginName: '<?php echo esc_js(Metasync::get_effective_plugin_name()); ?>',
            ottoName: '<?php echo esc_js(Metasync::get_whitelabel_otto_name()); ?>'
        };
        
        jQuery(document).ready(function($) {
            
            // Function to sync admin bar status
            function syncAdminBarStatus() {
                var pluginPageStatus = $('.metasync-integration-status .status-text').text();
                var adminBarItem = $('#wp-admin-bar-searchatlas-status .ab-item');
                var adminBarContainer = $('#wp-admin-bar-searchatlas-status');
                var pluginName = window.MetasyncConfig.pluginName;
                
                if (pluginPageStatus && adminBarItem.length) {
                    var allClasses = 'searchatlas-synced searchatlas-not-synced searchatlas-warning';

                    // Helper to update emoji in admin bar
                    function updateAdminBarEmoji(targetEmoji, targetSvgCode) {
                        var emojiImg = adminBarItem.find('img.emoji');
                        if (emojiImg.length > 0) {
                            emojiImg.attr('alt', targetEmoji);
                            var currentSrc = emojiImg.attr('src');
                            var updatedSrc = currentSrc.replace(/1f7e2\.svg|1f534\.svg|1f7e1\.svg/, targetSvgCode + '.svg');
                            emojiImg.attr('src', updatedSrc);
                        } else {
                            var newHtml = adminBarItem.html().replace(/🟢|🔴|🟡/, targetEmoji);
                            if (!newHtml.includes(targetEmoji) && newHtml.includes(pluginName)) {
                                newHtml = newHtml.replace(pluginName, pluginName + ' ' + targetEmoji);
                            }
                            adminBarItem.html(newHtml);
                        }
                    }

                    if (pluginPageStatus.includes('Synced') && !pluginPageStatus.includes('Not Synced')) {
                        // Update admin bar to synced (GREEN)
                        updateAdminBarEmoji('🟢', '1f7e2');
                        adminBarContainer.removeClass(allClasses).addClass('searchatlas-synced');
                        var syncTitle = pluginName + ' - Synced (Heartbeat API connectivity verified)';
                        adminBarContainer.attr('title', syncTitle);
                        adminBarItem.attr('title', syncTitle);

                    } else if (pluginPageStatus.includes('Warning')) {
                        // Update admin bar to warning (YELLOW)
                        updateAdminBarEmoji('🟡', '1f7e1');
                        adminBarContainer.removeClass(allClasses).addClass('searchatlas-warning');
                        var warnTitle = pluginName + ' - Connected but OTTO UUID is missing — deploys will not work. Please reconnect.';
                        adminBarContainer.attr('title', warnTitle);
                        adminBarItem.attr('title', warnTitle);

                    } else if (pluginPageStatus.includes('Not Synced')) {
                        // Update admin bar to not synced (RED)
                        updateAdminBarEmoji('🔴', '1f534');
                        adminBarContainer.removeClass(allClasses).addClass('searchatlas-not-synced');
                        var notSyncTitle = pluginName + ' - Not Synced (Heartbeat API not responding or unreachable)';
                        adminBarContainer.attr('title', notSyncTitle);
                        adminBarItem.attr('title', notSyncTitle);
                    }
                }
            }
            
            // Sync when tabs are switched (for General/Advanced tabs)
            $(document).on('click', 'a[href*="tab="]', function() {
                setTimeout(syncAdminBarStatus, 200);
            });
            
            // Also check every 5 seconds to keep it in sync
            setInterval(syncAdminBarStatus, 5000);
        });
        </script>
        <?php endif; ?>
        <?php
    }

    /**
     * Add Search Atlas status indicator to WordPress admin bar.
     */
    public function add_searchatlas_admin_bar_status($wp_admin_bar)
    {
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }

        # For backward compatibility, constant takes precedence.
        if (defined('METASYNC_SHOW_ADMIN_BAR_STATUS') && !METASYNC_SHOW_ADMIN_BAR_STATUS) {
            return;
        }
        
        # Check if admin bar status is disabled via setting
        $general_settings = Metasync::get_option('general');
        $show_admin_bar = $general_settings['show_admin_bar_status'] ?? true;
        if (!$show_admin_bar) {
            return;
        }
        
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }

        if (!is_admin() && !apply_filters('metasync_show_admin_bar_status_frontend', false)) {
            return;
        }

        $general_settings = Metasync::get_option('general');
        if (!is_array($general_settings)) {
            $general_settings = [];
        }
        
        $is_synced = Metasync_Heartbeat_Manager::instance()->is_heartbeat_connected($general_settings);
        
        $plugin_name = Metasync::get_effective_plugin_name();
        
        if ($is_synced) {
            $otto_uuid = $general_settings['otto_pixel_uuid'] ?? '';
            if (!empty($otto_uuid)) {
                $status_emoji = '🟢'; // Green circle for synced
                $title = $plugin_name . ' - Synced (Heartbeat API connectivity verified)';
                $status_class = 'searchatlas-synced';
            } else {
                $status_emoji = '🟡'; // Yellow circle for warning
                $title = $plugin_name . ' - Connected but OTTO UUID is missing — deploys will not work. Please reconnect.';
                $status_class = 'searchatlas-warning';
            }
        } else {
            $status_emoji = '🔴'; // Red circle for not synced
            $title = $plugin_name . ' - Not Synced (Heartbeat API not responding or unreachable)';
            $status_class = 'searchatlas-not-synced';
        }

        $display_name = $plugin_name;
        $admin_bar_title = $display_name . ' ' . $status_emoji;
        
        $wp_admin_bar->add_node(array(
            'id'    => 'searchatlas-status',
            'title' => $admin_bar_title,
            'href'  => admin_url('admin.php?page=' . Metasync_Admin::$page_slug),
            'meta'  => array(
                'title' => $title,
                'class' => $status_class
            )
        ));
    }
}

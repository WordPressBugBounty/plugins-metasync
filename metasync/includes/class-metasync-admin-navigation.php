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

    const CACHE_KEY = 'metasync_admin_bar_status';

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
    //  Logo resolution helper
    // ------------------------------------------------------------------

    /**
     * Resolve which logo(s) to display based on whitelabel settings.
     *
     * @return array{show_logo: bool, use_dual: bool, url: string, light_url: string, dark_url: string}
     */
    private function resolve_logo_data()
    {
        $wl_settings  = Metasync::get_whitelabel_settings();
        $is_whitelabel = !empty($wl_settings['is_whitelabel']);

        $light_raw = Metasync::get_whitelabel_logo_light();
        $dark_raw  = Metasync::get_whitelabel_logo_dark();
        $has_light = !empty($light_raw) && filter_var($light_raw, FILTER_VALIDATE_URL);
        $has_dark  = !empty($dark_raw) && filter_var($dark_raw, FILTER_VALIDATE_URL);
        $use_dual  = ($has_light && $has_dark && $light_raw !== $dark_raw);

        $data = [
            'show_logo' => false,
            'use_dual'  => false,
            'url'       => '',
            'light_url' => '',
            'dark_url'  => '',
        ];

        if ($use_dual) {
            $data['show_logo'] = true;
            $data['use_dual']  = true;
            $data['light_url'] = $light_raw;
            $data['dark_url']  = $dark_raw;
        } else {
            $single = $has_light ? $light_raw : ($has_dark ? $dark_raw : null);
            if ($single) {
                $data['show_logo'] = true;
                $data['url']       = $single;
            } elseif (!$is_whitelabel) {
                $data['show_logo'] = true;
                $data['url']       = Metasync::HOMEPAGE_DOMAIN . '/wp-content/uploads/2023/12/white.svg';
            }
        }

        return $data;
    }

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
        $effective_plugin_name = Metasync::get_effective_plugin_name();
        $display_title = $page_title ?: $effective_plugin_name;
        $logo = $this->resolve_logo_data();

        $current_theme = get_option('metasync_theme', 'dark');
        $general_settings = Metasync::get_option('general');
        $searchatlas_api_key = isset($general_settings['searchatlas_api_key']) ? $general_settings['searchatlas_api_key'] : '';
        $is_integrated = !empty($searchatlas_api_key);
        ?>
        <div class="metasync-header" data-current-theme="<?php echo esc_attr($current_theme); ?>">
            <div class="metasync-header-left">
                <?php if ($logo['show_logo'] && $logo['use_dual']): ?>
                    <div class="metasync-logo-container">
                        <img src="<?php echo esc_url($logo['light_url']); ?>" alt="Logo" class="metasync-logo metasync-logo-light" />
                        <img src="<?php echo esc_url($logo['dark_url']); ?>" alt="Logo" class="metasync-logo metasync-logo-dark" />
                    </div>
                <?php elseif ($logo['show_logo'] && !empty($logo['url'])): ?>
                    <div class="metasync-logo-container">
                        <img src="<?php echo esc_url($logo['url']); ?>" alt="Logo" class="metasync-logo" />
                    </div>
                <?php endif; ?>
            </div>
            <div class="metasync-header-right">
                <div class="metasync-status <?php echo $is_integrated ? 'connected' : 'disconnected'; ?>">
                    <span class="status-dot"></span>
                    <span class="status-text"><?php echo $is_integrated ? 'Connected' : 'Not Connected'; ?></span>
                </div>
                <button type="button" class="metasync-theme-toggle" onclick="toggleMetasyncTheme()" title="Toggle theme">
                    <span class="theme-icon-light">&#9728;</span>
                    <span class="theme-icon-dark">&#9790;</span>
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
            'dashboard'        => 'dashboard',
            'seo_controls'     => 'search',
            'optimal_settings' => 'superhero-alt',
            'instant_index'    => 'performance',
            'google_console'   => 'chart-area',
            'compatibility'    => 'admin-tools',
            'sync_log'         => 'list-view',
            'redirections'     => 'undo',
            'robots_txt'       => 'shield',
            'xml_sitemap'      => 'networking',
            'custom_pages'     => 'admin-page',
            'report_issue'     => 'sos',
            'general'          => 'admin-settings',
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
            $menu_items['sync_log'] = ['title' => 'Changes Log', 'slug_suffix' => '-sync-log'];
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
                    $icon = $menu_icons[$key] ?? 'admin-generic';
                    $page_url = '?page=' . $page_slug . $menu_item['slug_suffix'];
                ?>
                    <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-tab <?php echo $is_active ? 'active' : ''; ?>">
                        <span class="tab-icon"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></span>
                        <span class="tab-text"><?php echo esc_html($menu_item['title']); ?></span>
                    </a>
                <?php endforeach; ?>
                </div>
                <div class="metasync-nav-right">
                    <a href="?page=<?php echo esc_attr( $page_slug ); ?>-custom-pages" class="metasync-nav-tab <?php echo $current_page === 'custom_pages' ? 'active' : ''; ?>" style="margin-right: 10px;">
                        <span class="tab-icon"><span class="dashicons dashicons-admin-page"></span></span>
                        <span class="tab-text">Custom Pages</span>
                    </a>
                    <a href="?page=<?php echo esc_attr( $page_slug ); ?>-report-issue" class="metasync-nav-tab <?php echo $current_page === 'report_issue' ? 'active' : ''; ?>">
                        <span class="tab-icon"><span class="dashicons dashicons-sos"></span></span>
                        <span class="tab-text">Report Issue</span>
                    </a>
                    <div class="metasync-simple-dropdown">
                        <button type="button" class="metasync-seo-btn" id="metasync-seo-btn" onclick="toggleSeoMenuPortal(event)" aria-expanded="false">
                            <span class="tab-icon"><span class="dashicons dashicons-search"></span></span>
                            <span class="tab-text">SEO</span>
                            <span class="dropdown-arrow">▼</span>
                        </button>
                    </div>
                    <div class="metasync-simple-dropdown">
                        <button type="button" class="metasync-settings-btn" id="metasync-settings-btn" onclick="toggleSettingsMenuPortal(event)" aria-expanded="false">
                            <span class="tab-icon"><span class="dashicons dashicons-admin-settings"></span></span>
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
                button.setAttribute('aria-expanded', 'false');
                return;
            }
            var menu = document.createElement('div');
            menu.id = 'metasync-seo-portal-menu';
            menu.className = 'metasync-portal-menu';

            var pageSlug = '<?php echo esc_js( $page_slug ); ?>';
            var seoLinks = [
                { href: '?page=' + pageSlug + '-seo-controls', text: 'Indexation Control' },
                { href: '?page=' + pageSlug + '-xml-sitemap',  text: 'XML Sitemap' },
                { href: '?page=' + pageSlug + '-robots-txt',   text: 'Robots.txt' },
                { href: '?page=' + pageSlug + '-redirections', text: 'Redirections' },
            ];
            seoLinks.forEach(function(item) {
                var link = document.createElement('a');
                link.href = item.href;
                link.className = 'metasync-portal-item';
                link.textContent = item.text;
                menu.appendChild(link);
            });

            var rect = button.getBoundingClientRect();
            menu.style.position = 'fixed';
            menu.style.top = (rect.bottom + 8) + 'px';
            menu.style.right = (window.innerWidth - rect.right) + 'px';
            menu.style.zIndex = '999999999';
            document.body.appendChild(menu);
            button.classList.add('active');
            button.setAttribute('aria-expanded', 'true');
        }

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

            var hideAdvanced = <?php echo !empty($whitelabel_settings['hide_advanced']) ? 'true' : 'false'; ?>;
            var showGeneral = <?php echo Metasync_Access_Control::user_can_access('hide_settings') ? 'true' : 'false'; ?>;
            
            if (showGeneral) {
                var generalLink = document.createElement('a');
                generalLink.href = '?page=<?php echo esc_js( $page_slug ); ?>&tab=general';
                generalLink.className = 'metasync-portal-item';
                generalLink.textContent = 'General';
                menu.appendChild(generalLink);
            }

            var whitelabelLink = document.createElement('a');
            whitelabelLink.href = '?page=<?php echo esc_js( $page_slug ); ?>&tab=whitelabel';
            whitelabelLink.className = 'metasync-portal-item';
            whitelabelLink.textContent = 'White Label';
            menu.appendChild(whitelabelLink);

            if (!hideAdvanced) {
                var advancedLink = document.createElement('a');
                advancedLink.href = '?page=<?php echo esc_js( $page_slug ); ?>&tab=advanced';
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
            button.setAttribute('aria-expanded', 'true');
        }
        document.addEventListener('click', function(event) {
            var seoButton = document.getElementById('metasync-seo-btn');
            var seoMenu = document.getElementById('metasync-seo-portal-menu');
            if (seoMenu && seoButton && !seoButton.contains(event.target) && !seoMenu.contains(event.target)) {
                seoMenu.remove();
                seoButton.classList.remove('active');
                seoButton.setAttribute('aria-expanded', 'false');
            }

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
        
        // Schema Markup
        $menu_items['schema_markup'] = [
            'title' => 'Schema Markup',
            'slug_suffix' => '-schema-markup',
            'callback' => 'create_admin_schema_markup_page',
            'internal_nav' => 'Schema Markup',
            'group' => 'seo'
        ];

        // 404 Monitor (always available — SEO monitoring tool)
        $menu_items['monitor_404'] = [
            'title' => '404 Monitor',
            'slug_suffix' => '-404-monitor',
            'callback' => 'create_admin_404_monitor_page',
            'internal_nav' => '404 Monitor',
            'group' => 'seo'
        ];

        // Site Verification
        $menu_items['site_verification'] = [
            'title' => 'Site Verification',
            'slug_suffix' => '-search-engine-verify',
            'callback' => 'create_admin_search_engine_verification_page',
            'internal_nav' => 'Site Verification',
            'group' => 'seo'
        ];

        // SEO Health dashboard (always available)
        $menu_items['seo_health'] = [
            'title' => 'SEO Health',
            'slug_suffix' => '-seo-health',
            'callback' => 'create_admin_seo_health_page',
            'internal_nav' => 'SEO Health',
            'group' => 'seo'
        ];

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

        // Settings (check access control)
        if (Metasync_Access_Control::user_can_access('hide_settings')) {
            $menu_items['general'] = [
                'title' => 'Settings',
                'slug_suffix' => '',
                'callback' => 'create_admin_settings_page',
                'internal_nav' => 'General Settings',
                'group' => 'plugin'
            ];
        }

        // Local Business
        $menu_items['local_business'] = [
            'title' => 'Local Business',
            'slug_suffix' => '-local-business',
            'callback' => 'create_admin_local_business_page',
            'internal_nav' => 'Local Business',
            'group' => 'seo'
        ];

        // Breadcrumbs
        $menu_items['breadcrumbs'] = [
            'title' => 'Breadcrumbs',
            'slug_suffix' => '-breadcrumbs',
            'callback' => 'create_admin_breadcrumbs_page',
            'internal_nav' => 'Breadcrumbs',
            'group' => 'seo'
        ];

        // Code Snippets
        $menu_items['code_snippets'] = [
            'title' => 'Code Snippets',
            'slug_suffix' => '-code-snippets',
            'callback' => 'create_admin_code_snippets_page',
            'internal_nav' => 'Code Snippets',
            'group' => 'plugin'
        ];

        // Code Minification
        $menu_items['code_minification'] = [
            'title' => 'Code Minification',
            'slug_suffix' => '-code-minification',
            'callback' => 'create_admin_code_minification_page',
            'internal_nav' => 'Code Minification',
            'group' => 'plugin'
        ];

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
                'title' => 'Changes Log',
                'slug_suffix' => '-sync-log',
                'callback' => 'create_admin_sync_log_page',
                'internal_nav' => 'Changes Log',
                'group' => 'plugin'
            ];
        }

        // Bot Statistics
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
        $menu_icon = !isset($data['white_label_plugin_menu_icon']) || $data['white_label_plugin_menu_icon'] == "" ? 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMzIiIGhlaWdodD0iMzQiIHZpZXdCb3g9IjAgMCAzMiAzNCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPGcgY2xpcC1wYXRoPSJ1cmwoI2NsaXAwXzE4NzlfMTcyNzcpIj4KPHBhdGggZD0iTTI5LjAyMTUgMi4xNjc2NkwzMC4wMTAxIDIuMTIyNDFMMjkuMTYyNyA4LjcyNzA1TDI5LjExNTcgOC44MTc0M0w1LjI5NTA0IDMzLjI0NTJMNC43MzAxMiAzMy4yOTA1TDQuMDcxMDQgMzMuOTY5MUgxLjk1MjYyTDIuMjM1MDggMzIuMjk1M0wyLjA5Mzg0IDMyLjM0MDZMMi4xODggMzEuNjYySDEuNjIzMDhDMS41NzYgMzEuNjYyIDEuNDgxODUgMzEuNjYyIDEuNDgxODUgMzEuNjE2N0MxLjQzNDc4IDMxLjU3MTYgMS4zODc3IDMxLjQ4MSAxLjM4NzcgMzEuNDM1OEwyLjUxNzU0IDI1LjEwMjdDMi41MTc1NCAyNS4wNTc1IDIuNTY0NiAyNS4wMTIyIDIuNTY0NiAyNS4wMTIyTDI2LjE5NjkgMC42Mjk2MDdDMjYuMjQ0IDAuNTg0MzY2IDI2LjI5MTEgMC41MzkxMjUgMjYuMzM4MSAwLjUzOTEyNUgyOC4zNjI1QzI4LjQwOTUgMC40OTM4ODQgMjguNDU2NiAwLjUzOTEyNSAyOC41MDM3IDAuNTg0MzY2QzI4LjU1MDcgMC42Mjk2MDcgMjguNTk3OSAwLjcyMDA3MSAyOC41OTc5IDAuNzY1MzExTDI4LjUwMzcgMS4zNTMzOUgyOS4xMTU3TDI5LjAyMTUgMi4xNjc2NlpNMS45MDU1NCAzMS4yMDk2SDIuMjgyMTZMMy4yMjM2OCAyNS43ODEySDMuMjcwNzZMNi44MDE1IDIyLjI1MjhMNi44NDg1MSAyMi4yOTgxTDIwLjIxODMgOC40NTU1N0wyNi45MDMxIDEuMzUzMzlIMjguMDMyOUwyOC4wNzk5IDAuOTkxNDk5SDI2LjQ3OTNMMi45ODgzIDI1LjIzODRMMS45MDU1NCAzMS4yMDk2Wk0yOC42OTE5IDguNTQ1OTVMMjkuNDQ1MiAyLjYyMDAxSDI4Ljk3NDVMMjguMzE1MyA3Ljc3Njk2TDI4LjI2ODMgNy44MjIyNEwyOC4xMjcxIDcuOTU3ODlMMjcuOTM4NyA5LjMxNTExTDI4LjY5MTkgOC41NDU5NVoiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik0zMS41NTQ0IDE4LjE5MzdDMzEuNzM0OSAxOC42NjYyIDMxLjgwMjggMTkuMjA2NCAzMS42ODk4IDE5Ljc2OUwyOS42NTgyIDMyLjEwMTNIMjkuMDkzOUwyOS4wMjYyIDMyLjQzODhIMjUuOTMzNkwyNi4wNjkxIDMxLjYwNjJIMjYuMDAxM0wyNi4wNjkxIDMxLjI2ODZIMjUuNzk4MUMyNS43NTMgMzEuMjY4NiAyNS43MzA1IDMxLjI2ODYgMjUuNzA3OSAzMS4yNDYxQzI1LjY4NTIgMzEuMjIzNyAyNS42ODUyIDMxLjE3ODYgMjUuNjg1MiAzMS4xNTZMMjYuMzYyNCAyNy4zOThIMTguNDE2N0wxNy40MjM0IDMyLjA3ODdIMTYuODM2NUwxNi43Njg3IDMyLjQxNjNIMTMuNjk4OEwxMy45MDE5IDMxLjU4MzZIMTMuODM0M0wxMy45MDE5IDMxLjI2ODZIMTMuNjMxQzEzLjYwODQgMzEuMjY4NiAxMy41NjMzIDMxLjI2ODYgMTMuNTQwOCAzMS4yNDYxQzEzLjU0MDggMzEuMjAxMSAxMy41MTgyIDMxLjE3ODYgMTMuNTQwOCAzMS4xMzM2TDE2LjM2MjQgMTguOTEzOEMxNi40OTc5IDE4LjM1MTIgMTYuNzQ2MiAxNy44MzM2IDE3LjEyOTkgMTcuMzM4NUMxNy40OTEyIDE2Ljg2NiAxNy45NDI2IDE2LjQ4MzUgMTguNDYxOCAxNi4yMTM0QzE4Ljk4MSAxNS45MjA3IDE5LjUwMDIgMTUuNzg1OCAyMC4wNDE5IDE1Ljc4NThIMjguNTk3MkMyOS4xMzkgMTUuNzg1OCAyOS42MTMxIDE1LjkyMDcgMzAuMDE5MyAxNi4yMTM0QzMwLjM1NzkgMTYuNDYwOSAzMC42Mjg5IDE2Ljc5ODUgMzAuODA5NSAxNy4xODFDMzEuMTI1NSAxNy40NTExIDMxLjM5NjMgMTcuNzg4NiAzMS41NTQ0IDE4LjE5MzdaTTI3LjQ5MTIgMjMuMzY5N0wyOC4wNTU1IDIwLjI2NDFDMjguMDMyOSAyMC4yNDE1IDI4LjAzMjkgMjAuMjE5MSAyOC4wMTA0IDIwLjIxOTFIMjcuODk3NUwyNy4zNzgzIDIzLjA5OTZDMjcuMzc4MyAyMy4xNDQ3IDI3LjMxMDYgMjMuMTg5NiAyNy4yNjU1IDIzLjE4OTZIMTkuMzQyMUwxOS4yOTcgMjMuMzY5N0gyNy40OTEyWk0xOS4wOTM5IDIzLjE4OTZIMTguODQ1NUwxOC44MjI5IDIzLjM2OTdIMTkuMDcxM0wxOS4wOTM5IDIzLjE4OTZaTTE5LjUwMDIgMjAuMjY0MUwxOC45MTMzIDIyLjk2NDZIMTkuMTYxNUwxOS43NDg0IDIwLjIxOTFIMTkuNTQ1M0MxOS41NDUzIDIwLjIxOTEgMTkuNTIyNyAyMC4yNDE1IDE5LjUwMDIgMjAuMjY0MVpNMjcuNjcxOCAyMC4yMTkxSDE5Ljk3NDJMMTkuMzg3MiAyMi45NjQ2SDI3LjE3NTFMMjcuNjcxOCAyMC4yMTkxWk0xMy43ODkgMzEuMDQzNkgxMy45NDdMMTUuMDk4NCAyNi4xMTUySDE1LjE2NkwxNi43MjM2IDE5LjI5NjRDMTYuODU5MSAxOC43NTYzIDE3LjEwNzMgMTguMjM4OCAxNy40Njg1IDE3Ljc2NjFDMTcuODI5NiAxNy4zMTYxIDE4LjI4MTIgMTYuOTMzNCAxOC43Nzc4IDE2LjY2MzNDMTkuMDI2MSAxNi41Mjg0IDE5LjI3NDUgMTYuNDM4NCAxOS41MjI3IDE2LjM0ODNMMTkuNTAwMiAxNi4zMDM0QzE5Ljc0ODQgMTYuMjEzNCAyMC4wMTkzIDE2LjE5MDggMjAuMjkwMyAxNi4xOTA4SDI4Ljg2ODJDMjkuMjI5NCAxNi4xOTA4IDI5LjU5MDUgMTYuMjU4MyAyOS44ODQgMTYuMzkzNEMyOS41MjI5IDE2LjEyMzMgMjkuMDkzOSAxNi4wMTA4IDI4LjU5NzIgMTYuMDEwOEgyMC4wNDE5QzE5LjU0NTMgMTYuMDEwOCAxOS4wNDg2IDE2LjE0NTkgMTguNTc0NyAxNi4zOTM0QzE4LjA3OCAxNi42NjMzIDE3LjY0OTEgMTcuMDIzNSAxNy4zMTA0IDE3LjQ5NkMxNi45NDkzIDE3Ljk0NjIgMTYuNzAxIDE4LjQ0MTIgMTYuNTg4MSAxOC45NTg5TDEzLjc4OSAzMS4wNDM2Wk0xNy4yMjAyIDMxLjg1MzdMMTguMTkxIDI3LjM5OEgxNy45NDI2TDE3LjAxNzEgMzEuNTgzNkgxNi45NDkzTDE2LjkwNDIgMzEuODUzN0gxNy4yMjAyWk0yNS45MzM2IDMxLjA0MzZIMjYuMTE0MkwyNi43Njg5IDI3LjM5OEgyNi41ODgzTDI1LjkzMzYgMzEuMDQzNlpNMzEuNDg2NyAxOS43NDY0QzMxLjU3NjkgMTkuMjA2NCAzMS41MDkzIDE4LjcxMTMgMzEuMzI4NyAxOC4yNjEyQzMxLjI4MzYgMTguMTQ4OCAzMS4yMTU4IDE4LjAzNjIgMzEuMTQ4MSAxNy45MjM2QzMxLjI4MzYgMTguMzUxMiAzMS4zMjg3IDE4LjgwMTQgMzEuMjM4MyAxOS4yOTY0TDMwLjA4NzIgMjYuMTE1MkwzMC4xNTQ4IDI2LjEzNzdMMjkuMjUxOSAzMS42MDYySDI5LjE4NDNMMjkuMTM5IDMxLjg3NjNIMjkuNDU1TDMxLjQ4NjcgMTkuNzQ2NFoiIGZpbGw9IndoaXRlIi8+CjxwYXRoIGQ9Ik0xNy42ODQ3IDIuNDI3MTdIMTcuNjYxOEMxNy44NjgyIDIuODk5MTIgMTcuOTE0IDMuNDM4NDkgMTcuODIyMiA0LjAwMDMzTDE3LjU5MjkgNS4zOTM3SDE3LjA0MjNMMTYuOTczNSA1LjczMDhIMTMuOTY4NEwxNC4xMjkxIDQuODk5MjhIMTQuMDM3M0wxNC4xMDYyIDQuNTg0NjRIMTMuODUzOEMxMy44MDggNC41ODQ2NCAxMy43ODUxIDQuNTYyMTcgMTMuNzYyIDQuNTM5NjlDMTMuNzM5MSA0LjUxNzIzIDEzLjczOTEgNC40OTQ3NSAxMy43MzkxIDQuNDQ5OEg1LjkxNjg2TDUuNTQ5ODcgNi4wOTAzOUgxMy41Nzg1QzE0LjEyOTEgNi4wOTAzOSAxNC42MzM3IDYuMjQ3NzEgMTUuMDQ2NyA2LjUzOTg3QzE1LjM2NzggNi43NDIxMyAxNS41OTcxIDcuMDU2NzcgMTUuODAzNiA3LjM3MTQxQzE1Ljg0OTUgNy40Mzg4OSAxNS44NzI0IDcuNDgzODIgMTUuOTE4NCA3LjU1MTEyQzE2LjIxNjYgNy43OTgzMiAxNi40Njg4IDguMTEyOTkgMTYuNjI5NSA4LjQ5NTE0QzE2LjgzNTkgOC45NjY5OCAxNi45MDQ4IDkuNTA2NDcgMTYuNzkwMSAxMC4wOTA3TDE2LjI4NTMgMTMuMTY5NkMxNi4xOTM1IDEzLjczMTUgMTUuOTQxMyAxNC4yNDg0IDE1LjU3NDMgMTQuNzQyOEMxNS4yMDcyIDE1LjIxNDcgMTQuNzQ4NCAxNS41OTY4IDE0LjIyMDggMTUuODg4OUMxNC4xNTIgMTUuOTExNSAxNC4wNjAyIDE1Ljk1NjQgMTMuOTkxNSAxNS45Nzg4QzEzLjg3NjcgMTYuMDY4OCAxMy43NjIgMTYuMTU4NyAxMy42MjQ1IDE2LjIyNkMxMy4wOTY4IDE2LjQ5NTcgMTIuNTY5MiAxNi42NTMxIDExLjk5NTcgMTYuNjUzMUgyLjYzNjYxQzIuMDg2MDcgMTYuNjUzMSAxLjU4MTQxIDE2LjQ5NTcgMS4xNjg1IDE2LjIyNkMwLjc1NTYxIDE1Ljk1NjQgMC40ODAzMzEgMTUuNTc0MyAwLjMxOTc1IDE1LjEyNDhDMC4xODIxMiAxNC43NjUyIDAuMTU5MTg3IDE0LjQwNTggMC4xODIxMTkgMTQuMDAxMkMwLjE4MjExOSAxMy45NTYxIDAuMTM2MjU0IDEzLjkzMzggMC4xMzYyNTQgMTMuODg4OEMtMC4wMjQzMjYxIDEzLjQxNjggLTAuMDQ3MjU4MSAxMi44Nzc1IDAuMDkwMzcyNSAxMi4zMTU2TDAuMzg4NTgzIDExLjAxMjJDMC4zODg1ODMgMTAuOTY3MyAwLjQ1NzM5OCAxMC45MjIzIDAuNTAzMjY0IDEwLjkyMjNIMy41NTQxN0MzLjU3NzExIDEwLjkyMjMgMy42MDAwNCAxMC45NDQ3IDMuNjIyOTkgMTAuOTY3M0MzLjY0NTkyIDEwLjk4OTYgMy42Njg4NyAxMS4wMzQ2IDMuNjQ1OTIgMTEuMDU3MUwzLjYwMDA0IDExLjMyNjlIMy44OTgyNUwzLjgwNjUgMTEuNzMxNEg0LjMxMTE2TDQuMjE5MzkgMTIuMjAzMkgxMi4yNzFDMTIuMjcxIDEyLjIwMzIgMTIuMjcxIDEyLjIwMzIgMTIuMjkzOSAxMi4xODA4QzEyLjI5MzkgMTIuMTU4MyAxMi4zMTY4IDEyLjE1ODMgMTIuMzE2OCAxMi4xNTgzTDEyLjU5MjEgMTAuNTYyN0gzLjk5MDAyQzMuNDM5NDggMTAuNTYyNyAyLjk1Nzc1IDEwLjQyNzggMi41Njc3OSAxMC4xMzU2QzIuMTU0ODggOS44NjU5IDEuODc5NjIgOS41MDY0NyAxLjcxOTA0IDkuMDM0NDZDMS42MDQzNCA4LjY5NzQxIDEuNTU4NDYgOC4zMzc4MSAxLjYwNDM0IDcuOTMzMjhDMS42MDQzNCA3Ljg4ODM1IDEuNTU4NDYgNy44NjU4IDEuNTU4NDYgNy44MjA4N0MxLjM5NzkgNy4zNDg4NiAxLjM3NDk3IDYuODA5NTYgMS41MTI2IDYuMjI1MjNMMi4yNDY2NSAzLjE0NjM0QzIuMzg0MjggMi41ODQ0OSAyLjYzNjYxIDIuMDY3NTggMy4wMDM2MyAxLjU3MzE2QzMuMzcwNjYgMS4xMDEyMiAzLjgyOTQ0IDAuNzE5MTUyIDQuMzU3MDQgMC40NDk0NzZDNC44ODQ2MyAwLjE1NzMxOSA1LjQxMjI0IDAgNS45NjI4MyAwSDE0LjcwMjZDMTUuMjMwMSAwIDE1LjcxMTggMC4xNTczMTkgMTYuMTI0OCAwLjQ0OTQ3NkMxNi40NDU5IDAuNjc0MjA2IDE2LjY3NTMgMC45NjYzOCAxNi44NTg4IDEuMzAzNDhDMTYuOTA0OCAxLjM0ODQzIDE2LjkyNzcgMS4zOTMzNyAxNi45NzM1IDEuNDYwOEMxNy4yNzE4IDEuNzMwNDggMTcuNTI0IDIuMDIyNjQgMTcuNjg0NyAyLjQyNzE3Wk0zLjY0NTkyIDEyLjU4NTRWMTIuNjA3OEgzLjg5ODI1TDMuOTIxMiAxMi40MjhIMy42Njg4N0wzLjY0NTkyIDEyLjU2MjhDMy42MjI5OSAxMi41NjI4IDMuNjIyOTkgMTIuNTg1NCAzLjY0NTkyIDEyLjU4NTRaTTQuOTk5MzMgNi41NjIzNUg1LjIwNTc4TDUuMjc0NjEgNi4zMTUxNEg0Ljk1MzQ1TDQuOTA3NTggNi40NDk5N0M0LjkwNzU4IDYuNDk0OTIgNC45MDc1OCA2LjUxNzQgNC45MzA1MSA2LjUzOTg3QzQuOTUzNDUgNi41NjIzNSA0Ljk3NjQgNi41NjIzNSA0Ljk5OTMzIDYuNTYyMzVaTTEuNzQxOTcgNi4yNzAxN0MxLjY1MDIzIDYuNjc0NyAxLjY1MDIzIDcuMDU2NzcgMS43MTkwNCA3LjM5Mzc5TDEuNzQxOTcgNy4yMzY1NUMxLjc2NDkyIDcuMDExODEgMS43ODc4NiA2LjgwOTU2IDEuODMzNzQgNi41ODQ4MUwyLjU0NDg0IDMuNTI4MzlDMi42ODI0OSAyLjk2NjU0IDIuOTM0ODIgMi40NDk2NSAzLjMwMTg1IDEuOTc3NjlDMy4zNDc3MSAxLjkzMjc0IDMuMzcwNjYgMS44ODc4IDMuMzkzNTkgMS44NDI4NUwzLjQ2MjQxIDEuOTEwMjhDMy44MDY1IDEuNDgzMjcgNC4xOTY0NiAxLjE0NjE2IDQuNjc4MTkgMC44OTg5NTNDNS4xODI4NCAwLjYyOTI2IDUuNjg3NSAwLjQ5NDQyMyA2LjIxNTA2IDAuNDk0NDIzSDE0Ljk3NzlDMTUuMTg0MyAwLjQ5NDQyMyAxNS4zOTA3IDAuNTE2OTA0IDE1LjU5NzEgMC41NjE4NUwxNS42MiAwLjQ5NDQyM0MxNS43MzQ5IDAuNTE2OTA0IDE1Ljg0OTUgMC41NjE4NSAxNS45NjQyIDAuNjA2Nzk2QzE1LjU5NzEgMC4zNTk1ODUgMTUuMTg0MyAwLjI0NzIxMSAxNC43MDI2IDAuMjQ3MjExSDUuOTYyODNDNS40NTgxMiAwLjI0NzIxMSA0Ljk1MzQ1IDAuMzU5NTg0IDQuNDcxNzQgMC42MjkyNkMzLjk2NzA3IDAuODk4OTUzIDMuNTU0MTcgMS4yNTg1NCAzLjE4NzE1IDEuNzA4MDFDMi44NDMwNSAyLjE3OTk1IDIuNTkwNzIgMi42NzQzOCAyLjQ3NjAzIDMuMTkxMjhMMS43NDE5NyA2LjI3MDE3Wk0xMi4yNzEgMTIuNDI4SDQuMTczNTNMNC4xMjc2NSAxMi42MDc4SDcuNzI5MVYxMi42NzUySDEyLjU2OTJDMTIuNTkyMSAxMi42NzUyIDEyLjYxNSAxMi42NTI3IDEyLjY2MSAxMi42MzAzQzEyLjY4MzkgMTIuNjA3OCAxMi43MDY4IDEyLjU2MjggMTIuNzA2OCAxMi41NDAzTDEyLjg0NDUgMTEuODIxMkwxMy4wNTEgMTAuNjc1QzEzLjA3MzkgMTAuNjMgMTMuMDUxIDEwLjYwNzcgMTMuMDI4MSAxMC41ODUxQzEzLjAwNSAxMC41NjI3IDEyLjk4MjEgMTAuNTYyNyAxMi45NTkyIDEwLjU2MjdIMTIuODQ0NUwxMi41MjMzIDEyLjIwMzJDMTIuNTIzMyAxMi4yNDgyIDEyLjUwMDQgMTIuMzE1NiAxMi40MzE3IDEyLjM2MDZDMTIuMzg1NyAxMi40MDU1IDEyLjMxNjggMTIuNDI4IDEyLjI3MSAxMi40MjhaTTQuMDM1OSAxMS45NTZIMy43NjA2MkwzLjcxNDc0IDEyLjIwMzJIMy45NjcwN0w0LjAzNTkgMTEuOTU2Wk0wLjI5NjgxNyAxMi4zNjA2QzAuMjA1MDY5IDEyLjc2NTEgMC4yMDUwNyAxMy4xNjk2IDAuMjczODg2IDEzLjUyOTJMMC4zMTk3NSAxMy4zMDQ0QzAuMzE5NzUgMTMuMTAyMSAwLjM0MjcgMTIuODk5OSAwLjQxMTUxNiAxMi42NzUyTDAuNzA5NzI3IDExLjMyNjlIMy4zNzA2NkwzLjM5MzU5IDExLjE0N0gwLjU5NTAyOUwwLjI5NjgxNyAxMi4zNjA2Wk0xNi41ODM1IDEwLjA0NThDMTYuNjc1MyA5LjUwNjQ3IDE2LjYwNjYgOS4wMTE5MSAxNi40MjMgOC41ODVDMTYuNDAwMSA4LjU0MDA3IDE2LjM3NzEgOC40OTUxNCAxNi4zNTQyIDguNDUwMjFDMTYuNDAwMSA4LjY1MjQ4IDE2LjQyMyA4Ljg1NDc0IDE2LjQyMyA5LjA3OTM5QzE2LjQyMyA5LjI1OTI3IDE2LjQyMyA5LjQzODk5IDE2LjM3NzEgOS42MTg3TDE1Ljg3MjQgMTIuNjk3NkMxNS44MjY2IDEyLjkyMjQgMTUuNzU3OCAxMy4xMjQ3IDE1LjY4ODkgMTMuMzI3TDE1LjY0MzEgMTMuNTk2N0MxNS41NTE0IDE0LjA2ODUgMTUuMzQ0OSAxNC41MTggMTUuMDQ2NyAxNC45NDUxQzE1LjE2MTQgMTQuODMyNyAxNS4yOTkgMTQuNzIwMyAxNS4zOTA3IDE0LjYwOEMxNS43MzQ5IDE0LjE1ODQgMTUuOTY0MiAxMy42NDE2IDE2LjA1NiAxMy4xMjQ3TDE2LjU4MzUgMTAuMDQ1OFpNMTMuNTc4NSA2LjMxNTE0SDUuNTAzOTlMNS40NTgxMiA2LjU2MjM1SDEwLjA5MTdWNi40OTQ5MkgxMy44NTM4QzE0LjI0MzcgNi40OTQ5MiAxNC41ODc5IDYuNTYyMzUgMTQuOTA5IDYuNzE5NjVDMTQuNTE5IDYuNDQ5OTcgMTQuMDgzMyA2LjMxNTE0IDEzLjU3ODUgNi4zMTUxNFpNNS4zMjA0NyA2LjA5MDM5TDUuNjg3NSA0LjQ0OThINS40NTgxMkM1LjQzNTE3IDQuNDQ5OCA1LjQxMjI0IDQuNDcyMjggNS4zODkyOSA0LjQ5NDc1QzUuMzQzNDIgNC41MTcyMyA1LjM0MzQyIDQuNTYyMTggNS4zMjA0NyA0LjU4NDY0TDQuOTk5MzMgNi4wOTAzOUg1LjMyMDQ3Wk0xNy41OTI5IDMuOTc3ODZDMTcuNjg0NyAzLjQzODQ5IDE3LjYzODcgMi45NDQwNyAxNy40NTUyIDIuNDk0NTlDMTcuNDU1MiAyLjQ3MjExIDE3LjQwOTQgMi40NDk2NSAxNy40MDk0IDIuNDA0NjhDMTcuNDc4MyAyLjc2NDI3IDE3LjUwMTEgMy4xNDYzNCAxNy40MzIzIDMuNTUwODVMMTcuMjAzIDQuODk5MjhIMTcuMTM0MUwxNy4wODgzIDUuMTY4OTdIMTcuNDA5NEwxNy41OTI5IDMuOTc3ODZaIiBmaWxsPSJ3aGl0ZSIvPgo8L2c+CjxkZWZzPgo8Y2xpcFBhdGggaWQ9ImNsaXAwXzE4NzlfMTcyNzciPgo8cmVjdCB3aWR0aD0iMzEuNzQ0OSIgaGVpZ2h0PSIzNCIgZmlsbD0id2hpdGUiLz4KPC9jbGlwUGF0aD4KPC9kZWZzPgo8L3N2Zz4K' : $data['white_label_plugin_menu_icon'];
       
        // Use 'read' capability since actual access is controlled by current_user_has_plugin_access() check above
        $menu_capability = 'read';
        
        // Separator just before the plugin entry, after all other plugins (Yoast etc. ~99)
        add_action('admin_menu', function() {
            global $menu;
            $menu['100'] = array('', 'read', 'separator-metasync-before', '', 'wp-menu-separator');
        }, 5);

        // Main menu page at position 100.1 — bottom of the menu after all standard items
        add_menu_page(
            $menu_name,
            $menu_title,
            $menu_capability,
            $menu_slug,
            array($admin, 'create_admin_settings_page'),
            $menu_icon,
            '100.1'
        );

        // Check connection status for submenu availability
        $general_options = Metasync::get_option('general');
        $has_api_key = !empty($general_options['searchatlas_api_key']);
        $has_uuid = !empty($general_options['otto_pixel_uuid']);
        $is_fully_connected = Metasync_Heartbeat_Manager::instance()->is_heartbeat_connected($general_options);

        $seo_controls = Metasync::get_option('seo_controls');

        // ── Connect slug ──────────────────────────────────────────────────
        $connect_slug = $menu_slug . '-connect';

        // Dashboard
        if (Metasync_Access_Control::user_can_access('hide_dashboard')) {
            add_submenu_page($menu_slug, 'Dashboard', 'Dashboard', $menu_capability, $menu_slug . '-dashboard', array($admin, 'create_admin_dashboard_iframe'));
        }

        // Indexation Control
        if (Metasync_Access_Control::user_can_access('hide_indexation_control')) {
            add_submenu_page($menu_slug, 'Indexation Control', 'Indexation Control', $menu_capability, $menu_slug . '-seo-controls', array($admin, 'create_admin_seo_controls_page'));
        }

        // 404 Monitor
        add_submenu_page($menu_slug, '404 Monitor', '404 Monitor', $menu_capability, $menu_slug . '-404-monitor', array($admin, 'create_admin_404_monitor_page'));

        // Redirections
        if (Metasync_Access_Control::user_can_access('hide_redirections')) {
            add_submenu_page($menu_slug, 'Redirections', 'Redirections', $menu_capability, $menu_slug . '-redirections', array($admin, 'create_admin_redirections_page'));
        }

        // XML Sitemap
        if (Metasync_Access_Control::user_can_access('hide_xml_sitemap')) {
            add_submenu_page($menu_slug, 'XML Sitemap', 'XML Sitemap', $menu_capability, $menu_slug . '-xml-sitemap', array($admin, 'create_admin_xml_sitemap_page'));
        }

        // Robots.txt
        if (Metasync_Access_Control::user_can_access('hide_robots')) {
            add_submenu_page($menu_slug, 'Robots.txt', 'Robots.txt', $menu_capability, $menu_slug . '-robots-txt', array($admin, 'create_admin_robots_txt_page'));
        }

        // Site Verification
        add_submenu_page($menu_slug, 'Site Verification', 'Site Verification', $menu_capability, $menu_slug . '-search-engine-verify', array($admin, 'create_admin_search_engine_verification_page'));

        // Instant Indexing (conditional)
        if ($seo_controls['enable_googleinstantindex'] ?? false) {
            add_submenu_page($menu_slug, 'Instant Indexing', 'Instant Indexing', $menu_capability, $menu_slug . '-instant-index', array($admin, 'create_admin_google_instant_index_page'));
        }

        // Google Console (conditional)
        if ($general_options['enable_google_console'] ?? false) {
            add_submenu_page($menu_slug, 'Google Console', 'Google Console', $menu_capability, $menu_slug . '-google-console', array($admin, 'create_admin_google_console_page'));
        }

        // Bing Console (conditional)
        if ($seo_controls['enable_binginstantindex'] ?? false) {
            add_submenu_page($menu_slug, 'Bing Console', 'Bing Console', $menu_capability, $menu_slug . '-bing-console', array($admin, 'create_admin_bing_console_page'));
        }

        // Schema Markup
        add_submenu_page($menu_slug, 'Schema Markup', 'Schema Markup', $menu_capability, $menu_slug . '-schema-markup', array($admin, 'create_admin_schema_markup_page'));

        // Import SEO Data
        if (Metasync_Access_Control::user_can_access('hide_import_seo')) {
            add_submenu_page($menu_slug, 'Import SEO Data', 'Import SEO Data', $menu_capability, $menu_slug . '-import-external', array($admin, 'render_import_external_data_page'));
        }

        // Settings
        if (Metasync_Access_Control::user_can_access('hide_settings')) {
            add_submenu_page($menu_slug, 'Settings', 'Settings', $menu_capability, $menu_slug, array($admin, 'create_admin_settings_page'));
        }

        // Local Business
        add_submenu_page($menu_slug, 'Local Business', 'Local Business', $menu_capability, $menu_slug . '-local-business', array($admin, 'create_admin_local_business_page'));

        // Breadcrumbs
        add_submenu_page($menu_slug, 'Breadcrumbs', 'Breadcrumbs', $menu_capability, $menu_slug . '-breadcrumbs', array($admin, 'create_admin_breadcrumbs_page'));

        // Code Snippets
        add_submenu_page($menu_slug, 'Code Snippets', 'Code Snippets', $menu_capability, $menu_slug . '-code-snippets', array($admin, 'create_admin_code_snippets_page'));

        // Code Minification
        add_submenu_page($menu_slug, 'Code Minification', 'Code Minification', $menu_capability, $menu_slug . '-code-minification', array($admin, 'create_admin_code_minification_page'));

        // Custom Pages
        if (Metasync_Access_Control::user_can_access('hide_custom_pages')) {
            add_submenu_page($menu_slug, 'Custom Pages', 'Custom Pages', $menu_capability, $menu_slug . '-custom-pages', array($admin, 'create_admin_custom_pages_page'));
        }

        // Bot Statistics
        add_submenu_page($menu_slug, 'Bot Statistics', 'Bot Statistics', $menu_capability, $menu_slug . '-bot-statistics', array($admin, 'create_admin_bot_statistics_page'));

        // SEO Health dashboard
        add_submenu_page($menu_slug, 'SEO Health', 'SEO Health', $menu_capability, $menu_slug . '-seo-health', array($admin, 'create_admin_seo_health_page'));

        // Compatibility
        if (Metasync_Access_Control::user_can_access('hide_compatibility')) {
            add_submenu_page($menu_slug, 'Compatibility', 'Compatibility', $menu_capability, $menu_slug . '-compatibility', array($admin, 'create_admin_compatibility_page'));
        }

        // Changes Log
        if (Metasync_Access_Control::user_can_access('hide_sync_log')) {
            add_submenu_page($menu_slug, 'Changes Log', 'Changes Log', $menu_capability, $menu_slug . '-sync-log', array($admin, 'create_admin_sync_log_page'));
        }

        // Report Issue
        if (Metasync_Access_Control::user_can_access('hide_report_issue')) {
            add_submenu_page($menu_slug, 'Report Issue', 'Report Issue', $menu_capability, $menu_slug . '-report-issue', array($admin, 'create_admin_report_issue_page'));
        }

        // ── Connect CTA (shown when not authenticated) ────────────────────
        if (!$is_fully_connected) {
            add_submenu_page($menu_slug, 'Connect to SearchAtlas', 'Connect to SearchAtlas', $menu_capability, $connect_slug, array($admin, 'create_admin_settings_page'));
        }

        // ── Hidden pages (no sidebar entry needed) ────────────────────────
        add_submenu_page('', 'Setup Wizard', 'Setup Wizard', $menu_capability, $menu_slug . '-setup-wizard', array($admin->setup_wizard, 'render_wizard_page'));


        // Rename auto-generated first submenu from plugin name to "Settings" and reorder
        add_action('admin_menu', function() use ($menu_slug) {
            global $submenu;
            if (!isset($submenu[$menu_slug])) {
                return;
            }
            // Remove the auto-duplicate of the main menu item (same slug as parent)
            foreach ($submenu[$menu_slug] as $key => $item) {
                if ($item[2] === $menu_slug) {
                    unset($submenu[$menu_slug][$key]);
                    break;
                }
            }
        }, 999);

    }

    /**
     * Render the grouped navigation menu with Dashboard + SEO/Plugin dropdowns.
     */
    public function render_navigation_menu($current_page = null)
    {
        $page_slug = Metasync_Admin::$page_slug;
        $available_menu_items = $this->get_available_menu_items();
        
        $menu_icons = [
            'general'          => 'admin-settings',
            'dashboard'        => 'dashboard',
            'compatibility'    => 'admin-tools',
            'sync_log'         => 'list-view',
            'seo_controls'     => 'search',
            'optimal_settings' => 'superhero-alt',
            'instant_index'    => 'performance',
            'google_console'   => 'chart-area',
            'bing_console'     => 'chart-bar',
            'redirections'     => 'undo',
            'robots_txt'       => 'shield',
            'xml_sitemap'      => 'networking',
            'schema_markup'    => 'tag',
            'site_verification'=> 'yes-alt',
            'import_seo'       => 'download',
            'custom_pages'     => 'admin-page',
            'code_minification'=> 'media-code',
            'bot_statistics'   => 'visibility',
            'breadcrumbs'      => 'menu',
            'monitor_404'      => 'warning',
            'local_business'   => 'building',
            'code_snippets'    => 'editor-code',
            'report_issue'     => 'sos',
            'error_log'        => 'warning',
            'seo_health'       => 'heart'

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
                    $icon = $menu_icons['dashboard'] ?? 'admin-generic';
                    $page_url = '?page=' . $page_slug . $seo_items['dashboard']['slug_suffix'];
                    ?>
                    <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-tab <?php echo $is_active ? 'active' : ''; ?>">
                        <span class="tab-icon"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></span>
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
                        <span class="tab-icon"><span class="dashicons dashicons-search"></span></span>
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
                            $icon = $menu_icons[$key] ?? 'admin-generic';
                            $page_url = '?page=' . $page_slug . $menu_item['slug_suffix'];
                            ?>
                            <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-dropdown-item <?php echo $is_active ? 'active' : ''; ?>">
                                <span class="tab-icon"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></span>
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
                        <span class="tab-icon"><span class="dashicons dashicons-admin-settings"></span></span>
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
                            $icon = $menu_icons[$key] ?? 'admin-generic';
                            $page_url = '?page=' . $page_slug . $menu_item['slug_suffix'];
                            ?>
                            <a href="<?php echo esc_url($page_url); ?>" class="metasync-nav-dropdown-item <?php echo $is_active ? 'active' : ''; ?>">
                                <span class="tab-icon"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></span>
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
                            <span class="tab-icon"><span class="dashicons dashicons-sos"></span></span>
                            <span class="tab-text">Report Issue</span>
                        </a>
                    <?php } ?>
                    <div class="metasync-simple-dropdown">
                        <button type="button" class="metasync-settings-btn" id="metasync-settings-btn" onclick="toggleSettingsMenuPortal(event)" aria-expanded="false">
                            <span class="tab-icon"><span class="dashicons dashicons-admin-settings"></span></span>
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
                generalLink.href = '?page=<?php echo esc_js( $page_slug ); ?>&tab=general';
                generalLink.className = 'metasync-portal-item' + (isGeneralActive ? ' active' : '');
                generalLink.textContent = 'General';
                menu.appendChild(generalLink);
            }

            const whitelabelLink = document.createElement('a');
            whitelabelLink.href = '?page=<?php echo esc_js( $page_slug ); ?>&tab=whitelabel';
            whitelabelLink.className = 'metasync-portal-item' + (isWhitelabelActive ? ' active' : '');
            whitelabelLink.textContent = 'White label';
            menu.appendChild(whitelabelLink);

            if (!hideAdvanced) {
                const advancedLink = document.createElement('a');
                advancedLink.href = '?page=<?php echo esc_js( $page_slug ); ?>&tab=advanced';
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

        $effective_plugin_name = Metasync::get_effective_plugin_name();
        $display_title = $page_title ?: $effective_plugin_name;
        $logo = $this->resolve_logo_data();

        $searchatlas_api_key = isset($general_settings['searchatlas_api_key']) ? $general_settings['searchatlas_api_key'] : '';
        $otto_pixel_uuid = isset($general_settings['otto_pixel_uuid']) ? $general_settings['otto_pixel_uuid'] : '';

        $is_integrated = Metasync_Heartbeat_Manager::instance()->is_heartbeat_connected($general_settings);

        $current_theme = get_option('metasync_theme', 'dark');
        ?>

        <!-- Plugin Header with Logo -->
        <div class="metasync-header" data-current-theme="<?php echo esc_attr($current_theme); ?>">
            <div class="metasync-header-left">
                <?php if ($logo['show_logo'] && $logo['use_dual']): ?>
                    <div class="metasync-logo-container">
                        <img src="<?php echo esc_url($logo['light_url']); ?>" alt="Logo" class="metasync-logo metasync-logo-light" />
                        <img src="<?php echo esc_url($logo['dark_url']); ?>" alt="Logo" class="metasync-logo metasync-logo-dark" />
                    </div>
                <?php elseif ($logo['show_logo'] && !empty($logo['url'])): ?>
                    <div class="metasync-logo-container">
                        <img src="<?php echo esc_url($logo['url']); ?>" alt="Logo" class="metasync-logo" />
                    </div>
                <?php endif; ?>
            </div>
            
                         <div class="metasync-header-right">
                             <!-- Theme Toggle -->
                        <div class="metasync-theme-toggle" role="group" aria-label="Theme Selector">
                            <button class="metasync-theme-option <?php echo ($current_theme === 'light') ? 'active' : ''; ?>" data-theme="light" aria-label="Light Theme" type="button">
                                <span class="metasync-theme-icon">&#9728;</span>
                                <span class="theme-label">Light</span>
                            </button>
                            <button class="metasync-theme-option <?php echo ($current_theme === 'dark') ? 'active' : ''; ?>" data-theme="dark" aria-label="Dark Theme" type="button">
                                <span class="metasync-theme-icon">&#9790;</span>
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
        if (!is_array($general_settings)) {
            $general_settings = [];
        }
        $show_admin_bar = $general_settings['show_admin_bar_status'] ?? true;
        if (!$show_admin_bar) {
            return;
        }

        if (!is_admin() && !apply_filters('metasync_show_admin_bar_status_frontend', false)) {
            return;
        }

        $node = get_transient(self::CACHE_KEY);

        if ($node === false) {
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

            $admin_bar_title = $plugin_name . ' ' . $status_emoji;

            $node = array(
                'title'      => $admin_bar_title,
                'href'       => admin_url('admin.php?page=' . Metasync_Admin::$page_slug),
                'meta_title' => $title,
                'meta_class' => $status_class,
            );

            set_transient(self::CACHE_KEY, $node, apply_filters('metasync_admin_bar_status_cache_ttl', 5 * MINUTE_IN_SECONDS));
        }

        $wp_admin_bar->add_node(array(
            'id'    => 'searchatlas-status',
            'title' => $node['title'],
            'href'  => $node['href'],
            'meta'  => array(
                'title' => $node['meta_title'],
                'class' => $node['meta_class'],
            ),
        ));
    }

    /**
     * Invalidate the admin bar status cache so the next page load recomputes status.
     */
    public static function invalidate_admin_bar_status_cache()
    {
        delete_transient(self::CACHE_KEY);
    }

    // ------------------------------------------------------------------
    //  Yoast-style 3-column layout helpers
    // ------------------------------------------------------------------

    /**
     * Open the 3-column page layout.
     * Call this at the start of every admin page callback instead of
     * render_plugin_header() + render_navigation_menu().
     * Close with render_layout_close().
     *
     * @param string $page_title      Human-readable page title.
     * @param string $current_page    Key matching get_available_menu_items() (e.g. 'general').
     * @param string $description     Optional subtitle shown below the title.
     */
    public function render_layout_open($page_title = '', $current_page = '', $description = '')
    {
        $theme       = esc_attr(get_option('metasync_theme', 'dark'));
        $plugin_name = Metasync::get_effective_plugin_name();
        $general     = Metasync::get_option('general') ?? [];
        $is_connected = Metasync_Heartbeat_Manager::instance()->is_heartbeat_connected($general);
        $logo        = $this->resolve_logo_data();

        $current_theme = get_option('metasync_theme', 'dark');
        $api_key       = $general['searchatlas_api_key'] ?? '';
        ?>
        <div class="wrap metasync-dashboard-wrap" data-theme="<?php echo $theme; ?>">

        <?php
        // Inject whitelabel color palette overrides
        $wl_settings = Metasync::get_whitelabel_settings();
        $wl_palette  = isset($wl_settings['color_palette']) && is_array($wl_settings['color_palette']) ? $wl_settings['color_palette'] : array();
        if (!empty($wl_palette)) {
            echo '<style id="metasync-whitelabel-palette">';
            foreach (array('dark', 'light') as $t) {
                if (!empty($wl_palette[$t]) && is_array($wl_palette[$t])) {
                    $selector = ($t === 'dark') ? ':root, [data-theme="dark"]' : '[data-theme="light"]';
                    $vars = '';
                    foreach ($wl_palette[$t] as $var_name => $color) {
                        // Skip gradient partial keys — handled below
                        if (strpos($var_name, 'gradient-') !== false) continue;
                        if (preg_match('/^dashboard-[a-z-]+$/', $var_name) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
                            $vars .= '--' . esc_attr($var_name) . ':' . esc_attr($color) . ';';
                        }
                    }
                    // Build gradient overrides from paired from/to colors
                    $gradient_pairs = array(
                        'dashboard-gradient-primary' => array('dashboard-gradient-primary-from', 'dashboard-gradient-primary-to'),
                        'dashboard-gradient-accent'  => array('dashboard-gradient-accent-from', 'dashboard-gradient-accent-to'),
                    );
                    foreach ($gradient_pairs as $grad_var => $pair) {
                        $from = isset($wl_palette[$t][$pair[0]]) ? $wl_palette[$t][$pair[0]] : '';
                        $to   = isset($wl_palette[$t][$pair[1]]) ? $wl_palette[$t][$pair[1]] : '';
                        if ($from && $to && preg_match('/^#[0-9a-fA-F]{3,8}$/', $from) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $to)) {
                            $vars .= '--' . esc_attr($grad_var) . ':linear-gradient(135deg,' . esc_attr($from) . ' 0%,' . esc_attr($to) . ' 100%);';
                        }
                    }
                    if ($vars) {
                        echo $selector . '{' . $vars . '}';
                    }
                }
            }
            echo '</style>';
        }
        ?>

        <!-- Compact top header -->
        <div class="metasync-header-compact">
            <div style="display:flex;align-items:center;gap:10px;">
                <?php if ($logo['show_logo'] && $logo['use_dual']): ?>
                    <img src="<?php echo esc_url($logo['light_url']); ?>" alt="<?php echo esc_attr($plugin_name); ?>" class="metasync-logo metasync-logo-light" style="height:28px;width:auto;">
                    <img src="<?php echo esc_url($logo['dark_url']); ?>" alt="<?php echo esc_attr($plugin_name); ?>" class="metasync-logo metasync-logo-dark" style="height:28px;width:auto;">
                <?php elseif ($logo['show_logo'] && $logo['url']): ?>
                    <img src="<?php echo esc_url($logo['url']); ?>" alt="<?php echo esc_attr($plugin_name); ?>" class="metasync-logo" style="height:28px;width:auto;">
                <?php else: ?>
                    <strong style="font-size:15px;color:var(--dashboard-text-primary);"><?php echo esc_html($plugin_name); ?></strong>
                <?php endif; ?>
            </div>
            <div class="metasync-header-compact-right">
                <div class="metasync-status <?php echo !empty($api_key) ? 'connected' : 'disconnected'; ?>">
                    <span class="status-dot"></span>
                    <span class="status-text"><?php echo !empty($api_key) ? 'Connected' : 'Not Connected'; ?></span>
                </div>
                <button type="button" class="metasync-theme-toggle" onclick="toggleMetasyncTheme()" title="Toggle theme">
                    <span class="theme-icon-light">&#9728;</span>
                    <span class="theme-icon-dark">&#9790;</span>
                </button>
            </div>
        </div>

        <!-- 3-column layout -->
        <div class="metasync-layout">

            <!-- Left sidenav -->
            <aside class="metasync-layout-nav">
                <?php $this->render_sidenav($current_page); ?>
            </aside>

            <!-- Main content -->
            <main class="metasync-layout-main">
                <?php if ($page_title || $description): ?>
                <div class="metasync-page-header">
                    <?php if ($page_title): ?>
                        <h1><?php echo esc_html($page_title); ?></h1>
                    <?php endif; ?>
                    <?php if ($description): ?>
                        <p><?php echo esc_html($description); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
        <?php
        // Note: render_layout_close() closes </main>, renders promo sidebar, closes </div>.metasync-layout and </div>.wrap
    }

    /**
     * Close the 3-column layout opened by render_layout_open().
     *
     * @param bool $show_promo Whether to render the right promo sidebar. Default true.
     *                         Pass false for full-width pages (e.g. dashboard iframe).
     */
    public function render_layout_close($show_promo = true)
    {
        ?>
            </main><!-- /.metasync-layout-main -->

            <?php if ($show_promo):
                $general      = Metasync::get_option('general') ?? [];
                $is_connected = Metasync_Heartbeat_Manager::instance()->is_heartbeat_connected($general);
            ?>
            <!-- Right promo sidebar -->
            <aside class="metasync-layout-promo">
                <?php $this->render_promo_sidebar($is_connected); ?>
            </aside>
            <?php endif; ?>

        </div><!-- /.metasync-layout -->
        </div><!-- /.metasync-dashboard-wrap -->
        <?php
    }

    /**
     * Render the left sticky sidenav with grouped items.
     *
     * @param string $current_page  Active page key.
     */
    public function render_sidenav($current_page = '')
    {
        $page_slug   = Metasync_Admin::$page_slug;
        $menu_items  = $this->get_available_menu_items();

        // Dashicons names (without 'dashicons-' prefix) — monochrome, color set by CSS
        $icons = [
            'dashboard'        => 'dashboard',
            'seo_controls'     => 'search',
            'monitor_404'      => 'warning',
            'redirections'     => 'undo',
            'xml_sitemap'      => 'networking',
            'robots_txt'       => 'shield',
            'site_verification'=> 'yes-alt',
            'instant_index'    => 'performance',
            'google_console'   => 'chart-area',
            'bing_console'     => 'chart-bar',
            'import_seo'       => 'download',
            'general'          => 'admin-settings',
            'schema_markup'    => 'tag',
            'local_business'   => 'building',
            'breadcrumbs'      => 'menu',
            'code_snippets'    => 'editor-code',
            'code_minification'=> 'media-code',
            'custom_pages'     => 'admin-page',
            'optimal_settings' => 'superhero-alt',
            'compatibility'    => 'admin-tools',
            'sync_log'         => 'list-view',
            'bot_statistics'   => 'visibility',
            'report_issue'     => 'sos',
            'media_optimization'=> 'images-alt2',
            'seo_health'       => 'heart'
        ];

        $seo_items    = [];
        $plugin_items = [];
        foreach ($menu_items as $key => $item) {
            if (($item['group'] ?? 'plugin') === 'seo') {
                $seo_items[$key] = $item;
            } else {
                $plugin_items[$key] = $item;
            }
        }
        ?>
        <nav class="metasync-sidenav">

            <!-- SEO Features group -->
            <div class="metasync-sidenav-group">
                <div class="metasync-sidenav-group-title">SEO Features</div>
                <ul>
                <?php foreach ($seo_items as $key => $item):
                    $is_active = ($current_page === $key);
                    $icon      = $icons[$key] ?? 'admin-generic';
                    $url       = esc_url(admin_url('admin.php?page=' . $page_slug . $item['slug_suffix']));
                ?>
                    <li class="<?php echo $is_active ? 'metasync-sidenav-active' : ''; ?>">
                        <a href="<?php echo $url; ?>">
                            <span class="metasync-sidenav-icon"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></span>
                            <?php echo esc_html($item['title']); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>

            <!-- Plugin group -->
            <div class="metasync-sidenav-group">
                <div class="metasync-sidenav-group-title">Plugin</div>
                <ul>
                <?php foreach ($plugin_items as $key => $item):
                    if ($key === 'report_issue') continue;
                    $is_active = ($current_page === $key);
                    $icon      = $icons[$key] ?? 'admin-generic';
                    $url       = esc_url(admin_url('admin.php?page=' . $page_slug . $item['slug_suffix']));
                ?>
                    <li class="<?php echo $is_active ? 'metasync-sidenav-active' : ''; ?>">
                        <a href="<?php echo $url; ?>">
                            <span class="metasync-sidenav-icon"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></span>
                            <?php echo esc_html($item['title']); ?>
                        </a>
                    </li>
                    <?php if ($key === 'general'): ?>
                    <li class="<?php echo $current_page === 'advanced_settings' ? 'metasync-sidenav-active' : ''; ?>" style="padding-left: 8px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $page_slug . '&tab=advanced')); ?>">
                            <span class="metasync-sidenav-icon"><span class="dashicons dashicons-admin-generic"></span></span>
                            Advanced Settings
                        </a>
                    </li>
                    <li class="<?php echo $current_page === 'whitelabel' ? 'metasync-sidenav-active' : ''; ?>" style="padding-left: 8px;">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $page_slug . '&tab=whitelabel')); ?>">
                            <span class="metasync-sidenav-icon"><span class="dashicons dashicons-tag"></span></span>
                            Whitelabel
                        </a>
                    </li>
                    <?php endif; ?>
                <?php endforeach; ?>
                </ul>
            </div>

            <!-- Report Issue at the bottom -->
            <?php if (isset($menu_items['report_issue'])): ?>
            <div class="metasync-sidenav-group">
                <ul>
                    <li class="<?php echo $current_page === 'report_issue' ? 'metasync-sidenav-active' : ''; ?>">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . $page_slug . $menu_items['report_issue']['slug_suffix'])); ?>">
                            <span class="metasync-sidenav-icon"><span class="dashicons dashicons-sos"></span></span>
                            Report Issue
                        </a>
                    </li>
                </ul>
            </div>
            <?php endif; ?>

        </nav>
        <?php
    }

    /**
     * Render the right promotional sidebar.
     *
     * @param bool $is_connected  Whether the plugin is authenticated.
     */
    public function render_promo_sidebar($is_connected = false)
    {
        $plugin_name      = Metasync::get_effective_plugin_name();
        $otto_name        = Metasync::get_whitelabel_otto_name();
        $settings_url     = esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug));
        $homepage         = Metasync::HOMEPAGE_DOMAIN;
        $is_default_brand = ( $plugin_name === 'Search Atlas' );
        $whitelabel       = Metasync::get_whitelabel_settings();
        $custom_links     = isset($whitelabel['quick_links']) && is_array($whitelabel['quick_links'])
                              ? array_filter($whitelabel['quick_links'], function($l) { return !empty($l['url']); })
                              : [];
        ?>

        <?php if (!$is_connected): ?>
        <!-- Connect CTA card -->
        <div class="metasync-promo-card metasync-promo-card--connect">
            <div class="metasync-promo-card-header">
                <div class="metasync-promo-card-icon">
                    <span class="dashicons dashicons-admin-links"></span>
                </div>
                <div>
                    <h3>Connect <?php echo esc_html($plugin_name); ?></h3>
                </div>
            </div>
            <p class="metasync-promo-tagline">Link your site to <?php echo esc_html($plugin_name); ?> to unlock <?php echo esc_html($otto_name); ?>, keyword data, and automated SEO.</p>
            <ul class="metasync-promo-benefits">
                <li><span class="promo-check">&#10003;</span> <?php echo esc_html($otto_name); ?> — hands-free on-page SEO</li>
                <li><span class="promo-check">&#10003;</span> Real-time keyword tracking</li>
                <li><span class="promo-check">&#10003;</span> Automated schema markup</li>
                <li><span class="promo-check">&#10003;</span> Instant Google indexing</li>
            </ul>
            <a href="<?php echo $settings_url; ?>" class="metasync-promo-btn metasync-promo-btn--primary">
                Connect Now
            </a>
        </div>
        <?php else: ?>
        <!-- Connected — feature highlights -->
        <div class="metasync-promo-card metasync-promo-card--accent">
            <div class="metasync-promo-card-header">
                <div class="metasync-promo-card-icon">
                    <span class="dashicons dashicons-performance"></span>
                </div>
                <div>
                    <h3><?php echo esc_html($otto_name); ?> Active</h3>
                </div>
            </div>
            <p class="metasync-promo-tagline">Your site is connected and <?php echo esc_html($otto_name); ?> is optimizing pages automatically.</p>
            <ul class="metasync-promo-benefits">
                <li><span class="promo-check">&#10003;</span> Schema markup auto-applied</li>
                <li><span class="promo-check">&#10003;</span> Meta titles &amp; descriptions optimized</li>
                <li><span class="promo-check">&#10003;</span> Internal linking suggestions active</li>
            </ul>
            <?php if ($is_default_brand): ?>
            <a href="<?php echo esc_url($homepage); ?>" target="_blank" rel="noopener" class="metasync-promo-btn metasync-promo-btn--outline">
                View <?php echo esc_html($plugin_name); ?> Dashboard
            </a>
            <?php elseif (!empty($whitelabel['domain'])): ?>
            <a href="<?php echo esc_url($whitelabel['domain']); ?>" target="_blank" rel="noopener" class="metasync-promo-btn metasync-promo-btn--outline">
                View <?php echo esc_html($plugin_name); ?> Dashboard
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php
        // Quick Links: show default links for default brand, custom links if whitelabeled + provided, hide if whitelabeled + none
        $show_quick_links = $is_default_brand || !empty($custom_links);
        if ($show_quick_links):
        ?>
        <!-- Quick links card -->
        <div class="metasync-promo-card">
            <h3 style="margin:0 0 12px;font-size:13px;font-weight:700;color:var(--dashboard-text-primary);">Quick Links</h3>
            <ul class="metasync-promo-links">
            <?php if ($is_default_brand): ?>
                <li><a href="<?php echo esc_url($homepage . '/blog/'); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-rss"></span> SEO Blog</a></li>
                <li><a href="<?php echo esc_url($homepage . '/academy/'); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-welcome-learn-more"></span> SEO Academy</a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-setup-wizard')); ?>"><span class="dashicons dashicons-admin-customizer"></span> Setup Wizard</a></li>
                <li><a href="<?php echo esc_url(admin_url('admin.php?page=' . Metasync_Admin::$page_slug . '-report-issue')); ?>"><span class="dashicons dashicons-sos"></span> Report Issue</a></li>
            <?php else: ?>
                <?php foreach ($custom_links as $link): ?>
                <li><a href="<?php echo esc_url($link['url']); ?>" <?php echo !empty($link['external']) ? 'target="_blank" rel="noopener"' : ''; ?>><span class="dashicons dashicons-admin-links"></span> <?php echo esc_html($link['label'] ?: $link['url']); ?></a></li>
                <?php endforeach; ?>
            <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php
    }
}

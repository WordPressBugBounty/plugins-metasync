<?php
/**
 * Mixpanel Analytics Integration for MetaSync Plugin
 *
 * Handles usage tracking in the WordPress admin area only.
 * Provides secure, privacy-compliant analytics for plugin feature adoption.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2025, Search Atlas Group - support@searchatlas.com
 * @link        https://searchatlas.com/
 * @since       2.5.9
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Metasync_Mixpanel {

    /**
     * Get Mixpanel Project Token
     * @return string|null Returns token if configured, null otherwise
     */
    private function get_mixpanel_token() {
        return defined('METASYNC_MIXPANEL_TOKEN') && !empty(METASYNC_MIXPANEL_TOKEN)
            ? METASYNC_MIXPANEL_TOKEN
            : null;
    }

    /**
     * Check if Mixpanel is properly configured
     * @return bool
     */
    private function is_configured() {
        return $this->get_mixpanel_token() !== null;
    }

    /**
     * Option name for tracking opt-in status
     * @var string
     */
    private const OPT_IN_OPTION = 'metasync_analytics_opt_in';

    /**
     * Singleton instance
     * @var Metasync_Mixpanel
     */
    private static $instance = null;

    /**
     * Get singleton instance
     * @return Metasync_Mixpanel
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Initialize hooks
     * Note: This is only called from admin_init hook, so no need to check is_admin()
     */
    private function __construct() {
        // Don't initialize if Mixpanel token is not configured
        if (!$this->is_configured()) {
            // if (defined('WP_DEBUG') && WP_DEBUG) {
            //     error_log('MetaSync: Mixpanel tracking disabled - METASYNC_MIXPANEL_TOKEN not defined');
            // }
            return;
        }

        // Set default opt-in to true on first load
        if (get_option(self::OPT_IN_OPTION) === false) {
            update_option(self::OPT_IN_OPTION, 'yes');
        }

        // Enqueue Mixpanel script in admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_mixpanel_script'));

        // Track admin page views
        add_action('admin_head', array($this, 'track_admin_page_view'));
    }

    /**
     * Check if user has opted in to analytics
     * @return bool
     */
    public function is_opted_in() {
        return get_option(self::OPT_IN_OPTION, 'yes') === 'yes';
    }

    /**
     * Get anonymized user ID (hashed)
     * @return string
     */
    public function get_anonymized_user_id() {
        $current_user = wp_get_current_user();
        if (!$current_user || !$current_user->ID) {
            return 'guest_' . wp_hash(session_id());
        }

        // Create a unique, non-reversible hash of user ID + site URL
        // This ensures user privacy while maintaining consistency
        $site_identifier = get_option('siteurl');
        return 'user_' . wp_hash($current_user->ID . '|' . $site_identifier);
    }

    /**
     * Get plugin version
     * @return string
     */
    private function get_plugin_version() {
        return defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown';
    }

    /**
     * Enqueue Mixpanel JavaScript SDK in admin
     */
    public function enqueue_mixpanel_script() {
        // Don't load if not configured
        if (!$this->is_configured()) {
            return;
        }

        // Debug logging
        $is_plugin_page = $this->is_plugin_admin_page();
        $is_opted_in = $this->is_opted_in();

        // Only load in plugin admin pages
        if (!$is_plugin_page) {
            return;
        }

        // Don't track if user hasn't opted in
        if (!$is_opted_in) {
            return;
        }

        // Inline script to initialize Mixpanel
        $inline_script = $this->get_mixpanel_inline_script();

        // Add debug console log if WP_DEBUG is enabled
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'unknown';
            $inline_script .= "\nconsole.log('[Mixpanel] Initialized on page: " . esc_js($current_page) . "');";
            $inline_script .= "\nconsole.log('[Mixpanel] User ID: " . esc_js($this->get_anonymized_user_id()) . "');";
        }

        // Register and enqueue a dummy script to attach inline script
        wp_register_script('metasync-mixpanel-init', '', array(), $this->get_plugin_version(), false);
        wp_enqueue_script('metasync-mixpanel-init');
        wp_add_inline_script('metasync-mixpanel-init', $inline_script, 'before');
    }

    /**
     * Get Mixpanel initialization script
     * @return string
     */
    private function get_mixpanel_inline_script() {
        $user_id = $this->get_anonymized_user_id();
        $plugin_version = $this->get_plugin_version();

        // Mixpanel SDK loader + initialization
        return "(function(e,c){if(!c.__SV){var l,h;window.mixpanel=c;c._i=[];c.init=function(q,r,f){function t(d,a){var g=a.split(\".\");2==g.length&&(d=d[g[0]],a=g[1]);d[a]=function(){d.push([a].concat(Array.prototype.slice.call(arguments,0)))}}var b=c;\"undefined\"!==typeof f?b=c[f]=[]:f=\"mixpanel\";b.people=b.people||[];b.toString=function(d){var a=\"mixpanel\";\"mixpanel\"!==f&&(a+=\".\"+f);d||(a+=\" (stub)\");return a};b.people.toString=function(){return b.toString(1)+\".people (stub)\"};l=\"disable time_event track track_pageview track_links track_forms track_with_groups add_group set_group remove_group register register_once alias unregister identify name_tag set_config reset opt_in_tracking opt_out_tracking has_opted_in_tracking has_opted_out_tracking clear_opt_in_out_tracking start_batch_senders start_session_recording stop_session_recording people.set people.set_once people.unset people.increment people.append people.union people.track_charge people.clear_charges people.delete_user people.remove\".split(\" \");for(h=0;h<l.length;h++)t(b,l[h]);var n=\"set set_once union unset remove delete\".split(\" \");b.get_group=function(){function d(p){a[p]=function(){b.push([g,[p].concat(Array.prototype.slice.call(arguments,0))])}}for(var a={},g=[\"get_group\"].concat(Array.prototype.slice.call(arguments,0)),m=0;m<n.length;m++)d(n[m]);return a};c._i.push([q,r,f])};c.__SV=1.2;var k=e.createElement(\"script\");k.type=\"text/javascript\";k.async=!0;k.src=\"undefined\"!==typeof MIXPANEL_CUSTOM_LIB_URL?MIXPANEL_CUSTOM_LIB_URL:\"file:\"===e.location.protocol&&\"//cdn.mxpnl.com/libs/mixpanel-2-latest.min.js\".match(/^\\/\\//)?\"https://cdn.mxpnl.com/libs/mixpanel-2-latest.min.js\":\"//cdn.mxpnl.com/libs/mixpanel-2-latest.min.js\";e=e.getElementsByTagName(\"script\")[0];e.parentNode.insertBefore(k,e)}})(document,window.mixpanel||[]);

mixpanel.init('" . esc_js($this->get_mixpanel_token()) . "', {
    autocapture: false,
    record_sessions_percent: 0,
    persistence: 'localStorage'
});

// Identify user with anonymized ID
mixpanel.identify('" . esc_js($user_id) . "');

// Set super properties (sent with every event)
mixpanel.register({
    'Plugin Version': '" . esc_js($plugin_version) . "',
    'WordPress Version': '" . esc_js(get_bloginfo('version')) . "',
    'PHP Version': '" . esc_js(PHP_VERSION) . "'
});";
    }

    /**
     * Check if current page is a plugin admin page
     * @return bool
     */
    private function is_plugin_admin_page() {
        if (!isset($_GET['page'])) {
            return false;
        }

        $page = sanitize_text_field($_GET['page']);

        // Get the actual plugin slug from Metasync_Admin class
        // Default is 'searchatlas' but can be whitelabeled
        $plugin_slug = 'searchatlas';
        if (class_exists('Metasync_Admin') && isset(Metasync_Admin::$page_slug)) {
            $plugin_slug = Metasync_Admin::$page_slug;
        }

        // Check if page matches or starts with plugin slug
        return $page === $plugin_slug || strpos($page, $plugin_slug . '-') === 0;
    }

    /**
     * Get current admin screen name
     * @return string
     */
    private function get_admin_screen_name() {
        if (!isset($_GET['page'])) {
            return 'Unknown';
        }

        $page = sanitize_text_field($_GET['page']);

        // Get the actual plugin slug from Metasync_Admin class
        $plugin_slug = 'searchatlas';
        if (class_exists('Metasync_Admin') && isset(Metasync_Admin::$page_slug)) {
            $plugin_slug = Metasync_Admin::$page_slug;
        }

        // Map page slugs to readable names (based on actual menu structure in class-metasync-admin.php)
        $screen_map = array(
            $plugin_slug                        => 'Settings',
            $plugin_slug . '-dashboard'         => 'Dashboard',
            $plugin_slug . '-compatibility'     => 'Compatibility',
            $plugin_slug . '-sync-log'          => 'Sync Log',
            $plugin_slug . '-seo-controls'      => 'Indexation Control',
            $plugin_slug . '-redirections'      => 'Redirections',
            $plugin_slug . '-xml-sitemap'       => 'XML Sitemap',
            $plugin_slug . '-robots-txt'        => 'Robots.txt',
            $plugin_slug . '-404-monitor'       => '404 Monitor',
            'ottodebug'                         => 'OTTO Debug', // Special case
        );

        // Check for settings tabs
        if ($page === $plugin_slug && isset($_GET['tab'])) {
            $tab = sanitize_text_field($_GET['tab']);
            return 'Settings - ' . ucfirst(str_replace('_', ' ', $tab));
        }

        return $screen_map[$page] ?? 'Unknown Page';
    }

    /**
     * Track admin page view
     */
    public function track_admin_page_view() {
        if (!$this->is_configured() || !$this->is_plugin_admin_page() || !$this->is_opted_in()) {
            return;
        }

        $screen_name = $this->get_admin_screen_name();
        $this->track_event_inline('Plugin Admin Accessed', array(
            'Admin Screen Name' => $screen_name
        ));
    }

    /**
     * Track event inline (outputs JavaScript)
     * @param string $event_name
     * @param array $properties
     */
    private function track_event_inline($event_name, $properties = array()) {
        $properties_json = json_encode($properties);
        echo "<script type='text/javascript'>
        if (typeof mixpanel !== 'undefined') {
            mixpanel.track('" . esc_js($event_name) . "', " . $properties_json . ");
        }
        </script>";
    }

    /**
     * Track Content Genius article creation/update
     * @param int $post_id
     * @param string $action 'created' or 'updated'
     */
    public function track_content_genius_event($post_id, $action) {
        if (!$this->is_configured() || !$this->is_opted_in()) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        $event_data = array(
            'user_id' => $this->get_anonymized_user_id(),
            'content_type' => $post->post_type,
            'action' => ucfirst($action),
            'plugin_version' => $this->get_plugin_version()
        );

        // Send event via HTTP API (server-side)
        $this->send_mixpanel_event('Content Genius Article ' . ucfirst($action), $event_data);
    }

    /**
     * Track OTTO page optimization
     * @param array $data Notification data from OTTO
     */
    public function track_otto_optimization($data) {
        if (!$this->is_configured() || !$this->is_opted_in()) {
            return;
        }

        // Extract URLs from data
        $urls = isset($data['urls']) ? $data['urls'] : array();

        foreach ($urls as $url) {
            // Get post ID from URL
            $post_id = url_to_postid($data['domain'] . $url);
            $content_type = 'Unknown';

            if ($post_id > 0) {
                $post = get_post($post_id);
                if ($post) {
                    $content_type = $post->post_type;
                }
            }

            $event_data = array(
                'user_id' => $this->get_anonymized_user_id(),
                'content_type' => $content_type,
                'url' => $url,
                'plugin_version' => $this->get_plugin_version()
            );

            // Send event via HTTP API (server-side)
            $this->send_mixpanel_event(Metasync::get_whitelabel_otto_name() . ' Page Optimized', $event_data);
        }
    }

    /**
     * Track 1-Click Activation (Search Atlas Connect).
     *
     * Tracks when the admin uses 1-click connect to retrieve the Search Atlas API key
     * and Otto UUID from the Search Atlas platform. Does NOT track WordPress logins.
     *
     * @param string $auth_method 'searchatlas_connect' or 'manual'
     * @param bool $is_reconnection Whether this is a re-authentication
     */
    public function track_one_click_activation($auth_method = 'searchatlas_connect', $is_reconnection = false) {
        if (!$this->is_configured() || !$this->is_opted_in()) {
            return;
        }

        $event_data = array(
            'user_id' => $this->get_anonymized_user_id(),
            'auth_method' => $auth_method,
            'is_reconnection' => $is_reconnection,
            'plugin_version' => $this->get_plugin_version()
        );

        # Send event via HTTP API (server-side)
        $this->send_mixpanel_event('1-Click Activation', $event_data);
    }

    /**
     * Send event to Mixpanel via HTTP API (server-side)
     * @param string $event_name
     * @param array $properties
     */
    private function send_mixpanel_event($event_name, $properties) {
        // Don't send if not configured
        $token = $this->get_mixpanel_token();
        if ($token === null) {
            return;
        }

        // Add distinct_id to properties
        $properties['distinct_id'] = $this->get_anonymized_user_id();
        $properties['token'] = $token;

        // Prepare event data
        $event = array(
            'event' => $event_name,
            'properties' => $properties
        );

        // Encode event data
        $data = base64_encode(json_encode($event));

        // Send to Mixpanel track endpoint
        $url = 'https://api.mixpanel.com/track/?data=' . $data;

        // Use WordPress HTTP API
        wp_remote_get($url, array(
            'timeout' => 5,
            'blocking' => false // Non-blocking to avoid slowing down the request
        ));
    }
}

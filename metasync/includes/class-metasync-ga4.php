<?php
/**
 * GA4 Analytics Integration for MetaSync Plugin
 *
 * Client-side (gtag.js) for admin page views and 1-click activation.
 * Server-side (Measurement Protocol) for Content Genius and OTTO events.
 *
 * @package     Search Atlas SEO
 * @copyright   Copyright (C) 2021-2026, Search Atlas Group - support@searchatlas.com
 * @link        https://searchatlas.com/
 * @since       2.6.0
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class Metasync_GA4 {

    private const OPT_IN_OPTION = 'metasync_analytics_opt_in';

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!$this->is_configured()) {
            return;
        }

        if (get_option(self::OPT_IN_OPTION) === false) {
            update_option(self::OPT_IN_OPTION, 'yes');
        }

        add_action('admin_enqueue_scripts', array($this, 'enqueue_ga4_script'));
        add_action('admin_head', array($this, 'track_admin_page_view'));
    }

    private function get_measurement_id() {
        return defined('METASYNC_GA4_MEASUREMENT_ID') && !empty(METASYNC_GA4_MEASUREMENT_ID)
            ? METASYNC_GA4_MEASUREMENT_ID
            : null;
    }

    private function is_configured() {
        return $this->get_measurement_id() !== null;
    }

    public function is_opted_in() {
        return get_option(self::OPT_IN_OPTION, 'yes') === 'yes';
    }

    private function get_anonymized_user_id() {
        $current_user = wp_get_current_user();
        if (!$current_user || !$current_user->ID) {
            return 'guest';
        }

        $site_identifier = get_option('siteurl');
        return 'user_' . wp_hash($current_user->ID . '|' . $site_identifier);
    }

    private function get_plugin_version() {
        return defined('METASYNC_VERSION') ? METASYNC_VERSION : 'unknown';
    }

    private function is_plugin_admin_page() {
        if (!isset($_GET['page'])) {
            return false;
        }

        $page = sanitize_text_field($_GET['page']);

        $plugin_slug = 'searchatlas';
        if (class_exists('Metasync_Admin') && isset(Metasync_Admin::$page_slug)) {
            $plugin_slug = Metasync_Admin::$page_slug;
        }

        return $page === $plugin_slug || strpos($page, $plugin_slug . '-') === 0;
    }

    private function get_admin_screen_name() {
        if (!isset($_GET['page'])) {
            return 'Unknown';
        }

        $page = sanitize_text_field($_GET['page']);

        $plugin_slug = 'searchatlas';
        if (class_exists('Metasync_Admin') && isset(Metasync_Admin::$page_slug)) {
            $plugin_slug = Metasync_Admin::$page_slug;
        }

        $screen_map = array(
            $plugin_slug                        => 'Settings',
            $plugin_slug . '-dashboard'         => 'Dashboard',
            $plugin_slug . '-compatibility'     => 'Compatibility',
            $plugin_slug . '-sync-log'          => 'Changes Log',
            $plugin_slug . '-seo-controls'      => 'Indexation Control',
            $plugin_slug . '-redirections'       => 'Redirections',
            $plugin_slug . '-xml-sitemap'       => 'XML Sitemap',
            $plugin_slug . '-robots-txt'        => 'Robots.txt',
            $plugin_slug . '-404-monitor'       => '404 Monitor',
            'ottodebug'                         => 'OTTO Debug',
        );

        if ($page === $plugin_slug && isset($_GET['tab'])) {
            $tab = sanitize_text_field($_GET['tab']);
            return 'Settings - ' . ucfirst(str_replace('_', ' ', $tab));
        }

        return $screen_map[$page] ?? 'Unknown Page';
    }

    public function enqueue_ga4_script() {
        if (!$this->is_configured() || !$this->is_plugin_admin_page() || !$this->is_opted_in()) {
            return;
        }

        $measurement_id = esc_js($this->get_measurement_id());
        $user_id = esc_js($this->get_anonymized_user_id());
        $plugin_version = esc_js($this->get_plugin_version());
        $wp_version = esc_js(get_bloginfo('version'));
        $php_version = esc_js(PHP_VERSION);

        $inline_script = "
var gtagScript = document.createElement('script');
gtagScript.async = true;
gtagScript.src = 'https://www.googletagmanager.com/gtag/js?id={$measurement_id}';
document.head.appendChild(gtagScript);

window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '{$measurement_id}', {
    'user_id': '{$user_id}',
    'send_page_view': false
});
gtag('set', {
    'plugin_version': '{$plugin_version}',
    'wp_version': '{$wp_version}',
    'php_version': '{$php_version}'
});

window.metasyncGA4Track = function(eventName, params) {
    if (typeof gtag !== 'undefined') {
        gtag('event', eventName, params || {});
    }
};";

        wp_register_script('metasync-ga4-init', '', array(), $this->get_plugin_version(), false);
        wp_enqueue_script('metasync-ga4-init');
        wp_add_inline_script('metasync-ga4-init', $inline_script, 'before');
    }

    public function track_admin_page_view() {
        if (!$this->is_configured() || !$this->is_plugin_admin_page() || !$this->is_opted_in()) {
            return;
        }

        $screen_name = $this->get_admin_screen_name();
        $params = wp_json_encode(
            array('screen_name' => $screen_name),
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        echo "<script type='text/javascript'>
        if (typeof gtag !== 'undefined') {
            gtag('event', 'plugin_admin_accessed', " . $params . ");
        }
        </script>";
    }

    /**
     * Track Content Genius article creation/update (server-side)
     * @param int    $post_id
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

        $this->send_mp_event('content_genius_article_' . strtolower($action), array(
            'content_type' => $post->post_type,
            'action'       => ucfirst($action),
        ));
    }

    /**
     * Track OTTO page optimization (server-side)
     * @param array $data Notification data from OTTO
     */
    public function track_otto_optimization($data) {
        if (!$this->is_configured() || !$this->is_opted_in()) {
            return;
        }

        $urls = isset($data['urls']) ? $data['urls'] : array();

        foreach ($urls as $url) {
            $post_id = url_to_postid((isset($data['domain']) ? $data['domain'] : '') . $url);
            $content_type = 'unknown';

            if ($post_id > 0) {
                $post = get_post($post_id);
                if ($post) {
                    $content_type = $post->post_type;
                }
            }

            $this->send_mp_event('otto_page_optimized', array(
                'content_type' => $content_type,
                'url'          => $url,
            ));
        }
    }

    /**
     * Track 1-Click Activation (server-side)
     * @param string $auth_method
     * @param bool   $is_reconnection
     */
    public function track_one_click_activation($auth_method = 'searchatlas_connect', $is_reconnection = false) {
        if (!$this->is_configured() || !$this->is_opted_in()) {
            return;
        }

        $this->send_mp_event('one_click_activation', array(
            'auth_method'     => $auth_method,
            'is_reconnection' => $is_reconnection,
        ));
    }

    /**
     * Get GA4 Measurement Protocol API secret
     * @return string|null
     */
    private function get_api_secret() {
        return defined('METASYNC_GA4_API_SECRET') && !empty(METASYNC_GA4_API_SECRET)
            ? METASYNC_GA4_API_SECRET
            : null;
    }

    /**
     * Send event via GA4 Measurement Protocol (server-side)
     * Requires METASYNC_GA4_API_SECRET to be set; silently skips if not configured.
     * @param string $event_name
     * @param array  $params
     */
    private function send_mp_event($event_name, $params = array()) {
        $measurement_id = $this->get_measurement_id();
        $api_secret     = $this->get_api_secret();

        if (!$measurement_id || !$api_secret) {
            return;
        }

        $params['plugin_version'] = $this->get_plugin_version();
        $params['wp_version']     = get_bloginfo('version');
        $params['php_version']    = PHP_VERSION;

        $body = wp_json_encode(array(
            'client_id' => $this->get_anonymized_user_id(),
            'events'    => array(
                array(
                    'name'   => $event_name,
                    'params' => $params,
                ),
            ),
        ));

        $url = add_query_arg(
            array(
                'measurement_id' => $measurement_id,
                'api_secret'     => $api_secret,
            ),
            'https://www.google-analytics.com/mp/collect'
        );

        wp_remote_post($url, array(
            'body'     => $body,
            'headers'  => array('Content-Type' => 'application/json'),
            'timeout'  => 5,
            'blocking' => false,
        ));
    }
}

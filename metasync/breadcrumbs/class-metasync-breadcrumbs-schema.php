<?php
/**
 * MetaSync Breadcrumbs Schema — BreadcrumbList JSON-LD output via wp_head.
 *
 * @package    Metasync
 * @subpackage Metasync/breadcrumbs
 * @since      2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Breadcrumbs_Schema {

    /**
     * Flag indicating whether this class has already injected BreadcrumbList JSON-LD.
     *
     * Checked by Metasync_Schema_Markup to avoid duplicate output.
     *
     * @var bool
     */
    public static $breadcrumb_list_injected = false;

    /**
     * Plugin name.
     *
     * @var string
     */
    private $plugin_name;

    /**
     * Plugin version.
     *
     * @var string
     */
    private $version;

    /**
     * Constructor.
     *
     * @param string $plugin_name Plugin slug.
     * @param string $version     Plugin version.
     */
    public function __construct($plugin_name = 'metasync', $version = '1.0.0') {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;

        // Priority 5 — before the schema-markup module which runs at default (10).
        add_action('wp_head', array($this, 'output_breadcrumb_schema'), 5);
    }

    /**
     * Output BreadcrumbList JSON-LD in <head>.
     */
    public function output_breadcrumb_schema() {
        // Check if breadcrumbs are enabled.
        $settings = Metasync::get_option('breadcrumbs', array());
        if (isset($settings['enabled']) && empty($settings['enabled'])) {
            return;
        }

        // Skip on admin, feeds, robots.
        if (is_admin() || is_feed() || is_robots()) {
            return;
        }

        // --- Cross-plugin compatibility: skip if another SEO plugin handles BreadcrumbList ---

        // Yoast SEO.
        if (defined('WPSEO_VERSION')) {
            $wpseo_options = get_option('wpseo', array());
            if (!empty($wpseo_options['breadcrumbs-enable'])) {
                return;
            }
        }

        // Rank Math.
        if (defined('RANK_MATH_VERSION')) {
            return;
        }

        // AIOSEO — breadcrumbs are always available in v4+ (the old enable toggle was removed).
        if (defined('AIOSEO_VERSION')) {
            return;
        }

        // Resolve the trail.
        $breadcrumbs = new Metasync_Breadcrumbs();
        $trail       = $breadcrumbs->resolve_breadcrumb_trail();

        if (count($trail) < 2) {
            return;
        }

        // Build ListItem entries.
        $list_items = array();
        $position   = 1;

        foreach ($trail as $item) {
            $list_item = array(
                '@type'    => 'ListItem',
                'position' => $position,
                'name'     => $item['label'],
            );

            if (!empty($item['url'])) {
                $list_item['item'] = $item['url'];
            }

            $list_items[] = $list_item;
            $position++;
        }

        $json_ld = array(
            '@context' => 'https://schema.org',
            '@graph'   => array(
                array(
                    '@type'           => 'BreadcrumbList',
                    'itemListElement' => $list_items,
                ),
            ),
        );

        echo '<script type="application/ld+json" class="metasync-breadcrumb-schema">' . "\n";
        echo wp_json_encode($json_ld, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        echo "\n" . '</script>' . "\n";

        self::$breadcrumb_list_injected = true;
    }
}

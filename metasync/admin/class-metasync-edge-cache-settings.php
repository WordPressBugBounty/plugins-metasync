<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Edge Cache / CDN Settings for MetaSync Plugin
 *
 * Manages CDN credentials and cache-tag header configuration.
 * Extracted from the main admin class to reduce file size.
 *
 * @package    Metasync
 * @subpackage Metasync/admin
 */
class Metasync_Edge_Cache_Settings {

    /**
     * Option key for storing edge cache settings.
     */
    const OPTION_KEY = 'metasync_edge_cache_options';

    /**
     * Nonce action for edge cache settings.
     */
    const NONCE_ACTION = 'metasync_edge_cache_nonce';

    /**
     * Nonce field name.
     */
    const NONCE_FIELD = 'edge_cache_nonce';

    /**
     * Default settings.
     *
     * @return array
     */
    public static function get_defaults() {
        return array(
            'cache_tags_enabled'     => true,
            'cloudflare_enabled'     => false,
            'cloudflare_zone_id'     => '',
            'cloudflare_api_token'   => '',
            'sucuri_enabled'         => false,
            'sucuri_api_key'         => '',
            'sucuri_api_secret'      => '',
            'fastly_enabled'         => false,
            'fastly_service_id'      => '',
            'fastly_api_token'       => '',
            'akamai_enabled'         => false,
            'akamai_client_token'    => '',
            'akamai_access_token'    => '',
            'akamai_client_secret'   => '',
            'akamai_host'            => '',
            'sevalla_enabled'        => false,
            'sevalla_api_key'        => '',
            'sevalla_application_id' => '',
            'cloudways_enabled'      => false,
            'flywheel_enabled'       => false,
        );
    }

    /**
     * Get all settings merged with defaults.
     *
     * @return array
     */
    public static function get_settings() {
        return wp_parse_args(
            get_option(self::OPTION_KEY, array()),
            self::get_defaults()
        );
    }

    /**
     * All setting keys that hold boolean toggles.
     *
     * @return array
     */
    private static function get_bool_keys() {
        return array(
            'cache_tags_enabled',
            'cloudflare_enabled',
            'sucuri_enabled',
            'fastly_enabled',
            'akamai_enabled',
            'sevalla_enabled',
            'cloudways_enabled',
            'flywheel_enabled',
        );
    }

    /**
     * Sanitize and save settings from $_POST.
     *
     * @param array $post POST data (defaults to $_POST).
     * @return bool True if saved.
     */
    public static function save_from_post($post = null) {
        if ($post === null) {
            $post = $_POST;
        }

        // Only save if at least one edge-cache field is present in the payload.
        if (!isset($post['cache_tags_enabled'])) {
            return false;
        }

        $bool_keys = self::get_bool_keys();
        $defaults  = self::get_defaults();
        $settings  = array();

        foreach ($defaults as $key => $default) {
            if (in_array($key, $bool_keys, true)) {
                $settings[$key] = !empty($post[$key]) && $post[$key] === '1';
            } else {
                $settings[$key] = sanitize_text_field($post[$key] ?? '');
            }
        }

        update_option(self::OPTION_KEY, $settings);
        return true;
    }

    /**
     * AJAX handler: save edge cache settings (standalone endpoint).
     */
    public static function ajax_save() {
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce($_POST[self::NONCE_FIELD], self::NONCE_ACTION)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        if (!Metasync::current_user_has_plugin_access()) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        self::save_from_post();
        wp_send_json_success(array('message' => 'Edge cache settings saved'));
    }

    /**
     * Render the Edge Cache / CDN settings section.
     */
    public static function render() {
        $settings       = self::get_settings();
        $show_sevalla   = !class_exists('KinstaCache');
        $show_cloudways = !empty($_SERVER['HTTP_X_VARNISH']) || !empty(get_option('metasync_cloudways_detected'));
        $show_flywheel  = defined('FLYWHEEL_CONFIG_DIR');
        ?>
        <div style="background: var(--dashboard-card-bg); padding: 20px; border-radius: 8px;">
            <p style="color: var(--dashboard-text-secondary); margin: 0 0 20px 0;">
                Configure CDN credentials for cache-tag purging. When enabled, cache-tag headers are added to OTTO-processed pages so CDNs can purge by post ID.
            </p>

            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>

            <!-- Cache-Tag Headers Master Toggle -->
            <div style="background: var(--dashboard-card-bg-alt, rgba(255,255,255,0.05)); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h3 style="color: var(--dashboard-text-primary); margin: 0 0 14px 0; font-size: 16px; font-weight: 600;">Cache-Tag Headers</h3>
                <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px; cursor: pointer;">
                    <input type="hidden" name="cache_tags_enabled" value="0" />
                    <input type="checkbox" name="cache_tags_enabled" value="1" <?php checked($settings['cache_tags_enabled']); ?> style="width: 16px; height: 16px; cursor: pointer;" />
                    <span style="color: var(--dashboard-text-primary); font-weight: 500;">Enable Cache-Tag Response Headers</span>
                    <span style="color: var(--dashboard-text-secondary); font-size: 12px;">— adds purge-by-tag support for CDNs</span>
                </label>
                <div style="background: rgba(59, 130, 246, 0.1); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 8px; padding: 16px;">
                    <p style="color: var(--dashboard-text-secondary); margin: 0; font-size: 13px;">
                        <strong style="color: var(--dashboard-info, #3b82f6);">ℹ️ How it works:</strong><br>
                        Adds <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 3px;">Cache-Tag</code>,
                        <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 3px;">Surrogate-Key</code>, and
                        <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 3px;">Edge-Cache-Tag</code>
                        headers to singular pages. These allow Cloudflare, Fastly, Akamai, and other CDNs to purge individual posts by tag instead of flushing the entire cache.
                    </p>
                </div>
            </div>

            <!-- CDN Providers -->
            <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--dashboard-border); border-radius: 8px; padding: 20px;">
                <h5 style="margin: 0 0 14px 0; color: var(--dashboard-text-primary);">CDN Provider Credentials</h5>

                <?php
                self::render_provider('cloudflare', 'Cloudflare', 'purges via Cache-Tag API', $settings, array(
                    array('name' => 'cloudflare_zone_id', 'label' => 'Zone ID', 'type' => 'text',     'placeholder' => 'e.g. 023e105f4ecef8ad9ca31a8372d0c353', 'hint' => 'Found in Cloudflare dashboard &rarr; Overview &rarr; Zone ID'),
                    array('name' => 'cloudflare_api_token', 'label' => 'API Token', 'type' => 'password', 'hint' => 'Requires <strong>Cache Purge</strong> permission on the zone'),
                ));

                self::render_provider('sucuri', 'Sucuri WAF', 'purges Sucuri firewall cache', $settings, array(
                    array('name' => 'sucuri_api_key',    'label' => 'API Key',    'type' => 'password', 'hint' => 'Found in Sucuri dashboard &rarr; Settings &rarr; API'),
                    array('name' => 'sucuri_api_secret', 'label' => 'API Secret', 'type' => 'password'),
                ));

                self::render_provider('fastly', 'Fastly', 'purges via Surrogate-Key', $settings, array(
                    array('name' => 'fastly_service_id', 'label' => 'Service ID', 'type' => 'text',     'hint' => 'Found in Fastly dashboard &rarr; Service details'),
                    array('name' => 'fastly_api_token',  'label' => 'API Token',  'type' => 'password', 'hint' => 'Requires <strong>Purge all</strong> or <strong>Purge by key</strong> scope'),
                ));

                $has_more_after_akamai = $show_sevalla || $show_cloudways || $show_flywheel;
                self::render_provider('akamai', 'Akamai', 'purges via Edge-Cache-Tag', $settings, array(
                    array('name' => 'akamai_client_token',  'label' => 'Client Token',  'type' => 'password'),
                    array('name' => 'akamai_access_token',  'label' => 'Access Token',  'type' => 'password'),
                    array('name' => 'akamai_client_secret', 'label' => 'Client Secret', 'type' => 'password'),
                    array('name' => 'akamai_host',          'label' => 'Host',          'type' => 'text', 'placeholder' => 'e.g. akab-xxxxx.purge.akamaiapis.net', 'hint' => 'Found in Akamai Control Center &rarr; Identity &amp; Access &rarr; API credentials'),
                ), !$has_more_after_akamai);

                if ($show_sevalla) {
                    $has_more_after_sevalla = $show_cloudways || $show_flywheel;
                    self::render_provider('sevalla', 'Kinsta / Sevalla', 'purges edge cache via v3 API (v2 fallback)', $settings, array(
                        array('name' => 'sevalla_api_key',        'label' => 'Sevalla API Key', 'type' => 'password', 'hint' => 'Found in Sevalla dashboard &rarr; Company &rarr; API Keys'),
                        array('name' => 'sevalla_application_id', 'label' => 'Application ID',  'type' => 'text',     'placeholder' => 'e.g. fb5e5168-4281-4bec-94c5-0d1584e9e657', 'hint' => 'UUID format — found in the site URL: <code style="background: rgba(255,255,255,0.1); padding: 2px 6px; border-radius: 3px;">my.sevalla.com/applications/<strong>{UUID}</strong></code>'),
                    ), !$has_more_after_sevalla, 'Uses v3 edge cache purge API with v2 fallback. If KinstaCache mu-plugin is available, native purge is used instead.');
                }

                if ($show_cloudways) {
                    self::render_provider('cloudways', 'Cloudways', 'purges Varnish cache per URL', $settings, array(), !$show_flywheel, 'Detected Cloudways Varnish. Enable to purge Varnish on each OTTO update.');
                }

                if ($show_flywheel) {
                    self::render_provider('flywheel', 'Flywheel', 'full cache flush on OTTO updates', $settings, array(), true, 'Detected Flywheel hosting. Enable to clear cache on each OTTO update.');
                }
                ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Toggle credential fields visibility when provider checkbox changes
            var providers = ['cloudflare', 'sucuri', 'fastly', 'akamai', 'sevalla', 'cloudways', 'flywheel'];
            $.each(providers, function(_, name) {
                var $checkbox = $('#metasync-ec-' + name);
                var $fields = $('.metasync-ec-' + name + '-fields');
                if ($checkbox.length && $fields.length) {
                    $checkbox.on('change', function() {
                        if ($(this).is(':checked')) {
                            $fields.slideDown(200);
                        } else {
                            $fields.slideUp(200);
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render a single CDN provider block.
     *
     * @param string $key         Provider key (e.g. 'cloudflare').
     * @param string $title       Display title.
     * @param string $description Short dash-prefixed description.
     * @param array  $settings    Current settings.
     * @param array  $fields      Field definitions.
     * @param bool   $is_last     Whether this is the last provider (no bottom border).
     * @param string $notice      Optional warning notice text.
     */
    private static function render_provider($key, $title, $description, $settings, $fields, $is_last = false, $notice = '') {
        $enabled_key = $key . '_enabled';
        $is_enabled  = !empty($settings[$enabled_key]);
        $border      = $is_last ? '' : 'border-bottom: 1px solid var(--dashboard-border); padding-bottom: 20px; margin-bottom: 20px;';
        ?>
        <div class="metasync-edge-provider" style="<?php echo $border; ?>">
            <label style="display: flex; align-items: center; gap: 10px; margin-bottom: 12px; cursor: pointer;">
                <input type="hidden" name="<?php echo esc_attr($enabled_key); ?>" value="0" />
                <input type="checkbox"
                       id="metasync-ec-<?php echo esc_attr($key); ?>"
                       name="<?php echo esc_attr($enabled_key); ?>"
                       value="1"
                       <?php checked($is_enabled); ?>
                       style="width: 16px; height: 16px; cursor: pointer;" />
                <span style="color: var(--dashboard-text-primary); font-weight: 500;"><?php echo esc_html($title); ?></span>
                <span style="color: var(--dashboard-text-secondary); font-size: 12px;">— <?php echo esc_html($description); ?></span>
            </label>
            <div class="metasync-ec-<?php echo esc_attr($key); ?>-fields" style="margin-left: 26px; <?php echo $is_enabled ? '' : 'display: none;'; ?>">
                <?php if (!empty($notice)) : ?>
                <div style="background: rgba(255, 193, 7, 0.1); border-left: 4px solid #ffc107; padding: 12px; border-radius: 4px; margin-bottom: 16px;">
                    <span style="color: var(--dashboard-text-secondary); font-size: 12px;"><?php echo esc_html($notice); ?></span>
                </div>
                <?php endif; ?>

                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
                    <?php foreach ($fields as $field) : ?>
                    <div>
                        <label style="display: block; color: var(--dashboard-text-primary); margin-bottom: 8px; font-weight: 500;">
                            <?php echo esc_html($field['label']); ?>
                        </label>
                        <input type="<?php echo esc_attr($field['type']); ?>"
                               name="<?php echo esc_attr($field['name']); ?>"
                               value="<?php echo esc_attr($settings[$field['name']]); ?>"
                               <?php if (!empty($field['placeholder'])) : ?>placeholder="<?php echo esc_attr($field['placeholder']); ?>"<?php endif; ?>
                               style="width: 100%; padding: 8px; border: 1px solid var(--dashboard-border); border-radius: 6px; background: var(--dashboard-card-bg); color: var(--dashboard-text-primary); box-sizing: border-box;" />
                        <?php if (!empty($field['hint'])) : ?>
                        <p style="color: var(--dashboard-text-secondary); font-size: 12px; margin: 4px 0 0 0;">
                            <?php echo wp_kses($field['hint'], array('strong' => array(), 'code' => array('style' => array()))); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }
}

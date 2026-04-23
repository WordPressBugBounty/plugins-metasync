<?php
/**
 * MCP Tools: OTTO Pipeline Tools
 *
 * Provides three MCP tools that close the loop for AI-driven SEO work:
 *   1. Trigger Otto Optimization — runs the full OTTO pipeline (warm
 *      transient → SEO DB sync → plugin sync → cache purge → cache warm →
 *      CDN purge) for a URL or post.
 *   2. Get Otto Status — returns the current OTTO state for a URL or post
 *      (enabled flag, exclusion, transient warmth, last-written meta,
 *      persistence settings, plugin sync timestamps).
 *   3. Verify SEO Output — fetches the live rendered HTML for a URL and
 *      compares the rendered head tags against stored post meta.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trigger OTTO Optimization Tool
 */
class MCP_Tool_Trigger_Otto_Optimization extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_trigger_otto_optimization';
    }

    public function get_description() {
        return 'Trigger the full OTTO optimization pipeline for a URL or post ID: warm transient → SEO DB sync → plugin sync → cache purge → cache warm → CDN purge. Requires OTTO to be enabled and is rate-limited to 10 calls per minute per API key.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => [
                    'type'        => 'string',
                    'description' => 'Absolute URL to optimize. Either url or post_id must be provided.',
                ],
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'Post or page ID to optimize. Either url or post_id must be provided.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $has_url     = !empty($params['url']);
        $has_post_id = isset($params['post_id']) && (int) $params['post_id'] > 0;

        if (!$has_url && !$has_post_id) {
            throw new InvalidArgumentException('Either url or post_id is required');
        }
        if ($has_url && $has_post_id) {
            throw new InvalidArgumentException('Provide only one of url or post_id, not both');
        }

        // Resolve url <-> post_id
        if ($has_post_id) {
            $post_id = $this->sanitize_integer($params['post_id']);
            $url     = get_permalink($post_id);
            if (empty($url)) {
                throw new Exception(sprintf('Unable to resolve permalink for post_id %d', $post_id));
            }
        } else {
            $url     = $this->sanitize_url($params['url']);
            $post_id = (int) url_to_postid($url);
        }

        // Post-level permission check (when a specific post is targeted)
        if ($has_post_id && $post_id > 0) {
            $this->verify_post_exists($post_id);
            $this->check_post_permission($post_id);
        }

        // Load OTTO config
        $otto_base = dirname(dirname(dirname(__FILE__))) . '/otto/';
        if (!class_exists('Metasync_Otto_Config')) {
            $config_file = $otto_base . 'class-metasync-otto-config.php';
            if (file_exists($config_file)) {
                require_once $config_file;
            }
        }

        if (!class_exists('Metasync_Otto_Config') || !Metasync_Otto_Config::is_otto_enabled()) {
            throw new Exception('OTTO is not enabled for this site.');
        }

        // Rate-limit: 10 calls per 60s per API key (fall back to IP)
        if (class_exists('Metasync_Rate_Limiter')) {
            $options = get_option('metasync_options', []);
            $api_key = isset($options['general']['apikey']) ? (string) $options['general']['apikey'] : '';
            $rate_source = $api_key !== '' ? $api_key : (isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'anon');
            $rate_key    = hash('sha256', $rate_source);
            $rl_result   = Metasync_Rate_Limiter::get_instance()->check_rate_limit($rate_key, 10, 60, 'otto_trigger_');
            if (is_wp_error($rl_result)) {
                throw new Exception($rl_result->get_error_message());
            }
        }

        $otto_uuid = Metasync_Otto_Config::get_otto_uuid();
        $steps     = [];

        // Helper: time a callable and return a standardized step result.
        // The callable returns either a boolean success flag or an array
        // overriding fields in the result (e.g. ['success'=>false,'reason'=>'x']).
        $run_step = function (callable $fn) {
            $start = microtime(true);
            try {
                $outcome = $fn();
            } catch (Exception $e) {
                return [
                    'success'     => false,
                    'error'       => $e->getMessage(),
                    'duration_ms' => round((microtime(true) - $start) * 1000, 2),
                ];
            }
            $duration_ms = round((microtime(true) - $start) * 1000, 2);
            if (is_array($outcome)) {
                return array_merge(['duration_ms' => $duration_ms], $outcome);
            }
            return [
                'success'     => (bool) $outcome,
                'duration_ms' => $duration_ms,
            ];
        };

        // Step 1: warm_transient
        $steps['warm_transient'] = $run_step(function () use ($otto_base, $otto_uuid, $url) {
            $transient_file = $otto_base . 'class-metasync-otto-transient-cache.php';
            if (!class_exists('Metasync_Otto_Transient_Cache') && file_exists($transient_file)) {
                require_once $transient_file;
            }
            if (!class_exists('Metasync_Otto_Transient_Cache') || empty($otto_uuid)) {
                return ['success' => false, 'reason' => 'not_available'];
            }
            $tc     = new Metasync_Otto_Transient_Cache($otto_uuid);
            $result = $tc->warm_cache($url);
            return $result !== false;
        });

        // Step 2: seo_db_sync
        $steps['seo_db_sync'] = $run_step(function () use ($url) {
            if (!function_exists('metasync_process_otto_seo_data')) {
                return ['success' => false, 'reason' => 'not_available'];
            }
            return (bool) metasync_process_otto_seo_data($url);
        });

        // Step 3: plugin_sync (WP-196 — may not be available yet)
        $steps['plugin_sync'] = $run_step(function () use ($post_id) {
            if (!class_exists('Metasync_Plugin_Sync')) {
                return ['success' => false, 'reason' => 'not_available'];
            }
            $sync_result = null;
            if (method_exists('Metasync_Plugin_Sync', 'sync_post')) {
                $sync_result = Metasync_Plugin_Sync::sync_post($post_id);
            } elseif (method_exists('Metasync_Plugin_Sync', 'sync')) {
                $sync_result = Metasync_Plugin_Sync::sync($post_id);
            } else {
                return ['success' => false, 'reason' => 'not_available'];
            }
            return $sync_result !== false;
        });

        // Step 4: cache_purge
        $steps['cache_purge'] = $run_step(function () use ($url) {
            if (!class_exists('Metasync_Cache_Purge')) {
                return ['success' => false, 'reason' => 'not_available'];
            }
            Metasync_Cache_Purge::purge_single_url($url);
            return true;
        });

        // Step 5: cache_warm
        $steps['cache_warm'] = $run_step(function () use ($url) {
            if (!class_exists('Metasync_Cache_Purge')) {
                return ['success' => false, 'reason' => 'not_available'];
            }
            Metasync_Cache_Purge::warm_urls([$url]);
            return true;
        });

        // Step 6: cdn_purge
        $steps['cdn_purge'] = $run_step(function () use ($url) {
            if (!class_exists('Metasync_Edge_Cache_Purge')) {
                return ['success' => false, 'reason' => 'not_available'];
            }
            Metasync_Edge_Cache_Purge::purge([$url]);
            return true;
        });

        return $this->success(
            [
                'url'     => $url,
                'post_id' => $post_id ?: null,
                'steps'   => $steps,
            ],
            'OTTO optimization pipeline completed'
        );
    }
}

/**
 * Get OTTO Status Tool
 */
class MCP_Tool_Get_Otto_Status extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_get_otto_status';
    }

    public function get_description() {
        return 'Get the current OTTO state for a URL or post: enabled flag, exclusion status, transient warmth, last-written OTTO meta values, persistence settings, and plugin sync timestamps. Works regardless of whether OTTO is enabled.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => [
                    'type'        => 'string',
                    'description' => 'Absolute URL to inspect. Either url or post_id must be provided.',
                ],
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'Post or page ID to inspect. Either url or post_id must be provided.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $has_url     = !empty($params['url']);
        $has_post_id = isset($params['post_id']) && (int) $params['post_id'] > 0;

        if (!$has_url && !$has_post_id) {
            throw new InvalidArgumentException('Either url or post_id is required');
        }
        if ($has_url && $has_post_id) {
            throw new InvalidArgumentException('Provide only one of url or post_id, not both');
        }

        if ($has_post_id) {
            $post_id = $this->sanitize_integer($params['post_id']);
            $this->verify_post_exists($post_id);
            $this->check_post_permission($post_id);
            $url     = get_permalink($post_id);
        } else {
            $url     = $this->sanitize_url($params['url']);
            $post_id = (int) url_to_postid($url);
            if ($post_id > 0) {
                $this->check_post_permission($post_id);
            }
        }

        $otto_base = dirname(dirname(dirname(__FILE__))) . '/otto/';
        if (!class_exists('Metasync_Otto_Config')) {
            $config_file = $otto_base . 'class-metasync-otto-config.php';
            if (file_exists($config_file)) {
                require_once $config_file;
            }
        }

        $otto_enabled = class_exists('Metasync_Otto_Config') ? Metasync_Otto_Config::is_otto_enabled() : false;
        $otto_uuid    = class_exists('Metasync_Otto_Config') ? Metasync_Otto_Config::get_otto_uuid() : '';

        // Exclusion status (manual only — reflects user-configured exclusions)
        $url_excluded = null;
        if (!empty($url)) {
            if (!function_exists('metasync_is_otto_url_manually_excluded')) {
                $pixel_file = $otto_base . 'otto_pixel.php';
                if (file_exists($pixel_file)) {
                    require_once $pixel_file;
                }
            }
            if (function_exists('metasync_is_otto_url_manually_excluded')) {
                $url_excluded = (bool) metasync_is_otto_url_manually_excluded($url);
            }
        }

        // Transient state
        $transient = [
            'warm'            => false,
            'has_suggestions' => false,
        ];
        if (!empty($url) && !empty($otto_uuid)) {
            if (!class_exists('Metasync_Otto_Transient_Cache')) {
                $transient_file = $otto_base . 'class-metasync-otto-transient-cache.php';
                if (file_exists($transient_file)) {
                    require_once $transient_file;
                }
            }
            if (class_exists('Metasync_Otto_Transient_Cache')) {
                $tc    = new Metasync_Otto_Transient_Cache($otto_uuid);
                $stats = $tc->get_stats($url);
                $transient['warm']            = !empty($stats['has_cache']);
                $transient['has_suggestions'] = !empty($stats['has_suggestions']);
                $transient['cache_key']       = isset($stats['cache_key']) ? $stats['cache_key'] : null;

                // Get transient expiry for the OTTO cache
                global $wpdb;
                $site_id        = is_multisite() ? get_current_blog_id() : 0;
                $normalized_url = rtrim(strtolower($url), '/');
                $url_hash       = md5($normalized_url);
                $transient_key  = 'otto_suggestions_' . $site_id . '_' . $url_hash;
                $timeout_key    = '_transient_timeout_' . $transient_key;
                $expiry_raw = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                        $timeout_key
                    )
                );
                $cache_expires_at = $expiry_raw ? (int) $expiry_raw : null;
                $cache_expires_in = $cache_expires_at ? max(0, $cache_expires_at - time()) : null;

                $transient['cache_expires_at']         = $cache_expires_at;
                $transient['cache_expires_in_seconds'] = $cache_expires_in;
            }
        }

        // Last-written OTTO meta values
        $otto_meta_keys = [
            '_metasync_otto_title',
            '_metasync_otto_description',
            '_metasync_otto_keywords',
            '_metasync_otto_og_title',
            '_metasync_otto_og_description',
            '_metasync_otto_twitter_title',
            '_metasync_otto_twitter_description',
            '_metasync_canonical_url',
            '_metasync_otto_structured_data',
            '_metasync_otto_image_alt_data',
            '_metasync_otto_headings_data',
            '_metasync_otto_last_update',
        ];
        $last_written_meta = [];
        if ($post_id > 0) {
            foreach ($otto_meta_keys as $key) {
                $val = get_post_meta($post_id, $key, true);
                if ($val !== '' && $val !== false && $val !== null) {
                    $last_written_meta[$key] = $val;
                }
            }
        }

        // Persistence settings
        $persistence_settings = null;
        if (!class_exists('Metasync_Otto_Persistence_Settings')) {
            $persist_file = $otto_base . 'class-metasync-otto-persistence-settings.php';
            if (file_exists($persist_file)) {
                require_once $persist_file;
            }
        }
        if (class_exists('Metasync_Otto_Persistence_Settings')) {
            $persistence_settings = Metasync_Otto_Persistence_Settings::get_settings();
        }

        // Plugin sync timestamp (written by WP-196 when available)
        $plugin_sync_timestamp = null;
        if ($post_id > 0) {
            $ts = get_post_meta($post_id, '_metasync_plugin_sync_ts', true);
            $plugin_sync_timestamp = $ts !== '' ? $ts : null;
        }

        return $this->success(
            [
                'otto_enabled'          => (bool) $otto_enabled,
                'url'                   => $url,
                'post_id'               => $post_id ?: null,
                'url_excluded'          => $url_excluded,
                'transient'             => $transient,
                'last_written_meta'     => $last_written_meta,
                'persistence_settings'  => $persistence_settings,
                'plugin_sync_timestamp' => $plugin_sync_timestamp,
            ],
            'OTTO status retrieved'
        );
    }
}

/**
 * Verify SEO Output Tool
 */
class MCP_Tool_Verify_SEO_Output extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_verify_seo_output';
    }

    public function get_description() {
        return 'Fetch the live rendered HTML for a URL and extract what Google actually sees (title, description, canonical, robots, OG/Twitter tags, JSON-LD schema, hreflang). Compares rendered values against stored post meta and reports duplicate head tags.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => [
                    'type'        => 'string',
                    'description' => 'Absolute URL to fetch and verify.',
                ],
                'fields' => [
                    'type'        => 'array',
                    'description' => 'Optional list of field categories to include. If omitted, all are returned.',
                    'items'       => [
                        'type' => 'string',
                        'enum' => ['title', 'description', 'canonical', 'robots', 'og', 'twitter', 'hreflang', 'schema'],
                    ],
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $url = $this->sanitize_url($params['url']);
        if (empty($url)) {
            throw new InvalidArgumentException('url is required');
        }

        $fields_filter = [];
        if (!empty($params['fields']) && is_array($params['fields'])) {
            foreach ($params['fields'] as $f) {
                if (is_string($f)) {
                    $fields_filter[] = strtolower($f);
                }
            }
        }
        $include = function ($category) use ($fields_filter) {
            return empty($fields_filter) || in_array($category, $fields_filter, true);
        };

        $post_id = (int) url_to_postid($url);

        // Fetch the live HTML
        $response = wp_remote_get($url, [
            'timeout' => 15,
            'redirection' => 5,
            'user-agent' => 'MetaSync-Verify/1.0',
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            throw new Exception('HTTP fetch failed: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $html   = (string) wp_remote_retrieve_body($response);

        // Load simplehtmldom via the OTTO HTML class which owns the vendor lib
        $html_class_file = dirname(dirname(dirname(__FILE__))) . '/otto/Otto_html_class.php';
        if (file_exists($html_class_file)) {
            require_once $html_class_file;
        }
        if (!class_exists('simplehtmldom\\HtmlDocument')) {
            throw new Exception('simplehtmldom HtmlDocument is not available');
        }

        $dom = new simplehtmldom\HtmlDocument($html, true, true, 'UTF-8', false);

        // --- Extract rendered values ---
        $rendered = [];
        $duplicates = [];

        // title
        $title_nodes = $dom->find('title');
        $title_val   = null;
        if (!empty($title_nodes)) {
            $title_val = trim($title_nodes[0]->plaintext);
            if (count($title_nodes) > 1) {
                $duplicates['title'] = count($title_nodes);
            }
        }
        if ($include('title')) {
            $rendered['title'] = $title_val;
        }

        // meta description
        $desc_nodes = $dom->find('meta[name=description]');
        $desc_val   = null;
        if (!empty($desc_nodes)) {
            $desc_val = isset($desc_nodes[0]->content) ? trim($desc_nodes[0]->content) : null;
            if (count($desc_nodes) > 1) {
                $duplicates['meta[name=description]'] = count($desc_nodes);
            }
        }
        if ($include('description')) {
            $rendered['description'] = $desc_val;
        }

        // canonical
        $canon_nodes = $dom->find('link[rel=canonical]');
        $canon_val   = null;
        if (!empty($canon_nodes)) {
            $canon_val = isset($canon_nodes[0]->href) ? trim($canon_nodes[0]->href) : null;
            if (count($canon_nodes) > 1) {
                $duplicates['link[rel=canonical]'] = count($canon_nodes);
            }
        }
        if ($include('canonical')) {
            $rendered['canonical'] = $canon_val;
        }

        // robots
        $robots_nodes = $dom->find('meta[name=robots]');
        $robots_val   = null;
        if (!empty($robots_nodes)) {
            $robots_val = isset($robots_nodes[0]->content) ? trim($robots_nodes[0]->content) : null;
            if (count($robots_nodes) > 1) {
                $duplicates['meta[name=robots]'] = count($robots_nodes);
            }
        }
        if ($include('robots')) {
            $rendered['robots'] = $robots_val;
        }

        // OG tags (property starts with og:)
        if ($include('og')) {
            $og_tags = [];
            foreach ($dom->find('meta') as $m) {
                $property = isset($m->property) ? (string) $m->property : '';
                if ($property !== '' && strpos($property, 'og:') === 0) {
                    $og_tags[$property] = isset($m->content) ? (string) $m->content : '';
                }
            }
            $rendered['og'] = $og_tags;
        }

        // Twitter tags
        if ($include('twitter')) {
            $tw_tags = [];
            foreach ($dom->find('meta') as $m) {
                $name = isset($m->name) ? (string) $m->name : '';
                if ($name !== '' && strpos($name, 'twitter:') === 0) {
                    $tw_tags[$name] = isset($m->content) ? (string) $m->content : '';
                }
            }
            $rendered['twitter'] = $tw_tags;
        }

        // hreflang
        if ($include('hreflang')) {
            $hreflang = [];
            foreach ($dom->find('link[rel=alternate]') as $lnk) {
                $hl = isset($lnk->hreflang) ? (string) $lnk->hreflang : '';
                if ($hl !== '') {
                    $hreflang[] = [
                        'hreflang' => $hl,
                        'href'     => isset($lnk->href) ? (string) $lnk->href : '',
                    ];
                }
            }
            $rendered['hreflang'] = $hreflang;
        }

        // JSON-LD schema
        if ($include('schema')) {
            $schemas = [];
            foreach ($dom->find('script[type=application/ld+json]') as $s) {
                $raw     = trim($s->innertext);
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $schemas[] = $decoded;
                } else {
                    $schemas[] = ['_raw' => $raw, '_parse_error' => json_last_error_msg()];
                }
            }
            $rendered['schema'] = $schemas;
        }

        // --- Stored meta for comparison ---
        $stored_meta_keys = [
            'title'               => '_metasync_otto_title',
            'description'         => '_metasync_otto_description',
            'canonical'           => '_metasync_canonical_url',
            'og_title'            => '_metasync_otto_og_title',
            'og_description'      => '_metasync_otto_og_description',
            'twitter_title'       => '_metasync_otto_twitter_title',
            'twitter_description' => '_metasync_otto_twitter_description',
        ];
        $stored_meta = [];
        if ($post_id > 0) {
            foreach ($stored_meta_keys as $logical => $meta_key) {
                $val = get_post_meta($post_id, $meta_key, true);
                $stored_meta[$logical] = $val !== '' ? $val : null;
            }
        } else {
            foreach ($stored_meta_keys as $logical => $meta_key) {
                $stored_meta[$logical] = null;
            }
        }

        // --- Field-by-field comparison ---
        $comparison = [];

        $add_comparison = function ($category, $field, $stored_val, $rendered_val) use (&$comparison, $include) {
            if (!$include($category)) {
                return;
            }
            $comparison[] = [
                'field'    => $field,
                'match'    => ($stored_val === $rendered_val) || ($stored_val === null && $rendered_val === null) || ((string) $stored_val === (string) $rendered_val),
                'stored'   => $stored_val,
                'rendered' => $rendered_val,
            ];
        };

        $add_comparison('title', 'title', $stored_meta['title'], $title_val);
        $add_comparison('description', 'description', $stored_meta['description'], $desc_val);
        $add_comparison('canonical', 'canonical', $stored_meta['canonical'], $canon_val);

        if ($include('og')) {
            $og_rendered = isset($rendered['og']) ? $rendered['og'] : [];
            $add_comparison('og', 'og:title', $stored_meta['og_title'], isset($og_rendered['og:title']) ? $og_rendered['og:title'] : null);
            $add_comparison('og', 'og:description', $stored_meta['og_description'], isset($og_rendered['og:description']) ? $og_rendered['og:description'] : null);
        }
        if ($include('twitter')) {
            $tw_rendered = isset($rendered['twitter']) ? $rendered['twitter'] : [];
            $add_comparison('twitter', 'twitter:title', $stored_meta['twitter_title'], isset($tw_rendered['twitter:title']) ? $tw_rendered['twitter:title'] : null);
            $add_comparison('twitter', 'twitter:description', $stored_meta['twitter_description'], isset($tw_rendered['twitter:description']) ? $tw_rendered['twitter:description'] : null);
        }

        return $this->success(
            [
                'url'           => $url,
                'post_id'       => $post_id ?: null,
                'http_status'   => $status,
                'fetch_status'  => $status >= 200 && $status < 400 ? 'ok' : 'error',
                'rendered'      => $rendered,
                'stored_meta'   => $stored_meta,
                'comparison'    => $comparison,
                'duplicate_tags' => $duplicates,
            ],
            'SEO output verification complete'
        );
    }
}

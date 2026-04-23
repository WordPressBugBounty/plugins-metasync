<?php
/**
 * MCP Tool: Cache Purge
 *
 * Exposes the plugin's existing cache purge infrastructure (Metasync_Cache_Purge
 * and Metasync_Edge_Cache_Purge) as MCP tools so AI agents can clear caches
 * after updating content.
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Purge all WordPress caches and edge/CDN caches.
 *
 * Wraps Metasync_Cache_Purge::clear_all_caches() for WordPress-level caches
 * and Metasync_Edge_Cache_Purge::purge() for CDN providers. Passes home_url
 * to the edge purge so full-flush providers (Sucuri, Sevalla, Flywheel) fire,
 * and URL-based providers (Cloudflare, Cloudways) purge at least the homepage.
 */
class MCP_Tool_Cache_Purge_All extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_cache_purge_all';
    }

    public function get_description() {
        return 'Purge all WordPress caches (object cache + every detected cache plugin) and trigger edge/CDN cache invalidation for configured providers.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'source' => [
                    'type' => 'string',
                    'description' => 'Optional source identifier used for logging (defaults to "mcp").',
                    'default' => 'mcp'
                ]
            ],
            'required' => []
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $source = isset($params['source']) && is_string($params['source'])
            ? $this->sanitize_string($params['source'])
            : 'mcp';

        try {
            $results = Metasync_Cache_Purge::get_instance()->clear_all_caches($source);
            Metasync_Edge_Cache_Purge::purge([home_url('/')]);

            return $this->success([
                'cleared'    => isset($results['cleared']) ? $results['cleared'] : [],
                'failed'     => isset($results['failed']) ? $results['failed'] : [],
                'not_active' => isset($results['not_active']) ? $results['not_active'] : [],
            ], 'All WordPress and edge caches purged.');
        } catch (Exception $e) {
            $this->error('Failed to purge caches: ' . $e->getMessage());
        }
    }
}

/**
 * Purge cache for a specific URL (including CDN/edge cache).
 */
class MCP_Tool_Cache_Purge_URL extends MCP_Tool_Base {

    public function get_name() {
        return 'wordpress_cache_purge_url';
    }

    public function get_description() {
        return 'Purge cache for a specific URL across every detected cache plugin and configured CDN/edge provider.';
    }

    public function get_input_schema() {
        return [
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'Absolute URL to purge from every cache layer.'
                ]
            ],
            'required' => ['url']
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');

        $url = $this->sanitize_url($params['url']);

        if (empty($url)) {
            $this->error('Invalid URL: must be an absolute URL with scheme (e.g. https://example.com/page/).');
        }

        try {
            $page_cleared = Metasync_Cache_Purge::get_instance()->clear_url_cache($url);
            Metasync_Edge_Cache_Purge::purge([$url]);

            return $this->success([
                'url'          => $url,
                'page_cleared' => (bool) $page_cleared
            ], 'Cache cleared for URL.');
        } catch (Exception $e) {
            $this->error('Failed to purge cache for URL: ' . $e->getMessage());
        }
    }
}

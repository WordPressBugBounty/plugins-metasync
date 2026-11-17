<?php
/**
 * MCP tool inventory for the AI Agents admin page.
 *
 * Describes what a connected AI client can do: it runs the shared tool
 * catalogue through a lightweight collector (no MCP server boot, no tool
 * execution) and returns each tool's name, description, admin-facing group
 * and read/write classification. The REST endpoint's behaviour is untouched.
 *
 * @package Search Atlas SEO
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'class-metasync-mcp-tool-access.php';

/**
 * Minimal register_tool() receiver used on admin pages.
 *
 * The registration path only needs this one method; the collector records
 * instances so the inventory can read their metadata.
 */
final class Metasync_MCP_Tool_Collector
{
    /** @var object[] Registered tool instances, in registration order. */
    public $tools = [];

    /**
     * Record a tool instance.
     *
     * @param object $tool Tool instance (MCP_Tool_Base shape).
     * @return void
     */
    public function register_tool($tool)
    {
        $this->tools[] = $tool;
    }
}

/**
 * Builds the admin-facing inventory of MCP tools.
 */
final class Metasync_MCP_Tool_Inventory
{
    /** @var self|null */
    private static $instance = null;

    /** @var array|null Cached grouped inventory. */
    private $groups = null;

    /**
     * Registration group label => consolidated admin-facing label.
     *
     * Registration groups not listed here keep their own label, so a new
     * group added to the catalogue renders without further changes.
     *
     * @var array<string, string>
     */
    private static $display_groups = [
        'Post Meta Operations'        => 'Posts & content',
        'Post Operations'             => 'Posts & content',
        'Post CRUD Operations'        => 'Posts & content',
        'SEO Inventory'               => 'Posts & content',
        'Bulk Operations'             => 'Posts & content',
        'SEO Analysis'                => 'SEO analysis',
        'SEO Plugin Audit'            => 'SEO analysis',
        'Search Operations'           => 'Search',
        'Redirect Management'         => 'Redirects',
        '404 Error Monitoring'        => '404 monitor',
        'Robots.txt & Sitemap Management' => 'Robots & sitemaps',
        'Plugin Settings Management'  => 'Plugin settings',
        'Schema Markup Management'    => 'Schema markup',
        'Google Instant Index'        => 'Instant indexing',
        'Custom HTML Pages'           => 'Custom pages & builders',
        'HTML to Builder Converter'   => 'Custom pages & builders',
        'Code Snippets'               => 'Code snippets',
        'Categories & Taxonomies'     => 'Taxonomies',
        'Tags'                        => 'Taxonomies',
        'Taxonomy Meta Operations'    => 'Taxonomies',
        'Featured Images & Media'     => 'Media & alt text',
        'Bulk Alt Text Operations'    => 'Media & alt text',
        'WordPress Core SEO Settings' => 'WordPress settings',
        'OTTO Persistence Settings'   => 'OTTO',
        'OTTO Pipeline Tools'         => 'OTTO',
        'System Diagnostics & Plugin Info' => 'System',
        'Read-Only Database Access'   => 'Database',
        'Breadcrumb Tools'            => 'Breadcrumbs',
        'Cache Purge'                 => 'Cache',
        'LLMs.txt Tools'              => 'LLMs.txt',
    ];

    /** @var string Label for tools registered outside the catalogue. */
    const OTHER_GROUP = 'Other tools';

    /**
     * @return self
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * Grouped inventory: display label => ['read' => n, 'write' => n, 'tools' => [...]].
     *
     * Group order follows the catalogue's registration order; tools registered
     * by third parties land in a trailing "Other tools" group.
     *
     * @return array<string, array>
     */
    public function get_grouped_tools()
    {
        if ($this->groups !== null) {
            return $this->groups;
        }

        $this->groups = [];

        if (!function_exists('metasync_register_mcp_tools') || !function_exists('metasync_mcp_tool_catalogue')) {
            return $this->groups;
        }

        $collector = new Metasync_MCP_Tool_Collector();

        try {
            metasync_register_mcp_tools($collector, false);
        } catch (Throwable $e) {
            // The inventory must never break the admin page; render nothing.
            return $this->groups;
        }

        $class_group = [];
        foreach (metasync_mcp_tool_catalogue() as $group => $classes) {
            foreach ($classes as $class_name) {
                $class_group[$class_name] = $group;
            }
        }

        foreach ($collector->tools as $tool) {
            try {
                $name = (string) $tool->get_name();
                $description = (string) $tool->get_description();
            } catch (Throwable $e) {
                continue;
            }

            if ($name === '') {
                continue;
            }

            $class = get_class($tool);
            $registration_group = isset($class_group[$class]) ? $class_group[$class] : '';
            if ($registration_group === '') {
                $label = self::OTHER_GROUP;
            } elseif (isset(self::$display_groups[$registration_group])) {
                $label = self::$display_groups[$registration_group];
            } else {
                $label = $registration_group;
            }

            if (!isset($this->groups[$label])) {
                $this->groups[$label] = ['read' => 0, 'write' => 0, 'tools' => []];
            }

            $is_read = Metasync_MCP_Tool_Access::is_read_only_tool($name);
            $this->groups[$label][$is_read ? 'read' : 'write']++;
            $this->groups[$label]['tools'][] = [
                'name' => $name,
                'description' => $description,
                'read_only' => $is_read,
            ];
        }

        return $this->groups;
    }

    /**
     * Totals across all groups.
     *
     * @return array{'total': int, 'read': int, 'write': int}
     */
    public function get_totals()
    {
        $total = $read = $write = 0;

        foreach ($this->get_grouped_tools() as $group) {
            $total += count($group['tools']);
            $read += $group['read'];
            $write += $group['write'];
        }

        return ['total' => $total, 'read' => $read, 'write' => $write];
    }
}

<?php
/**
 * MCP Tools: LLMs.txt & markdown export.
 *
 * Provides 5 tools:
 *   - wordpress_get_llms_txt
 *   - wordpress_regenerate_llms_txt
 *   - wordpress_get_llms_txt_settings
 *   - wordpress_update_llms_txt_settings
 *   - wordpress_get_post_markdown
 *
 * @package    MetaSync
 * @subpackage MCP_Server/Tools
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Internal helper: locate and require generator/converter classes.
 */
if (!function_exists('metasync_llms_require_dependencies')) {
    function metasync_llms_require_dependencies() {
        $base = plugin_dir_path(dirname(dirname(__FILE__)));
        if (!class_exists('Metasync_Html_To_Markdown')) {
            $file = $base . 'includes/class-metasync-html-to-markdown.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
        if (!class_exists('Metasync_Llms_Txt_Generator')) {
            $file = $base . 'llms-txt/class-metasync-llms-txt-generator.php';
            if (file_exists($file)) {
                require_once $file;
            }
        }
    }
}

class MCP_Tool_Get_LLMs_Txt extends MCP_Tool_Base
{
    public function get_name() {
        return 'wordpress_get_llms_txt';
    }

    public function get_description() {
        return 'Return the current generated /llms.txt content (from cache if available).';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'full' => [
                    'type'        => 'boolean',
                    'description' => 'If true, return /llms-full.txt content instead.',
                    'default'     => false,
                ],
            ],
            'required'   => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');
        metasync_llms_require_dependencies();

        $full = isset($params['full']) ? (bool) $params['full'] : false;
        $key  = $full ? Metasync_Llms_Txt_Generator::TRANSIENT_FULL : Metasync_Llms_Txt_Generator::TRANSIENT_SHORT;

        $content = get_transient($key);
        if (false === $content || $content === '') {
            $generator = new Metasync_Llms_Txt_Generator();
            $content = $full ? $generator->generate_full() : $generator->generate();
            if ($content !== '') {
                set_transient($key, $content, Metasync_Llms_Txt_Generator::CACHE_TTL);
            }
        }

        return $this->success([
            'content' => $content,
            'full'    => $full,
        ]);
    }
}

class MCP_Tool_Regenerate_LLMs_Txt extends MCP_Tool_Base
{
    public function get_name() {
        return 'wordpress_regenerate_llms_txt';
    }

    public function get_description() {
        return 'Force a regeneration of the /llms.txt cache (and /llms-full.txt if enabled).';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');
        metasync_llms_require_dependencies();

        delete_transient(Metasync_Llms_Txt_Generator::TRANSIENT_SHORT);
        delete_transient(Metasync_Llms_Txt_Generator::TRANSIENT_FULL);

        $generator = new Metasync_Llms_Txt_Generator();
        $short = $generator->generate();
        if ($short !== '') {
            set_transient(Metasync_Llms_Txt_Generator::TRANSIENT_SHORT, $short, Metasync_Llms_Txt_Generator::CACHE_TTL);
        }

        $settings = $generator->get_settings();
        $full = '';
        if (!empty($settings['llms_full_enabled'])) {
            $full = $generator->generate_full();
            if ($full !== '') {
                set_transient(Metasync_Llms_Txt_Generator::TRANSIENT_FULL, $full, Metasync_Llms_Txt_Generator::CACHE_TTL);
            }
        }

        return $this->success([
            'regenerated' => true,
            'short_bytes' => strlen($short),
            'full_bytes'  => strlen($full),
        ]);
    }
}

class MCP_Tool_Get_LLMs_Txt_Settings extends MCP_Tool_Base
{
    public function get_name() {
        return 'wordpress_get_llms_txt_settings';
    }

    public function get_description() {
        return 'Return the stored LLMs.txt settings (enabled, post_types, max_posts, excluded_ids, custom_description, llms_full_enabled).';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => new \stdClass(),
            'required'   => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');
        metasync_llms_require_dependencies();

        $generator = new Metasync_Llms_Txt_Generator();
        return $this->success([
            'settings' => $generator->get_settings(),
        ]);
    }
}

class MCP_Tool_Update_LLMs_Txt_Settings extends MCP_Tool_Base
{
    public function get_name() {
        return 'wordpress_update_llms_txt_settings';
    }

    public function get_description() {
        return 'Update LLMs.txt settings. Partial updates supported – only provided keys are modified.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'enabled' => [
                    'type'        => 'boolean',
                    'description' => 'Enable or disable LLMs.txt generation.',
                ],
                'post_types' => [
                    'type'        => 'array',
                    'description' => 'Post types to include (e.g. ["page","post"]).',
                    'items'       => ['type' => 'string'],
                ],
                'max_posts' => [
                    'type'        => 'integer',
                    'description' => 'Maximum number of posts to include (1-500).',
                    'minimum'     => 1,
                    'maximum'     => 500,
                ],
                'excluded_ids' => [
                    'type'        => 'array',
                    'description' => 'Post/page IDs to exclude.',
                    'items'       => ['type' => 'integer'],
                ],
                'custom_description' => [
                    'type'        => 'string',
                    'description' => 'Override for the site tagline.',
                ],
                'llms_full_enabled' => [
                    'type'        => 'boolean',
                    'description' => 'Serve /llms-full.txt when enabled.',
                ],
                'max_posts_full' => [
                    'type'        => 'integer',
                    'description' => 'Maximum posts for /llms-full.txt (1-500, default 25).',
                    'minimum'     => 1,
                    'maximum'     => 500,
                ],
            ],
            'required'   => [],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('manage_options');
        metasync_llms_require_dependencies();

        $existing = get_option(Metasync_Llms_Txt_Generator::OPTION_KEY, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $updated = $existing;

        if (array_key_exists('enabled', $params)) {
            $updated['enabled'] = (bool) $params['enabled'];
        }
        if (array_key_exists('post_types', $params) && is_array($params['post_types'])) {
            $updated['post_types'] = array_values(array_filter(array_map('sanitize_text_field', $params['post_types'])));
        }
        if (array_key_exists('max_posts', $params)) {
            $val = (int) $params['max_posts'];
            $updated['max_posts'] = max(1, min(500, $val));
        }
        if (array_key_exists('excluded_ids', $params) && is_array($params['excluded_ids'])) {
            $updated['excluded_ids'] = array_values(array_filter(array_map('absint', $params['excluded_ids'])));
        }
        if (array_key_exists('custom_description', $params)) {
            $updated['custom_description'] = sanitize_text_field((string) $params['custom_description']);
        }
        if (array_key_exists('llms_full_enabled', $params)) {
            $updated['llms_full_enabled'] = (bool) $params['llms_full_enabled'];
        }
        if (array_key_exists('max_posts_full', $params)) {
            $val = (int) $params['max_posts_full'];
            $updated['max_posts_full'] = max(1, min(500, $val));
        }

        update_option(Metasync_Llms_Txt_Generator::OPTION_KEY, $updated);

        delete_transient(Metasync_Llms_Txt_Generator::TRANSIENT_SHORT);
        delete_transient(Metasync_Llms_Txt_Generator::TRANSIENT_FULL);

        return $this->success([
            'updated'  => true,
            'settings' => $updated,
        ]);
    }
}

class MCP_Tool_Get_Post_Markdown extends MCP_Tool_Base
{
    public function get_name() {
        return 'wordpress_get_post_markdown';
    }

    public function get_description() {
        return 'Convert any published post or page to clean markdown using Metasync_Html_To_Markdown.';
    }

    public function get_input_schema() {
        return [
            'type'       => 'object',
            'properties' => [
                'post_id' => [
                    'type'        => 'integer',
                    'description' => 'Post or page ID.',
                    'minimum'     => 1,
                ],
                'include_frontmatter' => [
                    'type'        => 'boolean',
                    'description' => 'Prepend YAML frontmatter (title/slug/date/author).',
                    'default'     => true,
                ],
                'include_featured_image' => [
                    'type'        => 'boolean',
                    'description' => 'Prepend the featured image as a markdown image.',
                    'default'     => true,
                ],
            ],
            'required'   => ['post_id'],
        ];
    }

    public function execute($params) {
        $this->validate_params($params);
        $this->require_capability('edit_posts');
        metasync_llms_require_dependencies();

        $post_id = absint($params['post_id']);
        $this->verify_post_exists($post_id);
        $this->check_post_permission($post_id);

        $options = [
            'include_frontmatter'    => isset($params['include_frontmatter']) ? (bool) $params['include_frontmatter'] : true,
            'include_featured_image' => isset($params['include_featured_image']) ? (bool) $params['include_featured_image'] : true,
        ];

        $markdown = Metasync_Html_To_Markdown::convert_post($post_id, $options);

        return $this->success([
            'post_id'  => $post_id,
            'markdown' => $markdown,
        ]);
    }
}

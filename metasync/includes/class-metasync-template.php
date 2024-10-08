<?php

/**
 * The Urls Redirection functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/redirections
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Template
{
    const TEMPLATE_NAME = "metasync-blank";
    const TEMPLATE_LABEL = "MetaSync Template";

    public function metasync_getPageTemplatePath()
    {
        return plugin_dir_path(dirname(__FILE__)) . 'templates/template-metasync-blank.php';
    }

    public function metasync_template_landing_page($page_templates)
    {
        $page_templates[self::TEMPLATE_NAME] = self::TEMPLATE_LABEL;
        return $page_templates;
    }

    public function metasync_template_landing_page_load($page_templates)
    {
        global $post;
        $page_template_slug = @get_page_template_slug($post->ID);
        if ($page_template_slug == self::TEMPLATE_NAME)
            return $this->metasync_getPageTemplatePath();
        return $page_templates;
    }
}

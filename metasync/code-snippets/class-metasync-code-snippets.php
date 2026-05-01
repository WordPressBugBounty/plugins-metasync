<?php

/**
 * The header and footer code snippets functionality of the plugin.
 *
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/code-snippets
 * @author     Engineering Team <support@searchatlas.com>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
	exit;
}

class Metasync_Code_Snippets
{

	/**
	 * Get Header Code Snippet.
	 *
	 */
	public function get_header_snippet()
	{
		$code_snippet_options = get_option(Metasync::option_name)['codesnippets'] ?? '';
		$header_snippet_option = $code_snippet_options['header_snippet'] ?? '';
		echo $header_snippet_option;
		echo get_post_meta(get_the_ID())['custom_post_header'][0] ?? '';
		echo get_post_meta(get_the_ID())['searchatlas_embed_top'][0] ?? '';

	}

	/**
	 * Get Footer Code Snippet.
	 *
	 */
	public function get_footer_snippet()
	{
		$code_snippet_options = get_option(Metasync::option_name)['codesnippets'] ?? '';
		$footer_snippet_option = $code_snippet_options['footer_snippet'] ?? '';
		echo $footer_snippet_option;
		echo get_post_meta(get_the_ID())['custom_post_footer'][0] ?? '';
		echo get_post_meta(get_the_ID())['searchatlas_embed_bottom'][0] ?? '';
	}
}

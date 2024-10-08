<?php

/**
 * The header and footer code snippets functionality of the plugin.
 *
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/code-snippets
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
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
		printf($header_snippet_option);
		printf(get_post_meta(get_the_ID())['custom_post_header'][0] ?? '');
		printf(get_post_meta(get_the_ID())['searchatlas_embed_top'][0] ?? '');

	}

	/**
	 * Get Footer Code Snippet.
	 *
	 */
	public function get_footer_snippet()
	{
		$code_snippet_options = get_option(Metasync::option_name)['codesnippets'] ?? '';
		$footer_snippet_option = $code_snippet_options['footer_snippet'] ?? '';
		printf($footer_snippet_option);
		printf(get_post_meta(get_the_ID())['custom_post_footer'][0] ?? '');
		printf(get_post_meta(get_the_ID())['searchatlas_embed_bottom'][0] ?? '');
	}
}

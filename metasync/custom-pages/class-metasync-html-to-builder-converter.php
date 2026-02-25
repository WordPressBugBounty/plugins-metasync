<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * HTML to Theme Builder Converter
 *
 * Converts custom HTML pages into theme builder formats (Elementor, Divi, Gutenberg)
 * with CSS preservation and full builder editability.
 *
 * @package    Metasync
 * @subpackage Metasync/custom-pages
 * @since      2.0.0
 */
class Metasync_HTML_To_Builder_Converter
{
	/**
	 * Common utilities instance
	 *
	 * @var Metasync_Common
	 */
	private $common;

	/**
	 * Plugin options
	 *
	 * @var array
	 */
	private $metasync_option_data;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		// Load common utilities
		if (!class_exists('Metasync_Common')) {
			require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-metasync-common.php';
		}
		$this->common = new Metasync_Common();

		// Load plugin options
		$this->metasync_option_data = get_option('metasync_option_data', array());
	}

	/**
	 * Main conversion entry point
	 *
	 * @param string $html HTML content to convert
	 * @param array $options Conversion options
	 *   - 'builder' (string|null): Target builder ('elementor', 'divi', 'gutenberg', or null for auto-detect)
	 *   - 'preserve_css' (bool): Whether to extract and apply CSS as inline styles (default: true)
	 *   - 'upload_images' (bool): Whether to upload images to media library (default: true)
	 *   - 'extract_header_footer' (bool): Whether to extract header/footer elements (default: true)
	 *
	 * @return array Conversion result with keys:
	 *   - 'builder' (string): Builder used ('elementor', 'divi', 'gutenberg')
	 *   - 'content' (string): Converted content
	 *   - 'meta_data' (array): Builder-specific meta data to save
	 *   - 'header' (string|null): Extracted header HTML
	 *   - 'footer' (string|null): Extracted footer HTML
	 *   - 'error' (string|null): Error message if conversion failed
	 */
	public function convert($html, $options = array())
	{
		// Set default options
		$defaults = array(
			'builder' => null, // Auto-detect
			'preserve_css' => true,
			'upload_images' => true,
			'extract_header_footer' => true,
		);
		$options = array_merge($defaults, $options);

		// Validate HTML
		if (empty($html) || !is_string($html)) {
			return array(
				'error' => 'Invalid HTML content provided',
			);
		}

		// Detect or validate builder
		$builder = $options['builder'];
		if (empty($builder) || $builder === 'auto') {
			$builder = $this->detect_active_builder();
		}

		// Validate builder
		$valid_builders = array('elementor', 'divi', 'gutenberg');
		if (!in_array($builder, $valid_builders)) {
			return array(
				'error' => "Invalid builder: {$builder}. Must be one of: " . implode(', ', $valid_builders),
			);
		}

		// Keep CSS in HTML for builder-specific extraction
		$html_with_css = $html;

		// Extract header and footer if requested
		$header = null;
		$footer = null;
		if ($options['extract_header_footer']) {
			$extraction = $this->extract_header_footer($html);
			$header = $extraction['header'];
			$footer = $extraction['footer'];
			$html = $extraction['body'];
			$html_with_css = $extraction['body']; // Update CSS version too
		}

		// Convert to builder format
		// Each builder handles CSS preservation differently:
		// - Gutenberg: Preserves <style> tag as Custom HTML block
		// - Elementor/Divi: Extracts body-level styles to section settings
		$result = null;
		switch ($builder) {
			case 'elementor':
				$result = $this->convert_to_elementor($html_with_css, $options);
				break;
			case 'divi':
				$result = $this->convert_to_divi($html_with_css, $options);
				break;
			case 'gutenberg':
				$result = $this->convert_to_gutenberg($html_with_css, $options);
				break;
		}

		// Add common metadata
		$result['builder'] = $builder;
		$result['header'] = $header;
		$result['footer'] = $footer;

		return $result;
	}

	/**
	 * Detect the active page builder
	 *
	 * Priority: Elementor > Divi > Gutenberg (default)
	 *
	 * @return string The detected builder ('elementor', 'divi', or 'gutenberg')
	 */
	public function detect_active_builder()
	{
		// Check for Elementor
		if (did_action('elementor/loaded') || class_exists('\Elementor\Plugin')) {
			return 'elementor';
		}

		// Check for Divi
		$theme = wp_get_theme();
		if ($theme->name == 'Divi' || $theme->get_template() == 'Divi' || function_exists('et_setup_theme')) {
			return 'divi';
		}

		// Default to Gutenberg
		return 'gutenberg';
	}

	/**
	 * Extract CSS from <style> tags
	 *
	 * @param string $html HTML content with <style> tags
	 * @return string All CSS from <style> tags concatenated
	 */
	public function extract_css_styles($html)
	{
		// Create DOMDocument
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);

		// Load HTML with proper encoding
		if (version_compare(PHP_VERSION, '8.2.0', '<')) {
			@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
		} else {
			$encoded_content = mb_encode_numericentity($html, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
			@$dom->loadHTML($encoded_content);
		}

		libxml_clear_errors();
		libxml_use_internal_errors(false);

		// Extract CSS from all <style> tags
		$all_css = '';
		$style_tags = $dom->getElementsByTagName('style');
		foreach ($style_tags as $tag) {
			$all_css .= $tag->textContent . "\n";
		}

		return trim($all_css);
	}

	/**
	 * Remove <style> tags from HTML
	 *
	 * @param string $html HTML content with <style> tags
	 * @return string HTML with <style> tags removed
	 */
	private function remove_style_tags($html)
	{
		// Create DOMDocument
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);

		// Load HTML with proper encoding
		if (version_compare(PHP_VERSION, '8.2.0', '<')) {
			@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
		} else {
			$encoded_content = mb_encode_numericentity($html, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
			@$dom->loadHTML($encoded_content);
		}

		libxml_clear_errors();
		libxml_use_internal_errors(false);

		// Remove <style> tags
		$style_tags = $dom->getElementsByTagName('style');
		$tags_to_remove = array();
		foreach ($style_tags as $tag) {
			$tags_to_remove[] = $tag;
		}
		foreach ($tags_to_remove as $tag) {
			if ($tag->parentNode) {
				$tag->parentNode->removeChild($tag);
			}
		}

		// Return modified HTML
		$output = $dom->saveHTML();

		// Clean up HTML wrapper tags
		$output = trim(str_replace([
			'<html>',
			'</html>',
			'<head>',
			'</head>',
			'<body>',
			'</body>'
		], '', $output));

		return $output;
	}

	/**
	 * Extract CSS from <style> tags and apply as inline styles
	 *
	 * @param string $html HTML content with <style> tags
	 * @return string HTML with CSS applied as inline styles and <style> tags removed
	 */
	public function extract_css_from_html($html)
	{
		// Create DOMDocument
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);

		// Load HTML with proper encoding
		if (version_compare(PHP_VERSION, '8.2.0', '<')) {
			@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
		} else {
			$encoded_content = mb_encode_numericentity($html, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
			@$dom->loadHTML($encoded_content);
		}

		libxml_clear_errors();
		libxml_use_internal_errors(false);

		// Extract CSS from <style> tags
		$css_rules = $this->parse_style_tags($dom);

		// Build CSS selector to styles map
		$css_map = $this->build_css_map($css_rules);

		// Apply CSS to elements as inline styles
		if (!empty($css_map)) {
			$this->apply_css_to_elements($dom, $css_map);
		}

		// Remove <style> tags
		$style_tags = $dom->getElementsByTagName('style');
		$tags_to_remove = array();
		foreach ($style_tags as $tag) {
			$tags_to_remove[] = $tag;
		}
		foreach ($tags_to_remove as $tag) {
			if ($tag->parentNode) {
				$tag->parentNode->removeChild($tag);
			}
		}

		// Return modified HTML
		$output = $dom->saveHTML();

		// Clean up HTML wrapper tags
		$output = trim(str_replace([
			'<html>',
			'</html>',
			'<head>',
			'</head>',
			'<body>',
			'</body>'
		], '', $output));

		return $output;
	}

	/**
	 * Parse <style> tags and extract CSS rules
	 *
	 * @param DOMDocument $dom Document to parse
	 * @return array Array of CSS rules (selector => declarations)
	 */
	private function parse_style_tags($dom)
	{
		$css_rules = array();
		$style_tags = $dom->getElementsByTagName('style');

		foreach ($style_tags as $style_tag) {
			$css = $style_tag->textContent;

			// Remove comments
			$css = preg_replace('/\/\*.*?\*\//s', '', $css);

			// Extract rules using regex: selector { declarations }
			preg_match_all('/([^{]+)\{([^}]+)\}/i', $css, $matches, PREG_SET_ORDER);

			foreach ($matches as $match) {
				$selectors = explode(',', $match[1]);
				$declarations = trim($match[2]);

				foreach ($selectors as $selector) {
					$selector = trim($selector);

					// Skip pseudo-selectors and at-rules (can't be inlined)
					if (strpos($selector, ':') !== false || strpos($selector, '@') === 0) {
						continue;
					}

					// Store or append declarations
					if (!isset($css_rules[$selector])) {
						$css_rules[$selector] = $declarations;
					} else {
						$css_rules[$selector] .= '; ' . $declarations;
					}
				}
			}
		}

		return $css_rules;
	}

	/**
	 * Build a map of CSS selectors to style declarations
	 *
	 * @param array $css_rules CSS rules (selector => declarations)
	 * @return array Processed CSS map
	 */
	private function build_css_map($css_rules)
	{
		// Already in the right format from parse_style_tags
		return $css_rules;
	}

	/**
	 * Extract body-level CSS styles for applying to builder sections
	 *
	 * @param array $css_rules All CSS rules from parse_style_tags
	 * @return array Body-level styles (background, colors, layout properties)
	 */
	private function extract_body_level_styles($css_rules)
	{
		$body_styles = array();

		// Check for body and container-level selectors
		$container_selectors = array('body', '.container', 'html', '[class*="container"]');

		foreach ($container_selectors as $selector) {
			if (isset($css_rules[$selector])) {
				$body_styles = array_merge($body_styles, $this->parse_css_declarations($css_rules[$selector]));
			}
		}

		return $body_styles;
	}

	/**
	 * Parse CSS declaration string into key-value pairs
	 *
	 * @param string $declarations CSS declarations (e.g., "color: red; background: blue;")
	 * @return array Parsed properties
	 */
	private function parse_css_declarations($declarations)
	{
		$properties = array();
		$declarations = trim($declarations);

		// Split by semicolon
		$parts = explode(';', $declarations);

		foreach ($parts as $part) {
			$part = trim($part);
			if (empty($part)) {
				continue;
			}

			// Split by colon
			$prop_parts = explode(':', $part, 2);
			if (count($prop_parts) === 2) {
				$property = trim($prop_parts[0]);
				$value = trim($prop_parts[1]);
				$properties[$property] = $value;
			}
		}

		return $properties;
	}

	/**
	 * Convert body-level styles to Elementor section settings
	 *
	 * @param array $body_styles Body-level CSS properties
	 * @return array Elementor settings
	 */
	private function body_styles_to_elementor_settings($body_styles)
	{
		$settings = array();

		// Background color or gradient
		if (isset($body_styles['background'])) {
			$bg = $body_styles['background'];

			// Check if it's a gradient
			if (strpos($bg, 'linear-gradient') !== false || strpos($bg, 'gradient') !== false) {
				$settings['background_background'] = 'gradient';
				$gradient_data = $this->parse_gradient_for_elementor($bg);
				$settings = array_merge($settings, $gradient_data);
			}
			// Solid color
			elseif (strpos($bg, '#') !== false || strpos($bg, 'rgb') !== false) {
				$settings['background_background'] = 'classic';
				$settings['background_color'] = $this->normalize_color($bg);
			}
		}

		// Background color (separate property)
		if (isset($body_styles['background-color'])) {
			$settings['background_background'] = 'classic';
			$settings['background_color'] = $this->normalize_color($body_styles['background-color']);
		}

		// Text color
		if (isset($body_styles['color'])) {
			$settings['color_text'] = $this->normalize_color($body_styles['color']);
		}

		// Min height
		if (isset($body_styles['min-height'])) {
			$settings['min_height'] = array('size' => $this->normalize_size($body_styles['min-height']));
		}

		// Padding
		foreach (array('padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left') as $prop) {
			if (isset($body_styles[$prop])) {
				$key = 'padding';
				if ($prop !== 'padding') {
					$key = str_replace('padding-', 'padding_', $prop);
				}
				$settings[$key] = array('size' => $this->normalize_size($body_styles[$prop]));
			}
		}

		// Flexbox properties (for centering)
		if (isset($body_styles['display']) && $body_styles['display'] === 'flex') {
			if (isset($body_styles['align-items']) && $body_styles['align-items'] === 'center') {
				$settings['content_position'] = 'middle';
			}
			if (isset($body_styles['justify-content']) && $body_styles['justify-content'] === 'center') {
				$settings['content_position'] = 'center';
			}
		}

		return $settings;
	}

	/**
	 * Parse CSS gradient for Elementor format
	 *
	 * @param string $gradient CSS gradient string
	 * @return array Elementor gradient settings
	 */
	private function parse_gradient_for_elementor($gradient)
	{
		$settings = array();

		// Extract gradient type
		if (strpos($gradient, 'linear-gradient') !== false) {
			$settings['background_gradient_type'] = 'linear';

			// Extract angle
			preg_match('/linear-gradient\((\d+)deg/', $gradient, $angle_match);
			if (isset($angle_match[1])) {
				$settings['background_gradient_angle'] = array('size' => $angle_match[1]);
			} else {
				$settings['background_gradient_angle'] = array('size' => 135); // Default
			}

			// Extract colors and positions
			preg_match_all('/(#[0-9A-Fa-f]{3,6}|rgba?\([^)]+\))\s*(\d+%)?/', $gradient, $color_matches, PREG_SET_ORDER);

			if (count($color_matches) >= 2) {
				// First color (start)
				$settings['background_gradient_color'] = $this->normalize_color($color_matches[0][1]);
				if (isset($color_matches[0][2])) {
					$settings['background_gradient_color_stop'] = array('size' => intval($color_matches[0][2]));
				}

				// Second color (end)
				$settings['background_gradient_color_b'] = $this->normalize_color($color_matches[1][1]);
				if (isset($color_matches[1][2])) {
					$settings['background_gradient_color_b_stop'] = array('size' => intval($color_matches[1][2]));
				}
			}
		}

		return $settings;
	}

	/**
	 * Normalize color value (convert to hex if needed)
	 *
	 * @param string $color CSS color value
	 * @return string Normalized color
	 */
	private function normalize_color($color)
	{
		$color = trim($color);

		// Already hex
		if (strpos($color, '#') === 0) {
			return $color;
		}

		// RGB/RGBA - try to convert
		if (strpos($color, 'rgb') === 0) {
			// For simplicity, return as-is (Elementor supports rgba)
			return $color;
		}

		// Named colors - return as-is
		return $color;
	}

	/**
	 * Normalize size value (extract numeric value)
	 *
	 * @param string $size CSS size value (e.g., "100vh", "50px")
	 * @return int Numeric size
	 */
	private function normalize_size($size)
	{
		// Extract numeric value
		preg_match('/(\d+)/', $size, $matches);
		return isset($matches[1]) ? intval($matches[1]) : 0;
	}

	/**
	 * Convert body-level styles to Divi section attributes
	 *
	 * @param array $body_styles Body-level CSS properties
	 * @return string Divi section attributes
	 */
	private function body_styles_to_divi_attributes($body_styles)
	{
		$attributes = array();

		// Background color or gradient
		if (isset($body_styles['background'])) {
			$bg = $body_styles['background'];

			// Check if it's a gradient
			if (strpos($bg, 'linear-gradient') !== false || strpos($bg, 'gradient') !== false) {
				$gradient_data = $this->parse_gradient_for_divi($bg);
				$attributes[] = $gradient_data;
			}
			// Solid color
			elseif (strpos($bg, '#') !== false || strpos($bg, 'rgb') !== false) {
				$color = $this->normalize_color($bg);
				$attributes[] = 'background_color="' . esc_attr($color) . '"';
			}
		}

		// Background color (separate property)
		if (isset($body_styles['background-color'])) {
			$color = $this->normalize_color($body_styles['background-color']);
			$attributes[] = 'background_color="' . esc_attr($color) . '"';
		}

		// Min height
		if (isset($body_styles['min-height'])) {
			$min_height = $this->normalize_size($body_styles['min-height']);
			$attributes[] = 'min_height="' . $min_height . 'vh"';
		}

		// Padding
		if (isset($body_styles['padding'])) {
			$padding = $this->normalize_size($body_styles['padding']);
			$attributes[] = 'padding_top="' . $padding . 'px"';
			$attributes[] = 'padding_bottom="' . $padding . 'px"';
			$attributes[] = 'padding_left="' . $padding . 'px"';
			$attributes[] = 'padding_right="' . $padding . 'px"';
		}

		// Text color
		if (isset($body_styles['color'])) {
			$color = $this->normalize_color($body_styles['color']);
			// Apply via custom CSS
			$custom_css = 'background-color: transparent; color: ' . $color . ';';
			$attributes[] = 'custom_css_main_element="' . esc_attr($custom_css) . '"';
		}

		return implode(' ', $attributes);
	}

	/**
	 * Parse CSS gradient for Divi format
	 *
	 * @param string $gradient CSS gradient string
	 * @return string Divi gradient attributes
	 */
	private function parse_gradient_for_divi($gradient)
	{
		$attributes = '';

		// Extract colors and positions
		preg_match_all('/(#[0-9A-Fa-f]{3,6}|rgba?\([^)]+\))\s*(\d+%)?/', $gradient, $color_matches, PREG_SET_ORDER);

		if (count($color_matches) >= 2) {
			$color1 = $this->normalize_color($color_matches[0][1]);
			$color2 = $this->normalize_color($color_matches[1][1]);

			// Extract angle
			preg_match('/(\d+)deg/', $gradient, $angle_match);
			$angle = isset($angle_match[1]) ? $angle_match[1] : 135;

			// Convert angle to Divi direction
			$direction = $this->angle_to_divi_direction($angle);

			$attributes = sprintf(
				'use_background_color_gradient="on" background_color_gradient_start="%s" background_color_gradient_end="%s" background_color_gradient_direction="%s"',
				esc_attr($color1),
				esc_attr($color2),
				esc_attr($direction)
			);
		}

		return $attributes;
	}

	/**
	 * Convert CSS angle to Divi gradient direction
	 *
	 * @param int $angle CSS angle in degrees
	 * @return string Divi direction value
	 */
	private function angle_to_divi_direction($angle)
	{
		// Divi uses: 0deg, 90deg, 180deg, 270deg, or diagonal directions
		if ($angle >= 0 && $angle < 45) {
			return '180deg'; // Bottom to top
		} elseif ($angle >= 45 && $angle < 135) {
			return '90deg'; // Left to right
		} elseif ($angle >= 135 && $angle < 225) {
			return '0deg'; // Top to bottom
		} elseif ($angle >= 225 && $angle < 315) {
			return '270deg'; // Right to left
		} else {
			return '180deg'; // Default
		}
	}

	/**
	 * Extract inline styles from DOM elements and convert to CSS rules
	 *
	 * This method scans all elements with inline style attributes, extracts those styles,
	 * generates unique class names, and returns CSS rules that can be added to the page.
	 * It also adds the generated class names to the elements.
	 *
	 * @param DOMDocument $dom DOM document
	 * @return string CSS rules generated from inline styles
	 */
	private function extract_inline_styles_to_css($dom)
	{
		$css_rules = '';
		$style_counter = 0;
		$xpath = new DOMXPath($dom);

		// Find all elements with style attributes (excluding body, html, head)
		$styled_elements = $xpath->query('//*[@style][not(self::body or self::html or self::head)]');

		if ($styled_elements === false || $styled_elements->length === 0) {
			return '';
		}

		foreach ($styled_elements as $element) {
			$inline_style = $element->getAttribute('style');

			if (empty(trim($inline_style))) {
				continue;
			}

			// Generate unique class name
			$style_counter++;
			$unique_class = 'metasync-inline-' . $style_counter;

			// Add class to element
			$existing_class = $element->getAttribute('class');
			if (!empty($existing_class)) {
				$element->setAttribute('class', $existing_class . ' ' . $unique_class);
			} else {
				$element->setAttribute('class', $unique_class);
			}

			// Create CSS rule
			$css_rules .= '.' . $unique_class . ' { ' . trim($inline_style) . " }\n";

			// Remove inline style attribute (styles now in CSS)
			$element->removeAttribute('style');
		}

		return $css_rules;
	}

	/**
	 * Apply CSS to DOM elements as inline styles
	 *
	 * @param DOMDocument $dom Document to modify
	 * @param array $css_map CSS selector to declarations map
	 */
	private function apply_css_to_elements($dom, $css_map)
	{
		foreach ($css_map as $selector => $declarations) {
			// Convert CSS selector to XPath query
			$xpath_query = $this->css_selector_to_xpath($selector);

			if (empty($xpath_query)) {
				continue;
			}

			// Find elements matching the selector
			$xpath = new DOMXPath($dom);
			$elements = @$xpath->query($xpath_query);

			if ($elements === false || $elements->length === 0) {
				continue;
			}

			// Apply styles to each matching element
			foreach ($elements as $element) {
				$existing_style = $element->getAttribute('style');

				// Merge with existing styles
				if (!empty($existing_style)) {
					// Ensure existing style ends with semicolon
					$existing_style = rtrim($existing_style, '; ') . '; ';
				}

				$new_style = $existing_style . $declarations;
				$element->setAttribute('style', $new_style);
			}
		}
	}

	/**
	 * Convert CSS selector to XPath query
	 *
	 * Supports basic selectors: tag, .class, #id, tag.class, tag#id
	 * Does not support complex selectors (descendants, children, etc.)
	 *
	 * @param string $selector CSS selector
	 * @return string|null XPath query or null if unsupported
	 */
	private function css_selector_to_xpath($selector)
	{
		$selector = trim($selector);

		// Universal selector
		if ($selector === '*') {
			return '//*';
		}

		// ID selector (#id)
		if (preg_match('/^#([\w-]+)$/', $selector, $matches)) {
			return "//*[@id='{$matches[1]}']";
		}

		// Class selector (.class)
		if (preg_match('/^\.([\w-]+)$/', $selector, $matches)) {
			return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$matches[1]} ')]";
		}

		// Tag selector (tag)
		if (preg_match('/^([\w-]+)$/', $selector, $matches)) {
			return "//{$matches[1]}";
		}

		// Tag with class (tag.class)
		if (preg_match('/^([\w-]+)\.([\w-]+)$/', $selector, $matches)) {
			$tag = $matches[1];
			$class = $matches[2];
			return "//{$tag}[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
		}

		// Tag with ID (tag#id)
		if (preg_match('/^([\w-]+)#([\w-]+)$/', $selector, $matches)) {
			$tag = $matches[1];
			$id = $matches[2];
			return "//{$tag}[@id='{$id}']";
		}

		// Descendant selector (simplified - only direct selectors)
		if (strpos($selector, ' ') !== false) {
			$parts = explode(' ', $selector);
			$parts = array_map('trim', $parts);
			$parts = array_filter($parts);

			$xpath_parts = array();
			foreach ($parts as $part) {
				$part_xpath = $this->css_selector_to_xpath($part);
				if ($part_xpath) {
					// Remove leading // for parts after the first
					$part_xpath = ltrim($part_xpath, '/');
					$xpath_parts[] = $part_xpath;
				}
			}

			if (!empty($xpath_parts)) {
				return '//' . implode('//', $xpath_parts);
			}
		}

		// Unsupported selector
		return null;
	}

	/**
	 * Extract header and footer from HTML
	 *
	 * @param string $html HTML content
	 * @return array Array with keys: 'header', 'footer', 'body'
	 */
	private function extract_header_footer($html)
	{
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);

		if (version_compare(PHP_VERSION, '8.2.0', '<')) {
			@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
		} else {
			$encoded_content = mb_encode_numericentity($html, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
			@$dom->loadHTML($encoded_content);
		}

		libxml_clear_errors();
		libxml_use_internal_errors(false);

		$xpath = new DOMXPath($dom);

		// Extract header
		$header = null;
		$header_queries = array(
			"//header",
			"//*[contains(@class, 'header')]",
			"//nav[contains(@class, 'nav')]",
		);

		foreach ($header_queries as $query) {
			$nodes = $xpath->query($query);
			if ($nodes && $nodes->length > 0) {
				$header = $dom->saveHTML($nodes->item(0));
				// Remove from main document
				$nodes->item(0)->parentNode->removeChild($nodes->item(0));
				break;
			}
		}

		// Extract footer
		$footer = null;
		$footer_queries = array(
			"//footer",
			"//*[contains(@class, 'footer')]",
		);

		foreach ($footer_queries as $query) {
			$nodes = $xpath->query($query);
			if ($nodes && $nodes->length > 0) {
				$footer = $dom->saveHTML($nodes->item(0));
				// Remove from main document
				$nodes->item(0)->parentNode->removeChild($nodes->item(0));
				break;
			}
		}

		// Get remaining body content
		$body = $dom->saveHTML();
		$body = trim(str_replace([
			'<html>',
			'</html>',
			'<head>',
			'</head>',
			'<body>',
			'</body>'
		], '', $body));

		return array(
			'header' => $header,
			'footer' => $footer,
			'body' => $body,
		);
	}

	/**
	 * Convert HTML to Elementor format
	 *
	 * @param string $html HTML content
	 * @param array $options Conversion options
	 * @return array Result with 'content' and 'meta_data' keys
	 */
	private function convert_to_elementor($html, $options)
	{
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);

		if (version_compare(PHP_VERSION, '8.2.0', '<')) {
			@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
		} else {
			$encoded_content = mb_encode_numericentity($html, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
			@$dom->loadHTML($encoded_content);
		}

		libxml_clear_errors();
		libxml_use_internal_errors(false);

		// Extract inline styles and convert to CSS rules FIRST
		$inline_styles_css = '';
		if ($options['preserve_css']) {
			$inline_styles_css = $this->extract_inline_styles_to_css($dom);
		}

		// Extract CSS rules before processing
		$css_rules = $this->parse_style_tags($dom);
		$body_styles = $this->extract_body_level_styles($css_rules);
		$elementor_settings = $this->body_styles_to_elementor_settings($body_styles);

		// Extract CSS content for preservation
		$css_content = '';
		if ($options['preserve_css']) {
			$style_tags = $dom->getElementsByTagName('style');
			foreach ($style_tags as $tag) {
				$css_content .= $tag->textContent . "\n";
			}
			// Add inline styles CSS
			if (!empty($inline_styles_css)) {
				$css_content .= "\n/* Inline styles converted to CSS */\n" . $inline_styles_css;
			}
			$css_content = trim($css_content);
		}

		// Apply inline CSS to elements (excluding body)
		$css_map = $this->build_css_map($css_rules);
		if (!empty($css_map) && $options['preserve_css']) {
			// Remove body-level selectors from inline application
			unset($css_map['body'], $css_map['.container'], $css_map['html']);
			$this->apply_css_to_elements($dom, $css_map);
		}

		// Remove style tags
		$style_tags = $dom->getElementsByTagName('style');
		$tags_to_remove = array();
		foreach ($style_tags as $tag) {
			$tags_to_remove[] = $tag;
		}
		foreach ($tags_to_remove as $tag) {
			if ($tag->parentNode) {
				$tag->parentNode->removeChild($tag);
			}
		}

		$modified_html = $dom->saveHTML();
		$elements = $this->parse_html_to_elementor(html_entity_decode($modified_html), $options);

		// Add Custom HTML widget at the beginning with CSS if preserve_css is enabled
		if ($options['preserve_css'] && !empty($css_content)) {
			$style_widget = array(
				'id' => uniqid(),
				'elType' => 'widget',
				'widgetType' => 'html',
				'settings' => array(
					'html' => '<style>' . $css_content . '</style>'
				)
			);
			// Prepend to elements array
			array_unshift($elements, $style_widget);
		}

		// Check if container feature is active
		$use_container = false;
		if (class_exists('\Elementor\Plugin')) {
			$use_container = \Elementor\Plugin::$instance->experiments->is_feature_active('container');
		}

		// Build Elementor data structure with body-level styles applied
		if ($use_container) {
			$container_settings = array_merge(
				array(
					'flex_direction' => 'column',
					'presetTitle' => 'Container',
					'presetIcon' => 'eicon-container'
				),
				$elementor_settings
			);

			$output_data = array(
				array(
					'id' => uniqid(),
					'elType' => 'container',
					'settings' => $container_settings,
					'elements' => $elements,
					'isInner' => false
				)
			);
		} else {
			$section_settings = array_merge(array(), $elementor_settings);

			$output_data = array(
				array(
					'id' => uniqid(),
					'elType' => 'section',
					'settings' => $section_settings,
					'elements' => array(
						array(
							'id' => uniqid(),
							'elType' => 'column',
							'settings' => array(
								'_column_size' => 100,
								'_inline_size' => null
							),
							'elements' => $elements,
							'isInner' => false
						)
					),
					'isInner' => false
				)
			);
		}

		$json_output = wp_slash(wp_json_encode($output_data));

		// Clean HTML for content field
		$content = trim(str_replace([
			'<html>',
			'</html>',
			'<head>',
			'</head>',
			'<body>',
			'</body>'
		], '', $modified_html));

		return array(
			'content' => $content,
			'meta_data' => array(
				'_elementor_data' => $json_output,
				'_elementor_edit_mode' => 'builder',
				'_elementor_version' => defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.0.0',
				'_elementor_page_settings' => array(
					'template' => 'elementor_canvas', // Full-width, no header/footer
					'custom_css' => ''
				),
				'_wp_page_template' => 'elementor_canvas' // WordPress page template
			),
			'css_content' => $css_content
		);
	}

	/**
	 * Parse HTML to Elementor block data
	 *
	 * @param string $content HTML content
	 * @param array $options Conversion options
	 * @return array Elementor elements array
	 */
	private function parse_html_to_elementor($content, $options)
	{
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);

		if (version_compare(PHP_VERSION, '8.2.0', '<')) {
			@$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
		} else {
			$encoded_content = mb_encode_numericentity($content, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
			@$dom->loadHTML($encoded_content);
		}

		libxml_clear_errors();
		libxml_use_internal_errors(false);

		$output_array = array();
		foreach ($dom->getElementsByTagName('*') as $root_element) {
			if (!in_array($root_element->nodeName, array('html', 'body', 'tbody', 'tfoot', 'tr', 'th', 'td'))) {
				$html_array = $this->convert_element_to_elementor($root_element, $options);
				if (!empty($html_array)) {
					$output_array[] = $html_array;
				}
			}
		}

		return $output_array;
	}

	/**
	 * Convert HTML element to Elementor widget
	 *
	 * @param DOMNode $node DOM node to convert
	 * @param array $options Conversion options
	 * @return array|string|null Elementor widget data or null
	 */
	private function convert_element_to_elementor($node, $options)
	{
		if ($node->nodeType === XML_TEXT_NODE) {
			return $node->nodeValue;
		}

		$result = array();
		$result['id'] = uniqid();
		$result['elType'] = 'widget';

		$node_name = strtolower($node->nodeName);

		// Handle headings (h1-h6)
		if (in_array($node_name, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'))) {
			$result['settings']['title'] = $node->nodeValue;
			$result['settings']['header_size'] = $node->nodeName;

			// Preserve element ID
			$existing_id = $node->getAttribute('id');
			if (!empty($existing_id)) {
				$result['settings']['_element_id'] = $existing_id;
			}

			// Apply custom color if configured
			if (isset($this->metasync_option_data['enabled_elementor_plugin_css']) &&
			    isset($this->metasync_option_data['enabled_elementor_plugin_css_color']) &&
			    $this->metasync_option_data['enabled_elementor_plugin_css'] !== "default") {
				$result['settings']['title_color'] = $this->metasync_option_data['enabled_elementor_plugin_css_color'];
			}

			$result['settings']['typography_typography'] = 'custom';
			$result['settings']['typography_font_family'] = 'Roboto';
			$result['settings']['typography_font_weight'] = '600';
			$result['widgetType'] = 'heading';
		}
		// Handle iframes
		elseif ($node_name === 'iframe') {
			$result['settings'] = array('html' => $node->ownerDocument->saveHTML($node));
			$result['widgetType'] = 'html';
		}
		// Handle images
		elseif ($node_name === 'img') {
			$src_url = $node->getAttribute('src');
			$alt_text = $node->getAttribute('alt');
			$title_text = $node->getAttribute('title');

			// Upload image if option enabled
			if ($options['upload_images']) {
				$attachment_id = $this->common->upload_image_by_url($src_url, $alt_text, $title_text);
				$new_src_url = wp_get_attachment_url($attachment_id);

				$result['settings']['image']['url'] = $new_src_url;
				$result['settings']['image']['id'] = $attachment_id;
			} else {
				$result['settings']['image']['url'] = $src_url;
				$result['settings']['image']['id'] = 0;
			}

			$result['settings']['image']['size'] = '';
			$result['settings']['image']['alt'] = $alt_text;
			$result['settings']['image']['source'] = 'library';

			if ($title_text !== "") {
				$result['settings']['image']['title'] = $title_text;
			}

			$result['widgetType'] = 'image';
		}
		// Handle paragraphs
		elseif ($node_name === 'p') {
			$node->setAttribute('class', 'metasyncPara');
			$result['settings'] = array('editor' => $node->ownerDocument->saveHTML($node));
			$result['elements'] = array();
			$result['widgetType'] = 'text-editor';
		}
		// Handle tables, lists
		elseif (in_array($node_name, array('table', 'ul', 'ol'))) {
			if ($node_name === 'table') {
				$node->setAttribute('class', 'metasyncTable');
			}
			$result['settings'] = array('editor' => $node->ownerDocument->saveHTML($node));
			$result['elements'] = array();
			$result['widgetType'] = 'text-editor';
		}
		// Handle blockquotes
		elseif ($node_name === 'blockquote') {
			$node->setAttribute('class', 'metasyncBlockquote');
			$html = $node->ownerDocument->saveHTML($node);
			$result['settings'] = array('editor' => $html);
			$result['elements'] = array();
			$result['widgetType'] = 'text-editor';
		}

		if (isset($result['widgetType'])) {
			// Preserve CSS classes from original HTML element
			if ($node->hasAttribute('class')) {
				$css_classes = $node->getAttribute('class');
				if (!empty(trim($css_classes))) {
					$result['settings']['_css_classes'] = trim($css_classes);
				}
			}

			return $result;
		}

		return null;
	}

	/**
	 * Convert HTML to Divi format
	 *
	 * @param string $html HTML content
	 * @param array $options Conversion options
	 * @return array Result with 'content' and 'meta_data' keys
	 */
	private function convert_to_divi($html, $options)
	{
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);

		if (version_compare(PHP_VERSION, '8.2.0', '<')) {
			@$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
		} else {
			$encoded_content = mb_encode_numericentity($html, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
			@$dom->loadHTML($encoded_content);
		}

		libxml_clear_errors();
		libxml_use_internal_errors(false);

		// Extract inline styles and convert to CSS rules FIRST
		$inline_styles_css = '';
		if ($options['preserve_css']) {
			$inline_styles_css = $this->extract_inline_styles_to_css($dom);
		}

		// Extract CSS rules before processing
		$css_rules = $this->parse_style_tags($dom);

		// Extract CSS content for preservation
		$css_content = '';
		if ($options['preserve_css']) {
			$style_tags = $dom->getElementsByTagName('style');
			foreach ($style_tags as $tag) {
				$css_content .= $tag->textContent . "\n";
			}
			// Add inline styles CSS
			if (!empty($inline_styles_css)) {
				$css_content .= "\n/* Inline styles converted to CSS */\n" . $inline_styles_css;
			}
			$css_content = trim($css_content);
		}

		// Apply inline CSS to elements (excluding body)
		$css_map = $this->build_css_map($css_rules);
		if (!empty($css_map) && $options['preserve_css']) {
			// Remove body-level selectors from inline application
			unset($css_map['body'], $css_map['.container'], $css_map['html']);
			$this->apply_css_to_elements($dom, $css_map);
		}

		// Remove style tags
		$style_tags = $dom->getElementsByTagName('style');
		$tags_to_remove = array();
		foreach ($style_tags as $tag) {
			$tags_to_remove[] = $tag;
		}
		foreach ($tags_to_remove as $tag) {
			if ($tag->parentNode) {
				$tag->parentNode->removeChild($tag);
			}
		}

		$modified_html = $dom->saveHTML();
		$divi_content = $this->parse_html_to_divi(html_entity_decode($modified_html), $options, $css_rules);

		// Prepend CSS as Code module if preserve_css is enabled
		if ($options['preserve_css'] && !empty($css_content)) {
			// Don't escape CSS content - it needs to remain valid CSS
			// The et_pb_code module handles output escaping internally
			$css_module = '[et_pb_code]<style>' . $css_content . '</style>[/et_pb_code]';
			$divi_content = $css_module . $divi_content;
		}

		return array(
			'content' => $divi_content,
			'meta_data' => array(
				'_et_pb_use_builder' => 'on',
				'_et_builder_version' => defined('ET_BUILDER_VERSION') ? ET_BUILDER_VERSION : '4.0.0',
				'_et_pb_page_layout' => 'et_no_sidebar', // Full width, no sidebar
				'_et_pb_side_nav' => 'off', // Disable side navigation
				'_et_pb_post_hide_nav' => 'on', // Hide post navigation
				'_wp_page_template' => 'page-template-blank.php' // Blank template (no header/footer)
			),
			'css_content' => $css_content
		);
	}

	/**
	 * Parse HTML to Divi shortcode format
	 *
	 * @param string $content HTML content
	 * @param array $options Conversion options
	 * @param array $css_rules CSS rules extracted from style tags
	 * @return string Divi shortcode content
	 */
	private function parse_html_to_divi($content, $options, $css_rules = array())
	{
		$dom = new DOMDocument();
		libxml_use_internal_errors(true);

		if (version_compare(PHP_VERSION, '8.2.0', '<')) {
			@$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
		} else {
			$encoded_content = mb_encode_numericentity($content, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
			@$dom->loadHTML($encoded_content);
		}

		libxml_clear_errors();
		libxml_use_internal_errors(false);

		// Extract body-level styles and convert to Divi attributes
		$body_styles = $this->extract_body_level_styles($css_rules);
		$section_attributes = $this->body_styles_to_divi_attributes($body_styles);

		$builder_version = defined('ET_BUILDER_VERSION') ? ET_BUILDER_VERSION : '4.0.0';
		$output_array = '[et_pb_section fb_built="1" _builder_version="' . $builder_version . '" _module_preset="default" global_colors_info="{}" ' . $section_attributes . '][et_pb_row _builder_version="' . $builder_version . '" _module_preset="default" global_colors_info="{}"][et_pb_column type="4_4" _builder_version="' . $builder_version . '" _module_preset="default" global_colors_info="{}"]';

		foreach ($dom->getElementsByTagName('*') as $root_element) {
			if (!in_array($root_element->nodeName, array('html', 'body', 'tbody', 'tfoot', 'tr', 'th', 'td'))) {
				$html_array = $this->convert_element_to_divi($root_element, $options);
				if (!is_array($html_array)) {
					$output_array .= $html_array;
				}
			}
		}

		$output_array .= '[/et_pb_column][/et_pb_row][/et_pb_section]';
		return $output_array;
	}

	/**
	 * Convert HTML element to Divi module
	 *
	 * @param DOMNode $node DOM node to convert
	 * @param array $options Conversion options
	 * @return string|array Divi shortcode or empty array
	 */
	private function convert_element_to_divi($node, $options)
	{
		if ($node->nodeType === XML_TEXT_NODE) {
			return $node->nodeValue;
		}

		$result = '';
		$node_name = strtolower($node->nodeName);
		$builder_version = defined('ET_BUILDER_VERSION') ? ET_BUILDER_VERSION : '4.0.0';

		// Handle headings (h1-h6)
		if (in_array($node_name, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'))) {
			$existing_id = $node->getAttribute('id');
			$extra_id_attr = !empty($existing_id) ? ' module_id="' . $existing_id . '"' : '';
			$result = ' [et_pb_heading title="' . $node->nodeValue . '" _builder_version="' . $builder_version . '" _module_preset="default" title_level="' . $node->nodeName . '" hover_enabled="0" sticky_enabled="0"' . $extra_id_attr . '][/et_pb_heading]';
		}
		// Handle images
		elseif ($node_name === 'img') {
			$src_url = $node->getAttribute('src');

			// Upload image if option enabled
			if ($options['upload_images']) {
				$alt_text = $node->getAttribute('alt');
				$title_text = $node->getAttribute('title');
				$attachment_id = $this->common->upload_image_by_url($src_url, $alt_text, $title_text);
				$new_src_url = wp_get_attachment_url($attachment_id);
				$src_url = $new_src_url ? $new_src_url : $src_url;
			}

			$result = '[et_pb_image src="' . $src_url . '" url="' . $src_url . '" _builder_version="' . $builder_version . '" _module_preset="default" hover_enabled="0" global_colors_info="{}" sticky_enabled="0"][/et_pb_image]';
		}
		// Handle iframes
		elseif ($node_name === 'iframe') {
			$result = '[et_pb_code _builder_version="' . $builder_version . '" _module_preset="default" global_colors_info="{}"]' . $node->ownerDocument->saveHTML($node) . '[/et_pb_code]';
		}
		// Handle paragraphs
		elseif ($node_name === 'p') {
			$node->setAttribute('class', 'metasyncPara');
			$result = '[et_pb_text _builder_version="' . $builder_version . '" _module_preset="default" global_colors_info="{}"]' . $node->ownerDocument->saveHTML($node) . '[/et_pb_text]';
		}
		// Handle tables, lists
		elseif (in_array($node_name, array('table', 'ul', 'ol'))) {
			if ($node_name === 'table') {
				$node->setAttribute('class', 'metasyncTable');
			}
			$result = '[et_pb_code _builder_version="' . $builder_version . '" _module_preset="default" global_colors_info="{}"]' . $node->ownerDocument->saveHTML($node) . '[/et_pb_code]';
		}
		// Handle blockquotes
		elseif ($node_name === 'blockquote') {
			$node->setAttribute('class', 'metasyncQuote');
			$quote_html = $node->ownerDocument->saveHTML($node);
			$result = '[et_pb_text _builder_version="' . $builder_version . '" _module_preset="default" global_colors_info="{}"]' . $quote_html . '[/et_pb_text]';
		}

		return $result;
	}

	/**
	 * Convert HTML to Gutenberg format
	 *
	 * @param string $html HTML content
	 * @param array $options Conversion options
	 * @return array Result with 'content' key
	 */
	private function convert_to_gutenberg($html, $options)
	{
		$blocks = array();
		$css_content = '';

		// If CSS preservation is enabled, extract <style> tags and add as Custom HTML block
		if ($options['preserve_css']) {
			$css = $this->extract_css_styles($html);
			if (!empty($css)) {
				$css_content = $css;
				// Add Custom HTML block with <style> tag at the beginning
				$blocks[] = array(
					'blockName' => 'core/html',
					'attrs' => array(),
					'innerBlocks' => array(),
					'innerHTML' => '<style>' . $css . '</style>',
					'innerContent' => array('<style>' . $css . '</style>')
				);
			}

			// Remove <style> tags from HTML before parsing to blocks
			$html = $this->remove_style_tags($html);
		}

		// Parse HTML to Gutenberg blocks
		$content_blocks = $this->parse_html_to_gutenberg($html, $options);
		$blocks = array_merge($blocks, $content_blocks);

		$serialized_blocks = serialize_blocks($blocks);

		return array(
			'content' => $serialized_blocks,
			'meta_data' => array(), // Gutenberg doesn't need special meta
			'css_content' => $css_content
		);
	}

	/**
	 * Parse HTML to Gutenberg blocks
	 *
	 * @param string $content HTML content
	 * @param array $options Conversion options
	 * @return array Gutenberg blocks array
	 */
	private function parse_html_to_gutenberg($content, $options)
	{
		// Validate content
		if (empty($content) || !is_string($content)) {
			return array();
		}

		$content = trim($content);
		if (empty($content)) {
			return array();
		}

		// Clean content
		$content = preg_replace('/^[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $content);
		$content = preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+$/u', '', $content);

		// Load HTML
		$dom = null;
		if (class_exists('Dom\HTMLDocument')) {
			$wrapped_content = trim($content);
			$is_complete = (stripos($wrapped_content, '<!DOCTYPE') === 0) || (stripos($wrapped_content, '<html') === 0);

			if (!$is_complete) {
				$wrapped_content = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $wrapped_content . '</body></html>';
			}

			try {
				$dom = @Dom\HTMLDocument::createFromString($wrapped_content);
				if ($dom === null) {
					throw new Exception('Dom\HTMLDocument::createFromString returned null');
				}
			} catch (Throwable $e) {
				// Fallback to DOMDocument
				$dom = new DOMDocument();
				libxml_use_internal_errors(true);
				$encoded_content = mb_encode_numericentity($content, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
				@$dom->loadHTML($encoded_content);
				libxml_clear_errors();
				libxml_use_internal_errors(false);
			}
		} else {
			// Fallback for older PHP
			$dom = new DOMDocument();
			libxml_use_internal_errors(true);

			$html_content = $content;
			if (!preg_match('/^\s*<(!DOCTYPE|html|body)/i', $content)) {
				$html_content = '<!DOCTYPE html><html><body>' . $content . '</body></html>';
			}

			if (version_compare(PHP_VERSION, '8.2.0', '<')) {
				$dom->loadHTML(mb_convert_encoding($html_content, 'HTML-ENTITIES', 'UTF-8'));
			} else {
				$encoded_content = mb_encode_numericentity($html_content, [0x80, 0xFFFF, 0, 0xFFFF], 'UTF-8');
				$dom->loadHTML($encoded_content);
			}

			libxml_clear_errors();
			libxml_use_internal_errors(false);
		}

		$output_array = array();

		foreach ($dom->getElementsByTagName('*') as $root_element) {
			if (!in_array($root_element->nodeName, array('html', 'body', 'tr', 'th', 'td'))) {
				$html_array = $this->convert_element_to_gutenberg($root_element, $options);
				if (!is_null($html_array)) {
					$output_array[] = $html_array;
				}
			}
		}

		return $output_array;
	}

	/**
	 * Convert HTML element to Gutenberg block
	 *
	 * @param DOMNode $node DOM node to convert
	 * @param array $options Conversion options
	 * @return array|null Gutenberg block data or null
	 */
	private function convert_element_to_gutenberg($node, $options)
	{
		if ($node->nodeType === XML_TEXT_NODE) {
			return $node->nodeValue;
		}

		$node_name = strtolower($node->nodeName);

		// Handle headings (h1-h6)
		if (in_array($node_name, array('h1', 'h2', 'h3', 'h4', 'h5', 'h6'))) {
			$level = intval(substr($node_name, 1));
			return array(
				'blockName' => 'core/heading',
				'attrs' => array('level' => $level),
				'innerBlocks' => array(),
				'innerHTML' => $node->ownerDocument->saveHTML($node),
				'innerContent' => array($node->ownerDocument->saveHTML($node))
			);
		}
		// Handle images
		elseif ($node_name === 'img') {
			$src_url = $node->getAttribute('src');
			$alt_text = $node->getAttribute('alt');
			$title_text = $node->getAttribute('title');

			// Upload image if option enabled
			if ($options['upload_images']) {
				$attachment_id = $this->common->upload_image_by_url($src_url, $alt_text, $title_text);
				$new_src_url = wp_get_attachment_url($attachment_id);

				$node->setAttribute('src', $src_url);
				$node->setAttribute('class', "wp-image-" . $attachment_id);
				$alt_attr = $node->getAttribute('alt');
				$node->setAttribute('alt', $alt_attr !== null ? $alt_attr : '');

				return array(
					'blockName' => 'core/image',
					'attrs' => array(
						'id' => $attachment_id,
						'sizeSlug' => 'large',
						'linkDestination' => 'none'
					),
					'innerBlocks' => array(),
					'innerHTML' => '',
					'innerContent' => array(
						sprintf(
							'<figure class="wp-block-image size-large"><img src="%s" alt="%s" class="wp-image-%d" /></figure>',
							esc_url($new_src_url),
							esc_attr($node->getAttribute('alt')),
							$attachment_id
						)
					)
				);
			} else {
				return array(
					'blockName' => 'core/image',
					'attrs' => array(
						'sizeSlug' => 'large',
						'linkDestination' => 'none'
					),
					'innerBlocks' => array(),
					'innerHTML' => '',
					'innerContent' => array(
						sprintf(
							'<figure class="wp-block-image size-large"><img src="%s" alt="%s" /></figure>',
							esc_url($src_url),
							esc_attr($alt_text)
						)
					)
				);
			}
		}
		// Handle iframes
		elseif ($node_name === 'iframe') {
			return array(
				'blockName' => 'core/html',
				'attrs' => array(),
				'innerBlocks' => array(),
				'innerHTML' => $node->ownerDocument->saveHTML($node),
				'innerContent' => array($node->ownerDocument->saveHTML($node))
			);
		}
		// Handle paragraphs
		elseif ($node_name === 'p') {
			return array(
				'blockName' => 'core/paragraph',
				'attrs' => array(),
				'innerBlocks' => array(),
				'innerHTML' => $node->ownerDocument->saveHTML($node),
				'innerContent' => array($node->ownerDocument->saveHTML($node))
			);
		}
		// Handle tables
		elseif ($node_name === 'table') {
			$table_html = $node->ownerDocument->saveHTML($node);
			$node->setAttribute('class', 'metasyncTable-block');
			return array(
				'blockName' => 'core/table',
				'attrs' => array(),
				'innerBlocks' => array(),
				'innerHTML' => '<figure class="wp-block-table meta-block-tabel">' . $table_html . '</figure>',
				'innerContent' => array('<figure class="wp-block-table meta-block-tabel">' . $table_html . '</figure>')
			);
		}
		// Handle lists (ol, ul)
		elseif (in_array($node_name, array('ol', 'ul'))) {
			return array(
				'blockName' => 'core/list',
				'attrs' => array('ordered' => ($node_name === 'ol')),
				'innerBlocks' => array(),
				'innerHTML' => $node->ownerDocument->saveHTML($node),
				'innerContent' => array($node->ownerDocument->saveHTML($node))
			);
		}
		// Handle blockquotes
		elseif ($node_name === 'blockquote') {
			$node->setAttribute('class', 'wp-block-quote');
			$quote_html = $node->ownerDocument->saveHTML($node);
			return array(
				'blockName' => 'core/quote',
				'attrs' => array(),
				'innerBlocks' => array(),
				'innerHTML' => $quote_html,
				'innerContent' => array($quote_html)
			);
		}

		return null;
	}

	/**
	 * Convert using legacy format (for backward compatibility)
	 *
	 * This method maintains compatibility with the old metasync_upload_post_content() function
	 *
	 * @param array $item Item data with 'post_content' key
	 * @param bool $landing_page_option Whether this is for a landing page
	 * @param bool $otto_enable Whether this is for Otto AI
	 * @return array Result with 'content' and optional builder meta data
	 */
	public function convert_legacy($item, $landing_page_option = false, $otto_enable = false)
	{
		// Replace newlines with <br>
		$item['post_content'] = str_replace(["\r\n", "\r", "\n"], "<br>", $item['post_content'] ?? '');

		// Detect builder
		$builder = $this->detect_active_builder();

		// Convert HTML
		$result = $this->convert($item['post_content'], array(
			'builder' => $builder,
			'preserve_css' => true,
			'upload_images' => true,
			'extract_header_footer' => false, // Legacy doesn't extract header/footer
		));

		// Handle errors
		if (isset($result['error'])) {
			return array('content' => $item['post_content']);
		}

		// For Otto or landing pages, return just content
		if ($otto_enable || $landing_page_option) {
			return array('content' => $result['content']);
		}

		// Return with builder meta data
		$output = array('content' => $result['content']);

		if (!empty($result['meta_data'])) {
			if ($builder === 'elementor') {
				$output['elementor_meta_data'] = $result['meta_data'];
			} elseif ($builder === 'divi') {
				$output['divi_meta_data'] = $result['meta_data'];
			}
		}

		return $output;
	}
}

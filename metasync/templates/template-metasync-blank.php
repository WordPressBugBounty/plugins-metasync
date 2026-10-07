<?php
// If this file is called directly, abort before emitting any document output.
if (!defined('ABSPATH')) {
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>" />
	<?php
	wp_head();
	?>

</head>
<?php
$post_id = get_the_ID();
$css_links = get_post_meta($post_id, 'css_links', true);
$inline_css = get_post_meta($post_id, 'inline_css', true);
$js_links = get_post_meta($post_id, 'js_links', true);

$decode_meta_list = static function ($value) {
	if (is_array($value)) {
		return $value;
	}
	if (!is_string($value) || '' === $value) {
		return array();
	}

	$decoded = json_decode($value, true);
	return is_array($decoded) ? $decoded : array();
};

foreach ($decode_meta_list($css_links) as $css_link) {
	if (is_string($css_link) && filter_var($css_link, FILTER_VALIDATE_URL) && 0 === strpos($css_link, 'http')) {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Dynamic per-post CSS URLs from the user's page settings (css_links meta) must print in <head> of this bare custom-page template; enqueueing after wp_head() would relocate them to the footer.
		echo '<link href="' . esc_url($css_link) . '" rel="stylesheet"/>';
	}
}

foreach ($decode_meta_list($inline_css) as $css) {
	if (is_string($css)) {
		// SECURITY FIX: Sanitize CSS to prevent XSS.
		$sanitized_css = wp_strip_all_tags($css);
		echo '<style>' . esc_html($sanitized_css) . '</style>';
	}
}

foreach ($decode_meta_list($js_links) as $js_link) {
	if (is_string($js_link) && filter_var($js_link, FILTER_VALIDATE_URL) && 0 === strpos($js_link, 'http')) {
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Dynamic per-post JS URLs from the user's page settings (js_links meta) must print in <head> of this bare custom-page template; enqueueing after wp_head() would relocate them to the footer.
		echo '<script src="' . esc_url($js_link) . '"></script>';
	}
}
?>

<body class="text-gray-800 font-sans leading-normal gradient-bg postid-<?php echo esc_attr($post_id); ?>">
	<?php
	$content = apply_filters('the_content', get_the_content());
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content() passthrough in a blank page template; escaping would corrupt the document
	echo $content;
	?>
</body>
<?php
wp_footer();
?>

</html>

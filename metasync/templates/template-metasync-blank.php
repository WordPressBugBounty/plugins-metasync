<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>" />
	<?php
	// If this file is called directly, abort.
	if (!defined('ABSPATH')) {
		exit;
	}
	wp_head();
	?>

</head>
<?php
// get the post ID
$post_id = get_the_ID();
// get post meta data for css_links 
$css_links = get_post_meta($post_id, 'css_links', true);
// get post meta data for inline_css
$inline_css = get_post_meta($post_id, 'inline_css', true);
// get post meta data for js_links
$js_links = get_post_meta($post_id, 'js_links', true);
// get post meta data for inline_js
$inline_js = get_post_meta($post_id, 'inline_js',  true);
// check if the key css_links exist
if($css_links!==''){
// Loop through all the css link
$decoded_css_links = json_decode($css_links);
if (is_array($decoded_css_links)) {
foreach ($decoded_css_links as $css_link) {
	// SECURITY FIX: Validate and escape CSS URLs
	if (filter_var($css_link, FILTER_VALIDATE_URL) && (strpos($css_link, 'http') === 0)) {
		echo '<link href="' . esc_url($css_link) . '" rel="stylesheet"/>';
	}
}
}
}
// check if the key inline_css exist
if($inline_css!==''){
// Loop through all the inline css and print it
$decoded_inline_css = json_decode($inline_css);
if (is_array($decoded_inline_css)) {
foreach ($decoded_inline_css as $css) {
	// SECURITY FIX: Sanitize CSS to prevent XSS
	$sanitized_css = wp_strip_all_tags($css);
	echo '<style>' . esc_html($sanitized_css) . '</style>';
}
}
}
// check if the key js_links exist
$js_links_decoded = !empty($js_links) ? json_decode($js_links) : null;
if(is_array($js_links_decoded)){
// Loop through all the js link and print it script tags
$decoded_js_links = json_decode($js_links);
if (is_array($decoded_js_links)) {
foreach ($decoded_js_links as $js_link) {
	// SECURITY FIX: Validate and escape JS URLs
	if (filter_var($js_link, FILTER_VALIDATE_URL) && (strpos($js_link, 'http') === 0)) {
		echo '<script src="' . esc_url($js_link) . '"></script>';
	}
}
}
}
?>

<body class="text-gray-800 font-sans leading-normal gradient-bg postid-<?= esc_attr($post_id) ?>">
	<?php
	// get content of the post
	$content = apply_filters('the_content', get_the_content());
	echo $content;
	?>
</body>
<?php
wp_footer();
// check if the key inline_js exist
$inline_js_decoded = !empty($inline_js) ? json_decode($inline_js) : null;
if(is_array($inline_js_decoded)){
// Loop through all the inline script and print the script tags after body
$decoded_inline_js = json_decode($inline_js);
if (is_array($decoded_inline_js)) {
foreach ($decoded_inline_js as $js) {
	// SECURITY FIX: Completely block inline JS to prevent XSS
	// This feature is disabled for security reasons
	error_log('MetaSync Security: Inline JS blocked for security - content: ' . substr($js, 0, 100));
}
}
}

?>

</html>
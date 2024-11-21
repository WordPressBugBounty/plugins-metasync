<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo('charset'); ?>" />
	<?php
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
foreach (json_decode($css_links) as $css_link) {
	printf('<link href="' . $css_link . '" rel="stylesheet"/>');
}
}
// check if the key inline_css exist
if($inline_css!==''){
// Loop through all the inline css and print it 
foreach (json_decode($inline_css) as $css) {
	printf('<style>' . ($css) . '</style>');
}
}
// check if the key js_links exist
if($js_links!==''){
// Loop through all the js link and print it script tags
foreach (json_decode($js_links) as $js_link) {
	printf('<script src="' . $js_link . '"></script>');
}
}
?>

<body class="text-gray-800 font-sans leading-normal gradient-bg <?= $post_id ?>">
	<?php
	// get content of the post
	$content = apply_filters('the_content', get_the_content());
	echo $content;
	?>
</body>
<?php
wp_footer();
// check if the key inline_js exist
if($inline_js!==''){
// Loop through all the inline scipt  and print the script tags after body
foreach (json_decode($inline_js) as $js) {
	printf('<script>' . $js . '</script>');
}
}

?>

</html>
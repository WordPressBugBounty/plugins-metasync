<?php


class MetaSyncHiddenPostManager
{
    # Define static properties for the hidden post
    private $post_title; # Title of the hidden post
    private $post_content; # Default content
    public $image_url; # Featured image URL 
    private $hidden_post_type; # Non-existent post type to hide it

    # Initialize hooks on WordPress init
    public function init()
    {
        # Get the last execution timestamp
        $metasync_data = Metasync::get_option('general');

        # Check if 24 hours have passed
        if (empty($metasync_data['last_run_time']) || (time() - $metasync_data['last_run_time']) > 604800) {

            $this->create_hidden_post(); #create/show post for crawling
        }
    }

    public function __construct()
    {
        $this->post_title = 'Please do not delete this Metasync test Post'; # Initialize post_title
        $this->post_content = 'This post, is used by the ' . Metasync::get_effective_plugin_name() . ' plugin, please do not delete.'; # Initialize content
        $this->hidden_post_type = 'metasync_post_type'; # Initialize Non-existent post type to hide it later

    }

    /**
     * Upload a local image to WordPress media library and return the URL
     */
    private function upload_local_image($file_path)
    {
        if (!file_exists($file_path)) {
            return ''; # Return empty if file doesn't exist
        }
    
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    
        # Create a temporary copy to prevent deletion
        $tmp_file_path = wp_tempnam($file_path);
        copy($file_path, $tmp_file_path);
    
        # Upload the copied file
        $file_array = [
            'name'     => basename($file_path),
            'tmp_name' => $tmp_file_path, # Use the copied file
        ];
    
        $attachment_id = media_handle_sideload($file_array, 0);
    
        # Clean up the temp file
        if (file_exists($tmp_file_path)) {
        @unlink($tmp_file_path); # Delete only the temp copy, not the original file
        }
    
        if (is_wp_error($attachment_id)) {
            return ''; # Return empty if upload fails
        }
    
        # Return the URL of the uploaded image
        return wp_get_attachment_url($attachment_id);
    }
    

    /**
     * Get Image url after load
     */

    public function get_image_url_Data()
    {

        # Get the latest uploaded image from the media library
        $latest_image = get_posts([
            'post_type'      => 'attachment',  # Fetch only media attachments
            'post_mime_type' => 'image',       # Ensure it's an image
            'posts_per_page' => 1,             # Limit to 1 result
            'orderby'        => 'date',        # Get the latest one
            'order'          => 'DESC',        # Sort in descending order
        ]);


        # Get local image path	
        $local_image_path = plugin_dir_path(dirname(__FILE__)) .     'assets/banner-1544x500.png';

        # Check if an image exists and retrieve its URL
        $this->image_url = (!empty($latest_image)) ? wp_get_attachment_url($latest_image[0]->ID) : $this->upload_local_image($local_image_path); # Use default if no image found 


    }

    /**
     * Create a hidden post programmatically if it doesn't exist
     */

    public function create_hidden_post()
    {

        # Check if the post already exists by title
        global $wpdb;

        # prepare query
        $query = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND (post_type = 'post' OR post_type = 'metasync_post_type') LIMIT 1",
            $this->post_title
        );


        # Get the Data
        $existing_post = $wpdb->get_var($query);

        $this->get_image_url_Data();
        if ($existing_post) {

            #show post
            $this->show_post_completely($existing_post);

            # Update Post meta for Divi
            $existing_meta = get_post_meta($existing_post, '_et_pb_built_for_post_type', true);

            if ($existing_meta !== '') {
                # If meta key exists, update it
                update_post_meta($existing_post, '_et_pb_built_for_post_type', 'post');
            } else {
                # If meta key does not exist, add it
                add_post_meta($existing_post, '_et_pb_built_for_post_type', 'post', true);
            }
            # Check if title and image exist in post content and fix if needed
            $this->check_and_fix_post_content($existing_post);

            $this->check_and_fix_page_content($existing_post);

            # Hide the post completely by changing its post type
            $this->hide_post_completely($existing_post);
            return $existing_post;
        }
        
        $prepare_content['post_content'] = $this->post_content;

        # Pass the Parameter
        if (defined('METASYNC_VERSION')) {
			$version = METASYNC_VERSION;
		} else {
			$version = '1.0.0';
		}
		$plugin_name = 'metasync';

        # Call the MetaSync Public Class 
        $content = new Metasync_Public($plugin_name,$version);
        $builderData = $content->metasync_upload_post_content($prepare_content);

        # Insert a new private post
        $post_id = wp_insert_post([
            'post_title'   => $this->post_title, # Insert poost title
            'post_content' => $builderData['content'] ?  $builderData['content'] : $prepare_content, # Insert post content
            'post_status'  => 'publish', # Make the post available
            'post_type'    => 'post', # Default post type before hiding it
        ]);


        $post_meta = array();

        # Update Post meta for Page Builder to work
        if(isset($builderData['elementor_meta_data'])){
            $post_meta = array_merge($post_meta,$builderData['elementor_meta_data']);
        }else if(isset($builderData['divi_meta_data'])){
            $post_meta = array_merge($post_meta,$builderData['divi_meta_data']);
            $post_meta['_et_pb_ab_current_shortcode']='[et_pb_split_track id="'.$post_id.'" /]';
            $post_meta['_et_pb_use_builder']='on'; # Turn on the page Builder
            $post_meta['_et_pb_built_for_post_type']='post';
        }

        # Add custom fields to posts and pages
        foreach ($post_meta as $key => $value) {
            # Add Post Meta
            add_post_meta($post_id, $key, $value, true);
            
        }
			
        if (is_wp_error($post_id)) {
            return false; # Return false if post creation fails
        }

        # check if the feature image is empty
        if (!empty($this->image_url)) {

            # Set a featured image for the post
            $this->set_featured_image($post_id, $this->image_url);
        }


        if (isset( $builderData['elementor_meta_data']) && did_action( 'elementor/loaded' )) {
            # Clear Elementor cache for the specified post ID
            \Elementor\Plugin::instance()->files_manager->clear_cache();

        }

        # Check if title and image exist in post content and fix if needed
        $this->check_and_fix_post_content($post_id);

        $this->check_and_fix_page_content($post_id);

        # Hide the post completely by changing its post type
        $this->hide_post_completely($post_id);

        return $post_id;
    }

    
    /**
     * Change the post type to page and check the  
     */
    private function check_and_fix_page_content($post_id)
    {

        # Update the Post type to page to check the template
        wp_update_post([
            'ID'        => $post_id,
            'post_type' => 'page',
            'post_status'  => 'publish', # Making Sure Page is Published 
        ]);
    
        # Update Post meta for Divi
        $existing_meta = get_post_meta($post_id, '_et_pb_built_for_post_type', true);

        if ($existing_meta !== '') {
            # If meta key exists, update it
            update_post_meta($post_id, '_et_pb_built_for_post_type', 'page');
        } else {
            # If meta key does not exist, add it
            add_post_meta($post_id, '_et_pb_built_for_post_type', 'page', true);
        }   

        # Check the page has the feature image and the Page title
        $this->check_and_fix_post_content($post_id,'page');
    }

    /**
     * Attach a featured image to the post if missing
     */
    private function set_featured_image($post_id, $image_url)
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        # Download and attach the image to the post
        $media_id = media_sideload_image($image_url, $post_id, null, 'id');

        if (!is_wp_error($media_id)) {
            set_post_thumbnail($post_id, $media_id); # Set as featured image
        }
    }

    /**
     * Check if the post content contains the title and featured image, add them if missing
     * add post title and post feature image on the content
     */
    private function check_and_fix_post_content($post_id,$post_type='post')
    {

        # Get the post title
        $post_title = get_the_title($post_id);

        # Get the Metasync Option
        $metasyncData = Metasync::get_option();
        # Prepare HTML content to prepend if title or image is missing
        $prepend_content = '';

        # Get the featured image URL of the post
        $featured_image_url = get_the_post_thumbnail_url($post_id, 'full');

        # Check for [.webp,.jpg,.png] pattern 
        if (!empty($featured_image_url)) {
            $featured_image_url = preg_replace('/\.[^.]+$/', '', $featured_image_url);
        }

        # Get the preview URL of the post
        $post_url = get_permalink($post_id);

        # Initialize cURL to fetch the post HTML
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $post_url); # Set the URL to fetch
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); # Return the response as a string
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); # Follow redirects if any
        $html = curl_exec($ch); # Execute the request and store the HTML response
        curl_close($ch); # Close the cURL session

        # check that the html object is not empty
        if(empty($html)){
            return false;
        }

        # Load the HTML content into DOMDocument
        $dom = new DOMDocument();
        libxml_use_internal_errors(true); # Suppress HTML parsing errors
        $dom->loadHTML($html); # Parse the HTML content
        libxml_clear_errors(); # Clear any errors

        # Use XPath to search for specific elements in the HTML
        $xpath = new DOMXPath($dom);

        # Query all heading tags (h1 - h6) and img tags
        $elements = $xpath->query("//h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //img");

        # Flags to check if title or image is present
        $title_in_headings = false;
        $image_in_content = false;

        # Arrays to store found headings and images
        $headings = [];
        $image_tags = [];

        # Loop through all queried elements
        foreach ($elements as $element) {
            if (in_array($element->nodeName, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {
                # Process heading tags
                $heading_text = trim($element->textContent); # Get the text content of the heading
                $headings[] = [
                    'tag' => $element->nodeName, # Store the tag type (h1, h2, etc.)
                    'text' => $heading_text # Store the heading text
                ];

                # Check if the post title is present in any heading
                if (stripos($heading_text, $post_title) !== false) {
                    $title_in_headings = true;
                }
            } elseif ($element->nodeName === 'img') {
                # Process image tags
                $img_src = $element->getAttribute("src"); # Get the image source URL
                $image_tags[] = $img_src; # Store the image URL

                # Check if the featured image is already present in content
                if (!empty($featured_image_url) && stripos($img_src, $featured_image_url) !== false) {
                    $image_in_content = true;
                }
            }
        }


        # If the post title is found title_in_headings to true
        if ($title_in_headings) {


            # Update Metasync data to indicate the title is already there
            $metasyncData['general']['title_in_headings'][$post_type] = true;
        } else {

            # Mark that the title was not there.
            $metasyncData['general']['title_in_headings'][$post_type] = false;
        }

        # If the featured image is found image_in_content to true or make it true if there is no feature image
        if ($image_in_content || empty($featured_image_url)) {

            # Update Metasync data to indicate the image is already there
            $metasyncData['general']['image_in_content'][$post_type] = true;
        } else {

            # Mark that the image was not there.
            $metasyncData['general']['image_in_content'][$post_type] = false;
        }

        # Update the timestamp
        $metasyncData['general']['last_run_time'] =  time();
        
        # Save the updated Metasync options
        Metasync::set_option($metasyncData);

        # Return the content that needs to be prepended
        return [
            'content' => $prepend_content
        ];
    }

    /**
     * Change post type to a post type to hide it from admin
     */
    private function show_post_completely($post_id)
    {
        global $wpdb;

        $wpdb->update(
            $wpdb->posts,
            ['post_type' => 'post'], # Change post type to show it
            ['ID' => $post_id]
        );

        # Publish the post to Access  
        wp_update_post([
            'ID'          => $post_id,
            'post_status' => 'publish',
        ]);
    }
    /**
     * Change post type to a non-existent type to hide it from admin
     */
    private function hide_post_completely($post_id)
    {
        global $wpdb;

        # Update post to draft 
        wp_update_post([
            'ID'          => $post_id,
            'post_status' => 'draft',
        ]);
        
        $wpdb->update(
            $wpdb->posts,
            ['post_type' => 'metasync_post_type'], # Change post type to hide it
            ['ID' => $post_id]
        );
        
    }

    /**
     * Prevent deletion of the hidden post
     */
    public function prevent_post_deletion($post_id)
    {
        global $wpdb;
        $post = get_post($post_id);

        if ($post->post_type === $this->hidden_post_type) {
            //    wp_die('This post cannot be deleted.'); # Block deletion
        }
    }
}

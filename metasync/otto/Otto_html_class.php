<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}


# uses the simple html dom library
use simplehtmldom\HtmlWeb;
use simplehtmldom\HtmlDocument;

/**
 * This Class Handles  
 */

Class Metasync_otto_html{

    # html dom
    private $dom;

    # the file path to save to
    private $html_file;

    # uuid of site
    private $site_uuid;

    # the otto endpoint url
    private $otto_end_point;

    # PERFORMANCE OPTIMIZATION: Defer DOM reload until final save
    # Reduces 6-10 serialize/deserialize cycles to just 1
    private $deferred_reload = false;

    # PERFORMANCE OPTIMIZATION: Cache commonly accessed DOM elements
    # Eliminates 8-10 full DOM traversals per page
    private $cached_elements = [];

    #
    function __construct($otto_uuid){

        # set the site uuid using the provided string
        $this->site_uuid = $otto_uuid;

        # Use endpoint manager if available, otherwise fallback to production
        if (class_exists('Metasync_Endpoint_Manager')) {
            $this->otto_end_point = Metasync_Endpoint_Manager::get_endpoint('OTTO_URL_DETAILS');
        } else {
            $this->otto_end_point = 'https://sa.searchatlas.com/api/v2/otto-url-details';
        }

        # laod the simple html dom parser with UTF-8 charset to handle special characters
        $this->dom = new HtmlDocument(null, true, true, 'UTF-8');
    }

    /**
     * Check Route Method
     * @param route : The route to check
     * @param path : The path of the html file to save
     */
    function process_route($route, $file_path){

        # Construct the full endpoint URL with query parameters
        $url_with_params = add_query_arg(
            [
                'url'  => $route,
                'uuid' => $this->site_uuid,
            ],
            $this->otto_end_point
        );

        # PERFORMANCE FIX: Add timeout to prevent blocking
        $args = array(
            'timeout' => 5, // 5 second max timeout (allow time for redirects)
            'redirection' => 5, // CRITICAL FIX: Allow redirects (API returns 301)
            'user-agent' => 'MetaSync-OTTO-SSR/2.0',
            'sslverify' => true
        );

        # Perform the GET request with timeout
        $response = wp_remote_get($url_with_params, $args);

        # Check for errors
        if (is_wp_error($response)) {
            error_log('MetaSync ' . Metasync::get_whitelabel_otto_name() . ': API call failed - ' . $response->get_error_message());
            return false;
        }

        # get the response body
        $body = wp_remote_retrieve_body($response);

        # Get the response code
        $response_code = wp_remote_retrieve_response_code($response);

        # if no change data skip
        if (empty($body) || $response_code !== 200){
            error_log('MetaSync ' . Metasync::get_whitelabel_otto_name() . ': API returned empty or non-200. Code: ' . $response_code);
            return false;
        }

        # set the html file path
        $this->html_file = $file_path;

        # load change data
        $change_data = json_decode($body, true);
        
        # Process with the fetched data
        return $this->process_route_with_data($route, $change_data, $file_path);
    }
    
    /**
     * Process route with pre-fetched suggestions data
     * OPTION 1: Used when data comes from transient cache
     * @param route : The route to check
     * @param change_data : Pre-fetched OTTO suggestions data
     * @param path : The path of the html file to save
     */
    function process_route_with_data($route, $change_data, $file_path){

        if (empty($change_data) || !is_array($change_data)) {
            return false;
        }

        # set the html file path
        $this->html_file = $file_path;
        
        # Analyze what Otto is providing and store for conditional SEO blocking
        $has_otto_title = false;
        $otto_description_tags = []; // Track specific description tags Otto provides
        
        if (!empty($change_data['header_replacements']) && is_array($change_data['header_replacements'])) {
            foreach ($change_data['header_replacements'] as $item) {
                if (!empty($item['type'])) {
                    # Check if Otto has title
                    if ($item['type'] == 'title' && !empty($item['recommended_value'])) {
                        $has_otto_title = true;
                    }
                    # Check if Otto has description - track specific tag types
                    if ($item['type'] == 'meta') {
                        # Check for meta[name=description]
                        if (!empty($item['name']) && $item['name'] == 'description' && !empty($item['recommended_value'])) {
                            $otto_description_tags[] = 'meta[name=description]';
                        }
                        # Check for meta[property=og:description]
                        if (!empty($item['property']) && $item['property'] == 'og:description' && !empty($item['recommended_value'])) {
                            $otto_description_tags[] = 'meta[property=og:description]';
                        }
                        # Check for meta[name=twitter:description]
                        if (!empty($item['name']) && $item['name'] == 'twitter:description' && !empty($item['recommended_value'])) {
                            $otto_description_tags[] = 'meta[name=twitter:description]';
                        }
                    }
                }
            }
        }
        
        # Check header_html_insertion for description
        if (!empty($change_data['header_html_insertion'])) {
            if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*>/i', $change_data['header_html_insertion'])) {
                $otto_description_tags[] = 'meta[name=description]';
            }
        }

        # Remove duplicates
        $otto_description_tags = array_unique($otto_description_tags);
        
        # Store blocking flags to pass to handle_route_html
        # This will be added to the internal fetch URL as parameters
        $change_data['_otto_blocking'] = array(
            'block_title' => $has_otto_title,
            'block_description_tags' => $otto_description_tags // Pass array of specific tags to remove
        );
        
        # Process the route with the suggestions data
        return $this->handle_route_html($route, $change_data);
        
    }

    # function to get tag attributes
    function get_tag_attributes($tag){

        # Extract existing attributes of the <body> tag
        $attributes = [];


        # set the tag attributes
        $tag_attributes = [];
        

        # check that the tag attributes 
        if(!is_object($tag) || !method_exists($tag, 'getAllAttributes')){
            return '';
        }

        # get the tag attributes
        $tag_attributes = $tag->getAllAttributes();  

        # loop all attributes
        foreach ($tag_attributes as $key => $value) {

            if ($value == 1) {

                # Handle boolean attributes
                $attributes[] = htmlspecialchars($key, ENT_QUOTES);
            } else {

                # Handle attributes with values
                $attributes[] = $key . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
            }
        }

        # Convert attributes array to a string
        $attributes_string = !empty($attributes) ? ' ' . implode(' ', $attributes) : '';

        # return the attributes string
        return $attributes_string;
    }

    function handle_route_html($route, $replacement_data){

        # Detect if current page uses Brizy and disable SG Cache if so
        # Using global function defined in otto_pixel.php
        if (function_exists('metasync_otto_disable_sg_cache_for_brizy')) {
            metasync_otto_disable_sg_cache_for_brizy();
        }

        # lablel the Otto Route
        # label otto requests to avoid loops
        // $request_body = add_query_arg(
        //     [
        //         'is_otto_page_fetch' => 1
        //     ],
        //     $route
        // );
        # Add blocking flags as URL parameters (no database writes!)
        $url_params = ['is_otto_page_fetch' => 1];
        
        # Add blocking flags if available
        if (!empty($replacement_data['_otto_blocking'])) {
            $url_params['otto_block_title'] = $replacement_data['_otto_blocking']['block_title'] ? '1' : '0';
            # For HTTP fetch path, check if any description tags need blocking
            $block_description_tags = $replacement_data['_otto_blocking']['block_description_tags'] ?? [];
            $url_params['otto_block_desc'] = !empty($block_description_tags) ? '1' : '0';        
            }
        
        $request_body = add_query_arg($url_params, $route);
        
        # TUNNEL/PROXY SUPPORT: If site is behind a tunnel (ngrok, zrok, etc.)
        # and loopback requests fail, try using localhost instead
        $request_body = apply_filters('metasync_otto_internal_fetch_url', $request_body, $route);
		# set cookie header var
		$cookie_header = '';
		
		# loop cookies to set header
		foreach ($_COOKIE as $name => $value) {
			
            # handle array values by converting to string
			$cookie_value = is_array($value) ? serialize($value) : $value;

			# add cookie to header
			# $cookie_header .= $name . '=' . $value . '; ';
            $cookie_header .= $name . '=' . $cookie_value . '; ';
		}
		
		# trim the string
		$cookie_header = rtrim($cookie_header, '; ');

		# Allow timeout customization for slow tunnel environments
		$fetch_timeout = apply_filters('metasync_otto_internal_fetch_timeout', 5);
		
		$args = array(
			'sslverify' => false, // Disabled for localhost/tunnel environments
			'timeout' => $fetch_timeout, // Configurable timeout for tunnels
			'redirection' => 5,
			'httpversion' => '1.1',
			'headers' => array(
				'Cookie' => $cookie_header,
				'Cache-Control' => 'no-cache, no-store, must-revalidate',
				'Pragma' => 'no-cache',
				'X-OTTO-Internal-Fetch' => '1',
				'User-Agent' => 'MetaSync-OTTO-SSR/3.0',
				'X-Forwarded-Host' => $_SERVER['HTTP_HOST'] ?? '', // Preserve original host for tunnels
			)
		);

        # get the associateed route html
        $route_html = wp_remote_get($request_body, $args);

		# Check for timeout or connection errors
		if (is_wp_error($route_html)) {
			$error_msg = $route_html->get_error_message();
			error_log('MetaSync ' . Metasync::get_whitelabel_otto_name() . ' DEBUG: FAILED - wp_remote_get error: ' . $error_msg . ' for route: ' . $route);
			return false;
		}

        # get body
        $html_body = wp_remote_retrieve_body($route_html);

        # Get the response code
        $response_code = wp_remote_retrieve_response_code($route_html);


        # check not empty
        if(empty($html_body) || $response_code !== 200){
			error_log('MetaSync ' . Metasync::get_whitelabel_otto_name() . ' DEBUG: FAILED - Empty body or non-200 status for route: ' . $route);
            return false;
        }

        # Remove XML declaration
        $html_body = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $html_body);

        # now that the html is not empty
        # load it into the simple html dom
        $this->dom->load($html_body);

        # Force UTF-8 charset to preserve emojis and special characters
        # This overrides any charset detection from HTML meta tags
        $this->dom->_charset = 'UTF-8';
        $this->dom->_target_charset = 'UTF-8';

        # PERFORMANCE OPTIMIZATION: Pre-cache commonly accessed DOM elements
        # This eliminates 8-10 full DOM traversals per page
        $this->cache_elements();

        # PERFORMANCE OPTIMIZATION: Enable deferred reload to skip intermediate reloads
        # This reduces 6-10 DOM serialize/deserialize cycles to just 1 final reload
        $this->deferred_reload = true;

        # now lets do the magic

        # COMPATIBILITY FIX: Transform 'value' to 'recommended_value' if needed
        # Some API versions return 'value' instead of 'recommended_value'
        if (!empty($replacement_data['header_replacements'])) {
            foreach ($replacement_data['header_replacements'] as &$item) {
                if (isset($item['value']) && !isset($item['recommended_value'])) {
                    $item['recommended_value'] = $item['value'];
                }
            }
            unset($item); // Break reference
        }

        # start the header html insertion
        $this->insert_header_html($replacement_data);

        # now we do the header replacements
        $this->do_header_replacements($replacement_data);

        # now do the body replacements
        $this->do_body_replacements($replacement_data);

        # now do the footer insertions
        $this->do_footer_html_insertion($replacement_data);

        # final cleanup: ensure metasync_optimized attribute is removed from AMP pages
        $this->cleanup_amp_metasync_attribute();

        # CRITICAL FIX: SimpleHtmlDom save() doesn't persist outertext/innertext changes
        # Use the same manual string replacement approach as process_html_directly
        $this->deferred_reload = false;

        # Get the HTML as string
        $result_html = $this->dom->save();

        # Apply manual replacements (same logic as process_html_directly)

        # Apply header replacements manually
        if (!empty($replacement_data['header_replacements'])) {
            foreach ($replacement_data['header_replacements'] as $item) {
                $type = $item['type'] ?? '';
                $value = $item['recommended_value'] ?? $item['value'] ?? '';

                if (empty($value)) continue;

                if ($type === 'title') {
                    $new_value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                    $result_html = preg_replace('/<title[^>]*>.*?<\/title>/is', '<title>' . $new_value . '</title>', $result_html, 1);
                } elseif ($type === 'meta') {
                    $name = $item['name'] ?? '';
                    $property = $item['property'] ?? '';
                    $new_value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

                    if (!empty($name)) {
                        $pattern = '/<meta\s+(?:name=["\']' . preg_quote($name, '/') . '["\']\s+content=["\'][^"\']*["\']|content=["\'][^"\']*["\']\s+name=["\']' . preg_quote($name, '/') . '["\'])\s*\/?>/i';
                        $replacement = '<meta name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" content="' . $new_value . '">';
                        $count = 0;
                        $result_html = preg_replace($pattern, $replacement, $result_html, -1, $count);

                        if ($count === 0) {
                            $result_html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $replacement, $result_html, 1);
                        }
                    } elseif (!empty($property)) {
                        $pattern = '/<meta\s+property=["\']' . preg_quote($property, '/') . '["\']\s+content=["\'][^"\']*["\']\s*\/?>/i';
                        $replacement = '<meta property="' . htmlspecialchars($property, ENT_QUOTES, 'UTF-8') . '" content="' . $new_value . '">';
                        $count = 0;
                        $result_html = preg_replace($pattern, $replacement, $result_html, -1, $count);

                        if ($count === 0) {
                            $result_html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $replacement, $result_html, 1);
                        }
                    }
                } elseif ($type === 'h1' || $type === 'heading') {
                    $new_value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                    $result_html = preg_replace('/<h1[^>]*>.*?<\/h1>/is', '<h1>' . $new_value . '</h1>', $result_html, 1);
                }
            }
        }

        # Apply insertions
        if (!empty($replacement_data['header_html_insertion'])) {
            $result_html = preg_replace('/(<\/head>)/i', $replacement_data['header_html_insertion'] . "\n" . '$1', $result_html, 1);
        }
        if (!empty($replacement_data['body_top_html_insertion'])) {
            $result_html = preg_replace('/(<body[^>]*>)/i', '$1' . "\n" . $replacement_data['body_top_html_insertion'], $result_html, 1);
        }
        if (!empty($replacement_data['body_bottom_html_insertion'])) {
            $result_html = preg_replace('/(<\/body>)/i', $replacement_data['body_bottom_html_insertion'] . "\n" . '$1', $result_html, 1);
        }
        if (!empty($replacement_data['footer_html_insertion'])) {
            $result_html = preg_replace('/(<\/html>)/i', $replacement_data['footer_html_insertion'] . "\n" . '$1', $result_html, 1);
        }

        return $result_html;
    }

    # do the footer html insertion
    function do_footer_html_insertion($replacement_data){

        # check that we have footer html
        if(empty($replacement_data['footer_html_insertion'])){
            return;
        }

        # OPTIMIZED: Use cached element instead of DOM traversal
        $footer = $this->get_cached_element('footer');

        # check that footer is object
        if(!is_object($footer) || !isset($footer->innertext, $footer->outertext)){
            return;
        }

        # get the tag attributes
        $attributes_string = $this->get_tag_attributes($footer);

        # now do the actual html replacements
        $footer->outertext = '<footer' . $attributes_string . '>' . $footer->innertext . $replacement_data['footer_html_insertion'].'</footer>';

        # save the document
        $this->save_reload();
    }

    # do body replacements
    function do_body_replacements($replacement_data){

        # start body top html replacements
        $this->do_body_top_html($replacement_data);
    
        # start the body bottom html replacements
        $this->do_body_bottom_html($replacement_data);

        # do the body substitutions
        $this->do_body_substitutions($replacement_data);

        # Check if the feature is enabled in general settings (Post/Page Editor Settings)
        $general_settings = get_option('metasync_options')['general'] ?? [];
        if (!empty($general_settings['open_external_links']) && $general_settings['open_external_links'] == '1') {
            $this->add_target_blank_to_external_links();
        }

        # Check if the feature is enabled in seo_controls settings (Indexation Control)
        $seo_controls = get_option('metasync_options')['seo_controls'] ?? [];
        if (!empty($seo_controls['add_nofollow_to_external_links']) && $seo_controls['add_nofollow_to_external_links'] === 'true') {
            $this->add_nofollow_to_external_links();
        }
    }

    # body substitutions data
    function do_body_substitutions($replacement_data){

        # check that we have an array of substitutions
        if(empty($replacement_data['body_substitutions']) || !is_array($replacement_data['body_substitutions'])){
            return;
        }

        # now work on different substitution keys
        foreach ($replacement_data['body_substitutions'] as $key => $value) {

            # check key categories
            if($key == 'images'){
                # do image replacements
                $this->handle_images($value);
            }
            elseif($key == 'headings'){
                # do heading repalcements
                $this->do_heading_body_substitutions($value);
            }
            elseif($key == 'links'){
                # do link replacements
                $this->do_link_body_substitutions($value);
            }

        }

        # save the document
        $this->save_reload();

    }

    /**
     * START BODY SUBSTITUTION FUNCTIONS
     * @see do_body_substitutions();
     */

    # image substitions
    function handle_images($image_data){

        if (empty($image_data) || !is_array($image_data)) {
            return;
        }

        # OPTIMIZED: Use cached images instead of DOM traversal
        $images = $this->get_cached_element('imgs', []);

        if (empty($images)) {
            return;
        }

        # PERFORMANCE OPTIMIZATION: O(n²) reduced to O(n)
        # Single pass with hash map lookup instead of nested loop
        foreach($images AS $key => $image){
            # Get image src
            $image_src = $image->src;

            if (empty($image_src)) {
                continue;
            }

            # Hash map lookup O(1) instead of loop O(n)
            if (isset($image_data[$image_src])) {
                # Set alt text - Note: This may not persist in all cases
                # Manual string replacement in process_html_directly() ensures it's applied
                $new_alt = htmlspecialchars($image_data[$image_src], ENT_QUOTES, 'UTF-8');

                # Get current img tag HTML and update alt attribute
                $current_html = $image->outertext;

                # Remove existing alt attribute (if any)
                $updated_html = preg_replace('/\s+alt=(["\'])[^"\']*\1/', '', $current_html);

                # Insert new alt attribute after the opening <img
                $updated_html = preg_replace('/^<img\s/', '<img alt="' . $new_alt . '" ', $updated_html);

                # Update the element
                $image->outertext = $updated_html;

                $multi_view_attr = $image->getAttribute('data-et-multi-view');
                if (!empty($multi_view_attr)) {
                    $this->update_divi_multi_view_alt($image, $image_data[$image_src]);
                }
            }
        }
    }

    /**
     * Update Divi's multi-view data attribute with alt text
     * Divi stores image attributes in a JSON structure within data-et-multi-view
     * 
     * @param object $image The image DOM element
     * @param string $alt_text The alt text to set
     */
    private function update_divi_multi_view_alt($image, $alt_text) {
        $multi_view_attr = $image->getAttribute('data-et-multi-view');
        
        if (!empty($multi_view_attr)) {
            try {
                # Decode the JSON
                $multi_view_data = json_decode($multi_view_attr, true);
                
                if ($multi_view_data && isset($multi_view_data['schema']['attrs'])) {
                    # Update alt in desktop view
                    if (isset($multi_view_data['schema']['attrs']['desktop'])) {
                        $multi_view_data['schema']['attrs']['desktop']['alt'] = $alt_text;
                    }
                    
                    # Update alt in other views if they exist (phone, tablet, etc.)
                    foreach ($multi_view_data['schema']['attrs'] as $view => $attrs) {
                        if (isset($attrs['alt'])) {
                            $multi_view_data['schema']['attrs'][$view]['alt'] = $alt_text;
                        }
                    }
                    
                    # Encode back to JSON and update the attribute
                    $updated_json = json_encode($multi_view_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    $image->setAttribute('data-et-multi-view', $updated_json);
                    
                   # error_log('MetaSync OTTO DEBUG: Updated Divi multi-view alt text');
                }
            } catch (Exception $e) {
                # If JSON decode fails, just log and continue
                # error_log('MetaSync OTTO DEBUG: Failed to update Divi multi-view - ' . $e->getMessage());
            }
        }
    }

    # heading substitutions
    function do_heading_body_substitutions($heading_data){

        # loop all data
        foreach($heading_data AS $okey => $heading){

            # find all occurences of the heading type
            $occurences = $this->dom->find($heading['type']);

            # loop all occurences
            foreach ($occurences as $ikey => $heading_old) {
                
                # get the header text
                $text = $heading_old->text();

                # check matching text
                if(trim($heading['current_value'] ?? '') == trim($text ?? '')){

                    # set the text
                    $heading_old->innertext = $heading['recommended_value'];

                }
            }

        }

        # save and reload the dom
        $this->save_reload();
    }

    # link replacements
    function do_link_body_substitutions($swap_data){

        # find all links in the body
        $links = $this->dom->find('a');

        # loop all links check if we have them in swap data 
        foreach ($links as $key => $link) {
            
            # check if link matches
            if(!empty($swap_data[$link->href])){
                
                # replace the link
                $link->href = $swap_data[$link->href];
            }

        }
    }
    
    /**
     * Add rel="nofollow" attribute to external links
     * Works for both buffer and HTTP rendering methods
     * Only processes links that don't already have rel="nofollow"
     */
    function add_nofollow_to_external_links(){
        
        # find all links in the body
        $links = $this->dom->find('a');
        
        # get home URL for comparison
        $home_url = rtrim(home_url(), '/');
        $home_url_lower = strtolower($home_url);
        
        # loop through all links
        foreach ($links as $key => $link) {
            
            # get the href attribute
            $href = $link->href ?? '';
            
            # skip if href is empty
            if (empty($href)) {
                continue;
            }
            
            # check if link is external
            if ($this->is_external_link($href, $home_url, $home_url_lower)) {
                # get existing rel attribute
                $existing_rel = $link->rel ?? '';
                
                # check if nofollow already exists
                if (empty($existing_rel)) {
                    # no rel attribute, add nofollow
                    $link->rel = 'nofollow';
                } elseif (strpos($existing_rel, 'nofollow') === false) {
                    # rel exists but nofollow is not present, add it
                    $link->rel = trim($existing_rel . ' nofollow');
                }
                # if nofollow already exists, do nothing
            }
        }
        
        # Note: No need to call save_reload() here - it's called at the end of process_html_directly()
        # This avoids redundant DOM save/reload operations and improves performance
    }

    /**
     * Check if a URL is external
     * 
     * @param string $url The URL to check
     * @param string $home_url The home URL without trailing slash
     * @param string $home_url_lower Lowercase version of home URL
     * @return bool True if external, false if internal
     */
    private function is_external_link($url, $home_url, $home_url_lower) {
        # Empty or anchor-only links are internal
        if (empty($url) || $url === '#' || strpos($url, '#') === 0) {
            return false;
        }

        # Relative URLs (starting with /) are internal
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return false;
        }

        # Check if URL starts with home URL (case-insensitive)
        $url_lower = strtolower($url);
        if (strpos($url_lower, $home_url_lower) === 0) {
            return false;
        }

        # If it's a protocol-relative URL (//example.com), check if it matches our domain
        if (strpos($url, '//') === 0) {
            $parsed_home = parse_url($home_url);
            $parsed_link = parse_url($url);
            
            if (isset($parsed_home['host']) && isset($parsed_link['host'])) {
                if (strtolower($parsed_home['host']) === strtolower($parsed_link['host'])) {
                    return false;
                }
            }
        }

        # All other URLs are considered external
        return true;
    }

     /**
     * Add target="_blank" attribute to external links
     * Works for both buffer and HTTP rendering methods
     * Only processes links that don't already have a target attribute
     */
    function add_target_blank_to_external_links(){
        
        # find all links in the body
        $links = $this->dom->find('a');
        
        # get home URL for comparison
        $home_url = rtrim(home_url(), '/');
        $home_url_lower = strtolower($home_url);
        
        # loop through all links
        foreach ($links as $key => $link) {
            
            # get the href attribute
            $href = $link->href ?? '';
            
            # skip if href is empty or already has target attribute
            if (empty($href) || !empty($link->target)) {
                continue;
            }
            
            # check if link is external
            if ($this->is_external_link($href, $home_url, $home_url_lower)) {
                # add target="_blank" attribute
                $link->target = '_blank';
                
                # add rel="noopener noreferrer" for security
                $existing_rel = $link->rel ?? '';
                if (empty($existing_rel)) {
                    $link->rel = 'noopener noreferrer';
                } elseif (strpos($existing_rel, 'noopener') === false) {
                    $link->rel = trim($existing_rel . ' noopener noreferrer');
                }
            }
        }
        
        # Note: No need to call save_reload() here - it's called at the end of process_html_directly()
        # This avoids redundant DOM save/reload operations and improves performance
    }

    # body bottom html replacement code
    function do_body_bottom_html($insert_data){

        # check that data is availbale
        if(empty($insert_data['body_bottom_html_insertion'])){
            return;
        }

        # OPTIMIZED: Use cached body element
        $body = $this->get_cached_element('body');

        # set the link property if not empty
        if(empty($body->outertext)){
            return;
        }


        # get the tag attributes
        $attributes_string = $this->get_tag_attributes($body);

        # now do the actual html replacements
        $body->outertext = '<body' . $attributes_string . '>' . $body->innertext . $insert_data['body_bottom_html_insertion'].'</body>';

        # save the document
        $this->save_reload();
    }

    # body top html replacement
    function do_body_top_html($insert_data){

        # check that data is availbale
        if(empty($insert_data['body_top_html_insertion'])){
            return;
        }

        # OPTIMIZED: Use cached body element
        $body = $this->get_cached_element('body');

        # get the tag attributes
        $attributes_string = $this->get_tag_attributes($body);

        # now do the actual html replacements
        $body->outertext = '<body' . $attributes_string . '>'.$insert_data['body_top_html_insertion'].$body->innertext . '</body>';
    }

    # this function does the header replacements
    function do_header_replacements($replacement_data){

        # check that we have header replacements
        if(empty($replacement_data['header_replacements']) || !is_array($replacement_data['header_replacements'])){
            return;
        }

        # Check for custom SEO values from MetaSync SEO Sidebar
        # Custom values take absolute priority over OTTO suggestions
        $custom_seo_title = '';
        $custom_seo_description = '';

        if (function_exists('is_singular') && is_singular()) {
            $post_id = get_the_ID();
            if ($post_id) {
                $custom_seo_title = get_post_meta($post_id, '_metasync_seo_title', true);
                $custom_seo_description = get_post_meta($post_id, '_metasync_seo_desc', true);
            }
        }

        # now lets do the replacement work
        foreach($replacement_data['header_replacements'] AS $key => $data){

            # skip cases where type is not specified
            if(empty($data['type'])){
                continue;
            }

            # handle title - skip if custom SEO title exists
            if($data['type'] == 'title'){
                if (!empty($custom_seo_title)) {
                    # Skip title replacement - custom SEO title takes priority
                    continue;
                }

                # handle the title logic
                $this->replace_title($data);

                #
                continue;
            }

            # handle canonical links
            if($data['type'] == 'link' && $data['rel'] === 'canonical'){

                # find the cannonical dom element
                $link = $this->dom->find('link[rel="canonical"]', 0);

                # set the link property if not empty
                if(!empty($link->href)){
                    $link->href = $data['recommended_value'] ?? $link->href;
                }

                #
                continue;
            }

            # work on other elemenets not titlte
            $this->handle_meta_element($data);
        }
    }

    # function to handle meta elements other than title
    function handle_meta_element($data){

        # Check if this is a description meta tag and if custom description exists
        # Custom values take absolute priority over OTTO suggestions
        $name = $data['name'] ?? false;
        $property = $data['property'] ?? false;

        # Check for custom SEO description
        if (!empty($name) && $name === 'description') {
            if (function_exists('is_singular') && is_singular()) {
                $post_id = get_the_ID();
                if ($post_id) {
                    $custom_seo_description = get_post_meta($post_id, '_metasync_seo_desc', true);
                    if (!empty($custom_seo_description)) {
                        # Custom description exists, skip OTTO's suggestion
                        return;
                    }
                }
            }
        }

        # extract property value
        $property = $data['property'] ?? false;

        # extract name value
        $name = $data['name'] ?? false;

        # set the selector
        $meta_selector = '';

        # extend selector
        if(!empty($name)){
            $meta_selector .= 'meta[name="' . trim($name) . '"]';
        }

        # extent if property is defined
        if(!empty($property)){
            if (!empty($meta_selector)) {
                $meta_selector .= ',';
            }
            $meta_selector .= 'meta[property="' . trim($property) . '"]';
        }

        # find the meta gat in the dom
        $meta_tag = $this->dom->find($meta_selector, 0);

        # if tag not exists add it
        if(empty($meta_tag)){
            if($data['type'] == 'meta'){

                # get the attribute
                $attribute = $property ? 'property' : 'name';

                # call the create metatag function
                $result = $this->create_metatag($attribute, $data);
            }

            # return after creation
            return;
        }

        # CRITICAL FIX: Clear cache and get fresh reference
        # Preserve 'imgs' key for later use by handle_images()
        $preserved_imgs = $this->cached_elements['imgs'] ?? null;
        $this->cached_elements = [];
        if ($preserved_imgs !== null) {
            $this->cached_elements['imgs'] = $preserved_imgs;
        }

        # Get fresh meta tag reference using same selector
        $meta_tag_fresh = $this->dom->find($meta_selector, 0);

        if ($meta_tag_fresh) {
            # Use outertext for replacement
            $new_value = htmlspecialchars($data['recommended_value'] ?? '', ENT_QUOTES, 'UTF-8');

            # Determine attribute name
            $attr_name = !empty($data['name']) ? 'name' : 'property';
            $attr_value = !empty($data['name']) ? $data['name'] : ($data['property'] ?? '');

            # Build new meta tag
            $meta_tag_fresh->outertext = '<meta ' . $attr_name . '="' . htmlspecialchars($attr_value, ENT_QUOTES, 'UTF-8') . '" content="' . $new_value . '">';
        }


    }

    # function to handle the page title
    function replace_title($title_data){

        # find the title
        $title = $this->dom->find('title', 0) ?? false;

        # if none
        if($title === false){
            return $this->create_title($title_data);
        }

        # CRITICAL FIX: Clear element cache and get fresh reference
        # Preserve 'imgs' key for later use by handle_images()
        $preserved_imgs = $this->cached_elements['imgs'] ?? null;
        $this->cached_elements = [];
        if ($preserved_imgs !== null) {
            $this->cached_elements['imgs'] = $preserved_imgs;
        }

        # Get fresh title element reference
        $title_fresh = $this->dom->find('title', 0);

        if ($title_fresh) {
            # Use outertext for replacement
            $new_value = htmlspecialchars($title_data['recommended_value'], ENT_QUOTES, 'UTF-8');
            $title_fresh->outertext = '<title>' . $new_value . '</title>';
        }

        # Don't save_reload here - will happen at the end
        # $this->save_reload();
    }

    # Function to create a title when it's missing
    function create_title($title_data) {

        # Find the <head> tag
        $head = $this->dom->find('head', 0);

        # Construct the <title> tag HTML
        $title_html = '<title>' . htmlspecialchars($title_data['recommended_value'], ENT_QUOTES) . '</title>';

        if (empty($head)) {
            return false;
        }

        # Extract existing attributes of the <head> tag
        $attributes = [];

        # get the tag attributes
        $tag_attributes = $head->getAllAttributes();

        # loop all attributes
        foreach ($tag_attributes as $key => $value) {

            if ($value == 1) {

                # Handle boolean attributes
                $attributes[] = htmlspecialchars($key, ENT_QUOTES);
            } else {

                # Handle attributes with values
                $attributes[] = $key . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
            }
        }

        # Convert attributes array to a string
        $attributes_string = !empty($attributes) ? ' ' . implode(' ', $attributes) : '';

        # Rebuild the <head> tag, inserting the <title> at the beginning
        $head->outertext = '<head' . $attributes_string . '>' . $title_html . $head->innertext . '</head>';

        # save and reload DOM
        $this->save_reload();
    }

    # function to create meta tag if none existss
    function create_metatag($attribute, $data){

        # Find the <head> tag
        $head = $this->dom->find('head', 0);

        # Construct the <title> tag HTML
        $meta_tag = '<meta '.$attribute.' = "'.$data[$attribute].'" content = "'.$data['recommended_value'].'">';

        if (empty($head)) {
            return false;
        }

        # Extract existing attributes of the <head> tag
        $attributes = [];

        # get the tag attributes
        $tag_attributes = $head->getAllAttributes();

        # loop all attributes
        foreach ($tag_attributes as $key => $value) {

            if ($value == 1) {

                # Handle boolean attributes
                $attributes[] = htmlspecialchars($key, ENT_QUOTES);
            } else {

                # Handle attributes with values
                $attributes[] = $key . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
            }
        }

        # Convert attributes array to a string
        $attributes_string = !empty($attributes) ? ' ' . implode(' ', $attributes) : '';

        # Rebuild the <head> tag, inserting the <title> at the beginning
        $head->outertext = '<head' . $attributes_string . '>' . $meta_tag . $head->innertext . '</head>';

        # save and reload DOM
        $this->save_reload();
    }

    # function to detect if current page is an AMP page
    function is_amp_page(){
        
        # Check if URL path contains /amp/
        $current_url = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($current_url, '/amp/') !== false) {
            return true;
        }
        
        # Check if URL ends with /amp
        if (preg_match('/\/amp\/?$/', $current_url)) {
            return true;
        }
        
        # Check if amp=1 query parameter is present
        if (isset($_GET['amp']) && $_GET['amp'] == '1') {
            return true;
        }
        
        # Check for other common AMP query parameters
        if (isset($_GET['amp']) && !empty($_GET['amp'])) {
            return true;
        }
        
        return false;
    }

    # this function insterts header html to the dom
    function insert_header_html($data){

        # check that we have the header html
        if(empty($data['header_html_insertion'])){
            #
            return;
        }

        # append the/ html at the start of the header
        $head = $this->dom->find('head', 0);

        if ($head) {

            # Check if this is an AMP page - if so, don't add metasync_optimized attribute
            $is_amp_page = $this->is_amp_page();

            # Append the new HTML at the start of the <head> tag
            # For AMP pages: use clean <head> tag without metasync_optimized attribute
            # For non-AMP pages: add metasync_optimized attribute to <head> tag
            if ($is_amp_page) {
                $head->outertext = '<head>' .$data['header_html_insertion']. $head->innertext . '</head>';
            } else {
                $head->outertext = '<head metasync_optimized>' .$data['header_html_insertion']. $head->innertext . '</head>';
            }

        }

        # save and reload DOM
        $this->save_reload();
    }

    # function to forcefully remove metasync_optimized attribute from head on AMP pages
    function cleanup_amp_metasync_attribute(){
        
        # Only proceed if this is an AMP page
        if (!$this->is_amp_page()) {
            return;
        }
        
        # Find the head tag
        $head = $this->dom->find('head', 0);
        
        if (!$head) {
            return;
        }
        
        # Check if head has metasync_optimized attribute
        $head_html = $head->outertext;
        
        # If metasync_optimized attribute is found, remove it
        if (strpos($head_html, 'metasync_optimized') !== false) {
            
            # Remove the metasync_optimized attribute from the head tag
            # This handles various formats: <head metasync_optimized>, <head metasync_optimized=""> etc.
            $cleaned_head_html = preg_replace('/\s*metasync_optimized(?:="[^"]*")?/', '', $head_html);
            
            # Update the head element
            $head->outertext = $cleaned_head_html;
            
        }
    }

    /**
     * PERFORMANCE OPTIMIZATION: Pre-cache commonly accessed DOM elements
     * Reduces 8-10 full DOM traversals to 1 initial traversal
     * Call this once after loading HTML, before processing
     */
    private function cache_elements() {
        if (!$this->dom) {
            return;
        }

        # Cache all commonly accessed elements in one pass
        $this->cached_elements = [
            'html'      => $this->dom->find('html', 0),
            'head'      => $this->dom->find('head', 0),
            'body'      => $this->dom->find('body', 0),
            'footer'    => $this->dom->find('footer', 0),
            'title'     => $this->dom->find('title', 0),
            'imgs'      => $this->dom->find('img'),
            'links'     => $this->dom->find('a'),
            'canonical' => $this->dom->find('link[rel="canonical"]', 0),
        ];
    }

    /**
     * Get cached element by key, with fallback to DOM find
     * @param string $key Element key from cache
     * @param mixed $fallback Fallback value if not cached
     * @return mixed Cached element or fallback
     */
    private function get_cached_element($key, $fallback = null) {
        return $this->cached_elements[$key] ?? $fallback;
    }

    # this function saves are reloads the dom for modifications to avoid conflict
    function save_reload(){

        # PERFORMANCE OPTIMIZATION: Skip reload if deferred
        # This reduces multiple serialize/deserialize cycles to just one final reload
        if ($this->deferred_reload) {
            return;
        }

        # Cleanup metasync_optimized attribute on AMP pages before saving
        $this->cleanup_amp_metasync_attribute();

        # DISABLED: Cache file creation temporarily disabled

        # if(file_put_contents($this->html_file, $this->dom)){

            # load the modified file to the DOM
           # $this->dom = new HtmlDocument($this->html_file );
        # }

        # this code is to be replaced in future
        # reson for adding is to prevent caching logged in user pages
        # why not just skip saving? it broke the DOM Library
        # check user is logged in clear the file

		# if(is_user_logged_in()) {
		#	unlink($this->html_file);
		# }

        # MEMORY-BASED RELOAD: Instead of file operations, reload DOM from current HTML string
        # This prevents DOM breaking while avoiding cache file creation
        if($this->dom){
            # Get current DOM as HTML string
            $current_html = $this->dom->save();

            # Reload DOM from the HTML string to refresh internal state
            # This replaces the file save/reload cycle that SimpleHtmlDOM expects
            $this->dom->load($current_html);

            # Force UTF-8 charset after reload to preserve emojis
            $this->dom->_charset = 'UTF-8';
            $this->dom->_target_charset = 'UTF-8';
        }

    }

    /**
     * Process HTML directly without HTTP request (for buffer approach)
     * This is the FAST path - eliminates the internal wp_remote_get call
     * 
     * CRITICAL: This method is called from the output buffer callback.
     * If it fails, we must return false so the original HTML can be used.
     * 
     * @param string $html The raw HTML captured from output buffer
     * @param array $replacement_data OTTO suggestions/replacement data
     * @return HtmlDocument|false Modified DOM or false on failure
     * @since 2.6.0
     */
    function process_html_directly($html, $replacement_data) {
        try {
            # Validate inputs
            if (empty($html) || empty($replacement_data) || !is_array($replacement_data)) {
                return false;
            }

            # Validate HTML is actual HTML content
            if (stripos($html, '<html') === false && stripos($html, '<!DOCTYPE') === false) {
                return false;
            }

            # Remove XML declaration if present
            $html = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $html);

            # Load HTML into DOM
            $this->dom->load($html);

            # Force UTF-8 charset to preserve emojis and special characters
            $this->dom->_charset = 'UTF-8';
            $this->dom->_target_charset = 'UTF-8';

            # PERFORMANCE OPTIMIZATION: Pre-cache commonly accessed DOM elements
            # This eliminates 8-10 full DOM traversals per page
            $this->cache_elements();

            # PERFORMANCE OPTIMIZATION: Enable deferred reload to skip intermediate reloads
            # This reduces 6-10 DOM serialize/deserialize cycles to just 1 final reload
            $this->deferred_reload = true;

            # Apply blocking flags if available (for SEO plugin coordination)
            if (!empty($replacement_data['_otto_blocking'])) {
                $block_title = $replacement_data['_otto_blocking']['block_title'] ?? false;
                $block_description_tags = $replacement_data['_otto_blocking']['block_description_tags'] ?? [];
                
                # Remove SEO plugin meta tags if OTTO is providing them
                if ($block_title || !empty($block_description_tags)) {
                    $this->remove_conflicting_seo_tags($block_title, $block_description_tags);
                }
            }

            # Apply all OTTO modifications (same as handle_route_html but without HTTP fetch)

            # COMPATIBILITY FIX: Transform 'value' to 'recommended_value' if needed
            # Some API versions return 'value' instead of 'recommended_value'
            if (!empty($replacement_data['header_replacements'])) {
                foreach ($replacement_data['header_replacements'] as &$item) {
                    if (isset($item['value']) && !isset($item['recommended_value'])) {
                        $item['recommended_value'] = $item['value'];
                    }
                }
                unset($item); // Break reference
            }

            # 1. Header HTML insertion
            $this->insert_header_html($replacement_data);

            # 2. Header replacements (title, meta, canonical)
            $this->do_header_replacements($replacement_data);

            # 3. Body replacements (top, bottom, substitutions)
            $this->do_body_replacements($replacement_data);

            # 4. Footer HTML insertion
            $this->do_footer_html_insertion($replacement_data);

            # 5. Final cleanup for AMP pages
            $this->cleanup_amp_metasync_attribute();

            # CRITICAL FIX: Clear cached elements and force DOM to refresh
            $this->deferred_reload = false;
            $this->cached_elements = []; // Clear our custom cache

            # Clear SimpleHtmlDom's internal cache
            if (method_exists($this->dom, 'clear')) {
                $this->dom->clear();
            }


            # Try getting HTML via root element instead of save()
            $root = $this->dom->root;
            if ($root && isset($root->outertext)) {
                $result_html = $root->outertext;
            } else {
                # Fallback to save() method
                $result_html = $this->dom->save();
            }

            # DEBUG: Check if DOM changes persisted
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $result_html, $matches)) {
            }

            # Apply header replacements manually via string replacement
            if (!empty($replacement_data['header_replacements'])) {

                foreach ($replacement_data['header_replacements'] as $idx => $item) {
                    $type = $item['type'] ?? '';
                    $value = $item['recommended_value'] ?? $item['value'] ?? '';


                    if (empty($value)) {
                        continue;
                    }

                    if ($type === 'title') {
                        # Replace title tag if present; otherwise insert (e.g. when Yoast was blocked and no <title> was output)
                        $new_value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                        $title_tag = '<title>' . $new_value . '</title>';
                        $replaced = preg_replace('/<title[^>]*>.*?<\/title>/is', $title_tag, $result_html, 1);
                        if ($replaced === $result_html && strpos($result_html, '<title') === false) {
                            # No <title> in document (common when Yoast is blocked) — insert after <head>
                            $result_html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $title_tag, $result_html, 1);
                        } else {
                            $result_html = $replaced;
                        }
                    } elseif ($type === 'meta') {
                        $name = $item['name'] ?? '';
                        $property = $item['property'] ?? '';
                        $new_value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

                        if (!empty($name)) {
                            # MEMORY OPTIMIZED: Count meta tags without storing all matches
                            # preg_match_all requires $matches, so we pass it but unset immediately
                            $before_count = preg_match_all('/<meta[^>]+name=["\']' . preg_quote($name, '/') . '["\'][^>]*>/i', $result_html, $before_matches);

                            # Free memory immediately after use
                            unset($before_matches);

                            # IMPROVED FIX: Remove ALL existing meta tags with this name
                            # This pattern matches ANY meta tag with name="X" regardless of:
                            # - Attribute order (name before/after content)
                            # - Additional attributes (id, class, data-*, etc.)
                            # - Quote style (single or double quotes)
                            # - Whitespace variations
                            # The pattern uses [^>]* to match ANY characters until the closing >
                            $removed_count = preg_replace_callback(
                                '/<meta\s+[^>]*name=["\']' . preg_quote($name, '/') . '["\'][^>]*>/i',
                                function($match) {
                                    return ''; // Remove the tag
                                },
                                $result_html,
                                -1, // Remove all occurrences
                                $total_removed
                            );

                            # Update the HTML with removed tags
                            if ($total_removed > 0) {
                                $result_html = $removed_count;
                            }


                            # MEMORY OPTIMIZED: Verify removal - count again but free memory immediately
                            $after_count = preg_match_all('/<meta[^>]+name=["\']' . preg_quote($name, '/') . '["\'][^>]*>/i', $result_html, $after_matches);
                            unset($after_matches);

                            # Now insert ONE new meta tag at the TOP of <head>
                            $replacement = '<meta name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '" content="' . $new_value . '" data-otto="true">';
                            $result_html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $replacement, $result_html, 1);
                        } elseif (!empty($property)) {
                            # Replace meta property tag
                            $pattern = '/<meta\s+property=["\']' . preg_quote($property, '/') . '["\']\s+content=["\'][^"\']*["\']\s*\/?>/i';
                            $replacement = '<meta property="' . htmlspecialchars($property, ENT_QUOTES, 'UTF-8') . '" content="' . $new_value . '">';

                            if (preg_match($pattern, $result_html)) {
                                $result_html = preg_replace($pattern, $replacement, $result_html, 1);
                            } else {
                                # Meta tag doesn't exist, insert it in head
                                $result_html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $replacement, $result_html, 1);
                            }
                        }
                    } elseif ($type === 'h1' || $type === 'heading') {
                        # Replace first H1 tag
                        $new_value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                        $pattern = '/<h1[^>]*>.*?<\/h1>/is';

                        if (preg_match($pattern, $result_html)) {
                            $result_html = preg_replace($pattern, '<h1>' . $new_value . '</h1>', $result_html, 1);
                        } else {
                        }
                    }
                }
            }

            # Apply header HTML insertion (for schema, etc.)
            if (!empty($replacement_data['header_html_insertion'])) {
                $header_html = $replacement_data['header_html_insertion'];
                # Insert before </head>
                $result_html = preg_replace('/(<\/head>)/i', $header_html . "\n" . '$1', $result_html, 1);
            }

            # When Otto block has a title: ensure only ONE <title> (remove non-Otto, then keep first only)
            if (!empty($replacement_data['header_html_insertion']) && stripos($replacement_data['header_html_insertion'], '<title') !== false) {
                $result_html = preg_replace(
                    '/<title(?![^>]*data-otto-pixel\s*=\s*["\']dynamic-seo["\'])[^>]*>.*?<\/title>\s*/is',
                    '',
                    $result_html
                );
                $first = true;
                $result_html = preg_replace_callback(
                    '/<title[^>]*>.*?<\/title>\s*/is',
                    function ($m) use (&$first) {
                        if ($first) {
                            $first = false;
                            return $m[0];
                        }
                        return '';
                    },
                    $result_html
                );
            }

            # Apply body top HTML insertion
            if (!empty($replacement_data['body_top_html_insertion'])) {
                $body_top_html = $replacement_data['body_top_html_insertion'];
                # Insert after <body>
                $result_html = preg_replace('/(<body[^>]*>)/i', '$1' . "\n" . $body_top_html, $result_html, 1);
            }

            # Apply body bottom HTML insertion
            if (!empty($replacement_data['body_bottom_html_insertion'])) {
                $body_bottom_html = $replacement_data['body_bottom_html_insertion'];
                # Insert before </body>
                $result_html = preg_replace('/(<\/body>)/i', $body_bottom_html . "\n" . '$1', $result_html, 1);
            }

            # Apply footer HTML insertion
            if (!empty($replacement_data['footer_html_insertion'])) {
                $footer_html = $replacement_data['footer_html_insertion'];
                # Insert before </html>
                $result_html = preg_replace('/(<\/html>)/i', $footer_html . "\n" . '$1', $result_html, 1);
            }

            # CRITICAL FIX: Apply image alt text manually via string replacement
            # DOM changes don't persist, must use string replacement
            if (!empty($replacement_data['body_substitutions']['images']) && is_array($replacement_data['body_substitutions']['images'])) {
                foreach ($replacement_data['body_substitutions']['images'] as $image_url => $alt_text) {
                    if (empty($alt_text) || strpos($result_html, $image_url) === false) {
                        continue;
                    }

                    $escaped_alt = htmlspecialchars($alt_text, ENT_QUOTES, 'UTF-8');
                    $escaped_url = preg_quote($image_url, '/');
                    $img_pattern = '/<img[^>]*src=["\']' . $escaped_url . '["\'][^>]*>/i';

                    if (preg_match_all($img_pattern, $result_html, $img_matches)) {
                        foreach ($img_matches[0] as $original_img) {
                            # Remove ALL existing alt attributes
                            $new_img = preg_replace('/\s+alt\s*=\s*(["\'])[^"\']*\1/i', '', $original_img);
                            $new_img = preg_replace('/<img\s+alt\s*=\s*(["\'])[^"\']*\1\s*/i', '<img ', $new_img);

                            # Add single alt attribute after <img
                            $new_img = preg_replace('/^<img\s*/i', '<img alt="' . $escaped_alt . '" ', $new_img);

                            # Update data-et-multi-view JSON if present (Divi theme)
                            if (strpos($new_img, 'data-et-multi-view') !== false) {
                                $new_img = preg_replace_callback(
                                    '/data-et-multi-view="([^"]+)"/i',
                                    function($mv_matches) use ($alt_text) {
                                        $json_str = html_entity_decode($mv_matches[1], ENT_QUOTES, 'UTF-8');
                                        $json_data = json_decode($json_str, true);

                                        if ($json_data && isset($json_data['schema']['attrs'])) {
                                            foreach ($json_data['schema']['attrs'] as &$attrs) {
                                                if (array_key_exists('alt', $attrs)) {
                                                    $attrs['alt'] = $alt_text;
                                                }
                                            }
                                            unset($attrs);
                                            $new_json = json_encode($json_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                                            return 'data-et-multi-view="' . str_replace('"', '&quot;', $new_json) . '"';
                                        }
                                        return $mv_matches[0];
                                    },
                                    $new_img
                                );
                            }

                            $result_html = str_replace($original_img, $new_img, $result_html);
                        }
                    }
                }
            }

            # DEBUG: Check what we're returning
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $result_html, $matches)) {
            }

            # FINAL VERIFICATION: Count meta descriptions in returned HTML
            $final_meta_count = preg_match_all('/<meta[^>]+name=["\']description["\'][^>]*>/i', $result_html, $final_meta_matches);
            if ($final_meta_count > 0) {
                foreach ($final_meta_matches[0] as $idx => $meta) {
                }
            }

            if ($final_meta_count > 1) {
            }

            # AGGRESSIVE DUPLICATE REMOVAL: Remove any meta description without data-otto marker
            # This catches meta descriptions added by theme/plugins/custom code AFTER OTTO processing
            # Especially needed when no SEO plugin is active but theme adds meta tags

            $removal_count = 0;
            $result_html = preg_replace_callback(
                '/<meta\s+([^>]*name=["\']description["\'][^>]*)>/i',
                function($match) use (&$removal_count) {
                    # Keep only if it has data-otto="true"
                    if (stripos($match[1], 'data-otto') !== false) {
                        return $match[0]; // Keep OTTO's meta tag
                    }
                    # Remove any other meta description
                    $removal_count++;
                    return '';
                },
                $result_html
            );


            # MEMORY OPTIMIZED: Free all large objects and arrays before returning
            # This ensures memory is released immediately, especially important for high-traffic sites
            unset($final_meta_matches, $matches);

            # Clear SimpleHtmlDom internal cache to free memory
            # Note: We don't unset $this->dom as the object may be reused
            if ($this->dom && method_exists($this->dom, 'clear')) {
                $this->dom->clear();
            }

            # Clear element cache array
            $this->cached_elements = [];

            return $result_html;

        } catch (Exception $e) {
            error_log('MetaSync ' . Metasync::get_whitelabel_otto_name() . ': Exception in process_html_directly - ' . $e->getMessage());
            return false;
        } catch (Error $e) {
            error_log('MetaSync ' . Metasync::get_whitelabel_otto_name() . ': Error in process_html_directly - ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove conflicting SEO plugin meta tags from DOM
     * Called when OTTO is providing its own title/description
     * 
     * @param bool $remove_title Remove title-related tags
     * @param array|bool $remove_description_tags Array of specific description tag selectors to remove, or false/empty array for none
     * @since 2.6.0
     */
    private function remove_conflicting_seo_tags($remove_title = false, $remove_description_tags = []) {
        if (!$this->dom) {
            return;
        }

        # Find head element
        $head = $this->dom->find('head', 0);
        if (!$head) {
            return;
        }

        # Check for custom SEO values from MetaSync SEO Sidebar
        # Custom values take absolute priority over OTTO suggestions
        $has_custom_title = false;
        $has_custom_description = false;

        if (function_exists('is_singular') && is_singular()) {
            $post_id = get_the_ID();
            if ($post_id) {
                $custom_seo_title = get_post_meta($post_id, '_metasync_seo_title', true);
                $custom_seo_description = get_post_meta($post_id, '_metasync_seo_desc', true);
                $has_custom_title = !empty($custom_seo_title);
                $has_custom_description = !empty($custom_seo_description);
            }
        }

        # Handle description tags - only remove specific tags that Otto is providing
        if (!empty($remove_description_tags) && is_array($remove_description_tags) && !$has_custom_description) {
            # Remove only the specific description tags that Otto is providing
            foreach ($remove_description_tags as $selector) {
                $tags = $this->dom->find($selector);
                foreach ($tags as $tag) {
                    # Remove the tag
                    $tag->outertext = '';
                }
            }
        }

        if ($remove_title && !$has_custom_title) {
            # Remove Open Graph and Twitter title tags (keep main <title>)
            $title_selectors = [
                'meta[property=og:title]',
                'meta[name=twitter:title]',
            ];

            foreach ($title_selectors as $selector) {
                $tags = $this->dom->find($selector);
                foreach ($tags as $tag) {
                    $tag->outertext = ''; # Remove the tag
                }
            }
        }

        # Save changes
        $this->save_reload();
    }


}
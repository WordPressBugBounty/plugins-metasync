<?php

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
    private $otto_end_point = 'https://sa.searchatlas.com/api/v2/otto-url-details';
    
    # 
    function __construct($otto_uuid){

        # set the site uuid using the provided string
        $this->site_uuid = $otto_uuid;

        # laod the simple html dom parser
        $this->dom = new HtmlDocument();
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

        # Perform the GET request
        $response = wp_remote_get($url_with_params);

        # get the response body
        $body = wp_remote_retrieve_body($response);

        # Get the response code
        $response_code = wp_remote_retrieve_response_code($response);

        # if no change data skip
        if (empty($body) || $response_code !== 200){
            return false;
        }

        # set the html file path
        $this->html_file = $file_path;

        # load change data
        $change_data = json_decode($body, true);

        # send the data to the hmtl processor code
        if(!empty($body)){
            
            # return the processed route html
            return $this->handle_route_html($route, $change_data);
        }
        
        # otherwise return false
        return false;
        
    }

    # function to get tag attributes
    function get_tag_attributes($tag){

        # Extract existing attributes of the <body> tag
        $attributes = [];

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

        # lablel the Otto Route 
        # label otto requests to avoid loops
        $request_body = add_query_arg(
            [
                'is_otto_page_fetch' => 1
            ],
            $route
        );

        # error log
        #error_log('sending_labeld request : '. $route);

        # get the associateed route html
        $route_html = wp_remote_get($request_body, [
            'sslverify' => false
        ]);

        # get body
        $html_body = wp_remote_retrieve_body($route_html);

        # Get the response code
        $response_code = wp_remote_retrieve_response_code($route_html);

        # check not empty
        if(empty($html_body) || $response_code !== 200){
            
            return false;
        }

        # now that the html is not empty
        # load it into the simple html dom
        $this->dom->load($html_body);

        # now lets do the magic

        # start the header html insertion
        $this->insert_header_html($replacement_data);

        # now we do the header replacements
        $this->do_header_replacements($replacement_data);

        # now do the body replacements
        $this->do_body_replacements($replacement_data);

        # now do the footer insertions
        $this->do_footer_html_insertion($replacement_data);

        # save the document
        $this->save_reload();

        # return the dom html
        return $this->dom;
    }

    # do the footer html insertion
    function do_footer_html_insertion($replacement_data){

        # check that we have footer html
        if(empty($replacement_data['footer_html_insertion'])){
            return;
        }

        # otherwise do the replacement
        $footer = $this->dom->find('footer', 0);

        # get the tag attributes
        $attributes_string = $this->get_tag_attributes($footer);

        # now do the actual html replacements
        $footer->outertext = '<footer 7' . $attributes_string . '>' . $footer->innertext . $replacement_data['footer_html_insertion'].'</footer>';

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
    }

    # body substitutions data
    function do_body_substitutions($replacement_data){

        # check that we have an array of substitutions
        if(empty($replacement_data['body_substitutions']) || !is_array($replacement_data['body_substitutions'])){

            #
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

        # find all images in dom
        $images = $this->dom->find('img');
    
        # loop images and handle alt
        foreach($images AS $key => $image){

            # loop image data to identify matching src
            foreach ($image_data as $src => $alt_text) {
                
                # check if row src matches image srce
                if($src == $image->src){

                    # set the alt text
                    $image->alt = $alt_text;
                }
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
                if(trim($heading['current_value']) == trim($text)){

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

    # body bottom html replacement code
    function do_body_bottom_html($insert_data){

        # check that data is availbale
        if(empty($insert_data['body_bottom_html_insertion'])){
            return;
        }

        # otherwise do the replacement
        $body = $this->dom->find('body', 0);

        # get the tag attributes
        $attributes_string = $this->get_tag_attributes($body);

        # now do the actual html replacements
        $body->outertext = '<body 7' . $attributes_string . '>' . $body->innertext . $insert_data['body_bottom_html_insertion'].'</body>';

        # save the document
        $this->save_reload();
    }

    # body top html replacement
    function do_body_top_html($insert_data){

        # check that data is availbale
        if(empty($insert_data['body_top_html_insertion'])){
            return;
        }

        # otherwise do the replacement
        $body = $this->dom->find('body', 0);

        # get the tag attributes
        $attributes_string = $this->get_tag_attributes($body);

        # now do the actual html replacements
        $body->outertext = '<body 5' . $attributes_string . '>'.$insert_data['body_top_html_insertion'].$body->innertext . '</body>';
    }

    # this function does the header replacements
    function do_header_replacements($replacement_data){

        # check that we have header replacements
        if(empty($replacement_data['header_replacements']) || !is_array($replacement_data['header_replacements'])){
            return;
        }        

        # now lets do the replacement work
        foreach($replacement_data['header_replacements'] AS $key => $data){

            # skip cases where type is not specified
            if(empty($data['type'])){
                continue;
            }

            # handle title
            if($data['type'] == 'title'){

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

        # extract property value
        $property = $data['property'] ?? false;

        # extract name value
        $name = $data['name'] ?? false;

        # set the selector
        $meta_selector = '';

        # extend selector 
        if(!empty($name)){
            $meta_selector .=   "meta[name=".trim($name)."],";
        }

        # extent if property is defined
        if(!empty($property)){
            $meta_selector .= "meta[property=".trim($property)."]";
        }

        # find the meta gat in the dom
        $meta_tag = $this->dom->find($meta_selector, 0);

        # if tag not exists add it
        if(empty($meta_tag)){
            if($data['type'] == 'meta'){
            
                # get the attribute 
                $attribute = $property ? 'property' : 'name';
                
                # call the create metatag function
                $this->create_metatag($attribute, $data);
            }

            # otherwise reut
            return;
        }

        # do the replacement
        $meta_tag->content = $data['recommended_value'] ?? $meta_tag->content;
        

    }

    # function to handle the page title
    function replace_title($title_data){

        # find the title
        $title = $this->dom->find('title', 0) ?? false;

        # if none
        if($title === false){

            return $this->create_title($title_data);
        }

        # add the recommended value to the title
        $title->innertext = $title_data['recommended_value'];

        # save and reload DOM
        $this->save_reload();
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
        $head->outertext = '<head 2' . $attributes_string . '>' . $title_html . $head->innertext . '</head>';

        # save and reload DOM
        $this->save_reload();
    }

    # function to create meta tag if none exists
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
        $head->outertext = '<head 5' . $attributes_string . '>' . $meta_tag . $head->innertext . '</head>';

        # save and reload DOM
        $this->save_reload();
    }

    # this function insterts header html to the dom
    function insert_header_html($data){

        # check that we have the header html
        if(empty($data['header_html_insertion'])){
            #
            return;
        }

        # append the html at the start of the header
        $head = $this->dom->find('head', 0);

        if ($head) {

            # Append the new HTML at the start of the <head> tag
            $head->outertext = '<head metasync_optimized>' .$data['header_html_insertion']. $head->innertext . '</head>';

        }

        # save and reload DOM
        $this->save_reload();
    }

    # this function saves are reloads the dom for modifications to avoid conflict
    function save_reload(){

        # 
        if(file_put_contents($this->html_file, $this->dom)){
            
            # load the modified file to the DOM
            $this->dom = new HtmlDocument($this->html_file );
        }
    }


}
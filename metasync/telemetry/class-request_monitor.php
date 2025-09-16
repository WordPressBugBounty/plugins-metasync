<?php
/**
 * Request monitoring
 * 
 * */

class Metasync_Telemetry_Request_Monitor {

    # telemetry manager
    private $telemetry_manager;

    # construct
    function __construct(){
        $this->telemetry_manager = Metasync_Telemetry_Manager::get_instance();
    }

    /**
     * Get current timestamp for logging
     */
    private function log_timestamp() {
        return current_time('mysql');
    }

    # uri strings

    # These are sections of the uri to look for in a request
    # prevents logging unauthorized requests
    protected $loggable_uri_strings = [
        'metasync', 'searchatlas'
    ];


    /**
     * Check Loggable URI
     * This method helps us check that the uri is permitted
     * Otherwise it returns fals
     * 
     */
    function log_uri_permitted($uri) {

        # Get permitted URI strings
        $permitted_strings = $this->loggable_uri_strings;
    
        # If an asterisk is used, allow all
        if ($permitted_strings === '*') {
            return true;
        }
    
        # Check if any string is in the URI
        foreach ($permitted_strings as $string) {
            if (strpos($uri, $string) !== false) {
                return true; # Found a match
            }
        }
    
        # Return false for no matches
        return false;
    }

    # @section
    # Monitors Incoming and Outgoing Request Data

    # let's monitor outgoing logs
    function api_monitor_outgoing($preempt, $args, $url){

        # ignore wp chron requests
        if(strpos($url, 'wp-cron.php') !== false){
            return $preempt;
        }

        # check that the route is permitted for logging
        if ( empty($url) || $this->log_uri_permitted($url) == False ){
            #error_log('Rejected Outgoing : '. $url);
            return $preempt;
        }

        # prepare the request object
        $request = [
            'timestamp' => $this->log_timestamp(),
            'type' => 'outgoing_request',
            'method' => $args['method'] ?? 'undefined',
            'request_url' => $url,
            'args' => $args
        ];

        # send the request with telemetry manager
        $this->telemetry_manager->send_message('Outgoing Request', 'info', $request);

        
        # Return preempt to allow the request to continue
        return $preempt;

    }

    # monitor incoming
    function api_monitor_incoming($result, $server, $request){

        # check that the route is permitted for logging
        if ( empty($request->get_route()) || $this->log_uri_permitted($request->get_route()) == False ){
            # error_log('Rejected Incoming: '. $request->get_route());

            return False;
        }

        # prepare the reuqest

        $request_data = [
            'timestamp' => $this->log_timestamp(),
            'type' => 'incoming_request',
            'method' => $request->get_method(),
            'uri' => $request->get_route(),
            'headers' => $request->get_headers(),
            'body' => $request->get_params()
        ];

        # if the request is not GET attach get params
        if($request->get_method() !== 'GET'){

            #append request get params
            $request_data['_GET_params'] = $_GET;
        }

        $this->telemetry_manager->send_message('Incoming Request', 'info', $request_data);

        
        # Return result to allow the request to continue
        return $result;
    }

    # @section 
    # Monitors the Request Responses
    # Helps checking for errors

    # monitor the response to an outgoing API call
    # function monitor_outgoing_response($response, $context, $url, $parsed_args){
    function monitor_outgoing_response($response, $parsed_args, $url){
        
        # Skip logging for wp-cron requests
        if (strpos($url, 'wp-cron.php') !== false) {
            return $response;
        }

        # check that the route is permitted for logging
        if ( empty($url) || $this->log_uri_permitted($url) == False ){
            #error_log('Rejected Out response: '. $url .' Response'. json_encode($response));
            return $response;
        }
        
        # request data
        $response_data = [
            'timestamp' => $this->log_timestamp(),
            'type' => 'outgoing_response',
            'status_code' => $response['response']['code'] ?? '',
            'url' => $url, 
            'response_data' => $this->sanitize_outgoing_response($response),
            'args' => $this->sanitize_args($parsed_args)
        ];

        #return the response
        $this->telemetry_manager->send_message('Outgoing Response', 'info', $response_data);

        
        # Return response to allow it to continue
        return $response;
    }

    # monitor the response to an incoming api call
    function monitor_incoming_response($response, $server, $request){
        
        # check that the route is permitted for logging
        if ( empty($request->get_route()) || $this->log_uri_permitted($request->get_route()) == False ){
            # error_log('Rejected incoming response: '. $request->get_route());
            return $response;
        }

        # prepare the response object   
        $response_object = array(
            'timestamp' => $this->log_timestamp(),
            'type' => 'incoming_response',
            'status_code' => $response->get_status() ?? '',
            'url' =>  $request->get_route(),
            'method' => $request->get_method(),
            'response_data' => $this->sanitize_response_data($response), 
            'request_data' => $this->sanitize_request_data($request)
        );

        # let record the response

        $this->telemetry_manager->send_message('Incoming Response', 'info', $response_object);
        
        # Return response to allow it to continue
        return $response;

    }

    /**
     * Sanitize WP_REST_Response object for serialization
     * 
     * @param mixed $response The response object
     * @return array Sanitized response data
     */
    private function sanitize_response_data($response) {
        if (!is_object($response)) {
            return is_array($response) ? $response : array();
        }
        
        return array(
            'status' => method_exists($response, 'get_status') ? $response->get_status() : null,
            'data' => method_exists($response, 'get_data') ? $response->get_data() : null,
            'headers' => method_exists($response, 'get_headers') ? $response->get_headers() : null,
            'links' => method_exists($response, 'get_links') ? $response->get_links() : null
        );
    }
    
    /**
     * Sanitize WP_REST_Request object for serialization
     * 
     * @param mixed $request The request object
     * @return array Sanitized request data
     */
    private function sanitize_request_data($request) {
        if (!is_object($request)) {
            return is_array($request) ? $request : array();
        }
        
        return array(
            'method' => method_exists($request, 'get_method') ? $request->get_method() : null,
            'route' => method_exists($request, 'get_route') ? $request->get_route() : null,
            'params' => method_exists($request, 'get_params') ? $request->get_params() : null,
            'headers' => method_exists($request, 'get_headers') ? $request->get_headers() : null,
            'body' => method_exists($request, 'get_body') ? $request->get_body() : null
        );
    }
    
    /**
     * Sanitize outgoing HTTP response for serialization
     * 
     * @param array $response The HTTP response array
     * @return array Sanitized response data
     */
    private function sanitize_outgoing_response($response) {
        if (!is_array($response)) {
            return $response;
        }
        
        $sanitized = array();
        
        // Safely extract response data
        if (isset($response['response'])) {
            $sanitized['response'] = array(
                'code' => $response['response']['code'] ?? null,
                'message' => $response['response']['message'] ?? null
            );
        }
        
        if (isset($response['headers'])) {
            $sanitized['headers'] = $response['headers'];
        }
        
        if (isset($response['body'])) {
            $sanitized['body'] = $response['body'];
        }
        
        if (isset($response['cookies'])) {
            $sanitized['cookies'] = $response['cookies'];
        }
        
        return $sanitized;
    }
    
    /**
     * Sanitize HTTP request arguments for serialization
     * 
     * @param array $args The HTTP request arguments
     * @return array Sanitized arguments
     */
    private function sanitize_args($args) {
        if (!is_array($args)) {
            return $args;
        }
        
        $sanitized = array();
        
        // Only include safe, serializable data
        $safe_keys = array('method', 'timeout', 'redirection', 'httpversion', 'user-agent', 'reject_unsafe_urls', 'blocking', 'sslverify', 'stream', 'filename', 'decompress');
        
        foreach ($safe_keys as $key) {
            if (isset($args[$key])) {
                $sanitized[$key] = $args[$key];
            }
        }
        
        // Sanitize headers if present
        if (isset($args['headers']) && is_array($args['headers'])) {
            $sanitized['headers'] = $args['headers'];
        }
        
        // Sanitize body if present (but limit size)
        if (isset($args['body'])) {
            $body = $args['body'];
            if (is_string($body) && strlen($body) > 1000) {
                $body = substr($body, 0, 1000) . '... [truncated]';
            }
            $sanitized['body'] = $body;
        }
        
        return $sanitized;
    }

    /**
     * @see monitor_api_calls
     *   
     */
    function monitor_api_calls(){
        # log incoming requests
        add_filter('rest_pre_dispatch', [$this, 'api_monitor_incoming'],10, 3);
        
        # add hook to monitor outgoing api requests
        add_filter('pre_http_request', [$this, 'api_monitor_outgoing'], 1, 3);
        
        # Hook to monitor outgoing API responses
        add_action('http_response', [$this, 'monitor_outgoing_response'], 10, 4);
        
        # Monitor incoming request responses
        add_filter('rest_post_dispatch', [$this, 'monitor_incoming_response'], 10, 3);
        
    }
}
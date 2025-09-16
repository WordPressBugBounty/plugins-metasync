<?php
/**
 * @see Logging Architecture
 * 
 * The Internal Request Monitoring Class
 * This class provides information on incoming
 * and outgoing API requests, focuses on those with problems
 * but can also fetch all requests and associated responses
 * 
 * @see Request Logs Format
 * We'll use NDJSON
 */

class Request_monitor extends Log_manager{

    # request file paths
    protected $incoming_log_file = 'incoming.log';

    # outgoing requests log p
    protected $outgoing_log_file = 'outgoing.log';

    # uri strings
    # Thes are sections of the uri to look for in a request
    # prevents logging unauthorized requests
    protected $loggable_uri_strings = [
        'metasync', 'searchatlas'
    ];

    # construct
    function __construct(){
        #call the parent construct
        parent::__construct();

        # ensure log directory and files are accessible
        $this->ensure_log_files_accessible();
    }

    /**
     * Ensure log files are accessible and can be written to
     */
    private function ensure_log_files_accessible(){
        
        # Check if logs directory exists and is writable
        if(!is_dir($this->metasync_logs_path) || !is_writable($this->metasync_logs_path)){
            error_log('MetaSync Request Monitor: Log directory not accessible: ' . $this->metasync_logs_path);
            return false;
        }

        # Create log files if they don't exist
        $log_files = array(
            $this->metasync_logs_path . $this->incoming_log_file
        );
        
        # Only create outgoing log file if outgoing logging is enabled
        if ($this->is_outgoing_log_enabled()) {
            $log_files[] = $this->metasync_logs_path . $this->outgoing_log_file;
        }

        foreach($log_files as $log_file){
            if(!file_exists($log_file)){
                $file = fopen($log_file, 'a');
                if($file){
                    fwrite($file, "# MetaSync Log File Created: " . date('Y-m-d H:i:s') . PHP_EOL);
                    fclose($file);
                } else {
                    error_log('MetaSync Request Monitor: Failed to create log file: ' . $log_file);
                }
            }
        }

        return true;
    }

    # time stamp function
    function log_timestamp(){
        return date('[d-M-Y H:i:s]');
    }

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

        # check if outgoing logging is enabled
        if (!$this->is_outgoing_log_enabled()) {
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

        # create the file path
        $outgoing_log_path = $this->metasync_logs_path.$this->outgoing_log_file;

        # lets append to the log path
        $file = fopen($outgoing_log_path, 'a');

        # if the file opened successfully
        if($file){
            #append the log line
            fwrite($file, json_encode($request).PHP_EOL);
            
            # close the file
            fclose($file);
        } else {
            # log the error if file couldn't be opened
            error_log('MetaSync Request Monitor: Failed to open outgoing log file: ' . $outgoing_log_path . ' - Check file permissions');
        }

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

        # now log the request
        # create the file path
        $incoming_log_path = $this->metasync_logs_path.$this->incoming_log_file;

        # open the file
        $file = fopen($incoming_log_path, 'a');

        # if file opened successfully
        if($file){

            #append log line to file
            fwrite($file, json_encode($request_data).PHP_EOL);
            
            # close the file
            fclose($file);
        } else {
            # log the error if file couldn't be opened
            error_log('MetaSync Request Monitor: Failed to open log file: ' . $incoming_log_path . ' - Check file permissions');
        }

        return False;
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

        # check if outgoing logging is enabled
        if (!$this->is_outgoing_log_enabled()) {
            return $response;
        }
        
        # request data
        $response_data = [
            'timestamp' => $this->log_timestamp(),
            'type' => 'outgoing_response',
            'status_code' => $response['response']['code'] ?? '',
            'url' => $url, 
            'response' => $response,
            'args' => $parsed_args
        ];

        # lets record the reponse in the outgoing file
        # create the file path
        $outgoing_log_path = $this->metasync_logs_path.$this->outgoing_log_file;

        # lets append to the log path
        $file = fopen($outgoing_log_path, 'a');

        # if the file opened successfully
        if($file){
            #append the log line
            fwrite($file, json_encode($response_data).PHP_EOL);
            
            # close the file
            fclose($file);
        } else {
            # log the error if file couldn't be opened
            error_log('MetaSync Request Monitor: Failed to open outgoing log file: ' . $outgoing_log_path . ' - Check file permissions');
        }

        #return the response
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
            'response' => $response, 
            'request' => $request
        );

        # let record the response

        # now log the request
        # create the file path
        $incoming_log_path = $this->metasync_logs_path.$this->incoming_log_file;

        # open the file
        $file = fopen($incoming_log_path, 'a');

        # if file opened successfully
        if($file){

            #append log line to file
            fwrite($file, json_encode($response_object).PHP_EOL);
            
            # close the file
            fclose($file);
        } else {
            # log the error if file couldn't be opened
            error_log('MetaSync Request Monitor: Failed to open incoming log file: ' . $incoming_log_path . ' - Check file permissions');
        }

        return $response;
    }
}
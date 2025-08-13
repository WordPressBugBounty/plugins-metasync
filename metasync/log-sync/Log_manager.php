<?php
/**
 * @see Logging Architecture
 * 
 * The Log_Manager Class
 * This will help handle 
 * One of the following depending on settings
 *  - Extracting plugin specific logs
 *  - Extracting General site logs
 * 
 * @var wp_option : Metasync_logging
 * The logging settings will be stored as a separate option in the DB
 * 
 * @
 */

Class Log_Manager{
    # wordpress log path
    protected $wp_log_path; 

    # plugin logs path
    protected $metasync_logs_path;

    # current debug log file for data
    protected $plugin_error_log;

    # the logging option wp
    protected $logging_options;

    # the logging option name
    protected $logging_option_name = 'metasync_logging_data';

    # log settings
    protected $log_settings;

    # log sort chron name
    protected $log_sort_chron;

    # log upload chron name
    protected $log_upload_chron;

    function __construct(){
        # set the wp debug path
        $this->load_wp_debug_path();

        # Enforce error logging dir
        $this->enforce_errorlog_dir();

        # Set the logging optoin 
        $options = get_option($this->logging_option_name);

        #set the options
        $this->logging_options = $options;

        #ensure array
        if(!is_array($options)){
            $this->logging_options = [];
        }
    }

    # app log function
    function app_log($message){

        #log the message
        error_log($message);
    }

    # Enforce plugin log dir
    function enforce_errorlog_dir(){

        # Check we have the Content Dir Const
        if( !defined('WP_CONTENT_DIR')){
            
            return;
        }

        # now set the desired folder path
        $metasync_logs_path = WP_CONTENT_DIR. '/metasync_data/';

        # if not dir make dir recursive
        if(!is_dir($metasync_logs_path)){

            # create the dir
            if(!mkdir($metasync_logs_path, 0777, true)){

                #failed create log message
                $this->app_log('Metasync : Failed to Create Log Dir');

                return;
            }
        }

        # set the metasync logs path
        $this->metasync_logs_path = $metasync_logs_path;

        # create the log file name 
        $this->plugin_error_log = $this->metasync_logs_path . '/plugin_errors.log';

    }

    # store the loging option in db
    function logging_options($save_ = false){
        
        # if we are not saving sth retrieve the option
        if($save_ == false){
            return $this->logging_options;
        }  

        # otherwise save the option data provided
        update_option($this->logging_option_name, $this->logging_options);
    }

    /**
     * Tracking the last checked log line's time
     * This is to help prevent appending duplicate lines to the log
     */
    function track_last_logline($line_time = false){
        # if the line time is false return the last log line time
        if($line_time == false){
            
            #update this to actual last line time
            return $this->logging_options()['last_debug_line'] ?? 0;
        }

        # save the last line 
        $this->logging_options['last_debug_line'] = $line_time;

        #save
        $this->logging_options(true);


        $this->app_log('Metasync Last Log Time' . date('d-m-Y H:i:s', $line_time));
    }

    # write log line method
    # this will help us in case we need to determine what lines should be written or not
    function write_error_log_line($write_log, $line){
        
        # log line conditin let's defaul to on
        $log_plugin_specific = True;

        # 
        if(!empty($this->log_settings['only_plugin_specific'])){

            # 
            $log_plugin_specific = $this->log_settings['only_plugin_specific'];
        }

        # Define regex pattern to match "metasync" with or without backslashes
        $pattern = '/metasync/i';

        # Check if the line contains "metasync"
        if ($log_plugin_specific == true && preg_match($pattern, $line)) {
            fwrite($write_log, $line);
            return;
        }

        # if we need to log every line
        if($log_plugin_specific == False){

            # otherwise write any lines
            fwrite($write_log, $line);
            return;
        }
    }

    # fetch the debug log path
    function load_wp_debug_path(){
        #
        $extracted_path = False;

        #check content dir
        if( defined('WP_CONTENT_DIR')){

            # extracted path
            $extracted_path = WP_CONTENT_DIR . '/debug.log';
        }

        #check if the debug is enabled 
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG){
            
            #check if it is a string 
            if(is_string(WP_DEBUG_LOG)){

                #
                $extracted_path = WP_DEBUG_LOG;
            }

        }

        #if no debug file path return
        if($extracted_path == False || !is_file($extracted_path)){

            #
            return False;
        }

        #set the wp_log_path prop to path
        $this->wp_log_path = $extracted_path;
    }

    /**
     * Method : Process_debug
     * 
     * @param plugin_specific : Default True
     * True : This specifies on whether to process the plugin specific logs or 
     * False : Fetch the whole debug log
     *
     * @return bool
     * 
     */
    function process_debug(){

        #get the debug log path
        if(!is_file($this->wp_log_path)){
            
            #log degbug not defined
            $this->app_log('Debug File Not Exists');
            
            return false;
        }

        # read file contents
        $debug_file = fopen($this->wp_log_path, 'r');

        # write to log date file 
        $write_log = fopen($this->plugin_error_log, 'a');

        # track line time to get the last
        $last_line_time = $this->track_last_logline();

        # check that we read a file
        if(!$debug_file){
            #
            $this->app_log('Error Reading Debug File');
            return false;
        }
        
        # loop lines
        # read line by line to prevent memory exhaustion for large files
        while(($line = fgets($debug_file)) !== false){

            # check data pattern
            $pattern = '/\[(.*?)\]/';

            # pre date string
            $date_string = '';

            #match date string pattern
            if(preg_match($pattern, $line, $matches)){
                
                # get the datestring
                $date_string = $matches[1] ?? false;
            }

            # to time stamp
            $time = strtotime($date_string);

            # conver to timestamp
            if($time > $last_line_time){

                #call the write line method 
                $this->write_error_log_line($write_log, $line);

                #record the time
                $last_line_time = $time;

            }

        }

        # track last log line
        $this->track_last_logline($last_line_time);

        #close the log files
        fclose($debug_file);
        fclose($write_log);
    }

    /**
     * Method : Create Zip
     * @param : none
     * Helps create a zip file of the available logs
     */
    function create_zip() {
        
        # Logs path
        $logs_path = $this->metasync_logs_path;

        # Child folder for the ZIP file
        $zip_folder = $logs_path . '/zipped_logs';

        # Ensure the folder exists
        if (!file_exists($zip_folder)) {
            mkdir($zip_folder, 0755, true);
        }

        # zip name 
        $f_name = date('d-m-y-H-i');

        # Path for the ZIP file
        $zip_file = $zip_folder . '/logs_' .$f_name. '.zip';

        # Initialize ZIP archive
        $zip = new ZipArchive();

        # Try to open or create the ZIP file
        if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            error_log('Could not create ZIP file at ' . $zip_file);
            return false;
        }

        # track zipped files 
        $files_zipped = [];

        # Loop through the .log files in the directory
        foreach (glob($logs_path . '/*') as $file) {
            # Skip directories and already zipped files
            if (is_dir($file) || pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                continue;
            }

            # Add the file to the ZIP archive
            $file_name = basename($file); // Get only the file name
            $zip->addFile($file, $file_name);

            # Track the file
            $files_zipped[] = $file;
        }

        # Close the ZIP file
        $zip->close();

        # Return the path to the created ZIP file
        return [
            'zip' => $zip_file,
            'files' => $files_zipped   
        ];
    }

    /**
     * Upload Latest zip fn
     * This uploads the day's log file
     * 
     */

    function upload_latest_zip() {

        # Logs path
        $logs_path = $this->metasync_logs_path;

        # Child folder for the ZIP files
        $zip_folder = $logs_path . '/zipped_logs';

        # Today's date
        $today = date('d-m-y');

        # Find the latest ZIP file for today
        $latest_zip_file = '';
        $latest_time = 0;

        # latest file name 
        $l_file_name = '';

        if (is_dir($zip_folder)) {
            $files = scandir($zip_folder);

            foreach ($files as $file) {
                # Check if the file matches today's pattern and is a ZIP file
                if (strpos($file, "logs_$today") === 0 && pathinfo($file, PATHINFO_EXTENSION) === 'zip') {
                    $file_path = $zip_folder . '/' . $file;

                    # Get the file's modification time
                    $file_time = filemtime($file_path);

                    # Update the latest file if it's the most recent
                    if ($file_time > $latest_time) {
                        $latest_time = $file_time;
                        $latest_zip_file = $file_path;

                        # set latest file naem
                        $l_file_name = $file;
                    }
                }
            }
        }

        # If no file found, exit
        if (empty($latest_zip_file) || !is_file($latest_zip_file)) {
            error_log('Metasync : No ZIP file found for today.');
            return;
        }

        # get site url
        $site_url = preg_replace('#^https?://#', '', home_url());

        # get the log upload endpoint
        $upload_endpoint = 'https://wp-logger.api.searchatlas.com/upload/' . $site_url;
        
        $curl = curl_init();

        # curl datra
        $curl_data = array(
            CURLOPT_URL => $upload_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => array('file'=> new CURLFILE($latest_zip_file)),
            CURLOPT_HTTPHEADER => array(
              'x-filename: '.$l_file_name
            ),
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
        );

        curl_setopt_array($curl, $curl_data);

        # Execute the request
        $response = curl_exec($curl);

        # Check for errors
        if ($response === false) {
            $error = curl_error($curl);
            error_log("Metasync Upload Zip : cURL Error: $error");
        }

        # Close the cURL handle
        curl_close($curl);

    }

    /**
     * Clean up old ZIP log files
     * @param int $days_to_keep Number of days to keep ZIP files (default: 30)
     */
    function cleanup_old_zip_files($days_to_keep = 30) {

        # Define the path to the zipped_logs folder
        $zip_folder = $this->metasync_logs_path . '/zipped_logs';
        
        # Exit early if the zipped_logs directory doesn't exist
        if (!is_dir($zip_folder)) {
            return;
        }
        
        # Calculate the cutoff time: current time minus the number of seconds in $days_to_keep days
        $cutoff_time = time() - ($days_to_keep * 24 * 60 * 60);

        # Counter to keep track of how many files are deleted
        $files_deleted = 0;
        
        # Get all files in the zipped_logs directory
        $files = scandir($zip_folder);
        foreach ($files as $file) {

            # Build the full file path
            $file_path = $zip_folder . '/' . $file;
            
            # Check if:  It is a file (not a folder), has a .zip extension, name starts with "logs_", modification time is older than the cutoff time
            if (is_file($file_path) && 
            pathinfo($file, PATHINFO_EXTENSION) === 'zip' && 
            strpos($file, 'logs_') === 0 && 
            filemtime($file_path) < $cutoff_time)
            {
                # delete the file
                if (unlink($file_path)) {
                    $files_deleted++;
                }
            }
        }
        # Log the number of deleted files
        error_log("Metasync: Deleted $files_deleted old ZIP log files (older than $days_to_keep days)");
    }
}
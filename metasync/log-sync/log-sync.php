<?php
/**
 * The Log Sync Main File
 * Handles the classes 
 * 
 */

require_once plugin_dir_path(__FILE__) . 'libraries/action-scheduler-trunk/action-scheduler.php';
require_once plugin_dir_path(__FILE__) . 'Log_manager.php';
require_once plugin_dir_path(__FILE__) . 'Request_monitor.php';
require_once plugin_dir_path(__FILE__) . 'Site_data.php';

/**
 * Schedule Chron Events
 * Event 1 : Collect logs : Log_Manager->process_debug()
 * Event 2 : Zip Logs : Log_Manager->zip_logs();
 * Event 2 : Upload Logs : Log_Manager->upload();
 */

# function to schedule the log preparation chron 
function metasync_schedule_log_prep_chron() {

    # define log pre interval
    $log_chron_interval = 3600 * 12;

    # check if chron already prepared
    if (false === as_next_scheduled_action('metasync_log_preparation')) {

        as_schedule_recurring_action(time(), $log_chron_interval, 'metasync_log_preparation');
    }
}

# calls the log prep function
function metasync_execute_metasync_log_preparation() {
    
    #error_log('runing');

    # initialize the log prep function
    $log_manager = new Log_Manager();

    # run the log pre method
    $log_manager->process_debug();

    # &&
    
    # init class
    $site_data = new Site_data();

    # call the site data log prep function
    $site_data->save_site_data();

    # bundle up the log data into a zip file for the day
    $file_data = $log_manager->create_zip();

    # if zip is success delete the current log files
    if(!empty($file_data['zip']) AND is_file($file_data['zip'])){

        #loop and delte
        foreach ($file_data['files'] as $file) {

            # if exists unlink
            if (file_exists($file)) {
                unlink($file);
            }
        }
        # Clean up old ZIP files (keep last 30 days)
        # Temporary hotfix to delete old ZIP files
        $log_manager->cleanup_old_zip_files(0);
    }
}

# schedule zip upload chron
function metasync_schedule_zip_upload_chron(){
    
    # upload chron interval to 12 hourly
    $upload_chron_interval = 3600 * 12;

    # check if chron already prepared
    if (false === as_next_scheduled_action('metasync_log_upload')) {

        as_schedule_recurring_action(time(), $upload_chron_interval, 'metasync_log_upload');
    }
}

function metasync_do_log_upload(){

    # initialize the log prep function
    $log_manager = new Log_Manager();

    # run the log pre method
    $log_manager->upload_latest_zip();
}

# initialize chrons function
function metasync_initialize_metasync_chron_jobs(){
    
    # check that we have the action scheduler function
    if(function_exists('as_next_scheduled_action') && function_exists('as_schedule_recurring_action')){

        # schedule the log prep chron
        metasync_schedule_log_prep_chron();

        # schedule zip upload chron
        metasync_schedule_zip_upload_chron();
    }
    else{
        error_log('Action Scheduler Functions not Existent');
    }
}

# initialize log chron
add_action('init', 'metasync_initialize_metasync_chron_jobs');

# hook to the log preparation schedule
add_action('metasync_log_preparation', 'metasync_execute_metasync_log_preparation');

# Backward compatibility: Support old function name for existing scheduled actions
if (!function_exists('execute_metasync_log_preparation')) {
    function execute_metasync_log_preparation() {
        metasync_execute_metasync_log_preparation();
    }
}

# hook onto the log upload action
add_action('metasync_log_upload', 'metasync_do_log_upload');

/**
 * @see monitor_api_calls
 *   
 */
function metasync_monitor_api_calls(){
    $app = new Request_monitor();

    # log incoming requests
    add_filter('rest_pre_dispatch', [$app, 'api_monitor_incoming'],10, 3);
    
    # add hook to monitor outgoing api requests
    add_filter('pre_http_request', [$app, 'api_monitor_outgoing'], 1, 3);
    
    # Hook to monitor outgoing API responses
    add_action('http_response', [$app, 'monitor_outgoing_response'], 10, 4);
    
    # Monitor incoming request responses
    add_filter('rest_post_dispatch', [$app, 'monitor_incoming_response'], 10, 3);
    
}

/**
 * Monitor api requests 
 * This is done to assist debuggin
 */

metasync_monitor_api_calls();
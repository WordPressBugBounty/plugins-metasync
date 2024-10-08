<?php

/**
 * The Urls Redirection functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @link       http://linkgraph.io
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/redirections
 * @author     Shah Rukh Khan <shahrukh@linkgraph.io>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Redirection
{

    private $db_redirection;
    private $common;

    public function __construct(&$db_redirection)
    {
        $this->db_redirection = $db_redirection;
        $this->common = new Metasync_Common();
    }

    function contains($haystack, $needle, $caseSensitive = false)
    {
        return $caseSensitive ?
            (strpos($haystack, $needle) === FALSE ? FALSE : TRUE) : (stripos($haystack, $needle) === FALSE ? FALSE : TRUE);
    }

    public function create_admin_redirection_interface()
    {

        if (!class_exists('WP_List_Table')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
        }
        require dirname(__FILE__, 2) . '/redirections/class-metasync-redirection-list-table.php';

        $MetasyncRedirection = new Metasync_Redirection_List_Table();

        $MetasyncRedirection->setDatabaseResource($this->db_redirection);

        $MetasyncRedirection->prepare_items();

        // Include the view markup.
        include dirname(__FILE__, 2) . '/views/metasync-redirection.php';
    }

    public function get_current_page_url()
    {
        $server_data =  sanitize_post($_SERVER);
        $link = '://' . $server_data['HTTP_HOST'] . $server_data['REQUEST_URI'];
        $link = (is_ssl() ? 'https' : 'http') . $link;
        return sanitize_url($link);
    }

    public function source_url_redirection(object $row, string $uri)
    {
        $source_urls = is_array(unserialize($row->sources_from)) ? unserialize($row->sources_from) : [];

        foreach ($source_urls as $source_key => $source_value) {

            switch ($source_value) {
                case 'exact':

                    if ($source_key === $uri) {

                        $this->db_redirection->update_counter($row);

                        if ($row->http_code === '410') {
                            status_header(410);
                            die;
                        }
                        if ($row->http_code === '451') {
                            status_header(451, 'Unavailable For Legal Reasons');
                            die;
                        }
                        if ($row->url_redirect_to) {
                            wp_redirect($row->url_redirect_to, $row->http_code);
                            die;
                        }
                    }
                    break;
                case 'contain':
                    if ($this->contains($source_key, $uri)) {
                        $this->db_redirection->update_counter($row);

                        if ($row->http_code === '410') {
                            status_header(410);
                            die;
                        }
                        if ($row->http_code === '451') {
                            status_header(451, 'Unavailable For Legal Reasons');
                            die;
                        }
                        if ($row->url_redirect_to) {
                            wp_redirect($row->url_redirect_to, $row->http_code);
                            die;
                        }
                    }
                    break;
                case 'start':
                    if (str_starts_with($source_key, $uri)) {
                        $this->db_redirection->update_counter($row);

                        if ($row->http_code === '410') {
                            status_header(410);
                            die;
                        }
                        if ($row->http_code === '451') {
                            status_header(451, 'Unavailable For Legal Reasons');
                            die;
                        }
                        if ($row->url_redirect_to) {
                            wp_redirect($row->url_redirect_to, $row->http_code);
                            die;
                        }
                    }
                    break;
                case 'end':
                    if (str_ends_with($source_key, $uri)) {
                        $this->db_redirection->update_counter($row);

                        if ($row->http_code === '410') {
                            status_header(410);
                            die;
                        }
                        if ($row->http_code === '451') {
                            status_header(451, 'Unavailable For Legal Reasons');
                            die;
                        }
                        if ($row->url_redirect_to) {
                            wp_redirect($row->url_redirect_to, $row->http_code);
                            die;
                        }
                    }
                    break;
                default:
                    return false;
                    break;
            }
        }
    }

  
}

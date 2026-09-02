<?php

if (!defined('WPINC')) {
    die;
}

/**
 * Determine whether telemetry is disabled before initializing handlers or sending.
 */
function metasync_telemetry_is_disabled()
{
    if (defined('METASYNC_DISABLE_TELEMETRY') && constant('METASYNC_DISABLE_TELEMETRY')) {
        return true;
    }

    if (function_exists('get_option')) {
        $options = get_option('metasync_options', array());
        if (is_array($options) && !empty($options['disable_telemetry'])) {
            return true;
        }
    }

    return false;
}

/**
 * Return only the path component of a request URI or URL.
 */
function metasync_telemetry_request_path($request_uri = null)
{
    if ($request_uri === null) {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    }

    if (!is_string($request_uri) || $request_uri === '') {
        return 'unknown';
    }

    $path = parse_url($request_uri, PHP_URL_PATH);

    return is_string($path) && $path !== '' ? $path : '/';
}

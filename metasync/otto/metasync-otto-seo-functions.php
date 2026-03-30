<?php
/**
 * OTTO SEO Integration Functions
 * 
 * This file contains all the supporting functions for OTTO SEO data processing
 * and SEO plugin integration.
 * 
 * @package MetaSync
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fetch SEO data from OTTO API
 * 
 * @param string $route The URL route
 * @param string $uuid The OTTO UUID
 * @return array|false SEO data array or false on failure
 */
function metasync_fetch_otto_seo_data($route, $uuid) {
    $route = esc_url_raw($route);

    # Get OTTO API endpoint (use endpoint manager if available)
    $api_endpoint = 'https://sa.searchatlas.com/api/v2/otto-url-details'; # default
    if (class_exists('Metasync_Endpoint_Manager')) {
        $api_endpoint = Metasync_Endpoint_Manager::get_endpoint('OTTO_URL_DETAILS');
    }

    # Construct OTTO API endpoint URL
    $api_url = add_query_arg(
        array(
            'url' => $route,
            'uuid' => $uuid
        ),
        $api_endpoint
    );

    # Make API request with timeout and error handling
    $response = wp_remote_get($api_url, array(
        'timeout' => 30,
        'sslverify' => true,
        'headers' => array(
            'User-Agent' => 'MetaSync-WordPress-Plugin/1.0'
        )
    ));

    # Check for HTTP errors
    if (is_wp_error($response)) {
        return false;
    }

    # Check response code
    $response_code = wp_remote_retrieve_response_code($response);

    if ($response_code === 429) {
        metasync_record_otto_rate_limit_hit();
    }

    if ($response_code !== 200) {
        return false;
    }

    # Parse response body
    $body = wp_remote_retrieve_body($response);
    if (empty($body)) {
        return false;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return false;
    }

    return $data;
}

/**
 * Record a SearchAtlas OTTO_URL_DETAILS API rate limit hit (HTTP 429)
 *
 * Appends the current Unix timestamp to a rolling log stored in a WordPress option.
 * Entries older than 48 hours are pruned on every write to prevent unbounded growth.
 * The log is read by the Site Health rate limit check to surface frequent throttling
 * to site administrators.
 *
 * @since    1.0.0
 */
function metasync_record_otto_rate_limit_hit() {
    $log    = get_option('metasync_otto_rate_limit_log', []);
    $cutoff = time() - (48 * HOUR_IN_SECONDS);
    $log    = array_values(array_filter($log, function($t) use ($cutoff) { return $t > $cutoff; }));
    $log[]  = time();
    update_option('metasync_otto_rate_limit_log', $log, false);
}

/**
 * Record a failed MetaSync cron action
 *
 * Appends the current Unix timestamp to a rolling log stored in a WordPress option.
 * Entries older than 48 hours are pruned on every write to prevent unbounded growth.
 * The log is read by the Site Health failed actions check.
 *
 * @since    1.0.0
 * @param    string    $hook    The cron hook name that failed (optional, for context)
 */
function metasync_record_failed_action( $hook = '' ) {
    $log    = get_option( 'metasync_failed_actions_log', [] );
    $cutoff = time() - ( 48 * HOUR_IN_SECONDS );
    $log    = array_values( array_filter( $log, function( $t ) use ( $cutoff ) { return $t > $cutoff; } ) );
    $log[]  = time();
    update_option( 'metasync_failed_actions_log', $log, false );
}

/**
 * Clean SEO plugin template variables from text
 * Removes variables like %%title%%, %%sep%%, %%sitename%%, etc.
 *
 * @param string $text The text to clean
 * @return string Cleaned text
 */
function metasync_clean_seo_variables($text) {
    if (empty($text)) {
        return $text;
    }

    # Remove Yoast/RankMath template variables (%%variable%%)
    $text = preg_replace('/%%[^%]+%%/i', '', $text);

    # Remove multiple spaces created by removal
    $text = preg_replace('/\s+/', ' ', $text);

    # Remove leading/trailing separators and spaces
    $text = trim($text, ' |-–—');

    # Clean up any remaining double separators
    $text = preg_replace('/\s*[-|–—]\s*[-|–—]\s*/', ' - ', $text);

    return trim($text);
}

/**
 * Extract meta title from OTTO response data
 *
 * @param array $seo_data The SEO data from OTTO API
 * @return string|null The meta title or null if not found
 */
function metasync_extract_meta_title($seo_data) {
    # First check header replacements for title
    if (!empty($seo_data['header_replacements']) && is_array($seo_data['header_replacements'])) {
        foreach ($seo_data['header_replacements'] as $replacement) {
            if (isset($replacement['type']) && $replacement['type'] === 'title'
                && !empty($replacement['recommended_value'])) {
                $title = sanitize_text_field($replacement['recommended_value']);
                # Clean SEO plugin variables
                $title = metasync_clean_seo_variables($title);
                # Only return if we have actual content after cleaning
                return !empty($title) ? $title : null;
            }
        }
    }

    # Check header_html_insertion for title tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];

        # Look for title tag with data-otto-pixel attribute
        if (preg_match('/<title[^>]*data-otto-pixel=["\']dynamic-seo["\'][^>]*>([^<]+)<\/title>/i', $html_content, $matches)) {
            $title = sanitize_text_field(trim($matches[1]));
            # Clean SEO plugin variables
            $title = metasync_clean_seo_variables($title);
            # Only return if we have actual content after cleaning
            return !empty($title) ? $title : null;
        }

        # Fallback: look for any title tag
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html_content, $matches)) {
            $title = sanitize_text_field(trim($matches[1]));
            # Clean SEO plugin variables
            $title = metasync_clean_seo_variables($title);
            # Only return if we have actual content after cleaning
            return !empty($title) ? $title : null;
        }
    }

    return null;
}

/**
 * Extract meta description from OTTO response data
 *
 * @param array $seo_data The SEO data from OTTO API
 * @return string|null The meta description or null if not found
 */
function metasync_extract_meta_description($seo_data) {
    # First check header replacements for meta description
    if (!empty($seo_data['header_replacements']) && is_array($seo_data['header_replacements'])) {
        foreach ($seo_data['header_replacements'] as $replacement) {
            $is_meta_type = isset($replacement['type']) && $replacement['type'] === 'meta';
            $has_description_name = isset($replacement['name']) && $replacement['name'] === 'description';
            $has_og_description = isset($replacement['property']) && $replacement['property'] === 'og:description';
            $has_value = !empty($replacement['recommended_value']);

            if ($is_meta_type && ($has_description_name || $has_og_description) && $has_value) {
                $description = sanitize_textarea_field($replacement['recommended_value']);

                # Clean SEO plugin variables
                $description = metasync_clean_seo_variables($description);

                # Only return if we have actual content after cleaning
                if (!empty($description)) {
                    return $description;
                }
            }
        }
    }

    # Check header_html_insertion for meta description tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];

        # Look for meta description tag with data-otto-pixel attribute
        if (preg_match('/<meta\s+name=["\']description["\']\s+[^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html_content, $matches)) {
            $description = sanitize_textarea_field($matches[1]);
            # Clean SEO plugin variables
            $description = metasync_clean_seo_variables($description);

            # Only return if we have actual content after cleaning
            if (!empty($description)) {
                return $description;
            }
        }
    }

    return null;
}

/**
 * Extract meta keywords from OTTO response data
 * 
 * @param array $seo_data The SEO data from OTTO API
 * @return string|null The meta keywords or null if not found
 */
function metasync_extract_meta_keywords($seo_data) {
    # Check header_html_insertion for meta keywords tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];
        
        # Look for meta keywords tag with data-otto-pixel attribute
        if (preg_match('/<meta\s+name=["\']keywords["\']\s+[^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html_content, $matches)) {
            return sanitize_text_field($matches[1]);
        }
    }
    
    return null;
}

/**
 * Extract Open Graph title from OTTO response data
 *
 * @param array $seo_data The SEO data from OTTO API
 * @return string|null The OG title or null if not found
 */
function metasync_extract_og_title($seo_data) {
    # Check header replacements for OG title
    if (!empty($seo_data['header_replacements']) && is_array($seo_data['header_replacements'])) {
        foreach ($seo_data['header_replacements'] as $replacement) {
            if (isset($replacement['type']) && $replacement['type'] === 'meta'
                && isset($replacement['property']) && $replacement['property'] === 'og:title'
                && !empty($replacement['recommended_value'])) {
                $og_title = sanitize_text_field($replacement['recommended_value']);
                # Clean SEO plugin variables
                $og_title = metasync_clean_seo_variables($og_title);
                # Only return if we have actual content after cleaning
                return !empty($og_title) ? $og_title : null;
            }
        }
    }

    # Fallback: Check header_html_insertion for og:title meta tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];

        # Match <meta property="og:title" content="..."> (property and content in any order)
        if (preg_match('/<meta\s+[^>]*property=["\']og:title["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:title["\'][^>]*>/i', $html_content, $matches)) {
            $og_title = sanitize_text_field(trim($matches[1]));
            $og_title = metasync_clean_seo_variables($og_title);
            return !empty($og_title) ? $og_title : null;
        }
    }

    return null;
}

/**
 * Extract Open Graph description from OTTO response data
 *
 * @param array $seo_data The SEO data from OTTO API
 * @return string|null The OG description or null if not found
 */
function metasync_extract_og_description($seo_data) {
    # Check header replacements for OG description
    if (!empty($seo_data['header_replacements']) && is_array($seo_data['header_replacements'])) {
        foreach ($seo_data['header_replacements'] as $replacement) {
            if (isset($replacement['type']) && $replacement['type'] === 'meta'
                && isset($replacement['property']) && $replacement['property'] === 'og:description'
                && !empty($replacement['recommended_value'])) {
                $og_description = sanitize_textarea_field($replacement['recommended_value']);
                # Clean SEO plugin variables
                $og_description = metasync_clean_seo_variables($og_description);
                # Only return if we have actual content after cleaning
                return !empty($og_description) ? $og_description : null;
            }
        }
    }

    # Fallback: Check header_html_insertion for og:description meta tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];

        # Match <meta property="og:description" content="..."> (property and content in any order)
        if (preg_match('/<meta\s+[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*property=["\']og:description["\'][^>]*>/i', $html_content, $matches)) {
            $og_description = sanitize_textarea_field(trim($matches[1]));
            $og_description = metasync_clean_seo_variables($og_description);
            return !empty($og_description) ? $og_description : null;
        }
    }

    return null;
}

/**
 * Extract Twitter title from OTTO response data
 *
 * @param array $seo_data The SEO data from OTTO API
 * @return string|null The Twitter title or null if not found
 */
function metasync_extract_twitter_title($seo_data) {
    # Check header replacements for Twitter title
    if (!empty($seo_data['header_replacements']) && is_array($seo_data['header_replacements'])) {
        foreach ($seo_data['header_replacements'] as $replacement) {
            if (isset($replacement['type']) && $replacement['type'] === 'meta'
                && isset($replacement['name']) && $replacement['name'] === 'twitter:title'
                && !empty($replacement['recommended_value'])) {
                $twitter_title = sanitize_text_field($replacement['recommended_value']);
                # Clean SEO plugin variables
                $twitter_title = metasync_clean_seo_variables($twitter_title);
                # Only return if we have actual content after cleaning
                return !empty($twitter_title) ? $twitter_title : null;
            }
        }
    }

    # Fallback: Check header_html_insertion for twitter:title meta tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];

        # Match <meta name="twitter:title" content="..."> (name and content in any order)
        if (preg_match('/<meta\s+[^>]*name=["\']twitter:title["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*name=["\']twitter:title["\'][^>]*>/i', $html_content, $matches)) {
            $twitter_title = sanitize_text_field(trim($matches[1]));
            $twitter_title = metasync_clean_seo_variables($twitter_title);
            return !empty($twitter_title) ? $twitter_title : null;
        }
    }

    return null;
}

/**
 * Extract Twitter description from OTTO response data
 *
 * @param array $seo_data The SEO data from OTTO API
 * @return string|null The Twitter description or null if not found
 */
function metasync_extract_twitter_description($seo_data) {
    # Check header replacements for Twitter description
    if (!empty($seo_data['header_replacements']) && is_array($seo_data['header_replacements'])) {
        foreach ($seo_data['header_replacements'] as $replacement) {
            if (isset($replacement['type']) && $replacement['type'] === 'meta'
                && isset($replacement['name']) && $replacement['name'] === 'twitter:description'
                && !empty($replacement['recommended_value'])) {
                $twitter_description = sanitize_textarea_field($replacement['recommended_value']);
                # Clean SEO plugin variables
                $twitter_description = metasync_clean_seo_variables($twitter_description);
                # Only return if we have actual content after cleaning
                return !empty($twitter_description) ? $twitter_description : null;
            }
        }
    }

    # Fallback: Check header_html_insertion for twitter:description meta tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];

        # Match <meta name="twitter:description" content="..."> (name and content in any order)
        if (preg_match('/<meta\s+[^>]*name=["\']twitter:description["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content=["\']([^"\']+)["\'][^>]*name=["\']twitter:description["\'][^>]*>/i', $html_content, $matches)) {
            $twitter_description = sanitize_textarea_field(trim($matches[1]));
            $twitter_description = metasync_clean_seo_variables($twitter_description);
            return !empty($twitter_description) ? $twitter_description : null;
        }
    }

    return null;
}

/**
 * Extract image alt text data from OTTO response data
 *
 * @param array $seo_data The SEO data from OTTO API
 * @return array Array of image URLs and their alt text
 */
function metasync_extract_image_alt_text($seo_data) {
    $image_alt_data = array();

    # Check body_substitutions for images
    if (!empty($seo_data['body_substitutions']['images']) && is_array($seo_data['body_substitutions']['images'])) {
        foreach ($seo_data['body_substitutions']['images'] as $image_url => $alt_text) {
            if (!empty($alt_text)) {
                $sanitized_url = esc_url_raw($image_url);
                $sanitized_alt = sanitize_text_field($alt_text);
                $image_alt_data[$sanitized_url] = $sanitized_alt;
            }
        }
    }

    return $image_alt_data;
}

/**
 * Extract heading data from OTTO response data
 * 
 * @param array $seo_data The SEO data from OTTO API
 * @return array Array of heading recommendations
 */
function metasync_extract_headings($seo_data) {
    $headings_data = array();
    
    # Check body_substitutions for headings
    if (!empty($seo_data['body_substitutions']['headings']) && is_array($seo_data['body_substitutions']['headings'])) {
        foreach ($seo_data['body_substitutions']['headings'] as $heading) {
            if (isset($heading['type']) && isset($heading['recommended_value']) && !empty($heading['recommended_value'])) {
                $headings_data[] = array(
                    'type' => sanitize_text_field($heading['type']),
                    'current_value' => sanitize_text_field($heading['current_value'] ?? ''),
                    'recommended_value' => sanitize_text_field($heading['recommended_value'])
                );
            }
        }
    }
    
    return $headings_data;
}

/**
 * Extract structured data from OTTO response data
 * 
 * @param array $seo_data The SEO data from OTTO API
 * @return string|null The structured data JSON or null if not found
 */
function metasync_extract_structured_data($seo_data) {
    # Check header_html_insertion for structured data
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];
        
        # Look for JSON-LD structured data
        if (preg_match('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html_content, $matches)) {
            $json_content = trim($matches[1]);
            if (!empty($json_content)) {
                return $json_content;
            }
        }
    }
    
    return null;
}

/**
 * Update comprehensive SEO meta fields for WordPress and popular SEO plugins
 * 
 * @param int $post_id WordPress post ID
 * @param array $seo_data Complete SEO data from OTTO
 * @return array Success status and updated flags for all fields
 */
function metasync_update_comprehensive_seo_fields($post_id, $seo_data) {
    if (!$post_id || empty($seo_data)) {
        return array(
            'updated' => false,
            'fields_updated' => array()
        );
    }

    $fields_updated = array();
    $any_updated = false;

    try {
        # OPTIMIZED: Load ALL post meta in single query instead of 10+ separate queries
        $all_meta = get_post_custom($post_id);

        # Check if meta descriptions are enabled in admin settings
        $metasync_options = get_option('metasync_options');
       # $enable_metadesc = $metasync_options['general']['enable_metadesc'] ?? '';

        # Extract all SEO data
        $meta_title = metasync_extract_meta_title($seo_data);
        $meta_description = metasync_extract_meta_description($seo_data);

        # we are checking the metadesc only in main function
        # If meta descriptions are disabled, don't process meta tags (title and description)
        # if ($enable_metadesc !== 'true' && $enable_metadesc !== true) {
            # Don't process meta title and description - return early
         #   return array(
         #       'updated' => false,
          #      'fields_updated' => array()
          #  );
       # }
        $meta_keywords = metasync_extract_meta_keywords($seo_data);
        $og_title = metasync_extract_og_title($seo_data);
        $og_description = metasync_extract_og_description($seo_data);
        $twitter_title = metasync_extract_twitter_title($seo_data);
        $twitter_description = metasync_extract_twitter_description($seo_data);
        $image_alt_data = metasync_extract_image_alt_text($seo_data);
        $headings_data = metasync_extract_headings($seo_data);
        $structured_data = metasync_extract_structured_data($seo_data);

        # Update basic meta fields (title and description)
        $basic_result = metasync_update_seo_meta_fields($post_id, $meta_title, $meta_description);
        if ($basic_result['title_updated']) {
          # $fields_updated['meta_title'] = $meta_title;
          $fields_updated['meta_title'] = $meta_title ?? ''; # Use empty string if null
            $any_updated = true;
        }
        if ($basic_result['description_updated']) {
           # $fields_updated['meta_description'] = $meta_description;
            $fields_updated['meta_description'] = $meta_description ?? ''; # Use empty string if null
            $any_updated = true;
        }

        # Update meta keywords (including empty values for rollback)
       # if ($meta_keywords !== null) {
         #   $current_keywords = get_post_meta($post_id, '_metasync_otto_keywords', true);
         # Update meta keywords (including clearing when OTTO returns null)
        $current_keywords = $all_meta['_metasync_otto_keywords'][0] ?? '';
        if ($meta_keywords !== null) {
            # OTTO provided keywords - update if different
            if ($meta_keywords !== $current_keywords) {
                update_post_meta($post_id, '_metasync_otto_keywords', $meta_keywords);
                $fields_updated['meta_keywords'] = $meta_keywords;
                $any_updated = true;
            }
        } else {
            # OTTO returned null for keywords - clear existing if it exists
            if (!empty($current_keywords)) {
                update_post_meta($post_id, '_metasync_otto_keywords', '');
                $fields_updated['meta_keywords'] = '';
                $any_updated = true;
            }
        }

        # Collect AIOSEO field updates to write in a single DB call at the end
        $aioseo_field_updates = [];

        # PERSISTENCE: meta_keywords → _metasync_focus_keyword + SEO plugin keyword fields
        if (class_exists('Metasync_Otto_Persistence_Settings') &&
            Metasync_Otto_Persistence_Settings::should_persist('meta_keywords') &&
            $meta_keywords !== null) {
            update_post_meta($post_id, '_metasync_focus_keyword', sanitize_text_field($meta_keywords));
            $fields_updated['meta_keywords_persisted'] = true;
            if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($meta_keywords));
            }
            if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($meta_keywords));
            }
            if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
                metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                # AIOSEO v4 stores focus keyphrase as JSON in the 'keyphrases' column,
                # not as a plain 'keywords' column. Encode it in the expected format.
                $aioseo_field_updates['keyphrases'] = wp_json_encode([
                    'focus' => [
                        'keyphrase' => sanitize_text_field($meta_keywords),
                        'score'     => 0,
                        'analysis'  => new stdClass(),
                    ],
                    'additional' => [],
                ]);
            }
        }

        # Update Open Graph fields (including empty values for rollback)
        # if ($og_title !== null) {
         #   $current_og_title = get_post_meta($post_id, '_metasync_otto_og_title', true);
             # Update Open Graph fields (including clearing when OTTO returns null)
        $current_og_title = $all_meta['_metasync_otto_og_title'][0] ?? '';
        if ($og_title !== null) {
            # OTTO provided OG title - update if different
            if ($og_title !== $current_og_title) {
                update_post_meta($post_id, '_metasync_otto_og_title', $og_title);
                $fields_updated['og_title'] = $og_title;
                $any_updated = true;
            }
        } else {
            # OTTO returned null for OG title - clear existing if it exists
            if (!empty($current_og_title)) {
                update_post_meta($post_id, '_metasync_otto_og_title', '');
                $fields_updated['og_title'] = '';
                $any_updated = true;
            }
        }

        # PERSISTENCE: og_title → _metasync_og_title + RankMath/Yoast/AIOSEO OG fields
        if (class_exists('Metasync_Otto_Persistence_Settings') &&
            Metasync_Otto_Persistence_Settings::should_persist('og_title') &&
            $og_title !== null) {
            update_post_meta($post_id, '_metasync_og_title', sanitize_text_field($og_title));
            $fields_updated['og_title_persisted'] = true;
            # Also write to SEO plugin OG fields so their output picks up OTTO data
            if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                update_post_meta($post_id, 'rank_math_facebook_title', sanitize_text_field($og_title));
            }
            if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                update_post_meta($post_id, '_yoast_wpseo_opengraph-title', sanitize_text_field($og_title));
            }
            if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
                metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                $aioseo_field_updates['og_title'] = sanitize_text_field($og_title);
            }
        }

       # if ($og_description !== null) {
           # $current_og_desc = get_post_meta($post_id, '_metasync_otto_og_description', true);
      $current_og_desc = $all_meta['_metasync_otto_og_description'][0] ?? '';
        if ($og_description !== null) {
            # OTTO provided OG description - update if different
            if ($og_description !== $current_og_desc) {
                update_post_meta($post_id, '_metasync_otto_og_description', $og_description);
                $fields_updated['og_description'] = $og_description;
                $any_updated = true;
            }
        } else {
            # OTTO returned null for OG description - clear existing if it exists
            if (!empty($current_og_desc)) {
                update_post_meta($post_id, '_metasync_otto_og_description', '');
                $fields_updated['og_description'] = '';
                $any_updated = true;
            }
        }

        # PERSISTENCE: og_description → _metasync_og_description + RankMath/Yoast/AIOSEO OG fields
        if (class_exists('Metasync_Otto_Persistence_Settings') &&
            Metasync_Otto_Persistence_Settings::should_persist('og_description') &&
            $og_description !== null) {
            update_post_meta($post_id, '_metasync_og_description', sanitize_textarea_field($og_description));
            $fields_updated['og_description_persisted'] = true;
            if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                update_post_meta($post_id, 'rank_math_facebook_description', sanitize_textarea_field($og_description));
            }
            if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                update_post_meta($post_id, '_yoast_wpseo_opengraph-description', sanitize_textarea_field($og_description));
            }
            if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
                metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                $aioseo_field_updates['og_description'] = sanitize_textarea_field($og_description);
            }
        }

        # Update Twitter fields (including empty values for rollback)
      #  if ($twitter_title !== null) {
          #  $current_twitter_title = get_post_meta($post_id, '_metasync_otto_twitter_title', true);
          # Update Twitter fields (including clearing when OTTO returns null)
        $current_twitter_title = $all_meta['_metasync_otto_twitter_title'][0] ?? '';
        if ($twitter_title !== null) {
            # OTTO provided Twitter title - update if different
            if ($twitter_title !== $current_twitter_title) {
                update_post_meta($post_id, '_metasync_otto_twitter_title', $twitter_title);
                $fields_updated['twitter_title'] = $twitter_title;
                $any_updated = true;
            }
        } else {
            # OTTO returned null for Twitter title - clear existing if it exists
            if (!empty($current_twitter_title)) {
                update_post_meta($post_id, '_metasync_otto_twitter_title', '');
                $fields_updated['twitter_title'] = '';
                $any_updated = true;
            }
        }

        # PERSISTENCE: twitter_title → _metasync_twitter_title + RankMath/Yoast/AIOSEO Twitter fields
        if (class_exists('Metasync_Otto_Persistence_Settings') &&
            Metasync_Otto_Persistence_Settings::should_persist('twitter_title') &&
            $twitter_title !== null) {
            update_post_meta($post_id, '_metasync_twitter_title', sanitize_text_field($twitter_title));
            $fields_updated['twitter_title_persisted'] = true;
            if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                update_post_meta($post_id, 'rank_math_twitter_title', sanitize_text_field($twitter_title));
            }
            if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                update_post_meta($post_id, '_yoast_wpseo_twitter-title', sanitize_text_field($twitter_title));
            }
            if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
                metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                $aioseo_field_updates['twitter_title'] = sanitize_text_field($twitter_title);
            }
        }

       # if ($twitter_description !== null) {
          #  $current_twitter_desc = get_post_meta($post_id, '_metasync_otto_twitter_description', true);
         $current_twitter_desc = $all_meta['_metasync_otto_twitter_description'][0] ?? '';
        if ($twitter_description !== null) {
            # OTTO provided Twitter description - update if different
            if ($twitter_description !== $current_twitter_desc) {
                update_post_meta($post_id, '_metasync_otto_twitter_description', $twitter_description);
                $fields_updated['twitter_description'] = $twitter_description;
                $any_updated = true;
            }
        } else {
            # OTTO returned null for Twitter description - clear existing if it exists
            if (!empty($current_twitter_desc)) {
                update_post_meta($post_id, '_metasync_otto_twitter_description', '');
                $fields_updated['twitter_description'] = '';
                $any_updated = true;
            }
        }

        # PERSISTENCE: twitter_description → _metasync_twitter_description + RankMath/Yoast/AIOSEO Twitter fields
        if (class_exists('Metasync_Otto_Persistence_Settings') &&
            Metasync_Otto_Persistence_Settings::should_persist('twitter_description') &&
            $twitter_description !== null) {
            update_post_meta($post_id, '_metasync_twitter_description', sanitize_textarea_field($twitter_description));
            $fields_updated['twitter_description_persisted'] = true;
            if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                update_post_meta($post_id, 'rank_math_twitter_description', sanitize_textarea_field($twitter_description));
            }
            if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                update_post_meta($post_id, '_yoast_wpseo_twitter-description', sanitize_textarea_field($twitter_description));
            }
            if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
                metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                $aioseo_field_updates['twitter_description'] = sanitize_textarea_field($twitter_description);
            }
        }

        # Update image alt text data
        if (!empty($image_alt_data)) {
            $current_image_alt = $all_meta['_metasync_otto_image_alt_data'][0] ?? '';
            $image_alt_json = json_encode($image_alt_data);
            if ($image_alt_json !== $current_image_alt) {
                update_post_meta($post_id, '_metasync_otto_image_alt_data', $image_alt_json);
                $fields_updated['image_alt_data'] = $image_alt_data;
                $any_updated = true;
            }

            # PERSISTENCE: If enabled, also update the Media Library directly
            if (class_exists('Metasync_Otto_Persistence_Settings') && 
                class_exists('Metasync_Otto_Persistence_Handler') &&
                Metasync_Otto_Persistence_Settings::should_persist('image_alt_text')) {
                $persistence_result = Metasync_Otto_Persistence_Handler::persist_image_alt_text($image_alt_data);
                if ($persistence_result['success']) {
                    $fields_updated['image_alt_text_persisted'] = $persistence_result['updated_count'];
                }
            }
        }

        # Update headings data
        if (!empty($headings_data)) {
            $current_headings = $all_meta['_metasync_otto_headings_data'][0] ?? '';
            $headings_json = json_encode($headings_data);
            if ($headings_json !== $current_headings) {
                update_post_meta($post_id, '_metasync_otto_headings_data', $headings_json);
                $fields_updated['headings_data'] = $headings_data;
                $any_updated = true;
            }
        }

        # PERSISTENCE: Process post_content modifications (headings and links) together
        # to avoid race conditions where one overwrites the other
        if (class_exists('Metasync_Otto_Persistence_Settings') && 
            class_exists('Metasync_Otto_Persistence_Handler')) {
            
            $persist_headings = Metasync_Otto_Persistence_Settings::should_persist('heading_changes') && !empty($headings_data);
            $persist_links = Metasync_Otto_Persistence_Settings::should_persist('link_corrections') && !empty($seo_data['body_substitutions']['links']);
            
            # Only process if at least one is enabled
            if ($persist_headings || $persist_links) {
                $post = get_post($post_id);
                if ($post) {
                    # Check if post uses a page builder - skip content modification to avoid breaking them
                    $uses_page_builder = false;
                    $page_builder_name = '';

                    # Elementor detection (array key lookups instead of DB queries)
                    if ((isset($all_meta['_elementor_edit_mode'][0]) && $all_meta['_elementor_edit_mode'][0] === 'builder') ||
                        (isset($all_meta['_elementor_data'][0]) && !empty($all_meta['_elementor_data'][0]))) {
                        $uses_page_builder = true;
                        $page_builder_name = 'Elementor';
                    }

                    # Divi Builder detection
                    if (!$uses_page_builder && (
                        (isset($all_meta['_et_pb_use_builder'][0]) && $all_meta['_et_pb_use_builder'][0] === 'on') ||
                        strpos($post->post_content, '[et_pb_') !== false)) {
                        $uses_page_builder = true;
                        $page_builder_name = 'Divi';
                    }

                    # WPBakery/Visual Composer detection
                    if (!$uses_page_builder && (
                        strpos($post->post_content, '[vc_') !== false ||
                        strpos($post->post_content, '[/vc_') !== false)) {
                        $uses_page_builder = true;
                        $page_builder_name = 'WPBakery';
                    }

                    # Beaver Builder detection
                    if (!$uses_page_builder &&
                        (isset($all_meta['_fl_builder_enabled'][0]) && $all_meta['_fl_builder_enabled'][0] === '1')) {
                        $uses_page_builder = true;
                        $page_builder_name = 'Beaver Builder';
                    }

                    # Oxygen Builder detection
                    if (!$uses_page_builder &&
                        (isset($all_meta['ct_builder_shortcodes'][0]) && !empty($all_meta['ct_builder_shortcodes'][0]))) {
                        $uses_page_builder = true;
                        $page_builder_name = 'Oxygen';
                    }

                    # Brizy detection
                    if (!$uses_page_builder &&
                        (isset($all_meta['brizy_post_uid'][0]) && !empty($all_meta['brizy_post_uid'][0]))) {
                        $uses_page_builder = true;
                        $page_builder_name = 'Brizy';
                    }
                    
                    # Skip content modification for page builder posts
                    if ($uses_page_builder) {
                        $fields_updated['content_persistence_skipped'] = sprintf(
                            'Post uses %s page builder - content modifications skipped to prevent data corruption',
                            $page_builder_name
                        );
                    } else {
                        # Safe to modify post_content for standard Gutenberg/Classic editor posts
                        $content = $post->post_content;
                        $original_content = $content;
                        
                        # Apply heading changes first
                        if ($persist_headings) {
                            foreach ($headings_data as $heading) {
                                if (empty($heading['type']) || empty($heading['current_value']) || empty($heading['recommended_value'])) {
                                    continue;
                                }
                                if ($heading['current_value'] === $heading['recommended_value']) {
                                    continue;
                                }
                                $tag = strtolower(sanitize_text_field($heading['type']));
                                $pattern = '/(<' . preg_quote($tag, '/') . '[^>]*>)\s*' . preg_quote($heading['current_value'], '/') . '\s*(<\/' . preg_quote($tag, '/') . '>)/i';
                                $replacement = '$1' . sanitize_text_field($heading['recommended_value']) . '$2';
                                $new_content = preg_replace($pattern, $replacement, $content, -1, $count);
                                if ($count > 0 && $new_content !== null) {
                                    $content = $new_content;
                                    $fields_updated['heading_changes_persisted'] = ($fields_updated['heading_changes_persisted'] ?? 0) + $count;
                                }
                            }
                        }
                        
                        # Apply link corrections second
                        if ($persist_links) {
                            foreach ($seo_data['body_substitutions']['links'] as $old_url => $new_url) {
                                $old_url = esc_url_raw($old_url);
                                $new_url = esc_url_raw($new_url);
                                if (empty($old_url) || empty($new_url) || $old_url === $new_url) {
                                    continue;
                                }
                                $count_before = substr_count($content, $old_url);
                                if ($count_before > 0) {
                                    $content = str_replace($old_url, $new_url, $content);
                                    $fields_updated['link_corrections_persisted'] = ($fields_updated['link_corrections_persisted'] ?? 0) + $count_before;
                                }
                            }
                        }
                        
                        # Save if content changed
                        if ($content !== $original_content) {
                            wp_update_post([
                                'ID' => $post_id,
                                'post_content' => $content,
                            ]);
                            clean_post_cache($post_id);
                            $any_updated = true;

                            # Log combined content persistence to sync history
                            if (class_exists('Metasync_Sync_History_Database')) {
                                try {
                                    $sync_history = new Metasync_Sync_History_Database();
                                    $headings_count = isset($fields_updated['heading_changes_persisted']) ? $fields_updated['heading_changes_persisted'] : 0;
                                    $links_count = isset($fields_updated['link_corrections_persisted']) ? $fields_updated['link_corrections_persisted'] : 0;

                                    $operations = [];
                                    if ($headings_count > 0) {
                                        $operations[] = sprintf('%d heading%s', $headings_count, $headings_count === 1 ? '' : 's');
                                    }
                                    if ($links_count > 0) {
                                        $operations[] = sprintf('%d link%s', $links_count, $links_count === 1 ? '' : 's');
                                    }

                                    $title = 'Updated ' . implode(' and ', $operations) . ' in post content';

                                    $sync_history->add([
                                        'title' => $title,
                                        'source' => 'OTTO Persistence',
                                        'status' => 'published',
                                        'content_type' => 'combined_content_update',
                                        'url' => get_permalink($post_id),
                                        'meta_data' => json_encode([
                                            'post_id' => $post_id,
                                            'headings_updated' => $headings_count,
                                            'links_updated' => $links_count,
                                        ]),
                                        'created_at' => current_time('mysql'),
                                    ]);
                                } catch (Exception $e) {
                                    // Failed to log, continue
                                }
                            }
                        }
                    }
                }
            }
        }

        # Update structured data (including empty values for rollback)
       # if ($structured_data !== null) {
         #   $current_structured = get_post_meta($post_id, '_metasync_otto_structured_data', true);
        # Update structured data (including clearing when OTTO returns null)
        $current_structured = $all_meta['_metasync_otto_structured_data'][0] ?? '';
        if ($structured_data !== null) {
            # OTTO provided structured data - update if different
            if ($structured_data !== $current_structured) {
                update_post_meta($post_id, '_metasync_otto_structured_data', $structured_data);
                $fields_updated['structured_data'] = $structured_data;
                $any_updated = true;
            }
        } else {
            # OTTO returned null for structured data - clear existing if it exists
            if (!empty($current_structured)) {
                update_post_meta($post_id, '_metasync_otto_structured_data', '');
                $fields_updated['structured_data'] = '';
                $any_updated = true;
            }
        }

        # PERSISTENCE: structured_data → metasync_schema_markup
        if (class_exists('Metasync_Otto_Persistence_Settings') &&
            Metasync_Otto_Persistence_Settings::should_persist('structured_data') &&
            !empty($structured_data)) {
            $existing_schema = get_post_meta($post_id, 'metasync_schema_markup', true);
            if (!is_array($existing_schema)) {
                $existing_schema = [];
            }
            $existing_schema['otto_jsonld'] = [
                'type'   => 'json_ld',
                'data'   => $structured_data,
                'source' => 'otto',
            ];
            update_post_meta($post_id, 'metasync_schema_markup', $existing_schema);
            $fields_updated['structured_data_persisted'] = true;
        }

        # PERSISTENCE: canonical_url → _metasync_canonical_url + SEO plugin canonical fields
        if (class_exists('Metasync_Otto_Persistence_Settings') &&
            Metasync_Otto_Persistence_Settings::should_persist('canonical_url') &&
            !empty($seo_data['canonical_url'])) {
            $canonical = esc_url_raw($seo_data['canonical_url']);
            if (!empty($canonical)) {
                update_post_meta($post_id, '_metasync_canonical_url', $canonical);
                $fields_updated['canonical_url_persisted'] = $canonical;
                if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                    update_post_meta($post_id, 'rank_math_canonical_url', $canonical);
                }
                if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                    update_post_meta($post_id, '_yoast_wpseo_canonical', $canonical);
                }
                if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
                    metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                    $aioseo_field_updates['canonical_url'] = $canonical;
                }
            }
        }

        # Write all collected AIOSEO field updates in a single DB call
        if (!empty($aioseo_field_updates)) {
            global $wpdb;
            $aioseo_table = $wpdb->prefix . 'aioseo_posts';
            $aioseo_field_updates['updated'] = current_time('mysql');
            $row_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$aioseo_table} WHERE post_id = %d", $post_id
            ));

            if ($row_exists) {
                $wpdb->update($aioseo_table, $aioseo_field_updates, ['post_id' => $post_id]);
            } else {
                $aioseo_field_updates['post_id'] = $post_id;
                $aioseo_field_updates['created'] = current_time('mysql');
                $wpdb->insert($aioseo_table, $aioseo_field_updates);
            }
        }

        # Store timestamp of last OTTO SEO update if any change
        if ($any_updated) {
            update_post_meta($post_id, '_metasync_otto_last_update', current_time('timestamp'));
        }

        return array(
            'updated' => $any_updated,
            'fields_updated' => $fields_updated
        );

    } catch (Exception $e) {
        return array(
            'updated' => false,
            'fields_updated' => array()
        );
    }
}

/**
 * Update comprehensive SEO meta fields for WordPress categories
 * 
 * @param int $category_id Category term ID
 * @param array $seo_data Complete SEO data from OTTO
 * @return array Success status and updated flags for all fields
 */
function metasync_update_comprehensive_category_seo_fields($category_id, $seo_data) {
    if (!$category_id || empty($seo_data)) {
        return array(
            'updated' => false,
            'fields_updated' => array()
        );
    }

    $fields_updated = array();
    $any_updated = false;

    try {
        # Check if meta descriptions are enabled in admin settings
        $metasync_options = get_option('metasync_options');
        
        # Extract all SEO data
        $meta_title = metasync_extract_meta_title($seo_data);
        $meta_description = metasync_extract_meta_description($seo_data);
        
        # If meta descriptions are disabled, don't process meta tags (title and description)
       # if ($enable_metadesc !== 'true' && $enable_metadesc !== true) {
            # Don't process meta title and description - return early
         #   return array(
           #     'updated' => false,
           #     'fields_updated' => array()
          #  );
       # }
        $meta_keywords = metasync_extract_meta_keywords($seo_data);
        $og_title = metasync_extract_og_title($seo_data);
        $og_description = metasync_extract_og_description($seo_data);
        $twitter_title = metasync_extract_twitter_title($seo_data);
        $twitter_description = metasync_extract_twitter_description($seo_data);
        $image_alt_data = metasync_extract_image_alt_text($seo_data);
        $headings_data = metasync_extract_headings($seo_data);
        $structured_data = metasync_extract_structured_data($seo_data);

        # Update basic meta fields (title and description)
        $basic_result = metasync_update_category_seo_meta_fields($category_id, $meta_title, $meta_description);
        if ($basic_result) {
          #  if ($meta_title) $fields_updated['meta_title'] = $meta_title;
          #  if ($meta_description) $fields_updated['meta_description'] = $meta_description;
            if ($meta_title !== null) $fields_updated['meta_title'] = $meta_title ?? '';
            if ($meta_description !== null) $fields_updated['meta_description'] = $meta_description ?? '';
            $any_updated = true;
        }

        # Update meta keywords (including empty values for rollback)
        if ($meta_keywords !== null) {
            $current_keywords = get_term_meta($category_id, '_metasync_otto_keywords', true);
            if ($meta_keywords !== $current_keywords) {
                update_term_meta($category_id, '_metasync_otto_keywords', $meta_keywords);
                $fields_updated['meta_keywords'] = $meta_keywords;
                $any_updated = true;
            }
        }

        # Update Open Graph fields (including empty values for rollback)
        if ($og_title !== null) {
            $current_og_title = get_term_meta($category_id, '_metasync_otto_og_title', true);
            if ($og_title !== $current_og_title) {
                update_term_meta($category_id, '_metasync_otto_og_title', $og_title);
                $fields_updated['og_title'] = $og_title;
                $any_updated = true;
            }
        }

        if ($og_description !== null) {
            $current_og_desc = get_term_meta($category_id, '_metasync_otto_og_description', true);
            if ($og_description !== $current_og_desc) {
                update_term_meta($category_id, '_metasync_otto_og_description', $og_description);
                $fields_updated['og_description'] = $og_description;
                $any_updated = true;
            }
        }

        # Update Twitter fields (including empty values for rollback)
        if ($twitter_title !== null) {
            $current_twitter_title = get_term_meta($category_id, '_metasync_otto_twitter_title', true);
            if ($twitter_title !== $current_twitter_title) {
                update_term_meta($category_id, '_metasync_otto_twitter_title', $twitter_title);
                $fields_updated['twitter_title'] = $twitter_title;
                $any_updated = true;
            }
        }

        if ($twitter_description !== null) {
            $current_twitter_desc = get_term_meta($category_id, '_metasync_otto_twitter_description', true);
            if ($twitter_description !== $current_twitter_desc) {
                update_term_meta($category_id, '_metasync_otto_twitter_description', $twitter_description);
                $fields_updated['twitter_description'] = $twitter_description;
                $any_updated = true;
            }
        }

        # Update image alt text data
        if (!empty($image_alt_data)) {
            $current_image_alt = get_term_meta($category_id, '_metasync_otto_image_alt_data', true);
            $image_alt_json = json_encode($image_alt_data);
            if ($image_alt_json !== $current_image_alt) {
                update_term_meta($category_id, '_metasync_otto_image_alt_data', $image_alt_json);
                $fields_updated['image_alt_data'] = $image_alt_data;
                $any_updated = true;
            }
        }

        # Update headings data
        if (!empty($headings_data)) {
            $current_headings = get_term_meta($category_id, '_metasync_otto_headings_data', true);
            $headings_json = json_encode($headings_data);
            if ($headings_json !== $current_headings) {
                update_term_meta($category_id, '_metasync_otto_headings_data', $headings_json);
                $fields_updated['headings_data'] = $headings_data;
                $any_updated = true;
            }
        }

        # Update structured data (including empty values for rollback)
        if ($structured_data !== null) {
            $current_structured = get_term_meta($category_id, '_metasync_otto_structured_data', true);
            if ($structured_data !== $current_structured) {
                update_term_meta($category_id, '_metasync_otto_structured_data', $structured_data);
                $fields_updated['structured_data'] = $structured_data;
                $any_updated = true;
            }
        }

        # Store timestamp of last OTTO SEO update if any change
        if ($any_updated) {
            update_term_meta($category_id, '_metasync_otto_last_update', current_time('timestamp'));
        }

        return array(
            'updated' => $any_updated,
            'fields_updated' => $fields_updated
        );

    } catch (Exception $e) {
        return array(
            'updated' => false,
            'fields_updated' => array()
        );
    }
}

/**
 * Update SEO meta fields for WordPress and popular SEO plugins
 * 
 * @param int $post_id WordPress post ID
 * @param string|null $meta_title Meta title to set
 * @param string|null $meta_description Meta description to set
 * @return array|false Success status and updated flags
 */
function metasync_update_seo_meta_fields($post_id, $meta_title, $meta_description) {
    if (!$post_id) {
        return array(
            'updated' => false,
            'title_updated' => false,
            'description_updated' => false,
        );
    }

    $title_updated = false;
    $description_updated = false;

    try {
        # Meta descriptions are always enabled by default

        # OPTIMIZED: Load post meta in single query
        $all_meta = get_post_custom($post_id);

        # Compare against previously stored OTTO values to avoid unnecessary updates
        $current_title = $all_meta['_metasync_otto_title'][0] ?? '';
        $current_description = $all_meta['_metasync_otto_description'][0] ?? '';

        # ENHANCED LOGIC: Handle clearing data when OTTO returns null (undeployed changes)
        # If OTTO returns null for title/description, we should clear existing OTTO data
        $should_update_title = false;
        $should_update_description = false;

        if ($meta_title !== null) {
            # OTTO provided a title - update if different from current
            $should_update_title = $meta_title !== $current_title;
        } else {
            # OTTO returned null for title - clear existing OTTO title if it exists
            $should_update_title = !empty($current_title);
            $meta_title = ''; # Set to empty string to clear the field
        }

        if ($meta_description !== null) {
            # OTTO provided a description - update if different from current
            $should_update_description = $meta_description !== $current_description;
        } else {
            # OTTO returned null for description - clear existing OTTO description if it exists
            $should_update_description = !empty($current_description);
            $meta_description = ''; # Set to empty string to clear the field
        }

        # Check persistence settings to determine if we should store in database
        # This is controlled via the OTTO Persistence API
        $persist_title = false;
        $persist_description = false;
        
        if (class_exists('Metasync_Otto_Persistence_Settings')) {
            $persist_title = Metasync_Otto_Persistence_Settings::should_persist('meta_title');
            $persist_description = Metasync_Otto_Persistence_Settings::should_persist('meta_description');
        }

        # Always write staging keys — these are read by OTTO's own render filters
        # regardless of whether persistence to native WP/3rd-party fields is enabled.
        if ($should_update_title) {
            update_post_meta($post_id, '_metasync_otto_title', $meta_title);
            $title_updated = true;
        }

        if ($should_update_description) {
            update_post_meta($post_id, '_metasync_otto_description', $meta_description);
            $description_updated = true;
        }

        # Write to plugin's own meta fields only when persistence is enabled
        if ($should_update_title && $persist_title) {
            update_post_meta($post_id, '_metasync_metatitle', $meta_title);
        }

        if ($should_update_description && $persist_description) {
            update_post_meta($post_id, '_metasync_metadesc', $meta_description);
        }

        # Sync to SEO plugins only if persistence is enabled via API
        if ($persist_title || $persist_description) {
            $effective_title = $meta_title;
            $effective_description = $meta_description;

            # Update RankMath SEO plugin meta fields
            if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                if ($should_update_title && $persist_title) {
                    update_post_meta($post_id, 'rank_math_title', $effective_title);
                }
                if ($should_update_description && $persist_description) {
                    update_post_meta($post_id, 'rank_math_description', $effective_description);
                }
            }

            # Update Yoast SEO plugin meta fields
            if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                if ($should_update_title && $persist_title) {
                    update_post_meta($post_id, '_yoast_wpseo_title', $effective_title);
                }
                if ($should_update_description && $persist_description) {
                    update_post_meta($post_id, '_yoast_wpseo_metadesc', $effective_description);
                }
            }

            # Update All in One SEO plugin fields (writes to aioseo_posts table, not postmeta)
            if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
                metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                global $wpdb;
                $aioseo_table = $wpdb->prefix . 'aioseo_posts';
                $aioseo_updates = [];

                if ($should_update_title && $persist_title) {
                    $aioseo_updates['title'] = $effective_title;
                }
                if ($should_update_description && $persist_description) {
                    $aioseo_updates['description'] = $effective_description;
                }

                if (!empty($aioseo_updates)) {
                    $aioseo_updates['updated'] = current_time('mysql');
                    $row_exists = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$aioseo_table} WHERE post_id = %d", $post_id
                    ));

                    if ($row_exists) {
                        $wpdb->update($aioseo_table, $aioseo_updates, ['post_id' => $post_id]);
                    } else {
                        $aioseo_updates['post_id'] = $post_id;
                        $aioseo_updates['created'] = current_time('mysql');
                        $wpdb->insert($aioseo_table, $aioseo_updates);
                    }
                }
            }

            # Store timestamp if any persistence occurred
            if (($should_update_title && $persist_title) || ($should_update_description && $persist_description)) {
                update_post_meta($post_id, '_metasync_otto_last_update', current_time('timestamp'));
            }
        }

        return array(
            'updated' => $title_updated || $description_updated,
            'title_updated' => $title_updated,
            'description_updated' => $description_updated,
        );

    } catch (Exception $e) {
        return array(
            'updated' => false,
            'title_updated' => false,
            'description_updated' => false,
        );
    }
}

/**
 * Update comprehensive SEO meta fields for any WordPress taxonomy (categories, WooCommerce product categories, etc.)
 * This is a generic function that works with any taxonomy type
 *
 * @param int $term_id Term ID
 * @param string $taxonomy Taxonomy name (e.g., 'category', 'product_cat', 'product_tag')
 * @param array $seo_data Complete SEO data from OTTO
 * @return array Success status and updated flags for all fields
 */
function metasync_update_comprehensive_taxonomy_seo_fields($term_id, $taxonomy, $seo_data) {
    if (!$term_id || empty($taxonomy) || empty($seo_data)) {
        return array(
            'updated' => false,
            'fields_updated' => array()
        );
    }

    # Verify the term exists
    $term = get_term($term_id, $taxonomy);
    if (is_wp_error($term) || !$term) {
        return array(
            'updated' => false,
            'fields_updated' => array()
        );
    }

    $fields_updated = array();
    $any_updated = false;

    try {
        # Check if meta descriptions are enabled in admin settings
        $metasync_options = get_option('metasync_options');

        # Extract all SEO data
        $meta_title = metasync_extract_meta_title($seo_data);
        $meta_description = metasync_extract_meta_description($seo_data);
        $meta_keywords = metasync_extract_meta_keywords($seo_data);
        $og_title = metasync_extract_og_title($seo_data);
        $og_description = metasync_extract_og_description($seo_data);
        $twitter_title = metasync_extract_twitter_title($seo_data);
        $twitter_description = metasync_extract_twitter_description($seo_data);
        $image_alt_data = metasync_extract_image_alt_text($seo_data);
        $headings_data = metasync_extract_headings($seo_data);
        $structured_data = metasync_extract_structured_data($seo_data);

        # Update basic meta fields (title and description) using the existing function
        $basic_result = metasync_update_taxonomy_seo_meta_fields($term_id, $taxonomy, $meta_title, $meta_description);
        if ($basic_result) {
            if ($meta_title !== null) $fields_updated['meta_title'] = $meta_title ?? '';
            if ($meta_description !== null) $fields_updated['meta_description'] = $meta_description ?? '';
            $any_updated = true;
        }

        # Update meta keywords
        $current_keywords = get_term_meta($term_id, '_metasync_otto_keywords', true);
        if ($meta_keywords !== null) {
            if ($meta_keywords !== $current_keywords) {
                update_term_meta($term_id, '_metasync_otto_keywords', $meta_keywords);
                $fields_updated['meta_keywords'] = $meta_keywords;
                $any_updated = true;
            }
        } else {
            if (!empty($current_keywords)) {
                update_term_meta($term_id, '_metasync_otto_keywords', '');
                $fields_updated['meta_keywords'] = '';
                $any_updated = true;
            }
        }

        # Update Open Graph fields
        $current_og_title = get_term_meta($term_id, '_metasync_otto_og_title', true);
        if ($og_title !== null) {
            if ($og_title !== $current_og_title) {
                update_term_meta($term_id, '_metasync_otto_og_title', $og_title);
                $fields_updated['og_title'] = $og_title;
                $any_updated = true;
            }
        } else {
            if (!empty($current_og_title)) {
                update_term_meta($term_id, '_metasync_otto_og_title', '');
                $fields_updated['og_title'] = '';
                $any_updated = true;
            }
        }

        $current_og_desc = get_term_meta($term_id, '_metasync_otto_og_description', true);
        if ($og_description !== null) {
            if ($og_description !== $current_og_desc) {
                update_term_meta($term_id, '_metasync_otto_og_description', $og_description);
                $fields_updated['og_description'] = $og_description;
                $any_updated = true;
            }
        } else {
            if (!empty($current_og_desc)) {
                update_term_meta($term_id, '_metasync_otto_og_description', '');
                $fields_updated['og_description'] = '';
                $any_updated = true;
            }
        }

        # Update Twitter fields
        $current_twitter_title = get_term_meta($term_id, '_metasync_otto_twitter_title', true);
        if ($twitter_title !== null) {
            if ($twitter_title !== $current_twitter_title) {
                update_term_meta($term_id, '_metasync_otto_twitter_title', $twitter_title);
                $fields_updated['twitter_title'] = $twitter_title;
                $any_updated = true;
            }
        } else {
            if (!empty($current_twitter_title)) {
                update_term_meta($term_id, '_metasync_otto_twitter_title', '');
                $fields_updated['twitter_title'] = '';
                $any_updated = true;
            }
        }

        $current_twitter_desc = get_term_meta($term_id, '_metasync_otto_twitter_description', true);
        if ($twitter_description !== null) {
            if ($twitter_description !== $current_twitter_desc) {
                update_term_meta($term_id, '_metasync_otto_twitter_description', $twitter_description);
                $fields_updated['twitter_description'] = $twitter_description;
                $any_updated = true;
            }
        } else {
            if (!empty($current_twitter_desc)) {
                update_term_meta($term_id, '_metasync_otto_twitter_description', '');
                $fields_updated['twitter_description'] = '';
                $any_updated = true;
            }
        }

        # Update image alt text data
        if (!empty($image_alt_data)) {
            $current_image_alt = get_term_meta($term_id, '_metasync_otto_image_alt_data', true);
            $image_alt_json = json_encode($image_alt_data);
            if ($image_alt_json !== $current_image_alt) {
                update_term_meta($term_id, '_metasync_otto_image_alt_data', $image_alt_json);
                $fields_updated['image_alt_data'] = $image_alt_data;
                $any_updated = true;
            }
        }

        # Update headings data
        if (!empty($headings_data)) {
            $current_headings = get_term_meta($term_id, '_metasync_otto_headings_data', true);
            $headings_json = json_encode($headings_data);
            if ($headings_json !== $current_headings) {
                update_term_meta($term_id, '_metasync_otto_headings_data', $headings_json);
                $fields_updated['headings_data'] = $headings_data;
                $any_updated = true;
            }
        }

        # Update structured data
        $current_structured = get_term_meta($term_id, '_metasync_otto_structured_data', true);
        if ($structured_data !== null) {
            if ($structured_data !== $current_structured) {
                update_term_meta($term_id, '_metasync_otto_structured_data', $structured_data);
                $fields_updated['structured_data'] = $structured_data;
                $any_updated = true;
            }
        } else {
            if (!empty($current_structured)) {
                update_term_meta($term_id, '_metasync_otto_structured_data', '');
                $fields_updated['structured_data'] = '';
                $any_updated = true;
            }
        }

        # Store timestamp of last OTTO SEO update if any change
        if ($any_updated) {
            update_term_meta($term_id, '_metasync_otto_last_update', current_time('timestamp'));
        }

        return array(
            'updated' => $any_updated,
            'fields_updated' => $fields_updated
        );

    } catch (Exception $e) {
        return array(
            'updated' => false,
            'fields_updated' => array()
        );
    }
}

/**
 * Update SEO meta fields for any WordPress taxonomy (generic function)
 *
 * @param int $term_id Term ID
 * @param string $taxonomy Taxonomy name
 * @param string|null $meta_title Meta title to set
 * @param string|null $meta_description Meta description to set
 * @return bool Success status
 */
function metasync_update_taxonomy_seo_meta_fields($term_id, $taxonomy, $meta_title, $meta_description) {
    if (!$term_id || empty($taxonomy)) {
        return false;
    }

    # Verify the term exists
    $term = get_term($term_id, $taxonomy);
    if (is_wp_error($term) || !$term) {
        return false;
    }

    $updated = false;

    try {
        # Check persistence settings to determine if we should store in database
        # This is controlled via the OTTO Persistence API
        $persist_title = false;
        $persist_description = false;
        
        if (class_exists('Metasync_Otto_Persistence_Settings')) {
            $persist_title = Metasync_Otto_Persistence_Settings::should_persist('meta_title');
            $persist_description = Metasync_Otto_Persistence_Settings::should_persist('meta_description');
        }

        # If persistence is not enabled, return early (changes only applied client-side)
        if (!$persist_title && !$persist_description) {
            return true;
        }

        # Compare against previously stored values
        $current_title = get_term_meta($term_id, '_metasync_otto_title', true);
        $current_description = get_term_meta($term_id, '_metasync_otto_description', true);

        $should_update_title = ($meta_title !== null) ? ($meta_title !== $current_title) : !empty($current_title);
        $should_update_description = ($meta_description !== null) ? ($meta_description !== $current_description) : !empty($current_description);

        if ($meta_title === null) $meta_title = '';
        if ($meta_description === null) $meta_description = '';

        # Store to OTTO meta fields if persistence is enabled
        if ($should_update_title && $persist_title) {
            update_term_meta($term_id, '_metasync_otto_title', $meta_title);
            update_term_meta($term_id, 'meta_title', $meta_title);
            $updated = true;
        }

        if ($should_update_description && $persist_description) {
            update_term_meta($term_id, '_metasync_otto_description', $meta_description);
            update_term_meta($term_id, 'meta_description', $meta_description);
            $updated = true;
        }

        # Sync to SEO plugins if persistence enabled
        if ($updated) {
            # RankMath
            if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                if ($should_update_title && $persist_title) {
                    update_term_meta($term_id, 'rank_math_title', $meta_title);
                }
                if ($should_update_description && $persist_description) {
                    update_term_meta($term_id, 'rank_math_description', $meta_description);
                }
            }

            # Yoast
            if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                if ($should_update_title && $persist_title) {
                    update_term_meta($term_id, '_yoast_wpseo_title', $meta_title);
                }
                if ($should_update_description && $persist_description) {
                    update_term_meta($term_id, '_yoast_wpseo_metadesc', $meta_description);
                }
            }

            # AIOSEO
            if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
                metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                if ($should_update_title && $persist_title) {
                    update_term_meta($term_id, '_aioseo_title', $meta_title);
                }
                if ($should_update_description && $persist_description) {
                    update_term_meta($term_id, '_aioseo_description', $meta_description);
                }
            }

            # Store timestamp
            update_term_meta($term_id, '_metasync_otto_last_update', current_time('timestamp'));
        }

        return true;

    } catch (Exception $e) {
        return false;
    }
}

/**
 * Update SEO meta fields for WordPress categories
 *
 * @param int $category_id Category term ID
 * @param string|null $meta_title Meta title to set
 * @param string|null $meta_description Meta description to set
 * @return bool Success status
 */
function metasync_update_category_seo_meta_fields($category_id, $meta_title, $meta_description) {
    if (!$category_id) {
        return false;
    }

    # Check persistence settings to determine if we should store in database
    # This is controlled via the OTTO Persistence API
    $persist_title = false;
    $persist_description = false;
    
    if (class_exists('Metasync_Otto_Persistence_Settings')) {
        $persist_title = Metasync_Otto_Persistence_Settings::should_persist('meta_title');
        $persist_description = Metasync_Otto_Persistence_Settings::should_persist('meta_description');
    }

    # If persistence is not enabled, return early (changes only applied client-side)
    if (!$persist_title && !$persist_description) {
        return true;
    }

    $updated = false;

    try {
        # Compare against previously stored values
        $current_title = get_term_meta($category_id, '_metasync_otto_title', true);
        $current_description = get_term_meta($category_id, '_metasync_otto_description', true);

        $should_update_title = ($meta_title !== null) ? ($meta_title !== $current_title) : !empty($current_title);
        $should_update_description = ($meta_description !== null) ? ($meta_description !== $current_description) : !empty($current_description);

        if ($meta_title === null) $meta_title = '';
        if ($meta_description === null) $meta_description = '';

        # Store to OTTO meta fields if persistence is enabled
        if ($should_update_title && $persist_title) {
            update_term_meta($category_id, '_metasync_otto_title', $meta_title);
            $updated = true;
        }

        if ($should_update_description && $persist_description) {
            update_term_meta($category_id, '_metasync_otto_description', $meta_description);
            $updated = true;
        }

        # Sync to SEO plugins if persistence enabled
        if ($updated) {
            # RankMath
            if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                if ($should_update_title && $persist_title) {
                    update_term_meta($category_id, 'rank_math_title', $meta_title);
                }
                if ($should_update_description && $persist_description) {
                    update_term_meta($category_id, 'rank_math_description', $meta_description);
                }
            }

            # Yoast
            if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                if ($should_update_title && $persist_title) {
                    update_term_meta($category_id, '_yoast_wpseo_title', $meta_title);
                }
                if ($should_update_description && $persist_description) {
                    update_term_meta($category_id, '_yoast_wpseo_metadesc', $meta_description);
                }
            }

            # AIOSEO
            if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
                metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                if ($should_update_title && $persist_title) {
                    update_term_meta($category_id, '_aioseo_title', $meta_title);
                }
                if ($should_update_description && $persist_description) {
                    update_term_meta($category_id, '_aioseo_description', $meta_description);
                }
            }

            # Store timestamp
            update_term_meta($category_id, '_metasync_otto_last_update', current_time('timestamp'));
        }

        return true;

    } catch (Exception $e) {
        return false;
    }
}

/**
 * Check if a specific plugin is active
 * 
 * @param string $plugin_path Plugin path (folder/file.php)
 * @return bool True if plugin is active
 */
function metasync_is_plugin_active($plugin_path) {
    if (!function_exists('is_plugin_active')) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
    
    return is_plugin_active($plugin_path);
}

/**
 * Clear SEO-related caches after updating meta fields
 * 
 * @param int $post_id WordPress post ID
 */
function metasync_clear_post_seo_caches($post_id) {
    # Clear WordPress object cache for the post
    clean_post_cache($post_id);
    
    # Clear RankMath cache if available
    if (class_exists('RankMath\\Helper') && method_exists('RankMath\\Helper', 'clear_cache')) {
        // @phpstan-ignore-next-line
        \RankMath\Helper::clear_cache();
    }
    
    # Clear Yoast SEO cache if available  
    if (class_exists('WPSEO_Utils') && method_exists('WPSEO_Utils', 'clear_cache')) {
        // @phpstan-ignore-next-line
        WPSEO_Utils::clear_cache();
    }
    
    # Clear AIOSEO cache if available
    if (class_exists('AIOSEO\\Plugin\\Common\\Utils\\Cache')) {
        // @phpstan-ignore-next-line
        $cache = new \AIOSEO\Plugin\Common\Utils\Cache();
        $cache->clear();
    }
    
    # Avoid global cache flush; 'clean_post_cache' already clears post-specific caches.
}

/**
 * Register meta fields for REST API access (called on init)
 * This ensures SEO plugin meta fields are accessible via REST API
 * Supports WordPress posts, pages, and WooCommerce products
 */
function metasync_register_seo_meta_fields() {
    # Get supported post types dynamically (includes WooCommerce products if active)
    $post_types = metasync_get_supported_post_types();

    # Define all meta fields to register
    $meta_fields = array(
        # MetaSync OTTO fields
        '_metasync_otto_title',
        '_metasync_otto_description',
        '_metasync_otto_keywords',
        '_metasync_otto_og_title',
        '_metasync_otto_og_description',
        '_metasync_otto_twitter_title',
        '_metasync_otto_twitter_description',
        # RankMath fields
        'rank_math_title',
        'rank_math_description',
        # Yoast SEO fields
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        # AIOSEO fields
        '_aioseo_title',
        '_aioseo_description',
    );

    # Register each meta field for each post type
    foreach ($post_types as $post_type) {
        foreach ($meta_fields as $meta_field) {
            register_post_meta($post_type, $meta_field, array(
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                }
            ));
        }
    }

    # Register taxonomy meta fields for supported taxonomies
    $taxonomies = metasync_get_supported_taxonomies();

    foreach ($taxonomies as $taxonomy) {
        foreach ($meta_fields as $meta_field) {
            register_term_meta($taxonomy, $meta_field, array(
                'type' => 'string',
                'single' => true,
                'show_in_rest' => true,
                'auth_callback' => function() {
                    return current_user_can('manage_categories');
                }
            ));
        }
    }
}

/**
 * Admin notice to inform about OTTO SEO integration
 */
// function metasync_otto_seo_admin_notice() {
//     if (!Metasync::current_user_has_plugin_access()) {
//         return;
//     }

//     $screen = get_current_screen();
//     if ($screen && $screen->id === 'plugins') {
//         echo '<div class="notice notice-info is-dismissible">';
//         echo '<p><strong>MetaSync OTTO SEO Integration:</strong> OTTO now automatically updates meta titles and descriptions for RankMath, Yoast SEO, and All in One SEO plugins when pages are crawled.</p>';
//         echo '</div>';
//     }
// }

# Register meta fields on WordPress init
add_action('init', 'metasync_register_seo_meta_fields');

# Show admin notice about new feature
// add_action('admin_notices', 'metasync_otto_seo_admin_notice');

/**
 * Astra Theme Compatibility
 * Override Astra theme's title output with OTTO optimized title
 */
function metasync_astra_title_override($title) {
    # Check for WooCommerce shop page
    if (function_exists('is_shop') && is_shop()) {
        $shop_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
        if ($shop_page_id) {
            $otto_title = get_post_meta($shop_page_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Check for individual product pages
    if (function_exists('is_product') && is_product()) {
        $post_id = get_the_ID();
        if ($post_id) {
            $otto_title = get_post_meta($post_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Check for product category pages
    if (is_tax('product_cat')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            # Check if OTTO has a title for this category
            $otto_title = get_term_meta($term->term_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Check for product tag pages
    if (is_tax('product_tag')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $otto_title = get_term_meta($term->term_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    return $title;
}

# Hook into Astra's title filters (multiple hooks for maximum compatibility)
add_filter('astra_the_title', 'metasync_astra_title_override', 99);
add_filter('astra_wp_title', 'metasync_astra_title_override', 99);

/**
 * WooCommerce Archive Title Override
 * This ensures OTTO title appears on WooCommerce shop, category, and tag pages
 */
function metasync_woocommerce_archive_title($title) {
    # Shop page
    if (function_exists('is_shop') && is_shop()) {
        $shop_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
        if ($shop_page_id) {
            $otto_title = get_post_meta($shop_page_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Product categories
    if (is_tax('product_cat')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $otto_title = get_term_meta($term->term_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Product tags
    if (is_tax('product_tag')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $otto_title = get_term_meta($term->term_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    return $title;
}
add_filter('woocommerce_page_title', 'metasync_woocommerce_archive_title', 99);

/**
 * wp_title Filter (Classic WordPress themes)
 * Fallback for themes that use wp_title()
 */
function metasync_wp_title_override($title, $sep = '') {
    # Shop page
    if (function_exists('is_shop') && is_shop()) {
        $shop_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
        if ($shop_page_id) {
            $otto_title = get_post_meta($shop_page_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Individual product pages
    if (function_exists('is_product') && is_product()) {
        $post_id = get_the_ID();
        if ($post_id) {
            $otto_title = get_post_meta($post_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Product categories
    if (is_tax('product_cat')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $otto_title = get_term_meta($term->term_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Product tags
    if (is_tax('product_tag')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $otto_title = get_term_meta($term->term_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Regular singular posts / pages (non-WooCommerce)
    if (is_singular()) {
        $post_id = get_the_ID();
        if ($post_id) {
            $otto_title = get_post_meta($post_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    return $title;
}
add_filter('wp_title', 'metasync_wp_title_override', 99, 2);

/**
 * Document Title Parts (WordPress 4.4+)
 * This is the modern way WordPress handles <title> tags
 */
function metasync_document_title_parts($title_parts) {
    # Homepage (static front page backed by a real page)
    if (is_front_page() && !is_home()) {
        $front_id = (int) get_option('page_on_front');
        if ($front_id) {
            $otto_title = get_post_meta($front_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                $title_parts['title'] = $otto_title;
                unset($title_parts['tagline']);
                unset($title_parts['site']);
            }
        }
    }

    # Shop page
    if (function_exists('is_shop') && is_shop()) {
        $shop_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
        if ($shop_page_id) {
            $otto_title = get_post_meta($shop_page_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                $title_parts['title'] = $otto_title;
                unset($title_parts['tagline']);
                unset($title_parts['site']);
            }
        }
    }

    # Individual product pages
    if (function_exists('is_product') && is_product()) {
        $post_id = get_the_ID();
        if ($post_id) {
            $otto_title = get_post_meta($post_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                $title_parts['title'] = $otto_title;
                unset($title_parts['tagline']);
                unset($title_parts['site']);
            }
        }
    }

    # Product categories
    if (is_tax('product_cat')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $otto_title = get_term_meta($term->term_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                # Replace the title part with OTTO title
                $title_parts['title'] = $otto_title;
                # Remove tagline and site name if present for cleaner title
                unset($title_parts['tagline']);
                unset($title_parts['site']);
            }
        }
    }

    # Product tags
    if (is_tax('product_tag')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $otto_title = get_term_meta($term->term_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                $title_parts['title'] = $otto_title;
                unset($title_parts['tagline']);
                unset($title_parts['site']);
            }
        }
    }

    # Regular singular posts / pages (non-WooCommerce)
    if (is_singular()) {
        $post_id = get_the_ID();
        if ($post_id) {
            $otto_title = get_post_meta($post_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                $title_parts['title'] = $otto_title;
                unset($title_parts['tagline']);
                unset($title_parts['site']);
            }
        }
    }

    return $title_parts;
}
add_filter('document_title_parts', 'metasync_document_title_parts', 99);

/**
 * Pre-get Document Title (WordPress 4.4+)
 * Final override before title is rendered
 */
function metasync_pre_get_document_title($title) {
    # Homepage (static front page backed by a real page)
    if (is_front_page() && !is_home()) {
        $front_id = (int) get_option('page_on_front');
        if ($front_id) {
            $otto_title = get_post_meta($front_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Shop page
    if (function_exists('is_shop') && is_shop()) {
        $shop_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
        if ($shop_page_id) {
            $otto_title = get_post_meta($shop_page_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Individual product pages
    if (function_exists('is_product') && is_product()) {
        $post_id = get_the_ID();
        if ($post_id) {
            $otto_title = get_post_meta($post_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Product categories
    if (is_tax('product_cat')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $otto_title = get_term_meta($term->term_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Product tags
    if (is_tax('product_tag')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $otto_title = get_term_meta($term->term_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    # Regular singular posts / pages (non-WooCommerce)
    if (is_singular()) {
        $post_id = get_the_ID();
        if ($post_id) {
            $otto_title = get_post_meta($post_id, '_metasync_otto_title', true);
            if (!empty($otto_title)) {
                return $otto_title;
            }
        }
    }

    return $title;
}
add_filter('pre_get_document_title', 'metasync_pre_get_document_title', 99);

/**
 * Output OTTO meta description tag in <head> for WooCommerce pages
 * This is the critical function that actually renders meta descriptions to HTML
 * Unlike titles, WordPress doesn't automatically output meta descriptions from meta fields
 */
function metasync_output_otto_meta_description() {
    $description = null;

    # Shop page
    if (function_exists('is_shop') && is_shop()) {
        $shop_page_id = function_exists('wc_get_page_id') ? wc_get_page_id('shop') : 0;
        if ($shop_page_id) {
            $description = get_post_meta($shop_page_id, '_metasync_otto_description', true);

            # Fallback to other description fields if OTTO description not found
            if (empty($description)) {
                $description = get_post_meta($shop_page_id, 'rank_math_description', true);
            }
            if (empty($description)) {
                $description = get_post_meta($shop_page_id, '_yoast_wpseo_metadesc', true);
            }
            if (empty($description)) {
                $description = get_post_meta($shop_page_id, 'meta_description', true);
            }
        }
    }
    # Individual product pages
    elseif (function_exists('is_product') && is_product()) {
        $post_id = get_the_ID();
        if ($post_id) {
            $description = get_post_meta($post_id, '_metasync_otto_description', true);

            # Fallback to other description fields
            if (empty($description)) {
                $description = get_post_meta($post_id, 'rank_math_description', true);
            }
            if (empty($description)) {
                $description = get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            }
            if (empty($description)) {
                $description = get_post_meta($post_id, 'meta_description', true);
            }
        }
    }
    # Product categories
    elseif (is_tax('product_cat')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $description = get_term_meta($term->term_id, '_metasync_otto_description', true);

            # Fallback to other description fields
            if (empty($description)) {
                $description = get_term_meta($term->term_id, 'rank_math_description', true);
            }
            if (empty($description)) {
                $description = get_term_meta($term->term_id, '_yoast_wpseo_metadesc', true);
            }
            if (empty($description)) {
                $description = get_term_meta($term->term_id, 'woocommerce_meta_description', true);
            }
            if (empty($description)) {
                $description = get_term_meta($term->term_id, 'meta_description', true);
            }
        }
    }
    # Product tags
    elseif (is_tax('product_tag')) {
        $term = get_queried_object();
        if ($term && isset($term->term_id)) {
            $description = get_term_meta($term->term_id, '_metasync_otto_description', true);

            # Fallback to other description fields
            if (empty($description)) {
                $description = get_term_meta($term->term_id, 'rank_math_description', true);
            }
            if (empty($description)) {
                $description = get_term_meta($term->term_id, '_yoast_wpseo_metadesc', true);
            }
            if (empty($description)) {
                $description = get_term_meta($term->term_id, 'meta_description', true);
            }
        }
    }
    # Regular singular posts / pages (non-WooCommerce)
    elseif (is_singular()) {
        $post_id = get_the_ID();
        if ($post_id) {
            $description = get_post_meta($post_id, '_metasync_otto_description', true);
        }
    }

    # Output meta description tag if we found one
    if (!empty($description)) {
        # Escape for HTML attribute
        $description_escaped = esc_attr($description);

        # Output the meta tag with OTTO marker
        echo '<meta name="description" content="' . $description_escaped . '" data-metasync-otto="true" />' . "\n";
    }
}
add_action('wp_head', 'metasync_output_otto_meta_description', 1);

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
    # Timeout is intentionally short: this function runs from a background job scheduled
    # by metasync_otto_crawl_notify(), and a slow upstream response should not stall the
    # job runner or pile up failures.
    $response = wp_remote_get($api_url, array(
        'timeout' => 5,
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
        if (preg_match('/<meta\s+name=["\']description["\']\s+[^>]*content="([^"]+)"[^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+name=["\']description["\']\s+[^>]*content=\'([^\']+)\'[^>]*>/i', $html_content, $matches)) {
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
        if (preg_match('/<meta\s+name=["\']keywords["\']\s+[^>]*content="([^"]+)"[^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+name=["\']keywords["\']\s+[^>]*content=\'([^\']+)\'[^>]*>/i', $html_content, $matches)) {
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
        if (preg_match('/<meta\s+[^>]*property=["\']og:title["\'][^>]*content="([^"]+)"[^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*property=["\']og:title["\'][^>]*content=\'([^\']+)\'[^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content="([^"]+)"[^>]*property=["\']og:title["\'][^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content=\'([^\']+)\'[^>]*property=["\']og:title["\'][^>]*>/i', $html_content, $matches)) {
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
        if (preg_match('/<meta\s+[^>]*property=["\']og:description["\'][^>]*content="([^"]+)"[^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*property=["\']og:description["\'][^>]*content=\'([^\']+)\'[^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content="([^"]+)"[^>]*property=["\']og:description["\'][^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content=\'([^\']+)\'[^>]*property=["\']og:description["\'][^>]*>/i', $html_content, $matches)) {
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
        if (preg_match('/<meta\s+[^>]*name=["\']twitter:title["\'][^>]*content="([^"]+)"[^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*name=["\']twitter:title["\'][^>]*content=\'([^\']+)\'[^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content="([^"]+)"[^>]*name=["\']twitter:title["\'][^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content=\'([^\']+)\'[^>]*name=["\']twitter:title["\'][^>]*>/i', $html_content, $matches)) {
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
        if (preg_match('/<meta\s+[^>]*name=["\']twitter:description["\'][^>]*content="([^"]+)"[^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*name=["\']twitter:description["\'][^>]*content=\'([^\']+)\'[^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content="([^"]+)"[^>]*name=["\']twitter:description["\'][^>]*>/i', $html_content, $matches)
            || preg_match('/<meta\s+[^>]*content=\'([^\']+)\'[^>]*name=["\']twitter:description["\'][^>]*>/i', $html_content, $matches)) {
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

        # Look for ALL JSON-LD structured data blocks
        if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html_content, $matches)) {
            $json_blocks = array_filter(array_map('trim', $matches[1]));
            if (empty($json_blocks)) {
                return null;
            }

            # Single block — return as-is (backward compatible)
            if (count($json_blocks) === 1) {
                return reset($json_blocks);
            }

            # Multiple blocks — merge into a single @graph structure
            $all_entities = [];
            foreach ($json_blocks as $json_string) {
                $decoded = json_decode($json_string, true);
                if (!is_array($decoded)) {
                    continue;
                }
                # Unwrap existing @graph arrays
                if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                    foreach ($decoded['@graph'] as $entity) {
                        unset($entity['@context']);
                        $all_entities[] = $entity;
                    }
                } elseif (isset($decoded['@type'])) {
                    unset($decoded['@context']);
                    $all_entities[] = $decoded;
                } elseif (isset($decoded[0])) {
                    # Plain JSON array of entities (e.g., ImageObject array)
                    foreach ($decoded as $entity) {
                        if (is_array($entity)) {
                            unset($entity['@context']);
                            $all_entities[] = $entity;
                        }
                    }
                }
            }

            if (!empty($all_entities)) {
                return wp_json_encode([
                    '@context' => 'https://schema.org',
                    '@graph'   => $all_entities,
                ]);
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
                                $pattern = '/(<' . preg_quote($tag, '/') . '[^>]*>)(.*?)(<\/' . preg_quote($tag, '/') . '>)/is';
                                $normalized_current = trim(preg_replace('/\s+/', ' ', html_entity_decode($heading['current_value'], ENT_QUOTES, 'UTF-8')));
                                $safe_recommended = sanitize_text_field($heading['recommended_value']);
                                $count = 0;
                                $new_content = preg_replace_callback(
                                    $pattern,
                                    function ($m) use ($normalized_current, $safe_recommended, &$count) {
                                        $inner_text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES, 'UTF-8')));
                                        if (strcasecmp($inner_text, $normalized_current) === 0) {
                                            $count++;
                                            return $m[1] . $safe_recommended . $m[3];
                                        }
                                        return $m[0];
                                    },
                                    $content
                                );
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
                # wp_slash counteracts wp_unslash inside update_metadata, preserving
                # backslash-escaped quotes (e.g. \") in the JSON string.
                update_post_meta($post_id, '_metasync_otto_structured_data', wp_slash($structured_data));
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
        # Always write otto_jsonld during an active sync regardless of the persistence flag.
        # The persistence flag controls whether this data survives OTTO being disabled/paused
        # — see OTTO deactivation path.
        if (class_exists('Metasync_Otto_Persistence_Settings') &&
            !empty($structured_data)) {
            $existing_schema = get_post_meta($post_id, 'metasync_schema_markup', true);
            if (!is_array($existing_schema)) {
                $existing_schema = [];
            }
            $existing_schema['otto_jsonld'] = [
                'type'           => 'json_ld',
                'data'           => $structured_data,
                'source'         => 'otto',
                'last_synced_at' => current_time('mysql'),
            ];
            # wp_slash counteracts wp_unslash inside update_metadata, preserving
            # backslash-escaped quotes in JSON strings within the array.
            update_post_meta($post_id, 'metasync_schema_markup', wp_slash($existing_schema));
            $fields_updated['structured_data_persisted'] = true;

            # Cross-plugin schema persistence: sync structured_data to RankMath, Yoast, AIOSEO
            if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                $rankmath_schemas = metasync_convert_jsonld_to_rankmath($structured_data);
                foreach ($rankmath_schemas as $schema_type => $schema_data) {
                    update_post_meta($post_id, 'rank_math_schema_' . $schema_type, wp_slash($schema_data));
                }
            }
            if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                $yoast_types = metasync_extract_yoast_schema_types($structured_data);
                if (!empty($yoast_types['article_type'])) {
                    update_post_meta($post_id, '_yoast_wpseo_schema_article_type', $yoast_types['article_type']);
                }
                if (!empty($yoast_types['page_type'])) {
                    update_post_meta($post_id, '_yoast_wpseo_schema_page_type', $yoast_types['page_type']);
                }
                // Also update Yoast's indexable cache so the change renders immediately
                if (!empty($yoast_types['article_type']) || !empty($yoast_types['page_type'])) {
                    global $wpdb;
                    $yoast_indexable_table = $wpdb->prefix . 'yoast_indexable';
                    $update_cols = array();
                    $update_vals = array();
                    if (!empty($yoast_types['article_type'])) {
                        $update_cols[] = 'schema_article_type = %s';
                        $update_vals[] = $yoast_types['article_type'];
                    }
                    if (!empty($yoast_types['page_type'])) {
                        $update_cols[] = 'schema_page_type = %s';
                        $update_vals[] = $yoast_types['page_type'];
                    }
                    $update_vals[] = $post_id;
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$yoast_indexable_table} SET " . implode(', ', $update_cols) . " WHERE object_id = %d AND object_type = 'post'",
                        $update_vals
                    ));
                }
            }
            // AIOSEO schema persistence skipped — AIOSEO Free locks Article/custom schema behind Pro paywall.
            // The graphs array (Pro-only) and default.graphName (UI-gated) cannot reliably render OTTO schema on Free.
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

        // WP-196: Sync to all active SEO plugins
        if (class_exists('Metasync_Plugin_Sync') && $any_updated) {
            Metasync_Plugin_Sync::get_instance()->sync_post($post_id);
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
        # Capture whether each field is an un-deploy "clear" (OTTO null) vs a real value.
        # On a clear we still wipe MetaSync's own staging/permanent fields, but must NOT
        # blank the SEO-plugin fields (empty meta is worse for SEO than a stale value).
        $title_is_clear = ($meta_title === null);
        $description_is_clear = ($meta_description === null);

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

        # Decouple the permanent/SEO-plugin write from the staging-key write.
        # The staging key gates on (incoming != staging); the permanent native /
        # SEO-plugin field must update whenever persistence is ON and the native
        # field is missing or differs from the incoming value. This back-fills
        # _metasync_metatitle / _metasync_metadesc (and SEO-plugin equivalents)
        # when a persistence flag is enabled after OTTO has already synced.
        $current_native_title = $all_meta['_metasync_metatitle'][0] ?? '';
        $current_native_description = $all_meta['_metasync_metadesc'][0] ?? '';

        $should_persist_title = $persist_title && ($meta_title !== $current_native_title);
        $should_persist_description = $persist_description && ($meta_description !== $current_native_description);

        # Per-plugin back-fill: gate each SEO-plugin write on that plugin's OWN
        # stored value, not on MetaSync's native field. Once the native field
        # matches the incoming value, $should_persist_* go false — but an active
        # plugin whose own column is empty/stale must still be back-filled. Read
        # each plugin's current value from the already-loaded $all_meta and write
        # only when persistence for that field is ON, it is not a "clear", and the
        # plugin's stored value differs from the incoming value. (AIOSEO lives in
        # the aioseo_posts table, so its current values are read inside its block.)
        $current_rm_title         = $all_meta['rank_math_title'][0] ?? '';
        $current_rm_description   = $all_meta['rank_math_description'][0] ?? '';
        $current_yoast_title      = $all_meta['_yoast_wpseo_title'][0] ?? '';
        $current_yoast_description = $all_meta['_yoast_wpseo_metadesc'][0] ?? '';

        $should_persist_rm_title         = $persist_title && !$title_is_clear && ($meta_title !== $current_rm_title);
        $should_persist_rm_description   = $persist_description && !$description_is_clear && ($meta_description !== $current_rm_description);
        $should_persist_yoast_title      = $persist_title && !$title_is_clear && ($meta_title !== $current_yoast_title);
        $should_persist_yoast_description = $persist_description && !$description_is_clear && ($meta_description !== $current_yoast_description);

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
        if ($should_persist_title) {
            update_post_meta($post_id, '_metasync_metatitle', $meta_title);
        }

        if ($should_persist_description) {
            update_post_meta($post_id, '_metasync_metadesc', $meta_description);
        }

        # Track whether any SEO-plugin field was actually back-filled, so the
        # timestamp / updated roll-up below also covers plugin-only writes (the
        # native field may already match the incoming value while a plugin's own
        # column is empty or stale).
        $plugin_title_written = false;
        $plugin_description_written = false;

        # Sync to SEO plugins whenever persistence is enabled — not only when the
        # native field changed. Each plugin's write is gated on that plugin's own
        # stored value (the $should_persist_*_* flags computed above), so an active
        # plugin whose column is empty/stale is back-filled even though MetaSync's
        # native field already matches the incoming value.
        if ($persist_title || $persist_description) {
            $effective_title = $meta_title;
            $effective_description = $meta_description;

            # Update RankMath SEO plugin meta fields
            if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
                if ($should_persist_rm_title) {
                    update_post_meta($post_id, 'rank_math_title', $effective_title);
                    $plugin_title_written = true;
                }
                if ($should_persist_rm_description) {
                    update_post_meta($post_id, 'rank_math_description', $effective_description);
                    $plugin_description_written = true;
                }
            }

            # Update Yoast SEO plugin meta fields
            if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
                if ($should_persist_yoast_title) {
                    update_post_meta($post_id, '_yoast_wpseo_title', $effective_title);
                    $plugin_title_written = true;
                }
                if ($should_persist_yoast_description) {
                    update_post_meta($post_id, '_yoast_wpseo_metadesc', $effective_description);
                    $plugin_description_written = true;
                }
            }

            # Update All in One SEO plugin fields (writes to aioseo_posts table, not postmeta)
            if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
                metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
                global $wpdb;
                $aioseo_table = $wpdb->prefix . 'aioseo_posts';

                # Read the plugin's own current values so the back-fill is gated on
                # the aioseo_posts row, not on MetaSync's native field.
                $current_aioseo = $wpdb->get_row($wpdb->prepare("SELECT title, description FROM {$aioseo_table} WHERE post_id = %d", $post_id));

                $should_persist_aioseo_title       = $persist_title && !$title_is_clear && ($meta_title !== ($current_aioseo->title ?? ''));
                $should_persist_aioseo_description = $persist_description && !$description_is_clear && ($meta_description !== ($current_aioseo->description ?? ''));

                $aioseo_updates = [];
                if ($should_persist_aioseo_title) {
                    $aioseo_updates['title'] = $effective_title;
                    $plugin_title_written = true;
                }
                if ($should_persist_aioseo_description) {
                    $aioseo_updates['description'] = $effective_description;
                    $plugin_description_written = true;
                }

                if (!empty($aioseo_updates)) {
                    $aioseo_updates['updated'] = current_time('mysql');

                    if ($current_aioseo) {
                        $wpdb->update($aioseo_table, $aioseo_updates, ['post_id' => $post_id]);
                    } else {
                        $aioseo_updates['post_id'] = $post_id;
                        $aioseo_updates['created'] = current_time('mysql');
                        $wpdb->insert($aioseo_table, $aioseo_updates);
                    }
                }
            }
        }

        # Store timestamp if any persistence occurred — native back-fill OR a
        # plugin-only back-fill. Kept outside the native-field gate so a write
        # that only touched a SEO plugin still records the sync timestamp.
        if ($should_persist_title || $should_persist_description || $plugin_title_written || $plugin_description_written) {
            update_post_meta($post_id, '_metasync_otto_last_update', current_time('timestamp')); # also covers $plugin_title_written / $plugin_description_written back-fills
        }

        # A persistence-only back-fill (staging key already current, but the permanent
        # / SEO-plugin fields were just written) must still mark the post as updated so
        # the caller runs cache purge and the WP-196 plugin sync. Without this, a
        # back-fill silently returns updated=false and the downstream refresh is skipped.
        $title_updated = $title_updated || $should_persist_title || $plugin_title_written;
        $description_updated = $description_updated || $should_persist_description || $plugin_description_written;

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

        # Mirror OTTO term SEO data into the active SEO plugin(s) term storage
        # so category/tag archive pages render OTTO-managed values regardless
        # of which SEO plugin is active.
        if (class_exists('Metasync_Term_Plugin_Sync')) {
            $sync_data = array();
            if (!empty($meta_title))          { $sync_data['title']         = $meta_title; }
            if (!empty($meta_description))    { $sync_data['desc']          = $meta_description; }
            if (!empty($og_title))            { $sync_data['og_title']      = $og_title; }
            if (!empty($og_description))      { $sync_data['og_desc']       = $og_description; }
            if (!empty($twitter_title))       { $sync_data['twitter_title'] = $twitter_title; }
            if (!empty($twitter_description)) { $sync_data['twitter_desc']  = $twitter_description; }

            if (!empty($sync_data)) {
                Metasync_Term_Plugin_Sync::get_instance()->sync_term($term_id, $taxonomy, $sync_data);
            }
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
 * Resolve a URL to a taxonomy term when `url_to_postid()` reports no matching
 * post. This lets OTTO optimizations targeting category/tag/custom-taxonomy
 * archive URLs be routed to term-level persistence.
 *
 * Strategy: try each registered public taxonomy's `rewrite` slugs against each
 * non-empty segment of the URL path (in reverse order so the most specific
 * term wins), and return the first match.
 *
 * @param string $url Absolute or relative URL.
 * @return array|null ['term_id'=>int,'taxonomy'=>string] on a hit, null otherwise.
 */
function metasync_resolve_url_to_term($url) {
    if (empty($url) || !is_string($url)) {
        return null;
    }

    # If WordPress can resolve the URL to a post, it isn't a term archive.
    if (function_exists('url_to_postid')) {
        $post_id = url_to_postid($url);
        if ($post_id > 0) {
            return null;
        }
    }

    $path = parse_url($url, PHP_URL_PATH);
    if (empty($path)) {
        return null;
    }

    $segments = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
    if (empty($segments)) {
        return null;
    }

    $taxonomies = get_taxonomies(array('public' => true), 'names');
    if (empty($taxonomies)) {
        return null;
    }

    # Walk segments from most-specific (last) to least-specific (first).
    for ($i = count($segments) - 1; $i >= 0; $i--) {
        $slug = $segments[$i];
        foreach ($taxonomies as $taxonomy_name) {
            $term = get_term_by('slug', $slug, $taxonomy_name);
            if ($term && !is_wp_error($term)) {
                return array(
                    'term_id'  => (int) $term->term_id,
                    'taxonomy' => (string) $taxonomy_name,
                );
            }
        }
    }

    return null;
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
 * Extract a URL string from a JSON-LD image value.
 *
 * Handles the various shapes JSON-LD images can take:
 * - Plain string: "https://example.com/img.jpg"
 * - Object with url: {"url": "https://...", "width": 800}
 * - Object with @id: {"@id": "#primaryimage"}
 * - Flat array: ["https://example.com/a.jpg", "https://example.com/b.jpg"]
 *
 * @param mixed $image The image value from JSON-LD.
 * @return string URL string or empty string.
 */
function metasync_extract_jsonld_image_url( $image ) {
    if ( empty( $image ) ) {
        return '';
    }
    if ( is_string( $image ) ) {
        return $image;
    }
    if ( is_array( $image ) ) {
        if ( isset( $image['url'] ) ) {
            return $image['url'];
        }
        if ( isset( $image['@id'] ) ) {
            return $image['@id'];
        }
        if ( isset( $image[0] ) && is_string( $image[0] ) ) {
            return $image[0];
        }
    }
    return '';
}

/**
 * Normalize a JSON-LD `@type` value to a single string.
 *
 * Per JSON-LD spec `@type` may be a string or an array of strings; OTTO can
 * send either form. Returns the first scalar element when given an array, or
 * an empty string when no usable scalar is present.
 *
 * @param mixed $type Raw `@type` value.
 * @return string Normalized type string (empty string if not derivable).
 */
function metasync_normalize_jsonld_type( $type ) {
    if ( is_array( $type ) ) {
        $type = reset( $type );
    }
    return is_string( $type ) && '' !== $type ? $type : '';
}

/**
 * Convert JSON-LD string to RankMath schema format.
 *
 * @param string $json_ld_string JSON-LD structured data string.
 * @return array Associative array of [$PascalCaseType => $fields_array].
 */
function metasync_convert_jsonld_to_rankmath( $json_ld_string ) {
    $decoded = json_decode( $json_ld_string, true );
    if ( ! is_array( $decoded ) ) {
        return array();
    }

    // Normalize to array of schema items (unwrap @graph if present).
    $items = array();
    if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
        $items = $decoded['@graph'];
    } elseif ( isset( $decoded['@type'] ) ) {
        $items = array( $decoded );
    } elseif ( isset( $decoded[0] ) ) {
        // Bare JSON array of schema objects (e.g. [{"@type":"Article",...}, ...]).
        $items = $decoded;
    }

    $result = array();
    foreach ( $items as $item ) {
        if ( ! isset( $item['@type'] ) ) {
            continue;
        }

        $type = metasync_normalize_jsonld_type( $item['@type'] );
        if ( '' === $type ) {
            continue;
        }
        $type_lower = strtolower( $type );

        if ( 'article' === $type_lower || 'blogposting' === $type_lower || 'newsarticle' === $type_lower ) {
            // headline fallback: OTTO sometimes sends 'name' instead of 'headline'
            $headline = '';
            if ( ! empty( $item['headline'] ) ) {
                $headline = $item['headline'];
            } elseif ( ! empty( $item['name'] ) ) {
                $headline = $item['name'];
            }
            $result['Article'] = array(
                '@type'          => 'Article',
                'headline'       => $headline,
                'description'    => isset( $item['description'] ) ? $item['description'] : '',
                'image'          => metasync_extract_jsonld_image_url( isset( $item['image'] ) ? $item['image'] : null ),
                'publisher'      => isset( $item['publisher']['name'] ) ? $item['publisher']['name'] : ( is_string( $item['publisher'] ?? null ) ? $item['publisher'] : '' ),
                'publisher_logo' => isset( $item['publisher']['logo']['url'] ) ? $item['publisher']['logo']['url'] : '',
            );
        } elseif ( 'faqpage' === $type_lower ) {
            $questions = array();
            if ( isset( $item['mainEntity'] ) && is_array( $item['mainEntity'] ) ) {
                foreach ( $item['mainEntity'] as $question ) {
                    if ( isset( $question['name'] ) && isset( $question['acceptedAnswer']['text'] ) ) {
                        $questions[] = array(
                            'name' => $question['name'],
                            'text' => $question['acceptedAnswer']['text'],
                        );
                    }
                }
            }
            $result['FAQPage'] = array(
                '@type'     => 'FAQPage',
                'questions' => $questions,
            );
        } elseif ( 'product' === $type_lower ) {
            $result['Product'] = array(
                '@type'       => 'Product',
                'name'        => isset( $item['name'] ) ? $item['name'] : '',
                'description' => isset( $item['description'] ) ? $item['description'] : '',
                'image'       => metasync_extract_jsonld_image_url( isset( $item['image'] ) ? $item['image'] : null ),
                'sku'         => isset( $item['sku'] ) ? $item['sku'] : '',
                'brand'       => isset( $item['brand']['name'] ) ? $item['brand']['name'] : '',
                'price'       => isset( $item['offers']['price'] ) ? floatval( $item['offers']['price'] ) : 0,
                'currency'    => isset( $item['offers']['priceCurrency'] ) ? $item['offers']['priceCurrency'] : 'USD',
                'inStock'     => isset( $item['offers']['availability'] ) ? ( strpos( $item['offers']['availability'], 'InStock' ) !== false ) : true,
            );
        } elseif ( 'recipe' === $type_lower ) {
            $instructions = array();
            if ( isset( $item['recipeInstructions'] ) && is_array( $item['recipeInstructions'] ) ) {
                foreach ( $item['recipeInstructions'] as $step ) {
                    if ( is_string( $step ) ) {
                        $instructions[] = $step;
                    } elseif ( isset( $step['text'] ) ) {
                        $instructions[] = $step['text'];
                    }
                }
            }
            $result['Recipe'] = array(
                '@type'             => 'Recipe',
                'name'              => isset( $item['name'] ) ? $item['name'] : '',
                'description'       => isset( $item['description'] ) ? $item['description'] : '',
                'image'             => metasync_extract_jsonld_image_url( isset( $item['image'] ) ? $item['image'] : null ),
                'recipeYield'       => isset( $item['recipeYield'] ) ? $item['recipeYield'] : '',
                'recipeIngredient'  => isset( $item['recipeIngredient'] ) ? $item['recipeIngredient'] : array(),
                'recipeInstructions' => $instructions,
                'prepTime'          => isset( $item['prepTime'] ) ? metasync_parse_iso8601_duration( $item['prepTime'] ) : 0,
                'cookTime'          => isset( $item['cookTime'] ) ? metasync_parse_iso8601_duration( $item['cookTime'] ) : 0,
                'totalTime'         => isset( $item['totalTime'] ) ? metasync_parse_iso8601_duration( $item['totalTime'] ) : 0,
                'calories'          => isset( $item['nutrition']['calories'] ) ? intval( $item['nutrition']['calories'] ) : 0,
            );
        } // Unrecognized types are skipped — RankMath can't render raw JSON-LD.
          // They remain in metasync_schema_markup where our own renderer handles them.
    }

    return $result;
}

/**
 * Extract Yoast-compatible schema type strings from OTTO JSON-LD.
 *
 * Yoast does NOT store full JSON schema. It stores only type strings in post meta:
 *   _yoast_wpseo_schema_article_type  (e.g. "Article", "BlogPosting", "NewsArticle")
 *   _yoast_wpseo_schema_page_type     (e.g. "FAQPage", "CollectionPage", "ItemPage")
 * Yoast's own graph builder then generates the full schema from post data.
 *
 * @param string $json_ld_string JSON-LD structured data string.
 * @return array With 'article_type' and/or 'page_type' keys, or empty array.
 */
function metasync_extract_yoast_schema_types( $json_ld_string ) {
    $decoded = json_decode( $json_ld_string, true );
    if ( ! is_array( $decoded ) ) {
        return array();
    }

    // Normalize to array of items.
    $items = array();
    if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
        $items = $decoded['@graph'];
    } elseif ( isset( $decoded['@type'] ) ) {
        $items = array( $decoded );
    } elseif ( isset( $decoded[0] ) ) {
        $items = $decoded;
    }

    // Valid Yoast article types (from Yoast's Schema_Types::ARTICLE_TYPES).
    $valid_article_types = array(
        'Article', 'BlogPosting', 'SocialMediaPosting', 'NewsArticle',
        'AdvertiserContentArticle', 'SatiricalArticle', 'ScholarlyArticle',
        'TechArticle', 'Report',
    );

    // Valid Yoast page types (from Yoast's Schema_Types::PAGE_TYPES).
    $valid_page_types = array(
        'WebPage', 'ItemPage', 'AboutPage', 'FAQPage', 'QAPage', 'ProfilePage',
        'ContactPage', 'MedicalWebPage', 'CollectionPage', 'CheckoutPage',
        'RealEstateListing', 'SearchResultsPage',
    );

    $result = array();
    foreach ( $items as $item ) {
        if ( ! is_array( $item ) || ! isset( $item['@type'] ) ) {
            continue;
        }
        $type = metasync_normalize_jsonld_type( $item['@type'] );
        if ( '' === $type ) {
            continue;
        }

        if ( in_array( $type, $valid_article_types, true ) && ! isset( $result['article_type'] ) ) {
            $result['article_type'] = $type;
        } elseif ( in_array( $type, $valid_page_types, true ) && ! isset( $result['page_type'] ) ) {
            $result['page_type'] = $type;
        }
    }

    return $result;
}

/**
 * Convert JSON-LD string to AIOSEO graph objects for the `schema` column.
 *
 * AIOSEO v4+ stores schema in the `schema` column as a JSON object with a `graphs`
 * array. Each graph has `id`, `graphName`, `type`, and a `properties` object.
 * The `schema_type` and `schema_type_options` columns are NOT used for rendering.
 *
 * @param string $json_ld_string JSON-LD structured data string.
 * @return array Array of AIOSEO graph objects for the `graphs` key, or empty array.
 */
function metasync_convert_jsonld_to_aioseo( $json_ld_string ) {
    $decoded = json_decode( $json_ld_string, true );
    if ( ! is_array( $decoded ) ) {
        return array();
    }

    // Normalize to array of items.
    // OTTO can send: {"@graph":[...]}, {"@type":"..."}, or a bare array [{...},{...}].
    $items = array();
    if ( isset( $decoded['@graph'] ) && is_array( $decoded['@graph'] ) ) {
        $items = $decoded['@graph'];
    } elseif ( isset( $decoded['@type'] ) ) {
        $items = array( $decoded );
    } elseif ( isset( $decoded[0] ) ) {
        // Bare JSON array of schema objects (e.g. [{"@type":"ImageObject",...}, ...]).
        $items = $decoded;
    }

    $graphs = array();
    foreach ( $items as $index => $item ) {
        if ( ! is_array( $item ) || ! isset( $item['@type'] ) ) {
            continue;
        }

        $type = metasync_normalize_jsonld_type( $item['@type'] );
        if ( '' === $type ) {
            continue;
        }
        $type_lower = strtolower( $type );
        $converted  = metasync_convert_jsonld_item_for_aioseo( $item, $type_lower );

        if ( ! empty( $converted ) ) {
            // Recognized type with field mapping.
            $graphs[] = array(
                'id'         => 'otto-schema-' . $index,
                'slug'       => '',
                'graphName'  => $converted['schema_type'],
                'label'      => $converted['schema_type'],
                'type'       => $converted['schema_type'],
                'properties' => $converted['options'],
            );
        } else {
            // Unrecognized type — pass through the raw JSON-LD properties.
            $properties = $item;
            unset( $properties['@context'], $properties['@type'] );
            $graphs[] = array(
                'id'         => 'otto-schema-' . $index,
                'slug'       => '',
                'graphName'  => $type,
                'label'      => $type,
                'type'       => $type,
                'properties' => $properties,
            );
        }
    }

    return $graphs;
}

/**
 * Convert a single JSON-LD item to AIOSEO option fields.
 *
 * @param array  $item       Decoded JSON-LD item.
 * @param string $type_lower Lowercased @type value.
 * @return array With 'schema_type' and 'options' keys, or empty array.
 */
function metasync_convert_jsonld_item_for_aioseo( $item, $type_lower ) {
    if ( 'article' === $type_lower || 'blogposting' === $type_lower || 'newsarticle' === $type_lower ) {
        $headline = '';
        if ( ! empty( $item['headline'] ) ) {
            $headline = $item['headline'];
        } elseif ( ! empty( $item['name'] ) ) {
            $headline = $item['name'];
        }
        return array(
            'schema_type' => 'Article',
            'options'     => array(
                'headline'         => $headline,
                'description'      => isset( $item['description'] ) ? $item['description'] : '',
                'image'            => metasync_extract_jsonld_image_url( isset( $item['image'] ) ? $item['image'] : null ),
                'organizationName' => isset( $item['publisher']['name'] ) ? $item['publisher']['name'] : ( is_string( $item['publisher'] ?? null ) ? $item['publisher'] : '' ),
                'organizationLogo' => isset( $item['publisher']['logo']['url'] ) ? $item['publisher']['logo']['url'] : '',
            ),
        );
    } elseif ( 'faqpage' === $type_lower ) {
        $questions = array();
        if ( isset( $item['mainEntity'] ) && is_array( $item['mainEntity'] ) ) {
            foreach ( $item['mainEntity'] as $question ) {
                if ( isset( $question['name'] ) && isset( $question['acceptedAnswer']['text'] ) ) {
                    $questions[] = array(
                        'question' => $question['name'],
                        'answer'   => $question['acceptedAnswer']['text'],
                    );
                }
            }
        }
        return array(
            'schema_type' => 'FAQPage',
            'options'     => array( 'questions' => $questions ),
        );
    } elseif ( 'product' === $type_lower ) {
        return array(
            'schema_type' => 'Product',
            'options'     => array(
                'name'         => isset( $item['name'] ) ? $item['name'] : '',
                'description'  => isset( $item['description'] ) ? $item['description'] : '',
                'image'        => metasync_extract_jsonld_image_url( isset( $item['image'] ) ? $item['image'] : null ),
                'sku'          => isset( $item['sku'] ) ? $item['sku'] : '',
                'brand'        => isset( $item['brand']['name'] ) ? $item['brand']['name'] : '',
                'price'        => isset( $item['offers']['price'] ) ? floatval( $item['offers']['price'] ) : 0,
                'currency'     => isset( $item['offers']['priceCurrency'] ) ? $item['offers']['priceCurrency'] : 'USD',
                'availability' => isset( $item['offers']['availability'] ) ? basename( $item['offers']['availability'] ) : 'InStock',
                'condition'    => isset( $item['offers']['itemCondition'] ) ? basename( $item['offers']['itemCondition'] ) : 'NewCondition',
            ),
        );
    } elseif ( 'recipe' === $type_lower ) {
        $instructions = array();
        if ( isset( $item['recipeInstructions'] ) && is_array( $item['recipeInstructions'] ) ) {
            foreach ( $item['recipeInstructions'] as $step ) {
                if ( is_string( $step ) ) {
                    $instructions[] = $step;
                } elseif ( isset( $step['text'] ) ) {
                    $instructions[] = $step['text'];
                }
            }
        }
        return array(
            'schema_type' => 'Recipe',
            'options'     => array(
                'name'               => isset( $item['name'] ) ? $item['name'] : '',
                'description'        => isset( $item['description'] ) ? $item['description'] : '',
                'image'              => metasync_extract_jsonld_image_url( isset( $item['image'] ) ? $item['image'] : null ),
                'recipeYield'        => isset( $item['recipeYield'] ) ? $item['recipeYield'] : '',
                'recipeIngredient'   => isset( $item['recipeIngredient'] ) ? $item['recipeIngredient'] : array(),
                'recipeInstructions' => $instructions,
                'prepTime'           => isset( $item['prepTime'] ) ? metasync_parse_iso8601_duration( $item['prepTime'] ) : 0,
                'cookTime'           => isset( $item['cookTime'] ) ? metasync_parse_iso8601_duration( $item['cookTime'] ) : 0,
                'totalTime'          => isset( $item['totalTime'] ) ? metasync_parse_iso8601_duration( $item['totalTime'] ) : 0,
                'calories'           => isset( $item['nutrition']['calories'] ) ? intval( $item['nutrition']['calories'] ) : 0,
            ),
        );
    }

    return array();
}

/**
 * Parse ISO 8601 duration string to minutes.
 * E.g., "PT15M" = 15, "PT1H30M" = 90.
 *
 * @param string $duration ISO 8601 duration string.
 * @return int Duration in minutes.
 */
function metasync_parse_iso8601_duration( $duration ) {
    if ( empty( $duration ) ) {
        return 0;
    }

    $minutes = 0;
    if ( preg_match( '/PT(\d+)H/', $duration, $hours ) ) {
        $minutes += intval( $hours[1] ) * 60;
    }
    if ( preg_match( '/(\d+)M/', $duration, $mins ) ) {
        $minutes += intval( $mins[1] );
    }

    return $minutes;
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
 * WP-313: Suppress OTTO-synced SEO plugin output for logged-in users.
 *
 * When "Disable OTTO for Logged in Users" is enabled, filter Yoast SEO and
 * Rank Math output hooks to return original (non-OTTO) values. These plugins
 * use internal Indexable caches, so filtering get_post_metadata is insufficient.
 */
function metasync_is_otto_suppressed_for_current_user() {
    static $result = null;
    if ($result === null) {
        $result = class_exists('Metasync_Otto_Config')
            && Metasync_Otto_Config::is_disabled_for_loggedin()
            && is_user_logged_in();
    }
    return $result;
}

# Yoast SEO filters
add_filter('wpseo_title', 'metasync_suppress_yoast_title_for_loggedin', 99999);
add_filter('wpseo_opengraph_title', 'metasync_suppress_yoast_title_for_loggedin', 99999);
add_filter('wpseo_metadesc', 'metasync_suppress_yoast_desc_for_loggedin', 99999);
add_filter('wpseo_opengraph_desc', 'metasync_suppress_yoast_desc_for_loggedin', 99999);
add_filter('wpseo_schema_webpage', 'metasync_suppress_yoast_schema_for_loggedin', 99999);

# Rank Math filters
add_filter('rank_math/frontend/title', 'metasync_suppress_yoast_title_for_loggedin', 99999);
add_filter('rank_math/frontend/description', 'metasync_suppress_yoast_desc_for_loggedin', 99999);

function metasync_suppress_yoast_title_for_loggedin($title) {
    if (!metasync_is_otto_suppressed_for_current_user()) {
        return $title;
    }
    if (is_singular()) {
        $original = get_the_title();
        $sep = '-';
        if (function_exists('YoastSEO') || class_exists('WPSEO_Utils')) {
            $sep = apply_filters('document_title_separator', '-');
        }
        return $original . ' ' . $sep . ' ' . get_bloginfo('name');
    }
    return $title;
}

function metasync_suppress_yoast_desc_for_loggedin($desc) {
    if (!metasync_is_otto_suppressed_for_current_user()) {
        return $desc;
    }
    # Return empty so Yoast/Rank Math falls back to auto-generated excerpt
    return '';
}

function metasync_suppress_yoast_schema_for_loggedin($data) {
    if (!metasync_is_otto_suppressed_for_current_user()) {
        return $data;
    }
    if (is_singular() && isset($data['name'])) {
        $data['name'] = get_the_title() . ' - ' . get_bloginfo('name');
    }
    return $data;
}

/**
 * Astra Theme Compatibility
 * Override Astra theme's title output with OTTO optimized title
 */
function metasync_astra_title_override($title) {
    if (class_exists('Metasync_Otto_Config') && Metasync_Otto_Config::is_disabled_for_loggedin() && is_user_logged_in()) {
        return $title;
    }

    if (class_exists('Metasync_SEO_Conflict_Handler')) {
        $handler = Metasync_SEO_Conflict_Handler::get_instance();
        if ($handler->has_active_seo_plugin() && !$handler->otto_has_live_suggestions()) {
            return $title;
        }
    }

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
    if (class_exists('Metasync_Otto_Config') && Metasync_Otto_Config::is_disabled_for_loggedin() && is_user_logged_in()) {
        return $title;
    }

    if (class_exists('Metasync_SEO_Conflict_Handler')) {
        $handler = Metasync_SEO_Conflict_Handler::get_instance();
        if ($handler->has_active_seo_plugin() && !$handler->otto_has_live_suggestions()) {
            return $title;
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
    if (class_exists('Metasync_Otto_Config') && Metasync_Otto_Config::is_disabled_for_loggedin() && is_user_logged_in()) {
        return $title;
    }

    if (class_exists('Metasync_SEO_Conflict_Handler')) {
        $handler = Metasync_SEO_Conflict_Handler::get_instance();
        if ($handler->has_active_seo_plugin() && !$handler->otto_has_live_suggestions()) {
            return $title;
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
add_filter('wp_title', 'metasync_wp_title_override', 99, 2);

/**
 * Document Title Parts (WordPress 4.4+)
 * This is the modern way WordPress handles <title> tags
 */
function metasync_document_title_parts($title_parts) {
    if (class_exists('Metasync_Otto_Config') && Metasync_Otto_Config::is_disabled_for_loggedin() && is_user_logged_in()) {
        return $title_parts;
    }

    if (class_exists('Metasync_SEO_Conflict_Handler')) {
        $handler = Metasync_SEO_Conflict_Handler::get_instance();
        if ($handler->has_active_seo_plugin() && !$handler->otto_has_live_suggestions()) {
            return $title_parts;
        }
    }

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
    if (class_exists('Metasync_Otto_Config') && Metasync_Otto_Config::is_disabled_for_loggedin() && is_user_logged_in()) {
        # Return empty to neutralize any SEO plugin (Yoast/RankMath) that may have
        # already set $title to the synced OTTO value at a lower priority.
        # An empty string tells WordPress to rebuild the title from default parts.
        return '';
    }

    if (class_exists('Metasync_SEO_Conflict_Handler')) {
        $handler = Metasync_SEO_Conflict_Handler::get_instance();
        if ($handler->has_active_seo_plugin() && !$handler->otto_has_live_suggestions()) {
            return $title;
        }
    }

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
    if (class_exists('Metasync_Otto_Config') && Metasync_Otto_Config::is_disabled_for_loggedin() && is_user_logged_in()) {
        return;
    }

    // WP-361: Mirror the conflict guard used in metasync_pre_get_document_title()
    // (around line 2817). When a third-party SEO plugin is active AND OTTO has no
    // live suggestions for this URL, defer to the SEO plugin's description instead
    // of rendering a stale _metasync_otto_description from a previous deployment.
    if (class_exists('Metasync_SEO_Conflict_Handler')) {
        $handler = Metasync_SEO_Conflict_Handler::get_instance();
        if ($handler->has_active_seo_plugin() && !$handler->otto_has_live_suggestions()) {
            return;
        }
    }

    // WP-196: When synced to an active SEO plugin, that plugin outputs the
    // description from its native storage — skip OTTO's own description tag.
    if (is_singular()) {
        $post_id = get_the_ID();
        if ($post_id) {
            $sync_ts = get_post_meta($post_id, '_metasync_plugin_sync_ts', true);
            if (!empty($sync_ts) && class_exists('Metasync_SEO_Conflict_Handler')) {
                $handler = Metasync_SEO_Conflict_Handler::get_instance();
                if ($handler->has_active_seo_plugin()) {
                    return;
                }
            }
        }
    }

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

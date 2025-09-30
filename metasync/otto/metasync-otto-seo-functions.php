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
    # Construct OTTO API endpoint URL
    $api_url = add_query_arg(
        array(
            'url' => $route,
            'uuid' => $uuid
        ),
        'https://sa.searchatlas.com/api/v2/otto-url-details'
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
        error_log("MetaSync OTTO: API request failed: " . $response->get_error_message());
        return false;
    }

    # Check response code
    $response_code = wp_remote_retrieve_response_code($response);
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
                return sanitize_text_field($replacement['recommended_value']);
            }
        }
    }
    
    # Check header_html_insertion for title tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];
        
        # Look for title tag with data-otto-pixel attribute
        if (preg_match('/<title[^>]*data-otto-pixel=["\']dynamic-seo["\'][^>]*>([^<]+)<\/title>/i', $html_content, $matches)) {
            return sanitize_text_field(trim($matches[1]));
        }
        
        # Fallback: look for any title tag
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html_content, $matches)) {
            return sanitize_text_field(trim($matches[1]));
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
            if (isset($replacement['type']) && $replacement['type'] === 'meta'
                && (
                    (isset($replacement['name']) && $replacement['name'] === 'description') ||
                    (isset($replacement['property']) && $replacement['property'] === 'og:description')
                )
                && !empty($replacement['recommended_value'])) {
                return sanitize_textarea_field($replacement['recommended_value']);
            }
        }
    }
    
    # Check header_html_insertion for meta description tag
    if (!empty($seo_data['header_html_insertion'])) {
        $html_content = $seo_data['header_html_insertion'];
        
        # Look for meta description tag with data-otto-pixel attribute
        if (preg_match('/<meta\s+name=["\']description["\']\s+[^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html_content, $matches)) {
            return sanitize_textarea_field($matches[1]);
        }
        
        # Fallback: look for any meta description tag
        if (preg_match('/<meta\s+name=["\']description["\']\s+[^>]*content=["\']([^"\']+)["\'][^>]*>/i', $html_content, $matches)) {
            return sanitize_textarea_field($matches[1]);
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
                return sanitize_text_field($replacement['recommended_value']);
            }
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
                return sanitize_textarea_field($replacement['recommended_value']);
            }
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
                return sanitize_text_field($replacement['recommended_value']);
            }
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
                return sanitize_textarea_field($replacement['recommended_value']);
            }
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
                $image_alt_data[sanitize_url($image_url)] = sanitize_text_field($alt_text);
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

        # Update basic meta fields (title and description)
        $basic_result = metasync_update_seo_meta_fields($post_id, $meta_title, $meta_description);
        if ($basic_result['title_updated']) {
            $fields_updated['meta_title'] = $meta_title;
            $any_updated = true;
        }
        if ($basic_result['description_updated']) {
            $fields_updated['meta_description'] = $meta_description;
            $any_updated = true;
        }

        # Update meta keywords (including empty values for rollback)
        if ($meta_keywords !== null) {
            $current_keywords = get_post_meta($post_id, '_metasync_otto_keywords', true);
            if ($meta_keywords !== $current_keywords) {
                update_post_meta($post_id, '_metasync_otto_keywords', $meta_keywords);
                $fields_updated['meta_keywords'] = $meta_keywords;
                $any_updated = true;
            }
        }

        # Update Open Graph fields (including empty values for rollback)
        if ($og_title !== null) {
            $current_og_title = get_post_meta($post_id, '_metasync_otto_og_title', true);
            if ($og_title !== $current_og_title) {
                update_post_meta($post_id, '_metasync_otto_og_title', $og_title);
                $fields_updated['og_title'] = $og_title;
                $any_updated = true;
            }
        }

        if ($og_description !== null) {
            $current_og_desc = get_post_meta($post_id, '_metasync_otto_og_description', true);
            if ($og_description !== $current_og_desc) {
                update_post_meta($post_id, '_metasync_otto_og_description', $og_description);
                $fields_updated['og_description'] = $og_description;
                $any_updated = true;
            }
        }

        # Update Twitter fields (including empty values for rollback)
        if ($twitter_title !== null) {
            $current_twitter_title = get_post_meta($post_id, '_metasync_otto_twitter_title', true);
            if ($twitter_title !== $current_twitter_title) {
                update_post_meta($post_id, '_metasync_otto_twitter_title', $twitter_title);
                $fields_updated['twitter_title'] = $twitter_title;
                $any_updated = true;
            }
        }

        if ($twitter_description !== null) {
            $current_twitter_desc = get_post_meta($post_id, '_metasync_otto_twitter_description', true);
            if ($twitter_description !== $current_twitter_desc) {
                update_post_meta($post_id, '_metasync_otto_twitter_description', $twitter_description);
                $fields_updated['twitter_description'] = $twitter_description;
                $any_updated = true;
            }
        }

        # Update image alt text data
        if (!empty($image_alt_data)) {
            $current_image_alt = get_post_meta($post_id, '_metasync_otto_image_alt_data', true);
            $image_alt_json = json_encode($image_alt_data);
            if ($image_alt_json !== $current_image_alt) {
                update_post_meta($post_id, '_metasync_otto_image_alt_data', $image_alt_json);
                $fields_updated['image_alt_data'] = $image_alt_data;
                $any_updated = true;
            }
        }

        # Update headings data
        if (!empty($headings_data)) {
            $current_headings = get_post_meta($post_id, '_metasync_otto_headings_data', true);
            $headings_json = json_encode($headings_data);
            if ($headings_json !== $current_headings) {
                update_post_meta($post_id, '_metasync_otto_headings_data', $headings_json);
                $fields_updated['headings_data'] = $headings_data;
                $any_updated = true;
            }
        }

        # Update structured data (including empty values for rollback)
        if ($structured_data !== null) {
            $current_structured = get_post_meta($post_id, '_metasync_otto_structured_data', true);
            if ($structured_data !== $current_structured) {
                update_post_meta($post_id, '_metasync_otto_structured_data', $structured_data);
                $fields_updated['structured_data'] = $structured_data;
                $any_updated = true;
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

        # Update basic meta fields (title and description)
        $basic_result = metasync_update_category_seo_meta_fields($category_id, $meta_title, $meta_description);
        if ($basic_result) {
            if ($meta_title) $fields_updated['meta_title'] = $meta_title;
            if ($meta_description) $fields_updated['meta_description'] = $meta_description;
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
    if (!$post_id || ($meta_title === null && $meta_description === null)) {
        return array(
            'updated' => false,
            'title_updated' => false,
            'description_updated' => false,
        );
    }

    $title_updated = false;
    $description_updated = false;

    try {
        # Compare against previously stored OTTO values to avoid unnecessary updates
        $current_title = get_post_meta($post_id, '_metasync_otto_title', true);
        $current_description = get_post_meta($post_id, '_metasync_otto_description', true);

        # Update if values are different OR if OTTO returns empty (to rollback/clear fields)
        $should_update_title = ($meta_title !== null && $meta_title !== $current_title);
        $should_update_description = ($meta_description !== null && $meta_description !== $current_description);

        # Update WordPress native meta fields only if changed
        if ($should_update_title) {
            update_post_meta($post_id, '_metasync_otto_title', $meta_title);
            $title_updated = true;
        }
        
        if ($should_update_description) {
            update_post_meta($post_id, '_metasync_otto_description', $meta_description);
            $description_updated = true;
        }

        # Update RankMath SEO plugin meta fields
        if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
            if ($title_updated) {
                update_post_meta($post_id, 'rank_math_title', $meta_title);
            }
            
            if ($description_updated) {
                update_post_meta($post_id, 'rank_math_description', $meta_description);
            }
        }

        # Update Yoast SEO plugin meta fields
        if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
            if ($title_updated) {
                update_post_meta($post_id, '_yoast_wpseo_title', $meta_title);
            }
            
            if ($description_updated) {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_description);
            }
        }

        # Update All in One SEO (AIOSEO) plugin meta fields
        if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
            metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
            
            # AIOSEO uses custom database tables, so we use their API if available
            if (class_exists('AIOSEO\\Plugin\\Common\\Models\\Post')) {
                try {
                    // @phpstan-ignore-next-line
                    $aioseo_post = \AIOSEO\Plugin\Common\Models\Post::getPost($post_id);
                    if ($aioseo_post) {
                        if ($title_updated) {
                            $aioseo_post->title = $meta_title;
                        }
                        if ($description_updated) {
                            $aioseo_post->description = $meta_description;
                        }
                        $aioseo_post->save();
                    }
                } catch (Exception $e) {
                    # Fallback: Use post meta (for older AIOSEO versions)
                    if ($title_updated) {
                        update_post_meta($post_id, '_aioseo_title', $meta_title);
                    }
                    if ($description_updated) {
                        update_post_meta($post_id, '_aioseo_description', $meta_description);
                    }
                }
            } else {
                # Fallback: Use post meta for AIOSEO
                if ($title_updated) {
                    update_post_meta($post_id, '_aioseo_title', $meta_title);
                }
                if ($description_updated) {
                    update_post_meta($post_id, '_aioseo_description', $meta_description);
                }
            }
        }

        # Store timestamp of last OTTO SEO update
        if ($title_updated || $description_updated) {
            update_post_meta($post_id, '_metasync_otto_last_update', current_time('timestamp'));
        }

        return array(
            'updated' => ($title_updated || $description_updated),
            'title_updated' => $title_updated,
            'description_updated' => $description_updated,
        );

    } catch (Exception $e) {
        //error_log("MetaSync OTTO: Exception updating SEO meta fields: " . $e->getMessage());
        return array(
            'updated' => false,
            'title_updated' => false,
            'description_updated' => false,
        );
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
    if (!$category_id || ($meta_title === null && $meta_description === null)) {
        return false;
    }

    $updated = false;

    try {
        # Compare against previously stored OTTO values to avoid unnecessary updates
        $current_title = get_term_meta($category_id, '_metasync_otto_title', true);
        $current_description = get_term_meta($category_id, '_metasync_otto_description', true);

        # Update if values are different OR if OTTO returns empty (to rollback/clear fields)
        $should_update_title = ($meta_title !== null && $meta_title !== $current_title);
        $should_update_description = ($meta_description !== null && $meta_description !== $current_description);

        # Update WordPress native term meta fields only if changed
        if ($should_update_title) {
            update_term_meta($category_id, '_metasync_otto_title', $meta_title);
            $updated = true;
        }
        
        if ($should_update_description) {
            update_term_meta($category_id, '_metasync_otto_description', $meta_description);
            $updated = true;
        }

        # Update RankMath SEO plugin category meta fields
        if (metasync_is_plugin_active('seo-by-rank-math/rank-math.php') || metasync_is_plugin_active('seo-by-rankmath/rank-math.php')) {
            if ($should_update_title) {
                update_term_meta($category_id, 'rank_math_title', $meta_title);
            }
            
            if ($should_update_description) {
                update_term_meta($category_id, 'rank_math_description', $meta_description);
            }
        }

        # Update Yoast SEO plugin category meta fields
        if (metasync_is_plugin_active('wordpress-seo/wp-seo.php')) {
            if ($should_update_title) {
                update_term_meta($category_id, '_yoast_wpseo_title', $meta_title);
            }
            
            if ($should_update_description) {
                update_term_meta($category_id, '_yoast_wpseo_metadesc', $meta_description);
            }
        }

        # Update All in One SEO (AIOSEO) plugin category meta fields
        if (metasync_is_plugin_active('all-in-one-seo-pack/all_in_one_seo_pack.php') ||
            metasync_is_plugin_active('all-in-one-seo-pack-pro/all_in_one_seo_pack.php')) {
            
            # AIOSEO uses custom database tables, so we use their API if available
            if (class_exists('AIOSEO\\Plugin\\Common\\Models\\Term')) {
                try {
                    // @phpstan-ignore-next-line
                    $aioseo_term = \AIOSEO\Plugin\Common\Models\Term::getTerm($category_id);
                    if ($aioseo_term) {
                        if ($should_update_title) {
                            $aioseo_term->title = $meta_title;
                        }
                        if ($should_update_description) {
                            $aioseo_term->description = $meta_description;
                        }
                        $aioseo_term->save();
                    }
                } catch (Exception $e) {
                    # Fallback: Use term meta (for older AIOSEO versions)
                    if ($should_update_title) {
                        update_term_meta($category_id, '_aioseo_title', $meta_title);
                    }
                    if ($should_update_description) {
                        update_term_meta($category_id, '_aioseo_description', $meta_description);
                    }
                }
            } else {
                # Fallback: Use term meta for AIOSEO
                if ($should_update_title) {
                    update_term_meta($category_id, '_aioseo_title', $meta_title);
                }
                if ($should_update_description) {
                    update_term_meta($category_id, '_aioseo_description', $meta_description);
                }
            }
        }

        # Store timestamp of last OTTO SEO update if any change
        if ($should_update_title || $should_update_description) {
            update_term_meta($category_id, '_metasync_otto_last_update', current_time('timestamp'));
        }

        return $updated;

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
 */
function metasync_register_seo_meta_fields() {
    $post_types = array('post', 'page');
    
    foreach ($post_types as $post_type) {
        # MetaSync OTTO fields
        register_post_meta($post_type, '_metasync_otto_title', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        register_post_meta($post_type, '_metasync_otto_description', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        # RankMath fields
        register_post_meta($post_type, 'rank_math_title', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        register_post_meta($post_type, 'rank_math_description', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        # Yoast SEO fields
        register_post_meta($post_type, '_yoast_wpseo_title', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
        
        register_post_meta($post_type, '_yoast_wpseo_metadesc', array(
            'type' => 'string',
            'single' => true,
            'show_in_rest' => true,
            'auth_callback' => function() {
                return current_user_can('edit_posts');
            }
        ));
    }
}

/**
 * Admin notice to inform about OTTO SEO integration
 */
// function metasync_otto_seo_admin_notice() {
//     if (!current_user_can('manage_options')) {
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

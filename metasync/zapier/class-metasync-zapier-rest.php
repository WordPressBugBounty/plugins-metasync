<?php
/**
 * Zapier REST API Endpoints
 *
 * Provides the REST Hook endpoints that Zapier calls to manage subscriptions,
 * plus a polling fallback and an admin listing endpoint.
 *
 * Endpoints:
 *   POST   /wp-json/metasync/v1/zapier/subscribe      — Zapier registers a hook
 *   DELETE /wp-json/metasync/v1/zapier/unsubscribe    — Zapier removes a hook
 *   GET    /wp-json/metasync/v1/zapier/hooks          — Admin: list all subscriptions
 *   GET    /wp-json/metasync/v1/zapier/poll/{event}   — Zapier polling fallback
 *
 * Authentication: X-API-Key header (same MetaSync plugin auth token used by MCP).
 *
 * @package    Metasync
 * @subpackage Metasync/zapier
 * @since      2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Zapier_REST {

    private const NS = 'metasync/v1';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    // ------------------------------------------------------------------
    // Route registration
    // ------------------------------------------------------------------

    public function register_routes(): void {
        // Subscribe (Zapier REST Hook)
        register_rest_route(self::NS, '/zapier/subscribe', [
            'methods'             => 'POST',
            'callback'            => [$this, 'subscribe'],
            'permission_callback' => [$this, 'check_api_key'],
            'args'                => [
                'target_url' => [
                    'required'          => true,
                    'type'              => 'string',
                    'format'            => 'uri',
                    'sanitize_callback' => 'esc_url_raw',
                    'validate_callback' => [$this, 'validate_url'],
                ],
                'event' => [
                    'required'          => true,
                    'type'              => 'string',
                    'enum'              => Metasync_Zapier_Database::EVENTS,
                ],
            ],
        ]);

        // Unsubscribe (Zapier REST Hook)
        register_rest_route(self::NS, '/zapier/unsubscribe', [
            'methods'             => ['DELETE', 'POST'],
            'callback'            => [$this, 'unsubscribe'],
            'permission_callback' => [$this, 'check_api_key'],
            'args'                => [
                'subscription_id' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Admin: list all subscriptions
        register_rest_route(self::NS, '/zapier/hooks', [
            'methods'             => 'GET',
            'callback'            => [$this, 'list_hooks'],
            'permission_callback' => [$this, 'check_api_key'],
        ]);

        // Polling fallback: returns the N most-recently published/updated/deleted posts
        register_rest_route(self::NS, '/zapier/poll/(?P<event>[a-z_]+)', [
            'methods'             => 'GET',
            'callback'            => [$this, 'poll'],
            'permission_callback' => [$this, 'check_api_key'],
            'args'                => [
                'event' => [
                    'required'          => true,
                    'type'              => 'string',
                    'enum'              => Metasync_Zapier_Database::EVENTS,
                ],
                'limit' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 5,
                    'minimum'           => 1,
                    'maximum'           => 100,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Handlers
    // ------------------------------------------------------------------

    /**
     * Register a new Zapier REST Hook subscription.
     */
    public function subscribe(WP_REST_Request $request): WP_REST_Response {
        $target_url = $request->get_param('target_url');
        $event      = $request->get_param('event');

        $subscription_id = Metasync_Zapier_Database::insert($target_url, $event);

        if (is_wp_error($subscription_id)) {
            return new WP_REST_Response(
                ['error' => $subscription_id->get_error_message()],
                500
            );
        }

        return new WP_REST_Response(
            [
                'subscription_id' => $subscription_id,
                'target_url'      => $target_url,
                'event'           => $event,
                'message'         => 'Subscription created.',
            ],
            201
        );
    }

    /**
     * Remove a Zapier REST Hook subscription.
     */
    public function unsubscribe(WP_REST_Request $request): WP_REST_Response {
        $subscription_id = $request->get_param('subscription_id');

        $deleted = Metasync_Zapier_Database::delete($subscription_id);

        if (!$deleted) {
            return new WP_REST_Response(
                ['error' => 'Subscription not found.'],
                404
            );
        }

        return new WP_REST_Response(
            ['message' => 'Subscription removed.'],
            200
        );
    }

    /**
     * List all registered subscriptions (admin use).
     */
    public function list_hooks(WP_REST_Request $request): WP_REST_Response {
        $rows = Metasync_Zapier_Database::get_all();

        // Mask target URLs for security (show only host + path start)
        $sanitized = array_map(function (array $row): array {
            $parsed             = wp_parse_url($row['target_url']);
            $row['target_url_display'] = ($parsed['host'] ?? '') . '/' . substr($parsed['path'] ?? '', 1, 20) . '…';
            return $row;
        }, $rows);

        return new WP_REST_Response(
            [
                'count'         => count($rows),
                'subscriptions' => $sanitized,
            ],
            200
        );
    }

    /**
     * Polling fallback — returns recently published/updated/deleted posts.
     * Zapier uses this when configuring the trigger to show sample data.
     */
    public function poll(WP_REST_Request $request): WP_REST_Response {
        $event = $request->get_param('event');
        $limit = $request->get_param('limit');

        $dispatcher = new Metasync_Zapier_Dispatcher_Readonly();
        $items      = $dispatcher->get_recent_for_poll($event, $limit);

        return new WP_REST_Response($items, 200);
    }

    // ------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------

    /**
     * Validate requests using the MetaSync plugin auth token (same as MCP).
     */
    public function check_api_key(WP_REST_Request $request): bool {
        // Accept logged-in admins
        if (current_user_can('manage_options')) {
            return true;
        }

        $options    = get_option('metasync_options', []);
        $stored_key = $options['general']['apikey'] ?? '';

        if (empty($stored_key)) {
            return false;
        }

        $provided = $request->get_header('X-API-Key')
                 ?: $request->get_param('api_key');

        if (empty($provided)) {
            return false;
        }

        return hash_equals($stored_key, $provided);
    }

    // ------------------------------------------------------------------
    // Validators
    // ------------------------------------------------------------------

    public function validate_url($value): bool {
        return (bool) filter_var($value, FILTER_VALIDATE_URL);
    }
}

/**
 * Thin read-only helper used only by the polling endpoint.
 * Avoids instantiating the full dispatcher (which registers hooks).
 */
class Metasync_Zapier_Dispatcher_Readonly {

    public function get_recent_for_poll(string $event, int $limit): array {
        $status = 'publish';

        if ($event === 'post_deleted') {
            // Return recently trashed posts as sample data
            $status = 'trash';
        }

        $posts = get_posts([
            'post_status'    => $status,
            'posts_per_page' => $limit,
            'orderby'        => $event === 'post_published' ? 'date' : 'modified',
            'order'          => 'DESC',
            'post_type'      => ['post', 'page'],
        ]);

        return array_map(function (WP_Post $post) use ($event): array {
            $author = get_userdata($post->post_author);

            $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);
            $tags       = wp_get_post_tags($post->ID, ['fields' => 'names']);

            $thumbnail_url = '';
            if ($thumb_id = get_post_thumbnail_id($post->ID)) {
                $img           = wp_get_attachment_image_src($thumb_id, 'full');
                $thumbnail_url = $img ? $img[0] : '';
            }

            $seo_meta = [];
            foreach ([
                'title'          => '_metasync_metatitle',
                'description'    => '_metasync_metadesc',
                'focus_keyword'  => '_metasync_focus_keyword',
                'canonical_url'  => '_metasync_canonical_url',
            ] as $label => $key) {
                $seo_meta[$label] = get_post_meta($post->ID, $key, true) ?: '';
            }

            return [
                'event'          => $event,
                'id'             => $post->ID,
                'title'          => get_the_title($post),
                'url'            => get_permalink($post),
                'slug'           => $post->post_name,
                'status'         => $post->post_status,
                'post_type'      => $post->post_type,
                'excerpt'        => has_excerpt($post->ID)
                                       ? wp_strip_all_tags(get_the_excerpt($post))
                                       : '',
                'author'         => $author ? $author->display_name : '',
                'categories'     => is_array($categories) ? array_values($categories) : [],
                'tags'           => is_array($tags) ? array_values($tags) : [],
                'featured_image' => $thumbnail_url,
                'published_at'   => get_the_date('c', $post),
                'modified_at'    => get_the_modified_date('c', $post),
                'site_url'       => get_site_url(),
                'site_name'      => get_bloginfo('name'),
                'seo'            => $seo_meta,
            ];
        }, $posts);
    }
}

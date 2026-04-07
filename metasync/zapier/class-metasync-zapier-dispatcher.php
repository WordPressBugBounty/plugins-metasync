<?php
/**
 * Zapier Event Dispatcher
 *
 * Listens to WordPress post lifecycle events and delivers webhook payloads
 * to all registered Zapier subscriptions for the matching event.
 *
 * Delivery is asynchronous: payloads are queued via wp_schedule_single_event
 * so the publish/update/delete action never blocks on the HTTP call.
 *
 * @package    Metasync
 * @subpackage Metasync/zapier
 * @since      2.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Zapier_Dispatcher {

    /** Post types to watch. Empty array = all post types. */
    private const WATCHED_POST_TYPES = ['post', 'page'];

    /** Number of delivery attempts before giving up. */
    private const MAX_ATTEMPTS = 3;

    /** HTTP timeout in seconds for each delivery attempt. */
    private const HTTP_TIMEOUT = 10;

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------

    public function __construct() {
        // post_published
        add_action('transition_post_status', [$this, 'on_status_transition'], 10, 3);

        // post_updated (only fires when a post that was already published is updated)
        add_action('post_updated', [$this, 'on_post_updated'], 10, 3);

        // post_deleted (fires just before permanent deletion)
        add_action('before_delete_post', [$this, 'on_before_delete'], 10, 2);

        // Async delivery worker (fired by wp_schedule_single_event)
        add_action('metasync_zapier_deliver', [$this, 'deliver_payload'], 10, 3);
    }

    // ------------------------------------------------------------------
    // WordPress hooks
    // ------------------------------------------------------------------

    /**
     * Fires when a post transitions status.
     * We capture the "published" event here.
     */
    public function on_status_transition(string $new_status, string $old_status, WP_Post $post): void {
        if (!$this->is_watched($post)) {
            return;
        }

        if ($new_status === 'publish' && $old_status !== 'publish') {
            $this->queue_event('post_published', $this->build_payload('post_published', $post));
        }
    }

    /**
     * Fires when a published post is updated (saved while already published).
     */
    public function on_post_updated(int $post_id, WP_Post $post_after, WP_Post $post_before): void {
        if (!$this->is_watched($post_after)) {
            return;
        }

        // Only fire if the post was already published (not a draft save)
        if ($post_after->post_status !== 'publish') {
            return;
        }

        // Avoid double-firing when a draft goes directly to publish
        // (that's handled by on_status_transition)
        if ($post_before->post_status !== 'publish') {
            return;
        }

        $this->queue_event('post_updated', $this->build_payload('post_updated', $post_after));
    }

    /**
     * Fires just before a post is permanently deleted.
     */
    public function on_before_delete(int $post_id, WP_Post $post): void {
        if (!$this->is_watched($post)) {
            return;
        }

        // Only fire for previously published content
        if (!in_array($post->post_status, ['publish', 'trash'], true)) {
            return;
        }

        $this->queue_event('post_deleted', $this->build_payload('post_deleted', $post));
    }

    // ------------------------------------------------------------------
    // Queue & delivery
    // ------------------------------------------------------------------

    /**
     * Schedule async delivery for every subscription matching the event.
     */
    private function queue_event(string $event, array $payload): void {
        $subscriptions = Metasync_Zapier_Database::get_by_event($event);

        if (empty($subscriptions)) {
            return;
        }

        foreach ($subscriptions as $sub) {
            // Schedule immediately (0-second delay) so it runs in a separate request
            wp_schedule_single_event(
                time(),
                'metasync_zapier_deliver',
                [$sub['subscription_id'], $sub['target_url'], $payload]
            );
        }

        // Ensure the cron runs right away (WP-Cron is lazy by default)
        spawn_cron();
    }

    /**
     * Deliver a payload to a single target URL with retry logic.
     * Called by the wp-cron worker.
     *
     * @param string $subscription_id
     * @param string $target_url
     * @param array  $payload
     */
    public function deliver_payload(string $subscription_id, string $target_url, array $payload): void {
        $body    = wp_json_encode($payload);
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent'   => 'MetaSync-Zapier/' . METASYNC_VERSION,
            'X-MetaSync-Event' => $payload['event'] ?? '',
        ];

        $success  = false;
        $last_err = '';

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = wp_remote_post($target_url, [
                'body'      => $body,
                'headers'   => $headers,
                'timeout'   => self::HTTP_TIMEOUT,
                'sslverify' => true,
            ]);

            if (is_wp_error($response)) {
                $last_err = $response->get_error_message();
                // Exponential backoff: sleep before retry (only for subsequent attempts)
                if ($attempt < self::MAX_ATTEMPTS) {
                    sleep(2 ** ($attempt - 1));
                }
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);

            if ($code >= 200 && $code < 300) {
                $success = true;
                break;
            }

            $last_err = 'HTTP ' . $code;

            if ($attempt < self::MAX_ATTEMPTS) {
                sleep(2 ** ($attempt - 1));
            }
        }

        if ($success) {
            Metasync_Zapier_Database::record_fire($subscription_id);
        } else {
            // Log failure so admins can see it in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(
                    sprintf(
                        '[MetaSync Zapier] Delivery failed for subscription %s after %d attempts. Last error: %s. URL: %s',
                        $subscription_id,
                        self::MAX_ATTEMPTS,
                        $last_err,
                        $target_url
                    )
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Payload builder
    // ------------------------------------------------------------------

    /**
     * Build the webhook payload for a given event and post.
     */
    private function build_payload(string $event, WP_Post $post): array {
        $author = get_userdata($post->post_author);

        $categories = wp_get_post_categories($post->ID, ['fields' => 'names']);
        $tags       = wp_get_post_tags($post->ID, ['fields' => 'names']);

        $thumbnail_url = '';
        if ($thumb_id = get_post_thumbnail_id($post->ID)) {
            $img           = wp_get_attachment_image_src($thumb_id, 'full');
            $thumbnail_url = $img ? $img[0] : '';
        }

        $payload = [
            'event'       => $event,
            'id'          => $post->ID,
            'title'       => get_the_title($post),
            'url'         => get_permalink($post),
            'slug'        => $post->post_name,
            'status'      => $post->post_status,
            'post_type'   => $post->post_type,
            'excerpt'     => has_excerpt($post->ID)
                                ? wp_strip_all_tags(get_the_excerpt($post))
                                : '',
            'author'      => $author ? $author->display_name : '',
            'author_email'=> $author ? $author->user_email : '',
            'categories'  => is_array($categories) ? array_values($categories) : [],
            'tags'        => is_array($tags) ? array_values($tags) : [],
            'featured_image' => $thumbnail_url,
            'published_at'   => get_the_date('c', $post),
            'modified_at'    => get_the_modified_date('c', $post),
            'site_url'       => get_site_url(),
            'site_name'      => get_bloginfo('name'),
        ];

        // Append MetaSync SEO meta when available
        $payload['seo'] = $this->get_seo_meta($post->ID);

        return $payload;
    }

    /**
     * Collect MetaSync SEO meta for the payload.
     */
    private function get_seo_meta(int $post_id): array {
        $keys = [
            'title'         => '_metasync_metatitle',
            'description'   => '_metasync_metadesc',
            'focus_keyword' => '_metasync_focus_keyword',
            'robots_index'  => '_metasync_robots_index',
            'canonical_url' => '_metasync_canonical_url',
            'og_title'      => '_metasync_og_title',
            'og_description'=> '_metasync_og_description',
            'og_image'      => '_metasync_og_image',
        ];

        $seo = [];
        foreach ($keys as $label => $meta_key) {
            $value = get_post_meta($post_id, $meta_key, true);
            $seo[$label] = $value ?: '';
        }

        return $seo;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function is_watched(WP_Post $post): bool {
        if (empty(self::WATCHED_POST_TYPES)) {
            return true;
        }

        return in_array($post->post_type, self::WATCHED_POST_TYPES, true);
    }
}

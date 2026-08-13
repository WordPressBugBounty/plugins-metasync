<?php
/**
 * Unified "SEO Suite" meta box.
 *
 * Consolidates MetaSync's separate Classic-editor meta boxes (SEO, Robots,
 * Canonical, Redirection, Social & Open Graph, Schema, Video Sitemap) into a
 * single tabbed box. Classic editor only — the block editor keeps its own SEO
 * sidebar and is left untouched.
 *
 * Presentation-only merge: this class does NOT reimplement any save logic. It
 * runs after MetaSync registers its own boxes, captures their render callbacks,
 * removes them from display, and renders the same fields (identical name/nonce
 * attributes) inside one tabbed container. The plugin's existing save_post
 * handlers persist everything unchanged. Schema is rendered via MetaSync's own
 * builder callback. Custom HTML settings stay in their standalone box.
 *
 * On LPS / custom-HTML pages (metasync_is_custom_or_lps_page) the box shows a
 * read-only notice and emits no nonces, so nothing here overwrites the
 * externally-managed SEO for those pages.
 *
 * @package Metasync
 */

if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Seo_Suite
{
    /** Original MetaSync meta-box IDs this box absorbs (Custom HTML is intentionally excluded). */
    private $target_ids = array(
        'metasync-seo-meta',
        'metasync-seo-lps-notice',
        'common-robots-meta',
        'advance-robots-meta',
        'post-canonical-meta',
        'post-redirection-meta',
        'metasync_opengraph_meta_box',
        'metasync-schema-markup',
        'metasync-video-sitemap-meta',
    );

    /** @var array<string,bool> Which target boxes were present on the current screen. */
    private $present = array();

    /** @var array|null Captured Schema meta-box definition (rendered inside the Schema tab). */
    private $schema_box = null;

    public function __construct()
    {
        add_action('add_meta_boxes', array($this, 'unify'), 9999, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /** Load the media library for the OG/Twitter image pickers. */
    public function enqueue_assets($hook)
    {
        if (in_array($hook, array('post.php', 'post-new.php'), true)) {
            wp_enqueue_media();
        }
    }

    /**
     * True for any request originating from the block editor.
     *
     * The block editor renders its meta-box area during the main page load
     * (is_block_editor() is true there) and re-posts that same form to
     * post.php?meta-box-loader=1 when saving. Both are treated as block-editor
     * context so this box never engages in either.
     */
    private function is_block_editor_request()
    {
        if (!empty($_REQUEST['meta-box-loader'])) {
            return true;
        }
        $screen = get_current_screen();
        return $screen && $screen->is_block_editor();
    }

    /**
     * Runs after MetaSync registers its boxes (default priority). Captures the
     * target boxes, removes them from display, and registers the unified box.
     */
    public function unify($post_type, $post)
    {
        /** Allow sites to opt out of the unified box and keep the classic separate boxes. */
        if (!apply_filters('metasync_enable_seo_suite', true, $post_type, $post)) {
            return;
        }

        // Classic editor only. In the block editor the plugin's own SEO sidebar owns
        // these fields, and the SEO box is registered __back_compat_meta_box so it
        // never renders there at all. Returning before any remove_meta_box() call
        // leaves the block editor byte-for-byte as it is without this class.
        if ($this->is_block_editor_request()) {
            return;
        }

        global $wp_meta_boxes;
        if (empty($wp_meta_boxes[$post_type])) {
            return;
        }

        $this->present = array();
        $this->schema_box = null;

        foreach ($wp_meta_boxes[$post_type] as $context => $priorities) {
            foreach ((array) $priorities as $boxes) {
                if (!is_array($boxes)) {
                    continue;
                }
                foreach ($boxes as $box_id => $box) {
                    if (in_array($box_id, $this->target_ids, true) && $box && !empty($box['callback'])) {
                        $this->present[$box_id] = true;
                        if ($box_id === 'metasync-schema-markup') {
                            $this->schema_box = $box;
                        }
                    }
                }
            }
            foreach ($this->target_ids as $id) {
                remove_meta_box($id, $post_type, $context);
            }
        }

        if (empty($this->present)) {
            return;
        }

        if (empty($wp_meta_boxes[$post_type]['normal']['high']['metasync_seo_suite'])) {
            $plugin_name = class_exists('Metasync') ? Metasync::get_effective_plugin_name() : 'MetaSync';
            add_meta_box(
                'metasync_seo_suite',
                sprintf('SEO Suite by %s', $plugin_name),
                array($this, 'render'),
                $post_type,
                'normal',
                'high'
            );
        }
    }

    /** True when the page's front-end output bypasses WordPress SEO (LPS / custom-HTML). */
    private function is_managed_page($post_id)
    {
        if (function_exists('metasync_is_custom_or_lps_page') && $post_id > 0 && metasync_is_custom_or_lps_page($post_id)) {
            return true;
        }
        // Fallbacks if the helper isn't loaded on this request.
        if (get_post_meta($post_id, '_metasync_lps_import', true)) {
            return true;
        }
        if (get_post_meta($post_id, '_metasync_is_custom_html_page', true) === '1'
            && get_post_meta($post_id, '_metasync_raw_html_enabled', true) === '1') {
            return true;
        }
        return false;
    }

    private static function v($post_id, $key)
    {
        $value = get_post_meta($post_id, $key, true);
        return is_array($value) ? '' : (string) $value;
    }

    private static function checked_attr($cond)
    {
        return $cond ? ' checked' : '';
    }

    /** A clean, "pretty" permalink for placeholders/preview (avoids ?p=ID on drafts). */
    private static function pretty_permalink($post)
    {
        $link = get_permalink($post->ID);
        if ($link
            && strpos($link, '?p=') === false
            && strpos($link, '?page_id=') === false
            && strpos($link, 'preview=') === false) {
            return $link;
        }
        $slug = $post->post_name ? $post->post_name : sanitize_title(get_the_title($post->ID));
        if (!$slug) {
            $slug = 'your-post';
        }
        return user_trailingslashit(home_url('/' . $slug . '/'));
    }

    /**
     * Render the unified box.
     */
    public function render($post)
    {
        $P = $this->present;
        $id = $post->ID;
        $a = 'esc_attr';

        $managed = $this->is_managed_page($id);

        $has_seo    = isset($P['metasync-seo-meta']) || isset($P['metasync-seo-lps-notice']);
        $has_crob   = isset($P['common-robots-meta']);
        $has_arob   = isset($P['advance-robots-meta']);
        $has_robots = $has_crob || $has_arob;
        $has_canon  = isset($P['post-canonical-meta']);
        $has_redir  = isset($P['post-redirection-meta']);
        $has_social = isset($P['metasync_opengraph_meta_box']);
        $has_schema = isset($P['metasync-schema-markup']);
        $has_video  = isset($P['metasync-video-sitemap-meta']);

        // Current values.
        $seo_title  = self::v($id, '_metasync_seo_title');
        $seo_desc   = self::v($id, '_metasync_seo_desc');
        $otto_title = self::v($id, '_metasync_otto_title');
        $otto_desc  = self::v($id, '_metasync_otto_description');
        $otto_kw    = self::v($id, '_metasync_otto_keywords');

        // Robots: post meta first, falling back to the site-wide defaults exactly as
        // the standalone Common/Advance Robots boxes do (new option spelling first,
        // then the legacy "mata" spelling).
        $cr = get_post_meta($id, 'metasync_common_robots', true);
        if (!$cr && class_exists('Metasync')) {
            $cr = Metasync::get_option('common_robots_meta') ?? Metasync::get_option('common_robots_mata') ?? '';
        }
        if (!is_array($cr)) {
            $cr = array();
        }
        $ar = get_post_meta($id, 'metasync_advance_robots', true);
        if (!$ar && class_exists('Metasync')) {
            $ar = Metasync::get_option('advance_robots_meta') ?? Metasync::get_option('advance_robots_mata') ?? '';
        }
        if (!is_array($ar)) {
            $ar = array();
        }
        $ar_snip_e = isset($ar['max-snippet']['enable']) ? $ar['max-snippet']['enable'] : '';
        $ar_snip_l = isset($ar['max-snippet']['length']) ? $ar['max-snippet']['length'] : '';
        $ar_vid_e  = isset($ar['max-video-preview']['enable']) ? $ar['max-video-preview']['enable'] : '';
        $ar_vid_l  = isset($ar['max-video-preview']['length']) ? $ar['max-video-preview']['length'] : '';
        $ar_img_e  = isset($ar['max-image-preview']['enable']) ? $ar['max-image-preview']['enable'] : '';
        $ar_img_l  = isset($ar['max-image-preview']['length']) ? $ar['max-image-preview']['length'] : '';

        // Canonical: same sanitise-and-repair path as the standalone Canonical box.
        // A bare reset() here would re-corrupt nested-array rows to the string "Array".
        $raw_canonical = get_post_meta($id, 'meta_canonical', true);
        if (class_exists('Metasync_Canonical_Sanitizer')) {
            $canon = Metasync_Canonical_Sanitizer::sanitize($raw_canonical);
            if (is_array($raw_canonical) || Metasync_Canonical_Sanitizer::is_corrupted($raw_canonical)) {
                if ($canon !== '') {
                    update_post_meta($id, 'meta_canonical', $canon);
                } else {
                    delete_post_meta($id, 'meta_canonical');
                }
            } elseif ($canon === '' && is_string($raw_canonical) && trim($raw_canonical) !== '') {
                // Stored value the validator doesn't recognise: show it as-is so a
                // routine post save doesn't silently wipe it.
                $canon = trim($raw_canonical);
            }
        } else {
            $canon = is_array($raw_canonical) ? '' : (string) $raw_canonical;
        }
        $pretty = self::pretty_permalink($post);

        $rd = get_post_meta($id, 'metasync_post_redirection_meta', true);
        if (!is_array($rd)) {
            $rd = array();
        }
        $rd_en   = (isset($rd['enable']) ? $rd['enable'] : '') === 'true';
        $rd_type = isset($rd['type']) ? $rd['type'] : '301';
        $rd_url  = isset($rd['url']) ? $rd['url'] : '';

        // The standalone Open Graph box defaults this setting to enabled when
        // no value has been saved yet. Preserve that behaviour: only an
        // explicit '0' represents an editor opting out.
        $og_en_value = get_post_meta($id, '_metasync_og_enabled', true);
        $og_en       = ($og_en_value !== '0');
        // Match the standalone Open Graph box exactly: its computed defaults are
        // real form values (and are therefore persisted on save), not placeholders.
        $og_defaults = array('title' => '', 'description' => '', 'image' => '');
        $opengraph = class_exists('Metasync_OpenGraph') ? Metasync_OpenGraph::get_instance() : null;
        if ($opengraph) {
            $og_defaults = $opengraph->get_default_og_values($id);
        }
        $og_title = self::v($id, '_metasync_og_title') ?: $og_defaults['title'];
        $og_desc  = self::v($id, '_metasync_og_description') ?: $og_defaults['description'];
        $og_image = self::v($id, '_metasync_og_image') ?: $og_defaults['image'];
        $og_type  = self::v($id, '_metasync_og_type') ?: 'article';
        $og_url   = self::v($id, '_metasync_og_url');
        if ($og_url === '') {
            $og_url = $opengraph ? $opengraph->get_canonical_url($post) : $pretty;
        }
        $tw_card  = self::v($id, '_metasync_twitter_card') ?: 'summary_large_image';
        $tw_site  = self::v($id, '_metasync_twitter_site');
        $tw_title = self::v($id, '_metasync_twitter_title') ?: $og_title;
        $tw_desc  = self::v($id, '_metasync_twitter_description') ?: $og_desc;
        $tw_image = self::v($id, '_metasync_twitter_image') ?: $og_image;
        $tw_alt   = self::v($id, '_metasync_twitter_image_alt');
        $tw_app_iphone = self::v($id, '_metasync_twitter_app_id_iphone');
        $tw_app_ipad   = self::v($id, '_metasync_twitter_app_id_ipad');
        $tw_app_gp     = self::v($id, '_metasync_twitter_app_id_googleplay');
        $tw_app_url_iphone = self::v($id, '_metasync_twitter_app_url_iphone');
        $tw_app_url_ipad   = self::v($id, '_metasync_twitter_app_url_ipad');
        $tw_app_url_gp     = self::v($id, '_metasync_twitter_app_url_googleplay');
        $tw_app_country    = self::v($id, '_metasync_twitter_app_country');
        $tw_player     = self::v($id, '_metasync_twitter_player');
        $tw_player_w   = self::v($id, '_metasync_twitter_player_width');
        $tw_player_h   = self::v($id, '_metasync_twitter_player_height');
        $tw_app_disabled = $tw_card === 'app' ? '' : ' disabled';
        $tw_player_disabled = $tw_card === 'player' ? '' : ' disabled';

        $v_url   = self::v($id, '_metasync_video_url');
        $v_thumb = self::v($id, '_metasync_video_thumbnail');
        $v_title = self::v($id, '_metasync_video_title');
        $v_desc  = self::v($id, '_metasync_video_description');
        $v_dur   = self::v($id, '_metasync_video_duration');

        $host    = strtoupper((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $host_lc = strtolower($host);
        $prev_url_disp = preg_replace('#^https?://#', '', (string) $pretty);
        $site = get_bloginfo('name');
        if ($site === '') {
            $site = $host;
        }
        $avatar = strtoupper(function_exists('mb_substr') ? mb_substr($site, 0, 1) : substr($site, 0, 1));
        $handle = sanitize_title($site) ?: 'site';

        $this->styles();
        ?>
        <div id="mss-root" data-theme="light"<?php echo $managed ? ' class="lps"' : ''; ?>>
          <?php
          // On managed pages (LPS / custom-HTML) we emit NO nonces, so nothing here can
          // overwrite the externally-managed SEO for those pages.
          if (!$managed) {
              if ($has_seo)    { wp_nonce_field('metasync_seo_meta_nonce', 'metasync_seo_meta_nonce'); }
              if ($has_crob)   { wp_nonce_field('metasync_common_robots_nonce', 'metasync_common_robots_nonce'); }
              if ($has_arob)   { wp_nonce_field('metasync_advance_robots_nonce', 'metasync_advance_robots_nonce'); }
              if ($has_canon)  { wp_nonce_field('metasync_post_canonical_nonce', 'metasync_post_canonical_nonce'); }
              if ($has_redir)  { wp_nonce_field('metasync_post_redirection_nonce', 'metasync_post_redirection_nonce'); }
              if ($has_social) { wp_nonce_field('metasync_opengraph_nonce', 'metasync_opengraph_nonce'); }
              if ($has_video)  { wp_nonce_field('metasync_video_sitemap_meta_nonce', 'metasync_video_sitemap_meta_nonce'); }
          }
          ?>
          <div class="metabox">
            <div class="mb-head">
              <div class="badge">M</div>
              <div class="t"><b>SEO Suite</b><span>On-page SEO for this post</span></div>
              <div class="mss-actions">
                <button type="button" class="theme-toggle" onclick="mssTheme(this)"><span class="ti">&#127769;</span> <span class="tl">Dark</span></button>
              </div>
            </div>

            <div class="mss-lockable">
            <div class="mb-body"<?php echo $managed ? ' inert' : ''; ?>>
              <div class="rail">
                <?php
                $first = true;
                $tab = function ($key, $icon, $label) use (&$first) {
                    echo '<div class="nav' . ($first ? ' active' : '') . '" role="button" tabindex="0" onclick="mssGo(this,\'' . esc_attr($key) . '\')" onkeydown="mssKey(event,this)"><span class="ic">' . $icon . '</span> ' . $label . '</div>';
                    $first = false;
                };
                if ($has_seo)    { $tab('seo', '&#128269;', 'SEO'); }
                if ($has_robots) { $tab('robots', '&#129302;', 'Robots'); }
                if ($has_canon)  { $tab('canonical', '&#128279;', 'Canonical'); }
                if ($has_redir)  { $tab('redirect', '&#8618;&#65039;', 'Redirection'); }
                if ($has_social) { $tab('social', '&#128226;', 'Social &amp; OG'); }
                if ($has_schema) { $tab('schema', '&#128208;', 'Schema'); }
                if ($has_video)  { $tab('video', '&#127916;', 'Video'); }
                ?>
              </div>

              <div class="panel-wrap">
                <?php
                $pfirst = true;
                $pcls = function () use (&$pfirst) {
                    $c = 'panel' . ($pfirst ? ' active' : '');
                    $pfirst = false;
                    return $c;
                };
                ?>

                <?php if ($has_seo): ?>
                <div class="<?php echo $pcls(); ?>" data-p="seo">
                  <h2 class="p-title">&#128269; Search Appearance</h2>
                  <p class="p-sub">Set the SEO Title and Meta Description. Leave blank to use the OTTO suggestion.</p>
                  <div class="field">
                    <div class="lbl"><span>SEO Title</span><span class="cc"><span class="tC">0</span>/60</span></div>
                    <input class="ctrl seoT" name="metasync_seo_title" value="<?php echo $a($seo_title); ?>" placeholder="<?php echo $a($otto_title); ?>" oninput="mssRc()">
                  </div>
                  <div class="field">
                    <div class="lbl"><span>Meta Description</span><span class="cc"><span class="dC">0</span>/160</span></div>
                    <textarea class="ctrl seoD" name="metasync_seo_desc" placeholder="<?php echo $a($otto_desc); ?>" oninput="mssRc()"><?php echo esc_textarea($seo_desc); ?></textarea>
                  </div>
                  <?php if ($otto_kw !== ''): ?>
                  <div class="field">
                    <div class="lbl"><span>Focus Keyword</span><span class="ro-badge">Managed by OTTO</span></div>
                    <div class="mss-ro"><span class="lock">&#128274;</span> <?php echo esc_html($otto_kw); ?></div>
                    <div class="hint">Set in Search Atlas &mdash; shown here for reference. Read-only.</div>
                  </div>
                  <?php endif; ?>
                  <div class="sec">Preview</div>
                  <div class="gprev">
                    <div class="u"><?php echo esc_html($prev_url_disp); ?></div>
                    <div class="ti gT"><?php echo esc_html($seo_title ?: ($otto_title ?: get_the_title($id))); ?></div>
                    <div class="de gD"><?php echo esc_html($seo_desc ?: ($otto_desc ?: 'No description set.')); ?></div>
                  </div>
                </div>
                <?php endif; ?>

                <?php if ($has_robots): ?>
                <div class="<?php echo $pcls(); ?>" data-p="robots">
                  <h2 class="p-title">&#129302; Robots Meta</h2>
                  <p class="p-sub">Crawler directives for this post. These are independent flags &mdash; combine as needed.</p>
                  <?php if ($has_crob): ?>
                  <div class="sec">Common directives</div>
                  <div class="chips">
                    <?php
                    $common = array(
                        'index'        => array('Index', 'Allow search engines to index this page'),
                        'noindex'      => array('No Index', 'Keep this page out of results'),
                        'nofollow'     => array('No Follow', "Don't follow links on this page"),
                        'noarchive'    => array('No Archive', 'No cached copy'),
                        'noimageindex' => array('No Image Index', 'Exclude images from image search'),
                        'nosnippet'    => array('No Snippet', 'No text snippet in results'),
                    );
                    foreach ($common as $key => $c): ?>
                      <label class="chip">
                        <input type="checkbox" name="common_robots_meta[<?php echo $a($key); ?>]" value="<?php echo $a($key); ?>"<?php echo isset($cr[$key]) ? self::checked_attr($cr[$key] === $key) : ''; ?>>
                        <span class="box">&#10003;</span><div><?php echo esc_html($c[0]); ?><small><?php echo esc_html($c[1]); ?></small></div>
                      </label>
                    <?php endforeach; ?>
                  </div>
                  <?php endif; ?>
                  <?php if ($has_arob): ?>
                  <div class="sec">Advanced limits <span class="sec-note">&mdash; tick a limit to apply it</span></div>
                  <div class="row">
                    <div class="field">
                      <label class="chip mss-advchip"><input type="checkbox" name="advanced_robots_meta[max-snippet][enable]" value="1"<?php checked('1', $ar_snip_e); ?>><span class="box">&#10003;</span><div>Max Snippet</div></label>
                      <input class="ctrl" type="number" name="advanced_robots_meta[max-snippet][length]" value="<?php echo $a($ar_snip_l); ?>" min="-1" placeholder="e.g. 160">
                      <div class="hint">Max snippet length in characters.</div></div>
                    <div class="field">
                      <label class="chip mss-advchip"><input type="checkbox" name="advanced_robots_meta[max-video-preview][enable]" value="1"<?php checked('1', $ar_vid_e); ?>><span class="box">&#10003;</span><div>Max Video Preview</div></label>
                      <input class="ctrl" type="number" name="advanced_robots_meta[max-video-preview][length]" value="<?php echo $a($ar_vid_l); ?>" min="-1" placeholder="e.g. -1">
                      <div class="hint">Max preview duration in seconds.</div></div>
                  </div>
                  <div class="field">
                    <label class="chip mss-advchip"><input type="checkbox" name="advanced_robots_meta[max-image-preview][enable]" value="1"<?php checked('1', $ar_img_e); ?>><span class="box">&#10003;</span><div>Max Image Preview</div></label>
                    <select class="ctrl" name="advanced_robots_meta[max-image-preview][length]">
                      <option value="large"<?php selected($ar_img_l, 'large'); ?>>Large</option>
                      <option value="standard"<?php selected($ar_img_l, 'standard'); ?>>Standard</option>
                      <option value="none"<?php selected($ar_img_l, 'none'); ?>>None</option>
                    </select></div>
                  <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($has_canon): ?>
                <div class="<?php echo $pcls(); ?>" data-p="canonical">
                  <h2 class="p-title">&#128279; Canonical URL</h2>
                  <p class="p-sub">Point search engines to the preferred version of this content.</p>
                  <div class="field">
                    <div class="lbl"><span>Canonical URL</span></div>
                    <input class="ctrl" type="text" name="post_canonical_url_meta" value="<?php echo $a($canon); ?>" placeholder="<?php echo $a($pretty); ?>">
                    <div class="hint">Leave blank to use this post's own URL.</div>
                  </div>
                </div>
                <?php endif; ?>

                <?php if ($has_redir): ?>
                <div class="<?php echo $pcls(); ?>" data-p="redirect">
                  <h2 class="p-title">&#8618;&#65039; Redirection</h2>
                  <p class="p-sub">Send visitors of this post somewhere else.</p>
                  <div class="switch-card">
                    <label class="switch"><input type="checkbox" name="post_redirect_meta[enable]" value="true"<?php echo self::checked_attr($rd_en); ?>></label>
                    <div class="m"><b>Enable redirection</b><span>Send visitors of this post to another URL</span></div>
                  </div>
                  <div class="row">
                    <div class="field"><div class="lbl"><span>Type</span></div>
                      <select class="ctrl" name="post_redirect_meta[type]">
                        <option value="301"<?php selected($rd_type, '301'); ?>>301 Permanent Move</option>
                        <option value="302"<?php selected($rd_type, '302'); ?>>302 Temporary Move</option>
                        <option value="307"<?php selected($rd_type, '307'); ?>>307 Temporary Redirect</option>
                        <option value="410"<?php selected($rd_type, '410'); ?>>410 Content Deleted</option>
                        <option value="451"<?php selected($rd_type, '451'); ?>>451 Content Unavailable</option>
                      </select></div>
                    <div class="field"><div class="lbl"><span>Destination URL</span></div>
                      <input class="ctrl" type="text" name="post_redirect_meta[url]" value="<?php echo $a($rd_url); ?>" placeholder="https://example.com/new-url/"></div>
                  </div>
                </div>
                <?php endif; ?>

                <?php if ($has_social): ?>
                <div class="<?php echo $pcls(); ?>" data-p="social">
                  <h2 class="p-title">&#128226; Social &amp; Open Graph</h2>
                  <p class="p-sub">How this post looks when shared on social platforms.</p>
                  <div class="switch-card">
                    <label class="switch"><input type="checkbox" name="_metasync_og_enabled" value="1"<?php echo self::checked_attr($og_en); ?>></label>
                    <div class="m"><b>Enable Open Graph &amp; Social Media Tags</b><span>Adds Open Graph + Twitter Card tags for better sharing</span></div>
                  </div>

                  <div class="subhead"><span class="sq"></span> Open Graph</div>
                  <div class="field"><div class="lbl"><span>Title (og:title)</span></div><input class="ctrl" name="_metasync_og_title" maxlength="60" value="<?php echo $a($og_title); ?>" placeholder="<?php echo $a(get_the_title($id)); ?>" oninput="mssSocial()"></div>
                  <div class="field"><div class="lbl"><span>Description (og:description)</span></div><textarea class="ctrl" name="_metasync_og_description" maxlength="155" placeholder="Post excerpt&hellip;" oninput="mssSocial()"><?php echo esc_textarea($og_desc); ?></textarea></div>
                  <div class="field"><div class="lbl"><span>Image (og:image)</span></div>
                    <div class="mss-imgrow"><input class="ctrl ogimg" type="url" name="_metasync_og_image" value="<?php echo $a($og_image); ?>" placeholder="https://example.com/image.jpg" oninput="mssSocial()"><button class="btn ghost sm mss-pick" data-target=".ogimg" type="button">Select</button></div>
                    <div class="hint">Recommended 1200&times;630px.</div></div>
                  <div class="row">
                    <div class="field"><div class="lbl"><span>Type (og:type)</span></div>
                      <select class="ctrl" name="_metasync_og_type">
                        <?php foreach (array('article', 'website', 'blog', 'product', 'video', 'music') as $t): ?>
                          <option value="<?php echo $a($t); ?>"<?php selected($og_type, $t); ?>><?php echo esc_html(ucfirst($t)); ?></option>
                        <?php endforeach; ?>
                      </select></div>
                    <div class="field"><div class="lbl"><span>URL (og:url)</span></div><input class="ctrl" type="url" name="_metasync_og_url" value="<?php echo $a($og_url); ?>" placeholder="<?php echo $a($pretty); ?>"<?php echo $post->post_status === 'auto-draft' ? ' disabled' : ''; ?>></div>
                  </div>

                  <div class="subhead"><span class="sq"></span> Twitter Card</div>
                  <div class="field"><div class="lbl"><span>Card Type</span></div>
                    <select class="ctrl mssTw" name="_metasync_twitter_card" onchange="mssTwT(this)">
                      <option value="summary"<?php selected($tw_card, 'summary'); ?>>Summary</option>
                      <option value="summary_large_image"<?php selected($tw_card, 'summary_large_image'); ?>>Summary Large Image</option>
                      <option value="app"<?php selected($tw_card, 'app'); ?>>App</option>
                      <option value="player"<?php selected($tw_card, 'player'); ?>>Player</option>
                    </select></div>
                  <div class="row">
                    <div class="field"><div class="lbl"><span>Twitter Site</span></div><input class="ctrl" name="_metasync_twitter_site" value="<?php echo $a($tw_site); ?>" placeholder="@yoursite"></div>
                    <div class="field"><div class="lbl"><span>Twitter Title</span></div><input class="ctrl" name="_metasync_twitter_title" maxlength="70" value="<?php echo $a($tw_title); ?>" placeholder="Falls back to OG title" oninput="mssSocial()"></div>
                  </div>
                  <div class="field"><div class="lbl"><span>Twitter Description</span></div><textarea class="ctrl" name="_metasync_twitter_description" maxlength="200" placeholder="Falls back to OG description" oninput="mssSocial()"><?php echo esc_textarea($tw_desc); ?></textarea></div>
                  <div class="row">
                    <div class="field"><div class="lbl"><span>Twitter Image</span></div>
                      <div class="mss-imgrow"><input class="ctrl twimg" type="url" name="_metasync_twitter_image" value="<?php echo $a($tw_image); ?>" placeholder="Falls back to OG image" oninput="mssSocial()"><button class="btn ghost sm mss-pick" data-target=".twimg" type="button">Select</button></div></div>
                    <div class="field"><div class="lbl"><span>Image Alt</span></div><input class="ctrl" name="_metasync_twitter_image_alt" maxlength="420" value="<?php echo $a($tw_alt); ?>" placeholder="Accessibility description"></div>
                  </div>

                  <div class="mssTwApp" style="display:<?php echo $tw_card === 'app' ? 'block' : 'none'; ?>">
                    <div class="subhead"><span class="sq"></span> App Card</div>
                    <div class="row">
                      <div class="field"><div class="lbl"><span>iPhone App ID</span></div><input class="ctrl" name="_metasync_twitter_app_id_iphone" value="<?php echo $a($tw_app_iphone); ?>" placeholder="307234931"<?php echo $tw_app_disabled; ?>></div>
                      <div class="field"><div class="lbl"><span>iPad App ID</span></div><input class="ctrl" name="_metasync_twitter_app_id_ipad" value="<?php echo $a($tw_app_ipad); ?>" placeholder="307234931"<?php echo $tw_app_disabled; ?>></div>
                    </div>
                    <div class="field"><div class="lbl"><span>Google Play App ID</span></div><input class="ctrl" name="_metasync_twitter_app_id_googleplay" value="<?php echo $a($tw_app_gp); ?>" placeholder="com.android.app"<?php echo $tw_app_disabled; ?>></div>
                    <div class="row">
                      <div class="field"><div class="lbl"><span>iPhone Custom URL</span></div><input class="ctrl" type="url" name="_metasync_twitter_app_url_iphone" value="<?php echo $a($tw_app_url_iphone); ?>" placeholder="myapp://"<?php echo $tw_app_disabled; ?>></div>
                      <div class="field"><div class="lbl"><span>iPad Custom URL</span></div><input class="ctrl" type="url" name="_metasync_twitter_app_url_ipad" value="<?php echo $a($tw_app_url_ipad); ?>" placeholder="myapp://"<?php echo $tw_app_disabled; ?>></div>
                    </div>
                    <div class="row">
                      <div class="field"><div class="lbl"><span>Google Play Custom URL</span></div><input class="ctrl" type="url" name="_metasync_twitter_app_url_googleplay" value="<?php echo $a($tw_app_url_gp); ?>" placeholder="myapp://"<?php echo $tw_app_disabled; ?>></div>
                      <div class="field"><div class="lbl"><span>App Store Country</span></div>
                        <select class="ctrl" name="_metasync_twitter_app_country"<?php echo $tw_app_disabled; ?>>
                          <option value=""<?php selected($tw_app_country, ''); ?>>US (Default)</option>
                          <?php foreach (array('GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia', 'DE' => 'Germany', 'FR' => 'France', 'JP' => 'Japan', 'IN' => 'India', 'BR' => 'Brazil', 'MX' => 'Mexico') as $code => $country): ?>
                            <option value="<?php echo $a($code); ?>"<?php selected($tw_app_country, $code); ?>><?php echo esc_html($country); ?></option>
                          <?php endforeach; ?>
                        </select>
                        <div class="hint">Required if your app is not available in the US App Store.</div>
                      </div>
                    </div>
                  </div>
                  <div class="mssTwPlayer" style="display:<?php echo $tw_card === 'player' ? 'block' : 'none'; ?>">
                    <div class="subhead"><span class="sq"></span> Player Card</div>
                    <div class="field"><div class="lbl"><span>Player URL</span></div><input class="ctrl" type="url" name="_metasync_twitter_player" value="<?php echo $a($tw_player); ?>" placeholder="https://example.com/player"<?php echo $tw_player_disabled; ?>></div>
                    <div class="row">
                      <div class="field"><div class="lbl"><span>Width (px)</span></div><input class="ctrl short" type="number" name="_metasync_twitter_player_width" min="1" value="<?php echo $a($tw_player_w); ?>" placeholder="1280"<?php echo $tw_player_disabled; ?>></div>
                      <div class="field"><div class="lbl"><span>Height (px)</span></div><input class="ctrl short" type="number" name="_metasync_twitter_player_height" min="1" value="<?php echo $a($tw_player_h); ?>" placeholder="720"<?php echo $tw_player_disabled; ?>></div>
                    </div>
                  </div>

                  <div class="subhead"><span class="sq"></span> Social preview</div>
                  <div class="mss-sptabs">
                    <button type="button" class="on" onclick="mssSpTab(this,'facebook')">Facebook</button>
                    <button type="button" onclick="mssSpTab(this,'twitter')">Twitter / X</button>
                    <button type="button" onclick="mssSpTab(this,'linkedin')">LinkedIn</button>
                  </div>
                  <div class="spwrap">
                    <div class="spcard fb on" data-plat="facebook">
                      <div class="sp-head"><span class="sp-av"><?php echo esc_html($avatar); ?></span><div class="sp-meta"><b><?php echo esc_html($site); ?></b><span>2 hours ago &middot; &#127760;</span></div></div>
                      <div class="sp-img"><span class="sp-noimg">No image selected</span></div>
                      <div class="sp-body">
                        <div class="sp-url"><?php echo esc_html($host); ?></div>
                        <div class="sp-title spT"></div>
                        <div class="sp-desc spD"></div>
                      </div>
                    </div>
                    <div class="spcard tw" data-plat="twitter">
                      <div class="sp-head"><span class="sp-av"><?php echo esc_html($avatar); ?></span><div class="sp-meta"><b><?php echo esc_html($site); ?></b><span>@<?php echo esc_html($handle); ?> &middot; now</span></div></div>
                      <div class="tw-card">
                        <div class="sp-img"><span class="sp-noimg">No image selected</span></div>
                        <div class="sp-body">
                          <div class="sp-title spT"></div>
                          <div class="sp-desc spD"></div>
                          <div class="sp-url"><?php echo esc_html($host_lc); ?></div>
                        </div>
                      </div>
                    </div>
                    <div class="spcard li" data-plat="linkedin">
                      <div class="sp-head"><span class="sp-av"><?php echo esc_html($avatar); ?></span><div class="sp-meta"><b><?php echo esc_html($site); ?></b><span>Promoted</span></div></div>
                      <div class="sp-img"><span class="sp-noimg">No image selected</span></div>
                      <div class="sp-body">
                        <div class="sp-title spT"></div>
                        <div class="sp-url"><?php echo esc_html($host_lc); ?> &middot; 1 min read</div>
                      </div>
                    </div>
                  </div>
                </div>
                <?php endif; ?>

                <?php if ($has_schema): ?>
                <div class="<?php echo $pcls(); ?>" data-p="schema">
                  <h2 class="p-title">&#128208; Schema Markup</h2>
                  <div class="mss-native">
                    <?php
                    if (!empty($this->schema_box['callback'])) {
                        call_user_func($this->schema_box['callback'], $post, $this->schema_box);
                    } else {
                        echo '<p class="p-sub">Schema builder unavailable on this screen.</p>';
                    }
                    ?>
                  </div>
                </div>
                <?php endif; ?>

                <?php if ($has_video): ?>
                <div class="<?php echo $pcls(); ?>" data-p="video">
                  <h2 class="p-title">&#127916; Video Sitemap</h2>
                  <p class="p-sub">Override auto-detected video data. Leave blank to use auto-detection.</p>
                  <div class="field"><div class="lbl"><span>Video URL</span></div><input class="ctrl" type="url" name="metasync_video_url" value="<?php echo $a($v_url); ?>" placeholder="https://www.youtube.com/watch?v=..."></div>
                  <div class="field"><div class="lbl"><span>Thumbnail URL</span></div><input class="ctrl" type="url" name="metasync_video_thumbnail" value="<?php echo $a($v_thumb); ?>" placeholder="https://img.youtube.com/vi/.../hqdefault.jpg"></div>
                  <div class="field"><div class="lbl"><span>Video Title</span></div><input class="ctrl" name="metasync_video_title" value="<?php echo $a($v_title); ?>" placeholder="Defaults to post title"></div>
                  <div class="field"><div class="lbl"><span>Video Description</span></div><textarea class="ctrl" name="metasync_video_description" placeholder="Defaults to post excerpt"><?php echo esc_textarea($v_desc); ?></textarea></div>
                  <div class="field"><div class="lbl"><span>Duration (seconds)</span></div><input class="ctrl short" type="number" name="metasync_video_duration" min="0" step="1" value="<?php echo $a($v_dur); ?>" placeholder="300"></div>
                </div>
                <?php endif; ?>

              </div>
            </div>

            <div class="mb-foot">
              <span class="mss-foot-hint">Changes are saved when you update the post.</span>
            </div>

            <?php if ($managed): ?>
            <div class="mss-lps-overlay">
              <div class="mss-lps-card">
                <div class="mss-lps-icon">&#128274;</div>
                <h3>SEO managed by WebStudio</h3>
                <p>This page's content is served as pre-built HTML before WordPress renders its <code>&lt;head&gt;</code>, so these SEO Suite settings won't apply. SEO for this page is managed by <b>WebStudio</b> (or OTTO, if enabled) to keep everything in sync.</p>
                <div class="mss-lps-hint">Editing is disabled here to avoid conflicting values.</div>
              </div>
            </div>
            <?php endif; ?>
            </div>
          </div>
        </div>

        <script>
        (function(){
          var root=document.getElementById('mss-root'); if(!root) return;
          function byName(n){return root.querySelector('[name="'+n+'"]');}
          function mssFld(n){var e=byName(n);return e?e.value.trim():'';}
          window.mssKey=function(e,el){ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); el.click(); } };
          window.mssGo=function(el,p){
            el.parentElement.querySelectorAll('.nav').forEach(function(n){n.classList.remove('active')});
            el.classList.add('active');
            root.querySelectorAll('.panel').forEach(function(pn){pn.classList.remove('active')});
            var t=root.querySelector('.panel[data-p="'+p+'"]'); if(t)t.classList.add('active');
          };
          window.mssRc=function(){
            var ti=root.querySelector('.seoT'), de=root.querySelector('.seoD'); if(!ti) return;
            root.querySelector('.tC').textContent=ti.value.length;
            root.querySelector('.dC').textContent=de.value.length;
            root.querySelector('.gT').textContent=ti.value||ti.getAttribute('placeholder')||'Untitled';
            root.querySelector('.gD').textContent=de.value||de.getAttribute('placeholder')||'No description set.';
          };
          window.mssTwT=function(sel){
            var isApp=sel.value==='app', isPlayer=sel.value==='player';
            var appSection=root.querySelector('.mssTwApp'), playerSection=root.querySelector('.mssTwPlayer');
            if(appSection){
              appSection.style.display=isApp?'block':'none';
              appSection.querySelectorAll('input,select,textarea').forEach(function(field){field.disabled=!isApp;});
            }
            if(playerSection){
              playerSection.style.display=isPlayer?'block':'none';
              playerSection.querySelectorAll('input,select,textarea').forEach(function(field){field.disabled=!isPlayer;});
            }
          };
          window.mssTheme=function(btn){
            var dark=root.getAttribute('data-theme')==='dark';
            root.setAttribute('data-theme',dark?'light':'dark');
            btn.querySelector('.ti').textContent=dark?'\u{1F319}':'☀️';
            btn.querySelector('.tl').textContent=dark?'Dark':'Light';
          };
          window.mssSpTab=function(btn,p){
            btn.parentElement.querySelectorAll('button').forEach(function(b){b.classList.remove('on')});
            btn.classList.add('on');
            root.querySelectorAll('.spcard').forEach(function(c){c.classList.toggle('on', c.getAttribute('data-plat')===p)});
          };
          window.mssSocial=function(){
            var seoT=root.querySelector('.seoT'), seoD=root.querySelector('.seoD');
            var ogTitleEl=byName('_metasync_og_title');
            var baseTitle=mssFld('_metasync_og_title') || (ogTitleEl&&ogTitleEl.getAttribute('placeholder')) || (seoT&&seoT.value.trim()) || 'Untitled';
            var baseDesc =mssFld('_metasync_og_description') || (seoD&&seoD.value.trim()) || 'No description set.';
            var baseImg  =mssFld('_metasync_og_image');
            var twTitle=mssFld('_metasync_twitter_title');
            var twDesc =mssFld('_metasync_twitter_description');
            var twImg  =mssFld('_metasync_twitter_image');
            root.querySelectorAll('.spcard').forEach(function(c){
              var isTw=c.getAttribute('data-plat')==='twitter';
              var T=isTw?(twTitle||baseTitle):baseTitle;
              var D=isTw?(twDesc||baseDesc):baseDesc;
              var I=isTw?(twImg||baseImg):baseImg;
              var et=c.querySelector('.spT'); if(et)et.textContent=T;
              var ed=c.querySelector('.spD'); if(ed)ed.textContent=D;
              var eb=c.querySelector('.sp-img'); if(eb){ if(I){eb.style.backgroundImage='url("'+I.replace(/"/g,'')+'")';eb.classList.add('has');}else{eb.style.backgroundImage='';eb.classList.remove('has');} }
            });
          };
          root.querySelectorAll('.mss-pick').forEach(function(btn){
            btn.addEventListener('click',function(e){e.preventDefault();if(typeof wp==='undefined'||!wp.media)return;
              var target=root.querySelector(btn.getAttribute('data-target'));
              var frame=wp.media({title:'Select image',button:{text:'Use image'},multiple:false});
              frame.on('select',function(){var img=frame.state().get('selection').first().toJSON();if(target){target.value=img.url;mssSocial();}});
              frame.open();});
          });
          ['_metasync_og_title','_metasync_og_description','_metasync_og_image','_metasync_twitter_title','_metasync_twitter_description','_metasync_twitter_image'].forEach(function(n){
            var e=byName(n); if(e) e.addEventListener('input',mssSocial);
          });
          var twitterCard=byName('_metasync_twitter_card'); if(twitterCard)mssTwT(twitterCard); mssRc(); mssSocial();
        })();
        </script>
        <?php
    }

    private function styles()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        ?>
<style>
  #mss-root[data-theme="light"]{--card:#fff;--card-2:#f1f3f5;--rail:#fbfbfd;--text:#1a1f26;--muted:#6b7280;--faint:#9ca3af;--border:#e5e7eb;--border-soft:#eef0f3;--accent:#667eea;--purple:#764ba2;--grad:linear-gradient(135deg,#667eea 0%,#764ba2 100%);--grad-hover:linear-gradient(135deg,#5568d3 0%,#653f8c 100%);--grad-soft:linear-gradient(135deg,rgba(102,126,234,.10) 0%,rgba(118,75,162,.06) 100%);--success:#10b981;--warning:#f59e0b;--error:#ef4444;--field-bg:#fff;--shadow:0 4px 24px rgba(17,24,39,.08);--shadow-sm:0 1px 3px rgba(17,24,39,.06)}
  #mss-root[data-theme="dark"]{--card:#1a1f26;--card-2:#222831;--rail:#171c23;--text:#fff;--muted:#9ca3af;--faint:#8b93a3;--border:#374151;--border-soft:#283242;--accent:#8ea2f0;--purple:#764ba2;--grad:linear-gradient(135deg,#667eea 0%,#764ba2 100%);--grad-hover:linear-gradient(135deg,#5568d3 0%,#653f8c 100%);--grad-soft:linear-gradient(135deg,rgba(102,126,234,.16) 0%,rgba(118,75,162,.10) 100%);--success:#10b981;--warning:#f59e0b;--error:#ef4444;--field-bg:#11161d;--shadow:0 8px 30px rgba(0,0,0,.45);--shadow-sm:0 1px 3px rgba(0,0,0,.4)}
  #mss-root,#mss-root *{box-sizing:border-box}
  #mss-root{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Inter,Oxygen,Ubuntu,sans-serif;color:var(--text);font-size:13.5px;line-height:1.55;margin:-6px -12px -12px;background:var(--card);overflow:hidden}
  #mss-root .mb-head{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border-soft)}
  #mss-root .mb-head .badge{width:30px;height:30px;border-radius:9px;background:var(--grad);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:15px}
  #mss-root .mb-head .t b{font-size:14.5px;color:var(--text)}
  #mss-root .mb-head .t span{display:block;color:var(--muted);font-size:11.5px}
  #mss-root .mss-actions{margin-left:auto;display:flex;gap:8px}
  #mss-root .theme-toggle{display:flex;align-items:center;gap:7px;background:var(--grad);border:0;color:#fff;border-radius:20px;padding:6px 13px;cursor:pointer;font-size:12px;font-weight:600}
  #mss-root .mb-body{display:grid;grid-template-columns:212px 1fr;min-height:430px}
  @media (max-width:900px){#mss-root .mb-body{grid-template-columns:1fr}}
  #mss-root .rail{background:var(--rail);border-right:1px solid var(--border-soft);padding:12px 10px;display:flex;flex-direction:column;gap:3px}
  @media (max-width:900px){#mss-root .rail{flex-direction:row;overflow-x:auto;border-right:0;border-bottom:1px solid var(--border-soft)}}
  #mss-root .nav{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:10px;cursor:pointer;color:var(--muted);font-weight:600;font-size:13px;border:1px solid transparent;white-space:nowrap;transition:.15s;position:relative}
  #mss-root .nav:hover{background:var(--card-2);color:var(--text)}
  #mss-root .nav.active{background:var(--grad-soft);color:var(--accent);border-color:rgba(102,126,234,.35)}
  #mss-root .nav.active::before{content:"";position:absolute;left:0;top:8px;bottom:8px;width:3px;border-radius:3px;background:var(--grad)}
  #mss-root .nav .ic{width:20px;height:20px;flex:none;display:flex;align-items:center;justify-content:center;font-size:14px}
  #mss-root .panel-wrap{padding:24px 26px;position:relative}
  #mss-root .panel{display:none;animation:mssfade .22s ease}
  #mss-root .panel.active{display:block}
  @keyframes mssfade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:none}}
  #mss-root .p-title{font-size:16px;font-weight:700;margin:0 0 3px;display:flex;align-items:center;gap:9px;color:var(--text)}
  #mss-root .p-sub{color:var(--muted);font-size:12.5px;margin:0 0 20px}
  #mss-root .sec{font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--faint);margin:24px 0 13px;display:flex;align-items:center;gap:8px}
  #mss-root .sec::after{content:"";flex:1;height:1px;background:var(--border-soft)}
  #mss-root .sec-note{text-transform:none;letter-spacing:0;font-weight:500;color:var(--faint)}
  #mss-root .subhead{font-size:14px;font-weight:700;color:var(--text);margin:24px 0 14px;display:flex;align-items:center;gap:9px;padding-bottom:9px;border-bottom:2px solid var(--border-soft)}
  #mss-root .subhead .sq{width:9px;height:16px;border-radius:3px;background:var(--grad);flex:none}
  #mss-root .field{margin:0 0 17px}
  #mss-root .field:last-child{margin-bottom:0}
  #mss-root .field .lbl{display:flex;justify-content:space-between;align-items:baseline;font-weight:600;margin-bottom:6px;font-size:12.5px;color:var(--text)}
  #mss-root .field .lbl .cc{color:var(--faint);font-weight:600;font-size:11px}
  #mss-root .ctrl{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-size:13px;background:var(--field-bg);color:var(--text);transition:.15s;font-family:inherit;line-height:1.4;min-height:0;box-shadow:none}
  #mss-root .ctrl:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(102,126,234,.16)}
  #mss-root textarea.ctrl{min-height:78px;resize:vertical}
  #mss-root input.short{max-width:140px}
  #mss-root .mss-imgrow{display:flex;gap:9px}
  #mss-root .hint{color:var(--faint);font-size:11.5px;margin-top:5px;line-height:1.5}
  #mss-root .row{display:grid;grid-template-columns:1fr 1fr;gap:15px}
  @media (max-width:560px){#mss-root .row{grid-template-columns:1fr}}
  #mss-root .switch-card{display:flex;align-items:center;gap:13px;padding:13px 15px;background:var(--card-2);border:1px solid var(--border-soft);border-radius:11px;margin-bottom:18px}
  #mss-root .switch{width:42px;height:24px;border-radius:24px;background:var(--border);position:relative;flex:none;cursor:pointer;transition:.18s;display:inline-block}
  #mss-root .switch input{position:absolute;opacity:0;width:0;height:0}
  #mss-root .switch::after{content:"";position:absolute;width:18px;height:18px;border-radius:50%;background:#fff;top:3px;left:3px;transition:.18s;box-shadow:0 1px 2px rgba(0,0,0,.3)}
  #mss-root .switch:has(input:checked){background:var(--grad)}
  #mss-root .switch:has(input:checked)::after{left:21px}
  #mss-root .switch-card .m b{display:block;font-size:13px;color:var(--text)}
  #mss-root .switch-card .m span{color:var(--muted);font-size:11.5px}
  #mss-root .chips{display:flex;flex-wrap:wrap;gap:9px}
  #mss-root .chip{display:flex;align-items:center;gap:8px;padding:9px 13px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;font-size:12.5px;font-weight:600;background:var(--field-bg);transition:.15s;user-select:none;color:var(--text)}
  #mss-root .chip input{position:absolute;opacity:0;width:0;height:0}
  #mss-root .chip:hover{border-color:var(--accent)}
  #mss-root .chip:has(input:checked){background:var(--grad-soft);border-color:var(--accent);color:var(--accent)}
  #mss-root .chip .box{width:15px;height:15px;border-radius:4px;border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:10px;color:transparent;flex:none}
  #mss-root .chip:has(input:checked) .box{background:var(--accent);border-color:var(--accent);color:#fff}
  #mss-root .chip small{display:block;font-weight:500;color:var(--muted);font-size:10.5px;margin-top:2px;max-width:160px;line-height:1.35}
  #mss-root .chip:has(input:checked) small{color:var(--text)}
  #mss-root .mss-advchip{display:inline-flex;margin-bottom:7px}
  #mss-root .gprev{border:1px solid var(--border);border-radius:11px;padding:15px 17px;background:var(--field-bg)}
  #mss-root .gprev .u{color:var(--muted);font-size:12px}
  #mss-root .gprev .ti{color:#1a0dab;font-size:18px;margin:3px 0;font-weight:400}
  #mss-root[data-theme="dark"] .gprev .ti{color:#8ab4f8}
  #mss-root .gprev .de{color:var(--muted);font-size:12.5px}
  #mss-root .btn{background:var(--grad);color:#fff;border:0;padding:9px 17px;border-radius:9px;cursor:pointer;font-size:13px;font-weight:600;box-shadow:var(--shadow-sm);transition:.15s;height:auto;line-height:1.3}
  #mss-root .btn:hover{background:var(--grad-hover);color:#fff}
  #mss-root .btn.ghost{background:transparent;color:var(--accent);border:1.5px solid rgba(102,126,234,.45);box-shadow:none}
  #mss-root .btn.ghost:hover{background:var(--grad-soft)}
  #mss-root .btn.sm{padding:6px 12px;font-size:12px}
  #mss-root .mss-sptabs{display:flex;gap:2px;margin-bottom:13px;border-bottom:1px solid var(--border-soft)}
  #mss-root .mss-sptabs button{background:none;border:0;padding:8px 13px;font-size:12.5px;font-weight:600;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}
  #mss-root .mss-sptabs button.on{color:var(--accent);border-bottom-color:var(--accent)}
  #mss-root .spcard{display:none;border:1px solid var(--border);border-radius:11px;overflow:hidden;background:var(--field-bg);max-width:524px}
  #mss-root .spcard.on{display:block}
  #mss-root .sp-head{display:flex;align-items:center;gap:10px;padding:11px 13px}
  #mss-root .sp-av{width:34px;height:34px;border-radius:50%;background:var(--grad);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;flex:none}
  #mss-root .sp-meta b{font-size:13px;color:var(--text);display:block;line-height:1.3}
  #mss-root .sp-meta span{font-size:11px;color:var(--faint)}
  #mss-root .sp-img{height:210px;background:var(--card-2) center/cover no-repeat;display:flex;align-items:center;justify-content:center}
  #mss-root .sp-img.has .sp-noimg{display:none}
  #mss-root .sp-noimg{color:var(--faint);font-size:12px}
  #mss-root .sp-body{padding:11px 14px;border-top:1px solid var(--border-soft);background:var(--card-2)}
  #mss-root[data-theme="dark"] .sp-body{background:var(--card)}
  #mss-root .sp-url{font-size:10.5px;text-transform:uppercase;color:var(--faint);letter-spacing:.03em}
  #mss-root .sp-title{font-size:15px;font-weight:700;color:var(--text);margin:3px 0}
  #mss-root .sp-desc{font-size:12.5px;color:var(--muted)}
  #mss-root .spcard.tw .tw-card{margin:0 13px 14px;border:1px solid var(--border);border-radius:15px;overflow:hidden}
  #mss-root .spcard.tw .sp-body{border-top:0;background:var(--field-bg);display:flex;flex-direction:column}
  #mss-root .spcard.tw .sp-title{font-size:14px;margin:0 0 3px;order:1}
  #mss-root .spcard.tw .sp-desc{order:2}
  #mss-root .spcard.tw .sp-url{order:0;text-transform:none;margin-bottom:4px}
  #mss-root .spcard.li .sp-title{font-size:14.5px}
  #mss-root .mb-foot{display:flex;align-items:center;gap:14px;padding:13px 26px;border-top:1px solid var(--border-soft);background:var(--rail)}
  #mss-root .mss-foot-hint{font-size:12px;color:var(--muted)}
  #mss-root .mss-lockable{position:relative}
  #mss-root .mss-lps-overlay{position:absolute;inset:0;display:none;align-items:center;justify-content:center;padding:26px;z-index:6;background:rgba(248,249,250,.62);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
  #mss-root[data-theme="dark"] .mss-lps-overlay{background:rgba(15,20,25,.66)}
  #mss-root.lps .mss-lps-overlay{display:flex}
  #mss-root .mss-lps-card{max-width:440px;text-align:center;background:var(--card);border:1px solid var(--border);border-radius:18px;box-shadow:var(--shadow);padding:32px 30px}
  #mss-root .mss-lps-icon{width:58px;height:58px;margin:0 auto 16px;border-radius:16px;background:var(--grad);display:flex;align-items:center;justify-content:center;font-size:27px;box-shadow:var(--shadow-sm)}
  #mss-root .mss-lps-card h3{margin:0 0 9px;font-size:18px;color:var(--text)}
  #mss-root .mss-lps-card p{margin:0 0 20px;color:var(--muted);font-size:13px;line-height:1.65}
  #mss-root .mss-lps-card p b{color:var(--text)}
  #mss-root .mss-lps-card p code{background:var(--card-2);padding:1px 5px;border-radius:4px;font-size:11px}
  #mss-root .mss-lps-hint{margin-top:15px;font-size:11.5px;color:var(--faint)}
  #mss-root .mss-ro{width:100%;padding:10px 12px;border:1.5px dashed var(--border);border-radius:9px;background:var(--card-2);color:var(--text);font-size:13px;font-weight:600;display:flex;align-items:center;gap:8px}
  #mss-root .ro-badge{font-weight:600;font-size:10.5px;color:var(--accent);background:var(--grad-soft);border:1px solid rgba(102,126,234,.30);border-radius:20px;padding:2px 9px}
  #mss-root .mss-native input[type=text],#mss-root .mss-native input[type=url],#mss-root .mss-native input[type=number],#mss-root .mss-native textarea,#mss-root .mss-native select{border-radius:8px !important}
  /* The builder's own `.schema-field input {width:100%}` also hits checkboxes and radios,
     stretching them into a full-width block. Core styles these with
     -webkit-appearance:none, so they have NO intrinsic size and depend entirely on an
     explicit width/height — restate core's 1rem box rather than using width:auto, which
     would collapse the control to nothing while unchecked (the checked state only stays
     visible because core paints a ::before glyph over it). */
  #mss-root .mss-native input[type=checkbox],
  #mss-root .mss-native input[type=radio]{width:1rem !important;min-width:1rem !important;max-width:1rem !important;height:1rem !important;flex:0 0 auto;padding:0 !important;margin:0 7px 0 0;vertical-align:middle}
  #mss-root .mss-native input[type=radio]{border-radius:50% !important}
  #mss-root .mss-native .schema-field label:has(input[type=checkbox]),
  #mss-root .mss-native .schema-field label:has(input[type=radio]){display:flex;align-items:center;gap:2px}
  /* Dark mode: theme the embedded native Schema builder. Every surface the builder paints
     light — whether from its stylesheet or an inline style — is listed here. Text is only
     forced light on surfaces this block also darkens, so nothing ends up light-on-light. */
  #mss-root[data-theme="dark"] .mss-native{color:var(--text)}
  #mss-root[data-theme="dark"] .mss-native h1,#mss-root[data-theme="dark"] .mss-native h2,#mss-root[data-theme="dark"] .mss-native h3,#mss-root[data-theme="dark"] .mss-native h4,#mss-root[data-theme="dark"] .mss-native h5,#mss-root[data-theme="dark"] .mss-native label,#mss-root[data-theme="dark"] .mss-native strong,#mss-root[data-theme="dark"] .mss-native p{color:var(--text) !important}
  #mss-root[data-theme="dark"] .mss-native .description,#mss-root[data-theme="dark"] .mss-native small{color:var(--muted) !important}
  /* Light surfaces → dark. Class/ID list mirrors schema-markup-admin.css; the [style*=]
     matchers catch the builder's inline backgrounds (#f5f5f5, #f9f9f9, #fff, white). */
  #mss-root[data-theme="dark"] .mss-native .schema-types-container,
  #mss-root[data-theme="dark"] .mss-native .schema-types-header,
  #mss-root[data-theme="dark"] .mss-native .schema-type-item,
  #mss-root[data-theme="dark"] .mss-native .schema-type-header,
  #mss-root[data-theme="dark"] .mss-native .schema-type-content,
  #mss-root[data-theme="dark"] .mss-native .schema-fields-container,
  #mss-root[data-theme="dark"] .mss-native .schema-field,
  #mss-root[data-theme="dark"] .mss-native .schema-field-group,
  #mss-root[data-theme="dark"] .mss-native .schema-override-header,
  #mss-root[data-theme="dark"] .mss-native .schema-override-content,
  #mss-root[data-theme="dark"] .mss-native .no-schema-types,
  #mss-root[data-theme="dark"] .mss-native .faq-item,
  #mss-root[data-theme="dark"] .mss-native .instruction-item,
  #mss-root[data-theme="dark"] .mss-native .logo-upload-container,
  #mss-root[data-theme="dark"] .mss-native .logo-preview,
  #mss-root[data-theme="dark"] .mss-native #schema-preview-output,
  #mss-root[data-theme="dark"] .mss-native #schema-type-modal > div,
  #mss-root[data-theme="dark"] .mss-native [style*="background: #fff"],
  #mss-root[data-theme="dark"] .mss-native [style*="background:#fff"],
  #mss-root[data-theme="dark"] .mss-native [style*="background: white"],
  #mss-root[data-theme="dark"] .mss-native [style*="background:white"],
  #mss-root[data-theme="dark"] .mss-native [style*="background: #f9f9f9"],
  #mss-root[data-theme="dark"] .mss-native [style*="background:#f9f9f9"],
  #mss-root[data-theme="dark"] .mss-native [style*="background: #f5f5f5"],
  #mss-root[data-theme="dark"] .mss-native [style*="background:#f5f5f5"],
  #mss-root[data-theme="dark"] .mss-native [style*="#ffffff"]{background-color:var(--card-2) !important;border-color:var(--border) !important}
  /* Notices keep their semantic colour instead of being flattened to a grey surface. */
  #mss-root[data-theme="dark"] .mss-native .metasync-schema-validation-warning,
  #mss-root[data-theme="dark"] .mss-native [style*="background: #fff3cd"],
  #mss-root[data-theme="dark"] .mss-native [style*="background:#fff3cd"]{background-color:rgba(245,158,11,.14) !important;border-color:rgba(245,158,11,.45) !important}
  #mss-root[data-theme="dark"] .mss-native .metasync-schema-validation-warning,
  #mss-root[data-theme="dark"] .mss-native .metasync-schema-validation-warning *,
  #mss-root[data-theme="dark"] .mss-native [style*="#fff3cd"] *,
  #mss-root[data-theme="dark"] .mss-native [style*="color: #856404"],
  #mss-root[data-theme="dark"] .mss-native [style*="color:#856404"]{color:#fcd34d !important}
  #mss-root[data-theme="dark"] .mss-native .metasync-schema-requirements-info,
  #mss-root[data-theme="dark"] .mss-native [style*="background: #e7f3ff"],
  #mss-root[data-theme="dark"] .mss-native [style*="background:#e7f3ff"]{background-color:rgba(102,126,234,.14) !important;border-color:rgba(102,126,234,.45) !important}
  #mss-root[data-theme="dark"] .mss-native .metasync-schema-requirements-info,
  #mss-root[data-theme="dark"] .mss-native .metasync-schema-requirements-info *{color:#c7d2fe !important}
  /* Dark inline text on the surfaces darkened above → readable. */
  #mss-root[data-theme="dark"] .mss-native [style*="color: #333"],
  #mss-root[data-theme="dark"] .mss-native [style*="color:#333"],
  #mss-root[data-theme="dark"] .mss-native [style*="color: #000"],
  #mss-root[data-theme="dark"] .mss-native [style*="color:#000"],
  #mss-root[data-theme="dark"] .mss-native [style*="color: #666"],
  #mss-root[data-theme="dark"] .mss-native [style*="color:#666"]{color:var(--text) !important}
  #mss-root[data-theme="dark"] .mss-native input[type=text],#mss-root[data-theme="dark"] .mss-native input[type=url],#mss-root[data-theme="dark"] .mss-native input[type=number],#mss-root[data-theme="dark"] .mss-native input[type=email],#mss-root[data-theme="dark"] .mss-native textarea,#mss-root[data-theme="dark"] .mss-native select{background:var(--field-bg) !important;color:var(--text) !important;border-color:var(--border) !important}
  /* Checkboxes/radios: core paints them white-on-dark-border, which glares against this
     box. Unchecked gets the field surface with a visible border; checked gets the brand
     accent, which keeps enough contrast for core's white ::before tick. */
  #mss-root[data-theme="dark"] .mss-native input[type=checkbox],
  #mss-root[data-theme="dark"] .mss-native input[type=radio]{background:var(--field-bg) !important;border-color:#6b7280 !important}
  #mss-root[data-theme="dark"] .mss-native input[type=checkbox]:checked,
  #mss-root[data-theme="dark"] .mss-native input[type=radio]:checked{background:var(--accent) !important;border-color:var(--accent) !important}
  /* Core's .button is painted light by wp-admin; keep it legible on the dark surface.
     Destructive buttons (the builder paints them red) are left alone. */
  #mss-root[data-theme="dark"] .mss-native .button,
  #mss-root[data-theme="dark"] .mss-native .button-secondary{background:var(--card-2) !important;color:var(--accent) !important;border-color:rgba(102,126,234,.45) !important;text-shadow:none !important}
  #mss-root[data-theme="dark"] .mss-native .button:hover,
  #mss-root[data-theme="dark"] .mss-native .button-secondary:hover{background:var(--grad-soft) !important;color:var(--text) !important}
  #mss-root[data-theme="dark"] .mss-native .button-primary{background:var(--grad) !important;color:#fff !important;border-color:transparent !important}
  /* .remove-schema-type is a text-style .button (red text, no fill) — keep it red rather
     than letting the .button rule above recolour it. .remove-faq-item / .remove-item are
     solid-red fills with white text and are deliberately left untouched. */
  #mss-root[data-theme="dark"] .mss-native .remove-schema-type,
  #mss-root[data-theme="dark"] .mss-native .remove-schema-type .dashicons{color:#f87171 !important;border-color:rgba(248,113,113,.45) !important}
</style>
        <?php
    }
}

<?php

/**
 * Meta box template for Open Graph settings
 *
 * @package    MetaSync
 * @subpackage MetaSync/admin/partials
 * @since      1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

?>

<div class="metasync-opengraph-meta-box">

    <!-- Toggle Switch -->
    <div class="metasync-og-toggle-section">
        <label class="metasync-toggle-switch">
            <input type="checkbox"
                   name="_metasync_og_enabled"
                   value="1"
                   <?php checked($og_enabled, '1'); ?>
                   class="metasync-og-toggle">
            <span class="metasync-toggle-slider"></span>
            <span class="metasync-toggle-label"><?php esc_html_e('Enable Open Graph & Social Media Tags', 'metasync'); ?></span>
        </label>
        <p class="description">
            <?php esc_html_e('Enable this to add Open Graph and Twitter Card meta tags to this post/page for better social media sharing.', 'metasync'); ?>
        </p>
    </div>

    <div class="metasync-og-content" <?php echo ($og_enabled !== '1') ? 'style="display:none;"' : ''; ?>>
        
        <!-- Open Graph Section -->
        <div class="metasync-og-section">
            <h4><?php esc_html_e('Open Graph Tags', 'metasync'); ?></h4>
            
            <div class="metasync-og-field-group">
                <label for="metasync_og_title"><?php esc_html_e('Title (og:title)', 'metasync'); ?></label>
                <input type="text" 
                       id="metasync_og_title" 
                       name="_metasync_og_title" 
                       value="<?php echo esc_attr($og_title); ?>" 
                       class="widefat metasync-og-input"
                       maxlength="60"
                       placeholder="<?php echo esc_attr($post->post_title); ?>">
                <p class="description">
                    <?php esc_html_e('The title that will appear when shared on social media. Recommended: 60 characters or less.', 'metasync'); ?>
                    <span class="metasync-char-count" data-target="metasync_og_title">0/60</span>
                </p>
            </div>

            <div class="metasync-og-field-group">
                <label for="metasync_og_description"><?php esc_html_e('Description (og:description)', 'metasync'); ?></label>
                <textarea id="metasync_og_description" 
                          name="_metasync_og_description" 
                          class="widefat metasync-og-input"
                          rows="3"
                          maxlength="155"
                          placeholder="<?php echo esc_attr($this->get_post_excerpt($post)); ?>"><?php echo esc_textarea($og_description); ?></textarea>
                <p class="description">
                    <?php esc_html_e('The description that will appear when shared on social media. Recommended: 155 characters or less.', 'metasync'); ?>
                    <span class="metasync-char-count" data-target="metasync_og_description">0/155</span>
                </p>
            </div>

            <div class="metasync-og-field-group">
                <label for="metasync_og_image"><?php esc_html_e('Image (og:image)', 'metasync'); ?></label>
                <div class="metasync-image-field">
                    <input type="url" 
                           id="metasync_og_image" 
                           name="_metasync_og_image" 
                           value="<?php echo esc_attr($og_image); ?>" 
                           class="widefat metasync-og-input"
                           placeholder="<?php esc_html_e('https://example.com/image.jpg', 'metasync'); ?>">
                    <div class="metasync-image-controls">
                        <button type="button" class="button metasync-upload-image">
                            <?php esc_html_e('Select Image', 'metasync'); ?>
                        </button>
                        <button type="button" class="button metasync-remove-image" style="<?php echo empty($og_image) ? 'display:none;' : ''; ?>">
                            <?php esc_html_e('Remove', 'metasync'); ?>
                        </button>
                    </div>
                </div>
                <div class="metasync-image-preview" <?php echo empty($og_image) ? 'style="display:none;"' : ''; ?>>
                    <img src="<?php echo esc_url($og_image); ?>" alt="<?php esc_html_e('Preview', 'metasync'); ?>">
                </div>
                <p class="description">
                    <?php esc_html_e('The image that will appear when shared on social media. Recommended size: 1200x630 pixels.', 'metasync'); ?>
                </p>
            </div>

            <div class="metasync-og-field-row">
                <div class="metasync-og-field-group metasync-half-width">
                    <label for="metasync_og_type"><?php esc_html_e('Type (og:type)', 'metasync'); ?></label>
                    <select id="metasync_og_type" name="_metasync_og_type" class="widefat">
                        <option value="article" <?php selected($og_type, 'article'); ?>><?php esc_html_e('Article', 'metasync'); ?></option>
                        <option value="website" <?php selected($og_type, 'website'); ?>><?php esc_html_e('Website', 'metasync'); ?></option>
                        <option value="blog" <?php selected($og_type, 'blog'); ?>><?php esc_html_e('Blog', 'metasync'); ?></option>
                        <option value="product" <?php selected($og_type, 'product'); ?>><?php esc_html_e('Product', 'metasync'); ?></option>
                        <option value="video" <?php selected($og_type, 'video'); ?>><?php esc_html_e('Video', 'metasync'); ?></option>
                        <option value="music" <?php selected($og_type, 'music'); ?>><?php esc_html_e('Music', 'metasync'); ?></option>
                    </select>
                </div>

                <div class="metasync-og-field-group metasync-half-width">
                    <label for="metasync_og_url"><?php esc_html_e('URL (og:url)', 'metasync'); ?></label>
                    <input type="url" 
                           id="metasync_og_url" 
                           name="_metasync_og_url" 
                           value="<?php echo esc_attr($og_url); ?>" 
                           class="widefat metasync-og-input"
                           placeholder="<?php echo esc_attr($this->get_canonical_url($post)); ?>"
                           <?php echo ($post->post_status === 'auto-draft') ? 'disabled' : ''; ?>>
                    <?php if ($post->post_status === 'auto-draft'): ?>
                        <p class="description metasync-url-disabled-notice">
                            <span class="dashicons dashicons-info"></span>
                            <?php esc_html_e('This field will be automatically populated with the post permalink after you save the post for the first time.', 'metasync'); ?>
                        </p>
                    <?php else: ?>
                        <p class="description">
                            <?php esc_html_e('The canonical URL for this post. Leave empty to use the post permalink, or enter a custom URL.', 'metasync'); ?>
                            <button type="button" class="button button-small metasync-use-permalink" style="margin-left: 10px;">
                                <?php esc_html_e('Use Post Permalink', 'metasync'); ?>
                            </button>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Twitter Card Section -->
        <div class="metasync-twitter-section">
            <h4><?php esc_html_e('Twitter Card Tags', 'metasync'); ?></h4>
            
            <div class="metasync-og-field-group">
                <label for="metasync_twitter_card"><?php esc_html_e('Card Type', 'metasync'); ?></label>
                <select id="metasync_twitter_card" name="_metasync_twitter_card" class="widefat">
                    <option value="summary" <?php selected($twitter_card, 'summary'); ?>><?php esc_html_e('Summary', 'metasync'); ?></option>
                    <option value="summary_large_image" <?php selected($twitter_card, 'summary_large_image'); ?>><?php esc_html_e('Summary Large Image', 'metasync'); ?></option>
                    <option value="app" <?php selected($twitter_card, 'app'); ?>><?php esc_html_e('App', 'metasync'); ?></option>
                    <option value="player" <?php selected($twitter_card, 'player'); ?>><?php esc_html_e('Player', 'metasync'); ?></option>
                </select>
                <p class="description">
                    <?php esc_html_e('The type of Twitter Card to display. "Summary Large Image" is recommended for most content.', 'metasync'); ?>
                </p>
            </div>

            <div class="metasync-og-field-group">
                <label for="metasync_twitter_site"><?php esc_html_e('Twitter Site (@username)', 'metasync'); ?></label>
                <input type="text" 
                       id="metasync_twitter_site" 
                       name="_metasync_twitter_site" 
                       value="<?php echo esc_attr($twitter_site); ?>" 
                       class="widefat"
                       placeholder="@yoursite">
                <p class="description">
                    <?php esc_html_e('Your site\'s Twitter username (including @). This will be used for attribution.', 'metasync'); ?>
                </p>
            </div>

            <div class="metasync-og-field-group">
                <label for="metasync_twitter_title"><?php esc_html_e('Twitter Title', 'metasync'); ?></label>
                <input type="text" 
                       id="metasync_twitter_title" 
                       name="_metasync_twitter_title" 
                       value="<?php echo esc_attr($twitter_title); ?>" 
                       class="widefat metasync-og-input"
                       maxlength="70"
                       placeholder="<?php echo esc_attr($og_title); ?>">
                <p class="description">
                    <?php esc_html_e('Twitter-specific title. Leave empty to use the Open Graph title. Max: 70 characters.', 'metasync'); ?>
                    <span class="metasync-char-count" data-target="metasync_twitter_title">0/70</span>
                </p>
            </div>

            <div class="metasync-og-field-group">
                <label for="metasync_twitter_description"><?php esc_html_e('Twitter Description', 'metasync'); ?></label>
                <textarea id="metasync_twitter_description" 
                          name="_metasync_twitter_description" 
                          class="widefat metasync-og-input"
                          rows="2"
                          maxlength="200"
                          placeholder="<?php echo esc_attr($og_description); ?>"><?php echo esc_textarea($twitter_description); ?></textarea>
                <p class="description">
                    <?php esc_html_e('Twitter-specific description. Leave empty to use the Open Graph description. Max: 200 characters.', 'metasync'); ?>
                    <span class="metasync-char-count" data-target="metasync_twitter_description">0/200</span>
                </p>
            </div>

            <div class="metasync-og-field-group">
                <label for="metasync_twitter_image"><?php esc_html_e('Twitter Image', 'metasync'); ?></label>
                <div class="metasync-image-field">
                    <input type="url" 
                           id="metasync_twitter_image" 
                           name="_metasync_twitter_image" 
                           value="<?php echo esc_attr($twitter_image); ?>" 
                           class="widefat metasync-og-input"
                           placeholder="<?php echo esc_attr($og_image); ?>">
                    <div class="metasync-image-controls">
                        <button type="button" class="button metasync-upload-twitter-image">
                            <?php esc_html_e('Select Image', 'metasync'); ?>
                        </button>
                        <button type="button" class="button metasync-remove-twitter-image" style="<?php echo empty($twitter_image) ? 'display:none;' : ''; ?>">
                            <?php esc_html_e('Remove', 'metasync'); ?>
                        </button>
                    </div>
                </div>
                <div class="metasync-twitter-image-preview" <?php echo empty($twitter_image) ? 'style="display:none;"' : ''; ?>>
                    <img src="<?php echo esc_url($twitter_image); ?>" alt="<?php esc_html_e('Preview', 'metasync'); ?>">
                </div>
                <p class="description">
                    <?php esc_html_e('Twitter-specific image. Leave empty to use the Open Graph image. Recommended: 1200x675 pixels.', 'metasync'); ?>
                </p>
            </div>

            <div class="metasync-og-field-group">
                <label for="metasync_twitter_image_alt"><?php esc_html_e('Twitter Image Alt Text', 'metasync'); ?></label>
                <input type="text" 
                       id="metasync_twitter_image_alt" 
                       name="_metasync_twitter_image_alt" 
                       value="<?php echo esc_attr($twitter_image_alt); ?>" 
                       class="widefat metasync-og-input"
                       maxlength="420"
                       placeholder="<?php esc_html_e('Describe the image for visually impaired users', 'metasync'); ?>">
                <p class="description">
                    <?php esc_html_e('Alt text for the Twitter image. Essential for accessibility. Max: 420 characters.', 'metasync'); ?>
                    <span class="metasync-char-count" data-target="metasync_twitter_image_alt">0/420</span>
                </p>
            </div>
        </div>

        <!-- Twitter App Card Section -->
        <div class="metasync-twitter-app-section" style="display: none;">
            <h4><?php esc_html_e('Twitter App Card Settings', 'metasync'); ?></h4>
            <p class="description">
                <?php esc_html_e('Configure settings for Twitter App Card. These fields are only used when Twitter Card type is set to "App".', 'metasync'); ?>
            </p>
            
            <div class="metasync-og-field-group">
                <label for="metasync_twitter_app_id_iphone"><?php esc_html_e('iPhone App ID', 'metasync'); ?> <span class="required">*</span></label>
                <input type="text" 
                       id="metasync_twitter_app_id_iphone" 
                       name="_metasync_twitter_app_id_iphone" 
                       value="<?php echo esc_attr($twitter_app_id_iphone); ?>" 
                       class="widefat"
                       placeholder="307234931"
                       disabled>
                <p class="description">
                    <?php esc_html_e('Numeric representation of your app ID in the App Store (e.g., "307234931").', 'metasync'); ?>
                </p>
            </div>

            <div class="metasync-og-field-group">
                <label for="metasync_twitter_app_id_ipad"><?php esc_html_e('iPad App ID', 'metasync'); ?> <span class="required">*</span></label>
                <input type="text" 
                       id="metasync_twitter_app_id_ipad" 
                       name="_metasync_twitter_app_id_ipad" 
                       value="<?php echo esc_attr($twitter_app_id_ipad); ?>" 
                       class="widefat"
                       placeholder="307234931"
                       disabled>
                <p class="description">
                    <?php esc_html_e('Numeric representation of your app ID in the App Store (e.g., "307234931").', 'metasync'); ?>
                </p>
            </div>

            <div class="metasync-og-field-group">
                <label for="metasync_twitter_app_id_googleplay"><?php esc_html_e('Google Play App ID', 'metasync'); ?> <span class="required">*</span></label>
                <input type="text" 
                       id="metasync_twitter_app_id_googleplay" 
                       name="_metasync_twitter_app_id_googleplay" 
                       value="<?php echo esc_attr($twitter_app_id_googleplay); ?>" 
                       class="widefat"
                       placeholder="com.android.app"
                       disabled>

                <p class="description">
                    <?php esc_html_e('String representation of your app ID in Google Play (e.g., "com.android.app").', 'metasync'); ?>
                </p>
            </div>

            <div class="metasync-og-field-row">
                <div class="metasync-og-field-group metasync-half-width">
                    <label for="metasync_twitter_app_url_iphone"><?php esc_html_e('iPhone Custom URL', 'metasync'); ?></label>
                    <input type="url" 
                           id="metasync_twitter_app_url_iphone" 
                           name="_metasync_twitter_app_url_iphone" 
                           value="<?php echo esc_attr($twitter_app_url_iphone); ?>" 
                           class="widefat"
                          placeholder="myapp://"
                           disabled>
                    <p class="description">
                        <?php esc_html_e('Custom URL scheme (include "://" after scheme name).', 'metasync'); ?>
                    </p>
                </div>

                <div class="metasync-og-field-group metasync-half-width">
                    <label for="metasync_twitter_app_url_ipad"><?php esc_html_e('iPad Custom URL', 'metasync'); ?></label>
                    <input type="url" 
                           id="metasync_twitter_app_url_ipad" 
                           name="_metasync_twitter_app_url_ipad" 
                           value="<?php echo esc_attr($twitter_app_url_ipad); ?>" 
                           class="widefat"
                          placeholder="myapp://"
                           disabled>
                    <p class="description">
                        <?php esc_html_e('Custom URL scheme (include "://" after scheme name).', 'metasync'); ?>
                    </p>
                </div>
            </div>

            <div class="metasync-og-field-row">
                <div class="metasync-og-field-group metasync-half-width">
                    <label for="metasync_twitter_app_url_googleplay"><?php esc_html_e('Google Play Custom URL', 'metasync'); ?></label>
                    <input type="url" 
                           id="metasync_twitter_app_url_googleplay" 
                           name="_metasync_twitter_app_url_googleplay" 
                           value="<?php echo esc_attr($twitter_app_url_googleplay); ?>" 
                           class="widefat"
                          placeholder="myapp://"
                           disabled>
                    <p class="description">
                        <?php esc_html_e('Custom URL scheme (include "://" after scheme name).', 'metasync'); ?>
                    </p>
                </div>

                <div class="metasync-og-field-group metasync-half-width">
                    <label for="metasync_twitter_app_country"><?php esc_html_e('App Store Country', 'metasync'); ?></label>
                    <select id="metasync_twitter_app_country" name="_metasync_twitter_app_country" class="widefat" disabled>
                        <option value=""><?php esc_html_e('US (Default)', 'metasync'); ?></option>
                        <option value="GB" <?php selected($twitter_app_country, 'GB'); ?>><?php esc_html_e('United Kingdom', 'metasync'); ?></option>
                        <option value="CA" <?php selected($twitter_app_country, 'CA'); ?>><?php esc_html_e('Canada', 'metasync'); ?></option>
                        <option value="AU" <?php selected($twitter_app_country, 'AU'); ?>><?php esc_html_e('Australia', 'metasync'); ?></option>
                        <option value="DE" <?php selected($twitter_app_country, 'DE'); ?>><?php esc_html_e('Germany', 'metasync'); ?></option>
                        <option value="FR" <?php selected($twitter_app_country, 'FR'); ?>><?php esc_html_e('France', 'metasync'); ?></option>
                        <option value="JP" <?php selected($twitter_app_country, 'JP'); ?>><?php esc_html_e('Japan', 'metasync'); ?></option>
                        <option value="IN" <?php selected($twitter_app_country, 'IN'); ?>><?php esc_html_e('India', 'metasync'); ?></option>
                        <option value="BR" <?php selected($twitter_app_country, 'BR'); ?>><?php esc_html_e('Brazil', 'metasync'); ?></option>
                        <option value="MX" <?php selected($twitter_app_country, 'MX'); ?>><?php esc_html_e('Mexico', 'metasync'); ?></option>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Required if your app is not available in the US App Store.', 'metasync'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Twitter Player Card Section -->
        <div class="metasync-twitter-player-section" style="display: none;">
            <h4><?php esc_html_e('Twitter Player Card Settings', 'metasync'); ?></h4>
            <p class="description">
                <?php esc_html_e('Configure settings for Twitter Player Card. These fields are only used when Twitter Card type is set to "Player".', 'metasync'); ?>
            </p>
            
            <div class="metasync-og-field-group">
                <label for="metasync_twitter_player"><?php esc_html_e('Player URL', 'metasync'); ?> <span class="required">*</span></label>
                <input type="url" 
                       id="metasync_twitter_player" 
                       name="_metasync_twitter_player" 
                       value="<?php echo esc_attr($twitter_player); ?>" 
                       class="widefat"
                       placeholder="https://example.com/player"
                       disabled>
                <p class="description">
                    <?php esc_html_e('HTTPS URL to iFrame player. Must be HTTPS and not generate mixed content warnings.', 'metasync'); ?>
                </p>
            </div>

            <div class="metasync-og-field-row">
                <div class="metasync-og-field-group metasync-half-width">
                    <label for="metasync_twitter_player_width"><?php esc_html_e('Player Width (px)', 'metasync'); ?> <span class="required">*</span></label>
                    <input type="number" 
                           id="metasync_twitter_player_width" 
                           name="_metasync_twitter_player_width" 
                           value="<?php echo esc_attr($twitter_player_width); ?>" 
                           class="widefat"
                           min="1"
                          placeholder="1280"
                           disabled>
                    <p class="description">
                        <?php esc_html_e('Width of iFrame player in pixels.', 'metasync'); ?>
                    </p>
                </div>

                <div class="metasync-og-field-group metasync-half-width">
                    <label for="metasync_twitter_player_height"><?php esc_html_e('Player Height (px)', 'metasync'); ?> <span class="required">*</span></label>
                    <input type="number" 
                           id="metasync_twitter_player_height" 
                           name="_metasync_twitter_player_height" 
                           value="<?php echo esc_attr($twitter_player_height); ?>" 
                           class="widefat"
                           min="1"
                          placeholder="720"
                           disabled>
                    <p class="description">
                        <?php esc_html_e('Height of iFrame player in pixels.', 'metasync'); ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Social Media Preview -->
        <div class="metasync-preview-section">
            <h4><?php esc_html_e('Social Media Preview', 'metasync'); ?></h4>
            <p class="description">
                <?php esc_html_e('This is how your content will look when shared on social media platforms.', 'metasync'); ?>
            </p>
            
            <div class="metasync-preview-container">
                <div class="metasync-preview-loading" style="display:none;">
                    <span class="spinner is-active"></span>
                    <?php esc_html_e('Generating preview...', 'metasync'); ?>
                </div>
                
                <div class="metasync-preview-content-wrapper">
                    <?php 
                    // Show initial preview with current values
                    $initial_title = !empty($og_title) ? $og_title : $post->post_title;
                    $initial_description = !empty($og_description) ? $og_description : $this->get_post_excerpt($post);
                    $initial_image = !empty($og_image) ? $og_image : $this->get_featured_image_url($post->ID);
                    $initial_url = !empty($og_url) ? $og_url : get_permalink($post->ID);
                    
                    echo $this->generate_preview_html($initial_title, $initial_description, $initial_image, $initial_url);
                    ?>
                </div>
            </div>
            
            <button type="button" class="button button-secondary metasync-refresh-preview">
                <?php esc_html_e('Refresh Preview', 'metasync'); ?>
            </button>
        </div>
    </div>
</div>

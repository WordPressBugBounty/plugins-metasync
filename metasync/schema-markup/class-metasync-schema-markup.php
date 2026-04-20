<?php

/**
 * The Schema Markup functionality of the plugin.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/schema-markup
 * @author     Engineering Team <support@searchatlas.com>
 */

// Abort if this file is accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

class Metasync_Schema_Markup
{
    private $plugin_name;
    private $version;
    private $common;

    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
        $this->common = new Metasync_Common();

        // Initialize hooks
        add_action('admin_init', [$this, 'add_schema_markup_meta_box']);
        add_action('save_post', [$this, 'save_schema_markup_data']);
        add_action('wp_head', [$this, 'output_schema_markup']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_notices', [$this, 'display_schema_validation_notices']);
    }

    /**
     * Add the Schema Markup meta box to post and page editors
     */
    public function add_schema_markup_meta_box()
    {
        // Don't show metabox if user's role doesn't have plugin access
        if (!Metasync::current_user_has_plugin_access()) {
            return;
        }

        // Don't show metabox if disabled in Post/Page Editor Settings
        $general_settings = Metasync::get_option('general', []);
        if (!empty($general_settings['disable_schema_markup_metabox'])) {
            return;
        }

        $plugin_name = Metasync::get_effective_plugin_name();

        add_meta_box(
            'metasync-schema-markup',
            "Schema Markup by $plugin_name",
            [$this, 'schema_markup_meta_box_display'],
            ['post', 'page'],
            'normal',
            'default'
        );
    }

    /**
     * Display the Schema Markup meta box
     */
    public function schema_markup_meta_box_display($post)
    {
        // Get existing schema data
        $schema_data = get_post_meta($post->ID, 'metasync_schema_markup', true);
        $schema_enabled = isset($schema_data['enabled']) ? $schema_data['enabled'] : false;
        $schema_types = isset($schema_data['types']) ? $schema_data['types'] : [];

        // Get validation errors if any
        $validation_errors = get_post_meta($post->ID, '_metasync_schema_validation_errors', true);
        $has_errors = !empty($validation_errors) && is_array($validation_errors);

        // Schema is always enabled by default
        $global_schema_enabled = true;

        // Add nonce for security
        wp_nonce_field('metasync_schema_markup_nonce', 'metasync_schema_markup_nonce');

        ?>
        <div class="metasync-schema-markup-container">
            
            <?php if (!$global_schema_enabled): ?>
            <div class="metasync-schema-global-disabled-notice" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 15px 0; border-radius: 4px;">
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #856404;">
                    <span class="dashicons dashicons-info" style="color: #ffc107; vertical-align: middle;"></span>
                    Global Schema Markup is Disabled
                </p>
                <p style="margin: 0; color: #856404; font-size: 13px;">
                    Schema markup is disabled globally. Please enable it in 
                    <a href="<?php echo admin_url('admin.php?page=searchatlas'); ?>" target="_blank">General Configuration → Enable Schema</a> 
                    for schema markup to be output on the frontend.
                </p>
            </div>
            <?php endif; ?>
            <p>
                <label>
                    <input type="checkbox" name="schema_markup[enabled]" value="1" <?php checked($schema_enabled, true); ?>>
                    Enable Schema Markup for this post/page
                </label>
            </p>

            <?php if ($schema_enabled && $has_errors): ?>
            <div class="metasync-schema-validation-warning" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 15px 0; border-radius: 4px;">
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #856404;">
                    <span class="dashicons dashicons-warning" style="color: #ffc107;"></span>
                    Validation Issues Detected
                </p>
                <ul style="margin: 0; padding-left: 20px; color: #856404;">
                    <?php foreach ($validation_errors as $error): ?>
                        <li><?php echo wp_kses_post($error['message']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="schema-types-container" style="<?php echo $schema_enabled ? '' : 'display: none;'; ?>">
                <div class="schema-types-header" style="margin-bottom: 20px;">
                    <h4 style="margin: 0 0 10px 0;">Schema Types</h4>
                    <p class="description" style="margin: 0;">Add multiple schema types to enhance your content's search visibility.</p>
                </div>

                <div id="schema-types-list">
                    <?php if (!empty($schema_types)): ?>
                        <?php foreach ($schema_types as $index => $schema_type_data): ?>
                            <?php $this->render_schema_type_item($index, $schema_type_data); ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no-schema-types" style="text-align: center; padding: 20px; background: #f9f9f9; border: 2px dashed #ddd; border-radius: 4px; color: #666;">
                            <p style="margin: 0;">No schema types added yet. Click "Add Schema Type" to get started.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="add-schema-type-section" style="margin-top: 20px;">
                    <button type="button" class="button button-primary" id="add_schema_type_button">
                        <span class="dashicons dashicons-plus" style="vertical-align: middle;"></span> Add Schema Type
                    </button>
                </div>

                <div class="schema-preview-section" style="margin-top: 20px;">
                    <button type="button" class="button button-secondary" id="preview_schema_button">
                        <span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span> Preview Schema
                    </button>
                    <button type="button" class="button button-secondary" id="copy_schema_button" style="display: none;">
                        <span class="dashicons dashicons-clipboard" style="vertical-align: middle;"></span> Copy to Clipboard
                    </button>
                </div>

                <div id="schema-preview-output" style="display: none; margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <h4 style="margin: 0;">JSON-LD Preview:</h4>
                        <button type="button" class="button-link" id="close_preview" style="color: #dc3232; text-decoration: none;">
                            <span class="dashicons dashicons-no-alt"></span> Close
                        </button>
                    </div>
                    <pre id="schema-json-preview" style="background: #1e1e1e; color: #ffffff; padding: 15px; border: 1px solid #333; border-radius: 4px; overflow-x: auto; max-height: 500px; font-family: 'Courier New', Consolas, monospace; font-size: 13px; line-height: 1.5;"></pre>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render individual schema type item
     */
    private function render_schema_type_item($index, $schema_type_data)
    {
        $index = (int) $index;
        $schema_type = isset($schema_type_data['type']) ? $schema_type_data['type'] : '';
        $schema_fields = isset($schema_type_data['fields']) ? $schema_type_data['fields'] : [];
        $schema_type_names = [
            'article' => 'Article',
            'FAQPage' => 'FAQ',
            'product' => 'Product',
            'recipe' => 'Recipe',
            'Event' => 'Event',
            'JobPosting' => 'Job Posting',
            'Review' => 'Review',
            'Course' => 'Course',
            'Organization' => 'Organization',
            'Person' => 'Person',
            'WebSite' => 'Website',
            'NewsArticle' => 'News Article',
            'LocalBusiness' => 'Local Business',
            'HowTo' => 'How-To',
            'VideoObject' => 'Video Object'
        ];
        $display_name = isset($schema_type_names[$schema_type]) ? $schema_type_names[$schema_type] : ucfirst($schema_type);
        
        ?>
        <div class="schema-type-item" data-index="<?php echo esc_attr($index); ?>" style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
            <div class="schema-type-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h5 style="margin: 0; color: #333;">
                    <span class="dashicons dashicons-tag" style="color: #0073aa;"></span>
                    <?php echo esc_html($display_name); ?> Schema
                </h5>
                <button type="button" class="button button-link remove-schema-type" style="color: #dc3232; text-decoration: none;">
                    <span class="dashicons dashicons-trash"></span> Remove
                </button>
            </div>
            
            <div class="schema-type-content">
                <input type="hidden" name="schema_markup[types][<?php echo esc_attr($index); ?>][type]" value="<?php echo esc_attr($schema_type); ?>">
                
                <div class="schema-fields-container">
                    <?php $this->render_schema_fields($schema_type, $schema_fields, $index); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render schema fields based on type
     */
    private function render_schema_fields($schema_type, $schema_fields, $index = 0)
    {
        switch ($schema_type) {
            case 'article':
                $this->render_article_fields($schema_fields, $index);
                break;
            case 'FAQPage':
                $this->render_faq_fields($schema_fields, $index);
                break;
            case 'product':
                $this->render_product_fields($schema_fields, $index);
                break;
            case 'recipe':
                $this->render_recipe_fields($schema_fields, $index);
                break;
            case 'Event':
                $this->render_event_fields($schema_fields, $index);
                break;
            case 'JobPosting':
                $this->render_jobposting_fields($schema_fields, $index);
                break;
            case 'Review':
                $this->render_review_fields($schema_fields, $index);
                break;
            case 'Course':
                $this->render_course_fields($schema_fields, $index);
                break;
            case 'Organization':
                $this->render_organization_fields($schema_fields, $index);
                break;
            case 'Person':
                $this->render_person_fields($schema_fields, $index);
                break;
            case 'WebSite':
                $this->render_website_fields($schema_fields, $index);
                break;
            case 'NewsArticle':
                $this->render_newsarticle_fields($schema_fields, $index);
                break;
            case 'LocalBusiness':
                $this->render_local_business_fields($schema_fields, $index);
                break;
            case 'HowTo':
                $this->render_howto_fields($schema_fields, $index);
                break;
            case 'VideoObject':
                $this->render_video_object_fields($schema_fields, $index);
                break;
            default:
                echo '<p>Select a schema type to see available fields.</p>';
                break;
        }
    }

    /**
     * Get default values for schema fields (title, description, image)
     * 
     * @param int $post_id Post ID
     * @return array Array with 'title', 'description', 'image' keys
     */
    private function get_default_schema_values($post_id)
    {
        $defaults = [
            'title' => get_the_title($post_id),
            'description' => '',
            'image' => ''
        ];

        // Get description: excerpt first, then trimmed content
        $post_excerpt = get_the_excerpt($post_id);
        if (!empty($post_excerpt)) {
            $defaults['description'] = $post_excerpt;
        } else {
            $post_content = get_post_field('post_content', $post_id);
            if (!empty($post_content)) {
                $defaults['description'] = wp_trim_words($post_content, 30);
            }
        }

        // Get featured image
        $featured_image_id = get_post_thumbnail_id($post_id);
        if ($featured_image_id) {
            $featured_image_url = wp_get_attachment_image_url($featured_image_id, 'full');
            if ($featured_image_url) {
                $defaults['image'] = $featured_image_url;
            }
        }

        return $defaults;
    }

    /**
     * Render override default fields section
     * 
     * @param array $fields Schema fields
     * @param int $index Schema type index
     * @param string $schema_type Schema type (article, product, recipe)
     * @param bool $include_image Whether to include image override field
     */
    private function render_override_fields_section($fields, $index, $schema_type, $include_image = true)
    {
        global $post;
        $post_id = $post ? $post->ID : 0;
        
        if (!$post_id) {
            return;
        }

        $defaults = $this->get_default_schema_values($post_id);
        
        // Get override values - only populate with placeholders for NEW schemas (keys don't exist)
        // If keys exist but are empty, respect the user's choice to leave them blank
        if (!array_key_exists('title_override', $fields)) {
            // New schema - populate with placeholder
            $title_override = '{{post_title}}';
        } else {
            // Existing schema - respect saved value (even if blank)
            $title_override = $fields['title_override'];
        }
        
        if (!array_key_exists('description_override', $fields)) {
            // New schema - populate with placeholder
            $description_override = '{{post_description}}';
        } else {
            // Existing schema - respect saved value (even if blank)
            $description_override = $fields['description_override'];
        }
        
        if (!array_key_exists('image_override', $fields)) {
            // New schema - populate with placeholder
            $image_override = '{{featured_image}}';
        } else {
            // Existing schema - respect saved value (even if blank)
            $image_override = $fields['image_override'];
        }
        
        // Use override value for image preview if provided and not a placeholder, otherwise use default
        $image_placeholder = ($image_override && !$this->is_placeholder($image_override)) ? $image_override : $defaults['image'];

        // Check if any overrides are active (not empty and not placeholders)
        $has_overrides = (!empty($title_override) && !$this->is_placeholder($title_override)) || 
                        (!empty($description_override) && !$this->is_placeholder($description_override)) || 
                        (!empty($image_override) && !$this->is_placeholder($image_override));
        
        ?>
        <div class="schema-override-fields-section" style="margin-bottom: 20px;">
            <div class="schema-override-header" style="background: #f5f5f5; border: 1px solid #ddd; border-radius: 4px; padding: 10px; cursor: pointer;" data-toggle-target="override-fields-<?php echo $index; ?>">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h4 style="margin: 0; font-size: 14px; font-weight: 600; color: #333;">
                        <span class="dashicons dashicons-edit" style="color: #0073aa; vertical-align: middle;"></span>
                        Override Default Fields
                        <?php if ($has_overrides): ?>
                            <span class="override-indicator" style="background: #0073aa; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-left: 8px;">Active</span>
                        <?php endif; ?>
                    </h4>
                    <span class="dashicons dashicons-arrow-down-alt2 toggle-icon" style="color: #666; transition: transform 0.3s;"></span>
                </div>
            </div>
            
            <div class="schema-override-content" id="override-fields-<?php echo $index; ?>" style="display: none; padding: 15px; background: #fff; border: 1px solid #ddd; border-top: none; border-radius: 0 0 4px 4px;">
                <p class="description" style="margin: 0 0 15px 0; color: #000000; font-size: 13px;">
                    These fields are required and will be used in schema markup.
                </p>

                <div class="schema-field" style="margin-bottom: 15px;">
                    <label for="override_title_<?php echo $index; ?>" style="display: block; font-weight: 600; margin-bottom: 5px; color: #333;">
                        Title: <span style="color: #dc3232;">*</span>
                    </label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" 
                               name="schema_markup[types][<?php echo $index; ?>][fields][title_override]" 
                               id="override_title_<?php echo $index; ?>" 
                               value="<?php echo esc_attr($title_override); ?>" 
                               data-default-value="{{post_title}}"
                               style="flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 3px; font-size: 14px;">
                        <button type="button" class="button reset-override-field" data-field="override_title_<?php echo $index; ?>" style="flex-shrink: 0;">
                            Reset
                        </button>
                    </div>
                    <p class="description" style="margin: 5px 0 0 0; color: #000000; font-size: 12px;">
                        Default: {{post_title}} (<?php echo esc_html(wp_trim_words($defaults['title'], 10)); ?>)
                    </p>
                </div>

                <div class="schema-field" style="margin-bottom: 15px;">
                    <label for="override_description_<?php echo $index; ?>" style="display: block; font-weight: 600; margin-bottom: 5px; color: #333;">
                        Description: <span style="color: #dc3232;">*</span>
                    </label>
                    <div style="display: flex; gap: 8px; align-items: flex-start;">
                        <textarea name="schema_markup[types][<?php echo $index; ?>][fields][description_override]" 
                                  id="override_description_<?php echo $index; ?>" 
                                  data-default-value="{{post_description}}"
                                  rows="4"
                                  style="flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 3px; font-size: 14px; resize: vertical;"><?php echo esc_textarea($description_override); ?></textarea>
                        <button type="button" class="button reset-override-field" data-field="override_description_<?php echo $index; ?>" style="flex-shrink: 0; margin-top: 0;">
                            Reset
                        </button>
                    </div>
                    <p class="description" style="margin: 5px 0 0 0; color: #000000; font-size: 12px;">
                        Default: {{post_description}} (<?php echo esc_html(wp_trim_words($defaults['description'], 20)); ?>)
                    </p>
                </div>

                <?php if ($include_image): ?>
                <div class="schema-field" style="margin-bottom: 15px;">
                    <label for="override_image_<?php echo $index; ?>" style="display: block; font-weight: 600; margin-bottom: 5px; color: #333;">
                        Image: <span style="color: #dc3232;">*</span>
                    </label>
                    <div class="image-upload-container" style="margin-bottom: 10px;">
                        <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 10px;">
                            <input type="text" 
                                   name="schema_markup[types][<?php echo $index; ?>][fields][image_override]" 
                                   id="override_image_<?php echo $index; ?>" 
                                   value="<?php echo esc_attr($image_override); ?>" 
                                   data-default-value="{{featured_image}}"
                                   style="flex: 1; padding: 8px 12px; border: 1px solid #ddd; border-radius: 3px; font-size: 14px;">
                            <button type="button" class="button upload-image-button" data-target="<?php echo $index; ?>" data-field-id="override_image_<?php echo $index; ?>">
                                Upload Image
                            </button>
                            <button type="button" class="button reset-override-field" data-field="override_image_<?php echo $index; ?>" style="flex-shrink: 0;">
                                Reset
                            </button>
                        </div>
                        <?php if ($image_placeholder): ?>
                        <div class="image-preview" id="override_image_preview_<?php echo $index; ?>" style="margin-top: 10px;">
                            <img src="<?php echo esc_attr($image_placeholder); ?>" alt="Image Preview" style="max-width: 200px; max-height: 150px; border: 1px solid #ddd; padding: 5px; border-radius: 3px;">
                        </div>
                        <?php else: ?>
                        <div class="image-preview" id="override_image_preview_<?php echo $index; ?>" style="display: none; margin-top: 10px;">
                            <img src="" alt="Image Preview" style="max-width: 200px; max-height: 150px; border: 1px solid #ddd; padding: 5px; border-radius: 3px;">
                        </div>
                        <?php endif; ?>
                    </div>
                    <p class="description" style="margin: 5px 0 0 0; color: #000000; font-size: 12px;">
                        Default: {{featured_image}} <?php if ($defaults['image']): ?>(<a href="<?php echo esc_url($defaults['image']); ?>" target="_blank">Current Featured Image</a>)<?php else: ?>(No featured image set)<?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Article schema fields
     */
    private function render_article_fields($fields, $index = 0)
    {
        $organization_name = isset($fields['organization_name']) ? $fields['organization_name'] : '';
        $organization_logo = isset($fields['organization_logo']) ? $fields['organization_logo'] : '';

        ?>
        <div class="schema-field-group">
            <?php $this->render_override_fields_section($fields, $index, 'article', true); ?>

            <h4>Article Information</h4>
            <p class="description" style="margin-top: 0;">Optional fields to enhance your article schema.</p>
            
            <div class="schema-field">
                <label for="article_organization_name_<?php echo $index; ?>">Organization Name:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][organization_name]" id="article_organization_name_<?php echo $index; ?>" value="<?php echo esc_attr($organization_name); ?>" placeholder="e.g., Search Atlas">
                <p class="description">The name of the organization that published this article.</p>
            </div>

            <div class="schema-field">
                <label for="article_organization_logo_<?php echo $index; ?>">Organization Logo:</label>
                <div class="logo-upload-container">
                    <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][organization_logo]" id="article_organization_logo_<?php echo $index; ?>" value="<?php echo esc_attr($organization_logo); ?>" placeholder="https://example.com/logo.png" style="width: 70%; margin-right: 10px;">
                    <button type="button" class="button upload-logo-button" data-target="<?php echo $index; ?>">Upload Logo</button>
                    <button type="button" class="button remove-logo-button" data-target="<?php echo $index; ?>" style="<?php echo empty($organization_logo) ? 'display: none;' : ''; ?>">Remove</button>
                </div>
                <div class="logo-preview" id="logo_preview_<?php echo $index; ?>" style="<?php echo empty($organization_logo) ? 'display: none;' : ''; ?>">
                    <img src="<?php echo esc_attr($organization_logo); ?>" alt="Logo Preview" style="max-width: 200px; max-height: 100px; margin-top: 10px; border: 1px solid #ddd; padding: 5px;">
                </div>
                <p class="description">Logo of the organization (recommended for better rich results).</p>
            </div>
        </div>
        <?php
    }

    /**
     * Render FAQ schema fields
     */
    private function render_faq_fields($fields, $index = 0)
    {
        $index = (int) $index;
        $faq_items = isset($fields['faq_items']) ? $fields['faq_items'] : [['question' => '', 'answer' => '']];

        ?>
        <div class="schema-field-group">
            <h4>FAQ Questions & Answers</h4>
            <p class="description">Add questions and answers for your FAQ page. Both fields are required for each FAQ item.</p>
            
            <div class="faq-items-list">
                <?php foreach ($faq_items as $faq_index => $item): ?>
                    <div class="faq-item" style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                        <div class="schema-field">
                            <label>Question <?php echo $faq_index + 1; ?>: <span style="color: #dc3232;">*</span></label>
                            <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][faq_items][<?php echo $faq_index; ?>][question]" value="<?php echo esc_attr($item['question']); ?>" placeholder="Enter your question" style="width: 100%; margin-bottom: 10px;">
                        </div>
                        <div class="schema-field">
                            <label>Answer <?php echo $faq_index + 1; ?>: <span style="color: #dc3232;">*</span></label>
                            <textarea name="schema_markup[types][<?php echo $index; ?>][fields][faq_items][<?php echo $faq_index; ?>][answer]" placeholder="Enter your answer" style="width: 100%; height: 80px; margin-bottom: 10px;"><?php echo esc_textarea($item['answer']); ?></textarea>
                        </div>
                        <button type="button" class="button remove-faq-item" style="background: #dc3232; color: white; border-color: #dc3232;">Remove Question</button>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <button type="button" class="button add-faq-item" style="margin-top: 10px;">Add Question</button>
        </div>
        <?php
    }

    /**
     * Render Product schema fields
     */
    private function render_product_fields($fields, $index = 0)
    {
        $sku = isset($fields['sku']) ? $fields['sku'] : '';
        $brand = isset($fields['brand']) ? $fields['brand'] : '';
        $price = isset($fields['price']) ? $fields['price'] : '';
        $currency = isset($fields['currency']) ? $fields['currency'] : 'USD';
        $availability = isset($fields['availability']) ? $fields['availability'] : 'InStock';
        $condition = isset($fields['condition']) ? $fields['condition'] : 'NewCondition';

        ?>
        <div class="schema-field-group">
            <?php $this->render_override_fields_section($fields, $index, 'product', true); ?>

            <h4>Product Information</h4>
            <p class="description" style="margin-top: 0;">Fill in the product details below. Fields marked with * are required.</p>
            
            <div class="schema-field">
                <label for="product_sku_<?php echo $index; ?>">SKU:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][sku]" id="product_sku_<?php echo $index; ?>" value="<?php echo esc_attr($sku); ?>" placeholder="Product SKU">
            </div>

            <div class="schema-field">
                <label for="product_brand_<?php echo $index; ?>">Brand:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][brand]" id="product_brand_<?php echo $index; ?>" value="<?php echo esc_attr($brand); ?>" placeholder="Product Brand">
            </div>

            <div class="schema-field">
                <label for="product_price_<?php echo $index; ?>">Price: <span style="color: #dc3232;">*</span></label>
                <input type="number" step="0.01" name="schema_markup[types][<?php echo $index; ?>][fields][price]" id="product_price_<?php echo $index; ?>" value="<?php echo esc_attr($price); ?>" placeholder="0.00">
                <p class="description">Required: Enter the product price (must be greater than 0).</p>
            </div>

            <div class="schema-field">
                <label for="product_currency_<?php echo $index; ?>">Currency:</label>
                <select name="schema_markup[types][<?php echo $index; ?>][fields][currency]" id="product_currency_<?php echo $index; ?>">
                    <option value="USD" <?php selected($currency, 'USD'); ?>>USD</option>
                    <option value="EUR" <?php selected($currency, 'EUR'); ?>>EUR</option>
                    <option value="GBP" <?php selected($currency, 'GBP'); ?>>GBP</option>
                    <option value="CAD" <?php selected($currency, 'CAD'); ?>>CAD</option>
                    <option value="AUD" <?php selected($currency, 'AUD'); ?>>AUD</option>
                </select>
            </div>

            <div class="schema-field">
                <label for="product_availability_<?php echo $index; ?>">Availability:</label>
                <select name="schema_markup[types][<?php echo $index; ?>][fields][availability]" id="product_availability_<?php echo $index; ?>">
                    <option value="InStock" <?php selected($availability, 'InStock'); ?>>In Stock</option>
                    <option value="OutOfStock" <?php selected($availability, 'OutOfStock'); ?>>Out of Stock</option>
                    <option value="PreOrder" <?php selected($availability, 'PreOrder'); ?>>Pre-Order</option>
                    <option value="LimitedAvailability" <?php selected($availability, 'LimitedAvailability'); ?>>Limited Availability</option>
                </select>
            </div>

            <div class="schema-field">
                <label for="product_condition_<?php echo $index; ?>">Condition:</label>
                <select name="schema_markup[types][<?php echo $index; ?>][fields][condition]" id="product_condition_<?php echo $index; ?>">
                    <option value="NewCondition" <?php selected($condition, 'NewCondition'); ?>>New</option>
                    <option value="UsedCondition" <?php selected($condition, 'UsedCondition'); ?>>Used</option>
                    <option value="RefurbishedCondition" <?php selected($condition, 'RefurbishedCondition'); ?>>Refurbished</option>
                    <option value="DamagedCondition" <?php selected($condition, 'DamagedCondition'); ?>>Damaged</option>
                </select>
            </div>
        </div>
        <?php
    }

    /**
     * Render Recipe schema fields
     */
    private function render_recipe_fields($fields, $index = 0)
    {
        $yield = isset($fields['yield']) ? $fields['yield'] : '';
        $ingredients = isset($fields['ingredients']) ? $fields['ingredients'] : [''];
        $instructions = isset($fields['instructions']) ? $fields['instructions'] : [''];
        $prep_time = isset($fields['prep_time']) ? $fields['prep_time'] : '';
        $cook_time = isset($fields['cook_time']) ? $fields['cook_time'] : '';
        $total_time = isset($fields['total_time']) ? $fields['total_time'] : '';
        $calories = isset($fields['calories']) ? $fields['calories'] : '';

        ?>
        <div class="schema-field-group">
            <?php $this->render_override_fields_section($fields, $index, 'recipe', true); ?>

            <h4>Recipe Information</h4>
            <p class="description" style="margin-top: 0;">Optional fields to enhance your recipe schema.</p>
            
            <div class="schema-field">
                <label for="recipe_yield_<?php echo $index; ?>">Yield (servings):</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][yield]" id="recipe_yield_<?php echo $index; ?>" value="<?php echo esc_attr($yield); ?>" placeholder="e.g., 4 servings">
            </div>

            <div class="schema-field">
                <label>Ingredients:</label>
                <div class="ingredients-list">
                    <?php foreach ($ingredients as $ingredient): ?>
                    <div class="ingredient-item">
                        <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][ingredients][]" value="<?php echo esc_attr($ingredient); ?>" placeholder="Enter ingredient">
                        <button type="button" class="remove-item">Remove</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="add-ingredient">Add Ingredient</button>
            </div>

            <div class="schema-field">
                <label>Instructions:</label>
                <div class="instructions-list">
                    <?php foreach ($instructions as $instruction): ?>
                    <div class="instruction-item">
                        <textarea name="schema_markup[types][<?php echo $index; ?>][fields][instructions][]" placeholder="Enter instruction step"><?php echo esc_textarea($instruction); ?></textarea>
                        <button type="button" class="remove-item">Remove</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="add-instruction">Add Instruction</button>
            </div>

            <div class="schema-field">
                <label for="recipe_prep_time_<?php echo $index; ?>">Prep Time (minutes):</label>
                <input type="number" step="any" min="0" name="schema_markup[types][<?php echo $index; ?>][fields][prep_time]" id="recipe_prep_time_<?php echo $index; ?>" value="<?php echo esc_attr($prep_time); ?>" placeholder="15" class="recipe-time-input">
            </div>

            <div class="schema-field">
                <label for="recipe_cook_time_<?php echo $index; ?>">Cook Time (minutes):</label>
                <input type="number" step="any" min="0" name="schema_markup[types][<?php echo $index; ?>][fields][cook_time]" id="recipe_cook_time_<?php echo $index; ?>" value="<?php echo esc_attr($cook_time); ?>" placeholder="30" class="recipe-time-input">
            </div>

            <div class="schema-field">
                <label for="recipe_total_time_<?php echo $index; ?>">Total Time (minutes):</label>
                <input type="number" step="any" min="0" name="schema_markup[types][<?php echo $index; ?>][fields][total_time]" id="recipe_total_time_<?php echo $index; ?>" value="<?php echo esc_attr($total_time); ?>" placeholder="45" class="recipe-time-input">
            </div>

            <div class="schema-field">
                <label for="recipe_calories_<?php echo $index; ?>">Calories per serving:</label>
                <input type="number" name="schema_markup[types][<?php echo $index; ?>][fields][calories]" id="recipe_calories_<?php echo $index; ?>" value="<?php echo esc_attr($calories); ?>" placeholder="250">
            </div>
        </div>
        <?php
    }

    /**
     * Render LocalBusiness schema fields
     */
    private function render_local_business_fields($fields, $index = 0)
    {
        $business_name = isset($fields['business_name']) ? $fields['business_name'] : '';
        $street_address = isset($fields['street_address']) ? $fields['street_address'] : '';
        $description = isset($fields['description']) ? $fields['description'] : '';
        $url = isset($fields['url']) ? $fields['url'] : '';
        $telephone = isset($fields['telephone']) ? $fields['telephone'] : '';
        $price_range = isset($fields['price_range']) ? $fields['price_range'] : '';
        $image = isset($fields['image']) ? $fields['image'] : '';
        $city = isset($fields['city']) ? $fields['city'] : '';
        $state = isset($fields['state']) ? $fields['state'] : '';
        $postal_code = isset($fields['postal_code']) ? $fields['postal_code'] : '';
        $country = isset($fields['country']) ? $fields['country'] : '';
        $latitude = isset($fields['latitude']) ? $fields['latitude'] : '';
        $longitude = isset($fields['longitude']) ? $fields['longitude'] : '';
        $opening_hours = isset($fields['opening_hours']) ? $fields['opening_hours'] : [];

        ?>
        <div class="schema-field-group">
            <h4>Business Information</h4>
            <p class="description" style="margin-top: 0;">Fill in the business details below. Fields marked with * are required.</p>

            <div class="schema-field">
                <label for="local_business_name_<?php echo $index; ?>">Business Name: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][business_name]" id="local_business_name_<?php echo $index; ?>" value="<?php echo esc_attr($business_name); ?>" placeholder="e.g., Joe's Coffee Shop" style="width: 100%;">
            </div>

            <div class="schema-field">
                <label for="local_business_street_<?php echo $index; ?>">Street Address: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][street_address]" id="local_business_street_<?php echo $index; ?>" value="<?php echo esc_attr($street_address); ?>" placeholder="e.g., 123 Main St" style="width: 100%;">
            </div>

            <div class="schema-field">
                <label for="local_business_description_<?php echo $index; ?>">Description:</label>
                <textarea name="schema_markup[types][<?php echo $index; ?>][fields][description]" id="local_business_description_<?php echo $index; ?>" placeholder="Brief description of the business" style="width: 100%; height: 80px;"><?php echo esc_textarea($description); ?></textarea>
            </div>

            <div class="schema-field">
                <label for="local_business_url_<?php echo $index; ?>">URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][url]" id="local_business_url_<?php echo $index; ?>" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com" style="width: 100%;">
            </div>

            <div class="schema-field">
                <label for="local_business_telephone_<?php echo $index; ?>">Telephone:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][telephone]" id="local_business_telephone_<?php echo $index; ?>" value="<?php echo esc_attr($telephone); ?>" placeholder="e.g., +1-555-555-5555">
            </div>

            <div class="schema-field">
                <label for="local_business_price_range_<?php echo $index; ?>">Price Range:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][price_range]" id="local_business_price_range_<?php echo $index; ?>" value="<?php echo esc_attr($price_range); ?>" placeholder="$$$">
            </div>

            <div class="schema-field">
                <label for="local_business_image_<?php echo $index; ?>">Image:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][image]" id="local_business_image_<?php echo $index; ?>" value="<?php echo esc_attr($image); ?>" placeholder="https://example.com/image.jpg" style="width: 100%;">
            </div>

            <h4>Address Details</h4>

            <div class="schema-field">
                <label for="local_business_city_<?php echo $index; ?>">City:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][city]" id="local_business_city_<?php echo $index; ?>" value="<?php echo esc_attr($city); ?>" placeholder="e.g., San Francisco">
            </div>

            <div class="schema-field">
                <label for="local_business_state_<?php echo $index; ?>">State:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][state]" id="local_business_state_<?php echo $index; ?>" value="<?php echo esc_attr($state); ?>" placeholder="e.g., CA">
            </div>

            <div class="schema-field">
                <label for="local_business_postal_code_<?php echo $index; ?>">Postal Code:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][postal_code]" id="local_business_postal_code_<?php echo $index; ?>" value="<?php echo esc_attr($postal_code); ?>" placeholder="e.g., 94105">
            </div>

            <div class="schema-field">
                <label for="local_business_country_<?php echo $index; ?>">Country:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][country]" id="local_business_country_<?php echo $index; ?>" value="<?php echo esc_attr($country); ?>" placeholder="e.g., US">
            </div>

            <h4>Geo Coordinates</h4>

            <div class="schema-field">
                <label for="local_business_latitude_<?php echo $index; ?>">Latitude:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][latitude]" id="local_business_latitude_<?php echo $index; ?>" value="<?php echo esc_attr($latitude); ?>" placeholder="e.g., 37.7749">
            </div>

            <div class="schema-field">
                <label for="local_business_longitude_<?php echo $index; ?>">Longitude:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][longitude]" id="local_business_longitude_<?php echo $index; ?>" value="<?php echo esc_attr($longitude); ?>" placeholder="e.g., -122.4194">
            </div>

            <h4>Opening Hours</h4>
            <p class="description">Add opening hours for the business.</p>

            <div class="opening-hours-list">
                <?php if (!empty($opening_hours)): ?>
                    <?php foreach ($opening_hours as $oh_index => $hours): ?>
                        <div class="opening-hours-item" style="margin: 10px 0; padding: 10px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                            <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][opening_hours][<?php echo $oh_index; ?>][day]" value="<?php echo esc_attr($hours['day']); ?>" placeholder="e.g., Monday" style="width: 30%;">
                            <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][opening_hours][<?php echo $oh_index; ?>][open]" value="<?php echo esc_attr($hours['open']); ?>" placeholder="09:00" style="width: 25%;">
                            <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][opening_hours][<?php echo $oh_index; ?>][close]" value="<?php echo esc_attr($hours['close']); ?>" placeholder="17:00" style="width: 25%;">
                            <button type="button" class="button remove-item">Remove</button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <button type="button" class="button add-opening-hours" data-index="<?php echo $index; ?>" style="margin-top: 10px;">Add Opening Hours</button>
        </div>
        <?php
    }

    /**
     * Render HowTo schema fields
     */
    private function render_howto_fields($fields, $index = 0)
    {
        $total_time = isset($fields['total_time']) ? $fields['total_time'] : '';
        $estimated_cost = isset($fields['estimated_cost']) ? $fields['estimated_cost'] : '';
        $supplies = isset($fields['supplies']) ? $fields['supplies'] : [];
        $tools = isset($fields['tools']) ? $fields['tools'] : [];
        $steps = isset($fields['steps']) ? $fields['steps'] : [['instructions' => '', 'image' => '']];

        ?>
        <div class="schema-field-group">
            <?php $this->render_override_fields_section($fields, $index, 'howto', true); ?>

            <h4>How-To Information</h4>
            <p class="description" style="margin-top: 0;">Fill in the how-to details below. At least one step with instructions is required.</p>

            <div class="schema-field">
                <label for="howto_total_time_<?php echo $index; ?>">Total Time (minutes):</label>
                <input type="number" step="any" min="0" name="schema_markup[types][<?php echo $index; ?>][fields][total_time]" id="howto_total_time_<?php echo $index; ?>" value="<?php echo esc_attr($total_time); ?>" placeholder="30" class="recipe-time-input">
            </div>

            <div class="schema-field">
                <label for="howto_estimated_cost_<?php echo $index; ?>">Estimated Cost:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][estimated_cost]" id="howto_estimated_cost_<?php echo $index; ?>" value="<?php echo esc_attr($estimated_cost); ?>" placeholder="e.g., 20.00 USD">
            </div>

            <div class="schema-field">
                <label>Supplies:</label>
                <div class="supplies-list">
                    <?php foreach ($supplies as $supply): ?>
                    <div class="supply-item">
                        <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][supplies][]" value="<?php echo esc_attr($supply); ?>" placeholder="Enter supply">
                        <button type="button" class="remove-item">Remove</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button add-supply" data-index="<?php echo $index; ?>" style="margin-top: 5px;">Add Supply</button>
            </div>

            <div class="schema-field">
                <label>Tools:</label>
                <div class="tools-list">
                    <?php foreach ($tools as $tool): ?>
                    <div class="tool-item">
                        <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][tools][]" value="<?php echo esc_attr($tool); ?>" placeholder="Enter tool">
                        <button type="button" class="remove-item">Remove</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button add-tool" data-index="<?php echo $index; ?>" style="margin-top: 5px;">Add Tool</button>
            </div>

            <h4>Steps</h4>
            <p class="description">Add at least one step with instructions. Fields marked with * are required.</p>

            <div class="howto-steps-list">
                <?php foreach ($steps as $step_index => $step): ?>
                    <div class="howto-step-item" style="margin: 15px 0; padding: 15px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                        <div class="schema-field">
                            <label>Step <?php echo $step_index + 1; ?> Instructions: <span style="color: #dc3232;">*</span></label>
                            <textarea name="schema_markup[types][<?php echo $index; ?>][fields][steps][<?php echo $step_index; ?>][instructions]" placeholder="Enter step instructions" style="width: 100%; height: 80px;"><?php echo esc_textarea(isset($step['instructions']) ? $step['instructions'] : ''); ?></textarea>
                        </div>
                        <div class="schema-field">
                            <label>Step <?php echo $step_index + 1; ?> Image (optional):</label>
                            <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][steps][<?php echo $step_index; ?>][image]" value="<?php echo esc_attr(isset($step['image']) ? $step['image'] : ''); ?>" placeholder="https://example.com/step-image.jpg" style="width: 100%;">
                        </div>
                        <button type="button" class="button remove-howto-step" style="background: #dc3232; color: white; border-color: #dc3232;">Remove Step</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button add-howto-step" data-index="<?php echo $index; ?>" style="margin-top: 10px;">Add Step</button>
        </div>
        <?php
    }

    /**
     * Render VideoObject schema fields
     */
    private function render_video_object_fields($fields, $index = 0)
    {
        $video_name = isset($fields['video_name']) ? $fields['video_name'] : '';
        $video_description = isset($fields['video_description']) ? $fields['video_description'] : '';
        $thumbnail_url = isset($fields['thumbnail_url']) ? $fields['thumbnail_url'] : '';
        $upload_date = isset($fields['upload_date']) ? $fields['upload_date'] : '';
        $content_url = isset($fields['content_url']) ? $fields['content_url'] : '';
        $embed_url = isset($fields['embed_url']) ? $fields['embed_url'] : '';
        $duration = isset($fields['duration']) ? $fields['duration'] : '';

        ?>
        <div class="schema-field-group">
            <h4>Video Information</h4>
            <p class="description" style="margin-top: 0;">Fill in the video details below. Fields marked with * are required.</p>

            <div class="schema-field">
                <label for="video_name_<?php echo $index; ?>">Video Name: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][video_name]" id="video_name_<?php echo $index; ?>" value="<?php echo esc_attr($video_name); ?>" placeholder="e.g., How to Bake a Cake" style="width: 100%;">
            </div>

            <div class="schema-field">
                <label for="video_description_<?php echo $index; ?>">Video Description: <span style="color: #dc3232;">*</span></label>
                <textarea name="schema_markup[types][<?php echo $index; ?>][fields][video_description]" id="video_description_<?php echo $index; ?>" placeholder="Brief description of the video" style="width: 100%; height: 80px;"><?php echo esc_textarea($video_description); ?></textarea>
            </div>

            <div class="schema-field">
                <label for="video_thumbnail_url_<?php echo $index; ?>">Thumbnail URL: <span style="color: #dc3232;">*</span></label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][thumbnail_url]" id="video_thumbnail_url_<?php echo $index; ?>" value="<?php echo esc_attr($thumbnail_url); ?>" placeholder="https://example.com/thumbnail.jpg" style="width: 100%;">
            </div>

            <div class="schema-field">
                <label for="video_upload_date_<?php echo $index; ?>">Upload Date: <span style="color: #dc3232;">*</span></label>
                <input type="date" name="schema_markup[types][<?php echo $index; ?>][fields][upload_date]" id="video_upload_date_<?php echo $index; ?>" value="<?php echo esc_attr($upload_date); ?>">
            </div>

            <div class="schema-field">
                <label for="video_content_url_<?php echo $index; ?>">Content URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][content_url]" id="video_content_url_<?php echo $index; ?>" value="<?php echo esc_attr($content_url); ?>" placeholder="https://example.com/video.mp4" style="width: 100%;">
                <p class="description">Direct URL to the video file.</p>
            </div>

            <div class="schema-field">
                <label for="video_embed_url_<?php echo $index; ?>">Embed URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][embed_url]" id="video_embed_url_<?php echo $index; ?>" value="<?php echo esc_attr($embed_url); ?>" placeholder="https://www.youtube.com/embed/xxxxx" style="width: 100%;">
                <p class="description">URL for embedding the video (e.g., YouTube embed URL).</p>
            </div>

            <div class="schema-field">
                <label for="video_duration_<?php echo $index; ?>">Duration (minutes):</label>
                <input type="number" step="any" min="0" name="schema_markup[types][<?php echo $index; ?>][fields][duration]" id="video_duration_<?php echo $index; ?>" value="<?php echo esc_attr($duration); ?>" placeholder="10" class="recipe-time-input">
            </div>
        </div>
        <?php
    }

    /**
     * Save schema markup data
     */
    public function save_schema_markup_data($post_id)
    {
        // Check if user has permission to edit the post
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Verify nonce
        if (!isset($_POST['metasync_schema_markup_nonce']) || 
            !wp_verify_nonce($_POST['metasync_schema_markup_nonce'], 'metasync_schema_markup_nonce')) {
            return;
        }

        // Check if this is an autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Get existing schema data to preserve if needed
        $existing_schema_data = get_post_meta($post_id, 'metasync_schema_markup', true);
        
        // Sanitize and save data
        $schema_data = [];
        
        if (isset($_POST['schema_markup'])) {
            $post_data = $this->common->sanitize_array($_POST['schema_markup']);
            
            $schema_data['enabled'] = isset($post_data['enabled']) ? (bool)$post_data['enabled'] : false;
            $schema_data['types'] = [];
            
            // Process multiple schema types and ensure uniqueness
            if (isset($post_data['types']) && is_array($post_data['types'])) {
                $seen_types = [];
                foreach ($post_data['types'] as $type_data) {
                    if (!empty($type_data['type'])) {
                        $schema_type = sanitize_text_field($type_data['type']);
                        
                        // Skip if this schema type already exists (keep first occurrence)
                        if (in_array($schema_type, $seen_types)) {
                            continue;
                        }
                        
                        $schema_fields = isset($type_data['fields']) ? $this->sanitize_schema_fields($type_data['fields'], $schema_type) : [];
                        
                        $schema_data['types'][] = [
                            'type' => $schema_type,
                            'fields' => $schema_fields
                        ];
                        
                        // Mark this type as seen
                        $seen_types[] = $schema_type;
                    }
                }
            }
        }

        // If checkbox is unchecked, preserve existing schema data but mark as disabled
        if (empty($schema_data['enabled'])) {
            if (!empty($existing_schema_data) && !empty($existing_schema_data['types'])) {
                // Preserve existing schema types but mark as disabled
                $schema_data['enabled'] = false;
                $schema_data['types'] = $existing_schema_data['types'];
            }
        }

        // Delete any existing validation errors
        delete_post_meta($post_id, '_metasync_schema_validation_errors');

        // Save the meta (only validate if enabled)
        if (!empty($schema_data['enabled']) && !empty($schema_data['types'])) {
            // Validate schema requirements for all types
            $all_validation_errors = [];
            
            foreach ($schema_data['types'] as $schema_type_data) {
                $validation_errors = $this->validate_schema_requirements($post_id, $schema_type_data['type'], $schema_type_data['fields']);
                if (!empty($validation_errors)) {
                    $all_validation_errors = array_merge($all_validation_errors, $validation_errors);
                }
            }
            
            if (!empty($all_validation_errors)) {
                // Store validation errors as post meta
                update_post_meta($post_id, '_metasync_schema_validation_errors', $all_validation_errors);
                
                // Set a transient to show admin notice immediately after save
                set_transient('metasync_schema_validation_error_' . $post_id, $all_validation_errors, 45);
            }
        }
        
        // Save the schema data
        update_post_meta($post_id, 'metasync_schema_markup', $schema_data);
    }

    /**
     * Validate schema requirements based on type
     * 
     * @param int $post_id The post ID
     * @param string $type Schema type
     * @param array $fields Schema fields
     * @return array Array of validation errors (empty if valid)
     */
    private function validate_schema_requirements($post_id, $type, $fields)
    {
        $errors = [];

        switch ($type) {
            case 'article':
                $errors = $this->validate_article_schema($post_id, $fields);
                break;
            // Add more schema type validations here in the future
            case 'product':
                $errors = $this->validate_product_schema($post_id, $fields);
                break;
            case 'recipe':
                $errors = $this->validate_recipe_schema($post_id, $fields);
                break;
            case 'FAQPage':
                $errors = $this->validate_faq_schema($post_id, $fields);
                break;
            case 'Event':
                $errors = $this->validate_event_schema($post_id, $fields);
                break;
            case 'JobPosting':
                $errors = $this->validate_jobposting_schema($post_id, $fields);
                break;
            case 'Review':
                $errors = $this->validate_review_schema($post_id, $fields);
                break;
            case 'Course':
                $errors = $this->validate_course_schema($post_id, $fields);
                break;
            case 'Organization':
                $errors = $this->validate_organization_schema($post_id, $fields);
                break;
            case 'Person':
                $errors = $this->validate_person_schema($post_id, $fields);
                break;
            case 'WebSite':
                $errors = $this->validate_website_schema($post_id, $fields);
                break;
            case 'NewsArticle':
                $errors = $this->validate_newsarticle_schema($post_id, $fields);
                break;
            case 'LocalBusiness':
                $errors = $this->validate_local_business_schema($post_id, $fields);
                break;
            case 'HowTo':
                $errors = $this->validate_howto_schema($post_id, $fields);
                break;
            case 'VideoObject':
                $errors = $this->validate_video_object_schema($post_id, $fields);
                break;
        }

        return $errors;
    }

    /**
     * Validate Article schema requirements
     * Article requires: Headline, Description, and Image
     * 
     * @param int $post_id The post ID
     * @param array $fields Schema fields
     * @return array Array of validation errors
     */
    private function validate_article_schema($post_id, $fields)
    {
        $errors = [];

        // Get effective values (override if provided, otherwise defaults)
        $effective_title = $this->get_effective_schema_value('title_override', $fields, $post_id);
        $effective_description = $this->get_effective_schema_value('description_override', $fields, $post_id);
        $effective_image = $this->get_effective_schema_value('image_override', $fields, $post_id);

        // 1. Validate Headline (Title)
        if (empty($effective_title) || trim($effective_title) === '') {
            $errors[] = [
                'field' => 'headline',
                'message' => 'Article schema requires a <strong>Headline</strong>. Please add a post title or override it in the Override Default Fields section.'
            ];
        }

        // 2. Validate Description
        if (empty($effective_description) || trim($effective_description) === '') {
            $errors[] = [
                'field' => 'description',
                'message' => 'Article schema requires a <strong>Description</strong>. Please add post content, an excerpt, or override it in the Override Default Fields section.'
            ];
        }

        // 3. Validate Image
        if (empty($effective_image)) {
            $errors[] = [
                'field' => 'image',
                'message' => 'Article schema requires an <strong>Image</strong>. Please set a featured image for this post or override it in the Override Default Fields section.'
            ];
        }

        return $errors;
    }

    /**
     * Validate Product schema requirements
     * Product requires: Name, Description, Image, and Price
     * 
     * @param int $post_id The post ID
     * @param array $fields Schema fields
     * @return array Array of validation errors
     */
    private function validate_product_schema($post_id, $fields)
    {
        $errors = [];

        // Get effective values (override if provided, otherwise defaults)
        $effective_title = $this->get_effective_schema_value('title_override', $fields, $post_id);
        $effective_description = $this->get_effective_schema_value('description_override', $fields, $post_id);
        $effective_image = $this->get_effective_schema_value('image_override', $fields, $post_id);

        // 1. Validate Name (Title)
        if (empty($effective_title) || trim($effective_title) === '') {
            $errors[] = [
                'field' => 'name',
                'message' => 'Product schema requires a <strong>Name</strong>. Please add a post title or override it in the Override Default Fields section.'
            ];
        }

        // 2. Validate Description
        if (empty($effective_description) || trim($effective_description) === '') {
            $errors[] = [
                'field' => 'description',
                'message' => 'Product schema requires a <strong>Description</strong>. Please add post content, an excerpt, or override it in the Override Default Fields section.'
            ];
        }

        // 3. Validate Image
        if (empty($effective_image)) {
            $errors[] = [
                'field' => 'image',
                'message' => 'Product schema requires an <strong>Image</strong>. Please set a featured image for this post or override it in the Override Default Fields section.'
            ];
        }

        // 4. Validate Price
        if (empty($fields['price']) || floatval($fields['price']) <= 0) {
            $errors[] = [
                'field' => 'price',
                'message' => 'Product schema requires a valid <strong>Price</strong>. Please enter a price greater than 0.'
            ];
        }

        return $errors;
    }

    /**
     * Validate Recipe schema requirements
     * Recipe requires: Name, Description, and Image
     * 
     * @param int $post_id The post ID
     * @param array $fields Schema fields
     * @return array Array of validation errors
     */
    private function validate_recipe_schema($post_id, $fields)
    {
        $errors = [];

        // Get effective values (override if provided, otherwise defaults)
        $effective_title = $this->get_effective_schema_value('title_override', $fields, $post_id);
        $effective_description = $this->get_effective_schema_value('description_override', $fields, $post_id);
        $effective_image = $this->get_effective_schema_value('image_override', $fields, $post_id);

        // 1. Validate Name (Title)
        if (empty($effective_title) || trim($effective_title) === '') {
            $errors[] = [
                'field' => 'name',
                'message' => 'Recipe schema requires a <strong>Name</strong>. Please add a post title or override it in the Override Default Fields section.'
            ];
        }

        // 2. Validate Description
        if (empty($effective_description) || trim($effective_description) === '') {
            $errors[] = [
                'field' => 'description',
                'message' => 'Recipe schema requires a <strong>Description</strong>. Please add post content, an excerpt, or override it in the Override Default Fields section.'
            ];
        }

        // 3. Validate Image
        if (empty($effective_image)) {
            $errors[] = [
                'field' => 'image',
                'message' => 'Recipe schema requires an <strong>Image</strong>. Please set a featured image for this post or override it in the Override Default Fields section.'
            ];
        }

        return $errors;
    }

    /**
     * Validate FAQ schema requirements
     * FAQ requires at least one Q&A pair with non-empty question and answer
     * 
     * @param int $post_id The post ID
     * @param array $fields Schema fields
     * @return array Array of validation errors
     */
    private function validate_faq_schema($post_id, $fields)
    {
        $errors = [];

        // Check if FAQ items exist
        if (empty($fields['faq_items']) || !is_array($fields['faq_items'])) {
            $errors[] = [
                'field' => 'faq_items',
                'message' => 'FAQ schema requires at least one <strong>Question & Answer</strong> pair. Please add at least one FAQ item.'
            ];
            return $errors;
        }

        // Validate each FAQ item
        $has_valid_item = false;
        foreach ($fields['faq_items'] as $index => $item) {
            $question = isset($item['question']) ? trim($item['question']) : '';
            $answer = isset($item['answer']) ? trim($item['answer']) : '';

            // Check if both question and answer are provided
            if (!empty($question) && !empty($answer)) {
                $has_valid_item = true;
            } elseif (!empty($question) || !empty($answer)) {
                // Partial entry - one is filled but not the other
                $item_number = $index + 1;
                if (empty($question)) {
                    $errors[] = [
                        'field' => 'faq_items',
                        'message' => "FAQ item #{$item_number}: <strong>Question</strong> cannot be left blank."
                    ];
                }
                if (empty($answer)) {
                    $errors[] = [
                        'field' => 'faq_items',
                        'message' => "FAQ item #{$item_number}: <strong>Answer</strong> cannot be left blank."
                    ];
                }
            }
        }

        // Check if at least one valid Q&A pair exists
        if (!$has_valid_item) {
            $errors[] = [
                'field' => 'faq_items',
                'message' => 'FAQ schema requires at least one complete <strong>Question & Answer</strong> pair. Please fill in both question and answer for at least one FAQ item.'
            ];
        }

        return $errors;
    }

    /**
     * Validate LocalBusiness schema requirements
     */
    private function validate_local_business_schema($post_id, $fields)
    {
        $errors = [];

        if (empty($fields['business_name']) || trim($fields['business_name']) === '') {
            $errors[] = [
                'field' => 'business_name',
                'message' => 'LocalBusiness schema requires a <strong>Business Name</strong>.'
            ];
        }

        if (empty($fields['street_address']) || trim($fields['street_address']) === '') {
            $errors[] = [
                'field' => 'street_address',
                'message' => 'LocalBusiness schema requires a <strong>Street Address</strong>.'
            ];
        }

        return $errors;
    }

    /**
     * Validate HowTo schema requirements
     */
    private function validate_howto_schema($post_id, $fields)
    {
        $errors = [];

        // Validate override fields (title, description, image)
        $effective_title = $this->get_effective_schema_value('title_override', $fields, $post_id);
        $effective_description = $this->get_effective_schema_value('description_override', $fields, $post_id);
        $effective_image = $this->get_effective_schema_value('image_override', $fields, $post_id);

        if (empty($effective_title) || trim($effective_title) === '') {
            $errors[] = [
                'field' => 'name',
                'message' => 'How-To schema requires a <strong>Name</strong>. Please add a post title or override it in the Override Default Fields section.'
            ];
        }

        if (empty($effective_description) || trim($effective_description) === '') {
            $errors[] = [
                'field' => 'description',
                'message' => 'How-To schema requires a <strong>Description</strong>. Please add post content, an excerpt, or override it in the Override Default Fields section.'
            ];
        }

        if (empty($effective_image)) {
            $errors[] = [
                'field' => 'image',
                'message' => 'How-To schema requires an <strong>Image</strong>. Please set a featured image for this post or override it in the Override Default Fields section.'
            ];
        }

        // Validate at least one step with non-empty instructions
        $has_valid_step = false;
        if (!empty($fields['steps']) && is_array($fields['steps'])) {
            foreach ($fields['steps'] as $step) {
                if (!empty($step['instructions']) && trim($step['instructions']) !== '') {
                    $has_valid_step = true;
                    break;
                }
            }
        }

        if (!$has_valid_step) {
            $errors[] = [
                'field' => 'steps',
                'message' => 'How-To schema requires at least one <strong>Step</strong> with instructions.'
            ];
        }

        return $errors;
    }

    /**
     * Validate VideoObject schema requirements
     */
    private function validate_video_object_schema($post_id, $fields)
    {
        $errors = [];

        if (empty($fields['video_name']) || trim($fields['video_name']) === '') {
            $errors[] = [
                'field' => 'video_name',
                'message' => 'VideoObject schema requires a <strong>Video Name</strong>.'
            ];
        }

        if (empty($fields['video_description']) || trim($fields['video_description']) === '') {
            $errors[] = [
                'field' => 'video_description',
                'message' => 'VideoObject schema requires a <strong>Video Description</strong>.'
            ];
        }

        if (empty($fields['thumbnail_url']) || trim($fields['thumbnail_url']) === '') {
            $errors[] = [
                'field' => 'thumbnail_url',
                'message' => 'VideoObject schema requires a <strong>Thumbnail URL</strong>.'
            ];
        }

        if (empty($fields['upload_date']) || trim($fields['upload_date']) === '') {
            $errors[] = [
                'field' => 'upload_date',
                'message' => 'VideoObject schema requires an <strong>Upload Date</strong>.'
            ];
        }

        return $errors;
    }

    /**
     * Display schema validation notices in admin
     */
    public function display_schema_validation_notices()
    {
        // Only show on post edit screens
        $screen = get_current_screen();
        if (!$screen || ($screen->base !== 'post' && $screen->base !== 'post-new')) {
            return;
        }

        // Get current post ID
        global $post;
        if (!$post || !isset($post->ID)) {
            return;
        }

        $post_id = $post->ID;

        // Check for validation errors from transient (immediately after save)
        $errors = get_transient('metasync_schema_validation_error_' . $post_id);
        
        // If no transient, check post meta (for persistent errors)
        if ($errors === false) {
            $errors = get_post_meta($post_id, '_metasync_schema_validation_errors', true);
        } else {
            // Delete the transient after displaying once
            delete_transient('metasync_schema_validation_error_' . $post_id);
        }

        if (empty($errors) || !is_array($errors)) {
            return;
        }

        // Get schema type for context
        $schema_data = get_post_meta($post_id, 'metasync_schema_markup', true);
        $schema_type = isset($schema_data['type']) ? ucfirst($schema_data['type']) : 'Schema';

        ?>
        <div class="notice notice-error is-dismissible metasync-schema-validation-notice">
            <h3 style="margin: 0.5em 0;">⚠️ <?php echo esc_html($schema_type); ?> Schema Validation Errors</h3>
            <p><strong>The following required fields are missing:</strong></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo wp_kses_post($error['message']); ?></li>
                <?php endforeach; ?>
            </ul>
            <p><em>Please fix these issues to ensure your schema markup is valid and can be properly indexed by search engines.</em></p>
        </div>
        <?php
    }

    /**
     * Sanitize schema fields based on type
     */
    private function sanitize_schema_fields($fields, $type)
    {
        $sanitized = [];

        // Sanitize override fields (common to all types that support them)
        // Store placeholders as-is in database, will be replaced during JSON generation
        $sanitized['title_override'] = isset($fields['title_override']) ? sanitize_text_field($fields['title_override']) : '';
        $sanitized['description_override'] = isset($fields['description_override']) ? sanitize_textarea_field($fields['description_override']) : '';
        
        // For image_override, check if it's a placeholder before sanitizing as URL
        $image_override_raw = isset($fields['image_override']) ? trim($fields['image_override']) : '';
        if ($this->is_placeholder($image_override_raw)) {
            // Store placeholder as-is
            $sanitized['image_override'] = $image_override_raw;
        } else {
            // Sanitize as URL only if it's not a placeholder
            $sanitized['image_override'] = !empty($image_override_raw) ? esc_url_raw($image_override_raw) : '';
        }

        switch ($type) {
            case 'article':
                $sanitized['organization_name'] = isset($fields['organization_name']) ? sanitize_text_field($fields['organization_name']) : '';
                $sanitized['organization_logo'] = isset($fields['organization_logo']) ? esc_url_raw($fields['organization_logo']) : '';
                break;
            case 'FAQPage':
                // FAQ doesn't use override fields, so remove them
                unset($sanitized['title_override'], $sanitized['description_override'], $sanitized['image_override']);
                $sanitized['faq_items'] = [];
                if (isset($fields['faq_items']) && is_array($fields['faq_items'])) {
                    foreach ($fields['faq_items'] as $item) {
                        if (!empty($item['question']) && !empty($item['answer'])) {
                            $sanitized['faq_items'][] = [
                                'question' => sanitize_text_field($item['question']),
                                'answer' => sanitize_textarea_field($item['answer'])
                            ];
                        }
                    }
                }
                break;
            case 'product':
                $sanitized['sku'] = isset($fields['sku']) ? sanitize_text_field($fields['sku']) : '';
                $sanitized['brand'] = isset($fields['brand']) ? sanitize_text_field($fields['brand']) : '';
                $sanitized['price'] = isset($fields['price']) ? floatval($fields['price']) : 0;
                $sanitized['currency'] = isset($fields['currency']) ? sanitize_text_field($fields['currency']) : 'USD';
                $sanitized['availability'] = isset($fields['availability']) ? sanitize_text_field($fields['availability']) : 'InStock';
                $sanitized['condition'] = isset($fields['condition']) ? sanitize_text_field($fields['condition']) : 'NewCondition';
                break;

            case 'recipe':
                $sanitized['yield'] = isset($fields['yield']) ? sanitize_text_field($fields['yield']) : '';
                $sanitized['ingredients'] = isset($fields['ingredients']) ? array_map('sanitize_text_field', array_filter($fields['ingredients'])) : [];
                $sanitized['instructions'] = isset($fields['instructions']) ? array_map('sanitize_textarea_field', array_filter($fields['instructions'])) : [];
                $sanitized['prep_time'] = isset($fields['prep_time']) ? intval($fields['prep_time']) : 0;
                $sanitized['cook_time'] = isset($fields['cook_time']) ? intval($fields['cook_time']) : 0;
                $sanitized['total_time'] = isset($fields['total_time']) ? intval($fields['total_time']) : 0;
                $sanitized['calories'] = isset($fields['calories']) ? intval($fields['calories']) : 0;
                break;

            case 'Event':
                unset($sanitized['title_override'], $sanitized['description_override'], $sanitized['image_override']);
                $sanitized['name'] = isset($fields['name']) ? sanitize_text_field($fields['name']) : '';
                $sanitized['startDate'] = isset($fields['startDate']) ? sanitize_text_field($fields['startDate']) : '';
                $sanitized['endDate'] = isset($fields['endDate']) ? sanitize_text_field($fields['endDate']) : '';
                $sanitized['eventStatus'] = isset($fields['eventStatus']) ? sanitize_text_field($fields['eventStatus']) : 'EventScheduled';
                $sanitized['eventAttendanceMode'] = isset($fields['eventAttendanceMode']) ? sanitize_text_field($fields['eventAttendanceMode']) : 'OfflineEventAttendanceMode';
                $sanitized['location_name'] = isset($fields['location_name']) ? sanitize_text_field($fields['location_name']) : '';
                $sanitized['location_address'] = isset($fields['location_address']) ? sanitize_text_field($fields['location_address']) : '';
                $sanitized['organizer_name'] = isset($fields['organizer_name']) ? sanitize_text_field($fields['organizer_name']) : '';
                $sanitized['organizer_url'] = isset($fields['organizer_url']) ? esc_url_raw($fields['organizer_url']) : '';
                $sanitized['offer_price'] = isset($fields['offer_price']) ? sanitize_text_field($fields['offer_price']) : '';
                $sanitized['offer_priceCurrency'] = isset($fields['offer_priceCurrency']) ? sanitize_text_field($fields['offer_priceCurrency']) : 'USD';
                $sanitized['offer_availability'] = isset($fields['offer_availability']) ? sanitize_text_field($fields['offer_availability']) : 'InStock';
                $sanitized['offer_url'] = isset($fields['offer_url']) ? esc_url_raw($fields['offer_url']) : '';
                $sanitized['image'] = isset($fields['image']) ? esc_url_raw($fields['image']) : '';
                $sanitized['description'] = isset($fields['description']) ? sanitize_textarea_field($fields['description']) : '';
                break;

            case 'JobPosting':
                unset($sanitized['title_override'], $sanitized['description_override'], $sanitized['image_override']);
                $sanitized['title'] = isset($fields['title']) ? sanitize_text_field($fields['title']) : '';
                $sanitized['description'] = isset($fields['description']) ? sanitize_textarea_field($fields['description']) : '';
                $sanitized['datePosted'] = isset($fields['datePosted']) ? sanitize_text_field($fields['datePosted']) : '';
                $sanitized['validThrough'] = isset($fields['validThrough']) ? sanitize_text_field($fields['validThrough']) : '';
                $sanitized['employmentType'] = isset($fields['employmentType']) ? sanitize_text_field($fields['employmentType']) : 'FULL_TIME';
                $sanitized['hiringOrganization_name'] = isset($fields['hiringOrganization_name']) ? sanitize_text_field($fields['hiringOrganization_name']) : '';
                $sanitized['hiringOrganization_sameAs'] = isset($fields['hiringOrganization_sameAs']) ? esc_url_raw($fields['hiringOrganization_sameAs']) : '';
                $sanitized['hiringOrganization_logo'] = isset($fields['hiringOrganization_logo']) ? esc_url_raw($fields['hiringOrganization_logo']) : '';
                $sanitized['jobLocation_address'] = isset($fields['jobLocation_address']) ? sanitize_text_field($fields['jobLocation_address']) : '';
                $sanitized['baseSalary_currency'] = isset($fields['baseSalary_currency']) ? sanitize_text_field($fields['baseSalary_currency']) : '';
                $sanitized['baseSalary_value'] = isset($fields['baseSalary_value']) ? floatval($fields['baseSalary_value']) : 0;
                $sanitized['baseSalary_unitText'] = isset($fields['baseSalary_unitText']) ? sanitize_text_field($fields['baseSalary_unitText']) : 'YEAR';
                break;

            case 'Review':
                unset($sanitized['title_override'], $sanitized['description_override'], $sanitized['image_override']);
                $sanitized['itemReviewed_name'] = isset($fields['itemReviewed_name']) ? sanitize_text_field($fields['itemReviewed_name']) : '';
                $sanitized['reviewRating_ratingValue'] = isset($fields['reviewRating_ratingValue']) ? floatval($fields['reviewRating_ratingValue']) : 0;
                $sanitized['reviewRating_bestRating'] = isset($fields['reviewRating_bestRating']) ? floatval($fields['reviewRating_bestRating']) : 5;
                $sanitized['author_name'] = isset($fields['author_name']) ? sanitize_text_field($fields['author_name']) : '';
                $sanitized['reviewBody'] = isset($fields['reviewBody']) ? sanitize_textarea_field($fields['reviewBody']) : '';
                break;

            case 'Course':
                unset($sanitized['title_override'], $sanitized['description_override'], $sanitized['image_override']);
                $sanitized['name'] = isset($fields['name']) ? sanitize_text_field($fields['name']) : '';
                $sanitized['description'] = isset($fields['description']) ? sanitize_textarea_field($fields['description']) : '';
                $sanitized['provider_name'] = isset($fields['provider_name']) ? sanitize_text_field($fields['provider_name']) : '';
                $sanitized['provider_sameAs'] = isset($fields['provider_sameAs']) ? esc_url_raw($fields['provider_sameAs']) : '';
                $sanitized['courseInstance_courseMode'] = isset($fields['courseInstance_courseMode']) ? sanitize_text_field($fields['courseInstance_courseMode']) : 'online';
                $sanitized['courseInstance_instructor_name'] = isset($fields['courseInstance_instructor_name']) ? sanitize_text_field($fields['courseInstance_instructor_name']) : '';
                break;

            case 'Organization':
                unset($sanitized['title_override'], $sanitized['description_override'], $sanitized['image_override']);
                $sanitized['name'] = isset($fields['name']) ? sanitize_text_field($fields['name']) : '';
                $sanitized['url'] = isset($fields['url']) ? esc_url_raw($fields['url']) : '';
                $sanitized['logo'] = isset($fields['logo']) ? esc_url_raw($fields['logo']) : '';
                $sanitized['contactPoint_telephone'] = isset($fields['contactPoint_telephone']) ? sanitize_text_field($fields['contactPoint_telephone']) : '';
                $sanitized['contactPoint_contactType'] = isset($fields['contactPoint_contactType']) ? sanitize_text_field($fields['contactPoint_contactType']) : '';
                $sanitized['sameAs'] = isset($fields['sameAs']) ? sanitize_textarea_field($fields['sameAs']) : '';
                break;

            case 'Person':
                unset($sanitized['title_override'], $sanitized['description_override'], $sanitized['image_override']);
                $sanitized['name'] = isset($fields['name']) ? sanitize_text_field($fields['name']) : '';
                $sanitized['url'] = isset($fields['url']) ? esc_url_raw($fields['url']) : '';
                $sanitized['jobTitle'] = isset($fields['jobTitle']) ? sanitize_text_field($fields['jobTitle']) : '';
                $sanitized['worksFor'] = isset($fields['worksFor']) ? sanitize_text_field($fields['worksFor']) : '';
                $sanitized['email'] = isset($fields['email']) ? sanitize_email($fields['email']) : '';
                $sanitized['image'] = isset($fields['image']) ? esc_url_raw($fields['image']) : '';
                $sanitized['sameAs'] = isset($fields['sameAs']) ? sanitize_textarea_field($fields['sameAs']) : '';
                break;

            case 'WebSite':
                unset($sanitized['title_override'], $sanitized['description_override'], $sanitized['image_override']);
                $sanitized['name'] = isset($fields['name']) ? sanitize_text_field($fields['name']) : '';
                $sanitized['url'] = isset($fields['url']) ? esc_url_raw($fields['url']) : '';
                $sanitized['searchbox_enabled'] = isset($fields['searchbox_enabled']) ? (bool) $fields['searchbox_enabled'] : false;
                $sanitized['searchbox_query_input'] = isset($fields['searchbox_query_input']) ? sanitize_text_field($fields['searchbox_query_input']) : '';
                break;

            case 'NewsArticle':
                $sanitized['organization_name'] = isset($fields['organization_name']) ? sanitize_text_field($fields['organization_name']) : '';
                $sanitized['organization_logo'] = isset($fields['organization_logo']) ? esc_url_raw($fields['organization_logo']) : '';
                break;

            case 'LocalBusiness':
                // Self-managed type, remove override fields
                unset($sanitized['title_override'], $sanitized['description_override'], $sanitized['image_override']);
                $sanitized['business_name'] = isset($fields['business_name']) ? sanitize_text_field($fields['business_name']) : '';
                $sanitized['street_address'] = isset($fields['street_address']) ? sanitize_text_field($fields['street_address']) : '';
                $sanitized['description'] = isset($fields['description']) ? sanitize_textarea_field($fields['description']) : '';
                $sanitized['url'] = isset($fields['url']) ? esc_url_raw($fields['url']) : '';
                $sanitized['telephone'] = isset($fields['telephone']) ? sanitize_text_field($fields['telephone']) : '';
                $sanitized['price_range'] = isset($fields['price_range']) ? sanitize_text_field($fields['price_range']) : '';
                $sanitized['image'] = isset($fields['image']) ? esc_url_raw($fields['image']) : '';
                $sanitized['city'] = isset($fields['city']) ? sanitize_text_field($fields['city']) : '';
                $sanitized['state'] = isset($fields['state']) ? sanitize_text_field($fields['state']) : '';
                $sanitized['postal_code'] = isset($fields['postal_code']) ? sanitize_text_field($fields['postal_code']) : '';
                $sanitized['country'] = isset($fields['country']) ? sanitize_text_field($fields['country']) : '';
                $sanitized['latitude'] = isset($fields['latitude']) ? sanitize_text_field($fields['latitude']) : '';
                $sanitized['longitude'] = isset($fields['longitude']) ? sanitize_text_field($fields['longitude']) : '';
                $sanitized['opening_hours'] = [];
                if (isset($fields['opening_hours']) && is_array($fields['opening_hours'])) {
                    foreach ($fields['opening_hours'] as $hours) {
                        if (!empty($hours['day'])) {
                            $sanitized['opening_hours'][] = [
                                'day' => sanitize_text_field($hours['day']),
                                'open' => sanitize_text_field($hours['open']),
                                'close' => sanitize_text_field($hours['close']),
                            ];
                        }
                    }
                }
                break;

            case 'HowTo':
                $sanitized['total_time'] = isset($fields['total_time']) ? intval($fields['total_time']) : 0;
                $sanitized['estimated_cost'] = isset($fields['estimated_cost']) ? sanitize_text_field($fields['estimated_cost']) : '';
                $sanitized['supplies'] = isset($fields['supplies']) ? array_map('sanitize_text_field', array_filter($fields['supplies'])) : [];
                $sanitized['tools'] = isset($fields['tools']) ? array_map('sanitize_text_field', array_filter($fields['tools'])) : [];
                $sanitized['steps'] = [];
                if (isset($fields['steps']) && is_array($fields['steps'])) {
                    foreach ($fields['steps'] as $step) {
                        $sanitized['steps'][] = [
                            'instructions' => isset($step['instructions']) ? sanitize_textarea_field($step['instructions']) : '',
                            'image' => isset($step['image']) ? esc_url_raw($step['image']) : '',
                        ];
                    }
                }
                break;

            case 'VideoObject':
                // Self-managed type, remove override fields
                unset($sanitized['title_override'], $sanitized['description_override'], $sanitized['image_override']);
                $sanitized['video_name'] = isset($fields['video_name']) ? sanitize_text_field($fields['video_name']) : '';
                $sanitized['video_description'] = isset($fields['video_description']) ? sanitize_textarea_field($fields['video_description']) : '';
                $sanitized['thumbnail_url'] = isset($fields['thumbnail_url']) ? esc_url_raw($fields['thumbnail_url']) : '';
                $sanitized['upload_date'] = isset($fields['upload_date']) ? sanitize_text_field($fields['upload_date']) : '';
                $sanitized['content_url'] = isset($fields['content_url']) ? esc_url_raw($fields['content_url']) : '';
                $sanitized['embed_url'] = isset($fields['embed_url']) ? esc_url_raw($fields['embed_url']) : '';
                $sanitized['duration'] = isset($fields['duration']) ? intval($fields['duration']) : 0;
                break;
        }

        return $sanitized;
    }

    /**
     * Output schema markup to the frontend
     */
    public function output_schema_markup()
    {
        $all_json_ld = [];

        // ---- Global schema: WebSite on front page, Organization on all pages ----
        $metasync_options = get_option('metasync_options', []);
        $schema_settings = isset($metasync_options['schema']) ? $metasync_options['schema'] : [];

        // Organization schema (site-wide)
        if (!empty($schema_settings['org_name'])) {
            if ($this->should_output_schema_type('Organization')) {
                $org_node = ['@type' => 'Organization'];
                $org_node['name'] = $schema_settings['org_name'];
                if (!empty($schema_settings['org_url'])) {
                    $org_node['url'] = $schema_settings['org_url'];
                }
                if (!empty($schema_settings['org_logo'])) {
                    $org_node['logo'] = [
                        '@type' => 'ImageObject',
                        'url' => $schema_settings['org_logo'],
                    ];
                }
                if (!empty($schema_settings['org_contact_telephone']) || !empty($schema_settings['org_contact_type'])) {
                    $org_node['contactPoint'] = ['@type' => 'ContactPoint'];
                    if (!empty($schema_settings['org_contact_telephone'])) {
                        $org_node['contactPoint']['telephone'] = $schema_settings['org_contact_telephone'];
                    }
                    if (!empty($schema_settings['org_contact_type'])) {
                        $org_node['contactPoint']['contactType'] = $schema_settings['org_contact_type'];
                    }
                }
                if (!empty($schema_settings['org_same_as'])) {
                    $urls = array_filter(array_map('trim', explode("\n", $schema_settings['org_same_as'])));
                    if (!empty($urls)) {
                        $org_node['sameAs'] = $urls;
                    }
                }
                $all_json_ld[] = $org_node;
            }
        }

        // WebSite schema (front page only)
        if (is_front_page()) {
            if (!empty($schema_settings['website_name']) && $this->should_output_schema_type('WebSite')) {
                $ws_node = ['@type' => 'WebSite'];
                $ws_node['name'] = $schema_settings['website_name'];
                $ws_node['url'] = !empty($schema_settings['website_url']) ? $schema_settings['website_url'] : home_url('/');
                if (!empty($schema_settings['website_searchbox'])) {
                    $base_url = rtrim($ws_node['url'], '/');
                    $ws_node['potentialAction'] = [
                        '@type' => 'SearchAction',
                        'target' => [
                            '@type' => 'EntryPoint',
                            'urlTemplate' => $base_url . '/?s={search_term_string}',
                        ],
                        'query-input' => 'required name=search_term_string',
                    ];
                }
                $all_json_ld[] = $ws_node;
            }
        }

        // Person schema on author archive pages
        if (is_author()) {
            if ($this->should_output_schema_type('Person')) {
                $author = get_queried_object();
                if ($author && is_a($author, 'WP_User')) {
                    $person_node = [
                        '@type' => 'Person',
                        'name' => $author->display_name,
                        'url' => get_author_posts_url($author->ID),
                    ];
                    $user_desc = get_the_author_meta('description', $author->ID);
                    if (!empty($user_desc)) {
                        $person_node['description'] = $user_desc;
                    }
                    $all_json_ld[] = $person_node;
                }
            }
        }

        // ---- Per-post schema (singular pages only) ----
        if (is_singular()) {
            global $post;
            if ($post && isset($post->ID)) {
                $schema_data = get_post_meta($post->ID, 'metasync_schema_markup', true);

                $has_user_types  = !empty($schema_data['enabled']) && !empty($schema_data['types']);

                // Track which types the global schema already outputs
                $global_types_output = [];
                if (!empty($schema_settings['org_name'])) {
                    $global_types_output[] = 'Organization';
                }
                if (is_front_page() && !empty($schema_settings['website_name'])) {
                    $global_types_output[] = 'WebSite';
                }

                // Generate JSON-LD for user-entered schema types.
                if ($has_user_types) {
                    $validation_errors = get_post_meta($post->ID, '_metasync_schema_validation_errors', true);
                    if (empty($validation_errors) || !is_array($validation_errors)) {
                        foreach ($schema_data['types'] as $schema_type_data) {
                            // Skip per-post types that the global schema already covers
                            $type_key = $schema_type_data['type'];
                            if (in_array($type_key, $global_types_output, true)) {
                                continue;
                            }

                            // Skip BreadcrumbList if already injected by the breadcrumbs module.
                            if (
                                $type_key === 'BreadcrumbList'
                                && class_exists('Metasync_Breadcrumbs_Schema')
                                && Metasync_Breadcrumbs_Schema::$breadcrumb_list_injected
                            ) {
                                continue;
                            }

                            if ($this->should_output_schema_type($type_key)) {
                                $json_ld = $this->generate_json_ld($post, $schema_type_data);
                                if ($json_ld) {
                                    $all_json_ld[] = $json_ld;
                                }
                            }
                        }
                    }
                }

                // OTTO-persisted JSON-LD is intentionally NOT merged here.
                // OTTO schema is delivered live via `header_html_insertion` and injected by
                // Otto_html_class with a `data-otto="true"` marker. Merging the DB-persisted
                // copy into this @graph produced duplicate JSON-LD on every page (WP-168)
                // and also exposed a Unicode-corruption bug from the update_post_meta round-trip.
                // API-delivered wins — same pattern used for <title>/og:*/twitter:* tags.
            }
        }

        // Output all schemas in a single script tag with @graph pattern
        if (!empty($all_json_ld)) {
            $final_json_ld = [
                '@context' => 'https://schema.org',
                '@graph'   => $all_json_ld,
            ];

            echo '<script type="application/ld+json" class="metasync-schema">' . "\n";
            echo wp_json_encode($final_json_ld, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            echo "\n" . '</script>' . "\n";
        }
    }

    /**
     * Replace placeholders with actual dynamic values
     * 
     * @param string $value Value that may contain placeholders
     * @param int $post_id Post ID
     * @return string Value with placeholders replaced
     */
    private function replace_placeholders($value, $post_id)
    {
        if (empty($value)) {
            return $value;
        }

        $defaults = $this->get_default_schema_values($post_id);
        
        // Replace placeholders with actual values
        $value = str_replace('{{post_title}}', $defaults['title'], $value);
        $value = str_replace('{{post_description}}', $defaults['description'], $value);
        $value = str_replace('{{featured_image}}', $defaults['image'], $value);
        
        return $value;
    }

    /**
     * Check if a value is a placeholder (should be treated as empty/default)
     * 
     * @param string $value Value to check
     * @return bool True if value is a placeholder
     */
    private function is_placeholder($value)
    {
        $placeholders = ['{{post_title}}', '{{post_description}}', '{{featured_image}}'];
        return in_array(trim($value), $placeholders, true);
    }

    /**
     * Get effective schema value (override if provided, otherwise default)
     * Replaces placeholders with actual dynamic values during JSON generation
     * 
     * @param string $field_name Field name (title_override, description_override, image_override)
     * @param array $fields Schema fields array
     * @param int $post_id Post ID
     * @return string Effective value with placeholders replaced
     */
    private function get_effective_schema_value($field_name, $fields, $post_id)
    {
        // Get override value from database
        $override_key = $field_name;
        $override_value = isset($fields[$override_key]) ? trim($fields[$override_key]) : '';
        
        // If override value is empty (blank field), return empty
        // This will trigger validation error - user must either fill it or use placeholder
        if (empty($override_value)) {
            return '';
        }
        
        // If override value is provided (including placeholders), replace placeholders with actual data
        return $this->replace_placeholders($override_value, $post_id);
    }

    /**
     * Generate JSON-LD based on schema type
     */
    private function generate_json_ld($post, $schema_type_data)
    {
        $type = $schema_type_data['type'];
        $fields = $schema_type_data['fields'];

        // Build base JSON-LD without @context (will be added at root level)
        // Map type keys to proper Schema.org @type values
        $type_map = [
            'article' => 'Article',
            'FAQPage' => 'FAQPage',
            'product' => 'Product',
            'recipe' => 'Recipe',
            'Event' => 'Event',
            'JobPosting' => 'JobPosting',
            'Review' => 'Review',
            'Course' => 'Course',
            'Organization' => 'Organization',
            'Person' => 'Person',
            'WebSite' => 'WebSite',
            'NewsArticle' => 'NewsArticle',
            'LocalBusiness' => 'LocalBusiness',
            'HowTo' => 'HowTo',
            'VideoObject' => 'VideoObject',
        ];
        $json_ld = [
            '@type' => isset($type_map[$type]) ? $type_map[$type] : ucfirst($type)
        ];

        // Types that manage their own fields (no override fields pattern)
        $self_managed_types = ['Event', 'JobPosting', 'Review', 'Course', 'Organization', 'Person', 'WebSite', 'LocalBusiness', 'VideoObject'];

        if (!in_array($type, $self_managed_types)) {
            // Get effective values (override if provided, otherwise defaults)
            $effective_title = $this->get_effective_schema_value('title_override', $fields, $post->ID);
            $effective_description = $this->get_effective_schema_value('description_override', $fields, $post->ID);
            $effective_image = $this->get_effective_schema_value('image_override', $fields, $post->ID);

            // For articles/NewsArticle, don't add 'name' and 'url' as we'll use 'headline' and 'mainEntityOfPage' instead
            // For FAQ, we only need the schema type and mainEntity
            if ($type !== 'article' && $type !== 'FAQPage' && $type !== 'NewsArticle') {
                $json_ld['name'] = $effective_title;
                $json_ld['url'] = get_permalink($post->ID);
                $json_ld['description'] = $effective_description;

                // Add images - use override if provided, otherwise get from featured image
                if (!empty($effective_image)) {
                    $json_ld['image'] = $effective_image;
                } else {
                    $images = $this->get_post_images($post->ID);
                    if (!empty($images)) {
                        $json_ld['image'] = count($images) === 1 ? $images[0] : $images;
                    }
                }
            } elseif ($type === 'article' || $type === 'NewsArticle') {
                $json_ld['description'] = $effective_description;

                // Add images - use override if provided, otherwise get from featured image
                if (!empty($effective_image)) {
                    $json_ld['image'] = $effective_image;
                } else {
                    $images = $this->get_post_images($post->ID);
                    if (!empty($images)) {
                        $json_ld['image'] = count($images) === 1 ? $images[0] : $images;
                    }
                }
            }
        }

        switch ($type) {
            case 'article':
                $json_ld = array_merge($json_ld, $this->generate_article_json_ld($fields, $post));
                break;
            case 'FAQPage':
                $json_ld = array_merge($json_ld, $this->generate_faq_json_ld($fields));
                break;
            case 'product':
                $json_ld = array_merge($json_ld, $this->generate_product_json_ld($fields));
                break;
            case 'recipe':
                $json_ld = array_merge($json_ld, $this->generate_recipe_json_ld($fields));
                break;
            case 'Event':
                $json_ld = array_merge($json_ld, $this->generate_event_json_ld($fields, $post));
                break;
            case 'JobPosting':
                $json_ld = array_merge($json_ld, $this->generate_jobposting_json_ld($fields, $post));
                break;
            case 'Review':
                $json_ld = array_merge($json_ld, $this->generate_review_json_ld($fields, $post));
                break;
            case 'Course':
                $json_ld = array_merge($json_ld, $this->generate_course_json_ld($fields, $post));
                break;
            case 'Organization':
                $json_ld = array_merge($json_ld, $this->generate_organization_json_ld($fields, $post));
                break;
            case 'Person':
                $json_ld = array_merge($json_ld, $this->generate_person_json_ld($fields, $post));
                break;
            case 'WebSite':
                $json_ld = array_merge($json_ld, $this->generate_website_json_ld($fields, $post));
                break;
            case 'NewsArticle':
                $json_ld = array_merge($json_ld, $this->generate_newsarticle_json_ld($fields, $post));
                break;
            case 'LocalBusiness':
                $local_business_data = $this->generate_local_business_json_ld($fields);
                if ($local_business_data === false) {
                    return null;
                }
                $json_ld = array_merge($json_ld, $local_business_data);
                break;
            case 'HowTo':
                $json_ld = array_merge($json_ld, $this->generate_howto_json_ld($fields));
                break;
            case 'VideoObject':
                $json_ld = array_merge($json_ld, $this->generate_video_object_json_ld($fields));
                break;
        }

        return $json_ld;
    }

    /**
     * Generate Article JSON-LD
     */
    private function generate_article_json_ld($fields, $post)
    {
        $article_data = [];

        // Add headline (required by Google) - use override if provided
        $effective_title = $this->get_effective_schema_value('title_override', $fields, $post->ID);
        $article_data['headline'] = $effective_title;

        // Add mainEntityOfPage instead of url (Google's standard for articles)
        $article_data['mainEntityOfPage'] = [
            '@type' => 'WebPage',
            '@id' => get_permalink($post->ID)
        ];

        // Add datePublished (required by Google)
        $article_data['datePublished'] = get_the_date('c', $post->ID);

        // Add dateModified (required by Google)
        $article_data['dateModified'] = get_the_modified_date('c', $post->ID);

        // Add author information
        if (!empty($fields['organization_name'])) {
            // Organization as author
            $article_data['author'] = [
                '@type' => 'Organization',
                'name' => $fields['organization_name']
            ];

            // Add organization logo if provided
            if (!empty($fields['organization_logo'])) {
                $article_data['author']['logo'] = [
                    '@type' => 'ImageObject',
                    'url' => $fields['organization_logo']
                ];
            }
        } else {
            // Fallback to WordPress post author
            $author_id = $post->post_author;
            $author_name = get_the_author_meta('display_name', $author_id);
            $author_url = get_author_posts_url($author_id);
            
            $article_data['author'] = [
                '@type' => 'Person',
                'name' => $author_name,
                'url' => $author_url
            ];
        }

        // Add publisher information (required by Google)
        if (!empty($fields['organization_name'])) {
            $article_data['publisher'] = [
                '@type' => 'Organization',
                'name' => $fields['organization_name']
            ];

            // Add organization logo if provided (required for publisher)
            if (!empty($fields['organization_logo'])) {
                $article_data['publisher']['logo'] = [
                    '@type' => 'ImageObject',
                    'url' => $fields['organization_logo']
                ];
            }
        }

        return $article_data;
    }

    /**
     * Generate FAQ JSON-LD
     */
    private function generate_faq_json_ld($fields)
    {
        $faq_data = [];

        // Add FAQ mainEntity
        if (!empty($fields['faq_items'])) {
            $faq_data['mainEntity'] = [];
            
            foreach ($fields['faq_items'] as $item) {
                $faq_data['mainEntity'][] = [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer']
                    ]
                ];
            }
        }

        return $faq_data;
    }

    /**
     * Generate Product JSON-LD
     */
    private function generate_product_json_ld($fields)
    {
        $metasync_options = get_option('metasync_options', []);
        $override_wc = !empty($metasync_options['schema']['override_woocommerce_schema']);
        $wc_active = class_exists('WooCommerce');

        // When WooCommerce is active and override is OFF, suppress MetaSync Product
        // schema entirely — let WooCommerce handle its own Product structured data.
        if ($wc_active && !$override_wc) {
            return [];
        }

        $product_data = [];

        if (!empty($fields['sku'])) {
            $product_data['sku'] = $fields['sku'];
        }

        if (!empty($fields['brand'])) {
            $product_data['brand'] = [
                '@type' => 'Brand',
                'name' => $fields['brand']
            ];
        }

        if (!empty($fields['price'])) {
            $product_data['offers'] = [
                '@type' => 'Offer',
                'price' => $fields['price'],
                'priceCurrency' => $fields['currency'] ?? 'USD',
                'availability' => 'https://schema.org/' . ($fields['availability'] ?? 'InStock'),
                'itemCondition' => 'https://schema.org/' . ($fields['condition'] ?? 'NewCondition')
            ];
        }

        // When WooCommerce is active AND override is ON, enrich with WC product data
        if ($wc_active && $override_wc && function_exists('wc_get_product')) {
            global $post;
            $wc_post_id = isset($post->ID) ? $post->ID : 0;
            $product = wc_get_product($wc_post_id);
            if ($product && is_a($product, 'WC_Product')) {
                // Auto-fill price from WC (overwrites manual fields with live data)
                $wc_price = $product->get_price();
                if (!empty($wc_price)) {
                    if (!isset($product_data['offers'])) {
                        $product_data['offers'] = ['@type' => 'Offer'];
                    }
                    $product_data['offers']['price'] = $wc_price;
                    $product_data['offers']['priceCurrency'] = get_woocommerce_currency();
                    $product_data['offers']['availability'] = 'https://schema.org/' . ($product->is_in_stock() ? 'InStock' : 'OutOfStock');
                }

                // Auto-fill SKU
                $wc_sku = $product->get_sku();
                if (!empty($wc_sku)) {
                    $product_data['sku'] = $wc_sku;
                }

                // AggregateRating from WooCommerce reviews
                $review_count = $product->get_review_count();
                if ($review_count > 0) {
                    $product_data['aggregateRating'] = [
                        '@type' => 'AggregateRating',
                        'ratingValue' => $product->get_average_rating(),
                        'reviewCount' => $review_count,
                    ];
                }
            }
        }

        return $product_data;
    }

    /**
     * Generate Recipe JSON-LD
     */
    private function generate_recipe_json_ld($fields)
    {
        $recipe_data = [];

        if (!empty($fields['yield'])) {
            $recipe_data['recipeYield'] = $fields['yield'];
        }

        if (!empty($fields['ingredients'])) {
            $recipe_data['recipeIngredient'] = $fields['ingredients'];
        }

        if (!empty($fields['instructions'])) {
            $recipe_data['recipeInstructions'] = [];
            foreach ($fields['instructions'] as $instruction) {
                $recipe_data['recipeInstructions'][] = [
                    '@type' => 'HowToStep',
                    'text' => $instruction
                ];
            }
        }

        if (!empty($fields['prep_time'])) {
            $recipe_data['prepTime'] = 'PT' . $fields['prep_time'] . 'M';
        }

        if (!empty($fields['cook_time'])) {
            $recipe_data['cookTime'] = 'PT' . $fields['cook_time'] . 'M';
        }

        if (!empty($fields['total_time'])) {
            $recipe_data['totalTime'] = 'PT' . $fields['total_time'] . 'M';
        }

        if (!empty($fields['calories'])) {
            $recipe_data['nutrition'] = [
                '@type' => 'NutritionInformation',
                'calories' => $fields['calories'] . ' calories'
            ];
        }

        return $recipe_data;
    }

    // ======================================================================
    // New Schema Type: Event
    // ======================================================================

    /**
     * Render Event schema fields
     */
    private function render_event_fields($fields, $index = 0)
    {
        $name = isset($fields['name']) ? $fields['name'] : '';
        $startDate = isset($fields['startDate']) ? $fields['startDate'] : '';
        $endDate = isset($fields['endDate']) ? $fields['endDate'] : '';
        $eventStatus = isset($fields['eventStatus']) ? $fields['eventStatus'] : 'EventScheduled';
        $eventAttendanceMode = isset($fields['eventAttendanceMode']) ? $fields['eventAttendanceMode'] : 'OfflineEventAttendanceMode';
        $location_name = isset($fields['location_name']) ? $fields['location_name'] : '';
        $location_address = isset($fields['location_address']) ? $fields['location_address'] : '';
        $organizer_name = isset($fields['organizer_name']) ? $fields['organizer_name'] : '';
        $organizer_url = isset($fields['organizer_url']) ? $fields['organizer_url'] : '';
        $offer_price = isset($fields['offer_price']) ? $fields['offer_price'] : '';
        $offer_priceCurrency = isset($fields['offer_priceCurrency']) ? $fields['offer_priceCurrency'] : 'USD';
        $offer_availability = isset($fields['offer_availability']) ? $fields['offer_availability'] : 'InStock';
        $offer_url = isset($fields['offer_url']) ? $fields['offer_url'] : '';
        $image = isset($fields['image']) ? $fields['image'] : '';
        $description = isset($fields['description']) ? $fields['description'] : '';

        ?>
        <div class="schema-field-group">
            <h4>Event Information</h4>
            <p class="description" style="margin-top: 0;">Fields marked with * are required.</p>

            <div class="schema-field">
                <label for="event_name_<?php echo $index; ?>">Event Name: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][name]" id="event_name_<?php echo $index; ?>" value="<?php echo esc_attr($name); ?>" placeholder="e.g., Annual Tech Conference">
            </div>

            <div class="schema-field">
                <label for="event_startDate_<?php echo $index; ?>">Start Date: <span style="color: #dc3232;">*</span></label>
                <input type="datetime-local" name="schema_markup[types][<?php echo $index; ?>][fields][startDate]" id="event_startDate_<?php echo $index; ?>" value="<?php echo esc_attr($startDate); ?>">
            </div>

            <div class="schema-field">
                <label for="event_endDate_<?php echo $index; ?>">End Date:</label>
                <input type="datetime-local" name="schema_markup[types][<?php echo $index; ?>][fields][endDate]" id="event_endDate_<?php echo $index; ?>" value="<?php echo esc_attr($endDate); ?>">
            </div>

            <div class="schema-field">
                <label for="event_status_<?php echo $index; ?>">Event Status:</label>
                <select name="schema_markup[types][<?php echo $index; ?>][fields][eventStatus]" id="event_status_<?php echo $index; ?>">
                    <option value="EventScheduled" <?php selected($eventStatus, 'EventScheduled'); ?>>Scheduled</option>
                    <option value="EventCancelled" <?php selected($eventStatus, 'EventCancelled'); ?>>Cancelled</option>
                    <option value="EventPostponed" <?php selected($eventStatus, 'EventPostponed'); ?>>Postponed</option>
                    <option value="EventRescheduled" <?php selected($eventStatus, 'EventRescheduled'); ?>>Rescheduled</option>
                </select>
            </div>

            <div class="schema-field">
                <label for="event_attendance_<?php echo $index; ?>">Attendance Mode:</label>
                <select name="schema_markup[types][<?php echo $index; ?>][fields][eventAttendanceMode]" id="event_attendance_<?php echo $index; ?>">
                    <option value="OfflineEventAttendanceMode" <?php selected($eventAttendanceMode, 'OfflineEventAttendanceMode'); ?>>In-Person</option>
                    <option value="OnlineEventAttendanceMode" <?php selected($eventAttendanceMode, 'OnlineEventAttendanceMode'); ?>>Online</option>
                    <option value="MixedEventAttendanceMode" <?php selected($eventAttendanceMode, 'MixedEventAttendanceMode'); ?>>Mixed</option>
                </select>
            </div>

            <div class="schema-field">
                <label for="event_location_name_<?php echo $index; ?>">Location Name:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][location_name]" id="event_location_name_<?php echo $index; ?>" value="<?php echo esc_attr($location_name); ?>" placeholder="e.g., Convention Center">
            </div>

            <div class="schema-field">
                <label for="event_location_address_<?php echo $index; ?>">Location Address:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][location_address]" id="event_location_address_<?php echo $index; ?>" value="<?php echo esc_attr($location_address); ?>" placeholder="e.g., 123 Main St, City, State">
            </div>

            <div class="schema-field">
                <label for="event_organizer_name_<?php echo $index; ?>">Organizer Name:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][organizer_name]" id="event_organizer_name_<?php echo $index; ?>" value="<?php echo esc_attr($organizer_name); ?>" placeholder="Organization name">
            </div>

            <div class="schema-field">
                <label for="event_organizer_url_<?php echo $index; ?>">Organizer URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][organizer_url]" id="event_organizer_url_<?php echo $index; ?>" value="<?php echo esc_attr($organizer_url); ?>" placeholder="https://example.com">
            </div>

            <div class="schema-field">
                <label for="event_offer_price_<?php echo $index; ?>">Ticket Price:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][offer_price]" id="event_offer_price_<?php echo $index; ?>" value="<?php echo esc_attr($offer_price); ?>" placeholder="0.00">
            </div>

            <div class="schema-field">
                <label for="event_offer_currency_<?php echo $index; ?>">Price Currency:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][offer_priceCurrency]" id="event_offer_currency_<?php echo $index; ?>" value="<?php echo esc_attr($offer_priceCurrency); ?>" placeholder="USD">
            </div>

            <div class="schema-field">
                <label for="event_offer_availability_<?php echo $index; ?>">Availability:</label>
                <select name="schema_markup[types][<?php echo $index; ?>][fields][offer_availability]" id="event_offer_availability_<?php echo $index; ?>">
                    <option value="InStock" <?php selected($offer_availability, 'InStock'); ?>>In Stock</option>
                    <option value="SoldOut" <?php selected($offer_availability, 'SoldOut'); ?>>Sold Out</option>
                    <option value="PreOrder" <?php selected($offer_availability, 'PreOrder'); ?>>Pre-Order</option>
                    <option value="LimitedAvailability" <?php selected($offer_availability, 'LimitedAvailability'); ?>>Limited Availability</option>
                </select>
            </div>

            <div class="schema-field">
                <label for="event_offer_url_<?php echo $index; ?>">Ticket URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][offer_url]" id="event_offer_url_<?php echo $index; ?>" value="<?php echo esc_attr($offer_url); ?>" placeholder="https://example.com/tickets">
            </div>

            <div class="schema-field">
                <label for="event_image_<?php echo $index; ?>">Image URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][image]" id="event_image_<?php echo $index; ?>" value="<?php echo esc_attr($image); ?>" placeholder="https://example.com/event-image.jpg">
            </div>

            <div class="schema-field">
                <label for="event_description_<?php echo $index; ?>">Description:</label>
                <textarea name="schema_markup[types][<?php echo $index; ?>][fields][description]" id="event_description_<?php echo $index; ?>" rows="4" style="width: 100%;" placeholder="Event description"><?php echo esc_textarea($description); ?></textarea>
            </div>
        </div>
        <?php
    }

    /**
     * Validate Event schema
     */
    private function validate_event_schema($post_id, $fields)
    {
        $errors = [];

        if (empty($fields['name']) || trim($fields['name']) === '') {
            $errors[] = [
                'field' => 'name',
                'message' => 'Event schema requires a <strong>Name</strong>.'
            ];
        }

        if (empty($fields['startDate']) || trim($fields['startDate']) === '') {
            $errors[] = [
                'field' => 'startDate',
                'message' => 'Event schema requires a <strong>Start Date</strong>.'
            ];
        }

        return $errors;
    }

    /**
     * Format an event date with the site's timezone offset for ISO 8601
     */
    private function format_event_date($date_string)
    {
        if (empty($date_string)) {
            return $date_string;
        }

        // If it already has timezone info, return as-is
        if (preg_match('/[+-]\d{2}:\d{2}$|Z$/', $date_string)) {
            return $date_string;
        }

        // Append WordPress site timezone offset
        $timezone = wp_timezone();
        try {
            $dt = new \DateTime($date_string, $timezone);
            return $dt->format('c'); // ISO 8601 with timezone
        } catch (\Exception $e) {
            return $date_string;
        }
    }

    /**
     * Generate Event JSON-LD
     */
    private function generate_event_json_ld($fields, $post)
    {
        $data = [];

        if (!empty($fields['name'])) {
            $data['name'] = $fields['name'];
        }
        if (!empty($fields['startDate'])) {
            $data['startDate'] = $this->format_event_date($fields['startDate']);
        }
        if (!empty($fields['endDate'])) {
            $data['endDate'] = $this->format_event_date($fields['endDate']);
        }
        if (!empty($fields['eventStatus'])) {
            $data['eventStatus'] = 'https://schema.org/' . $fields['eventStatus'];
        }
        if (!empty($fields['eventAttendanceMode'])) {
            $data['eventAttendanceMode'] = 'https://schema.org/' . $fields['eventAttendanceMode'];
        }
        if (!empty($fields['description'])) {
            $data['description'] = $fields['description'];
        }
        if (!empty($fields['image'])) {
            $data['image'] = $fields['image'];
        }

        if (!empty($fields['location_name']) || !empty($fields['location_address'])) {
            $data['location'] = [
                '@type' => 'Place',
            ];
            if (!empty($fields['location_name'])) {
                $data['location']['name'] = $fields['location_name'];
            }
            if (!empty($fields['location_address'])) {
                $data['location']['address'] = [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $fields['location_address'],
                ];
            }
        }

        if (!empty($fields['organizer_name'])) {
            $data['organizer'] = [
                '@type' => 'Organization',
                'name' => $fields['organizer_name'],
            ];
            if (!empty($fields['organizer_url'])) {
                $data['organizer']['url'] = $fields['organizer_url'];
            }
        }

        if (!empty($fields['offer_price'])) {
            $data['offers'] = [
                '@type' => 'Offer',
                'price' => $fields['offer_price'],
                'priceCurrency' => !empty($fields['offer_priceCurrency']) ? $fields['offer_priceCurrency'] : 'USD',
                'availability' => 'https://schema.org/' . (!empty($fields['offer_availability']) ? $fields['offer_availability'] : 'InStock'),
            ];
            if (!empty($fields['offer_url'])) {
                $data['offers']['url'] = $fields['offer_url'];
            }
        }

        return $data;
    }

    // ======================================================================
    // New Schema Type: JobPosting
    // ======================================================================

    /**
     * Render JobPosting schema fields
     */
    private function render_jobposting_fields($fields, $index = 0)
    {
        $title = isset($fields['title']) ? $fields['title'] : '';
        $description = isset($fields['description']) ? $fields['description'] : '';
        $datePosted = isset($fields['datePosted']) ? $fields['datePosted'] : '';
        $validThrough = isset($fields['validThrough']) ? $fields['validThrough'] : '';
        $employmentType = isset($fields['employmentType']) ? $fields['employmentType'] : 'FULL_TIME';
        $hiringOrganization_name = isset($fields['hiringOrganization_name']) ? $fields['hiringOrganization_name'] : '';
        $hiringOrganization_sameAs = isset($fields['hiringOrganization_sameAs']) ? $fields['hiringOrganization_sameAs'] : '';
        $hiringOrganization_logo = isset($fields['hiringOrganization_logo']) ? $fields['hiringOrganization_logo'] : '';
        $jobLocation_address = isset($fields['jobLocation_address']) ? $fields['jobLocation_address'] : '';
        $baseSalary_currency = isset($fields['baseSalary_currency']) ? $fields['baseSalary_currency'] : '';
        $baseSalary_value = isset($fields['baseSalary_value']) ? $fields['baseSalary_value'] : '';
        $baseSalary_unitText = isset($fields['baseSalary_unitText']) ? $fields['baseSalary_unitText'] : 'YEAR';

        ?>
        <div class="schema-field-group">
            <h4>Job Posting Information</h4>
            <p class="description" style="margin-top: 0;">Fields marked with * are required.</p>

            <div class="schema-field">
                <label for="job_title_<?php echo $index; ?>">Job Title: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][title]" id="job_title_<?php echo $index; ?>" value="<?php echo esc_attr($title); ?>" placeholder="e.g., Software Engineer">
            </div>

            <div class="schema-field">
                <label for="job_description_<?php echo $index; ?>">Description: <span style="color: #dc3232;">*</span></label>
                <textarea name="schema_markup[types][<?php echo $index; ?>][fields][description]" id="job_description_<?php echo $index; ?>" rows="4" style="width: 100%;" placeholder="Job description"><?php echo esc_textarea($description); ?></textarea>
            </div>

            <div class="schema-field">
                <label for="job_datePosted_<?php echo $index; ?>">Date Posted: <span style="color: #dc3232;">*</span></label>
                <input type="date" name="schema_markup[types][<?php echo $index; ?>][fields][datePosted]" id="job_datePosted_<?php echo $index; ?>" value="<?php echo esc_attr($datePosted); ?>">
            </div>

            <div class="schema-field">
                <label for="job_validThrough_<?php echo $index; ?>">Valid Through:</label>
                <input type="date" name="schema_markup[types][<?php echo $index; ?>][fields][validThrough]" id="job_validThrough_<?php echo $index; ?>" value="<?php echo esc_attr($validThrough); ?>">
            </div>

            <div class="schema-field">
                <label for="job_employmentType_<?php echo $index; ?>">Employment Type:</label>
                <select name="schema_markup[types][<?php echo $index; ?>][fields][employmentType]" id="job_employmentType_<?php echo $index; ?>">
                    <option value="FULL_TIME" <?php selected($employmentType, 'FULL_TIME'); ?>>Full Time</option>
                    <option value="PART_TIME" <?php selected($employmentType, 'PART_TIME'); ?>>Part Time</option>
                    <option value="CONTRACTOR" <?php selected($employmentType, 'CONTRACTOR'); ?>>Contractor</option>
                    <option value="TEMPORARY" <?php selected($employmentType, 'TEMPORARY'); ?>>Temporary</option>
                    <option value="INTERN" <?php selected($employmentType, 'INTERN'); ?>>Intern</option>
                    <option value="VOLUNTEER" <?php selected($employmentType, 'VOLUNTEER'); ?>>Volunteer</option>
                    <option value="PER_DIEM" <?php selected($employmentType, 'PER_DIEM'); ?>>Per Diem</option>
                    <option value="OTHER" <?php selected($employmentType, 'OTHER'); ?>>Other</option>
                </select>
            </div>

            <div class="schema-field">
                <label for="job_org_name_<?php echo $index; ?>">Hiring Organization: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][hiringOrganization_name]" id="job_org_name_<?php echo $index; ?>" value="<?php echo esc_attr($hiringOrganization_name); ?>" placeholder="Company name">
            </div>

            <div class="schema-field">
                <label for="job_org_url_<?php echo $index; ?>">Organization URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][hiringOrganization_sameAs]" id="job_org_url_<?php echo $index; ?>" value="<?php echo esc_attr($hiringOrganization_sameAs); ?>" placeholder="https://example.com">
            </div>

            <div class="schema-field">
                <label for="job_org_logo_<?php echo $index; ?>">Organization Logo URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][hiringOrganization_logo]" id="job_org_logo_<?php echo $index; ?>" value="<?php echo esc_attr($hiringOrganization_logo); ?>" placeholder="https://example.com/logo.png">
            </div>

            <div class="schema-field">
                <label for="job_location_<?php echo $index; ?>">Job Location: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][jobLocation_address]" id="job_location_<?php echo $index; ?>" value="<?php echo esc_attr($jobLocation_address); ?>" placeholder="e.g., 123 Main St, City, State">
            </div>

            <div class="schema-field">
                <label for="job_salary_currency_<?php echo $index; ?>">Salary Currency:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][baseSalary_currency]" id="job_salary_currency_<?php echo $index; ?>" value="<?php echo esc_attr($baseSalary_currency); ?>" placeholder="USD">
            </div>

            <div class="schema-field">
                <label for="job_salary_value_<?php echo $index; ?>">Salary Value:</label>
                <input type="number" step="0.01" name="schema_markup[types][<?php echo $index; ?>][fields][baseSalary_value]" id="job_salary_value_<?php echo $index; ?>" value="<?php echo esc_attr($baseSalary_value); ?>" placeholder="0.00">
            </div>

            <div class="schema-field">
                <label for="job_salary_unit_<?php echo $index; ?>">Salary Unit:</label>
                <select name="schema_markup[types][<?php echo $index; ?>][fields][baseSalary_unitText]" id="job_salary_unit_<?php echo $index; ?>">
                    <option value="HOUR" <?php selected($baseSalary_unitText, 'HOUR'); ?>>Per Hour</option>
                    <option value="DAY" <?php selected($baseSalary_unitText, 'DAY'); ?>>Per Day</option>
                    <option value="WEEK" <?php selected($baseSalary_unitText, 'WEEK'); ?>>Per Week</option>
                    <option value="MONTH" <?php selected($baseSalary_unitText, 'MONTH'); ?>>Per Month</option>
                    <option value="YEAR" <?php selected($baseSalary_unitText, 'YEAR'); ?>>Per Year</option>
                </select>
            </div>
        </div>
        <?php
    }

    /**
     * Validate JobPosting schema
     */
    private function validate_jobposting_schema($post_id, $fields)
    {
        $errors = [];

        if (empty($fields['title']) || trim($fields['title']) === '') {
            $errors[] = ['field' => 'title', 'message' => 'Job Posting schema requires a <strong>Title</strong>.'];
        }
        if (empty($fields['description']) || trim($fields['description']) === '') {
            $errors[] = ['field' => 'description', 'message' => 'Job Posting schema requires a <strong>Description</strong>.'];
        }
        if (empty($fields['datePosted']) || trim($fields['datePosted']) === '') {
            $errors[] = ['field' => 'datePosted', 'message' => 'Job Posting schema requires a <strong>Date Posted</strong>.'];
        }
        if (empty($fields['hiringOrganization_name']) || trim($fields['hiringOrganization_name']) === '') {
            $errors[] = ['field' => 'hiringOrganization_name', 'message' => 'Job Posting schema requires a <strong>Hiring Organization</strong>.'];
        }
        if (empty($fields['jobLocation_address']) || trim($fields['jobLocation_address']) === '') {
            $errors[] = ['field' => 'jobLocation_address', 'message' => 'Job Posting schema requires a <strong>Job Location</strong>.'];
        }

        return $errors;
    }

    /**
     * Generate JobPosting JSON-LD
     */
    private function generate_jobposting_json_ld($fields, $post)
    {
        $data = [];

        if (!empty($fields['title'])) {
            $data['title'] = $fields['title'];
        }
        if (!empty($fields['description'])) {
            $data['description'] = $fields['description'];
        }
        if (!empty($fields['datePosted'])) {
            $data['datePosted'] = $fields['datePosted'];
        }
        if (!empty($fields['validThrough'])) {
            $data['validThrough'] = $fields['validThrough'];
        }
        if (!empty($fields['employmentType'])) {
            $data['employmentType'] = $fields['employmentType'];
        }

        if (!empty($fields['hiringOrganization_name'])) {
            $data['hiringOrganization'] = [
                '@type' => 'Organization',
                'name' => $fields['hiringOrganization_name'],
            ];
            if (!empty($fields['hiringOrganization_sameAs'])) {
                $data['hiringOrganization']['sameAs'] = $fields['hiringOrganization_sameAs'];
            }
            if (!empty($fields['hiringOrganization_logo'])) {
                $data['hiringOrganization']['logo'] = $fields['hiringOrganization_logo'];
            }
        }

        if (!empty($fields['jobLocation_address'])) {
            $data['jobLocation'] = [
                '@type' => 'Place',
                'address' => [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $fields['jobLocation_address'],
                ],
            ];
        }

        if (!empty($fields['baseSalary_value']) && !empty($fields['baseSalary_currency'])) {
            $data['baseSalary'] = [
                '@type' => 'MonetaryAmount',
                'currency' => $fields['baseSalary_currency'],
                'value' => [
                    '@type' => 'QuantitativeValue',
                    'value' => $fields['baseSalary_value'],
                    'unitText' => !empty($fields['baseSalary_unitText']) ? $fields['baseSalary_unitText'] : 'YEAR',
                ],
            ];
        }

        return $data;
    }

    // ======================================================================
    // New Schema Type: Review
    // ======================================================================

    /**
     * Render Review schema fields
     */
    private function render_review_fields($fields, $index = 0)
    {
        $itemReviewed_name = isset($fields['itemReviewed_name']) ? $fields['itemReviewed_name'] : '';
        $reviewRating_ratingValue = isset($fields['reviewRating_ratingValue']) ? $fields['reviewRating_ratingValue'] : '';
        $reviewRating_bestRating = isset($fields['reviewRating_bestRating']) ? $fields['reviewRating_bestRating'] : '5';
        $author_name = isset($fields['author_name']) ? $fields['author_name'] : '';
        $reviewBody = isset($fields['reviewBody']) ? $fields['reviewBody'] : '';

        ?>
        <div class="schema-field-group">
            <h4>Review Information</h4>
            <p class="description" style="margin-top: 0;">Fields marked with * are required.</p>

            <div class="schema-field">
                <label for="review_item_<?php echo $index; ?>">Item Reviewed: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][itemReviewed_name]" id="review_item_<?php echo $index; ?>" value="<?php echo esc_attr($itemReviewed_name); ?>" placeholder="Name of the item being reviewed">
            </div>

            <div class="schema-field">
                <label for="review_rating_<?php echo $index; ?>">Rating Value: <span style="color: #dc3232;">*</span></label>
                <input type="number" step="0.1" min="1" max="5" name="schema_markup[types][<?php echo $index; ?>][fields][reviewRating_ratingValue]" id="review_rating_<?php echo $index; ?>" value="<?php echo esc_attr($reviewRating_ratingValue); ?>" placeholder="1-5">
            </div>

            <div class="schema-field">
                <label for="review_best_rating_<?php echo $index; ?>">Best Rating:</label>
                <input type="number" step="1" min="1" name="schema_markup[types][<?php echo $index; ?>][fields][reviewRating_bestRating]" id="review_best_rating_<?php echo $index; ?>" value="<?php echo esc_attr($reviewRating_bestRating); ?>" placeholder="5">
            </div>

            <div class="schema-field">
                <label for="review_author_<?php echo $index; ?>">Author Name: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][author_name]" id="review_author_<?php echo $index; ?>" value="<?php echo esc_attr($author_name); ?>" placeholder="Reviewer name">
            </div>

            <div class="schema-field">
                <label for="review_body_<?php echo $index; ?>">Review Body:</label>
                <textarea name="schema_markup[types][<?php echo $index; ?>][fields][reviewBody]" id="review_body_<?php echo $index; ?>" rows="4" style="width: 100%;" placeholder="The review text"><?php echo esc_textarea($reviewBody); ?></textarea>
            </div>
        </div>
        <?php
    }

    /**
     * Validate Review schema
     */
    private function validate_review_schema($post_id, $fields)
    {
        $errors = [];

        if (empty($fields['itemReviewed_name']) || trim($fields['itemReviewed_name']) === '') {
            $errors[] = ['field' => 'itemReviewed_name', 'message' => 'Review schema requires an <strong>Item Reviewed</strong> name.'];
        }
        if (empty($fields['reviewRating_ratingValue']) || floatval($fields['reviewRating_ratingValue']) <= 0) {
            $errors[] = ['field' => 'reviewRating_ratingValue', 'message' => 'Review schema requires a <strong>Rating Value</strong>.'];
        }
        if (empty($fields['author_name']) || trim($fields['author_name']) === '') {
            $errors[] = ['field' => 'author_name', 'message' => 'Review schema requires an <strong>Author Name</strong>.'];
        }

        return $errors;
    }

    /**
     * Generate Review JSON-LD
     */
    private function generate_review_json_ld($fields, $post)
    {
        $data = [];

        if (!empty($fields['itemReviewed_name'])) {
            $data['itemReviewed'] = [
                '@type' => 'Thing',
                'name' => $fields['itemReviewed_name'],
            ];
        }

        if (!empty($fields['reviewRating_ratingValue'])) {
            $data['reviewRating'] = [
                '@type' => 'Rating',
                'ratingValue' => $fields['reviewRating_ratingValue'],
                'bestRating' => !empty($fields['reviewRating_bestRating']) ? $fields['reviewRating_bestRating'] : '5',
            ];
        }

        if (!empty($fields['author_name'])) {
            $data['author'] = [
                '@type' => 'Person',
                'name' => $fields['author_name'],
            ];
        }

        if (!empty($fields['reviewBody'])) {
            $data['reviewBody'] = $fields['reviewBody'];
        }

        return $data;
    }

    // ======================================================================
    // New Schema Type: Course
    // ======================================================================

    /**
     * Render Course schema fields
     */
    private function render_course_fields($fields, $index = 0)
    {
        $name = isset($fields['name']) ? $fields['name'] : '';
        $description = isset($fields['description']) ? $fields['description'] : '';
        $provider_name = isset($fields['provider_name']) ? $fields['provider_name'] : '';
        $provider_sameAs = isset($fields['provider_sameAs']) ? $fields['provider_sameAs'] : '';
        $courseInstance_courseMode = isset($fields['courseInstance_courseMode']) ? $fields['courseInstance_courseMode'] : 'online';
        $courseInstance_instructor_name = isset($fields['courseInstance_instructor_name']) ? $fields['courseInstance_instructor_name'] : '';

        ?>
        <div class="schema-field-group">
            <h4>Course Information</h4>
            <p class="description" style="margin-top: 0;">Fields marked with * are required.</p>

            <div class="schema-field">
                <label for="course_name_<?php echo $index; ?>">Course Name: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][name]" id="course_name_<?php echo $index; ?>" value="<?php echo esc_attr($name); ?>" placeholder="e.g., Introduction to Web Development">
            </div>

            <div class="schema-field">
                <label for="course_description_<?php echo $index; ?>">Description:</label>
                <textarea name="schema_markup[types][<?php echo $index; ?>][fields][description]" id="course_description_<?php echo $index; ?>" rows="4" style="width: 100%;" placeholder="Course description"><?php echo esc_textarea($description); ?></textarea>
            </div>

            <div class="schema-field">
                <label for="course_provider_<?php echo $index; ?>">Provider Name: <span style="color: #dc3232;">*</span></label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][provider_name]" id="course_provider_<?php echo $index; ?>" value="<?php echo esc_attr($provider_name); ?>" placeholder="Organization providing the course">
            </div>

            <div class="schema-field">
                <label for="course_provider_url_<?php echo $index; ?>">Provider URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][provider_sameAs]" id="course_provider_url_<?php echo $index; ?>" value="<?php echo esc_attr($provider_sameAs); ?>" placeholder="https://example.com">
            </div>

            <div class="schema-field">
                <label for="course_mode_<?php echo $index; ?>">Course Mode:</label>
                <select name="schema_markup[types][<?php echo $index; ?>][fields][courseInstance_courseMode]" id="course_mode_<?php echo $index; ?>">
                    <option value="online" <?php selected($courseInstance_courseMode, 'online'); ?>>Online</option>
                    <option value="onsite" <?php selected($courseInstance_courseMode, 'onsite'); ?>>On-site</option>
                    <option value="blended" <?php selected($courseInstance_courseMode, 'blended'); ?>>Blended</option>
                </select>
            </div>

            <div class="schema-field">
                <label for="course_instructor_<?php echo $index; ?>">Instructor Name:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][courseInstance_instructor_name]" id="course_instructor_<?php echo $index; ?>" value="<?php echo esc_attr($courseInstance_instructor_name); ?>" placeholder="Instructor name">
            </div>
        </div>
        <?php
    }

    /**
     * Validate Course schema
     */
    private function validate_course_schema($post_id, $fields)
    {
        $errors = [];

        if (empty($fields['name']) || trim($fields['name']) === '') {
            $errors[] = ['field' => 'name', 'message' => 'Course schema requires a <strong>Name</strong>.'];
        }
        if (empty($fields['provider_name']) || trim($fields['provider_name']) === '') {
            $errors[] = ['field' => 'provider_name', 'message' => 'Course schema requires a <strong>Provider Name</strong>.'];
        }

        return $errors;
    }

    /**
     * Generate Course JSON-LD
     */
    private function generate_course_json_ld($fields, $post)
    {
        $data = [];

        if (!empty($fields['name'])) {
            $data['name'] = $fields['name'];
        }
        if (!empty($fields['description'])) {
            $data['description'] = $fields['description'];
        }

        if (!empty($fields['provider_name'])) {
            $data['provider'] = [
                '@type' => 'Organization',
                'name' => $fields['provider_name'],
            ];
            if (!empty($fields['provider_sameAs'])) {
                $data['provider']['sameAs'] = $fields['provider_sameAs'];
            }
        }

        if (!empty($fields['courseInstance_courseMode']) || !empty($fields['courseInstance_instructor_name'])) {
            $data['hasCourseInstance'] = [
                '@type' => 'CourseInstance',
            ];
            if (!empty($fields['courseInstance_courseMode'])) {
                $data['hasCourseInstance']['courseMode'] = $fields['courseInstance_courseMode'];
            }
            if (!empty($fields['courseInstance_instructor_name'])) {
                $data['hasCourseInstance']['instructor'] = [
                    '@type' => 'Person',
                    'name' => $fields['courseInstance_instructor_name'],
                ];
            }
        }

        return $data;
    }

    // ======================================================================
    // New Schema Type: Organization
    // ======================================================================

    /**
     * Render Organization schema fields
     */
    private function render_organization_fields($fields, $index = 0)
    {
        $name = isset($fields['name']) ? $fields['name'] : '';
        $url = isset($fields['url']) ? $fields['url'] : '';
        $logo = isset($fields['logo']) ? $fields['logo'] : '';
        $contactPoint_telephone = isset($fields['contactPoint_telephone']) ? $fields['contactPoint_telephone'] : '';
        $contactPoint_contactType = isset($fields['contactPoint_contactType']) ? $fields['contactPoint_contactType'] : '';
        $sameAs = isset($fields['sameAs']) ? $fields['sameAs'] : '';

        ?>
        <div class="schema-field-group">
            <h4>Organization Information</h4>

            <div class="schema-field">
                <label for="org_name_<?php echo $index; ?>">Organization Name:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][name]" id="org_name_<?php echo $index; ?>" value="<?php echo esc_attr($name); ?>" placeholder="Organization name">
            </div>

            <div class="schema-field">
                <label for="org_url_<?php echo $index; ?>">URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][url]" id="org_url_<?php echo $index; ?>" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com">
            </div>

            <div class="schema-field">
                <label for="org_logo_<?php echo $index; ?>">Logo URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][logo]" id="org_logo_<?php echo $index; ?>" value="<?php echo esc_attr($logo); ?>" placeholder="https://example.com/logo.png">
            </div>

            <div class="schema-field">
                <label for="org_phone_<?php echo $index; ?>">Contact Telephone:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][contactPoint_telephone]" id="org_phone_<?php echo $index; ?>" value="<?php echo esc_attr($contactPoint_telephone); ?>" placeholder="+1-555-1234">
            </div>

            <div class="schema-field">
                <label for="org_contact_type_<?php echo $index; ?>">Contact Type:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][contactPoint_contactType]" id="org_contact_type_<?php echo $index; ?>" value="<?php echo esc_attr($contactPoint_contactType); ?>" placeholder="customer service">
            </div>

            <div class="schema-field">
                <label for="org_sameas_<?php echo $index; ?>">Social Profiles (one URL per line):</label>
                <textarea name="schema_markup[types][<?php echo $index; ?>][fields][sameAs]" id="org_sameas_<?php echo $index; ?>" rows="4" style="width: 100%;" placeholder="https://facebook.com/...&#10;https://twitter.com/..."><?php echo esc_textarea($sameAs); ?></textarea>
            </div>
        </div>
        <?php
    }

    /**
     * Validate Organization schema
     */
    private function validate_organization_schema($post_id, $fields)
    {
        return [];
    }

    /**
     * Generate Organization JSON-LD
     */
    private function generate_organization_json_ld($fields, $post)
    {
        $data = [];

        if (!empty($fields['name'])) {
            $data['name'] = $fields['name'];
        }
        if (!empty($fields['url'])) {
            $data['url'] = $fields['url'];
        }
        if (!empty($fields['logo'])) {
            $data['logo'] = [
                '@type' => 'ImageObject',
                'url' => $fields['logo'],
            ];
        }

        if (!empty($fields['contactPoint_telephone']) || !empty($fields['contactPoint_contactType'])) {
            $data['contactPoint'] = [
                '@type' => 'ContactPoint',
            ];
            if (!empty($fields['contactPoint_telephone'])) {
                $data['contactPoint']['telephone'] = $fields['contactPoint_telephone'];
            }
            if (!empty($fields['contactPoint_contactType'])) {
                $data['contactPoint']['contactType'] = $fields['contactPoint_contactType'];
            }
        }

        if (!empty($fields['sameAs'])) {
            $urls = array_filter(array_map('trim', explode("\n", $fields['sameAs'])));
            if (!empty($urls)) {
                $data['sameAs'] = $urls;
            }
        }

        return $data;
    }

    // ======================================================================
    // New Schema Type: Person
    // ======================================================================

    /**
     * Render Person schema fields
     */
    private function render_person_fields($fields, $index = 0)
    {
        // Auto-populate from post author if fields are empty
        $author_id = 0;
        global $post;
        if ($post && isset($post->post_author)) {
            $author_id = $post->post_author;
        }

        $name = isset($fields['name']) ? $fields['name'] : '';
        $url = isset($fields['url']) ? $fields['url'] : '';
        $jobTitle = isset($fields['jobTitle']) ? $fields['jobTitle'] : '';
        $worksFor = isset($fields['worksFor']) ? $fields['worksFor'] : '';
        $email = isset($fields['email']) ? $fields['email'] : '';
        $image = isset($fields['image']) ? $fields['image'] : '';
        $sameAs = isset($fields['sameAs']) ? $fields['sameAs'] : '';

        // Build auto-populate hints from author profile
        $author_name = $author_id ? get_the_author_meta('display_name', $author_id) : '';
        $author_url = $author_id ? get_author_posts_url($author_id) : '';
        $author_email = $author_id ? get_the_author_meta('user_email', $author_id) : '';
        $author_avatar = $author_id ? get_avatar_url($author_id, ['size' => 256]) : '';
        $author_desc = $author_id ? get_the_author_meta('description', $author_id) : '';

        ?>
        <div class="schema-field-group">
            <h4>Person Information</h4>
            <?php if ($author_id && $author_name): ?>
            <p class="description" style="margin-top: 0;">Leave fields blank to auto-populate from author profile (<strong><?php echo esc_html($author_name); ?></strong>) when generating schema output.</p>
            <?php endif; ?>

            <div class="schema-field">
                <label for="person_name_<?php echo $index; ?>">Name:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][name]" id="person_name_<?php echo $index; ?>" value="<?php echo esc_attr($name); ?>" placeholder="Full name">
            </div>

            <div class="schema-field">
                <label for="person_url_<?php echo $index; ?>">URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][url]" id="person_url_<?php echo $index; ?>" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com">
            </div>

            <div class="schema-field">
                <label for="person_jobtitle_<?php echo $index; ?>">Job Title:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][jobTitle]" id="person_jobtitle_<?php echo $index; ?>" value="<?php echo esc_attr($jobTitle); ?>" placeholder="e.g., Software Engineer">
            </div>

            <div class="schema-field">
                <label for="person_worksfor_<?php echo $index; ?>">Works For (Organization Name):</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][worksFor]" id="person_worksfor_<?php echo $index; ?>" value="<?php echo esc_attr($worksFor); ?>" placeholder="e.g., Search Atlas">
            </div>

            <div class="schema-field">
                <label for="person_email_<?php echo $index; ?>">Email:</label>
                <input type="email" name="schema_markup[types][<?php echo $index; ?>][fields][email]" id="person_email_<?php echo $index; ?>" value="<?php echo esc_attr($email); ?>" placeholder="email@example.com">
            </div>

            <div class="schema-field">
                <label for="person_image_<?php echo $index; ?>">Image URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][image]" id="person_image_<?php echo $index; ?>" value="<?php echo esc_attr($image); ?>" placeholder="https://example.com/photo.jpg">
            </div>

            <div class="schema-field">
                <label for="person_sameas_<?php echo $index; ?>">Social Profiles (one URL per line):</label>
                <textarea name="schema_markup[types][<?php echo $index; ?>][fields][sameAs]" id="person_sameas_<?php echo $index; ?>" rows="4" style="width: 100%;" placeholder="https://linkedin.com/in/...&#10;https://twitter.com/..."><?php echo esc_textarea($sameAs); ?></textarea>
            </div>
        </div>
        <?php
    }

    /**
     * Validate Person schema
     */
    private function validate_person_schema($post_id, $fields)
    {
        return [];
    }

    /**
     * Generate Person JSON-LD
     */
    private function generate_person_json_ld($fields, $post)
    {
        $data = [];

        // Auto-populate from post author when fields are empty
        $author_id = isset($post->post_author) ? $post->post_author : 0;

        $name = !empty($fields['name']) ? $fields['name'] : ($author_id ? get_the_author_meta('display_name', $author_id) : '');
        $url = !empty($fields['url']) ? $fields['url'] : ($author_id ? get_author_posts_url($author_id) : '');
        $image = !empty($fields['image']) ? $fields['image'] : ($author_id ? get_avatar_url($author_id, ['size' => 256]) : '');

        if (!empty($name)) {
            $data['name'] = $name;
        }
        if (!empty($url)) {
            $data['url'] = $url;
        }
        if (!empty($fields['jobTitle'])) {
            $data['jobTitle'] = $fields['jobTitle'];
        }
        if (!empty($fields['worksFor'])) {
            $data['worksFor'] = [
                '@type' => 'Organization',
                'name' => $fields['worksFor'],
            ];
        }
        if (!empty($fields['email'])) {
            $data['email'] = $fields['email'];
        }
        if (!empty($image)) {
            $data['image'] = $image;
        }

        if (!empty($fields['sameAs'])) {
            $urls = array_filter(array_map('trim', explode("\n", $fields['sameAs'])));
            if (!empty($urls)) {
                $data['sameAs'] = $urls;
            }
        }

        return $data;
    }

    // ======================================================================
    // New Schema Type: WebSite
    // ======================================================================

    /**
     * Render WebSite schema fields
     */
    private function render_website_fields($fields, $index = 0)
    {
        $name = isset($fields['name']) ? $fields['name'] : '';
        $url = isset($fields['url']) ? $fields['url'] : '';
        $searchbox_enabled = isset($fields['searchbox_enabled']) ? (bool) $fields['searchbox_enabled'] : false;
        $searchbox_query_input = isset($fields['searchbox_query_input']) ? $fields['searchbox_query_input'] : '';

        ?>
        <div class="schema-field-group">
            <h4>Website Information</h4>

            <div class="schema-field">
                <label for="website_name_<?php echo $index; ?>">Website Name:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][name]" id="website_name_<?php echo $index; ?>" value="<?php echo esc_attr($name); ?>" placeholder="Your website name">
            </div>

            <div class="schema-field">
                <label for="website_url_<?php echo $index; ?>">URL:</label>
                <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][url]" id="website_url_<?php echo $index; ?>" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com">
            </div>

            <div class="schema-field">
                <label>
                    <input type="checkbox" name="schema_markup[types][<?php echo $index; ?>][fields][searchbox_enabled]" value="1" <?php checked($searchbox_enabled, true); ?>>
                    Enable Sitelinks Searchbox
                </label>
                <p class="description">Adds SearchAction for Google Sitelinks Searchbox in SERPs.</p>
            </div>

            <div class="schema-field">
                <label for="website_query_<?php echo $index; ?>">Search URL Template:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][searchbox_query_input]" id="website_query_<?php echo $index; ?>" value="<?php echo esc_attr($searchbox_query_input); ?>" placeholder="/?s={search_term_string}">
                <p class="description">Search URL template (default: /?s={search_term_string})</p>
            </div>
        </div>
        <?php
    }

    /**
     * Validate WebSite schema
     */
    private function validate_website_schema($post_id, $fields)
    {
        return [];
    }

    /**
     * Generate WebSite JSON-LD
     */
    private function generate_website_json_ld($fields, $post)
    {
        $data = [];

        if (!empty($fields['name'])) {
            $data['name'] = $fields['name'];
        }
        if (!empty($fields['url'])) {
            $data['url'] = $fields['url'];
        }

        if (!empty($fields['searchbox_enabled'])) {
            $search_target = !empty($fields['searchbox_query_input']) ? $fields['searchbox_query_input'] : '/?s={search_term_string}';
            $base_url = !empty($fields['url']) ? rtrim($fields['url'], '/') : home_url();
            $data['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $base_url . $search_target,
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $data;
    }

    // ======================================================================
    // New Schema Type: NewsArticle
    // ======================================================================

    /**
     * Render NewsArticle schema fields
     */
    private function render_newsarticle_fields($fields, $index = 0)
    {
        $organization_name = isset($fields['organization_name']) ? $fields['organization_name'] : '';
        $organization_logo = isset($fields['organization_logo']) ? $fields['organization_logo'] : '';

        ?>
        <div class="schema-field-group">
            <?php $this->render_override_fields_section($fields, $index, 'NewsArticle', true); ?>

            <h4>News Article Information</h4>
            <p class="description" style="margin-top: 0;">Optional fields to enhance your news article schema.</p>

            <div class="schema-field">
                <label for="newsarticle_organization_name_<?php echo $index; ?>">Organization Name:</label>
                <input type="text" name="schema_markup[types][<?php echo $index; ?>][fields][organization_name]" id="newsarticle_organization_name_<?php echo $index; ?>" value="<?php echo esc_attr($organization_name); ?>" placeholder="e.g., News Corp">
                <p class="description">The name of the organization that published this article.</p>
            </div>

            <div class="schema-field">
                <label for="newsarticle_organization_logo_<?php echo $index; ?>">Organization Logo:</label>
                <div class="logo-upload-container">
                    <input type="url" name="schema_markup[types][<?php echo $index; ?>][fields][organization_logo]" id="newsarticle_organization_logo_<?php echo $index; ?>" value="<?php echo esc_attr($organization_logo); ?>" placeholder="https://example.com/logo.png" style="width: 70%; margin-right: 10px;">
                </div>
                <p class="description">Logo of the organization (recommended for better rich results).</p>
            </div>
        </div>
        <?php
    }

    /**
     * Validate NewsArticle schema (same checks as Article)
     */
    private function validate_newsarticle_schema($post_id, $fields)
    {
        $errors = [];

        $effective_title = $this->get_effective_schema_value('title_override', $fields, $post_id);
        $effective_description = $this->get_effective_schema_value('description_override', $fields, $post_id);
        $effective_image = $this->get_effective_schema_value('image_override', $fields, $post_id);

        if (empty($effective_title) || trim($effective_title) === '') {
            $errors[] = [
                'field' => 'headline',
                'message' => 'News Article schema requires a <strong>Headline</strong>. Please add a post title or override it in the Override Default Fields section.'
            ];
        }

        if (empty($effective_description) || trim($effective_description) === '') {
            $errors[] = [
                'field' => 'description',
                'message' => 'News Article schema requires a <strong>Description</strong>. Please add post content, an excerpt, or override it in the Override Default Fields section.'
            ];
        }

        if (empty($effective_image)) {
            $errors[] = [
                'field' => 'image',
                'message' => 'News Article schema requires an <strong>Image</strong>. Please set a featured image or override it in the Override Default Fields section.'
            ];
        }

        return $errors;
    }

    /**
     * Generate NewsArticle JSON-LD (same shape as Article)
     */
    private function generate_newsarticle_json_ld($fields, $post)
    {
        return $this->generate_article_json_ld($fields, $post);
    }

    // ======================================================================
    // Cross-plugin schema deduplication
    // ======================================================================

    /**
     * Check if a conflicting SEO plugin already outputs this schema type.
     *
     * Per-type suppression: only suppress the specific @type that a competing
     * plugin is known to output, not all MetaSync schema.
     *
     * @param string $type Schema.org type name
     * @return bool True if MetaSync should output this type; false to suppress
     */
    private function should_output_schema_type($type)
    {
        // Yoast SEO — outputs these types via its schema graph
        if (class_exists('WPSEO_Schema_Context')) {
            $yoast_types = ['Article', 'NewsArticle', 'WebSite', 'Organization', 'Person', 'WebPage', 'BreadcrumbList'];
            if (in_array($type, $yoast_types, true)) {
                return false;
            }
        }

        // Yoast Local SEO — specifically for LocalBusiness
        if ($type === 'LocalBusiness' && class_exists('WPSEO_Local_Core')) {
            return false;
        }

        // Rank Math — outputs these types via its schema module
        if (class_exists('RankMath\\Schema\\JsonLD')) {
            $rankmath_types = ['Article', 'NewsArticle', 'WebSite', 'Organization', 'Person', 'WebPage', 'BreadcrumbList'];
            if (in_array($type, $rankmath_types, true)) {
                return false;
            }
        }

        // AIOSEO — outputs these types when its schema feature is active
        if (has_filter('aioseo_schema')) {
            $aioseo_types = ['Article', 'NewsArticle', 'WebSite', 'Organization', 'Person', 'WebPage', 'BreadcrumbList'];
            if (in_array($type, $aioseo_types, true)) {
                return false;
            }
        }

        return true;
    }

    // ======================================================================
    // WooCommerce integration for Product schema
    // ======================================================================

    /**
     * Generate LocalBusiness JSON-LD
     */
    private function generate_local_business_json_ld($fields)
    {
        // Deduplication: suppress if Yoast Local SEO is active
        // (also checked upstream in dedup_check_for_type, but kept as safety net)
        if (class_exists('WPSEO_Local_Core')) {
            return false;
        }

        $data = [];

        if (!empty($fields['business_name'])) {
            $data['name'] = $fields['business_name'];
        }

        if (!empty($fields['description'])) {
            $data['description'] = $fields['description'];
        }

        if (!empty($fields['url'])) {
            $data['url'] = $fields['url'];
        }

        if (!empty($fields['telephone'])) {
            $data['telephone'] = $fields['telephone'];
        }

        if (!empty($fields['price_range'])) {
            $data['priceRange'] = $fields['price_range'];
        }

        if (!empty($fields['image'])) {
            $data['image'] = $fields['image'];
        }

        // Build PostalAddress sub-object
        $address = [];
        if (!empty($fields['street_address'])) {
            $address['streetAddress'] = $fields['street_address'];
        }
        if (!empty($fields['city'])) {
            $address['addressLocality'] = $fields['city'];
        }
        if (!empty($fields['state'])) {
            $address['addressRegion'] = $fields['state'];
        }
        if (!empty($fields['postal_code'])) {
            $address['postalCode'] = $fields['postal_code'];
        }
        if (!empty($fields['country'])) {
            $address['addressCountry'] = $fields['country'];
        }
        if (!empty($address)) {
            $address['@type'] = 'PostalAddress';
            $data['address'] = $address;
        }

        // Build GeoCoordinates sub-object
        if (!empty($fields['latitude']) && !empty($fields['longitude'])) {
            $data['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $fields['latitude'],
                'longitude' => $fields['longitude'],
            ];
        }

        // Build openingHoursSpecification
        if (!empty($fields['opening_hours']) && is_array($fields['opening_hours'])) {
            $specs = [];
            foreach ($fields['opening_hours'] as $hours) {
                if (!empty($hours['day'])) {
                    $spec = [
                        '@type' => 'OpeningHoursSpecification',
                        'dayOfWeek' => $hours['day'],
                    ];
                    if (!empty($hours['open'])) {
                        $spec['opens'] = $hours['open'];
                    }
                    if (!empty($hours['close'])) {
                        $spec['closes'] = $hours['close'];
                    }
                    $specs[] = $spec;
                }
            }
            if (!empty($specs)) {
                $data['openingHoursSpecification'] = $specs;
            }
        }

        return $data;
    }

    /**
     * Generate HowTo JSON-LD
     */
    private function generate_howto_json_ld($fields)
    {
        $data = [];

        // Steps
        if (!empty($fields['steps']) && is_array($fields['steps'])) {
            $data['step'] = [];
            foreach ($fields['steps'] as $step) {
                if (!empty($step['instructions'])) {
                    $step_data = [
                        '@type' => 'HowToStep',
                        'text' => $step['instructions'],
                    ];
                    if (!empty($step['image'])) {
                        $step_data['image'] = $step['image'];
                    }
                    $data['step'][] = $step_data;
                }
            }
        }

        // Supplies
        if (!empty($fields['supplies']) && is_array($fields['supplies'])) {
            $data['supply'] = [];
            foreach ($fields['supplies'] as $supply) {
                if (!empty($supply)) {
                    $data['supply'][] = [
                        '@type' => 'HowToSupply',
                        'name' => $supply,
                    ];
                }
            }
        }

        // Tools
        if (!empty($fields['tools']) && is_array($fields['tools'])) {
            $data['tool'] = [];
            foreach ($fields['tools'] as $tool) {
                if (!empty($tool)) {
                    $data['tool'][] = [
                        '@type' => 'HowToTool',
                        'name' => $tool,
                    ];
                }
            }
        }

        // Total time (ISO 8601 duration)
        if (!empty($fields['total_time'])) {
            $data['totalTime'] = 'PT' . $fields['total_time'] . 'M';
        }

        // Estimated cost
        if (!empty($fields['estimated_cost'])) {
            $data['estimatedCost'] = [
                '@type' => 'MonetaryAmount',
                'value' => $fields['estimated_cost'],
            ];
        }

        return $data;
    }

    /**
     * Generate VideoObject JSON-LD
     */
    private function generate_video_object_json_ld($fields)
    {
        $data = [];

        if (!empty($fields['video_name'])) {
            $data['name'] = $fields['video_name'];
        }

        if (!empty($fields['video_description'])) {
            $data['description'] = $fields['video_description'];
        }

        if (!empty($fields['thumbnail_url'])) {
            $data['thumbnailUrl'] = $fields['thumbnail_url'];
        }

        if (!empty($fields['upload_date'])) {
            $data['uploadDate'] = $fields['upload_date'];
        }

        if (!empty($fields['content_url'])) {
            $data['contentUrl'] = $fields['content_url'];
        }

        if (!empty($fields['embed_url'])) {
            $data['embedUrl'] = $fields['embed_url'];
        }

        if (!empty($fields['duration'])) {
            $data['duration'] = 'PT' . $fields['duration'] . 'M';
        }

        return $data;
    }

    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook)
    {
        // Only load on post/page edit screens
        if ($hook !== 'post.php' && $hook !== 'post-new.php') {
            return;
        }

        wp_enqueue_script('jquery');
        
        // Enqueue WordPress media scripts for image uploader
        wp_enqueue_media();
        
        // Enqueue schema markup admin CSS
        wp_enqueue_style(
            'metasync-schema-markup-admin',
            plugin_dir_url(__FILE__) . 'css/schema-markup-admin.css',
            [],
            $this->version,
            'all'
        );
        
        // Enqueue schema markup admin JS
        wp_enqueue_script(
            'metasync-schema-markup-admin',
            plugin_dir_url(__FILE__) . 'js/schema-markup-admin.js',
            ['jquery', 'wp-util'],
            $this->version,
            true
        );
        
        // Localize script with nonces
        wp_localize_script(
            'metasync-schema-markup-admin',
            'metasyncSchemaMarkup',
            [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'schema_fields_nonce' => wp_create_nonce('metasync_schema_fields_nonce'),
                'preview_schema_nonce' => wp_create_nonce('metasync_preview_schema_nonce')
            ]
        );
    }

    /**
     * AJAX handler for getting schema fields
     */
    public function ajax_get_schema_fields()
    {
        // Verify nonce
        if (empty($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'metasync_schema_fields_nonce')) {
            wp_die('Security check failed');
        }

        $schema_type = sanitize_text_field($_POST['schema_type']);
        $index = isset($_POST['index']) ? intval($_POST['index']) : 0;
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        
        // Set global post for override fields rendering
        if ($post_id) {
            global $post;
            $post = get_post($post_id);
        }
        
        ob_start();
        $this->render_schema_fields($schema_type, [], $index);
        $html = ob_get_clean();

        wp_send_json_success($html);
    }

    /**
     * AJAX handler for previewing schema markup
     */
    public function ajax_preview_schema()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'metasync_preview_schema_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        // Get post ID
        $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
        if (!$post_id) {
            wp_send_json_error(['message' => 'Invalid post ID']);
        }

        // Get post object
        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => 'Post not found']);
        }

        // Check if schema is enabled
        $schema_enabled = isset($_POST['schema_enabled']) && $_POST['schema_enabled'];
        if (!$schema_enabled) {
            wp_send_json_error(['message' => 'Schema markup is not enabled']);
        }

        // Get schema types data
        $schema_types = isset($_POST['schema_types']) ? $_POST['schema_types'] : [];
        if (empty($schema_types)) {
            wp_send_json_error(['message' => 'No schema types provided']);
        }

        // Build schema data array
        $schema_data = [
            'enabled' => true,
            'types' => []
        ];

        foreach ($schema_types as $type_data) {
            if (!empty($type_data['type'])) {
                $schema_type = sanitize_text_field($type_data['type']);
                $fields = isset($type_data['fields']) ? $type_data['fields'] : [];
                $sanitized_fields = $this->sanitize_schema_fields($fields, $schema_type);
                
                $schema_data['types'][] = [
                    'type' => $schema_type,
                    'fields' => $sanitized_fields
                ];
            }
        }

        // Validate schema requirements before generating preview
        $all_validation_errors = [];
        foreach ($schema_data['types'] as $schema_type_data) {
            $validation_errors = $this->validate_schema_requirements($post_id, $schema_type_data['type'], $schema_type_data['fields']);
            if (!empty($validation_errors)) {
                // Group errors by schema type with display-friendly names
                $schema_type_display_names = [
                    'article' => 'Article',
                    'FAQPage' => 'FAQ',
                    'product' => 'Product',
                    'recipe' => 'Recipe',
                    'Event' => 'Event',
                    'JobPosting' => 'Job Posting',
                    'Review' => 'Review',
                    'Course' => 'Course',
                    'Organization' => 'Organization',
                    'Person' => 'Person',
                    'WebSite' => 'Website',
                    'NewsArticle' => 'News Article',
                    'LocalBusiness' => 'Local Business',
                    'HowTo' => 'How-To',
                    'VideoObject' => 'Video Object'
                ];
                $schema_type_name = isset($schema_type_display_names[$schema_type_data['type']]) 
                    ? $schema_type_display_names[$schema_type_data['type']] 
                    : ucfirst($schema_type_data['type']);
                
                foreach ($validation_errors as $error) {
                    $all_validation_errors[] = [
                        'schema_type' => $schema_type_name,
                        'field' => $error['field'],
                        'message' => $error['message']
                    ];
                }
            }
        }

        // If there are validation errors, return them instead of generating preview
        if (!empty($all_validation_errors)) {
            wp_send_json_error([
                'message' => 'Validation errors detected',
                'validation_errors' => $all_validation_errors
            ]);
        }

        // Generate JSON-LD for all schema types
        $all_json_ld = [];
        foreach ($schema_data['types'] as $schema_type_data) {
            $json_ld = $this->generate_json_ld($post, $schema_type_data);
            if ($json_ld) {
                $all_json_ld[] = $json_ld;
            }
        }

        if (empty($all_json_ld)) {
            wp_send_json_error(['message' => 'Could not generate schema markup']);
        }

        // Build the final JSON-LD structure with @context and @graph
        $final_json_ld = [
            '@context' => 'https://schema.org',
            '@graph' => $all_json_ld
        ];
        
        $formatted_json = wp_json_encode($final_json_ld, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        
        // Wrap in script tags for preview
        $formatted_json_with_tags = '<script type="application/ld+json" class="metasync-schema">' . "\n" . $formatted_json . "\n" . '</script>';

        wp_send_json_success([
            'json' => $formatted_json_with_tags,
            'types' => array_column($schema_data['types'], 'type')
        ]);
    }

    /**
     * Get the plugin name
     *
     * @return string The plugin name
     */
    public function get_plugin_name()
    {
        return $this->plugin_name;
    }

    /**
     * Get the plugin version
     *
     * @return string The plugin version
     */
    public function get_version()
    {
        return $this->version;
    }

    /**
     * Get images for schema markup
     * Currently returns featured image, but designed to support multiple sources in future
     * 
     * @param int $post_id The post ID
     * @return array Array of image URLs
     */
    private function get_post_images($post_id)
    {
        $images = [];

        // 1. Featured Image (Post Thumbnail)
        $featured_image_id = get_post_thumbnail_id($post_id);
        if ($featured_image_id) {
            $featured_image_url = wp_get_attachment_image_url($featured_image_id, 'full');
            if ($featured_image_url) {
                $images[] = $featured_image_url;
            }
        }

        // Future expansion points:
        // 2. Gallery images from post content
        // 3. Images from post attachments
        // 4. WooCommerce product gallery (if WooCommerce is active)
        // 5. Custom field images
        // 6. Images from post content (img tags)

        return $images;
    }

}

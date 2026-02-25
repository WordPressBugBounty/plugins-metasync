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
        $schema_type = isset($schema_type_data['type']) ? $schema_type_data['type'] : '';
        $schema_fields = isset($schema_type_data['fields']) ? $schema_type_data['fields'] : [];
        $schema_type_names = [
            'article' => 'Article',
            'FAQPage' => 'FAQ',
            'product' => 'Product',
            'recipe' => 'Recipe'
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
        }

        return $sanitized;
    }

    /**
     * Output schema markup to the frontend
     */
    public function output_schema_markup()
    {
        // Schema is always enabled by default - no check needed

        // Only output on single posts/pages
        if (!is_singular()) {
            return;
        }

        global $post;
        // Check if $post object exists and has an ID
        if (!$post || !isset($post->ID)) {
            return;
        }
        $schema_data = get_post_meta($post->ID, 'metasync_schema_markup', true);

        if (empty($schema_data) || !$schema_data['enabled'] || empty($schema_data['types'])) {
            return;
        }

        // Check for validation errors - do not output JSON-LD if there are errors
        $validation_errors = get_post_meta($post->ID, '_metasync_schema_validation_errors', true);
        if (!empty($validation_errors) && is_array($validation_errors)) {
            // Don't output schema markup if there are validation errors
            return;
        }

        // Generate JSON-LD for all schema types
        $all_json_ld = [];
        foreach ($schema_data['types'] as $schema_type_data) {
            $json_ld = $this->generate_json_ld($post, $schema_type_data);
            if ($json_ld) {
                $all_json_ld[] = $json_ld;
            }
        }

        // Output all schemas in a single script tag with @graph pattern
        if (!empty($all_json_ld)) {
            // Build the final JSON-LD structure with @context and @graph
            $final_json_ld = [
                '@context' => 'https://schema.org',
                '@graph' => $all_json_ld
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
        $json_ld = [
            '@type' => ucfirst($type)
        ];

        // Get effective values (override if provided, otherwise defaults)
        $effective_title = $this->get_effective_schema_value('title_override', $fields, $post->ID);
        $effective_description = $this->get_effective_schema_value('description_override', $fields, $post->ID);
        $effective_image = $this->get_effective_schema_value('image_override', $fields, $post->ID);

        // For articles, don't add 'name' and 'url' as we'll use 'headline' and 'mainEntityOfPage' instead
        // For FAQ, we only need the schema type and mainEntity
        if ($type !== 'article' && $type !== 'FAQPage') {
            $json_ld['name'] = $effective_title;
            $json_ld['url'] = get_permalink($post->ID);
            $json_ld['description'] = $effective_description;
            
            // Add images - use override if provided, otherwise get from featured image
            if (!empty($effective_image)) {
                $json_ld['image'] = $effective_image;
            } else {
                $images = $this->get_post_images($post->ID);
                if (!empty($images)) {
                    // Return single image URL if only one, array if multiple
                    $json_ld['image'] = count($images) === 1 ? $images[0] : $images;
                }
            }
        } elseif ($type === 'article') {
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
        if (!wp_verify_nonce($_POST['nonce'], 'metasync_schema_fields_nonce')) {
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
                    'recipe' => 'Recipe'
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

<?php

/**
 * The setup wizard functionality of the plugin
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/includes
 */

/**
 * The setup wizard class.
 *
 * This class handles the setup wizard functionality for first-time plugin setup.
 * Guides users through connecting the platform, importing from other SEO plugins,
 * and configuring basic SEO settings.
 *
 * @since      1.0.0
 * @package    Metasync
 * @subpackage Metasync/includes
 * @author     Engineering Team <support@searchatlas.com>
 */
class Metasync_Setup_Wizard
{
	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Total number of wizard steps
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      int
	 */
	private $total_steps = 6;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct($plugin_name, $version)
	{
		$this->plugin_name = $plugin_name;
		$this->version = $version;
	}

	/**
	 * Check if wizard should auto-launch
	 *
	 * @since    1.0.0
	 * @return   boolean
	 */
	public function should_auto_launch_wizard()
	{
		// Check if wizard has been completed
		$wizard_completed = get_option('metasync_wizard_completed', false);

		if ($wizard_completed && !empty($wizard_completed['completed'])) {
			return false;
		}

		// Check if first activation flag is set
		return get_option('metasync_show_wizard', false);
	}

	/**
	 * Render the setup wizard page
	 *
	 * @since    1.0.0
	 */
	public function render_wizard_page()
	{
		// Check user has access
		if (!Metasync::current_user_has_plugin_access()) {
			wp_die(__('You do not have sufficient permissions to access this page.'));
		}

		// Get current wizard state
		$user_id = get_current_user_id();
		$wizard_state = get_transient("metasync_wizard_state_{$user_id}");

		if (!$wizard_state) {
			// Initialize wizard state
			$wizard_state = array(
				'current_step' => 1,
				'completed_steps' => array(),
				'started_at' => time(),
				'user_id' => $user_id
			);
			set_transient("metasync_wizard_state_{$user_id}", $wizard_state, DAY_IN_SECONDS);
		}

		// Load wizard template
		require_once plugin_dir_path(dirname(__FILE__)) . 'views/metasync-setup-wizard.php';
	}

	/**
	 * Get all wizard steps configuration
	 *
	 * @since    1.0.0
	 * @return   array
	 */
	private function get_wizard_steps()
	{
		return array(
			1 => array(
				'id' => 'welcome',
				'title' => 'Welcome',
				'description' => 'Welcome to MetaSync'
			),
			2 => array(
				'id' => 'connection',
				'title' => 'Connect',
				'description' => 'Connect to platform'
			),
			3 => array(
				'id' => 'import',
				'title' => 'Import',
				'description' => 'Import from other plugins'
			),
			4 => array(
				'id' => 'seo_settings',
				'title' => 'SEO Settings',
				'description' => 'Configure indexation'
			),
			5 => array(
				'id' => 'schema',
				'title' => 'Schema',
				'description' => 'Schema preferences'
			),
			6 => array(
				'id' => 'complete',
				'title' => 'Complete',
				'description' => 'Setup complete'
			)
		);
	}

	/**
	 * Render Step 1: Welcome Screen
	 *
	 * @since    1.0.0
	 */
	public function render_step_welcome()
	{
		$plugin_name = Metasync::get_effective_plugin_name();
		?>
		<div class="wizard-step wizard-step-welcome active" data-step="1">
			<div class="wizard-step-header">
				<h2>🎉 Welcome to <?php echo esc_html($plugin_name); ?>!</h2>
				<p>Let's get your site optimized for search engines in just a few minutes.</p>
			</div>

			<div class="wizard-step-content">
				<div class="wizard-benefits">
					<div class="wizard-benefit-card">
						<span class="benefit-icon">🚀</span>
						<h3>Automated SEO</h3>
						<p>Automatically optimize your content for search engines</p>
					</div>
					<div class="wizard-benefit-card">
						<span class="benefit-icon">📊</span>
						<h3>Performance Tracking</h3>
						<p>Monitor your site's search performance in real-time</p>
					</div>
					<div class="wizard-benefit-card">
						<span class="benefit-icon">🔗</span>
						<h3>Seamless Integration</h3>
						<p>Connect with <?php echo esc_html($plugin_name); ?> for advanced features</p>
					</div>
				</div>

				<div class="wizard-time-estimate">
					<span>⏱️ Estimated time: 3-5 minutes</span>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Step 2: Connection
	 *
	 * @since    1.0.0
	 */
	public function render_step_connection()
	{
		$plugin_name = Metasync::get_effective_plugin_name();
		$general_settings = Metasync::get_option('general');
		$is_connected = !empty($general_settings['searchatlas_api_key']);
		?>
		<div class="wizard-step wizard-step-connection" data-step="2">
			<div class="wizard-step-header">
				<h2>🔗 Connect to <?php echo esc_html($plugin_name); ?></h2>
				<p>Link your WordPress site to your <?php echo esc_html($plugin_name); ?> account for advanced features.</p>
			</div>

			<div class="wizard-step-content">
				<?php if (!$is_connected): ?>
					<div class="wizard-connection-box">
						<p><strong>Why connect?</strong></p>
						<ul>
							<li>Access OTTO AI-powered SEO assistant</li>
							<li>Get real-time search ranking data</li>
							<li>Receive automated optimization suggestions</li>
						</ul>

						<button id="wizard-connect-btn" class="wizard-primary-btn">
							Connect with <?php echo esc_html($plugin_name); ?>
						</button>
					</div>
				<?php else: ?>
					<div class="wizard-connection-success">
						<span class="success-icon">✓</span>
						<h3>Successfully Connected!</h3>
						<p>Your site is linked to <?php echo esc_html($plugin_name); ?>.</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Step 3: Plugin Import
	 *
	 * @since    1.0.0
	 */
	public function render_step_import()
	{
		$installed_plugins = $this->detect_seo_plugins();
		?>
		<div class="wizard-step wizard-step-import" data-step="3">
			<div class="wizard-step-header">
				<h2>📥 Import from Other SEO Plugins</h2>
				<p>We detected other SEO plugins. Import your existing settings to save time.</p>
			</div>

			<div class="wizard-step-content">
				<?php if (!empty($installed_plugins)): ?>
					<div class="wizard-import-grid">
						<?php foreach ($installed_plugins as $plugin): ?>
							<div class="wizard-import-card">
								<div class="import-card-header">
									<h3><?php echo esc_html($plugin['name']); ?></h3>
									<span class="import-badge">Detected</span>
								</div>

								<div class="import-card-options">
									<label>
										<input type="checkbox" class="import-option"
											   data-plugin="<?php echo esc_attr($plugin['slug']); ?>"
											   data-type="robots">
										Robots.txt
									</label>
									<label>
										<input type="checkbox" class="import-option"
											   data-plugin="<?php echo esc_attr($plugin['slug']); ?>"
											   data-type="sitemap">
										Sitemap Settings
									</label>
									<label>
										<input type="checkbox" class="import-option"
											   data-plugin="<?php echo esc_attr($plugin['slug']); ?>"
											   data-type="redirections">
										Redirections
									</label>
									<label>
										<input type="checkbox" class="import-option"
											   data-plugin="<?php echo esc_attr($plugin['slug']); ?>"
											   data-type="indexation">
										Indexation/Robots Meta
									</label>
									<label>
										<input type="checkbox" class="import-option"
											   data-plugin="<?php echo esc_attr($plugin['slug']); ?>"
											   data-type="schema">
										Schema Markup
									</label>
								</div>

								<div class="import-card-footer">
									<button class="wizard-import-btn"
											data-plugin="<?php echo esc_attr($plugin['slug']); ?>">
										Import Selected
									</button>
									<div class="import-progress" style="display:none;">
										<div class="import-progress-bar"></div>
										<span class="import-progress-text">Importing...</span>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php else: ?>
					<div class="wizard-no-imports">
						<p>No compatible SEO plugins detected. You can skip this step.</p>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}


	/**
	 * Render Step 4: SEO Settings (Indexation Controls)
	 *
	 * @since    1.0.0
	 */
	public function render_step_seo_settings()
	{
		$seo_controls = Metasync::get_option('seo_controls') ?: array();
		?>
		<div class="wizard-step wizard-step-seo" data-step="4">
			<div class="wizard-step-header">
				<h2>🚀 Basic SEO Settings</h2>
				<p>Choose which archive types should be indexed by search engines.</p>
			</div>

			<div class="wizard-step-content">
				<div class="wizard-seo-grid">
					<div class="wizard-seo-card">
						<h3>📅 Archive Types</h3>
						<p>Control which WordPress archives are visible to search engines.</p>

						<div class="wizard-toggle-group">
							<label class="wizard-toggle-label">
								<input type="checkbox" name="seo_date_archives"
									   <?php checked(!isset($seo_controls['index_date_archives']) || $seo_controls['index_date_archives'] !== 'true'); ?>>
								<span>Index Date Archives</span>
							</label>

							<label class="wizard-toggle-label">
								<input type="checkbox" name="seo_author_archives"
									   <?php checked(!isset($seo_controls['index_author_archives']) || $seo_controls['index_author_archives'] !== 'true'); ?>>
								<span>Index Author Archives</span>
							</label>

							<label class="wizard-toggle-label">
								<input type="checkbox" name="seo_category_archives"
									   <?php checked(!isset($seo_controls['index_category_archives']) || $seo_controls['index_category_archives'] !== 'true'); ?>>
								<span>Index Category Archives</span>
							</label>

							<label class="wizard-toggle-label">
								<input type="checkbox" name="seo_tag_archives"
									   <?php checked(!isset($seo_controls['index_tag_archives']) || $seo_controls['index_tag_archives'] !== 'true'); ?>>
								<span>Index Tag Archives</span>
							</label>
						</div>
					</div>

					<div class="wizard-recommendation-box">
						<h4>💡 Recommended Settings</h4>
						<p>For most sites, we recommend:</p>
						<ul>
							<li>✓ Index Category and Tag archives (helps with SEO)</li>
							<li>✗ Noindex Date archives (usually duplicate content)</li>
							<li>✗ Noindex Author archives (unless multi-author blog)</li>
						</ul>
						<button class="wizard-apply-recommended">Apply Recommended</button>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Step 5: Schema Preferences
	 *
	 * @since    1.0.0
	 */
	public function render_step_schema()
	{
		$general_settings = Metasync::get_option('general');
		$schema_enabled = !empty($general_settings['enable_schema_markup']);
		?>
		<div class="wizard-step wizard-step-schema" data-step="5">
			<div class="wizard-step-header">
				<h2>📋 Schema Markup Preferences</h2>
				<p>Configure structured data to help search engines understand your content.</p>
			</div>

			<div class="wizard-step-content">
				<div class="wizard-schema-enable">
					<label class="wizard-toggle-label-large">
						<input type="checkbox" id="schema-enabled" name="schema_enabled"
							   <?php checked($schema_enabled); ?>>
						<span>Enable Automatic Schema Markup</span>
					</label>
					<p class="wizard-field-help">
						Schema markup helps search engines display rich snippets in search results.
					</p>
				</div>

				<div class="wizard-schema-settings" style="display: <?php echo $schema_enabled ? 'block' : 'none'; ?>;">
					<h3>Default Schema Types</h3>

					<div class="wizard-schema-types">
						<label class="wizard-schema-type-card">
							<input type="radio" name="default_schema_type" value="article"
								   <?php checked(($general_settings['default_schema_type'] ?? 'article') === 'article'); ?>>
							<div class="schema-type-content">
								<span class="schema-type-icon">📰</span>
								<h4>Article</h4>
								<p>Best for blog posts and news articles</p>
							</div>
						</label>

						<label class="wizard-schema-type-card">
							<input type="radio" name="default_schema_type" value="webpage"
								   <?php checked(($general_settings['default_schema_type'] ?? '') === 'webpage'); ?>>
							<div class="schema-type-content">
								<span class="schema-type-icon">📄</span>
								<h4>WebPage</h4>
								<p>Best for general website pages</p>
							</div>
						</label>

						<label class="wizard-schema-type-card">
							<input type="radio" name="default_schema_type" value="product"
								   <?php checked(($general_settings['default_schema_type'] ?? '') === 'product'); ?>>
							<div class="schema-type-content">
								<span class="schema-type-icon">🛍️</span>
								<h4>Product</h4>
								<p>Best for e-commerce sites</p>
							</div>
						</label>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Step 7: Completion Screen
	 *
	 * @since    1.0.0
	 */
	public function render_step_complete()
	{
		$menu_slug = 'searchatlas';
		$data = Metasync::get_option('general');
		if (!empty($data['white_label_plugin_menu_slug'])) {
			$menu_slug = $data['white_label_plugin_menu_slug'];
		}
		?>
		<div class="wizard-step wizard-step-complete" data-step="6">
			<div class="wizard-step-header">
				<div class="wizard-complete-icon">🎉</div>
				<h2>Setup Complete!</h2>
				<p>Your site is now optimized for search engines.</p>
			</div>

			<div class="wizard-step-content">
				<div class="wizard-complete-summary">
					<h3>What's Next?</h3>

					<div class="wizard-next-steps">
						<div class="wizard-next-step-card">
							<span class="next-step-number">1</span>
							<div class="next-step-content">
								<h4>Explore the Dashboard</h4>
								<p>View your SEO performance metrics and insights</p>
								<a href="?page=<?php echo esc_attr($menu_slug); ?>-dashboard"
								   class="wizard-next-step-link">View Dashboard →</a>
							</div>
						</div>

						<div class="wizard-next-step-card">
							<span class="next-step-number">2</span>
							<div class="next-step-content">
								<h4>Optimize Your Content</h4>
								<p>Start optimizing individual posts and pages</p>
								<a href="<?php echo esc_url(admin_url('edit.php')); ?>"
								   class="wizard-next-step-link">View Posts →</a>
							</div>
						</div>

						<div class="wizard-next-step-card">
							<span class="next-step-number">3</span>
							<div class="next-step-content">
								<h4>Fine-Tune Settings</h4>
								<p>Adjust advanced SEO settings for your specific needs</p>
								<a href="?page=<?php echo esc_attr($menu_slug); ?>"
								   class="wizard-next-step-link">View Settings →</a>
							</div>
						</div>
					</div>
				</div>

				<div class="wizard-complete-actions">
					<button class="wizard-primary-btn wizard-complete-btn">
						Get Started with <?php echo esc_html(Metasync::get_effective_plugin_name()); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Detect installed SEO plugins
	 *
	 * @since    1.0.0
	 * @return   array
	 */
	private function detect_seo_plugins()
	{
		$detected = array();

		if (defined('WPSEO_VERSION')) {
			$detected[] = array('name' => 'Yoast SEO', 'slug' => 'yoast');
		}
		if (defined('RANK_MATH_VERSION')) {
			$detected[] = array('name' => 'Rank Math', 'slug' => 'rankmath');
		}
		if (defined('AIOSEO_VERSION')) {
			$detected[] = array('name' => 'All in One SEO', 'slug' => 'aioseo');
		}

		return $detected;
	}
}

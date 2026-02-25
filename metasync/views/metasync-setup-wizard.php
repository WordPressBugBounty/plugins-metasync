<?php
/**
 * Setup Wizard View Template
 *
 * This file is used to markup the setup wizard page of the plugin.
 *
 * @link       https://searchatlas.com
 * @since      1.0.0
 *
 * @package    Metasync
 * @subpackage Metasync/views
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
	die;
}

$plugin_name = Metasync::get_effective_plugin_name();
?>

<div class="wrap metasync-dashboard-wrap metasync-wizard-wrap">

	<!-- Wizard Header -->
	<div class="wizard-header">
		<div class="wizard-header-content">
			<h1><?php echo esc_html($plugin_name); ?> Setup Wizard</h1>
			<p>Complete your setup in just a few easy steps</p>
		</div>
		<div class="wizard-header-actions">
			<a href="?page=searchatlas" class="wizard-exit-link">Exit Setup</a>
		</div>
	</div>

	<!-- Progress Indicator -->
	<div class="wizard-progress">
		<div class="wizard-progress-bar-container">
			<div class="wizard-progress-bar" style="width: 16.66%;"></div>
		</div>
		<div class="wizard-progress-steps">
			<div class="wizard-progress-step active" data-step="1">
				<span class="step-number">1</span>
				<span class="step-label">Welcome</span>
			</div>
			<div class="wizard-progress-step" data-step="2">
				<span class="step-number">2</span>
				<span class="step-label">Connect</span>
			</div>
			<div class="wizard-progress-step" data-step="3">
				<span class="step-number">3</span>
				<span class="step-label">Import</span>
			</div>
			<div class="wizard-progress-step" data-step="4">
				<span class="step-number">4</span>
				<span class="step-label">SEO Settings</span>
			</div>
			<div class="wizard-progress-step" data-step="5">
				<span class="step-number">5</span>
				<span class="step-label">Schema</span>
			</div>
			<div class="wizard-progress-step" data-step="6">
				<span class="step-number">6</span>
				<span class="step-label">Complete</span>
			</div>
		</div>
	</div>

	<!-- Step Container -->
	<div class="wizard-steps-container">
		<?php
		// Render all wizard steps
		$this->render_step_welcome();
		$this->render_step_connection();
		$this->render_step_import();
		$this->render_step_seo_settings();
		$this->render_step_schema();
		$this->render_step_complete();
		?>
	</div>

	<!-- Navigation Footer -->
	<div class="wizard-footer">
		<button class="wizard-btn wizard-btn-prev" disabled>
			<span>←</span> Previous
		</button>

		<button class="wizard-btn wizard-btn-skip">
			Skip This Step
		</button>

		<button class="wizard-btn wizard-btn-next wizard-btn-primary">
			Next <span>→</span>
		</button>
	</div>

</div>

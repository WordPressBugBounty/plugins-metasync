<style type="text/css">
<?php
// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	exit;
}
?>
	/* Root Variables - Dashboard Color Scheme */
	:root {
		--dashboard-bg: #0f1419;
		--dashboard-card-bg: #1a1f26;
		--dashboard-card-hover: #222831;
		--dashboard-text-primary: #ffffff;
		--dashboard-text-secondary: #9ca3af;
		--dashboard-accent: #3b82f6;
		--dashboard-accent-hover: #2563eb;
		--dashboard-success: #10b981;
		--dashboard-warning: #f59e0b;
		--dashboard-error: #ef4444;
		--dashboard-border: #374151;
		--dashboard-gradient-primary: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
		--dashboard-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
		--dashboard-shadow-hover: 0 20px 25px -5px rgba(0, 0, 0, 0.4), 0 10px 10px -5px rgba(0, 0, 0, 0.2);
	}

	.column-cb {
		width: 2.2em;
	}

	.wrap .wp-list-table th.check-column,
	.wrap .wp-list-table td.check-column {
		width: 2.2em;
		padding: 8px 0 0 3px;
		vertical-align: middle;
	}

	.wrap .wp-list-table th.check-column input[type="checkbox"],
	.wrap .wp-list-table td.check-column input[type="checkbox"] {
		margin: 0;
		padding: 0;
		vertical-align: middle;
	}

	.column-uri {
		width: 35%;
	}

	.column-hits_count {
		width: 10%;
	}

	.column-date_time {
		width: 20%;
	}

	.column-user_agent {
		width: 30%;
	}

	.metasync-stats-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
		gap: 20px;
		margin-bottom: 30px;
	}

	.metasync-stat-card {
		background: var(--dashboard-card-bg);
		border: 1px solid var(--dashboard-border);
		border-radius: 16px;
		padding: 24px 20px;
		text-align: center;
		box-shadow: var(--dashboard-shadow);
		transition: all 0.3s ease;
		backdrop-filter: blur(10px);
	}

	.metasync-stat-card:hover {
		background: var(--dashboard-card-hover);
		box-shadow: var(--dashboard-shadow-hover);
		transform: translateY(-2px);
	}

	.metasync-stat-number {
		font-size: 2.5em;
		font-weight: 700;
		color: var(--dashboard-accent);
		margin-bottom: 8px;
		line-height: 1.2;
	}

	.metasync-stat-label {
		color: var(--dashboard-text-secondary);
		font-size: 14px;
		font-weight: 500;
		text-transform: uppercase;
		letter-spacing: 0.5px;
	}

	.metasync-chart-container {
		background: var(--dashboard-card-bg);
		border: 1px solid var(--dashboard-border);
		border-radius: 16px;
		padding: 24px;
		margin-bottom: 24px;
		box-shadow: var(--dashboard-shadow);
		backdrop-filter: blur(10px);
	}

	.metasync-chart-title {
		font-size: 1.5rem;
		font-weight: 600;
		margin-bottom: 20px;
		color: var(--dashboard-text-primary);
		display: flex;
		align-items: center;
		gap: 12px;
	}

	.metasync-chart-bar {
		display: flex;
		align-items: center;
		margin-bottom: 16px;
		padding: 8px 0;
	}

	.metasync-chart-bar-label {
		min-width: 200px;
		max-width: 300px;
		font-size: 13px;
		color: var(--dashboard-text-secondary);
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
		font-weight: 500;
	}

	.metasync-chart-bar-fill {
		flex: 1;
		height: 24px;
		background: rgba(255, 255, 255, 0.1);
		border-radius: 12px;
		overflow: hidden;
		margin: 0 16px;
		position: relative;
	}

	.metasync-chart-bar-progress {
		height: 100%;
		transition: width 0.3s ease, background-color 0.3s ease;
		border-radius: 12px;
		position: relative;
	}

	/* Color-coded bars based on hit count */
	.metasync-chart-bar-progress.low-hits {
		background: var(--dashboard-accent);
	}

	.metasync-chart-bar-progress.medium-hits {
		background: var(--dashboard-warning);
	}

	.metasync-chart-bar-progress.high-hits {
		background: var(--dashboard-error);
	}

	.metasync-chart-bar-value {
		width: 60px;
		text-align: right;
		font-weight: 700;
		color: var(--dashboard-text-primary);
		font-size: 14px;
	}

	/* Page background */
	body {
		background: var(--dashboard-bg) !important;
	}

	.wrap {
		background: var(--dashboard-bg) !important;
		color: var(--dashboard-text-primary) !important;
	}

	/* Page headers and content */
	.wp-heading-inline {
		color: var(--dashboard-text-primary) !important;
	}

	h1, h2, h3 {
		color: var(--dashboard-text-primary) !important;
	}

	/* Enhanced table styling with higher specificity */
	.wrap .wp-list-table {
		background: var(--dashboard-card-bg) !important;
		border: 1px solid var(--dashboard-border) !important;
		border-radius: 12px !important;
		overflow: hidden !important;
		box-shadow: var(--dashboard-shadow) !important;
	}

	.wrap .wp-list-table th {
		background: var(--dashboard-card-hover) !important;
		color: var(--dashboard-text-primary) !important;
		border-bottom: 1px solid var(--dashboard-border) !important;
		font-weight: 600 !important;
	}

	.wrap .wp-list-table td {
		background: var(--dashboard-card-bg) !important;
		border-bottom: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-secondary) !important;
	}

	.wrap .wp-list-table tr:hover td {
		background: var(--dashboard-card-hover) !important;
		color: var(--dashboard-text-primary) !important;
	}

	.wrap .wp-list-table tr:nth-child(even) td {
		background: var(--dashboard-card-bg) !important;
	}

	.wrap .wp-list-table tr:nth-child(odd) td {
		background: rgba(255, 255, 255, 0.05) !important;
	}

	.wrap .wp-list-table tr:nth-child(even):hover td {
		background: var(--dashboard-card-hover) !important;
		color: var(--dashboard-text-primary) !important;
	}

	.wrap .wp-list-table tr:nth-child(odd):hover td {
		background: var(--dashboard-card-hover) !important;
		color: var(--dashboard-text-primary) !important;
	}

	/* Optimized table row backgrounds - alternating pattern for all rows */
	.wrap .wp-list-table tbody tr:nth-child(odd) td {
		background: var(--dashboard-card-bg) !important;
	}

	.wrap .wp-list-table tbody tr:nth-child(even) td {
		background: rgba(255, 255, 255, 0.05) !important;
	}

	/* Ensure hover states work correctly */
	.wrap .wp-list-table tbody tr:nth-child(odd):hover td {
		background: var(--dashboard-card-hover) !important;
		color: var(--dashboard-text-primary) !important;
	}

	.wrap .wp-list-table tbody tr:nth-child(even):hover td {
		background: var(--dashboard-card-hover) !important;
		color: var(--dashboard-text-primary) !important;
	}

	/* Button styling with higher specificity */
	.wrap .button-primary, 
	.wrap .button-primary:visited,
	.wrap input[type="submit"].button-primary,
	.wrap input[type="button"].button-primary {
		background: var(--dashboard-accent) !important;
		border-color: var(--dashboard-accent) !important;
		color: white !important;
		border-radius: 8px !important;
		font-weight: 500 !important;
		transition: all 0.3s ease !important;
		text-decoration: none !important;
	}

	.wrap .button-primary:hover, 
	.wrap .button-primary:focus,
	.wrap input[type="submit"].button-primary:hover,
	.wrap input[type="button"].button-primary:hover {
		background: var(--dashboard-accent-hover) !important;
		border-color: var(--dashboard-accent-hover) !important;
		transform: translateY(-1px) !important;
		box-shadow: var(--dashboard-shadow-hover) !important;
		color: white !important;
		text-decoration: none !important;
	}

	.wrap .button-secondary, 
	.wrap .button-secondary:visited,
	.wrap input[type="submit"].button-secondary,
	.wrap input[type="button"].button-secondary {
		background: transparent !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-secondary) !important;
		border-radius: 8px !important;
		transition: all 0.3s ease !important;
		text-decoration: none !important;
	}

	.wrap .button-secondary:hover, 
	.wrap .button-secondary:focus,
	.wrap input[type="submit"].button-secondary:hover,
	.wrap input[type="button"].button-secondary:hover {
		background: var(--dashboard-card-hover) !important;
		color: var(--dashboard-text-primary) !important;
		border-color: var(--dashboard-accent) !important;
		text-decoration: none !important;
	}

	/* Specific button targeting for 404 monitor */
	.wrap input[type="submit"]#doaction,
	.wrap input[type="submit"]#post-query-submit,
	.wrap input[type="submit"]#search-submit,
	.wrap input[type="submit"].button,
	.wrap input[type="submit"][value="Apply"],
	.wrap input[type="submit"][value="Filter"],
	.wrap input[type="submit"][value="Search"] {
		background: var(--dashboard-accent) !important;
		border-color: var(--dashboard-accent) !important;
		color: white !important;
		border-radius: 8px !important;
		font-weight: 500 !important;
		transition: all 0.3s ease !important;
		padding: 8px 16px !important;
		cursor: pointer !important;
	}

	.wrap input[type="submit"]#doaction:hover,
	.wrap input[type="submit"]#post-query-submit:hover,
	.wrap input[type="submit"]#search-submit:hover,
	.wrap input[type="submit"].button:hover,
	.wrap input[type="submit"][value="Apply"]:hover,
	.wrap input[type="submit"][value="Filter"]:hover,
	.wrap input[type="submit"][value="Search"]:hover {
		background: var(--dashboard-accent-hover) !important;
		border-color: var(--dashboard-accent-hover) !important;
		transform: translateY(-1px) !important;
		box-shadow: var(--dashboard-shadow-hover) !important;
		color: white !important;
	}

	/* Link button styling */
	.wrap a.button, 
	.wrap a.button:visited {
		background: var(--dashboard-accent) !important;
		border-color: var(--dashboard-accent) !important;
		color: white !important;
		border-radius: 8px !important;
		font-weight: 500 !important;
		transition: all 0.3s ease !important;
		text-decoration: none !important;
		display: inline-block !important;
		padding: 8px 16px !important;
	}

	.wrap a.button:hover, 
	.wrap a.button:focus {
		background: var(--dashboard-accent-hover) !important;
		border-color: var(--dashboard-accent-hover) !important;
		transform: translateY(-1px) !important;
		box-shadow: var(--dashboard-shadow-hover) !important;
		color: white !important;
		text-decoration: none !important;
	}

	/* Form elements */
	.wrap select, 
	.wrap input[type="text"], 
	.wrap input[type="search"],
	.wrap input[type="date"] {
		background: var(--dashboard-card-bg) !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 8px !important;
		padding: 6px 8px !important;
	}

	.wrap select:focus, 
	.wrap input[type="text"]:focus, 
	.wrap input[type="search"]:focus,
	.wrap input[type="date"]:focus {
		border-color: var(--dashboard-accent) !important;
		box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
		outline: none !important;
	}

	/* Tablenav styling for 404 monitor */
	.wrap .tablenav {
		background: var(--dashboard-card-bg);
		border: 1px solid var(--dashboard-border);
		border-radius: 8px;
		padding: 12px 16px;
		margin-bottom: 16px;
		box-shadow: var(--dashboard-shadow);
		display: flex !important;
		align-items: center !important;
		justify-content: space-between !important;
		flex-wrap: wrap !important;
		gap: 12px !important;
	}

	.wrap .tablenav .actions {
		display: flex !important;
		align-items: center !important;
		gap: 12px !important;
		flex-wrap: wrap !important;
	}

	.wrap .tablenav .alignleft {
		display: flex !important;
		align-items: center !important;
		gap: 12px !important;
		flex-wrap: wrap !important;
	}

	.wrap .tablenav .alignright {
		display: flex !important;
		align-items: center !important;
		gap: 12px !important;
		margin-left: auto !important;
	}

	.wrap .tablenav select {
		background: var(--dashboard-card-bg) !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 6px !important;
		padding: 6px 8px !important;
		height: 32px !important;
		vertical-align: middle !important;
		text-align: left !important;
		line-height: 1.4 !important;
		font-size: 13px !important;
		font-weight: 500 !important;
		appearance: none !important;
		-moz-appearance: none !important;
		-webkit-appearance: none !important;
		background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e") !important;
		background-repeat: no-repeat !important;
		background-position: right 8px center !important;
		background-size: 16px !important;
		padding-right: 32px !important;
		cursor: pointer !important;
	}

	.wrap .tablenav select:focus {
		border-color: var(--dashboard-accent) !important;
		box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
		outline: none !important;
	}

	.wrap .tablenav select option {
		background: var(--dashboard-card-bg) !important;
		color: var(--dashboard-text-primary) !important;
		padding: 8px !important;
		font-size: 13px !important;
		font-weight: 500 !important;
	}

	.wrap .tablenav input[type="search"],
	.wrap .tablenav input[type="text"],
	.wrap .tablenav input[type="date"] {
		background: var(--dashboard-card-bg) !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 6px !important;
		padding: 6px 12px !important;
		height: 32px !important;
		vertical-align: middle !important;
	}

	.wrap .tablenav input[type="submit"] {
		height: 32px !important;
		vertical-align: middle !important;
		line-height: 1 !important;
		padding: 6px 12px !important;
	}

	.wrap .tablenav .search-box {
		margin: 0 !important;
		display: flex !important;
		align-items: center !important;
		gap: 8px !important;
		height: 32px !important;
		padding: 0 !important;
		border: none !important;
		background: transparent !important;
	}

	.wrap .tablenav .search-box input[type="search"] {
		margin: 0 !important;
		height: 32px !important;
		vertical-align: middle !important;
		line-height: 1 !important;
		box-sizing: border-box !important;
		padding: 8px 12px !important;
		border: 1px solid var(--dashboard-border) !important;
		background: var(--dashboard-card-bg) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 6px !important;
		font-size: 13px !important;
	}

	/* Specific search button alignment */
	.wrap .tablenav .search-box input[type="submit"] {
		height: 32px !important;
		vertical-align: middle !important;
		line-height: 1 !important;
		padding: 8px 12px !important;
		box-sizing: border-box !important;
		margin: 0 !important;
		border-radius: 6px !important;
		font-size: 13px !important;
	}

	/* Additional targeting for WordPress default styles */
	.wrap .tablenav p.search-box {
		display: flex !important;
		align-items: center !important;
		gap: 8px !important;
		height: 32px !important;
		margin: 0 !important;
		padding: 0 !important;
		line-height: 1 !important;
	}

	.wrap .tablenav p.search-box input {
		margin: 0 !important;
		vertical-align: middle !important;
		line-height: 1 !important;
	}

	/* Specific ID targeting for search elements - fixed padding */
	.wrap .tablenav #post-search-input {
		height: 32px !important;
		line-height: 1 !important;
		vertical-align: middle !important;
		margin: 0 !important;
		padding: 8px 12px !important;
		border: 1px solid var(--dashboard-border) !important;
		background: var(--dashboard-card-bg) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 6px !important;
		font-size: 13px !important;
		box-sizing: border-box !important;
	}

	.wrap .tablenav #search-submit {
		height: 32px !important;
		line-height: 1 !important;
		vertical-align: middle !important;
		margin: 0 !important;
		padding: 8px 12px !important;
		border-radius: 6px !important;
		font-size: 13px !important;
		box-sizing: border-box !important;
		display: inline-block !important;
	}

	/* Force alignment with transform if needed */
	.wrap .tablenav .search-box {
		transform: translateY(0) !important;
	}

	.wrap .tablenav .search-box input {
		transform: translateY(0) !important;
	}

	/* Item count styling - fixed alignment */
	.wrap .tablenav .displaying-num {
		color: var(--dashboard-text-primary) !important;
		font-size: 13px !important;
		font-weight: 500 !important;
		background: var(--dashboard-card-hover) !important;
		padding: 6px 12px !important;
		border-radius: 6px !important;
		border: 1px solid var(--dashboard-border) !important;
		margin: 0 !important;
		display: inline-block !important;
		vertical-align: middle !important;
		line-height: 1 !important;
		height: 32px !important;
		box-sizing: border-box !important;
	}

	/* Alternative item count selectors - maintain inline flow */
	.wrap .tablenav .alignright {
		display: flex !important;
		align-items: center !important;
		gap: 12px !important;
		margin-left: auto !important;
	}

	/* WordPress pagination controls - ensure right alignment */
	.wrap .tablenav-pages {
		display: flex !important;
		align-items: center !important;
		gap: 8px !important;
		margin-left: auto !important;
		justify-content: flex-end !important;
	}


	.wrap .tablenav-pages .displaying-num {
		color: var(--dashboard-text-primary) !important;
		font-size: 13px !important;
		font-weight: 500 !important;
		background: var(--dashboard-card-hover) !important;
		padding: 6px 12px !important;
		border-radius: 6px !important;
		border: 1px solid var(--dashboard-border) !important;
		margin-right: 12px !important;
		white-space: nowrap !important;
	}

	.wrap .tablenav .alignright p,
	.wrap .tablenav .alignright span {
		color: var(--dashboard-text-primary) !important;
		font-size: 13px !important;
		font-weight: 500 !important;
		margin: 0 !important;
		background: var(--dashboard-card-hover) !important;
		padding: 0 !important;
		border-radius: 6px !important;
		border: 1px solid var(--dashboard-border) !important;
		display: inline-block !important;
		vertical-align: middle !important;
		line-height: 1 !important;
		height: 32px !important;
		box-sizing: border-box !important;
	}

	/* Ensure all buttons in tablenav are properly aligned */
	.wrap .tablenav .button,
	.wrap .tablenav input[type="submit"],
	.wrap .tablenav input[type="button"] {
		vertical-align: middle !important;
		height: 32px !important;
		line-height: 1 !important;
		display: inline-flex !important;
		align-items: center !important;
		justify-content: center !important;
		text-align: center !important;
		font-size: 13px !important;
		font-weight: 500 !important;
	}

	/* Specific fix for Apply button text alignment */
	.wrap .tablenav input[type="submit"][value="Apply"] {
		text-align: center !important;
		vertical-align: middle !important;
		line-height: 1 !important;
		padding: 6px 12px !important;
		display: inline-flex !important;
		align-items: center !important;
		justify-content: center !important;
	}

	/* Fix for Min Hits input field */
	.wrap .tablenav input[name="min_hits"],
	.wrap .tablenav input[type="number"] {
		background: var(--dashboard-card-bg) !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 6px !important;
		padding: 6px 12px !important;
		height: 32px !important;
		vertical-align: middle !important;
		width: auto !important;
		min-width: 80px !important;
	}

	.wrap .tablenav input[name="min_hits"]:focus,
	.wrap .tablenav input[type="number"]:focus {
		border-color: var(--dashboard-accent) !important;
		box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
		outline: none !important;
	}

	/* Additional styling for 404 monitor specific elements */
	.wrap .metasync-stats-grid {
		margin-bottom: 24px !important;
	}

	.wrap .metasync-chart-container {
		margin-bottom: 24px !important;
	}

	/* Description text styling */
	.wrap .description,
	.wrap p.description {
		color: var(--dashboard-text-secondary, #9ca3af) !important;
		font-size: 13px !important;
		line-height: 1.5 !important;
	}
</style>

<div class="wrap">
	<h1 class="wp-heading-inline">404 Error Monitor</h1>
	
	<?php
	// Get 404 statistics
	$stats = $this->database->get_404_statistics();
	?>
	
	<!-- Statistics Dashboard -->
	<div class="metasync-stats-grid">
		<div class="metasync-stat-card">
			<div class="metasync-stat-number"><?php echo intval($stats['total_errors']); ?></div>
			<div class="metasync-stat-label">Total 404 Errors</div>
		</div>
		<div class="metasync-stat-card">
			<div class="metasync-stat-number"><?php echo intval($stats['total_hits']); ?></div>
			<div class="metasync-stat-label">Total Hits</div>
		</div>
		<div class="metasync-stat-card">
			<div class="metasync-stat-number"><?php echo intval($stats['recent_errors']); ?></div>
			<div class="metasync-stat-label">Last 24 Hours</div>
		</div>
		<div class="metasync-stat-card">
			<div class="metasync-stat-number"><?php echo count($stats['most_frequent']); ?></div>
			<div class="metasync-stat-label">Frequent Errors</div>
		</div>
	</div>

	<!-- Charts Section -->
	<div class="metasync-chart-container">
		<div class="metasync-chart-title">Most Frequent 404 Errors</div>
		<?php if (!empty($stats['most_frequent'])): ?>
			<?php
			$max_hits = max(array_column($stats['most_frequent'], 'hits_count'));
			foreach ($stats['most_frequent'] as $error):
				$percentage = $max_hits > 0 ? ($error->hits_count / $max_hits) * 100 : 0;
				$hits = intval($error->hits_count);

				// Determine color class based on hit count
				$color_class = 'low-hits';
				if ($hits >= 10) {
					$color_class = 'high-hits'; // Red for high hits
				} elseif ($hits >= 5) {
					$color_class = 'medium-hits'; // Yellow for medium hits
				}
			?>
			<div class="metasync-chart-bar">
				<div class="metasync-chart-bar-label" title="<?php echo esc_attr($error->uri); ?>">
					<?php echo esc_html(substr($error->uri, 0, 60) . (strlen($error->uri) > 60 ? '...' : '')); ?>
				</div>
				<div class="metasync-chart-bar-fill">
					<div class="metasync-chart-bar-progress <?php echo esc_attr($color_class); ?>" style="width: <?php echo $percentage; ?>%"></div>
				</div>
				<div class="metasync-chart-bar-value"><?php echo $hits; ?></div>
			</div>
			<?php endforeach; ?>
		<?php else: ?>
			<p>No 404 errors found.</p>
		<?php endif; ?>
	</div>

	<!-- 404 Errors List -->
	<form id="404-monitor-form" method="post">
		<?php wp_nonce_field('metasync_404_monitor_form'); ?>
		
		<!-- Search and Filter Controls -->
		<div class="tablenav top">
			
			<div class="alignleft actions">
				<label for="date-from-filter" class="screen-reader-text">Filter by date from</label>
				<input type="date" name="date_from" id="date-from-filter" 
					   value="<?php echo esc_attr(isset($_REQUEST['date_from']) ? $_REQUEST['date_from'] : ''); ?>" 
					   placeholder="From Date">
				
				<label for="date-to-filter" class="screen-reader-text">Filter by date to</label>
				<input type="date" name="date_to" id="date-to-filter" 
					   value="<?php echo esc_attr(isset($_REQUEST['date_to']) ? $_REQUEST['date_to'] : ''); ?>" 
					   placeholder="To Date">
				
				<label for="min-hits-filter" class="screen-reader-text">Filter by minimum hits</label>
				<input type="number" name="min_hits" id="min-hits-filter" 
					   value="<?php echo esc_attr(isset($_REQUEST['min_hits']) ? $_REQUEST['min_hits'] : ''); ?>" 
					   placeholder="Min Hits" min="1">
				
				<input type="submit" name="filter_action" id="post-query-submit" class="button" value="Filter">
			</div>
			
			<div class="alignright">
				<p class="search-box">
					<label class="screen-reader-text" for="post-search-input">Search 404 Errors:</label>
					<input type="search" id="post-search-input" name="s" 
						   value="<?php echo esc_attr(isset($_REQUEST['s']) ? $_REQUEST['s'] : ''); ?>" 
						   placeholder="Search URIs or User Agents...">
					<input type="submit" id="search-submit" class="button" value="Search">
				</p>
			</div>
		</div>
		
		<!-- Now we can render the completed list table -->
		<?php $Metasync404Monitor->display() ?>
	</form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit filters when changed
    const filterInputs = document.querySelectorAll('#date-from-filter, #date-to-filter, #min-hits-filter');
    filterInputs.forEach(function(input) {
        input.addEventListener('change', function() {
            document.getElementById('404-monitor-form').submit();
        });
    });

    // Add confirmation for bulk actions
    const bulkActionForm = document.getElementById('404-monitor-form');
    if (bulkActionForm) {
        bulkActionForm.addEventListener('submit', function(e) {
            const actionSelect = document.getElementById('bulk-action-selector-top');
            if (actionSelect.value === 'empty') {
                if (!confirm('Are you sure you want to empty all 404 error logs? This action cannot be undone.')) {
                    e.preventDefault();
                }
            } else if (actionSelect.value === 'delete_bulk') {
                const checkedBoxes = document.querySelectorAll('input[name="item[]"]:checked');
                if (checkedBoxes.length > 0) {
                    if (!confirm('Are you sure you want to delete the selected 404 errors?')) {
                        e.preventDefault();
                    }
                }
            }
        });
    }

    // Add tab parameter to all pagination links in 404-monitor tab
    function addTabToPaginationLinks() {
        const urlParams = new URLSearchParams(window.location.search);
        const currentTab = urlParams.get('tab') || 'redirections';

        // Find all pagination links within 404-monitor-content
        const monitorContent = document.getElementById('404-monitor-content');
        if (monitorContent) {
            const paginationLinks = monitorContent.querySelectorAll('.tablenav-pages a');
            paginationLinks.forEach(function(link) {
                const url = new URL(link.href);
                url.searchParams.set('tab', '404-monitor');
                link.href = url.toString();
            });
        }
    }

    // Run immediately and after a short delay
    addTabToPaginationLinks();
    setTimeout(addTabToPaginationLinks, 100);
    setTimeout(addTabToPaginationLinks, 500);
});
</script>
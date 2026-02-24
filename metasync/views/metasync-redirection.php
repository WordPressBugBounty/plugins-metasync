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

	.column-sources_from {
		width: 25%;
	}

	.column-url_redirect_to {
		width: 25%;
	}

	.column-http_code {
		width: 10%;
	}

	.column-pattern_type {
		width: 12%;
	}

	.column-hits_count {
		width: 8%;
	}

	.column-status {
		width: 10%;
	}

	.column-last_accessed_at {
		width: 10%;
	}

	/* Page background */
	body {
		background: var(--dashboard-bg) !important;
	}

	.wrap {
		background: var(--dashboard-bg) !important;
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

	/* Specific button targeting */
	.wrap input[type="submit"]#doaction,
	.wrap input[type="submit"]#post-query-submit,
	.wrap input[type="submit"]#search-submit,
	.wrap input[type="submit"].button {
		background: var(--dashboard-accent) !important;
		border-color: var(--dashboard-accent) !important;
		color: white !important;
		border-radius: 8px !important;
		font-weight: 500 !important;
		transition: all 0.3s ease !important;
	}

	.wrap input[type="submit"]#doaction:hover,
	.wrap input[type="submit"]#post-query-submit:hover,
	.wrap input[type="submit"]#search-submit:hover,
	.wrap input[type="submit"].button:hover {
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

	/* Add Redirection Form Styling */
	.wrap #add-redirection-form {
		background: var(--dashboard-card-bg) !important;
		border: 1px solid var(--dashboard-border) !important;
		border-radius: 12px !important;
		padding: 24px !important;
		margin-bottom: 24px !important;
		box-shadow: var(--dashboard-shadow) !important;
	}

	.wrap #add-redirection-form h1 {
		color: var(--dashboard-text-primary) !important;
		margin-bottom: 20px !important;
		font-size: 1.5rem !important;
		font-weight: 600 !important;
	}

	.wrap #add-redirection-form .form-table {
		background: transparent !important;
		border: none !important;
	}

	.wrap #add-redirection-form .form-table th {
		background: transparent !important;
		color: var(--dashboard-text-primary) !important;
		font-weight: 600 !important;
		padding: 12px 0 !important;
		width: 150px !important;
	}

	.wrap #add-redirection-form .form-table td {
		background: transparent !important;
		color: var(--dashboard-text-secondary) !important;
		padding: 15px !important;
	}

	.wrap #add-redirection-form input[type="text"],
	.wrap #add-redirection-form input[type="url"],
	.wrap #add-redirection-form textarea {
		background: var(--dashboard-card-hover) !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 8px !important;
		padding: 8px 12px !important;
		width: 100% !important;
		max-width: 500px !important;
	}

	.wrap #add-redirection-form input[type="text"]:focus,
	.wrap #add-redirection-form input[type="url"]:focus,
	.wrap #add-redirection-form textarea:focus {
		border-color: var(--dashboard-accent) !important;
		box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
		outline: none !important;
	}

	.wrap #add-redirection-form textarea {
		min-height: 80px !important;
		resize: vertical !important;
	}

	.wrap #add-redirection-form input[type="radio"] {
		margin-right: 8px !important;
		accent-color: var(--dashboard-accent) !important;
	}

	.wrap #add-redirection-form label {
		color: var(--dashboard-text-secondary) !important;
		margin-right: 16px !important;
		font-weight: 500 !important;
	}

	.wrap #add-redirection-form .source-url-list {
		background: var(--dashboard-card-hover) !important;
		border: 1px solid var(--dashboard-border) !important;
		border-radius: 8px !important;
		padding: 12px !important;
		margin: 8px 0 !important;
	}

	.wrap #add-redirection-form .source-url-list li {
		background: transparent !important;
		border: none !important;
		padding: 8px 0 !important;
		margin: 0 !important;
		display: flex !important;
		align-items: center !important;
		gap: 12px !important;
	}

	.wrap #add-redirection-form .source-url-list input[type="text"] {
		flex: 1 !important;
		max-width: none !important;
		margin: 0 !important;
	}

	/* Target the actual select elements */
	.wrap #add-redirection-form select[name="search_type[]"],
	.wrap #add-redirection-form select {
		background: var(--dashboard-card-hover) !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 6px !important;
		padding: 6px 8px !important;
		min-width: 120px !important;
		appearance: none !important;
		-moz-appearance: none !important;
		-webkit-appearance: none !important;
		background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23ffffff' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e") !important;
		background-repeat: no-repeat !important;
		background-position: right 8px center !important;
		background-size: 16px !important;
		padding-right: 32px !important;
		cursor: pointer !important;
		margin-left: 8px !important;
	}

	.wrap #add-redirection-form select[name="search_type[]"]:focus,
	.wrap #add-redirection-form select:focus {
		border-color: var(--dashboard-accent) !important;
		box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
		outline: none !important;
	}

	.wrap #add-redirection-form select[name="search_type[]"] option,
	.wrap #add-redirection-form select option {
		background: var(--dashboard-card-hover) !important;
		color: var(--dashboard-text-primary) !important;
		padding: 8px !important;
	}

	/* Target the actual remove button class */
	.wrap #add-redirection-form .source_url_delete,
	.wrap #add-redirection-form button.source_url_delete {
		background: var(--dashboard-error) !important;
		border: 1px solid var(--dashboard-error) !important;
		color: white !important;
		border-radius: 6px !important;
		padding: 6px 12px !important;
		font-size: 12px !important;
		font-weight: 500 !important;
		transition: all 0.3s ease !important;
		cursor: pointer !important;
		display: inline-block !important;
		margin-left: 8px !important;
	}

	.wrap #add-redirection-form .source_url_delete:hover,
	.wrap #add-redirection-form button.source_url_delete:hover {
		background: #dc2626 !important;
		border-color: #dc2626 !important;
		transform: translateY(-1px) !important;
		color: white !important;
	}

	.wrap #add-redirection-form .add-source-url {
		background: var(--dashboard-accent) !important;
		border: 1px solid var(--dashboard-accent) !important;
		color: white !important;
		border-radius: 8px !important;
		padding: 8px 16px !important;
		font-weight: 500 !important;
		transition: all 0.3s ease !important;
		margin-top: 8px !important;
	}

	.wrap #add-redirection-form .add-source-url:hover {
		background: var(--dashboard-accent-hover) !important;
		border-color: var(--dashboard-accent-hover) !important;
		transform: translateY(-1px) !important;
		box-shadow: var(--dashboard-shadow-hover) !important;
	}

	.wrap #add-redirection-form .form-actions {
		background: transparent !important;
		border: none !important;
		padding: 20px 0 0 0 !important;
		margin-top: 20px !important;
		border-top: 1px solid var(--dashboard-border) !important;
	}

	.wrap #add-redirection-form .form-actions input[type="submit"] {
		background: var(--dashboard-accent) !important;
		border: 1px solid var(--dashboard-accent) !important;
		color: white !important;
		border-radius: 8px !important;
		padding: 10px 20px !important;
		font-weight: 500 !important;
		margin-right: 12px !important;
		transition: all 0.3s ease !important;
	}

	.wrap #add-redirection-form .form-actions input[type="submit"]:hover {
		background: var(--dashboard-accent-hover) !important;
		border-color: var(--dashboard-accent-hover) !important;
		transform: translateY(-1px) !important;
		box-shadow: var(--dashboard-shadow-hover) !important;
	}

	.wrap #add-redirection-form .form-actions input[type="button"] {
		background: transparent !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-secondary) !important;
		border-radius: 8px !important;
		padding: 10px 20px !important;
		font-weight: 500 !important;
		transition: all 0.3s ease !important;
	}

	.wrap #add-redirection-form .form-actions input[type="button"]:hover {
		background: var(--dashboard-card-hover) !important;
		color: var(--dashboard-text-primary) !important;
		border-color: var(--dashboard-accent) !important;
	}

	/* Additional form element styling */
	.wrap #add-redirection-form input[type="radio"]:checked {
		accent-color: var(--dashboard-accent) !important;
	}

	.wrap #add-redirection-form input[type="radio"]:checked + label {
		color: var(--dashboard-text-primary) !important;
		font-weight: 600 !important;
	}

	/* Ensure all buttons in the form are properly styled */
	.wrap #add-redirection-form button,
	.wrap #add-redirection-form input[type="button"],
	.wrap #add-redirection-form input[type="submit"] {
		font-family: inherit !important;
		font-size: 14px !important;
		line-height: 1.4 !important;
		vertical-align: middle !important;
	}

	/* Fix any remaining button styling issues */
	.wrap #add-redirection-form .button,
	.wrap #add-redirection-form .button-secondary {
		background: transparent !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-secondary) !important;
		border-radius: 8px !important;
		padding: 8px 16px !important;
		font-weight: 500 !important;
		transition: all 0.3s ease !important;
		text-decoration: none !important;
		display: inline-block !important;
		cursor: pointer !important;
	}

	.wrap #add-redirection-form .button:hover,
	.wrap #add-redirection-form .button-secondary:hover {
		background: var(--dashboard-card-hover) !important;
		color: var(--dashboard-text-primary) !important;
		border-color: var(--dashboard-accent) !important;
		text-decoration: none !important;
	}

	/* Additional targeting for form elements */
	.wrap #add-redirection-form #source_urls li {
		display: flex !important;
		align-items: center !important;
		gap: 8px !important;
		margin-bottom: 8px !important;
		padding: 8px !important;
		background: var(--dashboard-card-hover) !important;
		border-radius: 8px !important;
		border: 1px solid var(--dashboard-border) !important;
	}

	.wrap #add-redirection-form #source_urls li input[type="text"] {
		flex: 1 !important;
		background: var(--dashboard-card-bg) !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 6px !important;
		padding: 6px 8px !important;
	}

	/* Force override for any conflicting styles */
	.wrap #add-redirection-form * {
		box-sizing: border-box !important;
	}

	/* Page headers and content */
	.wp-heading-inline {
		color: var(--dashboard-text-primary) !important;
	}

	h1, h2, h3 {
		color: var(--dashboard-text-primary) !important;
	}

	/* Tablenav styling */
	.wrap .tablenav {
		background: var(--dashboard-card-bg);
		border: 1px solid var(--dashboard-border);
		border-radius: 8px;
		padding: 18px 24px;
		margin-bottom: 16px;
		box-shadow: var(--dashboard-shadow);
		display: flex !important;
		align-items: center !important;
		justify-content: space-between !important;
		flex-wrap: nowrap !important;
		gap: 18px !important;
		min-height: 64px !important;
		overflow-x: auto !important;
		overflow-y: hidden !important;
	}

	.wrap .tablenav .actions {
		display: flex !important;
		align-items: center !important;
		gap: 12px !important;
		flex-wrap: nowrap !important;
		flex-shrink: 0 !important;
		min-width: 0 !important;
	}

	.wrap .tablenav .alignleft {
		display: flex !important;
		align-items: center !important;
		gap: 12px !important;
		flex-wrap: nowrap !important;
		flex-shrink: 0 !important;
		min-width: 0 !important;
	}

	.wrap .tablenav .alignright {
		display: flex !important;
		align-items: center !important;
		gap: 12px !important;
		margin-left: auto !important;
		flex-shrink: 0 !important;
		min-width: 0 !important;
	}

	.wrap .tablenav select {
		background: var(--dashboard-card-bg) !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 6px !important;
		padding: 10px 16px !important;
		height: 40px !important;
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
		background-position: right 12px center !important;
		background-size: 16px !important;
		padding-right: 40px !important;
		cursor: pointer !important;
		width: 140px !important;
		flex-shrink: 0 !important;
		white-space: nowrap !important;
		overflow: hidden !important;
		text-overflow: ellipsis !important;
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
	.wrap .tablenav input[type="text"] {
		background: var(--dashboard-card-bg) !important;
		border: 1px solid var(--dashboard-border) !important;
		color: var(--dashboard-text-primary) !important;
		border-radius: 6px !important;
		padding: 10px 16px !important;
		height: 40px !important;
		vertical-align: middle !important;
		width: 250px !important;
		flex-shrink: 0 !important;
		white-space: nowrap !important;
		overflow: hidden !important;
		text-overflow: ellipsis !important;
	}

	.wrap .tablenav input[type="submit"] {
		height: 40px !important;
		vertical-align: middle !important;
		line-height: 1 !important;
		padding: 10px 20px !important;
		flex-shrink: 0 !important;
		white-space: nowrap !important;
		width: auto !important;
		min-width: 100px !important;
	}

	.wrap .tablenav .search-box {
		margin: 0 !important;
		display: flex !important;
		align-items: center !important;
		gap: 12px !important;
		height: 40px !important;
		padding: 0 !important;
		border: none !important;
		background: transparent !important;
		flex-shrink: 0 !important;
		white-space: nowrap !important;
		min-width: 0 !important;
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
		margin-left: 15px !important;
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

	/* Fix for number input fields */
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

	.wrap .tablenav input[type="number"]:focus {
		border-color: var(--dashboard-accent) !important;
		box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
		outline: none !important;
	}

	/* Page title action styling */
	.page-title-action {
		background: var(--dashboard-accent) !important;
		border-color: var(--dashboard-accent) !important;
		color: white !important;
		border-radius: 8px !important;
		font-weight: 500 !important;
		transition: all 0.3s ease !important;
		text-decoration: none !important;
		padding: 8px 16px !important;
		display: inline-flex !important;
		align-items: center !important;
		gap: 8px !important;
	}

	.page-title-action:hover {
		background: var(--dashboard-accent-hover) !important;
		border-color: var(--dashboard-accent-hover) !important;
		transform: translateY(-1px);
		box-shadow: var(--dashboard-shadow-hover) !important;
		color: white !important;
	}

	/* Status indicators */
	.status-active {
		color: var(--dashboard-success) !important;
		font-weight: 600;
	}

	.status-inactive {
		color: var(--dashboard-text-secondary) !important;
		font-weight: 600;
	}

	/* HTTP code styling */
	.http-code-301, .http-code-302, .http-code-307 {
		color: var(--dashboard-accent) !important;
		font-weight: 600;
	}

	.http-code-410, .http-code-451 {
		color: var(--dashboard-warning) !important;
		font-weight: 600;
	}

	/* Fix for duplicate table elements */
	.wrap .wp-list-table + .wp-list-table {
		display: none !important;
	}

	/* Hide duplicate bulk actions */
	.wrap .tablenav + .tablenav {
		display: none !important;
	}

	/* Ensure only one table header is visible */
	.wrap .wp-list-table thead:not(:first-of-type) {
		display: none !important;
	}

	/* Force no line breaks - aggressive approach */
	.wrap .tablenav * {
		box-sizing: border-box !important;
	}

	.wrap .tablenav .alignleft > *,
	.wrap .tablenav .alignright > *,
	.wrap .tablenav .actions > * {
		display: inline-block !important;
		vertical-align: middle !important;
		margin: 0 !important;
	}

	/* Specific element spacing */
	.wrap .tablenav label {
		margin-right: 12px !important;
		margin-left: 4px !important;
		padding: 8px 4px !important;
		white-space: nowrap !important;
		flex-shrink: 0 !important;
		font-weight: 500 !important;
		color: var(--dashboard-text-primary) !important;
		display: inline-block !important;
		vertical-align: middle !important;
	}

	/* Responsive behavior for smaller screens */
	@media (max-width: 1400px) {
		.wrap .tablenav {
			overflow-x: auto !important;
			overflow-y: hidden !important;
			padding: 16px 20px !important;
		}
		
		.wrap .tablenav select {
			width: 120px !important;
			padding: 8px 12px !important;
			height: 36px !important;
		}
		
		.wrap .tablenav input[type="search"],
		.wrap .tablenav input[type="text"] {
			width: 200px !important;
			padding: 8px 12px !important;
			height: 36px !important;
		}
		
		.wrap .tablenav input[type="submit"] {
			padding: 8px 16px !important;
			height: 36px !important;
		}
	}

	@media (max-width: 1200px) {
		.wrap .tablenav {
			overflow-x: auto !important;
			overflow-y: hidden !important;
			padding: 14px 18px !important;
		}
		
		.wrap .tablenav select {
			width: 100px !important;
			padding: 6px 10px !important;
			height: 32px !important;
		}
		
		.wrap .tablenav input[type="search"],
		.wrap .tablenav input[type="text"] {
			width: 180px !important;
			padding: 6px 10px !important;
			height: 32px !important;
		}
		
		.wrap .tablenav input[type="submit"] {
			padding: 6px 12px !important;
			height: 32px !important;
		}
	}

	@media (max-width: 768px) {
		.wrap .tablenav {
			flex-direction: column !important;
			align-items: stretch !important;
			gap: 12px !important;
		}
		
		.wrap .tablenav .alignleft,
		.wrap .tablenav .alignright {
			justify-content: center !important;
			margin: 0 !important;
		}
		
		.wrap .tablenav select,
		.wrap .tablenav input[type="search"],
		.wrap .tablenav input[type="text"],
		.wrap .tablenav input[type="submit"] {
			width: 100% !important;
			max-width: none !important;
			min-width: auto !important;
		}
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
	<a href="<?php echo esc_url(admin_url('admin.php?page=searchatlas-redirections&action=add')); ?>" class="page-title-action">Add New Redirect</a>
	
	<!-- Import from SEO Plugins Button -->
	<a href="<?php echo esc_url(admin_url('admin.php?page=metasync-import-external&tab=redirections')); ?>" class="page-title-action" style="background: #10b981 !important; border-color: #10b981 !important;">
		<span>📥</span> Import from SEO Plugins
	</a>
	<!-- Forms are NOT created automatically, so you need to wrap the table in one to use features like bulk actions -->
	<form id="redirection-form" method="post" action="">
		<?php wp_nonce_field('metasync_redirection_form', 'metasync_redirection_nonce'); ?>
		<?php include "metasync-add-redirection.php";
		$request_data = sanitize_post($_REQUEST); ?>
		<!-- For plugins, we also need to ensure that the form posts back to our current page -->
		<input type="hidden" name="page" value="<?php echo esc_attr($request_data['page']) ?>" />
		
		<!-- Search and Filter Controls -->
		<div class="tablenav top">
			
			<div class="alignleft actions">
				<label for="status-filter" class="screen-reader-text">Filter by status</label>
				<select name="status_filter" id="status-filter">
					<option value="">All Statuses</option>
					<option value="active" <?php selected(isset($_REQUEST['status_filter']) ? $_REQUEST['status_filter'] : '', 'active'); ?>>Active</option>
					<option value="inactive" <?php selected(isset($_REQUEST['status_filter']) ? $_REQUEST['status_filter'] : '', 'inactive'); ?>>Inactive</option>
				</select>
				
				<label for="pattern-filter" class="screen-reader-text">Filter by pattern type</label>
				<select name="pattern_filter" id="pattern-filter">
					<option value="">All Patterns</option>
					<option value="exact" <?php selected(isset($_REQUEST['pattern_filter']) ? $_REQUEST['pattern_filter'] : '', 'exact'); ?>>Exact Match</option>
					<option value="start" <?php selected(isset($_REQUEST['pattern_filter']) ? $_REQUEST['pattern_filter'] : '', 'start'); ?>>Starts With</option>
					<option value="end" <?php selected(isset($_REQUEST['pattern_filter']) ? $_REQUEST['pattern_filter'] : '', 'end'); ?>>Ends With</option>
					<option value="wildcard" <?php selected(isset($_REQUEST['pattern_filter']) ? $_REQUEST['pattern_filter'] : '', 'wildcard'); ?>>Wildcard (*)</option>
					<option value="regex" <?php selected(isset($_REQUEST['pattern_filter']) ? $_REQUEST['pattern_filter'] : '', 'regex'); ?>>Regex Pattern</option>
				</select>
				
				<label for="http-code-filter" class="screen-reader-text">Filter by HTTP code</label>
				<select name="http_code_filter" id="http-code-filter">
					<option value="">All Types</option>
					<option value="301" <?php selected(isset($_REQUEST['http_code_filter']) ? $_REQUEST['http_code_filter'] : '', '301'); ?>>301 Permanent</option>
					<option value="302" <?php selected(isset($_REQUEST['http_code_filter']) ? $_REQUEST['http_code_filter'] : '', '302'); ?>>302 Temporary</option>
					<option value="307" <?php selected(isset($_REQUEST['http_code_filter']) ? $_REQUEST['http_code_filter'] : '', '307'); ?>>307 Temporary</option>
					<option value="410" <?php selected(isset($_REQUEST['http_code_filter']) ? $_REQUEST['http_code_filter'] : '', '410'); ?>>410 Gone</option>
					<option value="451" <?php selected(isset($_REQUEST['http_code_filter']) ? $_REQUEST['http_code_filter'] : '', '451'); ?>>451 Unavailable</option>
				</select>
				
				<input type="submit" name="filter_action" id="post-query-submit" class="button" value="Filter">
			</div>
			
			<div class="alignright">
				<p class="search-box">
					<label class="screen-reader-text" for="post-search-input">Search Redirections:</label>
					<input type="search" id="post-search-input" name="s" value="<?php echo esc_attr(isset($_REQUEST['s']) ? $_REQUEST['s'] : ''); ?>" placeholder="Search redirections...">
					<input type="submit" id="search-submit" class="button" value="Search">
				</p>
			</div>
		</div>
		
		<!-- Now we can render the completed list table -->
		<?php $MetasyncRedirection->display() ?>
	</form>
	
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit filters when changed
    const filterSelects = document.querySelectorAll('#status-filter, #pattern-filter, #http-code-filter');
	const form = document.getElementById('redirection-form');
    filterSelects.forEach(function(select) {
        select.addEventListener('change', function() {
            // document.getElementById('redirection-form').submit();
			const formAction = form.getAttribute('action') || window.location.href;
            const url = new URL(formAction, window.location.origin);
            url.searchParams.delete('paged_redir');
            form.setAttribute('action', url.pathname + url.search);
            
            // Also add hidden field to ensure pagination resets
            let pagedInput = form.querySelector('input[name="paged_redir"]');
            if (!pagedInput) {
                pagedInput = document.createElement('input');
                pagedInput.type = 'hidden';
                pagedInput.name = 'paged_redir';
                form.appendChild(pagedInput);
            }
            pagedInput.value = '1';
            
            form.submit();
        });
    });

    // Add tab parameter to all pagination links in redirections tab
    function addTabToPaginationLinks() {
        // Find all pagination links within redirections-content
        const redirectionsContent = document.getElementById('redirections-content');
        if (redirectionsContent) {
            const paginationLinks = redirectionsContent.querySelectorAll('.tablenav-pages a');
            paginationLinks.forEach(function(link) {
                const url = new URL(link.href);
                url.searchParams.set('tab', 'redirections');
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
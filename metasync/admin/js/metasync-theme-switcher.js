/**
 * MetaSync Theme Switcher
 * Handles dark/light theme toggling and persistence
 */

(function ($) {
	'use strict';

	const MetaSyncTheme = {
		init: function () {
			this.initTheme();
			this.bindEvents();
		},

		/**
         * Initialize theme on page load
         */
		initTheme: function () {
			// Get saved theme preference or default to dark
			const savedTheme = this.getSavedTheme();
			this.applyTheme(savedTheme);
			this.updateToggleButton(savedTheme);
		},

		/**
         * Get saved theme from localStorage or WordPress options
         */
		getSavedTheme: function () {
			// First check localStorage for immediate UI response
			const localTheme = localStorage.getItem('metasync_theme');
			if (localTheme) {
				return localTheme;
			}

			// Check if theme is set in the data attribute (from PHP)
			const dashboardWrap = document.querySelector('.metasync-dashboard-wrap');
			if (dashboardWrap) {
				const dataTheme = dashboardWrap.getAttribute('data-theme');
				if (dataTheme) {
					return dataTheme;
				}
			}

			// Default to dark theme
			return 'dark';
		},

		/**
         * Apply theme to the dashboard
         */
		applyTheme: function (theme) {
			const dashboardWrap = document.querySelector('.metasync-dashboard-wrap');
			if (dashboardWrap) {
				dashboardWrap.setAttribute('data-theme', theme);
			}

			// Also set on body for global scope if needed
			document.documentElement.setAttribute('data-theme', theme);
            
			// Save to localStorage for instant UI on next page load
			localStorage.setItem('metasync_theme', theme);
		},

		/**
         * Update toggle button active state
         */
		updateToggleButton: function (theme) {
			$('.metasync-theme-option').removeClass('active');
			$('.metasync-theme-option[data-theme="' + theme + '"]').addClass('active');
		},

		/**
         * Toggle between themes
         */
		toggleTheme: function (newTheme) {
			this.applyTheme(newTheme);
			this.updateToggleButton(newTheme);

			// Save to WordPress database via AJAX
			this.saveThemePreference(newTheme);

			// Trigger custom event for other scripts
			$(document).trigger('metasync:theme-changed', [newTheme]);
		},

		/**
         * Save theme preference to WordPress database
         */
		saveThemePreference: function (theme) {
			// Check if wp.ajax is available (WordPress 4.4+)
			if (typeof wp !== 'undefined' && wp.ajax) {
				wp.ajax.post('metasync_save_theme', {
					theme: theme,
					_ajax_nonce: (typeof metasyncThemeData !== 'undefined' && metasyncThemeData.nonce) ? metasyncThemeData.nonce : ''
				}).done(function (response) {
					console.log('MetaSync: Theme preference saved:', theme);
				}).fail(function (error) {
					console.error('MetaSync: Failed to save theme preference', error);
				});
			} else {
				// Fallback to jQuery.ajax
				$.ajax({
					url: (typeof metasyncThemeData !== 'undefined' && metasyncThemeData.ajaxUrl) ? metasyncThemeData.ajaxUrl : ajaxurl,
					type: 'POST',
					data: {
						action: 'metasync_save_theme',
						theme: theme,
						_ajax_nonce: (typeof metasyncThemeData !== 'undefined' && metasyncThemeData.nonce) ? metasyncThemeData.nonce : ''
					},
					success: function (response) {
						console.log('MetaSync: Theme preference saved:', theme);
					},
					error: function (xhr, status, error) {
						console.error('MetaSync: Failed to save theme preference', error);
					}
				});
			}
		},

		/**
         * Bind event handlers
         */
		bindEvents: function () {
			const self = this;

			// Theme toggle button click
			$(document).on('click', '.metasync-theme-option', function (e) {
				e.preventDefault();
				const theme = $(this).data('theme');
				self.toggleTheme(theme);
			});

			// Keyboard accessibility
			$(document).on('keydown', '.metasync-theme-option', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					const theme = $(this).data('theme');
					self.toggleTheme(theme);
				}
			});
		}
	};

	// Initialize on document ready
	$(document).ready(function () {
		MetaSyncTheme.init();
	});

})(jQuery);


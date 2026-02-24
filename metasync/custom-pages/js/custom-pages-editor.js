/**
 * MetaSync Custom Pages Enhanced Editor
 *
 * Provides enhanced HTML editing with CodeMirror syntax highlighting
 * and live preview functionality
 */

/* global metasyncCustomPagesSettings */

(function ($) {
	'use strict';

	var CustomPagesEditor = {
		editor: null,
        
		/**
         * Initialize the editor
         */
		init: function () {
			this.setupCodeEditor();
			this.bindEvents();
			this.toggleControls();
		},

		/**
         * Setup CodeMirror editor
         */
		setupCodeEditor: function () {
			var textarea = document.getElementById('metasync_html_content');
            
			if (!textarea) {
				console.error('MetaSync: HTML content textarea not found');
				return;
			}
            
			if (typeof metasyncCustomPagesSettings === 'undefined' || !metasyncCustomPagesSettings.codeEditorSettings) {
				console.warn('MetaSync: CodeMirror settings not available, using plain textarea');
				return;
			}

			// Initialize CodeMirror
			var editorSettings = metasyncCustomPagesSettings.codeEditorSettings;
            
			// Enhanced settings for HTML editing
			editorSettings.codemirror = $.extend({}, editorSettings.codemirror, {
				mode: 'htmlmixed',
				lineNumbers: true,
				lineWrapping: true,
				matchBrackets: true,
				autoCloseTags: true,
				autoCloseBrackets: true,
				matchTags: { bothTags: true },
				extraKeys: {
					'Ctrl-Space': 'autocomplete',
					'Ctrl-J': 'toMatchingTag',
					'F11': function (cm) {
						cm.setOption('fullScreen', !cm.getOption('fullScreen'));
					},
					'Esc': function (cm) {
						if (cm.getOption('fullScreen')) {
							cm.setOption('fullScreen', false);
						}
					}
				},
				theme: 'default',
				indentUnit: 2,
				tabSize: 2,
				indentWithTabs: false
			});

			try {
				this.editor = wp.codeEditor.initialize(textarea, editorSettings);
                
				if (this.editor) {
					console.log('MetaSync: CodeMirror initialized successfully');
                    
					// Force CodeMirror to refresh and load textarea content
					setTimeout(function () {
						if (this.editor && this.editor.codemirror) {
							this.editor.codemirror.refresh();
							// Ensure textarea value is synced to CodeMirror
							var textareaValue = textarea.value;
							if (textareaValue && textareaValue.length > 0) {
								this.editor.codemirror.setValue(textareaValue);
								console.log('MetaSync: Loaded ' + textareaValue.length + ' characters into CodeMirror');
							}
						}
					}.bind(this), 100);
                    
					// Add fullscreen toggle button
					this.addFullscreenButton();
				} else {
					console.warn('MetaSync: CodeMirror failed to initialize, using plain textarea');
				}
			} catch (error) {
				console.error('MetaSync: Error initializing CodeMirror:', error);
			}
		},

		/**
         * Add fullscreen button to editor toolbar
         */
		addFullscreenButton: function () {
			if (!this.editor) {
				return;
			}

			var $editorWrap = $(this.editor.codemirror.getWrapperElement()).parent();
            
			var $toolbar = $('<div class="metasync-editor-toolbar"></div>');
			var $fullscreenBtn = $('<button type="button" class="button metasync-fullscreen-btn" title="Toggle Fullscreen (F11)"><span class="dashicons dashicons-fullscreen-alt"></span></button>');
            
			$toolbar.append($fullscreenBtn);
			$editorWrap.prepend($toolbar);

			var editor = this.editor.codemirror;
			$fullscreenBtn.on('click', function (e) {
				e.preventDefault();
				editor.setOption('fullScreen', !editor.getOption('fullScreen'));
				$(this).find('.dashicons').toggleClass('dashicons-fullscreen-alt dashicons-fullscreen-exit-alt');
			});
		},

		/**
         * Bind event handlers
         */
		bindEvents: function () {
			var self = this;

			// Toggle HTML controls when checkbox changes
			$('#metasync_html_enabled').on('change', function () {
				self.toggleControls();
			});

			// File upload handling
			$('#metasync_html_file').on('change', function (e) {
				self.handleFileUpload(e);
			});

			// Preview button
			$('#metasync-preview-html').on('click', function (e) {
				e.preventDefault();
				self.previewHTML();
			});

			// Format HTML button
			$(document).on('click', '.metasync-format-html', function (e) {
				e.preventDefault();
				self.formatHTML();
			});

			// Minify HTML button
			$(document).on('click', '.metasync-minify-html', function (e) {
				e.preventDefault();
				self.minifyHTML();
			});

			// CRITICAL FIX: Sync CodeMirror content back to textarea before form submission
			// This ensures that changes made in CodeMirror are saved when publishing/updating
			$('#post').on('submit', function () {
				self.syncEditorContent();
			});

			// Also sync on autosave
			$(document).on('heartbeat-tick.autosave', function () {
				self.syncEditorContent();
			});

			// Sync before WordPress autosave runs
			if (typeof wp !== 'undefined' && wp.autosave) {
				$(document).on('before-autosave', function () {
					self.syncEditorContent();
				});
			}

			// Periodic sync every 10 seconds to ensure data is never lost
			setInterval(function () {
				self.syncEditorContent();
			}, 10000);

			// Sync before page unload (when user navigates away)
			$(window).on('beforeunload', function () {
				self.syncEditorContent();
			});

			// Add keyboard shortcuts info
			this.addKeyboardShortcutsInfo();
		},

		/**
         * Sync CodeMirror content back to textarea
         * CRITICAL: This must be called before form submission to save changes
         */
		syncEditorContent: function () {
			if (this.editor && this.editor.codemirror) {
				var content = this.editor.codemirror.getValue();
				$('#metasync_html_content').val(content);
				console.log('MetaSync: Synced ' + content.length + ' characters from CodeMirror to textarea');
				return true;
			}
			return false;
		},

		/**
         * Toggle HTML controls visibility
         */
		toggleControls: function () {
			var isEnabled = $('#metasync_html_enabled').is(':checked');

			if (isEnabled) {
				$('#metasync-html-controls').slideDown();
			} else {
				$('#metasync-html-controls').slideUp();
			}
		},

		/**
         * Handle HTML file upload
         */
		handleFileUpload: function (e) {
			var self = this;
			var file = e.target.files[0];
            
			if (!file) {
				return;
			}

			// Validate file type
			if (!file.name.match(/\.(html|htm)$/i)) {
				alert('Please upload an HTML file (.html or .htm)');
				e.target.value = '';
				return;
			}

			// Validate file size (max 5MB)
			if (file.size > 5 * 1024 * 1024) {
				alert('File size must be less than 5MB');
				e.target.value = '';
				return;
			}

			var reader = new FileReader();
            
			reader.onload = function (event) {
				var htmlContent = event.target.result;
                
				// Set content in CodeMirror or textarea
				if (self.editor && self.editor.codemirror) {
					self.editor.codemirror.setValue(htmlContent);
				} else {
					$('#metasync_html_content').val(htmlContent);
				}
                
				// Show success message
				self.showNotice('HTML file loaded successfully. Remember to save/publish the page.', 'success');
			};

			reader.onerror = function () {
				alert('Error reading file. Please try again.');
			};

			reader.readAsText(file);
		},

		/**
         * Preview HTML in new window
         */
		previewHTML: function () {
			var htmlContent;
            
			if (this.editor && this.editor.codemirror) {
				htmlContent = this.editor.codemirror.getValue();
			} else {
				htmlContent = $('#metasync_html_content').val();
			}

			if (!htmlContent || htmlContent.trim() === '') {
				alert('Please enter HTML content first.');
				return;
			}

			// Open preview in new window
			var previewWindow = window.open('', 'preview', 'width=1200,height=800,menubar=no,toolbar=no,location=no');
            
			if (!previewWindow) {
				alert('Preview blocked by popup blocker. Please allow popups for this site.');
				return;
			}

			previewWindow.document.write(htmlContent);
			previewWindow.document.close();
		},

		/**
         * Format/beautify HTML
         */
		formatHTML: function () {
			var htmlContent;
            
			if (this.editor && this.editor.codemirror) {
				htmlContent = this.editor.codemirror.getValue();
			} else {
				htmlContent = $('#metasync_html_content').val();
			}

			if (!htmlContent) {
				return;
			}

			// Simple HTML formatting (indent properly)
			var formatted = this.beautifyHTML(htmlContent);

			if (this.editor && this.editor.codemirror) {
				this.editor.codemirror.setValue(formatted);
			} else {
				$('#metasync_html_content').val(formatted);
			}

			this.showNotice('HTML formatted successfully!', 'success');
		},

		/**
         * Simple HTML beautifier
         */
		beautifyHTML: function (html) {
			var tab = '  ';
			var result = '';
			var indent = '';

			html.split(/>\s*</).forEach(function (element) {
				if (element.match(/^\/\w/)) {
					indent = indent.substring(tab.length);
				}

				result += indent + '<' + element + '>\r\n';

				if (element.match(/^<?\w[^>]*[^/]$/) && !element.startsWith('input')) {
					indent += tab;
				}
			});

			return result.substring(1, result.length - 3);
		},

		/**
         * Minify HTML
         */
		minifyHTML: function () {
			var htmlContent;
            
			if (this.editor && this.editor.codemirror) {
				htmlContent = this.editor.codemirror.getValue();
			} else {
				htmlContent = $('#metasync_html_content').val();
			}

			if (!htmlContent) {
				return;
			}

			// Simple minification - remove extra whitespace
			var minified = htmlContent
				.replace(/\s+/g, ' ')
				.replace(/>\s+</g, '><')
				.trim();

			if (this.editor && this.editor.codemirror) {
				this.editor.codemirror.setValue(minified);
			} else {
				$('#metasync_html_content').val(minified);
			}

			this.showNotice('HTML minified successfully!', 'success');
		},

		/**
         * Show admin notice
         */
		showNotice: function (message, type) {
			type = type || 'info';
            
			var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
			$('.metasync-custom-html-container').prepend($notice);
            
			setTimeout(function () {
				$notice.fadeOut(function () {
					$(this).remove();
				});
			}, 3000);
		},

		/**
         * Add keyboard shortcuts info
         */
		addKeyboardShortcutsInfo: function () {
			var shortcuts = [
				{ key: 'Ctrl + Space', action: 'Autocomplete' },
				{ key: 'Ctrl + J', action: 'Jump to matching tag' },
				{ key: 'F11', action: 'Toggle fullscreen' },
				{ key: 'Esc', action: 'Exit fullscreen' },
				{ key: 'Ctrl + /', action: 'Toggle comment' }
			];

			var $info = $('<div class="metasync-keyboard-shortcuts"></div>');
			$info.append('<h4>⌨️ Keyboard Shortcuts</h4>');
            
			var $list = $('<ul></ul>');
			shortcuts.forEach(function (shortcut) {
				$list.append('<li><kbd>' + shortcut.key + '</kbd> - ' + shortcut.action + '</li>');
			});
            
			$info.append($list);
			$('#metasync-html-controls').append($info);
		}
	};

	// Initialize when document is ready
	$(document).ready(function () {
		// Only initialize on custom page post type
		if ($('#metasync_custom_html').length > 0) {
			CustomPagesEditor.init();
		}
	});

})(jQuery);


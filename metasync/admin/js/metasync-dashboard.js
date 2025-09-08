/**
 * Dashboard-inspired JavaScript enhancements for Metasync plugin
 * Adds modern interactions and animations matching the dashboard design
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Only run on plugin pages
        if (!$('.metasync-dashboard-wrap').length) {
            return;
        }
        
        // Fix plugin admin styles (scoped)
        fixPluginAdminStyles();
        
        // Add loading states to buttons
        enhanceButtonInteractions();
        
        // Add smooth transitions to cards
        // enhanceCardAnimations(); // Disabled - was too distracting in settings pages
        
        // Enhance form validation
        enhanceFormValidation();
        
        // Add dashboard-style notifications
        enhanceDashboardNotifications();
        
        // Enhanced API response handling for Google Console
        enhanceGoogleConsoleResponse();
        
        // Enhanced SSO authentication handling
        enhanceSSOAuthentication();
        
        // Integrate with existing SSO functions
        integrateSSOWithDashboard();
        
        // Add tooltips to stat cards
        addStatCardTooltips();
        
        // Initialize progress bars
        initializeProgressBars();
        
        // Fix stat card text overflow
        fixStatCardTextOverflow();
        
        // Handle responsive layout
        handleResponsiveLayout();
        
    });

    /**
     * Enhance button interactions with loading states - SCOPED TO PLUGIN
     */
    function enhanceButtonInteractions() {
        $('.metasync-dashboard-wrap .button-primary, .metasync-dashboard-wrap .button-secondary').on('click', function(e) {
            const $button = $(this);
            
            // Don't add loading to certain buttons
            if ($button.hasClass('no-loading') || $button.attr('type') === 'submit') {
                return;
            }
            
            // Add loading state
            $button.addClass('dashboard-loading');
            $button.prop('disabled', true);
            
            // Remove loading state after animation
            setTimeout(() => {
                $button.removeClass('dashboard-loading');
                $button.prop('disabled', false);
            }, 2000);
        });
    }

    /**
     * Add hover animations to dashboard cards - SCOPED TO PLUGIN
     */
    function enhanceCardAnimations() {
        $('.metasync-dashboard-wrap .dashboard-card').hover(
            function() {
                $(this).addClass('card-hover');
            },
            function() {
                $(this).removeClass('card-hover');
            }
        );
        
        // Add stagger animation to stat cards
        $('.metasync-dashboard-wrap .dashboard-stat-card').each(function(index) {
            $(this).css('animation-delay', (index * 0.1) + 's');
            $(this).addClass('fade-in-up');
        });
    }

    /**
     * Enhance form validation with better UX - SCOPED TO PLUGIN
     */
    function enhanceFormValidation() {
        $('.metasync-dashboard-wrap input, .metasync-dashboard-wrap textarea, .metasync-dashboard-wrap select').on('blur', function() {
            const $input = $(this);
            
            if ($input.is(':invalid')) {
                $input.addClass('input-error');
                showInputError($input, 'Please check this field');
            } else {
                $input.removeClass('input-error');
                hideInputError($input);
            }
        });

        $('.metasync-dashboard-wrap input, .metasync-dashboard-wrap textarea, .metasync-dashboard-wrap select').on('input', function() {
            const $input = $(this);
            if ($input.hasClass('input-error') && $input.is(':valid')) {
                $input.removeClass('input-error');
                hideInputError($input);
            }
        });
    }

    /**
     * Show input error with dashboard styling
     */
    function showInputError($input, message) {
        const errorId = 'error-' + Math.random().toString(36).substr(2, 9);
        
        if ($input.siblings('.input-error-message').length === 0) {
            $input.after(`
                <div class="input-error-message" id="${errorId}" style="
                    color: var(--dashboard-error);
                    font-size: 12px;
                    margin-top: 4px;
                    opacity: 0;
                    transform: translateY(-10px);
                    transition: all 0.3s ease;
                ">${message}</div>
            `);
            
            setTimeout(() => {
                $(`#${errorId}`).css({
                    opacity: 1,
                    transform: 'translateY(0)'
                });
            }, 10);
        }
    }

    /**
     * Hide input error
     */
    function hideInputError($input) {
        const $error = $input.siblings('.input-error-message');
        $error.css({
            opacity: 0,
            transform: 'translateY(-10px)'
        });
        
        setTimeout(() => {
            $error.remove();
        }, 300);
    }

    /**
     * Enhance dashboard notifications - SCOPED TO PLUGIN
     */
    function enhanceDashboardNotifications() {
        $('.metasync-dashboard-wrap .notice').each(function() {
            const $notice = $(this);
            $notice.addClass('dashboard-notice-enhanced');
            
            // Add close button
            if (!$notice.find('.notice-dismiss').length) {
                $notice.append(`
                    <button type="button" class="notice-dismiss">
                        <span class="screen-reader-text">Dismiss this notice.</span>
                    </button>
                `);
            }
        });
        
        // Handle notice dismiss ONLY within plugin pages
        $('.metasync-dashboard-wrap').on('click', '.notice-dismiss', function() {
            $(this).closest('.notice').fadeOut(300);
        });
    }

    /**
     * Enhanced Google Console API response handling
     */
    function enhanceGoogleConsoleResponse() {
        const $responseDiv = $('#metasync-giapi-response');
        
        if ($responseDiv.length === 0) return;
        
        // Show response with animation when data is received
        const originalShow = $responseDiv.show;
        $responseDiv.show = function() {
            $responseDiv.css('opacity', 0).slideDown(300).animate({opacity: 1}, 300);
            return this;
        };
        
        // Enhanced send button for Google Console
        $('#metasync-btn-send').on('click', function() {
            const $button = $(this);
            const originalText = $button.text();
            
            $button.html('🔄 Sending...').prop('disabled', true);
            
            // Simulate API call progress (replace with actual API integration)
            let progress = 0;
            const progressInterval = setInterval(() => {
                progress += 10;
                if (progress >= 100) {
                    clearInterval(progressInterval);
                    $button.html(originalText).prop('disabled', false);
                }
            }, 200);
        });
    }

    /**
     * Add tooltips to stat cards - SCOPED TO PLUGIN
     */
    function addStatCardTooltips() {
        $('.metasync-dashboard-wrap .dashboard-stat-card').each(function() {
            const $card = $(this);
            const label = $card.find('.dashboard-stat-label').text();
            const value = $card.find('.dashboard-stat-value').text();
            
            $card.attr('title', `${label}: ${value}`)
                 .addClass('dashboard-tooltip');
        });
    }

    /**
     * Initialize animated progress bars - SCOPED TO PLUGIN
     */
    function initializeProgressBars() {
        $('.metasync-dashboard-wrap .dashboard-progress-bar').each(function() {
            const $bar = $(this);
            const width = $bar.data('width') || '0%';
            
            // Animate progress bar on scroll into view
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            $bar.css('width', width);
                        }, 500);
                        observer.unobserve(entry.target);
                    }
                });
            });
            
            observer.observe($bar[0]);
        });
    }

    /**
     * Add success animation to forms after submission
     */
    function addSuccessAnimation($element) {
        $element.addClass('metasync-sso-success-animation');
        setTimeout(() => {
            $element.removeClass('metasync-sso-success-animation');
        }, 600);
    }

    /**
     * Enhanced tab navigation - SCOPED TO PLUGIN
     */
    $('.metasync-dashboard-wrap .metasync-nav-tab, .metasync-dashboard-wrap .nav-tab').on('click', function(e) {
        const $tab = $(this);
        
        // Add loading effect
        $tab.addClass('tab-loading');
        
        // Remove loading after page load
        setTimeout(() => {
            $tab.removeClass('tab-loading');
        }, 1000);
    });

    /**
     * Set active navigation tab based on current page
     */
    function setActiveNavTab() {
        const currentUrl = window.location.href;
        const $navTabs = $('.metasync-dashboard-wrap .metasync-nav-tab');
        
        $navTabs.each(function() {
            const $tab = $(this);
            const tabHref = $tab.attr('href');
            
            if (tabHref && currentUrl.includes(tabHref.split('?')[1])) {
                $navTabs.removeClass('active');
                $tab.addClass('active');
            }
        });
    }
    
    // Set active tab on page load
    setActiveNavTab();

    /**
     * Add keyboard navigation for accessibility - SCOPED TO PLUGIN
     */
    $('.metasync-dashboard-wrap .dashboard-card').attr('tabindex', '0').on('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            $(this).click();
        }
    });

    /**
     * Dark mode toggle functionality (if needed)
     */
    function addDarkModeToggle() {
        // This can be expanded if manual dark mode toggle is needed
        // Currently the dashboard is always dark themed
    }

    /**
     * Responsive navigation improvements
     */
    function enhanceResponsiveNavigation() {
        const $navTabs = $('.nav-tab-wrapper');
        
        if ($navTabs.length && window.innerWidth < 768) {
            $navTabs.addClass('nav-mobile');
        }
        
        $(window).on('resize', function() {
            if (window.innerWidth < 768) {
                $navTabs.addClass('nav-mobile');
            } else {
                $navTabs.removeClass('nav-mobile');
            }
        });
    }
    
    // Initialize responsive enhancements
    enhanceResponsiveNavigation();

    /**
     * Fix WordPress admin styles - SCOPED TO PLUGIN ONLY
     */
    function fixPluginAdminStyles() {
        const $pluginWrap = $('.metasync-dashboard-wrap');
        
        if ($pluginWrap.length === 0) return;
        
        // Handle WordPress notices ONLY within plugin pages
        $pluginWrap.find('.notice, .error, .updated').each(function() {
            $(this).addClass('dashboard-notice');
        });
        
        // Fix nav tabs background ONLY within plugin pages
        $pluginWrap.find('.nav-tab-wrapper').css('background', 'transparent');
    }

    /**
     * Fix stat card text overflow issues - SCOPED TO PLUGIN
     */
    function fixStatCardTextOverflow() {
        $('.metasync-dashboard-wrap .dashboard-stat-card').each(function() {
            const $card = $(this);
            const $value = $card.find('.dashboard-stat-value');
            const $label = $card.find('.dashboard-stat-label');
            
            // Handle long theme names or values
            if ($value.text().length > 20) {
                $value.addClass('long-text');
                $card.attr('title', $value.text());
            }
            
            // Adjust font size based on text length
            if ($value.text().length > 15) {
                $value.css('font-size', '1.4rem');
            }
            if ($value.text().length > 25) {
                $value.css('font-size', '1.2rem');
            }
        });
    }

    /**
     * Handle responsive layout changes - SCOPED TO PLUGIN
     */
    function handleResponsiveLayout() {
        function adjustLayout() {
            const $pluginWrap = $('.metasync-dashboard-wrap');
            if ($pluginWrap.length === 0) return;
            
            const windowWidth = $(window).width();
            
            if (windowWidth < 768) {
                $pluginWrap.find('.dashboard-stats').addClass('mobile-layout');
                $pluginWrap.find('.metasync-sso-buttons').addClass('mobile-buttons');
            } else {
                $pluginWrap.find('.dashboard-stats').removeClass('mobile-layout');
                $pluginWrap.find('.metasync-sso-buttons').removeClass('mobile-buttons');
            }
            
            if (windowWidth < 1200) {
                $pluginWrap.find('.dashboard-card').addClass('compact-layout');
            } else {
                $pluginWrap.find('.dashboard-card').removeClass('compact-layout');
            }
        }
        
        // Run on load
        adjustLayout();
        
        // Run on resize
        $(window).on('resize', debounce(adjustLayout, 250));
    }

    /**
     * Debounce function for performance
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Enhanced SSO Authentication handling - SCOPED TO PLUGIN
     */
    function enhanceSSOAuthentication() {
        const $ssoContainer = $('.metasync-dashboard-wrap .metasync-sso-container');
        const $connectBtn = $('.metasync-dashboard-wrap #connect-searchatlas-sso');
        const $retryBtn = $('.metasync-dashboard-wrap .metasync-sso-retry-btn');
        
        if ($ssoContainer.length === 0) return;
        
        // Handle connect button clicks
        $connectBtn.on('click', function() {
            const $btn = $(this);
            const originalText = $btn.text();
            
            // Add loading state
            $btn.prop('disabled', true)
                .html('<span class="metasync-sso-loading"></span> Opening Authentication...')
                .addClass('dashboard-loading');
            
            // Show progress indicator
            showSSOProgress();
            
            // Start progress timer
            startSSOTimer();
            
            // Remove any existing status messages
            $('.metasync-sso-status').remove();
        });
        
        // Handle retry button clicks
        $(document).on('click', '.metasync-sso-retry-btn', function() {
            const $btn = $(this);
            
            // Remove status messages
            $('.metasync-sso-status').remove();
            
            // Re-enable connect button
            $connectBtn.prop('disabled', false)
                      .html('🔄 Re-authenticate with ' + (window.MetasyncConfig && window.MetasyncConfig.pluginName ? window.MetasyncConfig.pluginName : 'Search Atlas'))
                      .removeClass('dashboard-loading');
            
            // Hide progress
            $('.metasync-sso-progress').fadeOut(300);
        });
        
        // Handle authentication tips toggle (both new and existing elements)
        $(document).on('click', '.metasync-sso-tips-toggle, details.metasync-sso-tips summary', function(e) {
            const $toggle = $(this);
            
            // Handle new-style tips
            if ($toggle.hasClass('metasync-sso-tips-toggle')) {
                const $content = $toggle.siblings('.metasync-sso-tips-content');
                const $icon = $toggle.find('.tips-icon');
                
                $content.slideToggle(300);
                $icon.text($content.is(':visible') ? '▼' : '▶');
            }
            // The details element handles its own toggle, just add animation
            else if ($toggle.is('summary')) {
                const $details = $toggle.parent();
                setTimeout(() => {
                    $details.find('div').addClass('tips-animated');
                }, 10);
            }
        });
    }
    
    /**
     * Show SSO progress indicator
     */
    function showSSOProgress() {
        const progressHTML = `
            <div class="metasync-sso-progress">
                <div class="metasync-sso-progress-header">
                    <span>🔒 Authentication in Progress</span>
                    <span class="metasync-sso-progress-time">0min elapsed, 5min remaining</span>
                </div>
                <div class="metasync-sso-progress-bar">
                    <div class="metasync-sso-progress-fill" style="width: 0%"></div>
                </div>
                <div class="metasync-sso-progress-text">Please complete authentication in the popup window...</div>
            </div>
        `;
        
        $('.metasync-sso-container').append(progressHTML);
    }
    
    /**
     * Start SSO timer and progress animation
     */
    function startSSOTimer() {
        let elapsed = 0;
        const maxTime = 300; // 5 minutes in seconds
        
        const timer = setInterval(() => {
            elapsed += 1;
            const remaining = maxTime - elapsed;
            const progressPercent = (elapsed / maxTime) * 100;
            
            // Update progress bar
            $('.metasync-sso-progress-fill').css('width', progressPercent + '%');
            
            // Update time display
            const elapsedMin = Math.floor(elapsed / 60);
            const remainingMin = Math.floor(remaining / 60);
            $('.metasync-sso-progress-time').text(`${elapsedMin}min elapsed, ${remainingMin}min remaining`);
            
            // Check if time is up
            if (elapsed >= maxTime) {
                clearInterval(timer);
                showSSOTimeout();
            }
        }, 1000);
        
        // Store timer reference for cleanup
        $('.metasync-sso-container').data('sso-timer', timer);
    }
    
    /**
     * Show SSO timeout message
     */
    function showSSOTimeout() {
        showSSOStatus('error', 'Authentication Timeout', 'The authentication window timed out. You can try again when you\'re ready.');
    }
    
    /**
     * Show SSO status message
     */
    function showSSOStatus(type, title, message) {
        const iconMap = {
            success: '✅',
            error: '⚠️',
            info: 'ℹ️',
            warning: '⚠️'
        };
        
        const statusHTML = `
            <div class="metasync-sso-status ${type}">
                <div class="metasync-sso-status-content">
                    <div class="metasync-sso-status-title">
                        ${iconMap[type]} ${title}
                    </div>
                    <div class="metasync-sso-status-message">${message}</div>
                    ${type === 'error' ? '<button class="metasync-sso-retry-btn">🔄 Try Again</button>' : ''}
                </div>
            </div>
        `;
        
        // Remove existing status
        $('.metasync-sso-status').remove();
        
        // Add new status
        $('.metasync-sso-container').append(statusHTML);
        
        // Clear timer and progress
        const timer = $('.metasync-sso-container').data('sso-timer');
        if (timer) {
            clearInterval(timer);
        }
        
        // Reset button state
        $('#connect-searchatlas-sso').prop('disabled', false)
                                     .html('🔄 Re-authenticate with ' + (window.MetasyncConfig && window.MetasyncConfig.pluginName ? window.MetasyncConfig.pluginName : 'Search Atlas'))
                                     .removeClass('dashboard-loading');
        
        // Hide progress after delay
        setTimeout(() => {
            $('.metasync-sso-progress').fadeOut(300);
        }, 1000);
    }

    /**
     * Integrate with existing SSO authentication functions
     */
    function integrateSSOWithDashboard() {
        // Override existing SSO status display to use dashboard styling
        if (typeof window.showSSOStatus !== 'undefined') {
            const originalShowSSOStatus = window.showSSOStatus;
            
            window.showSSOStatus = function(type, title, message, actions) {
                // If we're on a dashboard page, use our enhanced styling
                if ($('.metasync-dashboard-wrap').length > 0) {
                    showSSOStatus(type, title, message);
                } else {
                    // Fallback to original function for non-dashboard pages
                    originalShowSSOStatus.call(this, type, title, message, actions);
                }
            };
        }
        
        // Enhance existing SSO containers with dashboard styling
        $('.metasync-sso-container').each(function() {
            if (!$(this).closest('.metasync-dashboard-wrap').length) {
                return; // Only enhance containers within dashboard pages
            }
            
            // Add dashboard classes to existing elements
            $(this).addClass('dashboard-enhanced');
            
            // Style existing status messages
            $(this).find('.metasync-sso-status').each(function() {
                $(this).addClass('dashboard-styled');
            });
            
            // Style existing progress elements
            $(this).find('.metasync-sso-progress').each(function() {
                $(this).addClass('dashboard-styled');
            });
            
            // Style existing authentication tips (PHP-generated details element)
            // Check for both class-based tips AND details elements to prevent duplication
            const hasExistingTips = $(this).find('.metasync-sso-tips').length > 0 || 
                                   $(this).find('details summary').filter(function() {
                                       return $(this).text().includes('Authentication Tips');
                                   }).length > 0;
            
            if (!hasExistingTips) {
                // No existing tips found, this shouldn't happen but just in case
                console.log('No authentication tips found in SSO container');
            } else {
                // Style existing PHP-generated details element
                $(this).find('details').each(function() {
                    const $details = $(this);
                    if ($details.find('summary').text().includes('Authentication Tips')) {
                        // Add dashboard styling to existing tips
                        $details.addClass('metasync-sso-tips dashboard-styled');
                        $details.find('summary').addClass('metasync-sso-tips-toggle');
                        $details.find('div').addClass('metasync-sso-tips-content');
                    }
                });
            }
        });
        
        // Monitor for dynamically added SSO elements
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) { // Element node
                        const $node = $(node);
                        
                        // Style new SSO status messages
                        if ($node.hasClass('metasync-sso-status')) {
                            $node.addClass('dashboard-styled');
                        }
                        
                        // Style new progress indicators
                        if ($node.hasClass('metasync-sso-progress')) {
                            $node.addClass('dashboard-styled');
                        }
                        
                        // Check for nested SSO elements
                        $node.find('.metasync-sso-status, .metasync-sso-progress').addClass('dashboard-styled');
                    }
                });
            });
        });
        
        // Start observing
        $('.metasync-dashboard-wrap').each(function() {
            observer.observe(this, {
                childList: true,
                subtree: true
            });
        });
    }

    // Expose functions globally for SSO integration
    window.metasyncDashboard = {
        showSSOStatus: showSSOStatus,
        showSSOProgress: showSSOProgress,
        startSSOTimer: startSSOTimer,
        integrateSSOWithDashboard: integrateSSOWithDashboard
    };

})(jQuery);

// CSS animations for JavaScript enhancements
const additionalCSS = `
    <style>
        .fade-in-up {
            animation: fadeInUp 0.6s ease forwards;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .card-hover {
            /* Removed translateY animation - was too distracting in settings pages */
            /* transform: translateY(-4px) !important; */
            /* transition: transform 0.3s ease !important; */
        }
        
        .input-error {
            border-color: var(--dashboard-error) !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1) !important;
        }
        
        .dashboard-notice-enhanced {
            backdrop-filter: blur(10px);
            animation: slideInRight 0.3s ease;
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .tab-loading {
            position: relative;
            overflow: hidden;
        }
        
        .tab-loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: shimmer 1s ease-in-out;
        }
        
        /* Enhanced loading effect for new nav tabs */
        .metasync-nav-tab.tab-loading {
            opacity: 0.7;
        }
        
        .metasync-nav-tab.tab-loading .tab-icon {
            animation: spin 1s linear infinite;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transform-origin: center;
        }
        
        /* Animation for existing authentication tips */
        .tips-animated {
            animation: slideInTips 0.3s ease;
        }
        
        @keyframes slideInTips {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }
        
        .nav-mobile {
            flex-direction: column !important;
            gap: 8px !important;
        }
        
        .nav-mobile .nav-tab {
            text-align: center;
            width: 100%;
        }
        
        /* Additional responsive classes */
        .mobile-layout .dashboard-stat-card {
            margin-bottom: 12px;
        }
        
        .mobile-buttons {
            flex-direction: column;
            align-items: stretch;
        }
        
        .mobile-buttons button {
            width: 100%;
            margin: 4px 0 !important;
        }
        
        .compact-layout {
            padding: 16px !important;
        }
        
        .long-text {
            word-break: break-word;
            hyphens: auto;
        }
        
        /* Dashboard notice styling */
        .dashboard-notice {
            backdrop-filter: blur(10px);
            animation: slideInNotice 0.3s ease;
        }
        
        @keyframes slideInNotice {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
    </style>
`;

// Inject additional CSS
document.head.insertAdjacentHTML('beforeend', additionalCSS);

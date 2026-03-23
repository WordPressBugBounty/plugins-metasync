/* global metasyncNavData */
/**
 * MetaSync Navigation Portal Scripts
 *
 * Extracted for Phase 5, #887 — Part B JS extraction.
 * Combines Block 1 (top-bar portal menus) and Block 10 (settings inner-page
 * navigation dropdown) into a single file.
 *
 * Localized data object: metasyncNavData
 *   - hideAdvanced (bool)  — whether the Advanced tab is hidden
 *   - showGeneral  (bool)  — whether the user can see the General tab
 *   - pageSlug     (string) — admin page slug for link hrefs
 *
 * @since Phase 5
 * @see   wp_localize_script() call in class-metasync-admin.php
 */

/* ==========================================================================
   Block 1 — Top-bar SEO & Settings portal menus
   ========================================================================== */

function toggleSeoMenuPortal(event) {
    event.preventDefault();
    event.stopPropagation();
    var button = event.currentTarget;
    var existingMenu = document.getElementById('metasync-seo-portal-menu');
    if (existingMenu) {
        existingMenu.remove();
        button.classList.remove('active');
        return;
    }
    var menu = document.createElement('div');
    menu.id = 'metasync-seo-portal-menu';
    menu.className = 'metasync-portal-menu';

    var rect = button.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.top = (rect.bottom + 8) + 'px';
    menu.style.right = (window.innerWidth - rect.right) + 'px';
    menu.style.zIndex = '999999999';
    document.body.appendChild(menu);
    button.classList.add('active');
}

function toggleSettingsMenuPortal(event) {
    event.preventDefault();
    event.stopPropagation();
    var button = event.currentTarget;
    var existingMenu = document.getElementById('metasync-portal-menu');
    if (existingMenu) {
        existingMenu.remove();
        button.classList.remove('active');
        return;
    }
    var menu = document.createElement('div');
    menu.id = 'metasync-portal-menu';
    menu.className = 'metasync-portal-menu';

    var hideAdvanced = metasyncNavData.hideAdvanced;
    var showGeneral = metasyncNavData.showGeneral;

    if (showGeneral) {
        var generalLink = document.createElement('a');
        generalLink.href = '?page=' + metasyncNavData.pageSlug + '&tab=general';
        generalLink.className = 'metasync-portal-item';
        generalLink.textContent = 'General';
        menu.appendChild(generalLink);
    }

    var whitelabelLink = document.createElement('a');
    whitelabelLink.href = '?page=' + metasyncNavData.pageSlug + '&tab=whitelabel';
    whitelabelLink.className = 'metasync-portal-item';
    whitelabelLink.textContent = 'White Label';
    menu.appendChild(whitelabelLink);

    if (!hideAdvanced) {
        var advancedLink = document.createElement('a');
        advancedLink.href = '?page=' + metasyncNavData.pageSlug + '&tab=advanced';
        advancedLink.className = 'metasync-portal-item';
        advancedLink.textContent = 'Advanced';
        menu.appendChild(advancedLink);
    }

    var rect = button.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.top = (rect.bottom + 8) + 'px';
    menu.style.right = (window.innerWidth - rect.right) + 'px';
    menu.style.zIndex = '999999999';
    document.body.appendChild(menu);
    button.classList.add('active');
}

document.addEventListener('click', function(event) {
    var seoButton = document.getElementById('metasync-seo-btn');
    var seoMenu = document.getElementById('metasync-seo-portal-menu');
    if (seoMenu && seoButton && !seoButton.contains(event.target) && !seoMenu.contains(event.target)) {
        seoMenu.remove();
        seoButton.classList.remove('active');
    }

    var button = document.getElementById('metasync-settings-btn');
    var menu = document.getElementById('metasync-portal-menu');
    if (menu && button && !button.contains(event.target) && !menu.contains(event.target)) {
        menu.remove();
        button.classList.remove('active');
    }
});

/* ==========================================================================
   Block 10 — Settings inner-page navigation dropdown (portal pattern)
   ========================================================================== */

(function() {
    var dropdowns = document.querySelectorAll('.metasync-nav-dropdown');
    var activePortal = null;
    var activeButton = null;
    var activeDropdown = null;
    var scrollHandler = null;
    var resizeHandler = null;

    // Position the portal menu relative to its trigger button
    function positionPortalMenu(button, portalMenu) {
        var rect = button.getBoundingClientRect();
        var menuRect = portalMenu.getBoundingClientRect();
        var viewportWidth = window.innerWidth;
        var viewportHeight = window.innerHeight;

        // Calculate left position, ensuring menu stays within viewport
        var left = rect.left;
        if (left + menuRect.width > viewportWidth - 10) {
            left = Math.max(10, viewportWidth - menuRect.width - 10);
        }

        // Calculate top position, prefer below button but flip above if needed
        var top = rect.bottom + 8;
        if (top + menuRect.height > viewportHeight - 10 && rect.top > menuRect.height + 10) {
            top = rect.top - menuRect.height - 8;
        }

        portalMenu.style.position = 'fixed';
        portalMenu.style.top = top + 'px';
        portalMenu.style.left = left + 'px';
        portalMenu.style.zIndex = '999999999';
    }

    // Close the active portal menu
    function closeActivePortal() {
        if (activePortal && activePortal.parentNode) {
            activePortal.parentNode.removeChild(activePortal);
        }
        if (activeButton) {
            activeButton.setAttribute('aria-expanded', 'false');
        }
        if (activeDropdown) {
            activeDropdown.classList.remove('active');
        }
        if (scrollHandler) {
            window.removeEventListener('scroll', scrollHandler, true);
            scrollHandler = null;
        }
        if (resizeHandler) {
            window.removeEventListener('resize', resizeHandler);
            resizeHandler = null;
        }
        activePortal = null;
        activeButton = null;
        activeDropdown = null;
    }

    // Open a dropdown as a portal
    function openAsPortal(dropdown, button, menu) {
        // Close any existing portal first
        closeActivePortal();

        // Clone the menu content and create portal
        var portalMenu = menu.cloneNode(true);
        portalMenu.id = 'metasync-nav-portal-menu';
        portalMenu.style.opacity = '1';
        portalMenu.style.visibility = 'visible';
        portalMenu.style.transform = 'none';

        // Append to body (portal pattern)
        document.body.appendChild(portalMenu);

        // Position after adding to DOM so we can measure
        positionPortalMenu(button, portalMenu);

        // Update state
        activePortal = portalMenu;
        activeButton = button;
        activeDropdown = dropdown;
        dropdown.classList.add('active');
        button.setAttribute('aria-expanded', 'true');

        // Create bound handlers for repositioning
        scrollHandler = function() {
            if (activePortal && activeButton) {
                positionPortalMenu(activeButton, activePortal);
            }
        };
        resizeHandler = scrollHandler;

        // Reposition on scroll and resize
        window.addEventListener('scroll', scrollHandler, true);
        window.addEventListener('resize', resizeHandler);

        // Handle clicks within portal menu (for navigation)
        portalMenu.addEventListener('click', function(e) {
            var link = e.target.closest('a');
            if (link) {
                // Let the link navigate naturally, then close
                setTimeout(closeActivePortal, 0);
            }
        });
    }

    dropdowns.forEach(function(dropdown) {
        var button = dropdown.querySelector('.metasync-nav-dropdown-btn');
        var menu = dropdown.querySelector('.metasync-nav-dropdown-menu');

        if (!button || !menu) return;

        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var isExpanded = button.getAttribute('aria-expanded') === 'true';

            if (isExpanded) {
                closeActivePortal();
            } else {
                openAsPortal(dropdown, button, menu);
            }
        });
    });

    // Close portal when clicking outside
    document.addEventListener('click', function(e) {
        if (activePortal && activeButton) {
            // Check if click is outside both the portal and the trigger button
            if (!activePortal.contains(e.target) && !activeButton.contains(e.target)) {
                closeActivePortal();
            }
        }
    });

    // Close portal on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && activePortal) {
            closeActivePortal();
            if (activeButton) {
                activeButton.focus();
            }
        }
    });
})();

// Portal-style dropdown that bypasses stacking contexts (settings inner page)
function toggleSettingsMenuPortalInner(event) {
    event.preventDefault();
    event.stopPropagation();

    var button = event.currentTarget;
    var existingMenu = document.getElementById('metasync-portal-menu');

    // If menu exists, close it
    if (existingMenu) {
        existingMenu.remove();
        button.classList.remove('active');
        button.setAttribute('aria-expanded', 'false');
        return;
    }

    // Create menu outside form context
    var menu = document.createElement('div');
    menu.id = 'metasync-portal-menu';
    menu.className = 'metasync-portal-menu';

    // Get current page context for active states
    var currentUrl = window.location.href;
    var isGeneralActive = currentUrl.indexOf('tab=general') > -1 || currentUrl.indexOf('tab=') === -1;
    var isAdvancedActive = currentUrl.indexOf('tab=advanced') > -1;
    var isWhitelabelActive = currentUrl.indexOf('tab=whitelabel') > -1;

    // Fixed: Use safer DOM manipulation instead of innerHTML to prevent XSS
    menu.textContent = '';

    var hideAdvanced = metasyncNavData.hideAdvanced;
    var showGeneral = metasyncNavData.showGeneral;

    // Only add General link when user has access to Settings
    if (showGeneral) {
        var generalLink = document.createElement('a');
        generalLink.href = '?page=' + metasyncNavData.pageSlug + '&tab=general';
        generalLink.className = 'metasync-portal-item' + (isGeneralActive ? ' active' : '');
        generalLink.textContent = 'General';
        menu.appendChild(generalLink);
    }

    // White Label tab
    var whitelabelLink = document.createElement('a');
    whitelabelLink.href = '?page=' + metasyncNavData.pageSlug + '&tab=whitelabel';
    whitelabelLink.className = 'metasync-portal-item' + (isWhitelabelActive ? ' active' : '');
    whitelabelLink.textContent = 'White label';
    menu.appendChild(whitelabelLink);

    // Only add Advanced tab if not hidden
    if (!hideAdvanced) {
        var advancedLink = document.createElement('a');
        advancedLink.href = '?page=' + metasyncNavData.pageSlug + '&tab=advanced';
        advancedLink.className = 'metasync-portal-item' + (isAdvancedActive ? ' active' : '');
        advancedLink.textContent = 'Advanced';
        menu.appendChild(advancedLink);
    }

    // Position menu relative to button
    var rect = button.getBoundingClientRect();
    menu.style.position = 'fixed';
    menu.style.top = (rect.bottom + 8) + 'px';
    menu.style.right = (window.innerWidth - rect.right) + 'px';
    menu.style.zIndex = '999999999';

    // Append to body to escape form context
    document.body.appendChild(menu);

    // Update button state
    button.classList.add('active');
    button.setAttribute('aria-expanded', 'true');
}

// Close dropdown when clicking outside (settings inner page)
document.addEventListener('click', function(event) {
    var button = document.getElementById('metasync-settings-btn');
    var menu = document.getElementById('metasync-portal-menu');

    if (menu && button && !button.contains(event.target) && !menu.contains(event.target)) {
        menu.remove();
        button.classList.remove('active');
        button.setAttribute('aria-expanded', 'false');
    }
});

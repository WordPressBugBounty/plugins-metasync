/**
 * Front-end behaviour for the [accordion_metasync] shortcode.
 *
 * Vanilla JS on purpose: this is the only script the shortcode needs, and
 * depending on jQuery here forced jQuery to load on every front-end request.
 */
(function () {
	'use strict';

	function init() {
		var panels = document.querySelectorAll('.metasync-accordion-block.metasync-panel');
		for (var i = 0; i < panels.length; i++) {
			panels[i].style.display = 'none';
		}

		var buttons = document.querySelectorAll('button.metasync-accordion');
		for (var j = 0; j < buttons.length; j++) {
			buttons[j].addEventListener('click', function (event) {
				// Mirrors the jQuery handler's `return false` (preventDefault + stopPropagation).
				event.preventDefault();
				event.stopPropagation();

				if (this.parentNode && this.parentNode.classList) {
					this.parentNode.classList.toggle('metasync-active');
				}
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

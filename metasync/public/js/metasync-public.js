(function( $ ) {
	'use strict';

	/**
	 * This enables you to define handlers, for when the DOM is ready:
	 *
	 * $(function() {
	 *
	 * });
	 *
	 * When the window is loaded:
	 *
	 * $( window ).load(function() {
	 *
	 * });
	 *
	 */

	$(function() {
		var allPanels = $('.metasync-accordion-block.metasync-panel').hide();
		$('button.metasync-accordion').click(function() {
			$(this).parent().toggleClass('metasync-active');
			return false;
		});
	});

})( jQuery );

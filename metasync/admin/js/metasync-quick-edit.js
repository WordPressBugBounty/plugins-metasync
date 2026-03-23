/* global metasyncQuickEditData, inlineEditPost, jQuery */
/**
 * MetaSync Quick Edit Badge
 *
 * Extracted for Phase 5, #887.
 * Copies the MetaSync HTML badge from the posts list into the
 * WordPress quick-edit panel.
 *
 * Localized object: metasyncQuickEditData
 *   - standardPageLabel (string)  Translated "Standard page" label
 *
 * @since Phase 5
 */
(function ($) {
    // Only runs on post/page list screens where inlineEditPost exists
    if (typeof inlineEditPost === 'undefined') return;

    // Copy badge from posts list to quick edit panel
    var wp_inline_edit = inlineEditPost.edit;
    inlineEditPost.edit = function (id) {
        wp_inline_edit.apply(this, arguments);

        var post_id = 0;
        if (typeof (id) === 'object') {
            post_id = parseInt(this.getId(id));
        }

        if (post_id > 0) {
            var $row = $('#post-' + post_id);
            var $badge = $row.find('.metasync-html-badge').clone();

            if ($badge.length) {
                $('.metasync-quick-edit-badge-container').html($badge);
            } else {
                $('.metasync-quick-edit-badge-container').html('<em>' + metasyncQuickEditData.standardPageLabel + '</em>');
            }
        }
    };
})(jQuery);

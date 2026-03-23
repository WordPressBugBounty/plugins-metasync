/**
 * MetaSync error logs — copy to clipboard.
 *
 * Extracted from site-error-logs/class-metasync-error-logs.php (Phase 5, #887).
 */
(function() {
    var button = document.getElementById('copy-log-btn');
    if (!button) {
        return;
    }

    button.addEventListener('click', function() {
        var content = document.getElementById('error-log-content').textContent;
        var originalText = button.innerHTML;

        navigator.clipboard.writeText(content).then(function() {
            button.innerHTML = '<span class="dashicons dashicons-yes" style="font-size: 16px; width: 16px; height: 16px;"></span> Copied!';
            button.style.background = '#00a32a';
            button.style.color = '#ffffff';
            button.style.borderColor = '#00a32a';

            setTimeout(function() {
                button.innerHTML = originalText;
                button.style.background = '';
                button.style.color = '';
                button.style.borderColor = '';
            }, 2000);
        }).catch(function(err) {
            console.error('Failed to copy log:', err);
            button.innerHTML = '<span class="dashicons dashicons-no" style="font-size: 16px; width: 16px; height: 16px;"></span> Failed';
            button.style.background = '#dc3232';
            button.style.color = '#ffffff';
            button.style.borderColor = '#dc3232';

            setTimeout(function() {
                button.innerHTML = originalText;
                button.style.background = '';
                button.style.color = '';
                button.style.borderColor = '';
            }, 2000);
        });
    });
})();

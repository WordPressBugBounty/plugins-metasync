/* global metasyncOttoDebugData, jQuery, ajaxurl */
/**
 * MetaSync OTTO Debug Panel
 *
 * Extracted for Phase 5, #887.
 * Debug tools: API test, notification test, cache clear, crawl simulation,
 * URL testing, OTTO change emulation, simple AJAX test, DB permissions test.
 *
 * Localized object: metasyncOttoDebugData
 *   - nonce (string)
 *
 * @since Phase 5
 */
jQuery(document).ready(function ($) {
    var nonce = metasyncOttoDebugData.nonce;

    // Test API connectivity
    $('#test-otto-api').on('click', function () {
        var button = $(this);
        button.prop('disabled', true).text('Testing...');

        $.post(ajaxurl, {
            action: 'metasync_otto_debug_test_api',
            nonce: nonce
        }, function (response) {
            $('#api-test-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
            button.prop('disabled', false).text('Test API Connectivity');
        });
    });

    // Test notification endpoint
    $('#test-notification-endpoint').on('click', function () {
        var button = $(this);
        button.prop('disabled', true).text('Testing...');

        $.post(ajaxurl, {
            action: 'metasync_otto_debug_test_notification',
            nonce: nonce
        }, function (response) {
            $('#notification-test-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
            button.prop('disabled', false).text('Test Notification Endpoint');
        });
    });

    // Clear OTTO cache
    $('#clear-otto-cache').on('click', function () {
        if (!confirm('Are you sure you want to clear all OTTO cache data?')) {
            return;
        }

        var button = $(this);
        button.prop('disabled', true).text('Clearing...');

        $.post(ajaxurl, {
            action: 'metasync_otto_debug_clear_cache',
            nonce: nonce
        }, function (response) {
            $('#cache-clear-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
            button.prop('disabled', false).text('Clear OTTO Cache');
        });
    });

    // Simulate crawl notification
    $('#simulate-crawl').on('click', function () {
        var button = $(this);
        button.prop('disabled', true).text('Simulating...');

        $.post(ajaxurl, {
            action: 'metasync_otto_debug_simulate_crawl',
            nonce: nonce
        }, function (response) {
            $('#crawl-simulate-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
            button.prop('disabled', false).text('Simulate Crawl Notification');
        });
    });

    // Test specific URL
    $('#test-specific-url').on('click', function () {
        var button = $(this);
        var testUrl = $('#test-url-input').val();

        if (!testUrl) {
            alert('Please enter a URL to test');
            return;
        }

        button.prop('disabled', true).text('Testing...');

        console.log('Testing URL:', testUrl);

        $.post(ajaxurl, {
            action: 'metasync_otto_debug_test_url',
            nonce: nonce,
            test_url: testUrl
        }, function (response) {
            console.log('URL Test Response:', response);
            $('#url-test-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
            button.prop('disabled', false).text('Test URL & Check All Points');
        }).fail(function (xhr, status, error) {
            console.error('URL Test Error:', error, xhr.responseText);
            $('#url-test-results').html('<div class="error">Error: ' + error + '<br>Response: ' + xhr.responseText + '</div>').show();
            button.prop('disabled', false).text('Test URL & Check All Points');
        });
    });

    // Emulate OTTO changes
    $('#emulate-otto-changes').on('click', function () {
        var button = $(this);
        var testUrl = $('#test-url-input').val();

        if (!testUrl) {
            alert('Please enter a URL to test');
            return;
        }

        button.prop('disabled', true).text('Emulating...');

        console.log('Emulating OTTO changes for URL:', testUrl);

        $.post(ajaxurl, {
            action: 'metasync_otto_debug_emulate_changes',
            nonce: nonce,
            test_url: testUrl
        }, function (response) {
            console.log('Emulation Response:', response);
            $('#emulation-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
            button.prop('disabled', false).text('Emulate OTTO Changes');
        }).fail(function (xhr, status, error) {
            console.error('Emulation Error:', error, xhr.responseText);
            $('#emulation-results').html('<div class="error">Error: ' + error + '<br>Response: ' + xhr.responseText + '</div>').show();
            button.prop('disabled', false).text('Emulate OTTO Changes');
        });
    });

    // Simple AJAX test
    $('#simple-ajax-test').on('click', function () {
        var button = $(this);
        button.prop('disabled', true).text('Testing...');

        console.log('Testing basic AJAX connection');

        $.post(ajaxurl, {
            action: 'metasync_otto_debug_simple_test',
            nonce: nonce
        }, function (response) {
            console.log('Simple Test Response:', response);
            $('#url-test-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
            button.prop('disabled', false).text('Test AJAX Connection');
        }).fail(function (xhr, status, error) {
            console.error('Simple Test Error:', error, xhr.responseText);
            $('#url-test-results').html('<div class="error">AJAX Error: ' + error + '<br>Response: ' + xhr.responseText + '</div>').show();
            button.prop('disabled', false).text('Test AJAX Connection');
        });
    });

    // Test database permissions
    $('#test-db-permissions').on('click', function () {
        var button = $(this);
        button.prop('disabled', true).text('Testing...');

        console.log('Testing database permissions');

        $.post(ajaxurl, {
            action: 'metasync_otto_debug_test_db_permissions',
            nonce: nonce
        }, function (response) {
            console.log('DB Permissions Response:', response);
            $('#db-permissions-results').html('<div class="json-output">' + JSON.stringify(response, null, 2) + '</div>').show();
            button.prop('disabled', false).text('Test Database Permissions');
        }).fail(function (xhr, status, error) {
            console.error('DB Permissions Error:', error, xhr.responseText);
            $('#db-permissions-results').html('<div class="error">Error: ' + error + '<br>Response: ' + xhr.responseText + '</div>').show();
            button.prop('disabled', false).text('Test Database Permissions');
        });
    });
});

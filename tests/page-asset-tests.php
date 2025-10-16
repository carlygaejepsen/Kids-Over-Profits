<?php
/**
 * Template Name: Asset Tests
 * Browser-based asset loading test runner
 */

get_header();
?>

<div class="container" style="padding: 40px 20px;">
    <h1>Asset Loading Tests</h1>
    <p>Click the button below to run the front-end asset diagnostic suite.</p>
    <button id="run-tests-btn" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">
        Run Tests
    </button>
    <div id="test-results" style="margin-top: 20px;"></div>
</div>

<script>
(function() {
    'use strict';

    var resultsDiv = document.getElementById('test-results');
    var runButton = document.getElementById('run-tests-btn');

    function renderList(items, color) {
        if (!items || !items.length) {
            return '<p style="color: green;">✓ None</p>';
        }

        var html = '<ul style="color: ' + color + ';">';
        items.forEach(function(item) {
            html += '<li>' + item + '</li>';
        });
        html += '</ul>';
        return html;
    }

    function displayResults(report) {
        var html = '<div style="font-family: monospace;">';
        html += '<h2>Test Results</h2>';
        html += '<p><strong>Timestamp:</strong> ' + report.timestamp + '</p>';
        html += '<p><strong>CSS Loaded:</strong> ' + report.css.loaded.length + ' / ' + report.css.expected.length + '</p>';
        html += '<p><strong>JS Loaded:</strong> ' + report.js.loaded.length + ' / ' + report.js.expected.length + '</p>';

        html += '<h3>CSS Failures</h3>';
        html += renderList(report.css.failed.map(function(item) {
            return '✗ ' + (item.id || item.href) + ' (' + (item.error || 'Unknown error') + ')';
        }), 'red');

        html += '<h3>JS Failures</h3>';
        html += renderList(report.js.failed.map(function(item) {
            return '✗ ' + (item.id || item.src) + ' (' + (item.error || 'Unknown error') + ')';
        }), 'red');

        html += '<h3>API Endpoints</h3>';
        html += '<ul>';
        Object.keys(report.api.endpoints || {}).forEach(function(key) {
            var status = report.api.endpoints[key];
            var color = status.ok ? 'green' : 'red';
            var label = status.ok ? '✓' : '✗';
            var detail = status.ok ? status.status : status.error;
            html += '<li style="color: ' + color + ';">' + label + ' ' + key + ' (' + detail + ')</li>';
        });
        html += '</ul>';

        html += '<h2 style="color: ' + (report.summary.hasErrors ? 'red' : 'green') + ';">';
        html += report.summary.hasErrors ? '❌ Tests Failed' : '✅ All Tests Passed';
        html += '</h2>';

        html += '</div>';

        resultsDiv.innerHTML = html;
    }

    runButton.addEventListener('click', function() {
        resultsDiv.innerHTML = '<p>Running tests...</p>';

        if (!window.KOP_HEALTH_CHECK || typeof window.KOP_HEALTH_CHECK.run !== 'function') {
            resultsDiv.innerHTML = '<p style="color: red;">Health check system not loaded.</p>';
            return;
        }

        window.KOP_HEALTH_CHECK.run().then(displayResults).catch(function(error) {
            resultsDiv.innerHTML = '<p style="color: red;">Error: ' + error.message + '</p>';
        });
    });
})();
</script>

<?php
get_footer();

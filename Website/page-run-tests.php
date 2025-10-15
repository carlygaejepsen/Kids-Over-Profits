<?php
/**
 * Template Name: Test Runner Page
 * 
 * WordPress page template for running JavaScript unit tests
 */

get_header(); ?>

<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h1>Kids Over Profits Test Runner</h1>
            
            <div class="test-controls" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                <h3>JavaScript Unit Tests</h3>
                <p>Click the button below to run the JavaScript unit tests.</p>
                <button onclick="runJavaScriptTests()" class="btn btn-primary" style="margin-right: 10px;">Run JavaScript Tests</button>
                <button onclick="clearTestOutput()" class="btn btn-secondary">Clear Output</button>
            </div>
            
            <div id="js-test-output" style="background: #f5f5f5; border: 1px solid #ddd; padding: 15px; margin: 20px 0; min-height: 200px; font-family: monospace;">
                <p>JavaScript test output will appear here...</p>
            </div>
            
            <div class="test-info" style="margin: 20px 0; padding: 15px; background: #e9ecef; border-radius: 8px;">
                <h3>Other Test Suites</h3>
                <p><strong>PHP Tests:</strong> Run <code>php run-tests.php php</code> from the project root</p>
                <p><strong>Python Tests:</strong> Run <code>python Scripts/test_scrapers.py</code> from the project root</p>
                <p><strong>Integration Tests:</strong> Run <code>php run-tests.php integration</code> from the project root</p>
                <p><strong>All Tests:</strong> Run <code>php run-tests.php</code> from the project root</p>
                
                <h4>Existing Test Tools</h4>
                <ul>
                    <li><strong>Autocomplete Tests:</strong> <a href="/test-autocomplete">Test Autocomplete Page</a></li>
                    <li><strong>Visual Tests:</strong> Add <code>?debug=visual</code> to any page URL</li>
                    <li><strong>Report Tests:</strong> Automatically available on report pages</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
.test-output-line {
    margin: 2px 0;
    padding: 2px 0;
}
.test-pass {
    color: #28a745;
}
.test-fail {
    color: #dc3545;
}
.test-info-line {
    color: #6c757d;
}
</style>

<script>
// Override console.log to capture test output
const originalConsoleLog = console.log;
const testOutputDiv = document.getElementById('js-test-output');

console.log = function(...args) {
    // Call original console.log
    originalConsoleLog.apply(console, args);
    
    // Display in our output div
    const message = args.join(' ');
    const div = document.createElement('div');
    div.className = 'test-output-line';
    
    // Style based on content
    if (message.includes('✓ PASS')) {
        div.className += ' test-pass';
    } else if (message.includes('✗ FAIL')) {
        div.className += ' test-fail';
    } else if (message.includes('===') || message.includes('Running:')) {
        div.className += ' test-info-line';
        div.style.fontWeight = 'bold';
    }
    
    div.textContent = message;
    testOutputDiv.appendChild(div);
    
    // Scroll to bottom
    testOutputDiv.scrollTop = testOutputDiv.scrollHeight;
};

function runJavaScriptTests() {
    clearTestOutput();
    console.log('=== Starting JavaScript Unit Tests ===');
    
    try {
        // Check if our test class is available
        if (typeof KidsOverProfitsUnitTests === 'undefined') {
            console.log('✗ FAIL: KidsOverProfitsUnitTests class not found');
            console.log('Make sure unit-tests.js is loaded on this page');
            return;
        }
        
        // Create and run tests
        const tests = new KidsOverProfitsUnitTests();
        const results = tests.runAllTests();
        
        // Display summary
        console.log('=== Test Execution Complete ===');
        console.log(`Total: ${results.total}, Passed: ${results.passed}, Failed: ${results.failed}`);
        
    } catch (error) {
        console.log('✗ ERROR: Exception while running tests');
        console.log('Error: ' + error.message);
        console.log('Stack: ' + error.stack);
    }
}

function clearTestOutput() {
    testOutputDiv.innerHTML = '<p>JavaScript test output will appear here...</p>';
}

// Auto-run tests if requested via URL parameter
if (window.location.search.includes('autorun=true')) {
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(runJavaScriptTests, 1000); // Give page time to load
    });
}

// Load the test script
document.addEventListener('DOMContentLoaded', function() {
    // Try to load the unit tests script if not already loaded
    if (typeof KidsOverProfitsUnitTests === 'undefined') {
        const script = document.createElement('script');
        script.src = '<?php echo get_stylesheet_directory_uri(); ?>/js/unit-tests.js';
        script.onload = function() {
            console.log('✓ Unit test script loaded successfully');
        };
        script.onerror = function() {
            console.log('✗ Failed to load unit test script');
        };
        document.head.appendChild(script);
    }
});
</script>

<?php get_footer(); ?>
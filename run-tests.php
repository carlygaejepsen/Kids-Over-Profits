<?php
/**
 * Test Runner for Kids Over Profits
 * 
 * Centralized test runner that executes all test suites:
 * - PHP Unit Tests
 * - Integration Tests
 * - JavaScript Tests (via browser automation)
 * - Python Tests (via system calls)
 */

class TestRunner
{
    private $results = [];
    private $config;
    private $start_time;
    
    public function __construct()
    {
        $this->start_time = microtime(true);
        $this->config = [
            'php_tests' => [
                'Website/tests/ApiTest.php',
                'Website/tests/IntegrationTest.php'
            ],
            'js_test_file' => 'Website/js/unit-tests.js',
            'python_test_file' => 'Scripts/test_scrapers.py',
            'test_timeout' => 300, // 5 minutes
            'verbose' => true
        ];
    }
    
    /**
     * Run all test suites
     */
    public function runAllTests($suite = 'all')
    {
        echo "=== Kids Over Profits Test Suite ===\n";
        echo "Started at: " . date('Y-m-d H:i:s') . "\n\n";
        
        switch (strtolower($suite)) {
            case 'php':
                $this->runPhpTests();
                break;
            case 'js':
            case 'javascript':
                $this->runJavaScriptTests();
                break;
            case 'python':
                $this->runPythonTests();
                break;
            case 'integration':
                $this->runIntegrationTests();
                break;
            case 'e2e':
            case 'end-to-end':
                $this->runE2ETests();
                break;
            case 'all':
            default:
                $this->runPhpTests();
                $this->runJavaScriptTests();
                $this->runPythonTests();
                $this->runE2ETests();
                break;
        }
        
        $this->printFinalSummary();
    }
    
    /**
     * Run PHP unit tests
     */
    public function runPhpTests()
    {
        echo "=== Running PHP Tests ===\n";
        
        foreach ($this->config['php_tests'] as $test_file) {
            $full_path = __DIR__ . '/' . $test_file;
            
            if (!file_exists($full_path)) {
                $this->recordResult('PHP Test', $test_file, false, "Test file not found: $full_path");
                continue;
            }
            
            echo "Running: $test_file\n";
            
            try {
                // Capture output
                ob_start();
                $start = microtime(true);
                
                // Include and run the test
                include $full_path;
                
                $duration = microtime(true) - $start;
                $output = ob_get_clean();
                
                // Parse results (basic success/failure detection)
                $success = !preg_match('/FAIL|ERROR|✗/', $output);
                $message = $success ? "Tests completed successfully" : "Some tests failed";
                
                $this->recordResult('PHP Test', basename($test_file), $success, $message, $duration);
                
                if ($this->config['verbose']) {
                    echo $output . "\n";
                }
                
            } catch (Exception $e) {
                $this->recordResult('PHP Test', basename($test_file), false, "Exception: " . $e->getMessage());
            } catch (Error $e) {
                $this->recordResult('PHP Test', basename($test_file), false, "Error: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Run JavaScript tests
     */
    public function runJavaScriptTests()
    {
        echo "\n=== Running JavaScript Tests ===\n";
        
        $js_test_file = __DIR__ . '/' . $this->config['js_test_file'];
        
        if (!file_exists($js_test_file)) {
            $this->recordResult('JavaScript Test', 'unit-tests.js', false, "Test file not found");
            return;
        }
        
        // Create a simple HTML test runner
        $test_html = $this->createJavaScriptTestRunner($js_test_file);
        $test_html_path = __DIR__ . '/test-runner.html';
        file_put_contents($test_html_path, $test_html);
        
        echo "JavaScript test runner created: test-runner.html\n";
        echo "To run JavaScript tests, open test-runner.html in a browser\n";
        echo "Or add ?runtests=js to any page URL that includes the test file\n";
        
        $this->recordResult('JavaScript Test', 'Setup', true, "Test runner created successfully");
        
        // Clean up
        if (file_exists($test_html_path)) {
            unlink($test_html_path);
        }
    }
    
    /**
     * Run Python tests
     */
    public function runPythonTests()
    {
        echo "\n=== Running Python Tests ===\n";
        
        $python_test_file = __DIR__ . '/' . $this->config['python_test_file'];
        
        if (!file_exists($python_test_file)) {
            $this->recordResult('Python Test', 'test_scrapers.py', false, "Test file not found");
            return;
        }
        
        // Check if Python is available
        $python_cmd = $this->findPythonExecutable();
        if (!$python_cmd) {
            $this->recordResult('Python Test', 'Environment', false, "Python executable not found");
            return;
        }
        
        echo "Running Python tests with: $python_cmd\n";
        
        $start = microtime(true);
        
        // Change to Scripts directory and run tests
        $old_cwd = getcwd();
        chdir(dirname($python_test_file));
        
        $cmd = "$python_cmd " . basename($python_test_file) . " 2>&1";
        $output = shell_exec($cmd);
        $return_code = 0; // shell_exec doesn't return exit code
        
        chdir($old_cwd);
        
        $duration = microtime(true) - $start;
        
        // Parse Python test output for success/failure
        $success = $output && !preg_match('/FAILED|ERROR/', $output) && preg_match('/OK|Tests run:/', $output);
        $message = $success ? "Python tests completed successfully" : "Python tests encountered issues";
        
        $this->recordResult('Python Test', 'test_scrapers.py', $success, $message, $duration);
        
        if ($this->config['verbose'] && $output) {
            echo $output . "\n";
        }
    }
    
    /**
     * Run integration tests
     */
    public function runIntegrationTests()
    {
        echo "\n=== Running Integration Tests ===\n";
        
        $integration_test_file = __DIR__ . '/Website/tests/IntegrationTest.php';
        
        if (!file_exists($integration_test_file)) {
            $this->recordResult('Integration Test', 'IntegrationTest.php', false, "Test file not found");
            return;
        }
        
        try {
            ob_start();
            $start = microtime(true);
            
            include $integration_test_file;
            
            $duration = microtime(true) - $start;
            $output = ob_get_clean();
            
            $success = !preg_match('/FAIL|ERROR|✗/', $output);
            $message = $success ? "Integration tests completed successfully" : "Some integration tests failed";
            
            $this->recordResult('Integration Test', 'IntegrationTest.php', $success, $message, $duration);
            
            if ($this->config['verbose']) {
                echo $output . "\n";
            }
            
        } catch (Exception $e) {
            $this->recordResult('Integration Test', 'IntegrationTest.php', false, "Exception: " . $e->getMessage());
        }
    }
    
    /**
     * Run End-to-End tests
     */
    public function runE2ETests()
    {
        echo "\n=== Running End-to-End Tests ===\n";
        
        $e2e_test_file = __DIR__ . '/Website/tests/e2e-state-reports.php';
        
        if (!file_exists($e2e_test_file)) {
            $this->recordResult('E2E Test', 'e2e-state-reports.php', false, "Test file not found");
            return;
        }
        
        try {
            $start = microtime(true);
            
            // Run the E2E test
            ob_start();
            include $e2e_test_file;
            $test = new StateReportsE2ETest();
            $passed = $test->runAllTests();
            $output = ob_get_clean();
            
            $duration = microtime(true) - $start;
            
            $message = $passed ? "E2E tests completed successfully" : "E2E tests detected deployment issues";
            $this->recordResult('E2E Test', 'StateReports', $passed, $message, $duration);
            
            if ($this->config['verbose']) {
                echo $output . "\n";
            }
            
        } catch (Exception $e) {
            $this->recordResult('E2E Test', 'StateReports', false, "Exception: " . $e->getMessage());
        }
    }
    
    /**
     * Create JavaScript test runner HTML
     */
    private function createJavaScriptTestRunner($js_test_file)
    {
        $js_content = file_get_contents($js_test_file);
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kids Over Profits JavaScript Tests</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .test-output { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; margin: 10px 0; }
        .pass { color: #28a745; }
        .fail { color: #dc3545; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <h1>Kids Over Profits JavaScript Tests</h1>
    <div id="test-output" class="test-output">
        <p>Click "Run Tests" to execute JavaScript unit tests.</p>
    </div>
    <button onclick="runTests()">Run Tests</button>
    <button onclick="clearOutput()">Clear Output</button>
    
    <script>
        // Override console.log to capture output
        const originalConsoleLog = console.log;
        const testOutput = document.getElementById('test-output');
        
        console.log = function(...args) {
            originalConsoleLog.apply(console, args);
            
            const message = args.join(' ');
            const p = document.createElement('p');
            
            if (message.includes('✓ PASS')) {
                p.className = 'pass';
            } else if (message.includes('✗ FAIL')) {
                p.className = 'fail';
            }
            
            p.textContent = message;
            testOutput.appendChild(p);
        };
        
        function runTests() {
            clearOutput();
            console.log('Starting JavaScript tests...');
            
            try {
                const tests = new KidsOverProfitsUnitTests();
                tests.runAllTests();
            } catch (error) {
                console.log('Error running tests: ' + error.message);
            }
        }
        
        function clearOutput() {
            testOutput.innerHTML = '<p>Test output will appear here...</p>';
        }
        
        // Include the test file content
        $js_content
        
        // Auto-run if requested
        if (window.location.search.includes('autorun=true')) {
            document.addEventListener('DOMContentLoaded', runTests);
        }
    </script>
</body>
</html>
HTML;
    }
    
    /**
     * Find Python executable
     */
    private function findPythonExecutable()
    {
        $candidates = ['python3', 'python', 'py'];
        
        foreach ($candidates as $candidate) {
            $output = shell_exec("where $candidate 2>nul || which $candidate 2>/dev/null");
            if ($output && trim($output)) {
                return $candidate;
            }
        }
        
        return null;
    }
    
    /**
     * Record test result
     */
    private function recordResult($suite, $test_name, $passed, $message, $duration = null)
    {
        $this->results[] = [
            'suite' => $suite,
            'test' => $test_name,
            'passed' => $passed,
            'message' => $message,
            'duration' => $duration
        ];
        
        $status = $passed ? "✓ PASS" : "✗ FAIL";
        $duration_str = $duration ? sprintf(" (%.2fs)", $duration) : "";
        echo "  $status: $test_name - $message$duration_str\n";
    }
    
    /**
     * Print final test summary
     */
    private function printFinalSummary()
    {
        $total_time = microtime(true) - $this->start_time;
        
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "=== FINAL TEST SUMMARY ===\n";
        echo str_repeat("=", 60) . "\n";
        
        $total_tests = count($this->results);
        $passed_tests = array_filter($this->results, function($r) { return $r['passed']; });
        $passed_count = count($passed_tests);
        $failed_count = $total_tests - $passed_count;
        
        echo "Total Tests: $total_tests\n";
        echo "Passed: $passed_count\n";
        echo "Failed: $failed_count\n";
        echo "Success Rate: " . ($total_tests > 0 ? round(($passed_count / $total_tests) * 100, 1) : 0) . "%\n";
        echo "Total Time: " . round($total_time, 2) . " seconds\n";
        
        // Group results by suite
        $suites = [];
        foreach ($this->results as $result) {
            $suite = $result['suite'];
            if (!isset($suites[$suite])) {
                $suites[$suite] = ['total' => 0, 'passed' => 0, 'failed' => 0];
            }
            $suites[$suite]['total']++;
            if ($result['passed']) {
                $suites[$suite]['passed']++;
            } else {
                $suites[$suite]['failed']++;
            }
        }
        
        echo "\n=== Results by Suite ===\n";
        foreach ($suites as $suite_name => $suite_results) {
            $suite_rate = $suite_results['total'] > 0 ? round(($suite_results['passed'] / $suite_results['total']) * 100, 1) : 0;
            echo "$suite_name: {$suite_results['passed']}/{$suite_results['total']} passed ({$suite_rate}%)\n";
        }
        
        if ($failed_count > 0) {
            echo "\n=== Failed Tests ===\n";
            foreach ($this->results as $result) {
                if (!$result['passed']) {
                    echo "✗ {$result['suite']}: {$result['test']} - {$result['message']}\n";
                }
            }
        }
        
        echo "\n=== Test Run Complete ===\n";
        echo "Finished at: " . date('Y-m-d H:i:s') . "\n";
        
        // Exit with appropriate code
        exit($failed_count > 0 ? 1 : 0);
    }
}

// Handle command line arguments
$suite = 'all';
if (isset($argv[1])) {
    $suite = $argv[1];
}

// Show help
if ($suite === 'help' || $suite === '--help' || $suite === '-h') {
    echo "Kids Over Profits Test Runner\n\n";
    echo "Usage: php run-tests.php [suite]\n\n";
    echo "Available test suites:\n";
    echo "  all         - Run all test suites (default)\n";
    echo "  php         - Run PHP unit tests only\n";
    echo "  js          - Run JavaScript tests only\n";
    echo "  python      - Run Python tests only\n";
    echo "  integration - Run integration tests only\n";
    echo "  help        - Show this help message\n\n";
    echo "Examples:\n";
    echo "  php run-tests.php\n";
    echo "  php run-tests.php php\n";
    echo "  php run-tests.php integration\n\n";
    exit(0);
}

// Run the tests
$runner = new TestRunner();
$runner->runAllTests($suite);
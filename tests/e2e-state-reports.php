<?php
/**
 * End-to-End State Reports Loading Test
 * 
 * This test verifies that state reports actually load on the live website,
 * catching deployment gaps that unit tests miss.
 */

class StateReportsE2ETest {
    private $baseUrl = 'https://kidsoverprofits.org';
    private $states = ['ca', 'ut', 'az', 'tx', 'mt', 'ct', 'wa'];
    private $results = [];
    
    public function runAllTests() {
        echo "=== End-to-End State Reports Test ===\n\n";
        
        $this->testJavaScriptFilesAccessible();
        $this->testStateReportPagesLoad();
        $this->testReportDataLoads();
        
        $this->displayResults();
        return $this->allTestsPassed();
    }
    
    /**
     * Test 1: Verify critical JavaScript files are accessible on live site
     */
    private function testJavaScriptFilesAccessible() {
        echo "1. Testing JavaScript file accessibility...\n";
        
        $jsFiles = [
            'ca-reports.js',
            'facilities-display.js',
            'report-test.js'
        ];
        
        foreach ($jsFiles as $file) {
            $url = $this->baseUrl . '/wp-content/themes/child/js/' . $file;
            $response = $this->checkUrlAccessible($url);
            
            if ($response['status'] === 200) {
                $this->results[] = "✓ $file accessible ({$response['size']} bytes)";
                echo "   ✓ $file accessible\n";
            } else {
                $this->results[] = "✗ $file NOT ACCESSIBLE (HTTP {$response['status']})";
                echo "   ✗ $file NOT ACCESSIBLE (HTTP {$response['status']})\n";
            }
        }
        echo "\n";
    }
    
    /**
     * Test 2: Verify state report pages load without errors
     */
    private function testStateReportPagesLoad() {
        echo "2. Testing state report pages load...\n";
        
        foreach ($this->states as $state) {
            $url = $this->baseUrl . '/' . $state . '-reports';
            $response = $this->checkPageContent($url);
            
            if ($response['status'] === 200 && !$response['hasError']) {
                $this->results[] = "✓ {$state} reports page loads successfully";
                echo "   ✓ {$state}-reports page loads\n";
            } else {
                $this->results[] = "✗ {$state} reports page FAILED (HTTP {$response['status']})";
                echo "   ✗ {$state}-reports page FAILED\n";
            }
        }
        echo "\n";
    }
    
    /**
     * Test 3: Verify report data actually loads (not stuck on "Loading...")
     */
    private function testReportDataLoads() {
        echo "3. Testing report data loads (not stuck loading)...\n";
        
        foreach ($this->states as $state) {
            $url = $this->baseUrl . '/' . $state . '-reports';
            $content = $this->getPageContent($url);
            
            // Check if page is stuck on "Loading report data..."
            if (strpos($content, 'Loading report data...') !== false && 
                strpos($content, 'facility-item') === false) {
                $this->results[] = "✗ {$state} reports STUCK ON LOADING (likely JS 404 errors)";
                echo "   ✗ {$state}-reports stuck on 'Loading report data...'\n";
            } else if (strpos($content, 'facility-item') !== false || 
                      strpos($content, 'facilities-container') !== false) {
                $this->results[] = "✓ {$state} reports data loaded successfully";
                echo "   ✓ {$state}-reports data loads properly\n";
            } else {
                $this->results[] = "⚠ {$state} reports status unclear (manual check needed)";
                echo "   ⚠ {$state}-reports status unclear\n";
            }
        }
        echo "\n";
    }
    
    /**
     * Check if a URL is accessible and return status info
     */
    private function checkUrlAccessible($url) {
        // Use file_get_contents with context for simpler HTTP requests
        $context = stream_context_create([
            'http' => [
                'method' => 'HEAD',
                'timeout' => 10,
                'user_agent' => 'StateReports E2E Test/1.0',
                'ignore_errors' => true
            ]
        ]);
        
        $headers = @get_headers($url, 1, $context);
        
        if ($headers === false) {
            return [
                'status' => 0,
                'size' => 0,
                'accessible' => false
            ];
        }
        
        // Extract status code from first header line
        $status = 0;
        if (isset($headers[0])) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headers[0], $matches);
            $status = isset($matches[1]) ? (int)$matches[1] : 0;
        }
        
        $size = isset($headers['Content-Length']) ? $headers['Content-Length'] : 'unknown';
        
        return [
            'status' => $status,
            'size' => $size,
            'accessible' => $status === 200
        ];
    }
    
    /**
     * Get full page content and check for errors
     */
    private function checkPageContent($url) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'StateReports E2E Test/1.0',
                'ignore_errors' => true
            ]
        ]);
        
        $content = @file_get_contents($url, false, $context);
        
        // Get status from response headers
        $status = 200; // Default assumption
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                    $status = (int)$matches[1];
                    break;
                }
            }
        }
        
        if ($content === false) {
            return [
                'status' => 0,
                'content' => '',
                'hasError' => true
            ];
        }
        
        $hasError = strpos($content, '404') !== false || 
                   strpos($content, 'Not Found') !== false ||
                   strpos($content, 'Error') !== false;
        
        return [
            'status' => $status,
            'content' => $content,
            'hasError' => $hasError
        ];
    }
    
    /**
     * Get page content for analysis
     */
    private function getPageContent($url) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'StateReports E2E Test/1.0',
                'ignore_errors' => true
            ]
        ]);
        
        $content = @file_get_contents($url, false, $context);
        
        return $content !== false ? $content : '';
    }
    
    /**
     * Display test results summary
     */
    private function displayResults() {
        echo "=== TEST RESULTS SUMMARY ===\n";
        foreach ($this->results as $result) {
            echo "$result\n";
        }
        
        $passed = count(array_filter($this->results, function($r) { return strpos($r, '✓') === 0; }));
        $failed = count(array_filter($this->results, function($r) { return strpos($r, '✗') === 0; }));
        $warnings = count(array_filter($this->results, function($r) { return strpos($r, '⚠') === 0; }));
        
        echo "\nSUMMARY: $passed passed, $failed failed, $warnings warnings\n";
        
        if ($failed > 0) {
            echo "\n🎯 DEPLOYMENT ISSUE DETECTED:\n";
            echo "   - JavaScript files missing from live website (404 errors)\n";
            echo "   - State reports stuck on 'Loading report data...'\n";
            echo "   - Run diagnostic: php diagnose-state-reports.php\n";
        }
    }
    
    /**
     * Check if all critical tests passed
     */
    private function allTestsPassed() {
        $criticalFailures = array_filter($this->results, function($result) {
            return strpos($result, '✗') === 0 && 
                   (strpos($result, 'NOT ACCESSIBLE') !== false || 
                    strpos($result, 'STUCK ON LOADING') !== false);
        });
        
        return count($criticalFailures) === 0;
    }
}

// Run the test if called directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $test = new StateReportsE2ETest();
    $passed = $test->runAllTests();
    exit($passed ? 0 : 1);
}
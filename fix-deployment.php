<?php
/**
 * Deployment Fix Script for Kids Over Profits
 * 
 * This script provides multiple strategies to fix the deployment gap
 * where JavaScript files aren't reaching the live website.
 */

class DeploymentFixer {
    private $jsFiles = [
        'Website/js/ca-reports.js',
        'Website/js/facilities-display.js', 
        'Website/js/report-test.js'
    ];
    
    private $baseUrl = 'https://kidsoverprofits.org';
    private $deploymentPath = '/home/kidsover/public_html/wp-content/themes/child/';
    
    public function diagnoseAndFix() {
        echo "=== Kids Over Profits Deployment Fix ===\n\n";
        
        // Step 1: Verify local files
        if (!$this->verifyLocalFiles()) {
            echo "❌ Local files missing. Cannot proceed.\n";
            return false;
        }
        
        // Step 2: Try Git deployment fix
        $this->tryGitDeploymentFix();
        
        // Step 3: Generate manual deployment commands  
        $this->generateManualDeploymentCommands();
        
        // Step 4: Create deployment verification
        $this->createDeploymentVerification();
        
        return true;
    }
    
    private function verifyLocalFiles() {
        echo "1. Verifying local JavaScript files...\n";
        $allExist = true;
        
        foreach ($this->jsFiles as $file) {
            if (file_exists($file)) {
                $size = filesize($file);
                echo "   ✓ $file ($size bytes)\n";
            } else {
                echo "   ✗ $file MISSING\n";
                $allExist = false;
            }
        }
        
        return $allExist;
    }
    
    private function tryGitDeploymentFix() {
        echo "\n2. Attempting Git deployment fix...\n";
        
        // Force a new deployment by updating a critical file
        $touchFile = 'Website/deployment-trigger.txt';
        $timestamp = date('Y-m-d H:i:s');
        
        file_put_contents($touchFile, "Deployment triggered: $timestamp\n");
        echo "   ✓ Created deployment trigger file\n";
        
        // Git commands
        echo "   📝 Run these Git commands to trigger deployment:\n";
        echo "      git add .\n";
        echo "      git commit -m \"Fix deployment: Force sync JS files - $timestamp\"\n";
        echo "      git push origin main\n";
        echo "      Then wait 2-3 minutes for cPanel to sync\n\n";
    }
    
    private function generateManualDeploymentCommands() {
        echo "3. Manual deployment options if Git fails...\n";
        
        echo "   A. cPanel File Manager:\n";
        echo "      - Log into cPanel\n";
        echo "      - Navigate to File Manager\n";
        echo "      - Go to /public_html/wp-content/themes/child/js/\n";
        echo "      - Upload these files manually:\n";
        
        foreach ($this->jsFiles as $file) {
            $filename = basename($file);
            echo "        • $filename\n";
        }
        
        echo "\n   B. FTP/SFTP Upload:\n";
        echo "      - Connect to your hosting FTP\n";
        echo "      - Navigate to: {$this->deploymentPath}js/\n";
        echo "      - Upload all files from Website/js/ folder\n";
        
        echo "\n   C. Direct rsync (if you have SSH access):\n";
        echo "      rsync -aP Website/js/ user@host:{$this->deploymentPath}js/\n\n";
    }
    
    private function createDeploymentVerification() {
        echo "4. Creating deployment verification script...\n";
        
        $verificationScript = '<!DOCTYPE html>
<html>
<head>
    <title>Deployment Verification</title>
</head>
<body>
    <h1>Kids Over Profits - Deployment Verification</h1>
    <div id="results"></div>
    
    <script>
        const jsFiles = [
            "/wp-content/themes/child/Website/js/ca-reports.js",
            "/wp-content/themes/child/Website/js/facilities-display.js", 
            "/wp-content/themes/child/Website/js/report-test.js"
        ];
        
        const results = document.getElementById("results");
        let html = "<h2>JavaScript File Accessibility Test</h2>";
        
        jsFiles.forEach(file => {
            fetch(file)
                .then(response => {
                    if (response.ok) {
                        html += `<p style="color: green;">✓ ${file} - ACCESSIBLE (${response.status})</p>`;
                    } else {
                        html += `<p style="color: red;">✗ ${file} - NOT ACCESSIBLE (${response.status})</p>`;
                    }
                    results.innerHTML = html;
                })
                .catch(error => {
                    html += `<p style="color: red;">✗ ${file} - ERROR: ${error.message}</p>`;
                    results.innerHTML = html;
                });
        });
    </script>
</body>
</html>';
        
        file_put_contents('deployment-verification.html', $verificationScript);
        echo "   ✓ Created deployment-verification.html\n";
        echo "   📋 After deployment, upload this file and visit it to test\n\n";
    }
    
    public function checkDeploymentStatus() {
        echo "5. Checking current deployment status...\n";
        
        foreach ($this->jsFiles as $file) {
            $filename = basename($file);
            $url = $this->baseUrl . '/wp-content/themes/child/Website/js/' . $filename;
            
            $headers = @get_headers($url, 1);
            if ($headers && strpos($headers[0], '200') !== false) {
                echo "   ✓ $filename is accessible\n";
            } else {
                echo "   ✗ $filename is NOT accessible (404)\n";
            }
        }
    }
}

// Alternative: Create a simple deployment test
function createQuickDeploymentTest() {
    echo "\n=== Quick Deployment Test ===\n";
    echo "Creating test file to verify deployment is working...\n";
    
    $testContent = "<?php\necho 'Deployment test: ' . date('Y-m-d H:i:s');\n?>";
    file_put_contents('Website/deployment-test.php', $testContent);
    
    echo "✓ Created Website/deployment-test.php\n";
    echo "📝 After pushing this file, check: https://kidsoverprofits.org/wp-content/themes/child/Website/deployment-test.php\n";
    echo "   If it shows the timestamp, deployment is working\n";
    echo "   If it shows 404, deployment is broken\n\n";
}

// Run the deployment fixer
$fixer = new DeploymentFixer();
$fixer->diagnoseAndFix();
$fixer->checkDeploymentStatus();
createQuickDeploymentTest();

echo "=== Summary of Actions Required ===\n";
echo "1. Run the Git commands shown above to trigger deployment\n";
echo "2. Wait 2-3 minutes for cPanel to sync\n";
echo "3. Test with: php diagnose-state-reports.php\n";
echo "4. If still broken, use manual upload option A or B\n";
echo "5. Verify with deployment-verification.html\n";
echo "6. Run E2E test: php run-tests.php e2e\n\n";
echo "🎯 Goal: State reports should show facility data instead of 'Loading...'\n";
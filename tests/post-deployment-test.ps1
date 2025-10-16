# Quick post-deployment verification test for Windows
# Kids Over Profits - PowerShell Test Script

$SITE_URL = "https://kidsoverprofits.org"
$ERRORS = 0

Write-Host ""
Write-Host "Kids Over Profits - Deployment Test" -ForegroundColor Cyan
Write-Host "====================================" -ForegroundColor Cyan
Write-Host ""

# Test 1: Homepage loads
Write-Host "Testing homepage... " -NoNewline
try {
    $response = Invoke-WebRequest -Uri $SITE_URL -UseBasicParsing -TimeoutSec 10
    if ($response.StatusCode -eq 200) {
        Write-Host "✅ OK (HTTP $($response.StatusCode))" -ForegroundColor Green
    } else {
        Write-Host "❌ FAIL (HTTP $($response.StatusCode))" -ForegroundColor Red
        $ERRORS++
    }
} catch {
    Write-Host "❌ FAIL (Error: $($_.Exception.Message))" -ForegroundColor Red
    $ERRORS++
}

# Test 2: API endpoint accessible
Write-Host "Testing API endpoint... " -NoNewline
$API_URL = "$SITE_URL/wp-content/themes/child/api/data_form/get-master-data.php"
try {
    $response = Invoke-WebRequest -Uri $API_URL -UseBasicParsing -TimeoutSec 10
    if ($response.StatusCode -eq 200) {
        Write-Host "✅ OK (HTTP $($response.StatusCode))" -ForegroundColor Green
    } else {
        Write-Host "❌ FAIL (HTTP $($response.StatusCode))" -ForegroundColor Red
        $ERRORS++
    }
} catch {
    Write-Host "❌ FAIL (Error: $($_.Exception.Message))" -ForegroundColor Red
    $ERRORS++
}

# Test 3: CSS files load
Write-Host "Testing CSS files... " -NoNewline
$CSS_URL = "$SITE_URL/wp-content/themes/child/css/common.css"
try {
    $response = Invoke-WebRequest -Uri $CSS_URL -UseBasicParsing -TimeoutSec 10
    if ($response.StatusCode -eq 200) {
        Write-Host "✅ OK (HTTP $($response.StatusCode))" -ForegroundColor Green
    } else {
        Write-Host "❌ FAIL (HTTP $($response.StatusCode))" -ForegroundColor Red
        $ERRORS++
    }
} catch {
    Write-Host "❌ FAIL (Error: $($_.Exception.Message))" -ForegroundColor Red
    $ERRORS++
}

# Test 4: JS files load
Write-Host "Testing JS files... " -NoNewline
$JS_URL = "$SITE_URL/wp-content/themes/child/js/theme-base-bootstrap.js"
try {
    $response = Invoke-WebRequest -Uri $JS_URL -UseBasicParsing -TimeoutSec 10
    if ($response.StatusCode -eq 200) {
        Write-Host "✅ OK (HTTP $($response.StatusCode))" -ForegroundColor Green
    } else {
        Write-Host "❌ FAIL (HTTP $($response.StatusCode))" -ForegroundColor Red
        $ERRORS++
    }
} catch {
    Write-Host "❌ FAIL (Error: $($_.Exception.Message))" -ForegroundColor Red
    $ERRORS++
}

# Test 5: New fallback loader exists
Write-Host "Testing fallback loader... " -NoNewline
$FALLBACK_URL = "$SITE_URL/wp-content/themes/child/js/css-fallback-loader.js"
try {
    $response = Invoke-WebRequest -Uri $FALLBACK_URL -UseBasicParsing -TimeoutSec 10
    if ($response.StatusCode -eq 200) {
        Write-Host "✅ OK (HTTP $($response.StatusCode))" -ForegroundColor Green
    } else {
        Write-Host "❌ FAIL (HTTP $($response.StatusCode))" -ForegroundColor Red
        $ERRORS++
    }
} catch {
    Write-Host "❌ FAIL (Error: $($_.Exception.Message))" -ForegroundColor Red
    $ERRORS++
}

# Test 6: API resolver exists
Write-Host "Testing API resolver... " -NoNewline
$RESOLVER_URL = "$SITE_URL/wp-content/themes/child/js/api-endpoint-resolver.js"
try {
    $response = Invoke-WebRequest -Uri $RESOLVER_URL -UseBasicParsing -TimeoutSec 10
    if ($response.StatusCode -eq 200) {
        Write-Host "✅ OK (HTTP $($response.StatusCode))" -ForegroundColor Green
    } else {
        Write-Host "❌ FAIL (HTTP $($response.StatusCode))" -ForegroundColor Red
        $ERRORS++
    }
} catch {
    Write-Host "❌ FAIL (Error: $($_.Exception.Message))" -ForegroundColor Red
    $ERRORS++
}

Write-Host ""
Write-Host "====================================" -ForegroundColor Cyan
if ($ERRORS -eq 0) {
    Write-Host "✅ ALL TESTS PASSED" -ForegroundColor Green
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Yellow
    Write-Host "1. Visit https://kidsoverprofits.org/data/ and test the form"
    Write-Host "2. Check browser console for any errors"
    Write-Host "3. Monitor error logs for the next hour"
    Write-Host ""
    exit 0
} else {
    Write-Host "❌ $ERRORS TEST(S) FAILED" -ForegroundColor Red
    Write-Host ""
    Write-Host "Troubleshooting:" -ForegroundColor Yellow
    Write-Host "1. Check cPanel deployment logs"
    Write-Host "2. Verify .env file exists on server"
    Write-Host "3. Check file permissions"
    Write-Host ""
    exit 1
}

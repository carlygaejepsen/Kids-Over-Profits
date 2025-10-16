@echo off
REM Quick post-deployment verification test for Windows
REM Kids Over Profits - Command Prompt Test Script

echo.
echo Kids Over Profits - Deployment Test
echo ====================================
echo.

REM Check if curl is available
where curl >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo ERROR: curl is not installed or not in PATH
    echo.
    echo Please either:
    echo 1. Use PowerShell script: powershell -ExecutionPolicy Bypass -File tests\post-deployment-test.ps1
    echo 2. Install curl from: https://curl.se/windows/
    echo 3. Manually test URLs in browser
    echo.
    pause
    exit /b 1
)

echo Testing with curl...
echo.

REM Test 1: Homepage
curl -s -o NUL -w "Homepage:           HTTP %%{http_code}\n" https://kidsoverprofits.org

REM Test 2: API endpoint
curl -s -o NUL -w "API Endpoint:       HTTP %%{http_code}\n" https://kidsoverprofits.org/wp-content/themes/child/api/data_form/get-master-data.php

REM Test 3: CSS files
curl -s -o NUL -w "CSS Files:          HTTP %%{http_code}\n" https://kidsoverprofits.org/wp-content/themes/child/css/common.css

REM Test 4: JS files
curl -s -o NUL -w "JS Files:           HTTP %%{http_code}\n" https://kidsoverprofits.org/wp-content/themes/child/js/theme-base-bootstrap.js

REM Test 5: Fallback loader
curl -s -o NUL -w "Fallback Loader:    HTTP %%{http_code}\n" https://kidsoverprofits.org/wp-content/themes/child/js/css-fallback-loader.js

REM Test 6: API resolver
curl -s -o NUL -w "API Resolver:       HTTP %%{http_code}\n" https://kidsoverprofits.org/wp-content/themes/child/js/api-endpoint-resolver.js

echo.
echo ====================================
echo Test complete!
echo.
echo Status codes:
echo   200 = OK (Success)
echo   404 = Not Found
echo   500 = Server Error
echo.
echo If all tests show HTTP 200, deployment was successful!
echo.
echo Next steps:
echo 1. Visit https://kidsoverprofits.org/data/
echo 2. Test the data submission form
echo 3. Check browser console for errors
echo.
pause

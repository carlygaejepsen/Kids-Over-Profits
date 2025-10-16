@echo off
REM Helper script to create .env file for Kids Over Profits
REM This creates a local .env file with database credentials

echo.
echo Kids Over Profits - .env File Creator
echo ======================================
echo.
echo This script will create a .env file in the api folder.
echo.
echo WARNING: This file contains database credentials.
echo Make sure .gitignore is configured to exclude it!
echo.
pause

cd api

REM Create .env file
(
echo DB_HOST=localhost
echo DB_NAME=kidsover_suggestions
echo DB_USER=kidsover_dani
echo DB_PASS=Xk4^&z9!pT#vR7bN@
) > .env

if exist .env (
    echo.
    echo SUCCESS: .env file created at api\.env
    echo.
    echo File contents:
    type .env
    echo.
    echo.
    echo IMPORTANT: You still need to create this file on the server!
    echo.
    echo Steps to create .env on server:
    echo 1. Log into cPanel
    echo 2. Go to File Manager
    echo 3. Navigate to: /home/kidsover/public_html/wp-content/themes/child/api/
    echo 4. Create new file named: .env
    echo 5. Copy the contents shown above into that file
    echo 6. Save and set permissions to 600
    echo.
) else (
    echo.
    echo ERROR: Failed to create .env file
    echo.
)

cd ..
pause

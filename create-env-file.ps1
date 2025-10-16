# Helper script to create .env file for Kids Over Profits
# PowerShell version

Write-Host ""
Write-Host "Kids Over Profits - .env File Creator" -ForegroundColor Cyan
Write-Host "======================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "This script will create a .env file in the api folder." -ForegroundColor Yellow
Write-Host ""
Write-Host "WARNING: This file contains database credentials." -ForegroundColor Red
Write-Host "Make sure .gitignore is configured to exclude it!" -ForegroundColor Red
Write-Host ""

$confirmation = Read-Host "Continue? (y/n)"
if ($confirmation -ne 'y') {
    Write-Host "Cancelled." -ForegroundColor Yellow
    exit
}

# Create .env file
$envContent = @"
DB_HOST=localhost
DB_NAME=kidsover_suggestions
DB_USER=kidsover_dani
DB_PASS=Xk4&z9!pT#vR7bN@
"@

try {
    Set-Content -Path "api\.env" -Value $envContent -NoNewline

    Write-Host ""
    Write-Host "SUCCESS: .env file created at api\.env" -ForegroundColor Green
    Write-Host ""
    Write-Host "File contents:" -ForegroundColor Cyan
    Write-Host $envContent
    Write-Host ""
    Write-Host ""
    Write-Host "IMPORTANT: You still need to create this file on the server!" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Steps to create .env on server:" -ForegroundColor Cyan
    Write-Host "1. Log into cPanel"
    Write-Host "2. Go to File Manager"
    Write-Host "3. Navigate to: /home/kidsover/public_html/wp-content/themes/child/api/"
    Write-Host "4. Create new file named: .env"
    Write-Host "5. Copy the contents shown above into that file"
    Write-Host "6. Save and set permissions to 600"
    Write-Host ""

} catch {
    Write-Host ""
    Write-Host "ERROR: Failed to create .env file" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
    Write-Host ""
}

Read-Host "Press Enter to exit"

# ==========================================
# Coalition‑Grade OCR Batch Script (Clean pass)
# Requires: ocrmypdf installed and in PATH
# ==========================================

# === CONFIG ===
$InputFolder  = "C:\Users\daniu\Downloads\ocr batch"
$OutputFolder = "C:\Users\daniu\Downloads\ocr batch\output_pdfs"
$LogFolder    = "C:\Users\daniu\Downloads\ocr batch\ocr_logs"
$ToolVersion  = & ocrmypdf --version

# Auto-create folders if missing
New-Item -ItemType Directory -Force -Path $InputFolder, $OutputFolder, $LogFolder | Out-Null

# Timestamp for run
$Timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$RunLog    = Join-Path $LogFolder "ocr_run_$Timestamp.log"

# === PROCESS FILES ===
$Files = Get-ChildItem -Path $InputFolder -Filter *.pdf

foreach ($File in $Files) {

    $InputPath  = $File.FullName
    $OutputPath = Join-Path $OutputFolder $File.Name

    Write-Host "OCR'ing: $($File.Name)"

    try {
        & ocrmypdf `
            --redo-ocr `
            --output-type pdfa `
            "$InputPath" "$OutputPath" 2>&1 |
            Tee-Object -FilePath $RunLog -Append

        Add-Content -Path $RunLog -Value "✅ Success: $($File.Name)"
    }
    catch {
        Add-Content -Path $RunLog -Value "❌ Error: $($File.Name) - $($_.Exception.Message)"
        Write-Warning "Failed: $($File.Name)"
    }
}

# === PROVENANCE FOOTER ===
Add-Content -Path $RunLog -Value "`n--- OCR Batch Complete ---"
Add-Content -Path $RunLog -Value "Tool Version: $ToolVersion"
Add-Content -Path $RunLog -Value "Run Timestamp: $Timestamp"
Add-Content -Path $RunLog -Value "Input Folder: $InputFolder"
Add-Content -Path $RunLog -Value "Output Folder: $OutputFolder"
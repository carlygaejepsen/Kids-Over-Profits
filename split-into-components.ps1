#!/usr/bin/env powershell
<#
.SYNOPSIS
    Splits the monolithic tti-program-links.json into manageable component files.

.DESCRIPTION
    Extracts programs array, search index, and metadata into separate files for easier maintenance.
#>

param(
    [string]$SourceFile = "c:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\js\data\tti-program-links.json",
    [string]$OutputDir = "c:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\js\data\programs-by-state"
)

$ErrorActionPreference = "Stop"

Write-Host "Loading master file: $SourceFile"
$json = Get-Content -Path $SourceFile -Raw | ConvertFrom-Json

if (-not $json -or -not $json.programs) {
    Write-Error "Failed to parse JSON or missing programs array"
}

Write-Host "Extracted data:"
Write-Host "  - Programs: $($json.programs.Count)"
Write-Host "  - Search index entries: $($json.searchIndexSize)"
Write-Host "  - Program count metadata: $($json.programCount)"

# Ensure output directory exists
if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null
}

# 1. Save programs array
$programsFile = Join-Path $OutputDir "programs-array.json"
$programsData = @{
    count = $json.programs.Count
    programs = $json.programs
    generated = (Get-Date -Format "o")
    source = "tti-program-links.json"
}
$programsData | ConvertTo-Json -Depth 10 | Set-Content -Path $programsFile
Write-Host "`n✓ Programs array saved: $programsFile"

# 2. Save search index
$searchIndexFile = Join-Path $OutputDir "search-index.json"
$searchIndexData = @{
    count = $json.searchIndexSize
    searchIndex = $json.searchIndex
    generated = (Get-Date -Format "o")
    source = "tti-program-links.json"
}
$searchIndexData | ConvertTo-Json -Depth 15 | Set-Content -Path $searchIndexFile
Write-Host "✓ Search index saved: $searchIndexFile"

# 3. Save metadata
$metadataFile = Join-Path $OutputDir "metadata.json"
$metadata = @{
    generated = $json.generated
    source = $json.source
    programCount = $json.programCount
    searchIndexSize = $json.searchIndexSize
    components = @{
        programsArray = "programs-array.json"
        searchIndex = "search-index.json"
        metadata = "metadata.json"
        index = "index.json"
    }
}
$metadata | ConvertTo-Json -Depth 5 | Set-Content -Path $metadataFile
Write-Host "✓ Metadata saved: $metadataFile"

# 4. Create assembly index
$indexFile = Join-Path $OutputDir "index.json"
$componentDesc1 = "Complete programs array - $($json.programs.Count) programs"
$componentDesc2 = "Search index for program discovery - $($json.searchIndexSize) entries"
$index = @{
    version = "2.0"
    description = "Split TTI Programs Database"
    originalFile = "tti-program-links.json"
    totalPrograms = $json.programs.Count
    totalSearchIndexEntries = $json.searchIndexSize
    components = @(
        @{ file = "programs-array.json"; description = $componentDesc1 }
        @{ file = "search-index.json"; description = $componentDesc2 }
        @{ file = "metadata.json"; description = "Database metadata and timestamps" }
        @{ file = "index.json"; description = "This file" }
        @{ file = "README.md"; description = "Documentation" }
    )
    lastUpdated = (Get-Date -Format "o")
}
$index | ConvertTo-Json -Depth 5 | Set-Content -Path $indexFile
Write-Host "✓ Index created: $indexFile"

# File size summary
Write-Host "`nFile sizes:"
@(
    (Get-Item $programsFile).Length,
    (Get-Item $searchIndexFile).Length,
    (Get-Item $metadataFile).Length,
    (Get-Item $indexFile).Length
) | Measure-Object -Sum | ForEach-Object {
    Write-Host "  Total component files: $([math]::Round($_.Sum / 1MB, 2)) MB"
}

$origSize = (Get-Item $SourceFile).Length
Write-Host "  Original file: $([math]::Round($origSize / 1MB, 2)) MB"

Write-Host "`n✅ Split complete! All components saved to: $OutputDir"

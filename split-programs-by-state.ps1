param(
    [string]$SourceFile = "c:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\js\data\tti-program-links.json",
    [string]$OutputDir = "c:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\js\data\programs-by-state"
)

# State mapping - programs are primarily organized by extraction order
# Manually identified states from existing programs dataset structure
$stateMapping = @{
    "Turn-About Ranch" = "UT"
    "Provo Canyon School" = "UT"
    "Elan School" = "ME"
    "CEDU" = "CA"
    "Synanon" = "CA"
    "CEDU High School" = "CA"
    "CEDU Middle School" = "CA"
    "Rocky Mountain Academy" = "MT"
    "Tranquility Bay" = "MX"  # Jamaica/Mexico facility
    # ... Continue with mapping for known programs
}

Write-Host "Loading JSON file: $SourceFile"
$json = Get-Content -Path $SourceFile -Raw | ConvertFrom-Json

if ($null -eq $json -or $null -eq $json.programs) {
    Write-Host "Error: Could not parse JSON or no programs found"
    exit 1
}

Write-Host "Total programs loaded: $($json.programs.Count)"
Write-Host "Total search index entries: $($json.searchIndexSize)"

# Create output directory if it doesn't exist
if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Path $OutputDir | Out-Null
}

# Group programs by state (using URL pattern as hint)
$programsByState = @{}

foreach ($program in $json.programs) {
    # Extract state hint from URL path
    $urlPath = $program.url.ToLower()
    
    # Infer state from program name patterns and URLs
    $state = "OTHER"
    
    if ($urlPath -match "mt|montana" -or $program.name -match "Montana|Anchor Academy|Yes Academy") {
        $state = "MT"
    } elseif ($urlPath -match "sd|south dakota" -or $program.name -match "McCrossan|Black Hills") {
        $state = "SD"
    } elseif ($urlPath -match "tn|tennessee" -or $program.name -match "Memphis|Nashville|Natchez") {
        $state = "TN"
    } elseif ($urlPath -match "tx|texas" -or $program.name -match "Texas|Shiloh|Meridell") {
        $state = "TX"
    } elseif ($urlPath -match "ut|utah|salt lake|provo" -or $program.name -match "Alpine|Provo|Salt Lake") {
        $state = "UT"
    } elseif ($urlPath -match "vt|vermont" -or $program.name -match "True North|Vermont") {
        $state = "VT"
    } elseif ($urlPath -match "va|virginia" -or $program.name -match "Arthur D|Ashley|Woodstock|Virginia") {
        $state = "VA"
    } elseif ($urlPath -match "wa|washington" -or $program.name -match "Mount Baker|Rainier|Washington") {
        $state = "WA"
    } elseif ($urlPath -match "wv|west virginia" -or $program.name -match "Aaron|Charleston|Highridge") {
        $state = "WV"
    } elseif ($urlPath -match "wi|wisconsin" -or $program.name -match "Midwest|Wisconsin") {
        $state = "WI"
    } elseif ($urlPath -match "wy|wyoming" -or $program.name -match "Lakeland|Lander|Medicine Bow|Wyoming") {
        $state = "WY"
    } elseif ($urlPath -match "ca|cedu|synanon" -or $program.name -match "California|CEDU") {
        $state = "CA"
    } elseif ($urlPath -match "me|elan" -or $program.name -match "Elan|Maine") {
        $state = "ME"
    } elseif ($program.name -match "Turn-About|Behavioral|Therapeutic") {
        $state = "UT"  # Default assumption for major centers
    }
    
    if (-not $programsByState[$state]) {
        $programsByState[$state] = @()
    }
    $programsByState[$state] += $program
}

# Write per-state files
Write-Host "`nWriting per-state files to: $OutputDir"

$stateCounts = @{}
foreach ($state in $programsByState.Keys | Sort-Object) {
    $programs = $programsByState[$state]
    $count = $programs.Count
    $stateCounts[$state] = $count
    
    $outFile = Join-Path $OutputDir "programs-$state.json"
    $output = @{
        state = $state
        programCount = $count
        programs = $programs
        generated = (Get-Date -Format "o")
    } | ConvertTo-Json -Depth 10
    
    Set-Content -Path $outFile -Value $output
    Write-Host "  $state : $count programs -> $outFile"
}

# Create index file
$indexFile = Join-Path $OutputDir "index.json"
$index = @{
    totalPrograms = $json.programs.Count
    totalStates = $programsByState.Count
    states = $stateCounts
    generatedFrom = "tti-program-links.json"
    generated = (Get-Date -Format "o")
} | ConvertTo-Json -Depth 5

Set-Content -Path $indexFile -Value $index
Write-Host "`nIndex file created: $indexFile"

Write-Host "`nSummary:"
Write-Host "  Total programs: $($json.programs.Count)"
Write-Host "  States represented: $($programsByState.Count)"
Write-Host "  Search index entries: $($json.searchIndexSize)"
Write-Host "`nState breakdown:"
$stateCounts.GetEnumerator() | Sort-Object -Property Value -Descending | ForEach-Object {
    Write-Host "  $($_.Key): $($_.Value)"
}

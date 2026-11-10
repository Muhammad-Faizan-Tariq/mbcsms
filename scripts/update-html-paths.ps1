# MBC SMS - Update HTML Asset Paths
# Updates all HTML files to use shared assets path (/ints/assets/)

param(
    [switch]$DryRun = $false
)

$ErrorActionPreference = "Stop"
$ProjectRoot = "C:\Users\hp\CascadeProjects\MBC SMS"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "MBC SMS - Update HTML Asset Paths" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

if ($DryRun) {
    Write-Host "[DRY RUN MODE] No changes will be made" -ForegroundColor Yellow
    Write-Host ""
}

# Path replacements
$Replacements = @(
    # CSS paths
    @{ Old = 'href="../css/'; New = 'href="/ints/assets/css/' },
    @{ Old = "href='../css/"; New = "href='/ints/assets/css/" },
    @{ Old = 'href="../../css/'; New = 'href="/ints/assets/css/' },
    
    # JS paths
    @{ Old = 'src="../js/'; New = 'src="/ints/assets/js/' },
    @{ Old = "src='../js/"; New = "src='/ints/assets/js/" },
    @{ Old = 'src="../../js/'; New = 'src="/ints/assets/js/' },
    
    # IMG paths
    @{ Old = 'src="../img/'; New = 'src="/ints/assets/img/' },
    @{ Old = "src='../img/"; New = "src='/ints/assets/img/" },
    @{ Old = 'src="../../img/'; New = 'src="/ints/assets/img/' },
    
    # ICO paths
    @{ Old = 'href="../ico/'; New = 'href="/ints/assets/ico/' },
    @{ Old = "href='../ico/"; New = "href='/ints/assets/ico/" },
    
    # Fix double slashes
    @{ Old = '/ints/assets//'; New = '/ints/assets/' }
)

# Find all HTML files in public folder
$HtmlFiles = @()
$PublicRoot = "$ProjectRoot\public\ints"

if (Test-Path "$PublicRoot\manager") {
    $HtmlFiles += Get-ChildItem -Path "$PublicRoot\manager" -Filter "*.html" -File
}
if (Test-Path "$PublicRoot\agent") {
    $HtmlFiles += Get-ChildItem -Path "$PublicRoot\agent" -Filter "*.html" -File
}
if (Test-Path "$PublicRoot\client") {
    $HtmlFiles += Get-ChildItem -Path "$PublicRoot\client" -Filter "*.html" -File
}
if (Test-Path "$PublicRoot\login.html") {
    $HtmlFiles += Get-Item "$PublicRoot\login.html"
}

Write-Host "Found $($HtmlFiles.Count) HTML files to process" -ForegroundColor Green
Write-Host ""

$UpdatedFiles = 0
$TotalChanges = 0

foreach ($File in $HtmlFiles) {
    $Content = Get-Content -Path $File.FullName -Raw -Encoding UTF8
    $OriginalContent = $Content
    $FileChanges = 0
    
    foreach ($Replacement in $Replacements) {
        $OldPattern = $Replacement.Old
        $NewPattern = $Replacement.New
        
        if ($Content -match [regex]::Escape($OldPattern)) {
            $Count = ([regex]::Matches($Content, [regex]::Escape($OldPattern))).Count
            $Content = $Content -replace [regex]::Escape($OldPattern), $NewPattern
            $FileChanges += $Count
        }
    }
    
    if ($Content -ne $OriginalContent) {
        $UpdatedFiles++
        $TotalChanges += $FileChanges
        
        Write-Host "Updated: $($File.Name) ($FileChanges changes)" -ForegroundColor Yellow
        
        if (-not $DryRun) {
            Set-Content -Path $File.FullName -Value $Content -Encoding UTF8 -NoNewline
        }
    } else {
        Write-Host "No changes: $($File.Name)" -ForegroundColor Gray
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
if ($DryRun) {
    Write-Host "[DRY RUN COMPLETE]" -ForegroundColor Yellow
    Write-Host "Would update $UpdatedFiles files with $TotalChanges changes" -ForegroundColor Yellow
} else {
    Write-Host "Complete!" -ForegroundColor Green
    Write-Host "Updated $UpdatedFiles files with $TotalChanges total changes" -ForegroundColor Green
}
Write-Host "========================================" -ForegroundColor Cyan

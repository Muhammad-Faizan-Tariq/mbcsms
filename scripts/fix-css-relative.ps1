# Fix CSS paths to use RELATIVE paths (works both locally and on server)
$ErrorActionPreference = "Stop"
$ProjectRoot = "C:\Users\hp\CascadeProjects\MBC SMS"

Write-Host "Fixing CSS paths to relative..." -ForegroundColor Cyan

$CssFiles = Get-ChildItem -Path "$ProjectRoot\public\ints\assets\css" -Filter "*.css" -Recurse

$UpdatedCount = 0

foreach ($File in $CssFiles) {
    $Content = Get-Content -Path $File.FullName -Raw
    $OriginalContent = $Content
    
    # CSS files are in /ints/assets/css/ so relative path to img is ../img/
    # Fix absolute paths back to relative
    $Content = $Content -replace 'url\("/ints/assets/img/', 'url("../img/'
    $Content = $Content -replace "url\('/ints/assets/img/", "url('../img/"
    $Content = $Content -replace 'url\(/ints/assets/img/', 'url(../img/'
    
    $Content = $Content -replace 'url\("/ints/assets/font/', 'url("../font/'
    $Content = $Content -replace "url\('/ints/assets/font/", "url('../font/"
    
    $Content = $Content -replace 'url\("/ints/assets/fonts/', 'url("../fonts/'
    $Content = $Content -replace "url\('/ints/assets/fonts/", "url('../fonts/"
    
    if ($Content -ne $OriginalContent) {
        Set-Content -Path $File.FullName -Value $Content -NoNewline
        Write-Host "Updated: $($File.Name)" -ForegroundColor Yellow
        $UpdatedCount++
    }
}

Write-Host ""
Write-Host "Complete! Updated $UpdatedCount CSS files" -ForegroundColor Green

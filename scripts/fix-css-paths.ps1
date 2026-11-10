# Fix CSS relative image paths
$ErrorActionPreference = "Stop"
$ProjectRoot = "C:\Users\hp\CascadeProjects\MBC SMS"

Write-Host "Fixing CSS relative paths..." -ForegroundColor Cyan

$CssFiles = Get-ChildItem -Path "$ProjectRoot\public\ints\assets\css" -Filter "*.css"

$UpdatedCount = 0

foreach ($File in $CssFiles) {
    $Content = Get-Content -Path $File.FullName -Raw
    $OriginalContent = $Content
    
    # Fix url("../img/...) -> url("/ints/assets/img/...)
    $Content = $Content -replace 'url\("\.\./img/', 'url("/ints/assets/img/'
    $Content = $Content -replace "url\('\.\./img/", "url('/ints/assets/img/"
    $Content = $Content -replace 'url\(\.\./img/', 'url(/ints/assets/img/'
    
    # Fix url("../font/...) -> url("/ints/assets/font/...)
    $Content = $Content -replace 'url\("\.\./font/', 'url("/ints/assets/font/'
    $Content = $Content -replace "url\('\.\./font/", "url('/ints/assets/font/"
    
    # Fix url("../fonts/...) -> url("/ints/assets/fonts/...)
    $Content = $Content -replace 'url\("\.\./fonts/', 'url("/ints/assets/fonts/'
    $Content = $Content -replace "url\('\.\./fonts/", "url('/ints/assets/fonts/"
    
    if ($Content -ne $OriginalContent) {
        Set-Content -Path $File.FullName -Value $Content -NoNewline
        Write-Host "Updated: $($File.Name)" -ForegroundColor Yellow
        $UpdatedCount++
    }
}

Write-Host ""
Write-Host "Complete! Updated $UpdatedCount CSS files" -ForegroundColor Green

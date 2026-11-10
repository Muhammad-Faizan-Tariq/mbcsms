# Fix remaining relative paths in HTML files
$ErrorActionPreference = "Stop"
$ProjectRoot = "C:\Users\hp\CascadeProjects\MBC SMS"

Write-Host "Fixing remaining relative paths..." -ForegroundColor Cyan

$HtmlFiles = Get-ChildItem -Path "$ProjectRoot\public\ints" -Recurse -Filter "*.html"

$UpdatedCount = 0

foreach ($File in $HtmlFiles) {
    $Content = Get-Content -Path $File.FullName -Raw
    $OriginalContent = $Content
    
    # Fix ../js/plupupload/ -> /ints/assets/js/plupupload/
    $Content = $Content -replace '\.\./js/plupupload/', '/ints/assets/js/plupupload/'
    
    # Fix any remaining ../css/ paths
    $Content = $Content -replace '\.\./css/', '/ints/assets/css/'
    
    # Fix any remaining ../js/ paths (not already fixed)
    $Content = $Content -replace '\.\./js/(?!plupupload)', '/ints/assets/js/'
    
    # Fix any remaining ../img/ paths
    $Content = $Content -replace '\.\./img/', '/ints/assets/img/'
    
    # Fix any remaining ../ico/ paths
    $Content = $Content -replace '\.\./ico/', '/ints/assets/ico/'
    
    # Fix ../../ paths
    $Content = $Content -replace '\.\./\.\./css/', '/ints/assets/css/'
    $Content = $Content -replace '\.\./\.\./js/', '/ints/assets/js/'
    $Content = $Content -replace '\.\./\.\./img/', '/ints/assets/img/'
    
    if ($Content -ne $OriginalContent) {
        Set-Content -Path $File.FullName -Value $Content -NoNewline
        Write-Host "Updated: $($File.Name)" -ForegroundColor Yellow
        $UpdatedCount++
    }
}

Write-Host ""
Write-Host "Complete! Updated $UpdatedCount files" -ForegroundColor Green

# Fix HTML paths to use RELATIVE paths (works both locally and on server)
$ErrorActionPreference = "Stop"
$ProjectRoot = "C:\Users\hp\CascadeProjects\MBC SMS"

Write-Host "Fixing HTML paths to relative..." -ForegroundColor Cyan

$HtmlFiles = Get-ChildItem -Path "$ProjectRoot\public\ints" -Recurse -Filter "*.html"

$UpdatedCount = 0

foreach ($File in $HtmlFiles) {
    $Content = Get-Content -Path $File.FullName -Raw
    $OriginalContent = $Content
    
    # Determine relative path depth based on folder
    $RelativePath = ""
    if ($File.DirectoryName -like "*\manager" -or $File.DirectoryName -like "*\agent" -or $File.DirectoryName -like "*\client") {
        $RelativePath = "../assets/"
    } elseif ($File.Name -eq "login.html") {
        $RelativePath = "assets/"
    }
    
    # Fix absolute paths back to relative
    # /ints/assets/css/ -> ../assets/css/ (for panels) or assets/css/ (for login)
    if ($RelativePath -ne "") {
        $Content = $Content -replace '/ints/assets/css/', ($RelativePath + 'css/')
        $Content = $Content -replace '/ints/assets/js/', ($RelativePath + 'js/')
        $Content = $Content -replace '/ints/assets/img/', ($RelativePath + 'img/')
        $Content = $Content -replace '/ints/assets/ico/', ($RelativePath + 'ico/')
    }
    
    if ($Content -ne $OriginalContent) {
        Set-Content -Path $File.FullName -Value $Content -NoNewline
        Write-Host "Updated: $($File.Name)" -ForegroundColor Yellow
        $UpdatedCount++
    }
}

Write-Host ""
Write-Host "Complete! Updated $UpdatedCount files" -ForegroundColor Green

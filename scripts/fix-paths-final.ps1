# Fix HTML paths for new structure: ../css/ -> ../assets/css/
$ErrorActionPreference = "Stop"
$ProjectRoot = "C:\Users\hp\CascadeProjects\MBC SMS"

Write-Host "Fixing HTML paths for new structure..." -ForegroundColor Cyan

$HtmlFiles = Get-ChildItem -Path "$ProjectRoot\public\ints" -Recurse -Filter "*.html"

$UpdatedCount = 0

foreach ($File in $HtmlFiles) {
    $Content = Get-Content -Path $File.FullName -Raw
    $OriginalContent = $Content
    
    # Fix paths: ../css/ -> ../assets/css/, ../js/ -> ../assets/js/, etc.
    # For panel files (manager, agent, client subfolders)
    if ($File.DirectoryName -like "*\manager" -or $File.DirectoryName -like "*\agent" -or $File.DirectoryName -like "*\client") {
        $Content = $Content -replace '\.\./css/', '../assets/css/'
        $Content = $Content -replace '\.\./js/', '../assets/js/'
        $Content = $Content -replace '\.\./img/', '../assets/img/'
        $Content = $Content -replace '\.\./ico/', '../assets/ico/'
        $Content = $Content -replace '\.\./font/', '../assets/font/'
        $Content = $Content -replace '\.\./fonts/', '../assets/fonts/'
        
        # Also fix ../../ paths
        $Content = $Content -replace '\.\./\.\./css/', '../assets/css/'
        $Content = $Content -replace '\.\./\.\./js/', '../assets/js/'
        $Content = $Content -replace '\.\./\.\./img/', '../assets/img/'
    }
    
    # For login.html (in ints folder directly)
    if ($File.Name -eq "login.html") {
        $Content = $Content -replace 'href="css/', 'href="assets/css/'
        $Content = $Content -replace 'href="js/', 'href="assets/js/'
        $Content = $Content -replace 'href="img/', 'href="assets/img/'
        $Content = $Content -replace 'href="ico/', 'href="assets/ico/'
        $Content = $Content -replace 'src="css/', 'src="assets/css/'
        $Content = $Content -replace 'src="js/', 'src="assets/js/'
        $Content = $Content -replace 'src="img/', 'src="assets/img/'
    }
    
    if ($Content -ne $OriginalContent) {
        Set-Content -Path $File.FullName -Value $Content -NoNewline
        Write-Host "Updated: $($File.Name)" -ForegroundColor Yellow
        $UpdatedCount++
    }
}

Write-Host ""
Write-Host "Complete! Updated $UpdatedCount files" -ForegroundColor Green

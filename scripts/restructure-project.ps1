# MBC SMS Project Restructure Script
# Run this script to reorganize the project into a professional structure

param(
    [switch]$DryRun = $false
)

$ErrorActionPreference = "Stop"
$ProjectRoot = "C:\Users\hp\CascadeProjects\MBC SMS"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "MBC SMS Project Restructure Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

if ($DryRun) {
    Write-Host "[DRY RUN MODE] No changes will be made" -ForegroundColor Yellow
    Write-Host ""
}

# ============================================
# STEP 1: Create New Directory Structure
# ============================================
Write-Host "Step 1: Creating new directory structure..." -ForegroundColor Green

$NewDirs = @(
    "$ProjectRoot\public",
    "$ProjectRoot\public\ints",
    "$ProjectRoot\public\ints\assets",
    "$ProjectRoot\public\ints\assets\css",
    "$ProjectRoot\public\ints\assets\js",
    "$ProjectRoot\public\ints\assets\js\chart",
    "$ProjectRoot\public\ints\assets\js\plupupload",
    "$ProjectRoot\public\ints\assets\img",
    "$ProjectRoot\public\ints\assets\img\sprite-icons",
    "$ProjectRoot\public\ints\assets\img\tbl-img",
    "$ProjectRoot\public\ints\assets\img\ui",
    "$ProjectRoot\public\ints\assets\ico",
    "$ProjectRoot\public\ints\manager",
    "$ProjectRoot\public\ints\agent",
    "$ProjectRoot\public\ints\client",
    "$ProjectRoot\public\api",
    "$ProjectRoot\app",
    "$ProjectRoot\app\Auth",
    "$ProjectRoot\app\Helpers",
    "$ProjectRoot\app\Middleware",
    "$ProjectRoot\config"
)

foreach ($Dir in $NewDirs) {
    if (-not (Test-Path $Dir)) {
        if (-not $DryRun) {
            New-Item -ItemType Directory -Path $Dir -Force | Out-Null
        }
        Write-Host "  Created: $Dir" -ForegroundColor Gray
    } else {
        Write-Host "  Exists: $Dir" -ForegroundColor DarkGray
    }
}

# ============================================
# STEP 2: Move Shared Assets
# ============================================
Write-Host ""
Write-Host "Step 2: Moving shared assets..." -ForegroundColor Green

# CSS files
$CssSource = "$ProjectRoot\Manager_frontend\ints\css"
$CssDest = "$ProjectRoot\public\ints\assets\css"
if (Test-Path $CssSource) {
    Get-ChildItem -Path $CssSource -File | ForEach-Object {
        $DestFile = "$CssDest\$($_.Name)"
        if (-not $DryRun) {
            Copy-Item -Path $_.FullName -Destination $DestFile -Force
        }
        Write-Host "  CSS: $($_.Name)" -ForegroundColor Gray
    }
}

# JS files
$JsSource = "$ProjectRoot\Manager_frontend\ints\js"
$JsDest = "$ProjectRoot\public\ints\assets\js"
if (Test-Path $JsSource) {
    Get-ChildItem -Path $JsSource -File | ForEach-Object {
        $DestFile = "$JsDest\$($_.Name)"
        if (-not $DryRun) {
            Copy-Item -Path $_.FullName -Destination $DestFile -Force
        }
        Write-Host "  JS: $($_.Name)" -ForegroundColor Gray
    }
    # Copy chart subfolder
    if (Test-Path "$JsSource\chart") {
        if (-not $DryRun) {
            Copy-Item -Path "$JsSource\chart\*" -Destination "$JsDest\chart\" -Force -Recurse
        }
        Write-Host "  JS/chart: copied" -ForegroundColor Gray
    }
    # Copy plupupload subfolder
    if (Test-Path "$JsSource\plupupload") {
        if (-not $DryRun) {
            Copy-Item -Path "$JsSource\plupupload\*" -Destination "$JsDest\plupupload\" -Force -Recurse
        }
        Write-Host "  JS/plupupload: copied" -ForegroundColor Gray
    }
}

# IMG files
$ImgSource = "$ProjectRoot\Manager_frontend\ints\img"
$ImgDest = "$ProjectRoot\public\ints\assets\img"
if (Test-Path $ImgSource) {
    Get-ChildItem -Path $ImgSource -File | ForEach-Object {
        $DestFile = "$ImgDest\$($_.Name)"
        if (-not $DryRun) {
            Copy-Item -Path $_.FullName -Destination $DestFile -Force
        }
        Write-Host "  IMG: $($_.Name)" -ForegroundColor Gray
    }
    # Copy subfolders
    @("sprite-icons", "tbl-img", "ui") | ForEach-Object {
        $subfolder = $_
        if (Test-Path "$ImgSource\$subfolder") {
            if (-not $DryRun) {
                Copy-Item -Path "$ImgSource\$subfolder\*" -Destination "$ImgDest\$subfolder\" -Force -Recurse
            }
            Write-Host "  IMG/$subfolder copied" -ForegroundColor Gray
        }
    }
}

# ICO files
$IcoSource = "$ProjectRoot\Manager_frontend\ints\ico"
$IcoDest = "$ProjectRoot\public\ints\assets\ico"
if (Test-Path $IcoSource) {
    Get-ChildItem -Path $IcoSource -File | ForEach-Object {
        $DestFile = "$IcoDest\$($_.Name)"
        if (-not $DryRun) {
            Copy-Item -Path $_.FullName -Destination $DestFile -Force
        }
        Write-Host "  ICO: $($_.Name)" -ForegroundColor Gray
    }
}

# ============================================
# STEP 3: Move Panel HTML Files
# ============================================
Write-Host ""
Write-Host "Step 3: Moving panel HTML files..." -ForegroundColor Green

# Manager pages
$ManagerSource = "$ProjectRoot\Manager_frontend\ints\manager"
$ManagerDest = "$ProjectRoot\public\ints\manager"
if (Test-Path $ManagerSource) {
    Get-ChildItem -Path $ManagerSource -File | ForEach-Object {
        if (-not $DryRun) {
            Copy-Item -Path $_.FullName -Destination "$ManagerDest\$($_.Name)" -Force
        }
        Write-Host "  Manager: $($_.Name)" -ForegroundColor Gray
    }
    # Copy res folder if exists
    if (Test-Path "$ManagerSource\res") {
        if (-not $DryRun) {
            New-Item -ItemType Directory -Path "$ManagerDest\res" -Force | Out-Null
            Copy-Item -Path "$ManagerSource\res\*" -Destination "$ManagerDest\res\" -Force -Recurse
        }
        Write-Host "  Manager/res: copied" -ForegroundColor Gray
    }
}

# Agent pages
$AgentSource = "$ProjectRoot\Agent_frontend\ints\agent"
$AgentDest = "$ProjectRoot\public\ints\agent"
if (Test-Path $AgentSource) {
    Get-ChildItem -Path $AgentSource -File | ForEach-Object {
        if (-not $DryRun) {
            Copy-Item -Path $_.FullName -Destination "$AgentDest\$($_.Name)" -Force
        }
        Write-Host "  Agent: $($_.Name)" -ForegroundColor Gray
    }
    # Copy img and res folders if exist
    if (Test-Path "$AgentSource\img") {
        if (-not $DryRun) {
            if (-not (Test-Path "$AgentDest\img")) {
                New-Item -ItemType Directory -Path "$AgentDest\img" -Force | Out-Null
            }
            Get-ChildItem -Path "$AgentSource\img" -Recurse | ForEach-Object {
                $relativePath = $_.FullName.Substring("$AgentSource\img".Length)
                $destPath = "$AgentDest\img$relativePath"
                if ($_.PSIsContainer) {
                    New-Item -ItemType Directory -Path $destPath -Force | Out-Null
                } else {
                    Copy-Item -Path $_.FullName -Destination $destPath -Force
                }
            }
        }
        Write-Host "  Agent/img: copied" -ForegroundColor Gray
    }
    if (Test-Path "$AgentSource\res") {
        if (-not $DryRun) {
            if (-not (Test-Path "$AgentDest\res")) {
                New-Item -ItemType Directory -Path "$AgentDest\res" -Force | Out-Null
            }
            Copy-Item -Path "$AgentSource\res\*" -Destination "$AgentDest\res\" -Force -Recurse
        }
        Write-Host "  Agent/res: copied" -ForegroundColor Gray
    }
}

# Client pages
$ClientSource = "$ProjectRoot\client_frontend\ints\client"
$ClientDest = "$ProjectRoot\public\ints\client"
if (Test-Path $ClientSource) {
    Get-ChildItem -Path $ClientSource -File | ForEach-Object {
        if (-not $DryRun) {
            Copy-Item -Path $_.FullName -Destination "$ClientDest\$($_.Name)" -Force
        }
        Write-Host "  Client: $($_.Name)" -ForegroundColor Gray
    }
}

# Login page
$LoginSource = "$ProjectRoot\client_frontend\ints\login.html"
$LoginDest = "$ProjectRoot\public\ints\login.html"
if (Test-Path $LoginSource) {
    if (-not $DryRun) {
        Copy-Item -Path $LoginSource -Destination $LoginDest -Force
    }
    Write-Host "  Login: login.html" -ForegroundColor Gray
}

# ============================================
# STEP 4: Move API Files
# ============================================
Write-Host ""
Write-Host "Step 4: Moving API files..." -ForegroundColor Green

$ApiSource = "$ProjectRoot\api"
$ApiDest = "$ProjectRoot\public\api"
if (Test-Path $ApiSource) {
    Get-ChildItem -Path $ApiSource -File | ForEach-Object {
        if (-not $DryRun) {
            Copy-Item -Path $_.FullName -Destination "$ApiDest\$($_.Name)" -Force
        }
        Write-Host "  API: $($_.Name)" -ForegroundColor Gray
    }
}

# ============================================
# STEP 5: Move App Core Files
# ============================================
Write-Host ""
Write-Host "Step 5: Moving app core files..." -ForegroundColor Green

# signin.php
$SigninSource = "$ProjectRoot\ints\signin.php"
$SigninDest = "$ProjectRoot\app\Auth\signin.php"
if (Test-Path $SigninSource) {
    if (-not $DryRun) {
        Copy-Item -Path $SigninSource -Destination $SigninDest -Force
    }
    Write-Host "  Auth: signin.php" -ForegroundColor Gray
}

# login.php (if exists)
$LoginPhpSource = "$ProjectRoot\ints\login.php"
if (Test-Path $LoginPhpSource) {
    if (-not $DryRun) {
        Copy-Item -Path $LoginPhpSource -Destination "$ProjectRoot\app\Auth\login.php" -Force
    }
    Write-Host "  Auth: login.php" -ForegroundColor Gray
}

# ============================================
# STEP 6: Move Config Files
# ============================================
Write-Host ""
Write-Host "Step 6: Moving config files..." -ForegroundColor Green

# .env
$EnvSource = "$ProjectRoot\.env"
$EnvDest = "$ProjectRoot\config\.env"
if (Test-Path $EnvSource) {
    if (-not $DryRun) {
        Copy-Item -Path $EnvSource -Destination $EnvDest -Force
    }
    Write-Host "  Config: .env" -ForegroundColor Gray
}

# nginx.conf
$NginxSource = "$ProjectRoot\nginx.conf"
$NginxDest = "$ProjectRoot\config\nginx.conf"
if (Test-Path $NginxSource) {
    if (-not $DryRun) {
        Copy-Item -Path $NginxSource -Destination $NginxDest -Force
    }
    Write-Host "  Config: nginx.conf" -ForegroundColor Gray
}

# ============================================
# STEP 7: Move Database Files
# ============================================
Write-Host ""
Write-Host "Step 7: Database files already in place..." -ForegroundColor Green

# Create migrations folder
if (-not (Test-Path "$ProjectRoot\database\migrations")) {
    if (-not $DryRun) {
        New-Item -ItemType Directory -Path "$ProjectRoot\database\migrations" -Force | Out-Null
    }
    Write-Host "  Created: database/migrations" -ForegroundColor Gray
}

# ============================================
# STEP 8: Move Docs
# ============================================
Write-Host ""
Write-Host "Step 8: Docs folder already in place..." -ForegroundColor Green

# ============================================
# COMPLETE
# ============================================
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
if ($DryRun) {
    Write-Host "[DRY RUN COMPLETE]" -ForegroundColor Yellow
    Write-Host "Run without -DryRun to apply changes" -ForegroundColor Yellow
} else {
    Write-Host "Restructure Complete!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Yellow
    Write-Host "1. Update nginx.conf for new structure" -ForegroundColor White
    Write-Host "2. Update HTML files to use /ints/assets/ paths" -ForegroundColor White
    Write-Host "3. Update API config.php paths" -ForegroundColor White
    Write-Host "4. Test all panels" -ForegroundColor White
}
Write-Host "========================================" -ForegroundColor Cyan

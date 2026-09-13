<#
.SYNOPSIS
  Assembles the standalone vastai.plg XML file for Unraid installation.
#>

$ErrorActionPreference = "Stop"

$root = Resolve-Path "$PSScriptRoot\.."
$srcDir = Join-Path $root "src\vastai\usr\local\emhttp\plugins\vastai"
$plgFile = Join-Path $root "vastai.plg"

Write-Host "Building standalone vastai.plg from: $srcDir" -ForegroundColor Cyan

# Read version and metadata
$version = Get-Date -Format "yyyy.MM.dd"
$author = "0xntpower"
$pluginName = "vastai"

# Helper to read text files
function Get-FileContentSafe([string]$relPath) {
    $fullPath = Join-Path $srcDir $relPath
    if (-not (Test-Path $fullPath)) {
        throw "Missing required source file: $fullPath"
    }
    return [System.IO.File]::ReadAllText($fullPath, [System.Text.Encoding]::UTF8)
}

# Read source files
$dashPage   = Get-FileContentSafe "VastAIDashboard.page"
$settPage   = Get-FileContentSafe "VastAISettings.page"
$defCfg     = Get-FileContentSafe "default.cfg"
$vastAiPhp  = Get-FileContentSafe "include\VastAI.php"
$statusPhp  = Get-FileContentSafe "include\getvaststatus.php"
$vastJs     = Get-FileContentSafe "javascript\vastai.js"
$vastCss    = Get-FileContentSafe "styles\vastai.css"

# Base64 encode images
$iconPath = Join-Path $srcDir "images\vastai.png"
$iconB64 = ""
if (Test-Path $iconPath) {
    $iconBytes = [System.IO.File]::ReadAllBytes($iconPath)
    $iconB64 = [Convert]::ToBase64String($iconBytes)
}

$plgContent = @"
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
  <!ENTITY name      "$pluginName">
  <!ENTITY author    "$author">
  <!ENTITY version   "$version">
  <!ENTITY plugin    "/boot/config/plugins/&name;">
  <!ENTITY emhttp    "/usr/local/emhttp/plugins/&name;">
]>

<PLUGIN name="&name;"
        author="&author;"
        version="&version;"
        launch="Settings/VastAISettings"
        icon="cubes"
        min="6.12.0">

<CHANGES>
###$version
- Real-time Vast.ai host machine monitoring widget on Unraid Dashboard.
- Live active rental status (Interruptible, On-Demand, Reserved, Listed, Unlisted, Offline).
- Real-time hourly earnings rate (`$/hr`) and projected daily income (`$/day`).
- GPU telemetry, temperature monitoring with color alerts, and reliability scores.
- Hardware specs overview: CPU, cores, RAM, storage, network bandwidth.
- Low-overhead local caching proxy to prevent API rate limits.
- Native theme compatibility across all Unraid themes (Dark/Black, White, Azure, Gray).
</CHANGES>

<!-- Pre-install compatibility check -->
<FILE Run="/usr/bin/php">
<INLINE><![CDATA[<?php
  `$v = parse_ini_file('/etc/unraid-version')['version'] ?? '0';
  if (version_compare(`$v, '6.12.0', '<')) {
    echo "\n*** Vast.ai Monitor requires Unraid 6.12.0 or newer (found `$v). ***\n";
    exit(1);
  }
?>]]></INLINE>
</FILE>

<!-- Create directories -->
<FILE Run="/bin/bash">
<INLINE>
mkdir -p &plugin;
mkdir -p &emhttp;/include
mkdir -p &emhttp;/javascript
mkdir -p &emhttp;/styles
mkdir -p &emhttp;/images
</INLINE>
</FILE>

<!-- Default Config (seed if not present) -->
<FILE Name="&emhttp;/default.cfg">
<INLINE><![CDATA[$defCfg]]></INLINE>
</FILE>

<FILE Run="/bin/bash">
<INLINE>
if [ ! -f &plugin;/&name;.cfg ]; then
  cp &emhttp;/default.cfg &plugin;/&name;.cfg
fi
</INLINE>
</FILE>

<!-- Plugin Icon -->
<FILE Name="&emhttp;/images/vastai.png">
<BASE64>
$iconB64
</BASE64>
</FILE>

<!-- Dashboard Page Hook -->
<FILE Name="&emhttp;/VastAIDashboard.page">
<INLINE><![CDATA[$dashPage]]></INLINE>
</FILE>

<!-- Settings Page -->
<FILE Name="&emhttp;/VastAISettings.page">
<INLINE><![CDATA[$settPage]]></INLINE>
</FILE>

<!-- PHP Backend Data Fetcher -->
<FILE Name="&emhttp;/include/getvaststatus.php">
<INLINE><![CDATA[$statusPhp]]></INLINE>
</FILE>

<!-- PHP Dashboard Tile Builder -->
<FILE Name="&emhttp;/include/VastAI.php">
<INLINE><![CDATA[$vastAiPhp]]></INLINE>
</FILE>

<!-- Widget Styles -->
<FILE Name="&emhttp;/styles/vastai.css">
<INLINE><![CDATA[$vastCss]]></INLINE>
</FILE>

<!-- Widget JavaScript -->
<FILE Name="&emhttp;/javascript/vastai.js">
<INLINE><![CDATA[$vastJs]]></INLINE>
</FILE>

<!-- Post-install notification -->
<FILE Run="/bin/bash">
<INLINE>
echo ""
echo "----------------------------------------------------"
echo " &name; has been installed successfully!           "
echo " Version: &version;                                 "
echo "----------------------------------------------------"
echo " Next step: Navigate to Settings -> Utilities ->   "
echo " Vast.ai Monitor to enter your Vast.ai API Key.     "
echo "----------------------------------------------------"
echo ""
</INLINE>
</FILE>

<!-- Removal script -->
<FILE Run="/bin/bash" Method="remove">
<INLINE>
echo "Removing &name;..."
rm -rf &emhttp;
rm -f /tmp/vastai_cache.json*
echo "&name; has been removed."
</INLINE>
</FILE>

</PLUGIN>
"@

[System.IO.File]::WriteAllText($plgFile, $plgContent.Trim(), [System.Text.Encoding]::UTF8)
Write-Host "Successfully generated standalone plugin installer: $plgFile" -ForegroundColor Green

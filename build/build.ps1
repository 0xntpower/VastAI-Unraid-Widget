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
$version = (Get-Content (Join-Path $root "VERSION") -Raw).Trim()
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
$pluginDesc = Get-FileContentSafe "README.md"

# Base64 encode images
$iconPath = Join-Path $srcDir "images\vastai.png"
$iconB64 = ""
if (Test-Path $iconPath) {
    $iconBytes = [System.IO.File]::ReadAllBytes($iconPath)
    $iconB64 = [Convert]::ToBase64String($iconBytes)
}

# Unraid resolves a .page Icon= value to /plugins/<name>/icons/<file>,
# which is a different directory from the images/ one the banner uses.
$navIconPath = Join-Path $srcDir ("icons" + [IO.Path]::DirectorySeparatorChar + "vastai.png")
$navIconB64 = ""
if (Test-Path $navIconPath) {
    $navIconBytes = [System.IO.File]::ReadAllBytes($navIconPath)
    $navIconB64 = [Convert]::ToBase64String($navIconBytes)
}

$plgContent = @"
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
  <!ENTITY name      "$pluginName">
  <!ENTITY author    "$author">
  <!ENTITY version   "$version">
  <!ENTITY github    "0xntpower/VastAI-Unraid-Widget">
  <!ENTITY pluginURL "https://raw.githubusercontent.com/&github;/main/&name;.plg">
  <!ENTITY plugin    "/boot/config/plugins/&name;">
  <!ENTITY emhttp    "/usr/local/emhttp/plugins/&name;">
]>

<!--
  GENERATED FILE - DO NOT EDIT.
  Every source below is inlined from src/ by build/build.ps1.
  Edit the files under src/, then rebuild. Edits made here are lost on next build.
-->

<PLUGIN name="&name;"
        author="&author;"
        version="&version;"
        pluginURL="&pluginURL;"
        launch="Settings/VastAISettings"
        icon="vastai.png"
        min="6.12.0">

<CHANGES>
##Vast.ai Monitor

###$version
- The plugin now has an icon everywhere Unraid shows one. The Plugins page entry,
  the Settings and Utilities nav entry and the dashboard tile all use the Vast.ai
  mark instead of a generic glyph or a missing image.
- Icons are now transparent PNGs rather than opaque dark squares, so they sit
  correctly on the light Unraid themes as well as the dark ones.
- The Plugins page now shows a description instead of just the plugin name.

###2026.09.16.2315
- Fix: plugin updates now actually install. Previously the plugin manager skipped
  every file that already existed, so an update reported success but kept running
  the old code until the next reboot.
- Fix: the plugin icon is now installed. The manifest used a BASE64 element that
  the plugin manager does not implement, so no image was ever written.
- Fix: listed machines showed a per-GPU rate in the same column that shows a
  per-machine rate once rented. An 8-GPU host under-reported its asking price by 8x.
- Fix: multi-GPU hosts were misclassified. gpu_occupancy is a per-GPU string, so
  comparing it to a single character never matched, and treating any non-empty
  value as rented could show an idle host as rented at 0.00/hr.
- Fix: theme support. 19 of 26 CSS variable references named variables Unraid does
  not define, so every theme fell back to dark colours.
- Fix: the tile can be collapsed again, using the native dashboard control.
- Fix: stale data is now labelled STALE instead of ONLINE, and a warning is shown.
- Fix: an upstream outage no longer increases outbound API traffic.
- Fix: Test Connection no longer overwrites the shared dashboard cache.
- Security: test_key is accepted on POST only. The GET path bypassed CSRF validation.
- Security: the API key is scrubbed from the config on uninstall.
- Removed three settings that had no effect: SHOW_TEMP, SHOW_SPECS, SHOW_OFFLINE.
</CHANGES>

<!-- Create directories -->
<!-- rm -rf is required: the plugin manager skips any FILE Name whose target
     already exists unless a SHA256/MD5 invalidates it, and INLINE files have
     none. Without this, an update installs nothing until the next reboot. -->
<FILE Run="/bin/bash">
<INLINE>
rm -rf &emhttp;
mkdir -p &plugin;
mkdir -p &emhttp;/include
mkdir -p &emhttp;/javascript
mkdir -p &emhttp;/styles
mkdir -p &emhttp;/images
mkdir -p &emhttp;/icons
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
<FILE Name="&emhttp;/images/vastai.png" Type="base64">
<INLINE>
$iconB64
</INLINE>
</FILE>

<!-- Nav icon. Unraid resolves a .page Icon= value to /plugins/<name>/icons/ -->
<FILE Name="&emhttp;/icons/vastai.png" Type="base64">
<INLINE>
$navIconB64
</INLINE>
</FILE>

<!-- Description shown on the Plugins page. ShowPlugins.php renders
     plugins/<name>/README.md as Markdown, falling back to the bold name. -->
<FILE Name="&emhttp;/README.md">
<INLINE><![CDATA[$pluginDesc]]></INLINE>
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
if [ -f &plugin;/&name;.cfg ]; then
  sed -i 's|^API_KEY=.*|API_KEY=""|' &plugin;/&name;.cfg
  echo "Vast.ai API key scrubbed from &plugin;/&name;.cfg"
fi
echo "&name; has been removed."
</INLINE>
</FILE>

</PLUGIN>
"@

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($plgFile, $plgContent.Trim(), $utf8NoBom)
Write-Host "Successfully generated standalone plugin installer: $plgFile" -ForegroundColor Green

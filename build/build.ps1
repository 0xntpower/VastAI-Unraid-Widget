<#
.SYNOPSIS
  Assembles vastai.plg, the one file Unraid installs, from the files under src/.

.PARAMETER OutFile
  Where to write the manifest. Defaults to vastai.plg in the repository root.
  tests/check.sh passes a temporary path so that a check never rewrites the
  committed file.

.NOTES
  The template below is a literal string. PowerShell expands nothing in it, so
  the shell scripts and the release notes may contain $ and backticks. The only
  substitutions are the @@NAME@@ tokens listed in $tokens.
#>
param([string]$OutFile)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$root    = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$srcDir  = Join-Path $root 'src/vastai/usr/local/emhttp/plugins/vastai'
$plgFile = if ($OutFile) { $OutFile } else { Join-Path $root 'vastai.plg' }
$version = (Get-Content (Join-Path $root 'VERSION') -Raw).Trim()

# Every file under src/ ships. Nothing is listed by hand, so a new source file
# cannot be forgotten here. Ordinal order keeps the output identical on every
# platform.
$files = [string[]]@(Get-ChildItem -Path $srcDir -Recurse -File | ForEach-Object {
    [System.IO.Path]::GetRelativePath($srcDir, $_.FullName).Replace('\', '/')
})
[Array]::Sort($files, [StringComparer]::Ordinal)
if ($files.Count -eq 0) { throw "No source files under $srcDir" }

$fileBlocks = foreach ($rel in $files) {
    $path = Join-Path $srcDir $rel
    if ($rel.EndsWith('.png')) {
        $body = [Convert]::ToBase64String([System.IO.File]::ReadAllBytes($path))
        "<FILE Name=`"&emhttp;/$rel`" Type=`"base64`">`n<INLINE>`n$body`n</INLINE>`n</FILE>"
    } else {
        $body = [System.IO.File]::ReadAllText($path, [System.Text.Encoding]::UTF8)
        if ($body.Trim().Length -eq 0) { throw "Source file is empty: $rel" }
        # The terminator would end the CDATA block early and let the rest of the
        # file into a manifest whose scripts run as root.
        if ($body.Contains(']]>')) { throw "Source file contains a CDATA terminator: $rel" }
        "<FILE Name=`"&emhttp;/$rel`">`n<INLINE><![CDATA[$body]]></INLINE>`n</FILE>"
    }
}

$dirs = [string[]]@($files | Where-Object { $_.Contains('/') } |
    ForEach-Object { $_.Substring(0, $_.LastIndexOf('/')) } | Select-Object -Unique)
[Array]::Sort($dirs, [StringComparer]::Ordinal)
$mkdirs = foreach ($dir in $dirs) { "mkdir -p &emhttp;/$dir" }

$template = @'
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
  <!ENTITY name      "vastai">
  <!ENTITY author    "0xntpower">
  <!ENTITY version   "@@VERSION@@">
  <!ENTITY github    "0xntpower/VastAI-Unraid-Widget">
  <!ENTITY pluginURL "https://raw.githubusercontent.com/&github;/main/&name;.plg">
  <!ENTITY support   "https://forums.unraid.net/topic/200608-plugin-vastai-dashboard-tile/">
  <!ENTITY plugin    "/boot/config/plugins/&name;">
  <!ENTITY emhttp    "/usr/local/emhttp/plugins/&name;">
  <!ENTITY cache     "/var/local/&name;">
  <!ENTITY oldcache  "/tmp/&name;_cache">
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
        support="&support;"
        launch="Settings/VastAISettings"
        icon="vastai.png"
        min="7.2.0">

<CHANGES>
##Vast.ai Monitor

###2026.11.21.2200
- Action recommended: if your API key was created without restrictions, replace
  it with one that can only read machines and read account info. The plugin
  needs nothing else. Delete the old key in the Vast.ai console.
- Requires Unraid 7.2.0 or later. The tile's colours and header layout depend on
  parts of the dashboard that older releases do not have. On an older Unraid the
  plugin stays on the version it has.
- Fix: the utilization of a multi-GPU host. Vast.ai separates the per-GPU letters
  with spaces, which the previous release did not expect, so it showed a dash
  where a percentage belonged.
- Fix: a rejected API key was retried on every poll, about 2,900 failed sign-ins
  a day for one open dashboard. It is now retried every five minutes, and the
  tile says the key was rejected and links to Settings.
- Fix: an outage with nothing cached, such as after a reboot or an update, was
  also retried on every poll. The 30 second wait now applies there too.
- Fix: Test Connection reported an account balance of $0.00 when the balance had
  not been fetched. It now says the balance is not available.
- Fix: an offline host with a rental still open showed live earnings and 100%
  utilization next to "offline". It is now shown as offline only, and is left out
  of the rented count and the earnings total.
- Fix: the header said ONLINE whenever the API answered, even with every host
  offline. It now reports the hosts: ONLINE, OFFLINE or how many are online.
- Fix: stale data now says how old it is.
- Fix: text was hard to read on the light themes. The tile now takes its colours
  from the theme.
- Fix: the RAM line in the details row had no icon.
- Security: the cache moved from /tmp to a directory only root can use. A local
  process that could write to /tmp could plant a cache file that the dashboard
  then displayed as if it came from Vast.ai.
- Security: every number the tile prints is now checked to be a number first.
- Several dashboards open at once now share one request to Vast.ai, and the
  balance is fetched every five minutes, not on every refresh.
- A closed tile no longer polls. The tile refreshes when its browser tab comes
  back into view, and stops polling when the session has ended.
- With the refresh interval and the cache expiration both at 30 seconds, the
  figures now refresh every 30 seconds. It was every 60.
- Failures are written to the system log, once per failure and not once per poll.
- Removed the Dashboard Placement setting. Unraid's own saved layout overrode it
  as soon as any tile was dragged. Drag the tile like any built-in tile. A
  position already chosen with the setting is kept.
- Uninstalling blanks the API key in the plugin's settings file, as before. The
  uninstall message now says that copies in flash backups are not affected.

###2026.11.21.2151
- Fix: a multi-GPU host with any GPU rented showed 100% utilization. The column
  now shows the share of GPUs rented, and a dash when the API does not report it.
- Fix: a rented host with no rental count from the API was labelled "1 job". A
  job count is now shown only when the API provides one.
- Fix: a host with both on-demand and interruptible rentals was labelled On-Demand
  but drawn with the interruptible bar colour. The bar now matches the label.

###2026.11.18.1829
- Fix: setting the refresh interval to Off had no effect. The tile kept polling
  every 30 seconds.
- Fix: a response from the API that was not machine data, such as a CDN error
  page, was treated as an empty fleet and replaced the last good data.
- Fix: a rejected API key is now reported as such. Previously the tile kept
  showing the old data as STALE for as long as the key stayed invalid.
- Fix: after changing the API key, the tile could briefly show the previous
  account's machines. The cache is now kept per key.
- Fix: the tile and Test Connection gave up before a slow API answered and
  reported the local service as unreachable.
- Security: the API key is sent in an Authorization header instead of the URL,
  and requests are restricted to HTTPS, including redirects. Earlier versions put
  the key in the request URL. To be sure no copy of it is left in a log, create a
  new key, paste it in Settings and delete the old one in the Vast.ai console.
- Security: Test Connection no longer renders the API's error text as HTML.
- API responses are now requested gzip-compressed.

###2026.09.17.0040
- The plugin's Support link now opens its Unraid forum thread.

###2026.09.16.2345
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
  value as rented could show an idle host as rented at $0.00/hr.
- Fix: theme support on Unraid 7.1 and later. 19 of 26 CSS variable references
  named variables Unraid does not define, so every theme fell back to dark colours.
- Fix: the tile can be collapsed again, using the native dashboard control.
- Fix: stale data is now labelled STALE instead of ONLINE, and a warning is shown.
- Fix: during an upstream outage the tile waits 30 seconds between retries while
  it has cached data to show.
- Fix: Test Connection no longer overwrites the shared dashboard cache.
- Security: test_key is accepted on POST only. The GET path bypassed CSRF validation.
- Security: uninstalling blanks the API key in the plugin's settings file. Copies
  in flash backups are not affected.
- Removed three settings that had no effect: SHOW_TEMP, SHOW_SPECS, SHOW_OFFLINE.
</CHANGES>

<!-- Clear the previous install and create the directories.
     The rm -rf is required. The plugin manager skips any FILE Name whose target
     already exists unless a checksum says it changed, and INLINE files have no
     checksum. Without it an update installs nothing until the next reboot.
     The cache goes too, because its format can change between versions.
     &oldcache; is where earlier versions kept theirs, in /tmp. -->
<FILE Run="/bin/bash">
<INLINE>
rm -rf &emhttp;
rm -rf &cache;
rm -f &oldcache;*
mkdir -p &plugin;
@@MKDIRS@@
</INLINE>
</FILE>

<!-- One FILE per file under src/. The settings file in &plugin; is not created
     here. It appears the first time the user presses Apply, and until then
     default.cfg supplies every value. -->
@@FILES@@

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
rm -rf &cache;
rm -f &oldcache;*
if [ -f &plugin;/&name;.cfg ]; then
  sed -i 's|^API_KEY=.*|API_KEY=""|' &plugin;/&name;.cfg
  echo "Vast.ai API key blanked in &plugin;/&name;.cfg"
  echo "Copies may remain in flash backups. Delete the key at https://console.vast.ai/manage-keys/"
fi
echo "&name; has been removed."
</INLINE>
</FILE>

</PLUGIN>
'@

# The newest release notes carry the version as a literal heading. A VERSION
# bump without new notes stops here, where it once relabelled the previous
# release's notes as the new release.
$topHeading = [regex]::Match($template, '(?m)^###(\S+)').Groups[1].Value
if ($topHeading -ne $version) {
    throw "The first release-notes heading is ###$topHeading but VERSION is $version. Add a ###$version section above it."
}

$tokens = @{
    VERSION = $version
    MKDIRS  = $mkdirs -join "`n"
    FILES   = $fileBlocks -join "`n`n"
}
# One pass over the template. Text that came from a source file is never
# scanned for tokens, and a token with no value stops the build.
$plgContent = [regex]::Replace($template, '@@([A-Z]+)@@', {
    param($m)
    $name = $m.Groups[1].Value
    if (-not $tokens.ContainsKey($name)) { throw "Unknown token in the template: @@$name@@" }
    $tokens[$name]
})

[System.IO.File]::WriteAllText($plgFile, $plgContent.Trim(), (New-Object System.Text.UTF8Encoding($false)))
Write-Host "Wrote $plgFile ($($files.Count) files, version $version)" -ForegroundColor Green

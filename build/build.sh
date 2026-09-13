#!/bin/bash
# Assembles standalone vastai.plg XML for Unraid installation

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SRC_DIR="$ROOT_DIR/src/vastai/usr/local/emhttp/plugins/vastai"
PLG_FILE="$ROOT_DIR/vastai.plg"

VERSION="2026.09.13.1"
AUTHOR="0xntpower"
PLUGIN_NAME="vastai"

echo "Building standalone vastai.plg from: $SRC_DIR"

ICON_B64=""
if [ -f "$SRC_DIR/images/vastai.png" ]; then
    ICON_B64=$(base64 -w 0 "$SRC_DIR/images/vastai.png" 2>/dev/null || base64 "$SRC_DIR/images/vastai.png")
fi

cat <<EOF > "$PLG_FILE"
<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
  <!ENTITY name      "$PLUGIN_NAME">
  <!ENTITY author    "$AUTHOR">
  <!ENTITY version   "$VERSION">
  <!ENTITY github    "0xntpower/VastAI-Unraid-Widget">
  <!ENTITY pluginURL "https://raw.githubusercontent.com/&github;/main/&name;.plg">
  <!ENTITY plugin    "/boot/config/plugins/&name;">
  <!ENTITY emhttp    "/usr/local/emhttp/plugins/&name;">
]>

<PLUGIN name="&name;"
        author="&author;"
        version="&version;"
        pluginURL="&pluginURL;"
        launch="Settings/VastAISettings"
        icon="cubes"
        min="6.12.0">

<CHANGES>
###$VERSION
- Real-time Vast.ai host machine monitoring widget on Unraid Dashboard.
- Live active rental status (Interruptible, On-Demand, Reserved, Listed, Unlisted, Offline).
- Real-time hourly earnings rate (\$/hr) and projected daily income (\$/day).
- GPU telemetry, temperature monitoring with color alerts, and reliability scores.
- Hardware specs overview: CPU, cores, RAM, storage, network bandwidth.
- Low-overhead local caching proxy to prevent API rate limits.
- Native theme compatibility across all Unraid themes (Dark/Black, White, Azure, Gray).
</CHANGES>

<!-- Pre-install compatibility check -->
<FILE Run="/usr/bin/php">
<INLINE><![CDATA[<?php
  \$v = parse_ini_file('/etc/unraid-version')['version'] ?? '0';
  if (version_compare(\$v, '6.12.0', '<')) {
    echo "\n*** Vast.ai Monitor requires Unraid 6.12.0 or newer (found \$v). ***\n";
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

<!-- Default Config -->
<FILE Name="&emhttp;/default.cfg">
<INLINE><![CDATA[$(cat "$SRC_DIR/default.cfg")]]></INLINE>
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
$ICON_B64
</BASE64>
</FILE>

<!-- Dashboard Page Hook -->
<FILE Name="&emhttp;/VastAIDashboard.page">
<INLINE><![CDATA[$(cat "$SRC_DIR/VastAIDashboard.page")]]></INLINE>
</FILE>

<!-- Settings Page -->
<FILE Name="&emhttp;/VastAISettings.page">
<INLINE><![CDATA[$(cat "$SRC_DIR/VastAISettings.page")]]></INLINE>
</FILE>

<!-- PHP Backend Data Fetcher -->
<FILE Name="&emhttp;/include/getvaststatus.php">
<INLINE><![CDATA[$(cat "$SRC_DIR/include/getvaststatus.php")]]></INLINE>
</FILE>

<!-- PHP Dashboard Tile Builder -->
<FILE Name="&emhttp;/include/VastAI.php">
<INLINE><![CDATA[$(cat "$SRC_DIR/include/VastAI.php")]]></INLINE>
</FILE>

<!-- Widget Styles -->
<FILE Name="&emhttp;/styles/vastai.css">
<INLINE><![CDATA[$(cat "$SRC_DIR/styles/vastai.css")]]></INLINE>
</FILE>

<!-- Widget JavaScript -->
<FILE Name="&emhttp;/javascript/vastai.js">
<INLINE><![CDATA[$(cat "$SRC_DIR/javascript/vastai.js")]]></INLINE>
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
EOF

echo "Generated: $PLG_FILE"

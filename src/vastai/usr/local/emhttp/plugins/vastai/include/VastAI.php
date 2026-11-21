<?php
/**
 * Vast.ai Unraid Plugin - dashboard tile shell
 *
 * Registers the empty tile with the dashboard. javascript/vastai.js fills it.
 * The header uses the dashboard's own tile-header classes, which exist from
 * Unraid 7.2.0. That is why the plugin asks for 7.2.0.
 */

global $mytiles;

require_once __DIR__ . '/config.php';

// Empty except under test, see getvaststatus.php.
$root     = PHP_SAPI === 'cli' ? (string) getenv('VASTAI_TEST_ROOT') : '';
$cfg      = vast_config($root);
$interval = $cfg['interval'];

// autov() appends the file's modification time. The browser then keeps both
// files until an update changes them, and a missing file is a visible 404 and
// not a tile that says "Loading" forever.
$asset = fn(string $path): string => function_exists('autov') ? autov($path, true) : $path;
$css   = $asset('/plugins/vastai/styles/vastai.css');
$js    = $asset('/plugins/vastai/javascript/vastai.js');

// The dashboard stores a tile's position, its collapsed state and its closed
// state under md5(title). Translating or renaming the title would reset all
// three for every user, so it is a constant.
$t_title   = 'VAST.AI';
$tr        = function_exists('_');
$t_refresh = $tr ? _('Refresh interval') : 'Refresh interval';
$t_set     = $tr ? _('Vast.ai Settings') : 'Vast.ai Settings';
$t_load    = $tr ? _('Loading Vast.ai status…') : 'Loading Vast.ai status…';

$intOpts = '';
foreach (VAST_INTERVALS as $v) {
    $lab = $v === 0 ? 'off' : ($v < 120 ? "$v s" : ($v / 60) . ' m');
    $sel = $interval === $v ? ' selected' : '';
    $intOpts .= "<option value=\"$v\"$sel>$lab</option>";
}

$mytiles['vastai'][$cfg['column']] = <<<EOT
<tbody title="$t_title" id="vast_tile" data-interval="$interval">
<tr><td>
<link type="text/css" rel="stylesheet" href="$css">
<span class="tile-header">
  <span class="tile-header-left">
    <img src="/plugins/vastai/icons/vastai.png" class="vast-tile-glyph" alt="">
    <span class="vast-tile-title">$t_title</span>
    <span id="vast_subtitle" class="vast-tile-sub">Connecting…</span>
  </span>
  <span class="tile-header-right">
    <span class="tile-header-right-controls vast-header-controls">
      <select id="vast_int" class="auto" title="$t_refresh">$intOpts</select>
      <a href="javascript:void(0)" id="vast_btn_refresh" title="Refresh Now"><i id="vast_refresh_icon" class="fa fa-refresh control"></i></a>
      <a href="/Settings/VastAISettings" title="$t_set"><i class="fa fa-fw fa-cog control"></i></a>
    </span>
  </span>
</span>
</td></tr>
<tr><td>
<div id="vast_widget_container">
  <div class="vast-msg-box grey-text">
    <i class="fa fa-spinner fa-spin vast-msg-spinner"></i> $t_load
  </div>
</div>
<script src="$js"></script>
</td></tr>
</tbody>
EOT;

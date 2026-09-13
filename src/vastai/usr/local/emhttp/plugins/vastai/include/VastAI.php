<?php
/**
 * Vast.ai Unraid Plugin - Dashboard Tile Builder
 * Conforms strictly to Unraid's standardized dashboard design language.
 */

global $mytiles;

$base = '/usr/local/emhttp/plugins/vastai';
$config_file = '/boot/config/plugins/vastai/vastai.cfg';
$default_cfg = "$base/default.cfg";

$cfg = [];
if (file_exists($default_cfg)) {
    $default_ini = @parse_ini_file($default_cfg);
    if (is_array($default_ini)) {
        $cfg = array_merge($cfg, $default_ini);
    }
}
if (file_exists($config_file)) {
    $user_ini = @parse_ini_file($config_file);
    if (is_array($user_ini)) {
        $cfg = array_merge($cfg, $user_ini);
    }
}

$interval = (int) ($cfg['INTERVAL'] ?? 30);
$column   = $cfg['COLUMN'] ?? 'column1';
if (!in_array($column, ['column1', 'column2', 'column3'], true)) {
    $column = 'column1';
}

$css = (string) @file_get_contents("$base/styles/vastai.css");
$js  = (string) @file_get_contents("$base/javascript/vastai.js");

$tr        = function_exists('_');
$t_title   = 'VAST.AI';
$t_refresh = $tr ? _('Refresh interval') : 'Refresh interval';
$t_set     = $tr ? _('Vast.ai Settings') : 'Vast.ai Settings';
$t_load    = $tr ? _('Loading Vast.ai status…') : 'Loading Vast.ai status…';

$intOpts = '';
foreach (['10' => '10 s', '30' => '30 s', '60' => '60 s', '120' => '2 m', '0' => 'off'] as $v => $lab) {
    $sel = ($interval === (int) $v) ? ' selected' : '';
    $intOpts .= "<option value=\"$v\"$sel>$lab</option>";
}

$mytiles['vastai'][$column] = <<<EOT
<tbody title="$t_title" id="vast_tile" data-interval="$interval">
<tr><td>
<style>$css</style>
<span class="tile-header">
  <span class="tile-header-left">
    <i class="fa fa-cubes vast-tile-glyph"></i>
    <span class="vast-tile-title">$t_title</span>
    <span id="vast_subtitle" class="vast-tile-sub">Connecting…</span>
  </span>
  <span class="tile-header-right">
    <span class="vast-header-controls">
      <select id="vast_int" class="auto" title="$t_refresh">$intOpts</select>
      <a href="javascript:void(0)" id="vast_btn_refresh" title="Refresh Now"><i id="vast_refresh_icon" class="fa fa-refresh control"></i></a>
      <a href="/Settings/VastAISettings" title="$t_set"><i class="fa fa-fw fa-cog control"></i></a>
      <i class="fa fa-chevron-up control" title="Toggle Content"></i>
    </span>
  </span>
</span>
</td></tr>
<tr><td>
<div id="vast_widget_container">
  <div class="vast-msg-box">
    <i class="fa fa-spinner fa-spin" style="font-size: 16px; margin-right: 6px;"></i> $t_load
  </div>
</div>
<script>$js</script>
</td></tr>
</tbody>
EOT;

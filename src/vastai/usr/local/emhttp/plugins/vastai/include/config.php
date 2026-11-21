<?php
/**
 * Vast.ai Unraid Plugin - settings reader
 *
 * The one place that reads default.cfg and the saved settings and decides what
 * a valid value is. The endpoint, the tile and the settings page all call it,
 * so they cannot disagree about a default or an allowed value.
 */

// Seconds. 0 is "Off". The order is the order the selects show them in.
const VAST_INTERVALS  = [10, 30, 60, 120, 0];
const VAST_CACHE_TTLS = [15, 30, 60, 120];

/**
 * $root is empty except under test, where it points at a scratch directory.
 */
function vast_config(string $root = ''): array {
    $raw = [];
    foreach (["$root/usr/local/emhttp/plugins/vastai/default.cfg",
              "$root/boot/config/plugins/vastai/vastai.cfg"] as $file) {
        // A settings file that does not parse, which one stray quote pasted into
        // the key field is enough to cause, leaves the defaults in place.
        $ini = is_file($file) ? @parse_ini_file($file) : false;
        if (is_array($ini)) {
            $raw = array_merge($raw, $ini);
        }
    }
    $text = fn(string $key, string $default): string
        => is_scalar($raw[$key] ?? null) ? (string) $raw[$key] : $default;
    // Digits only. (int) "abc" is 0, and 0 would silently mean "Off".
    $int  = fn(string $key): int => ctype_digit($text($key, '')) ? (int) $text($key, '') : -1;
    $pick = fn($value, array $allowed, $default) => in_array($value, $allowed, true) ? $value : $default;

    return [
        'api_key'      => trim($text('API_KEY', '')),
        // A value outside the lists is a hand edit. It gets the default, so the
        // tile never polls faster than Settings allows and the select always
        // shows the value in use.
        'interval'     => $pick($int('INTERVAL'), VAST_INTERVALS, 30),
        'cache_ttl'    => $pick($int('CACHE_TTL'), VAST_CACHE_TTLS, 30),
        // Settings no longer offers this, because the dashboard's own saved
        // layout overrides it as soon as any tile is dragged. It is still read
        // so the tile stays where an existing install put it.
        'column'       => $pick($text('COLUMN', ''), ['column1', 'column2', 'column3'], 'column1'),
        'show_balance' => $text('SHOW_BALANCE', '1') !== '0',
    ];
}

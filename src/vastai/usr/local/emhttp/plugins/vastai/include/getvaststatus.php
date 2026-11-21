<?php
/**
 * Vast.ai Unraid Plugin - status endpoint
 *
 * Fetches the account's machines and balance from the Vast.ai API, keeps the
 * answer in a private cache, and prints one JSON document. The dashboard tile
 * and the settings page's Test Connection are the two readers. The fields are
 * listed in the README under "Endpoint response".
 */

require_once __DIR__ . '/config.php';

const VAST_MACHINES_URL = 'https://console.vast.ai/api/v0/machines/?owner=me';
const VAST_USER_URL     = 'https://console.vast.ai/api/v0/users/current/';
const VAST_BACKOFF      = 30;               // seconds to wait after a failed fetch
const VAST_BACKOFF_AUTH = 300;              // a rejected key does not heal by itself
const VAST_BALANCE_TTL  = 300;              // the balance changes slowly
const VAST_MAX_BODY     = 8 * 1024 * 1024;  // largest API answer worth reading

function vast_respond(array $body): void {
    // Partial output turns a value JSON cannot hold into 0 or null, so one odd
    // number from the API can never leave the tile with an empty response.
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** A finite float, or 0.0 for anything else (a string, 1e999, null). */
function vast_num($value): float {
    $n = is_numeric($value) ? (float) $value : 0.0;
    return is_finite($n) ? $n : 0.0;
}

if (!function_exists('vast_api_get')) { // tests/prelude.php supplies a canned one
function vast_api_get(string $url, string $api_key, int $timeout = 9): array {
    static $ch = null; // one handle, so the balance call reuses the connection
    $ch ??= curl_init();
    $body = '';
    $too_big = false;
    curl_setopt_array($ch, [
        CURLOPT_URL             => $url,
        CURLOPT_HTTPGET         => true,
        CURLOPT_FOLLOWLOCATION  => true,
        CURLOPT_MAXREDIRS       => 5,
        // A redirect may never downgrade to plain HTTP with the key attached.
        CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT  => 5,
        CURLOPT_TIMEOUT         => $timeout,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_ENCODING        => '', // accept gzip, the API serves it
        CURLOPT_USERAGENT       => 'VastAI-Unraid-Widget/1.0',
        CURLOPT_HTTPHEADER      => [
            'Accept: application/json',
            // A header and not a query parameter, so the key never appears in a
            // URL. libcurl drops it if a redirect leaves the original host.
            'Authorization: Bearer ' . $api_key,
        ],
        // The body arrives here already decompressed, so the cap also bounds
        // what a small compressed answer can expand to.
        CURLOPT_WRITEFUNCTION   => function ($ch, string $chunk) use (&$body, &$too_big): int {
            if (strlen($body) + strlen($chunk) > VAST_MAX_BODY) {
                $too_big = true;
                return 0; // anything but the chunk length aborts the transfer
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    return [
        'code'     => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'errno'    => curl_errno($ch),
        'error'    => $too_big ? 'The Vast.ai API answer was too large to read' : curl_error($ch),
        'response' => $ok ? $body : false,
    ];
}
}

/**
 * The cache directory, or null when it cannot be trusted. Then the request
 * runs without a cache.
 *
 * The cache used to be a file in /tmp, which any local process can write. A
 * planted file was served to the admin's dashboard as if it came from Vast.ai.
 * /var/local is writable by root only, and the directory must also be a real
 * directory, owned by the user this script runs as, closed to everyone else.
 */
function vast_cache_dir(string $root): ?string {
    $dir = "$root/var/local/vastai";
    umask(0077);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $st  = @lstat($dir);
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : 0;
    $private = $st
        && ($st['mode'] & 0170000) === 0040000   // a directory, not a symlink to one
        && ($st['mode'] & 0077) === 0
        && $st['uid'] === $uid;
    return $private ? $dir : null;
}

function vast_read_json(?string $file): ?array {
    if ($file === null || !is_file($file) || is_link($file)) {
        return null;
    }
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

/** Writes through a temporary file and a rename, so a reader never sees half a file. */
function vast_write_json(string $file, array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    $tmp  = $file . '.' . uniqid('tmp', true);
    if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
    }
}

/** The cached API answer for a key, or null when there is none worth using. */
function vast_cache_record(?string $file): ?array {
    $record = vast_read_json($file);
    return $record && is_array($record['machines'] ?? null) && is_int($record['fetched_at'] ?? null)
        ? $record : null;
}

/**
 * One machine as the tile shows it. Every decision about a machine is made
 * here, once: its state, how many GPUs are rented, and its prices per machine.
 */
function vast_machine(array $m): array {
    $text = fn(string $key, string $default = ''): string
        => is_scalar($m[$key] ?? null) ? (string) $m[$key] : $default;
    $num  = fn(string $key): float => vast_num($m[$key] ?? 0);

    $id       = (int) vast_num($m['id'] ?? $m['machine_id'] ?? 0);
    $num_gpus = max(1, (int) $num('num_gpus'));
    $listed   = !empty($m['listed']);

    // timeout is 0 for a machine in contact and grows while it is not. Vast.ai
    // documents none of this. If the field ever goes missing every machine
    // reads as online, which keeps the rental data on screen at the price of
    // no longer noticing an offline host.
    $is_online = $num('timeout') <= 0.0;

    // gpu_occupancy has one letter per GPU, separated by spaces: "D x x x " is
    // a 4-GPU host with one GPU rented. D, R and I mark an on-demand, reserved
    // and interruptible rental and x an idle GPU. The spaces are dropped here,
    // so the string is one character per GPU from this point on. Test for the
    // three letters, never for "not empty", and never compare the whole string
    // with one letter.
    $occupancy = strtoupper(preg_replace('/\s+/', '', $text('gpu_occupancy')));
    $jobs      = max(0, (int) $num('current_rentals_running'));
    $busy      = preg_match_all('/[DRI]/', $occupancy);
    $is_rented = $jobs > 0 || $busy > 0;

    // Offline wins. A host that stopped answering is shown as offline even if
    // a rental is still open on it, and is left out of the rented count and
    // the earnings totals.
    $state = !$is_online ? 'offline' : ($is_rented ? 'rented' : ($listed ? 'listed' : 'unlisted'));

    // How many GPUs are rented, or null when the API did not say: the string
    // is not one character per GPU, or only the rental counter reports a job.
    if ($state !== 'rented') {
        $gpus_rented = 0;
    } elseif ($num_gpus === 1) {
        $gpus_rented = 1;
    } else {
        $gpus_rented = (strlen($occupancy) === $num_gpus && $busy > 0) ? $busy : null;
    }

    $rental_type = null;
    foreach (['D' => 'on_demand', 'R' => 'reserved', 'I' => 'interruptible'] as $letter => $type) {
        if (str_contains($occupancy, $letter)) {
            $rental_type = $type;
            break;
        }
    }

    $cpu_name = preg_replace('/\b(Processor|\d+-Cores?)\b/i', '', $text('cpu_name'));
    $gb       = fn(string $key): float => round($num($key) / 1024, 1);

    // Vast.ai prices a listing per GPU and reports earnings per machine. The
    // tile shows machines, so the two asks are scaled here and nowhere else.
    $gpu_cost = $num('listed_gpu_cost');

    return [
        'id'               => $id,
        'hostname'         => $text('hostname', "Machine #$id"),
        'num_gpus'         => $num_gpus,
        'gpu_name'         => preg_replace('/^NVIDIA\s+/i', '', $text('gpu_name', 'GPU')),
        'gpu_ram_gb'       => $gb('gpu_ram'),
        'gpu_temp'         => is_numeric($m['gpu_max_cur_temp'] ?? null) ? round($num('gpu_max_cur_temp'), 1) : null,
        'reliability'      => is_numeric($m['reliability2'] ?? null) ? round($num('reliability2') * 100, 1) : null,
        'verification'     => $text('verification', 'unverified'),
        'state'            => $state,
        'rental_type'      => $state === 'rented' ? $rental_type : null,
        'gpus_rented'      => $gpus_rented,
        'running_rentals'  => $jobs,
        // Vast.ai reports these as running averages of what the machine really
        // earned, so an idle machine can show a small amount and one that has
        // never been rented a number like 8e-55. Rounding turns that into 0.
        'earn_hour'        => round($num('earn_hour'), 4),
        'earn_day'         => round($num('earn_day'), 4),
        'ask_hour'         => round($gpu_cost * $num_gpus, 4),
        'min_bid_hour'     => round($num('min_bid_price') * $num_gpus, 4),
        'listed_gpu_cost'  => $gpu_cost,
        'cpu_name'         => trim(preg_replace('/\s+/', ' ', $cpu_name)),
        'cpu_cores'        => (int) $num('cpu_cores'),
        'cpu_ram_gb'       => $gb('cpu_ram'),
        'disk_space_gb'    => round($num('disk_space'), 1),
        'avail_disk_gb'    => round($num('avail_disk_space'), 1),
        'inet_down_mbps'   => round($num('inet_down'), 1),
        'inet_up_mbps'     => round($num('inet_up'), 1),
        // The API's own answers, kept for a dashboard tab that was opened
        // before an update and still runs the previous tile script.
        'is_online'        => $is_online,
        'listed'           => $listed,
        'is_rented'        => $is_rented,
        'occupancy_code'   => $occupancy,
        'min_bid_price'    => $num('min_bid_price'),
    ];
}

/**
 * The success response, built from a cache record. A cached answer goes
 * through here again on every request, so nothing that was not cast and
 * checked can reach the browser, whatever the cache file holds.
 */
function vast_payload(array $record, bool $show_balance): array {
    $machines = [];
    $online = $rented = 0;
    $earn_hour = $earn_day = 0.0;
    foreach ($record['machines'] as $raw) {
        if (!is_array($raw)) {
            continue;
        }
        $m = vast_machine($raw);
        $machines[] = $m;
        if ($m['state'] !== 'offline') {
            $online++;
            $earn_hour += $m['earn_hour'];
            $earn_day  += $m['earn_day'];
        }
        if ($m['state'] === 'rented') {
            $rented++;
        }
    }
    $balance = $record['balance'] ?? null;

    return [
        'success'   => true,
        'timestamp' => (int) $record['fetched_at'],
        'summary'   => [
            'total_machines'  => count($machines),
            'online_machines' => $online,
            'rented_machines' => $rented,
            'total_earn_hour' => round($earn_hour, 4),
            'total_earn_day'  => round($earn_day, 4),
            // null means not known: switched off, or the balance call failed.
            // It is never reported as 0.
            'account_balance' => $show_balance && is_numeric($balance) ? round(vast_num($balance), 2) : null,
        ],
        'machines'  => $machines,
    ];
}

/** Cached data served in place of a fetch that failed. */
function vast_stale(array $record, bool $show_balance, string $error): array {
    return vast_payload($record, $show_balance) + [
        'stale'     => true,
        'cache_age' => max(0, time() - $record['fetched_at']),
        'warning'   => "Vast.ai API error ($error). Showing cached data.",
    ];
}

// ---------------------------------------------------------------- request

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// Tests run this script under the CLI with VASTAI_TEST_ROOT set to a scratch
// directory. Over HTTP the SAPI is fpm-fcgi, so the prefix is always empty.
$root    = PHP_SAPI === 'cli' ? (string) getenv('VASTAI_TEST_ROOT') : '';
$cfg     = vast_config($root);
$api_key = $cfg['api_key'];
$show    = $cfg['show_balance'];
$force   = in_array($_GET['force'] ?? '', ['1', 'true'], true);

// test_key is read from POST only. Unraid checks the CSRF token on POST and on
// nothing else, so a key accepted over GET would be the one request in this
// plugin that another site could make on the admin's behalf.
$is_test = false;
if (is_string($_POST['test_key'] ?? null) && trim($_POST['test_key']) !== '') {
    $api_key = trim($_POST['test_key']);
    $is_test = true;
}

if ($api_key === '') {
    vast_respond([
        'success'   => false,
        'code'      => 'not_configured',
        'error'     => 'Vast.ai API Key is not configured. Please open Settings → Utilities → Vast.ai Monitor to enter your API key.',
        'timestamp' => time(),
    ]);
}

// One cache per key, so a changed key can never show the previous account's
// machines. A Test Connection request has no cache at all: it reads nothing
// cached and leaves nothing behind for the dashboard to pick up.
$dir          = $is_test ? null : vast_cache_dir($root);
$base         = $dir === null ? null : "$dir/cache_" . substr(hash('sha256', $api_key), 0, 16);
$cache_file   = $base === null ? null : "$base.json";
$backoff_file = $base === null ? null : "$base.backoff";

// What can be answered without calling Vast.ai: a cache younger than the TTL,
// or, for a while after a failed fetch, the old data or the recorded error.
$from_cache = function () use ($cache_file, $backoff_file, $cfg, $show): ?array {
    $record = vast_cache_record($cache_file);
    $age    = $record ? time() - $record['fetched_at'] : -1;
    if ($age >= 0 && $age < $cfg['cache_ttl']) {
        return vast_payload($record, $show) + ['cached' => true, 'cache_age' => $age];
    }
    $failure = vast_read_json($backoff_file);
    $since   = $failure ? time() - (int) ($failure['timestamp'] ?? 0) : -1;
    $window  = ($failure['code'] ?? '') === 'auth' ? VAST_BACKOFF_AUTH : VAST_BACKOFF;
    if ($since >= 0 && $since < $window) {
        return $record ? vast_stale($record, $show, (string) ($failure['error'] ?? 'unreachable')) : $failure;
    }
    return null;
};

if (!$force && ($hit = $from_cache())) {
    vast_respond($hit);
}

// One request per key talks to Vast.ai at a time. The lock is released when
// the script ends, so no exit path has to unlock it.
$lock   = $base === null ? null : @fopen("$base.lock", 'c');
$waited = false;
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    // Another request is fetching this key. Old data is good enough for one
    // poll. With nothing to show yet, wait for that request to finish.
    if (!$force && ($record = vast_cache_record($cache_file))) {
        vast_respond(vast_payload($record, $show) + ['cached' => true, 'cache_age' => max(0, time() - $record['fetched_at'])]);
    }
    flock($lock, LOCK_EX);
    $waited = true;
}
// The request that held the lock left a cache or a recorded failure behind.
if ($lock && ($waited || !$force) && ($hit = $from_cache())) {
    vast_respond($hit);
}

// 1. Machines
$started = time();
$res     = vast_api_get(VAST_MACHINES_URL, $api_key);
$data    = json_decode((string) $res['response'], true);
// A 200 whose body is not machine data (a CDN error page, a captive portal) is
// a failure too. Taking it for an empty fleet would replace the last good data.
$ok = $res['errno'] === 0 && $res['code'] === 200
    && is_array($data) && is_array($data['machines'] ?? null);

if (!$ok) {
    $code = $res['code'];
    // Vast.ai answers a missing key with 403 and an invalid one with 404
    // "Invalid user key". Only a complete answer counts: a transfer that broke
    // off says nothing about the key.
    $auth    = $res['errno'] === 0 && in_array($code, [401, 403, 404], true);
    $api_msg = is_array($data) && is_scalar($data['msg'] ?? $data['error'] ?? null)
        ? (string) ($data['msg'] ?? $data['error']) : '';

    if ($res['error'] !== '') {
        $error = $res['error'];
    } elseif ($api_msg !== '') {
        $error = "$api_msg (HTTP $code)";
    } elseif ($auth) {
        $error = "Invalid API Key or unauthorized (HTTP $code). Please verify your key from console.vast.ai/manage-keys.";
    } elseif ($code === 200) {
        $error = 'Unexpected response from the Vast.ai API (HTTP 200, not machine data)';
    } else {
        $error = "HTTP $code";
    }
    $error = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $error));

    $failure = [
        'success'   => false,
        // "auth" means the key has to change. "upstream" means wait.
        'code'      => $auth ? 'auth' : 'upstream',
        'error'     => $error,
        'http_code' => $code,
        'timestamp' => time(),
    ];

    if ($backoff_file !== null) {
        // Logged when a failure starts or changes, not on every poll.
        if ((vast_read_json($backoff_file)['error'] ?? null) !== $error) {
            syslog(LOG_WARNING, "vastai: $error");
        }
        // The next requests replay this file for the backoff window and do not
        // call Vast.ai again, whether or not there is cached data to show.
        vast_write_json($backoff_file, $failure);

        if ($auth) {
            // A rejected key is not an outage. Showing its old data as STALE
            // would hide the real problem for as long as the key stays revoked.
            @unlink($cache_file);
        } elseif ($record = vast_cache_record($cache_file)) {
            vast_respond(vast_stale($record, $show, $error));
        }
    }
    vast_respond($failure);
}

// 2. Balance. A failure here only leaves the balance unknown. The last value
//    is reused for a few minutes, so most refreshes are one API call, not two.
$balance    = null;
$balance_at = 0;
if ($show) {
    $previous = $force ? null : vast_cache_record($cache_file);
    $since    = $previous ? time() - (int) ($previous['balance_at'] ?? 0) : -1;
    if ($since >= 0 && $since < VAST_BALANCE_TTL && is_numeric($previous['balance'] ?? null)) {
        $balance    = vast_num($previous['balance']);
        $balance_at = (int) $previous['balance_at'];
    } else {
        $user_res = vast_api_get(VAST_USER_URL, $api_key, 5);
        $user     = $user_res['code'] === 200 ? json_decode((string) $user_res['response'], true) : null;
        if (is_array($user) && is_numeric($user['balance'] ?? null)) {
            $balance    = vast_num($user['balance']);
            $balance_at = time();
        }
    }
}

// 3. Keep what the API said, then answer from it. The age of a cache counts
//    from when its fetch started, so a refresh interval equal to the cache TTL
//    gets new data on every poll and not on every second one.
$record = [
    'fetched_at' => $started,
    'balance'    => $balance,
    'balance_at' => $balance_at,
    'machines'   => $data['machines'],
];
if ($cache_file !== null) {
    vast_write_json($cache_file, $record);
    @unlink($backoff_file);
}

vast_respond(vast_payload($record, $show));

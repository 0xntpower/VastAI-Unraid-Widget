<?php
/**
 * Vast.ai Unraid Plugin - Data Fetcher & Cache Proxy
 *
 * Retrieves machine statuses, rental state, pricing, and account balance
 * from the Vast.ai API with local file caching and error recovery.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$plugin_dir = '/usr/local/emhttp/plugins/vastai';
$config_file = '/boot/config/plugins/vastai/vastai.cfg';
$default_cfg = "$plugin_dir/default.cfg";

// Load configuration
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

$api_key      = trim($cfg['API_KEY'] ?? '');
$cache_ttl    = max(10, (int) ($cfg['CACHE_TTL'] ?? 30));
$show_balance = (($cfg['SHOW_BALANCE'] ?? '1') !== '0');
$force_reload = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');

// test_key is accepted on POST only. Unraid validates CSRF on POST via
// local_prepend.php but the gate is REQUEST_METHOD === 'POST', so a GET branch
// here would be the only unauthenticated entry point in the plugin.
$is_test_key = false;
if (!empty($_POST['test_key'])) {
    $api_key = trim($_POST['test_key']);
    $is_test_key = true;
}

// If API key is not configured, inform the UI cleanly
if (empty($api_key)) {
    echo json_encode([
        'success'        => false,
        'not_configured' => true,
        'error'          => 'Vast.ai API Key is not configured. Please open Settings → Utilities → Vast.ai Monitor to enter your API key.',
        'timestamp'      => time(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// One cache per key. Changing the key in Settings must never show the previous
// account's fleet, not even as a stale fallback while the new key is failing.
$cache_file = '/tmp/vastai_cache_' . substr(hash('sha256', $api_key), 0, 16) . '.json';

// Check cache if not forcing refresh and not testing an ad-hoc key
if (!$force_reload && !$is_test_key && file_exists($cache_file)) {
    $mtime = filemtime($cache_file);
    $age = time() - $mtime;
    if ($age < $cache_ttl) {
        $cached_data = @file_get_contents($cache_file);
        if ($cached_data) {
            $json = json_decode($cached_data, true);
            if (is_array($json) && !empty($json['success'])) {
                $json['cached'] = true;
                $json['cache_age'] = $age;
                echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }
}

// Negative caching. After an upstream failure, do not retry on every poll.
// Without this an outage makes outbound traffic go UP, because the stale path
// serves the cache without refreshing its mtime, so every later request retries.
$backoff_file = $cache_file . '.backoff';
$backoff_ttl  = 30;
if (!$force_reload && !$is_test_key
    && file_exists($backoff_file) && (time() - filemtime($backoff_file)) < $backoff_ttl
    && file_exists($cache_file)) {
    $stale_data = @file_get_contents($cache_file);
    $json = $stale_data ? json_decode($stale_data, true) : null;
    if (is_array($json)) {
        $json['stale']   = true;
        $json['warning'] = 'Vast.ai API unreachable. Showing cached data.';
        echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/**
 * Helper to execute HTTP GET via cURL
 */
function vast_api_get(string $url, string $api_key, int $timeout = 9): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL             => $url,
        CURLOPT_RETURNTRANSFER  => true,
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
            // A header, not ?api_key=, so the key never appears in a URL, and
            // libcurl drops it if a redirect leaves the original host.
            'Authorization: Bearer ' . $api_key,
        ],
    ]);

    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $error    = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code'     => $httpCode,
        'errno'    => $errno,
        'error'    => $error,
        'response' => $response,
    ];
}

$machines_url = 'https://console.vast.ai/api/v0/machines/?owner=me';
$user_url     = 'https://console.vast.ai/api/v0/users/current/';

// 1. Fetch hosted machines
$mach_res = vast_api_get($machines_url, $api_key);
$raw_machines_data = json_decode((string) $mach_res['response'], true);
// A 200 whose body is not the expected JSON (a CDN error page, a captive
// portal) is a failure too. Treating it as success cached an empty fleet over
// the last good response.
$mach_ok = $mach_res['errno'] === 0 && $mach_res['code'] === 200
    && is_array($raw_machines_data) && is_array($raw_machines_data['machines'] ?? null);

if (!$mach_ok) {
    $code        = $mach_res['code'];
    // Vast.ai answers a missing key with 403 and an invalid one with 404
    // "Invalid user key", not 401.
    $auth_failed = in_array($code, [401, 403, 404], true);
    $api_msg     = is_array($raw_machines_data)
        ? (string) ($raw_machines_data['msg'] ?? $raw_machines_data['error'] ?? '') : '';

    if ($mach_res['error'] !== '') {
        $errMsg = $mach_res['error'];
    } elseif ($api_msg !== '') {
        $errMsg = "$api_msg (HTTP $code)";
    } elseif ($auth_failed) {
        $errMsg = "Invalid API Key or unauthorized (HTTP $code). Please verify your key from console.vast.ai/manage-keys.";
    } elseif ($code === 200) {
        $errMsg = 'Unexpected response from the Vast.ai API (HTTP 200, not machine data)';
    } else {
        $errMsg = "HTTP $code";
    }

    if (!$is_test_key) {
        // Record the failure so the negative-cache backoff above engages and we
        // do not hammer a down upstream once per poll per open dashboard tab.
        @touch($backoff_file);

        if ($auth_failed) {
            // A rejected key is not an outage. Serving its cache as STALE would
            // hide the real problem for as long as the key stays revoked.
            @unlink($cache_file);
        } elseif (file_exists($cache_file)) {
            $stale_data = @file_get_contents($cache_file);
            $json = $stale_data ? json_decode($stale_data, true) : null;
            if (is_array($json)) {
                $json['stale']   = true;
                $json['warning'] = "Vast.ai API error ($errMsg). Showing cached data.";
                echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }

    echo json_encode([
        'success'   => false,
        'error'     => $errMsg,
        'http_code' => $code,
        'timestamp' => time(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
$raw_machines = $raw_machines_data['machines'];

// 2. Fetch account balance (optional). A failure here only hides the balance.
$balance = 0.0;
if ($show_balance) {
    $user_res = vast_api_get($user_url, $api_key, 5);
    $u = $user_res['code'] === 200 ? json_decode((string) $user_res['response'], true) : null;
    if (is_array($u)) {
        $balance = (float) ($u['balance'] ?? 0.0);
    }
}

// 3. Process each machine
$processed_machines = [];
$total_earn_hour = 0.0;
$total_earn_day  = 0.0;
$rented_count    = 0;

foreach ($raw_machines as $m) {
    if (!is_array($m)) {
        continue;
    }
    $mach_id   = (int) ($m['id'] ?? $m['machine_id'] ?? 0);
    $hostname  = (string) ($m['hostname'] ?? "Machine #{$mach_id}");
    $num_gpus  = max(1, (int) ($m['num_gpus'] ?? 1));

    // Clean GPU Name (remove NVIDIA prefix if repetitive)
    $gpu_name = (string) ($m['gpu_name'] ?? 'GPU');
    $gpu_name = preg_replace('/^NVIDIA\s+/i', '', $gpu_name);

    $gpu_ram_mb = (int) ($m['gpu_ram'] ?? 0);
    $gpu_ram_gb = $gpu_ram_mb > 0 ? round($gpu_ram_mb / 1024, 1) : 0;

    $listed   = !empty($m['listed']);
    $timeout  = (float) ($m['timeout'] ?? 0);
    $is_online = ($timeout <= 0.0);

    // Rentals detection
    $running_rentals = (int) ($m['current_rentals_running'] ?? 0);
    $occupancy_code  = strtoupper(trim((string) ($m['gpu_occupancy'] ?? '')));

    // gpu_occupancy is a PER-GPU string, one character per GPU, so an 8-GPU host
    // returns something like "DDxxxxxx". Two consequences:
    //   1. Never compare it for equality against a single character. That can
    //      never match a multi-GPU host.
    //   2. Never treat "non-empty" as rented. An idle host still returns a
    //      full-length string of placeholder characters.
    // Testing for the known occupancy letters avoids depending on whatever the
    // idle placeholder character happens to be. The tile derives the rental
    // type and the share of GPUs rented from the same letters client side.
    $is_rented = ($running_rentals > 0) || preg_match('/[DRI]/', $occupancy_code) === 1;
    if ($is_online && $is_rented) {
        $rented_count++;
    }

    // Pricing & Earnings
    $earn_hour       = (float) ($m['earn_hour'] ?? 0.0);
    $earn_day        = (float) ($m['earn_day'] ?? 0.0);
    $listed_gpu_cost = (float) ($m['listed_gpu_cost'] ?? 0.0);
    $min_bid_price   = (float) ($m['min_bid_price'] ?? 0.0);

    $total_earn_hour += $earn_hour;
    $total_earn_day  += $earn_day;

    // GPU Temp & Reliability
    $gpu_temp    = isset($m['gpu_max_cur_temp']) && $m['gpu_max_cur_temp'] !== null ? round((float) $m['gpu_max_cur_temp'], 1) : null;
    $reliability = isset($m['reliability2']) && $m['reliability2'] !== null ? round(((float) $m['reliability2']) * 100, 1) : null;
    $verification = (string) ($m['verification'] ?? 'unverified');

    // Host Hardware Specs
    $cpu_name = (string) ($m['cpu_name'] ?? '');
    // Clean up CPU branding
    $cpu_name = preg_replace('/(Processor|12-Cores|16-Cores|8-Cores|6-Cores|24-Cores|32-Cores|64-Cores)/i', '', $cpu_name);
    $cpu_name = trim(preg_replace('/\s+/', ' ', $cpu_name));

    $cpu_cores = (int) ($m['cpu_cores'] ?? 0);
    $cpu_ram_mb = (int) ($m['cpu_ram'] ?? 0);
    $cpu_ram_gb = $cpu_ram_mb > 0 ? round($cpu_ram_mb / 1024, 1) : 0;

    $disk_space_gb  = (float) ($m['disk_space'] ?? 0);
    $avail_disk_gb  = (float) ($m['avail_disk_space'] ?? 0);
    $inet_down_mbps = round((float) ($m['inet_down'] ?? 0), 1);
    $inet_up_mbps   = round((float) ($m['inet_up'] ?? 0), 1);

    $processed_machines[] = [
        'id'               => $mach_id,
        'hostname'         => $hostname,
        'num_gpus'         => $num_gpus,
        'gpu_name'         => $gpu_name,
        'gpu_ram_gb'       => $gpu_ram_gb,
        'gpu_temp'         => $gpu_temp,
        'reliability'      => $reliability,
        'verification'     => $verification,
        'is_online'        => $is_online,
        'listed'           => $listed,
        'is_rented'        => $is_rented,
        'occupancy_code'   => $occupancy_code,
        'running_rentals'  => $running_rentals,
        'earn_hour'        => $earn_hour,
        'earn_day'         => $earn_day,
        'listed_gpu_cost'  => $listed_gpu_cost,
        'min_bid_price'    => $min_bid_price,
        'cpu_name'         => $cpu_name,
        'cpu_cores'        => $cpu_cores,
        'cpu_ram_gb'       => $cpu_ram_gb,
        'disk_space_gb'    => $disk_space_gb,
        'avail_disk_gb'    => $avail_disk_gb,
        'inet_down_mbps'   => $inet_down_mbps,
        'inet_up_mbps'     => $inet_up_mbps,
    ];
}

$payload = [
    'success'   => true,
    'timestamp' => time(),
    'summary'   => [
        'total_machines'  => count($processed_machines),
        'rented_machines' => $rented_count,
        'total_earn_hour' => round($total_earn_hour, 4),
        'total_earn_day'  => round($total_earn_day, 4),
        'account_balance' => round($balance, 2),
    ],
    'machines'  => $processed_machines,
];

// Write to cache file atomically. Never on a test-key request, or clicking
// Test Connection would publish that account's fleet to every dashboard tab.
if (!$is_test_key) {
    @unlink($backoff_file);
    $tmp_cache = $cache_file . '.' . uniqid('tmp', true);
    if (@file_put_contents($tmp_cache, json_encode($payload, JSON_UNESCAPED_SLASHES)) !== false) {
        if (!@rename($tmp_cache, $cache_file)) {
            @unlink($tmp_cache);
        }
    } else {
        @unlink($tmp_cache);
    }
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

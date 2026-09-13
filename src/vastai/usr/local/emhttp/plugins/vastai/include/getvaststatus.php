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
$cache_file = '/tmp/vastai_cache.json';

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
$interval     = (int) ($cfg['INTERVAL'] ?? 30);
$cache_ttl    = max(10, (int) ($cfg['CACHE_TTL'] ?? 30));
$show_balance = (($cfg['SHOW_BALANCE'] ?? '1') !== '0');
$force_reload = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');

$is_test_key = false;
if (!empty($_POST['test_key'])) {
    $api_key = trim($_POST['test_key']);
    $is_test_key = true;
} elseif (!empty($_GET['test_key'])) {
    $api_key = trim($_GET['test_key']);
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

/**
 * Helper to execute HTTP GET via cURL
 */
function vast_api_get(string $url, int $timeout = 9): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'VastAI-Unraid-Widget/1.0',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
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

$api_key_enc = urlencode($api_key);
$machines_url = "https://console.vast.ai/api/v0/machines/?owner=me&api_key={$api_key_enc}";
$user_url     = "https://console.vast.ai/api/v0/users/current/?api_key={$api_key_enc}";

// 1. Fetch hosted machines
$mach_res = vast_api_get($machines_url);
if ($mach_res['errno'] !== 0 || $mach_res['code'] !== 200) {
    // If request failed, check for stale cache fallback (unless testing a custom key)
    if (!$is_test_key && file_exists($cache_file)) {
        $stale_data = @file_get_contents($cache_file);
        if ($stale_data) {
            $json = json_decode($stale_data, true);
            if (is_array($json)) {
                $json['stale'] = true;
                $json['warning'] = 'Vast.ai API connection error (' . ($mach_res['error'] ?: "HTTP {$mach_res['code']}") . '). Showing cached data.';
                echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                exit;
            }
        }
    }

    $errMsg = $mach_res['error'] ?: "HTTP {$mach_res['code']}";
    if ($mach_res['code'] === 401 || $mach_res['code'] === 403) {
        $resp_json = @json_decode($mach_res['response'] ?? '', true);
        if (is_array($resp_json) && !empty($resp_json['msg'])) {
            $errMsg = $resp_json['msg'] . " (HTTP {$mach_res['code']})";
        } else {
            $errMsg = "Invalid API Key or unauthorized (HTTP {$mach_res['code']}). Please verify your key from console.vast.ai/manage-keys.";
        }
    }
    echo json_encode([
        'success'   => false,
        'error'     => $errMsg,
        'http_code' => $mach_res['code'],
        'timestamp' => time(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw_machines_data = json_decode($mach_res['response'], true);
if (is_array($raw_machines_data) && isset($raw_machines_data['success']) && $raw_machines_data['success'] === false) {
    $errMsg = $raw_machines_data['msg'] ?? $raw_machines_data['error'] ?? 'API error';
    echo json_encode([
        'success'   => false,
        'error'     => $errMsg,
        'http_code' => $mach_res['code'],
        'timestamp' => time(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
$raw_machines = $raw_machines_data['machines'] ?? [];

// 2. Fetch User Account Balance (optional)
$account_info = [
    'balance' => 0.0,
    'credit'  => 0.0,
    'email'   => '',
];
if ($show_balance) {
    $user_res = vast_api_get($user_url, 5);
    if ($user_res['code'] === 200 && !empty($user_res['response'])) {
        $u = json_decode($user_res['response'], true);
        if (is_array($u)) {
            $account_info['balance'] = (float) ($u['balance'] ?? 0.0);
            $account_info['credit']  = (float) ($u['credit'] ?? 0.0);
            $account_info['email']   = (string) ($u['email'] ?? '');
        }
    }
}

// 3. Process each machine
$processed_machines = [];
$total_earn_hour = 0.0;
$total_earn_day  = 0.0;
$rented_count    = 0;
$total_gpus      = 0;
$rented_gpus     = 0;

foreach ($raw_machines as $m) {
    $mach_id   = (int) ($m['id'] ?? $m['machine_id'] ?? 0);
    $hostname  = (string) ($m['hostname'] ?? "Machine #{$mach_id}");
    $num_gpus  = max(1, (int) ($m['num_gpus'] ?? 1));
    $total_gpus += $num_gpus;

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
    $resident_rentals = (int) ($m['current_rentals_resident'] ?? 0);
    $on_demand_rentals = (int) ($m['current_rentals_running_on_demand'] ?? 0);
    $reserved_rentals  = (int) ($m['current_rentals_running_reserved'] ?? 0);
    $occupancy_raw     = (string) ($m['gpu_occupancy'] ?? '');
    $occupancy_code    = strtoupper(trim($occupancy_raw));

    $is_rented = ($running_rentals > 0) || ($occupancy_code !== '');

    // Rental status classification
    if (!$is_online) {
        $status_key   = 'offline';
        $status_label = 'Offline';
        $status_color = 'red';
    } elseif ($is_rented) {
        $rented_count++;
        $rented_gpus += $num_gpus;

        if ($on_demand_rentals > 0 || $occupancy_code === 'D') {
            $status_key   = 'rented_on_demand';
            $status_label = 'Rented (On-Demand)';
            $status_color = 'green';
        } elseif ($reserved_rentals > 0 || $occupancy_code === 'R') {
            $status_key   = 'rented_reserved';
            $status_label = 'Rented (Reserved)';
            $status_color = 'purple';
        } elseif ($occupancy_code === 'I' || $running_rentals > 0) {
            $status_key   = 'rented_interruptible';
            $status_label = 'Rented (Interruptible)';
            $status_color = 'yellow';
        } else {
            $status_key   = 'rented';
            $status_label = 'Rented';
            $status_color = 'green';
        }
    } elseif ($listed) {
        $status_key   = 'listed';
        $status_label = 'Listed & Available';
        $status_color = 'blue';
    } else {
        $status_key   = 'unlisted';
        $status_label = 'Unlisted';
        $status_color = 'gray';
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
        'status_key'       => $status_key,
        'status_label'     => $status_label,
        'status_color'     => $status_color,
        'occupancy_code'   => $occupancy_code,
        'running_rentals'  => $running_rentals,
        'resident_rentals' => $resident_rentals,
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
        'total_gpus'      => $total_gpus,
        'rented_gpus'     => $rented_gpus,
        'total_earn_hour' => round($total_earn_hour, 4),
        'total_earn_day'  => round($total_earn_day, 4),
        'account_balance' => round($account_info['balance'], 2),
        'account_credit'  => round($account_info['credit'], 2),
    ],
    'machines'  => $processed_machines,
];

// Write to cache file atomically
$tmp_cache = $cache_file . '.' . uniqid('tmp', true);
if (@file_put_contents($tmp_cache, json_encode($payload, JSON_UNESCAPED_SLASHES))) {
    @rename($tmp_cache, $cache_file);
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

<?php
// Runs the real endpoint once per request in a child php process, with a canned
// Vast.ai, and checks the JSON it prints, the cache files it leaves and how
// many calls it made. No network, no web server. Needs Linux (rm, symlink).
// Run:  php tests/endpoint.test.php
//       php tests/endpoint.test.php --update-fixture   (after a deliberate format change)
declare(strict_types=1);

const KEY = 'k-main';
$endpoint = __DIR__ . '/../src/vastai/usr/local/emhttp/plugins/vastai/include/getvaststatus.php';
$payload  = __DIR__ . '/fixtures/payload.json';
$root     = sys_get_temp_dir() . '/vastai-test-' . getmypid();
$cfg      = "$root/boot/config/plugins/vastai/vastai.cfg";
$cache    = "$root/var/local/vastai";
$upstream = "$root/upstream.json";
$fail     = 0;

// ------------------------------------------------------------------ harness

function check(string $name, bool $ok): void {
    global $fail;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . "\n";
    $fail = $ok ? $fail : 1;
}

// A clean box: no cache, no call log, the given settings.
function box(string $ini = 'API_KEY="' . KEY . '"'): void {
    global $root, $cfg;
    if (is_dir($root)) {
        exec('rm -rf ' . escapeshellarg($root));
    }
    mkdir(dirname($cfg), 0700, true);
    file_put_contents($cfg, $ini . "\n");
}

// What Vast.ai answers from now on. Each is [http code, body, curl errno, curl error].
function vast(array $machines, array $user = [200, '{"balance": 5.129}'], int $delay_ms = 0): void {
    global $upstream;
    file_put_contents($upstream, json_encode(compact('machines', 'user', 'delay_ms')));
}

function start(array $get = [], array $post = []) {
    global $endpoint, $root, $upstream;
    $env = getenv() + [
        'VASTAI_TEST_ROOT' => $root,
        'T_UPSTREAM'       => $upstream,
        'T_GET'            => json_encode($get),
        'T_POST'           => json_encode($post),
    ];
    $cmd = [PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/prelude.php', '-d', 'display_errors=1', $endpoint];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    return [$p, $pipes];
}

// The decoded answer, or null when the output was not exactly one JSON document.
// A PHP warning printed into the body makes it null, which fails the check.
function finish(array $h): ?array {
    [$p, $pipes] = $h;
    $out = stream_get_contents($pipes[1]);
    proc_close($p);
    return json_decode($out, true);
}

function get(array $get = [], array $post = []): ?array {
    return finish(start($get, $post));
}

function calls(string $which = 'machines'): int {
    global $upstream;
    return count(preg_grep("/^$which /", @file("$upstream.log") ?: []));
}

function keys_used(): array {
    global $upstream;
    return array_values(array_unique(array_map(fn($l) => explode(' ', $l)[1], @file("$upstream.log") ?: [])));
}

// The files the endpoint left in its cache directory, by suffix.
function left(string $suffix = '.json'): array {
    global $cache;
    return array_values(array_filter(glob("$cache/*") ?: [], fn($f) => str_ends_with($f, $suffix)));
}

// Makes a cache or backoff file look $seconds older than it is. Ages are read
// from fields inside the file, not from its modification time.
function age(string $file, int $seconds, array $fields = ['fetched_at', 'timestamp']): void {
    $data = json_decode(file_get_contents($file), true);
    foreach ($fields as $f) {
        if (isset($data[$f])) {
            $data[$f] -= $seconds;
        }
    }
    file_put_contents($file, json_encode($data));
}

$fleet = file_get_contents(__DIR__ . '/fixtures/machines.json');
$ok    = [200, $fleet];
$down  = [500, ''];
$gone  = [404, '{"msg": "Invalid user key"}'];

// -------------------------------------------------------------------- cases

echo "not configured\n";
box('API_KEY=""');
vast($ok);
$r = get();
check('says so with a code', ($r['code'] ?? '') === 'not_configured' && $r['success'] === false);
check('makes no call to Vast.ai', calls() === 0 && calls('user') === 0);

echo "success\n";
box();
vast($ok);
$r = get();
$m = array_column($r['machines'] ?? [], null, 'id');
$s = $r['summary'] ?? [];
check('succeeds, live', ($r['success'] ?? false) === true && !isset($r['cached']) && !isset($r['stale']));
check('entries that are not machines are skipped', count($m) === 8 && $s['total_machines'] === 8);
check('idle hosts are listed, whatever the length of their occupancy string',
    $m[101]['state'] === 'listed' && $m[103]['state'] === 'listed' && $m[103]['gpus_rented'] === 0);
check('space-separated occupancy letters are read one per GPU',
    $m[102]['occupancy_code'] === 'DDXXXXXX' && $m[102]['state'] === 'rented' && $m[102]['gpus_rented'] === 2);
check('occupancy letters alone mark a host rented',
    $m[105]['state'] === 'rented' && $m[105]['running_rentals'] === 0 && $m[105]['gpus_rented'] === 1);
check('the rental type is the first of on-demand, reserved, interruptible',
    $m[102]['rental_type'] === 'on_demand' && $m[105]['rental_type'] === 'reserved' && $m[106]['rental_type'] === 'interruptible');
check('a rental counter with no letters is rented with an unknown split',
    $m[108]['state'] === 'rented' && $m[108]['gpus_rented'] === null && $m[108]['rental_type'] === null);
check('offline wins over rented', $m[104]['state'] === 'offline' && $m[104]['gpus_rented'] === 0 && $m[104]['rental_type'] === null);
check('an unlisted host is unlisted', $m[107]['state'] === 'unlisted');
check('the summary counts online and rented hosts', $s['online_machines'] === 7 && $s['rented_machines'] === 4);
check('earnings leave out the offline host', $s['total_earn_hour'] === 2.2221 && $s['total_earn_day'] === 53.7108);
check('a vanishing earnings average is zero', $m[107]['earn_hour'] == 0);
check('the balance is rounded to cents', $s['account_balance'] === 5.13);
check('asks are scaled to the machine and the per-GPU price is kept',
    $m[102]['ask_hour'] == 6 && $m[102]['min_bid_hour'] === 2.4 && $m[102]['listed_gpu_cost'] === 0.75);
// json_encode prints 24.0 as 24, so whole numbers come back as int. Compare loosely.
check('display fields are cleaned',
    $m[101]['gpu_name'] === 'RTX 4090' && $m[101]['gpu_ram_gb'] == 24 && $m[101]['reliability'] === 99.5
    && $m[101]['cpu_name'] === 'AMD EPYC 7763' && $m[102]['cpu_name'] === 'Intel Xeon Platinum 8480+');
check('a missing temperature or reliability is null, not 0', $m[103]['gpu_temp'] === null && $m[103]['reliability'] === null);
check('the key goes to Vast.ai in the call, not in its URL',
    keys_used() === [KEY] && !str_contains(file_get_contents("$upstream.log"), 'api_key=') && !str_contains(file_get_contents("$upstream.log"), '=' . KEY));
check('one machines call and one balance call', calls() === 1 && calls('user') === 1);
check('one cache file, no backoff file', count(left()) === 1 && left('.backoff') === []);
check('the cache directory and file are closed to other users',
    (fileperms($cache) & 0777) === 0700 && (fileperms(left()[0]) & 0077) === 0);

// The endpoint's answer for the fixture fleet, pinned. The tile test and the
// preview both read this file, so they always see what the endpoint really sends.
$r['timestamp'] = 0;
$json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (in_array('--update-fixture', $argv, true)) {
    file_put_contents($payload, $json);
}
check('the answer matches tests/fixtures/payload.json', @file_get_contents($payload) === $json);

echo "cache\n";
$r = get();
check('a second request is answered from cache with no call', ($r['cached'] ?? false) === true && is_int($r['cache_age']) && calls() === 1);
$r = get(['force' => '1']);
check('force=1 fetches the machines and the balance again', !isset($r['cached']) && calls() === 2 && calls('user') === 2);
age(left()[0], 25, ['fetched_at']);
get();
check('a cache younger than the TTL is still used', calls() === 2);
age(left()[0], 5, ['fetched_at']);
get();
check('a cache as old as the TTL is not, so interval = TTL refreshes on every poll', calls() === 3);
box('API_KEY="' . KEY . "\"\nCACHE_TTL=\"0\"");
vast($ok);
get();
$r = get();
check('a CACHE_TTL that Settings does not offer falls back to 30', ($r['cached'] ?? false) === true && calls() === 1);

echo "Vast.ai down, cache present\n";
box();
vast($ok);
get();
age(left()[0], 3600);
vast($down);
$r = get();
check('shows the old data as stale, with a warning and its age',
    ($r['stale'] ?? false) === true && count($r['machines']) === 8 && str_contains($r['warning'], 'HTTP 500') && $r['cache_age'] >= 3600);
$r = get();
check('the next request inside the wait makes no call', ($r['stale'] ?? false) === true && calls() === 2);
age(left('.backoff')[0], 31);
get();
check('after the wait it tries again', calls() === 3);
vast($ok);
age(left('.backoff')[0], 31);
$r = get();
check('recovery clears the recorded failure and the stale flag', !isset($r['stale']) && left('.backoff') === []);

echo "Vast.ai down, nothing cached\n";
box();
vast($down);
$r = get();
check('reports the failure with a code', $r['success'] === false && $r['code'] === 'upstream' && $r['error'] === 'HTTP 500' && $r['http_code'] === 500);
$r = get();
check('the next request replays it and makes no call', $r['success'] === false && $r['code'] === 'upstream' && calls() === 1);
$r = get(['force' => '1']);
check('force=1 tries again at once', calls() === 2);
box();
vast([0, '', 28, "Operation timed out\nafter 9001 ms"]);
$r = get();
check('a transport error is reported in curl\'s words, on one line', $r['error'] === 'Operation timed out after 9001 ms');

echo "rejected key\n";
box();
vast($ok);
get();
age(left()[0], 3600);
vast($gone);
$r = get();
check('is an error with code auth, not stale data', $r['success'] === false && $r['code'] === 'auth' && !isset($r['stale']) && str_contains($r['error'], 'Invalid user key'));
check('drops that key\'s cache', left() === []);
$n = calls();
$r = get();
age(left('.backoff')[0], 200);
get();
check('is not presented to Vast.ai again for five minutes', $r['code'] === 'auth' && calls() === $n);
age(left('.backoff')[0], 101);
get();
check('is tried again after five minutes', calls() === $n + 1);
box();
vast([401, '']);
$r = get();
check('401 is a rejected key too', $r['code'] === 'auth');

echo "a 200 that is not machine data\n";
box();
vast($ok);
get();
age(left()[0], 3600);
$before = md5_file(left()[0]);
vast([200, '<html>502 Bad Gateway</html>']);
$r = get();
check('an HTML body does not replace the last good data', ($r['stale'] ?? false) === true && md5_file(left()[0]) === $before);
box();
vast([200, '{"error": "nope"}']);
$r = get();
check('JSON without a machines list is a failure', $r['success'] === false && left() === []);
box();
vast([200, '{"machines": []}']);
$r = get();
check('an empty fleet is a success with zero machines', $r['success'] === true && $r['summary']['total_machines'] === 0 && $r['machines'] === []);
box();
vast([200, '{"machines": [{}]}']);
$r = get();
check('a machine with no fields gets safe defaults',
    $r['machines'][0]['num_gpus'] === 1 && $r['machines'][0]['state'] === 'unlisted' && $r['machines'][0]['hostname'] === 'Machine #0');

echo "values JSON cannot hold, and values of the wrong type\n";
box();
vast([200, '{"machines": [{"id": 1, "disk_space": 1e999, "num_gpus": "<img src=x>", "hostname": ["a"], "earn_hour": "x"}]}']);
$r = get();
check('the answer is still JSON', is_array($r) && $r['success'] === true);
check('an out-of-range or mistyped number becomes 0 or the default',
    $r['machines'][0]['disk_space_gb'] == 0 && $r['machines'][0]['num_gpus'] === 1 && $r['machines'][0]['earn_hour'] == 0
    && $r['machines'][0]['hostname'] === 'Machine #1');
check('the cache it left is readable', is_array(json_decode(file_get_contents(left()[0]), true)));
$r = get();
check('and is served', ($r['cached'] ?? false) === true && count($r['machines']) === 1);

echo "test key\n";
box();
vast($ok);
get();
$before = md5_file(left()[0]);
vast([200, '{"machines": [{"id": 9, "hostname": "host-of-{KEY}"}]}']);
$r = get(['force' => '1'], ['test_key' => ' k-other ']);
check('uses the posted key, trimmed', $r['machines'][0]['hostname'] === 'host-of-k-other');
check('writes no cache and leaves the saved key\'s cache alone', count(left('')) === 2 && md5_file(left()[0]) === $before);
$n = calls();
$r = get([], ['test_key' => KEY]);
check('is never answered from cache, even for the saved key', !isset($r['cached']) && calls() === $n + 1);
vast($gone);
$r = get([], ['test_key' => KEY]);
check('a rejected test key does not delete the cache or start a wait',
    $r['code'] === 'auth' && count(left()) === 1 && left('.backoff') === []);
vast([200, '{"machines": [{"id": 9, "hostname": "host-of-{KEY}"}]}']);
$r = get(['test_key' => 'k-evil', 'force' => '1']);
check('test_key in the query string is ignored', $r['machines'][0]['hostname'] === 'host-of-' . KEY);
$r = get(['force' => '1'], ['test_key' => ['a']]);
check('test_key sent as an array is ignored too', is_array($r) && $r['machines'][0]['hostname'] === 'host-of-' . KEY);

echo "cache trust\n";
box();
vast($ok);
get();
file_put_contents(left()[0], json_encode(['fetched_at' => time(), 'balance' => '<img src=x>', 'balance_at' => time(),
    'machines' => [['id' => 1, 'num_gpus' => '<img src=x>', 'timeout' => '<img src=x>', 'gpu_max_cur_temp' => '<img src=x>']]]));
$r = get();
check('a cache file with markup where numbers belong is cast, not replayed',
    ($r['cached'] ?? false) === true && !str_contains(json_encode($r), '<img') && $r['summary']['account_balance'] === null);
$real = left()[0];
rename($real, "$root/planted.json");
symlink("$root/planted.json", $real);
$n = calls();
$r = get();
check('a cache file that is a symlink is not used', !isset($r['cached']) && calls() === $n + 1);
box();
vast($ok);
mkdir($cache, 0700, true);
chmod($cache, 0777);
get();
get();
check('a cache directory other users can write is not used', calls() === 2 && left('') === []);
box();
vast($ok);
mkdir("$root/elsewhere", 0700);
mkdir(dirname($cache), 0700, true);
symlink("$root/elsewhere", $cache);
get();
get();
check('nor is one that is a symlink', calls() === 2 && glob("$root/elsewhere/*") === []);

echo "balance\n";
box();
vast($ok);
get();
age(left()[0], 60, ['fetched_at']);
$r = get();
check('is reused on the next fleet refresh', calls() === 2 && calls('user') === 1 && $r['summary']['account_balance'] === 5.13);
age(left()[0], 301, ['fetched_at', 'balance_at']);
get();
check('is fetched again after five minutes', calls('user') === 2);
box();
vast($ok, [500, '']);
$r = get();
check('a failed balance call still returns the fleet', $r['success'] === true && count($r['machines']) === 8);
check('an unknown balance is null, not 0', array_key_exists('account_balance', $r['summary']) && $r['summary']['account_balance'] === null);
get(['force' => '1']);
check('and is asked for again on the next fetch', calls('user') === 2);
box();
vast($ok, [200, '{"credit": 3}']);
$r = get();
check('an answer with no balance field is unknown too', $r['summary']['account_balance'] === null);
box('API_KEY="' . KEY . "\"\nSHOW_BALANCE=\"0\"");
vast($ok);
$r = get();
check('SHOW_BALANCE=0 makes no balance call and shows none', calls('user') === 0 && $r['summary']['account_balance'] === null);
box();
vast($ok);
get();
file_put_contents($cfg, 'API_KEY="' . KEY . "\"\nSHOW_BALANCE=\"0\"\n");
$r = get();
check('switching the balance off hides a cached one', ($r['cached'] ?? false) === true && $r['summary']['account_balance'] === null);

echo "several requests at once\n";
box();
vast($ok, [200, '{"balance": 1}'], 400);
$a = start();
$b = start();
$c = start(['force' => '1']);
$ra = finish($a);
$rb = finish($b);
$rc = finish($c);
check('all get the fleet', count($ra['machines'] ?? []) === 8 && count($rb['machines'] ?? []) === 8 && count($rc['machines'] ?? []) === 8);
check('only one of them calls Vast.ai', calls() === 1 && calls('user') === 1);
check('nothing but the cache and its lock is left behind', count(left('')) === 2 && left('.backoff') === []);
age(left()[0], 3600, ['fetched_at']);
vast($ok, [200, '{"balance": 1}'], 2000);
$a = start();
usleep(500000);
$t = microtime(true);
$rb = get();
check('while one request refreshes, another gets the old data without waiting for it',
    ($rb['cached'] ?? false) === true && $rb['cache_age'] >= 3600 && microtime(true) - $t < 1.0);
finish($a);
check('and makes no call of its own', calls() === 2);

exec('rm -rf ' . escapeshellarg($root));
echo $fail ? "\nendpoint.test.php: FAILED\n" : "\nendpoint.test.php: ok\n";
exit($fail);

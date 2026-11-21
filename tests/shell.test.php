<?php
// Builds the dashboard tile shell with the real VastAI.php and checks what it
// hands to the browser, and that the settings reader accepts only what the
// settings page offers.
// Run:  php tests/shell.test.php
//       php tests/shell.test.php --update-fixture   (after a deliberate markup change)
declare(strict_types=1);

$root = sys_get_temp_dir() . '/vastai-shell-' . getmypid();
$base = __DIR__ . '/../src/vastai/usr/local/emhttp/plugins/vastai';
mkdir("$root/boot/config/plugins/vastai", 0700, true);
mkdir("$root/usr/local/emhttp/plugins/vastai", 0700, true);
copy("$base/default.cfg", "$root/usr/local/emhttp/plugins/vastai/default.cfg");
putenv("VASTAI_TEST_ROOT=$root");
$fail = 0;

function check(string $name, bool $ok): void {
    global $fail;
    echo ($ok ? '  ok    ' : '  FAIL  ') . $name . "\n";
    $fail = $ok ? $fail : 1;
}

// Returns [column, html] for the given settings file.
function shell(string $ini): array {
    global $root, $base, $mytiles;
    $mytiles = [];
    file_put_contents("$root/boot/config/plugins/vastai/vastai.cfg", $ini . "\n");
    include "$base/include/VastAI.php";
    return [array_key_first($mytiles['vastai']), implode('', $mytiles['vastai'])];
}

[$col, $html] = shell('');
check('with nothing saved the defaults apply', $col === 'column1' && str_contains($html, 'data-interval="30"'));
check('the tile starts on the loading message', str_contains($html, 'Loading Vast.ai status'));
check('the script and the stylesheet are linked, not inlined',
    str_contains($html, '<script src="/plugins/vastai/javascript/vastai.js') && str_contains($html, 'href="/plugins/vastai/styles/vastai.css')
    && !str_contains($html, '<style>'));
check('the title is the constant the dashboard keys its layout on', str_contains($html, '<tbody title="VAST.AI"'));

// The shell with default settings, pinned. preview/index.html loads this file,
// so the preview always shows the markup the plugin really registers.
$fixture = __DIR__ . '/fixtures/shell.html';
if (in_array('--update-fixture', $argv, true)) {
    file_put_contents($fixture, $html);
}
check('the shell matches tests/fixtures/shell.html', @file_get_contents($fixture) === $html);

[$col, $html] = shell('INTERVAL="0"');
check('Off reaches the browser as 0 and is the selected option',
    str_contains($html, 'data-interval="0"') && str_contains($html, '<option value="0" selected>'));
[$col, $html] = shell('INTERVAL="60"');
check('a saved interval is selected', str_contains($html, 'data-interval="60"') && str_contains($html, '<option value="60" selected>'));
foreach (['5', '45', 'abc', '-1'] as $bad) {
    [$col, $html] = shell("INTERVAL=\"$bad\"");
    check("an interval of $bad, which Settings does not offer, becomes 30",
        str_contains($html, 'data-interval="30"') && str_contains($html, '<option value="30" selected>'));
}

[$col] = shell('COLUMN="column9"');
check('an unknown column falls back to column1', $col === 'column1');
[$col] = shell('COLUMN="column3"');
check('a column saved by an earlier version is kept', $col === 'column3');

file_put_contents("$root/boot/config/plugins/vastai/vastai.cfg", "API_KEY=\"a\"b\"\nINTERVAL=\"60\"\n");
$cfg = vast_config($root);
check('a settings file that does not parse leaves the defaults', $cfg['api_key'] === '' && $cfg['interval'] === 30);
file_put_contents("$root/boot/config/plugins/vastai/vastai.cfg", "API_KEY=\"  k  \"\nCACHE_TTL=\"120\"\nSHOW_BALANCE=\"0\"\n");
$cfg = vast_config($root);
check('saved values are read and the key is trimmed', $cfg['api_key'] === 'k' && $cfg['cache_ttl'] === 120 && $cfg['show_balance'] === false);

exec('rm -rf ' . escapeshellarg($root));
echo $fail ? "shell.test.php: FAILED\n" : "shell.test.php: ok\n";
exit($fail);

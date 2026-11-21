<?php
// Prepended to the endpoint by endpoint.test.php (php -d auto_prepend_file=...).
// Supplies the request and a canned Vast.ai, and logs every call made to it.

$_GET     = json_decode(getenv('T_GET') ?: '[]', true);
$_POST    = json_decode(getenv('T_POST') ?: '[]', true);
$_REQUEST = $_GET + $_POST;

function vast_api_get(string $url, string $api_key, int $timeout = 9): array {
    $file   = getenv('T_UPSTREAM');
    $script = json_decode(file_get_contents($file), true);
    $which  = str_contains($url, '/users/') ? 'user' : 'machines';
    file_put_contents("$file.log", "$which $api_key $url\n", FILE_APPEND | LOCK_EX);
    usleep(($script['delay_ms'] ?? 0) * 1000);
    [$code, $body, $errno, $error] = $script[$which] + [2 => 0, 3 => ''];
    return [
        'code'     => $code,
        'errno'    => $errno,
        'error'    => $error,
        // {KEY} lets a test see which key produced an answer.
        'response' => $errno ? false : str_replace('{KEY}', $api_key, $body),
    ];
}

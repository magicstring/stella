<?php
// Streams an uploaded file from outside the web root. Supports Range requests so videos play in Safari.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

$path = Media::path((string)($_GET['f'] ?? ''));
if ($path === null) {
    http_response_code(404);
    exit('File not found.');
}

$size = filesize($path);
$start = 0;
$end = $size - 1;

header('Content-Type: ' . Media::mime($path));
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') $start = (int)$m[1];
    if ($m[2] !== '') $end = min((int)$m[2], $size - 1);
    if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); $end = $size - 1; }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

header('Content-Length: ' . ($end - $start + 1));
session_write_close();

$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(8192, $left));
    echo $chunk;
    $left -= strlen($chunk);
    flush();
}
fclose($fh);

<?php
// Setup check. Delete this file before the demo.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Claude.php';

function row(string $label, bool $ok, string $detail): void {
    $color = $ok ? 'green' : 'red';
    echo "<p><strong>$label:</strong> <span style='color:$color'>" . ($ok ? 'OK' : 'FAIL') . '</span> '
       . htmlspecialchars($detail) . '</p>';
}
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>SailePost setup check</title></head>
<body style="font-family:sans-serif;max-width:680px;margin:40px auto;padding:0 16px">
<h1>SailePost setup check</h1>
<?php
row('PHP version', version_compare(PHP_VERSION, '8.0', '>='), PHP_VERSION);
row('cURL', function_exists('curl_init'), function_exists('curl_init') ? 'enabled' : 'missing');
row('fileinfo', class_exists('finfo'), class_exists('finfo') ? 'enabled' : 'missing (enable it in hPanel > PHP extensions)');
row('mbstring', function_exists('mb_strlen'), function_exists('mb_strlen') ? 'enabled' : 'missing');
row('Upload limit', true, 'upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size'));

$dirOk = is_dir(UPLOAD_DIR) ? is_writable(UPLOAD_DIR) : @mkdir(UPLOAD_DIR, 0750, true);
row('Upload folder', (bool)$dirOk, UPLOAD_DIR);

try {
    $count = db()->query('SELECT COUNT(*) FROM platforms')->fetchColumn();
    $words = db()->query('SELECT COUNT(*) FROM banned_words')->fetchColumn();
    db()->query('SELECT fixes, verdict FROM checks LIMIT 1');
    db()->query('SELECT 1 FROM scheduled_posts LIMIT 1');
    row('Database', $count > 0, "connected; $count platforms, $words risky words" . ($count ? '' : ' (import seed.sql)'));
} catch (Throwable $e) {
    row('Database', false, $e->getMessage() . ' (import schema.sql, or upgrade_m1.sql if you imported the old schema)');
}

try {
    $reply = Claude::ask('You are a test endpoint.', 'Reply with exactly: Pre-flight ready.', 50);
    row('Claude API', true, $reply);
} catch (Throwable $e) {
    row('Claude API', false, $e->getMessage());
}

row('Facebook', true, (FB_PAGE_ID !== '' && FB_PAGE_TOKEN !== '') ? 'configured' : 'not configured; every platform will be simulated');
row('Sign-in', APP_PASSWORD !== 'change-me', APP_PASSWORD === 'change-me' ? 'change APP_PASSWORD in config.php' : 'set');
?>
</body></html>

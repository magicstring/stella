<?php
// Sends scheduled posts that are due.
// Hostinger cron (every 5 minutes):  php /home/USER/public_html/run_scheduled.php
// Or over the web:                   https://your-site/run_scheduled.php?key=CRON_KEY
require_once __DIR__ . '/lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    if (CRON_KEY === '' || !hash_equals(CRON_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain');
}

$sent = Publisher::runDue(50);
$refreshed = Insights::refreshDueFacebook(10);
echo date('Y-m-d H:i:s') . " sent $sent post(s), refreshed numbers for $refreshed Facebook post(s)\n";

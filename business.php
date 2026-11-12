<?php
// POST: switch the active business, then go back to the page you were on.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();
require_post();

$id = (int)($_POST['business_id'] ?? 0);
if (switch_business($id)) {
    flash('Switched to ' . (current_business()['name'] ?? 'that business') . '.');
} else {
    flash("That business doesn't exist anymore.", 'error');
}

// Return to the same page (same site only); pages tied to one record go back to the overview
$back = 'dashboard.php';
$ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''));
if (($ref['host'] ?? '') === ($_SERVER['HTTP_HOST'] ?? '') && !empty($ref['path'])) {
    $page = basename($ref['path']);
    $safe = ['dashboard.php', 'index.php', 'suggest.php', 'scout.php', 'trends.php', 'help.php', 'calendar.php', 'history.php', 'accounts.php', 'profile.php'];
    if (in_array($page, $safe, true)) $back = $page;
}
redirect($back);

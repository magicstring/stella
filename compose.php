<?php
// JSON endpoint for Create New Post: schedule or post right away (no check required), or suggest a posting time.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();
require_post();

try {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'suggest_time') {
        $platform = platform_by_id((int)($_POST['platform_id'] ?? 0)) ?? (platforms()[0] ?? null);
        $date = (string)($_POST['date'] ?? '');
        if (!$platform || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_out(['error' => 'Pick a platform and a date first.'], 422);
        $raw = Claude::askJson(Prompts::TIME, Prompts::timeInput($platform, $date, (string)($_POST['caption'] ?? ''), current_business()), 300);
        $time = (string)($raw['time'] ?? '');
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $time)) json_out(['error' => "The AI didn't return a usable time. Try again."], 502);
        json_out(['time' => str_pad($time, 5, '0', STR_PAD_LEFT), 'why' => (string)($raw['why'] ?? '')]);
    }

    if ($action !== 'schedule') json_out(['error' => 'Unknown action.'], 400);

    $platforms = array_values(array_filter(array_map(fn($id) => platform_by_id((int)$id), (array)($_POST['platform_ids'] ?? []))));
    if (!$platforms) json_out(['error' => 'Pick at least one platform.'], 422);
    $notConnected = array_values(array_filter($platforms, fn($p) => !isset(accounts()[(int)$p['id']])));
    if ($notConnected) {
        json_out(['error' => 'Connect ' . implode(' and ', array_column($notConnected, 'name')) . ' on the Channels page first, or untick ' . (count($notConnected) > 1 ? 'them' : 'it') . '.'], 422);
    }

    $caption = trim((string)($_POST['caption'] ?? ''));
    if (mb_strlen($caption) > 10000) json_out(['error' => 'That caption is too long.'], 422);

    $file = null;
    if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $file = Media::store($_FILES['file']);
    } elseif (!empty($_POST['media_file']) && Media::path((string)$_POST['media_file'])) {
        $file = (string)$_POST['media_file'];
    }
    if ($caption === '' && $file === null) json_out(['error' => 'Write a caption or add an image or video.'], 422);

    $now = ($_POST['when'] ?? 'now') !== 'later';
    if ($now) {
        $when = date('Y-m-d H:i:s');
    } else {
        $date = (string)($_POST['date'] ?? '');
        $time = (string)($_POST['time'] ?? '');
        $ts = strtotime("$date $time");
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) || !$ts) {
            json_out(['error' => 'Pick a date and a time.'], 422);
        }
        if ($ts < time() - 60) json_out(['error' => 'That date and time has already passed.'], 422);
        $when = date('Y-m-d H:i:s', $ts);
    }

    // The pre-flight check is optional, so these posts are queued without one
    foreach ($platforms as $p) Publisher::queue(null, $p, $caption, $file, $when);
    if ($now) Publisher::runDue(20);

    $names = implode(', ', array_column($platforms, 'name'));
    json_out([
        'ok'      => true,
        'message' => $now ? "Sent to $names." : "Scheduled for $names on " . date('D, M j \a\t g:i A', strtotime($when)) . '.',
        'month'   => date('Y-m', strtotime($when)),
    ]);
} catch (Throwable $e) {
    json_out(['error' => user_error_message($e)], 400);
}

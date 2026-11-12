<?php
// JSON endpoint for the calendar planner: generate drafts for chosen dates, edit them, and check + schedule them.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();
require_post();

const PLAN_MAX_DATES = 7;

function drafts_ready(): bool {
    try { db()->query('SELECT 1 FROM content_drafts LIMIT 1'); return true; } catch (PDOException $e) { return false; }
}

function find_draft(int $id): ?array {
    $st = db()->prepare('SELECT d.*, p.name AS platform FROM content_drafts d JOIN platforms p ON p.id = d.platform_id WHERE d.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function draft_payload(array $d): array {
    return [
        'id'           => (int)$d['id'],
        'date'         => $d['post_date'],
        'date_label'   => date('D, M j', strtotime($d['post_date'])),
        'time'         => substr((string)$d['post_time'], 0, 5),
        'platform'     => $d['platform'],
        'platform_id'  => (int)$d['platform_id'],
        'media_type'   => $d['media_type'],
        'hook'         => (string)$d['hook'],
        'caption'      => (string)$d['caption'],
        'hashtags'     => (string)$d['hashtags'],
        'format'       => (string)$d['format'],
        'visual'       => (string)$d['visual'],
        'shots'        => array_values(array_filter(array_map('trim', explode('|', (string)$d['shots'])))),
        'image_prompt' => (string)$d['image_prompt'],
        'file'         => $d['file_path'],
        'url'          => $d['file_path'] && Media::path($d['file_path']) ? 'media.php?f=' . rawurlencode($d['file_path']) : null,
        'status'       => $d['status'],
        'check_id'     => $d['check_id'] ? (int)$d['check_id'] : null,
    ];
}

function full_caption(array $d): string {
    return trim($d['caption'] . ($d['hashtags'] !== '' ? "\n\n" . $d['hashtags'] : ''));
}

try {
    if (!drafts_ready()) {
        json_out(['error' => 'Import planner.sql in phpMyAdmin to turn on the planner.'], 422);
    }
    $action = (string)($_POST['action'] ?? '');

    // ---------- Generate drafts for the chosen dates ----------
    if ($action === 'generate') {
        $today = date('Y-m-d');
        $dates = [];
        foreach ((array)($_POST['dates'] ?? []) as $d) {
            $d = (string)$d;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) && $d >= $today) $dates[$d] = true;
        }
        $dates = array_keys($dates);
        sort($dates);
        if (!$dates) json_out(['error' => 'Pick at least one date from today onward.'], 422);
        if (count($dates) > PLAN_MAX_DATES) json_out(['error' => 'Pick up to ' . PLAN_MAX_DATES . ' dates at a time.'], 422);

        $platforms = array_values(array_filter(array_map(fn($id) => platform_by_id((int)$id), (array)($_POST['platform_ids'] ?? []))));
        if (!$platforms) json_out(['error' => 'Pick at least one platform.'], 422);
        $media = in_array($_POST['media'] ?? '', ['none', 'image', 'video'], true) ? $_POST['media'] : 'none';
        $language = in_array($_POST['language'] ?? '', Prompts::LANGUAGES, true) ? $_POST['language'] : 'Taglish';
        $theme = mb_substr(trim((string)($_POST['theme'] ?? '')), 0, 500);
        // Times the user set themselves; dates without one get the AI's suggested time
        $manualTimes = [];
        foreach ((array)($_POST['times'] ?? []) as $d => $t) {
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$t)) $manualTimes[(string)$d] = $t . ':00';
        }

        $raw = Claude::askJson(
            Prompts::PLAN,
            Prompts::planInput($dates, $platforms, $media, $language, $theme, current_business(), Trends::top(null, 20)),
            min(8000, 1200 + 900 * count($dates))
        );
        $posts = is_array($raw['posts'] ?? null) ? $raw['posts'] : [];

        // Match each post to a date (by its date, else by position)
        $byDate = [];
        foreach ($posts as $i => $p) {
            if (!is_array($p)) continue;
            $d = (string)($p['date'] ?? '');
            $key = in_array($d, $dates, true) && !isset($byDate[$d]) ? $d : ($dates[$i] ?? null);
            if ($key !== null && !isset($byDate[$key])) $byDate[$key] = $p;
        }
        if (!$byDate) json_out(['error' => "The AI didn't return a usable plan. Try again."], 502);

        $batch = bin2hex(random_bytes(8));
        $ins = db()->prepare('INSERT INTO content_drafts (batch, post_date, post_time, platform_id, media_type, hook, caption, hashtags, format, visual, image_prompt, shots)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $setBiz = multi_business_ready() ? db()->prepare('UPDATE content_drafts SET business_id = ? WHERE id = ?') : null;
        $out = [];
        foreach ($dates as $d) {
            if (!isset($byDate[$d])) continue;
            $p = $byDate[$d];
            $platform = platform_by_name((string)($p['platform'] ?? ''));
            if (!$platform || !in_array((int)$platform['id'], array_map(fn($x) => (int)$x['id'], $platforms), true)) $platform = $platforms[0];
            $time = $manualTimes[$d] ?? (preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', (string)($p['time'] ?? '')) ? $p['time'] . ':00' : '12:00:00');
            $tags = array_values(array_filter(array_map(fn($t) => '#' . ltrim(trim((string)$t), '#'), is_array($p['hashtags'] ?? null) ? $p['hashtags'] : []), fn($t) => $t !== '#'));
            $ins->execute([
                $batch, $d, $time, $platform['id'], $media,
                mb_substr((string)($p['hook'] ?? ''), 0, 255),
                trim((string)($p['caption'] ?? '')),
                mb_substr(implode(' ', $tags), 0, 500),
                mb_substr((string)($p['format'] ?? ''), 0, 60),
                (string)($p['visual'] ?? ''),
                $media === 'none' ? '' : (string)($p['image_prompt'] ?? ''),
                $media === 'video' ? (string)($p['shots'] ?? '') : '',
            ]);
            $newId = (int)db()->lastInsertId();
            if ($setBiz) $setBiz->execute([current_business_id(), $newId]);
            $out[] = draft_payload(find_draft($newId));
        }
        json_out(['drafts' => $out, 'image_on' => ImageGen::configured()]);
    }

    $draft = find_draft((int)($_POST['draft_id'] ?? 0));
    if (!$draft) json_out(['error' => "That draft doesn't exist anymore."], 404);

    switch ($action) {
        case 'get':
            json_out(['draft' => draft_payload($draft)]);

        case 'update':
            if ($draft['status'] !== 'draft') json_out(['error' => 'This draft is already scheduled.'], 422);
            $platform = platform_by_id((int)($_POST['platform_id'] ?? $draft['platform_id'])) ?? platform_by_id((int)$draft['platform_id']);
            $date = (string)($_POST['date'] ?? $draft['post_date']);
            $time = (string)($_POST['time'] ?? substr($draft['post_time'], 0, 5));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
                json_out(['error' => 'Use a valid date and time.'], 422);
            }
            $st = db()->prepare('UPDATE content_drafts SET caption = ?, hashtags = ?, post_date = ?, post_time = ?, platform_id = ? WHERE id = ?');
            $st->execute([
                trim((string)($_POST['caption'] ?? $draft['caption'])),
                mb_substr(trim((string)($_POST['hashtags'] ?? $draft['hashtags'])), 0, 500),
                $date, $time . ':00', $platform['id'], $draft['id'],
            ]);
            json_out(['draft' => draft_payload(find_draft((int)$draft['id']))]);

        case 'delete':
            db()->prepare("DELETE FROM content_drafts WHERE id = ? AND status = 'draft'")->execute([$draft['id']]);
            json_out(['ok' => true]);

        case 'schedule':
            if ($draft['status'] !== 'draft') json_out(['error' => 'This draft is already scheduled.'], 422);
            $platform = platform_by_id((int)$draft['platform_id']);
            if (!isset(accounts()[(int)$platform['id']])) {
                json_out(['error' => "Connect {$platform['name']} on the Channels page first."], 422);
            }
            $when = $draft['post_date'] . ' ' . $draft['post_time'];
            if (strtotime($when) < time()) {
                json_out(['error' => 'That date and time has passed. Move the draft to a later time first.'], 422);
            }
            $caption = full_caption($draft);
            $file = $draft['file_path'] && Media::path($draft['file_path']) ? $draft['file_path'] : null;

            // The pre-flight check is optional: schedule straight away unless the user asked for it
            if (empty($_POST['check'])) {
                Publisher::queue(null, $platform, $caption, $file, date('Y-m-d H:i:s', strtotime($when)), isset($draft['business_id']) ? (int)$draft['business_id'] : null);
                db()->prepare("UPDATE content_drafts SET status = 'scheduled' WHERE id = ?")->execute([$draft['id']]);
                json_out(['scheduled' => true, 'check_id' => null, 'score' => null, 'draft' => draft_payload(find_draft((int)$draft['id']))]);
            }

            // Every planned post goes through the same pre-flight check as a hand-written one
            $checkId = Preflight::run($platform, $caption, $file, [], current_business());
            $check = find_check($checkId);
            db()->prepare('UPDATE content_drafts SET check_id = ? WHERE id = ?')->execute([$checkId, $draft['id']]);

            if ($check['verdict'] === 'hold' && empty($_POST['force'])) {
                json_out(['held' => true, 'check_id' => $checkId, 'score' => (int)$check['score'], 'draft' => draft_payload(find_draft((int)$draft['id']))]);
            }
            Publisher::queue($checkId, $platform, $caption, $file, date('Y-m-d H:i:s', strtotime($when)));
            db()->prepare("UPDATE content_drafts SET status = 'scheduled' WHERE id = ?")->execute([$draft['id']]);
            json_out(['scheduled' => true, 'check_id' => $checkId, 'score' => (int)$check['score'], 'verdict' => $check['verdict'], 'draft' => draft_payload(find_draft((int)$draft['id']))]);

        default:
            json_out(['error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    json_out(['error' => user_error_message($e)], 400);
}

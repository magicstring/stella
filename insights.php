<?php
// JSON endpoint for the calendar drawer: load a post's numbers, add numbers, refresh from Facebook, dismiss alerts.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();
require_post();

function post_payload(int $id): array {
    $post = Insights::post($id);
    if (!$post) json_out(['error' => "That post doesn't exist anymore."], 404);

    $latestAll = Insights::latestAll();
    $hist = Insights::history($id);
    $status = Insights::status($id, $latestAll);
    $latest = $latestAll[$id] ?? null;
    $acc = accounts()[(int)$post['platform_id']] ?? null;
    $eng = $latest ? Insights::engagement($latest) : null;
    $base = Insights::baseline($id, $latestAll);

    // Pre-flight score next to real performance
    $versus = null;
    if ($latest && $post['score'] !== null) {
        $versus = 'Pre-flight score ' . (int)$post['score'] . ' · '
            . ($status['ratio'] !== null
                ? number_format($status['ratio'], 1) . '× your usual engagement'
                : 'needs ' . Insights::MIN_BASELINE_POSTS . ' posts with numbers to compare');
    }

    return [
        'id'          => (int)$post['id'],
        'check_id'    => (int)$post['check_id'],
        'platform'    => $post['platform'],
        'handle'      => $acc['handle'] ?? $post['platform'],
        'live'        => ($acc['mode'] ?? '') === 'live',
        'caption'     => (string)$post['caption'],
        'status'      => $post['status'],
        'when'        => nice_time($post['posted_at'] ?: $post['scheduled_at']),
        'score'       => $post['score'] !== null ? (int)$post['score'] : null,
        'verdict'     => $post['verdict'],
        'media'       => $post['file_path'] && Media::path($post['file_path']) ? 'media.php?f=' . rawurlencode($post['file_path']) : null,
        'is_video'    => (bool)preg_match('/\.(mp4|mov|webm)$/', (string)$post['file_path']),
        'can_refresh' => $post['status'] === 'posted' && strcasecmp($post['platform'], 'Facebook') === 0 && $post['external_id'],
        'sent'        => in_array($post['status'], ['posted', 'simulated'], true),
        'latest'      => $latest ? array_map('intval', array_intersect_key($latest, array_flip(['views', 'likes', 'comments', 'shares']))) + ['source' => $latest['source'], 'at' => nice_time($latest['captured_at'])] : null,
        'engagement'  => $eng,
        'baseline'    => $base !== null ? (int)round($base) : null,
        'state'       => $status,
        'versus'      => $versus,
        'history'     => array_map(fn($h) => ['at' => date('M j, g:i A', strtotime($h['captured_at'])), 'engagement' => Insights::engagement($h), 'source' => $h['source']], $hist),
    ];
}

try {
    if (!Insights::ready()) {
        json_out(['error' => 'Import insights.sql in phpMyAdmin to turn on post insights.'], 422);
    }
    $action = (string)($_POST['action'] ?? 'get');
    $id = (int)($_POST['post_id'] ?? 0);
    $alerts = [];

    switch ($action) {
        case 'get':
            break;
        case 'manual':
            $m = [];
            foreach (['views', 'likes', 'comments', 'shares'] as $k) {
                $v = trim((string)($_POST[$k] ?? ''));
                if ($v !== '' && !ctype_digit($v)) json_out(['error' => 'Use whole numbers only.'], 422);
                $m[$k] = (int)$v;
            }
            if (!array_sum($m)) json_out(['error' => 'Enter at least one number.'], 422);
            if (!Insights::post($id)) json_out(['error' => "That post doesn't exist anymore."], 404);
            $alerts = Insights::save($id, 'manual', $m);
            break;
        case 'sample':
        case 'spike':
            if (!Insights::post($id)) json_out(['error' => "That post doesn't exist anymore."], 404);
            $alerts = Insights::sample($id, $action === 'spike');
            break;
        case 'refresh':
            $alerts = Insights::refreshFacebook($id);
            break;
        case 'dismiss':
            $alertId = (int)($_POST['alert_id'] ?? 0);
            Insights::markSeen($alertId ?: null);
            json_out(['ok' => true, 'unseen' => Insights::unseenCount()]);
        default:
            json_out(['error' => 'Unknown action.'], 400);
    }

    json_out(['post' => post_payload($id), 'alerts' => $alerts, 'unseen' => Insights::unseenCount()]);
} catch (Throwable $e) {
    json_out(['error' => user_error_message($e)], 400);
}

<?php
// POST check_id: rewrite the caption for every platform. Saves to checks.fixes and returns JSON {versions}.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();
require_post();

try {
    $check = find_check((int)($_POST['check_id'] ?? 0));
    if (!$check) {
        json_out(['error' => "That check doesn't exist."], 404);
    }

    $ruleData = decode_json($check['rule_results']);
    $aiRaw = decode_json($check['ai_results']);
    $ai = isset($aiRaw['overall_score']) ? $aiRaw : null;
    $caption = (string)$check['caption'];

    $issues = Preflight::issueList($ruleData['checks'] ?? [], $ai, $caption);
    $disclosure = $ai ? (bool)$ai['disclosure_needed'] : false;

    // The checked platform first, then the rest
    $all = platforms();
    usort($all, fn($a, $b) => ((int)$b['id'] === (int)$check['platform_id']) <=> ((int)$a['id'] === (int)$check['platform_id']));

    $raw = Claude::askJson(Prompts::FIX, Prompts::fixInput($caption, $issues, $disclosure, $all, current_business()), 3000);

    $versions = [];
    foreach ($raw['versions'] ?? [] as $v) {
        $p = platform_by_name((string)($v['platform'] ?? ''));
        if (!$p) continue;
        $tags = array_values(array_filter(array_map(
            fn($t) => '#' . ltrim(trim((string)$t), '#'),
            is_array($v['hashtags'] ?? null) ? $v['hashtags'] : []
        ), fn($t) => $t !== '#'));
        $versions[] = [
            'platform' => $p['name'],
            'caption'  => trim((string)($v['caption'] ?? '')),
            'hashtags' => $tags,
            'changes'  => array_values(array_map('strval', is_array($v['changes'] ?? null) ? $v['changes'] : [])),
        ];
    }
    if (!$versions) {
        json_out(['error' => "Claude didn't return any usable captions. Try again."], 502);
    }

    $st = db()->prepare('UPDATE checks SET fixes = ? WHERE id = ?');
    $st->execute([json_encode(['versions' => $versions], JSON_UNESCAPED_UNICODE), $check['id']]);

    json_out(['versions' => $versions]);
} catch (Throwable $e) {
    json_out(['error' => user_error_message($e)], 400);
}

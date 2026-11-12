<?php
// Runs a full pre-flight check: media analysis, rule checks, Claude's review, score, verdict. Saves to `checks`.

class Preflight {
    public static function run(array $platform, string $caption, ?string $mediaFile, array $client, ?array $business): int {
        $media = $mediaFile ? Media::analyze($mediaFile, $client) : null;
        $rules = Rules::run($platform, $caption, $media);
        $ruleScore = Rules::score($rules);
        $trends = Trends::top($platform['name'], 15);

        $ai = null;
        $aiError = null;
        try {
            $raw = Claude::askJson(Prompts::PREFLIGHT, Prompts::preflightInput($platform, $caption, $media, $rules, $trends, $business), 2500);
            $ai = self::normalizeAi($raw);
        } catch (Throwable $e) {
            $aiError = $e->getMessage();
            error_log('Stella Claude error: ' . $aiError);
        }

        $score = $ai ? (int)round($ruleScore * 0.4 + $ai['overall_score'] * 0.6) : $ruleScore;
        $verdict = self::verdict($rules, $ai, $score, $caption)['key'];

        $st = db()->prepare('INSERT INTO checks (platform_id, business_id, caption, file_path, rule_results, ai_results, score, verdict)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([
            $platform['id'],
            $business['id'] ?? null,
            $caption,
            $mediaFile,
            json_encode(['checks' => $rules, 'rule_score' => $ruleScore, 'media' => $media], JSON_UNESCAPED_UNICODE),
            json_encode($ai ?? ['error' => $aiError], JSON_UNESCAPED_UNICODE),
            $score,
            $verdict,
        ]);
        return (int)db()->lastInsertId();
    }

    public static function hasDisclosure(string $caption): bool {
        return (bool)preg_match('/(#ad\b|#sponsored|#partner|paid partnership|sponsored)/iu', $caption);
    }

    // ready = post it; fix = warnings to look at; hold = something that could get it flagged or rejected.
    public static function verdict(array $rules, ?array $ai, int $score, string $caption): array {
        $counts = Rules::counts($rules);
        $ph = $ai['ph_compliance']['level'] ?? 'low';
        $high = $ai && ($ai['policy_risk']['level'] === 'high' || $ai['brand_safety']['level'] === 'high' || $ph === 'high');
        $medium = $ai && ($ai['policy_risk']['level'] === 'medium' || $ai['brand_safety']['level'] === 'medium' || $ph === 'medium');
        $missingAd = $ai && $ai['disclosure_needed'] && !self::hasDisclosure($caption);

        if ($counts['fail'] > 0 || $high || $score < 50) {
            return ['key' => 'hold', 'label' => "Don't post yet", 'note' => 'Something here could get the post rejected, flagged or taken down. Fix the red items first.'];
        }
        if ($counts['warn'] > 0 || $medium || $missingAd || $score < 80) {
            return ['key' => 'fix', 'label' => 'Fix before posting', 'note' => "Nothing blocking, but a few fixes would make this post safer and stronger."];
        }
        return ['key' => 'ready', 'label' => 'Ready to post', 'note' => 'No problems found. This post is clear to publish.'];
    }

    // Collect every issue as text, for the fix prompt.
    public static function issueList(array $rules, ?array $ai, string $caption): array {
        $issues = [];
        foreach ($rules as $r) {
            if ($r['status'] !== 'pass') $issues[] = $r['name'] . ': ' . $r['message'];
        }
        if ($ai) {
            foreach (['policy_risk', 'brand_safety', 'ph_compliance'] as $k) {
                foreach ($ai[$k]['issues'] ?? [] as $i) $issues[] = $i['issue'] . (!empty($i['rule']) ? ' (' . $i['rule'] . ')' : '');
            }
            if ($ai['disclosure_needed'] && !self::hasDisclosure($caption)) $issues[] = 'Sponsored post without #ad disclosure.';
            if ($ai['hook_quality']['score'] < 60 && $ai['hook_quality']['note'] !== '') $issues[] = 'Weak hook: ' . $ai['hook_quality']['note'];
        }
        return $issues;
    }

    private static function phIssues($list): array {
        $out = [];
        foreach (is_array($list) ? $list : [] as $i) {
            if (is_array($i) && trim((string)($i['issue'] ?? '')) !== '') {
                $out[] = ['issue' => trim((string)$i['issue']), 'rule' => trim((string)($i['rule'] ?? '')), 'fix' => trim((string)($i['fix'] ?? ''))];
            }
        }
        return $out;
    }

    public static function normalizeAi(array $raw): array {
        $level = fn($v) => in_array($v, ['low', 'medium', 'high'], true) ? $v : 'medium';
        $issues = function ($list): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $i) {
                if (is_array($i) && trim((string)($i['issue'] ?? '')) !== '') {
                    $out[] = ['issue' => trim((string)$i['issue']), 'fix' => trim((string)($i['fix'] ?? ''))];
                }
            }
            return $out;
        };
        $num = fn($v) => max(0, min(100, (int)$v));

        return [
            'policy_risk'       => ['level' => $level($raw['policy_risk']['level'] ?? null), 'issues' => $issues($raw['policy_risk']['issues'] ?? [])],
            'brand_safety'      => ['level' => $level($raw['brand_safety']['level'] ?? null), 'issues' => $issues($raw['brand_safety']['issues'] ?? [])],
            'ph_compliance'     => [
                'level'  => $level($raw['ph_compliance']['level'] ?? 'low'),
                'issues' => array_map(
                    fn($i) => $i + ['rule' => ''],
                    self::phIssues($raw['ph_compliance']['issues'] ?? [])
                ),
            ],
            'disclosure_needed' => (bool)($raw['disclosure_needed'] ?? false),
            'hook_quality'      => ['score' => $num($raw['hook_quality']['score'] ?? 0), 'note' => (string)($raw['hook_quality']['note'] ?? '')],
            'trend_fit'         => [
                'score'         => $num($raw['trend_fit']['score'] ?? 0),
                'matched_trend' => (string)($raw['trend_fit']['matched_trend'] ?? ''),
                'suggestion'    => (string)($raw['trend_fit']['suggestion'] ?? ''),
            ],
            'fixed_caption'     => (string)($raw['fixed_caption'] ?? ''),
            'overall_score'     => $num($raw['overall_score'] ?? 0),
        ];
    }
}

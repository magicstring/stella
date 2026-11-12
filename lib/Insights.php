<?php
// Post performance: snapshots of views, likes, comments and shares, plus "taking off" and "climbing" alerts.
// Engagement = likes + 2 x comments + 3 x shares (comments and shares signal more than a like).

class Insights {
    public const TRENDING_RATIO = 2.0;  // at least 2x the usual engagement
    public const CLIMB_GROWTH = 0.30;   // up 30% or more since the previous snapshot
    public const MIN_BASELINE_POSTS = 3;

    public static function ready(): bool {
        static $ok = null;
        if ($ok === null) {
            try {
                db()->query('SELECT 1 FROM post_insights LIMIT 1');
                db()->query('SELECT 1 FROM alerts LIMIT 1');
                $ok = true;
            } catch (PDOException $e) {
                $ok = false;
            }
        }
        return $ok;
    }

    public static function engagement(array $m): int {
        return (int)$m['likes'] + 2 * (int)$m['comments'] + 3 * (int)$m['shares'];
    }

    public static function history(int $postId): array {
        $st = db()->prepare('SELECT * FROM post_insights WHERE post_id = ? ORDER BY captured_at, id');
        $st->execute([$postId]);
        return $st->fetchAll();
    }

    // Latest snapshot for every post that has one, keyed by post_id
    public static function latestAll(): array {
        if (!self::ready()) return [];
        $rows = db()->query('SELECT pi.* FROM post_insights pi
                             JOIN (SELECT post_id, MAX(id) mid FROM post_insights GROUP BY post_id) x ON x.mid = pi.id
                             JOIN scheduled_posts sp ON sp.id = pi.post_id WHERE ' . biz_where('sp'))->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[(int)$r['post_id']] = $r;
        return $out;
    }

    // Average engagement of other posts; null until there are enough posts to compare against
    public static function baseline(int $excludePostId, ?array $latest = null): ?float {
        $latest ??= self::latestAll();
        $vals = [];
        foreach ($latest as $pid => $m) {
            if ($pid !== $excludePostId) $vals[] = self::engagement($m);
        }
        if (count($vals) < self::MIN_BASELINE_POSTS) return null;
        return array_sum($vals) / count($vals);
    }

    // Status of one post: taking off, climbing, steady, or no data
    public static function status(int $postId, ?array $latest = null): array {
        $latest ??= self::latestAll();
        if (!isset($latest[$postId])) return ['key' => 'none', 'label' => 'No numbers yet', 'ratio' => null, 'growth' => null];

        $eng = self::engagement($latest[$postId]);
        $base = self::baseline($postId, $latest);
        $ratio = $base && $base > 0 ? $eng / $base : null;

        $hist = self::history($postId);
        $growth = null;
        if (count($hist) >= 2) {
            $prev = self::engagement($hist[count($hist) - 2]);
            $growth = $prev > 0 ? ($eng - $prev) / $prev : null;
        }

        if ($ratio !== null && $ratio >= self::TRENDING_RATIO) return ['key' => 'trending', 'label' => 'Taking off', 'ratio' => $ratio, 'growth' => $growth];
        if ($growth !== null && $growth >= self::CLIMB_GROWTH) return ['key' => 'climbing', 'label' => 'Climbing', 'ratio' => $ratio, 'growth' => $growth];
        return ['key' => 'steady', 'label' => 'Steady', 'ratio' => $ratio, 'growth' => $growth];
    }

    // Save a snapshot and raise alerts if the post is now taking off or climbing. Returns new alerts.
    public static function save(int $postId, string $source, array $m): array {
        $st = db()->prepare('INSERT INTO post_insights (post_id, source, views, likes, comments, shares, captured_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$postId, $source, max(0, (int)$m['views']), max(0, (int)$m['likes']), max(0, (int)$m['comments']), max(0, (int)$m['shares']), date('Y-m-d H:i:s')]);
        return self::evaluate($postId);
    }

    private static function evaluate(int $postId): array {
        $status = self::status($postId);
        if (!in_array($status['key'], ['trending', 'climbing'], true)) return [];

        // One "taking off" alert per post; "climbing" at most once a day per post
        $sql = $status['key'] === 'trending'
            ? "SELECT 1 FROM alerts WHERE post_id = ? AND kind = 'trending'"
            : "SELECT 1 FROM alerts WHERE post_id = ? AND kind = 'climbing' AND created_at >= ?";
        $args = $status['key'] === 'trending' ? [$postId] : [$postId, date('Y-m-d H:i:s', strtotime('-1 day'))];
        $st = db()->prepare($sql);
        $st->execute($args);
        if ($st->fetchColumn()) return [];

        $post = self::post($postId);
        $where = $post ? $post['platform'] . ' post' : 'post';
        $message = $status['key'] === 'trending'
            ? "Your $where is taking off: " . number_format($status['ratio'], 1) . '× your usual engagement.'
            : "Your $where is climbing: engagement up " . round($status['growth'] * 100) . '% since the last update.';
        db()->prepare('INSERT INTO alerts (post_id, kind, message, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$postId, $status['key'], $message, date('Y-m-d H:i:s')]);
        return [['kind' => $status['key'], 'message' => $message]];
    }

    public static function post(int $postId): ?array {
        $st = db()->prepare('SELECT sp.*, p.name AS platform, c.score, c.verdict FROM scheduled_posts sp
                             JOIN platforms p ON p.id = sp.platform_id LEFT JOIN checks c ON c.id = sp.check_id WHERE sp.id = ?');
        $st->execute([$postId]);
        return $st->fetch() ?: null;
    }

    // Plausible demo numbers, always saved with source 'sample' and labelled in the app
    public static function sample(int $postId, bool $spike = false): array {
        $hist = self::history($postId);
        $last = $hist ? end($hist) : null;
        if ($last) {
            $f = $spike ? 3.2 : 1 + mt_rand(5, 25) / 100;
            $m = ['views' => (int)($last['views'] * $f), 'likes' => (int)($last['likes'] * $f), 'comments' => (int)($last['comments'] * $f), 'shares' => (int)($last['shares'] * $f) + ($spike ? 5 : 0)];
        } else {
            $views = mt_rand(400, 3000) * ($spike ? 3 : 1);
            $m = ['views' => $views, 'likes' => (int)($views * mt_rand(3, 8) / 100), 'comments' => (int)($views * mt_rand(3, 15) / 1000), 'shares' => (int)($views * mt_rand(2, 10) / 1000)];
        }
        return self::save($postId, 'sample', $m);
    }

    public static function refreshFacebook(int $postId): array {
        $post = self::post($postId);
        if (!$post || $post['status'] !== 'posted' || strcasecmp($post['platform'], 'Facebook') !== 0 || !$post['external_id']) {
            throw new RuntimeException('Only posts sent to Facebook for real can pull live numbers.');
        }
        return self::save($postId, 'facebook', Facebook::stats((string)$post['external_id']));
    }

    // Refresh live Facebook posts from the last 7 days that haven't been updated in an hour (for the cron job)
    public static function refreshDueFacebook(int $limit = 10): int {
        if (!self::ready() || !Facebook::configured()) return 0;
        $st = db()->prepare("SELECT sp.id FROM scheduled_posts sp JOIN platforms p ON p.id = sp.platform_id
                             WHERE p.name = 'Facebook' AND sp.status = 'posted' AND sp.external_id IS NOT NULL AND sp.posted_at >= ?
                             AND NOT EXISTS (SELECT 1 FROM post_insights pi WHERE pi.post_id = sp.id AND pi.captured_at >= ?)
                             ORDER BY sp.posted_at DESC LIMIT " . (int)$limit);
        $st->execute([date('Y-m-d H:i:s', strtotime('-7 days')), date('Y-m-d H:i:s', strtotime('-1 hour'))]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            try { self::refreshFacebook((int)$id); $n++; } catch (Throwable $e) { error_log('Stella insights: ' . $e->getMessage()); }
        }
        return $n;
    }

    public static function unseenAlerts(int $limit = 10): array {
        if (!self::ready()) return [];
        $st = db()->prepare('SELECT a.*, sp.check_id, sp.posted_at, p.name AS platform FROM alerts a
                             JOIN scheduled_posts sp ON sp.id = a.post_id JOIN platforms p ON p.id = sp.platform_id
                             WHERE a.seen_at IS NULL AND ' . biz_where('sp') . ' ORDER BY a.id DESC LIMIT ' . (int)$limit);
        $st->execute();
        return $st->fetchAll();
    }

    public static function unseenCount(): int {
        if (!self::ready()) return 0;
        return (int)db()->query('SELECT COUNT(*) FROM alerts a JOIN scheduled_posts sp ON sp.id = a.post_id WHERE a.seen_at IS NULL AND ' . biz_where('sp'))->fetchColumn();
    }

    public static function markSeen(?int $id = null): void {
        if ($id) {
            db()->prepare('UPDATE alerts SET seen_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $id]);
        } else {
            db()->prepare('UPDATE alerts a JOIN scheduled_posts sp ON sp.id = a.post_id SET a.seen_at = ? WHERE a.seen_at IS NULL AND ' . biz_where('sp'))->execute([date('Y-m-d H:i:s')]);
        }
    }
}

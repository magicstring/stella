<?php
// Queues posts and sends the ones that are due. Facebook posts for real when configured; other platforms are simulated.

class Publisher {
    // $checkId is null when the post skipped the (optional) pre-flight check
    public static function queue(?int $checkId, array $platform, string $caption, ?string $file, string $when, ?int $businessId = null): int {
        if (multi_business_ready()) {
            $st = db()->prepare('INSERT INTO scheduled_posts (check_id, business_id, platform_id, caption, file_path, status, scheduled_at)
                                 VALUES (?, COALESCE((SELECT business_id FROM checks WHERE id = ?), ?), ?, ?, ?, ?, ?)');
            $st->execute([$checkId, $checkId, $businessId ?? current_business_id(), $platform['id'], $caption, $file, 'scheduled', $when]);
        } else {
            $st = db()->prepare('INSERT INTO scheduled_posts (check_id, platform_id, caption, file_path, status, scheduled_at) VALUES (?, ?, ?, ?, ?, ?)');
            $st->execute([$checkId, $platform['id'], $caption, $file, 'scheduled', $when]);
        }
        return (int)db()->lastInsertId();
    }

    // Send every post that is due. Safe to call on any page load; each post is claimed before sending.
    public static function runDue(int $limit = 10): int {
        $st = db()->prepare("SELECT * FROM scheduled_posts WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at LIMIT ?");
        $st->bindValue(1, date('Y-m-d H:i:s'));
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        $sent = 0;
        foreach ($st->fetchAll() as $row) {
            $claim = db()->prepare("UPDATE scheduled_posts SET status = 'posting' WHERE id = ? AND status = 'scheduled'");
            $claim->execute([$row['id']]);
            if ($claim->rowCount() === 1) {
                self::send($row);
                $sent++;
            }
        }
        return $sent;
    }

    private static function send(array $row): void {
        $platform = platform_by_id((int)$row['platform_id']);
        $status = 'simulated';
        $externalId = 'demo-' . bin2hex(random_bytes(4));
        $error = null;

        if ($platform && strcasecmp($platform['name'], 'Facebook') === 0 && Facebook::configured()) {
            try {
                $path = $row['file_path'] ? Media::path($row['file_path']) : null;
                $externalId = Facebook::post((string)$row['caption'], $path, $path ? Media::mime($path) : null);
                $status = 'posted';
            } catch (Throwable $e) {
                $status = 'failed';
                $externalId = null;
                $error = mb_substr($e->getMessage(), 0, 480);
            }
        }

        $st = db()->prepare('UPDATE scheduled_posts SET status = ?, posted_at = ?, external_id = ?, error = ? WHERE id = ?');
        $st->execute([$status, date('Y-m-d H:i:s'), $externalId, $error, $row['id']]);
    }
}

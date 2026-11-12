<?php
class Trends {
    public const TYPES = ['sound', 'format', 'hashtag', 'product', 'angle'];

    // Top trends for a platform (or all platforms when $platform is null), fastest-rising first.
    public static function top(?string $platform, int $limit = 15): array {
        if ($platform === null) {
            $st = db()->prepare('SELECT type, name, platform, velocity, note, seen_at FROM trends ORDER BY velocity DESC, seen_at DESC LIMIT ?');
            $st->bindValue(1, $limit, PDO::PARAM_INT);
        } else {
            $st = db()->prepare("SELECT type, name, platform, velocity, note, seen_at FROM trends
                                 WHERE platform IS NULL OR platform = '' OR platform = ?
                                 ORDER BY velocity DESC, seen_at DESC LIMIT ?");
            $st->bindValue(1, $platform);
            $st->bindValue(2, $limit, PDO::PARAM_INT);
        }
        $st->execute();
        return $st->fetchAll();
    }
}

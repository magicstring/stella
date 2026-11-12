<?php
// Plain-code checks against each platform's limits. Every item: name, status (pass|warn|fail), message, fix.

class Rules {
    public static function run(array $platform, string $caption, ?array $media): array {
        $checks = [];
        $name = $platform['name'];

        // Caption length
        $len = mb_strlen($caption);
        $max = (int)$platform['caption_max'];
        if ($len === 0) {
            $checks[] = self::item('Caption', 'warn', 'The caption is empty.', 'Add a caption with a strong first line.');
        } elseif ($max > 0 && $len > $max) {
            $checks[] = self::item('Caption length', 'fail', "The caption is $len characters; $name allows $max.", 'Cut ' . ($len - $max) . ' characters, or use Fix it for me.');
        } else {
            $checks[] = self::item('Caption length', 'pass', "$len of $max characters.", '');
        }

        // Hashtags
        $tags = self::hashtags($caption);
        $count = count($tags);
        $hmax = (int)$platform['hashtag_max'];
        if ($hmax > 0 && $count > $hmax) {
            $status = strcasecmp($name, 'Instagram') === 0 ? 'fail' : 'warn';
            $checks[] = self::item('Hashtags', $status, "$count hashtags; keep it to $hmax or fewer on $name.", 'Keep the ' . min($hmax, 5) . ' most relevant hashtags.');
        } elseif ($count === 0 && $len > 0) {
            $checks[] = self::item('Hashtags', 'warn', 'No hashtags.', 'Add 3 to 5 relevant hashtags so the post can be found.');
        } elseif ($count > 0) {
            $checks[] = self::item('Hashtags', 'pass', "$count hashtags.", '');
        }

        // Risky words
        $found = 0;
        foreach (self::bannedWords() as $w) {
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($w['word'], '/') . '(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $caption)) {
                $found++;
                $checks[] = self::item('Risky word: "' . $w['word'] . '"', $w['severity'], (string)$w['reason'], 'Remove or rephrase "' . $w['word'] . '".');
            }
        }
        if ($found === 0 && $len > 0) {
            $checks[] = self::item('Risky words', 'pass', 'No risky words found.', '');
        }

        // Philippine rules that plain text can catch
        $promo = '/(?<![\p{L}])(giveaway|give away|raffle|pa-?raffle|contest|papremyo|chance to win|win an?|lucky winner|promo winner)(?![\p{L}])/iu';
        $permit = '/(dti|fda)[^\n]{0,40}(permit|fteb|spd)|permit\s*no/iu';
        if (preg_match($promo, $caption) && !preg_match($permit, $caption)) {
            $checks[] = self::item('PH: promo permit', 'warn', 'Prize promos, raffles and contests usually need a DTI permit (FDA for food, drugs and cosmetics), shown in the post.', 'Add your permit line, e.g. "Per DTI Fair Trade Permit No. ___".');
        }
        if (preg_match('/food supplement|dietary supplement|supplement/iu', $caption) && !preg_match('/no approved therapeutic claims/iu', $caption)) {
            $checks[] = self::item('PH: supplement notice', 'warn', 'Food supplement ads in the Philippines must say "No approved therapeutic claims".', 'Add "No approved therapeutic claims." to the caption.');
        }

        // Media
        if ($media === null) {
            $checks[] = self::item('Visual', 'warn', 'No image or video attached.', 'Attach the visual so its size and shape can be checked.');
            return $checks;
        }

        $maxMb = (int)$platform['max_file_mb'];
        if ($maxMb > 0 && $media['size_mb'] > $maxMb) {
            $checks[] = self::item('File size', 'fail', "{$media['size_mb']} MB; the limit here is $maxMb MB.", 'Compress or shorten the file.');
        } else {
            $checks[] = self::item('File size', 'pass', "{$media['size_mb']} MB.", '');
        }

        $target = Media::ratioValue((string)$platform['aspect_ratio']);
        if ($media['width'] && $media['height'] && $target) {
            $actual = $media['width'] / $media['height'];
            $dims = "{$media['width']} × {$media['height']} ({$media['ratio']})";
            if (abs($actual - $target) <= 0.03) {
                $checks[] = self::item('Aspect ratio', 'pass', "$dims matches $name's {$platform['aspect_ratio']}.", '');
            } elseif ($actual > 1 && $target < 1) {
                $checks[] = self::item('Aspect ratio', 'fail', "$dims is landscape; $name is vertical ({$platform['aspect_ratio']}).", 'Crop or re-export at 1080 × 1920.');
            } else {
                $checks[] = self::item('Aspect ratio', 'warn', "$dims; $name works best at {$platform['aspect_ratio']}.", 'Re-export at ' . $platform['aspect_ratio'] . ' to avoid crops or bars.');
            }
        } elseif ($target) {
            $checks[] = self::item('Aspect ratio', 'warn', "Couldn't read the dimensions.", "Make sure it's {$platform['aspect_ratio']}.");
        }

        if ($media['kind'] === 'video') {
            $vmax = (int)$platform['video_max_sec'];
            if ($media['duration'] === null) {
                $checks[] = self::item('Video length', 'warn', "Couldn't read the video length.", "Make sure it's under $vmax seconds.");
            } elseif ($vmax > 0 && $media['duration'] > $vmax) {
                $checks[] = self::item('Video length', 'fail', "{$media['duration']} s; $name allows $vmax s here.", 'Trim it to under ' . $vmax . ' seconds.');
            } else {
                $checks[] = self::item('Video length', 'pass', "{$media['duration']} s.", '');
            }
        }

        return $checks;
    }

    public static function hashtags(string $text): array {
        preg_match_all('/#[\p{L}\p{N}_]+/u', $text, $m);
        return array_values(array_unique(array_map('mb_strtolower', $m[0])));
    }

    public static function score(array $checks): int {
        $score = 100;
        foreach ($checks as $c) {
            if ($c['status'] === 'fail') $score -= 20;
            elseif ($c['status'] === 'warn') $score -= 7;
        }
        return max(0, $score);
    }

    public static function counts(array $checks): array {
        $out = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($checks as $c) {
            $out[$c['status']] = ($out[$c['status']] ?? 0) + 1;
        }
        return $out;
    }

    private static function bannedWords(): array {
        static $words = null;
        if ($words === null) {
            $words = db()->query('SELECT word, severity, reason FROM banned_words')->fetchAll();
        }
        return $words;
    }

    private static function item(string $name, string $status, string $message, string $fix): array {
        return ['name' => $name, 'status' => $status, 'message' => $message, 'fix' => $fix];
    }
}

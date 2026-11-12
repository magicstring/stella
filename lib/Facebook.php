<?php
// Real posting to one Facebook Page through the Graph API. Other platforms are simulated in the demo.
// Credentials come from Accounts (saved in the database), or from config.php as a fallback.

class Facebook {
    private static ?array $creds = null;

    public static function credentials(): array {
        if (self::$creds === null) {
            self::$creds = ['page_id' => FB_PAGE_ID, 'token' => FB_PAGE_TOKEN];
            try {
                $row = db()->query("SELECT a.page_id, a.token FROM accounts a JOIN platforms p ON p.id = a.platform_id
                                    WHERE p.name = 'Facebook' AND a.mode = 'live' LIMIT 1")->fetch();
                if ($row && $row['page_id'] && $row['token']) {
                    self::$creds = ['page_id' => $row['page_id'], 'token' => $row['token']];
                }
            } catch (Throwable $e) {
                // accounts table not imported yet; use config.php
            }
        }
        return self::$creds;
    }

    public static function configured(): bool {
        $c = self::credentials();
        return $c['page_id'] !== '' && $c['token'] !== '';
    }

    // Check a Page ID and token. Returns the Page name, or throws with Facebook's message.
    public static function verify(string $pageId, string $token): string {
        $url = 'https://graph.facebook.com/' . FB_GRAPH_VERSION . '/' . rawurlencode($pageId)
             . '?fields=name&access_token=' . rawurlencode($token);
        $data = self::request($url, null);
        return (string)($data['name'] ?? $pageId);
    }

    // Likes, comments and shares for a Page post; views (impressions) when the token has read_insights.
    public static function stats(string $postId): array {
        $c = self::credentials();
        $base = 'https://graph.facebook.com/' . FB_GRAPH_VERSION . '/' . rawurlencode($postId);
        $data = self::request($base . '?fields=' . rawurlencode('shares,likes.summary(true).limit(0),comments.summary(true).limit(0)')
            . '&access_token=' . rawurlencode($c['token']), null);
        $views = 0;
        try {
            $ins = self::request($base . '/insights?metric=post_impressions&access_token=' . rawurlencode($c['token']), null);
            $views = (int)($ins['data'][0]['values'][0]['value'] ?? 0);
        } catch (Throwable $e) {
            // No read_insights permission, or a photo object without insights: keep views at 0
        }
        return [
            'views'    => $views,
            'likes'    => (int)($data['likes']['summary']['total_count'] ?? 0),
            'comments' => (int)($data['comments']['summary']['total_count'] ?? 0),
            'shares'   => (int)($data['shares']['count'] ?? 0),
        ];
    }

    // Returns the new post's ID, or throws with Facebook's error message.
    public static function post(string $message, ?string $path, ?string $mime): string {
        $c = self::credentials();
        $page = rawurlencode($c['page_id']);
        $version = FB_GRAPH_VERSION;

        if ($path && $mime && str_starts_with($mime, 'image/')) {
            $url = "https://graph.facebook.com/$version/$page/photos";
            $fields = ['caption' => $message, 'source' => new CURLFile($path, $mime), 'access_token' => $c['token']];
        } elseif ($path && $mime && str_starts_with($mime, 'video/')) {
            $url = "https://graph-video.facebook.com/$version/$page/videos";
            $fields = ['description' => $message, 'source' => new CURLFile($path, $mime), 'access_token' => $c['token']];
        } else {
            $url = "https://graph.facebook.com/$version/$page/feed";
            $fields = ['message' => $message, 'access_token' => $c['token']];
        }

        $data = self::request($url, $fields);
        return (string)($data['post_id'] ?? $data['id'] ?? '');
    }

    private static function request(string $url, ?array $fields): array {
        $ch = curl_init($url);
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180];
        if ($fields !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $fields;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Facebook request failed: ' . $err);
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || isset($data['error'])) {
            throw new RuntimeException('Facebook: ' . ($data['error']['message'] ?? 'unexpected reply'));
        }
        return $data;
    }
}

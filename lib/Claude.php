<?php
// AI client. Works with Claude (Anthropic) or Grok (xAI); pick one with AI_PROVIDER in config.php.
// The class keeps the name Claude so the rest of the app doesn't change.
require_once __DIR__ . '/../config.php';

class Claude {
    public static function provider(): string {
        return defined('AI_PROVIDER') ? strtolower(AI_PROVIDER) : 'claude';
    }

    // Send a prompt, get plain text back.
    // $images: optional [['mime' => 'image/jpeg', 'data' => base64], ...] for prompts that need to look at a picture.
    public static function ask(string $system, string $user, int $maxTokens = 1500, array $images = []): string {
        if (self::provider() === 'xai') {
            $url = 'https://api.x.ai/v1/chat/completions';
            $headers = ['content-type: application/json', 'authorization: Bearer ' . XAI_API_KEY];
            $payload = [
                'model'      => XAI_MODEL,
                'max_tokens' => $maxTokens,
                'messages'   => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $images ? array_merge(
                        array_map(fn($i) => ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $i['mime'] . ';base64,' . $i['data']]], $images),
                        [['type' => 'text', 'text' => $user]]) : $user],
                ],
            ];
        } else {
            $url = 'https://api.anthropic.com/v1/messages';
            $headers = ['content-type: application/json', 'x-api-key: ' . CLAUDE_API_KEY, 'anthropic-version: 2023-06-01'];
            $payload = [
                'model'      => CLAUDE_MODEL,
                'max_tokens' => $maxTokens,
                'system'     => $system,
                'messages'   => [['role' => 'user', 'content' => $images ? array_merge(
                    array_map(fn($i) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $i['mime'], 'data' => $i['data']]], $images),
                    [['type' => 'text', 'text' => $user]]) : $user]],
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ]);
        $raw   = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $name = self::provider() === 'xai' ? 'Grok' : 'Claude';
        if ($raw === false) {
            throw new RuntimeException("$name request failed: $error");
        }
        $data = json_decode($raw, true);
        if ($code !== 200) {
            $msg = $data['error']['message'] ?? ($data['error'] ?? $raw);
            throw new RuntimeException("$name API error ($code): " . (is_string($msg) ? $msg : json_encode($msg)));
        }

        if (self::provider() === 'xai') {
            return trim((string)($data['choices'][0]['message']['content'] ?? ''));
        }
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        return trim($text);
    }

    // Send a prompt that must return JSON. Retries once if the reply isn't valid JSON.
    public static function askJson(string $system, string $user, int $maxTokens = 2000, array $images = []): array {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $prompt = $attempt === 0 ? $user : $user . "\n\nReply with valid JSON only. No markdown, no extra text.";
            $text   = self::ask($system, $prompt, $maxTokens, $images);
            $clean  = trim(preg_replace('/^```(?:json)?|```$/m', '', $text));
            // Fall back to the outermost {...} if the model added words around the JSON
            if (!str_starts_with($clean, '{') && preg_match('/\{.*\}/s', $clean, $m)) $clean = $m[0];
            $json = json_decode($clean, true);
            if (is_array($json)) return $json;
        }
        throw new RuntimeException('The AI did not return valid JSON.');
    }
}

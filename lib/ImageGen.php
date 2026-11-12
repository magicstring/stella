<?php
// AI image generation for Create New Post and the calendar planner. Works with OpenAI or xAI (both use the same images API shape).
// Add to config.php:
//   define('IMAGE_PROVIDER', 'openai');      // 'openai' or 'xai'
//   define('IMAGE_API_KEY', 'your_key');
//   define('IMAGE_MODEL', 'gpt-image-1');    // xAI: check their docs for the current image model name
// Endpoints and model names are from memory; check the provider's docs if a call fails.
//
// Reference photos ("pegs"): with OpenAI gpt-image models, a reference image is sent to the images/edits
// endpoint so the result can keep the product or layout. Other providers get the reference as a written
// description inside the prompt (see Prompts::PEG), so pegs still work everywhere.

class ImageGen {
    public static function configured(): bool {
        return defined('IMAGE_PROVIDER') && defined('IMAGE_API_KEY') && IMAGE_API_KEY !== '' && IMAGE_API_KEY !== 'your_key';
    }

    private static function provider(): string { return strtolower(IMAGE_PROVIDER); }
    private static function model(): string {
        return defined('IMAGE_MODEL') ? IMAGE_MODEL : (self::provider() === 'xai' ? 'grok-2-image' : 'gpt-image-1');
    }

    // True when the provider can take a reference image directly (not just a description of it)
    public static function supportsReference(): bool {
        return self::configured() && self::provider() === 'openai' && str_starts_with(self::model(), 'gpt-image');
    }

    // Generates one image and saves it to the upload folder. Returns the stored file name.
    // $referencePath: optional path to a reference image (the user's peg) to build from.
    public static function generate(string $prompt, bool $portrait = true, ?string $referencePath = null): string {
        if (!self::configured()) {
            throw new RuntimeException('Image generation is off. Add IMAGE_PROVIDER, IMAGE_API_KEY and IMAGE_MODEL to config.php.');
        }
        $prompt = mb_substr(trim($prompt), 0, 3000) . "\n\nNo text, letters, words, watermarks or logos anywhere in the image.";
        $size = $portrait ? '1024x1536' : '1024x1024';

        $data = null;
        if ($referencePath && is_file($referencePath) && self::supportsReference()) {
            try {
                $data = self::request('https://api.openai.com/v1/images/edits', [
                    'model'  => self::model(),
                    'prompt' => $prompt,
                    'n'      => 1,
                    'size'   => $size,
                    'image'  => new CURLFile($referencePath, Media::mime($referencePath), 'reference.' . pathinfo($referencePath, PATHINFO_EXTENSION)),
                ], true);
            } catch (Throwable $e) {
                error_log('Stella image edit failed, using text-only generation: ' . $e->getMessage());
                $data = null;   // fall back to a normal generation from the written description
            }
        }
        if ($data === null) {
            if (self::provider() === 'xai') {
                $data = self::request('https://api.x.ai/v1/images/generations',
                    ['model' => self::model(), 'prompt' => $prompt, 'n' => 1, 'response_format' => 'b64_json']);
            } else {
                $data = self::request('https://api.openai.com/v1/images/generations',
                    ['model' => self::model(), 'prompt' => $prompt, 'n' => 1, 'size' => $size]);
            }
        }

        $item = $data['data'][0] ?? [];
        if (!empty($item['b64_json'])) {
            $bytes = base64_decode($item['b64_json'], true);
        } elseif (!empty($item['url']) && preg_match('#^https://#', $item['url'])) {
            $bytes = self::download($item['url']);
        } else {
            throw new RuntimeException('The image API returned no image.');
        }
        if (!$bytes) throw new RuntimeException('The image could not be read.');

        // Store it like an upload: random name, outside the web root, verified type
        $tmp = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($tmp, $bytes);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(Media::TYPES[$mime]) || !str_starts_with($mime, 'image/')) {
            @unlink($tmp);
            throw new RuntimeException("The image API returned an unsupported file ($mime).");
        }
        if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0750, true)) {
            throw new RuntimeException('The upload folder could not be created.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . Media::TYPES[$mime];
        if (!rename($tmp, UPLOAD_DIR . $name)) {
            @unlink($tmp);
            throw new RuntimeException('The image could not be saved.');
        }
        @chmod(UPLOAD_DIR . $name, 0640);
        return $name;
    }

    // POST to the image API. $multipart sends form data (for file uploads) instead of JSON.
    private static function request(string $url, array $payload, bool $multipart = false): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 150,
            CURLOPT_HTTPHEADER     => $multipart ? ['authorization: Bearer ' . IMAGE_API_KEY]
                                                 : ['content-type: application/json', 'authorization: Bearer ' . IMAGE_API_KEY],
            CURLOPT_POSTFIELDS     => $multipart ? $payload : json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new RuntimeException('Image request failed: ' . $err);
        $data = json_decode($raw, true);
        if ($code !== 200 || !is_array($data)) {
            $msg = $data['error']['message'] ?? ($data['error'] ?? 'unexpected reply');
            throw new RuntimeException("Image API error ($code): " . (is_string($msg) ? $msg : json_encode($msg)));
        }
        return $data;
    }

    private static function download(string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
        $bytes = curl_exec($ch);
        curl_close($ch);
        return is_string($bytes) ? $bytes : '';
    }
}

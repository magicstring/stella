<?php
// Stores uploads outside the web root and reads their size, shape and length.

class Media {
    public const TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'video/mp4'       => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm'      => 'webm',
    ];

    public const NAME_PATTERN = '/^[a-f0-9]{32}\.(jpg|png|webp|mp4|mov|webm)$/';

    // Validate and save an upload from $_FILES. Returns the stored file name.
    public static function store(array $file): string {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadError((int)$err));
        }
        if ($file['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
            throw new RuntimeException('The file is larger than ' . MAX_UPLOAD_MB . ' MB. Compress it and try again.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset(self::TYPES[$mime])) {
            throw new RuntimeException("This file type ($mime) isn't supported. Use JPG, PNG, WebP, MP4, MOV or WebM.");
        }
        if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0750, true)) {
            throw new RuntimeException('The upload folder could not be created. Check UPLOAD_DIR in config.php.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $name)) {
            throw new RuntimeException('The file could not be saved. Check that the upload folder is writable.');
        }
        return $name;
    }

    public static function path(string $name): ?string {
        if (!preg_match(self::NAME_PATTERN, $name)) return null;
        $path = UPLOAD_DIR . $name;
        return is_file($path) ? $path : null;
    }

    public static function mime(string $path): string {
        return (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    }

    // Read metadata. $client holds width/height/duration measured by the browser,
    // which handles phone video rotation correctly, so it wins over ffprobe for video.
    public static function analyze(string $name, array $client = []): ?array {
        $path = self::path($name);
        if ($path === null) return null;

        $mime = self::mime($path);
        $info = [
            'file'     => $name,
            'mime'     => $mime,
            'kind'     => str_starts_with($mime, 'video/') ? 'video' : 'image',
            'size_mb'  => round(filesize($path) / 1048576, 2),
            'width'    => null,
            'height'   => null,
            'duration' => null,
            'ratio'    => null,
        ];

        if ($info['kind'] === 'image') {
            $size = @getimagesize($path);
            if ($size) {
                $info['width'] = (int)$size[0];
                $info['height'] = (int)$size[1];
            }
        } else {
            $cw = (int)($client['width'] ?? 0);
            $ch = (int)($client['height'] ?? 0);
            $cd = (float)($client['duration'] ?? 0);
            if ($cw > 0 && $ch > 0) {
                $info['width'] = $cw;
                $info['height'] = $ch;
            }
            if ($cd > 0) {
                $info['duration'] = round($cd, 1);
            }
            if (!$info['width'] || !$info['duration']) {
                $probe = self::ffprobe($path);
                if ($probe) {
                    $info['width'] = $info['width'] ?: $probe['width'];
                    $info['height'] = $info['height'] ?: $probe['height'];
                    $info['duration'] = $info['duration'] ?: $probe['duration'];
                }
            }
        }

        if ($info['width'] && $info['height']) {
            $info['ratio'] = self::ratioLabel($info['width'], $info['height']);
        }
        return $info;
    }

    public static function ratioLabel(int $w, int $h): string {
        $r = $w / $h;
        $common = ['9:16' => 9 / 16, '4:5' => 4 / 5, '3:4' => 3 / 4, '1:1' => 1.0, '4:3' => 4 / 3, '16:9' => 16 / 9];
        foreach ($common as $label => $value) {
            if (abs($r - $value) <= 0.03) return $label;
        }
        return number_format($r, 2) . ':1';
    }

    public static function ratioValue(string $label): ?float {
        if (preg_match('/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', trim($label), $m) && (float)$m[2] > 0) {
            return (float)$m[1] / (float)$m[2];
        }
        return null;
    }

    private static function ffprobe(string $path): ?array {
        if (!function_exists('shell_exec')) return null;
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        if (in_array('shell_exec', $disabled, true)) return null;

        $bin = trim((string)@shell_exec('command -v ffprobe 2>/dev/null'));
        if ($bin === '') return null;

        $cmd = escapeshellarg($bin) . ' -v error -select_streams v:0 -show_entries stream=width,height:format=duration -of json '
             . escapeshellarg($path) . ' 2>/dev/null';
        $data = json_decode((string)@shell_exec($cmd), true);
        if (!is_array($data)) return null;

        return [
            'width'    => isset($data['streams'][0]['width']) ? (int)$data['streams'][0]['width'] : null,
            'height'   => isset($data['streams'][0]['height']) ? (int)$data['streams'][0]['height'] : null,
            'duration' => isset($data['format']['duration']) ? round((float)$data['format']['duration'], 1) : null,
        ];
    }

    private static function uploadError(int $code): string {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is bigger than the server allows. Raise upload_max_filesize and post_max_size in hPanel, or compress the file.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Try again.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            default => "The upload failed (error $code). Try again.",
        };
    }
}

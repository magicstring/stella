<?php
// POST: run a pre-flight check. Returns JSON {id, redirect} or {error}.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();
require_post();

try {
    $platform = platform_by_id((int)($_POST['platform_id'] ?? 0));
    if (!$platform) {
        json_out(['error' => 'Pick a platform.'], 422);
    }

    $caption = trim((string)($_POST['caption'] ?? ''));
    if (mb_strlen($caption) > 10000) {
        json_out(['error' => 'That caption is over 10,000 characters. Shorten it first.'], 422);
    }

    $mediaFile = null;
    if (!empty($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $mediaFile = Media::store($_FILES['file']);
    } elseif (!empty($_POST['media_file']) && Media::path((string)$_POST['media_file'])) {
        // An AI image generated in Ask for help, already stored in the upload folder
        $mediaFile = (string)$_POST['media_file'];
    } elseif (!empty($_POST['reuse_check_id'])) {
        // Re-check with a fixed caption: reuse the earlier upload
        $prev = find_check((int)$_POST['reuse_check_id']);
        $mediaFile = $prev['file_path'] ?? null;
    }

    if ($caption === '' && $mediaFile === null) {
        json_out(['error' => 'Add a caption or a file to check.'], 422);
    }

    // Browser-measured size and length, also passed on for re-checks
    $client = [
        'width'    => $_POST['client_width'] ?? null,
        'height'   => $_POST['client_height'] ?? null,
        'duration' => $_POST['client_duration'] ?? null,
    ];
    if (!empty($_POST['reuse_check_id']) && empty($client['width'])) {
        $prevMedia = decode_json($prev['rule_results'] ?? null)['media'] ?? null;
        if ($prevMedia) {
            $client = ['width' => $prevMedia['width'], 'height' => $prevMedia['height'], 'duration' => $prevMedia['duration']];
        }
    }

    $id = Preflight::run($platform, $caption, $mediaFile, $client, current_business());
    json_out(['id' => $id, 'redirect' => 'result.php?id=' . $id]);
} catch (Throwable $e) {
    json_out(['error' => user_error_message($e)], 400);
}

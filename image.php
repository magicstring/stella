<?php
// POST prompt: generate one AI image, save it, return {file, url}. Capped per session so a demo can't run up a bill.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();
require_post();

const IMAGE_SESSION_CAP = 30;

try {
    $prompt = trim((string)($_POST['prompt'] ?? ''));
    if ($prompt === '' || mb_strlen($prompt) > 3000) {
        json_out(['error' => 'Describe the image first (up to 3,000 characters).'], 422);
    }
    $used = (int)($_SESSION['images_made'] ?? 0);
    if ($used >= IMAGE_SESSION_CAP) {
        json_out(['error' => 'Image limit reached for this session (' . IMAGE_SESSION_CAP . '). Sign out and back in to reset it.'], 429);
    }
    $portrait = ($_POST['shape'] ?? 'portrait') !== 'square';
    // Use the peg (reference photo) from Create New Post when the result was built on one
    $ref = null;
    $peg = $_SESSION['help_peg'] ?? null;
    if (!empty($_POST['use_peg']) && $peg && in_array($peg['use'], ['product', 'layout'], true)) {
        $ref = Media::path((string)$peg['file']);
    }
    $file = ImageGen::generate($prompt, $portrait, $ref);
    $_SESSION['images_made'] = $used + 1;
    // Attach to a calendar draft when asked
    $draftId = (int)($_POST['draft_id'] ?? 0);
    if ($draftId) {
        db()->prepare("UPDATE content_drafts SET file_path = ? WHERE id = ? AND status = 'draft'")->execute([$file, $draftId]);
    }
    // Remember which slot on the Ask for help page this image belongs to, so it survives a reload
    $slot = (string)($_POST['slot'] ?? '');
    if (preg_match('/^[a-z0-9_-]{1,40}$/', $slot)) {
        $_SESSION['help_images'][$slot] = $file;
    }
    json_out(['file' => $file, 'url' => 'media.php?f=' . rawurlencode($file)]);
} catch (Throwable $e) {
    json_out(['error' => user_error_message($e)], 400);
}

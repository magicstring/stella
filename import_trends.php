<?php
// POST a CSV of trends: type, name, platform, velocity, note, seen_at (header row optional).
require_once __DIR__ . '/lib/bootstrap.php';
require_login();
require_post();

$file = $_FILES['csv'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) {
    flash('Choose a CSV file under 2 MB.', 'error');
    redirect('trends.php');
}

$fh = fopen($file['tmp_name'], 'r');
$rows = [];
$skipped = 0;
$line = 0;
while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
    $line++;
    if ($cols === [null]) continue; // blank line
    $cols = array_map(fn($c) => trim((string)$c), $cols);
    if ($line === 1) {
        $cols[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cols[0]); // Excel BOM
        if (strtolower($cols[0]) === 'type') continue;          // header row
    }
    $type = strtolower($cols[0] ?? '');
    $name = $cols[1] ?? '';
    if (!in_array($type, Trends::TYPES, true) || $name === '' || mb_strlen($name) > 150) {
        $skipped++;
        continue;
    }
    $platform = platform_by_name($cols[2] ?? '');
    $seen = $cols[5] ?? '';
    $time = $seen !== '' ? strtotime($seen) : false;
    $rows[] = [
        $type,
        $name,
        $platform['name'] ?? null,
        max(0, min(100, (int)($cols[3] ?? 0))),
        mb_substr($cols[4] ?? '', 0, 255),
        $time ? date('Y-m-d', $time) : date('Y-m-d'),
    ];
}
fclose($fh);

if (!$rows) {
    flash('No valid rows found. Check the columns match the template.', 'error');
    redirect('trends.php');
}

$pdo = db();
$pdo->beginTransaction();
try {
    if (!empty($_POST['replace'])) {
        $pdo->exec('DELETE FROM trends');
    }
    $st = $pdo->prepare('INSERT INTO trends (type, name, platform, velocity, note, seen_at) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($rows as $r) $st->execute($r);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    flash(user_error_message($e), 'error');
    redirect('trends.php');
}

flash('Imported ' . count($rows) . ' trends' . ($skipped ? ", skipped $skipped rows with a missing or unknown type or name." : '.'));
redirect('trends.php');

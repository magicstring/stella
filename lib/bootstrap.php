<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/Claude.php';
require_once __DIR__ . '/Media.php';
require_once __DIR__ . '/Rules.php';
require_once __DIR__ . '/Trends.php';
require_once __DIR__ . '/Prompts.php';
require_once __DIR__ . '/Preflight.php';
require_once __DIR__ . '/Facebook.php';
require_once __DIR__ . '/Publisher.php';
require_once __DIR__ . '/Insights.php';
require_once __DIR__ . '/ImageGen.php';
require_once __DIR__ . '/layout.php';

date_default_timezone_set(APP_TIMEZONE);
mb_internal_encoding('UTF-8');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// ---------- Output helpers ----------

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function wants_json(): bool {
    return !empty($_SERVER['HTTP_X_CSRF_TOKEN'])
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(?string $message = null, string $type = 'ok'): ?array {
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// Hide database details from users; show our own messages.
function user_error_message(Throwable $e): string {
    if ($e instanceof PDOException) {
        error_log('Stella DB error: ' . $e->getMessage());
        return 'The database returned an error. Check the server error log.';
    }
    return $e->getMessage();
}

// ---------- Security ----------

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        if (wants_json()) {
            json_out(['error' => 'Your session expired. Reload the page and try again.'], 403);
        }
        http_response_code(403);
        exit('Your session expired. Go back, reload the page and try again.');
    }
}

function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (wants_json()) {
            json_out(['error' => 'Use POST for this request.'], 405);
        }
        http_response_code(405);
        exit('Use POST for this request.');
    }
    csrf_check();
}

function require_login(): void {
    if (APP_PASSWORD === '' || !empty($_SESSION['signed_in'])) {
        return;
    }
    if (wants_json()) {
        json_out(['error' => 'You were signed out. Reload the page and sign in.'], 401);
    }
    redirect('login.php');
}

// ---------- Data helpers ----------

function decode_json(?string $s): array {
    $d = json_decode($s ?? '', true);
    return is_array($d) ? $d : [];
}

function platforms(): array {
    static $rows = null;
    if ($rows === null) {
        $rows = db()->query('SELECT * FROM platforms ORDER BY id')->fetchAll();
    }
    return $rows;
}

function platform_by_id(int $id): ?array {
    foreach (platforms() as $p) {
        if ((int)$p['id'] === $id) return $p;
    }
    return null;
}

function platform_by_name(string $name): ?array {
    foreach (platforms() as $p) {
        if (strcasecmp($p['name'], trim($name)) === 0) return $p;
    }
    return null;
}

// ---------- Businesses ----------
// The active business is kept in the session; on a fresh sign-in it's the one used most recently.

function current_business(bool $refresh = false): ?array {
    static $cache = false;
    if ($cache !== false && !$refresh) return $cache;
    $row = null;
    $id = (int)($_SESSION['business_id'] ?? 0);
    if ($id) {
        $st = db()->prepare('SELECT * FROM business_profiles WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch() ?: null;
    }
    if (!$row) {
        try {
            $row = db()->query('SELECT * FROM business_profiles ORDER BY last_used_at IS NULL, last_used_at DESC, id DESC LIMIT 1')->fetch() ?: null;
        } catch (PDOException $e) {   // business.sql not imported yet
            $row = db()->query('SELECT * FROM business_profiles ORDER BY id DESC LIMIT 1')->fetch() ?: null;
        }
        if ($row && PHP_SAPI !== 'cli') $_SESSION['business_id'] = (int)$row['id'];
    }
    $cache = $row;
    return $cache;
}

function current_business_id(): int {
    return (int)(current_business()['id'] ?? 0);
}

function all_businesses(): array {
    return db()->query('SELECT id, name, ' . (multi_business_ready() ? 'industry' : "'' AS industry") . ' FROM business_profiles ORDER BY name')->fetchAll();
}

// True once business.sql has been imported (the per-business columns exist)
function multi_business_ready(): bool {
    static $ok = null;
    if ($ok === null) {
        try { db()->query('SELECT business_id FROM scheduled_posts LIMIT 1'); db()->query('SELECT industry FROM business_profiles LIMIT 1'); $ok = true; }
        catch (PDOException $e) { $ok = false; }
    }
    return $ok;
}

// SQL condition that limits a table to the active business. $alias is the table alias, e.g. 'c' or 'sp'.
// Before business.sql is imported it matches everything, so nothing breaks.
function biz_where(string $alias = ''): string {
    if (!multi_business_ready()) return '1=1';
    return ($alias !== '' ? $alias . '.' : '') . 'business_id = ' . current_business_id();
}

function switch_business(int $id): bool {
    $st = db()->prepare('SELECT id FROM business_profiles WHERE id = ?');
    $st->execute([$id]);
    if (!$st->fetchColumn()) return false;
    $_SESSION['business_id'] = $id;
    if (multi_business_ready()) {
        db()->prepare('UPDATE business_profiles SET last_used_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $id]);
    }
    // Ideas and drafts in the session belong to the old business
    unset($_SESSION['suggestions'], $_SESSION['help'], $_SESSION['help_images'], $_SESSION['scout']);
    current_business(true);
    return true;
}

function find_check(int $id): ?array {
    $st = db()->prepare('SELECT * FROM checks WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function nice_time(?string $dt): string {
    if (!$dt) return '';
    $t = strtotime($dt);
    return $t ? date('M j, g:i A', $t) : $dt;
}

// Connected accounts keyed by platform_id. Empty if accounts.sql hasn't been imported.
function accounts(): array {
    static $rows = null;
    if ($rows === null) {
        $rows = [];
        try {
            foreach (db()->query('SELECT * FROM accounts')->fetchAll() as $a) {
                $rows[(int)$a['platform_id']] = $a;
            }
        } catch (PDOException $e) {
            $rows = [];
        }
    }
    return $rows;
}

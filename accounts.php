<?php
// Connect social accounts. Facebook can connect for real with a Page ID and Page token.
// Other platforms connect in demo mode: posts are queued and marked Simulated.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

$tableReady = true;
try {
    db()->query('SELECT 1 FROM accounts LIMIT 1');
} catch (PDOException $e) {
    $tableReady = false;
}

if ($tableReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $platform = platform_by_id((int)($_POST['platform_id'] ?? 0));
    $action = $_POST['action'] ?? '';
    if (!$platform) {
        flash('Pick a platform.', 'error');
        redirect('accounts.php');
    }
    $isFacebook = strcasecmp($platform['name'], 'Facebook') === 0;

    if ($action === 'disconnect') {
        db()->prepare('DELETE FROM accounts WHERE platform_id = ?')->execute([$platform['id']]);
        flash($platform['name'] . ' disconnected.');
        redirect('accounts.php');
    }

    if ($action === 'connect') {
        $handle = trim((string)($_POST['handle'] ?? ''));
        $pageId = trim((string)($_POST['page_id'] ?? ''));
        $token = trim((string)($_POST['token'] ?? ''));
        $mode = 'demo';

        if ($isFacebook && ($pageId !== '' || $token !== '')) {
            if ($pageId === '' || $token === '') {
                flash('Enter both the Page ID and the Page access token, or leave both blank for a demo connection.', 'error');
                redirect('accounts.php');
            }
            try {
                $name = Facebook::verify($pageId, $token);
            } catch (Throwable $e) {
                flash("Facebook didn't accept that Page ID and token. " . $e->getMessage(), 'error');
                redirect('accounts.php');
            }
            $handle = $handle !== '' ? $handle : $name;
            $mode = 'live';
        }

        if ($handle === '' || mb_strlen($handle) > 150) {
            flash('Enter the account name or @handle (up to 150 characters).', 'error');
            redirect('accounts.php');
        }

        $st = db()->prepare('INSERT INTO accounts (platform_id, handle, mode, page_id, token) VALUES (?, ?, ?, ?, ?)
                             ON DUPLICATE KEY UPDATE handle = VALUES(handle), mode = VALUES(mode), page_id = VALUES(page_id),
                             token = VALUES(token), connected_at = CURRENT_TIMESTAMP');
        $st->execute([$platform['id'], $handle, $mode, $mode === 'live' ? $pageId : null, $mode === 'live' ? $token : null]);
        flash($platform['name'] . ($mode === 'live' ? " connected to $handle. Posts go out for real." : " connected in demo mode as $handle."));
        redirect('accounts.php');
    }
}

$accounts = $tableReady ? accounts() : [];

page_header('Channels', 'accounts.php');
?>
<h1 class="font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight mb-2">Connected accounts</h1>
<p class="text-muted mb-5 sm:mb-8 max-w-prose">Connect the accounts Stella publishes to. Facebook Pages post for real. TikTok, Instagram and YouTube need an approved developer app before anyone can post through them, so for now they connect in demo mode: posts are queued and marked Simulated.</p>

<?php if (!$tableReady): ?>
  <div class="rounded-md bg-failbg text-fail px-4 py-3">Import <code>accounts.sql</code> in phpMyAdmin, then reload this page.</div>
<?php else: ?>
<div class="grid md:grid-cols-2 gap-5">
  <?php foreach (platforms() as $p):
      $a = $accounts[(int)$p['id']] ?? null;
      $isFacebook = strcasecmp($p['name'], 'Facebook') === 0; ?>
    <section class="rounded-md border border-line bg-white p-4 sm:p-5" aria-labelledby="acc-<?= (int)$p['id'] ?>">
      <div class="flex items-center justify-between gap-3 mb-3">
        <h2 id="acc-<?= (int)$p['id'] ?>" class="font-display text-xl font-extrabold"><?= e($p['name']) ?></h2>
        <?= status_pill($a['mode'] ?? 'none') ?>
      </div>

      <?php if ($a): ?>
        <p class="font-semibold"><?= e($a['handle']) ?></p>
        <p class="text-sm text-muted mt-1">
          <?= $a['mode'] === 'live' ? 'Posts go out to this Page for real.' : 'Posts are queued and marked Simulated.' ?>
          Connected <?= e(nice_time($a['connected_at'])) ?>.
        </p>
        <form method="post" class="mt-4" onsubmit="return confirm('Disconnect <?= e($p['name']) ?>?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="disconnect"><input type="hidden" name="platform_id" value="<?= (int)$p['id'] ?>">
          <button class="text-sm text-fail underline">Disconnect</button>
        </form>
      <?php else: ?>
        <form method="post" class="space-y-3">
          <?= csrf_field() ?><input type="hidden" name="action" value="connect"><input type="hidden" name="platform_id" value="<?= (int)$p['id'] ?>">
          <div>
            <label for="h-<?= (int)$p['id'] ?>" class="text-sm font-semibold block mb-1"><?= $isFacebook ? 'Page name (optional)' : 'Account @handle' ?></label>
            <input id="h-<?= (int)$p['id'] ?>" name="handle" maxlength="150" <?= $isFacebook ? '' : 'required' ?> placeholder="<?= $isFacebook ? 'Filled in from Facebook' : '@yourbrand' ?>" class="w-full rounded-md border border-line px-3 py-2 text-sm">
          </div>
          <?php if ($isFacebook): ?>
            <div>
              <label for="pid" class="text-sm font-semibold block mb-1">Page ID</label>
              <input id="pid" name="page_id" inputmode="numeric" autocomplete="off" class="w-full rounded-md border border-line px-3 py-2 text-sm">
            </div>
            <div>
              <label for="tok" class="text-sm font-semibold block mb-1">Page access token</label>
              <input id="tok" name="token" type="password" autocomplete="off" class="w-full rounded-md border border-line px-3 py-2 text-sm">
              <p class="text-xs text-muted mt-1">From Graph API Explorer with pages_manage_posts. Leave both blank to connect in demo mode instead.</p>
            </div>
          <?php endif; ?>
          <button class="rounded-md bg-sea px-4 py-2 text-sm font-semibold text-white hover:bg-seadeep">Connect <?= e($p['name']) ?></button>
        </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php page_footer(); ?>

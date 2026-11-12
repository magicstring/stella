<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

$check = find_check((int)($_GET['check_id'] ?? $_POST['check_id'] ?? 0));
if (!$check) {
    flash("That check doesn't exist. Check a post first.", 'error');
    redirect('index.php');
}

$ruleData = decode_json($check['rule_results']);
$aiRaw = decode_json($check['ai_results']);
$ai = isset($aiRaw['overall_score']) ? $aiRaw : null;
$verdict = Preflight::verdict($ruleData['checks'] ?? [], $ai, (int)$check['score'], (string)$check['caption']);
$fixes = [];
foreach (decode_json($check['fixes'])['versions'] ?? [] as $v) {
    $fixes[$v['platform']] = trim($v['caption'] . ($v['hashtags'] ? "\n\n" . implode(' ', $v['hashtags']) : ''));
}
$checkedPlatform = platform_by_id((int)$check['platform_id']);
$accounts = accounts();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $selected = array_map('intval', (array)($_POST['platforms'] ?? []));
    $captions = (array)($_POST['captions'] ?? []);
    $mode = ($_POST['when'] ?? 'now') === 'later' ? 'later' : 'now';

    if (!$selected) {
        flash('Pick at least one platform to publish to.', 'error');
        redirect('publish.php?check_id=' . (int)$check['id']);
    }
    if ($verdict['key'] === 'hold' && empty($_POST['override'])) {
        flash("This post is on hold. Fix it first, or tick \"Publish anyway\".", 'error');
        redirect('publish.php?check_id=' . (int)$check['id']);
    }

    $when = date('Y-m-d H:i:s');
    if ($mode === 'later') {
        $t = strtotime((string)($_POST['scheduled_at'] ?? ''));
        if (!$t || $t < time() - 60) {
            flash('Pick a date and time in the future.', 'error');
            redirect('publish.php?check_id=' . (int)$check['id']);
        }
        $when = date('Y-m-d H:i:s', $t);
    }
    if (!array_filter($selected, fn($pid) => isset($accounts[$pid]))) {
        flash('Connect an account first, on the Accounts page.', 'error');
        redirect('publish.php?check_id=' . (int)$check['id']);
    }

    $count = 0;
    foreach ($selected as $pid) {
        $p = platform_by_id($pid);
        if (!$p || !isset($accounts[$pid])) continue;
        $caption = trim((string)($captions[$pid] ?? $check['caption']));
        Publisher::queue((int)$check['id'], $p, $caption, $check['file_path'] ?: null, $when);
        $count++;
    }
    if ($mode === 'now') {
        Publisher::runDue(20);
        flash("Sent to $count platform" . ($count === 1 ? '' : 's') . '. See the status below.');
    } else {
        flash("Scheduled for $count platform" . ($count === 1 ? '' : 's') . ' on ' . nice_time($when) . '.');
    }
    redirect('publish.php?check_id=' . (int)$check['id']);
}

Publisher::runDue();
$st = db()->prepare('SELECT sp.*, p.name AS platform FROM scheduled_posts sp JOIN platforms p ON p.id = sp.platform_id WHERE sp.check_id = ? ORDER BY sp.id DESC');
$st->execute([$check['id']]);
$queued = $st->fetchAll();

page_header('Publish', 'history.php');
?>
<a href="result.php?id=<?= (int)$check['id'] ?>" class="text-sm text-sea underline">Back to the check</a>
<h1 class="font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight mt-4 mb-2">Publish</h1>
<p class="text-muted mb-6 max-w-prose">
  Choose where this post goes and when. Connected Facebook Pages post for real; demo connections are marked Simulated.
  <a href="accounts.php" class="text-sea underline">Manage accounts</a>
</p>

<div class="mb-5 sm:mb-8 flex items-center gap-3 rounded-md border border-line bg-white px-4 py-3">
  <?= status_pill($verdict['key']) ?>
  <p class="text-sm">Score <strong class="tabular-nums"><?= (int)$check['score'] ?></strong> on <?= e($checkedPlatform['name'] ?? '') ?>. <?= e($verdict['note']) ?></p>
</div>

<form method="post" class="space-y-6">
  <?= csrf_field() ?>
  <input type="hidden" name="check_id" value="<?= (int)$check['id'] ?>">

  <div class="grid md:grid-cols-2 gap-4">
    <?php foreach (platforms() as $p):
        $text = $fixes[$p['name']] ?? (string)$check['caption'];
        $acc = $accounts[(int)$p['id']] ?? null;
        $isChecked = $acc && (int)$p['id'] === (int)$check['platform_id']; ?>
      <div class="rounded-md border border-line bg-white p-4 <?= $acc ? '' : 'opacity-60' ?>">
        <label class="flex items-center gap-2 font-semibold mb-1">
          <input type="checkbox" name="platforms[]" value="<?= (int)$p['id'] ?>" <?= $isChecked ? 'checked' : '' ?> <?= $acc ? '' : 'disabled' ?> class="h-4 w-4 accent-[#0B6E7F]">
          <?= e($p['name']) ?>
          <span class="ml-auto"><?= status_pill($acc['mode'] ?? 'none') ?></span>
        </label>
        <p class="text-xs text-muted mb-2">
          <?php if ($acc): ?><?= e($acc['handle']) ?> · <?= isset($fixes[$p['name']]) ? 'fixed caption' : 'original caption' ?>
          <?php else: ?>Not connected. <a href="accounts.php" class="text-sea underline">Connect <?= e($p['name']) ?></a><?php endif; ?>
        </p>
        <label for="cap-<?= (int)$p['id'] ?>" class="sr-only"><?= e($p['name']) ?> caption</label>
        <textarea id="cap-<?= (int)$p['id'] ?>" name="captions[<?= (int)$p['id'] ?>]" rows="5" class="w-full rounded-md border border-line px-3 py-2 text-sm leading-relaxed focus:border-sea focus:outline-none"><?= e($text) ?></textarea>
        <?php if (mb_strlen($text) > (int)$p['caption_max']): ?>
          <p class="text-xs text-fail mt-1"><?= mb_strlen($text) ?> characters; <?= e($p['name']) ?> allows <?= (int)$p['caption_max'] ?>. Use Fix it for me first.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <fieldset class="rounded-md border border-line bg-white p-4">
    <legend class="font-semibold px-1">When</legend>
    <div class="flex flex-wrap items-center gap-6">
      <label class="flex items-center gap-2"><input type="radio" name="when" value="now" checked class="accent-[#0B6E7F]"> Post now</label>
      <label class="flex items-center gap-2"><input type="radio" name="when" value="later" class="accent-[#0B6E7F]"> Schedule for</label>
      <label for="scheduled_at" class="sr-only">Date and time</label>
      <input id="scheduled_at" type="datetime-local" name="scheduled_at" min="<?= date('Y-m-d\TH:i') ?>" class="rounded-md border border-line px-3 py-1.5 text-sm">
    </div>
    <p class="text-xs text-muted mt-2">Philippine time. Scheduled posts go out when the cron job runs, or when someone opens History or this page.</p>
  </fieldset>

  <?php if ($verdict['key'] === 'hold'): ?>
    <label class="flex items-center gap-2 text-sm text-fail font-semibold"><input type="checkbox" name="override" value="1" class="accent-[#B42318]"> Publish anyway, even though this post is on hold</label>
  <?php endif; ?>

  <button class="rounded-md bg-sea px-6 py-3 font-semibold text-white hover:bg-seadeep">Publish</button>
</form>

<?php if ($queued): ?>
  <h2 class="font-display text-2xl font-extrabold mt-8 lg:mt-12 mb-3">Status</h2>
  <div class="overflow-x-auto rounded-md border border-line bg-white">
    <table class="w-full text-sm">
      <thead class="text-left text-muted border-b border-line"><tr><th class="p-3 font-semibold">Platform</th><th class="p-3 font-semibold">Status</th><th class="p-3 font-semibold">When</th><th class="p-3 font-semibold">Details</th></tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($queued as $q): ?>
          <tr>
            <td class="p-3 font-semibold"><?= e($q['platform']) ?></td>
            <td class="p-3"><?= status_pill($q['status']) ?></td>
            <td class="p-3 whitespace-nowrap"><?= e(nice_time($q['posted_at'] ?: $q['scheduled_at'])) ?></td>
            <td class="p-3 text-muted">
              <?php if ($q['status'] === 'failed'): ?><span class="text-fail"><?= e($q['error']) ?></span>
              <?php elseif ($q['status'] === 'posted'): ?>Post ID <?= e($q['external_id']) ?>
              <?php elseif ($q['status'] === 'simulated'): ?>Demo only; not sent to <?= e($q['platform']) ?>.
              <?php else: ?>Waiting<?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php page_footer(); ?>

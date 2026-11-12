<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

Publisher::runDue();

$checks = db()->query('SELECT c.id, c.caption, c.score, c.verdict, c.created_at, p.name AS platform
                       FROM checks c LEFT JOIN platforms p ON p.id = c.platform_id
                       WHERE ' . biz_where('c') . ' ORDER BY c.id DESC LIMIT 50')->fetchAll();
$posts = db()->query('SELECT sp.*, p.name AS platform FROM scheduled_posts sp JOIN platforms p ON p.id = sp.platform_id
                      WHERE ' . biz_where('sp') . ' ORDER BY sp.id DESC LIMIT 50')->fetchAll();

page_header('Queue and history', 'history.php');
?>
<h1 class="font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight mb-5 sm:mb-8">History</h1>

<h2 class="font-display text-2xl font-extrabold mb-3">Checks</h2>
<?php if (!$checks): ?>
  <div class="rounded-md border border-dashed border-line bg-white p-8 text-center mb-10">
    <p class="font-semibold">No checks yet</p>
    <a href="index.php" class="text-sm text-sea underline mt-1 inline-block">Check your first post</a>
  </div>
<?php else: ?>
  <div class="overflow-x-auto rounded-md border border-line bg-white mb-10">
    <table class="w-full text-sm">
      <thead class="text-left text-muted border-b border-line"><tr><th class="p-3 font-semibold text-right">Score</th><th class="p-3 font-semibold">Verdict</th><th class="p-3 font-semibold">Platform</th><th class="p-3 font-semibold">Caption</th><th class="p-3 font-semibold">Checked</th></tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($checks as $c): ?>
          <tr class="hover:bg-paper">
            <td class="p-3 text-right font-display text-lg font-extrabold tabular-nums"><a href="result.php?id=<?= (int)$c['id'] ?>"><?= (int)$c['score'] ?></a></td>
            <td class="p-3"><?= status_pill($c['verdict'] ?: 'fix') ?></td>
            <td class="p-3"><?= e($c['platform']) ?></td>
            <td class="p-3 max-w-md"><a class="hover:underline" href="result.php?id=<?= (int)$c['id'] ?>"><?= e(mb_strimwidth((string)$c['caption'], 0, 90, '…') ?: 'No caption') ?></a></td>
            <td class="p-3 whitespace-nowrap text-muted"><?= e(nice_time($c['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<h2 class="font-display text-2xl font-extrabold mb-3">Posts</h2>
<?php if (!$posts): ?>
  <p class="text-sm text-muted">Nothing published or scheduled yet. Publish a checked post from its result page.</p>
<?php else: ?>
  <div class="overflow-x-auto rounded-md border border-line bg-white">
    <table class="w-full text-sm">
      <thead class="text-left text-muted border-b border-line"><tr><th class="p-3 font-semibold">Platform</th><th class="p-3 font-semibold">Status</th><th class="p-3 font-semibold">Caption</th><th class="p-3 font-semibold">When</th></tr></thead>
      <tbody class="divide-y divide-line">
        <?php foreach ($posts as $p): ?>
          <tr>
            <td class="p-3 font-semibold"><?= e($p['platform']) ?></td>
            <td class="p-3"><?= status_pill($p['status']) ?><?php if ($p['status'] === 'failed'): ?><p class="text-xs text-fail mt-1"><?= e($p['error']) ?></p><?php endif; ?></td>
            <td class="p-3 max-w-md"><a class="hover:underline" href="<?= $p['check_id'] ? 'publish.php?check_id=' . (int)$p['check_id'] : 'calendar.php?month=' . date('Y-m', strtotime($p['posted_at'] ?: $p['scheduled_at'])) ?>"><?= e(mb_strimwidth((string)$p['caption'], 0, 90, '…')) ?></a></td>
            <td class="p-3 whitespace-nowrap text-muted"><?= e(nice_time($p['posted_at'] ?: $p['scheduled_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
<?php page_footer(); ?>

<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

// Add, update or delete one trend
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id) {
        db()->prepare('DELETE FROM trends WHERE id = ?')->execute([$id]);
        flash('Trend deleted.');
        redirect('trends.php');
    }

    if ($action === 'save') {
        $type = (string)($_POST['type'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        $platform = trim((string)($_POST['platform'] ?? ''));
        $velocity = max(0, min(100, (int)($_POST['velocity'] ?? 0)));
        $note = trim((string)($_POST['note'] ?? ''));
        $seen = (string)($_POST['seen_at'] ?? '');
        $seen = preg_match('/^\d{4}-\d{2}-\d{2}$/', $seen) ? $seen : date('Y-m-d');

        if (!in_array($type, Trends::TYPES, true) || $name === '' || mb_strlen($name) > 150 || mb_strlen($note) > 255) {
            flash('Give the trend a type and a name (up to 150 characters).', 'error');
            redirect('trends.php' . ($id ? '?edit=' . $id : ''));
        }
        if ($platform !== '' && !platform_by_name($platform)) $platform = '';

        if ($id) {
            $st = db()->prepare('UPDATE trends SET type = ?, name = ?, platform = ?, velocity = ?, note = ?, seen_at = ? WHERE id = ?');
            $st->execute([$type, $name, $platform ?: null, $velocity, $note, $seen, $id]);
            flash('Trend updated.');
        } else {
            $st = db()->prepare('INSERT INTO trends (type, name, platform, velocity, note, seen_at) VALUES (?, ?, ?, ?, ?, ?)');
            $st->execute([$type, $name, $platform ?: null, $velocity, $note, $seen]);
            flash('Trend added.');
        }
        redirect('trends.php');
    }
}

$edit = null;
if (!empty($_GET['edit'])) {
    $st = db()->prepare('SELECT * FROM trends WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$trends = db()->query('SELECT * FROM trends ORDER BY velocity DESC, seen_at DESC')->fetchAll();

page_header('Trends', 'trends.php');
?>
<div class="flex flex-wrap items-end justify-between gap-4 mb-5 sm:mb-8">
  <div>
    <h1 class="font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight mb-2">What's rising in the Philippines</h1>
    <p class="text-muted max-w-prose">Collected from TikTok Creative Center, Google Trends PH, Shopee and Lazada. Rise is a 0 to 100 score for how fast it's growing. Claude uses the fastest-rising ones for trend fit and post ideas.</p>
  </div>
</div>

<div class="grid lg:grid-cols-[1fr_320px] gap-5 lg:gap-8">
  <section>
    <?php if (!$trends): ?>
      <div class="rounded-md border border-dashed border-line bg-white p-8 text-center">
        <p class="font-semibold">No trends yet</p>
        <p class="text-sm text-muted mt-1">Add one with the form, or import a CSV. Without trends, trend fit falls back to evergreen ideas.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto rounded-md border border-line bg-white">
        <table class="w-full text-sm">
          <thead class="text-left text-muted border-b border-line">
            <tr><th class="p-3 font-semibold">Trend</th><th class="p-3 font-semibold">Type</th><th class="p-3 font-semibold">Platform</th><th class="p-3 font-semibold text-right">Rise</th><th class="p-3 font-semibold">Seen</th><th class="p-3"><span class="sr-only">Actions</span></th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <?php foreach ($trends as $t): ?>
              <tr>
                <td class="p-3"><p class="font-semibold"><?= e($t['name']) ?></p><?php if ($t['note']): ?><p class="text-muted text-xs mt-0.5"><?= e($t['note']) ?></p><?php endif; ?></td>
                <td class="p-3"><?= e(ucfirst($t['type'])) ?></td>
                <td class="p-3"><?= e($t['platform'] ?: 'All') ?></td>
                <td class="p-3 text-right">
                  <span class="inline-flex items-center gap-2 tabular-nums">
                    <span class="hidden sm:block w-16 h-1.5 rounded bg-line"><span class="block h-1.5 rounded bg-sea" style="width: <?= (int)$t['velocity'] ?>%"></span></span>
                    <?= (int)$t['velocity'] ?>
                  </span>
                </td>
                <td class="p-3 whitespace-nowrap"><?= e($t['seen_at'] ? date('M j', strtotime($t['seen_at'])) : '') ?></td>
                <td class="p-3 whitespace-nowrap text-right">
                  <a href="trends.php?edit=<?= (int)$t['id'] ?>" class="text-sea underline">Edit</a>
                  <form method="post" class="inline" onsubmit="return confirm('Delete this trend?')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button class="ml-3 text-fail underline">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <aside class="space-y-6">
    <form method="post" class="rounded-md border border-line bg-white p-4 sm:p-5 space-y-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <h2 class="font-semibold"><?= $edit ? 'Edit trend' : 'Add a trend' ?></h2>
      <div>
        <label for="t-name" class="text-sm font-semibold block mb-1">Name</label>
        <input id="t-name" name="name" required maxlength="150" value="<?= e($edit['name'] ?? '') ?>" class="w-full rounded-md border border-line px-3 py-2 text-sm">
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label for="t-type" class="text-sm font-semibold block mb-1">Type</label>
          <select id="t-type" name="type" class="w-full rounded-md border border-line px-2 py-2 text-sm bg-white">
            <?php foreach (Trends::TYPES as $ty): ?><option value="<?= $ty ?>" <?= ($edit['type'] ?? '') === $ty ? 'selected' : '' ?>><?= ucfirst($ty) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="t-platform" class="text-sm font-semibold block mb-1">Platform</label>
          <select id="t-platform" name="platform" class="w-full rounded-md border border-line px-2 py-2 text-sm bg-white">
            <option value="">All</option>
            <?php foreach (platforms() as $p): ?><option <?= ($edit['platform'] ?? '') === $p['name'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="t-velocity" class="text-sm font-semibold block mb-1">Rise (0–100)</label>
          <input id="t-velocity" name="velocity" type="number" min="0" max="100" value="<?= (int)($edit['velocity'] ?? 50) ?>" class="w-full rounded-md border border-line px-3 py-2 text-sm">
        </div>
        <div>
          <label for="t-seen" class="text-sm font-semibold block mb-1">Seen on</label>
          <input id="t-seen" name="seen_at" type="date" value="<?= e($edit['seen_at'] ?? date('Y-m-d')) ?>" class="w-full rounded-md border border-line px-2 py-2 text-sm">
        </div>
      </div>
      <div>
        <label for="t-note" class="text-sm font-semibold block mb-1">Why it's rising</label>
        <input id="t-note" name="note" maxlength="255" value="<?= e($edit['note'] ?? '') ?>" class="w-full rounded-md border border-line px-3 py-2 text-sm">
      </div>
      <div class="flex items-center gap-3">
        <button class="rounded-md bg-sea px-4 py-2 text-sm font-semibold text-white hover:bg-seadeep"><?= $edit ? 'Save trend' : 'Add trend' ?></button>
        <?php if ($edit): ?><a href="trends.php" class="text-sm text-muted underline">Cancel</a><?php endif; ?>
      </div>
    </form>

    <form method="post" action="import_trends.php" enctype="multipart/form-data" class="rounded-md border border-line bg-white p-4 sm:p-5 space-y-3">
      <?= csrf_field() ?>
      <h2 class="font-semibold">Import from CSV</h2>
      <p class="text-sm text-muted">Columns: type, name, platform, velocity, note, seen_at. Start from <a class="text-sea underline" href="trends_template.csv" download>the template</a>.</p>
      <label for="csv" class="sr-only">CSV file</label>
      <input id="csv" name="csv" type="file" accept=".csv,text/csv" required class="block w-full text-sm">
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="replace" value="1"> Replace all existing trends</label>
      <button class="rounded-md border border-sea text-sea px-4 py-2 text-sm font-semibold hover:bg-foam">Import trends</button>
    </form>
  </aside>
</div>
<?php page_footer(); ?>

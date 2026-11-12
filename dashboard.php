<?php
// Home: what's going out next, how posts are scoring, what keeps getting flagged, and what's rising.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

Publisher::runDue();

$pdo = db();
$business = current_business();
$accounts = accounts();
$weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
$monthAgo = date('Y-m-d H:i:s', strtotime('-30 days'));

$one = function (string $sql, array $args = []) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
};

// Headline numbers
$B = biz_where();   // every number below is for the active business only
$checksWeek   = (int)$one("SELECT COUNT(*) FROM checks WHERE $B AND created_at >= ?", [$weekStart]);
$checksTotal  = (int)$one("SELECT COUNT(*) FROM checks WHERE $B");
$avgScore     = $one("SELECT ROUND(AVG(score)) FROM checks WHERE $B AND created_at >= ?", [$monthAgo]);
$avgScore     = $avgScore === null ? null : (int)$avgScore;
$scheduledN   = (int)$one("SELECT COUNT(*) FROM scheduled_posts WHERE $B AND status = 'scheduled'");
$sentWeek     = (int)$one("SELECT COUNT(*) FROM scheduled_posts WHERE $B AND status IN ('posted','simulated') AND posted_at >= ?", [$weekStart]);
$failedN      = (int)$one("SELECT COUNT(*) FROM scheduled_posts WHERE $B AND status = 'failed'");
$trendsN      = (int)$one('SELECT COUNT(*) FROM trends');
$todayN       = (int)$one("SELECT COUNT(*) FROM scheduled_posts WHERE $B AND status = 'scheduled' AND DATE(scheduled_at) = ?", [date('Y-m-d')]);
$avgThisWeek  = $one("SELECT ROUND(AVG(score)) FROM checks WHERE $B AND created_at >= ?", [$weekStart]);
$avgLastWeek  = $one("SELECT ROUND(AVG(score)) FROM checks WHERE $B AND created_at >= ? AND created_at < ?", [date('Y-m-d 00:00:00', strtotime('monday last week')), $weekStart]);
$delta        = ($avgThisWeek !== null && $avgLastWeek !== null) ? (int)$avgThisWeek - (int)$avgLastWeek : null;

// Verdicts in the last 30 days
$verdicts = ['ready' => 0, 'fix' => 0, 'hold' => 0];
$st = $pdo->prepare("SELECT verdict, COUNT(*) n FROM checks WHERE $B AND created_at >= ? GROUP BY verdict");
$st->execute([$monthAgo]);
foreach ($st->fetchAll() as $r) {
    if (isset($verdicts[$r['verdict']])) $verdicts[$r['verdict']] = (int)$r['n'];
}
$verdictTotal = array_sum($verdicts);
$readyRate = $verdictTotal ? (int)round($verdicts['ready'] / $verdictTotal * 100) : null;

// Score trend: last 12 checks, oldest first
$scores = array_reverse(array_map('intval', $pdo->query("SELECT score FROM checks WHERE $B ORDER BY id DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN)));

// What keeps getting flagged (last 100 checks)
$issues = [];
foreach ($pdo->query("SELECT caption, rule_results, ai_results FROM checks WHERE $B ORDER BY id DESC LIMIT 100")->fetchAll() as $row) {
    foreach (decode_json($row['rule_results'])['checks'] ?? [] as $c) {
        if (($c['status'] ?? 'pass') === 'pass') continue;
        $key = str_starts_with($c['name'], 'Risky word') ? 'Risky wording' : (str_starts_with($c['name'], 'PH:') ? 'Philippine rules' : $c['name']);
        $issues[$key] = ($issues[$key] ?? 0) + 1;
    }
    $ai = decode_json($row['ai_results']);
    if (isset($ai['overall_score'])) {
        if (($ai['policy_risk']['level'] ?? 'low') !== 'low') $issues['Policy risk'] = ($issues['Policy risk'] ?? 0) + 1;
        if (($ai['brand_safety']['level'] ?? 'low') !== 'low') $issues['Brand safety'] = ($issues['Brand safety'] ?? 0) + 1;
        if (($ai['ph_compliance']['level'] ?? 'low') !== 'low') $issues['Philippine rules'] = ($issues['Philippine rules'] ?? 0) + 1;
        if (!empty($ai['disclosure_needed']) && !Preflight::hasDisclosure((string)$row['caption'])) $issues['Missing #ad'] = ($issues['Missing #ad'] ?? 0) + 1;
        if (($ai['hook_quality']['score'] ?? 100) < 60) $issues['Weak hook'] = ($issues['Weak hook'] ?? 0) + 1;
    }
}
arsort($issues);
$issues = array_slice($issues, 0, 5, true);
$issueMax = $issues ? max($issues) : 1;

// Lists
$upNext = $pdo->query("SELECT sp.*, p.name AS platform FROM scheduled_posts sp JOIN platforms p ON p.id = sp.platform_id
                       WHERE sp.status = 'scheduled' AND " . biz_where('sp') . " ORDER BY sp.scheduled_at LIMIT 6")->fetchAll();
$recentSent = $pdo->query("SELECT sp.*, p.name AS platform FROM scheduled_posts sp JOIN platforms p ON p.id = sp.platform_id
                           WHERE sp.status IN ('posted','simulated','failed') AND " . biz_where('sp') . " ORDER BY sp.posted_at DESC LIMIT 3")->fetchAll();
$recentChecks = $pdo->query('SELECT c.id, c.caption, c.score, c.verdict, c.created_at, p.name AS platform
                             FROM checks c LEFT JOIN platforms p ON p.id = c.platform_id WHERE ' . biz_where('c') . ' ORDER BY c.id DESC LIMIT 5')->fetchAll();
$rising = Trends::top(null, 5);

// First steps
$steps = [
    ['Set up your business', 'Tell Stella what you sell and how you sound.', 'profile.php', 'Add business', (bool)$business],
    ['Connect a channel', 'Link Facebook for real, or other platforms in demo mode.', 'accounts.php', 'Connect channel', (bool)$accounts],
    ['Create a post', 'Write one yourself or let AI write it, then schedule it.', 'help.php', 'Create post', $scheduledN + $sentWeek > 0 || $checksTotal > 0],
    ['Publish or schedule', 'Send a checked post to your channels.', 'history.php', 'Open Publish', (bool)$pdo->query("SELECT 1 FROM scheduled_posts WHERE $B LIMIT 1")->fetchColumn()],
];
$stepsDone = count(array_filter($steps, fn($s) => $s[4]));

$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$moon = $hour >= 18 || $hour < 5;

function day_label(string $dt): string {
    $d = date('Y-m-d', strtotime($dt));
    if ($d === date('Y-m-d')) return 'Today';
    if ($d === date('Y-m-d', strtotime('+1 day'))) return 'Tomorrow';
    return date('D, M j', strtotime($dt));
}

function when_label(string $dt): string {
    $t = strtotime($dt);
    $day = date('Y-m-d', $t);
    $time = date('g:i A', $t);
    if ($day === date('Y-m-d')) return "Today $time";
    if ($day === date('Y-m-d', strtotime('+1 day'))) return "Tomorrow $time";
    return date('M j', $t) . ", $time";
}

function thumb(?string $file): string {
    if (!$file || !Media::path($file)) return '';
    if (preg_match('/\.(mp4|mov|webm)$/', $file)) {
        return '<span class="flex w-20 h-20 shrink-0 items-center justify-center rounded-lg bg-ink text-white" aria-label="Video"><svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span>';
    }
    return '<img src="media.php?f=' . e($file) . '" alt="" class="w-20 h-20 shrink-0 rounded-lg object-cover border border-line" loading="lazy">';
}

// One plain-language sentence that sums up the day
$summary = [];
$summary[] = $todayN ? "$todayN post" . ($todayN === 1 ? '' : 's') . ' sail today' : ($scheduledN ? 'Nothing sails today' : 'Nothing is scheduled yet');
if ($avgThisWeek !== null) {
    $summary[] = 'your average score this week is ' . (int)$avgThisWeek
        . ($delta === null ? '' : ($delta === 0 ? ', same as last week' : ', ' . ($delta > 0 ? 'up ' : 'down ') . abs($delta) . ' from last week'));
}
if ($failedN) $summary[] = "$failedN post" . ($failedN === 1 ? '' : 's') . ' failed to send';
$summaryText = ucfirst(implode('; ', $summary)) . '.';

$scoreTone = $avgScore === null ? 'text-muted' : ($avgScore >= 80 ? 'text-pass' : ($avgScore >= 50 ? 'text-warn' : 'text-fail'));

page_header('Overview', 'dashboard.php');
?>
<!-- Greeting -->
<section class="flex flex-wrap items-end justify-between gap-6">
  <div class="max-w-2xl">
    <p class="text-sm text-muted"><?= date('l, j F') ?></p>
    <h1 class="font-display text-2xl sm:text-4xl lg:text-5xl font-extrabold tracking-tight mt-1"><?= e($greeting) ?><?= $business ? ', ' . e($business['name']) : '' ?>.</h1>
    <p class="mt-2 text-base sm:text-lg text-muted"><?= e($summaryText) ?></p>
  </div>
  <div class="flex gap-2">
    <a href="suggest.php" data-nav class="rounded-lg border border-line bg-white px-4 py-2.5 font-semibold hover:border-sea hover:text-sea">Get post ideas</a>
    <a href="help.php" data-nav class="rounded-lg bg-sea px-4 py-2.5 font-semibold text-white hover:bg-seadeep">Create a post</a>
  </div>
</section>

<?php $alertsNow = Insights::unseenAlerts(3); if ($alertsNow): ?>
<section class="mt-5 sm:mt-8 space-y-2" aria-label="Posts doing well">
  <?php foreach ($alertsNow as $a): ?>
    <a href="calendar.php#alerts" data-nav class="flex items-center gap-3 rounded-xl border border-sea/30 bg-foam px-4 py-3 hover:border-sea">
      <span class="flex w-9 h-9 shrink-0 items-center justify-center rounded-full bg-sea text-white" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg></span>
      <span class="flex-1 text-sm"><strong><?= $a['kind'] === 'trending' ? 'Taking off.' : 'Climbing.' ?></strong> <?= e($a['message']) ?></span>
      <span class="text-sm font-semibold text-sea">See numbers</span>
    </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($stepsDone < count($steps)): ?>
<!-- Setup progress: one slim bar, not a wall of cards -->
<section class="mt-5 sm:mt-8 rounded-xl border border-line bg-white p-4 sm:p-5" aria-labelledby="setupTitle">
  <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
    <div class="min-w-[180px]">
      <h2 id="setupTitle" class="font-semibold">Finish setting up</h2>
      <p class="text-sm text-muted"><?= $stepsDone ?> of <?= count($steps) ?> done</p>
    </div>
    <ol class="flex flex-1 flex-wrap gap-2">
      <?php foreach ($steps as [$title, $text, $href, $cta, $done]): ?>
        <li>
          <?php if ($done): ?>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-passbg px-3 py-1.5 text-sm text-pass">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg><?= e($title) ?>
            </span>
          <?php else: ?>
            <a href="<?= $href ?>" data-nav title="<?= e($text) ?>" class="inline-flex items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-sm hover:border-sea hover:text-sea">
              <span class="w-3.5 h-3.5 rounded-full border-2 border-line" aria-hidden="true"></span><?= e($title) ?>
            </a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
  <div class="mt-4 h-1 rounded-full bg-line"><div class="h-1 rounded-full bg-sea" style="width: <?= round($stepsDone / count($steps) * 100) ?>%"></div></div>
</section>
<?php endif; ?>

<!-- Key numbers -->
<section class="mt-5 sm:mt-8 grid grid-cols-2 lg:grid-cols-4 gap-px overflow-hidden rounded-xl border border-line bg-line" aria-label="Key numbers">
  <div class="bg-white p-4 sm:p-5">
    <p class="text-sm text-muted">Average score</p>
    <p class="mt-1 font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tabular-nums <?= $scoreTone ?>"><?= $avgScore ?? '–' ?></p>
    <p class="mt-1 text-xs text-muted">Last 30 days, out of 100</p>
  </div>
  <div class="bg-white p-4 sm:p-5">
    <p class="text-sm text-muted">Ready to post</p>
    <p class="mt-1 font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tabular-nums"><?= $readyRate === null ? '–' : $readyRate . '%' ?></p>
    <p class="mt-1 text-xs text-muted"><?= $verdictTotal ? "{$verdicts['ready']} of $verdictTotal checks passed clean" : 'No checks yet' ?></p>
  </div>
  <div class="bg-white p-4 sm:p-5">
    <p class="text-sm text-muted">Checks this week</p>
    <p class="mt-1 font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tabular-nums"><?= $checksWeek ?></p>
    <p class="mt-1 text-xs text-muted"><?= $checksTotal ?> all time</p>
  </div>
  <div class="bg-white p-4 sm:p-5">
    <p class="text-sm text-muted">Sent this week</p>
    <p class="mt-1 font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tabular-nums"><?= $sentWeek ?></p>
    <p class="mt-1 text-xs text-muted"><?= $scheduledN ?> waiting in the queue</p>
  </div>
</section>

<div class="mt-5 sm:mt-8 grid lg:grid-cols-[1fr_340px] gap-6">
  <div class="space-y-6 min-w-0">

    <!-- Departures board: the one bold element on this page -->
    <section class="rounded-xl border border-line bg-white overflow-hidden" aria-labelledby="depTitle">
      <div class="flex items-center justify-between gap-4 px-5 pt-5">
        <div>
          <h2 id="depTitle" class="font-display text-2xl font-extrabold">Departures</h2>
          <p class="text-sm text-muted">Posts leaving the harbour next</p>
        </div>
        <a href="calendar.php" data-nav class="text-sm text-sea underline">Calendar</a>
      </div>
      <?php if (!$upNext): ?>
        <div class="px-5 py-6 sm:py-10 text-center">
          <p class="font-semibold">The harbour is quiet</p>
          <p class="text-sm text-muted mt-1">Create a post and schedule it, or plan a week in the calendar.</p>
          <a href="index.php" data-nav class="mt-4 inline-block rounded-lg bg-sea px-4 py-2 text-sm font-semibold text-white hover:bg-seadeep">Create a post</a>
        </div>
      <?php else: ?>
        <div class="mt-4 overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-muted border-y border-line bg-paper">
                <th class="px-5 py-2 font-medium">Time</th><th class="px-3 py-2 font-medium">Channel</th><th class="px-3 py-2 font-medium">Post</th><th class="px-5 py-2 font-medium text-right">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <?php foreach ($upNext as $p): $acc = $accounts[(int)$p['platform_id']] ?? null; ?>
                <tr class="hover:bg-paper">
                  <td class="px-5 py-3 whitespace-nowrap align-top">
                    <span class="block font-display text-xl font-extrabold tabular-nums leading-tight"><?= date('g:i', strtotime($p['scheduled_at'])) ?><span class="text-sm font-semibold text-muted"> <?= date('A', strtotime($p['scheduled_at'])) ?></span></span>
                    <span class="block text-xs text-muted"><?= e(day_label($p['scheduled_at'])) ?></span>
                  </td>
                  <td class="px-3 py-3 align-top">
                    <span class="flex items-center gap-2"><?= platform_badge($p['platform'], 'w-7 h-7 text-[10px]') ?><span class="hidden sm:block truncate max-w-[120px] text-muted"><?= e($acc['handle'] ?? $p['platform']) ?></span></span>
                  </td>
                  <td class="px-3 py-3 align-top min-w-[200px]"><a href="<?= $p['check_id'] ? 'publish.php?check_id=' . (int)$p['check_id'] : 'calendar.php?month=' . date('Y-m', strtotime($p['scheduled_at'])) ?>" class="line-clamp-2 hover:underline"><?= e($p['caption']) ?></a></td>
                  <td class="px-5 py-3 align-top text-right whitespace-nowrap">
                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold <?= ($acc['mode'] ?? '') === 'live' ? 'text-pass' : 'text-sea' ?>">
                      <span class="w-1.5 h-1.5 rounded-full bg-current" aria-hidden="true"></span><?= ($acc['mode'] ?? '') === 'live' ? 'On time' : 'Demo' ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- Recent checks -->
    <section aria-labelledby="checksTitle">
      <div class="flex items-baseline justify-between mb-3">
        <h2 id="checksTitle" class="font-display text-xl font-extrabold">Recent checks</h2>
        <a href="history.php" data-nav class="text-sm text-sea underline">See all</a>
      </div>
      <?php if (!$recentChecks): ?>
        <div class="rounded-xl border border-dashed border-line bg-white p-4 sm:p-6 text-center text-sm text-muted">No checks yet. <a href="index.php" data-nav class="text-sea underline">Check your first post</a>.</div>
      <?php else: ?>
        <ul class="grid sm:grid-cols-2 gap-3">
          <?php foreach ($recentChecks as $c):
              $tone = ['ready' => 'border-l-pass', 'fix' => 'border-l-warn', 'hold' => 'border-l-fail'][$c['verdict']] ?? 'border-l-line'; ?>
            <li>
              <a href="result.php?id=<?= (int)$c['id'] ?>" class="flex h-full items-start gap-4 rounded-lg border border-line border-l-4 <?= $tone ?> bg-white p-4 hover:shadow-sm">
                <span class="font-display text-xl sm:text-2xl lg:text-3xl font-extrabold tabular-nums leading-none"><?= (int)$c['score'] ?></span>
                <span class="flex-1 min-w-0">
                  <span class="block line-clamp-2 text-sm"><?= e($c['caption'] !== '' ? $c['caption'] : 'No caption') ?></span>
                  <span class="mt-1.5 flex items-center gap-2 text-xs text-muted"><?= platform_badge((string)$c['platform'], 'w-5 h-5 text-[8px]') ?><?= e(nice_time($c['created_at'])) ?></span>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>

  <!-- Side column -->
  <div class="space-y-6">
    <section class="rounded-xl border border-line bg-white p-4 sm:p-5" aria-labelledby="healthTitle">
      <h2 id="healthTitle" class="font-display text-xl font-extrabold">Pre-flight health</h2>
      <p class="text-sm text-muted">Last 30 days</p>
      <?php if (!$verdictTotal): ?>
        <p class="text-sm text-muted mt-4">Run a few checks to see how your posts are doing.</p>
      <?php else: ?>
        <div class="mt-4 flex h-2.5 gap-0.5 overflow-hidden rounded-full" aria-hidden="true">
          <?php foreach (['ready' => 'bg-pass', 'fix' => 'bg-warn', 'hold' => 'bg-fail'] as $k => $cls): if (!$verdicts[$k]) continue; ?>
            <span class="<?= $cls ?>" style="width: <?= round($verdicts[$k] / $verdictTotal * 100, 1) ?>%"></span>
          <?php endforeach; ?>
        </div>
        <dl class="mt-3 flex justify-between text-sm">
          <div><dt class="text-muted">Ready</dt><dd class="font-semibold text-pass tabular-nums"><?= $verdicts['ready'] ?></dd></div>
          <div><dt class="text-muted">Fix first</dt><dd class="font-semibold text-warn tabular-nums"><?= $verdicts['fix'] ?></dd></div>
          <div class="text-right"><dt class="text-muted">Hold</dt><dd class="font-semibold text-fail tabular-nums"><?= $verdicts['hold'] ?></dd></div>
        </dl>
      <?php endif; ?>

      <?php if (count($scores) >= 2):
          $w = 300; $h = 70; $n = count($scores); $pts = [];
          foreach ($scores as $i => $s) $pts[] = round($i * ($w / ($n - 1)), 1) . ',' . round($h - ($s / 100) * $h, 1);
          $poly = implode(' ', $pts); [$lx, $ly] = explode(',', end($pts)); ?>
        <h3 class="mt-6 text-sm font-semibold">Score, last <?= $n ?> checks</h3>
        <svg viewBox="0 -5 <?= $w ?> <?= $h + 10 ?>" class="mt-2 w-full h-20" role="img" aria-label="Scores, oldest to newest: <?= e(implode(', ', $scores)) ?>">
          <line x1="0" x2="<?= $w ?>" y1="<?= $h * 0.2 ?>" y2="<?= $h * 0.2 ?>" stroke="#1F7A4D" stroke-dasharray="2 4" opacity=".6"/>
          <polyline points="<?= $poly ?>" fill="none" stroke="<?= BRAND['sea'] ?>" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
          <circle cx="<?= $lx ?>" cy="<?= $ly ?>" r="4" fill="<?= BRAND['sea'] ?>"/>
        </svg>
        <p class="text-xs text-muted">Dotted line is 80, the bar for ready to post.</p>
      <?php endif; ?>

      <?php if ($issues): ?>
        <h3 class="mt-6 text-sm font-semibold">Caught most often</h3>
        <ul class="mt-3 space-y-2.5">
          <?php foreach ($issues as $name => $n): ?>
            <li class="grid grid-cols-[1fr_auto] gap-x-3 text-sm">
              <span><?= e($name) ?></span><span class="tabular-nums text-muted"><?= $n ?></span>
              <span class="col-span-2 mt-1 h-1 rounded-full bg-line"><span class="block h-1 rounded-full bg-sea" style="width: <?= round($n / $issueMax * 100) ?>%"></span></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="rounded-xl border border-line bg-white p-4 sm:p-5" aria-labelledby="risingTitle">
      <div class="flex items-baseline justify-between">
        <h2 id="risingTitle" class="font-display text-xl font-extrabold">Rising now</h2>
        <a href="trends.php" data-nav class="text-sm text-sea underline">All <?= $trendsN ?></a>
      </div>
      <p class="text-sm text-muted">Fastest-growing in the Philippines</p>
      <?php if (!$rising): ?>
        <p class="text-sm text-muted mt-4">No trends yet. <a href="trends.php" data-nav class="text-sea underline">Add some</a>, or save one from <a href="scout.php" data-nav class="text-sea underline">Why it worked</a>.</p>
      <?php else: ?>
        <ol class="mt-4 space-y-3">
          <?php foreach ($rising as $i => $t): ?>
            <li class="flex items-center gap-3">
              <span class="w-5 text-sm text-muted tabular-nums"><?= $i + 1 ?></span>
              <span class="flex-1 min-w-0">
                <span class="block truncate font-semibold text-sm"><?= e($t['name']) ?></span>
                <span class="block text-xs text-muted"><?= e(ucfirst($t['type'])) ?> · <?= e($t['platform'] ?: 'All platforms') ?></span>
              </span>
              <span class="rounded bg-foam px-1.5 py-0.5 text-xs font-semibold text-seadeep tabular-nums" title="Rise score">+<?= (int)$t['velocity'] ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
        <a href="suggest.php" data-nav class="mt-5 block rounded-lg border border-sea py-2 text-center text-sm font-semibold text-sea hover:bg-foam">Turn these into post ideas</a>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php page_footer(); ?>

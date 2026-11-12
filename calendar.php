<?php
// Content calendar: every scheduled and sent post by day. Click a post for its numbers and how it's doing.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

Publisher::runDue();

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$first = strtotime($month . '-01');
$start = strtotime('monday this week', $first);              // grid starts on a Monday
$last = strtotime(date('Y-m-t', $first));
$end = strtotime('sunday this week', $last);
$prev = date('Y-m', strtotime('-1 month', $first));
$next = date('Y-m', strtotime('+1 month', $first));

$st = db()->prepare("SELECT sp.*, p.name AS platform, COALESCE(sp.posted_at, sp.scheduled_at) AS at, c.score
                     FROM scheduled_posts sp JOIN platforms p ON p.id = sp.platform_id LEFT JOIN checks c ON c.id = sp.check_id
                     WHERE COALESCE(sp.posted_at, sp.scheduled_at) BETWEEN ? AND ? AND " . biz_where('sp') . "
                     ORDER BY at");
$st->execute([date('Y-m-d 00:00:00', $start), date('Y-m-d 23:59:59', $end)]);
$byDay = [];
foreach ($st->fetchAll() as $p) $byDay[date('Y-m-d', strtotime($p['at']))][] = $p;

$insightsOn = Insights::ready();
$latest = $insightsOn ? Insights::latestAll() : [];
$state = [];
foreach ($byDay as $posts) foreach ($posts as $p) {
    $state[(int)$p['id']] = $insightsOn ? Insights::status((int)$p['id'], $latest)['key'] : 'none';
}
$alerts = Insights::unseenAlerts(6);
$accounts = accounts();
$monthCount = array_sum(array_map(fn($d) => count(array_filter($d, fn($p) => date('Y-m', strtotime($p['at'])) === $month)), $byDay));

// AI-planned drafts (not yet checked or scheduled) for the same range
$draftsOn = true;
$draftCount = 0;
try {
    $ds = db()->prepare("SELECT d.*, p.name AS platform, CONCAT(d.post_date, ' ', d.post_time) AS at
                         FROM content_drafts d JOIN platforms p ON p.id = d.platform_id
                         WHERE d.status = 'draft' AND d.post_date BETWEEN ? AND ? AND " . biz_where('d') . " ORDER BY at");
    $ds->execute([date('Y-m-d', $start), date('Y-m-d', $end)]);
    foreach ($ds->fetchAll() as $d) {
        $d['_draft'] = true;
        $byDay[$d['post_date']][] = $d;
        if (date('Y-m', strtotime($d['post_date'])) === $month) $draftCount++;
    }
    foreach ($byDay as &$list) usort($list, fn($a, $b) => strcmp($a['at'], $b['at']));
    unset($list);
} catch (PDOException $e) {
    $draftsOn = false;
}
$planPlatforms = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'connected' => isset($accounts[(int)$p['id']])], platforms());
$business = current_business();
$bizPlatforms = array_map('trim', explode(',', (string)($business['platforms'] ?? '')));

function chip(array $p, string $state): string {
    if (!empty($p['_draft'])) {
        $icon = $p['media_type'] === 'video' ? '▶' : ($p['media_type'] === 'image' ? ($p['file_path'] ? '◼' : '◻') : '');
        return '<button type="button" data-draft="' . (int)$p['id'] . '" class="draft-chip flex w-full items-center gap-1.5 rounded-md border border-dashed border-sea/60 bg-white px-1.5 py-1 text-left text-xs hover:border-sea hover:bg-foam">'
            . platform_badge($p['platform'], 'w-4 h-4 text-[7px]')
            . '<span class="tabular-nums text-muted">' . date('g:i', strtotime($p['at'])) . '</span>'
            . '<span class="flex-1 truncate">' . e(mb_strimwidth((string)($p['hook'] ?: $p['caption']), 0, 60, '…')) . '</span>'
            . ($icon ? '<span class="shrink-0 text-[10px] text-sea" aria-hidden="true">' . $icon . '</span>' : '')
            . '<span class="shrink-0 rounded bg-foam px-1 text-[10px] font-semibold text-sea">Draft</span>'
            . '</button>';
    }
    $edge = ['posted' => 'border-l-pass', 'simulated' => 'border-l-sea', 'failed' => 'border-l-fail', 'scheduled' => 'border-l-line', 'posting' => 'border-l-warn'][$p['status']] ?? 'border-l-line';
    $hot = in_array($state, ['trending', 'climbing'], true);
    return '<button type="button" data-post="' . (int)$p['id'] . '" class="post-chip group flex w-full items-center gap-1.5 rounded-md border border-line border-l-4 ' . $edge
        . ($hot ? ' bg-foam' : ' bg-white') . ' px-1.5 py-1 text-left text-xs hover:border-sea">'
        . platform_badge($p['platform'], 'w-4 h-4 text-[7px]')
        . '<span class="tabular-nums text-muted">' . date('g:i', strtotime($p['at'])) . '</span>'
        . '<span class="flex-1 truncate">' . e(mb_strimwidth((string)$p['caption'], 0, 60, '…')) . '</span>'
        . ($hot ? '<span class="shrink-0 text-sea" title="' . ($state === 'trending' ? 'Taking off' : 'Climbing') . '"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg><span class="sr-only">' . ($state === 'trending' ? 'Taking off' : 'Climbing') . '</span></span>' : '')
        . '</button>';
}

page_header('Calendar', 'calendar.php');
?>
<div class="flex flex-wrap items-end justify-between gap-4">
  <div>
    <h1 class="font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight">Content calendar</h1>
    <p class="mt-1 text-muted"><?= $monthCount ?> post<?= $monthCount === 1 ? '' : 's' ?> in <?= date('F', $first) ?><?= $draftCount ? " and $draftCount draft" . ($draftCount === 1 ? '' : 's') : '' ?>. Click a post to see how it's doing, or plan new content.</p>
  </div>
  <div class="flex flex-wrap items-center gap-3">
  <button id="planBtn" type="button" class="inline-flex items-center gap-2 rounded-lg bg-sea px-4 py-2.5 font-semibold text-white hover:bg-seadeep" aria-pressed="false">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.4z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/></svg>
    <span id="planLabel">Plan content</span>
  </button>
  <nav class="flex items-center gap-1" aria-label="Month">
    <a href="calendar.php?month=<?= $prev ?>" class="rounded-lg border border-line bg-white p-2 hover:border-sea" title="Previous month"><span class="sr-only">Previous month</span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg></a>
    <span class="min-w-[150px] text-center font-display text-xl font-extrabold"><?= date('F Y', $first) ?></span>
    <a href="calendar.php?month=<?= $next ?>" class="rounded-lg border border-line bg-white p-2 hover:border-sea" title="Next month"><span class="sr-only">Next month</span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a>
    <?php if ($month !== date('Y-m')): ?><a href="calendar.php" class="ml-2 rounded-lg border border-line bg-white px-3 py-2 text-sm font-semibold hover:border-sea">Today</a><?php endif; ?>
  </nav>
  </div>
</div>

<?php if (!$insightsOn): ?>
  <div class="mt-6 rounded-lg bg-warnbg text-warn px-4 py-3 text-sm">Import <code>insights.sql</code> in phpMyAdmin to turn on post numbers and "taking off" alerts. The calendar works without it.</div>
<?php endif; ?>

<?php if (!$draftsOn): ?>
  <div class="mt-6 rounded-lg bg-warnbg text-warn px-4 py-3 text-sm">Import <code>planner.sql</code> in phpMyAdmin to turn on content planning.</div>
<?php endif; ?>

<?php if ($alerts): ?>
<section id="alerts" class="mt-6 space-y-2" aria-label="Alerts">
  <?php foreach ($alerts as $a): ?>
    <div class="alert-row flex items-center gap-3 rounded-lg border border-sea/30 bg-foam px-4 py-3">
      <span class="flex w-9 h-9 shrink-0 items-center justify-center rounded-full bg-sea text-white" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg></span>
      <p class="flex-1 text-sm"><strong><?= $a['kind'] === 'trending' ? 'Taking off.' : 'Climbing.' ?></strong> <?= e($a['message']) ?> <span class="text-muted"><?= e(nice_time($a['created_at'])) ?></span></p>
      <button type="button" data-post="<?= (int)$a['post_id'] ?>" class="post-chip text-sm font-semibold text-sea underline">See numbers</button>
      <button type="button" data-dismiss="<?= (int)$a['id'] ?>" class="rounded p-1 text-muted hover:text-ink" title="Dismiss"><span class="sr-only">Dismiss</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<!-- Legend -->
<ul class="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-xs text-muted">
  <li class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm border-l-4 border-l-line border border-line bg-white"></span>Scheduled</li>
  <li class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-pass"></span>Posted</li>
  <li class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-sea"></span>Simulated (demo)</li>
  <li class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm bg-fail"></span>Failed</li>
  <li class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-sm border border-dashed border-sea"></span>Draft (AI planned)</li>
  <li class="flex items-center gap-1.5 text-sea"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>Taking off or climbing</li>
</ul>

<!-- Month grid (tablet and up) -->
<div class="mt-4 hidden md:block overflow-hidden rounded-xl border border-line bg-line">
  <div class="grid grid-cols-7 gap-px">
    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?>
      <div class="bg-paper px-3 py-2 text-xs font-semibold text-muted"><?= $d ?></div>
    <?php endforeach; ?>
    <?php for ($t = $start; $t <= $end; $t = strtotime('+1 day', $t)):
        $key = date('Y-m-d', $t);
        $inMonth = date('Y-m', $t) === $month;
        $isToday = $key === date('Y-m-d');
        $posts = $byDay[$key] ?? []; ?>
      <div class="day-cell relative min-h-[128px] p-2 <?= $inMonth ? 'bg-white' : 'bg-paper/70' ?>"<?= $key >= date('Y-m-d') ? ' data-date="' . $key . '"' : '' ?>>
        <span class="sel-tick pointer-events-none absolute right-2 top-2 hidden h-5 w-5 items-center justify-center rounded-full bg-sea text-white" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><path d="m5 12 5 5 9-10"/></svg></span>
        <div class="mb-1.5 flex items-center justify-between">
          <span class="inline-flex w-7 h-7 items-center justify-center rounded-full text-sm tabular-nums <?= $isToday ? 'bg-sea text-white font-semibold' : ($inMonth ? '' : 'text-muted') ?>"><?= date('j', $t) ?></span>
          <?php if ($inMonth && $t >= strtotime('today')): ?>
            <a href="help.php?mode=write&amp;date=<?= $key ?>" class="day-add hidden rounded p-0.5 text-muted hover:text-sea" title="New post on <?= date('M j', $t) ?>"><span class="sr-only">New post</span><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></a>
          <?php endif; ?>
        </div>
        <div class="space-y-1">
          <?php foreach (array_slice($posts, 0, 3) as $p) echo chip($p, $state[(int)$p['id']] ?? 'none'); ?>
          <?php if (count($posts) > 3): ?>
            <details class="text-xs">
              <summary class="cursor-pointer px-1 text-sea">+<?= count($posts) - 3 ?> more</summary>
              <div class="mt-1 space-y-1"><?php foreach (array_slice($posts, 3) as $p) echo chip($p, $state[(int)$p['id']] ?? 'none'); ?></div>
            </details>
          <?php endif; ?>
        </div>
      </div>
    <?php endfor; ?>
  </div>
</div>

<!-- Agenda list (phones) -->
<div class="mt-4 md:hidden space-y-4">
  <?php $any = false;
  for ($t = $first; $t <= $last; $t = strtotime('+1 day', $t)):
      $key = date('Y-m-d', $t);
      if (empty($byDay[$key])) continue;
      $any = true; ?>
    <section>
      <h2 class="mb-1.5 text-sm font-semibold <?= $key === date('Y-m-d') ? 'text-sea' : 'text-muted' ?>"><?= date('D, M j', $t) ?></h2>
      <div class="space-y-1.5"><?php foreach ($byDay[$key] as $p) echo chip($p, $state[(int)$p['id']] ?? 'none'); ?></div>
    </section>
  <?php endfor; ?>
  <?php if (!$any): ?>
    <div class="rounded-xl border border-dashed border-line bg-white p-4 sm:p-6 text-center text-sm text-muted">Nothing this month. <a href="help.php" class="text-sea underline">Create a post</a> or plan content.</div>
  <?php endif; ?>
</div>

<!-- Insight drawer -->
<div id="drawerScrim" data-portal class="fixed inset-0 z-40 hidden bg-ink/25"></div>
<aside id="drawer" data-portal class="invisible fixed inset-y-0 right-0 z-50 flex w-full max-w-md translate-x-full flex-col bg-white shadow-2xl transition-transform duration-300" role="dialog" aria-modal="true" aria-labelledby="dTitle" tabindex="-1">
  <div class="flex items-center gap-3 border-b border-line px-5 py-4">
    <span id="dBadge"></span>
    <div class="flex-1 min-w-0">
      <h2 id="dTitle" class="truncate font-semibold">Post</h2>
      <p id="dWhen" class="text-xs text-muted"></p>
    </div>
    <button id="dClose" type="button" class="rounded p-1.5 text-muted hover:bg-paper hover:text-ink"><span class="sr-only">Close</span><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
  </div>
  <div id="dBody" class="flex-1 overflow-y-auto px-5 py-5"></div>
</aside>

<!-- Toast for new alerts -->
<div id="toast" data-portal class="pointer-events-none fixed right-4 top-20 z-[60] w-[min(92vw,360px)] space-y-2" aria-live="polite"></div>

<style>
  @media (min-width: 768px) { .grid > div:hover .day-add { display: inline-flex; } }
  .toast-in { animation: toastIn .4s cubic-bezier(.2,.8,.2,1) both; }
  @keyframes toastIn { from { opacity: 0; transform: translateY(-10px) scale(.98); } to { opacity: 1; transform: none; } }
  .bump { animation: bump .5s cubic-bezier(.2,1.6,.4,1); }
  @keyframes bump { 0% { transform: scale(1); } 40% { transform: scale(1.12); } 100% { transform: scale(1); } }
  @media (prefers-reduced-motion: reduce) { .toast-in, .bump { animation: none !important; } #drawer { transition: none !important; } }
</style>

<script>
const drawer = document.getElementById('drawer'), dScrim = document.getElementById('drawerScrim'), dBody = document.getElementById('dBody');
const esc = escapeHtml;
let lastFocus = null, currentId = null;

function openDrawer() {
  lastFocus = document.activeElement;
  drawer.classList.remove('invisible');
  requestAnimationFrame(() => drawer.classList.remove('translate-x-full'));
  dScrim.classList.remove('hidden');
  drawer.focus();
}
function closeDrawer() {
  drawer.classList.add('translate-x-full'); dScrim.classList.add('hidden');
  setTimeout(() => { if (!currentId) drawer.classList.add('invisible'); }, 320);
  if (window.onDrawerClosed) window.onDrawerClosed();
  currentId = null;
  if (lastFocus) lastFocus.focus();
}
document.getElementById('dClose').addEventListener('click', closeDrawer);
dScrim.addEventListener('click', closeDrawer);
document.addEventListener('keydown', e => { if (e.key === 'Escape' && currentId) closeDrawer(); });

const BADGES = { 'TikTok': ['TT', '#111827'], 'Instagram': ['IG', '#C13584'], 'Facebook': ['FB', '#1877F2'], 'YouTube Shorts': ['YT', '#DC2626'] };
function badge(name) {
  const [l, c] = BADGES[name] || [name.slice(0, 2).toUpperCase(), '#0E1433'];
  return `<span class="inline-flex w-9 h-9 items-center justify-center rounded-full text-[11px] font-bold text-white" style="background:${c}" aria-hidden="true">${esc(l)}</span>`;
}
const STATUS = { posted: ['Posted', 'bg-passbg text-pass'], simulated: ['Simulated', 'bg-foam text-seadeep'], scheduled: ['Scheduled', 'bg-paper text-muted'], failed: ['Failed', 'bg-failbg text-fail'], posting: ['Posting', 'bg-warnbg text-warn'] };
const SOURCE = { facebook: 'From Facebook', manual: 'Entered by you', sample: 'Sample numbers, not real' };
const fmt = n => Number(n).toLocaleString();

function spark(hist) {
  if (hist.length < 2) return '';
  const w = 300, h = 60, max = Math.max(...hist.map(x => x.engagement), 1);
  const pts = hist.map((x, i) => `${(i * w / (hist.length - 1)).toFixed(1)},${(h - x.engagement / max * h).toFixed(1)}`).join(' ');
  const [lx, ly] = pts.split(' ').pop().split(',');
  return `<h3 class="mt-6 text-sm font-semibold">Engagement over time</h3>
    <svg viewBox="0 -5 ${w} ${h + 10}" class="mt-2 w-full h-16" role="img" aria-label="Engagement: ${hist.map(x => x.engagement).join(', ')}">
      <polyline points="${pts}" fill="none" stroke="#5236E0" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
      <circle cx="${lx}" cy="${ly}" r="4" fill="#5236E0"/></svg>
    <p class="text-xs text-muted">${hist.length} updates, first ${esc(hist[0].at)}</p>`;
}

function render(p) {
  currentId = p.id;
  document.getElementById('dBadge').innerHTML = badge(p.platform);
  document.getElementById('dTitle').textContent = `${p.platform} · ${p.handle}`;
  const [sl, sc] = STATUS[p.status] || [p.status, 'bg-paper text-muted'];
  document.getElementById('dWhen').innerHTML = `${esc(p.when)} <span class="ml-1 rounded px-1.5 py-0.5 font-semibold ${sc}">${esc(sl)}</span>`;

  const st = p.state || {};
  const hot = st.key === 'trending' || st.key === 'climbing';
  const L = p.latest;
  let html = '';

  if (hot) {
    html += `<div class="bump mb-5 flex items-center gap-3 rounded-lg bg-sea px-4 py-3 text-white">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg>
      <p class="text-sm"><strong>${st.key === 'trending' ? 'Taking off.' : 'Climbing.'}</strong> ${st.key === 'trending'
        ? `${Number(st.ratio).toFixed(1)}× your usual engagement.`
        : `Engagement up ${Math.round(st.growth * 100)}% since the last update.`}</p></div>`;
  }

  html += `<div class="flex gap-4">
    ${p.media ? (p.is_video ? `<video src="${esc(p.media)}" class="w-20 h-28 shrink-0 rounded-md bg-black object-cover" muted playsinline></video>` : `<img src="${esc(p.media)}" alt="" class="w-20 h-20 shrink-0 rounded-md border border-line object-cover">`) : ''}
    <p class="text-sm leading-relaxed whitespace-pre-wrap line-clamp-6">${esc(p.caption)}</p></div>`;

  if (!p.sent) {
    html += `<div class="mt-6 rounded-lg border border-dashed border-line p-4 sm:p-5 text-center text-sm text-muted">Numbers appear once this post is sent.</div>`;
  } else {
    html += `<div class="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-line bg-line">
      ${['views', 'likes', 'comments', 'shares'].map(k => `<div class="bg-white p-3"><p class="text-xs text-muted">${k[0].toUpperCase() + k.slice(1)}</p>
        <p class="font-display text-2xl font-extrabold tabular-nums">${L ? fmt(L[k]) : '–'}</p></div>`).join('')}</div>`;
    if (L) {
      html += `<p class="mt-2 text-xs ${L.source === 'sample' ? 'text-warn font-semibold' : 'text-muted'}">${esc(SOURCE[L.source] || L.source)} · updated ${esc(L.at)}</p>
        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
          <div class="rounded-lg bg-paper p-3"><dt class="text-xs text-muted">Engagement</dt><dd class="font-semibold tabular-nums">${fmt(p.engagement)}</dd></div>
          <div class="rounded-lg bg-paper p-3"><dt class="text-xs text-muted">Your usual</dt><dd class="font-semibold tabular-nums">${p.baseline !== null ? fmt(p.baseline) : 'Not enough posts yet'}</dd></div>
        </dl>
        <p class="mt-1 text-xs text-muted">Engagement counts likes, plus comments twice and shares three times.</p>`;
    }
    if (p.versus) html += `<a href="result.php?id=${p.check_id}" class="mt-4 flex items-center justify-between rounded-lg border border-line px-3 py-2.5 text-sm hover:border-sea"><span>${esc(p.versus)}</span><span class="text-sea">Open check</span></a>`;
    html += spark(p.history);

    html += `<div class="mt-6 border-t border-line pt-5">`;
    if (p.can_refresh) {
      html += `<button type="button" data-act="refresh" class="act w-full rounded-lg bg-sea py-2.5 font-semibold text-white hover:bg-seadeep">Refresh from Facebook</button>`;
    }
    html += `<form id="manualForm" class="${p.can_refresh ? 'mt-5' : ''}">
        <p class="text-sm font-semibold">${p.can_refresh ? 'Or enter numbers yourself' : 'Enter the latest numbers'}</p>
        <p class="text-xs text-muted">Copy them from ${esc(p.platform)}'s own insights.</p>
        <div class="mt-3 grid grid-cols-4 gap-2">
          ${['views', 'likes', 'comments', 'shares'].map(k => `<label class="text-xs text-muted">${k[0].toUpperCase() + k.slice(1)}
            <input name="${k}" inputmode="numeric" pattern="[0-9]*" class="mt-1 w-full rounded-md border border-line px-2 py-1.5 text-sm text-ink tabular-nums" value="${L ? L[k] : ''}"></label>`).join('')}
        </div>
        <button class="mt-3 w-full rounded-lg border border-sea py-2 text-sm font-semibold text-sea hover:bg-foam">Save numbers</button>
      </form>
      <details class="mt-5 rounded-lg bg-paper px-3 py-2 text-sm">
        <summary class="cursor-pointer font-semibold">Demo tools</summary>
        <p class="mt-2 text-xs text-muted">For showing the feature without real data. These are saved and labelled as sample numbers.</p>
        <div class="mt-3 flex gap-2">
          <button type="button" data-act="sample" class="act flex-1 rounded-md border border-line bg-white py-1.5 hover:border-sea">Fill sample numbers</button>
          <button type="button" data-act="spike" class="act flex-1 rounded-md border border-line bg-white py-1.5 hover:border-sea">Simulate a spike</button>
        </div>
      </details>
    </div>`;
  }
  html += `<p id="dError" role="alert" class="mt-3 text-sm text-fail"></p>
    ${p.check_id ? `<a href="result.php?id=${p.check_id}" class="mt-4 inline-block text-sm text-sea underline">Open its pre-flight check</a>` : '<p class="mt-4 text-xs text-muted">Scheduled without a pre-flight check.</p>'}`;
  dBody.innerHTML = html;

  dBody.querySelectorAll('.act').forEach(b => b.addEventListener('click', () => call(b.dataset.act, {}, b)));
  const mf = document.getElementById('manualForm');
  if (mf) mf.addEventListener('submit', ev => {
    ev.preventDefault();
    const vals = Object.fromEntries(new FormData(mf).entries());
    call('manual', vals, mf.querySelector('button'));
  });
}

function toast(a) {
  const el = document.createElement('div');
  el.className = 'toast-in pointer-events-auto flex items-start gap-3 rounded-lg bg-white p-4 shadow-xl ring-1 ring-line';
  el.innerHTML = `<span class="flex w-8 h-8 shrink-0 items-center justify-center rounded-full bg-sea text-white" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/></svg></span>
    <p class="text-sm"><strong>${a.kind === 'trending' ? 'Heads up: taking off!' : 'Heads up: climbing'}</strong><br>${esc(a.message)}</p>`;
  document.getElementById('toast').appendChild(el);
  setTimeout(() => { el.style.transition = 'opacity .3s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 6000);
}

async function call(action, extra, btn) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('post_id', currentId);
  Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
  if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Working…'; }
  try {
    const data = await api('insights.php', fd);
    render(data.post);
    (data.alerts || []).forEach(toast);
    if ((data.alerts || []).length) setBell(data.unseen);
  } catch (e) {
    const err = document.getElementById('dError');
    if (err) err.textContent = e.message; else dBody.innerHTML = `<p class="text-sm text-fail">${esc(e.message)}</p>`;
    if (btn) { btn.disabled = false; btn.textContent = btn.dataset.label; }
  }
}

function setBell(n) {
  const b = document.getElementById('bellCount');
  if (!b) return;
  b.textContent = n;
  b.classList.toggle('hidden', !n);
}

document.querySelectorAll('.post-chip').forEach(c => c.addEventListener('click', async () => {
  currentId = c.dataset.post;
  dBody.innerHTML = '<p class="text-sm text-muted">Loading…</p>';
  document.getElementById('dTitle').textContent = 'Post';
  document.getElementById('dWhen').textContent = '';
  openDrawer();
  await call('get', {}, null);
}));

document.querySelectorAll('[data-dismiss]').forEach(b => b.addEventListener('click', async () => {
  const fd = new FormData();
  fd.append('action', 'dismiss');
  fd.append('alert_id', b.dataset.dismiss);
  try { const d = await api('insights.php', fd); b.closest('.alert-row').remove(); setBell(d.unseen); } catch (e) {}
}));
</script>

<!-- Planning: selection bar -->
<div id="planBar" data-portal class="pointer-events-none fixed inset-x-0 bottom-20 lg:bottom-5 z-40 flex justify-center px-4 opacity-0 translate-y-4 transition duration-200">
  <div class="pointer-events-auto flex items-center gap-3 rounded-full bg-ink py-2 pl-5 pr-2 text-sm text-white shadow-2xl">
    <span id="planCount">Tap dates to plan</span>
    <button id="planClear" type="button" class="rounded-full px-3 py-1.5 text-white/70 hover:text-white">Clear</button>
    <button id="planGo" type="button" class="rounded-full bg-sea px-4 py-1.5 font-semibold hover:bg-seadeep disabled:opacity-50" disabled>Generate content</button>
  </div>
</div>

<style>
  .planning .day-cell[data-date] { cursor: pointer; }
  .planning .day-cell[data-date]:hover { box-shadow: inset 0 0 0 2px <?= BRAND['line'] ?>; }
  .day-cell.selected { box-shadow: inset 0 0 0 2px <?= BRAND['sea'] ?> !important; background: <?= BRAND['foam'] ?> !important; }
  .day-cell.selected .sel-tick { display: inline-flex; }
  .planning .day-cell:not([data-date]) { opacity: .55; }
  #planBar.show { opacity: 1; transform: none; }
</style>

<script>
const PLAN_PLATFORMS = <?= json_encode($planPlatforms, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const BIZ_PLATFORMS = <?= json_encode($bizPlatforms, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const IMAGE_ON = <?= ImageGen::configured() ? 'true' : 'false' ?>;
const MAX_DATES = 7;
const selected = new Set();
let planning = false, needsReload = false;

const planBtn = document.getElementById('planBtn'), planBar = document.getElementById('planBar');
function setPlanning(on) {
  planning = on;
  document.body.classList.toggle('planning', on);
  planBar.classList.toggle('show', on);
  planBtn.setAttribute('aria-pressed', String(on));
  document.getElementById('planLabel').textContent = on ? 'Done selecting' : 'Plan content';
  if (!on) { selected.clear(); syncSelection(); }
}
function syncSelection() {
  document.querySelectorAll('.day-cell[data-date]').forEach(c => c.classList.toggle('selected', selected.has(c.dataset.date)));
  const n = selected.size;
  document.getElementById('planCount').textContent = n ? `${n} date${n === 1 ? '' : 's'} selected${n >= MAX_DATES ? ' (max)' : ''}` : 'Tap dates to plan';
  document.getElementById('planGo').disabled = !n;
}
planBtn.addEventListener('click', () => {
  // On phones there's no grid: go straight to the planner and pick dates there
  if (!matchMedia('(min-width: 768px)').matches) { openPlanner([]); return; }
  setPlanning(!planning);
});
document.getElementById('planClear').addEventListener('click', () => { selected.clear(); syncSelection(); });
document.getElementById('planGo').addEventListener('click', () => openPlanner([...selected].sort()));
document.querySelectorAll('.day-cell[data-date]').forEach(cell => cell.addEventListener('click', ev => {
  if (!planning || ev.target.closest('button, a, details')) return;
  const d = cell.dataset.date;
  if (selected.has(d)) selected.delete(d);
  else if (selected.size < MAX_DATES) selected.add(d);
  syncSelection();
}));

// ---------- Planner form (in the drawer) ----------
function fmtDate(d) { return new Date(d + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' }); }
function seg(name, options, value) {
  return `<div class="inline-flex flex-wrap rounded-lg border border-line bg-paper p-1">${options.map(([v, l]) => `<label class="cursor-pointer">
    <input type="radio" name="${name}" value="${esc(v)}" class="peer sr-only" ${v === value ? 'checked' : ''}>
    <span class="block rounded-md px-3 py-1.5 text-sm font-semibold text-muted peer-checked:bg-white peer-checked:text-sea peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-sea">${esc(l)}</span></label>`).join('')}</div>`;
}
let planDates = [];
const planTimes = {};   // date -> "HH:MM" when the user sets times themselves
const manualTimes = () => document.querySelector('#planForm input[name=time_mode]:checked')?.value === 'manual';

function openPlanner(dates) {
  planDates = dates.slice(0, MAX_DATES);
  document.getElementById('dBadge').innerHTML = '<span class="flex h-9 w-9 items-center justify-center rounded-full bg-foam text-sea" aria-hidden="true">✦</span>';
  document.getElementById('dTitle').textContent = 'Plan content';
  document.getElementById('dWhen').textContent = 'One AI-written draft per date';
  const defaults = PLAN_PLATFORMS.filter(p => BIZ_PLATFORMS.includes(p.name)).map(p => p.id);
  dBody.innerHTML = `<form id="planForm" class="space-y-6" novalidate>
    <div>
      <p class="text-sm font-semibold">Dates</p>
      <div id="dateChips" class="mt-2 flex flex-wrap gap-2"></div>
      <div class="mt-2 flex gap-2">
        <label class="sr-only" for="addDate">Add a date</label>
        <input id="addDate" type="date" min="${new Date().toISOString().slice(0, 10)}" class="rounded-md border border-line px-2 py-1.5 text-sm">
        <button type="button" id="addDateBtn" class="rounded-md border border-line px-3 py-1.5 text-sm font-semibold hover:border-sea">Add date</button>
      </div>
      <p class="mt-1 text-xs text-muted">Up to ${MAX_DATES} dates per batch.</p>
    </div>
    <fieldset>
      <legend class="text-sm font-semibold">Platforms</legend>
      <div class="mt-2 flex flex-wrap gap-2">${PLAN_PLATFORMS.map(p => `<label class="cursor-pointer">
        <input type="checkbox" name="platform_ids[]" value="${p.id}" class="peer sr-only" ${(defaults.length ? defaults.includes(p.id) : p.connected) ? 'checked' : ''}>
        <span class="inline-flex items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-sm peer-checked:border-sea peer-checked:bg-foam peer-checked:font-semibold peer-focus-visible:ring-2 peer-focus-visible:ring-sea">${esc(p.name)}${p.connected ? '' : ' <span class="text-xs font-normal text-muted">(not connected)</span>'}</span></label>`).join('')}</div>
    </fieldset>
    <fieldset><legend class="text-sm font-semibold">Posting time</legend><div class="mt-2">${seg('time_mode', [['ai', 'AI suggests the best time'], ['manual', 'I’ll set the times']], 'ai')}</div>
      <p class="mt-1 text-xs text-muted">Setting them yourself? A time box appears next to each date above.</p></fieldset>
    <fieldset><legend class="text-sm font-semibold">Visual for each post</legend><div class="mt-2">${seg('media', [['none', 'Text only'], ['image', 'Image'], ['video', 'Video']], 'image')}</div>
      <p class="mt-1 text-xs text-muted">Image or video, one per batch. Videos get a shot list and an AI cover frame.</p></fieldset>
    <label class="flex items-center gap-2 text-sm ${IMAGE_ON ? '' : 'opacity-50'}"><input type="checkbox" name="auto_images" value="1" ${IMAGE_ON ? 'checked' : 'disabled'} class="accent-sea"> Generate the images now${IMAGE_ON ? '' : ' (add an image API key in config.php)'}</label>
    <fieldset><legend class="text-sm font-semibold">Language</legend><div class="mt-2">${seg('language', [['English', 'English'], ['Taglish', 'Taglish'], ['Fun Taglish', 'Fun Taglish']], 'Taglish')}</div></fieldset>
    <div>
      <label for="planTheme" class="text-sm font-semibold">Theme or campaign <span class="font-normal text-muted">(optional)</span></label>
      <textarea id="planTheme" name="theme" rows="2" maxlength="500" class="mt-2 w-full rounded-md border border-line px-3 py-2 text-sm" placeholder="Example: 12.12 sale week, or rainy-season pet care tips"></textarea>
    </div>
    <button id="planSubmit" class="w-full rounded-lg bg-sea py-3 font-semibold text-white hover:bg-seadeep disabled:opacity-50">Generate drafts</button>
    <p id="dError" role="alert" class="text-sm text-fail"></p>
  </form>`;
  document.querySelectorAll('#planForm input[name=time_mode]').forEach(r => r.addEventListener('change', renderDateChips));
  renderDateChips();
  document.getElementById('addDateBtn').addEventListener('click', () => {
    const v = document.getElementById('addDate').value;
    if (v && !planDates.includes(v) && planDates.length < MAX_DATES) { planDates.push(v); planDates.sort(); renderDateChips(); }
  });
  document.getElementById('planForm').addEventListener('submit', runPlan);
  currentId = 'planner';
  openDrawer();
}
function renderDateChips() {
  const box = document.getElementById('dateChips');
  const manual = manualTimes();
  box.innerHTML = planDates.length ? planDates.map(d => `<span class="inline-flex items-center gap-1 rounded-full bg-foam py-1 pl-3 pr-1 text-sm font-semibold text-seadeep">${esc(fmtDate(d))}
    ${manual ? `<input type="time" data-time="${d}" value="${esc(planTimes[d] || '')}" required aria-label="Time on ${esc(fmtDate(d))}" class="ml-1 rounded-md border border-line bg-white px-1.5 py-0.5 text-sm font-normal text-ink">` : ''}
    <button type="button" data-rm="${d}" class="rounded-full px-1.5 text-muted hover:text-ink" aria-label="Remove ${esc(fmtDate(d))}">×</button></span>`).join('') : '<span class="text-sm text-muted">No dates yet</span>';
  box.querySelectorAll('[data-time]').forEach(i => i.addEventListener('input', () => { planTimes[i.dataset.time] = i.value; }));
  box.querySelectorAll('[data-rm]').forEach(b => b.addEventListener('click', () => { planDates = planDates.filter(x => x !== b.dataset.rm); renderDateChips(); }));
  const sub = document.getElementById('planSubmit');
  if (sub) { sub.disabled = !planDates.length; sub.textContent = planDates.length ? `Generate ${planDates.length} draft${planDates.length === 1 ? '' : 's'}` : 'Add a date first'; }
}

async function runPlan(ev) {
  ev.preventDefault();
  const form = ev.target, err = document.getElementById('dError'), sub = document.getElementById('planSubmit');
  err.textContent = '';
  const fd = new FormData(form);
  if (!fd.getAll('platform_ids[]').length) { err.textContent = 'Pick at least one platform.'; return; }
  if (manualTimes() && planDates.some(d => !planTimes[d])) { err.textContent = 'Set a time for every date, or let the AI suggest them.'; return; }
  fd.append('action', 'generate');
  planDates.forEach(d => { fd.append('dates[]', d); if (manualTimes() && planTimes[d]) fd.append(`times[${d}]`, planTimes[d]); });
  const media = fd.get('media'), autoImages = fd.get('auto_images') === '1';
  sub.disabled = true;
  sub.innerHTML = '<span class="inline-flex items-center gap-2"><svg class="spin" width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="40 20"/></svg>Writing your drafts…</span>';
  try {
    const data = await api('planner.php', fd);
    needsReload = true;
    renderPlanned(data.drafts, media !== 'none' && autoImages && data.image_on);
    setPlanning(false);
  } catch (e) {
    err.textContent = e.message;
    renderDateChips();
  }
}

// ---------- Generated drafts: review, images, check + schedule ----------
function draftCard(d) {
  const thumb = d.media_type === 'none' ? '' : `<div class="dthumb relative w-20 shrink-0 overflow-hidden rounded-md border border-dashed border-line bg-paper aspect-[4/5]">
      ${d.url ? `<img src="${esc(d.url)}" alt="" class="absolute inset-0 h-full w-full object-cover">` : `<span class="absolute inset-0 grid place-items-center text-[10px] text-muted">${d.media_type === 'video' ? 'Cover' : 'Image'}</span>`}</div>`;
  return `<li class="dcard flex gap-3 rounded-lg border border-line p-3" data-id="${d.id}">
    ${thumb}
    <div class="min-w-0 flex-1">
      <p class="text-xs text-muted">${esc(d.date_label)} · ${esc(d.time)} · ${esc(d.platform)}${d.format ? ' · ' + esc(d.format) : ''}</p>
      <p class="mt-0.5 font-semibold leading-snug">${esc(d.hook || d.caption.slice(0, 80))}</p>
      <p class="mt-1 line-clamp-2 text-sm text-muted">${esc(d.caption)}</p>
      <p class="dstatus mt-1.5 text-xs"></p>
      <button type="button" data-open="${d.id}" class="mt-1 text-xs font-semibold text-sea underline">Edit</button>
    </div>
  </li>`;
}
async function genImageFor(d, li) {
  const st = li.querySelector('.dstatus'), th = li.querySelector('.dthumb');
  if (!d.image_prompt) return;
  st.textContent = 'Generating image…';
  if (th) th.innerHTML = '<div class="shimmer absolute inset-0"></div>';
  const fd = new FormData();
  fd.append('prompt', d.image_prompt);
  fd.append('shape', 'portrait');
  fd.append('draft_id', d.id);
  try {
    const r = await api('image.php', fd);
    d.url = r.url; d.file = r.file;
    if (th) th.innerHTML = `<img src="${esc(r.url)}" alt="" class="absolute inset-0 h-full w-full object-cover">`;
    st.textContent = '';
  } catch (e) {
    if (th) th.innerHTML = '<span class="absolute inset-0 grid place-items-center text-[10px] text-fail">Failed</span>';
    st.innerHTML = `<span class="text-fail">${esc(e.message)}</span>`;
  }
}
function renderPlanned(drafts, autoImages) {
  document.getElementById('dTitle').textContent = `${drafts.length} draft${drafts.length === 1 ? '' : 's'} ready`;
  document.getElementById('dWhen').textContent = 'Saved to your calendar as drafts';
  dBody.innerHTML = `<p class="text-sm text-muted">Review them, then run every draft through the pre-flight check and schedule the ones that pass.</p>
    <ul id="dList" class="mt-4 space-y-3">${drafts.map(draftCard).join('')}</ul>
    <label class="mt-5 flex items-center gap-2 text-sm"><input type="checkbox" id="checkFirst" class="accent-sea"> Run a pre-flight check on each first <span class="text-muted">(optional)</span></label>
    <button id="scheduleAll" type="button" class="mt-3 w-full rounded-lg bg-sea py-3 font-semibold text-white hover:bg-seadeep disabled:opacity-50">Schedule all</button>
    <button id="closeReload" type="button" class="mt-2 w-full rounded-lg border border-line py-2.5 text-sm font-semibold hover:border-sea">Close and view calendar</button>
    <p class="mt-3 text-xs text-muted">Posts on platforms you haven't connected stay as drafts. With the check on, anything that gets a "Don't post yet" verdict also stays a draft, with a link to see why.</p>`;
  const byId = Object.fromEntries(drafts.map(d => [d.id, d]));
  dBody.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => openDraft(b.dataset.open)));
  document.getElementById('closeReload').addEventListener('click', () => location.reload());
  document.getElementById('scheduleAll').addEventListener('click', async ev => {
    const btn = ev.currentTarget;
    btn.disabled = true;
    const check = document.getElementById('checkFirst').checked;
    for (const d of drafts) {
      const li = dBody.querySelector(`.dcard[data-id="${d.id}"]`), st = li.querySelector('.dstatus');
      st.innerHTML = `<span class="text-muted">${check ? 'Checking…' : 'Scheduling…'}</span>`;
      st.innerHTML = await scheduleDraft(d.id, false, check);
    }
    btn.textContent = 'All done';
  });
  if (autoImages) (async () => { for (const d of drafts) await genImageFor(d, dBody.querySelector(`.dcard[data-id="${d.id}"]`)); })();
}

async function scheduleDraft(id, force = false, check = false) {
  const fd = new FormData();
  fd.append('action', 'schedule');
  fd.append('draft_id', id);
  if (force) fd.append('force', '1');
  if (check) fd.append('check', '1');
  try {
    const r = await api('planner.php', fd);
    needsReload = true;
    if (r.held) return `<span class="text-fail font-semibold">Held, score ${r.score}.</span> <a class="text-sea underline" href="result.php?id=${r.check_id}">See why</a>`;
    return r.check_id
      ? `<span class="text-pass font-semibold">Scheduled, score ${r.score}.</span> <a class="text-sea underline" href="result.php?id=${r.check_id}">Check</a>`
      : '<span class="text-pass font-semibold">Scheduled.</span>';
  } catch (e) {
    return `<span class="text-fail">${esc(e.message)}</span>`;
  }
}

// ---------- One draft: edit, image, check + schedule, delete ----------
async function openDraft(id) {
  currentId = 'draft';
  if (drawer.classList.contains('invisible')) openDrawer();
  dBody.innerHTML = '<p class="text-sm text-muted">Loading…</p>';
  const fd = new FormData();
  fd.append('action', 'get');
  fd.append('draft_id', id);
  try { renderDraft((await api('planner.php', fd)).draft); }
  catch (e) { dBody.innerHTML = `<p class="text-sm text-fail">${esc(e.message)}</p>`; }
}
function renderDraft(d) {
  document.getElementById('dBadge').innerHTML = badge(d.platform);
  document.getElementById('dTitle').textContent = d.hook || 'Draft';
  document.getElementById('dWhen').innerHTML = `${esc(d.date_label)}, ${esc(d.time)} <span class="ml-1 rounded bg-foam px-1.5 py-0.5 font-semibold text-sea">${d.status === 'draft' ? 'Draft' : 'Scheduled'}</span>`;
  const editable = d.status === 'draft';
  dBody.innerHTML = `<form id="draftForm" class="space-y-4" novalidate>
    ${d.media_type !== 'none' ? `<div class="flex gap-4">
      <div id="dImg" class="relative w-28 shrink-0 overflow-hidden rounded-lg border border-dashed border-line bg-paper aspect-[4/5]">
        ${d.url ? `<img src="${esc(d.url)}" alt="" class="absolute inset-0 h-full w-full object-cover">` : `<span class="absolute inset-0 grid place-items-center p-2 text-center text-xs text-muted">${d.media_type === 'video' ? 'No cover yet' : 'No image yet'}</span>`}
      </div>
      <div class="min-w-0 flex-1 text-sm">
        <p class="font-semibold">${d.media_type === 'video' ? 'Video' : 'Image'}</p>
        <p class="text-muted">${esc(d.visual)}</p>
        ${d.shots.length ? `<ol class="mt-2 list-decimal space-y-0.5 pl-4 text-xs">${d.shots.map(x => `<li>${esc(x)}</li>`).join('')}</ol>` : ''}
        ${editable ? `<button type="button" id="dGen" class="mt-2 rounded-md border border-sea px-3 py-1.5 text-xs font-semibold text-sea hover:bg-foam disabled:opacity-50" ${IMAGE_ON && d.image_prompt ? '' : 'disabled'}>${d.url ? 'Regenerate' : (d.media_type === 'video' ? 'Generate cover' : 'Generate image')}</button>` : ''}
        ${IMAGE_ON ? '' : '<p class="mt-1 text-xs text-muted">Add an image API key in config.php to generate.</p>'}
      </div></div>` : ''}
    <div class="grid grid-cols-3 gap-2">
      <label class="text-xs text-muted">Date<input name="date" type="date" value="${esc(d.date)}" ${editable ? '' : 'disabled'} class="mt-1 w-full rounded-md border border-line px-2 py-1.5 text-sm text-ink"></label>
      <label class="text-xs text-muted">Time<input name="time" type="time" value="${esc(d.time)}" ${editable ? '' : 'disabled'} class="mt-1 w-full rounded-md border border-line px-2 py-1.5 text-sm text-ink"></label>
      <label class="text-xs text-muted">Platform<select name="platform_id" ${editable ? '' : 'disabled'} class="mt-1 w-full rounded-md border border-line bg-white px-1 py-1.5 text-sm text-ink">${PLAN_PLATFORMS.map(p => `<option value="${p.id}" ${p.id === d.platform_id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
    </div>
    ${editable ? `<div><button type="button" id="dSuggestTime" class="inline-flex items-center gap-1.5 text-xs font-semibold text-sea underline">Suggest the best time with AI</button><p id="dWhy" class="mt-1 text-xs text-muted"></p></div>` : ''}
    <label class="block text-xs text-muted">Caption<textarea name="caption" rows="6" ${editable ? '' : 'disabled'} class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm leading-relaxed text-ink">${esc(d.caption)}</textarea></label>
    <label class="block text-xs text-muted">Hashtags<input name="hashtags" value="${esc(d.hashtags)}" ${editable ? '' : 'disabled'} class="mt-1 w-full rounded-md border border-line px-3 py-2 text-sm text-sea"></label>
    ${editable ? `<div class="flex flex-wrap gap-2">
      <button type="submit" class="rounded-lg border border-line px-4 py-2 text-sm font-semibold hover:border-sea">Save</button>
      <button type="button" id="dSchedule" class="flex-1 rounded-lg bg-sea px-4 py-2 text-sm font-semibold text-white hover:bg-seadeep">Schedule</button>
    </div>
    <button type="button" id="dCheckSchedule" class="w-full rounded-lg border border-line px-4 py-2 text-sm font-semibold hover:border-sea hover:text-sea">Check first, then schedule <span class="font-normal text-muted">(optional)</span></button>
    <button type="button" id="dDelete" class="text-sm text-fail underline">Delete draft</button>` : `<a href="result.php?id=${d.check_id}" class="text-sm text-sea underline">Open its pre-flight check</a>`}
    <p id="dMsg" role="status" class="text-sm"></p>
    <p id="dError" role="alert" class="text-sm text-fail"></p>
  </form>`;
  if (!editable) return;
  const form = document.getElementById('draftForm');
  const save = async () => {
    const fd = new FormData(form);
    fd.append('action', 'update');
    fd.append('draft_id', d.id);
    const r = await api('planner.php', fd);
    needsReload = true;
    return r.draft;
  };
  form.addEventListener('submit', async ev => {
    ev.preventDefault();
    try { renderDraft(await save()); document.getElementById('dMsg').innerHTML = '<span class="text-pass">Saved.</span>'; }
    catch (e) { document.getElementById('dError').textContent = e.message; }
  });
  const doSchedule = async (btn, check) => {
    const label = btn.innerHTML;
    btn.disabled = true;
    btn.textContent = check ? 'Checking…' : 'Scheduling…';
    try { await save(); } catch (e) { document.getElementById('dError').textContent = e.message; btn.disabled = false; btn.innerHTML = label; return; }
    const html = await scheduleDraft(d.id, false, check);
    document.getElementById('dMsg').innerHTML = html + (html.includes('Held') ? ` · <button type="button" id="dForce" class="text-fail underline">Schedule anyway</button>` : '');
    btn.innerHTML = label;
    btn.disabled = false;
    const f = document.getElementById('dForce');
    if (f) f.addEventListener('click', async () => { document.getElementById('dMsg').innerHTML = await scheduleDraft(d.id, true, true); });
  };
  document.getElementById('dSchedule').addEventListener('click', ev => doSchedule(ev.currentTarget, false));
  document.getElementById('dCheckSchedule').addEventListener('click', ev => doSchedule(ev.currentTarget, true));
  document.getElementById('dSuggestTime').addEventListener('click', async ev => {
    const why = document.getElementById('dWhy');
    why.textContent = 'Thinking about your audience…';
    const fd = new FormData();
    fd.append('action', 'suggest_time');
    fd.append('platform_id', form.platform_id.value);
    fd.append('date', form.date.value);
    fd.append('caption', form.caption.value);
    try {
      const r = await api('compose.php', fd);
      form.time.value = r.time;
      why.innerHTML = `<span class="font-semibold text-sea">Suggested ${esc(r.time)}.</span> ${esc(r.why)} Save to keep it.`;
    } catch (e) { why.innerHTML = `<span class="text-fail">${esc(e.message)}</span>`; }
  });
  document.getElementById('dDelete').addEventListener('click', async () => {
    if (!confirm('Delete this draft?')) return;
    const fd = new FormData(); fd.append('action', 'delete'); fd.append('draft_id', d.id);
    try { await api('planner.php', fd); location.reload(); } catch (e) { document.getElementById('dError').textContent = e.message; }
  });
  const gen = document.getElementById('dGen');
  if (gen) gen.addEventListener('click', async () => {
    gen.disabled = true; gen.textContent = 'Generating…';
    document.getElementById('dImg').innerHTML = '<div class="shimmer absolute inset-0"></div>';
    const fd = new FormData();
    fd.append('prompt', d.image_prompt); fd.append('shape', 'portrait'); fd.append('draft_id', d.id);
    try { await api('image.php', fd); needsReload = true; openDraft(d.id); }
    catch (e) { document.getElementById('dError').textContent = e.message; gen.disabled = false; gen.textContent = 'Try again'; }
  });
}
document.querySelectorAll('.draft-chip').forEach(c => c.addEventListener('click', () => { if (!planning) openDraft(c.dataset.draft); }));

// Reload after closing if drafts changed, so the calendar shows them
window.onDrawerClosed = () => { if (needsReload) setTimeout(() => location.reload(), 250); };

// Launch week from the business builder: open the planner with the next 7 days and the launch brief.
<?php $launch = (!empty($_GET['launch']) && !empty($_SESSION['launch_plan'])) ? $_SESSION['launch_plan'] : null; unset($_SESSION['launch_plan']);
$launchDates = []; for ($i = 1; $i <= 7; $i++) $launchDates[] = date('Y-m-d', strtotime("+$i day")); ?>
const LAUNCH = <?= json_encode($launch ? ['theme' => $launch['theme'], 'language' => $launch['language'], 'dates' => $launchDates] : null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
if (LAUNCH) {
  openPlanner(LAUNCH.dates);
  document.getElementById('dTitle').textContent = 'Plan your launch week';
  const theme = document.getElementById('planTheme'); if (theme) theme.value = LAUNCH.theme;
  const lang = document.querySelector(`#planForm input[name=language][value="${LAUNCH.language}"]`); if (lang) lang.checked = true;
  history.replaceState(null, '', 'calendar.php');
}
</script>
<style>
  .shimmer { background: linear-gradient(100deg, #F1F1F4 30%, #EEEBFD 50%, #F1F1F4 70%); background-size: 300% 100%; animation: shimmer 1.4s linear infinite; }
  @keyframes shimmer { to { background-position: -100% 0; } }
  @media (prefers-reduced-motion: reduce) { .shimmer { animation: none; } #planBar { transition: none; } }
</style>
<?php page_footer(); ?>

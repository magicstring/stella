<?php
// Scout: paste a post that did well, learn why it worked, get 3 angles for your business, save the pattern as a trend.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

$business = current_business();

// Clean up the AI reply into a predictable shape
function scout_normalize(array $raw, array $platforms): array {
    $str = fn($v) => trim(is_string($v) ? $v : '');
    $list = fn($v) => array_values(array_filter(array_map($str, is_array($v) ? $v : [])));

    $why = [];
    foreach (is_array($raw['why_it_worked'] ?? null) ? $raw['why_it_worked'] : [] as $w) {
        if (is_array($w) && $str($w['explanation'] ?? '') !== '') {
            $why[] = ['factor' => $str($w['factor'] ?? '') ?: 'insight', 'explanation' => $str($w['explanation'])];
        }
    }

    $angles = [];
    foreach (is_array($raw['angles'] ?? null) ? $raw['angles'] : [] as $a) {
        if (!is_array($a) || $str($a['caption'] ?? '') === '') continue;
        $p = platform_by_name($str($a['platform'] ?? '')) ?? $platforms[0];
        $tags = array_values(array_filter(array_map(
            fn($t) => '#' . ltrim($str($t), '#'),
            is_array($a['hashtags'] ?? null) ? $a['hashtags'] : []
        ), fn($t) => $t !== '#'));
        $angles[] = [
            'title' => $str($a['title'] ?? ''), 'hook' => $str($a['hook'] ?? ''), 'caption' => $str($a['caption']),
            'hashtags' => $tags, 'format' => $str($a['format'] ?? ''), 'visual' => $str($a['visual'] ?? ''), 'platform' => $p['name'],
        ];
    }

    $t = is_array($raw['trend_to_track'] ?? null) ? $raw['trend_to_track'] : [];
    $type = in_array($t['type'] ?? '', Trends::TYPES, true) ? $t['type'] : 'angle';
    $trend = $str($t['name'] ?? '') !== '' ? [
        'type' => $type,
        'name' => mb_substr($str($t['name']), 0, 150),
        'note' => mb_substr($str($t['note'] ?? ''), 0, 255),
        'velocity' => max(0, min(100, (int)($t['velocity'] ?? 50))),
    ] : null;

    return [
        'summary' => $str($raw['summary'] ?? ''),
        'why_it_worked' => $why,
        'copy_this' => $list($raw['copy_this'] ?? []),
        'avoid' => $list($raw['avoid'] ?? []),
        'angles' => $angles,
        'trend_to_track' => $trend,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    $action = $_POST['action'] ?? 'analyze';

    // Save the pattern behind the post to the trends table
    if ($action === 'save_trend') {
        $t = $_SESSION['scout']['result']['trend_to_track'] ?? null;
        if (!$t) {
            json_out(['error' => 'Analyze a post first.'], 422);
        }
        $p = platform_by_name((string)($_SESSION['scout']['input']['platform'] ?? ''));
        try {
            $st = db()->prepare('INSERT INTO trends (type, name, platform, velocity, note, seen_at) VALUES (?, ?, ?, ?, ?, ?)');
            $st->execute([$t['type'], $t['name'], $p['name'] ?? null, $t['velocity'], $t['note'], date('Y-m-d')]);
        } catch (Throwable $e) {
            json_out(['error' => user_error_message($e)], 400);
        }
        json_out(['ok' => true, 'message' => 'Saved to Trends. Suggestions and trend fit will use it now.']);
    }

    try {
        $input = [
            'caption'  => trim((string)($_POST['caption'] ?? '')),
            'platform' => trim((string)($_POST['platform'] ?? '')),
            'views'    => trim((string)($_POST['views'] ?? '')),
            'likes'    => trim((string)($_POST['likes'] ?? '')),
            'comments' => trim((string)($_POST['comments'] ?? '')),
            'shares'   => trim((string)($_POST['shares'] ?? '')),
            'posted'   => trim((string)($_POST['posted'] ?? '')),
            'notes'    => trim((string)($_POST['notes'] ?? '')),
        ];
        if ($input['caption'] === '' && $input['notes'] === '') {
            json_out(['error' => 'Paste the caption, or describe what happens in the post.'], 422);
        }
        if (mb_strlen($input['caption']) > 5000 || mb_strlen($input['notes']) > 2000) {
            json_out(['error' => 'That text is too long. Keep the caption under 5,000 characters and notes under 2,000.'], 422);
        }

        $names = $business ? array_filter(array_map('trim', explode(',', (string)$business['platforms']))) : [];
        $platforms = array_values(array_filter(array_map('platform_by_name', $names))) ?: platforms();

        $raw = Claude::askJson(Prompts::SCOUT, Prompts::scoutInput(array_filter($input, fn($v) => $v !== ''), $business, $platforms), 3500);
        $result = scout_normalize($raw, $platforms);
        if (!$result['why_it_worked'] && !$result['angles']) {
            json_out(['error' => "The AI didn't return a usable analysis. Try again."], 502);
        }
        $_SESSION['scout'] = ['input' => $input, 'result' => $result];
        json_out($result);
    } catch (Throwable $e) {
        json_out(['error' => user_error_message($e)], 400);
    }
}

$saved = $_SESSION['scout'] ?? null;
$in = $saved['input'] ?? [];

page_header('Why it worked', 'scout.php');
?>
<div class="grid lg:grid-cols-[400px_1fr] gap-6 lg:gap-10 items-start">
  <section>
    <h1 class="font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight mb-2">Why did this post work?</h1>
    <p class="text-muted mb-6">Paste a post that's doing well right now, yours or anyone's. Stella explains what made it work and turns it into three angles<?= $business ? ' for <strong class="text-ink">' . e($business['name']) . '</strong>' : '' ?>.</p>

    <form id="scoutForm" class="space-y-4" novalidate>
      <div>
        <label for="s-caption" class="font-semibold block mb-1">Caption</label>
        <textarea id="s-caption" name="caption" rows="5" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm leading-relaxed focus:border-sea focus:outline-none" placeholder="Paste the post's caption and hashtags"><?= e($in['caption'] ?? '') ?></textarea>
      </div>
      <div>
        <label for="s-notes" class="font-semibold block mb-1">What happens in it</label>
        <textarea id="s-notes" name="notes" rows="3" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm leading-relaxed focus:border-sea focus:outline-none" placeholder="Example: 12-second POV, uses a trending OPM sound, ends with a reveal"><?= e($in['notes'] ?? '') ?></textarea>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label for="s-platform" class="text-sm font-semibold block mb-1">Platform</label>
          <select id="s-platform" name="platform" class="w-full rounded-md border border-line bg-white px-2 py-2 text-sm">
            <option value="">Not sure</option>
            <?php foreach (platforms() as $p): ?><option <?= ($in['platform'] ?? '') === $p['name'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="s-posted" class="text-sm font-semibold block mb-1">Posted</label>
          <input id="s-posted" name="posted" value="<?= e($in['posted'] ?? '') ?>" placeholder="2 days ago" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm">
        </div>
        <?php foreach (['views' => 'Views', 'likes' => 'Likes', 'comments' => 'Comments', 'shares' => 'Shares'] as $k => $label): ?>
          <div>
            <label for="s-<?= $k ?>" class="text-sm font-semibold block mb-1"><?= $label ?></label>
            <input id="s-<?= $k ?>" name="<?= $k ?>" value="<?= e($in[$k] ?? '') ?>" inputmode="numeric" placeholder="Optional" class="w-full rounded-md border border-line bg-white px-3 py-2 text-sm tabular-nums">
          </div>
        <?php endforeach; ?>
      </div>
      <button id="scoutBtn" type="submit" class="inline-flex items-center gap-2 rounded-md bg-sea px-6 py-3 font-semibold text-white hover:bg-seadeep disabled:opacity-60">
        <svg id="spinner" class="spin hidden" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="40 20"/></svg>
        <span id="scoutLabel">Explain this post</span>
      </button>
      <p id="scoutError" role="alert" class="text-sm text-fail"></p>
    </form>
  </section>

  <section id="placeholder" class="rounded-2xl border border-line bg-white p-8 lg:sticky lg:top-20" aria-label="What you'll get">
    <p class="text-sm font-semibold text-sea">What you'll get</p>
    <h2 class="font-display text-2xl font-extrabold mt-1">A breakdown you can reuse</h2>
    <ol class="mt-6 space-y-5">
      <li class="flex gap-4"><span class="font-display text-2xl font-extrabold text-sea leading-none">1</span><div><p class="font-semibold">Why it worked</p><p class="text-sm text-muted">The 3 to 5 things behind it: hook, format, emotion, timing, sound.</p></div></li>
      <li class="flex gap-4"><span class="font-display text-2xl font-extrabold text-sea leading-none">2</span><div><p class="font-semibold">Copy this, not that</p><p class="text-sm text-muted">What's safe to reuse, and what would be copying or break platform rules.</p></div></li>
      <li class="flex gap-4"><span class="font-display text-2xl font-extrabold text-sea leading-none">3</span><div><p class="font-semibold">Angles for your business</p><p class="text-sm text-muted">Three ready posts in your tone, one click from a pre-flight check.</p></div></li>
      <li class="flex gap-4"><span class="font-display text-2xl font-extrabold text-sea leading-none">4</span><div><p class="font-semibold">A trend to track</p><p class="text-sm text-muted">Save the pattern so post ideas and trend fit use it.</p></div></li>
    </ol>
    <div class="mt-5 sm:mt-8 space-y-2" aria-hidden="true">
      <div class="h-3 w-3/4 rounded-full bg-paper"></div>
      <div class="h-3 w-full rounded-full bg-paper"></div>
      <div class="h-3 w-5/6 rounded-full bg-paper"></div>
    </div>
    <p class="mt-6 text-sm text-muted">Tip: add views, likes, comments and shares. Shares and comments compared with views say a lot about why a post spread.</p>
  </section>

  <section id="out" class="hidden space-y-8" aria-live="polite">
    <div>
      <p id="summary" class="font-display text-2xl font-extrabold leading-snug"></p>
    </div>
    <div>
      <h2 class="font-display text-xl font-extrabold mb-3">Why it worked</h2>
      <ol id="why" class="space-y-3"></ol>
    </div>
    <div class="grid sm:grid-cols-2 gap-4">
      <div class="rounded-md border border-line bg-white p-4"><h3 class="font-semibold text-pass mb-2">Copy this</h3><ul id="copy" class="text-sm space-y-1.5 list-disc pl-4"></ul></div>
      <div class="rounded-md border border-line bg-white p-4"><h3 class="font-semibold text-fail mb-2">Don't copy this</h3><ul id="avoid" class="text-sm space-y-1.5 list-disc pl-4"></ul></div>
    </div>
    <div>
      <h2 class="font-display text-xl font-extrabold mb-3">Angles for you</h2>
      <div id="angles" class="grid md:grid-cols-3 gap-4"></div>
    </div>
    <div id="trendBox" class="rounded-md border border-sea/20 bg-foam p-4 sm:p-5 hidden">
      <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
          <p class="text-sm text-muted">Pattern to track</p>
          <p id="trendName" class="font-semibold text-lg"></p>
          <p id="trendNote" class="text-sm text-muted mt-1"></p>
        </div>
        <button id="saveTrend" type="button" class="rounded-md bg-sea text-white px-4 py-2 text-sm font-semibold hover:bg-seadeep disabled:opacity-60">Save to Trends</button>
      </div>
      <p id="trendMsg" role="status" class="text-sm text-sea mt-2"></p>
    </div>
  </section>
</div>

<script>
const SAVED = <?= json_encode($saved['result'] ?? null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function fill(id, items) {
  document.getElementById(id).innerHTML = items.length ? items.map(i => `<li>${escapeHtml(i)}</li>`).join('') : '<li class="list-none -ml-4 text-muted">Nothing noted.</li>';
}

function render(r) {
  document.getElementById('placeholder').classList.add('hidden');
  document.getElementById('out').classList.remove('hidden');
  document.getElementById('summary').textContent = r.summary;
  document.getElementById('why').innerHTML = r.why_it_worked.map((w, i) => `
    <li class="flex gap-4 rounded-md border border-line bg-white p-4">
      <span class="font-display text-2xl font-extrabold text-sea tabular-nums leading-none">${i + 1}</span>
      <div><p class="font-semibold">${escapeHtml(w.factor.charAt(0).toUpperCase() + w.factor.slice(1))}</p><p class="text-sm text-muted mt-0.5">${escapeHtml(w.explanation)}</p></div>
    </li>`).join('');
  fill('copy', r.copy_this);
  fill('avoid', r.avoid);
  document.getElementById('angles').innerHTML = r.angles.map(a => {
    const full = (a.caption + (a.hashtags.length ? '\n\n' + a.hashtags.join(' ') : '')).trim();
    const url = 'index.php?' + new URLSearchParams({ platform: a.platform, caption: full, visual: a.visual }).toString();
    return `<article class="rounded-md border border-line bg-white p-4 flex flex-col">
      <p class="text-sm text-muted">${escapeHtml(a.platform)}${a.format ? ' · ' + escapeHtml(a.format) : ''}</p>
      <h3 class="font-display text-lg font-extrabold leading-snug mt-1">${escapeHtml(a.title || a.hook)}</h3>
      ${a.hook && a.title ? `<p class="text-sm font-semibold mt-2">${escapeHtml(a.hook)}</p>` : ''}
      <p class="text-sm leading-relaxed mt-2 whitespace-pre-wrap flex-1">${escapeHtml(a.caption)}</p>
      ${a.hashtags.length ? `<p class="text-sm text-sea mt-2">${escapeHtml(a.hashtags.join(' '))}</p>` : ''}
      ${a.visual ? `<p class="text-xs text-muted mt-2"><strong class="text-ink">Visual:</strong> ${escapeHtml(a.visual)}</p>` : ''}
      <a href="${escapeHtml(url)}" class="mt-3 self-start rounded-md bg-sea text-white px-3 py-1.5 text-sm font-semibold hover:bg-seadeep">Use this angle</a>
    </article>`;
  }).join('');
  const t = r.trend_to_track;
  const box = document.getElementById('trendBox');
  if (t) {
    box.classList.remove('hidden');
    document.getElementById('trendName').textContent = `${t.name} (${t.type}, rise ${t.velocity})`;
    document.getElementById('trendNote').textContent = t.note;
    document.getElementById('trendMsg').textContent = '';
    const b = document.getElementById('saveTrend');
    b.disabled = false;
    b.textContent = 'Save to Trends';
  } else {
    box.classList.add('hidden');
  }
}
if (SAVED) render(SAVED);

document.getElementById('scoutForm').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const err = document.getElementById('scoutError');
  const btn = document.getElementById('scoutBtn');
  err.textContent = '';
  btn.disabled = true;
  document.getElementById('spinner').classList.remove('hidden');
  document.getElementById('scoutLabel').textContent = 'Reading the post…';
  try {
    const fd = new FormData(ev.target);
    fd.append('action', 'analyze');
    render(await api('scout.php', fd));
  } catch (e) {
    err.textContent = e.message;
  }
  btn.disabled = false;
  document.getElementById('spinner').classList.add('hidden');
  document.getElementById('scoutLabel').textContent = 'Explain this post';
});

document.getElementById('saveTrend').addEventListener('click', async (ev) => {
  const b = ev.currentTarget;
  b.disabled = true;
  const fd = new FormData();
  fd.append('action', 'save_trend');
  try {
    const data = await api('scout.php', fd);
    document.getElementById('trendMsg').textContent = data.message;
    b.textContent = 'Saved';
  } catch (e) {
    document.getElementById('trendMsg').textContent = e.message;
    b.disabled = false;
  }
});
</script>
<?php page_footer(); ?>

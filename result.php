<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

$check = find_check((int)($_GET['id'] ?? 0));
if (!$check) {
    http_response_code(404);
    page_header('Not found');
    echo '<p>That check doesn\'t exist. <a class="text-sea underline" href="index.php">Check a post</a></p>';
    page_footer();
    exit;
}

$platform = platform_by_id((int)$check['platform_id']);
$ruleData = decode_json($check['rule_results']);
$rules = $ruleData['checks'] ?? [];
$media = $ruleData['media'] ?? null;
$aiRaw = decode_json($check['ai_results']);
$ai = isset($aiRaw['overall_score']) ? $aiRaw : null;
$aiError = $aiRaw['error'] ?? null;
$score = (int)$check['score'];
$verdict = Preflight::verdict($rules, $ai, $score, (string)$check['caption']);
$fixes = decode_json($check['fixes']);
$counts = Rules::counts($rules);

$colors = ['ready' => '#1F7A4D', 'fix' => '#9A6200', 'hold' => '#B42318'];
$color = $colors[$verdict['key']];

// Fail and warn first
$order = ['fail' => 0, 'warn' => 1, 'pass' => 2];
usort($rules, fn($a, $b) => $order[$a['status']] <=> $order[$b['status']]);

$jsPlatforms = [];
foreach (platforms() as $p) $jsPlatforms[$p['name']] = Prompts::platformLimits($p) + ['id' => (int)$p['id']];

page_header('Check result', 'history.php');
?>
<a href="index.php" class="text-sm text-sea underline">Check another post</a>

<div class="grid lg:grid-cols-[340px_1fr] gap-6 lg:gap-10 mt-6">
  <!-- Verdict column -->
  <section aria-labelledby="verdictTitle">
    <div class="rounded-md bg-white border border-line p-4 sm:p-6 text-center">
      <svg viewBox="0 0 200 118" class="w-60 mx-auto" role="img" aria-label="Score <?= $score ?> out of 100">
        <path d="M20 100 A80 80 0 0 1 180 100" fill="none" stroke="#D6DEE2" stroke-width="16" stroke-linecap="round"/>
        <path id="arc" d="M20 100 A80 80 0 0 1 180 100" fill="none" stroke="<?= $color ?>" stroke-width="16" stroke-linecap="round"
              pathLength="100" stroke-dasharray="100" stroke-dashoffset="100" style="transition: stroke-dashoffset 1.1s cubic-bezier(.2,.8,.2,1)"/>
        <text x="100" y="94" text-anchor="middle" font-family="Bricolage Grotesque, system-ui" font-size="46" font-weight="800" fill="#13233A"><?= $score ?></text>
      </svg>
      <h1 id="verdictTitle" class="font-display text-xl sm:text-2xl lg:text-3xl font-extrabold mt-1" style="color: <?= $color ?>"><?= e($verdict['label']) ?></h1>
      <p class="text-sm text-muted mt-2"><?= e($verdict['note']) ?></p>
      <p class="text-sm mt-4 tabular-nums">
        <span class="text-fail font-semibold"><?= $counts['fail'] ?> fail</span> ·
        <span class="text-warn font-semibold"><?= $counts['warn'] ?> warn</span> ·
        <span class="text-pass font-semibold"><?= $counts['pass'] ?> pass</span>
      </p>
      <div class="mt-6 flex flex-col gap-2">
        <button id="fixBtn" type="button" class="rounded-md bg-sea px-4 py-2.5 font-semibold text-white hover:bg-seadeep disabled:opacity-60">
          <?= $fixes ? 'Rewrite again' : 'Fix it for me' ?>
        </button>
        <a href="publish.php?check_id=<?= (int)$check['id'] ?>" class="rounded-md border border-ink px-4 py-2.5 font-semibold hover:bg-ink hover:text-white">Publish</a>
      </div>
      <p id="fixError" role="alert" class="text-sm text-fail mt-2"></p>
    </div>

    <div class="mt-6">
      <h2 class="font-semibold mb-2"><?= e($platform['name'] ?? 'Unknown platform') ?> caption</h2>
      <p class="whitespace-pre-wrap rounded-md bg-white border border-line p-4 text-sm leading-relaxed"><?= $check['caption'] !== '' ? e($check['caption']) : '<span class="text-muted">No caption</span>' ?></p>
      <?php if ($check['file_path'] && $media): ?>
        <div class="mt-4 max-w-[220px] rounded-md overflow-hidden border border-line bg-black">
          <?php if ($media['kind'] === 'video'): ?>
            <video src="media.php?f=<?= e($check['file_path']) ?>" controls muted playsinline class="w-full"></video>
          <?php else: ?>
            <img src="media.php?f=<?= e($check['file_path']) ?>" alt="The uploaded image" class="w-full">
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <p class="text-xs text-muted mt-3">Checked <?= e(nice_time($check['created_at'])) ?></p>
    </div>
  </section>

  <!-- Details column -->
  <section class="space-y-8">
    <div>
      <h2 class="font-display text-2xl font-extrabold mb-3">Platform rules</h2>
      <ul class="divide-y divide-line rounded-md border border-line bg-white">
        <?php foreach ($rules as $r): ?>
          <li class="p-4 flex gap-4 items-start">
            <?= status_pill($r['status']) ?>
            <div>
              <p class="font-semibold"><?= e($r['name']) ?></p>
              <p class="text-sm text-muted"><?= e($r['message']) ?></p>
              <?php if ($r['fix'] !== ''): ?><p class="text-sm mt-1"><strong>Fix:</strong> <?= e($r['fix']) ?></p><?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div>
      <h2 class="font-display text-2xl font-extrabold mb-3">Claude's review</h2>
      <?php if (!$ai): ?>
        <div class="rounded-md bg-warnbg text-warn px-4 py-3 text-sm">
          Claude's review didn't run, so this score is from the platform rules only.
          <?php if ($aiError): ?><br><span class="text-ink/70">Reason: <?= e($aiError) ?></span><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="grid sm:grid-cols-2 gap-4">
          <?php foreach (['policy_risk' => 'Policy risk', 'brand_safety' => 'Brand safety'] as $key => $label): ?>
            <div class="rounded-md border border-line bg-white p-4">
              <div class="flex items-center justify-between mb-2"><h3 class="font-semibold"><?= $label ?></h3><?= status_pill($ai[$key]['level']) ?></div>
              <?php if (!$ai[$key]['issues']): ?>
                <p class="text-sm text-muted">No issues found.</p>
              <?php else: ?>
                <ul class="space-y-3">
                  <?php foreach ($ai[$key]['issues'] as $i): ?>
                    <li class="text-sm"><p><?= e($i['issue']) ?></p><?php if ($i['fix']): ?><p class="mt-0.5"><strong>Fix:</strong> <?= e($i['fix']) ?></p><?php endif; ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

          <div class="rounded-md border border-line bg-white p-4">
            <div class="flex items-center justify-between mb-2"><h3 class="font-semibold">Hook</h3><span class="font-display text-xl font-extrabold tabular-nums"><?= (int)$ai['hook_quality']['score'] ?></span></div>
            <p class="text-sm text-muted"><?= e($ai['hook_quality']['note']) ?></p>
          </div>

          <div class="rounded-md border border-line bg-white p-4">
            <div class="flex items-center justify-between mb-2"><h3 class="font-semibold">Disclosure</h3>
              <?php if (!$ai['disclosure_needed']): ?><?= status_pill('pass') ?>
              <?php elseif (Preflight::hasDisclosure((string)$check['caption'])): ?><?= status_pill('pass') ?>
              <?php else: ?><?= status_pill('warn') ?><?php endif; ?>
            </div>
            <p class="text-sm text-muted">
              <?php if (!$ai['disclosure_needed']): ?>Doesn't look sponsored.
              <?php elseif (Preflight::hasDisclosure((string)$check['caption'])): ?>Looks sponsored and is disclosed.
              <?php else: ?>Looks sponsored but has no #ad. Add it so followers and the platform know.<?php endif; ?>
            </p>
          </div>
        </div>

        <?php $ph = $ai['ph_compliance'] ?? null; ?>
        <div class="mt-4 rounded-md border border-line bg-white p-4 sm:p-5">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
              <h3 class="font-semibold">Philippine rules</h3>
              <p class="text-xs text-muted">Consumer Act, FDA, DTI promo permits, Data Privacy Act and ad standards</p>
            </div>
            <?= $ph ? status_pill($ph['level']) : '<span class="text-xs text-muted">Not checked on this older result</span>' ?>
          </div>
          <?php if ($ph && $ph['issues']): ?>
            <ul class="mt-3 divide-y divide-line">
              <?php foreach ($ph['issues'] as $i): ?>
                <li class="py-3 text-sm">
                  <p><?= e($i['issue']) ?></p>
                  <?php if ($i['rule'] !== ''): ?><p class="text-xs text-muted mt-0.5">Rule: <?= e($i['rule']) ?></p><?php endif; ?>
                  <?php if ($i['fix'] !== ''): ?><p class="mt-1"><strong>Fix:</strong> <?= e($i['fix']) ?></p><?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php elseif ($ph): ?>
            <p class="mt-3 text-sm text-muted">No Philippine advertising or consumer-rule issues found.</p>
          <?php endif; ?>
          <p class="mt-3 text-xs text-muted">A heads-up to catch common problems, not legal advice. Check anything important with your legal team.</p>
        </div>

        <div class="mt-4 rounded-md border border-sea/20 bg-foam p-4 sm:p-5">
          <div class="flex items-center justify-between gap-4">
            <h3 class="font-semibold">Trend fit in the Philippines</h3>
            <span class="font-display text-xl sm:text-2xl lg:text-3xl font-extrabold tabular-nums text-sea"><?= (int)$ai['trend_fit']['score'] ?></span>
          </div>
          <?php if ($ai['trend_fit']['matched_trend'] !== ''): ?>
            <p class="text-sm mt-2"><span class="text-muted">Closest trend:</span> <?= e($ai['trend_fit']['matched_trend']) ?></p>
          <?php endif; ?>
          <p class="text-sm mt-2"><?= e($ai['trend_fit']['suggestion']) ?></p>
        </div>
      <?php endif; ?>
    </div>

    <div id="fixPanel" class="<?= $fixes ? '' : 'hidden' ?>">
      <h2 class="font-display text-2xl font-extrabold mb-1">Fixed captions</h2>
      <p class="text-sm text-muted mb-4">Rewritten for each platform. Edit them here, then re-check to see the new score. Publish uses these versions.</p>
      <div id="fixList" class="grid md:grid-cols-2 gap-4"></div>
    </div>
  </section>
</div>

<script>
const CHECK_ID = <?= (int)$check['id'] ?>;
const SCORE = <?= $score ?>;
const PLATFORMS = <?= json_encode($jsPlatforms, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const SAVED_FIXES = <?= json_encode($fixes['versions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

// The one animated moment: the score arc fills on load.
requestAnimationFrame(() => requestAnimationFrame(() => {
  document.getElementById('arc').setAttribute('stroke-dashoffset', String(100 - SCORE));
}));

function renderFixes(versions) {
  const list = document.getElementById('fixList');
  list.innerHTML = '';
  versions.forEach(v => {
    const p = PLATFORMS[v.platform];
    if (!p) return;
    const full = (v.caption + (v.hashtags && v.hashtags.length ? '\n\n' + v.hashtags.join(' ') : '')).trim();
    const card = document.createElement('div');
    card.className = 'rounded-md border border-line bg-white p-4 flex flex-col';
    card.innerHTML = `
      <div class="flex items-baseline justify-between mb-2">
        <h3 class="font-semibold">${escapeHtml(v.platform)}</h3>
        <span class="count text-xs tabular-nums text-muted"></span>
      </div>
      <label class="sr-only" for="fix-${p.id}">${escapeHtml(v.platform)} caption</label>
      <textarea id="fix-${p.id}" rows="6" class="w-full rounded-md border border-line px-3 py-2 text-sm leading-relaxed focus:border-sea focus:outline-none">${escapeHtml(full)}</textarea>
      ${v.changes && v.changes.length ? `<ul class="mt-2 text-xs text-muted list-disc pl-4 space-y-0.5">${v.changes.map(c => `<li>${escapeHtml(c)}</li>`).join('')}</ul>` : ''}
      <button type="button" class="recheck mt-3 self-start rounded-md border border-sea text-sea px-3 py-1.5 text-sm font-semibold hover:bg-foam disabled:opacity-60">Re-check for ${escapeHtml(v.platform)}</button>`;
    const ta = card.querySelector('textarea');
    const count = card.querySelector('.count');
    const update = () => {
      const len = [...ta.value].length;
      count.textContent = `${len} / ${p.caption_max}`;
      count.className = 'count text-xs tabular-nums ' + (len > p.caption_max ? 'text-fail font-semibold' : 'text-muted');
    };
    ta.addEventListener('input', update);
    update();
    card.querySelector('.recheck').addEventListener('click', async (ev) => {
      const btn = ev.currentTarget;
      btn.disabled = true;
      btn.textContent = 'Checking…';
      const fd = new FormData();
      fd.append('platform_id', p.id);
      fd.append('caption', ta.value);
      fd.append('reuse_check_id', CHECK_ID);
      try {
        const data = await api('check.php', fd);
        window.location = data.redirect;
      } catch (e) {
        btn.disabled = false;
        btn.textContent = 'Re-check for ' + v.platform;
        document.getElementById('fixError').textContent = e.message;
      }
    });
    list.appendChild(card);
  });
  document.getElementById('fixPanel').classList.remove('hidden');
}

if (SAVED_FIXES.length) renderFixes(SAVED_FIXES);

document.getElementById('fixBtn').addEventListener('click', async (ev) => {
  const btn = ev.currentTarget;
  const err = document.getElementById('fixError');
  err.textContent = '';
  btn.disabled = true;
  btn.textContent = 'Rewriting for each platform…';
  const fd = new FormData();
  fd.append('check_id', CHECK_ID);
  try {
    const data = await api('fix.php', fd);
    renderFixes(data.versions);
    document.getElementById('fixPanel').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    btn.textContent = 'Rewrite again';
  } catch (e) {
    err.textContent = e.message;
    btn.textContent = 'Fix it for me';
  }
  btn.disabled = false;
});
</script>
<?php page_footer(); ?>

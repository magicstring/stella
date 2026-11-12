<?php
// Post ideas: 1 to 5 ideas from the business profile and rising trends, in English, Taglish or Fun Taglish.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

$business = current_business();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    try {
        if (!$business) {
            json_out(['error' => 'Set up your business first, so the ideas fit it.'], 422);
        }
        $count = max(1, min(5, (int)($_POST['count'] ?? 3)));
        $language = in_array($_POST['language'] ?? '', Prompts::LANGUAGES, true) ? $_POST['language'] : 'Taglish';
        // Regenerate: tell the AI which hooks the user already saw and didn't want
        $previous = !empty($_POST['regenerate'])
            ? array_values(array_filter(array_map(fn($p) => (string)($p['hook'] ?? ''), $_SESSION['suggestions'] ?? [])))
            : [];

        $names = array_filter(array_map('trim', explode(',', (string)$business['platforms'])));
        $chosen = array_values(array_filter(array_map('platform_by_name', $names)));
        if (!$chosen) $chosen = platforms();

        $raw = Claude::askJson(
            Prompts::SUGGEST,
            Prompts::suggestInput($business, $chosen, Trends::top(null, 25), $count, $language, $previous),
            1000 + 700 * $count
        );

        $posts = [];
        foreach ($raw['posts'] ?? [] as $p) {
            if (!is_array($p) || trim((string)($p['caption'] ?? '')) === '') continue;
            $platform = platform_by_name((string)($p['platform'] ?? '')) ?? $chosen[0];
            $tags = array_values(array_filter(array_map(
                fn($t) => '#' . ltrim(trim((string)$t), '#'),
                is_array($p['hashtags'] ?? null) ? $p['hashtags'] : []
            ), fn($t) => $t !== '#'));
            $posts[] = [
                'hook'     => (string)($p['hook'] ?? ''),
                'caption'  => trim((string)$p['caption']),
                'hashtags' => $tags,
                'format'   => (string)($p['format'] ?? ''),
                'visual'   => (string)($p['visual'] ?? ''),
                'platform' => $platform['name'],
                'why_now'  => (string)($p['why_now'] ?? ''),
                'trend'    => (string)($p['trend'] ?? ''),
            ];
            if (count($posts) >= $count) break;
        }
        if (!$posts) {
            json_out(['error' => "The AI didn't return any usable ideas. Try again."], 502);
        }
        $_SESSION['suggestions'] = $posts;
        $_SESSION['suggest_settings'] = ['count' => $count, 'language' => $language];
        json_out(['posts' => $posts, 'language' => $language]);
    } catch (Throwable $e) {
        json_out(['error' => user_error_message($e)], 400);
    }
}

$saved = $_SESSION['suggestions'] ?? [];
$settings = $_SESSION['suggest_settings'] ?? ['count' => 3, 'language' => 'Taglish'];
page_header('Post ideas', 'suggest.php');
?>
<h1 class="font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight">Post ideas</h1>
<p class="mt-2 text-muted max-w-prose">
  <?php if ($business): ?>
    Ideas for <strong class="text-ink"><?= e($business['name']) ?></strong>, built from your profile and what's rising in the Philippines. Not feeling them? Regenerate for a fresh set.
  <?php else: ?>
    Set up your business first so the ideas fit what you sell and how you talk.
  <?php endif; ?>
</p>

<?php if (!$business): ?>
  <a href="profile.php" data-nav class="mt-6 inline-block rounded-lg bg-sea px-6 py-3 font-semibold text-white hover:bg-seadeep">Set up your business</a>
<?php else: ?>
<form id="genForm" class="mt-5 sm:mt-8 flex flex-wrap items-end gap-x-8 gap-y-5 rounded-xl border border-line bg-white p-4 sm:p-5" novalidate>
  <fieldset>
    <legend class="mb-2 text-sm font-semibold">How many ideas</legend>
    <div class="inline-flex rounded-lg border border-line bg-paper p-1">
      <?php for ($i = 1; $i <= 5; $i++): ?>
        <label class="cursor-pointer">
          <input type="radio" name="count" value="<?= $i ?>" class="peer sr-only" <?= (int)$settings['count'] === $i ? 'checked' : '' ?>>
          <span class="block w-10 rounded-md py-1.5 text-center text-sm font-semibold tabular-nums text-muted peer-checked:bg-white peer-checked:text-sea peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= $i ?></span>
        </label>
      <?php endfor; ?>
    </div>
  </fieldset>
  <fieldset>
    <legend class="mb-2 text-sm font-semibold">Language</legend>
    <div class="inline-flex rounded-lg border border-line bg-paper p-1">
      <?php foreach (Prompts::LANGUAGES as $lang): ?>
        <label class="cursor-pointer">
          <input type="radio" name="language" value="<?= e($lang) ?>" class="peer sr-only" <?= $settings['language'] === $lang ? 'checked' : '' ?>>
          <span class="block rounded-md px-3 py-1.5 text-sm font-semibold text-muted peer-checked:bg-white peer-checked:text-sea peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= e($lang) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <div class="ml-auto flex flex-wrap gap-2">
    <button id="regenBtn" type="button" class="<?= $saved ? '' : 'hidden ' ?>inline-flex items-center gap-2 rounded-lg border border-line bg-white px-4 py-3 font-semibold hover:border-sea hover:text-sea disabled:opacity-60">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/></svg>
      Regenerate
    </button>
    <button id="genBtn" type="submit" class="inline-flex items-center gap-2 rounded-lg bg-sea px-6 py-3 font-semibold text-white hover:bg-seadeep disabled:opacity-60">
      <svg id="spinner" class="spin hidden" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="40 20"/></svg>
      <span id="genLabel">Suggest posts</span>
    </button>
  </div>
</form>
<p id="genError" role="alert" class="mt-3 text-sm text-fail"></p>
<p id="genMeta" class="mt-6 text-sm text-muted"></p>
<div id="ideas" class="mt-3 grid md:grid-cols-2 xl:grid-cols-3 gap-5"></div>

<style>
  .reveal > * { animation: rise .45s cubic-bezier(.2,.8,.2,1) both; }
  .reveal > *:nth-child(2) { animation-delay: .07s; } .reveal > *:nth-child(3) { animation-delay: .14s; }
  .reveal > *:nth-child(4) { animation-delay: .21s; } .reveal > *:nth-child(5) { animation-delay: .28s; }
  @keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
  @media (prefers-reduced-motion: reduce) { .reveal > * { animation: none !important; } }
</style>

<script>
const SAVED = <?= json_encode(['posts' => $saved, 'language' => $settings['language']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const form = document.getElementById('genForm');

function labelFor() {
  const n = form.querySelector('input[name=count]:checked').value;
  document.getElementById('genLabel').textContent = `Suggest ${n} post${n === '1' ? '' : 's'}`;
}
form.querySelectorAll('input[name=count]').forEach(r => r.addEventListener('change', labelFor));
labelFor();

function render(data, animate) {
  const box = document.getElementById('ideas');
  box.innerHTML = '';
  box.classList.toggle('reveal', animate);
  document.getElementById('genMeta').textContent = data.posts.length ? `${data.posts.length} idea${data.posts.length === 1 ? '' : 's'} in ${data.language}` : '';
  data.posts.forEach(p => {
    const full = (p.caption + (p.hashtags.length ? '\n\n' + p.hashtags.join(' ') : '')).trim();
    const url = 'index.php?' + new URLSearchParams({ platform: p.platform, caption: full, visual: p.visual }).toString();
    const card = document.createElement('article');
    card.className = 'rounded-xl border border-line bg-white flex flex-col overflow-hidden';
    card.innerHTML = `
      <div class="p-4 sm:p-5 flex-1">
        <p class="text-sm text-muted">${escapeHtml(p.platform)}${p.format ? ' · ' + escapeHtml(p.format) : ''}</p>
        <h2 class="font-display text-xl font-extrabold leading-snug mt-1">${escapeHtml(p.hook)}</h2>
        <p class="text-sm leading-relaxed mt-3 whitespace-pre-wrap">${escapeHtml(p.caption)}</p>
        ${p.hashtags.length ? `<p class="text-sm text-sea mt-2">${escapeHtml(p.hashtags.join(' '))}</p>` : ''}
        <div class="mt-4 text-sm"><p class="font-semibold">Visual to make</p><p class="text-muted">${escapeHtml(p.visual)}</p></div>
      </div>
      <div class="border-t border-line bg-foam p-4 text-sm">
        <p class="font-semibold">Why now${p.trend ? ': ' + escapeHtml(p.trend) : ''}</p>
        <p class="text-muted mt-1">${escapeHtml(p.why_now)}</p>
        <a href="${escapeHtml(url)}" class="mt-3 inline-block rounded-md bg-sea text-white px-3 py-1.5 font-semibold hover:bg-seadeep">Use this post</a>
      </div>`;
    box.appendChild(card);
  });
}
if (SAVED.posts.length) render(SAVED, false);

async function generate(regenerate) {
  const err = document.getElementById('genError');
  const gen = document.getElementById('genBtn'), regen = document.getElementById('regenBtn');
  err.textContent = '';
  gen.disabled = regen.disabled = true;
  document.getElementById('spinner').classList.remove('hidden');
  document.getElementById('genLabel').textContent = regenerate ? 'Finding fresh angles…' : 'Reading trends and writing…';
  try {
    const fd = new FormData(form);
    if (regenerate) fd.append('regenerate', '1');
    const data = await api('suggest.php', fd);
    render(data, true);
    regen.classList.remove('hidden');
  } catch (e) {
    err.textContent = e.message;
  }
  gen.disabled = regen.disabled = false;
  document.getElementById('spinner').classList.add('hidden');
  labelFor();
}
form.addEventListener('submit', ev => { ev.preventDefault(); generate(false); });
document.getElementById('regenBtn').addEventListener('click', () => generate(true));
</script>
<?php endif; ?>
<?php page_footer(); ?>

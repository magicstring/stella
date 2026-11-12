<?php
// Start a business: turn an idea, a strength or what someone already has into a small business,
// then save it as a Stella business profile and plan the launch week on the calendar.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

const BUILD_TONES = ['Friendly', 'Fun and playful', 'Professional', 'Warm and caring', 'Bold'];
const BUILD_BUDGETS = ['Not sure yet', 'Under ₱10,000', '₱10,000 to ₱50,000', '₱50,000 to ₱200,000', 'Over ₱200,000'];
const BUILD_TIME = ['Weekends only', 'Part-time', 'Full-time'];
const BUILD_STARTS = [
    'idea'     => ['I have an idea', 'You already know what you want to sell or do.', 'Example: Home-baked ube cheese pandesal for offices near Ortigas.'],
    'strength' => ['I know my strength', 'A skill, hobby or work experience you could build on.', 'Example: I worked 3 years in a salon, I am good at nail art and I love designing.'],
    'have'     => ['Here\'s what I have', 'Savings, space, equipment, time or people you can start with.', 'Example: A small garage in Marikina, some savings, free on weekends, and my mom cooks great kakanin.'],
];
// General first registrations for a small business in the Philippines. Shown as guidance, not legal advice.
const PH_CHECKLIST = [
    ['DTI business name registration', 'For a sole proprietorship. Partnerships and corporations register with the SEC instead.'],
    ['Barangay clearance', 'From the barangay where the business will operate.'],
    ['Mayor\'s permit (business permit)', 'From your city or municipal hall.'],
    ['BIR registration', 'Register the business with your TIN, then get your books of accounts and invoices.'],
    ['SSS, PhilHealth and Pag-IBIG', 'Only once you hire people: register as an employer.'],
];

$clean = fn($v, int $max) => mb_substr(trim((string)$v), 0, $max);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post();
    try {
        $action = (string)($_POST['action'] ?? '');
        $language = in_array($_POST['language'] ?? '', Prompts::LANGUAGES, true) ? $_POST['language'] : 'Taglish';

        if ($action === 'ideas') {
            $start = array_key_exists($_POST['start'] ?? '', BUILD_STARTS) ? $_POST['start'] : 'idea';
            $input = [
                'starting_point' => BUILD_STARTS[$start][0],
                'what_they_said' => $clean($_POST['details'] ?? '', 1500),
                'budget'         => in_array($_POST['budget'] ?? '', BUILD_BUDGETS, true) ? $_POST['budget'] : 'Not sure yet',
                'location'       => $clean($_POST['location'] ?? '', 120),
                'time'           => in_array($_POST['time'] ?? '', BUILD_TIME, true) ? $_POST['time'] : 'Part-time',
                'language'       => $language,
            ];
            if (mb_strlen($input['what_they_said']) < 8) json_out(['error' => 'Tell Stella a little more first, even one sentence helps.'], 422);
            $previous = !empty($_POST['regenerate']) ? array_column($_SESSION['builder']['ideas'] ?? [], 'name') : [];

            $raw = Claude::askJson(Prompts::BUILD_IDEAS, Prompts::buildIdeasInput($input, $previous), 3000);
            $ideas = [];
            foreach ($raw['ideas'] ?? [] as $i) {
                if (!is_array($i) || trim((string)($i['name'] ?? '')) === '') continue;
                $str = fn($k, $m = 400) => mb_substr(trim((string)($i[$k] ?? '')), 0, $m);
                $ideas[] = [
                    'name' => $str('name', 80), 'one_liner' => $str('one_liner', 200), 'why_you' => $str('why_you'),
                    'customer' => $str('customer'), 'how_it_sells' => $str('how_it_sells'), 'start_small' => $str('start_small'),
                    'main_risk' => $str('main_risk'), 'industry' => $str('industry', 100),
                    'offer' => array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 120), (array)($i['offer'] ?? [])))), 0, 5),
                ];
                if (count($ideas) >= 3) break;
            }
            if (!$ideas) json_out(['error' => "The AI didn't return usable ideas. Try again."], 502);
            $_SESSION['builder'] = ['input' => $input, 'ideas' => $ideas];
            json_out(['ideas' => $ideas]);
        }

        if ($action === 'shape') {
            $b = $_SESSION['builder'] ?? null;
            $n = (int)($_POST['idea'] ?? -1);
            if (!$b || !isset($b['ideas'][$n])) json_out(['error' => 'Pick one of the ideas first.'], 422);
            $platformNames = array_column(platforms(), 'name');
            $raw = Claude::askJson(Prompts::BUILD_SHAPE, Prompts::buildShapeInput($b['input'], $b['ideas'][$n], $platformNames, BUILD_TONES), 3000);
            $list = fn($v, $max = 8) => array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 160), (array)$v))), 0, $max);
            $s = fn($k, $m = 600) => mb_substr(trim((string)($raw[$k] ?? '')), 0, $m);
            $permits = [];
            foreach ((array)($raw['permits'] ?? []) as $p) {
                if (is_array($p) && trim((string)($p['item'] ?? '')) !== '') $permits[] = ['item' => mb_substr(trim($p['item']), 0, 120), 'why' => mb_substr(trim((string)($p['why'] ?? '')), 0, 240)];
            }
            $pricing = is_array($raw['pricing'] ?? null) ? $raw['pricing'] : [];
            $shape = [
                'idea'        => $b['ideas'][$n],
                'names'       => $list($raw['names'] ?? [], 4),
                'tagline'     => $s('tagline', 150),
                'description' => $s('description', 1200),
                'audience'    => $s('audience', 250),
                'products'    => $list($raw['products'] ?? []),
                'services'    => $list($raw['services'] ?? []),
                'location'    => $s('location', 150) ?: $b['input']['location'],
                'tone'        => in_array($raw['tone'] ?? '', BUILD_TONES, true) ? $raw['tone'] : 'Friendly',
                'language'    => in_array($raw['language'] ?? '', Prompts::LANGUAGES, true) ? $raw['language'] : $b['input']['language'],
                'platforms'   => array_values(array_intersect($platformNames, (array)($raw['platforms'] ?? []))),
                'pricing'     => ['approach' => mb_substr(trim((string)($pricing['approach'] ?? '')), 0, 500), 'how_to_check' => $list($pricing['how_to_check'] ?? [], 4)],
                'first_steps' => $list($raw['first_steps'] ?? [], 5),
                'permits'     => array_slice($permits, 0, 4),
                'launch_theme'=> $s('launch_theme', 500),
            ];
            $_SESSION['builder']['shape'] = $shape;
            json_out(['shape' => $shape]);
        }

        if ($action === 'save') {
            $name = $clean($_POST['name'] ?? '', 150);
            $tagline = $clean($_POST['tagline'] ?? '', 150);
            $description = $clean($_POST['description'] ?? '', 1800);
            $lines = fn($v) => array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 120), preg_split('/\r?\n|,/', (string)$v))));
            $products = $lines($_POST['products'] ?? '');
            $services = $lines($_POST['services'] ?? '');
            $audience = $clean($_POST['audience'] ?? '', 255);
            $location = $clean($_POST['location'] ?? '', 150);
            $industry = $clean($_POST['industry'] ?? '', 100);
            $tone = in_array($_POST['tone'] ?? '', BUILD_TONES, true) ? $_POST['tone'] : 'Friendly';
            $platforms = implode(', ', array_values(array_filter((array)($_POST['platforms'] ?? []), fn($p) => platform_by_name((string)$p) !== null)));
            $theme = $clean($_POST['launch_theme'] ?? '', 500);

            $errors = [];
            if ($name === '') $errors[] = 'Give the business a name.';
            if ($description === '' && !$products && !$services) $errors[] = 'Describe what the business offers.';
            if ($platforms === '') $errors[] = 'Pick at least one platform.';
            if ($errors) json_out(['error' => implode(' ', $errors)], 422);

            $fullDesc = trim(($tagline !== '' ? "Tagline: $tagline\n" : '') . $description);
            if (multi_business_ready()) {
                $st = db()->prepare('INSERT INTO business_profiles (name, industry, description, services, products, audience, location, tone, language, platforms, last_used_at)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $st->execute([$name, $industry, $fullDesc, implode("\n", $services), implode("\n", $products), $audience, $location, $tone, $language, $platforms, date('Y-m-d H:i:s')]);
            } else {
                $desc = trim($fullDesc . ($services ? "\nServices: " . implode(', ', $services) : '') . ($products ? "\nProducts: " . implode(', ', $products) : ''));
                $st = db()->prepare('INSERT INTO business_profiles (name, description, audience, location, tone, platforms) VALUES (?, ?, ?, ?, ?, ?)');
                $st->execute([$name, $desc, $audience, $location, "$tone, $language", $platforms]);
            }
            switch_business((int)db()->lastInsertId());
            $_SESSION['launch_plan'] = ['theme' => $theme !== '' ? $theme : "Launch week for $name: introduce the business, the offer and how to order.", 'language' => $language];
            unset($_SESSION['builder']);
            flash("$name is set up. Here's your launch week: pick the options and generate the drafts.");
            json_out(['redirect' => 'calendar.php?launch=1']);
        }
        json_out(['error' => 'Unknown action.'], 400);
    } catch (Throwable $e) {
        json_out(['error' => user_error_message($e)], 400);
    }
}

$platformNames = array_column(platforms(), 'name');
page_header('Start a business', 'build.php');
?>
<div class="max-w-5xl">
  <p class="text-xs font-semibold uppercase tracking-[.14em] text-sea">Business builder</p>
  <h1 class="mt-2 font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight">Start a business</h1>
  <p class="mt-2 text-muted max-w-prose">Have an idea, a skill, or just what you have right now? Stella turns it into a small business you can launch, then helps you market it.</p>

  <ol id="steps" class="mt-6 flex flex-wrap gap-2 text-sm" aria-label="Steps">
    <li data-step="1" class="step-pill">1 · Your starting point</li>
    <li data-step="2" class="step-pill">2 · Pick an idea</li>
    <li data-step="3" class="step-pill">3 · Shape it and launch</li>
  </ol>

  <!-- Step 1 -->
  <section id="s1" class="mt-6">
    <form id="startForm" class="rounded-xl border border-line bg-white p-4 sm:p-6" novalidate>
      <fieldset>
        <legend class="text-sm font-semibold">Where are you starting from?</legend>
        <div class="mt-3 grid gap-3 sm:grid-cols-3">
          <?php foreach (BUILD_STARTS as $key => [$label, $hint, $example]): ?>
            <label class="cursor-pointer">
              <input type="radio" name="start" value="<?= e($key) ?>" data-example="<?= e($example) ?>" class="peer sr-only" <?= $key === 'idea' ? 'checked' : '' ?>>
              <span class="block h-full rounded-xl border-2 border-line p-4 peer-checked:border-sea peer-checked:bg-foam peer-focus-visible:ring-2 peer-focus-visible:ring-sea">
                <span class="block font-display text-lg font-extrabold"><?= e($label) ?></span>
                <span class="mt-1 block text-sm text-muted"><?= e($hint) ?></span>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <label for="details" class="mt-6 block text-sm font-semibold">Tell Stella about it</label>
      <textarea id="details" name="details" rows="4" maxlength="1500" required class="mt-2 w-full rounded-lg border border-line px-3 py-2.5 focus:border-sea focus:outline-none"></textarea>
      <p class="mt-1 text-xs text-muted">English, Filipino or Taglish is fine.</p>

      <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div>
          <label for="budget" class="text-sm font-semibold">Starting budget</label>
          <select id="budget" name="budget" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2.5">
            <?php foreach (BUILD_BUDGETS as $b): ?><option><?= e($b) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="location" class="text-sm font-semibold">Location</label>
          <input id="location" name="location" maxlength="120" placeholder="City or area" class="mt-2 w-full rounded-lg border border-line px-3 py-2.5 focus:border-sea focus:outline-none">
        </div>
        <div>
          <label for="time" class="text-sm font-semibold">Time you can give</label>
          <select id="time" name="time" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2.5">
            <?php foreach (BUILD_TIME as $t): ?><option <?= $t === 'Part-time' ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
          </select>
        </div>
        <fieldset>
          <legend class="text-sm font-semibold">Language</legend>
          <div class="mt-2 inline-flex rounded-lg border border-line bg-paper p-1">
            <?php foreach (Prompts::LANGUAGES as $lang): ?>
              <label class="cursor-pointer"><input type="radio" name="language" value="<?= e($lang) ?>" class="peer sr-only" <?= $lang === 'Taglish' ? 'checked' : '' ?>>
                <span class="block rounded-md px-2.5 py-1.5 text-sm font-semibold text-muted peer-checked:bg-white peer-checked:text-sea peer-checked:shadow-sm"><?= e($lang) ?></span></label>
            <?php endforeach; ?>
          </div>
        </fieldset>
      </div>
      <div class="mt-6 flex flex-wrap items-center gap-3">
        <button id="ideasBtn" type="submit" class="inline-flex items-center gap-2 rounded-lg bg-sea px-6 py-3 font-semibold text-white hover:bg-seadeep disabled:opacity-60">
          <svg class="spin hidden" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="40 20"/></svg>
          <span>Show me 3 business ideas</span>
        </button>
        <p id="s1Error" role="alert" class="text-sm text-fail"></p>
      </div>
    </form>
  </section>

  <!-- Step 2 -->
  <section id="s2" class="mt-6 hidden">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="font-display text-xl font-extrabold">Three ways to start</h2>
      <div class="flex gap-2">
        <button type="button" id="backTo1" class="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold hover:border-sea">Edit my answers</button>
        <button type="button" id="regenBtn" class="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold hover:border-sea hover:text-sea disabled:opacity-60">Show different ideas</button>
      </div>
    </div>
    <p id="s2Error" role="alert" class="mt-2 text-sm text-fail"></p>
    <div id="ideaCards" class="mt-4 grid gap-5 lg:grid-cols-3"></div>
  </section>

  <!-- Step 3 -->
  <section id="s3" class="mt-6 hidden">
    <div id="shapeLoading" class="rounded-xl border border-line bg-white p-6">
      <p class="font-semibold">Shaping <span id="shapeName"></span>…</p>
      <div class="mt-4 space-y-3"><div class="shim h-4 w-2/3 rounded"></div><div class="shim h-4 w-1/2 rounded"></div><div class="shim h-24 rounded"></div></div>
    </div>
    <p id="s3Error" role="alert" class="mt-2 text-sm text-fail"></p>
    <form id="shapeForm" class="hidden grid gap-5 lg:grid-cols-[1.4fr_1fr]" novalidate></form>
  </section>
</div>

<style>
  .step-pill { border: 1px solid <?= BRAND['line'] ?>; background: #fff; color: <?= BRAND['muted'] ?>; border-radius: 999px; padding: 6px 14px; font-weight: 600; }
  .step-pill.on { border-color: <?= BRAND['sea'] ?>; background: <?= BRAND['foam'] ?>; color: <?= BRAND['sea'] ?>; }
  .step-pill.done { color: <?= BRAND['ink'] ?>; }
  .reveal > * { animation: rise .45s cubic-bezier(.2,.8,.2,1) both; }
  .reveal > *:nth-child(2) { animation-delay: .08s; } .reveal > *:nth-child(3) { animation-delay: .16s; }
  @keyframes rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
  .shim { background: linear-gradient(100deg, #F1F1F4 30%, <?= BRAND['foam'] ?> 50%, #F1F1F4 70%); background-size: 300% 100%; animation: shim 1.4s linear infinite; }
  @keyframes shim { to { background-position: -100% 0; } }
  @media (prefers-reduced-motion: reduce) { .reveal > *, .shim { animation: none !important; } }
</style>

<script>
const PLATFORMS = <?= json_encode($platformNames, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const TONES = <?= json_encode(BUILD_TONES) ?>;
const LANGS = <?= json_encode(Prompts::LANGUAGES) ?>;
const CHECKLIST = <?= json_encode(PH_CHECKLIST, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const $ = id => document.getElementById(id);
const esc = escapeHtml;
const startForm = $('startForm');
let ideas = [], language = 'Taglish';

function show(step) {
  [1, 2, 3].forEach(n => $('s' + n).classList.toggle('hidden', n !== step));
  document.querySelectorAll('.step-pill').forEach(p => { const n = +p.dataset.step; p.classList.toggle('on', n === step); p.classList.toggle('done', n < step); });
  window.scrollTo({ top: 0, behavior: 'smooth' });
}
show(1);

function syncExample() { $('details').placeholder = startForm.querySelector('input[name=start]:checked').dataset.example; }
startForm.querySelectorAll('input[name=start]').forEach(r => r.addEventListener('change', syncExample));
syncExample();

function busy(btn, on, label) {
  btn.disabled = on;
  const sp = btn.querySelector('.spin'); if (sp) sp.classList.toggle('hidden', !on);
  if (label) btn.querySelector('span:last-child').textContent = label;
}

async function getIdeas(regenerate) {
  const btn = $('ideasBtn'), err = regenerate ? $('s2Error') : $('s1Error');
  err.textContent = '';
  const fd = new FormData(startForm);
  language = fd.get('language') || 'Taglish';
  fd.append('action', 'ideas');
  if (regenerate) fd.append('regenerate', '1');
  busy(btn, true, 'Thinking of ideas…'); $('regenBtn').disabled = true;
  if (regenerate) $('regenBtn').textContent = 'Finding new ideas…';
  try {
    const data = await api('build.php', fd);
    ideas = data.ideas; renderIdeas(); show(2);
  } catch (e) { err.textContent = e.message; }
  busy(btn, false, 'Show me 3 business ideas'); $('regenBtn').disabled = false; $('regenBtn').textContent = 'Show different ideas';
}
startForm.addEventListener('submit', ev => { ev.preventDefault(); getIdeas(false); });
$('regenBtn').addEventListener('click', () => getIdeas(true));
$('backTo1').addEventListener('click', () => show(1));

function renderIdeas() {
  const box = $('ideaCards');
  box.classList.add('reveal');
  box.innerHTML = ideas.map((i, n) => `
    <article class="flex flex-col overflow-hidden rounded-xl border border-line bg-white">
      <div class="flex-1 p-5">
        <p class="text-xs font-semibold uppercase tracking-wider text-muted">${esc(i.industry || 'Idea ' + (n + 1))}</p>
        <h3 class="mt-1 font-display text-xl font-extrabold leading-snug">${esc(i.name)}</h3>
        <p class="mt-2 text-sm">${esc(i.one_liner)}</p>
        ${i.offer.length ? `<div class="mt-3 flex flex-wrap gap-1.5">${i.offer.map(o => `<span class="rounded-md bg-foam px-2 py-1 text-xs font-semibold text-sea">${esc(o)}</span>`).join('')}</div>` : ''}
        <dl class="mt-4 space-y-3 text-sm">
          <div><dt class="font-semibold">Why it fits you</dt><dd class="text-muted">${esc(i.why_you)}</dd></div>
          <div><dt class="font-semibold">Who buys</dt><dd class="text-muted">${esc(i.customer)}</dd></div>
          <div><dt class="font-semibold">How it sells</dt><dd class="text-muted">${esc(i.how_it_sells)}</dd></div>
          <div><dt class="font-semibold">Test it small</dt><dd class="text-muted">${esc(i.start_small)}</dd></div>
        </dl>
      </div>
      <div class="border-t border-line bg-warnbg/60 px-5 py-3 text-sm"><span class="font-semibold text-warn">Watch out:</span> ${esc(i.main_risk)}</div>
      <div class="p-4"><button type="button" data-pick="${n}" class="w-full rounded-lg bg-sea py-2.5 font-semibold text-white hover:bg-seadeep">Build this one</button></div>
    </article>`).join('');
  box.querySelectorAll('[data-pick]').forEach(b => b.addEventListener('click', () => shape(+b.dataset.pick)));
}

async function shape(n) {
  show(3);
  $('shapeName').textContent = ideas[n].name;
  $('shapeLoading').classList.remove('hidden'); $('shapeForm').classList.add('hidden'); $('s3Error').textContent = '';
  const fd = new FormData(); fd.append('action', 'shape'); fd.append('idea', n);
  try {
    const data = await api('build.php', fd);
    renderShape(data.shape);
  } catch (e) {
    $('s3Error').innerHTML = esc(e.message) + ' <button type="button" class="underline" id="retryShape">Try again</button>';
    $('retryShape').addEventListener('click', () => shape(n));
    $('shapeLoading').classList.add('hidden');
  }
}

const field = (label, html, hint = '') => `<div><label class="text-sm font-semibold">${label}</label>${html}${hint ? `<p class="mt-1 text-xs text-muted">${hint}</p>` : ''}</div>`;
const input = (name, value, attrs = '') => `<input name="${name}" value="${esc(value)}" ${attrs} class="mt-2 w-full rounded-lg border border-line px-3 py-2.5 focus:border-sea focus:outline-none">`;
const area = (name, value, rows = 3) => `<textarea name="${name}" rows="${rows}" class="mt-2 w-full rounded-lg border border-line px-3 py-2.5 focus:border-sea focus:outline-none">${esc(value)}</textarea>`;

function renderShape(s) {
  const f = $('shapeForm');
  const lang = s.language || language;
  f.innerHTML = `
    <div class="space-y-5 rounded-xl border border-line bg-white p-4 sm:p-6">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-sea">Your business</p>
        <h2 class="mt-1 font-display text-2xl font-extrabold">${esc(s.idea.name)}</h2>
      </div>
      ${field('Business name', `${s.names.length ? `<div class="mt-2 flex flex-wrap gap-2">${s.names.map(n => `<button type="button" data-name="${esc(n)}" class="rounded-full border border-line px-3 py-1.5 text-sm font-semibold hover:border-sea hover:text-sea">${esc(n)}</button>`).join('')}</div>` : ''}${input('name', s.names[0] || s.idea.name, 'maxlength="150" required')}`, 'Tap a suggestion or type your own. Check that the name is free when you register it with DTI.')}
      ${field('Tagline', input('tagline', s.tagline, 'maxlength="150"'))}
      ${field('What the business does', area('description', s.description, 4))}
      ${field('Who it\'s for', input('audience', s.audience, 'maxlength="255"'))}
      <div class="grid gap-5 sm:grid-cols-2">
        ${field('Products', area('products', s.products.join('\n'), 4), 'One per line')}
        ${field('Services', area('services', s.services.join('\n'), 4), 'One per line')}
      </div>
      <div class="grid gap-5 sm:grid-cols-2">
        ${field('Location', input('location', s.location, 'maxlength="150"'))}
        ${field('Brand voice', `<select name="tone" class="mt-2 w-full rounded-lg border border-line bg-white px-3 py-2.5">${TONES.map(t => `<option ${t === s.tone ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select>`)}
      </div>
      <fieldset><legend class="text-sm font-semibold">Language for posts</legend>
        <div class="mt-2 inline-flex rounded-lg border border-line bg-paper p-1">${LANGS.map(l => `<label class="cursor-pointer"><input type="radio" name="language" value="${esc(l)}" class="peer sr-only" ${l === lang ? 'checked' : ''}><span class="block rounded-md px-3 py-1.5 text-sm font-semibold text-muted peer-checked:bg-white peer-checked:text-sea peer-checked:shadow-sm">${esc(l)}</span></label>`).join('')}</div></fieldset>
      <fieldset><legend class="text-sm font-semibold">Where you'll post</legend>
        <div class="mt-2 flex flex-wrap gap-2">${PLATFORMS.map(p => `<label class="cursor-pointer"><input type="checkbox" name="platforms[]" value="${esc(p)}" class="peer sr-only" ${(s.platforms.length ? s.platforms.includes(p) : p === 'Facebook') ? 'checked' : ''}><span class="inline-flex rounded-full border border-line px-3 py-1.5 text-sm peer-checked:border-sea peer-checked:bg-foam peer-checked:font-semibold">${esc(p)}</span></label>`).join('')}</div></fieldset>
      ${field('Launch week brief', area('launch_theme', s.launch_theme, 3), 'Stella uses this to draft your first 7 days of posts on the calendar.')}
      <input type="hidden" name="industry" value="${esc(s.idea.industry)}">
      <div class="flex flex-wrap items-center gap-3 pt-2">
        <button id="saveBtn" class="inline-flex items-center gap-2 rounded-lg bg-sea px-6 py-3 font-semibold text-white hover:bg-seadeep disabled:opacity-60">
          <svg class="spin hidden" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="40 20"/></svg>
          <span>Make this my business and plan launch week</span></button>
        <button type="button" id="backTo2" class="rounded-lg border border-line bg-white px-4 py-3 text-sm font-semibold hover:border-sea">Back to ideas</button>
      </div>
      <p id="saveError" role="alert" class="text-sm text-fail"></p>
    </div>

    <aside class="space-y-5">
      <div class="rounded-xl border border-line bg-white p-5">
        <h3 class="font-display text-lg font-extrabold">How to price it</h3>
        <p class="mt-2 text-sm">${esc(s.pricing.approach)}</p>
        ${s.pricing.how_to_check.length ? `<ul class="mt-3 space-y-1.5 text-sm text-muted">${s.pricing.how_to_check.map(x => `<li>• ${esc(x)}</li>`).join('')}</ul>` : ''}
        <p class="mt-3 text-xs text-muted">Stella doesn't guess prices. Work out your costs and check what similar sellers near you charge.</p>
      </div>
      ${s.first_steps.length ? `<div class="rounded-xl border border-line bg-white p-5">
        <h3 class="font-display text-lg font-extrabold">This week</h3>
        <ol class="mt-3 space-y-2 text-sm">${s.first_steps.map((x, i) => `<li class="flex gap-3"><span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-foam text-xs font-bold text-sea">${i + 1}</span><span>${esc(x)}</span></li>`).join('')}</ol>
      </div>` : ''}
      <div class="rounded-xl border border-line bg-white p-5">
        <h3 class="font-display text-lg font-extrabold">Getting registered</h3>
        <ul class="mt-3 space-y-3 text-sm">${CHECKLIST.map(([t, w]) => `<li><p class="font-semibold">${esc(t)}</p><p class="text-muted">${esc(w)}</p></li>`).join('')}
        ${s.permits.map(p => `<li><p class="font-semibold">${esc(p.item)} <span class="rounded bg-warnbg px-1.5 py-0.5 text-xs font-semibold text-warn">May apply</span></p><p class="text-muted">${esc(p.why)}</p></li>`).join('')}</ul>
        <p class="mt-4 rounded-lg bg-paper p-3 text-xs text-muted">General guidance, not legal advice. Requirements and fees vary by city and business type, so confirm with DTI, your city or municipal hall, and BIR.</p>
      </div>
    </aside>`;
  $('shapeLoading').classList.add('hidden'); f.classList.remove('hidden');
  f.querySelectorAll('[data-name]').forEach(b => b.addEventListener('click', () => { f.querySelector('input[name=name]').value = b.dataset.name; }));
  $('backTo2').addEventListener('click', () => show(2));
}

$('shapeForm').addEventListener('submit', async ev => {
  ev.preventDefault();
  const f = ev.target, btn = $('saveBtn'), err = $('saveError');
  err.textContent = '';
  const fd = new FormData(f); fd.append('action', 'save');
  if (!fd.getAll('platforms[]').length) { err.textContent = 'Pick at least one platform.'; return; }
  busy(btn, true, 'Setting up your business…');
  try {
    const data = await api('build.php', fd);
    location.href = data.redirect;
  } catch (e) { err.textContent = e.message; busy(btn, false, 'Make this my business and plan launch week'); }
});
</script>
<?php page_footer(); ?>

<?php
// Add a business: a short guided setup (basics, offer, customers, location, voice, review).
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

const INDUSTRIES = ['Food and drinks', 'Beauty and wellness', 'Fashion and retail', 'Pets', 'Health and fitness', 'Home services', 'Education', 'Travel and events', 'Tech and gadgets', 'Professional services'];
const AUDIENCES = ['Students', 'Young professionals', 'Parents and families', 'Pet owners', 'Small business owners', 'OFWs and their families', 'Gen Z', 'Seniors', 'Tourists'];
const SERVES = ['Walk-in store', 'Delivery', 'Online, nationwide', 'Home service', 'By appointment'];
const TONES = [
    'Friendly' => 'Hi neighbors! Fresh batch just came out of the oven. Drop by before 5pm.',
    'Fun and playful' => 'Gutom ka na ba? Same. Kaya may bago kaming flavor. Tara na!',
    'Professional' => 'Our new service hours start Monday: 8 AM to 6 PM, Monday to Saturday.',
    'Warm and caring' => 'Every pet that stays with us goes home happy, rested and loved.',
    'Bold' => 'Forget everything you know about milk tea. This changes it.',
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $clean = fn($v, int $max) => mb_substr(trim((string)$v), 0, $max);
    $list = fn($v) => array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 120), (array)$v)));

    $name = $clean($_POST['name'] ?? '', 150);
    $industry = $clean(($_POST['industry'] ?? '') === 'Other' ? ($_POST['industry_other'] ?? '') : ($_POST['industry'] ?? ''), 100);
    $description = $clean($_POST['description'] ?? '', 2000);
    $services = $list($_POST['services'] ?? []);
    $products = $list($_POST['products'] ?? []);
    $audChips = array_values(array_intersect(AUDIENCES, (array)($_POST['audience'] ?? [])));
    $audText = $clean($_POST['audience_text'] ?? '', 200);
    $audience = mb_substr(implode(', ', $audChips) . ($audText !== '' ? ($audChips ? '; ' : '') . $audText : ''), 0, 255);
    $serve = array_values(array_intersect(SERVES, (array)($_POST['serve'] ?? [])));
    $city = $clean($_POST['location'] ?? '', 100);
    $location = mb_substr($city . ($serve ? ($city !== '' ? ' · ' : '') . implode(', ', $serve) : ''), 0, 150);
    $tone = array_key_exists($_POST['tone'] ?? '', TONES) ? $_POST['tone'] : 'Friendly';
    $language = in_array($_POST['language'] ?? '', Prompts::LANGUAGES, true) ? $_POST['language'] : 'Taglish';
    $platforms = implode(', ', array_values(array_filter((array)($_POST['platforms'] ?? []), fn($n) => platform_by_name((string)$n) !== null)));

    if ($name === '') $errors[] = 'Add the business name.';
    if ($description === '' && !$services && !$products) $errors[] = 'Tell us what the business offers.';
    if ($platforms === '') $errors[] = 'Pick at least one platform.';

    if (!$errors) {
        if (multi_business_ready()) {
            $st = db()->prepare('INSERT INTO business_profiles (name, industry, description, services, products, audience, location, tone, language, platforms, last_used_at)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $st->execute([$name, $industry, $description, implode("\n", $services), implode("\n", $products), $audience, $location, $tone, $language, $platforms, date('Y-m-d H:i:s')]);
        } else {
            // Before business.sql: fold the extra details into the description
            $desc = trim($description . ($services ? "\nServices: " . implode(', ', $services) : '') . ($products ? "\nProducts: " . implode(', ', $products) : ''));
            $st = db()->prepare('INSERT INTO business_profiles (name, description, audience, location, tone, platforms) VALUES (?, ?, ?, ?, ?, ?)');
            $st->execute([$name, $desc, $audience, $location, "$tone, $language", $platforms]);
        }
        switch_business((int)db()->lastInsertId());
        flash("Welcome aboard, $name. Stella will write for this business from now on.");
        redirect('dashboard.php');
    }
}

$hasBusiness = (bool)current_business();
$old = fn(string $k, $d = '') => $_POST[$k] ?? $d;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title>Add a business · Stella</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = { theme: { extend: {
  colors: Object.assign(<?= json_encode(BRAND) ?>, { fail: '#B42318', failbg: '#FCE9E7' }),
  fontFamily: { display: ['"Bricolage Grotesque"', 'system-ui', 'sans-serif'], sans: ['"IBM Plex Sans"', 'system-ui', 'sans-serif'] }
} } };
</script>
<style>
  :focus-visible { outline: 3px solid <?= BRAND['sea'] ?>; outline-offset: 2px; }
  @media (max-width: 640px) { input, select, textarea { font-size: 16px !important; } }
  .step { display: none; } .step.active { display: block; animation: stepIn .3s cubic-bezier(.2,.8,.2,1); }
  .step.active.back { animation-name: stepBack; }
  @keyframes stepIn { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: none; } }
  @keyframes stepBack { from { opacity: 0; transform: translateX(-24px); } to { opacity: 1; transform: none; } }
  @media (prefers-reduced-motion: reduce) { .step.active { animation: none; } #bar { transition: none !important; } }
</style>
</head>
<body class="bg-paper font-sans text-ink antialiased">
<div class="mx-auto flex min-h-screen max-w-2xl flex-col px-4 pb-28 pt-5 sm:px-6 sm:pt-10">
  <header class="flex items-center gap-3">
    <?= stella_mark(30) ?>
    <span class="font-display text-xl font-extrabold tracking-tight">stella</span>
    <a href="build.php" class="ml-auto text-sm font-semibold text-sea hover:text-seadeep">No business yet? Start from an idea</a>
    <?php if ($hasBusiness): ?><a href="dashboard.php" class="text-sm text-muted hover:text-ink">Cancel</a><?php endif; ?>
  </header>

  <div class="mt-6">
    <div class="flex items-center justify-between text-xs text-muted"><span id="stepNo">Step 1 of 6</span><span id="stepName">Basics</span></div>
    <div class="mt-2 h-1.5 rounded-full bg-line"><div id="bar" class="h-1.5 rounded-full bg-sea transition-all duration-300" style="width: 16%"></div></div>
  </div>

  <?php if ($errors): ?>
    <div role="alert" class="mt-5 rounded-lg bg-failbg px-4 py-3 text-sm text-fail"><?php foreach ($errors as $er) echo '<p>' . e($er) . '</p>'; ?></div>
  <?php endif; ?>

  <form id="ob" method="post" class="mt-6 flex-1" novalidate>
    <?= csrf_field() ?>

    <!-- 1. Basics -->
    <section class="step active" data-name="Basics">
      <h1 class="font-display text-2xl font-extrabold sm:text-3xl"><?= $hasBusiness ? 'Add another business' : "Let's set up your business" ?></h1>
      <p class="mt-1 text-sm text-muted">A few questions so every caption, idea and check sounds like this business. About 2 minutes.</p>
      <label for="name" class="mt-6 block text-sm font-semibold">Business name</label>
      <input id="name" name="name" required maxlength="150" value="<?= e($old('name')) ?>" placeholder="e.g. Grey's Pet Hotel" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 focus:border-sea focus:outline-none">
      <p class="mt-5 text-sm font-semibold">Industry</p>
      <div class="mt-2 flex flex-wrap gap-2">
        <?php foreach (array_merge(INDUSTRIES, ['Other']) as $ind): ?>
          <label class="cursor-pointer"><input type="radio" name="industry" value="<?= e($ind) ?>" class="peer sr-only" <?= $old('industry') === $ind ? 'checked' : '' ?>>
            <span class="block rounded-full border border-line bg-white px-3 py-1.5 text-sm peer-checked:border-sea peer-checked:bg-sea peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= e($ind) ?></span></label>
        <?php endforeach; ?>
      </div>
      <input id="industryOther" name="industry_other" maxlength="100" value="<?= e($old('industry_other')) ?>" placeholder="Your industry" class="mt-3 hidden w-full rounded-lg border border-line bg-white px-3 py-2.5 focus:border-sea focus:outline-none">
    </section>

    <!-- 2. Offer -->
    <section class="step" data-name="What you offer">
      <h2 class="font-display text-2xl font-extrabold sm:text-3xl">What do you offer?</h2>
      <p class="mt-1 text-sm text-muted">Posts get specific when Stella knows what you actually sell.</p>
      <label for="description" class="mt-6 block text-sm font-semibold">In a sentence or two, what does the business do?</label>
      <textarea id="description" name="description" rows="3" maxlength="2000" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 focus:border-sea focus:outline-none" placeholder="e.g. A cozy boarding and grooming place for dogs and cats in Marikina."><?= e($old('description')) ?></textarea>
      <?php foreach (['services' => ['Services', 'e.g. Pet boarding, then press Enter'], 'products' => ['Products', 'e.g. Organic dog treats, then press Enter']] as $key => [$label, $ph]): ?>
        <p class="mt-5 text-sm font-semibold"><?= $label ?> <span class="font-normal text-muted">(optional)</span></p>
        <div class="tags mt-1.5 flex flex-wrap items-center gap-2 rounded-lg border border-line bg-white p-2 focus-within:border-sea" data-name="<?= $key ?>[]">
          <?php foreach ((array)$old($key, []) as $t): ?><span class="tag inline-flex items-center gap-1 rounded-full bg-foam py-1 pl-3 pr-1 text-sm text-seadeep"><?= e($t) ?><input type="hidden" name="<?= $key ?>[]" value="<?= e($t) ?>"><button type="button" class="rm rounded-full px-1.5 text-muted" aria-label="Remove">×</button></span><?php endforeach; ?>
          <input class="tag-in min-w-[180px] flex-1 border-0 bg-transparent px-1 py-1 text-sm focus:outline-none" placeholder="<?= e($ph) ?>" aria-label="<?= $label ?>">
        </div>
      <?php endforeach; ?>
    </section>

    <!-- 3. Customers -->
    <section class="step" data-name="Customers">
      <h2 class="font-display text-2xl font-extrabold sm:text-3xl">Who are your customers?</h2>
      <p class="mt-1 text-sm text-muted">Pick any that fit. This shapes the hook, the humor and the hashtags.</p>
      <div class="mt-6 flex flex-wrap gap-2">
        <?php foreach (AUDIENCES as $a): ?>
          <label class="cursor-pointer"><input type="checkbox" name="audience[]" value="<?= e($a) ?>" class="peer sr-only" <?= in_array($a, (array)$old('audience', []), true) ? 'checked' : '' ?>>
            <span class="block rounded-full border border-line bg-white px-3 py-1.5 text-sm peer-checked:border-sea peer-checked:bg-sea peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= e($a) ?></span></label>
        <?php endforeach; ?>
      </div>
      <label for="audience_text" class="mt-5 block text-sm font-semibold">Describe them in your words <span class="font-normal text-muted">(optional)</span></label>
      <input id="audience_text" name="audience_text" maxlength="200" value="<?= e($old('audience_text')) ?>" placeholder="e.g. Busy fur parents who travel on weekends" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 focus:border-sea focus:outline-none">
    </section>

    <!-- 4. Location -->
    <section class="step" data-name="Location">
      <h2 class="font-display text-2xl font-extrabold sm:text-3xl">Where do you operate?</h2>
      <p class="mt-1 text-sm text-muted">Local posts land better with local details.</p>
      <label for="location" class="mt-6 block text-sm font-semibold">City or area</label>
      <input id="location" name="location" maxlength="100" value="<?= e($old('location')) ?>" placeholder="e.g. Marikina City, Metro Manila" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 focus:border-sea focus:outline-none">
      <p class="mt-5 text-sm font-semibold">How customers reach you</p>
      <div class="mt-2 grid gap-2 sm:grid-cols-2">
        <?php foreach (SERVES as $s): ?>
          <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-line bg-white px-3 py-2.5 text-sm has-[:checked]:border-sea has-[:checked]:bg-foam">
            <input type="checkbox" name="serve[]" value="<?= e($s) ?>" class="accent-[<?= BRAND['sea'] ?>]" <?= in_array($s, (array)$old('serve', []), true) ? 'checked' : '' ?>><?= e($s) ?></label>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 5. Voice -->
    <section class="step" data-name="Voice">
      <h2 class="font-display text-2xl font-extrabold sm:text-3xl">How should your posts sound?</h2>
      <p class="mt-1 text-sm text-muted">You can change this per post later.</p>
      <div class="mt-6 grid gap-2">
        <?php $selTone = $old('tone', 'Friendly'); foreach (TONES as $t => $ex): ?>
          <label class="flex cursor-pointer gap-3 rounded-lg border border-line bg-white p-3 has-[:checked]:border-sea has-[:checked]:bg-foam">
            <input type="radio" name="tone" value="<?= e($t) ?>" class="mt-1 accent-[<?= BRAND['sea'] ?>]" <?= $selTone === $t ? 'checked' : '' ?>>
            <span><span class="block text-sm font-semibold"><?= e($t) ?></span><span class="block text-sm text-muted">“<?= e($ex) ?>”</span></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="mt-5 text-sm font-semibold">Default language</p>
      <div class="mt-2 inline-flex rounded-lg border border-line bg-white p-1">
        <?php $selLang = $old('language', 'Taglish'); foreach (Prompts::LANGUAGES as $l): ?>
          <label class="cursor-pointer"><input type="radio" name="language" value="<?= e($l) ?>" class="peer sr-only" <?= $selLang === $l ? 'checked' : '' ?>>
            <span class="block rounded-md px-3 py-1.5 text-sm font-semibold text-muted peer-checked:bg-foam peer-checked:text-sea peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= e($l) ?></span></label>
        <?php endforeach; ?>
      </div>
      <p class="mt-5 text-sm font-semibold">Where do you post?</p>
      <div class="mt-2 flex flex-wrap gap-2">
        <?php foreach (platforms() as $p): ?>
          <label class="cursor-pointer"><input type="checkbox" name="platforms[]" value="<?= e($p['name']) ?>" class="peer sr-only" <?= in_array($p['name'], (array)$old('platforms', []), true) ? 'checked' : '' ?>>
            <span class="flex items-center gap-2 rounded-full border border-line bg-white py-1 pl-1 pr-3 text-sm peer-checked:border-sea peer-checked:bg-foam peer-checked:font-semibold peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= platform_badge($p['name'], 'w-6 h-6 text-[9px]') ?><?= e($p['name']) ?></span></label>
        <?php endforeach; ?>
      </div>
    </section>

    <!-- 6. Review -->
    <section class="step" data-name="Review">
      <h2 class="font-display text-2xl font-extrabold sm:text-3xl">Looks right?</h2>
      <p class="mt-1 text-sm text-muted">Tap a section to change it.</p>
      <dl id="review" class="mt-6 divide-y divide-line overflow-hidden rounded-xl border border-line bg-white"></dl>
    </section>

    <p id="stepErr" role="alert" class="mt-4 text-sm text-fail"></p>

    <!-- Sticky actions -->
    <div class="fixed inset-x-0 bottom-0 border-t border-line bg-white/95 backdrop-blur" style="padding-bottom: env(safe-area-inset-bottom)">
      <div class="mx-auto flex max-w-2xl items-center gap-3 px-4 py-3 sm:px-6">
        <button type="button" id="back" class="invisible rounded-lg px-4 py-2.5 font-semibold text-muted hover:text-ink">Back</button>
        <button type="button" id="next" class="ml-auto rounded-lg bg-sea px-6 py-2.5 font-semibold text-white hover:bg-seadeep">Continue</button>
        <button type="submit" id="finish" class="ml-auto hidden rounded-lg bg-sea px-6 py-2.5 font-semibold text-white hover:bg-seadeep">Create business</button>
      </div>
    </div>
  </form>
</div>

<script>
const steps = [...document.querySelectorAll('.step')];
let i = 0;
const form = document.getElementById('ob');
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

// "Other" industry
const other = document.getElementById('industryOther');
const syncOther = () => other.classList.toggle('hidden', form.querySelector('input[name=industry]:checked')?.value !== 'Other');
form.querySelectorAll('input[name=industry]').forEach(r => r.addEventListener('change', () => { syncOther(); if (r.value === 'Other') other.focus(); }));
syncOther();

// Tag inputs: Enter or comma adds a chip
document.querySelectorAll('.tags').forEach(box => {
  const input = box.querySelector('.tag-in');
  const add = () => {
    const v = input.value.replace(/,$/, '').trim();
    if (!v) return;
    const chip = document.createElement('span');
    chip.className = 'tag inline-flex items-center gap-1 rounded-full bg-foam py-1 pl-3 pr-1 text-sm text-seadeep';
    chip.innerHTML = `${esc(v)}<input type="hidden" name="${box.dataset.name}" value="${esc(v)}"><button type="button" class="rm rounded-full px-1.5 text-muted" aria-label="Remove ${esc(v)}">×</button>`;
    box.insertBefore(chip, input);
    input.value = '';
  };
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); add(); }
    if (e.key === 'Backspace' && !input.value) { const last = input.previousElementSibling; if (last && last.classList.contains('tag')) last.remove(); }
  });
  input.addEventListener('blur', add);
  box.addEventListener('click', e => { if (e.target.classList.contains('rm')) e.target.parentElement.remove(); else input.focus(); });
});

function valid(n) {
  const err = document.getElementById('stepErr');
  err.textContent = '';
  if (n === 0 && !form.elements.name.value.trim()) { err.textContent = 'Add the business name.'; form.elements.name.focus(); return false; }
  if (n === 1 && !form.elements.description.value.trim() && !form.querySelector('input[name="services[]"], input[name="products[]"]')) {
    err.textContent = 'Tell us what the business does, or add a service or product.'; form.elements.description.focus(); return false;
  }
  if (n === 4 && !form.querySelector('input[name="platforms[]"]:checked')) { err.textContent = 'Pick at least one platform.'; return false; }
  return true;
}

function review() {
  const fd = new FormData(form);
  const industry = fd.get('industry') === 'Other' ? fd.get('industry_other') : fd.get('industry');
  const aud = [...fd.getAll('audience[]'), fd.get('audience_text')].filter(Boolean).join(', ');
  const loc = [fd.get('location'), fd.getAll('serve[]').join(', ')].filter(Boolean).join(' · ');
  const rows = [
    [0, 'Business', [fd.get('name'), industry].filter(Boolean).join(' · ')],
    [1, 'What you offer', [fd.get('description'), fd.getAll('services[]').join(', '), fd.getAll('products[]').join(', ')].filter(Boolean).join(' · ')],
    [2, 'Customers', aud],
    [3, 'Location', loc],
    [4, 'Voice', `${fd.get('tone')} · ${fd.get('language')} · ${fd.getAll('platforms[]').join(', ')}`],
  ];
  document.getElementById('review').innerHTML = rows.map(([n, k, v]) => `<button type="button" data-go="${n}" class="flex w-full gap-4 px-4 py-3 text-left hover:bg-paper">
      <dt class="w-28 shrink-0 text-sm text-muted">${k}</dt><dd class="flex-1 text-sm">${v ? esc(v) : '<span class="text-muted">Not set</span>'}</dd>
      <span class="text-xs font-semibold text-sea">Edit</span></button>`).join('');
  document.querySelectorAll('[data-go]').forEach(b => b.addEventListener('click', () => go(+b.dataset.go)));
}

function go(n) {
  const back = n < i;
  steps[i].classList.remove('active', 'back');
  i = n;
  steps[i].classList.add('active');
  steps[i].classList.toggle('back', back);
  document.getElementById('stepNo').textContent = `Step ${i + 1} of ${steps.length}`;
  document.getElementById('stepName').textContent = steps[i].dataset.name;
  document.getElementById('bar').style.width = ((i + 1) / steps.length * 100) + '%';
  document.getElementById('back').classList.toggle('invisible', i === 0);
  document.getElementById('next').classList.toggle('hidden', i === steps.length - 1);
  document.getElementById('finish').classList.toggle('hidden', i !== steps.length - 1);
  if (i === steps.length - 1) review();
  const f = steps[i].querySelector('input:not([type=hidden]):not([type=radio]):not([type=checkbox]), textarea');
  if (f && matchMedia('(min-width: 640px)').matches) f.focus();
  window.scrollTo({ top: 0 });
}
document.getElementById('next').addEventListener('click', () => { if (valid(i)) go(i + 1); });
document.getElementById('back').addEventListener('click', () => go(i - 1));
form.addEventListener('keydown', e => {
  if (e.key === 'Enter' && e.target.tagName === 'INPUT' && !e.target.classList.contains('tag-in') && i < steps.length - 1) {
    e.preventDefault();
    if (valid(i)) go(i + 1);
  }
});
form.addEventListener('submit', e => { for (let n = 0; n < steps.length - 1; n++) if (!valid(n)) { e.preventDefault(); go(n); valid(n); return; } });
<?php if ($errors): ?>go(5);<?php endif; ?>
</script>
</body>
</html>

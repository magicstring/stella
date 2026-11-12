<?php
// Edit the active business. New businesses are added through onboarding.php.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

$business = current_business();
if (!$business) redirect('onboarding.php');
$multi = multi_business_ready();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $data = [
        'name' => $f('name', 150), 'industry' => $f('industry', 100), 'description' => $f('description', 2000),
        'services' => $f('services', 3000), 'products' => $f('products', 3000), 'audience' => $f('audience', 255),
        'location' => $f('location', 150), 'tone' => $f('tone', 100),
        'language' => in_array($_POST['language'] ?? '', Prompts::LANGUAGES, true) ? $_POST['language'] : 'Taglish',
        'platforms' => implode(', ', array_values(array_filter((array)($_POST['platforms'] ?? []), fn($n) => platform_by_name((string)$n) !== null))),
    ];
    if ($data['name'] === '') $errors[] = 'Add the business name.';
    if ($data['platforms'] === '') $errors[] = 'Pick at least one platform.';

    if (!$errors) {
        if ($multi) {
            $st = db()->prepare('UPDATE business_profiles SET name = ?, industry = ?, description = ?, services = ?, products = ?, audience = ?, location = ?, tone = ?, language = ?, platforms = ? WHERE id = ?');
            $st->execute([...array_values($data), $business['id']]);
        } else {
            $st = db()->prepare('UPDATE business_profiles SET name = ?, description = ?, audience = ?, location = ?, tone = ?, platforms = ? WHERE id = ?');
            $st->execute([$data['name'], $data['description'], $data['audience'], $data['location'], $data['tone'], $data['platforms'], $business['id']]);
        }
        flash('Business saved.');
        redirect('profile.php');
    }
    $business = array_merge($business, $data);
}

$chosen = array_map('trim', explode(',', (string)$business['platforms']));
$all = all_businesses();
$input = 'w-full rounded-lg border border-line bg-white px-3 py-2 focus:border-sea focus:outline-none';

page_header('Business', 'profile.php');
?>
<div class="flex flex-wrap items-end justify-between gap-3">
  <div>
    <h1 class="font-display text-2xl font-extrabold tracking-tight sm:text-3xl lg:text-4xl"><?= e($business['name']) ?></h1>
    <p class="mt-1 text-sm text-muted">Everything Stella writes for this business follows this profile.</p>
  </div>
  <a href="onboarding.php" class="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold hover:border-sea hover:text-sea">Add another business</a>
</div>

<?php if ($errors): ?>
  <div role="alert" class="mt-5 rounded-lg bg-failbg px-4 py-3 text-sm text-fail"><?php foreach ($errors as $er) echo '<p>' . e($er) . '</p>'; ?></div>
<?php endif; ?>

<div class="mt-6 grid items-start gap-6 lg:grid-cols-[1fr_280px]">
  <form method="post" class="space-y-5 rounded-xl border border-line bg-white p-4 sm:p-6">
    <?= csrf_field() ?>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label for="name" class="mb-1 block text-sm font-semibold">Business name</label><input id="name" name="name" required maxlength="150" value="<?= e($business['name']) ?>" class="<?= $input ?>"></div>
      <?php if ($multi): ?><div><label for="industry" class="mb-1 block text-sm font-semibold">Industry</label><input id="industry" name="industry" maxlength="100" value="<?= e($business['industry'] ?? '') ?>" class="<?= $input ?>"></div><?php endif; ?>
    </div>
    <div><label for="description" class="mb-1 block text-sm font-semibold">What it does</label><textarea id="description" name="description" rows="3" class="<?= $input ?>"><?= e($business['description']) ?></textarea></div>
    <?php if ($multi): ?>
      <div class="grid gap-4 sm:grid-cols-2">
        <div><label for="services" class="mb-1 block text-sm font-semibold">Services <span class="font-normal text-muted">(one per line)</span></label><textarea id="services" name="services" rows="4" class="<?= $input ?>"><?= e($business['services'] ?? '') ?></textarea></div>
        <div><label for="products" class="mb-1 block text-sm font-semibold">Products <span class="font-normal text-muted">(one per line)</span></label><textarea id="products" name="products" rows="4" class="<?= $input ?>"><?= e($business['products'] ?? '') ?></textarea></div>
      </div>
    <?php endif; ?>
    <div class="grid gap-4 sm:grid-cols-2">
      <div><label for="audience" class="mb-1 block text-sm font-semibold">Customers</label><input id="audience" name="audience" maxlength="255" value="<?= e($business['audience']) ?>" class="<?= $input ?>"></div>
      <div><label for="location" class="mb-1 block text-sm font-semibold">Location</label><input id="location" name="location" maxlength="150" value="<?= e($business['location']) ?>" class="<?= $input ?>"></div>
      <div><label for="tone" class="mb-1 block text-sm font-semibold">Tone</label><input id="tone" name="tone" list="tones" maxlength="100" value="<?= e($business['tone']) ?>" class="<?= $input ?>">
        <datalist id="tones"><option value="Friendly"><option value="Fun and playful"><option value="Professional"><option value="Warm and caring"><option value="Bold"></datalist></div>
      <?php if ($multi): ?>
        <div><span class="mb-1 block text-sm font-semibold">Default language</span>
          <div class="inline-flex rounded-lg border border-line bg-paper p-1">
            <?php foreach (Prompts::LANGUAGES as $l): ?>
              <label class="cursor-pointer"><input type="radio" name="language" value="<?= e($l) ?>" class="peer sr-only" <?= ($business['language'] ?: 'Taglish') === $l ? 'checked' : '' ?>>
                <span class="block rounded-md px-3 py-1.5 text-sm font-semibold text-muted peer-checked:bg-white peer-checked:text-sea peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= e($l) ?></span></label>
            <?php endforeach; ?>
          </div></div>
      <?php endif; ?>
    </div>
    <fieldset>
      <legend class="mb-2 text-sm font-semibold">Platforms</legend>
      <div class="flex flex-wrap gap-2">
        <?php foreach (platforms() as $p): ?>
          <label class="cursor-pointer"><input type="checkbox" name="platforms[]" value="<?= e($p['name']) ?>" class="peer sr-only" <?= in_array($p['name'], $chosen, true) ? 'checked' : '' ?>>
            <span class="flex items-center gap-2 rounded-full border border-line py-1 pl-1 pr-3 text-sm peer-checked:border-sea peer-checked:bg-foam peer-checked:font-semibold peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= platform_badge($p['name'], 'w-6 h-6 text-[9px]') ?><?= e($p['name']) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <button class="w-full rounded-lg bg-sea px-6 py-2.5 font-semibold text-white hover:bg-seadeep sm:w-auto">Save changes</button>
  </form>

  <aside class="rounded-xl border border-line bg-white p-4">
    <h2 class="text-sm font-semibold">Your businesses</h2>
    <ul class="mt-3 space-y-1">
      <?php foreach ($all as $b): $on = (int)$b['id'] === (int)$business['id']; ?>
        <li>
          <form method="post" action="business.php">
            <?= csrf_field() ?><input type="hidden" name="business_id" value="<?= (int)$b['id'] ?>">
            <button <?= $on ? 'disabled' : '' ?> class="flex w-full items-center gap-3 rounded-lg px-2 py-2 text-left <?= $on ? 'bg-foam' : 'hover:bg-paper' ?>">
              <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-foam font-display font-extrabold text-sea"><?= e(mb_strtoupper(mb_substr($b['name'], 0, 1))) ?></span>
              <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold"><?= e($b['name']) ?></span><span class="block truncate text-xs text-muted"><?= e($b['industry'] ?: ($on ? 'Active' : 'Switch to this business')) ?></span></span>
              <?php if ($on): ?><span class="text-xs font-semibold text-sea">Active</span><?php endif; ?>
            </button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
    <a href="onboarding.php" class="mt-3 block rounded-lg border border-dashed border-line py-2 text-center text-sm font-semibold text-sea hover:border-sea">+ Add a business</a>
    <?php if (!$multi): ?><p class="mt-3 text-xs text-warn">Import business.sql to keep each business's posts and checks separate.</p><?php endif; ?>
  </aside>
</div>
<?php page_footer(); ?>

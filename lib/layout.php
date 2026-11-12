<?php
// Stella page chrome.
// Concept: clean white rail and pale grey canvas, with Nova indigo used sparingly for actions and the
// sliding marker that glides to the item you click while the page slides away. All colours live in BRAND.

const BRAND = [
    'ink'     => '#0E1433', // Midnight: text, dark surfaces
    'sea'     => '#5236E0', // Nova: primary actions, links, active marker
    'seadeep' => '#3F27B8', // Nova deep: hover
    'foam'    => '#EEEBFD', // Halo: selected states, highlight panels
    'paper'   => '#F6F6FA', // Canvas
    'line'    => '#E6E6EF', // borders
    'muted'   => '#596080', // Dusk: secondary text
    'night'   => '#0E1433', // logo tile, login hero, dark panels
    'star'    => '#FFB82E', // Starlight: accent only (logo star, Taking off). Never text on white.
    'nebula'  => '#FF6B9A', // Climbing alerts, second chart series
    'nova2'   => '#8B7BFF', // Nova on dark backgrounds
];

// The Stella Orbit mark: an S-shaped constellation inside a ring, led by a gold star.
function stella_mark(int $size = 32, bool $dark = false): string {
    $ring = $dark ? '#4A4F86' : '#C9C0F7';
    $dot  = $dark ? '#FFFFFF' : BRAND['sea'];
    $star = BRAND['star'];
    $dots = '';
    foreach ([[25, 12, 3.2], [16, 22, 4], [32, 32, 3], [48, 42, 4], [40, 52, 3.2], [18, 52, 4.4]] as [$x, $y, $r]) {
        $dots .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="' . $dot . '"/>';
    }
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 64 64" class="shrink-0" aria-hidden="true">'
        . '<circle cx="32" cy="32" r="29" fill="none" stroke="' . $ring . '" stroke-width="2.4"/>'
        . '<g transform="translate(8 8) scale(.75)"><polyline points="46,12 25,12 16,22 32,32 48,42 40,52 18,52" fill="none" stroke="' . $ring . '" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>' . $dots . '</g>'
        . '<path fill="' . $star . '" d="M42.5 9.5C43.85 15.65 43.85 15.65 50 17C43.85 18.35 43.85 18.35 42.5 24.5C41.15 18.35 41.15 18.35 35 17C41.15 15.65 41.15 15.65 42.5 9.5Z"/>'
        . '<circle cx="11.5" cy="52.5" r="2.6" fill="' . $star . '"/></svg>';
}

function nav_icon(string $name): string {
    $paths = [
        'spark'   => '<path d="M12 3c.5 4.5 1.5 5.5 6 6-4.5.5-5.5 1.5-6 6-.5-4.5-1.5-5.5-6-6 4.5-.5 5.5-1.5 6-6z"/><path d="M19 15v5M16.5 17.5h5"/>',
        'home'    => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'create'  => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'ideas'   => '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7V16h8v-1.3A7 7 0 0 0 12 2z"/>',
        'scout'   => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'queue'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'trends'  => '<path d="M3 17l6-6 4 4 8-8"/><path d="M14 7h7v7"/>',
        'gear'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-2.9 1.2V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-2.9-1.2l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.7 1.7 0 0 0 3 15.1a1.7 1.7 0 0 0-1.6-1.1H1.3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 3 8.9a1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.7 1.7 0 0 0 8.9 3h.1a1.7 1.7 0 0 0 1-1.6V1.3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.6 1h.1a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.6 1z"/>',
        'buoy'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="4"/><path d="m5.6 5.6 3.6 3.6M14.8 14.8l3.6 3.6M18.4 5.6l-3.6 3.6M9.2 14.8l-3.6 3.6"/>',
        'list'    => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        'bell'    => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
        'menu'    => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'business'=> '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/>',
        'logout'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    ];
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? '') . '</svg>';
}

// Small round platform badge (initials, not brand logos)
function platform_badge(string $name, string $size = 'w-8 h-8 text-[11px]'): string {
    $map = [
        'tiktok'         => ['TT', '#111827'],
        'instagram'      => ['IG', '#C13584'],
        'facebook'       => ['FB', '#1877F2'],
        'youtube shorts' => ['YT', '#DC2626'],
    ];
    [$label, $bg] = $map[strtolower($name)] ?? [mb_strtoupper(mb_substr($name, 0, 2)), BRAND['ink']];
    return '<span class="inline-flex shrink-0 items-center justify-center rounded-full font-bold text-white ' . $size . '" style="background:' . $bg . '" title="' . e($name) . '" aria-hidden="true">' . e($label) . '</span>';
}

// Business switcher: the active business, a list to switch to, and "Add a business". Opens up (sidebar) or down (top bar).
function biz_switcher(?array $current, string $dir): string {
    $list = $current ? all_businesses() : [];
    $initial = e(mb_strtoupper(mb_substr($current['name'] ?? '+', 0, 1)));
    $panel = $dir === 'up' ? 'bottom-full mb-2 left-0 right-0' : 'top-full mt-2 left-0 w-72';
    ob_start(); ?>
    <details class="biz relative">
      <summary class="flex cursor-pointer items-center gap-2.5 rounded-lg <?= $dir === 'up' ? 'border border-line p-2.5 hover:border-sea' : 'py-1 pr-2' ?>">
        <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-foam font-display font-extrabold text-sea"><?= $initial ?></span>
        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm font-semibold"><?= e($current['name'] ?? 'Add your business') ?></span>
          <?php if ($dir === 'up'): ?><span class="block text-xs text-muted"><?= count($list) > 1 ? 'Switch business' : 'Business profile' ?></span><?php endif; ?>
        </span>
        <svg class="shrink-0 text-muted" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m7 15 5 5 5-5M7 9l5-5 5 5"/></svg>
      </summary>
      <div class="absolute <?= $panel ?> z-50 overflow-hidden rounded-xl border border-line bg-white shadow-xl">
        <?php if ($list): ?>
          <p class="px-3 pt-3 pb-1 text-xs text-muted">Businesses</p>
          <ul class="max-h-64 overflow-y-auto px-1.5">
            <?php foreach ($list as $b): $on = (int)$b['id'] === (int)($current['id'] ?? 0); ?>
              <li><form method="post" action="business.php"><?= csrf_field() ?><input type="hidden" name="business_id" value="<?= (int)$b['id'] ?>">
                <button class="flex w-full items-center gap-2.5 rounded-lg px-2 py-2 text-left <?= $on ? 'bg-foam' : 'hover:bg-paper' ?>" <?= $on ? 'aria-current="true"' : '' ?>>
                  <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-paper font-display text-sm font-extrabold text-sea"><?= e(mb_strtoupper(mb_substr($b['name'], 0, 1))) ?></span>
                  <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold"><?= e($b['name']) ?></span><?php if ($b['industry']): ?><span class="block truncate text-xs text-muted"><?= e($b['industry']) ?></span><?php endif; ?></span>
                  <?php if ($on): ?><svg class="text-sea" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg><?php endif; ?>
                </button></form></li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div class="mt-1.5 border-t border-line p-1.5">
          <?php if ($current): ?><a href="profile.php" class="flex items-center gap-2 rounded-lg px-2 py-2 text-sm hover:bg-paper"><?= nav_icon('business') ?> Edit business profile</a><?php endif; ?>
          <a href="onboarding.php" class="flex items-center gap-2 rounded-lg px-2 py-2 text-sm font-semibold text-sea hover:bg-foam"><?= nav_icon('plus') ?> Add a business</a>
        </div>
      </div>
    </details>
    <?php return ob_get_clean();
}

function sidebar_data(): array {
    $scheduled = [];
    $total = 0;
    try {
        foreach (db()->query("SELECT platform_id, COUNT(*) n FROM scheduled_posts WHERE status = 'scheduled' AND " . biz_where() . " GROUP BY platform_id")->fetchAll() as $r) {
            $scheduled[(int)$r['platform_id']] = (int)$r['n'];
            $total += (int)$r['n'];
        }
    } catch (Throwable $e) {
    }
    return ['scheduled' => $scheduled, 'total' => $total, 'accounts' => accounts(), 'business' => current_business(), 'alerts' => Insights::unseenCount()];
}

function page_header(string $title, string $active = ''): void {
    $GLOBALS['__active'] = $active;
    $d = sidebar_data();
    $groups = [
        'Start' => [
            ['build.php', 'Start a business', 'spark', null],
        ],
        'Plan' => [
            ['dashboard.php', 'Overview', 'home', null],
            ['suggest.php', 'Post ideas', 'ideas', null],
            ['scout.php', 'Why it worked', 'scout', null],
            ['trends.php', 'Trends', 'trends', null],
        ],
        'Make' => [
            ['index.php', 'Pre-flight check', 'create', null],
        ],
        'Ship' => [
            ['calendar.php', 'Calendar', 'queue', null],
            ['history.php', 'Queue and history', 'list', $d['total'] ?: null],
            ['accounts.php', 'Channels', 'gear', count($d['accounts']) ?: null],
        ],
    ];
    $crumb = '';
    foreach ($groups as $g => $items) {
        foreach ($items as $it) if ($it[0] === $active) $crumb = $g;
    }
    $connected = [];
    foreach (platforms() as $p) {
        if (isset($d['accounts'][(int)$p['id']])) $connected[] = $p;
    }
    $f = flash();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#FFFFFF">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> · Stella</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,800&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = {
  theme: { extend: {
    colors: Object.assign(<?= json_encode(BRAND) ?>, {
      pass: '#1F7A4D', passbg: '#E6F4EC', warn: '#9A6200', warnbg: '#FDF3DF', fail: '#B42318', failbg: '#FCE9E7'
    }),
    fontFamily: {
      display: ['"Bricolage Grotesque"', 'system-ui', 'sans-serif'],
      sans: ['"IBM Plex Sans"', 'system-ui', 'sans-serif']
    }
  } }
};
</script>
<style>
  :focus-visible { outline: 3px solid <?= BRAND['sea'] ?>; outline-offset: 2px; }
  .spin { animation: spin 0.9s linear infinite; } @keyframes spin { to { transform: rotate(360deg); } }

  /* Page motion: content slides in on arrival and slides out when you pick another menu item. */
  #page { animation: slideIn .32s cubic-bezier(.2,.8,.2,1) backwards; }
  /* Phones: 16px inputs stop iOS from zooming in; no grey flash on tap */
  @media (max-width: 640px) { input, select, textarea { font-size: 16px !important; } }
  * { -webkit-tap-highlight-color: transparent; }
  .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
  details.biz > summary { list-style: none; } details.biz > summary::-webkit-details-marker { display: none; }
  #page.leaving { animation: slideOut .18s cubic-bezier(.4,0,1,1) both; }
  @keyframes slideIn  { from { opacity: 0; transform: translateX(28px); } to { opacity: 1; transform: none; } }
  @keyframes slideOut { from { opacity: 1; transform: none; } to { opacity: 0; transform: translateX(-28px); } }

  /* The marker that glides between menu items */
  #navMarker { transition: transform .26s cubic-bezier(.2,.8,.2,1), height .26s cubic-bezier(.2,.8,.2,1), opacity .2s; }

  @media (prefers-reduced-motion: reduce) {
    *, #page, #page.leaving { animation: none !important; transition: none !important; }
  }
</style>
<script>
  // POST FormData to a JSON endpoint with the CSRF token. Throws with the server's message on error.
  async function api(url, formData) {
    const res = await fetch(url, {
      method: 'POST',
      body: formData,
      headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf]').content, 'Accept': 'application/json' }
    });
    let data = {};
    try { data = await res.json(); } catch (e) { throw new Error('The server sent an unexpected reply (HTTP ' + res.status + '). Check the PHP error log.'); }
    if (!res.ok || data.error) throw new Error(data.error || ('Request failed (HTTP ' + res.status + ').'));
    return data;
  }
  function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  }
</script>
</head>
<body class="bg-paper text-ink font-sans antialiased">
<div class="lg:flex min-h-screen">

  <!-- Harbour rail -->
  <aside id="sidebar" class="fixed inset-y-0 left-0 z-40 w-72 -translate-x-full transition-transform duration-300 lg:translate-x-0 lg:sticky lg:top-0 lg:h-screen lg:w-60 shrink-0 bg-white border-r border-line flex flex-col overflow-y-auto" aria-label="Sidebar">
    <div class="px-5 pt-5 pb-4">
      <a href="dashboard.php" data-nav class="flex items-center gap-2.5 font-display text-[28px] font-extrabold tracking-tight leading-none" aria-label="Stella home">
        <?= stella_mark(34) ?>
        stella
      </a>
      <p class="mt-1.5 text-xs text-muted">Find the posts that shine.</p>
    </div>

    <div class="px-4">
      <a href="help.php" data-nav class="flex items-center justify-between rounded-lg bg-sea px-4 py-2.5 font-semibold text-white shadow-sm hover:bg-seadeep">
        New post <?= nav_icon('plus') ?>
      </a>
    </div>

    <nav id="nav" class="relative mt-3 px-3" aria-label="Main">
      <span id="navMarker" class="pointer-events-none absolute left-3 right-3 top-0 rounded-lg bg-foam opacity-0" aria-hidden="true">
        <span class="absolute left-0 top-2 bottom-2 w-1 rounded-r bg-sea"></span>
      </span>
      <?php foreach ($groups as $group => $items): ?>
        <p class="px-3 pt-3 pb-1 text-xs text-muted"><?= e($group) ?></p>
        <?php foreach ($items as [$href, $label, $icon, $count]): $on = $active === $href; ?>
          <a href="<?= $href ?>" data-nav class="relative flex items-center gap-3 rounded-lg px-3 py-2 text-[15px] <?= $on ? 'text-ink font-semibold' : 'text-ink/70 hover:text-ink hover:bg-paper' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
            <span class="<?= $on ? 'text-sea' : 'text-muted' ?>"><?= nav_icon($icon) ?></span>
            <span class="flex-1"><?= e($label) ?></span>
            <?php if ($count): ?><span class="rounded-full bg-paper px-2 text-xs tabular-nums text-muted"><?= (int)$count ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </nav>

    <div class="mt-auto p-3">
      <?= biz_switcher($d['business'], 'up') ?>
      <?php if (APP_PASSWORD !== '' && !empty($_SESSION['signed_in'])): ?>
        <a href="logout.php" class="mt-1 flex items-center gap-2 px-3 py-1.5 text-sm text-muted hover:text-ink"><?= nav_icon('logout') ?> Sign out</a>
      <?php endif; ?>
    </div>
  </aside>
  <div id="scrim" class="fixed inset-0 z-30 hidden bg-ink/30 lg:hidden"></div>

  <!-- Content -->
  <div class="flex-1 min-w-0">
    <header class="sticky top-0 z-20 border-b border-line bg-white/90 backdrop-blur">
      <div class="max-w-6xl mx-auto flex items-center gap-2 sm:gap-3 px-4 sm:px-6 lg:px-8 h-12 lg:h-14">
        <a href="dashboard.php" data-nav class="lg:hidden shrink-0" aria-label="Stella home"><?= stella_mark(28) ?></a>
        <div class="lg:hidden min-w-0"><?= biz_switcher($d['business'], 'down') ?></div>
        <p class="hidden lg:block text-sm text-muted truncate"><?= $crumb ? e($crumb) . ' <span aria-hidden="true">/</span> ' : '' ?><span class="text-ink font-semibold"><?= e($title) ?></span></p>
        <a href="calendar.php#alerts" data-nav class="ml-auto relative shrink-0 rounded-full border border-line bg-white p-1.5 text-muted hover:border-sea hover:text-sea" title="Alerts">
          <span class="sr-only">Alerts</span><?= nav_icon('bell') ?>
          <span id="bellCount" class="<?= $d['alerts'] ? '' : 'hidden ' ?>absolute -right-1 -top-1 min-w-[18px] rounded-full bg-sea px-1 text-center text-[11px] font-semibold leading-[18px] text-white tabular-nums"><?= (int)$d['alerts'] ?></span>
        </a>
        <a href="accounts.php" data-nav class="flex shrink-0 items-center gap-2 rounded-full border border-line bg-white p-1 sm:pr-3 text-sm hover:border-sea" title="Channels">
          <span class="sr-only">Channels</span><span class="flex -space-x-2">
            <?php if (!$connected): ?><span class="inline-flex w-7 h-7 items-center justify-center rounded-full bg-line text-muted"><?= nav_icon('plus') ?></span><?php endif; ?>
            <?php foreach ($connected as $p): ?><span class="rounded-full ring-2 ring-white"><?= platform_badge($p['name'], 'w-7 h-7 text-[10px]') ?></span><?php endforeach; ?>
          </span>
          <span class="hidden sm:inline"><?= $connected ? count($connected) . ' channel' . (count($connected) === 1 ? '' : 's') : 'Connect channels' ?></span>
        </a>
      </div>
    </header>
    <main id="page" class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 pt-5 pb-28 sm:pt-6 lg:py-8">
<?php if ($f): ?>
      <div role="status" class="mb-4 sm:mb-6 rounded-lg border px-4 py-2.5 text-sm <?= $f['type'] === 'error' ? 'bg-failbg border-fail/30 text-fail' : 'bg-passbg border-pass/30 text-pass' ?>"><?= e($f['message']) ?></div>
<?php endif; ?>
<?php
}

function page_footer(): void {
    $active = $GLOBALS['__active'] ?? '';
    $tabs = [['dashboard.php', 'Home', 'home'], ['suggest.php', 'Ideas', 'ideas'], ['new', '', 'plus'], ['calendar.php', 'Calendar', 'queue'], ['more', 'More', 'menu']];
    ?>
    </main>
  </div>
</div>
<nav class="pb-safe fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white/95 backdrop-blur lg:hidden" aria-label="Quick">
  <div class="mx-auto grid max-w-md grid-cols-5 items-end px-2">
    <?php foreach ($tabs as [$href, $label, $icon]): ?>
      <?php if ($href === 'new'): ?>
        <a href="help.php" data-nav class="mx-auto -mt-5 mb-1.5 flex h-12 w-12 items-center justify-center rounded-full bg-sea text-white shadow-lg ring-4 ring-white" title="New post"><span class="sr-only">New post</span><?= nav_icon('plus') ?></a>
      <?php elseif ($href === 'more'): ?>
        <button id="menuBtn" type="button" aria-controls="sidebar" aria-expanded="false" class="flex flex-col items-center gap-0.5 py-2 text-[11px] text-muted"><?= nav_icon($icon) ?><?= e($label) ?></button>
      <?php else: $on = $active === $href; ?>
        <a href="<?= $href ?>" data-nav class="flex flex-col items-center gap-0.5 py-2 text-[11px] <?= $on ? 'font-semibold text-sea' : 'text-muted' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= nav_icon($icon) ?><?= e($label) ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
</nav>
<script>
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const nav = document.getElementById('nav');
  const marker = document.getElementById('navMarker');
  const page = document.getElementById('page');
  const side = document.getElementById('sidebar'), scrim = document.getElementById('scrim'), btn = document.getElementById('menuBtn');

  // Business switcher: close when clicking elsewhere or pressing Escape
  document.addEventListener('click', e => document.querySelectorAll('details.biz[open]').forEach(d => { if (!d.contains(e.target)) d.removeAttribute('open'); }));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('details.biz[open]').forEach(d => d.removeAttribute('open')); });

  // Overlays (drawers, toasts) live on <body> so page animations can't trap them
  document.querySelectorAll('[data-portal]').forEach(el => document.body.appendChild(el));

  // Place the marker on an item
  function moveMarker(el, instant) {
    if (!el || !nav.contains(el)) { marker.style.opacity = '0'; return; }
    if (instant) marker.style.transition = 'none';
    marker.style.transform = `translateY(${el.offsetTop}px)`;
    marker.style.height = el.offsetHeight + 'px';
    marker.style.opacity = '1';
    if (instant) { marker.offsetHeight; marker.style.transition = ''; }
  }
  moveMarker(nav.querySelector('[aria-current="page"]'), true);

  // Every menu click: marker glides to the item, the page slides out, then we navigate
  document.querySelectorAll('[data-nav]').forEach(a => a.addEventListener('click', ev => {
    if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
    if (reduce) return;
    ev.preventDefault();
    moveMarker(a);
    page.classList.add('leaving');
    setTimeout(() => { window.location.href = a.href; }, 200);
  }));
  // Coming back with the browser's back button: show the page again
  window.addEventListener('pageshow', e => { if (e.persisted) page.classList.remove('leaving'); });

  // Mobile drawer
  if (btn) {
    const set = open => {
      side.classList.toggle('-translate-x-full', !open);
      scrim.classList.toggle('hidden', !open);
      btn.setAttribute('aria-expanded', String(open));
      if (open) moveMarker(nav.querySelector('[aria-current="page"]'), true);
    };
    btn.addEventListener('click', () => set(side.classList.contains('-translate-x-full')));
    scrim.addEventListener('click', () => set(false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') set(false); });
  }
})();
</script>
</body>
</html>
<?php
}

// Small UI helpers

function status_pill(string $status): string {
    $map = [
        'pass' => ['Pass', 'bg-passbg text-pass'],
        'warn' => ['Warn', 'bg-warnbg text-warn'],
        'fail' => ['Fail', 'bg-failbg text-fail'],
        'low' => ['Low risk', 'bg-passbg text-pass'],
        'medium' => ['Medium risk', 'bg-warnbg text-warn'],
        'high' => ['High risk', 'bg-failbg text-fail'],
        'ready' => ['Ready to post', 'bg-passbg text-pass'],
        'fix' => ['Fix first', 'bg-warnbg text-warn'],
        'hold' => ['Hold', 'bg-failbg text-fail'],
        'posted' => ['Posted', 'bg-passbg text-pass'],
        'simulated' => ['Simulated', 'bg-foam text-seadeep'],
        'scheduled' => ['Scheduled', 'bg-foam text-seadeep'],
        'posting' => ['Posting', 'bg-warnbg text-warn'],
        'failed' => ['Failed', 'bg-failbg text-fail'],
        'live' => ['Connected', 'bg-passbg text-pass'],
        'demo' => ['Demo connection', 'bg-foam text-seadeep'],
        'none' => ['Not connected', 'bg-line text-muted'],
    ];
    [$label, $cls] = $map[$status] ?? [ucfirst($status), 'bg-line text-ink'];
    return '<span class="inline-block whitespace-nowrap rounded px-2 py-0.5 text-xs font-semibold ' . $cls . '">' . e($label) . '</span>';
}

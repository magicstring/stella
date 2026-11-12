<?php
// Ask for help: tick what you need, press the big button, get it written (and the image generated),
// alongside what competitors have been posting.
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

const HELP_TEXT_PARTS = [
    'title'    => 'Title',
    'body'     => 'Body text',
    'hashtags' => 'Hashtags',
    'timing'   => 'Timing of post',
];
const HELP_VISUALS = ['none' => 'No visual', 'images' => 'Images', 'videos' => 'Videos'];
// How the AI should use a reference photo or video (the user's "peg")
const PEG_USES = ['style' => 'Match the look and mood', 'product' => 'Keep my product', 'layout' => 'Copy the layout'];

$business = current_business();
$tableReady = true;
try {
    db()->query('SELECT 1 FROM competitor_posts LIMIT 1');
} catch (PDOException $e) {
    $tableReady = false;
}

function competitor_rows(?string $platform, int $limit): array {
    $sql = 'SELECT *, (likes + comments * 2 + shares * 3) AS engagement FROM competitor_posts WHERE ' . biz_where();
    $args = [];
    if ($platform !== null) {
        $sql .= " AND (platform = ? OR platform IS NULL OR platform = '')";
        $args[] = $platform;
    }
    $st = db()->prepare($sql . ' ORDER BY engagement DESC, posted_on DESC LIMIT ' . (int)$limit);
    $st->execute($args);
    return $st->fetchAll();
}

function help_normalize(array $raw, array $parts): array {
    $s = fn($v) => trim(is_string($v) || is_numeric($v) ? (string)$v : '');
    $arr = fn($v) => is_array($v) ? $v : [];
    $out = [];
    if (in_array('title', $parts, true)) $out['title'] = $s($raw['title'] ?? '');
    if (in_array('body', $parts, true)) $out['body'] = $s($raw['body'] ?? '');
    if (in_array('hashtags', $parts, true)) {
        $out['hashtags'] = [];
        foreach ($arr($raw['hashtags'] ?? null) as $h) {
            $tag = is_array($h) ? $s($h['tag'] ?? '') : $s($h);
            if ($tag !== '') $out['hashtags'][] = ['tag' => '#' . ltrim($tag, '#'), 'why' => is_array($h) ? $s($h['why'] ?? '') : ''];
        }
    }
    if (in_array('timing', $parts, true)) {
        $t = $arr($raw['timing'] ?? null);
        $slots = [];
        foreach ($arr($t['slots'] ?? null) as $x) {
            if (is_array($x)) $slots[] = ['day' => $s($x['day'] ?? ''), 'time' => $s($x['time'] ?? ''), 'why' => $s($x['why'] ?? '')];
        }
        $out['timing'] = ['slots' => $slots, 'note' => $s($t['note'] ?? '')];
    }
    if (in_array('images', $parts, true)) {
        $out['images'] = [];
        foreach ($arr($raw['images'] ?? null) as $x) {
            if (is_array($x) && $s($x['concept'] ?? '') !== '') {
                $concept = $s($x['concept']);
                $shot = $s($x['shot'] ?? '');
                $out['images'][] = ['concept' => $concept, 'shot' => $shot, 'overlay' => $s($x['overlay'] ?? ''),
                    'prompt' => $s($x['image_prompt'] ?? '') ?: "$concept. $shot"];
            }
        }
    }
    if (in_array('videos', $parts, true)) {
        $v = $arr($raw['videos'] ?? null);
        $scenes = [];
        foreach ($arr($v['scenes'] ?? null) as $x) {
            if (!is_array($x)) continue;
            $shot = $s($x['shot'] ?? '');
            $scenes[] = ['seconds' => $s($x['seconds'] ?? ''), 'shot' => $shot, 'text' => $s($x['text'] ?? ''), 'audio' => $s($x['audio'] ?? ''),
                'prompt' => $s($x['image_prompt'] ?? '') ?: $shot];
        }
        $hook = $s($v['hook'] ?? '');
        $out['videos'] = ['hook' => $hook, 'length_sec' => (int)($v['length_sec'] ?? 0), 'scenes' => $scenes, 'sound' => $s($v['sound'] ?? ''),
            'cover_prompt' => $s($v['cover_prompt'] ?? '') ?: ($scenes[0]['prompt'] ?? $hook)];
    }
    $c = $raw['competitor_take'] ?? null;
    $out['competitor_take'] = is_array($c) ? [
        'patterns'  => array_values(array_filter(array_map($s, $arr($c['patterns'] ?? null)))),
        'gaps'      => array_values(array_filter(array_map($s, $arr($c['gaps'] ?? null)))),
        'stand_out' => $s($c['stand_out'] ?? ''),
    ] : null;
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        require_post();
        try {
            $parts = array_values(array_intersect(array_keys(HELP_TEXT_PARTS), array_map('strval', (array)($_POST['parts'] ?? []))));
            $visual = array_key_exists($_POST['visual'] ?? '', HELP_VISUALS) ? $_POST['visual'] : 'none';
            if ($visual !== 'none') $parts[] = $visual;   // images OR videos, never both
            if (!$parts) json_out(['error' => 'Pick at least one thing you need help with.'], 422);

            $platform = platform_by_id((int)($_POST['platform_id'] ?? 0)) ?? (platforms()[0] ?? null);
            if (!$platform) json_out(['error' => 'No platforms found. Import seed.sql first.'], 422);
            $brief = trim((string)($_POST['brief'] ?? ''));
            if (mb_strlen($brief) > 1000) json_out(['error' => 'Keep the brief under 1,000 characters.'], 422);
            $language = in_array($_POST['language'] ?? '', Prompts::LANGUAGES, true) ? $_POST['language'] : 'Taglish';

            // The peg: an optional reference photo (or a frame from a reference video) plus the outcome they want
            $peg = null; $pegInfo = null;
            if ($visual === 'images') {
                $use = array_key_exists($_POST['peg_use'] ?? '', PEG_USES) ? $_POST['peg_use'] : 'style';
                $outcome = mb_substr(trim((string)($_POST['peg_outcome'] ?? '')), 0, 500);
                $fromVideo = false;   // photo pegs only for now
                $pegFile = null; $reference = null;
                if (($_FILES['peg']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $pegFile = Media::store($_FILES['peg']);
                    if (!str_starts_with(Media::mime(Media::path($pegFile)), 'image/')) {
                        json_out(['error' => 'The peg must be a photo (JPG, PNG or WebP).'], 422);
                    }
                } elseif (!empty($_POST['peg_keep']) && !empty($_SESSION['help_peg']['file']) && Media::path((string)$_SESSION['help_peg']['file'])) {
                    $prev = $_SESSION['help_peg'];
                    $pegFile = $prev['file']; $fromVideo = !empty($prev['from_video']);
                    if (($prev['use'] ?? '') === $use && ($prev['outcome'] ?? '') === $outcome) $reference = $prev['reference'] ?? null;
                }
                if ($pegFile) {
                    if (!$reference) {
                        $path = Media::path($pegFile);
                        $raw = Claude::askJson(Prompts::PEG, Prompts::pegInput(PEG_USES[$use], $outcome, $fromVideo), 900,
                            [['mime' => Media::mime($path), 'data' => base64_encode((string)file_get_contents($path))]]);
                        $reference = [];
                        foreach (['subject', 'setting', 'lighting', 'colors', 'composition', 'style', 'avoid', 'summary'] as $k) {
                            $reference[$k] = mb_substr(trim((string)($raw[$k] ?? '')), 0, 300);
                        }
                    }
                    $peg = ['use' => $use, 'outcome' => $outcome, 'reference' => $reference];
                    $_SESSION['help_peg'] = ['file' => $pegFile, 'use' => $use, 'outcome' => $outcome, 'reference' => $reference, 'from_video' => $fromVideo];
                    $pegInfo = [
                        'url' => 'media.php?f=' . rawurlencode($pegFile), 'use' => $use, 'use_label' => PEG_USES[$use], 'outcome' => $outcome,
                        'summary' => $reference['summary'] ?? '', 'from_video' => $fromVideo,
                        'direct' => ImageGen::supportsReference() && in_array($use, ['product', 'layout'], true),
                    ];
                } else {
                    unset($_SESSION['help_peg']);
                }
            }

            $competitors = $tableReady ? competitor_rows($platform['name'], 6) : [];
            $raw = Claude::askJson(
                Prompts::HELP,
                Prompts::helpInput($parts, $brief, $platform, $business, Trends::top($platform['name'], 10), $competitors, $language, $peg),
                4000
            );
            $payload = ['result' => help_normalize($raw, $parts), 'parts' => $parts, 'platform' => $platform['name'], 'competitors' => $competitors, 'images' => [], 'peg' => $pegInfo];
            $_SESSION['help'] = $payload + ['brief' => $brief, 'language' => $language, 'visual' => $visual];
            $_SESSION['help_images'] = [];
            json_out($payload);
        } catch (Throwable $e) {
            json_out(['error' => user_error_message($e)], 400);
        }
    }

    csrf_check();
    if ($tableReady && $action === 'add_competitor') {
        $name = trim((string)($_POST['competitor'] ?? ''));
        $caption = trim((string)($_POST['caption'] ?? ''));
        $url = trim((string)($_POST['url'] ?? ''));
        $p = platform_by_name((string)($_POST['platform'] ?? ''));
        $date = (string)($_POST['posted_on'] ?? '');
        if ($name === '' || $caption === '' || mb_strlen($name) > 150 || mb_strlen($caption) > 5000) {
            flash('Add the competitor name and the post caption.', 'error');
            redirect('help.php#swipe');
        }
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = '';
        $bizCol = multi_business_ready() ? 'business_id, ' : '';
        $st = db()->prepare("INSERT INTO competitor_posts ({$bizCol}competitor, platform, caption, url, posted_on, views, likes, comments, shares) VALUES (" . ($bizCol ? '?, ' : '') . '?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute(array_merge($bizCol ? [current_business_id()] : [], [
            $name, $p['name'] ?? null, $caption, $url !== '' ? mb_substr($url, 0, 500) : null,
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
            max(0, (int)($_POST['views'] ?? 0)), max(0, (int)($_POST['likes'] ?? 0)),
            max(0, (int)($_POST['comments'] ?? 0)), max(0, (int)($_POST['shares'] ?? 0)),
        ]));
        flash('Competitor post saved.');
        redirect('help.php#swipe');
    }
    if ($tableReady && $action === 'delete_competitor') {
        db()->prepare('DELETE FROM competitor_posts WHERE id = ? AND ' . biz_where())->execute([(int)($_POST['id'] ?? 0)]);
        flash('Competitor post removed.');
        redirect('help.php#swipe');
    }
    redirect('help.php');
}

$saved = $_SESSION['help'] ?? null;
if ($saved) $saved['images'] = $_SESSION['help_images'] ?? [];
$swipe = $tableReady ? competitor_rows(null, 50) : [];
$selParts = $saved ? array_values(array_intersect($saved['parts'], array_keys(HELP_TEXT_PARTS))) : ['title', 'body', 'hashtags'];
$selVisual = $saved['visual'] ?? 'none';
$selLang = $saved['language'] ?? 'Taglish';
$selPlatform = platform_by_name((string)($saved['platform'] ?? '')) ?? (platforms()[0] ?? null);

// Prefill for the "Write it myself" tab (from the calendar's + button, or a post idea)
$prefill = [
    'mode'     => ($_GET['mode'] ?? '') === 'write' || !empty($_GET['date']) ? 'write' : 'ai',
    'date'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) && $_GET['date'] >= date('Y-m-d') ? $_GET['date'] : '',
    'caption'  => mb_substr((string)($_GET['caption'] ?? ''), 0, 5000),
    'platform' => (string)($_GET['platform'] ?? ''),
    'media'    => Media::path((string)($_GET['media'] ?? '')) ? (string)$_GET['media'] : '',
];
$accts = accounts();
$bizPlat = array_map('trim', explode(',', (string)($business['platforms'] ?? '')));
$composePlatforms = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'caption_max' => (int)$p['caption_max'], 'hashtag_max' => (int)$p['hashtag_max'],
    'connected' => isset($accts[(int)$p['id']]), 'default' => isset($accts[(int)$p['id']]) && in_array($p['name'], $bizPlat, true)], platforms());

page_header('Create New Post', 'help.php');
?>
<style>
  /* Create button: a calm brand button with a living constellation above it */
  .cta { display: inline-flex; align-items: center; gap: 10px; border-radius: 16px; padding: 16px 30px; color: #fff;
    background: <?= BRAND['sea'] ?>; box-shadow: 0 14px 30px -12px rgba(82,54,224,.65), inset 0 1px 0 rgba(255,255,255,.18);
    transition: transform .15s ease, background-color .2s, box-shadow .2s; }
  .cta:hover:not(:disabled) { background: <?= BRAND['seadeep'] ?>; transform: translateY(-1px); box-shadow: 0 18px 34px -12px rgba(82,54,224,.7); }
  .cta:active:not(:disabled), .cta.pressed { transform: scale(.97); }
  .cta:disabled:not(.pressed) { opacity: .45; cursor: not-allowed; box-shadow: none; }
  .busy .cta { background: linear-gradient(100deg, <?= BRAND['sea'] ?> 30%, #8B7BFF 50%, <?= BRAND['sea'] ?> 70%); background-size: 300% 100%; animation: ctaShine 1.6s linear infinite; }
  @keyframes ctaShine { to { background-position: -100% 0; } }
  .cta .cta-spark { animation: none; transform-origin: center; }
  .busy .cta .cta-spark { animation: spin 1.4s linear infinite; }
  .cta.pop { animation: ctaPop .45s cubic-bezier(.2,1.6,.4,1); }
  @keyframes ctaPop { 0% { transform: scale(.97); } 60% { transform: scale(1.04); } 100% { transform: none; } }

  #constellation { width: 260px; height: 184px; overflow: visible; }
  #constellation .bg { fill: #8B7BFF; animation: twinkle 3s ease-in-out infinite; }
  #csLine { fill: none; stroke: #C9C0F7; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; transition: stroke .4s, opacity .4s; }
  .busy #csLine { stroke: #8B7BFF; stroke-dasharray: 7 6; animation: march .9s linear infinite; }
  @keyframes march { to { stroke-dashoffset: -13; } }
  #constellation .dot { fill: <?= BRAND['sea'] ?>; transition: fill .4s; }
  .busy #constellation .dot { animation: twinkle 1.2s ease-in-out infinite; }
  #constellation .cs:nth-child(3) .dot { animation-delay: .2s; } #constellation .cs:nth-child(4) .dot { animation-delay: .4s; }
  #constellation .cs:nth-child(5) .dot { animation-delay: .6s; } #constellation .cs:nth-child(6) .dot { animation-delay: .8s; }
  #constellation .cs:nth-child(7) .dot { animation-delay: 1s; } #constellation .cs:nth-child(8) .dot { animation-delay: .3s; }
  @keyframes twinkle { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
  #csLead { transform-box: fill-box; transform-origin: center; filter: drop-shadow(0 0 6px rgba(255,184,46,.65)); }
  .busy #csLead { animation: spin 2.4s linear infinite; }
  .burst #csLead { animation: burst .8s cubic-bezier(.2,1.6,.4,1); }
  @keyframes burst { 0% { transform: scale(1); } 45% { transform: scale(1.9) rotate(45deg); } 100% { transform: scale(1); } }
  .shoot { stroke: url(#shootGrad); stroke-width: 2; stroke-linecap: round; opacity: 0; }
  .busy .shoot { animation: shoot 2.6s ease-out infinite; }
  .busy .shoot.s2 { animation-delay: 1.3s; }
  @keyframes shoot { 0% { opacity: 0; transform: translate(0, 0); } 8% { opacity: 1; } 30% { opacity: 0; transform: translate(-70px, 40px); } 100% { opacity: 0; } }
  @media (prefers-reduced-motion: reduce) { .busy .cta, .busy .cta .cta-spark, #constellation .bg, .busy #constellation .dot, .busy #csLine, .busy #csLead, .burst #csLead, .busy .shoot, .cta.pop { animation: none !important; } }
  .reveal > * { animation: rise .45s cubic-bezier(.2,.8,.2,1) both; }
  .reveal > *:nth-child(2) { animation-delay: .08s; } .reveal > *:nth-child(3) { animation-delay: .16s; } .reveal > *:nth-child(4) { animation-delay: .24s; }
  @keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: none; } }
  .shimmer { background: linear-gradient(100deg, #F1F1F4 30%, #FBE7F3 50%, #F1F1F4 70%); background-size: 300% 100%; animation: shimmer 1.4s linear infinite; }
  @keyframes shimmer { to { background-position: -100% 0; } }
  .panel-in { animation: panelIn .3s cubic-bezier(.2,.8,.2,1); }
  @keyframes panelIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
  @media (prefers-reduced-motion: reduce) { .panel-in { animation: none !important; } }
  @media (prefers-reduced-motion: reduce) { .busy .sonar, .flash .sonar, .reveal > *, .sos-btn.pop, .shimmer { animation: none !important; } }
</style>

<div class="flex flex-wrap items-end justify-between gap-3">
  <div>
    <h1 class="font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight">Create New Post</h1>
    <p class="mt-1 text-sm text-muted sm:text-base">Write it yourself, or let AI write it for <?= $business ? '<strong class="text-ink">' . e($business['name']) . '</strong>' : 'your business' ?>.</p>
  </div>
  <div role="tablist" aria-label="How do you want to create it?" class="grid w-full grid-cols-2 rounded-xl border border-line bg-white p-1 sm:w-auto">
    <button type="button" role="tab" data-mode="ai" class="mode-tab flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.4z"/></svg>Generate with AI</button>
    <button type="button" role="tab" data-mode="write" class="mode-tab flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>Write it myself</button>
  </div>
</div>

<!-- ============ Write it myself ============ -->
<section id="panelWrite" class="mode-panel mt-5 hidden" role="tabpanel" aria-label="Write it myself">
  <form id="composeForm" class="grid items-start gap-5 lg:grid-cols-[1fr_320px]" novalidate>
    <div class="space-y-5 rounded-2xl border border-line bg-white p-4 sm:p-6">
      <fieldset>
        <legend class="text-sm font-semibold">Post to</legend>
        <div id="cPlatforms" class="mt-2 flex flex-wrap gap-2"></div>
        <p id="cConnectHint" class="mt-1.5 hidden text-xs text-muted">Greyed-out platforms aren't connected. <a href="accounts.php" class="text-sea underline">Connect channels</a></p>
      </fieldset>
      <div>
        <div class="flex items-baseline justify-between gap-3">
          <label for="cCaption" class="text-sm font-semibold">Caption and hashtags</label>
          <span id="cCount" class="text-xs tabular-nums text-muted" aria-live="polite"></span>
        </div>
        <textarea id="cCaption" name="caption" rows="8" class="mt-1.5 w-full rounded-lg border border-line px-3 py-2 leading-relaxed focus:border-sea focus:outline-none" placeholder="First line is your hook. Hashtags at the end."></textarea>
      </div>
      <div>
        <p class="text-sm font-semibold">Image or video <span class="font-normal text-muted">(optional)</span></p>
        <div id="cMediaBox" class="mt-1.5 hidden items-center gap-3 rounded-lg border border-line p-2">
          <div id="cThumb" class="h-20 w-16 shrink-0 overflow-hidden rounded bg-paper"></div>
          <p id="cMediaInfo" class="flex-1 text-sm text-muted"></p>
          <button type="button" id="cMediaRemove" class="rounded px-2 py-1 text-sm text-fail hover:bg-failbg">Remove</button>
        </div>
        <label id="cDrop" class="mt-1.5 flex cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border-2 border-dashed border-line px-4 py-5 text-center text-sm text-muted hover:border-sea hover:text-sea">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/></svg>
          <span><strong class="text-ink">Choose a file</strong> · JPG, PNG, WebP, MP4, MOV or WebM up to <?= MAX_UPLOAD_MB ?> MB</span>
          <input id="cFile" name="file" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" class="sr-only">
        </label>
        <input type="hidden" name="media_file" id="cMediaFile">
        <input type="hidden" name="client_width" id="cW"><input type="hidden" name="client_height" id="cH"><input type="hidden" name="client_duration" id="cD">
      </div>
    </div>

    <aside class="space-y-4 rounded-2xl border border-line bg-white p-4 sm:p-5 lg:sticky lg:top-20">
      <fieldset>
        <legend class="text-sm font-semibold">When</legend>
        <div class="mt-2 grid grid-cols-2 rounded-lg border border-line bg-paper p-1">
          <label class="cursor-pointer"><input type="radio" name="when" value="now" class="peer sr-only"><span class="block rounded-md py-1.5 text-center text-sm font-semibold text-muted peer-checked:bg-white peer-checked:text-sea peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-sea">Post now</span></label>
          <label class="cursor-pointer"><input type="radio" name="when" value="later" class="peer sr-only" checked><span class="block rounded-md py-1.5 text-center text-sm font-semibold text-muted peer-checked:bg-white peer-checked:text-sea peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-sea">Schedule</span></label>
        </div>
      </fieldset>
      <div id="cLater" class="space-y-2">
        <div class="grid grid-cols-2 gap-2">
          <label class="text-xs text-muted">Date<input type="date" name="date" id="cDate" min="<?= date('Y-m-d') ?>" class="mt-1 w-full rounded-md border border-line px-2 py-1.5 text-sm text-ink"></label>
          <label class="text-xs text-muted">Time<input type="time" name="time" id="cTime" class="mt-1 w-full rounded-md border border-line px-2 py-1.5 text-sm text-ink"></label>
        </div>
        <button type="button" id="cSuggest" class="flex w-full items-center justify-center gap-1.5 rounded-md border border-line py-1.5 text-sm font-semibold hover:border-sea hover:text-sea disabled:opacity-50">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l1.9 4.6L18.5 9l-4.6 1.9L12 15.5l-1.9-4.6L5.5 9l4.6-1.4z"/></svg>Suggest the best time</button>
        <p id="cWhy" class="text-xs text-muted"></p>
      </div>
      <button type="submit" id="cSubmit" class="w-full rounded-lg bg-sea py-2.5 font-semibold text-white hover:bg-seadeep disabled:opacity-50">Schedule post</button>
      <div class="border-t border-line pt-4">
        <p class="text-xs text-muted"><span class="font-semibold text-ink">Optional:</span> check platform rules, policy risk and Philippine rules before it goes out.</p>
        <button type="button" id="cCheck" class="mt-2 w-full rounded-lg border border-line py-2 text-sm font-semibold hover:border-sea hover:text-sea disabled:opacity-50">Run a pre-flight check</button>
      </div>
      <div id="cDone" class="hidden rounded-lg bg-passbg p-3 text-sm text-pass"></div>
      <p id="cErr" role="alert" class="text-sm text-fail"></p>
    </aside>
  </form>
</section>

<!-- ============ Generate with AI ============ -->
<div id="panelAi" class="mode-panel mt-5 hidden" role="tabpanel" aria-label="Generate with AI">
<?php if (!$tableReady): ?>
  <div class="mt-6 rounded-lg bg-warnbg px-4 py-3 text-sm text-warn">Import <code>competitors.sql</code> in phpMyAdmin to turn on competitor comparisons. Everything else works without it.</div>
<?php endif; ?>

<form id="helpForm" class="grid overflow-hidden rounded-2xl border border-line bg-white lg:grid-cols-[1fr_380px]" novalidate>
  <div class="space-y-8 p-4 sm:p-6 sm:p-8">
    <!-- Step 1 -->
    <fieldset>
      <legend class="flex items-center gap-3 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-foam text-xs text-sea">1</span>What do you need?</legend>
      <div class="mt-4 flex flex-wrap gap-2">
        <?php foreach (HELP_TEXT_PARTS as $key => $label): ?>
          <label class="cursor-pointer">
            <input type="checkbox" name="parts[]" value="<?= $key ?>" class="peer sr-only" <?= in_array($key, $selParts, true) ? 'checked' : '' ?>>
            <span class="inline-flex items-center gap-2 rounded-full border border-line px-4 py-2 text-sm font-semibold text-ink/80 transition peer-checked:border-sea peer-checked:bg-sea peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-sea peer-focus-visible:ring-offset-2">
              <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5" aria-hidden="true"><path d="m5 12 5 5 9-10"/></svg><?= e($label) ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="mt-5 text-sm font-semibold">Visual</p>
      <div class="mt-2 inline-flex rounded-lg border border-line bg-paper p-1" role="radiogroup" aria-label="Visual">
        <?php foreach (HELP_VISUALS as $key => $label): ?>
          <label class="cursor-pointer">
            <input type="radio" name="visual" value="<?= $key ?>" class="peer sr-only" <?= $selVisual === $key ? 'checked' : '' ?>>
            <span class="block rounded-md px-4 py-1.5 text-sm font-semibold text-muted peer-checked:bg-white peer-checked:text-sea peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= e($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="mt-2 text-xs text-muted">Images or videos, one at a time. Images can be generated with AI right here; videos get a shot plan with AI cover and storyboard frames.</p>

      <!-- The peg: a reference photo or video the AI builds the new visual from -->
      <div id="pegPanel" class="mt-4 hidden rounded-xl border border-sea/30 bg-foam/60 p-4">
        <p class="font-semibold">What's your peg? <span class="font-normal text-muted">(optional)</span></p>
        <p class="mt-1 text-sm text-muted">Got a photo that looks like what you want? Add it and tell Stella the outcome you're after. The AI recreates it into a new image for your post.</p>
        <div class="mt-3 flex flex-wrap items-start gap-4">
          <label for="pegFile" class="relative flex h-32 w-24 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-lg border-2 border-dashed border-sea/40 bg-white p-2 text-center text-xs font-semibold text-sea hover:border-sea">
            <span id="pegEmpty">+ Add a photo</span>
            <img id="pegThumb" alt="Your peg" class="absolute inset-0 hidden h-full w-full object-cover">
          </label>
          <input id="pegFile" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only">
          <div class="min-w-[220px] flex-1 space-y-3">
            <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="How to use your peg">
              <?php foreach (PEG_USES as $key => $label): ?>
                <label class="cursor-pointer">
                  <input type="radio" name="peg_use" value="<?= $key ?>" class="peer sr-only" <?= $key === 'style' ? 'checked' : '' ?>>
                  <span class="inline-flex rounded-full border border-line bg-white px-3 py-1.5 text-sm font-semibold text-muted peer-checked:border-sea peer-checked:text-sea peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <label for="pegOutcome" class="sr-only">What outcome do you want?</label>
            <textarea id="pegOutcome" name="peg_outcome" rows="2" maxlength="500" class="w-full rounded-lg border border-line bg-white px-3 py-2 text-sm focus:border-sea focus:outline-none" placeholder="What outcome do you want? Example: Same cozy café vibe, but with our ube latte in front and soft morning light."></textarea>
            <p id="pegMeta" class="text-xs text-muted"></p>
            <button type="button" id="pegClear" class="hidden text-xs font-semibold text-muted underline hover:text-ink">Remove peg</button>
          </div>
        </div>
      </div>
    </fieldset>

    <!-- Step 2 -->
    <div>
      <label for="brief" class="flex items-center gap-3 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-foam text-xs text-sea">2</span>What's the post about? <span class="font-normal text-muted">(optional)</span></label>
      <textarea id="brief" name="brief" rows="3" maxlength="1000" class="mt-3 w-full rounded-lg border border-line bg-white px-3 py-2 focus:border-sea focus:outline-none" placeholder="Example: Weekend grooming promo, 20% off for first-timers"><?= e($saved['brief'] ?? '') ?></textarea>
    </div>

    <!-- Step 3 -->
    <div class="flex flex-wrap gap-x-10 gap-y-6">
      <fieldset>
        <legend class="flex items-center gap-3 font-semibold"><span class="flex h-6 w-6 items-center justify-center rounded-full bg-foam text-xs text-sea">3</span>Platform</legend>
        <div class="mt-3 flex flex-wrap gap-2">
          <?php foreach (platforms() as $p): ?>
            <label class="cursor-pointer">
              <input type="radio" name="platform_id" value="<?= (int)$p['id'] ?>" class="peer sr-only" <?= (int)$p['id'] === (int)($selPlatform['id'] ?? 0) ? 'checked' : '' ?>>
              <span class="flex items-center gap-2 rounded-full border border-line py-1 pl-1 pr-3 text-sm peer-checked:border-sea peer-checked:bg-foam peer-checked:font-semibold peer-focus-visible:ring-2 peer-focus-visible:ring-sea">
                <?= platform_badge($p['name'], 'w-6 h-6 text-[9px]') ?><?= e($p['name']) ?>
              </span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
      <fieldset>
        <legend class="font-semibold">Language</legend>
        <div class="mt-3 inline-flex rounded-lg border border-line bg-paper p-1">
          <?php foreach (Prompts::LANGUAGES as $lang): ?>
            <label class="cursor-pointer">
              <input type="radio" name="language" value="<?= e($lang) ?>" class="peer sr-only" <?= $selLang === $lang ? 'checked' : '' ?>>
              <span class="block rounded-md px-3 py-1.5 text-sm font-semibold text-muted peer-checked:bg-white peer-checked:text-sea peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= e($lang) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    </div>
  </div>

  <!-- Create button with the constellation -->
  <div class="flex flex-col items-center justify-center border-t border-line bg-gradient-to-b from-foam/70 to-white px-6 py-6 sm:py-10 text-center lg:border-l lg:border-t-0">
    <div id="sosWrap" class="relative flex flex-col items-center">
      <svg id="constellation" viewBox="0 0 240 170" aria-hidden="true">
        <defs><linearGradient id="shootGrad" x1="0" x2="1"><stop offset="0" stop-color="#FFB82E"/><stop offset="1" stop-color="#FFB82E" stop-opacity="0"/></linearGradient></defs>
        <g id="csBg"><circle class="bg" cx="18" cy="30" r="1.4"/><circle class="bg" cx="214" cy="22" r="1.2" style="animation-delay:.7s"/><circle class="bg" cx="226" cy="120" r="1.6" style="animation-delay:1.4s"/><circle class="bg" cx="30" cy="148" r="1.2" style="animation-delay:2s"/><circle class="bg" cx="196" cy="160" r="1.3" style="animation-delay:.4s"/><circle class="bg" cx="8" cy="92" r="1.1" style="animation-delay:1.1s"/></g>
        <line class="shoot" x1="200" y1="14" x2="226" y2="0"/><line class="shoot s2" x1="60" y1="20" x2="86" y2="6"/>
        <g id="csGroup">
          <polyline id="csLine" points=""/>
          <g class="cs"><path id="csLead" fill="#FFB82E" d="M0 -11C1.2 -1.2 1.2 -1.2 11 0C1.2 1.2 1.2 1.2 0 11C-1.2 1.2 -1.2 1.2 -11 0C-1.2 -1.2 -1.2 -1.2 0 -11Z"/></g>
          <g class="cs"><circle class="dot" r="4.4"/></g><g class="cs"><circle class="dot" r="5.2"/></g><g class="cs"><circle class="dot" r="4"/></g>
          <g class="cs"><circle class="dot" r="5.2"/></g><g class="cs"><circle class="dot" r="4.4"/></g><g class="cs"><circle class="dot" r="5.8"/></g>
        </g>
      </svg>
      <button id="sosBtn" type="submit" class="cta -mt-2 font-display text-lg sm:text-xl font-extrabold">
        <svg class="cta-spark" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path fill="#FFB82E" d="M12 1c.6 5.2 1.8 6.4 7 7-5.2.6-6.4 1.8-7 7-.6-5.2-1.8-6.4-7-7 5.2-.6 6.4-1.8 7-7z"/></svg>
        <span id="sosLabel">Create my post</span>
      </button>
    </div>
    <p id="sosSummary" class="mt-4 text-sm font-semibold"></p>
    <p id="sosStatus" role="status" aria-live="polite" class="mt-1 text-sm text-muted">Press when you're ready</p>
    <p id="sosError" role="alert" class="mt-1 text-sm text-fail"></p>
  </div>
</form>

<!-- Results -->
<section id="results" class="mt-8 lg:mt-12 hidden" aria-labelledby="resTitle">
  <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h2 id="resTitle" class="font-display text-xl sm:text-2xl lg:text-3xl font-extrabold">Here's your help</h2>
    <button type="button" id="useBtn" class="hidden rounded-lg bg-sea px-5 py-2.5 font-semibold text-white hover:bg-seadeep">Use this post</button>
  </div>
  <div id="resLayout" class="grid items-start gap-6 lg:grid-cols-[360px_1fr]">
    <!-- Post preview -->
    <article id="preview" class="hidden overflow-hidden rounded-2xl border border-line bg-white shadow-sm lg:sticky lg:top-20">
      <div class="flex items-center gap-3 px-4 py-3">
        <span class="flex h-9 w-9 items-center justify-center rounded-full bg-foam font-display font-extrabold text-sea"><?= e(mb_strtoupper(mb_substr($business['name'] ?? 'S', 0, 1))) ?></span>
        <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold"><?= e($business['name'] ?? 'Your business') ?></p><p id="pvPlatform" class="text-xs text-muted"></p></div>
        <span class="text-xs text-muted">Preview</span>
      </div>
      <div id="pvMedia" class="hidden aspect-[4/5] bg-paper"></div>
      <div class="space-y-2 px-4 py-4">
        <p id="pvTitle" class="font-semibold leading-snug"></p>
        <p id="pvBody" class="whitespace-pre-wrap text-sm leading-relaxed"></p>
        <p id="pvTags" class="text-sm text-sea"></p>
      </div>
      <div class="flex gap-4 border-t border-line px-4 py-3 text-xs">
        <button type="button" id="copyAll" class="font-semibold text-sea underline">Copy caption</button>
        <span id="pvNote" class="text-muted"></span>
      </div>
    </article>
    <div id="resGrid" class="space-y-5"></div>
  </div>
</section>

<section id="compSection" class="mt-8 lg:mt-14 hidden" aria-labelledby="compTitle">
  <h2 id="compTitle" class="font-display text-xl sm:text-2xl lg:text-3xl font-extrabold">What competitors posted</h2>
  <p class="mt-1 text-muted">From your competitor swipe file, highest engagement first.</p>
  <div id="compTake" class="mt-5"></div>
  <div id="compGrid" class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3"></div>
</section>

<!-- Swipe file -->
<?php if ($tableReady): ?>
<section id="swipe" class="mt-8 lg:mt-14 border-t border-line pt-6 sm:pt-10" aria-labelledby="swipeTitle">
  <div class="flex flex-wrap items-end justify-between gap-4">
    <div>
      <h2 id="swipeTitle" class="font-display text-2xl font-extrabold">Competitor swipe file</h2>
      <p class="mt-1 max-w-prose text-sm text-muted">Save competitor posts you spot, with their numbers. Stella compares against these; it doesn't pull other accounts' posts automatically.</p>
    </div>
    <span class="text-sm text-muted"><?= count($swipe) ?> saved</span>
  </div>
  <details class="mt-5 rounded-xl border border-line bg-white" <?= $swipe ? '' : 'open' ?>>
    <summary class="cursor-pointer px-4 py-3 font-semibold">Add a competitor post</summary>
    <form method="post" class="grid gap-3 border-t border-line p-4 sm:grid-cols-2 lg:grid-cols-4">
      <?= csrf_field() ?><input type="hidden" name="action" value="add_competitor">
      <div class="sm:col-span-2"><label for="c-name" class="mb-1 block text-sm font-semibold">Competitor</label><input id="c-name" name="competitor" required maxlength="150" placeholder="@theirbrand" class="w-full rounded-md border border-line px-3 py-2 text-sm"></div>
      <div><label for="c-platform" class="mb-1 block text-sm font-semibold">Platform</label><select id="c-platform" name="platform" class="w-full rounded-md border border-line bg-white px-2 py-2 text-sm"><?php foreach (platforms() as $p): ?><option><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
      <div><label for="c-date" class="mb-1 block text-sm font-semibold">Posted on</label><input id="c-date" name="posted_on" type="date" class="w-full rounded-md border border-line px-2 py-2 text-sm"></div>
      <div class="sm:col-span-2 lg:col-span-4"><label for="c-caption" class="mb-1 block text-sm font-semibold">Caption</label><textarea id="c-caption" name="caption" required rows="3" class="w-full rounded-md border border-line px-3 py-2 text-sm"></textarea></div>
      <div class="sm:col-span-2 lg:col-span-4"><label for="c-url" class="mb-1 block text-sm font-semibold">Link <span class="font-normal text-muted">(optional)</span></label><input id="c-url" name="url" type="url" placeholder="https://" class="w-full rounded-md border border-line px-3 py-2 text-sm"></div>
      <?php foreach (['views' => 'Views', 'likes' => 'Likes', 'comments' => 'Comments', 'shares' => 'Shares'] as $k => $label): ?>
        <div><label for="c-<?= $k ?>" class="mb-1 block text-sm font-semibold"><?= $label ?></label><input id="c-<?= $k ?>" name="<?= $k ?>" type="number" min="0" class="w-full rounded-md border border-line px-3 py-2 text-sm tabular-nums"></div>
      <?php endforeach; ?>
      <div class="sm:col-span-2 lg:col-span-4"><button class="rounded-lg bg-sea px-4 py-2 text-sm font-semibold text-white hover:bg-seadeep">Save competitor post</button></div>
    </form>
  </details>
  <?php if ($swipe): ?>
    <ul class="mt-5 divide-y divide-line rounded-xl border border-line bg-white">
      <?php foreach ($swipe as $c): ?>
        <li class="flex items-start gap-3 p-4">
          <?= platform_badge((string)($c['platform'] ?: '?'), 'w-7 h-7 text-[10px]') ?>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold"><?= e($c['competitor']) ?> <span class="font-normal text-muted"><?= $c['posted_on'] ? '· ' . e(date('M j', strtotime($c['posted_on']))) : '' ?></span></p>
            <p class="line-clamp-2 text-sm"><?= e($c['caption']) ?></p>
            <p class="mt-1 text-xs tabular-nums text-muted"><?= number_format((int)$c['views']) ?> views · <?= number_format((int)$c['likes']) ?> likes · <?= number_format((int)$c['comments']) ?> comments · <?= number_format((int)$c['shares']) ?> shares</p>
          </div>
          <form method="post" onsubmit="return confirm('Remove this competitor post?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="delete_competitor"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <button class="text-sm text-fail underline">Remove</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
<?php endif; ?>
</div>

<script>
const IMAGE_ON = <?= ImageGen::configured() ? 'true' : 'false' ?>;
const SAVED_HELP = <?= json_encode($saved ? ['result' => $saved['result'], 'parts' => $saved['parts'], 'platform' => $saved['platform'], 'competitors' => $saved['competitors'], 'images' => $saved['images']] : null, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const esc = escapeHtml;
const form = document.getElementById('helpForm');
const btn = document.getElementById('sosBtn');
const wrap = document.getElementById('sosWrap');
const boxes = [...form.querySelectorAll('input[name="parts[]"]')];
const visuals = [...form.querySelectorAll('input[name=visual]')];
const LABELS = { title: 'Title', body: 'Body', hashtags: 'Hashtags', timing: 'Timing', images: 'Images', videos: 'Video plan' };

let state = null;          // last result
let chosenImage = null;    // file name of the image picked for the post

function picked() {
  const p = boxes.filter(b => b.checked).map(b => b.value);
  const v = visuals.find(r => r.checked)?.value;
  if (v && v !== 'none') p.push(v);
  return p;
}
function syncSummary() {
  const p = picked();
  document.getElementById('sosSummary').textContent = p.length ? 'You’ll get: ' + p.map(x => LABELS[x]).join(' · ') : 'Pick at least one thing';
  btn.disabled = !p.length && !btn.classList.contains('pressed');
}
[...boxes, ...visuals].forEach(el => el.addEventListener('change', syncSummary));
syncSummary();

// ---------- The peg (reference photo or video) ----------
const pegPanel = document.getElementById('pegPanel'), pegInput = document.getElementById('pegFile');
const pegThumb = document.getElementById('pegThumb'), pegMeta = document.getElementById('pegMeta'), pegClear = document.getElementById('pegClear');
let pegBlob = null, pegFromVideo = false, pegKeep = false;
function syncPeg() {
  const v = visuals.find(r => r.checked)?.value;
  pegPanel.classList.toggle('hidden', v !== 'images');
}
visuals.forEach(r => r.addEventListener('change', syncPeg));
syncPeg();
function showPeg(src, note) {
  pegThumb.src = src; pegThumb.classList.remove('hidden');
  document.getElementById('pegEmpty').classList.add('hidden');
  pegMeta.textContent = note; pegClear.classList.remove('hidden');
}
// Shrink to a JPEG (max 1280px) so uploads stay small and any photo or video frame works the same way
function toJpeg(source, w, h) {
  const k = Math.min(1, 1280 / Math.max(w, h)), c = document.createElement('canvas');
  c.width = Math.round(w * k); c.height = Math.round(h * k);
  c.getContext('2d').drawImage(source, 0, 0, c.width, c.height);
  return new Promise(res => c.toBlob(res, 'image/jpeg', 0.88));
}
pegInput.addEventListener('change', async () => {
  const file = pegInput.files[0];
  if (!file) return;
  pegMeta.textContent = 'Reading your peg…';
  const url = URL.createObjectURL(file);
  try {
    if (!file.type.startsWith('image/')) throw new Error('Please add a photo (JPG, PNG or WebP).');
    const img = new Image(); img.src = url; await img.decode();
    pegBlob = await toJpeg(img, img.naturalWidth, img.naturalHeight); pegFromVideo = false;
    pegKeep = false;
    showPeg(URL.createObjectURL(pegBlob), 'Photo peg ready.');
  } catch (e) {
    pegBlob = null; pegMeta.textContent = e.message || 'This file could not be read.';
  }
  URL.revokeObjectURL(url);
  pegInput.value = '';
});
pegClear.addEventListener('click', () => {
  pegBlob = null; pegKeep = false; pegFromVideo = false;
  pegThumb.classList.add('hidden'); pegThumb.removeAttribute('src');
  document.getElementById('pegEmpty').classList.remove('hidden');
  pegMeta.textContent = ''; pegClear.classList.add('hidden');
});

// ---------- Rendering ----------
function card(title, inner, extra = '') {
  return `<article class="rounded-2xl border border-line bg-white p-4 sm:p-5"><div class="mb-3 flex items-baseline justify-between gap-3"><h3 class="font-display text-xl font-extrabold">${esc(title)}</h3>${extra}</div>${inner}</article>`;
}
function slotHtml(slot, prompt, label) {
  const file = state.images?.[slot];
  return `<div class="gen-slot" data-slot="${esc(slot)}">
    <div class="slot-media relative aspect-[4/5] overflow-hidden rounded-xl border border-dashed border-line bg-paper">
      ${file ? imgTag(file) : `<div class="absolute inset-0 grid place-items-center p-4 text-center text-xs text-muted">${IMAGE_ON ? 'No image yet' : 'Image generation is off'}</div>`}
    </div>
    <details class="mt-2 text-xs"><summary class="cursor-pointer text-muted">Edit prompt</summary>
      <textarea class="slot-prompt mt-1 w-full rounded-md border border-line px-2 py-1.5 text-xs" rows="4">${esc(prompt)}</textarea></details>
    <div class="mt-2 flex flex-wrap gap-2">
      <button type="button" class="slot-gen rounded-md border border-sea px-3 py-1.5 text-xs font-semibold text-sea hover:bg-foam disabled:opacity-50" ${IMAGE_ON ? '' : 'disabled'}>${file ? 'Regenerate' : label}</button>
      ${file && slot.startsWith('img') ? `<button type="button" class="slot-use rounded-md bg-sea px-3 py-1.5 text-xs font-semibold text-white hover:bg-seadeep">${chosenImage === file ? 'In your post' : 'Use in post'}</button>` : ''}
    </div>
    <p class="slot-err mt-1 text-xs text-fail"></p>
  </div>`;
}
function imgTag(file) {
  return `<img src="media.php?f=${encodeURIComponent(file)}" alt="AI-generated image" class="absolute inset-0 h-full w-full object-cover">`;
}

function render(data, animate) {
  state = data;
  state.images = state.images || {};
  const r = data.result, cards = [];

  // Post preview: title + body + hashtags (+ the chosen image)
  const hasText = 'title' in r || 'body' in r || 'hashtags' in r;
  const pv = document.getElementById('preview');
  const showPv = hasText || 'images' in r;
  pv.classList.toggle('hidden', !showPv);
  document.getElementById('resLayout').classList.toggle('lg:grid-cols-[360px_1fr]', showPv);
  document.getElementById('pvPlatform').textContent = data.platform;
  document.getElementById('pvTitle').textContent = r.title || '';
  document.getElementById('pvBody').textContent = r.body || '';
  document.getElementById('pvTags').textContent = (r.hashtags || []).map(h => h.tag).join(' ');
  document.getElementById('pvNote').textContent = 'images' in r ? 'Pick an image below to add it here' : '';

  if (r.hashtags && r.hashtags.length) {
    cards.push(card('Why these hashtags', `<ul class="space-y-2">${r.hashtags.map(h => `<li class="text-sm"><span class="font-semibold text-sea">${esc(h.tag)}</span> <span class="text-muted">${esc(h.why)}</span></li>`).join('')}</ul>`));
  }
  if ('timing' in r) {
    cards.push(card('When to post', `<ol class="grid gap-3 sm:grid-cols-3">${r.timing.slots.map((s, i) => `<li class="rounded-xl bg-paper p-4"><p class="text-xs font-semibold text-sea">Option ${i + 1}</p><p class="mt-1 font-display text-lg font-extrabold leading-tight">${esc(s.day)}</p><p class="font-semibold">${esc(s.time)}</p><p class="mt-1 text-xs text-muted">${esc(s.why)}</p></li>`).join('')}</ol>
      ${r.timing.note ? `<p class="mt-3 text-xs text-muted">${esc(r.timing.note)}</p>` : ''}`));
  }
  if (data.peg) {
    const pg = data.peg;
    cards.push(card('Built from your peg', `<div class="flex gap-4">
        <img src="${esc(pg.url)}" alt="Your peg" class="h-28 w-24 shrink-0 rounded-lg object-cover">
        <div class="text-sm">
          <p class="font-semibold text-sea">${esc(pg.use_label)}</p>
          ${pg.summary ? `<p class="mt-1 text-muted">${esc(pg.summary)}</p>` : ''}
          ${pg.outcome ? `<p class="mt-2"><span class="font-semibold">Outcome you want:</span> ${esc(pg.outcome)}</p>` : ''}
          <p class="mt-2 text-xs text-muted">${pg.direct ? 'New images are made from your photo itself, so your product or layout carries over.' : 'New images follow a description of your peg, so expect the same feel, not an exact copy.'}</p>
        </div></div>`));
  }
  if ('images' in r) {
    cards.push(card('Images', `${IMAGE_ON ? '' : '<p class="mb-3 rounded-lg bg-warnbg px-3 py-2 text-xs text-warn">Add an image API key in config.php to generate images here.</p>'}
      <div class="grid gap-5 sm:grid-cols-3">${r.images.map((m, i) => `<div>
        ${slotHtml('img' + i, m.prompt, 'Generate image')}
        <p class="mt-2 text-sm font-semibold">${esc(m.concept)}</p>
        <p class="text-xs text-muted">${esc(m.shot)}</p>
        ${m.overlay ? `<p class="mt-1 text-xs"><span class="text-muted">Text to add:</span> “${esc(m.overlay)}”</p>` : ''}
      </div>`).join('')}</div>`, '<span class="text-xs text-muted">AI images</span>'));
  }
  if ('videos' in r) {
    const v = r.videos;
    cards.push(card('Video plan', `<div class="grid gap-5 sm:grid-cols-[180px_1fr]">
        <div>${slotHtml('cover', v.cover_prompt, 'Generate cover')}<p class="mt-1 text-xs text-muted">Cover image</p></div>
        <div>
          <p class="text-sm text-muted">Hook</p><p class="font-display text-xl font-extrabold leading-snug">${esc(v.hook)}</p>
          <p class="mt-2 text-sm text-muted">${v.length_sec ? v.length_sec + ' seconds' : ''}${v.sound ? ' · Sound: ' + esc(v.sound) : ''}</p>
          <p class="mt-3 text-xs text-muted">Stella generates stills: a cover and a frame for each scene. Film the video with these as your guide.</p>
        </div>
      </div>
      <h4 class="mt-6 text-sm font-semibold">Storyboard</h4>
      <ol class="mt-3 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">${v.scenes.map((s, i) => `<li>
        ${slotHtml('scene' + i, s.prompt, 'Generate frame')}
        <p class="mt-2 text-xs font-semibold text-sea tabular-nums">${esc(s.seconds)}</p>
        <p class="text-sm">${esc(s.shot)}</p>
        ${s.text ? `<p class="mt-1 text-xs"><span class="text-muted">On screen:</span> ${esc(s.text)}</p>` : ''}
        ${s.audio ? `<p class="text-xs"><span class="text-muted">Audio:</span> ${esc(s.audio)}</p>` : ''}
      </li>`).join('')}</ol>`));
  }

  const grid = document.getElementById('resGrid');
  grid.innerHTML = cards.join('');
  grid.classList.toggle('reveal', animate);
  document.getElementById('results').classList.remove('hidden');
  bindSlots();
  updateUse();
  renderCompetitors(data, animate);
}

function updateUse() {
  const r = state.result;
  const caption = [r.title, r.body, (r.hashtags || []).map(h => h.tag).join(' ')].filter(Boolean).join('\n\n');
  const use = document.getElementById('useBtn');
  if (!caption && !chosenImage) { use.classList.add('hidden'); return; }
  use.onclick = () => fillComposer({ platform: state.platform, caption, media: chosenImage });
  use.classList.remove('hidden');

  const box = document.getElementById('pvMedia');
  box.classList.toggle('hidden', !chosenImage);
  box.innerHTML = chosenImage ? `<img src="media.php?f=${encodeURIComponent(chosenImage)}" alt="Chosen image" class="h-full w-full object-cover">` : '';
}

function bindSlots() {
  document.querySelectorAll('.gen-slot').forEach(bindSlot);
}
function bindSlot(slotEl) {
  {
    const slot = slotEl.dataset.slot;
    const gen = slotEl.querySelector('.slot-gen');
    gen.addEventListener('click', async () => {
      const media = slotEl.querySelector('.slot-media');
      const err = slotEl.querySelector('.slot-err');
      err.textContent = '';
      gen.disabled = true;
      gen.textContent = 'Generating…';
      media.innerHTML = '<div class="shimmer absolute inset-0"></div><div class="absolute inset-0 grid place-items-center text-xs text-muted">Painting…</div>';
      const fd = new FormData();
      fd.append('prompt', slotEl.querySelector('.slot-prompt').value);
      fd.append('shape', 'portrait');
      fd.append('slot', slot);
      if (state && state.peg) fd.append('use_peg', '1');
      try {
        const data = await api('image.php', fd);
        state.images[slot] = data.file;
        if (slot.startsWith('img') && !chosenImage) chosenImage = data.file;   // first image goes straight into the preview
        const live = document.querySelector(`.gen-slot[data-slot="${slot}"]`) || slotEl;
        rerenderSlot(live);
        updateUse();
      } catch (e) {
        const live = document.querySelector(`.gen-slot[data-slot="${slot}"]`) || slotEl;
        live.querySelector('.slot-err').textContent = e.message;
        rerenderSlot(live);
      }
    });
    const use = slotEl.querySelector('.slot-use');
    if (use) use.addEventListener('click', () => {
      chosenImage = state.images[slot];
      document.querySelectorAll('.gen-slot').forEach(rerenderSlot);
      updateUse();
      document.getElementById('preview').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
    });
  }
}
function rerenderSlot(slotEl) {
  const prompt = slotEl.querySelector('.slot-prompt').value;
  const err = slotEl.querySelector('.slot-err').textContent;
  const label = slotEl.dataset.slot.startsWith('img') ? 'Generate image' : (slotEl.dataset.slot === 'cover' ? 'Generate cover' : 'Generate frame');
  const tmp = document.createElement('div');
  tmp.innerHTML = slotHtml(slotEl.dataset.slot, prompt, label);
  const fresh = tmp.firstElementChild;
  fresh.querySelector('.slot-err').textContent = err;
  slotEl.replaceWith(fresh);
  bindSlot(fresh);
}

function renderCompetitors(data, animate) {
  const sec = document.getElementById('compSection');
  sec.classList.remove('hidden');
  const comps = data.competitors || [];
  const take = data.result.competitor_take;
  document.getElementById('compTake').innerHTML = take ? `<div class="grid gap-5 rounded-2xl border border-sea/20 bg-foam p-4 sm:p-5 md:grid-cols-3">
      <div><h3 class="font-semibold">What they keep doing</h3><ul class="mt-2 list-disc space-y-1.5 pl-4 text-sm text-muted">${take.patterns.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>
      <div><h3 class="font-semibold">What they're missing</h3><ul class="mt-2 list-disc space-y-1.5 pl-4 text-sm text-muted">${take.gaps.map(x => `<li>${esc(x)}</li>`).join('')}</ul></div>
      <div><h3 class="font-semibold">How you stand out</h3><p class="mt-2 text-sm text-muted">${esc(take.stand_out)}</p></div>
    </div>` : '';
  const grid = document.getElementById('compGrid');
  grid.innerHTML = comps.length ? comps.map(c => `<article class="flex flex-col rounded-2xl border border-line bg-white p-4">
      <p class="text-sm"><strong>${esc(c.competitor)}</strong> <span class="text-muted">· ${esc(c.platform || 'Any platform')}${c.posted_on ? ' · ' + esc(c.posted_on) : ''}</span></p>
      <p class="mt-2 line-clamp-5 flex-1 text-sm leading-relaxed">${esc(c.caption)}</p>
      <p class="mt-3 text-xs tabular-nums text-muted">${Number(c.views).toLocaleString()} views · ${Number(c.likes).toLocaleString()} likes · ${Number(c.comments).toLocaleString()} comments · ${Number(c.shares).toLocaleString()} shares</p>
      ${c.url ? `<a href="${esc(c.url)}" target="_blank" rel="noopener noreferrer" class="mt-2 text-sm text-sea underline">Open post</a>` : ''}
    </article>`).join('')
    : `<div class="rounded-2xl border border-dashed border-line bg-white p-4 sm:p-6 text-center text-sm text-muted sm:col-span-2 lg:col-span-3">No competitor posts saved for ${esc(data.platform)} yet. <a href="#swipe" class="text-sea underline">Add some to the swipe file</a> and they'll show up here with a comparison.</div>`;
  grid.classList.toggle('reveal', animate);
}

document.getElementById('copyAll').addEventListener('click', async ev => {
  const r = state.result;
  const text = [r.title, r.body, (r.hashtags || []).map(h => h.tag).join(' ')].filter(Boolean).join('\n\n');
  try { await navigator.clipboard.writeText(text); ev.target.textContent = 'Copied'; } catch (e) { ev.target.textContent = 'Copy failed'; }
  setTimeout(() => ev.target.textContent = 'Copy caption', 1500);
});

if (SAVED_HELP) {
  const firstImg = Object.entries(SAVED_HELP.images || {}).find(([k]) => k.startsWith('img'));
  chosenImage = firstImg ? firstImg[1] : null;
  render(SAVED_HELP, false);
  if (SAVED_HELP.peg) {   // keep the last peg ready for the next press
    pegKeep = true;
    showPeg(SAVED_HELP.peg.url, 'Using your last peg. Add a new one to replace it.');
    const u = document.querySelector(`input[name=peg_use][value="${SAVED_HELP.peg.use}"]`); if (u) u.checked = true;
    document.getElementById('pegOutcome').value = SAVED_HELP.peg.outcome || '';
  }
}

// ---------- The constellation above the button ----------
// Idle: Stella's S constellation breathes gently. Working: stars scatter, gather into the S, then drift and orbit
// while the lines march between them. Done: the lead star bursts and everything settles.
const CS_STAGE = document.getElementById('constellation');
const CS_PTS = [[46, 12], [25, 12], [16, 22], [32, 32], [48, 42], [40, 52], [18, 52]].map(([x, y]) => [x * 2.6 + 37, y * 2.6 + 2]);
const csStars = [...CS_STAGE.querySelectorAll('.cs')], csLine = document.getElementById('csLine'), csGroup = document.getElementById('csGroup');
const CS_REDUCE = matchMedia('(prefers-reduced-motion: reduce)').matches;
let csMode = 'idle', csFrom = null, csGather = 0;
const csT0 = performance.now();
function csDraw(pts, rot) {
  pts.forEach((p, i) => csStars[i].setAttribute('transform', `translate(${p[0].toFixed(1)} ${p[1].toFixed(1)})`));
  csLine.setAttribute('points', pts.map(p => p[0].toFixed(1) + ',' + p[1].toFixed(1)).join(' '));
  csGroup.setAttribute('transform', `rotate(${rot.toFixed(2)} 120 85)`);
}
function csFrame(now) {
  const t = (now - csT0) / 1000, busy = csMode === 'busy', amp = busy ? 7 : 2;
  let pts = CS_PTS.map(([x, y], i) => [x + Math.sin(t * (busy ? 2.1 : 1.1) + i * 1.7) * amp, y + Math.cos(t * (busy ? 1.8 : .9) + i * 2.3) * amp]);
  if (csFrom) {
    const k = Math.min(1, (now - csGather) / 950), e = 1 - Math.pow(1 - k, 3);
    pts = pts.map((p, i) => [csFrom[i][0] + (p[0] - csFrom[i][0]) * e, csFrom[i][1] + (p[1] - csFrom[i][1]) * e]);
    csLine.style.opacity = e;
    if (k >= 1) csFrom = null;
  }
  csDraw(pts, busy ? Math.sin(t * .9) * 9 : 0);
  requestAnimationFrame(csFrame);
}
if (CS_REDUCE) csDraw(CS_PTS, 0); else requestAnimationFrame(csFrame);
function constellation(state) {
  wrap.classList.toggle('busy', state === 'busy');
  if (state === 'busy') {
    csMode = 'busy';
    if (!CS_REDUCE) { csFrom = CS_PTS.map(() => [10 + Math.random() * 220, 10 + Math.random() * 150]); csGather = performance.now(); }
  } else {
    csMode = 'idle';
    if (state === 'done') { wrap.classList.add('burst'); setTimeout(() => wrap.classList.remove('burst'), 850); }
  }
}

// ---------- The button ----------
form.addEventListener('submit', async ev => {
  ev.preventDefault();
  const err = document.getElementById('sosError'), status = document.getElementById('sosStatus');
  err.textContent = '';
  if (!picked().length) { err.textContent = 'Pick at least one thing you need help with.'; return; }

  btn.classList.add('pressed');
  btn.disabled = true;
  constellation('busy');
  document.getElementById('sosLabel').textContent = 'Stella is on it…';
  status.textContent = 'Writing your post and reading competitor posts…';
  try {
    const fd = new FormData(form);
    fd.append('action', 'generate');
    const v = visuals.find(r => r.checked)?.value;
    if (v === 'images') {
      if (pegBlob) { fd.append('peg', pegBlob, 'peg.jpg'); status.textContent = 'Looking at your peg and writing your post…'; }
      else if (pegKeep) fd.append('peg_keep', '1');
    }
    const data = await api('help.php', fd);
    chosenImage = null;
    render(data, true);
    status.textContent = 'Done. Your post is below.';
    constellation('done');
    document.getElementById('results').scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  } catch (e) {
    err.textContent = e.message;
    status.textContent = 'Press to try again';
  }
  if (wrap.classList.contains('busy')) constellation('idle');
  btn.classList.remove('pressed');
  btn.classList.add('pop');
  setTimeout(() => btn.classList.remove('pop'), 500);
  document.getElementById('sosLabel').textContent = 'Create my post';
  syncSummary();
});

// ============ Mode tabs ============
const PREFILL = <?= json_encode($prefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const CPLAT = <?= json_encode($composePlatforms, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
function setMode(mode, animate = true) {
  document.querySelectorAll('.mode-tab').forEach(t => {
    const on = t.dataset.mode === mode;
    t.setAttribute('aria-selected', String(on));
    t.classList.toggle('bg-sea', on); t.classList.toggle('text-white', on); t.classList.toggle('shadow-sm', on);
    t.classList.toggle('text-muted', !on);
  });
  const show = document.getElementById(mode === 'write' ? 'panelWrite' : 'panelAi');
  const hide = document.getElementById(mode === 'write' ? 'panelAi' : 'panelWrite');
  hide.classList.add('hidden');
  show.classList.remove('hidden');
  if (animate) { show.classList.remove('panel-in'); void show.offsetWidth; show.classList.add('panel-in'); }
  history.replaceState(null, '', mode === 'write' ? 'help.php?mode=write' : 'help.php');
}
document.querySelectorAll('.mode-tab').forEach(t => t.addEventListener('click', () => setMode(t.dataset.mode)));

// ============ Write it myself ============
const cForm = document.getElementById('composeForm');
const cCaption = document.getElementById('cCaption');
const cFile = document.getElementById('cFile');

document.getElementById('cPlatforms').innerHTML = CPLAT.map(p => `<label class="${p.connected ? 'cursor-pointer' : 'cursor-not-allowed opacity-50'}">
  <input type="checkbox" name="platform_ids[]" value="${p.id}" class="peer sr-only" ${p.connected ? '' : 'disabled'} ${p.default ? 'checked' : ''}>
  <span class="flex items-center gap-2 rounded-full border border-line py-1 pl-1 pr-3 text-sm peer-checked:border-sea peer-checked:bg-foam peer-checked:font-semibold peer-focus-visible:ring-2 peer-focus-visible:ring-sea">${escapeHtml(p.name)}</span></label>`).join('');
if (!CPLAT.some(p => p.default)) { const first = CPLAT.find(p => p.connected); if (first) cForm.querySelector(`input[name="platform_ids[]"][value="${first.id}"]`).checked = true; }
document.getElementById('cConnectHint').classList.toggle('hidden', CPLAT.every(p => p.connected));
const chosenPlatforms = () => [...cForm.querySelectorAll('input[name="platform_ids[]"]:checked')].map(i => CPLAT.find(p => p.id === +i.value));

function updateCount() {
  const ps = chosenPlatforms();
  const len = [...cCaption.value].length, tags = (cCaption.value.match(/#[\p{L}\p{N}_]+/gu) || []).length;
  if (!ps.length) { document.getElementById('cCount').textContent = `${len} characters`; return; }
  const capMax = Math.min(...ps.map(p => p.caption_max)), tagMax = Math.min(...ps.map(p => p.hashtag_max));
  const tight = ps.find(p => p.caption_max === capMax);
  const el = document.getElementById('cCount');
  el.textContent = `${len} / ${capMax} characters${ps.length > 1 ? ' (' + tight.name + ')' : ''} · ${tags} / ${tagMax} hashtags`;
  el.className = 'text-xs tabular-nums ' + (len > capMax || tags > tagMax ? 'font-semibold text-fail' : 'text-muted');
}
cCaption.addEventListener('input', updateCount);
cForm.querySelectorAll('input[name="platform_ids[]"]').forEach(i => i.addEventListener('change', updateCount));

// Media: an uploaded file, or an AI image passed in
function showMedia(src, isVideo, label) {
  const thumb = document.getElementById('cThumb');
  thumb.innerHTML = isVideo ? `<video src="${src}" muted playsinline class="h-full w-full object-cover"></video>` : `<img src="${src}" alt="" class="h-full w-full object-cover">`;
  const m = thumb.firstElementChild;
  const measure = () => {
    document.getElementById('cW').value = isVideo ? m.videoWidth : m.naturalWidth;
    document.getElementById('cH').value = isVideo ? m.videoHeight : m.naturalHeight;
    if (isVideo) document.getElementById('cD').value = m.duration.toFixed(1);
    document.getElementById('cMediaInfo').textContent = label + ' · ' + (isVideo ? `${m.videoWidth} × ${m.videoHeight}, ${m.duration.toFixed(1)} s` : `${m.naturalWidth} × ${m.naturalHeight}`);
  };
  m.addEventListener(isVideo ? 'loadedmetadata' : 'load', measure);
  document.getElementById('cMediaInfo').textContent = label;
  document.getElementById('cMediaBox').classList.replace('hidden', 'flex');
  document.getElementById('cDrop').classList.add('hidden');
}
function clearMedia() {
  cFile.value = '';
  ['cMediaFile', 'cW', 'cH', 'cD'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('cThumb').innerHTML = '';
  document.getElementById('cMediaBox').classList.replace('flex', 'hidden');
  document.getElementById('cDrop').classList.remove('hidden');
}
cFile.addEventListener('change', () => {
  const f = cFile.files[0];
  if (!f) return clearMedia();
  document.getElementById('cMediaFile').value = '';
  showMedia(URL.createObjectURL(f), f.type.startsWith('video/'), `${f.name} · ${(f.size / 1048576).toFixed(1)} MB`);
});
document.getElementById('cMediaRemove').addEventListener('click', clearMedia);

// When: now or a date and time
const syncWhen = () => {
  const later = cForm.querySelector('input[name=when]:checked').value === 'later';
  document.getElementById('cLater').classList.toggle('hidden', !later);
  document.getElementById('cSubmit').textContent = later ? 'Schedule post' : 'Post now';
};
cForm.querySelectorAll('input[name=when]').forEach(r => r.addEventListener('change', syncWhen));

document.getElementById('cSuggest').addEventListener('click', async ev => {
  const b = ev.currentTarget, why = document.getElementById('cWhy');
  const ps = chosenPlatforms(), date = document.getElementById('cDate').value;
  if (!date) { why.innerHTML = '<span class="text-fail">Pick a date first.</span>'; return; }
  b.disabled = true; why.textContent = 'Thinking about your audience…';
  const fd = new FormData();
  fd.append('action', 'suggest_time');
  fd.append('platform_id', ps[0] ? ps[0].id : CPLAT[0].id);
  fd.append('date', date);
  fd.append('caption', cCaption.value);
  try {
    const r = await api('compose.php', fd);
    document.getElementById('cTime').value = r.time;
    why.innerHTML = `<span class="font-semibold text-sea">Suggested ${escapeHtml(r.time)}.</span> ${escapeHtml(r.why)}`;
  } catch (e) { why.innerHTML = `<span class="text-fail">${escapeHtml(e.message)}</span>`; }
  b.disabled = false;
});

function composeData() {
  const fd = new FormData(cForm);
  if (!cFile.files.length) fd.delete('file');
  return fd;
}
cForm.addEventListener('submit', async ev => {
  ev.preventDefault();
  const err = document.getElementById('cErr'), done = document.getElementById('cDone'), btn = document.getElementById('cSubmit');
  err.textContent = ''; done.classList.add('hidden');
  if (!chosenPlatforms().length) { err.textContent = 'Pick at least one connected platform.'; return; }
  const fd = composeData();
  fd.append('action', 'schedule');
  btn.disabled = true;
  const label = btn.textContent; btn.textContent = 'Saving…';
  try {
    const r = await api('compose.php', fd);
    done.innerHTML = `<p class="font-semibold">${escapeHtml(r.message)}</p>
      <p class="mt-2 flex gap-4"><a href="calendar.php?month=${r.month}" class="underline">View in calendar</a><button type="button" id="cAgain" class="underline">Create another</button></p>`;
    done.classList.remove('hidden');
    document.getElementById('cAgain').addEventListener('click', () => { cForm.reset(); clearMedia(); cForm.querySelector('input[name=when][value=later]').checked = true; syncWhen(); updateCount(); done.classList.add('hidden'); });
  } catch (e) { err.textContent = e.message; }
  btn.disabled = false; btn.textContent = label;
});

// Optional pre-flight check: runs on the first selected platform, then opens the result
document.getElementById('cCheck').addEventListener('click', async ev => {
  const err = document.getElementById('cErr'), b = ev.currentTarget;
  err.textContent = '';
  const ps = chosenPlatforms();
  if (!ps.length) { err.textContent = 'Pick a platform to check against.'; return; }
  const fd = composeData();
  fd.append('platform_id', ps[0].id);
  b.disabled = true; b.textContent = 'Checking…';
  try { const r = await api('check.php', fd); window.location = r.redirect; }
  catch (e) { err.textContent = e.message; b.disabled = false; b.textContent = 'Run a pre-flight check'; }
});

// Bring a post over from the AI tab (or from the URL)
function fillComposer({ platform, caption, media, date }) {
  if (caption) cCaption.value = caption;
  if (platform) {
    const p = CPLAT.find(x => x.name === platform && x.connected);
    if (p) { cForm.querySelectorAll('input[name="platform_ids[]"]').forEach(i => i.checked = +i.value === p.id); }
  }
  if (media) { clearMedia(); document.getElementById('cMediaFile').value = media; showMedia('media.php?f=' + encodeURIComponent(media), /\.(mp4|mov|webm)$/i.test(media), 'AI image'); }
  if (date) document.getElementById('cDate').value = date;
  updateCount();
  setMode('write');
  window.scrollTo({ top: 0, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
}

syncWhen();
updateCount();
if (PREFILL.date) document.getElementById('cDate').value = PREFILL.date;
if (PREFILL.mode === 'write') fillComposer({ platform: PREFILL.platform, caption: PREFILL.caption, media: PREFILL.media, date: PREFILL.date });
else setMode('ai', false);
</script>
<?php page_footer(); ?>

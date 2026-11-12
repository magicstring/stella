<?php
require_once __DIR__ . '/lib/bootstrap.php';
require_login();

$platforms = platforms();
$business = current_business();

// Prefill from a suggestion or a re-check link
$prefCaption = (string)($_GET['caption'] ?? '');
$prefPlatform = platform_by_name((string)($_GET['platform'] ?? ''));
$prefVisual = (string)($_GET['visual'] ?? '');
$prefMedia = Media::path((string)($_GET['media'] ?? '')) ? (string)$_GET['media'] : '';  // e.g. an AI image from Ask for help
$selectedId = (int)($prefPlatform['id'] ?? ($platforms[0]['id'] ?? 0));

$jsPlatforms = [];
foreach ($platforms as $p) {
    $jsPlatforms[$p['id']] = Prompts::platformLimits($p);
}

page_header('Pre-flight check', 'index.php');
?>
<div class="grid lg:grid-cols-[1fr_320px] gap-6 lg:gap-10">
  <section>
    <h1 class="font-display text-2xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight mb-2">Check a post before it sails</h1>
    <p class="text-muted mb-5 sm:mb-8 max-w-prose">Write the caption, attach the visual and pick where it's going. Stella checks platform limits and risky wording, then Claude reviews policy risk, brand safety, the hook and trend fit.</p>

    <?php if (!$platforms): ?>
      <div class="rounded-md bg-failbg text-fail px-4 py-3">No platforms found. Import <code>seed.sql</code> in phpMyAdmin first.</div>
    <?php else: ?>
    <form id="checkForm" class="space-y-7" novalidate>
      <fieldset>
        <legend class="font-semibold mb-2">Platform</legend>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($platforms as $p): ?>
            <label class="cursor-pointer">
              <input type="radio" name="platform_id" value="<?= (int)$p['id'] ?>" class="peer sr-only" <?= (int)$p['id'] === $selectedId ? 'checked' : '' ?>>
              <span class="block rounded-md border border-line bg-white px-4 py-2 text-sm peer-checked:border-sea peer-checked:bg-foam peer-checked:text-seadeep peer-checked:font-semibold peer-focus-visible:ring-2 peer-focus-visible:ring-sea"><?= e($p['name']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>

      <div>
        <div class="flex items-baseline justify-between mb-2">
          <label for="caption" class="font-semibold">Caption and hashtags</label>
          <span id="counter" class="text-sm text-muted tabular-nums" aria-live="polite"></span>
        </div>
        <textarea id="caption" name="caption" rows="7" class="w-full rounded-md border border-line bg-white px-3 py-2 leading-relaxed focus:border-sea focus:outline-none" placeholder="First line is your hook. Hashtags can go at the end."><?= e($prefCaption) ?></textarea>
      </div>

      <div>
        <label for="file" class="font-semibold block mb-2">Image or video</label>
        <?php if ($prefVisual !== ''): ?>
          <p class="text-sm bg-foam text-seadeep rounded-md px-3 py-2 mb-3"><strong>Visual to make:</strong> <?= e($prefVisual) ?></p>
        <?php endif; ?>
        <?php if ($prefMedia !== ''): ?>
          <div id="presetBox" class="mb-3 flex items-center gap-3 rounded-lg border border-line bg-white p-2">
            <img id="presetImg" src="media.php?f=<?= e($prefMedia) ?>" alt="AI-generated image for this post" class="w-16 h-20 rounded object-cover">
            <p class="flex-1 text-sm"><strong>AI image attached.</strong> <span class="text-muted">Choose a file below to replace it.</span></p>
          </div>
          <input type="hidden" name="media_file" id="media_file" value="<?= e($prefMedia) ?>">
        <?php endif; ?>
        <input id="file" name="file" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm"
               class="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-foam file:px-4 file:py-2 file:text-sea file:font-semibold hover:file:bg-sea hover:file:text-white">
        <p class="text-xs text-muted mt-1">JPG, PNG, WebP, MP4, MOV or WebM, up to <?= MAX_UPLOAD_MB ?> MB.</p>
        <div id="preview" class="mt-4 hidden">
          <div id="previewMedia" class="max-w-[240px] rounded-md overflow-hidden border border-line bg-black"></div>
          <p id="previewMeta" class="text-sm text-muted mt-2 tabular-nums"></p>
        </div>
      </div>

      <input type="hidden" name="client_width" id="client_width">
      <input type="hidden" name="client_height" id="client_height">
      <input type="hidden" name="client_duration" id="client_duration">

      <div class="flex flex-wrap items-center gap-4">
        <button id="runBtn" type="submit" class="inline-flex items-center gap-2 rounded-md bg-sea px-6 py-3 font-semibold text-white hover:bg-seadeep disabled:opacity-60">
          <svg id="spinner" class="spin hidden" width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="40 20"/></svg>
          <span id="runLabel">Run pre-flight check</span>
        </button>
        <p id="formError" role="alert" class="text-sm text-fail"></p>
      </div>
    </form>
    <?php endif; ?>
  </section>

  <aside class="space-y-6 lg:pt-16">
    <div class="rounded-md border border-line bg-white p-4 sm:p-5">
      <h2 class="font-semibold mb-1">Checking for</h2>
      <?php if ($business): ?>
        <p class="font-display text-xl font-extrabold"><?= e($business['name']) ?></p>
        <p class="text-sm text-muted mt-1"><?= e($business['tone']) ?><?= $business['location'] ? ' · ' . e($business['location']) : '' ?></p>
        <a href="profile.php" class="text-sm text-sea underline mt-3 inline-block">Edit business</a>
      <?php else: ?>
        <p class="text-sm text-muted">No business profile yet. Claude reviews better when it knows who's posting.</p>
        <a href="profile.php" class="text-sm text-sea underline mt-2 inline-block">Set up your business</a>
      <?php endif; ?>
    </div>
    <div class="rounded-md border border-line bg-white p-4 sm:p-5">
      <h2 class="font-semibold mb-1">Need an idea?</h2>
      <p class="text-sm text-muted">Get three post ideas built from your business profile and what's rising in the Philippines.</p>
      <a href="suggest.php" class="text-sm text-sea underline mt-2 inline-block">Suggest posts</a>
    </div>
  </aside>
</div>

<script>
const PLATFORMS = <?= json_encode($jsPlatforms, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
const form = document.getElementById('checkForm');
if (form) {
  const caption = document.getElementById('caption');
  const counter = document.getElementById('counter');
  const fileInput = document.getElementById('file');

  function currentPlatform() {
    const r = form.querySelector('input[name=platform_id]:checked');
    return r ? PLATFORMS[r.value] : null;
  }
  function updateCounter() {
    const p = currentPlatform();
    if (!p) return;
    const len = [...caption.value].length;
    const tags = (caption.value.match(/#[\p{L}\p{N}_]+/gu) || []).length;
    counter.textContent = `${len} / ${p.caption_max} characters · ${tags} / ${p.hashtag_max} hashtags`;
    counter.className = 'text-sm tabular-nums ' + (len > p.caption_max || tags > p.hashtag_max ? 'text-fail font-semibold' : 'text-muted');
  }
  caption.addEventListener('input', updateCounter);
  form.querySelectorAll('input[name=platform_id]').forEach(r => r.addEventListener('change', updateCounter));
  updateCounter();

  // An AI image passed in from Ask for help: measure it so the shape check works
  const presetImg = document.getElementById('presetImg');
  if (presetImg) {
    const setSize = () => {
      document.getElementById('client_width').value = presetImg.naturalWidth;
      document.getElementById('client_height').value = presetImg.naturalHeight;
    };
    presetImg.complete ? setSize() : presetImg.addEventListener('load', setSize);
  }

  // Preview, and measure dimensions and length in the browser (handles phone video rotation).
  fileInput.addEventListener('change', () => {
    const f = fileInput.files[0];
    const box = document.getElementById('previewMedia');
    const meta = document.getElementById('previewMeta');
    ['client_width', 'client_height', 'client_duration'].forEach(id => document.getElementById(id).value = '');
    box.innerHTML = '';
    if (!f) { document.getElementById('preview').classList.add('hidden'); return; }
    document.getElementById('preview').classList.remove('hidden');
    const url = URL.createObjectURL(f);
    const mb = (f.size / 1048576).toFixed(1);
    if (f.type.startsWith('video/')) {
      const v = document.createElement('video');
      v.src = url; v.muted = true; v.controls = true; v.playsInline = true; v.className = 'w-full';
      v.addEventListener('loadedmetadata', () => {
        document.getElementById('client_width').value = v.videoWidth;
        document.getElementById('client_height').value = v.videoHeight;
        document.getElementById('client_duration').value = v.duration.toFixed(1);
        meta.textContent = `${v.videoWidth} × ${v.videoHeight} · ${v.duration.toFixed(1)} s · ${mb} MB`;
      });
      box.appendChild(v);
    } else {
      const img = new Image();
      img.src = url; img.alt = 'Preview of the selected image'; img.className = 'w-full';
      img.onload = () => {
        document.getElementById('client_width').value = img.naturalWidth;
        document.getElementById('client_height').value = img.naturalHeight;
        meta.textContent = `${img.naturalWidth} × ${img.naturalHeight} · ${mb} MB`;
      };
      box.appendChild(img);
    }
  });

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const err = document.getElementById('formError');
    err.textContent = '';
    if (fileInput.files.length) { const mf = document.getElementById('media_file'); if (mf) mf.remove(); const pb = document.getElementById('presetBox'); if (pb) pb.remove(); }
    if (!caption.value.trim() && !fileInput.files.length && !document.getElementById('media_file')) {
      err.textContent = 'Add a caption or a file to check.';
      return;
    }
    const btn = document.getElementById('runBtn');
    btn.disabled = true;
    document.getElementById('spinner').classList.remove('hidden');
    document.getElementById('runLabel').textContent = 'Checking rules and asking Claude…';
    try {
      const data = await api('check.php', new FormData(form));
      window.location = data.redirect;
    } catch (e) {
      err.textContent = e.message;
      btn.disabled = false;
      document.getElementById('spinner').classList.add('hidden');
      document.getElementById('runLabel').textContent = 'Run pre-flight check';
    }
  });
}
</script>
<?php page_footer(); ?>

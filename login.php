<?php
require_once __DIR__ . '/lib/bootstrap.php';

if (APP_PASSWORD === '' || !empty($_SESSION['signed_in'])) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (hash_equals(APP_PASSWORD, (string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['signed_in'] = true;
        redirect('dashboard.php');
    }
    sleep(1);
    $error = "That password isn't right.";
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= BRAND['night'] ?>">
<title>Sign in · Stella</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,800&family=IBM+Plex+Sans:wght@400;600&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { theme: { extend: { colors: <?= json_encode(BRAND) ?> } } };</script>
<style>
  body{font-family:"IBM Plex Sans",system-ui,sans-serif;background:radial-gradient(120% 90% at 80% 0%,#25307A 0%,<?= BRAND['night'] ?> 55%,#090D24 100%) fixed}
  .display{font-family:"Bricolage Grotesque",system-ui,sans-serif}
  :focus-visible{outline:3px solid <?= BRAND['nova2'] ?>;outline-offset:2px}
  .sky{position:fixed;inset:0;pointer-events:none;background-image:
    radial-gradient(1.5px 1.5px at 12% 18%,#fff8,transparent),radial-gradient(1px 1px at 28% 72%,#fff6,transparent),
    radial-gradient(1.5px 1.5px at 44% 12%,#fff7,transparent),radial-gradient(1px 1px at 63% 38%,#fff5,transparent),
    radial-gradient(1.5px 1.5px at 78% 82%,#fff7,transparent),radial-gradient(1px 1px at 88% 24%,#fff6,transparent),
    radial-gradient(1px 1px at 8% 88%,#fff5,transparent),radial-gradient(1.5px 1.5px at 55% 92%,#fff6,transparent),
    radial-gradient(1px 1px at 93% 58%,#fff5,transparent),radial-gradient(1px 1px at 35% 45%,#fff4,transparent)}
</style>
</head>
<body class="min-h-screen text-ink flex items-center justify-center p-4">
  <div class="sky" aria-hidden="true"></div>
  <main class="relative w-full max-w-sm">
    <div class="flex items-center justify-center gap-3 mb-6 text-white">
      <?= stella_mark(52, true) ?>
      <h1 class="display text-5xl font-extrabold tracking-tight">stella</h1>
    </div>
    <form method="post" class="rounded-2xl bg-white p-8 shadow-2xl shadow-black/30">
      <?= csrf_field() ?>
      <p class="text-muted mb-6">Suggest, check and publish posts that shine across your platforms.</p>
      <label for="password" class="block text-sm font-semibold mb-1">Team password</label>
      <input id="password" name="password" type="password" required autofocus autocomplete="current-password"
             class="w-full rounded-lg border border-line px-3 py-2.5 mb-3 focus:border-sea focus:outline-none">
      <?php if ($error): ?><p role="alert" class="text-sm text-[#B42318] mb-3"><?= e($error) ?></p><?php endif; ?>
      <button class="w-full rounded-lg bg-sea text-white font-semibold py-2.5 hover:bg-seadeep">Sign in</button>
    </form>
  </main>
</body>
</html>

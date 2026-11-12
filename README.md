# SailePost

Suggest posts from a business profile, check them before they go out, and publish across platforms.
PHP 8, MySQL, Tailwind (CDN), vanilla JS, Claude API. No frameworks, no Composer.

## Set up on Hostinger (about 15 minutes)

1. **Database.** hPanel > Databases: create a database and user. In phpMyAdmin import `schema.sql`, then `seed.sql`.
   If you already imported the Milestone 1 schema, import `upgrade_m1.sql` instead of `schema.sql`.
2. **PHP settings.** hPanel > Advanced > PHP Configuration: PHP 8.1 or newer, `fileinfo` and `mbstring` on,
   `upload_max_filesize` and `post_max_size` at 100M or more.
3. **Config.** Fill in `config.php`: database, Claude API key, a team password, and optionally your Facebook Page.
4. **Upload.** Put every file in `public_html` (keep the `lib` folder). Uploads are saved to
   `sailepost_uploads/` next to `public_html`, outside the web root, and created automatically.
5. **Test.** Open `/test.php`. Every line should say OK (Facebook can say "not configured"). Then delete `test.php`.
6. **Cron (optional).** hPanel > Advanced > Cron Jobs, every 5 minutes:
   `php /home/USER/public_html/run_scheduled.php`
   Without it, scheduled posts still go out whenever someone opens History or a Publish page.

## Facebook Page posting (optional, for the one real platform)

Roughly, and check Meta's current docs since the steps change:
1. Create an app at developers.facebook.com (Business type). Development mode is fine for a Page you admin.
2. In Graph API Explorer, pick the app, add `pages_manage_posts` and `pages_read_engagement`, generate a user token,
   then get a Page token from `/me/accounts`.
3. Put the Page ID and Page token in `config.php`. Confirm `FB_GRAPH_VERSION` is current.

Every other platform is simulated and labelled "Simulated" in the app.

## How it works

| Page | What it does |
| --- | --- |
| `profile.php` | Business profile Claude writes for |
| `suggest.php` | 3 post ideas from the profile and top trends |
| `index.php` | Write or paste a post, attach the visual, run the check |
| `check.php` | Rules + Claude review, saves a check, returns JSON |
| `result.php` | Score, verdict, every issue with its fix, trend fit |
| `fix.php` | Rewrites the caption for every platform |
| `publish.php` | Post now or schedule, per platform |
| `trends.php` | Trends list, add, edit, CSV import |
| `history.php` | Past checks and posts |

Code lives in `lib/`: `Rules.php` (platform limits, risky words), `Media.php` (uploads, size, shape, length),
`Prompts.php` (every Claude prompt), `Preflight.php` (score and verdict), `Publisher.php` and `Facebook.php`.

**Score:** 40% rules (−20 per fail, −7 per warn) + 60% Claude's overall score. Rules only if Claude is unreachable.
**Verdict:** *Don't post yet* on any rule fail, high policy or brand risk, or a score under 50.
*Fix before posting* on warnings, medium risk, missing #ad, or a score under 80. Otherwise *Ready to post*.

## Test posts for Milestone 2

- **Too long for Shorts:** pick YouTube Shorts and paste a caption over 100 characters.
- **Risky claim:** "This serum cures acne in 3 days, 100% effective! Link in bio #skincare"
- **Missing #ad:** "Thanks @brand for sending this over! Use my code KEVIN10 for 10% off #glow"
- **Wrong shape:** attach a landscape video for TikTok.

## Before the demo

- Change `APP_PASSWORD`, delete `test.php`, import real trends.
- Platform limits in `seed.sql` are approximate; verify them on each platform's help pages.

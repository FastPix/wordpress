# FastPix Video for WordPress

Upload, embed, protect and measure video from your WordPress dashboard, powered by [FastPix](https://fastpix.com). Visitors stream straight from the FastPix network. A FastPix account is required.

- **Version:** 2.0.0
- **Requires:** WordPress 6.8+, PHP 8.3+, HTTPS, MySQL 8.0+ or MariaDB 10.11+ (InnoDB)
- **Tested up to:** WordPress 7.1
- **License:** GPLv2 or later
- **User docs:** [fastpix.com/docs/integrations/wordpress](https://fastpix.com/docs/integrations/wordpress) · WordPress.org readme: `readme.txt` · History: `CHANGELOG.md`

## Features

- **Upload & migrate** — resumable browser uploads, import from a public URL, and Media Library migration (originals are kept).
- **Video library** — search across titles, transcripts and chapters; filters, bulk actions, subtitles, on-demand AI chapters, summaries and entities.
- **Embed** — Gutenberg block and `[fastpix id="…"]` shortcode, with VideoObject structured data for public videos.
- **Protected playback** — public, private (signed) and DRM video, with access tied to the posts that embed it.
- **Analytics** — views, watch time, quality of experience and errors from a local daily rollup; consent-gated watch progress with resume.
- **Course features (optional)** — LearnDash, Tutor LMS, LifterLMS and LearnPress: skip-proof completion, automatic lesson credit, per-course analytics.
- **Live streaming** — RTMPS or SRT streams; one embed moves from waiting card to live player to recording.

## Install

1. Upload the plugin zip in **Plugins → Add New → Upload Plugin** and activate. Activation checks the requirements and names any that fail (`wp fastpix doctor` runs the same checks).
2. In the FastPix dashboard, go to **Manage → Access Tokens** and create a token with Video (read + write), Data (read) and System permissions. Copy the token ID, secret key and workspace key.
3. The setup wizard opens (**FastPix → Connection**). Paste the token ID and secret, click **Connect**, then save the workspace key.
4. Optional: copy the webhook URL from **FastPix → Settings**, add it under **Org Settings → Webhooks** in the FastPix dashboard, paste the signing secret back and click **Save & verify**. Without webhooks the plugin polls FastPix every few minutes.

From source:

```bash
git clone https://FastPix@dev.azure.com/FastPix/SDKs/_git/Wordpress-plugin fastpix
cp -r fastpix /path/to/wordpress/wp-content/plugins/
```

`vendor/` is committed (Action Scheduler), so no Composer step is needed on the server.

## Layout

| Path | What it holds |
|---|---|
| `fastpix-video-embed.php` | Plugin entry point; loads every class explicitly. |
| `includes/class-fastpix-*.php` | One class per concern: API client, connection, uploads, sync, render, analytics, LMS, live, REST, schema, jobs. |
| `templates/` | Admin screens (wizard, videos, add media, analytics, settings), rendered through `fastpix_template()`. |
| `assets/css`, `assets/js` | Plain CSS and ES5-style JavaScript, no build step. |
| `assets/vendor/` | Bundled FastPix player, resumable uploader and hls.js. |
| `blocks/video/` | The Gutenberg block (uses the `wp` globals, no bundler). |
| `config/` | Defaults such as accepted MIME types. |
| `vendor/` | Action Scheduler (background jobs). |
| `tests/` | Self-checks that run against a real WordPress install. |
| `uninstall.php` | Removes local data only when the owner opted in. |

## Architecture

`Fastpix_Api_Client` is the only class that talks HTTP to FastPix and handles retries and the circuit breaker. Signed webhooks (`/wp-json/fastpix/v1/webhook`) and periodic sweeps keep the local video registry in step with the platform, with processing done in Action Scheduler jobs. Every REST route registers through `Fastpix_Rest::register()` with a capability or a public rate-limited bucket. The renderer produces the block, shortcode and player markup, signs private playback and caches public markup for an hour. Analytics screens read only the local daily rollup, never FastPix at render time. Watch progress and per-learner data stay in the WordPress database.

## Running the self-checks

The tests are plain PHP files that load a real WordPress site with cron and Action Scheduler held still. Run them inside the WordPress container, or on any host where `/var/www/html/wp-load.php` is the site to test:

```bash
php -d zend.assertions=1 -d assert.exception=1 tests/test-analytics.php
```

Each file prints `<name>: all checks passed` (or `OK`), or throws an `AssertionError` naming the failed check. Tests snapshot and restore what they touch and leave no fixtures behind. Lint with `php -l` and `node --check assets/js/<file>.js`.

## Building the distribution zip

`.distignore` lists what must not ship (tests, docs, specs, tooling). Copy the tree minus those entries into a folder named `fastpix/` and zip that folder, so the zip unpacks to a single `fastpix/` directory. Then run Plugin Check on the result:

```bash
wp plugin check /path/to/fastpix
```

## Contributing

Work on a `feature/*` branch; the Azure pipeline runs SonarCloud on every push and pull request. Keep JavaScript build-free, register REST routes only through `Fastpix_Rest::register()`, prefix template variables with `$fastpix_`, and add a self-check under `tests/` for any new server-side behaviour.

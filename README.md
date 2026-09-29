# FastPix Video for WordPress - video hosting, streaming and analytics plugin

[![Version](https://img.shields.io/badge/version-2.0.0-5D09C7.svg)](CHANGELOG.md)
[![WordPress](https://img.shields.io/badge/WordPress-6.8%2B-21759B.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4.svg)](https://www.php.net)
[![License](https://img.shields.io/badge/license-GPLv2%20or%20later-green.svg)](LICENSE)
[![Powered by FastPix](https://img.shields.io/badge/powered%20by-FastPix-090114.svg)](https://fastpix.com)

**FastPix Video for WordPress is a video hosting and streaming plugin** that lets you upload, embed, protect and measure video directly from your WordPress dashboard. Instead of slow self-hosted video files or generic embeds, your visitors stream adaptive-bitrate video from the [FastPix](https://fastpix.com) network, and you manage every video, its analytics and its access rules inside WordPress.

It brings resumable video uploads, a Gutenberg video block and a `[fastpix id="…"]` shortcode, signed private and DRM-protected playback, live streaming, video SEO (VideoObject structured data), LMS completion tracking and built-in video analytics to any WordPress site.

Ideal for publishers, membership and course sites, and media teams that need private or DRM-protected video, live streaming, or quality-of-experience analytics on WordPress. A FastPix account is required.

**Requires:** WordPress 6.8+, PHP 8.3+, MySQL 8.0+ or MariaDB 10.11+ (InnoDB). HTTPS is recommended and required for DRM playback. Tested up to WordPress 7.1. Licensed GPLv2 or later.

[User docs](https://fastpix.com/docs/integrations/wordpress) · [Create a FastPix account](https://dashboard.fastpix.com/signup?utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-plugin) · [Pricing](https://fastpix.com/pricing)

---

## Get started

Follow these in order to go from install to your first embedded, protected video:

1. [Why FastPix Video for WordPress?](#why-fastpix-video-for-wordpress)
2. [Features](#features)
3. [How it works](#how-it-works)
4. [Install and connect](#install-and-connect)
5. [Embed a video](#embed-a-video)
6. [FAQ](#faq)

## For developers

- [Install from source](#install-from-source)
- [Plugin layout](#plugin-layout)
- [Architecture](#architecture)
- [Running the self-checks](#running-the-self-checks)
- [Building the distribution zip](#building-the-distribution-zip)
- [Contributing](#contributing)

---

## Why FastPix Video for WordPress?

Hosting video on WordPress the default way is painful: large files bloat your server, playback stutters without adaptive streaming, there is no real way to protect a video, and you get no data on who watched what. This plugin moves hosting, encoding and delivery to FastPix while keeping the editing experience native to WordPress.

- **Real video hosting for WordPress.** Uploads go to FastPix, are encoded to adaptive-bitrate HLS, and stream from a multi-CDN network - not from your web host.
- **Native embedding.** A Gutenberg block and a `[fastpix id="…"]` shortcode, with VideoObject structured data so public videos are eligible for video rich results (video SEO).
- **Protected video.** Public, signed private, and DRM playback, with access tied to the posts that embed each video.
- **Built-in analytics.** Views, watch time, quality of experience and errors, shown in your WordPress admin - no separate analytics tag to install.
- **Usage-based pricing.** FastPix bills per minute of video, so costs track your actual library and viewership. See [pricing](https://fastpix.com/pricing).

## Features

- **Upload and migrate** - resumable browser uploads, import from a public URL, and Media Library migration (originals are kept).
- **Video library** - search across titles, transcripts and chapters; filters, bulk actions, subtitles, on-demand AI chapters, summaries and entities.
- **Embed** - Gutenberg block and `[fastpix id="…"]` shortcode, with VideoObject structured data for public videos.
- **Protected playback** - public, private (signed) and DRM video, with access tied to the posts that embed it.
- **Analytics** - views, watch time, quality of experience and errors, stored as a local daily rollup; consent-gated watch progress with resume.
- **Course features (optional)** - LearnDash, Tutor LMS, LifterLMS and LearnPress: skip-proof completion, automatic lesson credit, per-course analytics.
- **Live streaming** - RTMPS or SRT streams; one embed moves from waiting card to live player to recording.

## How it works

You upload or import a video from WordPress. FastPix encodes it to adaptive-bitrate HLS and stores it for multi-CDN delivery. You embed it with the block or shortcode and choose whether it is public, private or DRM-protected. While visitors watch, the FastPix player reports playback quality to FastPix (only with the visitor's consent where a consent platform is in use); the plugin pulls the resulting figures into a local daily rollup that you read from the WordPress admin.

## Install and connect

1. Upload the plugin zip in **Plugins → Add New → Upload Plugin** and activate. Activation checks the requirements and names any that fail (`wp fastpix doctor` runs the same checks).
2. In the FastPix dashboard, go to **Manage → Access Tokens** and create a token with Video (read + write), Data (read) and System permissions. Copy the token ID, secret key and workspace key.
3. The setup wizard opens (**FastPix → Connection**). Paste the token ID and secret, click **Connect**, then save the workspace key.
4. Optional: copy the webhook URL from **FastPix → Settings**, add it under **Settings → Webhooks** in the FastPix dashboard, paste the signing secret back and click **Save & verify**. Without webhooks the plugin checks FastPix for new videos every 15 minutes and refreshes older ones nightly.

## Embed a video

Once a video reaches **Ready**, embed it anywhere with the `[fastpix id="…"]` shortcode (replace `…` with the media ID from your video library), or add the **FastPix Video** block in the Gutenberg editor and pick the video from your library. Public videos ship VideoObject structured data automatically, so they are eligible for video rich results in search.

## Install from source

Clone the repository into a folder named `fastpix-video` (the plugin's WordPress.org slug) inside your WordPress plugins directory:

```bash
git clone https://github.com/FastPix/wordpress.git /path/to/wordpress/wp-content/plugins/fastpix-video
```

`vendor/` is committed (Action Scheduler), so no Composer step is needed on the server.

## Plugin layout

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

Each file prints `<name>: all checks passed` (or `OK`), or throws an `AssertionError` naming the failed check. Tests snapshot and restore what they touch and clean up their fixtures when they finish; a run stopped half-way can leave fixtures behind, which the next run clears. Lint with `php -l` and `node --check assets/js/<file>.js`.

## Building the distribution zip

`.distignore` lists what must not ship (tests, docs, specs, tooling, CI files). Copy the tree minus those entries into a folder named `fastpix-io/` (the WordPress.org slug) and zip that folder, so the zip unpacks to a single `fastpix-io/` directory. Then run Plugin Check on the result:

```bash
wp plugin check /path/to/fastpix-video
```

## FAQ

**What is the best way to host video on WordPress?**
Hosting large video files on your own web server slows the site and offers no adaptive streaming. A video hosting plugin like FastPix Video for WordPress uploads your video to a dedicated video platform, encodes it to adaptive-bitrate HLS, and streams it from a multi-CDN network, while you manage everything from WordPress.

**How do I embed a video in WordPress with this plugin?**
Add the **FastPix Video** Gutenberg block and pick the video, or paste the `[fastpix id="…"]` shortcode into any post or page. See [Embed a video](#embed-a-video).

**Can I add private or password-protected video to WordPress?**
Yes. Videos can be public, signed private, or DRM-protected, and access is tied to the posts that embed them, so a private video only plays where you placed it.

**Does it support adaptive-bitrate streaming?**
Yes. FastPix encodes each upload to adaptive-bitrate HLS, so playback adjusts to each viewer's connection instead of serving one fixed file.

**Does it help with video SEO?**
Public videos ship VideoObject structured data automatically, which makes them eligible for video rich results in Google search.

**Can I live stream on WordPress?**
Yes. Start an RTMPS or SRT stream and a single embed moves from a waiting card to the live player to the recording.

**Does it work with LearnDash and other LMS plugins?**
Yes - LearnDash, Tutor LMS, LifterLMS and LearnPress are supported, with skip-proof completion, automatic lesson credit and per-course analytics.

**What video analytics do I get?**
Views, watch time, quality of experience and errors, shown in your WordPress admin from a local daily rollup. The FastPix player reports playback quality to FastPix, and the plugin pulls the figures from there - there is no separate analytics tag to install. Where a consent platform is in use, reporting runs only with the visitor's consent.

**Is a FastPix account required?**
Yes. The plugin connects to your FastPix workspace for hosting, encoding and delivery. [Create an account](https://dashboard.fastpix.com/signup?utm_source=wordpress&utm_medium=plugin&utm_campaign=wp-plugin).

**How is it priced?**
FastPix is usage-based, billed per minute of video. See [pricing](https://fastpix.com/pricing).

## Related FastPix tools

- [migration-tool](https://github.com/FastPix/migration-tool) - move an existing video library to FastPix.
- [web-player-component](https://github.com/FastPix/web-player-component) - the FastPix web player used for playback.
- [web-uploads-sdk](https://github.com/FastPix/web-uploads-sdk) - resumable browser uploads that power the upload flow.
- [node-sdk](https://github.com/FastPix/node-sdk) and [fastpix-php](https://github.com/FastPix/fastpix-php) - server SDKs for building your own FastPix integrations.
- [moodle-mod_fastpix](https://github.com/FastPix/moodle-mod_fastpix) - the FastPix activity plugin for Moodle, if you also run an LMS outside WordPress.

## Contributing

Work on a `feature/*` branch; the Azure pipeline runs SonarCloud on every push and pull request. Keep JavaScript build-free, register REST routes only through `Fastpix_Rest::register()`, prefix template variables with `$fastpix_`, and add a self-check under `tests/` for any new server-side behaviour.

## Documentation

Full setup and usage docs live at [fastpix.com/docs/integrations/wordpress](https://fastpix.com/docs/integrations/wordpress). The WordPress.org readme is `readme.txt`; version history is in `CHANGELOG.md`.

## License

GPLv2 or later. See [LICENSE](LICENSE).

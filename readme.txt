=== FastPix Video ===
Contributors: fastpix
Tags: video, video hosting, streaming, lms, analytics
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Upload, embed, protect and measure video from your WordPress dashboard — powered by FastPix. Includes live streaming and LMS course tracking.

== Description ==

FastPix Video connects your WordPress site to [FastPix](https://fastpix.com), so you can upload, manage, embed, protect and measure video without leaving the dashboard. Visitors stream straight from the FastPix network. A FastPix account is required.

= Features =

* **Upload & migrate** — resumable browser uploads, import from a public URL, and a tool that moves your existing Media Library videos to FastPix (originals are kept).
* **Video library** — search titles, transcripts and chapters; filters, bulk actions, subtitles, and on-demand AI chapters, summaries and entities.
* **Embed anywhere** — a Gutenberg block and a `[fastpix id="…"]` shortcode. Public videos can emit VideoObject structured data for search.
* **Protected playback** — public, private (signed) and DRM video, with access tied to the posts that embed it.
* **Analytics** — views, watch time, quality of experience and errors in the dashboard, plus consent-gated watch progress with resume.
* **Course features (optional)** — for LearnDash, Tutor LMS, LifterLMS and LearnPress: skip-proof lesson completion, automatic lesson credit and per-course analytics.
* **Live streaming** — create RTMPS or SRT streams; one embed shows a waiting card, the live player, then the recording.

Great for online courses, membership sites, marketing pages, publishers, agencies and live events.

= External services =

This plugin talks to FastPix, a third-party video platform. It does not work without it.

* **FastPix API** (`api.fastpix.com`) — the plugin calls it to upload and manage video, read stream state, generate subtitles/AI output, and read analytics. Requests authenticate with the access token pair you enter on the connection screen.
* **FastPix delivery network** (`stream.fastpix.com`, `images.fastpix.com`, and other `*.fastpix.com` delivery hosts such as `cdn.fastpix.com`) — visitors' browsers fetch video, posters and thumbnails from it when a page with an embed loads.
* **FastPix live ingest** (`live.fastpix.com`) — your encoder (for example OBS) sends live video there; the plugin only displays the address.
* **FastPix data collection** (`<your workspace key>.anlytix.io`) — the embedded player reports playback quality events (views, watch time, buffering, errors) to FastPix, keyed to your workspace. These power the analytics screens. When the visitor's consent platform refuses statistics (see Privacy), the player is started with this reporting switched off.
* **Google Cast** (`www.gstatic.com`) — in browsers other than Safari the embedded player loads Google's Cast sender library from Google so viewers can cast the video to a TV. Google does not allow this library to be bundled. [Google Terms of Service](https://policies.google.com/terms) · [Google Privacy Policy](https://policies.google.com/privacy)

**Data sent to FastPix:** your API credentials (for authentication); video files or source URLs you upload; video titles and metadata you edit; subtitle files you provide; playback quality events from viewers' browsers (no names — viewers are identified only by the player's anonymous ids). Watch progress and per-learner course data are stored **only in your WordPress database** and are never sent to FastPix.

Service terms: [FastPix Terms](https://fastpix.com/terms-and-conditions) · [FastPix Privacy Policy](https://fastpix.com/privacy-policy)

= Privacy =

* Watch progress, and the player's playback-quality reporting to FastPix, run only when the visitor's consent platform allows statistics (WP Consent API, Cookiebot, OneTrust, CookieYes are recognised; a site can also decide with `window.fastpixConsent`). With no consent platform on the site, nothing is refused.
* Per-learner course data is opt-in and stored hashed (never names), pruned on a retention schedule you control, and covered by the WordPress personal-data export and erasure tools. Site administrators see the display name in the roster row (a view-only reveal, never shown to lower roles or stored); the `fastpix_reveal_learner_names` filter disables it.

= Bundled libraries =

* [Action Scheduler](https://actionscheduler.org/) — GPLv3, by Automattic. Runs the plugin's background jobs.
* [FastPix Player](https://www.npmjs.com/package/@fastpix/fp-player) 1.0.21 (`assets/vendor/fastpix-player.js`) — MIT, by FastPix, Inc. Human-readable source: [github.com/FastPix/web-player-component](https://github.com/FastPix/web-player-component).
* [FastPix Resumable Uploads](https://www.npmjs.com/package/@fastpix/resumable-uploads) 1.0.6 (`assets/vendor/fastpix-resumable-uploads.js`) — MIT, by FastPix, Inc. Human-readable source: [github.com/FastPix/web-uploads-sdk](https://github.com/FastPix/web-uploads-sdk).
* [hls.js](https://github.com/video-dev/hls.js) 1.7.3 (`assets/vendor/hls.min.js`) — Apache-2.0, by the video-dev team. Plays HLS video in browsers without native support. Human-readable source: [github.com/video-dev/hls.js/tree/v1.7.3](https://github.com/video-dev/hls.js/tree/v1.7.3).

The files above are the packages' published builds (minified); the linked repositories hold the source they are built from. Apart from Google Cast (see External services), no code is loaded from remote servers — everything the plugin runs ships in this package.

== Installation ==

**Requirements:** WordPress 6.8+, PHP 8.3+, HTTPS, MySQL 8.0+ or MariaDB 10.11+ (InnoDB), and a FastPix account.

1. In **Plugins → Add New**, search for "FastPix Video", then **Install Now** and **Activate** (or upload the ZIP via **Plugins → Add New → Upload Plugin**).
2. In the FastPix dashboard, go to **Manage → Access Tokens** and create a token with Video (read + write), Data (read) and System permissions. Copy the access token ID, the secret key and your workspace key.
3. The setup wizard opens after activation (reopen it any time from **FastPix → Connection**). Paste the token ID and secret, click **Connect**, then save your workspace key.
4. Optional: turn on instant updates. Copy the webhook URL from **FastPix → Settings**, add it in the FastPix dashboard under **Org Settings → Webhooks**, paste the signing secret back into WordPress, and click **Save & verify**.
5. Add your first video from **FastPix → Add media**, or migrate existing Media Library videos from **FastPix → Videos**.

Full guide: [FastPix WordPress documentation](https://fastpix.com/docs/integrations/wordpress).

== Frequently Asked Questions ==

= Do I need a FastPix account? =
Yes. The plugin is a client for the FastPix platform; hosting, streaming and analytics run there.

= Why won't the plugin activate? =
Activation runs environment checks and the notice names the one that failed: WordPress or PHP version, HTTPS, a reachable REST API, outbound connectivity to FastPix, or a conflicting video plugin. Fix it and activate again, or run `wp fastpix doctor`.

= Do I have to set up webhooks? =
No, but it's recommended. With webhooks, new videos and status changes appear instantly; without them the plugin checks FastPix every few minutes.

= Can authors upload video without seeing the API credentials? =
Yes. Only roles with the settings capability can view or change credentials. Authors and editors can still upload and manage video.

= What happens if I deactivate the plugin? =
Nothing is deleted and no post is edited. Migrated videos fall back to their local files, public videos show their saved poster and a link, and private/DRM videos show a short message. Shortcodes show "This video is not available right now." via a small must-use file (`wp-content/mu-plugins/fastpix-shortcode-fallback.php`) that is removed when the plugin is deleted.

= What happens if I uninstall it? =
Local data is kept for a reinstall unless you tick "Delete plugin data on uninstall" in Settings first. Video on the FastPix platform is never touched.

= Does it work on WordPress Multisite? =
Yes, per site. Activate it on each site individually; network-wide activation is refused because each site keeps its own connection, library and capabilities.

== Changelog ==

= 2.0.0 =
**Features**

* Connection wizard: access token pair, workspace key, and optional webhooks with a signing secret and self-test.
* Resumable browser uploads, import from a public URL, and a tool that migrates Media Library videos to FastPix (originals are kept).
* Video library with search across titles, transcripts and chapters, filters, bulk actions, subtitle management, and on-demand AI chapters, summaries and entities.
* Gutenberg block and `[fastpix id="…"]` shortcode, with a poster-and-link fallback saved in the post.
* Public, private (signed) and DRM playback, with access tied to the posts that embed each video.
* Analytics: views, viewers, watch time, quality of experience and playback errors, with CSV export.
* Consent-gated watch progress with resume (WP Consent API, Cookiebot, OneTrust and CookieYes supported).
* Optional course features for LearnDash, Tutor LMS, LifterLMS and LearnPress: skip-proof lesson completion, automatic lesson credit and per-course analytics.
* Live streaming (RTMPS/SRT): one embed shows a waiting card, the live player, then the recording.
* Personal-data export and erasure, an opt-in to remove local data on uninstall, and a system report for support.

**Notes**

* Complete rewrite of the plugin. Requires WordPress 6.8 and PHP 8.3 or later.
* Existing v1 credentials and shortcodes keep working. The plugin migrates its registry on first run and does not edit posts.
* Site-wide player defaults from v1 are removed; player options are now set per embed.
* Each connection is scoped to its FastPix workspace; switching credentials no longer shows another workspace's media or analytics.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
Major rebuild. Existing v1 credentials and shortcodes keep working; the plugin migrates its registry on first run and edits no posts.

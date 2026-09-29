=== FastPix - Video Hosting, Player and Analytics for WordPress ===
Contributors: fastpix
Tags: video, video hosting, video player, learndash, video analytics
Requires at least: 6.8
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Video hosting, player and analytics for WordPress. Host, protect and measure video from wp-admin. Requires a FastPix account.

== Description ==

Host and manage video content directly in WordPress with FastPix. Upload and play videos, add AI-powered captions and chapters, protect content with DRM, and track video and course performance through analytics in wp-admin.

Your videos are stored, encoded to adaptive-bitrate quality and streamed from the FastPix network, so your site stays fast and your content stays free of YouTube ads and outside branding. A FastPix account is required.

= Who it's for =

* **Online courses** : Upload your videos to the media library, then use them to build the lessons for specific courses. A lesson is marked complete only when the video is actually watched, not when a student drags the progress bar to the end. Works with LearnDash, Tutor LMS, LifterLMS and LearnPress, with per-course analytics.
* **Live streaming** : Broadcast over RTMPS or SRT straight from WordPress. One embed shows a waiting card before the stream, switches to the live player, then serves the recording automatically.
* **Membership and paid content** : Sell or gate video with private, signed playback and DRM, so it only plays where you allow and cannot be shared or downloaded.
* **Publishers and media libraries** : Move a large, uncaptioned library to FastPix, auto-generate subtitles, and search across titles, transcripts and chapters.
* **Marketing and landing pages** : Replace slow self-hosted files with fast adaptive-bitrate video that will not drag down your page speed.
* **Multi-tenant platforms** : Keep separate video libraries that never collide. A course platform split into divisions - say Architecture and E-commerce, each with its own sections - can run every division as its own site on WordPress Multisite, with its own library, courses and analytics, isolated from the rest.

= Features =

* Video hosting with resumable uploads, URL import and Media Library migration
* Adaptive-bitrate (HLS) streaming from a global multi-CDN network
* Live streaming over RTMPS or SRT, with automatic recording to your library
* Gutenberg video block and a `[fastpix id="…"]` shortcode
* Public, private (signed) and DRM-protected playback
* Watermark your videos to brand them and deter re-sharing
* AI captions and subtitles, chapters, summaries and named entities
* Transcript and chapter search across your whole library
* Analytics in wp-admin - per student, per video and per course - with CSV export
* Course completion tracking for LearnDash, Tutor LMS, LifterLMS and LearnPress
* Multi-tenant isolation on WordPress Multisite - a separate library, courses and analytics per site
* VideoObject structured data for public videos (video SEO)

= Why FastPix? =

* **Everything in wp-admin** - upload, embed, protect and measure video without leaving WordPress or juggling separate tools.
* **Your site stays fast** - video is delivered from the FastPix network, not your web host, so pages load quickly and playback does not stutter.
* **Open and yours** - the plugin is open source (GPLv2), and your videos, embeds and viewer data stay in your own WordPress site.

= For developers =

The plugin is built to be extended. Here is what you get and how to use it.

**Shortcode** - use it to place a video anywhere the block editor doesn't reach: classic editor, a page builder, a widget, or a theme template (via `do_shortcode()`). The only required attribute is the media ID; everything else tunes the player.

`[fastpix id="abc123" autoplay muted accentcolour="#0055FF" starttime="30"]`

Common attributes: `autoplay`, `muted`, `loop`, `controls`, `chapters`, `transcript`, `captionsdefault`, `lazyload`, `accentcolour`, `poster`, `starttime`, `aspectratio`. On lesson videos, `complete_at` sets the watched-percentage that counts as complete and `track_viewer` records who watched. For a live stream, use `[fastpix streamid="…"]`.

**LMS completion hook** - use `fastpix_video_completed` to run your own logic the moment a student finishes a lesson video (award points, send an email, unlock the next module). It fires once, before the LMS credits the lesson:

`add_action('fastpix_video_completed', function ($video_id, $user_id, $post_id) { /* your code */ }, 10, 3);`

To stop the plugin crediting the lesson automatically and do it yourself, return false from `fastpix_lms_auto_complete`:

`add_filter('fastpix_lms_auto_complete', '__return_false');`

**Webhooks** - FastPix notifies your site through signed webhooks the moment a video finishes processing, a subtitle track is ready, or a live stream changes state, so your library stays in sync without polling. Set the webhook URL and signing secret during setup (see Installation), and every event is verified with an HMAC-SHA256 signature.

**Other hooks** - filters let you change behaviour without touching the plugin: `fastpix_feature_live` (turn live streaming on), `fastpix_completion_threshold` (default watched-percentage for completion), `fastpix_lesson_post_types` (treat custom post types as lessons), `fastpix_structured_data` (edit the VideoObject JSON-LD before output). Actions let you react to events: `fastpix_media_ready` (a video finished processing), `fastpix_connected` (a workspace was connected). Example - turn live streaming on:

`add_filter('fastpix_feature_live', '__return_true');`

**WP-CLI** - manage large libraries and automate maintenance from the terminal or a cron job: `wp fastpix status` (check the connection, schema and queue), `wp fastpix sync` (pull in new videos now), `wp fastpix reindex` (rebuild the transcript search index), `wp fastpix doctor` (re-run the activation checks when something looks wrong), and more.

Full reference for every setting, capability, hook and command: https://fastpix.com/docs/integrations/wordpress-settings-and-reference

= Learn more =

New to hosting video on WordPress? Start with our guide to the [best video hosting for WordPress in 2026](https://fastpix.com/blog/best-video-hosting-for-wordpress), see [how to add video to WordPress without YouTube](https://fastpix.com/blog/add-video-to-wordpress-without-youtube), and compare the field in [the best WordPress video player plugins in 2026](https://fastpix.com/blog/best-wordpress-video-player-plugins).

If you sell or gate content, read how [protecting paid video on WordPress with signed URLs and DRM](https://fastpix.com/blog/protect-video-wordpress) works, and if you run an LMS, see [video hosting for LearnDash, Tutor LMS, LifterLMS and LearnPress](https://fastpix.com/blog/learndash-tutorlms-lifterlms-video-hosting). Going live is covered in [live streaming from WordPress with RTMPS and SRT](https://fastpix.com/blog/wordpress-live-streaming).

Already have a library? Learn about [moving a WordPress Media Library off your server](https://fastpix.com/blog/wordpress-offload-media), keeping [hero and background video from wrecking your LCP](https://fastpix.com/blog/wordpress-background-video-lcp), and what [video analytics in WordPress](https://fastpix.com/blog/wordpress-video-analytics) can tell you that a simple view counter cannot.

== Installation ==

= Requirements =

* WordPress 6.8 or later
* PHP 8.3 or later
* A site served over HTTPS
* MySQL 5.7 / MariaDB 10.3 or newer with InnoDB and FULLTEXT support (for transcript search)
* A FastPix account - create one free at https://dashboard.fastpix.io/signup

= 1. Install the plugin =

From the WordPress plugin directory (recommended):

1. In your WordPress admin, go to **Plugins > Add New**.
2. Search for **FastPix**.
3. Click **Install Now**, then **Activate**.

From a ZIP file:

1. Download the plugin ZIP from the WordPress plugin directory.
2. Go to **Plugins > Add New > Upload Plugin**, choose the file, click **Install Now**, then **Activate**.

On activation the plugin runs its environment checks and opens the setup wizard. If a check fails, activation is refused and the notice names the requirement. You can re-run the checks any time with `wp fastpix doctor`.

= 2. Get your FastPix credentials =

1. Sign in to the FastPix dashboard.
2. Go to **Manage > Access Tokens** and generate a token. Copy the **Access token ID** and **Secret key**. The token needs **Video: Read + Write**, **Data: Read**, and **System** permissions.
3. Copy your **Workspace key** from the workspace settings.

= 3. Connect the plugin =

1. In the setup wizard (**FastPix > Connection**), paste the **Access token ID** and **Secret key** and click **Connect**.
2. Paste your **Workspace key** and click **Save workspace**.
3. Optional but recommended: set up webhooks. Copy the webhook URL from **FastPix > Settings**, add it under **Org Settings > Webhooks** in the FastPix dashboard, paste the signing secret back into WordPress and click **Save & verify**. Without webhooks, the plugin polls FastPix every few minutes.

When the Connection screen shows **Connected**, the full FastPix menu (Videos, Add media, Analytics, Settings) appears. Go to **FastPix > Add media** to upload your first video, then embed it with the block or the `[fastpix id="…"]` shortcode.

== Frequently Asked Questions ==

= What is the best way to host video on WordPress? =

Hosting large video files on your own web server slows the site and has no adaptive streaming. A video hosting plugin like FastPix uploads your video to a dedicated video platform, encodes it to adaptive-bitrate HLS, and streams it from a multi-CDN network, while you manage everything from the WordPress dashboard.

= Do I need a FastPix account? =

Yes. The plugin is free and open source (GPLv2 or later), and it connects WordPress to your FastPix account, which hosts, encodes and delivers your video. Create one at https://dashboard.fastpix.io/signup.

= How do I embed a video in WordPress? =

Add the **FastPix Video** Gutenberg block and pick a video from your library, or paste the `[fastpix id="…"]` shortcode into any post or page. Both render at view time and share the same player options.

= Can I add private or password-protected video to WordPress? =

Yes. Videos can be public, private (short-lived signed tokens), or DRM-protected. Access is tied to the posts that embed the video, so a private video only plays on a page the visitor is allowed to read.

= Does it support adaptive-bitrate streaming? =

Yes. FastPix encodes every upload to adaptive-bitrate HLS, so playback adjusts to each viewer's connection instead of serving one fixed file.

= Does it help with video SEO? =

Public videos emit VideoObject structured data (JSON-LD) automatically, which makes them eligible for video rich results in Google search. Structured data is never emitted for private or DRM video.

= Does it work with LearnDash and other LMS plugins? =

Yes - LearnDash, Tutor LMS, LifterLMS and LearnPress. A lesson is only marked complete when the student actually watches the video (skipping to the end does not count), lesson credit is automatic, and the Analytics screen shows per-course student progress.

= Can I live stream on WordPress? =

Yes. Create an RTMPS or SRT stream, embed it with `[fastpix streamid="…"]`, and one embed handles the waiting card, the live player and the recording.

= What video formats and file size can I upload? =

Common video formats (MP4, MOV, AVI, MKV, WMV, WebM, and more) and audio (MP3, WAV, AAC, M4A, OGG), up to 8 hours per file. Uploads are resumable in 5 MB chunks.

= What happens to my videos if I deactivate or uninstall the plugin? =

Deactivating deletes nothing: published embeds fall back to a saved poster and link, and reactivating restores them. Uninstalling keeps local data by default; video stored on FastPix is never deleted by the plugin.

= Does it work on WordPress Multisite? =

Yes, per site. Activate it on each site individually; each site keeps its own connection, library and capabilities.

== Screenshots ==

1. Welcome screen - what FastPix does and exactly what data it receives.
2. Connect your FastPix account with an access token and secret key.
3. Link your workspace and turn on instant updates through webhooks.
4. Upload settings - privacy, quality, subtitles, domain lock and watermark for each video.
5. The video library - search titles, transcripts and chapters, with shortcodes and privacy at a glance.
6. The FastPix Video block in the editor - pick a video and set autoplay, resume and player controls per embed.
7. Live streaming - connect OBS or any RTMPS/SRT encoder; the recording lands in your library automatically.
8. Video analytics - views, watch time, quality of experience and playback errors.

== Changelog ==

= 2.0.0 =
* Video hosting on WordPress with resumable uploads, URL import, and Media Library migration (originals kept).
* Gutenberg video block and `[fastpix id="…"]` shortcode with a shared renderer and per-embed options.
* Public, private (signed) and DRM playback, with access tied to the embedding posts.
* VideoObject structured data for public videos (video SEO).
* Video analytics from a local daily rollup: views, watch time, quality of experience and errors, with CSV export.
* Course features for LearnDash, Tutor LMS, LifterLMS and LearnPress: completion that only counts a genuinely watched video, automatic lesson credit, per-course analytics.
* Live streaming over RTMPS or SRT, with automatic recording.
* Signed webhooks, WP-CLI commands, and developer hooks. See https://fastpix.com/docs/integrations/wordpress-settings-and-reference.

== Upgrade Notice ==

= 2.0.0 =
Video hosting, adaptive-bitrate streaming, private and DRM playback, LMS completion tracking, live streaming and analytics for WordPress. Requires a free FastPix account.
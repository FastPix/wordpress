# Changelog

All notable changes to the FastPix WordPress plugin. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow the plugin header and `readme.txt`.

## [2.0.0] - 2026-09-13

Complete rebuild of the plugin.

### Added
- Connection wizard: access token pair, workspace key, optional instant updates through webhooks with a signing secret and a self-test.
- Resumable browser uploads, ingestion from a public URL, and a migration tool that moves Media Library videos to FastPix while keeping the originals.
- Video library with search across titles, transcripts and chapters, filters, bulk actions, subtitle management and on-demand AI enrichment (chapters, summaries, named entities).
- Gutenberg block and `[fastpix id="…"]` shortcode with a static poster-and-link fallback saved in the post, so pages degrade gracefully if the plugin is deactivated.
- Public, private (signed token) and DRM playback with access checks tied to the posts that embed each video.
- Analytics: daily local rollups of views, people, watch time, quality of experience scores and playback errors; per-video coverage; CSV export as a background job.
- Consent-gated watch progress with resume and once-only completion events (WP Consent API, Cookiebot, OneTrust and CookieYes recognised).
- Optional course features for LearnDash, TutorLMS, LifterLMS and LearnPress: skip-proof completion on lesson videos, automatic lesson credit, per-course student analytics with hashed identities and a retention schedule.
- Live streaming behind a feature flag: create streams, encoder credentials, one embed that waits, goes live and then serves the recording.
- Personal-data export and erasure integration, an uninstall opt-in that removes local data, and a system report for support.

### Changed
- Admin screens rebuilt to the FastPix-V3 design: onboarding, videos, add media, analytics and settings, responsive down to phone widths.
- Every REST route now registers through one gate with a capability or a public rate-limited bucket.
- The API client is the only component that talks to FastPix; it carries retries, backoff and a circuit breaker.
- Each connection is scoped to its FastPix workspace; connecting a different pair no longer shows the previous workspace's media, streams or analytics.

### Removed
- The v1 rendering stack and its site-wide player defaults; player options are now per embed.

## [1.0.0]

- Initial release: shortcode embedding of FastPix videos.

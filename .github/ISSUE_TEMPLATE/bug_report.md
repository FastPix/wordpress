---
name: Bug Report
about: Report a bug in the FastPix Video WordPress plugin
title: "[BUG] "
labels: bug
assignees: ""
---

# Bug Report

Thank you for reporting a bug in the FastPix Video WordPress plugin.

Complete the sections below to help us reproduce and investigate the issue.

> **Important**
>
> Never include secrets. Redact the FastPix access token pair, the webhook signing secret, signed playback tokens, stream keys, SRT passphrases, and database credentials.

## Description

A clear and concise description of the bug.

<!-- Example: activation is refused, the connection wizard fails its self-test, an upload stalls,
     a private video won't play, a webhook returns 401, or analytics stay empty -->

## Severity

How severely does this issue affect your site?

- [ ] Blocks production
- [ ] High
- [ ] Medium
- [ ] Low

## Which part is affected?

- [ ] Activation / environment checks
- [ ] Connection wizard (access token pair, workspace key)
- [ ] Webhooks (`/wp-json/fastpix/v1/webhook`, signature verification, self-test)
- [ ] Uploads (resumable browser upload, import from URL)
- [ ] Media Library migration
- [ ] Video library (search, filters, bulk actions)
- [ ] Subtitles / AI enrichment (chapters, summaries, entities)
- [ ] Gutenberg block (`fastpix/video`)
- [ ] Shortcode (`[fastpix id="…"]`)
- [ ] Playback — public / private (signed) / DRM
- [ ] Analytics (rollups, dashboards, CSV export)
- [ ] Watch progress / resume / consent
- [ ] LMS course features (LearnDash, Tutor LMS, LifterLMS, LearnPress)
- [ ] Live streaming (RTMPS / SRT, waiting card, recording)
- [ ] Background jobs (Action Scheduler, sync sweeps)
- [ ] WP-CLI (`wp fastpix …`)
- [ ] Privacy export / erasure, uninstall
- [ ] Deactivation fallback (poster-and-link, mu-plugin shortcode fallback)
- [ ] Other (describe):

## Steps to Reproduce

1. …
2. …
3. …

## Expected Behavior

What you expected to happen.

## Actual Behavior

What actually happened.

## Embed markup (if relevant)

<!-- The shortcode or block markup from the post (Code editor view). Redact nothing here unless it
     contains a token. -->

```html

```

## Support report

<!-- FastPix → Settings → "Copy support report", or the output of `wp fastpix doctor` and
     `wp fastpix status`. The report never contains credentials, but check before pasting. -->

```

```

## Logs or error messages

<!-- PHP errors from debug.log (WP_DEBUG_LOG), browser console errors, the failing REST response,
     or failed actions from Tools → Scheduled Actions (groups starting with "fastpix-"). -->

```

```

## Environment

- FastPix Video plugin version:
- WordPress version:
- PHP version:
- Multisite: [ ] no [ ] yes
- Web server: [ ] Apache [ ] Nginx [ ] LiteSpeed [ ] Other:
- Hosting provider:
- Active theme:
- Caching / optimization plugins (e.g. WP Rocket, LiteSpeed Cache, Cloudflare):
- Consent plugin (if any):
- LMS plugin and version (if relevant):
- Browser and version (for playback or admin UI issues):
- Webhooks configured: [ ] yes [ ] no

## Additional Context

Anything else that helps (screenshots, the affected FastPix media id, timing, recent changes to the site).

## Checklist

- [ ] Redacted all secrets and tokens
- [ ] Included the plugin, WordPress and PHP versions
- [ ] Provided steps to reproduce
- [ ] Ran `wp fastpix doctor` or checked Tools → Site Health
- [ ] Tested with other plugins disabled and a default theme (if possible)
- [ ] Checked this isn't already reported

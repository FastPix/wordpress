# Pull Request

## Type of Change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that changes existing behavior)
- [ ] Documentation update
- [ ] Refactor / internal change (no behavior change)
- [ ] Chore (build, CI, dependencies)

## Area(s) Affected

- [ ] Plugin bootstrap / activation / deactivation (`fastpix-io.php`, `class-fastpix-activation.php`, `class-fastpix-deactivate.php`)
- [ ] API client (`class-fastpix-api-client.php`)
- [ ] Connection / credentials (`class-fastpix-connection.php`, `class-fastpix-credentials.php`)
- [ ] REST routes (`class-fastpix-rest*.php`, `class-fastpix-videos-rest.php`)
- [ ] Webhooks / sync (`class-fastpix-webhooks*.php`, `class-fastpix-sync*.php`)
- [ ] Uploads / migration (`class-fastpix-uploads*.php`, `class-fastpix-migration*.php`)
- [ ] Rendering — block, shortcode, player, live (`class-fastpix-render*.php`, `blocks/video/`)
- [ ] Playback signing / DRM / access (`class-fastpix-signing.php`)
- [ ] Analytics / progress (`class-fastpix-analytics*.php`, `class-fastpix-progress.php`)
- [ ] LMS integration (`class-fastpix-lms*.php`)
- [ ] Live streaming (`class-fastpix-live*.php`)
- [ ] Background jobs (`class-fastpix-jobs.php`, Action Scheduler)
- [ ] Database schema (`class-fastpix-schema.php`)
- [ ] Admin screens (`templates/`, `assets/css`, `assets/js`)
- [ ] WP-CLI (`class-fastpix-cli.php`)
- [ ] Privacy / uninstall (`class-fastpix-lms-privacy.php`, `uninstall.php`)
- [ ] Packaging (`.distignore`, `vendor/`, `readme.txt` headers)
- [ ] Docs (`readme.txt` / `README.md` / `CHANGELOG.md`)
- [ ] Other (describe):

## Summary

Describe the change and explain why it was made.

<!-- Link related issues if applicable (for example, Closes #123). -->

## Breaking Changes (if applicable)

Describe any breaking changes and any migration steps required for existing sites.

<!-- Changing shortcode attributes, block attributes or saved markup, option names, table schema,
     REST route paths, capabilities, or public hooks breaks sites that already use the plugin.
     Say what they need to do, and whether an upgrade routine handles it. -->

## Code Example (Optional)

If this PR changes embedding, WP-CLI or public hooks, include an example showing the new behavior.

```
[fastpix id="…"]
```

```bash
wp fastpix status
wp fastpix doctor
wp fastpix sync --deep
```

## Testing

- [ ] `php -l` passes on changed PHP files
- [ ] `node --check` passes on changed JavaScript files
- [ ] Relevant self-checks pass (`php -d zend.assertions=1 -d assert.exception=1 tests/test-<area>.php`)
- [ ] Added / updated a self-check under `tests/` for new server-side behavior
- [ ] Tested on the minimum supported versions (WordPress 6.8, PHP 8.3)
- [ ] Tested the block and shortcode on the front end, including with the plugin deactivated
- [ ] `wp plugin check` passes on the distribution build

## Testing Notes (Optional)

Provide any additional testing details, screenshots, logs, or reproduction steps that help reviewers verify the change.

## Review Checklist

- [ ] The change is accurate and matches the description in this pull request.
- [ ] No secrets are logged or returned to the browser (access tokens, signing secret, stream keys, playback tokens), and none appear in the support report
- [ ] All input is sanitized and all output escaped; admin actions check a nonce and a capability
- [ ] New REST routes register only through `Fastpix_Rest::register()` with a capability or a rate-limited public bucket
- [ ] Only `Fastpix_Api_Client` talks HTTP to FastPix
- [ ] Database changes go through `class-fastpix-schema.php` and are safe to run repeatedly on existing sites
- [ ] JavaScript stays build-free; template variables are prefixed `$fastpix_`; user-facing strings use the `fastpix` text domain
- [ ] Viewer or learner data stays in the WordPress database and remains covered by privacy export / erasure
- [ ] Nothing new ships that `.distignore` should exclude
- [ ] Documentation updated (`readme.txt`, `README.md` or `CHANGELOG.md`) if behavior changed
- [ ] Version bumped in the plugin header and `readme.txt` `Stable tag` if this should be released

---

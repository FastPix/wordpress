# Security Policy

## Supported Versions

Security fixes are released for the latest version of the FastPix Video WordPress plugin only.

| Version | Supported |
| ------- | --------- |
| 2.x     | Yes       |
| 1.x     | No        |

Keep the plugin updated to receive fixes.

## Reporting a Vulnerability

**Do not report security vulnerabilities through public GitHub issues, pull requests or discussions.**

Email **security@fastpix.io** with:

- A description of the vulnerability and its impact
- The affected plugin version, WordPress version and PHP version
- Steps to reproduce, or a proof of concept
- Which user role is needed to exploit it (unauthenticated visitor, subscriber, author, editor, administrator)
- Any suggested fix

Never include real credentials. Redact access tokens, webhook signing secrets, signed playback tokens and stream keys.

We will acknowledge your report, keep you updated while we investigate, and credit you in the release notes unless you prefer to stay anonymous. Please give us reasonable time to release a fix before disclosing the issue publicly.

## Scope

In scope: code in this repository, including:

- REST routes under `/wp-json/fastpix/v1/`, including the webhook receiver
- Access checks for private (signed) and DRM playback
- Storage of the FastPix access token pair and webhook signing secret
- Admin screens, capabilities, and nonce and input handling
- Uploads, URL import and Media Library migration
- Watch progress, LMS learner data, and personal-data export and erasure

Out of scope:

- The FastPix platform itself (API, delivery network, player). Report those to the same address and see the [FastPix security policy](https://www.fastpix.io/fastpix-security).
- Vulnerabilities in WordPress core, themes or other plugins
- Issues that require an attacker to already have administrator access or server access
- Missing security headers or findings from automated scanners without a demonstrated impact

# Changelog

All notable changes to `particle-academy/fancy-git-gitlab` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0:** breaking changes may land in MINOR releases.

## [Unreleased]

### Changed

- **The tag workflow is now `.github/workflows/publish.yml`, named `Publish`**
  (it was `release-gate.yml`, "Release gate"). Every Particle-Academy package
  publishes from that file under that name. What it does is unchanged: on a
  `v*` tag it checks that `CHANGELOG.md` has an entry for the version and that
  the entry is the newest one. Packagist syncs from the tag and never reads the
  workflow file, so nothing about how a release reaches Composer changes.

  **What you must do:** nothing. Only a script that looks runs up by the old
  file (`gh run list --workflow=release-gate.yml`) needs `publish.yml` instead.

## [0.3.0] — 2026-09-13

### Fixed

- **Every GitLab call now works. In 0.1.0–0.2.0 none of them did.**
  `repository()`, `listReviews()`, `getReview()`, `createReview()`, `compare()`
  and `checks()` all threw `Error: Call to undefined method Gitlab\Client::api()`:
  the adapter was written against php-gitlab-api's old `api('projects')`
  accessor, which the `^12.1` it required does not have. Only `kind()` and
  `identify()` ever ran. Had the accessor existed, two more defects were behind
  it: the project path was encoded twice (`group%252Fapp`, a 404 on GitLab), and
  a self-managed base URL's path was dropped, so an instance under
  `https://example.com/gitlab` was addressed at the host root.

  **What you must do:** nothing, unless you worked around it.

### Changed

- **BREAKING — the constructor takes a `FancyGit\GitLab\GitLabClient`, not a
  `Gitlab\Client`.** `m4tthumphrey/php-gitlab-api` is gone (it fails the suite's
  92-day freshness bar), replaced by a small first-party REST v4 client over the
  Guzzle this package already required.

  **What you must do:** if you call `GitLabProvider::withToken($token, $baseUrl)`,
  nothing. If you called `new GitLabProvider($gitlabClient, $baseUrl)`, build the
  client instead: `new GitLabProvider(new GitLabClient($baseUrl, $token))` —
  and pass `TokenType::OAuth` or `TokenType::CiJob` as the third argument if you
  had authenticated that way, or your own Guzzle client as the fourth. Because no
  network method worked before, the only code this can break is code that used
  `identify()` on a hand-built client.

- **BREAKING — the base URL is validated.** It must be an `https` instance URL
  with no credentials, query, fragment or dot segments, and not the `/api/v4` URL.
  Anything else throws `GitException` (`invalid_argument`) at construction.

  **What you must do:** if you pass `http://…`, move the instance to https — over
  plain http the token is readable by anything on the path. If you pass
  `…/api/v4`, drop that suffix.

- **Failures are the contract's `FancyGit\Error\GitException`**, instead of
  php-gitlab-api's exceptions: 401/403 → `auth`, 404 → `not_found`, 405 →
  `unsupported`, 409 → `conflict`, 400/422 → `invalid_argument` (with GitLab's
  field messages), 429 → `rate_limited` with "Retry after N seconds" from
  `Retry-After`, anything else → `unknown`. `getCode()` is the HTTP status.
  **What you must do:** if you caught `Gitlab\Exception\*`, catch `GitException`
  — though as above, nothing could have reached those catches.

- `particle-academy/fancy-git` floor raised from `>=0.1` to `>=0.1.1 <2.0`:
  `GitErrorCode::InvalidArgument` does not exist in 0.1.0. **No action needed**
  unless you pinned fancy-git to exactly 0.1.0, which also lacks that release's
  security fix.

### Added

- `listReviews()` honours `cursor` (the page number) and returns `nextCursor`
  and `total` from GitLab's `X-Next-Page` / `X-Total`, as the contract allows.
  `limit` is kept within GitLab's 1–100.
- `checks()` reads every page of pipelines for the revision instead of the first
  twenty.
- Self-managed instances under a relative URL root. `identify()` strips the root
  from https remotes, so the owner is the namespace rather than `gitlab/group`.
- `GitLabClient::getAll()` walks offset and keyset pagination, and stops with an
  error past its page limit rather than returning a list that reads as complete.

### Security

- **Requests only go to the configured instance.** Redirects are never followed
  — Guzzle strips `Authorization` on a cross-origin redirect but would forward
  GitLab's `PRIVATE-TOKEN` — and this is set per request, so it holds for an
  injected client configured to follow them. A `Link` pagination URL is followed
  only if it points back inside the instance's `/api/v4/`.
- **A ref from another instance is refused before any request.** A ref whose
  `baseUrl` differs from the provider's would otherwise have sent this instance's
  token to whatever project has the same path here, and `createReview()` would
  have opened a merge request on it. Refs without a `baseUrl` are unaffected.
- **The token stays out of messages, dumps and storage.** It is held as a
  `SensitiveParameterValue`; `var_dump` / `print_r` show `[REDACTED]`; the client
  refuses to serialize; a blank token or one containing whitespace or CR/LF is
  rejected without being echoed; and Guzzle's exceptions, whose requests carry the
  header, are never chained.

### Removed

- `m4tthumphrey/php-gitlab-api` and `http-interop/http-factory-guzzle`, and with
  them eleven transitive packages (`php-http/*`, `psr/cache`,
  `symfony/options-resolver`, …). **No action needed.** The `php-http/discovery`
  plugin permission in `composer.json` went with them.

## [0.2.0] — 2026-08-07

### Changed

- **BREAKING — PHP 8.3 is no longer supported.** `require.php` moves from `^8.3` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.3, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.


### Changed

- Widened the `particle-academy/fancy-git` requirement from `^0.1.0` to `>=0.1 <2.0`, so a sibling
  minor release is an upgrade and not a resolver conflict. **No action needed** —
  widening a range only adds candidates; the version you have today still resolves.

  A caret on a `0.x` range locks the MINOR, so every one of these pinned a
  sibling at whatever it happened to be on the day it was written, and each
  sibling release then read as a conflict to Composer/npm rather than an
  upgrade. Nothing in this package was using an API the newer minors removed
  — the range was the whole problem.

## [0.1.1] - 2026-07-23

### Security

- Raised the `guzzlehttp/guzzle` floor from `^7.9` to `^7.15.1` and refreshed the
  lockfile to 7.15.1. This closes four Guzzle advisories that `^7.9` still
  allowed (Proxy-Authorization leaking to origin on cross-host redirect,
  host-only cookie scope not preserved, unbounded response cookies DoS, and URI
  fragments disclosed in redirect `Referer` headers).

  **Action:** run `composer update guzzlehttp/guzzle` to pull 7.15.1. The raised
  floor means `composer update` can no longer resolve a vulnerable Guzzle for
  consumers of this package.

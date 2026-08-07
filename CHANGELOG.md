# Changelog

All notable changes to `particle-academy/fancy-git-gitlab` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0:** breaking changes may land in MINOR releases.

## [Unreleased]

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

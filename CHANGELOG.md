# Changelog

All notable changes to `particle-academy/fancy-git-gitlab` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Pre-1.0:** breaking changes may land in MINOR releases.

## [Unreleased]

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

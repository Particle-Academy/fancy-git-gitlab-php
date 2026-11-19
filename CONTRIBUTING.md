# Contributing to particle-academy/fancy-git-gitlab

Notes for anyone, human or agent, working ON this package's code. How to USE the
package is in [AGENTS.md](./AGENTS.md); what it is, is in [README.md](./README.md).
`CLAUDE.md` is a symlink to this file, so Claude Code reads these notes when it
works in this repo.

## Layout

- `src/GitLabClient.php` — the small first-party GitLab REST v4 client (with
  `GitLabResponse` and the `TokenType` enum).
- `src/GitLabProvider.php` — the fancy-git provider over that client.
- `tests/` — the Pest suite, helpers in `tests/Support/`.

## Commands

```bash
composer install
composer test   # pest
```

## Parity

The Node twin is [`@particle-academy/fancy-git-gitlab`](https://github.com/Particle-Academy/fancy-git-gitlab-js),
which promises the same rules, messages and error codes. A change to either is a
change to both.

## Changelog

Update `CHANGELOG.md` (Keep a Changelog) in the same commit as the change.

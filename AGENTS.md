# Using particle-academy/fancy-git-gitlab — guide for agents

How an agent installs and uses this package in a consumer app. What the package
is, is in [README.md](./README.md). Notes for working ON this repo's code are in
[CONTRIBUTING.md](./CONTRIBUTING.md).

## Install

```bash
composer require particle-academy/fancy-git-gitlab
```

- Requires PHP `^8.4`, `particle-academy/fancy-git` (`>=0.1.1 <2.0`) and
  `guzzlehttp/guzzle` `^7.15.1`; Composer pulls them in.

## Find it on the Fancy MCP server

The catalog server is `https://ui.particle.academy/mcp` (streamable HTTP, no API
key; MCP id `fancy-ui`). This package is the registry entry
`fancy-git-gitlab-php`. Server-side packages are found with
`search-backend-packages` (stack `php`); `install-instructions` gives the exact
commands for the slug.

## API essentials

```php
use FancyGit\GitLab\GitLabProvider;
use FancyGit\Provider\ProviderRegistry;

$gitlab = GitLabProvider::withToken($token);                               // GitLab.com
$selfManaged = GitLabProvider::withToken($token, 'https://gitlab.example.com');
$providers = (new ProviderRegistry)->register($gitlab);
```

- For OAuth or CI job tokens, or your own Guzzle client, build the client:
  `new GitLabProvider(new GitLabClient($baseUrl, $token, TokenType::OAuth, $guzzle))`.
  `TokenType` cases: `AccessToken` (default), `OAuth`, `CiJob`.
- `GitLabProvider` implements fancy-git's `FancyGit\Provider\GitProvider`
  (`kind()` is `'gitlab'`): `identify`, `repository`, `listReviews`,
  `getReview`, `createReview`, `compare`, `checks`. It does not implement
  `IssueProvider`.
- Failures are `FancyGit\Error\GitException` with a `GitErrorCode` (`auth`,
  `not_found`, `conflict`, `invalid_argument`, `rate_limited`, ...); the HTTP
  status is the exception code.

## Traps

- **The base URL is the instance URL, not the `/api/v4` URL.** It must be
  `https`, with no credentials, query or fragment.
- **The base URL is trusted configuration.** Private and loopback addresses are
  allowed. If end users can supply it, check the host against your own allowlist
  first.
- Without a token, requests are anonymous.

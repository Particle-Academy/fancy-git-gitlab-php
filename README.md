# Fancy Git — GitLab adapter for PHP

[![Fancified](art/fancified.svg)](https://particle.academy)

GitLab.com and GitLab Self-Managed implementation of the Fancy Git provider
contract, over a small first-party GitLab REST v4 client.

```php
use FancyGit\GitLab\GitLabClient;
use FancyGit\GitLab\GitLabProvider;
use FancyGit\GitLab\TokenType;

// GitLab.com, or a self-managed instance by its https URL.
$gitlab = GitLabProvider::withToken($token);
$gitlab = GitLabProvider::withToken($token, 'https://gitlab.example.com');

// OAuth or CI job tokens, or your own Guzzle client (proxy, CA bundle, timeouts).
$gitlab = new GitLabProvider(new GitLabClient('https://gitlab.example.com', $token, TokenType::OAuth, $guzzle));
```

- The base URL is the **instance** URL (a relative URL root such as
  `https://example.com/gitlab` works); `/api/v4` is added for you. It must be
  `https`, with no credentials, query or fragment.
- The base URL is **trusted configuration**. Private and loopback addresses are
  allowed on purpose, because that is where most self-managed instances live — so
  if end users can supply it, check the host against your own allowlist first.
- Requests only ever go to that instance. Redirects are not followed, and a
  pagination link pointing anywhere else is refused, because every request
  carries the token.
- Failures are `FancyGit\Error\GitException` with a `GitErrorCode`
  (`auth`, `not_found`, `conflict`, `invalid_argument`, `rate_limited`, …); the
  HTTP status is the exception code, and a 429 message says when to retry.

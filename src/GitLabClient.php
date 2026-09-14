<?php

declare(strict_types=1);

namespace FancyGit\GitLab;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

/**
 * A small first-party client for GitLab's REST API v4 — the endpoints the
 * provider needs, over Guzzle, and nothing else.
 *
 * Three rules hold for every request, because every request carries a
 * credential:
 *
 * 1. **It only goes to the configured instance.** The base URL is validated
 *    once (https, no userinfo, no query, no dot segments) and every path is
 *    checked before it is appended, so neither a caller-supplied project name
 *    nor a server-supplied pagination link can point a token somewhere else.
 * 2. **Redirects are never followed.** Guzzle strips `Authorization` on a
 *    cross-origin redirect, but it does not know that GitLab's `PRIVATE-TOKEN`
 *    and `JOB-TOKEN` headers are credentials, and would forward them. This is
 *    set per request, so it holds even for an injected client configured to
 *    follow redirects.
 * 3. **The token never appears in a message.** It is held as a
 *    `SensitiveParameterValue`, errors are built from GitLab's response rather
 *    than from the request, and Guzzle's exceptions — whose request objects
 *    carry the headers — are never attached as a previous exception.
 */
final class GitLabClient
{
    public const GITLAB_COM = 'https://gitlab.com';

    private const API_ROOT = '/api/v4/';

    private const USER_AGENT = 'particle-academy/fancy-git-gitlab';

    /** GitLab's own ceiling; it silently serves 100 for anything larger. */
    public const MAX_PER_PAGE = 100;

    private readonly string $baseUrl;

    private readonly ?\SensitiveParameterValue $token;

    private readonly ClientInterface $http;

    /**
     * @param  string  $baseUrl  the INSTANCE URL (`https://gitlab.example.com`, or
     *                           `https://example.com/gitlab` under a relative URL
     *                           root) — not the `/api/v4` URL
     * @param  string|null  $token  null sends anonymous requests, which GitLab
     *                              answers for public projects only
     * @param  ClientInterface|null  $http  bring your own Guzzle client for proxies,
     *                                      a private CA bundle or timeouts
     */
    public function __construct(
        string $baseUrl = self::GITLAB_COM,
        #[\SensitiveParameter] ?string $token = null,
        private readonly TokenType $tokenType = TokenType::AccessToken,
        ?ClientInterface $http = null,
    ) {
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);

        // Visible ASCII only. GitLab tokens are all of that shape, and it rules
        // out both a blank env var — which would otherwise send anonymous
        // requests that a private project answers with a misleading 404 — and a
        // CR/LF that would inject a header.
        if ($token !== null && preg_match('/^[\x21-\x7E]+$/', $token) !== 1) {
            throw self::invalid('The GitLab token is blank or contains whitespace or control characters, which no GitLab token does. Nothing was sent.');
        }

        $this->token = $token === null ? null : new \SensitiveParameterValue($token);
        $this->http = $http ?? new Client(['connect_timeout' => 10, 'timeout' => 30]);
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Validate an instance URL and return its canonical form.
     *
     * @throws GitException invalid_argument — the URL is deliberately NOT echoed,
     *                      because a rejected URL may be one carrying a password
     */
    public static function normalizeBaseUrl(string $url): string
    {
        // Control characters, whitespace and backslashes first: parsers disagree
        // about all three, and a URL two parsers read differently is how a
        // request reaches a host other than the one that was checked.
        if ($url === '' || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw self::invalid('The GitLab base URL is empty or contains whitespace, control characters or a backslash.');
        }

        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || $parts['host'] === '') {
            throw self::invalid('The GitLab base URL must be an absolute https URL, such as https://gitlab.example.com.');
        }
        if (strtolower($parts['scheme']) !== 'https') {
            throw self::invalid('The GitLab base URL must use https. Over plain http the access token is readable by anything on the network path.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw self::invalid('The GitLab base URL must not contain credentials. Pass the token as the token argument.');
        }
        if (isset($parts['query']) || isset($parts['fragment']) || str_contains($url, '?') || str_contains($url, '#')) {
            throw self::invalid('The GitLab base URL must not contain a query string or fragment.');
        }

        $host = strtolower($parts['host']);
        $isHostname = preg_match('/^[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?(?:\.[a-z0-9_](?:[a-z0-9_-]*[a-z0-9_])?)*$/', $host) === 1;
        $isIpv6 = preg_match('/^\[([0-9a-f:.]+)\]$/', $host, $ip) === 1 && filter_var($ip[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (! $isHostname && ! $isIpv6) {
            throw self::invalid('The GitLab base URL does not have a valid host name.');
        }

        $path = rtrim($parts['path'] ?? '', '/');
        if ($path !== '') {
            // Plain segments only: no percent-encoding, no empty or dot segments.
            if (preg_match('#^(?:/[A-Za-z0-9._~-]+)+$#', $path) !== 1 || self::hasDotSegment(substr($path, 1))) {
                throw self::invalid('The GitLab base URL path may only be a relative URL root made of plain segments, such as /gitlab.');
            }
            if (preg_match('#/api/v\d+$#i', $path) === 1) {
                throw self::invalid('Pass the GitLab INSTANCE URL (https://gitlab.example.com), not its API URL; /api/v4 is added for you.');
            }
        }

        $port = $parts['port'] ?? null;

        return 'https://'.$host.($port !== null && $port !== 443 ? ':'.$port : '').$path;
    }

    /**
     * The API path of a project, with its full namespace encoded as ONE segment
     * (`projects/group%2Fsub%2Fapp`), which is how GitLab addresses a project by
     * path. Encode it exactly once: a second pass yields `%252F`, which GitLab
     * answers with a 404 indistinguishable from a permissions problem.
     */
    public static function projectPath(string $owner, string $name): string
    {
        if (trim($owner) === '' || trim($name) === '') {
            throw self::invalid('A GitLab project needs a non-empty owner (namespace) and name.');
        }

        return 'projects/'.rawurlencode($owner.'/'.$name);
    }

    /** @param  array<string, scalar|null>  $query */
    public function get(string $path, array $query = []): GitLabResponse
    {
        [$response, $data] = $this->exchange('GET', $this->url($path, $query));

        return new GitLabResponse(
            $response->getStatusCode(),
            $data,
            self::nextPageNumber($response),
            ctype_digit($total = trim($response->getHeaderLine('X-Total'))) ? (int) $total : null,
            'GET '.$path,
        );
    }

    /** @param  array<string, mixed>  $body  sent as JSON */
    public function post(string $path, array $body): GitLabResponse
    {
        [$response, $data] = $this->exchange('POST', $this->url($path), $body);

        return new GitLabResponse($response->getStatusCode(), $data, describe: 'POST '.$path);
    }

    /**
     * Every item of a paginated collection.
     *
     * Follows `X-Next-Page` (offset pagination), rebuilding the request against
     * this instance from the page number; otherwise a `Link: rel="next"` (keyset
     * pagination), which must point back inside this instance's API before it is
     * followed. Stops with an error past `$maxPages` rather than returning a
     * truncated list that reads as complete.
     *
     * @param  array<string, scalar|null>  $query  `per_page` defaults to 100
     * @return list<array<mixed>>
     */
    public function getAll(string $path, array $query = [], int $maxPages = 10): array
    {
        if ($maxPages < 1) {
            throw self::invalid('getAll needs a page limit of at least 1.');
        }

        $query += ['per_page' => self::MAX_PER_PAGE];
        $url = $this->url($path, $query);
        $items = [];

        for ($page = 1; ; $page++) {
            [$response, $data] = $this->exchange('GET', $url);
            array_push($items, ...(new GitLabResponse($response->getStatusCode(), $data, describe: 'GET '.$path))->items());

            if (($next = self::nextPageNumber($response)) !== null) {
                $nextUrl = $this->url($path, [...$query, 'page' => $next]);
            } elseif (($link = self::nextLink($response)) !== null) {
                $nextUrl = $this->ownLink($link, 'GET '.$path);
            } else {
                return $items;
            }

            if ($page >= $maxPages) {
                throw new GitException(GitErrorCode::Unknown, "GitLab has more than {$maxPages} pages of GET {$path}. Stopped there rather than return a list that reads as complete.");
            }

            $url = $nextUrl;
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'tokenType' => $this->tokenType, 'token' => $this->token === null ? null : '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new \LogicException('GitLabClient holds a credential and is never serialized. Build it where it is used, from configuration.');
    }

    /** @param  array<mixed>  $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('GitLabClient holds a credential and is never unserialized.');
    }

    // ---------------------------------------------------------------------

    /** @param  array<string, scalar|null>  $query */
    private function url(string $path, array $query = []): string
    {
        if (! self::isApiPath($path)) {
            throw self::invalid('A GitLab API path must be relative to /api/v4/, made of plain or percent-encoded segments, with no dot segments, query or fragment.');
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $this->baseUrl.self::API_ROOT.$path.($queryString === '' ? '' : '?'.$queryString);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array{0: ResponseInterface, 1: array<mixed>}
     */
    private function exchange(string $method, string $url, ?array $json = null): array
    {
        $what = $method.' '.$this->describe($url);
        $headers = ['Accept' => 'application/json', 'User-Agent' => self::USER_AGENT];
        $body = null;

        if ($json !== null) {
            try {
                $body = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } catch (\JsonException $e) {
                throw self::invalid("The body for {$what} cannot be encoded as JSON: {$e->getMessage()}.");
            }
            $headers['Content-Type'] = 'application/json';
        }

        if ($this->token !== null) {
            $headers += $this->tokenType->headers($this->token->getValue());
        }

        try {
            $response = $this->http->send(new Request($method, $url, $headers, $body), [
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            // Message only. The exception's request carries the credential header,
            // so it is not chained where a logger could serialize it.
            throw new GitException(GitErrorCode::Unknown, "GitLab request {$what} could not be completed: {$e->getMessage()}");
        }

        $status = $response->getStatusCode();

        if ($status >= 300 && $status < 400) {
            throw new GitException(GitErrorCode::Unknown, "GitLab answered {$what} with a {$status} redirect. It was not followed, because the request carries a credential and a redirect can lead anywhere. Set the base URL to the instance's canonical https address.", $status);
        }

        if ($status >= 400) {
            throw self::httpError($what, $response);
        }

        $raw = (string) $response->getBody();
        if (trim($raw) === '') {
            return [$response, []];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $type = $response->getHeaderLine('Content-Type') ?: 'none';

            throw new GitException(GitErrorCode::Unknown, "GitLab answered {$what} with a {$status} that is not JSON (Content-Type: {$type}). Check that the base URL is the GitLab instance itself, not a sign-in page or a proxy in front of it.", $status);
        }

        if (! is_array($data)) {
            throw new GitException(GitErrorCode::Unknown, "GitLab answered {$what} with JSON that is neither an object nor a list.", $status);
        }

        return [$response, $data];
    }

    private static function httpError(string $what, ResponseInterface $response): GitException
    {
        $status = $response->getStatusCode();
        $code = match ($status) {
            401, 403 => GitErrorCode::Auth,
            404 => GitErrorCode::NotFound,
            405 => GitErrorCode::Unsupported,
            409 => GitErrorCode::Conflict,
            400, 422 => GitErrorCode::InvalidArgument,
            429 => GitErrorCode::RateLimited,
            default => GitErrorCode::Unknown,
        };

        $message = "GitLab answered {$what} with {$status}: ".self::errorMessage($response);

        if ($status === 429) {
            $retry = self::retryAfter($response);
            $message .= $retry === null ? '. GitLab did not say when to retry.' : ". Retry after {$retry} seconds.";
        }

        return new GitException($code, $message, $status);
    }

    /** GitLab's error body, in the three shapes it uses. */
    private static function errorMessage(ResponseInterface $response): string
    {
        $fallback = $response->getReasonPhrase() ?: 'no reason given';

        try {
            $content = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $fallback;
        }
        if (! is_array($content)) {
            return $fallback;
        }

        $message = match (true) {
            is_string($content['message'] ?? null) => $content['message'],
            is_array($content['message'] ?? null) => self::flatten($content['message']),
            is_string($content['error_description'] ?? null) => $content['error_description'],
            is_string($content['error'] ?? null) => $content['error'],
            default => $fallback,
        };

        // Bounded and single-line: this ends up in logs.
        $message = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);

        return strlen($message) > 500 ? substr($message, 0, 500).'…' : $message;
    }

    /** `{"title": ["can't be blank"]}` → `"title" can't be blank` */
    private static function flatten(array $messages): string
    {
        $out = [];
        foreach ($messages as $field => $value) {
            foreach (is_array($value) ? $value : [$value] as $text) {
                if (is_scalar($text)) {
                    $out[] = is_int($field) ? (string) $text : sprintf('"%s" %s', $field, $text);
                }
            }
        }

        return implode(', ', array_unique($out));
    }

    private static function retryAfter(ResponseInterface $response): ?int
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }

        $at = strtotime($value);

        return $at === false ? null : max(0, $at - time());
    }

    private static function nextPageNumber(ResponseInterface $response): ?string
    {
        $next = trim($response->getHeaderLine('X-Next-Page'));

        return preg_match('/^[1-9][0-9]{0,9}$/', $next) === 1 ? $next : null;
    }

    private static function nextLink(ResponseInterface $response): ?string
    {
        foreach ($response->getHeader('Link') as $line) {
            preg_match_all('/<([^>]*)>((?:\s*;\s*[^;,]+)*)/', $line, $links, PREG_SET_ORDER);
            foreach ($links as $link) {
                if (preg_match('/;\s*rel\s*=\s*"?([^";]*)"?/i', $link[2], $rel) === 1
                    && in_array('next', preg_split('/\s+/', strtolower(trim($rel[1]))) ?: [], true)) {
                    return $link[1];
                }
            }
        }

        return null;
    }

    /** A server-supplied link, accepted only if it points back inside this instance's API. */
    private function ownLink(string $link, string $what): string
    {
        $mine = parse_url($this->baseUrl);
        $theirs = preg_match('/[\x00-\x20\x7F\\\\]/', $link) === 1 ? false : parse_url($link);
        $prefix = ($mine['path'] ?? '').self::API_ROOT;

        $own = is_array($theirs)
            && strtolower($theirs['scheme'] ?? '') === 'https'
            && ! isset($theirs['user']) && ! isset($theirs['pass']) && ! isset($theirs['fragment'])
            && strtolower($theirs['host'] ?? '') === $mine['host']
            && ($theirs['port'] ?? 443) === ($mine['port'] ?? 443)
            && str_starts_with($theirs['path'] ?? '', $prefix)
            && self::isApiPath(substr($theirs['path'], strlen($prefix)));

        if (! $own) {
            throw new GitException(GitErrorCode::Unknown, "GitLab's pagination link for {$what} points outside {$this->baseUrl}/api/v4/. It was not followed, because the request carries a credential.");
        }

        return $link;
    }

    private static function isApiPath(string $path): bool
    {
        $segment = '(?:[A-Za-z0-9._~-]|%[0-9A-Fa-f]{2})+';

        return preg_match('#^'.$segment.'(?:/'.$segment.')*$#', $path) === 1
            && ! self::hasDotSegment(rawurldecode($path));
    }

    /** Checked on the DECODED path, so `%2e%2e` and `..%2F..` are caught too. */
    private static function hasDotSegment(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function describe(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $prefix = (string) parse_url($this->baseUrl, PHP_URL_PATH).self::API_ROOT;

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private static function invalid(string $message): GitException
    {
        return new GitException(GitErrorCode::InvalidArgument, $message);
    }
}

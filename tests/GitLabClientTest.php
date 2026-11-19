<?php

declare(strict_types=1);

namespace FancyGit\GitLab\Tests;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\GitLab\GitLabClient;
use FancyGit\GitLab\GitLabProvider;
use FancyGit\GitLab\TokenType;
use FancyGit\GitLab\Tests\Support\FakeGitLab;
use PHPUnit\Framework\TestCase;

/**
 * The first-party GitLab REST v4 client: where requests may go, what they
 * carry, and how far a response is allowed to steer the next one.
 */
abstract class GitLabClientCase extends TestCase
{
    protected static function invalid(callable $call): GitException
    {
        try {
            $call();
        } catch (GitException $e) {
            self::assertSame(GitErrorCode::InvalidArgument, $e->errorCode, $e->getMessage());
            self::assertStringNotContainsString(FakeGitLab::TOKEN, $e->getMessage());

            return $e;
        }

        self::fail('Expected an invalid_argument GitException.');
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeBaseUrls(): iterable
    {
        yield 'plain http sends the token in cleartext' => ['http://gitlab.com'];
        yield 'other scheme' => ['ftp://gitlab.com'];
        yield 'no scheme' => ['gitlab.com'];
        yield 'empty' => [''];
        yield 'no host' => ['https:///gitlab'];
        yield 'credentials in the URL' => ['https://user:secret@gitlab.com'];
        yield 'userinfo disguising the host' => ['https://gitlab.com@attacker.example'];
        yield 'backslash host confusion' => ['https://gitlab.com\\@attacker.example'];
        yield 'query string' => ['https://gitlab.com/?private_token=x'];
        yield 'fragment' => ['https://gitlab.com/#x'];
        yield 'dot segment' => ['https://example.test/gitlab/../admin'];
        yield 'encoded dot segment' => ['https://example.test/%2e%2e/admin'];
        yield 'the API URL instead of the instance URL' => ['https://gitlab.com/api/v4'];
        yield 'leading whitespace' => [' https://gitlab.com'];
        yield 'embedded newline' => ["https://gitlab.com\r\nX-Injected: 1"];
        yield 'space in host' => ['https://git lab.com'];
    }

    /** @return iterable<string, array{string}> */
    public static function unsafePaths(): iterable
    {
        yield 'parent segment' => ['projects/../users'];
        yield 'encoded parent segment' => ['projects/%2e%2e/users'];
        yield 'absolute path' => ['/projects'];
        yield 'absolute URL' => ['https://attacker.example/api/v4/projects'];
        yield 'protocol-relative' => ['//attacker.example/projects'];
        yield 'query smuggled into the path' => ['projects?sudo=root'];
        yield 'fragment' => ['projects#x'];
        yield 'empty' => [''];
        yield 'raw space' => ['projects/a b'];
    }

    /** @return iterable<string, array{string, string}> */
    public static function foreignLinks(): iterable
    {
        yield 'another host' => ['https://gitlab.com', 'https://attacker.example/api/v4/projects?page=2'];
        yield 'a lookalike host' => ['https://gitlab.com', 'https://gitlab.com.attacker.example/api/v4/projects?page=2'];
        yield 'a downgrade to http' => ['https://gitlab.com', 'http://gitlab.com/api/v4/projects?page=2'];
        yield 'another port' => ['https://gitlab.com', 'https://gitlab.com:444/api/v4/projects?page=2'];
        yield 'outside the API' => ['https://gitlab.com', 'https://gitlab.com/users/sign_in?page=2'];
        yield 'outside the relative root' => ['https://example.test/gitlab', 'https://example.test/api/v4/projects?page=2'];
        yield 'userinfo' => ['https://gitlab.com', 'https://gitlab.com@attacker.example/api/v4/projects?page=2'];
        yield 'relative reference' => ['https://gitlab.com', '/api/v4/projects?page=2'];
    }
}

uses(GitLabClientCase::class);

// ---- base URL ---------------------------------------------------------

test('the base url is normalized', function () {
    self::assertSame('https://gitlab.com', (new GitLabClient)->baseUrl());
    self::assertSame('https://gitlab.com', (new GitLabClient('https://GitLab.com/'))->baseUrl());
    self::assertSame('https://git.example.test:8443', (new GitLabClient('https://git.example.test:8443'))->baseUrl());
    self::assertSame('https://example.test/tools/gitlab', (new GitLabClient('https://example.test/tools/gitlab/'))->baseUrl());
    self::assertSame('https://[2001:db8::1]', (new GitLabClient('https://[2001:db8::1]/'))->baseUrl());
});

test('an unsafe base url is refused', function (string $url) {
    self::invalid(fn () => new GitLabClient($url, FakeGitLab::TOKEN));
})->with(GitLabClientCase::unsafeBaseUrls());

test('with token validates the base url too', function () {
    self::invalid(fn () => GitLabProvider::withToken(FakeGitLab::TOKEN, 'http://git.example.test'));

    self::assertSame(
        ['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app', 'baseUrl' => 'https://git.example.test'],
        GitLabProvider::withToken(FakeGitLab::TOKEN, 'https://git.example.test/')->identify(['name' => 'origin', 'fetchUrl' => 'https://git.example.test/acme/app.git']),
    );
});

// ---- credentials ------------------------------------------------------

test('each token type uses its own header', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json([]), FakeGitLab::json([]), FakeGitLab::json([]), FakeGitLab::json([])]);

    (new GitLabClient(token: FakeGitLab::TOKEN, http: $gitlab->guzzle()))->get('projects');
    (new GitLabClient(token: 'oauth-abc', tokenType: TokenType::OAuth, http: $gitlab->guzzle()))->get('projects');
    (new GitLabClient(token: 'glcbt-job', tokenType: TokenType::CiJob, http: $gitlab->guzzle()))->get('projects');
    (new GitLabClient(http: $gitlab->guzzle()))->get('projects');

    self::assertSame(FakeGitLab::TOKEN, $gitlab->request(0)->getHeaderLine('PRIVATE-TOKEN'));
    self::assertSame('Bearer oauth-abc', $gitlab->request(1)->getHeaderLine('Authorization'));
    self::assertFalse($gitlab->request(1)->hasHeader('PRIVATE-TOKEN'));
    self::assertSame('glcbt-job', $gitlab->request(2)->getHeaderLine('JOB-TOKEN'));

    $anonymous = $gitlab->request(3);
    self::assertFalse($anonymous->hasHeader('PRIVATE-TOKEN'));
    self::assertFalse($anonymous->hasHeader('Authorization'));
    self::assertFalse($anonymous->hasHeader('JOB-TOKEN'));

    foreach (range(0, 3) as $i) {
        self::assertSame('application/json', $gitlab->request($i)->getHeaderLine('Accept'));
        self::assertStringStartsWith('particle-academy/fancy-git-gitlab', $gitlab->request($i)->getHeaderLine('User-Agent'));
    }
});

test('a blank or malformed token is refused without echoing it', function () {
    // A blank env var would otherwise send anonymous requests, and a private
    // project answers those with a 404 that looks like a typo in its path.
    self::invalid(fn () => new GitLabClient(token: ''));
    self::invalid(fn () => new GitLabClient(token: '   '));
    self::invalid(fn () => new GitLabClient(token: FakeGitLab::TOKEN."\r\nX-Injected: 1"));
});

test('the token does not leak through dumps or serialization', function () {
    $client = new GitLabClient(token: FakeGitLab::TOKEN);
    $provider = new GitLabProvider($client);

    ob_start();
    var_dump($provider);
    print_r($provider);
    var_export($provider);
    $dumped = (string) ob_get_clean();

    self::assertStringNotContainsString(FakeGitLab::TOKEN, $dumped);
    self::assertStringContainsString('[REDACTED]', $dumped);

    // Asserted by MESSAGE. The Guzzle client inside holds closures, so
    // serialize() would throw on those regardless — a bare "it threw" would
    // pass even if the token itself were perfectly serializable.
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('holds a credential');
    serialize($client);
});

// ---- requests ---------------------------------------------------------

test('redirects and http errors are off even on a client configured otherwise', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json([])]);

    (new GitLabClient(token: FakeGitLab::TOKEN, http: $gitlab->guzzle(['allow_redirects' => true, 'http_errors' => true])))->get('projects');

    self::assertFalse($gitlab->options()['allow_redirects']);
    self::assertFalse($gitlab->options()['http_errors']);
});

test('a path cannot escape the api root', function (string $path) {
    $gitlab = new FakeGitLab([FakeGitLab::json([])]);

    self::invalid(fn () => (new GitLabClient(http: $gitlab->guzzle()))->get($path));
    self::assertSame(0, $gitlab->count());
})->with(GitLabClientCase::unsafePaths());

test('project paths are encoded as one segment', function () {
    self::assertSame('projects/group%2Fsub%2Fapp', GitLabClient::projectPath('group/sub', 'app'));
    self::assertSame('projects/a.b%2Fc-d_e', GitLabClient::projectPath('a.b', 'c-d_e'));
    self::invalid(fn () => GitLabClient::projectPath('', 'app'));
    self::invalid(fn () => GitLabClient::projectPath('acme', ''));
});

// ---- pagination -------------------------------------------------------

test('keyset pagination follows a same origin link', function () {
    $next = 'https://gitlab.com/api/v4/projects?id_after=10&order_by=id&pagination=keyset&per_page=100&sort=asc';
    $gitlab = new FakeGitLab([
        FakeGitLab::json([['id' => 9], ['id' => 10]], 200, ['Link' => "<{$next}>; rel=\"next\", <https://gitlab.com/api/v4/projects?order_by=id&pagination=keyset&per_page=100&sort=asc>; rel=\"first\""]),
        FakeGitLab::json([['id' => 11]]),
    ]);

    $items = (new GitLabClient(http: $gitlab->guzzle()))->getAll('projects', ['pagination' => 'keyset', 'order_by' => 'id', 'sort' => 'asc']);

    self::assertSame([['id' => 9], ['id' => 10], ['id' => 11]], $items);
    self::assertSame(2, $gitlab->count());
    self::assertSame($next, (string) $gitlab->request(1)->getUri());
});

test('x next page wins over a link header', function () {
    // Offset pagination is rebuilt from the page NUMBER against our own
    // base URL, so the server's URL is not needed at all when it gave one.
    $gitlab = new FakeGitLab([
        FakeGitLab::json([['id' => 1]], 200, ['X-Next-Page' => '2', 'Link' => '<https://gitlab.com/api/v4/projects?page=2>; rel="next"']),
        FakeGitLab::json([['id' => 2]], 200, ['X-Next-Page' => '']),
    ]);

    (new GitLabClient(http: $gitlab->guzzle()))->getAll('projects');

    self::assertSame(['per_page' => '100', 'page' => '2'], $gitlab->query(1));
});

test('a pagination link cannot send the token anywhere else', function (string $baseUrl, string $link) {
    $gitlab = new FakeGitLab([
        FakeGitLab::json([['id' => 1]], 200, ['Link' => "<{$link}>; rel=\"next\""]),
        FakeGitLab::json([['id' => 2]]),
    ]);

    try {
        (new GitLabClient($baseUrl, FakeGitLab::TOKEN, http: $gitlab->guzzle()))->getAll('projects');
        self::fail('A foreign pagination link was followed.');
    } catch (GitException $e) {
        self::assertSame(GitErrorCode::Unknown, $e->errorCode);
        self::assertStringContainsString('pagination link', $e->getMessage());
    }

    self::assertSame(1, $gitlab->count());
})->with(GitLabClientCase::foreignLinks());

test('a walk that does not end stops at the page limit', function () {
    $gitlab = new FakeGitLab(array_map(
        fn (int $page) => FakeGitLab::json([['id' => $page]], 200, ['X-Next-Page' => (string) ($page + 1)]),
        range(1, 4),
    ));

    try {
        (new GitLabClient(http: $gitlab->guzzle()))->getAll('projects', maxPages: 3);
        self::fail('An endless walk was not stopped.');
    } catch (GitException $e) {
        // Not a silently truncated list that reads as complete.
        self::assertStringContainsString('more than 3 pages', $e->getMessage());
    }

    self::assertSame(3, $gitlab->count());
});

test('a page that is not a list is an error', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json(['message' => 'not a list'])]);

    $this->expectException(GitException::class);
    (new GitLabClient(http: $gitlab->guzzle()))->getAll('projects');
});

// ---- provider inputs --------------------------------------------------

test('a ref from another instance is refused before any request', function () {
    // Otherwise this host's credentials would be used against whatever
    // project happens to have the same path here — and createReview would
    // open a merge request on it.
    $gitlab = new FakeGitLab([FakeGitLab::json([])]);
    $provider = $gitlab->provider();

    $e = self::invalid(fn () => $provider->repository(['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app', 'baseUrl' => 'https://git.example.test']));

    self::assertStringContainsString('https://git.example.test', $e->getMessage());
    self::assertSame(0, $gitlab->count());
});

test('a ref naming this instance explicitly is accepted', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json(['id' => 1, 'web_url' => 'https://git.example.test/acme/app', 'default_branch' => 'main', 'visibility' => 'private'])]);

    $repository = $gitlab->provider('https://git.example.test')->repository(['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app', 'baseUrl' => 'https://GIT.example.test/']);

    self::assertSame('1', $repository['id']);
});

test('malformed provider inputs are refused before any request', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json([])]);
    $provider = $gitlab->provider();
    $ref = ['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app'];

    self::invalid(fn () => $provider->repository(['provider' => 'gitlab', 'owner' => '', 'name' => 'app']));
    self::invalid(fn () => $provider->repository(['provider' => 'gitlab', 'name' => 'app']));
    self::invalid(fn () => $provider->getReview($ref, 0));
    self::invalid(fn () => $provider->listReviews($ref, ['cursor' => '../2']));
    self::invalid(fn () => $provider->listReviews($ref, ['cursor' => '0']));
    self::invalid(fn () => $provider->createReview($ref, ['title' => 'x', 'sourceBranch' => 'feature']));
    self::invalid(fn () => $provider->compare($ref, '', 'feature'));
    self::invalid(fn () => $provider->checks($ref, ''));

    self::assertSame(0, $gitlab->count());
});

test('the limit is kept within what gitlab serves', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json([]), FakeGitLab::json([])]);
    $provider = $gitlab->provider();

    $provider->listReviews(['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app'], ['limit' => 500]);
    $provider->listReviews(['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app'], ['limit' => 0]);

    self::assertSame('100', $gitlab->query(0)['per_page']);
    self::assertSame('1', $gitlab->query(1)['per_page']);
});

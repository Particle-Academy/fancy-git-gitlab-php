<?php

declare(strict_types=1);

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\GitLab\GitLabClient;
use FancyGit\GitLab\Tests\Support\FakeGitLab;

/*
| A transient 5xx on a GET is retried, a bounded number of times; a POST is
| never replayed, because GitLab may already hold what the first one created.
| Found by the fancy-labs git-adapter test (`get-503-once`, `post-502`); the
| Node twin holds the same rule.
*/

const RETRY_REF = ['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app'];
const RETRY_PROJECT = ['id' => 42, 'name' => 'app', 'description' => null, 'default_branch' => 'main', 'visibility' => 'private', 'web_url' => 'https://gitlab.com/acme/app'];

function retryCaught(callable $call): GitException
{
    try {
        $call();
    } catch (GitException $e) {
        return $e;
    }
    throw new RuntimeException('Expected a GitException.');
}

test('a GET that answered 5xx is retried, and the retry answers', function (int $status) {
    $gitlab = new FakeGitLab([FakeGitLab::json(['message' => (string) $status], $status), FakeGitLab::json(RETRY_PROJECT)]);

    $repository = $gitlab->provider()->repository(RETRY_REF);

    expect($repository['id'])->toBe('42')
        ->and($gitlab->count())->toBe(2)
        ->and($gitlab->request(1)->getMethod())->toBe('GET');
})->with([500, 502, 503, 504]);

test('a GET gives up after a bounded number of retries, with the last 5xx', function () {
    $gitlab = new FakeGitLab(array_map(static fn () => FakeGitLab::json(['message' => '503 Service Unavailable'], 503), range(1, 10)));

    $e = retryCaught(fn () => $gitlab->provider()->repository(RETRY_REF));

    expect($gitlab->count())->toBe(1 + GitLabClient::GET_RETRIES)
        ->and($e->errorCode)->toBe(GitErrorCode::Unknown)
        ->and($e->getCode())->toBe(503);
});

test('each page of a walk is retried on its own', function () {
    $gitlab = new FakeGitLab([
        FakeGitLab::json([['id' => 1]], 200, ['X-Next-Page' => '2']),
        FakeGitLab::json(['message' => '502 Bad Gateway'], 502),
        FakeGitLab::json([['id' => 2]]),
    ]);

    $items = (new GitLabClient('https://gitlab.com', FakeGitLab::TOKEN, http: $gitlab->guzzle()))->getAll('projects/acme%2Fapp/pipelines');

    expect($items)->toBe([['id' => 1], ['id' => 2]])
        ->and($gitlab->count())->toBe(3);
});

test('a POST that answered 5xx is never replayed', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json(['message' => '502 Bad Gateway'], 502), FakeGitLab::json(['iid' => 7], 201)]);

    $e = retryCaught(fn () => $gitlab->provider()->createReview(RETRY_REF, ['title' => 'T', 'sourceBranch' => 'f', 'targetBranch' => 'main']));

    expect($gitlab->count())->toBe(1)
        ->and($e->errorCode)->toBe(GitErrorCode::Unknown)
        ->and($e->getCode())->toBe(502);
});

test('a 4xx is not retried', function (int $status) {
    $gitlab = new FakeGitLab([FakeGitLab::json(['message' => (string) $status], $status), FakeGitLab::json(RETRY_PROJECT)]);

    retryCaught(fn () => $gitlab->provider()->repository(RETRY_REF));

    expect($gitlab->count())->toBe(1);
})->with([400, 401, 404, 429]);

test('repository() keeps the base URL the ref was given, as the Node twin does', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json(RETRY_PROJECT)]);

    $repository = $gitlab->provider('https://example.test/gitlab')->repository(RETRY_REF + ['baseUrl' => 'https://example.test/gitlab']);

    expect($repository['baseUrl'] ?? null)->toBe('https://example.test/gitlab');
});

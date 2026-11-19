<?php

declare(strict_types=1);

namespace FancyGit\GitLab\Tests;

use FancyGit\GitLab\Tests\Support\FakeGitLab;
use FancyGit\Provider\GitProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the adapter sends to GitLab, and what it hands back through the
 * `GitProvider` contract — pinned at the HTTP boundary.
 *
 * Request shapes are GitLab REST v4 as documented, and the ones the adapter's
 * original php-gitlab-api calls resolve to. Response bodies are trimmed
 * copies of GitLab's documented payloads, carrying every field the mapping
 * reads plus a few it must ignore.
 */
abstract class ProviderContractCase extends TestCase
{
    protected const REF = ['provider' => 'gitlab', 'owner' => 'group/team', 'name' => 'app'];

    /** Nested groups travel as ONE path segment with the slashes encoded. */
    protected const PROJECT = '/api/v4/projects/group%2Fteam%2Fapp';

    /** @return array<string, mixed> */
    protected static function mergeRequest(array $over = []): array
    {
        return $over + [
            'id' => 9001,
            'iid' => 7,
            'project_id' => 42,
            'title' => 'Add the thing',
            'description' => 'Adds it.',
            'state' => 'opened',
            'draft' => false,
            'web_url' => 'https://gitlab.com/group/team/app/-/merge_requests/7',
            'source_branch' => 'feature',
            'target_branch' => 'main',
            'author' => ['id' => 1, 'username' => 'ada', 'name' => 'Ada'],
            'merge_status' => 'can_be_merged',
            'detailed_merge_status' => 'mergeable',
            'created_at' => '2026-09-01T10:00:00.000Z',
            'updated_at' => '2026-09-02T11:00:00.000Z',
        ];
    }

    /** @return array<string, mixed> */
    protected static function pipeline(int $id, string $status): array
    {
        return [
            'id' => $id,
            'iid' => $id - 100,
            'project_id' => 42,
            'sha' => 'a1b2c3d',
            'ref' => 'feature',
            'status' => $status,
            'source' => 'push',
            'web_url' => "https://gitlab.com/group/team/app/-/pipelines/{$id}",
            'created_at' => '2026-09-01T10:00:00.000Z',
            'updated_at' => '2026-09-01T10:05:00.000Z',
        ];
    }
}

uses(ProviderContractCase::class);

test('it is a git provider of kind gitlab', function () {
    $provider = (new FakeGitLab)->provider();

    self::assertInstanceOf(GitProvider::class, $provider);
    self::assertSame('gitlab', $provider->kind());
});

test('repository reads the project by its encoded path', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json([
        'id' => 42,
        'name' => 'app',
        'path_with_namespace' => 'group/team/app',
        'description' => null,
        'default_branch' => 'main',
        'visibility' => 'internal',
        'web_url' => 'https://gitlab.com/group/team/app',
    ])]);

    $repository = $gitlab->provider()->repository(self::REF);

    self::assertSame(1, $gitlab->count());
    $request = $gitlab->request();
    self::assertSame('GET', $request->getMethod());
    self::assertSame('https', $request->getUri()->getScheme());
    self::assertSame('gitlab.com', $request->getUri()->getHost());
    // Encoded ONCE. `group%252Fteam%252Fapp` is what a second encoding
    // produces, and GitLab answers it with a 404 that reads like a
    // permissions problem.
    self::assertSame(self::PROJECT, $request->getUri()->getPath());
    self::assertSame(FakeGitLab::TOKEN, $request->getHeaderLine('PRIVATE-TOKEN'));

    self::assertSame([
        'provider' => 'gitlab',
        'owner' => 'group/team',
        'name' => 'app',
        'id' => '42',
        'webUrl' => 'https://gitlab.com/group/team/app',
        'defaultBranch' => 'main',
        'private' => true,
        'description' => null,
    ], $repository);
});

test('list reviews defaults to open merge requests', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json([
        self::mergeRequest(),
        self::mergeRequest(['id' => 9002, 'iid' => 8, 'draft' => true]),
        self::mergeRequest(['id' => 9003, 'iid' => 9, 'state' => 'merged']),
        self::mergeRequest(['id' => 9004, 'iid' => 10, 'state' => 'closed', 'author' => null]),
    ], 200, ['X-Next-Page' => '', 'X-Page' => '1', 'X-Per-Page' => '30'])]);

    $page = $gitlab->provider()->listReviews(self::REF);

    self::assertSame('GET', $gitlab->request()->getMethod());
    self::assertSame(self::PROJECT.'/merge_requests', $gitlab->request()->getUri()->getPath());
    self::assertSame(['state' => 'opened', 'per_page' => '30'], $gitlab->query());

    self::assertSame([
        'id' => '9001',
        'number' => 7,
        'title' => 'Add the thing',
        'state' => 'open',
        'webUrl' => 'https://gitlab.com/group/team/app/-/merge_requests/7',
        'sourceBranch' => 'feature',
        'targetBranch' => 'main',
        'author' => 'ada',
    ], $page['items'][0]);
    self::assertSame(['open', 'draft', 'merged', 'closed'], array_column($page['items'], 'state'));
    self::assertSame('unknown', $page['items'][3]['author']);
    // The last page says so with an EMPTY X-Next-Page, and must not be
    // turned into a cursor that loops back to page one.
    self::assertArrayNotHasKey('nextCursor', $page);
});

test('list reviews maps state and limit into the query', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json([]), FakeGitLab::json([]), FakeGitLab::json([])]);
    $provider = $gitlab->provider();

    $provider->listReviews(self::REF, ['state' => 'merged', 'limit' => 5]);
    $provider->listReviews(self::REF, ['state' => 'closed']);
    $provider->listReviews(self::REF, ['state' => 'draft']);

    self::assertSame(['state' => 'merged', 'per_page' => '5'], $gitlab->query(0));
    self::assertSame(['state' => 'closed', 'per_page' => '30'], $gitlab->query(1));
    // GitLab has no "draft" state to filter on; drafts are opened MRs.
    self::assertSame(['state' => 'opened', 'per_page' => '30'], $gitlab->query(2));
});

test('list reviews pages with a cursor and reports the next one', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json(
        [self::mergeRequest()],
        200,
        ['X-Page' => '2', 'X-Next-Page' => '3', 'X-Total' => '61', 'X-Per-Page' => '30'],
    )]);

    $page = $gitlab->provider()->listReviews(self::REF, ['cursor' => '2']);

    self::assertSame(['state' => 'opened', 'per_page' => '30', 'page' => '2'], $gitlab->query());
    self::assertSame('3', $page['nextCursor']);
    self::assertSame(61, $page['total']);
});

test('get review adds the detail fields', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json(self::mergeRequest(['merge_status' => 'cannot_be_merged']))]);

    $review = $gitlab->provider()->getReview(self::REF, 7);

    self::assertSame('GET', $gitlab->request()->getMethod());
    self::assertSame(self::PROJECT.'/merge_requests/7', $gitlab->request()->getUri()->getPath());
    self::assertSame('Adds it.', $review['body']);
    self::assertFalse($review['mergeable']);
    self::assertSame('2026-09-01T10:00:00.000Z', $review['createdAt']);
    self::assertSame('2026-09-02T11:00:00.000Z', $review['updatedAt']);
    self::assertSame(7, $review['number']);
});

test('create review posts the merge request as json', function () {
    $gitlab = new FakeGitLab([
        FakeGitLab::json(self::mergeRequest(), 201),
        FakeGitLab::json(self::mergeRequest(['description' => null]), 201),
    ]);
    $provider = $gitlab->provider();

    $review = $provider->createReview(self::REF, ['title' => 'Add the thing', 'body' => 'Adds it.', 'sourceBranch' => 'feature', 'targetBranch' => 'main']);
    $provider->createReview(self::REF, ['title' => 'Add the thing', 'sourceBranch' => 'feature', 'targetBranch' => 'main']);

    $request = $gitlab->request(0);
    self::assertSame('POST', $request->getMethod());
    self::assertSame(self::PROJECT.'/merge_requests', $request->getUri()->getPath());
    self::assertStringStartsWith('application/json', $request->getHeaderLine('Content-Type'));
    self::assertSame(
        ['source_branch' => 'feature', 'target_branch' => 'main', 'title' => 'Add the thing', 'description' => 'Adds it.'],
        json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR),
    );
    // No body means no description key at all, not `"description": null`.
    self::assertSame(
        ['source_branch' => 'feature', 'target_branch' => 'main', 'title' => 'Add the thing'],
        json_decode((string) $gitlab->request(1)->getBody(), true, flags: JSON_THROW_ON_ERROR),
    );
    self::assertSame('open', $review['state']);
    self::assertSame(7, $review['number']);
});

test('compare reads the repository comparison', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json([
        'commit' => ['id' => 'b2'],
        'commits' => [[
            'id' => 'b2c3d4e5f6',
            'short_id' => 'b2c3d4e5',
            'title' => 'Add the thing',
            'message' => "Add the thing\n\nLonger.",
            'author_name' => 'Ada',
            'author_email' => 'ada@example.test',
            'authored_date' => '2026-09-01T09:00:00.000Z',
            'parent_ids' => ['a1'],
        ]],
        'diffs' => [],
        'compare_timeout' => false,
        'compare_same_ref' => false,
    ])]);

    $comparison = $gitlab->provider()->compare(self::REF, 'main', 'feature/x');

    self::assertSame(self::PROJECT.'/repository/compare', $gitlab->request()->getUri()->getPath());
    self::assertSame(['from' => 'main', 'to' => 'feature/x', 'straight' => 'false'], $gitlab->query());
    self::assertSame([
        'aheadBy' => 1,
        'behindBy' => 0,
        'commits' => [[
            'id' => 'b2c3d4e5f6',
            'shortId' => 'b2c3d4e5',
            'parents' => ['a1'],
            'authorName' => 'Ada',
            'authorEmail' => 'ada@example.test',
            'authoredAt' => '2026-09-01T09:00:00.000Z',
            'subject' => 'Add the thing',
        ]],
    ], $comparison);
});

test('checks reads every page of pipelines for the revision', function () {
    $gitlab = new FakeGitLab([
        FakeGitLab::json([self::pipeline(301, 'success'), self::pipeline(302, 'running')], 200, ['X-Page' => '1', 'X-Next-Page' => '2']),
        FakeGitLab::json([self::pipeline(303, 'waiting_for_resource'), self::pipeline(304, 'canceled'), self::pipeline(305, 'manual')], 200, ['X-Page' => '2', 'X-Next-Page' => '']),
    ]);

    $checks = $gitlab->provider()->checks(self::REF, 'a1b2c3d');

    self::assertSame(2, $gitlab->count());
    self::assertSame(self::PROJECT.'/pipelines', $gitlab->request(0)->getUri()->getPath());
    self::assertSame(['sha' => 'a1b2c3d', 'per_page' => '100'], $gitlab->query(0));
    self::assertSame(['sha' => 'a1b2c3d', 'per_page' => '100', 'page' => '2'], $gitlab->query(1));

    self::assertSame([
        'id' => '301',
        'name' => 'Pipeline #301',
        'state' => 'passed',
        'webUrl' => 'https://gitlab.com/group/team/app/-/pipelines/301',
        'startedAt' => '2026-09-01T10:00:00.000Z',
        'completedAt' => '2026-09-01T10:05:00.000Z',
    ], $checks[0]);
    self::assertSame(['passed', 'running', 'queued', 'cancelled', 'unknown'], array_column($checks, 'state'));
});

test('a self managed instance is addressed under its own url', function () {
    $gitlab = new FakeGitLab([
        FakeGitLab::json(['id' => 1, 'web_url' => 'https://git.example.test/acme/app', 'default_branch' => 'main', 'visibility' => 'public']),
        FakeGitLab::json(['id' => 1, 'web_url' => 'https://example.test/gitlab/acme/app', 'default_branch' => 'main', 'visibility' => 'public']),
    ]);

    $gitlab->provider('https://git.example.test:8443/')->repository(['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app']);
    // GitLab supports installation under a relative URL root.
    $gitlab->provider('https://example.test/gitlab')->repository(['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app']);

    self::assertSame('https://git.example.test:8443/api/v4/projects/acme%2Fapp', (string) $gitlab->request(0)->getUri());
    self::assertSame('https://example.test/gitlab/api/v4/projects/acme%2Fapp', (string) $gitlab->request(1)->getUri());
});

test('identify strips a relative url root from https remotes only', function () {
    $provider = (new FakeGitLab)->provider('https://example.test/gitlab');
    $expected = ['provider' => 'gitlab', 'owner' => 'acme/tools', 'name' => 'app', 'baseUrl' => 'https://example.test/gitlab'];

    self::assertSame($expected, $provider->identify(['name' => 'origin', 'fetchUrl' => 'https://example.test/gitlab/acme/tools/app.git']));
    self::assertSame($expected, $provider->identify(['name' => 'origin', 'fetchUrl' => 'git@example.test:acme/tools/app.git']));
    // Same host, outside the root: another application, not this GitLab.
    self::assertNull($provider->identify(['name' => 'origin', 'fetchUrl' => 'https://example.test/other/acme/app.git']));
});

test('identify is unchanged for gitlab com and self managed', function () {
    $com = (new FakeGitLab)->provider();
    $managed = (new FakeGitLab)->provider('https://git.example.test');

    self::assertSame(
        ['provider' => 'gitlab', 'owner' => 'group/team', 'name' => 'app'],
        $com->identify(['name' => 'origin', 'fetchUrl' => 'https://gitlab.com/group/team/app.git']),
    );
    self::assertSame(
        ['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app', 'baseUrl' => 'https://git.example.test'],
        $managed->identify(['name' => 'origin', 'fetchUrl' => 'git@git.example.test:acme/app.git']),
    );
    self::assertNull($com->identify(['name' => 'origin', 'fetchUrl' => 'git@github.com:acme/app.git']));
    self::assertNull($managed->identify(['name' => 'origin', 'fetchUrl' => 'git@gitlab.com:acme/app.git']));
});

<?php

declare(strict_types=1);

namespace FancyGit\GitLab\Tests;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\GitLab\GitLabClient;
use FancyGit\GitLab\Tests\Support\FakeGitLab;

/*
 * The keyset-pagination `Link` header must be parsed in LINEAR time.
 *
 * 0.3.0 matched it with `/<([^>]*)>((?:\s*;\s*[^;,]+)*)/`. On a header of
 * 100,000 `<` and no `>`, every `<` starts a match attempt that scans to the
 * end of the string before failing: quadratic. The header comes from whatever
 * server the base URL points at, so it is untrusted input, and it is read on
 * every page of every paginated call.
 *
 * The fix parses with strpos / substr, and refuses a header over 8 KB with an
 * error rather than parsing it. Refusing rather than ignoring is deliberate:
 * ignoring an oversized header would end the walk early and hand back a list
 * that reads as complete, which `getAll` exists to never do.
 *
 * Ported case for case from the Node twin's tests/link-header-redos.test.ts,
 * which promises the same rules and messages.
 */

const LINK_BASE = 'https://example.test/gitlab';
const LINK_API = LINK_BASE.'/api/v4/projects/1/pipelines';
const LINK_BOUND_MS = 50.0;

function linkClient(FakeGitLab $gitlab): GitLabClient
{
    return new GitLabClient(LINK_BASE, FakeGitLab::TOKEN, http: $gitlab->guzzle());
}

/** @return array{0: mixed, 1: float} the outcome (result or exception) and the elapsed milliseconds */
function timedGetAll(FakeGitLab $gitlab): array
{
    $client = linkClient($gitlab);
    $started = hrtime(true);
    try {
        $outcome = $client->getAll('projects/1/pipelines');
    } catch (\Throwable $e) {
        $outcome = $e;
    }

    return [$outcome, (hrtime(true) - $started) / 1e6];
}

// ---- Link header parsing is linear -------------------------------------

test('refuses a 100k-character hostile header in under 50 ms, with an error rather than a short list', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json([['id' => 1]], 200, ['Link' => str_repeat('<', 100_000)])]);

    [$outcome, $elapsed] = timedGetAll($gitlab);

    expect($outcome)->toBeInstanceOf(GitException::class);
    expect($outcome->errorCode)->toBe(GitErrorCode::Unknown);
    expect($outcome->getMessage())->toBe('GitLab sent a pagination Link header longer than 8192 characters. It was not parsed, and the list was not returned, because it may be incomplete.');
    expect($elapsed)->toBeLessThan(LINK_BOUND_MS, sprintf('took %.0fms; the quadratic Link parser is back', $elapsed));
});

test('an over-cap header that does carry a next link is refused, never read as the last page', function () {
    // PHP-specific. PCRE does not go quadratic on the shape that hurt V8, but
    // on a long run of parameters the 0.3.0 regex exhausted PCRE's recursion
    // limit, preg_match_all returned false, and getAll returned page one as if
    // it were the whole list. Measured: 100,000 characters, "Recursion limit
    // exhausted", with or without JIT.
    $link = '<'.LINK_API.'>'.str_repeat('; a', 33_000).', <'.LINK_API.'?id_after=1>; rel="next"';
    $gitlab = new FakeGitLab([FakeGitLab::json([['id' => 1]], 200, ['Link' => $link]), FakeGitLab::json([['id' => 2]])]);

    [$outcome] = timedGetAll($gitlab);

    expect($outcome)->toBeInstanceOf(GitException::class);
    expect($outcome->getMessage())->toContain('Link header');
    expect($gitlab->count())->toBe(1);
});

test('the cap is 8 KB and public, like the Node twin', function () {
    expect(GitLabClient::MAX_LINK_HEADER_LENGTH)->toBe(8 * 1024);
});

test('parses a hostile header just under the 8 KB cap in under 50 ms', function () {
    // Under the cap the parser itself runs, so this is the test of the parser
    // rather than of the cap.
    $gitlab = new FakeGitLab([FakeGitLab::json([['id' => 1]], 200, ['Link' => str_repeat('<', 8 * 1024)])]);

    [$outcome, $elapsed] = timedGetAll($gitlab);

    expect($outcome)->toBe([['id' => 1]]);
    expect($elapsed)->toBeLessThan(LINK_BOUND_MS, sprintf('took %.0fms', $elapsed));
});

test('parses a hostile header of repeated parameters in under 50 ms', function () {
    $gitlab = new FakeGitLab([FakeGitLab::json([['id' => 1]], 200, ['Link' => '<'.LINK_API.'>'.str_repeat('; a', 2_000).'!'])]);

    [$outcome, $elapsed] = timedGetAll($gitlab);

    expect($outcome)->toBe([['id' => 1]]);
    expect($elapsed)->toBeLessThan(LINK_BOUND_MS, sprintf('took %.0fms', $elapsed));
});

// ---- a hostile next link, once parsed, is judged in linear time ---------

test('refuses a target just under the cap that would backtrack a URL split, in under 50 ms', function () {
    $target = 'https://'.str_repeat('a', 8 * 1024 - 40).'#';
    $gitlab = new FakeGitLab([FakeGitLab::json([['id' => 1]], 200, ['Link' => "<{$target}>; rel=\"next\""])]);

    [$outcome, $elapsed] = timedGetAll($gitlab);

    expect($outcome)->toBeInstanceOf(GitException::class);
    expect($outcome->getMessage())->toContain('pagination link');
    expect($elapsed)->toBeLessThan(LINK_BOUND_MS, sprintf('took %.0fms', $elapsed));
});

// ---- Link header parsing is unchanged ------------------------------------
// The fix replaces a regex with a scan, so what the regex accepted is pinned
// here. A parser that misses `next` ends pagination early, which is a wrong
// answer rather than a slow one.

const LINK_NEXT = LINK_API.'?id_after=10&pagination=keyset';

test('follows', function (string $link) {
    $gitlab = new FakeGitLab([FakeGitLab::json([['id' => 1]], 200, ['Link' => $link]), FakeGitLab::json([['id' => 2]])]);

    expect(linkClient($gitlab)->getAll('projects/1/pipelines'))->toBe([['id' => 1], ['id' => 2]]);
    expect((string) $gitlab->request(1)->getUri())->toBe(str_contains($link, 'a,b') ? LINK_NEXT.'&x=a,b' : LINK_NEXT);
})->with([
    'quoted rel' => ['<'.LINK_NEXT.'>; rel="next"'],
    'unquoted rel' => ['<'.LINK_NEXT.'>; rel=next'],
    'upper-case REL and NEXT' => ['<'.LINK_NEXT.'>; REL="NEXT"'],
    'spacing around the separators' => ['<'.LINK_NEXT.'>  ;  rel = "next"'],
    'next among several rels' => ['<'.LINK_NEXT.'>; rel="next last"'],
    'next after another link' => ['<'.LINK_API.'>; rel="first", <'.LINK_NEXT.'>; rel="next"'],
    'next before another link' => ['<'.LINK_NEXT.'>; rel="next", <'.LINK_API.'>; rel="last"'],
    'another parameter first' => ['<'.LINK_NEXT.'>; type="application/json"; rel="next"'],
    'a comma inside the target' => ['<'.LINK_NEXT.'&x=a,b>; rel="next"'],
]);

test('stops on', function (string $link) {
    $gitlab = new FakeGitLab([FakeGitLab::json([['id' => 1]], 200, ['Link' => $link])]);

    expect(linkClient($gitlab)->getAll('projects/1/pipelines'))->toBe([['id' => 1]]);
    expect($gitlab->count())->toBe(1);
})->with([
    'only prev and first' => ['<'.LINK_API.'>; rel="prev", <'.LINK_API.'>; rel="first"'],
    'no rel at all' => ['<'.LINK_NEXT.'>'],
    'a rel that merely contains next' => ['<'.LINK_NEXT.'>; rel="nextish"'],
    'an unclosed target' => ['<'.LINK_NEXT.'; rel="next"'],
]);

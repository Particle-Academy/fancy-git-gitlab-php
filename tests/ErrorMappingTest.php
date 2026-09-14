<?php

declare(strict_types=1);

namespace FancyGit\GitLab\Tests;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\GitLab\Tests\Support\FakeGitLab;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * GitLab's failures, as the contract's `GitException` + `GitErrorCode`.
 *
 * One exception type for local and hosted Git alike, so a caller writes one
 * `catch` and branches on `errorCode` — and a message that never carries the
 * credential the request was sent with.
 */
final class ErrorMappingTest extends TestCase
{
    private const REF = ['provider' => 'gitlab', 'owner' => 'acme', 'name' => 'app'];

    private function caught(FakeGitLab $gitlab, ?callable $call = null): GitException
    {
        try {
            ($call ?? fn () => $gitlab->provider()->repository(self::REF))();
        } catch (GitException $e) {
            self::assertStringNotContainsString(FakeGitLab::TOKEN, $e->getMessage());

            return $e;
        }

        self::fail('Expected a GitException.');
    }

    /** @return iterable<string, array{int, array<mixed>, GitErrorCode, string}> */
    public static function statuses(): iterable
    {
        yield '400 bad request' => [400, ['message' => '400 Bad request - state is invalid'], GitErrorCode::InvalidArgument, 'state is invalid'];
        yield '401 bad token' => [401, ['message' => '401 Unauthorized'], GitErrorCode::Auth, '401 Unauthorized'];
        // GitLab's real wording. The contract's `GitException::redact` blanks the
        // word after "Token", so the assertion reads the rest of the sentence.
        yield '401 oauth' => [401, ['error' => 'invalid_token', 'error_description' => 'Token is expired. You can either do re-authorization or token refresh.'], GitErrorCode::Auth, 'expired'];
        yield '403 scope' => [403, ['error' => 'insufficient_scope'], GitErrorCode::Auth, 'insufficient_scope'];
        yield '404 project' => [404, ['message' => '404 Project Not Found'], GitErrorCode::NotFound, '404 Project Not Found'];
        yield '405 disabled' => [405, ['message' => '405 Method Not Allowed'], GitErrorCode::Unsupported, 'Method Not Allowed'];
        yield '409 duplicate MR' => [409, ['message' => ['Another open merge request already exists for this source branch: !7']], GitErrorCode::Conflict, 'Another open merge request already exists'];
        yield '422 validation' => [422, ['message' => ['title' => ["can't be blank"], 'target_branch' => ['is invalid']]], GitErrorCode::InvalidArgument, '"title" can\'t be blank, "target_branch" is invalid'];
        yield '500' => [500, ['message' => '500 Internal Server Error'], GitErrorCode::Unknown, '500 Internal Server Error'];
        yield '502 html' => [502, [], GitErrorCode::Unknown, '502'];
    }

    #[DataProvider('statuses')]
    public function test_http_statuses_map_to_the_contract_error_codes(int $status, array $body, GitErrorCode $code, string $needle): void
    {
        $response = $body === []
            ? new Response($status, ['Content-Type' => 'text/html'], '<html>Bad Gateway</html>')
            : FakeGitLab::json($body, $status);

        $e = $this->caught(new FakeGitLab([$response]));

        self::assertSame($code, $e->errorCode);
        self::assertSame($status, $e->getCode());
        self::assertStringContainsString($needle, $e->getMessage());
    }

    public function test_rate_limiting_carries_retry_after(): void
    {
        $e = $this->caught(new FakeGitLab([FakeGitLab::json(['message' => '429 Too Many Requests'], 429, ['Retry-After' => '37'])]));

        self::assertSame(GitErrorCode::RateLimited, $e->errorCode);
        self::assertSame(429, $e->getCode());
        self::assertStringContainsString('Retry after 37 seconds', $e->getMessage());
    }

    public function test_rate_limiting_reads_an_http_date_retry_after(): void
    {
        $when = gmdate('D, d M Y H:i:s', time() + 120).' GMT';

        $e = $this->caught(new FakeGitLab([new Response(429, ['Retry-After' => $when], 'Retry later')]));

        self::assertSame(GitErrorCode::RateLimited, $e->errorCode);
        self::assertMatchesRegularExpression('/Retry after 1[12]\d seconds/', $e->getMessage());
    }

    public function test_a_redirect_is_refused_rather_than_followed_with_the_token(): void
    {
        // A redirect to another host would carry PRIVATE-TOKEN with it: Guzzle
        // strips `Authorization` on a cross-origin hop, but it has no idea that
        // GitLab's custom header is a credential.
        $gitlab = new FakeGitLab([
            new Response(302, ['Location' => 'https://attacker.example/steal']),
            FakeGitLab::json(['id' => 1]),
        ]);

        $e = $this->caught($gitlab);

        self::assertSame(GitErrorCode::Unknown, $e->errorCode);
        self::assertStringContainsString('redirect', $e->getMessage());
        self::assertSame(1, $gitlab->count());
    }

    public function test_a_transport_failure_is_a_git_exception_without_the_token(): void
    {
        $gitlab = new FakeGitLab([
            new ConnectException('cURL error 6: Could not resolve host: gitlab.com', new Request('GET', 'https://gitlab.com/api/v4/projects/acme%2Fapp')),
        ]);

        $e = $this->caught($gitlab);

        self::assertSame(GitErrorCode::Unknown, $e->errorCode);
        self::assertStringContainsString('Could not resolve host', $e->getMessage());
    }

    public function test_a_success_that_is_not_json_is_reported_not_mapped(): void
    {
        // What a base URL pointing at an SSO login page, or at the wrong site,
        // looks like from here.
        $e = $this->caught(new FakeGitLab([new Response(200, ['Content-Type' => 'text/html'], '<html>Sign in</html>')]));

        self::assertSame(GitErrorCode::Unknown, $e->errorCode);
        self::assertStringContainsString('not JSON', $e->getMessage());
    }

    public function test_an_error_body_echoing_the_token_is_redacted(): void
    {
        $e = $this->caught(new FakeGitLab([FakeGitLab::json(['message' => 'token '.FakeGitLab::TOKEN.' is expired'], 401)]));

        self::assertSame(GitErrorCode::Auth, $e->errorCode);
    }
}

<?php

declare(strict_types=1);

namespace FancyGit\GitLab\Tests\Support;

use FancyGit\GitLab\GitLabClient;
use FancyGit\GitLab\GitLabProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A GitLab that answers from a queue and records every request it was sent.
 *
 * The transport is a real Guzzle client with a MockHandler in place of the
 * network, so every request passes through the same middleware stack it would
 * in production — nothing is mocked above the HTTP layer, and nothing leaves
 * the machine. When the queue runs dry the MockHandler throws, so a test that
 * sends one request more than it expects fails instead of passing quietly.
 */
final class FakeGitLab
{
    /**
     * A token of the real `glpat-` shape, so redaction is tested against what it
     * will actually meet. Assembled from two halves because GitHub push
     * protection rightly rejects a commit containing anything shaped like a
     * GitLab token — this one is fake, and splitting it keeps the scanner useful
     * rather than teaching anyone to click through its bypass.
     */
    public const TOKEN = 'glpat'.'-FAKEfakeFAKEfake1234';

    public readonly MockHandler $mock;

    /**
     * A recorder OBJECT, not a by-reference array: rebinding a by-ref parameter
     * severs it from the caller's variable, and the sibling GitHub suite once
     * spent three tests asserting against the empty array that left behind.
     *
     * @var \ArrayObject<int, array{request: RequestInterface, options: array<string, mixed>}>
     */
    public readonly \ArrayObject $history;

    /** @param list<Response|\Throwable> $responses */
    public function __construct(array $responses = [])
    {
        $this->mock = new MockHandler($responses);
        $this->history = new \ArrayObject;
    }

    /** @param array<mixed>|list<mixed> $body */
    public static function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function guzzle(array $config = []): Client
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack] + $config);
    }

    /**
     * The ONE place the suite knows how a provider is wired to its transport.
     *
     * Everything else asserts on HTTP requests and normalized return values,
     * which is what lets the same tests run against the adapter before and
     * after its GitLab client was replaced.
     */
    public function provider(string $baseUrl = 'https://gitlab.com', ?string $token = self::TOKEN): GitLabProvider
    {
        return new GitLabProvider(new GitLabClient($baseUrl, $token, http: $this->guzzle()));
    }

    public function request(int $index = 0): RequestInterface
    {
        if (! isset($this->history[$index])) {
            throw new \LogicException("No request #{$index} was sent; ".count($this->history).' recorded.');
        }

        return $this->history[$index]['request'];
    }

    /** @return array<string, mixed> */
    public function options(int $index = 0): array
    {
        return $this->history[$index]['options'];
    }

    /** @return array<string, string> */
    public function query(int $index = 0): array
    {
        parse_str($this->request($index)->getUri()->getQuery(), $query);

        return $query;
    }

    public function count(): int
    {
        return count($this->history);
    }
}

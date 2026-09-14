<?php

declare(strict_types=1);

namespace FancyGit\GitLab;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;

/**
 * One decoded GitLab REST response.
 *
 * Deliberately carries no URLs. Offset pagination is expressed as a page
 * NUMBER, which a caller sends back through {@see GitLabClient::get()} against
 * the configured instance; keyset pagination, which only GitLab's `Link` header
 * can express, is walked inside {@see GitLabClient::getAll()}, where the link is
 * checked against the instance before it is followed.
 */
final readonly class GitLabResponse
{
    /**
     * @param  array<mixed>  $data  the decoded JSON body
     * @param  string|null  $nextPage  `X-Next-Page`, or null on the last page
     * @param  int|null  $total  `X-Total`, which GitLab omits for large collections
     */
    public function __construct(
        public int $status,
        public array $data,
        public ?string $nextPage = null,
        public ?int $total = null,
        private string $describe = 'the request',
    ) {}

    /** @return list<array<mixed>> */
    public function items(): array
    {
        if (! array_is_list($this->data)) {
            throw new GitException(GitErrorCode::Unknown, "GitLab answered {$this->describe} with a JSON object where a list was expected.", $this->status);
        }

        return $this->data;
    }
}

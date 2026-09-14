<?php

declare(strict_types=1);

namespace FancyGit\GitLab;

use FancyGit\Error\GitErrorCode;
use FancyGit\Error\GitException;
use FancyGit\Provider\GitProvider;

final class GitLabProvider implements GitProvider
{
    public function __construct(private readonly GitLabClient $client) {}

    /**
     * @param  string  $baseUrl  the instance URL, e.g. https://gitlab.example.com
     */
    public static function withToken(#[\SensitiveParameter] string $token, string $baseUrl = GitLabClient::GITLAB_COM): self
    {
        return new self(new GitLabClient($baseUrl, $token));
    }

    public function kind(): string { return 'gitlab'; }

    public function identify(array $remote): ?array
    {
        $baseUrl = $this->client->baseUrl();

        if (! preg_match('#^(?:https?://|ssh://git@|git@)([^/:]+)[:/](.+?)/([^/]+?)(?:\.git)?$#', $remote['fetchUrl'], $match)
            || $match[1] !== parse_url($baseUrl, PHP_URL_HOST)) {
            return null;
        }

        $owner = $match[2];

        // Under a relative URL root (https://example.com/gitlab) an https remote
        // carries the root in its path and an ssh remote does not. The root is
        // part of the instance, not of the namespace.
        $root = ltrim((string) parse_url($baseUrl, PHP_URL_PATH), '/');
        if ($root !== '' && preg_match('#^https?://#i', $remote['fetchUrl']) === 1) {
            if (! str_starts_with($owner, $root.'/')) {
                return null;
            }
            $owner = substr($owner, strlen($root) + 1);
        }

        return array_filter(['provider' => 'gitlab', 'owner' => $owner, 'name' => $match[3], 'baseUrl' => $baseUrl === GitLabClient::GITLAB_COM ? null : $baseUrl]);
    }

    public function repository(array $ref): array
    {
        $data = $this->client->get($this->project($ref))->data;

        return ['provider' => 'gitlab', 'owner' => $ref['owner'], 'name' => $ref['name'], 'id' => (string) $data['id'], 'webUrl' => $data['web_url'], 'defaultBranch' => $data['default_branch'], 'private' => $data['visibility'] !== 'public', 'description' => $data['description'] ?? null];
    }

    public function listReviews(array $ref, array $query = []): array
    {
        $state = match ($query['state'] ?? null) { 'merged' => 'merged', 'closed' => 'closed', default => 'opened' };
        $params = ['state' => $state, 'per_page' => max(1, min(GitLabClient::MAX_PER_PAGE, (int) ($query['limit'] ?? 30)))];
        if (isset($query['cursor'])) {
            $params['page'] = self::cursor($query['cursor']);
        }

        $response = $this->client->get($this->project($ref).'/merge_requests', $params);

        $page = ['items' => array_map($this->mapReview(...), $response->items())];
        if ($response->nextPage !== null) {
            $page['nextCursor'] = $response->nextPage;
        }
        if ($response->total !== null) {
            $page['total'] = $response->total;
        }

        return $page;
    }

    public function getReview(array $ref, int $number): array
    {
        if ($number < 1) {
            throw self::invalid("A merge request number is a positive integer; got {$number}.");
        }

        $data = $this->client->get($this->project($ref).'/merge_requests/'.$number)->data;

        return $this->mapReview($data) + ['body' => $data['description'] ?? null, 'mergeable' => ($data['merge_status'] ?? null) === 'can_be_merged', 'createdAt' => $data['created_at'], 'updatedAt' => $data['updated_at']];
    }

    public function createReview(array $ref, array $input): array
    {
        $body = [
            'source_branch' => self::required($input, 'sourceBranch'),
            'target_branch' => self::required($input, 'targetBranch'),
            'title' => self::required($input, 'title'),
        ];
        if (($input['body'] ?? null) !== null) {
            $body['description'] = (string) $input['body'];
        }

        return $this->mapReview($this->client->post($this->project($ref).'/merge_requests', $body)->data);
    }

    public function compare(array $ref, string $base, string $head): array
    {
        if (trim($base) === '' || trim($head) === '') {
            throw self::invalid('compare needs a non-empty base and head.');
        }

        $data = $this->client->get($this->project($ref).'/repository/compare', ['from' => $base, 'to' => $head, 'straight' => 'false'])->data;

        return ['aheadBy' => count($data['commits'] ?? []), 'behindBy' => 0, 'commits' => array_map(static fn (array $commit): array => ['id' => $commit['id'], 'shortId' => $commit['short_id'], 'parents' => $commit['parent_ids'] ?? [], 'authorName' => $commit['author_name'], 'authorEmail' => $commit['author_email'], 'authoredAt' => $commit['authored_date'], 'subject' => $commit['title']], $data['commits'] ?? [])];
    }

    public function checks(array $ref, string $revision): array
    {
        if (trim($revision) === '') {
            throw self::invalid('checks needs a non-empty revision.');
        }

        $items = $this->client->getAll($this->project($ref).'/pipelines', ['sha' => $revision]);

        return array_map(static fn (array $pipeline): array => ['id' => (string) $pipeline['id'], 'name' => 'Pipeline #'.$pipeline['id'], 'state' => self::checkState($pipeline['status']), 'webUrl' => $pipeline['web_url'] ?? null, 'startedAt' => $pipeline['created_at'] ?? null, 'completedAt' => $pipeline['updated_at'] ?? null], $items);
    }

    /**
     * The project path for a ref — refusing a ref that belongs to another instance.
     *
     * Without that check, a ref identified against gitlab.example.com and handed
     * to the gitlab.com provider would send THIS instance's credentials to
     * whatever project has the same path here, and createReview would open a
     * merge request on it.
     */
    private function project(array $ref): string
    {
        $theirs = $ref['baseUrl'] ?? null;
        if ($theirs !== null && $theirs !== '') {
            $theirs = GitLabClient::normalizeBaseUrl(is_string($theirs) ? $theirs : '');
            if ($theirs !== $this->client->baseUrl()) {
                throw self::invalid("This ref belongs to {$theirs}, but this GitLabProvider talks to {$this->client->baseUrl()}. Use a provider configured for that instance.");
            }
        }

        return GitLabClient::projectPath(
            is_string($ref['owner'] ?? null) ? $ref['owner'] : '',
            is_string($ref['name'] ?? null) ? $ref['name'] : '',
        );
    }

    private static function cursor(mixed $cursor): string
    {
        if ((! is_string($cursor) && ! is_int($cursor)) || preg_match('/^[1-9][0-9]{0,9}$/', (string) $cursor) !== 1) {
            throw self::invalid('A GitLab review cursor is the page number returned as nextCursor.');
        }

        return (string) $cursor;
    }

    private static function required(array $input, string $key): string
    {
        if (! is_string($input[$key] ?? null) || trim($input[$key]) === '') {
            throw self::invalid("createReview needs a non-empty {$key}.");
        }

        return $input[$key];
    }

    private static function invalid(string $message): GitException
    {
        return new GitException(GitErrorCode::InvalidArgument, $message);
    }

    private function mapReview(array $item): array
    {
        $state = $item['state'] === 'merged' ? 'merged' : ($item['state'] === 'opened' ? (($item['draft'] ?? false) ? 'draft' : 'open') : 'closed');
        return ['id' => (string) $item['id'], 'number' => $item['iid'], 'title' => $item['title'], 'state' => $state, 'webUrl' => $item['web_url'], 'sourceBranch' => $item['source_branch'], 'targetBranch' => $item['target_branch'], 'author' => $item['author']['username'] ?? 'unknown'];
    }

    private static function checkState(string $status): string
    {
        return match ($status) { 'created', 'pending', 'waiting_for_resource', 'preparing' => 'queued', 'running' => 'running', 'success' => 'passed', 'canceled' => 'cancelled', 'skipped' => 'skipped', 'failed' => 'failed', default => 'unknown' };
    }
}

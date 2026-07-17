<?php

declare(strict_types=1);

namespace FancyGit\GitLab;

use FancyGit\Provider\GitProvider;
use Gitlab\Client;

final class GitLabProvider implements GitProvider
{
    public function __construct(private readonly Client $client, private readonly string $baseUrl = 'https://gitlab.com') {}

    public static function withToken(string $token, string $baseUrl = 'https://gitlab.com'): self
    {
        $client = new Client;
        $client->setUrl(rtrim($baseUrl, '/').'/api/v4/');
        $client->authenticate($token, Client::AUTH_HTTP_TOKEN);

        return new self($client, rtrim($baseUrl, '/'));
    }

    public function kind(): string { return 'gitlab'; }

    public function identify(array $remote): ?array
    {
        if (! preg_match('#^(?:https?://|ssh://git@|git@)([^/:]+)[:/](.+?)/([^/]+?)(?:\.git)?$#', $remote['fetchUrl'], $match)
            || $match[1] !== parse_url($this->baseUrl, PHP_URL_HOST)) {
            return null;
        }

        return array_filter(['provider' => 'gitlab', 'owner' => $match[2], 'name' => $match[3], 'baseUrl' => $this->baseUrl === 'https://gitlab.com' ? null : $this->baseUrl]);
    }

    public function repository(array $ref): array
    {
        $data = $this->client->api('projects')->show($this->project($ref));

        return ['provider' => 'gitlab', 'owner' => $ref['owner'], 'name' => $ref['name'], 'id' => (string) $data['id'], 'webUrl' => $data['web_url'], 'defaultBranch' => $data['default_branch'], 'private' => $data['visibility'] !== 'public', 'description' => $data['description'] ?? null];
    }

    public function listReviews(array $ref, array $query = []): array
    {
        $state = match ($query['state'] ?? null) { 'merged' => 'merged', 'closed' => 'closed', default => 'opened' };
        $items = $this->client->api('merge_requests')->all($this->project($ref), ['state' => $state, 'per_page' => $query['limit'] ?? 30]);

        return ['items' => array_map($this->mapReview(...), $items)];
    }

    public function getReview(array $ref, int $number): array
    {
        $data = $this->client->api('merge_requests')->show($this->project($ref), $number);

        return $this->mapReview($data) + ['body' => $data['description'] ?? null, 'mergeable' => ($data['merge_status'] ?? null) === 'can_be_merged', 'createdAt' => $data['created_at'], 'updatedAt' => $data['updated_at']];
    }

    public function createReview(array $ref, array $input): array
    {
        return $this->mapReview($this->client->api('merge_requests')->create($this->project($ref), $input['sourceBranch'], $input['targetBranch'], $input['title'], ['description' => $input['body'] ?? null]));
    }

    public function compare(array $ref, string $base, string $head): array
    {
        $data = $this->client->api('repositories')->compare($this->project($ref), $base, $head);

        return ['aheadBy' => count($data['commits'] ?? []), 'behindBy' => 0, 'commits' => array_map(static fn (array $commit): array => ['id' => $commit['id'], 'shortId' => $commit['short_id'], 'parents' => $commit['parent_ids'] ?? [], 'authorName' => $commit['author_name'], 'authorEmail' => $commit['author_email'], 'authoredAt' => $commit['authored_date'], 'subject' => $commit['title']], $data['commits'] ?? [])];
    }

    public function checks(array $ref, string $revision): array
    {
        $items = $this->client->api('pipelines')->all($this->project($ref), ['sha' => $revision]);

        return array_map(static fn (array $pipeline): array => ['id' => (string) $pipeline['id'], 'name' => 'Pipeline #'.$pipeline['id'], 'state' => self::checkState($pipeline['status']), 'webUrl' => $pipeline['web_url'] ?? null, 'startedAt' => $pipeline['created_at'] ?? null, 'completedAt' => $pipeline['updated_at'] ?? null], $items);
    }

    private function project(array $ref): string { return rawurlencode($ref['owner'].'/'.$ref['name']); }

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

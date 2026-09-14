<?php

declare(strict_types=1);

namespace FancyGit\GitLab\Tests;

use FancyGit\GitLab\Tests\Support\FakeGitLab;
use PHPUnit\Framework\TestCase;

final class GitLabProviderTest extends TestCase
{
    public function test_it_identifies_nested_namespaces(): void
    {
        self::assertSame(['provider' => 'gitlab', 'owner' => 'group/team', 'name' => 'app'], (new FakeGitLab)->provider(token: null)->identify(['name' => 'origin', 'fetchUrl' => 'git@gitlab.com:group/team/app.git']));
    }
}

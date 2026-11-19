<?php

declare(strict_types=1);

namespace FancyGit\GitLab\Tests;

use FancyGit\GitLab\Tests\Support\FakeGitLab;

test('it identifies nested namespaces', function () {
    self::assertSame(['provider' => 'gitlab', 'owner' => 'group/team', 'name' => 'app'], (new FakeGitLab)->provider(token: null)->identify(['name' => 'origin', 'fetchUrl' => 'git@gitlab.com:group/team/app.git']));
});

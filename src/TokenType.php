<?php

declare(strict_types=1);

namespace FancyGit\GitLab;

/**
 * How a GitLab credential is presented. Each kind has its own header, and
 * GitLab rejects a token sent under the wrong one.
 */
enum TokenType: string
{
    /** Personal, project and group access tokens (`glpat-…`). Sent as `PRIVATE-TOKEN`. */
    case AccessToken = 'access_token';

    /** An OAuth 2.0 access token. Sent as `Authorization: Bearer`. */
    case OAuth = 'oauth';

    /**
     * A CI/CD job token (`CI_JOB_TOKEN`). Sent as `JOB-TOKEN`. GitLab accepts it
     * on a small subset of endpoints only, so most provider calls will answer
     * with a 401 or 403 — that is GitLab's policy, not a fault here.
     */
    case CiJob = 'ci_job';

    /** @return array<string, string> */
    public function headers(#[\SensitiveParameter] string $token): array
    {
        return match ($this) {
            self::AccessToken => ['PRIVATE-TOKEN' => $token],
            self::OAuth => ['Authorization' => 'Bearer '.$token],
            self::CiJob => ['JOB-TOKEN' => $token],
        };
    }
}

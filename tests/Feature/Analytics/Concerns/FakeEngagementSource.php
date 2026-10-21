<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Contracts\EngagementSource;
use App\Exceptions\Analytics\EngagementSourceException;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Support\Analytics\Engagement\AccountMetricsReading;
use App\Support\Analytics\Engagement\PostMetricsReading;

/**
 * Hands out queued readings in order and records which channels / accounts were read.
 */
class FakeEngagementSource implements EngagementSource
{
    /** @var list<int> */
    public array $postCalls = [];

    /** @var list<int> */
    public array $accountCalls = [];

    /**
     * @param  list<PostMetricsReading|null>  $posts
     * @param  list<AccountMetricsReading|null>  $accounts
     */
    public function __construct(private array $posts = [], private array $accounts = [], private ?string $error = null) {}

    public function key(): string
    {
        return 'fake';
    }

    public function postMetrics(PostTarget $target): ?PostMetricsReading
    {
        $this->postCalls[] = $target->id;

        if ($this->error !== null) {
            throw new EngagementSourceException($this->error);
        }

        return array_shift($this->posts);
    }

    public function accountMetrics(SocialAccount $account): ?AccountMetricsReading
    {
        $this->accountCalls[] = $account->id;

        if ($this->error !== null) {
            throw new EngagementSourceException($this->error);
        }

        return array_shift($this->accounts);
    }
}

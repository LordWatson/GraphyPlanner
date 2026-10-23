<?php

namespace App\Contracts;

use App\Models\Client;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\IdeaContext;

/**
 * Step B-6.1 — the local/seasonal idea engine, kept as its own contract (bound in a Service Provider
 * the same way {@see AiProvider}, {@see CampaignAiProvider} and {@see GridAiProvider} are) so the
 * business shell can depend on it without the agency {@see AiProvider::recommendPosts()} path — or
 * its many test doubles — changing at all.
 *
 * Implementations are grounded in the same Brand Brain context the other methods use, plus the
 * {@see IdeaContext} (location, season, upcoming local dates, recent posts to avoid repeating). They
 * never throw: a disabled/failed provider returns empty ideas so a "More ideas" tap never dead-ends.
 */
interface LocalIdeaProvider
{
    public function recommendLocalPosts(Client $client, IdeaContext $context): AiPostIdeas;
}

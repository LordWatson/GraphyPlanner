<?php

namespace App\Actions\AI;

use App\Contracts\LocalIdeaProvider;
use App\Models\Client;
use App\Services\AI\AiTextUsage;
use App\Services\AI\DeepSeekProvider;
use App\Services\AI\IdeaContextBuilder;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\IdeaContext;
use Illuminate\Support\Facades\Log;

/**
 * Step B-6.1 — the business shell's idea generator behind the "More ideas" button and the nightly
 * {@see GenerateDailyIdeaAction}. It wraps the {@see LocalIdeaProvider} call with everything that
 * must happen around it, in one place so the controller stays thin:
 *  1. build the brand's local/seasonal {@see IdeaContext} ({@see IdeaContextBuilder});
 *  2. ask the provider for ideas grounded in it;
 *  3. guardrail-filter and upsert the survivors (reusing {@see RecommendPostsAction::filterAndPersist()});
 *  4. record one `ai_usage_logs` row for the B-9.2 allowance.
 *
 * Never throws for an AI failure — a disabled/failed provider yields empty ideas, which the page
 * shows as "no ideas yet", never a broken screen.
 */
class GenerateBrandIdeasAction
{
    public function __construct(
        private readonly LocalIdeaProvider $provider,
        private readonly IdeaContextBuilder $contextBuilder,
        private readonly RecommendPostsAction $recommendPosts,
        private readonly AiTextUsage $usage,
    ) {}

    public function __invoke(Client $brand): AiPostIdeas
    {
        Log::info('Business brand ideas requested', ['org_id' => $brand->org_id, 'client_id' => $brand->id]);

        $context = $this->contextBuilder->build($brand);

        $startedAt = microtime(true);
        $ideas = $this->provider->recommendLocalPosts($brand, $context);

        $deepSeek = $this->provider instanceof DeepSeekProvider;
        $this->usage->record(
            $brand,
            'recommendLocalPosts',
            $ideas->ideas !== [],
            $startedAt,
            $deepSeek ? 'deepseek' : 'null',
            $deepSeek ? (string) config('ai.deepseek.model', 'deepseek-chat') : 'none',
            $deepSeek ? (float) config('ai.deepseek.estimated_cost_per_call', 0) : 0.0,
        );

        return $this->recommendPosts->filterAndPersist($brand, $ideas);
    }
}

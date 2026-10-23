<?php

namespace App\Jobs;

use App\Actions\AI\DiscoverTrendsAction;
use App\Actions\AI\RecommendPostsAction;
use App\Actions\AI\SuggestCaptionsForPostAction;
use App\Enums\AiTextJobKind;
use App\Enums\AiTextJobStatus;
use App\Enums\Platform;
use App\Exceptions\AI\AiBudgetExceededException;
use App\Models\AiTextJob;
use App\Services\AI\AiGuardrailService;
use App\Services\AI\BrandFitScorer;
use App\Support\AI\AiCaptionSuggestions;
use App\Support\AI\AiPostIdeas;
use App\Support\AI\AiTrends;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step M-7.2 (dev plan I-9.2) — runs one agency text AI task off the request thread so vendor
 * latency never blocks the browser or trips a gateway timeout. Loads the {@see AiTextJob}, calls the
 * same Action the sync endpoint used, and stores the finished payload for the client's poll. A job
 * the user cancelled before it starts is skipped; a provider failure (including an over-budget
 * refusal) is recorded as `failed` with a human message, never left hanging.
 */
class RunAiTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $jobId)
    {
        // Step I-2.8: text AI has its own queue/workers, so it never sits behind a publish or report.
        $this->onQueue(Queues::AI);
    }

    public function handle(
        SuggestCaptionsForPostAction $captions,
        DiscoverTrendsAction $trends,
        RecommendPostsAction $ideas,
        AiGuardrailService $guardrail,
        BrandFitScorer $brandFit,
    ): void {
        $job = AiTextJob::query()->find($this->jobId);

        if ($job === null || $job->status !== AiTextJobStatus::Queued) {
            return; // Already running, settled, or cancelled before we started.
        }

        $job->update(['status' => AiTextJobStatus::Running]);

        try {
            $result = match ($job->kind) {
                AiTextJobKind::Captions => $this->captions($job, $captions, $guardrail, $brandFit),
                AiTextJobKind::Trends => $this->trends($job, $trends),
                AiTextJobKind::Ideas => $this->ideas($job, $ideas),
            };

            // A cancel that landed while we were calling the vendor wins — don't resurrect the job.
            if ($job->fresh()?->status === AiTextJobStatus::Cancelled) {
                return;
            }

            $job->update([
                'status' => AiTextJobStatus::Completed,
                'result' => json_decode((string) json_encode($result), true),
            ]);
        } catch (Throwable $e) {
            $message = $e instanceof AiBudgetExceededException
                ? $e->getMessage()
                : 'AI couldn’t finish that just now. Please try again.';

            Log::warning('AI text job failed', [
                'ai_text_job_id' => $job->id,
                'kind' => $job->kind->value,
                'org_id' => $job->org_id,
                'exception' => $e::class,
            ]);

            $job->update(['status' => AiTextJobStatus::Failed, 'error' => $message]);
        }
    }

    /**
     * @return array{suggestions: AiCaptionSuggestions, policyWarnings: array<int, array<int, string>>, brandFit: array<int, array{score: int, reason: string}>}
     */
    private function captions(AiTextJob $job, SuggestCaptionsForPostAction $action, AiGuardrailService $guardrail, BrandFitScorer $brandFit): array
    {
        $post = $job->post;

        if ($post === null) {
            return ['suggestions' => new AiCaptionSuggestions, 'policyWarnings' => [], 'brandFit' => []];
        }

        $post->loadMissing('targets.socialAccount', 'client.brandBrain');
        $suggestions = $action($post);
        $platforms = $post->targetPlatforms();
        $brain = $post->client?->brandBrain;

        $policyWarnings = collect($suggestions->suggestions)
            ->map(fn ($suggestion) => collect($platforms)
                ->flatMap(fn (Platform $platform) => $guardrail->platformWarnings($platform, $suggestion))
                ->values()
                ->all())
            ->values()
            ->all();

        // Step M-7.4: a 0–100 brand-fit score + reason per suggestion for the editor's badge.
        $scores = array_map(fn ($suggestion) => $brandFit->score($brain, $suggestion), $suggestions->suggestions);

        return ['suggestions' => $suggestions, 'policyWarnings' => $policyWarnings, 'brandFit' => $scores];
    }

    /**
     * @return array{trends: AiTrends}
     */
    private function trends(AiTextJob $job, DiscoverTrendsAction $action): array
    {
        $client = $job->client;

        if ($client === null) {
            return ['trends' => new AiTrends];
        }

        $platforms = array_values(array_filter(array_map(
            fn ($value) => Platform::tryFrom((string) $value)?->value,
            (array) ($job->params['platforms'] ?? []),
        )));

        return ['trends' => $action($client, $platforms)];
    }

    /**
     * @return array{ideas: AiPostIdeas}
     */
    private function ideas(AiTextJob $job, RecommendPostsAction $action): array
    {
        $client = $job->client;

        if ($client === null) {
            return ['ideas' => new AiPostIdeas];
        }

        return ['ideas' => $action($client)];
    }
}

<?php

namespace App\Jobs;

use App\Actions\AI\Images\PrepareImageGenerationAction;
use App\Models\AiImageGeneration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Step 16.7: drafts a set's brief and fans its variants out, on the dedicated `ai-images` queue so
 * image work never delays PublishPostJob. Dispatched after the generation's transaction commits.
 */
class PrepareImageGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public readonly int $generationId)
    {
        $this->onQueue((string) config('ai.images.queue', 'ai-images'));
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'generation:'.$this->generationId;
    }

    public function handle(PrepareImageGenerationAction $action): void
    {
        $generation = AiImageGeneration::find($this->generationId);

        if ($generation === null) {
            Log::warning('PrepareImageGenerationJob: generation not found', ['generation_id' => $this->generationId]);

            return;
        }

        $action($generation);
    }
}

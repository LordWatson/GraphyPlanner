<?php

namespace App\Actions\AI\Images;

use App\Enums\ImageVariantKind;
use App\Models\AiImageVariant;
use App\Models\User;
use App\Services\AI\AiGuardrailService;
use App\Support\AI\Images\AiImageBrief;
use Illuminate\Support\Str;

/**
 * Step 16.8: a short plain-words instruction ("warmer light", "remove the cup") applied to a
 * ready variant through ImageProvider::edit(); the result is a new child variant in the same set
 * at the parent's tier. The instruction is PII-scrubbed and stripped of the brand's banned phrases.
 */
class RefineImageVariantAction
{
    public function __construct(
        private readonly CreateFollowUpVariantAction $followUp,
        private readonly AiGuardrailService $guardrail,
    ) {}

    public function __invoke(AiImageVariant $variant, User $user, string $instruction, string $requestId): AiImageVariant
    {
        $brief = $this->guardrail->filterImageBrief(
            $variant->generation->client->brandBrain,
            new AiImageBrief(subject: Str::limit(trim($instruction), 300, '')),
            ['org_id' => $variant->org_id, 'variant_id' => $variant->id],
        );

        return ($this->followUp)($variant, $user, ImageVariantKind::Refine, $requestId, $variant->quality_tier, [
            'instruction' => $brief->subject,
        ]);
    }
}

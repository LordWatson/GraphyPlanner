<?php

namespace App\Http\Controllers;

use App\Actions\AI\RecordAiFeedbackAction;
use App\Enums\AiRating;
use App\Models\AiPostIdea;
use App\Models\AiTrend;
use App\Models\Post;
use App\Services\AI\AiAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Step M-7.1 (dev plan Step 2.9) — records a user's thumbs up/down on an AI suggestion so the team
 * can measure how helpful AI is per client and tune prompts over time. Thin: it gates (AI enabled +
 * Owner/Strategist in the subject's own org, plus the usual `view` policy on the owning resource)
 * *before* validating, then delegates to {@see RecordAiFeedbackAction}, which is idempotent per
 * user/subject. Validation is inline (not a FormRequest) so a cross-tenant request is refused on
 * authorization before any body is inspected.
 */
class AiFeedbackController extends Controller
{
    public function __construct(
        private readonly AiAccess $access,
        private readonly RecordAiFeedbackAction $record,
    ) {}

    public function trend(Request $request, AiTrend $aiTrend): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorize('view', $aiTrend->client);

        return $this->respond($aiTrend, $request);
    }

    public function idea(Request $request, AiPostIdea $aiPostIdea): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorize('view', $aiPostIdea->client);

        return $this->respond($aiPostIdea, $request);
    }

    public function caption(Request $request, Post $post): JsonResponse
    {
        $this->access->authorize($request->user());
        $this->authorize('view', $post);

        return $this->respond($post, $request);
    }

    private function respond(Model $subject, Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Only the explicit thumbs are accepted; `Applied` is an implicit, server-only signal.
            'rating' => ['required', Rule::in([AiRating::Up->value, AiRating::Down->value])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $feedback = ($this->record)(
            $subject,
            $request->user(),
            AiRating::from($validated['rating']),
            $validated['reason'] ?? null,
        );

        return response()->json(['rating' => $feedback->rating->value]);
    }
}

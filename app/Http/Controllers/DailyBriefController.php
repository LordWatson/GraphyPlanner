<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Client;
use App\Models\DailyBrief;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller behind the Clients module's "Daily Briefs" tab (Step 2.5), listing the
 * client-scoped `DailyBrief` rows generated nightly by `GenerateDailyBriefsAction`. Gated the
 * same way as `AiController`: 404 when AI is disabled, 403 unless Owner/Strategist in the
 * client's own organization.
 */
class DailyBriefController extends Controller
{
    /**
     * List a client's Daily Briefs, optionally filtered by date range/pillar/campaign.
     */
    public function index(Request $request, Client $client): JsonResponse
    {
        abort_unless((bool) config('ai.enabled', false), 404);
        $this->authorize('view', $client);
        $this->authorizeAiUse($request, $client->org_id);

        $query = DailyBrief::query()
            ->where('client_id', $client->id)
            ->orderByDesc('brief_date');

        if ($from = $request->query('from')) {
            $query->whereDate('brief_date', '>=', $from);
        }

        if ($to = $request->query('to')) {
            $query->whereDate('brief_date', '<=', $to);
        }

        $briefs = $query->get();

        $pillar = $request->query('pillar');
        $campaign = $request->query('campaign');

        if ($pillar || $campaign) {
            $briefs = $briefs->filter(function (DailyBrief $brief) use ($pillar, $campaign) {
                $text = strtolower(collect($brief->ideas ?? [])
                    ->map(fn (array $idea) => ($idea['title'] ?? '').' '.($idea['description'] ?? ''))
                    ->implode(' '));

                if ($pillar && ! str_contains($text, strtolower((string) $pillar))) {
                    return false;
                }

                if ($campaign && ! str_contains($text, strtolower((string) $campaign))) {
                    return false;
                }

                return true;
            })->values();
        }

        return response()->json([
            'briefs' => $briefs->map(fn (DailyBrief $brief) => $this->transform($brief)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(DailyBrief $brief): array
    {
        return [
            'id' => $brief->id,
            'brief_date' => $brief->brief_date->toDateString(),
            'trends' => $brief->trends ?? [],
            'ideas' => $brief->ideas ?? [],
            'notified_at' => $brief->notified_at?->toIso8601String(),
        ];
    }

    /**
     * AI features are Owner/Strategist only by default (mirrors AiController::authorizeAiUse()).
     */
    private function authorizeAiUse(Request $request, int $orgId): void
    {
        $user = $request->user();

        abort_unless(
            $user && $user->org_id === $orgId && in_array($user->role, [Role::Owner, Role::Strategist], true),
            403,
        );
    }
}

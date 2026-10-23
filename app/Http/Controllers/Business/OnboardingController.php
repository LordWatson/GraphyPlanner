<?php

namespace App\Http\Controllers\Business;

use App\Actions\AI\DraftBrandProfileAction;
use App\Actions\BrandBrains\UpdateBrandBrainAction;
use App\Actions\Clients\UpdateClientAction;
use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\DraftBrandProfileRequest;
use App\Http\Requests\Business\SaveBrandVoiceRequest;
use App\Http\Requests\Business\UpdateBusinessBrandRequest;
use App\Models\Client;
use App\Models\Organization;
use App\Models\SocialAccount;
use App\Services\Publishing\PlatformCapabilities;
use App\Services\Workspace\CurrentWorkspace;
use App\Services\Workspace\OnboardingProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step B-2.2 / B-2.3 — the guided setup a business/creator workspace lands on after signup
 * (`/welcome-setup`). Thin by design: every step calls an existing Action (`UpdateClientAction`,
 * `DraftBrandProfileAction`, `UpdateBrandBrainAction`) with the brand resolved implicitly from
 * `CurrentWorkspace`. The checklist is computed from real data by `OnboardingProgress`; this
 * controller only records the "finished / skipped" decision on `onboarding_completed_at`.
 */
class OnboardingController extends Controller
{
    public function show(Request $request, CurrentWorkspace $workspace, OnboardingProgress $progress, PlatformCapabilities $platforms): Response
    {
        $brand = $workspace->brand();
        $organization = $workspace->organization();
        $brand?->loadMissing('brandBrain');

        return Inertia::render('business/onboarding', [
            'brandId' => $brand?->id,
            'brand' => $this->brandProps($brand),
            'voice' => $this->voiceProps($brand),
            'progress' => $progress->for($organization),
            'platforms' => array_map(fn ($platform) => [
                'value' => $platform->value,
                'label' => $platform->label(),
            ], $platforms->enabledPlatforms()),
            'accounts' => $this->accountProps($brand),
            'defaultTimezone' => $organization->default_timezone,
            'aiEnabled' => (bool) config('ai.enabled', false),
        ]);
    }

    public function updateBrand(UpdateBusinessBrandRequest $request, CurrentWorkspace $workspace, UpdateClientAction $update): RedirectResponse
    {
        $update($workspace->brand(), $request->brandData());

        return back();
    }

    /**
     * Step B-2.4 — the AI quick-start. Deliberately *not* gated behind `config('ai.enabled')`: when
     * AI is off (or the vendor fails), the action returns an empty draft, which the wizard shows as
     * a friendly editable blank form rather than a blocked step. Nothing is persisted here.
     */
    public function draftProfile(DraftBrandProfileRequest $request, CurrentWorkspace $workspace, DraftBrandProfileAction $action): JsonResponse
    {
        $draft = $action($workspace->brand(), $request->draftInput());

        return response()->json(['draft' => $draft->toArray()]);
    }

    public function saveVoice(SaveBrandVoiceRequest $request, CurrentWorkspace $workspace, UpdateBrandBrainAction $update): RedirectResponse
    {
        $update($workspace->brand(), $request->brandBrainData());

        return back();
    }

    public function skip(Request $request, CurrentWorkspace $workspace): RedirectResponse
    {
        $this->stampComplete($workspace->organization(), 'skipped');

        return to_route('dashboard');
    }

    public function complete(Request $request, CurrentWorkspace $workspace): RedirectResponse
    {
        $this->stampComplete($workspace->organization(), 'completed');

        return to_route('dashboard');
    }

    private function stampComplete(?Organization $organization, string $via): void
    {
        if ($organization === null || $organization->onboarding_completed_at !== null) {
            return;
        }

        $organization->forceFill(['onboarding_completed_at' => now()])->save();

        Log::info('Business onboarding finished', ['org_id' => $organization->id, 'via' => $via]);
    }

    /**
     * @return array{name: string, one_liner: string|null, location: string|null, website: string|null, brand_kind: string|null}
     */
    private function brandProps(?Client $brand): array
    {
        if ($brand === null) {
            return ['name' => '', 'one_liner' => null, 'location' => null, 'website' => null, 'brand_kind' => null];
        }

        return [
            'name' => $brand->name,
            'one_liner' => $brand->industry,
            'location' => $brand->location,
            'website' => $brand->website,
            'brand_kind' => $brand->brand_kind?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function voiceProps(?Client $brand): array
    {
        $brain = $brand?->brandBrain;

        return [
            'voice' => $brain->voice ?? ['tone' => null, 'personality' => null, 'do_nots' => []],
            'audience' => $brain->audience ?? ['description' => null, 'demographics' => null, 'pain_points' => []],
            'offer' => $brain->offer ?? ['value_proposition' => null, 'key_products' => [], 'pricing_notes' => null],
            'content_pillars' => $brain->content_pillars ?? [],
        ];
    }

    /**
     * @return array<int, array{id: int, platform: string, handle: string, connected: bool}>
     */
    private function accountProps(?Client $brand): array
    {
        if ($brand === null) {
            return [];
        }

        return $brand->socialAccounts()
            ->get(['id', 'platform', 'handle', 'connection_status'])
            ->map(fn (SocialAccount $account) => [
                'id' => (int) $account->id,
                'platform' => $account->platform->value,
                'handle' => (string) $account->handle,
                'connected' => $account->connection_status === ConnectionStatus::Connected,
            ])
            ->values()
            ->all();
    }
}

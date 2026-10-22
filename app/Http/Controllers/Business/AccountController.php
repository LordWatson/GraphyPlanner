<?php

namespace App\Http\Controllers\Business;

use App\Actions\SocialAccounts\ConnectManagedAccountAction;
use App\Actions\SocialAccounts\ConnectSocialAccountAction;
use App\Actions\SocialAccounts\CreateSocialAccountAction;
use App\Actions\SocialAccounts\UpdateSocialAccountAction;
use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSocialAccountRequest;
use App\Http\Requests\UpdateSocialAccountRequest;
use App\Models\SocialAccount;
use App\Services\Publishing\PlatformCapabilities;
use App\Services\Workspace\CurrentWorkspace;
use App\Support\Clients\ClientTabPresenter;
use App\Support\Options\TimezoneOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Step B-4.1 / B-5.2 — the business shell's "Accounts" page. One card per publishing platform the
 * brand can post to; each card opens the hosted "log in with…" connect flow for the org's managed
 * Upload-Post profile (Step B-5.1), with no API keys and no handle to type. The brand is implicit
 * from {@see CurrentWorkspace}; the callback (public) maps the result back to the primary brand.
 */
class AccountController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace, PlatformCapabilities $capabilities): Response
    {
        $brand = $workspace->brand();
        $user = $request->user();

        $this->authorize('viewAny', [SocialAccount::class, $brand]);

        // The brand's accounts keyed by platform — one card per enabled platform, showing the
        // connected account (if any) or a "Connect" call to action.
        $accounts = $brand->socialAccounts()->latest()->get()->keyBy(fn (SocialAccount $account) => $account->platform->value);

        $platforms = array_map(function (Platform $platform) use ($accounts, $user) {
            $account = $accounts->get($platform->value);

            return [
                'value' => $platform->value,
                'label' => $platform->label(),
                'hosted_connect' => $platform->spec()->hostedConnect,
                'account' => $account ? ClientTabPresenter::socialAccount($account, $user) : null,
            ];
        }, $capabilities->enabledPlatforms());

        return Inertia::render('business/accounts', [
            'platforms' => $platforms,
            'timezones' => TimezoneOptions::options(),
            // Advanced defaults so the brand rarely touches them — filled from the brand/org.
            'defaults' => [
                'timezone' => $workspace->organization()->default_timezone ?? 'UTC',
                'language' => $brand->default_language,
                'country' => $brand->countries[0] ?? null,
            ],
            'can' => [
                'create' => $user->can('create', [SocialAccount::class, $brand]),
            ],
        ]);
    }

    public function store(StoreSocialAccountRequest $request, CurrentWorkspace $workspace, CreateSocialAccountAction $action): RedirectResponse
    {
        $action($workspace->brand(), $request->validatedAccount());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account added. Connect it to start publishing.')]);

        return to_route('business.accounts.index');
    }

    public function update(UpdateSocialAccountRequest $request, SocialAccount $socialAccount, UpdateSocialAccountAction $action): RedirectResponse
    {
        $action($socialAccount, $request->validatedAccount());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account saved.')]);

        return to_route('business.accounts.index');
    }

    public function destroy(SocialAccount $socialAccount): RedirectResponse
    {
        $this->authorize('delete', $socialAccount);

        $socialAccount->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account removed.')]);

        return to_route('business.accounts.index');
    }

    /**
     * Step B-5.2 — one-tap connect for a platform: lazily creates (or reuses) the brand's account
     * for that platform with managed credentials and returns the hosted connect URL as JSON, so the
     * page can open it in a popup and reload once the account comes back connected.
     */
    public function connectPlatform(
        Request $request,
        string $platform,
        CurrentWorkspace $workspace,
        PlatformCapabilities $capabilities,
        ConnectManagedAccountAction $action,
    ): JsonResponse {
        $platformEnum = Platform::tryFrom($platform);

        // Only platforms switched on for publishing (rollout flags) can be connected.
        if ($platformEnum === null || ! in_array($platformEnum, $capabilities->enabledPlatforms(), true)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $brand = $workspace->brand();

        $this->authorize('create', [SocialAccount::class, $brand]);

        try {
            $url = $action($brand, $platformEnum, $request->user());
        } catch (RuntimeException $e) {
            Log::warning('Business one-tap connect failed to start', [
                'org_id' => $brand->org_id,
                'platform' => $platformEnum->value,
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Start the hosted connect flow for an existing account (reconnect) and return the vendor URL
     * as JSON — kept for the per-account "Advanced" path and reconnection.
     */
    public function connect(Request $request, SocialAccount $socialAccount, ConnectSocialAccountAction $action): JsonResponse
    {
        $this->authorize('update', $socialAccount);

        try {
            $url = $action($socialAccount, $request->user());
        } catch (RuntimeException $e) {
            Log::warning('Business account connect failed to start', [
                'social_account_id' => $socialAccount->id,
                'org_id' => $socialAccount->org_id,
                'reason' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], HttpResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['url' => $url]);
    }
}

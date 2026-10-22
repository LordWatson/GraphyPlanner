<?php

namespace App\Http\Controllers\Business;

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
 * Step B-4.1 — the business shell's "Accounts" page: connect, reconnect and remove the brand's
 * social accounts. Calls the same Actions the agency uses, with the brand implicit from
 * {@see CurrentWorkspace}. The richer one-tap "log in with…" connect for managed credentials lands
 * in Step B-5.2; this is the manual-add + hosted-connect flow available today.
 */
class AccountController extends Controller
{
    public function index(Request $request, CurrentWorkspace $workspace): Response
    {
        $brand = $workspace->brand();
        $user = $request->user();

        $this->authorize('viewAny', [SocialAccount::class, $brand]);

        return Inertia::render('business/accounts', [
            'accounts' => $brand->socialAccounts()
                ->latest()
                ->get()
                ->map(fn (SocialAccount $account) => ClientTabPresenter::socialAccount($account, $user)),
            // Only platforms switched on for publishing can be added (rollout flags).
            'platforms' => array_map(
                fn (Platform $platform) => ['value' => $platform->value, 'label' => $platform->label()],
                app(PlatformCapabilities::class)->enabledPlatforms(),
            ),
            'timezones' => TimezoneOptions::options(),
            // Sensible defaults so the brand rarely touches "Advanced" (Step B-5.2 fills these in).
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
     * Start the hosted connect flow for an account and return the vendor URL as JSON, so the page
     * can open it in a popup and poll for the result (mirrors the shared `social-accounts.connect`).
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

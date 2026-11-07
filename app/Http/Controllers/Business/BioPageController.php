<?php

namespace App\Http\Controllers\Business;

use App\Actions\Business\AddBioPageDomainAction;
use App\Actions\Business\BuildBioPageAction;
use App\Actions\Business\SaveBioPageAction;
use App\Actions\Business\VerifyBioPageDomainAction;
use App\Contracts\Entitlements;
use App\Enums\BioBlockType;
use App\Enums\BioTheme;
use App\Enums\Feature;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Business\SaveBioPageRequest;
use App\Models\BioBlock;
use App\Models\BioPage;
use App\Models\BioPageDomain;
use App\Models\Product;
use App\Services\Workspace\CurrentWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step CG-3.2 — the bio page editor (`/bio`). Thin: resolves the brand from
 * {@see CurrentWorkspace}, hands the whole submitted page to {@see SaveBioPageAction}, and renders
 * the owner's live preview with the same {@see BuildBioPageAction} payload the public page uses.
 *
 * Publishing is a plan gate ({@see Feature::BioPage}); editing a draft isn't, so a Free workspace
 * can build its page and see exactly what upgrading would put online.
 */
class BioPageController extends Controller
{
    public function edit(Request $request, CurrentWorkspace $workspace, BuildBioPageAction $build): Response
    {
        $brand = $workspace->brand();
        $this->authorize('view', $brand);

        $page = BioPage::query()->with('blocks')->where('client_id', $brand->id)->first();
        $organization = $brand->organization;

        return Inertia::render('business/bio', [
            'page' => $page === null ? null : [
                'handle' => $page->handle,
                'title' => $page->title,
                'bio' => $page->bio,
                'theme' => $page->theme->value,
                'is_published' => $page->isPublished(),
                'public_url' => $page->publicUrl(),
                'blocks' => $page->blocks->map(fn (BioBlock $block) => [
                    'id' => $block->id,
                    'type' => $block->type->value,
                    'is_visible' => $block->is_visible,
                    'settings' => $block->settings ?? [],
                ])->values(),
            ],
            // Not `preview`: that's the shared preview-mode boolean, and a page prop of the same
            // name shadows it (which switches the whole shell into read-only preview mode).
            'publicPreview' => $page === null ? null : Inertia::defer(fn () => $build($page)),
            'suggestedHandle' => $page === null ? $this->suggestHandle($brand->name) : $page->handle,
            'themes' => array_map(fn (BioTheme $theme) => ['value' => $theme->value, 'label' => $theme->label()], BioTheme::cases()),
            'blockTypes' => array_map(fn (BioBlockType $type) => ['value' => $type->value, 'label' => $type->label()], BioBlockType::live()),
            // Step CG-4.2: what a `product` block can point at — the brand's own live products.
            'products' => Product::query()
                ->where('client_id', $brand->id)
                ->where('status', ProductStatus::Live)
                ->orderBy('title')
                ->get(['id', 'title'])
                ->map(fn (Product $product) => ['id' => $product->id, 'title' => $product->title])
                ->values(),
            // Step GTM-3.2: the creator's own hostnames, and what they have to put in DNS.
            'domains' => $page === null ? [] : $page->domains()->orderBy('id')->get()->map(fn (BioPageDomain $domain) => [
                'id' => $domain->id,
                'hostname' => $domain->hostname,
                'verified' => $domain->isVerified(),
                'ssl_status' => $domain->ssl_status,
                'last_error' => $domain->last_error,
                'verification_token' => $domain->verification_token,
                'last_checked_at' => $domain->last_checked_at?->toIso8601String(),
            ])->values(),
            'domainTarget' => (string) config('bio.domain_target'),
            'maxDomains' => BioPageDomain::MAX_PER_PAGE,
            'entitled' => $organization !== null
                && app(Entitlements::class)->allows($organization, Feature::BioPage),
            'domainsEntitled' => $organization !== null
                && app(Entitlements::class)->allows($organization, Feature::CustomDomain),
            'canManage' => $request->user()?->can('update', $brand) ?? false,
        ]);
    }

    public function update(SaveBioPageRequest $request, CurrentWorkspace $workspace, SaveBioPageAction $action): RedirectResponse
    {
        $page = $action($workspace->brand(), $request->validated());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $page->isPublished()
                ? __('Your page is live at :url', ['url' => $page->publicUrl()])
                : __('Saved. Your page is still private.'),
        ]);

        return back();
    }

    /**
     * A free handle to offer a brand that hasn't picked one: its name slugified, with a short
     * suffix when that's already somebody's.
     */
    /**
     * Step GTM-3.2 — claim a hostname for this brand's page. Created unverified: it serves
     * nothing until DNS points here.
     */
    public function storeDomain(Request $request, CurrentWorkspace $workspace, AddBioPageDomainAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);

        // Normalised before validating, because people paste `https://links.me.com/` and the
        // strict hostname rule below would otherwise just say no.
        $request->merge(['hostname' => BioPageDomain::normaliseHostname((string) $request->input('hostname'))]);

        $validated = $request->validate([
            'hostname' => ['required', 'string', 'max:253', 'regex:/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/'],
        ]);

        $page = BioPage::query()->where('client_id', $brand->id)->firstOrFail();
        $domain = $action($page, $validated['hostname']);

        Inertia::flash('toast', $domain->isVerified()
            ? ['type' => 'success', 'message' => __('Domain verified and live.')]
            : ['type' => 'info', 'message' => __('Domain added. Point your DNS at us and check again.')]);

        return back();
    }

    /**
     * Re-check one domain now. The same Action the nightly sweep uses, so it can take a domain
     * offline as well as bring one online.
     */
    public function verifyDomain(BioPageDomain $domain, CurrentWorkspace $workspace, VerifyBioPageDomainAction $action): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($domain->page?->client_id === $brand->id, 404);

        $verified = $action($domain);

        Inertia::flash('toast', $verified
            ? ['type' => 'success', 'message' => __('Domain verified and live.')]
            : ['type' => 'error', 'message' => $domain->refresh()->last_error ?? __('Not pointing here yet.')]);

        return back();
    }

    public function destroyDomain(BioPageDomain $domain, CurrentWorkspace $workspace): RedirectResponse
    {
        $brand = $workspace->brand();
        $this->authorize('update', $brand);
        abort_unless($domain->page?->client_id === $brand->id, 404);

        $domain->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Domain removed. Your @handle address still works.')]);

        return back();
    }

    private function suggestHandle(string $name): string
    {
        $base = Str::limit(Str::slug($name, ''), 24, '') ?: 'mybrand';

        return BioPage::query()->where('handle', $base)->exists()
            ? $base.Str::lower(Str::random(4))
            : $base;
    }
}

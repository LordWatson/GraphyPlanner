<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Actions\Analytics\GetOrganizationAnalyticsSummaryAction;
use App\Actions\Organizations\RegisterOrganizationAction;
use App\Actions\SuperAdmin\DeleteOrganizationAction;
use App\Actions\SuperAdmin\GetOrganizationDetailAction;
use App\Actions\SuperAdmin\ListOrganizationsAction;
use App\Actions\SuperAdmin\ReinstateOrganizationAction;
use App\Actions\SuperAdmin\SuspendOrganizationAction;
use App\Actions\SuperAdmin\UpdateOrganizationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\AnalyticsFilterRequest;
use App\Http\Requests\SuperAdmin\DeleteOrganizationRequest;
use App\Http\Requests\SuperAdmin\OrganizationIndexRequest;
use App\Http\Requests\SuperAdmin\StoreOrganizationRequest;
use App\Http\Requests\SuperAdmin\SuspendOrganizationRequest;
use App\Http\Requests\SuperAdmin\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Support\Analytics\OrganizationAnalyticsFilters;
use App\Support\Options\CurrencyOptions;
use App\Support\Options\TimezoneOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step 15.3 — the super admin's organization directory: list/search, onboard on a customer's
 * behalf, view, update, suspend/reinstate, delete — plus the per-org analytics drill-down
 * (Step 15.4). Every route sits behind the `super_admin` middleware.
 */
class OrganizationController extends Controller
{
    public function index(OrganizationIndexRequest $request, ListOrganizationsAction $list): Response
    {
        $filters = $request->validated();

        return Inertia::render('super-admin/organizations/index', [
            'organizations' => $list($filters)->through(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'owner' => $organization->owner ? [
                    'name' => $organization->owner->name,
                    'email' => $organization->owner->email,
                ] : null,
                'users_count' => (int) $organization->getAttribute('users_count'),
                'clients_count' => (int) $organization->getAttribute('clients_count'),
                'published_posts_count' => (int) $organization->getAttribute('published_posts_count'),
                'created_at' => $organization->created_at?->toIso8601String(),
                'is_suspended' => $organization->isSuspended(),
                'is_preview' => $organization->isPreviewFixture(),
            ]),
            'filters' => [
                'search' => $filters['search'] ?? '',
                'status' => $filters['status'] ?? 'all',
                'sort' => $filters['sort'] ?? 'newest',
            ],
            'counts' => [
                'all' => Organization::query()->count(),
                'active' => Organization::query()->active()->count(),
                'suspended' => Organization::query()->suspended()->count(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Organization::class);

        return Inertia::render('super-admin/organizations/create', [
            'timezones' => TimezoneOptions::options(),
            'currencies' => CurrencyOptions::options(),
        ]);
    }

    public function store(StoreOrganizationRequest $request, RegisterOrganizationAction $register): RedirectResponse
    {
        $owner = $register($request->registration(), $request->user());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Organization created — we’ve emailed :email a link to choose a password.', ['email' => $owner->email]),
        ]);

        return to_route('super-admin.organizations.show', $owner->org_id);
    }

    public function show(Organization $organization, GetOrganizationDetailAction $detail): Response
    {
        $this->authorize('view', $organization);

        return Inertia::render('super-admin/organizations/show', [
            ...$detail($organization),
            'timezones' => TimezoneOptions::options(),
            'currencies' => CurrencyOptions::options(),
        ]);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization, UpdateOrganizationAction $update): RedirectResponse
    {
        $update($organization, $request->details(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organization details saved.')]);

        return to_route('super-admin.organizations.show', $organization);
    }

    public function suspend(SuspendOrganizationRequest $request, Organization $organization, SuspendOrganizationAction $suspend): RedirectResponse
    {
        $suspend($organization, $request->user(), $request->validated('reason'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is suspended. Its members are locked out until you reinstate it.', ['name' => $organization->name])]);

        return to_route('super-admin.organizations.show', $organization);
    }

    public function reinstate(Request $request, Organization $organization, ReinstateOrganizationAction $reinstate): RedirectResponse
    {
        $this->authorize('suspend', $organization);

        $reinstate($organization, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is back. Its members can sign in again.', ['name' => $organization->name])]);

        return to_route('super-admin.organizations.show', $organization);
    }

    public function destroy(DeleteOrganizationRequest $request, Organization $organization, DeleteOrganizationAction $delete): RedirectResponse
    {
        $name = $organization->name;

        $delete($organization, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name and all of its data were deleted.', ['name' => $name])]);

        return to_route('super-admin.organizations.index');
    }

    /**
     * Step 15.4 — the per-org drill-down. Since Step 17.9 it runs the organization's own analytics
     * (`GetOrganizationAnalyticsSummaryAction`, every section visible to a super admin, in the
     * org's timezone), so support and sales see exactly what the customer's Owner sees.
     */
    public function analytics(AnalyticsFilterRequest $request, Organization $organization, GetOrganizationAnalyticsSummaryAction $summary): Response
    {
        $this->authorize('view', $organization);

        $filters = OrganizationAnalyticsFilters::fromArray(
            Arr::only($request->validated(), ['range', 'from', 'to']),
            $organization,
        );

        return Inertia::render('super-admin/organizations/analytics', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'is_suspended' => $organization->isSuspended(),
                'is_preview' => $organization->isPreviewFixture(),
            ],
            'summary' => $summary($organization, $filters, $request->user()),
        ]);
    }
}

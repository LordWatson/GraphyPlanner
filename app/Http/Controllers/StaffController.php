<?php

namespace App\Http\Controllers;

use App\Actions\Staff\RemoveStaffMemberAction;
use App\Actions\Staff\UpdateStaffMemberAction;
use App\Enums\Role;
use App\Http\Requests\UpdateStaffMemberRequest;
use App\Models\StaffActivityLog;
use App\Models\StaffInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    /**
     * Display the organization's staff roster (Owner-only): current members and any
     * pending/accepted/revoked invitations, mirroring the Clients module's index page.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $organization = $request->user()->organization;

        return Inertia::render('staff/index', [
            'members' => $organization->users()
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => $this->transform($user, $request->user())),
            'invitations' => $organization->staffInvitations()
                ->latest()
                ->get()
                ->map(fn (StaffInvitation $invitation) => $this->transformInvitation($invitation, $request->user())),
            'roles' => $this->roleOptions(),
            'can' => [
                'invite' => $request->user()->can('create', [StaffInvitation::class, $organization]),
            ],
        ]);
    }

    /**
     * Display a single staff member's profile and activity history.
     */
    public function show(Request $request, User $staff): Response
    {
        $this->authorize('view', $staff);

        return Inertia::render('staff/show', [
            'member' => $this->transform($staff, $request->user()),
            'activityLogs' => $staff->staffActivityLogs()
                ->with('actor')
                ->latest()
                ->get()
                ->map(fn (StaffActivityLog $log) => $this->transformActivityLog($log)),
        ]);
    }

    /**
     * Show the form for editing a staff member's name/role.
     */
    public function edit(Request $request, User $staff): Response
    {
        $this->authorize('update', $staff);

        return Inertia::render('staff/edit', [
            'member' => $this->transform($staff, $request->user()),
            'roles' => $this->roleOptions(),
        ]);
    }

    /**
     * Update a staff member's name/role.
     */
    public function update(UpdateStaffMemberRequest $request, User $staff, UpdateStaffMemberAction $action): RedirectResponse
    {
        $action($staff, $request->validated(), $request->user());

        return to_route('staff.show', $staff);
    }

    /**
     * Remove a staff member from the organization.
     */
    public function destroy(Request $request, User $staff, RemoveStaffMemberAction $action): RedirectResponse
    {
        $this->authorize('delete', $staff);

        $action($staff, $request->user());

        return to_route('staff.index');
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        return array_values(array_map(
            fn (Role $role) => ['value' => $role->value, 'label' => $role->label()],
            array_filter(Role::cases(), fn (Role $role) => $role !== Role::ClientReviewer),
        ));
    }

    /**
     * Transform a staff member into an array for the staff pages.
     *
     * @return array<string, mixed>
     */
    private function transform(User $staff, User $viewer): array
    {
        return [
            'id' => $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => $staff->role?->value,
            'role_label' => $staff->role?->label(),
            'created_at' => $staff->created_at?->toIso8601String(),
            'can' => [
                'update' => $viewer->can('update', $staff),
                'delete' => $viewer->can('delete', $staff),
            ],
        ];
    }

    /**
     * Transform a staff invitation into an array for the staff index page. Deliberately never
     * includes the token or its hash — only `App\Actions\StaffInvitations\CreateStaffInvitationAction`
     * ever sees the plain token, and it never gets persisted or rendered.
     *
     * @return array<string, mixed>
     */
    private function transformInvitation(StaffInvitation $invitation, User $user): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'role_label' => $invitation->role->label(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'accepted_at' => $invitation->accepted_at?->toIso8601String(),
            'revoked_at' => $invitation->revoked_at?->toIso8601String(),
            'is_pending' => $invitation->isPending(),
            'can' => [
                'delete' => $invitation->isPending() && $user->can('delete', $invitation),
            ],
        ];
    }

    /**
     * Transform a staff activity log entry for the member show page.
     *
     * @return array<string, mixed>
     */
    private function transformActivityLog(StaffActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'from_role' => $log->from_role?->label(),
            'to_role' => $log->to_role?->label(),
            'note' => $log->note,
            'actor_name' => $log->actor?->name,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}

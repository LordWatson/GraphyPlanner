# Staff (Org-Level Team Module)

## Purpose
A real, first-class module (not a settings sub-page) letting an org Owner see, invite, edit, and
remove staff (Owner/Strategist/Designer/Viewer — never `client_reviewer`, which is reserved for the
client-portal flow). Lives in the main app sidebar (Owner-only), styled to match the Clients module
(gradient header, table roster, per-member show/edit pages).

## Models / Tables
- `StaffInvitation` (`staff_invitations`): `org_id`, `email`, `role` (cast to `App\Enums\Role`),
  `invited_by_user_id`, `token_hash` (unique, hashed at rest — the plain token is only ever returned
  once, at creation time), `expires_at`, `accepted_at`, `revoked_at`. `isPending()` mirrors
  `ClientInvitation::isPending()`.
- `StaffActivityLog` (`staff_activity_logs`): `org_id`, `user_id` (nullable, set-null on delete —
  the member the entry is about), `actor_user_id` (nullable, set-null on delete — who performed the
  action), `action` (`invited`/`joined`/`role_changed`/`removed`), `from_role`/`to_role` (cast to
  `App\Enums\Role`, nullable), `note`. Powers the "Activity" timeline on `staff/show`.
- `Organization::staffInvitations()`: `HasMany<StaffInvitation>`.
- `User::staffActivityLogs()`: `HasMany<StaffActivityLog>` (entries about that user).

## Actions / Services / Contracts
- `App\Actions\StaffInvitations\CreateStaffInvitationAction`: creates the invitation (wrapped in
  `DB::transaction()`), logs an `invited` `StaffActivityLog` entry, emails the invited address
  (`StaffInvitationMail`) a signed `/staff-invite/:token` link, and logs success/failure.
- `App\Actions\StaffInvitations\AcceptStaffInvitationAction`: creates (or, if a `User` with that
  email already exists, reuses/updates) a `User` scoped to the invitation's `org_id`/`role`, sets
  the chosen password, marks the invitation accepted, and logs a `joined` `StaffActivityLog` entry.
  Wrapped in `DB::transaction()`.
- `App\Actions\Staff\UpdateStaffMemberAction`: updates a member's name/role; if the role changed,
  logs a `role_changed` `StaffActivityLog` entry and an `info` log line. Wrapped in `DB::transaction()`.
- `App\Actions\Staff\RemoveStaffMemberAction`: logs a `removed` `StaffActivityLog` entry (capturing
  the member's name/email in `note` since the FK will null out `user_id`), then deletes the `User`.
  Wrapped in `DB::transaction()`.

## Routes / Controllers
- `GET /staff` → `StaffController@index` — Owner-only roster: members table + invite form +
  pending/historical invitations, mirroring `clients/index`.
- `GET /staff/{staff}` → `StaffController@show` — a member's profile + full `StaffActivityLog`
  history.
- `GET /staff/{staff}/edit` / `PUT /staff/{staff}` → `StaffController@edit`/`update` — change a
  member's name/role.
- `DELETE /staff/{staff}` → `StaffController@destroy` — remove a member from the org.
- `POST /staff/invitations` → `StaffInvitationController@store` — send a staff invitation.
- `DELETE /staff/invitations/{invitation}` → `StaffInvitationController@destroy` — revoke a pending
  invitation.
- `GET /staff-invite/{token}` / `POST /staff-invite/{token}` → `StaffInvitationAcceptController` —
  unauthenticated accept-invitation flow (access granted purely by token possession, like
  `/client-invite/:token`), renders `resources/js/pages/staff-invite/show.tsx` with no app layout
  (`app.tsx`'s `staff-invite/` case).

## Frontend
- `resources/js/pages/staff/index.tsx`, `show.tsx`, `edit.tsx` — full CRUD pages styled like the
  Clients module (gradient hero header, table roster, per-member activity timeline).
- Owner-only "Staff" link in the main app sidebar (`resources/js/components/app-sidebar.tsx`), using
  the `UsersRound` icon. Removed from the Settings sidebar (`resources/js/layouts/settings/layout.tsx`)
  now that it's a top-level module.

## Phase M-1 additions
- The studio roster, client reviewers and invitations render through the shared `DataTable`
  (sorted in place by name, email or role).

## Business Rules
- `StaffPolicy` (bound to `App\Models\User`): only `Role::Owner`, org-scoped, can view the roster or
  a member's profile, or edit a member. `delete` additionally forbids removing yourself and removing
  the last remaining Owner in the org (so an org can never be locked without an Owner).
- `StaffInvitationPolicy`: same Owner-only/org-scoped shape for invite/revoke.
- The roles a tenant flow may assign come from a single allow-list, `Role::assignableFor($orgType)`,
  used by `StoreStaffInvitationRequest`, `UpdateStaffMemberRequest` (`Rule::enum(...)->only(...)`) and
  `StaffController::roleOptions()` (the assign/edit `<select>`). It excludes **`Role::ClientReviewer`**
  (assigned only through the client-portal invitation flow) and **`Role::SuperAdmin`** (org-less
  platform staff — `Gate::before` grants it every ability and it bypasses `OrganizationScope`, so it
  is provisioned out of band and is **never** assignable inside a tenant workspace; otherwise an Owner
  could escalate themselves or a teammate to platform god-mode). A business org further narrows the
  list to its own Owner/Manager/Creator/Viewer vocabulary.
- Defence in depth behind the form requests: `CreateStaffInvitationAction` and
  `UpdateStaffMemberAction` both re-check `Role::assignableFor()` and throw `InvalidArgumentException`
  if a non-assignable role (notably `SuperAdmin`) reaches them.
- A duplicate pending invitation to the same email within the org is rejected
  (`StoreStaffInvitationRequest::withValidator`).
- Accepting reuses an existing `User` record for the invited email (updating name/password/org_id/role)
  rather than erroring, mirroring `AcceptClientInvitationAction`.
- Every meaningful event (invited, joined, role changed, removed) is recorded as a `StaffActivityLog`
  row, visible per-member on `staff/show`.

## Tests
- `tests/Feature/StaffControllerTest.php`: roster/profile visibility (Owner-only, org-scoped), update
  (role change + activity log + `client_reviewer` and `super_admin` rejection, plus the action-level
  `super_admin` guard), destroy (activity log, self-removal and last-Owner protections).
- `tests/Feature/StaffInvitationControllerTest.php`: send/revoke authorization, duplicate-pending
  rejection, `client_reviewer` and `super_admin` role rejection, token never rendered.
- `tests/Unit/Enums/RoleWorkspaceLabelTest.php`: `Role::assignableFor()` excludes `client_reviewer`
  and `super_admin` in every workspace type.
- `tests/Feature/StaffInvitationAcceptControllerTest.php`: accept flow creates/reuses a `User` with the
  invited role, and 404s for accepted/expired/revoked/invalid tokens.

## Known Gaps / Open Questions
- Staff invitations aren't queued (matches the synchronous `ClientInvitationMail` pattern already in
  the codebase) — revisit if invite volume grows.
- No self-service "leave organization" flow — only an Owner can remove a member.

## Changelog
- 2026-10-24: Security fix — `Role::SuperAdmin` was assignable inside agency workspaces. The agency
  branch of `Role::assignableFor()` excluded only `ClientReviewer`, and both `UpdateStaffMemberRequest`
  and `StaffController::roleOptions()` filtered roles ad-hoc (dropping only `ClientReviewer`), so an
  agency Owner could invite or promote a member — or themselves — to the platform `super_admin` role
  and gain `Gate::before` god-mode across all tenants. Now `assignableFor()` excludes `SuperAdmin` in
  every workspace type, the update request and the dropdown both route through it, and
  `UpdateStaffMemberAction` re-checks it (mirroring `CreateStaffInvitationAction`). Added HTTP + unit
  regression tests.
- 2026-10-16: Rebuilt `pages/staff/index.tsx` (UI only): a `StatStrip` (studio / client reviewers /
  pending), the invite form as a `Panel` with its fields and button aligned on one row, the roster
  split into "The studio" and "Client reviewers", and invitations as one divided list whose states
  render through `StatusPill kind="invitation"` (new in `lib/status.ts`) instead of one-off badge
  colours. **Revoke now goes through `confirmDialog`** (it previously submitted on click); same
  route and policy. Expiry and activity-log timestamps render via `lib/dates.ts`.
- 2026-10-16: Brand-book overhaul (UI only): Staff is chapter 06 in the sidebar and running head;
  the roster title reads "Your team." with the accent word.
- 2026-09-19: Added `staff_invitations` table/model, `StaffInvitationPolicy` (Owner-only),
  Create/AcceptStaffInvitationAction, `StaffInvitationController`/`StaffInvitationAcceptController`/
  `Settings\StaffController`, `/settings/staff` + `/staff-invite/:token` routes, and the
  `settings/staff` + `staff-invite/show` Inertia pages.
- 2026-09-19: Promoted Staff from a settings sub-page to a full top-level module: added
  `staff_activity_logs` table/model, `StaffPolicy` (view/update/delete, including last-Owner
  protection), `UpdateStaffMemberAction`/`RemoveStaffMemberAction`, a new `StaffController`
  (index/show/edit/update/destroy) replacing `Settings\StaffController`, moved routes from
  `/settings/staff*` to `/staff*`, added an Owner-only sidebar link, and built `staff/index`,
  `staff/show`, `staff/edit` pages matching the Clients module's look and feel.
- 2026-10-20: Phase M-1: roster and invitations on `DataTable`.

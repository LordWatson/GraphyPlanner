# Authentication & Profile/Security Settings

## Purpose
Handles user authentication (via Laravel Fortify) and the user-facing settings pages for profile
and security (password/2FA). Corresponds to the base scaffolding from Step 0.1 of the plan, ahead of
org/role modeling (Step 0.2).

## Models / Tables
- `User` (`users`): Laravel default auth model (name, email, password), now also carries `org_id` and
  `role` (see `organizations-users.md` for details, added in Step 0.2).

## Actions / Services / Contracts
- `App\Actions\Fortify\ResetUserPassword`: Fortify's password reset action. (`CreateNewUser` was
  removed in Phase 15 — Fortify registration is disabled; signup goes through
  `/register-organization`, see `super-admin.md`.)
- `App\Http\Responses\LoginResponse` and `App\Http\Responses\TwoFactorLoginResponse` (implement
  Fortify's `LoginResponse`/`TwoFactorLoginResponse` contracts, bound in
  `FortifyServiceProvider::register()`): after a successful login (with or without a two-factor
  challenge), redirect `Role::ClientReviewer` users to `/portal` and everyone else to `/dashboard`,
  always via `redirect()->intended()` so a specific deep link (e.g. a direct post URL) still wins
  over the role-based default.

## Routes / Controllers
- `App\Http\Controllers\Settings\ProfileController`: profile settings page (view/update).
- `App\Http\Controllers\Settings\SecurityController`: password/security settings page.
- Auth routes are provided by Fortify (login/register/password reset), configured in
  `routes/settings.php` for the settings-specific routes.

## Phase M-1 additions
- Fortify's password confirmation (`auth/confirm-password`, `password.confirm` middleware) now
  guards org settings and data exports; irreversible deletes check `current_password` in their
  Form Requests (see `ops.md`).

## Business Rules
- Post-login redirect is role-aware: `Role::ClientReviewer` -> `/portal`, `Role::SuperAdmin` ->
  `/super-admin`, all other roles -> `/dashboard`, unless an intended URL is present in the session
  (`redirect()->intended()`), which always takes priority for any role.
- Registration: Fortify's `Features::registration()` is off (it created org-less users). `/register`
  (route name `register`, still used by the login/welcome pages) redirects to the public
  organization signup at `/register-organization`.
- Email verification is enforced: `User implements MustVerifyEmail`, so the `verified` middleware
  sends unverified users to Fortify's verification notice (`auth/verify-email.tsx`, the "check your
  inbox" greeting shown right after signup). Self-serve signups receive **one** email — the
  `OrganizationWelcomeMail` — whose single button is a signed `verification.verify` link
  (48-hour window, built by `RegisterOrganizationAction`); clicking it verifies the address and
  lands them in the app. `RegisterOrganizationAction` deliberately does **not** fire the
  `Registered` event, which would send a second, separate verification email. Invitation accepts and
  super-admin onboarding mark the address verified; users that existed before Phase 15 were
  grandfathered by migration. The verify-email page's "Resend verification email" button still uses
  Fortify's standard `verification.send` (default 60-min link) for a fresh link on demand.
- `Role::SuperAdmin` keeps profile, security and appearance settings; organization-level settings
  pages redirect them to `/super-admin` (`org.member`).

## Tests
- `tests/Feature/Auth/AuthenticationTest.php`: covers the default `/dashboard` redirect, the
  `Role::ClientReviewer` -> `/portal` redirect, and that an intended URL still wins for client
  reviewers.

## Known Gaps / Open Questions
- Org/role/policy documentation now lives in `organizations-users.md`; this file stays focused on
  pure authentication.

## Changelog
- 2026-10-25: Self-serve signups now receive a single welcome email whose one button is a signed
  48-hour `verification.verify` link (`RegisterOrganizationAction` no longer fires `Registered`, so
  no duplicate Fortify verification email). The `auth/verify-email.tsx` greeting copy was reworded to
  point owners at that welcome email. See `organizations-users.md`.
- 2026-10-17: Phase 15 — super admin login redirect, Fortify registration replaced by
  `/register-organization` (`auth/register.tsx`, `CreateNewUser` and `RegistrationTest` removed;
  covered by `OrganizationRegistrationTest`), `MustVerifyEmail` enforced, `SuperAdminAccessTest`
  added.
- 2026-10-16: Brand-book overhaul: `layouts/auth/auth-simple-layout.tsx` is now the guidelines'
  cover — an Acid Lime panel with an ink running head, the tagline and the wordmark set huge across
  its foot (Ink/Lime colourway; the wordmark is the panel's only logo). Page copy moved to the brand
  voice ("Welcome back.", "Let's get you set up.", "Forgot your password?", "Choose a new
  password.", "Check your inbox.").
- 2026-09-08: Initial documentation created alongside AGENTS.md setup; reflects scaffold state
  (Fortify auth + Profile/Security settings controllers, no org/role model yet).
- 2026-09-08: Noted `org_id`/`role` addition to `User` (Step 0.2); see `organizations-users.md`.
- 2026-09-20: Added `App\Http\Responses\LoginResponse` so `Role::ClientReviewer` users land on
  `/portal` (instead of `/dashboard`) after login, while still respecting `redirect()->intended()`.
- 2026-10-20: Phase M-1: password confirmation used for sensitive Owner actions.

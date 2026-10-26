# Preview Mode

## Purpose
Provides a read-only `/preview/*` mirror of the main app, backed by frozen fixture data, so the
product can be demoed without any risk of touching real client data (Step 0.14). Every mutating
request made under `/preview/*` is rejected with a 403 before it reaches any controller/Action.

## Models / Tables
No new tables. Reuses `Organization`, `User`, `Client`, `Campaign`, `SocialAccount`, `Post`,
`PostTarget` — scoped to one dedicated fixture organization (`organizations.slug =
'preview-studio'`) created by `Database\Seeders\PreviewSeeder`.

## Actions / Services / Contracts
- `Database\Seeders\PreviewSeeder`: idempotent (`updateOrCreate`) seeder that creates/refreshes the
  "Preview Studio" organization, its Owner user (`preview@graphy.test`), one client ("Fixture &
  Co"), one campaign, one connected social account, and one post with a scheduled target. Called
  from `DatabaseSeeder` so it's always present after `php artisan db:seed`/`migrate:fresh --seed`.
- `App\Http\Middleware\EnsurePreviewMode` (aliased as `preview`): applied to every route in the
  `/preview/*` group.
  - Aborts with `403` on any request whose method isn't `GET`/`HEAD`, *before* any controller runs.
  - Otherwise, resolves the seeded Preview Studio owner as the user **for this request only**
    (`Auth::guard()->setUser()`, restoring the previous user — or none — once the response is
    built), so the existing controllers/policies — which all scope by `$user->org_id` —
    transparently return only the fixture data with zero per-controller preview branching. Nothing
    is written to the session (Step I-1.1): a guest is still a guest on the next real request, and
    a logged-in user is still themselves.
- `App\Http\Middleware\RejectPreviewUserOutsidePreview` (appended to the `web` group, Step I-1.1):
  if the preview owner (`User::isPreviewUser()`, matched on `User::PREVIEW_EMAIL`) is ever
  authenticated on a non-`/preview/*` URL — e.g. an old session persisted before the fix — it is
  logged at `warning`, signed out (session invalidated) and the request gets a `403`.
  - Aborts with `404` if the fixture hasn't been seeded yet.

## Routes / Controllers
`routes/web.php` defines a `Route::prefix('preview')->name('preview.')->middleware(['preview'])`
group that mirrors the authenticated app route group one-for-one (same controllers, same route
paths, `preview.` name prefix): `dashboard`, `calendar`, `home` (`needs-attention`), the `clients`
resource, brand-brain edit/update, invoices, social-accounts, campaigns, assets, and posts
(store/edit/update/transition/comments). The write endpoints are deliberately included so the §12
acceptance test can assert they're rejected, not just omitted from the route list.

## Business Rules
- Preview is **read-only, full stop**: any non-GET/HEAD request anywhere under `/preview/*` gets a
  `403`, regardless of the target resource or the visitor's would-be permissions. This is enforced
  by the middleware alone, ahead of any policy/Action, so it also automatically covers future
  publish-adjacent routes (Phase 1) once they're added under this prefix — no extra guard needed.
- The `preview` shared Inertia prop (see `App\Http\Middleware\HandleInertiaRequests::share()`) is
  derived from the request path (`$request->is('preview', 'preview/*')`) rather than a request
  attribute, because Inertia's own middleware resolves shared props *before* route middleware runs.
- `resources/js/components/preview-banner.tsx` renders a persistent banner (wired into
  `app-sidebar-layout.tsx`) whenever `preview` is `true`, so visitors always know the data is frozen
  fixture data.
- Rejected write attempts are logged (`warning` level, with method/path/ip — no secrets) for
  visibility.

## Frontend
- **Preview guard (Step I-1.1):** `resources/js/hooks/use-preview-guard.ts` (`usePreviewGuard`,
  installed by `app-sidebar-layout.tsx`) registers an Inertia `router.on('before')` listener while
  `preview` is true. `resources/js/lib/preview.ts` (`decidePreviewVisit`) rewrites any GET visit to
  a mirrored page (`MIRRORED_PATHS`, kept in sync with the `preview.*` GET routes) onto its
  `/preview/*` twin, and cancels writes and unmirrored pages with a toast — so links and forms in
  the reused pages stay inside the mirror without per-page branching. `useIsPreview()` is exported
  for components that want to hide a write control outright.
- Because Wayfinder generates action-based helpers (`resources/js/actions/App/Http/Controllers/*`)
  keyed by controller method, mirroring a controller's routes under `/preview/*` makes any method
  that resolves to more than one URI (e.g. `ClientController::destroy` now matching both
  `/clients/{client}` and `/preview/clients/{client}`) turn into a URI-keyed dictionary instead of a
  single callable — so `Controller.method.form(...)` throws `... .form is not a function`. Pages
  that render forms for any controller mirrored under `/preview/*` (`ClientController`,
  `BrandBrainController`, `SocialAccountController`, `CampaignController`, `PostController`,
  `AssetController`, `InvoiceController`) must import the specific action from the **named**
  `resources/js/routes/**` module instead (e.g. `import { destroy } from '@/routes/clients'`), since
  those stay unambiguous per route name even when the same controller method backs multiple URIs.
  Affected pages fixed accordingly: `clients/{create,edit,show}.tsx`, `brand-brain/edit.tsx`, and
  `posts/edit.tsx`.

## Known Gaps / Open Questions
- Write controls in the reused pages are still rendered in preview; the router guard cancels them
  with a toast (and the backend 403s them), but they aren't visually hidden yet. Use
  `useIsPreview()` per component when a page is next touched.
- Plain `<a href>` links (not Inertia `<Link>`/`router`) bypass the router guard; on a real route
  the visitor is simply a guest, so they land on the login page rather than in the real app.
- `MIRRORED_PATHS` in `resources/js/lib/preview.ts` is hand-maintained against `routes/web.php`.
- No dedicated `/preview` landing page yet — `/preview/dashboard` is the entry point.

## Tests
- `tests/Feature/PreviewModeTest.php`: preview dashboard is reachable without logging in and shares
  `preview: true`; POST/PUT/DELETE against a preview-scoped client all return 403 and leave the
  fixture untouched; non-preview routes share `preview: false`. Step I-1.1 regressions: a preview visit leaves the
  visitor a guest on `/clients`; a logged-in Owner is still themselves on `/dashboard` after a
  preview visit; a persisted preview session is signed out with a 403 outside preview; real-route
  writes to the fixture org after a preview visit redirect to login.

## Changelog
- 2026-10-20: Phase 18 (grid planner) — GET mirrors for the client Grid tab, the planner and its schedule preview (plus writes kept to assert the 403); `lib/preview.ts` mirrors `/clients/{id}/grids` and `/grids/{id}/edit|schedule`. `PreviewSeeder` seeds an approved nine-tile grid (a 1×3 split, a carousel, a Reel, six linked posts) via `SeedsGridShowcase`; `DemoContentSeeder` adds a draft grid per demo client.
- 2026-10-18: Campaigns enhancement — GET mirrors for the campaigns hub, client campaigns tab and every workspace tab; `PreviewSeeder` seeds the campaign showcase (`SeedsCampaignShowcase`).
- 2026-10-18: Step I-2.4 — `MIRRORED_PATHS` and the `preview.*` routes include the client tab pages
  (`posts`, `media`, `campaigns`, `billing`, `portal-access`, `ai`, `briefs`).
- 2026-10-18: Step I-1.1 — preview never creates a real session. `EnsurePreviewMode` swaps the
  identity per request (`setUser` + restore) instead of `Auth::login()`; new
  `RejectPreviewUserOutsidePreview` web middleware; frontend `usePreviewGuard` keeps visits inside
  the mirror. Fixes the confirmed session leak onto `/dashboard`, `/clients`, ….
- 2026-10-18: Phase 17 — read-only `/preview/analytics` mirror (`analytics`, `analytics/export`, `analytics/pdf`, `clients/{client}/analytics`, `analytics/reports` index/show/export/pdf).
- 2026-10-16: Fixed a blank first page in preview mode. `HandleInertiaRequests::share()` read
  `$request->user()` eagerly, but it runs before the route-level `preview` middleware signs the
  visitor in, so a fresh session's first `/preview/*` request shared `auth.user = null` and the
  sidebar crashed on `auth.user.role`. Every user-derived shared prop (`auth`, `client`, `portal`,
  `unreadNotificationsCount`, `currency`) is now a closure resolved at response time. Regression:
  `PreviewModeTest::test_first_preview_request_shares_the_signed_in_preview_owner`.
- 2026-09-16: Fixed a regression introduced by the preview route mirroring: switched
  `clients/{create,edit,show}.tsx`, `brand-brain/edit.tsx`, and `posts/edit.tsx` from
  action-based Wayfinder imports (`*Controller.method.form(...)`) to the unambiguous named
  `resources/js/routes/**` imports, fixing `Uncaught TypeError: ... .form is not a function` on
  `clients.show` (and equivalent pages) caused by those controller methods now resolving to two
  URIs.
- 2026-09-16: Initial implementation of Step 0.14 — `PreviewSeeder`, `EnsurePreviewMode` middleware,
  `/preview/*` route group, shared `preview` Inertia prop, and the frontend banner.

---
name: implement-plan-step
description: Implement a numbered step from .junie/plans/graphy-development-steps.md (e.g. "do step 2.7", "implement Phase 3.1") end-to-end following Graphy's layering, testing, logging and module-doc rules.
---

# Implement a Graphy plan step

## 1. Load context (do not skip)
1. Read `.junie/AGENTS.md` (core principles).
2. Find the step in `.junie/plans/graphy-development-steps.md` (`grep -n "### Step X.Y"`), read it plus
   its phase intro and any "Done when"/exit criteria. Steps marked ✅ are done — confirm with the user
   before redoing one.
3. Read the matching `.junie/modules/<module>.md` (index in `.junie/modules/README.md`).
4. If the step has UI, read `.junie/brand-guidelines.md` and use the `graphy-frontend-page` skill.

## 2. Build it in this order
1. **Enum / migration / model / factory** — migrations named `YYYY_MM_DD_HHMMSS_...`; every
   org-owned table has `org_id`; add a factory (tests depend on them).
2. **Contract** in `app/Contracts` if there is (or will be) more than one implementation or it hits an
   external API; bind it in `AppServiceProvider::register()` with a short comment naming the step.
3. **Action** in `app/Actions/<Domain>/<Verb><Noun>Action.php` — invokable `__invoke()`, multi-write
   logic inside `DB::transaction()`, `Log::info()` with ids (`org_id`, `client_id`, `post_id`, actor
   `user_id`). Never log keys/tokens. Mirror `app/Actions/Clients/CreateClientAction.php`.
4. **Service** in `app/Services` only for logic shared across several Actions or vendor wrappers.
5. **Policy** in `app/Policies`, registered via `Gate::policy` in `AppServiceProvider::boot()`.
   Always scope by `org_id`; check `Role` enum.
6. **Form Request** in `app/Http/Requests` for every write.
7. **Controller** — thin: `$this->authorize()`, Form Request, call Action, `Inertia::render` or
   `to_route()`. No query building beyond simple lookups.
8. **Routes** in `routes/web.php` (named). Then regenerate Wayfinder: `php artisan wayfinder:generate`.
9. **Tests** — see `.claude/agents/graphy-test-writer.md` conventions (PHPUnit classes, `RefreshDatabase`,
   `Http::fake`, role/tenant denial cases).

## 3. Finish
1. Run the `graphy-checks` skill.
2. Update the module doc + README status table + the step heading (`✅ Done`) — use the
   `sync-module-docs` skill.
3. Summarize: files changed, tests added, anything deferred (also recorded under "Known Gaps").

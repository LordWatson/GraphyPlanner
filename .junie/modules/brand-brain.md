# BrandBrain

## Purpose
Covers Step 0.5: a 1:1 "brand brain" record per client capturing Voice, Audience, Offer, Visual,
Music policy, Hashtag policy, Content pillars, and an optional Brand Persona PDF upload — the
structured creative brief editors reference when drafting posts, and the future source of context for
Grok (xAI) caption assistance in Phase 2.

## Models / Tables
- `BrandBrain` (`brand_brains`): `client_id` (FK, unique — enforces 1:1 with `Client`), plus seven
  json columns: `voice`, `audience`, `offer`, `visual`, `music_policy`, `hashtag_policy`,
  `content_pillars`. Each of `voice`/`audience`/`offer`/`visual`/`music_policy`/`hashtag_policy` is a
  small associative array of a few string fields plus one or more string-list fields (see the model's
  PHPDoc for the exact shape); `content_pillars` is a flat array of strings. `belongsTo` `Client`;
  `Client::brandBrain(): HasOne`.
- Brand Persona file columns (added 2026-09-17, migration
  `2026_09_17_210000_add_persona_to_brand_brains_table`): `persona_disk`, `persona_path`,
  `persona_url`, `persona_original_filename`, `persona_mime_type`, `persona_size` — all nullable,
  mirroring the shape of `Asset`'s storage columns. `BrandBrain::hasPersona()` returns whether a file
  is currently stored.
- The exact §4.3 field list from the original spec wasn't available in this repo (see Known Gaps), so
  the column shapes above are a reasonable interim definition covering the categories named in the
  development plan.

- `BrandBrainReferenceAsset` (`brand_brain_reference_assets`, Step 16.4): visual references for the
  AI Image Studio: `brand_brain_id`, `asset_id`, `role` (`ReferenceImageRole` logo/style/product),
  `position`, plus `normalized_disk/path/mime_type` (a ≤1024px WebP copy made once on save). Unique on
  `(brand_brain_id, asset_id)`. `BrandBrain::referenceAssets()` (ordered by position).

## Actions / Services / Contracts
- `App\Actions\BrandBrains\SyncBrandReferenceAssetsAction` (Step 16.4): replaces a client's ordered,
  role-tagged references in one transaction (creating the `BrandBrain` row if missing). Rejects more
  than `ai.images.max_references`, duplicates, other clients' assets and anything that isn't a stored
  image (link-only assets must be uploaded first). Normalises new references once
  (`ImageNormalizer::reference()`, stored under `brand-references/{org}/{client}`), deletes the copies
  of removed ones, logs ids/counts. `ClientContextBuilder::fingerprint()` includes reference
  ids/roles/positions, so cached image briefs invalidate when the brand's look changes.
- `App\Actions\BrandBrains\UpdateBrandBrainAction`: `BrandBrain::updateOrCreate` scoped to a `Client`,
  wrapped in `DB::transaction`, logs the upsert. There is no separate create action since the record
  is 1:1 and always upserted from the editor.
- `App\Actions\BrandBrains\UploadBrandPersonaAction`: `firstOrCreate`s the `BrandBrain` for the given
  `Client`, deletes the previously stored persona file (if any) via the shared `App\Contracts\AssetStorage`
  binding, stores the new PDF under `brand-personas/{org_id}/{client_id}` on the same storage
  abstraction used by `Asset` uploads, and updates the `persona_*` columns — all wrapped in
  `DB::transaction` and logged.
- `App\Policies\BrandBrainPolicy`: not a standard Eloquent-model policy (it's authorized against the
  parent `Client`, since the `BrandBrain` row may not exist yet when the editor is first opened):
  - `viewForClient(user, client)` — Owner/Strategist/Designer/Viewer in the client's org, or the
    scoped Client reviewer.
  - `update(user, client)` — Owner/Strategist in the client's org only.
  - `view(user, brandBrain)` delegates to `viewForClient` once a row exists.
  - Still registered via `Gate::policy(BrandBrain::class, BrandBrainPolicy::class)` in
    `AppServiceProvider` for consistency, but the controller/request call the policy methods directly
    since the abilities take a `Client`, not a `BrandBrain`.

## Routes / Controllers
- `PUT /clients/{client}/brand-brain/references` → `clients.brand-brain.references.update`
  (`BrandBrainController::updateReferences`, `UpdateBrandReferencesRequest`, Owner/Strategist via
  `BrandBrainPolicy::update`). `edit` also passes `references`, `referenceRoles`, `maxReferences`, and
  the page has a "Visual references" tab (`components/brand-brain/visual-references.tsx`: ordered grid
  with role toggles, drag or arrow-button reordering, "Add from assets" via the images-only
  `AssetLibraryDialog`; `#references` deep-links to the tab). Tests: `tests/Feature/AI/BrandReferencesTest.php`.
- `GET /clients/{client}/brand-brain` → `clients.brand-brain.edit` (`BrandBrainController::edit`) —
  Inertia tabbed editor; renders even if no `BrandBrain` row exists yet (uses an empty in-memory
  instance so the form starts blank). Now also passes `persona_url`/`persona_original_filename`.
- `PUT /clients/{client}/brand-brain` → `clients.brand-brain.update`
  (`BrandBrainController::update`) — validated via `UpdateBrandBrainRequest`, delegates to
  `UpdateBrandBrainAction`.
- `POST /clients/{client}/brand-brain/persona` → `clients.brand-brain.persona.store`
  (`BrandBrainController::uploadPersona`) — validated via `UploadBrandPersonaRequest` (`persona` file,
  `mimes:pdf`, capped at `config('assets.max_upload_size_kb')`, same policy gate as `update`),
  delegates to `UploadBrandPersonaAction`, redirects back to the editor.
- Inertia page: `resources/js/pages/brand-brain/edit.tsx`, built with the new shadcn-style
  `resources/js/components/ui/tabs.tsx` (`@radix-ui/react-tabs`, added as a dependency since no Tabs
  component existed yet) — one tab per category (Voice, Audience, Offer, Visual, Music policy,
  Hashtag policy, Content pillars, Brand persona). List-like fields (do-nots, pain points, key
  products, color palette, genres, hashtags, content pillars) are edited as one-item-per-line
  textareas and are converted to/from arrays in `UpdateBrandBrainRequest::prepareForValidation()`.
- The "Brand persona" tab has its own `<Form>` (posting to `clients.brand-brain.persona.store`,
  `multipart/form-data`) sitting alongside — not nested inside — the main `update` `<Form>`, since both
  share one `<Tabs>` context but HTML forbids nested `<form>` elements. When a persona already exists,
  its URL/filename are shown above the uploader with an embedded `<iframe>` PDF preview so users can
  review it in-page before deciding whether to overwrite it.
- Linked from the client show page (`resources/js/pages/clients/show.tsx`) via a "Brand brain" button
  next to Edit/Delete.

## Frontend
- `resources/js/pages/brand-brain/edit.tsx` opens with the same gradient-brand hero banner ("Brand
  brain" headline with `text-gradient-brand` treatment + client-name subtitle) used on the dashboard,
  calendar, clients, and post editor pages, replacing the plain `Heading` component, per the
  agency-premium brand guidelines. The tabbed form itself stays flat per the data-dense-screen rule.
  The page container is `max-w-5xl` (matching the post editor page) rather than `max-w-3xl`, so it
  no longer looks half-width next to the other main sections.

## Business Rules
- Exactly one `BrandBrain` per `Client` (`client_id` unique constraint); `updateOrCreate` keyed on
  `client_id` means the first save creates the row and every subsequent save updates it in place.
- Only Owner/Strategist can save changes; Designer/Viewer/Client reviewer can open the editor
  read-only (the "Save" button and the persona uploader are hidden client-side via `can.update`, and
  both the `update` and `persona.store` routes are policy-gated server-side regardless of the UI).
- Client reviewers are restricted to their own scoped client, matching `ClientPolicy`.
- Only one Brand Persona PDF per `BrandBrain`; uploading a new one deletes the previously stored file
  from the `AssetStorage` disk before saving the new `persona_*` columns, so overwriting never leaves
  an orphaned file behind.
- `BrandBrain::toGrokContext()` returns `{markdown, json}`: a human-readable markdown briefing (one
  section per category, with "—" placeholders for empty fields) and the raw structured array — fixed
  now so Phase 2 Grok integration has a stable contract to consume, per the Step 0.5 "Done when".

## Seeders
- `Database\Seeders\BrandBrainSeeder`: seeds a full brand brain for "Acme Corp" (created by
  `ClientSeeder`). Called from `DatabaseSeeder` right after `ClientSeeder`.

## Tests
- `tests/Feature/BrandBrainControllerTest.php`: edit/update access per role (Owner, Designer,
  Client reviewer), create-vs-update upsert behavior, cross-client reviewer denial, and Brand Persona
  upload coverage (Owner can upload, replacing a file deletes the old one from disk, Designer is
  forbidden, non-PDF uploads are rejected by validation).
- `tests/Unit/Models/BrandBrainTest.php`: `toGrokContext()` with fixture data (asserts markdown
  sections and JSON structure) and with an unsaved/empty brand brain (graceful "—" placeholders).

## Known Gaps / Open Questions
- The exact §4.3 field list from the original spec wasn't available in this repo; the current json
  column shapes (a few string fields + string-list fields per category) are an interim definition
  covering the six named categories. Revisit if the original spec surfaces later.
- No dedicated "create" UI/route — the editor always upserts, which matches the 1:1 nature of the
  model but means there's no explicit "brand brain not yet created" empty state beyond blank fields.
- `toGrokContext()`'s markdown is consumed by `App\Support\AI\ClientContextBuilder` (see `ai.md`), which
  grounds every AI trends/ideas/captions request in it. The Brand Persona PDF itself is not yet read
  by the AI (no PDF text extraction) — only the structured fields are.

## Changelog
- 2026-09-08: Initial `BrandBrain` model/migration/policy/action/controller/requests, tabbed Inertia
  editor (new `tabs.tsx` UI component + `@radix-ui/react-tabs` dependency), `toGrokContext()`,
  `BrandBrainSeeder`, and feature/unit tests (Step 0.5).
- 2026-09-09: Added a gradient-brand hero header to the brand brain editor, matching the
  agency-premium restyle applied to the dashboard, calendar, clients, and post editor pages.
- 2026-09-17: Added Brand Persona PDF upload — new `persona_*` columns, `UploadBrandPersonaAction`
  (reusing `App\Contracts\AssetStorage`), `UploadBrandPersonaRequest`,
  `BrandBrainController::uploadPersona`, and a new "Brand persona" tab in the editor with an embedded
  `<iframe>` preview of the current file plus an upload/replace form.
- 2026-10-16: Brand Brain is now consumed by every AI request (trends, ideas, caption/hashtag
  suggestions) via `App\Support\AI\ClientContextBuilder`; editing it busts the cached trends.
  No changes to the model/editor itself.
- 2026-10-17: Step 16.4 Brand Brain visual references (logo/style/product images) for the AI Image Studio: `brand_brain_reference_assets` table, `BrandBrainReferenceAsset`, `SyncBrandReferenceAssetsAction` (limit, image-only, same-client, normalised copies), `PUT clients/{client}/brand-brain/references`, and the "Visual references" tab.

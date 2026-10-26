# Assets

## Purpose
Covers the asset half of Step 0.7: `Asset`s (uploaded files, Figma links, external URLs, and AI
generated images) that belong to a client and optionally a campaign, and are consumed by
`Post`/`PostTarget` (Step 0.8+). Also covers the storage seam (`AssetStorage`), private media
(Step I-1.6) and the asset library dialog. Campaigns live in `campaigns.md` (split from the old
shared campaigns & assets doc in Step C-0.1).

## Models / Tables
- `Asset` (`assets`): `org_id`, `client_id` (FKs, cascade on delete), `campaign_id` (nullable FK,
  null on delete), `uploaded_by` (nullable FK to `users`, null on delete), `source` (enum:
  upload/figma/url/ai), `type` (nullable enum: image/video/document/other), `disk`/`path` (populated
  only for `source = upload` — whatever `config('assets.disk')` resolved to at upload time), `url`
  (always populated: the storage-resolved URL for uploads, or the given link for figma/url),
  `original_filename`, `mime_type`, `size`, `width`/`height` (not yet populated — no image-dimension
  probing implemented), `rights` (usage-rights notes), `variant_group_id` (uuid, groups asset
  variants — the AI image set uuid), `ai_image_variant_id`, `is_ai_generated`. `belongsTo`
  `Organization`/`Client`/`Campaign`, `uploader(): BelongsTo` (`User`). `Client::assets(): HasMany`.
  Soft deletes (Step I-1.5).
- The exact §4.5/§4.6/§10 field lists weren't available in this repo; the fields above are interim
  definitions in the same spirit as prior steps — revisit if the original spec surfaces later.

## Actions / Services / Contracts
- `App\Contracts\AssetStorage`: abstracts where/how an uploaded file is stored (`store`, `url`,
  `signedUrl`, `delete`, `storeContents`, `contents`) so the spec §10 Upload-Post-style
  signed-URL/S3 flow can be dropped in later without touching any Action/Controller.
- `App\Services\LocalAssetStorage`: the only implementation today. Stores files on the Laravel
  filesystem disk named by `config('assets.disk')`. Bound to `AssetStorage` in
  `AppServiceProvider::register()`. Records the file's mime type (used both for `Asset::type`
  auto-detection and by `AssetPreview` on the frontend to decide whether to render an `<img>` or
  `<video>` tag). **Not yet S3**: the `s3` disk is already defined in `config/filesystems.php`, so
  switching later should only require setting `ASSETS_DISK=s3` (plus `AWS_*` env vars).
- `App\Actions\Assets\CreateAssetAction`: creates an `Asset` for a client (optionally scoped to a
  campaign). For `source = upload`, stores the file via `AssetStorage::store()` under
  `assets/{org_id}/{client_id}` and records disk/path/url/mime/size/original_filename; for
  `figma`/`url`, just records the given `url`. If the caller didn't explicitly pass a `type`, it's
  auto-detected from the stored file's mime type (`image/*` → `image`, `video/*` → `video`,
  `application/pdf` → `document`, else `other`), so video uploads get tagged `video` without any
  manual UI selection — this feeds `UploadPostAdapter`'s Instagram REELS/IMAGE `media_type`
  inference (see `publishing.md`). Wrapped in `DB::transaction`, logs the creation.
- `App\Actions\Assets\SearchClientAssetsAction`: pages a client's assets newest first
  (`PER_PAGE = 24`, eager-loads `campaign:id,name`), optionally filtered by a case-insensitive
  `original_filename` substring (`%`/`_` escaped so input matches literally) and an `AssetType`.
  Read-only, so no transaction/logging.
- `App\Actions\Assets\CreateAssetFromBase64Action`: ingests base64-encoded media (an image a user put
  into their AI assistant, via the MCP `upload_media` tool) as an uploaded `Asset`. Decodes the bytes
  (tolerating a `data:…;base64,` prefix), wraps them in a test-mode `UploadedFile`, and validates with
  the **same rules as `StoreAssetRequest` for `source=upload`** (`mimes:` allowlist, per-type size
  ceiling, `SafeUpload` content-sniffing/SVG-refusal/virus-scan) before delegating to
  `CreateAssetAction` — so the MCP path can store nothing the web uploader would reject. Throws
  `ValidationException` on a bad file; never logs the raw bytes. See `mcp.md`.
- `App\Actions\Assets\DeleteAssetAction`: soft-deletes the row (Step I-1.5 keeps the stored file so
  the asset can be restored), wrapped in `DB::transaction`, logs the deletion.
- `App\Policies\AssetPolicy`: `viewAny`/`view` mirror `CampaignPolicy`. `create` additionally allows
  **Designer** (per spec §3: "Designer restricted to assets/Figma links"). `delete` allows
  Owner/Strategist for any asset in their org, or Designer only for assets they uploaded themselves
  (`uploaded_by === user.id`).

## Phase M-5 additions (Step M-5.9 — Canva & cloud imports)

- **`DesignImportProvider`** (`canva`/`google_drive`/`dropbox`) with an `assetSource()` →
  `AssetSource` (new `Canva`/`GoogleDrive`/`Dropbox` cases). Figma stays link-only.
- **`design_import_connections`** (`DesignImportConnection`, `BelongsToOrganization`): an org's OAuth
  connection per provider — `access_token`/`refresh_token` **encrypted** (never logged), `expires_at`,
  `account_label`, `revoked_at` (revoke keeps the row). `scopeLive()` = not revoked.
- **Contract `App\Contracts\DesignImportSource`** (per I-15.2): `authorizationUrl` / `exchangeCode`
  (OAuth2), `listFiles` (`DesignImportListing` of `DesignFile`), `download` (`DesignImportedFile`).
  A dead connection throws `App\Exceptions\DesignImportReconnectRequired`. `OAuth2ImportSource` base
  does the token plumbing (auto-refresh once, else reconnect); concrete `CanvaImportSource`
  (async export + bounded poll), `GoogleDriveImportSource`, `DropboxImportSource`. `DesignImportManager`
  resolves a source per provider and reports which are enabled (`config/design-imports.php`,
  credentials from env). `FakeDesignImportSource` backs the tests.
- **`ImportDesignAssetAction`**: downloads the file and runs it through the **normal private asset
  pipeline** — `AssetStorage::storeContents` (private disk, signed URLs), `Asset` tagged with the
  provider's `AssetSource`, optional `campaign_id` (a campaign asset kit, C-4.1), then `ProbeAssetJob`.
- **Routes** (`DesignImportController`, agency): `design-imports.connect` / `.callback` (OAuth,
  Owner/Strategist; `state` in session), `design-imports.disconnect` (revoke + scrub tokens),
  `clients.design-imports.files` (JSON list; 409 `needs_reconnect` on a dead connection),
  `clients.design-imports.import` (asset `create` on the client). UI: an "Import from Canva / Drive /
  Dropbox" action on the client Assets tab opening `DesignImportDialog` (connect / browse / import /
  reconnect). Enabled providers + connection status come from `ClientTabController::media`.
- Tests: `tests/Feature/Assets/DesignImportTest.php` (private tagged asset + probe, campaign kit,
  per-provider source, revoked → reconnect, listing 409, connect authz, disconnect scrub).

## Phase M-2 additions (M-2.4 / I-3.5)

- **Columns**: `duration_ms`, `video_codec`, `thumbnail_path`, `probed_at` (width/height now filled).
- `App\Jobs\ProbeAssetJob` (queued after every upload by `CreateAssetAction`): image size via GD
  (EXIF-oriented) + 480px WebP thumbnail; video via `App\Contracts\MediaProber`
  (`FfprobeMediaProber`, bound in `AppServiceProvider`; `assets.ffprobe_path` / `ffmpeg_path`)
  for size/length/codec + a frame thumbnail. Never fails loudly.
- `Asset::thumbnailUrl()` (falls back to the original for images, null for videos) and
  `aspectRatio()`; every asset list payload sends `thumbnail_url`, and `AssetThumbnail` /
  `AssetPreview` show it. Thumbnails are included in a client purge (`StoredFileInventory`).

## Phase M-1 additions (Step M-1.2 / I-16.2)
- `App\Rules\SafeUpload` on `StoreAssetRequest`, `UploadBrandPersonaRequest` and
  `UploadInvoicePdfRequest`: the extension must be allowed **and** the type sniffed from the
  file's content must fit it (a `.jpg` must be an image; a renamed HTML/script file is refused);
  SVG, HTML, XML and executable/script types are always refused.
- `svg` is no longer in `assets.allowed_upload_extensions` (SVG can carry script).
- `App\Contracts\VirusScanner` → `NullVirusScanner` (default) or `ClamAvVirusScanner`
  (`UPLOAD_VIRUS_SCANNER=clamav`, clamd `INSTREAM` over TCP: `CLAMAV_HOST`/`PORT`/`TIMEOUT`;
  `CLAMAV_FAIL_CLOSED` (default true) refuses uploads while clamd is unreachable).
- The editor, media tab, campaign kit and grid uploads go through the shared `http` helper
  (`frontend.md`).

## Routes / Controllers
- `GET /clients/{client}/media` → `clients.media` (`ClientTabController::media`) — the client
  Assets tab (header props only; the library is browsed through the dialog below).
- `GET /clients/{client}/assets` → `clients.assets.index` (`AssetController::index`, also under
  `preview.`) — JSON only. `SearchAssetsRequest` authorizes `AssetPolicy::viewAny` for the client
  and validates `search` (≤100 chars), `type` (`AssetType`) and `page`; delegates to
  `SearchClientAssetsAction`. Returns `{data: [{id, url, original_filename, type, is_ai_generated,
  campaign_name, created_at, can: {delete}}], meta: {current_page, last_page, per_page, total}}`.
  Backs the post editor's "Browse library" dialog (see `posts.md`) and the client Assets tab's
  library dialog.
- `POST /clients/{client}/assets` → `clients.assets.store` (`AssetController::store`) — validated via
  `StoreAssetRequest` (conditional rules: `file` required when `source = upload`, `url` required when
  `source` is `figma`/`url`; `file` restricted to `config('assets.allowed_upload_extensions')` and
  sized against `config('assets.max_video_upload_size_kb')` instead of `max_upload_size_kb` whenever
  the file's mime type starts with `video/`), delegates to `CreateAssetAction`. Redirects for a
  normal request; JSON callers get the created asset (`id`/`url`/`original_filename`/`type`) as `201`.
- `DELETE /assets/{asset}` → `assets.destroy` (`AssetController::destroy`) — delegates to
  `DeleteAssetAction`. Redirects to `clients.media`, or returns `204` for JSON callers.
- `resources/js/pages/clients/tabs/media.tsx`: the client Assets tab — `UploadTile` and
  `BrowseLibraryTile` (from `components/post-editor/media-picker.tsx`) and `AssetLibraryDialog` in
  `manage` mode.

## Business Rules
- Owner/Strategist/**Designer** can upload/link assets; Designer may only delete assets they
  personally uploaded. Viewer/Client reviewer cannot create assets (Client reviewer can view them via
  `viewAny`, same scoping as elsewhere).
- An asset's `disk`/`path` are only set for `source = upload`; `url` is always set (resolved via the
  storage disk for uploads, or the literal given link for figma/url).
- `max_upload_size_kb` (default 50MB) bounds non-video uploads; `max_video_upload_size_kb` (default
  500MB) is used instead whenever the uploaded file's mime type starts with `video/`.
- `StoreAssetRequest`'s `file` rule restricts uploads to `config('assets.allowed_upload_extensions')`.
- `Asset::type` is auto-detected from the upload's mime type in `CreateAssetAction` when the caller
  doesn't explicitly pass one.
- `AssetPreview` (`resources/js/components/asset-preview.tsx`) renders a `<video controls>` element
  for video uploads and an `<img>` for images.
- Private media (Step I-1.6): `config('assets.disk')` defaults to the private `local` disk, served
  only through signed, expiring URLs at `/files/*`.

## Tests
- `tests/Feature/AssetControllerTest.php`: uploads (Owner, Designer, video auto-typing, video size
  limit, disallowed extensions), Figma links, validation, Viewer forbidden, deletion, Client
  reviewer scoping, the paginated/searchable/type-filtered library JSON, per-asset `can.delete`,
  JSON delete `204`.
- `tests/Unit/Services/AI/Images/ImageStorageTest.php`: AI image storage.

## Known Gaps / Open Questions
- S3 storage and direct uploads (Step M-1.3) are deferred until the bucket exists; uploads still go
  through PHP to the private local disk.
- **PHP ini limits must accommodate `max_video_upload_size_kb`**: PHP's own
  `upload_max_filesize`/`post_max_size`/`memory_limit` are enforced before `StoreAssetRequest` runs.
  See `ops.md` (Step I-2.8) for the production values. nginx's `client_max_body_size` defaults
  to 1 MB and rejects larger bodies with an HTML 413 before Laravel runs; both upload UIs
  (`posts/edit.tsx`, `clients/tabs/media.tsx`) show a specific "larger than the server accepts"
  toast for a 413.
- `width`/`height` columns exist but nothing probes uploaded image dimensions yet.
- Storage is local-only for now; `App\Contracts\AssetStorage` is the seam for real S3 signed-URL
  uploads (spec §10).
- Figma/URL assets and the `rights` field can no longer be created from the UI (the endpoint still
  accepts them).
- Campaign asset kit (Step C-4.1) will add a `pick-for-campaign` mode to `AssetLibraryDialog`; see
  `campaigns.md`.

## Changelog
- 2026-10-26: Media over MCP — `CreateAssetFromBase64Action` ingests base64 media from the AI
  assistant through the same `SafeUpload`/size validation + `CreateAssetAction` pipeline the web
  uploader uses (reuse, not a second path). See `mcp.md` for the `upload_media`/`set_post_media` tools.
- 2026-10-23: Step M-5.9 — Canva & cloud imports. `DesignImportProvider` + `AssetSource` cases,
  `design_import_connections` (encrypted tokens), `DesignImportSource` contract + `OAuth2ImportSource`
  base + Canva/Drive/Dropbox sources + `DesignImportManager` + `FakeDesignImportSource`,
  `ImportDesignAssetAction` (private pipeline + probe + campaign kit), `DesignImportController` +
  routes, `DesignImportDialog` on the client Assets tab. `config/design-imports.php`. Tests
  `tests/Feature/Assets/DesignImportTest.php`. See "Phase M-5 additions".
- 2026-10-20: Phase 18 (grid planner) — `assets.source_asset_id`: `SplitAssetIntoGridTilesAction` slices one image into 3/6/9 derived 1080×1440 assets pointing back at the source (never changed); `grids:prune` soft-deletes split slices only when no post or live grid uses them. Grid tiles hold assets through `grid_tile_assets` (same framing columns as `post_assets`).
- 2026-10-20: Phase M-2 — media probing + thumbnails (`ProbeAssetJobTest`).
- 2026-10-19: Upload UIs show a clear toast when the web server answers 413 (body too large)
  instead of the generic "Unable to upload the asset."
- 2026-10-18: Campaigns enhancement C-4.1 — the campaign asset kit tags/untags `assets.campaign_id` (`UpdateCampaignAssetKitAction`, never deletes; Designer allowed; only the campaign's client's assets). The asset search gains a `campaign` filter and the editor's asset library can default to the campaign's kit.
- 2026-10-18: Step C-0.1 — split out of the shared campaigns & assets doc into this file and
  `campaigns.md`. The asset history below is moved across verbatim; campaign history lives in
  `campaigns.md`.
- 2026-10-18: The client Assets tab (`clients/tabs/media.tsx`) reuses the post editor's
  `UploadTile`/`BrowseLibraryTile` and opens `AssetLibraryDialog` in a new `manage` mode.
  `clients.assets.index` gained `can.delete`; `assets.destroy` answers JSON with `204`.
- 2026-10-18: Step I-2.4 — assets have their own client tab (`GET /clients/{client}/media` →
  `clients.media`). Production `php.ini` limits for video uploads are documented in `ops.md`.
- 2026-10-18: Step I-1.6 — private media: signed, expiring URLs at `/files/*`;
  `AssetStorage::signedUrl()`; `Asset::url` is an accessor minting a fresh signed URL.
- 2026-10-18: Step I-1.5 — `Asset` uses `SoftDeletes`; `DeleteAssetAction` keeps the stored file.
- 2026-10-17: Phase 16 (Step 16.2) — `AssetSource::Ai`, `assets.ai_image_variant_id` +
  `assets.is_ai_generated`, `variant_group_id` used, `AssetStorage::storeContents()`/`contents()`.
- 2026-10-16: Added `clients.assets.index` (`AssetController::index` + `SearchAssetsRequest` +
  `SearchClientAssetsAction`).
- 2026-09-23: `AssetController::store` returns `201` JSON for JSON callers.
- 2026-09-19: Video upload support (`allowed_upload_extensions`, `max_video_upload_size_kb`, mime
  type auto-detection).
- 2026-09-16: Assets moved from a collapsible card to a tab on the client page; demo assets seeded
  by `DemoContentSeeder`.
- 2026-09-08: Initial `Asset` model/migration/policy/actions/controller/request, the `AssetStorage`
  contract + `LocalAssetStorage` implementation, factories, and feature tests (Step 0.7).
- 2026-10-20: Step M-1.2: `SafeUpload` (content sniffing, SVG blocked) and the `VirusScanner` hook (ClamAV); uploads via the shared `http` helper.

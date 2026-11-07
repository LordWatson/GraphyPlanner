# Ops (infrastructure, queues, database)

## Purpose
How Graphy runs in production: the database (**MySQL 8** — the test suite uses in-memory SQLite),
Redis for queues/cache/sessions/rate limits, Laravel Horizon for the workers, and the server limits
the app depends on. Covers Steps I-2.1 (indexes) and I-2.8 (infrastructure drivers) of
`.junie/plans/improvements.md`.

## Models / Tables
- No models. Index work (Step I-2.1, `2026_10_18_120000_add_hot_path_indexes.php`):
  - InnoDB already creates an index for every foreign-key column, so on MySQL the migration adds
    the composite / non-FK hot filters: `posts (client_id, status)`; `post_targets
    (scheduled_at_utc)`, `(scheduled_local_date)`, `(status, updated_at)`, `(external_post_id)`;
    `social_accounts (org_id, connection_status)`; `assets (client_id, created_at)`; `clients
    (org_id, name)`; `post_activity_logs (created_at, post_id)`.
  - A composite that leads with an FK column replaces InnoDB's implicit FK index, so `down()`
    re-adds a plain index before dropping one a constraint still needs (MySQL error 1553).
  - A final pass indexes any FK column with no leading index (needed on SQLite only).
  - `tests/Feature/Database/IndexCoverageTest.php` fails if any FK column lacks a leading index, or a
    hot-filter index disappears — on SQLite in the normal run, and on MySQL when run with
    `DB_CONNECTION=mysql`.

## Actions / Services / Contracts
- `App\Support\Queues`: the queue names — `publishing`, `webhooks` (reserved for Step I-16),
  `notifications`, `mail`, `ai`, `reports`, `default`. AI Image Studio jobs keep
  `config('ai.images.queue')` (`ai-images`). `Queues::all()` lists every one, highest priority
  first (`publishing` first, `default` last).
  - `PublishPostJob`, `PublishPostTargetJob` → `publishing` (set in their constructors).
  - Every mailable (they all implement `ShouldQueue`) → `mail`; every notification's
    `viaQueues()` → `database: notifications`, `mail: mail`.
  - `PurgeOrganizationJob` → `default`.
- `App\Providers\HorizonServiceProvider`: `/horizon` is for super admins only, in every environment
  (Horizon's default lets anyone in on `local`).

## Configuration
- `config/horizon.php` — one supervisor per workload, each with its own timeout and retries
  (a job's own `$tries`/`$timeout` win):

  | Supervisor | Queues | timeout | tries | prod maxProcesses |
  |---|---|---|---|---|
  | `publishing` | `publishing` | 200 s | 3 | 8 |
  | `messaging` | `webhooks`, `notifications`, `mail` | 90 s | 3 | 4 |
  | `ai` | `ai`, `ai-images` | 150 s | 2 | 4 |
  | `background` | `reports`, `default` | 620 s | 3 | 2 |

  A long-wait alert fires when `redis:publishing` waits more than 30 s.
- `config/queue.php`: default `redis`; `redis.retry_after` 660 s (must stay above the longest
  supervisor timeout, or a slow job runs twice).
- `config/cache.php`: default `redis`; new `cache.limiter` (`CACHE_LIMITER`) picks the store rate
  limiters count in. `config/session.php`: default `redis`.
- `.env.example` is production-shaped (MySQL, Redis everywhere). For a local box without Redis set
  `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` to `database` and run
  `php artisan queue:work --queue=publishing,webhooks,notifications,mail,ai,ai-images,reports,default`
  instead of Horizon. A worker without `--queue` only works `default`: scheduled posts never reach
  Upload-Post and mail/notifications never send, with nothing in the log.
- `php artisan dev`: Horizon swaps the framework's `queue` dev command for `php artisan horizon`.
  Unless the queue connection is `redis` *and* `ext-pcntl` is loaded,
  `AppServiceProvider::registerDevQueueWorker()` drops `horizon` again and runs
  `queue:listen` on `Queues::all()` instead (Horizon can't run on Windows or work the `database`
  connection, so before this nothing was processed locally).
- Horizon needs `ext-pcntl`/`ext-posix` (Linux only). On the Windows dev box it was installed with
  `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`; `php artisan horizon` itself
  can't run there.
- Production runs: `php artisan horizon` (supervised, e.g. systemd/Supervisor), `php artisan
  schedule:work` or a cron for `schedule:run`, and `php artisan horizon:terminate` on deploy.

## PHP limits (video uploads)
- Uploads go through PHP today, so `upload_max_filesize` and `post_max_size` must be ≥
  `config('assets.max_video_upload_size_kb')` (500 MB by default) and `memory_limit` must cover
  the request; otherwise PHP drops the body before `StoreAssetRequest` runs. Example `php.ini`:
  `upload_max_filesize = 512M`, `post_max_size = 520M`, `memory_limit = 512M`,
  `max_execution_time = 300`. The web server's body limit (nginx `client_max_body_size`) must
  match. Direct-to-storage uploads (Step I-7.1) will remove this dependency.

## Phase M-1 additions (Steps M-1.1 / I-16.1, M-1.2 / I-16.2)

### Observability
- **Correlation id.** `AssignCorrelationId` (global, first) reuses a well-formed incoming
  `X-Request-Id` (`config('observability.correlation_header')`) or mints a UUID, puts it in
  Laravel `Context` and echoes it on the response. `ShareRequestContext` (web) adds `user_id` and
  `org_id`. `Context` is on every log line, is carried into every queued job the request
  dispatches, and rides on every Sentry event. Jobs dispatched outside a request (scheduler) and
  console commands get their own id (`ObservabilityServiceProvider`). Keys live in
  `App\Support\Observability\TraceContext`; the publishing jobs add `post_id` /
  `post_target_id`, and the webhook adds `publish_correlation_id` (see `publishing.md`).
- **Error tracking.** `App\Contracts\ErrorTracker` → `SentryErrorTracker` when
  `SENTRY_LARAVEL_DSN` is set, else `NullErrorTracker`. `bootstrap/app.php` sends every reported
  exception to it (`reportable`) and turns on `dontReportDuplicates()`. Sentry tags: correlation,
  user, org, post, target ids; only the user id is sent (`send_default_pii` off).
  `SecretScrubber::beforeSend` (wired in `config/sentry.php`) strips passwords, tokens, keys,
  signatures, cookies and auth headers from request data, extra and contexts.
- **Alerts.** `App\Contracts\OpsAlerter` → `NotifyingOpsAlerter`: logs `critical` on
  `OPS_ALERT_LOG_CHANNEL` (e.g. `slack`) and emails `OPS_ALERT_MAIL_TO` (`OpsAlertMail`, sent
  inline because the alert may be about the queue). One alert per key per
  `OPS_ALERT_THROTTLE_MINUTES` (repeats log at `warning`). Fired by
  `AlertOnFailedPublishingJob` (any job on the `publishing` queue that fails for good) and by the
  Upload-Post webhook on a bad signature.
- **Structured logs.** New `json` log channel (Monolog `JsonFormatter`, `LOG_JSON_PATH`);
  production uses `LOG_STACK=json`. `Context` lands under `extra`.
- **Pulse.** `laravel/pulse` at `/pulse` — slow queries, jobs, outgoing requests, exceptions.
  Super admins only (`viewPulse` gate). Production: `PULSE_INGEST_DRIVER=redis` +
  `php artisan pulse:work` (supervised) and `php artisan pulse:check` for the servers card.
  `PULSE_ENABLED=false` in PHPUnit.

### Security hardening
- `SecurityHeaders` (global): CSP with a per-request nonce for scripts (Vite tags and the inline
  theme script carry it), `'unsafe-inline'` styles (React `style`, Radix, Recharts),
  `frame-ancestors 'self'` + `X-Frame-Options: SAMEORIGIN` (the app frames its own PDFs),
  `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS on HTTPS only. `config/security.php`:
  `SECURITY_CSP_REPORT_ONLY`, `SECURITY_CSP_REPORT_URI`, and `csp.except` (Horizon, Pulse,
  Livewire get every header but the CSP — they ship inline scripts). While `npm run dev` runs, the
  Vite origin is allowed; an IPv6 dev host (`[::1]`) is allowed by scheme, because browsers ignore
  IPv6-literal CSP sources (regression-tested).
- Session cookie is `secure` by default when `APP_ENV=production`.
- CI fails on high/critical advisories: `composer audit … --ignore-severity=low
  --ignore-severity=medium` and `npm audit --audit-level=high`.
- Password confirmation (`password.confirm`, 3 h window): org settings (keys + webhook secret),
  invoice/analytics/report CSV exports. Irreversible deletes re-check `current_password` in the
  request: client purge (`PurgeClientRequest`), super-admin org delete.
- Uploads: see `assets.md` (content sniffing, no SVG, virus-scan hook).

### CI (`.github/workflows/tests.yml`)
- PHP **8.5** (the lock needs ≥ 8.4.1 via Symfony 8, so 8.3 couldn't install); SQLite + array
  drivers for the runner; audits; `composer ci:check`; `npm test` (Vitest); Playwright journeys
  (`npm run test:e2e`, see `frontend.md`).

## Step GTM-5.1 — the nightly ad-spend sweep

`ads:sync` (04:30) queues one `SyncOrganizationAdsJob` per organization **that has ad accounts**,
unique per org, on the reports queue. It re-reads the whole `ads.window_days` window every night
rather than only yesterday, because ad platforms restate recent days as conversions land.

**It is off until configured.** `ADS_PROVIDER` is unset by default, which binds `NullAdsSource`, so
every read returns nothing and the panels show gaps. Turning it on needs `ADS_PROVIDER=meta` **and**
`ads_read` granted on our own Facebook app (Phase 5). Until both are true, leave it off: honest
gaps beat confident wrong numbers in a spend report.

## Step GTM-7.2 — the competitor review nag

`competitors:review-due` (daily 09:00) is silent until `COMPETITORS_LAST_CHECKED` is more than
`competitors.review_every_days` old, then raises one `OpsAlerter` alert (which is itself throttled).
It checks **nothing automatically**: vendors move prices behind marketing pages and A/B tests, and a
scraper would put a confident wrong price on a public comparison page. Re-read each vendor's own
pricing page, update `config/competitors.php`, move the date.

## Step GTM-6.1 — the Play listing and `assetlinks.json`

The Android app is a TWA generated by Bubblewrap from `android/twa-manifest.json`; there is no
Android source in the repository and the keystore is **not** committed (losing it means losing the
ability to publish updates). `/.well-known/assetlinks.json` is served from `config/twa.php` and
**404s until `ANDROID_PACKAGE` and `ANDROID_FINGERPRINTS` are set**, rather than serving a file that
would fail Android's verification quietly. The symptom of getting this wrong is an address bar
across the top of the installed app, which reads like a Play problem and is a deploy problem. Full
steps in `android/README.md`.

## Step GTM-4.1 — the nightly competitor sweep

`benchmarks:sync` (04:00, in `SchedulerHeartbeat::COMMANDS`) queues one
`SyncOrganizationBenchmarksJob` per organization **that has tracked profiles** {D} orgs that don't
use the feature are never queued, so it costs them nothing. The job is unique per org and runs on
the reports queue, so it can never sit in front of a publish.

The caps in `config/benchmarks.php` are about Meta's rate limits rather than ours: a profile
already read today is skipped, and a run stops after `max_profiles_per_org` (oldest-read first),
with the remainder going the next night. Nothing here needs an operator step {D} it uses the
Facebook Login tokens the grid preview already relies on.

## Step GTM-3.2 — custom domains for bio pages (DNS and TLS)

This is the one step with real infrastructure surface, and **the app does not own most of it**.

- **What the app does.** `bio_page_domains` holds the hostname; `VerifyBioPageDomainAction` checks
  public DNS through the `DnsResolver` contract and sets or clears `verified_at`; a verified
  hostname's **root** serves that creator's bio page (`BioPageController@home`). Nightly,
  `bio-domains:reverify` (03:30, in `SchedulerHeartbeat::COMMANDS`) re-checks every domain so a
  repointed one stops serving and a newly propagated one starts.
- **What the operator must do.**
  1. Point `BIO_DOMAIN_TARGET` (`config/bio.php`) at the hostname creators should CNAME to. It
     defaults to the `APP_URL` host, which is right for a single-server deploy and wrong behind a
     proxy or CDN — set it explicitly there.
  2. Accept traffic for **unknown** `Host` headers. Nothing else is needed in the app (routes are
     not host-scoped), but the web server or load balancer has to stop rejecting hostnames it has
     no vhost for.
  3. **Issue the certificates.** Alima cannot: there is no ACME client here. Terminate TLS in
     front of the app with on-demand/wildcard-capable TLS (Caddy's `on_demand_tls`, a Cloudflare
     for SaaS custom-hostname setup, or an ALB with per-host certs). `bio_page_domains.ssl_status`
     is a **record of what that layer reports**, not something the app drives, and nothing writes
     anything but `pending` to it today.
  4. If on-demand TLS is used, point its ask-endpoint at a check that the hostname exists and is
     verified, or the server will try to get a certificate for every hostname aimed at it.
- **Why DNS is behind a contract.** `App\Contracts\DnsResolver` (`SystemDnsResolver` over
  `dns_get_record`) exists so verification can be tested without the network. Implementations
  **never throw**: a failed lookup is "not verified yet", which is also what it means to a creator
  who hasn't finished their DNS.

## Data retention

The only retention window the application enforces by itself is the one on **data exports**:

- `config('exports.retention_days')` (default **7**, `EXPORTS_RETENTION_DAYS`) — how long a finished
  organisation data export stays downloadable. `exports:purge-expired` (daily, 02:30, in
  `SchedulerHeartbeat::COMMANDS`) deletes the archive, clears `data_exports.disk`/`path` and marks
  the row `expired`. **The row stays** as a receipt: an owner asking "was a copy of everything taken,
  and is it gone?" can read the answer. A file that will not delete is logged and the row is still
  marked expired — the link must stop working even when the storage call fails.
- `DataExport::isDownloadable()` re-checks the expiry on every download, so a link in somebody's
  downloads list stops working at the window's end whether or not the sweep has run yet.

Everything else is kept until the customer deletes it, and an organisation delete removes it all
(`DeleteOrganizationAction` soft-deletes, `PurgeOrganizationJob` then removes every stored file and
hard-deletes the rows). That is the policy; if these numbers change, the policy changes.

## Business Rules
- Publishing must never share workers with AI or report work (asserted in
  `tests/Feature/QueueInfrastructureTest.php`, which also checks every supervisor timeout is below
  `retry_after`).
- **CI runs the suite on both engines.** The `ci` job uses SQLite (fast, no service); the `mysql`
  job runs it against MySQL 8 with `graphy_test` as the database. Tests on one engine only are not
  enough: `AGENTS.md` requires SQL to work on both, and a `LIKE ... ESCAPE '\'` clause shipped in
  three places because MySQL rejects it (error 1064) while SQLite accepts it.
- **`/up` fails while asset storage is misconfigured** (`ASSETS_DISK` naming a disk that does not
  exist, or an `s3` disk with no bucket/key/secret), so a half-finished storage switch is caught by
  the load balancer rather than by the first user to upload a file. See
  `ObservabilityServiceProvider::assetDiskMisconfiguration()`.
- **A single app boot peaks at ~36 MB** with all providers and 627 routes. CI asserts a boot
  succeeds at PHP's 128 MB default, so a provider that starts loading the world fails the build
  instead of quietly raising every worker's memory floor. The *test suite* needs more (512 MB, set
  in `phpunit.xml`) because PHPUnit keeps every test's application alive — a different thing, and
  not a sign the app is heavy.

## Tests
- `tests/Feature/Database/IndexCoverageTest.php`, `tests/Feature/QueueInfrastructureTest.php`.

## Benchmarks (local MySQL 8.0.40, Windows, 2026-10-18)
One org, 50 clients, 50,000 posts / targets, 100,000 activity-log rows (`scratchpad` script, not
committed):

| Path | Time | Queries |
|---|---|---|
| Home needs-attention (realistic mix) | 139 ms | 14 |
| Home needs-attention (stress: 20k active drafts, no media) | 457 ms | 20 |
| Calendar month window (~3k targets in view) | 175 ms | 4 |
| Org analytics summary, 90 days, cold | 1.3–1.6 s | 46 |
| Org analytics summary, cached | 0.3 ms | 0 |

`EXPLAIN`: calendar window → `range` on `post_targets_scheduled_local_date_index`; upcoming →
`range` on `post_targets_scheduled_at_utc_index`; Home buckets → `ref` on
`posts_client_id_status_index`; webhook lookup → `ref` on `post_targets_external_post_id_index`.

## Known Gaps / Open Questions
- Sentry, the alert mailbox and Pulse's Redis ingest need production values (`SENTRY_LARAVEL_DSN`,
  `OPS_ALERT_MAIL_TO`, `PULSE_INGEST_DRIVER=redis` + `pulse:work`) before the M-8 release.
- 41 PHPStan errors pre-date Phase M-1 (44 on `edbfebb`; three fixed here in the webhook
  controller). None are in Phase M-1 code; `composer types:check` stays red until they're fixed.
- Cold analytics misses the 500 ms target — see `analytics.md` (needs `org_id` on
  `post_activity_logs`).
- No failed-job alerting or error tracking yet (Step I-16.1).
- ~~Three classes of MySQL-only failure (org purge hitting `SQLSTATE 1451`, `TIME` columns read back
  with seconds, JSON key reordering).~~ **Fixed 2026-11-07** — all 14 affected tests pass on both
  engines, and the `mysql` CI job is blocking so the class cannot come back. See the changelog.

## Changelog
- 2026-11-07: Added the data-export retention sweep (`exports:purge-expired`, daily 02:30) and the
  "Data retention" section above. See `settings.md` for the export feature itself.
- 2026-11-07: **The dependency audit gate passes again, and email deliverability can be checked.**
  Symfony updated 8.1.9 → 8.1.11 across the board, clearing 11 advisories on 6 packages that had
  been failing `composer audit` in CI independently of any code change (audit finding 28). New
  `php artisan mail:check-dns` reports SPF, DKIM and DMARC on `mail.from.address` and exits non-zero,
  so it can be a release-checklist step — reusing the `DnsResolver` contract built for bio-page
  domains, so it needs no new seam and never touches the network in tests. New `MAIL_DKIM_SELECTOR`
  and `RESEND_WEBHOOK_SECRET`. Audit item 17 (the code half; the DNS records themselves are still
  outstanding).
- 2026-11-07: **The suite now runs on MySQL in CI, and the 14 failures that exposed are fixed.**
  New blocking `tests (mysql)` job (MySQL 8 service, `graphy_test`, `DB_CONNECTION=mysql` — PHPUnit's
  `<env>` does not overwrite an already-set variable, so no second config file is needed), plus a
  boot smoke test at PHP's 128 MB default. `phpunit.xml` now sets `memory_limit=512M` for the suite
  itself (PHPUnit holds every test's application alive; a single boot peaks at ~36 MB).
  `brianium/paratest` added so `artisan test --parallel` works.
  The failures it found, all previously invisible:
  * **`PurgeOrganizationJob` threw `SQLSTATE 1451` on MySQL**, so a deleted organisation was never
    actually purged. `invoices.client_id` was set to `NO ACTION` believing that is deferred to the
    end of the statement; InnoDB treats it as `RESTRICT` and checks it immediately. The job now
    deletes the org's invoices inside its transaction before `forceDelete()`, and the migration's
    comment has been corrected. A *client* purge still refuses while invoices exist, by design.
  * **`post_targets.scheduled_local_time` read back as `09:00:00`** — the schema's only `TIME`
    column. It reached the frontend unchanged, so the calendar and post editor showed seconds in
    production and never in a test. A `PostTarget` accessor now normalises reads to `H:i`, which
    fixes every reader at once.
  * **Stored JSON key order** differs by engine; five tests used order-sensitive `assertSame` on
    JSON columns and now use `assertEquals` (order-insensitive on object keys, still ordered on
    nested lists).
  * **`Schema::getTables()` / `getTableListing()` are server-wide on MySQL** (388 tables across
    eight databases on a dev machine). Both call sites are now scoped to the connection's own
    database — which also means
    `SecretsAndPrivateMediaTest::test_no_database_column_holds_a_raw_review_token` actually runs
    there now instead of erroring on a table from another project.
  See finding 29 in `.junie/plans/market-readiness-audit.md`.
- 2026-10-21: Phase M-3 — scheduler: `analytics:sync-engagement` daily 04:00 (jobs on `reports`), `analytics:send-client-reports` hourly, `clients:recompute-health` now hourly; all registered in `SchedulerHeartbeat`. New config files `config/analytics.php`, `config/clients.php`.
- 2026-10-18: Created. Steps I-2.1 (hot-path indexes + coverage test) and I-2.8 (Redis defaults,
  Horizon with per-workload supervisors, named queues, super-admin-only dashboard).
- 2026-10-20: `Queues::all()`; `php artisan dev` falls back from Horizon to `queue:listen` on every
  named queue when Horizon can't run (no worker ran locally, so scheduled posts never reached
  Upload-Post and mail/notifications piled up).
- 2026-10-20: Phase M-1: correlation ids in `Context`, Sentry `ErrorTracker` with secret scrubbing, `OpsAlerter` (failed publishing jobs, bad webhook signatures), JSON log channel, Pulse; security headers/CSP, secure session cookie, audits in CI, password confirmation for keys/exports/purges; CI on PHP 8.5 with Vitest + Playwright.

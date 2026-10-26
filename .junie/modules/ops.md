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

## Business Rules
- Publishing must never share workers with AI or report work (asserted in
  `tests/Feature/QueueInfrastructureTest.php`, which also checks every supervisor timeout is below
  `retry_after`).

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
- On MySQL, `PurgeOrganizationJob` fails when the org has invoices (`SQLSTATE 1451` on
  `invoices_client_id_foreign`): `invoices.client_id` is `NO ACTION` (Step I-1.5) and deleting the
  organization reaches clients before invoices. The SQLite suite passes, so it isn't caught there
  (`ClientArchiveTest::test_deleting_an_organization_soft_deletes_it_then_purges_its_files` fails
  when run on MySQL, on the base commit too). Pre-existing; not changed here.
- Two more tests fail only on MySQL, also pre-existing: calendar times come back as `09:00:00`
  (MySQL `TIME`) where tests expect `09:00`, and saved-report filters come back with JSON keys
  reordered (MySQL normalizes JSON key order).

## Changelog
- 2026-10-21: Phase M-3 — scheduler: `analytics:sync-engagement` daily 04:00 (jobs on `reports`), `analytics:send-client-reports` hourly, `clients:recompute-health` now hourly; all registered in `SchedulerHeartbeat`. New config files `config/analytics.php`, `config/clients.php`.
- 2026-10-18: Created. Steps I-2.1 (hot-path indexes + coverage test) and I-2.8 (Redis defaults,
  Horizon with per-workload supervisors, named queues, super-admin-only dashboard).
- 2026-10-20: `Queues::all()`; `php artisan dev` falls back from Horizon to `queue:listen` on every
  named queue when Horizon can't run (no worker ran locally, so scheduled posts never reached
  Upload-Post and mail/notifications piled up).
- 2026-10-20: Phase M-1: correlation ids in `Context`, Sentry `ErrorTracker` with secret scrubbing, `OpsAlerter` (failed publishing jobs, bad webhook signatures), JSON log channel, Pulse; security headers/CSP, secure session cookie, audits in CI, password confirmation for keys/exports/purges; CI on PHP 8.5 with Vitest + Playwright.

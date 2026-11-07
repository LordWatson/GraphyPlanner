# Alima / Graphy — Audit & Market-Readiness Plan

**Date:** 2026-11-07 · **Branch:** `master` @ `f111a3b` · **Scope:** whole repo

> **Status (2026-11-07)**
>
> **Phase one — done.** Findings 1, 2, 3, 4 fixed with tests, plus 15. GTM-0.3 (tax and entity)
> is not code and still needs an accountant. Finding 1's fix is now **confirmed against real
> MySQL 8.0.46**: `escape '\'` returns error 1064, `escape '!'` is accepted.
>
> **Phase two — code done, deployment outstanding.** Finding 11 ✅ (and **corrected** — my original
> diagnosis was wrong; see the note on it). Finding 5 ◐ — the S3 adapter, the migration command and
> the health check are in, but the bucket, CDN and env switch are infrastructure, so **you still
> cannot run a second web server until someone does those**.
>
> **Also closed on the way:** the MySQL CI gap, which was the most important item carried over from
> phase one — and then finding 29, the 14 failures it exposed.
>
> **Phase four — partly done.** Every carried-over "should fix" and "nice to have" is now closed:
> findings 6 (booking race), 8 (broadcast scale), 13 (test chunk in the build), 14 (callback
> throttle), 16 (duplicate docs row), and 12 instrumented and spread. **Items 18, 19 and 23 — the
> public API, client onboarding templates and retainer profitability — are not started**; they are
> the three features of the first-quarter group and each deserves its own run.
>
> **New finding:** 30 — `npm run check` has been failing CI on three oversized editor components,
> independently of any change here. It also means my earlier claim that every CI gate passes was
> wrong: I had checked `composer audit`, Pint, PHPStan, `tsc` and the suite, but not the frontend
> guards.
>
> **Phase three — done.** The leftovers (findings 27, 28 and the webhook-secret banner), 7 (AI
> credit claims), 10 (team chat), 9 (per-client staff access), and the code half of item 17 (email
> suppression plus an SPF/DKIM/DMARC check). Item 17's DNS records and the two-domain split are not
> code and remain outstanding.
>
> **Verification as of 2026-11-07:** **2,397 tests, all passing on SQLite *and* MySQL 8.0.46**
> (14,517 assertions, identical on both). Pint green, `tsc` green, PHPStan at its pre-existing 87
> errors with nothing new. `composer audit` is clean. `npm run check` is **not** — see finding 30.
>
> **New findings raised by this phase:** 17 (org purge leaves the brand logo behind), 18
> (`composer audit` already fails CI) and 19 (fourteen pre-existing failures that only happen on
> MySQL) — numbered at the end of Part 1.
>
> **Finding 19 is now also fixed** (2026-11-07), including the real one: a deleted organisation was
> never actually purged on MySQL. The `tests (mysql)` job is **blocking**. 17 and 18 remain open.

## What this repo does

Graphy is a Laravel 13 + Inertia/React product for running social media on behalf of other people.
An agency (or a single business/creator) keeps its clients, brands, campaigns and posts here,
sends posts to the networks through a publish adapter (Upload-Post), gets the results back by
webhook, and shows clients what happened through a portal, a link-in-bio page, a store, invoices
and reports.

**Scale I judged it against:** a real multi-tenant SaaS. Hundreds to thousands of organisations,
each with tens of clients and social accounts, many web requests and queue workers running at the
same time, and real money moving through Stripe. Not a single-user script.

**What I actually did:** read the entry points (`routes/web.php`, `bootstrap/app.php`,
`routes/console.php`), the publishing pipeline, both webhook endpoints, the money path (store
checkout, orders, coupons, refunds), tenancy scoping, the AI quota guards, asset storage, the
build output, and the module docs. I ran the test suite: **2,291 tests, 2,291 passed, 14,119
assertions, 148s** (serially, with `memory_limit=1G` — see finding 11). Part 2 is grounded in
competitor and agency pain-point research; sources are listed at the end.

---
---

# Part 1 — Engineering findings

Grouped by importance, numbered so you can say "do 1, 3 and 9".

## Must fix

### 1. Post search and asset search crash on MySQL — ✅ fixed 2026-11-07

**Where:** `app/Actions/Posts/ListPostsAction.php:53`,
`app/Actions/Posts/ListClientPostsAction.php:33`,
`app/Actions/Assets/SearchClientAssetsAction.php:32`

**What this is:** When a user types into the search box on the posts list, the client portal's
post list, or the asset library, these three places build a `LIKE` query. They add an `ESCAPE`
clause so a `%` the user typed is treated as a plain character rather than a wildcard.

**Problem:** The SQL text they send is `escape '\'` — a single backslash inside single quotes.
MySQL treats a backslash inside a string as an escape character by default, so `\'` escapes the
closing quote, the string is never closed, and MySQL answers with a syntax error. SQLite does not
use backslash escapes, so the same text is a valid one-character string there. **The whole test
suite runs on SQLite, so all 2,291 tests pass and the bug is invisible.** Production runs MySQL 8.
I verified the exact bytes PHP produces — `27 5c 27`, i.e. `'\'` — for all three call sites, and
`config/database.php` sets no `modes`, so MySQL runs with backslash escaping on.

Concrete case: a user opens Posts, types `launch`, and gets a 500 error page. Same for a client
searching their own posts in the portal, and for anyone searching the asset library.

**Fix — step by step:**
1. Stop using backslash as the escape character. Pick one no SQL engine treats specially.
2. Add one shared helper, because the same two lines are copied three times. In `app/Support/`
   add `LikeSearch`:
   ```php
   /** A LIKE pattern matching the user's text literally, with `!` as the escape char. */
   public static function literal(string $search): string
   {
       return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)).'%';
   }
   ```
3. In each of the three Actions, replace the `addcslashes(...)` and `whereRaw(...)` lines with:
   ```php
   $query->whereRaw("lower({$column}) like ? escape '!'", [LikeSearch::literal($search)]);
   ```
   `'!'` needs no backslash, so it is safe in both MySQL and SQLite.
4. Add one test that searches for a term containing `%` and `_`, asserting the literal row is
   found and a wildcard match is not.
5. **Run that test against MySQL, not only SQLite.** Add a CI job that points
   `DB_CONNECTION=mysql` at a MySQL 8 service and runs at least the search and analytics tests.
   This is the step that stops the next bug of this kind.

**If we skip it:** Search is broken for every user on day one of production, and nothing in CI
will tell you.

**Done:** new `app/Support/LikeSearch.php` (escapes with `!`), the three Actions now call it, and
`addcslashes` is gone from all of them. Tests: `tests/Unit/Support/LikeSearchTest.php` (6 cases,
including "the escape character is never a backslash"),
`ContentHubTest::test_caption_search_matches_the_term_literally`,
`AssetControllerTest::test_asset_library_search_handles_a_backslash_in_the_term`.
**Step 5 is still open:** there is no MySQL CI job, so this is still only proven on SQLite. That
matters more than the other open steps in this document — the bug it would have caught is the one
above. See "Still open after phase one".

---

### 2. A paid order can be lost forever by the Stripe Connect webhook — ✅ fixed 2026-11-07

**Where:** `app/Http/Controllers/Store/ConnectWebhookController.php:78-92`

**What this is:** Stripe tells us a sale completed by posting to this endpoint. To avoid handling
the same event twice, the controller inserts the Stripe event id into `connect_webhook_events`
(which has a unique column) and then processes the event.

**Problem — two parts.**

*Part A: the claim is written before the work is done.* The row is inserted with
`processed_at => now()`, which is a lie at that moment. If `MarkOrderPaidAction` then throws — the
database hiccups, a mail driver is unreachable, a bug in fulfilment — the request returns 500.
Stripe retries. The retry reaches `claim()`, finds the id already there, logs "replay ignored" and
answers 200. **The sale is never marked paid, the buyer never gets their download, and Stripe
stops retrying.** Money taken, nothing delivered.

*Part B: the catch is too wide.* `catch (QueryException) { return false; }` treats *every*
database error as "someone already claimed this". A dropped connection, a deadlock or a full disk
all become a silently dropped paid order with a 200 response.

Concrete case: a creator sells a download. The mail provider rate-limits for 30 seconds. The first
delivery 500s, the retry is swallowed as a replay, and the order sits `pending` forever while the
card has been charged.

**Fix — step by step:**
1. Claim the event id **without** `processed_at`:
   `ConnectWebhookEvent::create(['stripe_event_id' => $eventId, 'type' => $type]);`
2. Narrow the catch to a genuine duplicate:
   ```php
   } catch (UniqueConstraintViolationException) {
       return false;   // real replay
   }
   ```
   Let every other `QueryException` propagate. A 500 that Stripe will retry is the correct answer
   to "our database is broken".
3. Stamp `processed_at` only **after** the `match` block finishes without throwing.
4. Treat an unfinished claim as retryable: when `claim()` finds an existing row whose
   `processed_at` is null and older than ~2 minutes, let the delivery through instead of calling
   it a replay.
5. Add two tests: (a) a handler that throws leaves `processed_at` null and a second delivery
   *does* process the order; (b) a second delivery of a fully processed event changes nothing.
6. Grep `StripeWebhookEvent` and apply the same three changes if the subscription webhook uses
   the same pattern.

**If we skip it:** Buyers are charged and get nothing, with no retry and no alert. This is the
worst failure in the repo, because the customer notices before you do.

**Done:** `processed_at` is nullable (migration
`2027_01_23_100100_make_connect_webhook_events_processed_at_nullable`), the claim is written
without it, stamped only after the handler returns, and **deleted if the handler throws** — which
replaced the planned "stale claim" time check as the main mechanism, because deleting on failure
needs no timing guess and is directly testable. The 5-minute stale-claim take-over is still there,
but now only covers the narrow case of a worker killed between the insert and the release. The
blanket `catch (QueryException)` is now `catch (UniqueConstraintViolationException)`. Tests:
`ConnectWebhookTest` +4 (failed handler then successful retry; success stamps the claim; abandoned
claim taken over; fresh claim left alone).
**Step 6 resolved as "nothing to do":** `SyncSubscriptionOnStripeWebhook` already records the event
*after* doing the work and uses `insertOrIgnore`, so Cashier's path never had this bug.

---

### 3. Discount codes die from abandoned shopping carts — ✅ fixed 2026-11-07

**Where:** `app/Actions/Store/CreateCheckoutAction.php:201`,
`app/Actions/Store/ExpireStaleOrdersAction.php:56-70`, `app/Actions/Store/RefundOrderAction.php`

**What this is:** A coupon can be capped — "first 50 buyers". The cap is enforced by counting up
`coupons.redemptions` and comparing it to `max_redemptions`.

**Problem:** The count goes up when the buyer *starts* checkout, before any money moves, and
nothing ever puts it back. `ExpireStaleOrdersAction` cancels abandoned checkouts and frees the
booking slot but never touches the coupon. `RefundOrderAction` frees the slot but never touches
the coupon. I grepped the whole tree: `redemptions` is only ever incremented, never decremented.

The class comment says "An abandoned checkout therefore holds them until `ExpireStaleOrdersAction`
releases it". That is true of the slot and false of the coupon.

Concrete case: a creator posts `LAUNCH50`, capped at 50 uses. Fifty people click through to
Stripe and change their mind. The code is now dead, and the 51st person — the one who would have
paid — is told "That code is not valid for this."

**Fix — step by step:**
1. In `ExpireStaleOrdersAction::cancelAbandoned()`, after the transition to `Cancelled`, release
   the claim with `$order->coupon?->decrement('redemptions')`. Eager-load `coupon` next to
   `product` so this stays one query per sweep.
2. Do the same in `RefundOrderAction` inside its existing transaction, and in the webhook's
   `chargeRefunded()`.
3. Guard against going negative on a double release:
   `Coupon::whereKey($id)->where('redemptions', '>', 0)->decrement('redemptions')`.
4. Decide the refund rule and write it down. "A refund gives the code back" is the generous
   reading; "a refund keeps it spent" prevents abuse. Pick one, put it in the class comment, make
   the code match.
5. Add a test: start a checkout with a capped code, run `store:expire-orders`, assert
   `redemptions` is back to its old value and the code works again.

**If we skip it:** Capped discount codes silently stop working during the exact campaign they
were made for, and the creator blames the product.

**Done:** new `Coupon::releaseRedemption()` (one method, three callers, floored at zero in SQL),
called from `ExpireStaleOrdersAction` (which now eager-loads `coupon` next to `product`),
`RefundOrderAction`, and the webhook's `charge.refunded` path. Tests: `CreatorStoreTest` +2.
**Step 4, the refund rule, is decided:** a refund hands the code back. Written into the
`releaseRedemption()` docblock and `creator-store.md`. The generous reading is right for a product
whose sellers are individual creators, and the abuse it allows is bounded by the refund itself.

---

### 4. The publish webhook accepts unsigned deliveries — ✅ fixed 2026-11-07

**Where:** `app/Http/Controllers/UploadPostWebhookController.php:128-141`

**What this is:** Upload-Post posts the result of a publish here, signed with an HMAC over
`timestamp.body`. The controller resolves the secret for the target's organisation and checks the
signature.

**Problem:** When the organisation has no webhook secret saved, the check is **skipped and the
delivery is trusted** (`return true`), with only a log warning. In bring-your-own-account mode the
secret is something the agency copies out of the Upload-Post dashboard by hand, so "not set yet"
is the normal state for a new organisation, not an edge case.

Concrete case: an agency connects its own Upload-Post account and does not paste the webhook
secret. Anyone on the internet can now POST to `/webhooks/upload-post` with a guessed or observed
`job_id` and mark that post as published — with a fake live URL shown to the client — or as
failed. No session, no token, no signature.

Smaller problem on the same endpoint: the signature is only checked **after** the target lookup,
so an unsigned request still costs a database query.

**Fix — step by step:**
1. Change the default to refuse. With no secret, log, alert through `OpsAlerter`, and return
   `401`. A webhook you cannot verify is a webhook you must not act on.
2. Keep one escape hatch for local work: `config('publishing.webhook_allow_unsigned')`, default
   `false`, with a test asserting it is false in the production config.
3. Make the missing secret visible to the person who can fix it: a red banner on the
   organisation's publishing settings ("Webhook secret not set — publish results will not be
   recorded"), and refuse to switch an organisation into byo mode until it is set.
4. Move the timestamp and signature-shape checks **above** the `PostTarget` lookup, so a request
   without a well-formed signature header never touches the database.
5. Add tests: no secret → 401 and the target unchanged; bad signature → 401; good signature →
   the target syncs.

**If we skip it:** An outsider can falsify "your post went live" or "your post failed" on any
organisation that has not pasted a secret — which is every new one.

**Done:** no secret now means 401 plus a `webhooks.upload_post.secret_missing` ops alert. Signature
shape checks (fresh timestamp, `sha256=` prefix) moved ahead of the target lookup. New
`publishing.webhook_allow_unsigned`, default false, with a test that asserts the default *and*
exercises the hatch. New `tests/Concerns/SignsUploadPostWebhooks` — six existing tests across two
files were relying on the fail-open behaviour to pass, so they now sign their deliveries, which is
what they should have been doing all along. Tests: `UploadPostWebhookControllerTest` +4.
**Step 3, the settings banner, is not done** — see "Still open after phase one".

---

### 5. All client media lives on one server's local disk — ◐ code done 2026-11-07, deployment outstanding

**Where:** `app/Providers/AppServiceProvider.php:140`, `config/assets.php`,
`config/filesystems.php`, `composer.json`

**What this is:** `AssetStorage` is bound to `LocalAssetStorage`, and `assets.disk` defaults to
the private `local` disk under `storage/app/private`. Files are served through the app at
`/files/*` with signed URLs.

**Problem:** Three consequences, all of them scale problems.

- **You cannot run two web servers.** A video uploaded on node A does not exist on node B, so the
  post needing it fails to publish and the client sees a broken thumbnail. Every horizontal
  scale-out, blue/green deploy, or container restart on ephemeral storage loses files.
- **Every byte of every image and video goes through PHP.** A grid planner page with 30 thumbnails
  is 30 PHP requests that should have been CDN hits. With 500 MB video uploads allowed
  (`max_video_upload_size_kb`), one client reviewing a reel occupies a PHP worker for a long time.
- **The S3 switch does not currently work.** `config/assets.php` says switching is
  `ASSETS_DISK=s3` plus the AWS vars and the flysystem package — and `league/flysystem-aws-s3-v3`
  is **not in `composer.json`**. Setting `ASSETS_DISK=s3` today fails at runtime, not at boot.

The code is already right for this: `LocalAssetStorage` works unchanged against an `s3` disk, and
every row remembers its own disk. The GTM plan lists it as an outstanding deployment step. It is
here because it is the one open item that blocks more than one web node.

**Fix — step by step:**
1. `composer require league/flysystem-aws-s3-v3`.
2. Create the bucket: private, versioning on, lifecycle rule matching
   `ai.images.prune_after_days`.
3. Put a CDN in front of it and set `AWS_URL` to the CDN host, so signed URLs serve from the edge.
4. Set `ASSETS_DISK=s3` plus the `AWS_*` variables in production. Change nothing in PHP — that is
   what the `AssetStorage` contract bought you.
5. Write a one-off `assets:migrate-disk` command that walks every row with `disk = 'local'`,
   streams the file up with `readStream` (never `contents` — a 500 MB video must not land in
   memory), updates `disk`/`path`/`url`, and is safe to re-run.
6. Run it, then verify: 20 random assets load, a publish with video media succeeds, a paid store
   download still streams.
7. Add a startup check so that `ASSETS_DISK=s3` with blank credentials fails the health endpoint
   rather than the first upload.
8. Lower `assets.url_ttl_minutes` once the CDN is in place, and confirm
   `vendor_url_ttl_minutes` (24h) is still long enough for Upload-Post to fetch media.

**If we skip it:** You are limited to one web server forever, a restart can lose client media, and
image serving competes with page rendering for the same workers.

**Done (the code half):**
- Step 1 ✅ `league/flysystem-aws-s3-v3` is installed, so `ASSETS_DISK=s3` now works instead of
  failing at the first upload. It pulls in `aws/aws-sdk-php`, which is large; there is no lighter
  supported path to S3 from Laravel.
- Step 5 ✅ `php artisan storage:migrate-disk --from=local --to=s3`. Named `storage:` rather than
  the `assets:` in the plan, because assets are only one of **six** tables that store a file —
  the others are brand brain persona PDFs, invoice PDFs, AI image previews, brand brain reference
  copies and the white-label organisation logo. Missing one would silently leave those files on a
  disk the next web node does not have, so a test reads the schema and fails if any `*_disk`
  column is not covered.
  It streams (a 500 MB video never enters memory), never deletes the source (so it is re-runnable
  and reversible), rewrites the stored URL only for a public target disk, counts and logs each
  failed row with its table and id while continuing, and exits non-zero if anything failed. Run it
  with `--dry-run` first.
- Step 7 ✅ `/up` now fails while `assets.disk` names a disk that does not exist, or an `s3` disk
  with no bucket/key/secret. A half-finished switch is caught by the load balancer rather than by
  the first person to upload a file.
- Tests: `tests/Feature/Storage/StoredFilesDiskMigrationTest.php` (12).

**Still outstanding, and none of it is code:** steps 2, 3, 4, 6 and 8 — create the bucket, put a
CDN in front of it, set `ASSETS_DISK=s3` and the `AWS_*` variables, run the migration and verify a
sample, then revisit `assets.url_ttl_minutes`. **Until those are done this finding is not closed
and you still cannot run a second web server.** The code no longer blocks you; the infrastructure
does.

---

## Should fix

### 6. Two buyers racing for the last booking slot: one gets a 500 — ✅ fixed 2026-11-07

**Where:** `app/Actions/Store/CreateCheckoutAction.php:86-94`,
`app/Http/Controllers/Store/CheckoutController.php:65-80`

**What this is:** A booking product has a unique index on `(product_id, starts_at)`. That index
is what stops two people holding the same slot.

**Problem:** The index does its job by making the second `Booking::create()` throw a
`QueryException`. Nothing catches it. The controller only catches `CheckoutUnavailableException`
and `PaymentProviderException`. So the second buyer gets a 500 error page, on a public
unauthenticated page, mid-purchase — instead of the sentence the code already wrote for exactly
this case: "That time has just gone — pick another." The `isBookable()` check a few lines earlier
closes the common case but not the race, and the race is precisely what the unique index is for.

**Fix — step by step:**
1. Wrap the create:
   ```php
   try {
       Booking::create([...]);
   } catch (UniqueConstraintViolationException) {
       throw new CheckoutUnavailableException(__('That time has just gone — pick another.'));
   }
   ```
2. Throwing out of the `DB::transaction()` closure rolls back the order and the coupon claim,
   which is what you want — confirm that in the test.
3. Add a test that inserts a conflicting booking and asserts the friendly toast, not a 500.
4. Add a catch-all in the controller: any unexpected throwable becomes a reported error plus a
   generic "we could not start that checkout" toast. A public money page should never render
   Laravel's error page.

**If we skip it:** A popular creator's busy slot produces crash pages for paying customers.

---

### 7. AI credits can be overspent by running requests at the same time — ✅ fixed 2026-11-07

**Where:** `app/Services/AI/AiUsageGuard.php:36-70`,
`app/Services/AI/Images/ImageQuotaGuard.php:30-45`

**What this is:** Plans include a monthly number of AI text requests (`ai_text_credits`) and AI
images (`ai_image_credits`). Before each call the guard counts what has been used this month and
refuses once the count reaches the allowance.

**Problem:** This is "check, then do" with nothing holding the gap. The count comes from rows
written *after* the vendor call returns, so twenty requests starting in the same second all see
the same low count and all pass. The class comment accepts that "a single request can tip usage
slightly past the ceiling" — true of one request, not of many. Images are the expensive side, with
a real per-image cost at the vendor, and both the grid AI fill and the campaign planner fan work
out in parallel.

Concrete case: an organisation has 2 image credits left. A user clicks "Fill grid with AI", which
dispatches 9 jobs. All 9 read "used 48 of 50" before any has written a row. You pay for 9 images
and bill for 2.

**Fix — step by step:**
1. Reserve before you spend instead of counting after. Add a monthly counter (Redis, or a small
   `ai_usage_counters` row) and claim it atomically:
   ```php
   $used = Cache::increment("ai:{$org->id}:".now()->format('Y-m').":images", $n);
   if ($used > $quota) { Cache::decrement(/* … */); throw new AiBudgetExceededException(...); }
   ```
   `INCR` is atomic, so twenty callers get twenty different numbers.
2. Keep `ai_usage_logs` as the record of truth for billing and the usage page. Reconcile the
   counter from the logs daily and whenever it is missing, so a cache flush cannot grant a free
   month.
3. Give the refund path a home: when a vendor call fails and no image was produced, decrement.
4. Set the counter's TTL to end-of-month plus a few days.
5. Add a test that fires N concurrent claims against a quota of 1 and asserts exactly one wins.
6. Apply the same reservation to `ensureWithinPlanTextCredits`.

**If we skip it:** Vendor spend above what customers paid, concentrated on your heaviest users.

---

### 8. An audience broadcast queues one email per recipient inside the web request — ✅ fixed 2026-11-07

**Where:** `app/Actions/Audience/SendAudienceBroadcastAction.php:80-83`

**What this is:** "Email my list" loads every marketable contact and sends them a mailable. The
mailable is `ShouldQueue`, so each `send()` pushes a job rather than calling the mail provider.

**Problem:** The push still happens in the request, once per contact, in a `foreach`. A 5,000-contact
list means 5,000 sequential queue writes before the HTTP response — seconds of a held PHP worker,
and a hard failure at `max_execution_time` on a bigger list with no record of how far it got.
There is also no pacing: 5,000 jobs land at once and the workers throw them at the provider as
fast as they can, which is how you collect 429s and damage your sending reputation.

**Fix — step by step:**
1. Have the Action create the `AudienceBroadcast` row and dispatch **one** job
   (`SendAudienceBroadcastJob::dispatch($broadcast->id)`), then return.
2. In that job, select recipients with `chunkById(500)` so memory stays flat, and push mails per
   chunk.
3. Pace the send — `Bus::batch()` with a delay per chunk, or the existing rate limiter — matched
   to the Resend rate limit you actually have.
4. Record progress on the broadcast row (`sent_count`, `failed_count`, `finished_at`) so the UI
   can show "2,300 of 5,000 sent" and a retry knows where to resume.
5. Add a test with 1,200 contacts: the request returns fast, one job is dispatched, running it
   queues 1,200 mails.
6. Do the same review on `ImportAudienceContactsAction:59`, which sends a confirmation email per
   imported row inside the import request — a 2,000-row CSV has the same shape of problem.

**If we skip it:** The first customer with a real mailing list times out mid-send, and nobody can
tell who received what.

---

### 9. Every teammate can see every client — ✅ fixed 2026-11-07

**Where:** `app/Enums/Role.php`, `app/Models/Scopes/OrganizationScope.php`, `app/Policies/*`

**What this is:** Access is decided by one `users.role` value (Owner, Strategist, Designer,
Viewer) plus the organisation scope, which keeps organisation A out of organisation B.

**Problem:** Inside an organisation there is no client-level boundary. A Designer hired for one
client can open every other client's brand brain, captions, analytics, invoices, inbox and media.
`users.client_id` exists but is used for the client-portal reviewer, not for staff.

This is not only a feature gap. Freelancers and contractors are normal in this market, client
contracts routinely restrict who may see the work, and two competing brands are often on the same
roster. It was also the single most repeated complaint in the agency research: junior team members
end up with access to every client's account.

**Fix — step by step:**
1. Add `client_user` (`client_id`, `user_id`, optional `role`, unique pair) plus
   `Client::members()` / `User::clients()`.
2. Add one organisation flag, `restrict_staff_to_assigned_clients`, default **off**, so nothing
   changes for existing customers until they ask.
3. Add `User::accessibleClientIds(): ?array` — `null` means all clients (Owner, or flag off),
   otherwise the assigned ids.
4. Enforce it in one place, not thirty: extend `OrganizationScope` (or add a sibling
   `ClientAccessScope`) so every org-owned model carrying `client_id` also filters on that list.
   Models without `client_id` keep reaching the policies as they do now.
5. Add the same check to `ClientPolicy::view` and to the handful of Actions that take a
   `client_id` straight from the request.
6. Build the assignment UI on the existing Staff page: a client multi-select per teammate, and an
   "Assigned staff" list on the client page.
7. Write the test the way `CrossTenantRouteTest` is written: every route binding a client-owned
   model must 403 for a staff member not assigned to that client.
8. Update `.junie/modules/organizations-users.md` and `clients.md`.

**If we skip it:** You cannot sell to any agency that uses freelancers or holds competing brands,
and you will fail the first security questionnaire that asks about least privilege.

---

### 10. "Notify my team chat" notifies nothing — ✅ fixed 2026-11-07

**Where:** `app/Contracts/ChatNotifier.php`, `app/Providers/AppServiceProvider.php:172`

**What this is:** There is a `ChatNotifier` contract for posting short messages to an
organisation's chat channel. It is bound to `NullChatNotifier`.

**Problem:** The only implementation does nothing. Every caller that thinks it is telling the team
something is talking to a no-op. The contract's comment says a WhatsApp implementation lands later
and is "Deliberately not Slack."

The research disagrees with that call, strongly. The most repeated finding across agency sources
is that **approvals leak out of the tool into Slack and email, and the tool's own approval state
becomes a formality updated after the fact.** A product that cannot post into the channel where
the agency already works is a product whose workflow gets bypassed. Slack and Teams are where
agencies run their day; WhatsApp is where some talk to clients. Both are useful, but Slack first
is the one that keeps the workflow inside your product — which is also what keeps your approval
data true.

**Fix — step by step:**
1. Ship a Slack implementation behind the existing contract. Incoming webhooks are the small
   version: one URL per organisation, stored encrypted, posted to with `Http::post`. No OAuth app,
   no review queue.
2. Bind it by config the way `AdsSource` and `VirusScanner` already are:
   `config('notifications.chat') === 'slack' ? SlackChatNotifier::class : NullChatNotifier::class`.
3. Add the field to organisation notification settings with a "Send test message" button — the
   only way anyone trusts this kind of integration.
4. Send the five messages that matter, not everything: post waiting on client, client approved,
   client requested changes, publish failed, approval overdue. Each with a deep link.
5. Keep failures invisible to the caller: catch, log, carry on. The contract already promises it.
6. Add Microsoft Teams next — also a webhook URL, the same class shape.
7. Keep the WhatsApp plan for client-facing messages, which is a different job.
8. Test with a faked HTTP client: assert the payload, and that a 500 from Slack does not bubble up.

**If we skip it:** Agencies keep approving posts in Slack and updating your tool afterwards, which
makes your approval data wrong and your product optional.

---

### 11. `composer test` cannot run on a default PHP install — ✅ fixed 2026-11-07

> **Corrected 2026-11-07.** This finding originally said "the app needs more than 128 MB just to
> boot", and used that to argue for splitting `routes/web.php` and for a hosting-cost saving. I
> measured it before changing anything, and that was wrong. **One full boot of this app, with the
> route cache loaded and all 627 routes registered, peaks at 36 MB.** That is healthy. The claim
> about PHP-FPM baseline memory and hosting cost was unfounded, and step 3 (splitting the routes
> file) had no measured problem behind it — it is dropped rather than done. What is left is a real
> but much smaller problem, described below.

**Where:** `phpunit.xml`

**What this is:** PHPUnit builds a fresh application for every test and holds them for the life of
the process, so memory grows across a run. This suite has 2,309 tests.

**Problem:** Somewhere past the two-thousandth test the process crosses PHP's default 128 MB and
dies. Confirmed directly: `tests/Unit` alone (530 tests) is enough to exhaust 128 MB. The error
names the biggest single allocation in a boot rather than the cause:

```
Allowed memory size of 134217728 bytes exhausted ... in bootstrap/cache/routes-v7.php on line 7
```

That reads like a routing problem and is not one, which is what sent the first version of this
finding down the wrong path. `ops.md` documents `memory_limit = 512M` in production, so production
was never affected — this only ever broke `composer test` on a machine or CI runner using PHP's
default.

Separately, `composer test --parallel` failed outright because `brianium/paratest` was not
installed.

**Fix — step by step:**
1. ✅ Add `<ini name="memory_limit" value="512M"/>` to `phpunit.xml`. Verified: `tests/Unit` now
   passes at PHP's default limit with no `-d` flag.
2. ✅ `composer require --dev brianium/paratest`. `php artisan test --parallel` now runs the full
   suite: **2,309 passed**.
   *Measured caveat:* parallel took 316 s against 209 s serial on this machine, so the "roughly a
   quarter of the time" guess in the first version of this finding was also wrong — it is process
   startup cost against few cores. CI was therefore left running the suite serially: switching it
   would be a guess about runner width, and `composer ci:check` is the same command developers run
   locally. `--parallel` now simply works for anyone whose machine benefits.
3. ~~Split `routes/web.php`.~~ **Dropped.** No measured problem supports it, and re-ordering 520
   routes risks changing resolution for the catch-all patterns (`@{handle}`, `compare/{rival}`) for
   no gain.
4. ✅ Measured: 36 MB peak for a full console boot, 627 routes registered. The router is not the
   baseline, so there is nothing further to optimise.
5. ✅ CI smoke step added: boots the app at `memory_limit=128M` and fails if it cannot. That is the
   figure that matters — if a single boot ever needs more than PHP's default, something has gone
   badly wrong, and this catches it.

**If we skip it:** New contributors and CI runners on default settings cannot run the tests, and
the error message points them at the wrong file.

---

### 12. Load-test the engagement and comment sweeps before launch, not after — ◐ instrumented and spread 2026-11-07

**Where:** `routes/console.php` (`analytics:sync-engagement` every 30 min, `inbox:sync-comments`
hourly, `benchmarks:sync`, `ads:sync`), `config/horizon.php` (reports supervisor)

**What this is:** Several schedules dispatch one job per organisation onto the `reports` queue.
Each job then makes vendor API calls per social account and per recent post.

**Problem:** I did not find a bug here, so this is a measurement task rather than a defect — but it
is the thing most likely to break first as you grow. At 1,000 organisations the 30-minute
engagement sweep dispatches 1,000 jobs each making several external calls, against one supervisor
with a 620 s timeout. If the average job takes 20 s, one sweep needs about 5.5 worker-hours and
the next sweep starts before the last finished. Everything on `reports` and `default` queues
behind it, including user-facing exports.

**Fix — step by step:**
1. Measure one real job: log duration and vendor-call count in `SyncOrganizationEngagementJob`.
2. Compute the ceiling: `orgs × seconds ÷ (processes × 1800)`. Above 1 means the sweep cannot keep
   up.
3. Spread the load rather than firing everything on the half hour: schedule by `org_id % 30`
   minutes, or run a rolling sweep taking the N least-recently-synced organisations each minute.
4. Separate user-facing work from background sweeps — give exports and PDFs their own queue, so a
   backed-up sweep never makes a user wait.
5. Alert on `reports` queue wait time in Horizon/Pulse, and surface "last successful sync" per
   organisation in the super-admin console. The `SchedulerHeartbeat` work is already the right
   shape for this.

**If we skip it:** Analytics quietly goes stale under load — the feature clients look at most.

---

## Nice to have

### 13. 425 KB of test code is in the production build — ✅ fixed 2026-11-07

**Where:** `resources/js/pages/inbox/composer.test.tsx` → `public/build/assets/composer.test-*.js`

**What this is:** Inertia resolves pages by globbing `resources/js/pages/**/*.tsx`. One Vitest
file lives in that folder.

**Problem:** The glob matches it, so the build compiles it and everything it imports —
`@testing-library/react` and friends — into a 425 KB chunk: 11% of the 3.98 MB of JavaScript you
deploy. No user can ever load it (nothing renders a page called `inbox/composer.test`), so this is
dead weight in the artifact rather than a page-load cost. I checked, and the testing library did
not leak into the shared chunks, so the damage is contained.

**Fix — step by step:**
1. Move the file to `resources/js/pages/inbox/__tests__/composer.test.tsx` (outside the glob) or to
   a `resources/js/tests/` tree, and fix its imports.
2. Confirm `vp test run` still finds it; adjust `test.include` if not.
3. Rebuild and check: `ls public/build/assets | grep test` should print nothing.
4. Add the rule to `scripts/frontend-guards.mjs` — fail the build when any file matches
   `pages/**/*.test.tsx`. That is the step that stops it coming back; 13 test files live under
   `resources/js` today and only this one is misplaced.

---

### 14. The social connect callback is the one public route with no rate limit — ✅ fixed 2026-11-07

**Where:** `routes/web.php:264` (`social-accounts/callback`)

**What this is:** Upload-Post sends the user's browser back here after the hosted connect flow.
Access is a signed single-use state token, checked properly by `ConnectStateStore::consume`.

**Problem:** The authorisation is right; the route just sits outside the `throttle:public-token`
group every other public route is in. Each attempt costs a state-store read even when it fails,
so it is a cheap way to generate load.

**Fix:** Add `->middleware('throttle:public-token')` to the route — one line — and extend whichever
test asserts public routes are throttled.

---

### 15. A malformed webhook payload is logged in full — ✅ fixed 2026-11-07

**Where:** `app/Http/Controllers/UploadPostWebhookController.php:54`

**What this is:** When `job_id` or `platform` is missing, the controller logs `$request->all()`.

**Problem:** That writes whatever a public, unauthenticated endpoint was sent straight into your
logs. Upload-Post's payload carries no secrets today, but the endpoint is open to anyone, so the
content is attacker-chosen — including log-injection newlines and anything large.

**Fix:** Log only what you need:
`['job_id' => $jobId, 'platform' => $platform, 'keys' => array_keys($request->all())]`. You already
have `SecretScrubber` in `app/Services/Observability` if you want more of the body.

**Done** alongside finding 4, because it was two words inside the method that finding was
rewriting. Not scope creep worth avoiding; leaving a known log-injection in lines being retyped
would have been the odd choice.

---

### 16. One stale row in the module docs index — ✅ fixed 2026-11-07

**Where:** `.junie/modules/README.md`

**What this is:** `plans.md` has two rows in the modules table — one "In progress (Phase CG-2)",
one "Done (Phase CG-2)".

**Problem:** `tests/Unit/ModuleDocsTest.php` checks that every row names a real file and every file
has a row, so a duplicate passes. The stale row says a finished module is unfinished, which is
exactly the drift the docs workflow exists to prevent.

**Fix:** Delete the "In progress" row, and add a uniqueness assertion on the file column in
`ModuleDocsTest`.

---

---

## Found while doing phase two

Numbered after the original twenty so the earlier numbers keep meaning what they meant.

### 27. Purging an organisation leaves its logo file behind — ✅ fixed 2026-11-07

**Where:** `app/Services/StoredFileInventory.php:forClients()`, `app/Jobs/PurgeOrganizationJob.php`

**What this is:** `StoredFileInventory::forClients()` lists every stored file belonging to a set of
clients, so a purge can delete the files before the rows cascade away. `PurgeOrganizationJob` calls
it with the organisation's client ids.

**Problem:** The white-label logo is on `organizations.brand_logo_disk` / `brand_logo_path`, not on
anything belonging to a client, so `forClients()` cannot see it — correctly, since an org logo is
not a client's file. But the organisation purge has no other sweep, so the logo file stays in
storage after the organisation and all its rows are gone. I found this while enumerating disk
columns for the migration command: it is the one column `StoredFileInventory` does not name.

Concrete case: an agency closes its account and is purged. Its uploaded logo is still on the disk,
referenced by nothing, indefinitely. For a product that advertises white-label branding and will be
asked a data-retention question, that is a file nobody can account for.

**Fix — step by step:**
1. Add `StoredFileInventory::forOrganizations(array $orgIds)` returning the `brand_logo_disk` /
   `brand_logo_path` pairs, built with the same private `pairs()` helper.
2. In `PurgeOrganizationJob`, merge it into the file list already being deleted — one extra line
   next to the existing `forClients()` call.
3. Add a test asserting the logo file is gone after a purge. There is almost certainly an existing
   purge test to extend rather than a new file to write.
4. While there, decide whether `forClients()` should stay client-only (it should — a client purge
   must not delete the organisation's logo) and say so in its docblock, so the split is deliberate
   rather than an oversight waiting to be "tidied up".

**If we skip it:** Orphaned files accumulate, and the honest answer to "what happens to our data
when we leave" is "most of it is deleted".

### 28. `composer audit` already fails CI — ✅ fixed 2026-11-07

**Where:** `.github/workflows/tests.yml` (`Audit PHP dependencies`), `composer.lock`

**What this is:** CI runs `composer audit --locked --abandoned=report --ignore-severity=low
--ignore-severity=medium`, which is meant to fail the build on high or critical advisories.

**Problem:** It exits 1 today: **11 advisories across 6 packages**, all Symfony
(`symfony/http-foundation`, `symfony/serializer`, `symfony/yaml` and others), reported 2026-11-04.
I checked this against a clean tree with my own dependency changes stashed — the count and the exit
code are identical, so this is pre-existing and not something phase two introduced. It does mean
the `tests` workflow is red on that step right now, independently of any code change.

**Fix — step by step:**
1. `composer update symfony/* --with-all-dependencies` to pick up 7.4.23 / 8.1.11 or later, which
   is where the fixes landed.
2. Run the full suite. Symfony patch releases inside a Laravel major are usually uneventful, but
   `http-foundation` touches requests, responses and cookies, so this is not a change to make
   without the suite.
3. Re-run `composer audit` with the CI flags and confirm exit 0.
4. Do this as its own commit, separate from feature work, so a regression is easy to bisect.
5. Consider a scheduled weekly `composer audit` run, so the next advisory is noticed without
   waiting for someone to open a pull request.

**If we skip it:** Your dependency gate is already failing, so it will not tell you about the next
advisory — the one you would actually want to hear about.

### 29. Fourteen things that only break on MySQL — ✅ fixed 2026-11-07

**Where:** found by the new `tests (mysql)` CI job. Three groups; the third is a production bug.

**What this is:** the suite had only ever run on SQLite. Running it against MySQL 8.0.46 gives
**2,321 tests, 2,305 passed, 16 failed**. Two of those sixteen were test bugs introduced or
uncovered by this phase and are already fixed (below), leaving **14 pre-existing failures** that
had been invisible since the project started.

`ops.md` had already recorded three of these in its Known Gaps, marked "pre-existing; not changed
here". That note was correct and the problem simply outlived it, which is what a gate is for.

**Group A — a TIME column comes back with seconds (7 tests).** `post_targets.scheduled_local_time`
is a `TIME` column. MySQL returns `09:00:00`; SQLite returns the `09:00` that was written.
`BuildCalendarOccurrencesAction:52` passes the value straight to the frontend as
`occurrences.*.account_local.time`, so **the calendar and post editor display `09:00:00` in
production and `09:00` in every test.** Cosmetic, but on the screen agencies look at all day.
Failing: `CalendarControllerTest`, `PortalCalendarTest`, `PostControllerTest`, `RecyclePostTest`,
`ReschedulePostTest`, `RescheduleTargetTest` (×2).

**Group B — MySQL reorders JSON object keys (5 tests).** A saved report's filters are stored as
JSON and read back with the keys in a different order. The tests use `assertSame` on the whole
array, which is order-sensitive. No production impact that I can see — nothing appears to depend on
key order — so this is a test-portability problem, but it has to be settled before the gate can
block. Failing: `OrganizationReportTest` (×2), `CampaignBriefTest`, `PostMediaFormatTest`,
`SuperAdmin\ReportControllerTest`.

**Group C — deleting an organisation with invoices fails (2 tests). This one is real.**
`2026_10_18_110000_add_soft_deletes_and_protect_invoices` set `invoices.client_id` to
`noActionOnDelete()`, with the comment: "NO ACTION (checked at the end of the statement) rather
than RESTRICT (checked immediately), so purging a whole organization — which cascades to its
clients *and* its invoices in the same statement — still works."

**That premise is false on InnoDB.** MySQL treats `NO ACTION` as a synonym for `RESTRICT` and
checks it immediately; it does not implement deferred foreign keys. SQLite does, which is why the
test suite has always passed.

Concrete case: a super admin deletes an agency that has ever issued an invoice.
`PurgeOrganizationJob` throws `SQLSTATE[23000] 1451 Cannot delete or update a parent row`, burns
all three retries, and the organisation is left soft-deleted forever — rows not removed, client
media not deleted. For a product that will be asked "what happens to our data when we leave", the
honest answer today is "on MySQL, nothing". Failing:
`SuperAdmin\OrganizationManagementTest`, `Clients\ClientArchiveTest`.

**Fix — step by step:**
1. **Group C first**, because it is the only one that loses data. In `PurgeOrganizationJob`, delete
   the invoices inside the existing transaction before `forceDelete()`:
   ```php
   DB::transaction(function () use ($organization, $clientIds) {
       // InnoDB checks NO ACTION immediately — it is a synonym for RESTRICT there, whatever the
       // migration comment says — so invoices must go before the clients they reference.
       DB::table('invoices')->whereIn('client_id', $clientIds)->delete();
       $organization->forceDelete();
   });
   ```
   `invoice_items` cascades from `invoices` and `brand_deals.invoice_id` is `nullOnDelete`, so
   nothing else needs ordering. Deleting them is the documented intent — the migration says an org
   purge should remove invoices; only its mechanism was wrong.
2. Correct that migration's comment in place. It is load-bearing documentation and it is currently
   teaching the next person something untrue about InnoDB.
3. Check whether any other table relies on the same deferred-FK assumption:
   `grep -rn "noActionOnDelete" database/migrations/`.
4. **Group A**: normalise the time once, where it is read, not at each of the several call sites.
   A cast or accessor on `PostTarget::scheduled_local_time` returning `H:i` makes every reader
   agree — `BuildCalendarOccurrencesAction`, `GetPortalCalendarPostsAction`, the grid actions and
   `ShiftCampaignAction` (which already parses with Carbon and tolerates both). Then the 7 tests
   assert the same thing on both engines, and the UI stops showing seconds.
5. **Group B**: compare the stored filters order-insensitively —
   `assertEqualsCanonicalizing`, or assert per key. Do not sort the JSON on write to make the test
   pass; the storage format is not the problem.
6. Then delete `continue-on-error: true` from the `mysql` job in
   `.github/workflows/tests.yml`, so the gate starts holding the line.

**Already fixed as part of this phase (2 of the 16):**
- `Schema::getTables()` / `getTableListing()` read `information_schema` on MySQL and are
  **server-wide**: on the development machine they returned 388 tables across eight databases,
  including other projects, `sakila` and `world`. Both calls are now scoped to the connection's own
  database. This broke the new `storage:migrate-disk` drift guard, and — more importantly — it
  broke `Security\SecretsAndPrivateMediaTest::test_no_database_column_holds_a_raw_review_token`,
  which errored out on a table from another database and so **had never actually verified on MySQL
  that review tokens are not stored in plain text**. It does now.

**If we skip it:** The gate stays advisory, which means it stays ignored, and the next
engine-specific bug ships exactly the way the `ESCAPE '\'` one did. Meanwhile an organisation that
asks to be deleted is not deleted.

**Done — all 16, and the gate is blocking:**
1. ✅ Group C. `PurgeOrganizationJob` deletes the organisation's invoices inside its existing
   transaction before `forceDelete()`. The existing test already asserted invoices should be gone
   after an org purge, so this was the documented intent with a broken mechanism — nothing about
   the policy changed, and `PurgeClientAction` still refuses to purge a single client that has
   invoices. 25 tests green on MySQL.
2. ✅ The migration comment is corrected in place, since it was teaching something untrue about
   InnoDB. `grep -rn "noActionOnDelete"` confirms this was the only place relying on it.
3. ✅ Group A, fixed once at the root: a `PostTarget::scheduledLocalTime` read accessor returning
   `H:i`. Checked first that nothing compares the column in SQL — every reader either parses it
   with Carbon, presents it, or copies it forward — so one accessor fixes all seven tests *and*
   the production display. It also makes `SendPostPushRemindersAction`'s two branches agree, since
   its fallback already used `format('H:i')`. 40 tests green on MySQL.
4. ✅ Group B: `assertSame` → `assertEquals` on the four stored-JSON assertions. PHP's `==` on
   arrays ignores object key order while still comparing nested *lists* in order, which is exactly
   the contract — `metrics` order is meaningful, key order is not. I deliberately did **not** use
   `assertEqualsCanonicalizing` (it would sort the lists too) and did not sort JSON on write.
   45 tests green on MySQL.
   I also checked whether key order could bite in production: `CampaignBrief::hashContent()` is
   order-sensitive, but it is only ever computed from a freshly built array and only ever compared
   against the stored string, so the brief approval check is sound. No production change needed.
5. ✅ `continue-on-error` removed from the `mysql` job.

---

### 30. `npm run check` has been failing CI

**Where:** `scripts/frontend-guards.mjs`, `resources/js/components/post-editor/*`

**What this is:** `composer ci:check` runs `npm run check`, which is `vp check` plus the project's
own frontend guards. One guard caps editor files at 300 lines.

**Problem:** Three files are over it and have been for some time:
`channel-schedule.tsx` (309), `editor/settings-section.tsx` (338), `media-picker.tsx` (385). The
guard exits non-zero, so `npm run check` fails, so `composer ci:check` fails. I verified this
against a clean tree with every change of mine stashed — identical — so it is pre-existing.

It also corrects something I said earlier in this document: after phase three I wrote that every
CI gate passes. I had run `composer audit`, Pint, PHPStan, `tsc` and the full suite, and had not
run the frontend guards. That claim was wrong.

**Fix — step by step:**
1. Split each of the three by **job**, not by line count. `media-picker.tsx` at 385 lines almost
   certainly contains a browse-library concern and an upload concern that can stand apart; the
   guard is a smell detector, and satisfying it by extracting helpers that exist only to shorten a
   file would leave the smell and lose the signal.
2. Run `npm run check` and `npx vp test run` after each split, one file per commit, so a
   regression in the editor is easy to bisect.
3. If a file genuinely has one job and is simply long, raise the cap deliberately in
   `frontend-guards.mjs` with a comment saying which file forced it and why — a threshold edited
   down to pass is the thing to avoid, but a threshold that was always wrong is worth correcting
   openly rather than carrying three permanent failures.
4. Do not do this inside a feature phase. It is a refactor of the most-used screen in the product
   and wants its own change and its own review.

**If we skip it:** The command developers and CI both run stays red, which trains everyone to
ignore its output — and the next real failure it reports goes unnoticed.

---

## Verdict

**Healthy, with four things to fix before anyone pays you.** This is unusually careful code for its
size. The publishing pipeline's at-most-once handling — dispatch ids, attempt ids, row locks, and
an honest "we cannot tell whether the platform got it" state — is better than most production
systems. Tenancy has a real safety net plus a cross-tenant route test. Tokens are hashed, raw SQL
is parameterised everywhere I looked, rate limits are in place, and 2,291 tests pass.

Fix in this order: **1** (search is broken on the production database), **2** (a paid order can be
lost forever), **4** (an open webhook), **3** (coupons), then **5** (S3, which is what lets you run
more than one server). *Findings 1–4 were fixed on 2026-11-07; **5** is now the top of the list.*

**Lean:** about −425 KB of build output and −3 duplicated query blocks are available from findings
13 and 1. Nothing here is over-engineered enough to delete. The one-implementation contracts
(`AssetStorage`, `Entitlements`, `ChatNotifier`) each have a named second implementation coming and
are the seams the tests mock, so they earn their keep.

**Not checked:** I did not read the 135 React pages or 240 components, the campaign and grid
planner internals, the MCP server, or the DeepSeek and Upload-Post adapters line by line (1,254 and
1,038 lines), and I did not review the invoice/tax maths. I did not run `tsc`, Vitest or Playwright.
No load test was run, so finding 12 is a task, not a result.

*Updated 2026-11-07:* Pint and PHPStan have since been run (PHPStan has 87 pre-existing errors,
none in files this work touched), and the MySQL caveat is closed — there was a MySQL 8.0.46 on the
development machine after all, finding 1 was reproduced on it directly (error 1064), and the suite
now runs against MySQL both locally and in CI.

---
---

# Part 2 — Market readiness

## How the product compares today

The recurring complaints in the 2026 agency-tooling coverage line up in a clear order, and Graphy
already answers most of them. Worth saying plainly, because it changes what to build next.

| What agencies complain about | Where Graphy stands |
|---|---|
| Login walls delay client approval | **Already solved.** Token review links, no account needed. |
| Approvals have no audit trail | **Already solved.** `post_activity_logs`, approval SLA, reminders. |
| One global approval workflow for every client | **Ahead of the market.** Per-client `any` / `all` / `sequential` approver chains. |
| Approval features locked to a $100+ tier | **Positioning advantage.** They are core here. |
| White-label branding locked to a $200 tier | **Already solved.** Branding resolver, verified custom domains. |
| Reporting behind a "Power Reports" upsell | **Already solved.** Saved reports, scheduled email, CSV/PDF. |
| Per-seat pricing creeping toward ~$900/mo | **Positioning advantage** if you keep seats generous. |
| Instagram/TikTok DMs missing from the inbox | **Gap** — comments only; DMs deferred. |
| Junior staff can see every client | **Gap** — finding 9. |
| Approvals happen in Slack, not the tool | **Gap** — finding 10. |
| Approval emails land in spam | **Gap** — item 17. |
| Onboarding a new client is the bottleneck | **Partial** — campaign templates exist, client onboarding templates do not. |

Honest summary: the *workflow* is competitive or better. The gaps are in **reach** (DMs,
listening), **plumbing** (API, deliverability), **trust** (SSO, SOC 2, export) and **proof**
(nobody knows this exists). Items below are ordered by what I would do first.

---

### 17. Make approval emails arrive — ◐ code done 2026-11-07, DNS outstanding

**Why:** The research named email landing in spam as *the* biggest source of approval delay in
2026. Every clever thing you built — token links, reminder nudges, SLA tracking — depends on one
email arriving. You also cannot send a single audience broadcast until this is right
(`MAIL_MAILER=resend` is already an outstanding launch step), and one bad list can poison the
domain your approval mail uses.

**Steps:**
1. Use two sending domains, not one: `mail.<yourdomain>` for transactional (approvals, invites,
   receipts) and `news.<yourdomain>` for marketing and audience broadcasts. A complaint against
   the second must never hurt the first.
2. Publish SPF, DKIM and DMARC on both. Start `p=none` with an `rua=` report address, read the
   reports for two weeks, then move to `p=quarantine`.
3. Add a Resend webhook for bounces, complaints and opens. Store delivery state against the row
   that caused the send (the review request, the invitation, the invoice) and show it in the UI:
   "Approval request — delivered 10:02", or "bounced".
4. Add the fallback the research points at: when an approval request has not been delivered or
   opened within N hours, escalate — nudge in Slack (finding 10), raise it in the agency's "needs
   attention" list, and offer "copy the review link" so a human can paste it anywhere.
5. Add an "Email setup" health page in settings that checks SPF/DKIM/DMARC live and shows
   red/green. Agencies will not do this themselves.
6. Hard-suppress any address that bounces or complains, across both domains.

---

### 18. A public REST API, outgoing webhooks and a Zapier app

**Why:** Three reasons. It is a procurement checkbox — API access is on every agency evaluation
list. It is how customers glue you to tools you will never build: their CRM, time tracker, data
warehouse. And a Zapier/Make listing is a distribution channel with a long lead time, like the
comparison pages already in the GTM plan. The MCP server is genuinely differentiated, but an AI
assistant protocol is not a substitute for `POST /v1/posts` when the buyer's ops person asks.

**Steps:**
1. Reuse what exists. `McpToken` already gives org-scoped bearer tokens with a capability scope
   set — extend that model rather than inventing a second auth system.
2. Add `routes/api.php` with a thin `/v1` surface over existing Actions; nothing new in the domain
   layer. Start with clients, social accounts, posts (list/create/schedule), asset upload,
   analytics summary, invoices. Read-only first, writes second.
3. Version in the path, paginate with cursors, rate limit per token with the existing
   `RateLimits` helper.
4. Add outgoing webhooks: a `webhook_endpoints` table per organisation, with events for
   `post.published`, `post.failed`, `post.approved`, `post.changes_requested`, `invoice.paid`,
   `order.paid`. Sign deliveries exactly the way you *verify* Upload-Post's (HMAC over
   `timestamp.body`) — you already have the code, and finding 4 is the lesson: never fail open.
5. Deliver from a queue with retries and a dead-letter view the customer can see and replay.
6. Publish an OpenAPI spec, generate the docs page from it, and add "API access" to the plan
   matrix in `config/plans.php`.
7. Build the Zapier app on the same API (triggers: post published, approval requested; actions:
   create draft post, add audience contact). Submit early — review takes weeks.

---

### 19. Client onboarding templates

**Why:** The research flagged onboarding as the scaling bottleneck and specifically recommended
reusable templates for workspaces, roles and approval stages. Graphy has campaign templates but
nothing for the step before: standing up a new client. For an agency signing two clients a month,
this is the difference between your tool being where work starts and where work gets copied to.

**Steps:**
1. Add `client_templates` holding a snapshot: brand brain fields, approval policy and approver
   roles, posting cadence, default platforms and channel formats, hashtag sets, linked campaign
   templates, report schedule, invoice/retainer defaults.
2. Add "Save as template" on an existing client, capturing that snapshot.
3. Add "New client from template" to the create flow, with a checklist of what will be copied.
4. Ship 3 templates in the box, because an empty template list teaches nobody: *Local service
   business*, *E-commerce brand*, *B2B thought leadership*.
5. Add a per-client onboarding checklist (connect accounts, invite approvers, fill brand brain,
   set cadence, first post) and surface "onboarding incomplete" through the existing client health
   signals — the machinery is already there.
6. Make the client self-connect invite part of the template, so step one of onboarding is one
   email.

---

### 20. Direct messages in the inbox

**Why:** The inbox reads comments only; `.junie/modules/inbox.md` records DMs as deferred. The
research found this is among the most cited limits in reviews of competing tools, which means
buyers ask in demos and score it. Today the honest answer is "comments only", and that costs you
the deal against anyone who has DMs even partially.

**Steps:**
1. Decide the vendor path first. DMs need the Meta app with `instagram_manage_messages` — the same
   Phase 5 work the GTM plan defers. Check whether Upload-Post exposes DMs before building
   anything; if it does, this is a `CommentSource` sibling and cheap.
2. Add a `MessageSource` contract mirroring `CommentSource`, so the inbox never learns where
   messages came from.
3. Extend `InboxConversation` with a kind (`comment` / `dm`) rather than building a second inbox.
   Triage, assignment, saved replies, SLA and AI draft replies then work unchanged — that is the
   payoff of how the inbox was built.
4. Respect Meta's 24-hour messaging window and show it in the UI, so staff do not write a reply
   that cannot send.
5. Be explicit on the marketing pages about which networks have DMs and which do not. The research
   shows buyers are tired of discovering the asterisks after paying.

---

### 21. Enterprise trust: SSO, audit export, and a security page

**Why:** SSO and audit logs are repeatedly described as the contents of the enterprise tier, and
SOC 2 comes up in every procurement conversation above a certain deal size. You will not win a
50-seat agency without at least a straight answer on each.

**Steps:**
1. Add SAML/OIDC single sign-on. Fortify is already in place; add a per-organisation provider
   (`sso_connections`) with an "SSO only" enforcement option. Price it on the top plan.
2. Add an organisation-level "all members must have 2FA" switch. Fortify's 2FA is already enabled;
   the enforcement is what is missing.
3. Add audit log export. `post_activity_logs` and the staff activity log exist; add an
   organisation-wide, filterable, CSV-exportable audit view (who did what, when, from where). A
   day of work that answers half a questionnaire.
4. Write the security page: subprocessors, where data lives, retention, encryption, how to report
   a vulnerability, DPA on request. `.junie/mcp-security-review.md` shows you already think this
   way — publish it.
5. Ship GDPR mechanics as product features, not support tickets: per-organisation data export (you
   have `PurgeOrganizationJob` for the delete side) and a documented retention policy.
6. Start the SOC 2 Type 1 clock when revenue justifies it, and say "in progress, Type 1 expected
   <date>" rather than nothing.

---

### 22. Charge for value without punishing seats

**Why:** The loudest pricing complaint in the research was cost creep — "$29 on the homepage, $900
once you add the seats and profiles an agency actually needs" — plus core features locked behind
double-price tiers. That is a positioning gift. Graphy's approvals, white-label and reporting are
core, which is already the right answer; the job is to say so where buyers compare.

**Steps:**
1. Keep seats unlimited or very generous, and meter what actually costs you money: social
   accounts/brands, AI credits, storage. Honest and defensible.
2. Never gate approvals, white-label or client portal access by tier. They are the reason to pick
   you.
3. Put "no per-seat pricing" and "approvals on every plan" on the pricing page and on every
   comparison page. `config/competitors.php` already carries dated figures, so quote their
   per-seat cost next to yours.
4. Add an in-app calculator: "5 clients, 15 channels, 8 people" → your price next to theirs.
5. Fix the metering first. Finding 7 means AI credits currently leak; do not build pricing on a
   counter that can be raced.
6. Close the open tax question (GTM-0.3) before any of this ships. Nothing takes money until an
   accountant has answered it in writing.

---

### 23. Retainer profitability per client

**Why:** The money question every agency owner asks is "which clients are worth keeping". You have
invoices, retainer drafts, campaign billing and client health — everything except the number that
joins them. Most competitors stop at scheduling, so this is a genuine wedge, and it is mostly
joins over data you already store.

**Steps:**
1. Capture effort without a timesheet, because nobody fills those in. Infer from what the app
   already sees: posts produced, revisions requested, approval rounds, AI calls, assets uploaded,
   inbox replies. Add one optional manual "hours this month" per client for agencies that do track
   time.
2. Add a per-client cost model: retainer value from invoices, minus a configurable blended rate
   times estimated effort, minus AI and storage usage.
3. Show one panel per client — retainer, effort, margin, trend — then one roster-wide table sorted
   by margin.
4. Flag the pattern that destroys agency margins: a client whose revision rounds climb while the
   retainer stays flat. `ApprovalMetrics` already computes approval round counts.
5. Put it in the monthly client report as an internal-only section, never client-facing.
6. Call it "Client profitability". That is the phrase agencies search for.

---

### 24. Reach: Bluesky, Reddit, and an honest listening story

**Why:** Nine networks is a competitive set, but Bluesky is now standard on competitor grids and is
cheap to add. Real social listening is explicitly deferred in your plan and I agree with that call
— but the *claim* needs managing, because buyers compare feature grids.

**Steps:**
1. Add Bluesky. Public API, no app review, fits `PlatformCapabilities` plus the rollout gate you
   already have. Likely the cheapest platform you will ever add.
2. Evaluate Reddit and Mastodon the same way. Reddit matters for B2B clients and has a real API.
3. For listening, ship the narrow honest version rather than nothing: keyword alerts over the
   comments and mentions you already ingest for connected accounts. Call it "mention alerts", not
   "social listening".
4. On the comparison pages, say plainly that you do not do web-wide listening, and name who does.
   Your `/compare/{rival}` pages are already built on dated, checked figures; that credibility is
   worth more than a checkmark.
5. Revisit real listening only when a lost deal names it as the reason, and record that in the
   lost-deal notes so the decision is data, not opinion.

---

### 25. Proof: the first ten customers are a product feature

**Why:** The GTM plan covers comparison pages, an affiliate programme and a Play listing — all the
right moves. What no plan can skip is evidence. An agency buying a tool to run their clients'
brands will ask who else uses it, and the answer cannot be nobody.

**Steps:**
1. Pick ten design-partner agencies. Free for a year in exchange for a named case study and the
   right to email them with questions.
2. Instrument the three numbers that show whether the product works, per organisation: time from
   draft to client approval, approval rounds per post, publish success rate. You already compute
   all three (`PostApprovalSlaService`, `ApprovalMetrics`, `GetPublishReliabilityStatsAction`).
3. Turn those into the marketing claim once you have real data: "approvals in X hours instead of
   Y". Defensible, unlike "powerful AI".
4. Add an in-app NPS prompt at day 30 and route anything below 7 straight to a human.
5. Publish a status page and uptime history. The research noted a competitor's two-day API outage;
   being visibly reliable is a differentiator you can earn cheaply.
6. Build the migration path in: an "import from Hootsuite / Later / Planable" CSV mapper on top of
   the existing `BulkPostImportController`. Switching cost is the main reason agencies stay put.

---

### 26. Localisation, when a market asks for it

**Why:** There is no `lang/` directory. Every string is English, wrapped in `__()` so the machinery
is ready but nothing is translated. Correctly *not* a launch blocker — but it is a hard ceiling on
EU and LatAm agencies, and the cost rises with every new string.

**Steps:**
1. Add `lang/en/` now and extract the strings, while there are hundreds and not thousands. The
   `__()` calls are already there, which is the expensive part.
2. Add a per-user locale and a per-organisation default. The timezone handling already follows
   this shape.
3. Add Spanish and German only when a real prospect asks, and translate the client-facing surface
   first — portal, review page, emails, invoices. That is what the agency's client sees; the
   agency's own staff can live in English.
4. Format dates, times and currency from the locale rather than hardcoding. Check the invoice and
   report PDFs specifically.
5. Add a CI check that fails on a hardcoded user-facing string in a Blade or page component, so
   this does not regress.

---

## Carried over

Updated after phase two.

1. ~~**There is still no MySQL CI job.**~~ ✅ **Closed 2026-11-07.** `.github/workflows/tests.yml`
   gained a `tests (mysql)` job: a MySQL 8 service, `DB_CONNECTION=mysql`, `graphy_test` as the
   database (which `Tests\TestCase::isTestDatabase()` already permits), running the full suite.
   PHPUnit's `<env>` entries do not overwrite an already-set variable, so the job's environment
   wins over the SQLite defaults in `phpunit.xml` with no second config file.
   This was verified against a real MySQL 8.0.46 before being committed, not just written and
   hoped for — and the verification immediately paid for itself: see finding 29, whose 14 failures
   are now fixed, so the job is **blocking**.
2. **No "webhook secret not set" banner in organisation settings** (finding 4, step 3). Still open.
   The security hole is closed and on-call gets an alert, but the person who can actually fix it —
   the agency owner looking at their own publishing settings — is still not told. Until the banner
   exists, a byo organisation that never pastes its secret records no publish results and the only
   signal is an ops email to us. This is now the oldest unclosed step in this document.
3. ~~**`composer test` cannot run on a default PHP install.**~~ ✅ **Closed 2026-11-07** — see
   finding 11, which was also corrected.
4. **The S3 deployment steps** (finding 5, steps 2/3/4/6/8). New this phase, and the one that still
   blocks running more than one web server. Code is ready; bucket, CDN and env are not.

## Suggested order

**Before you take a single payment:** ~~findings 1 (search), 2 (lost orders), 4 (open webhook),
3 (coupons)~~ ✅ **all done 2026-11-07**, plus GTM-0.3 — the tax question only an accountant can
answer, and still the blocker.

**Before you run more than one server:** ~~findings 5 (S3), 11 (boot memory)~~ — **code done
2026-11-07**, but finding 5's bucket/CDN/env steps are infrastructure and still outstanding, so
this line is not yet crossed off. Add finding 28 (`composer audit` is already failing CI) here,
because a red dependency gate should not be carried into a launch.

**First month after launch:** ~~finding 10 (Slack), finding 9 (per-client permissions), finding 7
(AI credit metering)~~ ✅ **done 2026-11-07**; item 17 (deliverability) is code-done, with its DNS
records and the transactional/marketing domain split still to do by hand.

**First quarter:** item 18 (API + webhooks + Zapier), item 19 (onboarding templates), item 23
(profitability), finding 8 (broadcast scale), finding 12 (sweep load test).

**When the market asks:** item 20 (DMs), 21 (SSO / SOC 2), 24 (Bluesky, mention alerts),
26 (localisation).

---

## Sources for Part 2

Agency pain points and competitor positioning were drawn from the 2026 coverage below. Most of it
is vendor-published and therefore marketing-influenced: the *complaints* recur across independent
sources, but the *rankings and prices* should be re-verified against each vendor before you quote
them on a comparison page.

- [Client Approval Workflow for Social Media Agencies (2026 Playbook) — PlanMyGrid](https://www.planmygrid.com/blog/client-approval-workflow-for-social-media-agencies)
- [Agency social media workflow: the six-stage system (2026) — Sked Social](https://skedsocial.com/blog/agency-social-media-workflow)
- [Social media agency workflow: manage 10+ clients — Planable](https://planable.io/blog/social-media-workflow/)
- [11 best social media management tools for agencies in 2026 — Planable](https://planable.io/blog/social-media-management-tools-for-agencies/)
- [Hootsuite vs Sprout Social 2026: pricing, analytics, verdict — Planable](https://planable.io/blog/hootsuite-vs-sprout-social/)
- [Hootsuite vs Buffer vs Sprout vs Later: Best Choice in 2026 — Social9](https://social9.com/blog/hootsuite-buffer-sprout-social-later-comparison-2026)
- [8 Best Social Media Content Planners for Agencies in 2026 — Cloud Campaign](https://www.cloudcampaign.com/smm-tips/best-social-media-content-planners-for-agencies-in-2026)
- [7 Best Social Media Inbox Managers for Agencies in 2026 — Cloud Campaign](https://www.cloudcampaign.com/smm-tips/best-social-media-inbox-managers-for-agencies-in-2026-white-label-tools-that-actually-deliver)
- [8 best social media APIs for agency workflows in 2026 — Planable](https://planable.io/blog/social-media-apis/)
- [7 Best Social Media Management Tools for Marketing Agencies (2026) — Leadsie](https://www.leadsie.com/blog/best-social-media-management-tools-agencies)
- [Best Social Media Management Tools for Agencies in 2026 (And Where They Still Fall Short) — Inexra](https://www.inexra.com/resources/best-social-media-management-tools-for-agencies-2026)
- [Agorapulse reviews — Capterra](https://capterra.com/p/123971/Agorapulse/reviews/?page=9)
- [Social media inbox for agencies and multi-location brands — Sked Social](https://skedsocial.com/features/inbox)
- [The 10 best social media management tools in 2026 — Zapier](https://zapier.com/blog/best-social-media-management-tools/)

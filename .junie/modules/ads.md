# Paid Ads Reporting (read-only)

## Purpose

Phase GTM-5 of `.junie/plans/go-to-market.md`. Agencies report on ad spend every month, and
reporting is roughly a tenth of the work of managing ads and most of the value. So Alima reports:
spend, results and cost per result per ad account per day, beside the organic figures, on the
client's analytics page and in their monthly report.

**Paid ads _management_ is an explicit non-goal** (plan §1.1), and this module is built so a later
step cannot quietly become one.

## READ-ONLY BY CONSTRUCTION

`App\Contracts\AdsSource` has exactly two methods: `key()` and `accountMetrics()`. There is no
`createCampaign`, no `updateBudget`, no `pause`, and **no write method is to be added to it.**

That is a design rule, not a preference:

- the blast radius of a bug in code that can spend somebody else's money is not comparable to one
  in code that reads;
- there is deliberately nowhere in this module for a budget change to be plumbed through, so a
  feature request for one is a new contract, a new decision and a conversation about liability;
- `AdsReportingTest::test_the_contract_exposes_no_way_to_write_to_an_ad_platform` asserts the
  method list by reflection, so a write method fails the suite before it reaches a reviewer.

## Models / Tables

`2026_12_26_100100_create_ad_account_tables.php`, following `social_accounts` /
`social_account_metrics` exactly:

- `AdAccount` (`ad_accounts`): `org_id`, `client_id`, `platform`, `external_account_id` (digits, the
  `act_` prefix stripped), `name`, `currency`, `connection_status` (`ConnectionStatus`),
  `metrics_synced_at`, `metrics_error`. Unique `(org_id, platform, external_account_id)`.
- `AdAccountMetric` (`ad_account_metrics`): `org_id`, `ad_account_id`, `captured_on`,
  `spend_minor`, `impressions`, `clicks`, `conversions`, `fetched_at`. Unique
  `(ad_account_id, captured_on)`.

**CPC and CPA are not columns.** They are spend ÷ clicks and spend ÷ conversions, derived where
they are shown, so a stored ratio can never disagree with the figures above it. Both are null when
the denominator is 0 or unknown — a cost per click with no clicks is unanswerable, not infinite.

Money is minor units in the ad account's **own** currency. Nothing is ever converted; a report
shows each account in the currency it spent.

## Actions / Services / Contracts

- `App\Contracts\AdsSource` — bound in `AppServiceProvider` from `config('ads.provider')`.
- `App\Services\Analytics\Ads\NullAdsSource` — the **default**. No provider configured means no
  figures, so a fresh install shows honest gaps rather than wrong numbers.
- `App\Services\Analytics\Ads\MetaAdsSource` — the Marketing API's insights endpoint
  (`/act_{id}/insights?...&time_increment=1`). A "conversion" is a purchase or a lead, not a page
  like: counting engagement as a conversion would flatter the numbers.
- `App\Actions\Analytics\SyncAdAccountMetricsAction` — one account, one row per day, upserted.
  Returns `stored` / `empty` / `failed`.
- `App\Actions\Analytics\SyncOrganizationAdsAction` + `SyncOrganizationAdsJob` + `ads:sync`
  (nightly 04:30, in `SchedulerHeartbeat::COMMANDS`).
- `App\Actions\Analytics\GetClientAdsAction` — the panel and report payload, plus the organic
  figures for the side-by-side.

## Routes / Controllers

Agency shell, inside the `workspace:agency` analytics group:

- `POST /clients/{client}/ad-accounts` → `Analytics\AdAccountController@store`
- `POST /clients/{client}/ad-accounts/{adAccount}/sync` → `@sync` (read one now)
- `DELETE /clients/{client}/ad-accounts/{adAccount}` → `@destroy`

Every one of them records _what to read_, reads it, or stops reading it. Writes need the
client-update ability.

Frontend: `components/analytics/ads-panel.tsx` on `pages/analytics/client.tsx`, the "Paid and
organic" block in `components/client-reports/client-report-document.tsx` and in
`resources/views/pdf/client-report.blade.php`.

## Business Rules

- **A gap is a gap, never a zero.** A disconnected account, or one the platform wouldn't answer
  for, shows em dashes and the reason. In a spend report a 0 would read as "we spent nothing",
  which is a different and false claim.
- **The whole window is re-read every night**, not just yesterday, because ad platforms restate
  recent days as conversions land. The daily upsert is what makes that safe.
- **A failed read marks the account not-connected** with the reason, and the nightly sweep then
  skips it. "Refresh" re-connects first, so a fixed permission is picked up without a support
  ticket.
- **`Feature::AdsReporting` is agency-only** (every agency tier, per the GTM-0.1 decision that
  tiers differ on capacity not capability). A downgrade stops the reads, not just the panel.
- **The report block is absent, not empty**, when the plan has no ads reporting or the brand has no
  ad accounts, so the PDF and the portal page skip the section entirely.
- **Meta ad account ids are normalised** (`act_` stripped), so the same account can't be added
  twice with and without the prefix.

## Tests

- `tests/Feature/Analytics/AdsReportingTest.php` — the contract's method list by reflection (the
  read-only guarantee); one row a day with a re-read restating it; a refusing source disconnecting
  the account and writing no zeroes; a disconnected account never read until retried; CPC derived
  and CPA unanswerable without results; a plan without the gate seeing nothing and never being
  swept; only Meta accepted and ids normalised; the command queueing only orgs with ad accounts;
  paid beside organic in both the panel and the report (including the PDF); the report block absent
  when there is nothing to report; another org's ad account untouchable; and the default binding
  reporting nothing rather than guessing.

## Known Gaps / Open Questions

- **`ads_read` is not granted on our Meta app**, which is Phase 5 work. `ADS_PROVIDER` therefore
  defaults to none, and until both are in place every read returns null and every panel shows gaps.
  **This is the main thing standing between the code and a usable feature.**
- **No connection flow.** An ad account is added by typing its id, and access is assumed from the
  brand's existing Facebook Login token. There is no account picker and no "which accounts can I
  see" call.
- **Meta only.** TikTok and LinkedIn ads are not read. CG open question §6 asked Meta-only or
  Meta + TikTok; Meta alone covers most agency reporting, so that is where it stopped.
- **No spend trend chart**, only window totals per account. The daily rows are there to draw one
  from when somebody asks.
- **No currency conversion and no cross-account totals in mixed currencies** — the panel sums
  minor units across accounts and labels the result with the first account's currency, which is
  wrong for an agency running one account in GBP and another in USD. It should group by currency
  like the funnel figures do; it does not yet.
- **No attribution of organic vs paid to the same post.** The two are shown side by side, not
  joined: Alima does not know that a boosted post is the same post.
- **B-13.2 (export/deletion)** must list `ad_accounts` and `ad_account_metrics` when it lands.

## Gotchas

- **`connection_status` is optimistic on create.** The first read is what proves access, and it
  flips the account to not-connected with the reason when it fails. That is why adding an account
  can immediately show an error toast.
- **Spend arrives as a decimal string** from Meta in the account's currency, and is stored ×100 as
  minor units. A provider that reports minor units already would need its own conversion.
- **A day in the future is skipped** on upsert, so a timezone disagreement with the platform can't
  write tomorrow's empty row.

## Changelog

- 2026-11-07: Phase GTM-5 (GTM-5.1, GTM-5.2) built: the read-only `AdsSource` contract with Meta
  and null implementations, `ad_accounts` / `ad_account_metrics`, the nightly `ads:sync` sweep,
  `Feature::AdsReporting`, the "Ad spend" panel on the client analytics page, and the "Paid and
  organic" block in the client report (PDF and portal).

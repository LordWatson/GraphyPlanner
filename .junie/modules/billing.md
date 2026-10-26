# Billing / Invoices

## Purpose
Lets an Owner/Strategist raise invoices against a client's retainer (built from line items or as a
fixed amount), email them to the client with an Alima-branded PDF attached, chase them with
reminders, record payments, void or duplicate them, and track everything from an org-wide ledger
(`/invoices`) with cash-flow figures and CSV export, plus a per-client history on the client's
page. The client portal
exposes a read-only version of the same detail (amount/dates/status plus the PDF, no actions). Not
part of the phased plan in `.junie/plans/graphy-development-steps.md`; added as an ad-hoc billing
module alongside the Client (CRM) work.

## Models / Tables
- `Invoice` (`invoices`): `org_id` (FK to `organizations`), `client_id` (FK to `clients`),
  `invoice_number` (generated as `INV-000001`, sequential per organization; unique together with
  `org_id` via a composite unique index — see the 2026-10-13 bugfix note below),
  `status` (`App\Enums\InvoiceStatus`: draft, sent, paid, void), `amount` (decimal 10,2), `is_finalized`
  (bool, default `false`, Phase 7 Step 7.3), `retainer_amount_snapshot` (decimal 10,2, nullable,
  Step 7.3 — the client's `retainer_amount` at the moment of finalizing, so a later retainer change
  never silently alters an already-finalized invoice's `amount`), `currency` (3-letter code, default
  `USD`; chosen per invoice — see "Per-invoice currency" below), `issue_date`, `due_date` (nullable), `description` (nullable text), `sent_at`/`paid_at`
  (nullable timestamps), `pdf_disk`/`pdf_path`/`pdf_url`/`pdf_original_filename`/`pdf_mime_type`/
  `pdf_size` (nullable, mirrors `BrandBrain`'s `persona_*` columns — see `App\Contracts\AssetStorage`).
  `belongsTo` `Organization` and `Client`. `Invoice::hasPdf()` reports whether a PDF has been
  uploaded.
  2026-10-16 lifecycle columns: `finalized_at`, `sent_to_email` (who the invoice email actually
  went to; null = marked sent without emailing), `last_reminded_at`/`reminder_count`,
  `payment_reference`, `voided_at`/`void_reason`. Model helpers: `isOverdue()`/`daysOverdue()`/
  `displayStatus()`/`displayStatusLabel()` (overdue is **derived** from `sent` + `due_date` before
  today, never stored), `itemsSubtotal()`, `needsFinalizing()` (has items, not finalized),
  `canGeneratePdf()` (finalized, or a fixed-amount invoice with no items and amount > 0),
  `canChangeCurrency()` (a `draft` that isn't finalized yet),
  `generatedPdfFilename()`/`shouldRefreshGeneratedPdf()` (only re-render a PDF we generated, never
  a manual upload), `timeline()` (events derived from the timestamps), and an `overdue()` scope.
- `Client::invoices()`: `hasMany` `Invoice`. `Client::billing_email`/`billing_address` (2026-10-16,
  billing-gated like `retainer_amount`) and `Client::invoiceRecipientEmail()` (billing email,
  falling back to `approval_email`).
- `Organization::billing_details`/`payment_instructions` (text, printed on PDFs/emails) and
  `invoice_payment_terms_days` (default 14, applied as the due date when an invoice is created
  without one). Edited in Settings > Organization (see `settings.md`).
- `InvoiceItem` (`invoice_items`, Phase 7 Step 7.1): `invoice_id` (FK, cascade delete), `org_id`
  (denormalized from the invoice, for simple org-scoped reporting later), `post_id` (nullable FK to
  `posts`, `nullOnDelete` — optionally ties an item back to the content it bills for),
  `description` (text), `is_billable` (bool, default `true`), `price` (decimal 10,2, nullable),
  `quantity` (unsigned int, default 1), `sort_order` (unsigned int). `belongsTo` `Invoice`,
  `Organization`, `Post`. `InvoiceItem::lineTotal()`: `is_billable ? price * quantity : 0`
  (computed, never stored — treats a missing `price` as `0`). `Invoice::items()`: `hasMany`
  `InvoiceItem`, ordered by `sort_order`.

## Actions / Services / Contracts
- `App\Actions\Invoices\CreateInvoiceAction`: creates a `draft` invoice for a client, auto-generates
  the next sequential `invoice_number` for the client's org, sets `currency` from the request
  (falling back to the client's `Organization::default_currency`), wrapped in `DB::transaction`,
  logs creation (incl. the currency). `nextInvoiceNumber()` derives the number from the org's highest existing
  `invoice_number` (not a row count) and locks that lookup (`lockForUpdate()`) inside the
  surrounding transaction so two concurrent creations for the same org can't compute the same
  next number — see the 2026-10-13 bugfix note below.
- `App\Actions\Invoices\SendInvoiceAction`: transitions `draft` → `sent`, stamping `sent_at`. Throws a
  `ValidationException` (`status` field) if the invoice isn't currently `draft`.
- `App\Actions\Invoices\MarkInvoicePaidAction`: transitions `sent` → `paid`, stamping `paid_at`.
  Throws a `ValidationException` (`status` field) if the invoice isn't currently `sent`.
- `App\Actions\Invoices\UpdateInvoiceAction`: updates an invoice's editable details (amount,
  currency, dates, description) — never touches `status`, `invoice_number`, `org_id` or
  `client_id`. A null/omitted `currency` keeps the current one; changing it is rejected
  (`ValidationException` on `currency`) unless `Invoice::canChangeCurrency()`. Wrapped in
  `DB::transaction`, logs the update (with `currency_from`/`currency_to` when it changed).
- `App\Actions\Invoices\UploadInvoicePdfAction`: stores (or replaces) the invoice's PDF via the
  shared `App\Contracts\AssetStorage`, deleting the previously stored file (if any) first — mirrors
  `App\Actions\BrandBrains\UploadBrandPersonaAction`.
- `App\Actions\Invoices\DeleteInvoiceAction`: deletes the stored PDF (if any) via `AssetStorage`,
  then the invoice row, wrapped in `DB::transaction`.
- `App\Policies\InvoicePolicy`: `viewAny`/`view` defer to `ClientPolicy::viewBilling`; `create`/`send`/
  `markPaid`/`update`/`delete` require the acting user to be in the same org as the invoice/client
  and hold `Owner` or `Strategist` role (mirrors `ClientPolicy::update`). Registered via
  `Gate::policy` in `AppServiceProvider`.
- `App\Actions\Invoices\GetMonthlyInvoiceTotalsAction`: org-scoped monthly invoiced/paid totals used
  by the dashboard's invoice revenue chart (see `.junie/modules/home.md`). Only counts invoices in
  the organization's `default_currency` (other currencies can't be summed into one series).
- `App\Actions\InvoiceItems\AddInvoiceItemAction`/`UpdateInvoiceItemAction`/
  `DeleteInvoiceItemAction` (Phase 7 Step 7.2): each wrapped in `DB::transaction()`, each rejecting
  the operation with a `ValidationException` (`invoice` field) unless the parent invoice's `status`
  is `draft` — line items are frozen once an invoice is sent. `AddInvoiceItemAction` assigns the
  next `sort_order` (`max(sort_order) + 1` within the invoice). Items remain editable even after
  `is_finalized` is `true` (needed so a generated PDF can be corrected/regenerated) — each action
  calls `Invoice::refreshFinalizedAmount()` after the write when the invoice is already finalized,
  which re-sums every item's `lineTotal()` plus the already-snapshotted `retainer_amount_snapshot`
  and re-persists `Invoice::amount`, so the locked-in total (and any later `generate-pdf` call)
  always reflects the current items without a separate un-finalize/re-finalize step.
- `App\Policies\InvoiceItemPolicy`: same Owner/Strategist-only, same-org rule as `InvoicePolicy`.
  Registered via `Gate::policy` in `AppServiceProvider`.
- `App\Actions\Invoices\FinalizeInvoiceAction` (Phase 7 Step 7.3): callable only on a `draft`,
  not-yet-finalized invoice that has at least one item (each rejected with a `ValidationException`
  on the `invoice` field otherwise). Sums every item's `lineTotal()`, adds the client's current
  `retainer_amount` (`0` if `null`), writes the result into `Invoice::amount`, snapshots the
  retainer used into `retainer_amount_snapshot`, and flips `is_finalized` to `true`. Wrapped in
  `DB::transaction()`, logs the computed amount. `UpdateInvoiceAction` still rejects a direct
  `amount` change once `is_finalized` is `true` (a `ValidationException` on the `amount` field) —
  only item edits (via `Invoice::refreshFinalizedAmount()`, see above) may move `Invoice::amount`
  post-finalize. Finalizing itself is a one-way flag (no un-finalize action) distinct from
  `SendInvoiceAction`'s `draft → sent` transition (an invoice can be finalized while still `draft`,
  then sent).
- `App\Actions\Invoices\GenerateInvoicePdfAction` (Phase 7 Step 7.5): callable only on a
  finalized invoice (`ValidationException` on the `invoice` field otherwise). Renders
  `resources/views/pdf/invoice.blade.php` (branded per `.junie/brand-guidelines.md` §9 — see the
  PDF bullet under Business Rules) via
  `barryvdh/laravel-dompdf`, writes the output to a temp file wrapped in a test-mode `UploadedFile`,
  then stores it through the same `App\Contracts\AssetStorage` and `pdf_*` columns
  `UploadInvoicePdfAction` already uses — deleting any previously stored PDF first. Wrapped in
  `DB::transaction()`, logs on success. All money in the PDF (item prices, line totals, subtotal,
  retainer, grand total) is rendered via `App\Support\MoneyFormatter::format()` — a thin wrapper
  around PHP's `intl` `NumberFormatter` that renders `Invoice::currency`'s actual symbol/placement
  (e.g. `$100.00`, `€100,00`), not a hardcoded `USD`-looking string; the frontend already does the
  equivalent via `resources/js/lib/currency.ts`'s `Intl.NumberFormat`.

### Invoicing v3 (2026-10-16)
- `CreateInvoiceAction`: `amount` is now optional (defaults to `0`; itemised invoices get theirs on
  finalize) and a missing `due_date` becomes `issue_date + Organization::invoice_payment_terms_days`.
- `FinalizeInvoiceAction`: also stamps `finalized_at`; a **pure retainer invoice** (no items, client
  retainer > 0) can now be finalized. Only "no items and no retainer" is rejected.
- `SendInvoiceAction` (rewritten): rejects non-drafts, itemised drafts that aren't finalized, and
  `amount <= 0` (`status` field). Transitions to `sent`, stamps `sent_at`/`sent_to_email`, refreshes
  the generated PDF (`shouldRefreshGeneratedPdf()`), then delivers via `InvoiceDeliveryService`.
  Returns `{invoice, recipient, emailed}`; no recipient means marked sent without emailing; a mail
  failure is logged, `sent_to_email` cleared, and the controller toasts a warning.
- `SendInvoiceReminderAction`: `sent` only; 24h cooldown (`COOLDOWN_HOURS`); recipient = override,
  then `sent_to_email`, then the client billing/approval email (rejects on `email` when none);
  bumps `reminder_count`/`last_reminded_at` only after a successful send.
- `MarkInvoicePaidAction`: optional `paid_at` (date, not in the future) + `payment_reference`;
  refreshes the generated PDF so it doubles as a receipt.
- `VoidInvoiceAction`: `sent` to `void` with an optional reason; refreshes the generated PDF.
  Drafts are deleted instead; paid invoices can't be voided.
- `DuplicateInvoiceAction`: new draft (via `CreateInvoiceAction`, so numbering stays sequential)
  issued today with the original's payment window, currency, notes and every item; never finalized. A
  fixed-amount source keeps its amount.
- `DeleteInvoiceAction`: now rejects `sent` ("void it instead") and `paid` invoices.
- `UpdateInvoiceAction`: a null/omitted `amount` is ignored (the edit page omits it once the amount
  is derived from items).
- `GenerateInvoicePdfAction`: guarded by `canGeneratePdf()`; `render()` is public; ensures
  `storage/fonts` (dompdf's font-metric cache, committed with a `.gitignore`) exists; the temp file
  is always cleaned up.
- `App\Services\InvoiceDeliveryService`: resolves the recipient and sends `App\Mail\InvoiceSentMail`
  (`emails/invoice-sent.blade.php`, PDF attached from storage, reminder copy variant) plus the
  `App\Notifications\InvoiceIssued` database notification to the client's portal users.
- `App\Services\InvoiceLedgerService`: one filtered query (`status` incl. derived `overdue`/
  `outstanding`, `client_id`, `search` over number/notes/client name, `from`/`to` issue date) shared
  by the ledger page and the CSV export (`csvRows()`), plus `summary()` (outstanding, overdue, paid
  this month, drafts, average days sent-to-paid over 12 months, computed in PHP for driver parity).
  Money totals are `*_totals` lists of `{currency, total}` — one entry per currency, org default
  first — never one mixed-currency sum.
- `App\Support\Invoices\InvoicePresenter`: `row()`/`detail()`/`abilities()`/`portal()`, the single
  place invoice arrays are shaped for Inertia (ledger, detail/edit, client billing tab, portal).
  `abilities()` combines policy checks with state so buttons only render when they can succeed
  (incl. `edit_currency` = `canChangeCurrency()` + `update`).

### Per-invoice currency (2026-10-16)
- Each invoice is billed in its own `currency`, picked in the "New invoice" dialog (preselected to
  the org's `default_currency`) and changeable on the edit page while the invoice is an unfinalized
  draft. `StoreInvoiceRequest`/`UpdateInvoiceRequest` accept `currency` as nullable, `Rule::in`
  `App\Support\Options\CurrencyOptions::codes()` (plus the org default / invoice's current code,
  so a legacy code outside the curated list never blocks a save).
- Every figure for an invoice (items, subtotal, retainer, total, dialogs, ledger rows, portal, PDF,
  email) is formatted with `invoice.currency` — the shared `currency` Inertia prop is only used for
  org-level figures (client retainer, dashboard chart) and as the fallback/primary currency.
- Aggregates never add different currencies: the ledger `summary`, the client page "Outstanding"
  stat and the portal Outstanding/Paid stats show per-currency totals (`totalsByCurrency()` /
  `formatCurrencyTotals()` in `resources/js/lib/currency.ts`, e.g. "£1.2K + €300"). The dashboard
  revenue chart only charts the org default currency (its eyebrow names it).
- `InvoiceController@index`/`@edit` and `ClientController@show` (billing viewers only) pass a
  `currencies` options prop (`CurrencyOptions::options()`).
- `InvoicePolicy`: new `viewLedger` (Owner/Strategist, class-level), `void`, `remind`, `duplicate`.
- PDF (`resources/views/pdf/invoice.blade.php`) in the Alima language (brand guidelines §9):
  Cloud Cream paper, a Midnight Ink header band carrying the master panda mark on a Deep Teal tile
  (read from `public/brand/alima_mark_cream.svg`), a Poppins SemiBold headline with one upright teal
  accent word that reads by status ("Payment *due*.", "Payment *overdue*.", "Paid in
  *full*.", "Invoice *void*.", "Draft *invoice*."), DM Mono spec-sheet labels, a status pill, an ink
  "Amount due" cell, Billed to / From blocks (client legal name + billing address/email, studio
  billing details), a hairline items table (non-billable rows tagged "Included", retainer as its own
  line), an ink total block, Notes + "How to pay" cards, and a fixed footer with the teal Alima
  lock-up ("Prepared with"). Poppins Regular/SemiBold and DM Mono are bundled as static TTFs in
  `resources/fonts/pdf/` (SIL OFL, from Google Fonts) because dompdf can't use web/variable fonts;
  token colours are inlined as hex.

## Phase M-1 additions
- The ledger (`/invoices` and the client Billing tab) renders through `InvoiceLedgerList` on the
  shared `DataTable`. `/invoices` sorts server-side: `InvoiceIndexRequest` accepts
  `sort` ∈ `InvoiceLedgerService::SORTS` (`invoice_number`, `issue_date`, `due_date`, `amount`) and
  `direction`, ordered with an `id` tiebreak (default newest issued first). The issue-date filters
  use the shared `DatePicker`.
- `GET invoices/export` is behind `password.confirm` (Step M-1.2).

## Phase M-4 additions (Invoicing you can actually send)

### M-4.1 — VAT/tax lines, VAT/TRN numbers, per-client currency
- **Tax rates** are a per-org list: `TaxRate` (`tax_rates`: `org_id`, `name`, `rate` decimal(5,2) %,
  `sort_order`, `archived_at`), org-scoped, `active()` scope. Managed in Settings > Organization
  (Owner-only, `TaxRatePolicy`) via `TaxRateController` (`tax-rates.store`/`.update`/`.archive`) and
  `Create`/`Update`/`ArchiveTaxRateAction`. Archived (never deleted) once invoices snapshot them.
  Starter rates (UK 20/0/exempt, UAE 5/0) seed from `config/tax.php` via `SeedDefaultTaxRatesAction`
  on org registration (`RegisterOrganizationAction`), with a backfill migration for existing orgs.
- **VAT/TRN numbers:** `organizations.tax_registration_number` + `tax_registration_label`
  (Settings > Organization); `clients.tax_registration_number` (client edit, billing-gated). Both
  print on the PDF; the org's presence drives the **"Tax invoice"** heading (`Organization::hasTaxRegistration()`).
- **Tax on invoices:** `invoice_items` carry `tax_rate_id` + snapshotted `tax_rate`/`tax_name`
  (`TaxRateSnapshot` fills them in `Add`/`UpdateInvoiceItemAction`); `InvoiceItem::taxAmount()` is net
  × rate. A fixed-amount invoice (no items) carries an invoice-level `invoices.tax_rate_id`.
  `invoices.subtotal` (net), `tax_total`, `tax_breakdown` (json `[{name,rate,net,tax}]`) are snapshotted;
  `amount` stays the **grand total due** (= subtotal + tax). `App\Support\Invoices\InvoiceTotals` is
  the single calculator (pure, over net+rate lines; tax only where a rate is set, so zero-rate/legacy
  invoices read exactly as before). `Invoice::refreshFinalizedAmount()` / `netSubtotal()` /
  `taxBreakdownRows()` / `finalizedTaxLines()` back it. `FinalizeInvoiceAction` snapshots the
  retainer's rate (`retainer_tax_rate`/`retainer_tax_name`) from the client's `defaultTaxRate` and
  **rejects a retainer invoice whose currency ≠ the client's billing currency** (no conversion).
- **Per-client billing currency:** `clients.billing_currency` (nullable → org default,
  `Client::billingCurrency()`). `CreateInvoiceAction` defaults a new invoice's currency to it, and the
  client's `default_tax_rate_id` fills a fixed-amount invoice's / campaign fee's rate
  (`CreateInvoiceFromCampaignAction`).
- PDF (`invoice.blade.php`): "Tax invoice" heading + band caption when the org is registered, VAT/TRN
  in the From/Billed-to blocks, and a Subtotal → tax-per-rate → Total block (all via `MoneyFormatter`).
  CSV export gains Subtotal + Tax columns.
- **Tax adviser sign-off on the exact UK/UAE required contents is still _pending_** (see Decisions in
  `mvp.md`); rates/labels are editable per-org data so adjusting to the adviser's wording is config,
  not code. M-4.1 is feature-complete pending that sign-off.

### M-4.2 — Payment confirmation handshake (no online payments)
- `InvoiceStatus::PendingConfirmation` ("Payment reported") sits between `sent` and `paid`. State
  machine: `draft → sent → pending_confirmation → paid`, plus `pending_confirmation → sent` (revert),
  `sent → paid` (org direct), `sent → void` unchanged. **A `pending_confirmation` invoice cannot be
  voided — it is reverted to `sent` first** (`VoidInvoiceAction` stays `sent`-only). Invalid
  transitions are still a `422` on `status`.
- The client (portal, read-only) reports payment via `ReportInvoicePaymentAction`
  (`POST /portal/invoices/{invoice}/report-payment`, scoped to their own `client_id`, sent-only;
  drafts 404), storing `payment_reported_at`/`_method`/`_reference`/`_note` and notifying the org's
  Owner/Strategist (`InvoicePaymentReported`). It never marks the invoice paid.
- The org confirms via `MarkInvoicePaidAction` (now accepts `sent` **or** `pending_confirmation`;
  carries the client's reported reference when none supplied) — `invoices.confirm-payment` — or
  reverts via `RevertPaymentReportAction` — `invoices.revert-payment` — both authorized by
  `InvoicePolicy::confirmPayment` (Owner/Strategist). `InvoicePresenter::abilities()` exposes
  `confirm_payment`/`revert_payment`; `portal()` exposes `can_report_payment` +
  `is_pending_confirmation` + the reported fields. Graphy never processes money.

### M-4.3 — Recurring retainer drafts
- `clients.auto_invoice_retainer` (opt-in) + `invoices.retainer_period` (e.g. `2026-11`, `2026-Q4`).
  `GenerateRetainerDraftsAction` (command `invoices:generate-retainer-drafts`, scheduled daily 06:30)
  drafts the current period's retainer invoice for each opted-in client with a retainer and a
  recurring cycle, in the client's billing currency, idempotent on `(client_id, retainer_period)`.
  **Drafts only — never finalized or sent** (auto-send is out of the MVP); notifies Owner/Strategist
  (`RetainerDraftsReady`).

## Routes / Controllers
- `GET /invoices` -> `InvoiceController@index` (ledger: `invoices` paginator of `row()`s, `summary`,
  `filters`, `clients` options) and `GET /invoices/export` -> `@export` (streamed CSV, same filters),
  both validated/authorized by `InvoiceIndexRequest` (`viewLedger`).
- `POST /invoices/{invoice}/remind` -> `@remind` (`SendInvoiceReminderRequest`),
  `POST /invoices/{invoice}/void` -> `@void` (`VoidInvoiceRequest`),
  `POST /invoices/{invoice}/duplicate` -> `@duplicate` (redirects to the new draft). `send`/
  `mark-paid` now take `SendInvoiceRequest` (`email`, `message`) / `MarkInvoicePaidRequest`
  (`paid_at`, `payment_reference`) and redirect `back()` with a toast; `store` now redirects to the
  new invoice's detail page. All registered in both the authenticated and `preview` groups.
- `POST /clients/{client}/invoices` → `InvoiceController@store` — validated via
  `StoreInvoiceRequest`, delegates to `CreateInvoiceAction`, redirects to `clients.show`.
- `GET /invoices/{invoice}` → `InvoiceController@show` — authorizes `view`, renders the
  `invoices/show` detail page (details, PDF viewer, Edit/Send/Mark paid/Delete actions gated by
  `can`).
- `GET /invoices/{invoice}/edit` → `InvoiceController@edit` — authorizes `update`, renders the
  `invoices/edit` page (editable fields + PDF upload/replace form).
- `PUT /invoices/{invoice}` → `InvoiceController@update` — validated via `UpdateInvoiceRequest`,
  authorizes `update`, delegates to `UpdateInvoiceAction`, redirects to `invoices.show`.
- `DELETE /invoices/{invoice}` → `InvoiceController@destroy` — authorizes `delete`, delegates to
  `DeleteInvoiceAction`, redirects to `clients.show`.
- `POST /invoices/{invoice}/pdf` → `InvoiceController@uploadPdf` — validated via
  `UploadInvoicePdfRequest` (`mimes:pdf`, `max:assets.max_upload_size_kb`), authorizes `update` (via
  the request), delegates to `UploadInvoicePdfAction`, redirects to `invoices.show`.
- `POST /invoices/{invoice}/send` → `InvoiceController@send` — authorizes `send`, delegates to
  `SendInvoiceAction`.
- `POST /invoices/{invoice}/mark-paid` → `InvoiceController@markPaid` — authorizes `markPaid`,
  delegates to `MarkInvoicePaidAction`.
- `POST /invoices/{invoice}/items` → `InvoiceItemController@store` — validated via
  `StoreInvoiceItemRequest` (authorizes `create` against the invoice), delegates to
  `AddInvoiceItemAction`, redirects to `invoices.show`.
- `PUT /invoice-items/{invoiceItem}` → `InvoiceItemController@update` — validated via
  `UpdateInvoiceItemRequest` (authorizes `update`), delegates to `UpdateInvoiceItemAction`,
  redirects to `invoices.show`.
- `DELETE /invoice-items/{invoiceItem}` → `InvoiceItemController@destroy` — authorizes `delete`,
  delegates to `DeleteInvoiceItemAction`, redirects to `invoices.show`. All three routes are
  registered in both the authenticated group and the read-only `preview` group (the latter rejects
  every non-GET request with a 403 regardless, per the preview middleware's write-block).
- `POST /invoices/{invoice}/finalize` → `InvoiceController@finalize` (Phase 7 Step 7.3) —
  authorizes `InvoicePolicy::finalize` (same Owner/Strategist-only, same-org rule as the rest of
  billing), delegates to `FinalizeInvoiceAction`, redirects to `invoices.show`. Registered in both
  the authenticated and `preview` groups.
- `POST /invoices/{invoice}/generate-pdf` → `InvoiceController@generatePdf` (Phase 7 Step 7.5) —
  authorizes `update` (same Owner/Strategist-only rule as the manual PDF upload), delegates to
  `GenerateInvoicePdfAction`, redirects to `invoices.show`. Registered in both the authenticated and
  `preview` groups. `InvoiceController@show`/`@edit` expose a `can.generate_pdf` flag (`true` only
  once `is_finalized`).
- `ClientController@show` now also returns an `invoices` array (empty unless the viewer passes
  `ClientPolicy::viewBilling`) and `can.createInvoice`, each invoice entry including
  `can.send`/`can.mark_paid`/`can.update`/`can.delete` flags for the acting user. `can.send`/
  `can.mark_paid` combine the `InvoicePolicy` role check with the invoice's current `status`
  (`can.send` only when `draft`, `can.mark_paid` only when `sent`), so the Send/Mark paid buttons
  never render for an already-sent or already-paid invoice.
- `GET /portal/invoices/{invoice}` → `PortalInvoiceController@show` (unchanged route, richer
  payload) now also returns `pdf_url`/`pdf_original_filename` so the portal detail page can render
  the same PDF read-only, with no edit/upload/delete actions exposed.
- Inertia UI: `resources/js/pages/clients/show.tsx` renders a "Billing" tab with an inline
  create-invoice form (Owner/Strategist only) and a list of invoices with status badges and
  Send/Mark paid buttons, gated per-invoice by the `can` flags from the backend; each invoice number
  links to its `invoices/show` detail page. See the 2026-09-16 Tabs redesign note below — the
  "Outstanding" stat card on the hero row also jumps straight to this tab.
- `resources/js/pages/invoices/show.tsx`: standalone detail page (details, inline PDF `<iframe>`
  viewer when a PDF is uploaded, Edit/Send/Mark paid/Finalize/Delete buttons gated by `can`). Phase
  7 Step 7.4 added an "Items" section: a list of existing items with inline edit (description,
  billable checkbox, price, quantity) and delete, an add-item form (shown only while
  `can.manage_items` — requires `status === draft`, independent of `is_finalized` so items stay
  editable after finalizing/generating a PDF), a running items subtotal, and — once
  `invoice.is_finalized` — the retainer snapshot and grand total (which updates live from any item
  edit via `Invoice::refreshFinalizedAmount()`). `InvoiceController@show` passes `invoice.items`
  (each with a server-computed `line_total`), `invoice.items_subtotal`, `invoice.is_finalized`,
  `invoice.retainer_amount_snapshot`, and `can.manage_items` (`false` once `status !== draft`) /
  `can.finalize` (`false` once `status !== draft` or already `is_finalized`).
- `resources/js/pages/invoices/edit.tsx`: standalone edit page (amount/issue_date/due_date/
  description form, plus an "Upload"/"Replace" PDF form mirroring the Brand Persona upload UI on
  `brand-brain/edit.tsx`). Currency is a select while `can.edit_currency` (unfinalized draft),
  otherwise a locked read-only field showing `invoice.currency`. Once `invoice.is_finalized`, a short note above the manual upload form
  points the user to the "Generate branded PDF" button on `invoices/show.tsx` instead — the manual
  upload form itself is left in place as a one-off override, never removed (Step 7.5).
- `resources/js/pages/invoices/show.tsx`'s "Invoice PDF" section gained a "Generate branded PDF"
  button, shown only when `can.generate_pdf` — submits to `invoices.generate-pdf` and re-renders the
  same inline `<iframe>` viewer used for a manually-uploaded PDF (no separate preview UI needed).
- `resources/js/pages/portal/invoices/show.tsx`: read-only portal detail page now also renders the
  invoice PDF inline (via `<iframe>`) when `pdf_url` is present — no upload/edit controls.

## Business Rules
- Invoices are always scoped to the client's `org_id`; cross-org access is denied by the policy.
- Only `Owner`/`Strategist` can create, send, mark invoices as paid, edit their details, upload/
  replace the PDF, or delete them — the same roles allowed to see billing-sensitive client fields
  (`ClientPolicy::viewBilling`).
- Status machine: `draft` → `sent` → `paid`, plus `sent` → `void` (2026-10-16) and, since M-4.2,
  `sent` → `pending_confirmation` → `paid` with `pending_confirmation` → `sent` (revert). `overdue`
  is a derived display status, not a stored one. Only `draft`/`void` invoices can be deleted, and a
  `pending_confirmation` invoice is reverted to `sent` before it can be voided.
- (Original rule) Status is a strict linear state machine: `draft` → `sent` → `paid`. Sending a non-draft invoice or
  marking a non-sent invoice as paid is rejected with a `422` validation error on the `status` field;
  there is no "reopen"/"unsend" action. `UpdateInvoiceAction` never touches `status`.
- `invoice_number` is generated server-side (`INV-######`, sequential per org, unique per org via a
  composite `(org_id, invoice_number)` index) and is never user-editable (not present in
  `UpdateInvoiceRequest`'s rules).
- `currency` is chosen per invoice (default: the org's `default_currency`) and locks once the
  invoice is finalized or sent. No conversion is ever performed.
- The invoice PDF is optional — an invoice can be created, sent, and paid without ever having a
  PDF attached; `invoices/show.tsx` and the portal detail page simply omit the viewer when
  `pdf_url` is `null`.
- Client reviewers, Designers, and Viewers never see invoices (the `invoices` payload is an empty
  array for them, same redaction approach as `retainer_amount`/`billing_cycle`). Client reviewers
  only ever reach the read-only `portal/invoices/*` pages, scoped to their own `client_id` by the
  `portal` middleware — they never get `can.update`/`can.delete` style flags.
- Finalizing (Step 7.3) is a one-way lock, separate from the `draft → sent → paid` status machine:
  an invoice can be finalized while still `draft` (locking in `amount` from its items + retainer
  snapshot), then sent/paid as normal. A finalized invoice can never be un-finalized, and its items
  can never be added/edited/deleted again, nor can `amount` be changed via `UpdateInvoiceAction`.

## Seeders
- `Database\Seeders\InvoiceSeeder`: seeds a mix of paid/sent/draft invoices for the `Recommendo`
  (monthly) and `Frzn` (quarterly) clients created by `ClientSeeder`, using `updateOrCreate` on
  `invoice_number` so it's safe to re-run. Called from `DatabaseSeeder` after `BrandBrainSeeder`.
- `Database\Factories\InvoiceFactory`: base factory (draft state) plus `sent()`/`paid()` states for
  tests; derives `org_id` from the related `Client` when not explicitly set.

## Tests
- `tests/Feature/InvoiceControllerTest.php`: covers create (Owner allowed, Designer forbidden),
  send/mark-paid happy paths, invalid state transitions (double-send, pay-a-draft), org isolation,
  that client reviewers get an empty `invoices` payload on the client show page, that a `paid`
  invoice exposes `can.send`/`can.mark_paid` as `false` on the client show page, show/edit page
  access (Owner allowed, Designer/other-org forbidden), update/delete (Owner allowed, Designer
  forbidden), and PDF upload/replace/rejection (non-PDF file, Designer forbidden).
- `tests/Feature/PortalInvoiceControllerTest.php`: covers the portal's read-only list/detail
  (client scoping, unpaid flagging, 404 on another client's invoice).
- `tests/Unit/Models/InvoiceItemTest.php`: `lineTotal()` for a billable item with quantity > 1, a
  billable item with no price yet (treated as `0`), and a non-billable item (always `0`).
- `tests/Feature/InvoiceItemControllerTest.php`: add/update/delete a line item (Owner allowed,
  Designer forbidden, other-org forbidden), and rejection (`422` on the `invoice` field) when
  attempted on a `sent` invoice.
- `tests/Unit/Actions/Invoices/FinalizeInvoiceActionTest.php`: billable + retainer summation, a
  `null` retainer treated as `0`, and rejection (no items, non-draft, already-finalized).
- `tests/Feature/InvoiceControllerTest.php` (Step 7.3/7.4 additions): the `finalize` route (Owner
  allowed, Designer forbidden, rejected with no items), that items/amount can no longer be
  changed once `is_finalized`, and that the `invoices/show` Inertia payload exposes `invoice.items`/
  `items_subtotal`/`is_finalized`/`can.manage_items`/`can.finalize`.
- `tests/Feature/InvoiceControllerTest.php` (Step 7.5 additions): the `generate-pdf` route (Owner
  allowed on a finalized invoice — asserts `pdf_path`/`pdf_mime_type` and that the file exists on
  the faked disk; Designer forbidden; rejected on a non-finalized invoice).

## Known Gaps / Open Questions
- **Tax adviser sign-off pending (M-4.1):** the exact required contents of UK/UAE tax invoices still
  need the adviser's confirmation (recorded in `mvp.md` Decisions). Tax rates/labels are editable
  per-org data, so adjusting to the final wording is config, not code.
- Sending emails the client as of 2026-10-16. Not yet built: automatic scheduled overdue reminders
  (deliberately manual so the studio controls what goes to clients), partial payments, credit notes,
  discounts, configurable invoice-number formats (all deferred to M-12), and linking items to posts
  from the UI (`post_id` exists on items). Tax/VAT lines shipped in M-4.1.
- `OrganizationController@update` passes `validated()` (`array<string, mixed>`) to an action typed
  with an array shape: a PHPStan `argument.type` report that predates v3.
- No currency conversion. Changing the org's `default_currency` doesn't touch existing invoices.
  The client's `retainer_amount` has no currency of its own (it's read as the org default), yet
  finalizing adds it as-is to an invoice in any currency — check the retainer when billing a
  retainer client in a non-default currency. A per-client billing currency could be a follow-up.
- The dashboard revenue chart ignores invoices billed in non-default currencies.
- The PDF is either generated (finalized invoices, via `GenerateInvoicePdfAction` — Step 7.5) or
  uploaded manually (`UploadInvoicePdfAction`, kept as a one-off override per the "don't delete
  working functionality" principle); both paths store through the same shared
  `App\Contracts\AssetStorage` (local `public` disk today, same as Assets/Brand Persona).
- Phase 8 (client reporting from invoice line items) is not yet started.

## Changelog
- 2026-10-24: Phase M-4. **M-4.1** — `TaxRate` model + per-org tax-rate list (Settings, `TaxRatePolicy`,
  `Create`/`Update`/`ArchiveTaxRateAction`, `SeedDefaultTaxRatesAction` + backfill migration,
  `config/tax.php`); org VAT/TRN number + label; client VAT/TRN, `billing_currency`,
  `default_tax_rate_id`; per-item + invoice-level tax (`tax_rate_id`/`tax_rate`/`tax_name`,
  `TaxRateSnapshot`), `invoices.subtotal`/`tax_total`/`tax_breakdown`, `InvoiceTotals` calculator;
  retainer tax snapshot + mixed-currency-retainer rejection on finalize; "Tax invoice" PDF heading +
  VAT/TRN + tax lines; CSV Subtotal/Tax columns; campaign fee inherits the client's rate. Tests:
  `InvoiceTotalsTest`, `TaxInvoicingTest`. **M-4.2** — payment handshake: `InvoiceStatus::PendingConfirmation`,
  `payment_reported_*` columns, `ReportInvoicePaymentAction` (portal) + `RevertPaymentReportAction`,
  `MarkInvoicePaidAction` widened to confirm, `InvoicePolicy::confirmPayment`, `InvoicePaymentReported`
  notification, portal "Mark Paid" dialog + internal Confirm/Not-received buttons; a
  `pending_confirmation` invoice reverts to `sent` before voiding. Tests: `PaymentHandshakeTest`.
  **M-4.3** — `clients.auto_invoice_retainer` + `invoices.retainer_period`, `GenerateRetainerDraftsAction`
  + `invoices:generate-retainer-drafts` (daily, drafts only, idempotent), `RetainerDraftsReady`. Tests:
  `RetainerDraftsTest`. Adviser sign-off on exact invoice contents stays pending (see Known Gaps).
- 2026-10-18: Campaigns enhancement C-8.3 — the campaign Commercials panel shows invoiced and paid (from `invoice_items.campaign_id`) and, for Owners, AI spend.
- 2026-10-18: Campaigns enhancement C-8 — `campaigns.billing_type` / `fee_amount` and `invoice_items.campaign_id`. `CreateInvoiceFromCampaignAction` adds a fixed-fee campaign to the latest non-finalized draft (or a new invoice), asks for confirmation before billing again, and never edits a finalized invoice. Fees are Owner/Strategist only.
- 2026-10-18: The client page's Billing tab now runs through `InvoiceLedgerService::query`
  (scoped to the client) via `ClientBillingTabRequest`, so its status filter (draft / outstanding
  / overdue / paid / void) and number/description search mean the same as on the org ledger;
  paged 8 at a time (`ClientTabController::INVOICES_PER_PAGE`).
- 2026-10-18: Step I-2.4 — invoices on the client page moved to their own tab
  (`GET /clients/{client}/billing` → `ClientTabController@billing`, paginated 25, `viewBilling`);
  deleting an invoice redirects there (`clients.billing`).
- 2026-10-18: Step I-1.6 — invoice PDFs are private: `GET invoices/{invoice}/pdf`
  (`InvoiceController::showPdf`, `InvoicePolicy::view`) and `GET portal/invoices/{invoice}/pdf`
  (`PortalInvoiceController::pdf`, portal isolation, never drafts) stream them inline via
  `Invoice::pdfResponse()`. `InvoicePresenter` hands out those routes as `pdf_url` instead of a
  storage URL. A guessed `/files/...` URL without a signature is refused.
- 2026-10-18: Step I-1.5 — invoices are legal records: `Invoice` uses `SoftDeletes` (deleting a draft
  or void invoice is now a soft delete), `invoices.client_id` changed from `CASCADE` to `NO ACTION`
  so a client with invoices can't be hard-deleted, and `Invoice::client()` resolves archived
  clients (`withTrashed()`). Archiving a client keeps every invoice queryable.
- 2026-10-18: The invoice PDF's artwork/fonts moved to the shared `App\Support\Pdf\PdfBrand` + `pdf.partials.brand-fonts` (same output), reused by the analytics report PDF. Invoice totals now also feed 06 Analytics (`analytics.md`).
- 2026-10-16: Alima rebrand. Invoice PDF re-skinned (Poppins + DM Mono TTFs replace the Instrument
  families, master Alima mark/lock-up, Alima status colours, teal accent, orange eyebrow dash);
  `invoice-sent` email re-skinned; ledger status tabs select with a teal pill and no longer wrap.
  No route, prop or behaviour changes.
- 2026-10-16: Per-invoice currency (reverses the 2026-09-20 "org default only" rule on request).
  `currency` selectable on create (New invoice dialog) and on the edit page while an unfinalized
  draft; duplicates keep the source's currency; every per-invoice figure uses `invoice.currency`;
  ledger/client/portal totals are split per currency; dashboard chart limited to the org default.
  Tests: create default/chosen/invalid currency, change on draft, keep when omitted, rejection once
  finalized/sent (`InvoiceControllerTest`), per-currency summary (`InvoiceLedgerTest`), duplicate
  keeps currency (`InvoiceLifecycleTest`), chart excludes other currencies (`DashboardTest`).
- 2026-10-16: Invoicing v3. Restyled the generated PDF to the portal's "Studio Paper" look (bundled
  brand fonts, ink band, serif status headline, spec sheet, payment/notes cards). Added an
  org-wide ledger page (`/invoices`, sidebar "Invoices" for Owner/Strategist) with outstanding /
  overdue / paid-this-month / average-days-to-pay stats, status/client/date/search filters and CSV
  export; a derived `overdue` status everywhere (new `overdue`/`void` tones in `lib/status.ts`);
  emailing invoices on send (`InvoiceSentMail`, PDF attached, portal notification); payment
  reminders with a 24h cooldown; payment date/reference on mark-paid; voiding; duplicating as a new
  draft; optional amount + default payment terms on create; pure-retainer finalize; delete limited
  to draft/void; a lifecycle timeline; client billing email/address; org billing details, payment
  instructions and payment terms. Portal: drafts are no longer visible (previously a client could
  see the studio's drafts); the detail page shows the item breakdown, overdue state and how to pay.
  Fixed the item editor's "Billable" checkbox (an unchecked Radix checkbox submitted nothing, which
  failed `required|boolean`) with an explicit hidden input. New shared React pieces in
  `resources/js/components/invoices/` (types, ledger list, action dialogs, new-invoice dialog,
  `ConfirmActionButton`) and a `ui/textarea.tsx` primitive. Tests: `InvoiceLifecycleTest`,
  `InvoiceLedgerTest`, `tests/Unit/Models/InvoiceTest.php`, plus additions to the portal, settings
  and client tests; existing tests updated for the new redirects and rules.
- 2026-10-13: Fixed a bug (`UniqueConstraintViolationException` on `invoices_invoice_number_unique`)
  where creating an invoice could fail with a duplicate `invoice_number`. Root cause:
  `invoice_number` was generated from a simple per-org row count (so two different orgs with the
  same invoice count collided, since the column was globally unique) and reused a number once any
  invoice for an org was deleted. Changed the unique constraint to a composite
  `(org_id, invoice_number)` index (migration `2026_10_13_210000_make_invoice_number_unique_per_org`)
  and rewrote `CreateInvoiceAction::nextInvoiceNumber()` to derive the next number from the org's
  highest existing `invoice_number` with a `lockForUpdate()` lookup inside the existing
  `DB::transaction()`, so concurrent requests for the same org can't compute the same next number.
  Added regression tests to `InvoiceControllerTest` covering the cross-org collision and the
  after-deletion reuse case.
- 2026-10-13: Restyled `resources/views/pdf/invoice.blade.php` for a more modern, polished "design
  agency" look (per the brand guidelines' palette, no copied external layout): a circular monogram
  logo mark + bold brand wordmark in the header band, a gradient accent color strip under the
  header, top-accented bordered "billed to"/currency cards, a refined items table, a gradient
  highlighted grand-total bar, and a closing "Thank you for working with us" line. No data/formatting
  changes — `MoneyFormatter`/currency logic untouched; verified via a manual view render plus the
  full `InvoiceControllerTest`/`PortalInvoiceControllerTest` suite (35 passed).
- 2026-10-13: Fixed the generated invoice PDF rendering money as a raw currency-code prefix (read
  as "always USD") by adding `App\Support\MoneyFormatter` — wraps PHP's `intl` `NumberFormatter` to
  render `Invoice::currency`'s real symbol/placement — and used it for every amount in
  `resources/views/pdf/invoice.blade.php` (item prices, line totals, subtotal, retainer, grand
  total). Also restyled the PDF for more visual character: a dark/orange gradient header band with
  a status pill, bordered "billed to"/currency cards, a dark items-table header with zebra rows, a
  highlighted grand-total row, and an accent-bordered notes callout — still per
  `.junie/brand-guidelines.md`'s palette, no copied external layout.
- 2026-09-27 (Phase 7 Step 7.5): Added `barryvdh/laravel-dompdf`, a branded
  `resources/views/pdf/invoice.blade.php` view (orange accent, near-black headings, items table with
  a billable/non-billable distinction, retainer + total breakdown — styled per
  `.junie/brand-guidelines.md`, not copied from any external reference layout), and
  `App\Actions\Invoices\GenerateInvoicePdfAction` (finalized-only, stores through the existing
  `AssetStorage`/`pdf_*` columns). Added `InvoiceController@generatePdf` +
  `POST /invoices/{invoice}/generate-pdf` (both route groups) and a `can.generate_pdf` flag. Added a
  "Generate branded PDF" button to `invoices/show.tsx` and a finalized-invoice note on
  `invoices/edit.tsx`'s manual upload form (kept in place as a one-off override, never removed).
- 2026-10-13: Line items can now be added/edited/deleted on an invoice even after it's been
  finalized (previously rejected with a `422`), so a user can correct a mistake and regenerate the
  PDF without starting over. `Invoice::refreshFinalizedAmount()` re-sums the items plus the
  already-snapshotted retainer and re-persists `Invoice::amount` whenever an item changes on an
  already-finalized invoice; `UpdateInvoiceAction` still blocks a *direct* `amount` edit once
  finalized. `InvoiceController@show`'s `can.manage_items` no longer excludes `is_finalized`.
  Confirmed (isolated test render + an `intl` sanity check) that `App\Support\MoneyFormatter`
  already renders each currency's real symbol correctly (e.g. EUR/GBP), and cleared the stale
  compiled-view cache that was still serving the pre-`MoneyFormatter` template during manual testing.
- 2026-09-27 (Phase 7 Step 7.3-7.4): Added `Invoice::is_finalized`/`retainer_amount_snapshot`
  columns and `FinalizeInvoiceAction` (sums items + client retainer, locks `amount`, snapshots the
  retainer used, flips `is_finalized`), `InvoicePolicy::finalize`, `InvoiceController@finalize` +
  `POST /invoices/{invoice}/finalize` (both route groups). `AddInvoiceItemAction`/
  `UpdateInvoiceItemAction`/`DeleteInvoiceItemAction` and `UpdateInvoiceAction` now also reject once
  `is_finalized`. Added the "Items" section to `invoices/show.tsx` (add/edit/delete items, live
  subtotal via page reload, Finalize button, read-only breakdown once finalized) per Step 7.4. PDF
  generation (Step 7.5) is still pending — see Known Gaps.
- 2026-09-26 (Phase 7 Step 7.1-7.2): Added `InvoiceItem` (model/migration/factory) with a computed
  `lineTotal()`, `Invoice::items()`, and line item CRUD restricted to `draft` invoices
  (`AddInvoiceItemAction`/`UpdateInvoiceItemAction`/`DeleteInvoiceItemAction`,
  `InvoiceItemPolicy`, `InvoiceItemController` + `invoices/{invoice}/items` and
  `invoice-items/{invoiceItem}` routes, registered in both the authenticated and `preview`
  middleware groups).
- 2026-09-20: The "Outstanding" stat card on the client show page's hero row now renders the total
  in compact notation (e.g. "£5K") via the new `formatCurrencyCompact()` helper in
  `resources/js/lib/currency.ts`, so large totals no longer overflow the card. The exact amount
  (`formatCurrency()`) is shown in a `Tooltip` on hover/focus.
- 2026-09-20: Invoices are no longer created/updated with a user-supplied `currency` — the
  `currency` field was dropped from `StoreInvoiceRequest`/`UpdateInvoiceRequest`, and
  `CreateInvoiceAction`/`UpdateInvoiceAction` now always set/preserve the organization's
  `default_currency` (see `settings.md`). Amount displays across the app (`invoices/show.tsx`,
  `invoices/edit.tsx`, `clients/show.tsx`, portal invoice pages) now format via
  `resources/js/lib/currency.ts`'s `formatCurrency()` using the shared `currency` Inertia prop.
- 2026-09-19: Added a dedicated invoice detail/edit page (CRUD): `invoices/{invoice}` (show),
  `invoices/{invoice}/edit` (edit), plus `PUT`/`DELETE` routes and a `POST
  /invoices/{invoice}/pdf` upload endpoint. New `pdf_*` columns on `invoices` (mirrors `BrandBrain`
  persona columns), `UpdateInvoiceAction`/`UploadInvoicePdfAction`/`DeleteInvoiceAction`,
  `InvoicePolicy::update`/`delete`, and `UpdateInvoiceRequest`/`UploadInvoicePdfRequest`. The
  client portal's read-only `portal/invoices/show.tsx` now also renders the uploaded PDF inline.
- 2026-09-16: The client show page's "Billing history" section was converted from a collapsible
  card to a "Billing" tab inside a `Tabs`/`TabsList`/`TabsContent` layout on
  `resources/js/pages/clients/show.tsx` — see `clients.md`'s changelog for the full redesign note.
- 2026-09-08: Added `GetMonthlyInvoiceTotalsAction`, consumed by `DashboardController` to power an
  org-scoped invoice revenue chart on the dashboard (Owner/Strategist only). See `.junie/modules/home.md`.
- 2026-09-08: Initial `Invoice` model/migration/enum, `InvoicePolicy`, Create/Send/MarkPaid actions,
  `InvoiceController` + routes, billing history UI on the client show page, `InvoiceFactory`/
  `InvoiceSeeder`, and Feature tests.
- 2026-09-08: Billing history card on the client show page is now collapsible (folded by default),
  toggled by clicking the section header.
- 2026-09-08: Fixed bug where the Send/Mark paid buttons remained visible (and clicking Mark paid
  silently failed with a `status` validation error) for invoices that were already `sent`/`paid`.
  `ClientController::transformInvoice` now also checks `invoice->status` before exposing
  `can.send`/`can.mark_paid`, instead of relying solely on the role-only `InvoicePolicy` checks.
- 2026-10-20: Phase M-1: ledger on `DataTable` with server-side sort (`InvoiceLedgerTest`); CSV export needs a recent password confirmation.

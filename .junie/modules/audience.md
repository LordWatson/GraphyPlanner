# Audience (Email List)

## Purpose
Phase GTM-2 of `.junie/plans/go-to-market.md` (closes **CG-6.1–6.3**). The creator's own email
list, on top of the surfaces that already collect addresses: the link in bio (CG-3) and the store
(CG-4). Three things:

- **Capture (GTM-2.1)** — an `email_capture` block on the bio page with **double opt-in**, buyers
  captured at checkout, and a pasted-CSV import that invites rather than assumes.
- **Automated emails (GTM-2.2)** — welcome, purchase follow-up, abandoned checkout. Queued,
  idempotent per contact + trigger, and every one carries an unsubscribe link.
- **Broadcasts (GTM-2.3)** — one email to a segment, written by the owner (optionally drafted by
  AI), sent by the owner. **Nothing but a human request can send one.**

The whole module is one rule: *consent is the person's, never ours*.

## Models / Tables
`2026_12_05_100100_create_audience_tables.php`:

- `AudienceContact` (`audience_contacts`): `org_id`, `client_id`, `email`, `name`,
  `source` (`AudienceSource`), `consent_at`, `unsubscribed_at`. Unique `(client_id, email)` — one
  person per brand, so every capture path finds the existing row instead of making a duplicate.
  Indexes `org_id`, `(client_id, consent_at)`. Carries `BelongsToOrganization`.
- `audience_sends` (**no model** — written with the query builder, like `bio_events`): `org_id`,
  `audience_contact_id`, `trigger`, `reference`, timestamps. Unique
  `(audience_contact_id, trigger, reference)` **is the idempotency lock**, claimed with
  `insertOrIgnore`. `reference` is `''` rather than null for a trigger that isn't tied to a row,
  because MySQL and SQLite both treat NULLs in a unique index as distinct and would let duplicates
  through. `created_at` is the sent time; there is no separate `sent_at`.
- `AudienceBroadcast` (`audience_broadcasts`): `org_id`, `client_id`, `created_by`, `subject`,
  `body`, `segment`, `product_id` (nullable, `nullOnDelete`), `sent_at`, `recipients`. `sent_at` is
  both the record of the send and the proof it happened once.

## Enums
- `App\Enums\AudienceSource`: `order`, `bio_capture`, `import`. Where they met the brand — never a
  licence to email them.
- `App\Enums\AudienceTrigger`: `welcome`, `purchase`, `abandoned`, with `requiresConsent()`. See
  **Business Rules** for why only `welcome` needs consent.
- `App\Enums\Feature::EmailAutomation` — the plan gate (Creator Pro; see `plans.md`). It gates
  *sending*, not capturing.
- `App\Enums\BioBlockType::EmailCapture` — switched on in `live()` by this phase.

## Actions / Services / Contracts
- `App\Actions\Audience\CaptureAudienceContactAction` — **the only way a contact is created.**
  Never duplicates, never downgrades consent, never resurrects an unsubscribed contact, and only
  records consent the person gave themselves.
- `App\Actions\Audience\ConfirmAudienceContactAction` — the double opt-in. Sets `consent_at` and
  sends the welcome **on that transition only**, so following the link twice sends one email.
- `App\Actions\Audience\UnsubscribeAudienceContactAction` — one click, idempotent, keeps the row.
- `App\Actions\Audience\ImportAudienceContactsAction` — pasted CSV (`email` or `email,name`), max
  500 rows, every new contact unconfirmed and invited. Bad lines are skipped, not fatal.
- `App\Actions\Audience\SendAudienceTriggerAction` — one automated email, at most once. Four
  refusals, each returning `false` rather than throwing (it runs from a queued job, where an
  exception retries forever and an upgrade prompt has nobody to show itself to): the plan
  (`allows()`, not `ensure()`), the unsubscribe, consent where the trigger needs it, and the
  `audience_sends` claim.
- `App\Actions\Audience\SendAudienceBroadcastAction` — takes the sending `User`. Uses `ensure()`
  (there is a person to show the prompt to), resolves the segment live, and refuses an empty one.
- `App\Actions\Audience\GetAudienceAction` — the `/audience` payload; the counts are one grouped
  query.
- `App\Jobs\SendAudienceTriggerJob` — runs a trigger later so its checks happen **at delivery, not
  at dispatch**. That is the whole reason it exists: the purchase follow-up is delayed a day, and
  somebody who unsubscribes in that day must not receive it.
- `App\Support\Audience\AudienceLinks` — the confirm and unsubscribe URLs, both signed routes.
- No new contract: there is one implementation of each, and the AI draft reuses the existing
  `WritingAssistant` seam (see **Gotchas**).

## Mail
- `AudienceConfirmMail` → `emails/audience-confirm.blade.php`. The only email sent to an
  unconfirmed address, so it carries no marketing and **no unsubscribe link** — there is nothing to
  unsubscribe from yet, and ignoring it is the answer.
- `AudienceMessageMail` → `emails/audience-message.blade.php`. Welcome, purchase follow-up,
  abandoned checkout **and** broadcasts: one mailable and one blade because it is one email (brand
  header, headline, body, at most one button), and because that is what guarantees every one of
  them has the unsubscribe link in its footer. Exactly one of `$trigger` or `$broadcast` is given.

Both are sent **from the creator's brand**, like the store receipt: the person signed up to hear
from them, not from Alima.

## Routes / Controllers
Public (inside `throttle:public-token`):
- `POST /@{handle}/subscribe` → `BioPageController@subscribe` — an `email_capture` sign-up.
  Honeypot (`website`) plus its own `throttle:6,1` on top of the group's. **The response is
  identical** whether the address was new, already on the list, already unsubscribed or caught by
  the honeypot, so the form can't be used to find out who is on somebody's list.

Public (inside `signed`):
- `GET /audience/confirm/{contact}` → `AudienceLinkController@confirm`
- `GET /audience/unsubscribe/{contact}` → `AudienceLinkController@unsubscribe`

Business shell (`workspace:business`; writes need the brand-**update** ability):
- `GET /audience` → `Business\AudienceController@index`
- `POST /audience/import`, `DELETE /audience/contacts/{contact}`
- `POST /audience/broadcasts` — **the only thing that sends a broadcast**
- `POST /audience/draft` — returns text as JSON and sends nothing

Frontend: `pages/business/audience.tsx`, `pages/public/audience.tsx`, the `EmailCapture` block in
`components/bio/bio-page-view.tsx` (rendered inert in the owner's preview via `isPreview`), and the
`audienceContact` kind in `lib/status.ts`. Nav: **Audience** is a sub-item of the **Brand** chapter
(prefix `/audience`) — no new chapter number, as CG-3.2 and CG-5.2 did for bio, kit and deals.

## Business Rules
- **Double opt-in, always.** A bio-page sign-up and an import both create a contact with
  `consent_at = null` and send a confirmation email. Following that link is the single moment
  consent exists. An import **never** marks consent, whatever the file claims.
- **A purchase is not consent to marketing.** A buyer is captured at *checkout* (not at
  fulfilment — an abandoned checkout needs a contact too) with `consent_at` set only if they ticked
  the marketing box on the form.
- **Transactional vs marketing.** `welcome` is marketing and needs confirmed consent. `purchase`
  and `abandoned` are about the person's **own** order or own unfinished checkout, so they go out
  without a marketing opt-in — otherwise two of the three triggers could never fire for anyone.
  Every trigger, transactional included, is stopped by an unsubscribe. A **broadcast** always needs
  confirmed consent. This is stricter than UK PECR, which would allow marketing similar products to
  a buyer on the soft opt-in: a buyer here never receives a broadcast until they confirm.
- **An unsubscribe outranks everything and is never cleared.** The row is kept rather than deleted,
  because a suppression an import can wipe is not a suppression. A later import, purchase or
  sign-up leaves it exactly as it is and sends nothing.
- **Sending is the plan gate, capturing isn't.** Refusing to record a buyer's address would lose
  the creator real data over a plan they can still upgrade to, so `/audience` renders without
  `Feature::EmailAutomation` (like `/bio`, `/store` and `/deals`) and shows the list either way.
- **Once, per contact per trigger per reference.** The `audience_sends` unique index is claimed
  *before* the mailable reaches the queue, deliberately: a duplicate email can't be taken back, a
  missing one can be re-sent.
- **The owner sends; AI never does.** `SendAudienceBroadcastAction` takes a `User` and is only
  reachable from an authenticated request. No job, command or schedule can originate a broadcast.
  The AI endpoint returns text and nothing else.
- **An unconfirmed or unsubscribed contact is in no segment**, so there is no way to compose a
  broadcast that reaches them.
- **No visitor data beyond the address and name.** No IP, no user agent, no open or click tracking.

## Tests
- `tests/Feature/Audience/AudienceTest.php` — a bio sign-up is unconfirmed and unmailable until the
  emailed link is followed, which sends the welcome exactly once; a sign-up with no visible block,
  or with the honeypot filled, records nothing and answers identically; unsubscribing is one click
  and survives a later import, purchase and sign-up; an import marks no consent, invites everyone
  and re-importing neither duplicates nor re-mails; a checkout captures the buyer and only the
  ticked box is consent; the purchase follow-up is dispatched with a delay and sends once however
  many times the job runs; an abandoned checkout is nudged and a completed one never is; the
  transactional triggers need no consent but an unsubscribe stops them; a plan without
  `EmailAutomation` sends nothing (and the human path gets the upgrade prompt); a broadcast reaches
  the segment and never the unconfirmed or unsubscribed; a Designer and a guest cannot send or
  import; the AI draft returns text, meters a credit and sends nothing; the page renders
  un-entitled; another brand's contact 404s and deleting a brand takes its list with it.

## Known Gaps / Open Questions
- **No open or click tracking, and no bounce or complaint handling.** Resend reports both over
  webhooks; nothing consumes them, so a hard-bouncing address stays on the list and keeps being
  mailed. This is the first thing to add if deliverability matters.
- **No scheduled or recurring broadcasts, no drip sequences and no A/B tests.** A broadcast is
  written and sent in one go. Stan's flows are a bigger feature than GTM-2 claims to be.
- **The import is pasted text, not a file upload** (`shortcut: upgrade when somebody asks`), capped
  at 500 rows, and there is no column mapping — `email` or `email,name` only.
- **The contact list shows the newest 200** with no search, filter or pagination
  (`GetAudienceAction::MAX_CONTACTS`).
- **The broadcast body is plain text**, rendered with `white-space: pre-line`. No rich text, no
  images, no per-contact personalisation beyond the greeting.
- **The AI draft writes the body only**, not the subject line — see **Gotchas**.
- **No per-plan sending limit.** `Feature::EmailAutomation` is on or off; a Creator Pro workspace
  can mail its whole list as often as it likes, which is an open cost question in the same shape as
  the agency AI-credit one in `plans.md`.
- **B-13.2 (export/deletion) still doesn't exist**, so a deletion is covered only by the cascade on
  `clients`. When B-13.2 lands it must list `audience_contacts`, `audience_sends` and
  `audience_broadcasts`.
- **A contact belongs to one brand**, so the same person on two of an agency's brands is two rows
  with two separate consents. That is correct for consent and wrong for a "do not contact" list
  across a roster; revisit if agencies start using this.

## Gotchas
- **The AI draft reuses the caption rewrite seam.** `AudienceController@draft` calls
  `RewriteCaptionAction` with `WritingTool::Expand` on the owner's rough notes, which is why it
  inherits the Brand Brain grounding, the banned-phrase guardrail and the AI credit metering for
  free (`shortcut: no email-specific provider method; upgrade to its own AiProvider method when
  subject lines or longer-form structure are wanted`). It writes the body; the owner writes the
  subject.
- **Capture inside the checkout is wrapped in try/catch.** A failure to record a contact must never
  cost the creator a sale — the contact can be captured again next time, the sale cannot.
- **`SendAudienceTriggerJob` exists for the delay, not for the queue.** The mailables are already
  `ShouldQueue`; deferring one of *those* would have frozen the consent decision at dispatch time.
- **The abandoned-checkout email needs no cancellation logic.** It is sent when
  `ExpireStaleOrdersAction` cancels the order, and a completed order never reaches that sweep.
- **The bio editor's preview renders the real form.** `BioPageView` takes `isPreview`, which
  disables submit — without it the owner would subscribe themselves while editing.

## Changelog
- 2026-11-07: **A broadcast is handed to one job instead of sent in a loop.** Sending used to be a
  `foreach` in the web request pushing one queued mail per contact — a 5,000-contact list meant
  5,000 sequential writes before the response, and a bigger one hit `max_execution_time` with no
  record of how far it got. New `SendAudienceBroadcastJob` (unique per broadcast) pages through the
  segment with `chunkById`, delays each chunk further than the last so the mail provider sees a
  stream rather than a burst, and records `sent_count` / `failed_count` / `last_contact_id` /
  `finished_at` as it goes — so progress is sayable and a retry resumes instead of re-sending the
  first chunks. Segment resolution moved to `App\Support\Audience\BroadcastRecipients` so the
  Action (which counts) and the job (which sends, minutes later) cannot drift. Consent is re-read at
  send time, not frozen when the owner pressed the button. Tests: `BroadcastScaleTest` (7). Audit
  finding 8.
- 2026-11-07: Phase GTM-2 (GTM-2.1–2.3) built, closing CG-6.1–6.3: `audience_contacts`,
  `audience_sends` and `audience_broadcasts`; the `email_capture` bio block with double opt-in;
  buyer capture at checkout with an explicit marketing opt-in; a pasted-CSV import that invites;
  the welcome, purchase-follow-up and abandoned-checkout triggers behind one idempotency index;
  signed confirm and unsubscribe links; and human-only broadcasts with an AI-drafted body.

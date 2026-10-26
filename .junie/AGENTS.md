# AGENTS.md — Context & Rules for Junie

This file is the persistent context Junie must read before working on this codebase. It defines the
project, the coding principles to follow on every change, and the documentation workflow that keeps
module knowledge current across sessions.

## Project

Graphy is a Laravel 13 + Inertia.js + React application for managing social media content across
clients, brands, campaigns, and scheduled posts, with a pluggable publish-adapter architecture
(Upload-Post first) and optional AI assistance (DeepSeek, behind the `AiProvider` contract). MySQL 8 is
the database (tests run on in-memory SQLite, so SQL must work on both); PHPUnit (class-based tests extending `Tests\TestCase`) is the test framework. The full
phased build plan lives at `.junie/plans/graphy-development-steps.md` — read
it to understand feature scope, data model, and acceptance criteria before implementing a step.

## Core Coding Principles

These principles apply to every change, regardless of which phase/step of the plan is being worked on.

1. **Controllers stay thin.**
   - Controllers only: validate input (via Form Requests), call an Action/Service, and return a
     response (Inertia render or redirect/JSON).
   - No business logic, no query building beyond simple model lookups, no direct external API calls
     inside a controller method.

2. **Features must be tested.**
   - Every new feature/bugfix gets PHPUnit coverage (Feature tests for HTTP/behavior, Unit tests for
     isolated logic in Actions/Services/models).
   - Write tests for both the happy path and the relevant edge cases/rejections (e.g. invalid state
     transitions, unauthorized roles, validation failures).
   - For bug fixes, add a regression test that fails before the fix and passes after.

3. **Use Action and Service classes where suitable.**
   - `App\Actions\*`: a single, named, invokable unit of business logic (e.g. `CreateClientAction`,
     `TransitionPostStatusAction`). Prefer Actions for one discrete operation triggered by a
     controller/job/command.
   - `App\Services\*`: cohesive logic spanning multiple operations or reused across several Actions/
     controllers (e.g. `ClientHealthService`, a vendor API client wrapper).
   - Controllers and jobs should depend on Actions/Services, never reimplement their logic inline.

4. **Bind classes with interfaces where suitable.**
   - Anything with more than one implementation, or anything that must be swappable/mockable in tests
     (e.g. the `PublishAdapter` contract for Upload-Post and future adapters), gets an interface in
     `App\Contracts\*` (or module-appropriate namespace) bound in a Service Provider.
   - Prefer constructor-injecting the interface, not the concrete class, in Actions/Services/Controllers.

5. **Wrap CRUD/multi-step logic in DB transactions.**
   - Any Action/Service that performs multiple related writes (create + related rows, status
     transition + activity log entry, etc.) must be wrapped in `DB::transaction()` so partial failures
     don't leave inconsistent state.
   - Simple single-row reads or single-statement writes don't need an explicit transaction.

6. **Add logging for easier bug navigation.**
   - Log at meaningful boundaries: external API calls (adapters, AI provider), webhook/queue job
     start/success/failure, state transitions, and caught exceptions.
   - Use appropriate log levels (`info` for normal flow milestones, `warning`/`error` for failures) and
     include contextual identifiers (post id, account id, org id) in the log context array.
   - Never log secrets (API keys, tokens, raw webhook signatures).

## Module Documentation Workflow

Every module of the app has a corresponding Markdown file under `.junie/modules/` (e.g.
`clients.md`, `social-accounts.md`, `posts.md`, `publishing.md`). Each file records: purpose, key
models/tables, Actions/Services/Contracts involved, routes/controllers, important business rules, and
open questions/known gaps.

**Whenever Junie touches a module (adds/changes a model, migration, Action, Service, controller,
route, or test related to it), Junie must update that module's `.md` file in the same change** so the
documentation never drifts from the code. If a module doesn't have a file yet, create one following
the existing template/style before finishing the task.

See `.junie/modules/README.md` for the list of modules and the file template.

## Frontend Brand Guidelines

Before building or restyling any frontend page or component, read `.junie/brand-guidelines.md`. It
defines the visual/interaction language (clean, modern, agency-appropriate) for the Inertia/React
frontend, including color usage, typography, spacing, and component patterns.

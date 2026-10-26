---
name: sync-module-docs
description: Update .junie/modules/*.md (and the README status table / plan step marker) to reflect code changes in the current diff. Required by AGENTS.md whenever a module's code changes.
---

# Sync module docs

1. Get the change set: `git status --short` and `git diff HEAD --stat` (plus untracked files).
2. Map each changed path to a module using `.junie/modules/README.md` (e.g. `app/Actions/Invoices/*`,
   `InvoiceController`, `invoices` migrations → `billing.md`; `Services/Publishing/*` → `publishing.md`;
   `Services/AI/*`, `Actions/AI/*` → `ai.md`; `pages/portal/*` → `client-portal.md`).
3. For each affected module file, edit only the relevant sections of the template:
   Purpose · Models / Tables · Actions / Services / Contracts · Routes / Controllers · Business Rules ·
   Tests · Known Gaps / Open Questions · Changelog.
   - Name real classes, routes (`METHOD /path → Controller@method`) and test files — verify each
     exists; never document something that isn't in the code.
   - Append a Changelog line: `- YYYY-MM-DD: <what changed and why>` (use today's date).
   - Remove gaps that the change closed; add new ones that it deferred.
4. New module with no file → create it from the template in `.junie/modules/README.md` and add a row
   to the README table.
5. If a plan step was completed, update its status in the README table and add ` ✅ Done` to the
   `### Step X.Y` heading in `.junie/plans/graphy-development-steps.md`.
6. Keep the existing tone/density of the file; don't rewrite unrelated sections.

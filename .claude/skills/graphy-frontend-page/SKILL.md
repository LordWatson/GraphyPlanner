---
name: graphy-frontend-page
description: Build or restyle an Inertia/React page or component in resources/js following Graphy's brand guidelines, shadcn primitives, and Wayfinder routing. Use for any UI work.
---

# Graphy frontend page

## Before writing
- Read `.junie/brand-guidelines.md` (Alima: teal sidebar frame, Cloud Cream workspace, Poppins
  SemiBold headlines + DM Mono eyebrows, Ink main buttons / teal brand actions, Signal Orange as
  decoration only, status = colour **and** label/icon).
- Look at a sibling page in `resources/js/pages/<area>/` and copy its structure (e.g.
  `pages/clients/index.tsx`). Check the controller's `Inertia::render('<path>', [...props])` for
  exact prop shapes.

## Rules
- Page file path must match the controller's render name (`clients/index` →
  `resources/js/pages/clients/index.tsx`), default export.
- Compose from `@/components/ui/*` (shadcn: button, badge, card, dialog, select, tabs, sheet, tooltip,
  …). Don't hand-roll primitives; add a new shadcn component only if none fits.
- Semantic Tailwind tokens only (`bg-card`, `text-muted-foreground`, `border-border`, `bg-primary`) —
  no raw hex/`orange-500`. Tokens live in `resources/css/app.css`.
- Icons: `lucide-react` only.
- URLs: import from Wayfinder (`@/routes/<name>` for named routes, `@/actions/App/Http/Controllers/...`
  for controller actions). Never hard-code paths. After route changes run
  `php artisan wayfinder:generate`.
- Forms: Inertia `useForm`/`<Form>`, show errors with `InputError`; flash via `use-flash-toast`.
- Gate UI on a `can` prop from the controller — never re-derive permissions client-side.
- Type props explicitly; no `any`.
- Charts: recharts (already installed).
- Verify light **and** dark mode; keep layouts usable at mobile widths.

## Done
`npm run check` and `npm run types:check` pass; if you can, run the app (`composer dev`) and look at it.

---
paths:
  - 'resources/views/**'
---

# Views

## Every new screen reuses the existing designs
- Every new screen reuses the designs that already exist, so the whole app looks and behaves alike. Before building a page, find the closest existing one (the super admin console in `resources/views/pages/admin/` is the reference) and copy its layout, spacing, typography, colours, icons and patterns for lists, tables, filters, detail pages, modals, empty states, badges and flash messages.
- Reuse the shared layouts (`resources/views/layouts/`), components (`resources/views/components/`, `resources/views/flux/`) and partials rather than writing new markup for something that already exists. When two screens need the same piece, extract it into a component instead of copying it.
- Do not introduce a new visual style, colour, or interaction pattern for one screen. If none of the existing designs fit, ask first.

## Onboarding forms are step-by-step wizards
- Build onboarding flows as a step-by-step wizard modeled on `resources/views/pages/admin/tenants/⚡create.blade.php`; copy its structure rather than starting from a plain form.
- One card, one question per step: an icon, the question as the heading, a one-line subheading, then only that step's fields. A footer bar holds `Go back` (hidden on step 1) and `Continue`, which reads as the final action on the last step.
- Responsive shape: a portrait single-column card on mobile; from `lg` a landscape card centred in the viewport, with the icon, question and a numbered step list in a tinted left panel and the fields on the right.
- `Continue` validates only the current step's fields, from the same rules the final action uses (expose them as a public `rules()` on the action). The final submit revalidates everything and, on failure, jumps back to the step holding the first error.

---
version: 1
slug: "resources-views-pages-admin-dashboard-blade-php"
primary_target: "resources/views/pages/admin/⚡dashboard.blade.php"
related_targets: ["resources/views/layouts/app/navbar.blade.php","resources/views/components/app-chrome.blade.php"]
---

# Surface brief: super admin dashboard

Scope: the platform console's dashboard (`/admin`), the first surface of DevvStack's new identity; its shell (top bar, band, navigation) is shared by every console. Mode: Operate. Audience: the super admin at a desk. Task: see the platform's state and act on what needs a decision. Constraints: Flux UI and Livewire, light and dark themes, WCAG AA, every existing action and test kept.

## Direction contract

THESIS: The platform reads like a travel document an inspector trusts: every agency's standing is an entry stamp, dated and inked. It refuses the white-card SaaS dashboard with a blue accent and the dark neon console.

OWN-WORLD: Passport-cover maroon owns the top bar; a guilloché security band (fine interference rings on mint fading to peach) sits under it; navy serif titles (Source Serif 4) over a workhorse sans (Manrope) with tabular figures; near-white ground, hairline borders; status colours as stamp inks (green, amber, red, violet) inside double-ruled, slightly rotated entry stamps that carry a date.

STORY: The super admin sees in one glance how many agencies run, what money is overdue, still owed and received, which agency stands where, and what waits for a decision; acts on each waiting item from its row.

FIRST VIEWPORT: Maroon top bar (DevvStack wordmark, top-level navigation: Dashboard, Agencies, Billing, Requests with count, Audit log; user at the right) over the guilloché band; page title "Dashboard" in navy serif with its one-line purpose and "Onboard an agency" at the right; four figures (Agencies, Overdue, Still owed, Received this month); below, the agencies table with entry stamps (left, wider) beside the "Needs a decision" queue with an action per row (right).

FORM: Passport and entry stamps, Impeccable's pick (position 1 on the ordered list), seed:88cf62c0068c49f6.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

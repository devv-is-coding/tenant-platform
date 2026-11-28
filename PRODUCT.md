# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Super admin (platform operator):** runs the DevvStack platform from the central console.
  Onboards travel agencies, puts them on plans, activates, pauses, suspends or closes them, records
  platform payments, decides restore and plan requests, and reads the platform audit log. Works at
  a desk on a computer.
- **Agency admin:** runs one travel agency's account on the agency's own domain. Manages the team,
  the agency's settings and its customer app's branding, the agency's plan and billing with the
  platform, and asks the platform to restore deleted records. Works at a desk on a computer.
- **Agency manager:** decides inside one agency: approves listings, bookings and refunds, moderates
  reviews, releases customer transfers. Works at a desk on a computer.
- **Agency staff:** day-to-day agency work: the catalog (tours, visas, hotel rooms), inventory and
  availability, the bookings desk and cash payments, QR check-in. Works mostly at a desk on a
  computer; staff screens are desktop-first and must still work on a phone.
- **Customers** never use this web app: they book through their agency's own branded Flutter app,
  over the agency's REST API. Customer-facing design is out of scope here.

## Product Purpose

DevvStack is a multi-tenant SaaS for independent travel and tour agencies selling tours, visas and
hotel rooms. Each agency runs on its own isolated database and domain, with its own staff and its
own branded customer app, while the platform keeps oversight of them all. It reworks the
**360 Degrees** capstone (one travel agency) into a platform: the capstone's business logic is
ported deliberately and its defects are fixed deliberately, each with a named regression test.

The project is a **portfolio showcase**: it exists to demonstrate that a working system can be
taken apart, understood and rebuilt correctly, built as if it were a real product. Success is a
platform that holds up to a reviewer's scrutiny in its code, its tests and its interface.

## Positioning

One platform, every agency its own: a database, a domain, a staff and a customer app per agency,
with the platform deciding only whether an agency operates, never how it runs its business.

## Operating Context

- Two web consoles on one Laravel app: the platform console on the central host
  (`/admin`), and each agency's console on the agency's own domain (`{agency}.localhost` locally).
- Agencies move through statuses (Setting up, Pending, Active, Paused, Suspended, Closed); a
  paused, suspended or closed agency is read-only, and a closed one hides customers and money.
- Plans are feature tiers (Basic, Advanced, Pro) the super admin prices; screens refuse a feature
  the agency's tier does not include, but never hide what the agency already has.
- Money is in the agency's currency (Philippine peso for the seeded agencies); platform billing
  has invoices, payments, card payments through Stripe and auto-pay.
- Seeded demo agencies are in Negros Oriental, Philippines (Dumaguete, Tanjay, Zamboanguita,
  Dauin, Bayawan).

## Capabilities and Constraints

- Stack: Laravel 13, PHP 8.4, Livewire 4 (single-file components), Flux UI 2, Tailwind CSS 4,
  Fortify (sign-in, two-factor, passkeys). The web UI is built with Flux components.
- Light and dark themes are both supported (Appearance setting: light, dark, system).
- Terminology: say **agency**, not tenant, in the interface; roles are Super admin, Admin,
  Manager, Staff, Customer.
- Every destructive action asks for a reason and deletes softly; restore exists for deleted
  records.
- English only today.
- Manager and staff screens (`TODO.md` TM and TS items) are not built yet.

## Brand Commitments

- The platform's name is **DevvStack** (user, 2026-11-25). It has no real logo yet: today's "DS"
  mark is a placeholder.
- Each agency has its own brand (customer app name, primary and accent colours, logo, support
  contacts) set on its App branding page; that brand belongs to the agency, not to DevvStack.
- The user chose a **new visual identity** for every console (2026-11-25), not a refinement of
  today's look.

## Evidence on Hand

- Seeded demo data: five agencies with staff, customers, catalog, bookings and platform billing
  (`database/seeders/`).
- No real customers, testimonials, press or metrics exist; none may be invented.
- No photography or illustration assets exist in the project.

## Product Principles

1. One design from super admin down to staff: every role's screens are built from the same parts
   and read as one product.
2. Operators first: consoles are working tools, read at a desk for long stretches; clarity,
   density and speed beat decoration there.
3. The agency owns its brand: an agency's portal and app wear the agency's identity; DevvStack
   stays in the background on the agency's side.
4. Say what happens: actions explain their consequences before they happen, in plain language.
5. Built to be reviewed: as a portfolio piece, every screen should hold up to scrutiny as if it
   shipped to real agencies.

## Accessibility & Inclusion

- WCAG 2.1 AA contrast in both light and dark themes.
- Full keyboard use of the consoles (the navigation bar already supports arrow keys, Home and End).
- Reduced motion respected.

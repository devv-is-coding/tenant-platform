---
name: DevvStack
description: The platform and agency consoles, set like a travel document an inspector trusts.
colors:
  passport: "#5b1626"
  passport-deep: "#44101c"
  ink: "#1d2b4f"
  ground: "#fbfbf9"
  paper: "#fbfbf9"
  mint: "#e3f1e8"
  peach: "#f7e4da"
  stamp-active: "#1f7a4d"
  stamp-pending: "#9a5b00"
  stamp-paused: "#1d5b94"
  stamp-suspended: "#b3261e"
  stamp-closed: "#5d6377"
  stamp-setting-up: "#4b3a8c"
  neutral-50: "#f7f8fa"
  neutral-200: "#dde1e8"
  neutral-500: "#646b80"
  neutral-800: "#222939"
  neutral-950: "#0d1220"
typography:
  headline:
    fontFamily: "Source Serif 4, ui-serif, Georgia, serif"
    fontSize: "1.875rem"
    fontWeight: 600
    lineHeight: 1.2
    letterSpacing: "-0.01em"
  title:
    fontFamily: "Source Serif 4, ui-serif, Georgia, serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.35
  figure:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.875rem"
    fontWeight: 700
    fontFeature: "tnum"
  body:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.9375rem"
    fontWeight: 400
    lineHeight: 1.6
    fontFeature: "tnum"
  label:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 600
    letterSpacing: "0.025em"
  stamp:
    fontFamily: "Manrope, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.6875rem"
    fontWeight: 800
    letterSpacing: "0.08em"
rounded:
  stamp: "6px"
  control: "8px"
  card: "12px"
spacing:
  gutter: "24px"
  gutter-lg: "32px"
  band: "28px"
components:
  button-primary:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.paper}"
    rounded: "{rounded.control}"
  top-bar:
    backgroundColor: "{colors.passport}"
    textColor: "{colors.paper}"
    height: "56px"
  entry-stamp:
    textColor: "{colors.stamp-active}"
    typography: "{typography.stamp}"
    rounded: "{rounded.stamp}"
    padding: "2px 8px"
  card:
    backgroundColor: "#ffffff"
    rounded: "{rounded.card}"
---

# Design System: DevvStack

## Overview

**Creative North Star: "The Inspector's Passport"**

Every DevvStack console reads like a travel document an inspector trusts. The cover is passport maroon and owns the top bar; under it runs a security band of fine interference rings, captioned with whose document this is (the platform console, or the agency's own name). Pages are plain paper with navy serif titles. The one signature move is the entry stamp: every agency's standing is inked like an immigration stamp, dated, in its status's own colour. Everything else stays quiet, because these are working screens read at a desk for long stretches.

The world lends type, palette, density and the stamp; it never supplies layout, navigation or controls, which stay the standard Flux components every operator already knows. Light and dark themes are both first-class: in dark, the paper turns night navy, the band dims, and the inks brighten to stay legible.

**Key Characteristics:**
- Passport-maroon top bar with a captioned guilloche security band, on every console.
- Navy serif titles over a workhorse sans with tabular figures everywhere.
- Status shown only as dated entry stamps in six inks; colour is otherwise rare.
- Neutrals tinted toward the passport's navy, so every Flux part wears the identity.
- Every daily destination on the top bar; counts shown beside what waits.

## Colors

A restrained paper-and-ink palette: one cover colour, one ink, a pale security tint, and six stamp inks that mean status and nothing else.

### Primary
- **Passport Maroon** (passport): the top bar, the logo's seal, and nothing else. Its deeper shade (passport-deep) is the bar's shadow line.
- **Intaglio Navy** (ink): titles in light mode, the primary button, links' focus rings, the caret and form accents.

### Secondary
- **Security Mint and Peach** (mint, peach): the band's two-tone ground under the guilloche rings, and text selection. Never a fill for content.

### Tertiary
- **Stamp inks**: Active green (stamp-active), Pending amber (stamp-pending), Paused blue (stamp-paused), Suspended red (stamp-suspended), Closed grey (stamp-closed), Setting-up violet (stamp-setting-up). Overdue money is set in the Suspended red.

### Neutral
- **Paper** (ground): the page ground in light mode; night navy (`#0f1424`) in dark.
- **Navy-tinted greys** (neutral-50 to neutral-950): borders, secondary text, table rules and dark surfaces. They replace Tailwind's zinc scale, so Flux components pick them up unchanged.

### Named Rules
**The Ink Means Status Rule.** A stamp ink appears only where it states an agency's standing (or overdue money in red). Nothing decorative is green, amber, blue, red or violet.

**The One Cover Rule.** Maroon covers the top bar and the logo seal. It never fills a button, a card or a badge.

## Typography

**Display Font:** Source Serif 4 (with ui-serif, Georgia)
**Body Font:** Manrope (with ui-sans-serif, system-ui)

**Character:** A printed document's serif for what a page is called, and a clear, slightly humanist sans for everything an operator reads and types. Both self-hosted.

### Hierarchy
- **Headline** (600, 30px, 1.2): page titles (`h1`), in navy serif.
- **Title** (600, 18px, 1.35): section and card headings (`h2`), serif.
- **Figure** (700, 30px, tabular): the dashboard's headline figures.
- **Body** (400, 15px, 1.6): paragraphs and table text, `text-wrap: pretty`.
- **Label** (600, 14px, uppercase, 0.025em): figure labels and the queue's group labels.
- **Stamp** (800, 11px, uppercase, 0.08em; its date 10px): entry stamps.

### Named Rules
**The Tabular Figures Rule.** Every number lines up: the body sets `tabular-nums`, so money and counts align in every table and figure.

**The Working Scale Rule.** Consoles use the scale above. The old 56/48/32px marketing scale is retired; public pages get their own treatment.

## Layout

One centred column up to 115rem wide, with a 24px gutter (32px from `lg`). The top bar and its 28px band share that column's left edge. Dashboards stack: a row of four figures, then a wide table beside a narrower queue (1.35 : 1 from `lg`, one column below), then three summary cards, then two, then a full-width activity table. Grid columns use `minmax(0, …)` so wide tables scroll inside their card and the page never scrolls sideways at 390px.

## Elevation & Depth

Flat, layered by hairlines and tint rather than shadow: cards are white (dark: one step up from night navy) with a 1px neutral border, and hover only darkens that border. The top bar is the one solid mass. No decorative shadows.

### Named Rules
**The Paper Stays Flat Rule.** Depth is a border, never a shadow. A raised card is a mistake.

## Shapes

Gently rounded containers (12px cards, 8px controls) against the stamp's tighter 6px corner and its double rule. The stamp alone is turned (-3°), easing to -1° on its row's hover when motion is allowed; nothing else rotates.

## Components

### Buttons
- **Shape:** gently rounded (8px).
- **Primary:** filled navy with paper text (dark mode inverts to pale ink on navy text).
- **Outline:** neutral border, used for "Open" and secondary moves in lists.
- **Focus:** a 2px outline in the accent, offset 2px.

### Top bar and navigation
- **Style:** passport maroon, white wordmark beside the logo's paper tile (the e-passport seal in maroon). Navigation items are white at 78%, full white with a faint white wash when hovered, open or current; the current item carries a white underline.
- **Destinations:** every daily destination is top-level (super admin: Dashboard, Agencies, Billing, Requests, Audit log); a white pill shows how many items wait (Requests).
- **Band:** 28px, under the bar, captioned in tracked navy capitals with whose document this is.
- **Mobile:** a sidebar list with the same sections; counts read as "(N waiting)".

### Cards / Containers
- **Corner Style:** 12px.
- **Background:** white on paper (dark: one step above the night ground).
- **Border:** 1px neutral-200 (dark neutral-700).
- **Internal Padding:** 20px; table cards run edge to edge with a 20px inset on first and last cells.

### Inputs / Fields
- **Style:** Flux's own, on the navy-tinted neutrals.
- **Placeholder:** neutral-500 (neutral-400 in dark), which passes 4.5:1.
- **Focus:** 2px accent ring with offset.

### Entry stamp (signature component)
An agency's standing as an immigration stamp: its status in uppercase inside a 3px double rule, in its status's ink, turned -3°, with "Since <date>" beneath when the date is known. Used wherever an agency's status appears (dashboard, directory, status counts). `x-entry-stamp`.

### Attention row
One item waiting on a decision: an optional group label (first row of each kind), a bold title, a one-line detail, and a button to where it is decided (primary for decisions, outline to open). `x-attention-row`.

## Do's and Don'ts

### Do:
- **Do** show every agency status as an entry stamp, never a plain badge.
- **Do** keep colour for meaning: stamp inks for status, red for overdue money, navy for the primary action.
- **Do** set titles in Source Serif 4 and everything else in Manrope with tabular figures.
- **Do** check both themes: every ink and placeholder must pass AA (4.5:1) in light and dark.
- **Do** caption the band with whose console it is (the platform, or the agency's name).

### Don't:
- **Don't** fill buttons, cards or badges with passport maroon.
- **Don't** add shadows for depth; use a hairline border.
- **Don't** rotate or stamp anything except an agency's standing.
- **Don't** bring back the 56/48px headline scale inside a console.

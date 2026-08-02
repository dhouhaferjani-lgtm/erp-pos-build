# Ticket: TopBar user-menu + language dropdowns unreachable by real mouse clicks (stacking-context defect)

From the W1a money-campaign leg (2026-08-01, docs/sessions/MONEY-CAMPAIGN-RESULTS.md AUTH-09 +
MTP-I18N-04a — two independent live repros; both specs currently assert the DEFECT as tripwires).
Was never ticketed; filed now per the 2026-08-02 full-E2E plan review (F-1).

**Defect.** `TopBar.tsx`'s user-menu and language dropdowns are `position:absolute` with no
`z-index`, in a sibling that precedes `DashboardLayout`'s `<main className="relative ...">`
(DashboardLayout.tsx:57 — `relative` added deliberately for a scroll-clipping reason per its own
comment). Two positioned siblings with `z-index:auto` stack in DOM order → `<main>` wins and
swallows pointer events over the dropdown region (~everything below 64px). Real users cannot
reliably reach Settings / Sign out / language switch on any page tall enough for `<main>` to
extend under the header — effectively everywhere.

**Fix direction.** `z-index` on the dropdown containers (or lift TopBar into a stacking context
above `<main>`); verify the DashboardLayout scroll-clipping reason still holds. P1 usability.

**On fix:** flip AUTH-09 + MTP-I18N-04a from tripwire (asserting interception) to asserting the
click works; helpers.ts `logout()` can drop its direct-POST workaround (keep as fallback).

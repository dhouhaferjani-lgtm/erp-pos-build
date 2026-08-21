# X-report discloses cash takings under blind cash count

**Opened** 2026-08-21 · lane `fix/pos-mocked-manager-screens` (POS manager screens)
**Status** OPEN — pre-existing, NOT fixed in that lane (out of scope, deliberately untouched)
**Related** LEDGER row **B-13** (owner QUESTION recorded, no acceptance) · SV-10 blind cash count

## What

The blind cash count regime (`require_blind_cash_count`) exists so the person
counting the drawer cannot see what it is supposed to hold.
`CashReconciliationSection` enforces that inside the closure flow, and
`EndOfDayPreviewModal` extends the concealment to the surrounding money cards.

The **X report** does not participate. `Header.handleXReport` →
`generateXReport` → `XReportModal` renders per-tender takings (including CASH)
with no policy gate, and the X report is reachable from the Header Reports menu
by **any** operator — it is not manager-gated at all.

Cash takings + the opening float are the two terms of the drawer expectation, so
an operator who can read the X report can reconstruct what the blind count is
concealing.

## Why it was not fixed here

The lane that found it (`fix/pos-mocked-manager-screens`) was scoped to
replacing mocked data on `/shift` and `/reports`. It fixed those two surfaces:

- `/shift` conceals every drawer figure under `resolveCashDisclosure`
  (fail-closed) — `apps/pos/src/lib/offline/cashDisclosurePolicy.ts`.
- `/reports` conceals physical-tender rows whenever a shift is OPEN under
  conceal policy.

Touching the X report means changing a **fiscal-event-emitting** path
(`generateXReport` appends an `X_REPORT` event) and deciding whether the report
itself should become manager-gated. That is a separate decision with its own
blast radius, and B-13 is unruled.

## Two more disclosure surfaces in the same family

1. **`Header` shift badge tooltip** — `apps/pos/src/components/Header.tsx:652`
   renders `title={t('shift.opening', { amount: shift.opening_cash })}` on
   **every route**, ungated. This is the *other* term of the expectation.
   Not fixed in-lane because gating it is **not** a one-liner: `Header` only
   resolves the fraud policy when the EOD modal opens, so gating the tooltip
   needs the policy resolved at Header mount — a policy fetch on every app
   start, on every route. That deserves its own design (cache-first? resolve
   lazily on hover?).
   Mitigating: opening float **alone** is not the secret — the secret is
   *expected* cash. It only matters combined with cash takings, which `/shift`
   and `/reports` now conceal and the X report still does not.

2. **`/reports` residual arithmetic derivability** — with the physical-tender
   rows concealed, the net headline and the visible non-cash rows still allow
   `cash ≈ headline − Σ(visible non-cash)`. The bars are refund-signed and the
   headline is net-of-refunds, so post-O-28 the two bases reconcile exactly
   (asserted in `ReportsPage.test.tsx` → "reconciles: the tender bars sum to the
   net headline"), which is precisely what makes the subtraction work.
   Closing it fully means concealing the headline too whenever a shift is open
   under blind mode — which makes `/reports` useless during trading hours.
   **Needs an owner ruling, not a unilateral fix.**

## Ask

Owner ruling on B-13, scoped to: does the blind cash count regime bind
(a) the X report, (b) the Header opening-float tooltip, (c) the `/reports`
headline while a shift is open? Then one lane to implement whatever is ruled.

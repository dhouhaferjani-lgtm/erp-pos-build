# Receipt reporting terminal-audit follow-ups

**Raised by:** parent terminal audit, frontend-conventions and fiscal lenses.
**Status:** OPEN. These are non-blocking follow-ups recorded without implementation in
the Phase 1.2 receipt-reporting lane.

## 1 — Training-row tint loses to hover styling

Training rows carry a background tint, but the shared hover treatment can override it.
Choose a combined training-plus-hover token treatment and lock it with a focused visual
or class-contract test so the training distinction survives pointer interaction.

## 2 — Refund destinations need a complete i18n fallback contract

The destination translation map covers only `cash`, and dynamic lookups provide no
`defaultValue`. Define the supported destination vocabulary in both EN and FR and render
unknown or legacy values through an explicit translated fallback instead of exposing an
i18n key.

## 3 — Per-terminal capability banners form a signal wall

The refund register renders one capability banner per terminal. For companies with many
terminals this overwhelms the historical register. Explore a summary-first treatment,
including the proposed “collapse all acknowledged” behavior, while preserving direct
access to each terminal's reason and action state.

## 4 — Register columns are inconsistent

The receipt and refund registers do not use the Location column consistently, and the
sales register can carry an effectively empty Type column. Decide one cross-register
column contract, including whether the Type column is conditional when every visible row
is an ordinary live sale.

## 5 — `alertCount` does not follow the locale plural-suffix convention

Replace the single interpolated alert-count key with the repository's plural-aware
suffix convention in every supported locale, then cover singular and plural rendering.

## 6 — Cashier filter options are not training-filtered

The receipt list excludes training rows by default, but its cashier option source can
still be populated only by training receipts. Align option eligibility with the active
training axis, or document and visibly communicate a deliberate wider option universe.

## 7 — `posted_at` has two serialization shapes

The list contract emits microsecond UTC (`Y-m-d\\TH:i:s.u\\Z`), while detail emits an
ISO timestamp with an offset. Pick one canonical wire representation for receipt
timestamps, migrate both DTO surfaces together, regenerate shared types if necessary,
and lock the exact shape in list and detail tests.

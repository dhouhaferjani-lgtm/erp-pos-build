# Handoff — Outbound (Supplier-Direction) Instruments

**Phase:** Treasury Phase ④ — Expense Depth
**Date:** 2026-07-13
**Status:** Deferred to a dedicated outbound-instruments phase

## Deferred scope

> **G20 outbound instrument lifecycle** → dedicated handoff at phase end, fold into / precede Phase ⑤ bank import. Includes: `ChecksToPay`/`EffetsPayable` purposes + seeding + brownfield backfill, direction-aware `PaymentController` deferred-supplier rework, outbound clearing action, outbound maturity alerts, reconcile-check review, expense-pay-by-instrument UI.

Phase ④ ships direction guards only. Outbound instruments can still be registered, edited while received, received through the existing lifecycle, and cancelled. The inbound collection lifecycle actions—custody transfer, deposit, clear, bounce, and remittance-line admission—reject `direction = outbound` with the canonical domain error. Inbound instruments retain the existing deposit and clear flow.

## Follow-up design checklist

- Define and seed `ChecksToPay` and `EffetsPayable` account purposes, including brownfield account backfill and chart verification.
- Rework deferred-supplier payment creation and `PaymentController` posting so supplier-direction instruments do not immediately use the inbound collection accounts or cash movement path.
- Add the outbound clearing/payment action and its journal/movement semantics, with idempotency and reconcile coverage.
- Add outbound maturity alerts and reconcile checks for outstanding supplier instruments.
- Add expense pay-by-instrument workflow and UI after the accounting contract is approved.

Until that phase lands, do not use an Outbound instrument as a collection instrument or remittance line. Do not infer payable-instrument accounting from the existing inbound collection accounts.

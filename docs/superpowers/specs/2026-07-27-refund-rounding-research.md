# Refund Cash-Rounding — Industry Research (2026-07-27)

Owner-requested research backing the §8.2 decision in `2026-07-27-pos-cash-rounding-tolerance-design.md`. Two Sonnet web-research agents: (A) regulation/jurisdictions, (B) POS vendor implementations. Decision taken: **adopt the industry pattern — independent rounding of the cash payout at refund time** (neutral nearest-D; no unwinding; partial refunds round their own amount; VAT exact; delta symmetric to the rounding accounts).

## Consensus (both lanes converge)

1. **Rounding is a property of the cash tender leg at the moment cash changes hands** — a refund payout gets an independent rounding pass with the same function. NO jurisdiction or vendor reconstructs/reverses the original sale's adjustment.
2. **Partial refunds round independently on their own computed amount.** Shopify/Canada worked example: $5.02 cash refund → $5.00 (adjustment −0.02, unrelated in sign/magnitude to the sale's adjustment). Full refunds naturally reproduce the originally-collected rounded amount (same base → same rounding).
3. **VAT/tax is always computed and reported on the exact pre-rounding amounts** (Belgium FPS explicit; CRA; Ireland). Rounding is a pure cash-settlement adjustment.
4. **Cash-only** — card/gift-card/store-credit refunds are never rounded (Shopify explicit: "Refunds to a gift card aren't rounded").
5. **The delta goes to a dedicated, explicit line/account** (Odoo dual profit/loss accounts; Lightspeed "Rounding Adjustment" pseudo-tender + Xero expense mapping; Swiss SAP `DRD1` / Sage `SWIRND` document-level rounding-difference accounts) — symmetric on credit notes.

## Key primary sources

- **Belgium FPS Economy (regulatory, explicit on refunds):** "Even if at the moment of purchase the amount paid was not subject to rounding, you will have to round the amount refunded in cash to the nearest 0 or 5 cents. … Any amount returned to the customer will always be rounded." VAT on the pre-rounding amount. (economie.fgov.be mandatory-rounding FAQ)
- **Canada (CRA penny elimination):** round only the final cash settlement after tax; tax/bookkeeping on exact figures; electronic payments never rounded.
- **Shopify POS:** "cash rounding also applies to refunds and exchanges"; `CashRoundingAdjustment` object on order + receipt; cannot be disabled by cashier.
- **NetSuite SCIS:** rounding "applied to the total amount of every cash payment … and refund"; dedicated rounding item + per-payment-method rule.
- **Oracle Xstore:** minimum-cash-denomination applies identically to sale and refund totals; over-tendering a refund disallowed; only the cash leg of a mixed-tender refund rounds.
- **SAP POS:** supports asymmetric purchase-vs-return rounding curves (considered; declined — neutral symmetric chosen).
- **Tunisia:** NO BCT/DGI cash-rounding regulation exists; 5-millime coins legal but scarce — 0.050 rounding is convention, design is free to follow international best practice.

## Notes for the follow-up track's spec

- Vendor-lane observation (recorded, NOT adopted — owner decision 1 locks 658/758): several vendors keep a pricing-rounding account SEPARATE from till-variance accounts. Our 6580/7580 ("écart de règlement") serve both mechanisms by owner decision; keep the source_type distinction (`pos_refund_rounding` vs tolerance/rounding types) so they remain separable in reporting.
- Implementation obstacles already catalogued (r3 treasury F-8): `ReceiptReturnService` totals are server-computed from lines and bound by `pos_receipts_totals` CHECK; both `executeCashRefund` and the prorated non-cash path must be threaded; `ReceiptReturnService::scale()` is a no-arg `getScale()` (rule-19 trap); define per-partial-return accounting (each independent — per this research, no cap/allocation needed, matching Shopify).
- Enforcement school: hard-block untenderable cash payout amounts (Xstore/NetSuite school) rather than suggest-and-override (SkyTab school) — consistent with our sale-side design.

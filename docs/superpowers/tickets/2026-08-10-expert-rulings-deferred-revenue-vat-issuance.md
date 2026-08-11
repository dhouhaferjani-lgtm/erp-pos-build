# Expert-comptable rulings — invoice-before-delivery (received via owner, 2026-08-10)

Answers to the two lane-separation research questions
(`.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/research-lane-separation-report.md`,
Q-LS expert pair). Near-verbatim substance; owner forwarded = adopted.

## EQ-2 — Deferred revenue on anticipated invoicing: **YES**
Under NCT 03, revenue on a sale of goods is recognized only at transfer of risks and
rewards (delivery). Any invoice issued beforehand is strictly a LIABILITY — 472
(Produits constatés d'avance) or 419 (Avances clients) — never revenue.

## EQ-3 — VAT exigibility on an invoice without delivery: **YES**
The Code de la TVA is dual on this point but the strict rule wins: in principle the
taxable event for goods is delivery (Art. 5); however **Art. 18 is formal — anyone who
mentions VAT on an invoice owes it by the mere fact of issuance**. The administration
will demand immediate remittance of that collected VAT.

## Operational recommendation (expert, adopted)
To avoid the cash-flow trap and respect accounting principles: **issue NO definitive
sales invoice before delivery.** Anticipated payments are handled via fund calls /
proforma invoices (WITHOUT VAT mention).

## Owner rider (2026-08-10, binding)
The guard must accommodate ANY country: **the pre-delivery invoicing policy is a
per-country SEEDED SETTING, never hardcoded.** Some countries do it differently. Shape:
a country-defaulted, tenant-editable policy in the settings/capabilities family (align
with the country-defaults `CountryAccountingCapabilities` registry direction — one
authority, not two). TN seeds `require_delivery_first` per the ruling above; other
countries may seed `allow` (which would then need the deferred-revenue 472 treatment
before any such country launches — record as a precondition on that seed value, refuse
`allow` until the 472 machinery exists).

## Program consequences (recorded by the DPA orchestrator)
1. **B1's posture flips from ALLOW+DETECT to GUIDED-REQUIRE + DETECT.** The
   lane-separation research recommended allowing standalone physical-goods invoices with
   acknowledgment + detector; that is now non-compliant for TN. Wave-3 sub-wave 3E
   (plan-wave3.md T23–T25) must be revised at its plan gate: for invoices with physical
   product lines and no delivered goods, the "create & confirm DN now" affordance
   becomes the REQUIRED path (refusal otherwise, typed + translated); prepayments route
   through the advance machinery (419 — seeded for FR by the SEEDS lane; TN has it);
   proforma documents stay OUTSIDE the fiscal invoice chain (no VAT mention). The
   detector (D-a..D-d) and the dormant 418-accrual wiring remain — they now surface
   legacy/edge populations rather than an allowed flow.
2. The owner's lane principle stands as scoped by D-2 ("the invoice does not MOVE
   stock"); these rulings ADD "a definitive goods invoice may not PRECEDE delivery."
   The two compose; nothing else in the Wave-3 plan changes because of this.
3. FR analog (PCG 487 / French VAT delivery-vs-débits option) was NOT asked —
   remains on the expert queue before FR launch.
4. Cross-reference: the research's D-1 ("much larger lane than Wave 3") is now
   PARTIALLY absorbed: the guided-require closes the entry path, so no deferred-revenue
   (472) machinery is needed in Wave 3 — it becomes necessary only if the owner ever
   wants definitive pre-delivery invoicing, which the expert recommends against.

# Own-Named Document-Total Tax Lane

## Context

`TaxCalculationResult::documentTaxTotal` is consumed as `stamp_duty_amount` by document persistence, fiscal hashing, and accounting posting. Treating every `DOCUMENT_TOTAL` configuration as that value mislabels generic surcharges as stamp duty and routes them through stamp-duty GL accounts.

The C/G/H/I accounting-gap lane therefore reserves `DOCUMENT_TOTAL` for supported `is_stamp_duty=true` configurations at mutation time and makes `TaxCalculationService` ignore brownfield non-stamp rows.

## Follow-up scope

Introduce an explicitly named, end-to-end money lane for non-stamp document-total charges before allowing them in configuration again. The design must define:

- a domain name and separate result field rather than reusing `documentTaxTotal` or `stamp_duty_amount`;
- persisted document columns or immutable tax-detail derivation;
- invoice and credit-note total semantics, allocation semantics, and fiscal hash bytes;
- dedicated GL purposes and balanced posting shapes;
- API and UI labels in English and French;
- migration/backfill behavior for any existing non-stamp `DOCUMENT_TOTAL` rows;
- exact-byte tests across draft, confirm, post, reversal, and export paths.

## Typed service-layer refusal (added 2026-08-10, fix round 1 / H1)

The fiscal gate (item C+H verdict, finding 9) observed that the dispatch asked for REFUSAL at configuration time, but item G implements refusal only at the **HTTP surface**. A brownfield non-stamp `DOCUMENT_TOTAL` row — seeded, imported, written by raw SQL, or created from a template — still reaches `TaxCalculationService` and calculates to zero.

Fix round 1 closed the *silence*, not the *design*: `TaxCalculationService` now emits a structured `Log::warning` naming the configuration, company, country, and document whenever it skips such a row. It deliberately does **not** throw. Live blast radius today is zero (the only `DOCUMENT_TOTAL` producer anywhere is the TN stamp seeder, which sets `is_stamp_duty=true`), and turning a calculation path into a throwing one is a behaviour change that belongs with this ticket's design rather than ahead of it.

This ticket therefore owns the decision: **should a non-stamp `DOCUMENT_TOTAL` configuration be a typed domain refusal at the service layer?** Points to settle:

- the exception type and where it is caught — a document calculation that throws must not strand a draft, a POS sync, or a queued projection;
- whether refusal or the named money lane lands first (if the lane lands first, the refusal may become unnecessary);
- what happens to documents already posted while such a row was live;
- whether the warning added in fix round 1 becomes redundant, or stays as the pre-throw canary during a deprecation window.

Until this is decided, the `Log::warning` is the contract: the exclusion is correct, observable, and non-fatal.

## Acceptance boundary

Configuration validation may permit a non-stamp document-total charge only after its value is carried under its own name through calculation, persistence, hash serialization, reporting, and accounting. It must never be stored in `stamp_duty_amount` or posted to stamp-duty accounts.

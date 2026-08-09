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

## Acceptance boundary

Configuration validation may permit a non-stamp document-total charge only after its value is carried under its own name through calculation, persistence, hash serialization, reporting, and accounting. It must never be stored in `stamp_duty_amount` or posted to stamp-duty accounts.

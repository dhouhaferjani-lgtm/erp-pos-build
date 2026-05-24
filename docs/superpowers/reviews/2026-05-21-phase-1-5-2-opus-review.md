# Phase 1.5.2 Opus Second-Pass Review

**Reviewed implementation commit:** `a6cdb9304` (`Phase 1.5.2: Enforce per-country fiscal tax numbers`)
**Reviewer:** Opus second-pass adversarial review via Codex subagent
**Initial verdict:** REQUEST-CHANGES

## Findings

### P1 - ACCOUNT_PAYMENT customer TN tax numbers can still seal in slash form

`apps/pos/src/lib/offline/accountPaymentService.ts` copied `input.tax_number` directly into `payload.customer.tax_number`, while only the seller path normalized TN slashes. The engine validates by normalizing a local variable but hashes the original `request.payload` verbatim, so a TN account-payment customer with `tax_number: "1234567/A/M/000"` could pass validation while canonical bytes retained the slash form. This contradicted synthesis v5 §7, which says canonical producers emit compact TN tax numbers.

**Resolution:** Fixed in `b94304005`. `buildAccountPaymentPayload()` now builds the seller block first, passes the resolved seller jurisdiction into `buildCustomer()`, and normalizes non-null customer tax numbers before canonical hashing. Added focused coverage for `7654321/B/M/000 -> 7654321BM000`.

### P3 - Synthesis v5 status metadata still contradicted the landed Phase 1.5.2 contract

Synthesis v5 §7 said the per-country table was landed, but the doc header and §16 still described the document as draft / pending owner sign-off.

**Resolution:** Fixed in `b94304005`. The header and §16 now mark v5 locked and note the Phase 1.5.2 §7 amendment.

## Checks Passed By Reviewer

- PHP/TS country regexes and requested failure prefixes mirror for FR/TN/SA/DE/IT, FR buyer TVA, IT `buyer.codice_fiscale`, and unknown-country fallback.
- SALE_RECEIPT seller normalization is live via `receiptService.ts` and `SaleReceiptPayload.ts`.
- Parser/resolution paths are live through `StrictCanonicalParser` and `ParseFailureResolutionService`.
- Cross-tenant FK safety: N/A for this commit; no touched hidden projection lookup found.
- CLAUDE.md rule 13: no production `app()`, `App::make()`, or `resolve()` introduced in reviewed fiscal/POS paths.

## R2 Re-Review

**Verdict:** APPROVE

No blocking or request-change findings remained in the R2 delta.

R2 checks:

- ACCOUNT_PAYMENT customer TN tax IDs are normalized before hashing. `buildAccountPaymentPayload()` builds `seller` first, passes `seller.tax_jurisdiction_country_code` into `buildCustomer()`, and normalizes non-null `customer.tax_number` before the payload is returned and passed to `engine.append()`.
- The fallback country choice is correct for the current `AttachedCheckoutCustomer` DTO because it has no address/country field and ACCOUNT_PAYMENT currently emits `customer.address: null`; this mirrors validator fallback behavior.
- Seller normalization was not regressed; seller and customer use the same account-payment normalizer.
- Focused coverage now includes customer normalization (`7654321/B/M/000 -> 7654321BM000`).
- Synthesis v5 status metadata is internally consistent with the landed §7 amendment.

R2 audit note: the reviewer noticed an older non-status sentence in synthesis v5 §11 still saying tax numbers used the universal pattern per v4. This was not a request-change because §7 and §16 already superseded it, but it was cleaned before the audit-trail commit.

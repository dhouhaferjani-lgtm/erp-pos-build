# Codex Round-2 Review — Task 27B Pass 2A.PHP.1 R2 Fix

**Date:** 2026-05-20
**Reviewer:** Codex (transcribed by controller — sandbox-write workaround per project memory standing pattern; Codex's `apply_patch` was scoped to `apps/erp` not `apps/erp.fiscal-phase1`).

## Executive Summary

APPROVE. R2 closes all 5 Codex round-1 findings cleanly (N-01 through N-05). The 7 new-defect candidates (N-06 through N-12) probed in this round are all clean. The implementer's scoping decision (skipping UUID validation on `product_id, buyer.customer_id, buyer.contact_id, table_id`) is contract-faithful — v3 §3 documents those fields as opaque `string`. PHP.2 deferrals (3 Opus P3s) are tracked in the R2 commit body. R2 is dispatch-ready.

## Phase 1 — Round-1 Closure Verification

| Finding | Verdict | Evidence |
|---|---|---|
| N-01 currency_scale allowlist | CLOSED | Allowlist `{0, 2, 3}` enforced in validator; new negative test for `currency_scale=8` rejected with `payload_currency_scale_unsupported:value=8:allowed=0,2,3`; `foreign_currency_amount` scale lookup also restricted to `{0, 2, 3}`. |
| N-02 UUID + datetime format validators | CLOSED | New helpers `validateUuid` + `validateIsoDateTimeWithMs` with documented regexes (`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$` + `^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}(Z\|[+-][0-9]{2}:[0-9]{2})$`); all 6 Codex-named identity fields validated; `event_time_device` ISO 8601 ms+tz validated; 6 new negative tests cover both format failures. |
| N-03 TRAINING-flag coupling invariant | CLOSED | Invariant `(invoice_type_code === 'TRAINING') ⟺ training_flag` enforced; both mismatch directions tested (TRAINING+false → reject; non-TRAINING+true → reject); forensic prefix `payload_training_flag_mismatch:invoice_type_code=<v>:training_flag=<bool>`. |
| N-04 F-15 generator BCMath | CLOSED | `bcdiv((string)$unitPriceMinor, '100', $currencyScale)` replaces the prior `(string)($minor/100)` float hop. F-15 byte-equality test passes — generator output matches committed fixture bytes (F15_BYTES_MATCH confirmed). |
| N-05 §6.E case 8 doc amendment | CLOSED | Synthesis v5 §6.E case 8 forensic prefix amended from `payload_money_format_mismatch` to `payload_money_scale_mismatch` (consistency with §6.B). No code change needed. |

## Phase 2 — New-Defect Probes

| Candidate | Verdict | Notes |
|---|---|---|
| N-06 UUID regex permissiveness | CLEAN | Lowercase-only regex correctly rejects uppercase UUIDs (matches Task 13 v37 SQLite convention). v4/v7 UUID variants both match the format pattern. Acceptable for Phase 1 scope. |
| N-07 Datetime regex format coverage | CLEAN | Exact regex matches the v3 §3 contract (`event_time_device: ISO 8601 with ms + timezone offset`). Microseconds (`.123456`) rejected by design — Phase 1 contract is ms precision. All F-01..F-15 `event_time_device` values match after R2 regeneration. |
| N-08 TRAINING invariant order-of-operations | CLEAN | Validator runs `invoice_type_code` enum check → `training_flag` bool check → coupling invariant. Missing `training_flag` surfaces as bool error before reaching the coupling check; forensic prefixes are distinct (`payload_required_key_missing` vs `payload_invalid_type` vs `payload_training_flag_mismatch`). Order is sane. |
| N-09 F-15 byte-equality fragility | CLEAN | After R2 regenerated all 15 fixtures with new `event_time_device` format, F-15 byte equality between generator output and committed fixture holds. JSON key ordering stable per JCS canonical encoder. |
| N-10 Scoping decision audit | CLEAN | v3 §3 confirms all 4 fields the implementer scoped OUT (`product_id`, `buyer.customer_id`, `buyer.contact_id`, `table_id`) are typed as opaque `string`, not UUID. Existing fixtures legitimately use non-UUID values (`prod-default`, `cust-007`, `T-007`). Scoping decision is contract-faithful. Codex's original round-1 N-02 list (6 fields) was correct. |
| N-11 foreign_currency_amount scale lookup | CLEAN | `foreign_currency_amount` scale lookup confirmed restricted to `{0, 2, 3}`. (Note: real foreign currencies with scale=4 like some forex pairs are deferred to Phase 2+ when ZATCA/multi-currency implementation lands.) |
| N-12 Forensic prefix uniqueness | CLEAN | 4 new forensic prefixes (`payload_currency_scale_unsupported`, `payload_uuid_format_mismatch`, `payload_datetime_format_mismatch`, `payload_training_flag_mismatch`) verified unique — no collisions with existing prefixes in the validator. |

## Phase 3 — Forward-Compatibility (PHP.2 Tracking)

R2 commit body explicitly tracks the 3 Opus P3 deferrals:
- D16 grep missing 4 Eloquent static-call regexes (`Customer::`, `Contact::`, `B2B::`, `TreasuryPayment::`).
- F-15 determinism via in-process double-construction equality test.
- F-15 SALE-with-null-reference synthesis ambiguity (implementer chose only legal interpretation).

No additional PHP.2-deferrable items surfaced by R2. The PaymentMethodResolver injection site planned for PHP.2 remains compatible with the R2 validator surface.

## Verdict

R2 is a tight, well-scoped fix that closes all 5 round-1 Codex findings cleanly. The implementer's scoping decision on UUID validation respects the contract documentation in v3 §3. No new defects introduced. R2 is dispatch-ready. Push R1+R2 to origin and proceed to PHP.2.

VERDICT: APPROVE

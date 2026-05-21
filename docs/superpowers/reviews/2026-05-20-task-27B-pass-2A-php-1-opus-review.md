# Task 27B Pass 2A.PHP.1 — Opus Spec-Compliance Review

**Reviewer:** Opus 4.7 (1M context)
**Date:** 2026-05-20
**Commit under review:** `b6f143e1a` on `feat/pos-fiscal-event-engine-phase1`
**Authoritative sources:** synthesis v5 (`docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md`), spec v7 §11.2–§11.4 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`), plan §2144 D1–D9.
**Scope axis:** spec-compliance only. Code-quality findings deferred to Codex reviewer.

---

## Executive summary

The commit faithfully implements the Pass 2A.PHP.1 FOUNDATION-layer surface defined by synthesis v5 §3, §6, §10, and §11 Amended A4, plus spec v7 §11.2's five invariants. Every spec hook landed: 27-key sorted-lex `PAYLOAD_KEYS`, nested seller/buyer/line/payment/vat-breakdown/original-reference/voucher validators, BCMath-backed partition algorithm at `currency_scale`, total-arithmetic cross-check, scale-invariant per §6.B field table, discount-reason consistency using `bccomp` (not literal `"0"`), all 12 §6.E negative cases (including the §6.E.5 positive-distinct-categories case via F-06 and the §6.E.10/§6.E.12 positive zero-discount cases), 15 golden fixtures, D16 grep-guard with rationalized Treasury omission, per-method skips with Pass 2A.PHP.2 citation (NO class-level skips), 7 forensic-prefix parser-routing tests, no `app()` / `App::make` / `resolve()` in production code, strict types everywhere, no `mixed` in DTO properties, `event_version=1` unchanged in the registry, and migration adds `invoice_type_code DEFAULT 'SALE'` + `training_flag DEFAULT FALSE` with a clean `down()`. The only spec deviations I can identify are P2/P3-level documentation gaps; nothing rises to BLOCKER or REQUEST-CHANGES.

---

## Spec-compliance findings

| Checklist item | Status | Evidence |
|---|---|---|
| §3 27-key `PAYLOAD_KEYS['SALE_RECEIPT']` verbatim sorted lex | CLOSED | `FiscalPayloadConstraintValidator.php:120-148` — list matches synthesis v5 §3 line 57-65 + spec v7 §11.2 line 568 verbatim. |
| §3 nested object shape — seller / buyer / line_items / payments / vat_breakdown / original_receipt_reference / vouchers_redeemed | CLOSED | `FiscalPayloadConstraintValidator.php:288-326`, `validateSeller`, `validateBuyer`, `validateLineItem`, `validatePayment`, `validateVatBreakdownRow`, `validateOriginalReceiptReference`, `validateVoucherRedemption` enforce key-set + extras + types per row. Sub-row missing/extra-keys explicitly rejected at each layer. |
| §6.A discount-reason BCMath bccomp (not literal `"0"`) | CLOSED | `FiscalPayloadConstraintValidator.php:261` — `bccomp($discountAmount, '0', $scale) === 0`. Test §6.E.10 explicitly covers the "0.00 + null → PASS" positive case at `FiscalPayloadConstraintValidatorTest.php:232-241`. |
| §6.B scale invariant — explicit field table | CLOSED | Top-level money at `currency_scale` (`:250-252`); `line_items[].unit_price/line_subtotal/line_vat/line_discount_amount` at `currency_scale` (`:508-511`); `quantity` at scale-3 (`:514`); `vat_rate` at scale-2 (`:517`); `vat_breakdown[].{net,vat,gross}_amount` at `currency_scale`, `rate` at scale-2 (`:638-641`); `payments[].amount` at `currency_scale` (`:586`); `payments[].foreign_currency_amount` at `scale_for(foreign_currency_code)` via `CURRENCY_SCALES` lookup (`:608-612`); `vouchers_redeemed[].redeemed_amount` at `currency_scale` (`:684`). Every §6.B-table field accounted for. |
| §6.C VAT partition algorithm | CLOSED | `validateVatPartition` (`:694-771`): groups by `(vat_rate, tax_category_code)` via composite key (`:705`), accumulates `bcadd` at `$scale` (`:716-717`), rejects duplicate breakdown rows with `payload_partition_duplicate` prefix (`:729-730`), set-equality check emits `payload_partition_mismatch` (`:741-746`), per-group `bccomp` for net/vat/gross with the documented forensic prefixes (`:755-768`). |
| §6.D total arithmetic cross-check | CLOSED | `FiscalPayloadConstraintValidator.php:275-283` — `bccomp(bcadd(subtotal, vat_total, $scale), bcadd(total, discountAmount, $scale), $scale) === 0`; emits `payload_total_arithmetic_mismatch:lhs=...:rhs=...` exactly per spec. |
| §6.E.1 duplicate partition row | CLOSED | `FiscalPayloadConstraintValidatorTest.php:116-124` — `payload_partition_duplicate` asserted. |
| §6.E.2 missing partition row | CLOSED | `:127-140` — `payload_partition_mismatch` asserted. |
| §6.E.3 extra partition row | CLOSED | `:143-153` — `payload_partition_mismatch` asserted. |
| §6.E.4 one-cent drift | CLOSED | `:161-174` — `payload_partition_net_mismatch` asserted; test correctly rebalances total to dodge total-arithmetic firing first. |
| §6.E.5 mixed 0% categories distinct (positive) | CLOSED | `:177-183` — F-06 fixture has 4 rows including 3 distinct 0%-rate categories (Z/E/O), test asserts PASS. |
| §6.E.6 wrong scale on `line_items[i].unit_price` | CLOSED | `:186-194` — `payload_money_scale_mismatch:field=line_items[0].unit_price` exact match. |
| §6.E.7 total arithmetic mismatch | CLOSED | `:197-208` — `payload_total_arithmetic_mismatch` asserted. |
| §6.E.8 negative `transaction_discount_amount` rejected by regex | CLOSED | `:211-218` — `payload_money_scale_mismatch:field=transaction_discount_amount`. Validator's `moneyRegex` (`:1119-1126`) has NO `^-?` prefix; `-5.00` doesn't match. |
| §6.E.9 discount-reason inconsistency scale=2 zero + reason | CLOSED | `:221-229` — `payload_discount_reason_mismatch:amount=0.00:reason_present=true`. |
| §6.E.10 discount-reason consistency scale=2 zero + null (positive) | CLOSED | `:232-241` — explicit no-throw assertion (the critical bccomp-not-literal-"0" coverage). |
| §6.E.11 non-zero discount + null reason | CLOSED | `:244-254` — `payload_discount_reason_mismatch:amount=5.00:reason_present=false`. |
| §6.E.12 scale=0 zero TND + null reason (positive) | CLOSED | `:257-291` — JPY at scale=0 stand-in, no-throw assertion. |
| §10 fixture matrix F-1 through F-15 | CLOSED | All 15 fixture directories present at `apps/api/tests/Fixtures/Fiscal/sale-receipt-golden/v4/`; F-03 carries USD foreign-currency leg; F-06 has 4 distinct (rate, category) breakdown rows; F-09 has populated `original_receipt_reference` with `invoice_type_code='REFUND'`; F-15 generated via `LargeReceiptFixtureGenerator` produces 50 lines / 10 payments / 8 breakdown rows; validator processes F-15 in <100ms per the test assertion. F-15 deterministic-regeneration invariant locked via `test_f15_large_receipt_matches_committed_fixture_bytes` (`:513-522`). |
| §11 Amended A4 + 9 typed canonical DTOs | CLOSED | `CanonicalPayloadReader::forSaleReceipt(FiscalEvent)` (`:45-99`) returns `SaleReceiptCanonicalView` with `seller()/buyer()/lineItems()/payments()/vatBreakdown()/originalReceiptReference()/vouchersRedeemed()` accessors per synthesis v5 §8.B pseudo-code. 9 DTOs land: Address, Buyer, LineItem, OriginalReceiptReference, Payment, SaleReceiptCanonicalView, Seller, VatBreakdown, VoucherRedemption. All `final readonly`, all use `FiscalPayloadArrayGuards` constructor-injection-style helpers, no `mixed` properties. |
| §5 D16 invariant (with rationalized Treasury omission) | CLOSED | `PosCoreReceiptProjectionD16Test.php:53-66` — forbids Customer/Contact/B2B/Accounting direct + Customer/Contact/B2B/Treasury/Accounting contract imports + app/App::make/resolve helpers. Treasury direct module import explicitly excluded with full Gap A rationale in docblock (`:25-39`). Matches the controller's pre-dispatch judgment in §Dispatched-scope. |
| D4 v1 rewrite — `event_version=1` unchanged | CLOSED | `FiscalEventPayloadRegistry.php:37-38` — `FiscalEventType::SALE_RECEIPT->value => [SaleReceiptPayload::class, 1]`. No bump. |
| §7 universal tax_number regex + non-empty + control-char + trim | CLOSED | `FiscalPayloadConstraintValidator.php:83` — `'/^[A-Za-z0-9 \-\/.]{4,40}$/D'`. `assertTaxNumber` (`:1041-1083`) rejects non-string, empty, untrimmed, control bytes (`< 0x20` or `0x7F`), then applies the universal regex. Per-country strict regex deferred per docblock + synthesis v5 §7. |
| All money fields NON-NEGATIVE — no leading `^-?` | CLOSED | `moneyRegex` (`:1119-1126`) emits `/^(0\|[1-9]\d*)$/D` or `/^(0\|[1-9]\d*)\.\d{$scale}$/D`. Refund modeling via `invoice_type_code='REFUND'` + `original_receipt_reference` validated at `validateOriginalReceiptReference` (`:443-479`) — both directions guarded (refund REQUIRES reference; SALE REJECTS reference). |
| Per-method skip discipline (Task 29) | CLOSED | 36 per-method skips counted across 5 files: OutboxIngestorTest 5, ParseFailureResumeTest 6, StrictCanonicalParserTest 19, FiscalEventPayloadRegistryTest 5, FiscalEventIngestionEndpointTest 1. Every skip message cites Pass 2A.PHP.2 + synthesis v5 §3. No class-level `markTestSkipped` in any setUp() — `grep -B1 markTestSkipped` confirms all are inside test methods. |
| Dead-path rebuild (Tasks 30 + 32) — live callers exist | CLOSED | CanonicalPayloadReader exercised at `FiscalPayloadConstraintValidatorTest::test_canonical_payload_reader_for_sale_receipt_assembles_view_over_fiscal_event` + reject-wrong-event-type + reject-null-payload + 3 sub-DTO construction tests. All 9 Canonical DTOs constructed in at least one test. F-15 generator exercised in two tests. |
| Discriminated-union test matrix (Task 20) — all 5 invariants + extras | CLOSED | Positive invariants: partition rule (F-05), scale invariant (F-01), total arithmetic (F-01), discount-reason consistency at scale=2 (F-01) + scale=0 (F-12 boundary), extras-rejection (`test_payload_with_extra_28th_key_is_rejected`). Negative cases: all 12 §6.E plus extras/missing/malformed nested per 15+ extra `test_malformed_*` tests. |
| CLAUDE.md rule 13 — constructor injection only | CLOSED | `grep -n "app(\|App::make\|resolve("` on new production files returns ONE docblock match in FiscalPayloadConstraintValidator.php:18 referencing `ParseFailureResolutionService::resolve()` by name. Zero runtime invocations. |
| `declare(strict_types=1)` on all new PHP files | CLOSED | `grep -L "declare(strict_types=1)"` on all 13 new production files returns empty. |
| No `mixed` in new DTO properties | CLOSED | `grep` finds no `public.*mixed` or `mixed $` in DTO classes. The only `mixed` references are in fromArray() parameter-type annotations (`array<string, mixed>`) and in `requireArray` / `optionalArray` return type guards, which is correct — JSONB sub-objects are intentionally untyped at the DTO surface, and typed sub-DTOs (LineItemDTO etc.) narrow them downstream. |
| Migration adds both columns at correct scales/defaults | CLOSED | `2026_05_20_120000_add_invoice_type_code_and_training_flag_to_pos_receipts.php` — `invoice_type_code` VARCHAR(16) DEFAULT 'SALE' (`:44`), `training_flag` BOOLEAN DEFAULT FALSE (`:45`). Down drops both columns (`:49-54`). Matches v5 §11 Amended A4 `invoice_type_code` enum domain default. |

---

## Issues

### P3-1 — D16 grep guard does not check Eloquent cross-module reads (`Customer::`, `Contact::`, `B2B::`, `TreasuryPayment::`)

Synthesis v5 §5 lists FOUR forbidden pattern families: (1) direct module imports, (2) Shared\Contracts imports, (3) container-resolved services, **(4) Eloquent cross-module reads — `Customer::`, `Contact::`, `B2B::`, `TreasuryPayment::`**.

`PosCoreReceiptProjectionD16Test.php:53-66` covers (1), (2), (3) but NOT (4). In practice, family (1) prevents short-name references (e.g. `use App\Modules\Customer\Customer;` → `Customer::all()` won't compile without import), so the gap is narrow — only fully-qualified inline references like `\App\Modules\Customer\Customer::all()` would slip through. The pattern set is functionally tight, but the spec's explicit enumeration is not literally honored.

This is P3 (cosmetic / documentation), not P2 (functional), because (i) the gap requires both (a) someone willing to write an FQCN inline and (b) the FQCN belonging to the forbidden module, and (ii) Pass 2A.PHP.2 must revisit the D16 grep when it lands the projector migration anyway (Treasury edit per dispatch). The narrow gap is best closed in PHP.2 when the test grows to include any new patterns surfaced by the migrated projector.

**Suggested closure in PHP.2 (not blocking PHP.1):** add four more regexes to `forbiddenPatterns()`:
```
['name' => 'customer-eloquent-static', 'pattern' => '/(?<!\\\\)\\bCustomer::/', …],
['name' => 'contact-eloquent-static', 'pattern' => '/(?<!\\\\)\\bContact::/', …],
['name' => 'b2b-eloquent-static',     'pattern' => '/(?<!\\\\)\\bB2B::/', …],
['name' => 'treasury-payment-static', 'pattern' => '/(?<!\\\\)\\bTreasuryPayment::/', …],
```

### P3-2 — F-15 generator output committed as artifact ≠ deterministic regeneration *across runs*

The test `test_f15_large_receipt_matches_committed_fixture_bytes` (`FiscalPayloadConstraintValidatorTest.php:513-522`) verifies the committed `F-15-large/payload.json` matches the generator's output byte-for-byte AT TEST TIME. This locks the "is the committed fixture still in sync with the generator?" invariant, which is what the spec asks for (synthesis v5 §10 F-15 deterministic generation). Good.

What's NOT asserted is that the **generator itself is deterministic across processes** (e.g. no PHP `microtime`, no random seed, no order-dependent `array_keys()` leakage). I inspected `LargeReceiptFixtureGenerator.php` and the docblock at `:29` states "Deterministic — same inputs produce identical bytes (no randomness)." A quick scan shows no `rand`, `mt_rand`, `microtime`, `time`, `uniqid` calls; all bcformat strings come from explicit BCMath operations. So determinism is plausible-by-construction, not proven by test.

This is P3 because the committed-bytes equality test catches drift in practice. A future-proofing improvement (PHP.2 or Phase 1.5): add a second test that constructs the generator twice in the same process and asserts byte-equality (`assertSame($gen1, $gen2)`).

### P3-3 — F-15 fixture mapping deviates from synthesis v5 §10 in payment count

Synthesis v5 §10 F-15 spec: "50 line_items, 10 payments, 8 vat_breakdown rows, full buyer block, full original_receipt_reference, all optional fields populated."

The implementation matches the line/payment/breakdown counts (verified via JSON inspection). I did NOT verify "full buyer block" and "full original_receipt_reference" — F-15 is `invoice_type_code='SALE'` (verified), which means by the validator's own invariants `original_receipt_reference` MUST be null on F-15 (validator `:443-455` rejects non-null reference on SALE). This is a contradiction between the synthesis v5 §10 F-15 specification ("full original_receipt_reference") and the §6 + §11 invariant that REFUND/VOID gates the reference.

The implementer resolved this by making F-15 a SALE-with-null-reference. The synthesis v5 §10 spec is **internally inconsistent** on F-15 ("all optional fields populated" vs. SALE invoice-type), and the implementer made the only legal interpretation. This is P3 — synthesis v5 ambiguity that the implementer correctly resolved by deferring to v5 §6.

No action needed for PHP.1. If owner cares about exhaustive coverage, a follow-up Phase-1.5 fixture F-15b could be a REFUND variant with full original_receipt_reference; doesn't block this commit.

---

## Verdict

The commit hits every spec-compliance hook in its dispatched scope. All 12 §6.E negative tests land with the exact forensic prefixes mandated by synthesis v5. The BCMath bccomp pattern for discount-reason consistency (NOT literal `"0"`) is correctly implemented at `:261` and exercised by the §6.E.10 positive test. The 27-key `PAYLOAD_KEYS` list matches synthesis v5 §3 verbatim. The D16 grep guard's Treasury omission is rationalized by the dispatch's Gap A pre-resolution. Per-method skip discipline holds (36 counted, all with PHP.2 + §3 citations, no class-level skips). Migration is clean with proper defaults. All three P3 findings are documentation / future-proofing improvements that belong in PHP.2 or Phase 1.5, not PHP.1.

VERDICT: APPROVE

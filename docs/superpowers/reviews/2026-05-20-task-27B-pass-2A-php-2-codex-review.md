# Codex Adversarial Review — Task 27B Pass 2A.PHP.2

**Date:** 2026-05-20
**Reviewer:** Codex (transcribed by controller — sandbox-write workaround per project memory standing pattern; Codex's `apply_patch` was scoped outside the worktree). agentId `ac38813d114099f95`.

## Executive Summary

Pass 2A.PHP.2 ships meaningful infrastructure — PaymentMethodResolver abstraction, Nf525DataProvider bifurcation, TreasuryReceiptBridge migration, 36 skip removals, D16 test extensions — but contains two correctness BLOCKERs that must be fixed before approval. First: the product FK resolver in `PosCoreReceiptProjection::resolveProductFk()` performs a non-tenant-scoped lookup against `products.id`, opening a cross-tenant FK binding path. Second: canonical `REFUND`/`VOID` receipts that arrive with a non-resolvable local `original_receipt_id` are stored with `receipt_type = 'sale'`, causing NF525 export to route them through the sales path instead of the returns path. Additionally, the D16 grep guard is missing the `use App\Shared\Contracts\Treasury\` forbidden pattern, and the required buyer-deletion snapshot regression test is absent. BLOCK pending these four repairs.

---

## Phase 1: Implementer Claim Verification

**P1-1. PaymentMethodResolver namespace** — CONFIRMED. Interface at `apps/api/app/Shared/Contracts/Fiscal/PaymentMethodResolver.php`, namespace `App\Shared\Contracts\Fiscal`. Synthesis v5 §5 places it in the Fiscal Shared Contracts namespace (not Treasury) to avoid Treasury → POS reverse coupling.

**P1-2. EloquentPaymentMethodResolver tenant-scoping** — CONFIRMED. `EloquentPaymentMethodResolver.php:38-44`: `->where('tenant_id', $tenantId)->where('code', $methodCode)->first()`. No cross-tenant leak.

**P1-3. Buyer block snapshot test (v5 §5 invariant #4)** — NOT-CONFIRMED. No test in `PosCoreReceiptProjectionTest.php` seals a receipt with `buyer.customer_id = X`, deletes the customer row, re-runs projection, and asserts payload-only read. Grep across the full test suite yields no match.

**P1-4. D16 grep guard completeness** — PARTIAL. `PosCoreReceiptProjectionD16Test.php` contains patterns for `use App\Modules\Customer\`, `use App\Modules\Contact\`, `use App\Modules\B2B\`, `use App\Modules\Treasury\`, `use App\Modules\Accounting\`, `use App\Shared\Contracts\Customer\`, `use App\Shared\Contracts\Contact\`, `use App\Shared\Contracts\B2B\`, `use App\Shared\Contracts\Accounting\`, `\bapp\(`, `App::make`, `\bresolve\(`, `\bCustomer::`, `\bContact::`, `\bB2B::`, `\bTreasuryPayment::`. **Missing: `use App\Shared\Contracts\Treasury\`.** PaymentMethodResolver legit import (`use App\Shared\Contracts\Fiscal\PaymentMethodResolver`) is NOT false-positived.

**P1-5. Nf525DataProvider bifurcation correctness** — CONFIRMED for `mapSaleReceipt`. `mapVoidedReceipt` and `mapReturnReceipt` also branch on `fiscal_event_id` at lines 171-195. Null path falls through to legacy relation reads; non-null path reads from CanonicalPayloadReader and skips legacy line/payment/VAT relations.

**P1-6. TreasuryReceiptBridge scope** — PARTIAL. Synthesis v5 §8.B explicitly lists TreasuryReceiptBridge as in-scope. Implementation correct.

**P1-7. Repository_id resolution determinism** — NOT-CONFIRMED. `TreasuryReceiptBridge.php:214-228`: `->where('tenant_id', $tenantId)->where('company_id', $companyId)->whereNotNull('gl_account_id')->first()`. No `orderBy`. "First" is DB-order dependent. Two payments in the same receipt could resolve to different repositories under concurrent transactions.

**P1-8. return_reason mapping** — CONFIRMED. Canonical free-text stored in `payload`; `return_reason` column maps to `ReturnReason::Other`. Legacy queries unaffected.

**P1-9. product_id FK resolution** — **NOT-CONFIRMED (BLOCKER).** `PosCoreReceiptProjection.php:498-516`: uses `Product::where('id', $productId)->exists()` with no tenant_id scope. The `products` table has `tenant_id` FK (migration `2025_11_30_052910_create_products_table.php:17-18`). A product UUID from tenant A can match a row belonging to tenant B, writing a cross-tenant FK into `pos_receipt_lines.product_id`. No test covering this boundary.

**P1-10. F-15 determinism test** — CONFIRMED. `FiscalHashChainDeterminismTest.php:testF15DoubleConstructionBytewiseEquality()` calls `buildGenerator()` twice and asserts byte-equality on two in-memory invocations.

---

## Phase 2: New Defects Found

### BLOCKER

**BLOCKER-1: Cross-tenant product FK binding.**
`PosCoreReceiptProjection::resolveProductFk()` at line 498-516 queries `Product::where('id', $productId)->exists()` without `where('tenant_id', $tenantId)`. If a receipt line carries a `product_id` UUID that matches a product row in ANY tenant, the FK is written. A receipt from tenant B can end up with `pos_receipt_lines.product_id` pointing to tenant A's product. **Data-integrity + cross-tenant isolation BLOCKER** — same defect class as Task 21 R2 Opus F3 closed for `payment_method_id`, but missed here for `product_id`.
**Fix:** add `->where('tenant_id', $tenantId)` to the exists check. Add a negative test for cross-tenant product_id rejection (parallel to the existing payment_method cross-tenant test).

**BLOCKER-2: REFUND/VOID receipt_type downgrade on unresolved original.**
`PosCoreReceiptProjection.php:389-404`: when a canonical payload has `event_type = REFUND` or `VOID` but `original_receipt_id` cannot be resolved to a local receipt UUID, the projection falls through to the default `receipt_type = 'sale'`. `Nf525DataProvider::mapSaleReceipt()` (line 126-135) then exports it as a sale for NF525. NF525 §11.2-§11.4 + spec v7 §14 require voided and return receipts to appear in the correct fiscal movement type. **Fiscal-compliance BLOCKER** — silent downgrade falsifies the audit trail.
**Fix:** throw a `FiscalProjectionException` (or store with a dedicated `UNRESOLVED_RETURN` status) rather than silently downgrading to sale. Operator notified.

### P1

**P1-1: Buyer block deletion regression test absent.**
Synthesis v5 §5 invariant #4 explicitly requires a test that seals a receipt with `buyer.customer_id = X`, deletes the customer row, re-runs projection, and asserts the projection reads from payload only. Not present in PHP.2. Without this test, a future regression (projector re-querying customer table instead of payload) will be invisible.

**P1-2: D16 guard missing `use App\Shared\Contracts\Treasury\`.**
`PosCoreReceiptProjectionD16Test.php` forbids `use App\Modules\Treasury\` but not `use App\Shared\Contracts\Treasury\`. If a future developer adds `use App\Shared\Contracts\Treasury\SomeInterface` to the projector (instead of the correct Fiscal namespace), the D16 guard will not catch it.

### P2

**P2-1: PaymentMethodResolver null contract underdocumented.**
Interface docblock says `@return ?string` with no specification of when null is returned vs when an exception should be thrown. `PosCoreReceiptProjection` treats null as "method code not found → throw RuntimeException." A future implementation that returns null for transient reasons (DB timeout) will trigger the same exception. Contract should specify: null means "code does not exist in this tenant," and implementations must not return null for transient failures.

**P2-2: TreasuryReceiptBridge D16 safety — import graph.**
`TreasuryReceiptBridge` now imports `CanonicalPayloadReader` and `PaymentMethodResolver`. Both verified to live in Shared/Contracts (not POS module direct). Safe per D16 §13.6. Documentation note only.

**P2-3: D16 regex anchoring for TreasuryPayment.**
Pattern `\bTreasuryPayment::` uses `\b` word boundary. `preg_match` handles correctly. Safe. P2 note: add a comment explaining why `\bTreasuryPayment::` and not the broader `use App\Modules\Treasury\TreasuryPayment`.

**P2-4: TreasuryServiceProvider binding on POS-only tenants.**
If Treasury module is excluded from a POS-only deployment (IziPOS minimal config), the binding is absent and `PosCoreReceiptProjection` constructor injection fails at runtime with `BindingResolutionException`. No fallback binding in `PosServiceProvider`. Risk currently low (Treasury always loaded in this monorepo) but should be documented.

**P2-5: PosCoreReceiptProjection constructor arity — call sites updated.**
Two call sites verified: `PosServiceProvider.php:88` (service container injection) and `PosCoreReceiptProjectionTest.php:setUp()` (mock PaymentMethodResolver injected). CONFIRMED safe.

**P2-6: TreasuryReceiptBridge repository_id determinism (raised as P1-7 in Phase 1).**
Repository lookup lacks deterministic ordering. Even if functionally equivalent in single-receipt context, parallel writes across replicated DB instances could differ. Add `->orderBy('id')` (or some deterministic ordering) in PHP.2 round-2, OR explicitly document the non-determinism + Phase 1.5 deferral.

### P3

**P3-1: Migration default values.** Postgres + SQLite handle defaults correctly. No NULL violation risk. No action required.

**P3-2: Migration ordering.** PHP.1 migration precedes PHP.2 writes. Rollback safe. Documentation note only.

**P3-3: Receipt model fillable + cast.** CONFIRMED correct.

---

## Verdict

Two correctness BLOCKERs must be resolved before approval: cross-tenant product FK binding in `resolveProductFk()` and silent REFUND/VOID → sale downgrade in `PosCoreReceiptProjection`. Additionally, the buyer deletion snapshot test (v5 §5 invariant #4) must be added and the D16 guard must include `use App\Shared\Contracts\Treasury\`. Fix all four, re-run the targeted fiscal slice, then re-submit.

VERDICT: BLOCK

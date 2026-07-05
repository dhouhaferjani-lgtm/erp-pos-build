# Adversarial Review — variant-aware exactly-once POS stock decrement

- **Branch:** `fix/pos-variant-stock-decrement`
- **Commit:** `27537df5d` — `fix(pos): variant-aware exactly-once stock decrement in PosCoreReceiptProjection`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp.pos-stock`
- **Reviewer stance:** adversarial — tried to break it.
- **Overall verdict: PASS-WITH-NITS.** No BLOCK. The change is a faithful, narrowly-scoped port of the already-variant-aware legacy decrement into the projection path; the worst-case I chased (orphan FK / wrong-row decrement) is contained by existing DB constraints. Two test-quality NITs and one observability NIT are worth fixing; one **out-of-scope pre-existing** latent bug (refund/void decrement direction) is flagged for follow-up.

---

## 1. EXACTLY-ONCE DECREMENT — **PASS**

The projection is the sole *live* server-side decrement for a SALE_RECEIPT.

- `apply()` decrements unconditionally at `PosCoreReceiptProjection.php:319` after the receipt row is inserted.
- The draft-creation path `ReceiptCreationService::createReceipt` (its own `decrementStock`, `ReceiptCreationService.php:883`) is provably retired. `apps/api/scripts/saleReceipt-chokepoint-manifest.json` lists all three call sites:
  - `ReceiptController::store` → disposition `b`, note "POST /pos/receipts is 410 Gone; … Body never invoked."
  - `OrderToReceiptService::convertToReceipt` → `b`, "POST /pos/orders/{id}/close is 410 Gone; … body never invoked."
  - `ExchangeService::processExchange` → `b`, `live:false`, "no live route/controller caller."
  - The manifest is CI-reconciled (`scripts/check-saleReceipt-chokepoints.sh` + `tests/Feature/Fiscal/ChokepointCompletenessTest.php`), so a resurrected live caller fails CI. The "single authoritative" claim rests on this gate, which is the right place for it.
- Idempotency keys it to one decrement per fiscal event (see §3).

**Caveat (out of scope, see §7):** the projection only declares `SALE_RECEIPT` support (`:136`) but a SALE_RECEIPT event whose `invoice_type_code` is `REFUND`/`VOID` is still processed (mapped to `ReceiptType::Return`, `:237`) and `decrementStockForLines` is then called **unconditionally** at `:319` — i.e. a refund would *decrement* (now the variant row) rather than restore. This is pre-existing and not introduced/worsened in kind by this commit, but it is the one place where "anything decrements wrongly" could bite. Flagged for a separate task; verify the refund-line quantity sign before acting.

---

## 2. VARIANT SCOPING (highest-value area) — **PASS-WITH-NITS**

### Scoping is correct
`decrementStock` (`:925-934`) scopes by `product_id + location_id + company_id`, then `where('variant_id', $variantId)` when set, else `whereNull('variant_id')`. The DB backs this with partial unique indexes (`2026_06_02_100005_add_variant_id_to_stock_levels.php`): `stock_levels_non_variant` `(tenant, product, location) WHERE variant_id IS NULL` and `stock_levels_with_variant` `(tenant, product, variant, location) WHERE variant_id IS NOT NULL`. So `lockForUpdate()->first()` resolves to a single deterministic row — no duplicate-row ambiguity. `stock_movements.variant_id` is written at `:972`. The tests (a)/(b) prove the variant row moves and the product-level decoy does not, and vice-versa — not tautological.

### The raw-vs-resolved `variant_id` divergence — contained, not a bug
This is the divergence the brief flagged: `decrementStock` receives the **raw** `$line->variantId` (`:901`), with **no** UUID/FK/product-anchor validation, whereas the receipt line gets `$variantFk` from `resolveVariantFk(tenant, productFk, …)` (`:584-585`), which is UUID-checked, tenant-scoped and **product-anchored** and may yield `null`. I tried to turn that into a defect; the existing constraints close every path:

- **Orphan `stock_movements.variant_id` insert?** `stock_movements.variant_id` has `FOREIGN KEY … REFERENCES product_variants(id) … NOT VALID` (`2026_06_02_100006`). `NOT VALID` in Postgres skips validation of *pre-existing* rows only — **new inserts are still checked**. So a raw `variant_id` that isn't a real variant would FK-violate and roll back the whole projection (a fail-closed retry, not silent corruption). But the movement insert is only reached after a matching `stock_levels` row is found, and `stock_levels.variant_id` *also* FK-references `product_variants` (same migration). Hence any row found by raw lookup guarantees the variant exists → the movement FK passes. No orphan insert, no rollback in practice.
- **Wrong-row decrement?** To decrement the wrong row, raw `(product_id, variant_id)` would have to find a `stock_levels` row that `resolveVariantFk` rejected. `resolveProductFk` cannot diverge (a `stock_levels` row implies a `products` row via FK, so `resolveProductFk` resolves it). The only residual case is a `stock_levels` row whose `variant_id` belongs to a *different* product than the line's product — corrupt seed data with no DB constraint forbidding it. In that single case the movement would stamp a `variant_id` the receipt line recorded as `null`. Requires manufactured bad data; **low-severity NIT, not a blocker.**

### NIT 2a — silent no-op leak when a variant line has no variant-level stock row (observability)
If a variant line is sold but only a product-level (`variant_id IS NULL`) `stock_levels` row exists, `decrementStock` returns early at `:939-941` and decrements **nothing** (the product-level pool is *not* touched). Pre-fix, the product-level row was decremented. This matches the legacy path's documented "No stock record — skip … for products without inventory tracking" (`ReceiptCreationService.php:907`), so it is intended "untracked" behavior, **not** a regression. But unlike the insufficient-stock branch (`:947-954` logs a warning), the missing-row branch is **completely silent** — there is no way to distinguish "intentionally untracked" from "tracked product whose variant stock row was never seeded," which is exactly the failure mode that produces silent inventory overstatement. Recommend a `Log::info/warning` on the early return. **NIT.**

### NIT 2b — raw `variantId` not validated before stamping the movement
Even though DB constraints make it safe today, threading the **resolved** `$variantFk` (or at least UUID-guarding the raw value as `decrementStockForLines` already does for `productId` at `:886`) would make the movement's `variant_id` consistent-by-construction with the receipt line, removing reliance on the `NOT VALID` FK as the last line of defense. **NIT.**

---

## 3. IDEMPOTENCY — **PASS**

- Fast-path probe `Receipt::query()->where('fiscal_event_id', …)->exists()` at `:152`.
- Atomic `INSERT … ON CONFLICT ON CONSTRAINT pos_receipts_fiscal_event_id_unique DO NOTHING RETURNING id` (`:337-344`); `insertedId === null` ⇒ early return at `:310-313` **before** `decrementStockForLines`.
- All inside `DB::transaction` (`:171`) with `lockForUpdate()` on the stock row (`:937`).
- Race: two concurrent jobs both pass `exists()`; one wins the INSERT and decrements, the other gets `null` and returns. The docblock additionally cites the per-row `WithoutOverlapping` lock on `ApplyFiscalEventProjectionJob`. Replay test (d) exercises a second `apply()` and asserts quantity, movement count, and receipt count all stay at one. **Solid.**

---

## 4. PRECISION — **PASS**

- Decrement: `bcsub($stockQty, $qty, 4)` (`:962`); guard `bccomp($available, $qty, 4)` (`:947`); `getAvailableQuantity()` is `bcsub($quantity, $reserved, 4)` (`StockLevel.php:102`). Scale 4 everywhere, matching the decimal(4) storage contract.
- Quantities flow as strings: `$line->quantity` → `decrementStock($quantity: string)` → bound directly; `quantity`/`quantity_before`/`quantity_after` are strings. No `(float)`, `Number()`, or `number_format` on the decrement path. **PASS.**

---

## 5. NO CompanyContext / app() DEPENDENCY (rule 20) — **PASS**

`grep app(\|CompanyContext` over `PosCoreReceiptProjection.php` returns **zero** hits. `decrementStock` derives every value from arguments sourced from the event/terminal: `tenantId = $event->tenant_id`, `companyId = $event->company_id`, `locationId = $terminal->location_id`. No container resolution, no implicit company context. The projection is safe to run in the queue worker with no context. **PASS.**

---

## 6. TEST QUALITY — **PASS-WITH-NITS**

Tests (a) variant-row-exactly-once, (b) product-row-exactly-once, and (d) replay-no-op are meaningful and would fail if the fix were reverted (the pre-fix code decremented the product-level decoy for a variant sale → (a) would fail). Good decoy design (both grains seeded).

**NIT 6a — test (c) "no double-decrement" is effectively a restatement of (a).** It applies a single projection of qty 1 and asserts the variant row dropped by 1 and the product row is untouched. It **never exercises** the retired draft path, so it cannot detect a resurrected double-decrement — the real guard for that claim is `ChokepointCompletenessTest`, not this test. The test name over-promises relative to what it proves.

**NIT 6b — test (e) "no float drift" is largely tautological.** `10.0001 - 0.3000 = 9.7001` is asserted by reading back `stock_levels.quantity` and `stock_movements.quantity_after` as strings. Both columns are `decimal(4)`, so the DB **re-rounds on store**: even a float subtraction (`(float)10.0001 - (float)0.3 = 9.700099999…`) would be stored and read back as `'9.7001'`. The assertion would therefore **pass with a float-cast bug present**. For a single subtraction the decimal(4) column masks any drift, so this test does not actually distinguish `bcsub` from float. To make it meaningful you'd need a sequence of operations whose float error survives a 4-dp round, or assert on the pre-store intermediate — otherwise it is decoration.

**Coverage gaps (not blockers):** the missing-variant-stock-row early-return (NIT 2a), the raw/resolved divergence (NIT 2b), and the insufficient-stock warning branch (`:947`) are untested.

---

## 7. Out-of-scope observation (flag, do not block this commit)

**Refund/void decrement direction.** A SALE_RECEIPT event with `invoice_type_code ∈ {REFUND, VOID}` is mapped to `ReceiptType::Return` (`:237`) yet still falls through to the unconditional `decrementStockForLines($…)` at `:319`. If refund line quantities are positive, this projection **decrements** stock on a refund (now against the variant row), which is the wrong direction and may also collide with `ReceiptReturnService` (manifest disposition `c`, live) restoring stock. This is **pre-existing** and untouched by this commit, but the variant change means refunds now deplete a *different* row than before. Recommend a separate task: gate `decrementStockForLines` on `ReceiptType::Sale`, or confirm refund line-quantity sign.

---

## Verdict summary

| Area | Verdict | Key evidence |
|---|---|---|
| 1. Exactly-once | PASS | manifest 410-Gone + `ChokepointCompletenessTest`; `:319` sole live decrement |
| 2. Variant scoping | PASS-WITH-NITS | `:925-934`, FK+partial-unique contain divergence; NIT 2a silent no-op (`:939`), NIT 2b raw id unvalidated (`:901`) |
| 3. Idempotency | PASS | `:152`, `:308`/`:310`, `lockForUpdate` `:937` |
| 4. Precision | PASS | bcsub/bccomp scale 4 (`:947`,`:962`); `StockLevel.php:102` |
| 5. No CompanyContext | PASS | zero `app()`/`CompanyContext` in file |
| 6. Test quality | PASS-WITH-NITS | NIT 6a test (c) restates (a); NIT 6b decimal(4) masks float drift |
| 7. (out of scope) | flag | refund/void decrement direction `:237`/`:319` |

**No BLOCK. Recommend merging after, ideally, addressing NIT 2a (log the missing-stock-row no-op) and acknowledging NIT 6b (decimal-4 test does not prove float-safety).**

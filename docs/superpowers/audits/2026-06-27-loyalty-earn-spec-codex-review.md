# Loyalty Earn Per Product Design Review

Review target: `docs/superpowers/specs/2026-06-27-loyalty-earn-per-product-design.md`

Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.loyalty-earn`

## BLOCKERS

1. The design can still double-count if any existing active earning rule remains on the program.

   The spec correctly says `earnPoints()` sums all active rules, and the code confirms it: `EarningProcessingService` fetches all active rules for the enrollment program and adds every calculated amount (`apps/api/app/Modules/Loyalty/Application/Services/EarningProcessingService.php:71`, `:101-109`). `EloquentEarningRuleRepository::findActiveByProgram()` filters only active/date windows, not rule type (`apps/api/app/Modules/Loyalty/Infrastructure/Repositories/EloquentEarningRuleRepository.php:41-54`).

   The spec's activation listener skips only when an `ItemOverride` rule already exists. That is not enough. If the program already has an active `Spend`, `Item`, `Category`, etc. rule, seeding `ItemOverride` creates exactly the double-count the owner locked out. Fix the activation/migration rule: the cutoff program must have exactly one active earn rule that participates in sale earning, or the seed must replace/deactivate incompatible active earn rules. Add a test with a pre-existing active `Spend` rule.

2. The proposed projection hook would award points on refunds/voids/training receipts unless it explicitly gates invoice type.

   `PosCoreReceiptProjection` handles `FiscalEventType::SALE_RECEIPT`, but inside that event family it resolves `invoice_type_code` values including `REFUND` and `VOID` into return receipts (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:227-237`, `:288-289`). The canonical payload explicitly models refunds with non-negative amounts, not negative line values (`apps/api/app/Modules/Fiscal/Domain/DTOs/SaleReceiptPayload.php:20-24`).

   If `earnLoyaltyPoints()` is added after `redeemVouchers()` for every projected receipt, a refund or void can credit positive points from `lineSubtotal`. Training receipts also carry `trainingFlag` and should not earn real loyalty balance. The spec needs an explicit guard, likely only plain sale receipts with `invoiceTypeCode === 'SALE'` and `trainingFlag === false`.

3. Catching all loyalty failures inside the projection makes non-duplicate earn failures permanent after the receipt row commits.

   The projection returns immediately when a `pos_receipts.fiscal_event_id` row already exists (`apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:151-153`) and also no-ops when the insert conflict loses (`:308-312`). That is correct for duplicate projection. But the spec also says the loyalty call is try/catch-wrapped so sale projection still succeeds.

   Sequential duplicate earn is safe: `earnPoints()` checks `findBySourceDocument()` before balance mutation and throws on an existing Earn (`apps/api/app/Modules/Loyalty/Application/Services/EarningProcessingService.php:65-69`), so swallowing that duplicate leaves balance unchanged. The problem is any non-duplicate transient failure before/inside loyalty earning. The receipt still commits; a replay then hits the `fiscal_event_id` guard and never retries earning. This violates "every purchase earns" in real failure cases. Keep the sale projection non-blocking if that is locked, but add a durable retry/compensation path or separate duplicate exceptions from real failures and persist failed earn attempts for replay.

## SHOULD-FIX

1. Tighten the idempotency claim: `findBySourceDocument()` is a read-before-write check, not a database uniqueness guarantee.

   The repository queries JSON metadata (`apps/api/app/Modules/Loyalty/Infrastructure/Repositories/EloquentTransactionRepository.php:65-69`), while the transaction table has indexes on `order_id` and enrollment fields but no unique index for `metadata.source_type/source_id` (`apps/api/database/migrations/tenant/2026_01_10_100006_create_loyalty_transactions_table.php:30-38`, `:54-58`). For the live projection path, the `pos_receipts.fiscal_event_id` unique guard is the durable first line of defense. For direct `earnPoints()` callers or concurrent manual `/loyalty/pos/earn`, the service can race. Either document that the exactly-once guarantee is projection-scoped, or add a DB-level source uniqueness strategy.

2. The projection-side module gate is not covered by route/UI gating.

   Existing Loyalty routes are under `module:Loyalty` (`apps/api/app/Modules/Loyalty/Presentation/routes.php:25`), and `ProductForm` already has `hasModule` available (`apps/web/src/features/inventory/ProductForm.tsx:136`). But the fiscal projection is not an HTTP route, and all module providers are globally registered (`apps/api/bootstrap/providers.php:54-87`). If a tenant has stale active loyalty data but the module is disabled, the proposed projection contract can still award points unless `SaleEarningService` checks module activation or the contract is a no-op when Loyalty is off.

3. Preserve the retired listener's timestamp semantics.

   The retired listener sent `timestamp => $receipt->posted_at ?? now()` (`apps/api/app/Modules/Loyalty/Application/Listeners/EarnPointsOnReceiptCompleted.php:79-83`). The new context should use the sealed fiscal event/device posting time, not `now()`, because `ruleApplies()` evaluates time/day conditions from `transactionData['timestamp']` when present and otherwise defaults to current time (`apps/api/app/Modules/Loyalty/Domain/Services/PointEarningService.php:162-190`). This matters if any active time rule survives, and it also keeps audit metadata sale-time based.

4. Be explicit that category-based legacy rule behavior is intentionally not relocated.

   The retired listener enriched items with `category_id` from `lines.product` (`apps/api/app/Modules/Loyalty/Application/Listeners/EarnPointsOnReceiptCompleted.php:69-76`). The proposed `SaleEarnContext` only needs product, quantity, and net line amount for `ItemOverride`, and the canonical `LineItemDTO` does not expose category (`apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/LineItemDTO.php:31-48`). That is fine only if all non-ItemOverride rules are eliminated for this sale path. Otherwise category rules will silently stop matching or, if category is looked up live, would violate the sealed snapshot boundary.

## NITS

1. `reward_type='multiplier'` is functionally harmless but semantically odd.

   `PointEarningService` uses `rule_type` and `reward_value`; it does not branch on `reward_type` for earn calculation (`apps/api/app/Modules/Loyalty/Domain/Services/PointEarningService.php:252-269`). The seeded `multiplier` value should still work, but a clearer internal meaning such as "fallback_rate" is not available in the existing schema. This is not a blocker.

2. Empty conditions are safe.

   `ruleApplies()` returns true for empty conditions (`apps/api/app/Modules/Loyalty/Domain/Services/PointEarningService.php:97-103`), and `findActiveByProgram()` does not skip empty-condition rules. The seeded `conditions={}` rule should apply to all qualifying sale transactions.

## FINAL VERDICT

Not sound to proceed directly to an implementation plan. The core shape is viable: the shared POS-to-Loyalty contract can use primitives from `BuyerDTO` and `LineItemDTO`, `lineSubtotal` is the correct net fallback base, `getScaleSafe()` is already used in the earn path, and a bcmath-only `ItemOverride` arm can avoid the existing float/int precision traps.

Fix the blockers first: enforce exactly one active sale-earning rule, skip refund/void/training receipts, and make non-duplicate loyalty failures recoverable despite the projection idempotency guard. After those changes, the design is sound enough to plan.

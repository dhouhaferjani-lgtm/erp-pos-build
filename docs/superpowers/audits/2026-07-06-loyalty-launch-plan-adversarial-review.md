# Adversarial Review — Loyalty Launch Roadmap + LB-1..LB-4 Plan (2026-07-06)

**VERDICT: READY-WITH-FIXES**

Mandatory fixes before dispatching implementation:
1. **[MAJOR-1]** Task 1 Step 5: do NOT add a `ProgramBootstrapService` parameter to `ParapharmacySeeder::run()` — it breaks `DemoPharmacySeeder`'s `parent::run()` call. Use seeder constructor injection (or `$this->container->make()`).
2. **[MAJOR-2]** LB-1 misses already-provisioned demo/staging tenants: `DemoPharmacySeeder`'s RE-RUN branch skips `parent::run()`, so `ensureActiveProgram` never fires on redeploy. Call it in the re-run closure (idempotent) or add an explicit deploy step.
3. **[MAJOR-3]** Task 7's constraint-translation branch (the actual atomic fix) is untestable in-process as designed; the plan's own Test 2 degrades to re-testing the pre-check. Extract the SQLSTATE translation into a unit-testable method.

Everything below cites code read on worktree `apps/erp.loyalty`, branch `feat/loyalty-launch` (`f15635a8e`).

---

## Factual verification (checklist A) — claims that HOLD

Both documents are unusually well-grounded. The following claims were verified true against code:

- `PosLoyaltyBalanceService::ensureAndGetBalance` — no-program → `notEnrolled` (`PosLoyaltyBalanceService.php:44-47`); phone-null member-missing → `notEnrolled` (`:54-59`); `findOrCreateMember` withTrashed/restore/re-point (`:90-130`). ✔
- POS balance route + `loyalty/pos/*` all gated `can:pos.operate_terminal` (`routes.php:188-212`). ✔ Cashier holds `pos.operate_terminal` (`RolesAndPermissionsSeeder.php:525`; `usePermissions.ts:112`). ✔
- `POST /loyalty/members/{memberId}/enroll` gated `can:loyalty.manage` (`routes.php:240-242`). ✔ No by-partner endpoint exists. ✔
- `EventServiceProvider` registers `SeedDefaultEarningRuleOnProgramActivated` on `ProgramActivated` (`:70-71`) and `EarnPointsOnReceiptCompleted` on `ReceiptCompleted` (`:73-74`). ✔
- `ReceiptCompleted` is dispatched from exactly ONE site: `ReceiptPaymentService.php:446` (`DB::afterCommit`). ✔
- `POST /pos/receipts` and `POST /pos/receipts/{id}/payments` are retired 410 (`POS/routes.php`, the two closures). ✔
- Projection `insertReceiptOnConflictDoNothing` returns null on conflict → early `return` at `PosCoreReceiptProjection.php:314-317`, BEFORE `earnLoyaltyPoints($receiptId,$event,$view,...)` at `:323`. The "early-return-before-earn" claim is correct. ✔
- `SaleEarningService` sends `'items' => []` (`:59`) and its docblock calls the listener "retired" (`:20-23`) while the listener is live — divergence + stale-docblock claims correct. ✔ Listener `Log::error`s ALL failures incl. duplicates (`EarnPointsOnReceiptCompleted.php:95-101`) and floats `unit_price`/`totalAmount` (`:75`,`:80`). ✔
- Dedupe is TOCTOU `whereJsonContains(metadata->…)` with no DB constraint (`EloquentTransactionRepository.php:65-70`); the ≤0 and no-rules early returns do NOT persist rows (`EarningProcessingService.php:73-89`,`:111-127`), so no phantom source rows for the backfill. ✔ `findBySourceDocument` has ONE caller. ✔
- `LineItemDTO.productId` / `.quantity` are the fields Task 9 maps (`LineItemDTO.php:39,64`); `$view->lineItems()` returns `list<LineItemDTO>` and is in scope at `earnLoyaltyPoints` (`:863-887`). ✔
- `PointEarningService::calculateItemPoints/Category/Quantity` consume `items[].{product_id,category_id,quantity}` and int-cast quantity (`:293-348`). Task 9's items shape matches. ✔ reward_value '5' × qty 2 = '10.000' expectation is correct. ✔
- `loyalty_transactions` schema/`Transaction::$fillable` (`Transaction.php:50-65`), `LoyaltyProgram`/`EarningRule` fillables, enums `ProgramType::Points`/`ProgramStatus::Active`/`EarningRuleType::Spend`, factories, `EnrollmentRepositoryInterface::findByMemberAndProgram`, `LoyaltyProgramRepositoryInterface::findById/findByTenantAndStatus`, `MemberResolver::resolveByContactOrPartner`, `MemberEnrollmentService::enroll(memberId,programId,?welcomeBonus)` — all exist as the plan states. ✔
- `usePermissions.ts:161-162` (`loyalty.view|manage = ['admin','manager']`), `MODULE_PERMISSIONS.loyalty = ['loyalty.view']` (`:240`). ✔
- `CustomerLoyaltyBadge` renders inert `loyalty.joinsOnPurchase` span when `!balance.enrolled`; `useLoyaltyBalance` returns `LoyaltyBalance|null` and has exactly ONE production consumer (the badge). ✔
- `EnrollMemberRequest` constructor-injects `CompanyContext` + uses `ScopedExists::tenant` (pattern Task 3 reuses). ✔ `ScopedExists` exists. ✔
- `PartnerDetailPage` plug-in pattern `showVehiclesTab`/`showDepositsTab` at `:206-210`, `hasModule` via `useCompanyConfig` (`:137`), `isCustomerContext` (`:153`). ✔
- Test harness files all present (`LoyaltyPOSControllerTest` two-tenant setUp `:41-77`, permission-denied → 403 `:187`; `PosLoyaltyBalanceTest`, `PosCoreReceiptProjectionLoyaltyEarnTest`, `SaleEarningServiceTest`, `LoyaltyTenantIsolationTest`). ✔
- SQLSTATE accessor: `($e->errorInfo[0] ?? null) === '23505'` is the established repo idiom (`DuplicateBarcodeException.php:15`, `ReceiptReturnService.php:523`). Task 7's catch is correct. ✔

---

## Findings

### MAJOR

**MAJOR-1 — Task 1 Step 5 method-injection breaks `DemoPharmacySeeder`.**
`ParapharmacySeeder::run()` is declared `public function run(): void` (`ParapharmacySeeder.php:284`). `DemoPharmacySeeder::run()` calls `parent::run();` with no arguments (`DemoPharmacySeeder.php:367`). Laravel container method-injection ONLY happens when the framework invokes `run()` via `$this->call(...)`; a direct `parent::run()` is a plain PHP call and passes zero args. Adding a required `ProgramBootstrapService $loyaltyBootstrap` parameter to `run()` (the plan's stated fallback, plan lines 168-169) will throw `ArgumentCountError` on `DemoPharmacySeeder` first-run — i.e. it fatals the launch demo account seeding.
*Fix:* constructor-inject `ProgramBootstrapService` into `ParapharmacySeeder` (rule-13 compliant; seeders are container-resolved, `DemoPharmacySeeder` inherits the ctor and is also container-resolved). Alternative: `$this->container->make(ProgramBootstrapService::class)` inside `run()` (not the banned `app()` helper). Do NOT change the `run()` signature.

**MAJOR-2 — LB-1 does not reach an already-provisioned demo/staging tenant.**
`DemoPharmacySeeder::run()` only calls `parent::run()` on FIRST RUN; on RE-RUN it deliberately skips the parent (`DemoPharmacySeeder.php:369-388`, to avoid the `createParapharmacyTenant()` tenant-delete). The bootstrap is wired into `ParapharmacySeeder::run()` (Task 1 Step 5), so on any redeploy against an EXISTING demo tenant, `ensureActiveProgram` is never called and the demo tenant stays program-less — defeating LB-1 for the one account the launch demos on ([[project_demo_pharmacy_account]]). The plan's deploy note (plan line 23) covers migrate + `permission:cache-reset` but not this.
*Fix:* also invoke `ensureActiveProgram($this->tenant->id, …)` inside the re-run `$tenant->run(function () {...})` closure (`DemoPharmacySeeder.php:391+`) — it is idempotent (returns the existing active program untouched). Add a bullet to the deploy note for tenants provisioned before this change.

**MAJOR-3 — Task 7's constraint-translation branch has no real test coverage.**
The plan keeps the global pre-check `findBySourceDocument` (`EarningProcessingService.php:66-69`) AND adds a partial unique index, both keyed on `(source_type, source_id)`+`transaction_type='earn'`. The pre-check is strictly BROADER (global vs the per-enrollment index), so in any single-connection `RefreshDatabase` test the pre-check throws first and the `catch (QueryException)` 23505→`InvalidArgumentException("already earned")` branch is unreachable. The plan's Test 2 (plan lines 519-531) visibly wrestles with this and settles on "the PRE-CHECK will catch it — also fine," i.e. it does NOT exercise the catch. The atomic fix therefore ships with zero direct coverage (Test 1 proves only that the raw index rejects a duplicate INSERT, not that `earnPoints` translates it).
*Fix:* extract the translation into a small pure method, e.g. `private function translateEarnDuplicate(QueryException $e, string $sourceType, string $sourceId): InvalidArgumentException` (returns/throws on `errorInfo[0]==='23505' && str_contains(msg,'loyalty_txn_earn_source_unique')`), and unit-test it with a fabricated `QueryException`. Keep Test 1 for the index. Drop the confused Test 2.

### MINOR

**MINOR-4 — Spec G1 factual error re: DemoPharmacySeeder extras (plan is right, spec is wrong).**
Spec line 23 states "DemoPharmacySeeder doesn't even enable the extra" and LB-1 (spec line 56) tells the implementer to "add `Loyalty` to `enabled_extras`" for it. But `DemoPharmacySeeder` does not override `createParapharmacyTenant()` or `enabled_extras` (grep: none), and inherits `enabled_extras => ['BatchExpiry','Loyalty','Ecommerce']` (`ParapharmacySeeder.php:473`, inside `createParapharmacyTenant()`) via `parent::run()`. So the demo tenant DOES get the Loyalty extra. The plan (Task 1, "no separate change needed") is correct; the spec's LB-1 DemoPharmacySeeder bullet is redundant and its ground-truth G1 is stale.
*Fix:* correct the spec so an implementer doesn't add a dead `enabled_extras` edit; the only real Demo gap is MAJOR-2 (re-run bootstrap), which the spec does not mention.

**MINOR-5 — Task 3 module-gate test should pin 403.**
`RequireModule` does `abort(403, …)` (`app/Http/Middleware/RequireModule.php`). The plan hedges "403/404 per RequireModule behavior" (plan line 303). Pin the assertion to `assertStatus(403)`.

**MINOR-6 — Listener has no receipt-type guard; LB-4's "refunds earn nothing" is only tested on the projection path.**
The projection guards refunds/voids/training explicitly (`PosCoreReceiptProjection.php:872`). `EarnPointsOnReceiptCompleted` has NO such guard — it earns for ANY `ReceiptCompleted` and relies implicitly on a non-positive `totalAmount` producing ≤0 points. `ReceiptCompleted` carries no receipt type (`ReceiptCompleted.php:20-25`). If a server-authored refund/exchange fires `ReceiptCompleted` with a non-negative total (even swap), the listener earns. Task 8 edits this listener but adds no type guard, and the LB-4 refund/void test is written against the projection file only.
*Fix (optional but recommended):* add an explicit non-Sale / non-positive-base guard in the listener while you are in it, and add one listener-path "refund earns nothing" test.

**MINOR-7 — Task 9 variant + fractional-quantity edges.**
`LineItemDTO.productId` is the PARENT product identity in both payload versions (`LineItemDTO.php:26-27,39`). Category lives on the parent (`products.category_id` — confirmed via `2026_06_28_110000_add_brand_to_products.php:14` `after('category_id')`), so Category rules resolve correctly. But an **Item** rule keyed on a variant's own id would never match device sales through the projection (it only ever sees parent ids). Also `calculateItemPoints/getTotalQuantity` do `(int) $item['quantity']` (`PointEarningService.php:302,423`), truncating fractional canonical quantities like `"2.500"` → 2. Both are acceptable for a parapharmacy launch (products, whole units) but should be documented as known limits, not silently shipped.

**MINOR-8 — Task 3 has no explicit cross-tenant test for the new partner endpoints.**
Structurally the endpoints are safe (db-per-tenant isolates `DB::table('partners')`; `MemberResolver` filters `tenant_id`), but a brand-new partner-scoped surface warrants one negative test (partner from tenant B, acting as tenant A → 404), mirroring `LoyaltyTenantIsolationTest`. The plan's Task 3 test list omits it.

**MINOR-9 — Task 4 must add the `usePermissions` import to `PartnerDetailPage`.**
`PartnerDetailPage.tsx` currently imports `useCompanyConfig` but not `usePermissions` (grep at `:137` only). The plan's `showLoyaltyCard` uses `hasPermission('loyalty.enroll')` (plan lines 425-430) but never states the import must be added.

### NIT

**NIT-10 — Dedupe layers keyed differently (by design, note it).** The new unique index is per-`(enrollment_id, source_type, source_id)` but the pre-check `findBySourceDocument` is GLOBAL, so the index only ever fires on a true same-enrollment concurrent race (exactly the stated defect) — correct, but means a future "multi-program earn" change must also relax the global pre-check; the index already supports per-enrollment. Worth a one-line comment in the migration.

**NIT-11 — Task 5 test (b) wording.** "rate === null keeps the old quiet state, no button": when `!enrolled && rate === null` the badge returns `null` entirely (`CustomerLoyaltyBadge.tsx:19` `if (!balance.enrolled && estimate === null) return null`) — there is no "quiet state," just nothing. Outcome (no button) still holds; fix the description.

**NIT-12 — Task 8 `(string) $event->totalAmount` is a no-op** since `ReceiptCompleted.totalAmount` is already a string (`ReceiptCompleted.php:24`). The real rule-19 fix is removing the `(float)`. Harmless; noted so the reviewer isn't surprised the cast "changes nothing."

---

## Scope assessment (checklist D)

- **LB set is right.** LB-1 (seed program) is correctly the top blocker — without an active program every earn/balance/enroll no-ops (`PosLoyaltyBalanceService.php:44-47`). LB-2/LB-3/LB-4 are all genuinely launch-shaped. PL-1 (pay-with-points) correctly deferred (redeem FE is dead code; earn-only launch is defensible).
- **Two-PR split is sound.** Branch A (Tasks 1-6) and Branch B (Tasks 7-9) touch mostly disjoint files. The only shared file is `SaleEarningService.php` — Branch A does not touch it; Branch B touches its docblock (Task 8) and `earnForSale` items (Task 9). No overlap with Branch A → low conflict risk. Base Branch B on `origin/dev` (not on Branch A) as the plan says, and rebase after A merges. Acceptable.
- **Nothing launch-blocking is missing** beyond MAJOR-2 (demo re-run) — that is the one true scope gap, and it is a wiring omission, not a missing feature.

## Could NOT verify (and why)
- **Exchange-path liveness of the listener.** `ReceiptCompleted` is dispatched only from `ReceiptPaymentService.php:446`, but I did not trace every caller of that method to confirm the listener is actually reachable at runtime (vs. fully dead now that `POST /pos/receipts/{id}/payments` is 410). Non-blocking: keeping the listener is harmless either way, and the plan does not deregister it.
- **Spec G6** (web `POSPage` unrouted, `lookupMember` uncalled, `LoyaltyRewardSelector` orphaned) — did not trace `apps/web` routes; it is PL-2 (post-launch) and does not gate this build.
- **Runtime/test execution.** Read-only review; no tests, PHPStan, or preflight were run.
- **Exact `products.category_id` migration.** Column existence is proven by `after('category_id')` in `2026_06_28_110000_add_brand_to_products.php`, but I did not open the specific migration that adds it; `DB::table('products')->pluck('category_id','id')` (Task 9) is valid given `id` (uuid PK, `create_products_table.php:17`) and `category_id` both exist.

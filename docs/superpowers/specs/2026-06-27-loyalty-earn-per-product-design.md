# Design — Loyalty earn (per-product points on purchase) + reward-catalog redemption

> **Cutoff B2 for the parapharmacy demo.** Goal (owner, 2026-06-27): a simple loyalty program where
> the customer **earns points on every purchase**, points **assigned per product**, with a
> guaranteed fallback so every purchase earns even before per-product values are set. Treat the demo
> client as having paid for the Loyalty add-on.
>
> **Source handover:** `docs/handoff/HANDOVER-cutoff-loyalty-earn.md` +
> `docs/handoff/HANDOFF-loyalty-product-fields.md`.

## Scope

**IN**
- Earn points on a live POS sale (the fiscal-event projection path), per-product + 1 TND = 1 point
  fallback.
- Persist a per-product "Loyalty points" override (Loyalty-owned) and surface it in the product
  editor, gated on the Loyalty module.
- Reward-catalog redemption: **verify-only** — exercise the existing `/loyalty/pos/redeem` path in
  E2E; add a test only if a real gap surfaces. Do not rebuild.

**OUT (do not build this cutoff)**
- **Pay-with-points tender** — deferred to the integration branch (`LoyaltyPoints` case in
  `PaymentInstrumentKind`, POS tender UI, fiscal-event payload support, GL/fiscal decision).
- **"Eligible for discounts" field** — stays an unwired, module-gated placeholder (Promotions
  concern, different boundary).
- Any change to the retired `ReceiptCompleted` / `EarnPointsOnReceiptCompleted` path.

## Locked decisions (owner, 2026-06-27)

1. **Earn model = override wins per line (exclusive).** A line whose product has a per-product points
   value earns exactly `points_value × quantity`. A line with no override earns the fallback
   `line_amount × fallbackRate` (default `fallbackRate = 1`, i.e. 1 TND = 1 point). **No line is
   double-counted.**
2. **Persistence = module-owned `loyalty_product_overrides` table** (Loyalty module, FK
   `product_id`). The Product master stays clean; the override is read/written only through the
   Loyalty module's public surface.
3. **Engine integration = one engine-native unified rule type.** Add a single
   `EarningRuleType::ItemOverride` arm to the shared `PointEarningService`; seed exactly one such rule
   on program activation (`reward_value` = fallback rate). This reuses the engine's idempotency, tier
   multipliers, caps, and transaction-write machinery, and keeps the rule admin-visible/editable.
   - **Why one rule, not two:** `earnPoints()` evaluates *all* active rules and **sums** them. A
     basket-level `Spend` rule + an `Item` rule would double-count override lines, violating the
     exclusive model. One per-line rule expresses both behaviors without double-counting.
4. **Member resolution:** resolve by `pos_receipts.contact_id` → `partner_id` (polymorphic
   `loyaltyable_type`/`loyaltyable_id`), looping active enrollments. **No customer ⇒ no earning**
   (expected, silent).
5. **Redemption: verify-only** (see Scope).

## Context grounded in the codebase (verified 2026-06-27)

- **Earn engine is ready and string-safe.** `EarningProcessingService::earnPoints(string $enrollmentId,
  array $transactionData, string $sourceType, string $sourceId, ?string $description)` canonicalizes
  money via `CurrencyScale::bcformat()` and resolves scale with `getScaleSafe($currency, 3)` (no
  CompanyContext required). Idempotency via
  `transactionRepository->findBySourceDocument($sourceType, $sourceId)` — it **throws**
  `InvalidArgumentException` on a duplicate Earn; callers must catch.
- **Rule engine:** `PointEarningService::calculateBasePoints()` is a pure domain service (no repos),
  `match`-ing on `EarningRuleType` (`Spend`, `Item`, `Category`, `Quantity`, `Visit`, `Threshold`,
  `Time` — at `app/Modules/Loyalty/Domain/Enums/EarningRuleType.php`). The existing `Item` arm casts
  `quantity` to `(int)` (truncates fractional qty) and uses one flat `reward_value` — **not** usable
  for differing per-product values, hence the new arm.
- **Cross-module pattern:** `app/Shared/Contracts/` holds `Fiscal/`, `Treasury/`, `Compliance/`
  interfaces, bound in the owning module's provider and constructor-injected into consumers (e.g.
  `Shared/Contracts/Fiscal/PaymentMethodResolver` bound in `TreasuryServiceProvider`, injected into
  `PosCoreReceiptProjection`). **POS has zero Loyalty references today.**
- **Projection trigger site:** `PosCoreReceiptProjection::apply()` persists the receipt then runs
  synchronous side-effect blocks: `writeLines()`, `writeVatBreakdown()`, `writePayments()`,
  `redeemVouchers()` (try/catch-wrapped — the block to mirror), `decrementStockForLines()`
  (~`PosCoreReceiptProjection.php:315-319`). The projection's first statement is an idempotency guard:
  `if (Receipt::where('fiscal_event_id', $event->id)->exists()) return;`. Buyer is read **only** from
  the sealed payload snapshot (`$view->buyer`: `customerId`/`contactId`/`name`/`taxNumber`); lines
  from `$view->lineItems` (`productId`, `quantity`, `unitPrice`, `lineSubtotal`, … as bcformat
  strings); currency from `$view->payload->currencyCode`.
- **Program activation:** `ProgramManagementService::activateProgram()` dispatches `ProgramActivated`
  (`programId`, `tenantId`, `programName`, `activatedAt`) via `DB::afterCommit`. **No listener exists
  yet.** Listeners register in `app/Providers/EventServiceProvider.php` `$listen`. No unique
  constraint on one-active-program-per-tenant (fine for the demo).
- **Product editor:** `apps/web/src/features/inventory/ProductForm.tsx` has **no** loyalty field yet.
  Module-gated sections follow the parapharmacy pattern (`{isParapharmacy && (<div id="section-…">…)}`,
  ~line 1272). Module gating via `useCompanyConfig().hasModule('Loyalty')`. Backend gating via
  `module:Loyalty` middleware (`app/Http/Middleware/RequireModule.php`); Loyalty routes already group
  under `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class,'module:Loyalty']`.
- **`EarningRuleFactory` does not exist** — create it. `LoyaltyProgramFactory` exists.

## Architecture

### Components and boundaries

```
POS module                          Shared/Contracts/Loyalty            Loyalty module
-----------                         ------------------------            --------------
PosCoreReceiptProjection ── calls ─▶ LoyaltyEarningContract ── bound ──▶ SaleEarningService (Application)
  (after receipt persisted,            ::earnForSale(SaleEarnContext)      ├─ resolve member (contact→partner)
   try/catch like redeemVouchers)                                          ├─ loop active enrollments
                                                                           ├─ read loyalty_product_overrides (own table)
                                                                           ├─ enrich items: {product_id, quantity,
                                                                           │    line_amount, points_override}
                                                                           └─ EarningProcessingService::earnPoints()
                                                                                └─ PointEarningService (ItemOverride arm)
```

**Why this split:** POS depends only on a `Shared/Contracts/Loyalty` interface (rule 6) and passes
raw, already-sealed sale data (a DTO of primitives/strings). The only place that reads the
Loyalty-owned override table is inside the Loyalty module. The pure domain service
(`PointEarningService`) stays repo-free; override values are injected into `transactionData` by the
Application-layer `SaleEarningService`.

### Data model — `loyalty_product_overrides`

New tenant migration (`database/migrations/tenant/`):

| Column         | Type            | Notes                                              |
|----------------|-----------------|----------------------------------------------------|
| `id`           | uuid PK         |                                                    |
| `tenant_id`    | uuid            | tenant scope (consistent with sibling tables)      |
| `product_id`   | uuid            | FK `products(id)`, `onDelete('cascade')`           |
| `points_value` | decimal(15,4)   | nullable; null/absent ⇒ no override (use fallback) |
| timestamps     |                 |                                                    |

Unique index on `product_id` (one override per product). Index `[tenant_id, product_id]`.

Model `LoyaltyProductOverride` (`Loyalty/Domain/Entities/`), `points_value` cast `decimal:4`.
Repository `LoyaltyProductOverrideRepositoryInterface` (Domain/Repositories) +
`EloquentLoyaltyProductOverrideRepository` (Infrastructure), bound in `LoyaltyServiceProvider`:
`findByProduct(productId): ?…`, `upsert(productId, ?pointsValue): …`,
`getMany(productIds[]): array<productId,pointsValue>` (batch read for the sale path).

### Public contract (cross-module surface)

`app/Shared/Contracts/Loyalty/LoyaltyEarningContract.php`:

```php
interface LoyaltyEarningContract
{
    public function earnForSale(SaleEarnContext $context): void;
}
```

`SaleEarnContext` (immutable DTO of primitives — `app/Shared/Contracts/Loyalty/SaleEarnContext.php`):
`tenantId`, `?contactId`, `?partnerId`, `currency` (string), `sourceType` (`'pos_receipt'`),
`sourceId` (the `pos_receipts.id`), `?receiptNumber`, `totalAmount` (numeric-string),
`lines: SaleEarnLine[]` where `SaleEarnLine = {productId, quantity (numeric-string),
lineAmount (numeric-string)}`. **All money/qty are strings (rule 19).**

Bound in `LoyaltyServiceProvider::register()` →
`App\Modules\Loyalty\Application\Services\SaleEarningService`.

`SaleEarningService::earnForSale()` (relocates + replaces the retired listener's logic):
1. If `contactId` and `partnerId` both null ⇒ return (no member).
2. Resolve `LoyaltyMember` by `loyaltyable_type='contact' & loyaltyable_id=contactId` (tenant-scoped),
   else by `partner` / legacy `customer_id = partnerId`. None ⇒ return.
3. Batch-read overrides for all `lines[].productId` from the override repo.
4. Build `transactionData = { amount: totalAmount, currency, items: [{ product_id, quantity,
   line_amount, points_override (string|null) }], timestamp }`.
5. For each **active** enrollment: `try { earningProcessingService->earnPoints(enrollmentId,
   transactionData, 'pos_receipt', sourceId, "POS receipt #{receiptNumber}"); }
   catch (\Throwable $e) { Log::error(...); }` — duplicate-source throw is swallowed (idempotent).

> **Known limitation (documented, not fixed this cutoff):** `findBySourceDocument` keys on
> `(sourceType, sourceId)` globally, so a member enrolled in *multiple* programs earns on only the
> first enrollment (the 2nd sees the 1st's txn and is skipped). The demo runs a single program, so
> this is out of scope. Flagged for the integration branch.

### Engine arm — `EarningRuleType::ItemOverride`

- Add enum case `ItemOverride = 'item_override'`.
- `PointEarningService::calculateBasePoints()` new arm → `calculateItemOverridePoints($rewardValue,
  $transactionData, $scale)`:
  - For each `item` in `transactionData['items']`:
    - `override = item['points_override'] ?? null`
    - if `override !== null`: `linePts = bcmul(override, (string)item['quantity'], scale+4)`
    - else: `lineAmount = item['line_amount'] ?? '0'; linePts = bcmul(lineAmount, $rewardValue, scale+4)`
    - `total = bcadd($total, $linePts, scale+4)`
  - return `PointsAmount::fromNumericString(CurrencyScale::bcformat($total, $scale))`.
  - **Quantity stays a string through bcmath** (no `(int)` truncation — supports fractional qty).
  - Robust to absent fields (manual `/loyalty/pos/earn` calls that don't enrich items): missing
    `points_override` ⇒ fallback branch; missing `line_amount` ⇒ contributes 0.

### Projection wiring

In `PosCoreReceiptProjection`:
- Constructor-inject `private readonly LoyaltyEarningContract $loyaltyEarning`.
- After the receipt + lines are persisted (after `redeemVouchers(...)`, before/after stock — order
  independent), add `earnLoyaltyPoints($receiptId, $event, $view)`:
  - Build `SaleEarnContext` from `$view->buyer`, `$view->lineItems` (productId, quantity,
    `lineSubtotal` as `line_amount`), `$view->payload->currencyCode`, `sourceId=$receiptId`.
  - Wrap the call in `try/catch + Log::error` so a loyalty failure never breaks the sale projection
    (mirror `redeemVouchers`).
- **Idempotency / replay:** the projection's `fiscal_event_id` guard already prevents re-running for
  the same event; `findBySourceDocument('pos_receipt', receiptId)` is the second line of defense.

> **`line_amount` semantics:** use the canonical **net** line subtotal (`$view->lineItems[].lineSubtotal`)
> for the fallback base, consistent with how the rest of the projection treats line economics. The
> fallback rate then reads "1 point per net TND". (POS `unit_price` is tax-inclusive — do **not** use
> it for the fallback base; precision-contract `unit_price` caveat.)

### Default-rule seed on activation

- New listener `SeedDefaultEarningRuleOnProgramActivated` (Loyalty Application/Listeners), registered
  in `EventServiceProvider` under `ProgramActivated::class`.
- On activation, if the program has no `ItemOverride` rule, persist one via the earning-rule repo:
  `rule_type=ItemOverride`, `reward_value='1'` (1 pt / net TND fallback), `reward_type='multiplier'`,
  `priority=1`, `is_active=true`, `conditions={}`.
- Idempotent: skip if an `ItemOverride` rule already exists for the program (re-activation safe).
- Add `EarningRuleFactory` (does not exist) for tests/seeding.

### Frontend — gated product-editor field

- New gated section in `ProductForm.tsx`, rendered only when `hasModule('Loyalty')`, mirroring the
  parapharmacy block. Single field "Loyalty points" (`<QuantityInput>`-style string input; points are
  not currency — a plain numeric string field with the points regex), all labels via `t()`
  (`catalog:editor.sectionLabels.loyalty` + field keys).
- **Not** routed through `buildProductPayload` — the override is independent of the product master:
  - Load: `GET /loyalty/products/{productId}/points` → `{ points_value: string|null }`.
  - Save: `PUT /loyalty/products/{productId}/points` with `{ points_value: string|null }`.
  - Both under the existing Loyalty route group (`module:Loyalty` + `SetPermissionsTeam` +
    `can:loyalty.manage` or a suitable existing permission). New controller methods on a Loyalty
    Presentation controller; FormRequest with the points regex `/^\d+(\.\d{1,4})?$/` (nullable).
  - The editor fires the points mutation alongside the product save when the field is dirty and the
    product exists (on create, persist after the product id is known).

## Error handling

- Loyalty earn failures in the projection: caught + logged, sale projection still succeeds.
- Duplicate Earn (replay / double-fire): `earnPoints` throws → caught in `SaleEarningService` →
  balance unchanged.
- No member / no customer: silent no-op.
- Override endpoints: `module:Loyalty` returns 403 when the add-on is off; invalid `product_id`
  (non-UUID) validated before query (rule: `Str::isUuid` guard).

## Testing (TDD, scoped `--filter` only — never the full suite, never `--parallel`)

Backend (PHPUnit, `--filter` by class):
1. `PointEarningService` ItemOverride arm: override line → `points×qty`; non-override line →
   `lineAmount×rate`; mixed cart → exclusive sum; fractional qty not truncated; absent fields safe.
2. `SaleEarningService`: member resolution (contact, partner, none → no-op); override batch-read +
   enrichment; per-enrollment earn; money/qty passed as strings.
3. **Replay test (rule 20):** apply the same fiscal event/`SaleEarnContext` twice → exactly one Earn
   transaction, enrollment balance unchanged. Clear CompanyContext before apply (worker reality).
4. Projection: a sale with an enrolled buyer credits points once with correct per-product totals; a
   sale with no buyer credits nothing; a loyalty failure does not abort the projection.
5. `SeedDefaultEarningRuleOnProgramActivated`: activation seeds exactly one `ItemOverride` rule;
   re-activation does not duplicate.
6. Override endpoints: GET/PUT happy path; 403 when `module:Loyalty` off; points-regex validation;
   non-UUID product guard.

Frontend (Vitest):
7. Editor shows the loyalty field only when `hasModule('Loyalty')`; hidden otherwise.
8. Field loads existing `points_value` and saves via the PUT mutation (string payload).

E2E (parapharmacy campaign, manual):
9. Ring a POS sale for an enrolled member → points credited once, correct per-product totals.
10. Redeem a catalog reward via `/loyalty/pos/redeem` (verify-only).

## File-target map

| Concern                         | File                                                                                  | Action |
|---------------------------------|---------------------------------------------------------------------------------------|--------|
| Override table                  | `database/migrations/tenant/<ts>_create_loyalty_product_overrides_table.php`           | new    |
| Override model/repo             | `Loyalty/Domain/Entities/LoyaltyProductOverride.php`, `…/Repositories/*`, Infra impl   | new    |
| Public contract + DTO           | `app/Shared/Contracts/Loyalty/{LoyaltyEarningContract,SaleEarnContext,SaleEarnLine}.php`| new    |
| Sale earning service            | `Loyalty/Application/Services/SaleEarningService.php`                                  | new    |
| Engine arm                      | `Loyalty/Domain/Enums/EarningRuleType.php`, `Domain/Services/PointEarningService.php`  | edit   |
| Activation seed                 | `Loyalty/Application/Listeners/SeedDefaultEarningRuleOnProgramActivated.php` + EventSP  | new/edit |
| Earning-rule factory            | `database/factories/Loyalty/EarningRuleFactory.php`                                    | new    |
| Projection trigger              | `POS/Application/Projections/PosCoreReceiptProjection.php`                             | edit   |
| Override endpoints              | `Loyalty/Presentation/Controllers/*`, `Loyalty/Presentation/routes.php`, FormRequest   | new/edit |
| Provider bindings               | `Loyalty/Providers/LoyaltyServiceProvider.php`                                         | edit   |
| Product editor field            | `apps/web/src/features/inventory/ProductForm.tsx` + a loyalty fields component + i18n   | new/edit |

## Out-of-scope guard (restate)

Do **not**, in this cutoff: build pay-with-points tender; wire "Eligible for discounts"; touch the
retired `ReceiptCompleted`/`EarnPointsOnReceiptCompleted` path; fix the multi-enrollment idempotency
limitation; rebuild redemption.

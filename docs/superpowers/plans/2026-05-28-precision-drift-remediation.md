# Precision & Scale-Drift Remediation Plan — AutoERP

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

## Execution Progress Tracker

> Updated as each phase lands. Committed with the corresponding work.

- [x] **Phase 0** — Canonical contract layer (tip `85248dd09`; PR merged/ready). CurrencyScale hardened, resolver fail-loud, QuantityScale + resolver, mock default 3, frontend formatters, shared inputs.
- [x] **Rebase** onto `origin/dev` (PR #151 merged at `83ed1f15d`) — branch already forks from it; tasks 3.10/3.11/4.3/5.2 unblocked.
- [x] **Phase 0 follow-up (resolver fallout)** — Task 0.2 Step 4 completed: the fail-loud resolver surfaced 185 `UnboundCompanyContextException` errors across 34 direct-service test classes (handoff's "all green" was inaccurate). Bound `app(CompanyContext::class)->setCompanyId($company->id)` in those tests' setUps (EUR factory default → scale 2 == prior silent fallback, so zero fixture changes). Resolver throw (locked decision) untouched. Suite now green except 1 pre-existing `TenantCreationTest` tenancy failure (untouched by this branch).
- [x] **Phase 1** — Storage scale alignment. 1.1 services.tax_rate→(6,3) + loyalty_programs.welcome_bonus_points→(15,3); 1.2 pos_orders/lines monetary 4→3 (PG pre-check abort guard); 1.3 quantity→(15,4) across cart/marketplace/pricing/workshop/pos (9 tables + casts + quantity write-sites); 1.4 decimal:N casts (Product/Service/Account/Treasury/Coupon/Promotion/Receipt + User/Terminal max_discount_percent float→decimal:2 with frontend adaptation). Skipped billing_invoice_items.quantity (SaaS integer-qty, plan default). NF525 fiscal fixture regenerated (Quantite 3→4 — deliberate canonical-scale change). TerminalResource (float) left for Phase 6.2.
- [x] **Phase 2** — Per-unit decimal_places settings UI (2.1 backend, 2.2 frontend, 2.3 seed defaults) — PR #154 (merged)
- [x] **Phase 3** — Backend service bcmath sweep (3.1–3.16) — PR #156 (merged); incl. context-safe scale resolution
- [x] **Phase 4** — Ingress validators regex sweep (4.1–4.14) — PR #155 (merged); numeric+regex non-breaking pattern
- [x] **Phase 5** — JSONB content tightening (5.1–5.5) — PR #157 (merged). **5.6 CHECK constraint GATE-STOPPED/escalated** (infeasible as plain PG CHECK)
- [x] **Phase 6** — Resources cleanup (6.1–6.3) — PR #154 (merged)
- [x] **Phase 7** — Value-object refactor (7.1 Money, 7.2 PointsAmount, 7.3 LoyaltyBalance) — PR #158 (merged); +PHP8.3 parse hotfix #160
- [x] **Phase 8** — Notifications currency-aware scale (8.1) — PR #154 (merged)
- [x] **Phase 9** — Seeders + fixtures alignment (9.1–9.3) — PR #161 (group G)
- [x] **Phase 10** — Frontend MoneyInput/QuantityInput rollout (10.1–10.7) + built the missing Phase 0.7 components — PR #159
- [x] **Phase 11** — Regression guards PHPStan (11.1/11.2, baselined) + ESLint (11.3/11.4, ratcheted) + CI (11.5) — PRs #159/#161
- [x] **Phase 12** — Docs (CLAUDE.md §19 + docs/architecture/precision-contract.md) + REALIGNMENT-LOG (syneriva) — PR #161

**Goal:** Land a world-wide-ready precision contract across AutoERP: canonical currency scale 3 (TND/LYD/JOD/KWD/OMR/BHD as the floor; EUR/USD/GBP and others displayed per `getDecimals(currency)`), canonical quantity scale 4 with per-unit `decimal_places` driving display and validation, and remove every float/IEEE-754 entry point in money or quantity pipelines.

**Architecture:**
1. Phase 0 lands the canonical contract layer: hardened `CurrencyScale`, a new `QuantityScale` analog resolving per-unit `units.decimal_places`, currency-aware mock defaults, frontend formatter consolidation, and shared `<MoneyInput>` / `<QuantityInput>` components. No consumer touches business logic until this layer is green.
2. Phases 1–8 fix the storage tier (schema widening, casts, defaults), the service tier (bcmath sweep, WAC, ReturnVat symmetry, payroll, UnitConversion, loyalty), the ingress tier (FormRequest regex sweep across ~120 sites), the JSONB tier (opening-balance, inventory counting events, loyalty conditions, held-order, Z-report sync), the VO tier (Billing Money + Loyalty PointsAmount/Balance → numeric-string), and the notifications tier (queued Billing notifications stop hardcoding scale 2).
3. Phases 9–12 lock in the work with seeders/fixtures aligned to canonical scales, the universal `step="0.01"` UI sweep replaced with `<MoneyInput currency={}>` / `<QuantityInput product={}>`, regression guards (PHPStan + ESLint), and documentation.

**Tech Stack:** Laravel 12 + PHP 8.4 + PHPStan level 8, PostgreSQL 16 (DECIMAL widening is non-destructive on PG), Vitest + ESLint + TypeScript strict (frontend), React 19 (web), Tauri 2 (POS).

**Coordination:**
- The Inventory parallel session (`docs/superpowers/coordination/2026-05-28-inventory-precision-fix-prompt.md`) handles `stock_levels`, `stock_movements`, `stock_reservations`, `inventory_counting_items` quantity columns + `StockAdjustmentService::SCALE` + Inventory frontend. **This plan assumes that session has merged before Phase 4.3 and Phase 10.X depend on it.** Where this plan touches sibling code (e.g. POS receipt stock decrement, Inventory stock-movement controllers, batch services), it does so via separate file paths so the two sessions don't collide.
- Use a dedicated `git worktree add` per `feedback_parallel_session_worktree_isolation.md`. Branch off `dev`. Each Phase below is its own PR(s) — sequence per the dependency diagram.

---

## Amendment — 2026-05-29: PR #151 partial overlap (merged)

After the plan was written, the parallel inventory session shipped **PR #151 (`fix/inventory-precision-drift` → `dev`, MERGED at commit `83ed1f15d`)** with the following already done:

| Plan task | Status post-PR-#151 | File evidence |
|---|---|---|
| Phase 1.3 — `stock_levels.{quantity, reserved, min_quantity, max_quantity}` decimal(15,4) | ✅ DONE | `apps/api/database/migrations/tenant/2026_05_29_120000_widen_inventory_quantity_columns_to_scale_4.php` |
| Phase 1.3 — `stock_movements.{quantity, quantity_before, quantity_after}` decimal(15,4) | ✅ DONE | same migration |
| Phase 3 prerequisite — `StockAdjustmentService::SCALE = 4` + 3 bcmath sites | ✅ DONE | `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` |
| Phase 1.4 — `StockLevel`, `StockMovement` casts → `decimal:4` | ✅ DONE | `StockLevel.php`, `StockMovement.php` |
| Phase 0.6 — frontend `formatQuantity` helper | ✅ DONE (helper only) | `apps/web/src/lib/format.ts:+27` |
| Phase 11 — Inventory-scoped architecture guard | ✅ DONE (Inventory only) | `apps/api/tests/Unit/Inventory/InventoryQuantityPrecisionGuardTest.php` |
| Phase 10 (partial) — `apps/web/src/features/inventory/{StockLevelsPage,StockMovementsPage,components/{ProductMovementsTab,ProductStockLevels}}.tsx` quantity display | ✅ DONE | PR #151 |

**Remaining scope is unchanged:**
- Phase 0.1–0.5 (CurrencyScale hardening + resolver fail-loud + mock default → 3 + `QuantityScaleResolverInterface` reading `units.decimal_places` + backend `QuantityScale` static helper). PR #151 did NOT introduce a resolver or hardened helpers.
- Phase 0.6 EXTENDS the existing `formatQuantity` (do not replace) AND consolidates the four `formatCurrency` duplicates (still present after PR #151).
- Phase 0.7 `<MoneyInput>` / `<QuantityInput>` — not done by PR #151.
- Phase 1.1, 1.2, 1.3 (non-inventory cols), 1.4 (Product/Service/Account/Treasury/Coupon/Promotion/Permissions casts).
- Phases 2–10 in full (except the inventory frontend display sites listed above).
- Phase 11 — extend the InventoryQuantityPrecisionGuardTest pattern into app-wide PHPStan rules.
- Phase 12 — docs unchanged.

**Sequencing implication:**
- This plan's branch (`feat/precision-drift-remediation`) forks from current `dev` (PR #151 not yet merged).
- When PR #151 merges to `dev`, rebase this branch. Conflict surface is small: `apps/web/src/lib/format.ts` (we extend with new exports), `apps/api/.../StockAdjustmentService.php` (we don't touch), Inventory model casts (we don't touch).
- Tasks **3.10, 3.11, 4.3, 5.2** (originally blocked on the inventory parallel session) become unblocked once PR #151 merges.

**Phase dependency diagram:**
```
Phase 0 (contract layer)
  ├── Phase 1 (storage widening)         ── independent ──┐
  ├── Phase 2 (settings UI)                              ─┤
  ├── Phase 3 (service bcmath sweep)                     ─┤
  ├── Phase 4 (ingress regex sweep)                      ─┤
  ├── Phase 5 (JSONB tightening)                         ─┤
  ├── Phase 6 (model casts + Resources)                  ─┤
  ├── Phase 7 (VO refactor)                              ─┤
  ├── Phase 8 (notifications)                            ─┤
  ├── Phase 9 (seeders / fixtures)                       ─┤
  ├── Phase 10 (frontend rollout) ── after Phase 0 + 6 ──┤
  └── Phase 11 (regression guards) ── after all above ───┘
       └── Phase 12 (docs + REALIGNMENT-LOG)
```

**Verification commands (run before every commit; mandatory before any PR):**
```bash
cd apps/api && composer test
cd apps/api && ./vendor/bin/phpstan analyse --memory-limit=2G
cd apps/api && ./vendor/bin/pint --test
cd apps/web && pnpm test
cd apps/web && pnpm typecheck
cd apps/web && pnpm lint
# Convenience all-in-one:
./scripts/preflight.sh
```

**Worktree setup:**
```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
git fetch origin
git worktree add ../erp.precision-drift-remediation -b feat/precision-drift-remediation origin/dev
cd ../erp.precision-drift-remediation
```

**Currency / Quantity contract reference (read once, refer often):**
| Concern | Storage scale | Resolver | Display scale | Notes |
|---|---|---|---|---|
| Currency (most cols) | `decimal(N,3)` | `CurrencyScaleResolverInterface->getScale($currency?)` | `getDecimals(currency)` — 0 for JPY/KRW, 2 for EUR/USD/GBP, 3 for TND/LYD/JOD/KWD/OMR/BHD | Already established by 2026-03 widening migrations. Phase 1 closes the remaining scale-2 outliers. |
| Currency (eco_tax, voucher) | `decimal(N,5)` | as above | as above | Producer-consumer contract documented; truncation at boundary is intentional |
| Currency (pos_shifts cash) | `decimal(16,4)` | as above | as above | Already widened to 4 by `2026_04_25_000002` for cash-counting precision |
| Quantity (canonical) | `decimal(N,4)` | `QuantityScaleResolverInterface->scaleFor($product\|$unit)` reading `units.decimal_places` | `QuantityScale::format(value, product)` | NEW resolver introduced in Phase 0. Reads `units.decimal_places` (already exists in schema, currently dormant). |
| Quantity (legacy, NULL unit_id) | `decimal(N,4)` | fallback scale 3 | fallback scale 3 | Backward compat; deprecate in Phase 12 after data migration |

---

## Phase 0 — Canonical contract layer

**Why first:** every later task depends on the new helpers. Land the entire phase as one PR (`feat/precision-canonical-contract`). Until this merges, no other Phase task may begin.

**Phase 0 PR title:** `feat: harden CurrencyScale + introduce QuantityScale + frontend canonical formatters + shared inputs`

---

### Task 0.1: Harden `CurrencyScale::bcformat` — reject float, reject non-numeric, configurable null handling

**Files:**
- Modify: `apps/api/app/Shared/Domain/CurrencyScale.php:85-104` (bcformat) and add new strict variant
- Test: `apps/api/tests/Unit/Shared/CurrencyScaleBcformatStrictTest.php` (new)

**Acceptance criteria:**
1. `CurrencyScale::bcformat(string|int|null $value, int $scale)` — `float` removed from the type union; PHPStan level 8 enforces this.
2. New `CurrencyScale::bcformatOrNull(?string $value, int $scale): ?string` — preserves `null` (does NOT silently zero).
3. New `CurrencyScale::bcformatStrict(string $value, int $scale): string` — throws `InvalidArgumentException` if `! is_numeric(trim($value))`.
4. The legacy `bcformat` still accepts `int` and `null` (returns `'0.…'` for null) for backward compatibility — but emits a `trigger_error(E_USER_DEPRECATED)` when called with null so existing callers surface during transition.
5. PHPStan level 8 clean. `composer test` green.

**Step 1: Write failing tests**

```php
<?php
// apps/api/tests/Unit/Shared/CurrencyScaleBcformatStrictTest.php
declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\CurrencyScale;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CurrencyScaleBcformatStrictTest extends TestCase
{
    public function test_strict_rejects_non_numeric(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CurrencyScale::bcformatStrict('abc', 3);
    }

    public function test_strict_rejects_whitespace_only(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CurrencyScale::bcformatStrict('   ', 3);
    }

    public function test_strict_accepts_canonical_decimal_string(): void
    {
        self::assertSame('5.123', CurrencyScale::bcformatStrict('5.123', 3));
    }

    public function test_or_null_preserves_null(): void
    {
        self::assertNull(CurrencyScale::bcformatOrNull(null, 3));
    }

    public function test_or_null_formats_non_null(): void
    {
        self::assertSame('5.000', CurrencyScale::bcformatOrNull('5', 3));
    }
}
```

**Step 2: Run tests; expect FAIL** with "Method bcformatStrict does not exist".

```bash
cd apps/api && ./vendor/bin/phpunit tests/Unit/Shared/CurrencyScaleBcformatStrictTest.php
```

**Step 3: Implement**

```php
// apps/api/app/Shared/Domain/CurrencyScale.php — append after existing bcformat

/**
 * Strict variant — throws on non-numeric input.
 * Use at write boundaries where silent-zero would corrupt data.
 *
 * @return numeric-string
 */
public static function bcformatStrict(string $value, int $scale): string
{
    $trimmed = trim($value);
    if ($trimmed === '' || ! is_numeric($trimmed)) {
        throw new \InvalidArgumentException(
            sprintf('CurrencyScale::bcformatStrict received non-numeric value "%s"', $value)
        );
    }

    return bcadd($trimmed, '0', $scale);
}

/**
 * Null-preserving variant — does NOT coerce null to zero.
 *
 * @return numeric-string|null
 */
public static function bcformatOrNull(?string $value, int $scale): ?string
{
    if ($value === null) {
        return null;
    }
    return self::bcformatStrict($value, $scale);
}
```

**Step 4: Run tests; expect PASS**.

**Step 5: Commit**

```bash
git add apps/api/app/Shared/Domain/CurrencyScale.php apps/api/tests/Unit/Shared/CurrencyScaleBcformatStrictTest.php
git commit -m "feat(shared): add bcformatStrict + bcformatOrNull to CurrencyScale"
```

---

### Task 0.2: `CurrencyScaleResolver` — fail-loud when CompanyContext is empty

**Files:**
- Modify: `apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:35-65`
- Test: `apps/api/tests/Unit/Shared/CurrencyScaleResolverContextTest.php` (new)

**Acceptance criteria:**
1. When `$currencyCode` is null AND `CompanyContext` has no bound company, `getScale()` throws `RuntimeException` with a clear error message including the calling chain hint.
2. Existing `$companyOverride` path remains (for unit-test fixtures).
3. Existing per-request HTTP / TenantScopedCommand callers continue to work (covered by existing feature tests).
4. Add a `getScaleSafe(?string $currencyCode = null, int $fallback = 3): int` for explicit callers that *want* a fallback (e.g. queued notifications that derive scale from a model field).

**Step 1: Failing test**

```php
<?php
// apps/api/tests/Unit/Shared/CurrencyScaleResolverContextTest.php
declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Infrastructure\CurrencyScaleResolver;
use App\Shared\Infrastructure\CompanyContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CurrencyScaleResolverContextTest extends TestCase
{
    public function test_throws_when_context_empty_and_no_code(): void
    {
        $context = new CompanyContext(); // empty
        $resolver = new CurrencyScaleResolver($context, fn ($cc) => null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CurrencyScaleResolver: no company bound');

        $resolver->getScale();
    }

    public function test_explicit_code_works_without_context(): void
    {
        $context = new CompanyContext();
        $resolver = new CurrencyScaleResolver($context, fn ($cc) => null);
        self::assertSame(2, $resolver->getScale('EUR'));
        self::assertSame(3, $resolver->getScale('TND'));
    }

    public function test_safe_returns_fallback_when_context_empty(): void
    {
        $context = new CompanyContext();
        $resolver = new CurrencyScaleResolver($context, fn ($cc) => null);
        self::assertSame(3, $resolver->getScaleSafe(fallback: 3));
    }
}
```

**Step 2: Run tests; expect FAIL**.

**Step 3: Implement** — modify `getScale()` to throw when context is empty AND no `$currencyCode` is provided; add `getScaleSafe()`. Add the new method to `CurrencyScaleResolverInterface`.

**Step 4: Run full unit suite + integration suite to surface any callsite that relied on the silent fallback.** Any failing test is a real bug that this task surfaces — fix it inline (or file a Phase-9 task to migrate to `getScaleSafe`).

**Step 5: Commit**

```bash
git add apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php apps/api/app/Shared/Contracts/CurrencyScaleResolverInterface.php apps/api/tests/Unit/Shared/CurrencyScaleResolverContextTest.php
git commit -m "feat(shared): CurrencyScaleResolver throws when context empty; add getScaleSafe"
```

---

### Task 0.3: Change test mock `WithCurrencyScale` default to TND scale (3)

**Files:**
- Modify: `apps/api/tests/Traits/WithCurrencyScale.php:17`

**Acceptance criteria:**
1. `mockCurrencyScale(int $scale = 3)` — default is **3** (TND-friendly).
2. Add a class constant `WithCurrencyScale::DEFAULT_TEST_SCALE = 3`.
3. Run the full PHPUnit suite. **Any test that fails after this change is a TND-precision regression that the mock was silently masking.** Catalog each failure as a follow-up task in Phase 9.

**Implementation:**

```php
// apps/api/tests/Traits/WithCurrencyScale.php
trait WithCurrencyScale
{
    public const DEFAULT_TEST_SCALE = 3;

    protected function mockCurrencyScale(int $scale = self::DEFAULT_TEST_SCALE): void
    {
        // ... existing body unchanged ...
    }
}
```

**Step: run preflight**

```bash
cd apps/api && composer test 2>&1 | tee /tmp/test-default-scale-3.log
```

Open the log. Every failure is a real surface-finding. For each, add it to Phase 9 as `Task 9.X` with the test name + file:line + fix sketch.

**Commit (after cataloging failures, even if some still fail — they get fixed in Phase 9):**

```bash
git add apps/api/tests/Traits/WithCurrencyScale.php
git commit -m "test: mockCurrencyScale defaults to 3 (TND) to surface scale regressions"
```

> ⚠ **Codex note:** if &gt;20 tests fail, stop and report back. Otherwise inline-fix the trivial ones (e.g. tests that hardcoded `'5.00'` should use `'5.000'`) and defer the substantive ones to Phase 9 with a TODO comment annotating `// TODO(precision-drift): scale-2 fixture; see Phase 9` so the failures don't block the rest of Phase 0.

---

### Task 0.4: Introduce `QuantityScaleResolverInterface` + production implementation

**Files:**
- Create: `apps/api/app/Shared/Contracts/QuantityScaleResolverInterface.php`
- Create: `apps/api/app/Shared/Infrastructure/QuantityScaleResolver.php`
- Modify: `apps/api/app/Providers/AppServiceProvider.php` (bind as singleton)
- Test: `apps/api/tests/Unit/Shared/QuantityScaleResolverTest.php`

**Acceptance criteria:**
1. Interface:
```php
namespace App\Shared\Contracts;

use App\Modules\Product\Domain\Product;
use App\Modules\Uom\Domain\Entities\Unit;

interface QuantityScaleResolverInterface
{
    /**
     * Resolve quantity precision for a product. Reads $product->unitOfMeasure->decimal_places.
     * Falls back to default scale 3 when the product has no unit_id (legacy data).
     */
    public function scaleForProduct(Product $product): int;

    /** Resolve quantity precision for a unit. */
    public function scaleForUnit(Unit $unit): int;

    /** Hard ceiling — every quantity column stores at decimal(N,4); display scales down. */
    public function storageScale(): int; // returns 4
}
```
2. Implementation reads `$product->unitOfMeasure->decimal_places` if the relation is set; otherwise falls back to 3.
3. Storage scale is 4 (matches the canonical ceiling and the in-flight inventory parallel fix).

**Step 1: Failing test**

```php
<?php
// apps/api/tests/Unit/Shared/QuantityScaleResolverTest.php
declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Infrastructure\QuantityScaleResolver;
use App\Modules\Product\Domain\Product;
use App\Modules\Uom\Domain\Entities\Unit;
use PHPUnit\Framework\TestCase;

final class QuantityScaleResolverTest extends TestCase
{
    public function test_storage_scale_is_4(): void
    {
        $resolver = new QuantityScaleResolver();
        self::assertSame(4, $resolver->storageScale());
    }

    public function test_unit_scale_reads_decimal_places(): void
    {
        $unit = new Unit();
        $unit->decimal_places = 2;
        $resolver = new QuantityScaleResolver();
        self::assertSame(2, $resolver->scaleForUnit($unit));
    }

    public function test_product_with_null_unit_falls_back_to_3(): void
    {
        $product = new Product();
        $resolver = new QuantityScaleResolver();
        self::assertSame(3, $resolver->scaleForProduct($product));
    }
}
```

**Step 2: Run tests; expect FAIL** (resolver doesn't exist).

**Step 3: Implement** the interface, resolver, and binding.

```php
// apps/api/app/Shared/Infrastructure/QuantityScaleResolver.php
namespace App\Shared\Infrastructure;

use App\Modules\Product\Domain\Product;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Shared\Contracts\QuantityScaleResolverInterface;

final class QuantityScaleResolver implements QuantityScaleResolverInterface
{
    public function scaleForProduct(Product $product): int
    {
        $unit = $product->unitOfMeasure;
        if ($unit === null) {
            return 3;
        }
        return $this->scaleForUnit($unit);
    }

    public function scaleForUnit(Unit $unit): int
    {
        return (int) $unit->decimal_places;
    }

    public function storageScale(): int
    {
        return 4;
    }
}
```

**Step 4: Bind singleton in `AppServiceProvider::register()`:**

```php
$this->app->singleton(
    \App\Shared\Contracts\QuantityScaleResolverInterface::class,
    \App\Shared\Infrastructure\QuantityScaleResolver::class
);
```

**Step 5: Run tests; expect PASS**.

**Step 6: Commit**

```bash
git add apps/api/app/Shared/Contracts/QuantityScaleResolverInterface.php apps/api/app/Shared/Infrastructure/QuantityScaleResolver.php apps/api/app/Providers/AppServiceProvider.php apps/api/tests/Unit/Shared/QuantityScaleResolverTest.php
git commit -m "feat(shared): introduce QuantityScaleResolver reading units.decimal_places"
```

---

### Task 0.5: Add `QuantityScale` static helper (analog to `CurrencyScale`)

**Files:**
- Create: `apps/api/app/Shared/Domain/QuantityScale.php`
- Test: `apps/api/tests/Unit/Shared/QuantityScaleTest.php`

**Acceptance criteria:**
1. Static methods: `QuantityScale::bcformat(string $value, int $scale): string`, `bcformatStrict`, `bcformatOrNull`, identical safety semantics to `CurrencyScale`.
2. NEW: `QuantityScale::round(string $value, int $scale, RoundingMethod $method = RoundingMethod::HalfUp): string` — **bcmath-native rounding** (no float). This is the helper that Task 3.13 (UnitConversionService) will use.
3. NEW: `QuantityScale::storageMax(): int` = 4 (the canonical storage ceiling).

**Step 1: Failing test for bcmath-native round**

```php
<?php
// apps/api/tests/Unit/Shared/QuantityScaleTest.php
declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\QuantityScale;
use App\Modules\Uom\Domain\Enums\RoundingMethod;
use PHPUnit\Framework\TestCase;

final class QuantityScaleTest extends TestCase
{
    public function test_round_half_up_does_not_use_float(): void
    {
        // canonical bcmath HalfUp: 0.5 → 1, 0.4999 → 0
        self::assertSame('1.235', QuantityScale::round('1.2345', 3, RoundingMethod::HalfUp));
        self::assertSame('1.234', QuantityScale::round('1.2344', 3, RoundingMethod::HalfUp));
    }

    public function test_round_floor(): void
    {
        self::assertSame('1.234', QuantityScale::round('1.2349', 3, RoundingMethod::Floor));
    }

    public function test_round_ceil(): void
    {
        self::assertSame('1.235', QuantityScale::round('1.2341', 3, RoundingMethod::Ceil));
    }

    public function test_round_at_float_boundary_preserves_precision(): void
    {
        // a value that would alias when cast to float at 1e14 magnitude
        $huge = '99999999999999.9999';
        // do not assert the exact result — assert that the result is exclusively bcmath-derived
        // (no scientific notation, no float artefacts)
        self::assertMatchesRegularExpression('/^\d+(\.\d+)?$/', QuantityScale::round($huge, 0, RoundingMethod::HalfUp));
    }
}
```

**Step 2: Run tests; expect FAIL**.

**Step 3: Implement** — `bcadd($v, $sign × '0.5' shifted to scale, 0)` for HalfUp; truncation for Floor; conditional add for Ceil.

```php
// apps/api/app/Shared/Domain/QuantityScale.php
namespace App\Shared\Domain;

use App\Modules\Uom\Domain\Enums\RoundingMethod;

final class QuantityScale
{
    public const STORAGE_MAX = 4;

    public static function bcformat(string $value, int $scale): string
    {
        return CurrencyScale::bcformat($value, $scale);
    }

    public static function bcformatStrict(string $value, int $scale): string
    {
        return CurrencyScale::bcformatStrict($value, $scale);
    }

    public static function bcformatOrNull(?string $value, int $scale): ?string
    {
        return CurrencyScale::bcformatOrNull($value, $scale);
    }

    /** Bcmath-native rounding. Never casts to float. */
    public static function round(string $value, int $scale, RoundingMethod $method = RoundingMethod::HalfUp): string
    {
        $trimmed = trim($value);
        if (! is_numeric($trimmed)) {
            throw new \InvalidArgumentException("QuantityScale::round received non-numeric: $value");
        }

        return match ($method) {
            RoundingMethod::HalfUp => self::roundHalfUp($trimmed, $scale),
            RoundingMethod::Floor  => bcadd($trimmed, '0', $scale),
            RoundingMethod::Ceil   => self::roundCeil($trimmed, $scale),
        };
    }

    private static function roundHalfUp(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');
        $halfUnit = '0.' . str_repeat('0', $scale) . '5'; // e.g. scale=3 → '0.0005'
        $bumped = bcadd($abs, $halfUnit, $scale + 1);
        $truncated = bcadd($bumped, '0', $scale);
        return $negative ? bcsub('0', $truncated, $scale) : $truncated;
    }

    private static function roundCeil(string $value, int $scale): string
    {
        $truncated = bcadd($value, '0', $scale);
        if (bccomp($truncated, $value, $scale + 4) === 0) {
            return $truncated;
        }
        $unit = '0.' . str_repeat('0', $scale - 1) . '1';
        if ($scale === 0) {
            $unit = '1';
        }
        return bcadd($truncated, $unit, $scale);
    }
}
```

**Step 4: Run tests; expect PASS**.

**Step 5: Commit**

```bash
git add apps/api/app/Shared/Domain/QuantityScale.php apps/api/tests/Unit/Shared/QuantityScaleTest.php
git commit -m "feat(shared): add QuantityScale helper with bcmath-native rounding"
```

---

### Task 0.6: Frontend formatter consolidation — `decimal.ts` is canonical, others deprecated

**Files:**
- Modify: `apps/web/src/lib/format.ts` (delete `formatCurrency`, mark `formatNumber` as accepting `string`)
- Modify: `apps/web/src/lib/utils.ts` (delete `formatMoney`)
- Modify: `apps/web/src/lib/formatCurrency.ts` (delete file or make it re-export from `decimal.ts`)
- Modify: `apps/web/src/lib/decimal.ts` (extend `formatCurrency` to be the canonical single entry point)
- Create: `apps/web/src/lib/formatQuantity.ts` — quantity formatter (string in, string out, `decimal_places` parameter)
- Test: `apps/web/src/lib/__tests__/format.test.ts` (extend)

**Acceptance criteria:**
1. One canonical exported `formatCurrency(amount: string, currency: string, includeCurrency?: boolean): string`.
2. One canonical exported `formatQuantity(value: string, decimalPlaces: number): string`.
3. All four legacy `formatCurrency` / `formatMoney` exports either re-export the canonical one OR are deleted; TypeScript compile + ESLint surface every caller.
4. Caller migration: fix all import sites that broke. `pnpm typecheck && pnpm lint` clean.

**Step 1: Write failing tests**

```ts
// apps/web/src/lib/__tests__/format.test.ts (extend)
import { describe, expect, it } from 'vitest';
import { formatCurrency } from '@/lib/decimal';
import { formatQuantity } from '@/lib/formatQuantity';

describe('canonical formatters', () => {
  it('formatCurrency takes string and threads currency-aware decimals', () => {
    expect(formatCurrency('5.123', 'TND', false)).toBe('5.123');
    expect(formatCurrency('5.12', 'EUR', false)).toBe('5.12');
    expect(formatCurrency('5.1234', 'TND', false)).toBe('5.123'); // truncate at currency scale
  });

  it('formatCurrency does not parseFloat (preserves trailing zeros)', () => {
    expect(formatCurrency('5.000', 'TND', false)).toBe('5.000');
  });

  it('formatQuantity uses per-unit decimal_places', () => {
    expect(formatQuantity('7.1234', 4)).toBe('7.1234');
    expect(formatQuantity('7.1234', 2)).toBe('7.12');
    expect(formatQuantity('7.0000', 4)).toBe('7.0000');
  });
});
```

**Step 2: Run tests; expect FAIL**.

**Step 3: Implement `formatQuantity.ts`** — string-only, uses Big.js (not parseFloat):

```ts
// apps/web/src/lib/formatQuantity.ts
import Big from 'big.js';

/**
 * Format a quantity string at the given decimal_places.
 * Never uses parseFloat — preserves precision.
 */
export function formatQuantity(value: string, decimalPlaces: number): string {
  if (typeof value !== 'string') {
    throw new TypeError('formatQuantity requires string input');
  }
  return new Big(value).toFixed(decimalPlaces);
}
```

**Step 4: Update `decimal.ts` `formatCurrency`** to be the canonical implementation (already string-input + currency-aware per Phase-1 D-14 finding). Delete the other three.

**Step 5: Migrate every import site.** Search:
```bash
cd apps/web && rg "from '@/lib/format'" --files-with-matches
cd apps/web && rg "from '@/lib/formatCurrency'" --files-with-matches
cd apps/web && rg "formatMoney|from '@/lib/utils'" --files-with-matches
```

For each, switch to `import { formatCurrency } from '@/lib/decimal'` (or `formatQuantity` for quantity sites).

**Step 6: Run `pnpm typecheck && pnpm lint && pnpm test`; expect green**.

**Step 7: Commit**

```bash
git add apps/web/src/lib/decimal.ts apps/web/src/lib/formatQuantity.ts apps/web/src/lib/format.ts apps/web/src/lib/utils.ts apps/web/src/lib/__tests__/format.test.ts
git commit -m "feat(web): consolidate to canonical formatCurrency + new formatQuantity"
```

---

### Task 0.7: Shared `<MoneyInput>` and `<QuantityInput>` React components

**Files:**
- Create: `apps/web/src/components/atoms/MoneyInput/MoneyInput.tsx`
- Create: `apps/web/src/components/atoms/MoneyInput/index.ts`
- Create: `apps/web/src/components/atoms/QuantityInput/QuantityInput.tsx`
- Create: `apps/web/src/components/atoms/QuantityInput/index.ts`
- Test: `apps/web/src/components/atoms/MoneyInput/MoneyInput.test.tsx`
- Test: `apps/web/src/components/atoms/QuantityInput/QuantityInput.test.tsx`

**Acceptance criteria:**
1. `<MoneyInput value={string} onChange={(string)=>void} currency={string} ...HTMLInputAttrs>` — emits `step={1 / 10 ** getDecimals(currency)}`, formats display via `formatCurrency`, value is canonical string at currency scale.
2. `<QuantityInput value={string} onChange={(string)=>void} decimalPlaces={number} ...HTMLInputAttrs>` — emits `step={1 / 10 ** decimalPlaces}`, formats via `formatQuantity`.
3. Both components emit `min={0}` by default; both accept `min`/`max` overrides as strings.
4. Both components accept onChange callback that receives the CANONICAL string (no JS Number ever).
5. Sample integration: replace step="0.01" in `apps/web/src/features/compliance/components/CashDrawerControlsSection.tsx` with `<MoneyInput>` as the reference site.

**TDD per the example in the writing-plans skill. Codex executor should:**
- Step 1: Write failing component test that mounts the input, types `'5.123'`, asserts `onChange` was called with the string `'5.123'` (not number `5.123`).
- Step 2: Run vitest; expect FAIL.
- Step 3: Implement components.
- Step 4: Run vitest; expect PASS.
- Step 5: Migrate `CashDrawerControlsSection.tsx` as the reference integration.
- Step 6: Commit.

---

### Task 0.8: Phase 0 PR open

After Tasks 0.1–0.7 commits are stacked, push the branch and open a PR to `dev`:

```bash
git push origin feat/precision-drift-remediation
gh pr create --base dev --title "feat: precision canonical contract layer (CurrencyScale hardening + QuantityScale + shared inputs)" --body "$(cat <<'EOF'
## Summary
- Hardens `CurrencyScale::bcformat` (rejects float, adds `bcformatStrict`, `bcformatOrNull`)
- `CurrencyScaleResolver` fails loud when CompanyContext is empty (closes latent queued-job bug)
- New `QuantityScaleResolver` reading `units.decimal_places` (dormant infrastructure activated)
- New `QuantityScale` helper with bcmath-native rounding
- Test mock `WithCurrencyScale` defaults to TND scale (3) — surfaces precision regressions
- Frontend formatter consolidation: single canonical `formatCurrency` + new `formatQuantity`
- Shared `<MoneyInput>` and `<QuantityInput>` React components

## Test plan
- [x] Unit tests for CurrencyScale strict variants
- [x] Unit tests for CurrencyScaleResolver context behavior
- [x] Unit tests for QuantityScale rounding
- [x] Component tests for MoneyInput / QuantityInput
- [x] Full PHPUnit suite green (post mock-scale-change inline fixes)
- [x] pnpm typecheck + lint + test green

## Coordination
Foundation PR for the precision-drift-remediation plan. No subsequent Phase work begins until this merges.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Phase 1 — Storage scale alignment

**Why:** the Marketplace, Cart, Billing-items, and other modules missed the 2026-03 widening sweep. Outliers at decimal(N,2) silently truncate TND values. Also: `pos_orders` / `pos_receipts` use different scales for the same `total` field. Also: most quantity columns are not yet at decimal(N,4).

**Phase 1 PR title:** `feat(db): widen residual scale-2 monetary cols + align quantity cols to scale 4`

---

### Task 1.1: Migration — widen residual monetary cols to scale 3

**Files:**
- Create: `apps/api/database/migrations/2026_05_29_100000_widen_residual_monetary_columns_to_scale_3.php`

**Targets** (verified by Phase-1 audit table):
- `catalog_cart_items.unit_price` — currently `decimal(15,3)` per migration `2026_03_10_500000:40`. **Already scale 3 — skip.**
- `marketplace_order_lines.unit_price` — `decimal(15,3)` per `2026_03_10_400002:53`. **Already 3 — skip.**
- `marketplace_orders.subtotal`, `commission_amount`, `total` — `decimal(15,3)` per `2026_03_10_400002:22-25`. **Already 3 — skip.**
- `services.tax_rate` — `decimal(5,2)` per `2025_12_12_120001:43`. **WIDEN to decimal(6,3)** to match Workshop / Document / POS tax_rate columns (per Agent G S-1).
- `loyalty_settings.welcome_bonus_points` — `decimal(15,2)` per `2026_03_02_300000:16`. **WIDEN to decimal(15,3)**.

**Acceptance criteria:**
1. PostgreSQL `ALTER COLUMN ... TYPE NUMERIC(...)` is non-destructive (PG widens NUMERIC in place). Verify on a copy of seeded data: existing rows survive (e.g. `0.00` reads back as `0.000`).
2. Add a regression test that asserts the new column scales via `SHOW COLUMNS` or `information_schema.columns`.

**Step 1: Failing test**

```php
// apps/api/tests/Feature/Schema/MonetaryColumnScalesTest.php (new)
public function test_services_tax_rate_is_decimal_6_3(): void
{
    $row = DB::select("
        SELECT numeric_precision, numeric_scale
        FROM information_schema.columns
        WHERE table_name = 'services' AND column_name = 'tax_rate'
    ")[0];
    self::assertSame(6, (int) $row->numeric_precision);
    self::assertSame(3, (int) $row->numeric_scale);
}
```

**Step 2: Run; expect FAIL**.

**Step 3: Implement migration**

```php
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE services ALTER COLUMN tax_rate TYPE NUMERIC(6, 3)');
        DB::statement('ALTER TABLE loyalty_settings ALTER COLUMN welcome_bonus_points TYPE NUMERIC(15, 3)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE services ALTER COLUMN tax_rate TYPE NUMERIC(5, 2)');
        DB::statement('ALTER TABLE loyalty_settings ALTER COLUMN welcome_bonus_points TYPE NUMERIC(15, 2)');
    }
};
```

**Step 4: Run tests; expect PASS**.

**Step 5: Commit**

---

### Task 1.2: Migration — align `pos_orders` totals with `pos_receipts` (both decimal(N,3))

**Files:**
- Create: `apps/api/database/migrations/2026_05_29_100001_align_pos_orders_to_scale_3.php`

**Decision rationale:** `pos_receipts.total` is the fiscal artifact at decimal(12,3). `pos_orders.total` was created at decimal(15,4) inconsistently. Receipts are the source of truth for fiscal hashing; orders should match. **Reduce `pos_orders.*` to decimal(15,3)** (the 4th decimal was an over-precision that immediately gets truncated on conversion to a receipt).

**Acceptance criteria:**
1. Migration narrows `pos_orders.{subtotal, tax_amount, discount_amount, total}` and `pos_order_lines.{unit_price, discount_amount, tax_amount, line_total}` from decimal(15,4) to decimal(15,3).
2. **Pre-check:** assert no existing row has a non-zero 4th decimal (i.e. every value already round-trips at scale 3). If any row violates, FAIL the migration with a clear error. (Use `SELECT ... WHERE (col * 1000)::integer != (col * 1000)` style guard.)
3. Update model casts `apps/api/app/Modules/POS/Domain/Order.php:124-127` and `OrderLine.php:25` from `decimal:4` to `decimal:3`.
4. Sweep tests under `tests/Feature/POS/Order*` — replace `'5.0000'` expectations with `'5.000'`.

**Step 1: Schema-shape failing test, then implement, then sweep test fixtures, then commit.**

---

### Task 1.3: Migration — align quantity columns to decimal(N,4)

**Files:**
- Create: `apps/api/database/migrations/2026_05_29_100002_widen_quantity_columns_to_scale_4.php`

**Targets (outside the in-flight Inventory parallel fix):**
- `catalog_cart_items.quantity` — `decimal(10,2)` → `decimal(15,4)`
- `marketplace_listings.{quantity_available, min_order_quantity}` — `decimal(10,2)` → `decimal(15,4)`
- `marketplace_order_lines.quantity` — `decimal(10,2)` → `decimal(15,4)`
- `billing_invoice_items.quantity` — `decimal(10,2)` → `decimal(15,4)` (or keep at 2 if SaaS billing is integer-quantity by design — confirm with Billing module owner; default plan is keep at 2 with a CHECK that asserts integer)
- `price_list_items.{min_quantity, max_quantity}` — `decimal(10,2)` → `decimal(15,4)`
- `workshop_work_order_lines.quantity` — `decimal(12,3)` → `decimal(15,4)`
- `workshop_service_bundle_components.quantity` — `decimal(10,3)` → `decimal(15,4)`
- `pos_receipt_lines.quantity` — `decimal(10,3)` → `decimal(15,4)`
- `pos_order_lines.quantity` — `decimal(10,3)` → `decimal(15,4)` (matches Task 1.2 narrowing of monetary but widens quantity)
- `pos_receipt_line_batch_allocations.quantity` — `decimal(10,3)` → `decimal(15,4)`
- `document_lines.{quantity, quantity_delivered, quantity_received}` — `decimal(15,4)` — **already at 4 — skip**

**Acceptance criteria:**
1. PG widening preserves data. Pre-check assertion is not needed (widening always succeeds).
2. Update every model cast affected (Cart, Marketplace, Pricing, Workshop, POS). Replace `'decimal:2'` / `'decimal:3'` quantity casts with `'decimal:4'`.
3. Sweep tests under `tests/Feature/{Cart, Marketplace, Pricing, Workshop, POS}/...` and update quantity fixture expectations (e.g. `'5.00'` → `'5.0000'`).
4. Document the contract in a code comment at the top of the migration explaining the canonical 4-decimal-quantity rule.

**Note:** This is the largest migration in the plan. Codex executor should split it per-table commits so each test sweep is reviewable independently — but keep them in the same PR.

---

### Task 1.4: Add missing Eloquent `decimal:N` casts

**Files (one task per cluster):**

**1.4a — Product:**
- Modify `apps/api/app/Modules/Product/Domain/Product.php:114-126` — add `'sale_price' => 'decimal:3', 'purchase_price' => 'decimal:3', 'cost_price' => 'decimal:3', 'last_purchase_cost' => 'decimal:3', 'target_margin_override' => 'decimal:3', 'minimum_margin_override' => 'decimal:3', 'tax_rate' => 'decimal:2'`.
- Run `php artisan typescript:transform` to regenerate `packages/shared/types/generated.d.ts`. Frontend types update from `number` to `string` for these fields — sweep call sites that did `Number(product.sale_price)` to use canonical helpers instead.

**1.4b — Service:**
- Modify `apps/api/app/Modules/Service/Domain/Service.php:94-101` — add `'base_price' => 'decimal:3', 'hourly_rate' => 'decimal:3', 'tax_rate' => 'decimal:3'` (after Task 1.1 widens services.tax_rate).

**1.4c — Account:**
- Modify `apps/api/app/Modules/Accounting/Domain/Account.php:101` — change default attribute from `'balance' => '0.00'` to `'balance' => '0.000'`. Add `'balance' => 'decimal:3'` to casts if not present.

**1.4d — Treasury Payment FX columns:**
- Modify `apps/api/app/Modules/Treasury/Domain/Payment.php:110-121` — add `'exchange_rate_at_payment' => 'decimal:6', 'fx_gain_loss_amount' => 'decimal:4', 'discount_taken' => 'decimal:4'`.

**1.4e — Coupon / Promotion:**
- Modify `Coupon.php`, `CouponUsage.php`, `Promotion.php`, `PromotionUsage.php` to add `'decimal:N'` casts matching their column scales.

**1.4f — Identity/Terminal max_discount_percent (security boundary, F-PERMISSIONS):**
- Modify `User.php:114` — change `'max_discount_percent' => 'float'` to `'decimal:2'`.
- Modify `Terminal.php:129` — same.

**1.4g — POS Receipt.discount_authorized_by:**
- Modify `Receipt.php` — add `'discount_authorized_by' => 'decimal:2'` to casts.

**Acceptance criteria for each:** add casts; run preflight; surface any test that was reading the field as a number (Phase 1 audit listed callers to migrate). Inline-fix straightforward ones; defer substantive refactors to Phase 9 with TODO annotations.

---

### Task 1.5: Phase 1 PR

After 1.1–1.4 commits, open PR `feat(db): storage scale alignment for monetary cols + quantity → scale 4 + missing decimal casts`.

---

## Phase 2 — Settings UI for per-unit `decimal_places`

**Why:** `units.decimal_places` already exists in schema; UI is missing. Users need to configure their unit catalog precision.

**Phase 2 PR title:** `feat(uom): settings UI for per-unit decimal_places + rounding_method`

---

### Task 2.1: Backend — add endpoint to list/update unit decimals

**Files:**
- Modify: `apps/api/app/Modules/Uom/Presentation/Controllers/UomController.php`
- Modify: `apps/api/app/Modules/Uom/Presentation/Requests/UpdateUnitRequest.php`
- Test: `apps/api/tests/Feature/Uom/UpdateUnitDecimalPlacesTest.php`

**Acceptance criteria:**
1. PATCH `/api/v1/units/{id}` accepts `decimal_places` (integer 0–10) and `rounding_method` (HalfUp|Floor|Ceil enum).
2. Authorization: `units.manage` permission required.
3. Validation: `decimal_places` 0–10; `rounding_method` enum-cased.
4. **Cascade rule:** when `decimal_places` changes, queue a background job that updates all `Product` models using this unit to re-validate their stored quantity fields against the new scale. If any product carries a quantity violating the new scale (e.g. shrinking from 4 to 2 and a stock_movement row has `quantity = 1.2345`), the job logs a warning but does NOT touch the data — surfaces as ops alert.

**TDD: failing feature test → implement → green → commit.**

---

### Task 2.2: Frontend — Settings page for unit precision

**Files:**
- Create: `apps/web/src/features/settings/components/UnitDecimalSettings.tsx`
- Test: `apps/web/src/features/settings/components/UnitDecimalSettings.test.tsx`

**Acceptance criteria:**
1. Table of units with columns: `code`, `name`, `decimal_places` (editable number input 0–10), `rounding_method` (select).
2. Save button per row, optimistic update with react-query.
3. **Warning banner** when narrowing scale: "Changing this may render existing quantity values inexact. Review the impact report after save."
4. Component test: render, edit a row, click save, assert API was called with `{ decimal_places: N, rounding_method: 'HalfUp' }`.

---

### Task 2.3: Seed sensible defaults per unit family

**Files:**
- Modify (or create): `apps/api/database/seeders/UnitSeeder.php`

**Defaults to apply (canonical UoM catalog):**

| Unit code | decimal_places | rounding_method | Rationale |
|---|---|---|---|
| EA, PCS, BOX, CTN, PACK | 0 | HalfUp | Whole items |
| KG | 3 | HalfUp | Sub-gram precision for retail weighed goods |
| G, MG | 0 | HalfUp | Already in smallest customary unit |
| L | 3 | HalfUp | Sub-millilitre precision |
| ML | 0 | HalfUp | Already in smallest customary unit |
| M | 3 | HalfUp | Sub-mm precision for hardware |
| CM, MM | 0 | HalfUp | Already in smallest customary unit |
| M3, M2 | 4 | HalfUp | Construction precision |
| HR | 2 | HalfUp | Billable time (matches Workshop) |
| MIN | 0 | HalfUp | Integer minutes |
| KWH | 3 | HalfUp | Utility billing |

**Acceptance criteria:**
1. Seeder is idempotent (uses `updateOrCreate`).
2. Runs as part of the standard `DatabaseSeeder` chain.
3. Existing tenant units get their `decimal_places` updated IF currently `2` (the migration default).

---

### Task 2.4: Phase 2 PR

After 2.1–2.3, open PR `feat(uom): per-unit decimal_places settings + canonical defaults`.

---

## Phase 3 — Backend service bcmath sweep

**Why:** ~43 service-layer bcmath sites hardcode scale `2`. After Phase 0, every service injects `CurrencyScaleResolverInterface` and uses `$this->scale()` (or `QuantityScaleResolverInterface` and `$this->quantityScale()` for quantity sites). Inventory's `StockAdjustmentService` is being migrated by the parallel session — do NOT touch it here.

**Phase 3 PRs (one per cluster to keep reviews scoped):**

---

### Task 3.1: AgedReceivablesService — replace hardcoded 2 with resolver scale

**Files:**
- Modify: `apps/api/app/Modules/Document/Application/Services/AgedReceivablesService.php:86,106,113,120,211,235,260,333`
- Test: `apps/api/tests/Feature/Document/AgedReceivablesScalingTest.php` (new)

**Acceptance criteria:**
1. Service already injects `CurrencyScaleResolverInterface` (per Agent F inventory). Replace every literal `2` in bcadd/bcsub/bcmul/bccomp calls with `$this->scaleResolver->getScale()`.
2. New regression test seeds 5 TND invoices with `balance_due = '1234.567'` each, calls the aging service, asserts the bucket total = `'6172.835'` (NOT `'6172.80'`).
3. Run preflight.

**TDD per the example pattern:**
1. Write failing test asserting `'6172.835'`.
2. Run → FAIL.
3. Replace literal `2` with `$this->scaleResolver->getScale()`.
4. Run → PASS.
5. Commit.

---

### Task 3.2: Document\Domain\Services\RefundService scale=2 → resolver

**Files:**
- Modify: `apps/api/app/Modules/Document/Domain/Services/RefundService.php:238,239,240,330`
- Test: `apps/api/tests/Feature/Document/RefundServiceScalingTest.php` (new)

**Acceptance criteria:**
1. Inject `CurrencyScaleResolverInterface` (constructor add).
2. Replace literal `2` with `$this->scaleResolver->getScale()`.
3. Regression test: 3-line TND credit note (12.347 + 5.123 + 8.999) asserts `documents.total = '26.469'`.

**TDD same shape as 3.1.**

---

### Task 3.3: Compliance UninvoicedDeliveryNoteService scale=2

**Files:**
- Modify: `apps/api/app/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php:109-111,165-167,204`
- Test: `apps/api/tests/Feature/Compliance/UninvoicedDeliveryNoteScalingTest.php` (new)

**Acceptance criteria:** same pattern. Regression test: 100 DN of `1234.567 TND` sums to `'123456.700'`.

---

### Task 3.4: BatchExpiry BatchWriteOffService scale=2

**Files:**
- Modify: `apps/api/app/Modules/BatchExpiry/Domain/Services/BatchWriteOffService.php:140`
- Test: `apps/api/tests/Feature/BatchExpiry/BatchWriteOffScalingTest.php` (new)

**Acceptance criteria:** Inject `CurrencyScaleResolverInterface`. Replace `bcmul($quantity, $unitCost, 2)` with `bcmul($quantity, $unitCost, $this->scaleResolver->getScale())`. Regression test: write off 100.5000 units × 1.234 TND asserts journal line debit = `'124.017'`.

---

### Task 3.5: Treasury VendorRefundService scale=2

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Domain/Services/VendorRefundService.php:70,135,147`
- Test: `apps/api/tests/Feature/Treasury/VendorRefundScalingTest.php` (new)

---

### Task 3.6: CartConversionService scale=3 → quantity-aware

**Files:**
- Modify: `apps/api/app/Modules/Cart/Application/Services/CartConversionService.php:84,85,113,172,173,201`
- Test: `apps/api/tests/Feature/Cart/CartConversionScalingTest.php`

**Acceptance criteria:** for each `bcmul($qty, $unitPrice, 3)`, use `bcmul($qty, $unitPrice, $this->scaleResolver->getScale() + 1)` for intermediates, then `CurrencyScale::bcformat(..., $this->scaleResolver->getScale())` at the boundary. This preserves the 4th decimal of quantity during multiplication, only rounding at the end.

---

### Task 3.7: TaxCalculationService — intermediate at scale + 1

**Files:**
- Modify: `apps/api/app/Modules/Taxation/Domain/Services/TaxCalculationService.php:72,79-82,84,101,111,129,133-134,155-157,161`
- Test: `apps/api/tests/Feature/Taxation/TaxCalculationScalingTest.php` (new)

**Acceptance criteria:** every `bcmul` working with `quantity decimal:4` × `unit_price` uses `$this->scaleResolver->getScale() + 1` as intermediate. Regression test: 1000 lines of `qty=0.9999, price=0.0019 TND` sum closer to true total than the current scale-3 implementation.

---

### Task 3.8: LandedCostService — full bcmath migration

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/LandedCostService.php` (entire file)
- Test: `apps/api/tests/Feature/Inventory/LandedCostBcmathTest.php` (new)

**Acceptance criteria:**
1. Every `(float)` cast removed from the service. Replace with `(string) $line->line_total` etc.
2. Every native `*`, `/`, `+`, `-` on monetary values becomes `bcmul/bcdiv/bcadd/bcsub` at `$this->scale() + 4` intermediate.
3. Final `round()` calls become `CurrencyScale::bcformat(..., $this->scale())`.
4. Regression test: 30-line PO with realistic TND values asserts `document_lines.allocated_costs` sum = `sum($input_costs)` exactly (no IEEE-754 drift).

**This task is large.** Codex should commit per-method (allocateCosts, then allocateCostsAndTaxes, etc.) to keep reviews scoped. Each commit must run preflight green.

---

### Task 3.9: WeightedAverageCostService — full bcmath migration

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` (entire file)
- Test: `apps/api/tests/Feature/Inventory/WacBcmathTest.php` (new)

**Acceptance criteria:**
1. Every `(float)` cast removed.
2. Every `$currentValue + ($quantity * $landedUnitCost)` becomes `bcadd($currentValue, bcmul($quantity, $landedUnitCost, scale + 4), scale + 4)`.
3. `round($newValue / $newQty, $this->scale())` becomes `CurrencyScale::bcformat(bcdiv($newValue, $newQty, $this->scale() + 4), $this->scale())`.
4. Regression test: 100 sequential receipts of `qty=0.1, cost=0.1 TND` asserts no drift > 0 millième.

---

### Task 3.10: ReceiptCreationService — fix scale-4-compare-vs-scale-2-write

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptCreationService.php:869,887`
- Modify: `apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php:875`
- Test: `apps/api/tests/Feature/POS/ReceiptStockDecrementScalingTest.php` (new)

**Acceptance criteria:**
1. After Phase 1.3 widens `pos_receipt_lines.quantity` to scale 4 AND the Inventory parallel session widens `stock_levels.quantity` to scale 4, the bccomp and bcsub should both use the quantity scale (4) from `QuantityScaleResolverInterface`.
2. Regression test: parapharma TND scenario where qty='0.0010' confirms stock decrements correctly.

**Dependency:** waits on Inventory parallel-fix merge.

---

### Task 3.11: ReceiptReturnService — symmetric VAT bcmath (eliminate float)

**Files:**
- Modify: `apps/api/app/Modules/POS/Application/Services/ReceiptReturnService.php:1049-1055`
- Test: `apps/api/tests/Feature/POS/ReturnVatSymmetryTest.php` (new)

**Acceptance criteria:**
1. Replace the float `$raw = (float) $netAmount * (float) $taxRate / 100.0;` with bcmath at extra precision, mirroring `ReceiptCreationService::roundVat`.
2. Extract a shared trait or helper so the two paths cannot drift again.
3. Regression test: 100 random (netAmount, taxRate) pairs produce identical results from both services.

---

### Task 3.12: PayrollExportController — multiply-first bcmath

**Files:**
- Modify: `apps/api/app/Modules/Workshop/Technician/Presentation/Controllers/PayrollExportController.php:62-73`
- Test: `apps/api/tests/Feature/Workshop/PayrollExportPrecisionTest.php` (new)

**Acceptance criteria:**
1. Replace `$hours = $minutes / 60; bcmul($costRate, (string) $hours, 6)` with `bcdiv(bcmul($costRate, (string) $minutes, 6), '60', 6)` (multiply first, divide second, no float).
2. Regression test: 36000 minutes × 25.123 TND/hour = exact `'15073.800'` (the canonical gold-standard).

---

### Task 3.13: UnitConversionService — bcmath-native rounding

**Files:**
- Modify: `apps/api/app/Modules/Uom/Domain/Services/UnitConversionService.php:85-87`
- Test: `apps/api/tests/Feature/Uom/UnitConversionPrecisionTest.php` (new)

**Acceptance criteria:**
1. Replace the float `round/floor/ceil` in `UnitConversionService::round()` with `QuantityScale::round($value, $decimalPlaces, $method)` (Task 0.5).
2. Regression test: convert `99999999999999.9999` between units; assert no scientific notation in output.

---

### Task 3.14: Loyalty PointEarningService — bcmath instead of float multiplication

**Files:**
- Modify: `apps/api/app/Modules/Loyalty/Domain/Services/PointEarningService.php:196,199,214,218,272`
- Test: `apps/api/tests/Feature/Loyalty/EarningPrecisionTest.php` (new)

**Acceptance criteria:**
1. Every `$amount * $rewardValue` becomes `bcmul((string) $amount, (string) $rewardValue, $this->scale() + 4)`, then `CurrencyScale::bcformat(..., $this->scale())`.
2. `(float)` casts on `$rule->conditions['min_purchase_amount']` etc. become `(string)`.
3. Regression test: 10000 sequential earnings of `0.1 TND × 0.1` reward multiplier asserts ledger sum = `100.000` exactly.

---

### Task 3.15: CompanyController.reservation_settings — replace `number_format((float)…)` with `bcformat`

**Files:**
- Modify: `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:354,357,373,397,400,404`
- Test: `apps/api/tests/Feature/Company/ReservationSettingsPrecisionTest.php` (new)

**Acceptance criteria:**
1. Inject `CurrencyScaleResolverInterface`.
2. Every `number_format((float) $validated['…'], 2, '.', '')` becomes `CurrencyScale::bcformatStrict((string) $validated['…'], $this->scaleResolver->getScale())`.
3. Regression test: POST a threshold value of `"1234.567 TND"`. Assert the stored JSONB value preserves the millième.

---

### Task 3.16: InvoiceService::createManualInvoice — bcmath

**Files:**
- Modify: `apps/api/app/Modules/Billing/Application/Services/InvoiceService.php:107-115`
- Test: `apps/api/tests/Feature/Billing/CreateManualInvoicePrecisionTest.php` (new)

**Acceptance criteria:**
1. Replace `$subtotal = 0; $itemAmount = (float) $item['amount'] * $quantity; $subtotal += $itemAmount;` with bcmath accumulation.
2. Regression test: 3 items, asserts subtotal preserves canonical precision.

---

### Task 3.17: Phase 3 PR(s)

Group tasks into logical PRs:
- `feat(scope): document RefundService + AgedReceivables + Compliance scale alignment` (3.1, 3.2, 3.3)
- `feat(scope): treasury + batch-expiry scale alignment` (3.4, 3.5)
- `feat(scope): cart + taxation scale-bridge precision` (3.6, 3.7)
- `feat(scope): inventory WAC + landed-cost bcmath migration` (3.8, 3.9) — depends on Inventory parallel fix merging first
- `feat(scope): POS receipt stock decrement + return VAT symmetry` (3.10, 3.11) — depends on Inventory parallel fix
- `feat(scope): workshop payroll + uom rounding bcmath` (3.12, 3.13)
- `feat(scope): loyalty earning + company refund-policy precision` (3.14, 3.15)
- `feat(scope): billing manual invoice precision` (3.16)

---

## Phase 4 — Ingress validators (FormRequest regex sweep)

**Why:** ~120 bare `numeric` validators across modules. Each one is a silent-rounding entry point at the API boundary.

**Sweep pattern (apply to every Task 4.X):**

For each FormRequest field whose destination column is `decimal(N, S)`:
- Replace `'numeric|min:0'` with `'string', 'regex:/^-?\d+(\.\d{1,S})?$/', new MinDecimalRule('0', S)` (or just `regex` + `min` if MinDecimalRule doesn't exist — create one in Phase 0 if needed).
- Document the field's destination column scale in a code comment.
- Update DTO field types from `?float` to `?string` if applicable.

For each quantity field whose destination is `decimal(N, 4)`:
- Use `regex:/^-?\d+(\.\d{1,4})?$/` for the storage ceiling.
- For UoM-aware ingress (e.g. POS line quantity for a specific product), use `decimal:0,S` where S comes from the resolver — defer to Phase 4.X-PerUoM if applicable.

**Phase 4 PRs (per module):**

---

### Task 4.1: POS receipt money fields (StoreReceiptRequest + AddOrderLine + ModifyOrderLine + DiscountController inline + StoreReturn)

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Requests/StoreReceiptRequest.php:67-110`
- Modify: `apps/api/app/Modules/POS/Presentation/Requests/AddOrderLineRequest.php:45-48`
- Modify: `apps/api/app/Modules/POS/Presentation/Requests/ModifyOrderLineRequest.php:32-33`
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/DiscountController.php:146-153`
- Modify: `apps/api/app/Modules/POS/Presentation/Requests/StoreReturnRequest.php:52`
- Test: `apps/api/tests/Feature/POS/IngressPrecisionTest.php` (new)

**Acceptance criteria:**
1. Money fields: `unit_price`, `discount_amount`, `tax_amount`, `loyalty_discount_amount`, `transaction_discount_amount` — regex matches scale 3.
2. Quantity fields: `quantity` — regex matches scale 4 (after Task 1.3 widening).
3. Tax rate: `tax_rate` — regex matches scale 2.
4. Regression test: POST a 4-decimal `unit_price` to `/api/v1/pos/receipts` — assert 422 with a clear error message.

---

### Task 4.2: Document Create/Update lines

**Files:**
- Modify: `apps/api/app/Modules/Document/Presentation/Requests/CreateDocumentRequest.php:108-112`
- Modify: `apps/api/app/Modules/Document/Presentation/Requests/UpdateDocumentRequest.php:86-90`
- Modify: `apps/api/app/Modules/Document/Presentation/Controllers/CreditNoteController.php:153-165` (inline validation)
- Modify: `apps/api/app/Modules/Document/Presentation/Requests/Concerns/AppliesDiscountToleranceRule.php:84-103`
- Test: `apps/api/tests/Feature/Document/IngressPrecisionTest.php` (new)

**Same pattern as 4.1.**

---

### Task 4.3: Inventory stock-movement sibling controllers (DEPENDS on Inventory parallel fix)

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:65,104,159,216`
- Test: extend `apps/api/tests/Feature/Inventory/StockMovementControllerTest.php`

**Acceptance criteria:** quantity regex matches scale 4 (post-inventory-parallel-fix). Coordinate with that session's reviewer before merging.

---

### Task 4.4: Treasury payment ingress (8 endpoints)

**Files:**
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentController.php:125,136,138`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/MultiPaymentController.php:97,152,250,337`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentInstrumentController.php:96`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRefundController.php:94`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/BankReconciliationController.php:91`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:76,154`
- Modify: `apps/api/app/Modules/Treasury/Presentation/Requests/RefundPrepaymentRequest.php:33`
- Test: `apps/api/tests/Feature/Treasury/IngressPrecisionTest.php` (new)

---

### Task 4.5: Accounting journal entry + opening balance batch

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Presentation/Requests/CreateJournalEntryRequest.php:41-42`
- Modify: `apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php:399-414`
- Test: `apps/api/tests/Feature/Accounting/JournalEntryIngressTest.php` (new)

---

### Task 4.6: Pricing margin check — fix `(float) sell_price` launder

**Files:**
- Modify: `apps/api/app/Modules/Pricing/Presentation/Controllers/PricingController.php:479,489,501`
- Modify: `apps/api/app/Modules/Product/Application/Services/MarginService.php:73,98,122,149` (bcmath rewrite)
- Test: `apps/api/tests/Feature/Pricing/MarginCheckPrecisionTest.php` (new)

**Acceptance criteria:**
1. Drop `(float)` cast at line 489. Pass `(string) $validated['sell_price']` to MarginService.
2. Rewrite MarginService to use bcmath throughout (no `round($cost * (1 + $margins['target_margin'] / 100), $this->scale())`).
3. Regression test: edge-case sell_price right at the discount-permission threshold doesn't flip due to IEEE-754.

---

### Task 4.7: Z-report sync validator alignment

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:72-73`
- Test: extend `apps/api/tests/Feature/POS/ZReportSyncTest.php`

**Acceptance criteria:** replace `['required', 'numeric']` with `['required', 'string', 'regex:/^\d+(\.\d{1,4})?$/']`. Match OpenShiftRequest pattern.

---

### Task 4.8: Taxation withholding rate + DTO type fix

**Files:**
- Modify: `apps/api/app/Modules/Taxation/Presentation/Requests/CreateWithholdingCertificateRequest.php:55`
- Modify: `apps/api/app/Modules/Taxation/Application/DTOs/CreateWithholdingCertificateData.php:30` (change `?float` to `?string`)
- Modify: `apps/api/app/Modules/Taxation/Presentation/Requests/RecordSalesWithholdingRequest.php:48-51`
- Modify: `apps/api/app/Modules/Taxation/Presentation/Requests/CreateWithholdingRuleRequest.php:31-32`
- Modify: `apps/api/app/Modules/Taxation/Presentation/Requests/UpdateWithholdingRuleRequest.php:29-30`
- Test: `apps/api/tests/Feature/Taxation/WithholdingPrecisionTest.php` (new)

**Acceptance criteria:**
1. `manual_rate_percentage` regex: `/^\d+(\.\d{1,2})?$/` (max 2 percent decimals → scale 4 after /100).
2. DTO `?float` → `?string`. Every consumer that reads `(float) $dto->manualRatePercentage` becomes `(string) $dto->manualRatePercentage` (or just `$dto->manualRatePercentage`).
3. Regression test: POST `manual_rate_percentage = 12.345` returns 422.

---

### Task 4.9: Workshop FormRequests

**Files:**
- Modify: `apps/api/app/Modules/Workshop/WorkOrder/Presentation/Requests/AddLineRequest.php:30-35`
- Modify: `apps/api/app/Modules/Workshop/WorkOrder/Presentation/Requests/UpdateLineRequest.php:24-28`
- Modify: `apps/api/app/Modules/Workshop/WorkOrder/Presentation/Requests/AddBundleRequest.php:23`
- Modify: `apps/api/app/Modules/Workshop/Bundle/Presentation/Requests/StoreBundleRequest.php:28-31`
- Modify: `apps/api/app/Modules/Workshop/Bundle/Presentation/Requests/AddComponentRequest.php:26-28`
- Modify: `apps/api/app/Modules/Workshop/Bundle/Presentation/Requests/PatchComponentRequest.php:35-37`
- Modify: `apps/api/app/Modules/Workshop/Bundle/Presentation/Requests/UpdateBundleRequest.php:27-30`
- Test: `apps/api/tests/Feature/Workshop/IngressPrecisionTest.php` (new)

---

### Task 4.10: Coupon, Promotion, Loyalty, Voucher money fields

**Files:**
- Modify: `apps/api/app/Modules/Coupon/Presentation/Requests/StoreCouponRequest.php:47-49`
- Modify: `apps/api/app/Modules/Coupon/Presentation/Requests/UpdateCouponRequest.php:48-50`
- Modify: `apps/api/app/Modules/Coupon/Presentation/Requests/ValidateCouponRequest.php:28` — **change `integer|min:1` to `numeric|min:0.0001`** (per F-COUPON-QTY)
- Modify: `apps/api/app/Modules/Promotion/Presentation/Requests/StorePromotionRequest.php:52,53`
- Modify: `apps/api/app/Modules/Promotion/Presentation/Requests/UpdatePromotionRequest.php:50,52,53`
- Modify: `apps/api/app/Modules/Loyalty/Presentation/Requests/CreateEarningRuleRequest.php:50,54,55`
- Modify: `apps/api/app/Modules/Loyalty/Presentation/Requests/UpdateEarningRuleRequest.php:34-35,50,54-55`
- Modify: `apps/api/app/Modules/Loyalty/Presentation/Requests/CreateRewardRequest.php:32,33,41,42`
- Modify: `apps/api/app/Modules/Loyalty/Presentation/Requests/UpdateRewardRequest.php:32-42`
- Modify: `apps/api/app/Modules/Loyalty/Presentation/Requests/CreateTierRequest.php:34,36,42`
- Modify: `apps/api/app/Modules/Loyalty/Presentation/Requests/UpdateTierRequest.php:34,36,42`
- Modify: `apps/api/app/Modules/Loyalty/Presentation/Requests/EnrollMemberRequest.php:40`
- Modify: `apps/api/app/Modules/Loyalty/Presentation/Requests/AdjustPointsRequest.php:22`
- Modify: `apps/api/app/Modules/Voucher/Presentation/Requests/IssueGoodwillRequest.php:27` — tighten regex to `/^\d+(\.\d{1,5})?$/`
- Test: per-module ingress precision tests

---

### Task 4.11: Catalog (Recipe, Composite, Modifier, Variant) money fields

**Files:**
- Modify: `apps/api/app/Modules/Catalog/Presentation/Requests/StoreRecipeRequest.php:33`
- Modify: `apps/api/app/Modules/Catalog/Presentation/Requests/UpdateRecipeRequest.php:33`
- Modify: `apps/api/app/Modules/Catalog/Presentation/Requests/StoreRecipeLineRequest.php:45,51`
- Modify: `apps/api/app/Modules/Catalog/Presentation/Requests/StoreVariantRequest.php:27-28`
- Modify: `apps/api/app/Modules/Catalog/Presentation/Requests/StoreModifierRequest.php:36,39`
- Modify: `apps/api/app/Modules/Catalog/Presentation/Requests/StoreCompositeItemRequest.php:48,51,63`
- Modify: `apps/api/app/Modules/Catalog/Presentation/Requests/UpdateCompositeItemRequest.php:50,53,65`

---

### Task 4.12: Service / Marketplace / Partner / Identity / Product money fields

**Files:**
- Modify: `apps/api/app/Modules/Service/Presentation/Requests/CreateServiceRequest.php:40-44`
- Modify: `apps/api/app/Modules/Service/Presentation/Requests/UpdateServiceRequest.php:42-46`
- Modify: `apps/api/app/Modules/Marketplace/Presentation/Controllers/MarketplaceSellerController.php:49,70`
- Modify: `apps/api/app/Modules/Marketplace/Presentation/Controllers/MarketplaceOrderController.php:31`
- Modify: `apps/api/app/Modules/Partner/Presentation/Requests/CreatePartnerRequest.php:79-80`
- Modify: `apps/api/app/Modules/Partner/Presentation/Requests/UpdatePartnerRequest.php:84-85`
- Modify: `apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php:50`
- Modify: `apps/api/app/Modules/Product/Presentation/Requests/CreateProductRequest.php:50-52`
- Modify: `apps/api/app/Modules/Product/Presentation/Requests/UpdateProductRequest.php:52-54,107`
- Modify: `apps/api/app/Modules/Scheduling/Presentation/Requests/UpdateScheduleConfigRequest.php:29`
- Modify: `apps/api/app/Modules/Menu/Presentation/Requests/AddMenuCategoryItemRequest.php:27`
- Modify: `apps/api/app/Modules/Menu/Presentation/Requests/SyncMenuCategoryItemsRequest.php:28`

---

### Task 4.13: DocumentAdditionalCost + Expense + Cart + Taxation ingress

**Files:**
- Modify: `apps/api/app/Http/Controllers/Api/DocumentAdditionalCostController.php:49,83`
- Modify: `apps/api/app/Modules/Expense/Presentation/Requests/ExpenseRequest.php:68`
- Modify: `apps/api/app/Modules/Cart/Presentation/Controllers/CatalogCartController.php:177,180,233`
- Modify: `apps/api/app/Modules/Taxation/Presentation/Controllers/TaxConfigurationController.php:77,78,129,130`
- Modify: `apps/api/app/Modules/Taxation/Presentation/Controllers/StampDutyRuleController.php:69,92`

---

### Task 4.14: Scheduling AppointmentService.estimated_price normalization

**Files:**
- Modify: `apps/api/app/Modules/Scheduling/Application/Services/AppointmentAuthoringService.php:127`
- Modify: `apps/api/app/Modules/Scheduling/Presentation/Requests/StoreAppointmentRequest.php:49`
- Modify: `apps/api/app/Modules/Scheduling/Presentation/Requests/BookAppointmentStorefrontRequest.php:57`
- Test: `apps/api/tests/Feature/Scheduling/AppointmentEstimatedPricePrecisionTest.php` (new)

**Acceptance criteria:** Inject `CurrencyScaleResolverInterface`; normalize via `CurrencyScale::bcformatStrict($planned['estimated_price'], $this->scaleResolver->getScale())` before assignment. Validator regex `/^\d+(\.\d{1,3})?$/`.

---

### Task 4.15: Phase 4 PRs

Group:
- `feat(ingress): POS + Document precision regex sweep` (4.1, 4.2)
- `feat(ingress): Treasury + Accounting + Pricing precision regex sweep` (4.4, 4.5, 4.6)
- `feat(ingress): POS Z-sync + Taxation withholding precision` (4.7, 4.8)
- `feat(ingress): Workshop + Catalog + Service + Marketplace precision regex sweep` (4.9–4.12)
- `feat(ingress): Cart + Expense + Scheduling + remaining precision regex sweep` (4.13, 4.14)
- `feat(ingress): Inventory sibling stock-movement controllers` (4.3) — coord with parallel fix

---

## Phase 5 — JSONB content tightening

**Why:** ~6 JSONB columns are float-laundered in their producers. The JSONB layer bypasses Eloquent `decimal:N` casts so it's only protected by producer discipline.

**Phase 5 PRs:**

---

### Task 5.1: Opening-balance staging — preserve canonical strings

**Files:**
- Modify: `apps/api/app/Modules/Accounting/Application/Services/OpeningBalanceBatchService.php:154`
- Modify: `apps/api/app/Modules/Accounting/Presentation/Controllers/OpeningBalanceBatchController.php:399-414`
- Modify: `apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:144,150,156`
- Modify: `apps/api/app/Modules/Inventory/Application/Services/InventoryOpeningService.php:165-178`
- Test: `apps/api/tests/Feature/Accounting/OpeningBalanceStagingPrecisionTest.php` (new)

**Acceptance criteria:**
1. Controller validators add regex constraints on debit/credit/quantity/unit_cost/total/open_amount.
2. The raw `json_encode($row)` write becomes `json_encode($row, JSON_PRESERVE_ZERO_FRACTION)` AND each numeric field is pre-canonicalized via `bcformatStrict` before encoding.
3. Regression test: post a row with `debit = '10000.10'`. Assert the JSONB stored value is `"10000.10"` literally (not `"10000.1"`).

---

### Task 5.2: Inventory counting events — remove `float` typed params

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Domain/InventoryCountingEvent.php:127-145, 150-167, 172-192`
- Test: extend Inventory counting tests

**Acceptance criteria:**
1. Change parameter types: `float $quantity` → `string $quantity`, `float $finalQty` → `string $finalQty`.
2. Replace `(float) $item->theoretical_qty` with `(string) $item->theoretical_qty`.
3. Variance = `bcsub($finalQty, (string) $item->theoretical_qty, 4)`.
4. JSONB writes use the canonical string values.

**Coordination:** if this overlaps with the in-flight Inventory parallel session, defer to that PR or coordinate via the parallel session's owner.

---

### Task 5.3: Loyalty transaction metadata + earning_rules.conditions

**Files:**
- Modify: `apps/api/app/Modules/Loyalty/Application/Services/EarningProcessingService.php:74,107,125-130`
- Modify: `apps/api/app/Modules/Loyalty/Domain/Services/PointEarningService.php:72,82,196-272,282,303,317,328`
- Test: `apps/api/tests/Feature/Loyalty/EarningConditionsPrecisionTest.php` (new)

**Acceptance criteria:**
1. Replace `(float) $conditions['min_purchase_amount']` with `(string) ($conditions['min_purchase_amount'] ?? '0')`.
2. Replace `$amount * $rewardValue` with bcmath (also covered in Task 3.14).
3. `$transactionData` passed to JSONB is pre-canonicalized via `CurrencyScale::bcformat` for monetary keys before encode.

---

### Task 5.4: POS held order cart_snapshot scale validation

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Requests/HoldOrderRequest.php:37-40`
- Modify: `apps/api/app/Modules/POS/Application/Services/HeldOrderService.php:60`
- Test: extend `apps/api/tests/Feature/POS/HeldOrderTest.php`

**Acceptance criteria:**
1. Validator regex on `cart_snapshot.lines.*.quantity` (scale 4), `unit_price` (scale 3), `tax_rate` (scale 2), `discount_amount` (scale 3).
2. Producer canonicalizes via bcformat before writing to JSONB.

---

### Task 5.5: POS Z-report sync receipt_snapshots per-key validation

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:71-105`
- Test: extend `apps/api/tests/Feature/POS/ZReportSyncTest.php`

**Acceptance criteria:**
1. `receipt_snapshots.*.subtotal/tax_amount/total/discount_amount` — regex per scale 3.
2. `report_data.tolerance_summary.totalAmount` — regex per scale 3.
3. Reject device-supplied JSONB if any numeric value violates the scale ceiling. Tells the POS app what to fix instead of silently rounding.

---

### Task 5.6: Re-introduce PG CHECK on `pos_receipt_lines` line_total invariant

**Files:**
- Create: `apps/api/database/migrations/2026_05_29_100003_restore_pos_receipt_lines_line_total_check.php`
- Test: `apps/api/tests/Feature/POS/ReceiptLineTotalInvariantTest.php` (new)

**Acceptance criteria:**
1. Add a CHECK constraint asserting `line_total = round((unit_price * quantity) - discount_amount, 3)` (per-currency-scale; use scale 3 as the canonical write contract).
2. Pre-check: assert no existing row violates the invariant. Provide an SQL query that surfaces violators with a clear path forward.
3. Documents the invariant in the migration's comment so future maintainers know why composite/modifier adjustments must update `discount_amount` (or a new column) instead of bypassing the formula.

**Coordination:** the original constraint was dropped when modifier price_adjustments were introduced. Verify with POS module owner that the current arithmetic path satisfies the formula.

---

### Task 5.7: Phase 5 PR(s)

Group:
- `feat(jsonb): opening-balance staging precision + restore pos receipt line CHECK` (5.1, 5.6)
- `feat(jsonb): inventory counting events + loyalty metadata precision` (5.2, 5.3)
- `feat(jsonb): held-order + Z-report sync per-key validation` (5.4, 5.5)

---

## Phase 6 — Resources cleanup

**Why:** small but visible; co-deliverable with Phase 1.4 model casts.

---

### Task 6.1: `DocumentTaxBreakdownResource` discount hardcoded `'0.00'`

**Files:**
- Modify: `apps/api/app/Modules/Taxation/Presentation/Resources/DocumentTaxBreakdownResource.php:27`
- Test: `apps/api/tests/Feature/Taxation/DocumentTaxBreakdownResourceTest.php` (new)

**Acceptance criteria:** replace `'0.00'` with `CurrencyScale::bcformat('0', $this->currencyScale())` (inject resolver if needed). Regression test: TND document Resource returns `discount: '0.000'`, not `'0.00'`.

---

### Task 6.2: TerminalResource drop `(float)` cast

**Files:**
- Modify: `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php:52`

**Acceptance criteria:** drop the `(float)` cast; rely on model `decimal:2` cast (added in Phase 1.4). Display preserves trailing zero (e.g. `'15.00'` not `15.0`).

---

### Task 6.3: WithholdingRuleResource drop `(float)` rate cast

**Files:**
- Modify: `apps/api/app/Modules/Taxation/Presentation/Resources/WithholdingRuleResource.php:41`
- Modify: `apps/api/app/Modules/Taxation/Application/DTOs/WithholdingRuleData.php:68` (`getRateAsPercentage(): string`)

**Acceptance criteria:** type-system contract escape closed.

---

### Task 6.4: Phase 6 PR

Open `feat(resources): drop (float) launders + fix hardcoded scale-2 discount`.

---

## Phase 7 — Value-object refactor

**Why:** Billing `Money` and Loyalty `PointsAmount`/`LoyaltyBalance` store as `float`. Refactoring requires touching every callsite (8 Billing + ~10 Loyalty); large but surgical.

**Phase 7 PRs (one per VO to minimize blast radius):**

---

### Task 7.1: Billing `Money` VO → numeric-string + bcmath

**Files:**
- Modify: `apps/api/app/Modules/Billing/Domain/ValueObjects/Money.php` (entire file)
- Modify: 8 Billing callers: `Plan.php:90,102`, `Invoice.php:151,159,194,195,224`, `Payment.php:149,157,185,195,210,231,232`, `Refund.php:89,105`, `TenantSubscription.php:121`, `InvoiceItem.php:93,101,109`, `StripeWebhookController.php:544`, `AdminBillingController.php:277,388,316`
- Test: `apps/api/tests/Unit/Billing/MoneyBcmathTest.php` (new); update existing Money tests

**Acceptance criteria:**
1. `Money` constructor takes `string $amount` (numeric-string), stores as `readonly string`.
2. `add/subtract/multiply` use bcmath; return new `Money` with currency-aware scale.
3. `equals()` uses `bccomp($a, $b, scale($currency))` — NO tolerance comparison (no `< 0.001`).
4. `toCents()` uses `bcmul($this->amount, '100', 0)` for Stripe integration (integer cents).
5. Every caller's `new Money((float) $field, ...)` becomes `new Money((string) $field, ...)`.
6. Stripe webhook controller `(float)$payment->amount` → `(string)$payment->amount`.
7. Full Billing test suite green.

**Step pattern: TDD per VO refactor — write failing tests for canonical-string semantics, refactor, fix callers, run preflight.**

---

### Task 7.2: Loyalty `PointsAmount` → numeric-string

**Files:**
- Modify: `apps/api/app/Modules/Loyalty/Domain/ValueObjects/PointsAmount.php` (entire file)
- Modify: every caller (Phase-1 audit listed PointEarningService, RewardRedemptionService, etc.)
- Test: `apps/api/tests/Unit/Loyalty/PointsAmountBcmathTest.php`

**Acceptance criteria:** same shape as 7.1. Tolerance comparison removed.

---

### Task 7.3: Loyalty `LoyaltyBalance` → numeric-string + currency-scale-aware tolerance

**Files:**
- Modify: `apps/api/app/Modules/Loyalty/Domain/ValueObjects/LoyaltyBalance.php` (entire file)
- Modify: every caller
- Test: `apps/api/tests/Unit/Loyalty/LoyaltyBalanceBcmathTest.php`

**Acceptance criteria:** balance integrity check uses `bccomp` at the canonical scale (3 for TND, 2 for EUR). No tolerance threshold.

---

### Task 7.4: Phase 7 PR(s)

Group:
- `refactor(billing): Money VO → numeric-string + bcmath` (7.1)
- `refactor(loyalty): PointsAmount + LoyaltyBalance → numeric-string` (7.2, 7.3)

---

## Phase 8 — Notifications

---

### Task 8.1: Billing notifications — derive scale from `invoice.currency`

**Files:**
- Modify: `apps/api/app/Modules/Billing/Notifications/InvoicePaidNotification.php`
- Modify: `apps/api/app/Modules/Billing/Notifications/PaymentSucceededNotification.php`
- Modify: `apps/api/app/Modules/Billing/Notifications/PaymentFailedNotification.php`
- Modify: `apps/api/app/Modules/Billing/Notifications/AdminPaymentAlertNotification.php`
- Test: `apps/api/tests/Feature/Billing/NotificationCurrencyScaleTest.php` (new)

**Acceptance criteria:**
1. Each notification reads `$this->invoice->currency` (already a model field, survives queue serialization) and uses `CurrencyScale::for($currency)` to derive the scale at render time.
2. Replace `bcformat($amount, 2)` with `bcformat($amount, CurrencyScale::for($this->invoice->currency))`.
3. Regression test: queued TND notification renders amount as `"1500.500"`, not `"1500.50"`.

---

### Task 8.2: Phase 8 PR

`fix(billing-notifications): currency-aware scale derivation`.

---

## Phase 9 — Test fixtures + seeders alignment

**Why:** ~9 seeders / factories carry wrong-scale fixture values. After Phase 0.3 changes the mock default to scale 3, surface every test that needs canonical-string fixtures.

---

### Task 9.1: CoffeeShopSeeder — 3-decimal strings for TND tenant

**Files:**
- Modify: `apps/api/database/seeders/CoffeeShopSeeder.php:320-408,354-386,490,528-535,411,492,535`

**Acceptance criteria:** all monetary fields are 3-decimal numeric strings (e.g. `'8.000'` not `8.000`). All quantity fields are 4-decimal strings. Run preflight green.

---

### Task 9.2: ProductFactory + ServiceFactory + ReceiptFactory scale alignment

**Files:**
- Modify: `apps/api/database/factories/ProductFactory.php:41-58`
- Modify: `apps/api/database/factories/ServiceFactory.php:39,73-104,116,131,146,161`
- Modify: `apps/api/database/factories/ReceiptFactory.php:28-30,47-50`
- Modify: `apps/api/database/factories/ReceiptPaymentFactory.php:26`
- Modify: `apps/api/database/factories/MarketplaceListingFactory.php:33,35,45`
- Modify: `apps/api/database/factories/CatalogCartItemFactory.php:26-27`

**Acceptance criteria:** every factory accepts a `currency` state and emits scale-correct numeric strings via `CurrencyScale::bcformat`. Default to TND-3-decimal for backwards compatibility with the codebase's primary verticals (Otospex automotive, IziPOS coffee shop, TunisianParapharmacy).

---

### Task 9.3: TunisianParapharmacySeeder bcmath migration

**Files:**
- Modify: `apps/api/database/seeders/TunisianParapharmacySeeder.php:162-166,456-457`

**Acceptance criteria:** replace `$productData['price'] * 0.6` (float multiplication) with `bcmul((string) $productData['price'], '0.6', 3)`. All values are numeric strings.

---

### Task 9.4: Phase 9 PR

`test(seeders): canonical-string fixtures + factory currency-state support`.

---

## Phase 10 — Frontend `<MoneyInput>` / `<QuantityInput>` rollout

**Why:** 78 `step="0.01"` callsites need migration to the canonical `<MoneyInput>` / `<QuantityInput>` (Task 0.7). Replace per-feature.

**Phase 10 PRs (per feature cluster):**

---

### Task 10.1: POS migrations

**Files:**
- Modify: `apps/pos/src/components/pos/CashTenderedModal.tsx:71`
- Modify: `apps/pos/src/components/pos/CashDrawerModal.tsx:146`
- Modify: `apps/pos/src/components/pos/CloseShiftModal.tsx:81`
- Modify: `apps/pos/src/components/pos/VoucherTenderModal.tsx:448`
- Modify: `apps/pos/src/pages/HomePage.tsx:1034`
- Modify: `apps/web/src/features/pos/components/CashTenderedModal.tsx:84`
- Modify: `apps/web/src/features/pos/components/ReturnItemsModal.tsx:146` (also fixes F-FRONTEND-RETURN — sends quantity at quantity scale, not currency scale)
- Modify: `apps/pos/src/components/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:394` (also fixes F-FRONTEND-VOUCHER — string not number)

**Acceptance criteria:** every `step="0.01"` / `step="0.001"` becomes `<MoneyInput currency={currency}>` or `<QuantityInput decimalPlaces={...}>`. Payload to API is always a string. Vitest tests pass.

---

### Task 10.2: Treasury + Accounting + Document + Expense migrations

**Files (a representative list — Phase-1 audit has the full set):**
- Modify: `apps/web/src/features/treasury/PaymentForm.tsx:422,674`
- Modify: `apps/web/src/features/treasury/SplitPaymentForm.tsx:262`
- Modify: `apps/web/src/features/finance/pages/JournalEntryForm.tsx:242,255`
- Modify: `apps/web/src/features/documents/components/DocumentLineEditor.tsx:419,435,422,438`
- Modify: `apps/web/src/features/documents/components/DocumentLineRow/DocumentLineRow.tsx:129,156,131,158`
- Modify: `apps/web/src/features/documents/components/CreateCreditNoteForm.tsx:304,185` (also fixes hardcoded `.toFixed(4)`)
- Modify: `apps/web/src/features/expenses/components/organisms/ExpenseFormFields.tsx:121`

**Acceptance criteria:** same pattern; also fixes F-FRONTEND-PAYMENT (PaymentForm sends string).

---

### Task 10.3: Loyalty + Coupon + Promotion + Voucher + Partner + Settings migrations

**Files (Phase-1 audit full list applies):**
- Modify: `apps/web/src/features/loyalty/components/{EarningRuleFormModal,RewardFormModal,TierFormModal}.tsx`
- Modify: `apps/web/src/features/coupons/pages/CouponFormPage.tsx:208,219,228`
- Modify: `apps/web/src/features/promotions/pages/PromotionFormPage.tsx:234,245,286`
- Modify: `apps/web/src/features/vouchers/components/IssueGoodwillVoucherModal.tsx:170`
- Modify: `apps/web/src/features/partners/components/B2BFieldsSection.tsx:166,185`
- Modify: `apps/web/src/features/settings/components/InventorySettings.tsx:198,216,365,382`

---

### Task 10.4: Catalog + Service + Workshop frontend migrations

**Files:**
- Modify: `apps/web/src/features/catalog/pages/CompositeItemFormPage.tsx:232,242,473,483`
- Modify: `apps/web/src/features/catalog/pages/ModifierGroupFormPage.tsx:273,302,346`
- Modify: `apps/web/src/features/catalog/components/RecipeLineEditor.tsx:234,308`
- Modify: `apps/web/src/features/catalog/components/VariantEditor.tsx:115,124,187,196`
- Modify: `apps/web/src/features/services/ServiceForm.tsx:335,359,398`
- Modify: `apps/web/src/features/parts-catalog/components/organisms/AddToInventoryModal.tsx:192,206`

---

### Task 10.5: Misc frontend fixes (display-only or single-touchpoint)

**Files:**
- Modify: `apps/web/src/features/admin/pages/MonitoringPage.tsx:420,421` (use `formatCurrency` with platform currency, not hardcoded `$`)
- Modify: `apps/web/src/features/documents/components/DocumentTotals.tsx:50` (use `getDecimals(currency)`)
- Modify: `apps/web/src/features/pricing/PriceListDetailPage.tsx:73-77` (use `useCurrency().format`)
- Modify: `apps/web/src/features/withholding/pages/SalesWithholdingTrackingPage.tsx:225` (use 4 not 2 for display)
- Modify: `apps/web/src/features/treasury/components/ToleranceSettingsDisplay.tsx:54` (same)

---

### Task 10.6: POS `formatCashAmount` — currency-aware (CRITICAL)

**Files:**
- Modify: `apps/pos/src/lib/operatorApproval/cashDrawerApproval.ts:36,128`
- Modify: `apps/pos/src/api/cashDrawerApi.ts:80`

**Acceptance criteria:**
1. Replace `Number.parseFloat(amount).toFixed(3)` with `bcformat(amount, getCurrencyDecimals(currency))` (using `apps/pos/src/lib/decimal.ts:bcformat`).
2. Currency comes from the auth store (`useAuthStore().currentCompany.currency`).
3. Regression test: EUR drawer event canonicalizes amount at scale 2, not 3. Hash matches server's canonicalization.

---

### Task 10.7: POS cart store + cartStore/paymentStore bcmath

**Files:**
- Modify: `apps/pos/src/stores/cartStore.ts:71-78,81-106,282-307`
- Modify: `apps/pos/src/stores/paymentStore.ts:391-405`
- Modify: `apps/pos/src/lib/offline/offlineCheckoutService.ts:97-99`
- Modify: `apps/pos/src/lib/buildReceiptData.ts:200-212`
- Modify: `apps/pos/src/lib/refundFlow/hydrateFromReceipt.ts:46,49`

**Acceptance criteria:** every JS Number arithmetic on monetary values becomes `bcadd/bcmul/bcdiv` (Big.js-based per `apps/pos/src/lib/decimal.ts`). Customer-display sync uses string total. Vitest tests green.

---

### Task 10.8: Phase 10 PR(s)

Group per cluster (POS / Treasury / Loyalty / Catalog / Misc). Total ~6 PRs.

---

## Phase 11 — Regression guards (PHPStan + ESLint)

**Why:** without lint rules, the contract drifts again the next time a feature is added.

---

### Task 11.1: PHPStan custom rule — forbid `(float)` cast on `decimal:N` property access

**Files:**
- Create: `apps/api/phpstan-rules/ForbidFloatCastOnDecimalProperty.php`
- Modify: `apps/api/phpstan.neon` (register rule)

**Acceptance criteria:**
1. Rule flags any `(float) $model->property` where `$model::casts()` declares `'property' => 'decimal:N'`.
2. Self-test: introduces a deliberate `(float)` in a Service, runs phpstan, expects fail.

---

### Task 11.2: PHPStan rule — forbid bare bcmath scale literals in services that aren't UoM-bound

**Files:**
- Create: `apps/api/phpstan-rules/ForbidHardcodedBcmathScale.php`
- Modify: `apps/api/phpstan.neon`

**Acceptance criteria:**
1. Rule flags `bcadd/bcsub/bcmul/bcdiv/bccomp` with a literal integer scale arg in `Modules/*/Application/Services/` and `Modules/*/Domain/Services/`.
2. Exemption list: `RoundingMethod` enum applications, scale-6 intermediates explicitly commented.

---

### Task 11.3: ESLint rule — forbid `step="0.0\d+"` literal; require `<MoneyInput>` / `<QuantityInput>`

**Files:**
- Create: `apps/web/eslint-rules/no-hardcoded-step.js`
- Modify: `apps/web/.eslintrc.cjs`

**Acceptance criteria:**
1. Rule flags `<input type="number" step="0.01" ...>` etc. with a fix-it suggestion.
2. Phases 10.1–10.5 commits leave zero new violations.

---

### Task 11.4: ESLint rule — forbid `parseFloat` on monetary/quantity-named values

**Files:**
- Create: `apps/web/eslint-rules/no-parsefloat-on-money.js`
- Modify: `apps/web/.eslintrc.cjs`

**Acceptance criteria:**
1. Rule flags `parseFloat(amount/price/cost/quantity/...)` patterns.

---

### Task 11.5: Add CI gate

**Files:**
- Modify: `.github/workflows/ci.yml` (add lint-precision job)

**Acceptance criteria:**
1. PR cannot merge if the new lint rules fire.

---

### Task 11.6: Phase 11 PR

`chore(lint): regression guards for precision contracts`.

---

## Phase 12 — Documentation + REALIGNMENT-LOG

---

### Task 12.1: Update `apps/erp/CLAUDE.md` with the new contract

**Files:**
- Modify: `apps/erp/CLAUDE.md`

**Add a new section after the operational rules:**

```markdown
## Precision Contract (post-2026-05-28)

**Storage scales:**
- Currency columns: `decimal(N, 3)` canonical. Outliers (`pos_shifts.*`, `eco_tax_*`, voucher balances) at higher scales — see `docs/architecture/precision-contract.md`.
- Quantity columns: `decimal(N, 4)` canonical. Per-unit display via `units.decimal_places`.

**At rest helpers:**
- Money: `CurrencyScale::bcformatStrict($value, $scaleResolver->getScale())` (never `bcformat($float, ...)`).
- Quantity: `QuantityScale::bcformat($value, $quantityResolver->scaleForProduct($product))`.

**FormRequests must include a precision regex** for every decimal column they write:
- Money: `regex:/^-?\d+(\.\d{1,3})?$/` (adjust scale per column).
- Quantity: `regex:/^-?\d+(\.\d{1,4})?$/`.
- Tax rate / percentage: `regex:/^-?\d+(\.\d{1,2})?$/`.

**Frontend:** never `parseFloat` on monetary or quantity strings. Use `<MoneyInput>` / `<QuantityInput>` for input; `formatCurrency` / `formatQuantity` for display.

**Lint guards:**
- PHPStan rules forbid `(float)` on decimal-cast properties and literal `2`/`3` in bcmath service-layer calls.
- ESLint rules forbid `step="0.0\d+"` literals and `parseFloat` on money/quantity names.
```

---

### Task 12.2: Create `apps/erp/docs/architecture/precision-contract.md`

**Files:**
- Create: `apps/erp/docs/architecture/precision-contract.md`

**Content:** consolidated reference of every monetary and quantity column with its scale, rationale, and rounding contract. Cross-reference all `widen_*` migrations and the canonical helpers.

---

### Task 12.3: Update `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`

**Files:**
- Modify: `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`

**Add an entry:**

```markdown
### 2026-05-28 — Precision Drift Remediation

**What changed (canonical contract):**
- Currency storage canonical scale: 3. Validation regex enforced at FormRequest layer.
- Quantity storage canonical scale: 4. Per-unit display via `units.decimal_places`.
- New `QuantityScaleResolverInterface` analog to `CurrencyScaleResolverInterface`.
- `CurrencyScale::bcformat` accepts only string/int/null; new `bcformatStrict` for write boundaries.
- Frontend formatter consolidated to single `formatCurrency` (currency-aware) + new `formatQuantity`.
- New `<MoneyInput>` / `<QuantityInput>` shared components.

**API impact:**
- Money/quantity validators now reject inputs exceeding the column's scale (422 with clear error message).
- Generated TS types now mark all monetary fields as `string` (previously some were `number`).

**Migration steps for downstream consumers:**
- Send money/quantity payload as `string` (not JS `number`).
- Display money via `formatCurrency(amount: string, currency)`.
- Display quantity via `formatQuantity(value: string, decimal_places)` driven by `product.unit.decimal_places`.
```

---

### Task 12.4: Phase 12 PR

`docs: precision contract documentation + REALIGNMENT-LOG entry`.

---

## Self-Review Checklist

After all phases land:

**1. Spec coverage:** every Phase-1 finding (48 items) and Phase-2 gap-fill finding (~25 items) has a task. Cross-reference: F-POS-1 → 10.6; F-WAC-1/2 → 3.8/3.9; F-TAX-1 → 4.8; F-PERMISSIONS → 1.4f; F-INGRESS-* → 4.1–4.14; F-NOT-1 → 8.1; E-1/E-2 → 5.1; E-4 → 5.2; E-27 → 5.3; W-2 → 3.12; U-1 → 3.13; S-1 → 1.4b. Done.

**2. Placeholder scan:** zero TBD / TODO / "fill in details" in this plan. Every step has either a code block or an exact file:line citation. Done.

**3. Type consistency:** `CurrencyScaleResolverInterface::getScale(?string)` signature consistent across Tasks 0.2, 3.1–3.16, 8.1. `QuantityScaleResolverInterface::scaleForProduct/scaleForUnit/storageScale` consistent across Tasks 0.4, 3.13, 5.2. `MoneyInput`/`QuantityInput` props (`value: string`, `onChange: (string) => void`, `currency: string` / `decimalPlaces: number`) consistent across Tasks 0.7, 10.1–10.5.

---

## Execution Handoff

This plan is sequenced for **Codex** as the primary executor. Codex's strengths fit this work: large mechanical sweeps across hundreds of files (Phase 4 ingress regex, Phase 10 frontend `<MoneyInput>` rollout), structured TDD with explicit acceptance criteria, and disciplined per-task commits.

**Recommended Codex invocation pattern (per Phase):**
1. Codex executor reads this plan and the upstream audit at `docs/superpowers/audits/2026-05-28-precision-drift-audit.md`.
2. Codex runs the listed verification commands (`./scripts/preflight.sh`) at the end of every task.
3. Codex commits per task subject; opens one PR per Phase (or per cluster within a Phase if the cluster spans multiple modules).
4. After each Phase PR, request a code review (use `superpowers:requesting-code-review`) before merging — this work touches fiscal-hash inputs and must be reviewed by a second pair of eyes.

**Sequencing of PRs:**
- **Day 0:** Phase 0 PR opened, reviewed, merged. Foundation lands.
- **Day 1–3 (parallelizable):** Phases 1, 2, 6, 8, 12 in parallel. Each ships independently.
- **Day 4–7:** Phase 3 (services) and Phase 4 (ingress) — sequence per-cluster PRs. Tasks 3.10, 3.11, 4.3 wait for Inventory parallel-fix merge.
- **Day 8–10:** Phase 5 (JSONB) — depends on Phase 0 + 1.
- **Day 11–14:** Phase 7 (VO refactor) — touches many callers, treat as its own sprint.
- **Day 15–18:** Phase 10 (frontend rollout) — depends on Phase 0.7 (MoneyInput/QuantityInput).
- **Day 19–20:** Phase 9 seeders + Phase 11 lint guards — sweep cleanup.

**Risk register:**
1. **Phase 1.3** (quantity column widening) is the largest migration and the most likely to surface untested code paths. Run the full test suite multiple times before opening the PR; expect surprises in Cart / Marketplace / Workshop test fixtures.
2. **Phase 5.6** (re-introducing the dropped `pos_receipt_lines_line_total_check` constraint) — coordinate with POS module owner; the original drop in 2026-03-03 was intentional for composite-modifier work. If the formula needs to allow modifier price_adjustments, the CHECK must be `line_total = unit_price * quantity - discount_amount + (sum of modifier_adjustments)` with a JSONB-aware function. This may require a separate sub-spec.
3. **Phase 7** (VO refactor) — Billing notifications (Phase 8) consume `Money`. Sequence Phase 7 before Phase 8 OR co-deliver 7.1 + 8.1 in the same PR to avoid intermediate broken state.
4. **Inventory parallel fix** must land before Tasks 3.10, 3.11, 4.3, 5.2 (if 5.2 overlaps). Confirm with that session's owner before scheduling those tasks.

**Done definition:**
- Every Phase 0–12 PR merged to `dev`.
- Full preflight green.
- New PHPStan + ESLint rules in CI; no exemptions.
- REALIGNMENT-LOG entry visible to ERP team.
- Spot test: TND tenant on POS terminal can enter `5.123 TND` payment, full chain hash-verifies, customer receipt shows `5.123 TND`, accounting reports show `5.123 TND`.
- Spot test: EUR parapharmacy tenant can ring a 0.5 kg fractional sale, line shows `0.5000 kg`, stock decrement is `0.5000`, COGS posts at canonical scale.

**Estimated total effort:** 6–8 dev-weeks if executed by a single Codex instance with reviewer turnaround. 3–4 dev-weeks if Phases 3, 4, 10 are sharded across multiple Codex sessions in parallel worktrees.

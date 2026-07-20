# UoM Display Precision Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quantities shown to humans render/increment at the product's unit precision (pieces → whole numbers), the POS device learns per-product precision, and four ratcheted guards make regression un-mergeable.

**Architecture:** Server emits `suggested_qty` unit-formatted + `quantity_decimals` per row (wire `requested_qty` stays scale-4); POS maps the already-served `/products` `quantity_decimals` field into sqlite (v62) and renders via a new atom; web aligns to the existing `getQuantityDecimals` pattern; guards = 2 PHPStan rules + 2 ESLint rules + 1 AST scanner, all baseline-ratcheted.

**Tech Stack:** Laravel 12/PHP 8.2 (bcmath, PHPStan L8), React 19/TS strict, Tauri sqlite, ESLint flat config, mjs AST scanners.

**Spec:** `docs/superpowers/specs/2026-07-20-uom-display-precision-design.md` (Rev 2 — READ IT FIRST; §6 records review decisions). Worktree `../erp.uom-precision`, branch `feat/uom-display-precision`.

## Global Constraints

- Backend paths under `apps/api/`; run PHPUnit **by path only** (never full suite).
- No float on money/qty anywhere; strings end-to-end (CLAUDE.md rule 19). No `parseFloat`/`Number()` on quantities.
- Wire contract: POS endpoints snake_case. `requested_qty` stays scale-4 on the wire; only `suggested_qty` is emitted unit-formatted.
- No server DB migrations in this feature. No new queues. `SyncController::pull` must NOT be touched.
- Module boundaries: no cross-module model imports; `QuantityScale` (Shared) takes primitives, never a Uom entity.
- POS new tsx uses POS semantic tokens (`border-border-subtle`, `bg-surface-raised`, `text-ink`, `focus:ring-action`); web tsx follows rule 18 designTokens.
- All frontend user-facing text via `t()` (no new keys expected; if one appears, add to existing `replenishment` namespaces en+fr).
- Commit at every green (WIP commits fine, squash later). PHPStan level 8 zero NEW errors; `pnpm typecheck` clean.
- Each wave ends at a HARD STOP for a controller-run reviewer gate. Do not proceed past a gate.

---

## WAVE 1 — Backend (Tasks 1–4) → Gate 1 (inventory-costing reviewer)

### Task 1: `QuantityScale::formatForUnit`

**Files:**
- Modify: `apps/api/app/Shared/Domain/QuantityScale.php` (add method after `round`)
- Test: `apps/api/tests/Unit/Shared/QuantityScaleFormatForUnitTest.php` (create)

**Interfaces — Produces:** `QuantityScale::formatForUnit(string $value, ?int $decimalPlaces, ?string $roundingMethod = null): string` — primitives only (module-boundary-safe; callers pass `$unit?->decimal_places`, `$unit?->rounding_method?->value`).

- [ ] **Step 1: failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Shared\Domain\QuantityScale;
use PHPUnit\Framework\TestCase;

final class QuantityScaleFormatForUnitTest extends TestCase
{
    public function test_zero_decimals_renders_whole_number(): void
    {
        self::assertSame('1', QuantityScale::formatForUnit('1.0000', 0));
        self::assertSame('7', QuantityScale::formatForUnit('7.0000', 0, 'half_up'));
    }

    public function test_three_decimals_pads(): void
    {
        self::assertSame('1.000', QuantityScale::formatForUnit('1.0000', 3));
    }

    public function test_rounding_methods(): void
    {
        self::assertSame('2', QuantityScale::formatForUnit('1.5000', 0, 'half_up'));
        self::assertSame('1', QuantityScale::formatForUnit('1.9000', 0, 'floor'));
        self::assertSame('2', QuantityScale::formatForUnit('1.1000', 0, 'ceil'));
    }

    public function test_null_args_fall_back_to_scale_4_half_up(): void
    {
        self::assertSame('1.0000', QuantityScale::formatForUnit('1.0000', null));
        self::assertSame('2.5001', QuantityScale::formatForUnit('2.50005', null, null));
    }
}
```

- [ ] **Step 2:** `cd apps/api && ./vendor/bin/phpunit tests/Unit/Shared/QuantityScaleFormatForUnitTest.php` → FAIL (method undefined).
- [ ] **Step 3: implement**

```php
    /**
     * Format a canonical scale-4 quantity for human display at a unit's precision.
     * Primitives only (Shared must not depend on module entities): callers pass
     * $unit?->decimal_places and $unit?->rounding_method?->value.
     *
     * @param numeric-string $value
     * @return numeric-string exactly $decimalPlaces digits (0 => integer string)
     */
    public static function formatForUnit(string $value, ?int $decimalPlaces, ?string $roundingMethod = null): string
    {
        return self::round($value, $decimalPlaces ?? self::SCALE, $roundingMethod ?? self::HALF_UP);
    }
```

(`round()` already returns exactly-N-digit strings — verified against its docblock.)

- [ ] **Step 4:** re-run test → PASS. `./vendor/bin/phpstan analyse app/Shared/Domain/QuantityScale.php` → 0 new errors.
- [ ] **Step 5:** `git commit -m "feat(shared): QuantityScale::formatForUnit — per-unit display precision"`

### Task 2: Unit-aware `ReplenishmentSuggestionService`

**Files:**
- Modify: `apps/api/app/Modules/Inventory/Application/Services/ReplenishmentSuggestionService.php`
- Test: extend `apps/api/tests/Unit/Inventory/ReplenishmentSuggestionServiceTest.php` (or its actual existing test path — locate with `grep -rl ReplenishmentSuggestionService apps/api/tests`)

**Interfaces — Produces:** `suggestionsForLocation()` return values become unit-formatted strings (`'7'` for pieces, `'1.000'` for kg). Consumers (Task 3) rely on this.

- [ ] **Step 1: failing tests** — seed a Piece product (`unit_id` → unit with `decimal_places=0`, `rounding_method='half_up'`) and a kg product (`decimal_places=3`), each with a StockLevel at one location:
  - piece, max=12, available=5 → expect `'7'` (not `'7.0000'`)
  - kg, max=2, available=0.5 → expect `'1.500'`
  - piece, no min/max → expect `'1'` (floor, unit-formatted)
  - product with `unit_id = null`, no min/max → expect `'1.0000'` (fallback unchanged)

```php
    public function test_piece_product_suggestion_is_whole_number(): void
    {
        // arrange: $unit = Unit::create([... 'decimal_places' => 0, 'rounding_method' => 'half_up' ...]);
        // $product = Product::create([... 'unit_id' => $unit->id ...]);
        // StockLevel::create(['product_id' => $product->id, 'location_id' => $loc->id,
        //   'quantity' => '5.0000', 'reserved' => '0', 'max_quantity' => '12.0000', ...]);
        $result = $this->service->suggestionsForLocation($tenantId, $companyId, $loc->id, [[$product->id, '']]);
        self::assertSame('7', $result[$product->id.'|']);
    }
```

(Mirror the existing test file's seeding helpers and grain-key helper exactly — read them before writing; the grain key format must match `ReplenishmentSuggestionService::grainKey`.)

- [ ] **Step 2:** run by path → FAIL (`'7.0000'` returned).
- [ ] **Step 3: implement** — inside the service, after the existing computation loop, add ONE batched lookup and a formatting pass. Use the query builder (NOT a cross-module model import):

```php
use Illuminate\Support\Facades\DB;

// after computing $suggestions keyed by grainKey(productId, variantId):
$productIds = array_unique(array_map(static fn (array $g): string => $g[0], $grains));
$unitMeta = DB::table('products')
    ->leftJoin('units', 'units.id', '=', 'products.unit_id')
    ->whereIn('products.id', $productIds)
    ->get(['products.id', 'units.decimal_places', 'units.rounding_method'])
    ->keyBy('id');

foreach ($suggestions as $key => $value) {
    [$productId] = explode('|', $key, 2);
    $meta = $unitMeta->get($productId);
    $suggestions[$key] = QuantityScale::formatForUnit(
        $value,
        $meta?->decimal_places !== null ? (int) $meta->decimal_places : null,
        $meta?->rounding_method,
    );
}
```

Keep `FLOOR_QUANTITY = '1.0000'` and all internal scale-4 math unchanged — formatting happens once at the end (round-once-at-boundary rule). NOTE: if the service's grain-key separator differs from `'|'`, use its actual constant/helper — read `grainKey()` first.

- [ ] **Step 4:** run tests by path → PASS. Add a query-count assertion (unit lookup must be exactly 1 query regardless of grain count):

```php
    DB::enableQueryLog();
    $this->service->suggestionsForLocation(...many grains...);
    $unitQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'units'))->count();
    self::assertSame(1, $unitQueries);
```

- [ ] **Step 5:** `./vendor/bin/phpstan analyse app/Modules/Inventory` → 0 new. Commit `"feat(inventory): unit-aware suggested quantity formatting"`.

### Task 3: `quantity_decimals` on the replenishment feed + pinned-test updates

**Files:**
- Modify: `apps/api/app/Modules/Replenishment/Presentation/Resources/ReplenishmentRequestResource.php`
- Modify: `apps/api/app/Modules/Replenishment/Application/Services/ReplenishmentQueryService.php` (eager-load) and `apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php` + the web `ReplenishmentRequestController` index (locate: `grep -rn "ReplenishmentRequestResource" apps/api/app` — ensure EVERY collection site eager-loads `product.unitOfMeasure`)
- Test: `apps/api/tests/Feature/POS/PosReplenishmentControllerTest.php`, `apps/api/tests/Feature/Replenishment/ReplenishmentActionsTest.php`

**Interfaces — Produces:** every feed row gains `"quantity_decimals": <int>` (0–4, default 4); `suggested_qty` arrives unit-formatted. `requested_qty` unchanged.

- [ ] **Step 1: failing test** — extend the existing feed test: seed piece unit on the product, assert `quantity_decimals === 0` and `suggested_qty === '7'` in the JSON. Update the existing pinned assertions (:181-184, :201-202, :225-226 suggested_qty strings; ReplenishmentActionsTest :97,:145) to seed explicit units and expect unit-formatted values; assertions on `requested_qty` (:85,:93,:106) must stay byte-identical (wire unchanged — do NOT touch them).
- [ ] **Step 2:** run both files by path → FAIL.
- [ ] **Step 3: implement** — resource line (after `suggested_qty`):

```php
'quantity_decimals' => $this->product?->unitOfMeasure?->decimal_places ?? 4,
```

Add `->with(['product.unitOfMeasure'])` (or extend the existing eager-load array) at every site found in the grep; verify no site serializes without the load (avoid N+1 — the resource uses nullsafe access so a missing load silently degrades to 4: add one test asserting a kg product row emits 3, which fails if the load is missing).
- [ ] **Step 4:** run by path → PASS. PHPStan on the module → 0 new.
- [ ] **Step 5:** Commit `"feat(replenishment): emit quantity_decimals + unit-formatted suggested_qty"`.

### Task 4: `quantity_decimals` on PO receipt/invoice lines (SupplierInvoice backend half)

**Files:**
- Locate: `grep -rn "usePurchaseOrderReceiptLinesForSupplierInvoice" apps/web/src` → note the endpoint path it calls → `grep -rn "<that path>" apps/api/app/Modules` → the controller + resource serializing receipt/invoice lines.
- Modify: that resource (add field, eager-load `product.unitOfMeasure` at its query site)
- Test: the controller's existing feature test file (extend)

**Interfaces — Produces:** receipt/invoice line payloads gain `quantity_decimals` (int, default 4) for Task 10.

- [ ] **Step 1:** failing feature test — seed piece-unit product on a receipt line, assert `quantity_decimals === 0` in the lines JSON.
- [ ] **Step 2:** FAIL → **Step 3:** same one-line resource pattern as Task 3 + eager-load. → **Step 4:** PASS by path, PHPStan 0 new. → **Step 5:** commit `"feat(purchases): quantity_decimals on receipt/invoice line payloads"`.

**⛔ GATE 1 — STOP.** Report: files changed, test output (paths run), phpstan summary. Controller runs inventory-costing reviewer before Wave 2.

---

## WAVE 2 — POS client (Tasks 5–7) → Gate 2 (fiscal-pos reviewer)

### Task 5: sqlite v62 + product mapping (four coordinated upsert edits)

**Files:**
- Modify: `apps/pos/src/lib/db/migrations.ts` (append v62 after v61 block at :1899-1911, same shape)
- Modify: `apps/pos/src/types/product.ts` (POSProduct), `apps/pos/src/lib/db/repositories/productRepository.ts` (`PARAMS_PER_ROW` :138, value-clause :149-151, INSERT list :195, ON CONFLICT :197-216, `ProductRow`, `rowToProduct`)
- Test: `apps/pos/src/lib/db/__tests__/migrations.v62.test.ts` (create, mirror `migrations.v61.test.ts`), extend the existing productRepository round-trip test

- [ ] **Step 1: failing tests**
  - v62 idempotence: fresh DB migrate-to-62 ≡ upgrade-from-61 (copy the v61 test's structure; assert `quantity_decimals` column exists and re-running the migration is a no-op).
  - Round-trip: `upsertProducts([{...product, quantity_decimals: 0}])` then read → `rowToProduct(row).quantity_decimals === 0`; also a product with the field ABSENT (older server) round-trips as `null`. This test catches any param misalignment across the 19-param batch (assert EVERY field of the round-tripped product, not just the new one).

```ts
// migration block to implement against:
{
  version: 62,
  name: 'add_quantity_decimals_to_products',
  run: async (db) => {
    try {
      await db.execute('ALTER TABLE products ADD COLUMN quantity_decimals INTEGER');
    } catch (error) {
      if (!isDuplicateColumnError(error)) throw error;
    }
  },
},
```

- [ ] **Step 2:** `cd apps/pos && pnpm vitest run src/lib/db/__tests__/migrations.v62.test.ts` → FAIL.
- [ ] **Step 3: implement.** Type: `quantity_decimals?: number | null;` on `POSProduct` and `ProductRow`. Repository — ALL FOUR in lockstep: `PARAMS_PER_ROW = 19`; add `quantity_decimals` to the column list, `?` to the per-row value template, `product.quantity_decimals ?? null` to the params pusher, and `quantity_decimals = excluded.quantity_decimals` to the `ON CONFLICT ... DO UPDATE SET` block. `rowToProduct`: `quantity_decimals: row.quantity_decimals ?? null`.
- [ ] **Step 4:** vitest by path → PASS. `pnpm typecheck` clean.
- [ ] **Step 5:** commit `"feat(pos): sqlite v62 quantity_decimals + product sync mapping"`.

### Task 6: POS `formatQuantity` + `QuantityInput` atom

**Files:**
- Create: `apps/pos/src/lib/quantity.ts`, `apps/pos/src/components/atoms/QuantityInput.tsx`
- Test: `apps/pos/src/lib/__tests__/quantity.test.ts`, `apps/pos/src/components/atoms/__tests__/QuantityInput.test.tsx`

**Interfaces — Produces:** `formatQuantity(value: string, decimalPlaces: number | null | undefined): string` (pad/truncate via existing `bcadd`; precondition: value already server-rounded); `<QuantityInput value onChange decimalPlaces id? invalid? ariaLabel?>` emitting raw strings.

- [ ] **Step 1: failing tests** — `formatQuantity('1.0000', 0) === '1'`; `('1.0000', 3) === '1.000'`; `('7', 0) === '7'`; `('2.5', null) === '2.5000'`; atom: `decimalPlaces=0` → `pattern="^\d+$"`, `inputMode="numeric"`, `step="1"`; `decimalPlaces=3` → `pattern` allows `1.250`, `step="0.001"`; onChange passes raw string.
- [ ] **Step 2:** vitest by path → FAIL.
- [ ] **Step 3: implement**

```ts
// apps/pos/src/lib/quantity.ts
import { bcadd } from '@/lib/decimal';

export function clampQuantityDecimals(decimalPlaces: number | null | undefined): number {
  if (typeof decimalPlaces !== 'number' || !Number.isInteger(decimalPlaces)) return 4;
  return Math.min(Math.max(decimalPlaces, 0), 4);
}

/** Pad/truncate a server-rounded quantity string to the unit precision. */
export function formatQuantity(value: string, decimalPlaces: number | null | undefined): string {
  return bcadd(value, '0', clampQuantityDecimals(decimalPlaces));
}
```

```tsx
// apps/pos/src/components/atoms/QuantityInput.tsx  (POS semantic tokens — rule 18)
import { clampQuantityDecimals } from '@/lib/quantity';

interface QuantityInputProps {
  value: string;
  onChange: (value: string) => void;
  decimalPlaces: number | null | undefined;
  id?: string;
  invalid?: boolean;
  ariaLabel?: string;
}

export function QuantityInput({ value, onChange, decimalPlaces, id, invalid, ariaLabel }: QuantityInputProps) {
  const dp = clampQuantityDecimals(decimalPlaces);
  const pattern = dp === 0 ? '^\\d+$' : `^\\d+(\\.\\d{1,${dp}})?$`;
  const step = dp === 0 ? '1' : `0.${'0'.repeat(dp - 1)}1`;
  return (
    <input
      id={id}
      type="text"
      inputMode={dp === 0 ? 'numeric' : 'decimal'}
      pattern={pattern}
      step={step}
      value={value}
      aria-invalid={invalid || undefined}
      aria-label={ariaLabel}
      onChange={(e) => onChange(e.target.value)}
      className="mt-1 w-full rounded-ctl border border-border-subtle bg-surface-raised px-3 py-2 text-ink focus:outline-none focus:ring-2 focus:ring-action"
    />
  );
}
```

(Match the exact className set currently on RequestRefillSheet's input :164 — copy it verbatim from the file, the block above is the reviewed baseline.)
- [ ] **Step 4:** vitest by path → PASS; typecheck clean. → **Step 5:** commit `"feat(pos): QuantityInput atom + formatQuantity helper"`.

### Task 7: RequestRefillSheet precision-aware

**Files:**
- Modify: `apps/pos/src/components/organisms/RequestRefillSheet/RequestRefillSheet.tsx`
- Test: `apps/pos/src/components/organisms/RequestRefillSheet/__tests__/RequestRefillSheet.test.tsx` (fixtures :130,:143,:164,:206 revisited)

**Interfaces — Consumes:** Task 5's `product.quantity_decimals`, Task 6's atom+helper.

- [ ] **Step 1: failing tests** — with `product.quantity_decimals: 0` and cached `suggested_qty: '6.0000'`: input renders `'6'`; typing `2.5` marks invalid, typing `3` submits `requested_qty: '3'`; with `quantity_decimals: null` behavior identical to today (scale-4 pattern, prefill `'6.0000'` unchanged).
- [ ] **Step 2:** FAIL.
- [ ] **Step 3: implement** — `const dp = clampQuantityDecimals(product.quantity_decimals);` prefill (line 71): `(cached?.suggested_qty !== undefined && cached?.suggested_qty !== null ? formatQuantity(cached.suggested_qty, dp) : '')`; replace the raw `<input>` (:164-178) with `<QuantityInput decimalPlaces={product.quantity_decimals} ...>`; derive the submit-validation regex from dp (replace the module-level `QUANTITY_PATTERN` usage with a `patternForDecimals(dp)` local — keep `bccomp(normalized, '0') > 0` as-is).
- [ ] **Step 4:** vitest by path → PASS; typecheck; `pnpm lint` (POS) no new. → **Step 5:** commit `"feat(pos): refill sheet renders/validates at product unit precision"`.

**⛔ GATE 2 — STOP.** Controller runs fiscal-pos reviewer (compat matrix: old device fixtures, absent-field tolerance, param alignment).

---

## WAVE 3 — Web (Tasks 8–10) → Gate 3 (frontend-conventions reviewer)

### Task 8: canonicalize `formatQuantity`

**Files:**
- Modify: `apps/web/src/lib/format.ts:133` (rename `formatQuantity` → `formatQuantityTrimmed`, add `@deprecated for product quantities — use lib/decimal formatQuantity` JSDoc) + mechanically update its importers (`grep -rn "formatQuantity" apps/web/src --include="*.ts*" | grep "from.*lib/format"`).
- Test: existing tests of both helpers keep passing (run the two lib test files by path).

- [ ] Steps: grep importers → rename symbol + all import sites (pure mechanical, no behavior change) → run `pnpm vitest run src/lib` + `pnpm typecheck` → commit `"refactor(web): disambiguate formatQuantity (pad=canonical decimal.ts, trim=formatQuantityTrimmed)"`.

### Task 9: replenishment surfaces unit-aware

**Files:**
- Modify: `apps/web/src/features/replenishment/types/index.ts` (add `quantity_decimals: number` to `ReplenishmentLine`), `components/AddToPoDialog.tsx:139-140`, `components/CreateTransferDialog.tsx:140-141`, `pages/ReplenishmentQueuePage.tsx:145,192`
- Explicitly EXCLUDED: `pages/ReplenishmentCapturePage.tsx:77` (pre-product field — spec §3.3; add code comment `{/* pre-product standing field — intentionally scale-4; see spec 2026-07-20 §3.3 */}`)
- Test: the features' existing vitest files (extend)

- [ ] **Step 1: failing tests** — queue row with `requested_qty: '2.0000', quantity_decimals: 0` renders `2`; AddToPoDialog with decimals 0 gets `decimalPlaces === 0` and `min === '1'`; with decimals 3 `min === '0.001'`.
- [ ] **Step 2:** FAIL → **Step 3: implement**

```tsx
// dialogs (both):
const dp = getQuantityDecimals(line);            // reads line.quantity_decimals, clamps [0,4]
const minForDp = dp === 0 ? '1' : `0.${'0'.repeat(dp - 1)}1`;
<QuantityInput decimalPlaces={dp} min={minForDp} ... />

// queue page :145 and matrix cell :192:
import { formatQuantity } from '@/lib/decimal';
{line.requested_qty !== null ? formatQuantity(line.requested_qty, getQuantityDecimals(line)) : t('matrix.requested_no_qty')}
```

- [ ] **Step 4:** vitest by path, typecheck, `pnpm lint` → PASS/clean → **Step 5:** commit `"feat(web): replenishment surfaces render at unit precision"`.

### Task 10: SupplierInvoiceCreatePage both quantity sites

**Files:**
- Modify: `apps/web/src/features/purchases/supplier-invoices/types.ts:213-234` (+`quantity_decimals?: number` on receipt+invoice line types and `InvoiceLineFormState`), `SupplierInvoiceCreatePage.tsx:538,626` + `prefilledLines` plumbing (:240-283)
- Test: the page's existing vitest file (extend with a 0-dp line asserting `decimalPlaces 0`)

- [ ] Steps: failing test → implement (`:538` → `getQuantityDecimals(line.product)`; `:626` → `getQuantityDecimals(line)` with the field plumbed from Task 4's payload through `prefilledLines`) → vitest by path + typecheck + lint → commit `"feat(web): supplier invoice quantity inputs at unit precision"`.

**⛔ GATE 3 — STOP.** Controller runs frontend-conventions reviewer.

---

## WAVE 4 — Guards, seeders, docs (Tasks 11–15) → Gate 4 (frontend-conventions + inventory-costing)

### Task 11: PHPStan `ForbidFixedScaleQuantityEmission`

**Files:**
- Create: `apps/api/phpstan-rules/ForbidFixedScaleQuantityLiteralRule.php` + `ForbidQuantityScaleConstantInPresentationRule.php` (mirror the existing rule dir/namespace of `ForbidHardcodedBcmathScale` — locate it first, copy its registration style in `phpstan.neon`/`phpstan.neon.dist`)
- Test: fixture-based rule tests exactly as the existing precision rules are tested (locate their tests and mirror)

Rule A (literals): node `PhpParser\Node\Scalar\String_`; report when `preg_match('/^\d+\.0{4}$/', $node->value)` AND the file's namespace matches `/App\\Modules\\.+\\Presentation\\/`. Message: `Fixed scale-4 quantity literal in Presentation layer — use QuantityScale::formatForUnit() or move to Application.` Rule B: node `Expr\StaticCall`; report `QuantityScale::round(...)` whose 2nd arg is `ClassConstFetch` of `QuantityScale::SCALE`, same namespace filter, same message.

- [ ] Steps: failing fixture tests (one violating Presentation file, one clean, one Application-layer file with the literal = not reported) → implement both rules → register → run `./vendor/bin/phpstan analyse app/Modules --level 8`; any pre-existing hits go into the phpstan baseline with a `# uom-display-precision ratchet` comment → commit `"guard(phpstan): forbid fixed-scale quantity emission in Presentation"`.

### Task 12: ESLint guards (web scope + POS copies)

**Files:**
- Create: `apps/web/eslint-rules/no-literal-decimal-places.js` (+ register `'precision/no-literal-decimal-places': 'warn'` in `apps/web/eslint.config.js` next to no-hardcoded-step at :87)
- Create: `apps/pos/eslint-rules/no-hardcoded-step.js` (copy from `apps/web/eslint-rules/no-hardcoded-step.js` verbatim) and `apps/pos/eslint-rules/no-raw-quantity-input.js`; register both in `apps/pos/eslint.config.js` (existing `precisionPlugin` pattern)
- Test: `apps/web/eslint-rules/__tests__/no-literal-decimal-places.test.mjs`, `apps/pos/eslint-rules/__tests__/no-raw-quantity-input.test.mjs` (RuleTester, mirror existing rule tests)

```js
// no-literal-decimal-places.js — core create():
const INCLUDED_DIRS = [
  'features/replenishment', 'features/purchases', 'features/documents',
  'features/inventory', 'features/stock-transfers', 'features/batches',
  'features/products/sections/ProductInventorySection',
];
create(context) {
  const filename = context.getFilename().replaceAll('\\', '/');
  if (!INCLUDED_DIRS.some((d) => filename.includes(d))) return {};
  return {
    JSXOpeningElement(node) {
      if (node.name.type !== 'JSXIdentifier' || node.name.name !== 'QuantityInput') return;
      for (const attr of node.attributes) {
        if (attr.type !== 'JSXAttribute' || attr.name.name !== 'decimalPlaces') continue;
        const v = attr.value;
        if (v?.type === 'JSXExpressionContainer' && v.expression.type === 'Literal' && typeof v.expression.value === 'number') {
          context.report({ node: attr, messageId: 'literalDecimalPlaces' });
        }
      }
    },
  };
}
```

```js
// no-raw-quantity-input.js — core create():
const ALLOWLIST = [
  'components/atoms/MoneyInput.tsx',
  'components/atoms/QuantityInput.tsx',
  'pages/ShiftClosurePage.tsx',          // cash counting (money)
  'components/customers/CustomerAttachPanel.tsx', // non-quantity decimal
];
create(context) {
  const filename = context.getFilename().replaceAll('\\', '/');
  if (ALLOWLIST.some((f) => filename.endsWith(f))) return {};
  return {
    JSXOpeningElement(node) {
      if (node.name.type !== 'JSXIdentifier' || node.name.name !== 'input') return;
      const im = node.attributes.find((a) => a.type === 'JSXAttribute' && a.name.name === 'inputMode');
      if (im?.value?.type === 'Literal' && im.value.value === 'decimal') {
        context.report({ node, messageId: 'rawQuantityInput' }); // use <QuantityInput> or add to allowlist with justification
      }
    },
  };
}
```

- [ ] Steps: RuleTester failing tests (valid: MoneyInput file, excluded-dir literal; invalid: RequestRefillSheet-shaped fixture, replenishment-dir literal) → implement → `pnpm lint` both apps: expect ZERO new errors (Wave 2/3 already fixed the true positives; any residue = fix now, not baseline) → commit `"guard(eslint): no-literal-decimal-places (web) + no-hardcoded-step/no-raw-quantity-input (pos)"`.

### Task 13: `audit-quantity-display.mjs` scanner + wiring

**Files:**
- Create: `apps/web/tools/audit-quantity-display.mjs` (scans BOTH `apps/web/src` and `apps/pos/src` — single source of truth; mirror the parse harness of `apps/web/tools/audit-tanstack-keys.mjs`), baseline `apps/web/tools/quantity-display-baseline.json`
- Modify: `apps/web/package.json` (add `audit:quantity` to `lint` chain like `audit:keys` at :10,:13), `scripts/preflight.sh` (mirror :178), `.github/workflows/ci.yml` (mirror the audit:keys step)
- Test: `apps/web/tools/__tests__/audit-quantity-display.test.mjs` (fixture files: violation caught; canonical-import wrap passes; excluded identifier ignored; baseline entry tolerated; NEW violation fails)

Detection contract (from spec §3.4.4): flag a JSX expression rendering an identifier/member whose terminal name is in INCLUDE = `['requested_qty','suggested_qty','received_qty','quantity']` (`quantity` only when the object chain is a line/item variable, i.e. member expression — bare `quantity` state vars are excluded), EXCLUDE terminal names matching `/^(total_|available_|reserved_|stock_|min_|max_|component_|required_).*|.*_count$/`, UNLESS the expression is (a) an argument of a call whose callee resolves to an import of `formatQuantity` from `lib/decimal` (web) / `lib/quantity` (pos), or (b) inside a `<QuantityInput>` value attribute. Emit `file:line identifier`; compare against baseline (sorted JSON array of `"file:identifier"` — line-number-free so edits don't churn it); exit 1 on new entries; exit 1 with "stale baseline" if a baseline entry no longer matches (shrink-only ratchet).

- [ ] Steps: write failing fixture tests → implement scanner → run full scan; triage findings: mechanical ones fixed inline (formatQuantity wrap), non-mechanical → baseline + list them in the Gate-4 report for ticketing → wire into lint/preflight/CI → commit `"guard(scanner): audit-quantity-display with ratchet baseline (first run = app-wide audit)"`.

### Task 14: seeder min/max + pinned grain + assertion test

**Files:**
- Modify: `apps/api/database/seeders/DemoPharmacySeeder.php` (seedTunisiaStock values array :675-687 + pinned grain), `apps/api/database/seeders/ParapharmacySeeder.php` (seedStockLevels :1283-1290)
- Test: `apps/api/tests/Feature/Seeders/DemoPharmacyReplenishmentSuggestionTest.php` (create)

```php
// deterministic per (sku, locationCode) — NOT derived from randomized qty:
private function demoMinMaxFor(string $sku, string $locationCode): array
{
    $h = crc32($sku.'|'.$locationCode);
    $min = (string) (2 + ($h % 4));          // 2..5, always >= 1 whole unit
    $max = (string) ((int) $min * 3);        // 6..15
    return [$min, $max];
}
// DemoPharmacySeeder::seedTunisiaStock — add to the updateOrCreate VALUES array (backfills NULLs on re-run):
//   'min_quantity' => $min, 'max_quantity' => $max,
// ParapharmacySeeder::seedStockLevels — add the same two keys to the create() payload (fresh tenant per run; no backfill semantics).

// PINNED GRAIN (fixed literals, after the loop in seedTunisiaStock):
// PB-BAB-0060 @ STORE-SOU: quantity '5.0000', min '6.0000', max '12.0000'  → suggestion '7'
```

- [ ] **Step 1: failing test** — seed (RefreshDatabase + the seeder pathway used by existing seeder tests), resolve `STORE-SOU` location id and PB-BAB-0060 product id, call `ReplenishmentSuggestionService::suggestionsForLocation($tenant, $company, $sousseLocationId, [[$productId, '']])`, assert `'7'` exactly (piece-formatted, non-floor).
- [ ] **Step 2:** FAIL → **Step 3:** implement seeder changes → **Step 4:** PASS by path; also re-run Task 2's tests by path (guard against interference) → **Step 5:** commit `"feat(seeders): shop min/max + pinned deterministic suggestion grain"`.

### Task 15: docs + reviewer checklists

**Files:**
- Modify: `docs/architecture/precision-contract.md` (new "## Emission & display" section), root `CLAUDE.md` (rule 19 bullet), `.claude/agents/inventory-costing-reviewer.md`, `.claude/agents/fiscal-pos-reviewer.md`, `.claude/agents/frontend-conventions-reviewer.md`

- [ ] Precision-contract section (verbatim content to add): storage `decimal(N,4)` vs display per-unit split; `QuantityScale::formatForUnit(value, ?int, ?string)` with the enum `->value` note; canonical helpers table (web display = `lib/decimal.ts formatQuantity` [pads], `lib/format.ts formatQuantityTrimmed` [deprecated for quantities], POS = `lib/quantity.ts formatQuantity` + `QuantityInput` atom); POS data contract (`/products` → `quantity_decimals` → sqlite v62); guard inventory (both PHPStan rules, both ESLint rules, `audit-quantity-display.mjs`, baselines shrink-only).
- [ ] CLAUDE.md rule 19 addition (one bullet): `**Display/emission:** quantities surfaced to humans use the product unit's precision (units.decimal_places) — backend QuantityScale::formatForUnit, web getQuantityDecimals + QuantityInput + lib/decimal formatQuantity, POS QuantityInput + lib/quantity formatQuantity. Guarded by ForbidFixedScaleQuantityEmission (PHPStan), no-literal-decimal-places / no-raw-quantity-input (ESLint), audit-quantity-display.mjs (ratchet).`
- [ ] Reviewer files: add one checklist line each — "Quantity display precision: any human-facing quantity must render at units.decimal_places (see precision-contract.md Emission & display); flag raw scale-4 strings or literal decimalPlaces in product-quantity surfaces."
- [ ] Commit `"docs: display-precision contract section + rule-19 bullet + reviewer checklists"`.

**⛔ GATE 4 — STOP.** Controller runs frontend-conventions (guards/scanner) + inventory-costing (seeders/PHPStan rules) reviewers, then merges --no-ff to LOCAL dev. Never push origin.

---

## Self-review record
- Spec coverage: §3.1→T1-T4, §3.2→T5-T7, §3.3→T8-T10 (CapturePage exclusion in T9), §3.4 guards 1-4→T11-T13, §3.4.5+§3.5→T14, §3.6→T15. R1 wire decision enforced in T3 Step 1 (requested_qty pins untouched).
- Types: `formatForUnit(string, ?int, ?string)` consistent T1→T2; `quantity_decimals?: number | null` consistent T5→T7; `ReplenishmentLine.quantity_decimals: number` (T9) matches resource default-4 (T3 — never null on the wire).
- Known intentional deviations from spec Rev 2: `formatForUnit` takes primitives, not `?Unit` (module-boundary rule — Shared cannot import a Uom entity); flagged for plan review.

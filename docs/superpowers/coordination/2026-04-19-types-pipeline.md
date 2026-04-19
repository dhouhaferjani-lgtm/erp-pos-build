# Types Pipeline Overhaul — Coordination Memo

**Status:** Active
**Date opened:** 2026-04-19
**Pipeline branch:** `feat/types-pipeline-overhaul` (off `dev`)
**Plan:** [`docs/superpowers/plans/2026-04-19-typescript-types-pipeline.md`](../superpowers/plans/2026-04-19-typescript-types-pipeline.md)

## TL;DR for parallel sessions (work-order, HRM, anything else in flight)

A separate Claude session is overhauling the `php artisan typescript:transform` pipeline so generated types in `packages/shared/types/generated.d.ts` finally become consumable from `apps/web`. Until that work lands, follow the **five rules** below for any new DTO you add — your module benefits automatically and needs zero rework.

## What's changing in the pipeline session

| Before | After |
|---|---|
| `packages/shared/types/generated.ts` (module-local, unused, 0 imports in apps/web) | `packages/shared/types/generated.d.ts` (ambient global, `App.Modules.<…>` resolvable everywhere) |
| `Spatie\TypeScriptTransformer\Transformers\DtoTransformer` (loses `DataCollection<T>` element type → 34 `Array<any>`) | `Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer` (preserves element types) |
| Hand-written shadow interfaces in every `features/<module>/types.ts` | Re-exports from generated namespace |
| No drift detection | `scripts/preflight.sh` regenerates and fails CI on diff |

**Explicitly NOT changing in this scope:** the wire format. snake_case stays snake_case. The Identity module's camelCase outlier is **deferred** to a separate planned change with a coordinated multi-client deploy. Don't introduce camelCase DTOs.

## The five rules for new DTOs

For every new DTO you add in your module:

### 1. Tag with `#[TypeScript]`

```php
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class WorkOrderData extends Data { /* … */ }
```

(Already CLAUDE.md rule #7 — restated for emphasis.)

### 2. snake_case all public properties

```php
// good
public string $work_order_number,
public string $opened_at,
public ?string $assigned_technician_id,

// bad — will become a future migration target
public string $workOrderNumber,
```

69 of 73 existing tagged DTOs already do this. Match the convention.

### 3. Use `#[DataCollectionOf(LineData::class)]` for collection properties

```php
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\DataCollection;

public function __construct(
    public string $work_order_number,
    #[DataCollectionOf(WorkOrderLineData::class)]
    public DataCollection|array $lines,
) {}
```

Without the attribute the transformer can't resolve element types and the property emits `Array<any>` (one of the bugs the pipeline session is fixing).

### 4. PHPDoc for typed scalar arrays

```php
/** @var string[] */
public array $oem_numbers,
/** @var int[] */
public array $bay_ids,
```

Same reason as rule 3 — typed arrays without attributes need the PHPDoc to resolve.

### 5. Do NOT hand-write TypeScript shadow interfaces

If your frontend feature needs the type before the pipeline lands, write a TEMPORARY shadow in `apps/web/src/features/<module>/types.ts` with this exact header so it's easy to find and delete:

```ts
// TEMPORARY shadow — replace with re-export from
//   App.Modules.<Module>.Application.DTOs.<Name>Data
// once feat/types-pipeline-overhaul lands.
// Tracked in docs/superpowers/coordination/2026-04-19-types-pipeline.md
```

The pipeline session will grep for `TEMPORARY shadow` after Phase D and either help or list them as follow-ups.

## Things you must NOT touch

These belong exclusively to the pipeline session — your changes WILL conflict:

- `apps/api/config/typescript-transformer.php`
- `packages/shared/types/generated.ts` and `generated.d.ts`
- `packages/shared/types/index.ts`
- `apps/web/src/vite-env.d.ts`
- `scripts/preflight.sh`
- `apps/api/app/Shared/TypeScript/` (this directory will be created by the pipeline session)
- Any file under `apps/web/src/features/inventory/StockLevelsPage.tsx` (Phase D reference migration target)

You do NOT need to run `php artisan typescript:transform` yourself — the pipeline session owns regeneration. Commit your PHP DTO changes; the pipeline session regenerates `generated.d.ts` at merge time.

## Compliance reminders for in-flight modules

- **Work order DTOs** (car body shops, quick service stations) are fiscal-document precursors. Workshop labor + parts → invoice → fiscal hash chain (NF525 / Italian SDI / Tunisia fiscal / UK MTD). Tag every line-item, tax-allocation, and totals DTO from day one — Phase B specifically catches drift on these via the `DataCollection<T>` fix, so tagging up-front is the cheapest path.
- **HRM DTOs** carry employee PII (national ID, salary, address, contract clauses). GDPR scope. Tagging makes "is this field exposed in the frontend?" a compile-time question instead of a code-review question.

## Merge order

1. Your module sessions land into `dev` first as you finish them. Each lands as a normal PR.
2. The pipeline session rebases `feat/types-pipeline-overhaul` onto the latest `dev` between every other-session merge, regenerating `generated.d.ts` to include the new DTOs.
3. Pipeline session lands its own PR last. Phase E (preflight drift guard) is the very last commit so it doesn't activate while other PRs are in flight.

If a merge conflict in `generated.d.ts` happens, the pipeline session resolves it by regenerating from scratch — you don't need to.

## Open questions / requests for the pipeline session

(Append below as needed. The pipeline session will read this file at the start of each work block.)

- _none yet_

---

**To the pipeline session:** if you're starting work, also leave a note here so the parallel sessions know you're active.

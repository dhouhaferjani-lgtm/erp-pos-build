# Task C3 Report — Web "Reverse / correct" action for a posted write-off

## Status
COMPLETE — all test-gates GREEN, typecheck clean, ESLint clean.

---

## Route confirmation
```
POST /api/v1/stock-movements/{movementId}/reverse-write-off
```
Confirmed from `apps/api/app/Modules/BatchExpiry/Presentation/routes.php` line:
```php
Route::post('/stock-movements/{movementId}/reverse-write-off', [BatchController::class, 'reverseWriteOff'])
    ->middleware('can:batches.write-off');
```
The `{movementId}` path segment is a UUID. Permission: `batches.write-off`.

---

## Component chosen
`apps/web/src/features/inventory/StockMovementsPage.tsx`

This is the canonical page that lists ALL stock movements (receipts, issues, adjustments, transfers, write-offs). Write-off movements have `movement_type === 'issue'` and `reason === 'write_off'`. The Reverse action is added as an inline button in a new "actions" DataTable column — visible only on write-off rows for users with `batches.write-off` permission.

---

## How "already reversed" is detected
The `StockMovement` model has a `HasOne reversalOf()` relationship: another movement pointing at this row via `reverses_movement_id`. The existing `StockMovementController::formatMovement()` did **not** expose this field.

**Gap fixed in this PR:** `formatMovement()` now returns two additional fields:
- `reason: string | null` — the `MovementReason` enum value (e.g. `'write_off'`)
- `reverses_movement_id: string | null` — UUID of the original movement this row reverses
- `is_reversed: bool` — `true` when `reversalOf` is loaded and non-null

`reversalOf` is now eager-loaded in the `index()` query to avoid N+1. For single-item endpoints the field defaults to `false` via the `relationLoaded()` guard (additive, non-breaking).

---

## Files changed

### Backend
| File | Change |
|---|---|
| `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php` | Add `reversalOf` to eager load in `index()`; add `reason`, `reverses_movement_id`, `is_reversed` to `formatMovement()` |

### Frontend
| File | Change |
|---|---|
| `apps/web/src/features/batches/api/batches.ts` | Add `ReverseWriteOffResult` interface + `reverseWriteOff(movementId)` function |
| `apps/web/src/hooks/usePermissions.ts` | Add `'batches.write-off': ['admin', 'manager']` to PERMISSIONS map |
| `apps/web/src/locales/en/inventory.json` | Add `movements.filters.writeOffs`, `movements.typeLabels.write_off`, `movements.actions.*` (7 keys) |
| `apps/web/src/locales/fr/inventory.json` | Same additions in French |
| `apps/web/src/features/inventory/_invalidation.ts` | Add `stockMovementsInvalidationPredicate` |
| `apps/web/src/features/inventory/StockMovementsPage.tsx` | Full Reverse action: `StockMovement` interface extended, `write_off` filter tab, `reverseWriteOffMutation`, actions column, `ConfirmDialog`, write-off type label in `movementTypeConfig` |
| `apps/web/src/features/inventory/StockMovementsPage.test.tsx` | Regression fix: mock `useMutation`, `useQueryClient`, `usePermissions`, `batches/api/batches` (existing tests were unaffected by the feature but newly need these mocks) |
| `apps/web/src/features/inventory/StockMovementsPage.reverseWriteOff.test.tsx` | **New** — 9 tests covering all test-gates |

---

## Test run (vitest)

```
Test Files  2 passed (2)
Tests  13 passed (13)
```

### New tests (9):
1. Reverse button visible for unreversed write-off with permission ✓
2. Reverse button absent for already-reversed write-off ✓
3. Reverse button absent for non-write-off issue movement ✓
4. Reverse button absent when user lacks `batches.write-off` permission ✓
5. Opens ConfirmDialog when Reverse button is clicked ✓
6. Calls `mutate` with correct movement id on confirm ✓
7. Shows error toast on 409 (already reversed) ✓
8. Shows generic error toast for non-409 errors ✓
9. Uses only i18n keys for user-facing strings ✓

---

## TypeScript typecheck

```
$ tsc --noEmit
(no output — clean)
```

---

## ESLint

```
$ pnpm exec eslint <changed files>
(no output — clean)
```

---

## Gaps / ⚠️ notes

1. **`reason` and `is_reversed` not previously exposed** — This is the gap identified in the brief. Fixed in this PR by updating `StockMovementController::formatMovement()`. No new endpoint introduced; existing `/api/v1/stock-movements` GET response is additive (new fields, no removals).

2. **`usePermissions.ts` uses a hardcoded role map** — The existing TODO note applies: `batches.write-off` is added to the map with roles `['admin', 'manager']`, consistent with the backend `can:batches.write-off` gate and all other batch permissions in the file. This is the correct workaround until the auth payload exposes live permissions.

3. **Write-off filter tab** — A new "Write-Offs" filter tab was added to `StockMovementsPage`. It filters client-side on `reason === 'write_off'` (since the backend has no write-off-specific `movement_type`). The backend `issue` filter still works for non-write-off issues.

4. **`is_reversed` in single-item endpoints** — `receive`, `issue`, `adjust` endpoints do not eager-load `reversalOf`, so `is_reversed` will always be `false` there. This is correct: those are creation endpoints where reversals have not yet occurred. The list (`index`) endpoint does eager-load it.

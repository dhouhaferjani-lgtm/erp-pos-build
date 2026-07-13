# GATE 2 — Location Placement Phase 2

Reviewed `plc-gate-1` (`c66764dec`) → HEAD (`a61951409`, `plc-gate-2-rc1`): 11 files, +336/-7. Wave 2 = product-page placement field (spec §5.4), frontend-only.

## Hunted risks

- **tenantScopedKey** wraps all read keys (`ProductPlacementFields.tsx:44,49,122`); form matches Wave-1 canonical `PlacementPage.tsx:70`. `tenantScopedKey.ts:29-38` confirms tenant/company are **suffixes**.
- **Invalidation filters** are bare prefixes both here (`:56-58`) and in the `NodeProductsPanel` fix (`:48-52`) → correct direction; bare 3-el prefix matches 6-el scoped key.
- **Hardcoded colors:** none — tokens only (`designTokens.ts` fields all resolve); design-audit 0-new plausible.
- **Stale `zones.*`:** none introduced; two stale message *values* actually **fixed** (created/updated).
- **en/fr/ar parity:** full for `placement.productField.*` (9 keys) + reused `location.empty`/`picker.label`.
- **parseFloat/Number, subtree-query drift, A1-vs-A10:** N/A (no quantity or path-prefix logic this wave).
- **Permission gates:** reads always shown (`inventory.view` server-side), writes behind `canAdjustInventory = hasPermission('inventory.adjust')` (`ProductForm.tsx:308,941`), module-gated `hasModule('Inventory')`.
- **Untransformed types:** no DTO touched; `ProductPlacementDto` already has every field used (`generated.d.ts:902-912`); `apiPut` exists.
- **No escalation:** no counting-seed / CSV bulk-write surface.
- **TDD/§10:** set + clear + read-only-permission tests present.

## Findings

1. **LOW — cache detail:** product-page mutation does not invalidate node-products detail lists.
2. **LOW — visibility:** section visibility is keyed off module and permission context.
3. **LOW — deleted placement:** a placement to a deleted node renders as unassigned.
4. **LOW — pending state:** the Change button remains enabled while a save is pending.
5. **LOW — toggle label:** the Change button keeps a static accessible label when the picker is open.

## Executor verification

- Scoped Vitest: 61/61 passed.
- TypeScript: passed.
- Design-system audit: 752 acknowledged / 0 new / 0 stale.
- TanStack key audit: 0 violations.
- React Doctor against `plc-gate-1`: no issues found.
- Exact en/fr/ar placement key parity: 79 keys per locale.

VERDICT: APPROVE

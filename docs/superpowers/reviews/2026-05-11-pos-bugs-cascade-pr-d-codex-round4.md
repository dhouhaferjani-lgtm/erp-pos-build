# PR D — Codex Round 4 Review

**Subject:** feat(pos): real-time catalog refresh via WebSocket (Bug 1)
**PR:** https://github.com/otospexsolutions/erp/pull/122
**Base:** `dev`
**Branch:** `feat/pos-catalog-websocket-sync` (head `29c93699` — Codex r3 P2 closure)
**Date:** 2026-05-11

## Findings

### [P2] Broadcast modifier-group assignments

`apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php:40-41`

When a modifier group is attached to or detached from a composite item via `ModifierGroupController::assignToItem()` / `removeFromItem()`, Laravel only writes the `composite_item_modifier_groups` pivot table with `syncWithoutDetaching()` / `detach()` and does not save either `CompositeItem` or `ModifierGroup`. These new model observers therefore never emit `catalog.changed`, even though `/active-menu` changes immediately for that item, so POS clients stay stale until the 60 s polling fallback.

## Verdict

**REQUEST-CHANGES** — single P2 must close before merge.

## Resolution applied (round 5 prep)

Added explicit `CatalogModelObserver::broadcastFor(...)` calls inside `ModifierGroupController::assignToItem` and `::removeFromItem`, after the pivot operations:

- `assignToItem` → `'CompositeItemModifierGroup.assigned'`
- `removeFromItem` → `'CompositeItemModifierGroup.removed'`

Two new HTTP-level Feature tests in `CatalogChannelEventBroadcastTest` exercise the full controller path (Sanctum + `composite-items.update` permission + UserCompanyMembership) and assert the broadcast lands despite the pivot-only writes.

## Raw codex output

> The realtime refresh path still misses pivot-only modifier group assignment changes that directly affect the active-menu payload consumed by POS.
>
> Review comment:
>
> - [P2] Broadcast modifier-group assignments — apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php:40-41
>   When a modifier group is attached to or detached from a composite item via `ModifierGroupController::assignToItem()` / `removeFromItem()`, Laravel only writes the `composite_item_modifier_groups` pivot table with `syncWithoutDetaching()` / `detach()` and does not save either `CompositeItem` or `ModifierGroup`. These new model observers therefore never emit `catalog.changed`, even though `/active-menu` changes immediately for that item, so POS clients stay stale until the 60s polling fallback.

# PR D — Codex Round 1 Review

**Subject:** feat(pos): real-time catalog refresh via WebSocket (Bug 1)
**PR:** https://github.com/otospexsolutions/erp/pull/122
**Base:** `dev`
**Branch:** `feat/pos-catalog-websocket-sync` (head `52d20044`)
**Date:** 2026-05-11

## Findings

### [P2] Handle bulk menu-item removals

`apps/api/app/Modules/Menu/Providers/MenuServiceProvider.php:57`

When menu items are removed through the existing `removeItem()` endpoint, or `syncItems()` is called with an empty item list, the controller uses `MenuCategoryItem::where(...)->delete()`, which is a mass delete and does not fire Eloquent `deleted` model events. This listener therefore never runs for those removals, so POS clients will not receive `catalog.changed` and will keep deleted menu items until the polling fallback. Add an explicit broadcast in those deletion paths or avoid mass deletes when this realtime contract is required.

## Verdict

**REQUEST-CHANGES** — single P2 must close before merge.

## Resolution applied (round 2 prep)

Added a private `broadcastCatalogChange(MenuCategory $category, string $reason)` helper to `MenuCategoryController` that resolves the parent Menu's tenant + company and calls `CatalogModelObserver::broadcastFor(...)`. Invoked from both:

- `syncItems` — after the bulk `where()->delete()` + insert loop, fires `'MenuCategoryItem.sync'`.
- `removeItem` — after the single-row `where()->delete()`, fires `'MenuCategoryItem.removed'`.

Two new HTTP-level Feature tests in `CatalogChannelEventBroadcastTest` boot the full controller path (Sanctum + spatie permission + UserCompanyMembership + tenant team) and `Event::fake([CatalogChannelEvent::class])` to prove the broadcast lands despite the bypassed model events.

## Raw codex output

> The patch misses a common deletion path for menu category items because Laravel mass deletes bypass the newly registered model-event listener. This breaks the intended real-time catalog refresh for removals.
>
> Review comment:
>
> - [P2] Handle bulk menu-item removals — apps/api/app/Modules/Menu/Providers/MenuServiceProvider.php:57-57
>   When menu items are removed through the existing `removeItem()` endpoint, or `syncItems()` is called with an empty item list, the controller uses `MenuCategoryItem::where(...)->delete()`, which is a mass delete and does not fire Eloquent `deleted` model events. This listener therefore never runs for those removals, so POS clients will not receive `catalog.changed` and will keep deleted menu items until the polling fallback. Add an explicit broadcast in those deletion paths or avoid mass deletes when this realtime contract is required.

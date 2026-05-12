# PR D — Codex Round 2 Review

**Subject:** feat(pos): real-time catalog refresh via WebSocket (Bug 1)
**PR:** https://github.com/otospexsolutions/erp/pull/122
**Base:** `dev`
**Branch:** `feat/pos-catalog-websocket-sync` (head `56c421be` — Codex r1 P2 closure)
**Date:** 2026-05-11

## Findings

### [P2] Observe composite item catalog changes

`apps/api/app/Modules/Product/ProductServiceProvider.php:35`

For Menu tenants that add `MenuCategoryItem` rows with `sellable_type = 'composite_item'`, `/active-menu` serializes the `CompositeItem`'s name/code/base price/tax/image into the POS catalog. Updating or deleting a composite item through `CompositeItemController` therefore changes what the cashier should see, but this realtime hook only observes `Product` plus menu/menu-category models, so no `catalog.changed` event is emitted and POS clients remain stale until the 60 s polling fallback. Register the same observer for `CompositeItem` or explicitly broadcast from those endpoints.

## Verdict

**REQUEST-CHANGES** — single P2 must close before merge.

## Resolution applied (round 3 prep)

Registered `CompositeItem::observe(CatalogModelObserver::class)` in `CatalogServiceProvider::boot()`. `CompositeItem` carries `tenant_id` / `company_id` directly, so the observer's default attribute-lookup path is sufficient.

Added a regression test (`test_composite_item_save_dispatches_catalog_channel_event`) asserting that `CompositeItem::create()` fires a `CatalogChannelEvent` with the parent tenant + company and a reason prefix of `'CompositeItem.'`.

## Raw codex output

> The realtime catalog refresh misses composite item changes even though those items are part of the active menu payload consumed by POS. This leaves a real catalog mutation path without the new websocket notification.
>
> Review comment:
>
> - [P2] Observe composite item catalog changes — apps/api/app/Modules/Product/ProductServiceProvider.php:35-35
>   For Menu tenants that add `MenuCategoryItem` rows with `sellable_type = 'composite_item'`, `/active-menu` serializes the `CompositeItem`'s name/code/base price/tax/image into the POS catalog. Updating or deleting a composite item through `CompositeItemController` therefore changes what the cashier should see, but this realtime hook only observes `Product` plus menu/menu-category models, so no `catalog.changed` event is emitted and POS clients remain stale until the 60s polling fallback. Register the same observer for `CompositeItem` or explicitly broadcast from those endpoints.

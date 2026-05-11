# PR D — Codex Round 3 Review

**Subject:** feat(pos): real-time catalog refresh via WebSocket (Bug 1)
**PR:** https://github.com/otospexsolutions/erp/pull/122
**Base:** `dev`
**Branch:** `feat/pos-catalog-websocket-sync` (head `8a7da9bb` — Codex r2 P2 closure)
**Date:** 2026-05-11

## Findings

### [P2] Cover modifier changes in catalog broadcasts

`apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php:29`

For Menu tenants, `/active-menu` includes each composite item's modifier groups and modifiers, but this only observes `CompositeItem`. Mutations through `ModifierController` or `ModifierGroupController` (e.g. changing a modifier price/name/active flag, or attaching/detaching a group from an item) change the POS catalog payload without saving the composite item, so no `catalog.changed` event is emitted and the POS remains stale until the 60s polling fallback.

## Verdict

**REQUEST-CHANGES** — single P2 must close before merge.

## Resolution applied (round 4 prep)

Registered observers for `ModifierGroup` (direct tenant/company columns → `Model::observe(CatalogModelObserver::class)`) and `Modifier` (relation-resolved via `ModifierGroup` → `Event::listen` with closure that walks `modifier_group_id` → `ModifierGroup::find(...)` → broadcast).

Two new regression tests in `CatalogChannelEventBroadcastTest`:
- `test_modifier_group_save_dispatches_catalog_channel_event` — direct observer.
- `test_modifier_save_dispatches_catalog_channel_event_with_resolved_tenant` — Event::listen + relation resolver.

## Raw codex output

> The new realtime catalog refresh path misses modifier-related catalog mutations that are part of the active menu consumed by POS. This leaves a concrete class of catalog updates dependent on the old polling delay.
>
> Review comment:
>
> - [P2] Cover modifier changes in catalog broadcasts — apps/api/app/Modules/Catalog/Providers/CatalogServiceProvider.php:29-29
>   For Menu tenants, `/active-menu` includes each composite item's modifier groups and modifiers, but this only observes `CompositeItem`. Mutations through `ModifierController` or `ModifierGroupController` change the POS catalog payload without saving the composite item, so no `catalog.changed` event is emitted and the POS remains stale until the 60s polling fallback.

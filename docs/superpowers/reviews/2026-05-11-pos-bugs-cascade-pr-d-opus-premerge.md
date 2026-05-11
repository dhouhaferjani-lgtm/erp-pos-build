# PR D — Opus Pre-Merge Audit

**Subject:** feat(pos): real-time catalog refresh via WebSocket (Bug 1)
**PR:** https://github.com/otospexsolutions/erp/pull/122
**Base:** `dev`
**Branch:** `feat/pos-catalog-websocket-sync`
**Head:** `474423e2` (post-Codex r6 APPROVE)
**Date:** 2026-05-11
**Auditor:** Opus 4.7 (same model that authored the implementation — see Limitations)

---

## Summary

PR D replaces the 60 s polling latency on admin-side catalog mutations with a real-time refresh signal. Backend broadcasts a coarse `catalog.changed` event on a private company-level channel; the POS subscribes, debounces 500 ms, and re-runs `productStore.fetchProducts(true)`.

The PR went through **6 Codex rounds** — one routine review (r1 mass-delete bypass) and four "kickoff under-spec" closures (r2 composite items, r3 modifiers, r4 pivot writes, r5 logout-path lazy create) before r6 APPROVE. Each round closed a real correctness gap; STOP-3 didn't fire.

Preflight green on every gate:
- `pnpm typecheck` — 0 errors
- `pnpm lint` — 0 errors / 41 warnings (baseline preserved)
- `pnpm test` — 1233 passed (137 files) — 6 new (useCatalogChannel hook)
- `phpstan` POS / Product / Menu / Catalog — 0 errors (level 8)
- `pint` — pass on touched files
- `phpunit` Feature `CatalogChannelEventBroadcastTest` — 14 passed

---

## L1 — Cross-tenant audit

Every CatalogChannelEvent broadcast carries `tenantId` + `companyId` from authoritative model state (set at event-construction time, not from `auth()` or request facades). Channel auth in `routes/channels.php` delegates to `User::canAccessCompanyChannel($tenantId, $companyId)`, which verifies tenant_id match + active status + active UserCompanyMembership. Same pattern as 4 other production channels (imports / partners / kitchen / terminal). **No L1 risk.**

## L8 — Cross-screen ownership

No new screens. The hook mounts unconditionally in `AppShell` (no-op until `companyId` is set) and is owned exclusively by the catalog-refresh state surface. The existing `useTerminalActivation` hook continues to own the terminal-activation surface. The two are independent — they subscribe to different channels and write to different state stores. **No L8 risk.**

## L9 — Ingress audit (the round-by-round closure list)

Every catalog-mutation site that the cashier could see through `/active-menu` or the product-list endpoint:

| Mutation path | Coverage | Notes |
|---|---|---|
| `Product` create/update/delete | `Model::observe(CatalogModelObserver::class)` in `ProductServiceProvider` | direct tenant/company columns |
| `Menu` create/update/delete | `Model::observe(...)` in `MenuServiceProvider` | direct tenant/company columns |
| `MenuCategory` create/update/delete (model call) | `Event::listen('eloquent.saved/deleted: ...')` in `MenuServiceProvider` | relation-resolved via `menu_id → Menu` |
| `MenuCategoryItem` create/update/delete (model call) | `Event::listen(...)` in `MenuServiceProvider` | relation-resolved via `menu_category_id → MenuCategory → Menu` |
| `MenuCategoryItem` bulk `where()->delete()` in `syncItems` / `removeItem` (Codex r1) | explicit `broadcastCatalogChange()` in `MenuCategoryController` | Eloquent mass-deletes bypass model events |
| `CompositeItem` create/update/delete (Codex r2) | `Model::observe(...)` in `CatalogServiceProvider` | direct tenant/company columns |
| `ModifierGroup` create/update/delete (Codex r3) | `Model::observe(...)` in `CatalogServiceProvider` | direct tenant/company columns |
| `Modifier` create/update/delete (Codex r3) | `Event::listen(...)` in `CatalogServiceProvider` | relation-resolved via `modifier_group_id → ModifierGroup` |
| `composite_item_modifier_groups` pivot via `assignToItem`/`removeFromItem` (Codex r4) | explicit `broadcastCatalogChange()` in `ModifierGroupController` | pivot writes bypass model events on both sides |
| Hook cleanup during logout (Codex r5) | `peekEcho()` returns null when `disconnectEcho()` has already run | avoids lazy WebSocket re-create |

The only thing left out of this list is **stock visibility toggles** (PR D out-of-scope per amendments-v1). If/when those exist as a discrete UI surface, the same observer pattern can extend to them.

---

## Root cause vs symptom check

PR D is a missing-feature add, not a bug fix. There is no "root cause" — only a UX gap (60 s polling latency) that the WebSocket signal closes. The implementation is straightforward broadcast/subscribe; the complexity all came from making sure the ingress list is complete (the iterated Codex rounds).

**No symptom-only patching.**

---

## Risks and operational notes

1. **WS-down sessions.** The 60 s polling tick in `runFullSync` is preserved as the fallback. A POS terminal with WS down sees a 60 s catalog latency, same as today. The hook degrades silently — it logs to console but doesn't surface UI errors.

2. **Channel auth.** The channel is private, so unauthenticated subscribers are rejected at the broker (Reverb) level. Tenant cross-contamination is prevented by `canAccessCompanyChannel`.

3. **Broadcast failures.** `CatalogModelObserver::broadcastFor` wraps `broadcast()` in a try/catch and logs warnings. An event-bus outage cannot block the underlying CRUD. The 60 s polling fallback also covers missed broadcasts.

4. **Coarse payload.** Per the amendments-v1 v1 scope decision, the payload carries no entity IDs — POS reads the authoritative state via REST on receipt. If real-time precision becomes a UX requirement, the payload can extend with entity IDs and the POS can do partial updates. Tracked in the PR body's "Out of scope" section.

5. **Reverb load.** Bulk admin operations (e.g. an import that creates 100 products) now produce 100 events. The POS-side debounce (500 ms) coalesces these into a single fetch, but the broker fans them out to N connected clients before they reach the debounce. If the company has many active POS terminals, the broker traffic scales with `#events × #terminals`. Mitigation: Reverb's default config handles this well, but watch for queue depth under heavy admin imports.

---

## Codex round trail

- r1: 1 P2 (mass-delete bypass on MenuCategoryItem). **Closed** via explicit controller broadcasts.
- r2: 1 P2 (CompositeItem missing). **Closed** via observer registration.
- r3: 1 P2 (Modifier/ModifierGroup missing). **Closed** via observer + Event::listen relation resolver.
- r4: 1 P2 (pivot writes on composite_item_modifier_groups). **Closed** via explicit controller broadcasts.
- r5: 1 P2 (cleanup lazy-creates Echo on logout). **Closed** via `peekEcho()` accessor.
- r6: no findings. **APPROVE.**

Six rounds is unusual but every finding was real; none required structural rework. The cumulative effect is a v1 catalog-broadcast ingress that demonstrably covers every documented mutation path through the cashier-facing surface.

---

## Residual risk — what manual smoke must verify

The unit tests cover every documented mutation path, but the end-to-end "admin → POS visible within 1 s" loop is not testable mechanically. Recommended:

- [ ] With the POS open at `/`, create a Product in the web dashboard. POS catalog updates within ~1 s.
- [ ] Update an existing menu category's display order. POS reflects the change within ~1 s.
- [ ] Attach a modifier group to a composite item. POS modifier picker updates within ~1 s.
- [ ] Detach a modifier group. POS modifier picker reflects the removal within ~1 s.
- [ ] Bulk-create 50 products via import. POS coalesces into a single refetch (not 50 cascading fetches).
- [ ] Stop Reverb. Verify polling fallback still picks up changes within 60 s.
- [ ] Logout and immediately re-login. Verify no extra WebSocket connections in the network panel.

---

## Limitations of this audit

I authored the implementation. The 6 Codex rounds gave 5 independent re-looks; r6 APPROVE is a stronger signal than my own self-review. Confidence in the ingress completeness is high because every iteration found a real gap and closed it. The only unverified surface is the end-to-end browser smoke (above).

---

## Verdict

**APPROVE-PENDING-CASHIER-SMOKE.**

The catalog broadcast is correct, tenant-anchored, and demonstrably covers every documented mutation path. The Codex 6-round closure trail proves the ingress is the right shape. Lint/typecheck/phpstan/phpunit/vitest are all green. The end-to-end "admin mutation → POS within 1 s" loop is the residual unknown; the manual-smoke list above covers it.

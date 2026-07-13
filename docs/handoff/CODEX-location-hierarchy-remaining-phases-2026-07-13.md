# CODEX brief — Location Hierarchy: ALL remaining phases (spec §11.2–.5), autonomous with gates

> Long-running autonomous Codex-desktop task. Design spec (approved + Codex-review reconciled): `docs/superpowers/specs/2026-07-07-location-placement-hierarchy-design.md` — READ IT FULLY FIRST; owner decisions D1–D12 and §5–§7 are binding. Phase 1 (backend: models, tree/placement APIs, delta endpoint, rename migration) is MERGED and cut over (`52eac5364`); you build the human-facing surfaces on top. Mobile handover finalization (§11.6) is already done — NOT yours.

## Setup

- Fresh worktree: `git worktree add ../erp.placement-ui -b feat/location-placement-phase2 origin/dev`. Never commit to shared dev; never push dev.
- One wave per spec phase, in order, each its own commit series with a HARD-STOP gate (below) before the next.
- Progress file: `docs/handoff/location-placement-phase2-progress.md` (wave log, gate verdicts, deviations with reasons).

## Autonomous gates (after EVERY wave)

1. Tag `plc-gate-<N>-rc<attempt>`.
2. `claude -p --model claude-opus-4-8 "ADVERSARIAL GATE REVIEW, Location Placement Phase 2, GATE <N>. Review ONLY git diff <prev-tag-or-origin/dev>..HEAD against docs/superpowers/specs/2026-07-07-location-placement-hierarchy-design.md §5-§7 + §10 testing strategy + this brief's wave scope. Verify with file:line citations; hunt: hardcoded colors (design-audit 0-new), missing tenantScopedKey on query keys / wrapped invalidation filters (both wrong directions), stale zones.* i18n keys, missing en/fr/ar parity, parseFloat/Number on quantities, subtree-query drift from the spec's canonical §3.1 form, prefix-collision (A1 vs A10) regressions, permission-gate gaps (view vs adjust per the Phase-1 route table), untransformed types (typescript:transform is a BLOCKING step). Write review to docs/handoff/gate-reviews-plc/GATE-<N>-rc<attempt>.md ending VERDICT: APPROVE or CHANGES-REQUIRED with numbered severity findings."`
3. **Escalation:** a BLOCKER/HIGH touching **counting-seed correctness** (subtree/variant expansion, counted-quantity handling — inventory-spine) or **CSV bulk-write integrity** (mass placement/node writes, dry-run vs commit divergence) → STOP and report to the owner for a model-tiering decision before re-running (owner's standing rule: ask before using Fable on any review). Do NOT auto-escalate to Fable. Everything else stays Opus.
4. CHANGES-REQUIRED → fix test-first, bump rc, re-run. 3 consecutive rc failures on one BLOCKER → STOP and report.
5. APPROVE → tag `plc-gate-<N>`, log in progress file, continue. After the last gate: leave worktree intact, report done. Final whole-branch review + merge is done by the Claude session (Phase-② pattern) — NOT you.

## Ground rules (violations block merge)

TDD both layers (vitest/PHPUnit by path ONLY — never full suites; kill `node (vitest` zombies after hangs). TS strict no `any`; PHP strict, PHPStan L8 zero on new code; Pint. Design tokens exclusively in new files (`@/lib/designTokens` / `semanticColorTokens`) — audit:design-system MUST stay 0-new (753 baseline). TanStack: `tenantScopedKey([...])` on QUERY keys; invalidation FILTER keys = bare prefixes (audit:keys errors on wrapped filters). i18n en+fr+ar REAL translations, `inventory` namespace; migrate `zones.*` → `placement.*` and add the spec's stale-`zones.*` search check as a test. Constructor injection only. `CACHE_STORE=array php artisan typescript:transform` after ANY DTO change — generated types are the FE source of truth, never hand-edit. Quantities: strings end-to-end, `QuantityScale`/`formatQuantity`, never parseFloat. Routes: follow the Phase-1 middleware pattern exactly (`can:inventory.view` reads / `can:inventory.adjust` writes).

## Phase-1 API surface you build on (verified on dev)

`GET /inventory/locations/{location}/nodes` (tree) · `POST /inventory/nodes` · `PATCH /inventory/nodes/{node}` · `POST /inventory/nodes/{node}/move` · `DELETE /inventory/nodes/{node}` · `POST /inventory/nodes/{node}/restore` · `GET /inventory/nodes/{node}/products` (paginated) · `POST /inventory/nodes/{node}/assign-products` · `DELETE /inventory/nodes/{node}/products/{product}` (unassign) · `POST /inventory/placements/bulk-move` · `GET /inventory/products/{product}/placements` · `PUT /inventory/products/{product}/placements` · `GET /inventory/placements` (mobile delta — do NOT touch). DTOs: `LocationNodeDto`, `ProductPlacementDto`, `LocationNodeType` in generated types.

## Wave 1 — Tree management UI (spec §5.1–§5.3, §11.2)

New page `/inventory/placement` ("Rangement & emplacements"), Inventaire nav, gated `inventory.view` (write actions gated `inventory.adjust` in-UI). Master–detail (§5.2 view B): location selector + node tree (expand/collapse, type icon, code+name, product-count badge, active state; add-child; drag-reorder → `sort_order`; drag-reparent → `move` endpoint) | shared node-detail component (header: name/code/`path` breadcrumb/type/edit/add-child/delete/restore; Products tab: paginated+searchable, add by name/SKU/barcode, per-row unassign, multi-select bulk-move). View A (§5.3): tree-table toggle over the SAME data source, same node-detail in a drawer. Replace the old zones modal reachability: `settings/locations` card button → this page pre-scoped. The bulk-move route already exists: `POST /inventory/placements/bulk-move` (`can:inventory.adjust`) → `ProductPlacementController::bulkMove` → `LocationNodeService::bulkMove`. Wire the multi-select bulk-move UI to it; do NOT add a route or reimplement placement logic. i18n migration `zones.*`→`placement.*` + stale-key check test.

## Wave 2 — Product-page placement field (spec §5.4, §11.3)

"Storage location" field per location on the product page. The per-product endpoints ALREADY EXIST from Phase 1 — `GET /inventory/products/{product}/placements` (`productPlacements`, `can:inventory.view`) and `PUT /inventory/products/{product}/placements` (`setProductPlacement`, `can:inventory.adjust`; node_id uuid = set, node_id null/'' = clear). This wave is **frontend-only**: wire the field to those endpoints. Field = node picker scoped to the location (reuse Wave 1's tree picker component), shows current placement `path`, clearable. Do NOT add or duplicate the routes/controller methods.

## Wave 3 — CSV `placement_path` (spec §6, §11.4)

Product import gains optional `placement_path` per target location (`A1 > R2 > B7`). Strict mode (segments must resolve) + auto-create mode (missing segments created; node-type-per-depth chosen in a dry-run mapping step). CODE-FIRST (D6): name segments only when unambiguous — deterministic error on ambiguous names in dry-run. Dry-run preview through the EXISTING import pipeline (`docs/modules/imports.md` + the unified-imports conventions — reuse, don't fork): nodes-to-create, placements-to-set, per-row errors, before commit. Onboarding explainer copy on the placement/import screens. Import writes go through the Phase-1 service (partial-unique-safe) — never raw inserts.

## Wave 4 — Counting node-scope (spec §7, §11.5)

Counting wizard `ZoneScopeSelection` → node-tree picker (location → multi-select nodes; a selected node scopes its ENTIRE subtree). Relabel per D7: enum value `'zone'` stays, UI says "Node / Zone". CONFIRMED MISSING (verified against merged code at review time): `zoneItemSeeds` (`InventoryCountingService.php:305-351`) does a flat `whereIn('node_id', $zoneIds)` (NOT subtree-aware — no §3.1 `path`/`LIKE` query) and hard-codes `variant_id => null` (NO variant expansion). Implement BOTH per §4.5 THIS wave: subtree via the canonical §3.1 query (`path = :p OR path LIKE :p || '/%'` — mind the A1 vs A10 collision), and product→variant expansion at seed. This is the inventory-spine surface — gate escalation rule applies. Assign-as-you-count: selected node, ancestor-ok, single-scope only. Reference-migration test per §10. E2E per §10's flow: create tree → assign → bulk-move → subtree count expands variants → finalize proves stock math unchanged.

## Out of scope

Mobile app anything (separate track); variant-level placement (D9 fast-follow); node full-sync cursor (§12); per-location reorder policy; touching the delta endpoint contract.

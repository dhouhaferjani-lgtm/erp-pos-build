# Location Placement Hierarchy — Design Spec

**Date:** 2026-07-07
**Status:** Draft — design approved by owner; **Codex adversarial review (BLOCK) reconciled 2026-07-07** (see `docs/superpowers/specs/reviews/2026-07-07-location-placement-hierarchy-codex-review.md` and §13); pending owner spec review.
**Author:** ERP web/API session
**Supersedes:** the flat `location_zones` model + Zones modal introduced by the live-inventory-counting feature (`apps/api/database/migrations/tenant/2026_07_06_200002_create_location_zones_tables.php`, `apps/web/src/features/settings/zones/`).

---

## 1. Problem & goals

Today "zones" are a **flat** placement label: a location has zones; a product occupies exactly one zone per location. The management UI is a **modal-in-a-modal** (`ZonesPanel` inside a `Modal`, with nested dialogs). This does not scale to a real client — tens/hundreds of locations, each with many aisles/racks/shelves/bins — and cannot express how stores actually organize stock.

**Goal:** a flexible, arbitrary-depth **location placement hierarchy** managed on proper pages, practical at 20–30k products, that also makes inventory counting more structured (count a subtree).

**Non-goals (explicit):**
- **No bin-level stock.** Nodes are *labels only*; on-hand stock stays at the `(product, location[, variant])` grain. WAC/costing, stock movements, transfers, POS decrement, fiscal projections are **untouched**.
- No per-tenant custom node-type sets in v1 (fixed enum; future extra).
- No rigid level-ordering rules (any parent→child type combination is allowed).
- **No hard deletes in v1** — nodes/placements are soft-deleted (tombstones) so offline clients can converge (§3.4).

---

## 2. Approved decisions

| # | Decision | Rationale |
|---|---|---|
| D1 | **Single self-referencing table** (`location_nodes`), arbitrary depth via `parent_id`. | Flexible composition (zone→rack, rack→bin, aisle→rack→bin) with one table + one migration. |
| D2 | **Labels only** — no quantity on nodes; stock stays at product×location. | Bounds complexity; keeps costing engine stable. |
| D3 | **Node types**: fixed v1 enum `{zone, aisle, rack, shelf, bin, section}`, **no ordering constraints**. Type = label + icon. | "Go as deep or shallow as you like, any combination." |
| D4 | **Placement UI = B master–detail + A tree-table toggle.** Shared node-detail component. | Standard for hierarchical mgmt at scale; A reuses the same data. |
| D5 | **All four placement mechanisms in v1** + CSV `placement_path` on product import. | 20–30k products need bulk seeding. |
| D6 | **CSV auto-create-along-path with dry-run preview**, plus a strict "must pre-exist" mode. **Code-first for bulk production loads** (names allowed only when unambiguous). | Realistic onboarding; avoids name-collision ambiguity (review Minor 3). |
| D7 | **Keep `CountingScopeType` value `'zone'`**; relabel in UI to "Node / Zone". Counting scope becomes subtree-aware. | Avoids data migration of existing counts. |
| D8 | **Home in the Inventaire nav** (plus reachable per-location), replacing the modal. | First-class surface at scale. |
| **D9** | **Placement is PRODUCT-level, not variant-level, in v1.** `product_placements` keys on `(product_id, location_id)`; a subtree count expands a placed product to all its variants at seed time. | Simpler; a variant is stored with its product. Variant-level placement = documented fast-follow (review Important 3). |
| **D10** | **Soft-delete only** (`SoftDeletes` on both models). Deletes tombstone the row (and, for nodes, the subtree + affected placements) inside one transaction — **never** via FK cascade. Tombstones retained ≥ a configured window; any future purge is a separate GC that respects the mobile sync watermark. | FK cascade doesn't fire on soft-delete; hard-delete would erase tombstones mobile needs (review Critical 2). |
| **D11** | **Node `code` grammar**: `^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$` — **bans `/`, `%`, `_`, whitespace**, so it's a safe `path` segment and safe in `LIKE`. | `path` uses `/` as separator; `%`/`_` are LIKE wildcards (review Critical 4). |
| **D12** | **Delta contract is DEFINED (not provisional)**: server-issued high-water-mark + `(updated_at, id)` tuple cursor (§4.3). Page size is the only tunable. | Removes the missed-at-watermark / pagination-drift bug (review Critical 1). |

---

## 3. Data model

### 3.1 `location_nodes` (evolve `location_zones`)

Rename `location_zones` → `location_nodes`; add columns. Model gets **`SoftDeletes`**.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `tenant_id` | uuid | plain index (central-DB tenants) |
| `location_id` | uuid FK→locations cascade | |
| `parent_id` | uuid FK→location_nodes NULL | self-ref; NULL = top-level. On hard-delete (GC only) cascade; normal delete is soft (D10). |
| `node_type` | string | `LocationNodeType` enum: `zone|aisle|rack|shelf|bin|section`. |
| `code` | string(50) | grammar per **D11**. Unique **partial** `(location_id, code) WHERE deleted_at IS NULL` (a deleted code can be reused; review Important 1). |
| `name` | string(255) | |
| `path` | string(indexed) | materialized ancestor **code** chain, `/`-joined (`A1/R2/B7`). Server-authoritative. Index uses **`text_pattern_ops`** (or `ltree`) so subtree `LIKE` uses the index (review Critical 4). |
| `depth` | smallint | 0 = top-level. |
| `sort_order` | int default 0 | |
| `is_active` | bool default true | |
| `deleted_at` | timestamptz NULL | tombstone (`SoftDeletes`). |
| timestamps | timestamptz | `updated_at` = delta cursor source. |

**Subtree query (canonical, everywhere):**
```sql
WHERE (n.path = :p OR n.path LIKE :p || '/%' ESCAPE '\')
```
`:p` is the node's own path; the explicit `= :p OR … '/%'` form avoids the `A1` vs `A10` prefix collision. Because `code` bans `%`/`_`/`\` (D11) no escaping of `:p` content is needed, but queries still declare `ESCAPE '\'` defensively.

**Invariants:** `parent_id` same `location_id`; no cycles (guarded on move); `path`/`depth` recomputed for node **and whole subtree** on create / move / code-change.

### 3.2 `product_placements` (evolve `product_zone_assignments`)

Rename `product_zone_assignments` → `product_placements`; `zone_id` → `node_id`; add `deleted_at`; model gets **`SoftDeletes`**. **Product-level (D9)** — no `variant_id`.

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `tenant_id` | uuid | |
| `product_id` | uuid FK→products cascade | |
| `location_id` | uuid FK→locations cascade | |
| `node_id` | uuid FK→location_nodes | node at **any** depth. |
| `deleted_at` | timestamptz NULL | tombstone. |
| timestamps | timestamptz | `updated_at` = delta cursor. |

**Uniqueness & write algorithm (review Critical 3):**
- Partial unique index: `CREATE UNIQUE INDEX ... ON product_placements(product_id, location_id) WHERE deleted_at IS NULL` — one **live** placement per product per location; tombstoned duplicates allowed.
- **Assign / move / reassign** run inside a transaction that locks by `(product_id, location_id)`:
  1. `SELECT ... FOR UPDATE WHERE product_id=? AND location_id=? AND deleted_at IS NULL` → if a live row exists, `UPDATE node_id`, bump `updated_at`.
  2. else `INSERT` a new live row (`ON CONFLICT (product_id, location_id) WHERE deleted_at IS NULL DO UPDATE`), OR restore the most-recent tombstone — both acceptable; implementation picks one and tests both branches.
- **Unassign** = set `deleted_at` on the live row.

### 3.3 Required indexes (review Important 7)
- `location_nodes(location_id, code) UNIQUE WHERE deleted_at IS NULL`
- `location_nodes(location_id, path text_pattern_ops)` (subtree prefix scans)
- `product_placements(product_id, location_id) UNIQUE WHERE deleted_at IS NULL`
- `product_placements(location_id, node_id) WHERE deleted_at IS NULL` (node-products listing)
- `product_placements(location_id, updated_at, id)` (delta cursor scan)

### 3.4 Models / services / DTOs (rename map)
- `LocationZone` → `LocationNode` (+`SoftDeletes`, `parent()`, `children()`, `descendants()` via path, `productPlacements()`).
- `ProductZoneAssignment` → `ProductPlacement` (+`SoftDeletes`).
- `ZoneService` → `LocationNodeService` (+`move`, `unassign`, `bulkMove`, `restore`, `subtreeProductIds`, `recomputePath`). **All mutating methods take tenant/company context and validate product+location+node+parent consistency inside the transaction** (review Important 6 — don't trust the controller as the only boundary).
- `ZoneController` → `LocationNodeController`.
- DTOs → `LocationNodeDto` (+`parent_id, node_type, path, depth`) / `ProductPlacementDto`.
- **`CountingScopeType` value stays `'zone'`** (D7); only `label()` changes.

---

## 4. API

Base `/inventory/...`, middleware `['api','auth:sanctum',SetPermissionsTeam]`, module-gated. Reuse `inventory.view`/`inventory.adjust` — **no new permission keys** (no `permission:cache-reset` on deploy).

### 4.1 Node tree
| Method | Path | Perm | Purpose |
|---|---|---|---|
| GET | `/inventory/locations/{location}/nodes` | inventory.view | Full node tree (flat + `parent_id`/`path`). Small; not paginated (ceiling §12). Excludes tombstones by default; `?include_deleted=1` for sync prune. |
| POST | `/inventory/nodes` | inventory.adjust | Create (`location_id, parent_id?, node_type, name, code, sort_order?`). Validates code grammar (D11), same-location parent; computes `path`/`depth`. |
| PATCH | `/inventory/nodes/{node}` | inventory.adjust | Update name/code/type/sort_order/is_active. Code change → recompute subtree `path` atomically (§4.2). |
| POST | `/inventory/nodes/{node}/move` | inventory.adjust | Reparent. Locks node+new-parent+subtree, rejects cycle (new parent inside moving subtree) and cross-location, then updates `parent_id`/`path`/`depth` for the subtree in one transaction (review Important 2). |
| DELETE | `/inventory/nodes/{node}` | inventory.adjust | **Soft-delete node + subtree + their placements** in one transaction (NOT FK cascade). Refuses if live placements exist unless `?force=1` (then tombstones them). |
| POST | `/inventory/nodes/{node}/restore` | inventory.adjust | Un-tombstone a node (code must still be free per partial unique). |

### 4.2 Path recomputation (atomicity)
Create/move/code-change wrap in a transaction with `SELECT … FOR UPDATE` on the moving node + new parent (or a per-`location_id` advisory lock). Subtree `path` update is a single prefix-replacement `UPDATE … SET path = :newprefix || substr(path, length(:oldprefix)+1)` over `WHERE location_id=? AND (path=:old OR path LIKE :old||'/%')`. Depth recomputed from `path` segment count.

### 4.3 Placements
| Method | Path | Perm | Purpose |
|---|---|---|---|
| GET | `/inventory/nodes/{node}/products` | inventory.view | Placements in node (paginated + `search=`). |
| POST | `/inventory/nodes/{node}/assign-products` | inventory.adjust | Bulk assign `product_ids` (upsert per §3.2 algorithm). |
| DELETE | `/inventory/nodes/{node}/products/{product}` | inventory.adjust | **Unassign** (tombstone). |
| POST | `/inventory/placements/bulk-move` | inventory.adjust | Move `product_ids` → target `node_id` (same location; §3.2 algorithm each). |
| GET | `/inventory/products/{product}/placements` | inventory.view | A product's live placement per location. |
| PUT | `/inventory/products/{product}/placements` | inventory.adjust | Set/clear a product's placement at a location. |

### 4.4 Delta sync (mobile) — **DEFINED contract (review Critical 1)**
`GET /inventory/placements?location_id=&cursor=&limit=`
- **First call** (no `cursor`): server captures `sync_high_watermark = now()` (server clock) and returns it in the response; page 1 uses it.
- Query per page:
  ```sql
  WHERE location_id = :loc
    AND (updated_at, id) > (:cur_ts, :cur_id)
    AND updated_at <= :sync_high_watermark
  ORDER BY updated_at, id
  LIMIT :limit
  ```
- Response: `{ rows:[…incl deleted_at…], next_cursor:{updated_at,id}|null, sync_high_watermark }`. Rows **include tombstones** (deleted since last cursor).
- Client persists the cursor **only after the final page** (`next_cursor=null`); carries `sync_high_watermark` across pages of one sync run. This closes the missed-at-watermark and concurrent-write-drift holes.
- **Wire format:** timestamps are **ISO 8601 UTC** on the wire; the device normalizes to SQLite `YYYY-MM-DD HH:MM:SS` via `toSqliteUtc()` only for local SQL comparisons (repo rule 20 / `apps/pos/src/lib/db/sqliteTime.ts`). Cursor tuple is compared numerically/lexically consistently, never mixing ISO `T` with SQLite space.
- `limit` default 500 (tunable); the only unfrozen knob.
- Node tree has no delta endpoint (full-sync); nodes carry `deleted_at` so the device prunes deleted nodes.

### 4.5 Counting consumption (single backend phase + tests — review Important 4)
Every old reference migrates together, guarded by a test that all of these run against `location_nodes`/`product_placements`:
`CreateCountingRequest` (validation table + `validateZonesBelongToLocation`), `InventoryCountingService::zoneItemSeeds` + `assignCountedItemToZone`, `CountingBlockService::zoneAdvisoriesFor`, zone-draft activation, DTO transform.
- `zoneItemSeeds` becomes **subtree-aware** via the canonical §3.1 query; a placed product **expands to all its variants** at seed (counting items stay variant-grain — review Important 3).
- **Assign-as-you-count** (review Important 5, refined 2026-07-28): with exactly one scoped node, a first-counted item keeps its current precise placement when it is already inside that node's subtree. An unplaced item or one outside the subtree is assigned to the explicitly selected node, which may be an ancestor. No "leaf" requirement.

---

## 5. Web UX

### 5.1 IA / navigation
- New page under **Inventaire**: "Rangement & emplacements" (`/inventory/placement`), gated `inventory.view`.
- Reachable from each `settings/locations` card ("Zones" button → "Rangement", pre-scoped to that location); **modal removed**.
- i18n: extend `inventory` namespace, migrate `zones.*` → `placement.*`; an **acceptance/search check** ensures no stale `zones.*` labels remain in the counting wizard or settings (review Minor 1).

### 5.2 Primary view — B (master–detail)
Left: location selector + node tree (expand/collapse, type icon, code, name, product-count badge, active). Add-child, drag-reorder (`sort_order`), drag-reparent (→ `move`).
Right: **shared node-detail component** — header (name/code, `path` breadcrumb, type, edit/add-child/delete/restore); **Products** paginated+searchable, add (name/SKU/barcode), per-row unassign, multi-select → bulk-move.

### 5.3 Secondary view — A (tree-table toggle)
Same data flattened into an expandable table (code, name, type, #products, active, actions); bulk-select rows; clicking a node opens the **same** node-detail component in a drawer.

### 5.4 Product page
"Storage location" field per location (reads/writes `products/{id}/placements`).

### 5.5 Conventions
TanStack `tenantScopedKey`, design tokens, `t()` i18n, no-`any`, constructor injection, both-layer module gating. **`php artisan typescript:transform` is a blocking step before FE compiles** (review Minor 2).

---

## 6. CSV import + onboarding
- Product import gains optional **`placement_path`** column per target location, e.g. `A1 > R2 > B7`.
- **Code-first for bulk production loads (D6)**; name segments allowed only when unambiguous — the dry-run raises a deterministic error on any non-unique name segment (review Minor 3).
- **Modes:** *strict* (segments must resolve) and *auto-create* (missing segments created; node type per depth set in a mapping step in the dry-run preview).
- **Dry-run preview** (reuse import pipeline): nodes-to-create, placements-to-set, row errors — before commit.
- **Onboarding explainer** on the placement/import screen: "Define your storage layout first, or let the import build it from `placement_path`."

## 7. Counting integration (the payoff)
Wizard `ZoneScopeSelection` → **node-tree picker**: choose a location, select one or more nodes; a selected node counts its **entire subtree** (canonical §3.1 query). Assign-as-you-count per §4.5 preserves an existing descendant placement and re-homes only unplaced or out-of-subtree products. Zone-scoped counts stay non-blocking (advisory toasts). Existing counts referencing old ids keep working (rows migrate in place; enum value unchanged).

## 8. Mobile sync contract
See `docs/handoff/HANDOVER-location-hierarchy-mobile.md` (regenerated post-review). Full-sync `location_nodes`; delta-sync `product_placements` via the **§4.4 tuple-cursor contract** + tombstones; `path` for offline subtree counting; stock grain unchanged. Page size is the only tunable; cursor/format are defined.

## 9. Migration & backward compatibility
**Additive + backfill, zero data loss.** Ordered:
1. `ALTER TABLE location_zones RENAME TO location_nodes`; add `parent_id, node_type, path, depth, deleted_at`; backfill `node_type='zone', parent_id=NULL, path=code, depth=0`. Drop old `UNIQUE(location_id, code)`; add partial unique `WHERE deleted_at IS NULL`; add `location_id, path text_pattern_ops` index.
2. `ALTER TABLE product_zone_assignments RENAME TO product_placements`; rename `zone_id → node_id`; add `deleted_at`. **Drop** old `UNIQUE(product_id, location_id)`; **add** partial unique `WHERE deleted_at IS NULL`; add the §3.3 indexes.
3. Update all references (§4.5 list), regen types.
4. The "Aisle 1 / A1" node created during testing migrates to a top-level node.
- Deploy: `tenants:migrate` before code serves traffic (additive/backfill). No new permissions/queues.
- **Table naming**: `location_nodes`/`product_placements` intentionally evolve existing **unprefixed tenant inventory** tables, preserving local naming continuity (review Minor 5).

### 9.1 Branch / sequencing — **MANDATORY prerequisite** (review Critical/Important 9)
This redesign renames tables introduced by the **not-yet-merged** `feat/live-inventory-counting` branch. **Hard rule:** land live-inventory-counting on dev first (flat zones), then build this on a **new branch off dev** (`feat/location-placement-hierarchy`). Implementing before that merge would target tables absent on dev and entangle two review scopes. Not optional.

## 10. Testing strategy
- **Backend:** migration up/backfill; partial-unique enforcement (live vs tombstoned; reassign-after-unassign both write branches); `path` recompute on create/move/code-change incl. deep subtree; cycle + cross-location + prefix-collision (`A1` vs `A10`) guards; delta endpoint boundary (row updated exactly at `sync_high_watermark` is caught next run; no drift under concurrent insert during paging); tombstones returned since cursor; unassign/restore; **reference-migration test** (§4.5). `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`; **no full suite run without permission**.
- **Frontend:** node-tree render/expand; B⟷A toggle over one source; assign/unassign/bulk-move + `tenantScopedKey`; product-page field; render-output assertions; **stale `zones.*` i18n search check**; **`typescript:transform` regen gate**.
- **CSV:** strict vs auto-create dry-run; type-per-depth mapping; code-first; name-collision deterministic error.
- **E2E:** create tree → assign via node panel → bulk-move → counting subtree count (expands variants) → finalize (labels-only ⇒ stock math unchanged).

## 11. Implementation phasing
1. Model + migration + API (tree CRUD w/ atomic path + move; placement assign/unassign/bulk-move w/ partial-unique algorithm; **§4.4 delta endpoint**) + DTO/type regen.
2. Web tree UI — B + A + shared node-detail.
3. Product-page placement field.
4. CSV `placement_path` (strict + auto-create + dry-run) + onboarding explainer.
5. Counting node-scope (subtree seed + variant expansion + node-tree picker) + relabel + reference-migration tests.
6. Mobile handover finalization (append endpoint reference).

## 12. Risks & remaining open questions
- **Does the device ever *write* placements offline** (re-shelving)? Decides mobile outbox need. Deferred to mobile session; not blocking web/API.
- **Node full-sync ceiling:** fine to low tens of thousands of nodes/tenant; beyond that reuse the §4.4 cursor contract for nodes too (review Minor 4).
- **Large subtree move** cost — bounded by the single prefix-replacement UPDATE; benchmark at depth.
- Node delete-with-placements UX copy (force flag).

## 13. Codex review reconciliation (2026-07-07)
Review verdict was **BLOCK**; every finding is resolved here:
- **Critical 1 (delta)** → §4.4 defined tuple-cursor + server high-water-mark; handover corrected (delta is *defined*, page size the only tunable). **D12.**
- **Critical 2 (soft-delete vs cascade)** → **D10**, `SoftDeletes` both models, transactional subtree/placement tombstoning, no FK cascade for sync, no hard-delete in v1.
- **Critical 3 (partial-unique write algo)** → §3.2 lock-and-upsert algorithm + migration index sequence §9.
- **Critical 4 (path safety)** → **D11** code grammar; §3.1 canonical `= :p OR LIKE :p||'/%' ESCAPE` query; `text_pattern_ops` index.
- **Important 1 (node code reuse)** → partial unique on `code WHERE deleted_at IS NULL` + `restore` endpoint.
- **Important 2 (move atomicity)** → §4.2 locking + cycle check.
- **Important 3 (variant grain)** → **D9** product-level, seed expands to variants.
- **Important 4 (counting refs)** → §4.5 single phase + reference-migration test.
- **Important 5 (assign-as-you-count)** → §4.5 selected-node semantics, refined to preserve an existing descendant placement and re-home only unplaced or out-of-subtree products; removed "leaf" wording.
- **Important 6 (service authz)** → §3.4 service methods validate tenant/company/consistency in-transaction.
- **Important 7 (indexes)** → §3.3.
- **Important 8 (mobile timestamp/boundary)** → §4.4 wire-format + handover fix.
- **Important 9 (sequencing)** → §9.1 mandatory.
- **Minor 1–5** → i18n check (§5.1/§10), type-regen gate (§5.5/§10), CSV code-first (D6/§6), node-sync ceiling (§12), naming note (§9).

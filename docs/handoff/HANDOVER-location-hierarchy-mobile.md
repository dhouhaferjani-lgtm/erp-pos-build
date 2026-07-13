# Handover → Mobile ERP session: Location Placement Hierarchy (sync contract)

**Date:** 2026-07-07 (v2 — reconciled with Codex adversarial review)
**From:** ERP web/API session (worktree `apps/erp.live-counting`)
**To:** erp-mobile session (SQLite / offline-first infra migration)
**Feature status:** design approved + Codex review (BLOCK) reconciled. Spec: `docs/superpowers/specs/2026-07-07-location-placement-hierarchy-design.md`. **Data model + delta contract are DEFINED and safe to build against.** Only **endpoint paths** and **page size** remain tunable — wrap the base path behind one config.

---

## TL;DR — why this touches you

We're replacing the flat `location_zones` model with a **flexible, arbitrary-depth location hierarchy** (Location → Zone / Aisle / Rack / Shelf / Bin, any combination). Nodes are **placement labels only** — **stock quantity does NOT move to the node grain**, it stays at `(product, location[, variant])` exactly as today.

**What this means for you:**
- ✅ **Your stock/on-hand sync is UNAFFECTED** — grain stays product×location. No bin-level quantities, no new movement types.
- 🔧 **Two new things to model in SQLite**: the node tree (small, full-sync) and product placements (large, delta-sync).
- ⏳ **Build against the columns + delta contract below** — they're locked. Don't hard-code endpoint paths yet.

---

## 1. SQLite tables (LOCKED shape)

### `location_nodes` — the hierarchy (FULL sync; small: hundreds/tenant)
```
id           TEXT  PK (uuid)
location_id  TEXT
parent_id    TEXT  NULL      -- self-reference; NULL = top-level
node_type    TEXT            -- 'zone'|'aisle'|'rack'|'shelf'|'bin'|'section'
code         TEXT            -- grammar: ^[A-Za-z0-9][A-Za-z0-9._-]{0,49}$  (NO '/','%','_',space)
name         TEXT
path         TEXT            -- server-authoritative ancestor CODE chain, e.g. 'A1/R2/B7'  (§3)
depth        INTEGER
sort_order   INTEGER
is_active    INTEGER (bool)
updated_at   TEXT            -- normalize to SQLite 'YYYY-MM-DD HH:MM:SS' for local SQL (see §4)
deleted_at   TEXT  NULL      -- tombstone
```
- **No `quantity` column, ever.** Nodes carry no stock.
- `code` grammar bans `/ % _` and whitespace so `path` segments are safe and `LIKE`-safe. Treat `path` as **server-authoritative** — never compute it on-device.

### `product_placements` — where each product sits (DELTA sync; large: ~#products × #locations)
```
id           TEXT  PK (uuid)
product_id   TEXT
location_id  TEXT
node_id      TEXT            -- points at a location_nodes row at ANY depth
updated_at   TEXT
deleted_at   TEXT  NULL      -- tombstone (unassign / move-out)
-- one LIVE placement per (product_id, location_id); tombstoned duplicates may exist
```
- **Product-level, not variant-level** (v1). A variant is stored with its product.
- **Move** = same `(product_id, location_id)`, new `node_id`, bumped `updated_at`. **Unassign** = `deleted_at` set. Honor tombstones or you'll show ghosts.
- Because tombstoned duplicates can exist, resolve "current placement" as **the row with `deleted_at IS NULL`** (there is at most one).

---

## 2. Sync strategy (LOCKED)

| Data | Volume | Strategy |
|---|---|---|
| `location_nodes` | small (100s) | **Full sync** each cycle (replace-all fine). Request `include_deleted` to prune tombstoned nodes. Ceiling: fine to low tens of thousands; beyond that, same cursor contract as placements. |
| `product_placements` | large (20–30k × locations) | **Delta sync** via the tuple-cursor contract in §2.1. |

### 2.1 Placement delta contract (DEFINED — build against this)
`GET {base}/inventory/placements?location_id=&cursor=&limit=`
- **First page of a run** (no `cursor`): server captures and returns a `sync_high_watermark` (server clock). Use it for **every page of that run**.
- Server pages with, ordered by `(updated_at, id)`:
  ```
  WHERE location_id = :loc
    AND (updated_at, id) > (:cursor_updated_at, :cursor_id)
    AND updated_at <= :sync_high_watermark
  ```
- Response: `{ rows:[… each incl deleted_at …], next_cursor:{updated_at,id}|null, sync_high_watermark }`.
- **Rows include tombstones** (anything changed since your cursor, deletes included).
- **Persist your stored cursor ONLY after the final page** (`next_cursor == null`). Carry `sync_high_watermark` across that run's pages.
- This is what prevents (a) missing a row updated exactly at the boundary and (b) drift when writes land mid-pagination. Don't shortcut it.
- `limit`: server default 500, tunable — the only unfrozen knob.

## 3. `path` = offline subtree queries (no recursive CTE)
```sql
-- all products stored anywhere under Aisle 1, on-device (prefix-collision-safe):
SELECT p.* FROM product_placements pp
JOIN location_nodes n ON n.id = pp.node_id
WHERE pp.location_id = ? AND pp.deleted_at IS NULL
  AND (n.path = 'A1' OR n.path LIKE 'A1/%');
```
The explicit `= 'A1' OR LIKE 'A1/%'` form avoids the `A1` vs `A10` collision. This powers node/zone-scoped counting offline. `path` is server-maintained (recomputed on node move) — consume it, don't derive it.

## 4. Timestamp discipline (repo rule 20 — do not skip)
- **Wire format is ISO 8601 UTC.** The device **normalizes to SQLite `YYYY-MM-DD HH:MM:SS`** via `toSqliteUtc()` (`apps/pos/src/lib/db/sqliteTime.ts`) **before** any local SQL comparison — never compare an ISO `T`-separated string against a SQLite space-separated column (lexical `' ' < 'T'` silently drops rows).
- The delta **cursor** is compared consistently on both sides; if you store `updated_at` in SQLite format locally, convert it back to ISO (or send the server's opaque tuple) when requesting the next page — never mix formats in the tuple comparison.
- Comparisons in §2.1 are **strict `>` on the tuple** and **`<=` on the high-water-mark** — inclusive/exclusive as written; match exactly.

## 5. What is NOT in your scope (web/onboarding side)
Tree-management UI (master-detail + tree-table), CSV `placement_path` import, product-page placement field — all web. You **consume** nodes/placements and (later, maybe) let a device user **see/set** a product's placement.

## 6. Coordination / sequencing
1. **You (mobile, now):** build SQLite schema §1 + delta plumbing §2.1 + timestamp discipline §4. All locked — safe to proceed.
2. **Me (web/API):** implement model+API first (per spec §11 phase 1) so you have a live endpoint. **Sequencing is now a hard prerequisite:** this ships on a new branch off dev *after* live-inventory-counting merges.
3. **Together:** confirm page size at your device's memory profile; agree the base endpoint path when it's pinned (I'll append the endpoint reference to this doc once implemented).
4. **Your ERP catalog/stock delta groundwork** should finish first — placement delta reuses the same cursor shape, so it drops in.

## 7. One genuinely-open question (not blocking your schema)
- **Does the device ever WRITE placements offline** (a device user re-shelving a product)? If yes, you need an outbox for placements (same pattern as your other offline writes) and we add server conflict handling. If read-only for v1, skip it. Your call on the mobile side — tell me and I'll spec the server side to match.

---

## 8. ✅ ENDPOINT REFERENCE (appended 2026-07-13 — API is LIVE on dev `52eac5364`, rename migration applied)

All under the standard API base (`/api/v1`), middleware `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim,'module:Inventory']` — the tenant must have the **Inventory module enabled** (else 403), in addition to `can:inventory.view` on reads. Bearer token with `tenant:<uuid>` ability (same auth your existing sync uses).

| Purpose | Endpoint |
|---|---|
| **Node full-sync** (per location; small) | `GET /inventory/locations/{location}/nodes` |
| **Placement delta-sync** (§2.1 contract) | `GET /inventory/placements` — tuple cursor + server `sync_high_watermark` + tombstones per §2.1, **with the three wire corrections below** |

Three wire-level corrections vs §2.1's prose, verified against the shipped `ProductPlacementController::delta`:
- ⚠️ **Envelope:** the page array is returned under **`data`**, not `rows`. Parse `response.data`, `response.next_cursor`, `response.sync_high_watermark`.
- ⚠️ **Cursor serialization:** the response `next_cursor` is an object `{updated_at, id}`, but the **request** `cursor` query param must be the string `"<updated_at_iso8601>|<id_uuid>"` (server 422s if it lacks `|` or the id isn't a uuid). Re-serialize the object to that pipe string for the next page.
- ⚠️ **Watermark echo:** on pages 2..N of a run, send the first page's watermark back as query param **`sync_high_watermark=<iso8601>`** (same name). If omitted the server re-captures `now()` and you lose the mid-pagination drift guarantee.
| Node products (if you build a browse surface) | `GET /inventory/nodes/{node}/products` (paginated) |

Write endpoints exist (`POST /inventory/nodes`, `move`, `assign-products`, …) but are **web-scoped for now** — see §7; do not build against them until the offline-write question is decided. Page size remains the only tunable on the delta endpoint. Generated type shapes: `LocationNodeDto`, `ProductPlacementDto`, `LocationNodeType` (`'zone'|'aisle'|'rack'|'shelf'|'bin'|'section'`) in the ERP repo `packages/shared/types/generated.d.ts` — mirror them, don't invent fields.

## 9. Dispatch instructions (Codex, mobile repo — end-to-end)

Implement the consumer end-to-end, autonomously, with checkpoints:

- **Scope (v1 = READ-ONLY sync + read surfaces):** (1) SQLite migration adding the two §1 tables (check the current schema-version max and bump — never reuse a version); (2) full-sync of `location_nodes` per active location + delta-sync loop for `product_placements` per §2.1 (strict tuple `>` cursor, `<=` high-water-mark, tombstone application), wired into the existing sync scheduler alongside catalog/stock; (3) timestamp normalization per §4 (`toSqliteUtc` equivalent) — this is the #1 historical bug class, test it explicitly with a same-day boundary row; (4) read surfaces: product detail shows its placement `path` per location; counting/browse can filter by subtree using the §3 `= path OR LIKE path/%` form (test `A1` vs `A10`).
- **NOT in scope:** any placement WRITE from the device (outbox) — blocked on §7's owner decision; the web write endpoints; inventing quantity-per-node anywhere (nodes NEVER carry stock).
- **Checkpoints:** hard-stop gate after (a) schema+sync plumbing, (b) read surfaces — each gate = `claude -p --model claude-opus-4-8` adversarial review of the diff against THIS document (§1 shapes, §2.1 comparisons, §4 timestamps), verdict file committed in the mobile repo, CHANGES-REQUIRED → fix test-first → re-run. No Fable escalation expected (no money/quantity math in scope).
- **Tests:** delta boundary (row updated exactly at the watermark is caught next run), tombstone removal, timestamp round-trip, subtree filter collision, offline cold-start with populated tables.
- Verification per that repo's standard preflight; leave the branch for the ERP-side session's final review before merge.

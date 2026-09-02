# Inventory counting — cross-layer evidence (API · web · POS · mobile), 2026-09-02

**Question asked:** is the mobile app (erp-mobile `d5ebf9e`, 2026-08-03) still aligned with the ERP for the inventory flow, and does the counting flow work with and without sales blocking on API, web and mobile?
**Environment:** ERP worktree `.worktrees/n-inventory-mobile` at `dev 3615cab8f`, API on 127.0.0.1:8016, db-per-tenant PG 5433, demo tenant PharmaBio (`owner@pharmabio.tn`), company `01a04e72-6090-710e-891d-e73ea3483ce8`, location STORE-SFA `9540970b-3006-4f2e-9b5d-bf5c9c913412`, terminal POS01 `f6eedc50-f045-4837-8f35-cc9e13b18cf5`. Queue is `sync` so the finalize listener runs inline. All requests carried `X-Company-Id` and a Bearer token exactly like the mobile client.

## 1. Live probes

### 1.1 Sales-blocked counting (`block_sales=true`, scope product_location, 2 products, count_1 = owner, requires_count_2=false) — CNT-2026-0001
| Step | Request | Result |
|---|---|---|
| create | `POST /inventory/countings` | 201, `block_sales:true`, `ambiguity_window_minutes:15` |
| activate | `POST …/activate` | 200 → `count_1_in_progress` |
| my-tasks | `GET …/my-tasks` | 200, 1 task, `progress.count_1 {0/2}` |
| counter-view | `GET …/counter-view` | 200, banner data `block_sales:true`, 2 items, no theoretical qty |
| items/to-count | `GET …/items/to-count?uncounted_only=false` | 200, same items, no `my_count_at` |
| lookup hit / miss | `GET …/lookup?barcode=6190000008688` / `…=0000000000000` | 200 `{found:true,data}` / 404 `{found:false,message}` |
| **terminal payload during block** | `GET /pos/terminals/{POS01}` | `active_counting_block:{counting_id, counting_number:"CNT-2026-0001", started_at}` — POS gate input present |
| count item 1 | `POST …/items/{item}/count {quantity:"6", idempotency_key, counted_at_device, device_now}` | 200 |
| replay same payload | same | 200 (progress derived from rows; no double count) |
| **count "6.12345"** | same with 5 decimals | **200 — stored `6.1234` (silent truncation, finding A-1)** |
| count `6.5` as JSON number | | 200, response echoes `6.5` (number) |
| count item 2 exact | `"13"` | 200 → status auto-moved to **`pending_review`**; my-tasks now `[]` |
| finalize with variance unresolved | `POST …/finalize {}` | 422 `BUSINESS_ERROR` "Cannot finalize: 2 items still pending resolution." (before counting item 2) |
| reconciliation | `GET …/reconciliation` | 200, `terminal_sync_health.requires_acknowledgement:true`, 17 terminals at STORE-SFA all `state:"unknown"` (never reported) |
| override item 1 → 6 | `POST /inventory/countings/items/{item}/override {quantity:"6", notes}` | 200 |
| finalize without ack | `POST …/finalize {}` | **422 `TERMINAL_SYNC_ACKNOWLEDGEMENT_REQUIRED`** + `terminal_sync_health{acknowledgement_signature}` |
| finalize with ack | `{acknowledge_terminal_sync_risk:true, terminal_sync_health_signature}` | 200 → `finalized` |
| stock after | DB | PB-BAB-0018 7 → **6** (`adjustment −1`, reference inventory_counting), PB-BAB-0024 13 unchanged |
| terminal payload after | `GET /pos/terminals/{POS01}` | `active_counting_block: null` — block released |
| report | `GET …/report` | `items_applied:1, items_not_applied:0, late_sales_corrections:0, net −10.287 TND` |

### 1.2 Live counting (`block_sales=false`, 1 product, movement DURING the count)
| Step | Result |
|---|---|
| create + activate | 201/200, `block_sales:false` |
| terminal payload | `active_counting_block: null` (sales continue) |
| count 14 (= theoretical) | 200 |
| stock transfer 2 units STORE-SFA → WH-01, complete | 201/200, stock 14 → 12 (`transfer_out`) |
| reconciliation | `replay_preview {mode:"timestamp_replay", movements_since_count:"-2.0000", expected_now:"12.0000", adjustment:"0.0000", will_auto_post:true}`, `flag_reasons:null`, `late_sales_flags:[]` |
| finalize (with ack) | 200 → `finalized`; **no adjustment movement posted**, stock stays 12 — the mid-count movement was neutralised by the timestamp replay (correct) |

### 1.3 Mobile-initiated draft path (as the mobile client sends it)
| Step | Result |
|---|---|
| `POST /inventory/countings/drafts {scope_type:"product_location", scope_filters:{product_ids:[]}, created_on_mobile:true, title}` | 201; response has no `title` |
| `POST …/add-product {barcode}` | 201; same barcode again → **409 `{"error":"Product already added to this count"}`** (bare string); unknown barcode → 404 `{"error":"Product not found with barcode: …"}` |
| `POST …/add-products/batch` | 200 with typed `errors[].code` `PRODUCT_ALREADY_IN_COUNT` / `PRODUCT_NOT_FOUND` |
| `GET …/my-drafts` | `last_modified_at:"2026-09-02 10:43:58+00"` (not ISO-8601 — finding A-11) |
| `PATCH …/draft {count_1_user_id, requires_count_2:false, execution_mode}` | 200 |
| `POST …/activate-draft` | 200 → `count_1_in_progress`; **counter-view listed 7 items across STORE-SFA, WH-01, STORE-TUN1, STORE-SOU** for a "product_location" counting with no `location_id` (finding A-3 / M-3) |
| cancel | 200 |

### 1.4 Other mobile endpoints (GET, owner token)
`/user/companies` 200 · `/locations` 200 · `/inventory/zones` **404 (route gone since 2026-07-07)** · `/inventory/locations/{id}/nodes` 200 · `/inventory/onboarding-worklist?location_id=` 200 · `/inventory/countings/my-tasks` 200 · `/purchase-orders?status=confirmed` 200 · `/purchase-orders/{id}` 200 (lines carry `quantity_received`) · `/purchase-orders/{id}/receipt-status` 200 (+ free_quantity_* fields) · `POST /purchase-orders/{id}/receive {quantities}` 200 with `meta.receipt_status` · `/line-entry/resolve-code?code=` 200 but **wrapped in `data` (mobile reads the root → scan never resolves)** · `/expenses` 200 · `/expense-categories` 200 · `/dashboard/stats` 200 · `/inventory/placements?location_id=` 200.

### 1.5 Validation probes
`POST /inventory/countings` zone scope with `block_sales:true` → 422 VALIDATION_ERROR `block_sales: "Sales blocking is not supported for zone-scoped counts"` (correct).

## 2. Findings register
| # | Layer | Sev | Finding | Owner / lane |
|---|---|---|---|---|
| A-1 | API | P1 | `SubmitCountRequest` quantity has no scale-4 ceiling: `6.12345` stored as `6.1234`; `1e3` would 500 (sibling `ManualOverrideRequest` fixed 2026-08-25) | ERP lane N-1 |
| A-3 | API | P0 | `product_location` drafts accept/activate without `location_id` and count every location; escapes `CountingBlockService::scopeCoversLocation` and the per-location terminal-sync gate | ERP lane N-1 (+ mobile M-3) |
| A-8 | Web | P1 | `block_sales` / `ambiguity_window_minutes` invisible after creation (detail page, review step, list); FE type omits them — a tester cannot confirm the mode from the UI | ERP lane N-1 |
| A-11 | API | P2 | `my-drafts.last_modified_at` raw `Y-m-d H:i:s+00` | ERP lane N-1 |
| A-5 | API | P3 | `idempotency_key` not a server field; replay is harmless (progress derived from rows) but the mobile's documented "dedupe by key" obligation is not honoured | documented, no change |
| A-6 | API | P3 | `abort(403,'You are not assigned…')` message discarded → generic FORBIDDEN; draft endpoints use bare-string `{"error":…}`; lookup miss uses `{found,message}` | documented (mobile M-5 handles all three) |
| A-7 | API/POS | info | Finalize requires terminal-sync acknowledgement whenever any terminal at the location is `unknown`/`stale`/`pending`; on a fresh tenant with a claimed Tauri terminal the POS reports health every sync tick, otherwise the reviewer must tick the acknowledgement — by design | tester note |
| A-9 | POS | design | The block gates add-to-cart (`stockGate.ts`) not checkout; a cart built before the block tenders through and becomes a `late_sales_flags` entry; no persistent banner, toast only; polling lag ≤60 s (≤5 min under backoff) | tester note |
| A-10 | Web | P2 | Web create wizard never sends `include_zero_stock` (defaults from the location's onboarding mode) | backlog |
| M-1 | Mobile | P0 | `GET /inventory/zones` 404 → zone drafts + zone label broken | Codex mobile handover |
| M-2 | Mobile | P1 | `warehouse` scope not a server enum value; `zone` label missing | Codex mobile handover |
| M-3 | Mobile | P0 | product_location drafts sent without `location_id` | Codex mobile handover |
| M-4 | Mobile | P1 | no lifecycle awareness (`status` unread; `COUNTING_TRANSITION_REFUSED` unhandled; parked rows forever) | Codex mobile handover |
| M-5 | Mobile | P1 | error envelope parsing misses bare-string and validation-bag shapes; draft screen shows raw axios text | Codex mobile handover |
| M-6 | Mobile | P0 | receiving `resolve-code` read at the root instead of `data` → barcode scan never resolves (since day one) | Codex mobile handover |
| M-7 | Mobile | P2 | Android manual entry `Alert.prompt` no-op | Codex mobile handover |

## 3. What works (no action)
Both counting modes work end to end on the API: blocking count → POS terminal payload carries the block → count → auto `pending_review` → override → finalize (with sync acknowledgement) → stock adjustment posted → block released; live count → mid-count movement neutralised by timestamp replay → nothing over-posted. The mobile's counter-view contract (blind items, banner from `block_sales`/`ambiguity_window_minutes`, quantity strings, device timestamps) matches the server byte for byte; `InventoryCountingController` has had zero commits since the mobile's sync point, so no mobile-facing response shape drifted after 2026-08-03 — the mobile defects above predate it.

## 4. Sources
Agent maps (API contract, mobile client, web/POS enforcement) reconciled against the live probes above; server spec `docs/superpowers/specs/2026-07-06-live-inventory-counting-design.md`; stale handover `docs/handoff/HANDOVER-live-counting-mobile.md` (still documents `/inventory/zones`).

## 5. Promotion census — location-less `product_location` / `zone` drafts

Lane N-1 makes `scope_filters.location_id` mandatory for `product_location` and `zone`
countings at every write boundary, and refuses activation without one. Rows created before
those guards can therefore be **stranded**: `activateDraft` refuses them forever and no
endpoint can repair `scope_filters` (it is written wholesale at creation only —
`UpdateDraftCountingRequest` has no such field and `batchUpdateDrafts` touches only
title/instructions/mode/flags/users/dates). The exit for a stranded row is cancel + re-create.

**Run this before promoting to any environment.** It is read-only, and must be run per
TENANT database (db-per-tenant):

```sql
-- Stranded rows: DRAFT product_location/zone countings with no location.
SELECT
    current_database()                        AS tenant_db,
    scope_type,
    count(*)                                  AS location_less_drafts,
    min(created_at)                           AS oldest,
    max(created_at)                           AS newest,
    count(*) FILTER (WHERE created_on_mobile) AS created_on_mobile
FROM inventory_countings
WHERE status = 'draft'
  AND scope_type IN ('product_location', 'zone')
  AND coalesce(scope_filters->>'location_id', '') = ''
GROUP BY scope_type
ORDER BY scope_type;

-- Context (any status), to size the class rather than only the stranded part:
SELECT current_database(), scope_type, status, count(*)
FROM inventory_countings
WHERE scope_type IN ('product_location', 'zone')
  AND coalesce(scope_filters->>'location_id', '') = ''
GROUP BY 1, 2, 3;
```

`scope_filters` is `jsonb` (`database/migrations/tenant/2025_12_02_070000_create_inventory_countings_table.php:28`),
so `->>` is the correct operator; `status` is a string column whose `draft` value matches
`CountingStatus::Draft`.

Driver note: iterate the databases with `while IFS= read -r db`, not `for db in $DBS` — under
zsh an unquoted parameter is **not** word-split, so the loop runs once with the whole blob as
one name, every `psql` fails, and the census reports a false all-clear. Always print a control
total (databases actually reached, total countings) alongside the result.

### Results

| Run | Date | Scope | DBs reached | Countings | Location-less DRAFTS (stranded) |
|---|---|---|---|---|---|
| Local (lane, `LIKE 'tenant%'`) | 2026-09-02 | local PG 16 `127.0.0.1:5433` | 239 | 40 | **0** |
| Local (gate r2 reviewer, every non-template DB with the table) | 2026-09-02 | same instance | 303 | 79 | **0** |
| **Staging** (coordinator) | 2026-09-02 | staging PG | 16 | 0 | **0** |

The two local runs differ only in database selection (`tenant%` prefix vs every non-template
database carrying `public.inventory_countings`); both agree on the answer. Across all three
runs the only location-less row anywhere is a single `product_location` counting in
`tenant01a04e72-3947-…` which is already `cancelled` (`bea1f946-…`, CNT-2026-0002,
`created_on_mobile = t`) — the original mobile reproduction this lane was opened on. **Nothing
is stranded; no backfill or repair endpoint is required for promotion.** Re-run on production
before the guards ship there.

### Documented residuals (not fixed in N-1)

- **Two error envelopes on the activation route.** `activate-draft` answers the controller
  guards as a bare `{"error":"A location must be selected before activation"}` (matching its
  sibling draft endpoints) but the zero-item refusal as
  `{"error":{"code":"BUSINESS_ERROR","message":…}}` (the generic `DomainException` renderer,
  `bootstrap/app.php:1022-1031`). The mobile client must parse both shapes from one route.
  Unifying them is a cross-endpoint contract change (every draft endpoint uses the bare shape),
  so it is deliberately out of this lane. Recorded in the mobile brief.
- **`scope_filters.zone_ids` is unvalidated on the draft path.** `CreateCountingRequest:55-60`
  validates zone ids (soft-delete-aware, pinned to the location); `CreateDraftCountingRequest`
  does not, so a mobile zone draft may carry arbitrary ids. No leak: `zoneItemSeeds` pins them
  to the company-checked location (`InventoryCountingService.php:326-334`), so a bad id simply
  resolves to nothing — which now 422s at activation instead of producing an empty live count.
  Worth closing for symmetry in a follow-up.
- **Uniform per-row refusal on the batch endpoint.** A missing/foreign/malformed
  `location_id` is refused per row into `errors[]`, but an unknown `scopeType` still 422s the
  whole batch (ruled that way in r1). `CountingScopeType::tryFrom(...) === null → errors[] +
  continue` would make the endpoint's contract uniform; the mobile brief mitigates it by
  contract ("never enqueue an unknown scope type").

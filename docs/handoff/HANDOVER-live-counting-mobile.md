# Handover: Live Inventory Counting — Mobile (erp-mobile)

**Date:** 2026-07-07
**Server branch:** `feat/live-inventory-counting` (`apps/erp.live-counting`)
**Mobile repo:** `erp-mobile` (separate repo, ships separately — owner pushes it personally)
**Design doc:** [`docs/superpowers/specs/2026-07-06-live-inventory-counting-design.md`](../superpowers/specs/2026-07-06-live-inventory-counting-design.md)

Every contract below was verified against the landed code in `apps/api/app/Modules/Inventory/` and `apps/api/app/Modules/POS/Presentation/Resources/TerminalResource.php` on this branch — not against the plan. File:line citations are given so mobile can re-verify independently.

---

## 1. Feature summary

The counting subsystem now supports counting **while the shop keeps selling**, in addition to the existing stop-and-count workflow. Every count session gets a `block_sales` flag (default off) chosen at creation: **blocking** sessions push a hard stop to the POS device for the scoped location(s) before finalize, so the shelf is frozen while it's counted; **live** sessions let sales continue and reconcile the difference at finalize by replaying every stock movement that happened between "when the line was counted" and "now" (`expected_now = counted_qty − outflows_after(T) + inflows_after(T)`). Zone-scoped counts (new `location_zones` / `product_zone_assignments` tables) are always live — they show a **soft advisory banner** on the POS, never a hard block, because a sale doesn't declare which shelf the item came from. A basket-window guard (default ±15 minutes around the count instant) flags lines with movements too close to the count timestamp for auto-replay, sending them to manual review instead.

A new **onboarding mode** exists per location for brand-new clients who cannot close to stocktake: while it's on, the effective POS stock policy is forced to "off" (negative stock allowed), and the *first* count of a product at that location posts as an **opening balance** (with cost, from `products.cost_price` or a review-page backfill) rather than a shrinkage variance. Zones are pure labels for count scoping and shelf placement — they never touch `stock_movements` or `stock_levels`, which stay at `(product, location)` grain. Zone assignment happens three ways in v1: scanning a product during a zone-scoped count (assign-as-you-count), a web bulk-assign screen, and a `zone` column on product import.

---

## 2. API contract deltas (verified against code)

### 2.1 Zones — new resource

`GET /inventory/zones?location_id={uuid}` (`ZoneController::index`, `.../Presentation/Controllers/ZoneController.php:31`) — **this is the zone list source for the mobile zone picker.** `location_id` is required and must belong to the caller's company (404 otherwise); a non-UUID `location_id` returns `422` with `{"error":{"code":"ZONE_INVALID_LOCATION",...}}`. Response is `ZoneDto[]`, ordered by `sort_order`, then `name`:

```json
// GET /inventory/zones?location_id=8f2b...
{
  "data": [
    {
      "id": "b1c2...",
      "location_id": "8f2b...",
      "name": "Cosmetics wall",
      "code": "COS-01",
      "sort_order": 0,
      "is_active": true,
      "created_at": "2026-07-01T09:00:00+00:00",
      "updated_at": "2026-07-01T09:00:00+00:00"
    }
  ]
}
```

There is **no single-zone-by-id GET**. To resolve a zone name from a `zone_id` you already have (e.g. from `scope_filters.zone_ids[0]`), call this same endpoint with the session's `scope_filters.location_id` and match by id client-side.

### 2.2 Counting creation — `scope_type: 'zone'`

Two request shapes exist and they are **not symmetric** — this matters for the mobile flow:

- **`CreateCountingRequest`** (`POST /inventory/countings`, `Presentation/Requests/CreateCountingRequest.php:37`) — the strict, manager/web path. For `scope_type: 'zone'` it requires `scope_filters.zone_ids` (array of zone UUIDs) **and** `scope_filters.location_id`, validates every zone belongs to that location, and **rejects `block_sales: true` for zone scope** with a 422 (`"Sales blocking is not supported for zone-scoped counts"`, line 139) — zone counts are always soft-advisory. Also accepts `include_zero_stock` (bool) and `ambiguity_window_minutes` (int, 0–1440, default 15 when omitted).
- **`CreateDraftCountingRequest`** (`POST /inventory/countings/drafts`, `Presentation/Requests/CreateDraftCountingRequest.php:41`) — the mobile-initiated draft path used by `create-draft.tsx`. **It does not validate or even read `zone_ids`, `location_id`, `block_sales`, `ambiguity_window_minutes`, or `include_zero_stock` as named fields.** Its `rules()` only validates `scope_filters.product_ids`; the controller (`InventoryCountingController::createDraft`, line 537) copies whatever `scope_filters` array the client sent verbatim onto the model (`$counting->scope_filters = $request->input('scope_filters', [])`, line 558) and never reads `block_sales`/`ambiguity_window_minutes`/`include_zero_stock` at all — those top-level fields are silently dropped if sent to this endpoint. **Consequence for mobile:** to create a zone-scoped draft, send `scope_type: 'zone'` and `scope_filters: { location_id, zone_ids: [...] }` (freeform, unvalidated at this stage) — do **not** expect `block_sales`/`ambiguity_window_minutes` to be settable from the draft screen; they aren't applicable to zone scope anyway (see previous bullet), so this is a non-issue in practice.

Example draft-creation payload for the new zone picker:

```json
// POST /inventory/countings/drafts
{
  "scope_type": "zone",
  "title": "Cosmetics wall — weekly",
  "created_on_mobile": true,
  "scope_filters": {
    "location_id": "8f2b...",
    "zone_ids": ["b1c2..."]
  }
}
```

**Known backend gap — flag to backend before shipping the zone picker:** `InventoryCountingController::activateDraft` (line 802) unconditionally requires `scope_filters.product_ids` to be non-empty before it will activate *any* draft:

```php
$productIds = $counting->scope_filters['product_ids'] ?? [];
if (count($productIds) === 0) {
    return response()->json(['error' => 'At least one product must be added before activation'], 422);
}
```

But for `scope_type: 'zone'`, item generation never reads `product_ids` — it pulls seeds from `product_zone_assignments` (`InventoryCountingService::zoneItemSeeds`, `Application/Services/InventoryCountingService.php:301`). A zone-scoped draft built with only `location_id`/`zone_ids` (no `product_ids`) will **always be rejected at activation** with `422 "At least one product must be added before activation"`, even though it has valid zone-assigned products server-side. This is a genuine gap in the landed code, not a design decision — the mobile zone-draft → activate flow is blocked on a backend fix (loosen the check to skip the `product_ids` requirement for `scope_type === 'zone'`, or check for at least one zone-assigned product instead). **Do not build around this in the mobile app; get it fixed backend-side first**, otherwise QA will see every zone draft fail to activate.

Also note: the counting session payloads mobile reads (`transformCounting()`, used by `show`/`counter-view`/`index`/`dashboard`/`my-tasks`) **do not include `block_sales`, `ambiguity_window_minutes`, or `includes_zero_stock`** at all (checked — those keys are absent from every response array in `InventoryCountingController`). `scope_filters` (raw, including `zone_ids`/`location_id`) IS included in the full `show`/`index`/`dashboard` payload, but **not** in `counter-view` (the blind counter view mobile actually polls — see §3).

### 2.3 Count submission — `counted_at_device` + `device_now`

`POST /inventory/countings/{counting}/items/{item}/count` (single endpoint, `CountingItemController::submitCount`, line 80) via `SubmitCountRequest` (`Presentation/Requests/SubmitCountRequest.php`):

| Field | Required | Format |
|---|---|---|
| `quantity` | required | numeric string, ≥0 (scale-4 canonicalized server-side) |
| `notes` | optional | string, max 500 |
| `counted_at_device` | optional | strict ISO-8601: `\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})` — e.g. `2026-07-06T10:00:00Z` or `2026-07-06T10:00:00+02:00` |
| `device_now` | optional | same ISO-8601 format |

**Both fields are independently `nullable`, but sending only one is actively harmful — send both together or neither.** Verified in `InventoryCountingItem::submitCount` (`Domain/InventoryCountingItem.php:266`):

- **Neither sent** → server-stamped: `count_N_at_estimate = serverNow`. No skew correction. Exactly today's behavior.
- **Both sent** → `skewSeconds = serverNow - device_now`; `count_N_at_estimate = counted_at_device + skewSeconds` (correcting the device clock's drift using its own self-reported "now" at submit time). If `abs(skewSeconds) > 300` (5 minutes, `CLOCK_SKEW_TOLERANCE_SECONDS`, line 71) the item is flagged `clock_skew` for manual review at finalize.
- **Only `counted_at_device` sent (no `device_now`)** → treated as an **unverifiable claim**: the server cannot compute skew, so it falls back to `count_N_at_estimate = serverNow` **and unconditionally flags the item for review** (line 298), which is *worse* than sending neither. This is the trap: do not attach `counted_at_device` alone.

Example online submission with skew correction:

```json
// POST /inventory/countings/{counting}/items/{item}/count
{
  "quantity": "12.0000",
  "notes": null,
  "counted_at_device": "2026-07-06T10:00:00Z",
  "device_now": "2026-07-06T10:04:30Z"
}
```

If the server clock reads `2026-07-06T10:05:00Z` at receipt, skew = 30s, well within tolerance; `count_1_at_estimate` = `10:00:30Z`.

Backward compatibility: this is fully additive on the single existing endpoint. An older mobile build that never sends these two fields keeps working exactly as before — server-stamped, no skew correction, no new flags.

### 2.4 Onboarding worklist

`GET /inventory/onboarding-worklist?location_id={uuid}&per_page={n}` (`InventoryCountingController::onboardingWorklist`, line 44). Requires `inventory.view`. `location_id` required UUID (plain Laravel `Validator`, not a FormRequest — 422 with standard `{"errors":{"location_id":[...]}}` shape on failure, **not** the `{"error":{"code":...}}` envelope used elsewhere in this module — worth noting if mobile has a shared error-envelope parser). Returns active products at the location with negative or missing stock that haven't yet been counted in any non-draft/non-cancelled session, paginated (`per_page` clamped 1–100, default 15):

```json
// GET /inventory/onboarding-worklist?location_id=8f2b...&per_page=20
{
  "data": [
    {
      "product_id": "a3d1...",
      "name": "Vitamin C Serum 30ml",
      "sku": "VCS-30",
      "on_hand": "-4.0000",
      "last_sold_at": "2026-07-05T14:22:10+00:00"
    }
  ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 20, "total": 57 }
}
```

Good candidate for an optional "count these next" mobile screen (out of scope for this task per the brief, but the endpoint is ready if picked up later).

### 2.5 Opening-cost backfill (D3)

`PATCH /inventory/countings/{counting}/items/{item}/opening-cost` (`CountingItemController::setOpeningCost`, line 258), `can:inventory.adjust`, both route params UUID-guarded. Body: `{"unit_cost": "12.500000"}` — string, `numeric`, regex `^\d+(\.\d{1,6})?$` (6 fractional digits, cost scale, **not** currency-scaled). Mobile never calls this (per design §5, "mobile never asks for cost") — it's a web review-page-only endpoint, listed here only so mobile doesn't accidentally wire a screen to it.

### 2.6 Terminal payload (`TerminalResource`) — not consumed by the mobile counting app

`active_counting_block` and `counting_zone_advisories` (`TerminalResource.php:66-87`) are served on the **POS terminal's** own sync payload, not on any endpoint the `erp-mobile` counting app calls. They're documented here only for completeness / in case a future mobile screen needs the same POS-side blocking/advisory state — the mobile counting app's own blocking/advisory banner must instead be derived from the counting session it's actively working on (see §3), since the counting-session JSON is the only zone/block-relevant payload mobile touches today.

---

## 3. Mobile work items (per screen/file)

All paths relative to `erp-mobile/`. Current state was read directly from the repo (read-only — nothing here was modified).

### `app/(app)/counting/create-draft.tsx` + `src/features/counting/store/draftCountingStore.ts`
- `DraftCounting.scopeType` union (`draftCountingStore.ts:20`) has no `'zone'` member; `SCOPE_TYPES` in `create-draft.tsx:23-58` has no zone entry. Add `'zone'` to both, plus new `DraftCounting` fields `locationId?: string` and `zoneIds?: string[]` (there is currently no `scope_filters` concept in the store at all — only `productIds: string[]`).
- Zone list source: `GET /inventory/zones?location_id=` (§2.1) once a location is picked — add a location picker step ahead of the zone picker (there's no location list in this screen today).
- `src/features/counting/services/draftSyncService.ts:129` hardcodes `scopeFilters: { product_ids: draft.productIds }` in `syncNewDrafts()` — this must branch on `scopeType === 'zone'` and instead send `{ location_id: draft.locationId, zone_ids: draft.zoneIds }` (see §2.2 payload shape). `src/features/counting/api/countingApi.ts:143-149` (`createDraft`, non-batch path) has the same hardcoding for the online-create path and needs the same branch.
- **Do not ship this until the `activateDraft` product_ids gap (§2.2) is fixed backend-side** — otherwise every zone draft a counter builds will fail to activate.

### `app/(app)/counting/[id]/index.tsx` (session header)
- `CountingSession` type (`src/features/counting/api/countingApi.ts:56-69`, sourced from `GET /inventory/countings/{id}/counter-view`) currently exposes only `counting.{id,status,instructions,deadline}` — no zone/location/scope info at all. The counter-view endpoint (`InventoryCountingController::counterView`, line 268) does not currently surface `scope_type`/`scope_filters`, so **a zone label cannot be added to this header from data already being fetched** — either request a small backend addition (surface `scope_type` + resolved zone name(s) on `counter-view`), or have mobile separately fetch the full session (`GET /inventory/countings/{id}`, which does return `scope_filters`) and cross-reference `GET /inventory/zones?location_id=` to resolve names. Prefer asking backend to add the fields to `counter-view` — it's the endpoint already being polled.

### `src/features/counting/hooks/useSubmitCount.ts` (online path) + `src/features/counting/hooks/useBackgroundSync.ts` (offline-drain path)
This is the critical one. Today `useSubmitCount.ts:32-35` calls `countingApi.submitCount(..., { quantity, notes })` with no timestamp fields, and the offline queue (`src/features/counting/store/countingStore.ts`) stores a `PendingCount.countedAt: string` set via `new Date().toISOString()` at `addPendingCount()` time (`countingStore.ts:48`) — i.e. **at the moment the count is physically made**, whether online or offline. `useBackgroundSync.ts:48-51` drains the queue later and also calls `submitCount` with only `{ quantity, notes }`.

Required change, both call sites:
- **Online path** (`useSubmitCount.ts`): capture `const now = new Date().toISOString()` immediately before the `countingApi.submitCount` call and send it as **both** `counted_at_device` and `device_now` (they're the same instant — the device just counted it, and the device's own clock is "now"). Skew will compute to ~0 for a healthy clock, non-zero for a genuinely skewed one — correct either way.
- **Offline-queued path** — the one that actually matters: `PendingCount.countedAt` (the device-clock instant captured at `addPendingCount()` time) must be sent as `counted_at_device`, **unchanged**, no matter how long the item sits in the queue. `device_now` must be captured **fresh, at drain time** (`new Date().toISOString()` inside `useBackgroundSync.ts`'s `syncPendingCounts()`, right before each `countingApi.submitCount` call), not reused from `countedAt`.

  Why it must be two different reads: `skewSeconds = serverNow − device_now` measures the device clock's offset from true time **as of the drain moment**, and that same offset is applied to correct `counted_at_device` (`counted_at_device + skewSeconds`). If the item was counted at `T0` offline and only synced at `T1` (`T1 > T0`, possibly hours later), the device clock's drift can itself have changed between `T0` and `T1` (NTP resync, manual clock fix, more accumulated drift) — reusing the `T0` reading as `device_now` would apply a stale correction and silently mis-estimate `counted_at_server_estimate`, defeating the entire replay mechanism the corrected timestamp feeds into (`expected_now = counted_qty − outflows_after(T) + inflows_after(T)`, where `T` = `counted_at_server_estimate`). The two-timestamp design exists precisely so skew is measured at the instant it's used to correct, not at the instant the count was made.
- `countingApi.submitCount()` itself (`api/countingApi.ts:104-116`) needs its `payload` type extended with optional `countedAtDevice?: string; deviceNow?: string`, mapped to the snake_case body fields (`counted_at_device`, `device_now`).
- Both fields are optional — if either hook fails to attach them (e.g. clock unavailable), omit both rather than sending one alone (see §2.3 trap).

### Blocking/advisory banners
- Verified data source: the counting-session responses mobile already fetches contain no `block_sales` field (§2.2, confirmed absent from `transformCounting()` output) — this is a real gap, not a mobile oversight. Since zone-scoped counts (the only kind mobile creates) can never have `block_sales: true` anyway (§2.2, rejected by validation), a **zone advisory banner** is the only banner mobile realistically needs for its own zone-scoped sessions, and even that requires the same backend surfacing discussed above (scope_type + zone name on `counter-view`, or a client-side cross-reference via `GET /inventory/zones`). A **blocking banner** for a location-wide/full-inventory block created on web is a POS-device concern (`TerminalResource`, §2.6), not something the mobile counting app needs to render for its own sessions.

### Optional: onboarding worklist screen
`GET /inventory/onboarding-worklist` (§2.4) is fully built and ready; a "count these next" screen was explicitly out of scope for this task per the brief — flagged here only so it's known to be low-effort to pick up later (endpoint exists, paginated, includes `on_hand` and `last_sold_at`).

---

## 4. Recommended test cases

1. **Offline count with skewed device clock, end-to-end:**
   - Set the test device's clock 10 minutes fast (exceeds the 300s tolerance).
   - Go offline (airplane mode). Submit a count for a known item — verify `PendingCount.countedAt` is captured at this moment.
   - Wait/advance a few minutes (simulating time in the offline queue), then correct the device clock back to accurate time.
   - Go online. Verify the background sync drain sends `counted_at_device` = the original offline-capture timestamp (unchanged) and `device_now` = a *fresh* timestamp read at drain time (now showing accurate time, not skewed).
   - Assert server-side: `count_N_at_estimate` should reflect the **skew that existed at count time** (10 min fast), not zero — i.e. the correction uses the drift measured relative to whatever `device_now` reports at drain, so if the clock was fixed before draining, the server has no way to know the count was originally mis-timed by the device unless `device_now` is captured at count time too. **This is worth an explicit design conversation with backend/QA before writing the automated test**: the current one-shot `device_now`-at-drain model corrects for drift *at drain time*, not drift *at count time* if the two diverge (e.g., clock manually fixed while offline). Confirm expected behavior against `InventoryCountingItem::submitCount` (`Domain/InventoryCountingItem.php:266`) before asserting a specific estimate in the test.
   - Simpler, unambiguous variant: keep the clock's skew constant (don't "fix" it mid-test) — fast by a fixed +N minutes throughout — and assert `count_N_at_estimate ≈ counted_at_device` corrected by that constant skew, and that no `clock_skew` flag is raised when `abs(skew) ≤ 300s`, and that it IS raised when `abs(skew) > 300s`.
2. **Only `counted_at_device` sent, no `device_now`:** confirm the item gets flagged `clock_skew` (§2.3) — a regression test to make sure mobile never regresses into sending one without the other.
3. **Sale-during-count replay verification:**
   - Create a zone-scoped count (once the activation gap in §2.2 is fixed) covering a zone with 1+ assigned products with known starting stock.
   - Ring a POS sale for one of the zone's products *before* it's counted in the session — verify the counted quantity, once submitted, reflects the shelf as physically counted (sale already reflected in the physical count, no double-deduction).
   - Ring a second POS sale for the *same product* *after* it's been counted but before finalize — verify at finalize the on-hand ends up `expected_now = counted_qty − qty_sold_after_count`, not `counted_qty` unchanged (i.e., the second sale replays correctly on top).
   - Ring a sale within the ambiguity window (±15 min default) of the count instant — verify the line is flagged for manual recount rather than auto-applied.
4. **Zone picker + create-draft happy path:** once §2.2's activation gap is fixed, full round trip — pick location, pick zone(s), create draft, activate, count an assigned item, finalize, and confirm on-hand changes as expected (this exercises the assign-as-you-count upsert into `product_zone_assignments` too, per `InventoryCountingService::assignCountedItemToZone`).

---

## 5. Backward compatibility

**The server is fully backward-compatible with the current `erp-mobile` build as it exists on `main`/whatever is currently shipped.** No mobile changes are required for the server-side work in this branch to be safe to deploy:
- `counted_at_device` / `device_now` are both `nullable` on `SubmitCountRequest` — an unmodified mobile build that never sends them gets pure server-stamped timestamps and no skew correction, exactly as before.
- The zone scope type, `block_sales`, `ambiguity_window_minutes`, and onboarding mode are all additive — existing `product`/`product_location`/`location`/`category`/`full_inventory` scope flows are untouched.
- Existing mobile draft-creation, add-product, and batch-sync flows (product-only scope) are unaffected — nothing about their request/response shape changed.

**Owner pushes `erp-mobile` personally** — this handover is documentation and analysis only; no commits were made to the `erp-mobile` repo as part of this task.

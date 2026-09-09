# T-1 transfers / requests edge campaign — 2026-09-09

Status: review. Branch `lane/t1-transfers-edge`, worktree `.worktrees/t1-transfers`, base local dev `63e0e5e16`. Authority: [dispatch](../../handoff/CODEX-DISPATCH-T1-transfers-requests-edge-campaign-2026-09-09.md), [case brief](../../handoff/BRIEF-lane-T1-transfers-requests-edge-campaign-2026-09-08.md), [parent benchmark §0–§1](../../handoff/BRIEF-parallel-transfers-blind-receiving-2026-09-08.md).

The only production change is the source-access validation rule in `apps/api/app/Modules/Replenishment/Presentation/Requests/CreateTransferFromRequestsRequest.php`: 12 added / 2 removed lines, constructor-injected existing ValidLocationAccess, within the ≤20-line allowance. `StockTransferService.php` and all counting files are untouched.

## Case matrix

Method names below are exact, prefixed with `test_` in the source. S = StockTransferEdgeCasesTest, P = StockTransferCompleteConcurrencyPostgresTest, R = ReplenishmentEdgeCasesTest, L = existing StockTransferLocationScopeTest. “First” records actual first execution, including harness mistakes instead of disguising them as product defects. Replenishment's initial fixture boot used the nonexistent `replenishment.request` permission and errored before any case ran; it was corrected to `replenishment.create` before the behavior colours below.

| Case | Test method(s), without `test_` prefix | First → verified | What it pins / brief benchmark | Ticket |
|---|---|---|---|---|
| 1 | S.complete_twice_returns_typed_422_and_receives_each_line_once | RED assertion typo → GREEN | Two product lines, complete twice, typed `INVALID_TRANSFER_STATE` 422, one transfer-in and exact destination quantity per line. First assertion mistakenly spelled the existing code `TRANSFER_STATE_INVALID`; corrected without production change. Brief case 1 idempotent completion. | — |
| 2 | P.concurrent_complete_waits_for_row_lock_then_refuses_without_duplicate_stock | RED observer harness → GREEN PG | Real independent PHP process is observed waiting on a PG lock while winner remains uncommitted; then gets typed Completed/complete exception after commit; source 6, destination 4, one receipt. Added `pg_stat_clear_snapshot()` to refresh transaction-local statistics snapshot; no production fix. Brief case 2 FOR UPDATE. | — |
| 3 | S.terminal_transitions_are_refused_without_new_movements | RED assertion typo → GREEN | Complete→cancel and cancel→complete both 422, unchanged movement count and terminal state. Same error-code spelling correction as case 1. Brief case 3 enum guards. | — |
| 4 | S.recalled_after_dispatch_must_not_silently_land_at_destination; S.expired_after_dispatch_preserves_allocated_lot_on_completion | Recall RED → SKIP; expiry GREEN | Recall receives 200, expected refusal; expiry-only receives the original allocated lot after its date passes. Brief: Odoo reserved lines stay / ERPNext transit unaffected; ticket silent recalled receipt. | [T1-4](../tickets/2026-09-09-t1-recalled-in-transit.md) |
| 5 | S.location_feed_tracks_transit_then_terminal_stock; S.location_distribution_tracks_transit_then_terminal_stock; S.matrix_tracks_transit_then_terminal_stock; S.wac_counts_transit_exactly_once_before_and_after_terminal_action | GREEN | Same 10-unit source / 4-unit transfer fixture for every reader, each run for cancel and complete. Incoming=4 while in transit, then 0; source/destination totals exact. WAC's private owned-quantity reader is tested through real cost capitalization: +10 raises unit cost 5→6 before terminal action, then +10 raises 6→7 afterwards. Parent transit-location guarantee. | — |
| 6 | P.completed_status_precedes_freight_capitalization_inside_transaction | GREEN PG | Inside outer transaction, completed status, freight +10 over exactly 10 owned units, WAC=6; adjustment quantity_before/after=10. Denominator must not become 14. Brief capitalization ordering. | — |
| 7 | S.variant_reservations_prevent_transfer_despite_sufficient_on_hand; S.batch_reservations_prevent_transfer_despite_sufficient_aggregate_stock | GREEN | On-hand 10, reserved 8, request 4 rejected, no transfer inserted, stock stays 10 at both grains. Brief available-not-on-hand guarantee. | — |
| 8 | L.restricted_user_can_complete_incoming_transfer_at_their_location; L.restricted_user_cannot_complete_outgoing_transfer_to_foreign_destination; L.restricted_user_cannot_show_foreign_transfer; L.restricted_user_sees_incoming_and_outgoing_only | GREEN | Existing full 12-test location-scope file passes; incoming permitted, outgoing receive forbidden, unrelated transfer hidden from index/show. No gap requiring edits to this existing class. Brief case 8 says extend only for a gap. | — |
| 9 | S.second_company_transfer_is_invisible_on_index_show_complete_and_cancel | GREEN | Real second Company factory creation in same tenant, its own two locations/product and transfer; A's own row visible, B row absent, show/complete/cancel 404, no movements or state change in B. Brief isolation and second-of-everything. | — |
| 10 | S.idempotency_key_replays_identical_payload_but_refuses_changed_quantity | RED → SKIP | Same payload replays its ID; changed 4→5 under same key returns 201 instead of 422. Brief changed payload must refuse. | [T1-10](../tickets/2026-09-09-t1-idempotency-payload.md) |
| 11 | R.capture_and_bump_during_transit_do_not_reopen_original_or_settle_new_demand; R.replayed_initiated_event_must_not_settle_demand_captured_after_dispatch | Normal bump GREEN; event replay RED → SKIP | Original stays fulfilled, new POS-grain demand 3+1 stays pending under ordinary capture; replaying the old initiation fulfills that new demand again against the original transfer. Brief must not double-settle/reopen. | [T1-11](../tickets/2026-09-09-t1-settlement-replay.md) |
| 12 | R.pg_cancel_merges_into_new_open_grain_and_replay_does_not_merge_twice | GREEN on first PG execution | Real partial unique index exists; cancel merges 2+3 into one open row (5, count 2), supersedes original, preserves notes, repeat cancel and client UUID replay do not add again. SQLite intentionally skips. Brief merge collision guarantee. | — |
| 13 | R.po_append_refuses_other_company_and_creates_new_po_in_current_company | RED fixture snapshot → GREEN | Other-company draft deliberately shares supplier to isolate company guard; explicit append gets 422, retry without foreign target creates current-company PO, stamps request destination, foreign row/lines unchanged. Fixed first fixture snapshot by refreshing factory-created model before comparing stored attributes. Brief same-supplier foreign PO must not append. | — |
| 14 | R.requester_can_cancel_in_progress_request_while_status_is_open | RED → SKIP | Real PO-to-warehouse action starts processing, requester-only cancel returns 422 despite isOpen(). Current behavior recorded, not silently changed. Brief: Odoo cancel until done / ERPNext stop material request. | [T1-14](../tickets/2026-09-09-t1-cancel-in-progress.md) |
| 15 | R.restricted_processor_cannot_source_transfer_from_hidden_location | RED → GREEN | Previously HTTP 200 from hidden source; now 422 before mutation, request pending/no fulfillment/no transfer. Existing ValidLocationAccess rule added to replenishment action request. Brief StockTransferLocationAccessRuleTest precedent. | Fixed in lane |

## Verification

All test runs were by explicit file; no full PHPUnit or Vitest suite was run. PG connections, including central, were pinned to **autoerp_test_t**, loopback port **5433**. No test reset was directed at the demo database. PostgreSQL concurrency uses committed fixtures, explicit cleanup and an independent Symfony Process; it does not fake a row lock or rely on a sleep alone.

| Run | Result |
|---|---|
| StockTransferEdgeCasesTest, SQLite | 16 tests, 74 assertions, 2 known-defect skips |
| StockTransferEdgeCasesTest, PostgreSQL | 16 tests, 74 assertions, same 2 skips |
| StockTransferCompleteConcurrencyPostgresTest, PostgreSQL | 2 tests, 13 assertions, no skips |
| ReplenishmentEdgeCasesTest, SQLite | 6 tests, 23 assertions, 2 known-defect skips + 1 PG-only skip |
| ReplenishmentEdgeCasesTest, PostgreSQL | 6 tests, 41 assertions, 2 known-defect skips |
| Existing StockTransferLocationScopeTest, SQLite | 12 tests, 24 assertions, no skips |
| Existing ReplenishmentActionsTest, SQLite | 16 tests, 67 assertions, no skips |
| Pint on all four PHP files | PASS |
| PHPStan level 8 on changed FormRequest | PASS |
| `php tools/feature-lane-manifest-check.php` | PASS: 1517 Feature classes, 74 groups, 1253 parked classes; anchored filters uniquely matched |

Four behavior pins are deliberately skipped pending their linked tickets, **not fixed or counted as green**. `T1_RUN_KNOWN_REDS=1` enables all four without editing tests. Initial red evidence and corrected runs are retained locally under `docs/sessions/t1/` (gitignored); the case table above carries their durable account.

CI wiring: Inventory ceiling 127→129, Replenishment 7→8, aggregate gated ceiling 1250→1253, truthful dated notes. All three new classes are selected by the existing `backend-test-pgsql` regex. Feature lanes remain parked; this is configured selection, not a claimed CI pass. The job does not run on push→dev. No branch was pushed or merged.

## Preflight result

`./scripts/preflight.sh` **PASS** with `PREFLIGHT_SCOPE=paths`, the three new PHPUnit files, Pint scoped to all four PHP files, PHPStan scoped to the changed FormRequest, and Vitest scoped to `src/features/replenishment/__tests__/CreateTransferDialog.test.tsx`. TypeScript, ESLint, TanStack/design/quantity/i18n audits, custom rule/tool tests, fiscal fixture parity and §14.3 gate passed. No frontend source was edited. The first preflight attempt stopped at missing worktree node_modules; `pnpm install --offline --frozen-lockfile --ignore-scripts` restored cached dependencies and the rerun passed. Artisan reported missing-.env warnings during the scoped backend leg; direct PHPUnit runs were clean. Creating an empty ignored `.env` removed the warning, verified by rerunning the duplicate-completion case through artisan (PASS, 9 assertions). The four known-defect skips remain explicit regardless of preflight's successful exit.

## Local API evidence

The following are actual curl request/response transcripts. Authentication used the existing demo tenant per `reference_local_db_per_tenant_demo_launch`, with a fresh synthetic T1 product and seeded source stock only. API served from this worktree at **8011**, central `iziposcentral`, tenant resolved from the Bearer claim. Token is redacted. Case 12's curl captures use the web capture endpoint, which exercises the same capture/merge service; its PostgreSQL test explicitly captures with the POS channel and replays the client UUID.

Observed: initial 20 source units; transfer 4 → source 16/incoming 4; complete → destination 4/incoming 0; +10 freight produces WAC **5.500000** across 20 owned units. Duplicate completion and both illegal terminal transitions return **422 INVALID_TRANSFER_STATE**. A second transfer is cancelled back to source. Request collision leaves one pending **5.0000**, count **2** row. The three-reader agreement and WAC-owned transit denominator are established by the focused tests; the API transcript independently checks matrix/location stock/WAC output.

Authenticated against demo-pharmacy-tn in database-per-tenant mode on :8011. Bearer token omitted. All operations below target a newly created synthetic T1 product; initial source stock 20.0000, destination 0.0000, WAC 5.0000.

### Cases 1/5 initiate

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/stock-transfers -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{"source_location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","destination_location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","lines":[{"product_id":"01a0857a-3761-715c-92c0-de235331737d","quantity":"4.0000"}],"transfer_cost":"10.000","notes":"T1 synthetic API evidence"}'
```
```json
HTTP 201
{"data":{"id":"01a0857b-1dbc-72f6-80fd-5bfe59d47be1","transfer_number":"TR-2026-00005","transfer_type":"intracompany","status":"in_transit","source_location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","source_location_name":"PharmaBio Entrep\u00f4t Central","destination_location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","destination_location_name":"PharmaBio Sfax \u2014 Centre","notes":"T1 synthetic API evidence","transfer_cost":"10.0000","transfer_cost_label":null,"transfer_cost_distribution":"pro_rata_value","initiated_by_user_id":"d05e8090-75c9-47ba-9352-64746596d167","initiated_by_name":"Jean-Baptiste Mercier","completed_by_user_id":null,"completed_by_name":null,"cancelled_by_user_id":null,"cancelled_by_name":null,"initiated_at":"2026-09-09T09:23:53+00:00","completed_at":null,"cancelled_at":null,"cancellation_reason":null,"created_at":"2026-09-09T09:23:53+00:00","updated_at":"2026-09-09T09:23:53+00:00","lines":[{"id":"01a0857b-1dc5-7193-b907-0880be17fe31","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","product_sku":"T1-EDGE-092254","variant_id":null,"variant_sku":null,"variant_name":null,"quantity":"4.0000","quantity_decimals":4,"unit_cost_snapshot":"5.0000","allocated_transfer_cost":"0.0000","batch_allocations":[]}]}}
```

### Case 5 matrix in transit

```sh
curl -sS -X GET 'http://127.0.0.1:8011/api/v1/inventory/stock-matrix?include=incoming&search=T1-EDGE-092254' -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json'
```
```json
HTTP 200
{"data":[{"product_id":"01a0857a-3761-715c-92c0-de235331737d","variant_id":null,"name":"T1 synthetic transfer campaign","sku":"T1-EDGE-092254","is_variant_parent":false,"cells":{"6eb6f94c-10f8-41fc-bdc7-18fe548dff98":{"on_hand":"16.0000","reserved":"0.0000","available":"16.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"52bfc8ec-a58a-484d-9152-5a66f1026f64":{"on_hand":"0.0000","reserved":"0.0000","available":"0.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"748e676b-2cbd-409c-8e3c-1e7c9dd55424":{"on_hand":"0.0000","reserved":"0.0000","available":"0.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"3ff63446-0438-429d-9fe1-9c98587f7557":{"on_hand":"0.0000","reserved":"0.0000","available":"0.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"9540970b-3006-4f2e-9b5d-bf5c9c913412":{"on_hand":"0.0000","reserved":"0.0000","available":"0.0000","min_quantity":null,"max_quantity":null,"incoming":"4.0000"}}}],"meta":{"current_page":1,"last_page":1,"total":1}}
```

### Case 5 location stock in transit

```sh
curl -sS -X GET http://127.0.0.1:8011/api/v1/products/01a0857a-3761-715c-92c0-de235331737d/stock-levels -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json'
```
```json
HTTP 200
{"data":{"locations":[{"id":"01a0857a-37b1-72c0-9421-b111341f1ee3","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","location_name":"PharmaBio Entrep\u00f4t Central","quantity":"16.0000","reserved":"0.0000","available":"16.0000","incoming":"0.00","projected_available":"16.0000","min_quantity":null,"max_quantity":null,"is_below_minimum":false,"quantity_decimals":4,"requires_batch_tracking":false,"has_lots_at_location":false}],"totals":{"quantity":"16.0000","reserved":"0.0000","available":"16.0000","incoming":"0.0000","projected_available":"16.0000","quantity_decimals":4}},"meta":{"timestamp":"2026-09-09T09:23:54+00:00","request_id":"2eadc19f-5b45-4c31-98a7-b34c1589eec1"}}
```

### Case 1 first complete

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/stock-transfers/01a0857b-1dbc-72f6-80fd-5bfe59d47be1/complete -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{}'
```
```json
HTTP 200
{"data":{"id":"01a0857b-1dbc-72f6-80fd-5bfe59d47be1","transfer_number":"TR-2026-00005","transfer_type":"intracompany","status":"completed","source_location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","source_location_name":"PharmaBio Entrep\u00f4t Central","destination_location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","destination_location_name":"PharmaBio Sfax \u2014 Centre","notes":"T1 synthetic API evidence","transfer_cost":"10.0000","transfer_cost_label":null,"transfer_cost_distribution":"pro_rata_value","initiated_by_user_id":"d05e8090-75c9-47ba-9352-64746596d167","initiated_by_name":"Jean-Baptiste Mercier","completed_by_user_id":"d05e8090-75c9-47ba-9352-64746596d167","completed_by_name":"Jean-Baptiste Mercier","cancelled_by_user_id":null,"cancelled_by_name":null,"initiated_at":"2026-09-09T09:23:53+00:00","completed_at":"2026-09-09T09:23:54+00:00","cancelled_at":null,"cancellation_reason":null,"created_at":"2026-09-09T09:23:53+00:00","updated_at":"2026-09-09T09:23:54+00:00","lines":[{"id":"01a0857b-1dc5-7193-b907-0880be17fe31","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","product_sku":"T1-EDGE-092254","variant_id":null,"variant_sku":null,"variant_name":null,"quantity":"4.0000","quantity_decimals":4,"unit_cost_snapshot":"5.0000","allocated_transfer_cost":"10.0000","batch_allocations":[]}]}}
```

### Case 1 second complete

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/stock-transfers/01a0857b-1dbc-72f6-80fd-5bfe59d47be1/complete -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{}'
```
```json
HTTP 422
{"error":{"code":"INVALID_TRANSFER_STATE","message":"Cannot complete transfer 01a0857b-1dbc-72f6-80fd-5bfe59d47be1 in status completed","details":{"transfer_id":"01a0857b-1dbc-72f6-80fd-5bfe59d47be1","current_status":"completed","attempted":"complete"}}}
```

### Case 3 cancel completed

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/stock-transfers/01a0857b-1dbc-72f6-80fd-5bfe59d47be1/cancel -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{"reason":"T1 terminal refusal"}'
```
```json
HTTP 422
{"error":{"code":"INVALID_TRANSFER_STATE","message":"Cannot cancel transfer 01a0857b-1dbc-72f6-80fd-5bfe59d47be1 in status completed","details":{"transfer_id":"01a0857b-1dbc-72f6-80fd-5bfe59d47be1","current_status":"completed","attempted":"cancel"}}}
```

### Case 5 matrix completed

```sh
curl -sS -X GET 'http://127.0.0.1:8011/api/v1/inventory/stock-matrix?include=incoming&search=T1-EDGE-092254' -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json'
```
```json
HTTP 200
{"data":[{"product_id":"01a0857a-3761-715c-92c0-de235331737d","variant_id":null,"name":"T1 synthetic transfer campaign","sku":"T1-EDGE-092254","is_variant_parent":false,"cells":{"6eb6f94c-10f8-41fc-bdc7-18fe548dff98":{"on_hand":"16.0000","reserved":"0.0000","available":"16.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"52bfc8ec-a58a-484d-9152-5a66f1026f64":{"on_hand":"0.0000","reserved":"0.0000","available":"0.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"748e676b-2cbd-409c-8e3c-1e7c9dd55424":{"on_hand":"0.0000","reserved":"0.0000","available":"0.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"3ff63446-0438-429d-9fe1-9c98587f7557":{"on_hand":"0.0000","reserved":"0.0000","available":"0.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"9540970b-3006-4f2e-9b5d-bf5c9c913412":{"on_hand":"4.0000","reserved":"0.0000","available":"4.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"}}}],"meta":{"current_page":1,"last_page":1,"total":1}}
```

### Case 5 location stock completed

```sh
curl -sS -X GET http://127.0.0.1:8011/api/v1/products/01a0857a-3761-715c-92c0-de235331737d/stock-levels -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json'
```
```json
HTTP 200
{"data":{"locations":[{"id":"01a0857a-37b1-72c0-9421-b111341f1ee3","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","location_name":"PharmaBio Entrep\u00f4t Central","quantity":"16.0000","reserved":"0.0000","available":"16.0000","incoming":"0.00","projected_available":"16.0000","min_quantity":null,"max_quantity":null,"is_below_minimum":false,"quantity_decimals":4,"requires_batch_tracking":false,"has_lots_at_location":false},{"id":"01a0857b-21b3-71e2-9754-d2cc19ae58b5","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","location_name":"PharmaBio Sfax \u2014 Centre","quantity":"4.0000","reserved":"0.0000","available":"4.0000","incoming":"0.00","projected_available":"4.0000","min_quantity":null,"max_quantity":null,"is_below_minimum":false,"quantity_decimals":4,"requires_batch_tracking":false,"has_lots_at_location":false}],"totals":{"quantity":"20.0000","reserved":"0.0000","available":"20.0000","incoming":"0.0000","projected_available":"20.0000","quantity_decimals":4}},"meta":{"timestamp":"2026-09-09T09:23:55+00:00","request_id":"4c7142f7-6b1d-4fa4-b579-b75e1d00ec83"}}
```

### Case 5 product WAC after freight

```sh
curl -sS -X GET http://127.0.0.1:8011/api/v1/products/01a0857a-3761-715c-92c0-de235331737d -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json'
```
```json
HTTP 200
{"data":{"id":"01a0857a-3761-715c-92c0-de235331737d","name":"T1 synthetic transfer campaign","sku":"T1-EDGE-092254","type":"part","description":null,"sale_price":"10.000","purchase_price":null,"cost_price":"5.500000","last_purchase_cost":null,"tax_rate":null,"default_tax_configuration_id":null,"unit":null,"unit_id":null,"units_per_pack":null,"shelf_location":null,"reorder_point":null,"reorder_quantity":null,"quantity_decimals":4,"barcode":null,"is_active":true,"is_active_for_ecommerce":false,"is_physical":true,"requires_batch_tracking":false,"oem_numbers":null,"cross_references":null,"target_margin_override":null,"minimum_margin_override":null,"max_discount_percent":null,"platform_product_id":null,"created_at":"2026-09-09T09:22:54+00:00","updated_at":"2026-09-09T09:23:54+00:00","has_variants":false,"primary_image_url":null,"media":[],"brand":null,"brand_source":null,"category":null,"parapharmacy_metadata":null,"automotive_metadata":null,"opening":null,"enrichment_status":null,"latest_enrichment_result":null,"stock_quantity":"20.0000","pricing_mode":"manual","effective_margins":{"target_margin":"30.00","minimum_margin":"10.00","target_source":"company","target_source_category_id":null,"minimum_source":"company","minimum_source_category_id":null,"minimum_clamped":false}},"meta":{"timestamp":"2026-09-09T09:23:55+00:00","request_id":"281334e2-fa21-48f6-8221-843ef95ee298"}}
```

### Case 3 initiate second transfer

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/stock-transfers -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{"source_location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","destination_location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","lines":[{"product_id":"01a0857a-3761-715c-92c0-de235331737d","quantity":"4.0000"}],"transfer_cost":"0.000","notes":"T1 synthetic API evidence"}'
```
```json
HTTP 201
{"data":{"id":"01a0857b-27b4-70f0-a49f-b3f8ca23bf26","transfer_number":"TR-2026-00006","transfer_type":"intracompany","status":"in_transit","source_location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","source_location_name":"PharmaBio Entrep\u00f4t Central","destination_location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","destination_location_name":"PharmaBio Sfax \u2014 Centre","notes":"T1 synthetic API evidence","transfer_cost":"0.0000","transfer_cost_label":null,"transfer_cost_distribution":"pro_rata_value","initiated_by_user_id":"d05e8090-75c9-47ba-9352-64746596d167","initiated_by_name":"Jean-Baptiste Mercier","completed_by_user_id":null,"completed_by_name":null,"cancelled_by_user_id":null,"cancelled_by_name":null,"initiated_at":"2026-09-09T09:23:55+00:00","completed_at":null,"cancelled_at":null,"cancellation_reason":null,"created_at":"2026-09-09T09:23:55+00:00","updated_at":"2026-09-09T09:23:55+00:00","lines":[{"id":"01a0857b-27be-727f-84c9-462f59763d45","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","product_sku":"T1-EDGE-092254","variant_id":null,"variant_sku":null,"variant_name":null,"quantity":"4.0000","quantity_decimals":4,"unit_cost_snapshot":"5.5000","allocated_transfer_cost":"0.0000","batch_allocations":[]}]}}
```

### Case 3 cancel in transit

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/stock-transfers/01a0857b-27b4-70f0-a49f-b3f8ca23bf26/cancel -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{"reason":"T1 return to source"}'
```
```json
HTTP 200
{"data":{"id":"01a0857b-27b4-70f0-a49f-b3f8ca23bf26","transfer_number":"TR-2026-00006","transfer_type":"intracompany","status":"cancelled","source_location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","source_location_name":"PharmaBio Entrep\u00f4t Central","destination_location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","destination_location_name":"PharmaBio Sfax \u2014 Centre","notes":"T1 synthetic API evidence","transfer_cost":"0.0000","transfer_cost_label":null,"transfer_cost_distribution":"pro_rata_value","initiated_by_user_id":"d05e8090-75c9-47ba-9352-64746596d167","initiated_by_name":"Jean-Baptiste Mercier","completed_by_user_id":null,"completed_by_name":null,"cancelled_by_user_id":"d05e8090-75c9-47ba-9352-64746596d167","cancelled_by_name":"Jean-Baptiste Mercier","initiated_at":"2026-09-09T09:23:55+00:00","completed_at":null,"cancelled_at":"2026-09-09T09:23:56+00:00","cancellation_reason":"T1 return to source","created_at":"2026-09-09T09:23:55+00:00","updated_at":"2026-09-09T09:23:56+00:00","lines":[{"id":"01a0857b-27be-727f-84c9-462f59763d45","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","product_sku":"T1-EDGE-092254","variant_id":null,"variant_sku":null,"variant_name":null,"quantity":"4.0000","quantity_decimals":4,"unit_cost_snapshot":"5.5000","allocated_transfer_cost":"0.0000","batch_allocations":[]}]}}
```

### Case 3 complete cancelled

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/stock-transfers/01a0857b-27b4-70f0-a49f-b3f8ca23bf26/complete -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{}'
```
```json
HTTP 422
{"error":{"code":"INVALID_TRANSFER_STATE","message":"Cannot complete transfer 01a0857b-27b4-70f0-a49f-b3f8ca23bf26 in status cancelled","details":{"transfer_id":"01a0857b-27b4-70f0-a49f-b3f8ca23bf26","current_status":"cancelled","attempted":"complete"}}}
```

### Case 5 matrix cancelled

```sh
curl -sS -X GET 'http://127.0.0.1:8011/api/v1/inventory/stock-matrix?include=incoming&search=T1-EDGE-092254' -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json'
```
```json
HTTP 200
{"data":[{"product_id":"01a0857a-3761-715c-92c0-de235331737d","variant_id":null,"name":"T1 synthetic transfer campaign","sku":"T1-EDGE-092254","is_variant_parent":false,"cells":{"6eb6f94c-10f8-41fc-bdc7-18fe548dff98":{"on_hand":"16.0000","reserved":"0.0000","available":"16.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"52bfc8ec-a58a-484d-9152-5a66f1026f64":{"on_hand":"0.0000","reserved":"0.0000","available":"0.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"748e676b-2cbd-409c-8e3c-1e7c9dd55424":{"on_hand":"0.0000","reserved":"0.0000","available":"0.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"3ff63446-0438-429d-9fe1-9c98587f7557":{"on_hand":"0.0000","reserved":"0.0000","available":"0.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"},"9540970b-3006-4f2e-9b5d-bf5c9c913412":{"on_hand":"4.0000","reserved":"0.0000","available":"4.0000","min_quantity":null,"max_quantity":null,"incoming":"0.0000"}}}],"meta":{"current_page":1,"last_page":1,"total":1}}
```

### Case 5 location stock cancelled

```sh
curl -sS -X GET http://127.0.0.1:8011/api/v1/products/01a0857a-3761-715c-92c0-de235331737d/stock-levels -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json'
```
```json
HTTP 200
{"data":{"locations":[{"id":"01a0857a-37b1-72c0-9421-b111341f1ee3","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","location_name":"PharmaBio Entrep\u00f4t Central","quantity":"16.0000","reserved":"0.0000","available":"16.0000","incoming":"0.00","projected_available":"16.0000","min_quantity":null,"max_quantity":null,"is_below_minimum":false,"quantity_decimals":4,"requires_batch_tracking":false,"has_lots_at_location":false},{"id":"01a0857b-21b3-71e2-9754-d2cc19ae58b5","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","location_name":"PharmaBio Sfax \u2014 Centre","quantity":"4.0000","reserved":"0.0000","available":"4.0000","incoming":"0.00","projected_available":"4.0000","min_quantity":null,"max_quantity":null,"is_below_minimum":false,"quantity_decimals":4,"requires_batch_tracking":false,"has_lots_at_location":false}],"totals":{"quantity":"20.0000","reserved":"0.0000","available":"20.0000","incoming":"0.0000","projected_available":"20.0000","quantity_decimals":4}},"meta":{"timestamp":"2026-09-09T09:23:57+00:00","request_id":"126058ba-3a12-495e-bafe-3e943f5701bc"}}
```

### Case 12 original request

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/replenishment-requests -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{"location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","product_id":"01a0857a-3761-715c-92c0-de235331737d","requested_qty":"2.0000","note":"T1 original demand"}'
```
```json
HTTP 201
{"data":{"id":"01a0857b-2cca-710d-b787-701deb979b8d","location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","location_name":"PharmaBio Sfax \u2014 Centre","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","variant_id":null,"variant_name":null,"requested_qty":"2.0000","suggested_qty":null,"quantity_decimals":4,"note":"T1 original demand","request_count":1,"status":"pending","source_channel":"web","first_requested_at":"2026-09-09T09:23:57+00:00","last_requested_at":"2026-09-09T09:23:57+00:00","sourcing_document_id":null,"fulfillment_type":null,"fulfillment_id":null,"rejection_reason":null}}
```

### Case 12 create request transfer

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/replenishment-requests/actions/create-transfer -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{"source_location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","lines":[{"request_id":"01a0857b-2cca-710d-b787-701deb979b8d","quantity":"2.0000"}]}'
```
```json
HTTP 200
{"data":{"transfer_ids":["01a0857b-2db1-73d1-81ab-aebb177175fd"]}}
```

### Case 12 new open request

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/replenishment-requests -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{"location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","product_id":"01a0857a-3761-715c-92c0-de235331737d","requested_qty":"3.0000","note":"T1 new demand while in transit"}'
```
```json
HTTP 201
{"data":{"id":"01a0857b-2e98-73ac-ad06-be809af6306b","location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","location_name":"PharmaBio Sfax \u2014 Centre","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","variant_id":null,"variant_name":null,"requested_qty":"3.0000","suggested_qty":null,"quantity_decimals":4,"note":"T1 new demand while in transit","request_count":1,"status":"pending","source_channel":"web","first_requested_at":"2026-09-09T09:23:57+00:00","last_requested_at":"2026-09-09T09:23:57+00:00","sourcing_document_id":null,"fulfillment_type":null,"fulfillment_id":null,"rejection_reason":null}}
```

### Case 12 cancel transfer with collision

```sh
curl -sS -X POST http://127.0.0.1:8011/api/v1/stock-transfers/01a0857b-2db1-73d1-81ab-aebb177175fd/cancel -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json' --data-raw '{"reason":"T1 merge collision"}'
```
```json
HTTP 200
{"data":{"id":"01a0857b-2db1-73d1-81ab-aebb177175fd","transfer_number":"TR-2026-00007","transfer_type":"intracompany","status":"cancelled","source_location_id":"6eb6f94c-10f8-41fc-bdc7-18fe548dff98","source_location_name":"PharmaBio Entrep\u00f4t Central","destination_location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","destination_location_name":"PharmaBio Sfax \u2014 Centre","notes":null,"transfer_cost":"0.0000","transfer_cost_label":null,"transfer_cost_distribution":"pro_rata_value","initiated_by_user_id":"d05e8090-75c9-47ba-9352-64746596d167","initiated_by_name":"Jean-Baptiste Mercier","completed_by_user_id":null,"completed_by_name":null,"cancelled_by_user_id":"d05e8090-75c9-47ba-9352-64746596d167","cancelled_by_name":"Jean-Baptiste Mercier","initiated_at":"2026-09-09T09:23:57+00:00","completed_at":null,"cancelled_at":"2026-09-09T09:23:57+00:00","cancellation_reason":"T1 merge collision","created_at":"2026-09-09T09:23:57+00:00","updated_at":"2026-09-09T09:23:57+00:00","lines":[{"id":"01a0857b-2db5-71e1-902d-42015cd00cf6","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","product_sku":"T1-EDGE-092254","variant_id":null,"variant_sku":null,"variant_name":null,"quantity":"2.0000","quantity_decimals":4,"unit_cost_snapshot":"5.5000","allocated_transfer_cost":"0.0000","batch_allocations":[]}]}}
```

### Case 12 merged request index

```sh
curl -sS -X GET 'http://127.0.0.1:8011/api/v1/replenishment-requests?status=open&search=T1-EDGE-092254' -H 'Authorization: Bearer <redacted>' -H 'X-Company-Id: 01a04e72-6090-710e-891d-e73ea3483ce8' -H 'Accept: application/json' -H 'Content-Type: application/json'
```
```json
HTTP 200
{"data":[{"id":"01a0857b-2e98-73ac-ad06-be809af6306b","location_id":"9540970b-3006-4f2e-9b5d-bf5c9c913412","location_name":"PharmaBio Sfax \u2014 Centre","product_id":"01a0857a-3761-715c-92c0-de235331737d","product_name":"T1 synthetic transfer campaign","variant_id":null,"variant_name":null,"requested_qty":"5.0000","suggested_qty":null,"quantity_decimals":4,"note":"T1 new demand while in transit\n---\nT1 original demand\n---\ntransfer TR-2026-00007 cancelled","request_count":2,"status":"pending","source_channel":"web","first_requested_at":"2026-09-09T09:23:57+00:00","last_requested_at":"2026-09-09T09:23:57+00:00","sourcing_document_id":null,"fulfillment_type":null,"fulfillment_id":null,"rejection_reason":null}],"meta":{"truncated":false}}
```


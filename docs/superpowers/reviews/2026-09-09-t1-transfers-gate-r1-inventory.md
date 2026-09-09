# Gate r1 — lane T-1 (stock transfers + replenishment requests edge campaign)

Reviewer: `inventory-costing-reviewer` (adversarial, code-grounded). Date 2026-09-09.
Branch `lane/t1-transfers-edge`, worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/t1-transfers`.
Base `63e0e5e16` (`git merge-base HEAD dev`), six commits `b3c957903 … 691eacba2`.
Authority read: `docs/handoff/BRIEF-lane-T1-transfers-requests-edge-campaign-2026-09-08.md`,
`docs/handoff/CODEX-DISPATCH-T1-transfers-requests-edge-campaign-2026-09-09.md`,
handback `.worktrees/t1-transfers/docs/handoff/HANDBACK-T1-2026-09-09.md`,
evidence `.worktrees/t1-transfers/docs/superpowers/reviews/2026-09-09-t1-transfers-requests-edge-evidence.md`.

## VERDICT: MERGE-WITH-FIXES — 0 Blocker, 4 Major, 5 Minor

Every claim below was verified by reading the file or by running the command shown. Nothing is asserted from memory.

---

## 1. Scope discipline — PASS

`git diff 63e0e5e16..HEAD --stat` (12 files, 1034 insertions):

| File | Verdict |
|---|---|
| `apps/api/app/Modules/Replenishment/Presentation/Requests/CreateTransferFromRequestsRequest.php` | **the only production change** — +12 / −2 |
| `.github/workflows/ci.yml` | +11 / −2 (allowlist + comment) |
| `apps/api/tests/feature-lane-manifest.json` | ceilings + notes |
| 3 new test classes, 1 handback, 1 evidence doc, 4 tickets | tests/docs only |

- `StockTransferService.php` — **untouched** (verified: absent from the diff file list). Lane T-2 keeps the lifecycle.
- Inventory-counting files — **untouched**. No migration, no DTO, no frontend source, no `packages/shared` change.
- The fix is inside the pinned seam of case 15 (the FormRequest of `/api/v1/replenishment-requests/actions/create-transfer`, the endpoint case 15 exercises) and is 14 changed lines ≤ 20.
- Blast radius is genuinely narrow: the cross-company guard already existed downstream at `apps/api/app/Modules/Replenishment/Presentation/Controllers/ReplenishmentActionController.php:139-144`
  (`assertLocationCompany`, thrown as a 422 `ValidationException`), and `LocationContext::canAccessLocation`
  (`apps/api/app/Modules/Company/Services/LocationContext.php:224-239`) returns `true` for any location when
  `allowed_location_ids === null`. So the new rule changes behaviour **only** for location-restricted users — exactly the case that exposed it.
- Pattern is in-repo precedent, not invention: `apps/api/app/Modules/Inventory/Presentation/Requests/StoreStockTransferRequest.php:20-25`
  constructor-injects the same two contexts and uses the same `ValidLocationAccess`; the sibling
  `CreatePoFromRequestsRequest.php:14-17,27-28` already calls `requireCompanyId()` inside `rules()`.
- Rule 13 respected: `private readonly` constructor injection, no `app()` in production
  (`CreateTransferFromRequestsRequest.php:14-19`). `app()` appears only in the test classes, which is the
  established repo idiom (`tests/Feature/Inventory/StockTransferLocationAccessRuleTest.php:115-117`).

## 2. The 15 cases — all present, assertions test data meaning

`S` = `tests/Feature/Inventory/StockTransferEdgeCasesTest.php`, `P` = `tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php`,
`R` = `tests/Feature/Replenishment/ReplenishmentEdgeCasesTest.php`, `L` = existing `StockTransferLocationScopeTest.php`.

| Case | Where | Data-meaning assertion I verified |
|---|---|---|
| 1 idempotent complete | S:69-84 | one `TransferIn` movement **per line** (`assertCount(1, …)`), exact per-line qty, destination `stock_levels.quantity` per line; typed `INVALID_TRANSFER_STATE` |
| 2 concurrent complete (PG) | P:85-127 | real second PHP process observed in `pg_stat_activity.wait_event_type = 'Lock'` (P:113-120) while the winner is uncommitted; then typed refusal; source 6.0000 / dest 4.0000 / exactly 1 `TransferIn` |
| 3 terminal transitions | S:86-97 | `StockMovement::count()` unchanged across the refused call, terminal status preserved |
| 4 recall / expiry after dispatch | S:99-111 (skipped), S:113-122 (green) | expiry pin asserts the allocated lot's `BatchStock` 4.0000 at destination; recall pin is aspirational + skipped — see **M1** |
| 5 in-transit derived qty | S:132-185, 4 tests × 2 actions | `LocationStockQueryService::read()` incoming 4.0000→[]; `stockDistributionForProduct` 4.0000→0.0000 with on-hand 6→10; `StockMatrixQueryService::matrix` cell incoming/on_hand; **WAC via real capitalization**: +10 over 10 owned units moves 5→6 while 4 are in transit (proves in-transit counted once: 14 units would give 5.7142, 6 units 6.6667), then 6→7 after the terminal action |
| 6 capitalization ordering (PG) | P:129-144 | inside an outer transaction (`assertGreaterThan(0, DB::transactionLevel())`): status Completed, `cost_price` 6.0000, adjustment movement `quantity_before/after` 10.0000 and `avg_cost_after` 6.0000 — the denominator pin behind `StockTransferService.php:385-395` |
| 7 reservations block initiate | S:187-213 | both grains: `InsufficientStockException`, **0 transfers inserted**, stock still 10.0000 at variant grain and lot grain |
| 8 location-restricted user | L:203,241,254,264,274 (existing, unmodified) | the three sub-claims of the brief exist already; brief says extend only on a gap — correct call |
| 9 second company | S:215-234 | index excludes the foreign id, show/complete/cancel 404 (not 403), `StockMovement::count()` unchanged, foreign transfer still `InTransit` |
| 10 idempotency + changed payload | S:236-249 (skipped) | see ticket T1-10 |
| 11 bump during transit | R:70-86 (green), R:88-102 (skipped) | original stays Fulfilled with its `fulfillment_id`; the new grain accumulates 3+1 = 4.0000 with `request_count` 2 and stays Pending with `fulfillment_id` null; exactly 1 transfer |
| 12 reopen merge on partial unique (PG) | R:104-132 | asserts the real `replenishment_open_non_variant` index exists in `pg_indexes` and is a partial UNIQUE; merged 5.0000 / count 2 / both notes preserved; **exactly one** open row for the grain; repeat cancel 422 and client-UUID replay do not merge twice |
| 13 PO append never crosses company | R:134-153 | foreign draft `getAttributes()` snapshot **byte-identical** after the refusal, line count unchanged, new PO's `company_id` and line `location_id` asserted. Complements (does not duplicate) `ReplenishmentActionsTest.php:274` — that one mutates one document through three refusal modes; this one isolates the company guard with the same supplier and a draft status |
| 14 cancel after in_progress | R:155-168 (skipped) | see ticket T1-14 and **M1** |
| 15 restricted processor 422 | R:170-178 | request stays Pending, `fulfillment_id` null, `StockTransfer::count() === 0` — the mutation is refused before any write |

No `assertTrue(true)`, no mocked subject, no fake payloads. `RefreshDatabase` + `RolesAndPermissionsSeeder` + real models in both `RefreshDatabase` classes (S:38,59 / R:38,59); the PG concurrency class deliberately forgoes `RefreshDatabase` so a second OS process can see committed fixtures (P:53) and hand-cleans in `tearDown` (P:67-83).

### Red-first honesty

Recorded honestly, including the author's own harness mistakes rather than dressing them as product defects — evidence:9,13,14,15,25 admit an assertion typo (`TRANSFER_STATE_INVALID`), a missing `pg_stat_clear_snapshot()`, a wrong permission name in the fixture and a stale factory snapshot. I reproduced all four reds myself:

```
T1_RUN_KNOWN_REDS=1 phpunit tests/Feature/Inventory/StockTransferEdgeCasesTest.php
  → 2 failures: :108 expected 422 got 200 (recall)   :246 expected 422 got 201 (idempotency)
T1_RUN_KNOWN_REDS=1 phpunit tests/Feature/Replenishment/ReplenishmentEdgeCasesTest.php
  → 2 failures: :98 Pending vs Fulfilled (replay)     :166 expected 200 got 422 (cancel in_progress)
```

### Are the four tickets real defects? — yes, all four; citations check out

- **T1-10 idempotency** (`docs/superpowers/tickets/2026-09-09-t1-idempotency-payload.md`) — REAL. `StockTransferService::findExistingTransfer` (`:198-205`) keys only on `(tenant_id, company_id, idempotency_key)`; no payload comparison in either the happy path (`:109-113`) or the unique-collision recovery (`:185-190`). Confirmed benign for stock: the changed payload replays the OLD transfer, so there is **no second stock decrement** — the ticket should say so explicitly (it currently only says "an operator can believe changed demand was dispatched").
- **T1-4 recall in transit** (`…-t1-recalled-in-transit.md`) — REAL. `StockTransferService.php:356-368` calls `stockAdjustmentService->receive()` for each saved allocation with no recall re-check at receipt. Correctly deferred to T-2 (a race-safe batch-state lock + typed error contract is well over 20 lines).
- **T1-14 cancel in_progress** (`…-t1-cancel-in-progress.md`) — REAL inconsistency. `ReplenishmentRequestController.php:141` is `abort_unless($row->status === ReplenishmentStatus::Pending, 422)` while `ReplenishmentStatus::isOpen()` (`ReplenishmentStatus.php:16-18`) returns true for `InProgress` too. Refusing the one-line `isOpen()` substitution is the right call — it would decide unruled sourcing lifecycle behaviour.
- **T1-11 settlement replay** (`…-t1-settlement-replay.md`) — REAL but mis-scoped, see **m1**.

## 3. PG legs — independently re-run and confirmed

I ran only the two PG-relevant classes, against a throwaway DB `autoerp_test_gate_t1` on the local `autoerp_postgres` container (127.0.0.1:5433), never the full suite, never the shared lane DB:

| Run | Result | Evidence claim | Match |
|---|---|---|---|
| `StockTransferCompleteConcurrencyPostgresTest` on PG (cases 2, 6) | **OK, 2 tests, 13 assertions** (105 s) | 2/13/no skips | ✅ |
| `ReplenishmentEdgeCasesTest` on PG (case 12) | **OK, 6 tests, 41 assertions, 2 skips** (175 s) | 6/41/2 skips | ✅ |
| `StockTransferEdgeCasesTest` on SQLite | 16 tests / 74 assertions / 2 skips | identical | ✅ |
| `ReplenishmentEdgeCasesTest` on SQLite | 6 / 23 / 3 skips (incl. PG-only case 12) | identical | ✅ |
| `ReplenishmentActionsTest` (regression for the production change) | **OK, 16 tests, 67 assertions** | 16/67 | ✅ |

Cases 2, 6 and 12 were genuinely exercised on PostgreSQL, not asserted from a SQLite run. Case 12 additionally proves the partial unique exists in `pg_indexes` (R:110-113), so it cannot pass vacuously on a schema that lost the index.

## 4. Case 5 (three readers) and case 6 (ordering) — substantially met

Case 5 has one test per brief-named reader on the identical fixture, each run for both terminal actions
(`#[DataProvider('terminalActions')]`, S:124-185). The WAC reader is exercised through real capitalization rather than a private accessor, which is the stronger choice. Case 6 is pinned inside one PG transaction. Two caveats: **M2** (a fourth surface disagrees and the evidence narrates agreement) and **m2** (the case-6 method name overstates what is pinned).

## 5. Replenishment 11–15 — met

Not double-settled (R:74-85), reopen merge on the real partial unique with a replay leg (R:104-132), PO append company-scoped with a byte-identical foreign snapshot (R:141-152), cancel-after-`in_progress` pinned and benchmarked in its ticket, restricted processor 422 with the request left open (R:170-178).

## 6. Lane wiring — truthful and additive

- `apps/api/tests/feature-lane-manifest.json`: Inventory 127→129 (exactly the 2 new Inventory classes), Replenishment 7→8 (1 class), aggregate 1250→1253. Notes are dated, name the classes, state the lane is parked, and — unusually and correctly — say **"No observed CI run is claimed."**
- `php tools/feature-lane-manifest-check.php` → **exit 0**, "every `--filter` entry is anchored and uniquely matched". I ran it.
- `.github/workflows/ci.yml`: the three class names are appended to the existing `backend-test-pgsql --filter` (line 20 of the diff hunk); no entry removed. The comment block follows the `CountingMovementReferenceTest` / PR #221 precedent and repeats the standard `push->dev` caveat.

## 7. Rule 19 / rule 13 / style — clean

- `grep '(float)|floatval|number_format'` over the four changed PHP files → **no matches**. Every quantity in fixtures and assertions is a decimal string (`'4.0000'`, `'10.0000'`, `'0.0000'`); money as `'10.000'`; comparisons via `bccomp`, never `==`.
- The new FormRequest keeps the quantity regex ceiling `/^\d+(\.\d{1,4})?$/` (`:33`) untouched.
- `./vendor/bin/pint --test` on all four files → `{"result":"pass"}`.
- `phpstan analyse` (level 8) on the changed FormRequest → **No errors**. Note `phpstan.neon:6-8` analyses `app/` only, so the `bccomp(…, 4)` literals in tests are not a `ForbidHardcodedBcmathScale` violation.
- No WAC arithmetic was added or altered; no new rounding boundary; no new lock ordering. The lane only *reads* WAC.

---

## Findings

### Major

- **[MAJOR M1] `apps/api/tests/Feature/Inventory/StockTransferEdgeCasesTest.php:99-111` and `apps/api/tests/Feature/Replenishment/ReplenishmentEdgeCasesTest.php:155-168` — no live pin of CURRENT behaviour for cases 4 and 14.** The brief says for case 4 (brief:13) "Pin the current behaviour and benchmark it … Ticket if a recalled lot silently lands" and for case 14 (brief:25) "Pin current behaviour, benchmark … Ticket if inconsistent". Both tests instead encode the DESIRED behaviour and are `markTestSkipped` unless `T1_RUN_KNOWN_REDS=1`, so nothing green asserts that today a recalled lot lands at the destination on a 200, or that cancelling an `in_progress` request returns 422. **Why it matters:** the campaign's stated purpose is a tripwire; a silent change in either direction between now and T-2 is undetected, and T-2 loses its "before" baseline. **Fix:** add a green companion pinning today's outcome (recall → 200 and destination `BatchStock` 4.0000 with a `// current behaviour, ticket T1-4` comment; cancel `in_progress` → 422 and status unchanged), keeping the aspirational test skipped.

- **[MAJOR M2] evidence `2026-09-09-t1-transfers-requests-edge-evidence.md:58` claims a reader agreement its own transcript contradicts.** The sentence says the API transcript "independently checks matrix/location stock/WAC output". But the `Case 5 location stock in transit` transcript (evidence:89) shows `/api/v1/products/{id}/stock-levels` returning only the SOURCE row, `"incoming":"0.00"`, `totals.incoming:"0.0000"` — no destination row at all while 4 units are in transit — because that endpoint's `incoming` is the confirmed-PO unreceived remainder (`apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:1073-1093`), a different concept from the matrix's in-transit `incoming` (`apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137-165`). **Why it matters:** the three brief-named readers ARE correctly covered, but the evidence sentence would let a reader conclude the product-detail stock surface also shows in-transit, which it never does; and the guarantee "an operator sees stock in transit" is false on the product page. **Fix:** correct evidence:58, and open a ticket for the product-detail stock surface (in-transit invisible at the destination; separately, `ProductController.php:1091` defaults a quantity to the scale-2 literal `'0.00'`, against the rule-19 quantity contract).

- **[MAJOR M3] `apps/api/app/Modules/Replenishment/Presentation/Requests/CreateTransferFromRequestsRequest.php:30` — the same denial now has two API contracts.** `/api/v1/stock-transfers` converts a lone source-access failure into **403 `LOCATION_ACCESS_DENIED`** (`StoreStockTransferRequest.php:38-57`); the new guard on `/api/v1/replenishment-requests/actions/create-transfer` returns a bare **422 `VALIDATION_ERROR`**. Both are "dispatch a transfer from a source location the caller cannot see". The brief asked for 422, so the lane complied — but two sibling surfaces answering the same denial differently is a one-surface-per-concept drift, and the new test asserts only `assertUnprocessable()` (`ReplenishmentEdgeCasesTest.php:174`) without pinning the code or the field, so the contract is unpinned in either direction. **Fix:** assert `error.code` and the `source_location_id` field in the test, and record the divergence (or align on 403) in the T-2/T-3 spec.

- **[MAJOR M4] convention 09 gap for the production fix — no second-company pin on the guarded endpoint.** The new rule runs BEFORE the pre-existing company guard (`ReplenishmentActionController.php:139-144`), and `LocationContext::canAccessLocation` (`LocationContext.php:224-239`) never checks the location's `company_id` — an unrestricted user passes ANY location UUID and is stopped only by the later controller guard. Today the outcome is still 422, but nothing pins that ordering for this endpoint, so a future refactor that moves or drops `assertLocationCompany` would let a cross-company source through validation unnoticed. **Fix:** add to `ReplenishmentEdgeCasesTest` a company-B `source_location_id` → 422 "Location does not belong to the active company", with `StockTransfer::count() === 0`.

### Minor

- **[MINOR m1] ticket `docs/superpowers/tickets/2026-09-09-t1-settlement-replay.md` — severity and reachability overstated, and the reachable variant is missing.** `SettleRequestsOnTransferInitiated` is registered as a **synchronous** listener (`apps/api/app/Modules/Replenishment/Providers/ReplenishmentServiceProvider.php:19`) and does not implement `ShouldQueue`, so an event replay is not reachable in production today; the ticket is marked "severity: high". Meanwhile the reachable defect the same code carries is unstated: `SettleRequestsOnTransferInitiated.php:46-71` settles **every** open request at the grain regardless of quantity or capture time, so an operator creating an ordinary transfer via `/api/v1/stock-transfers` to that destination/product silently marks an unrelated open request Fulfilled against it. **Fix:** record both facts in the ticket so T-2 prioritises the reachable one.

- **[MINOR m2] `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php:129` — method name overstates the pin.** `test_completed_status_precedes_freight_capitalization_inside_transaction` asserts the post-conditions of `complete()` from outside that call; both writes happen inside it, so what is actually pinned is the **denominator** (10 owned units, not 14 — the invariant behind `StockTransferService.php:385-395`), not the write order. That is the valuable invariant; consider renaming to say so.

- **[MINOR m3] the four `getenv('T1_RUN_KNOWN_REDS')` guards can rot silently.** When T-2 fixes a behaviour, the pin stays skipped forever with no signal that it should now be green. **Fix:** list the four method names in the T-2 acceptance criteria, or convert them to expected-failure assertions when that lane opens.

- **[MINOR m4] demo-tenant and worktree residue.** `HANDBACK-T1-2026-09-09.md:55` deliberately retains a synthetic product (`T1-EDGE-092254`, 20 units, `cost_price` 5.500000), transfers `TR-2026-00005..00007` and a merged pending replenishment request of 5.0000 in the shared PharmaBio demo tenant; `:53` notes an empty `apps/api/.env` was created in the worktree. Both can confuse the parallel manual-testing loop (`docs/qa/MANUAL-TESTING-LOOP.md`). **Fix:** delete the fixture or register it in the QA loop doc; note the empty `.env` in the resume recipe (it is gitignored, so it will not propagate on merge).

- **[MINOR m5] `StockTransferCompleteConcurrencyPostgresTest.php:53,67-83` runs without `RefreshDatabase` and hand-cleans nine tables.** It is now named in the live `backend-test-pgsql` allowlist, so its **committed** rows share the CI database with `RefreshDatabase` classes. This matches the `StockTransferIdempotencyCollisionPostgresTest` precedent that the manifest note cites, and the cleanup succeeded on my throwaway DB, but any table written by `receive()` / `recordCostAdjustment` outside that nine-table list will accumulate across runs. Watch the first real CI arming.

### Not findings (checked and clear)

No float on money or quantity anywhere in the diff. No new WAC arithmetic, no rounding to currency scale mid-stream, no new lock ordering, no second stock-movement write path, no device-side decrement, no opening-balance path touched, no `if (batchTracked) continue;`. Movement signs come from the existing `MovementType`/reason enums. No `app()` in production code. No catalogue `unique(['tenant_id', …])` added. No new noun introduced, so no glossary entry is owed.

## What to fix before merge

Add the two current-behaviour pins (M1) and correct the evidence sentence + open the product-stock-levels ticket (M2); M3/M4 and the minors can land as a fix round or as T-2 follow-ups.

# Session B — Lane Q-9 (F1 kitchen/order module gating + terminal-state guard SM-1) — GATE r1, FISCAL/POS LENS (second half)

- **Date:** 2026-08-24
- **Reviewer:** fiscal-pos-reviewer (round 1, SECOND half of the dual gate; primary tenancy/authz half = `docs/superpowers/reviews/2026-08-24-sb-q9-kitchen-gate-r1-tenancy.md`)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb-q9-kitchen-gating`
- **Branch / commits:** `fix/sb-q9-kitchen-order-gating` @ `7cc3842d0` (= `020d0ab55` implementation + `7cc3842d0` tenancy-C-1 fix) on base `fc3330ad4`
- **Diff:** `git diff dev...HEAD` = 12 files, +473/-26 (3 backend prod, 5 backend test, 3 web, 1 factory manifest)
- **Migrations:** none. **New `onQueue`:** none. **`horizon.php`:** untouched. **New fiscal Event class / rename:** none.
- **Brief:** `docs/sessions/session-B-2026-08-23/BRIEF-Q9-kitchen-gating.md`; exploit chain `docs/handoff/TRIAGE-state-machine-audit-2026-08-23.md` §2

---

## Verdict

**VERDICT: spec ✅ + quality APPROVED (with 3 non-blocking tickets)**

On the fiscal/POS lens the lane is clean. The SM-1 guard is correctly placed (inside the transaction, after the
tenant+company-scoped `lockForUpdate`), it is genuinely tested, and I reproduced the whole triage §2 chain over
HTTP on **both** drivers: the exploit is dead and it writes **zero** `fiscal_events`, `pos_receipts` and
`stock_movements` rows. The §14.2 tombstone stays ungated and byte-identical, and the only fiscal writer in the
module (`OrderToReceiptService::convertToReceipt`) remains unreachable from HTTP. The **Tauri device calls none
of the 12 gated routes** — the gate cannot 403 a POS terminal.

No condition blocks merge. Three residuals below belong on the LEDGER, one of which (Finding 1) is a real
operator-visible regression on the KDS that should be ticketed *with* this merge, not after it.

### C-1 discharge (tenancy gate r1) — CONFIRMED

`7cc3842d0` changes `tests/Feature/POS/PosStabilizationTenantIsolationTest.php:220` from `Vertical::Restaurant`
to `Vertical::CoffeeShop` and rewrites the comment at :204-214. Recomputed independently from
`apps/api/config/verticals.php` (the SoT):

```
coffee_shop.default_modules   = Identity, Tenant, Catalog, Menu, Partner, Sales, Treasury, Accounting, CompositeItems
coffee_shop.compatible_extras = Tables, Loyalty, Inventory          → Loyalty ✅ AND Inventory ✅  (reconcile-stable)
Menu ∈ coffee_shop.default_modules                                  → the gated cluster is reachable ✅

retail.default_modules        = Identity, Tenant, Catalog, Partner, Sales, Inventory, Treasury, Accounting
OLD fixture (retail + Loyalty)      = the above + Loyalty                                  (9 modules)
NEW fixture (coffee_shop + Loyalty,Inventory) = OLD ∪ {Menu, CompositeItems}               (11 modules)
set-difference OLD \ NEW = ∅                                        → strict superset ✅
```

Both conditions the tenancy reviewer prescribed hold. **Delta-neutrality also verified empirically:** the
isolation suite on PostgreSQL at `7cc3842d0` returns `Tests: 49, Assertions: 154, Errors: 5, Skipped: 8` — the
exact same numbers the tenancy reviewer recorded at `020d0ab55`, and the same 5 pre-existing `shift_number`
errors, class-for-class. The fixture swap changed nothing but the vertical.

Tenancy C-2 (missing backend `module:Tables`) and C-3 (`shift_number` PG reds) remain open tickets; I add a
fiscal-lens caveat to C-2 in Residual R-3 below.

---

## Findings

### 1. [IMPORTANT — non-blocking, TICKET] `apps/web/src/features/pos/hooks/useKitchenOrders.ts:33-56` + `apps/web/src/features/pos/pages/KitchenDisplayPage/KitchenDisplayPage.tsx:21-27` — the new 422 refusal is swallowed silently on the Kitchen Display; the operator gets a dead button with no feedback and a stale card

**What's wrong.** The guard's refusal reaches the client as `422 {"message":"Order lines cannot be updated on a
cancelled order."}` (verified live — see Gate verified §A). Neither `useUpdateLineStatus` (:33-47) nor
`useBumpOrder` (:49-56) declares an `onError`; both invalidate **only** `onSuccess`. The page fires
`updateLineStatus.mutate({...})` / `bumpOrder.mutate(orderId)` bare at :22 and :26 with no error branch, and
there is **no global fallback** — `apps/web/src/lib/queryClient.ts:3-15` configures only `staleTime`/`retry`,
with no `MutationCache` `onError`. So the rejected mutation dies in the mutation's error state and renders
nothing.

**Why it matters.** There is a real window in which a kitchen operator hits this. `KitchenDisplayController::index`
(`apps/api/…/KitchenDisplayController.php:34-41`) filters to `SentToKitchen` + `Ready`, so a cancelled order
leaves the feed — *but only on the next fetch*. There is **no `OrderCancelled` event at all** in
`app/Modules/POS/Domain/Events/` and `BroadcastPosEventsListener::subscribe()` (:114-121) broadcasts only
`TerminalActivated`, `OrderSentToKitchen`, `OrderLineStatusChanged`, `OrderReady` — so a cancel emits **no
realtime KDS signal**. The card therefore lingers on the kitchen screen for up to the 30 s poll interval
(`useKitchenOrders.ts:29`), and every tap on it during that window is now a silent no-op.

**Not a reason to hold the merge:** before this lane the same tap returned 200 and *corrupted the order*. Silent
refusal strictly beats loud corruption. But it is a new operator-visible dead end on a live F&B surface.

**Suggested fix (follow-up lane).** (a) add `onError` to both mutations that surfaces a toast and
`invalidateQueries(kitchenKeys.orders())` so the stale card self-heals; (b) pair with Finding 2 so the client can
tell "order is gone" from "invalid transition". Note the silent-swallow *class* is pre-existing — a double-tap
on an already-`ready` line has always 422'd through the same dead channel
(`OrderManagementService::validateLineStatusTransition` :717-725, `Ready` → `default => []`).

### 2. [MINOR] `apps/api/app/Modules/POS/Presentation/Controllers/KitchenDisplayController.php:85-86`, `:115-116`, `:145-146` — the KDS 422 is a bare `{"message": …}`, not the house `{error:{code,…}}` envelope, so the refusal is not machine-readable

**What's wrong.** All three KDS catch blocks do `response()->json(['message' => $e->getMessage()], 422)`. Its
sibling `OrderController` uses the house envelope on the identical exception type —
`SEND_TO_KITCHEN_FAILED` (:343), `CLOSE_ORDER_FAILED` (:383), `CANCEL_ORDER_FAILED` (:425) — and the framework
validation handler does too (I observed `{"error":{"code":"VALIDATION_ERROR",…}}` live from `POST /pos/orders`).

**Why it matters.** The refusal is typed only in English prose. A KDS client cannot distinguish
`ORDER_TERMINAL` from `INVALID_LINE_TRANSITION` without string-matching, which blocks the clean fix for
Finding 1 (refetch-on-terminal vs toast-on-invalid) and cannot be translated (rule 11). Pre-existing shape; the
lane routes a *new* refusal reason into it.

**Leak check — clean.** The refusal body is exactly
`{"message":"Order lines cannot be updated on a cancelled order."}` — **no order payload, no ids, no line
data** (the success branch's `OrderResource` at :63-77 is never reached). The only disclosure is the order's own
status word, to an already-authenticated, already-tenant-scoped, `pos.operate_terminal`-holding caller. And
because the guard sits **after** `firstOrFail()` on the tenant+company-scoped query, a *foreign* order still
404s rather than 422s — the guard adds no existence oracle.

**Suggested fix.** `['error' => ['code' => 'ORDER_TERMINAL_STATE', 'message' => …]]`, mirroring `OrderController`.
Ticket with Finding 1 — they are one lane.

### 3. [MINOR] `apps/web/src/features/pos/pages/PosHubPage.tsx:28-62` — the POS hub still offers Orders/Tables/Kitchen tiles on a non-Menu vertical; the Orders tile is newly a dead link

All three F&B cards gate on `permissionModule: 'pos'` (:34, :41, :48) and are filtered by `canAccessModule('pos')`
at :69-74 — a **permission**-module check, unrelated to the `CompanyConfig.hasModule('Menu')` gate the lane
added. On a retail/parapharmacy tenant with POS permissions the hub therefore still renders "Orders", "Tables"
and "Kitchen"; clicking Orders now hits `<ModuleGuard module="Menu">` (`apps/web/src/routes/index.tsx:2974-2986`)
which `<Navigate to="/dashboard" replace/>`s (`apps/web/src/components/guards/ModuleGuard.tsx:70-72`) — a silent
bounce to the dashboard. **Kitchen has been in exactly this state since before the lane** (it was already
`ModuleGuard`-wrapped at :3229), so this is a pre-existing pattern the Orders tile now joins, not a new class of
defect — and it is not a security hole (route guard + backend gate both hold). Fix: `permissionModule` → a
`module: 'Menu'` field on those three cards, same as `Sidebar.tsx:236/238`. Low priority.

### 4. [MINOR] `apps/api/tests/Feature/Security/PosOrderKitchenModuleAccessControlTest.php:232-241` — the "no spurious `OrderReady`" half of the exploit is asserted by proxy, not by `Event::fake`

The class proves the state outcome (`status` still `Cancelled` :237, `ready_at` null :238, `cancelled_at` intact
:239, line still `Sent` with null `prepared_at` :240-241) but never asserts the **broadcast** half that the
implementer's own doc-block calls out (`OrderManagementService.php:546` — "broadcasting a spurious OrderReady").
The proxy is sound — `OrderReady::dispatch` lives *inside* the same `if` block as the `ready_at` write
(`OrderManagementService.php:748-756`), so a null `ready_at` implies no dispatch — but one
`Event::fake([OrderReady::class])` + `Event::assertNotDispatched(OrderReady::class)` would pin it directly.
**I ran that assertion myself and it passes on both drivers** (Gate verified §A), so this is a durability note on
the committed suite, not a gap in the fix.

### 5. [INFORMATIONAL — the strongest justification for the guard, not currently stated] the `Closed` half of `isActive()` protects a **receipted** order

`OrderStatus::isActive()` (`app/Modules/POS/Domain/Enums/OrderStatus.php:32-38`) = `Open`, `SentToKitchen`,
`Ready` → true; `Closed`, `Cancelled` → false. The brief and the commit message argue the `Cancelled` case. The
`Closed` case is the fiscally sharper one: a `Closed` order carries a populated `receipt_id`
(`OrderManagementService::closeOrder` :439-443). Pre-guard, a line PATCH on such an order would have flipped it
to `Ready`, which (a) re-admits an already-receipted order into the KDS feed (`KitchenDisplayController.php:34-41`
selects `Ready`), (b) makes it `bump`-able (`bumpOrder` :670 accepts `Ready`) and `served`-able
(`markOrderServed` → `canBeServed()`), and (c) leaves `ready_at` stamped *after* `closed_at` on a row that
already points at a projected receipt. Nothing in that path re-authors a device fact, so it was never a
hash-chain integrity issue — but it was an order/receipt state divergence, and the guard closes it. Only
historical rows can be `Closed` today (the close route is the §14.2 tombstone), which is why it is
informational. Worth one sentence in the deploy note.

### 6. [INFORMATIONAL] `Ready` → `Ready` is a **refusal**, not an idempotent no-op — and that is correct, unchanged behaviour

The task asked which the brief intends. `isActive()` returns true for `Ready`, so the new guard **permits** the
call; the pre-existing `validateLineStatusTransition` (:717-725) then throws for `ready → ready` (`match` has no
`Ready` arm → `default => []`), producing 422. Order-level re-stamping is separately blocked by the
`$order->status !== OrderStatus::Ready` term at :748 that the lane preserved. So: line-level = refusal
(pre-existing), order-level = idempotent no-op. The lane did not change either; both are the right posture. It
does mean a double-tap on the KDS 422s silently — see Finding 1.

---

## Device-path ruling (task item 3)

**RULING: the Tauri POS device (`apps/pos`) calls NONE of the 12 gated routes. The gate cannot produce a 403 on
a retail (IziPOS) terminal. No regression; no action required.**

Evidence — exhaustive greps of `apps/pos/src`:

- `grep -rn "pos/orders" apps/pos/src` → **0 hits**.
- `grep -rn "pos/kitchen" apps/pos/src` → **0 hits**.
- `grep -rn "kitchen" apps/pos/src` → 3 files, all non-API: `src/locales/{en,fr}/pos.json` and
  `src/pages/SettingsPage.tsx:188,779-784` (`settings.kitchenPrinter` — a **local printer** preference, not a
  server call).
- Full enumeration of every `/pos/…` path the device requests (`operatorStore.ts`, `terminalStore.ts`,
  `syncService.ts`, `refundSettlementService.ts`, `shiftReconcile.ts`, `scopedManagerPin.ts`,
  `pendingCustomerSyncService.ts`): `auth/verify-pin`, `auth/setup-pin`, `auth/has-pins`, `auth/pin-data`,
  `auth/sync-pins`, `verify-manager-pin`, `terminals/*`, `shifts/*`, `receipts/{id}`, `receipts/{id}/return`,
  `receipts/qr-index`, `sync/fiscal-events`, `reports/z/sync`, `cash-drawer/*`, `audit-events/sync`,
  `customers/pending`, `vouchers/sync`, `voucher-ledger/sync`, **`floors`**. Not one is inside the
  `module:Menu` group. The device's F&B touchpoint is `/pos/floors` (`syncService.ts:1812`, `pullTables`),
  which this lane deliberately did **not** gate.
- `apps/web` — beyond the Orders page, the only consumers of gated paths are `features/pos/api/orderApi.ts`
  (:126-210) and `features/pos/api/kitchenApi.ts` (:21-49), both reached exclusively from the two
  `ModuleGuard module="Menu"`-wrapped routes (`routes/index.tsx:2974-2986`, `:3227-3241`). The only
  un-mirrored web surface is the hub tile set — Finding 3.

**Operational consequence, unchanged from the tenancy record:** the gate is a live behaviour change for
retail/parapharmacy *browser* users who had reached `/pos/orders` (permission-only until now). Per the module
SoT they should never have had it. Deploy note should say so.

---

## Gate verified

### A. SM-1 exploit chain — walked end-to-end over HTTP, both drivers

I wrote a throwaway probe **outside the repo** (scratchpad; deleted after the run; **no file in the worktree was
modified**) that walks the full triage §2 chain through the real HTTP stack on a `coffee_shop` tenant:
`POST /pos/orders` → `POST /pos/orders/{id}/lines` → `POST /pos/orders/{id}/send-to-kitchen` →
`POST /pos/orders/{id}/cancel` → `PATCH /pos/kitchen/orders/{id}/lines/{lineId}/status {status:"ready"}`.

Result, **identical on sqlite and PostgreSQL 16** (`OK, 16 assertions`):

```
[WALK] exploit status=422 body={"message":"Order lines cannot be updated on a cancelled order."}
[WALK] fiscal_events=0 receipts=0 stock=0 (unchanged)
```

with all of the following asserted and green:

| Probe | Result |
|---|---|
| HTTP status of the exploit call | **422** (4xx ✅) |
| `Event::assertNotDispatched(OrderReady::class)` | ✅ no spurious `OrderReady` |
| `pos_orders.status` | still `Cancelled` ✅ |
| `pos_orders.ready_at` | **null** — no `ready_at` next to `cancelled_at` ✅ |
| `pos_orders.cancelled_at` | still populated ✅ |
| `pos_order_lines.status` | still `sent` — line untouched ✅ |
| `pos_order_lines.prepared_at` | **null** ✅ |
| `COUNT(fiscal_events)` before/after | 0 → 0 ✅ |
| `COUNT(pos_receipts)` before/after | 0 → 0 ✅ |
| `COUNT(stock_movements)` before/after | 0 → 0 ✅ |

### B. Guard placement, concurrency, and terminal-set enumeration

- **Inside the transaction, after the scoped lock:** `OrderManagementService.php:554` opens `DB::transaction`,
  :556-561 loads the order under `where('tenant_id')->where('company_id')->where('id')->lockForUpdate()->firstOrFail()`,
  and the guard is :563-571 — *then* the line lookup at :574. **A concurrent cancel cannot slip between check
  and write:** `cancelOrder` (:483-490) takes `lockForUpdate()` on the same row inside its own transaction, so
  the two serialise on the row lock; whichever loses reads the committed `Cancelled` status and refuses.
- **Terminal set enumerated** — `OrderStatus::isActive()` (`OrderStatus.php:32-38`): `Open` ✅ active,
  `SentToKitchen` ✅ active, `Ready` ✅ active, `Closed` ❌ terminal, `Cancelled` ❌ terminal. Correct and
  exhaustive (`match` over all 5 cases, no `default`, so a future case is a compile-time error). See Findings 5
  and 6 for the `Closed` and `Ready` semantics.
- **Defence in depth at :748** — `$allReady && $order->status->isActive() && $order->status !== OrderStatus::Ready`.
  `checkAndTransitionOrderToReady` has exactly one caller (:592), so it is genuinely redundant today; retaining
  it is the right posture for a private helper that writes `ready_at`.
- **Regression check on the siblings — all still guard correctly, all unchanged by the lane:**
  `bumpOrder` :670 (`in_array($order->status, [SentToKitchen, Ready], true)` — refuses `Cancelled`/`Closed`/`Open`),
  `markOrderServed` :623 (`canBeServed()`), `addLine` :217 / `modifyLine` :287 / `removeLine` :345
  (all `! $order->isOpen()` — *stricter* than the new guard, correctly so). `updateLineStatus` was the only
  entry point with no order-level state check; that asymmetry is now closed.
  Live-probed at `PosOrderKitchenModuleAccessControlTest.php:261-280`: bump → 422, served → 422 on a cancelled
  order, and the happy path at :244-259 still transitions an **active** order to `Ready` with `ready_at` set.

### C. Fiscal blast radius — nil, verified three ways

1. **Static:** `grep -n "Stock|FiscalEvent|Receipt|Shift|fiscal_"` over `OrderManagementService.php` returns only
   the `Shift` **read** at :91-95 (createOrder resolving the open shift) and the `closeOrder` receipt block at
   :419-443. `KitchenDisplayController.php` returns **zero** hits. Neither `updateLineStatus`,
   `checkAndTransitionOrderToReady`, `bumpOrder` nor `markOrderServed` touches a receipt, fiscal event, stock
   movement, shift or Z row.
2. **Event graph:** `OrderLineStatusChanged` (:595) and `OrderReady` (:693, :755) have exactly one subscriber —
   `BroadcastPosEventsListener::subscribe()` :114-121 — which only `broadcast()`s
   (`OrderLineStatusChangedBroadcast`, `OrderReadyBroadcast`, both `ShouldBroadcastNow`). No projection, no
   queued job, no GL/stock listener. Orders are pre-receipt, exactly as the brief asserts.
3. **Empirical:** the row-count deltas in §A on both drivers.
4. **No `fiscal_events` writer is reachable from any gated route.** The only fiscal writer in the module is
   `OrderToReceiptService::convertToReceipt`, called from exactly one place —
   `OrderManagementService::closeOrder` :437, itself called from exactly one place —
   `OrderController::close` :370 — **which is bound to no route**. `php artisan route:list` shows
   `POST api/v1/pos/orders/{id}/close → Closure`, not the controller.

### D. §14.2 tombstone — ungated and unchanged

`route:list` at HEAD, filtered to `pos/(orders|kitchen)` — **12 routes carry
`App\Http\Middleware\RequireModule:Menu`, the 13th is the tombstone and carries none:**

```
GET|HEAD api/v1/pos/kitchen/orders                                 RequireModule:Menu
POST     api/v1/pos/kitchen/orders/{orderId}/bump                  RequireModule:Menu
PATCH    api/v1/pos/kitchen/orders/{orderId}/lines/{lineId}/status RequireModule:Menu
POST     api/v1/pos/orders                                         RequireModule:Menu
GET|HEAD api/v1/pos/orders                                         RequireModule:Menu
GET|HEAD api/v1/pos/orders/{id}                                    RequireModule:Menu
POST     api/v1/pos/orders/{id}/cancel                             RequireModule:Menu
POST     api/v1/pos/orders/{id}/lines                              RequireModule:Menu
PATCH    api/v1/pos/orders/{id}/lines/{lineId}                     RequireModule:Menu
DELETE   api/v1/pos/orders/{id}/lines/{lineId}                     RequireModule:Menu
POST     api/v1/pos/orders/{id}/send-to-kitchen                    RequireModule:Menu
POST     api/v1/pos/orders/{orderId}/served                        RequireModule:Menu
POST     api/v1/pos/orders/{id}/close        → Closure             NONE  ← tombstone, by design
```

The tombstone body (`routes_orders.php:60-67`) is **unchanged** by the diff — only comment lines :55-59 were
added around it — and still returns `410 {"error":{"code":"NEW_SALE_AUTHORING_RETIRED", …}}`. Pinned on a
**non-Menu** vertical at `PosOrderKitchenModuleAccessControlTest.php:194-204` (parapharmacy → 410, not 403).
The gate boundary is drawn at `routes_orders.php:24` (`Route::middleware('module:Menu')->group`) which closes at
:35, deliberately excluding the tombstone; `routes_kitchen.php:23` gates its whole group inline.

### E. Rules 19/20 sweep on the diff — clean

`git diff dev...HEAD | grep '^+'` matched **zero** occurrences of `(float)`, `parseFloat`, `Number(`,
`onQueue`, `toISOString`, `bcformat`, or a no-arg `getScale()`. No migration, no `config/horizon.php` change, no
new named queue, no SQLite time-boundary binding, no device-authored shift-field rehydration, no new/renamed/
restructured fiscal Event class (rule 8), no per-line TTC-vs-HT assertion. The new test's money/quantity
fixtures are strings at the correct scales (`'10.000'` money, `'1.000'` qty, `'8.403'`/`'1.597'` at scale 3 —
`PosOrderKitchenModuleAccessControlTest.php:303-348`). The one `toISOString()` in the touched call graph is
`KitchenDisplayController.php:74` (`prepared_at?->toISOString()`) — an **outbound JSON response field**, not a
SQLite comparison boundary; pre-existing and out of scope of the rule-20 trap.

### F. Test execution — BY PATH ONLY (never the full suite)

| Driver | Files | Result |
|---|---|---|
| sqlite | `Security/PosOrderKitchenModuleAccessControlTest` + `POS/OrderManagementTest` + `POS/KitchenDisplayTest` | **OK 38 tests, 157 assertions, 1 skipped, 0 failures** (7 / 18 / 13) |
| sqlite | `Fiscal/NewSaleServerAuthoringDispositionTest` + `POS/PosStabilizationTenantIsolationTest` | **OK 60 tests, 202 assertions, 8 skipped, 0 failures** (11 / 49) |
| sqlite | `Fiscal/PosCoreReceiptProjectionTest` + `Fiscal/ChokepointCompletenessTest` + `Fiscal/FiscalEventsImmutabilityTest` + `Fiscal/ReceiptChainRebuildTest` | **OK 80 tests, 441 assertions, 18 skipped, 0 failures** |
| sqlite | throwaway `Q9FullChainWalkTest` (my probe, outside the repo) | **OK 1 test, 16 assertions** |
| **PG 16** (`autoerp_gate_q9f` @ 127.0.0.1:5433, **DROPPED** after the run) | `Security/PosOrderKitchenModuleAccessControlTest` | **OK 7 tests, 32 assertions** |
| **PG 16** | `POS/OrderManagementTest` + `POS/KitchenDisplayTest` + `Fiscal/NewSaleServerAuthoringDispositionTest` | **OK 42 tests, 156 assertions, 1 skipped** |
| **PG 16** | `POS/PosStabilizationTenantIsolationTest` | **49 tests, 154 assertions, 5 errors, 8 skipped** — see below |
| **PG 16** | throwaway walk probe + the 4 fiscal-chain suites | **OK 81 tests, 465 assertions, 3 skipped** |

`NewSaleServerAuthoringDispositionTest` path (task asked me to find it): `apps/api/tests/Feature/Fiscal/NewSaleServerAuthoringDispositionTest.php`.

**The 5 PG errors: PRE-EXISTING, zero delta — confirmed independently.**
`git diff dev...HEAD -- tests/Feature/POS/PosStabilizationTenantIsolationTest.php | grep -c shift_number` → **0**,
and the failing classes are exactly the 5 the tenancy reviewer recorded at `020d0ab55`
(`test_cancel_order_refuses_cross_tenant_order_id`, `test_send_to_kitchen_refuses_cross_tenant_order_id`,
`test_remove_line_refuses_cross_tenant_order_id`, `test_kitchen_bump_refuses_cross_tenant_order_id`,
`test_cancel_order_lookup_includes_tenant_and_company_predicates`) with identical totals
(49 / 154 / 5 / 8). The `7cc3842d0` fixture swap moved nothing. Root cause is a `shift_number` string bound into
an integer column; the lane's own new class binds `'shift_number' => 1` correctly
(`PosOrderKitchenModuleAccessControlTest.php:302`). Tenancy C-3 ticket stands, unchanged.

**Fiscal-chain no-movement evidence:** all 4 fiscal suites are green on **both** drivers, and notably the
PostgreSQL run skips only 3 (vs 18 on sqlite) — i.e. the PG-only fiscal assertions that sqlite masks *did* run
and *are* green. No fiscal write path moved.

### G. Static analysis / style

- **PHPStan L8** on the 3 changed backend prod files + both changed test classes: **9 errors, ALL of them
  pre-existing and ALL in `tests/Feature/POS/PosStabilizationTenantIsolationTest.php`** —
  8 × `deadCode.unreachable` at :677, :695, :713, :736, :759, :778, :905, :1663 (the §14.2 `markTestSkipped`
  blocks) plus `method.unused` for `seedCompositeItem()` at :934. The lane's diff to that file touches **only**
  :204-222 (`makeTenant`), nowhere near any reported line, so none is attributable. **Zero errors in
  `OrderManagementService.php`, `routes_orders.php`, `routes_kitchen.php` and the new
  `PosOrderKitchenModuleAccessControlTest.php`** — matching the tenancy record's `[OK] No errors` on that set.
- **Pint `--test`** on all 8 changed backend files: `{"result":"pass"}`.

### H. Manifest statement

`php apps/api/tools/feature-lane-manifest-check.php` from the worktree root → **exit 0**:
"1382 Feature classes in 74 groups; every group has a disposition; every declared lane is present in ci.yml;
every --filter entry is anchored and uniquely matched against 1771 test classes across all suites." The gated
figure it reports at HEAD is **70 groups / 1147 classes parked behind the execution gate**, plus the
pre-existing 1-group/1-class coverage-debt warning.

**No gated ceiling moves, by construction:** `tests/feature-lane-manifest.json` is **not in the lane diff**
(the only manifest file in the diff is `scripts/factory/manifests/routes-web.yaml`, one line), and the single
new class lands in the `Security` group whose lane `security-regression` is a **whole-directory selector on a
job with no `if:` guard** — i.e. ungated, running on every PR→dev from the moment it lands. (The group's
`"classes": 18` note in the manifest is the earlier membership-offboarding lane's informational count, not
this lane's; the checker treats live-lane counts as informational and exits 0.)

⚠ **One number to reconcile at the parent level:** the task line pins dev's gated figure at **1163**; the
checker reports **1147** in this worktree. The lane cannot account for a delta in either direction (it adds no
gated class and edits no manifest), so this is a base-commit difference between the parent's reference point
and `fc3330ad4`. Worth a one-line confirm before the batch promotion, not a lane finding.

### I. Scope

Nothing on Session A's collision matrix: no X/Z surfaces, no PIN / `has_pins`, no VAT resolution, no
`StockLevel`, no web document pages. The two shared web files (`routes/index.tsx`, `Sidebar/Sidebar.tsx`) are
the two pre-announced in OWNER-SHEET §E. Working tree clean apart from the stray untracked `node_modules`
(ignored per instructions). **Nothing was modified by this review except this record**; my probe test lived in
the scratchpad and was deleted, the throwaway PG database `autoerp_gate_q9f` was dropped, and `pgrep` confirms
no phpunit/vitest processes survive.

---

## Residuals for the LEDGER

- **R-1 (from Findings 1 + 2, one lane).** KDS refusal is silent and untyped: add `onError` +
  `invalidateQueries` to `useUpdateLineStatus`/`useBumpOrder`, and convert
  `KitchenDisplayController.php:85-86/:115-116/:145-146` to the house `{error:{code,message}}` envelope
  (`ORDER_TERMINAL_STATE` vs `INVALID_LINE_TRANSITION`). Consider a `OrderCancelled` broadcast so the KDS card
  clears in realtime instead of after ≤30 s.
- **R-2 (Finding 3).** `PosHubPage.tsx:28-62` — move the Orders/Tables/Kitchen tiles from `permissionModule: 'pos'`
  to a `hasModule('Menu')` check so the hub stops offering a link that bounces to `/dashboard`.
- **R-3 (fiscal caveat on tenancy C-2).** When the missing backend `module:Tables` gate is finally added, it
  **must not** be applied naively to `/pos/floors`: the **Tauri device** pulls that endpoint
  (`apps/pos/src/lib/sync/syncService.ts:1812`, `pullTables`) and `coffee_shop` has `Tables` only as a
  *compatible extra*, not a default (`config/verticals.php`). A bare `module:Tables` there 403s the table-layout
  sync on every coffee-shop terminal. Attach this note to the C-2 ticket before it is picked up.
- **R-4 (Finding 4, cheap).** Add `Event::fake([OrderReady::class])` + `assertNotDispatched` to
  `PosOrderKitchenModuleAccessControlTest::test_a_cancelled_order_cannot_be_flipped_back_to_ready_by_a_line_status_update`.
- **R-5 (deploy note, Finding 5).** State out loud that (a) the order/kitchen cluster is newly 403 for every
  non-F&B tenant, and (b) the guard also protects legacy `Closed` (receipted) orders from being resurrected into
  the KDS feed.
- **R-6.** Carry over tenancy C-2 (no backend `module:Tables` anywhere) and C-3 (`shift_number` PG reds on the
  isolation suite) unchanged — both re-confirmed by this run.

---

**What to fix before merge:** nothing blocking — merge as-is, and file R-1 (silent + untyped KDS refusal) in
the same batch so the F&B operator surface does not ship a dead button.

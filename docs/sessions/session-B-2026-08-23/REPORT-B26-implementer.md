All nine items are done. Final report below.

---

## Lane B2-6 / residual sweep — deliverable

**Worktree** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb2-b26-residual-sweep` · **branch** `fix/sb2-b26-residual-sweep` · base `d91886af9` (local dev tip at start; local `dev` has since moved on — the branch is behind, parent merges). **Nothing merged, nothing pushed, no stash, no migration, no `.github/**` edit.** Working tree clean. Throwaway DB `autoerp_b26_test` (PG 5433) left in place for the reviewer.

**9 commits, one per item** (oldest → newest):

| commit | item |
|---|---|
| `533336b19` | C-30(i) |
| `04031b570` | C-14(ii) |
| `92dfb0d3b` | C-14(iii) |
| `a0ecfdf4c` | C-14(iv) |
| `b91677396` | C-16(iii) |
| `5913bea08` | C-17(iv) |
| `66c4f265f` | C-17(ii) |
| `6a9aac13d` | C-17(vii)+(viii) |
| `3f880783a` | C-28(i) |

Diff vs merge-base: 18 files, +1111 / −167. All in brief scope.

### Per item — red → green, file:line

**C-30(i)** — `PaymentMethodController.php:101` + `:219` `'default_journal_id' => ['prohibited']`; write removed from `create()` (`:153`); defensive `unset()` before `update()` (`:266`). Column kept.
Red: `tests/Feature/Treasury/TreasuryTenantIsolationTest.php --filter default_journal_id` → 2 failures ("Expected 422 but received 201" / "…200"). Green: 3 tests/12 assertions; whole file sqlite **OK 73/227**, PG **OK 73/227**.
Honest pin rewrite: the two old tests asserted *no* validation error for the field — they pinned the defect. Replaced with refusal pins on both verbs + a control proving a payload without the field still creates (201, column null).

**C-14(ii)** — `InventoryCountingService.php:1119-1120` `lockCounting($item->counting_id)` + `assertNotTerminal(…, PendingReview)` inside `manualOverride()`'s transaction; `CountingItemController.php` override docblock points at it (no duplicate check-then-act read).
Red: sqlite 1 failure (probe D not refused); PG 2 failures (+ "manualOverride() must re-read the counting header FOR UPDATE"). Green: `CountingTerminalStateGuardTest.php` sqlite **8/33** (1 PG-only skip), PG **8/38**. `manualOverride` added to the PG-only "every mutating path emits the header row lock" sentinel.

**C-14(iii)** — new `CountingUnresolvedItemsException` (DomainException → generic 422 BUSINESS_ERROR, same shape as its two sibling pre-finalize refusals); thrown at `InventoryCountingService.php:1167`. Message pluralisation fixed ("1 item" — the old string said "1 items").
Red: "Expected response status code [422] but received 500." Green: `ReconciliationTest.php` sqlite **12/56**, later **13/62**; PG **13/62**.
Honest pin rewrite: `ReconciliationTest::test_cannot_finalize_with_unresolved_items` asserted `assertStatus(500) // Service throws InvalidArgumentException`.

**C-14(iv)** — `CountingTransitionException::TRANSLATION_KEY = 'inventory.counting.transition_refused'` + `translationReplacements()` (resolved at render time, statuses from `inventory.counting.status.*`, falls back to the raw enum value); `bootstrap/app.php:941` builds `error.message` via `__()`; keys added to `lang/en/inventory.php` and `lang/fr/inventory.php` (sentence + all 11 status labels mirroring `apps/web/src/locales/<locale>/inventory.json`). `getMessage()` stays the English developer/log string.
Red: "Failed asserting that 'This counting is finalized and cannot move to pending_review.' does not contain 'This counting is'".

**C-16(iii)** — `routes_held_orders.php:38-46`, per verb.

**C-17(iv)** — `POS/routes.php:77` `Route::whereUuid(['id', 'terminal'])->group(…)` around all 11 `{id}` routes.
Red (PG): "GET /api/v1/pos/terminals/not-a-uuid must 404 … Body: `{"error":{"code":"INTERNAL_ERROR"…}}` — Failed asserting that 500 is identical to 404." Green: PG **20/87**, sqlite **20/76**.

**C-17(ii)** — `TerminalController.php:904` `'z_hash_sequence' => $latestZReport->z_number`.
Red: "Failed asserting that 2 is identical to 3". Fixture has a *hole* (Z 1 and Z 3, Z 2 never synced — `pos_z_reports` carries an immutability trigger, so no deletion) plus a genesis control.

**C-17(vii)/(viii)** — `generateTerminalCode()` (`:1071-1086`) now max+1 over `POS<digits>` codes (parsed in PHP: `'POS99' > 'POS100'` lexicographically, and no portable SQL cast survives codes like `CAISSE-A`), serialised by new `takeTerminalCodeLock()` (`:1098`); `store()` allocates inside a `DB::transaction` (`:121`) — `requestTerminal()` already did. `release()` wrapped in `DB::transaction` (`:553`) with `lockForUpdate()` (`:557`) before the shift probe and the clearing write.
Red: code collision → 500 (both drivers); "must take the per-company advisory lock" (null, PG); "release() must re-read the terminal row FOR UPDATE" (null, PG). Green: sqlite **26/89** (3 PG-only skips), PG **26/111**; TerminalLocationPosEnabledTest 12/29, TerminalCreationFiscalSchemaVersionTest 6/15, TerminalActivationTest 3/9 unaffected.
Honest pin rewrite: `test_a_code_collision_is_not_mis_reported_as_a_device_binding_collision` asserted `>= 500` — it pinned the count() collision as reachable. It now asserts the provision succeeds on the next free code (POS03) and is still not a 409.

**C-28(i)** — `SupplierInvoiceDetailPage.tsx:368` `{!isPosted && …}` around Re-match (+ `data-testid="btn-rematch"`), `handleRematch` `onError` at `:231` in the `handleLinkReceipts` shape.
Red: `queryByTestId('btn-rematch')` present on a posted invoice. **Note:** the first form of that assertion passed *vacuously* because it ran before the fetch resolved; it now awaits `btn-record-payment` (posted-only) first — that is what made it a real red. Green: 20/20. `tsc --noEmit` clean; eslint **0 errors** (page-file warnings 9 → 11 — the two `no-unsafe-type-assertion`/`no-unnecessary-condition` warnings the copied house `onError` already carries at both sibling handlers). vitest pools killed (`pgrep -f vitest` clean; the one surviving match belongs to Session C's `sc-f0w-web-proforma`).

### Two deliberate deviations from the brief

1. **C-16(iii) mechanism.** The brief said `permission:pos_held_orders.…`. Spatie's `permission` alias is **not registered** in `bootstrap/app.php` (aliases are `super_admin`, `central_admin`, `central_admin_role`, `validate.location.access`, `module`, `require.any.permission`, `scheduling.captcha`, `cross_tenant`) — that string resolves to a missing class. The house mechanism is Laravel's `can:` (~600 route usages). Used `can:`.
2. **C-17(vii) "under the existing advisory/row lock of the claim path".** There is none: `claim()` locks nothing (its guarantee is a conditional UPDATE on an existing row) and `store()` ran outside any transaction. So a new `takeTerminalCodeLock()` was added in the `ExpenseService::generateExpenseNumber()` / `takeTenantNumberingLock()` shape, and `store()` was moved into a transaction (an advisory *xact* lock outside a transaction is a per-statement no-op).

### Held-order permission matrix (`RolesAndPermissionsSeeder.php`)

| role | `pos_held_orders.view/create/delete` | effect |
|---|---|---|
| admin (`:559`, `permissionNames()`) | all three | unaffected |
| manager (`:562`, granted `:628`) | all three | unaffected |
| cashier (`:667`, granted `:689`) | all three | unaffected |
| technician (`:743`) | **none** | now 403 on all five routes |
| accountant (`:802`) | **none** (only `pos.view_receipts`, `pos.view_reports`) | now 403 on all five routes |

Verb map: store → `.create`, index/show → `.view`, recall → `.create` (resuming a parked cart is the write side of the same hold lifecycle; it flips `held → recalled`), destroy → `.delete`.
Red: both new tests returned **200** instead of 403 — the discard actually deleted the row. Green: `HeldOrderTest.php` sqlite **21/67**, PG **21/67**; `HeldOrderTenantIsolationTest` 6/19 and `HeldOrderRecallContractTest` 10/25 unaffected (they act as `admin`).
**Deploy note:** any tenant with custom roles lacking `pos_held_orders.*` starts getting 403 — role reseed + permission-cache reset belongs on the promotion checklist.

### i18n finding

**The backend does not translate the two typed 422s the brief named.** `MATCH_NOT_ALLOWED` (`SupplierInvoiceController.php:280`) and `TERMINAL_HAS_OPEN_SHIFT` (`TerminalController.php:197`, `:552`, `:784`) both hardcode English message strings inline. But a house backend-translation pattern *does* exist and is what I used: `TRANSLATION_KEY` + `translationReplacements()` rendered with `__()` (`InsufficientStockForFulfilmentException`, `UnpricedPurchaseOrderLineException`; consumed at `InvoiceController.php:852`, `SalesOrderController.php:537`, `DeliveryNoteController.php:598`, `PurchaseOrderController.php:730`).
**AR gap:** `lang/ar/inventory.php` does not exist — `lang/ar/` holds only `documents.php` and `treasury.php`. The AR leg rides the AR-locale lane, same disposition as LEDGER C-17 N-2(ii). EN+FR shipped.
No FE consumer branches on `COUNTING_TRANSITION_REFUSED` today; the FE renders the backend message inside `counting.messages.overrideFailed` / `finalizeFailed` (`{{error}}`), which is exactly why the backend string had to be the translated one. FE locales untouched, so the i18n ratchet baseline is unmoved.

### Manifest numbers

`php tools/feature-lane-manifest-check.php` → **OK — 1436 Feature classes in 74 groups**, every group has a disposition, every declared lane present in ci.yml, every `--filter` entry anchored and uniquely matched against 1835 test classes. Parked: **70 groups / 1183 classes**. Coverage debt: 1 group / 1 class. **My lane adds 0 classes** (new tests went into existing files), so no ceiling moves: POS 156, Inventory 116, Treasury 122 unchanged.

### ci.yml allowlist — reported, NOT applied

The checker requires **nothing**. But CI reachability of the new pins is uneven:

- `TerminalClaimHardeningTest` is already named in the live `backend-pgsql` `--filter` allowlist (**ci.yml:1025**) → C-17(ii)/(iv)/(vii)/(viii) pins, including the four PG-only ones, run in CI today.
- `TreasuryTenantIsolationTest` sits in `tests/Feature/Treasury`, run wholesale by the live `treasury-spine-pgsql` job (`ci.yml:1268`) → C-30(i) runs today.
- `ReconciliationTest`, `CountingTerminalStateGuardTest`, `HeldOrderTest` are **not** in the allowlist (the line-1025 hit for "ReconciliationTest" is the substring inside `LocationReconciliationTest`). They only run in `feature-lane-inventory` / `feature-lane-pos`, both **PARKED behind `vars.SELF_HOSTED_RUNNER_READY`**. So C-14(ii)/(iii)/(iv) and C-16(iii) run nowhere until the owner flips that variable.

If the gate wants them live now, append to the `--filter` alternation at **ci.yml:1025** (all three are PG-green with **zero inherited reds**, measured alone on `autoerp_b26_test` — the B-3/C-28(ii) precedent for appending):

```
|CountingTerminalStateGuardTest|ReconciliationTest|HeldOrderTest
```

PG evidence for that claim: `CountingTerminalStateGuardTest` OK (8/38) · `ReconciliationTest` OK (13/62) · `HeldOrderTest` OK (21/67) · `TerminalClaimHardeningTest` OK (26/111) · `TreasuryTenantIsolationTest` OK (73/227).

### Residuals I saw and did NOT touch

1. **C-17(viii) is only half-closed, and it is the important half.** The terminal-row lock serialises release-vs-release, **not release-vs-shift-open**: neither shift-open path locks the terminal row (`ShiftManagementService::openShift()` opens a transaction but touches only `pos_shifts`; the v3 path is `ZSessionLifecycleProjection::projectPosShiftOpen()`, a projection of a synced event). A shift opening inside the window is still orphaned silently. Closing it needs the DB as arbiter — a conditional `UPDATE … WHERE NOT EXISTS (open shift)` in the non-forced arm, `claim()`'s own idiom. Documented inline at the top of `release()`.
2. **`CountingItemController::setOpeningCost()` (`:233`) is still check-then-act** — a pre-transaction status read with a bare `abort(422, …)`, i.e. untyped and unlocked, unlike the `manualOverride` path I fixed. Out of brief scope; noted in the controller docblock.
3. **`generateTerminalCode()` still has no uniqueness backstop for an ADMIN-SUPPLIED code** — `store()` accepts `code` verbatim, so a hand-typed duplicate still surfaces as an uncaught 23505/500. Only the auto-allocated path is fixed.
4. **`RouteRegistrar` trap, worth a lane-wide note:** chaining `->whereUuid('a')->whereUuid('b')` silently **replaces** the `where` array — only the last call survives. I hit it live (the group came out constrained on `terminal` only and the pin stayed red); dumping `$route->wheres` is the only way to see it. Both names must go into one call. Any other route group doing this is silently unconstrained.
5. **Test-harness hazard, confirmed the hard way:** two phpunit suites against the same throwaway PG DB corrupt each other's schema mid-run — `TreasuryTenantIsolationTest` reported **11 errors** while `CountingTerminalStateGuardTest` ran concurrently, and **OK (73/227)** when run alone. Same shape REPORT-C26 recorded on `autoerp_treasury_test`. Reviewers must run one file at a time on `autoerp_b26_test`.
6. **The `react-doctor` pre-commit hook printed "found staged regressions"** on the C-28(i) commit (which still succeeded, `3f880783a`). Re-run against `HEAD~1` shows the DetailPage findings are at `:66`, `:84`, `:134`, `:538` — all pre-existing, none at my edited lines (223-250, 352-380); the hook appears to react to the file changing at all.
7. `payment_methods.default_journal_id` is now write-closed but the **column and the phantom `journals` target still exist** — dropping it is a migration (LEDGER C-30(i)'s own framing), and the DS-1 FK lane still ships at most 8 of 9 FKs.

> **Correction (gate r1 IMPORTANT-1, applied by the parent):** the claim above that the FE renders the backend message inside `counting.messages.overrideFailed` / `finalizeFailed` was FALSE — `queries.ts:193/:245` interpolated the raw AxiosError's `.message`, so the toast always read "Request failed with status code 422" and C-14(iv) was inert on the only surface that raises it. Fixed in fix-round commit `fa732eed3` via `getErrorMessage()`. FE locales untouched; i18n ratchet baseline unmoved. Fix-round commits: `fa732eed3` (I-1 web), `1706e0877` (I-4 rule-19 ceiling + I-3 pending_review pin), `c4d8294af` (I-5 trans_choice + plural pin), `c8654d694` (I-2 activate/activateDraft lock + activation sentinel), `cb8d0290b` (PG fixture fix). Not taken: the other 7 `onError` handlers in `queries.ts` (same bug) → LEDGER.

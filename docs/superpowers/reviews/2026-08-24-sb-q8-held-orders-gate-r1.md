# Gate record — Session B lane Q-8 (held-order recall hardening), ROUND 1

- **Lens:** fiscal-pos-reviewer (adversarial, code-grounded)
- **Date:** 2026-08-24
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/sb-q8-held-orders`
- **Branch / commit under review:** `fix/sb-q8-held-order-recall` @ `5a3a8f2e4` (single commit; `git status` clean, nothing modified by the gate)
- **Base:** branch is cut off an older `dev`; local `dev` tip at review time = `efb4d6475`
- **Brief:** `docs/sessions/session-B-2026-08-23/BRIEF-Q8-held-orders.md`
- **Evidence:** POS retail-till sub-report "[MEDIUM] Held-order recall: unlocked check-then-set, not terminal-scoped, unconstrained status column" in `docs/handoff/AUDIT-state-machine-sweep-sub-reports-2026-08-23.md`
- **MIGRATION-BEARING:** yes — `apps/api/database/migrations/tenant/2026_08_23_163000_harden_pos_held_orders_status_and_discard.php`

## Verdict: **ACCEPT-with-conditions**

The core safety property the audit asked for is real and I proved it with a live two-process race on PostgreSQL 5433: a parked basket is consumed exactly once, the loser writes nothing, and no stock / receipt / GL / fiscal-chain surface is touched anywhere on this path. What is NOT true is the lane's stated *shape* of the loser's refusal (the typed 409 is unreachable on PostgreSQL), and the terminal-scope defect from the audit is fixed *in capability only* — the one live client still opts out, so production behaviour is unchanged. Both are documentation/closure-accuracy defects, not money or data-loss defects. Conditions below are all cheap and none require re-running the test matrix.

### Conditions (all must be satisfied before merge)

1. **C-1 (finding 1).** Resolve the 409-vs-422 contradiction. Either (a) throw `HeldOrderRecallConflictException` from the `isRecalled()` branch (`HeldOrderService.php:180-182`) so a consumed basket yields 409 on both drivers — note this changes `HeldOrderTest.php:330-338`, which currently pins 422 `RECALL_FAILED` for the stale-UI already-recalled case; or (b) keep the behaviour and correct **all four** durable artifacts (controller comment `HeldOrderController.php:157-167`, `HeldOrderRecallConflictException` docblock, the test name/docblock at `HeldOrderRecallContractTest.php:161-193`, and the POS manifest note) to state plainly: *on PostgreSQL the lost-race loser is refused with 422 `RECALL_FAILED`; the 409 belt is reachable only on engines without row locks.* (b) is the smaller change and is acceptable.
2. **C-2 (findings 2+3).** Correct the record of the terminal-scope decision and open a named follow-up. The claim "BOTH shipped clients POST bare" must be restated as "the ONE live client (`apps/web/.../heldOrderApi.ts:106`) POSTs bare; `apps/pos/src/api/holdApi.ts` is dead code with zero importers". The follow-up (web `useRecallOrder` sends `terminal_id`, then flip recall's `terminal_id` to `required`) must be booked as a lane, otherwise the audit item reads as closed when the live path is still unscoped. **Do not edit the POS device client in this lane.**
3. **C-3 (manifest union).** The branch's manifest numbers are stale relative to `dev`. Merge must land the union stated in "Manifest union" below (`gated_ceiling` 1155, `groups.POS` 152, `groups.Fiscal` 80, `groups.Inventory` 111) — merging the branch values verbatim would *lower* dev's gated ceiling from 1153 to 1147 and drop dev's Fiscal/Inventory raises.
4. **C-4 (finding 4).** Make `down()` symmetric with `up()`'s `hasColumn` guards, or drop the "re-entrant on purpose" claim from the migration docblock.

---

## Findings

### 1. [IMPORTANT] The typed 409 `HELD_ORDER_RECALL_CONFLICT` is unreachable on PostgreSQL; the production loser gets 422 `RECALL_FAILED`

- `apps/api/app/Modules/POS/Application/Services/HeldOrderService.php:177` takes the row lock, `:179-187` evaluates `canBeRecalled()` on **the row as returned by that locking SELECT**, `:191-199` runs the conditional `UPDATE ... AND status = 'held'`, `:201-203` raises the conflict on `$affected !== 1`.
- `apps/api/app/Modules/POS/Presentation/Controllers/HeldOrderController.php:157-167` maps the conflict to 409; `:168-175` maps every other `\RuntimeException` to 422 `RECALL_FAILED`.
- **Measured, not reasoned:** I replayed the service's exact SQL in two concurrent `psql` sessions on the throwaway DB `autoerp_gate_q8` (PG 5433). TILL_A: `status_seen_under_lock = held`, `UPDATE 1`. TILL_B (blocked on the row lock, released after A's COMMIT): `status_seen_under_lock = **recalled**`, `UPDATE 0`. Final row: single `recalled`. Under READ COMMITTED, `SELECT ... FOR UPDATE` re-evaluates against the post-commit row version (EvalPlanQual), so **the loser's guard at `:179` fires before the belt at `:201` can**, and the loser is refused with `\RuntimeException('This order has already been recalled.')` → 422.
- The only test pinning 409, `apps/api/tests/Feature/POS/HeldOrderRecallContractTest.php:169-193`, stages the flip from a `retrieved` model-event callback using `DB::table(...)->update(...)` — i.e. on the *same connection and the same transaction*, which bypasses the row lock. That interleaving cannot occur between two real tills on PostgreSQL. The test is green on PG for the wrong reason.
- **Why it matters:** the lane's headline deliverable ("loser gets the typed 409, remedy = refresh the list") is asserted in four durable artifacts including the CI-preserved manifest note. A future client keying on `HELD_ORDER_RECALL_CONFLICT` will never fire; it will see `RECALL_FAILED`/422. This is a false contract statement, not a safety hole.
- **Not a safety defect:** the production loser response (422 `RECALL_FAILED`, "already been recalled") is in fact already pinned by the pre-existing `apps/api/tests/Feature/POS/HeldOrderTest.php:330-338`.
- **Fix:** see condition C-1.

### 2. [IMPORTANT] Terminal scope is opt-in and the only live client opts out — the audit's "not terminal-scoped" defect is still live in production

- Recall accepts `terminal_id` as `nullable` (`HeldOrderController.php:135-137`) and only scopes when supplied (`HeldOrderService.php:172-174`).
- The single live caller, `apps/web/src/features/pos/api/heldOrderApi.ts:106` (`return apiPost(...\`/pos/held-orders/${id}/recall\`)`), sends **no body**; the mutation site `apps/web/src/features/pos/hooks/useHeldOrders.ts:69` is `mutationFn: (id: string) => recallHeldOrder(id)`.
- Product intent, read from the code and not assumed: the list contract is strictly per-terminal — `HeldOrderService::listHeldOrders():259` calls `->forTerminal($terminalId)` and `HeldOrderController::index():80-83` makes `terminal_id` **`required`** (verified PRE-EXISTING on dev via `git show dev:apps/api/app/Modules/POS/Presentation/Controllers/HeldOrderController.php`). A basket parked on till A is never listed on till B. **Same-terminal recall is the intent; cross-terminal recall is a hole, not a feature.**
- **Ruling on the decision:** the backward-compat choice (optional on the wire) is *defensible* — making it `required` today would 404 every live web recall — and it is the right call for THIS lane, which must not touch device/FE surfaces. But it does not close the audit item: with no client sending the field, the effective production behaviour is byte-for-byte the pre-Q-8 unscoped lookup. The capability is there; nothing exercises it. That must be recorded honestly (C-2), not filed as "terminal-scoped: done".

### 3. [IMPORTANT] The stated device residual is a phantom, and the list-endpoint `required` is pre-existing — the compat rationale rests on ONE client, not two

- `apps/pos/src/api/holdApi.ts` is **dead code**: `grep -rn "holdApi|recallHeldOrder|fetchHeldOrders|discardHeldOrder" apps/pos/src` excluding the file itself returns **no matches** (exit 1). The Tauri POS parks carts in **local SQLite**, not on the server — `apps/pos/src/stores/holdStore.ts:4-9` imports `insertHeldTransaction / listHeldTransactions / deleteHeldTransaction` from `@/lib/db/repositories/heldTransactionRepository`.
- Therefore the claimed residual "`holdApi.ts` does a bare GET while the list endpoint now REQUIRES `terminal_id` (= live 422 on Tauri)" is **false on two counts**: (i) the file is never imported, so there is no live device call at all; (ii) `terminal_id => required` on `index()` was already on `dev` before this lane, so nothing "now" changed.
- **Consequences:** (a) nothing to fix on the device, and the promotion checklist must not carry a phantom device regression; (b) the "both shipped clients POST bare" justification for optional scope is really "the one live client POSTs bare" — a single one-line web change away from being closable, which strengthens the case for booking the follow-up in C-2.
- Also confirmed in-scope-correct: the lane did **not** edit any POS device file (diffstat is 11 files, all `apps/api/**`).

### 4. [MINOR] Migration `down()` is not symmetric with `up()`'s guards

`…harden_pos_held_orders_status_and_discard.php` `up()` guards both column adds with `Schema::hasColumn`, but `down()` unconditionally calls `dropIndex('pos_held_orders_company_deleted_idx')`, `dropSoftDeletes()` and `dropColumn('discarded_by')`. On a tenant where `up()` skipped the adds, rollback throws. Either guard `down()` symmetrically or drop the "Re-entrant on purpose" claim from the docblock. (Note: on PostgreSQL Laravel wraps the whole migration in a schema transaction, so the pre-flight `throw` rolls the column adds back anyway — the re-entrancy claim is defensive rather than load-bearing.)

### 5. [MINOR] Docblock fleet sweep uses `LIKE 'tenant_%'` on DB names that contain no underscore

Local tenant databases are named `tenant<uuid>` (e.g. `tenant019fbe86-944a-7252-8a3b-8c341dfa9de9`). `LIKE 'tenant_%'` matches them only because `_` is a single-character LIKE wildcard, not because the pattern describes the naming. Prefer `LIKE 'tenant%'` so an operator reading the runbook does not conclude the sweep skips these databases.

### 6. [MINOR] `show()` remains unscoped by terminal — pre-existing, tracks with C-2

`HeldOrderController.php:109-111` fetches by tenant+company only, so till B can read till A's full `cart_snapshot` by id. Not a consumption path (status is untouched) and not widened by this lane, but it is the same hole as finding 2 and belongs in the same follow-up.

### 7. [MINOR / informational] A discarded basket keeps `status = 'held'` under its tombstone

`HeldOrderService::discardOrder():239-240` sets `discarded_by` then soft-deletes; it does not move `status`. `expireOrders():286-291` correctly skips it via the SoftDeletes global scope (pinned by `HeldOrderServiceTest::test_expire_orders_skips_soft_deleted_orders`). Intentional and correct — recorded only so a future raw query against `pos_held_orders` is not written without `deleted_at IS NULL`.

### Out-of-scope observation (no action in this lane)

`apps/api/app/Modules/POS/routes_held_orders.php:15-20` applies `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` but **no `permission:` middleware**, while `RolesAndPermissionsSeeder.php:375-377` seeds `pos_held_orders.view/create/delete`. Pre-existing on dev; recording it because Q-8 newly makes discard an audited, actor-attributed action whose authorisation is still only "any authenticated user in the company".

---

## Fiscal / POS lens — explicit answers to the gate questions

**1. Double-recall race (PG 5433, real contention).** Verified by experiment on throwaway DB `autoerp_gate_q8` (created and **dropped** — confirmed absent at end of run). Two concurrent sessions replaying the service's exact statement sequence: **exactly one winner** (`UPDATE 1` / `UPDATE 0`), final state a single `recalled` row, loser wrote nothing and consumed nothing. **The lock is taken BEFORE `canBeRecalled()` is evaluated** — `HeldOrderService.php:177` (`->lockForUpdate()->findOrFail()`) precedes the guard at `:179`, and the whole read+write is inside `DB::transaction()` opened at `:168`. There is **no path where a recalled basket is sold twice or stock decremented twice**: the loser never receives the snapshot (its exception propagates out of the transaction), and — see Q3 — recall does not decrement stock at all. Caveat on the *shape* of the loser's refusal: finding 1.

**2. Terminal-scope ruling.** Same-terminal recall is the product intent, evidenced by `listHeldOrders():259` + `index():80-83` (`required`, pre-existing on dev). Cross-terminal recall is a hole, not a feature. The lane's optional-on-the-wire choice is **accepted for this lane** (requiring it would 404 the live web client, and fixing the client is an FE change this lane must not make), **but the audit item is not closed** — booked as condition C-2. On the specific question asked: there is **no live 422 regression on the Tauri device**, because `apps/pos/src/api/holdApi.ts` has zero importers and the device parks carts in local SQLite (`holdStore.ts:4-9`); and `index()`'s `required` was already on `dev`. **Nothing must be fixed in-lane on the list endpoint, and the POS client must not be edited.**

**3. Stock / cart / receipts / shift / fiscal chain.** Untouched — held orders are pre-fiscal, confirmed. `grep -rn "pos_held_orders|HeldOrder::" app/ database/` returns only: `HeldOrderService.php` (`:65,:169,:191,:230,:257,:286`), `HeldOrderController.php:109`, the 2026_03_11 create migration, a comment in `ExpireHeldOrdersCommand.php:17`, and the permission seeder. **No stock reservation, no stock movement, no fiscal event, no receipt, no GL, no shift write.** The recalled basket's payload is returned **unchanged**: `HeldOrderResource.php:29` emits `cart_snapshot` verbatim from `$heldOrder->refresh()` (`HeldOrderService.php:205`), and the conditional UPDATE at `:195-199` writes only `status`, `recalled_at`, `updated_at`. Money/quantity precision path (`canonicaliseSnapshotScales`, bcmath only) is untouched by the diff. No queue, no `onQueue`, no CompanyContext-free execution surface, no SQLite time boundary.

**4. Migration.**
- pgsql-guarded: `if (DB::connection()->getDriverName() !== 'pgsql') { return; }` before the pre-flight and the CHECK; the two column adds run on every driver (correct — the model's `SoftDeletes` needs `deleted_at` on SQLite too).
- CHECK values are **generated from the enum**, not hardcoded: `quotedStatuses()` maps `HeldOrderStatus::cases()`, which is exactly `held` / `recalled` / `expired` (`app/Modules/POS/Domain/Enums/HeldOrderStatus.php:9-11`). Drift is structurally impossible, and `PosHeldOrdersStatusCheckTest::test_check_definition_is_derived_from_the_enum` pins it.
- Pre-flight aborts **honestly**: `assertNoOutOfEnumStatuses()` **throws `RuntimeException`** with the per-value census before any DDL — same shape as the `2026_08_11_000100_unique_journal_entries_source_inventory_movement.php:30` throw-precedent, i.e. the tenant **FAILS** its `tenants:migrate` rather than log-and-skip. It is not a Q-7-style BLOCKED/FAILED classification (there is no partial-success state here); it is a hard per-tenant stop before DDL, so a dirty tenant stops on its own database and the rest of the fleet migrates. Pinned by `test_pre_flight_scan_aborts_on_out_of_enum_rows` (asserts the message and that **no constraint was created**).
- `softDeletes` column is additive and nullable; `discarded_by` is a nullable uuid with no FK. No backfill needed.
- **Expiry sweep survives soft delete:** `expireOrders():286` goes through the Eloquent model, so the SoftDeletes global scope excludes discarded rows — a discarded basket is not re-expired, and no `withTrashed()` is needed. It does **not** hard-delete. Pinned by `HeldOrderServiceTest::test_expire_orders_skips_soft_deleted_orders`.

**5. `HeldOrder` model + SoftDeletes.** `SoftDeletes` added at `app/Modules/POS/Domain/HeldOrder.php:53`, `deleted_at` cast at `:90`, `discarded_by` fillable at `:76`. Every read path goes through the model, so all of them now exclude trashed rows: `listHeldOrders():257` (correct — discarded baskets must vanish from the till), `recallOrder():169,191` (correct — a discarded basket 404s instead of being recallable), `discardOrder():230` (correct — double-discard 404s), `expireOrders():286` (correct, see above), `HeldOrderController::show():109` (correct). **No query accidentally includes trashed rows, and none excludes a row it should include.** The only nuance is finding 7 (tombstone keeps `status='held'`).

**6. Scope vs Session A's collision matrix.** Clean. The 11-file diff is entirely `apps/api/**` (POS held-order service/model/controller/exceptions, one tenant migration, four test files, the manifest). No X/Z surfaces, no PIN/`has_pins`, no VAT resolution, no `StockLevel` read paths, no web document pages, no POS device code.

---

## Gate verified

Run by path only (never the full suite), from `.worktrees/sb-q8-held-orders/apps/api`.

| Test file | sqlite (`phpunit.xml`) | PostgreSQL 5433 (`phpunit-pgsql.xml`, db `autoerp_gate_q8`) |
|---|---|---|
| `tests/Feature/POS/HeldOrderRecallContractTest.php` | **8/8**, 22 assertions | **8/8**, 22 assertions |
| `tests/Feature/POS/PosHeldOrdersStatusCheckTest.php` | **6/6** (1 run, **5 skipped** — pgsql-only CHECK assertions), 2 assertions | **6/6**, 17 assertions |
| `tests/Feature/POS/HeldOrderTest.php` | **19/19**, 62 assertions | **19/19**, 62 assertions |
| `tests/Unit/POS/HeldOrderServiceTest.php` | **22/22**, 57 assertions | **22/22**, 57 assertions |
| `tests/Feature/POS/HeldOrderTenantIsolationTest.php` (regression, not in the diff) | **6/6**, 19 assertions | **6/6**, 19 assertions |
| **Total** | **61/61 green** (5 skipped on sqlite by design) | **61/61 green**, 0 skipped |

The implementer's "61/61 sqlite+PG" is **confirmed** — the 61 includes the untouched `HeldOrderTenantIsolationTest` (6). The 17-red claim was not independently re-derived (the reds are historical and the commit is squashed); the red-capture method described in the manifest note (revert `HeldOrderService` for the contract class, remove the migration for the CHECK class) is consistent with the assertions I read.

- **Pint** `--test` on all 10 changed backend files: `{"result":"pass"}`, **EXIT=0**.
- **PHPStan L8** on `HeldOrderService.php`, `HeldOrder.php`, `Domain/Exceptions`, `HeldOrderController.php`, the migration (29 files analysed with dependencies): **`[OK] No errors`**.
- **Feature-lane manifest checker** run **from the worktree root** (`php apps/api/tools/feature-lane-manifest-check.php`): **EXIT = 0** — "1381 Feature classes in 74 groups; every group has a disposition; every declared lane is present in ci.yml; every --filter entry is anchored and uniquely matched against 1770 test classes". Parked-lane and coverage-debt warnings are the standing F-2 ones, unchanged.

## Census results (MIGRATION-BEARING pre-flight, run for real)

Docblock census executed against **every** `tenant%` database on local PG 5433 (10 databases):

```
tenant019fbe86-944a-7252-8a3b-8c341dfa9de9  clean   (pos_held_orders rows: 0)
tenant019fcf48-49a3-7230-aaf5-1c5daa44b3b3  clean   (0)
tenant019fe276-750a-709d-8968-d1364e3459b6  clean   (0)
tenant01a01b77-21a0-73f7-a893-96f689fbe815  clean   (0)
tenant01a03028-9470-70e6-83ca-cdc354f17cf1  clean   (0)
tenant01a033c6-3f61-73ce-b7f4-026b75656b71  clean   (0)
tenant3f16ac36-1cc6-4a5d-82bd-4f14831be040  clean   (0)
tenant4c3a1260-ed30-4ee6-8755-a9d823d61403  clean   (0)
tenantbe3cd47a-e4a1-4941-8b77-dde33f6ca4ce  clean   (0)
tenantf6c592ac-2199-4095-96b4-e4244442dd80  clean   (0)
```

**10/10 clean, 0 rows in `pos_held_orders` fleet-wide locally.** Local abort risk: **zero**. The docblock's "FLEET-ABORT RISK: LOW" rationale (only `HeldOrderStatus` ever writes the column; no import path; no external writer) is confirmed by the grep in Q3 — `status` is written only at `HeldOrderService.php:73` (create), `:196` (recall), `:291` (expire), plus the `'held'` column default. **Staging/production census is still owed** — see promotion obligations.

## Manifest union (branch base is stale vs dev — must be reconciled at merge)

| | `gated_ceiling` | `groups.POS` | `groups.Fiscal` | `groups.Inventory` |
|---|---|---|---|---|
| local `dev` @ `efb4d6475` | 1153 | 150 | 80 | 111 |
| branch `5a3a8f2e4` (raised 1145→1147, POS 149→151 off its older base) | 1147 | 151 | 79 | 108 |
| **required post-merge union** | **1155** | **152** | **80** | **111** |

The lane legitimately adds **+2 POS classes** (`HeldOrderRecallContractTest`, `PosHeldOrdersStatusCheckTest`) and therefore **+2** to the gated ceiling. Merging the branch's literal numbers would *lower* `gated_ceiling` 1153 → 1147 and revert dev's Fiscal (80→79) and Inventory (111→108) raises. The merge resolution must produce **1155 / POS 152 / Fiscal 80 / Inventory 111**, and the POS note must retain dev's raise history in addition to the Q-8 paragraph. (Condition C-3.)

## MIGRATION-BEARING promotion obligations (verbatim — carry into the promotion checklist)

> Migration: `apps/api/database/migrations/tenant/2026_08_23_163000_harden_pos_held_orders_status_and_discard.php` (tenant-scoped, MIGRATION-BEARING).
>
> 1. **BEFORE pushing to `origin/dev`** (push = staging auto-deploy including `tenants:migrate`), run the pre-flight census on **every** staging tenant database and confirm every line reads `clean`:
>
> ```
> for db in $(psql -Atc "SELECT datname FROM pg_database WHERE datname LIKE 'tenant%'"); do
>   echo -n "$db: ";
>   psql -d "$db" -Atc "SELECT COALESCE(string_agg(status || '=' || n, ', '), 'clean')
>     FROM (SELECT COALESCE(status,'<null>') AS status, COUNT(*) AS n
>           FROM pos_held_orders
>           WHERE status IS NULL OR status NOT IN ('held','recalled','expired')
>           GROUP BY 1) s";
> done
> ```
>
> 2. **If any tenant is not `clean`**, `tenants:migrate` will HARD-FAIL on that tenant's database (RuntimeException thrown before any DDL; the rest of the fleet still migrates). Remediate first — a held order is an ephemeral parked cart with no fiscal or GL consequence, so normalise:
>
> ```
> UPDATE pos_held_orders
> SET status = 'expired'
> WHERE status IS NULL OR status NOT IN ('held','recalled','expired');
> ```
>
> then re-run the migration for that tenant.
>
> 3. **Repeat the same census on production** before the production promotion, not only on staging.
> 4. **After migrate**, spot-check one tenant: `pos_held_orders` has `deleted_at` + `discarded_by`, and `\d pos_held_orders` shows `pos_held_orders_status_check CHECK (status IN ('held','recalled','expired'))`.
> 5. **Behaviour change to announce:** `DELETE /api/v1/pos/held-orders/{id}` is now a **soft** delete (rows persist with `deleted_at`/`discarded_by`) and **refuses a RECALLED order with 422 `HELD_ORDER_DISCARD_REFUSED`**. Any operator runbook or report that assumed the row disappears must add `deleted_at IS NULL`.
> 6. **No device (Tauri) deployment is required** for this lane — the POS device does not call the server held-order API (`apps/pos/src/api/holdApi.ts` is dead code; parking is local SQLite).

## Gate housekeeping

- Throwaway database `autoerp_gate_q8` created on PG 5433 and **dropped**; verified absent (`SELECT datname ... LIKE 'autoerp_gate%'` now returns only `autoerp_gate_q7base` / `autoerp_gate_q7clone`, which belong to the Q-7 gate and were left untouched).
- No background processes left running. Worktree `git status` clean — the gate modified **no** file under review; the only file written is this record.
- **Not merged.** A human merges.


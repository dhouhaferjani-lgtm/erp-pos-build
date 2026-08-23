# C-7 test-infra lane — adversarial merge gate, round 1

- **Lane:** `fix/c7-poscore-projection-test-infra`
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/c7-poscore-testinfra`
- **Commits:** `0ed7c37fc` (read scoping), `72543c169` (slug pins) on base `fa807a699`
- **Reviewer posture:** adversarial, code-grounded. Every claim below cites a file/line I read or a command I ran.
- **Date:** 2026-08-23

---

## 0. Pre-flight: class resolution and scope

**Class resolution → WORKTREE (verified, not assumed).** `apps/api/vendor` in the worktree is a **real directory, not a symlink**, so the stale-main-repo-vendor trap does not apply. `ReflectionClass::getFileName()` on all three load-bearing classes resolves inside the worktree:

```
Tests\Feature\Fiscal\PosCoreReceiptProjectionTest        => .worktrees/c7-poscore-testinfra/apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php
App\...\Projections\PosCoreReceiptProjection             => .worktrees/c7-poscore-testinfra/apps/api/app/Modules/POS/Application/Projections/PosCoreReceiptProjection.php
Database\Factories\TenantFactory                         => .worktrees/c7-poscore-testinfra/apps/api/database/factories/TenantFactory.php
```

**Scope: TEST-ONLY confirmed.** `git diff --stat fa807a699..HEAD` = **one file, 232 insertions / 75 deletions**:

```
apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php | 307 ++++++-----
```

`git diff --stat fa807a699..HEAD -- apps/api/app` returns **empty** — production code is untouched. Worktree is clean (`git status --porcelain` empty). Both commits touch only the one test file (`0ed7c37fc`: 211+/72−; `72543c169`: 21+/3−). **No fiscal Event class renamed/restructured/deleted; no projection logic altered; rule 8 not engaged.**

---

## 1. The central question: did any assertion lose strength?

**Answer: no. Every site is equal-or-stronger, and the arithmetic closes exactly.**

I derived the census from the actual diff, not the lane's report (the lane's report is not committed to the repo — residuals live in the commit messages instead; see §5).

### 1.1 Arithmetic corroboration of "140 → 173"

I could not re-run the base file (main checkout has moved past the base to `bdf9ce5e9`, and mutating either checkout is outside my write scope), so I reconstructed the delta from the diff hunks and checked it against the observed runtime count:

| Source of change | Runtime assertions |
|---|---|
| Baseline (claimed) | 140 |
| 9 rollback sites converted to `assertNoProjectionRowsWritten` (5-table loop replacing 2–4 whole-table zeros) | **+26** |
| 7 new `assertSame(1, …->count())` uniqueness guards | **+7** |
| **Total** | **173** |

Observed on PostgreSQL: **173**. The reconstruction lands on the observed number exactly, which independently confirms the 140 baseline and that **no assertion was silently deleted**.

Cross-check on static call counts: base 138 → head 136 `$this->assert*` call sites, test methods **31 → 31** (no test deleted). The *static* drop of 2 alongside a *runtime* rise of 33 is exactly the expected signature of collapsing N whole-table zero-assertions into one 5-iteration helper call. A naive "assertion calls went down" reading would be a false alarm; the runtime count is the honest metric.

### 1.2 Attack (a) — snapshot-delta soundness

`projectionTableCounts()` (lines 1499–1513) takes **whole-table** counts of `pos_receipts`, `pos_receipt_lines`, `pos_receipt_payments`, `pos_receipt_vat_details`, `stock_movements`; `assertNoProjectionRowsWritten()` (1521–1530) asserts strict equality per table (catches both increase *and* decrease).

A snapshot-delta is sound only if (i) the snapshot is taken before `apply()`, and (ii) leaked rows are genuinely constant across the window. I verified **all 9 conversion sites individually**:

| Test | `$before = …` placement | Verdict |
|---|---|---|
| `terminal_not_found_fails_closed_without_crashing` | after event build, immediately before `apply()` | sound |
| `unknown_method_code_rolls_projection_back` | before `try{apply()}` | sound |
| `cross_tenant_method_code_rolls_projection_back_atomically` | before `try{apply()}` | sound |
| `voucher_redemption_failure_rolls_back_the_entire_projection` | before `try{apply()}` | sound |
| `cross_tenant_payment_method_id_is_rejected_fail_closed` | **after** the foreign-tenant/`PaymentMethod` fixtures (≈907–920), snapshot at ≈924, apply at ≈927 | sound — fixture writes are outside the window |
| `voucher_payment_line_without_instrument_type_is_rejected_by_projection` | before `try{apply()}` | sound |
| `voucher_payment_line_without_instrument_serial_is_rejected_by_projection` | before `try{apply()}` | sound |
| `refund_projection_throws_when_original_receipt_unresolvable` | before `try{apply()}` | sound |
| `void_projection_throws_when_original_receipt_unresolvable` | before `try{apply()}` | sound |

Condition (ii): the leaked rows are **committed** by a class that has already finished, so nothing mutates them mid-test. **No concurrent in-process writer exists**: `paratest` appears nowhere in `apps/api/composer.json` nor in `.github/workflows/` (`ci.yml`, `react-doctor.yml`, `smoke-test.yml`, `sonarcloud.yml`), and `scripts/run-feature-lane-local.sh:22-25` explicitly forbids two concurrent PHPUnit processes ("Two pgsql RefreshDatabase suites racing the same schema corrupt each other"). Snapshot-delta is therefore sound **under this repo's execution model**. See §4 residual R-4 for the standing assumption this creates.

Net effect: each converted site went from proving "0 rows in 2–4 tables" to proving "**zero new rows in all 5 projection tables**". These conversions are **strictly stronger**, and they additionally add `stock_movements` + `pos_receipt_vat_details` rollback coverage that several sites never had.

### 1.3 Attack (b) — vacuous child-scoped queries

`myReceiptChildren()` (1472–1478) walks `whereIn('receipt_id', <pos_receipts where tenant_id>)`. Where the test asserts **no receipt exists**, that subquery is empty and any `assertSame(0, …)` on it is **vacuously true**. I checked every rollback site for this trap. The lane used snapshot-delta at all nine — with **one leftover**:

- **F-1 (Minor):** `test_terminal_not_found_fails_closed_without_crashing` retains `assertSame(0, $this->myReceiptChildren('pos_receipt_payments')->count())` alongside the snapshot-delta. Given the preceding `assertSame(0, $this->myReceipts()->count())`, this child assertion is vacuous. It is **not a weakening** — the same claim is fully covered by `assertNoProjectionRowsWritten` on all 5 tables — but it is dead weight that could mislead a future reader into believing standalone child coverage exists there.

Conversely, `test_zero_payment_lines_receipt_persists_without_payment_rows` uses `myReceiptChildren('pos_receipt_payments')->count() === 0` **legitimately**: it is preceded by `assertSame(1, $this->myReceipts()->count())`, so the parent exists and the child scope is non-vacuous. Correctly distinguished by the lane.

### 1.4 Attack (c) — the `fiscal_event_id` linkage test

**Confirmed non-tautological.** `test_apply_links_pos_receipt_to_the_fiscal_event` (≈266–277) scopes by **tenant**, adds `assertSame(1, $this->myReceipts()->count())`, then reads `orderBy('id')->first()` and asserts `assertSame($event->id, $receipt->fiscal_event_id)`. Scoping by `fiscal_event_id` would have made that assertion assert itself; the lane avoided it and documented why in an inline comment.

Note the contrast with line 258 (`test` for canonical-bytes escaping), which *does* read `DB::table('pos_receipts')->where('fiscal_event_id', $event->id)->first()`. That is legitimate: the assertion there is about `canonical_bytes`, not about the linkage column, so the scope is not self-referential.

### 1.5 Attack (d) — ordering determinism

`pos_receipts.id` is **`$table->uuid('id')->primary()`** (`database/migrations/tenant/2026_01_08_190637_create_pos_receipts_table.php:22`); child tables use `foreignUuid('receipt_id')` (…`190638`:25, …`190639`:25, …`190640`:25); `stock_movements` has `uuid('id')` + `uuid('tenant_id')` (`2025_11_30_110000_create_inventory_tables.php:33-34`).

So `orderBy('id')` on a UUID PK is **stable but semantically arbitrary** — correct only if exactly one candidate row exists. I checked every multi-candidate read:

| Read site | Guard | Verdict |
|---|---|---|
| `apply_links_…` (219) | `assertSame(1, myReceipts()->count())` | safe |
| `idempotent_replay…` (456, 467) | `assertSame(1, …)` before and after | safe |
| stock movement (593) | `assertSame(1, myStockMovements()->count())` | safe |
| v2 variant lines (701, 746) | `assertSame(1, lines count)`; `orderBy('line_number')->orderBy('id')`, `line_number` is `integer` | safe |
| vat/payment hashes (1079) | `assertSame(1, …)` | safe |
| explicit receipt id (1104) | `assertSame(1, …)` | safe |
| product FK resolver (1166) | `assertSame(1, lines count)` | safe |
| buyer block (1376, 1401, 1429) | `assertSame(1, …)` | safe |
| split payments | `orderBy('amount','desc')->orderBy('id')`, amounts are `'7.00'`/`'3.00'` (distinct), `assertCount(2)`; ordering is not load-bearing (test sums via `bcadd`) | safe |

- **F-2 (Minor):** `test_applies_pos_core_effects_exactly_once` (line 219) is the **one** single-row read without an explicit `assertSame(1, myReceipts()->count())`. It relies on the preceding `assertSame(1, myReceipts()->whereNotNull('canonical_bytes')->count())`, which is sufficient in practice (the test applies once), but it is inconsistent with the other twelve sites. Consistency nit only — still strictly stronger than the base's unscoped `->first()`.

### 1.6 The scopes are real, not vacuous

A tenant-scoped helper that matched nothing would turn `assertSame(1, …)` red, so the green run already proves the scopes bind. Confirmed at source anyway: the projection writes `'tenant_id' => $tenantId` on both `StockMovement::query()->create([...])` sites — the `MovementType::Issue` / `MovementReason::POSSale` decrement (≈2042–2047) and the `MovementType::Receipt` / `MovementReason::POSReturn` **restock** (≈2447–2452). The restock direction on refund/void is correct per the fiscal contract (refund/void RESTOCK, not decrement) and is unchanged by this lane.

- **F-3 (Minor, pre-existing — not a lane regression):** in `test_apply_is_idempotent_via_the_fiscal_event_id_guard`, `$stockMovementsAfterFirst = $this->myStockMovements()->count()` is **0**, because the default fixture line is `['sku' => 'X', …]` with **no `product_id`** (helper default, ≈1597), so no product FK resolves and no stock movement is written. The before/after stock comparison is therefore `0 === 0` — vacuous. It was *equally* vacuous at base (`29 === 29` against leaked constants), so **this lane neither introduced nor worsened it**. Real stock-idempotency coverage does exist, in `test_voucher_plus_stock_combined_both_side_effects_fire_and_are_idempotent`, which is product-backed and asserts `assertSame(1, myStockMovements()->count())` before and after replay.

### 1.7 One deliberate narrowing, called out honestly

- **F-4 (Minor):** `test_runs_to_completion_with_treasury_inactive` changed `DB::table('payments')->count()` → `DB::table('payments')->where('tenant_id', $this->tenantId)->count()` (line 316). This is the **single site where the scoped form proves less** than the literal base form ("no payments anywhere" → "no payments for this tenant"). It is nevertheless the **correct** claim — the assertion is that the `TreasuryReceiptBridge` did not run for *this* receipt, and the unscoped form was itself leak-flaky. `payments` has `uuid('tenant_id')` (`2025_11_30_120000_create_treasury_tables.php:141`), so the scope binds. Worth noting that `payments` is **not** in `projectionTableCounts()`, so unlike the other narrowings this one gets no snapshot-delta backstop. Optional hardening below.

---

## 2. Honesty of the "untouched because already scoped" list

**Verified honest — no unscoped read slipped through the census.** Exhaustive grep of `DB::table(` in the file yields, outside helper bodies and docblocks, exactly:

- **258** — `DB::table('pos_receipts')->where('fiscal_event_id', $event->id)->first()` — already scoped by a per-test-unique key. Legitimate (§1.4).
- **316** — `DB::table('payments')->where('tenant_id', …)` — scoped (F-4).
- **1399** — `DB::table('pos_receipts')->where('id', $receipt->id)->update([...])` — a **write**, scoped by PK. Legitimate.

I also swept for Eloquent-based reads that a `DB::table` grep would miss (`::query()->count()`, `::count()`, `->first()`, `->value(`): all 15 hits are either the helper-scoped reads listed in §1.5 or the two legitimate sites above. **No remaining unscoped `->first()` / `->count()` in the class.**

I also confirmed `PosCoreReceiptProjectionTest` itself does **not** override `connectionsToTransact()` — the only two hits in the file (lines 86, 1459) are docblock prose. This matters: it means the target class rolls back cleanly and **cannot** leak into siblings, which is what licenses the causal claim in §3.

---

## 3. Evidence reproduced independently

Run against a throwaway PostgreSQL database (`c7_gate_r1`, PG on `127.0.0.1:5433`), env pinned the same way `scripts/run-feature-lane-local.sh` pins it (`DB_*` **and** `DB_CENTRAL_*` — the `central` connection reads `DB_CENTRAL_*` in preference to `DB_*`), `TENANCY_DB_PER_TENANT=false`, single process, `nice -n 19`. Database dropped and recreated before each run.

**(1) Standalone — matches the lane's claim exactly:**

```
OK (31 tests, 173 assertions)     Time: 01:38.557
```

**(2) Bracketed 14-file `PosCoreReceiptProjection*` cluster — run twice, byte-identical signature:**

```
Tests: 86, Assertions: 335, Failures: 12.
```

74 passed / 12 failed = **the claimed stable 12F/74P signature**. Crucially, I captured the **full** failure list (the first run's tail truncated items 1–3, so I re-ran with full capture rather than infer):

1–2. `PosCoreReceiptProjectionRefundNoDecrementTest` (2)
3–7. `PosCoreReceiptProjectionRefundStockTest` (5)
8–12. `PosCoreReceiptProjectionVariantStockTest` (5)

**Zero failures in `PosCoreReceiptProjectionTest`.** The target class is green inside the multi-class run — which is precisely the condition it was nondeterministically red under before. The residual failure modes are the same bleed signature (`Failed asserting that 16 is identical to 1`, `9 is identical to 1`) in the sibling classes that were never in this lane's scope.

Per §2, the target class does not commit state, so nothing in this two-commit diff can influence sibling outcomes; the 12 residual failures are **pre-existing and not introduced here**.

I did **not** run the full 836-test directory (~50 min); the lane's 3× evidence plus these two independent cluster runs stand.

---

## 4. Residuals — recorded, not silently attempted (verified)

- **R-1 — the real cure.** Adding a `tearDown`/restoring `RefreshDatabase` transactions on `PosCoreReceiptProjectionRefundDispositionStockTest` (`connectionsToTransact(): return []` at that file's lines 63–66 — **I read them, the citation is accurate**) is correctly **left alone**. `0ed7c37fc`'s message states this explicitly: *"The committing sibling is left alone on purpose — fixing it would change the directory's inherited-red baseline; written up as a residual instead."* Correct call under rule 4 (no scope creep).
- **R-2 — sibling files with the same pattern.** 18 files repo-wide override `connectionsToTransact`, of which 3 sibling classes in this cluster are still red from the bleed (§3). Untouched, correctly.
- **R-3 — global `TenantFactory` fix.** Root cause confirmed at `apps/api/database/factories/TenantFactory.php:36` — `'slug' => Str::slug($this->faker->unique()->company())`. `faker->unique()` de-dupes the company *name*; `Str::slug()` then collapses distinct names onto one slug. The lane pinned explicit slugs at the **3 call sites in this class only** (lines 146, 907, 1136 — all three verified present, `Illuminate\Support\Str` already imported at line 41) rather than mutating a shared factory used across the suite. Correct blast-radius call; the global fix remains a genuine residual.
- **R-4 — new standing assumption (not flagged by the lane).** `projectionTableCounts()` uses **whole-table** counts, which is sound only while the suite runs single-process (§1.2). If this repo ever adopts `paratest` against a shared database, these 9 sites become racy and will produce confusing intermittent reds. Worth a one-line note in the helper docblock so the next person doesn't rediscover it the hard way. This is a **documentation** item, not a defect today.

The lane's residual write-ups live in the **commit messages**, which are durable and accurate. No lane report file is committed to the repo (`find` for `*c7*` returns no lane report). Not a blocker, but if the LEDGER C-7 row is the tracking surface, R-1/R-2/R-3/R-4 should be mirrored there before this drops off the board.

---

## 5. Commit-message accuracy

Both messages were checked line-by-line against the diff and against the code they cite. `0ed7c37fc` accurately describes the four helpers, the root cause, the "no assertion weakened, no test deleted" claim (verified §1), and the 140→173 delta (verified §1.1). `72543c169` accurately describes the `tenants_slug_unique` collision, correctly attributes it to `Str::slug` collapsing `faker->unique()` names, correctly states "**No assertion touched**" (verified: the diff hunks are 3 factory-call edits plus comments), and cites `VerifyEventChainFleetCommandDbPerTenantTest` as prior art for the pattern. **No overclaiming found in either message.**

---

## 6. Findings

| # | Severity | Location | Finding | Fix |
|---|---|---|---|---|
| F-1 | Minor | `PosCoreReceiptProjectionTest.php` ≈366, `test_terminal_not_found_fails_closed_without_crashing` | `assertSame(0, myReceiptChildren('pos_receipt_payments')->count())` is vacuous (no parent receipt ⇒ empty subquery). Not a weakening — the snapshot-delta covers all 5 tables — but it is misleading dead weight. | Delete the line, or add a comment noting it is deliberately redundant with `assertNoProjectionRowsWritten`. |
| F-2 | Minor | ≈219, `test_applies_pos_core_effects_exactly_once` | Only single-row read lacking an explicit `assertSame(1, myReceipts()->count())` guard; relies on the `whereNotNull('canonical_bytes')` count. Inconsistent with the other 12 sites. | Add `$this->assertSame(1, $this->myReceipts()->count());` before the `->first()`. |
| F-3 | Minor (pre-existing) | ≈285–300, `test_apply_is_idempotent_via_the_fiscal_event_id_guard` | Stock-movement before/after comparison is `0 === 0` because the default fixture line carries no `product_id`. Vacuous at base too — **not a lane regression**. Real coverage lives in `test_voucher_plus_stock_combined…`. | Optional: use a product-backed fixture here, or comment that stock idempotency is proven in the combined test. |
| F-4 | Minor | line 316, `test_runs_to_completion_with_treasury_inactive` | Sole site where scoping narrows the literal claim ("no payments anywhere" → "none for this tenant"). Correct as the intended claim, but has no snapshot-delta backstop since `payments` is absent from `projectionTableCounts()`. | Optional hardening: add `'payments'` to `projectionTableCounts()` and assert the delta here too. |
| F-5 | Minor (doc) | helper docblock ≈1499–1513 | Whole-table snapshot soundness silently depends on single-process execution. | Add one line to the `projectionTableCounts()` docblock: sound only while the suite is single-process; `paratest` on a shared DB would make these sites racy. |

**No Critical or Important findings.** Production code untouched; no fiscal Event mutated; no projection write path, idempotency anchor, stock direction, `CompanyContext` handling, currency-scale resolution, queue registration, or SQLite time contract touched by this diff. The precision/fiscal checklists are not engaged by a read-scoping test change — I confirmed this by diffing `apps/api/app` (empty) rather than by assumption. The one money-adjacent line in the diff, the split-payment sum, already uses `bcadd` with no float and was not altered.

---

## 7. Verdict

The lane does exactly what it claims and nothing more. The central claim — **no assertion lost strength** — holds under site-by-site inspection of all 26 conversions, and the runtime arithmetic (140 + 26 + 7 = 173) closes on the independently observed number. The nine snapshot-delta conversions are sound (snapshot ordering verified individually; no concurrent writer exists in this repo) and are **strictly stronger** than the whole-table zeros they replaced, adding rollback coverage on two tables that previously had none. Seven new uniqueness guards were added. The one vacuous-subquery trap was correctly avoided at all nine rollback sites, with a single harmless leftover (F-1). Evidence reproduced independently on PostgreSQL: 31/173 standalone, and 12F/74P in the 14-file cluster with **zero failures in the target class**. Scope is two commits, one test file, production untouched. Residuals are recorded in durable commit messages, not silently attempted.

The five findings are all Minor and all optional — none blocks merge, and none touches correctness of what the suite proves.

VERDICT: ACCEPT

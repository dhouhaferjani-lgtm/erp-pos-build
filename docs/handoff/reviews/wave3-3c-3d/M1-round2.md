# M1 adversarial merge-gate review — round 2

**Range:** `26b63f0ff..HEAD` (40 commits; round-2 remediation = `6d4b7ed27..HEAD`) · **Lenses:** inventory-costing, fiscal-pos (both apply) · **Authority applied:** `ORCHESTRATOR-RULING-2026-08-11-t11c-sequencing.md` (amended M1 exit: pairs 1–6 green, pairs 7–10 red-with-evidence).

Round 1's central finding is **genuinely fixed**: pairs 7–10 now run real writers, the reds are cause-specific, and I verified they can flip green after T16d/T16e (see refuted bypasses 1–3). P1-2, P2-3, P2-4, P3-8, P3-9, P3-10 and P3-11 from round 1 are all closed against code. What blocks is a new defect the remediation introduced.

---

## Register

### P1-1 — The ruled-red pairs 9 & 10 were planted inside a class named in the CI **merge-gate** filter — CONFIRMED
`apps/api/tests/Feature/Fiscal/PosCoreReceiptProjectionRefundDispositionStockTest.php:381-388`; `.github/workflows/ci.yml:629` (and the job's gate condition at `:338`)

`PosCoreReceiptProjectionRefundDispositionStockTest` is a literal member of the `backend-test-pgsql` `--filter` allowlist (`ci.yml:629`), and that job runs on **every PR whose base is `dev` or `main`** (`ci.yml:338`). The remediation added `test_t11c_voucher_company_advisory_is_terminal_to_stock_projection` (2 data rows) into that class, and the ruling requires those rows to stay **RED until T16e lands in M2**. PHPUnit `--filter` matches the class name, so both red rows execute in the gate.

Failure scenario: merge M1 → the next PR to `dev` (M2's cutover, or any unrelated branch) fails `Backend Tests — PG-only invariants` with 2 failures for the entire M1→M2 window. The gate's own comment demands the opposite discipline — "Reviewers must confirm these names appear in the GATE RUN LOG" (`ci.yml:617-619`) — and the report's regression list never records a run of that filter. Pairs 7 & 8 are fine: `PosReturnScrapWriteOffTest` (`:442`) is **not** in the allowlist.

The ruling authorises a red *test*, not a red *shared merge gate*. Remedy: move the two voucher pairs into a dedicated class outside the allowlist (mirroring where 7/8 already sit), or `@group` them out until T16e; the red-before evidence obligation is unaffected either way.

### P2-2 — Round 2's own P3-7 fix (pgsql migration guard) makes two seam tests fail on SQLite — CONFIRMED
`apps/api/database/migrations/tenant/2026_08_11_000100_unique_journal_entries_source_inventory_movement.php:15-17`; `apps/api/tests/Feature/Inventory/InventoryGlPostingSeamTest.php:84-94`, `:636`

The migration now `return`s before creating the index on non-pgsql. `InventoryGlPostingSeamTest` has **no** driver skip (`:34-49`), and `test_exit_rounds_once_posts_balanced_and_replays_idempotently` reads the index back from `sqlite_master` (`:88-91`); with the index no longer created, `value('sql')` is `null` → `(string) null` = `''` → `assertStringContainsString('inventory_exit', '')` fails. Separately, `test_migration_refuses_a_preexisting_duplicate_and_names_its_pair` opens with `assertSame('pgsql', DB::getDriverName())` (`:636`) — a hard **failure**, not the mandated loud **skip** ("`[PG]` tests skip loudly, never silently", brief house rules).

Failure scenario: the sqlite lane (`phpunit.xml:41` pins `DB_CONNECTION=sqlite`) — including the "Full backend suite (manual security gate)" — goes red on two tests that were green before the round-2 fix. The declared regression runs were pgsql-only, which is exactly why this was invisible.

### P2-3 — A shipped voucher-GL amount path was changed in M1, with no red-first, no revert/replay, no test, and no run of its owning suite — CONFIRMED
`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:2712-2721` (commit `915a05c0b`)

`createVoucherLedgerEntry` was switched from `$this->scale()` (CompanyContext → `country.currency_decimal_places`) to `$this->scaleResolver->getScale((string) $ledgerRow->currency)` (static ISO map). This is not M1 scope (M1 = T11/T11e/T12/T13/T11c/T15a/T16c/V-10); it was made to let the pairs 9/10 *trace* run without a bound CompanyContext. It landed **after** the round-2 revert/replay pair (`2a4c67c4b`/`a8c797222`), so it has no red-first record and no revert/replay. `tests/Feature/Voucher/VoucherLedgerGlActorFallbackTest.php` — the only direct test of this method — is absent from every declared regression run, and `VoucherLedgerTest`/`VoucherLedgerAppendOnlyTest`/`PosCoreReceiptProjectionVoucherRefundNoRedemptionTest` are all in the pgsql merge gate that was never run.

Failure scenario: `$scale` feeds `bcmul($rawAmount, '-1', $scale)`, which **truncates**. A company whose country row scale differs from the voucher currency's ISO scale — e.g. a TN-registered company (`CountriesSeeder.php:24` → 3) issuing an EUR voucher (ISO → 2) — negates `-10.005` to `10.00` instead of `10.005`, and that amount is sealed into the fiscal hash chain and no longer reconciles against `voucher_ledger`. Under seeded countries the old and new scales coincide, so nothing in the suite would notice. This also pre-empts M2's non-waivable T16e obligation ("the shipped voucher suite green with byte-identical `voucher_ledger` rows and GL entries") without producing that characterisation. Either carry it into M2 with the byte-identity evidence, or pin it here with the voucher suite run.

### P2-4 — `reverseInventoryWriteOffEntry`'s posting **mechanism** was changed, beyond the idempotency remediation that was asked for — CONFIRMED
`GeneralLedgerService.php:5011-5016` vs. the pre-round-2 `if ($user !== null) { $this->postEntry(...) }`

Two changes ride along with the (legitimate) idempotency guard: `postEntry` → `postEntryNow`, and the condition `$user !== null` → `$wasPosted && status !== Posted`. `postEntry` fires `JournalEntryPosted` **inline**; `postEntryNow` defers it to `DB::afterCommit` (`:3462-3466`). And a reversal of a Posted write-off with `postedByUserId === null` now posts (previously left Draft).

Failure scenario: `DomainEventSubscriber::handleJournalEntryPosted` (`app/Modules/Compliance/Listeners/DomainEventSubscriber.php:100`) now runs post-commit; under `RefreshDatabase` `afterCommit` never fires (plan §0b.9), so any test asserting a compliance/audit row for a batch write-off reversal silently loses it. The only production caller is transaction-wrapped (`ReverseWriteOffService.php:155`), so `postEntryNow`'s `LogicException` guard is not tripped — but `tests/Feature/BatchExpiry/` (the sole suite covering this method) appears in **no** declared regression run. Round 1 asked for idempotency, not a mechanism swap.

### P2-5 — Pairs 1–6 remain non-falsifiable, and `M1-evidence.md` files them under a "production traces" heading — CONFIRMED
`apps/api/tests/Feature/Inventory/InventoryGlLockOrderContentionTest.php:127-154`, `:193-218`; `docs/handoff/reviews/wave3-3c-3d/M1-evidence.md:8-19` vs `:29-55`

`runTerminalOrder()` still creates a scratch table `w3_t11c_<rand>` and an advisory key `'t11c:'.$pair.':'.bin2hex(random_bytes(8))` (`:199`) — a random string, not the company id — and `$pair` selects nothing but the table name. No writer is invoked. Round 2 changed only the docblock and the pairs 7–10 assertion polarity. `M1-evidence.md` opens with "**Real writers are traced through `DB::listen`**" and then reports pairs 1–6 `PASS` from this file; a reader concludes 1–6 are production-traced. They are not: they assert a property of PostgreSQL and cannot go red for any code change.

I accept that DN/RN confirm and the POS sale lane do not post inventory GL until T14/T16 (M2), so a production trace for 1–3 is genuinely impossible in M1 — which is why this is P2 and not P1. Pair 4/5's T5b substance *is* production-driven (`GoodsReceiptGlPostingOrderTest::test_goods_received_fires_after_the_purchase_order_reaches_received`), and pair 6's composition is pinned at buffer level. What is required is that the evidence say so plainly, and that M2's "all ten GREEN" gate be read as *the production tests*, not the sensitivity controls (which are permanently green by construction and prove nothing about the cutover).

### P2-6 — The M1 evidence contract "red-first + revert-replay **per task**" is still not met — CONFIRMED (carried)
`docs/sessions/codex-dpa-wave3-3c-3d-report.md:105-135`; git log `26b63f0ff..HEAD`

M1 now has exactly **one** revert/replay pair (`2a4c67c4b` / `a8c797222`), covering three of round 2's assertions (duplicate `23505`, Draft-not-Posted, cross-connection buffer loss). T11's ladder, T11e, T12, T13 and T15a still carry no red-before record and no revert-replay; V-10 has a recorded red state only. The brief's evidence table requires the contract per task for M1 (`CODEX-DISPATCH…:540`). M0 demonstrated it five times, so the bar is established and reachable.

### P3-7 — The pair-6 "one flush" assertion is self-fulfilling
`InventoryGlPostingSeamTest.php:399-427`. `$flushCount` is incremented by the test body immediately before `flushIfOutermost()`, then `assertSame(1, $flushCount)`. It cannot detect a second flush. The real content (two contexts → two `inventory_exit` rows, buffer empty) is asserted correctly; the counter should come from a spy on the buffer or a `DB::listen` count of entry inserts.

### P3-8 — Pairs 7 & 8 (and 9 & 10) are the same test twice; the pair's *second* writer is never exercised
`PosReturnScrapWriteOffTest.php:434-442`; `PosCoreReceiptProjectionRefundDispositionStockTest.php:381-388`. Both data rows run identical code with identical assertions; `$pair` reaches only the failure message. Pair 8's counterparty (POS sale projection) and pair 7's (DN confirm) are never driven. Defensible under I-1 (a per-writer terminality property makes the cross product safe), but the pair labelling overstates what ran — say so in the evidence rather than emitting two identical rows.

### P3-9 — The new I-2 rule test runs in no automated gate
`apps/api/tests/PHPStan/InventoryPaymentRepositoryLockDisjointnessTest.php`. `tests/PHPStan` is not in any `phpunit.xml` testsuite (`:7-19`) and is not referenced by `scripts/preflight.sh` or `.github/workflows/ci.yml`. Pre-existing (both sibling rule tests share the fate), and the rule rewrite to AST + positive/decoy fixtures does close round-1 P3-11 on substance — but the fixtures only protect the rule if something runs them.

### P3-10 — `absoluteDeltaForRow()` hardcodes bcmath scale `4`
`apps/api/app/Modules/Inventory/Domain/StockMovement.php:202-204`. `bcsub(..., 4)` / `bccomp(..., 4)` instead of the `QuantityScale` constant. PHPStan reported clean, so `ForbidHardcodedBcmathScale` evidently scopes to currency — but rule 19's "one origin" intent points at the constant.

### P3-11 — The I-2 rule matches `String_` nodes anywhere in the class
`app/PHPStan/Rules/InventoryPaymentRepositoryLockDisjointness.php:53-62`. A class that takes `lockForUpdate()` and merely mentions `'stock_levels'` in a log message or an exception string trips the rule. Comment decoys are correctly excluded (comments are not nodes, and the fixture proves it); string decoys are not.

### P3-12 — `ReturnCostBasisResolver` still does not net units drawn by prior returns (plan-level, carried from round-1 P3-12)
`ReturnCostBasisResolver.php:69-89`. Correctly deferred by the wave to the plan owner / M3's D-g detector; recorded here so it is not lost. Not an implementation deviation.

---

## Bypasses I tried that FAILED

1. **"Pairs 7–10 can never turn green because the GL post itself reads stock tables, so `last_inventory` always trails the advisory"** — refuted. `createInventoryMovementEntry` (`GeneralLedgerService.php:4645-4757`), `createInventoryWriteOffEntry` and `postEntryNow`/`sealAndPersistEntry` touch `journal_entries`, `journal_lines`, `accounts`, `companies`, `users` and the fiscal chain only. No `"stock_levels"` / `"stock_movements"` statement follows the advisory. The tests are flippable.
2. **"Some other GL post inside `ReceiptReturnService::processReturn` takes the company advisory before the stock loop, so 7/8 stays red after T16d"** — refuted. The only GL call on that path is `applyScrapPair` → `ReturnScrapWriteOffService::writeOff` (`ReceiptReturnService.php:1367-1422`); nothing else in the root `DB::transaction` (`:191`) posts.
3. **Same for 9/10** — refuted. In `PosCoreReceiptProjection::apply()` the only pre-stock GL is `redeemVouchers` (`:458`); `writeLines`/`writeVatBreakdown`/`writePayments`/`earnLoyaltyPoints` post no journal entry. T16e's reorder alone flips the assertion.
4. **"The trace can't see the production advisory — the binding isn't the company id"** — refuted. `generateEntryNumber` binds `$companyId` (`GeneralLedgerService.php:5056`) and `sealAndPersistEntry` binds `$entry->company_id` (`:3524`), both via `pg_advisory_xact_lock(hashtextextended(?, 0))`, which is exactly what the trace matches.
5. **"The non-default-connection guard test passes vacuously because `central` *is* the default connection"** — refuted. `central` is a distinct connection (`config/database.php:124`) and the default is `sqlite`/`pgsql` (`:20`, `phpunit.xml:41`). Without the new guard the listener would reach `transactionLevel() === 0` on `central` and reset the buffer, so `test_rollback_on_a_non_default_connection_does_not_discard_tenant_contexts` is genuinely sensitive.
6. **"`createInventoryWriteOffEntry`'s new existence guard suppresses a legitimate second write-off"** — refuted. Both callers pass a freshly created `$movement->id` (`BatchWriteOffService.php:110`, `ReturnScrapWriteOffService.php:196`).
7. **"`postEntryNow` throws outside a transaction, so the reversal change breaks production"** — refuted. The sole production caller is inside `DB::transaction` (`ReverseWriteOffService.php:155`), and `ReverseWriteOffServiceTest::test_reversal_mirrors_posted_status_of_original` still satisfies the new condition.
8. **"`(string) $ledgerRow->currency` can be empty → silent `DEFAULT_SCALE = 2`"** — refuted as a null case: `voucher_ledger.currency` is `char(3)` NOT NULL (`2026_05_02_000002_create_voucher_ledger_table.php:27`).
9. **"PHPStan level 8 fails on the untyped `?array $paymentOverride` added to the projection test helper"** — refuted: `phpstan.neon:7-8` analyses `app/` only.
10. **"A second, production-driven T11c green arm exists elsewhere and I missed it"** — searched `tests/Feature/Inventory`, `tests/Feature/POS`, `tests/Feature/Fiscal`, `tests/Architecture`; none.

## What is solid

The pairs 7–10 remediation is the real thing: `DB::listen` traces against the actual writers, the advisory identified by both SQL shape and bound company id, and an assertion (`first_company_advisory > last_inventory`) that flips without editing the test — I verified the flip is reachable for both T16d and T16e. All four `MovementGlKind` arms are now pinned including count-correction gain/shrinkage direction; `enqueue` is proven query-free; savepoint / root-rollback / next-root / cross-connection buffer isolation are all covered; `return_cost_basis` persistence is pinned end-to-end at two lines with the exact movement cost; the Draft-repair asymmetry is closed on both guards; `hasInventoryMovementAccounts`'s hardcoded `false` is gone; I-2 is a real AST rule with positive and decoy fixtures; T13's duplicate pre-check has a genuine test that names the pair. Rule 19 holds across the seam (explicit non-nullable `currencyCode` on the DTO, `bcmul` at `scale+6` → `CurrencyScale::bcround`, no floats). V-10 ships en+fr. The aborting-subtransaction cannot-verify is proven directly against PostgreSQL.

The blocker is not the seam — it is that the ruled-red evidence was placed where it will break a shared merge gate.

VERDICT: CHANGES-REQUIRED

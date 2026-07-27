I've read every file in the diff and independently verified the risky claims rather than trusting the implementer's summary.

---

**GATE VERDICT: APPROVE**

## Verification of the gate focus items

**1. `ChecksToPay=4035` / `EffetsPayable=403` exhaustive + liability/system in all three charts — CONFIRMED**

- Resolver `match` has no default arm and covers all 9 cases: `InstrumentAccountResolver.php:45-55` (code) and `:60-70` (type). `ChecksToPay`/`EffetsPayable` → `'liability'` at `:61-62`.
- Seeds, all `type => 'liability', is_system => true`: `TunisiaChartOfAccountsSeeder.php:174-175`, `FranceChartOfAccountsSeeder.php:168-169`, `GenericChartOfAccountsSeeder.php:130-131`. Matches spec `…design.md:55-56`.
- No other exhaustive `match` over this enum exists anywhere (all 24 usages are selective enum references), so adding two cases breaks nothing.

**2. The riskiest part of the remediation commit — the resolver's new `type` + `is_active` filters apply to the 7 pre-existing *inbound* purposes used by live flows.** I checked every inbound code against every chart rather than assuming:

| Purpose | Codes | Seeded type | Resolver expects |
|---|---|---|---|
| ChecksToCollect | 5312 / 5112 | asset (`TN:216`, `FR:225`, `GEN:156`) | asset ✅ |
| EffectsReceivable | 413 | asset (`TN:183`, `FR:179`, `GEN:138`) | asset ✅ |
| EffectsInCollection | 5313 / 5113 | asset (`TN:217`, `FR:226`, `GEN:157`) | asset ✅ |
| EffectsDiscounted | 5314 / 5114 | asset (`TN:218`, `FR:227`, `GEN:158`) | asset ✅ |
| InstrumentBankFees | 6275 / 627 | expense (`TN:244`, `FR:252`, `GEN:176`) | expense ✅ |
| VatRecoverableOnFees | 43666 / 44566 | asset (`TN:196`, `FR:194`, `GEN:147`) | asset ✅ |
| DoubtfulReceivables | 416 | asset (`TN:184`, `FR:180`, `GEN:139`) | asset ✅ |

No regression. `is_active` is NOT NULL DEFAULT true (`2025_11_30_090000_create_accounts_table.php:21`), so no NULL-exclusion trap.

**3. Backfill command — CONFIRMED safe.** Dry-run writes nothing on either path (`:86-91` promotion preview, `:103-112` creation preview) and the tests assert non-mutation (`PayableInstrumentAccountsTest.php:100`, `:141`). Wrong-type accounts are reported and skipped with a non-zero exit, never mutated (`:72-83`, `:145`); test asserts the account is untouched (`:125-128`). Companies are iterated explicitly (`:39-44`) — no nullable-`Company` seeder trap. Parent selection (`:46-49`, TN/FR→`40`, else→`4000`) exactly mirrors the chart-selection rule at `ChartOfAccountsService.php:143-147`, including `strtoupper`. Missing-parent fails loud (`:55-64`).

**4. Migrations — CONFIRMED rerunnable/self-guarding.** All three column adds are `hasColumn`-guarded; indexes use `CREATE UNIQUE INDEX IF NOT EXISTS … WHERE … IS NOT NULL` (valid partial-unique on both PG and SQLite ≥3.8); the PG CHECK is `pg_constraint`-guarded (`100100:73-82`) with equivalent SQLite INSERT/UPDATE triggers (`:93-111`); FK existence is introspected (`100200:61-71`). `test_linkage_migrations_are_rerunnable` calls `up()` twice per migration (`:98-101`), and round 2 ran it on real PostgreSQL.

**5. Replay store — CONFIRMED.** Exact match passes / mismatch throws via `hash_equals` (`InstrumentEvent.php:54-59`), asserted at `OutboundIdempotencyStoreTest.php:34-37`. DB-level duplicate rejection asserted at `:40-48`. Digest-required CHECK asserted at `:50-64`. `journal_entry_id`/`movement_id` already exist from Phase ④ (`2026_07_12_100200_create_instrument_events.php:28-29`), so the plan's Task-2 column list is fully satisfied.

**6. Presentation cycle starts at 1** — `100000:19` `default(1)`, asserted `:66-71`. ✅

**7. No weakened tests, no scope creep** — both test files are new (0 deletions); every touched path appears in the plan's Task 1/2 file lists.

## Findings (all Minor — none blocking)

- **[Minor]** `InstrumentAccountResolver.php:29` — the `is_active = true` filter now also gates the 7 inbound purposes. A brownfield tenant that manually deactivated e.g. `5112` will get a hard `MissingInstrumentAccountException` on a previously-working deposit. This is the requested, correct behavior (failing loud beats posting to a disabled account), but it's a live-flow change and pushes to `dev` auto-deploy. *Fix:* add a read-only pre-deploy audit (all companies × all 9 purposes) to the Phase ⑤ deploy checklist.
- **[Minor]** `2026_07_18_100100…php:76-81` — `ALTER TABLE … ADD CONSTRAINT … CHECK` takes ACCESS EXCLUSIVE and validates the full table. Harmless now (all existing rows have `action_key IS NULL`), but on a large brownfield `instrument_events` it blocks during auto-deploy. *Fix:* `NOT VALID` + separate `VALIDATE CONSTRAINT`, or note the lock in the checklist.
- **[Minor]** `OutboundIdempotencyStoreTest.php` — no *positive* assertion that a legacy event (both columns NULL) still inserts. The gate criterion "legacy events remain possible" is only covered transitively by `InstrumentEventsImmutabilityTest`. *Fix:* one explicit insert-with-nulls assertion in this file.
- **[Minor]** `BackfillPayableInstrumentAccountsCommand.php:67→116` — TOCTOU between the existence check and the insert, with no transaction; concurrent runs raise an uncaught `QueryException` on the `(company_id, code)` unique (`2025_12_30_195200_fix_accounts_unique_constraint.php:22`). Ops command, single-run in practice. *Fix:* catch the unique violation and treat it as already-present.
- **[Minor]** `ExpenseMetadata.php:9,130-133` — Expense references the Treasury `PaymentInstrument` model directly, which Rule 6 discourages. It follows the pre-existing precedent in the same file (`PaymentMethod`/`PaymentRepository`, `:10-11`, untouched by this diff), so this is consistent rather than new drift. Flagging only so the umbrella boundary decision stays visible.

I did not re-run the suite; the round-2 dual-engine results (SQLite + isolated PostgreSQL, 13 passed/56 assertions) are consistent with the test bodies I read, and the PostgreSQL run is what empirically covers the partial uniques, expense FK, and digest CHECK.

---

**VERDICT: spec ✅ + quality APPROVED**

Before Wave 2: nothing blocking — but carry the two deploy-checklist items (inbound-purpose resolvability audit across existing companies, and the `instrument_events` CHECK lock) into the Phase ⑤ checklist, since Wave 2's `clear()` will make a null resolve a hard failure on a money path.

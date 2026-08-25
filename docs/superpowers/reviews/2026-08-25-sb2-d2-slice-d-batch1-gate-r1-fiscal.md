# Session B2 · lane B2-4 / Slice D batch 1 — 13 money/fiscal enum CHECKs — GATE r1, fiscal-pos lens

**VERDICT: spec ✅ · quality ACCEPT-WITH-CONDITIONS · merge-blocking: NO. Four conditions, all documentation/promoter-facing (F-1..F-4); no code change demanded.**

- **Lane / branch:** `fix/sb2-d2-slice-d-batch1` @ `3535d55b1` · worktree `.worktrees/sb2-d2-slice-d-batch1` · base `68de40b44`
- **Diff:** `git diff 68de40b44..3535d55b1` = 9 files, +1312/−33 — 5 NEW tenant migrations, 1 NEW Feature test, baseline JSON (−13, 0 additions), register, feature-lane manifest. **No `app/` production code. No `.github/**`. No fiscal Event class, no projection, no device code, no queue, no money/quantity math** — rule 19 and the POS cross-layer contracts of rule 20 are not engaged by this diff.
- **Reviewer:** fiscal-pos-reviewer. **Method: execution, not reading.** Every disposition below is a live run on my own throwaway `autoerp_fiscalgate_test` (PG 5433, `autoerp`/`autoerp_secret`), migrated with `APP_ENV=testing DB_CONNECTION=pgsql … php artisan migrate --force`. All probe scripts live in the session scratchpad; **I modified no branch file** — `git status --porcelain` on the worktree is EMPTY at review end. DB tampering was confined to my own database and reverted in-session (verified: 16/16 constraints validated, `journal_entries_company_id_foreign` restored, no `JE-TAMPER` row). A treasury reviewer ran in parallel on the same branch; nothing was stashed, merged or pushed.
- **Records:** brief `docs/sessions/session-B-2026-08-23/BRIEF-D2-slice-d-batch1.md`; report `docs/sessions/session-B-2026-08-23/REPORT-D2-implementer.md`; priors `2026-08-25-sb-d1-parity-gate-r{1-fiscal,2}.md`, `2026-08-25-sb2-c26-parity-preconditions-gate-r1-fiscal.md`. LEDGER C-26, C-37; owner sheet R-D2/R-D3/R-D8.

---

## 0. Spec conformance — the arithmetic dispute is resolved in the implementer's favour

The brief's "12 columns / baseline 176" is wrong and the report is right. `instrument_events` `from_status` / `to_status` are two register rows (`enum-check-parity-register.md:138-139`) and two baseline keys. **13 columns, baseline 188 → 175.** Independently re-derived, not read off the JSON — my own probe (real `EnumBackedColumnRegistry` + `PgValueSetCheckReader` + `EnumCheckParityAnalyzer`, no PHPUnit) on the migrated schema:

```
baselinefile=175 verdicts={"COVERED":68,"COVERED_BY_COMPOSITE":1,"INTENDED_NARROWER":1,"MISSING":175}
NEW=[]  STALE=[]
```

`PaymentType` has 7 cases (`PaymentType.php:10,13,16,19,22,25,38`), not the brief's 9 — irrelevant to correctness because the SQL is built from `Enum::cases()` (`…130300…:189-195`). `to_status` is nullable as well as `from_status` — confirmed against `information_schema` (below).

---

## 1. Emphasis (1) — can the new CHECKs reject a legitimate fiscal-chain write? **NO. Executed, not argued.**

`journal_entries.status` / `journal_code` and `documents.type` sit under the GL hash chain and the `documents` immutability triggers. Six files run **by path, one per invocation, on PostgreSQL, with all 13 constraints live and validated**:

| file | result |
|---|---|
| `tests/Feature/Accounting/CompleteGLHashChainE2ETest.php` | **OK (6 tests, 37 assertions)** |
| `tests/Feature/Accounting/PostEntryNowAtomicityTest.php` | **OK (5, 15)** |
| `tests/Feature/Accounting/CorrectingEntryGlPostingTest.php` | **OK (32, 59)** — writes `documents.type='correcting_entry'` |
| `tests/Feature/Accounting/CreditNoteGLIntegrationTest.php` | **OK (13, 84)** — writes `documents.type='credit_note'` |
| `tests/Feature/Document/CorrectingEntryEndpointTest.php` | **OK (21, 103)** — asserts BOTH `documents` immutability triggers + the admin `documents.correct` surface |
| `tests/Feature/POS/PosCoreReceiptProjectionCashRoundingTest.php` | **OK (17, 38)** — queue/projection context, no bound `CompanyContext` |

Static confirmation of why: **every** writer of the three fiscal columns goes through an Eloquent enum cast. `JournalEntry.php:79` casts `status` to `JournalEntryStatus::class`; the only writes are `AccountingService.php:604,756,1146,1347` and `AccountingOpeningService.php:683`, all `JournalEntryStatus::Posted`. `grep -rn "DB::table('documents')" app/` yields five call sites, all reads or non-`type` updates. `DocumentType::CorrectingEntry = 'correcting_entry'` (`DocumentType.php:48`) IS in the materialised set — I read the constraint out of `pg_constraint`, all 13 values present.

Column defaults are all inside their sets (`journal_entries.status` `'draft'`, `payments.payment_type` `'document_payment'`, `payments.status` `'pending'`, `vouchers.voucher_kind` `'MPV'`). Nullability, straight from `information_schema`, matches the four `IS NULL OR` constraints exactly: `journal_entries.journal_code`, `payments.origin`, `instrument_events.from_status`, `instrument_events.to_status` = YES; the other nine = NO.

**Collision scan:** the 25 most recently committed local branches (incl. `feat/sc-f0-proforma-output`, `feat/sc-0a0-payment-applicability`) touch **none** of the eight governing enum files. No in-flight lane is about to need a widening migration.

---

## 2. Emphasis (3) — parser / ratchet interaction. Verified live, including a tamper

- **All 13 read `COVERED`** (not `COVERED_BY_COMPOSITE`, not `NARROWER`, not `not_validated`), with the right nullable flag and the right constraint name. Probe output, verbatim:
  `journal_entries.journal_code COVERED nullable=true not_validated=false constraints=chk_journal_entries_journal_code_enum` … (13 rows, all `not_validated=false`).
- **`pg_constraint.convalidated = t` for all 13** — `SELECT count(*) FILTER (WHERE convalidated), count(*) FROM pg_constraint WHERE conname LIKE 'chk_%_enum'` → **16 | 16** (13 new + 3 pre-existing on `documents`).
- **The C-26 AND-refusal is NOT triggered by the `IS NULL OR` form.** `PgValueSetCheckReader.php:173-185` matches `CHECK (col IS NULL OR col = ANY ARRAY[…])` explicitly and only then; `containsUnquotedAnd()` (`:224-243`) never sees an AND because the constraints carry none. Rendered shapes read out of `pg_get_constraintdef`: NOT NULL columns `CHECK ((col)::text = ANY (ARRAY[…]))`, nullable columns `CHECK (((col IS NULL) OR ((col)::text = ANY (ARRAY[…]))))`. **No compound CHECK, no `IS NOT NULL AND` — C-37(iii) honoured.**
- **Armed gate:** `ENUM_CHECK_PARITY_PROTECTED_SEED=da5ae13792e2a5067edde96f85858d2ea37efccf ./vendor/bin/phpunit tests/Architecture/EnumCheckParityTest.php` → **OK (11 tests, 790 assertions)**. `EnumCheckParityDetectorLivenessTest.php` → **OK (47, 102)**. Both match the report exactly.
- **TAMPER (widening):** `ALTER TABLE journal_entries DROP CONSTRAINT chk_journal_entries_status_enum; ADD … CHECK (status IN ('draft','posted','reversed','abolished'));` → probe returns
  `verdicts={"COVERED":67,…,"WIDER":1}` · **`NEW=["journal_entries.status::WIDER"]`**. The gate flags it as a NEW failure, and it cannot be silenced by baselining because the O-31 anti-growth ceiling forbids baseline growth. **Ratchet is armed on a fresh schema.** Reverted.
- **Baseline diff is exactly 13 deletions, 0 additions**; central baseline and acknowledgements untouched. Register denominators reconcile: COVERED 55→68, MISSING 188→175, `*status`-suffixed 16→21 (the five `*status` members of the batch).
- `php tools/feature-lane-manifest-check.php` → **EXIT=0**. `pint --test` on the six new/changed PHP files → `{"result":"pass"}`. PHPStan does not reach these files (`phpstan.neon:6-8` `paths: app/`) — the report's voluntary run is a bonus, not a gate.

---

## 3. Emphasis (4) — census abort semantics vs `tenants:migrate`. **RULING: `throw` is correct. One promoter instruction is missing.**

The question is which command the fleet actually runs.

- Stancl's `tenants:migrate` is **fail-fast**: `Migrate.php:51` calls `tenancy()->runForMultiple(...)`, a bare `foreach` with no try/catch (`Tenancy.php:138-155`). One tenant's `RuntimeException` aborts the remaining tenants.
- The **deploy does not use it**. `apps/api/docker/entrypoint.sh:141` runs `php artisan tenants:migrate-rolling --force`, whose contract is **continue-and-collect** — `RollingTenantMigrationCommand.php:90-92`: `catch (Throwable $e) { // Isolate: never let one tenant's failure abort the fleet.`
- Therefore **a dirty tenant does NOT halt the fleet**, and `throw` (N-6's shape, Q-7's alternative rejected) is the right choice: it gives a named, diagnosable per-tenant failure instead of a blind PG DDL error, and the healthy tenants still migrate.

Executed proof of the abort, on my DB: planted a legacy `journal_code='ZZ'` row, re-ran `migrate --force` →
`Cannot create chk_journal_entries_journal_code_enum: journal_entries.journal_code carries 1 row(s) with the unknown value "ZZ". Find the writer before applying this constraint.` and **zero** `chk_%_enum` constraints on `journal_entries` afterwards.

**Condition (F-4a):** the MIGRATION-BEARING deliverable must say, in one line, "run `tenants:migrate-rolling`, never bare `tenants:migrate`". A promoter who reaches for the Stancl command turns one dirty tenant into an unmigrated fleet.

---

## 4. Emphasis (5) — `down()` where the constraint was never created. **Clean. Executed.**

Driving `down()`/`up()` directly on the five migration objects against the migrated DB:

```
before: 16
after 1st down(): 3        (the 3 pre-existing documents CHECKs)
after 2nd down() (constraints already absent): 3     ← no-op, no error
after up(): 16
after 2nd up() (re-runnable): 16
not validated: 0
```

`DROP CONSTRAINT IF EXISTS` + the `pgsql` + `Schema::hasTable` guards (`…130200…:137-150`) make `down()` safe on a tenant that never got the constraint, and `up()` idempotent.

---

## 5. Emphasis (6) — does the sqlite self-skip mask a non-PG failure? **No masking; but note the CI reach.**

`SliceDBatch1CheckConstraintsTest` on the default sqlite suite: `Tests: 19, Assertions: 0, Skipped: 19` — every method calls `requirePostgres()` (`:189-194`) and the migrations return early off `pgsql`, so on sqlite **there is nothing to fail**: the class asserts only PG-engine semantics (23514, `convalidated`, `information_schema`). Nothing non-PG is hidden.

The report's §7 claim is **correct as far as it goes** and I verified it: `treasury-spine-pgsql` runs `./vendor/bin/phpunit tests/Feature/Treasury` as a whole directory on a real PG service (`ci.yml:1239-1240`), and its `if:` (`ci.yml:1144`) gates on `workflow_dispatch` / `base_ref == 'dev'` / `base_ref == 'main'` — it is **not** parked behind `vars.SELF_HOSTED_RUNNER_READY`. No `.github/**` edit is required. See F-6 for what that job does *not* cover.

---

## FINDINGS (ordered by severity)

### [Important] F-1 — the NOT VALID / VALIDATE split is defeated by Laravel's per-migration transaction; the docblock's lock claims are false as executed
`…130100…` / `…130200…:33-36` / `…130300…` / `…130400…:35-38` / `…130500…:36-39` all assert: "`ADD CONSTRAINT … NOT VALID` — takes only a brief ACCESS EXCLUSIVE lock" and "a SEPARATE `VALIDATE CONSTRAINT` — SHARE UPDATE EXCLUSIVE, does not block readers or writers while it scans."

That is not what runs. `Migrator.php:448-450` wraps `up()` in `$connection->transaction()` whenever `supportsSchemaTransactions() && $migration->withinTransaction`; `PostgresGrammar.php:18` sets `protected $transactions = true`; and **none of the five migrations sets `public $withinTransaction = false`** (`grep -rn withinTransaction database/migrations/tenant/2026_08_25_1*.php` → empty). So the census, `ADD CONSTRAINT … NOT VALID` (`:132`) and `VALIDATE CONSTRAINT` (`:133`) all execute inside ONE transaction, and the ACCESS EXCLUSIVE lock taken at `:132` is held **until COMMIT — i.e. across the whole VALIDATE scan.** Net lock profile is identical to a plain `ADD CONSTRAINT`: full table, ACCESS EXCLUSIVE, for the duration of the scan. The one operational property the brief mandated the split for is not delivered.

**Proven, not inferred:** with a dirty `journal_code` row planted, `chk_journal_entries_status_enum` — successfully created earlier in the same `up()` loop iteration — was **absent** from `pg_constraint` after the abort. Only a transaction can do that.

**Why it matters:** this is a MIGRATION-BEARING lane whose deliverable is read by a promoter deciding when to run it. "Brief lock only" invites running it against a live tenant during business hours; the truth is that every write and read on `documents`/`journal_entries`/`payments` blocks for the scan. Pre-launch tables are small, so the *risk* today is low — the *claim* is wrong today.

**Fix (either, parent/owner to rule):** (a) keep the transaction — the atomic all-or-nothing abort proven in §3 is genuinely safer — and **delete the two lock-behaviour bullets from all five docblocks**, replacing them with the executed truth plus a note that the split is retained for diagnostic value; or (b) add `public $withinTransaction = false;` to all five (safe: `up()` is proven re-runnable and starts with `DROP CONSTRAINT IF EXISTS`) and keep the paragraph. I recommend (a). **Merge-blocking for the branch: NO. Blocking for the promotion note: YES.**

### [Important] F-2 — dead enum cases are being frozen into the DB, and the docblock's FROZEN paragraph is one-sided (R-D2 / R-D3 / R-D8)
`chk_journal_entries_status_enum` now materialises `'reversed'`, and `chk_payments_origin_enum` materialises `'mobile'` and `'api'`. All three are **dead**: `grep -rn "JournalEntryStatus::Reversed" app/` → **zero hits**, and `InstrumentLifecycleService.php:468` states outright that there is "currently NO" writer for `journal_entries.reversed_at`/`reversal_entry_id`; `PaymentOrigin::Mobile` and `::Api` → zero hits each. `VoucherStatus::Expired` is in the same family (R-D2, expiry engines unbuilt).

R-D3 ("JournalEntryStatus::Reversed — implement vs **delete**") and R-D8 ("PaymentStatus dead cases + default") are **open owner questions on this very program** (`docs/superpowers/specs/2026-08-23-state-machine-program-spec-skeleton.md:144-150`; owner sheet `:27`). After this merge, a "delete" ruling is no longer a one-line enum edit: the DB goes `WIDER`, and I proved the consequence live — an over-wide CHECK surfaces as `NEW=["journal_entries.status::WIDER"]` and **cannot be absorbed by the baseline** (growth is refused by the O-31 ceiling). The delete therefore costs a narrowing migration plus a per-tenant census across the fleet, and any surviving `status='reversed'` row aborts that tenant.

The five docblocks' FROZEN paragraph covers only **adding** a case ("A new case therefore needs its own widening migration") and is silent on **removing** one.

**Fix:** one sentence appended to the FROZEN paragraph in all five files ("removing a case is equally migration-bearing — the CHECK must be NARROWED, censused per tenant, in the same lane"), and a LEDGER row coupling R-D2/R-D3/R-D8 to this batch so the ruling is made with the cost visible. **Merge-blocking: NO.**

### [Minor] F-3 — the NOT NULL census branch over-rejects relative to its own constraint, on an incorrect stated rationale
`…130200…:157-165` (and the identical block in the other four) claims the `col IS NULL OR …` violation predicate exists "so that a column PostgreSQL still allows to be NULL … aborts the tenant here **instead of failing VALIDATE**". VALIDATE would **not** fail: for a NULL input, `col IN (…)` evaluates to NULL, which is not FALSE, and PostgreSQL admits it. So the census aborts a tenant for a row the constraint would accept. The *behaviour* is defensible (fail loud on NOT NULL drift); the *justification in the comment is wrong*. Fix the comment, keep the code.

### [Minor] F-4 — 13 PRE-flight census queries, zero POST-flight verification; and the rolling-migrate instruction is missing
(a) See §3 — the deliverable must name `tenants:migrate-rolling`.
(b) Executed: a `NOT VALID` constraint still reads **`COVERED`** in the parity gate (`journal_entries.status COVERED … not_validated=true`, `NEW=[]`) — `not_validated` is rendered only as a register *note* (`write-enum-check-parity-baseline.php:204-205`) and never changes a verdict (`EnumCheckParityAnalyzer.php:112`). So neither gate can detect a half-applied rollout **on a real tenant**; only `SliceDBatch1CheckConstraintsTest::test_every_constraint_exists_and_was_validated` (`:166-187`) can, and it runs against the CI test database, never against a tenant. Add one post-migration query to the promoter block:
`SELECT count(*) FILTER (WHERE convalidated) AS validated, count(*) AS total FROM pg_constraint WHERE conname LIKE 'chk_%_enum';` — expect **16 | 16** per fully-migrated tenant.

### [Minor] F-5 — report §8.5 default census is incomplete
It names `payments.payment_type` and `journal_entries.status`; `payments.status` (`'pending'`) and `vouchers.voucher_kind` (`'MPV'`) also carry defaults. All four are inside their sets — verified. No code impact; the record should be complete because a future enum rename has to move four defaults, not two.

### [Minor · inherited, not introduced] F-6 — what actually executes these CHECKs in CI
`treasury-spine-pgsql` runs exactly three selectors on PG (`ci.yml:1239-1246`): `tests/Feature/Treasury`, `tests/Feature/Accounting`, `tests/Unit/Treasury`. **No PG job runs `tests/Feature/Voucher`, `tests/Feature/Document` or `tests/Feature/POS` as a directory**, so the real write paths behind `chk_vouchers_*` and `chk_documents_type_enum` are guarded in CI only by the synthetic liveness pin plus the individually-allowlisted `CorrectingEntryEndpointTest`. Separately, `on.push.branches: [main]` (`ci.yml:5`) means the repo's own promotion (a direct fast-forward push to `dev`) triggers **no workflow at all** — this whole gate runs only on PR→dev/main or `workflow_dispatch`. Inherited S-17 condition; recorded so the batch-2 brief does not assume coverage it does not have. I compensated by running `CorrectingEntryEndpointTest`, `CorrectingEntryGlPostingTest` and `CreditNoteGLIntegrationTest` on PG myself (all green, §1).

### [Observation · NOT a lane defect] F-7 — `tests/Feature/Fiscal/ApplyFiscalEventProjectionJobTest.php` is red on PostgreSQL
`Tests: 18, Assertions: 90, Errors: 1, Failures: 1` on PG — (i) `SQLSTATE[23503] fiscal_event_projections_fiscal_event_id_fk` on a test that deliberately points a projection at a missing `fiscal_events` row (SQLite does not enforce the FK), and (ii) a `canonical_bytes` **stream-resource identity** comparison (`:290`) that can never match on PG. The same file is **OK (18 tests, 98 assertions)** on its designed sqlite lane. Neither table carries any constraint from this batch. Pre-existing PG-incompatibility of that class; filed for LEDGER, not for this lane.

---

## Conditions before merge/promotion
1. **F-1** — correct the lock-behaviour paragraph in all five migration docblocks (or opt out of the transaction), and carry the corrected statement into the promotion note. *(blocking for the promotion note, not for the branch)*
2. **F-2** — one sentence on case REMOVAL in the FROZEN paragraph of all five docblocks; LEDGER row coupling R-D2/R-D3/R-D8 to this batch.
3. **F-3** — fix the incorrect NOT-NULL-census rationale comment.
4. **F-4** — deliverable gains "use `tenants:migrate-rolling`, never bare `tenants:migrate`" + the post-flight `convalidated` verification query.

## Residuals for LEDGER
- **R1 (F-2):** R-D2/R-D3/R-D8 "delete" rulings are now migration-bearing across the fleet. Cost must be on the ruling sheet.
- **R2 (F-4b):** no gate proves a *tenant* got a VALIDATED constraint; the parity gate reads `NOT VALID` as COVERED by design.
- **R3 (F-6):** `tests/Feature/Voucher` / `tests/Feature/Document` / `tests/Feature/POS` have no PG lane; `on.push.branches: [main]` means the promotion path triggers no CI. S-17 family.
- **R4 (F-7):** `ApplyFiscalEventProjectionJobTest` cannot run on PostgreSQL (FK + bytea-stream identity). Pre-existing.
- **R5:** brief template defects for batch 2 — 12-rows-vs-13-columns, `PaymentType (9)`→(7), `to_status` nullability unflagged. Already flagged by the implementer; must be fixed before the template is reused.
- **R6 (C-37 carry-overs, unchanged by this batch):** F-4 acknowledgement-content mutability, R2-3 pin tests behind the class-wide PG skip, `ci-pin/enum-check-parity-r1` not yet created (O-31).

## Evidence appendix — every command I ran on PostgreSQL
| check | result |
|---|---|
| `artisan migrate --force` on `autoerp_fiscalgate_test` | 276 tables, exit 0; 16 `chk_%_enum`, **all `convalidated=t`** |
| `SliceDBatch1CheckConstraintsTest` (pgsql) | **OK (19, 70)** |
| `SliceDBatch1CheckConstraintsTest` (sqlite) | 19 tests, 0 assertions, **19 skipped** |
| `EnumCheckParityTest` armed (`da5ae1379…`) | **OK (11, 790)** |
| `EnumCheckParityDetectorLivenessTest` | **OK (47, 102)** |
| independent registry+reader+analyzer probe | `COVERED 68 / COMPOSITE 1 / INTENDED_NARROWER 1 / MISSING 175`, `NEW=[]`, `STALE=[]`, 13/13 COVERED |
| tamper: CHECK widened with `'abolished'` | `NEW=["journal_entries.status::WIDER"]` ✅ |
| tamper: CHECK replaced with `NOT VALID` | still `COVERED`, `NEW=[]` — F-4b |
| dirty-row census abort + rollback | RuntimeException names table/column/value/count; **0 constraints created** (proves the transaction — F-1) |
| `down()` ×2 then `up()` ×2 | 16 → 3 → 3 → 16 → 16, `not validated: 0` |
| `CompleteGLHashChainE2ETest` | OK (6, 37) |
| `PostEntryNowAtomicityTest` | OK (5, 15) |
| `CorrectingEntryGlPostingTest` | OK (32, 59) |
| `CreditNoteGLIntegrationTest` | OK (13, 84) |
| `CorrectingEntryEndpointTest` | OK (21, 103) |
| `PosCoreReceiptProjectionCashRoundingTest` | OK (17, 38) |
| `ApplyFiscalEventProjectionJobTest` pgsql / sqlite | 1 error + 1 failure / **OK (18, 98)** — F-7 |
| `tools/feature-lane-manifest-check.php` | EXIT=0 |
| `pint --test` (6 files) | `{"result":"pass"}` |
| branch worktree scope | `git status --porcelain` **empty** |

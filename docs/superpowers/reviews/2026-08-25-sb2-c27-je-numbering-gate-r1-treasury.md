# GATE r1 — Session B2 lane B2-1 / LEDGER C-27 (journal-entry + income numbering, tenant scope) — TREASURY lens

**VERDICT: ACCEPT-with-conditions — merge-blocking: NO.** spec ✅ · quality APPROVED-with-conditions.

- Branch `fix/sb2-c27-je-numbering-tenant-scope` @ `eaccb7323`, base dev `834c8c017`.
- Reviewer: treasury lens. Worktree read-only (a stock-gl reviewer runs in parallel on the same branch — **no branch file was modified by this gate**).
- Throwaway DB: `autoerp_treasurygate_test` on 127.0.0.1:5433 (left in place; `dropdb` when done).
- Everything below that claims behaviour was **executed**, not inferred.

---

## 1. What was verified by execution

| Check | Result |
|---|---|
| `journal_entries` unique shape (live PG, migrated from this branch) | `journal_entries_tenant_id_entry_number_unique` on `(tenant_id, entry_number)` is the **only** unique index touching `entry_number`; chain uniqueness is separate and per company (`uniq_je_company_chain_sequence` on `(company_id, chain_sequence) WHERE chain_sequence IS NOT NULL`). **Nothing narrower exists.** Matches `apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:41`. |
| `documents` unique shape | `documents_tenant_id_type_document_number_unique` on `(tenant_id, type, document_number)` — the Income scan's `where('type', DocumentType::Income)` matches the index exactly (`IncomeService.php:236-241`). |
| New JE test on PG (branch) | `OK (5 tests, 24 assertions)` |
| New Income test on PG (branch) | `OK (2 tests, 9 assertions)` |
| **Revert probe** — same two test files run against the UNFIXED base code (main checkout `dev` `6f857a2ff`, whose `GeneralLedgerService.php` / `IncomeService.php` are byte-identical to base `834c8c017`, verified by `git diff --stat`) | JE: `Tests: 5, Assertions: 13, Failures: 4` with `SQLSTATE[23505] … "journal_entries_tenant_id_entry_number_unique" DETAIL: Key (tenant_id, entry_number)=(…, JE-2026-000001) already exists`. Income: `Tests: 2, Assertions: 6, Failures: 2` with `… "documents_tenant_id_type_document_number_unique"`. **The new tests are genuinely red without the fix.** (Probe copies were deleted; `git status` on the main checkout is clean.) |
| Regressions on PG, by path, branch | `CreateJournalEntryTest` OK 11/29 · `ChainSequenceUniqueIndexTest` OK 2/3 · `ExpensePostTest` OK 5/16 (the Q-11 PG lock test now executes) |
| PHPStan level 8, 3 prod files + 2 test files, live-PG env | `[OK] No errors` |
| Pint `--test`, same 5 files | `{"result":"pass"}` |
| `php tools/feature-lane-manifest-check.php` | `OK — 1423 Feature classes in 74 groups`; parked 70 groups / 1174 classes; debt 1/1 |
| Lock-key namespacing (computed in PG) | `hashtextextended('<tenant-uuid>',0)=6419589265534726300` vs `'journal_entry_number:<uuid>'=9108026712910982132` vs `'income_number:<uuid>'=-4345797045288309695` vs `'expense_number:<uuid>'=7737011291430557443` — **the tenant numbering key cannot alias the bare-uuid company chain key.** |
| Local census (independent re-run) | `autoerp`, `autoerp_test`, `autoerp_test2`, `autoerp_lane_test`: `tenants_with_multi_company=0`. `pg_database` on 5433 has **no `tenant_*` database at all** (only `iziposcentral*`) — the db-per-tenant stack is not provisioned locally, so the implementer's "zero exposure" is a local artefact, not a fleet answer. |

## 2. Scope match (gate item 1) — PASS

- `GeneralLedgerService.php:5628` — `generateEntryNumber(string $tenantId, string $companyId)`; scan `->where('tenant_id', $tenantId)->where('entry_number','like',"JE-{$year}-%")` (`:5641-5645`); format `sprintf('JE-%s-%06d', …)` (`:5649`) — **prefix/format unchanged**.
- 44 occurrences of `generateEntryNumber(` in the file = 43 call sites + 1 declaration; **zero single-argument calls remain** (`grep -nE 'generateEntryNumber\(\$[A-Za-z_>-]+\)'` → empty).
- Spot-checked 14 sites against the `tenant_id` the adjacent `JournalEntry::create` writes — all identical expressions: `:157/:159` (`$invoice->tenant_id`), `:241`, `:364/$user`, `:463/:461`, `:927`, `:1015`, `:1192-1194` (`$tenantId`), `:2921/:2929` (`$voucher->tenant_id`), `:3944-3951` (`$payment->tenant_id`), `:4212/:4215`, `:4334-4336` (`(string) $receipt->tenant_id`), `:4504-4506`, `:4575-4577` (`$command->tenantId`/`$command->companyId` — the `CreatePOSChargeJournalEntryCommand` DTO, no context), `:5270-5272` (`$locked->tenant_id, $locked->company_id`).
- **No `CompanyContext` / `tenant()` / request-bound resolution anywhere in the new code** — every Horizon/projection site (`createPOSPaymentEntry`, `createPOSRefundReversalEntry`, `createPosCashRoundingEntry`, `createPosToleranceWriteoffEntry`, `createPOSChargeEntry`, `createRefundCompensationEntry`, the instrument entries) takes the tenant from the entity/DTO. This is the correct reading of rule 20 (workers bind no context).
- No money/quantity arithmetic was added or moved; no `(float)`, no `getScale()` — rule 19 is not engaged by this diff.
- `IncomeService.php:156` passes `$income->tenant_id` inside the `DB::transaction` opened at `:153`; there is exactly **one** call site (`grep -n generateIncomeNumber` → `:156` + the `:226` declaration), correcting the brief's "three call sites".
- `JournalEntryController.php:203-217` takes the byte-identical `journal_entry_number:{tenantId}` key inside the `DB::transaction` opened at `:94`.

## 3. Lock ordering / deadlock (gate item 2) — PASS, with a durability caveat

Order taken inside `generateEntryNumber` is tenant key **then** company key (`:5630-5638`). `sealAndPersistEntry` is untouched apart from one comment (`:3779-3782`) and still takes only the per-company key — correct, the chain and `chain_sequence` are per company.

I enumerated **every** `pg_advisory_xact_lock` in `apps/api/app/` and every external `postEntryNow`/`postEntry` caller to look for a transaction that takes the bare-company key **before** the tenant key (which would be a true ABBA against every normal minter, and would need only ONE company to deadlock):

- `AccountingService.php:1305-1307` (`postCorrectingEntryGl`) takes the company key first — but mints `CORR-…` itself and never calls `generateEntryNumber` in that transaction; its only caller (`CorrectingEntryService.php:166-171`) adds nothing after it.
- `TreasuryMovementService.php:245-247` (`transfer`) takes the company key first — its only caller `RepositoryTransferService.php:74-101` creates the GL entry **before** calling `transfer()` (so the tenant key is already held), and in the non-cross-GL case no number is minted at all.
- The two `postEntryNow($existing, …)` branches inside GLS that precede a mint (`:5157-5164`, `:5380-5387`) both `return` before reaching the mint.
- Every other GL caller (POS `ReceiptPaymentService:326-337/424-430`, `TreasuryReceiptBridge:567-575/1487-1498`, `InstrumentLifecycleService:433-489`, `OutboundInstrument*`, `RefundCompensationService:271-287`) is mint-then-post, i.e. tenant→company.

**Conclusion: no cycle exists today.** Both companies of a tenant, and the manual-JE controller, all acquire `journal_entry_number:{tenant}` first, so concurrent posts serialise rather than deadlock; the company chain key is only ever acquired alone or second.

## 4. Findings

### [Important] F-1 — a THIRD `JE-` minter exists and is missing from the census
`apps/api/app/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php:516-527` (and its twin `:576-588`) mint `sprintf('JE-%d-%06d', …)` into the same sequence with **(a)** a company-scoped scan, **(b)** no advisory lock, and **(c)** *no prefix filter at all* — `JournalEntry::where('company_id',…)->whereYear('entry_date',…)->orderByDesc('entry_number')->first()`. Because `'OB-' > 'JE-'` lexicographically, in a company that has opening entries this reads an `OB-YYYY-NNNNNN` row and mints `JE-` from the OB counter; a `CORR-…` max would parse six hex characters through `(int) substr(…, -6)`.
Why it matters: the lane's own docblock claims "one sequence, one serialisation point" (`JournalEntryController.php:193-199`) — that claim is not true while this minter exists.
Mitigation that keeps this non-blocking: it is **dormant** — `generateYearEndAdjustment` / `generateReversalEntry` have zero production callers (only `tests/Feature/Compliance/UninvoicedDNReportTest.php`), and `app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php:78` records and AST-enforces exactly that.
Fix: add a LEDGER row (C-27b) so it is fixed *before* anyone wires the year-end adjustment; do not touch it in this lane.

### [Important] F-2 — C-27 is only PARTIALLY closed; do not close the LEDGER row
`AccountingOpeningService.php:468-492` still scans `->where('company_id', $companyId)` for `OB-{year}-%` and locks `hashtext('gl-ob-seq:{companyId}:{year}')` against the same tenant-wide unique index — verified in the branch. Same for `ArApOpeningService` (`HIST-`), `Inventory/…/OpeningBalancePostingService.php:296`, `ResetOpeningBalanceService.php:263`. A second company in a tenant therefore still 23505s **on opening balances**, which is the first thing a newly-provisioned second company does. The implementer flagged all four as Session-W residuals (report §10) — correct scoping, but the LEDGER row C-27 must stay OPEN and must name the openings lane, or the "company B is GL-dead" symptom will be reported closed while it is still reachable.

### [Important] F-3 — the load-bearing lock order is prose-only, with no guard
The ordering rule lives in three comments (`GeneralLedgerService.php:5605-5622`, `:3779-3782`, `JournalEntryController.php:197-201`). Nothing static or runtime prevents a future path from taking the bare-company key (`GLS:3782`, `AccountingService:1306`, `TreasuryMovementService:246`) and *then* minting inside the same transaction — which is exactly the ABBA this design forbids, and which nothing in CI would catch. Cheapest durable guard: funnel both acquisitions through one private `withEntryNumberLock(string $tenantId, string $companyId)` helper and add an architecture test asserting `pg_advisory_xact_lock` with a bare-uuid binding never appears before a `journal_entry_number:` binding in a posting query log. Recommend as a follow-up, not a merge blocker.

### [Important / owner] F-4 — the numbering-contract change needs a RULING, not just an ack
The commit message carries the ack line (JE and INC numbers now interleave across the companies of a tenant, so each company's own register has gaps). The gate's position: that is not merely cosmetic. Per-entity **continuity** of `EcritureNum` is the property a FEC/PCG audit looks at, and the alternative fix — widen the unique to `(tenant_id, company_id, entry_number)` and keep numbering per company — was deferred *because* it is an owner ruling, not because it was judged wrong. The owner must choose explicitly:
  (a) accept tenant-wide interleave with per-company gaps (what this branch ships), or
  (b) widen the index + keep per-company numbering (migration-bearing, and it re-opens the FEC-quoted-identifier question).
Nothing in the code asserts contiguity today (no `entry_number` sequentiality/gap check exists anywhere in `app/`), so (a) is technically safe to merge now and reversible later — but the ruling should be recorded before a real multi-company tenant is provisioned.

### [Important / promotion] F-5 — the fleet census has NOT been run
Independently confirmed: port 5433 hosts **no `tenant_*` database**, so the implementer's census (report §8) could only look at scratch DBs. Before promotion, run the Q-11 §C queries in a `tenants:run` loop against staging (and prod when it exists). The change needs **no data repair** — the unique index guarantees no pre-existing duplicate can exist — but the census is what tells you which tenants were previously bricked and now need a "you can post again" note.

### [Minor] F-6 — T3 cannot fail
`tests/Feature/Accounting/JournalEntryNumberingTenantScopeTest.php:146-175` asserts that PostgreSQL's `pg_try_advisory_xact_lock` is mutually exclusive across two connections. It never invokes the service, so it is green on the unfixed base — my revert probe confirms it: on base, 4 of the 5 tests failed and **T3 was the one that passed**. The implementer says as much in report §10.6, and the real ordering proof (T2, `:107-136`, query-log index comparison) is honest and does go red on base. Record only; no change required.

### [Minor] F-7 — the Income pin runs nowhere in CI
`tests/feature-lane-manifest.json` raises `groups.Income.classes` 3 → 4 in the **parked** `feature-lane-fiscal-finance/Income` lane, so `IncomeNumberingTenantScopeTest` is gated behind `vars.SELF_HOSTED_RUNNER_READY` and will not execute until the owner flips it. The JE pin is fine — `treasury-spine-pgsql/feature-accounting` is live and PG-backed, which the PG-only T2/T3/T5 pins require. Pre-existing lane policy, recorded so nobody mistakes "manifest updated" for "covered".

### [Minor] F-8 — the `999999` ceiling is now shared tenant-wide
`sprintf('JE-%s-%06d')` + `(int) substr($last, -6)` (`GLS:5647-5649`, same shape in `IncomeService:243-245` and `JournalEntryController:220-226`) silently wraps at 1,000,000 entries in a year: the 1,000,001st mints `JE-2026-1000000`, whose last six characters are `000000`, so the next allocation restarts at 1 and 23505s forever. Pre-existing, but the ceiling is now consumed by ALL companies of a tenant rather than one. A one-line `throw` above `sprintf` when `$nextNumber > 999999` would convert a silent brick into a loud one.

### [Minor] F-9 — two ordering observations, both pre-existing, recorded for the register
1. The 8 hoisted `Company::findOrFail($companyId)` calls (e.g. `GLS:541-542`, `:5405-5406`) now run *before* the advisory locks instead of after. Harmless — an unlocked `SELECT` on `companies`, no row lock, and the value is only used for the lock key and the insert.
2. `reverseInventoryMovementEntry` (`GLS:5252-5257`) takes a `lockForUpdate()` row lock on `journal_entries` **before** the advisory keys, inverting the canonical "advisory → row" order documented in `GoodsReceiptService.php:448-478` and `TreasuryMovementService.php:238-247`. The old code had the same inversion with the company key, and the counterpart row is company-scoped, so the widening adds no new cycle. No action in this lane.

### Non-findings I checked and cleared
- Lock key aliasing (computed hashes above) — clean.
- `sealAndPersistEntry` untouched apart from a comment; `chain_sequence` stays per company and is asserted 1/1 by `JournalEntryNumberingTenantScopeTest:86-99`, which I ran green on PG and red on base.
- Tests use `RefreshDatabase`, real models, `RolesAndPermissionsSeeder`, real HTTP endpoints (`/api/v1/expenses/{id}/post`, `/api/v1/journal-entries`, `/api/v1/incomes/{id}/post`) and mock nothing under test; PG-only pins `markTestSkipped` rather than passing silently (`:109-111`, `:148-150`, `:183-185`, Income `:70-72`).
- No `payment_repositories` field, treasury movement, balance or GL-account semantics are touched by this diff; no `account_id`/`gl_account_id` conflation risk.

## 5. Conditions (all non-code; none block the merge)
1. **Do not close LEDGER C-27** on this merge — carry it to the openings lane (F-2), and file **C-27b** for `UninvoicedDeliveryNoteService` (F-1).
2. **Owner ruling on the numbering contract** (F-4) recorded on the owner sheet before a second company is provisioned for a live tenant — interleave-with-gaps (ship as-is) vs widen-the-index (migration-bearing).
3. **Run the fleet census on staging tenant DBs before promotion** (F-5); paste counts into the lane record. No data repair is implied.
4. Re-resolve the three manifest numbers (`gated_ceiling` 1174, `Accounting` 87, `Income` 4) against live dev at merge — the checker passes here, but three other lanes are in flight against the same file.
5. Optional but recommended before the next GL lane: the `withEntryNumberLock()` helper + ordering architecture test (F-3), and the `> 999999` throw (F-8).

## 6. Method / honesty notes
- I did not modify any file on the branch or in its worktree. The revert probe was executed by copying the two new test files into the **main checkout** (`dev` `6f857a2ff`, identical base content for the two production files), running them, and deleting the copies; `git status` on the main checkout is clean.
- No `git stash` was used; nothing was pushed; nothing was merged.
- Two-connection racing posts were not attempted (same 90-second tool-budget limit the implementer declared); the ordering claim rests on the query-log pin (T2) plus my static enumeration of every advisory-lock site in §3, and is stated at that strength.

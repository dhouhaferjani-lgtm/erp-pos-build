The file write wasn't approved — that's fine, the verdict is the deliverable and it's below. (Say the word if you want it written to `.gates/gate-t5b-gate-2-verdict-treasury.md`.)

---

REJECT

Reviewed `git diff e06d2a831...HEAD` (single commit `cc11bbcff`) in full, plus adjacent parser/schema/enum/config code and the Gate 1 R2 verdict. No Criticals. Two Majors block: a **one-way void → re-import dead end**, and the **repository row lock held across a full file re-parse** on a lock the treasury movement hot path shares.

**Disclosure: I could not execute PHP or PHPUnit here.** All findings are from code inspection; your reported figures (21/117 SQLite, 12/68 PG, Pint, PHPStan, `git diff --check`) are taken as reported. Where I verified from config rather than a test, I say so.

## Findings

### BLOCKER
None.

### MAJOR

**M1 — `StatementImportService.php:260-269` + `2026_07_19_110001_create_bank_statements.php:45` — voiding permanently bricks re-import of that file.** `rejectDuplicateFile` matches `(payment_repository_id, source_file_sha256)` with **no status filter** (`:262-265`), and `bank_statements_repository_file_unique` (`110001:45`) is unconditional. A `Voided` statement reserves its sha256 forever.

`period_start`, `period_end`, `opening_balance`, `closing_balance` are **all client-supplied at confirm** (`ConfirmBankStatementRequest.php:22-25`) and never validated against parsed content — nothing checks the period brackets the parsed value dates, or that `closing_balance` agrees with `detected_closing` (computed at `:92-93`, then discarded). Mistyping one is the likeliest operator error, and §5.3.4 void is the only remedy the design offers. After void there is no delete path and no re-import path. Spec §5.1 mandates the uniqueness but never reconciles it with §5.3.4 — unhandled interaction, not a spec instruction. Unexercised by tests: `test_void_requires_zero_allocations_and_executions` (`StatementImportFlowTest.php:199-207`) voids one file then uploads a **different** CSV.

**M2 — `StatementImportService.php:135-153` — the `payment_repositories` lock is held across a full re-parse.** Transaction opens `:135`, `lockForUpdate()` `:146`, `parseStored` re-reads and re-parses the whole file at `:153` **inside** it. Uploads cap at 20 MB (`UploadBankStatementRequest.php:30`); the XLSX path runs PhpSpreadsheet plus a second raw-XML ZIP pass. That is the same row lock `TreasuryMovementService.php:61-66` takes for **every** treasury movement — so a large confirm blocks all POS settlements, payments and transfers into that bank account, triggerable by any `bank-statements.import` holder. The lock isn't what makes the reparse safe: the file is integrity-checked before the transaction (`:123-129`), so the parse is deterministic. Replay safety comes from `:152` and `:154`, which must stay inside.

**M3 — five guard branches this gate explicitly asserts have zero coverage.**

| Guard | Impl | Test |
|---|---|---|
| Non-bank repository type | `:244-246` | none |
| Inactive repository | `:247-249` | none — factory hardcodes `is_active => true` (`StatementImportFlowTest.php:375`) |
| Inactive profile | `:255-257` | none — same, `:391` |
| Token expiry (4 h) | `:110-112` | none |
| Integrity mismatch | `:126-129` | none — `:312` appends to the **ciphertext**, so it exits at `decodeToken` (`:346-349`) and never reaches the hash compare |

Also untested: the `$hasAllocations` void branch (`:216-219`) despite the test's name, and `Reconciled → Voided` rejection (`:213`). The code reads correct; the gate claims coverage the suite doesn't provide.

### MINOR

- **m1 `:56-64`** — staged preview files never GC'd; only deleted on parse failure (`:79`). Abandoned previews leave full bank histories on disk forever. `grep` over `apps/api/app` + `routes` for `bank-statements/` returns only the write site.
- **m2 `ConfirmBankStatementRequest.php:24-25`** — money regex `/^-?\d+(?:\.\d+)?$/` lacks rule 19's scale ceiling and `numeric`. `0.00001` passes validation then trips `canonicalMoney` (`:369-372`) — 422 via `error.code=BUSINESS_ERROR` (`bootstrap/app.php:356-363`) instead of field-bound `errors`.
- **m3 `StatementRowMapper.php:85` vs `:99`** — `droppedZeroAmountRows` now conflates balance-only rows with genuine zero-amount rows. Check 2 wants these distinct.
- **m4 `:84-94`** — no mapped-row total surfaced (derivable, not reported).
- **m5 `:120,131` vs `:146,:168`** — currency/scale resolved from the *unlocked* repository; persisted currency read from the **locked** row. **Verified unreachable today**: `PaymentRepositoryController` has no currency-update path (`grep` returns only reads at `:271`, `:332`). Hardening only.
- **m6** — Gate 1 carry-overs m3/m5/m6 still open (out of range; noting so they aren't lost).

## Eight-invariant table

| # | Invariant | Verdict | Evidence |
|---|---|---|---|
| 1 | Active bank repo + active bound profile; private disk; hash before store; duplicate rejected with id | **PASS (M3)** | Guards `:244`,`:247`,`:250-253`,`:255`. Hash `:49` **precedes** storage `:57`; `rejectDuplicateFile` `:53` precedes both. Private root verified from `config/filesystems.php:35`; `serve => true` is **not** an exposure — visibility defaults `private`, so `ServeFile::hasValidSignature` requires `hasValidRelativeSignature()`. 422+id at `BankStatementController.php:141-147`. Tests `:118`,`:253` (`assertDirectoryEmpty` `:264` proves rejection precedes storage) |
| 2 | Preview persists nothing; six signals | **PASS (m3,m4)** | `:95-96` zero rows, `:79-80`/`:114-115` zero deltas; signals at `:84-94` |
| 3 | Token binds seven fields; verifies age/ownership/integrity; reparses | **PASS (M3)** | All seven `:69-77`; `Crypt::encryptString` is authenticated so unforgeable. Ownership `:107`, age `:110`, existence `:123`, `hash_equals` `:127`, reparse `:153`. No client line data accepted anywhere |
| 4 | Locks, replay-checks, skips fingerprints, `acknowledge_empty`, atomic `Imported` | **PASS (M2)** | `:146-147`,`:151`,`:152`,`:154` (`:296-298`),`:155-157`, one transaction `:135-206`, status `:173`. Tests `:132`,`:154` |
| 5 | Currency historical truth; must match; rules 19/20 | **PASS (m2,m5)** | Persisted `:168` (schema `110001:25`); match `:120`; `canonicalMoney` `:367-375` = scale regex + `bcformatStrict`. Scale always explicit-currency (`:131`,`:324`). `grep` for `(float)`/`floatval`/`number_format`/no-arg `getScale()` across service + both controllers: zero hits |
| 6 | Continuity vs previous **reconciled**, warns not blocks; location from repo | **PASS** | `:312-334` filters `Reconciled` `:316`, `bccomp` at resolved scale `:325`, surfaced in `meta` (`BankStatementController.php:116`), never throws. `location_id` `:195`. Test `:171` |
| 7 | Void only `Imported\|Reconciling → Voided`, zero allocs/execs, locked | **PASS (M1,M3)** | Lock `:212`, `canTransitionTo` `:213` (`BankStatementStatus.php:16-26` — `Reconciled→Voided` and `Voided→*` both false), `:216-219`, `:220-223` in-transaction. Scoping `:128-139` (tested `:283`) |
| 8 | Pure staging; parser follow-up preserves Gate 1 | **PASS** | Zero GL/movement references in all three new production files. Balance detection `:77-88` precedes both drops; `transactionAmountColumnsAreBlank` `:262-273` branches correctly on both conventions; inert when no balance columns mapped — no Gate 1 regression path |

## Staging purity

Clean, and **proven rather than asserted** — `:79-80`/`:114-115` is a count *delta* assertion, so it would catch an indirect write via an event listener, not just a direct one. Nothing stamps `payment_repositories.last_reconciled_*`, per §5.4. The only non-DB side effect is the staged file write (`:57`), correctly rolled back on parse failure (`:78-82`) but not on abandonment (m1).

## Replay and concurrency

**Replay sound.** Re-confirming a token: `rejectDuplicateFile` `:152` fires inside the lock → 422 with the existing id; the DB unique index is a hard backstop. Different-file overlap is handled separately by the locked fingerprint filter `:154` — file-identity and row-identity are distinct concerns and both are enforced. Right split.

**Concurrency correct but coarse.** Two confirms on one repository serialize on the repository row lock `:146`, and that is what actually makes the read-then-insert at `:152` and the `whereIn` at `:293-299` phantom-safe — `lockForUpdate` on that query locks only *existing* rows, so it alone wouldn't prevent a concurrent insert. The reasoning is right; the blast radius is the problem (M2). Lock order is repository → profile (`:146-147`), consistent. Wave 3 adds `repository_movements` locking beneath this same lock, which makes fixing M2 now the cheaper path.

## Test quality

Real behaviour throughout, no tautologies, no mocked subject; `RefreshDatabase` `:34` + `RolesAndPermissionsSeeder` `:63`. Two standouts: `:253` uses `assertDirectoryEmpty` to prove ownership rejection *precedes* storage rather than merely returning 422; `:267` drives the permission matrix through real `can()` plus live 403s — verified against the seeder (`RolesAndPermissionsSeeder.php:714` grants view/import/reconcile to `accountant`; `reopen` reaches only `admin` via `syncPermissions(Permission::all())` `:458`), matching §7's admin-only reopen. Rule 12 satisfied: all ten routes inside the existing group with `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` (`routes.php:35`), each with its own `can:` (`:267-296`). The five-route claim is accurate; profiles are a separate prefix and also covered (`:225`). Note `Storage::fake('local')` `:56` means check 1's storage location is verified by me from config, not by the suite.

---

**VERDICT: spec ❌ + quality CHANGES-REQUESTED**

Fix before merge: hoist `parseStored` out of the `DB::transaction`/`lockForUpdate` block in `StatementImportService::confirm` (keeping the duplicate-file check, fingerprint filter and inserts under the lock), exclude `Voided` from `rejectDuplicateFile` and make `bank_statements_repository_file_unique` partial on `status <> 'voided'`, and add the six missing tests (inactive repository, inactive profile, non-bank type, token expiry, integrity mismatch, void-blocked-by-allocations).

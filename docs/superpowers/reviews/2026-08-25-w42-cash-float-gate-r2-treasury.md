# GATE r2 — treasury/GL lens — W4-2 + W4-10 (VERIFY-ONLY)

**Lane** `fix/campaign-w42-opening-cash-float` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w42-cash-float` · HEAD `c0fc15b8d`
**Fix round** `4250c54a0`, `40989770c`, `8d67e0f6f` · handback `c09dd0897` · dev merged in at `562cb1331` (current dev tip — re-verified)
**r1** `docs/superpowers/reviews/2026-08-25-w42-cash-float-gate-r1-treasury.md` (F-1..F-12) · **Migration** NONE (re-confirmed: `git diff 562cb1331 HEAD` names no file under `apps/api/database/migrations/`)

## VERDICT

**spec ✅ / quality CHANGES-REQUESTED — merge-blocking: YES, on G-1 only.**

**All twelve r1 findings are closed, and I proved the five that matter by tampering, not by reading the
handback.** Reverting the production code turns the new tests red, one by one. The manifest resolves to
**1174** and the checker says so by execution. deptrac, PHPStan, pint, the lint ratchet and the inherited
Accounting reds are all at exact parity with the current dev tip, measured on dev, not asserted.

What blocks merge is the *consequence* of the F-7 fix, not the fix itself: the new
`OPENING_CASH_NOT_FULLY_SEEDED` refusal fires at **validate** time into `errors._batch`, and the wizard
throws that key away. The legacy four-column sheet that F-1 just un-blocked now uploads, validates every
row as VALID, reports "0 invalid", refuses to advance, and tells the operator **nothing**. Before this
round the same sheet at least failed with "missing columns: repository_code". Small FE change, day-one
path, so it is blocking.

---

## Manifest values (recomputed against CURRENT dev `562cb1331`)

| | dev `562cb1331` | branch `c0fc15b8d` | merge value |
|---|---|---|---|
| `gated_ceiling` | 1173 | **1174** | **1174** |
| `Expense.classes` | 22 | **23** | **23** |
| every other group | — | — | identical (diffed programmatically: Expense is the ONLY group that differs) |

`php tools/feature-lane-manifest-check.php` on the branch → **EXIT 0**:
> `lane manifest OK — 1423 Feature classes in 74 groups; … ⚠ PARKED …: 70 group(s) / **1174 class(es)**`

F-6 is closed. Because the lane has already merged dev `562cb1331`, there is nothing left to re-derive
**unless dev moves again before the merge** — see §Merge for the W4-3 interaction, which does change it.

---

## Verified by execution

| Claim | How | Result |
|---|---|---|
| lane tests, sqlite | `php artisan test` BY PATH — the 3 lane files | **28 passed / 1 skipped / 99 assertions** |
| lane tests, PostgreSQL 16 | throwaway `autoerp_test_w42g2` @ `127.0.0.1:5433`, `-c phpunit-pgsql.xml`, 4 files (incl. `UpcomingPaymentsTest`) | **33 passed / 131 assertions** — DB dropped after |
| **`treasury:reconcile` pinned in-test** | `OpeningCashFloatSeedsRepositoryTest.php:216-229` + `:448-450` — `assertSame(0, Artisan::call(…))` + `assertStringContainsString('froze 0', …)` after `CompanyContext::clear()` (rule 20) | present in BOTH the headline case and the merged-row case (`:436-451`); green on sqlite and PG. r1 §(c) closed |
| repository == GL after 200/1000/5000 | `:145-230` executed | drawer 200.000 / safe 1000.000 / bank 5000.000; 3 movements, each `source_type=opening_balance`, `source_id=batch`, `journal_entry_id=entry`; Σ tills on `53` == GL Dr `53` == 1200.000 |
| rollback atomicity re-probed | `test_post_refuses_when_a_row_naming_a_repository_failed_validation` (`:459-470`) and the coverage refusals throw INSIDE `postBatch`'s `DB::transaction` (`AccountingOpeningService.php:578`, refusals at `:613-641`) — all before `JournalEntry::create` at `:646`; all four refusal tests assert a clean state | atomic; nothing is written before the guards |
| deptrac | branch **and** dev `562cb1331`, both freshly run | **183 == 183** |
| PHPStan L8 | `./vendor/bin/phpstan analyse` on the branch | `[OK] No errors` |
| pint | `--test` | `{"result":"pass"}` |
| **lint ratchet** | `node scripts/lint-ratchet.mjs` on the branch AND on dev `562cb1331` | branch web **6451**, dev web **6451**, baseline 6449 → **FAIL on both, identical**. pos 84 == 84. Inherited red, lane adds zero |
| Accounting dir reds | whole `tests/Feature/Accounting` on the branch, then the named class on dev | branch **5 failed / 13 skipped / 875 passed**; all 5 are `InvoiceAndCreditNoteGLIntegrationTest` `DeliveryRequiredBeforeInvoiceException`; **the same 5 fail on dev** (5 failed / 3 passed). Byte-identical claim holds |
| Rule 19 | programmatic scan of every `+` line of `git diff 562cb1331 HEAD -- apps/api apps/web` (3403 lines) for `(float)` / `floatval` / `number_format` / `parseFloat` / `Number(` / no-arg `getScale()` / `toFixed` | **0 hits**. `AccountingOpeningService.php:83-89` uses `getScaleSafe($currency, 3)`; the coverage rule refuses non-numeric JSONB rather than casting (`:481-487`) |
| Rule 20 | no `onQueue`, no new job in the payload | n/a |
| repo hygiene | `git ls-files` for `deptrac.cache` / `node_modules`; `git status` | nothing tracked, tree clean. The `.gitignore` bare-`node_modules` line is correct and needed |

### The lint-ratchet "limitation" claim — corrected

The handback (§Gates, table row) says `scripts/lint-ratchet.mjs` "**could not execute in this worktree** —
`ERR_MODULE_NOT_FOUND: globals` through the symlinked `node_modules`". I reproduced that error and then
made it go away: the failure is **`apps/pos/node_modules` missing**, not the web symlink. Symlink all three
app `node_modules` and the script runs. It then confirms the hand-measured numbers **exactly** (6451/6451,
84/84). So the substance of the claim is right and the diagnosis was wrong — and, more usefully, the gate
that the handback declared unrunnable is runnable, and it is red on dev already.

### Tamper (red-proof) — temp `git worktree` at `c0fc15b8d`, never the lane

| Revert | Expected red | Measured |
|---|---|---|
| **F-4 + F-3** — `GeneralLedgerService.php:4752-4779` restored to dev's `match($repositoryType)` | the two new GL cases | `⨯ the credit lands on the tills own account…` and `⨯ a non cash expense credits the methods own account and never cash` — **2 failed / 7 passed** |
| **F-2** — `ExpenseService::post()` guard (`:388-402`) deleted | the post-time refusal, both files | **2 failed** — incl. `ExpensePostTest` "Expected response status code [422] but received 200" at `:168`. Confirms `ExpenseController::post()`'s catch is now LIVE, not dead |
| **F-7** — `openingCashCoverageGaps()` forced to `return []` | the coverage cases | **3 failed / 12 passed**, incl. the typed-422 API case |
| **F-1** — `missingColumns` filter back to `expectedColumns` | the legacy-CSV case | `× accepts a legacy four-column CSV that carries no repository_code` — **1 failed / 2 passed** (single-file vitest, forks pool, workers killed) |

Untampered, `FileUpload.test.tsx` is **3 passed**.

### New probes

| Probe | Measured |
|---|---|
| **R-9-REMEDY** — after the merged row over-seeds `CASH-01` to 1200, transfer 1000 drawer→safe | `drawer=200.000 safe=1000.000 jeBefore=1 jeAfter=1 linesBefore=2 linesAfter=2 reconcileExit=0 … froze 0`. **The mis-seed IS correctable in-product, and GL-neutrally** — same-GL-account transfers write no journal entry (`RepositoryTransferService.php:57,74`) |
| **OVERDRAFT** — `Cr 512 5000.000` with `BANK-01` active on `512` | `valid=true`, posts, `glCredit512=5000.000 bankRepoBalance=0.000 movements=0 reconcileExit=0` → see **G-3** |
| **PENDING** — a repository row still `PENDING` at post time | `RuntimeException: Cannot validate batch: 1 rows are still pending validation` (`OpeningBalanceBatchService.php:375`, reached from `AccountingOpeningService.php:740`) — the whole post rolls back. **No hole**; F-11's `Invalid`-only filter is sufficient because `Pending` cannot survive `postBatch` |

---

## RULING — R-9 (merged row silently over-seeds one till)

**Accept as shipped for tenant #1. Do NOT require one row per repository whose amount matches.**

Three reasons, each verified rather than argued:

1. **The stricter rule is unimplementable today.** `validateRow` rejects a zero-amount row
   (`AccountingOpeningService.php:246-250`, *"Either debit or credit must be non-zero"* at `:249`), so an operator
   has no way to declare "SAFE-01 opens empty". Requiring one row per repository would therefore
   hard-refuse a **correct** greenfield opening whose safe is genuinely empty — trading a data-entry risk
   for a guaranteed day-one block. The handback's justification is accurate; I checked it.
2. **The residual is money-neutral.** Σ tills on the account == GL Dr on the account by construction, so
   it is a mis-attribution *between* tills, not a ledger/treasury divergence. `treasury:reconcile` green,
   measured, in the shipped test at `OpeningCashFloatSeedsRepositoryTest.php:448-450`.
3. **Unlike R-6, it has an in-product remedy** — PROBE R-9-REMEDY above: one same-GL-account treasury
   transfer restores the intended split, writes no journal entry and no journal line, and leaves the
   reconciler green.

Conditions on the acceptance:
- (a) the `OPENING_CASH_NOT_FULLY_SEEDED` refusal ships — **it does**;
- (b) the residual is pinned as a *decision*, not an accident — **it is**,
  `test_a_merged_cash_row_over_seeds_one_till_and_is_deliberately_not_refused` (`OpeningCashFloatSeedsRepositoryTest.php:436-451`), with the
  instruction to rewrite it when a zero-float affordance exists;
- (c) the RUNBOOK must name the transfer remedy next to "One row per repository" — **G-9, not done**;
- (d) **LEDGER row**: ship an explicit zero-float declaration (a `0.000` row naming a repository, or a
  "this till opens empty" affordance); once it exists, the validator CAN and SHOULD require one row per
  repository on any debited cash account. Until then the coverage rule is the right amount of rule.

---

## Findings

### G-1 [IMPORTANT — MERGE-BLOCKING] The new refusal is invisible in the wizard, and the legacy sheet now dead-ends with no message at all

The coverage gaps are written to `errors['_batch']` and gate `valid`:
`apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php:157-166,177`
```php
$errors['_batch'] = array_merge($errors['_batch'] ?? [], $coverageGaps);
…
'valid' => $isBalanced && $invalidCount === 0 && $coverageGaps === [],
```
The API does put them on the wire — `OpeningBalanceBatchController.php:492-497` returns the whole
`$result`. The frontend then discards them:

- `apps/web/src/features/opening-balances/pages/OpeningBalanceWizardPage.tsx:112-117` types the state as
  `{ valid; total_rows; valid_rows; invalid_rows }` — **`errors` is dropped on assignment**;
- `:246-256` advances only when `result.valid`, and the stepper is strictly linear (no clickable step
  chips), so **Post is unreachable** and the good, translated post-time message never renders;
- `apps/web/src/features/opening-balances/components/ValidationResults.tsx:7-15` accepts only those four
  fields; `:53-105` renders per-ROW `validation_errors`; `:120-155` renders the summary. **Nothing renders
  `_batch`.**

Measured consequence on the exact path this lane exists to fix: a four-column legacy sheet now uploads
(the F-1 fix, correctly), every row validates `VALID`, `invalid_rows = 0`, `valid = false` → the operator
sees an amber `openingBalances.validation.hasErrors` banner reading *"N valid, 0 invalid, N total"*, no
expandable row, and a Validate button that never advances. Self-contradictory, and it names neither the
account nor `repository_code`. **This is strictly worse than the F-1 defect it replaced**, which at least
said "missing columns: repository_code".

Secondary, same finding: the gap strings are raw English assembled in
`AccountingOpeningService.php:537-540`, not `__()`-translated — unlike the POST-time twin at
`OpeningBalanceBatchController.php:647` (`messages.accounting.opening_cash_not_fully_seeded`, en+fr). So
even once rendered they would be untranslated.

**Fix:** widen `validationResult` and `ValidationResultsProps` with `errors?: Record<string, string[]>`,
render `errors._batch` inside the summary card, and translate the gap strings server-side (or return the
same typed code + structured gaps at validate time that `post` already returns). Pin it with a
`ValidationResults.test.tsx` case: `valid: false`, `invalid_rows: 0`, one `_batch` string → the string is
on screen.

### G-2 [IMPORTANT — record on the LEDGER] A CREDIT on a repository-backed account is the un-closed mirror of the P0, and the reconciler cannot see it

`AccountingOpeningService.php:488` skips every non-positive debit, so the coverage rule examines the
debit side only. `validateRepositoryColumn` refuses a repository on a credit line (`:313-319`), so there
is no way to express the treasury half either.

PROBE OVERDRAFT, measured: an opening sheet posting `Dr 119 5000.000 / Cr 512 5000.000` on a company with
an ACTIVE `BANK-01` linked to `512` **validates, posts, and yields `glCredit512=5000.000`,
`bankRepoBalance=0.000`, `movements=0`, `treasury:reconcile` exit 0**. GL bank is −5000 and the till reads
0, silently and permanently (the batch locks at post).

It escapes the reconciler because check 2 is **movement-driven** — `ReconcileTreasuryCommand.php:962-1000`
iterates movements and matches each against its JE; a journal line on a repository's `gl_account_id` with
**no** movement is never examined at all.

Not merge-blocking: the shipped provisioning seeds only `CASH-01` and `SAFE-01`, both on the **Cash**
purpose account and no bank repository at all (`database/seeders/PaymentRepositorySeeder.php:103,120,148-160`),
so no repository hangs off `512` on a day-one tenant. It becomes reachable the moment an operator creates
a bank repository. **Fix (or record):** refuse a credit on a repository-backed account with a message that
says an opening overdraft has no treasury representation, rather than posting it silently.

### G-3 [MINOR] F-11's own refusal reproduces the exact defect F-5 just fixed

`AccountingOpeningService.php:622-626` throws a plain `RuntimeException`, and
`OpeningBalanceBatchController.php:652-660` maps every `RuntimeException` to `'code' => 'POST_FAILED'` plus
`$e->getMessage()` — untyped, raw server English, no i18n key. One commit after F-5 gave
`REPOSITORY_ALREADY_SEEDED` a typed code and en/fr strings (`:635-641`), the new sibling refusal ships
with neither. **Fix:** give it a typed exception + `messages.accounting.*` key, the way its two neighbours
in the same `try` block now have.

### G-4 [MINOR] `apps/api/lang/ar/messages.php` does not exist

`ls apps/api/lang/ar/` → `documents.php`, `treasury.php` only. Both new keys
(`messages.treasury.repository_already_seeded`, `messages.accounting.opening_cash_not_fully_seeded`) fall
back to English for an Arabic tenant. **Pre-existing** — the sibling `repository_not_seeded` shipped with
the same gap — so not a lane regression, but the lane's own FE strings did get ar (`common.json`
`optionalColumn`), which makes the asymmetry visible inside one change.

### G-5 [MINOR] `error.gaps` is a non-standard key in the error envelope

`OpeningBalanceBatchController.php:645-650` adds `'gaps' => $e->gaps` alongside `code`/`message`. Every
other 422 in this controller carries only `code` and `message`, and the OpenAPI contract lane pins the
error envelope. **Fix:** move it under `error.errors`, or register the shape.

### G-6 [MINOR] Unscoped + N+1 account lookup inside the coverage loop

`AccountingOpeningService.php:528` — `Account::query()->whereKey($accountId)->value('code')` runs once per
offending account and carries no tenant/company predicate. Not exploitable (the id comes from the batch's
own rows, which `validateRow` resolved via `Account::forCompany`), and it feeds a message only. **Fix:**
hoist to one `whereIn` and scope it.

### G-7 [MINOR] `describeByGlAccounts` pays for a `hasMovements` probe the caller never reads

`RepositoryOpeningBalanceService.php:88` calls `hasForeignMovement($repository->id, null)` for every
repository, but `openingCashCoverageGaps` reads only `->glAccountId` and `->code` (`:516-539`). One wasted
query per cash repository on **every** validate and **every** post. **Fix:** make the probe lazy, or add a
descriptor variant without it.

### G-8 [MINOR] The RUNBOOK does not name the remedy for the residual it now documents

`docs/handoff/RUNBOOK-opening-cash-float-2026-08-23.md` step 1 says "One row per repository" and step 4 is
now honest about R-6 ("no in-product remedy … greenfield — re-provision rather than backfill", with the
owner disposition recorded — r1's residual **closed**). But nothing tells an operator what to do about the
merged row that W4-2 deliberately accepts. PROBE R-9-REMEDY shows the answer is one line: *"If a cash line
named the wrong till, correct it with a Treasury transfer between the two tills — they share the same GL
account, so no journal entry is written and the ledger is unaffected."* **Fix:** add it (condition (c) of
the R-9 ruling).

---

## r1 findings — disposition

| | Status | Evidence |
|---|---|---|
| F-1 required-header regression | **CLOSED** | `GL_REQUIRED_COLUMNS` split (`types/index.ts:232-240`), `getRequiredColumns` (`FileUpload.tsx:61-71`), `(optional)` chip in en/fr/ar; new `FileUpload.test.tsx` 3/3, **red-proved** |
| F-2 `post()` unguarded | **CLOSED** | `ExpenseService.php:388-402`; **red-proved**; the controller catch is live (422 measured) |
| F-3 non-cash carve-out credits `53` | **CLOSED, correctly** | `GeneralLedgerService.php:4655-4691` + `:4760-4778` — resolves `payment_methods.default_account_id` (tenant+company scoped), falls back to **Bank**, never Cash; **red-proved**. Safe on the provisioned shape: no seeder sets `default_account_id`, and no repository hangs off the Bank purpose account |
| F-4 GL change untested | **CLOSED** | `ExpensePaidFromRepositoryTest.php:181-233` uses a distinct `5311`; **red-proved** |
| F-5 dead `ERROR_CODE` | **CLOSED** | typed 422 + `messages.treasury.repository_already_seeded` (en/fr) + endpoint test `OpeningCashFloatSeedsRepositoryTest.php:478-495` |
| F-6 manifest 1170 | **CLOSED** | 1174, checker EXIT 0 against current dev |
| F-7 merged-row story | **CLOSED + ruled** | refusal shipped; the residual is a named decision test; see §RULING |
| F-8 unscoped `PaymentMethod` | **CLOSED** | `ExpenseService.php:311-318` |
| F-9 3-arg `update()` | **CLOSED** | `ExpensePaidFromRepositoryTest.php:410` |
| F-10 orphan i18n key | **CLOSED** | `BatchPreview.tsx:114-118`, gated on `hasRepositoryLine` |
| F-11 posts on STATUS alone | **CLOSED (narrowed)** | `AccountingOpeningService.php:602-626` — repository-naming `Invalid` rows refuse; `Pending` cannot reach post (probed). The generic case stays pre-existing, correctly scoped out |
| F-12 unverified `batchId` | **CLOSED** | `RepositoryOpeningBalanceService.php:104-120` asserts the batch exists for tenant+company via `DB::table` (rule 6 respected); both fixtures now post a real batch |
| R-6 runbook remedy | **CLOSED** | RUNBOOK §4 rewritten, owner disposition recorded |
| R-7 `TREASURY_SHIFT_VARIANCE_GL_ENABLED` | unchanged, correctly | not in the diff |

---

## Merge

1. **G-1** — render `errors._batch` in the wizard and translate the gap strings. (blocking)
2. G-2 / G-8 → LEDGER + RUNBOOK line. G-3..G-7 → record.
3. **Collision with `w43-openings` — W4-2 merges FIRST.** Three files collide; W4-3 must re-read and
   re-derive, not let git auto-merge:
   - **`apps/api/app/Modules/Accounting/Application/Services/AccountingOpeningService.php`** — *hard*
     conflicts. W4-2 changed the constructor to three params (`batchService, scaleResolver,
     repositoryOpeningSeeder`, `:48-52`); W4-3 inserts `PartnerControlAccountResolver` as param 2 against
     the two-param base → resolve to **four**. W4-2 also changed `validateRow`'s signature to
     `(row, tenantId, companyId, scale)` (`:201-206`); W4-3's control-account `elseif` is patched against
     the old `(row, companyId, scale)` → the hunk **will not apply**; re-insert it into W4-2's version.
     W4-3's per-row re-assert in `postBatch` sits inside the row loop, after `JournalEntry::create`;
     W4-2's two new guards sit before it (`:602-640`) — semantically compatible, but W4-3 should consider
     hoisting for consistency.
   - **`apps/web/src/features/opening-balances/components/FileUpload.tsx`** — W4-2 rewrote the block
     immediately above `getTemplateContent` (`:38-71`) and rewrote the ACCOUNTING template itself to a
     **five-column** sample with `CASH-01`/`SAFE-01` rows (`:72-77`). W4-3 rewrites the same template arm
     to drop the `401000` payables line. Resolution must keep **both**: five columns *and* no control
     account. Note W4-2's current sample still contains `401000,0.000,500.000,Opening Payables,` — which
     W4-3's new rule will REFUSE, so this is a real semantic collision, not just a textual one.
   - **`apps/api/tests/feature-lane-manifest.json`** — both lanes set `gated_ceiling` 1173 → **1174** off
     the same dev base (W4-2 via Expense 22→23, W4-3 via Document 84→85). After W4-2 lands, **W4-3's union
     is 1175**, not 1174, with Expense 23 taken from dev verbatim. W4-3's note prose says 1174 and must be
     corrected in the same resolution.
   - Whichever lands second re-runs `OpeningCashFloatSeedsRepositoryTest`,
     `OpeningBalancePreviewContractTest` and `ArApOpeningLedgerTest`.
4. **S-14 dispatch-verification leg applies** (`ci.yml` touched — two names appended to the pgsql
   `--filter`, allowlist justification correct and precedented). **S-17 stands**: CI unobserved on this
   branch. Note the lint ratchet is **red on dev already** (6451 vs baseline 6449) — inherited, not this
   lane's, and deliberately not re-baselined here.

*Throwaway PG DB `autoerp_test_w42g2` dropped; the tamper worktree removed; the lane worktree is clean and
untouched (`git status --porcelain` empty).*

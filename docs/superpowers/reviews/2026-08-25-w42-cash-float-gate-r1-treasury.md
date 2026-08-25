# GATE r1 — treasury/GL lens — W4-2 + W4-10

**Lane** `fix/campaign-w42-opening-cash-float` · worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/w42-cash-float` · HEAD `e07aadb63`
**Reviewed** 2026-08-25 · **Migration** none (confirmed: no file under `apps/api/database/migrations/` in `git diff dev...HEAD`)
**Collision** `w43-openings` (in fix round) also edits `AccountingOpeningService.php` — see F-6/§Merge.

## VERDICT

**spec ✅ (core) / quality CHANGES-REQUESTED** — **merge-blocking: YES**, on F-1 and F-6 only.

The P0 is genuinely closed and I verified it by execution, not by reading the handback: the ACCOUNTING
opening batch now posts the GL leg and the repository opening movement in one transaction, the balances
match the ledger exactly, the refusals fire, the replay is a no-op, and the whole thing rolls back
atomically. `treasury:reconcile` — which the handback said it could not run — I ran, and it is green.

What blocks merge is small and mechanical: a frontend one-liner that makes the "optional" column
mandatory and bricks every pre-existing ACCOUNTING CSV upload (F-1), and a stale manifest ceiling that
will conflict on merge and must be resolved to 1174, not 1170 (F-6). F-2/F-3/F-4/F-5 are real money-lane
gaps I would want fixed in the same round but would accept as recorded LEDGER rows.

---

## Verified by execution (not taken on trust)

| Claim | How I checked | Result |
|---|---|---|
| Lane tests green, sqlite | `php artisan test` BY PATH, both lane files | **14 passed / 56 assertions** |
| Lane tests green, PostgreSQL 16 | throwaway `autoerp_test_w42g` @ `127.0.0.1:5433`, `-c phpunit-pgsql.xml`, 4 files | **19 passed / 89 assertions** (DB dropped after) |
| drawer 200 / safe 1000 / bank 5000 | `OpeningCashFloatSeedsRepositoryTest:143-212` executed | Σ treasury on `53` = **1200.000** == GL Dr `53`; `512` = **5000.000** both sides |
| 5 typed refusals + already-seeded | tests `:249-335` executed | all fire; post-time guard authoritative |
| idempotent on (batch, repository) | `:218-247` executed | 1 movement, balance unchanged |
| **Rollback of either half** (PROBE A) | temp worktree; batch 2 writes an ordinary `JournalLine` for row 1, then row 2 throws `RepositoryAlreadySeededException` | **journal_entries, journal_lines, repository_movements all back to baseline; batch stays Draft; drawer untouched at 200.000** |
| **`treasury:reconcile`** (PROBE E) | `Artisan::call('treasury:reconcile', ['--tenant' => …])` after `CompanyContext::clear()` (rule 20) | `exit=0` — *"checked 3 repository(ies); froze 0 on cash drift; 0 portfolio drift(s); 0 statement alert(s); 0 error(s)"* |
| deptrac | ran on branch **and** on the CURRENT dev tip `300ee4d2ec1` | **183 == 183** |
| PHPStan L8 | `./vendor/bin/phpstan analyse` on the branch | `[OK] No errors` |
| Rule 19 | grepped every `+` line of the diff for `(float)`/`number_format`/`parseFloat`/`Number(`/no-arg `getScale()` | **zero hits**; `AccountingOpeningService.php:82-88` uses `getScaleSafe($currency, 3)`; the port owns all bcmath |
| Rule 20 | no `onQueue`, no new job, GL posts `SynchronousInTransaction` | n/a — nothing queued added |
| No hardcoded account codes | `GeneralLedgerService.php:4468-4474` still resolves via `SystemAccountPurpose`; the validator reads the expected code off `Account` for the message only (`AccountingOpeningService.php:284`) | clean |

---

## The three design calls

**(a) Linking the opening movement to the batch's JE — SOUND, and now proven.**
`ReconcileTreasuryCommand::journalEntryAmountMatches()` (`…/ReconcileTreasuryCommand.php:961-1000`) treats
lines on the repository's own `gl_account_id` as authoritative, matching **per line first, then by sum**.
That is exactly why the drawer+safe sharing account `53` does not false-freeze: each movement matches its
own line. PROBE E confirms it end to end — 3 repositories checked, **0 frozen**. Keep the call.

*But the justification the handback gives for it is not the reachable failure.* §2.1 claims a merged row
"would fail check 2's per-line/sum match and **freeze the drawer in production**". I measured the merged
shape (PROBE B): a single `Dr 53 1200.000` row naming only `CASH-01` **validates `valid=true`, posts, and
yields drawer=1200.000 / safe=0.000 / 1 movement — and `treasury:reconcile` stays green**, because the one
line equals the one movement. So the validator neither refuses the aggregate row nor freezes anything: it
**silently over-seeds one till and leaves the other at zero**. The only defence is prose — RUNBOOK line
"One row per repository". See F-7.

**(b) No `opening_float` `MovementReasonCode` — AGREE.**
`MovementReasonCode` is exactly and only the 4-case variance vocabulary
(`count_variance|correction|theft_loss|other`, `…/Enums/MovementReasonCode.php:9-12`) that the "Adjust
balance" dialog renders. `MovementSourceType::OpeningBalance` (`…/Enums/MovementSourceType.php:16`)
already carries the meaning, and adding a case would also collide with rule 8/9 discipline. Correct call.

**(c) `treasury:reconcile` "never run" — it CAN be run, and I ran it.**
The handback (§5.4) says the reconciler needs a provisioned per-tenant DB and so was replaced by a pinned
predicate. That is not so: `--tenant` is the established in-test pattern —
`tests/Feature/Treasury/ReconcileTreasuryTest.php:243`, `tests/Feature/Accounting/ExpenseVatPostingTest.php:430`,
`tests/Feature/Treasury/InstrumentClearTest.php:93` all do it. PROBE E: exit 0, 0 frozen, after the
3-repository opening batch. The mirrored predicate at `OpeningCashFloatSeedsRepositoryTest.php:180-194`
is good and should stay, but **the lane could and should have pinned the real command**; a one-line
`assertSame(0, Artisan::call('treasury:reconcile', ['--tenant' => $this->tenant->id]))` at the end of the
headline test would make the freeze invariant a regression pin rather than a re-implementation of it.

---

## Findings

### F-1 [IMPORTANT — MERGE-BLOCKING] The "optional" `repository_code` column is now a REQUIRED CSV header, and blocks every pre-existing ACCOUNTING upload

`apps/web/src/features/opening-balances/types/index.ts:231`
```ts
export const GL_COLUMNS = ['account_code', 'debit', 'credit', 'reference', 'repository_code'] as const
```
`GL_COLUMNS` is not a display list. `FileUpload.tsx:41` returns it from `getExpectedColumns()`, and
`FileUpload.tsx:101-113` uses it as the **required-header** set:
```ts
const missingColumns = expectedColumns.filter((col) => !parsed.headers.includes(col))
if (missingColumns.length > 0) { setParseError(t('…errors.missingColumns', …)) }
```
`handleUpload` early-returns on `parseError` (`FileUpload.tsx:152-155`) and the Upload button is rendered
only when `parsedData && !parseError` (`FileUpload.tsx:289`). So an operator's existing sheet with headers
`account_code,debit,credit,reference` — the shape the RUNBOOK and this very repo documented until
yesterday — now shows "missing columns: repository_code" and **cannot be uploaded at all**.

Why it matters: this is the day-one wizard the whole P0 exists to make work, and the lane's own docs
(`RepositoryOpeningBalanceSeederInterface.php:39`, `FileUpload.tsx:55-58`, RUNBOOK §1, the exception
message) all call the column *optional*. There is no `FileUpload.test.tsx` in
`apps/web/src/features/opening-balances/components/`, so nothing caught it.

**Fix:** split the constant — keep `GL_COLUMNS` (with `repository_code`) for the chip list + template, and
add `export const GL_REQUIRED_COLUMNS = ['account_code','debit','credit','reference'] as const`; use that
for the `missingColumns` computation. Add a `FileUpload.test.tsx` case that a 4-column ACCOUNTING CSV
parses without `parseError`.

### F-2 [IMPORTANT] The W4-10 guard is create/update-only — `post()` still produces the exact defect

`ExpenseService.php:105-109` (create) and `:218-222` (update) call `assertPaidExpenseNamesRepository`.
`ExpenseService::post()` (`:369-483`) does **not**. The campaign evidence describes the defect *at
posting* ("Posting settles it to the default cash account … with no `repository_movements` row").

PROBE C, on sqlite **and** on PostgreSQL — a Draft expense whose metadata carries
`is_paid = true, payment_repository_id = NULL` (a legacy row, or any writer that is not
create/update):
```
PROBE C posted=posted cashCredit=45.000 movements=0
```
i.e. `Cr 53 45.000` with zero treasury movements. Verbatim W4-10.

Consequence in the controller: `ExpenseController.php:240-243` adds
`catch (ExpensePaidWithoutRepositoryException …)` to `post()` — **dead code**, because `post()` cannot
throw it. That catch reads as if a post-time guard exists.

**Fix:** at the top of `post()`, after `$metadata` is loaded, call
`assertPaidExpenseNamesRepository($metadata->is_paid === true, $metadata->payment_repository_id, $metadata->payment_method_id)`.
The controller catch then becomes live. Pin it with a test built the way PROBE C is.

### F-3 [IMPORTANT] The sanctioned non-cash carve-out reproduces the very shape W4-10 refuses — and the new error message steers operators into it

PROBE F (expense born paid, `is_cash_tender = false` method, no repository, then posted):
```
PROBE F credit53=45 credit512=0 movements=0
```
`GeneralLedgerService.php:4467-4474`: with no repository, `$repository = null` ⇒ no
`repositoryGlAccount` ⇒ `match(RepositoryType::CashRegister) → SystemAccountPurpose::Cash`. So a
**card-paid** expense credits `53 Caisse`, and writes no movement. GL cash falls 45.000; no till moves.

That is the W4-10 damage model with a different door — and it is the door the lane advertises. The new
exception message and all three i18n strings say *"choose a non-cash payment method"* as an equal remedy
(`ExpensePaidWithoutRepositoryException.php:40-43`; `apps/web/src/locales/{en,fr,ar}/expenses.json`
`errors.paidWithoutRepository`). It also silently breaks the `Σ till balances == GL 53` equality W4-2 has
just established, and `treasury:reconcile` cannot see it: there is no movement to check.

The handback records this as residual R-4 "worth an owner ruling before a second tenant" — but the
message that pushes operators down the path ships **now**.

**Fix (minimal, in-lane):** remove "choose a non-cash payment method" from the exception message and from
the en/fr/ar strings, leaving "choose a repository, or leave it unpaid and settle it later". **Fix
(correct):** in the non-cash branch of `createFromExpense`, resolve `payment_methods.default_repository_id`
(the column exists — `PaymentMethod.php:41`) or credit `SystemAccountPurpose::Bank` instead of Cash; that
needs an owner ruling, so record it as a LEDGER row and ship the message fix.

### F-4 [IMPORTANT] The W4-10 GL change is untested — the shipped test cannot go red if it is reverted

`ExpensePaidFromRepositoryTest.php:156-160` asserts the credit on `$this->drawer->gl_account_id`, but the
fixture sets `gl_account_id` to the `Cash` **purpose** account (`:113-123`, `$this->cashAccount` is the
`SystemAccountPurpose::Cash` account created at `:106`). Both the new code and dev's pre-change code
resolve to that same account, so the assertion passes either way. The handback itself concedes it is "a
no-op there".

I verified the new behaviour is *correct* — PROBE D, a till linked to a distinct `5311 Caisse boutique`:
```
PROBE D creditOnOwn5311=5.000 creditOnPurpose53=0 balance=495.000
```
— but the lane ships no pin for it. `GeneralLedgerService.php:4448-4466` could be reverted tomorrow and
CI would stay green.

**Fix:** add a case with a repository whose `gl_account_id` is a distinct account and assert the credit
lands there **and** that the Cash purpose account carries nothing.

### F-5 [IMPORTANT] `REPOSITORY_ALREADY_SEEDED` is a dead constant; the operator gets untranslated English

PROBE G (real HTTP `POST /api/v1/companies/{c}/opening-batches/{b}/post` on an already-seeded till):
```
status=422 body={"error":{"code":"BUSINESS_ERROR","message":"Cannot post an opening float: payment repository 'Caisse' (CASH-01) already holds money…"}}
```
`RepositoryAlreadySeededException` extends `DomainException` (`:31`), which the batch controller does not
catch (`OpeningBalanceBatchController.php:626` catches `RuntimeException` only) — a global handler maps it
to `BUSINESS_ERROR`. `ERROR_CODE` (`:39`) has **zero** consumers anywhere in `apps/api` or `apps/web`
(grep). The docblock at `:36-38` claims it exists "so the wizard can name the offending row rather than
re-render a generic 422" — the generic 422 is exactly what ships.

Rule 11: the W4-10 refusal got `expenses:errors.paidWithoutRepository` in en/fr/ar; this one reaches the
operator as raw server English. Inconsistent within the same lane.

**Fix:** either map it in `OpeningBalanceBatchController::post()` the way
`ExpenseController::paidWithoutRepositoryError()` does and add an i18n key, or delete the constant and the
docblock claim.

### F-6 [IMPORTANT — MERGE-BLOCKING] Manifest arithmetic is stale; merge value is **1174**, not 1170

Branch `apps/api/tests/feature-lane-manifest.json` → `gated_ceiling: 1170`, Expense group `classes: 23`.
Current dev tip `300ee4d2ec1` → `gated_ceiling: **1173**`. Measured group delta vs the branch:
```
Document:  dev=84  branch=83
Expense:   dev=22  branch=23
Inventory: dev=114 branch=111
```
Document (+1) and Inventory (+3) landed on dev **after** this lane's base `8b0b822c7` (which was at 1169).
The lane adds exactly +1. **Merge value = 1174.** The `gated_ceiling` line will conflict (branch
1169→1170 vs dev 1169→1173); Document/Inventory lines are dev-only and merge cleanly. The Expense note's
embedded prose "gated_ceiling 1169 -> 1170" must be corrected in the same resolution.

`.github/workflows/ci.yml`: dev's `--filter` line still ends `…|PurchaseOrderUnpricedLineConfirmTest)::/`,
identical to this lane's base, so the two appended names merge cleanly. The allowlist justification
comment follows the established CorrectingEntryEndpointTest / SupplierGoodsReturnNoteTest precedent and is
correct: `Feature/Expense` is parked, so an unnamed guard would execute on no event. **S-14 dispatch-
verification leg applies** (ci.yml touched); **S-17 stands** — I did not observe CI.

### F-7 [MINOR] The merged-row story in the handback does not match measured behaviour

See §Design call (a). PROBE B: `Dr 53 1200.000 / CASH-01` as a single row validates, posts, gives
drawer=1200.000 / safe=0.000, and reconciles green. The handback §2.1's "would … freeze the drawer in
production" is not reachable through the shipped path; the real risk is a **silent over-seed**. The
RUNBOOK's "One row per repository" (`docs/handoff/RUNBOOK-opening-cash-float-2026-08-23.md`, step 1
addendum) is the only guard. **Fix:** correct the handback claim so the next reviewer is not chasing a
freeze that cannot happen; optionally warn in the preview when one GL account carries several
repository-bearing rows.

### F-8 [MINOR] `PaymentMethod` lookup in the W4-10 guard is not tenant/company scoped

`ExpenseService.php:305-311`:
```php
$isCashTender = PaymentMethod::query()->whereKey($paymentMethodId)->value('is_cash_tender');
```
No `tenant_id` / `company_id` predicate. Today the only reachable caller is the HTTP layer, which does
scope it (`ExpenseRequest.php:63-66`, `ScopedExists::tenantAndCompany('payment_methods', …)`), so this is
not exploitable now — but the guard is a domain invariant and a non-HTTP caller could bypass it with
another company's non-cash method. (Cross-*tenant* is impossible: db-per-tenant.)
**Fix:** add `->where('tenant_id', …)->where('company_id', …)`.

Related, same file: adding `use App\Modules\Treasury\Domain\PaymentMethod;` extends the pre-existing rule-6
breach in `ExpenseService` (it already imports `PaymentRepository`, `RepositoryMovement`, `MovementIntent`).
deptrac does not cover it and the count is unchanged at 183, so this is a note, not a regression.

### F-9 [MINOR] Test calls `ExpenseService::update()` with three arguments; the third is discarded

`ExpensePaidFromRepositoryTest.php:231`:
```php
$this->service()->update($expense, ['is_paid' => true], $this->user);
```
`ExpenseService::update(Document $expense, array $data): Document` (`ExpenseService.php:184`) takes two.
PHP silently drops the extra positional argument, and PHPStan cannot see it because `phpstan.neon:6-7`
analyses `app/` only. The test still proves what it claims (the guard fires off `$data`), but the call
reads as if `update()` takes a user. **Fix:** drop the third argument.

### F-10 [MINOR] `openingBalances.preview.repositoryHint` is an orphan key

Added to `apps/web/src/locales/{en,fr,ar}/common.json` and referenced nowhere in `apps/web/src` (grep).
**Fix:** use it (as the `repository_code` column hint in the preview / upload panel) or drop all three.

### F-11 [MINOR] A batch still posts on STATUS alone, so a refused till row degrades silently

`OpeningBalanceBatchController::post()` (`:558-593`) has no invalid-row gate; `canPost()` is
`status->canPost()` only (`OpeningBalanceBatch.php:168-171`); `postBatch` skips non-`Valid` rows
(`AccountingOpeningService.php:439-446`) and the imbalance is absorbed by the OBE offset (`:497-514`).
Pre-existing — but with `repository_code` it now means a till row rejected for (say) a GL-account mismatch
can post the rest of the batch with a `"Batch posted successfully"` message and the float missing, while
`119` quietly absorbs it. The FE gates on validation, the API does not.
**Fix (or record):** refuse `post` when `invalid_rows > 0`.

### F-12 [MINOR] The port does not verify `batchId` names a real batch — the "justifying document" is convention only

`RepositoryOpeningBalanceService::seed()` (`:75-137`) never checks that `$intent->batchId` resolves to an
`opening_balance_batches` row, though the contract asserts "The batch IS the justifying document
(document-per-action)" (`RepositoryOpeningBalanceSeederInterface.php:31-36`,
`OpeningFloatIntent.php:24-26`). Both new fixtures rely on that hole —
`UpcomingPaymentsTest.php:134-146` and `ExpensePaidFromRepositoryTest.php:246-257` pass
`(string) Str::uuid()` — normalising a float with no document behind it.
**Fix:** assert the batch exists (and belongs to tenant+company) in `seed()`, and have the fixtures post a
real batch; or stop claiming the invariant in the docblock.

---

## Residuals — the LEDGER answer

**R-6 (no backfill for GL-only-seeded tenants).** For a truly greenfield tenant #1, correct — nothing to
backfill, and the wizard is the first thing that touches the tills. **But the remedy the RUNBOOK now
prints is money-wrong for the tenants that are actually in R-6.** The updated §4 says:

> Move the cash in with a **Treasury transfer** from a till that does hold it, or — if no batch has been
> posted for that repository yet — post a new opening batch naming it.

A GL-only-seeded tenant (the campaign rehearsal tenant is exactly one: `Dr 53 1200.000` posted, both
repositories at `0.000`) has *no* till holding cash, so the transfer arm is unusable; and the second arm,
read as "no batch has *named* that repository", tells the operator to post a second opening batch — which
debits `53` **again**, doubling GL cash against unchanged physical cash. The honest statement is that
there is **no in-product remedy** for an already-Locked GL-only batch, because the only writer of an
`opening_balance` movement is `postBatch`, and it always posts a GL leg with it.
**Ask the owner:** ship a repair command, or reword §4 to "no in-product remedy — contact support".
Either way this must land on the LEDGER before the runbook is handed to an operator.

**R-7 (`TREASURY_SHIFT_VARIANCE_GL_ENABLED` stays false).** Confirmed unchanged
(`apps/api/config/treasury.php:28` not in the diff). Still open for the pre-enable gate, per the lane's own
statement: the deposit/payout half of SV-3 and SV-4 (`recordOpening` fires no event). W4-2 closes only the
float half. Nothing in this lane moves the flag, and nothing should.

Also worth carrying to the LEDGER, unchanged from the handback and independently confirmed while reading:
**R-1** (drawer and safe share GL `53` via `PaymentRepositorySeeder.php:120` — W4-2 handles it correctly,
per-line matching proven; the provisioning choice is the owner's) and **R-5** (a repository with
`gl_account_id = NULL` is accepted and seeded against whatever the row debits, because the account-match
refusal is guarded by `$descriptor->glAccountId !== null`, `AccountingOpeningService.php:281`).

---

## Merge checklist

1. **F-1** — split `GL_COLUMNS` so `repository_code` is not a required header. (blocking)
2. **F-6** — resolve `gated_ceiling` to **1174**; take Document 84 / Inventory 114 from dev; fix the note prose. (blocking)
3. F-2 — guard `ExpenseService::post()`; the controller catch is currently dead.
4. F-3 — drop "choose a non-cash payment method" from the message + en/fr/ar until the R-4 ruling lands.
5. F-4 — pin the per-till GL credit with a repository whose `gl_account_id` ≠ the Cash purpose account.
6. F-5 — surface `REPOSITORY_ALREADY_SEEDED` as a typed code with i18n, or delete the claim.
7. Re-pin `treasury:reconcile` inside the headline test (one `assertSame(0, Artisan::call(…))`).
8. Coordinate with `w43-openings` — both lanes edit `AccountingOpeningService.php`; W4-3 touches the AR/AP
   arm and this lane touches `validateRow`/`postBatch`, so the merge should be textually clean, but
   whichever lands second re-runs `OpeningCashFloatSeedsRepositoryTest` + `OpeningBalancePreviewContractTest`.
9. S-14 dispatch-verification leg (ci.yml touched) · S-17: CI unobserved on this branch.

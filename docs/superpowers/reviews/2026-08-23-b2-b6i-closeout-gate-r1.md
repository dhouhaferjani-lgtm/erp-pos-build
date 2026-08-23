# Adversarial merge gate — round 1 — `fix/b2-b6i-money-semantics-closeout`

**Reviewer:** treasury-reviewer (adversarial, code-grounded)
**Date:** 2026-08-23
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/b2-b6i-closeout`
**Commits:** `7a054db82` (runbook) · `53cd7fc12` (B-6(i) VAT pin) · `273c6a14a` (B-2 guard)
**Base:** `2a18fe5d7` (confirmed via `git merge-base`; `fa807a699` is an ancestor — same trunk as R-8)
**Authority:** `docs/handoff/RESEARCH-opening-float-and-vat-doc-count-2026-08-23.md` §Recommendations #1 (`:632`), #2 (`:633`), and the B-6(i) pin

---

## 0. Harness integrity (verified before trusting any run)

`apps/api/vendor` is a real directory, not a symlink to the main checkout. `ReflectionClass::getFileName()` resolves all three changed production classes into the **worktree**:

```
RepositoryAdjustmentService   => .worktrees/b2-b6i-closeout/apps/api/app/Modules/Treasury/Application/Services/RepositoryAdjustmentService.php
RepositoryNotSeededException  => .worktrees/b2-b6i-closeout/apps/api/app/Modules/Treasury/Domain/Exceptions/RepositoryNotSeededException.php
EloquentVatDataRepository     => .worktrees/b2-b6i-closeout/apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php
```

Every result below is therefore worktree code. Worktree was `git status --porcelain` clean at start and at end (all scratch mutations reverted via `git checkout`). Scratch PG database `autoerp_b2gate_r1_test` created and dropped.

---

## 1. Guard layer + predicate

### 1.1 "Movements is load-bearing" — re-derived, holds

`TreasuryReceiptBridge.php:1484` calls `$this->movementService->record(new MovementIntent(...))`, and the cited `:1498` lands exactly on `sourceType: MovementSourceType::FiscalEvent,` — the precise line that makes the claim true. The call is guarded only by `$shouldRecordMovement` (`:1480`), not by sale-vs-refund: `:1487` sets `direction: $isRefund ? MovementDirection::Out : MovementDirection::In`. So a till that has processed **any** cash tender leg carries a `fiscal_event` movement. "Zero movements" is a sound proxy for "never traded". **Citation accurate and well-chosen.**

Empirically confirmed by mutation M2 below: forcing the predicate to balance-only turns the traded-till test red.

### 1.2 (a) Till seeded only via a future `opening_float` movement — guard correctly ALLOWS

`MovementSourceType::OpeningBalance = 'opening_balance'` already exists (`MovementSourceType.php:15`). `isNeverSeeded()` (`RepositoryAdjustmentService.php:330-334`) tests `RepositoryMovement::query()->where('payment_repository_id', ...)->exists()` with **no `source_type` filter**, so any `opening_balance` movement satisfies conjunct 1 and the guard returns `false` → adjustment allowed. Forward-compatible with Recommendation 3. **No finding.**

### 1.3 (b) Manual adjustment on a genuinely-virgin till that received outside cash — refusal is correct, and a legal path exists

Refusing is correct per the research's intent (that cash is an accounting opening, not a variance). Critically, the operator is **not** trapped:

- `POST /payment-repositories/transfers` exists (`Treasury/Presentation/routes.php:102`, `can:treasury.transfer`) and `TreasuryMovementService.php:359` / `:379` write `MovementSourceType::Transfer` legs on **both** repositories. A transfer into the till therefore lays down a movement and permanently unlocks the guard.
- The runbook's own remediation (manual JE) is actionable: `POST /journal-entries` + `/journal-entries/{id}/post` exist (`Accounting/Presentation/routes.php:52`, `:56`).

The service docblock's claim that an OUT adjustment "is refused by the insufficient-balance rule anyway" is directionally right but strictly the guard now fires *first* (`:146`, before the transaction). Immaterial — both are 422 refusals with no writes. **No finding.**

### 1.4 (c) Balance conjunct cannot flip the outcome in production — verified honest

`2026_07_08_160000_forbid_direct_payment_repository_balance_writes.php` was read in full. Both branches are real:
- INSERT branch rejects `NEW.balance IS DISTINCT FROM 0` unless the GUC is `'on'`.
- UPDATE branch rejects `NEW.balance IS DISTINCT FROM OLD.balance` unless the GUC is `'on'`.

The GUC is set in exactly two places tenant-wide (`grep 'treasury_movement_port'`): `TreasuryMovementService.php:119` / `:319` (the port) and `PaymentRepositoryFactory.php:84` (test/seed only, reset at `:87`). Birth-at-zero is triple-confirmed: `PaymentRepositoryController.php:114` passes `'balance' => '0.00'`; `PaymentRepository.php:75-76` defaults `balance => 0`; `balance` is absent from `$fillable` (`:175`+). So on pgsql, `balance != 0` ⇒ movements exist ⇒ conjunct 1 already returned `false`. **The comment's claim is accurate.**

The test-factory bracket exemption is **honest** — `PaymentRepositoryFactory.php:55-70` documents it, confines it to a single INSERT, gates it on `pgsql && transactionLevel() > 0`, and resets in `finally`.

### 1.5 Rule 19 / rule 13 compliance

`isNeverSeeded()` uses `bccomp($repository->balance, '0', $scale)` — no float anywhere. `$scale` comes from the constructor-injected resolver **with the entity currency** (`getScale($repository->currency)`, `:139`) — correct for the queue/listener context, no bare no-arg `getScale()`. Guard is placed after scale resolution and before every write (`:146-153`). No `app()` in production code. Module boundaries respected (all imports same-module or `Shared/Contracts`). PHPStan level 8: **`[OK] No errors`** on all four changed production files. Pint `--test`: **`{"result":"pass"}`**.

---

## 2. Carve-out correctness

- **Exactly two callers of `post()`**, confirmed by grep over `apps/api/app` + `routes`: `PostShiftCashVarianceAdjustment.php:337` and `RepositoryAdjustmentController.php:58`. No others.
- The listener **always** sets it: `PostShiftCashVarianceAdjustment.php:359` is a literal `posShiftId: $event->shiftId,` at the single call site — not conditional, not defaulted. `adjustmentId` at `:358` is `$this->documentIdFor($event->shiftId)`, which is `Uuid::uuid5(...)` at `:597`, confirming the R-8-lane discriminator shape.
- The controller never sets it (`RepositoryAdjustmentIntent.php:47` defaults `?string $posShiftId = null`).

### 2.1 R-8 orthogonality — verified by reading `.worktrees/r8-sv-queue` READ-ONLY

R-8 tip `7db5fe467`, base `fa807a699` (an ancestor of this lane's base — same trunk). `git diff --name-only` over R-8's full range returns **zero** matches for `RepositoryAdjustment`: R-8 does **not** touch `RepositoryAdjustmentService.php`, `RepositoryAdjustmentController.php`, `RepositoryAdjustmentIntent.php`, or `RepositoryNotSeededException.php`. Its 16 touched files intersect this lane's 9 in **zero** places.

R-8 rewrites `PostShiftCashVarianceAdjustment.php` (+391) but preserves the contract: `grep posShiftId` in R-8's listener returns `:686 posShiftId: $event->shiftId,` — still unconditional at the single call site.

**Conclusion: textually and semantically mergeable in either order. No conflict. Not a finding.**

---

## 3. FE contract

The route is `POST /api/v1/payment-repositories/{repository}/adjustments` (`Treasury/Presentation/routes.php:98`, `can:treasury.adjust`). The sole FE consumer is `apps/web/src/features/treasury/hooks/useAdjustRepositoryBalance.ts`. Its `flatErrorMessage()` narrows to `data.error` and returns it only `if typeof data.error === 'string'`; `onError` renders `flatErrorMessage(error) ?? getErrorMessage(error)` into a toast. It **never enumerates response keys and never reads `code`** — so the additive `code` field is inert to it. `AdjustBalanceDialog.tsx` renders only react-hook-form field errors (`errors.direction`/`errors.amount`/`errors.reason_code`/`errors.reason_text`), never the server envelope. `apps/pos/src` has no consumer of this endpoint. **No FE break. The controller's "ADDITIVE" comment is accurate.**

### 3.1 AR i18n skip — the stated reason is TRUE

`apps/api/lang/` contains `ar/`, `en/`, `fr/`. `ls apps/api/lang/ar` returns **`treasury.php` only** — there is no `ar/messages.php`, while `en/` and `fr/` each carry 11 files including `messages.php`. The `messages` namespace is genuinely not wired for AR, so adding one key there would have created an orphan file, not a translation.

No gate trips: `apps/web/tools/audit-i18n-completeness.mjs` scans only `apps/web/src/locales/<locale>/<ns>.json` (`:16`, `:342`, `:427`) and never reads `apps/api/lang`. There is **no** backend lang-parity test (`grep` over `apps/api/tests` for `lang/fr|lang/en|lang_path` returns nothing). The AR gate rules FR everywhere + AR in wired namespaces — `messages` is not wired. **Claim verified. No finding.**

---

## 4. Runbook accuracy

`docs/handoff/RUNBOOK-opening-cash-float-2026-08-23.md` read in full. Every in-tree citation checked live:

| Citation | Status |
|---|---|
| `TunisiaChartOfAccountsSeeder.php:136` (`119 Solde d'ouverture`) | ✅ exact |
| `TunisiaChartOfAccountsSeeder.php:324-325` (`7580 Écart de règlement (produits)`, `PaymentToleranceIncome`) | ✅ exact |
| `apps/web/src/routes/index.tsx:2426-2437` (opening-balances route) | ✅ live (`{/* Opening Balances */}` + `<Route path="opening-balances">`) |
| `Accounting/Presentation/routes.php:108-126` (opening-batches CRUD) | ✅ live |
| `apps/api/config/treasury.php:28` (`shift_variance_gl_enabled` default `false`) | ✅ exact |
| `ReconcileTreasuryCommand.php:899-907` (JE exemption for `OpeningBalance`) | ✅ exact |
| `MovementSourceType.php` enum case | ✅ exists (`:15`) |

**B-15 caveat: present** (lines 17-25), correctly flags the TN-chart-is-French-PCG escalation and instructs the reader to use `payment_repositories.gl_account_id` rather than the literal code.

**Step 2 remediation is accounting-sane.** The misuse books `Dr cash / Cr 7580`; the float should be `Dr cash / Cr 119`. The prescribed correction — reverse `7580` against `119` (i.e. `Dr 7580 / Cr 119`) — is exactly the delta, and the doc correctly adds "do not also include the float in the opening batch", preventing a double cash debit.

**No claim contradicts the guard.** Step 2's blockquote explicitly states the API now refuses this mechanically with `REPOSITORY_NOT_SEEDED`, and correctly scopes the remediation paragraph to "any tenant seeded before that guard shipped". This is the specific contradiction the gate asked about, and it is **absent**.

Two accuracy defects found — see Findings 1, 2, 3.

---

## 5. VAT pins

**Comment-only in production: PROVEN.** Filtering the diff of `EloquentVatDataRepository.php` for changed lines that are not `//`, `*`, `/*` or blank returns **nothing** (grep exit 1). The two `COUNT(DISTINCT ...)` expressions at `:53` and `:145` are byte-identical to base. The comment's internal cross-reference to the document arm at `:53` is correct.

**Mutation proof re-run from scratch — both arms, each isolated:**

| Mutation | Result |
|---|---|
| POS arm `:145` → `COUNT(DISTINCT CASE WHEN r.receipt_type = 'sale' THEN r.id END)` | **exactly 1 failure**: `test_pos_refunds_reduce_output_vat_in_both_writer_sign_conventions` — "Failed asserting that 1 is identical to 3" at `VatDataRepositoryTest.php:380`. All 38 assertions still ran; every money assertion stayed green — proving the money tests genuinely could not have caught it. |
| Document arm `:53` → `COUNT(DISTINCT CASE WHEN d.type <> 'credit_note' THEN d.id END)` | **exactly 1 failure**: `test_credit_notes_count_as_declared_documents_while_netting_the_money` — "Failed asserting that 1 is identical to 2" at `:449`. |

Both reverted; worktree clean. **The mutation-proven claim is honest and reproducible.**

**Both drivers green:**

| Suite | sqlite | PG (`autoerp_b2gate_r1_test`) |
|---|---|---|
| `VatDataRepositoryTest` | OK 8 tests, 38 assertions | OK 8 tests, 38 assertions |
| `RepositoryAdjustmentTest` + `RepositoryAdjustmentServiceTest` | OK 28 tests, 189 assertions | OK 28 tests, 189 assertions |

The new document-arm test uses real `DocumentTaxDetail` rows, a real `FiscalCategory::CreditNote` document via a **non-breaking optional-parameter** widening of `createDocument()` (default preserved as `FiscalCategory::TaxInvoice`), and asserts both the netted money **and** the un-netted count. No `assertTrue(true)`, no mocking of the unit under test. **Test quality: good.**

See Finding 4 on the "30/30" count claim.

---

## 6. Red-first / conjunct isolation — reproduced

Method: `git checkout <base|HEAD> -- <file>` plus targeted scratch mutations, each run against `RepositoryAdjustmentTest` (22 tests), each reverted.

| # | Mutation | Observed | Verdict |
|---|---|---|---|
| **M1** | Base file restored (guard absent entirely) | exactly 1 red: `test_adjustment_on_a_never_seeded_repository_is_refused_with_a_named_code` — **"Expected 422 but received 201"** | ✅ and note: **201** proves the misuse was previously *accepted end-to-end*. The defect is real, not hypothetical. |
| **M2** | `isNeverSeeded()` short-circuited to a balance-only predicate | exactly 1 red: `test_adjustment_on_a_repository_with_prior_movements_is_accepted_unchanged` — "Expected 201 but received 422" | ✅ the movements conjunct is genuinely load-bearing; a balance-only guard would brick every swept till. |
| **M3** | Carve-out removed (`$intent->posShiftId === null &&` dropped) | exactly 1 error: `test_a_shift_originated_adjustment_is_exempt_on_a_never_seeded_till` throwing `RepositoryNotSeededException` from `RepositoryAdjustmentService.php:150` | ✅ the carve-out is load-bearing and asserted at the seam. |

Each mutation produced **one** correctly-targeted failure and no collateral. The three tests are genuinely orthogonal.

**Carve-out end-to-end:** `ShiftCashVarianceAdjustmentTest` is **OK 23 tests / 98 assertions** on sqlite, so the listener path is unaffected by the guard.

**Disclosed PG-only inherited red — confirmed pre-existing and untouched.** On PG, `ShiftCashVarianceAdjustmentTest` yields 1 error at `ShiftCashVarianceAdjustmentTest.php:413` — a **test fixture** doing `$this->till->forceFill(['balance' => '1.000', ...])->save()`, rejected by `forbid_direct_balance_write`. This is exactly `docs/superpowers/tickets/2026-08-10-g3-shiftcashvariance-fixture-forcefill-pg-trigger.md`, whose `:9` cites the same `ShiftCashVarianceAdjustmentTest.php:413`. The file is **not** in this lane's diff. Correctly disclosed, correctly out of scope.

---

## 7. Scope

`git diff --name-only 2a18fe5d7..HEAD` = **9 files**, matching the declared scope exactly:

```
apps/api/app/Modules/Taxation/Infrastructure/Repositories/EloquentVatDataRepository.php   (comment-only)
apps/api/app/Modules/Treasury/Application/Services/RepositoryAdjustmentService.php
apps/api/app/Modules/Treasury/Domain/Exceptions/RepositoryNotSeededException.php          (new)
apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryAdjustmentController.php
apps/api/lang/en/messages.php
apps/api/lang/fr/messages.php
apps/api/tests/Feature/Taxation/VatDataRepositoryTest.php
apps/api/tests/Feature/Treasury/RepositoryAdjustmentTest.php
docs/handoff/RUNBOOK-opening-cash-float-2026-08-23.md                                     (new)
```

No `.github/`, no G-5 surfaces, no `opening_float` implementation (correctly deferred per Recommendation 3 = post-launch), no R-8 files, no migrations, no generated types. **Scope is clean.**

### 7.1 Disclosed, justified deviations from Recommendation 2

Recommendation 2 (`:633`) scoped the refusal to "a `correction`/`other` adjustment on a repository with zero prior movements". The lane deliberately **widened** to all reason codes and **narrowed** by adding two conjuncts plus the caller-origin carve-out. Both deviations are explicitly documented in `isNeverSeeded()`'s docblock ("What it deliberately does NOT discriminate on"), and the reasoning is sound: the reason code is an operator-chosen dropdown, so scoping by it would let the identical misuse through under `count_variance`. **Endorsed — this is a better guard than the recommendation asked for.**

---

## Findings

### 1. [Important] Runbook asserts an owner ruling that does not exist

`docs/handoff/RUNBOOK-opening-cash-float-2026-08-23.md:4` reads:

```
**Owner ruling:** B-2 (owner sheet `OWNER-SHEET-2026-08-21-first-client-session.md`, resolution line 40)
```

But `OWNER-SHEET-2026-08-21-first-client-session.md:20` states verbatim: **"B-2 NOT YET RULED — research ordered"**, and commit `cee085bbd` records "B-1/B-3/B-5/B-7/B-13/B-14 ruled, **B-2**/B-4/B-6 research ordered". `grep 'B-2'` over the owner sheet returns only `:20` and `:46` — there is no later ruling.

**Why it matters:** in a program where owner rulings are tracked in a ledger and gate promotion, a doc that self-labels as an owner ruling can cause the B-2 row to be closed on false grounds. The *work* is authorized (research Recommendation 1 is "pre-launch mandatory"), and the runbook already carries a correct `**Authority:**` line — only the provenance label is wrong.

**Fix:** change line 4 to reflect delegated authority, e.g.
`**Owner ask:** B-2 (owner sheet OWNER-SHEET-2026-08-21-first-client-session.md:20 — research ordered 2026-08-23, not yet ruled; ask at :46). **Delivered under:** research Recommendation 1 (pre-launch mandatory).`

### 2. [Minor] Both owner-sheet line citations are wrong (they point at a blank line and a generic sentence)

- `RUNBOOK-opening-cash-float-2026-08-23.md:4` → "resolution line 40". Owner sheet `:40` is a **blank line**.
- `EloquentVatDataRepository.php:93` → "owner sheet 2026-08-21, resolution line 41". Owner sheet `:41` is *"Full audit evidence lives in the session transcript; gaps are ranked G1–G13. The asks:"*.

Correct anchors: **B-2** → `:20` (status) / `:46` (ask); **B-6(i)** → `:24` (`"B-6(i) RULED include-per-standards"`) / `:50` (ask).

**Why it matters:** the entire deliverable of commit `53cd7fc12` is a *traceability pin*. A pin whose line citation resolves to whitespace degrades exactly the property it exists to create, and the gate's own standard is that every `file:line` must be live. (The B-6(i) **`RULED`** claim itself is accurate — only the line number is wrong.)

**Fix:** `:4` of the runbook → `:46`; `EloquentVatDataRepository.php:93` → `resolution line 24`.

### 3. [Minor] Runbook step 2 under-states the guard's predicate and omits the carve-out and the unlock path

`RUNBOOK-opening-cash-float-2026-08-23.md:60-64` describes the refusal as firing on "zero movements, zero prior adjustments" — dropping the third conjunct (zero cached balance), not mentioning the shift-variance carve-out, and not telling the operator how a legitimately-virgin till becomes adjustable again.

**Why it matters:** an operator hitting `REPOSITORY_NOT_SEEDED` on a till that received outside cash has no documented next step; step 4 only covers the *outflow* refusal, which is a different error. The legal path (a `treasury.transfer` into the till, which lays a `Transfer` movement and permanently unlocks the guard) is real but undocumented.

**Fix:** one clause in that blockquote — state all three conjuncts, note that shift-close variance postings are exempt, and add: "A till that legitimately received cash outside the opening batch should receive it as a **Treasury transfer** (Treasury → Transfer), which records the movement and unlocks ordinary adjustments."

### 4. [Minor] The "30/30 both drivers" test claim does not match observed counts

Observed: `VatDataRepositoryTest` = **8** tests both drivers; `RepositoryAdjustmentTest` + `RepositoryAdjustmentServiceTest` = **28** tests both drivers; `RepositoryAdjustmentTest` alone = **22**. No invocation in this lane's scope produces 30. Everything is green, so this is a bookkeeping defect in the handback, not a correctness one — but a gate that accepts unverifiable run counts trains the next gate to skip the re-run.

**Fix:** restate the evidence line with the exact command and the exact counts (8/8 and 28/28, sqlite and PG).

### 5. [Minor — pre-existing, adjacent] `PaymentRepository.php:71` now contradicts the lane's new docblock

`PaymentRepository.php:71` says the direct-balance-write trigger "guards UPDATEs only". That has been stale since the cutover-hardening INSERT branch landed (`2026_07_08_160000_...php`, INSERT branch), and this lane's `isNeverSeeded()` docblock now correctly describes the INSERT guard — so the tree carries two comments that disagree about the same trigger.

Not introduced here and strictly out of scope under rule 4. Flagged so it is a deliberate choice: fix the one line while adjacent, or ticket it.

---

## Summary

The engineering substance of this lane is **sound and survived hostile testing**. Every load-bearing claim was independently re-derived: the movements conjunct is genuinely load-bearing (M2), the carve-out is genuinely load-bearing (M3), the pre-guard path genuinely returned 201 (M1), the balance conjunct genuinely cannot flip in production (trigger + GUC + birth-at-zero all verified), the VAT change is genuinely comment-only (filtered diff, exit 1), the mutation proof genuinely reproduces on both arms with exact isolation, the FE genuinely cannot break on an additive key, the AR skip reason is genuinely true, and R-8 genuinely does not overlap. Rule 19 is respected throughout (bcmath only, currency-argument scale resolution). PHPStan clean, Pint clean, 28/28 + 8/8 green on both sqlite and PG.

What blocks is **documentation provenance, not code**: the runbook claims an owner ruling for B-2 that the owner sheet explicitly says was never made, and both owner-sheet line citations resolve to whitespace/boilerplate in a commit whose sole purpose is traceability. These are markdown- and comment-only fixes requiring no re-test beyond a re-read; findings 3-5 can ride the same touch.

**One-line fix before merge:** correct the B-2 provenance label and the two dead owner-sheet line citations (runbook `:4`, `EloquentVatDataRepository.php:93` → `:20`/`:46` and `:24`), then re-gate on the doc diff alone.

VERDICT: CHANGES-REQUIRED

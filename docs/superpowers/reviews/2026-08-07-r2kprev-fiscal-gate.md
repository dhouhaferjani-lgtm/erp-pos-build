# GATE RECORD — R2-K-prev (deposit seal-before-resolve PREVENTION sublane) — fiscal-pos axis

**Round 1. Verdict: APPROVE-WITH-FIXES.**
**Branch:** `fix/r2k-prev-deposit-preflight` @ `fb590b977` (worktree `../erp.fix-r2kprev`), 2 commits on `264e6c483`.
**Diff surface:** 6 files, +338/−31. Reviewer ran the lane test file and an independent red-run.

## Verification of the 7 implementer claims

**Claim 1 — frozen check at `DepositReferenceResolutionService.php:151-153`, ordered LAST to mirror the port. PARTIALLY TRUE.**
Ordering mirror confirmed for the plain-cash path: `TreasuryMovementService.php:82` (currency guard) precedes `:91-96` (freeze policy), and the preflight matches — currency at `DepositReferenceResolutionService.php:142`, frozen at `:151`. Bridge-side ordering also matches (`TreasuryDepositBridge.php:110` method → `:111` repository → `:112` actor → `:249` movement port). **But the parity claim at `DepositReferenceResolutionService.php:66-69` ("the refusal a caller sees is the one they would have hit post-seal") is FALSE for maturity tenders — see I-1.**

**Claim 2 — membership check `:132-140` is an EXACT mirror of `resolveActorUserId()`. FALSE (strictly), harmless in practice.** See m-2.

**Claim 3 — `assertProjectable()` at both `:74` and `:106`; refusal at `:106` rolls back everything. TRUE.**
`RecordCustomerDepositService.php:106` is the **first** statement of the `$this->db->transaction()` closure opened at `:79`; the next statement is `appendDepositReceipt()` at `:108`. Nothing can seal between them. The throw propagates out of `ConnectionInterface::transaction()`, rolling back the outermost transaction (level 0 in prod). Empirically confirmed by the green run + `assertNoDepositWasSealed()`.

**Claim 4 — 4 probes, non-vacuous. TRUE — proven by the reviewer.**
Green run: `19 tests, 113 assertions, OK` (36s).
Independent red-run — reviewer commented out only `RecordCustomerDepositService.php:106`, re-ran, then restored (tree verified clean):
- `test_a_freeze_landing_after_the_pre_flight_...` → `RepositoryFrozenException` thrown from `TreasuryMovementService.php:95` via `TreasuryDepositBridge.php:249` — i.e. **post-seal, from inside the projection**, exactly the orphan-minting vector.
- `test_post_refuses_a_membership_revoked_after_the_pre_flight_...` → `Projector "treasury_deposit_bridge" cannot apply fiscal_event …: actor_not_active_company_member:…`.
Both mid-request probes fail without the in-transaction check ⇒ they are NOT testing the outer preflight. Non-vacuity established for both.
`assertNoDepositWasSealed()` (`RecordCustomerDepositTest.php:697-706`) queries the right three tables (`fiscal_events` filtered to `DEPOSIT_RECEIPT`, `pos_deposit_receipts`, `payments`); nothing downstream (movements/JEs/instruments) can exist without a payment on this flow.

**Claim 5 — honest-scope statement present and TRUE. TRUE.**
Present at `RecordCustomerDepositService.php:89-105`, `DepositReferenceRefusal.php:19-25`, `DepositReferenceResolutionService.php:39-50`. The statement is factually true: the transaction closes at `RecordCustomerDepositService.php:124` and `runSeededProjectionsSync()` runs at `:130`, post-commit — and the red-run *demonstrates* the post-commit failure mode it describes.

**Claim 6 — no hash-chain / sealing-format / `TreasuryDepositBridge` changes. TRUE.** Diffstat touches only the exception, the orchestrator service, the FormRequest docblock, the resolution service, the refusal enum, and the test file. `VirtualAdminFiscalEventService`, `TreasuryDepositBridge`, and all integrity/canonical-encoding code are untouched.

**Claim 7 — required-param sweep. TRUE.** `grep -rn "refusalFor\|forRefusal(" --include='*.php'` over `apps/api` (excl. vendor) returns exactly one caller each: `RecordCustomerDepositService.php:178` and `:191`. No interface/abstract declares `refusalFor`. No seeders/console consumers. No missed callers.

**Quality gates run by reviewer:** `pint --test` on all 6 files → pass; `phpstan analyse` on the 4 changed app files → `[OK] No errors`; test file 19/19 green. Deptrac: no new violation — `deptrac.yaml:18-22` explicitly does **not** enforce cross-module coupling, and `ModuleApplication → ModuleDomain` is an allowed edge (`deptrac.yaml:95-100`).

## FINDINGS

### I-1 (Important) — the new `RepositoryFrozen` refusal has NO post-seal counterpart on the maturity-tender path: a false 422 on a reachable ops flow, plus a false parity claim in code
`DepositReferenceResolutionService.php:146-153` (and the claims at `:37` and `:66-69`).
For a cheque/effet payment method the bridge takes the maturity leg and **returns before the movement port is ever called**: `TreasuryDepositBridge.php:113` (`handles()`), `:169-173` (`$shouldRecordMovement = false`), `:217-219` (short-circuit return before `movementService->record(...)` at `:249`). Neither the port's currency guard (`TreasuryMovementService.php:82`) nor its freeze policy (`:91-96`) executes. `InstrumentLifecycleService::receive()` carries no freeze check (only company-currency at `InstrumentLifecycleService.php:77-79`).
Reachability over HTTP is open: `RecordDepositRequest.php:77-84` accepts **any** active `payment_method_code`, and `HandlesMaturityTenderLeg::handles()` (`HandlesMaturityTenderLeg.php:33-37`) returns true for `has_maturity` + `Cheque|Effet`.
**Failure scenario:** a cash count freezes the drawer repository. A back-office user records a customer **cheque** deposit against that repository. Before this lane it projected fine (instrument received into the ChecksToCollect portfolio account; no cash movement, so the freeze is irrelevant). After this lane it is refused with 422 — a new, fail-closed but **wrong** refusal, directly contradicting the in-code guarantee at `:66-69` and the parity-table row at `:37`.
**Fix:** gate the `RepositoryFrozen` row on the method not being a maturity tender (mirror `HandlesMaturityTenderLeg::handles()`), or if the over-refusal were accepted, correct the parity claims — merging the false parity claim as-is will not pass. Note: the pre-existing `RepositoryCurrencyMismatch` row (`:35`) has the identical hole — prior debt, but re-asserted by the new ordering docblock; name it in the same fix.

### I-2 (Important) — the ticket of record was not updated; K-rec will consume a stale "not fixed" status
`docs/superpowers/tickets/2026-08-05-deposit-residual-seal-before-resolve-vectors.md:8` still reads `**Status:** OPEN — deliberately not fixed in the L1 lane`, and its V1 "Suggested disposition 1" and the V2 membership suggestion are now implemented. The diff contains **zero doc changes**.
**Failure scenario:** the K-rec implementer opens the ticket, reads "not fixed", and either re-implements V1/V2 preflights or mis-scopes K-rec's orphan-detection window.
**Fix:** amend the ticket status to record V1+V2 landed in R2-K-prev, races NARROWED to the sealing transaction, orphans still reachable post-commit, disposition/detection remains K-rec; record the I-1 maturity-tender caveat.

### m-1 (Minor) — cross-module import verdict: **ACCEPTABLE-WITH-TICKET**, but the ticket must name the existing compliant surface
`DepositReferenceResolutionService.php:8-9` adds `Company\Domain\UserCompanyMembership` + `MembershipStatus` (Treasury → Company model import; rule 6). Mirror-the-bridge rationale factually verified (`TreasuryDepositBridge.php:8-9` identical imports); debt documented in-code at `:51-58`; no deptrac regression. But `CompanyContext::userHasAccessToCompany()` (`CompanyContext.php:146-152`) is a Company-module **public service** carrying the same predicate — the predicate is now **triplicated**. Acceptable-with-ticket provided the debt ticket names all three sites and the `Shared/Contracts` port converges them.

### m-2 (Minor) — the membership mirror is not exact: the bridge's actor-existence arm is unmirrored
`DepositReferenceResolutionService.php:132-136` mirrors only arm (B) of `resolveActorUserId()`. Arm (A) — `User` row scoped to event tenant, `TreasuryDepositBridge.php:423-430` — has no preflight counterpart, yet the comment at `:127` claims a wholesale mirror. Practically unreachable (`user_company_memberships.user_id` `onDelete('cascade')`, no SoftDeletes on either model). **Fix:** narrow the comment or add the existence arm.

### m-3 (Minor) — the probe docblocks describe a mechanism that does not happen
`RecordCustomerDepositTest.php` (~`:548`) says the freeze "commits between the pre-transaction pre-flight and the append". `TransactionBeginning` fires *after* `beginTransaction()`, so the listener's `UPDATE` executes **inside** the transaction and never independently commits; the in-transaction re-read sees it because same connection. Probes valid and non-vacuous, but they model a same-connection write, not a concurrent committer — correct the docblock.

### m-4 (Observation) — the §65-80 authz-reachability answer is asserted in a docblock, not pinned by a test. Reviewer verified independently; it holds.
`CompanyContextMiddleware` in the global `api` group (`bootstrap/app.php:109`); `userHasAccessToCompany()` requires `status = Active`, so a non-member is 403'd before the controller runs. Suggest a 3-line regression test pinning the 403. Adjacent (out-of-lane): `CompanyContextMiddleware.php:50-52` skips company context for non-`User` principals (SuperAdmin) → `requireCompany()` at `PartnerDepositController.php:34` would throw rather than 403.

## Out-of-lane (recorded by implementer, confirmed ticket-worthy)
- `tests/Feature/Fiscal/TreasuryDepositBridgeTest.php:300` broken on dev (`ArgumentCountError` — 3 args vs 4-arg constructor since `HandlesMaturityTenderLeg`); its membership-failure test currently asserts nothing.

## VERDICT: **APPROVE-WITH-FIXES**
Contract substantially met — both vectors implemented, honest-scope statement present and verified true, in-transaction re-check proven load-bearing by independent red-run, zero hash-chain/sealing/bridge changes, no K-rec leakage, no missed callers, all local gates green. **Before merge:** correct/scope-gate the `RepositoryFrozen` parity for the maturity-tender short-circuit (I-1) and update the ticket of record (I-2).

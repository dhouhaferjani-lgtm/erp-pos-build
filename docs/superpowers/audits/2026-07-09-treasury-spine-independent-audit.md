# Treasury Money-Movement Spine — Independent Adversarial Audit (2026-07-09)

**Subject:** `feat/treasury-spine` @ `a7bba2e23` (54 commits over base `6a3292b42`, 134 files, +19,137/−1,662, confirmed UNPUSHED).
**Trigger:** owner request for an independent audit of `docs/handoff/TREASURY-SPINE-REVIEW-PREP-2026-07-09.md` before any remediation/promotion.
**Method:** four parallel adversarial reviewers (treasury write-port/reconcile; GL hash-chain/POS bridges; tenancy/authz/deploy-shape; precision/migrations/frontend), each instructed to refute the prior "READY FOR OWNER PROMOTION" verdict — plus an independent controller verification lane (test suites re-run, failure provenance checked against base, E2E evidence inspected, SDD ledger recovered).

---

## 1. Verdict

**CONDITIONAL READY.** The prior review's core engineering claims **hold under independent adversarial re-verification** — the write path is sound and no money-corrupting defect was found by any of the four reviewers. However the audit found:

- **2 new in-branch defects that should be fixed before promotion** (§3 N1, N2) — one degrades the reconcile safety-net's cross-tenant guarantee, one is a red test the handoff *misclassifies as pre-existing* (it is branch-caused).
- **1 handoff factual error** (§4) — the "ClosedFiscalPeriod tolerance test = pre-existing at base" claim is false.
- Confirmation that the disclosed deploy landmines are all real, and one (brownfield backfill) still has **no shipped tooling** — the branch is promotion-ready but **not deploy-ready** until §6 is executed.

Promotion to local `dev` is reasonable once N1/N2 are fixed (or explicitly accepted as tickets). Production deploy remains hard-gated on §6.

## 2. Independently re-verified claims (all TRUE)

| Claim | Evidence |
|---|---|
| Hash-chain sealing byte-identical | `GeneralLedgerHashService` diff vs base is **empty**; `sealAndPersistEntry` (GeneralLedgerService.php:2411-2490) hash sequence line-for-line identical to base `postEntryWithOptionalActor`; advisory lock (:2453) + closed-period guard (:2462) inserted **before** the hashed reads; `journal_code` not in payload (GeneralLedgerHashService.php:84-90) |
| Port idempotency fail-loud | `isUniqueViolation` (TreasuryMovementService.php:674-689) matches SQLSTATE 23505 exactly; `handleIdempotentHit` (:500-509) **throws** `IdempotencyConflictException` on non-idempotency violations; semantic-mismatch throws (:531-533) |
| Gapless ordinal / no double-applied balance | `lockForUpdate` on repo row before ordinal math (:61-66); savepoint recovery (:106-135); `(repo, ordinal)` unique backstop |
| GUC lifecycle | `SET LOCAL 'on'` before savepoint (:92/:223); `closePort()` in `finally` on all exit paths (:622-627); grep: only 4 legitimate setters (port ×2, trigger read, test factory) |
| Cutover trigger + sole-writer | INSERT+UPDATE both guarded (migration :46-58); grep: **zero** direct balance writers remain outside the port (:444); `balance` out of `$fillable` |
| Null-JE sweep | All `record()` call sites pass a real JE or a legitimate exemption; `567e8db04` VendorRefundService fix confirmed (VendorRefundService.php:160-198) |
| POS bridge idempotency | Canonical index stable (CanonicalPayloadReader::forSaleReceipt :91-93, no sort); `allowWhileFrozen = !isServerOnly()` correct on all 3 bridges; refund branch = direction Out + reversal JE (TreasuryReceiptBridge:508-552); legacy null-key fallback present (:292-301) |
| afterCommit-seal crash window fails safe | Reconcile Check 2 requires `status === Posted` (ReconcileTreasuryCommand:208) → freeze, never silent corruption |
| Authz/scoping | Both route groups full middleware incl. `EnforceTokenTenantClaim`; every new endpoint permission-gated; tenant+company scoping on all lookups (cross-company → 404); movements filters validated enums/dates, `orderByDesc('ordinal')` hardcoded — no injection surface |
| Precision (rule 19) | Zero float/`number_format`/raw-arithmetic on money in the diff's PHP additions; reconcile Σ is PHP-side bcmath at `getScaleSafe($currency, 3)`; `balance` widened to (15,3) by pre-existing `2026_03_11_200000` so the scale-3 compare is sound |
| Wave-G FE rules | `useRepositoryMovements` = `api.get`+`response.data` (rule 14, meta preserved); `tenantScopedKey` + dedicated regression test; no parseFloat-on-money; en/fr **431/431 key parity** (scripted); phantom Instrument fields genuinely eliminated; smoke CI guard effective (`workflow_dispatch`-only + `CI` always set) |
| Architecture tests not weakened | Only change is the **added** `TreasuryBalanceWritePortTest.php` (two-phase app-wide scan) |
| `bc0f30585` fixture-only | Grep of changed assert/expect lines across its diff: none — genuinely fixture-only |
| Test suites (re-run this audit) | Feature/Treasury **434/434** (18 pgsql-skips); Accounting+Expense+Income **502/502**; FE vitest treasury **214/214** (27 files, 0 zombie workers); E2E screenshots exist and are internally coherent (balance_after chain 524,750→534,750, refund −50,000 / adjustment +10,000) |

## 3. Findings (ranked; N = new this audit, K = known/acknowledged but re-qualified)

### Fix before promotion (in-branch, small)

- **N1 [IMPORTANT] `treasury:reconcile` has no per-tenant/per-repo failure isolation.** `TenantScopedCommand::forEachTenant` (app/Console/TenantScopedCommand.php:121-149) is `try/finally` only — no catch; `ReconcileTreasuryCommand.php:87-101` iterates repos bare. An exception in tenant 3's freeze path (lock timeout, audit write, corrupt row) aborts tenants 4..N — **their drifted drawers are silently never reconciled that night**, defeating the safety net exactly when it matters. Compounding: the scheduler `onFailure` alert (routes/console.php:49-51) then claims repositories "were FROZEN" when nothing was. Fix: `try/catch(\Throwable)` per repo + per tenant, aggregate to FAILURE, continue; make the alert text outcome-accurate.
- **N2 [IMPORTANT] Branch-caused red test misclassified as pre-existing.** `Tests\Unit\Treasury\PaymentAllocationServiceTolerancePersistenceTest` errors on the branch with the **new** `ClosedFiscalPeriodException` (fixture posts `payment_date = 2025-01-15` into an auto-closed period). Provenance proven: test file untouched by branch; exception class absent at base; **test passes 1/1 on dev**. Handoff §3's "confirmed identical at the base tree" is factually wrong for this test. Beyond gate hygiene, it is a live demonstration of the ticketed closed-period blast radius: **a legitimately backdated payment allocation now hard-fails in production**. Fix: repair the fixture date AND scope/decide the blast-radius policy (the ledger's tracked follow-up: POS/Treasury/seeder backdated-date sweep). Same process lesson as the prior review's own IMPORTANT-5 — Unit/Treasury was another sibling-suite blind spot.

### Owner decisions / deploy-gated (confirmed real; most acknowledged, some re-qualified)

- **K1 [DEPLOY-BLOCKER] Brownfield opening-balance backfill has NO shipped tooling.** Only `OpeningBalance` producer in the codebase is the greenfield seeder (PaymentRepositorySeeder:141); the demo remediation was a scratchpad script. First 02:15 reconcile freezes every pre-spine repo, and the cutover trigger blocks direct repair. **Productize the artisan backfill command before deploy** (acknowledged in handoff §8.3.1 — audit confirms the risk is exactly as stated and un-tooled).
- **K2 [IMPORTANT] TN/FR adjustment endpoint = confirmed HTTP 500, code-unfixed.** `Account::findByPurposeOrFail` (Account.php:256-262) throws bare `RuntimeException`; TN/FR chart seeders lack 658/758; the test suite masks it by hand-seeding (RepositoryAdjustmentTest.php:281-310). Recommend fixing in code (seed TN/FR charts + a `RuntimeException`→422 render), not only as a deploy note.
- **N3 [IMPORTANT] Closed-period guard is not universal.** The live invoice/credit-note→GL path (`AccountingService::createInvoiceGLEntries`/`createCreditNoteGLEntries`, AccountingService.php:106-224, via InvoicePostedListener:32) seals inline and bypasses `sealAndPersistEntry` — a back-dated invoice still posts into a CLOSED period. Pre-existing path, so not refuting; but the "closed-period guard" capability is materially incomplete without it.
- **N4 [IMPORTANT] FEC `journal_code` NULL on the sales journal.** Same `AccountingService` path stamps no journal_code (known Task-9 roll-up, re-qualified: the highest-volume journal (VT) is the one unstamped; no backfill exists).
- **K3 [IMPORTANT] ABBA lock-order inversion affects ALL THREE bridges, not just the receipt bridge** — follow-up ticket (a) is under-scoped. The prior review's "transient, port-idempotent recovery" claim was adversarially confirmed TRUE (single txn, atomic rollback, idempotent retry); residual worst case = retry exhaustion → `failed_jobs` → cash position understated for one receipt (non-corrupting). Fix: company advisory at top of `apply()` txn in receipt + deposit + account-payment bridges.
- **K4 [IMPORTANT] Reconcile TOCTOU false-freeze** (ticket IMPORTANT-3) — confirmed: repo loaded unlocked (:83-100), movements re-queried later (:127-130), freeze with no re-check. 02:15 is *not* race-free for 24/7 POS tenants. Snapshot-read or double-confirm before freeze.
- **N5 [IMPORTANT] UUID path params 500 on garbage input** — RepositoryAdjustmentController:55-58, RepositoryMovementController:40-43, ExpenseController:256-260 (`pay`) lack `Str::isUuid()` guards (documented repo pitfall; PG 22P02 → 500). Pattern-inherited; add `abort_unless(Str::isUuid($id), 404)`.
- **K5 [IMPORTANT] pgsql-only enforcement never exercised in CI** (confirmed §5 item): balance trigger, immutability trigger, GUC lifecycle all `markTestSkipped` on sqlite. A trigger-SQL regression would pass preflight undetected. CI needs a pgsql Treasury leg.
- **K6 [IMPORTANT] refund/reverse perms remain admin-only** — seeder adds `payments.refund`/`payments.reverse` to the catalog only; manager (:443) and accountant (:659) hunks grant `expenses.pay`+`treasury.adjust` but NOT refund/reverse. Existing tenants are cured **only** by reseed + `permission:cache-reset` (`tenants:migrate` does nothing for perms). Owner decision M7 stands.
- **N6 [IMPORTANT] Immutability migration not re-runnable** (elevates known M9): `…100200:56-63` bare `CREATE TRIGGER` (no `DROP IF EXISTS`) while its sibling `…160000:76-79` was hardened. Fails `tenants:migrate` (PG 42710) on any tenant where triggers pre-exist — i.e. precisely the hand-remediated/restored-dump tenants a brownfield deploy produces. Align with the 160000 pattern.

### Minors (new this audit; prior M1–M9 remain tracked in the SDD ledger)

- Chain-sequence index nuance: migration IS safely re-runnable **after dedupe** (atomic DDL); what's unverified is whether stancl `tenants:migrate` continues to the next tenant on one tenant's failure — check before multi-tenant rollout.
- Spine-columns migration aborts (transactionally, loudly) on repos with orphaned company / NULL company currency — pre-scan prod.
- Port accepts `OpeningBalance`+null-JE from any future caller (exemption is reconcile-side only; not HTTP-reachable today) — consider port-side rejection outside seeding/backfill.
- `transfer()` has zero production callers — its concurrency paths are test-verified only.
- Cross-module direct reads: ExpenseService imports/queries `RepositoryMovement` + `PaymentRepository` (ExpenseService.php:28-29, :326, :351) — feed the deptrac P1 baseline; consider a `wasRecorded()` port read.
- Hand-written FE domain types (useRepositoryMovements.ts:10-51 etc.) — enum mirrors verified exact today; rule-7 drift risk tomorrow.
- `closePort()` in `finally` masks the original 40P01 in logs after a deadlock abort; advisory-lock "per-company" comment imprecise (cluster-global, harmless).
- `RepositoryMovementsTab.tsx:225,250` literal `bg-white` (token-rule spirit; dark-mode liability); adjustment endpoint lacks request-level idempotency (only converged writer without it); `ar/treasury.json` stub (pre-existing; fallback masks).

## 4. Handoff corrections

1. **§3 "Known pre-existing… a ClosedFiscalPeriod tolerance test on sqlite — confirmed identical at the base tree" is FALSE** for `PaymentAllocationServiceTolerancePersistenceTest`: it passes at dev/base and fails on the branch (see N2).
2. §8.2 follow-up (a) scope: the ABBA inversion exists in the deposit and account-payment bridges too, not only the receipt bridge (K3).
3. §8.3.4 wording: the chain-sequence index migration is re-runnable *after dedupe*; "not re-runnable" overstates (the operative rule — never re-run-and-hope without dedupe — stands).

Note for completeness: `tests/Architecture` currently has 4 failures on this branch (POS routes literal-array, TenantHealthController, GoodsReceiptService locks, enrichment queue jobs) — **all pre-existing on dev** (none of those files are touched by this diff; the enrichment jobs come from the Track-A merge). Not treasury findings, but the enrichment-jobs one should go to that track.

## 5. Recommended remediation order (NOT executed — awaiting owner go-ahead)

1. **In-branch, before promotion:** N1 (reconcile try/catch + honest alert), N2 (tolerance-test fixture + reclassify in handoff), N5 (three `Str::isUuid` guards), N6 (DROP IF EXISTS on immutability triggers), K2 code-side (TN/FR 658/758 seeding + 422 render). All small, low-risk, test-covered.
2. **Ticketed (fold into existing follow-ups):** K3 broadened to 3 bridges, K4 TOCTOU, N3 closed-period universality + backdated-date blast-radius sweep, N4 FEC sales stamping, port-side OpeningBalance rejection.
3. **Deploy gate (unchanged from handoff §5/§8.3, re-confirmed):** productized backfill command **before first 02:15** (K1); per-tenant `tenants:migrate` + perm reseed + `permission:cache-reset` + owner role decision (K6); pgsql CI leg (K5); chain-sequence dedupe procedure; deliberate one-shot `smoke-test.yml` dispatch (2 staging-writing tests re-armed).

## 5b. Remediation record (fix wave executed 2026-07-09, same day)

§5.1 was executed as a subagent-driven fix wave (5 commits, each TDD'd + per-task reviewed, final adversarial gate APPROVE FOR OWNER PROMOTION):
- **N1** → `a2090e5be` + `f6cd0d15c`: per-repo/per-tenant reconcile isolation; freeze-vs-error boundary (post-freeze alerting failures log `treasury.reconcile.alert_failed`, never re-classify a completed freeze); truthful onFailure text.
- **N2** → `a45f9363a`: open-period fixture (9 assertions verbatim, red→green independently reproduced); handoff §3 corrected.
- **N5+N6** → `f4ec2aa16`: `Str::isUuid`→404 on the 3 endpoints (pgsql 22P02→404 proven); immutability migration `DROP TRIGGER IF EXISTS` (42710 reproduced, 3× re-run clean).
- **K2** → `2c238ce7f`: 6580/7580 PaymentTolerance accounts in TN+FR charts; missing-purpose→422 pre-check before the transaction.

Also on the branch (NOT from this wave — parallel session, owner identity): `d892f775c`+`594305d43`, FE-only Movements-tab conventions refactor; final gate sanity-checked them backend-blind (pagination contract, no parseFloat-on-money, no dangling i18n keys) — no alarms.

Verification at fix-wave HEAD `2c238ce7f`: Feature+Unit Treasury 525/525 (sqlite), FE vitest 241/241, tsc clean.

**Gate follow-up tickets CLOSED (2026-07-10, `92020bb93`, reviewed Approved):** `freezeAndAlert` alert channels split into independent try/catch (audit row written even when the drift log line throws; `alert_failed` now carries a `channel` key); `ToleranceContractTest.php:150` latent literal → `now()` + a date-literal sweep (14 remaining literals in `PaymentAllocationServiceTest.php` verified unreachable for posting paths); `forEachTenant` continue-on-throw contract documented in its docblock. §5.2 tickets and the §5.3 deploy gate are unchanged and still owed.

## 6. Bottom line

The spine's engineering is real: every load-bearing claim (hash byte-identity, port idempotency, sole-writer cutover, null-JE completeness, POS replay stability, precision, authz) survived four independent adversarial passes, and all claimed-green suites re-ran green. The prior review missed nothing money-corrupting. What it missed is operational: the nightly reconcile can silently skip tenants after one failure (N1), one red test was wrongly written off (N2), and several disclosed landmines still lack shipped tooling. Fix the §5.1 list in-branch, then promote; deploy only behind the §5.3 gate.

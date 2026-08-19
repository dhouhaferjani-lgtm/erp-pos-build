## M2 adversarial review — ES-07 (coverage / reporting / mirror), round 1

**Reviewed:** `git diff df85d43f4..HEAD`, M2 slice = `4d5b0d3f5..HEAD` (`VerifyPosChainCommand.php`, `ReceiptHashService.php`, new `ReceiptChainArmVerificationResult`, 2 test files, evidence doc). Brief section: `docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md:481-503` + R-1/R-2.

**What the milestone got right (verified, not taken on the report's word):** Z-arm diff lines = 0 (R-1 holds — `ZReportHashService` untouched, the command's Z branch unchanged). No row's hash shape changed: the legacy arm still re-applies `whereNull('fiscal_event_id')` itself (`ReceiptHashService.php:508`) and canonical-bytes rows still go through `integrityProvider->computeHash`. The mirror arm is a real new cross-layer control and is non-vacuous (disable proof + T-c red are genuine; the control/behavior commit split `be8751bab`→`079fb1f66`, `a8bb3a6b1`→`6a0e593c3` is real in `git log`). No `app()` added to production code; queries go through the injected `databaseManager` (`:80-83`).

---

### Register

**1 — P1 — CONFIRMED (reproduced) — `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:292-311`**
Removing the `fiscal_status = Fiscalized` count and the `count === 0` fast-path routes **pending-seal receipts** into the legacy arm, which never filtered `fiscal_status` (`ReceiptHashService.php:507-512`). A receipt with `fiscal_hash = NULL` now fails the comparison at `:545-551`.
*Failure scenario:* any terminal holding one in-flight unsealed receipt — routine in production — makes `pos:verify-chains` print `Receipts: Legacy ✗` and exit **1** where it exited 0. E-7 evidence runs go false-red.
Reproduced on this HEAD:

```
FAILED FiscalStatusFilterTest > verify chain command excludes pending seal receipts
Expected status code 0 but received 1.   at tests/Feature/POS/FiscalStatusFilterTest.php:155
```

That test (`:129-156`) is **untouched by the M2 diff** and encodes the contract explicitly ("the pending_seal receipt is invisible to the chain verifier"). It was not in the evidence's run list. Sub-defect: the breakpoint is `sprintf('Sequence #%d', null)` → `Sequence #0`, an unusable coordinate.

**2 — P1 — CONFIRMED — `ReceiptHashService.php:271-278` (no `chain_context` predicate), head carried from genesis at `:296`/`:370`**
The coverage fix makes the context-flattening false-red **unconditionally reachable**. Sequence numbers restart per context (`2026_05_24_100000_add_chain_context_to_fiscal_events.php:22-26`; `OutboxIngestor.php:172-179` resolves the head per context) and both contexts anchor at `genesis_seed` (`ZReportHashService.php:251-292` walks per context — the correct shape, four lines away). Pre-M2 the command short-circuited before this arm on a pure-v3 terminal; `:294` now always calls it.
In-range proof: `VerifyEventChainCommandTest.php:639-643` asserts `verifyTerminalChain()` is **false** on the clean two-context v3 fixture (`:611-644`) — and the command ORs those same three arms.
*Failure scenario:* every real v3 terminal (register `:132` — `z_session` + `operational`) reports a receipt-chain break on a clean chain. The brief (`:500-503`) requires GREEN on the clean equivalent; `M2-implementation-evidence.md:376-381` declines it outright. M0-round2/round3 flagged this exact consequence and closed it as a disclosed signal *requiring resolution in an authorized milestone or formal re-scope* — neither happened, and STOP condition C was not invoked. M2 traded a vacuous green for a false red across the whole target population.

**3 — P2 — CONFIRMED — `VerifyPosChainCommand.php:301-310`**
The command prints only `$arm->count`. The fiscal arm's `count` is receipt-linked events (`ReceiptHashService.php:290-293`) but its **verdict** spans every terminal event including snapshot/`z_session` rows; `inspectedCount` has no production consumer (grep: DTO, service, and one test at `ReceiptChainRebuildTest.php:658`).
*Failure scenario:* a tampered `TERMINAL_REGISTRY_SNAPSHOT` renders `Receipts: Fiscal Events ✗ count 0` — the "verdict over a count of 0" shape this milestone exists to kill, sign-flipped and misattributed to the receipt lane.

**4 — P2 — CONFIRMED — `ReceiptHashService.php:222-227` → `ReportController.php:440`**
`verifyTerminalChain()` now also runs the mirror arm, silently widening a **live** chain-integrity endpoint's `is_valid`. Defensible on merits, but `M2-implementation-evidence.md:370-374` claims "no ... controller ... behavior changed" — that claim is false — and no test covers the endpoint's new verdict. (`Nf525DataProvider.php:398` is unaffected; it delegates to the fiscal arm only.)

**5 — P3 — CONFIRMED — `ReceiptHashService.php:379-435`** Mirror arm returns on the first divergence, so multi-row drift needs one run per repair. Matches the legacy arm's habit; noted against the "reporting" goal.

**6 — P3 — PLAUSIBLE (pre-existing, not attributable to M2)** `ReceiptReturnRefactorV3Test` fails 2/9 at `:771` (`bccomp($independentNet,'10.000')` → -1), aborting before the post-Z-close `pos:verify-chains` assertion at `:941`. M2 touches no Z-aggregate code. Consequence: the one E2E test that would have exercised `pos:verify-chains` on a two-context terminal never reaches that line — which is how finding 2 stayed invisible to the suite.

### Lenses
- **fiscal-pos** — applied; see 1, 2, 3. Hash-shape preservation and R-1 both hold.
- **treasury** — **does not apply.** No payments/GL/expense/partial-write surface in the diff.

### Standing checks
Rule 19 **N/A** (no money/quantity arithmetic; no float casts added — full diff read). Red-first evidence **present and strong**. Tenant scoping OK (db-per-tenant, injected connection). Constructor injection OK. No migrations, no new queues. en+fr **N/A** (artisan console output).

### Bypasses I tried that FAILED
- Fan-out via the new `leftJoin('pos_receipts')` in `inspectFiscalEventsArm` (1:N would duplicate event rows → manufactured linkage break). **Refuted:** `pos_receipts.fiscal_event_id` is UNIQUE (`2026_05_14_100005_add_canonical_bytes_and_fiscal_event_id_to_pos_receipts.php:64-65`).
- Inflating the coverage count via a cross-terminal receipt reference. **Refuted:** the filter at `:290-293` compares `receipt_terminal_id` to the terminal.
- `Nf525VerifyChainParityTest` (3 passed), `ReceiptHashServiceVerifyLegacyArmV4Test` (5 passed), `PosCoreReceiptProjectionTest` (30 passed) as regression surfaces — all green.
- Reproducing finding 2 end-to-end through `ReceiptReturnRefactorV3Test` — blocked by finding 6; fell back to the in-range characterization assertion.

**Required before merge:** restore pending-seal exclusion in the legacy arm (or reinstate the status filter) with a covering test, and either partition the fiscal arm by `chain_context` so the mandated two-context fixture goes green, or stop the milestone under STOP condition C for an owner ruling. Findings 3 and 4 are fix-before-merge; the evidence doc's "no controller behavior changed" line needs correcting either way.

VERDICT: CHANGES-REQUIRED

## M2 adversarial review — ES-07 (coverage / reporting / mirror), round 2 (fix round)

**Reviewed:** the fix-round slice `4fea617e0..b8b3c52f6` (`bd4139ec3` ruling, `26b1371a6` controls,
`bc692820a` production fix, `b8b3c52f6` evidence + YAML) against the round-1 register
(`M2-round1.md`), the binding ruling (`ORCHESTRATOR-RULING-2026-08-19-m2-stop-c.md`) and the
milestone contract (`docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md:481-503` + R-1/R-2).
HEAD verified `b8b3c52f6`, tree clean, branch `codex/es-wave-a0`.

**Test harness I used:** PostgreSQL, `apps/api/phpunit-pgsql.xml`, the recorded env recipe
(`DB_DATABASE=autoerp_es_wave_a0_test` on `127.0.0.1:5432`, 270 public tables), every file **by
path**, never the full suite. `apps/api/vendor` in this worktree is a real directory (not the
symlink M0-round1 warned about), so the runs execute THIS worktree's production code.

---

### Round-1 findings — disposition (each re-verified against code, then re-run)

**Finding 1 (P1) — CLOSED.** `ReceiptHashService.php:579-591` re-applies
`->where('fiscal_status', FiscalStatus::Fiscalized->value)` inside the legacy arm, restoring the
predicate the command's deleted pre-count used to carry (`git show 4d5b0d3f5:…/VerifyPosChainCommand.php`
`verifyReceiptChain()` — `whereNull('fiscal_event_id')` + `fiscal_status = fiscalized` + `is_voided`
+ `is_training`). Sub-defect closed at `:676-689` (`legacyBreakPoint()`): a NULL `chain_sequence`
now yields `Receipt <uuid> (unsequenced, <check>)` instead of `Sequence #0`; `Receipt.php:45`
corrects the `@property` to `int|null`.
*Verified untouched-contract green:* `tests/Feature/POS/FiscalStatusFilterTest.php` → **3 passed
(7 assertions)** on this HEAD, and **1 failed** at `26b1371a6` (the control commit, fix reverted) —
so it is a genuine red→green, not a rewritten contract. The file is byte-identical to base
(`git diff 4d5b0d3f5..HEAD -- tests/Feature/POS/FiscalStatusFilterTest.php` is empty).
*Coverage-narrowing bypass attempted and refuted — see the bypass table.*

**Finding 2 (P1) + STOP C — CLOSED, and the ruling is complied with exactly.**
`ReceiptHashService.php:274-340` groups rows into one stream per `(company_id, chain_context)` and
`:349-433` (`inspectFiscalEventStream()`) walks each from `$terminal->genesis_seed`. That is the
same head shape the append path uses (`OutboxIngestor.php:172-179` resolves the prior row per
`(tenant_id, company_id, terminal_id, chain_context)`; `:453-491` anchors the first row of each
context at `pos_terminals.genesis_seed`) and the same shape the Z verifier uses
(`ZReportHashService.php:262-291`). `chain_context` is NOT NULL with default `operational` and a
4-value CHECK (`2026_05_24_100000_add_chain_context_to_fiscal_events.php:15-31`), so no stream key
can collapse on a NULL.
*The three checks and their `Log::error` failure modes are byte-identical outside the head scope:*
rehash (`:358-377`, `hash_mismatch`), 64-char link-length guard (`:379-409`,
`genesis_seed_or_link_length_invalid`), linkage (`:411-427`, `linkage_broken`). Only
`breakPoint` (now via `fiscalEventBreakPoint()` `:440-449`) and the hoisted `$inspectedCount`
changed.
*Red-first topology proven by execution, not by reading.* I created a detached scratch worktree at
the control commit `26b1371a6` (with a copied `vendor`), ran the same PG config, and got:

```
tests/Feature/Fiscal/ReceiptChainRebuildTest.php   8 failed
  ⨯ two context terminal verifies each context as its own chain
      "Each chain_context is its own chain from genesis_seed:
       Event 9cdeadec-… (sequence #1, link)"      ← the OLD, context-less coordinate
  ⨯ two context terminal detects a link tamper inside the z session context
  ⨯ two context terminal detects a link tamper inside the operational context
  ⨯ legacy arm excludes pending seal receipts     "Sequence #0 (hash)"
  ⨯ command reports inspected rows so a snapshot tamper is not attributed to receipts
  ⨯ (3 pre-existing table tests, header widened by the Inspected column)
tests/Feature/Fiscal/VerifyEventChainCommandTest.php --filter=test_seeded_v3_fixture   1 failed
tests/Feature/POS/FiscalStatusFilterTest.php                                           1 failed
```

At HEAD all of them are green (counts below). The scratch worktree was removed.
*Per-context negatives present and each names only its own context:*
`ReceiptChainRebuildTest.php:961-1006` — the z_session tamper asserts the coordinate contains the
tampered event id + `z_session` and NOT the operational event id, and the mirror-image test for
`operational`. The clean-fixture contract flip is exactly the one the ruling mandated
(`VerifyEventChainCommandTest.php:637-647`, `assertFalse` → `assertTrue`).
*No masking introduced:* moving a mid-chain row to another context still breaks BOTH streams (the
donor's successor loses its link, the moved row's `previous_hash` is not `genesis_seed`); deleting
a row still breaks its successor. The CHECK constraint bounds the context space to 4 values.

**Finding 3 (P2) — CLOSED.** `VerifyPosChainCommand.php:179-182` adds the `Inspected` header;
`:145-153` feeds it from `$arm->inspectedCount` via `:291-325`;
`ReceiptChainArmVerificationResult.php:16-26` defaults `inspectedCount` to `count`, so the mirror
and legacy arms report the honest `n/n`. A tampered `TERMINAL_REGISTRY_SNAPSHOT` now renders
`Receipts: Fiscal Events ✗  0  1  Event <uuid> (context operational, sequence #1, hash)` —
pinned by `ReceiptChainRebuildTest.php:1035-1069`. The "verdict over a count of 0" shape is dead on
the receipt lane. (Residual, recorded not required: the row is still *labelled* `Receipts: Fiscal
Events` for a non-receipt event — the ruling asked for the count/verdict attribution, which is
delivered, and the coordinate now identifies the event unambiguously.)

**Finding 4 (P2) — CLOSED.** `M2-implementation-evidence.md:376-387` strikes the false claim in
place and states the widening plainly; `:481-489` labels the new test a **coverage pin, not a
red-first control** — honest, and true: the widening shipped in M2's original diff. The pin
(`ReceiptChainVerificationTest.php:214-240`) is non-vacuous: the receipt carries
`fiscal_event_id`, so the legacy arm cannot reach it (`ReceiptHashService.php:580`), and the seeded
event rehashes clean and anchors at `genesis_seed` — the mirror arm is the ONLY arm that can flip
`is_valid`. Endpoint confirmed at `ReportController.php:440`.

**Finding 5 (P3) — recorded, unchanged, as ruled.**

**Finding 6 (P3) — inherited residual, correctly NOT fixed.** Re-run on this HEAD:
`ReceiptReturnRefactorV3Test` → **2 failed, 7 passed (253 assertions)**, failing at `:771` and
`:515` — identical to round 1. Recorded in the YAML as an open owning-lane question for the parent
(`es-wave-a0.progress.yaml` blockers), which is what the ruling required.

**R-1 — HOLDS.** `git diff 4d5b0d3f5..HEAD -- …/ZReportHashService.php` = **0 lines**, across the
whole M2 range, not just the fix round.

---

### New findings (this round)

**7 — P3 — CONFIRMED — `VerifyPosChainCommand.php:169-171`**
The new comment asserts *"The Z arm's verdict spans exactly the Z reports it counts"*. That is
false. `verifyZReportChain()` (`ZReportHashService.php:210-217`) ORs `verifyFiscalEventsArm()` —
which walks **every** `z_session` + `training_z_session` `fiscal_events` row for the terminal
(`:251-292`), including `SESSION_OPEN`/`SESSION_CLOSE` rows that are not Z reports — with the
legacy `ZReport` walk (`:219-249`). The printed `count` is
`ZReport::where('terminal_id', …)->count()` (`VerifyPosChainCommand.php:337`). So the Z row's
`Inspected` is a **copy of `count`, not a measurement**, and the comment states the exact invariant
the milestone was created to stop asserting without evidence.
*Failure scenario:* an E-7 evidence reviewer reads `Z-Reports ✓ 3 3` and concludes three rows were
inspected, when the verdict actually spanned every `z_session` event on the terminal. The related
`count === 0` fast path at `:339-345` (a v3 terminal with `z_session` events but no `ZReport` rows
returns valid over a count of 0) is **inherited and explicitly out of scope** — the brief says the Z
arm is out of scope beyond what the mirror requires, and R-1 forbids touching it. Only the
false claim and the fabricated `Inspected` value are attributable to this round.
*Fix:* say what it is — e.g. `'inspected' => $result['count'], // Z coverage; the Z arm's verdict
also spans z_session fiscal_events (out of scope, R-1)`.

**8 — P3 — CONFIRMED — `ReceiptHashService.php:549-556`**
`logChainFailure()` still emits `failed_sequence_number` with no `chain_context`. The fix's own
rationale (`:435-439`: *"`sequence_number` alone is ambiguous once contexts are partitioned — each
restarts at 1"*) applies verbatim to this structured forensic log, which is the auditor-facing
channel; only the human-facing `breakPoint` was updated. `failed_fiscal_event_id` keeps the row
resolvable, which is why this is P3 and not P2.
*Fix:* add `'failed_chain_context' => (string) $row->chain_context` to the log context.

**9 — P3 — CONFIRMED — `M2-implementation-evidence.md:524-527`**
The RED-run summary says *"5 failed in `ReceiptChainRebuildTest` (the new controls)"*. The measured
number at `26b1371a6` is **8 failed** — the 5 new controls plus the 3 pre-existing command-table
tests whose expected header the control commit widened. The error understates the red surface (the
safe direction), but an evidence artifact whose stated counts do not reproduce is exactly what this
program's honest-verification lane exists to prevent.

**10 — P3 — CONFIRMED, inherited, newly reachable — `ReportController.php:466-490`**
The `broken_at_sequence` block recomputes the **legacy pipe hash** over ALL receipts including
`fiscal_event_id`-linked ones and threads `previousHash` from `null`. For any fiscal-era terminal
the first projected receipt's `previous_hash` is the genesis seed (≠ `null`), so the loop always
reports the **lowest `chain_sequence`**, whatever receipt actually diverged. M2's mirror arm is now
the route that reaches this block. NOT a regression — pre-M2 the flattening defect made
`is_valid=false` on every clean two-context terminal, so the same wrong coordinate was returned far
more often. The new pin (`ReceiptChainVerificationTest.php:214-240`) asserts `is_valid` and
`chain_length` but not `broken_at_sequence`, so the coordinate stays uncovered.

---

### Lenses
- **fiscal-pos** — applied. No row's hash shape changed: the legacy arm still self-applies
  `whereNull('fiscal_event_id')` (`ReceiptHashService.php:580`) plus the per-row
  `sealed_hash_algorithm` dispatch, and canonical-bytes rows still go through
  `integrityProvider->computeHash` (`:360`). No device-authored fact is re-authored on the server;
  the change is verifier-side only. No event class touched (rule 8 safe). Refund/void model
  untouched.
- **treasury** — **does not apply.** No payments/GL/expense/partial-write surface in the fix round.

### Standing checks
- **Rule 19 — N/A.** No money or quantity arithmetic in the fix round; `git diff 4fea617e0..HEAD --
  apps/api/app | grep -E '\(float\)|parseFloat|number_format|bc(add|sub|mul)|getScale\(\)'` → no
  added lines. No no-arg `getScale()` reachable from this console path.
- **Rule 13 — holds.** No `app()` added to production code; the arm's queries go through the
  injected connection (`ReceiptHashService::db()`); `$this->app->make(...)` appears only in tests.
  (`ZReportHashService.php:257-259` uses `app()` — pre-existing, and R-1 forbids touching it.)
- **Rule 20 — holds.** No new queue (`onQueue` absent from the diff), so no `horizon.php` entry is
  owed; no migration; no CompanyContext dependency introduced in a console path; no SQLite
  timestamp boundary and no device shift re-hydration in scope.
- **PHPStan** level 8 on the four changed production files: `[OK] No errors` (run by me).
  **Pint** `--test` on all 7 changed files: `{"result":"pass"}` (run by me).
- **Tenant scoping** — unchanged: db-per-tenant, per-tenant binding in the command's tenant loop.
  The arm still filters on `terminal_id` only, which is pre-existing and safe under db-per-tenant.
- **en+fr** N/A (artisan console output).
- **SQLite-masking watch:** 3 tests in `ReceiptChainRebuildTest` are driver-skipped on PG
  (`:223`, `:460`, `:1139` — canonical-bytes/mirror UPDATE tampers that PG's immutability trigger
  blocks) and 1 is PG-only (`:801`). Pre-existing split, unchanged by this round; the new controls
  all run on PG.

### Tests I ran myself (PG, `phpunit-pgsql.xml`, by path)

| File | Result | Evidence claim |
|---|---|---|
| `tests/Feature/Fiscal/ReceiptChainRebuildTest.php` | 3 skipped, **21 passed** (147 assertions) | matches |
| `tests/Feature/Fiscal/VerifyEventChainCommandTest.php` | **33 passed** (136 assertions) | matches |
| `tests/Feature/POS/FiscalStatusFilterTest.php` | **3 passed** (7 assertions) | matches |
| `tests/Feature/POS/ReceiptChainVerificationTest.php` | **7 passed** (30 assertions) | matches |
| `tests/Feature/Fiscal/Nf525VerifyChainParityTest.php` | **3 passed** (15 assertions) | matches |
| `tests/Feature/POS/ReceiptHashServiceVerifyLegacyArmV4Test.php` | **5 passed** (6 assertions) | matches |
| `tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php` | **30 passed** (135 assertions) | matches |
| `tests/Feature/POS/ReceiptReturnRefactorV3Test.php` | **2 failed, 7 passed** (253) | matches (inherited) |
| RED at `26b1371a6` (scratch worktree) | 8 / 1 / 1 failed | **evidence says 5 — finding 9** |

Every GREEN count in the evidence reproduces exactly. No full-suite run.

### Bypasses I tried that FAILED

| Attempted bypass | Refutation |
|---|---|
| The restored `fiscal_status = fiscalized` filter silently drops sealed legacy rows from the walk (a coverage regression dressed as a fix) | The column default IS `fiscalized` (`2026_04_18_211150_add_offline_sync_fields_to_pos_receipts.php:20`); the PG trigger permits only `pending_seal→fiscalized` and `fiscalized→voided` (`2026_05_01_000003_…:34-63`); `chain_sequence`/`previous_hash`/`fiscal_hash` are assigned **only** at seal (`ReceiptFinalizationService.php:99-118`), so a `pending_seal` row occupies no chain slot and its exclusion cannot break the thread; and any row wrongly excluded mid-chain would still be caught by the next row's link check. No production writer sets `is_voided = true`. |
| Re-parenting a row's `chain_context`/`company_id` to manufacture a second "clean" chain that anchors at genesis | The moved row's `previous_hash` is the donor chain's head, not `genesis_seed` → link break in the new stream; the donor's successor also breaks. `chain_context` is CHECK-bounded to 4 values (`2026_05_24_100000_…:27-31`). |
| Fan-out through the `leftJoin('pos_receipts')` inflating `inspectedCount` / duplicating events | `pos_receipts.fiscal_event_id` is UNIQUE (`2026_05_14_100005_…:64-65`); join unchanged this round. |
| Fabricated red-first (controls written after the fix, or green-at-control) | Ran the controls at `26b1371a6` in a detached scratch worktree on the same PG config — 8 failed with the exact pre-fix symptoms, incl. `Sequence #0 (hash)` and the context-less `sequence #1, link` coordinate. |
| Existing assertions weakened to manufacture green | The only edits to pre-existing tests are the 3 table-header/row widenings for the `Inspected` column and the ruling-authorized `assertFalse→assertTrue` flip. No assertion removed (`git diff 4fea617e0..HEAD -- apps/api/tests`). |
| A hidden Z-arm behaviour change smuggled in under R-1 | `ZReportHashService` diff = 0 lines over `4d5b0d3f5..HEAD`. |
| The evidence's GREEN counts are invented | All 7 reproduced exactly, by path, on PG. |

### Disposition
All four fix-before-merge items from the parent's STOP C ruling are closed and independently
verified: the fiscal arm now partitions per `(company_id, chain_context)` in the same shape the
append path and the Z verifier already use, the clean two-context v3 fixture verifies TRUE
(red-first proven by re-running the controls at the control commit), both per-context negatives
detect and attribute correctly, the pending-seal exclusion and its `Sequence #0` sub-defect are
gone with the untouched `FiscalStatusFilterTest` green again, the operator table reports Count and
Inspected separately so a snapshot tamper no longer renders as a receipt-lane verdict over zero,
and the false "no controller behavior changed" evidence line is struck with an honestly-labelled
coverage pin on the widened live endpoint. R-1 holds at zero Z diff lines; no row's hash shape,
no event class, and no device-authored fact moved. Four residuals remain, all P3 and all
reporting-honesty rather than fiscal-integrity: a false Z-row comment with a fabricated `Inspected`
value, a `chain_context`-less forensic log, an understated RED count in the evidence, and an
inherited `broken_at_sequence` coordinate that M2's mirror arm newly routes to. None of them can
hide a chain break or change a verdict, so none blocks the milestone; they should be swept in M3's
diff or in the wave's close-out.

VERDICT: ACCEPT

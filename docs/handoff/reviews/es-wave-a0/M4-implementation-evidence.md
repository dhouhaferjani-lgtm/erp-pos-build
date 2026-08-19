# M4 implementation evidence — ES-09 / ES-41 / ES-42

**Milestone slice for the reviewer: `f21a5f32653..59bb6913f`**

| Commit | What |
|---|---|
| `c0b850f2c` | Phase 0.1.47 — open M4, close M3b, sweep the five M3b round-1 findings, ticket the four residuals |
| `8cee85f70` | Phase 0.1.48 — ES-09 |
| `59bb6913f` | Phase 0.1.49 — ES-41 + ES-42 |

**Harness:** PostgreSQL via `apps/api/phpunit-pgsql.xml`, `DB_DATABASE=autoerp_es_wave_a0_test` on
`127.0.0.1:5432`, every file **by path**, never the full suite. `apps/api/vendor` is a real
directory in this worktree, so the runs execute THIS worktree's production code. New tests were also
run on the default (SQLite) driver.

**Three contracts, not conflated.** ES-09 is CORRECTNESS (nothing starts refusing; the after-state
is a SUCCESSFUL two-context append). ES-41 is TRIGGER PRESENCE (assert on PG, DOCUMENT the non-PG
gap). ES-42 is the only REFUSAL, and it is two-sided.

---

## 0. The M3b round-1 sweeps, discharged at the opening commit

The register listed five as owed at M4's opening commit and four as tickets, not work.

| Finding | Where | What landed |
|---|---|---|
| **F-1** | six sites citing `VerifyEventChainCommand.php:856` | `:856` is now a BLANK LINE; the predicate is at `:896`. All six now cite the **SYMBOL** — `VerifyEventChainCommand::reportQuarantineIncidents()`'s `whereNull('resolved_at')`. Sites: `routes.php`, `QuarantineIncidentResolutionService`, `QuarantineIncidentResolutionController`, `FiscalEventQuarantineResolutionTest` (×2, one of which quotes the contract verbatim and now carries an inline erratum), `M3b-implementation-evidence.md` (§2 ×2 + §4), the YAML M3b row. The register's ultimatum was that a FOURTH recurrence is a BLOCKER, so the addressing mode is changed rather than the address. |
| **F-2** | `ParseFailureResumeTest.php:299` citing `:705` | The byte-identity check is at `:727`; now cited by SYMBOL as `recoverSealedPayloadFromFrozenBytes()`'s `$roundTrip !== $canonicalBytes`. |
| **F-3** | four `resolved_by`/§8 claims | Corrected. `Nf525DataProvider::buildQuarantineSection()` selects `resolved_at` (`:1571`) and emits `resolved_at` (`:1603-1605`); `resolved_by` appears nowhere in that file. §8 publishes **that** an incident was adjudicated and **when**, never **by whom**. This makes 17-G's refusal rationale STRONGER: the raw column is the identity's only home, so an overwrite is unrecoverable. Sites: `QuarantineAlreadyResolvedException` docblock, test `:173`, the cross-tenant message, the 17-G message, plus an inline CONTRACT ERRATUM under the verbatim 17-G quote. |
| **F-7** | three absolute `\uXXXX` claims | Qualified to "escapes **OF U+0080+**", each naming U+2028/U+2029 as the exception (PHP escapes them even under `JSON_UNESCAPED_UNICODE`, so the canonical encoder DOES emit those and the guard recovers them). Sites: `VerifyEventChainCommand` (the one surviving 30 lines below its own N-2 correction), `ParseFailureResumeTest` ×2. **Byte-level check: `LC_ALL=C grep` for `\xe2\x80\xa8` / `\xe2\x80\xa9` in `VerifyEventChainCommand.php` returns 0** — no raw literal was introduced while writing about them. |
| **F-4** | no observability on the adjudication write | `Log::info('fiscal.quarantine.incident_resolved', [...])` in `QuarantineIncidentResolutionService::resolve()`, mirroring `QuarantineBestEffortParseController::logParseInvoked()`'s shape. Context assembled **inside** the transaction (the row is read under the lock) but **emitted after commit** — a log line for a rolled-back write would assert an adjudication that never happened. Reason capture is **log-only**: an optional `reason` on the request body (`sometimes|nullable|string|max:500`, backwards-compatible — the endpoint previously took no body), never persisted, because a reason COLUMN is a schema change outside 17-A…17-G. |

**Ticketed, not worked** (YAML `findings:` block, each citing `M3b-round1.md`): **F-5** duplicated
suppression literal, **F-6** LIKE-underscore superset, **F-8** `show()` 409, **F-9** the
`enqueue-resolved-event-projections` regression. **New owner question** filed as
`nf525-s8-resolved-by`: should §8 carry `resolved_by`?

**F-4 red-first:** removing the `Log::info` line and re-running the two new tests →
`1 failed, 1 passed`, failing on "at least 1 times but called 0 times". The second test (a refused
re-stamp emits NO second line) is **disclosed as a guard**, not red-first.
`FiscalEventQuarantineResolutionTest` moved **10/69 → 12/74**.

---

## 1. ES-09 — CORRECTNESS

### The defect, in two halves

Register row (`:132`): server-authored events resolved the chain head by `(tenant, terminal)` only.

**(a) Context-blind head resolution.** Both `resolveChainPlacement()` implementations read
`fiscal_events` ordered by `sequence_number` DESC with no `company_id` and no `chain_context`. On a
two-context terminal — **every** v3 terminal — the deepest chain's head wins regardless of context.
The resulting row **hashes correctly** and **clears the per-context UNIQUE**
(`(tenant_id, company_id, terminal_id, chain_context, sequence_number)`, enforced since
`2026_05_24_100000_add_chain_context_to_fiscal_events.php:20-31`), so it **INSERTs silently**,
leaving a numeric gap in one chain and a `previous_hash` pointing into the other.

**(b) Unconditional verdict.** Both stamped `integrity_status = Verified` /
`payload_parse_status = Parsed` without running `verifyLinkage` / `verifyClock`.

### What landed

| File | Change |
|---|---|
| `Fiscal/Application/Services/TerminalRegistrySnapshotService.php` | head read scoped by `tenant + company + terminal + chain_context`, matching `OutboxIngestor`'s prior-row read verbatim; `chain_context` written EXPLICITLY on the row; prior head RETURNED so the verdict can be derived; `assertAdmissible()` before persist; new `CHAIN_CONTEXT` const |
| `POS/Application/Services/VirtualAdminFiscalEventService.php` | same, at BOTH call sites (`appendAccountStatusChanged`, `appendDepositReceipt` share one resolver, so both carried the defect) |
| `Fiscal/Application/Services/ServerAuthoredChainPlacementVerifier.php` (new) | derives the verdict in `OutboxIngestor`'s own §7.2 priority order — hash → linkage → clock; linkage mirrors `verifyLinkage()` including the T19-B3 genesis-seed rule; clock delegates to the existing `ClockAnomalyDetector` |
| `Fiscal/Domain/Exceptions/ServerAuthoredChainPlacementException.php` (new) | the refusal |

**One verifier, not two copies.** M3b's F-5 is the duplicated-literal lesson; a chain-admissibility
rule copied into two modules drifts silently. `VirtualAdminFiscalEventService` already imports
`Fiscal\Application\Services\FiscalPayloadConstraintValidator`, so the POS→Fiscal direction is the
existing coupling, not a new one.

**Why the derivation THROWS rather than quarantining.** A device envelope is a fact the ledger must
preserve even when defective — hence the quarantine table. A server-authored event is being composed
right now, by us, inside a transaction, from state read under a row lock. Admitting it as
`Quarantined` would invent server-side quarantine semantics no projector expects; stamping it
`Verified` is the defect. Rolling back is the honest third option. **This is not a refusal
contract**: for a legitimate append the verdict derives to `Verified` and the write succeeds, which
is what the two-context regression proves.

**Scope, per the STOP-C ruling.** `ReceiptHashService` is **not** touched (M2, `bc692820a`), and the
end-to-end test still asserts the receipt-verification arm.

### Clause-by-clause against the brief's M4 row

| Requirement | Discharged by |
|---|---|
| scoped at **BOTH** named services | `TerminalRegistrySnapshotService` + `VirtualAdminFiscalEventService`; the VirtualAdmin arm is proven at **both** its live callers (`ACCOUNT_STATUS_CHANGED`, `DEPOSIT_RECEIPT`), which the register names |
| matching `OutboxIngestor`'s shape | four `where()`s in the same order on the same columns; not a third shape |
| before-fix RED on a **two-context** terminal | 5 of 6 tests red; each failure is "expected 3, stored 6" — the deeper `z_session` head winning |
| after-fix GREEN = the two-context append **SUCCEEDS** | `test_the_two_context_append_succeeds_end_to_end_and_both_chains_still_verify` asserts the row EXISTS, then `fiscal:verify-event-chain` exit 0 on `operational` AND on `z_session`, then `pos:verify-chains` exit 0 |
| no unconditional `Verified`/`Parsed` | `ServerAuthoredChainPlacementVerifier`; `Parsed` was already earned by the pre-persist payload gate that throws, so the missing half was integrity |
| a single-context fixture is not evidence | every fixture seeds BOTH contexts, with `z_session` **deliberately deeper** (5 vs 2) so the pre-fix query's winner is deterministic rather than a planner coin-flip between equal-depth heads |
| field-occurrence claim | **No production probe was run**, so no claim is made that live rows are corrupted. Register confidence stays CONFIRMED (defect) / SUSPECTED (field occurrence). |

### Red / green

Red-first was re-run with the FINAL test file against the two service files restored from
`f21a5f32653` (`git show` into place, then copied back; `git status` clean afterwards):

```text
BEFORE  Tests: 5 failed, 1 passed (29 assertions)
  ⨯ snapshot service resolves the head of its own chain context …      6 !== 3
  ⨯ virtual admin account status change resolves …                     6 !== 3
  ⨯ virtual admin deposit receipt resolves …                           6 !== 3
  ⨯ the two context append succeeds end to end …
        "CHAIN BREAK at sequence_number 6 … sequence_number gap — expected 3, stored 6"
  ⨯ a server authored append onto a clock rolled back head refuses …   (stamped Verified instead)
  ✓ a legitimate server authored append derives verified …             ← DISCLOSED GUARD

AFTER   Tests: 6 passed (38 assertions)
```

**Disclosure:** the sixth test was green before and after. It is a non-regression control on the
after-state (a legitimate append still derives `Verified`, and the stored hash is the hash of the
stored bytes), **not** a red-first case, and is labelled as such.

The M1 verifier reporting the defect **in its own words** — `sequence_number gap — expected 3,
stored 6` — is the cross-check that the fixture reproduces the register row rather than something
adjacent.

### Regression

`TerminalRegistrySnapshotTest` **19/71**, `AccountStatusChangedConcurrencyTest` **1/8**,
`DepositReceiptProjectionTest` **5/14** — all unchanged.

**Inherited reds**, each reproduced with the pre-fix service files swapped back in:
`AccountStatusChangedServerOnlyTest` 1 and `DepositReceiptAppendTest` 1 (an `assertSame` on array
key ORDER plus a one-hour local-TZ artifact on `event_time_device`), `TreasuryDepositBridgeTest` 9
(`ArgumentCountError` — `TreasuryDepositBridge::__construct()` expects 4, the test passes 3; neither
file is touched by this wave and no M4 code is on the stack).

---

## 2. ES-41 — TRIGGER PRESENCE

New: `apps/api/tests/Feature/Fiscal/ImmutabilityTriggerPresenceTest.php`. **No production change.**

### The CONFIRMED half — the driver gate, made VISIBLE

`test_immutability_enforcement_exists_on_postgres_and_is_documented_as_absent_elsewhere` is the one
test in the file that does **not** skip. On PG it asserts the trigger names read out of `pg_trigger`:
`enforce_receipt_immutability` on `pos_receipts`, and all three of
`fiscal_events_immutability_{delete,truncate,update}`. On any other driver it asserts the **absence**,
with the reason spelled out: both migrations `return` immediately when the driver is not `pgsql`, so
a green run on that driver proves nothing about production.

This is deliberate: a test that merely SKIPPED on SQLite would leave the gap invisible, which is the
state ES-41 is complaining about. The assertion is labelled a CHARACTERIZATION and says what to do
the day it fails (the gate has been closed — replace it with a real assertion).

**Not "fixed":** the triggers are not made to run on SQLite, and immutability enforcement is not
redesigned. R-5 forbids both.

### Presence is not enough — the triggers REFUSE

Three `[PG]` tests, each **two-sided** (the exception AND the unchanged row): a DELETE on
`fiscal_events` (`append-only ledger`), an UPDATE of `current_hash`
(`… may change (spec §3.3)` — asserted against the trigger's own message so an unrelated constraint
cannot masquerade as the refusal), and a totals change on a fiscalized receipt (`fiscally sealed`).

The refused statement runs inside a **nested transaction** so PG's aborted-transaction state rolls
back to the savepoint; without that, `RefreshDatabase`'s outer transaction makes the two-sided
re-read impossible and the test can only assert a status.

### The SUSPECTED half — VERIFIED IN CODE FIRST, then demonstrated, and NOT fixed

The brief required verification before assertion. **The sweep CONFIRMS it.** In the live definition
(`2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php`),
`prevent_receipt_modification()` opens its UPDATE handling with:

```sql
IF OLD.fiscal_status = 'pending_seal' AND NEW.fiscal_status = 'fiscalized' THEN
    RETURN NEW;
END IF;
```

No column comparisons at all — against seven guarded columns in the `fiscalized → voided` branch
immediately below, thirteen in the FK-cleanup branch, and fifteen in the §6.2 branch.

`test_pg_the_pending_seal_to_fiscalized_branch_has_no_column_guards_characterization` proves the
consequence is **reachable, not merely readable**: ONE `UPDATE` flips the status and rewrites
`subtotal` + `total`, and it commits. The amounts move **coherently** so `pos_receipts_totals` is
satisfied — that CHECK is an arithmetic invariant, not an immutability control, and it certifies a
forged pair happily. A CHECK violation would have proven nothing about the trigger.

**Why it is not fixed here:** the sealing write legitimately populates `fiscal_hash`,
`chain_sequence` and `posted_at` during exactly this transition, so guarding the branch means
deciding, per column, what a seal may author — an immutability redesign against a LIVE seal path,
which R-5 names as scope creep for this row. It is ticketed with this test as executable evidence,
so the next lane inherits a demonstration rather than a suspicion. The test says, in its own failure
message, that the day it goes red the branch has grown guards and it should be replaced by a refusal
test.

### Runs

```text
PG        Tests: 5 passed (10 assertions)
default   Tests: 4 skipped, 1 passed (1 assertion)   ← the 4 skips are LOUD [PG] skips
```

---

## 3. ES-42 — the two-sided REFUSAL

### THE FIRST CHECK — run first, asserted, PASSED. No STOP.

`test_es42_first_check_the_device_principal_holds_the_locked_permission` asserts, in this order:

1. `pos.operate_terminal` is **already seeded** (`RolesAndPermissionsSeeder.php:336`).
2. The **device principal holds it** — an ordinary tenant `User` carrying the seeded `cashier` role,
   which is what a POS operator holds in production (`:657`; `manager` carries it too at `:590`).
3. The non-operator principal genuinely **lacks** it, so the 403 below cannot pass for the wrong
   reason.

**Result: the device principal DOES hold `pos.operate_terminal`.** Gate
`ES-42-device-permission-grant` does **not** fire; the milestone proceeds. **No new permission, no
reseed, no permission migration.**

### What landed

`Fiscal/routes.php` — `Route::post('/pos/sync/fiscal-events', …)->middleware('can:pos.operate_terminal')`,
matching the sibling device surface `ZReportSyncController::sync()`'s
`Gate::authorize('pos.operate_terminal')`. Mechanical reuse, not a policy choice.

### The refusal half — two-sided

Red-first: with a **company-member** tenant user lacking the permission, the pre-gate endpoint
returned **200** and the envelope landed (`Expected response status code [403] but received 200`).
The non-operator is deliberately a full company member — without the membership,
`CompanyContextMiddleware` 403s it with `NO_COMPANY_ACCESS` before the permission gate is consulted,
and the test is green with **no gate at all**. That false green was found and removed before the
gate was written.

After: **403**, **zero** `fiscal_events` rows, **zero** `fiscal_event_quarantine` rows. Quarantining
an unauthorised caller's envelope would let any tenant token fill the operator's incident queue —
the register row's blast radius, restated.

### The device-success half — in the SAME diff

`test_es42_the_device_sync_path_still_succeeds_end_to_end_with_the_gate_in_place` drives the route
exactly as `apps/pos/src/lib/sync/syncService.ts:406-440` does — an authenticated device principal
POSTing an envelope batch — and asserts `results.0.stored: true` plus the row in `fiscal_events`.

Beyond that one test, the **fixture principal for the whole endpoint suite** is now a realistic
device principal (cashier role) instead of a bare permissionless `User::factory()` — which is
exactly the register row restated as a fixture. Every one of the 14 tests in
`FiscalEventIngestionEndpointTest` is therefore a device-success assertion.

The **four other suites** that drive the route end to end were granted the same EXISTING permission
on their acting principals (`Task33FiscalFullFlowVerificationTest`,
`TaskPhase2AccountPaymentFullFlowTest`, `TaskPhase3AccountChargeFullFlowTest`,
`ReceiptReturnRefactorV3Test`), each with the reason and the deploy note inline. A diff that shipped
only the 403 would be a production outage waiting for the next deploy.

### DEPLOY NOTE — nothing is owed

Reusing `pos.operate_terminal` requires **no permission migration, no seeder change, and no
`permission:cache-reset`**. Stated explicitly because "nothing owed" is itself a deploy fact.

### Runs

```text
FiscalEventIngestionEndpointTest      PG  14 passed (66 assertions)
TaskPhase3AccountChargeFullFlowTest   PG   8 passed (122 assertions)
TaskPhase2AccountPaymentFullFlowTest  PG   2 passed (48 assertions)
ReceiptReturnRefactorV3Test           PG   2 failed, 7 passed (253 assertions)   ← inherited 2/9
Task33FiscalFullFlowVerificationTest  PG   1 failed (14 assertions)              ← inherited
```

---

## 4. Verification summary

**New / changed test files, PG by path:**

```text
ServerAuthoredChainContextScopingTest   6 passed (38 assertions)     [new]
ImmutabilityTriggerPresenceTest         5 passed (10 assertions)     [new]
FiscalEventIngestionEndpointTest       14 passed (66 assertions)     [10 -> 14]
                                       25 passed (114 assertions) together
```

**Default driver:** `21 passed, 4 skipped (105 assertions)` — the 4 skips are the loud `[PG]` ones.

**Headline counts unchanged** (M3 / M3b), PG by path, run together:

```text
ParseFailureResumeTest                       27 / 209    ✓ unchanged
VerifyEventChainCommandTest                  34 / 138    ✓ unchanged
FiscalEventQuarantineResolutionTest          12 /  74    (was 10/69 — +2 F-4 tests)
ZSessionLifecycleQuarantineVisibilityTest     7 /  91    ✓ unchanged
DeadLetteredProjectionsControllerTest        12 /  33    ✓ unchanged
                                             92 / 545 together
```

**R-1 holds.** `ZReportHashService.php` is **0 lines** over `f21a5f32653..59bb6913f`.

**Static gates:** `pint --test` `{"result":"pass"}` and `phpstan` level 8 `[OK] No errors` on every
changed and new file. One PHPStan finding was fixed at the cause rather than suppressed: two
`bccomp()` calls received a plain `string` from the DB; a `decimalOf()` helper now validates with
`is_numeric()` and fails the test loudly on anything else, narrowing to `numeric-string` without a
cast, an `@var`, or a baseline entry.

**Standing rules.** Rule 19: no float touches money — the only arithmetic in the diff is `bcadd` /
`bccomp` on validated numeric strings in an ES-41 fixture; no currency-scale resolution is reachable
from any new path. Rule 20: no `onQueue`, so no `horizon.php` entry is owed; no migration; no
`CompanyContext` dependency introduced; no SQLite TEXT-timestamp boundary; no device shift
re-hydration. Rule 13: constructor injection with `private readonly` throughout, no `app()` in
production code. Rule 12: the route joins the existing middleware group and adds a `can:` gate
identical in shape to its four siblings. Rule 8: no event class renamed, restructured or deleted.
Rule 6: the one cross-module dependency (POS → Fiscal) already existed in that file.

**Fiscal source-of-truth:** nothing re-authors a device-signed fact. ES-09 changes where a
server-authored event LINKS and whether its verdict is EARNED; it changes no hash function, no
canonical byte shape, and no device-authored row. ES-41 adds no production code. ES-42 adds an
authorization gate and nothing else.

**Treasury lens — applied, stood down.** No GL entry, no journal, no payment, no drawer or cash
surface, no balance arithmetic, no currency-scale resolution. `DEPOSIT_RECEIPT` appears only as a
chain-placement call site; its money path is untouched (`DepositReceiptProjectionTest` 5/14
unchanged, and the 9 `TreasuryDepositBridgeTest` reds are its own constructor-arity mismatch,
inherited).

---

## 5. Open items handed to the reviewer

- **Field occurrence for ES-09 is still SUSPECTED.** No production probe was run and none is
  claimed. Whether live tenants carry mis-linked server-authored rows is a data question for a
  probe, not a code question; the defect itself is CONFIRMED and closed.
- **ES-41's SUSPECTED half is now CONFIRMED and characterized, not fixed.** It needs an owning lane:
  guarding the `pending_seal → fiscalized` branch is a per-column decision against a live seal path.
- **`nf525-s8-resolved-by`** (from F-3) is a new open owner question on the ledger.
- **Inherited reds carried forward**, none caused by M4 and each proven so by swapping the pre-M4
  file back in: `TreasuryDepositBridgeTest` 9 (constructor arity),
  `AccountStatusChangedServerOnlyTest` 1 + `DepositReceiptAppendTest` 1 (array key order + local TZ),
  `Task33FiscalFullFlowVerificationTest` 1 (PG `bytea` stream handle; green on the default driver),
  `ReceiptReturnRefactorV3Test` 2 of 9 (already in the ledger's blockers).

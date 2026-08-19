## M4 adversarial review — ES-09 (amended scope) / ES-41 / ES-42, round 1

**Reviewed:** `f21a5f32653..59bb6913f` (`c0b850f2c` opening sweeps, `8cee85f70` ES-09,
`59bb6913f` ES-41 + ES-42) plus the ledger commit `126fb457b` on top. HEAD verified
`126fb457b025dcec6b0244d9d3652d13b1f7f0fc`, branch `codex/es-wave-a0`, tree clean before and
after every control I ran.

**Contracts of record — judged separately, not conflated:**
the YAML M4 row (`docs/handoff/progress/es-wave-a0.progress.yaml:271-278`),
`ORCHESTRATOR-RULING-2026-08-19-m2-stop-c.md` (ES-09 = the two named services ONLY;
`ReceiptHashService` 0-line in this slice; the two-context test still asserts the receipt arm),
and `M3b-round1.md` (the five owed sweeps + the citation-drift ultimatum).

**Harness:** PostgreSQL, `apps/api/phpunit-pgsql.xml`, `DB_DATABASE=autoerp_es_wave_a0_test` on
`127.0.0.1:5432`, every file **by path**, never the full suite. `apps/api/vendor` is a real
directory in this worktree, so every run executed THIS worktree's production code.

---

### 1. Scope — measured, not accepted

`git diff --numstat f21a5f32653..126fb457b` — 4 production files changed, 2 new production files,
8 test files, 2 docs. No migration, no config, no front-end, no seeder, no `onQueue`
(the only two hits over the whole range are prose in the ledger and the evidence, so no
`config/horizon.php` entry is owed — rule 20 holds).

**Zero-line gates — all hold over the FULL range including the ledger commit** (`git diff --numstat`
returns no entry for any of them):

```text
0  apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/ZReportHashService.php    (R-1)
0  apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php          (:815 PARAM_STR untouched)
0  apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php              (STOP-C: M4 must not re-fix it)
0  apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php (F16-7)
0  apps/api/database/seeders/RolesAndPermissionsSeeder.php                      (ES-42 "no reseed")
```

`VerifyEventChainCommand.php`'s 8/2 diff is the F-7 comment qualification only — I read the whole
hunk: zero executable lines.

---

### 2. ES-09 — CORRECTNESS. Contract MET.

| Clause (YAML M4 row) | Verified at | Verdict |
|---|---|---|
| scoped at **BOTH** named services | `TerminalRegistrySnapshotService::resolveChainPlacement()` and `VirtualAdminFiscalEventService::resolveChainPlacement()` — four `where()`s, `tenant_id, company_id, terminal_id, chain_context`, same order | **MET** |
| matching `OutboxIngestor`'s shape | `OutboxIngestor.php:173-179` — I diffed the three literally: identical columns, identical order, identical `orderByDesc('sequence_number')`. Only the column projection differs (the services select the 3 columns the verifier consumes; the ingestor selects `*`) — and those 3 are exactly what `verifyLinkage`/`verifyClock` read | **MET** |
| `chain_context` written EXPLICITLY | both services now pass `'chain_context' => self::CHAIN_CONTEXT` into `create()`. Value-preserving: the column default is `'operational'` (`2026_05_24_100000_add_chain_context_to_fiscal_events.php:16`), and the CHECK admits `operational, z_session, training_operational, training_z_session` | **MET** |
| ONE shared verifier, not two copies | `ServerAuthoredChainPlacementVerifier` (new, Fiscal) injected into both; POS→Fiscal was already this file's coupling (`FiscalPayloadConstraintValidator`) | **MET** |
| refusal rolls back, zero rows | `assertAdmissible()` is called INSIDE `$this->db->transaction(...)` in all three call sites, after the `lockForUpdate()` read and BEFORE `FiscalEvent::create()`. Nothing writes before it in any of the three closures (all preceding statements are reads + payload validation), so a throw leaves **zero** rows. Asserted in the test by a row-count delta, and I reproduced it | **MET** |
| no unconditional `Verified`/`Parsed` | `Parsed` was already earned by the pre-persist payload gate that throws; the integrity half is now derived | **MET, with F-1 below on how much of the derivation can actually fire** |
| a single-context fixture is not evidence | every fixture seeds BOTH contexts, `z_session` deliberately deeper (5 vs 2) — I read `seedTwoContextTerminal()` and its self-asserting depth guard at `:427-440` | **MET** |
| the receipt-verification arm still asserted (STOP-C) | `pos:verify-chains --company` at test `:296-304`. **Not vacuous, and I checked why:** `ReceiptHashService::inspectFiscalEventsArm()` walks **every** `fiscal_events` row for the terminal (`LEFT JOIN pos_receipts`, no receipt filter) and partitions per `(company_id, chain_context)`, so the fixture's 8 rows are genuinely inspected; and `VerifyPosChainCommand`'s fail-closed contract makes a filter that matched nothing exit **non-zero**, so an exit-0 assertion cannot pass on an empty walk | **MET** |

**Red-first — re-run by me, not accepted.** I restored both service files from `f21a5f32653`
(`git checkout f21a5f32653 -- …`), ran the FINAL test file, and restored (`git status --porcelain`
empty afterwards):

```text
Tests: 5 failed, 1 passed (29 assertions)
  "Failed asserting that 6 is identical to 3"   × 3 (the deeper z_session head winning)
  CHAIN BREAK at sequence_number 6 … sequence_number gap — expected 3, stored 6
      previous_hash linkage mismatch — expected 78d136f6… stored 3aefb3fe…
  the clock-rollback test: stamped Verified instead of refusing
  ✓ a legitimate server authored append derives verified …   ← the DISCLOSED guard, green before and after
```

Character-for-character the evidence's recorded block, same five tests, same messages, and the one
test disclosed as a guard is exactly the one that stayed green. The disclosure is honest and it cost
something to make. After the restore: **6 passed (38 assertions)** — reproduced.

**The one thing I pushed on and it did not hold.** See F-1: I kept the verifier fully wired and
de-scoped ONLY the head read (dropped `company_id` + `chain_context` from
`TerminalRegistrySnapshotService::resolveChainPlacement()`). The mis-linked row was **still written,
still stamped `Verified`, no exception** — `1 failed, 1 passed`, failing on "6 is identical to 3".
So the verifier's linkage arm cannot see a mis-scoped head. The scoped read is the entire fix for
defect (a); the verifier is defect (b) only. Restored, tree clean.

---

### 3. ES-41 — TRIGGER PRESENCE. Contract MET, with a hollow assertion (F-2) and a missing ticket (F-3).

- **No production change** — confirmed by numstat: the only file in the ES-41 half is the new test.
- **PG presence read from `pg_trigger` by name**, `NOT t.tgisinternal`, ordered — `:314-328`.
  Asserts `enforce_receipt_immutability` on `pos_receipts` and all three
  `fiscal_events_immutability_{delete,truncate,update}`. Real, and it is an `assertSame` on the
  exact list, not a `contains`.
- **Two-sided refusals** — 2 of 3 (see F-4). The two that are two-sided use
  `DB::transaction()` nested inside `RefreshDatabase`'s outer transaction, which is the correct
  savepoint pattern for recovering from PG's aborted-transaction state; and the `current_hash`
  update asserts against the trigger's OWN message (`may change (spec §3.3)`) so an unrelated
  constraint cannot masquerade as the refusal. That is the right level of paranoia.
- **The SUSPECTED half was verified in code FIRST, then demonstrated, and NOT fixed** — which is
  exactly what the contract instructed ("`pending_seal->fiscalized` branch SUSPECTED — verify before
  asserting"; R-5 forbids the fix). I read the live function definition in
  `2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php`: the branch is an
  unconditional `RETURN NEW` with no column comparisons. The characterization test proves
  reachability at runtime (one `UPDATE` flips the status and rewrites `subtotal`+`total`, coherently
  so `pos_receipts_totals` is satisfied — correctly reasoned: that CHECK is an arithmetic invariant,
  not an immutability control). The test carries its own "delete me when I go red" instruction. This
  is the right shape for a documented-not-fixed residual. **What is missing is the ticket itself —
  F-3.**
- **Runs reproduced:** PG `5 passed (10 assertions)`; default driver `4 skipped, 1 passed
  (1 assertion)`, the 4 skips loud `[PG]` ones.

---

### 4. ES-42 — the two-sided REFUSAL. Contract MET.

- **THE FIRST CHECK genuinely ran first and passed.** `pos.operate_terminal` is seeded
  (`RolesAndPermissionsSeeder.php:336` — I read the line, it is in the permission list), the
  `manager` role block starts at `:534` and carries it at `:590`, the `cashier` block starts at
  `:639` and carries it at `:657`. All three citations are exact. The gate
  `ES-42-device-permission-grant` correctly does not fire.
- **Not a policy invention.** `pos.operate_terminal` already gates the sibling DEVICE sync surfaces:
  `ZReportSyncController.php:61` and `VoucherSyncController.php:48,75,100,126` all
  `Gate::authorize('pos.operate_terminal')`. **This is the strongest deploy-safety argument in the
  diff and the evidence undersells it:** any principal that already syncs Z reports or vouchers
  necessarily already holds this permission, so the gate cannot lock out a device that was otherwise
  functional.
- **The FALSE-GREEN story is true — I reproduced it.** I removed `->middleware('can:pos.operate_terminal')`
  from `Fiscal/routes.php` and re-ran the file: **`1 failed, 13 passed` — "Expected response status
  code [403] but received 200"** on exactly the refusal test. So the 403 IS attributable to the new
  gate and not to `CompanyContextMiddleware`: the non-operator's `UserCompanyMembership` makes it
  past company context and lands the envelope when the gate is absent. Restored; tree clean.
  (Residual F-7: the standing assertion is status-only.)
- **Persists NOTHING** — both `fiscal_events` and `fiscal_event_quarantine` counts asserted at 0,
  with the right rationale (quarantining an unauthorised caller's envelope would let any tenant token
  fill the operator's incident queue).
- **Device-success half in the SAME diff** — a named end-to-end test plus the whole suite's fixture
  principal upgraded to a real `cashier`. Independently corroborated: `Task33FiscalFullFlowVerificationTest`
  reaches all 14 assertions and fails only at `:155` on a PG `bytea` stream handle — i.e. the POST
  through the gated route succeeded and the ingested row was read back.
- **Token abilities are not a hidden false green.** POS logins mint `['tenant:<uuid>', 'pos:*']`
  (`AuthController.php:173-176,306-310`), not `['*']`, while `Sanctum::actingAs()` defaults to `['*']`.
  I checked whether that matters: there is no `Gate::before`, no `tokenCan()` call, and no
  `ability`/`abilities` middleware anywhere on these routes, so `can:` resolves purely through Spatie
  permissions and the test's ability set is not doing hidden work.
- **DEPLOY: nothing owed** — verified, not accepted: `RolesAndPermissionsSeeder.php` is 0-line over
  the range, no permission migration, no `permission:cache-reset`. (Blast-radius gap: F-6.)

---

### 5. The five M3b sweeps — all discharged, and the ultimatum is NOT tripped

| Finding | Discharged | Evidence |
|---|---|---|
| **F-1** (`:856` × 6 sites) | YES, by SYMBOL | `reportQuarantineIncidents()` is now cited symbolically at `routes.php:61`, `QuarantineIncidentResolutionController.php:22`, `QuarantineIncidentResolutionService.php:24`, `FiscalEventQuarantineResolutionTest.php:41` and `:306`. The symbol exists at `VerifyEventChainCommand.php:894` |
| **F-2** (`:705`) | YES, by SYMBOL | `recoverSealedPayloadFromFrozenBytes()`'s `$roundTrip !== $canonicalBytes` at `ParseFailureResumeTest.php:299-300`; the symbol exists at `VerifyEventChainCommand.php:695` |
| **F-3** (`resolved_by`/§8) | YES | `QuarantineAlreadyResolvedException.php:20-26` now states the claim IS FALSE and names what §8 actually publishes; owner question `nf525-s8-resolved-by` filed at ledger `:51` |
| **F-7** (absolute `\uXXXX`) | YES | qualified to "OF U+0080+" at `VerifyEventChainCommand.php:717-723` and `ParseFailureResumeTest.php:310, :404` |
| **F-4** (no observability) | YES | `Log::info('fiscal.quarantine.incident_resolved', …)` at `QuarantineIncidentResolutionService.php:159`, context assembled under the lock but emitted AFTER the transaction returns — the right call, a log line for a rolled-back write would assert an adjudication that never happened |

**The citation-drift ultimatum.** I grepped every `VerifyEventChainCommand.php:<line>` citation in
`apps/api` and `docs/handoff`. Exactly **one** survives in code:
`FiscalEventQuarantineResolutionTest.php:303` — and it is inside a **verbatim block quote of the
approved contract**, immediately followed by an explicit `CITATION CORRECTION (M3b round 1, F-1)`
erratum at `:305-308`. Quoting a contract accurately and erratum-ing it below is the correct
handling, not a recurrence. Every other hit is a frozen historical review document. **No fourth
recurrence. The blocker does not fire.**

---

### 6. Verification I ran myself

**PG, by path — every claimed count reproduces exactly:**

```text
ServerAuthoredChainContextScopingTest    6 passed (38 assertions)     [claimed 6/38   ✓]
ImmutabilityTriggerPresenceTest          5 passed (10 assertions)     [claimed 5/10   ✓]
FiscalEventIngestionEndpointTest        14 passed (66 assertions)     [claimed 14/66  ✓]
TerminalRegistrySnapshotTest            19 passed (71 assertions)     [claimed 19/71  ✓]
VerifyEventChainCommandTest  +  FiscalEventQuarantineResolutionTest  +  ParseFailureResumeTest
                                        73 passed (421 assertions)    [34/138 + 12/74 + 27/209 ✓]
default driver, ImmutabilityTriggerPresenceTest   4 skipped, 1 passed (1 assertion)  ✓
```

**Controls (each restored, `git status --porcelain` empty after):**
1. ES-09 red-first — pre-M4 service files swapped in → `5 failed, 1 passed (29 assertions)`, messages
   identical to the evidence, guard green. **Reproduced.**
2. ES-09 verifier-blindness — head read de-scoped, verifier intact → row still written, still
   `Verified`, no throw. **F-1.**
3. ES-42 red-first — gate removed → `1 failed, 13 passed`, "received 200" on the refusal test.
   **Reproduced, and it is the gate that produces the 403.**
4. Inherited red — `Task33FiscalFullFlowVerificationTest` `1 failed (14 assertions)` at `:155`,
   a `bytea` stream-handle `assertSame` in the test itself, with all pre-ingestion assertions passed.
   **Inherited, not caused by ES-42.**

**Static gates, re-run by me:** `pint --test` on all 8 changed/new files → `{"result":"pass"}`;
`phpstan` level 8 on the 4 new/changed production files → `[OK] No errors`.

---

### 7. Standing checks

- **Rule 19 / precision — N/A, grepped not assumed.** Zero `(float)`, `floatval`, `number_format`,
  `parseFloat`, or bare `getScale()` in any added line under `apps/api/app/`. The only arithmetic in
  the delta is `bcadd`/`bccomp` on strings narrowed by `decimalOf()`'s `is_numeric()` guard in an
  ES-41 fixture — and that helper failing the test loudly rather than casting is the right call. No
  currency-scale resolution is reachable from any new path, so the no-arg `getScale()` trap is not in
  play.
- **Rule 20 — holds.** No `onQueue`, no migration, no `CompanyContext` dependency introduced (the
  ES-41 test in fact `clear()`s it at `:83`), no SQLite TEXT-timestamp boundary (the clock comparison
  is Carbon-side, not a SQL TEXT compare), no device shift re-hydration.
- **Rule 13 — holds.** Both new classes use constructor injection with `private readonly`; no `app()`
  in any added production line.
- **Rule 12 — holds.** The gated route stays inside the existing
  `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` group and adds a `can:` shaped
  identically to its three siblings.
- **Rule 8 — holds.** No event class renamed, restructured or deleted.
- **Rule 9 — nuance.** `chain_context` is a string const, not an enum. That matches the module's
  existing prior art (`FiscalEventEnvelope.php:63`, `FiscalPayloadConstraintValidator.php:452`,
  `VerifyEventChainCommand.php:640`), so it is within contract — but the const is now duplicated
  across two modules, which is the same duplicated-literal failure mode (M3b F-5) the diff cites as
  its own reason for extracting the verifier. Minor, recorded.
- **Fiscal source-of-truth — holds.** Nothing re-authors a device-signed fact. ES-09 changes where a
  SERVER-authored event links and whether its verdict is earned; no hash function, no canonical byte
  shape, no device-authored row is touched. ES-41 adds no production code. ES-42 adds an
  authorization gate and nothing else.
- **Test quality.** Zero `assertTrue(true)`. Nothing under test is mocked — ES-09 drives the real
  `fiscal:verify-event-chain` and `pos:verify-chains`, ES-42 drives the real HTTP route through the
  real ingestor. `RefreshDatabase` + real models + `RolesAndPermissionsSeeder` throughout. Every new
  file ran on PG, so the SQLite-masking risk is retired for this delta — with the one exception that
  is F-2, where the SQLite branch asserts a constant.
- **Treasury lens — APPLIED, and it does NOT stand fully down.** No GL entry, no journal, no payment,
  no drawer or cash surface, no balance arithmetic, no currency-scale resolution — that much of the
  evidence's statement is accurate and I verified it. But the lens is not empty here: the ES-09
  refusal is thrown inside `VirtualAdminFiscalEventService::appendDepositReceipt()`, which
  `RecordCustomerDepositService` calls **inside its own sealing transaction**, so a new hard-failure
  mode now exists on a customer money-in flow. See F-5. `DepositReceiptProjectionTest` and the
  deposit money path itself are untouched.

---

### 8. Bypass attempts

| Attempt | Refuted by |
|---|---|
| ES-09 red-first transcript fabricated | Reproduced independently by swapping both pre-M4 service files: 5 failed / 1 passed / 29 assertions, same messages, disclosed guard green |
| The two-context fixture passes vacuously | Fixture self-asserts the 2-vs-5 depth asymmetry at `:427-440`; the pre-fix winner is deterministic, and the M1 verifier reports the defect in its own words |
| The receipt-arm assertion is empty (no `pos_receipts` in the fixture) | `ReceiptHashService::inspectFiscalEventsArm()` walks ALL terminal `fiscal_events` rows, not just receipt-linked ones; and `VerifyPosChainCommand` exits NON-zero when a filter matches nothing, so exit-0 cannot be bought by an empty walk |
| "Matching OutboxIngestor verbatim" is prose | Diffed all three query builders literally: same four columns, same order, same `orderByDesc`. Only the select-list differs, and it carries exactly the 3 fields the mirrored checks consume |
| The refusal leaves a partial row | All three call sites place `assertAdmissible()` inside `db->transaction()` before any write; verified by reading each closure, and by the count-delta assertion which I re-ran |
| ES-42's 403 comes from `CompanyContextMiddleware`, not the gate | Removing the gate yields **200** with the envelope landing — the non-operator is a full company member by construction |
| A new permission smuggled in as "existing" | `RolesAndPermissionsSeeder.php` 0-line; `pos.operate_terminal` confirmed at `:336`, in `manager` at `:590` and `cashier` at `:657`; already used by 6 sibling device surfaces |
| The device test is green only because `Sanctum::actingAs` grants `['*']` | No `Gate::before`, no `tokenCan()`, no ability middleware anywhere on the path — token abilities do not participate in `can:` here |
| STOP-C violated by re-fixing `ReceiptHashService` | 0-line over the full range |
| Green bought by weakening an existing test | Five headline/regression suites reproduce their exact counts, including `TerminalRegistrySnapshotTest` 19/71 which is the suite most exposed to the new throw |
| Citation drift recurred a fourth time | One surviving `:856`, inside a verbatim contract quote with an explicit erratum directly below it |

---

### Findings — eight, none blocking

**F-1 (P2) — `ServerAuthoredChainPlacementVerifier.php:132-137` asserts a capability the code does
not have, and I disproved it at runtime.** The docblock says the mirrored linkage rule *"is the check
that makes a mis-scoped head resolution visible: a head read from the WRONG `chain_context` makes
this context's genuinely-first event look like a continuation and vice versa."* It cannot be. The
caller derives `$sequenceNumber` and `$previousHash` **from the same `$prior` row** it then hands to
the verifier (`TerminalRegistrySnapshotService.php` and `VirtualAdminFiscalEventService.php`,
`resolveChainPlacement()` returns the triple), so `deriveLinkageFailure()` compares a value against
its own source and is a tautology; likewise the hash arm re-verifies `$currentHash` against the same
bytes and the same provider that produced it one line earlier. **Only the clock arm can fire.**
Control: with the verifier fully wired and only the head read de-scoped, the mis-linked row was
written with `integrity_status = Verified` and no exception. This is not a behavioural defect — the
two arms are legitimate structural guards against a future divergence between `resolveChainPlacement()`
and its caller, and defect (a) is genuinely closed by the scoped read — but the sentence is falsified
prose in production code, the same claim-truth class as M3b's F-3/F-7. **Fix:** state that the hash and
linkage arms are structural guards that cannot fire while the caller derives its placement from the
same row, and that the defense against mis-scoping is the scoped read itself.

**F-2 (P2) — the ES-41 non-PG "absence" assertion is unfalsifiable, so the one test that does not
skip asserts nothing about the database.** `ImmutabilityTriggerPresenceTest::triggerNamesOn()`
(`:314-318`) returns `[]` **unconditionally when the driver is not `pgsql`, before running any
query**; the non-PG branch then asserts `assertSame([], $this->triggerNamesOn('pos_receipts'))`
(`:144-149`) — `[]` against a hard-coded `[]`. The docblock's promise (*"It fails the day someone
makes the triggers run on this driver — at which point the gap is closed and this expectation should
be replaced"*) can never be kept. The SQLite run confirms the shape: `4 skipped, 1 passed
(1 assertion)`. The contract clause is met only formally, and ES-41's whole purpose is making an
invisible gap visible. Second-order: the PG branch asserts BOTH tables, the non-PG branch asserts
only `pos_receipts`. **Fix:** on sqlite query `SELECT name FROM sqlite_master WHERE type='trigger'
AND tbl_name = ?` and assert absence on both tables.

**F-3 (P2) — ES-41's CONFIRMED seal-branch gap is claimed "ticketed" and is not.** The evidence
(§2) and the ledger both state it is *"ticketed WITH executable evidence for an owning lane"*. It is
not: `docs/handoff/progress/es-wave-a0.progress.yaml`'s `findings:` block holds exactly four entries
(F-5, F-6, F-8, F-9 — all inherited from M3b) and no file was added under
`docs/superpowers/tickets/`. The only record is a test docblock plus a comment inside the M4
milestone block, which is not where this wave keeps its residuals. This is the **highest-consequence
residual in the milestone** — on live PostgreSQL a single `UPDATE` can rewrite `subtotal` and `total`
while flipping `pending_seal → fiscalized`, demonstrated by a passing test — and M5 is the A0 exit
statement, after which nobody re-reads a milestone comment. **Fix, owed at M5's opening commit and
NON-WAIVABLE before the A0 exit statement:** a `findings:` entry naming
`prevent_receipt_modification()` in
`2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php`, citing
`ImmutabilityTriggerPresenceTest::test_pg_the_pending_seal_to_fiscalized_branch_has_no_column_guards_characterization`
as the executable evidence, and naming an owning lane.

**F-4 (P3) — "each refusal two-sided" is true for 2 of 3.**
`test_pg_the_fiscal_events_trigger_refuses_a_delete` (`:157-167`) asserts the exception ONLY —
`expectException` + the DELETE, no re-read — because it does not use the nested-transaction pattern
its two siblings use at `:179-192` and `:213-225`. The evidence and the ledger both say "each
refusal two-sided, the exception AND the unchanged row". **Fix:** wrap the DELETE the same way and
re-read the row, or narrow the claim to the two updates.

**F-5 (P3, treasury lens) — a new hard-failure mode on a customer money-in flow, unmapped at the HTTP
boundary, and disposition-asymmetric with the ingestor it claims to mirror.**
`ServerAuthoredChainPlacementException extends RuntimeException` and is thrown from
`VirtualAdminFiscalEventService::appendDepositReceipt()`, which `RecordCustomerDepositService` calls
**inside its sealing transaction** — so a `time_anomaly` verdict aborts a customer deposit as an
unmapped 500. `OutboxIngestor` treats the identical condition as accept-with-`time_anomaly`
(`ClockAnomalyDetector`'s own docblock: *"Out-of-tolerance is accepted, not blocked"*), so "derives
the verdict in `OutboxIngestor`'s own order" is true of the ORDER and not of the DISPOSITION. I
consider the throw defensible for a server-composed event and it is argued honestly in the exception
docblock; the probability is low because that terminal's chain carries only server-authored times.
But the asymmetry is undocumented and there is no boundary mapping. **Fix:** say so in the exception
docblock, and map it at the deposit boundary to a structured refusal rather than a 500.

**F-6 (P3) — the ES-42 blast-radius audit names five PHPUnit suites and misses two non-PHPUnit
callers of the gated route.** `apps/web/e2e/smoke/treasury-phase5b-reconciliation.smoke.ts:738` and
`apps/web/e2e/money-campaign/statement-support.ts:563` both POST to `/pos/sync/fiscal-events`. They
authenticate via `/auth/login` as the demo owner; if that account carries the seeded `admin` role it
holds every permission and nothing is owed — but that was neither stated nor verified, and I cannot
verify it without standing up the E2E stack. These scripts are launch-program evidence, so a silent
403 there costs an evidence run. **Fix:** name them in the deploy note, or add
`pos.operate_terminal` to the permission `arrayContaining` preamble the smoke already performs at
`:372`.

**F-7 (P3) — the standing ES-42 refusal test is status-only, so its attribution can rot.**
`test_es42_an_authenticated_tenant_user_without_the_permission_is_refused_and_persists_nothing`
asserts `assertStatus(403)` and nothing about which layer produced it. Today the attribution is
carried by a fixture comment and by a red-first run (which I reproduced), but the file's own history
is that this exact test was once green for the wrong reason. **Fix:** assert the error code/body so
a `NO_COMPANY_ACCESS` 403 can never impersonate the gate's 403.

**F-8 (P3, precision note — not a defect) — `TerminalRegistrySnapshotService` has ZERO production
callers.** `grep -rn "TerminalRegistrySnapshotService" apps/api --include='*.php'` outside `tests/`
and the class itself returns nothing; `emitInitialSnapshot()` is referenced only by
`TerminalRegistrySnapshotTest`, `VerifyEventChainCommandTest` and the new ES-09 test. This materially
narrows the still-SUSPECTED field-occurrence question the milestone hands forward: in the field only
`VirtualAdminFiscalEventService`'s two live callers can have written mis-linked rows, and only onto a
`VirtualAdmin` terminal — which carries no `z_session` chain, so the realistic field blast radius may
be nil. Worth writing down before anyone commissions a production probe on the back of ES-09.

**Minor** — `CHAIN_CONTEXT = 'operational'` is now declared twice, once per module, in the same diff
whose stated design principle is *"one verifier, not two copies … a chain-admissibility rule copied
into two modules drifts silently"*. Same failure mode, smaller blast radius (the value is also the DB
default and is CHECK-constrained). Within contract; recorded for whoever introduces a `ChainContext`
enum.

---

### Disposition

Three separate contracts, and the executor kept them separate — the evidence's insistence on that is
borne out by the code: ES-09 is a correctness change whose after-state is a SUCCESSFUL append, ES-41
adds no production line at all, and ES-42 is the only refusal. The things that are easiest to fake
are the things that held up hardest under my own controls. I reproduced the ES-09 red block
character-for-character from the pre-M4 service files, including the fact that the sixth test — which
the evidence had already disclosed as a guard rather than a red-first case — stays green in both
states. I reproduced the ES-42 false-green story by deleting the middleware and watching a
company-member non-operator get a **200** with the envelope landing, which is the only way to know
that the 403 belongs to the new gate and not to `CompanyContextMiddleware`. The STOP-C ruling is
honoured on both halves: `ReceiptHashService` is 0-line, and the receipt-verification arm assertion
turned out to be substantive rather than ceremonial — I traced `inspectFiscalEventsArm()` to confirm
it walks every terminal event, not only receipt-linked ones, and confirmed that
`VerifyPosChainCommand` fails closed on an empty filter so exit-0 cannot be bought cheaply. Every
claimed count reproduced exactly, on PostgreSQL, by path. The citation-drift ultimatum is discharged
in the strongest available way: the addressing mode was changed to symbols rather than the address
re-swept, and the single surviving line number is a verbatim contract quote with an erratum stapled
underneath it.

Where it is thinner than it reads. The ES-09 verifier is doing less than its own docblock says: two
of its three arms are tautologies against the value that produced them, and I proved it by de-scoping
only the head read and watching a mis-linked row sail through with a `Verified` stamp. That is not a
regression — the scoped read is the real fix and the guards are worth keeping — but the sentence
claiming the linkage arm exposes mis-scoping is false, and this wave has now produced that class of
sentence in three consecutive milestones. ES-41's one non-skipping test asserts a constant against a
helper that hard-codes the answer on the very driver it claims to characterize, which is the same
species of invisible gap the row exists to expose. And the milestone's most consequential discovery —
that a live PostgreSQL trigger branch lets a seal rewrite `subtotal` and `total` in the same statement
— is described as "ticketed" while the ledger's `findings:` block contains four entries, none of them
this one. None of the eight findings changes a byte of behaviour or a fiscal fact, and none of them
is worth a fix round on its own; all eight are sweepable in one commit, which is this wave's
established pattern. F-3 is the one I would not let slip past M5's opening commit, because M5 is the
exit statement and an unticketed confirmed money-column gap does not survive the end of a wave.

**Owed at M5's opening commit, verifiable in one grep each:** F-1 (rewrite the verifier docblock's
linkage claim), F-2 (make the non-PG absence assertion query the database), F-3 (**NON-WAIVABLE** —
a `findings:` entry for the confirmed seal-branch gap), F-4 (make the DELETE refusal two-sided or
narrow the claim), F-7 (assert the refusal's error code). **Owed as notes, not work:** F-5's
disposition asymmetry and boundary mapping, F-6's two E2E callers, F-8's narrowing of the ES-09 field
probe, and the duplicated `CHAIN_CONTEXT` literal.

VERDICT: ACCEPT

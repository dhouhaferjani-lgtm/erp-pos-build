Tree confirmed clean — I modified nothing in the reviewed worktree.

# M1 adversarial review — ES wave A0, round 1

**Range reviewed:** `df85d43f4..09e263c7d` (16 commits). Production diff = 3 files in `app/Modules/Fiscal/Infrastructure/Commands/` + 1 provider line. **Lenses:** fiscal-pos (live), treasury (applies only narrowly — see below).

**What I ran myself:** the full diff; `VerifyEventChainCommandTest` + `VerifyEventChainFleetCommandTest` on real PG 5432 (**37 passed, 149 assertions** — the green claim reproduces); `tools/deptrac-ratchet.php` at HEAD **and** at `BASE_SHA` in a disposable worktree; two `StrictCanonicalParser` probes through the real container.

**Treasury lens:** M1 writes nothing — no money, quantity, GL, payment or partial-write path is touched, so rule 19 / atomicity are N/A by construction (I checked: no `bcformat`, no float, no scale resolver owed). The lens applies only through consequence: `fiscal:verify-event-chain` is the control the launch program's E-7 treasury evidence leans on, and findings 1, 2 and 4 change what "green" means for it. `DEPOSIT_RECEIPT` (finding 1) is the partner-money event.

---

### 1. P1 — CONFIRMED — the new `payload`/sealed-coordinate checks turn the verifier permanently RED on hash-valid, sanctioned **server-authored** rows

`apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:403-420,431-439` · `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:234-248,288` · `apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:93-107,141,251-265,299` · `apps/api/app/Modules/Fiscal/Application/Services/StrictCanonicalParser.php:77-93,612-615`

`walkChain()` now runs `StrictCanonicalParser::parse()` on **every** row and emits a `CHAIN BREAK` when it fails. Both production services that author `fiscal_events` server-side build a canonical envelope **missing `chain_context`** — 14 keys against the parser's required 15-key set (`grep -c chain_context` on `TerminalRegistrySnapshotService.php` returns **0**; the same is true in `VirtualAdminFiscalEventService.php`). The row itself gets `chain_context` from the column default (`…create_fiscal_events_table.php:41`), so the divergence is invisible until now.

I proved the parse failure through the real container:

```
$ php artisan tinker --execute='... parse(<VirtualAdmin-shaped envelope>, ACCOUNT_STATUS_CHANGED)'
ok=false reason=envelope_field_missing:chain_context
```

Failure scenario: a tenant records one partner deposit (`DEPOSIT_RECEIPT`) or one account-status change, or the server emits its routine `TERMINAL_REGISTRY_SNAPSHOT`. `fiscal:verify-event-chain` then emits **two** incidents per such row — `sealed coordinates could not be derived from canonical_bytes (envelope_field_missing:chain_context)` and `payload does not semantically match canonical_bytes` (both services write `payload_parse_status = Parsed`) — and exits 1. `canonical_bytes` is frozen by the Task-8 trigger, so **there is no remediation**: that chain is red forever, and the new fleet driver aggregates it to a permanent non-zero for the entire tenant. This is the exact inversion the lane exists to prevent — the trusted control now cries wolf on healthy rows, which is what makes a real break unnoticeable.

Every M1 fixture hand-builds its bytes via `canonicalEnvelope()`, which **does** include `chain_context` — so the suite is green while the command is red on real data. R-9's "build the fixture through the production path where possible" was honoured for T-a only; that is precisely why this was missed.

This needs a disposition, not a silent ship: either narrow the check so it cannot red a class of rows it can't distinguish from tampering, or fix the two envelope builders (scope signal → owner ruling), or record it as a STOP/scope-signal with a fixture driven through one of those production paths.

### 2. P1 — CONFIRMED — T-b has no GREEN control, and the M0 two-context v3 fixture provably cannot verify green

`apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:701,711,776` · brief `:472` ("RED against T-a and T-b, GREEN on the clean equivalents. **Both** runs are part of the diff")

M1 reshaped `seedValidChain()`/`seedTamperedChain()` to real canonical envelopes but left `seedV3FiscalFixture()` on M0's placeholder bytes `'{"event":"v3_operational_receipt","sequence_number":1}'` with `event_type = SALE_RECEIPT`. Proved through the container:

```
ok=false reason=event_type_mismatch:expected=SALE_RECEIPT,envelope=null
```

So the clean two-context v3 fixture exits **1** under the hardened verifier. There is no test that runs `fiscal:verify-event-chain` green over it, and none can be written without reshaping the fixture. Failure scenario: A1's stated entry criterion is *"A0's verifiers merged and green on a seeded v3 tenant"* — the fixture A0 is supposed to hand it fails A0's own verifier for a reason unrelated to the defect it models. M0's evidence (`M0-preflight-evidence.md:79-83`) discloses a *different* blocker on this fixture (`ReceiptHashService` context flattening); this one is new, is M1's, and is undisclosed.

### 3. P2 — CONFIRMED — the T-b red run is contaminated: the fixture trips the new checks as well as the one it models

`apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:762-782` (`seedWrongContextPreviousHashTamper`), assertion at `:317-320`

All three rows in that fixture carry unparseable bytes (finding 2), so the run emits three `sealed coordinates could not be derived` incidents alongside the intended `previous_hash linkage mismatch`. The test's assertion is specific enough not to be vacuous, and the evidence file honestly attributes T-b to the pre-existing link check — but M0's own rule (brief `:446`) is that a helper must produce **the** intended divergence "or a 'red' run later may be red for the wrong reason". Failure scenario: M4 reuses this helper for ES-09's before/after pair and reads a red that four different checks are producing. Same root cause as finding 2; one fixture fix closes both.

### 4. P2 — CONFIRMED — a quarantined row is now permanently un-greenable, with no product remediation path, and this is recorded nowhere

`VerifyEventChainCommand.php:389-396` · `ParseFailureResolutionService.php:172-175` · immutability trigger `2026_05_14_100002_…:200-233`

The `integrity_status !== Verified` check is correct and brief-mandated (snapshot `:131`). But I traced every `quarantined → verified` writer in production: there is exactly **one**, `ParseFailureResolutionService`, and the trigger's Step-5 guard restricts it to `canonical_exception_class = 'canonical_parse_failure'`. So a `time_anomaly` / `sequence_gap` / `canonical_hash_mismatch` quarantine stays `quarantined` forever → chain red forever → fleet red forever for that tenant. And a `canonical_parse_failure` that *is* resolved then trips the payload check instead (that is T-a, by design) — so such a row has **no** state in which the chain verifies. Failure scenario: the launch checklist's "`fiscal:verify-event-chain` green on every terminal" gate (`OWNER-CHECKLIST-CONSOLIDATED-2026-08-01.md:72`) becomes unsatisfiable on any tenant with one historical quarantine, and the always-red fleet run loses all signal. Not M1's to fix — but it must be a recorded ticket/scope-signal in the tree before merge; `es-wave-a0.progress.yaml` has `blockers: []` and the evidence file says nothing.

### 5. P3 — CONFIRMED — the fleet driver's per-tenant **binding** is never exercised; all 11 tests run in single-schema compat mode

`apps/api/phpunit-pgsql.xml` (`TENANCY_DB_PER_TENANT=false force="true"`) · `VerifyEventChainFleetCommandTest.php` (no `config(['tenancy_resolver.db_per_tenant' => true])` anywhere; I grepped all three fiscal test files)

The config file itself documents the opt-in (`"DB-per-tenant fail-closed tests opt in via config([...])"`), and the whole reason `--tenant` was converted to a binding was the 42P01 regression under db-per-tenant. The fleet command's distinctive move — `$this->call()` on the sub-command *after* `tenancy()->end()`, so the child re-binds — is exactly the thing compat mode cannot exercise. Failure scenario: on real staging the child command re-initialises tenancy per chain and nothing in CI would have caught a mis-sequenced bind. One db-per-tenant-enabled test would close it.

### 6. P3 — PLAUSIBLE — the walk is unbounded and now runs a hand-rolled tokenizer per row, at fleet scale for the first time

`VerifyEventChainCommand.php:317-346,403`

`->get()` loads every row for a chain — including `canonical_bytes` and `payload` — with no chunking (pre-existing), and each row now also runs `StrictCanonicalParser` (new). The fleet driver invokes this for every `(terminal_id, chain_context)` in every manifest tenant with no `--from-sequence` batching. I have no volume data, so this is PLAUSIBLE, not confirmed: on a terminal with a year of receipts this could OOM or run long enough that the launch verifier is impractical to run fleet-wide.

### 7. P3 — CONFIRMED — the fleet reports success over zero work when directory and manifest are both empty

`VerifyEventChainFleetCommand.php:186-202`

An empty manifest against an empty directory prints `fleet chain verification completed: 0 tenant(s), 0 chain(s), no failures` and exits 0. Every non-degenerate shape is handled correctly (missing/unknown/no-data all fail loudly — I verified each in the passing tests), so this is the one residual "verified over zero" hole in an otherwise well-built driver.

### 8. P3 — CONFIRMED — `es-wave-a0.progress.yaml` M1 `commit:` is stale

`docs/handoff/progress/es-wave-a0.progress.yaml:56` records `f6d83fb55`; HEAD is `09e263c7d`. Deliverable item 2 requires the YAML to reflect reality.

### 9. P3 — CONFIRMED — `deptrac` is not in the evidence, though it does not regress

House rules (brief `:617`) require it. I ran `tools/deptrac-ratchet.php` at HEAD and at `BASE_SHA`: **identical** (99 → 116, same three categories) — pre-existing FAIL, **zero delta from this diff**. No finding on the code; the evidence file should say so rather than omit the gate.

---

### What M1 got right (verified, not accepted on assertion)

- All four new checks are **individually load-bearing**. I re-derived each isolated fixture and confirmed it trips exactly one check: contiguity (`:294-307` — seq-3 row's sealed coords and link both match), payload (`:137-147` — only `reason` differs), `integrity_status` (`:185-195`), sealed coordinates (`:205-222`, parsed **and** pending variants). The disable-proof table is consistent with the code.
- T-a is built through the **real** `ParseFailureResolutionService::resolve()` (`ParseFailureResumeTest.php:702-735`) with self-assertions on the frozen bytes — the strong form R-9 asked for.
- R-3 is met in full: manifest-only, no invented identity, actor gate re-applied under each tenant's binding **before** enumeration (proved by the loud-refusal tests asserting `doesntExpectOutputToContain('enumerated')`), missing/unknown/unauthorised all loud with non-zero aggregate, enumeration source stated and tested.
- Actor gate and tenant binding are a **pure extraction** into `AuthorizedFiscalChainCommand` — I diffed the moved block line-for-line against the original; the tenant-qualified lookup, `can()`, and the `finally` restore are byte-identical.
- Exit-code contract preserved (0/1/2, including the transient-2 path). No `app()`, no migration, no new queue, no event class, `declare(strict_types=1)` throughout. Architecture-test delta: zero (I confirm the eleven-class baseline is unrelated).

### Bypasses I attempted that FAILED

1. Tried to break the `payload_parse_status = Parsed` gate as an evasion (rewrite `payload`, downgrade the status). **Refuted** by the trigger: Step 2 forbids `parsed → *` and Step 4 makes `payload` write-once (`2026_05_14_100002_…:139-198`). The gate is sound on PG.
2. Tried to show the sequence-contiguity check false-reds on real chains because sequence numbers are shared across `chain_context`. **Refuted**: `OutboxIngestor` scopes the prior head by `(tenant, company, terminal, chain_context)` (`:173-179`) and requires `prior+1` (`:500`), and the raw insert conflicts on `(…, chain_context, sequence_number)` (`:871`). Per-context contiguity is the real contract; the check correctly exposes `TerminalRegistrySnapshotService::resolveChainPlacement()`'s context-blind head (ES-09, M4's row).
3. Tried to false-red `event_time_device` / `business_date` on timezone drift. **Refuted**: column is `timestampTz` (`…100001:39`), `config('app.timezone') = 'UTC'`, and the comparison normalises via `->utc()`.
4. Tried to false-red the payload semantic comparison on JSONB key reordering. **Refuted**: `normalizeJsonObject()` ksorts recursively, the canonical grammar is integer-only (no float round-trip), and `OutboxIngestor` stores `$parseResult->payload` verbatim (`:761-763`).
5. Tried to falsify the pasted green runs. **Corroborated** — I ran both files on a disposable PG database of my own: 37 passed, 149 assertions.
6. Tried to pin the deptrac FAIL on this diff. **Refuted** — identical at `BASE_SHA`.
7. Did **not** run the full PHPUnit suite (forbidden), and did not write any file into the reviewed worktree; `git status --porcelain` is empty.

**Disposition.** The command itself is well-built and the four checks are genuinely load-bearing — the milestone is close. But finding 1 means the hardened verifier is red on healthy production data with no remediation, and finding 2 means the wave's own two-context v3 fixture — A1's stated foundation — cannot pass it. Both are exactly the failure class R-11 exists to prevent, and neither is disclosed.

VERDICT: CHANGES-REQUIRED

## M1 adversarial merge-gate review — ES wave A0, round 4

**Range:** `df85d43f4..30903e69c` (25 commits). Production diff = 3 command classes + 1 provider line. **Lenses:** fiscal-pos (live), treasury (narrow — see below).

**What I ran myself** (disposable PG `autoerp_es_a0_m1r4`, created and dropped; worktree untouched, `git status --porcelain` empty at start and end):
- `VerifyEventChainCommandTest.php` → **33 passed, 136 assertions**
- `VerifyEventChainFleetCommandTest.php` + `VerifyEventChainFleetCommandDbPerTenantTest.php` → **13 passed, 66 assertions** (the db-per-tenant leg ran, it did not skip)
- `ParseFailureResumeTest.php` → **21 passed, 110 assertions**
- `pint --test` on all 7 touched PHP files → `pass`; `phpstan` (level 8, live-DB env) on the 4 production paths → **No errors**

**Treasury lens:** M1 writes no money, quantity, GL or payment path — rule 19 is N/A by construction (I grepped every added production line for `app(`, `(float)`, `floatval`, `number_format`, `bc*`, `round(` — zero hits). The lens applies only through consequence: `DEPOSIT_RECEIPT` (the partner-money event) is one of the three types the new legacy carve-out exempts from `chain_context` sealing, and finding 1 changes what a green fleet run means for the launch program's E-7 evidence.

**Round-1 findings, re-verified against code (not accepted on assertion):** #1 closed by the sanctioned-legacy-envelope path with three real negative controls (`VerifyEventChainCommand.php:513-619`; tests `:157-317`) — I confirmed `TerminalRegistrySnapshotService.php:232-249` and `VirtualAdminFiscalEventService.php:252-266` author exactly the 14 keys in `LEGACY_SERVER_ENVELOPE_KEYS`, and that `StrictCanonicalParser::ENVELOPE_KEYS` (`:77-93`) is that set plus `chain_context`, so the gate `failureReason === 'envelope_field_missing:chain_context'` admits only a single-key omission. #2/#3 closed (`seedV3FiscalFixture` now builds real envelopes, `:910-1016`; T-b now pins exactly one `CHAIN BREAK` line, `:884-908`). #4/#6 closed as durable tickets FEV-OPS-01/02. #5 closed by a real physical-database leg. #7 closed (`VerifyEventChainFleetCommand.php:67-71`). #9 closed in evidence. All four checks are new (the base `VerifyEventChainCommand` has no parser call, no `integrity_status` check, no gap check) and the actor gate + tenant binding extraction into `AuthorizedFiscalChainCommand` is behaviour-identical to `df85d43f4:…:198-260`.

---

### 1. P2 — CONFIRMED — the fleet driver's enumeration source makes orphan quarantine incidents invisible, and reports "no failures" over them

`VerifyEventChainFleetCommand.php:104-111` · `VerifyEventChainCommand.php:769-803` · `OutboxIngestor.php:290-341`

The fleet enumerates targets **only** from `SELECT DISTINCT terminal_id, chain_context FROM fiscal_events`. `reportQuarantineIncidents()` — the command's only window onto `fiscal_event_quarantine` — runs solely for an enumerated pair. But `quarantineMalformedEnvelope()` inserts a quarantine row with **no corresponding `fiscal_events` row** (`:341`; the whole point of that branch is that the row cannot be admitted to `fiscal_events`).

**Failure scenario:** a device's first `z_session` envelope is malformed. One `fiscal_event_quarantine` row lands at `(terminal T, z_session)`; that pair has zero `fiscal_events` rows. The fleet enumerates only `(T, operational)`, prints `TENANT X: VERIFIED 1 chain(s)` and `fleet chain verification completed: 1 tenant(s), 1 chain(s), no failures`, and exits **0** — while an unresolved incident sits in the tenant's quarantine table. The single-chain command *would* report it (`--chain-context=z_session` reaches `reportQuarantineIncidents()` even over an empty walk), so this is a fleet-coverage hole specifically.

This is the "verified over zero rows" class R-3 exists to kill, and it is undisclosed: `test_manifest_tenant_with_no_fiscal_event_pairs_fails_loudly…` (`VerifyEventChainFleetCommandTest.php:102`) closes the hole at **tenant** granularity only, and neither FEV-OPS-01 (un-greenable quarantines) nor FEV-OPS-02 (bounding/resume) covers it.

The brief pins the enumeration source, so **either remedy is acceptable** and both are small: union the distinct `(terminal_id, chain_context)` pairs from `fiscal_event_quarantine` into the target set (purely additive — touches neither the actor gate nor the tenant binding, and the child command already handles a zero-event walk), **or** state the gap explicitly in the milestone report and open a third FEV-OPS row. What is not acceptable is shipping it silent.

### 2. P3 — CONFIRMED — the fleet collapses the child's transient exit 2 into aggregate exit 1

`VerifyEventChainFleetCommand.php:179-181, 192-200`

`$exit !== self::SUCCESS` maps a child's documented **2 (transient — operator re-runs)** onto the wrapper's `self::FAILURE` (1, "a chain is broken"). The brief only requires a non-zero aggregate, and the per-tenant reason line does distinguish the transient authorization case (`:136-138`), so the single-chain 0/1/2 contract is intact. But an automation layer reading only the fleet's exit code cannot tell "retry" from "a chain broke" — which is the distinction the exit-code contract was written for.

### 3. P3 — CONFIRMED — the progress YAML's M1 `commit:` is stale again

`docs/handoff/progress/es-wave-a0.progress.yaml:56` records `36b7b39b4` — the *"Record second M1 review tool failure"* commit. HEAD is `30903e69c`; the last behavioural commit is `71f306c86`. Round-1 finding 8 recurred verbatim.

### 4. P3 — CONFIRMED — the legacy carve-out is framed as historical but applies to every future row those three services author

`VerifyEventChainCommand.php:506-512` ("the one historical production shape", "the historical row"), `:603-619`

The carve-out is keyed on envelope **shape**, not on a date, and M1 correctly did not touch the two envelope builders (rule 4). So `TERMINAL_REGISTRY_SNAPSHOT`, `ACCOUNT_STATUS_CHANGED` and `DEPOSIT_RECEIPT` will keep producing 14-key envelopes indefinitely, and `chain_context` will remain permanently unsealed for them — including the partner-money `DEPOSIT_RECEIPT`. Nothing in the tree records this as ongoing rather than legacy, and there is no ticket to seal `chain_context` in those builders. (I checked the integrity consequence: flipping such a row's `chain_context` column *is* still caught — the row leaves the sanctioned set, strict parse fails, and the contiguity check reds the gap it leaves behind. So this is a framing/ticket gap, not an integrity hole.)

### 5. P3 — CONFIRMED — the single-chain command still exits 0 with "chain verified — … 0 events walked" on an existing terminal with no events in the requested context

`VerifyEventChainCommand.php:284-294` · `VerifyEventChainCommandTest.php:460`

Pre-existing, explicitly tested, honest about the count, and outside M1's four named checks — and quarantine incidents for that context *are* still reported, so it is not blind. Recording it because it is the shape the launch checklist ticks as PASS, and the evidence does not mention it.

### 6. P3 — PLAUSIBLE — the db-per-tenant fleet leg mutates the shared central directory without `RefreshDatabase`

`VerifyEventChainFleetCommandDbPerTenantTest.php:41-78`

It creates a physical `tenant_<uuid>` database plus `tenants`/`domains` rows and removes them in `tearDown()` on a best-effort `try/catch(Throwable){}`. A hard death (fatal, SIGKILL, OOM) leaves an orphan database and directory rows behind that no later `RefreshDatabase` run reclaims. Test-infra only; no production impact. I did not reproduce a leak — the leg ran clean in my run.

---

### Bypasses I attempted that FAILED

1. Tried to slip a tampered row through the legacy carve-out. **Refuted:** `array_diff` in `StrictCanonicalParser:611-615` emits *all* missing keys, so the exact-string gate `'envelope_field_missing:chain_context'` admits only a single-key omission; the key set is then re-checked exactly (`:531-535`), the event type is restricted to three server-only types, and six frozen/status columns must all match (`:603-619`). `test_legacy_server_compatibility_rejects_an_extra_envelope_field` proves the extra-field direction closed.
2. Tried to hide an ES-06 payload rewrite behind the carve-out on a `DEPOSIT_RECEIPT`. **Refuted:** both carve-out exits return the *sealed* payload (`:551`, `:554`), so the semantic comparison at `:465` still runs against frozen bytes. A parse failure for any reason other than the one whitelisted `DEPOSIT_RECEIPT` mismatch falls back to `return $strict` — fail-closed.
3. Tried to make `sealedCoordinateMismatches()`'s new `continue`-on-missing-key (`:685-687`) skip a real coordinate. **Refuted:** all 14 compared coordinates are in `ENVELOPE_KEYS`, and strict parse guarantees the full 15-key set on every non-legacy row — the `continue` reaches only `chain_context` on the carve-out path, where it genuinely was never sealed.
4. Tried to show the dropped `->utc()` on the legacy `event_time_device` branch (`:668-670`) is a false-green. **Refuted:** both server builders format `$now` without `->utc()` (`TerminalRegistrySnapshotService.php:237`, `VirtualAdminFiscalEventService.php:254`), so the branch reproduces the authored representation exactly; under `app.timezone=UTC` it is identical to the non-legacy branch, and under a non-UTC app timezone the *unbranched* form would be the false **red**.
5. Tried to find state leakage across the fleet's per-tenant `forEachTenantNarrowed()` calls. **Refuted:** `TenantScopedCommand.php` resets `visitedTenantIds`/`skippedTenantIds` at the top of every call, and the child command is a separate instance.
6. Tried to make a db-probe skip pass silently in the fleet loop. **Refuted:** a skip leaves the aggregate SUCCESS but `failIfTenantFilterUnvisited()` returns non-null, and `VerifyEventChainFleetCommand.php:133` treats that as the tenant's FAILED.
7. Tried to falsify the green claim. **Corroborated** — 67 tests / 312 assertions reproduced on a disposable database of my own.
8. Did **not** run the full PHPUnit suite (forbidden), and wrote nothing into the reviewed worktree.

**Disposition.** The command is well-built, the four checks are individually load-bearing and I re-derived each isolated fixture, R-3 is met in full including the physical-binding leg, and every round-1 finding is genuinely closed. The one thing standing between this and ACCEPT is finding 1: the fleet driver — the milestone's headline deliverable, whose stated purpose is that nothing is ever silently skipped — can return `no failures` over a tenant carrying an unresolved quarantine incident, and that is undisclosed anywhere in the tree. Bounded remedy, either shape.

VERDICT: CHANGES-REQUIRED

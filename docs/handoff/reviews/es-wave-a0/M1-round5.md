# M1 adversarial merge-gate review — ES wave A0, round 5

**Range:** `df85d43f4..09b498749` (28 commits). **Production diff:** 3 command classes + 1 provider line — unchanged since round 4 (`71f306c86` is still the last behavioural commit; `ee86d86f4`/`09b498749` are docs-only). **Lenses:** fiscal-pos (live), treasury (narrow — see below).

**What I ran myself** (disposable PG `autoerp_es_a0_m1r5`, created and dropped; `git status --porcelain` empty at start and end; no full suite):
- `VerifyEventChainCommandTest.php` → **33 passed, 136 assertions**
- `VerifyEventChainFleetCommandTest.php` + `VerifyEventChainFleetCommandDbPerTenantTest.php` → **13 passed, 66 assertions** (the physical db-per-tenant leg ran, it did not skip)
- `ParseFailureResumeTest.php` → **21 passed, 110 assertions**
- `tests/Architecture/ConsoleCommandTenantContextTest.php` → 1 failed, and I reproduced the **identical eleven-class list** the evidence pastes (`M1-implementation-evidence.md:395-408`); **no fiscal command appears in it** — pre-existing, delta zero, honestly reported.
- `pint --test` on all 8 touched PHP files → `pass`; `phpstan` level 8 (live-DB env) on the 4 production paths → **No errors**

**Independent verification of the brief's M1 row, not accepted on assertion:**
- **payload ↔ canonical_bytes** — `VerifyEventChainCommand.php:464-472` (semantic) and `:446-453` (unparseable-bytes arm). T-a is demonstrated **through the real production path**: `ParseFailureResumeTest.php:701-736` drives `ParseFailureResolutionService::resolve()`, self-asserts the divergence it creates (frozen bytes + hash unchanged, payload replaced), and `:198-207` shows the verifier red on it. That is the strongest available form of the R-9 fixture.
- **integrity_status** — `:422-429`, red fixture `:748`, paired green control.
- **sealed coordinates** — `:455-462`/`:655-701`, red on both a parsed and a *pending* row (`:772`, `:799`) with green controls (`:824`).
- **sequence contiguity** — `:396-405`, red at `:846` on a fixture whose hash linkage is deliberately valid.
- **T-b** — `:884-904` pins **exactly one** `CHAIN BREAK` line and asserts the other four checks stayed silent.
- **Per-check discrimination** — the evidence performs the brief's own antidote (`:120-250`): each check disabled in turn with `false &&`, its fixture shown going **green**, restored. That proves each fixture trips only its own check, which is what "individually load-bearing" means.
- **Fleet driver (R-3)** — manifest-only tenant→actor map (`VerifyEventChainFleetCommand.php:214-253`), gate run **inside** the binding before any target query (`:99-102`), enumeration exactly as the brief pins it (`:104-111`), delegation to the unchanged single-chain command (`:172-177`), loud missing/unknown/no-data/unauthorised reporting with non-zero aggregate (`:74-81`, `:133-160`, `:192-200`), no new identity model. `AuthorizedFiscalChainCommand.php:29-69` is behaviour-identical to `df85d43f4:VerifyEventChainCommand.php:198-260` (I diffed them line by line).

**False-red completeness sweep (my own, not in the evidence).** I enumerated every production writer of `fiscal_events.canonical_bytes`: `OutboxIngestor` (which parses with `StrictCanonicalParser`, `:122`, and quarantines any parse failure — `deriveIntegrity()` `:757-767` makes `verified` + `failed`-parse unreachable), plus the two direct-insert server services. Those author **exactly** the 14 keys of `LEGACY_SERVER_ENVELOPE_KEYS` (`TerminalRegistrySnapshotService.php:234-247`, `VirtualAdminFiscalEventService.php:251-264`) and **exactly** three event types. So the carve-out's coverage is exact and complete, and M1 introduces no false red on any real envelope shape.

**Treasury lens:** M1 writes no money, quantity, GL or payment path — rule 19 is N/A by construction (grep of every added production line for `app(`, `(float)`, `floatval`, `number_format`, `bc*`, `round(` → zero hits). No migration, no `onQueue`, so the horizon-coverage and unattended-migration rules are vacuous here. The lens applies only through consequence: `DEPOSIT_RECEIPT` (the partner-money event) is one of the three carve-out types, and its validation path is the tightest part of the diff (see bypass 2).

**Round-4 findings, re-checked against the tree:** #1 closed by the remedy the round-4 reviewer explicitly sanctioned — the gap is stated in the milestone report (`M1-implementation-evidence.md:745-770`) *and* opened as `FEV-OPS-03` with an acceptance contract naming the exact first-malformed-`z_session` scenario. #2/#4/#5/#6 → `FEV-OPS-04`/`05`/`06`/`07`, each with an owner and a falsifiable contract. #3 closed: `es-wave-a0.progress.yaml:56` now reads `71f306c86`, which I confirmed by `git show --stat` is the last commit touching `apps/`.

---

### 1. P3 — CONFIRMED — the legacy `event_time_device` branch rests on a premise the two authoring services contradict

`VerifyEventChainCommand.php:657`, `:662-670`

The comment justifies skipping `->utc()` on the legacy path by claiming "the legacy server services passed a UTC Carbon to a timestampTz connection whose session timezone may be non-UTC" and that the row therefore "retains the sealed wall time with the connection offset". Both services in fact pin UTC at the source — `TerminalRegistrySnapshotService.php:233` and `VirtualAdminFiscalEventService.php:65,205` are all `Carbon::now('UTC')` — and `config/app.php:68` is `'UTC'`, so the branched and unbranched forms are identical in every configuration this codebase supports.

**I could not demonstrate harm.** I re-ran `test_verifies_the_existing_server_authored_snapshot_envelope`, `test_verifies_the_existing_virtual_admin_server_authored_envelopes` and `test_passes_on_a_valid_seeded_chain` against a database whose default `timezone` I set to `Europe/Paris` — **all three stayed green**. And it cannot produce a false green either: a tampered `event_time_device` column shifts both renderings equally against a fixed sealed string, so the mismatch survives. This is a justification/dead-branch defect, not a detection hole. Recording it because round-4 bypass #4 reasoned to the opposite conclusion from the same lines, and a future reader will inherit that reasoning.

### 2. P3 — CONFIRMED — the operability register does not cover the rows M1's headline check turns permanently red

`ParseFailureResumeTest.php:198-207` · `FEV-OPS-01` (`docs/superpowers/tickets/2026-08-12-…:8-25`)

A `canonical_parse_failure` row has, by construction, `canonical_bytes` the strict parser cannot parse. After `ParseFailureResolutionService::resolve()` succeeds, that row is `integrity_status=verified`, `payload_parse_status=parsed` — and M1's new checks now red it forever. The diff's own test proves this. So **every terminal on which the parse-failure resolution feature has ever been used goes permanently red**, in both the single-chain command and the fleet, with no remediation until D-8.

This is the brief's *intended* deliverable (R-9: "A0 ships the detection"; D-8 owns the workflow), so it is not a defect. But `FEV-OPS-01` is scoped to rows that are *still quarantined* ("the immutability trigger restricts it to `canonical_parse_failure` rows … a single historical quarantine can keep one chain red forever") — it does not name the *successfully resolved* class, which is a different shape with a different owner. Five smaller consequences got tickets in round 4's fix; this larger one did not. One line in the register pointing at D-8 would close it.

### 3. P3 — CONFIRMED — `fix_rounds: 4` at HEAD understates the round count

`docs/handoff/progress/es-wave-a0.progress.yaml:54`

Cosmetic; the controller owns the field and the `commit:`/`verdict:` fields are correct. Noted only so the count is not read as evidence of how many gates this milestone actually consumed.

---

### Bypasses I attempted that FAILED

1. **Slip a multi-key-tampered envelope through the carve-out gate.** *Refuted:* `StrictCanonicalParser.php:610-613` joins **all** missing keys with `implode(',')`, so the exact-string gate `'envelope_field_missing:chain_context'` (`VerifyEventChainCommand.php:516`) admits a single-key omission only; the key set is then re-compared exactly (`:531-535`).
2. **Hide a corrupt sealed field behind the `DEPOSIT_RECEIPT` sub-carve-out.** *Refuted, and this is tighter than it looks:* `StrictCanonicalParser::parse()` runs `validateEnvelopeShape()` (`:199`) **before** `validateChainContext()` (`:215`). So a synthetic parse that fails with `envelope_chain_context_event_type_mismatch` has already passed full envelope key/type/format validation, and `validateLegacyDepositReceipt()` (`:564-601`) then re-runs precisely the remaining steps — DTO, key set, per-event constraints. The carve-out reconstructs the whole parser minus the allowlist check, nothing less.
3. **Escape the payload check through either carve-out exit.** *Refuted:* both exits (`:551`, `:554`) return the **sealed** payload, so the comparison at `:465` runs against frozen bytes; any other parse failure falls through to `return $strict` — fail-closed.
4. **Reach a real coordinate through the `continue`-on-missing-key at `:685-687`.** *Refuted:* strict parse guarantees all 15 `ENVELOPE_KEYS`; the `continue` reaches only `chain_context` on the carve-out path, where it genuinely was never sealed. Flipping a carve-out row's `chain_context` column pushes it out of `isSanctionedLegacyServerRow()` (`:611`) → strict parse fails → break.
5. **Find a production envelope shape the carve-out misses (false red).** *Refuted* by the writer sweep above — three services, three types, exact key sets.
6. **Find a null-column fatal that the try/catch would convert into a misleading exit.** *Refuted:* `2026_05_14_100001_create_fiscal_events_table.php:39-40,60-61,78,86` make `event_time_device`, `business_date`, `previous_hash`, `current_hash`, `integrity_status`, `payload_parse_status` all NOT NULL, so `:427`, `:659` and `:670` cannot dereference null.
7. **Find cross-target state leakage in the fleet loop** (same child instance is reused across `$this->call()`s). *Refuted:* `lastWalkedCount` is reset at the top of every `walkChain()` (`:379`), and `TenantScopedCommand.php:263-264` resets the visited/skipped lists on every `forEachTenantNarrowed()`; tenancy is ended in a `finally` (`:342-346`), so the child rebinds cleanly. `test_enumerates_distinct_terminal_and_context_pairs_present_in_fiscal_events` exercises the multi-target path.
8. **Falsify the green claim.** *Corroborated* — 67 tests / 312 assertions reproduced on a disposable database of my own, plus the architecture-test baseline reproduced exactly.
9. Did **not** run the full PHPUnit suite (forbidden), and wrote nothing into the reviewed worktree.

---

**Disposition.** The production code is unchanged since round 4; what round 5 owed was the round-4 remedy, and it was delivered in the shape the round-4 reviewer explicitly sanctioned — the enumeration gap stated in the milestone report *and* ticketed as `FEV-OPS-03` with a falsifiable acceptance contract, alongside four more disclosures for the P3s. I did not take round 4's closures on trust: I re-derived the four checks, the carve-out's tightness, the actor-gate equivalence, and I added a writer sweep and a null-column sweep of my own, both of which corroborate the implementation. Every check the brief names is present, individually load-bearing by the brief's own antidote, and demonstrated red — with T-a built through the production resolution path rather than by hand. My two substantive findings are P3 notes: one is a mis-justified branch with no demonstrated impact in either direction, the other is a missing line in a disclosure register for behaviour the brief mandates. Neither blocks the merge.

VERDICT: ACCEPT

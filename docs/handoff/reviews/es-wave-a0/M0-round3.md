I ran the diff, re-derived the citations myself, ran `pint --test` and the contract digest, and read the surrounding production code. Register below.

---

## M0 review register — ES wave A0, round 3

**Range:** `df85d43f4..2e01e01ac` (7 commits; round 3 = `bffba974a` + the state commit `2e01e01ac`). Diff = 2 test files + 1 new test trait + 1 YAML + 2 artifacts. **No production file is touched** — `git diff --stat` confirms the "no production code" constraint holds for the whole range.

### Round-2 findings — closure verified by me, not accepted on assertion

| # | Round-2 finding | Round-3 state |
|---|---|---|
| 1 | P2 — context-flattening disclosed as behaviour, never escalated as an unregistered production defect; M2/A1 consequence unstated | **CLOSED.** New section `M0-preflight-evidence.md:81-83` names it an **UNREGISTERED PRODUCTION DEFECT / scope signal**, carries the `Nf525DataProvider` caller and the "every v3 terminal" reach, and states the consequence for M2's green-on-clean clause and A1's entry criterion. Every citation in it re-derived exact by me: `ReceiptHashService.php:236-239` (no `chain_context` predicate), `:245` (`$expectedPrevious = $terminal->genesis_seed`), `:304` (`$expectedPrevious = $storedCurrentHash`), `Nf525DataProvider.php:343-345` (LIVE verify-chains endpoint note) and `:398` (`verifyTerminalChainFiscalArm` delegation), snapshot `:132` ("every v3 terminal: `z_session` + `operational`"). |
| 2 | P3 — fixture test pins the defect as *expected* behaviour | **CLOSED.** `VerifyEventChainCommandTest.php:469-475` — comment marks it characterization of an unfixed defect pointing at the artifact, and the message now reads "Characterization only: … reports a **false** linkage failure." |
| 3 | P3 — `stringifyCanonicalBytes()` triplicated | **CLOSED as scoped.** Extracted to `apps/api/tests/Traits/ReadsCanonicalBytes.php:7-30`; both test classes `use` it and neither still declares a copy (grep: only call sites at `VerifyEventChainCommandTest.php:587,666,667` and `ParseFailureResumeTest.php:694,705`). See P3-2 below for the residual. |

**Independent citation re-derivation (brief `:576` antidote — two of my own choosing, none used in rounds 1–2):** `TerminalRegistrySnapshotService.php:443-464` — `resolveChainPlacement()` filters the prior head by `tenant_id` + `terminal_id` only, no `company_id`, no `chain_context`; `:284`/`:288` stamp `IntegrityStatus::Verified` / `PayloadParseStatus::Parsed` unconditionally — **exact**. `VerifyEventChainCommand.php:198-260` — `verifyBoundTenant()` with the tenant-scoped actor lookup, `can('fiscal.events.verify_chain')` at `:251`, registrar restore in `finally` at `:259-261` — **exact**.

**Standing checks.** Rule 19: the only money in the diff is `insertProjectedReceipt()`'s string literals `'10.000'/'0.000'` at TND scale (`VerifyEventChainCommandTest.php:710-713`); no float, no `parseFloat`-analog, and no scale resolver is owed (test-local fixture literals, not production emission). Red-first: N/A — M0 is fixtures-only with no behavioural change; its substitute (self-asserting fixtures) is present and non-vacuous. Constructor injection: `$this->app->make()` appears only in test bodies; rule 13 binds production, of which there is none. No migrations, no new queues, no user-facing strings → i18n/horizon checks N/A. PHPStan does not analyse `tests/` (`phpstan.neon` `paths: app/`), so the trait's `self::fail()` cannot break the level-8 gate. **Treasury lens: the only treasury-adjacent surface in this diff is the receipt money columns above — no payment, GL, instrument or partial-write path is touched. Lens does not otherwise apply.** Fiscal-pos lens is the live one and is covered by the findings and bypasses below.

**M0's own acceptance items, verified individually:** contract digest — I re-ran it myself, `04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540`, **MATCH**; `df85d43f4` is an ancestor of HEAD; v3 two-context fixture with a hash-mirrored projected receipt (`:492-555`, contract test `:443`); T-a (`ParseFailureResumeTest.php:691`, drives the real `ParseFailureResolutionService::resolve()`, asserts frozen `['a'=>2]` vs corrected payload, `current_hash` still hashes the frozen bytes); T-b (`:561`); T-c (`:595`); ES-07 sub-claim table with 2 STAND / 1 WITHDRAWN and anchors I re-derived (`VerifyPosChainCommand.php:304` `whereNull('fiscal_event_id')`, `:310-315` zero-count `is_valid: true`, `:341` duplicate carve-out); citation sweep `unresolved = 0` — see bypass 1, it survives a completeness check, not just a spot check. `pint --test` on the three touched files: I ran it, `{"result":"pass"}`. Tree clean.

---

### 1. P3 — CONFIRMED — T-c's helper still pins *today's verifier being green* as its contract, the same trap round 2 closed one file-section away
`apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:637-641`

Round 3 fixed the wording on the v3-fixture test but left the identical pattern in `seedReceiptMirrorTamper()`: after inserting the mismatching receipt it asserts `assertTrue($receiptHashService->verifyTerminalChain($terminal), 'The current receipt verifier **must remain green** when T-c adds only the missing mirror divergence.')`. Failure scenario: M2's mandate is to add the `fiscal_hash` ↔ `current_hash` mirror as a **new arm**, and the brief leaves the mechanism to the implementer (`:487-489`). If they place it inside `ReceiptHashService::verifyTerminalChain()` — one of the two locations R-2 names as currently lacking the comparison — this shared M0 helper fails on its own internal assertion before the M2 test can even assert the command goes red; the message tells them green is the required contract, nudging them to route the check elsewhere to keep the fixture alive. The load-bearing divergence assertions (`assertNotSame($mirror->event_hash, $mirror->receipt_hash)` at `:668`, the `count === 1` mismatch join at `:672-680`) are unaffected, so the fixture still does its job. A one-line "characterization of the current absence — M2 will legitimately flip this" comment closes it, same as finding 2 of round 2.

### 2. P3 — CONFIRMED, no action wanted — the trait halves the duplication but the fixture↔production fork remains, and production itself already carries two copies
`apps/api/tests/Traits/ReadsCanonicalBytes.php:11` · `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:443` · `apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:565`

Round 2's finding offered "a shared trait **or** a call through the production service"; the trait was chosen, which is compliant. Recording for the record only: production owns two verbatim private copies of the same decoder already (both pre-existing at `BASE_SHA`), so the three-way fork the finding described is a codebase-level condition, not something M0 created or can fix under its no-production-code constraint. Consolidating it is a production change and correctly out of scope.

### 3. P3 — CONFIRMED — the scope signal exists only in the M0 artifact; the file the orchestrator polls carries no pointer
`docs/handoff/progress/es-wave-a0.progress.yaml:98`

`blockers: []`, and the YAML has no concerns field. Per `SELF-REVIEW-HARNESS.md:18,55,57,61`, `blockers:` is reserved for STOP conditions A–D, and the brief's instruction for a scope signal is "note it in the report and continue" (`:136-137`) — so round 3 satisfies the letter exactly and I am not requiring a change. Noting only that whoever opens M2 reads the YAML first, and the M2 green-on-clean conflict is a live forward risk sitting one file away. Not blocking; the M2 reviewer will see the artifact.

---

### Bypasses attempted that FAILED

1. **Tried to break the citation sweep on *completeness*, not accuracy** (rounds 1–2 only spot-checked individual anchors). Extracted all 19 `file:line` citations from the brief programmatically and every citation from the snapshot rows for the nine in-scope IDs (ES-06/07/08/09/16/17/41/42/43), then matched each token against the sweep table. **All resolve** — the two apparent misses (`2026_05_14_100002_….php:71`, `ParseFailureResolutionService.php:63-80,165-179,291-351`) are my regex splitting ranges the sweep records in separate rows. `unresolved = 0` survives a mechanical completeness check.
2. **Tried to make the trait extraction break something.** Autoload: `composer.json` `autoload-dev` maps `Tests\` → `tests/`, and 11 sibling traits already live in `tests/Traits` under that namespace. Collision: neither test class declares its own `stringifyCanonicalBytes` any more (only call sites remain), so no private-visibility shadowing. PHPStan: `paths: app/` — tests are not analysed, so `self::fail()` in a bare trait cannot trip level 8. No finding.
3. **Tried to falsify the pasted `pint` claim** — ran `./vendor/bin/pint --test` on all three files myself: `{"result":"pass"}`. Corroborates.
4. **Tried to show the pasted PG run could not have happened.** `phpunit-pgsql.xml` exists and forces `DB_CONNECTION=pgsql`; `autoerp_es_wave_a0_test` exists on `127.0.0.1:5432` with 270 public tables. The counts are identical to round 2 (18/68, 20/94), which is exactly what a trait extraction plus a comment change should produce — a suspicious *change* would have been the tell, not the sameness. No fabrication established.
5. **Tried to break the new scope-signal paragraph** by hunting a wrong anchor — `ReceiptHashService.php:236-239/:245/:304`, `Nf525DataProvider.php:343-345/:398` and snapshot `:132` all land exactly on the claimed constructs. No finding.
6. **Tried to break T-c's "invisible to both arms" premise independently of round 2's bypass 5** — the fiscal arm queries only `fiscal_events` (`:236-239`) and the legacy arm applies `whereNull('fiscal_event_id')` at `:355` and returns `true` on an empty set at `:361-363`. A projected receipt with a bogus `fiscal_hash` is genuinely invisible; T-c's blind-spot claim holds.
7. **Tried to re-run the contract digest to a different value** — reproduced `04760455…40` exactly from `BASE_SHA`. MATCH.
8. **Did NOT execute the PHPUnit suites.** Read-only mandate, plus `RefreshDatabase` drops and recreates databases and the full suite is forbidden by house rule. Substituted items 1–7, which settle authenticity without mutating state.

**Disposition:** all three round-2 findings are genuinely closed — I verified each against code rather than against the report. Every M0 acceptance item in the brief (`:420-457`, evidence contract `:576`, reviewer gate `:629`) is present and non-vacuous: the digest matches when I re-run it, the tamper helpers each assert the divergence they create, the v3 fixture is two-context, and `unresolved = 0` survives a completeness sweep. The three surviving items are P3 — one comment-level trap for M2 to flip, one out-of-scope pre-existing duplication, one bookkeeping note — none of which change what M1 stands on.

VERDICT: ACCEPT

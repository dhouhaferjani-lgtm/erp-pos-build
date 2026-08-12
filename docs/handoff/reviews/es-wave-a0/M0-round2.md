I reviewed the round-2 range against the code, the brief's M0 section, the register snapshot, and the live PostgreSQL stack.

---

## M0 review register — ES wave A0, round 2

**Range:** `df85d43f4..5885cc85a` (5 commits; round 2 = `3cf7bf8a4` + `5885cc85a`). Diff is 2 test files + 1 YAML + 2 artifacts. **No production file touched** — the "no production code" constraint holds.

### Round-1 findings — closure verified myself

| # | Round-1 finding | Round-2 state |
|---|---|---|
| 1 | P1 — resource-vs-string on BYTEA defeats every `canonical_bytes` self-assertion | **CLOSED.** `stringifyCanonicalBytes()` added in both files (`ParseFailureResumeTest.php:789-806`, `VerifyEventChainCommandTest.php:907-924`) and every DB read-back now routes through it — I grepped all 21 `canonical_bytes` occurrences across both files; the only raw values are insert-time literals. |
| 2 | P1 — no post-change run | **CLOSED** (see bypass 1). Real PG runs pasted at `M0-preflight-evidence.md:26-38`; the mislabelled SQLite baseline is now explicitly retracted at `:20`. |
| 3 | P2 — T-a asserted string inequality, not divergence | **CLOSED.** `ParseFailureResumeTest.php:708-719` now decodes both sides and asserts frozen semantics `['a' => 2]` against the corrected 30-key payload (`subtotal '10.02'`, `cash_rounding_adjustment '-0.02'`, `assertArrayNotHasKey('a', …)`). I confirmed the underlying facts: sealed bytes are `'{"a":1,"a":2}'` (`:741`) and `resolve()` writes `Parsed`/`Verified` (`ParseFailureResolutionService.php:165-179`). Genuinely semantic now. |
| 4 | P2 — clean fixture red under the receipt verifier, undisclosed | **PARTIALLY closed** — see finding 1 below. |
| 5 | P3 — T-b overlaps the pre-existing linkage check | **CLOSED.** Recorded verbatim at `M0-preflight-evidence.md:55`, including the instruction that M1 must not claim T-b's red as new-check evidence. |
| 6 | P3 — dirty tree at preflight | Unchanged, self-disclosed, non-blocking. |

**Reviewer-re-derived citations (brief `:576` antidote — four of my own choosing, none of round 1's):** `OutboxIngestor.php:918-924` (ES-16 `CanonicalParseFailure` + `z_session_lifecycle:` projection suppression) — **exact**; `FiscalEventQuarantine.php:93-119` (`$fillable`, no `resolved_at`/`resolved_by`) — **exact**; `2026_07_31_940000_…php:47-49,60-63` (pgsql driver gate; `pending_seal→fiscalized` early `RETURN NEW`) — **exact**; `RolesAndPermissionsSeeder.php:638-658` (cashier grants include `pos.operate_terminal`) — **exact**. Receipt-verifier anchors also re-derived: `verifyTerminalChain():206`, fiscal arm `:234`, legacy arm `:352` with `whereNull('fiscal_event_id')` at `:355`; `VerifyPosChainCommand` `:293`/`:304`, `:335`/`:341`, `:372` — all **exact**.

**Standing checks.** Rule 19: money in `insertProjectedReceipt()` is bcmath-safe strings at TND scale (`VerifyEventChainCommandTest.php:723-726`, `'10.000'/'0.000'`); no float, no scale resolver needed (test-local literals, not production emission). Red-first: N/A — M0 is fixtures-only with no behavioural change; its substitute (self-asserting fixtures) is present and, after round 2, non-vacuous. Constructor injection: `$this->app->make()` appears only in test bodies; rule 13 binds production, and there is none. No migrations, no queues, no user-facing strings. **Treasury lens: the only treasury surface in this diff is the money columns above — no payment, GL, or partial-write path is touched. Lens does not otherwise apply.**

---

### 1. P2 — CONFIRMED — the fiscal arm's context-flattening is disclosed as *behaviour* but never escalated as an unregistered production defect, and the downstream consequence for M2 and A1 is unstated
`docs/handoff/reviews/es-wave-a0/M0-preflight-evidence.md:53` · `apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:466-470` · `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:234-244`

Round 2 correctly records the mechanism and the reason T-c had to be single-context. What it still does not say is that this is a **defect, and a production one, and not covered by any register row**:

- `verifyTerminalChainFiscalArm()` selects `->where('terminal_id', …)->orderBy('sequence_number')` with **no `chain_context` predicate** (`:236-240`) and carries one `$expectedPrevious` across the whole stream (`:303`).
- `OutboxIngestor.php:172-179` proves each context is an **independent chain** — prior head resolved by `(tenant, company, terminal, chain_context)`, linkage verified against that head. So on a real terminal the `z_session` chain's first row presents `genesis_seed`, not the preceding operational row's `current_hash`.
- The register itself states the shape is universal: ES-09 (`snapshot:132`) — *"every v3 terminal: `z_session` + `operational`"*.
- Therefore `verifyTerminalChain()` returns **false for essentially every v3 terminal that has ever closed a Z session** — and its callers include `VerifyPosChainCommand` and, per the docblock at `:241-247`, `Nf525DataProvider::verifyReceiptChain`, i.e. **the live NF525 verify-chains endpoint**. That is a standing false fiscal alarm.
- I grepped the snapshot and the addendum: **no row covers this.** ES-09 covers the two *authoring* services' head resolution (`TerminalRegistrySnapshotService`, `VirtualAdminFiscalEventService`), not the verifier. It is a genuinely new discovery — exactly the "scope signal → note it in the report and continue" case the brief names at `:135-137`.

Failure scenario: M2's contract (brief `:500-503`) demands **"GREEN on the clean equivalent"** with **per-arm counts proving nonzero fiscal-era coverage**, and A1's entry criterion (brief `:424-425`) is *"A0's verifiers merged and green on a seeded v3 tenant."* The only two-context v3 fixture A0 produces is red under an existing production verifier. Whoever runs M2 discovers this at implementation time and must either fix the flattening (outside narrowed ES-07) or fall back to a single-context "clean" fixture that cannot prove two-context coverage — the precise vacuity the brief was written to prevent. Nothing in the artifact, the YAML, or the milestone record warns them.

Required: one paragraph in `M0-preflight-evidence.md` naming it as an **unregistered production defect** (with the `Nf525DataProvider` caller and the "every v3 terminal" reach), flagging it as a **scope signal**, and stating the consequence for M2's green-on-clean clause and A1's entry criterion. No code change is needed or wanted.

### 2. P3 — CONFIRMED — the fixture test pins the defect as *expected* behaviour rather than characterisation-pending-fix
`apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:466-470`

The assertion message reads *"the second sequence-1 row **must** expose that known linkage failure"* — phrasing that presents a bug as the contract. Failure scenario: whoever eventually adds `chain_context` partitioning to `verifyTerminalChainFiscalArm()` sees `test_seeded_v3_fixture_has_two_contexts_and_a_hash_mirrored_projected_receipt` go red, reads it as a regression they caused, and either reverts the fix or flips the assertion without understanding which direction is correct. A one-line comment marking it as characterisation of an unfixed defect (with the finding-1 pointer) removes the trap.

### 3. P3 — CONFIRMED — `stringifyCanonicalBytes()` is triplicated, forking the fixtures from the production decoder
`apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:911-924` · `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php:793-806` · `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:443`

Two verbatim private copies of a method production already owns. Failure scenario: if the BYTEA decode convention ever changes (hex-escape handling, a rewind, an encoding guard), production's decoder changes and the two fixture copies silently do not — and these fixtures are what M1–M5 stand on, so a divergence would make a red run red for the wrong reason. Low likelihood, but the whole lane's premise is that fixtures cannot lie. A shared trait or a call through the production service closes it.

---

### Bypasses attempted that FAILED

1. **Tried to establish the pasted PG runs were fabricated — failed; they corroborate.** `autoerp_es_wave_a0_test` exists on `127.0.0.1:5432` with **270 migrated public tables** (`max(migration) = 2026_08_10_120000_create_country_document_settings_table`). Arithmetic: `ParseFailureResumeTest` = 80 baseline + 14 new assertions = **94, exactly as reported**. `VerifyEventChainCommandTest` = 31 baseline + 34 new (I counted the new methods programmatically: 5+7, 5+7, 10) = 65 vs **68 reported** — but the 31 is an *SQLite* baseline, not a PG one, and a fabricator working from arithmetic would have written 65. The mismatch argues for an observed number, not a computed one. No fabrication established.
2. **Tried to re-raise round-1's "the run executes main-repo code" blocker — it no longer holds.** `apps/api/vendor` is now a **real directory** in this worktree (`file vendor` → `directory`, mtime 09:02, before the 09:17 commit), so `autoload_psr4.php`'s `$baseDir = dirname($vendorDir)` resolves to the worktree. `.env` is still a symlink to the main repo, but Laravel's immutable Dotenv lets the explicitly-exported `DB_*` vars win, and the disposable DB's existence proves they did.
3. **Tried to find a surviving raw `(string)` cast on a DB-read `canonical_bytes`** — all 21 occurrences inspected; none.
4. **Tried to find a register row already covering the fiscal-arm flattening** (grep of snapshot + addendum for `verifyTerminalChainFiscalArm` / `chain_context`) — only ES-09 (`:132`) and ES-19 (`:147`), neither of which touches the verifier. Finding 1's "unregistered" claim survives.
5. **Tried to break T-c's `assertTrue(verifyTerminalChain(...))` claim** by locating any receipt-side check inside the verifier — the fiscal arm reads only `fiscal_events` (`:236-240`) and the legacy arm excludes `fiscal_event_id IS NOT NULL` (`:355`), so a projected receipt with a bogus `fiscal_hash` is invisible to both arms. T-c is a genuine blind spot, exactly as the artifact claims.
6. **Did NOT run the suites myself.** Read-only mandate plus `RefreshDatabase` dropping/recreating databases. Substituted items 1–2 above, which settle the authenticity question without mutating anything.

**To clear M0:** finding 1 (one paragraph in the M0 artifact — no code) is the only fix-before-merge item; findings 2 and 3 are cheap and can ride along. Round 1's five substantive findings are all genuinely closed, and the citation sweep survived four fresh re-derivations of my own choosing.

VERDICT: CHANGES-REQUIRED

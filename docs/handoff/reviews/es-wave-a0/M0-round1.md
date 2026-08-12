Verified the diff against code, the brief's M0 section, and the live stack. Findings below.

---

## M0 review register — ES wave A0, round 1

**Preflight items that PASS (re-verified myself, not taken from the report):**
- Contract digest: I re-ran the §1 command at `df85d43f4` → `04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540` — **MATCH**. No abort condition.
- `base_sha` pinned in `docs/handoff/progress/es-wave-a0.progress.yaml:13`, branch `codex/es-wave-a0`, tree clean, no production file touched in the range (diffstat: 2 test files, 1 YAML, 1 artifact). The "no production code" constraint holds.
- Citation sweep spot-checks — I re-derived four of my own choosing, all **CONFIRMED exact**: `VerifyPosChainCommand.php:304` (`whereNull('fiscal_event_id')`), `:310-316` (zero-count `is_valid:true, count:0` branch), `:318` (`ReceiptHashService::verifyTerminalChain()` delegation), `:341` (duplicate carve-out in `findReceiptChainBreak()`); `TerminalRegistrySnapshotService.php:443-464` (`resolveChainPlacement()`, tenant+terminal only — no `company_id`, no `chain_context`); `HashChainIntegrityProvider.php:11-24` (unkeyed SHA-256); `2026_05_24_100000_add_chain_context_to_fiscal_events.php:20-31` (unique coordinate + context CHECK).
- ES-07 sub-claim table is honest, and claim 2 is **stronger** than recorded: my app-wide sweep (not just the two files the report grepped) finds `fiscal_hash`/`current_hash` co-occurring only at `PosCoreReceiptProjection.php:374` and `ZReportProjection.php:114` — both *writes*. The mirror is written and never compared anywhere. Claim 3's WITHDRAWN verdict is correct (`VerifyPosChainCommand.php:372-398` counts all Z rows unfiltered).
- Rule 19: money in the fixture is bcmath-safe strings (`'10.000'`, `'0.000'` at `VerifyEventChainCommandTest.php:833-836`); no float touches money. **Treasury lens: no payment/GL/partial-write surface in this diff — lens does not apply beyond this.** Red-first evidence: N/A for M0 (fixtures only, no behavioral change).

---

### 1. P1 — CONFIRMED — every tamper self-assertion that touches `canonical_bytes` is broken or vacuous on PostgreSQL
`apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php:703,705,706` · `apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:578,646,647`

`fiscal_events.canonical_bytes` and `pos_receipts.canonical_bytes` are `BYTEA` (`2026_05_14_100001_create_fiscal_events_table.php:57`, `2026_05_14_100005_...:59`). I probed this stack's PDO directly (read-only `SELECT ?::bytea`):

```
type=resource
STRING_CAST=Resource id #4
```

Production code knows this — `ReceiptHashService::stringifyCanonicalBytes()` at `ReceiptHashService.php:443-456` exists solely to branch on `is_resource()`, and `PosCoreReceiptProjectionTest.php:178-179` guards the same way. The new helpers read the column back via `DB::table(...)->first()` and cast with a bare `(string)`.

Failure scenario, running the two files by path under `phpunit-pgsql.xml`:
- `ParseFailureResumeTest.php:703` — `assertSame($canonicalBytesBefore /*string*/, $row->canonical_bytes /*resource*/)` → **fails**.
- `:705` and `VerifyEventChainCommandTest.php:578` (T-b), `:646` (T-c) — `hash('sha256', (string)$row->canonical_bytes)` hashes the literal `"Resource id #N"` → never equals `current_hash` → **fail**.
- `VerifyEventChainCommandTest.php:647` — `assertSame($mirror->event_canonical_bytes, $mirror->receipt_canonical_bytes)` compares two distinct resource handles → **fails**.
- Worst of all, `ParseFailureResumeTest.php:706` — `assertNotSame((string)$row->canonical_bytes, (string)$row->payload)`, **the single assertion that is supposed to prove T-a's divergence** — compares `"Resource id #4"` against the payload JSON and is **vacuously true**. The brief's stated antidote ("each tamper helper asserts the divergence it creates") is defeated exactly where it was aimed.

Under the default `phpunit.xml` (SQLite, BLOB→string) these pass, which is how it survived. The evidence doc claims PostgreSQL as the platform, and the fixture rationale at `M0-preflight-evidence.md:33` depends on PG triggers — so PG is the contractual platform here.

### 2. P1 — CONFIRMED — no post-change test run exists; the only recorded run is the pre-change baseline
`docs/handoff/reviews/es-wave-a0/M0-preflight-evidence.md:20`

The row reads `VerifyEventChainCommandTest.php: 15 passed (31 assertions); ParseFailureResumeTest.php: 19 passed (80 assertions)`. At `df85d43f4` those files hold **15** and **19** `public function test_` methods; at HEAD they hold **18** and **20**. The recorded run is the base-SHA baseline. `ls docs/handoff/reviews/es-wave-a0/` shows one file, and a grep for `OK (` / `Tests:` / `assertions` across the artifact and YAML returns only that line — there is no evidence anywhere in the diff that the four new tests were ever executed.

The M0 antidote table in the brief (`:576`) names precisely this: *"A prose 'I built the fixtures' claim. Antidote: each tamper helper asserts the divergence it creates."* Self-assertions that were never run are prose. Finding #1 is what that missing run would have caught.

### 3. P2 — CONFIRMED — T-a's divergence assertion would still not assert divergence once the resource bug is fixed
`apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php:706`

`assertNotSame($canonicalBytes, $payload)` proves only *string inequality*. A perfectly legitimate resolution — one whose payload genuinely is the strict parse of the frozen bytes — also produces two unequal strings (key ordering, whitespace, jsonb reserialisation). The assertion cannot distinguish tamper from correct behaviour.

The fixture's *actual* divergence is real and severe (`storeParseFailedFiscalEvent()` seals `canonical_bytes = '{"a":1,"a":2}'` at `:728` while `resolve()` writes the 30-key `correctedPayloadV3()`), so the shape is honest — but nothing asserts it. Failure scenario: a future change that makes `ParseFailureResolutionService::resolve()` re-derive the payload from canonical bytes would leave this helper green while T-a silently stops being a tamper, and M1's "RED against T-a" would then be red for a different reason or not at all.

### 4. P2 — CONFIRMED — the clean two-context v3 fixture is RED under an existing verifier, and the M0 record neither says so nor discloses why T-c had to be re-cut
`apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:236-239` · `apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:482-548` · `docs/handoff/reviews/es-wave-a0/M0-preflight-evidence.md:31,46`

`verifyTerminalChainFiscalArm()` selects `->where('terminal_id', $terminal->id)->orderBy('sequence_number')` with **no `chain_context` predicate**, then chains `$expectedPrevious` across every row (`:245`, `:304`). `seedV3FiscalFixture()` creates `operational`/seq 1/`previous_hash = genesis` and `z_session`/seq 1/`previous_hash = genesis`. Whichever row PG returns first, the second row's `previous_hash` (genesis) ≠ the first row's `current_hash` → `failure_mode: linkage_broken` → `verifyTerminalChain()` returns **false**. Deterministic, independent of tie order.

This is why commit `f566eec9f` ("Isolate fiscal mirror tamper") had to rebuild T-c on its own single-context terminal in order to make its `assertTrue(verifyTerminalChain(...))` hold. The artifact records the outcome — *"isolated single-context fixture"* (`:31`) — without the reason, and its receipt-verifier anchor at `:46` describes the fiscal arm as *"walks `fiscal_events`, hashes `canonical_bytes` against `current_hash`, and checks genesis/prior linkage"* while omitting that it does so **across all chain contexts**. Brief item 5 asks for exactly this partitioning reality of `verifyTerminalChain()` / `verifyTerminalChainFiscalArm()` / `verifyLegacyArm()`.

Failure scenario: M2's contract is "GREEN on the clean equivalents." The wave's own clean fixture is not green under the POS receipt verifier, and nobody downstream has been told. A0 hands A1 a v3 fixture that trips a verifier the moment two contexts exist — the very shape M0 was told to build because *"the two-context shape is what makes ES-09's defect reachable."* Note also that `seedV3FiscalFixture()` self-asserts only its own shape and, unlike T-c, never asserts any existing verifier's verdict on it — so the fixture cannot report this itself.

### 5. P3 — CONFIRMED — T-b is already caught by the pre-existing linkage check; record it so M1 doesn't read a stale red as a new one
`apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:363-436`

`walkChain()` **does** filter `->where('chain_context', $chainContext)` at `:370` and chains `$expectedPrevious` at `:417`/`:432`. T-b's operational seq-2 row carries the `z_session` head as `previous_hash`, so today's command already emits `previous_hash linkage mismatch`. The fixture shape is faithful to the brief's description (internally well-formed, rehashes correctly, wrong context) — no defect here — but M1's R-11 per-check discrimination requires knowing that T-b's red comes from the *existing* check, not from a new one. Not recorded anywhere in the artifact.

### 6. P3 — CONFIRMED — documented deviation from HARD PREREQUISITE §2 ("your worktree MUST be clean")
`docs/handoff/reviews/es-wave-a0/M0-preflight-evidence.md:15`

`git status --porcelain` at preflight returned ` M docs/handoff/progress/es-wave-a0.progress.yaml`, not empty. Self-disclosed and self-inflicted by the dispatch's own YAML-initialisation instruction; noting for the record, not blocking.

---

### Bypasses attempted that FAILED

1. **Running the two test files by path under `phpunit-pgsql.xml` to get the missing evidence myself — abandoned, not run.** Two independent blockers: (a) `apps/api/vendor` is a symlink to `/Users/houssamr/Projects/syneriva/apps/erp/apps/api/vendor`, so `autoload_psr4.php`'s `$baseDir = dirname($vendorDir)` resolves through the symlink to the **main repo**, meaning the run would execute main-repo production code rather than this worktree's; (b) `apps/api/.env` points the pgsql suite at the live local dev database `autoerp` on port 5433, which `RefreshDatabase` would wipe — outside a read-only mandate. I substituted the non-mutating PDO probe above, which settles finding #1's decisive fact without touching any data.
2. **Searching for any other committed run log** (`ls docs/handoff/reviews/es-wave-a0/`, grep for `OK (` / `Tests:` / `assertions` across the artifact + YAML) — only the single pre-change baseline line exists.
3. **Trying to disprove finding #1** by locating a currently-green test that reads `canonical_bytes` back with a plain `(string)` cast: the only test that reads a *refetched* row (`PosCoreReceiptProjectionTest.php:178-179`) explicitly guards with `is_resource()`; the plain-`assertSame` cases (`ZReportProjectionTest.php:87-88`, `Task33FiscalFullFlowVerificationTest.php:145`) compare in-memory models that were never round-tripped through PDO, so they do not disprove it.
4. **Trying to find a schema reason the fixture inserts would fail before reaching the assertions** (which would have changed #1's failure mode): all columns exist, and the `pos_receipts_totals` CHECK (`total = subtotal + tax_amount`, `2026_01_08_190637_create_pos_receipts_table.php:111`) is satisfied by `10.000 = 10.000 + 0.000`. The inserts are sound; the assertions are what break.

**Required to clear M0:** route every `canonical_bytes` read in the new helpers through the `is_resource()`/`stream_get_contents()` form (#1); re-run both files by path under `phpunit-pgsql.xml` and paste the real output into the artifact (#2); make T-a assert semantic divergence, not string inequality (#3); record the context-blind `verifyTerminalChainFiscalArm()` walk and the reason T-c is single-context in the M0 artifact (#4); note T-b's pre-existing-check overlap (#5).

VERDICT: CHANGES-REQUIRED

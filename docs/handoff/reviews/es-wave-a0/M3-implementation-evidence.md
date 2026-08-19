# M3 implementation evidence — ES-06 detection half + the ES-16/ES-17 contract proposals + the M2-round2 P3 sweep

Milestone contract: `docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md:519-532` (M3) with **R-9**
(`:359-370`) and **R-7** (`:325-347`). Standing constraint **R-1** (ZReportHashService zero-diff)
still binds.

Three deliverables, in the brief's own order:

1. **ES-06 detection** — divergence on a sealed row is now genuinely detectable on the surface the
   register names, demonstrated red-first through the **production resolution path**.
2. **`M3-straggler-contracts.md`** — the ES-16 / ES-17 verification contracts, **committed in this
   range** so the reviewer's diff contains them. **No straggler implementation in M3.**
3. **The four P3 residuals** the M2 round-2 register left open (findings 7, 8, 9, 10), swept into
   this diff exactly as that disposition asked.

**D-8 was NOT reached.** Nothing below designs a second approver, a correcting-event scheme, or any
change to what `ParseFailureResolutionService` is *allowed* to do. The resolution service is
byte-identical in this range (`git diff … -- app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php`
= 0 lines). Detection only, per R-9.

**Fix round 1 (post `M3-round1.md`, verdict CHANGES-REQUIRED).** This document has been amended in
place — corrections are labelled where they land, not appended as a changelog nobody reads:

| Register finding | Where it is answered |
|---|---|
| **F-1** — the recovery guard omitted `JSON_UNESCAPED_UNICODE`, so the discriminating branch was dead for any non-ASCII envelope, **and this evidence did not disclose it** | new **§1.6** (the limit, the one-flag fix, the bytea-binding root cause, and the red-first trio) + **Verification — fix round 1** |
| **F-2** — ES-16 clause 16-C routed operators to a command clause 16-D and falsifier F16-2 forbid | new **§2.1**; the amendment itself is in `M3-straggler-contracts.md` revision 2 |
| **F-3** — citation drift `:104` → `:106` in the same artifact | **§2.1**; corrected in the artifact |

---

## 1. ES-06 — the detection half

### 1.1 The blind spot M1 left, stated precisely

M1 added a `payload` ↔ `canonical_bytes` check
(`VerifyEventChainCommand.php:493-501`, the `$parsed->ok` branch). That check is real, but it can
**never run on the ES-06 surface**, for a structural reason:

- `ParseFailureResolutionService` is the **only** post-seal `payload` write the immutability
  trigger permits — the gated `failed → parsed` resume
  (`2026_05_14_100002_create_fiscal_events_immutability.php:155-199`; `payload` is write-once at
  `:181-199`, and `:165-178` requires the row to be a quarantined `canonical_parse_failure`).
- Therefore **every** row it can touch is by definition a row whose `canonical_bytes` the strict
  parser already rejected.
- `VerifyEventChainCommand::parseCanonicalForVerification()` re-runs **that same parser**
  (`:544`). On this surface it always fails, so the walk always took the `! $parsed->ok` branch
  (`:438`) — which emitted **one fixed sentence** regardless of what the operator supplied.

Net effect: on exactly the surface ES-06 names, the verifier had **zero discriminating power**. It
re-reported the pre-existing parse failure and that was read as "divergence detected".

### 1.2 Red-first proof, through the production path

New fixture `ParseFailureResumeTest::seedRecoverableSealedPayloadResolution()`
(`tests/Feature/Fiscal/ParseFailureResumeTest.php:947-997`). It differs from M0's T-a helper
(`seedPayloadRewriteTamper()`) in the one way that matters:

| | M0's T-a (`seedPayloadRewriteTamper`) | M3's fixture |
|---|---|---|
| frozen `canonical_bytes` | `{"a":1,"a":2}` — not an envelope at all | a **well-formed canonical envelope** (all 15 `StrictCanonicalParser::ENVELOPE_KEYS`) carrying the device's real sealed payload |
| why the parser rejects it | `duplicate_key:a` | `envelope_extra_field:device_firmware_note` — **one** unknown envelope field |
| sealed payload recoverable? | no, ever | yes, at the JSON level |
| realistic? | it is a torture fixture | yes: a firmware adds a field the server grammar does not know, and the payload inside is perfectly valid |

Both fixtures are driven through the **real** `ParseFailureResolutionService::resolve()` — no
hand-written UPDATE anywhere. The helper self-asserts its own shape before the verifier is ever
run (per the M0 toolkit rule, so a later red cannot be red for the wrong reason): the strict parser
really does reject the bytes and for exactly that reason (`:962-964`); the sealed payload really is
recoverable from the frozen bytes (`:969-972`); the seal really did hold — `canonical_bytes` and
`current_hash` are byte-identical after resolution and `current_hash == sha256(canonical_bytes)`
(`:987-990`).

The divergent correction is the ES-06 attack in its most consequential form: every money field
rewritten **10.00 → 25.00**, consistently, so it passes the resolver's DTO + per-event constraint
validation (`divergentCorrectedPayload()`, `:1094-1120`). Projectors read `payload`, so the
business sees 25.00 while the frozen bytes say 10.00.

**The RED run, before the production change** (PG, `phpunit-pgsql.xml`, by path):

```
✓ recoverable sealed payload parse failure fixture is self asserting   (fixture is real)
⨯ event chain verifier names a divergent correction against the sealed payload
⨯ event chain verifier does not claim divergence when the correction matches the sealed payload
Tests: 2 failed, 1 passed (46 assertions)
```

The captured output at that point is the finding, verbatim — the **same two lines** for the
divergent correction and for the faithful one:

```
CHAIN BREAK at sequence_number 1 (id …): sealed coordinates could not be derived from
  canonical_bytes (envelope_extra_field:device_firmware_note)
CHAIN BREAK at sequence_number 1 (id …): payload does not semantically match canonical_bytes —
  canonical payload could not be derived (envelope_extra_field:device_firmware_note)
```

A 10.00 → 25.00 rewrite and a faithful correction were indistinguishable.

### 1.3 The change

`VerifyEventChainCommand.php:446-482` (the `! $parsed->ok` branch) and the new
`recoverSealedPayloadFromFrozenBytes()` (`:673-716` — the method grew in fix round 1, see §1.6).

When the strict parse fails, the verifier now tries to recover the **sealed** payload from the
frozen bytes at the JSON level:

- **recovered + disagrees with the stored payload** → a precise incident naming the divergence.
- **recovered + agrees** → **no payload incident**. The verifier does not assert a divergence it
  just disproved.
- **not recoverable** → today's fail-closed sentence, unchanged.

**No verdict is softened.** The sealed-coordinate incident at `:439-444` is raised
*unconditionally* before any of this, so the exit code is 1 in every one of the three cases. That
is pinned, not asserted:
`ParseFailureResumeTest::test_event_chain_verifier_does_not_claim_divergence_when_the_correction_matches_the_sealed_payload`
asserts `exitCode === 1` **and** that the coordinate incident survives, alongside the absence of
the divergence claim.

### 1.4 The ambiguity guard, and proof that it bites

Recovery is only trusted when the bytes are **unambiguous**: they must decode as a JSON object
**and re-encode byte-identically** (`:685-707`). That round-trip rules out the one way a lenient
`json_decode` could disagree with the strict parser about what was sealed — duplicate keys, where
`json_decode` silently keeps the last occurrence while the parser rejects the document outright.

Demonstrated, not argued —
`VerifyEventChainCommandTest::test_sealed_payload_recovery_refuses_ambiguous_duplicate_key_envelopes`
(`:763-790`) builds bytes with a duplicated `payload` key where the stored payload equals the
member `json_decode` would win with, so a guard-less recovery would find them equal and stay
silent. Control run with the round-trip check commented out, same PG config, by path:

```
⨯ sealed payload recovery refuses ambiguous duplicate key envelopes   1 failed (3 assertions)
```

Guard restored → green. The guard is fail-closed in every direction: when it refuses, the caller
keeps the original incident, so it can never *widen* what the verifier accepts — it only decides
which sentence is true.

### 1.5 Untouched contracts, verified green

`ParseFailureResumeTest::test_event_chain_verifier_rejects_the_real_parse_resolution_payload_divergence`
(M1's, on the unrecoverable `{"a":1,"a":2}` bytes) and
`VerifyEventChainCommandTest::test_fails_when_parsed_payload_diverges_from_canonical_bytes` /
`…_passes_when_parsed_payload_semantically_matches_…` (M1's, on parseable envelopes) are all
byte-identical and all green. The change is strictly additive on the branch M1 could not reach.

### 1.6 Fix round 1 — F-1: the round-trip guard escaped non-ASCII, so the branch was dead on French and Tunisian data

**Disclosed limit, and the fix.** The round-1 register raised this as finding F-1, and it also
faulted §1 of this document for **not disclosing it** (`M3-round1.md:96-98`). The non-disclosure
was the more serious half: as originally written §1.3–§1.5 read as if the detection were general,
when it fired only on ASCII-only envelopes.

**What was wrong.** The guard re-encoded with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES` and
nothing else. Production canonical bytes are RFC 8785, and `CanonicalJsonEncoder::encodeString()`
(`CanonicalJsonEncoder.php:106-108`) emits **raw UTF-8** for U+0080+ via `JSON_UNESCAPED_UNICODE`.
PHP's default escapes those same characters as `\uXXXX`, so the byte-identity test could never hold
for an envelope carrying one accented or Arabic character — and the canonical SALE_RECEIPT grammar
carries operator-typed free text in `line_items[].name`, `cashier_name`, `seller.name` and
`seller.address.*`. On this wave's target countries (France, Tunisia) that is most receipts. The
milestone's headline deliverable was therefore **dead on the data it exists for**, while the three
M3 tests passed only because the fixture was ASCII-only.

The failure mode was **fail-closed** — the sealed-coordinate incident, the fallback sentence and
exit 1 all survived, so nothing hid and no verdict weakened — but the verifier stayed exactly as
indiscriminate on the ES-06 surface as R-9 says it must not be.

**The fix** is one flag: the re-encode at `VerifyEventChainCommand.php:697-700` now uses
`JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, mirroring the canonical
encoder exactly. This does **not** widen what the verifier accepts: byte identity is still the whole
acceptance test, so bytes carrying literal `\uXXXX` escapes now refuse instead — correct, because
`CanonicalJsonEncoder` cannot emit that form. The refusal direction is pinned by a test, not
asserted (below).

**The harness limit the register flagged, and what it actually was.** The reviewer could not write a
non-ASCII fixture because the insert died with `SQLSTATE[22P02] invalid input syntax for type
bytea`. Root cause, established by probe rather than guessed: `canonical_bytes` is `bytea` on PG,
and `Illuminate\Database\Connection::bindValues()` binds a plain PHP **string** as
`PDO::PARAM_STR`, which PostgreSQL parses with the bytea *escape* input rules. **It is a backslash
problem, not a non-ASCII problem** — raw UTF-8 binds fine; what died was the fixture's own
`json_encode(..., JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)` — which, missing
`JSON_UNESCAPED_UNICODE`, produced literal backslash-`u` escape sequences, and `\u` is not a legal
bytea escape. Probe result, PG 16, same connection parameters as the test run:

```text
bytes bound                                     PARAM_STR              PARAM_LOB
escaped-unicode  {"name":"Caf\u00e9 cr\u00e8me"}  FAIL SQLSTATE 22P02    OK (byte-identical)
raw-utf8         {"name":"Café crème"}            OK (byte-identical)    OK (byte-identical)
escaped-quote    {"name":"He said \"hi\""}        FAIL SQLSTATE 22P02    OK (byte-identical)
```

Both halves are fixed in the harness:

1. `storeSealedEnvelopeParseFailure()` now seals with `JSON_UNESCAPED_UNICODE` by default — the
   fixture was not modelling a device envelope without it, since RFC 8785 is the raw-UTF-8 form. A
   `$escapeUnicode` parameter deliberately seals the WRONG form for the refusal test.
2. `byteaBinding()` (`ParseFailureResumeTest.php`) wraps the bytes in an in-memory stream, which
   `Connection::bindValues()` binds as `PDO::PARAM_LOB` — transmitted as binary, byte-identical on
   both PG (`bytea`) and SQLite (`blob`). That is what lets the refusal fixture carry backslashes at
   all. **Test-harness only — no production write path changes.**

**Out-of-scope observation, recorded not fixed (rule 4).** The same `PARAM_STR` binding is what
production ingestion uses (`OutboxIngestor.php:815`, plain string through Eloquent; no
`PDO::PARAM_LOB` or `pg_escape_bytea` exists anywhere in `app/`). Canonical bytes produced by
`CanonicalJsonEncoder` are raw UTF-8 and normally backslash-free, so ordinary accented text is safe
— but a **double quote or backslash inside free text** (a product named `He said "hi"`) makes RFC
8785 emit `\"`, and the probe above shows that shape failing to insert on PG. This is outside M3's
scope and is **not** touched here; it is flagged for whoever owns the ingestion lane.

**Red-first proof, all three new tests** (PG, `phpunit-pgsql.xml`, by path, before the flag change):

```text
✗ event chain verifier does not claim divergence for a faithful non ascii correction
    Failed asserting that … does not contain "canonical payload could not be derived"
    actual: "payload does not semantically match canonical_bytes — canonical payload
             could not be derived (envelope_extra_field:device_firmware_note)"
      → recovery REFUSED a canonical non-ASCII envelope. This is F-1 reproduced.

✗ event chain verifier names a divergent correction against a non ascii sealed payload
    To contain: "the sealed payload was recovered from the frozen envelope and disagrees
                 with the stored payload"
    actual:     "canonical payload could not be derived"
      → a full money rewrite on a French/Arabic receipt was indistinguishable from an
        ordinary parse failure.

✗ sealed payload recovery refuses unicode escaped non canonical bytes
    To contain: "canonical payload could not be derived"
    actual:     1 chain incident only — no payload sentence at all
      → recovery FIRED on `\uXXXX`-escaped bytes, a form CanonicalJsonEncoder cannot emit.

Tests: 3 failed (49 assertions)
```

That third failure is the sharpest statement of the defect: the pre-fix guard accepted exactly the
byte form production never produces and refused exactly the form it always produces. All three go
green with the one-flag change and nothing else.

---

## 2. `M3-straggler-contracts.md` — committed in this range

`docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md`. For **ES-16** and **ES-17** it states,
per R-7, what the fix must demonstrate and what would falsify it: 6 numbered clauses + 7 falsifiers
for ES-16 (F16-1…F16-7 — the count was stated as 6 here before M3 round-2 finding N-3; revision 2
added F16-7 under finding F-2 and this sentence was not updated with it), 7 clauses + 7 falsifiers
for ES-17, plus an explicit out-of-scope list for each.

Every defect claim in it is restated **from code**, not from the register, with `file:line`
re-derived against the tree as this milestone leaves it. Two findings worth flagging to the
reviewer because they sharpen the register's own wording:

- **ES-16** — `z_session_lifecycle` is an exception **reason**, not a class. The verdict is folded
  into `$linkageVerdict` at `OutboxIngestor.php:182-186`, so `deriveIntegrity()` (`:730-776`)
  classifies these rows as **`sequence_gap`**. That is *why* partition 2's
  `canonical_parse_failure` predicate (`DeadLetteredProjectionsController.php:107`) skips them.
- **ES-17** — the consequence is worse than "no operator workflow". `VerifyEventChainCommand.php:856`
  reports every `resolved_at IS NULL` quarantine row as a chain incident, so **one
  `sequence_conflict` envelope makes `fiscal:verify-event-chain` exit 1 for that terminal
  permanently** — the only condition that clears it is a column nothing writes. The command this
  wave exists to make trustworthy has a permanently-red state with no exit.

**No straggler implementation landed.** `git diff` in this range touches neither
`DeadLetteredProjectionsController.php` nor `FiscalEventQuarantine.php` nor `OutboxIngestor.php`.

### 2.1 Fix round 1 — findings F-2 and F-3, amendments to the artifact only

The artifact is now at **revision 2**; its own header records the three edits. Restated here so the
milestone evidence and the artifact do not diverge:

- **F-2 — clause 16-C was self-contradicting, and is DROPPED.** It required the surfaced row to
  advertise `fiscal:enqueue-resolved-event-projections` as its recovery path. That command applies
  no `integrity_status` filter (`EnqueueResolvedEventProjectionsCommand.php:244-245`, `:247-250`,
  `:254`) and `createMissingPendingRows()` (`:340-365`) re-checks no suppression, so on a
  `z_session_lifecycle` row it creates and dispatches exactly the projections
  `OutboxIngestor.php:922-924` refused — the outcome clause **16-D** calls "wrong aggregates" and
  falsifier **F16-2** makes a rejection trigger. Of the two options the register offered, **(i)** is
  taken: ES-16 is now a pure visibility contract. 16-C is rewritten as an explicit *absence*
  requirement (the row names no command at all), the reasoning is recorded under the clause table,
  and falsifier **F16-7** is added so an implementation that re-introduces the affordance — or that
  "fixes" the command by adding the missing precondition — is rejected on the contract's own terms.
  Option (ii) is refused explicitly and on the record: adjudicating a suppressed fiscal row is
  D-8-adjacent and owes its own STOP.
  The defect narrative's **item 5** carried the same false "recovery works" premise 16-C was built
  on; it is corrected **in place**, with the correction labelled, rather than silently edited.
- **F-3 — citation drift.** Clause 16-F's tenant-filter citation `:104` → `:106`. Re-verified:
  `DeadLetteredProjectionsController.php:104` is `if ($projectorFilter === null) {`, `:106` is
  `->where('tenant_id', $user->tenant_id)`, `:107` is the class predicate.

**Still no straggler implementation, and no command change.** `EnqueueResolvedEventProjectionsCommand.php`,
`DeadLetteredProjectionsController.php`, `FiscalEventQuarantine.php` and `OutboxIngestor.php` all
remain **0-line** diffs across M3 and this fix round — the F-2 amendment is a change to the
*contract*, per the register's instruction that M3b, not M3, implements.

---

## 3. The M2-round2 P3 sweep

### Finding 7 — the false Z-arm comment and the fabricated `Inspected` value

`VerifyPosChainCommand.php:161-193`. The old comment claimed the Z arm's verdict *"spans exactly
the Z reports it counts"* and reprinted `count` under the `Inspected` header. Both were false:
`verifyZReportChain()` ORs the legacy `ZReport` walk (`ZReportHashService.php:219-249`) with
`verifyFiscalEventsArm()` (`:251-292`), which walks **every** `z_session` /
`training_z_session` `fiscal_events` row on the terminal — `SESSION_OPEN` / `SESSION_CLOSE`
included — while `count` is only `ZReport::where('terminal_id', …)->count()` (`VerifyPosChainCommand.php:357`).

Measuring the real span requires changing `ZReportHashService`, which **R-1 forbids**. So the
honest report is that the number is **not measured**: the cell now renders `unmeasured` and the
comment states exactly what the verdict spans and why it is not measured here. The inherited
`count === 0` fast path (`:359-365`) is named in the same comment as inherited and out of scope,
per the reviewer's own scoping.

Pinned by `VerifyPosChainCommandTest::test_z_report_row_does_not_fabricate_an_inspected_count`.
**Red-first proven:** control run with `'inspected' => $result['count']` restored → the test fails
on the missing `unmeasured`.

**R-1 holds:** `git diff` over `app/Modules/POS/Domain/Services/ZReportHashService.php` = **0
lines** across this milestone.

### Finding 8 — `chain_context` in the forensic log

`ReceiptHashService.php:535-567` — `logChainFailure()` (`:551`) now emits `failed_chain_context` (`:561`). The fix's
own rationale (`fiscalEventBreakPoint()`, `:435-449`: *"`sequence_number` alone is ambiguous once
contexts are partitioned — each restarts at 1"*) applies verbatim to the auditor-facing structured
channel; only the human-facing break point had been updated. All three call sites (`:363`, `:395`,
`:413`) are fiscal-arm rows from `inspectFiscalEventStream()`, which selects `chain_context`.

Pinned by
`ReceiptChainRebuildTest::test_structured_chain_failure_log_carries_the_chain_context_coordinate`
— a **PG-runnable** test (link tamper via INSERT, not a canonical-bytes UPDATE, so the immutability
trigger does not block it; the two pre-existing `chain_verification_failed` log tests are both
SQLite-gated). **Red-first proven:** control run with the key removed → the test fails.

### Finding 9 — the understated RED count in M2's evidence

`M2-implementation-evidence.md:524-547`. The claim *"5 failed in `ReceiptChainRebuildTest`"* is
corrected **in place** to **8**, with the per-test breakdown the reviewer measured at the control
commit `26b1371a6`: 5 new controls + 3 pre-existing command-table tests whose expected header the
control commit widened. Corrected in place with the error named, not silently edited.

### Finding 10 — the unattributable `broken_at_sequence`

`ReportController.php:466-490` recomputes the **legacy pipe hash** over ALL receipts including
`fiscal_event_id`-linked ones and threads `$previousHash` from `null` (`:473`). Every fiscal-era
chain's first projected receipt carries the terminal `genesis_seed` as its `previous_hash`, which
is never `null`, so the first iteration takes the `:475` branch and returns the **lowest**
`chain_sequence` — before it ever inspects the row that diverged.

Per the milestone instruction (**smallest honest change; do not refactor the controller**) this is
**pinned, not fixed**:
`ReceiptChainVerificationTest::test_broken_at_sequence_is_unattributable_on_a_fiscal_era_terminal`
seeds two fiscal-era receipts where **#1 mirrors correctly and #2 does not**, so the honest
coordinate is 2 — and asserts the endpoint returns **1**, with the reason recorded in the test's
docblock. When someone fixes the controller that assertion goes red, which is the point. Not a
regression from M2: pre-M2 the context-flattening defect returned this same wrong coordinate far
more often.

---

## Verification

All runs **by path**, never the full suite.

**PostgreSQL** (`phpunit-pgsql.xml`, `DB_DATABASE=autoerp_es_wave_a0_test` on `127.0.0.1:5432`):

```text
tests/Feature/Fiscal/ParseFailureResumeTest.php                24 passed (157 assertions)
tests/Feature/Fiscal/VerifyEventChainCommandTest.php           34 passed (138 assertions)
tests/Feature/Fiscal/ReceiptChainRebuildTest.php               3 skipped, 22 passed (149)
tests/Feature/POS/VerifyPosChainCommandTest.php                15 passed (34 assertions)
tests/Feature/POS/ReceiptChainVerificationTest.php             8 passed (34 assertions)
tests/Feature/POS/FiscalStatusFilterTest.php                   3 passed (7 assertions)
tests/Feature/Fiscal/Nf525VerifyChainParityTest.php            3 passed (15 assertions)
tests/Feature/POS/ReceiptHashServiceVerifyLegacyArmV4Test.php  5 passed (6 assertions)
tests/Feature/Fiscal/PosCoreReceiptProjectionTest.php          30 passed (135 assertions)
```

**Default harness** (SQLite), the five files this milestone touched:

```text
tests/Feature/Fiscal/ParseFailureResumeTest.php                24 passed (157 assertions)
tests/Feature/Fiscal/VerifyEventChainCommandTest.php           34 passed (138 assertions)
tests/Feature/Fiscal/ReceiptChainRebuildTest.php               1 skipped, 24 passed (159)
tests/Feature/POS/VerifyPosChainCommandTest.php                15 passed (34 assertions)
tests/Feature/POS/ReceiptChainVerificationTest.php             8 passed (34 assertions)
```

Deltas vs M2's recorded counts, all accounted for: `ParseFailureResumeTest` 21 → 24 (+3 ES-06),
`VerifyEventChainCommandTest` 33 → 34 (+1 ambiguity guard), `ReceiptChainRebuildTest` 21 → 22 (+1
finding-8 log pin), `VerifyPosChainCommandTest` 14 → 15 (+1 finding-7 pin),
`ReceiptChainVerificationTest` 7 → 8 (+1 finding-10 pin). The four untouched files reproduce M2's
counts exactly.

**RED runs recorded in this milestone** (each control reverted immediately after):

| Control | Result |
|---|---|
| ES-06 detection, before the `VerifyEventChainCommand` change | 2 failed, 1 passed (46 assertions) — the fixture self-assertion passed, both detection tests failed |
| ambiguity guard commented out | 1 failed (3 assertions) |
| finding 7 — `'inspected' => $result['count']` restored | failed on the missing `unmeasured` |
| finding 8 — `failed_chain_context` key removed | failed |

**Static analysis:**

```text
$ ./vendor/bin/pint --test <8 changed paths>
{"result":"pass"}

$ ./vendor/bin/phpstan analyse --memory-limit=4G \
    app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php \
    app/Modules/POS/Commands/VerifyPosChainCommand.php \
    app/Modules/POS/Domain/Services/ReceiptHashService.php
[OK] No errors                                            # level 8
```

### Verification — fix round 1

One production file changed in this round (`VerifyEventChainCommand.php`, the one flag + comments)
and one test file (`ParseFailureResumeTest.php`). Re-run **by path**, never the full suite.

**PostgreSQL** (`phpunit-pgsql.xml`, `DB_DATABASE=autoerp_es_wave_a0_test` on `127.0.0.1:5432`):

```text
tests/Feature/Fiscal/ParseFailureResumeTest.php                27 passed (209 assertions)
tests/Feature/Fiscal/VerifyEventChainCommandTest.php           34 passed (138 assertions)
```

**Default harness** (SQLite), the same two files in one run:

```text
tests/Feature/Fiscal/ParseFailureResumeTest.php
tests/Feature/Fiscal/VerifyEventChainCommandTest.php           61 passed (347 assertions)
```

`61 = 27 + 34` and `347 = 209 + 138`, so both drivers agree test-for-test and assertion-for-
assertion. Delta on `ParseFailureResumeTest`: **24 → 27** (+3, the F-1 regression trio) and
157 → 209 assertions. `VerifyEventChainCommandTest` reproduces its M3 headline count **exactly**
(34 / 138) — the flag change alters no existing behaviour, including the duplicate-key ambiguity
guard, which is ASCII and unaffected.

**RED run for this round** — all three new tests, before the flag change, transcribed in §1.6. Summary:

| New test | Pre-fix result |
|---|---|
| faithful non-ASCII correction | FAILED — recovery refused a canonical non-ASCII envelope (`canonical payload could not be derived`) |
| divergent non-ASCII correction | FAILED — the money rewrite was not named; same generic sentence |
| `\uXXXX`-escaped non-canonical bytes | FAILED — recovery **fired** on bytes the canonical encoder cannot emit |

```text
Tests: 3 failed (49 assertions)   →   after the one-flag change: 27 passed (209 assertions)
```

**Static analysis, fix round 1:**

```text
$ ./vendor/bin/pint --test \
    app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php \
    tests/Feature/Fiscal/ParseFailureResumeTest.php
{"result":"pass"}

$ ./vendor/bin/phpstan analyse --memory-limit=4G \
    app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php
[OK] No errors                                            # level 8
```

## Standing checks

- **R-1 — HOLDS.** `ZReportHashService.php` diff = **0 lines** in this milestone.
- **D-8 — NOT reached.** `ParseFailureResolutionService.php` diff = **0 lines**. No approver, no
  correcting event, no change to what the resolver may do.
- **Rule 19 — N/A.** No money or quantity arithmetic added. The `10.00 → 25.00` values are
  **test-fixture strings** exercising a payload comparison; no arithmetic is performed on them and
  no scale resolution is reachable from this console path.
- **Rule 13 — holds.** No `app()` added to production code; `$this->app->make(...)` appears only in
  tests.
- **Rule 20 — holds.** No new queue (`onQueue` absent from the diff) so no `horizon.php` entry is
  owed; no migration; no `CompanyContext` dependency introduced; no SQLite timestamp boundary and
  no device shift re-hydration in scope.
- **Rule 8 — safe.** No event class touched.
- **Tenant scoping** — unchanged; the verifier keeps its per-tenant binding and actor gate
  (`VerifyEventChainCommand.php:68-100`, `:198-260`), untouched by this milestone.
- **en+fr** — N/A (artisan console output and structured logs).
- **No production row's hash shape changed.** The new recovery path is read-only: it decodes frozen
  bytes to choose an incident sentence and writes nothing.

## M3 adversarial review — ES-06 detection half + the ES-16/ES-17 straggler contract artifact, round 1

**Reviewed:** the slice `325499fe3..a8dee9efe` (`67b698cc6` ledger, `1edbf2633` M3 implementation,
`a8dee9efe` hand-to-review) against the milestone contract (YAML M3 row +
`docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md` M3/R-7/R-9 sections) and the four P3
residuals the M2 round-2 register swept forward (findings 7, 8, 9, 10). HEAD verified
`a8dee9efe629a7e42313e774a6b54e5e5b1a498f`, tree clean, branch `codex/es-wave-a0`.

**Test harness I used:** PostgreSQL, `apps/api/phpunit-pgsql.xml`, the recorded env recipe
(`DB_DATABASE=autoerp_es_wave_a0_test` on `127.0.0.1:5432`), every file **by path**, never the full
suite. `apps/api/vendor` in this worktree is a real directory, so the runs execute THIS worktree's
production code.

---

### Scope gates — all four verified mechanically, not on the executor's word

| Gate | Required | Observed |
|---|---|---|
| D-8 not reached | `ParseFailureResolutionService.php` diff = 0 lines | `git diff 325499fe3..a8dee9efe -- '*ParseFailureResolutionService*'` **empty** |
| No straggler implementation | `DeadLetteredProjectionsController`, `FiscalEventQuarantine`, `OutboxIngestor` all 0-line | all three diffs **empty** |
| R-1 holds | `ZReportHashService.php` diff = 0 lines | **empty** — finding 7 was closed at the rendering site (`VerifyPosChainCommand.php:191`) exactly so the Z service stays untouched |
| Straggler contract is a committed artifact in the reviewed range | `docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md` present in the diff | present, 221 lines, `PROPOSAL` status stated at `:3` |

Production surface of the whole milestone is three files:
`VerifyEventChainCommand.php` (+91), `VerifyPosChainCommand.php` (+28, comment + one rendered cell),
`ReceiptHashService.php` (+10, one log key). No migration, no route, no event class, no queue.

---

### The ES-06 detection change — verdict never softens (verified by execution)

`VerifyEventChainCommand.php:439-444` raises the **sealed-coordinate incident unconditionally**
before the new branch is consulted, so every row that can reach `:465` already carries at least one
incident. The new code can therefore only change *which sentence* is printed, never whether the run
fails. Confirmed end to end by
`ParseFailureResumeTest.php:262-289`: on a faithfully-resolved row the payload claim disappears, the
coordinate incident survives, and the exit code is still **1** — asserted on all three, not just the
message.

**Red-first is genuine and drives the production path.**
`seedRecoverableSealedPayloadResolution()` (`ParseFailureResumeTest.php:845-892`) calls the real
`ParseFailureResolutionService::resolve()` at `:872-873` — no hand-written UPDATE — and self-asserts
its own shape first: the strict parser really rejects the bytes for exactly one envelope-level
reason (`:855-860`, `envelope_extra_field:device_firmware_note`), the sealed payload really is
present in the frozen bytes (`:863-867`), and after resolution `canonical_bytes` and `current_hash`
are byte-identical (`:881-884`). That is the strongest available form of R-9's "built by driving the
real resolution path".

**The ambiguity guard bites — I disabled it and watched it fail.** I patched
`VerifyEventChainCommand.php:686` to `if (false)`, ran
`test_sealed_payload_recovery_refuses_ambiguous_duplicate_key_envelopes` on PG, and got
`1 failed — Output does not contain "canonical payload could not be derived"`: without the
round-trip check the duplicated-key envelope is silently judged against the SECOND `payload` member
and the fail-closed sentence vanishes. Guard restored; tree re-verified clean. Independently
confirmed at the language level that `json_decode` keeps the last duplicate (`{"a":1,"a":2}` →
`{"a":2}`), which is the exact premise the guard's docblock at `:654-661` states.

**Adversarial questions asked of this change:**

| Question | Answer, from code |
|---|---|
| Can a crafted payload make the recovery claim a **false match** (key order, unicode escapes, number formatting)? | **No.** Recovery only survives byte-identical re-encode (`:686`), and the comparison is `===` over recursively `ksort`ed arrays (`semanticallyEqual` `:706-709`, `normalizeJsonObject` `:715-727`) — no loose coercion, so `"0"` vs `0` still diverges. Empirically, every ambiguity class is REFUSED: duplicate keys, insignificant whitespace, `1.0`, `1e2`, bigint saturation. Numbers cannot reach the recovery path as a divergence vector anyway — the canonical grammar rejects fractions, exponents and non-canonical integers outright (`StrictCanonicalParser.php:506-550`). |
| Can the frozen bytes **leak** into output? | **No.** The new sentence emits only `sequence_number` and `id` (`:476-480`). The fallback emits `$parsed->failureReason`, which is pre-existing and carries at most a key/field NAME (`duplicate_key:<key>`, `envelope_extra_field:<field>`), never a value. |
| Does it alter behaviour for **non-resolution-permitted** rows? | Only in the honest direction. Reaching `:465` needs `! $parsed->ok` AND `payload_parse_status = Parsed`; at ingest those are mutually exclusive (`OutboxIngestor.php:747-757` forces parse failures to `failed` + NULL payload), so the state arises either via the resolution service or via a retroactively tightened grammar. For the latter the change suppresses a payload claim that was **false** while keeping the coordinate incident and exit 1. No row loses an incident it deserved. |

---

### Finding 1 (P2) — the recovery guard omits `JSON_UNESCAPED_UNICODE`, so the discriminating branch is dead for any non-ASCII payload

`VerifyEventChainCommand.php:682` re-encodes with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES`.
Production canonical bytes are RFC 8785 canonical JSON, which emits **raw UTF-8 for U+0080+** —
`CanonicalJsonEncoder.php:95-108` says so in terms and encodes with
`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR` at `:108`. PHP's default
`json_encode` escapes non-ASCII as `\uXXXX`, so the round-trip at `:686` can never match and
recovery is refused for **every** envelope containing one accented or Arabic character.

Canonical fiscal payloads carry exactly that kind of free text:
`line_items[].name` (`FiscalPayloadConstraintValidator.php:1660`, `:1681`, `:2109`),
`customer.name` (`:1258`), `seller.name` / `seller.address` (`:1797`, `:1809`).

**Control, run against the real encoder and the real private method under reflection:**

```text
ascii product name     recovery=RECOVERED  bytes={"payload":{"name":"Default item","total":"10.00"}}
french product name    recovery=REFUSED    bytes={"payload":{"name":"Cafe cremeé","total":"10.00"}}
arabic product name    recovery=REFUSED    bytes={"payload":{"name":"قهوة","total":"10.00"}}
```

The three ES-06 tests pass only because the fixture is ASCII-only
(`ParseFailureResumeTest.php:1138` `'Default Cashier'`, `:1150` `'Default item'`).

This is **fail-closed** — the fallback sentence and exit 1 are preserved, no chain break can hide,
and no verdict weakens — which is why it is P2 and not P1. But this wave's targets are France and
Tunisia, so in production the milestone's headline deliverable will silently not fire on the
majority of receipts, and the verifier stays exactly as indiscriminate as R-9 says it must not be.
The M3 evidence artifact does not disclose the limitation anywhere (`grep -in "ascii|unicode|escap|utf"`
over `M3-implementation-evidence.md` returns nothing), so a reader takes the detection as general.

**Fix before merge:** add `JSON_UNESCAPED_UNICODE` at `:682` so the guard's flag set matches the
canonical encoder's, plus a non-ASCII regression fixture. The widening is safe in the same
fail-closed direction — byte-identity is still the acceptance test, duplicate keys still refuse, and
bytes carrying literal `\uXXXX` escapes then refuse instead (which is correct: those are not what
the canonical encoder produces).

**Sub-note for whoever writes that fixture:** the current test harness cannot express it on PG. I
set `:1150` to a non-ASCII name and the insert died with
`SQLSTATE[22P02]: invalid input syntax for type bytea` at `ParseFailureResumeTest.php:931` —
`canonical_bytes` is `bytea` on PG and the helper binds a plain PHP string, which only survives
while it is ASCII. The regression test needs a bytea-aware binding, not just a changed literal.

---

### The straggler contract artifact — APPROVED for ES-17, APPROVED-AS-AMENDED for ES-16

I spot-checked **13** citations, not 6. All resolve, and the two most load-bearing ones resolve to
the exact line:

| Citation | Resolves to |
|---|---|
| `OutboxIngestor.php:510-556` | `verifyZSessionLifecycle()` opens at `:510`; returns `z_session_lifecycle:missing_session_close` at `:551` ✓ |
| `OutboxIngestor.php:182-186` | the lifecycle verdict folded into `$linkageVerdict` ✓ |
| `OutboxIngestor.php:730-776` | `deriveIntegrity()` at `:730`; `$linkageVerdict !== null => IntegrityExceptionClass::SequenceGap` at `:747-749` ✓ |
| `OutboxIngestor.php:922-924` | the `str_contains($exceptionReason, 'z_session_lifecycle:')` early return ✓ |
| `OutboxIngestor.php:1114-1119` / `:335-341` | `SequenceConflict` / `MalformedEnvelope` quarantine inserts ✓ |
| `DeadLetteredProjectionsController.php:107` | `->where('integrity_exception_class', 'canonical_parse_failure')` ✓ |
| `ParseFailureResolutionService.php:250-255` / `:263-269` | the `payload !== null` and exception-class preconditions ✓ |
| `EnqueueResolvedEventProjectionsCommand.php:245` | `->where('payload_parse_status', Parsed)` ✓ exact |
| `FiscalEventQuarantine.php:20-22` / `:57-58` / `:84-90` / `:151` | MUTABLE docblock, both `@property` lines, the `$fillable` omission rationale, the cast ✓ |
| `VerifyEventChainCommand.php:843`, `:856` | the docblock line and `->whereNull('resolved_at')` ✓ exact |
| `Nf525DataProvider.php:1571`, `:1603-1605` | the §8 export of the stamp ✓ |
| `ParseFailureResumeTest.php:138` | `givePermissionTo('fiscal.events.resolve_quarantine')` ✓ exact |
| `ReportController.php:466-490` (in the finding-10 docblock) | `$previousHash = null` at `:473`, the `!==` branch at `:475` ✓ both exact |

**The two sharpening findings the executor flagged are both real and both correctly handled.**
(a) `z_session_lifecycle` folded into `$linkageVerdict` at `:182-186` and therefore classified
`SequenceGap` by the priority `match` at `:747-752` — verified; the artifact restates it accurately
including the consequence that partition 2 skips the row. (b) One unresolved quarantine row is a
permanent exit 1: `whereNull('resolved_at')` at `VerifyEventChainCommand.php:856`, and a
repository-wide sweep for a writer finds none (`resolved_at` writes in `app/` are only the unrelated
`integrity_resolved_at` on `fiscal_events`, `FraudAlert`, `CountingReconciliationService` and
`PosCustomerAliasResource`). Clause 17-C names `:856` as the seam correctly.

**ES-17's contract is APPROVED as written.** 17-A through 17-G are each falsifiable, the refusal
half of 17-E is correctly two-sided (403 **and** persists nothing, F17-5), F17-4 forecloses the
obvious cheat of making the verifier ignore quarantine rows, and F17-2 correctly refuses to let the
stamp imply a correctness claim about the envelope.

### Finding 2 (P2) — ES-16 clause 16-C instructs the operator to run the exact command 16-D and F16-2 forbid

16-C requires the surfaced row to name `fiscal:enqueue-resolved-event-projections` as its recovery
path, on the stated ground that the command "genuinely works on it". I verified that premise and it
is true — and that is the problem. The command's only filters are
`payload_parse_status = parsed` (`EnqueueResolvedEventProjectionsCommand.php:244-245`), an optional
event id (`:247-250`) and `tenant_id` (`:254`). There is **no** `integrity_status` filter, and
`createMissingPendingRows()` (`:340-365`) inserts a pending row for every active projector without
re-checking the suppression at `OutboxIngestor.php:922-924`. So running it on a
`z_session_lifecycle` row creates and dispatches precisely the projections the ingestor refused.

The artifact says the opposite about that outcome, twice, in its own words:
16-D's rationale — *"suppressing projections for a lifecycle-invalid Z session is correct —
projecting it would write wrong aggregates"* (`M3-straggler-contracts.md:102`) — and
F16-2, which makes the projections firing a **falsifier** (`:112-114`). A contract cannot forbid the
implementation from producing those projections and simultaneously require it to advertise the
command that produces them. As written, M3b would ship an operator affordance that reads "run this
to fix it" for an action the same document classifies as writing wrong Z aggregates.

**Required amendment before M3b implements 16-C** — pick one and state which:
either (i) drop the recovery-path clause and keep ES-16 purely a visibility contract (the artifact's
own closing sentence already argues for this: *"the strongest version of this contract is the one
that adds no new decision anywhere"*, `:129-130`), or (ii) keep it but state explicitly that the
command is correct **only after a human has adjudicated the lifecycle violation**, and add the
clause that the surfaced row must say so — in which case note that an adjudication step on a
suppressed fiscal row is D-8-adjacent and may itself need a STOP. Silence is the one option that is
not available, because clause 16-C as written is the instruction an operator will actually follow.

### Finding 3 (P3) — citation drift in the artifact

`M3-straggler-contracts.md:104` (clause 16-F) cites `DeadLetteredProjectionsController.php:104` for
partition 2's tenant filter. `:104` is `if ($projectorFilter === null) {`; the tenant filter is
`:106`. `:107` in the same paragraph is exact. Trivial, but this artifact's whole value is that its
addresses resolve.

---

### The four swept M2-round2 P3s — all four closed honestly

**Finding 7 — CLOSED.** `VerifyPosChainCommand.php:191` now renders `'unmeasured'` instead of
reprinting `count`, with the reasoning recorded at `:172-190` and R-1 preserved (zero Z-service
lines). The `int|string` widening at `:139` is display-only — `inspected` is consumed exactly once,
by `$this->table(...)` at `:201`; there is no JSON/automation consumer to break. Pinned by
`VerifyPosChainCommandTest.php:85-101`.

**Finding 8 — CLOSED.** `ReceiptHashService.php:561` adds `failed_chain_context` to the
`chain_verification_failed` log, with the per-context sequence-restart rationale at `:542-550`.
Pinned by a real `Log::spy()` assertion on the message, the event id, the context AND the failure
mode (`ReceiptChainRebuildTest.php:1007-1053`) — not a smoke test.

**Finding 9 — CLOSED.** `M2-implementation-evidence.md` corrects 5 → **8** in place, with a
per-test breakdown and an explicit statement that the original line understated the red surface.
Correcting rather than silently editing is the right call for this program's honesty lane.

**Finding 10 — CLOSED, correctly as a pin and not a fix.**
`ReceiptChainVerificationTest.php:244-337` builds a two-receipt fixture where receipt #2 is the only
divergence, so the honest `broken_at_sequence` is **2**, and asserts the endpoint's **1** on purpose
(`:334`). The docblock's mechanism is verified: `ReportController.php:473` seeds `$previousHash =
null`, every fiscal-era first receipt carries `genesis_seed`, so `:475` fires on iteration one. The
assertion goes red when someone fixes the controller, which is what a characterization pin is for.

---

### Standing checks

- **Hash shape — untouched.** No production line in the diff computes, formats or stores a hash;
  `hash('sha256', …)` appears only in test fixtures. No device-authored fact is re-authored: the
  ES-06 change is read-only over `canonical_bytes` and writes nothing anywhere.
- **Tenant scoping — holds.** `recoverSealedPayloadFromFrozenBytes()` is a pure string function; its
  caller `walkChain()` is already scoped by tenant + terminal + `chain_context` (`:389`), and the
  quarantine reporter scopes all three (`:853-855`).
- **No verdict weakening — verified by execution, not by reading** (see the ES-06 section: exit 1
  asserted on the suppression path, and the guard-disabled control run).
- **Constructor injection (rule 13) — holds.** No `app()` added to production code; `$this->app->make()`
  appears only in tests.
- **Rule 19 / precision — holds.** No arithmetic added. Money in the fixtures is string-typed
  (`'10.00'`, `'25.00'`); no `(float)`, `parseFloat`, `number_format` or `floatval` anywhere in the
  added lines; no scale resolution is reachable from this console path. No per-line TTC-vs-HT
  equality is asserted anywhere — the fixtures carry `unit_price` and `line_subtotal` as opaque
  strings compared as JSON, which is the correct treatment.
- **Rule 20 — holds.** No `onQueue` in the diff, so no `horizon.php` entry is owed; no migration; no
  `CompanyContext` dependency introduced; no SQLite boundary and no device shift re-hydration in
  scope.
- **Rule 8 — holds.** No event class renamed, restructured or deleted.
- **Treasury lens — DOES NOT APPLY**, same as the M2-round1 precedent. The diff touches two chain
  verifiers, one log key and their tests. No GL entry, no journal, no payment, no cash/drawer
  surface, no currency-scale resolution, no balance arithmetic. Applied and stood down explicitly
  rather than skipped silently.

### Verification I ran

```text
PG, phpunit-pgsql.xml, by path:
tests/Feature/Fiscal/ParseFailureResumeTest.php        OK (24 tests, 157 assertions)   [claim: 24/157 ✓]
tests/Feature/Fiscal/VerifyEventChainCommandTest.php   OK (34 tests, 138 assertions)   [claim: 34/138 ✓]

Controls (each reverted immediately, tree re-verified clean after):
round-trip guard -> if (false)   1 failed — "Output does not contain 'canonical payload could not be derived'"
non-ASCII name in the ES-06 fixture   blocked by PG bytea binding (see finding 1 sub-note);
                                      proven instead by reflection against the real CanonicalJsonEncoder
```

Both headline counts reproduce exactly. Never ran the full suite.

### Bypass attempts

| Attempt | Refuted by |
|---|---|
| Crafted payload making recovery claim a false match via key order / escapes / number formatting | Byte-identical round-trip (`:686`) + strict `===` over `ksort`ed arrays (`:707-727`); canonical grammar admits no fractions/exponents/non-canonical ints (`StrictCanonicalParser.php:506-550`). Every ambiguity class tested REFUSES. |
| Recovery used to soften the exit code | Sealed-coordinate incident is raised unconditionally at `:439-444` before `:465`; asserted as exit 1 in `ParseFailureResumeTest.php:274-278`. |
| Green manufactured by a fixture that the production path cannot produce | Fixture drives the real `ParseFailureResolutionService::resolve()` (`:872-873`) and self-asserts parser rejection, payload recoverability and post-resolution byte identity first. |
| Straggler work smuggled in under the "proposal" label | All three straggler files are 0-line diffs. |
| D-8 workflow smuggled in | `ParseFailureResolutionService.php` = 0 lines; no approver, no correcting event. |
| Z-arm behaviour changed under R-1 | `ZReportHashService.php` = 0 lines; finding 7 closed at the rendering site. |
| Evidence counts invented | Both headline files reproduced exactly on PG; the one count the evidence previously got wrong (5 vs 8) is corrected in place by finding 9. |

### Disposition
The scope gates all hold mechanically: D-8 is not reached, no straggler implementation landed, R-1
is intact at zero Z-service lines, and the contract proposal is genuinely committed inside the
reviewed range. The ES-06 detection change is well built — the sealed-coordinate incident stays
unconditional so no verdict can soften, the round-trip guard genuinely bites (I disabled it and
watched the dup-key envelope go silent), the comparison is strict rather than coercive, nothing
leaks the frozen bytes, and the red-first fixture drives the real resolution service and asserts its
own shape before it is trusted. The four swept M2 P3s are all closed honestly, including the
correction of an evidence count in place rather than by silent edit and a characterization pin that
deliberately records a wrong coordinate. Two items block the milestone. First, the recovery guard
re-encodes without `JSON_UNESCAPED_UNICODE` while production canonical bytes are RFC 8785 raw UTF-8,
so the new discriminating branch is unreachable for any receipt carrying an accented or Arabic
product, customer or seller name — fail-closed, so nothing hides and no verdict weakens, but on
France/Tunisia data the milestone's headline deliverable will mostly not fire and the evidence does
not disclose the limit; it is a one-flag fix plus a bytea-aware fixture. Second, the ES-16 contract
contradicts itself: clause 16-C instructs the operator toward
`fiscal:enqueue-resolved-event-projections`, which I verified applies no `integrity_status` filter
and re-checks no suppression, so it creates exactly the projections clause 16-D calls "wrong
aggregates" and F16-2 makes a falsifier — that clause must be amended or dropped before M3b builds
against it. ES-17's contract is approved as written and needs no amendment. Neither item is a
fiscal-integrity defect and neither warrants a wave STOP; both are fix-before-merge.

VERDICT: CHANGES-REQUIRED

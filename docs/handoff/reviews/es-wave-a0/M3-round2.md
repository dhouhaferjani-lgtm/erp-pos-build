## M3 adversarial review — ES-06 detection half + the ES-16/ES-17 straggler contract artifact, round 2

**Reviewed:** the fix-round delta `4a93f8631..b1d86e641` only (`7c836cee3` fix, `b1d86e641` ledger),
against the three round-1 findings in `docs/handoff/reviews/es-wave-a0/M3-round1.md`. Everything
outside the delta was accepted at round 1 and is not re-litigated here. HEAD verified
`b1d86e64196b060f8f7acacf348d53766da9e64f`, branch `codex/es-wave-a0`, tree clean before and after
every control.

**Test harness I used:** PostgreSQL, `apps/api/phpunit-pgsql.xml`,
`DB_DATABASE=autoerp_es_wave_a0_test` on `127.0.0.1:5432`, every file **by path**, never the full
suite. `apps/api/vendor` is a real directory in this worktree, so the runs execute THIS worktree's
production code.

---

### Delta scope — production surface is one file, verified mechanically

```text
git diff --name-only 4a93f8631..b1d86e641
  apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php
  apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php
  docs/handoff/progress/es-wave-a0.progress.yaml
  docs/handoff/reviews/es-wave-a0/M3-implementation-evidence.md
  docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md
```

Filtered against `apps/api/app|apps/web/src|apps/pos/src|apps/api/config|apps/api/database|apps/api/routes`,
the ONLY production path in the delta is `VerifyEventChainCommand.php` — 20 lines, of which 4 are
executable (the flag argument split across `:697-700`) and the rest are comments. The bytea harness
change is confined to the test file, as claimed. No migration, no route, no queue, no config.

The M3 scope gates re-checked across the WHOLE milestone range `325499fe3..b1d86e641` (not just
round 1's slice) — all still 0-line diffs:
`ParseFailureResolutionService.php` (D-8 not reached), `ZReportHashService.php` (R-1),
`DeadLetteredProjectionsController.php`, `FiscalEventQuarantine.php`, `OutboxIngestor.php`
(no straggler implementation), and `EnqueueResolvedEventProjectionsCommand.php` (the F-2 amendment
is a contract change, not a command change, exactly as the register instructed).

---

### F-1 — CLOSED. Verified by execution, including the red-first trio and a guard-disabled control

`VerifyEventChainCommand.php:697-700` now re-encodes with
`JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, and
`CanonicalJsonEncoder.php:108` is `json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)`
— the flag sets now mirror exactly. The docblock addition at `:663-665` and the inline rationale at
`:686-696` state the defect, the fix and the boundedness honestly (one over-broad sentence, N-2 below).

**Headline counts reproduce exactly, on PG, by path:**

```text
tests/Feature/Fiscal/ParseFailureResumeTest.php        OK (27 tests, 209 assertions)   [claim 27/209 ✓]
tests/Feature/Fiscal/VerifyEventChainCommandTest.php   OK (34 tests, 138 assertions)   [claim 34/138 ✓]
```

`VerifyEventChainCommandTest` reproduces its M3 count unchanged (34/138), confirming the flag
altered no existing behaviour — including the duplicate-key ambiguity guard.

**Default SQLite harness, both files in one run:** `61 passed (347 assertions)` = 27+34 / 209+138.
Both drivers agree test-for-test and assertion-for-assertion, so the stream (`PARAM_LOB`) binding
works on `blob` as well as `bytea`. The executor's claim reproduces exactly.

**Red-first trio — I ran the control myself, I did not take the transcript on trust.** I reverted
`:697-700` to the pre-fix flag pair, ran the three new tests on PG, and got the executor's exact
result:

```text
Tests: 3 failed (49 assertions)      [claim: 3 failed (49 assertions) ✓]

  faithful non-ASCII  → "canonical payload could not be derived (envelope_extra_field:device_firmware_note)"
  divergent non-ASCII → same generic sentence; the money rewrite was NOT named
  \uXXXX-escaped      → "1 chain incidents" and NO payload sentence at all
                        → recovery FIRED on bytes the canonical encoder does not produce
```

That third failure is the one that matters and it is genuinely red for the right reason: the
pre-fix guard accepted the escaped form and refused the raw form, i.e. exactly inverted against
production. Flag restored; `git status --porcelain` empty; HEAD re-verified `b1d86e641`.

**Static gates re-run, not quoted:** `pint --test` on both changed files → `{"result":"pass"}`;
`phpstan analyse` (level 8) on `VerifyEventChainCommand.php` → `[OK] No errors`.

#### Adversarial: can the widened flag produce a FALSE `RECOVERED` match?

Asked as instructed, and answered from the code plus probes against the real encoder. **No.**

| Vector | Result, established by probe |
|---|---|
| **Unicode normalization (NFC vs NFD)** | Both forms round-trip successfully (each is raw UTF-8), so recovery FIRES on either — but the frozen bytes uniquely determine the recovered payload, and the stored payload is then compared with strict `===` over `ksort`ed arrays (`semanticallyEqual` `:724-727`). Probe: `"e\u{0301}" === "\u{00e9}"` is **false** in PHP, and no layer normalizes (PHP does not; PG `jsonb` does not). A normalization-doctored stored payload is therefore NAMED as divergence, never absorbed. Fail-closed, and the sentence it prints is literally true. |
| **Escaped vs raw form in the BYTES** | Refused — re-encode emits raw, frozen bytes carry the escape, `$roundTrip !== $canonicalBytes` at `:705`. This is the third new test. |
| **Escaped vs raw form in the STORED `payload` column** | Collapses to equal, because `jsonb`/`json` decoding resolves `é` to `é` on both sides. That is CORRECT — the claim under test is *semantic* match, and those two are the same JSON string. |
| **Float precision (`0.1000000000000000000001` vs `0.1`)** | Cannot reach the comparison at all: the round-trip guard forecloses it BEFORE the payload is returned. Probe: `{"a":0.1000000000000000000001}` re-encodes to `{"a":0.1}` → bytes differ → REFUSED. Same for `1e2` → `100`, `1.0` → `1`, and bigint saturation `10000000000000000000000` → `1.0e+22`. Only floats already in shortest-round-trip form survive, and those decode uniquely. |
| **Two DIFFERENT payloads re-encoding identically** | Impossible for the *recovery* half — `decode` is a function and the guard demands `encode(decode(b)) === b`, so the recovered payload is uniquely determined by the bytes. The only equivalence class the *comparison* half collapses is JSON-list vs object-with-sequential-numeric-keys (`{"line_items":{"0":{…}}}` normalizes equal to `{"line_items":[{…}]}`), a PHP array-decode artifact. Probed and confirmed — but it cannot express a money rewrite (every scalar is still compared strictly: `"0" !== 0`, `0 !== 0.0`, both probed **false**), and it is a pre-existing property of `semanticallyEqual`, unchanged by this delta and already accepted at round 1. |

Direction of the change is the honest one: the only incident that can now DISAPPEAR is a payload
claim on a row whose payload is in fact faithful. The sealed-coordinate incident at `:439-444` is
still raised unconditionally before `:465` is reached, so exit 1 is untouched — asserted in the new
faithful-path test itself (`ParseFailureResumeTest.php:314-318`).

---

### F-2 — CLOSED. The amended ES-16 is internally consistent and M3b-buildable

`M3-straggler-contracts.md` is at revision 2 with a three-edit changelog at `:26-34`.

- **16-C (`:122`) is now a pure ABSENCE requirement** — "the surfaced row **names no recovery command
  at all**", demonstrated as "no remediation/recovery/next-action field naming
  `fiscal:enqueue-resolved-event-projections` or any other command … Asserted as an absence, not
  assumed." Option **(i)** taken, and the reasoning is recorded under the clause table (`:127-153`)
  so M3b cannot re-derive the rejected version.
- **Option (ii) is refused on the record** (`:143-146`), on the correct ground: adjudicating a
  suppressed fiscal row is D-8-adjacent and owes its own STOP.
- **F16-7 (`:171-176`) forecloses BOTH escapes** — re-introducing the affordance in row/copy/doc/log,
  *and* the tempting "make the command safe by adding the missing `integrity_status` precondition"
  move. `:147-153` restates the same as a hard `EnqueueResolvedEventProjectionsCommand.php` = 0-line
  requirement for M3b.
- **Defect-narrative item 5 (`:90-103`) corrected in place, labelled**, because it carried the same
  false "recovery works" premise 16-C was built on. Correcting rather than silently editing is the
  right call for this program's honesty lane.
- **No residual contradiction.** `grep -n 'enqueue-resolved|EnqueueResolved|recovery path|recovery works'`
  over the artifact returns 10 hits and every one is either the changelog, the corrected item 5,
  the amended 16-C, the amendment rationale, or F16-7 — nothing still routes an operator to the
  command.

**I re-verified the premise the amendment rests on, from code, not from the register.**
`EnqueueResolvedEventProjectionsCommand.php:244-245` is `->where('payload_parse_status', PayloadParseStatus::Parsed->value)`,
`:247-250` the optional `fiscal-event-id`, `:254` `$query->where('tenant_id', $tenantOption)` — there
is no `integrity_status` / `integrity_exception_class` filter anywhere; and `createMissingPendingRows()`
at `:340` inserts one pending row per active projector (`:345-365`) with no suppression re-check.
`OutboxIngestor.php:922` is exactly `if ($exceptionReason !== null && str_contains($exceptionReason, 'z_session_lifecycle:')) {` → `return;`.
So running that command on such a row does create the projections the ingestor refused. The
amendment's factual basis holds.

**Buildability.** 16-A/16-B remain positive, testable clauses; 16-C is an absence assertion over the
response payload (automatable) plus a reviewer-diff check over copy/help/log (F16-7 makes that a
falsifier, which is the right instrument for a non-automatable half). 16-D/16-E/16-F are unchanged
and unaffected. The contract no longer forbids and requires the same outcome. ES-17 is untouched and
stays approved as written.

---

### F-3 — CLOSED, verified exact

`DeadLetteredProjectionsController.php` (`app/Modules/Fiscal/Presentation/Controllers/`):
`:104` = `if ($projectorFilter === null) {`, `:106` = `->where('tenant_id', $user->tenant_id)`,
`:107` = `->where('integrity_exception_class', 'canonical_parse_failure')`. Clause 16-F now cites
`:106`; the revision-2 changelog's restatement of `:104` is also exact.

---

### The bytea harness change — harness-only, mechanism verified

`byteaBinding()` (`ParseFailureResumeTest.php:1189-1211`) wraps the bytes in a `php://memory` stream.
The mechanism the docblock claims is real: `vendor/laravel/framework/src/Illuminate/Database/Connection.php:739`
is `is_resource($value) => PDO::PARAM_LOB`, so a stream binds as a LOB and a plain string does not.
`canonical_bytes` is `bytea` on PG (confirmed from the live `\d fiscal_events`), and the model
declares it a plain `string` property with no cast (`FiscalEvent.php:43`, `:114`).

`current_hash` still hashes the STRING, not the stream (`ParseFailureResumeTest.php:1070`
`hash('sha256', $canonicalBytes)`), so the fixture's hash stays correct — I checked, because binding
a consumed stream there would have silently changed what the fixture seals.

**I independently reproduced the evidence's probe table** against PG 16 on the same connection,
with raw PDO on a temp `bytea` table:

```text
                                                 PARAM_STR   PARAM_LOB
raw-ascii        {"name":"Cafe creme"}            OK          OK (identical)
accented-raw     {"name":"Café crème"}            OK          OK (identical)
escaped-unicode  {"name":"Caf\u00e9"}         FAIL 22P02  OK (identical)
escaped-quote    {"name":"He said \"hi\""}        FAIL 22P02  OK (identical)
```

Row-for-row identical to `M3-implementation-evidence.md:187-192`. The root-cause narrative — "it is a
BACKSLASH problem, not a non-ASCII problem" — is correct, and it correctly retires round 1's own
sub-note, which had mis-attributed the 22P02 to non-ASCII.

---

### The §1.6 disclosure and the out-of-scope observation — honest, citations resolve

§1.6 (`M3-implementation-evidence.md:151-238`) discloses the F-1 limit in its own words, names the
non-disclosure as "the more serious half", states the failure mode was fail-closed without using
that to minimise it, and transcribes the red-first trio. The fix-round answer table at `:24-28` maps
F-1/F-2/F-3 to where each is answered. The ledger entry (`es-wave-a0.progress.yaml:105-136`) is
accurate: `fix_rounds: 1`, `commit: 7c836cee3`, `last_verdict: CHANGES-REQUIRED`, and the prose
matches what the code actually does.

**The out-of-scope observation at `:204-210` is TRUE, its citations resolve, and it is correctly
recorded rather than fixed (rule 4).** `OutboxIngestor.php:815` is
`'canonical_bytes' => $envelope->canonicalBytes` — a plain string through Eloquent, hence `PARAM_STR`;
`grep -rn "PARAM_LOB\|pg_escape_bytea" app/` returns **nothing**. Combined with my probe above, a
device-sealed envelope carrying a `"` or `\` inside operator free text (RFC 8785 emits `\"`) is a
byte shape that fails to INSERT on PG. That is a fiscal-ingestion rejection path, not a display bug.
It is squarely outside M3 and must not be fixed here — **but it should not die in an evidence
footnote.** Escalated below as a wave-level owed ticket. (I verified it at the driver/binding layer;
end-to-end confirmation through `OutboxIngestor` is owed by whoever picks it up.)

---

### Standing checks on the delta

- **Hash shape — untouched.** The only executable production change is a `json_encode` flag inside a
  read-only recovery helper. Nothing computes, formats, stores or re-authors a hash; no device-signed
  fact is re-authored; the helper writes nothing.
- **Fiscal chain / verdict — no softening.** `:439-444` still raises the sealed-coordinate incident
  unconditionally before `:465`. Asserted as exit 1 in all three new tests.
- **Rule 19 / precision — holds.** `grep` over every added line for `(float)`, `parseFloat`,
  `floatval`, `number_format` → **none**. Money in the new fixtures is string-typed (`'10.00'`,
  `'25.00'`) and is only ever compared as JSON, never arithmetic. No scale resolution is reachable
  from this console path. No per-line TTC-vs-HT equality assertion is added — the non-ASCII payload
  builders only rewrite free-text `name` / `cashier_name` / `seller.*` fields.
- **Rule 20 — holds.** No `onQueue` in the delta, so no `horizon.php` entry is owed; no migration;
  no `CompanyContext` dependency introduced; no SQLite TEXT-timestamp boundary and no device shift
  re-hydration in scope.
- **Rule 13 — holds.** No `app()` added to production code.
- **Rule 8 — holds.** No event class renamed, restructured or deleted.
- **Test quality — real.** Zero `assertTrue(true)`; every new assertion carries a failure message
  stating the fiscal consequence; the fixtures drive the real `ParseFailureResolutionService::resolve()`
  and the real command through `runVerifierCapturingOutput()`; nothing under test is mocked.
- **SQLite-masking lens — applied.** The delta's fiscal logic was run on BOTH drivers with identical
  results (61/347 = 27/209 + 34/138), so nothing here is exercised only under SQLite.
- **Treasury lens — DOES NOT APPLY**, same as rounds 1 and 2 of M2. One `json_encode` flag in a chain
  verifier. No GL entry, no journal, no payment, no cash/drawer surface, no currency-scale
  resolution, no balance arithmetic. Applied and stood down explicitly rather than skipped silently.

---

### Findings — three P3s, no blocker

**N-1 (P3) — `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php:299` — citation drift the fix
round introduced into its own explanation.** The comment says "the byte-identity test at
`VerifyEventChainCommand.php:687`". `:687` is a COMMENT line of the new flag-set block
(`// (CanonicalJsonEncoder.php:106-108): RFC 8785 §3.2.3 seals U+0080+ as`); the byte-identity test
is `if ($roundTrip !== $canonicalBytes) {` at **`:705`**. The address was correct against the
pre-fix file and the fix's own 18-line comment insertion moved it. This is the exact class of defect
round 1 raised as F-3, reintroduced one commit later, in the sentence explaining the fix.
**Fix:** `:687` → `:705`.

**N-2 (P3) — `apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:663-665`
— the new docblock's boundedness claim is over-broad in both directions.** It states: "The re-encode
uses the CANONICAL flag set …, so any non-canonical byte form — `\uXXXX` escapes, insignificant
whitespace, escaped slashes — also returns null." Two probed counterexamples:

1. **A `\uXXXX` escape is not per se non-canonical.** PHP's `JSON_UNESCAPED_UNICODE` still escapes
   U+2028/U+2029 unless `JSON_UNESCAPED_LINE_TERMINATORS` is added, so the REAL encoder emits them
   escaped — probed against the real class: `(new CanonicalJsonEncoder)->encode(['a' => "\u{2028}"])`
   → `{"a":"\u2028"}`. Frozen bytes `{"a":"\u2028"}` round-trip identically through the guard and
   therefore **RECOVER**. The code is right (it mirrors the encoder, which is the whole invariant);
   the sentence is wrong.
2. **A canonical form that DOES return null:** `{}`. `CanonicalJsonEncoder::encode([])` emits `{}`
   deliberately — that is why `encode()`/`encodeList()` are split at all (`CanonicalJsonEncoder.php:13-16`).
   The guard cannot reproduce it: `json_decode('{}', true)` → `[]` → re-encodes as `[]`, so any
   envelope carrying an empty JSON object anywhere REFUSES. Fail-closed, and narrow (the strict
   parser requires non-empty sub-objects, `StrictCanonicalParser.php:44-49`), but it is a residual
   sibling of F-1: a byte form the encoder CAN emit on which the discriminating branch stays dead.

Neither changes a verdict and neither is a fix-round regression in behaviour — but the docblock's
converse reading ("canonical forms recover") is what a future maintainer will rely on, and it is
false. The same over-broad phrasing is repeated at `ParseFailureResumeTest.php:340-343` ("a form the
canonical encoder can never emit"). **Fix:** narrow the claim to what is actually true and testable —
"byte forms this PHP re-encode cannot reproduce (including `\uXXXX` escapes of U+0080+, insignificant
whitespace, escaped slashes, and empty JSON objects) return null" — and, if the empty-object case is
judged worth closing rather than documenting, that is M3b/straggler work, not this round's.

*(Adjacent, pre-existing, explicitly NOT a finding against this delta: `CanonicalJsonEncoder.php:98-104`
warns of a "PHP-vs-JS divergence" because PHP supposedly emits U+2028/U+2029 raw. Probed on PHP 8.4:
PHP escapes them, so the documented divergence does not exist. Out of scope, recorded for the lane
that owns that file — and it is the docblock the delta cites as its authority.)*

**N-3 (P3) — `docs/handoff/reviews/es-wave-a0/M3-implementation-evidence.md:245-246` — stale count in
the file the fix round amended.** It still reads "6 numbered clauses + 6 falsifiers for ES-16".
Revision 2 added F16-7, so ES-16 now has 6 clauses and **7** falsifiers (F16-1…F16-7 confirmed by
grep). Trivial arithmetic, but round-1 finding 9 was *precisely* an understated count in an evidence
file, and §2.1 was appended four lines below this sentence without correcting it. **Fix:** 6 → 7.
(Cosmetic rider: F16-7 is inserted between F16-5 and F16-6 in the falsifier list; renumber or
reorder so the list reads in sequence.)

---

### Escalated, NOT a finding against M3 — owed as a wave-level ticket

**`OutboxIngestor.php:815` binds `canonical_bytes` (PG `bytea`) as `PDO::PARAM_STR`.** Reproduced at
the driver layer on PG 16: any canonical envelope containing a backslash escape — which RFC 8785
emits for a `"` or `\` inside operator-typed free text such as a product name — fails to insert with
`SQLSTATE[22P02] invalid input syntax for type bytea`. No `PDO::PARAM_LOB` and no `pg_escape_bytea`
exists anywhere in `app/`. The executor found this while fixing the harness, correctly refused to
fix it under rule 4, and disclosed it. It is a device-authored fiscal event failing to land, so it
should not remain an evidence footnote: **orchestrator to raise it against the ingestion lane with
the probe above attached.** M3 must not be held for it.

---

### Bypass attempts on the delta

| Attempt | Refuted by |
|---|---|
| Fix claimed but flag not actually present | Read at `VerifyEventChainCommand.php:697-700`; and the control revert flipped all three tests red, so the flag is load-bearing |
| Red-first transcript fabricated | Reproduced independently: `3 failed (49 assertions)`, matching the evidence character-for-character, including the third test's "1 chain incidents" no-payload-sentence output |
| Green bought by weakening an existing test | `VerifyEventChainCommandTest` reproduces 34/138 unchanged; `ParseFailureResumeTest` moves 24→27 / 157→209, i.e. purely additive |
| Widening the flag made the guard lenient | Third new test pins the refusal direction; probes show floats, exponents, bigints, whitespace and escaped forms all still REFUSE |
| Production code smuggled in under a "harness-only" label | `git diff --name-only` over the range: exactly one file under `apps/api/app/`, 4 executable lines, all inside the one helper |
| Straggler / D-8 / R-1 work smuggled in during the fix round | All six gate files 0-line across the FULL range `325499fe3..b1d86e641`, not just round 1's slice |
| F-2 "amended" on paper while the affordance survives elsewhere | Full-artifact grep for the command name: every hit is changelog, correction, amended clause, rationale or falsifier |
| Evidence quietly rewritten rather than corrected | Both corrections (item 5, §1.6) are labelled in place with the original error named; the one thing NOT corrected is the falsifier count, raised as N-3 |

---

### Disposition

All three round-1 findings are closed, and closed for the right reasons rather than by assertion.
F-1's one-flag fix is real and load-bearing — I reverted it and watched all three new tests go red
with the executor's exact counts, including the sharpest of them, which shows the pre-fix guard
accepting precisely the byte form production never emits and refusing the form it always emits. The
widening is bounded in the only direction that matters: I could not construct a false `RECOVERED`
match from unicode normalization, escaped-vs-raw form, float precision or key order, because the
byte-identical round-trip makes the recovered payload a function of the frozen bytes and the
comparison that follows is strict `===` over `ksort`ed arrays with no coercion. NFC-vs-NFD
divergence is named, not absorbed. The sealed-coordinate incident remains unconditional, so exit 1
never softens. F-2's amendment takes option (i) cleanly, records why option (ii) is refused, and adds
F16-7 to foreclose both the re-introduction and the "fix the command instead" escape; I re-derived
its factual premise from `EnqueueResolvedEventProjectionsCommand.php` and `OutboxIngestor.php:922`
and it holds, the artifact carries no residual contradiction, and the amended ES-16 is buildable as
written. F-3 resolves exact. The bytea change is harness-only — one production file in the whole
delta, four executable lines — and the §1.6 disclosure is honest, with its probe table reproducing
row-for-row on my own PG and its out-of-scope observation's citations all resolving. Both headline
counts reproduce (27/209, 34/138) and both drivers agree (61/347). What remains is three P3s, all of
them accuracy rather than behaviour: a citation the fix round's own edit invalidated, a docblock
sentence that is over-broad in both directions (`\u2028` escapes ARE canonical and recover; `{}` IS
canonical and refuses), and a falsifier count left at 6 when it became 7. None of them changes a
verdict, a byte, or a fiscal fact. They are the same class the wave has been sweeping forward, and
they should be swept into M3b rather than spending another round. Separately, the `PARAM_STR`
`bytea` binding in `OutboxIngestor` is a real ingestion-rejection path that the executor was right
not to touch here and that the orchestrator should ticket immediately.

VERDICT: ACCEPT

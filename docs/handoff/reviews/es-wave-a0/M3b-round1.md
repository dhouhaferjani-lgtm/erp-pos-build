## M3b adversarial review — ES-16 / ES-17 straggler IMPLEMENTATION, round 1

**Reviewed:** `32671a9f6..4a2aa3637` (`f28306baf` ledger + the three swept M3 round-2 P3s,
`dfa58a418` implementation, `4a2aa3637` ledger). HEAD verified
`4a2aa3637ecc09ebb566599d5f0a97feb3b1c6be`, branch `codex/es-wave-a0`, tree clean before and after
every control I ran.

**Contract of record:** `docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md` at **revision 2a**
— ES-16 = clauses 16-A…16-F + falsifiers F16-1…F16-7; ES-17 = clauses 17-A…17-G +
F17-1…F17-7. Revision 2a's only edit over the ACCEPTed revision 2 is the N-3 cosmetic reorder,
verified as presentation-only by diff (`M3-straggler-contracts.md`, F16-6 moved above F16-7, no
falsifier text altered, no clause touched). The artifact M3b built to IS the artifact M3 round 2
approved.

**Harness:** PostgreSQL, `apps/api/phpunit-pgsql.xml`, `DB_DATABASE=autoerp_es_wave_a0_test` on
`127.0.0.1:5432`, every file **by path**, never the full suite. `apps/api/vendor` is a real
directory in this worktree, so the runs execute THIS worktree's production code.

---

### 1. Scope — measured, not accepted

```text
git diff --name-only 32671a9f6..4a2aa3637
  apps/api/app/Modules/Fiscal/Application/Services/QuarantineIncidentResolutionService.php   (new)
  apps/api/app/Modules/Fiscal/Domain/Exceptions/QuarantineAlreadyResolvedException.php       (new)
  apps/api/app/Modules/Fiscal/Domain/Exceptions/QuarantineIncidentNotFoundException.php      (new)
  apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php            (comment only)
  apps/api/app/Modules/Fiscal/Presentation/Controllers/DeadLetteredProjectionsController.php
  apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineIncidentResolutionController.php (new)
  apps/api/app/Modules/Fiscal/routes.php
  + 3 test files, 4 docs
```

No migration, no `onQueue` (grep over the whole range: the only three hits are prose in the ledger
and the evidence, so no `config/horizon.php` entry is owed — rule 20 holds), no config, no
front-end, no permission/seeder/`permission:cache-reset`. `VerifyEventChainCommand.php`'s diff is
the N-2 docblock rewrite only — I read the hunk: 24 added lines, all inside the
`recoverSealedPayloadFromFrozenBytes()` docblock, zero executable lines.

**Zero-line gates — all five hold** (`git diff --numstat` returns no entry for any of them over the
full range):

```text
0  apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/ZReportHashService.php              (R-1)
0  apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php                    (16-D, and :815 PARAM_STR untouched)
0  apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php (F16-7)
0  apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php     (F16-6 / D-8)
0  apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php                    (17-B / F17-3)
```

---

### 2. ES-16 — clause by clause, against the code

| Clause | Verified at | Verdict |
|---|---|---|
| 16-A row appears in `index()` | third partition `DeadLetteredProjectionsController.php:152-168`, formatted at `:309-327` | **MET**, red-first reproduced (§5) |
| 16-B names WHICH violation | `lifecycle_violation` at `:322`, extracted by `extractLifecycleViolation()` `:345-358` — regex `preg_quote(prefix)([a-z0-9_]+)` read out of `integrity_exception_reason`, returns null rather than guessing | **MET**; two-violation + `assertNotSame` at test `:208-222` kills F16-4 |
| 16-C names NO recovery command | `formatZSessionLifecycleRow()` `:313-326` returns 12 keys: `source, fiscal_event_id, event_type, terminal_id, chain_context, projector_name(null), integrity_exception_class, integrity_exception_reason, lifecycle_violation, server_received_at, session_id, shift_id`. **No `write_off_action_url`, no `remediation`/`recovery`/`next_action`/`action_url`/`resolve_url`/`command`, no `refund_amount`.** I enumerated the array literal myself rather than trusting the test | **MET** |
| 16-D no ingestion decision changes | `OutboxIngestor.php` 0-line | **MET** |
| 16-E existing partitions exact | partition 1 (`:94-127`) and partition 2 (`:133-146`) byte-unchanged; `formatDeadLetteredRow()`/`formatQuarantineRow()` unchanged | **MET**; `DeadLetteredProjectionsControllerTest` reproduces **12 tests / 33 assertions** on my run |
| 16-F tenant-scoped | `->where('tenant_id', $user->tenant_id)` at `:153`, same as partition 2's `:134` | **MET**, foreign-tenant row driven through the real ingestor |

**Falsifiers.** F16-1: partition 2's `canonical_parse_failure` predicate at `:135` is untouched and
the new partition is a separate query — the additive test requires **exactly one**
`ingress_quarantine` row while a `sequence_gap` lifecycle row is present, so a widened partition 2
would fail it. F16-2: `OutboxIngestor` 0-line, and the 16-A test asserts the projection count for
the row is still 0 (`test:167-171`). F16-3: every lifecycle fixture goes through
`$this->app->make(OutboxIngestor::class)->ingest(...)` and `assertIngestedLifecycleQuarantine()`
(`test:470-490`) re-asserts `sequence_gap` + the named violation, so the fixture fails loudly if the
ingestor stops producing the shape; the only hand-inserted rows are the two existing partitions'
controls, where they are the control. F16-4/F16-5/F16-6/F16-7: closed as above.
**No falsifier is tripped.**

#### The coupling question, ruled

The partition matches on the same string the suppression matches on — but the coupling is a
**duplicated literal, not a shared constant**. `OutboxIngestor.php:922` hardcodes
`'z_session_lifecycle:'`; the seven producers hardcode it again at `:521, :526, :533, :538, :542,
:548, :551`; the controller declares its own `private const Z_SESSION_LIFECYCLE_PREFIX` at
`DeadLetteredProjectionsController.php:78`. A reworded verdict prefix in the ingestor therefore
empties the partition while the suppression continues.

**Ruling: WITHIN CONTRACT.** No F16-* names a shared constant, and 16-D's zero-diff on
`OutboxIngestor` actively forbids M3b from introducing one. The fragility is real at the source
level but it is **not silent**: `ZSessionLifecycleQuarantineVisibilityTest` seeds through the real
ingestor and its fixture guard (`:483-487`) asserts `z_session_lifecycle:<violation>` inside
`integrity_exception_reason`, so a reword turns 5 of the 7 tests red — which I reproduced
empirically by removing the partition (`Tests: 7, Assertions: 64, Failures: 5`, §5). Recorded as
**F-5** for the next lane that legitimately opens `OutboxIngestor`.

---

### 3. ES-17 — clause by clause, against the code

The writer is `QuarantineIncidentResolutionService::resolve()` (`:80-116`): one
`$this->db->transaction()`, one `lockForUpdate()` read scoped by `id` + `tenant_id` (`:84-88`), one
`->update()` naming **exactly two columns** (`:109-114`). Mirrors the already-reviewed sibling
`ParseFailureResolutionService` (Eloquent `::query()->lockForUpdate()` read, `$this->db->table()`
write, both inside `$this->db->transaction()`), so the lock and the write are on one connection.

| Clause | Verified at | Verdict |
|---|---|---|
| 17-A both columns, one write | `:109-114` — `resolved_at` + `resolved_by` in a single `update()` | **MET**, red-first (404) reproduced by the executor and consistent with the route's absence at `32671a9f6` |
| 17-B explicit lifecycle code, never mass assignment | `FiscalEventQuarantine.php` 0-line; `$fillable` (`:93-119`) still omits both; the boundary docblock is `:84-92` | **MET** — and the test proves it at RUNTIME (`fill()`+`save()` persists nothing), not just on paper |
| 17-C verifier exits 1 → 0 | writer only; the predicate `whereNull('resolved_at')` in `reportQuarantineIncidents()` is **untouched** | **MET** (see the F17-4 control below) |
| 17-D changes nothing else | 14 quarantine columns compared byte-for-byte with `stringifyColumn()` handling PG `bytea` stream handles; `fiscal_events` count pinned; the conflicting event's 5 columns compared | **MET** |
| 17-E gated by an EXISTING seeded permission | `routes.php:43-45`, `->middleware('can:fiscal.events.resolve_quarantine')`, inside the existing `['api','auth:sanctum',SetPermissionsTeam,EnforceTokenTenantClaim]` group; the permission is seeded at `RolesAndPermissionsSeeder.php:465` (I confirmed the line) and already gates the two sibling quarantine routes at `routes.php:33-36` | **MET**; STOP correctly does not fire; refusal asserted two-sided (403 **and** both columns still NULL) |
| 17-F tenant-scoped | controller `:61-73` + service re-check under the lock `:87`; cross-tenant reported as 404, never as "already resolved", with a distinct exception type so one tenant is never told a true thing about another's incident | **MET**, with a positive control in the same test |
| 17-G non-destructive re-stamp | `:97-103` → `QuarantineAlreadyResolvedException` → 409 `QUARANTINE_ALREADY_RESOLVED` | **MET**; a *different* authorised operator gets 409 and the original stamp survives |

**F17-4 is the clause I pushed hardest on, because it is the one a lazy implementation buys instead
of earning.** It was earned: `VerifyEventChainCommand.php` carries no ES-17 change, and
`test_an_unresolved_sibling_incident_still_fails_the_verifier_after_one_is_stamped` (`:271-287`)
stamps one of two incidents and asserts the command still exits 1, still names
`claimed_sequence_number 3`, and no longer names `2`. A blinded verifier passes 17-C and fails that.

#### Can the stamp hide a REAL divergence?

Traced, not assumed:

- **Resolution suppresses only the incident list, not the chain checks.**
  `reportQuarantineIncidents()` (`VerifyEventChainCommand.php:888-921`) is the ONLY consumer of
  `resolved_at` in the verifier. The hash-linkage / sequence / sealed-coordinate walk over
  `fiscal_events` is a separate path and is untouched by the stamp. The sibling-incident control
  above is the behavioural proof.
- **No evidence is destroyed.** The quarantine row is never deleted, and NF525 §8 exports it
  **regardless of resolution** — `Nf525DataProvider::buildQuarantineSection()` selects the whole
  partition by `company_id` + `server_received_at` window with no `resolved_at` filter
  (`app/Modules/POS/Application/Services/Nf525DataProvider.php:1559-1578`). An adjudicated
  `sequence_conflict` envelope still appears in the auditor's export with its class, reason, hashes
  and raw envelope.
- **What the stamp records is thinner than the contract believed.** Actor + timestamp, nothing else.
  No reason, no log line (**F-4**), and `resolved_by` is **not** in the §8 export — only
  `resolved_at` is (`:1571`, `:1603-1605`; `resolved_by` appears nowhere in that file). That
  contradicts the claim repeated in `QuarantineAlreadyResolvedException.php:15-18` and three test
  messages (**F-3**).

Net: an authorised operator CAN turn a permanently-red terminal green by adjudication — which is the
whole point of ES-17 and exactly what 17-A prescribes — and cannot thereby erase the envelope, alter
the chain, admit anything into `fiscal_events`, or blind the verifier to anything else. The residual
is accountability thinness, not verification theater.

---

### 4. The disclosed residual — `show()`. **RULING: WITHIN CONTRACT.**

Confirmed in code first: `DeadLetteredProjectionsController::show()` resolves the event, finds no
dead-lettered projection row, tests `integrity_exception_class === 'canonical_parse_failure'`
(`:218`) — which a lifecycle row fails, its class being `sequence_gap` — and falls through to
`409 NOT_DEAD_LETTERED` at `:224-229`. The disclosure is accurate.

The approved contract's exact words: clause **16-A** reads *"A `z_session_lifecycle` quarantine
appears in `DeadLetteredProjectionsController::index()`'s response"*, and the clause table is
prefaced *"Clause by clause — what M3b must **demonstrate**"*. `show()` is named nowhere in
16-A…16-F or F16-1…F16-7. The headline sentence's *"the existing dead-letter read surface"* is the
intent statement, not a demonstrable clause, and the milestone's own governing rule — *"implementing
beyond … the approved contract is a milestone failure"* — cuts against extending a second endpoint
on a headline reading.

I decline to amend, for a reason beyond formalism: **the detail endpoint would carry no information
the list row lacks.** The lifecycle row already surfaces `chain_context`, `integrity_exception_class`,
`integrity_exception_reason`, `lifecycle_violation`, `session_id`, `shift_id` and
`server_received_at`. The residual is a deep-link dead end for a UI that does not exist yet, not an
information gap. **Recorded as a ledger residual (F-8), not a fix round.** If and when a detail view
is built, `show()` must return `formatZSessionLifecycleRow()` — including its deliberate absence of
any action affordance — and whoever builds it inherits 16-C.

---

### 5. Verification I ran myself

**Green, PG, by path — all four counts reproduce exactly:**

```text
ZSessionLifecycleQuarantineVisibilityTest   OK (7 tests, 91 assertions)     [claimed 7/91  ✓]
FiscalEventQuarantineResolutionTest         OK (10 tests, 69 assertions)    [claimed 10/69 ✓]
ParseFailureResumeTest                      OK (27 tests, 209 assertions)   [claimed 27/209 ✓]
VerifyEventChainCommandTest                 OK (34 tests, 138 assertions)   [claimed 34/138 ✓]
DeadLetteredProjectionsControllerTest       OK (12 tests, 33 assertions)    [16-E / F16-5 ✓]
```

**Red-first spot-check, ES-16 (the red-first class).** I reverted
`DeadLetteredProjectionsController.php` to `32671a9f6`, re-ran the file, and restored it
(`git status --porcelain` empty afterwards). Result: **`Tests: 7, Assertions: 64, Failures: 5`** —
character-for-character the evidence's recorded red block, same five tests, same messages
("Failed asserting that an array contains …", "actual size 2 matches expected size 3"). The two
tests the evidence **discloses as guards rather than red-first** —
`test_projector_filter_excludes_the_lifecycle_partition` and
`test_index_denies_a_user_without_the_permission` — are exactly the two that stayed green
(`FFFF.F.`). The disclosure is honest and the reds are the row being ABSENT, i.e. the blind spot
itself.

**Inherited-red spot-check (the guard class).** `QuarantineBestEffortParseControllerTest` runs
`Tests: 7, Assertions: 10, Errors: 3`, and the cause is the test's OWN fixture at `:189` tripping
the PG trigger: *"integrity_exception_class is write-once; once set on a quarantined row it cannot
be changed"*. That file is untouched by this range and no M3b code path is on the stack.
`tests/Architecture/ControllerTenantContextTest` fails on 1 of 3 — and I grepped the failure list:
**no Fiscal controller appears in it**, so the new `QuarantineIncidentResolutionController::store` is
correctly classified (it satisfies the heuristic via `$request->user` + `tenant_id`). Both inherited
claims check out.

**Static gates, re-run:** `pint --test` on all 9 changed/new files → `{"result":"pass"}`;
`phpstan` level 8 on all 7 production files → `[OK] No errors`.

**Swept P3s:** N-1 applied at `ParseFailureResumeTest.php:299` (`:687`→`:705`) — **but see F-2, the
correction is stale in the commit that made it**. N-2 applied at
`VerifyEventChainCommand.php:663-687`, and it is substantively right: it names the U+2028/U+2029
recovery case and the `{}` refusal case — **but see F-7, the falsified absolute claim survives 30
lines below it**. N-3 applied (`6`→`7` falsifiers, corrected in place with the error named) plus the
2a reorder. **U+2028 literal check:** byte-level `LC_ALL=C grep` for `\xe2\x80\xa8` / `\xe2\x80\xa9`
returns **0** in `VerifyEventChainCommand.php`, `ParseFailureResumeTest.php` and
`DeadLetteredProjectionsController.php`, at both `32671a9f6` and HEAD, and the entire range diff
contains **0** occurrences. The only raw hit in the repo is the pre-existing golden-vector fixture
`tests/Fixtures/Fiscal/canonical-golden-vectors.json:193`, which is deliberate. No stray literal
remains.

---

### 6. Standing checks

- **Rule 19 / precision — N/A, verified.** Grep over the range's `apps/api/app/` additions for
  `(float)`, `parseFloat`, `floatval`, `number_format`, `bcadd`, `bcmul`, `getScale`: **zero hits**.
  The ES-16 row surfaces no amount at all (`refund_amount` deliberately omitted — correct: it is the
  refund write-off surface's field and a lifecycle-invalid Z session has no write-off). The ES-17
  UPDATE writes a timestamp and a user id. No scale resolution is reachable, so the no-arg
  `getScale()` trap is not in play.
- **Rule 20 — holds.** No `onQueue` anywhere in the range, so no `horizon.php` entry is owed. No
  migration. No `CompanyContext` dependency introduced; neither new class resolves one. No SQLite
  TEXT-timestamp boundary and no device shift re-hydration in scope.
- **Rule 13 — holds.** No `app()` in any of the three production classes; both new classes use
  constructor injection with `private readonly`.
- **Rule 12 — holds.** The new route joins the existing middleware group verbatim and adds a `can:`
  gate identical in shape to its two siblings (`routes.php:33-36` vs `:43-45`).
- **Rule 8 — holds.** No event class renamed, restructured or deleted; nothing emitted or consumed.
- **Fiscal source-of-truth — holds.** Nothing re-authors a device-signed fact. ES-16 is a read
  partition. ES-17 writes two lifecycle columns on the non-admissible quarantine table, never on
  `fiscal_events`; the 17-D test compares the conflicting event's `canonical_bytes`, `current_hash`,
  `previous_hash`, `sequence_number` and `integrity_status` across the stamp.
- **Hash shape — untouched.** No hash computed, formatted, stored or re-derived.
- **Test quality.** Zero `assertTrue(true)`; the two `assertTrue` calls in the ES-17 file both assert
  real facts (permission seeded, principal can hold it). Every assertion carries a failure message
  naming the fiscal consequence. Nothing under test is mocked — ES-16 drives the real
  `OutboxIngestor`, ES-17 drives the real `fiscal:verify-event-chain` via `Artisan::call`. Both files
  use `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`. Both were run on PG, so the
  SQLite-masking risk is retired for this delta.
- **Treasury lens — APPLIED, STOOD DOWN.** No GL entry, no journal, no payment, no drawer/cash
  surface, no balance arithmetic, no currency-scale resolution. One read partition and one
  two-column lifecycle stamp. Treasury does **not** apply to this milestone.

---

### 7. Bypass attempts

| Attempt | Refuted by |
|---|---|
| ES-16 visibility bought by widening partition 2 | Predicate at `:135` byte-unchanged; new partition is a separate query; additive test requires exactly one `ingress_quarantine` row while a lifecycle row is present |
| Red-first transcript fabricated | Reproduced independently by reverting the controller: `7 tests, 64 assertions, 5 failures`, same tests, same messages, and the two disclosed guards are exactly the two that stayed green |
| 16-C satisfied by renaming the action field | Enumerated the returned array literal at `:313-326` myself — 12 keys, none actionable — rather than relying on the test's key list |
| 16-B satisfied by a constant | `extractLifecycleViolation()` reads via regex out of `integrity_exception_reason`; two violations produce two values plus an explicit `assertNotSame` |
| 17-C bought by blinding the verifier | `VerifyEventChainCommand.php` has no ES-17 change; sibling-incident control still exits 1 and still names `claimed_sequence_number 3` |
| 17-B satisfied on paper | `FiscalEventQuarantine.php` 0-line diff, and the runtime `fill()`+`save()` assertion proves the boundary holds in execution |
| Refusal asserted on status only | Both the 403 and the cross-tenant 404 tests re-read the row and assert both columns still NULL |
| A new permission smuggled in as "existing" | Confirmed `fiscal.events.resolve_quarantine` at `RolesAndPermissionsSeeder.php:465` and that it already gates `routes.php:33-36` |
| Straggler scope creep into forbidden files | All five gate files 0-line over the FULL range, including `EnqueueResolvedEventProjectionsCommand` (F16-7) and `OutboxIngestor` (16-D + the escalated `:815` PARAM_STR ticket) |
| Green bought by weakening an existing test | `DeadLetteredProjectionsControllerTest` 12/33 unchanged; both M3 headline files reproduce 27/209 and 34/138 |
| Inherited reds used to launder a new red | Spot-verified: the 3 `QuarantineBestEffortParse` errors are its own fixture tripping the write-once trigger, and no Fiscal controller appears in the Architecture failure list |

---

### Findings — seven, none blocking

**F-1 (P2) — `VerifyEventChainCommand.php:856` no longer exists; the seam is `:896`.** The
`whereNull('resolved_at')` predicate is at `app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:896`
(inside `reportQuarantineIncidents()`, `:888-921`). `:856` is now a **blank line**
inside `resolveExpectedPreviousHash()` (between the prior-row query at `:850-855` and its null check at
`:857`) — the citation resolves to nothing at all. The +22-line docblock insertion in **this same range** moved it.
The dead address is asserted as fact in six places M3b wrote or left standing:
`QuarantineIncidentResolutionService.php:23`, `QuarantineIncidentResolutionController.php:22`,
`routes.php:39`, `FiscalEventQuarantineResolutionTest.php:40` and `:233`, plus
`M3b-implementation-evidence.md` §2/§4 and the YAML M3b row. **Fix:** `856` → `896`, and stop citing
mutable line numbers in this file — cite `reportQuarantineIncidents()`'s `whereNull('resolved_at')`.

**F-2 (P2) — the N-1 sweep is stale in the commit that performed it, so N-1 is NOT closed.**
`ParseFailureResumeTest.php:299` was corrected `:687` → `:705`. But `f28306baf` also added 22 lines
to the docblock ABOVE that code in the same file, so the byte-identity test
`if ($roundTrip !== $canonicalBytes) {` now sits at **`VerifyEventChainCommand.php:727`**, not
`:705`. This is the **third** consecutive occurrence of one defect class — round-1 F-3, round-2 N-1,
now the fix for N-1 itself. **Fix:** `:705` → `:727`, and adopt symbolic citation for this file. A
fourth recurrence in M4 should be treated as a blocker, not a P3.

**F-3 (P2) — `resolved_by` is NOT published by the NF525 §8 export, contrary to four assertions in
the delta.** `Nf525DataProvider::buildQuarantineSection()` selects `resolved_at` only
(`app/Modules/POS/Application/Services/Nf525DataProvider.php:1571`) and emits `resolved_at` only
(`:1603-1605`); `resolved_by` does not appear anywhere in that file. Yet
`QuarantineAlreadyResolvedException.php:15-18`, `FiscalEventQuarantineResolutionTest.php:173`,
`:399-400` and `:440-441` each state that `resolved_by` "is the audit fact the NF525 §8 export
publishes" — and 17-G's whole refusal rationale rests on that premise. The claim is inherited from
the approved contract (`M3-straggler-contracts.md:241`), but M3b re-asserted it in production code.
Substance beyond the docstring: the identity of the human who cleared a fiscal chain incident lives
**only** in the raw column. **Fix:** correct the four claims. Whether §8 should carry `resolved_by`
is an export-schema change outside 17-A…17-G — ticket it against the NF525 lane, do not take it here.

**F-4 (P3) — the adjudication write has no observability.**
`QuarantineIncidentResolutionService::resolve()` (`:80-116`) emits no log line and the endpoint
captures no reason. Its strictly *less* consequential read-only sibling logs
`fiscal.quarantine.best_effort_parse_invoked` (`QuarantineBestEffortParseController.php:150-160`).
Mitigated (§3): the row is never deleted, §8 still exports it, the chain walk is untouched, siblings
still fail the verifier. Not a contract violation — 17-A asks for the stamp and nothing more.
**Fix:** add a `Log::info('fiscal.quarantine.incident_resolved', [...])` (within contract, no schema
change); a structured reason field is a schema change and belongs to a ticket.

**F-5 (P3) — ES-16's coupling to the suppression string is a duplicated literal.** `OutboxIngestor.php:922`
vs `DeadLetteredProjectionsController.php:78`, with seven more copies at `OutboxIngestor.php:521-551`.
**Ruled within contract** (§2): no F16-* requires a shared constant and 16-D forbids M3b from adding
one; the reword-empties-the-partition failure mode is loudly caught by the real-ingestor fixture
guard at test `:483-487`. **Fix (next lane that opens `OutboxIngestor`):** one shared constant
referenced by the seven producers, the suppressor and the partition.

**F-6 (P3) — the partition predicate is a superset of the suppression, so "by construction" overstates
it.** `DeadLetteredProjectionsController.php:154` uses
`where('integrity_exception_reason','like','%z_session_lifecycle:%')`. In both PG and SQLite, `_` is
a LIKE single-character wildcard, so the SQL matches `z?session?lifecycle:` — strictly wider than
`str_contains` at `OutboxIngestor.php:922`. **No false negative is possible**, so visibility is safe,
and no other verdict string the ingestor emits can collide today. But the docblock at `:74-76` and
the evidence's "BY CONSTRUCTION … not an approximation" claim assert identity. **Fix:** escape the
underscores with an explicit `ESCAPE` clause, or narrow the claim to "a superset that is exact
against every verdict the ingestor emits".

**F-7 (P3) — N-2's sweep is partial; the falsified absolute claim survives 30 lines below its own
correction.** `VerifyEventChainCommand.php:718` still reads *"bytes that carry literal `\uXXXX`
escapes now refuse instead, which is correct — the canonical encoder cannot emit them"*, directly
under the new docblock (`:663-687`, U+2028 bullet at `:680-687`) that correctly states PHP escapes U+2028/U+2029 so the encoder
DOES emit those and the guard recovers them. The same absolute phrasing also survives at
`ParseFailureResumeTest.php:376` and `:1016` (true in their non-ASCII fixture context, but written as
universals). **Fix:** qualify all three to "`\uXXXX` escapes **of U+0080+**".

**F-8 (residual, ruled — no fix owed) — `show()` still returns 409 for lifecycle rows.** Ruled
WITHIN CONTRACT in §4; recorded so the surface owner inherits it. If a detail view is ever built,
`show()` must reuse `formatZSessionLifecycleRow()` and its deliberate absence of any action
affordance.

**F-9 (residual, ruled — no fix owed) — ES-16 visibility regresses if anyone runs
`fiscal:enqueue-resolved-event-projections` on a lifecycle row.** The partition carries
`whereNotExists(fiscal_event_projections)` (`:155-159`), mirroring partition 2. That command creates
pending rows for exactly these events (the contract's own finding at `:97-106`), after which the row
leaves partition 3 — appearing in partition 1 only if the projections dead-letter, and in **no**
partition if they apply, i.e. the blind spot returns with wrong Z aggregates written. Not a contract
violation: 16-A is demonstrated, the `whereNotExists` matches partition 2's shape, and the contract
explicitly forbids M3b from making that command safe. Belongs to whichever lane owns lifecycle
adjudication.

---

### Disposition

M3b implemented both stragglers strictly inside the contracts M3 approved, and the parts that are
easiest to fake are the parts that hold up hardest. I removed the ES-16 partition and got the
executor's red block character-for-character, including the two tests it had already disclosed as
guards rather than red-first — that disclosure is the kind that costs something to make, and it is
accurate. 16-C is satisfied as an ABSENCE I enumerated from the returned array literal, not from the
test's key list: twelve keys, no `write_off_action_url`, no action of any kind, with the controller
docblock recording *why* naming the recovery command is the failure mode so a future maintainer does
not helpfully restore it. ES-17 earned 17-C rather than buying it: the verifier's predicate is
untouched and the sibling-incident control proves the command is still watching. All five zero-line
gates hold across the full range, including the two the contract made explicit rejection triggers,
and the `OutboxIngestor:815` PARAM_STR ticket stayed untouched as instructed. All four counts
reproduce on PG; pint and phpstan pass; the inherited reds are what the evidence says they are, and
the Architecture failure list contains no Fiscal controller, which is the positive evidence that the
new controller is correctly tenant-classified. Rule 19 is genuinely N/A here — I grepped rather than
assumed — and the treasury lens applies to nothing in this delta and is stood down explicitly.

On the two rulings asked of me: the `show()` residual is **within contract** — 16-A names `index()`
and only `index()`, the clause table is what M3b owes, and the detail endpoint would carry no
information the list row already carries, so it is a deep-link dead end for a UI that does not exist,
not an information gap; it goes to the ledger, not to a fix round. The suppression-string coupling is
**within contract** too — no falsifier demands a shared constant and 16-D forbids M3b from adding
one — and the fragility, while real, is caught loudly by a fixture guard that drives the real
ingestor, so it is a hardening note for the next lane rather than a defect in this one.

What remains is seven findings, none of which changes a byte of behaviour or a fiscal fact. Three of
them (F-1, F-2, F-7) are the same citation-and-docblock-truth class this wave has now swept forward
twice, and F-2 is the sharpest thing in this register: the fix for N-1 was invalidated by its own
sibling edit in the same commit, so a finding recorded as closed is not. That is a process failure,
not a code failure, and the answer is structural rather than another round — the sweeps are five
one-line comment edits, and citing symbols instead of line numbers in a file this wave keeps editing
removes the failure mode permanently. F-3 is the one with substance behind the prose: the
adjudicator's identity is not in the §8 export the contract believed publishes it, and combined with
F-4's missing log line, "who cleared this incident" survives only in a raw column. Neither is inside
ES-17's approved contract, so neither is M3b's to fix beyond correcting the false claims.

**Owed at M4's opening commit, verifiable in one grep each:** F-1 (`856`→`896`, six sites), F-2
(`705`→`727`), F-3 (four false `resolved_by`/§8 claims), F-7 (three absolute `\uXXXX` claims), and
F-4's log line. **Owed as tickets, not work:** F-5, F-6's predicate hardening, F-8, F-9, and the
question of whether NF525 §8 should carry `resolved_by`. A fourth recurrence of the citation-drift
class in M4 is a blocker.

VERDICT: ACCEPT

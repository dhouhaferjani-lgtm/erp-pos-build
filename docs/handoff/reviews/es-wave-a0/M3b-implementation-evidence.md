# M3b — ES-16 / ES-17 straggler implementation: evidence

**Contract of record:** `docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md` at **revision 2**
(as amended in M3 fix round 1), **ACCEPTed at M3 round 2**
(`docs/handoff/reviews/es-wave-a0/M3-round2.md`, register commit `32671a9f6`). ES-16 = clauses
16-A…16-F + falsifiers F16-1…F16-7 (16-C is a pure-visibility ABSENCE requirement). ES-17 = clauses
17-A…17-G + falsifiers F17-1…F17-7, approved as written.

Brief line 594 requires "a line-by-line mapping from each approved contract clause to the code/test
that satisfies it", with the clause cited verbatim. §3 and §4 are that mapping; each clause is quoted
verbatim in the test's own docblock as well, so the mapping is checkable from the code alone.

**Harness:** PostgreSQL via `apps/api/phpunit-pgsql.xml`, `DB_DATABASE=autoerp_es_wave_a0_test` on
`127.0.0.1:5432`, every file **by path**, never the full suite. `apps/api/vendor` is a real directory
in this worktree, so the runs execute THIS worktree's production code. Every new test was also run on
the default (SQLite) driver.

---

## 1. What landed, and the gates that did not move

Production surface — six files, two modified and four new:

| File | Row | What |
|---|---|---|
| `apps/api/app/Modules/Fiscal/Presentation/Controllers/DeadLetteredProjectionsController.php` | ES-16 | third read partition + its row format |
| `apps/api/app/Modules/Fiscal/routes.php` | ES-17 | one route, gated by the existing permission |
| `apps/api/app/Modules/Fiscal/Application/Services/QuarantineIncidentResolutionService.php` *(new)* | ES-17 | the missing `resolved_at`/`resolved_by` writer |
| `apps/api/app/Modules/Fiscal/Presentation/Controllers/QuarantineIncidentResolutionController.php` *(new)* | ES-17 | HTTP boundary + tenant scoping |
| `apps/api/app/Modules/Fiscal/Domain/Exceptions/QuarantineAlreadyResolvedException.php` *(new)* | ES-17 | 17-G refusal |
| `apps/api/app/Modules/Fiscal/Domain/Exceptions/QuarantineIncidentNotFoundException.php` *(new)* | ES-17 | 17-F, kept distinct so a cross-tenant row is never reported as "already resolved" |

Tests — two new files: `tests/Feature/Fiscal/ZSessionLifecycleQuarantineVisibilityTest.php` (ES-16)
and `tests/Feature/Fiscal/FiscalEventQuarantineResolutionTest.php` (ES-17).

**No migration. No queue (`onQueue`) — so no `config/horizon.php` entry is owed (rule 20). No config
change. No front-end (explicitly OUT for ES-17). No new permission, no role-seeder change, no
`permission:cache-reset`.**

### Zero-diff gates, measured mechanically over `32671a9f6..HEAD`

```text
0 lines  apps/api/app/Modules/POS/Domain/Services/Fiscal/V3/ZReportHashService.php
0 lines  apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php
0 lines  apps/api/app/Modules/Fiscal/Infrastructure/Commands/EnqueueResolvedEventProjectionsCommand.php
0 lines  apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php
```

- **R-1** holds — `ZReportHashService` untouched.
- **16-D** holds — `OutboxIngestor` untouched, so the `sequence_gap` classification and the
  projection suppression at `:922-924` both stand exactly as they were. The suppression is
  **correct**; projecting a lifecycle-invalid Z session would write wrong aggregates. ES-16 is a
  visibility gap, not a suppression bug. The same zero-diff also means **`OutboxIngestor.php:815`'s
  `PARAM_STR` binding is untouched** — that is the separate ingestion-lane ticket the M3 round-2
  register escalated, and M3b was told not to touch it.
- **F16-7 / contract `:147-153`** holds — `EnqueueResolvedEventProjectionsCommand` is not made "safe"
  by adding the missing `integrity_status` precondition, which the contract explicitly forbids M3b
  from doing.
- **F16-6 and D-8** hold — `ParseFailureResolutionService` is not extended to accept these rows.

**D-8 was NOT reached.** ES-17 is a single-actor adjudication record, which is what clause 17-A
prescribes. No approval flow, no second approver, no correcting event (F17-7). Nothing in the
implementation turned on whether a second approver is required, so no STOP was triggered.

**No STOP was triggered at all.** The one conditional STOP in scope is 17-E's ("if the fixture shows
the intended principal does not hold the permission, STOP `blocked_owner` rather than inventing
one"). It was checked FIRST and explicitly — see §4, clause 17-E — and it does not fire.

---

## 2. On the ES-17 seam, stated plainly

The contract's own finding is that `VerifyEventChainCommand.php:856` (`whereNull('resolved_at')`) has
**no writer**. Clause 17-A prescribes the writer ("An authorised operator can stamp `resolved_at` +
`resolved_by`") and 17-C makes the verifier's exit-1→exit-0 transition the demonstration. So M3b
builds the writer — the contract does not defer it to an owner-gated workflow.

**The predicate at `:856` is not touched.** F17-4 makes "demonstrate 17-C by teaching the verifier to
ignore quarantine rows" a rejection trigger, and it would delete a control this wave just finished
hardening. `VerifyEventChainCommand.php` carries **no ES-17 change** — its only diff in this
milestone is the N-2 comment correction in the opening commit, which is inside
`recoverSealedPayloadFromFrozenBytes()` and touches no executable line.

The positive control for that is a test of its own:
`test_an_unresolved_sibling_incident_still_fails_the_verifier_after_one_is_stamped` stamps one of two
incidents and asserts the command still exits 1, still names `claimed_sequence_number 3`, and no
longer names `claimed_sequence_number 2`. If 17-C had been bought by blinding the verifier, that test
would go green too — it does not.

---

## 3. ES-16 — clause-by-clause mapping

| Clause (verbatim) | Code | Test |
|---|---|---|
| **16-A** "A `z_session_lifecycle` quarantine appears in `DeadLetteredProjectionsController::index()`'s response." Red-first, seeded "through the **real ingestion path** (`OutboxIngestor`, one of the seven lifecycle violations)". | `DeadLetteredProjectionsController.php:152-171` (the third partition) | `ZSessionLifecycleQuarantineVisibilityTest::test_index_surfaces_a_lifecycle_quarantine_produced_by_the_real_ingestor` — **RED before, GREEN after** |
| **16-B** "The surfaced row names **which** lifecycle violation fired … Asserted for at least two *different* violations so the field is proven to vary rather than being a constant." | `lifecycle_violation` field at `:319`, extracted by `extractLifecycleViolation()` `:345-359` | `test_index_names_which_lifecycle_violation_fired_and_the_value_varies` — `missing_session_open` vs `session_open_source_mismatch`, plus an explicit `assertNotSame` |
| **16-C** *(amended)* "The surfaced row **names no recovery command at all.** … The response carries **no** remediation/recovery/next-action field naming `fiscal:enqueue-resolved-event-projections` or any other command … Asserted as an absence, not assumed." | `formatZSessionLifecycleRow()` `:309-325` — deliberately carries **no** `write_off_action_url` and no action field; the docblock `:284-308` records why | `test_index_row_for_a_lifecycle_quarantine_names_no_recovery_command` — asserts 7 forbidden keys absent AND 6 forbidden tokens absent from the whole response body |
| **16-D** "**No ingestion decision changes.** … `git diff` over `OutboxIngestor.php` shows **zero** behavioural lines" | — | measured: **0 lines** (§1) |
| **16-E** "The existing two partitions keep their exact current contents … proven **additive** and not a re-partitioning" | the two existing partitions' queries and both existing row formats are unmodified | `test_lifecycle_partition_is_additive_and_leaves_both_existing_partitions_exact` (3 rows, one per partition, existing shapes pinned incl. `write_off_action_url`) + the pre-existing `DeadLetteredProjectionsControllerTest` reproducing **12 tests / 33 assertions unchanged** |
| **16-F** "The new partition is **tenant-scoped** exactly like the existing two." | `->where('tenant_id', $user->tenant_id)` at `:153` | `test_a_lifecycle_quarantine_from_another_tenant_is_absent` — foreign tenant's row driven through the same real ingestor |

### Falsifier-by-falsifier

| Falsifier | Disposition |
|---|---|
| **F16-1** — visibility bought by weakening partition 2's `canonical_parse_failure` predicate | The predicate at `:107` is unmodified; the new partition is a separate query with its own predicate. Asserted: `test_lifecycle_partition_is_additive_…` requires **exactly one** `ingress_quarantine` row while a `sequence_gap` lifecycle row is present. A widened partition 2 would return two. |
| **F16-2** — the suppressed projections fire after all | `OutboxIngestor` diff = 0 lines. The ES-16 test asserts the projection count for the row is still **0** after the change. |
| **F16-3** — red-first written against a hand-inserted row the real ingestor never produces | Every lifecycle row in the file is produced by `$this->app->make(OutboxIngestor::class)->ingest(...)`. `assertIngestedLifecycleQuarantine()` re-asserts the produced shape (`sequence_gap` + the named violation) so the fixture fails loudly if the ingestor ever stops producing it. The only hand-inserted rows are the two EXISTING partitions' controls in the 16-E test, where they are the control and not the subject. |
| **F16-4** — 16-B satisfied by a hard-coded string | Killed by the two-violation assertion plus `assertNotSame`. A constant satisfies either assertion alone; it cannot satisfy both. |
| **F16-5** — response shape changes for rows the existing partitions already return | Both existing formatters are untouched; `DeadLetteredProjectionsControllerTest` reproduces 12/33 unchanged. |
| **F16-6** — `ParseFailureResolutionService` extended to accept these rows | 0-line diff. |
| **F16-7** — the row/copy/doc/log names the command, OR the command is made safe by adding the missing precondition | Both closed. The absence is asserted over the response body; `EnqueueResolvedEventProjectionsCommand.php` diff = 0 lines. The controller docblock at `:284-308` states *why* naming the command is the failure mode, so a future maintainer does not "helpfully" add the affordance back. |

### The mechanism, and why the predicate is the right one

The partition matches `integrity_exception_reason LIKE '%z_session_lifecycle:%'` — the **same string
`OutboxIngestor::dispatchProjections()` suppresses on** (`:922-924`,
`str_contains($exceptionReason, 'z_session_lifecycle:')`). The rows surfaced are therefore *by
construction* the rows the ingestor's own suppression hid, not an approximation of them. The prefix
is a named constant (`:78`) whose docblock says exactly that.

The partition also carries `whereNotExists(fiscal_event_projections)`, mirroring partition 2's shape,
so the three partitions are mutually exclusive. Worth recording: partitions 2 and 3 are disjoint *by
construction anyway* — `verifyZSessionLifecycle()` returns null unless `$parseResult->ok`, and
`deriveIntegrity()` only assigns `CanonicalParseFailure` when `! $parseResult->ok`, so a row can never
carry both. That is the same impossibility F16-3 names.

### What was NOT built, and why it is being disclosed rather than assumed away

**`show()` was deliberately NOT extended.** Clause 16-A names `index()` and only `index()`. A
lifecycle row requested at `GET /fiscal/dead-lettered-projections/{id}` therefore still returns
`409 NOT_DEAD_LETTERED` — the behaviour it has today, unchanged by M3b.

This is a real residual and it is stated rather than hidden: an operator who sees the row in the list
and follows it to the detail endpoint gets a 409. M3b did not fix it because "implementing beyond …
the approved contract is a milestone failure", and extending a second endpoint is beyond 16-A.
**If M3b's reviewer judges the detail endpoint to be part of "the existing dead-letter read surface"
in the contract's headline sentence, that is an amendment to make at review and implement in a fix
round** — not something to have quietly taken.

Also not built, all explicitly OUT per the contract: any change to the seven lifecycle rules, any new
`IntegrityExceptionClass` case, any automatic remediation.

---

## 4. ES-17 — clause-by-clause mapping

| Clause (verbatim) | Code | Test |
|---|---|---|
| **17-A** "An authorised operator can stamp `resolved_at` + `resolved_by` on a quarantine row, and both land together … Both columns non-null in the same write, or neither." | `QuarantineIncidentResolutionService::resolve()` `:80-117`; the single `->update([...])` naming exactly two columns at `:110-114` | `test_an_authorised_operator_stamps_both_resolution_columns_together` — **RED before (404), GREEN after** |
| **17-B** "The stamp is **explicit lifecycle code, never mass assignment.** … An implementation that adds them to `$fillable` violates the model's stated boundary discipline and fails this clause." | the service writes through a targeted `->update()`; `FiscalEventQuarantine::$fillable` (`:93-119`) is **unmodified** | `test_resolution_columns_stay_out_of_fillable_and_resist_mass_assignment` — asserts both columns absent from `getFillable()` AND that a runtime `fill()`+`save()` of them persists nothing |
| **17-C** "After the stamp, `fiscal:verify-event-chain` stops reporting that row … the command exits **1** … before, and **0** after (all other checks clean), on the same fixture." | the writer alone; `VerifyEventChainCommand.php:856` untouched | `test_verifier_exits_one_before_the_stamp_and_zero_after_on_the_same_fixture` — **RED before, GREEN after**; asserts exit 1 + `QUARANTINE INCIDENT` + `claimed_sequence_number 2` before, exit 0 + `chain verified` + no `QUARANTINE INCIDENT` after |
| **17-D** "The stamp changes **nothing else** … byte-identical before and after; the conflicting event … is untouched. Asserted column by column." | the `->update()` names two columns and nothing else | `test_the_stamp_changes_nothing_else_on_the_row_or_the_chain` — 14 quarantine columns compared byte-for-byte, `fiscal_events` row count unchanged, and the conflicting event's 5 columns compared |
| **17-E** "The action is **permission-gated**, reusing an existing seeded permission … `fiscal.events.resolve_quarantine` … An unauthorised principal is refused **and persists nothing**." | `routes.php:43-45`, `->middleware('can:fiscal.events.resolve_quarantine')` | `test_the_reused_permission_exists_in_the_seeded_set_and_the_resolver_principal_holds_it` (the STOP check) + `test_an_unauthorised_principal_is_refused_and_persists_nothing` (403 **and** row still unstamped) |
| **17-F** "Tenant-scoped. A resolver in tenant A cannot stamp tenant B's quarantine row." | controller `:61-73` scopes the lookup by `tenant_id`; the service re-checks under the row lock at `:84-95` | `test_a_resolver_cannot_stamp_another_tenants_quarantine_row` — with a **positive control** (the resolver's own row stamps OK in the same test) so the 404 is not satisfied by "the endpoint does not exist" |
| **17-G** "Idempotent / non-destructive on an already-resolved row … it must not silently overwrite the original `resolved_by`." | `:97-103` refuses with `QuarantineAlreadyResolvedException` → 409 | `test_restamping_an_already_resolved_row_refuses_and_preserves_the_original_stamp` — a **different** authorised operator gets 409 and the original `resolved_by` + `resolved_at` are unchanged |

### 17-E's STOP condition, checked before anything was built

The contract: *"If the fixture shows the intended principal does not hold it, STOP `blocked_owner`
rather than inventing a permission."* Checked as the first test in the file, and it passes:

- `fiscal.events.resolve_quarantine` **already exists** in the seeded set
  (`RolesAndPermissionsSeeder.php:465`), asserted directly against the `Permission` table.
- The intended principal **can hold it**, asserted via `$this->resolver->can(...)`.

So the gate does not fire. **Deploy note, stated explicitly as the brief asks for the sibling ES-42
case: reusing `fiscal.events.resolve_quarantine` requires no permission migration, no seeder change,
and no `permission:cache-reset`** (F17-6).

### Falsifier-by-falsifier

| Falsifier | Disposition |
|---|---|
| **F17-1** — the resolution admits the envelope into `fiscal_events` | The service touches only `fiscal_event_quarantine`. Asserted: the tenant's `fiscal_events` row count is identical across the stamp, and the conflicting event's columns are compared one by one. |
| **F17-2** — the stamp presented as a correctness claim ("verified", "accepted") | The endpoint is `resolve-incident`, not `verify`/`accept`; it takes **no body**, because there is nothing to claim. `test_the_response_does_not_present_the_stamp_as_a_correctness_claim` asserts the response body contains none of `verified` / `accepted` / `valid` / `correct` / `approved`, case-insensitively. |
| **F17-3** — columns added to `$fillable` or written through request-bound mass assignment | `$fillable` unmodified and asserted; the write is a targeted `->update()`; the endpoint accepts no request body at all, so there is no array that could reach the columns. |
| **F17-4** — 17-C demonstrated by making the verifier ignore quarantine rows | `VerifyEventChainCommand.php` carries no ES-17 change. Positive control test: an unresolved sibling incident still fails the verifier after the other is stamped. |
| **F17-5** — the refusal asserted only on HTTP status, not on persistence | The 403 test re-reads the row and asserts both columns still NULL. |
| **F17-6** — a new permission / role-seeder change / `permission:cache-reset` introduced without an owner ruling | None introduced; the existing permission is reused and its existence is asserted. |
| **F17-7** — an approval / second-approver flow is built | None. Single-actor stamp. The service docblock records that a second-approver requirement would be **D-8**, ruled first, never designed provisionally. |

---

## 5. Verification

### Red-first, ES-16 (before the controller change)

```text
tests/Feature/Fiscal/ZSessionLifecycleQuarantineVisibilityTest.php
  Tests: 7, Assertions: 64, Failures: 5

  16-A  test_index_surfaces_a_lifecycle_quarantine_…       Failed asserting that an array contains '8b0a769b-…'
  16-B  test_index_names_which_lifecycle_violation_…       Failed asserting that an array has the key '9a3d3171-…'
  16-C  test_index_row_…_names_no_recovery_command         Failed asserting that an array has the key 'aae45af5-…'
  16-E  test_lifecycle_partition_is_additive_…             actual size 2 matches expected size 3
  16-F  test_a_lifecycle_quarantine_from_another_tenant…   Failed asserting that an array contains 'fceb7b54-…'
```

Each red is the row being **absent** — the blind spot itself. The 64 assertions that DID run include
the fixture guards, which is the evidence that the real ingestion path produced the contract's shape
before the endpoint was asked about it.

**Disclosed, because they are guards and not red-first evidence:** two of the seven were green before
the change — `test_projector_filter_excludes_the_lifecycle_partition` (passes vacuously pre-change,
since the row is excluded by the filter either way; post-change it pins that the new partition
follows partition 2's filter convention) and `test_index_denies_a_user_without_the_permission`
(unchanged permission behaviour).

### Red-first, ES-17 (before the route/service/controller)

```text
tests/Feature/Fiscal/FiscalEventQuarantineResolutionTest.php
  Tests: 10, Assertions: 21, Failures: 8      (all eight: HTTP 404 — no path exists today)
```

Two tests were vacuously green on the first red run and were **strengthened before implementing**, so
the recorded 8 reds are honest:

- `test_the_response_does_not_present_the_stamp_as_a_correctness_claim` was passing against a 404
  body; an `assertOk()` was added first.
- `test_a_resolver_cannot_stamp_another_tenants_quarantine_row` expected 404 and got 404 for the
  wrong reason (route missing, not tenant filter); a positive control was added first — the
  resolver's own row must stamp OK in the same test.

**Disclosed:** the two still-green tests are `test_the_reused_permission_exists_…` (the 17-E STOP
check — its being green is the finding) and `test_resolution_columns_stay_out_of_fillable_…` (a
structural pin that is correctly green in both directions; 17-B asks for exactly that assertion).

### Green, both drivers

```text
PG (phpunit-pgsql.xml, by path):
  ZSessionLifecycleQuarantineVisibilityTest        OK (7 tests, 91 assertions)
  FiscalEventQuarantineResolutionTest              OK (10 tests, 69 assertions)

Default (SQLite), both files in one run:
  OK (17 tests, 160 assertions)                    = 7+10 / 91+69 — the drivers agree test-for-test
                                                     and assertion-for-assertion
```

### M3 headline counts reproduce unchanged

The opening commit edits a comment in `VerifyEventChainCommand.php`, so both M3 headline counts were
re-run on PG:

```text
tests/Feature/Fiscal/ParseFailureResumeTest.php       OK (27 tests, 209 assertions)   [M3: 27/209 ✓]
tests/Feature/Fiscal/VerifyEventChainCommandTest.php  OK (34 tests, 138 assertions)   [M3: 34/138 ✓]
```

### Regression, PG, by path

```text
DeadLetteredProjectionsControllerTest + RefundCompensationControllerTest
  + FiscalEventQuarantineTableTest + VerifyEventChainFleetCommandTest
  + Nf525VerifyChainParityTest                        OK (50 tests, 240 assertions)

DeadLetteredProjectionsControllerTest alone           OK (12 tests, 33 assertions)  ← 16-E / F16-5
```

### Static gates

```text
pint --test   (all 7 changed/new production files + both new test files)   {"result":"pass"}
phpstan       (level 8, all 7 changed/new production files)                [OK] No errors
```

---

## 6. Inherited reds — measured, not assumed

Four pre-existing failures sit in files adjacent to this work. Each was proven inherited by
**removing every line of M3b production code** (deleting the four new files and `git checkout --` on
the two modified ones) and re-running. The baselines are identical:

| Suite | With M3b | M3b production code removed | Verdict |
|---|---|---|---|
| `QuarantineBestEffortParseControllerTest` + `OutboxIngestorTest` + `DeadLetteredProjectionsControllerTest` | 44 tests, 175 assertions, 3 errors + 1 failure | **44 tests, 175 assertions, 3 errors + 1 failure** | inherited |
| `tests/Architecture` (whole directory) | 46 tests, 231 assertions, 4 failures | **46 tests, 231 assertions, 4 failures** | inherited |

Named, so nothing is hidden behind a count:

- `OutboxIngestorTest::test_server_received_at_is_driver_fetched_not_php_wall_clock` — "3601 is not
  less than 60", a one-hour offset: a local environment/TZ artifact of this machine.
- `QuarantineBestEffortParseControllerTest` × 3 — the tests' **own fixtures** trip the
  `fiscal_events` immutability trigger ("`integrity_exception_class` is write-once") at
  `QuarantineBestEffortParseControllerTest.php:165` / `:189`. Not reachable from any M3b code path.
- `tests/Architecture` × 4 — `AuthLifecycleTest` points at `app/Modules/POS/routes.php:93` (not
  `Fiscal/routes.php`); `ControllerTenantContextTest` names eight controllers in the Tenant,
  Notification and SupportAccess modules and **none** of M3b's. The identical 46/231 across the swap
  is also the positive evidence that the new `QuarantineIncidentResolutionController` IS correctly
  classified — an unclassified controller would have lengthened that list.

The working tree was restored and re-verified after both swaps (`git status --porcelain` matched, and
the two new test files were re-run green: 17/160).

---

## 7. Standing checks

- **Rule 19 / precision — N/A and verified so.** No added line touches money or quantity arithmetic.
  The ES-16 row surfaces no amount at all (`refund_amount` is deliberately omitted — it is the refund
  write-off surface's field and a lifecycle-invalid Z session has no write-off). No `(float)`,
  `parseFloat`, `floatval` or `number_format` anywhere in the diff; no scale resolution is reachable.
- **Rule 20 — holds.** No `onQueue`, so no `horizon.php` entry owed. No migration. No
  `CompanyContext` dependency introduced. No SQLite TEXT-timestamp boundary and no device
  shift re-hydration in scope.
- **Rule 13 — holds.** No `app()` in production code; both new classes use constructor injection with
  `private readonly`.
- **Rule 12 — holds.** The new route sits inside the existing
  `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` group and adds
  a `can:` gate, matching its two sibling quarantine routes exactly.
- **Rule 8 — holds.** No event class renamed, restructured or deleted; no event emitted or consumed.
- **Hash shape — untouched.** Nothing computes, formats, stores or re-authors a hash. The ES-17
  UPDATE names two lifecycle columns; every hash-bearing column is asserted byte-identical across it.
- **Fiscal verdict — no softening.** The verifier's exit-1 path is unchanged; the only way an
  incident now clears is a human stamp, and the sibling-incident control proves the verifier still
  reports everything else.
- **Test quality.** Zero `assertTrue(true)`. Every assertion carries a failure message stating the
  fiscal consequence. Nothing under test is mocked: the ES-16 fixtures drive the real `OutboxIngestor`
  and the ES-17 tests drive the real `fiscal:verify-event-chain` through `Artisan::call`.
- **Treasury lens — APPLIED AND STOOD DOWN.** No GL entry, no journal, no payment, no cash/drawer
  surface, no currency-scale resolution, no balance arithmetic. One read partition and one
  two-column lifecycle stamp. Stated explicitly rather than skipped silently.

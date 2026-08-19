# M1 adversarial review — SV-1 (takings-only retirement + three artefact rewrites), round 1

**Range reviewed:** `df85d43f4..8d5c9b921` (M1 commits `2d7503018`, `2b1684890`, `8d5c9b921`; M0 commits also in range)
**Lenses:** `fiscal-pos`, `treasury` — both apply and both are load-bearing (the retired symbol is a Z-report/hash-adjacent surface; two of the three artefacts are Treasury's config and listener docblock).

## What holds (verified, not taken from the report)

- **Retirement shape (R-3) = annotate, and the enumeration is real.** `ReportGenerationService.php:513-517` carries `@deprecated`. I re-ran the consumer enumeration independently across `apps/**` for every caller of `POST /pos/reports/z`: exactly two production clients — `apps/web/src/features/pos/api/shiftApi.ts:68-70,109` (`ZReportData = { terminal_id }`) and `apps/pos/src/api/reportApi.ts:245-246` (itself `@deprecated`, posts `{ terminal_id }`). No third client (no mobile, no admin, no server-to-server). `buildExpectedPerMethod` is `private`, so `ReportGenerationService.php:234` is the complete PHP call graph. The grep-proof in the report is honest.
- **Choosing annotate over delete was the right call**, and closes M0-round5's finding 3: the `:233-244` branch also carries `assertManagerCanOverride()` and the sealed hash-input keys `schema_version` / `cash_counts` / `variance_summary` / `tolerance_summary`. Deleting it would have moved fiscal bytes for a documentation defect.
- **Scope fence intact.** `git diff --name-only base..HEAD`: `CashDrawerService.php` byte-untouched, no Stage-2+ file, no event class, no migration, no queue, `shift_variance_gl_enabled` still `(bool) env(..., false)` (`config/treasury.php:26`).
- **The new stated gate is factually true.** I checked for a Treasury/Accounting consumer of `CashDrawerOperationRecorded` that would falsify "float and drawer ops unbooked": only `Compliance/DomainEventSubscriber.php:805,1138` (one `audit_events` row). SV-3/SV-4 as cited hold.
- **Guards green, independently re-run** on local PG 5432 (`autoerp_sv_stage1_m1_final_test`): `ZReportServerAuthoringChokepointTest` + `ServerReportAuthoringUnreachabilityTest` → 7 tests / 8783 assertions, exit 0 (the WARN marks are the disclosed missing-`.env` noise, not failures). Pint `{"result":"pass"}`; PHPStan L8 `[OK] No errors` on the new test.
- **Rule 19:** M1 is comment-only plus one reflection/file-shape test. No money or quantity arithmetic changed anywhere in the diff. Constructor injection, tenant scoping, i18n, migrations, queues: not reached by this milestone.

## Findings

**1. [P1 · CONFIRMED] `apps/api/app/Modules/Treasury/Application/Listeners/PostShiftCashVarianceAdjustment.php:69-74` — artefact 2 still names the dead takings-only formula as one of two *live* expected-cash bases, twenty lines below the block M1 rewrote.**
The DOUBLE-COUNT GUARD section reads: *"…while both expected-cash bases — the server's `SUM(pos_receipt_payments.amount) − change_due` and the device's own `cashTendered − change_due` term — are TENDERED-based, i.e. the cash that physically entered the drawer."* That first "basis" is `buildExpectedPerMethod()`, which the same commit declares dead, client-less and takings-only at `ReportGenerationService.php:513-517`. M1's own evidence-contract antidote is *"a grep for the refuted premise ('sums receipt payments only' / 'float … 658/758 on every close') returns **zero** policy assertions outside a historical note"* — it does not, and the surviving hit is inside a mandated artefact.
*Failure scenario:* the fifth reader opens the listener to understand why the flag is off, reads the corrected SHIPS-DISABLED block at `:44-50`, scrolls twenty lines, and re-derives from `:69-74` that the server's expected basis is receipt payments net of change — i.e. exactly the premise SV-1 was dispatched to bury, in the file SV-1 rewrote. Under the settled whole-drawer ruling the live server basis is `CashDrawerService::calculateExpectedCash()` (`opening + sales − refunds − deposits − payouts`), which is *not* purely tendered-based. The double-count *conclusion* still holds on the device basis, so this is a stale-premise defect, not a GL-correctness defect — but it is the milestone's whole mandate. Minimal close: one clause marking the server term as the retired v2 argument, or replacing it with the live whole-drawer basis.

**2. [P2 · CONFIRMED] `docs/superpowers/tickets/2026-08-08-g3-legacy-closeshift-no-gl-leg.md:23-31,41-49` — a sibling ticket in the same `2026-08-08-g3-*` family, from the same gate, still states the refuted premise verbatim *and* presents the settled ruling as open.**
`:23-24` — *"the cash-count branch's basis is `ReportGenerationService::buildExpectedPerMethod()` — receipt payments only, i.e. the shift's takings"*. `:30-31` — *"would post the opening float to 658/758 on every legacy close, permanently"*. `:41-49` — *"Sequenced strictly AFTER the owner ruling on count semantics: 1. If the ruling is **takings**… 2. If the ruling is **whole drawer**…"*. The ruling is CONFIRMED (OD:70) and is binding ruling 1 of this very brief.
*Failure scenario:* whoever picks up the legacy-closeshift ticket next branches on a ruling that was made, treats the dead helper as the authoritative cash-count basis, and re-opens the question SV-1 closed. Strictly outside R-2's list-of-three, but it fails M1's stated antidote grep and is the exact "fifth reader" hazard. Minimal close: correct the two clauses and collapse the `if takings / if whole drawer` fork to the ruled branch — or, if the wave declines to touch a ticket outside the three, record the deviation explicitly in the M1 report rather than leaving it silent.
*Note on the antidote itself:* the literal string `receipt payments only` is line-wrapped across `:23-24`, so a naive one-line grep for the antidote phrase returns clean here. An empty grep is not proof until the syntax is verified.

**3. [P2 · CONFIRMED] The historical note landed only in the ticket, although the annotate path (which keeps the code) was chosen — `ReportGenerationService.php:513-517` vs `2026-08-08-g3-shift-variance-gl-deploy-notes.md:57-60`.**
Dossier SV-1: *"Historical note worth preserving **in the code comment** (R16 §1.3)"*; brief R-3: *"If the code that would carry the note **is deleted**, the note lands in artefact 3 (the ticket)."* The code was not deleted, so the conditional does not fire. The `@deprecated` block carries no trace of the missing-join origin or the fourth-reader warning; the ticket carries both.
*Failure scenario:* a reader arrives via the code (which is where readers one through four arrived — via `CashCountToleranceVarianceRegressionTest.php:79-81` and the function itself), reads *"takings-only… no shipped client… production is whole-drawer"*, and gets no signal that the omission is a missing join rather than a doctrine — the precise distinction the note exists to transmit. The note does survive durably, hence P2 not P1. Minimal close: two lines appended to the `@deprecated` block.

**4. [P3 · CONFIRMED] Three further live surfaces still equate the takings-only figure with drawer cash; the diff corrected the production docblock that said this but not its mirrors.**
`ReportGenerationService.php:973-978` was rewritten to drop "the cash-count expected figure" framing — good — but:
- `apps/api/tests/Feature/Treasury/ShiftCashVarianceTriggerPathsTest.php:261-268` — *"the **real server basis**… `buildExpectedPerMethod()` therefore computes expected = 99.950 — **the cash actually in the drawer**"*.
- `apps/api/tests/Feature/POS/GenerateZReportWithCountsTest.php:574-579` — *"two views of the **same drawer** and MUST agree"*.
- `docs/superpowers/tickets/2026-08-08-g3-kill-switch-window-no-backfill.md:30` — *"the lane ships DISABLED pending an owner ruling on POS count semantics that is not yet scheduled"* (the ruling is made; the gate is now Treasury representation).

Both tests are in this wave's declared regression set, so they are read every round. Ticketable rather than blocking.

**5. [P3 · CONFIRMED] `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:256-260` — a fourth artefact was edited, outside R-2's "the list is three", and the rewrite trades a precise cross-reference for an unnamed one.**
The stale hard-coded range (`ReportGenerationService.php:504-532`) genuinely needed removing and the M0 register flagged it as M1 work, so this is disclosed, not smuggled — but it is rule-4 surface the brief did not name. The replacement, *"The legacy POS cash-count query faces the identical row-fan-out hazard"*, names no symbol, so the mirrored-technique claim is no longer independently checkable from that comment. The claim itself is still true (`ReportGenerationService.php:552-556` does `MAX(COALESCE(change_due,0))` grouped by `(payment_method_id, receipt_id)`, summed second). The edit also leaves a ragged wrap at `:259-260`.

**6. [P3 · PLAUSIBLE] `apps/api/tests/Feature/Fiscal/ZReportServerAuthoringChokepointTest.php:26-28` reaches outside `apps/api` via `dirname(base_path(), 2)`.**
Precedent exists (`tests/Unit/Application/Sweep/InventoryYamlSchemaTest.php:271`), and both regexes verified to match the current sources under an independent match. *Failure scenario:* any api-only checkout or Docker build context without `apps/web` / `apps/pos` turns `file_get_contents` into a warning + `false`, and the test fails for environment reasons rather than contract violation — a red that looks like a broken contract. A `markTestSkipped` when the sibling app is absent would make the failure mode honest.

**7. [P3 · CONFIRMED] Red-first evidence for the annotation test is asserted as a count, not pasted.**
`docs/sessions/codex-sv-stage1-report.md` reports *"Red: … did not contain `@deprecated` (1 failed, 2 assertions)"* with no literal output, while the grep-proof beside it is pasted in full as the brief demands. I confirmed the assertion is non-vacuous by other means (the base docblock has no `@deprecated`; deleting the method makes `ReflectionMethod` throw rather than pass), so the claim is credible — but the paste is the cheap part of the evidence contract.

## Bypasses attempted that FAILED (i.e. the work held)

1. Tried to falsify "no shipped client" by enumerating every caller of the Z route across `apps/**` (`.ts`/`.tsx`/`.php`), not just the two the brief names → no third client exists.
2. Tried to make the new test vacuous — deleting the method errors rather than passes; both client regexes are shape-pinning (`{ terminal_id: string }` exactly), not loose substrings, and both verified to match current sources independently.
3. Tried to catch a scope-fence break or a Stage-2+ leak in the file list → `CashDrawerService.php` untouched, no event, no migration, no queue, flag default unchanged.
4. Tried to falsify the *new* gate text by finding a Treasury/Accounting consumer of `CashDrawerOperationRecorded` → only the audit subscriber. The rewrite states a true gate.
5. Re-ran both chokepoint guards on real PostgreSQL rather than trusting the report → green.
6. Ran Pint and PHPStan L8 on every touched api file → clean.

Findings 1–3 are all the same defect wearing three hats: the refuted premise survives in places a reader will land, including inside a mandated artefact. Each fix is a handful of comment lines; none touches behaviour, so a fix round should be short.

VERDICT: CHANGES-REQUIRED

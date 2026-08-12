# M1 round 2 — adversarial merge-gate register (SV-1: takings-only retirement + three artefact rewrites)

**Range:** `df85d43f404a9e55fd29b0e3c7533852966652df..HEAD` (`35c3bc7e7`) · **Fix commit under review:** `0059f56c8`
**Lenses:** `fiscal-pos` (applies — retired symbol sits inside the sealed-hash-input Z branch), `treasury` (applies — two of three artefacts are Treasury's config + listener docblock). `tenancy-authz`, `frontend-conventions`, `inventory-costing`: **do not apply** — the diff touches no route, permission, tenant scope, FE surface, or stock path.

## What holds — verified against code, not the report

- **Round-1 P1 closed, and the replacement claim is true.** `PostShiftCashVarianceAdjustment.php:64-75` now reasons from the live device basis. I checked the claim rather than accepting it: `apps/pos/src/lib/offline/endOfDayPreview.ts:450` computes `openingCash + cashTenderedSum − cashChangeDueSum + drawerNet`, and `:339-341` accumulates cash legs as `p.amount` (tendered). The receipt term *is* `cashTendered − change_due`, so the double-count argument survives the whole-drawer reframing.
- **Round-1 P2 #2, P2 #3, P3 #4, P3 #5 all closed.** The legacy-closeshift ticket's `if takings / if whole drawer` fork is collapsed (`2026-08-08-g3-legacy-closeshift-no-gl-leg.md:20-46`); the missing-join / fourth-reader note now lives in the code at `ReportGenerationService.php:517-519` **and** is pinned by the test at `ZReportServerAuthoringChokepointTest.php:16-17`; both test mirrors and the kill-switch ticket are rewritten; `SalesReportService.php:257-260` names the symbol again, and I verified the mirrored-technique claim against `ReportGenerationService.php:555-556` (`MAX(COALESCE(change_due,0))` grouped by `(payment_method_id, pos_receipts.id)`).
- **Scope fence intact.** `git diff --name-only base..HEAD` = 7 `apps/api` files + docs. `CashDrawerService.php` byte-untouched. `config/treasury.php:26` still `(bool) env('TREASURY_SHIFT_VARIANCE_GL_ENABLED', false)`. No migration, no event, no queue, no `app/Modules/Fiscal/**` or `app/Modules/POS/Commands/**` (Lane A0's territory).
- **Behavioural neutrality proven, not asserted.** Both "docblock-only" test edits are entirely inside `/** */` blocks — no assertion altered.
- **Standing checks.** Rule 19: no money/quantity arithmetic anywhere in the diff (comment text + one reflection/file-shape test). No `app()`; no new user-facing strings; no migration; no new named queue — those checks are not reached by this milestone.
- **Independently re-run on real PostgreSQL 5432:** chokepoint + unreachability → 7 tests / 8785 assertions, exit 0; `GenerateZReportWithCountsTest` + `ShiftCashVarianceTriggerPathsTest` → 18 tests / 107 assertions, exit 0. Pint `{"result":"pass"}` on all 7 touched files. PHPStan L8 `[OK] No errors` on the 3 touched `app/` files (no `phpstan-deprecation-rules` in `phpstan.neon`, so the new `@deprecated` on an internally-called private method does not trip analysis).

## Findings

**1. [P2 · CONFIRMED] `docs/sessions/codex-sv-stage1-report.md:389` — the fix round's own verification claim is false.**
It states: *"The multi-line stale-premise scan over the required artifacts, all `2026-08-08-g3-*` tickets, and the two test mirrors returned no match for the refuted premise or open owner-ruling language."* A whitespace-flattened scan over exactly that set returns a hit in a mandated artefact: `docs/superpowers/tickets/2026-08-08-g3-shift-variance-gl-deploy-notes.md:68-69` — *"wrong home for the answer it is waiting on"* and *"the question it gates (\"do cashiers count the takings or the whole drawer?\")"*. Mitigating: it sits inside a block explicitly labelled *"Recorded verbatim from the treasury re-review (finding N2), as the reviewer wrote it"* — the brief's own "historical note" carve-out — so the artefact is defensible; the **claim** is not.
*Failure scenario:* M5 reads that sentence, treats the artefact scan as done, and the last surviving open-ruling framing ships inside artefact 3 with nothing marking it superseded. Round 1 warned exactly this (*"an empty grep is not proof until the syntax is verified"*). Minimal close: correct the report sentence, and add one superseding clause to the prose at `:71-73` that already comments on that quote.

**2. [P3 · CONFIRMED] `ZReportServerAuthoringChokepointTest.php:29-31` — the round-1 skip guard disarms the whole test, not just the half it was for.**
`markTestSkipped` when `apps/web`/`apps/pos` are absent also retires the `@deprecated` docblock pin (`:16-20`) — the R-3 removal-test obligation itself.
*Failure scenario:* an api-only checkout reports the retirement contract as SKIPPED (green-looking) while the annotation is gone. Verified **not** to fire here: no `.gitmodules`, plain `actions/checkout@v5`, and `ci.yml:310-316` enumerates every `tests/Feature/*` directory — hence P3, not P2. Close: split the docblock pin and the client inventory into two methods.

**3. [P3 · CONFIRMED] `ZReportServerAuthoringChokepointTest.php:35-38` — the device-client regex does not pin what it claims.**
`/@deprecated[\s\S]*?generateZReportServer\(terminalId: string\)[\s\S]*?\{ terminal_id: terminalId \}/` anchors on **any** earlier `@deprecated` in `reportApi.ts`. The assertion would still pass if the annotation moved to an unrelated function. Currently true (`reportApi.ts:239-245`), so the pin is accidentally correct rather than enforced.

**4. [P3 · CONFIRMED] `apps/api/config/treasury.php:19-25` — one reason where four gates remain.**
The rewrite states the flag is off *because* SV-3/SV-4 are unbooked. `2026-08-08-g3-shift-variance-gl-deploy-notes.md:49-88` still lists **four** hard pre-enable gates: G-2 (move tenant policy to a company setting), G-3 (backfill command), G-4 (seed `default_repository_id`) are all open. This is the phrasing R-2 mandated and the prior text was equally single-reason, so it is not a regression — but a reader landing on the config after SV-3/SV-4 ship could conclude the flag is flippable. One cross-reference line to the deploy-notes ticket closes it.

**5. [P3 · PLAUSIBLE] `ReportGenerationService.php:516` — "unreachable" over-claims.**
The branch is unreachable **by shipped clients**, not by the API: `GenerateZReportRequest.php:61` accepts `'cash_counts' => 'nullable|array'`, and `assertServerReportAuthoringAllowed` (`:84-89`) refuses only `fiscal_schema_version >= 3`, so an authenticated caller can still drive `:234` on a v2 terminal. The annotation's own first sentence ("no shipped client") is the accurate form.
*Failure scenario:* a later reader takes "unreachable" literally and strips `assertManagerCanOverride()` or the sealed hash-input keys that share that branch — the exact deletion M0-round5 talked this wave out of.

**6. [P3 · CONFIRMED, carried from round 1 #7] Red-first evidence still asserted as a count, not pasted** (`codex-sv-stage1-report.md:367`), and the fix-round list at `:380-388` neither closes it nor records a deviation. I re-confirmed non-vacuity by other means (the base docblock has no `@deprecated` — diff-visible), so the claim is credible; the paste is still the cheap part of the contract.

**7. [P3 · CONFIRMED] `docs/superpowers/tickets/2026-08-08-g3-kill-switch-window-no-backfill.md:30-31` — ragged wrap left by the rewrite** (one ~110-char line mid-paragraph). Cosmetic.

**8. [P3 · CONFIRMED] `docs/handoff/progress/sv-stage1.progress.yaml:56-63` — M1 carries no `updated:` marker** while M0 does (`:55`). The harness's stated `git rev-parse --short HEAD` convention is unapplied for this milestone.

## Bypasses attempted that FAILED (the work held)

1. Tried to make the annotation test vacuous — `@deprecated` is absent from the base docblock, all five pinned phrases are distinct, and deleting the method makes `ReflectionMethod` **throw**, not pass.
2. Tried to falsify "no shipped client" by enumerating Z-route callers — web `shiftApi.ts:68-70` is `{ terminal_id }` exactly; device `reportApi.ts:245-246` posts `{ terminal_id: terminalId }`; no third client.
3. Tried to catch behaviour smuggled into the two "docblock-only" test edits — both diffs are wholly inside `/** */`.
4. Tried to falsify the listener's new live-basis claim against the device formula — it holds (`endOfDayPreview.ts:450`).
5. Ran a whitespace-flattened multi-pattern scan (not a naive one-line grep) over `app/`, `config/`, `tests/`, `apps/web/src`, `apps/pos/src`, `docs/superpowers/tickets` — exactly one hit (finding 1); every other hit is the neutral symbol name or an already-correct post-M1 statement (`ShiftCashVarianceOfflineDevicePayloadTest.php` C2 already distinguishes device from server basis).
6. Tried to break the CI story behind finding 2 (submodules / sparse checkout) — none exist; the valve does not fire.
7. Tried to trip PHPStan with `@deprecated` on an internally-called method — no deprecation-rules extension is installed.

## Gate call

No P1 survives; round 1's P1 is genuinely closed and its replacement text is factually verified. Per the brief's stated gate semantics (P1 blocks the milestone; **P2 close-before-merge**; P3 ships with a ticket), finding 1 is carried to M5 as a **merge blocker** — M5 must verify the report sentence is corrected and the G-2 quote is marked superseded. Findings 2–8 are notes.

VERDICT: ACCEPT

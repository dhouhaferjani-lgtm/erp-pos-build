I re-ran every declared baseline myself, ran the combined run the report says is impossible, and enumerated the M1 blast radius by payload key rather than by symbol. Here is the round-4 register.

## M0 review register — sv-stage1, round 4 (lens: fiscal-pos)

**Independently verified at `HEAD = e8bebf155`.** Base check re-derived, not read: `git merge-base --is-ancestor df85d43f4 HEAD` → PASS; `git show HEAD:…ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md | tail -n +63 | shasum -a 256` → `04760455ac…20540`, exact; `git status --porcelain` → empty; `git diff --name-only df85d43f4..HEAD` → 8 docs paths, **zero production code**. `e8bebf155` moves exactly one line (the YAML `commit:` pointer to `6bdf52e53`) — `6bdf52e53` is the real round-3 fix commit, so the pointer is honest.

**Baselines re-run by me, not accepted on assertion — all three reproduce:**
- API 15-file command, fresh DB: **`Tests: 66 warnings (9124 assertions)`** when I appended the guard file — i.e. exactly the report's `63 warnings (9117 assertions)` (`:137`) plus the guard's `3 warnings (7 assertions)` (`:200-201`). Both figures corroborated arithmetically and by execution.
- Device: `Test Files 6 passed (6)` / `Tests 74 passed (74)` — exact.
- Web: `Test Files 1 passed (1)` / `Tests 8 passed (8)` — exact.
- Prior-round DBs `autoerp_sv_stage1_m0r1/m0r2/m0r3/m0r3_guard_test` all exist on local PG — the claimed runs happened.

**Round-3 findings — closure verified against code:**
- **R3-#1 (P2, undeclared guard on the retired symbol) → CLOSED in the narrow form.** `tests/Feature/POS/FiscalStatusFilterTest.php` is now declared (`:187-202`), its docblock names `buildExpectedPerMethod()` at `:30`, and it runs green. **Re-opens in a broader form — finding 1.**
- **R3-#2, R3-#3 → CLOSED as recorded forward work** (`:225-226`); I confirmed both live sites still exist (`SalesReportService.php:258` hard-codes `ReportGenerationService.php:504-532`; `CompanyFraudSettingsService.php:22-25` states the vertical split M3 overturns).
- **R3-#4 (brief allowlist) → NOT fixed in the brief**, resolved by reinterpretation at `:227` → finding 3.

**Antidote executed — ~20 citations re-derived, none overlapping rounds 1–3. All exact, zero drift:** `ReportGenerationService.php:233-244` (`if ($cashCountInputs !== null)` → `validate(...)` close at 244) · `:491` (docblock `/**`) · `:514` · `:528` (the `SUM(CASE WHEN receipt_type='return' THEN -ABS(...))` receipt sum) · `:532-555` (change aggregation) · `:576-581` (`array_key_exists` zero-fill at 578-579) · `:583` (`return $totals;`) · `shiftApi.ts:68-70` (`ZReportData { terminal_id }`) · `reportApi.ts:242-246` (`@deprecated generateZReportServer`) · ticket `:51-58` (G-1, verbatim refuted premise) · plan v1 `:52` / v2 `:7` (the "zero callers" claim and its later correction) · `CompanyFraudSettings.php:190-193` (docblock) / `:201` (`$defaults['require_blind_cash_count'] = $isAutomotive;`) · migration `000004:19` (`->default(false)`) · `CashDrawerControlsSection.tsx:13,52,68,76,90,99,146,291,297` (all nine) · `FraudSettingsPage.tsx:55` / `:396` (`?? false` round-trip) · `compliance.json:109` en+fr · `EndOfDayPreviewModal.tsx:240-262` and `:252-260` · `endOfDayPreview.ts:90`,`:406` · `pos.json` `cash_count` at 906, expected/actual/variance 912-914. **The R-5 Arabic finding is exact**: `apps/pos/src/locales/` is `en`,`fr` only; `i18n.ts:4-11,28-30` registers two languages, `lng/fallbackLng: 'en'`, four namespaces. **The web-side claim is also exact**: `apps/web/src/locales/ar/` exists and contains no `compliance.json`.

**R-6 list:** faithful to the dossier's canonical six, migration as point 3, R-7's unattended-safety checks appended at `:120`, outcome recorded either way. Complete.

---

### Findings

**1 — P2 · CONFIRMED · `docs/sessions/codex-sv-stage1-report.md:126`, `:149-173`**
The declared API set still omits the covering tests for the **exact branch M1 removes**, and the fix pattern has now been minimal three rounds running: each round adds only the file the reviewer named. Round 3 found `FiscalStatusFilterTest` with `grep -rln buildExpectedPerMethod tests/`. Widening one term to the payload key — `grep -rln 'cash_counts|buildExpectedPerMethod|expected_per_method' tests/` — returns **seven more files, none declared**:

| file | what it pins |
|---|---|
| `tests/Feature/POS/ZReportSyncControllerSchema2Test.php` (33 refs) | `cash_counts` → `pos_z_report_counts` persistence, rejection paths, `CashCountRecorded` dispatch |
| `tests/Feature/POS/GenerateZReportEndToEndTest.php` (10) | endpoint 201 **both** with and without `cash_counts` (`:64,:81`) |
| `tests/Feature/POS/GenerateZReportRequestValidationTest.php` (15) | validates `GenerateZReportRequest.php:61-68` — **an explicitly inventoried SV-1 citation** (`:65`) |
| `tests/Feature/POS/HashGoldenByteTest.php` + `tests/Integration/POS/HashGoldenByteTest.php` | golden **hash-input bytes** including `cash_counts[].expected_amount/actual_amount/variance_amount` |
| `tests/Unit/POS/HashInputScale4EquivalenceTest.php` (8) | scale-4 normalization of the same keys |
| `tests/Feature/POS/PosStabilizationTenantIsolationTest.php` | cross-tenant refusal on `cash_counts.*.payment_method_id` |

I ran the two heaviest on a fresh DB: **25 tests / 121 assertions, exit 0** — valid baseline candidates, not pre-existing reds.
*Failure scenario:* M1 takes R-3's **delete-with-branch** shape and removes `ReportGenerationService.php:233-244` together with `buildExpectedPerMethod()` (`:514`) — the report itself anticipates this at `:126` ("the legacy count branch that M1 removes"). The declared baseline then never drives the Z-report endpoint with a `cash_counts` payload through the sync controller, never re-checks `pos_z_report_counts` persistence, and never re-pins the schema-v2 hash-input golden bytes that carry the `cash_counts` keys. M1 reports green on 15+1 files; a shape change in the fiscal hash input or a break in counts persistence surfaces only at M5, which re-reads an accumulated evidence chain whose baseline never covered the surface.
*Honest limits:* `GenerateZReportWithCountsTest` **is** declared and would catch a total break of the branch; the two `HashGoldenByteTest` files exercise the normalizer, which M1 should not touch. The marginal, unmitigated exposure is the sync-controller persistence path, the request-validation contract on an inventoried citation, and tenant isolation on that payload.
*Also unfixed:* the brief's step 4 asks for "every suite **directory** the wave will touch (api Feature/Unit POS + Compliance + Treasury…)". The report declares files and says only "No directory-wide PHPUnit invocation is claimed" (`:126`) — round 3 explicitly ruled that a disclosure, not a deviation, and asked for one of two closes. Neither was done; the enumeration was instead deferred into M1's grep-proof (`:225`), but consumers ≠ covering tests, and M0 is where the baseline is pinned.
**Minimal close (either one):** add the enumerated paths to the API command, **or** state the file-level narrowing as a declared deviation with its rationale plus the enumerated list of knowingly-excluded covering files.

**2 — P3 · CONFIRMED · `docs/sessions/codex-sv-stage1-report.md:204`**
The isolation rationale does not reproduce. The report states a combined 16-file run "exhausted the local PostgreSQL server's lock table (`SQLSTATE[53200]: out of shared memory`) late in `GenerateZReportWithCountsTest`". I ran exactly that 16-file command against a fresh dedicated DB: **`Tests: 66 warnings (9124 assertions)`, 90.68s, no failures, no `53200`, no `25P02`**.
*Failure scenario:* an environmental constraint that a reviewer cannot reproduce is presented as established fact and used to justify splitting the declared baseline into two commands. A later session inherits "16 files exhausts the host" as a standing limit and narrows M1–M5 baselines on it. **Not masking** — I verified the combined set is green, so nothing is hidden; the counts are also arithmetically consistent (63+3=66, 9117+7=9124). **Fix:** either re-qualify the claim as an observed one-off under unstated host load, or drop the split and declare one 16-file command.

**3 — P3 · CONFIRMED (carried from rounds 2 and 3, still unfixed) · brief `CODEX-DISPATCH-sv-stage1-2026-08-11.md:104-107` (orchestrator-side)**
Base-check step 3's allowlist still names `docs/superpowers/reviews/*` while this wave's register is `docs/handoff/reviews/sv-stage1/` (`:314`) and its report lives in `docs/sessions/`. Run mechanically at `HEAD` today it returns 8 paths, of which `M0-round1/2/3.md` and `codex-sv-stage1-report.md` read as "contamination" — the agent's own required deliverables. The report resolves this by reinterpreting the check as dispatch-time-only (`:227`), which is a defensible reading and preserves the substantive guarantee (I independently confirmed zero production paths in `base..HEAD`), but the check is no longer mechanical. Not the implementer's defect; fix the brief before M1.

**4 — P3 · CONFIRMED · `docs/sessions/codex-sv-stage1-report.md:236-241`**
"M0 files touched" lists only the progress YAML and the report. The M0 commits also add `docs/handoff/reviews/sv-stage1/M0-round1.md`, `M0-round2.md`, `M0-round3.md`. Harmless in substance (docs, and they are the harness's own outputs) but the section reads as a complete inventory and is not one — the same class of precision the citation inventory is otherwise held to.

---

### Bypasses attempted that FAILED (the implementation held)

- **Tried to prove the 63/9117 figures were inflated:** ran the 15-file command plus the guard on a fresh DB → 66 tests / 9124 assertions. Both declared figures corroborated, to the assertion.
- **Tried to prove the guard isolation was hiding a red:** ran the combined 16-file set — green. It is a reproducibility defect (finding 2), not masking.
- **Tried to prove the device/web counts were fabricated:** 6 files/74 tests and 1 file/8 tests reproduce exactly.
- **Tried to break the citation inventory on ~20 entries rounds 1–3 never touched:** every one resolves to the claimed symbol *and* semantic anchor, including the four internal anchors inside `buildExpectedPerMethod` (528 / 532-555 / 576-581) which I had to re-derive twice because my own first hand-count was off by one — the report's were right.
- **Tried to prove the R-5 Arabic finding was overstated:** device locale tree, `i18n.ts` imports, `lng`/`fallbackLng` and the four namespaces are exactly as reported; `apps/web/src/locales/ar/` exists without `compliance.json`, exactly as `:84` claims.
- **Tried to find an existing `FraudSettingsPage` web test the baseline should have declared:** none exists (`pages/__tests__/` holds only `QuarantineResolveAssistPage.test.tsx`). The report's plan to add it at M3 is the correct call, not a gap.
- **Tried to break the base check:** all three legs re-derived at `HEAD` — ancestor PASS, digest byte-exact, tree clean.
- **Tried to find production-code drift in a no-production-code milestone:** docs only, 8 paths.

### Lens applicability
`fiscal-pos` applied and load-bearing: finding 1 is a fiscal finding — the undeclared surface is `cash_counts` persistence to `pos_z_report_counts` and the schema-v2 **hash-input golden bytes**, i.e. the NF525 chain input, on the exact branch M1 is authorised to delete. The whole-drawer (`CashDrawerService::calculateExpectedCash`, scope-fenced and untouched) vs takings-only (`buildExpectedPerMethod`) split, the schema-v3 server-authoring chokepoint, the blind-reveal gate (`CashCountTable.tsx:64-65`), the `EndOfDayPreviewModal` leak guard and the device-vs-server layering of `require_blind_cash_count` are each accurately characterised. Rule 19 precision, red-first evidence, tenant scoping, constructor injection, migration safety, Horizon queue coverage and en+fr strings are **not applicable at M0** — no production code, no migration, no queue, no DI surface, no user-facing string.

Finding 1 is the only blocking item; its close is one prose paragraph or six added test paths. Findings 2–4 may ship, with 3 belonging to the orchestrator.

VERDICT: CHANGES-REQUIRED

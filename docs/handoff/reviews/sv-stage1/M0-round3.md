I re-ran the declared baselines myself and hunted the M1/M3 blast radius by symbol. Here is the round-3 register.

## M0 review register — sv-stage1, round 3 (lens: fiscal-pos)

**Independently verified at `HEAD = 0399bf215`.** Base check re-derived, not read: `merge-base --is-ancestor df85d43f4 HEAD` → PASS; digest at `HEAD` → `04760455ac…20540` (exact match); `git status --porcelain` → empty; `git diff --name-only df85d43f4..HEAD` → 7 docs paths, **zero production code**. `0399bf215` moves only the YAML `commit:` pointer line — `370ae9701` is the real M0 content commit, so the pointer is honest.

**Baselines re-run by me, not accepted on assertion — all three reproduce exactly:**
- API, the literal 15-file command against a fresh `autoerp_sv_stage1_m0r3_test` on local PG 5432: exit 0, **`Tests: 63 warnings (9117 assertions)`** — identical to `:137`. (Duration 112.06s vs the report's 57.80s: machine variance, same as round 2 saw.)
- Device: `Test Files 6 passed (6)` / `Tests 74 passed (74)` — exact.
- Web: `Test Files 1 passed (1)` / `Tests 8 passed (8)` — exact.

**Round-2 findings — closure verified against code, not assertion:**
- **R2-#1 (P2, mis-attributed warnings) → CLOSED.** `apps/api/.env` is genuinely absent (only `.env.example`, `.env.production.example`); `tests/TestCase.php:22-26` is `setUp()`/`parent::setUp()`, matching the reported dotenv frame. The attribution at `:200` is now correct, the `.env`-absence consequence is recorded, `TREASURY_SHIFT_VARIANCE_GL_ENABLED` unset + `config/treasury.php:29` default `false` is stated and true, and `:202` replaces the inference with a real antidote — force `LOG_LEVEL=warning` **and spy the facade** for `Log::warning()`. That satisfies R-7's "silence ≠ success" without depending on the threshold. Good fix.
- **R2-#2 (P2, missing coverage for the wave's own surfaces) → CLOSED for the three named files.** `GenerateZReportWithCountsTest.php` (`:164`; it does set `require_blind_cash_count => false` at `:778`), `migration22.integration.test.ts` and `companyFraudSettingsCacheRepository.test.ts` (`:183-184`) all exist and are in the literal commands. Re-opens only in the narrower form of finding 1.
- **R2-#3 (P3, paraphrased summary) → CLOSED** — `:137-138` now carries the verbatim `Tests:` / `Duration:` lines with the count present.
- **R2-#4 (P3, brief allowlist) → NOT CLOSED** → finding 4.

**Antidote executed — 13 citations re-derived, none overlapping rounds 1 or 2. All exact:**
`ReportGenerationService.php:84-89` (`assertServerReportAuthoringAllowed`, schema ≥3 → `ServerFiscalAuthoringRetiredException::zSessionDeviceAuthority`) · `:219` (`$reportData['expected_cash'] = …calculateExpectedCash($shift)`) · `config/treasury.php:19-25` (refuted premise verbatim: "sums receipt payments ONLY … booked to 658/758 on every single close") · `PostShiftCashVarianceAdjustment.php:44-52` (SHIPS DISABLED block) and `:152-157` (`handle()` + kill-switch early return) · `FraudSettingsResolver.php:30`/`:45` · `CompanyFraudSettings.php:58`/`:120`/`:144`/`:179` · `GenerateZReportRequest.php:61-68` · `CashCountToleranceVarianceRegressionTest.php:79-81` (the historical note to preserve) · `ServerReportAuthoringUnreachabilityTest.php:49` · `ZReportServerAuthoringChokepointTest.php:12` · `CashReconciliationSection.tsx:84,87,229,260` · `CashCountTable.tsx:64-65` · `EndOfDayPreviewModal.tsx:240-262` — and the report's correction of the dossier's `239-262` is **right**: the SECURITY comment opens at 240, and the dossier's SV-9-row `:242-244` lands inside it.

**R-6 list:** faithful to the dossier's canonical six (`FINDINGS-…:206`), migration as point 3, R-7's checks appended at `:120`, outcome recorded either way. Complete.

---

### Findings

**1 — P2 · CONFIRMED · `docs/sessions/codex-sv-stage1-report.md:126`, `:149-173`**
The declared API set misses a **green, live guard on the exact symbol M1 retires**. `tests/Feature/POS/FiscalStatusFilterTest.php` pins that the Z-report tender sum excludes `pending_seal` receipts — its docblock names the site (`:30` "1. `ReportGenerationService::buildExpectedPerMethod()` (Z-report tender sum)") and `test_z_report_aggregation_excludes_pending_seal_receipts` (`:91`) drives it through the artisan Z-report path precisely because the method is private (`:88`). It is not in the 15-file command. I ran it: **3 tests / 7 assertions, exit 0** — a valid baseline candidate, not a pre-existing red.
*Failure scenario:* M1 takes R-3's delete-with-branch shape. The pending_seal exclusion on the Z-report tender-sum path loses its only dedicated coverage; the declared baseline (which never executes it) reports green; M1's own evidence contract names only the two chokepoint guards, so nothing else catches it; M5 then re-reads an accumulated evidence chain whose baseline never covered the surface. Under the `@deprecated`-annotation shape nothing breaks — but the shape is M1's choice, not M0's, and M0 is where the baseline is pinned.
*Honest scoping:* this is the same class as R2-#2, surfaced by a **by-symbol** grep (`grep -rln buildExpectedPerMethod tests/`) rather than by touch point. **Fix:** add the one path to the API command (or declare the file-level narrowing as a deviation with rationale — the report currently discloses "no directory-wide invocation is claimed" at `:126`, which is a disclosure, not the deviation the house rule's "every suite directory the diff touches" asks for).

**2 — P3 · CONFIRMED · `apps/api/app/Modules/Accounting/Application/Services/Reports/SalesReportService.php:258-259`**
A **live production cross-reference** to `ReportGenerationService::buildExpectedPerMethod` with a hard line range (`ReportGenerationService.php:504-532`) sits inside a different, shipping report's change_due fan-out rationale. It is in neither the citation inventory nor R-2's three artefacts. **Not an M0 failure by the letter** — the inventory is bounded to what the brief and dossier cite, and I verified the dossier cites this file nowhere. Recorded so M1 does not meet it as a surprise: delete-with-branch leaves a dangling reference with a stale range in a live sales/GL service. M1's enumerated grep-proof should surface it automatically.

**3 — P3 · CONFIRMED · `apps/api/app/Modules/Compliance/Application/Services/CompanyFraudSettingsService.php:22-25`**
A **fourth artefact** asserting the vertical split M3 overturns: *"Otospex companies (automotive) get blind cash counting enabled; IziPOS companies (retail / all others) get it disabled."* Not in the inventory, not among the dossier's six touch points (verified at `FINDINGS-…:206`). Behaviourally safe — `:31` delegates to `defaultsForVertical()`, so the flip propagates and its only test (`CompanyFraudSettingsVerticalDefaultsTest`) is already in the declared set — but after M3 the docblock states a retired policy. Note for M3, same treatment as `CompanyFraudSettings.php:190-193`.

**4 — P3 · CONFIRMED (carried from round 2, unfixed) · brief `CODEX-DISPATCH-sv-stage1-2026-08-11.md:104-107` (orchestrator-side)**
The base-check step-3 allowlist still names `docs/superpowers/reviews/*` while this wave's register is `docs/handoff/reviews/sv-stage1/` (`:314`) and its report lives in `docs/sessions/`. Re-running check 3 at `HEAD` now returns 7 paths, of which `M0-round1.md`, `M0-round2.md` and `codex-sv-stage1-report.md` read as "contamination" — the agent's own required deliverables. The report works around it by pasting `base..a5520f23c` (`:28-42`, disclosed as the baseline-to-dispatch delta) rather than `base..HEAD`. Not the implementer's defect; fix in the brief so the check stays mechanical.

---

### Bypasses attempted that FAILED (the implementation held)

- **Tried to prove the 63/9117 figures were inflated:** ran the literal 15-file command myself against a fresh DB → `Tests: 63 warnings (9117 assertions)`, exit 0. Byte-identical counts.
- **Tried to prove the device/web counts were fabricated:** 6 files/74 tests and 1 file/8 tests reproduce exactly.
- **Tried to prove M3's default flip breaks tests outside the declared set:** enumerated every test matching `require_blind_cash_count|requireBlindCashCount|blind_count_used|blindCount`. The five outside the set — `GenerateZReportEndToEndTest:98,134`, `GenerateZReportRequestValidationTest:120,270`, `OpenFraudAlertForShiftVarianceTest:62,82,266`, `PosShiftsScale4Test:87,93`, `ZReportResourceWithCountsTest:97-105,145` — every one sets the **device-supplied shift flag** explicitly or passes an explicit constructor arg. None depends on the setting's default. Not a finding.
- **Tried to find a `getDefaults()` consumer the flip would break:** enumerated all 7 production consumers + 1 test + 2 migrations. `OpenFraudAlertForShiftVariance.php:79` reads only `cash_variance_email_severity`; `OwnerReportingTest.php:413` seeds explicitly; only `CompanyFraudSettingsService.php:31` propagates, and its sole test is already declared.
- **Tried to prove the `.env` story was itself a story:** `apps/api/.env` genuinely absent; `tests/TestCase.php:22-26` matches the reported frame.
- **Tried to prove the YAML pointer under-points the M0 content:** `0399bf215` touches one line, the pointer itself.
- **Tried to break the base check:** all three legs re-derived at `HEAD` independently — ancestor PASS, digest exact, tree clean.
- **Tried to find production-code drift in a no-production-code milestone:** docs only.

### Lens applicability
`fiscal-pos` applied and load-bearing: finding 1 is a fiscal finding — the undeclared guard is exactly the `pending_seal` exclusion on the Z-report tender sum, the same drift-audit surface as the two declared chokepoint guards. The whole-drawer (`calculateExpectedCash`) vs takings-only (`buildExpectedPerMethod`) split, the schema-v3 server-authoring chokepoint, the blind-reveal gate and the device-vs-server layering of the setting are each accurately characterised, and the scope fence around the live formula holds. Rule 19 precision, red-first evidence, tenant scoping, constructor injection, migration safety and Horizon queue coverage are **not applicable at M0** — no production code, no migration, no queue, no DI surface.

Finding 1 is the only blocking item and the fix is one path added to the API command; 2–4 may ship, with 4 belonging to the orchestrator.

VERDICT: CHANGES-REQUIRED

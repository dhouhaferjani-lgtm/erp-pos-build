## M0 review register — sv-stage1, round 1 (lens: fiscal-pos)

**Verified independently at `HEAD = 1d3a7d0bf`.** Base check re-run by me: `merge-base --is-ancestor df85d43f4 HEAD` → PASS; digest at HEAD → `04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540` (matches); `git diff --name-only df85d43f4..HEAD` → 4 admin paths + the M0 report only; `git status --porcelain` clean. **No production code changed** — confirmed, not taken from the report.

**Antidote executed (brief asks for 2; I re-derived 8).** Every one matched symbol + semantic anchor, including honest drift corrections:
- `ReportGenerationService.php:233-244` — call at `:234`, fed to `validate()` at `:239-244`; "before validation" anchor ✓, and the dossier's `233-241` genuinely now ends at 244.
- `ReportGenerationService.php:491-583` — docblock opens at 491, `private function buildExpectedPerMethod` at 514, receipt-sum `selectRaw` at **exactly 528**, zero-fill 576-581 ✓.
- `CashDrawerService.php:387` — `calculateExpectedCash()`, OPENING/SALE add · REFUND/DEPOSIT/PAYOUT subtract, `bcadd`/`bcsub` at `$this->scale()` ✓ (rule 19 clean; correctly fenced as untouched).
- `CompanyFraudSettings.php:190-193` docblock (Otospex ON / IziPOS OFF) and `:201` `$defaults['require_blind_cash_count'] = $isAutomotive;` ✓ exact.
- `CompanyFraudSettingsVerticalDefaultsTest.php:53` = `assertFalse(...require_blind_cash_count)` ✓ — genuinely red-by-design for M3.
- `pos.json` — `cash_count` opens at 906, expected/actual/variance at 912/913/914 ✓ exact.
- `compliance.json:109` = `blindCountLabel` in **both** en and fr ✓.
- `2026-08-08-g3-...deploy-notes.md:51-58` = G-1 stating the refuted premise verbatim ("sums receipt payments ONLY… float would be booked to 658/758 on **every** close") ✓ — this is M1's third artefact and it is real.

**Completeness of "unresolved = 0":** I extracted every `file:line` the dossier gives for SV-1 (76-97), SV-9 (198-218), SV-10 (219-226), SV-11 (227-254), §3 Stage 1, and §5.1's four in-scope rows, and diffed against the inventory. **All 14 SV-1, all 15 SV-9, both SV-10, and the SV-11 citations are present.** The claim is non-vacuous.

**Six-touch-point list:** matches the dossier's canonical enumeration (`FINDINGS-…:206`) 1:1 — defaults · `defaultsForVertical()`+red test · data migration · optional column default · FE `:55`/`:396` round-trip · device SQLite `DEFAULT 0`. Complete.

**Arabic finding:** verified against code — `apps/pos/src/locales/` = `en/`, `fr/` only; `i18n.ts:28-30` `lng:'en'`, `fallbackLng:'en'`, 4 namespaces; `apps/web/src/locales/ar/` exists but has **no `compliance.json`** (correctly flagged as M3 work). Gates `sv11-arabic-device-locale` and `SV-9-blind-default-prerequisite` are both present in the YAML with `blocks_milestone: none`, correctly not wired as milestone stop fields.

---

### Findings

**1 — P2 · CONFIRMED · `docs/sessions/codex-sv-stage1-report.md:124`, `:131-133`**
The declared API regression set is defined by reference to "**the command record below**" — and no command record exists anywhere in the report (`grep` for `phpunit|artisan test|vendor/bin|command record` returns only line 124 itself). The declaration is therefore unreproducible, and it is internally contradictory: it names `tests/Unit/POS/` as declared scope, but that directory contains `CashCountValidationServiceTest.php`, which fatally errors — `FraudSettingsDTO::__construct` requires **11** parameters (`FraudSettingsDTO.php:13-34`, last three have no defaults) while the test passes **8** (`CashCountValidationServiceTest.php:35-44`, `:216-225`). An `exit 0` run demonstrably did not execute the declared directory.
*Failure scenario:* at M1–M4 the agent claims "declared regression set green"; the reviewer has no command to re-run and no way to detect that the scope silently narrowed around a newly-broken test. M5's whole-lane gate then re-reads an evidence chain whose baseline was never pinned. **Fix:** paste the literal command(s) actually run, plus the explicit `--exclude`/omitted files with reasons.
*(The two disclosed pre-existing reds are genuine, not excuses — the `payment_repositories.balance` trigger claim is backed by `apps/api/database/migrations/tenant/2026_07_08_160000_forbid_direct_payment_repository_balance_writes.php`. The report is incomplete here, not deceptive.)*

**2 — P3 · CONFIRMED · `docs/sessions/codex-sv-stage1-report.md:133`**
The pasted PHPUnit summary is `Tests: 50 warnings (9033 assertions)` — the **test count is absent**. There is no way to tell whether 12 or 1,200 tests ran, which is the number every later "no regression" claim is measured against.

**3 — P3 · CONFIRMED · `docs/sessions/codex-sv-stage1-report.md:91`**
Inventory row resolves `CashReconciliationSection.tsx:165-180 → 164-184`, but the `aggregateSeverity` memo actually spans **164-188** (closes at `CashReconciliationSection.tsx:188`, `}, [physicalTenders, actuals, fraudSettings, scale]);`). Symbol and semantic anchor are correct, so M4's audit is not misdirected; the range is just short by four lines.

**4 — P3 · CONFIRMED · dossier `FINDINGS-shift-variance-gl-2026-08-11.md:208`**
The SV-9 row's ⚠️ note cites `project_live_counting_completion_lane.md:40`, which is unaddressed in the inventory. It is a memory-directory path outside the repo, so "unresolved = 0" holds for in-codebase citations; noted only because the claim is stated absolutely.

---

### Bypasses attempted that FAILED (i.e. the implementation held)

- **Tried to break the base pin:** ran all three checks independently — ancestor, digest at HEAD, admin-only delta. All pass. Also checked `base..a5520f23c` separately to confirm the report's own 4-file diffstat was accurate at the time of writing. No contamination.
- **Tried to prove the citation inventory incomplete:** first extraction (dossier lines 76-197) surfaced ~50 "missing" citations. That range was contaminated — it spans SV-2…SV-8, all explicitly out of scope. Re-bounded to `### SV-1` (76-97) only: every citation resolves. My initial hypothesis was wrong.
- **Tried to prove §5.1 citations were dropped** (`g3-legacy-closeshift-no-gl-leg.md:51-53`, `CashDrawerService.php:201-213`, `zSessionAuthoring.ts:664-711`): all three belong to the SV-2/SV-4/SV-7-8 rows, not the four in-scope rows. Not a finding.
- **Tried to catch a fabricated pre-existing red:** both disclosed reds verified real against code and migrations.
- **Tried to find production-code drift smuggled into a "no production code" milestone:** `git diff --name-only` shows docs only.

### Lens applicability
`fiscal-pos` applied: the inventory's fiscal semantics (whole-drawer `calculateExpectedCash` vs takings-only `buildExpectedPerMethod`, the schema-v3 server-authoring chokepoint at `:84-89`, the blind-reveal gate at `CashCountTable.tsx:64-65`) were each re-derived and are accurately characterised; the scope fence around the live formula is correctly stated. Rule 19, red-first, tenant scoping, migrations and Horizon-queue checks are **not applicable** at M0 — no production code, no migration, no queue.

Fix round 1 should close finding 1 (and 2, same edit); 3 and 4 may ship as-is.

VERDICT: CHANGES-REQUIRED

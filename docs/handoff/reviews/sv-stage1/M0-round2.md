I re-ran the declared command myself and traced the warnings to their real source. Here is the round-2 register.

## M0 review register — sv-stage1, round 2 (lens: fiscal-pos)

**Independently verified at `HEAD = f5c10022c`.** Base check re-derived, not read: `merge-base --is-ancestor df85d43f4 HEAD` → PASS; digest at HEAD → `04760455ac…20540` (match); `git status --porcelain` → empty; `git diff --name-only df85d43f4..HEAD` → docs only, **no production code**. The diffstat pasted at `codex-sv-stage1-report.md:28-33` is **byte-identical** to my own `git diff --stat df85d43f4..a5520f23c` — not fabricated.

**Round-1 findings — closure status (verified, not accepted on assertion):**
- **R1-#1 (P2, unreproducible regression set) → CLOSED.** A literal 14-file command is now pasted (`:150-170`), the `tests/Unit/POS/` directory claim is gone (`:126`), and both omissions are named with reasons (`:172-177`). I re-ran it verbatim: exit clean, no failures.
- **R1-#2 (P3, missing test count) → CLOSED and CONFIRMED.** `phpunit --list-tests` over the same path list returns **exactly 50**; static method count across the 14 files also sums to 50. Both `autoerp_sv_stage1_test` and `autoerp_sv_stage1_m0r1_test` exist on local PG — the runs happened.
- **R1-#3 (P3, `164-184` → `164-188`) → CLOSED** (`:91` now reads `137-157,164-188`).
- **R1-#4 (P3, memory-path citation) → CLOSED** (`:98` states it explicitly and excludes it from the in-repo count).

**Antidote executed — 6 citations re-derived, none overlapping round 1's eight.** All exact: `FraudSettingsController.php:35` (allowlist entry) / `:120` (`sometimes|boolean`); `CompanyFraudSettingsData.php:44/75/105` (property / persisted / defaults mapping); seed migration `:37-43` (`defaultsForVertical($isAutomotive)` → `create`); `migrations.ts:518` (`require_blind_cash_count INTEGER NOT NULL DEFAULT 0`); `endOfDayPreview.ts:90` (`export interface EndOfDayPreview`) and `:406` (the `expected_cash = opening + net cash sales + deposits − payouts` comment); `pos.json` `cash_count` opens at **906**, expected/actual/variance at **912/913/914**, and FR `variance` = **"Écart"** — the M2 wording claim at `:92` is real.

**R-6 list:** faithful. R-6 step 2 defines *one* question asked per touch point, so the report's single-question-over-six-points formulation (`:111-119`) is the brief's shape, not a shortcut. The data migration is point 3, and `:120` adds the R-7 checks. Complete.

---

### Findings

**1 — P2 · CONFIRMED · `docs/sessions/codex-sv-stage1-report.md:179`**
The baseline's warnings are mis-attributed. The report says they come "from repository source-inventory `file_get_contents(...)` checks when run from a linked worktree." They do not. I captured the verbatim warning:

```
file_get_contents(/…/.worktrees/sv-stage1/apps/api/.env): Failed to open stream: No such file or directory
  at vendor/vlucas/phpdotenv/src/Store/File/Reader.php:73
  8   tests/TestCase.php:26
```

`apps/api/.env` **does not exist in this worktree** (`ls`: only `.env.example`, `.env.production.example`). That is why all 50 tests are WARN — not source inventory, which only 2 of the 14 files even perform (`ZReportServerAuthoringChokepointTest.php:89`, `ServerReportAuthoringUnreachabilityTest.php:110`).
*Failure scenario:* the declared baseline runs with **no `.env` loaded**, so every env-driven config silently takes its framework default. This lands directly on **R-7**, which requires the SV-9 migration's completion token at **warning** level *because production runs `LOG_LEVEL=warning` and swallows `Log::info`* — in this env `LOG_LEVEL` defaults to `debug`, so an M3 test asserting the token passes regardless of level, and the one thing R-7 exists to prevent (a silent unattended migration on staging) ships unproven. Secondarily, a 50/50-WARN baseline has zero warning-delta signal: a genuinely new PHP warning introduced at M1–M4 is indistinguishable from the pre-existing noise. **Fix:** correct the attribution, record that `apps/api/.env` is absent and which config values are therefore defaults, and state how M3 will prove the warning-level token despite it.
*(Checked and cleared: `TREASURY_SHIFT_VARIANCE_GL_ENABLED` is unset in both `.env`-absence and `phpunit-pgsql.xml`, and `config/treasury.php:29` defaults it to `false` — the flag under the wave's scope-fence is correctly off in the baseline. The env gap does not corrupt M1's subject.)*

**2 — P2 · CONFIRMED · `docs/sessions/codex-sv-stage1-report.md:126-128`**
The declared set omits covering tests for two of the wave's **own** in-scope surfaces. House rule requires "every suite directory the diff touches"; the report narrows to 14 named API files + 4 device files, states it as fact, and justifies only the 2 broken exclusions — the other ~350 files in `tests/Feature/POS` (119), `tests/Feature/Treasury` (119), `tests/Feature/Fiscal` (73) are dropped without a declared deviation. Concretely:
- **`apps/pos/src/lib/db/migrations.ts:518` is SV-9 touch point 6**, and the only test that executes that DDL is `apps/pos/src/lib/db/__tests__/migration22.integration.test.ts:85` — **excluded**. The 4 declared device tests (`endOfDayPreview`, `CashReconciliationSection`, `CashCountTable`, `EndOfDayPreviewModal`) are component/lib tests; none build the device schema.
- **`companyFraudSettingsCacheRepository.ts` is named in the inventory itself (`:86`)** while its covering test `src/lib/db/repositories/__tests__/companyFraudSettingsCacheRepository.test.ts:70,75` is excluded.
- `apps/api/tests/Feature/POS/GenerateZReportWithCountsTest.php:778` sets `require_blind_cash_count` explicitly and is excluded.

*Failure scenario:* M3 edits `migrations.ts:518` (an explicitly named touch point). A DDL slip in that `CREATE TABLE` breaks device schema construction; no test in the declared set executes it, so the milestone reports green, and M5's whole-lane gate re-reads a baseline that never covered the surface. **Honest limitation:** none of these three would catch a *default-value* flip (they all pass explicit values / assert column type), so the gap is about DDL and mapping edits, not the default itself. **Fix:** add the three files, or declare the file-level narrowing as a deviation with its rationale per the deliverable's "deviations with rationale".

**3 — P3 · CONFIRMED · `docs/sessions/codex-sv-stage1-report.md:136`**
"PHPUnit summary: 50 warnings (9033 assertions)" is a paraphrase that reads as *50 tests passed, some with warnings*. The verbatim final line is `Tests:    50 warnings (9033 assertions)` — there is **no `passed` segment at all**; every test in the declared baseline carries WARN status, zero carry PASS. Duration also drifts (report: 39.17s / 42.78s; my run: 69.55s) — machine variance, noted only because the summary block is presented as literal output. **Fix:** paste the verbatim last three lines.

**4 — P3 · CONFIRMED · brief `CODEX-DISPATCH-sv-stage1-2026-08-11.md:104-107` (orchestrator-side)**
The admin-path allowlist for base-check step 3 names `docs/superpowers/reviews/*`, but this wave's review register is `docs/handoff/reviews/sv-stage1/` (`:314`) and its report lives in `docs/sessions/`. Re-running check 3 at any post-M0 HEAD therefore flags the agent's own **required deliverables** as "contamination". Both review rounds have silently worked around this. Not the implementer's defect; fix in the brief before M1 so the check stays mechanical.

---

### Bypasses attempted that FAILED (the implementation held)

- **Tried to catch a fabricated test count:** independently ran `phpunit --list-tests` over the literal path list → **50**; static `public function test`/`#[Test]` count also 50. Then ran the full literal command against `autoerp_sv_stage1_m0r1_test`: 50 tests, 9033 assertions, **no failures**. The central fix-round claim is real.
- **Tried to prove the pasted base-check diffstat was hand-written:** `git diff --stat df85d43f4..a5520f23c` reproduces it byte-for-byte, including the `18/21/20/20` per-file counts.
- **Tried to prove the databases never existed:** both `autoerp_sv_stage1_test` and `autoerp_sv_stage1_m0r1_test` are present on local PG 5432.
- **Tried to break the citation inventory on six entries round 1 never touched:** all six resolve to the claimed symbol *and* semantic anchor, including the FR `Écart` wording and `endOfDayPreview`'s whole-drawer formula comment.
- **Tried to prove the `.env` gap corrupted M1's subject:** it does not — `shift_variance_gl_enabled` defaults `false` at `config/treasury.php:29` with no env override anywhere, which is the required shipped state.
- **Tried to find a blast-radius test outside the declared directories that a `getDefaults()` flip would break:** only `tests/Feature/Accounting/OwnerReportingTest.php:413` consumes `getDefaults()`, and it asserts `expected_cash` on explicitly-seeded shifts, not blind behaviour. Not a finding.
- **Tried to prove R-6's question list was a paraphrase:** R-6 step 2 asks the *same* question per touch point; the report's formulation is faithful.
- **Tried to find production-code drift in a "no production code" milestone:** `git diff --name-only` is docs-only.

### Lens applicability
`fiscal-pos` applied: the whole-drawer (`CashDrawerService::calculateExpectedCash`) vs takings-only (`buildExpectedPerMethod`) distinction, the schema-v3 server-authoring chokepoint, the blind-reveal gate at `CashCountTable.tsx:64-65`, and the device-vs-server layering of the blind setting are each accurately characterised, and the scope fence around the live formula is correctly stated. Rule 19 precision, red-first evidence, tenant scoping, constructor injection, migration safety and Horizon queue coverage are **not applicable at M0** — no production code, no migration, no queue, no DI surface. Finding 1 is the one place a lens reaches forward: it degrades the evidence environment for R-7, which M3 depends on.

Round 2 should close findings 1 and 2 (both are documentation edits plus three added test paths); 3 and 4 may ship, with 4 belonging to the orchestrator.

VERDICT: CHANGES-REQUIRED

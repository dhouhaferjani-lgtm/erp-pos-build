# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r2 / dev `a5520f23c`
Runner: Claude (Fable 5), mechanical precheck session (full re-run after r1 FAIL) · Date: 2026-08-12

Verification tree: `/Users/houssamr/Projects/syneriva/apps/erp`, branch `dev`, tip `a5520f23c` — **identical to the r1 run's tree and to the brief's own stated verification base**. `git status` shows zero uncommitted modifications to any cited code or doc surface (only unrelated `.claude/` agent files and handoff docs are dirty), so every citation was re-checked against exactly the tree the brief describes. No drift caveat applies.

| # | Check                         | Result    | Findings |
|---|-------------------------------|-----------|----------|
| 1 | Revision-log truth            | PASS      | 0 (both r2 claims verified against the document diff — table below) |
| 2 | Exhaustive claims proven      | PASS      | 0 FAIL, 2 observations (carried from r1, unchanged) |
| 3 | Test contracts executable     | PASS      | 0 FAIL, 1 observation (carried, unchanged) |
| 4 | Behavior claims cited         | PASS      | 0 FAIL — r1's R0-1 and R0-2 both fixed and re-verified; 1 new sub-observation (O-6, non-failing) |
| 5 | Permission keys verified      | PASS      | 0 (brief names no permission/module keys — grep empty, exit 1) |
| H | Hygiene (pipes, banner)       | PASS      | 0 (4 tables, all pipe-consistent; banner r2 = last revision-log entry r2) |

**VERDICT: PASS.** All five checks plus hygiene clean. The r2 document is round-0 clean and may accompany the adversarial brief-gate dispatch (which it still owes per its own banner: "NOT yet brief-gated").

---

## Check 1 — revision-log truth (every claim, not sampled)

| Claim (r2 log) | Section | Verified |
|---|---|---|
| R0-1: "ParserFactory claim narrowed to the 4 actual users" | §2 Deliverable 1 | **YES.** Old universal ("the existing `tests/Architecture/*.php` use `ParserFactory` + visitors") is gone. New text: "several of the existing `tests/Architecture/*.php` use `ParserFactory` + visitors — 4 of 16: `AuthLifecycleTest`, `BroadcastChannelTenantContextTest`, `TenantScopedExistsRulesTest`, `TenantScopedFindCallsTest`; the rest are `token_get_all`/regex static scans — and none use `RefreshDatabase`". Census re-run: `ls tests/Architecture/*.php | wc -l` → **16**; `grep -l 'ParserFactory\|PhpParser'` → exactly those **4** files; `grep -l RefreshDatabase` → empty (exit 1). Names and counts exact. |
| R0-2: "cementing-guard quote replaced with verbatim source text at file:line" | §1 Mission | **YES.** The r1 fabricated-quote form ("the plan's own words: 'Cementing guard (last): …'") is gone. New text cites `docs/superpowers/audits/2026-08-08-document-per-action-violation-sweep.md:136-139` and quotes it. Scripted character-for-character comparison (source lines 136–139, soft line-wraps joined by single spaces, `7. ` list marker stripped) vs the brief's quoted string → **MATCH** (Python equality on the full strings). `grep -n 'Cementing guard'` confirms the source text starts at line **136** — the cited range is correct. |

Banner (line 4, "Revision: r2 … round-0 fixes applied, NOT yet brief-gated") agrees with the last revision-log entry (line 5, r2). The surrounding prose "scheduled its cementing guard **last**" sits outside quote marks as the brief's own characterization — supported by the source (the guard is step 7 of 7, "after fixes"); same disposition as r1's O-2.

## Findings

None. Zero FAIL items.

## Observations (non-failing)

- **[O-1] (carried from r1, text unchanged in r2) Check 2 — counts sourced outside the repo tree:** "10 violations + 3 GL-gap grays", "~25–30 dev-days" (DPA register/plan), "25+ live C6 violations" (UI Wave 0 T7). Not mechanically re-runnable here; the brief self-flags the register-path gap (F-5) and makes the register cross-check a P1 deliverable (D4). The C6 mechanism claim IS verified (appendix: `STATUS_RE` still lacks `Tone`).
- **[O-3] (carried, unchanged) Check 3 — placeholder identifiers in P3 acceptance** (`--filter '^…UnbalancedGuard…$'`, `<path-to-country-chart-completeness-test>`): commands for tests the package itself authors; form exact and runnable once instantiated; `tests/Feature/Treasury` exists. Acceptable for a dispatch brief.
- **[O-4] (carried) `docs/handoff/reviews/` still does not exist** — harmless: `scripts/adversarial-review.sh:47` does `mkdir -p "$(dirname "$OUT")"`.
- **[O-5] (carried, re-confirmed at `a5520f23c`) Executor conditionals' current state:** UI Wave 0 T2 NOT landed (`gen-route-manifest.mjs:31-34` `WRAPPERS` lacks `KeyedByRouteId`, grep count 0); T3(b) NOT landed (`grep check-manifest-drift .github/workflows/*.yml` → exit 1); T7 NOT landed (`STATUS_RE` at `audit-design-system.mjs:59-63` suffix alternation is `Colors?|Classes?|Maps?|Styles?|Config|Badge` — no `Tone`). All three "check whether it landed" branches take the not-landed path on today's tree.
- **[O-6] NEW, Check 4, borderline-but-not-failing — §2 D1 residual gloss "the rest are `token_get_all`/regex static scans":** 11 of the 12 non-ParserFactory tests match that literally (`file_get_contents`/`preg_match`/`token_get_all`/`str_contains` — enumerated in the appendix); the 12th, `OrphanedTypesCleanupTest`, is a pure `assertFileDoesNotExist` filesystem assertion — static, no parser, no regex. Not failed because the claim's operative content — all 16 are static and none needs a database — is exactly true for all 16, and no executor decision changes on the outlier (the instruction "PHP-Parser AST, no DB" is unaffected). Flagged so the adversarial gate sees it; a one-word soften ("static scans") would make it airtight.

---

## Evidence appendix — full re-run at `a5520f23c`

### Check 1/4 code citations (all re-executed this run)

| Brief claim | Re-verified | Result |
|---|---|---|
| S0 seam: `StockAdjustmentService::recordMovement` `:1700`; `?StockMovementReferenceType $referenceType = null` / `?string $referenceId = null` `:1715-1716`; `assertReferenceLinkagePaired` `:1719` | sed on exact lines | EXACT |
| `AccountingService`: `DoubleEntryValidator` injected `:54`; `assertLegsBalance` `:313` (uses `isSumBalanced` `:333`, ends `:356`); call sites invoice `:503`, credit note `:716`, cancellation `:929` | sed on exact lines | EXACT |
| `GeneralLedgerService` `postEntry` `:2874`, `postEntryNow` `:3450`; zero `DoubleEntryValidator`/`isSumBalanced` refs in the file | grep -n / grep -c → 0 | EXACT |
| `requiredPurposes()` at `SystemAccountPurpose.php:160` returns **13** (11 + `CostOfGoodsSold` + `GeneralExpense`), with the "France booked zero COGS silently (PostCOGSOnInvoice swallows the miss)" comment | sed 160–187; counted 13 cases; comment present verbatim | EXACT |
| `expectedAccountType()` at `:~186` | actual :187 (tilde-qualified) | OK |
| `ChartOfAccountsService::validateCompanyAccounts()` iterates the list `:51` | `foreach (SystemAccountPurpose::requiredPurposes() ...)` at :51 | EXACT |
| `TreasuryReceiptBridge`: GLS injection `:153`; not-globally-unique comment `:323`; local `$requiredPurposes = [` lists `:446`, `:528` | sed on exact lines | EXACT |
| P3(a) zero-validator claim: `grep 'DoubleEntryValidator\|isSumBalanced\|Unbalanced'` over the 5 named files (`TreasuryReceiptBridge`, `TreasuryAccountChargeBridge`, `RepositoryAdjustmentService`, `InstrumentLifecycleService`, `ReverseWriteOffService`) | exit 1 (no hits) | EXACT |
| `ReverseWriteOffService` posts via injected `GeneralLedgerService` `:58` | `private readonly GeneralLedgerService $glService,` at :58 | EXACT |
| Model paths: `JournalEntry.php`, `StockMovement.php`, `StockLevel.php`, `BatchStock.php`, `StockMovementReferenceType.php`; batch writers `FEFOInventoryService`, `BatchWriteOffService`, `GroupedWriteOffService` (Application/Services), `ReverseWriteOffService` | ls — all exist at cited paths | EXACT |
| `phpunit.xml:17-18` suite `Architecture` → `tests/Architecture` | sed 17–18 | EXACT |
| Architecture census: 16 files; ParserFactory users = 4 (named); `RefreshDatabase` = 0 | wc -l / grep -l | EXACT (basis of Check-1 R0-1 row; O-6 gloss note) |
| Sweep-plan quote vs source `:136-139` | scripted char-for-char, soft-wraps joined | **MATCH** (basis of Check-1 R0-2 row) |
| `TenantScopedFindCallsTest` `#[Group('sweep-progress')]` pattern; `tests/Architecture/fixtures/` exists | re-confirmed (unchanged tree) | EXACT |
| `PLAN-p0-fix-lanes-pre-production-2026-08-05.md` §W-6 `:53` — D1a validator on `createInvoiceGLEntries()`; D1b −19.000 = campaign test money, not a code fix | sed :53, verbatim incl. both dispositions | EXACT |
| `AGENTS.md:16` commit format `Phase <major.minor.patch>: <imperative summary>` | sed :16 | EXACT |
| `usePermissions.ts:130-134` `canAccessModule` fails open | `if (!requiredPermissions) return true` at :132 | EXACT |

### CI workflow citations (`.github/workflows/ci.yml`)

| Brief claim | Result |
|---|---|
| Job anchors: `backend-lint` :27 · `backend-analyse` :105 · `backend-architecture` :143 · `backend-test` :180 · `frontend-lint` :853 · `frontend-typecheck` :893 · `frontend-test` :918 · `frontend-build` :977 · `types-drift` :1012 · `all-checks-pass` :1090 | ALL EXACT (sed on each line) |
| Only `--testsuite` invocation is `Unit` `:275` (others are comments :287, :298) | EXACT |
| `backend-test` skip comment `:183-184` + `if:` `:185` ("Skipped on PR→dev — local preflight is the gate") | EXACT |
| `frontend-test` `:918-922` and `frontend-build` `:977-981` carry the same main-only `if:` | CONFIRMED (if: at :922 and :981 respectively — main/workflow_dispatch only) |
| `all-checks-pass` skipped on PR→dev — `if:` `:1103` | EXACT |
| `tests/Feature/Security` whole-dir `:294` inside `backend-test`; celebration comment `:284-293` | EXACT |
| pgsql service comment "for future Feature-suite coverage that opts into it explicitly" `:226-230` | comment tail at :226 — in range |
| Allowlist `:629` = **93** class names; `AnalyticsTest`, `ExpenseAnalyticsTest`, `BackfillChartPurposesMigrationTest` all present | re-counted **93** — MATCH; all three entries confirmed (substring-shadow mechanism claim stands: `--filter` unanchored) |
| Second allowlist `:726` = **16** entries | re-counted **16** — MATCH |
| Directory-inclusion pattern `:844-847` (`tests/Feature/Treasury`, `tests/Feature/Accounting`) | EXACT; both dirs exist |
| deptrac ratchet command in `backend-architecture` `:178` | EXACT |
| Architecture suite in NO automatic lane; sole `tests/Architecture` reference `:316` gated by `workflow_dispatch` `if:` `:303` | CONFIRMED |
| Route-manifest drift check: `scripts/preflight.sh:193-195` local-only; zero workflow references (`check-manifest-drift` grep over workflows → exit 1) | CONFIRMED |

### Frontend citations

| Brief claim | Result |
|---|---|
| `apps/web/package.json:10` lint chain `lint:eslint && audit:keys && audit:design-system && audit:quantity && test:eslint-rules`; `audit:keys` (:13) = `audit-tanstack-keys.mjs` (TanStack, not i18n) | EXACT |
| `gen-route-manifest.mjs:31-34` `WRAPPERS` set; `KeyedByRouteId` absent | EXACT (grep count 0) |
| RuleTester coverage: `.test.mjs` for `no-dead-tailwind-token-interpolation`, `no-hardcoded-step`, `no-literal-decimal-places`; NONE for `no-parsefloat-on-money.js`, `no-untranslated-literal.js`, `no-hardcoded-entity-route.js` | EXACT (ls of `eslint-rules/`) |
| `tools/__tests__/` exists (6 test files incl. design-system/quantity/tanstack) | CONFIRMED |
| `audit-quantity-display.mjs` `partitionViolationsByBaseline` `:342`; three-way split consumed `:446`; stale-entry ratchet messaging `:449-461` | EXACT (4 stale/remove hits in range) |
| No i18n completeness tool anywhere (`ls tools/ | grep -i i18n` → exit 1); `setup.ts:2` imports real `lib/i18n`; `fallbackLng: 'en'` `i18n.ts:440`; `ns` array `:442`; `ar` en-fallback spreads `:~430` (`{...enNotifications, ...arNotifications}` at :429); only key guard `i18nRawKeyCoverage.test.tsx` | ALL CONFIRMED |
| C6: `STATUS_RE` `audit-design-system.mjs:59-63` never matches `Tone`/`Tones` | CONFIRMED (T7 not landed) |

### Harness / process citations

| Brief claim | Result |
|---|---|
| `SELF-REVIEW-HARNESS.md` + `scripts/adversarial-review.sh` exist; flags `--brief/--milestone/--lenses/--range/--out/--round` all present; exit codes 0=ACCEPT · 2=CHANGES-REQUIRED · 3=tool error (`adversarial-review.sh:15`); `max_fix_rounds` default 5 (`SELF-REVIEW-HARNESS.md:47`) | ALL CONFIRMED |
| `docs/handoff/progress/` ledger dir with in-flight lane files | CONFIRMED (7 progress YAMLs) |
| UI Wave 0 brief T2 (:197), T3 (:226, "+ a standalone manifest drift CI job"), T7 (:350) | ALL EXACT |
| Commits `bc67530a6`, `77d07de3c` exist locally | `git cat-file -t` → commit, both |
| Base: tip near `a5520f23c` | tip IS `a5520f23c`; no uncommitted changes on any cited surface | EXACT |

### Check 3 — test-contract executability (text unchanged from r1; re-checked)

- P1 block: `cd apps/api && ./vendor/bin/phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php` — exact form, path is the deliverable; tamper/ratchet proofs fixture-based; `git diff --stat` guard-only proof — runnable.
- P2 block: `pnpm lint`, `node tools/audit-i18n-completeness.mjs` (deliverable), `node --test tools/__tests__/` (dir exists), `pnpm test:eslint-rules` — runnable.
- P3 block: by-path PHPUnit with placeholders for to-be-authored tests (O-3); `./vendor/bin/phpstan` with the live-DB caveat disclosed in §5 — runnable.
- No contract asserts through a mock of the thing under test; no impossible interleavings; no lazy-row barriers.

### Check 5 — permission/module keys

`grep -oE "permission:[a-z._-]+|module:[A-Za-z]+"` over the brief → empty (exit 1). Nothing to verify against `RolesAndPermissionsSeeder.php` / route middleware / `MODULE_PERMISSIONS`. PASS (n/a).

### Hygiene

- Scripted GFM check (code fences excluded, backtick spans masked): **4 tables, 0 inconsistent** pipe counts; **0** literal `|` inside code spans within table cells.
- Banner `r2 — 2026-08-12, round-0 fixes applied, NOT yet brief-gated` matches revision-log tail (r2). UNVERIFIED items remain honestly labeled (F-5). PASS.

---

**Disposition:** PASS — attach this report to the adversarial brief-gate dispatch. Per the spec, a round-0 pass on r2 does not carry to any r3: if the brief is revised again, re-run round 0 in full.

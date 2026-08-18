# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r5 / dev `a5520f23c`
Runner: Claude (Fable 5), mechanical precheck session (full re-run from the top after the R0-4 fix) · Date: 2026-08-12

Verification tree: `/Users/houssamr/Projects/syneriva/apps/erp`, branch `dev`, `HEAD = a5520f23ca39209f5b517723037e9516808f2bca` — **identical to the r1–r4 round-0 bases and to the brief's stated verification base** ("tip near `a5520f23c`"). **Zero drift.** `git status` confirms `.github/workflows/ci.yml`, `apps/api/**`, `apps/web/**`, and `scripts/**` (except the unrelated new `scripts/dev-scan-stack.sh`) carry no working-tree modifications — no citation's ground moved between rounds. Per the standing methodology rules, **every citation and count below was re-derived from the tree this run** (the r4 report was read only to establish what the r5 diff must contain — none of its numbers were reused), and **conjunctions were checked as links, not just endpoints**.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 1 | Revision-log truth            | PASS   | 0 — r5 entry verified against the actual text delta (both loci corrected, old values gone, banner bumped); all 5 r4 claims still hold in the r5 text; all 17 r3 (gate-r1) claims and both r2 claims re-verified present |
| 2 | Exhaustive claims proven      | PASS   | 0 — every census re-ran exact (93/16 allowlists, 16/4/0 architecture, 3-of-6 rule tests, 6 tools tests, runs-nowhere greps exit 1, 27/1/4/9 partition verbatim at source, 17 = 4C+11H+2M) |
| 3 | Test contracts executable     | PASS   | 0 FAIL — all acceptance blocks runnable as written; O-3 placeholder observations carried; no mock-of-subject anywhere |
| 4 | Behavior claims cited         | PASS   | 0 — the three R0-4 citations now EXACT (`:876`/`:879`/`:882` re-derived independently); ~75 further citations re-checked, all exact |
| 5 | Permission keys verified      | PASS   | 0 (brief names no permission/module keys — `grep -oE 'permission:[a-z._-]+|module:[A-Za-z]+'` → empty, exit 1) |
| H | Hygiene (pipes, banner)       | PASS   | 0 (4 tables @ lines 38/73/98/121, all pipe-consistent after masking backtick spans; banner r5 = revision-log tail r5) |

**VERDICT: PASS — all six rows clean. The brief at r5 is round-0 clean and may proceed to gate round 2.**

---

## Findings

None failing.

## Observations (non-failing)

- **[O-1] (carried, unchanged) Check 2 — counts sourced outside the repo tree:** "10 violations + 3 GL-gap grays", "~25–30 dev-days", "25+ live C6 violations", "the finding that motivated this task said ~49". Not re-runnable here; the brief self-flags the register-path gap (F-5) and makes the register cross-check P1 deliverable 4. Same disposition as r2–r4.
- **[O-3] (carried) Check 3 — placeholder identifiers for to-be-authored artifacts:** P3's `--filter '^…UnbalancedGuard…$'` ×3, `<path-to-country-chart-completeness-test>`, P1's `<architecture-job-id>` grep, `<base_sha>` pins. Form exact, runnable once instantiated; `tests/Feature/{Treasury,BatchExpiry,Accounting}` all exist in the tree.
- **[O-4] (carried) `docs/handoff/reviews/` does not exist** — harmless: `scripts/adversarial-review.sh:47` does `mkdir -p "$(dirname "$OUT")"`.
- **[O-5] (carried, re-confirmed at `a5520f23c`) Executor conditionals' current state:** `scripts/factory/gen-route-manifest.mjs:31-34` `WRAPPERS` still lacks `KeyedByRouteId`; `check-manifest-drift` in no workflow; `audit-design-system.mjs:59-63` `STATUS_RE` alternations still lack `Tone`/`Tones`; `ProvisioningRequiredPurposesV1` absent from HEAD (correct — P3-M0 precondition owned by the country-defaults lane, whose prescribed file map at `CODEX-DISPATCH-country-defaults-phase-a-2026-08-10.md:128` matches the P3-M0 `git cat-file -e` path exactly).
- **[O-7] (carried) milestone-ID spelling:** YAMLs use bare `M0…Mn`, brief/bridge template uses `<package>-M<n>` — deliberate per the template's `--milestone` instruction, no mechanical break.
- **[O-9] (new, cosmetic) YAML header comments say "r4 of the brief":** all three `enforcement-p*.progress.yaml` line-5 comments read "(r4 of the brief applies all 17 gate-r1 findings + the round-0 R0-3 fix; re-gate before dispatch)". Factually still true (r4 DID apply them; r5 changed only three citation numbers in the brief text and touched no YAML), so not a revision-log falsehood — but a reader could take "r4" as the current brief revision. Optional one-word freshen at the next YAML touch; NOT verdict-relevant.
- **[O-10] (new, note-level) `:844-847` region cite in 2(b) Option B** ("the pattern `:844-847` already uses for two directories"): the two directory steps sit at `:844-845` (Treasury name+run) and `:847-848` (Accounting name+run) — the range's start and substance are correct and both steps are visibly identified inside it, but the Accounting run-line itself (`:848`, `run: ./vendor/bin/phpunit tests/Feature/Accounting`) falls one line outside. `:844-848` would be the exact region. Distinct from R0-4 (whose citations pointed at blank lines containing nothing): here the cited region does contain the claimed pattern and both step names. Recorded for the next natural edit; NOT verdict-controlling.

---

## Check 1 — revision-log truth

### r5 entry (brief line 27) — verified against the actual text delta

The brief file is untracked (no git history), so the r4→r5 delta is established by (a) the r4 round-0 report's precise record of the r4 text and its fix-direction, and (b) direct grep of the r5 text:

| r5 claim | Mechanical verification | Verified |
|---|---|---|
| Three frontend-lint step citations corrected `:877`/`:880`/`:883` → `:876`/`:879`/`:882` in both loci | `grep -n ':876\|:879\|:882'` → brief lines **25** (r4 log entry) and **186** (§3 census row) both now carry `:876`/`:879`/`:882`; `grep -n '877\|880\|883'` → the ONLY hits are line 27 (the r5 log entry honestly recording the old wrong values) and line 220's `:875-883` range cite (intended to remain). Zero residual wrong point-citations. | **YES** |
| The re-derived numbers are the true run-lines | Re-derived independently this run: `grep -n` on ci.yml → `run: pnpm audit:keys` **`:876`**, `run: pnpm audit:design-system` **`:879`**, `run: pnpm audit:quantity` **`:882`** (cross-checked with a `sed 850,895` window count). | **YES** |
| "the cited lines were the blank separators after each step" | `:877`, `:880`, `:883` are each blank lines in the ci.yml step list. | **YES** |
| "numbers had been copied from the r3 round-0 report instead of re-derived" | Matches the r4 report's lineage note (`…-round0-r4.md:26`); honest provenance. | **YES** |
| Banner bumped | Brief line 4: "Revision: r5 — 2026-08-12, round-0 finding R0-4 fixed … NOT yet re-gated" = revision-log tail (line 27). | **YES** |
| Delta is citation-only | The census row moved from r4's line 184 to r5's line 186 — exactly the +2-line shift the inserted r5 log entry produces; every other passage the r4 report quoted verbatim is byte-identical at its shifted position (spot-checked lines 25, 41, 186, 220, 231, 257, 332). No smuggled scope change. | **YES** |

### r4 entry (brief line 25) — all 5 claims still hold in the r5 text

| r4 claim | Where in r5 text | Verified this run |
|---|---|---|
| (1) census row corrected to true step list + supersession comment; `test:eslint-rules`/`tools/__tests__` CI-absence added to runs-nowhere census | line 186 ("does NOT run `pnpm lint` … discrete steps … `:885` … LOCAL-ONLY") + line 188 (runs-nowhere: "`pnpm test:eslint-rules` … and `node --test tools/__tests__/`: zero workflow references … exit 1 … closing this is 2(d) deliverable 4") | **YES** — and the census content re-verified true in the tree (below) |
| (2) 2(c) deliverable 3 = explicit discrete step in `frontend-lint`, never the `lint` chain; acceptance greps; H-9 `:1104` verify-at-base | line 220 + acceptance block lines 242-246 (both greps + `:1104` re-verify) | **YES** |
| (3) 2(d) named deliverable 4 — discrete step/job for RuleTester + tools tests, planted-failing-rule-test tamper proof | line 231, verbatim mechanism intact | **YES** |
| (4) H-3 mechanism updated in all five locations | sequencing table P2 row (line 41: "the job runs discrete steps, never `pnpm lint`, so the wiring is itself a workflow edit"); 2(c) (line 220); P2 milestone list (line 257); F-6 (line 332); `enforcement-p2.progress.yaml` header comment (line 5) + M1 title (line 62, carrying `:885`, `:1104`, `i18n.ts:392-405,416-424`, `:429-430` — each re-verified exact in the tree this run) | **YES** — all five |
| YAML line-5 comments refreshed in all three files | `enforcement-p{1,2,3}.progress.yaml` line 5 each, identical text (see O-9) | **YES** |

**Conjunction re-verification (standing rule (a)):** the causal links were re-checked in the tree, not just endpoints — (i) `frontend-lint` runs discrete steps and NOT `pnpm lint`: TRUE (job body `:853-891` = install + four run-steps `:876/:879/:882/:890` only; `:885` comment verbatim "Supersedes a raw `pnpm lint`: …"); (ii) the `package.json:10` chain (`lint:eslint && audit:keys && audit:design-system && audit:quantity && test:eslint-rules`) verbatim and CI-unreferenced; (iii) `frontend-lint` ∈ `all-checks-pass` `needs` at `:1104`; (iv) a step added inside `frontend-lint` inherits that membership (needs-entry is the job, not its steps) — the 2(c)/2(d) wiring claims hold as conjunctions.

### r3 entry — all 17 gate-r1 claims re-verified present in the r5 text (spot-verified per task, in fact all 17 walked)

C-1 (three YAMLs exist/parse/shape — §Companion artifacts below), C-2 (§4 3(a) rescope, "A green-at-base test disqualifies the target" verbatim at line 273), C-3 (§4 3(b) CONSUMER rewrite line 278-288 + DO-NOT-TOUCH frozen-seeder row line 104 + P3-M0 machine check in YAML), C-4 (§2 deliverable 3(c) anti-growth line 137 + i18n inheritance line 218), H-1 (never-foldable + `dpa_3c_merge_sha` ancestry/S0 encoding, line 40 + P1 YAML), H-2 (2(a) four-branch ownership tree lines 193-197 + OpenAPI reconciliation line 198), H-3 (line 257, mechanism corrected), H-4 (write-method vocabulary line 132 + mechanism×table fixture matrix lines 140-147), H-5 (authored-locale provenance line 218 + alias/spread fixtures line 221), H-6 (class-to-lane manifest + planted-class proof line 211), H-7 (no-push at lines 67, 208, 249, 321; LEDGER S-14), H-8 (whole-package gates P1-M3/P2-M4/P3-M3 at lines 178/257/308 + lens→agent-contract wiring line 63), H-9 (aggregate membership lines 139, 220, 231, 245-246), H-10 (S-15 pointer-only at lines 19, 77, 276, 333, 342), H-11 (three per-path phpunit lines 299-301), M-1 ("other static tests/scans" + `OrphanedTypesCleanupTest` gloss line 132), M-2 (`git diff --stat <base_sha>..HEAD` + path allowlist lines 168-172). The r2 entry's two claims (ParserFactory narrowed to the 4 actual users; verbatim cementing-guard quote) intact and re-verified against source (census + quote below).

---

## Evidence appendix — full re-run at `a5520f23c`

### CI workflow (`.github/workflows/ci.yml`)

| Brief claim | Re-derived result |
|---|---|
| **R0-4 fix targets:** `run: pnpm audit:keys` `:876` · `run: pnpm audit:design-system` `:879` · `run: pnpm audit:quantity` `:882` | **ALL EXACT** (`grep -n` + `sed` window agree; `:877/:880/:883` are blanks) |
| `frontend-lint` job `:853-891`; `run: pnpm lint:ratchet` `:890`; supersession comment `:885` verbatim; next job `frontend-typecheck:` `:893`; step-pattern region `:875-883` | ALL EXACT — `:875-883` remains valid as a region cite (contains all three name+run step pairs at `:875-882`; `:883` a trailing blank) |
| Job anchors `backend-lint :27` / `backend-analyse :105` / `backend-architecture :143` / `backend-test :180` / `frontend-test :918` / `frontend-build :977` / `types-drift :1012` / `all-checks-pass :1090` | ALL EXACT |
| Skip `if:`s: backend-test `:185` (comment `:183-184` "Heavy job… Skipped on PR→dev"), frontend-test `:922`, frontend-build `:981`, aggregate `:1103`; `needs` `:1104` incl. `frontend-lint` | ALL EXACT |
| Only `--testsuite` invocation = Unit `:275`; `tests/Feature/Security` `:294` (comment block `:284-293`); full suite incl. `tests/Architecture` only under `workflow_dispatch` (`:303` gate, `:316` path) | ALL EXACT |
| Allowlist `:629` = **93** entries (awk NR + tr-split count), `AnalyticsTest` and `ExpenseAnalyticsTest` both present (substring-shadow mechanism stands); second allowlist `:726` = **16** | MATCH |
| pgsql-provision comment `:226-230` ("for future Feature-suite coverage that opts into it explicitly"); Treasury/Accounting directory steps region `:844-847`/`:833-851` | EXACT (see O-10 for the `:848` edge) |
| `test:eslint-rules` / `tools/__tests__` in NO workflow (`grep -rn … .github/workflows/` → exit 1); `check-manifest-drift`/`gen-route-manifest` in NO workflow; `scripts/preflight.sh:193-195` invokes the drift check locally | ALL CONFIRMED |

### Backend code

| Brief claim | Re-derived result |
|---|---|
| S0 seam: `recordMovement` `:1700`, `?StockMovementReferenceType $referenceType`/`?string $referenceId` `:1715-1716`, `assertReferenceLinkagePaired` `:1719` (`StockAdjustmentService.php`) | ALL EXACT |
| `StockLevel::firstOrCreate` `:1574-1590` (call `:1578`, zero-valued defaults); `BatchStock::firstOrCreate` `StockAdjustmentService.php:1838-1863` (call `:1847`), `BatchStockService.php:88-105` (`:94`), `:322-335` (`:325`) | ALL EXACT |
| `sealAndPersistEntry` `:3480`, bcadd sums + `bccomp !== 0` → `InvalidArgumentException` "Cannot post unbalanced journal entry" inside `:3480-3510`; `postEntry` `:2874` → `postEntryWithOptionalActor` `:2879`; `postEntryNow` `:3450`; `createPOSChargeEntry` `:4072` | ALL EXACT |
| `TreasuryReceiptBridge` (Application/Projections) `postEntryNow` `:473,547,1387`; `$requiredPurposes = [` `:446`,`:528`; `InstrumentLifecycleService` `postEntryNow` `:274,456,488,847` | ALL EXACT |
| `journal_entries(source_type,source_id)` not globally unique | CONFIRMED in-tree: only partial per-source_type unique indexes exist; `2026_06_26_120000_unique_journal_entries_source_procurement.php:17` states verbatim "A global UNIQUE on (source_type, source_id) is NOT safe" |
| Writers/models/enum exist at stated paths: `JournalEntry.php`, `StockMovement.php`, `StockLevel.php`, `BatchStock.php` (BatchExpiry/Domain/Entities), `StockMovementReferenceType.php` (Shared/Domain/Enums), `AccountingService`, `TreasuryAccountChargeBridge`, `RepositoryAdjustmentService`, `ReverseWriteOffService`, `BatchWriteOffService`, `GroupedWriteOffService`, `FEFOInventoryService`, `BackfillChartPurposesCommand` | ALL CONFIRMED |
| `phpunit.xml:17-18` Architecture suite; census: **16** `tests/Architecture/*.php`, **4** ParserFactory users (exactly `AuthLifecycleTest`, `BroadcastChannelTenantContextTest`, `TenantScopedExistsRulesTest`, `TenantScopedFindCallsTest`), **0** `RefreshDatabase`; `OrphanedTypesCleanupTest.php:15-29` pure filesystem non-existence assertion (`assertFileDoesNotExist` `:29`) | ALL EXACT (re-counted) |
| `SystemAccountPurpose::expectedAccountType()` `:~186` (actual `:187`, tilde-qualified — OK); `ChartOfAccountsService::validateCompanyAccounts()` iterates `requiredPurposes()` at `:51` | EXACT |

### Frontend

| Brief claim | Re-derived result |
|---|---|
| `apps/web/package.json:10` lint chain verbatim; `:12` `test:eslint-rules` (three named rule tests); `audit:keys` = `audit-tanstack-keys.mjs` (TanStack, not i18n) | ALL EXACT |
| No i18n completeness tool; `setup.ts:2` imports real `../lib/i18n`; `ar` English-aliases `:392-405` and `:416-424` (incl. the in-file "fall back to English for the whole namespace" comment); spread-merges `:429-430` (`notifications`/`locations`); init block `:435-442`, `fallbackLng: 'en'` `:440`, `ns` array `:442`; `i18nRawKeyCoverage.test.tsx` exists | ALL EXACT |
| `audit-quantity-display.mjs`: `partitionViolationsByBaseline` `:342-358`; `--write-baseline` replace-and-exit-0 writer inside `:418-442` (exit(0) at `:442`); stale-entry messaging `:449-461` | ALL EXACT |
| RuleTester census: `.test.mjs` for exactly `no-dead-tailwind-token-interpolation`/`no-hardcoded-step`/`no-literal-decimal-places`; none for `no-parsefloat-on-money.js`/`no-untranslated-literal.js`/`no-hardcoded-entity-route.js`; `tools/__tests__/` = 6 files (extend-don't-duplicate instruction consistent) | EXACT |
| `gen-route-manifest.mjs:31-34` `WRAPPERS` lacks `KeyedByRouteId`; `audit-design-system.mjs:59-63` `STATUS_RE` alternations lack `Tone`/`Tones` | CONFIRMED |

### Harness / process / cross-lane

| Brief claim | Re-derived result |
|---|---|
| `SELF-REVIEW-HARNESS.md:49-50` populated milestone `owner_gate` = STOP (condition B); `:66-75` whole-branch final-milestone obligation (verbatim at `:74-75`) | ALL EXACT |
| `adversarial-review.sh`: prompt heredoc from `:49`, lenses passed as prose `${LENSES:-general}` within `:49-64`, no `.claude/agents` reference anywhere in the script; exit **0** ACCEPT `:98` / **2** CHANGES-REQUIRED `:99` / **3** fail-closed `:100`; `mkdir -p` `:47` | ALL EXACT |
| All 6 lens agent contract files exist in `.claude/agents/` | CONFIRMED |
| `CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:612-617` — 3C gated + merged to local dev before 3D exists | EXACT |
| `CODEX-DISPATCH-ui-wave0-2026-08-11.md`: T2 `WRAPPERS` gap `:204`, live drift evidence `/sales/invoices/:id` → `KeyedByRouteId` `:206`, T3(b) job + aggregate-needs contract `:240-247`, T7 C6 `Tone`/`Tones` regex fix `:350`,`:365`; `ui-wave0.progress.yaml` M4 owns T3(a)+T3(b) (`:64-65`) | ALL EXACT |
| `HANDOVER-openapi-lane-orchestration-2026-08-07.md:32-36` lane live/running; `2026-08-06-codex-dispatch-openapi-mcp-layer-a-to-z.md:9-16,21-26` owns spec CI-drift-guard + coverage-harness wiring | EXACT |
| Country-defaults dispatch: D-6 row inside `:57-66` ("requiredPurposes() is not the operational manifest… conformance must not key off it"); M1 `:289-318` ships `ProvisioningRequiredPurposesV1`; 41-case **27/1/4/9** partition verbatim `:301-302`; frozen-seeder markers `:314-318`; prescribed manifest path `:128` = `apps/api/app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php` (matches P3-M0's `git cat-file -e` path exactly); `country-defaults-phase-a.progress.yaml:33-40` M1 owns manifest+conformance+ratchet | ALL EXACT |
| Sweep-audit cementing-guard quote `:136-139` verbatim; `PLAN-p0…:53` = W-6 D1a/D1b incl. "campaign test money … not a code fix"; `AGENTS.md:16` `Phase <major.minor.patch>` commit format | ALL EXACT |
| `docs/handoff/LEDGER.md` §2: **S-14** `:56` (owner-executed post-promotion remote-CI verification, armed per package) and **S-15** `:57` (−19.000 demo-tenant disposition, "points here and carries no status of its own (gate-r1 H-10)") — pointers resolve both directions | ALL EXACT |
| Progress ledger dir: `docs/handoff/progress/` carries ui-wave0, country-defaults-phase-a, dn-consolidation-build, receipts-build (+ enforcement-p1/p2/p3) — supports the "all in flight" framing | CONFIRMED |

### Companion artifacts — the three progress YAMLs

| Artifact | Parses (js-yaml) | Harness shape | Milestone `owner_gate:` | M0 commands runnable |
|---|---|---|---|---|
| `enforcement-p1.progress.yaml` | YES | top-level `base_sha`/`branch`/`commit_series`/`owner_gates`(4)/`max_fix_rounds: 5`/`milestones` M0–M3 + pin `dpa_3c_merge_sha` (all pins null = awaiting parent, correct) | **NONE** | YES — `git rev-parse --verify`, `git merge-base --is-ancestor`, `git show <sha>:apps/api/…/StockAdjustmentService.php \| grep -n 'assertReferenceLinkagePaired'` — all standard git syntax; the embedded path exists in the tree |
| `enforcement-p2.progress.yaml` | YES | same + pin `quiet_window_ack`, `owner_gates`(5), M0–M4 | **NONE** | YES — null-tests + ownership-snapshot greps (ci.yml `check-manifest-drift` grep runs, 0 hits today as expected) |
| `enforcement-p3.progress.yaml` | YES | same + pin `p1_landed_sha`, `owner_gates`(3), M0–M3 | **NONE** | YES — `git cat-file -e <base_sha>:apps/api/app/Modules/CountryDefaults/Domain/Services/ProvisioningRequiredPurposesV1.php` (exits non-zero at HEAD today — the precondition is honestly unmet; path matches the owning lane's prescribed file map `:128`) |

Milestone counts match the brief's operative lists (P1 M0–M3, P2 M0–M4, P3 M0–M3); P2 YAML M1 title carries the corrected r4/R0-3 mechanism with `:885`/`:1104`/`i18n.ts:392-405,416-424`/`:429-430` — each re-verified exact this run. Lens sets match §⚙️ exactly.

### Check 3 — test-contract executability

- **P1 block:** phpunit by path (never full suite), 5 numbered tamper/ratchet proofs incl. the C-4 anti-growth base-branch diff, non-vacuous `git diff --stat <base_sha>..HEAD` + path allowlist, aggregate grep with placeholder job id (O-3). Runnable; no impossible interleavings; fixtures are real files scanned by the real guard — no mock-of-subject.
- **P2 block:** all local commands runnable; the two CI-wiring greps resolve path-correctly from `apps/web` (`../../.github/workflows/ci.yml` = repo root) and exit 1 today — correct, they prove the deliverable once authored.
- **P3 block:** three by-path phpunit invocations with placeholder filters (O-3) + completeness-test placeholder + phpstan (live-DB caveat disclosed in §5). Red-first contracts target class-(c) paths only; the C-2 green-at-base disqualifier prevents vacuous contracts.

### Check 5 / Hygiene

- Permission/module-key grep over the brief → empty (exit 1). PASS (n/a).
- Scripted GFM pipe check (fences excluded, backtick spans masked): **4 tables (lines 38/73/98/121), 0 inconsistent rows.**
- Banner (line 4, r5, "NOT yet re-gated") = revision-log tail (line 27). UNVERIFIED items (F-5) still honestly labeled.

---

**Disposition: PASS.** The R0-4 fix is verified correct and complete from the tree (not from any report); no regression anywhere in the full re-run; both standing methodology rules were applied (conjunctions checked as links; every number re-derived). Per the SPEC-GATE-ROUND0 hard rule, this all-PASS report accompanies the gate dispatch — **the brief at r5 is cleared for gate round 2.** HEAD `a5520f23c` = the brief's stated verification base; if `dev` moves before the gate runs, the reviewer re-verifies citations at its own base per the brief's standing instruction (line 30).

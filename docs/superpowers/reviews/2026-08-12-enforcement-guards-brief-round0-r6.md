# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r6 (tree a5520f23c)
Runner: Fable 5 (round-0 mechanical precheck subagent) · Date: 2026-08-12

Scope: the brief at r6 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15. Every line number below **re-derived from the tree** (HEAD `a5520f23ca39209f5b517723037e9516808f2bca` = exactly the brief's stated verification base `a5520f23c` — zero drift). Nothing was taken from any prior report.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 1 | Revision-log truth (r6: 11 items; r3/r4/r5 spot-checks) | **PASS** | 0 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable     | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 — no permission/module keys named |
| H | Hygiene (pipes, banner, stale refs) | **PASS** | 0 |

**VERDICT: PASS — all rows.** Three note-only observations at the end (none is a defect).

---

## Check 1 — Revision-log truth

### r6 log: all 11 finding-ID → change claims verified against document/YAML/LEDGER text

| Claim | Where verified | OK |
|---|---|---|
| **R2-C-1** two-phase pinned-baseline protocol, BOTH ratchets | Brief §2 deliverable 3(c) (Phase 1 seed / Phase 2 blob-pinned key-set compare / parent re-pin / event-anchoring / tamper case 5 retained) + 2(c) deliverable 1 same form. Pins present, exact names + described semantics: `enforcement-p1.progress.yaml:44-45` `dpa_baseline_seed_commit`/`dpa_baseline_protected_blob` (executor-writes-at-M2, parent re-pins, `git cat-file blob`, never a branch name, unfetchable = fail closed); `enforcement-p2.progress.yaml:32-33` `i18n_baseline_seed_commit`/`i18n_baseline_protected_blob` (same). No `origin/<base>`/branch-ref anywhere in the operative mechanism (grep: branch-name mentions only in historical log entries / supersession notes). | ✓ |
| **R2-C-2** true event graph + pre-promotion dispatch gate | `ci.yml:3-8` re-derived: `on:` = `push.branches [main]` (:4-5), `pull_request.branches [main, dev]` (:6-7), `workflow_dispatch:` (:8) — nothing else. `ci.yml:740-747` re-derived: comment block stating outright "`on.push.branches` (top of file) is `[main]` only, so a push event on `dev` never actually triggers this workflow at all" (:744-745, within the cited 740-747 comment). Declined-option text in §5. `pre_promotion_ci_dispatch` present in **all three** YAMLs (p1:51, p2:39, p3:46) with owner-executed/pre-merge/green+head-SHA-equality+jobs-executed semantics. Event-graph acceptance checks present in P1 acceptance block + P1-M3 and P2 acceptance block + P2-M4. | ✓ |
| **R2-H-1** announcement SPLIT | `enforcement-p2.progress.yaml:19` `quiet_window_ack` (window + PRELIMINARY notice, null at M0 = blocked_precondition) + `:23` `merge_announcement_ack` (post-M3/M4, pre-promotion, null = promotion blocked); M0 title explicitly forbids claiming the M3 checklist was sent; F-6 rewritten as two parent-owned artifacts. | ✓ |
| **R2-H-2** S-14 + §5/§7 rewrite | `docs/handoff/LEDGER.md:56` row S-14 re-read: PRE-promotion `workflow_dispatch` on owner-pushed throwaway ref, head == accepted candidate SHA, green, new jobs executed, recorded in `pre_promotion_ci_dispatch`; local actionlint/YAML-parse "explicitly INSUFFICIENT for the Actions job/event graph"; no green run = PROMOTION BLOCKED; post-promotion defect blocks the NEXT promotion. Brief §5 item 4 + §7 item 5 match. | ✓ |
| **R2-H-3** tools tests stay on Vitest | Re-derived: all 6 `apps/web/tools/__tests__/*.mjs` import from `vitest` (`grep -L vitest` → empty). No `test:tools` script exists today (`package.json` grep exit 1 — correctly a deliverable, not a claim of presence). The exact string `"test:tools": "vitest run tools/__tests__"` appears at brief :34, :256, :266-comment and p2 YAML M1; `pnpm test:tools` in P2-M1 wiring, M4, and acceptance block. Every `node --test` occurrence in brief+YAMLs is inside the removal ruling itself — zero operative `node --test`. | ✓ |
| **R2-H-4** P1-M0 accepted-3C proof | Sequencing-table P1 row (i)-(iv); `enforcement-p1.progress.yaml` header :15-28 + M0 title carry the M3-status/verdict/commit-equality-or-two-hop-ancestry logic; new pin `dpa_3c_reviewed_sha` at :33. `wave3-3c-3d.progress.yaml:49-56` re-derived = the M3 block, title "3C tail: …" — the citation and "3C tail milestone" gloss are exact. | ✓ |
| **R2-H-5** country-defaults whole-lane proof | Pin `country_defaults_landed_sha` at `enforcement-p3.progress.yaml:38`; §4 3(b) + P3 M0 carry manifest-exists + pin + top-level `status: complete` + M7 ACCEPT + two-hop ancestry + "M1 alone INSUFFICIENT". `country-defaults-phase-a.progress.yaml:81-88` re-derived = M7 "Whole-branch integration gate" block; `:33-40` = M1 block (manifest + conformance suite + AST ratchet). | ✓ |
| **R2-H-6** `p2_m2_landed_sha` | Pin at `enforcement-p3.progress.yaml:39`; hard precondition in P3-M2 title + owner_gate `p2-m2-landed-before-m2`; reciprocal note in brief 2(b) last bullet and p2 YAML M2 title ("never silently drop the landed P3 test"). | ✓ |
| **R2-H-7** census-derived acceptance | Brief §4 acceptance block: per-class-(c)-row named path + anchored filter + NONZERO selected-test count parsed from the PHPUnit summary; class-(b) no-new-test; zero-row modules census-only. Supporting claim re-verified: `apps/api/phpunit.xml` contains **no** `failOnEmptyTestSuite` (grep exit 1). P3 YAML M1 title carries the same rule; supersession of gate-r1 H-11 mechanism stated in both places. | ✓ |
| **R2-M-1** YAML line-5 comments | Line 5 of all three YAMLs: "(r6 of the brief applies all 17 gate-r1 findings, round-0 R0-3/R0-4, and the 11 gate-r2 findings; re-gate before dispatch)" — references r6; counts correct (gate-r1 = 17 = 4C+11H+2M; gate-r2 = 11 = 2C+7H+2M). | ✓ |
| **R2-M-2** Option B citation `:844-848` | Re-derived from `ci.yml`: :844 step name "PG-only invariants — Treasury Feature suite", :845 `run: ./vendor/bin/phpunit tests/Feature/Treasury`, :847 step name Accounting, **:848 `run: ./vendor/bin/phpunit tests/Feature/Accounting`**. The parenthetical "the Accounting run line is `:848`; `:847` is only its step name" is exactly right; the range covers the two directory-inclusion steps. | ✓ |

### r3/r4/r5 spot-checks (still true in r6 text)

- **r3 C-1**: exactly three files `enforcement-p{1,2,3}.progress.yaml` exist; no nested single file (`ls docs/handoff/progress | grep -i enforce` → 3); harness shape verified by parse (below); "P3-after-P1" and "P1-after-3C" are machine-checkable M0 items in the YAMLs, not prose. ✓
- **r3 C-2**: chokepoint claim re-verified in code — `GeneralLedgerService::sealAndPersistEntry` at :3480 loads lines, `bcadd` sums, `bccomp` throw (all inside cited :3480-3510); `postEntry` :2874 (via `postEntryWithOptionalActor` :2879), `postEntryNow` :3450, `createPOSChargeEntry` :4072 — all exact. ✓
- **r3 H-4**: live mechanisms verified — `StockLevel::firstOrCreate` at `StockAdjustmentService.php:1578` (∈ cited 1574-1590, zero-valued defaults `'0.00'` present); `BatchStock::firstOrCreate` at `StockAdjustmentService.php:1847` (∈ 1838-1863) and `BatchStockService.php:94`/`:325` (∈ cited 88-105 / 322-335). ✓
- **r3 H-8**: `scripts/adversarial-review.sh:49-64` re-read — the heredoc passes `${LENSES}` as prose into the prompt, loads no agent file; all six named `.claude/agents/*-reviewer.md` contracts exist (they are live agent types). ✓
- **r3 M-1**: gloss re-derived — `tests/Architecture` has **16** `*Test.php`; ParserFactory users are exactly the 4 named (`AuthLifecycleTest`, `BroadcastChannelTenantContextTest`, `TenantScopedExistsRulesTest`, `TenantScopedFindCallsTest`); **zero** `RefreshDatabase`; `OrphanedTypesCleanupTest.php:15-29` is precisely the provider + `assertFileDoesNotExist` block. ✓
- **r3 M-2 / H-9 / H-10**: `git diff --stat <base_sha>..HEAD` + allowlist in P1 acceptance and both YAML final milestones; `all-checks-pass` at `ci.yml:1090-1107` (job block; `needs:` at :1104 **containing `frontend-lint`**; `if:` at :1103 = dispatch/PR→main/push→main only, hence "aggregate skipped on PR→dev" and ":1090-1103" both correct); S-15 row present in LEDGER with pointer-only semantics. ✓
- **r4 (R0-3)**: census row re-verified — `frontend-lint` (:853-891) runs discrete steps, no `pnpm lint` anywhere in the job; `apps/web/package.json:10` `lint` chain matches the brief's quoted contents verbatim; `audit:keys` = `node tools/audit-tanstack-keys.mjs` (TanStack, not i18n) ✓; runs-nowhere census re-run: `grep -rn 'test:eslint-rules\|tools/__tests__' .github/workflows/` → **exit 1** ✓; 2(c) deliverable 3 is discrete-step wiring with the chain explicitly demoted to courtesy; 2(d) deliverable 4 named. ✓
- **r5 (R0-4)**: run-lines re-derived by grep, not counted: `run: pnpm audit:keys` **:876**, `run: pnpm audit:design-system` **:879**, `run: pnpm audit:quantity` **:882**, supersedes-comment **:885**, `run: pnpm lint:ratchet` **:890**. The old `:877/:880/:883` appear only inside the r5 log entry describing the fix. ✓

## Check 2 — Exhaustive claims / censuses (all re-run)

| Inventory claim | Census re-run | Documented | Re-derived | Match |
|---|---|---|---|---|
| pgsql allowlist "93 class names" (`ci.yml:629`) | split `--filter` on `\|`, count | 93 | **93** | ✓ |
| second allowlist "16-entry" (`:726`) | same | 16 | **16** | ✓ |
| `AnalyticsTest` substring-shadowing ("both happen to be listed") | `tr '\|' '\n' \| grep -c AnalyticsTest` on :629 | 2 (AnalyticsTest + ExpenseAnalyticsTest) | **2** | ✓ |
| Architecture tests "4 of 16" ParserFactory | `ls`, `grep -l ParserFactory` | 4 / 16 | **4 / 16** (same 4 names) | ✓ |
| "none use RefreshDatabase" | `grep -l RefreshDatabase tests/Architecture/*.php` | 0 | **0** (exit 1) | ✓ |
| DPA audit "10 violations + 3 GL-gap grays" | headings in the sweep audit | 10 + 3 | **V1–V10 = 10; G1–G3 = 3** | ✓ |
| 41-case partition (27+1+4+9) | `grep -c '^    case '` on `SystemAccountPurpose.php`; arithmetic | 41 | **41**; 27+1+4+9=41 | ✓ |
| tools tests "every current file imports from vitest" | `grep -L vitest tools/__tests__/*.mjs` | all 6 | **6/6** (grep -L empty) | ✓ |
| eslint-rules: tests for 3, none for 3 named | `ls apps/web/eslint-rules/` | 3 `.test.mjs` / 3 untested | **exactly as named** | ✓ |
| `test:eslint-rules`/`tools/__tests__` in NO workflow | grep `.github/workflows/` | exit 1 | **exit 1** | ✓ |
| manifest drift check zero workflow refs; local at `preflight.sh:193-195` | greps | 0 refs; :193-195 | **0 refs (exit 1)**; :193-195 exact | ✓ |
| C6 `STATUS_RE` never matches `Tone`/`Tones` | read `audit-design-system.mjs:59-62` | true at base | patterns = `Colors?\|Classes?\|Maps?\|Styles?\|Config\|Badge` — **no Tone** | ✓ |
| `KeyedByRouteId` missing from `WRAPPERS` `gen-route-manifest.mjs:31-34` | read + grep (file at `scripts/factory/`) | missing | **absent** (grep exit 1); WRAPPERS block = :31-34 | ✓ |
| Architecture suite in no automatic lane; only `--testsuite` invocation = Unit `:275` | grep `testsuite` in ci.yml | :275 only | **:275** (`php artisan test --testsuite=Unit`; :287/:298 are comments) | ✓ |

## Check 3 — Test contracts executable

- P1 acceptance: `./vendor/bin/phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php` — exact runnable command for a deliverable file; 5 tamper commands are fixture-plant/revert procedures against the real scanner (no mock-of-subject anywhere); `git diff --stat <base_sha>..HEAD` + path allowlist; `grep -n '<architecture-job-id>' .github/workflows/ci.yml` — placeholder is deliverable-derived, acceptable.
- P2 acceptance: `pnpm lint`, `node tools/audit-i18n-completeness.mjs`, `pnpm test:tools`, `pnpm test:eslint-rules` all exact; the `../../.github/workflows/ci.yml` relative path from `apps/web` resolves to the repo root correctly.
- P3 acceptance: census-derived phpunit template with anchored `--filter '^…$'` + the NONZERO-selection parse rule (grounded: no `failOnEmptyTestSuite` in phpunit.xml, verified); `./vendor/bin/phpstan` with the live-DB-env caveat carried from §5.
- M0/M2 precondition commands in all three YAMLs syntactically runnable as written: `git rev-parse --verify <sha>`, `git merge-base --is-ancestor <a> <b>`, `git show <sha>:apps/api/...StockAdjustmentService.php | grep -n 'assertReferenceLinkagePaired'`, `git cat-file -e <sha>:apps/api/...ProvisioningRequiredPurposesV1.php`, `git rev-parse <seed-commit>:<baseline-path>`, `git cat-file blob <hash>` — all valid git syntax; the seam-grep target string exists at the current tree (`StockAdjustmentService.php:1719`).
- No impossible interleavings, no lazy-row barriers, no mocked subjects in any contract.

## Check 4 — Behavior claims cite source (load-bearing citations spot-opened)

All verified exact unless noted: `ci.yml` :3-8, :27, :105, :143-178, :180-185 (incl. verbatim skip comment), :226-230, :275, :284-293, :294, :629, :726, :740-747, :833-851, :844-848, :853-891, :876/:879/:882/:885/:890, :893, :918-922, :977-981, :1012, :1090-1103/:1090-1107/:1103/:1104 · `StockAdjustmentService.php` :1700, :1715-1716, :1719, :1574-1590, :1838-1863 · `BatchStockService.php` :88-105/:322-335 (firstOrCreate at :94/:325) · `GeneralLedgerService.php` :2874, :3450, :3480-3510, :4072 · `TreasuryReceiptBridge` postEntryNow :473/:547/:1387, `$requiredPurposes` :446/:528 · `InstrumentLifecycleService` :274/:456/:488/:847 · `phpunit.xml:17-18` · `apps/web/package.json` :10/:12 · `i18n.ts` :392-405/:416-424 (en-aliased namespaces), :429-430 (spreads), :435-442 (init), fallbackLng :440, ns :442 · `setup.ts:2` · `audit-quantity-display.mjs` :342-358 (partitionViolationsByBaseline :342), :418-442 (`--write-baseline` writer), :449-461 (stale-entry "remove" messaging) · `SELF-REVIEW-HARNESS.md` :49-50 (owner_gate STOP — grep-exact) and :66-75 (whole-branch obligation at :74-75) · `adversarial-review.sh:49-64` · sweep audit :136-139 (quote **verbatim**) · `wave3-3c-3d.progress.yaml:49-56` (M3 = 3C tail) · `CODEX-DISPATCH-wave3-3c-3d:612-617` · `CODEX-DISPATCH-ui-wave0:240-247` · country brief :57-66 (D-6 in range), :289-318 (M1), :314-318 (frozen-seeder markers) · `country-defaults-phase-a.progress.yaml` :33-40 (M1) / :81-88 (M7) · `HANDOVER-openapi-lane:32-36` (lane live/owns branch) · openapi plan :9-16/:21-26 (CI-drift-guarded deliverable + coverage-harness CI script) · `PLAN-p0…:53` (§W-6 D1a/D1b, −19.000 campaign money) · `AGENTS.md:16` (Phase commit format) · `ChartOfAccountsService.php:51` (requiredPurposes() foreach) · `SystemAccountPurpose::expectedAccountType()` at :187 vs brief "~186" — explicitly approximate, within tolerance · `#[Group('sweep-progress')]` attribute real (`TenantScopedFindCallsTest.php:35`, header documents it :26) · `BackfillChartPurposesCommand.php` exists · all four target models + `StockMovementReferenceType.php` + all 7 named writer classes exist at the stated/na med paths · `i18nRawKeyCoverage.test.tsx` exists and pins hand-chosen keys · LEDGER S-14/S-15 as described. UNVERIFIED items are flagged as such in the brief itself (F-5) with dispatch-time pinning obligations — compliant.

## Check 5 — Permission keys

`grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\("` over the brief → no matches. The brief names no permission or module keys. **N/A — PASS.**

## Hygiene

- **Pipes:** four GFM tables (sequencing :51-55 = 5 pipes/row; read-order :86-93 = 4; DO-NOT-TOUCH :111-118 = 3; contract :134-139 = 4) — every row matches its header count; no unescaped in-cell pipes.
- **Banner ↔ log tail:** banner (:4) = r6, 2026-08-12, gate-r2 fix round applied, NOT yet re-gated; last revision-log entry = the r6 block (:29-40). Consistent, and the banner honestly demands round-0 + gate round 3 before dispatch.
- **Stale revision refs:** every pre-r6 mechanism mention (branch-name comparison, H-11 trio, "every push and PR", `:877/:880/:883`) survives only inside historical log entries or explicit supersession notes; zero operative stale text (greps in evidence).
- **YAML validity/shape:** all three parse (js-yaml); each has exactly one `base_sha`, one `branch`, ordered `milestones` (P1: M0-M3, P2: M0-M4, P3: M0-M3 — matching the brief's milestone lists), `owner_gates`, `max_fix_rounds: 5`, `reviewer_model: opus`; **milestone-level `owner_gate:` fields = 0 in all three** (the only `owner_gate:` strings are the safety comments); the safe-encoding claim's exemplars (`es-wave-a0`/`ui-wave0` YAMLs) carry the same pattern; `ui-wave0.progress.yaml` M4 (:64-65) indeed owns T3/T3(b) as 2(a)'s decision rule assumes.

## Note-only observations (not findings, no action required for gate dispatch)

1. `TreasuryReceiptBridge` lives at `app/Modules/Treasury/Application/Projections/` (not `Application/Services/`). The brief never states a path for it — only line numbers, all of which verify — so no citation is wrong; the gate reviewer just shouldn't assume a Services/ path.
2. The i18n en-alias ranges `:392-405`/`:416-424` contain a few Arabic-authored entries interleaved (e.g. `menu: arMenu` :393, `channels: arChannels` :419). The claim — that these ranges are where whole-namespace English aliasing for `ar` occurs — is correct and the operative mechanism (authored-provenance scanning) is unaffected.
3. `wave3-3c-3d.progress.yaml` M3 currently reads `status: pending` — consistent with the brief, which treats the ACCEPT state as a dispatch-time precondition (P1-M0), not a present-tense claim.

## Evidence appendix (key raw outputs)

```
HEAD: a5520f23ca39209f5b517723037e9516808f2bca  (== brief verification base)
ci.yml on-block (1-8): push.branches [main] / pull_request.branches [main, dev] / workflow_dispatch
ci.yml:744-745: "on.push.branches (top of file) is `[main]` only, so a push event on `dev`
                 never actually triggers this workflow at all"
job anchors: backend-lint:27 backend-analyse:105 backend-architecture:143 backend-test:180
             frontend-lint:853 frontend-typecheck:893 frontend-test:918 frontend-build:977
             types-drift:1012 all-checks-pass:1090 (if::1103, needs::1104 incl. frontend-lint)
grep run-lines: 876 audit:keys · 879 audit:design-system · 882 audit:quantity · 885 supersedes-comment · 890 lint:ratchet
allowlist counts: :629 → 93 · :726 → 16 · AnalyticsTest substring matches in :629 → 2
grep -rn 'test:eslint-rules\|tools/__tests__' .github/workflows/ → exit 1
grep -rn 'audit-i18n-completeness\|check-manifest-drift' .github/workflows/ → exit 1
tests/Architecture: 16 *Test.php; ParserFactory → exactly the 4 named; RefreshDatabase → none
phpunit.xml: suite Architecture at :17-18; failOnEmptyTestSuite → absent (exit 1)
sweep audit: V1..V10 (grep -cE '^### V' → 10); Tier-3 grays G1,G2,G3 → 3
SystemAccountPurpose cases → 41; expectedAccountType() at :187
tools/__tests__: 6 files, grep -L vitest → empty
eslint-rules: tests for no-dead-tailwind-token-interpolation / no-hardcoded-step / no-literal-decimal-places;
              none for no-parsefloat-on-money.js / no-untranslated-literal.js / no-hardcoded-entity-route.js
apps/web/package.json: :10 lint chain (verbatim match) · :12 test:eslint-rules · test:tools absent (exit 1)
i18n.ts: fallbackLng 'en' :440 · ns array :442 · spreads :429-430 · setup.ts:2 imports ../lib/i18n
gen-route-manifest.mjs (scripts/factory/): WRAPPERS :31-34; KeyedByRouteId absent (exit 1)
STATUS_RE (audit-design-system.mjs:59-62): suffixes Colors/Classes/Maps/Styles/Config/Badge — no Tone/Tones
YAML parse (js-yaml): p1 pins base_sha,dpa_3c_merge_sha,dpa_3c_reviewed_sha,dpa_baseline_seed_commit,
  dpa_baseline_protected_blob,pre_promotion_ci_dispatch,commit_series · p2 pins quiet_window_ack,
  merge_announcement_ack,i18n_baseline_seed_commit,i18n_baseline_protected_blob,pre_promotion_ci_dispatch ·
  p3 pins p1_landed_sha,country_defaults_landed_sha,p2_m2_landed_sha,pre_promotion_ci_dispatch ·
  milestone-level owner_gate fields = 0/0/0
node --test in brief+YAMLs: only inside the R2-H-3 removal ruling text (4 hits, all historical/ruling)
:877/:880/:883 in brief: only inside the r5 log entry (historical)
LEDGER S-14 (:56) / S-15 (:57): match the brief's §5/§6-F-7/§4-3(a)-6 descriptions
```

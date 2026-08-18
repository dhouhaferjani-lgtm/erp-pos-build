# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r7 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-12

Scope: the brief at r7 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15, all UNCOMMITTED on `dev` (`git status --porcelain` → all five `??`). `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca`, exactly the brief's stated verification base `a5520f23c` — zero drift.

**Methodology law observed:** every line number below was re-derived from the tree in this session. Nothing was copied from the r6 round-0 report, the gate-r3 register, or any other report. **Conjunction law observed:** every "A is wired into B" claim was opened on both sides and the connecting edge checked (see Check 1 R3-C-2 and Check 4 aggregate-membership rows).

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 1 | Revision-log truth (r7: 6 items; r3/r4/r5/r6 spot-checks) | **FAIL** | 1 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable     | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 — no permission/module keys named |
| H | Hygiene (pipes, banner)       | **PASS** | 0 — stale-text sweep's single hit is reported as R0-1 under Check 1, not double-counted here |

**VERDICT: FAIL — one FAIL row (Check 1). The document does NOT pass round 0 and must not be sent to gate round 4 until R0-1 is fixed and round 0 is re-run from the top.**

The defect is narrow and textual, not structural: the R3-C-2 receipt-cycle repair was applied to the P1 acceptance block, both YAML comment blocks, the execution-mode block, F-7, §5 item 4, §7 and LEDGER S-14 — but **the Package 2 acceptance block was missed** and still carries the superseded r6 "receipt recorded BEFORE merge" contract.

---

## Findings

- **[R0-1] Check 1 · CRITICAL — R3-C-2 log claim not fully true in operative text.** The r7 log (brief `:44`) claims post-acceptance receipts were moved OUT of the promoted candidate and that `pre_promotion_ci_dispatch` is "recorded AFTER promotion, on dev, in a POST-PROMOTION ADMIN COMMIT". The **Package 2 acceptance-evidence block** — operative acceptance criteria, `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:290` — still states the superseded r6 semantics verbatim:

  ```
  290: #   (gate-r2 R2-C-2/R2-H-2), recorded in pre_promotion_ci_dispatch + LEDGER §2 row S-14 BEFORE merge.
  ```

  This is exactly the self-referential cycle R3-C-2 was raised to kill (recording into the candidate changes the SHA it attests). The **P1 equivalent was correctly rewritten** — `:205-209` reads "…the parent verifies the run … BEFORE merging, **then records it in pre_promotion_ci_dispatch in the POST-promotion admin commit**" — proving the fix was applied per-block and P2's block was skipped, not that the phrasing is a harmless shorthand.

  Corroborating adjacent phrasing in the same block, `:292-293`: "the parent verifies **the recorded run** (green, head SHA == accepted candidate SHA, those steps/jobs executed) **BEFORE merging**" — "the recorded run" presupposes the recording already happened pre-merge. The P1 counterpart at `:205-209` says "verifies the run … then records it". Both lines should be brought onto the R3-C-2 contract.

  Commands run:
  ```
  $ grep -n 'pre_promotion_ci_dispatch' docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md
  31: … (r6 revision log — historical, OK)
  44: … (r7 revision log — the claim itself)
  88: "the receipt goes into pre_promotion_ci_dispatch in the POST-promotion admin commit"   OK
  209: "then records it in pre_promotion_ci_dispatch in the POST-promotion admin commit"      OK (P1 block)
  290: "recorded in pre_promotion_ci_dispatch + LEDGER §2 row S-14 BEFORE merge."             ← STALE (P2 block)
  379: "AFTER promotion, the parent records the receipts … POST-PROMOTION ADMIN COMMIT"       OK (§5 item 4 step 5)
  393: "receipts recorded in pre_promotion_ci_dispatch in the POST-promotion admin commit"    OK (F-7)
  406: "receipts recorded post-promotion in pre_promotion_ci_dispatch"                        OK (§7 item 5)
  ```

  **Repair:** rewrite `:290` (and align `:292-293`) onto the §5 item 4 / `:205-209` wording — the green dispatch run on exactly the accepted SHA is verified BEFORE merge; the receipt is recorded AFTER promotion in the admin commit. Then bump the revision log honestly to r8 and re-run round 0 from the top.

No other finding. Everything else below verified clean.

---

## Check 1 — Revision-log truth

### r7 log: all SIX gate-r3 finding-ID → change claims, verified against brief operative sections + YAMLs + LEDGER

| Claim | Required change | Where verified (re-derived) | OK |
|---|---|---|---|
| **R3-C-1** | Authority = owner-set repo variables in OPERATIVE mechanism text; YAML pins called NON-AUTHORITATIVE MIRRORS; fail-closed on unset var + unfetchable blob + mirror drift; owner-authenticated bootstrap/re-pin; §6 F-8 exists | **Brief §2 deliverable 3(c) Phase 2** `:160` — "the CI checker takes the protected blob hash from the **owner-set GitHub Actions repository variable `DPA_BASELINE_PROTECTED_BLOB`** — never from the YAML, never from a branch name"; `git cat-file blob "$DPA_BASELINE_PROTECTED_BLOB"`; "**FAIL CLOSED** on: variable unset/empty …, blob object unfetchable …, or the YAML mirror `dpa_baseline_protected_blob` differing from the variable (**mirror drift is a tamper signal**)". Bootstrap/re-pin `:161` = OWNER-authenticated variable write, "Never an executor write, never a CI write, never a candidate diff". **Brief §3 2(c) deliverable 1** `:254` — same three fail-closed conditions for `I18N_BASELINE_PROTECTED_BLOB`. **P1 YAML pin comment** `:37-53` — "NON-AUTHORITATIVE MIRROR pins", "The AUTHORITY the CI checker reads is the OWNER-SET repository variable DPA_BASELINE_PROTECTED_BLOB (the executor cannot write repo variables — no push, no admin)", "mirror != variable = CI FAIL (drift tripwire, fail closed — as are unset variable and unfetchable blob object)". **P2 YAML pin comment** `:28-41` — same, for I18N. **P1 M2 title** `:119` and **P2 M1 title** `:108` both carry "OWNER-SET repository variable …; FAIL CLOSED on unset variable / unfetchable blob / YAML-mirror drift". **§6 F-8** exists at `:394`, titled "OWNER-OPS DEPENDENCY (new gate-r3 R3-C-1)", with the residual-risk acceptance for candidate-editable `ci.yml`. Cross-referenced from P1 owner_gate `baseline-re-pin-after-promotion` `:85-88`, P2 owner_gate `i18n-baseline-re-pin` `:77-80`, and §5 item 4 step 2 `:376`. | ✓ |
| **R3-C-2** | Promotion SHA == accepted SHA == dispatched head, nothing committed between ACCEPT and merge; receipts in a POST-promotion admin commit; same semantics in all 3 YAML `pre_promotion_ci_dispatch` comments + P2 `merge_announcement_ack` comment + execution-mode block + LEDGER S-14 | **§5 item 4** `:374-380`: preamble "with **NOTHING committed to the package between final-gate ACCEPT and merge**"; step 3 head==A + GREEN + jobs executed; step 4 "The parent merges **exactly A** … promotion SHA == accepted SHA == dispatched head"; step 5 "AFTER promotion, the parent records the receipts … in a **POST-PROMOTION ADMIN COMMIT** … diff touches ONLY `docs/handoff/progress/*.progress.yaml` + `docs/handoff/LEDGER.md`". **P1 YAML** `:55-63`, **P2 YAML** `:43-50`, **P3 YAML** `:51-58` — all three carry "nothing is committed to the package between ACCEPT and merge" + "written by the parent AFTER promotion in the post-promotion ADMIN COMMIT" + "No green run on exactly A = PROMOTION BLOCKED (LEDGER §2 row S-14)". **P2 `merge_announcement_ack` comment** `:20-26` — "SENT after M3/M4 acceptance and BEFORE promotion … THIS field is the RECORD, written by the parent AFTER promotion in the post-promotion admin commit". **Execution-mode block** `:88` — "a green run on EXACTLY the accepted candidate SHA, which the parent then merges UNCHANGED — the receipt goes into `pre_promotion_ci_dispatch` in the POST-promotion admin commit". **LEDGER S-14** (`LEDGER.md:56`) — full 5-step sequence, "(4) the parent merges EXACTLY A — promotion SHA == accepted SHA == dispatched head; (5) AFTER promotion the parent records run URL + result … in a POST-promotion admin commit touching only … (admin-only delta, `a5520f23c`)". **All named locations verified present and correct.** | **✗ — see R0-1**: an OPERATIVE location the claim did not name, the **§3 Package 2 acceptance block** `:290`, still carries the superseded pre-merge recording contract. |
| **R3-H-1** | `M3.commit == dpa_3c_reviewed_sha` (exact equality) in sequencing-table P1 row, P1 YAML header check 3, P1 M0 title | **Sequencing table P1 row** `:61` — "(iii) the accepted M3 `commit` EQUALS `dpa_3c_merge_sha`, or — when both pins are set — M3's recorded `commit` EQUALS `dpa_3c_reviewed_sha` (gate-r3 R3-H-1: exact equality, an arbitrary ancestor can never stand in for the reviewed tip) AND reviewed-tip → merge-commit → `base_sha` ancestry holds". **P1 YAML header check 3** `:18-24` — "AND its recorded commit EQUALS dpa_3c_merge_sha — or, when dpa_3c_reviewed_sha is set, its recorded commit EQUALS dpa_3c_reviewed_sha (exact equality, gate-r3 R3-H-1) AND the chain reviewed-tip -> merge-commit -> base_sha holds … (both hops)". **P1 M0 title** `:103` — same, verbatim equality language plus "an arbitrary ancestor can never stand in for the reviewed tip". Also mirrored in P1 owner_gate `base-pin-and-3c-ancestry` `:79`. Cited source block re-derived: `wave3-3c-3d.progress.yaml:49-56` = the M3 block ("3C tail: T18, T19, T19b …"), `status: pending`, `commit: null`, `verdict: null` — consistent with the brief treating ACCEPT as a dispatch-time precondition, not a present-tense claim. | ✓ |
| **R3-H-2** | `p2_m2_landed_sha` → `p2_landed_sha` with whole-package proof (top-level `status: complete`, M4 passed + ACCEPT + commit, M2.commit → M4.commit → p2_landed_sha → base_sha) | **P3 YAML pin** `:49` `p2_landed_sha: null`; **header check 2b** `:23-30` (a. non-null + ancestor; b. top-level `status: complete` + M4 `status: passed` + parseable ACCEPT verdict + recorded commit; c. the four-hop ancestry chain); **M0 title** `:92` item (1b), same content, "an M2-passed snapshot ALONE IS INSUFFICIENT"; **owner_gates** `:73-76` id `p2-landed-at-dispatch`. **Brief sequencing-table P3 row** `:63`; **§4 3(b) deliverable 2** `:329`; **§4 milestones line** `:357` (`p3-M0` = "… the WHOLE P2 package landed per gate-r3 R3-H-2/R3-H-3: `p2_landed_sha` + P2 top-level `status: complete` + M4 ACCEPT + the M2.commit → M4.commit → `p2_landed_sha` → `base_sha` ancestry chain"). **js-yaml parse confirms `p2_m2_landed_sha` is not a key in any of the three YAMLs.** Every surviving textual occurrence of `p2_m2_landed_sha` is historical/supersession: brief `:37` (r6 log), brief `:46` (r7 log), brief `:63` ("superseding the r6 M2-time `p2_m2_landed_sha` pin"), P3 YAML `:17` and `:92` (both explicit supersession notes). | ✓ |
| **R3-H-3** | P2-landed is a P3 DISPATCH/M0 precondition; the "pin after dispatch" affordance is gone from OPERATIVE text; §3 2(b) reciprocal note updated | **P3 YAML** `:15-19` pin comment — "a DISPATCH/M0 precondition now; the 'pin after dispatch' affordance is DELETED, there is NO mid-wave integration, P3 is strictly last"; **owner_gate** `:74` — "Null/unverified at M0 = blocked_precondition; there is NO mid-wave pin and NO mid-wave integration"; **P3-M2 title** `:108` — "The P2 dependency is already satisfied at M0 (gate-r3 R3-H-3 — the whole P2 package is on the base by dispatch precondition; 'per 2(b) outcome' is a landed fact, no mid-wave pin exists)". **Brief §3 2(b) reciprocal note** `:247` — "P3 DISPATCHES only after the WHOLE P2 package has landed (`p2_landed_sha` is a P3-M0 dispatch precondition; P3's base contains this milestone's outcome by construction, so there is no mid-wave pin and no mid-wave integration)"; **sequencing table** `:63` — "There is NO mid-wave pin and NO mid-wave integration; P3 is strictly last (P1 and P2 may still run in parallel with each other)"; **P2-M2 title** `:116` carries the reciprocal. Stale-affordance sweep: `grep -n 'after dispatch\|mid-wave\|M2-time'` → **every hit is inside a negation or an explicit supersession note**; zero surviving operative affordance. | ✓ |
| **R3-H-4** | commit-1 / commit-2 / then-review order in §2 3(c) Phase 1, §3 2(c) deliverable 1, both YAML pin comments + M2/M1 titles; no operative "after the seed commit is reviewed" | **Brief §2 3(c) Phase 1** `:159` — "the initial baseline is generated in a DEDICATED SEED COMMIT (**commit 1**) …; a SECOND metadata commit (**commit 2**) records the seed commit SHA and the seed baseline's git blob hash … as the NON-AUTHORITATIVE MIRROR pins …; **ONLY THEN** is the P1-M2 bridge review invoked, and its verdict covers BOTH commits. A fix round that regenerates the seed recomputes the blob hash and updates the mirrors in the fix commit BEFORE re-review." **Brief §3 2(c) deliverable 1** `:254` — same three-step order for the i18n seed at P2-M1. **P1 YAML** `:42-46` ("Pin order INSIDE P1-M2 … commit 1 = the dedicated seed commit …; commit 2 = these mirror pins …; ONLY THEN the M2 bridge review runs, covering both commits"). **P2 YAML** `:33-35` (same for P2-M1). **P1 M2 title** `:119` — "ORDER INSIDE THIS MILESTONE: commit 1 = seed commit; commit 2 = mirror pins … + checker metadata; ONLY THEN the bridge review, whose verdict covers both commits". **P2 M1 title** `:108` — "commit 1 = dedicated SEED COMMIT, commit 2 = mirror pins … ONLY THEN this milestone's bridge review". Stale-phrase sweep: `grep -ni 'after the seed commit is reviewed\|after .* is reviewed\|once reviewed'` over brief + all three YAMLs → **exactly one hit, brief `:48`, inside the r7 revision-log entry describing the fix**. Zero operative survivors. Also confirmed the §5 item 4 step 2 and F-8 bootstrap ordering do not contradict it. | ✓ |

### r3 / r4 / r5 / r6 spot-checks (patterns from the prior report; all citations re-derived from the tree, none copied)

- **r3 C-1 (three harness-schema YAML files, no nested file):** exactly three `enforcement-p{1,2,3}.progress.yaml` exist; js-yaml parse of each confirms the harness top-level schema — one `base_sha`, one `branch`, ordered `milestones`, `owner_gates`, `max_fix_rounds: 5`, `reviewer_model: opus`; P3-after-P1 / P1-after-3C are machine-checkable M0 items, not prose. ✓
- **r3 C-2 (GL chokepoint already balance-validates):** re-read in code — `GeneralLedgerService::sealAndPersistEntry` declared at `:3480`, `$entry->load('lines')` `:3486`, `bcadd` sums `:3501-3504`, `bccomp` !== 0 → `InvalidArgumentException` `:3507-3510` (all inside the cited `:3480-3510`); `postEntry` `:2874` (via `postEntryWithOptionalActor` `:2879`), `postEntryNow` `:3450`, `createPOSChargeEntry` `:4072`. Named bridges re-derived: `TreasuryReceiptBridge` `postEntryNow` at `:473`, `:547`, `:1387`; `InstrumentLifecycleService` at `:274`, `:456`, `:488`, `:847` — exactly the four cited. ✓
- **r3 H-4 (real write mechanisms):** `StockLevel::firstOrCreate` at `StockAdjustmentService.php:1578` (∈ cited `:1574-1590`; zero-valued defaults `'0.00'` present at `:1587-1588`, matching the brief's "a zero-valued `firstOrCreate` writes a target row" gloss); `BatchStock::firstOrCreate` at `:1847` (∈ cited `:1838-1863`) and `BatchStockService.php:94` / `:325` (∈ cited `:88-105` / `:322-335`). ✓
- **r3 H-8 (lens → contract wiring is prose-only):** `scripts/adversarial-review.sh:49-64` re-read — `PROMPT=$(cat <<PROMPT_EOF` at `:49`, `DOMAIN LENSES for this milestone: ${LENSES:-general}` at `:57`; the heredoc interpolates the label string and loads no agent file. All six named contracts exist in `.claude/agents/`. ✓
- **r3 M-1 (Architecture-test gloss):** `tests/Architecture/*.php` → **16**; `grep -l ParserFactory` → exactly the 4 named (`AuthLifecycleTest`, `BroadcastChannelTenantContextTest`, `TenantScopedExistsRulesTest`, `TenantScopedFindCallsTest`); `grep -l RefreshDatabase` → **exit 1, zero**; `OrphanedTypesCleanupTest.php:15-29` = `orphanedTypeProvider()` + `assertFileDoesNotExist(base_path($path))` — a pure filesystem non-existence assertion, exactly as glossed. ✓
- **r3 M-2 / H-9 / H-10:** `git diff --stat <base_sha>..HEAD` + path allowlist present in the P1 acceptance block `:198-202` and in P1-M3 `:127`, P2-M4 `:132`, P3-M3 `:116`. `all-checks-pass` job block `:1090-1107`; `if:` `:1103`; `needs:` `:1104` **containing `frontend-lint`** — so both brief claims are true on their own terms (`:1090-1103` = "aggregate skipped on PR→dev", `:1090-1107` = the job block the new job must join, `:1104` = frontend-lint membership). LEDGER S-15 present at `LEDGER.md:57` with pointer-only semantics, matching brief `:319` and `:393`. ✓
- **r4 (R0-3, the false-conjunction fix):** conjunction re-checked on BOTH sides — `frontend-lint` job header `:853`, job body ends `:891`; the job's run-lines are `pnpm audit:keys` `:876`, `pnpm audit:design-system` `:879`, `pnpm audit:quantity` `:882`, `pnpm lint:ratchet` `:890`; **`pnpm lint` appears nowhere in the job**; the `:885` comment reads verbatim "Supersedes a raw `pnpm lint`". Other side: `apps/web/package.json:10` `lint` chain = `pnpm lint:eslint && pnpm audit:keys && pnpm audit:design-system && pnpm audit:quantity && pnpm test:eslint-rules` — matches the brief's quoted contents verbatim; `audit:keys` = `node tools/audit-tanstack-keys.mjs` (`:13`, TanStack not i18n) ✓. Runs-nowhere census re-run: `grep -rn 'test:eslint-rules\|tools/__tests__' .github/workflows/` → **exit 1** ✓. `test:eslint-rules` at `package.json:12` ✓. ✓
- **r5 (R0-4, re-derived run-lines):** the corrected `:876`/`:879`/`:882` re-derived by grep this session (above); the superseded `:877`/`:880`/`:883` appear in the brief only inside the r5 log entry `:27`. ✓
- **r6 (gate-r2) spot-checks:** `ci.yml:3-8` on-block re-derived (below); `ci.yml:740-747` dev-push comment re-derived (below); `test:tools` still absent from `apps/web/package.json` (grep exit 1 — correctly a deliverable, not a presence claim) and all 6 `tools/__tests__/*.mjs` import from `vitest`; `apps/api/phpunit.xml` has **no** `failOnEmptyTestSuite` (grep exit 1), grounding the nonzero-selection rule; `ci.yml:844-848` carries both directory commands with the Accounting run line at `:848`; all three YAML line-5 headers now reference **r7**. ✓

---

## Check 2 — Exhaustive claims / censuses (every census re-run this session)

Net cast: `grep -inoE 'exhaustive[a-z]*|complete partition|all [0-9]+|[0-9]+ (class names|classes|entries|call sites|endpoints|events|routes|tables|keys|cases)|4 of 16|93|41-case|16-entry|EVERY .tests/Feature|every current'` over the brief → 17 hits at `:15, :34, :98, :109, :153, :158, :221, :223(×2), :238(×2), :244, :246(×2), :254, :267, :323`. Each read in context and dispositioned below.

| Inventory claim (doc location) | Census command re-run | Documented | Re-derived | Match |
|---|---|---|---|---|
| pgsql allowlist "**93 class names**" (`:238`, `ci.yml:629`) | `sed -n '629p' ci.yml \| tr '\|' '\n' \| grep -c 'Test'` | 93 | **93** | ✓ |
| "second **16-entry** allowlist" (`:238`, `ci.yml:726`) | same, on `:726` | 16 | **16** | ✓ |
| `AnalyticsTest` / `ExpenseAnalyticsTest` "both happen to be listed" (`:240`) | `sed -n '629p' \| grep -o 'ExpenseAnalyticsTest\|AnalyticsTest' \| sort \| uniq -c` | both | **1 AnalyticsTest + 1 ExpenseAnalyticsTest** | ✓ |
| Architecture ParserFactory users "**4 of 16**" (`:153`) | `ls tests/Architecture/*.php \| wc -l`; `grep -l ParserFactory tests/Architecture/*.php` | 4 / 16, named | **4 / 16**, same 4 names | ✓ |
| "**none** use `RefreshDatabase`" (`:153`) | `grep -l RefreshDatabase tests/Architecture/*.php` | 0 | **0 (exit 1)** | ✓ |
| DPA audit "**10 violations + 3 GL-gap grays**" (`:136`) | `grep -oE '^### (V\|G)[0-9]+'`; Tier-3 section read | 10 + 3 | **V1–V10 = 10**; Tier 3 GRAY `:96-108` = **G1, G2, G3 = 3** | ✓ |
| "**complete 41-case partition** (27 REQUIRED / 1 SCOPE-REQUIRED / 4 CONDITIONAL / 9 SOFT)" (`:323`) | `grep -cE '^    case ' SystemAccountPurpose.php`; arithmetic | 41 | **41**; 27+1+4+9 = **41** | ✓ |
| "**every current** `tools/__tests__/*.mjs` imports from `vitest`" (`:34, :109, :158, :254, :267`) | `grep -L vitest apps/web/tools/__tests__/*` | all | **6 files, `grep -L` empty (exit 1); `grep -l` → 6/6** | ✓ |
| eslint-rules: tests exist for 3 named, **none** for 3 named (`:261`) | `ls apps/web/eslint-rules/` | 3 tested / 3 untested | **exactly as named** — `.test.mjs` for `no-dead-tailwind-token-interpolation`, `no-hardcoded-step`, `no-literal-decimal-places`; none for `no-parsefloat-on-money.js`, `no-untranslated-literal.js`, `no-hardcoded-entity-route.js` | ✓ |
| `test:eslint-rules` + `tools/__tests__` in **NO workflow** (`:223`) | `grep -rn 'test:eslint-rules\|tools/__tests__' .github/workflows/` | exit 1 | **exit 1** | ✓ |
| route-manifest drift check has **zero workflow references**; local only at `preflight.sh:193-195` (`:223`) | `grep -rn 'check-manifest-drift\|route-manifest\|gen-route-manifest' .github/workflows/`; read preflight | 0 refs; `:193-195` | **exit 1**; `:193` echo, `:194` `bash "$ROOT_DIR/scripts/factory/check-manifest-drift.sh"`, `:195` success echo — exact | ✓ |
| C6 `STATUS_RE` never matches `Tone`/`Tones` (`:261`) | read `audit-design-system.mjs:59-62` | true at base | patterns = `Colors?\|Classes?\|Maps?\|Styles?\|Config\|Badge\w*` — **no `Tone`** | ✓ |
| `KeyedByRouteId` missing from `gen-route-manifest.mjs:31-34` `WRAPPERS` (`:65`) | read + `grep -n KeyedByRouteId scripts/factory/gen-route-manifest.mjs` | missing | **WRAPPERS block = `:31-34`**; `KeyedByRouteId` **absent (exit 1)** | ✓ |
| Architecture suite runs in NO automatic lane; only `--testsuite` invocation is Unit `:275` (`:165`) | `grep -n 'testsuite' ci.yml` | `:275` only | `:275` = `run: php artisan test --testsuite=Unit --coverage-clover=coverage.xml`; other hits are comments | ✓ |
| "EVERY `tests/Feature` class" (`:246`) | — | — | **Not a brief-asserted inventory**: it is a mandated *deliverable* (a mechanically-checked manifest the executor must build, with a script that fails on any unassigned class) + a planted-class negative proof. Correctly framed; no census owed by the brief. | ✓ (N/A) |
| "**Exhaustive** class-to-lane/exclusion manifest" (`:15`) | — | — | Inside the r3 revision-log entry describing the H-6 ruling; the operative statement is `:246` above. | ✓ (N/A) |

**Note (not a finding):** the mission paragraph `:109` cites "~25–30 dev-days" as the *DPA sizing headline* — an explicitly attributed program estimate, not a repo inventory, and it carries no census obligation. The load-bearing part of that sentence — the sweep plan's **verbatim** words — was re-derived at `docs/superpowers/audits/2026-08-08-document-per-action-violation-sweep.md:136-139` and matches the brief's quotation character for character.

---

## Check 3 — Test contracts executable

| Contract | Command present | Physically executable | Mock-of-subject |
|---|---|---|---|
| P1 guard green (`:185`) | ✓ `cd apps/api && ./vendor/bin/phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php` | ✓ path is a named deliverable of the same package | none |
| P1 tamper cases 1–2 (`:187-188`) | ✓ plant/revert procedure against the real scanner | ✓ | none — fixture-plant, no mocks |
| P1 ratchet cases 3–4 (`:190-191`) | ✓ delete/add baseline entry → FAIL → restore | ✓ | none |
| P1 anti-growth case 5 (`:192-197`, **r7-rewritten**) | ✓ `git cat-file blob "$DPA_BASELINE_PROTECTED_BLOB"` with the explicit local instruction "export the reviewed seed blob hash under the same env-var name" | ✓ valid git plumbing; the env-var indirection is locally satisfiable exactly as written, so the new variable-authority contract did **not** make its own tamper proof unrunnable. Fail-closed sub-cases (mirror edited → FAIL; variable unset → FAIL) are both locally reproducible | none |
| P1 scope proof (`:199-202`) | ✓ `git diff --stat <base_sha>..HEAD` + explicit path allowlist | ✓ | n/a |
| P1 aggregate membership (`:204`) | ✓ `grep -n '<architecture-job-id>' .github/workflows/ci.yml` | ✓ placeholder resolves from the deliverable's own job id | n/a |
| P2 acceptance (`:272-283`) | ✓ `pnpm lint`, `node tools/audit-i18n-completeness.mjs`, `pnpm test:tools`, `pnpm test:eslint-rules`, two `grep -n … ../../.github/workflows/ci.yml` | ✓ — relative path re-checked: from `apps/web`, `../../.github/workflows/ci.yml` resolves to the repo root file. `pnpm test:tools` is the exact string the package's own deliverable adds | none |
| P3 acceptance (`:335-354`) | ✓ census-derived `./vendor/bin/phpunit <path> --filter '^…$'` template + `./vendor/bin/phpstan` | ✓ — and the nonzero-selected-count rule is **grounded**, not assumed: `apps/api/phpunit.xml` has no `failOnEmptyTestSuite` (re-verified, grep exit 1), so exit code alone genuinely proves nothing. `phpstan` carries the live-DB-env caveat from §5 | none |
| P1/P2/P3 M0 precondition commands (YAMLs) | ✓ `git rev-parse --verify`, `git merge-base --is-ancestor`, `git show <sha>:apps/api/…StockAdjustmentService.php \| grep -n 'assertReferenceLinkagePaired'`, `git cat-file -e <sha>:…ProvisioningRequiredPurposesV1.php`, `git rev-parse <seed-commit>:<baseline-path>`, `git cat-file blob <hash>` | ✓ all valid git syntax; the seam-grep target string exists in the current tree at `StockAdjustmentService.php:1719` | n/a |

No impossible interleavings, no synchronization barriers on lazily-created rows, no contract asserting through a mock of its own subject. **PASS.**

---

## Check 4 — Behavior/repo claims cite source (load-bearing citations spot-opened and re-derived)

**Event-graph re-derivation (mandated, re-run from the top of the file):**
```
ci.yml:3   on:
ci.yml:4     push:
ci.yml:5       branches: [main]              # post-merge confirmation only
ci.yml:6     pull_request:
ci.yml:7       branches: [main, dev]         # cheap checks on PR→dev; full pipeline on PR→main
ci.yml:8     workflow_dispatch:              # manual full run from the Actions tab
```
Nothing else. The brief's `:3-8` citation and its stated graph (PR→main, PR→dev, push→main, `workflow_dispatch` only) are **exact**.

**Dev-push comment block re-derivation:** `ci.yml:740-747` is the comment block; the load-bearing sentence sits at `:744-745` — "this workflow's `on.push.branches` (top of file) is `[main]` only, so a push event on `dev` never actually triggers this workflow at all". The brief's claim that this is documented "outright" at `:740-747` is exact.

**All other cited `file:line` re-opened this session and confirmed to say what the brief claims:**

`ci.yml` — `:27` backend-lint · `:105` backend-analyse · `:143-178` backend-architecture deptrac ratchet (job `:143`, `run: php tools/deptrac-ratchet.php … --baseline=deptrac.baseline.json` `:178`) · `:180-185` backend-test with the **verbatim** skip comment "Heavy job… Skipped on PR→dev — local preflight is the gate during dev iteration" (`:183-184`) and its `if:` `:185` · `:226-230` "The Postgres service remains available for future Feature-suite coverage that opts into it explicitly" (`:225-226`) · `:275` `--testsuite=Unit` · `:284-293` the Security-lane comment celebrating "new classes are gated the moment they land" (`:290-291`) · `:294` `run: ./vendor/bin/phpunit tests/Feature/Security` · `:629` / `:726` allowlists · `:833-851` / `:844-848` (`:844` Treasury step name, `:847` Accounting step name, **`:848` `run: ./vendor/bin/phpunit tests/Feature/Accounting`**) · `:853-891` frontend-lint · `:876`/`:879`/`:882`/`:885`/`:890` · `:893` frontend-typecheck · `:918-922` / `:977-981` heavy-job skips · `:1012` types-drift · `:1090-1107` / `:1103` / `:1104`.

**Conjunction check (aggregate membership).** The brief asserts "`frontend-lint` is in the `all-checks-pass` `needs` list today (`ci.yml:1104`)". Both sides opened: `frontend-lint` job exists at `:853`; `needs:` at `:1104` = `[backend-lint, backend-analyse, backend-architecture, backend-test, backend-test-pgsql, treasury-spine-pgsql, frontend-lint, frontend-typecheck, frontend-test, pos-test, frontend-build, types-drift]` — **the connecting edge exists**. The reciprocal claim, that the Architecture suite has no such edge, also holds: no Architecture job exists to be in the list.

Code — `StockAdjustmentService.php` `:1700` (`private function recordMovement(`), `:1715` `?StockMovementReferenceType $referenceType = null`, `:1716` `?string $referenceId = null`, `:1719` `$this->assertReferenceLinkagePaired(...)` (helper declared `:1788`); `:1574-1590`, `:1838-1863` · `BatchStockService.php:88-105` / `:322-335` (firstOrCreate `:94` / `:325`) · `GeneralLedgerService.php` `:2874`, `:2879`, `:3450`, `:3480-3510`, `:4072` · `TreasuryReceiptBridge` postEntryNow `:473`/`:547`/`:1387`, `$requiredPurposes` `:446` and `:528` · `InstrumentLifecycleService` `:274`/`:456`/`:488`/`:847` · `ChartOfAccountsService.php:51` = **exactly** `foreach (SystemAccountPurpose::requiredPurposes() as $purpose)` (the line the brief says a tightening proposal would grow) · `SystemAccountPurpose::expectedAccountType()` declared at `:187` vs the brief's explicitly-approximate "`:~186`" — within the tilde's tolerance (docblock `:184-186`) · `phpunit.xml:17-18` (`<testsuite name="Architecture">` / `<directory>tests/Architecture</directory>`).

Frontend — `apps/web/package.json` `:10` / `:12` · `i18n.ts` `:392-405` and `:416-424` (English-aliased namespaces for `ar`), `:429-430` (`{...enNotifications, ...arNotifications}`, `{...enLocations, ...arLocations}` spreads), `:435-442` init block, `fallbackLng: 'en'` `:440`, `ns` array `:442` · `src/test/setup.ts:2` = `import '../lib/i18n'` (the **real** module, grounding the "no generic gate fails" claim) · `audit-quantity-display.mjs` `:342` `export function partitionViolationsByBaseline` (∈ cited `:342-358`), `:419` `--write-baseline` writer (∈ `:418-442`), `:459-461` stale-entry "shrink-only ratchet — remove …" messaging (∈ `:449-461`) · `audit-design-system.mjs:59-62` STATUS_RE · `scripts/factory/gen-route-manifest.mjs:31-34` WRAPPERS.

Docs/harness — `SELF-REVIEW-HARNESS.md:49-50` ("If the milestone's `owner_gate` field is set … STOP (condition B)") and `:66-75` (whole-branch obligation at `:74-75`) · `scripts/adversarial-review.sh:49-64` · `scripts/preflight.sh:193-195` · `wave3-3c-3d.progress.yaml:49-56` (M3 = "3C tail") · `CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md:612-617` ("3D is created only after gated 3C is on `dev`" / "Gate 3C, then merge `codex/dpa-wave3-3c` to local `dev`") · `CODEX-DISPATCH-ui-wave0-2026-08-11.md:240-247` (T3(b) job spec, no-`if:` rationale `:241`, `all-checks-pass` `needs` contract `:244`) · `ui-wave0.progress.yaml` M4 `:64-65` — **does** own T3(a)+T3(b), the assumption 2(a)'s ownership decision rule depends on · `country-defaults-phase-a` brief `:57-66` (D-1..D-8 table, **D-6 at `:64`**), `:289-318` (M1 "Invariant kernel"), `:314-318` (frozen-seeder `@deprecated` markers) · `country-defaults-phase-a.progress.yaml:33-40` (M1) and **`:81-88`** (M7 "Whole-branch integration gate + executable evidence manifest") · `HANDOVER-openapi-lane-orchestration-2026-08-07.md:32-36` ("**The lane is running.**") · openapi plan `:9-16` / `:21-26` (coverage-harness CI script at `:25`) · `PLAN-p0-fix-lanes-pre-production-2026-08-05.md:53` (W-6 D1a/D1b) · `AGENTS.md:16` (`Phase <major.minor.patch>: <imperative summary>`) · `LEDGER.md:56` (S-14) / `:57` (S-15) · all six `.claude/agents/*-reviewer.md` contracts exist.

UNVERIFIED items are flagged as such by the brief itself (F-5, `:391`) with dispatch-time pinning obligations — compliant with the check's intent. **PASS.**

---

## Check 5 — Permission keys

```
$ grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule" \
    docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md \
    docs/handoff/progress/enforcement-p1.progress.yaml \
    docs/handoff/progress/enforcement-p2.progress.yaml \
    docs/handoff/progress/enforcement-p3.progress.yaml
(no output)   exit 1
```
The document set names **no** permission or module keys. The `canAccessModule` fail-open trap cannot apply. **N/A — PASS.**

---

## Hygiene

- **Unescaped GFM pipes:** four normative tables, every row's cell count matched against its header by `awk -F'|'`:
  - sequencing table `:59-63` — header 4 cells, all 5 rows (incl. separator) = **4** ✓
  - read-before-starting `:94-101` — header 3, all 8 rows = **3** ✓
  - DO-NOT-TOUCH `:119-126` — header 2, all 8 rows = **2** ✓
  - write-surface contract `:142-147` — header 3, all 6 rows = **3** ✓

  No row loses a column. The regex-bearing cells that could have carried a bare `|` (the `--filter` alternations, the STATUS_RE quote) all sit in prose/code spans outside tables. ✓
- **Status banner ↔ latest revision-log entry:** banner `:4` = "**Revision:** r7 — 2026-08-12, full gate-r3 fix round applied … **NOT yet re-gated**. Re-run round 0 from the top, then gate round 4, before dispatch." Last revision-log block = the **r7** block opening at `:42`. Revision-block anchors re-derived: `:4` (banner), `:5` (r1/r2/r3), `:24` (r4), `:27` (r5), `:29` (r6), `:42` (r7) — the r7 block is genuinely last. Banner, log tail and date agree, and the banner honestly demands round 0 + gate round 4 before dispatch. ✓
- **YAML line-5 header comments (r7 + finding counts):** all three read identically — `# (r7 of the brief applies all 17 gate-r1 findings, round-0 R0-3/R0-4, the 11 gate-r2 findings, and the 6 gate-r3 findings; re-gate before dispatch).` Counts re-derived from the gate registers themselves, not from the brief:
  - gate-r1: `grep -cE '^### (C|H|M)-[0-9]+'` → **17** (C-1..C-4 = 4, H-1..H-11 = 11, M-1..M-2 = 2) ✓
  - gate-r2: `grep -cE '^### R2-(C|H|M)-[0-9]+'` → **11** (2C + 7H + 2M) ✓
  - gate-r3: `grep -cE '^### R3-(C|H|M)-[0-9]+'` → **6** (2C + 4H + **0M** — the register states "Minor findings: None") ✓
- **YAML validity / shape (js-yaml parse, run from `apps/web` where `js-yaml` resolves):** all three parse. Each has **exactly one** `base_sha` and **one** `branch`; milestones ordered and complete (P1 `[M0,M1,M2,M3]`, P2 `[M0,M1,M2,M3,M4]`, P3 `[M0,M1,M2,M3]` — matching the brief's milestone lists); **milestone-level `owner_gate:` fields = 0 / 0 / 0** (the only `owner_gate:` strings are the safety comments explaining why none exists); every r7 pin present and `null`-valued, none missing, none pre-filled; `p2_m2_landed_sha` is **not a key** in any file.
- **Stale operative text sweep:** `node --test` → 4 hits, **all** inside the R2-H-3 removal ruling / deliverable-4 text that mandates the removal (zero operative uses). `origin/` → 3 hits: `:162` inside the negation sentence ("there is no `origin/<base>`/branch ref anywhere in the mechanism"), `:331` and `:381` referring to the real `origin/dev` staging auto-deploy (correct, unrelated). `p2_m2_landed_sha` → 5 hits, **all** historical log or explicit supersession. "after the seed commit is reviewed" → 1 hit, the r7 log entry describing its own removal. **Receipt-timing phrasing → 1 stale operative survivor, reported as R0-1.**

---

## Note-only observations (not findings; no action required, recorded for the gate reviewer)

1. **`TreasuryReceiptBridge` directory.** The class lives at `apps/api/app/Modules/Treasury/Application/**Projections**/TreasuryReceiptBridge.php` (not `Application/Services/`). The brief never states a path for it — only line numbers, all of which verify — so no citation is wrong; the gate reviewer simply should not assume a `Services/` path when resolving `:446` / `:528` / `:473` / `:547` / `:1387`.
2. **Mixed Arabic-authored entries inside the cited alias ranges.** `i18n.ts:392-405` and `:416-424` do contain a few genuinely Arabic-authored entries interleaved with the English aliases (`menu: arMenu` `:393`, `'parts-catalog': arPartsCatalog` `:398`, `channels: arChannels` `:419`, `reports: arReports` `:420`). The brief's claim — that these ranges are *where* whole-namespace English aliasing for `ar` occurs — is correct, and the operative mechanism (authored-provenance scanning) distinguishes them by construction. Note-only, carried forward from r6.
3. **`wave3-3c-3d.progress.yaml` M3 is still `status: pending`, `commit: null`, `verdict: null`.** Consistent with the brief, which treats the ACCEPT state as a dispatch-time P1-M0 precondition, not a present-tense fact.
4. **`SystemAccountPurpose::expectedAccountType()` is at `:187`, brief says `:~186`.** The tilde is an explicit approximation marker and the docblock occupies `:184-186`; within tolerance, no correction owed.
5. **`§3 2(c)` calls the YAML pins "the YAML mirror pins" rather than the fuller "NON-AUTHORITATIVE MIRROR" used in `§2 3(c)` and both YAML comment blocks.** Semantically unambiguous in context (the same sentence says the checker reads the variable "never the YAML"), so not a finding — but the phrase could be harmonized in the same edit that fixes R0-1.

---

## Evidence appendix (raw outputs)

```
$ git rev-parse HEAD
a5520f23ca39209f5b517723037e9516808f2bca            (== brief verification base a5520f23c)

$ git status --porcelain <the five target files>
?? docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md
?? docs/handoff/LEDGER.md
?? docs/handoff/progress/enforcement-p1.progress.yaml
?? docs/handoff/progress/enforcement-p2.progress.yaml
?? docs/handoff/progress/enforcement-p3.progress.yaml

--- ci.yml event graph (lines 1-20, head of file) ---
 3  on:
 4    push:
 5      branches: [main]              # post-merge confirmation only
 6    pull_request:
 7      branches: [main, dev]         # cheap checks on PR→dev; full pipeline on PR→main
 8    workflow_dispatch:              # manual full run from the Actions tab

--- ci.yml dev-push comment block (740-747) ---
744  # this workflow's `on.push.branches` (top of file) is `[main]` only, so a
745  # push event on `dev` never actually triggers this workflow at all; it's
747  # trigger is ever widened to include `dev`.

--- ci.yml job anchors (re-derived by sed) ---
backend-lint:27  backend-analyse:105  backend-architecture:143  backend-test:180
frontend-lint:853  frontend-typecheck:893  frontend-test:918  frontend-build:977
types-drift:1012  all-checks-pass:1090  (if::1103, needs::1104)

--- ci.yml:1104 needs list (verbatim) ---
needs: [backend-lint, backend-analyse, backend-architecture, backend-test, backend-test-pgsql,
        treasury-spine-pgsql, frontend-lint, frontend-typecheck, frontend-test, pos-test,
        frontend-build, types-drift]

--- frontend-lint run-lines (grep, not counted) ---
876  run: pnpm audit:keys
879  run: pnpm audit:design-system
882  run: pnpm audit:quantity
885  # Supersedes a raw `pnpm lint`: errors still hard-fail immediately,
890  run: pnpm lint:ratchet
(no `pnpm lint` anywhere in the job)

--- allowlist censuses ---
sed -n '629p' ci.yml | tr '|' '\n' | grep -c 'Test'   →  93
sed -n '726p' ci.yml | tr '|' '\n' | grep -c 'Test'   →  16
sed -n '629p' ci.yml | grep -o '…AnalyticsTest'       →  1 AnalyticsTest + 1 ExpenseAnalyticsTest
:844 Treasury step name · :847 Accounting step name · :848 run: ./vendor/bin/phpunit tests/Feature/Accounting

--- runs-nowhere censuses ---
grep -rn 'test:eslint-rules|tools/__tests__' .github/workflows/                        → exit 1
grep -rn 'check-manifest-drift|audit-i18n-completeness|route-manifest|gen-route-manifest' .github/workflows/ → exit 1
grep -n 'test:tools' apps/web/package.json                                             → exit 1
grep -n 'failOnEmptyTestSuite' apps/api/phpunit.xml                                    → exit 1

--- tools/__tests__ vitest census ---
ls apps/web/tools/__tests__/ → 6 files (audit-design-system, audit-pos-local-cache, audit-quantity-display,
                                        audit-tanstack-keys, offset-pagination-meta-consolidation,
                                        permission-map-drift-guard — all *.test.mjs)
grep -L vitest apps/web/tools/__tests__/*  → (empty, exit 1)
grep -l vitest apps/web/tools/__tests__/* | wc -l → 6

--- tests/Architecture census ---
ls tests/Architecture/*.php | wc -l           → 16
grep -l ParserFactory tests/Architecture/*.php → AuthLifecycleTest, BroadcastChannelTenantContextTest,
                                                 TenantScopedExistsRulesTest, TenantScopedFindCallsTest  (4)
grep -l RefreshDatabase tests/Architecture/*.php → exit 1  (0)
OrphanedTypesCleanupTest.php:15-29 = orphanedTypeProvider() + assertFileDoesNotExist(base_path($path))

--- other censuses ---
grep -cE '^    case ' SystemAccountPurpose.php                        → 41   (27+1+4+9 = 41)
grep -oE '^### (V|G)[0-9]+' <DPA sweep>                               → V1..V10 (10)
sweep "## Tier 3 — GRAY" :96-108                                      → G1, G2, G3 (3)
sweep :136-139 quote                                                  → verbatim match with brief :109
ls apps/web/eslint-rules/                                             → 3 *.test.mjs; none for
                                                                        no-parsefloat-on-money.js,
                                                                        no-untranslated-literal.js,
                                                                        no-hardcoded-entity-route.js
audit-design-system.mjs:59-62 STATUS_RE suffixes                      → Colors?|Classes?|Maps?|Styles?|Config|Badge\w*
gen-route-manifest.mjs WRAPPERS :31-34; KeyedByRouteId                → absent (exit 1)

--- js-yaml parse (run from apps/web) ---
enforcement-p1.progress.yaml  PARSE OK · base_sha×1 (null) · branch (null)
  milestones [M0,M1,M2,M3] ordered_match=true · milestone-level owner_gate fields: NONE (0)
  r7 pins present, all null: dpa_3c_merge_sha, dpa_3c_reviewed_sha, dpa_baseline_seed_commit,
                             dpa_baseline_protected_blob, pre_promotion_ci_dispatch
  p2_m2_landed_sha as a key: false
enforcement-p2.progress.yaml  PARSE OK · base_sha×1 (null) · branch (null)
  milestones [M0,M1,M2,M3,M4] ordered_match=true · milestone-level owner_gate fields: NONE (0)
  r7 pins present, all null: quiet_window_ack, merge_announcement_ack, i18n_baseline_seed_commit,
                             i18n_baseline_protected_blob, pre_promotion_ci_dispatch
  p2_m2_landed_sha as a key: false
enforcement-p3.progress.yaml  PARSE OK · base_sha×1 (null) · branch (null)
  milestones [M0,M1,M2,M3] ordered_match=true · milestone-level owner_gate fields: NONE (0)
  r7 pins present, all null: p1_landed_sha, country_defaults_landed_sha, p2_landed_sha,
                             pre_promotion_ci_dispatch
  p2_m2_landed_sha as a key: false
all three line-5 headers: "# (r7 of the brief applies all 17 gate-r1 findings, round-0 R0-3/R0-4,
                             the 11 gate-r2 findings, and the 6 gate-r3 findings; re-gate before dispatch)."

--- gate-register finding counts (re-derived from the registers) ---
grep -cE '^### (C|H|M)-[0-9]+'        gate-r1 → 17   (C-1..4, H-1..11, M-1..2)
grep -cE '^### R2-(C|H|M)-[0-9]+'     gate-r2 → 11   (R2-C-1..2, R2-H-1..7, R2-M-1..2)
grep -cE '^### R3-(C|H|M)-[0-9]+'     gate-r3 →  6   (R3-C-1..2, R3-H-1..4; Minor: None)

--- table cell-count check (awk -F'|', cells = NF-2) ---
:59-63   → 4,4,4,4,4     (header 4)   OK
:94-101  → 3 ×8          (header 3)   OK
:119-126 → 2 ×8          (header 2)   OK
:142-147 → 3 ×6          (header 3)   OK

--- stale operative text sweep ---
node --test                → brief :34, :267; p2 YAML :108  (all inside the removal ruling)   CLEAN
origin/                    → brief :162 (negation sentence), :331, :381 (real origin/dev)      CLEAN
p2_m2_landed_sha           → brief :37, :46, :63; p3 YAML :17, :92  (all supersession)         CLEAN
"after the seed commit is reviewed" → brief :48 only (r7 log describing its own removal)       CLEAN
"after dispatch"/"mid-wave"/"M2-time" → all inside negations or supersession notes             CLEAN
receipt-timing "recorded … BEFORE merge" → brief :290 (P2 acceptance block)                    ** STALE → R0-1 **
                                            corroborating: brief :292-293 "the recorded run … BEFORE merging"
                                            contrast (correct): brief :205-209 (P1 acceptance block)

--- check 5 ---
grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule"
  over brief + all three YAMLs → (no output) exit 1
```

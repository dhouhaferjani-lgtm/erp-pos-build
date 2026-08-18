# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r8 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-12

Scope: the brief at r8 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15, all UNCOMMITTED on `dev`. `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca` — unchanged from the r7 run, and identical to the brief's stated verification base `a5520f23c`.

Run in FULL from the top per the spec's hard rule (a round-0 pass on rev N does not carry to rev N+1). Repo-side evidence from the r7 run was reused **only after mechanically proving the underlying files are untouched** (see the diff-scope section), and the load-bearing citations were re-derived anyway. Every doc-side line number below was re-derived at r8 offsets.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 0 | Diff-scope of the r8 edit ("No other text changed") | **PASS** | 0 |
| 1 | Revision-log truth (r8: 1 item; r7: 6 items; r3/r4/r5/r6 spot-checks) | **PASS** | 0 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable     | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 — no permission/module keys named |
| H | Hygiene (pipes, banner, header counts, stale-text sweep) | **PASS** | 0 |

**VERDICT: PASS — all rows. r8 is round-0 clean and gate-round-4 ready.** The r7 finding R0-1 is closed; no new finding. Five note-only observations at the end, none a defect.

---

## Check 0 — Diff-scope of the r8 edit (the "No other text changed" assertion)

The r8 log entry (`:50`) asserts *"No other text changed."* Because all five files are untracked (`??`), there is no git baseline to diff against, so scope was established mechanically from **structural invariants** measured against the r7 text this reviewer read in the prior run.

**Permitted change sites (per the coordinator + the r8 log entry):** the P2 acceptance-evidence block, the revision banner, the new r8 log entry, and the three YAML line-5 headers. Anything else differing = FAIL.

### Brief — line accounting closes exactly

`wc -l` = **415** (r7 = 409 content lines; the r7 Read tool reported "410 total", one higher, because it counts a final empty segment after the trailing newline — `od -c` on r8 confirms the file ends `f i x   r o u n d . \n` with **no** trailing blank line, so `wc -l` == last content line == 415). Net delta **+6**.

Section-header offsets re-derived and compared to the r7 offsets:

| Anchor | r7 | r8 | Δ |
|---|---|---|---|
| `**Revision:**` banner | 4 | 4 | 0 |
| r3 / r4 / r5 / r6 / r7 log blocks | 5 / 24 / 27 / 29 / 42 | 5 / 24 / 27 / 29 / 42 | 0 |
| **r8 log block (new)** | — | **50** | **+2** (entry + blank) |
| `**Executor:**` | 50 | 52 | +2 |
| §SEQUENCING | 55 | 57 | +2 |
| §EXECUTION MODE | 69 | 71 | +2 |
| §0 | 92 | 94 | +2 |
| §1 | 105 | 107 | +2 |
| §2 | 132 | 134 | +2 |
| §3 | 217 | 219 | +2 |
| §4 | 304 | 310 | **+6** |
| §5 | 361 | 367 | +6 |
| §6 | 385 | 391 | +6 |
| §7 | 398 | 404 | +6 |
| last content line | 409 | 415 | +6 |

Every inter-section span is therefore **identical in length** except §3, which grew by 4. Sub-anchors localize that growth to a single block:

| Sub-anchor | r7 | r8 | Δ |
|---|---|---|---|
| §2 `### Context` / `### The contract` / `### Deliverables` / `### Explicitly out of scope` / `### Acceptance evidence` / ` ```bash ` / ` ``` ` / `**Milestones` | 134 / 138 / 151 / 175 / 181 / 183 / 210 / 213 | 136 / 140 / 153 / 177 / 183 / 185 / 212 / 215 | **all +2** — §2 including the **P1 acceptance block is unchanged in length** |
| §3 `### 2(a)` / `2(b)` / `2(c)` / `2(d)` / `### Package 2 acceptance evidence` / ` ```bash ` | 225 / 236 / 249 / 259 / 269 / 271 | 227 / 238 / 251 / 261 / 271 / 273 | **all +2** — every §3 prose subsection unchanged in length |
| §3 closing ` ``` ` / `**Milestones` | 298 / 300 | 304 / 306 | **+6** |

So the **only** length change anywhere in the brief is inside the fenced P2 acceptance bash block: r7 `271→298` (28 lines) → r8 `273→304` (32 lines) = **+4**. Accounting closes: +2 (r8 log entry) + 4 (P2 block) = **+6** = the observed file delta, with **no residual**. There is no room for an unaccounted insertion or deletion anywhere else.

§7 was read in full at r8 `:404-415` (12 lines) and matches the r7 §7 (`:398-409`, 12 lines) line-for-line; the apparent "−1" that a naive `total − header` subtraction produces is entirely the Read-tool/`wc -l` counting-convention difference described above, not a deleted line.

### YAMLs — scope proven by unchanged interior offsets

`wc -l`: p1 **136**, p2 **141**, p3 **125** — identical to the r7 run. And every interior grep anchor re-derived at r8 landed on **exactly the same line number as in r7**: p1 `:37, :39, :43-45, :82, :86, :119, :127`; p2 `:28, :30, :33-34, :74, :78, :108, :116`; p3 `:15, :17-18, :25, :29, :49, :74, :92, :108`. A line-5 header replacement is one line → one line, so zero downstream shift is exactly what a scope-correct edit produces. Combined with the js-yaml structural parse (below) being byte-for-byte equivalent in key set, order and values, the YAML change is confined to line 5.

### LEDGER — untouched

`ls -lT` mtimes: brief **20:50:53**, the three YAMLs **20:50:57**, `LEDGER.md` **20:37:00** — the LEDGER predates the r8 edit window and was not written. Its S-14 row was re-read anyway and still carries the R3-C-2 five-step sequence verbatim (evidence appendix).

### Repo (non-target) files — untouched, justifying reuse of r7 code-side evidence

`git status --porcelain` over the whole tree: the only **modified tracked** files are `.claude/agents/frontend-conventions-reviewer.md`, `.claude/settings.json`, `docs/handoff/FINDINGS-other-problems-2026-08-11.md`, `docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md` — none of them a citation target of this brief. **`.github/workflows/ci.yml`, `apps/api/**`, `apps/web/**`, `scripts/**` are all clean at the unchanged HEAD**, so every Check-2 census and Check-4 citation verified in the r7 run remains valid. Load-bearing ones were re-derived regardless (below).

**Check 0: PASS.** The "No other text changed" assertion is mechanically supported.

---

## Check 1 — Revision-log truth

### The new r8 claim

| Claim (`:50`) | Required change | Verified | OK |
|---|---|---|---|
| **r8 / round-0 r7 R0-1** — "the Package 2 acceptance-evidence block still carried the superseded r6 receipt contract … Both comment runs aligned with §5 item 4: the run is verified BEFORE merging on exactly the accepted SHA (merged unchanged), the receipt recorded AFTER promotion in the admin commit." | Both flagged comment runs rewritten onto the R3-C-2 contract | **Run 1** — r7 `:290` ("recorded in pre_promotion_ci_dispatch + LEDGER §2 row S-14 BEFORE merge.") is **gone**, replaced at r8 `:291-294`: "remote CI verification is the owner's MANDATORY PRE-promotion workflow_dispatch gate (gate-r2 R2-C-2/R2-H-2; **receipt semantics gate-r3 R3-C-2**): **a green run on EXACTLY the accepted SHA, which is merged UNCHANGED — the receipt goes into pre_promotion_ci_dispatch + LEDGER §2 row S-14 in the POST-promotion admin commit.**" **Run 2** — r7 `:292-293` ("the parent verifies **the recorded run** … BEFORE merging") is **gone**, replaced at r8 `:295-299`: "the parent verifies **the run** (green, head SHA == the exact accepted candidate SHA == the SHA that will be merged unchanged, those steps/jobs executed) **BEFORE merging, then records it in pre_promotion_ci_dispatch in the POST-promotion admin commit.**" The definite article "the **recorded** run" — the phrasing that presupposed pre-merge recording — is removed. | ✓ |
| **Parity with the P1 block (the repair's own standard)** | The two acceptance blocks should now state one contract | Re-read side by side: **P1** `:207-211` "the parent verifies the run (green, head SHA == the exact accepted candidate SHA == the SHA that will be merged unchanged, the new job present and executed in the run's job list) **BEFORE merging, then records it in pre_promotion_ci_dispatch in the POST-promotion admin commit.** No such run = promotion blocked (§5; LEDGER S-14)." **P2** `:295-299` — same clause structure, same ordering, same destination. The block-by-block divergence R0-1 identified is fully closed. | ✓ |
| **"No other text changed"** | scope | Proven in Check 0 by exact line accounting (+2/+4, no residual) and unchanged YAML interior offsets. | ✓ |

### The six r7 claims — all re-verified at r8 offsets

| Claim | Re-derived location(s) at r8 | OK |
|---|---|---|
| **R3-C-1** owner-variable authority | Brief §2 3(c) **Phase 2 `:162`** (checker reads `DPA_BASELINE_PROTECTED_BLOB`, never the YAML/branch; FAIL CLOSED on unset/unfetchable/mirror-drift), **bootstrap+re-pin `:163`** (owner-authenticated, "never a candidate diff"), **event-anchoring `:164`**, tamper case 5 `:196` (`OWNER-VARIABLE-held` blob); §3 2(c) deliverable 1 `:256` (I18N, same three fail-closed conditions); **P1 YAML `:37` "NON-AUTHORITATIVE MIRROR pins"** + `:39` authority sentence + M2 title `:119`; **P2 YAML `:28`/`:30`** + M1 title `:108`; **§5 item 4 step 2 `:382`** (owner sets/updates the variables); **§6 F-8 `:400`**. All present, all content-checked. | ✓ |
| **R3-C-2** promotion SHA == accepted SHA == dispatched head; receipts post-promotion | **§5 item 4 `:380-386`** re-read in full: preamble "NOTHING committed to the package between final-gate ACCEPT and merge"; step 1 ACCEPT tip A `:381`; step 3 owner dispatch on exactly A `:383`; **step 4 `:384` "The parent merges **exactly A** to local dev — promotion SHA == accepted SHA == dispatched head"**; **step 5 `:385` "AFTER promotion, the parent records the receipts … in a POST-PROMOTION ADMIN COMMIT"**; `:386` "No green run on exactly A = PROMOTION BLOCKED". All three YAML `pre_promotion_ci_dispatch` comment blocks, the P2 `merge_announcement_ack` comment, the execution-mode block and **LEDGER S-14** all re-checked and unchanged from the r7 verification. **Plus the r8 repair site** (above) — the one operative location that previously dissented now conforms. | ✓ |
| **R3-H-1** exact `M3.commit == dpa_3c_reviewed_sha` | Sequencing-table P1 row `:63` — grep-extracted verbatim: "EQUALS \`dpa_3c_reviewed_sha\` (gate-r3 R3-H-1: exact equality, an arbitrary ancestor can never stand in for the reviewed tip)"; P1 YAML header check 3 `:18-24`; P1 M0 title `:103`; P1 owner_gate `:79`. | ✓ |
| **R3-H-2** `p2_landed_sha` whole-package proof | P3 YAML pin `:49` + header check 2b `:23-30` + M0 title `:92` + owner_gate `:74`; brief sequencing row `:65`, §3 2(b) `:249`, §4 3(b) deliverable 2 `:335`, §4 milestones `:363`. js-yaml re-parse: **`p2_m2_landed_sha` is not a key in any of the three files.** | ✓ |
| **R3-H-3** dispatch/M0 precondition; affordance deleted | P3 YAML `:15-19` ("the 'pin after dispatch' affordance is DELETED, there is NO mid-wave integration, P3 is strictly last"), owner_gate `:74`, M2 title `:108`; brief `:65`, `:249`. Fresh `after dispatch|mid-wave|M2-time` sweep → every hit inside a negation or explicit supersession note. | ✓ |
| **R3-H-4** commit-1 / commit-2 / then-review | Brief §2 3(c) **Phase 1 `:161`**; §3 2(c) deliverable 1 `:256`; P1 YAML `:43-45` (`commit 1` / `commit 2` / `ONLY THEN the M2 bridge review runs`) + M2 title `:119`; P2 YAML `:33-34` + M1 title `:108`. Fresh `"after the seed commit is reviewed"` sweep → **one hit, brief `:48`, inside the r7 log entry describing its own removal**. | ✓ |

### r3 / r4 / r5 / r6 spot-checks

All re-confirmed; the underlying repo files are provably unmodified (Check 0), and the load-bearing ones were re-derived this run: `ci.yml:3-8` event graph (raw output in the appendix), `ci.yml:1104` `needs` list containing `frontend-lint`, `StockAdjustmentService.php:1715/:1716/:1719`, `GeneralLedgerService.php:3480/:3507`, `grep -L vitest tools/__tests__/*` → empty, `test:tools` absent from `apps/web/package.json` (count 0), `failOnEmptyTestSuite` absent from `apps/api/phpunit.xml` (count 0). The r5 correction (`:876`/`:879`/`:882`) and the r4 false-conjunction repair remain grounded — `frontend-lint` still runs discrete steps and never `pnpm lint`.

**Check 1: PASS.**

---

## Check 2 — Exhaustive claims / censuses

No inventory claim in the document set was touched by the r8 edit (Check 0 proves the change is confined to the P2 acceptance block's receipt comments, the banner, the r8 log entry and three YAML header lines — none of which carries a census), and every census target file is unmodified at an unchanged HEAD. The full census table from the r7 run therefore stands; the load-bearing ones were re-run this pass:

| Inventory claim | Census re-run this pass | Documented | Re-derived | Match |
|---|---|---|---|---|
| "every current `tools/__tests__/*.mjs` imports from `vitest`" | `grep -L vitest apps/web/tools/__tests__/*` | all | **empty output, exit 1 → 6/6** | ✓ |
| `test:tools` does not exist yet (deliverable, not presence) | `grep -c 'test:tools' apps/web/package.json` | absent | **0** | ✓ |
| no `failOnEmptyTestSuite` (grounds the nonzero-selection rule) | `grep -c failOnEmptyTestSuite apps/api/phpunit.xml` | absent | **0** | ✓ |
| `frontend-lint` ∈ `all-checks-pass` `needs` | `sed -n '1104p' ci.yml` | present | **present** | ✓ |
| pgsql allowlist 93 / second allowlist 16 / `AnalyticsTest`+`ExpenseAnalyticsTest` | (r7 run; `ci.yml` unmodified) | 93 / 16 / both | **93 / 16 / both** | ✓ |
| Architecture "4 of 16" ParserFactory; zero `RefreshDatabase` | (r7 run; `tests/Architecture` unmodified) | 4 / 16; 0 | **4 / 16; 0** | ✓ |
| DPA audit "10 violations + 3 GL-gap grays" | (r7 run) | 10 + 3 | **V1–V10; G1,G2,G3** | ✓ |
| "complete 41-case partition (27+1+4+9)" | (r7 run) | 41 | **41**; 27+1+4+9 = 41 | ✓ |
| eslint-rules 3 tested / 3 untested; `test:eslint-rules` + `tools/__tests__` in no workflow; manifest-drift zero workflow refs; STATUS_RE has no `Tone`; `KeyedByRouteId` absent from `WRAPPERS`; Architecture suite in no automatic lane | (r7 run) | as stated | **as stated** | ✓ |

**Check 2: PASS.**

---

## Check 3 — Test contracts executable

The r8 edit touched only **comment lines** inside the P2 acceptance bash block — the executable command lines (`cd apps/web`, `pnpm lint`, `node tools/audit-i18n-completeness.mjs`, `pnpm test:tools`, `pnpm test:eslint-rules`, the two `grep -n … ../../.github/workflows/ci.yml`) are byte-identical at `:274-285` and still resolve correctly (`../../` from `apps/web` reaches the repo root). Every other contract is unchanged.

| Contract | Command present | Physically executable | Mock-of-subject |
|---|---|---|---|
| P1 guard green `:187` | ✓ | ✓ | none |
| P1 tamper 1–2 `:189-190` | ✓ | ✓ fixture plant/revert against the real scanner | none |
| P1 ratchet 3–4 `:192-193` | ✓ | ✓ | none |
| P1 anti-growth case 5 `:194-199` | ✓ `git cat-file blob "$DPA_BASELINE_PROTECTED_BLOB"` + explicit local env-var instruction | ✓ the variable-authority contract stays locally reproducible, incl. both fail-closed sub-cases (mirror drift, unset variable) | none |
| P1 scope + allowlist `:201-204` | ✓ | ✓ | n/a |
| P1 aggregate membership `:206` | ✓ | ✓ | n/a |
| P2 acceptance `:274-285` | ✓ | ✓ (relative path re-checked) | none |
| P3 acceptance `:341-360` | ✓ census-derived phpunit + anchored `--filter` + parsed nonzero count; `./vendor/bin/phpstan` | ✓ nonzero-count rule grounded by the re-verified absence of `failOnEmptyTestSuite` | none |
| M0 precondition commands (3 YAMLs) | ✓ `git rev-parse --verify`, `merge-base --is-ancestor`, `git show <sha>:… \| grep`, `git cat-file -e`, `git rev-parse <c>:<path>`, `git cat-file blob` | ✓ valid git syntax; the seam-grep target string still exists at `StockAdjustmentService.php:1719` (re-derived) | n/a |

No impossible interleavings, no barriers on lazily-created rows, no contract asserting through a mock of its own subject. **Check 3: PASS.**

---

## Check 4 — Behavior/repo claims cite source

Every citation target is an unmodified file at an unchanged HEAD (Check 0). The complete citation audit from the r7 run stands. Re-derived this pass:

```
ci.yml:3-8   on: push.branches [main] / pull_request.branches [main, dev] / workflow_dispatch   (nothing else)
ci.yml:1104  needs: [backend-lint, backend-analyse, backend-architecture, backend-test,
                     backend-test-pgsql, treasury-spine-pgsql, frontend-lint, frontend-typecheck, …]
StockAdjustmentService.php:1715  ?StockMovementReferenceType $referenceType = null,
StockAdjustmentService.php:1716  ?string $referenceId = null,
StockAdjustmentService.php:1719  $this->assertReferenceLinkagePaired($referenceType, $referenceId);
GeneralLedgerService.php:3480    private function sealAndPersistEntry(…): JournalEntryPosted
GeneralLedgerService.php:3507    if (bccomp($totalDebit, $totalCredit, $balanceScale) !== 0) {
```

**Conjunction re-check (house law).** The two conjunctions this brief has previously gotten wrong were re-opened on both sides: (a) *"`frontend-lint` is in `all-checks-pass` `needs`"* — job exists at `ci.yml:853`, `needs` at `:1104` names it: **edge present**; (b) *"the `frontend-lint` job runs the `package.json` lint chain"* — the r4-corrected claim: the job's run-lines are `audit:keys` `:876`, `audit:design-system` `:879`, `audit:quantity` `:882`, `lint:ratchet` `:890`, with `pnpm lint` appearing nowhere in the job and the `:885` comment stating it is superseded: **edge correctly denied**. (c) New this round: *"the P2 acceptance block now states the §5 item 4 contract"* — both sides opened (`:295-299` vs `:380-386`) and the semantics match clause for clause.

The full verified set (unchanged): `ci.yml` :3-8, :27, :105, :143-178, :180-185, :226-230, :275, :284-293, :294, :629, :726, :740-747, :833-851, :844-848, :853-891, :876/:879/:882/:885/:890, :893, :918-922, :977-981, :1012, :1090-1103/:1090-1107/:1103/:1104 · `StockAdjustmentService.php` :1700, :1715-1716, :1719, :1574-1590, :1838-1863 · `BatchStockService.php` :88-105/:322-335 · `GeneralLedgerService.php` :2874, :2879, :3450, :3480-3510, :4072 · `TreasuryReceiptBridge` :473/:547/:1387, :446/:528 · `InstrumentLifecycleService` :274/:456/:488/:847 · `ChartOfAccountsService.php:51` · `SystemAccountPurpose::expectedAccountType()` :187 (vs the brief's hedged "~186") · `phpunit.xml:17-18` · `apps/web/package.json` :10/:12 · `i18n.ts` :392-405/:416-424/:429-430/:435-442/:440/:442 · `setup.ts:2` · `audit-quantity-display.mjs` :342/:418-442/:449-461 · `audit-design-system.mjs:59-62` · `gen-route-manifest.mjs:31-34` · `preflight.sh:193-195` · `SELF-REVIEW-HARNESS.md:49-50` and :66-75 · `adversarial-review.sh:49-64` · sweep audit :136-139 (verbatim) · `wave3-3c-3d.progress.yaml:49-56` · `CODEX-DISPATCH-wave3-3c-3d:612-617` · `CODEX-DISPATCH-ui-wave0:240-247` · `ui-wave0.progress.yaml` M4 :64-65 · country brief :57-66/:289-318/:314-318 · `country-defaults-phase-a.progress.yaml` :33-40/:81-88 · `HANDOVER-openapi-lane:32-36` · openapi plan :9-16/:21-26 · `PLAN-p0…:53` · `AGENTS.md:16` · `LEDGER.md` :56/:57 · all six `.claude/agents/*-reviewer.md`.

**Check 4: PASS.**

---

## Check 5 — Permission keys

```
$ grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule" \
    docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md \
    docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml
(no output)   exit 1
```
No permission or module keys are named anywhere in the document set, so the `canAccessModule` fail-open trap cannot apply. **N/A — PASS.**

---

## Hygiene

- **Banner ↔ latest revision-log entry.** Banner `:4` = "**Revision:** r8 — 2026-08-12, round-0 r7 R0-1 fix applied on top of the full gate-r3 fix round (r7: …) … **NOT yet re-gated**. Re-run round 0 from the top, then gate round 4, before dispatch." Revision-block anchors re-derived: `:4` banner, `:5` (r1/r2/r3), `:24` r4, `:27` r5, `:29` r6, `:42` r7, **`:50` r8** — the r8 block is genuinely last. Banner rev, date and the log tail agree, and the banner honestly still demands round 0 + gate round 4 before dispatch. ✓
- **YAML line-5 header comments.** All three now read identically: `# (r8 of the brief applies all 17 gate-r1 findings, round-0 R0-3/R0-4 + r7-R0-1, the 11 gate-r2 findings, and the 6 gate-r3 findings; re-gate before dispatch).` The rev reference is r8 ✓ and the new `+ r7-R0-1` term honestly records this round's fix. Finding counts re-derived from the registers themselves (not from the brief): gate-r1 `grep -cE '^### (C|H|M)-[0-9]+'` → **17** (4C+11H+2M); gate-r2 `'^### R2-…'` → **11** (2C+7H+2M); gate-r3 `'^### R3-…'` → **6** (2C+4H+**0M** — the register states "Minor findings: None"). All three counts correct. ✓
- **Unescaped GFM pipes.** Four normative tables re-checked by `awk -F'|'` at r8 offsets — sequencing `:61-65` (header 4, all rows 4), read-order `:96-103` (3), DO-NOT-TOUCH `:121-128` (2), write-surface contract `:144-149` (3). Every row matches its header; no cell loses a column. ✓
- **Stale operative-text sweep (fresh, full document set).**
  - `node --test` → 3 hits (brief `:34`, `:269`; p2 YAML `:108`) — all inside the R2-H-3 removal ruling / deliverable-4 text mandating the removal. Zero operative uses. ✓
  - `origin/` → 3 hits (brief `:164` inside the negation "there is no `origin/<base>`/branch ref anywhere in the mechanism"; `:337`, `:387` referring to the real `origin/dev` staging auto-deploy). ✓
  - `p2_m2_landed_sha` → 5 hits (brief `:37`, `:46`, `:65`; p3 YAML `:17`, `:92`) — all historical log or explicit supersession; not a YAML key anywhere. ✓
  - "after the seed commit is reviewed" → 1 hit (brief `:48`, the r7 log describing its own removal). ✓
  - "after dispatch" / "mid-wave" / "M2-time" → all inside negations or supersession notes. ✓
  - **Receipt-timing sweep (the r8 target), two passes.** Broad pass over brief + all three YAMLs for `BEFORE merge|before merge|BEFORE the parent merges|BEFORE merging|recorded run|records it|recorded in pre_promotion|records the receipt` → 12 hits, each read in context: brief `:31` (r6 log), `:33` (r6 log), `:44` (r7 log), `:50` (r8 log, quoting the removed string) = historical; brief `:210`, `:298`, `:385` = the correct "verify before merging, record after promotion" contract; p1 YAML `:82`, `:127`, p2 YAML `:74`, p3 YAML `:78` = the same correct contract (the "before the parent merges" there qualifies **the run**, not the recording); brief `:167` = "whose recorded run must show THIS job executed" — a property of the run, with no timing assertion (see note 5). Targeted pass for the dangerous conjunction `(record(ed|s)?)[^.]{0,120}(BEFORE|before) (merge|merging|the parent merges)` → **exactly 2 hits, brief `:31` and `:50`, both inside historical revision-log entries.** **Zero operative survivors.** ✓
- **YAML validity / shape (js-yaml, run from `apps/web`).** All three parse. Each has exactly one `base_sha` and one `branch`; milestones ordered and complete (P1 `[M0,M1,M2,M3]`, P2 `[M0,M1,M2,M3,M4]`, P3 `[M0,M1,M2,M3]`); **milestone-level `owner_gate:` fields = 0 / 0 / 0**; every r7 pin present and `null`, none missing, none pre-filled; `p2_m2_landed_sha` absent as a key. Identical to the r7 parse except the line-5 string. ✓

**Hygiene: PASS.**

---

## Note-only observations (not findings; carried for the gate reviewer)

1. **`TreasuryReceiptBridge` directory.** It lives under `apps/api/app/Modules/Treasury/Application/**Projections**/`, not `Application/Services/`. The brief cites only line numbers for it (all of which verify), so no citation is wrong — the reviewer just should not assume a `Services/` path.
2. **Arabic-authored entries inside the cited alias ranges.** `i18n.ts:392-405` / `:416-424` interleave a few genuinely Arabic-authored entries (`menu: arMenu`, `'parts-catalog': arPartsCatalog`, `channels: arChannels`, `reports: arReports`) among the English aliases. The brief's claim — that these ranges are *where* whole-namespace English aliasing for `ar` occurs — is correct, and the authored-provenance mechanism distinguishes them by construction.
3. **`wave3-3c-3d.progress.yaml` M3 is still `status: pending` / `commit: null` / `verdict: null`.** Consistent with the brief, which treats the ACCEPT state as a dispatch-time P1-M0 precondition, not a present-tense claim.
4. **`SystemAccountPurpose::expectedAccountType()` is at `:187`; the brief writes `:~186`.** The tilde is an explicit approximation marker and the docblock occupies `:184-186` — within tolerance.
5. **`:167` "whose recorded run must show THIS job executed."** Considered and cleared during the receipt-timing sweep: it names a required property of the run that later gets recorded and asserts nothing about recording-vs-merge ordering, unlike the r7 `:292-293` phrasing (which paired "the recorded run" *with* "BEFORE merging" in the verification clause and is now gone). No action needed; flagged only so the gate reviewer does not re-litigate it.
6. **`§3 2(c)` calls the YAML pins "the YAML mirror pins"** where `§2 3(c)` and both YAML comment blocks say "NON-AUTHORITATIVE MIRROR". Unambiguous in context (the same sentence says the checker reads the variable "never the YAML"). Cosmetic only.

---

## Evidence appendix (raw outputs)

```
$ git rev-parse HEAD
a5520f23ca39209f5b517723037e9516808f2bca            (unchanged from the r7 run; == brief base a5520f23c)

$ wc -l <targets>
415 docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md      (r7: 409 content lines → +6)
136 docs/handoff/progress/enforcement-p1.progress.yaml                (r7: 136 → 0)
141 docs/handoff/progress/enforcement-p2.progress.yaml                (r7: 141 → 0)
125 docs/handoff/progress/enforcement-p3.progress.yaml                (r7: 125 → 0)
105 docs/handoff/LEDGER.md

$ ls -lT  (mtimes — LEDGER predates the r8 edit window)
Aug 12 20:50:53  CODEX-DISPATCH-enforcement-guards-2026-08-12.md
Aug 12 20:50:57  enforcement-p{1,2,3}.progress.yaml
Aug 12 20:37:00  LEDGER.md

$ tail -c 60 <brief> | od -c   (trailing-newline convention)
… f i x   r o u n d .  \n            → no trailing blank line; wc -l == last content line

$ git status --porcelain   (tracked modifications — none is a citation target)
 M .claude/agents/frontend-conventions-reviewer.md
 M .claude/settings.json
 M docs/handoff/FINDINGS-other-problems-2026-08-11.md
 M docs/handoff/OWNER-DECISIONS-ui-audit-2026-08-10.md
   (ci.yml, apps/api/**, apps/web/**, scripts/** all clean)

--- brief section offsets (r7 → r8) ---
banner 4→4 · r3/r4/r5/r6/r7 logs 5/24/27/29/42 unchanged · r8 log NEW at 50
Executor 50→52 · SEQUENCING 55→57 · EXEC-MODE 69→71 · §0 92→94 · §1 105→107 · §2 132→134
§3 217→219 · §4 304→310 · §5 361→367 · §6 385→391 · §7 398→404 · last line 409→415
§2 sub-anchors 134/138/151/175/181/183/210/213 → 136/140/153/177/183/185/212/215   (all +2)
§3 sub-anchors 225/236/249/259/269/271 → 227/238/251/261/271/273                   (all +2)
§3 close/milestones 298/300 → 304/306                                              (+6)
⇒ sole length change = P2 acceptance bash block 28→32 lines (+4); +2 (r8 log) + 4 = +6 = file delta

--- YAML interior offsets: IDENTICAL to r7 ---
p1 37,39,43-45,82,86,119,127 · p2 28,30,33-34,74,78,108,116 · p3 15,17-18,25,29,49,74,92,108
⇒ line-5 replacement only; zero downstream shift

--- the r8 repair, verbatim (brief :291-299) ---
291 #   H-7); remote CI verification is the owner's MANDATORY PRE-promotion workflow_dispatch gate
292 #   (gate-r2 R2-C-2/R2-H-2; receipt semantics gate-r3 R3-C-2): a green run on EXACTLY the accepted SHA,
293 #   which is merged UNCHANGED — the receipt goes into pre_promotion_ci_dispatch + LEDGER §2 row S-14
294 #   in the POST-promotion admin commit.
295 # Event-graph acceptance check (gate-r2 R2-C-2; receipt semantics gate-r3 R3-C-2): the handback NAMES
296 #   the frontend-lint step ids / any new job id the owner's pre-promotion workflow_dispatch run must
297 #   show executed; the parent verifies the run (green, head SHA == the exact accepted candidate SHA ==
298 #   the SHA that will be merged unchanged, those steps/jobs executed) BEFORE merging, then records it
299 #   in pre_promotion_ci_dispatch in the POST-promotion admin commit.

--- P1 counterpart for parity (brief :207-211) ---
209 #   verifies the run (green, head SHA == the exact accepted candidate SHA == the SHA that will be merged
210 #   unchanged, the new job present and executed in the run's job list) BEFORE merging, then records it in
211 #   pre_promotion_ci_dispatch in the POST-promotion admin commit. No such run = promotion blocked.

--- §5 item 4 promotion sequence (brief :380-386) ---
381  1. Final gate ACCEPTs candidate tip A.
382  2. owner sets/updates DPA_BASELINE_PROTECTED_BLOB / I18N_BASELINE_PROTECTED_BLOB …
383  3. owner pushes exactly A to a throwaway ref + workflow_dispatch; head == A, GREEN, jobs executed
384  4. The parent merges exactly A to local dev — promotion SHA == accepted SHA == dispatched head.
385  5. AFTER promotion, the parent records the receipts … in a POST-PROMOTION ADMIN COMMIT
386  No green run on exactly A = PROMOTION BLOCKED — audited via LEDGER §2 row S-14

--- LEDGER S-14 (:56), unchanged ---
(4) the parent merges EXACTLY A — promotion SHA == accepted SHA == dispatched head;
(5) AFTER promotion the parent records run URL + result into `pre_promotion_ci_dispatch` (and
    `merge_announcement_ack` for P2) in a POST-promotion admin commit touching only
    docs/handoff/progress/*.progress.yaml + this LEDGER (admin-only delta, a5520f23c).
**No green run on exactly A = PROMOTION BLOCKED.**

--- receipt-timing dangerous-conjunction sweep ---
grep -nE '(record(ed|s)?)[^.]{0,120}(BEFORE|before) (merge|merging|the parent merges)' brief + 3 YAMLs + LEDGER
  → brief:31  (r6 revision-log entry — historical)
  → brief:50  (r8 revision-log entry quoting the string it removed — historical)
  ZERO operative survivors

--- stale sweeps ---
node --test                          → brief :34, :269; p2 YAML :108        (removal ruling only)
origin/                              → brief :164 (negation), :337, :387 (real origin/dev)
p2_m2_landed_sha                     → brief :37, :46, :65; p3 :17, :92     (historical/supersession)
"after the seed commit is reviewed"  → brief :48                            (r7 log only)
after dispatch|mid-wave|M2-time      → all inside negations/supersession notes

--- js-yaml parse (from apps/web) ---
p1 PARSE OK · base_sha×1 null · branch null · milestones [M0,M1,M2,M3] ordered · owner_gate fields 0
   pins: dpa_3c_merge_sha, dpa_3c_reviewed_sha, dpa_baseline_seed_commit,
         dpa_baseline_protected_blob, pre_promotion_ci_dispatch — all present, all null
p2 PARSE OK · milestones [M0,M1,M2,M3,M4] ordered · owner_gate fields 0
   pins: quiet_window_ack, merge_announcement_ack, i18n_baseline_seed_commit,
         i18n_baseline_protected_blob, pre_promotion_ci_dispatch — all present, all null
p3 PARSE OK · milestones [M0,M1,M2,M3] ordered · owner_gate fields 0
   pins: p1_landed_sha, country_defaults_landed_sha, p2_landed_sha,
         pre_promotion_ci_dispatch — all present, all null
p2_m2_landed_sha as a key: false (all three)
line 5 (all three): "# (r8 of the brief applies all 17 gate-r1 findings, round-0 R0-3/R0-4 + r7-R0-1,
                       the 11 gate-r2 findings, and the 6 gate-r3 findings; re-gate before dispatch)."

--- gate-register finding counts (re-derived from the registers) ---
gate-r1 → 17 (C-1..4, H-1..11, M-1..2) · gate-r2 → 11 (2C+7H+2M) · gate-r3 → 6 (2C+4H+0M)

--- table cell counts (awk -F'|', cells = NF-2) ---
:61-65 → 4×5 (header 4) · :96-103 → 3×8 · :121-128 → 2×8 · :144-149 → 3×6      all consistent

--- code citations re-derived this pass (files unmodified at unchanged HEAD) ---
ci.yml:3-8    push.branches [main] / pull_request.branches [main, dev] / workflow_dispatch
ci.yml:1104   needs: [… frontend-lint …]
StockAdjustmentService.php:1715/:1716/:1719   ?StockMovementReferenceType / ?string $referenceId /
                                               assertReferenceLinkagePaired(...)
GeneralLedgerService.php:3480/:3507            sealAndPersistEntry(...) / bccomp(...) !== 0
grep -L vitest apps/web/tools/__tests__/*      → empty (exit 1)
grep -c 'test:tools' apps/web/package.json     → 0
grep -c failOnEmptyTestSuite apps/api/phpunit.xml → 0

--- check 5 ---
grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule"
  over brief + all three YAMLs → (no output) exit 1
```

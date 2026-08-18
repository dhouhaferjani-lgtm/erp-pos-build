# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r11 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-13

Scope: the brief at r11 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15, all UNCOMMITTED on `dev`. `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca` — unchanged across every round-0 run in this series and identical to the brief's stated verification base.

Run in FULL from the top per the spec's hard rule. Repo-side evidence reused only after re-proving the sources unmodified; all load-bearing citations re-derived this pass. Every doc-side line number re-derived at r11 offsets — nothing copied from any prior report or gate register.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 1 | Revision-log truth (r11: 7 gate-r5 claims; r10/r9 + earlier spot-checks) | **FAIL** | 1 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable     | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 — no permission/module keys named |
| H | Hygiene (pipes, banner, header counts, YAML shape, stale sweeps) | **PASS** | 0 — the one stale-text hit is reported as R0-1 under Check 1, not double-counted |

**VERDICT: FAIL — one FAIL row (Check 1). r11 does NOT pass round 0; do not dispatch gate round 6 until R0-1 is fixed and round 0 is re-run from the top.**

Six of the seven r11 claims are fully and correctly applied, including all three Criticals. The seventh (**R5-H-3**) is applied to the brief and the LEDGER but **added alongside rather than replacing the superseded broad allowlist in all three YAMLs**, leaving each progress file stating two mutually inconsistent versions of the same rule — the permissive one being exactly the text R5-H-3 was raised to remove.

---

## Findings

- **[R0-1] Check 1 · HIGH — R5-H-3's narrowed closing allowlist was appended to, not substituted into, the three YAMLs; each now contradicts itself.**

  The r11 log (`:69`) claims the closing check now *"rejects every path outside the CURRENT package's specific closing set … any OTHER path (including ANOTHER package's evidence files) = closing FAIL."* The brief and LEDGER carry that correctly:
  - **brief `:420`** — *"every path in `git diff --name-only <A>..<admin-tip>` is inside the CURRENT package's closing set — its own `docs/handoff/progress/enforcement-<pkg>.progress.yaml` · its own `docs/handoff/reviews/enforcement-<pkg>/**` · its own `docs/handoff/HANDBACK-enforcement-<pkg>-*.md` · `docs/handoff/LEDGER.md`. Any other path — including ANOTHER package's evidence — = closing FAIL"* ✓
  - **LEDGER S-14** — *"closing set only (its own progress YAML · its own `docs/handoff/reviews/enforcement-<pkg>/**` · its own `HANDBACK-enforcement-<pkg>-*.md` · this LEDGER) — another package's evidence or any production/workflow path = closing FAIL (gate-r5 R5-H-3), output recorded in the receipt"* ✓

  But **all three YAMLs retain the superseded r10 wildcard allowlist, unqualified, immediately above the new note**:
  ```
  enforcement-p1.progress.yaml:77-79
    # register(s)/handback/final YAML status (gate-r4 R4-C-1) — diff ⊆ the admin allowlist:
    # docs/handoff/progress/*.progress.yaml · docs/handoff/LEDGER.md · docs/handoff/reviews/enforcement-p{1,2,3}/**
    # · docs/handoff/HANDBACK-enforcement-*.md; NO production/workflow path (admin-only-delta, a5520f23c).
  enforcement-p2.progress.yaml:63-66
    # … (gate-r4 R4-C-1) —
    # diff ⊆ the admin allowlist: docs/handoff/progress/*.progress.yaml · docs/handoff/LEDGER.md ·
    # docs/handoff/reviews/enforcement-p{1,2,3}/** · docs/handoff/HANDBACK-enforcement-*.md; NO
    # production/workflow path. …
  enforcement-p3.progress.yaml:75-76
    # YAML status (gate-r4 R4-C-1; diff ⊆ the admin allowlist incl. docs/handoff/reviews/enforcement-p{1,2,3}/**
    # + HANDBACK files; NO production/workflow path). …
  ```
  …while the r11 universal note four lines later (p1 `:84-86`, p2 `:71-73`, p3 `:81-83`) states the *opposite* scope: *"the named package-specific closing check (`git rev-list --count <A>..<admin-tip>` == 1; **diff paths only in THIS package's closing set**) apply to EVERY package."*

  **Why this is mechanical, not cosmetic.** The two sentences are both operative, both unqualified, and disagree on the decisive question. `docs/handoff/reviews/enforcement-p{1,2,3}/**` and `HANDBACK-enforcement-*.md` **admit every package's evidence**; `progress/*.progress.yaml` admits every package's progress file. A parent closing P2 who follows p2 `:63-66` passes a diff that also rewrites P1's landed register or YAML — which is verbatim R5-H-3's failure scenario ("while closing P2, the shared worktree also contains an edit to P1's final register … the path-only subset passes"). The brief itself designates the YAML as the operative file ("Read yours first … It is the resume point if you crash"), so the permissive text is not harmless background.

  Note the r11 edit *did* delete the r10 tag-deletion affordance cleanly (see R5-C-2 below), proving supersession-by-replacement was the intended technique here and was simply not applied to this sentence.

  **Repair:** delete the wildcard allowlist sentence from all three YAML `pre_promotion_ci_dispatch` comment blocks (p1 `:77-79`, p2 `:63-66`, p3 `:75-76`), or rewrite it in the per-package form used at brief `:420` / LEDGER S-14. Then bump the revision log honestly and re-run round 0 from the top.

No other finding.

---

## Check 1 — Revision-log truth

### The seven r11 claims, verified in operative text (brief + YAMLs + LEDGER)

| Claim | Required change | Re-derived verification | OK |
|---|---|---|---|
| **R5-C-1** ANNOTATED CLOSING TAG binding | parent digests exact bridge output at ACCEPT (step 1); owner pushes annotated tag at A with `{A, sha256(register(s)), sha256(handback)}` (step 3); registers never edited after digest; P3-M0 1d/2d verify annotation-A == landed tip + sha256(landed register) == digest for BOTH P1 and P2; also P3 M0 title items (1)/(2), sequencing-table P3 row, §4 milestones line | **Step 1 (`:416`)** — *"Immediately, the PARENT computes and records `sha256` digests of the EXACT bridge-output register file(s) and the handback (gate-r5 R5-C-1) — before anything edits them. Registers are never edited after digest."* **Step 3 (`:418`)** — *"The owner pushes the package's pre-allocated ANNOTATED closing pin tag at exactly A … annotation recording `{A, sha256(final register(s)), sha256(handback)}` … and verifies the tag resolves to A."* **Step 5 (`:420`)** — the closing commit lands *"the digested final register(s) and handback (exact bytes from step 1)"*. **P3-M0 header check 1d (`p3:29-35`)** — fetch P1's closing tag (name from `dpa_baseline_pin_tag` at base), read annotation, *"assert annotation A == the landed accepted tip AND sha256 of the LANDED register file == the annotation's register digest"* + the exactly-one-conforming-closing-commit clause. **2d (`p3:44-46`)** — *"EVIDENCE BINDING, same shape as 1d … P2's closing tag annotation (`i18n_baseline_pin_tag`)"*. **P3 M0 title (`p3:120`)**, **sequencing-table P3 row (`:85`)**, **§4 milestones (`:397`)** all carry `sha256`/annotation language. **F-8 (`:436`)** repeats the annotation binding. | ✓ |
| **R5-C-2** tag lifecycle | name PRE-ALLOCATED in metadata commit 2, non-null + gate-reviewed in A, allocation `ci-pin/enforcement-<pkg>-r<n>`, n = 1 + highest, never reuse — in §2 3(c) Phase 1, §3 2(c), both YAML `*_pin_tag` comments and both M2/M1 titles; owner creates exactly that tag at A and verifies resolution BEFORE variable/dispatch (§5 step 3); **tags NEVER deleted, r10 affordance revoked** | **§2 3(c) Phase 1 (`:181`)** — commit 2 records *"the PRE-ALLOCATED closing pin-tag name (gate-r5 R5-C-2 … the tag name must be NON-NULL in the accepted candidate)"* alongside `dpa_baseline_seed_commit`/`dpa_baseline_protected_blob`/`dpa_baseline_pin_tag`. **§3 2(c) (`:284`)** — *"pre-allocated in the metadata commit, NEVER deleted; gate-r4 R4-H-2, gate-r5 R5-C-2"*. **P1 YAML `:51-58`** — full allocation rule (`ci-pin/enforcement-p1-r<n>`, n = 1 + highest existing, collision = next n, never reuse), non-null + gate-reviewed, owner creates the ANNOTATED tag at A, *"tags are NEVER deleted"*, CI fetches explicitly. **P2 YAML `:44-45`** — same shape. Both **M2/M1 titles** carry `PRE-ALLOCATED` (grep count 2 in each file). **§5 step 3 (`:418`)** — creates exactly that tag, verifies it resolves to A, **before** step 2's variable work is validated and before dispatch; *"**Pin tags are NEVER deleted.**"*; *"The throwaway ref may be deleted after the run; the tag stays forever."* **Revocation sweep** (`retained until\|until a permanent remote branch\|may be deleted only AFTER\|delete the tag\|tag may be deleted\|deleting a tag\|tag deletion`) → **2 hits, brief `:56` and `:65`, both revision-log entries** (the r9 entry describing the old rule, the r11 entry announcing its revocation). **Zero operative survivors.** | ✓ |
| **R5-C-3** race | parent performs NO local-dev merge of ANY lane during steps 0–5; step 4a repeats the freshness assert immediately before the merge, failure = re-gate trigger — §5 item 4, universal note in all three YAML pre-promotion blocks, LEDGER S-14 | **§5 item 4 header (`:414`)** — *"**Serialization (gate-r5 R5-C-3): the parent performs NO local-dev merge of ANY lane between a package's step 0 and step 5** — all local-dev merges go through the parent (single writer), so the critical section is enforceable by the one actor holding the pen."* **Step 4 / 4a (`:419`)** — *"**Step 4a — freshness RE-assert immediately before the merge**: repeat `git merge-base --is-ancestor <current-dev-tip> A`; failure (or a fast-forward refusal) → re-gate protocol."* **Re-gate triggers (`:421`)** include *"step-0 or step-4a failure"*. All three YAML universal notes (p1 `:81-83`, p2 `:68-70`, p3 `:78-80`) carry the single-writer serialization + step-4a re-assert. **LEDGER S-14** carries both. | ✓ |
| **R5-H-1** trust-model ack | new pin `ratchet_trust_model_ack` in P1+P2 with comment; non-null an M0 precondition in BOTH M0 titles (P1 item (6), P2 item (5)); F-8 references it | Field present and null: **`enforcement-p1.progress.yaml:103`**, **`enforcement-p2.progress.yaml:91`** — both confirmed real top-level keys by the js-yaml parse. **P1 M0 title** ends *"(5) commit_series non-null; **(6) `ratchet_trust_model_ack` non-null** (gate-r5 R5-H-1 — the F-8 principal enumeration + non-authorship assertion is an M0 artifact, not prose)."* **P2 M0 title** ends *"(4) fresh worktree/branch off base_sha; **(5) `ratchet_trust_model_ack` non-null** (gate-r5 R5-H-1 …)."* **F-8 (`:436`)** — *"**The trust assertion is operative (gate-r5 R5-H-1):** the parent-owned `ratchet_trust_model_ack` pin in the P1/P2 YAMLs holds the dated principal enumeration + non-authorship assertion; non-null is a P1/P2 M0 precondition, re-confirmed at promotion if access changed."* | ✓ |
| **R5-H-2** fail-closed per side effect | preflight tag-name free BEFORE variable mutation; verify read-back; broadened re-gate triggers; restore last known-good; retry-same-A or re-gate; announcement re-sent whenever A changes — §5 step 2 + re-gate paragraph + §2 Bootstrap line + LEDGER S-14 | **Step 2 (`:417`)** — *"**Preflight first (gate-r5 R5-H-2): verify the PRE-ALLOCATED tag name recorded in A's mirror block is still free on the remote.** Then the owner sets/updates the repository variable(s) … and **VERIFIES the read-back value equals it** (a wrong value is re-set even when the seed did not change)."* **Re-gate paragraph (`:421`)** — triggers now *"step-0 or step-4a failure, a RED dispatch run, or ANY external side-effect failure (tag collision / tag push failure / tag not resolving to A / variable read-back mismatch / ref-push or dispatch API failure / announcement failure)"*; handling = *"STOP promotion, restore the last known-good variable value where possible, record the partial state in LEDGER S-14, then either RETRY the SAME unchanged A from step 2 … or return the package to an IN-PROGRESS final milestone … re-send the P2 announcement for A′ (re-send whenever A changes), re-verify the variable, fresh dispatch, restart from step 0."* **§2 Bootstrap (`:183`)** and **F-8 (`:436`)** carry the preflight-set-verify-tag ordering. **LEDGER S-14** carries the broadened triggers. | ✓ |
| **R5-H-3** named closing check | `git rev-list --count <A>..<admin-tip>` == 1 AND diff paths only in the CURRENT package's closing set; cross-package = FAIL; output recorded in receipt — §5 step 5, LEDGER S-14, the YAML universal notes; P3-M0 1d/2d carry the exactly-one-conforming-closing-commit check | **§5 step 5 (`:420`)** ✓ verbatim narrowed. **LEDGER S-14** ✓ narrowed ("closing set only (its own …) — another package's evidence … = closing FAIL … output recorded in the receipt"). **YAML universal notes** ✓ present (p1 `:84-86`, p2 `:71-73`, p3 `:81-83`). **P3-M0 1d/2d** ✓ carry the exactly-one-closing-commit + package-specific-diff clause. **BUT the superseded wildcard allowlist survives unqualified in all three YAMLs (p1 `:77-79`, p2 `:63-66`, p3 `:75-76`), contradicting the note four lines below.** | **✗ — R0-1** |
| **R5-H-4** universality | steps 0/1/4/4a/5 + serialization + closing tag + re-gate UNIVERSAL for every package; `workflow_dispatch` leg conditional on `.github/workflows/**`; P3's closing tag allocated at promotion (no CI consumer) per its YAML note — §5 item 4 header, universal notes, LEDGER S-14 | **§5 item 4 header (`:414`)** — *"**THE PROMOTION SEQUENCE — UNIVERSAL for EVERY package (gate-r5 R5-H-4)**"*. **Step 2 (`:417`)** scoped *"(Ratchet packages P1/P2 only.)"*. **Step 3 (`:418`)** — the tag is pushed unconditionally; *"(Workflow-touching packages only:)"* gates the throwaway ref + `workflow_dispatch`; *"the closing tag is still created either way"* (log `:70`). **Step 5 (`:420`)** — receipts *"(`pre_promotion_ci_dispatch` where a dispatch ran; `merge_announcement_ack` for P2)"*. **`:422`** — *"**For workflow-touching packages**, no green run on exactly the promoted SHA = PROMOTION BLOCKED"*. All three YAML universal notes end *"the workflow_dispatch leg applies only when the accepted branch touches `.github/workflows/**`"*. **P3 YAML `:84-85`** — *"P3's own closing tag name is allocated at promotion (no CI consumer fetches it — unlike P1/P2 there is no baseline checker) and recorded in the closing receipt (gate-r5 R5-H-4)."* | ✓ |

### Earlier-revision spot-checks (re-derived at r11 offsets)

- **r10 / r9 items** — P1-M3 still carries the LOCAL AUTHORITY SETUP clause; "OWNER-performed" (not "-authenticated") at the §2 Bootstrap line; P1 pin block still reads "no push, no credentials". ✓
- **r8 receipt timing** — `record*+BEFORE-merge` conjunction sweep → **2 hits, brief `:31` and `:50`, both revision-log entries**; both acceptance blocks unchanged and correct. ✓
- **r7 / R3-C-1..H-4, r3–r6** — NON-AUTHORITATIVE MIRROR framing, exact-A promotion, `M3.commit == dpa_3c_reviewed_sha` equality, `p2_landed_sha` whole-package proof, two-step seed topology, the frontend-lint discrete-step census and the true event graph all re-verified. `p2_m2_landed_sha` → brief `:37`/`:46`/`:85` + p3 `:17`/`:120`, all historical or supersession; `node --test` → brief `:34`/`:297` + p2 `:139`, all inside the R2-H-3 removal ruling. ✓

---

## Check 2 — Exhaustive claims / censuses (re-run)

`git status --porcelain apps/api apps/web .github scripts` → only untracked `scripts/dev-scan-stack.sh` (not a citation target); **no tracked modification** at an unchanged HEAD. r11 introduced no new inventory claim (its content is promotion protocol). Censuses re-run:

| Inventory claim | Census | Documented | Re-derived | Match |
|---|---|---|---|---|
| pgsql allowlist "93 class names" (`ci.yml:629`) | `tr '\|' '\n' \| grep -c Test` | 93 | **93** | ✓ |
| second allowlist "16-entry" (`:726`) | same | 16 | **16** | ✓ |
| Architecture ParserFactory "4 of 16" | `grep -l` / `ls` | 4 / 16 | **4 / 16** | ✓ |
| "complete 41-case partition (27+1+4+9)" | `grep -cE '^    case '` | 41 | **41** | ✓ |
| "every current `tools/__tests__/*.mjs` imports from `vitest`" | `grep -L vitest \| wc -l` | all | **0 non-vitest → 6/6** | ✓ |
| `test:tools` absent (deliverable) | `grep -c` | absent | **0** | ✓ |
| no `failOnEmptyTestSuite` | `grep -c` | absent | **0** | ✓ |
| `test:eslint-rules` + `tools/__tests__` in NO workflow | `grep -rn … .github/workflows/` | exit 1 | **exit 1** | ✓ |
| `frontend-lint` ∈ `all-checks-pass` `needs` (`:1104`) | `sed -n '1104p'` | present | **present** | ✓ |
| DPA "10 + 3 grays"; eslint-rules 3/3; manifest-drift zero refs; STATUS_RE no `Tone`; `KeyedByRouteId` absent; Architecture suite in no automatic lane | prior runs, sources unmodified | as stated | as stated | ✓ |

**PASS.**

---

## Check 3 — Test contracts executable

r11 adds promotion-side commands, all mechanically runnable as written; the executor-side acceptance blocks are unchanged.

| Contract | Command present | Physically executable | Mock-of-subject |
|---|---|---|---|
| P1 / P2 LOCAL AUTHORITY SETUP + acceptance (`:207-214`, `:303-308`) | ✓ | ✓ `env -u` subshell preserves the outer export | none |
| P1 tamper 1–5, scope/allowlist, aggregate grep | ✓ | ✓ | none |
| P3 acceptance (census-derived `--filter` + nonzero count, phpstan) | ✓ | ✓ nonzero rule grounded by the re-verified absence of `failOnEmptyTestSuite` | none |
| **Promotion step 1 digest (`:416`)** | ✓ `sha256` over named files | ✓ ordinary digest over on-disk bridge output | n/a |
| **Step 2 preflight + read-back (`:417`)** | ✓ tag-name availability check, then variable set + read-back compare | ✓ both expressible via `git ls-remote --tags` / `gh variable` read | n/a |
| **Step 3 annotated tag (`:418`)** | ✓ annotated tag at A with the `{A, digests}` annotation + resolution check | ✓ `git tag -a` annotation body is arbitrary text; `git rev-list -n1 <tag>` verifies target | n/a |
| **Step 4a re-assert (`:419`)** | ✓ `git merge-base --is-ancestor <current-dev-tip> A` | ✓ | n/a |
| **Step 5 named closing check (`:420`)** | ✓ `git rev-list --count <A>..<admin-tip>` == 1 AND `git diff --name-only <A>..<admin-tip>` path test | ✓ both are plain plumbing; the path test is decidable **once the allowlist is unambiguous** — today two conflicting allowlists are stated in the YAMLs (R0-1), which is a specification defect, not an executability one | n/a |
| **P3-M0 1d/2d binding checks (`p3:29-46`)** | ✓ fetch tag, read annotation, `sha256` compare, chain-shape check | ✓ all standard git + digest operations | n/a |
| M0 preconditions (3 YAMLs) | ✓ | ✓ seam-grep target still at `StockAdjustmentService.php:1719` | n/a |

No impossible interleavings, no barriers on lazily-created rows, no contract asserting through a mock of its subject. **PASS.**

---

## Check 4 — Behavior/repo claims cite source

**The load-bearing new claim, re-derived in code.** The r11 log (`:64`) and step 1 (`:416`) assert the bridge writes the register *after* A exists, which is what forces the digest-at-ACCEPT design:
```
scripts/adversarial-review.sh:47   mkdir -p "$(dirname "$OUT")"
scripts/adversarial-review.sh:49   PROMPT=$(cat <<PROMPT_EOF
scripts/adversarial-review.sh:93   cp "$TMP" "$OUT"
scripts/adversarial-review.sh:96   VERDICT_LINE="$(grep -E '^VERDICT:' "$OUT" | tail -1 || true)"
scripts/adversarial-review.sh:97   case "$VERDICT_LINE" in
```
The register file is materialised by `cp` at `:93`, after the reviewer runs against the already-committed tip, and the verdict is parsed at `:96-97`. The gate-r5 register's cited ranges (`:49-79`, `:82-100`) bracket these exact lines. The brief's derived claim — that a `VERDICT: ACCEPT` string in an editable file proves nothing without an external binding — is correctly grounded.

**Re-derived this pass:**
```
ci.yml:3-8    push.branches [main] · pull_request.branches [main, dev] · workflow_dispatch  (nothing else)
ci.yml:1104   needs: [… frontend-lint …]
StockAdjustmentService.php:1715/:1716/:1719   ?StockMovementReferenceType / ?string $referenceId /
                                               assertReferenceLinkagePaired(...)
GeneralLedgerService.php:3480/:3507            sealAndPersistEntry(...) / bccomp(...) !== 0
```

**Conjunction check (house law).** (a) `frontend-lint` ↔ `all-checks-pass` `needs` — edge present at `:1104`. (b) `frontend-lint` ↔ the `package.json` lint chain — edge correctly denied. (c) **New for r11:** the pin-tag *name* pinned in metadata commit 2 ↔ the tag the owner creates at step 3 ↔ the tag P3-M0 fetches — all three sides opened: `p1:51-58` pre-allocates and reviews the name in A, `:418` requires the owner to create *"exactly that tag"* from A's reviewed mirror field, and `p3:30-31` reads the name *"from its YAML mirror field `dpa_baseline_pin_tag` at base"*. The chain closes on one identifier. (d) R5-C-1's digest ↔ P3's verification — `:416` digests the bridge output, `:418` records it in the annotation, `p3:32` compares `sha256` of the landed register to the annotation digest. Genuinely linked, not merely co-present.

The full previously-verified citation set stands against unmodified sources. F-8's `gh api → owner.type: "User"` remains **owner-attested, not round-0-verified** (round 0 makes no network calls); the gate-r5 register independently confirmed it. **PASS.**

---

## Check 5 — Permission keys

```
$ grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule" \
    docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md \
    docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml
(no output)   exit 1
```
No permission or module keys anywhere; the `canAccessModule` fail-open trap cannot apply. **N/A — PASS.**

---

## Hygiene

- **Banner ↔ latest log entry.** Banner `:4` = *"**Revision:** r11 — 2026-08-13, full gate-r5 fix round applied (…gate-r5.md: R5-C-1..3, R5-H-1..4 — itemized log below), **NOT yet re-gated**. Re-run round 0 from the top, then **gate round 6**, before dispatch."* Log-block anchors: `:4` banner · `:5` (r1/r2/r3) · `:24` r4 · `:27` r5 · `:29` r6 · `:42` r7 · `:50` r8 · `:52` r9 · `:61` r10 · **`:63` r11** — r11 is genuinely last. Rev, date and next-gate number consistent. ✓
- **YAML line-5 headers.** All three identical and at **r11**: *"# (r11 of the brief applies all 17 gate-r1 findings, round-0 R0-3/R0-4 + r7-R0-1 + r9-R0-1, the 11 gate-r2 findings, the 6 gate-r3 findings, the 7 gate-r4 findings, and the 7 gate-r5 findings; re-gate before dispatch)."* Counts re-derived **from the registers**: gate-r1 → **17** (4C+11H+2M) · gate-r2 → **11** (2C+7H+2M) · gate-r3 → **6** (2C+4H+0M) · gate-r4 → **7** (2C+5H+0M) · gate-r5 `grep -cE '^### R5-(C|H|M)-[0-9]+'` → **7**, IDs `R5-C-1 R5-C-2 R5-C-3 R5-H-1 R5-H-2 R5-H-3 R5-H-4` = **3C+4H+0M**. All five counts correct. ✓
- **Unescaped GFM pipes.** Four normative tables re-checked by `awk -F'|'` at r11 offsets — sequencing `:81-85` (header 4, all rows 4) · read-order `:116-123` (3) · DO-NOT-TOUCH `:141-148` (2) · write-surface contract `:164-169` (3). Every row matches its header; no cell loses a column despite the new `{A, sha256(...)}` brace/comma content (all outside tables). ✓
- **YAML validity / shape (js-yaml, from `apps/web`).** All three parse. One `base_sha`, one `branch`, top-level `status: pending` each. Milestones ordered and complete: **P1 `[M0,M1,M2,M3]`, P2 `[M0,M1,M2,M3,M4]`, P3 `[M0,M1,M2,M3]`** ✓. **Milestone-level `owner_gate:` fields = 0 / 0 / 0** ✓. Pin sets: P1 now includes `dpa_baseline_pin_tag` **and** `ratchet_trust_model_ack`; P2 includes `i18n_baseline_pin_tag` **and** `ratchet_trust_model_ack`; P3's set unchanged (`p1_landed_sha`, `country_defaults_landed_sha`, `p2_landed_sha`, `pre_promotion_ci_dispatch`) — all present, all `null`, none pre-filled ✓. `p2_m2_landed_sha` is a key in none ✓.
- **P3-M0 header-check numbering.** `1(a,b,c,d)` P1 whole-package · `2(a,b,c,d)` P2 whole-package · `3(a,b,c,d)` country-defaults — **coherent, no duplicates, no gaps**; the new `d` sub-item is the R5-C-1 evidence binding in both 1 and 2, and country-defaults keeps its original four. ✓
- **Stale sweeps.**
  - **tag deletion/retention affordance (R5-C-2 revocation)** → **2 hits, brief `:56` (r9 log) and `:65` (r11 log announcing the revocation)**. **Zero operative survivors** — the r10 "retained until a permanent remote branch … may be deleted only AFTER" text is fully gone from §5, §2, §3, F-8 and all YAMLs. ✓
  - `record* … BEFORE merge` receipts → **2 hits, brief `:31`, `:50`**, both historical log entries. ✓
  - `p2_m2_landed_sha` → brief `:37`/`:46`/`:85` + p3 `:17`/`:120`, all historical or explicit supersession; not a key anywhere. ✓
  - operative `node --test` → brief `:34`/`:297` + p2 `:139`, all inside the R2-H-3 removal ruling. ✓
  - **closing-allowlist wording** → **inconsistent; reported as R0-1 under Check 1** (not double-counted here).

**Hygiene: PASS** for pipes, banner, header counts, YAML shape and the four sweeps above; the allowlist inconsistency is carried as the Check-1 finding.

---

## Note-only observations (not findings)

1. **F-8's `gh api → owner.type: "User"` is owner-attested, not round-0-verified** — round 0 makes no network calls. The gate-r5 register independently confirmed `owner.type: User`, `private: true`.
2. **`TreasuryReceiptBridge` lives under `Application/Projections/`**, not `Application/Services/`; the brief cites only line numbers for it (all verify).
3. **Arabic-authored entries interleave the cited `i18n.ts` alias ranges** — the claim about what those ranges demonstrate is correct.
4. **`wave3-3c-3d.progress.yaml` M3 remains `status: pending`** — consistent with the brief treating ACCEPT as a dispatch-time P1-M0 precondition.
5. **`SystemAccountPurpose::expectedAccountType()` is at `:187`; the brief writes `:~186`** — within the marked tolerance.
6. **Step 3's annotation payload is prose-specified, not schema-specified.** `{A, sha256(final register(s)), sha256(handback)}` leaves the literal annotation format (field names, ordering, multi-register encoding) to the parent, while P3-M0 must parse it months later. Mechanically runnable as written, so not a finding — but a fixed one-line-per-field format would remove a parsing ambiguity, and the gate reviewer may wish to rule on it.

---

## Evidence appendix (raw outputs)

```
$ git rev-parse HEAD
a5520f23ca39209f5b517723037e9516808f2bca      (unchanged; == brief base a5520f23c)

$ wc -l <targets>
451 CODEX-DISPATCH-enforcement-guards-2026-08-12.md   (r10: 442 → +9)
167 enforcement-p1.progress.yaml                      (r10: 149 → +18)
172 enforcement-p2.progress.yaml                      (r10: 154 → +18)
153 enforcement-p3.progress.yaml                      (r10: 135 → +18)
105 LEDGER.md                                          (S-14 rewritten in place)

$ ls -lT
Aug 13 08:29:39 brief · Aug 13 08:31:53 LEDGER · Aug 13 08:31:58 p1,p2,p3

$ git status --porcelain apps/api apps/web .github scripts
?? scripts/dev-scan-stack.sh          (untracked, not a citation target)

--- R0-1: the two conflicting allowlists ---
NARROWED (correct):
  brief:420   "… every path in `git diff --name-only <A>..<admin-tip>` is inside the CURRENT package's
               closing set — its own docs/handoff/progress/enforcement-<pkg>.progress.yaml · its own
               docs/handoff/reviews/enforcement-<pkg>/** · its own docs/handoff/HANDBACK-enforcement-<pkg>-*.md
               · docs/handoff/LEDGER.md. Any other path — including ANOTHER package's evidence — = closing FAIL"
  LEDGER:56   "closing set only (its own progress YAML · its own docs/handoff/reviews/enforcement-<pkg>/** ·
               its own HANDBACK-enforcement-<pkg>-*.md · this LEDGER) — another package's evidence or any
               production/workflow path = closing FAIL (gate-r5 R5-H-3), output recorded in the receipt"
  p1:84-86 · p2:71-73 · p3:81-83   "… diff paths only in THIS package's closing set … apply to EVERY package"
SUPERSEDED WILDCARD (survives, unqualified):
  p1:77-79   "diff ⊆ the admin allowlist: docs/handoff/progress/*.progress.yaml · docs/handoff/LEDGER.md ·
              docs/handoff/reviews/enforcement-p{1,2,3}/** · docs/handoff/HANDBACK-enforcement-*.md"
  p2:63-66   same wording
  p3:75-76   "diff ⊆ the admin allowlist incl. docs/handoff/reviews/enforcement-p{1,2,3}/** + HANDBACK files"
  (brief hits for the wildcard pattern are only :44 and :53 — both revision-log entries)

--- R5-C-2 revocation sweep (tag deletion/retention) ---
grep -niE 'retained until|until a permanent remote branch|may be deleted only AFTER|delete the tag|
           tag may be deleted|deleting a tag|tag deletion'  <brief + 3 YAMLs + LEDGER>
  brief:56  (r9 log — describes the old rule)
  brief:65  (r11 log — announces the revocation)
  ZERO operative survivors
"NEVER deleted / stays forever": brief:65, :182, :183, :284, :418, :436 · p1:57 · p2:45 · LEDGER:56

--- §5 item 4 (r11) ---
414 header: UNIVERSAL for EVERY package (R5-H-4) + serialization: parent performs NO local-dev merge
    of ANY lane between step 0 and step 5 (single writer)
415  0. Freshness assert: git merge-base --is-ancestor <current-local-dev-tip> A (FF only)
416  1. ACCEPT A; PARENT computes sha256 of EXACT bridge-output register(s) + handback BEFORE any edit
417  2. (P1/P2 only) preflight pre-allocated tag name still free → set variable → verify read-back;
        P2: parent SENDS the final announcement
418  3. Owner pushes the pre-allocated ANNOTATED tag at exactly A, annotation {A, sha256(register(s)),
        sha256(handback)}, verifies it resolves to A. Pin tags are NEVER deleted.
        (workflow-touching only:) throwaway ref + workflow_dispatch, head==A, GREEN, jobs executed
419  4/4a. Freshness RE-assert immediately before merge; then fast-forward exactly A
420  5. ONE admin commit lands digested register(s)+handback (exact bytes from step 1) + final YAML
        status + receipts + LEDGER; named check: rev-list --count == 1 AND diff ⊆ CURRENT package's set
421  Re-gate protocol: triggers = step-0/4a failure, RED run, or ANY external side-effect failure
        (tag collision / push failure / tag not resolving to A / variable read-back mismatch /
         ref-push or dispatch API failure / announcement failure); restore last known-good; record
         partial state in S-14; retry SAME A from step 2, or re-gate → A′ (announcement re-sent)

--- R5-C-1 binding chain (P3-M0) ---
p3:29-35  1d  fetch P1 closing tag (name from dpa_baseline_pin_tag at base) → annotation A == landed
              accepted tip AND sha256(LANDED register) == annotation digest AND exactly ONE closing
              admin commit whose diff ⊆ P1's specific closing set
p3:44-46  2d  same shape for P2 (i18n_baseline_pin_tag)

--- R5-H-1 ---
enforcement-p1.progress.yaml:103  ratchet_trust_model_ack: null   (M0 item (6))
enforcement-p2.progress.yaml:91   ratchet_trust_model_ack: null   (M0 item (5))
brief:436 F-8 — "The trust assertion is operative (gate-r5 R5-H-1) … non-null is a P1/P2 M0 precondition"

--- js-yaml parse ---
p1 PARSE OK · [M0,M1,M2,M3] match · owner_gate NONE (0) · pins all present+null
   (incl. dpa_baseline_pin_tag, ratchet_trust_model_ack) · p2_m2_landed_sha=false
p2 PARSE OK · [M0,M1,M2,M3,M4] match · owner_gate NONE (0) · pins all present+null
   (incl. i18n_baseline_pin_tag, ratchet_trust_model_ack) · p2_m2_landed_sha=false
p3 PARSE OK · [M0,M1,M2,M3] match · owner_gate NONE (0) · pins all present+null · p2_m2_landed_sha=false
line 5 (all three): "# (r11 … all 17 gate-r1 findings, round-0 R0-3/R0-4 + r7-R0-1 + r9-R0-1, the 11
   gate-r2 findings, the 6 gate-r3 findings, the 7 gate-r4 findings, and the 7 gate-r5 findings; …)"

--- gate-register counts (re-derived from the registers) ---
gate-r1 → 17 (4C+11H+2M) · gate-r2 → 11 (2C+7H+2M) · gate-r3 → 6 (2C+4H+0M)
gate-r4 →  7 (2C+5H+0M)  · gate-r5 →  7 (3C+4H+0M)  IDs R5-C-1..3, R5-H-1..4

--- table cell counts (awk -F'|', cells = NF-2) ---
:81-85 → 4×5 · :116-123 → 3×8 · :141-148 → 2×8 · :164-169 → 3×6      all consistent

--- other stale sweeps ---
record*+BEFORE-merge  → brief:31, brief:50 (historical log)                        CLEAN
p2_m2_landed_sha      → brief:37,46,85 + p3:17,120 (historical/supersession)       CLEAN
node --test           → brief:34,297 + p2:139 (removal ruling)                     CLEAN

--- repo citations re-derived ---
ci.yml:3-8 · ci.yml:1104 (needs incl. frontend-lint)
StockAdjustmentService.php:1715/:1716/:1719 · GeneralLedgerService.php:3480/:3507
adversarial-review.sh:47 mkdir · :49 PROMPT heredoc · :93 cp "$TMP" "$OUT" · :96 VERDICT_LINE
grep -L vitest tools/__tests__/* → 0 · test:tools → 0 · failOnEmptyTestSuite → 0
ParserFactory 4/16 · SystemAccountPurpose cases 41 · ci.yml:629 → 93 · ci.yml:726 → 16
grep -rn 'test:eslint-rules|tools/__tests__' .github/workflows/ → exit 1

--- check 5 ---
grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule"
  over brief + all three YAMLs → (no output) exit 1
```

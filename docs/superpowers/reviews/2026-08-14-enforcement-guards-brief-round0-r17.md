# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r17 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-14

Scope: the brief at r17 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15 + the two control artifacts, one of which was **rewritten** this round: `scripts/adversarial-review-final.sh` (152 → 198 lines) and `docs/handoff/enforcement-control-manifest.yaml` (47 → 49). All uncommitted on `dev`. `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca` — unchanged across the series.

Run in FULL from the top. **Artifacts were exercised, not read**: the rewritten bridge was syntax-checked, its manifest reader run against every key it requests, its verdict parser and seal predicate executed against adversarial inputs, and all nine manifest digests re-derived.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 0 | Diff-scope of the r17 edit    | **PASS** | 0 |
| 1 | Revision-log truth (6 gate-r9 claims) | **FAIL** | 1 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable **(artifacts exercised)** | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 |
| H | Hygiene (pipes, banner + ordering, header counts, YAML shape, stale sweeps) | **PASS** | 0 — the stale-rule hit is reported as R0-1 under Check 1 |

**VERDICT: FAIL — one FAIL row (Check 1). r17 does NOT pass round 0; do not dispatch gate round 10 until R0-1 is fixed and round 0 is re-run from the top.**

Five of the six gate-r9 repairs are applied and **verified by execution**, including the two hardest (exact verdict parsing and the neutral-cwd invocation). The sixth — **R9-H-2** — landed in the r17-bindings sentence and in the shipped script, but the **superseded, mutually exclusive rule was not substituted out of six operative locations**, and the shipped bridge now *deterministically rejects* the handover those six locations instruct actors to produce.

---

## Findings

- **[R0-1] Check 1 · CRITICAL — R9-H-2's seal rule was added, not substituted: six operative locations still mandate the exact handover the rewritten bridge rejects.**

  The r17 log (`:100`) and the §5 r17-bindings sentence (`:449`) state the new rule — *"the seal admits EXACTLY ONE dirty entry, the untracked handback source, which the bridge itself copies+hashes (R9-H-2)"* — and the shipped script enforces it verbatim:
  ```
  scripts/adversarial-review-final.sh:126  EXPECTED_STATUS="?? ${HANDBACK_SRC}"
  scripts/adversarial-review-final.sh:127  [[ "$STATUS" == "$EXPECTED_STATUS" ]] || { … exit 3; }
  ```
  But the **r16 rule survives unchanged in six operative places**, and it requires the opposite state:

  | Location | Surviving text |
  |---|---|
  | **brief `:449` sub-step (ii)** — *the same paragraph as the new rule* | *"(ii) verifies the handed-over worktree is otherwise CLEAN (`git status --porcelain` empty — an operative handover rule)"* |
  | **brief `:125` EXECUTION MODE** | *"HAND OVER WITH A CLEAN TREE (`git status --porcelain` empty — the handback is the ONE file the parent copies out before checking…)"* |
  | **`enforcement-p1.progress.yaml:176` (M3)** | *"hand over with a CLEAN tree (parent copies the handback out first, then `git status --porcelain` must be empty)"* |
  | **`enforcement-p2.progress.yaml:180` (M4)** | identical |
  | **`enforcement-p3.progress.yaml:182` (M3)** | identical |
  | **`LEDGER.md:56` S-14 step 1** | *"clean-tree handover after the parent copies the handback to its immutable workspace"* |

  A sweep confirms the asymmetry: the **new** rule (`EXACTLY ONE dirty` / `handback source` / `--handback-source`) appears in exactly **two** places, both in the brief (`:100` log, `:449` bindings sentence); the **superseded** rule appears in **six** operative places across three files.

  **This is not stale prose — it is executable-contradicting.** I exercised the shipped seal predicate against the four relevant states:
  ```
  [?? docs/handoff/HANDBACK-enforcement-p1-2026-08-12.md]  -> PASS seal
  [ M docs/handoff/HANDBACK-…]                             -> exit3 SEAL FAIL   (tracked-modified rejected ✓)
  [?? a  (+ other entries)]                                -> exit3 SEAL FAIL
  []  (EMPTY — i.e. the state the six locations mandate)   -> exit3 SEAL FAIL
  ```
  An executor/parent who follows any of the six locations produces an **empty** status and the bridge exits 3 at `:127` before creating the snapshot. That is the R9-H-2 deadlock re-created in the opposite direction: gate-r9 found *"copy plus an exactly empty source tree is not executable"* and the repair inverted the requirement — but only in two of eight places, leaving the *majority* of operative text pointing at the now-rejected state. Sub-step (ii) and the r17-bindings sentence are in **the same line of §5 step 1** and contradict each other outright: a tree containing `?? handback` is not "porcelain empty", and an empty tree is not "exactly one dirty entry".

  **Repair:** substitute the new seal rule into all six locations — §5 step 1 (ii), the EXECUTION MODE exception, the three final milestone titles, and LEDGER S-14 — so each states that the handover carries **exactly one dirty entry, the untracked handback source, which the bridge copies and hashes itself**. Then bump the revision log honestly and re-run round 0 from the top.

No other finding.

---

## Check 1 — Revision-log truth

| Claim | Re-derived verification | OK |
|---|---|---|
| **R9-C-1** base bound to the parent's dispatch record | **Brief §5 `:449` r17-bindings**: *"`--base` comes from the parent's dispatch record — never candidate YAML — with strict-ancestor + `BASE != A` checks and a candidate-YAML field-check against the manifest projection (R9-C-1)"*. **Script**: `--base` required (`:35`, `:44`); `git -C "$CANDIDATE" merge-base --is-ancestor "$BASE" "$ACCEPTED"` (`:100`); `[[ "$BASE" != "$ACCEPTED" ]]` (`:101`); `PROGRESS_PATH=$(manifest_get "packages.$PACKAGE.progress_path")` (`:97`); the embedded python field-checks `base_sha == BASE` (`:114-115`), the milestone exists (`:118-119`), `status == review` (`:120`), `fix_rounds <= max` (`:121`). **Manifest**: `progress_path` present for **p1, p2 and p3** (verified by both grep and js-yaml). | ✓ |
| **R9-H-1** neutral cwd + injected brief/harness + post-run re-assertions | **Script**: `NEUTRAL=$(mktemp -d)/run; mkdir -p "$NEUTRAL"` (`:140`); invocation `if ! (cd "$NEUTRAL" && claude -p "$PROMPT" …)` (`:164`). A `grep -n 'cd "'` over the whole file returns **exactly one hit — line 164** — so there is no `cd` into `$SNAP` or the caller's checkout anywhere. Prompt injects `$(cat "$BRIEF_PATH")` (`:156`) and `$(cat "$HARNESS_PATH")` (`:159`) under **BINDING BRIEF** / **HARNESS CONTRACT** headers. Post-run re-assertions `:170-175`: snapshot `HEAD == A`, snapshot `status --porcelain` empty, `HB_SHA2 == HB_SHA`. | ✓ |
| **R9-H-2** seal admits exactly one dirty entry; bridge copies+hashes | Script `:125-132` implements it (exact status equality, `cp`, `chmod a-w`, `sha`) and rejects tracked-modified by construction (` M path` ≠ `?? path`) — **verified by execution**. **But six operative locations still mandate the superseded empty-tree handover** → **R0-1**. | **✗** |
| **R9-H-3** `--expected-manifest-sha256` verified before parsing; manifest digest in register; P3 consumes it | **Script**: required argument (`:31`, `:44`); manifest authenticated at `:51-52` — **before `manifest_get` is even defined** (`:54`) and before any call; `CONTROL_LINES` opens with `manifest_sha256: ${MANIFEST_SHA}` (`:77`), emitted into the register banner at `:184`. **P3-M0 1d (`p3:42-43`)**: *"AND its `control_sha256`/`manifest_sha256` lines MATCH P1's dispatch manifest at base (via P1's `control_manifest` pin — gate-r9 R9-H-3)"*; **2d (`p3:56-58`)**: same for P2. | ✓ |
| **R9-H-4** exact verdict parsing | **Script** `:191-198`: `VCOUNT` must be exactly `1`, then a `case` on **quoted exact strings** `"VERDICT: ACCEPT"` / `"VERDICT: CHANGES-REQUIRED"`, everything else `exit 3`. **Verified by execution** (table in Check 3): `VERDICT: NOT ACCEPT` → exit 3, `ACCEPT WITH CONDITIONS` → exit 3, lowercase → exit 3, trailing space → exit 3; 0 or 2 verdict lines → exit 3. | ✓ |
| **R9-M-1** `closing_check == pass` in consumers + producer read-back | **P3-M0 1d (`p3:42`)**: *"its `closing_check` EQUALS `pass` (gate-r9 R9-M-1)"*; **2d (`p3:56`)**: *"`closing_check == pass`"*. Producer schema in all three YAMLs (`p1:115` and peers) and §5 step 5 unchanged and consistent. | ✓ |

### Earlier-revision spot-checks

`<admin-tip>` → 4 hits, brief-only (3 historical log entries + §5's negation); **0 in every YAML and the LEDGER** ✓. "allocated at promotion" → **0 everywhere** ✓. "sha256 of the verbatim check output" → 1 (brief `:86`, r15 log) ✓. R8 items (manifest-sourced lenses, detached snapshot, digest-not-in-prompt, tool-error split) all carried forward and strengthened ✓.

**Check 1: FAIL** (R0-1).

---

## Check 2 — Exhaustive claims / censuses (re-run)

| Inventory claim | Census | Documented | Re-derived | Match |
|---|---|---|---|---|
| pgsql allowlist "93 class names" (`ci.yml:629`) | `tr '\|' '\n' \| grep -c Test` | 93 | **93** | ✓ |
| second allowlist "16-entry" (`:726`) | same | 16 | **16** | ✓ |
| Architecture ParserFactory "4 of 16" | `grep -l` / `ls` | 4 / 16 | **4 / 16** | ✓ |
| "complete 41-case partition" | `grep -cE '^    case '` | 41 | **41** | ✓ |
| tools tests all import `vitest` · `test:tools` absent · no `failOnEmptyTestSuite` | `grep` | as stated | **0 / 0 / 0** | ✓ |
| `test:eslint-rules` + `tools/__tests__` in NO workflow | `grep -rn` | exit 1 | **exit 1** | ✓ |
| `frontend-lint` ∈ `all-checks-pass` `needs` | `sed -n '1104p'` | present | **present** | ✓ |
| manifest pins 6 lens contracts + 3 package blocks with `progress_path` | js-yaml | 6 / 3 | **6 / 3 (all `pp=yes`)** | ✓ |

**PASS.**

---

## Check 3 — Test contracts executable (artifacts exercised)

### The rewritten bridge

`bash -n` → **OK**; `test -x` → **executable**; 198 lines.

| Claimed behavior | Verification | Result |
|---|---|---|
| `--expected-manifest-sha256` required, verified **before** any `manifest_get` | required-var loop `:44-46`; check at `:51-52`; `manifest_get` defined at `:54` | ✓ ordering is provably correct |
| `--base` strict ancestor + `BASE != A` | `:100`, `:101` | ✓ |
| candidate progress-YAML field-check via manifest `progress_path` | `:97`, `:103-122` (base_sha / milestone / status==review / fix_rounds<=max) | ✓ |
| seal = exactly one dirty entry, bridge copies+hashes | `:125-132` | ✓ (exercised below) |
| neutral empty cwd; no cd into SNAP/caller | `:140`, `:164`; `grep -n 'cd "'` → **only `:164`** | ✓ |
| brief + harness injected as BINDING content | `:155-159` | ✓ |
| post-run re-assertions | `:170-175` | ✓ |
| exact verdict parsing | `:191-198` | ✓ (exercised below) |
| **prompt must not leak digests** | heredoc `:141-161` interpolates exactly `${ACCEPTED} ${BASE} ${BRIEF_PATH} ${HANDBACK} ${HARNESS_PATH} ${LENS_BLOCK} ${MILESTONE} ${PACKAGE} ${ROUND} ${SNAP}`; `grep -c 'HB_SHA\|EXPECTED_MANIFEST_SHA\|MANIFEST_SHA'` over those lines → **0** | ✓ |

**Verdict parser exercised** (case block extracted verbatim):
```
[VERDICT: ACCEPT]                  -> exit0 ACCEPT
[VERDICT: CHANGES-REQUIRED]        -> exit2 CHANGES-REQUIRED
[VERDICT: NOT ACCEPT]              -> exit3 tool_error (fail closed)   ← R9-H-4's exact scenario
[VERDICT: ACCEPT WITH CONDITIONS]  -> exit3 tool_error
[VERDICT: accept]                  -> exit3 tool_error
[VERDICT: ACCEPT ]                 -> exit3 tool_error
VCOUNT: 1 -> proceeds · 2 -> exit3 · 0 -> exit3
```

**Seal predicate exercised** — see R0-1; note the fourth row is the state the six superseded locations mandate.

**Manifest reader exercised** — the script's embedded `manifest_get` run against the shipped manifest for **every key the script requests**, including the new `progress_path`:
```
controls.bridge_final_sha256 / brief_path / brief_sha256 / harness_path / harness_sha256   ✓
packages.{p1,p2,p3}.progress_path / final_milestone / lenses / max_fix_rounds              ✓
lens_contracts.<lens>.path / .sha256                                                        ✓
```
All 16 keys resolve.

**All nine manifest digests re-derived against current bytes:**

| Pin | Value | Match |
|---|---|---|
| `bridge_final_sha256` → the **rewritten** script | `dafd4719462aafae53a9e907f97c85911f6e80d7ba415de3a00e38720ddfc9e8` | ✓ |
| `brief_sha256` → the **current r17** brief | `b18e69996bf089ee7ffad1df51ff1672f113d529f2f975427daf95eba8a20339` | ✓ |
| `harness_sha256` | `6086d86d…8c15` | ✓ |
| six lens contracts | `7e1c3c7c…` `7f7691e8…` `408258d0…` `419ac208…` `278596db…` `9e2e444a…` | ✓ |

*(Methodology note: my first extraction of `brief_sha256` used an unanchored `grep`, which also matched the manifest's caveat **comment** line and printed a spurious MISMATCH. Re-run line-anchored (`^  brief_sha256:`) it matches exactly. Recorded because a bad grep nearly produced a false finding — the same class I corrected at r15 and r16.)*

### Existing contracts

P1/P2 acceptance blocks, P1 tamper 1–5, P3 census-derived acceptance, promotion steps 0/2/3/4a/5 — unchanged and runnable. **No contract asserting through a mock of its subject**, and R9-C-1/H-1 remove the last two inputs the reviewed party controlled (review range, reviewer cwd/config).

**PASS.**

---

## Check 4 — Behavior/repo claims cite source

```
ci.yml:3-8 · :1104 · :1-26 permissions=0 · StockAdjustmentService.php:1715/:1716/:1719
GeneralLedgerService.php:3480/:3507 · adversarial-review.sh handback-count=0 (mid-wave bridge untouched)
.claude/agents/*-reviewer.md → 7 files (manifest pins the 6 used)
```

**Conjunctions.** (a) `frontend-lint` ↔ `needs` — edge at `:1104`. (b) `frontend-lint` ↔ lint chain — correctly denied. (c) **New for r17 — manifest `progress_path` ↔ the script's field-check ↔ the real files**: the manifest names `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml`, the script reads `"$CANDIDATE/$PROGRESS_PATH"` (`:103`), and those three files exist with the `base_sha`/`milestones[].status`/`fix_rounds` fields the python block reads — the projection is real, not nominal. (d) **New — `--expected-manifest-sha256` ↔ the `control_manifest` YAML pin ↔ P3-M0's comparison**: the pin exists in all three YAMLs (`p1:121`-class, parse-confirmed), and P3-M0 1d/2d compare the tag's `control_sha256`/`manifest_sha256` against *that* pin — the chain that R9-H-3 said was open now closes end-to-end.

**Owner-attested external facts** (`default_workflow_permissions: read`; `owner.type: "User"`) recorded as attested, not re-derived; locally-checkable halves re-derived.

**PASS.**

---

## Check 5 — Permission keys

`grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule"` over brief + three YAMLs + the manifest → **no output, exit 1**. **N/A — PASS.**

---

## Hygiene

- **Banner.** `:4` is a **single well-formed sentence** (the concatenation defect is gone): *"**Revision:** r17 — 2026-08-14, full gate-r9 fix round applied (…gate-r9.md: R9-C-1, R9-H-1..4, R9-M-1 — itemized log below; `scripts/adversarial-review-final.sh` REWRITTEN accordingly and re-pinned in the manifest), **NOT yet re-gated**. Re-run round 0 from the top, then **gate round 10**, before dispatch, per house rule…"*. Log anchors run monotonically to **`:97` r17 — last** ✓.
- **YAML headers.** All three at **r17**, ending *"…the 5 gate-r8 findings, **and the 6 gate-r9 findings**; re-gate before dispatch)."* Counts re-derived from the registers: **17 / 11 / 6 / 7 / 7 / 6 / 4 / 5 / 6**; gate-r9 `grep -cE '^### R9-…'` → **6**, IDs `R9-C-1 R9-H-1..4 R9-M-1` = **1C+4H+1M** ✓.
- **Pipes.** Four tables at r17 offsets — `:114-118` (4) · `:149-156` (3) · `:174-181` (2) · `:197-202` (3); every row matches its header ✓.
- **YAML shape.** All three parse; milestones **P1 [M0-M3] · P2 [M0-M4] · P3 [M0-M3]**; **milestone-level `owner_gate:` = 0/0/0**; pin sets complete, all `null`, **no extra/missing top-level keys**. Manifest parses independently with `progress_path` on all three packages ✓.
- **Stale sweeps.** `<admin-tip>` brief-only/historical ✓ · "allocated at promotion" 0 ✓ · "sha256 of the verbatim check output" 1 historical ✓ · **new seal-rule sweep → the R0-1 asymmetry (2 new vs 6 superseded)**, reported under Check 1.

**PASS** for pipes/banner/counts/shape/legacy sweeps; the seal-rule sweep result is the Check-1 finding.

---

## Note-only observations (not findings)

1. **The r17 claim list did not name the milestone titles / EXECUTION MODE / S-14 for R9-H-2** — so R0-1 is not a failure to meet the stated locations; it is that the *unstated* locations retain a rule the shipped executable now rejects. I raise it because it is executable-contradicting, not because the claim list demanded it.
2. **The 7-vs-6 lens glob** remains a conservative superset (`imports-reviewer.md` unpinned and unused).
3. **Both artifacts remain untracked**; F-9 and the manifest's own header carry the commit-before-dispatch obligation, and `brief_sha256` will move again on the next brief edit.
4. **The three-attempt tool-error bound still lives in the brief, not the script** — the script supplies `--attempt` and the retry hint at `:165`.
5. Standard carried notes: TreasuryReceiptBridge directory; Arabic-authored entries in the `i18n.ts` alias ranges; `wave3-3c-3d` M3 pending; `expectedAccountType()` at `:187` vs the hedged `:~186`; two owner-attested external facts.

---

## Evidence appendix (raw outputs)

```
$ wc -l
485 brief (r16 477 → +8) · 185 p1 (=) · 189 p2 (=) · 191 p3 (187 → +4) · 105 LEDGER (=)
 49 enforcement-control-manifest.yaml (47 → +2)      198 adversarial-review-final.sh (152 → +46, REWRITTEN)

--- banner + ordering ---
:4 single sentence, r17 / 2026-08-14 / "then gate round 10"
log anchors … :84 r15 · :90 r16 · :97 r17 (LAST)   strictly monotonic

--- R0-1: the seal-rule asymmetry ---
NEW rule ("EXACTLY ONE dirty" / "handback source"):   brief:100 (r17 log) · brief:449 (r17-bindings)
SUPERSEDED rule ("porcelain empty" / "copies the handback out"):
   brief:449 sub-step (ii)   ← same line as the new rule
   brief:125 EXECUTION MODE
   enforcement-p1.progress.yaml:176   (M3)
   enforcement-p2.progress.yaml:180   (M4)
   enforcement-p3.progress.yaml:182   (M3)
   LEDGER.md:56 S-14 step 1 ("clean-tree handover after the parent copies the handback to its
                              immutable workspace")
   (brief:91 is the r16 log — historical, permitted)
script enforcement:  :126 EXPECTED_STATUS="?? ${HANDBACK_SRC}"   :127 exact equality || exit 3

--- SEAL PREDICATE EXERCISED ---
[?? docs/handoff/HANDBACK-enforcement-p1-2026-08-12.md] -> PASS seal
[ M docs/handoff/HANDBACK-…]                            -> exit3 SEAL FAIL
[?? a + others]                                         -> exit3 SEAL FAIL
[] (empty — what the six locations mandate)             -> exit3 SEAL FAIL

--- VERDICT PARSER EXERCISED (script :191-198) ---
"VERDICT: ACCEPT"->0 · "VERDICT: CHANGES-REQUIRED"->2 · "VERDICT: NOT ACCEPT"->3 ·
"VERDICT: ACCEPT WITH CONDITIONS"->3 · "VERDICT: accept"->3 · "VERDICT: ACCEPT "->3
VCOUNT 1->proceed · 2->3 · 0->3

--- PROMPT heredoc (:141-161) interpolation audit ---
${ACCEPTED} ${BASE} ${BRIEF_PATH} ${HANDBACK} ${HARNESS_PATH} ${LENS_BLOCK} ${MILESTONE}
${PACKAGE} ${ROUND} ${SNAP}
grep -c 'HB_SHA|EXPECTED_MANIFEST_SHA|MANIFEST_SHA' over those lines -> 0
grep -n 'cd "' over the whole script -> only :164 (cd "$NEUTRAL")

--- MANIFEST DIGESTS RE-DERIVED (9/9) ---
bridge_final  ✓ dafd4719462aafae53a9e907f97c85911f6e80d7ba415de3a00e38720ddfc9e8  (rewritten script)
brief         ✓ b18e69996bf089ee7ffad1df51ff1672f113d529f2f975427daf95eba8a20339  (current r17 bytes)
harness       ✓ 6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
6 lens contracts ✓ (7e1c3c7c… 7f7691e8… 408258d0… 419ac208… 278596db… 9e2e444a…)
[first brief check printed a false MISMATCH from an unanchored grep hitting the caveat comment;
 line-anchored re-run matches — recorded to keep the false positive on the record]

--- manifest_get: 16/16 keys resolve, incl. packages.{p1,p2,p3}.progress_path ---

--- js-yaml ---
p1 [M0..M3] · p2 [M0..M4] · p3 [M0..M3] · og=0/0/0 · missing=[] · extra=[] · all pins null
manifest: p1(M3,pp=yes) p2(M4,pp=yes) p3(M3,pp=yes) · lenses=6
line5 (all three): "… the 5 gate-r8 findings, and the 6 gate-r9 findings; re-gate before dispatch)"

--- gate-register counts --- r1 17 · r2 11 · r3 6 · r4 7 · r5 7 · r6 6 · r7 4 · r8 5 · r9 6
--- tables --- :114-118 4×5 · :149-156 3×8 · :174-181 2×8 · :197-202 3×6   consistent
--- check 5 --- brief + 3 YAMLs + manifest -> no output, exit 1
--- repo --- ci.yml:3-8/:1104/:1-26(perms=0) · SAS:1715/:1716/:1719 · GLS:3480/:3507
             vitest-L=0 test:tools=0 failOnEmpty=0 PF=4/16 cases=41 :629→93 :726→16
             mid-wave bridge handback-count=0 · lens contracts on disk=7, manifest pins 6
```

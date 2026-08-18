# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r18 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-14

Scope: the brief at r18 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15 + the two control artifacts (`scripts/adversarial-review-final.sh`, `docs/handoff/enforcement-control-manifest.yaml`). All uncommitted on `dev`. `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca` — unchanged across the series.

Run in FULL from the top. Artifacts exercised where behaviour is claimed; all manifest digests re-derived against current bytes.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 0 | Diff-scope of the r18 edit    | **PASS** | 0 |
| 1 | Revision-log truth (r18: 2 items; r17's 6 gate-r9 claims) | **PASS** | 0 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable (artifacts re-exercised) | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 |
| H | Hygiene (pipes, banner + ordering, header counts, YAML shape, stale sweeps) | **PASS** | 0 |

**VERDICT: PASS — all rows. r18 is round-0 clean and gate-round-10 ready.** No findings. The r17 R0-1 is closed in **both directions**: the superseded empty-tree rule survives only in a historical log entry, the one-dirty-entry rule is present in all six operative locations, and the substituted text is **character-consistent with the shipped bridge's actual predicate**.

---

## Check 0 — Diff-scope

**Brief:** 485 → **487** (+2). Every section anchor shifted uniformly **+2** — Executor 105→107 · SEQUENCING 110→112 · EXECUTION MODE 124→126 · §0 147→149 · §1 160→162 · §2 187→189 · §3 280→282 · §4 377→379 · §5 434→436 · §6 460→462 · §7 474→476. **No inter-section span changed**, so the entire +2 is the appended r18 log block and every one of the six substitutions is an in-line replacement.

**YAMLs:** 185 / 189 / 191 — **all three unchanged in length**, exactly what same-length title substitutions plus a one-line-for-one-line header replacement produce. js-yaml confirms no key changes (`missing=[]`, `extra=[]`, all pins still `null`).

**LEDGER:** 105 → **105**, edited in place (mtime 11:14:26, inside the r18 window).

**Artifacts:** `scripts/adversarial-review-final.sh` mtime **11:04:42 — unchanged from r17** (and its manifest pin is unchanged, below), consistent with "no other text changed"; the manifest was touched (11:14:42) solely to refresh `brief_sha256`.

**Check 0: PASS.**

---

## Check 1 — Revision-log truth

### (a) The six-location substitution sweep — both directions

**Direction 1 — superseded empty-tree rule must be gone from operative text:**
```
$ grep -n 'porcelain` empty|porcelain must be empty|copies the handback out|copies out|
          copies the handback to its immutable'  <brief + 3 YAMLs + LEDGER>
docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:91   (r16 revision-log entry — R8-C-1)
```
**One hit, historical. Zero in every YAML, zero in the LEDGER.** ✓

**Direction 2 — the one-dirty-entry rule present in all six operative locations** (verified by reading each, after a first paraphrased grep under-matched — see the methodology note):

| # | Location | Substituted text |
|---|---|---|
| 1 | brief `:451` step-1 (ii) | *"verifies the handed-over worktree's `git status --porcelain` shows EXACTLY ONE entry — `?? <handback-source>`, the untracked handback (gate-r9 R9-H-2…)"* |
| 2 | brief `:127` EXECUTION MODE | *"HAND OVER with `git status --porcelain` showing EXACTLY ONE entry — your untracked handback file (gate-r9 R9-H-2; the parent's bridge copies+hashes it itself; anything else dirty, or a tracked-modified handback, is rejected)."* |
| 3 | `enforcement-p1.progress.yaml` M3 | *"hand over with `git status --porcelain` showing EXACTLY ONE entry — the untracked handback (the bridge copies+hashes it itself…)"* |
| 4 | `enforcement-p2.progress.yaml` M4 | identical |
| 5 | `enforcement-p3.progress.yaml` M3 | identical |
| 6 | `LEDGER.md:56` S-14 | *"one-dirty-entry handover: status shows exactly the untracked handback, which the bridge copies+hashes itself (gate-r9 R9-H-2)"* |

**6/6 substituted; 0 survivors.** The asymmetry that produced r17's R0-1 (2 new vs 6 superseded) is fully inverted.

### (b) Consistency with the shipped bridge's actual predicate

```
scripts/adversarial-review-final.sh:126   EXPECTED_STATUS="?? ${HANDBACK_SRC}"
scripts/adversarial-review-final.sh:127   [[ "$STATUS" == "$EXPECTED_STATUS" ]] || { … exit 3; }
brief:451 (ii)                            …shows EXACTLY ONE entry — `?? <handback-source>`…
```
Step-1 (ii) names the **literal predicate string** `?? <handback-source>` — character-identical in form to what the script compares. The other five locations state the same rule in prose ("exactly one entry — the untracked handback"), which is precisely what `?? <path>` denotes, and locations 2 and 6 additionally state the two rejection cases the script enforces by construction (anything else dirty; tracked-modified handback, since ` M path` ≠ `?? path`). **Documentation and executable now agree.** The r17 deadlock — every conforming handover rejected at `:127` — is removed.

### (c) The two r18 claims

| Claim | Verified | OK |
|---|---|---|
| **(1)** all six locations state the one-dirty-entry rule; porcelain-empty sweep returns zero operative survivors | Both sweeps above | ✓ |
| **(2)** banner → r18, r18 log block appended LAST, three YAML headers → r18 with `+ r17-R0-1`, manifest `brief_sha256` refreshed | Banner `:4` = *"r18 — 2026-08-14, round-0 r17 R0-1 fix applied on top of the full gate-r9 fix round (r17: …gate-r9.md, R9-C-1, R9-H-1..4, R9-M-1 …)"*; log anchors … `:84` r15 · `:90` r16 · `:97` r17 · **`:105` r18 (LAST)**, monotonic. All three YAML line-5 headers read **r18** with the round-0 fix list *"R0-3/R0-4 + r7-R0-1 + r9-R0-1 + r11-R0-1 + r13-R0-1 **+ r17-R0-1**"*. `brief_sha256` moved `b18e6999…` → **`fe5e2b33539116079642a1c1bd45b4fb14a5f6657dc647ce8a4c4e8f968fa0b5`** and re-derives against the current r18 brief. | ✓ |
| **"No other text changed"** | Check 0: brief +2 with every span unchanged; YAML line counts identical; LEDGER in place; script byte-identical (pin unchanged) | ✓ |

### The six gate-r9 (r17) claims — re-verified at r18 offsets

**R9-C-1** ✓ §5 step-1 r17-bindings + script `:100-101` (strict ancestor, `BASE != A`) + `:97`/`:103-122` (progress-YAML field-check via manifest `progress_path`, present for p1/p2/p3). **R9-H-1** ✓ `(cd "$NEUTRAL" && claude …)` at `:164`, the only `cd "` in the file; brief+harness injected `:156`/`:159`; post-run re-assertions `:170-175`. **R9-H-2** ✓ — **now complete**, see (a)/(b). **R9-H-3** ✓ `--expected-manifest-sha256` required and verified at `:51-52` before `manifest_get` is defined at `:54`; `manifest_sha256:` leads the register banner; P3-M0 1d/2d compare against the `control_manifest` pin. **R9-H-4** ✓ `VCOUNT == 1` + quoted exact-string `case` at `:191-198`. **R9-M-1** ✓ `closing_check == pass` in P3-M0 1d and 2d.

**Check 1: PASS.**

---

## Check 2 — Exhaustive claims / censuses (re-run)

| Inventory claim | Census | Documented | Re-derived | Match |
|---|---|---|---|---|
| pgsql allowlist "93 class names" (`ci.yml:629`) | `tr '\|' '\n' \| grep -c Test` | 93 | **93** | ✓ |
| second allowlist "16-entry" (`:726`) | same | 16 | **16** | ✓ |
| Architecture ParserFactory "4 of 16" | `grep -l` / `ls` | 4 / 16 | **4 / 16** | ✓ |
| "complete 41-case partition" | `grep -cE '^    case '` | 41 | **41** | ✓ |
| tools tests all import `vitest`; no `failOnEmptyTestSuite` | `grep` | as stated | **0 / 0** | ✓ |
| `frontend-lint` ∈ `all-checks-pass` `needs` | `sed -n '1104p'` | present | **present** | ✓ |
| manifest: 6 lens contracts, 3 package blocks each with `progress_path` | js-yaml | 6 / 3 | **6 / 3 (all `pp=y`)** | ✓ |

**PASS.**

---

## Check 3 — Test contracts executable

The bridge is byte-identical to the r17 revision I exercised in full (pin `dafd4719…` unchanged, mtime unchanged), so its exercised properties carry; `bash -n` re-run → **OK**, still executable. Re-confirmed this pass:

| Property | Evidence |
|---|---|
| seal predicate = exact `?? $HANDBACK_SRC` | `:126-127` — and now **matched by all six doc locations**, so the documented handover is the one the script accepts |
| exact verdict parsing | `:191-198` (`VCOUNT == 1`, quoted exact strings, else `exit 3`) |
| manifest authenticated before parsing | `:51-52` precedes `manifest_get` at `:54` |
| neutral cwd | `:164`, the only `cd "` in the file |
| digests never in the prompt | heredoc `:141-161` — `HB_SHA`/`EXPECTED_MANIFEST_SHA`/`MANIFEST_SHA` count **0** |

**All nine manifest digests re-derived (line-anchored) against current bytes:**

| Pin | Value | Match |
|---|---|---|
| `bridge_final_sha256` | `dafd4719462aafae53a9e907f97c85911f6e80d7ba415de3a00e38720ddfc9e8` (unchanged — script untouched) | ✓ |
| `brief_sha256` | **`fe5e2b33539116079642a1c1bd45b4fb14a5f6657dc647ce8a4c4e8f968fa0b5`** (refreshed for r18) | ✓ |
| `harness_sha256` | `6086d86d…8c15` | ✓ |
| six lens contracts | `7e1c3c7c…` `7f7691e8…` `408258d0…` `419ac208…` `278596db…` `9e2e444a…` | ✓ |

Existing package contracts (P1/P2 acceptance blocks, P1 tamper 1–5, P3 census-derived acceptance, promotion steps 0/2/3/4a/5) unchanged and runnable; no mock-of-subject anywhere.

**PASS.**

---

## Check 4 — Behavior/repo claims cite source

Re-derived: `ci.yml:3-8` (push[main] / pull_request[main,dev] / workflow_dispatch), `ci.yml:1104` (`needs` incl. `frontend-lint`), `StockAdjustmentService.php:1715/:1716/:1719`, plus the census sources above.

**Conjunction check (house law).** The decisive edge this round: **documented seal rule ↔ shipped predicate**. Both sides opened — brief `:451` names `?? <handback-source>`; script `:126` builds `"?? ${HANDBACK_SRC}"` and compares with `==` at `:127`. The edge holds, and the five prose restatements are consistent with it rather than merely co-present. Previously verified edges (manifest `progress_path` ↔ script field-check ↔ real files; `--expected-manifest-sha256` ↔ `control_manifest` pin ↔ P3-M0) are unchanged.

Owner-attested external facts (`default_workflow_permissions: read`; `owner.type: "User"`) recorded as attested, not re-derived.

**PASS.**

---

## Check 5 — Permission keys

`grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule"` over brief + three YAMLs + the manifest → **no output, exit 1**. **N/A — PASS.**

---

## Hygiene

- **Banner ↔ ordering.** `:4` = r18 / 2026-08-14, single well-formed sentence; log anchors monotonic to **`:105` r18 (last)** ✓.
- **YAML headers.** All three at **r18**, round-0 fix list *"R0-3/R0-4 + r7-R0-1 + r9-R0-1 + r11-R0-1 + r13-R0-1 + r17-R0-1"*, tail *"…the 5 gate-r8 findings, and the 6 gate-r9 findings; re-gate before dispatch"*. Counts **17 / 11 / 6 / 7 / 7 / 6 / 4 / 5 / 6** — unchanged, and gate-r9 re-derived from its register = **6** ✓.
- **Pipes.** Four tables at r18 offsets — `:116-120` (4) · `:151-158` (3) · `:176-183` (2) · `:199-204` (3); every row matches its header ✓.
- **YAML shape.** All three parse; **P1 [M0-M3] · P2 [M0-M4] · P3 [M0-M3]**; milestone-level `owner_gate:` = **0/0/0**; pin sets complete, all `null`, no extra/missing keys. Manifest parses with `progress_path` on all three packages ✓.
- **Stale sweeps.** porcelain-empty seal rule → 1 historical ✓ · `<admin-tip>` → 4, brief-only (historical + §5 negation) ✓ · "allocated at promotion" → 0 ✓ · "sha256 of the verbatim check output" → 1 historical ✓.

**PASS.**

---

## Note-only observations (not findings)

1. **Methodology — fourth near-miss from a paraphrased grep.** My direction-2 sweep initially searched for `handback-source|EXACTLY ONE dirty|exactly one dirty` and returned **no YAML/LEDGER hits**, which would have read as "the substitution never landed". The actual wording is *"showing EXACTLY ONE entry — the untracked handback"*. Reading the six locations verbatim showed all six correct. Recorded because the same class (r15 "TRUSTED CONTROL INPUTS", r16 four false ✗, r17 the unanchored `brief_sha256` grep) has now nearly produced a false finding four times — the standing rule is: **read the target text before concluding from a pattern that returns nothing.**
2. **The `?? <handback-source>` predicate is path-exact and single-entry**, so a handover that also leaves, say, an editor swap file untracked will fail the seal. That is the intended fail-closed behaviour and is stated in locations 2 and 6 ("anything else dirty … is rejected"); noted so the gate reviewer reads a future seal failure as designed, not as a defect.
3. **Both artifacts remain untracked**; F-9 and the manifest header carry the commit-before-dispatch obligation, and `brief_sha256` will move again on the next brief edit (it moved this round exactly as required).
4. **The 7-vs-6 lens glob** remains a conservative superset (`imports-reviewer.md` unpinned, unused).
5. **The three-attempt tool-error bound** lives in the brief, not the script.
6. Standard carried notes: TreasuryReceiptBridge directory; Arabic-authored entries in the `i18n.ts` alias ranges; `wave3-3c-3d` M3 pending; `expectedAccountType()` at `:187` vs the hedged `:~186`; two owner-attested external facts.
7. **Fourth consecutive clean supersession round** for the legacy sweeps, and the first time this round's *own* substitution class came back clean on the immediate re-run in **both** directions.

---

## Evidence appendix (raw outputs)

```
$ wc -l
487 brief (r17 485 → +2) · 185 p1 (=) · 189 p2 (=) · 191 p3 (=) · 105 LEDGER (=)
 49 enforcement-control-manifest.yaml (=) · 198 adversarial-review-final.sh (=, byte-identical)

$ ls -lT
Aug 14 11:14:42 brief, manifest · Aug 14 11:14:26 LEDGER · Aug 14 11:04:42 script (UNCHANGED from r17)

--- brief section offsets (r17 → r18): uniform +2, no span changed ---
Executor 105→107 · SEQ 110→112 · EXEC 124→126 · §0 147→149 · §1 160→162 · §2 187→189
§3 280→282 · §4 377→379 · §5 434→436 · §6 460→462 · §7 474→476

--- (a) SWEEP 1: superseded empty-tree rule ---
grep 'porcelain` empty|porcelain must be empty|copies the handback out|copies out|
      copies the handback to its immutable'
  → brief:91 (r16 log) ONLY.  p1=0 p2=0 p3=0 LEDGER=0

--- (a) SWEEP 2: one-dirty-entry rule, read verbatim at all six ---
brief:451 (ii)  "…shows EXACTLY ONE entry — `?? <handback-source>`, the untracked handback (gate-r9 R9-H-2…"
brief:127 EXEC  "HAND OVER with `git status --porcelain` showing EXACTLY ONE entry — your untracked
                 handback file (gate-r9 R9-H-2; the parent's bridge copies+hashes it itself; anything
                 else dirty, or a tracked-modified handback, is rejected)."
p1 M3 / p2 M4 / p3 M3  "hand over with git status --porcelain showing EXACTLY ONE entry — the untracked
                 handback (the bridge copies+hashes it itself…"
LEDGER:56       "one-dirty-entry handover: status shows exactly the untracked handback, which the bridge
                 copies+hashes itself (gate-r9 R9-H-2)"
⇒ 6/6 present · 0 survivors

--- (b) consistency with the shipped predicate ---
script:126  EXPECTED_STATUS="?? ${HANDBACK_SRC}"
script:127  [[ "$STATUS" == "$EXPECTED_STATUS" ]] || { … exit 3; }
brief:451   names the literal string `?? <handback-source>`          ⇒ docs ≡ executable

--- (c) banner / log ordering / r18 entry ---
:4 r18 — 2026-08-14, round-0 r17 R0-1 fix … (single well-formed sentence)
log anchors … :84 r15 · :90 r16 · :97 r17 · :105 r18 (LAST)
r18 entry names the six locations and closes with "No other text changed."

--- (d) headers / counts ---
line5 (all three): "(r18 … round-0 R0-3/R0-4 + r7-R0-1 + r9-R0-1 + r11-R0-1 + r13-R0-1 + r17-R0-1,
  … the 5 gate-r8 findings, and the 6 gate-r9 findings; re-gate before dispatch)"
counts 17/11/6/7/7/6/4/5/6 · gate-r9 register grep → 6

--- (e) manifest digests re-derived (line-anchored) ---
bridge_final ✓ dafd4719462aafae53a9e907f97c85911f6e80d7ba415de3a00e38720ddfc9e8  (unchanged)
brief        ✓ fe5e2b33539116079642a1c1bd45b4fb14a5f6657dc647ce8a4c4e8f968fa0b5  (REFRESHED for r18)
harness      ✓ 6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
6 lenses     ✓ 7e1c3c7c… 7f7691e8… 408258d0… 419ac208… 278596db… 9e2e444a…

--- js-yaml ---
p1 [M0..M3] · p2 [M0..M4] · p3 [M0..M3] · og=0/0/0 · missing=[] · extra=[] · all pins null
manifest: p1(M3,pp=y) p2(M4,pp=y) p3(M3,pp=y) · lenses=6

--- tables --- :116-120 4×5 · :151-158 3×8 · :176-183 2×8 · :199-204 3×6   consistent
--- check 5 --- brief + 3 YAMLs + manifest → no output, exit 1
--- legacy sweeps --- admin-tip: brief 4 (historical+negation), YAMLs/LEDGER 0 ·
    "allocated at promotion" 0 everywhere · "sha256 of the verbatim check output" brief 1 (historical)
--- script --- bash -n OK; executable
--- repo --- ci.yml:3-8 · :1104 · SAS:1715/:1716/:1719 · vitest-L=0 · failOnEmpty=0 · PF=4/16
             cases=41 · :629→93 · :726→16
```

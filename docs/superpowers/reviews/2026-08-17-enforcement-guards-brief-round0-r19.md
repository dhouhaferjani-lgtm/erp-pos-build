# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r19 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-17

Scope: the brief at r19 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15 + three control artifacts, two of which changed this round: **`scripts/adversarial-review-final.sh` (REWRITTEN, 198 → 243 lines)**, `docs/handoff/enforcement-control-manifest.yaml` (digests refreshed), and the **new receipt template shipped OUTSIDE the repo** at `~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/TEMPLATE.receipt.yaml`. `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca` — unchanged across the series.

Run in FULL from the top. **Artifacts exercised**: the rewritten bridge's verdict-finality parser, control/surrogate preflight, path guards and full field-check python were executed against adversarial inputs (the field-check via a purpose-built venv, since this host lacks PyYAML — see note 1); all nine manifest digests re-derived.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 0 | Diff-scope of the r19 edit    | **PASS** | 0 |
| 1 | Revision-log truth (7 gate-r10 claims) | **PASS** | 0 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable **(artifacts exercised)** | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 |
| H | Hygiene (pipes, banner + ordering, header counts, YAML shape, stale sweeps) | **PASS** | 0 |

**VERDICT: PASS — all rows. r19 is round-0 clean and gate-round-11 ready.** No findings. Headline mechanical results: the receipt template exists **outside the repo** with the exact schema; `--base`/`--manifest`/`--expected-manifest-sha256` are **gone from the parser** and the documented invocation's argument set is **identical to the on-disk parser's**; the regex fallback is **deleted** and the complete field-check rejects all four violation classes when exercised; verdict-finality rejects `VERDICT: ACCEPT` + trailing correction; and **all nine manifest digests re-derive** against the rewritten bridge, the r19 brief and the whitespace-corrected harness.

---

## Check 0 — Diff-scope

**Brief:** 487 → **496** (+9). Every section anchor shifted uniformly **+9** — Executor 107→116 · SEQUENCING 112→121 · EXECUTION MODE 126→135 · §0 149→158 · §1 162→171 · §2 189→198 · §3 282→291 · §4 379→388 · §5 436→445 · §6 462→471 · §7 476→485. **No inter-section span changed**, so the entire +9 is the r19 log block (header + 7 finding bullets + blank) and every operative edit — §5 step 1's r19-bindings, step 3's schema line, step 4a, step 5's read-back, F-9 — is in-line.

**YAMLs:** 185→**186**, 189→**190**, 191→**192** (+1 each) — the structured `control_manifest: {path, sha256}` comment expansion; js-yaml confirms no key changes.

**LEDGER:** 105 → **105**, in place. **Manifest:** 49 → **49** (digests refreshed in place). **Bridge:** 198 → **243** (rewritten). **Harness:** 87 lines, trailing-whitespace corrected (R10-M-1).

**New artifact:** `TEMPLATE.receipt.yaml` (1274 B) created at the named out-of-repo location.

**Check 0: PASS.**

---

## Check 1 — Revision-log truth

| Claim | Re-derived verification | OK |
|---|---|---|
| **R10-C-1** PARENT DISPATCH RECEIPT | **Template exists OUTSIDE the repo** at `~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/TEMPLATE.receipt.yaml`; js-yaml parse yields exactly the required keys: `package, base_sha, manifest_path, manifest_sha256, progress_path, final_milestone, lenses, max_fix_rounds, created` — with `manifest_path` given as a canonical **absolute** path. **Bridge interface**: parser now takes `--receipt` (`:30`); `--base`, `--manifest`, `--expected-manifest-sha256` are **absent from the parser** (grep of the case block → **0**). `receipt_get` loads `package`/`base_sha`/`manifest_path`/`manifest_sha256` (`:74-84`), package equality enforced (`:75`), `manifest_path` must be absolute and exist (`:79-80`). **Range preflight** rejects the six control files **and** `*control-manifest*`/`*control_manifest*` surrogates (`:167-176`) — exercised below. **Named in**: §5 step 1 r19-bindings (`:460`), F-9(ii) (`:480`), LEDGER S-14. | ✓ |
| **R10-H-1** PyYAML fail-closed, fallback deleted | Startup assert `python3 -c 'import yaml' … || exit 3` with the `pip3 install pyyaml` message (`:51`). **`except Exception` count → 0; no `import re`** — the partial fallback is gone. The field-check now requires: `base_sha == receipt base`, top-level `max_fix_rounds == projection`, structured `control_manifest {path,sha256} == receipt`, `milestones` is a list, **exactly one** final milestone, `status == review`, `fix_rounds <= max` (`:129-152`). F-9 lists **PyYAML as a parent-host prerequisite**. All predicates exercised (Check 3). | ✓ |
| **R10-H-2** `manifest_sha256` in the tag chain | **Step-3 schema, line 2 exactly**: *"line 1 `accepted_sha: <A>`; **line 2 `manifest_sha256: <hex>`** (the dispatch manifest's digest — gate-r10 R10-H-2); `register_sha256: …`; `handback_sha256: …`; final lines `control_sha256: …`"* (`:462`). Present in **step-4a** exact-annotation re-verify (`:463`), **LEDGER S-14**, the closing-receipt digests payload (via "the annotation payload"), and **P3-M0 1d** (`p3:42-43`: *"its `control_sha256`/`manifest_sha256` lines MATCH P1's dispatch manifest at base"*). **`control_manifest` is structured `{path: <canonical absolute manifest path>, sha256: <hex>}` in all three YAML comments** (verified individually). | ✓ |
| **R10-H-3** verdict must be the final non-empty line | Script `:236-238`: `LAST_NONEMPTY="$(grep -v '^[[:space:]]*$' "$OUT" | tail -1)"` then `[[ "$VERDICT_LINE" == "$LAST_NONEMPTY" ]] || … exit 3`. **Exercised**: `VERDICT: ACCEPT\nCorrection: blocker` → **exit 3 NOT FINAL** ✓ (the exact R10-H-3 case), while trailing blank lines still ACCEPT. | ✓ |
| **R10-H-4** abspath canonicalization before side effects | `abspath()` (`:48`) applied to receipt/candidate/handback/out at `:54-59`, **before** the receipt is read or anything is copied; existence checks on receipt (`-f`) and candidate (`-d`); `case "$HANDBACK"/"$OUT" in "$CANDIDATE"/*)` → reject (`:58-59`); `manifest_path` absoluteness enforced (`:79`). **§5 step 1 names every argument** — see the conjunction in Check 4. | ✓ |
| **R10-M-1** harness whitespace + all pins re-derived | `od -c` on the harness tail shows `g a t e s . \n` — **single trailing newline**, no blank line. **All nine manifest pins re-derive** (table in Check 3), including `harness_sha256` back to `6086d86d…` (the drift R10-M-1 flagged is reverted, so the pin matches the on-disk bytes again). | ✓ |
| **R10-M-2** universal close-tag read-back | Step 5 (`:464`): *"the PARENT, after **EVERY** close-tag push **including P3's no-workflow path**, re-fetches the tag and verifies `^{commit} == C` plus exactly one each of `closing_commit == C`, `closing_check == pass`, `accepted_sha == A`"*. | ✓ |

### Earlier-revision spot-checks

r18's six-location one-dirty-entry rule intact (`porcelain` empty` → 1 brief-only historical hit); `<admin-tip>` → 4, brief-only (historical + §5 negation); "allocated at promotion" → 0. The `--expected-manifest-sha256`/`--base` sweep returns **3 hits, all revision-log entries** (brief `:98`, `:101`, `:108`) — zero operative, zero in the LEDGER.

**Check 1: PASS.**

---

## Check 2 — Exhaustive claims / censuses (re-run)

| Inventory claim | Census | Documented | Re-derived | Match |
|---|---|---|---|---|
| pgsql allowlist "93 class names" (`ci.yml:629`) | `tr '\|' '\n' \| grep -c Test` | 93 | **93** | ✓ |
| Architecture ParserFactory "4 of 16" | `grep -l` / `ls` | 4 / 16 | **4 / 16** | ✓ |
| "complete 41-case partition" | `grep -cE '^    case '` | 41 | **41** | ✓ |
| tools tests all import `vitest`; no `failOnEmptyTestSuite` | `grep` | as stated | **0 / 0** | ✓ |
| `frontend-lint` ∈ `all-checks-pass` `needs` | `sed -n '1104p'` | present | **present** | ✓ |
| manifest: 6 lens contracts, 3 package blocks with `progress_path` | js-yaml | 6 / 3 | **6 / 3 (all `pp=y`)** | ✓ |
| **receipt template schema (8 fields + package)** | js-yaml | 9 keys | **exactly the 9 named** | ✓ |

**PASS.**

---

## Check 3 — Test contracts executable (artifacts exercised)

`bash -n` → **OK**; executable; 243 lines.

### Exercised behaviours

**Verdict finality + exactness (R10-H-3 / R9-H-4):**
```
ACCEPT only                                 -> exit0 ACCEPT
ACCEPT + trailing correction  (R10-H-3)     -> exit3 NOT FINAL      ← the gate's exact case
ACCEPT + trailing blank lines only          -> exit0 ACCEPT          (blank-line-tolerant, correct)
CHANGES-REQUIRED final                      -> exit2
VERDICT: NOT ACCEPT                         -> exit3 non-exact
two verdict lines / no verdict              -> exit3 (VCOUNT 2 / 0)
```

**Control + surrogate preflight (R10-C-1 tail):**
```
apps/api/tests/Architecture/…GuardTest.php            -> allow
scripts/adversarial-review-final.sh                   -> REJECT control
docs/handoff/enforcement-control-manifest.yaml        -> REJECT control
docs/handoff/enforcement-control-manifest-p1.yaml     -> REJECT surrogate   ← R10-C-1's attack path
docs/handoff/progress/my_control_manifest.yaml        -> REJECT surrogate
.claude/agents/treasury-reviewer.md                   -> REJECT control
docs/handoff/HANDBACK-enforcement-p1-2026-08-12.md    -> allow
```

**Path guards (R10-H-4):** a candidate-relative handback dest (`docs/handoff/HANDBACK-x.md`) canonicalizes inside the candidate → **REJECT**; `/tmp/parent-ws/hb.md` and `../erp-parent/hb.md` → allow.

**Field-check python (R10-H-1)** — run in a venv with PyYAML, against the real P1 YAML and synthetic cases:
```
real p1 YAML (control_manifest still null)  -> YAML FIELD FAIL (fail-closed) ✓
conforming                                  -> FIELD-CHECK PASS
status: pending  (harness stop condition)   -> FAIL: status pending != review   ← R10-H-1's scenario
fix_rounds 6 > max 5                        -> FAIL: fix_rounds exceeds max
control_manifest sha mismatch (surrogate)   -> FAIL: control_manifest {path,sha256} != receipt
top-level max_fix_rounds drift (9 vs 5)     -> FAIL: != manifest projection
```
Every predicate the gate demanded is present **and rejecting**; the r18 fallback that silently skipped them is gone.

**All nine manifest digests re-derived (line-anchored) against current bytes:**

| Pin | Value | Match |
|---|---|---|
| `bridge_final_sha256` | `8300703a34fe675884f0f29f8f6c6f7e2af6d9680db2e9e8df174c9831a69961` (refreshed for the rewrite) | ✓ |
| `brief_sha256` | `0e4caf48b90eb9b1a4dfa08c43254e475b6f99b0261f707ce3c56b9637e20b31` (refreshed for r19) | ✓ |
| `harness_sha256` | `6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15` (drift reverted — R10-M-1) | ✓ |
| six lens contracts | `7e1c3c7c…` `7f7691e8…` `408258d0…` `419ac208…` `278596db…` `9e2e444a…` | ✓ |

Existing package contracts (P1/P2 acceptance blocks, P1 tamper 1–5, P3 census-derived acceptance, promotion steps 0/2/3/4a/5) unchanged and runnable; **no contract asserting through a mock of its subject**.

**PASS.**

---

## Check 4 — Behavior/repo claims cite source

Re-derived: `ci.yml:3-8`, `ci.yml:1104`, `StockAdjustmentService.php:1715/:1716/:1719`, plus the Check-2 censuses.

**Conjunction checks (house law).** (a) **Argument surface — the decisive new edge:** the script's parser accepts exactly `--accepted --attempt --candidate --handback --handback-source --out --package --receipt --round`; §5 step 1 names exactly `--accepted --attempt --candidate --handback --handback-source --out --package --receipt --round`. **Identical sets, nine each — no drift, nothing documented that the parser rejects, nothing accepted that the doc omits.** (b) receipt template schema ↔ `receipt_get` keys ↔ the field-check's use of them — all four consumed keys (`package`, `base_sha`, `manifest_path`, `manifest_sha256`) exist in the template. (c) manifest `progress_path` ↔ `"$CANDIDATE/$PROGRESS_PATH"` ↔ the three real files. (d) `manifest_sha256` producer (step-3 line 2) ↔ step-4a re-verify ↔ P3-M0 1d consumer — the chain gate-r10 R10-H-2 said was open now closes. (e) `control_manifest {path,sha256}` structured comment ↔ the field-check's `isinstance(cm, dict)` requirement.

**Owner-attested external facts** (`default_workflow_permissions: read`; `owner.type: "User"`) recorded as attested, not re-derived.

**PASS.**

---

## Check 5 — Permission keys

`grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule"` over brief + three YAMLs + manifest + **the receipt template** → **no output, exit 1**. **N/A — PASS.**

---

## Hygiene

- **Banner ↔ ordering.** `:4` = *"r19 — **2026-08-17**, full gate-r10 fix round applied (…gate-r10.md: R10-C-1, R10-H-1..4, R10-M-1..2 …; the bridge is now RECEIPT-DRIVEN and a dispatch-receipt template ships…)"*, → **gate round 11**. Log anchors monotonic to **`:107` r19 (last)** ✓.
- **YAML headers.** All three at **r19**, tail *"…the 6 gate-r9 findings, **and the 7 gate-r10 findings**; re-gate before dispatch)"*. Counts re-derived from the registers: **17 / 11 / 6 / 7 / 7 / 6 / 4 / 5 / 6 / 7**; gate-r10 `grep -cE '^### R10-…'` → **7**, IDs `R10-C-1 R10-H-1..4 R10-M-1..2` = **1C+4H+2M** ✓.
- **Pipes.** Four tables at r19 offsets — `:125-129` (4) · `:160-167` (3) · `:185-192` (2) · `:208-213` (3); every row matches its header ✓.
- **YAML shape.** All three parse; **P1 [M0-M3] · P2 [M0-M4] · P3 [M0-M3]**; milestone-level `owner_gate:` = **0/0/0**; all pins `null` including the new `control_manifest`. Manifest and receipt template both parse ✓.
- **Stale sweeps.** `--expected-manifest-sha256`/`--base` → 3, all revision-log ✓ · unstructured `control_manifest` free-text → none (the three schema comments are structured `{path, sha256}`; the bare `control_manifest: null` lines are the un-filled pins) ✓ · `<admin-tip>` → 4 brief-only historical ✓ · "allocated at promotion" → 0 ✓ · porcelain-empty → 1 brief historical ✓.

**PASS.**

---

## Note-only observations (not findings)

1. **PyYAML is absent on this host**, so the bridge's startup assert would fire — which is the **designed** behaviour per R10-H-1, not a defect. To exercise the field-check I built a throwaway venv with PyYAML and ran the block verbatim; results in Check 3. Recorded so the gate reviewer knows the field-check was executed, not merely read, and that **F-9's PyYAML prerequisite is a real parent-host action item** before dispatch on this machine.
2. **§5 step 1's invocation is an inline enumeration, not a fenced code block.** It names all nine arguments and the set matches the parser exactly (Check 4a), so the executable contract is unambiguous; a fenced copy-paste template would be a convenience, not a correctness gain.
3. **`control_manifest` is `null` in all three YAMLs** — the un-filled pin. F-9(iii) makes non-null an M0 precondition, and the field-check fails closed on `null` (exercised). Correct pre-dispatch state.
4. **Only `TEMPLATE.receipt.yaml` exists** — the three per-package receipts (`enforcement-p{1,2,3}.receipt.yaml`) are dispatch-time artifacts owned by F-9(ii), with placeholder values in the template. Correct: the template ships, the instances are created at dispatch.
5. **Both repo artifacts remain untracked**; F-9 carries the commit-before-dispatch obligation, and `brief_sha256`/`bridge_final_sha256` will move again on any further edit (both moved correctly this round).
6. **The 7-vs-6 lens glob** remains a conservative superset; **the three-attempt tool-error bound** remains parent-owned in the brief, not the script.
7. Standard carried notes: TreasuryReceiptBridge directory; Arabic-authored entries in the `i18n.ts` alias ranges; `wave3-3c-3d` M3 pending; `expectedAccountType()` at `:187` vs the hedged `:~186`; two owner-attested external facts.
8. **Fifth consecutive clean supersession round** for the legacy sweeps, and this round's two new sweeps (dropped bridge args; unstructured `control_manifest`) were clean immediately.

---

## Evidence appendix (raw outputs)

```
$ wc -l
496 brief (r18 487 → +9) · 186 p1 (+1) · 190 p2 (+1) · 192 p3 (+1) · 105 LEDGER (=)
 49 manifest (=, digests refreshed) · 243 adversarial-review-final.sh (198 → REWRITTEN) · 87 harness
$ ls ~/.claude/projects/-Users-houssamr-…/memory/dispatch-receipts/
-rw-r--r--  1274  Aug 17 18:02  TEMPLATE.receipt.yaml            ← OUTSIDE the repo ✓

--- brief section offsets (r18 → r19): uniform +9, no span changed ---
Executor 107→116 · SEQ 112→121 · EXEC 126→135 · §0 149→158 · §1 162→171 · §2 189→198
§3 282→291 · §4 379→388 · §5 436→445 · §6 462→471 · §7 476→485

--- receipt template (js-yaml) ---
keys = package, base_sha, manifest_path, manifest_sha256, progress_path, final_milestone,
       lenses, max_fix_rounds, created
manifest_path given as canonical ABSOLUTE path

--- bridge parser: dropped args ---
grep -cE '\-\-base\)|\-\-manifest\)|\-\-expected-manifest-sha256\)' over the case block -> 0
parser accepts: --receipt --package --candidate --accepted --handback-source --handback
                --round --attempt --out              (9)
brief §5 step 1 names:  the same 9, identical set    ⇒ no drift

--- R10-H-1 ---
:51  python3 -c 'import yaml' || { "PyYAML required on the parent host (pip3 install pyyaml)"; exit 3 }
grep -c 'except Exception' -> 0 ; 'import re' -> none          (fallback DELETED)
field-check exercised (venv+PyYAML):
  real p1 YAML                     -> YAML FIELD FAIL (control_manifest null) — fail closed
  conforming                       -> FIELD-CHECK PASS
  status pending                   -> FAIL: M3 status pending != review
  fix_rounds 6 > 5                 -> FAIL: fix_rounds exceeds max
  control_manifest sha mismatch    -> FAIL: {path,sha256} != dispatch receipt
  top-level max_fix_rounds 9 vs 5  -> FAIL: != manifest projection

--- R10-H-3 verdict finality (exercised) ---
ACCEPT only ->0 · ACCEPT+correction ->3 NOT FINAL · ACCEPT+blank lines ->0 ·
CHANGES-REQUIRED ->2 · NOT ACCEPT ->3 · 2 verdict lines ->3 · 0 verdict lines ->3

--- R10-C-1 preflight (exercised) ---
control files -> REJECT · enforcement-control-manifest-p1.yaml -> REJECT surrogate ·
my_control_manifest.yaml -> REJECT surrogate · guard test / HANDBACK -> allow

--- R10-H-4 path guards (exercised) ---
docs/handoff/HANDBACK-x.md -> REJECT inside candidate · /tmp/parent-ws/hb.md -> allow

--- R10-H-2 ---
:462 step-3 schema: "line 1 accepted_sha: <A>; line 2 manifest_sha256: <hex> (the dispatch
     manifest's digest — gate-r10 R10-H-2); register_sha256: …; handback_sha256: …;
     final lines control_sha256: …"
:463 step-4a exact-annotation re-verify · LEDGER S-14 · p3:42-43 P3-M0 1d comparison
control_manifest comment = {path: <canonical absolute manifest path>, sha256: <hex>} in p1/p2/p3

--- R10-M-2 ---
:464 "…the PARENT, after EVERY close-tag push including P3's no-workflow path, re-fetches the tag
      and verifies ^{commit} == C plus exactly one each of closing_commit == C,
      closing_check == pass, accepted_sha == A"

--- R10-M-1 ---
harness tail (od -c): … g a t e s .  \n        (single trailing newline)
harness_sha256 pin == on-disk bytes (6086d86d…)

--- manifest digests (9/9 re-derived) ---
bridge_final 8300703a34fe675884f0f29f8f6c6f7e2af6d9680db2e9e8df174c9831a69961  (rewrite)
brief        0e4caf48b90eb9b1a4dfa08c43254e475b6f99b0261f707ce3c56b9637e20b31  (r19)
harness      6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
lenses       7e1c3c7c… 7f7691e8… 408258d0… 419ac208… 278596db… 9e2e444a…

--- hygiene ---
banner :4 r19 / 2026-08-17 / "gate round 11" · log anchors … :105 r18 · :107 r19 (LAST)
line5 (all three): "(r19 … + r17-R0-1, … the 6 gate-r9 findings, and the 7 gate-r10 findings; …)"
gate-r10 register -> 7 (R10-C-1, R10-H-1..4, R10-M-1..2)
tables :125-129 4×5 · :160-167 3×8 · :185-192 2×8 · :208-213 3×6   consistent
js-yaml: p1 [M0..M3] · p2 [M0..M4] · p3 [M0..M3] · og=0/0/0 · all pins null
check 5: brief + 3 YAMLs + manifest + receipt template -> no output, exit 1

--- stale sweeps ---
--expected-manifest-sha256 / --base -> brief:98, :101, :108 (all revision-log)   CLEAN
unstructured control_manifest       -> none (schema comments structured)         CLEAN
admin-tip -> brief 4 (historical+negation) · allocated-at-promotion -> 0 ·
porcelain-empty -> brief 1 (historical)                                          CLEAN

--- repo --- ci.yml:3-8 · :1104 · SAS:1715/:1716/:1719 · vitest-L=0 · failOnEmpty=0
             PF=4/16 · cases=41 · :629→93
```

# Round 0 report (FOCUSED) — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r20
Runner: opus subagent (round-0 focused) · Date: 2026-08-17

**Scope: focused, per the gate-r11 disposition** (*"Re-run round-0 only where the tag-consumer/control hashes change"*) — not the full five-check battery. Covered: (1) R11-H-1 tag-consumer split + ambiguous-"closing tag" sweep, (2) R11-M-1 bridge header + template placeholder, (3) R11-M-2 receipt↔manifest projection cross-check, (4) r20 revision-log truth, banner/headers, refreshed control hashes, `bash -n`, js-yaml parse. Checks 2/3/4/5 of the full battery were **not** re-run except where these areas touch them; the r19 full-battery report remains the standing evidence for the rest.

`git rev-parse HEAD` = `a5520f23c` (unchanged). Artifacts exercised where behaviour is claimed.

| # | Focused area | Result | Findings |
|---|---|---|---|
| 1 | R11-H-1 — two named tag predicates + ambiguity sweep | **FAIL** | 1 |
| 2 | R11-M-1 — bridge header + `<p1\|p2\|p3>` placeholder | **PASS** | 0 |
| 3 | R11-M-2 — receipt↔manifest projection cross-check | **PASS** | 0 |
| 4 | Revision-log truth · banner · headers · hashes · `bash -n` · parse | **PASS** | 0 |

**VERDICT: FAIL — one FAIL row (area 1). r20 does not pass the focused round 0; fix R0-1 and re-run the focused checks before the owner rules on close-out vs gate round 12.**

R11-H-1's repair landed correctly in the three locations the gate named (P3-M0 header checks 1d/2d, the brief sequencing-table P3 row, the §4 milestones line) — but **not in the P3-M0 milestone title**, which still describes a single "closing tag" carrying the A-tag predicates. That is exactly the conflation R11-H-1 was raised to remove, surviving in operative text.

---

## Findings

- **[R0-1] Area 1 · HIGH — R11-H-1's PIN/CLOSE separation did not reach the P3-M0 milestone title, which still names one "closing tag" doing both jobs.**

  The repair is correct and complete in the three named locations:
  - **P3-M0 header check 1d** (`enforcement-p3.progress.yaml:29-47`) — *"EVIDENCE BINDING — **TWO SEPARATELY NAMED TAG PREDICATES** (gate-r11 R11-H-1)"*, then **d1. PIN TAG at A** (resolves to `p1_landed_sha`; `accepted_sha`; **exactly one** `manifest_sha256` matching P1's dispatch manifest at base; landed register/handback digests; *"the `control_sha256` name set is exactly what the bridge emits — adversarial-review-final.sh, brief, **HARNESS**, and every package lens"*) and **d2. CLOSE TAG at C** (derived first-parent child, `diff-tree`, base blobs == C's blobs, resolves to C, annotation **EXACTLY** `{closing_commit == C, closing_check == pass, accepted_sha}` — *"the close tag carries **NO manifest/control lines**; those live on the PIN tag only"*). ✓
  - **2d** (`:56-64`) — the same two-predicate shape for P2. ✓
  - **Brief sequencing-table P3 row** — *"PIN tag at A carries manifest/control/digest lines, the CLOSE tag at C carries exactly {closing_commit, closing_check, accepted_sha}"*. ✓
  - **§4 milestones line** — *"PIN tag at A (manifest_sha256 + register/handback digests == landed bytes + control-name set) and CLOSE tag at C (derived first-parent child, conforming diff-tree, base blobs == …)"*. ✓

  **But the P3-M0 milestone title (`enforcement-p3.progress.yaml:165`) still reads:**
  ```
  … EVIDENCE BINDING hardened gate-r6 R6-H-1/R6-H-2: P1's closing-tag annotation
  (A == landed tip; sha256(landed register) == digest; sha256(landed handback) == handback_sha256)
  + the DERIVED closing commit C (first-parent child of p1_landed_sha, conforming diff-tree,
  base blobs == C's blobs); …
  … (2) P2 … EVIDENCE BINDING (incl. handback digest + derived C — gate-r6 R6-H-1/R6-H-2)
  via P2's closing tag; …
  ```
  This attributes **A-tag predicates** (`A == landed tip`, register digest, handback digest) to *"P1's **closing-tag** annotation"* — under the r20 schemas the close tag is the C-object holding only `{closing_commit, closing_check, accepted_sha}`. It also never mentions the pin tag, the `manifest_sha256` comparison, the control-name set, or the "no manifest/control lines on the close tag" rule, and it still cites only **gate-r6** rulings — it was not touched this round. An executor implementing the title literally reproduces R11-H-1's failure scenario: asking the C tag for fields it cannot contain, or silently dropping the manifest-identity comparison.

  Milestone titles are operative in this package (the brief calls the YAML *"your operative milestone list … the resume point if you crash"*), and three prior round-0 FAILs in this series (r9, r13, r17 R0-1) were exactly stale milestone-title summaries.

  **Repair:** rewrite the P3-M0 title's evidence-binding clauses for both P1 and P2 to name **d1 (PIN tag at A)** and **d2 (CLOSE tag at C)** as the header checks do, citing gate-r11 R11-H-1. Then re-run this focused round 0.

**Note-only (not the finding):** four other operative uses of the loose phrase "closing tag" remain, but each is **unambiguous by payload or cross-reference** and none names both objects:
- brief step-4a — *"re-fetch the closing tag verifying its target == **A** and its EXACT annotation bytes (incl. the `manifest_sha256:` line)"* → target A + manifest line = the pin tag, unambiguous;
- the three YAML universal notes (`p1:85`, `p2:71`, `p3:107`) — *"the ANNOTATED closing tag binding **{A, sha256(register(s)), sha256(handback), control_sha256 lines}**"* → the A payload, unambiguous;
- `p3:112` — *"P3's closing tag is PRE-ALLOCATED like P1/P2's (**p3_closing_pin_tag** above…)"* → explicit pin-tag cross-reference.

Renaming these to "pin tag" would remove the last naming looseness, but none is executable-contradicting, so they are not the finding.

---

## Area 2 — R11-M-1 (PASS)

**Bridge header** (`scripts/adversarial-review-final.sh:5-8`), rewritten for r20:
> *"Trust design (r20 — receipt-driven; header updated per gate-r11 R11-M-1): `--receipt` is the **SOLE base/manifest authority** … **There are NO `--base` / `--manifest` / `--expected-manifest-sha256` options.**"*

A grep of the header block for the removed options returns **only that negation sentence** — no instruction to supply them. ✓

**Brief template placeholder**: `--package <p1|p2|p3>` ✓. The old `<p_n_>` survives at exactly **one** location, brief `:118` — the r20 revision-log entry describing the fix (historical, permitted). ✓

---

## Area 3 — R11-M-2 (PASS)

The cross-check exists at `scripts/adversarial-review-final.sh:131-136`, after manifest authentication, iterating all four projection fields and failing closed:
```bash
# Receipt projection fields must EQUAL the authenticated manifest's package block (gate-r11 R11-M-2)
for pair in "progress_path=$PROGRESS_PATH" "final_milestone=$MILESTONE" "max_fix_rounds=$MAX_ROUNDS" "lenses=$LENSES"; do
  key="${pair%%=*}"; mval="${pair#*=}"
  … [[ "$rval" == "$mval" ]] || { echo "RECEIPT FAIL: receipt $key '$rval' != manifest '$mval' (stale receipt half — gate-r11 R11-M-2)"; exit 3; }
```
It reads the receipt side via `receipt_get`, per the claim. **Exercised** with the loop's verbatim logic:
```
conforming receipt                    -> PASS
WRONG LENS LIST (dropped a lens)      -> exit3  lenses 'stock-gl-interaction' != 'stock-gl-interaction,inventory-costing'
stale progress_path (P2 half)         -> exit3  progress_path '…enforcement-p2…' != '…enforcement-p1…'   ← the gate's exact scenario
wrong final_milestone (M4 vs M3)      -> exit3
wrong max_fix_rounds (9 vs 5)         -> exit3
```
The header also documents it (`:15-17`). ✓

---

## Area 4 — Revision-log truth, banner, headers, hashes, syntax, parse (PASS)

| Item | Verified |
|---|---|
| **r20 log, 3 claims** | `:117` R11-H-1 (two separately named tag predicates — **landed in the three named locations; the M0 title gap is R0-1**), `:118` R11-M-1 (header receipt-driven; placeholder `<p1\|p2\|p3>`), `:119` R11-M-2 (four fields cross-checked, mismatch = exit 3). Claims 2 and 3 fully true; claim 1 true at its three named locations. |
| **Banner** | `:4` = *"r20 — **2026-08-17**, full gate-r11 fix round applied (…gate-r11.md: R11-H-1, R11-M-1..2 …; **ZERO Criticals at r11, all seven gate-r10 findings RESOLVED**), **NOT yet re-gated**. Re-run round 0 (focused per the r11 disposition), then **the owner rules on close-out vs gate round 12**, before dispatch."* — the intentional next-step wording, present as described. ✓ |
| **Log ordering** | `:107` r19 · **`:116` r20 (LAST)** ✓ |
| **YAML headers** | All three at **r20**, ending *"…the 7 gate-r10 findings, **and the 3 gate-r11 findings**; re-gate before dispatch"* — counts …/7/**3** ✓. gate-r11 register re-derived: **3** (`R11-H-1 R11-M-1 R11-M-2` = 0C+1H+2M) ✓ |
| **Control hashes re-derived** | `bridge_final_sha256` = **`55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88`** ✓ (refreshed for the r20 header+loop edit); `brief_sha256` = **`a2bd2da5e7457fcdfda7b74494634b4939587a536e2f89ce479d753ab5405ee3`** ✓ (refreshed for r20); `harness_sha256` unchanged `6086d86d…` ✓ |
| **`bash -n`** | OK; script 243 → **254** lines (the R11-M-2 loop + header rewrite) |
| **js-yaml parse** | p1 [M0-M3] · p2 [M0-M4] · p3 [M0-M3], all ordered; **milestone-level `owner_gate:` = 0/0/0**; `control_manifest: null` in all three (correct pre-dispatch state, fail-closed at M0/F-9) ✓ |

---

## Evidence appendix

```
--- file sizes (r19 → r20) ---
brief 496 → 501 · p3 192 → 198 · script 243 → 254 · manifest 49 (=)

--- R11-H-1: repair present ---
p3:29-47   d. EVIDENCE BINDING — TWO SEPARATELY NAMED TAG PREDICATES (gate-r11 R11-H-1)
           d1. PIN TAG at A … EXACTLY ONE manifest_sha256 … control_sha256 name set … incl. HARNESS
           d2. CLOSE TAG at C … annotation EXACTLY {closing_commit == C, closing_check == pass,
               accepted_sha == p1_landed_sha} … "the close tag carries NO manifest/control lines;
               those live on the PIN tag only"
p3:56-64   same two-predicate shape for P2
brief sequencing P3 row: "PIN tag at A carries manifest/control/digest lines, the CLOSE tag at C
                          carries exactly {closing_commit, closing_check, accepted_sha}"
brief §4 milestones:     "PIN tag at A (manifest_sha256 + register/handback digests == landed bytes
                          + control-name set) and CLOSE tag at C (derived first-parent child, …)"

--- R0-1: the gap ---
p3:165 (M0 TITLE, operative, untouched this round — cites only gate-r6):
  "EVIDENCE BINDING hardened gate-r6 R6-H-1/R6-H-2: P1's closing-tag annotation (A == landed tip;
   sha256(landed register) == digest; sha256(landed handback) == handback_sha256) + the DERIVED
   closing commit C …"
  "(2) P2 … EVIDENCE BINDING (incl. handback digest + derived C — gate-r6 R6-H-1/R6-H-2) via P2's
   closing tag"
  ⇒ A-tag predicates attributed to "the closing tag"; no pin tag, no manifest_sha256 comparison,
    no control-name set, no "close tag carries no manifest/control lines"

--- other "closing tag" uses (unambiguous by payload/cross-ref — note-only) ---
brief step-4a "…target == A … incl. the manifest_sha256: line"        → pin tag
p1:85 / p2:71 / p3:107 "…binding {A, sha256(register(s)), sha256(handback), control_sha256 lines}" → pin tag
p3:112 "P3's closing tag is PRE-ALLOCATED like P1/P2's (p3_closing_pin_tag above…)"                → pin tag
brief :64,:70,:72,:79,:117 → revision-log entries (historical)

--- R11-M-1 ---
script:5-8  "Trust design (r20 …): --receipt is the SOLE base/manifest authority … There are NO
             --base / --manifest / --expected-manifest-sha256 options."
brief       --package <p1|p2|p3>        ;  '<p_n_>' survives only at brief:118 (r20 log entry)

--- R11-M-2 (exercised) ---
script:131-136 loop over progress_path / final_milestone / max_fix_rounds / lenses, receipt_get vs
               manifest package block, mismatch -> exit 3
conforming -> PASS · wrong lenses -> exit3 · stale P2 progress_path -> exit3 ·
wrong final_milestone -> exit3 · wrong max_fix_rounds -> exit3

--- area 4 ---
banner :4 r20 / 2026-08-17 / "the owner rules on close-out vs gate round 12"
log anchors :107 r19 · :116 r20 (LAST)
YAML headers r20 · "…the 7 gate-r10 findings, and the 3 gate-r11 findings…"
gate-r11 register -> 3 (R11-H-1, R11-M-1, R11-M-2)
bridge_final 55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88  ✓
brief        a2bd2da5e7457fcdfda7b74494634b4939587a536e2f89ce479d753ab5405ee3  ✓
harness      6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15  ✓ (unchanged)
bash -n OK · js-yaml: p1/p2/p3 parse, ordered milestones, owner_gate 0/0/0, control_manifest null
```

# Round 0 report (FOCUSED) — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r21
Runner: opus subagent (round-0 focused) · Date: 2026-08-17

**Scope: focused re-verify of the r20 R0-1 fix only** — (a) the P3-M0 title verbatim, (b) the ambiguity sweep, (c) diff-scope, (d) banner / last-block / headers / `brief_sha256` / P3 js-yaml parse. The r19 full-battery report remains the standing evidence for everything else; the r20 focused report covers R11-M-1/R11-M-2.

`git rev-parse HEAD` = `a5520f23c` (unchanged).

| # | Focused area | Result | Findings |
|---|---|---|---|
| a | P3-M0 title — two named predicates | **PASS** | 0 |
| b | Ambiguity sweep ("closing tag") | **PASS** | 0 |
| c | Diff-scope (nothing else changed) | **PASS** | 0 |
| d | Banner · last block · headers · `brief_sha256` · P3 parse | **PASS** | 0 |

**VERDICT: PASS — all rows. The r20 R0-1 is closed; r21 is focused-round-0 clean.** No findings.

---

## (a) P3-M0 title — verbatim

Both evidence-binding clauses now name the two predicates and cite `gate-r11 R11-H-1` with the header-check cross-reference:

> **P1 clause:** *"…an ancestor + top-level status ALONE IS INSUFFICIENT — PLUS the **TWO-PREDICATE EVIDENCE BINDING (gate-r11 R11-H-1, per header checks 1d1/1d2)**: **d1 = P1's PIN TAG at A** (resolves to `p1_landed_sha`; `accepted_sha`; **exactly one `manifest_sha256` matching the dispatch manifest**; landed register/handback digests; **control-name set incl. harness**) **AND d2 = P1's CLOSE TAG at C** (C = first-parent child of `p1_landed_sha`, conforming diff-tree, base blobs == C's blobs, tag resolves to C, annotation **EXACTLY** `{closing_commit, closing_check == pass, accepted_sha}` — **no manifest/control lines**)"*
>
> **P2 clause:** *"…an M2-passed snapshot ALONE IS INSUFFICIENT — PLUS the **same TWO-PREDICATE EVIDENCE BINDING via P2's PIN tag at A and CLOSE tag at C (gate-r11 R11-H-1, per header checks 2d1/2d2)**"*

Programmatic confirmation against the parsed title (8/8 markers): `TWO-PREDICATE EVIDENCE BINDING` ✓ · `gate-r11 R11-H-1` ✓ · `header checks 1d1/1d2` ✓ · `d1 = P1's PIN TAG at A` ✓ · `d2 = P1's CLOSE TAG at C` ✓ · `P2's PIN tag at A and CLOSE tag at C` ✓ · `header checks 2d1/2d2` ✓ · `no manifest/control lines` ✓.

**The r20 defect strings are gone**: a count of `"closing-tag annotation"` / `"via P2's closing tag"` inside the M0 title returns **0**. No A-tag payload (`A == landed tip`, register digest, handback digest) is attributed to a "closing tag" anywhere in the title, and the title now cites gate-r11 rather than only gate-r6.

---

## (b) Ambiguity sweep

`grep 'closing tag|closing-tag'` over brief + three YAMLs + LEDGER returns **11 hits**, all previously dispositioned and none executable-contradicting:

| Location | Disposition |
|---|---|
| brief `:64`, `:70`, `:72`, `:79`, `:117`, `:121` | revision-log entries (historical, incl. the r21 entry describing this fix) |
| brief `:470` step-4a | *"re-fetch the closing tag verifying its target == **A** and its EXACT annotation bytes (incl. the `manifest_sha256:` line)"* → target A + manifest line = the **pin tag**, unambiguous by payload |
| `p1:85`, `p2:71`, `p3:107` universal notes | *"the ANNOTATED closing tag binding **{A, sha256(register(s)), sha256(handback), control_sha256 lines}**"* → the A payload, unambiguous |
| `p3:112` | *"P3's closing tag is PRE-ALLOCATED like P1/P2's (**p3_closing_pin_tag** above…)"* → explicit pin-tag cross-reference |

**Zero hits in the P3-M0 title or the P3-M0 header checks.** As at r20, renaming the four benign uses to "pin tag" would remove the last naming looseness, but none names both objects or contradicts an executable predicate — flagged as note-only, not a finding.

---

## (c) Diff-scope

**Brief 501 → 503 (+2)**, and the +2 is exactly the r21 log entry: the log region reads `:107` r19 block → `:116` r20 block (bullets `:117-119`, blank `:120`) → **`:121` r21 entry, blank `:122`** → `:123` `**Executor:**`. Nothing inserted elsewhere.

**Measured against my own recorded baselines** (not remembered ones):

| File | r19 report | r20 report | r21 measured | Δ | Explained |
|---|---|---|---|---|---|
| brief | 496 | 501 | **503** | +2 | r21 log entry ✓ |
| p1 | 186 | *(not measured)* | **186** | 0 vs r19 | header replaced in line ✓ |
| p2 | 190 | *(not measured)* | **190** | 0 vs r19 | header replaced in line ✓ |
| p3 | 192 | 198 | **198** | 0 vs r20 | title fix is in-line ✓ |
| LEDGER | 105 | 105 | **105** | 0 | untouched ✓ |
| manifest | 49 | 49 | **49** | 0 | `brief_sha256` refreshed in line ✓ |
| script | 243 | 254 | **254** | 0 | untouched ✓ |

*(Methodology note: my first pass compared p1/p2 against an r20 baseline I had never measured — the r20 focused run only sized brief/p3/script/manifest. Re-anchoring on the r19 report's measured 186/190 shows both files unchanged. Recorded because asserting from an unmeasured baseline is the same class of error as asserting from an under-matching grep.)*

Everything the r21 log claims changed, changed; nothing else did.

---

## (d) Banner · last block · headers · hash · parse

- **Banner `:4`** = *"r21 — **2026-08-17**, round-0 r20 R0-1 fix applied on top of the full gate-r11 fix round (…gate-r11.md: R11-H-1, R11-M-1..2 — itemized log below…)"* ✓
- **Log ordering**: `:116` r20 · **`:121` r21 (LAST)** ✓
- **YAML headers**: all three at **r21**, round-0 fix list now *"… + r13-R0-1 + r17-R0-1 **+ r20-R0-1**,"*, tail *"…and the 3 gate-r11 findings; re-gate before dispatch"* ✓
- **`brief_sha256` refreshed and re-derives**: `20eb6a8e67137647c0c24fba924be88ab03e16445eec755ab7017976c25f36b5` ✓; `bridge_final_sha256` correctly **unchanged** at `55ca88b0…` (script untouched) ✓
- **P3 js-yaml parse**: `[M0,M1,M2,M3]` ordered ✓ · milestone-level `owner_gate:` = **0** ✓ · `control_manifest: null` and `p3_closing_pin_tag: null` (correct pre-dispatch state) ✓

---

## Evidence appendix

```
--- (a) P3-M0 title markers (parsed title, 8/8) ---
TWO-PREDICATE EVIDENCE BINDING ✓ · gate-r11 R11-H-1 ✓ · header checks 1d1/1d2 ✓
d1 = P1's PIN TAG at A ✓ · d2 = P1's CLOSE TAG at C ✓
P2's PIN tag at A and CLOSE tag at C ✓ · header checks 2d1/2d2 ✓ · no manifest/control lines ✓
r20 defect strings ("closing-tag annotation" | "via P2's closing tag") inside M0 title -> 0

--- (b) sweep ---
brief:64,70,72,79,117,121 historical · brief:470 step-4a (target == A + manifest_sha256 -> pin tag)
p1:85 · p2:71 · p3:107 universal notes ({A, register, handback, control_sha256} -> pin tag)
p3:112 (cross-refs p3_closing_pin_tag)                    ZERO in P3-M0 title/header checks

--- (c) log region ---
:107 r19 · :116 r20 (+bullets 117-119, blank 120) · :121 r21 · :122 blank · :123 **Executor:**
brief 501 -> 503 (+2 = r21 entry) ; p1 186 (=r19) ; p2 190 (=r19) ; p3 198 (=r20) ;
LEDGER 105 (=) ; manifest 49 (=) ; script 254 (=)

--- (d) ---
banner :4 r21 / 2026-08-17 ; last log block :121 r21
headers: p1/p2/p3 all "# (r21 …" with "+ r20-R0-1," and "…and the 3 gate-r11 findings…"
brief_sha256  20eb6a8e67137647c0c24fba924be88ab03e16445eec755ab7017976c25f36b5  ✓ re-derived
bridge_final  55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88  ✓ unchanged
P3 js-yaml: [M0,M1,M2,M3] · owner_gate 0 · control_manifest null · p3_closing_pin_tag null
```

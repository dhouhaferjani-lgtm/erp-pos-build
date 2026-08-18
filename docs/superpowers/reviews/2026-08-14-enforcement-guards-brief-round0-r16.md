# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r16 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-14

Scope: the brief at r16 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15 + **the two artifacts this round ships**: `scripts/adversarial-review-final.sh` and `docs/handoff/enforcement-control-manifest.yaml`. All uncommitted on `dev`. `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca` — unchanged across the series and identical to the brief's stated verification base.

Run in FULL from the top. Repo-side evidence reused only after re-proving the sources unmodified; all load-bearing citations re-derived. Every doc-side line number re-derived at r16 offsets. **The two new artifacts were executed against, not merely read** — see Check 3.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 0 | Diff-scope of the r16 edit    | **PASS** | 0 |
| 1 | Revision-log truth (r16: 5 gate-r8 claims + the r15 note-1 harmonization) | **PASS** | 0 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable **(incl. the two shipped artifacts)** | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 — no permission/module keys named |
| H | Hygiene (pipes, banner + log ordering, header counts, YAML shape, stale sweeps) | **PASS** | 0 |

**VERDICT: PASS — all rows. r16 is round-0 clean and gate-round-9 ready.** No findings. The headline mechanical result: **all nine manifest `sha256` pins re-derive exactly against the real files** (including `brief_sha256` against the current r16 brief bytes), the shipped bridge **parses, is executable, and its embedded manifest reader resolves every key it requests against the shipped manifest**, the prompt heredoc provably **does not** interpolate the handback digest, and the final-milestone symmetry sweep is **54/54**.

---

## Check 0 — Diff-scope

**Brief:** 469 → **477** (+8). Section anchors shifted **+7** uniformly through §6 (Executor 90→97 · SEQ 95→102 · EXEC 109→116 · §0 132→139 · §1 145→152 · §2 172→179 · §3 265→272 · §4 362→369 · §5 419→426 · §6 445→452), and **§7 452→466 (+8)**. The extra +1 localizes to §6: r15 §6→§7 span = 13 lines, r16 = 14 — **the new F-9 flag**. Accounting closes exactly: **+7 (r16 log block: header + 5 bullets + blank) + 1 (F-9) = +8**, with every other inter-section span unchanged, so all §5/EXECUTION-MODE rewrites are in-line insertions in this file's long-line style.

**YAMLs:** p1 179→**185** (+6), p2 184→**189** (+5), p3 182→**187** (+5) — the new `control_manifest` pin + its comment, the M0 item, and in-line title/close-tag edits. js-yaml confirms **no extra or missing top-level keys** in any file (`extra-top-level=[]`), so growth is the one new key plus comment/title text.

**LEDGER:** 105 → **105**, edited in place.

**New artifacts:** `scripts/adversarial-review-final.sh` (8378 B, mode `-rwxr-xr-x`) and `docs/handoff/enforcement-control-manifest.yaml` (2444 B) — both present, both `??` untracked, consistent with the whole dispatch package being uncommitted. The manifest's own header declares it must be *"Committed BEFORE dispatch (part of base_sha)"* — correctly flagged as a pre-dispatch obligation rather than a present-tense claim (see note 2).

**Repo:** no tracked modification under `apps/api`, `apps/web`, `.github`, `scripts` at an unchanged HEAD (the only `scripts/` entries are the two untracked files, one of which is this round's deliverable).

**Check 0: PASS.**

---

## Check 1 — Revision-log truth

### The five r16 claims

| Claim | Re-derived verification | OK |
|---|---|---|
| **R8-C-1** SEALED REVIEW BUNDLE | **§5 step 1 (`:441`)** sequences it explicitly: *"(i) the parent COPIES the handback to its own IMMUTABLE workspace path …; (ii) verifies the handed-over worktree is otherwise CLEAN (`git status --porcelain` empty — **an operative handover rule**); (iii) **control-file preflight:** … NO change to `scripts/adversarial-review.sh`, `scripts/adversarial-review-final.sh`, this brief, `docs/handoff/SELF-REVIEW-HARNESS.md`, `docs/handoff/enforcement-control-manifest.yaml`, or `.claude/agents/*-reviewer.md`; (iv) the final bridge … materializes a FRESH READ-ONLY DETACHED WORKTREE at exactly A and reviews THAT tree with lens-contract CONTENT injected; (v) …"*. **Six distinct preflight entries confirmed** (the raw grep returns 8 hits because two paths are named twice in the sentence; the distinct set is exactly six). Manifest-sourced lenses/`max_fix_rounds`/milestone with *"candidate YAML, which is field-checked against it"* ✓. The clean-tree rule is **operative in all four required places**, not a log footnote: EXECUTION MODE `:117` (*"HAND OVER WITH A CLEAN TREE (`git status --porcelain` empty — the handback is the ONE file the parent copies out before checking; an otherwise-dirty handover is rejected)"*), all three final milestone titles, and LEDGER S-14. | ✓ |
| **R8-H-1** shipped artifacts + pin | Both artifacts exist; the script is executable and `bash -n`-clean; **all nine manifest pins re-derive** (table in Check 3). `control_manifest` pin present and `null` in all three YAMLs (p1 `:121`, p2 `:108`, p3 `:126`), parse-confirmed as real top-level keys. M0 preconditions present at the claimed indices: **P1 item (7)**, **P2 item (6)**, **P3 item (6)** — each *"`control_manifest` non-null and the manifest file present at base with a matching sha256 (gate-r8 R8-H-1)"*. **F-9 exists** at `:461` — *"PRE-DISPATCH CONTROL DELIVERABLES (new gate-r8 R8-H-1; PARENT/OWNER-owned, exist in this working tree as of r16)"*. | ✓ |
| **R8-H-2** immutable handback copy | §5 step 1 (v): *"the bridge computes the handback `sha256` ITSELF and echoes it in the register (**the digest is NEVER in the prompt**); the reviewer must quote content-derived census evidence (row counts + first/last row keys); the parent verifies the echoed digest equals its own pre-invocation hash"*, and on ACCEPT *"**before tagging it re-hashes the immutable handback copy and requires exact equality**"*. **Verified in the shipped script, not just the prose** — see Check 3: `HB_SHA` is computed at `:112` and written only into the register banner at `:138`; the `PROMPT` heredoc (`:116-125`) interpolates `${PACKAGE} ${MILESTONE} ${ROUND} ${SNAP} ${ACCEPTED} ${BASE} ${HANDBACK} ${LENS_BLOCK}` and **never `${HB_SHA}`**. | ✓ |
| **R8-H-3** tool-error split | §5 step 1: *"a SUBSTANTIVE CHANGES-REQUIRED register transfers control to the executor — it alone fixes, commits, increments `fix_rounds`, resets to `status: review`, hands over; `max_fix_rounds` exhaustion = `blocked_review`. A `tool_error` (exit 3 / no parseable verdict) is PARENT-OWNED: retry the SAME A with `--attempt <m+1>`, no candidate commit, no `fix_rounds` increment, registers at unique parent-workspace paths `round<n>-attempt<m>`, **bounded at 3 attempts then `blocked_review`** …"*. Present in EXECUTION MODE `:117`, **all three final milestone titles** (sweep below), and LEDGER S-14 (*"tool_error = parent-owned same-A retry bounded at 3 — gate-r8 R8-H-3"*). | ✓ |
| **R8-M-1** close-tag | Sweep for the superseded payload (`sha256 of the verbatim check output` / `sha256(output)` / `sha256 of verbatim output`) → **1 hit, brief `:86`, the r15 revision-log entry; 0 in all three YAMLs and 0 in the LEDGER**. All three YAML close-tag schemas now read `{closing_commit: <C>, closing_check: pass (owner attestation — gate-r8 R8-M-1), accepted_sha: <A>}` (p1 `:97`, p2 `:83`, p3 `:111`); LEDGER S-14 reads `closing_check: pass`. **Target verification present in every consumer**: P3-M0 `p3:40` — *"exists, RESOLVES TO C (`git rev-parse <tag>^{commit} == C` — gate-r8 R8-M-1)"* — plus §5 step 5 `:445` and LEDGER. | ✓ |
| **r15 note-1 harmonization** | `control_sha256` now appears in every annotation summary that **enumerates** the payload: p1 `:56` + `:85`, p2 `:45` + `:71`, p3 `:97`, and LEDGER S-14 (*"`{…, control_sha256 lines}` (gate-r7 R7-C-1…)"*). P3's pin-tag comment (`:77`) does not enumerate at all — it delegates: *"annotation = the R5-C-1 schema"* — which is the cleanest form and cannot drift. **No partial-list survivor anywhere.** | ✓ |

### Symmetry sweep — 18 markers × 3 final milestone titles = **54/54**

```
        1  2  3  4  5  6  7  8  9 10 11 12 13 14 15 16 17 18
p1 M3   ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓
p2 M4   ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓
p3 M3   ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓  ✓
1 R8-C-1 · 2 adversarial-review-final.sh · 3 "copies the handback out" · 4 "CLEAN tree" ·
5 "status --porcelain must be empty" · 6 "detached worktree" · 7 enforcement-control-manifest.yaml ·
8 "from the MANIFEST" · 9 "field-checked" · 10 "never in the prompt" · 11 "content-derived census
evidence" · 12 "SUBSTANTIVE CHANGES-REQUIRED" · 13 "increments fix_rounds" · 14 "TOOL ERROR" ·
15 "attempt<m>" · 16 "bounded 3" · 17 blocked_review · 18 "never committed into the candidate"
```
*(Methodology note: a first pass using my own paraphrases produced four false ✗ per row. I re-derived the actual wording from the p1-M3 title and re-ran with matching patterns — the same near-miss that would have produced a false FAIL at r15. The 54/54 above is the corrected, verbatim-anchored result.)*

### Earlier-revision spot-checks

`<admin-tip>` → 4 hits, all in the brief (3 historical log entries + §5's negation); **0 in every YAML and the LEDGER** ✓. "allocated at promotion" → **0 everywhere** ✓. Wildcard closing allowlist → historical only ✓. R7 items (trusted control inputs, de-self-referenced receipt, fix-round machine, handback binding) all carried forward and extended rather than replaced ✓.

**Check 1: PASS.**

---

## Check 2 — Exhaustive claims / censuses (re-run)

r16 adds two artifacts but no inventory claim. All census targets unmodified at an unchanged HEAD:

| Inventory claim | Census | Documented | Re-derived | Match |
|---|---|---|---|---|
| pgsql allowlist "93 class names" (`ci.yml:629`) | `tr '\|' '\n' \| grep -c Test` | 93 | **93** | ✓ |
| second allowlist "16-entry" (`:726`) | same | 16 | **16** | ✓ |
| Architecture ParserFactory "4 of 16" | `grep -l` / `ls` | 4 / 16 | **4 / 16** | ✓ |
| "complete 41-case partition (27+1+4+9)" | `grep -cE '^    case '` | 41 | **41** | ✓ |
| tools tests all import `vitest` | `grep -L vitest \| wc -l` | all | **0 non-vitest** | ✓ |
| `test:tools` absent · no `failOnEmptyTestSuite` | `grep -c` | absent | **0 / 0** | ✓ |
| `test:eslint-rules` + `tools/__tests__` in NO workflow | `grep -rn …` | exit 1 | **exit 1** | ✓ |
| `frontend-lint` ∈ `all-checks-pass` `needs` | `sed -n '1104p'` | present | **present** | ✓ |
| **NEW — manifest pins 6 lens contracts** | js-yaml parse | 6 | **6** (stock-gl-interaction, inventory-costing, treasury, fiscal-pos, frontend-conventions, tenancy-authz) | ✓ |
| **NEW — per-package lens sets / max_fix_rounds / final milestone** | js-yaml parse | p1 M3/2 lenses, p2 M4/2, p3 M3/4, all mfr 5 | **exactly that** | ✓ |

**PASS.**

---

## Check 3 — Test contracts executable (the shipped artifacts were exercised)

### The two new artifacts

| Property claimed | Verification | Result |
|---|---|---|
| `scripts/adversarial-review-final.sh` exists, executable | `ls -l` → `-rwxr-xr-x`, 8378 B; `test -x` | ✓ |
| passes `bash -n` | `bash -n scripts/adversarial-review-final.sh` | ✓ parses |
| `--manifest` mandatory | arg parser `:37`; required-var loop `:49-51` includes `MANIFEST`; unknown args `exit 3` `:46` | ✓ |
| `--handback` accepted | `:42`, required at `:49` | ✓ |
| **self-sha256 verification** | `:77-79` — `SELF_ACTUAL=$(sha "$0")` compared to `controls.bridge_final_sha256`, mismatch → `exit 3` | ✓ |
| control verification of brief + harness + every lens | `:81-86` (brief, harness) and `:90-96` (per-lens `path`/`sha256`), each mismatch → `exit 3` | ✓ |
| **detached read-only worktree at A** | `:101-108` — `git status --porcelain` empty check, `HEAD == ACCEPTED` check, `git worktree add --detach "$SNAP" "$ACCEPTED"`, `chmod -R a-w`, cleanup `trap` | ✓ |
| **lens-contract CONTENT injection** (not labels) | `:95` `LENS_BLOCK+=… "$(cat "$lp")"`, injected into the prompt at `:124` | ✓ |
| manifest-sourced lenses / max_fix_rounds / milestone | `:88`, `:97`, `:98` | ✓ |
| **digest computed by the bridge, NEVER in the prompt** | `HB_SHA` computed `:112`; written only to the register banner `:138`; **the `PROMPT` heredoc `:116-125` interpolates 8 variables and `HB_SHA` is not among them** | ✓ |
| content-derived census evidence required | prompt `:120-122` — *"your register MUST quote, for each required table, the row count and the first and last row keys"* | ✓ |
| attempt-bounded tool errors | `--attempt` `:44`; failure path `:128-131` emits *"retry with --attempt $((ATTEMPT+1))"* and `exit 3`; no-parseable-verdict → `exit 3` `:150`; register path carries round/attempt `:135`. (The **bound of 3** is a brief-level rule at §5 step 1 — the script supplies the mechanism, the brief supplies the limit; consistent, no contradiction.) | ✓ |
| exit codes 0/2/3 | `:146-151` | ✓ |

**Manifest reader actually resolves what the script requests.** I extracted the script's embedded Python `manifest_get` verbatim and ran it against the shipped manifest for every key the script uses — including the three-level `lens_contracts.<lens>.{path,sha256}`:
```
controls.bridge_final_sha256                  => b53895b1…3c47
controls.brief_path                           => docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md
controls.brief_sha256                         => 0fc5f549…aea2
controls.harness_path / harness_sha256        => docs/handoff/SELF-REVIEW-HARNESS.md / 6086d86d…8c15
packages.p1.lenses / .max_fix_rounds / .final_milestone  => stock-gl-interaction,inventory-costing / 5 / M3
packages.p2.lenses                            => frontend-conventions,tenancy-authz
packages.p3.lenses                            => treasury,fiscal-pos,stock-gl-interaction,inventory-costing
lens_contracts.stock-gl-interaction.path/.sha256 => .claude/agents/stock-gl-interaction-reviewer.md / 7e1c3c7c…af9e
lens_contracts.tenancy-authz.path             => .claude/agents/tenancy-authz-reviewer.md
```
**Every key resolves.** This is the load-bearing repair of r15's note 2 / gate-r8 R8-H-1: the interface the brief names is now the interface on disk, and it works against the shipped manifest rather than hitting an unknown-argument branch.

**All nine manifest `sha256` pins re-derived against the real files:**

| Pin | Re-derived | Match |
|---|---|---|
| `bridge_final_sha256` → `scripts/adversarial-review-final.sh` | `b53895b1a553a00709605f52b6985864e42231a4aeacc56c554e126531ac3c47` | ✓ |
| `brief_sha256` → the brief **at its current r16 bytes** | `0fc5f5491d436be6cf400597e48dd9f8ed1f0649938a712e923dcb255ec3aea2` | ✓ |
| `harness_sha256` → `SELF-REVIEW-HARNESS.md` | `6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15` | ✓ |
| lens `stock-gl-interaction` | `7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e` | ✓ |
| lens `inventory-costing` | `7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea` | ✓ |
| lens `treasury` | `408258d068f2ef640b701533f985f0e7ab378a94a0583bab4f808e0727b35d5d` | ✓ |
| lens `fiscal-pos` | `419ac2083583210ff007956a00cdfded8de009cc763cdd2563411c24372b5a82` | ✓ |
| lens `frontend-conventions` | `278596dbc07e82ece964897d021d2d136dce59f136519578c9a256b39da45385` | ✓ |
| lens `tenancy-authz` | `9e2e444acf0b0de7b70f053eaf9948be2f001eb38de132a15103976d78b3a345` | ✓ |

**`brief_sha256` matching the current brief bytes is the specific risk the round flagged** — the manifest was refreshed after the final r16 edit, and it holds.

### Existing contracts

P1/P2 LOCAL AUTHORITY SETUP + acceptance blocks, P1 tamper 1–5, P3 census-derived acceptance (grounded by the re-verified absence of `failOnEmptyTestSuite`), promotion steps 0/2/3/4a/5 (freshness, preflight+read-back, annotated tag with the four-field schema, full pre-merge re-verification, deterministic C + close tag) — all unchanged and runnable; no impossible interleavings, no barriers on lazily-created rows, **no contract asserting through a mock of its subject** (and R8-C-1/R8-H-2 remove the last two places where the reviewed party supplied the review's own inputs).

**PASS.**

---

## Check 4 — Behavior/repo claims cite source

**Re-derived this pass:**
```
ci.yml:3-8    push[main] · pull_request[main,dev] · workflow_dispatch    ci.yml:1104  needs incl. frontend-lint
ci.yml:1-26   grep -c permissions → 0        StockAdjustmentService.php:1715/:1716/:1719
GeneralLedgerService.php:3480/:3507          adversarial-review.sh: grep -c handback → 0 (mid-wave bridge unchanged)
.claude/agents/*-reviewer.md → 7 files       manifest pins 6 of them
```

**Conjunction checks (house law).** (a) `frontend-lint` ↔ `all-checks-pass` `needs` — edge at `:1104`. (b) `frontend-lint` ↔ the `package.json` lint chain — correctly denied. (c) close tag pushed at step 5 ↔ P3-M0's verification ↔ the YAML schemas — one payload, and now `rev-parse ^{commit} == C` on both sides. (d) **New for r16 — manifest ↔ brief lens assignments:** the brief's EXECUTION-MODE lens table gives P1 `stock-gl-interaction,inventory-costing`; P2 final `frontend-conventions` + `tenancy-authz`; P3 `treasury,fiscal-pos,stock-gl-interaction,inventory-costing`. The manifest's `packages` block is **character-identical** for all three, and its `final_milestone` values (M3/M4/M3) match the packages' actual final milestones. Since the bridge now sources lenses from the manifest instead of candidate YAML, this is the edge that decides whether the seal is real — **it closes**. (e) **New — script ↔ manifest key surface:** verified by execution above, not by reading.

**Owner-attested external facts** (recorded, not re-derived — round 0 makes no network calls): `default_workflow_permissions: read` and `gh api → owner.type: "User"`. Locally-checkable halves re-derived (`ci.yml:1-26` has no top-level `permissions:` block).

**PASS.**

---

## Check 5 — Permission keys

```
$ grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule" \
    <brief + 3 YAMLs + enforcement-control-manifest.yaml>
(no output)   exit 1
```
No permission or module keys named, including in the new manifest. **N/A — PASS.**

---

## Hygiene

- **Banner ↔ log ordering.** Banner `:4` = *"r16 — 2026-08-14, full gate-r8 fix round applied (…gate-r8.md: R8-C-1, R8-H-1..3, R8-M-1 — itemized log below; **ships TWO control-plane artifacts**…), NOT yet re-gated … then **gate round 9**"*. Anchors `:4 · :5 · :24 · :27 · :29 · :42 · :50 · :52 · :61 · :63 · :72 · :74 · :82 · :84 · **:90 r16**` — strictly monotonic, **r16 last**. ✓
- **YAML headers.** All three at **r16**, ending *"…the 4 gate-r7 findings, **and the 5 gate-r8 findings**; re-gate before dispatch)."* Counts re-derived from the registers: **17 / 11 / 6 / 7 / 7 / 6 / 4 / 5**; gate-r8 `grep -cE '^### R8-(C|H|M)-[0-9]+'` → **5**, IDs `R8-C-1 R8-H-1 R8-H-2 R8-H-3 R8-M-1` = **1C+3H+1M**. All eight correct. ✓
- **Pipes.** Four normative tables at r16 offsets — sequencing `:106-110` (4) · read-order `:141-148` (3) · DO-NOT-TOUCH `:166-173` (2) · contract `:189-194` (3). Every row matches its header. ✓
- **YAML shape.** All three parse; one `base_sha`, one `branch`, `status: pending`; milestones **P1 [M0-M3] · P2 [M0-M4] · P3 [M0-M3]**; **milestone-level `owner_gate:` = 0/0/0**; pin sets complete with the new `control_manifest` and all `null`; **no extra/missing top-level keys**. The manifest parses independently (controls / lens_contracts×6 / packages×3). ✓
- **Stale sweeps.** `sha256 of the verbatim check output` → 1 (brief `:86`, historical) ✓ · `<admin-tip>` → brief-only, historical + negation ✓ · "allocated at promotion" → 0 ✓ · wildcard allowlist → historical ✓ · in-C `closing_commit`/`closing_check` receipt fields → 0 (all occurrences are close-tag annotations) ✓.

**PASS.**

---

## Note-only observations (not findings)

1. **The control-file preflight glob is broader than the pinned set — the safe direction.** `.claude/agents/*-reviewer.md` matches **7** files; the manifest pins **6**. The unpinned one is `imports-reviewer.md`, which no package's lens set uses. So the preflight rejects changes to a superset of what the bridge verifies, and the bridge verifies exactly what it injects. No gap; recorded so the gate reviewer does not read the 6-vs-7 difference as one.
2. **Both new artifacts are untracked (`??`).** The manifest's own header states it must be *"Committed BEFORE dispatch (part of `base_sha`)"* and that *"all values below are for the r16 working tree and MUST be refreshed in the pre-dispatch commit"* — an honest, self-declared pre-dispatch obligation, and F-9 carries it. Round 0 confirms the values are correct **as of now**; the refresh obligation remains live because committing the manifest changes nothing it hashes, but any further brief edit moves `brief_sha256`.
3. **The `max_fix_rounds` bound of 3 tool-error attempts lives in the brief, not the script.** The script supplies `--attempt` and suggests `m+1`; §5 step 1 supplies the limit and the `blocked_review` transition. Consistent division, no contradiction — noted because a reader of the script alone would not find the bound.
4. **Two owner-attested external facts** (`default_workflow_permissions: read`; `owner.type: "User"`) recorded as attested, not re-derived.
5. **`TreasuryReceiptBridge` lives under `Application/Projections/`**; the brief cites only line numbers for it (all verify).
6. **Arabic-authored entries interleave the cited `i18n.ts` alias ranges** — the claim about what those ranges demonstrate is correct.
7. **`wave3-3c-3d.progress.yaml` M3 remains `status: pending`** — consistent with treating ACCEPT as a dispatch-time P1-M0 precondition.
8. **`SystemAccountPurpose::expectedAccountType()` is at `:187`; the brief writes `:~186`** — within the marked tolerance.
9. **Third consecutive clean supersession round.** The append-vs-substitute class (four historical firings) did not recur, and this round's two *new* structural sweeps — the close-tag `pass` attestation and the in-C receipt fields — were clean immediately.

---

## Evidence appendix (raw outputs)

```
$ git rev-parse HEAD
a5520f23ca39209f5b517723037e9516808f2bca

$ wc -l <targets>
477 CODEX-DISPATCH-enforcement-guards-2026-08-12.md   (r15: 469 → +8)
185 enforcement-p1.progress.yaml   (179 → +6) · 189 enforcement-p2 (184 → +5) · 187 enforcement-p3 (182 → +5)
105 LEDGER.md (unchanged, in-place)

$ ls -l <new artifacts>
-rwxr-xr-x  8378  Aug 14 10:45  scripts/adversarial-review-final.sh
-rw-r--r--  2444  Aug 14 10:47  docs/handoff/enforcement-control-manifest.yaml
$ git status --porcelain <both> → ?? (untracked; whole package uncommitted)

--- brief section offsets (r15 → r16) ---
uniform +7 through §6 (Executor 90→97 … §6 445→452); §7 452→466 (+8)
§6→§7 span 13 → 14  ⇒ +1 = the new F-9;  +7 = r16 log block;  total +8 ✓

--- MANIFEST sha256 RE-DERIVATION (all 9) ---
bridge_final      ✓ b53895b1a553a00709605f52b6985864e42231a4aeacc56c554e126531ac3c47
brief             ✓ 0fc5f5491d436be6cf400597e48dd9f8ed1f0649938a712e923dcb255ec3aea2   ← current r16 bytes
harness           ✓ 6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
lens stock-gl     ✓ 7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
lens inv-costing  ✓ 7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
lens treasury     ✓ 408258d068f2ef640b701533f985f0e7ab378a94a0583bab4f808e0727b35d5d
lens fiscal-pos   ✓ 419ac2083583210ff007956a00cdfded8de009cc763cdd2563411c24372b5a82
lens frontend-cnv ✓ 278596dbc07e82ece964897d021d2d136dce59f136519578c9a256b39da45385
lens tenancy-authz✓ 9e2e444acf0b0de7b70f053eaf9948be2f001eb38de132a15103976d78b3a345

--- SCRIPT verification ---
bash -n → OK · test -x → OK
arg parser :37-45 (--manifest --package --candidate --accepted --base --handback --round --attempt --out)
required-var loop :49-51 · unknown arg → exit 3 :46
self-verify :77-79 (sha "$0" vs controls.bridge_final_sha256) · brief/harness :81-86 · lenses :90-96
seal :101-108 (porcelain-empty, HEAD==ACCEPTED, worktree add --detach, chmod a-w, trap cleanup)
handback digest :112 · register banner :138 · PROMPT heredoc :116-125 interpolates
  ${PACKAGE} ${MILESTONE} ${ROUND} ${SNAP} ${ACCEPTED} ${BASE} ${HANDBACK} ${LENS_BLOCK}
  → ${HB_SHA} ABSENT from the prompt ✓ (R8-H-2)
lens CONTENT injection :95 (cat "$lp") → :124 · exits :146-151 (0/2/3)

--- manifest_get PARSER TEST (script's embedded python, run against the shipped manifest) ---
every requested key resolves, incl. 3-level lens_contracts.<lens>.{path,sha256}   (13/13)

--- SYMMETRY SWEEP 18×3 = 54/54 ---
p1 M3 / p2 M4 / p3 M3 all ✓ on: R8-C-1 · final.sh · "copies the handback out" · "CLEAN tree" ·
porcelain-empty · detached worktree · manifest file · "from the MANIFEST" · "field-checked" ·
"never in the prompt" · content-derived census evidence · SUBSTANTIVE CHANGES-REQUIRED ·
increments fix_rounds · TOOL ERROR · attempt<m> · bounded 3 · blocked_review · registers-not-in-candidate

--- six-entry control-file preflight (§5 step 1, distinct paths) ---
scripts/adversarial-review.sh · scripts/adversarial-review-final.sh · this brief ·
docs/handoff/SELF-REVIEW-HARNESS.md · docs/handoff/enforcement-control-manifest.yaml ·
.claude/agents/*-reviewer.md

--- R8-M-1 ---
"sha256 of the verbatim check output" → brief:86 only (r15 log); 0 in YAMLs/LEDGER
close-tag schema now: {closing_commit: <C>, closing_check: pass (owner attestation — gate-r8 R8-M-1),
                       accepted_sha: <A>}   at p1:97 · p2:83 · p3:111 · LEDGER:56
target check: p3:40 "RESOLVES TO C (git rev-parse <tag>^{commit} == C — gate-r8 R8-M-1)" + brief:445

--- control_manifest pin + M0 ---
p1:121 · p2:108 · p3:126 (all null, parse-confirmed)
M0 items: P1 (7) · P2 (6) · P3 (6) — "control_manifest non-null and the manifest file present at base
with a matching sha256 (gate-r8 R8-H-1)"          F-9 at brief:461

--- js-yaml parse ---
p1 [M0..M3] · p2 [M0..M4] · p3 [M0..M3] · owner_gate 0/0/0 · pins all present+null · extra-top-level=[]
manifest: controls / lens_contracts(6) / packages p1(M3,5,2) p2(M4,5,2) p3(M3,5,4)
line5 (all three): "… the 4 gate-r7 findings, and the 5 gate-r8 findings; re-gate before dispatch)"

--- gate-register counts ---
r1 17 · r2 11 · r3 6 · r4 7 · r5 7 · r6 6 · r7 4 · r8 5 (R8-C-1, R8-H-1..3, R8-M-1)

--- tables --- :106-110 → 4×5 · :141-148 → 3×8 · :166-173 → 2×8 · :189-194 → 3×6   consistent

--- repo re-derivation ---
ci.yml:3-8 · :1104 · :1-26 permissions=0 · StockAdjustmentService.php:1715/:1716/:1719
GeneralLedgerService.php:3480/:3507 · adversarial-review.sh handback-count=0 (mid-wave bridge untouched)
vitest-L=0 · test:tools=0 · failOnEmpty=0 · ParserFactory 4/16 · cases=41 · :629→93 · :726→16
.claude/agents/*-reviewer.md → 7 files (6 pinned; imports-reviewer unpinned and unused)

--- check 5 --- over brief + 3 YAMLs + manifest → (no output) exit 1
```

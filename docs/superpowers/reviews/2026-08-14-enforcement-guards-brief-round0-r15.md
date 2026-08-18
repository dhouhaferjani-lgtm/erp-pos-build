# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r15 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-14

Scope: the brief at r15 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15, all UNCOMMITTED on `dev`. `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca` — unchanged across every round-0 run in this series and identical to the brief's stated verification base.

Run in FULL from the top per the spec's hard rule. Repo-side evidence reused only after re-proving the sources unmodified; all load-bearing citations re-derived this pass. Every doc-side line number re-derived at r15 offsets — nothing copied from any prior report or gate register.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 0 | Diff-scope of the r15 edit    | **PASS** | 0 |
| 1 | Revision-log truth (r15: 4 gate-r7 claims; r14/r13 + earlier spot-checks) | **PASS** | 0 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable     | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 — no permission/module keys named |
| H | Hygiene (pipes, banner + log ordering, header counts, YAML shape, stale sweeps) | **PASS** | 0 |

**VERDICT: PASS — all rows. r15 is round-0 clean and gate-round-8 ready.** No findings. All four gate-r7 repairs are applied **symmetrically**: the two sweeps that have historically caught this document (the N-1-of-N milestone-title sweep and the supersession sweep) both come back clean on the first pass — 9/9 markers in each of the three final milestone titles, and zero in-C survivors of the `closing_commit:`/`closing_check:` fields.

---

## Check 0 — Diff-scope

**Brief:** 463 → **469** (+6). Every section anchor shifted uniformly **+6** — Executor 84→90 · SEQUENCING 89→95 · EXEC-MODE 103→109 · §0 126→132 · §1 139→145 · §2 166→172 · §3 259→265 · §4 356→362 · §5 413→419 · §6 439→445 · §7 452→458. **No inter-section span changed** (EXEC→§0 = 23 lines in both revisions; §5→§6 = 26 in both), so the entire +6 is the r15 revision-log block (header + 4 finding bullets + blank), and every operative edit — §5 steps 1/3/5, the EXECUTION MODE exception — is an **in-line insertion into this file's long-line style**. Consistent with the r15 log's own account.

**YAMLs:** p1 175→**179** (+4), p2 180→**184** (+4), p3 175→**182** (+7). The +4s are the rewritten `closing_receipt` comment blocks (now 9 comment lines each: p1 `:91-99`, p2 `:78-86`) plus in-line final-milestone-title expansion; p3's +7 adds the same block (`:106-114`) **plus** the two close-tag verification clauses in M0 checks 1d (`:39`) and 2d (`:53`). js-yaml confirms **no new or removed top-level keys** (`extra-top-level=[]` for all three), so the growth is entirely comment/title text.

**LEDGER:** 105 → **105**, edited in place (mtime 10:27:37, inside the r15 window).

**Repo:** `git status --porcelain apps/api apps/web .github scripts` → only untracked `scripts/dev-scan-stack.sh` (not a citation target); no tracked modification at an unchanged HEAD.

**Check 0: PASS.**

---

## Check 1 — Revision-log truth

### The four r15 claims

| Claim | Required locations | Re-derived verification | OK |
|---|---|---|---|
| **R7-C-1** TRUSTED CONTROL INPUTS | §5 step 1 (parent's pinned copies outside the handed-over worktree, A as data only, control-file preflight over `base_sha..A`); EXECUTION MODE exception; step-3 annotation gains `control_sha256:`; LEDGER S-14 step 1 | **§5 step 1 (`:434`)** — *"**The PARENT ITSELF invokes the final whole-package bridge … FROM TRUSTED CONTROL INPUTS (gate-r7 R7-C-1)** … The bridge, this brief, and the lens contracts are the PARENT'S PINNED COPIES outside the handed-over worktree — never the candidate's; A is supplied only as reviewed data. **Control-file preflight first:** `git diff --name-only <base_sha>..A` must show NO change to `scripts/adversarial-review.sh`, this brief, `docs/handoff/SELF-REVIEW-HARNESS.md`, or `.claude/agents/*-reviewer.md` (any hit is simultaneously a scope FAIL…)"* — all four control-surface paths named. **EXECUTION MODE (`:110`)** — *"PARENT-INVOKED (gate-r6 R6-C-1) from the parent's TRUSTED control-file copies (gate-r7 R7-C-1) … the parent runs the bridge itself (its own pinned copy, your tree as data only, with a control-file preflight …)"*. **Step 3 (`:436`)** — *"final lines `control_sha256: <name>=<hex>` for the trusted bridge/brief/lens-contract blobs used by the parent's final invocation (gate-r7 R7-C-1)"*. **LEDGER S-14 step 1** — *"the PARENT ITSELF invokes the final whole-package bridge FROM ITS TRUSTED CONTROL-FILE COPIES (gate-r6 R6-C-1, gate-r7 R7-C-1 — candidate tree = data only; control-file preflight on base_sha..A rejects any change to the bridge/brief/harness/lens contracts …)"*. | ✓ |
| **R7-C-2** de-self-referenced receipt + CLOSE TAG | all three YAML `closing_receipt` comments list ONLY pre-commit-knowable fields; **zero surviving `closing_commit:`/`closing_check:` as IN-C fields**; each describes the close tag; §5 step 5 matches (receipt de-self-referenced, close tag after the check passes, amend window narrowed to before-close-tag); P3-M0 1d/2d verify P1/P2 close-tag annotations; LEDGER S-14 matches | **Sweep result — the round's designated FAIL criterion:** every `closing_commit:`/`closing_check:` occurrence in the three YAMLs is at p1 `:96`, p2 `:83`, p3 `:111` — **all inside the close-tag *annotation* description**, i.e. the second object outside C, which is correct by design. **Zero in-C-field survivors.** All three receipt blocks are byte-identical in substance: *"C can only contain what is knowable BEFORE commit): `accepted_sha: <A>` · `tag:` · `digests:` · `manifest:` · `preflight: pass`. **NEVER C's own SHA, NEVER post-commit check output** — those live in the SECOND owner-authenticated object: the annotated CLOSE TAG `ci-close/enforcement-<pkg>-r<n>` pushed AT C after the check passes … Consumers derive C as A's first-parent child (`C^ == A`) and verify the close tag."* **§5 step 5 (`:438`)** carries the same de-self-referenced list, the close-tag push after the check passes, and the narrowed amend window (*"ONLY while C is still the unpushed local dev tip **AND before the close tag exists**"*). **P3-M0 1d (`p3:39`)** — *"AND (gate-r7 R7-C-2) P1's CLOSE TAG (`ci-close/enforcement-p1-r<n>`)…"*; **2d (`p3:53`)** — *"…and P2's close tag"*. **LEDGER S-14** carries both the de-self-referenced receipt and `ci-close/enforcement-<pkg>-r<n> … {closing_commit: C, closing_check: sha256(output), accepted_sha: A}`. | ✓ |
| **R7-H-1** final fix-round state machine | §5 step 1, EXECUTION MODE exception, **all three** final milestone titles, LEDGER S-14 | **§5 step 1 (`:434`)** — *"**Final fix rounds (gate-r7 R7-H-1): on CHANGES-REQUIRED or tool error (exit 3 = fail closed), the parent keeps the round register in its OWN workspace (never committed into the candidate) and returns its content; the executor alone fixes, commits, increments `fix_rounds`, resets the milestone to `status: review`, and hands over again; the parent invokes every round; `max_fix_rounds` exhaustion = `blocked_review`; only the final ACCEPT register's bytes ever land in C.**"* **EXECUTION MODE (`:110`)** — same, addressed to the executor. **All three final milestone titles** — see the symmetry sweep below. **LEDGER S-14 step 1** — *"final fix rounds per gate-r7 R7-H-1: parent invokes every round, executor fixes/hands over, parent registers never committed into the candidate"*. | ✓ |
| **R7-H-2** handback-bound final review | §5 step 1, EXECUTION MODE exception, all three final milestone titles, LEDGER S-14 step 1 | **§5 step 1 (`:434`)** — *"**Handback binding (gate-r7 R7-H-2):** the parent computes `sha256` of the exact handback BEFORE invoking, passes path+digest to the trusted bridge, the reviewer must evaluate THAT file and echo path+digest in its register, and the parent verifies the echo before acting on any ACCEPT."* **EXECUTION MODE (`:110`)** — *"the handback digest-bound in — gate-r7 R7-H-2"*. **All three titles** — *"handback digest-bound into the review — gate-r7 R7-H-2"*. **LEDGER S-14 step 1** — *"handback digest-bound into the review and echo-verified — gate-r7 R7-H-2"*. | ✓ |

### Symmetry sweep — the failure class that has fired four times in this series

Nine independent markers checked against **each of the three final milestone titles** (P1-M3, P2-M4, P3-M3):

```
        R7-C-1  TRUSTED-copies  preflight  handback-bound  R7-H-2  R7-H-1  fix_rounds  max_rounds  registers-not-in-candidate
p1 M3     ✓          ✓             ✓            ✓            ✓       ✓          ✓           ✓                ✓
p2 M4     ✓          ✓             ✓            ✓            ✓       ✓          ✓           ✓                ✓
p3 M3     ✓          ✓             ✓            ✓            ✓       ✓          ✓           ✓                ✓
```
**27/27.** Representative text (identical in substance across all three): *"FINAL-GATE INVOCATION (gate-r6 R6-C-1; trusted inputs gate-r7 R7-C-1; fix rounds gate-r7 R7-H-1): this milestone's bridge review is PARENT-INVOKED from the parent's TRUSTED control-file copies (candidate tree = data only; control-file preflight on base_sha..A; handback digest-bound into the review — gate-r7 R7-H-2) … On the parent's CHANGES-REQUIRED (or tool error — parent re-invokes), the parent returns the register content; the EXECUTOR alone fixes scoped, commits, increments fix_rounds, resets to status: review, hands over again; the parent invokes every round; max_fix_rounds exhaustion = blocked_review; parent registers are never committed into the candidate."*

### Earlier-revision spot-checks

r14 (`<admin-tip>` → C substitution) holds: the sweep returns 4 hits, all historical log entries (`:69`, `:77`, `:82`) plus §5's negation at `:438`; **zero in any YAML or the LEDGER**. r13/r12/r11 items — R6-C-1 parent invocation, R6-H-1 handback digest in P3-M0, R6-H-4 `closing_receipt` + `p3_closing_pin_tag` fields, per-package closing sets, pin-tag pre-allocation and never-deleted, single-writer serialization, `ratchet_trust_model_ack` — all intact. Supersession sweeps: "allocated at promotion" → 0; wildcard allowlist → 2 historical; `record*+BEFORE merge` → 2 historical. ✓

**Check 1: PASS.**

---

## Check 2 — Exhaustive claims / censuses (re-run)

r15 introduced no inventory claim (its content is control-plane protocol). Every census target unmodified at an unchanged HEAD; censuses re-run:

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

| Contract | Command present | Physically executable | Mock-of-subject |
|---|---|---|---|
| P1 / P2 LOCAL AUTHORITY SETUP + acceptance | ✓ | ✓ `env -u` subshell preserves the outer export | none |
| P1 tamper 1–5, scope/allowlist, aggregate grep | ✓ | ✓ | none |
| P3 acceptance (census-derived `--filter` + nonzero count, phpstan) | ✓ | ✓ grounded by the re-verified absence of `failOnEmptyTestSuite` | none |
| **Step 1 control-file preflight (`:434`)** | ✓ `git diff --name-only <base_sha>..A` + a four-path reject list | ✓ plain plumbing; the four paths are literal and greppable | n/a |
| **Step 1 trusted invocation (`:434`)** | ✓ parent runs its own pinned copy of the bridge with A as data | ✓ `scripts/adversarial-review.sh` takes `--range` and `--brief` as arguments, so invoking a copy located outside the candidate against A's range is mechanically possible with the existing CLI | **none — and this closes the last self-attestation path**: the trust-producing executable is no longer supplied by the reviewed party |
| **Step 1 handback binding (`:434`)** | ✓ `--handback <path> --handback-sha256 <hex>` | ✓ **as a stated interface extension to the parent's trusted copy** — re-derived that today's `scripts/adversarial-review.sh` has **no** `--handback` flag; the r15 log (`:88`) labels this explicitly a *"trusted-copy interface extension"*, so the brief is honest that this is new work rather than describing an existing capability. Echo-verification is a `grep` over the returned register | **none — this is the repair for a mock-of-subject-adjacent gap**: the previously unreviewed handback is now a named, digest-bound review input |
| Step 2 preflight + read-back (`:435`) | ✓ | ✓ | n/a |
| **Step 3 annotated tag + extended schema (`:436`)** | ✓ four field types incl. `control_sha256:` | ✓ `git tag -a` body is free-form; the schema is line-oriented so appended `control_sha256` lines do not disturb the first three fields' parse | n/a |
| Step 4a full re-verification (`:437`) | ✓ | ✓ | n/a |
| **Step 5 closing check + CLOSE TAG (`:438`)** | ✓ `C^ == A`, `rev-list --count <A>..C == 1`, `diff-tree`, then `ci-close/...` pushed at C | ✓ **and now constructible** — the r14 fixed-point defect is gone: C contains only pre-commit-knowable data, so a truthful C exists and the amend loop converges | n/a |
| P3-M0 1d/2d binding (`p3:29-53`) | ✓ pin-tag annotation parse (register + handback digests) → derive C → base-blob equality → **close-tag verification** | ✓ deterministic | n/a |
| M0 preconditions (3 YAMLs) | ✓ | ✓ seam-grep target still at `StockAdjustmentService.php:1719` | n/a |

No impossible interleavings, no barriers on lazily-created rows, no contract asserting through a mock of its subject. **PASS.**

---

## Check 4 — Behavior/repo claims cite source

**Re-derived this pass:**
```
ci.yml:3-8    push.branches [main] · pull_request.branches [main, dev] · workflow_dispatch  (nothing else)
ci.yml:1104   needs: [… frontend-lint …]
ci.yml:1-26   grep -c permissions → 0   (no top-level permissions: block — R6-H-5 premise)
StockAdjustmentService.php:1715/:1716/:1719   ?StockMovementReferenceType / ?string $referenceId /
                                               assertReferenceLinkagePaired(...)
GeneralLedgerService.php:3480/:3507            sealAndPersistEntry(...) / bccomp(...) !== 0
adversarial-review.sh:47 mkdir · :93 cp "$TMP" "$OUT" · :96/:97 VERDICT_LINE parse
adversarial-review.sh  grep '--handback|self-blob|git cat-file' → NO MATCH
```
The last line is the load-bearing new check: it confirms both halves of R7-C-1/R7-H-2's premise — the shipped bridge performs **no self-blob or control-file verification** (so running the candidate's copy really was the laundering path r15 closes) and has **no handback interface** (so the `--handback`/`--handback-sha256` extension is genuinely new, exactly as the brief states).

**Owner-attested external facts (recorded, not re-derived — round 0 makes no network calls):** `default_workflow_permissions: read` (`:437`) and `gh api → owner.type: "User"` (F-8). Both used conservatively; each has a locally-checkable half that **was** re-derived (no top-level `permissions:` block; the candidate's own workflow diff).

**Conjunction check (house law).** (a) `frontend-lint` ↔ `all-checks-pass` `needs` — edge at `:1104`. (b) `frontend-lint` ↔ the `package.json` lint chain — correctly denied. (c) pin-tag name in A ↔ created at step 3 ↔ fetched by P3-M0 — one identifier. (d) §5 step 5's C ↔ the three YAML notes ↔ LEDGER's check clause — all C-bound (r14's repair holds). (e) **New for r15:** the close tag pushed at step 5 (`:438`) ↔ P3-M0's close-tag verification (`p3:39`, `:53`) ↔ the three YAML receipt blocks' description — producer, consumer and schema all name `ci-close/enforcement-<pkg>-r<n>` with the same `{closing_commit, closing_check, accepted_sha}` payload. (f) **New for r15:** the control-file preflight's reject list (`:434`) ↔ the packages' scope allowlists — the brief asserts *"any hit is simultaneously a scope FAIL"*; the three allowlists (`apps/api/tests/Architecture/**` · `.github/workflows/ci.yml` · `docs/handoff/**` for P1, and the per-package equivalents) indeed exclude `scripts/`, `.claude/agents/`, and `docs/handoff/SELF-REVIEW-HARNESS.md`, so the two rules genuinely reinforce rather than merely coexist.

**PASS.**

---

## Check 5 — Permission keys

```
$ grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule" \
    docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md \
    docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml
(no output)   exit 1
```
No permission or module keys named. **N/A — PASS.**

---

## Hygiene

- **Banner ↔ latest log entry, with ordering.** Banner `:4` = *"**Revision:** r15 — **2026-08-14**, full gate-r7 fix round applied (…gate-r7.md: R7-C-1..2, R7-H-1..2 — itemized log below), **NOT yet re-gated**. Re-run round 0 from the top, then **gate round 8**, before dispatch."* Full anchor list: `:4` · `:5` · `:24` · `:27` · `:29` · `:42` · `:50` · `:52` · `:61` · `:63` · `:72` · `:74` · `:82` · **`:84` r15** — strictly monotonic, **r15 last**. ✓
- **YAML line-5 headers.** All three at **r15**, ending *"…the 6 gate-r6 findings, **and the 4 gate-r7 findings**; re-gate before dispatch)."* Counts re-derived **from the registers**: gate-r1 **17** · r2 **11** · r3 **6** · r4 **7** · r5 **7** · r6 **6** · r7 `grep -cE '^### R7-(C|H|M)-[0-9]+'` → **4**, IDs `R7-C-1 R7-C-2 R7-H-1 R7-H-2` = **2C+2H+0M**. All seven correct. ✓
- **Unescaped GFM pipes.** Four normative tables re-checked at r15 offsets — sequencing `:99-103` (header 4, rows 4) · read-order `:134-141` (3) · DO-NOT-TOUCH `:159-166` (2) · write-surface contract `:182-187` (3). Every row matches its header; the new `{closing_commit, closing_check, accepted_sha}` and `control_sha256:` text sits outside all tables. ✓
- **YAML validity / shape (js-yaml, from `apps/web`).** All three parse. One `base_sha`, one `branch`, top-level `status: pending`. Milestones ordered and complete: **P1 `[M0,M1,M2,M3]`, P2 `[M0,M1,M2,M3,M4]`, P3 `[M0,M1,M2,M3]`** ✓. **Milestone-level `owner_gate:` fields = 0 / 0 / 0** ✓. Pin sets **unchanged from r14** and all `null`, with **no extra or missing top-level keys** in any file ✓. `p2_m2_landed_sha` a key in none ✓.
- **Stale sweeps.**
  - **NEW (r15): `closing_commit:`/`closing_check:` as IN-C `closing_receipt` fields** → **zero**; all occurrences are close-tag annotation descriptions ✓
  - `<admin-tip>` → 4 hits, all historical or §5's negation; zero operative ✓
  - "allocated at promotion" → 0 hits ✓
  - wildcard closing allowlist → 2 historical ✓
  - tag deletion/retention affordance → historical only ✓
  - `record* … BEFORE merge` receipts → 2 historical ✓

**Hygiene: PASS.**

---

## Note-only observations (not findings)

1. **The annotation-payload summaries in LEDGER S-14 step 3 and the three YAML pin-tag comment blocks still read `{A, sha256(register(s)), sha256(handback)}`, without the new `control_sha256` line.** Considered against the r15 claim and deliberately **not** raised as a finding, with reasoning recorded so the gate reviewer can overrule: (i) the claim scopes `control_sha256` to *"the step-3 annotation schema"*, which is where the authoritative, machine-parseable schema lives (`:436`) and where it is present; (ii) these are descriptive summaries of the binding's purpose, not command text — unlike the r13 `<admin-tip>` case, nothing instructs an actor to construct a 3-field annotation; (iii) the schema is line-oriented and `control_sha256` lines are specified as *final lines*, so P3-M0's parse of `accepted_sha`/`register_sha256`/`handback_sha256` is unaffected. Harmonizing the summaries would nonetheless remove the last place where two locations describe the same object at different completeness.
2. **`scripts/adversarial-review.sh` has no `--handback` flag today** (verified). The brief calls this a *"trusted-copy interface extension"* — accurate, and worth the gate reviewer's attention as real implementation work on the parent's pinned copy rather than a documentation-only change.
3. **Two owner-attested external facts** (`default_workflow_permissions: read`; `owner.type: "User"`) recorded as attested, not re-derived.
4. **`TreasuryReceiptBridge` lives under `Application/Projections/`**; the brief cites only line numbers for it (all verify).
5. **Arabic-authored entries interleave the cited `i18n.ts` alias ranges** — the claim about what those ranges demonstrate is correct.
6. **`wave3-3c-3d.progress.yaml` M3 remains `status: pending`** — consistent with treating ACCEPT as a dispatch-time P1-M0 precondition.
7. **`SystemAccountPurpose::expectedAccountType()` is at `:187`; the brief writes `:~186`** — within the marked tolerance.
8. **The append-vs-substitute class did not fire this round.** r15 is the second consecutive revision whose supersession sweeps are clean on the first re-run, and the first in which a *new* structural sweep (the in-C receipt fields) was clean immediately. Recorded as evidence the substitution discipline is holding, not just the individual instances.

---

## Evidence appendix (raw outputs)

```
$ git rev-parse HEAD
a5520f23ca39209f5b517723037e9516808f2bca      (unchanged; == brief base a5520f23c)

$ wc -l <targets>
469 CODEX-DISPATCH-enforcement-guards-2026-08-12.md   (r14: 463 → +6)
179 enforcement-p1.progress.yaml                      (r14: 175 → +4)
184 enforcement-p2.progress.yaml                      (r14: 180 → +4)
182 enforcement-p3.progress.yaml                      (r14: 175 → +7)
105 LEDGER.md                                          (r14: 105 → 0, edited in place)

$ ls -lT
Aug 14 10:26:58 brief · Aug 14 10:27:37 LEDGER, p1, p2, p3

$ git status --porcelain apps/api apps/web .github scripts
?? scripts/dev-scan-stack.sh          (untracked, not a citation target)

--- brief section offsets (r14 → r15): uniform +6, no span changed ---
Executor 84→90 · SEQ 89→95 · EXEC 103→109 · §0 126→132 · §1 139→145 · §2 166→172
§3 259→265 · §4 356→362 · §5 413→419 · §6 439→445 · §7 452→458
EXEC→§0 span 23 in both · §5→§6 span 26 in both

--- revision-log ordering (r15 LAST) ---
:4 banner · :5 (r1/r2/r3) · :24 r4 · :27 r5 · :29 r6 · :42 r7 · :50 r8 · :52 r9 ·
:61 r10 · :63 r11 · :72 r12 · :74 r13 · :82 r14 · :84 r15      strictly monotonic

--- R7-C-2 SWEEP (the round's designated FAIL criterion) ---
grep -n 'closing_commit:|closing_check:' <brief + 3 YAMLs + LEDGER>
  p1:96  "# {closing_commit: <C>, closing_check: <sha256 of verbatim output>, accepted_sha: <A>}"
  p2:83  identical
  p3:111 identical
      → ALL THREE inside the CLOSE-TAG ANNOTATION description (the second object outside C)
  brief:79 (r13 log) · brief:86 (r15 log) · brief:438 (§5 step 5 close tag) · LEDGER:56 (close tag)
  ZERO in-C `closing_receipt` field survivors
receipt blocks (p1:91-99 · p2:78-86 · p3:106-114), identical in substance:
  "C can only contain what is knowable BEFORE commit): accepted_sha: <A> · tag: <closing pin-tag name>
   · digests: <the annotation payload> · manifest: <the mandatory closing-path list> · preflight: pass.
   NEVER C's own SHA, NEVER post-commit check output — those live in the SECOND owner-authenticated
   object: the annotated CLOSE TAG ci-close/enforcement-<pkg>-r<n> pushed AT C after the check passes…
   Consumers derive C as A's first-parent child (C^ == A) and verify the close tag."

--- SYMMETRY SWEEP: 9 markers × 3 final milestone titles = 27/27 ---
        R7-C-1  TRUSTED-copies  preflight  handback-bound  R7-H-2  R7-H-1  fix_rounds  max_rounds  regs-not-in-candidate
p1 M3      ✓          ✓            ✓            ✓            ✓       ✓         ✓           ✓              ✓
p2 M4      ✓          ✓            ✓            ✓            ✓       ✓         ✓           ✓              ✓
p3 M3      ✓          ✓            ✓            ✓            ✓       ✓         ✓           ✓              ✓

--- §5 step 1 (r15) key clauses ---
:434 "FROM TRUSTED CONTROL INPUTS (gate-r7 R7-C-1) … the PARENT'S PINNED COPIES outside the
      handed-over worktree — never the candidate's; A is supplied only as reviewed data."
     "Control-file preflight first: git diff --name-only <base_sha>..A must show NO change to
      scripts/adversarial-review.sh, this brief, docs/handoff/SELF-REVIEW-HARNESS.md, or
      .claude/agents/*-reviewer.md (any hit is simultaneously a scope FAIL)"
     "Handback binding (gate-r7 R7-H-2): … computes sha256 of the exact handback BEFORE invoking,
      passes path+digest … the reviewer must evaluate THAT file and echo path+digest … the parent
      verifies the echo before acting on any ACCEPT."
     "Final fix rounds (gate-r7 R7-H-1): … parent keeps the round register in its OWN workspace …
      executor alone fixes, commits, increments fix_rounds, resets to status: review, hands over
      again; the parent invokes every round; max_fix_rounds exhaustion = blocked_review; only the
      final ACCEPT register's bytes ever land in C."
:436 "final lines control_sha256: <name>=<hex> for the trusted bridge/brief/lens-contract blobs"
:438 close tag ci-close/enforcement-<pkg>-r<n> at C; amend "ONLY while C is still the unpushed
      local dev tip AND before the close tag exists"

--- EXECUTION MODE exception (:110) ---
"EXCEPTION — the FINAL whole-package milestone (P1-M3 / P2-M4 / P3-M3) is PARENT-INVOKED
 (gate-r6 R6-C-1) from the parent's TRUSTED control-file copies (gate-r7 R7-C-1): you complete its
 implementation, set it status: review, and HAND OVER; the parent runs the bridge itself (its own
 pinned copy, your tree as data only, with a control-file preflight and the handback digest-bound
 in — gate-r7 R7-H-2) … FINAL FIX ROUNDS (gate-r7 R7-H-1): … YOU fix scoped, commit, increment
 fix_rounds, reset the milestone to status: review, and hand over again — the parent invokes every
 round; never commit a parent register into your tree."

--- LEDGER S-14 step 1 (r15) ---
"(1) the PARENT ITSELF invokes the final whole-package bridge FROM ITS TRUSTED CONTROL-FILE COPIES
 (gate-r6 R6-C-1, gate-r7 R7-C-1 — candidate tree = data only; control-file preflight on base_sha..A
 rejects any change to the bridge/brief/harness/lens contracts; handback digest-bound into the review
 and echo-verified — gate-r7 R7-H-2; an executor-run final register is not acceptance evidence;
 final fix rounds per gate-r7 R7-H-1: parent invokes every round, executor fixes/hands over, parent
 registers never committed into the candidate) and, on ACCEPT, immediately digests the
 register/handback bytes from ITS OWN invocation (gate-r5 R5-C-1)"

--- P3-M0 close-tag verification ---
p3:39  "AND (gate-r7 R7-C-2) P1's CLOSE TAG (ci-close/enforcement-p1-r<n>) …"
p3:53  "… and P2's close tag"

--- js-yaml parse ---
p1 PARSE OK · [M0,M1,M2,M3] · owner_gate NONE (0) · pins all present+null · extra-top-level=[]
p2 PARSE OK · [M0,M1,M2,M3,M4] · owner_gate NONE (0) · pins all present+null · extra-top-level=[]
p3 PARSE OK · [M0,M1,M2,M3] · owner_gate NONE (0) · pins all present+null · extra-top-level=[]
line 5 (all three): "# (r15 … the 6 gate-r6 findings, and the 4 gate-r7 findings; re-gate before
   dispatch)"

--- gate-register counts (re-derived from the registers) ---
gate-r1 = 17 · r2 = 11 · r3 = 6 · r4 = 7 · r5 = 7 · r6 = 6
gate-r7 = 4   IDs: R7-C-1 R7-C-2 R7-H-1 R7-H-2   (2C+2H+0M)

--- table cell counts (awk -F'|', cells = NF-2) ---
:99-103 → 4×5 · :134-141 → 3×8 · :159-166 → 2×8 · :182-187 → 3×6      all consistent

--- stale sweeps ---
in-C closing_commit/closing_check → 0                                    CLEAN (new sweep)
admin-tip            → brief:69, :77, :82 (log), :438 (negation)         CLEAN
allocated at promotion → 0 hits                                          CLEAN
wildcard allowlist   → brief:44, :53 (historical)                        CLEAN
record*+BEFORE-merge → brief:31, :50 (historical)                        CLEAN

--- repo citations re-derived ---
ci.yml:3-8 · ci.yml:1104 · ci.yml:1-26 permissions count = 0
StockAdjustmentService.php:1715/:1716/:1719 · GeneralLedgerService.php:3480/:3507
adversarial-review.sh:47/:93/:96/:97 · grep '--handback|self-blob|git cat-file' → NO MATCH
grep -L vitest tools/__tests__/* → 0 · test:tools → 0 · failOnEmptyTestSuite → 0
ParserFactory 4/16 · SystemAccountPurpose cases 41 · ci.yml:629 → 93 · ci.yml:726 → 16

--- check 5 ---
grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule"
  over brief + all three YAMLs → (no output) exit 1
```

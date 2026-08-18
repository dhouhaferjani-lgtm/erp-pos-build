# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r13 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-14

Scope: the brief at r13 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15, all UNCOMMITTED on `dev`. `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca` — unchanged across every round-0 run in this series and identical to the brief's stated verification base.

Run in FULL from the top per the spec's hard rule. Repo-side evidence reused only after re-proving the sources unmodified; all load-bearing citations re-derived this pass. Every doc-side line number re-derived at r13 offsets — nothing copied from any prior report or gate register.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 1 | Revision-log truth (r13: 6 gate-r6 claims; r12/r11 + earlier spot-checks) | **FAIL** | 1 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable     | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 — no permission/module keys named |
| H | Hygiene (pipes, banner + log ordering, header counts, YAML shape, stale sweeps) | **PASS** | 0 — the one stale-text hit is reported as R0-1 under Check 1, not double-counted |

**VERDICT: FAIL — one FAIL row (Check 1). r13 does NOT pass round 0; do not dispatch gate round 7 until R0-1 is fixed and round 0 is re-run from the top.**

Five of the six r13 claims are fully and symmetrically applied — including R6-C-1, which passes the symmetry sweep cleanly across all three final milestone titles. The sixth (**R6-H-2**) added the deterministic-C definition in four places but **left the superseded unbound `<admin-tip>` placeholder standing in four others**, which the round's own instruction defines as a FAIL.

---

## Findings

- **[R0-1] Check 1 · HIGH — R6-H-2's `<admin-tip>` placeholder survives in four operative locations; the deterministic-C rewrite was added beside it, not substituted for it.**

  The r13 log (`:77`) claims: *"step 5's `<admin-tip>` was unbound … Fixed with the **deterministic closing-commit identity**: the closing commit **C is THE first-parent child of A on dev** (`C^ == A`, verified)."* Brief `:430` states this correctly and explicitly: *"**C is THE first-parent child of A on dev; `C^ == A` is verified; there is no `<admin-tip>` placeholder.**"*

  A full-document-set sweep contradicts that last clause:
  ```
  $ grep -n 'admin-tip' <brief + 3 YAMLs + LEDGER>
  brief:69   (r11 revision-log entry)                                  historical  ok
  brief:77   (r13 revision-log entry describing the fix)               historical  ok
  brief:430  "…there is no `<admin-tip>` placeholder."                 negation    ok
  ─────────────────────────────────────────────────────────────────────────────────
  enforcement-p1.progress.yaml:86   git rev-list --count <A>..<admin-tip> == 1   ✗ OPERATIVE
  enforcement-p2.progress.yaml:73   git rev-list --count <A>..<admin-tip> == 1   ✗ OPERATIVE
  enforcement-p3.progress.yaml:96   git rev-list --count <A>..<admin-tip> == 1   ✗ OPERATIVE
  LEDGER.md:56  "git rev-list --count <A>..<admin-tip> == 1 AND every
                 git diff --name-only <A>..<admin-tip> path inside the
                 CURRENT package's closing set"                        ✗ OPERATIVE
  ```

  All four sit in the *"Universal closing sequence note"* (YAMLs) and in S-14's check clause — the operative statements of the very check R6-H-2 was raised about.

  **This is not a wording quibble: each file now states the check twice, once bound and once unbound.** The r13 edit *did* land the C definition nearby in every one of these files — LEDGER S-14 step 5 opens *"ONE closing admin commit C — THE first-parent child of A, C^ == A (gate-r6 R6-H-2)"*, and each YAML's new `closing_receipt` schema comment defines `closing_commit: <C SHA — THE first-parent child…>` (p1 `:91`, p2 `:78`, p3 `:103`). So the correct rule was **added in a new block while the superseded sentence was left in place** — the same append-instead-of-replace pattern that produced the r11 R0-1 finding, and precisely the N-1-of-N application this round was warned against.

  **Consequence is R6-H-2's own failure scenario.** A parent following the universal note or S-14's check clause runs `rev-list`/`diff` against an unbound endpoint, so the `A → C1 → C2` interrupted-close case passes every separately-named predicate while the required one-commit close never happened. No malicious actor needed.

  **Repair:** replace `<admin-tip>` with `C` in `enforcement-p1.progress.yaml:86`, `enforcement-p2.progress.yaml:73`, `enforcement-p3.progress.yaml:96`, and the S-14 check clause in `LEDGER.md:56`, matching brief `:430` (`git rev-list --count <A>..C` == 1 + `git diff-tree C^ C`). Then bump the revision log honestly and re-run round 0 from the top.

No other finding.

---

## Check 1 — Revision-log truth

### The six r13 claims, verified in operative text (brief + YAMLs + LEDGER)

| Claim | Required change | Re-derived verification | OK |
|---|---|---|---|
| **R6-C-1** PARENT-INVOKED final bridge | §5 step 1 rewritten; EXECUTION MODE carries the exception; **all three** final milestone titles carry the FINAL-GATE INVOCATION clause; the re-gate A′ path says "hand over for the PARENT-invoked whole-package bridge"; LEDGER S-14 step 1 matches | **§5 step 1 (`:426`)** — *"**The PARENT ITSELF invokes the final whole-package bridge (gate-r6 R6-C-1)** against the handed-over branch tip **A** (the executor completed the final milestone, set it `status: review`, and handed over — an executor-run "final" register is NOT acceptance evidence). On ACCEPT from the PARENT'S OWN invocation, **the parent immediately computes and records `sha256` digests of the register file(s) its own run produced** … bytes it observed itself, never bytes the executor nominated."* **EXECUTION MODE (`:102`)** — *"At the end of every MID-WAVE milestone you run the adversarial review…"* with the final-milestone exception. **Symmetry sweep — all three final milestone titles carry the IDENTICAL clause, verbatim:** `enforcement-p1.progress.yaml:165` (M3), `enforcement-p2.progress.yaml:170` (M4), `enforcement-p3.progress.yaml:165` (M3) — *"FINAL-GATE INVOCATION (gate-r6 R6-C-1): this milestone's bridge review is PARENT-INVOKED — the executor completes the implementation, sets status: review, and HANDS OVER; the parent runs the bridge itself and digests its own invocation's register; an executor-run final register is not acceptance evidence."* **Re-gate A′ path (`:431`)** — *"rerun every invalidated piece of local evidence, **hand over for the PARENT-invoked whole-package bridge** → NEW accepted tip A′"*. **LEDGER S-14 step 1** matches. **3 of 3 — no N-1 gap.** | ✓ |
| **R6-H-1** handback binding | P3-M0 1d **and** 2d parse `handback_sha256`, require the path at base, compare landed bytes, and require register+handback blobs at base == C's blobs; P3 M0 title items (1)/(2); sequencing-table P3 row; §4 milestones line | **P3 header check 1d (`p3:29-38`)** — *"(gate-r6 R6-H-1) sha256 of the LANDED handback at the annotation's handback path == the annotation's `handback_sha256` (path must exist at base); AND (gate-r6 R6-H-2) P1's closing commit C … AND the register+handback blobs at P3's base EQUAL C's blobs (later rewrites by any lane fail closed)."* **2d (`p3:48-50`)** — *"EVIDENCE BINDING, same shape as 1d incl. gate-r6 R6-H-1/R6-H-2: P2's closing tag annotation … `sha256(landed handback) == handback_sha256`, C derived as first-parent child of…"* **P3 M0 title (`p3:141`)** carries `handback_sha256`. **Sequencing-table P3 row (`:95`)** and **§4 milestones line (`:407`)** both updated. Both binding predicates covered — no 1-of-2 gap. | ✓ |
| **R6-H-2** deterministic C | §5 step 5 defines C, verifies `C^ == A`, no `<admin-tip>`; `git diff-tree` must contain every mandatory closing artifact; P3-M0 1d/2d derive C the same way; LEDGER S-14 step 5 matches | **§5 step 5 (`:430`)** ✓ verbatim, including *"there is no `<admin-tip>` placeholder"*, the `git diff-tree` mandatory-artifact requirement, and the per-package closing set. **P3-M0 (`p3:35-38`, `:50`)** ✓ derives C as the first-parent child of `p*_landed_sha` with `C^ ==` check and base-blob equality. **LEDGER S-14 step 5** ✓ opens with the C definition. **BUT the superseded `<admin-tip>` placeholder survives in four operative locations — p1 `:86`, p2 `:73`, p3 `:96`, LEDGER `:56` check clause.** | **✗ — R0-1** |
| **R6-H-3** idempotent recovery | re-gate paragraph carries the idempotency rule (matching tag = step 3 done; mismatch = re-gate with a new name); §5 step 5 carries preflight-before-commit + amend-only-while-unpushed-dev-tip; LEDGER S-14 matches | **Re-gate (`:431`)** — *"**Idempotency (gate-r6 R6-H-3): on a same-A retry, an existing tag whose target AND full annotation exactly match `{A, digests}` IS the successful step 3 — resume after it (a free-tag preflight does not re-fire); an existing tag that MISMATCHES = re-gate with a newly reviewed name (the old tag stays, never deleted, never reused).**"* **Step 5 (`:430`)** — *"PREFLIGHT the staged set BEFORE committing … **Step-5 recovery (gate-r6 R6-H-3): a failed post-commit check may be repaired by AMEND/REDO ONLY while C is still the unpushed local dev tip** (explicitly authorized — local, unpushed, single-writer; the amend is recorded in the receipt); once anything else lands on dev, a failed close BLOCKS the next promotion until a verified corrective closing state exists."* **LEDGER S-14** carries the amend-only-while-unpushed authorization. | ✓ |
| **R6-H-4** universal closing receipt + P3 pre-allocation | new top-level `closing_receipt` in **all three** YAMLs with schema comment; new `p3_closing_pin_tag` with metadata-commit-before-M3 pre-allocation comment; the r11 "allocated at promotion" note REPLACED; §5 step 3 + LEDGER S-14 say the name is reviewed in A for ALL packages | **`closing_receipt` present and null in all three** — `p1:95`, `p2:82`, `p3:107` — confirmed real top-level keys by the js-yaml parse, each with the schema comment (`closing_commit:` C's SHA · `tag:` · `closing_check:` verbatim output · `digests:` · workflow packages point to `pre_promotion_ci_dispatch`). **`p3_closing_pin_tag` present and null at `p3:77`** with the pre-allocation comment; `p3:100` routes the name into *"the structured `closing_receipt`"*. **Supersession sweep for the r11 affordance** (`allocated at promotion|allocate at promotion|allocated live`) → **zero hits anywhere**; the note was genuinely replaced, not appended. **§5 step 3 (`:428`)** and **LEDGER S-14** both state the pre-allocated-name-from-A rule universally. **3 of 3 receipts + the P3 field — no gap.** | ✓ |
| **R6-H-5** post-dispatch re-verify + workflow-authority check | §5 step 4a re-verifies tag target + exact annotation bytes + variable AFTER any dispatch, mismatch = re-gate trigger; owner check rejects candidate workflow diffs granting `contents: write`; F-8 carries the `ci-pin/*` ruleset hardening option; LEDGER S-14 step 4 matches | **Step 4a (`:429`)** — *"**full pre-merge RE-verification (… extended gate-r6 R6-H-5, AFTER any dispatch run):** repeat `git merge-base --is-ancestor <current-dev-tip> A`, AND re-fetch the closing tag verifying its target == A and its EXACT annotation bytes, AND re-read the repository variable verifying its value — any mismatch (a candidate workflow with self-granted write authority could have moved them) → re-gate protocol. **Owner workflow-authority check (gate-r6 R6-H-5): if the candidate's workflow diff grants content/ref write authority (`permissions:` containing `contents: write` or equivalent in any changed workflow file), promotion is BLOCKED**"*. **F-8 (`:446`)** carries the `ci-pin/enforcement-*` ruleset hardening option. **LEDGER S-14 step 4** matches. | ✓ |

### Earlier-revision spot-checks (re-derived at r13 offsets)

- **r12 items** — the three per-package closing sets are intact (no wildcard regression: sweep → 2 hits, brief `:44`/`:53`, both historical); the step-3 annotation schema (`accepted_sha:` / `register_sha256:` / `handback_sha256:`) is intact at `:428` and is now *consumed* by P3-M0's handback comparison, closing the producer↔consumer loop. ✓
- **r11 / gate-r5** — pin-tag pre-allocation, "pin tags are NEVER deleted" (deletion sweep → 1 hit, brief `:56`, historical), single-writer serialization, `ratchet_trust_model_ack` as an M0 predicate, and the named per-package closing set all intact. ✓
- **r10/r9/r8/r7 and earlier** — LOCAL AUTHORITY SETUP in all four consuming milestone titles; "OWNER-performed"; "no push, no credentials"; `record*+BEFORE-merge` → 2 hits (brief `:31`, `:50`), both historical; `p2_m2_landed_sha` and `node --test` only in historical/supersession text. ✓

**Check 1: FAIL** (R0-1).

---

## Check 2 — Exhaustive claims / censuses (re-run)

`git status --porcelain apps/api apps/web .github scripts` → only untracked `scripts/dev-scan-stack.sh` (not a citation target); no tracked modification at an unchanged HEAD. r13 introduced no new inventory claim (its content is promotion protocol and evidence binding). Censuses re-run:

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

r13's additions are promotion-side and all mechanically runnable; the executor-side acceptance blocks are unchanged.

| Contract | Command present | Physically executable | Mock-of-subject |
|---|---|---|---|
| P1 / P2 LOCAL AUTHORITY SETUP + acceptance | ✓ | ✓ `env -u` subshell preserves the outer export | none |
| P1 tamper 1–5, scope/allowlist, aggregate grep | ✓ | ✓ | none |
| P3 acceptance (census-derived `--filter` + nonzero count, phpstan) | ✓ | ✓ grounded by the re-verified absence of `failOnEmptyTestSuite` | none |
| **Step 1 parent-invoked bridge (`:426`)** | ✓ the parent runs `scripts/adversarial-review.sh` from its own session | ✓ the script is a normal CLI with `--brief/--milestone/--lenses/--range/--out/--round`; nothing prevents a second actor invoking it against the handed-over tip | **none — and this contract specifically removes a self-attestation path**, since the digests are now taken from the parent's own invocation |
| Step 2 preflight + read-back (`:427`) | ✓ | ✓ | n/a |
| Step 3 annotated tag + schema (`:428`) | ✓ | ✓ `git tag -a` body is free-form; `git cat-file tag` reads it back deterministically | n/a |
| **Step 4a full re-verification (`:429`)** | ✓ ancestry + `git fetch`/tag-target + exact annotation bytes + variable read | ✓ all plumbing; the workflow-authority check is a `grep` over the candidate's changed workflow files | n/a |
| **Step 5 deterministic C (`:430`)** | ✓ `C^ == A`, `git rev-list --count <A>..C` == 1, `git diff-tree C^ C` | ✓ **in the brief.** The YAML/LEDGER restatements remain unbound (R0-1) — a specification defect, not an executability one; once `<admin-tip>` → `C` the check is fully decidable | n/a |
| P3-M0 1d/2d binding (`p3:29-50`) | ✓ fetch tag → parse annotation → `sha256` compare register **and handback** → derive C → compare base blobs to C's blobs | ✓ deterministic given the r12 schema + r13 C-derivation | n/a |
| M0 preconditions (3 YAMLs) | ✓ | ✓ seam-grep target still at `StockAdjustmentService.php:1719` | n/a |

No impossible interleavings, no barriers on lazily-created rows, no contract asserting through a mock of its subject. **PASS.**

---

## Check 4 — Behavior/repo claims cite source

**Re-derived this pass:**
```
ci.yml:3-8    push.branches [main] · pull_request.branches [main, dev] · workflow_dispatch  (nothing else)
ci.yml:1104   needs: [… frontend-lint …]
ci.yml:1-26   NO top-level `permissions:` block            ← grounds R6-H-5's premise (re-derived)
StockAdjustmentService.php:1715/:1716/:1719   ?StockMovementReferenceType / ?string $referenceId /
                                               assertReferenceLinkagePaired(...)
GeneralLedgerService.php:3480/:3507            sealAndPersistEntry(...) / bccomp(...) !== 0
adversarial-review.sh:47 mkdir · :93 cp "$TMP" "$OUT" · :96/:97 VERDICT_LINE parse
```
The bridge lines remain the grounding for R6-C-1: the register is an ordinary file produced by `cp` at `:93` and ACCEPT is a parsed text line at `:96-97` — no receipt, nonce or signature — which is exactly why the r13 repair moves the *invocation* to the parent rather than adding cryptography.

**Owner-attested external facts (recorded, not re-derived — round 0 makes no network calls):**
1. **`default_workflow_permissions: read`** (cited at `:429` and in the r13 log `:80` as "verified via `gh api`"). Round 0 cannot query GitHub. Recorded as **attested**. Note the claim is used conservatively — the sentence's operative force is *"so only an explicit grant matters"*, and the blocking rule keys off the candidate's own `permissions:` diff, which **is** locally checkable. The repo-side half of the premise (no top-level `permissions:` block in `ci.yml:1-26`) **was** re-derived above.
2. **`gh api → owner.type: "User"`** in F-8 — same status, as in every prior round.

**Conjunction check (house law).** (a) `frontend-lint` ↔ `all-checks-pass` `needs` — edge present at `:1104`. (b) `frontend-lint` ↔ the `package.json` lint chain — edge correctly denied. (c) pin-tag name pinned in A ↔ created at step 3 ↔ fetched by P3-M0 — one identifier across all three. (d) **New for r13:** the annotation's `handback_sha256` (produced at `:428`) ↔ P3-M0's handback comparison (`p3:33-34`, `:50`) — the r12 schema field that had no consumer now has one; producer and consumer agree. (e) **New for r13:** step 1's parent-invoked bridge ↔ the three milestone titles' `status: review` handover ↔ the re-gate A′ path — all three sides opened and consistent.

**PASS.**

---

## Check 5 — Permission keys

```
$ grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule" \
    docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md \
    docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml
(no output)   exit 1
```
No permission or module keys named. (Note: the `permissions:` strings introduced by R6-H-5 are GitHub Actions workflow-permission tokens, not AutoERP permission keys, and do not match the key patterns.) **N/A — PASS.**

---

## Hygiene

- **Banner ↔ latest log entry, including the ordering check.** Banner `:4` = *"**Revision:** r13 — **2026-08-14**, full gate-r6 fix round applied (…gate-r6.md: R6-C-1, R6-H-1..5 — itemized log below), **NOT yet re-gated**. Re-run round 0 from the top, then **gate round 7**, before dispatch."* Full revision-block anchor list re-derived: `:4` banner · `:5` (r1/r2/r3) · `:24` r4 · `:27` r5 · `:29` r6 · `:42` r7 · `:50` r8 · `:52` r9 · `:61` r10 · `:63` r11 · `:72` r12 · **`:74` r13**. **Strictly monotonic — the r13 block IS last**; the ordering slip flagged for this round is corrected in the state reviewed here. Rev, date and next-gate number all consistent. ✓
- **YAML line-5 headers.** All three read **r13** and end *"…the 7 gate-r4 findings, the 7 gate-r5 findings, and **the 6 gate-r6 findings**; re-gate before dispatch)."* Counts re-derived **from the registers themselves**: gate-r1 → **17** · gate-r2 → **11** · gate-r3 → **6** · gate-r4 → **7** · gate-r5 → **7** · gate-r6 `grep -cE '^### R6-(C|H|M)-[0-9]+'` → **6**, IDs `R6-C-1 R6-H-1 R6-H-2 R6-H-3 R6-H-4 R6-H-5` = **1C+5H+0M**. All six counts correct. ✓
- **Unescaped GFM pipes.** Four normative tables re-checked by `awk -F'|'` at r13 offsets — sequencing `:91-95` (header 4, rows 4) · read-order `:126-133` (3) · DO-NOT-TOUCH `:151-158` (2) · write-surface contract `:174-179` (3). Every row matches its header; the new `permissions:`/`contents: write` and `C^ == A` text sits outside all tables. ✓
- **YAML validity / shape (js-yaml, from `apps/web`).** All three parse. One `base_sha`, one `branch`, top-level `status: pending`. Milestones ordered and complete: **P1 `[M0,M1,M2,M3]`, P2 `[M0,M1,M2,M3,M4]`, P3 `[M0,M1,M2,M3]`** ✓. **Milestone-level `owner_gate:` fields = 0 / 0 / 0** ✓. Pin sets complete, all `null`, none pre-filled — P1/P2 now include **`closing_receipt`**; P3 includes **`p3_closing_pin_tag`** and **`closing_receipt`** ✓. `p2_m2_landed_sha` a key in none ✓.
- **Stale sweeps.**
  - **`<admin-tip>` placeholder** → **7 hits: 3 historical/negation in the brief, 4 OPERATIVE (p1 `:86`, p2 `:73`, p3 `:96`, LEDGER `:56`)** — reported as R0-1 under Check 1.
  - "allocated at promotion" (the r11 P3 affordance) → **zero hits anywhere** ✓ genuinely replaced.
  - wildcard closing allowlist → **2 hits, brief `:44`, `:53`**, both historical ✓.
  - tag deletion/retention affordance → **1 hit, brief `:56`**, historical ✓.
  - `record* … BEFORE merge` receipts → **2 hits, brief `:31`, `:50`**, both historical ✓.
  - `p2_m2_landed_sha` / operative `node --test` → historical or supersession only ✓.

**Hygiene: PASS** for pipes, banner+ordering, header counts, YAML shape and five of six sweeps; the `<admin-tip>` sweep result is carried as the Check-1 finding rather than double-counted.

---

## Note-only observations (not findings)

1. **Two owner-attested external facts** (`default_workflow_permissions: read`; `owner.type: "User"`) are recorded as attested, not re-derived — round 0 makes no network calls. Both are used conservatively and the locally-checkable halves (`ci.yml:1-26` has no top-level `permissions:`; the candidate's own workflow diff) were re-derived.
2. **`TreasuryReceiptBridge` lives under `Application/Projections/`**, not `Application/Services/`; the brief cites only line numbers for it (all verify).
3. **Arabic-authored entries interleave the cited `i18n.ts` alias ranges** — the claim about what those ranges demonstrate is correct.
4. **`wave3-3c-3d.progress.yaml` M3 remains `status: pending`** — consistent with treating ACCEPT as a dispatch-time P1-M0 precondition.
5. **`SystemAccountPurpose::expectedAccountType()` is at `:187`; the brief writes `:~186`** — within the marked tolerance.
6. **R6-C-1 changes the harness's economics, not its mechanics.** The parent now runs three extra bridge invocations (one per package). Nothing in `scripts/adversarial-review.sh` prevents this, and the milestone titles + EXECUTION MODE block are consistent about who invokes what — flagged only so the gate reviewer knows the round-0 check found no mechanical obstacle to the new division of labour.

---

## Evidence appendix (raw outputs)

```
$ git rev-parse HEAD
a5520f23ca39209f5b517723037e9516808f2bca      (unchanged; == brief base a5520f23c)

$ wc -l <targets>
461 CODEX-DISPATCH-enforcement-guards-2026-08-12.md   (r12: 453 → +8)
174 enforcement-p1.progress.yaml                      (r12: 168 → +6)
179 enforcement-p2.progress.yaml                      (r12: 173 → +6)
174 enforcement-p3.progress.yaml                      (r12: 155 → +19)
105 LEDGER.md                                          (S-14 rewritten in place)

$ ls -lT
Aug 14 10:04:49 brief · Aug 14 10:04:31 LEDGER · Aug 14 10:03:36 p1,p2,p3

$ git status --porcelain apps/api apps/web .github scripts
?? scripts/dev-scan-stack.sh          (untracked, not a citation target)

--- R0-1: the <admin-tip> sweep ---
brief:69   (r11 log)                                                     historical
brief:77   (r13 log describing the fix)                                  historical
brief:430  "…C is THE first-parent child of A on dev; C^ == A is
            verified; there is no `<admin-tip>` placeholder."            negation (correct)
p1:86      "# (git rev-list --count <A>..<admin-tip> == 1; diff paths
            only in THIS package's closing set) apply to"                ** OPERATIVE **
p2:73      same                                                          ** OPERATIVE **
p3:96      same                                                          ** OPERATIVE **
LEDGER:56  "git rev-list --count <A>..<admin-tip> == 1 AND every
            git diff --name-only <A>..<admin-tip> path inside the
            CURRENT package's closing set"                               ** OPERATIVE **
Correct C definitions DO exist alongside them:
  LEDGER:56  "(5) ONE closing admin commit C — THE first-parent child of A, C^ == A (gate-r6 R6-H-2)"
  p1:91 · p2:78 · p3:103  "closing_commit: <C SHA — THE first-parent child…>"
  p3:35-36 · p3:50        P3-M0 derives C the same way
⇒ added beside, not substituted for

--- revision-log ordering (the flagged slip, now correct) ---
:4 banner · :5 (r1/r2/r3) · :24 r4 · :27 r5 · :29 r6 · :42 r7 · :50 r8 · :52 r9 ·
:61 r10 · :63 r11 · :72 r12 · :74 r13         → strictly monotonic, r13 LAST

--- R6-C-1 symmetry (3 of 3) ---
p1:165 (M3) · p2:170 (M4) · p3:165 (M3) — identical FINAL-GATE INVOCATION clause:
"this milestone's bridge review is PARENT-INVOKED — the executor completes the implementation,
 sets status: review, and HANDS OVER; the parent runs the bridge itself and digests its own
 invocation's register; an executor-run final register is not acceptance evidence."
§5 step 1 :426 · re-gate A′ path :431 ("hand over for the PARENT-invoked whole-package bridge")

--- R6-H-4 fields (js-yaml-confirmed, all null) ---
p1:95 closing_receipt · p2:82 closing_receipt · p3:77 p3_closing_pin_tag · p3:107 closing_receipt
"allocated at promotion" sweep → ZERO hits

--- js-yaml parse ---
p1 PARSE OK · [M0,M1,M2,M3] · owner_gate NONE (0) · pins all present+null · p2_m2=false
p2 PARSE OK · [M0,M1,M2,M3,M4] · owner_gate NONE (0) · pins all present+null · p2_m2=false
p3 PARSE OK · [M0,M1,M2,M3] · owner_gate NONE (0) · pins all present+null · p2_m2=false
line 5 (all three): "# (r13 … the 7 gate-r4 findings, the 7 gate-r5 findings, and the 6 gate-r6
   findings; re-gate before dispatch)"

--- gate-register counts (re-derived from the registers) ---
gate-r1 = 17 · gate-r2 = 11 · gate-r3 = 6 · gate-r4 = 7 · gate-r5 = 7
gate-r6 = 6   IDs: R6-C-1 R6-H-1 R6-H-2 R6-H-3 R6-H-4 R6-H-5  (1C+5H+0M)

--- table cell counts (awk -F'|', cells = NF-2) ---
:91-95 → 4×5 · :126-133 → 3×8 · :151-158 → 2×8 · :174-179 → 3×6      all consistent

--- other stale sweeps ---
"allocated at promotion"  → 0 hits                                        CLEAN
wildcard allowlist        → brief:44, :53 (historical)                    CLEAN
tag deletion/retention    → brief:56 (historical)                         CLEAN
record*+BEFORE-merge      → brief:31, :50 (historical)                    CLEAN

--- repo citations re-derived ---
ci.yml:3-8 · ci.yml:1104 · ci.yml:1-26 (no top-level permissions: block)
StockAdjustmentService.php:1715/:1716/:1719 · GeneralLedgerService.php:3480/:3507
adversarial-review.sh:47/:93/:96/:97
grep -L vitest tools/__tests__/* → 0 · test:tools → 0 · failOnEmptyTestSuite → 0
ParserFactory 4/16 · SystemAccountPurpose cases 41 · ci.yml:629 → 93 · ci.yml:726 → 16
grep -rn 'test:eslint-rules|tools/__tests__' .github/workflows/ → exit 1

--- check 5 ---
grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule"
  over brief + all three YAMLs → (no output) exit 1
```

# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r12 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-13

Scope: the brief at r12 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15, all UNCOMMITTED on `dev`. `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca` — unchanged across every round-0 run in this series and identical to the brief's stated verification base.

Run in FULL from the top per the spec's hard rule. Repo-side evidence reused only after re-proving the sources unmodified; all load-bearing citations re-derived this pass. Every doc-side line number re-derived at r12 offsets — nothing copied from any prior report or gate register.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 0 | Diff-scope of the r12 edit ("No other text changed") | **PASS** | 0 |
| 1 | Revision-log truth (r12: 3 items; r11's 7 gate-r5 claims; r10/r9 + earlier spot-checks) | **PASS** | 0 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable     | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 — no permission/module keys named |
| H | Hygiene (pipes, banner, header counts, YAML shape, stale sweeps) | **PASS** | 0 |

**VERDICT: PASS — all rows. r12 is round-0 clean and gate-round-6 ready.** The r11 finding R0-1 is closed: the wildcard closing-allowlist sweep now returns **zero non-historical hits**, and the newly schema-specified tag annotation is consistent with every prose description of the same payload. No new finding.

---

## Check 0 — Diff-scope of the r12 edit

The r12 log entry (`:72`) asserts *"No other text changed."* All target files are untracked, so scope was established from structural invariants measured against the r11 text this reviewer read in the prior run.

**Permitted change sites:** (1) the three YAML wildcard→own-closing-set replacements, (2) the §5 step 3 annotation schema, (3) the banner + new r12 log entry + three YAML line-5 headers.

### Brief — +2, entirely the r12 log entry

`wc -l` = **453** (r11 = 451). Every section anchor shifted by exactly **+2**:

| Anchor | r11 | r12 | Δ |
|---|---|---|---|
| banner / r3–r11 log blocks (`:4`, `:5`, `:24`, `:27`, `:29`, `:42`, `:50`, `:52`, `:61`, `:63`) | — | identical | 0 |
| **r12 log block (new)** | — | **72** | **+2** (entry + blank) |
| Executor · SEQUENCING · EXEC-MODE · §0 · §1 · §2 | 72 / 77 / 91 / 114 / 127 / 154 | 74 / 79 / 93 / 116 / 129 / 156 | +2 |
| §2 subs · §3 + subs · §4 + subs | all | all | **+2** |
| §5 · §6 · §7 | 401 / 427 / 440 | 403 / 429 / 442 | +2 |

**Every inter-section span is unchanged**, including §5 → §6 (26 lines in both revisions). Item 2 (the step-3 annotation schema) is therefore an **in-line insertion into step 3's existing single line**, which is exactly what a scope-correct edit to this file's long-line style produces — it adds no line and shifts nothing.

### YAMLs — deltas exactly account for the three block replacements

| File | r11 | r12 | Δ | Replacement span | Accounted |
|---|---|---|---|---|---|
| p1 | 167 | **168** | +1 | `:77-79` (3 lines) → `:77-80` (4) | ✓ |
| p2 | 172 | **173** | +1 | `:63-66` (4) → `:63-67` (5) | ✓ |
| p3 | 153 | **155** | +2 | `:75-76` (2) → `:75-78` (4) | ✓ |

Anchors **before** each change point are unmoved (`base_sha` at p1 `:33`, p2 `:14`, p3 `:62`); anchors **after** shift by exactly the file's delta (p1 `pre_promotion_ci_dispatch` 88→**89**, `ratchet_trust_model_ack` 103→**104**; p2 `ratchet_trust_model_ack` 91→**92**; p3 `pre_promotion_ci_dispatch` 86→**88**, `milestones:` 118→**120**). No residual movement anywhere, so no unaccounted edit. The line-5 header replacement is one line → one line, adding nothing.

### LEDGER — untouched

`ls -lT`: brief **08:39:35**, YAMLs **08:39:59**, `LEDGER.md` **08:31:53** — the LEDGER predates the r12 edit window (it was last written for r11). Its S-14 row still carries the narrowed per-package closing set and the step-1 digest requirement.

### Repo files — unmodified

`git status --porcelain apps/api apps/web .github scripts` → only untracked `scripts/dev-scan-stack.sh` (not a citation target); no tracked modification at an unchanged HEAD.

**Check 0: PASS.**

---

## Check 1 — Revision-log truth

### The three r12 claims

| Claim (`:72`) | Verified | OK |
|---|---|---|
| **(1) The three superseded wildcard sentences REPLACED with each package's OWN closing set** | **p1 `:77-80`** — *"diff ⊆ **P1's OWN closing set** (narrowed gate-r5 R5-H-3): `docs/handoff/progress/enforcement-p1.progress.yaml` · `docs/handoff/LEDGER.md` · `docs/handoff/reviews/enforcement-p1/**` · `docs/handoff/HANDBACK-enforcement-p1-*.md` — **ANOTHER package's evidence or any production/workflow path = closing FAIL** (admin-only-delta, `a5520f23c`)."* **p2 `:63-67`** — same shape, `enforcement-p2` throughout. **p3 `:75-78`** — same shape, `enforcement-p3` throughout. Each package now names **only its own** progress YAML, review subtree and handback, plus the shared LEDGER — matching brief `:422` and LEDGER S-14 exactly. The contradictory pairing that produced r11's R0-1 is gone: each block now states one rule, and the universal note four lines below (p1 `:82-88`, p2 `:69-75`, p3 `:80-87`) reinforces rather than contradicts it. | ✓ |
| **(2) §5 step 3 SCHEMA-specifies the annotation** | **`:420`** — *"annotation recording `{A, sha256(final register(s)), sha256(handback)}` (gate-r5 R5-C-1), **in the EXACT machine-parseable schema (r12 ruling — P3-M0 must parse it): line 1 `accepted_sha: <A>`; line 2 `register_sha256: <path>=<hex>` (one line per register file); last line `handback_sha256: <path>=<hex>`**"*. Deterministic field names, deterministic ordering, and an explicit multi-register rule — the ambiguity flagged as note 6 of the r11 report is closed. | ✓ |
| **(3) Banner → r12 + r12 log entry + three YAML headers at r12** | Banner `:4` = *"**Revision:** r12 — 2026-08-13, round-0 r11 R0-1 fix applied on top of the full gate-r5 fix round (r11: …gate-r5.md, R5-C-1..3, R5-H-1..4 — itemized log below), **NOT yet re-gated**. Re-run round 0 from the top, then **gate round 6**, before dispatch."* r12 log block at `:72`, genuinely last (r11 at `:63` precedes it). All three YAML line-5 headers read *"# (r12 of the brief applies all 17 gate-r1 findings, round-0 R0-3/R0-4 **+ r7-R0-1 + r9-R0-1 + r11-R0-1**, the 11 gate-r2 findings, the 6 gate-r3 findings, the 7 gate-r4 findings, and the 7 gate-r5 findings; re-gate before dispatch)."* | ✓ |
| **"No other text changed"** | Proven in Check 0: brief +2 = the log entry with every span unchanged; YAML deltas +1/+1/+2 exactly matching the three replacements with no residual anchor movement; LEDGER untouched. | ✓ |

### The wildcard-sweep-now-clean assertion — independently re-run

```
$ grep -n 'progress/\*\.progress\.yaml\|enforcement-p{1,2,3}/\*\*\|HANDBACK-enforcement-\*\.md' \
    <brief + 3 YAMLs + LEDGER>
docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:44   (r7 revision-log entry — R3-C-2)
docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:53   (r9 revision-log entry — R4-C-1)
```
**Two hits, both inside historical revision-log entries; zero in any YAML and zero in operative brief/LEDGER text.** The claim holds exactly as stated.

### Annotation schema ↔ prose payload consistency (the specific risk flagged for this round)

Every location describing the annotation was opened and its payload compared field-by-field against the step-3 schema:

| Location | Payload described | Matches schema? |
|---|---|---|
| brief `:420` (§5 step 3 — the schema itself) | `accepted_sha: <A>` · `register_sha256: <path>=<hex>` (per register file) · `handback_sha256: <path>=<hex>` | — (authoritative) |
| brief `:418` (step 1) | `sha256` of the EXACT bridge-output register file(s) **and** the handback | ✓ same three items |
| brief `:64` (r11 log), `:438` (F-8) | `{A, sha256(final register(s)), sha256(handback)}` | ✓ |
| brief `:87` (sequencing P3 row), `:399` (§4 milestones) | annotation's A == landed tip; `sha256` of the landed register == annotation digest | ✓ subset, no extra/absent field |
| p1 `:56`, `:84` · p2 `:44-45`, `:71` · p3 `:82` | `{A, sha256(register(s)), sha256(handback)}` | ✓ |
| p3 `:32`, `:45`, `:122` (M0 1d/2d + M0 title) | annotation A == landed tip; `sha256(landed register)` == recorded digest | ✓ |
| LEDGER S-14 | `sha256` digests of the EXACT bridge-output register file(s) + handback before anything edits them | ✓ |

**All thirteen locations describe the same three-item payload — accepted SHA, per-register digest(s), handback digest.** The schema is a serialization of that set, not a different payload; the plural "register(s)" in prose is honoured by the schema's explicit "one line per register file". **No contradiction — PASS**, and P3-M0's parse target is now unambiguous.

### The seven gate-r5 (r11) claims — re-verified at r12 offsets

| Claim | Re-derived | OK |
|---|---|---|
| **R5-C-1** annotated closing tag binding | step 1 digest (`:418`), annotated tag + schema (`:420`), exact-bytes landing (`:422`), P3-M0 1d/2d (`p3:29-35`, `:44-46`), P3 M0 title (`p3:122`), sequencing row (`:87`), §4 milestones (`:399`), F-8 (`:438`) | ✓ |
| **R5-C-2** tag lifecycle | pre-allocation in metadata commit 2 (`:183`, `:286`, p1 `:51-58`, p2 `:44-45`, both M2/M1 titles), owner creates exactly that tag and verifies resolution before variable/dispatch (`:420`), **"Pin tags are NEVER deleted"**. Deletion/retention sweep → **2 hits, brief `:56` (r9 log) and `:65` (r11 log)**, both historical; zero operative survivors | ✓ |
| **R5-C-3** race | single-writer serialization (`:416`), step 4a re-assert (`:421`), re-gate trigger (`:423`), all three YAML universal notes, LEDGER S-14 | ✓ |
| **R5-H-1** trust ack | `ratchet_trust_model_ack` real keys at p1 `:104` / p2 `:92` (parse-confirmed, null); M0 item (6) in P1 and (5) in P2; F-8 declares it operative | ✓ |
| **R5-H-2** fail-closed per side effect | step 2 preflight + read-back verify (`:419`), broadened triggers + restore/retry/re-gate + announcement re-send (`:423`) | ✓ |
| **R5-H-3** named closing check | brief `:422`, LEDGER S-14, all three YAML universal notes, P3-M0 1d/2d — **and now the per-package allowlist blocks themselves (item 1)** | ✓ |
| **R5-H-4** universality | §5 header "UNIVERSAL for EVERY package" (`:416`), step 2 "(Ratchet packages P1/P2 only.)", step 3 "(Workflow-touching packages only:)" with the tag created either way, `:424` "For workflow-touching packages…", p3 `:86-87` no-CI-consumer note | ✓ |

### Earlier-revision spot-checks

r10/r9 items (P1-M3 LOCAL AUTHORITY SETUP clause; "OWNER-performed"; "no push, no credentials") all intact. `record*+BEFORE-merge` → **2 hits, brief `:31`, `:50`**, both revision-log entries. `p2_m2_landed_sha` → brief `:37`/`:46`/`:87` + p3 `:17`/`:122`, all historical or supersession. `node --test` → brief `:34`/`:299` + p2 `:140`, all inside the R2-H-3 removal ruling. ✓

**Check 1: PASS.**

---

## Check 2 — Exhaustive claims / censuses (re-run)

r12 introduced no inventory claim. Every census target unmodified at an unchanged HEAD; censuses re-run anyway:

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

r12 strictly improves this check: the previously prose-only annotation is now a fixed three-field format, so P3-M0's parse step is deterministic rather than best-effort.

| Contract | Command present | Physically executable | Mock-of-subject |
|---|---|---|---|
| P1 / P2 LOCAL AUTHORITY SETUP + acceptance (`:209-216`, `:305-310`) | ✓ | ✓ `env -u` subshell preserves the outer export | none |
| P1 tamper 1–5, scope/allowlist, aggregate grep | ✓ | ✓ | none |
| P3 acceptance (census-derived `--filter` + nonzero count, phpstan) | ✓ | ✓ grounded by the re-verified absence of `failOnEmptyTestSuite` | none |
| Promotion step 1 digest (`:418`) | ✓ `sha256` over named files | ✓ | n/a |
| Step 2 preflight + read-back (`:419`) | ✓ | ✓ | n/a |
| **Step 3 annotated tag (`:420`)** | ✓ + **exact schema** | ✓ `git tag -a` annotation body is free-form text, so the three-line format is directly writable; `git for-each-ref --format='%(contents)'` / `git cat-file tag` reads it back deterministically | n/a |
| Step 4a re-assert (`:421`) | ✓ | ✓ | n/a |
| **Step 5 named closing check (`:422`)** | ✓ `git rev-list --count <A>..<admin-tip>` == 1 AND `git diff --name-only` path test | ✓ **and now decidable** — the r11 ambiguity (two conflicting allowlists in the YAMLs) is resolved; each package's set is a closed four-entry list | n/a |
| **P3-M0 1d/2d binding (`p3:29-46`)** | ✓ fetch tag → parse annotation → compare `accepted_sha` and `register_sha256` | ✓ deterministic given the r12 schema | n/a |
| M0 preconditions (3 YAMLs) | ✓ | ✓ seam-grep target still at `StockAdjustmentService.php:1719` | n/a |

No impossible interleavings, no barriers on lazily-created rows, no contract asserting through a mock of its subject. **PASS.**

---

## Check 4 — Behavior/repo claims cite source

Re-derived this pass:
```
ci.yml:3-8    push.branches [main] · pull_request.branches [main, dev] · workflow_dispatch  (nothing else)
ci.yml:1104   needs: [… frontend-lint …]
StockAdjustmentService.php:1715/:1716/:1719   ?StockMovementReferenceType / ?string $referenceId /
                                               assertReferenceLinkagePaired(...)
GeneralLedgerService.php:3480/:3507            sealAndPersistEntry(...) / bccomp(...) !== 0
adversarial-review.sh:47 mkdir · :93 cp "$TMP" "$OUT" · :96/:97 VERDICT_LINE parse
```
The bridge lines still ground R5-C-1's premise — the register is materialised by `cp` at `:93` after the review runs against the already-committed tip, so it cannot be inside A, which is why the digest-plus-annotation binding is needed.

**Conjunction check (house law).** (a) `frontend-lint` ↔ `all-checks-pass` `needs` — edge present at `:1104`. (b) `frontend-lint` ↔ the `package.json` lint chain — edge correctly denied. (c) pin-tag name: pinned in metadata commit 2 (`p1:51-58`) ↔ created by the owner at step 3 (`:420`, "exactly that tag" from A's reviewed mirror) ↔ fetched by P3-M0 (`p3:30-31`, "name from its YAML mirror field") — one identifier across all three. (d) **New for r12:** the step-3 annotation **schema** ↔ the payload P3-M0 **parses** — `:420` writes `accepted_sha` / `register_sha256` / `handback_sha256`; `p3:32` compares "annotation A == landed tip" and "sha256 of the LANDED register file == the annotation's register digest". Producer and consumer now agree on a named format, closing the last prose-to-parser gap.

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

- **Banner ↔ latest log entry.** Banner `:4` = r12, 2026-08-13, "round-0 r11 R0-1 fix applied on top of the full gate-r5 fix round", NOT yet re-gated, next step **gate round 6**. Log anchors: `:4` · `:5` · `:24` · `:27` · `:29` · `:42` · `:50` · `:52` · `:61` · `:63` · **`:72` r12** — r12 genuinely last. Consistent. ✓
- **YAML line-5 headers.** All three at **r12** with the honest `+ r11-R0-1` addition. Counts re-derived **from the registers themselves**: gate-r1 → **17**, gate-r2 → **11**, gate-r3 → **6**, gate-r4 → **7**, gate-r5 → **7**. All five match the header. ✓
- **Unescaped GFM pipes.** Four normative tables re-checked by `awk -F'|'` at r12 offsets — sequencing `:83-87` (header 4, rows 4) · read-order `:118-125` (3) · DO-NOT-TOUCH `:143-150` (2) · write-surface contract `:166-171` (3). Every row matches its header; the new `accepted_sha:`/`<path>=<hex>` schema text sits outside all tables. ✓
- **YAML validity / shape (js-yaml, from `apps/web`).** All three parse. One `base_sha`, one `branch`, top-level `status: pending`. Milestones ordered and complete: **P1 `[M0,M1,M2,M3]`, P2 `[M0,M1,M2,M3,M4]`, P3 `[M0,M1,M2,M3]`** ✓. **Milestone-level `owner_gate:` fields = 0 / 0 / 0** ✓. Pin sets complete, all `null`, none pre-filled — P1 with `dpa_baseline_pin_tag` + `ratchet_trust_model_ack`, P2 with `i18n_baseline_pin_tag` + `ratchet_trust_model_ack`, P3 unchanged ✓. `p2_m2_landed_sha` a key in none ✓.
- **Stale sweeps.**
  - **wildcard closing allowlist** → **2 hits, brief `:44` and `:53`, both revision-log entries; zero in the YAMLs.** ✓ *(the r11 finding, now closed)*
  - **tag deletion/retention affordance** → **2 hits, brief `:56` (r9 log) and `:65` (r11 log announcing the revocation)**; zero operative. ✓
  - `record* … BEFORE merge` receipts → **2 hits, brief `:31`, `:50`**, both historical. ✓
  - `p2_m2_landed_sha` → brief `:37`/`:46`/`:87` + p3 `:17`/`:122`, historical or supersession; not a key anywhere. ✓
  - operative `node --test` → brief `:34`/`:299` + p2 `:140`, all inside the R2-H-3 removal ruling. ✓

**Hygiene: PASS.**

---

## Note-only observations (not findings)

1. **F-8's `gh api → owner.type: "User"` is owner-attested, not round-0-verified** — round 0 makes no network calls; the gate-r5 register independently confirmed `owner.type: User`, `private: true`.
2. **`TreasuryReceiptBridge` lives under `Application/Projections/`**, not `Application/Services/`; the brief cites only line numbers for it (all verify).
3. **Arabic-authored entries interleave the cited `i18n.ts` alias ranges** — the claim about what those ranges demonstrate is correct.
4. **`wave3-3c-3d.progress.yaml` M3 remains `status: pending`** — consistent with treating ACCEPT as a dispatch-time P1-M0 precondition.
5. **`SystemAccountPurpose::expectedAccountType()` is at `:187`; the brief writes `:~186`** — within the marked tolerance.
6. **The r11 note-6 ambiguity is closed by the r12 schema ruling** and is carried here only to record that the note was dispositioned, not dropped.

---

## Evidence appendix (raw outputs)

```
$ git rev-parse HEAD
a5520f23ca39209f5b517723037e9516808f2bca      (unchanged; == brief base a5520f23c)

$ wc -l <targets>
453 CODEX-DISPATCH-enforcement-guards-2026-08-12.md   (r11: 451 → +2)
168 enforcement-p1.progress.yaml                      (r11: 167 → +1)
173 enforcement-p2.progress.yaml                      (r11: 172 → +1)
155 enforcement-p3.progress.yaml                      (r11: 153 → +2)
105 LEDGER.md                                          (r11: 105 → 0)

$ ls -lT
Aug 13 08:39:35 brief · Aug 13 08:39:59 p1,p2,p3 · Aug 13 08:31:53 LEDGER (predates the r12 window)

$ git status --porcelain apps/api apps/web .github scripts
?? scripts/dev-scan-stack.sh          (untracked, not a citation target)

--- brief section offsets (r11 → r12): uniform +2, no span changed ---
Executor 72→74 · SEQ 77→79 · EXEC 91→93 · §0 114→116 · §1 127→129 · §2 154→156
§3 247→249 · §4 344→346 · §5 401→403 · §6 427→429 · §7 440→442
§5→§6 span: 26 lines in BOTH revisions ⇒ the step-3 schema is an in-line insertion

--- YAML anchors (r12), pre-change-point unmoved / post-change-point shifted by the file delta ---
p1: base_sha 33 (=r11) · pre_promotion_ci_dispatch 89 (r11 88) · branch 94 · commit_series 98 ·
    ratchet_trust_model_ack 104 (r11 103) · milestones 133 · status 167
p2: base_sha 14 (=r11) · pre_promotion_ci_dispatch 76 · branch 83 · commit_series 86 ·
    ratchet_trust_model_ack 92 (r11 91) · milestones 130 · status 172
p3: base_sha 62 (=r11) · pre_promotion_ci_dispatch 88 (r11 86) · branch 91 · commit_series 94 ·
    milestones 120 (r11 118) · status 154

--- item 1: the three replaced closing-set blocks ---
p1:77-80  "diff ⊆ P1's OWN closing set (narrowed gate-r5 R5-H-3):
           docs/handoff/progress/enforcement-p1.progress.yaml · docs/handoff/LEDGER.md ·
           docs/handoff/reviews/enforcement-p1/** · docs/handoff/HANDBACK-enforcement-p1-*.md — ANOTHER
           package's evidence or any production/workflow path = closing FAIL (admin-only-delta, a5520f23c)."
p2:63-67  same shape, enforcement-p2 throughout
p3:75-78  same shape, enforcement-p3 throughout

--- wildcard sweep (the r11 R0-1 target) ---
grep -n 'progress/\*\.progress\.yaml|enforcement-p{1,2,3}/\*\*|HANDBACK-enforcement-\*\.md'
  brief:44  (r7 log) · brief:53  (r9 log)
  ZERO hits in any YAML · ZERO in operative brief/LEDGER text

--- item 2: §5 step 3 annotation schema (brief:420) ---
"annotation recording {A, sha256(final register(s)), sha256(handback)} (gate-r5 R5-C-1), in the EXACT
 machine-parseable schema (r12 ruling — P3-M0 must parse it): line 1 `accepted_sha: <A>`;
 line 2 `register_sha256: <path>=<hex>` (one line per register file);
 last line `handback_sha256: <path>=<hex>`"

--- annotation payload consistency (13 locations, all same 3-item payload) ---
brief:64,87,399,418,420,438 · p1:56,84 · p2:44,45,71 · p3:32,45,82,122 · LEDGER:56
⇒ accepted SHA + per-register digest(s) + handback digest everywhere; schema is a serialization,
  not a different payload

--- js-yaml parse ---
p1 PARSE OK · [M0,M1,M2,M3] · owner_gate NONE (0) · pins all present+null · p2_m2=false
p2 PARSE OK · [M0,M1,M2,M3,M4] · owner_gate NONE (0) · pins all present+null · p2_m2=false
p3 PARSE OK · [M0,M1,M2,M3] · owner_gate NONE (0) · pins all present+null · p2_m2=false
line 5 (all three): "# (r12 … round-0 R0-3/R0-4 + r7-R0-1 + r9-R0-1 + r11-R0-1, the 11 gate-r2 findings,
   the 6 gate-r3 findings, the 7 gate-r4 findings, and the 7 gate-r5 findings; re-gate before dispatch)."

--- gate-register counts (re-derived from the registers) ---
gate-r1 = 17 · gate-r2 = 11 · gate-r3 = 6 · gate-r4 = 7 · gate-r5 = 7

--- table cell counts (awk -F'|', cells = NF-2) ---
:83-87 → 4×5 · :118-125 → 3×8 · :143-150 → 2×8 · :166-171 → 3×6      all consistent

--- stale sweeps ---
wildcard allowlist   → brief:44, :53 (historical)                                  CLEAN
tag deletion/retention → brief:56 (r9 log), :65 (r11 log revocation)               CLEAN
record*+BEFORE-merge → brief:31, :50 (historical)                                  CLEAN
p2_m2_landed_sha     → brief:37,46,87 + p3:17,122 (historical/supersession)        CLEAN
node --test          → brief:34,299 + p2:140 (removal ruling)                      CLEAN

--- repo citations re-derived ---
ci.yml:3-8 · ci.yml:1104 · StockAdjustmentService.php:1715/:1716/:1719
GeneralLedgerService.php:3480/:3507 · adversarial-review.sh:47/:93/:96/:97
grep -L vitest tools/__tests__/* → 0 · test:tools → 0 · failOnEmptyTestSuite → 0
ParserFactory 4/16 · SystemAccountPurpose cases 41 · ci.yml:629 → 93 · ci.yml:726 → 16
grep -rn 'test:eslint-rules|tools/__tests__' .github/workflows/ → exit 1

--- check 5 ---
grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule"
  over brief + all three YAMLs → (no output) exit 1
```

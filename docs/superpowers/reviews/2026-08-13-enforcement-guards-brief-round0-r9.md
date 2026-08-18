# Round 0 report — docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md @ r9 (working tree, HEAD a5520f23c)
Runner: opus subagent (round-0 mechanical) · Date: 2026-08-13

Scope: the brief at r9 + `docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml` + `docs/handoff/LEDGER.md` rows S-14/S-15, all UNCOMMITTED on `dev`. `git rev-parse HEAD` = `a5520f23ca39209f5b517723037e9516808f2bca` — unchanged from the r7/r8 runs and identical to the brief's stated verification base `a5520f23c`.

Run in FULL from the top per the spec's hard rule. Repo-side evidence was reused only after re-proving the source files unmodified, and every load-bearing citation was re-derived this pass. All doc-side line numbers re-derived at r9 offsets; nothing copied from the r7/r8 reports or from any gate register.

| # | Check                         | Result | Findings |
|---|-------------------------------|--------|----------|
| 1 | Revision-log truth (r9: 7 items; r8/r7 + r3/r4/r5/r6 spot-checks) | **FAIL** | 1 |
| 2 | Exhaustive claims proven      | **PASS** | 0 |
| 3 | Test contracts executable     | **PASS** | 0 |
| 4 | Behavior claims cited         | **PASS** | 0 |
| 5 | Permission keys verified      | **PASS (N/A)** | 0 — no permission/module keys named |
| H | Hygiene (pipes, banner, header counts, stale-text sweep, YAML shape) | **PASS** | 0 |

**VERDICT: FAIL — one FAIL row (Check 1). r9 does NOT pass round 0; do not dispatch gate round 5 until R0-1 is fixed and round 0 is re-run from the top.**

Six of the seven r9 log claims are fully and symmetrically applied. The seventh (R4-H-4) is applied to every location except one: **P1's whole-package milestone title (P1-M3)** — the exact block gate-r4 R4-H-4 named as defective — while **P2's whole-package milestone (P2-M4) did receive it**. This is the same block-by-block application pattern that produced r7's R0-1.

---

## Findings

- **[R0-1] Check 1 · HIGH — R4-H-4's "whole-package rerun" repair is missing from P1-M3, though present in P2-M4.**

  The r9 log (`:58`) claims: *"every normal green invocation **and whole-package rerun** is preceded by an explicit **local authority setup**."* The gate-r4 R4-H-4 evidence named P1's whole-package milestone explicitly: *"P1 M3 likewise reruns the full accumulated evidence without specifying the local authority setup (`enforcement-p1.progress.yaml:126-133`)"*, and its required repair reads *"add an explicit local/review setup before every normal green invocation **and whole-package gate**."*

  Mechanical result — a `LOCAL AUTHORITY SETUP|authority setup|local authority` grep across the whole document set returns **7 hits, and P1-M3 is not one of them**:
  ```
  brief :58   (r9 revision-log entry — the claim itself)
  brief :196  P1 acceptance block — LOCAL AUTHORITY SETUP preamble        ✓
  brief :292  P2 acceptance block — LOCAL AUTHORITY SETUP preamble        ✓
  brief :425  §6 F-8 — "executor green runs use the LOCAL AUTHORITY SETUP export"
  p1 YAML :132  M2 title — "LOCAL green runs use the authority setup export …"   ✓
  p2 YAML :121  M1 title — "…LOCAL AUTHORITY SETUP…"                       ✓
  p2 YAML :145  M4 title (WHOLE-PACKAGE GATE) — "each preceded by the LOCAL
                AUTHORITY SETUP export (gate-r4 R4-H-4)"                   ✓
  ─────────────────────────────────────────────────────────────────────────
  p1 YAML :140  M3 title (WHOLE-PACKAGE GATE) — ABSENT                     ✗
  ```
  P1-M3's title runs *"…+ WHOLE-PACKAGE GATE: rerun the full accumulated evidence with EVERY lens this package used over the integrated branch (harness final-milestone obligation)…"* with no authority-setup clause, while its sibling P2-M4 states it explicitly. The asymmetry proves an oversight rather than a design choice: P2 restated the requirement in **both** its affected milestone titles (M1 and M4); P1 restated it in **only one** (M2, not M3).

  **Why it matters mechanically, not just cosmetically.** The brief's own execution-mode block designates the YAML as *"your operative milestone list … Read yours first; update after every milestone … It is the resume point if you crash."* Under the designed bootstrap the variable does not exist before first promotion (`brief:172`, `:425`), so an executor who runs P1-M3's whole-package rerun from the milestone title alone hits the fail-closed checker and cannot reach ACCEPT — precisely the R4-H-4 failure scenario.

  **Partial mitigation, recorded honestly:** the brief's P1 acceptance block *does* cover it — `:196-198` reads *"Before EVERY normal green invocation **and the M3 whole-package rerun**"* — so an executor reading §2 would perform the setup. The defect is the missing restatement at the operative milestone, and the P2-M4 precedent is the standard r9 itself set.

  **Repair:** add the LOCAL AUTHORITY SETUP clause to `enforcement-p1.progress.yaml` M3's title, mirroring P2-M4's wording (`"…each preceded by the LOCAL AUTHORITY SETUP export (gate-r4 R4-H-4)…"`). Then bump the revision log honestly and re-run round 0 from the top.

No other finding. Everything else below verified clean.

---

## Check 1 — Revision-log truth

### The seven r9 claims, each verified against operative text (brief + YAMLs + LEDGER), not the log

| Claim | Required change | Re-derived verification | OK |
|---|---|---|---|
| **R4-C-1** — admin-commit allowlist extended; all closing evidence lands together | allowlist = progress YAMLs · LEDGER · `docs/handoff/reviews/enforcement-p{1,2,3}/**` · `docs/handoff/HANDBACK-enforcement-*.md`; one admin commit carries final register/handback/final YAML status/receipts/announcement-ack; headers quote A; in §5 item 4 step 5, all three YAML pre-promotion comments, LEDGER S-14 | **§5 item 4 step 5 (`:409`)** — verbatim: *"ONE **POST-PROMOTION ADMIN COMMIT** on dev lands ALL closing evidence together (gate-r4 R4-C-1): the final review register(s) and handback, the progress-YAML final milestone status/commit/verdict + top-level `status: complete`, the receipts … and the LEDGER row update. Its diff must touch ONLY the **admin allowlist**: `docs/handoff/progress/*.progress.yaml` · `docs/handoff/LEDGER.md` · `docs/handoff/reviews/enforcement-p{1,2,3}/**` · `docs/handoff/HANDBACK-enforcement-*.md` — NO production or workflow path (machine check: diff ⊆ allowlist). Every register/handback header quotes A."* Also **step 1 (`:405`)** now states the register "exists only in the working tree at this point — the bridge writes it after A; it is NOT committed into A." **P1 YAML `:71-74`**, **P2 YAML `:57-61`**, **P3 YAML** pre-promotion comment — all three carry the identical four-path allowlist + "diff ⊆ the admin allowlist" + "NO production/workflow path". **LEDGER S-14** carries `docs/handoff/reviews/enforcement-p{1,2,3}/**` and `HANDBACK-enforcement-*.md` (grep-extracted). **Basis re-verified in code:** `scripts/adversarial-review.sh` creates the out-dir at `:47`, copies the reviewer output to `$OUT` at `:93`, then parses `VERDICT:` at `:96-98` — the register genuinely is written after the reviewed commit, which is what makes the extension necessary. | ✓ |
| **R4-C-2** — step-0 freshness, serialization, re-gate protocol | in §5 item 4 + YAML comments + LEDGER S-14 | **§5 item 4 preamble (`:403`)** — *"**Promotion critical sections are SERIALIZED (gate-r4 R4-C-2): one package completes steps 0–5 before another package's step 0 begins — even when the packages implemented in parallel.**"* **Step 0 (`:404`)** — *"`git merge-base --is-ancestor <current-local-dev-tip> A` must hold — only a FAST-FORWARD to A is legal. If it fails, do NOT merge: go to the re-gate protocol below."* **Step 4 (`:408`)** — "(a fast-forward, per step 0)". **Re-gate protocol (`:410`)** — *"the ONLY route back"*: in-progress final milestone → rebase onto current dev tip → rerun invalidated local evidence + whole-package bridge gate → new A′ → re-send P2 announcement for A′ → re-set variable if the seed blob changed → fresh dispatch → restart from step 0; *"Never merge a stale A; never force-move dev; never rebase without re-gating."* **P1 YAML `:67-70`** and **P2 YAML `:54-56`** carry step-0/serialization/re-gate in their pre-promotion comments. **LEDGER S-14** carries *"freshness, serialization, durable refs, and a single closing admin commit"*, *"SERIALIZED: one package completes steps 0–5 before another begins"*, and *"(0) freshness assert `git merge-base --is-ancestor <current-dev-tip> A` — only a FAST-FORWARD to A is legal"*. | ✓ |
| **R4-H-1** — admin-only overclaims removed; honest trust model in F-8 | remove "settable only by a repo admin"-class claims; F-8 records personal-repo fact (`owner.type "User"`) + owner's dispatch-time assertion | **§6 F-8 (`:425`)** — *"**The honest trust boundary (gate-r4 R4-H-1 — "repo admin only" was overclaimed):** the anchor is sound against the EXECUTOR, which provably cannot write variables (no push, no credentials, desktop session only); it is NOT proven sound against every principal with repository collaborator/write access plus a qualifying token. Verified at r9: this is a PERSONAL private repo (`gh api → owner.type: "User"`), where the UI variable path is owner-only — the org-repo write-access rule does not directly apply — but the REST/classic-PAT surface is not proven closed. Therefore, at each package dispatch the OWNER (i) enumerates the principals with write access … and (ii) asserts on the record that none of them authors these candidates … Residual accepted on the record; upgrade path … = a protected environment / org-level authority."* **Phase 2 (`:171`)** now closes with the narrow claim only: *"The executor provably cannot write repository variables (no push, no credentials) — the ceiling lives outside the candidate; the honest trust boundary is stated in §6 F-8."* **Full-doc-set overclaim sweep** (`settable only|only by a repo admin|repo admin|admin.only|admin-authenticated|owner-authenticated|owner.only|no admin`) → 9 hits, each read in context: brief `:43`/`:44` (r7 log, historical), `:55` (r9 log quoting the removed claim), `:409` + p1 `:74` + p2 `:25` (all "admin-only-delta" — the house *commit-shape* pattern name, unrelated to variable authority), `:425` (F-8, honest and qualified), `:172` and p1 `:39-40` (see notes 1–2 — procedural descriptors, not exclusivity claims). **No surviving "settable only by a repo admin"-class claim.** | ✓ |
| **R4-H-2** — durable pin tags, explicit fetch, deletion ordering, retention | tags `ci-pin/enforcement-<pkg>-r<n>`; new mirror fields `dpa_/i18n_baseline_pin_tag`; CI fetches the tag; throwaway-ref deletion + retention rule; in §2 3(c) Phase-2/Bootstrap, §3 2(c), §5 step 3, F-8, both YAML baseline blocks, LEDGER S-14 | **§2 3(c) Phase 2 (`:171`)** — *"**Blob availability is a durable-ref contract (gate-r4 R4-H-2), not a hope:** the protected commit is reachable via the owner-pushed pin tag `ci-pin/enforcement-<pkg>-r<n>` … the CI job runs `git fetch origin tag <pin-tag>` explicitly, then verifies the variable's blob hash resolves."* **Bootstrap (`:172`)** — owner "pushes the durable pin tag reaching the protected commit"; re-pin = "variable + a new pin tag together". **§3 2(c) (`:273`)** — *"blob reachability is guaranteed by the owner's durable pin tag fetched explicitly (`git fetch origin tag ci-pin/enforcement-p2-r<n>` — gate-r4 R4-H-2)"*. **§5 step 3 (`:407`)** — pushes to throwaway ref *"**AND to a durable pin tag `ci-pin/enforcement-<pkg>-r<n>`**"*; *"The throwaway ref may be deleted only AFTER the pin tag exists; the pin tag is retained until a permanent remote branch (`origin/dev` or `origin/main`) contains the protected commit."* **F-8 (`:425`)** repeats fetch + retention + deletion ordering. **New YAML fields**: `dpa_baseline_pin_tag` (P1 `:51-53`, `:61`) and `i18n_baseline_pin_tag` (P2 `:40-41`, `:48`), both documented and null — confirmed present as real keys by the js-yaml parse. **LEDGER S-14** carries `ci-pin/enforcement-<pkg>-r<n>`. | ✓ |
| **R4-H-3** — seed-changing fix rounds recreate the two-step topology | seed-revision commit + distinct metadata commit, both re-reviewed; single self-identifying fix commit forbidden; in §2 3(c) Phase 1, §3 2(c), both YAML pin blocks + M2/M1 titles | **§2 3(c) Phase 1 (`:170`)** — *"A fix round that regenerates the seed recreates the SAME two-step topology (gate-r4 R4-H-3 — one commit cannot truthfully contain its own SHA): a seed-revision commit with the regenerated baseline, then a DISTINCT metadata commit updating `dpa_baseline_seed_commit` + `dpa_baseline_protected_blob`, then BOTH are re-reviewed; a single self-identifying fix commit is forbidden."* **§3 2(c) (`:273`)** — *"every seed-changing FIX ROUND recreates the same two-step topology, never a single self-identifying fix commit (gate-r4 R4-H-3)"*. **P1 YAML pin block `:45-48`** and **P2 YAML pin block `:33-37`** — both carry the identical rule with the "forbidden" clause. **P1-M2 title (`:132`)** — *"a seed-changing fix round RECREATES the same two-step topology (seed-revision commit, then a distinct metadata commit, then BOTH re-reviewed — gate-r4 R4-H-3; a single self-identifying fix commit is forbidden)"*. **P2-M1 title (`:121`)** — *"two-step topology then re-reviews both — gate-r4 R4-H-3, single self-identifying fix commit forbidden"*. **Stale-phrase sweep** `"in the fix commit"` → **one hit, brief `:48`, inside the r7 revision-log entry** — historical text the r9 entry supersedes, exactly as flagged in the task brief; **zero operative survivors.** | ✓ |
| **R4-H-4** — LOCAL AUTHORITY SETUP + `${{ vars.* }}` mapping | preamble in the P1 acceptance block, the P2 acceptance block, **both whole-package milestone titles**; vars mapping in §2 3(c) Phase 2 and §3 2(c) | **P1 acceptance block (`:196-203`)** ✓ — full preamble: derive `seed_blob=$(git rev-parse <dpa_baseline_seed_commit>:<baseline-path>)`, `grep -q "$seed_blob" …enforcement-p1.progress.yaml` (assert == mirror), `export DPA_BASELINE_PROTECTED_BLOB="$seed_blob"`, applied *"Before EVERY normal green invocation and the M3 whole-package rerun"*; isolated negative `(env -u DPA_BASELINE_PROTECTED_BLOB ./vendor/bin/phpunit …; test $? -ne 0)`. **P2 acceptance block (`:292-297`)** ✓ — same shape with `I18N_…` and `(env -u I18N_BASELINE_PROTECTED_BLOB node tools/audit-i18n-completeness.mjs; test $? -ne 0)`. **`${{ vars.* }}` mapping**: §2 3(c) Phase 2 (`:171`) *"The CI step maps `${{ vars.DPA_BASELINE_PROTECTED_BLOB }}` into the checker's environment on every workflow event"* ✓; §3 2(c) (`:273`) *"mapped into the checker environment via `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}` on every workflow event"* ✓. **Whole-package milestone titles: P2-M4 (`p2:145`) ✓ — "each preceded by the LOCAL AUTHORITY SETUP export (gate-r4 R4-H-4)". P1-M3 (`p1:140`) ✗ — ABSENT.** | **✗ — R0-1** |
| **R4-H-5** — P3's P1 gate hardened symmetrically | P1 top-level complete + M3 passed/ACCEPT/commit + `M3.commit → p1_landed_sha → base_sha`; in the sequencing-table P3 row, §4 milestones line, P3 YAML header check 1 + M0 title + owner_gates | **§4 milestones line (`:386`)** — *"`p3-M0` = machine-checked preconditions (the WHOLE P1 package landed per gate-r4 R4-H-5: top-level complete + M3 ACCEPT + `M3.commit → p1_landed_sha → base_sha` …)"*. **Sequencing-table P3 row (`:74`)** carries the same hardened P1 predicate. **P3 YAML header check 1 (`:20-28`)** — *"hardened gate-r4 R4-H-5 — an ancestor + a candidate-editable status line alone is the partial-snapshot forgery class"*, then 1a ancestor, 1b top-level `status: complete` + M3 `status: passed` + parseable ACCEPT at its `verdict:` path *"(landed via the gate-r4 R4-C-1 extended post-promotion admin commit)"* + recorded commit, 1c `M3.commit -> p1_landed_sha -> base_sha` each hop. **P3-M0 title (`:102`)** item (1) — identical, closing *"an ancestor + top-level status ALONE IS INSUFFICIENT"*. **P3 owner_gates `base-pin-p1-ancestry-and-manifest` (`:80-82`)** — *"AND the gate-r4 R4-H-5 whole-package proof for P1: top-level complete + M3 passed/ACCEPT/commit + M3.commit -> p1_landed_sha -> base_sha ancestry"*. Note the R4-C-1 ↔ R4-H-5 conjunction is explicitly closed: the P1 M3 ACCEPT artifact P3 must read is exactly what the extended admin commit lands. | ✓ |

### Prior-revision spot-checks (all re-derived at r9 offsets)

- **r8 / R0-1 (receipt timing)** — the dangerous conjunction sweep `(record(ed|s)?)[^.]{0,120}(BEFORE|before) (merge|merging|the parent merges)` over brief + 3 YAMLs + LEDGER returns **2 hits, brief `:31` and `:50`, both historical revision-log entries**. The P1 (`:224-228`) and P2 (`:318-322`) acceptance blocks both still read "verifies the run … BEFORE merging, then records it … in the POST-promotion admin commit". No regression. ✓
- **r7 / R3-C-1, R3-C-2, R3-H-1..H-4** — re-verified at new offsets: NON-AUTHORITATIVE MIRROR framing (brief `:170`, p1 `:37`, p2 `:28`); exact-A promotion (`:403-411`); `M3.commit == dpa_3c_reviewed_sha` exact equality (brief `:72` sequencing row, p1 `:18-24`, p1-M0 `:116`); `p2_landed_sha` whole-package proof (p3 `:29-36`, `:55`, `:102`); commit-1/commit-2/then-review (brief `:170`, `:273`, p1 `:42-48`/`:132`, p2 `:33-37`/`:121`). ✓
- **r3/r4/r5/r6** — the frontend-lint discrete-step census, the r5 line-number correction, the Vitest tools contract, the event graph, and the census-derived P3 acceptance rule all re-grounded against unmodified sources (Check 2/4). ✓

---

## Check 2 — Exhaustive claims / censuses (all re-run this pass)

`git status --porcelain apps/api apps/web .github scripts` shows **no tracked modification** (single untracked `scripts/dev-scan-stack.sh`, not a citation target), at an unchanged HEAD — so every census target is byte-identical to the r7/r8 runs. Re-run regardless:

| Inventory claim | Census re-run | Documented | Re-derived | Match |
|---|---|---|---|---|
| pgsql allowlist "93 class names" (`ci.yml:629`) | `sed -n '629p' \| tr '\|' '\n' \| grep -c Test` | 93 | **93** | ✓ |
| second allowlist "16-entry" (`:726`) | same | 16 | **16** | ✓ |
| Architecture ParserFactory "4 of 16"; zero `RefreshDatabase` | `ls`/`grep -l` | 4 / 16; 0 | **4 / 16; 0** | ✓ |
| "complete 41-case partition (27+1+4+9)" | `grep -cE '^    case '` | 41 | **41**; 27+1+4+9 = 41 | ✓ |
| "every current `tools/__tests__/*.mjs` imports from `vitest`" | `grep -L vitest` | all | **empty (exit 1) → 6/6** | ✓ |
| `test:tools` absent (a deliverable, not a presence claim) | `grep -c` | absent | **0** | ✓ |
| no `failOnEmptyTestSuite` (grounds the nonzero-selection rule) | `grep -c` | absent | **0** | ✓ |
| `test:eslint-rules` + `tools/__tests__` in NO workflow | `grep -rn … .github/workflows/` | exit 1 | **exit 1** | ✓ |
| `frontend-lint` ∈ `all-checks-pass` `needs` (`:1104`) | `sed -n '1104p'` | present | **present** | ✓ |
| DPA audit "10 violations + 3 GL-gap grays"; eslint-rules 3 tested/3 untested; manifest-drift zero workflow refs; STATUS_RE has no `Tone`; `KeyedByRouteId` absent from `WRAPPERS`; Architecture suite in no automatic lane | (r7/r8 runs; sources provably unmodified) | as stated | as stated | ✓ |

No new inventory claim was introduced by r9 (the additions are protocol text, not censuses). **PASS.**

---

## Check 3 — Test contracts executable

r9 **improved** this check: the LOCAL AUTHORITY SETUP preambles fix the previously-latent problem that the acceptance commands could not go green pre-bootstrap.

| Contract | Command present | Physically executable | Mock-of-subject |
|---|---|---|---|
| P1 local authority setup (`:196-203`) | ✓ `git rev-parse <c>:<path>`, `grep -q`, `export`, `(env -u NAME cmd; test $? -ne 0)` | ✓ all valid POSIX/git; the subshell genuinely preserves the outer export, so the negative case cannot poison the following green runs | n/a |
| P1 guard green (`:204`) | ✓ | ✓ now reachable (authority exported first) | none |
| P1 tamper 1–2 (`:206-207`), ratchet 3–4 (`:209-210`), anti-growth 5 (`:211-216`) | ✓ | ✓ fixture plant/revert against the real scanner; mirror-drift and unset-variable sub-cases both locally reproducible | none |
| P1 scope + allowlist (`:217-221`), aggregate membership (`:223`) | ✓ | ✓ | n/a |
| P2 local authority setup (`:292-297`) | ✓ same shape, `../../docs/handoff/progress/enforcement-p2.progress.yaml` from `apps/web` | ✓ relative path resolves | n/a |
| P2 acceptance (`:298-308`) | ✓ `pnpm lint`, `node tools/audit-i18n-completeness.mjs`, `pnpm test:tools`, `pnpm test:eslint-rules`, two `grep -n … ../../.github/workflows/ci.yml` | ✓ | none |
| P3 acceptance (`:364-383`) | ✓ census-derived `phpunit <path> --filter '^…$'` + parsed nonzero count; `./vendor/bin/phpstan` | ✓ nonzero-count rule grounded by the re-verified absence of `failOnEmptyTestSuite` | none |
| Promotion steps 0–5 (`:404-409`) | ✓ `git merge-base --is-ancestor <dev-tip> A`, `git fetch origin tag <pin-tag>`, `git cat-file blob` | ✓ valid git; the "diff ⊆ allowlist" check is mechanically expressible from `git diff --name-only` | n/a |
| M0 precondition commands (3 YAMLs) | ✓ `git rev-parse --verify`, `merge-base --is-ancestor`, `git show <sha>:… \| grep`, `git cat-file -e` | ✓ seam-grep target still exists at `StockAdjustmentService.php:1719` (re-derived) | n/a |

No impossible interleavings, no barriers on lazily-created rows, no contract asserting through a mock of its own subject. **PASS.**

---

## Check 4 — Behavior/repo claims cite source

**New r9 claim requiring a citation, re-derived in code:** the R4-C-1 rationale that the bridge writes the register *after* the reviewed commit —
```
scripts/adversarial-review.sh:47   mkdir -p "$(dirname "$OUT")"
scripts/adversarial-review.sh:93   cp "$TMP" "$OUT"
scripts/adversarial-review.sh:96   VERDICT_LINE="$(grep -E '^VERDICT:' "$OUT" | tail -1 || true)"
scripts/adversarial-review.sh:98   *ACCEPT*) echo "ACCEPT"; exit 0 ;;
```
The register file is produced by `cp` at `:93` — i.e. after the review runs against the already-committed tip, and the ACCEPT verdict is only parsed at `:96-98`. The brief's claim at `:53` and §5 step 1's parenthetical at `:405` are exact.

**F-8's `owner.type: "User"` fact (`:425`)** is a claim about the GitHub repo, not the local tree; it is labelled *"Verified at r9"* with the exact command shape (`gh api → owner.type`). Round 0 does not make network calls, so this is recorded as **owner-attested, not round-0-verified** — consistent with how the brief marks its other unverifiable items (F-5). It is stated conservatively (the very next clause concedes the REST/PAT surface is not proven closed), so it carries no load-bearing weight the mechanical check could falsify.

**Re-derived this pass:**
```
ci.yml:3-8   push.branches [main] · pull_request.branches [main, dev] · workflow_dispatch   (nothing else)
ci.yml:1104  needs: [… frontend-lint …]
StockAdjustmentService.php:1715/:1716/:1719  ?StockMovementReferenceType / ?string $referenceId /
                                             assertReferenceLinkagePaired(...)
GeneralLedgerService.php:3480/:3507          sealAndPersistEntry(...) / bccomp(...) !== 0
```

**Conjunction check (house law).** Three "A is wired into B" claims opened on both sides: (a) `frontend-lint` ↔ `all-checks-pass` `needs` — edge present at `:1104`; (b) `frontend-lint` ↔ the `package.json` lint chain — edge correctly *denied* (job runs discrete steps, `:885` says it supersedes `pnpm lint`); (c) **new for r9** — R4-C-1's extended allowlist ↔ R4-H-5's requirement that P3 read P1-M3's ACCEPT artifact at base: `docs/handoff/reviews/enforcement-p{1,2,3}/**` is in the allowlist (`:409`) and P3 YAML `:26` explicitly notes the artifact is *"landed via the gate-r4 R4-C-1 extended post-promotion admin commit"* — **the two repairs are genuinely linked, not merely co-present.**

The full previously-verified citation set (`ci.yml` :27/:105/:143-178/:180-185/:226-230/:275/:284-293/:294/:629/:726/:740-747/:833-851/:844-848/:853-891/:876/:879/:882/:885/:890/:893/:918-922/:977-981/:1012/:1090-1107 · `StockAdjustmentService` · `BatchStockService` · `GeneralLedgerService` · `TreasuryReceiptBridge` · `InstrumentLifecycleService` · `ChartOfAccountsService.php:51` · `phpunit.xml:17-18` · `package.json` :10/:12 · `i18n.ts` · `setup.ts:2` · `audit-quantity-display.mjs` · `audit-design-system.mjs:59-62` · `gen-route-manifest.mjs:31-34` · `preflight.sh:193-195` · `SELF-REVIEW-HARNESS.md` :49-50/:66-75 · `adversarial-review.sh:49-64` · sweep audit :136-139 · `wave3-3c-3d.progress.yaml:49-56` · ui-wave0 :240-247 and M4 :64-65 · country brief/YAML · openapi handover+plan · `PLAN-p0…:53` · `AGENTS.md:16` · six agent contracts) stands against unmodified sources. **PASS.**

---

## Check 5 — Permission keys

```
$ grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule" \
    docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md \
    docs/handoff/progress/enforcement-p{1,2,3}.progress.yaml
(no output)   exit 1
```
No permission or module keys named anywhere in the document set; the `canAccessModule` fail-open trap cannot apply. **N/A — PASS.**

---

## Hygiene

- **Banner ↔ latest revision-log entry.** Banner `:4` = *"**Revision:** r9 — **2026-08-13**, full gate-r4 fix round applied (…gate-r4.md: R4-C-1..2, R4-H-1..5 — itemized log below), **NOT yet re-gated**. Re-run round 0 from the top, then **gate round 5**, before dispatch."* Revision-block anchors re-derived: `:4` banner · `:5` (r1/r2/r3) · `:24` r4 · `:27` r5 · `:29` r6 · `:42` r7 · `:50` r8 · **`:52` r9** — r9 is genuinely last. Rev, date (2026-08-13) and next-gate number all consistent. ✓
- **YAML line-5 headers.** All three identical: *"# (r9 of the brief applies all 17 gate-r1 findings, round-0 R0-3/R0-4 + r7-R0-1, the 11 gate-r2 findings, the 6 gate-r3 findings, and the 7 gate-r4 findings; re-gate before dispatch)."* Counts re-derived **from the registers themselves**: gate-r1 → **17** (4C+11H+2M) · gate-r2 → **11** (2C+7H+2M) · gate-r3 → **6** (2C+4H+0M) · gate-r4 `grep -cE '^### R4-(C|H|M)-[0-9]+'` → **7**, IDs `R4-C-1 R4-C-2 R4-H-1 R4-H-2 R4-H-3 R4-H-4 R4-H-5` = **2C+5H+0M** (register states "Minor findings: None"). All four counts correct. ✓
- **Unescaped GFM pipes.** Four normative tables re-checked by `awk -F'|'` at r9 offsets — sequencing `:70-74` (header 4, all rows 4) · read-order `:105-112` (3) · DO-NOT-TOUCH `:130-137` (2) · write-surface contract `:153-158` (3). Every row matches its header. ✓
- **YAML validity / shape (js-yaml, run from `apps/web`).** All three parse. Each has exactly one `base_sha` and one `branch`, top-level `status: pending`. Milestones ordered and complete: **P1 `[M0,M1,M2,M3]`, P2 `[M0,M1,M2,M3,M4]`, P3 `[M0,M1,M2,M3]`** ✓. **Milestone-level `owner_gate:` fields = 0 / 0 / 0** ✓. r9 pin sets all present and null, none missing, none pre-filled — P1 adds **`dpa_baseline_pin_tag`**, P2 adds **`i18n_baseline_pin_tag`**, P3 retains `p1_landed_sha`/`country_defaults_landed_sha`/`p2_landed_sha` ✓. `p2_m2_landed_sha` is a key in none of the three ✓. ✓
- **P3 M0 numbering.** Re-read in full: items **(1) (2) (3) (4) (5)** — P1 whole-package · P2 whole-package · country-defaults whole-lane · `commit_series` non-null · fresh worktree/branch. **No duplicates, no gaps**; the r8 "(1)/(1b)" split is gone, absorbed into a clean (1)/(2) after R4-H-5 promoted P1 to a full peer check. ✓
- **Stale-phrase sweep (fresh, full document set).**
  - `"in the fix commit"` → **1 hit, brief `:48`** (r7 log entry, superseded by the r9 entry per the task brief). Zero operative survivors. ✓
  - receipt `record* … BEFORE merge` conjunction → **2 hits, brief `:31`, `:50`**, both historical log entries. ✓
  - admin-only variable claims → 9 hits, all dispositioned above under R4-H-1; none is a surviving exclusivity claim. ✓
  - `p2_m2_landed_sha` → 5 hits (brief `:37`, `:46`, `:74`; p3 `:17`, `:102`) — all historical log or explicit supersession; not a key anywhere. ✓
  - `node --test` → 3 hits (brief `:34`, `:286`; p2 `:121`), all inside the R2-H-3 removal ruling. `origin/` → 5 hits (`:173` negation sentence; `:360`, `:412` real `origin/dev`; `:407` the new pin-tag/`origin` push and retention rule — legitimate new operative use). ✓

**Hygiene: PASS.**

---

## Note-only observations (not findings)

1. **`brief:172` heading reads "Bootstrap + re-pin on promotion (OWNER-authenticated, …)".** Considered against R4-H-1: this is a procedural descriptor of who performs the write (and the bullet closes with the narrow, true set — *"Never an executor write, never a CI write, never a candidate diff"*), not a claim that only an owner *can* write the variable. Read with F-8 it is not a false statement. Recommend harmonizing the word "OWNER-authenticated" anyway when R0-1 is fixed, since a hostile reviewer may read it as residual overclaim.
2. **`p1 YAML:39-40` still says "the executor cannot write repo variables — no push, no admin"** where the r9 honest framing is "no push, no credentials", and **P2's equivalent (`p2:30-31`) was trimmed to just "the executor cannot write repo variables."** The P1 wording is still true (the executor does lack admin) and is a claim about the executor, not about exclusivity — so not an overclaim. Cosmetic asymmetry only; worth aligning alongside R0-1.
3. **`gh api → owner.type: "User"` (F-8) is owner-attested, not round-0-verified** — round 0 makes no network calls. Stated conservatively and immediately qualified, so it bears no load the mechanical check could falsify.
4. **`TreasuryReceiptBridge` lives under `Application/Projections/`**, not `Application/Services/`; the brief cites only line numbers for it (all verify).
5. **Arabic-authored entries interleave the cited `i18n.ts` alias ranges** (`menu: arMenu`, `'parts-catalog': arPartsCatalog`, `channels: arChannels`, `reports: arReports`) — the claim about what those ranges demonstrate is correct and the authored-provenance mechanism distinguishes them.
6. **`wave3-3c-3d.progress.yaml` M3 remains `status: pending` / `commit: null` / `verdict: null`** — consistent with the brief treating ACCEPT as a dispatch-time P1-M0 precondition.
7. **`SystemAccountPurpose::expectedAccountType()` is at `:187`; the brief writes `:~186`** — within the tilde's marked tolerance.

---

## Evidence appendix (raw outputs)

```
$ git rev-parse HEAD
a5520f23ca39209f5b517723037e9516808f2bca      (unchanged; == brief base a5520f23c)

$ wc -l <targets>
440 CODEX-DISPATCH-enforcement-guards-2026-08-12.md   (r8: 415 → +25)
149 enforcement-p1.progress.yaml                      (r8: 136 → +13)
154 enforcement-p2.progress.yaml                      (r8: 141 → +13)
135 enforcement-p3.progress.yaml                      (r8: 125 → +10)
105 LEDGER.md

$ ls -lT   (LEDGER touched this round — expected, S-14 rewritten by R4-C-1/C-2)
Aug 13 07:43:07  brief · Aug 13 07:42:15 LEDGER · Aug 13 07:42:18 p1,p2 · Aug 13 07:42:55 p3

$ git status --porcelain apps/api apps/web .github scripts
?? scripts/dev-scan-stack.sh          (untracked, not a citation target)
  → NO tracked modification in apps/api, apps/web, .github, scripts

--- R0-1: LOCAL AUTHORITY SETUP coverage sweep ---
grep -n 'LOCAL AUTHORITY SETUP\|authority setup\|local authority' <brief + 3 YAMLs>
  brief:58   (r9 log — the claim)
  brief:196  P1 acceptance block preamble                        ✓
  brief:292  P2 acceptance block preamble                        ✓
  brief:425  F-8 cross-reference
  p1:132     M2 title — "LOCAL green runs use the authority setup export"   ✓
  p2:121     M1 title                                            ✓
  p2:145     M4 title (WHOLE-PACKAGE GATE) — "each preceded by the
             LOCAL AUTHORITY SETUP export (gate-r4 R4-H-4)"      ✓
  p1:140     M3 title (WHOLE-PACKAGE GATE)                       ✗ ABSENT  ← R0-1

--- §5 item 4 promotion sequence (r9) ---
403  preamble: NOTHING committed between ACCEPT and merge; critical sections SERIALIZED
404  0. Freshness assert: git merge-base --is-ancestor <current-local-dev-tip> A (FF only)
405  1. Final gate ACCEPTs A (register exists only in the working tree; NOT committed into A)
406  2. Owner sets/updates the variable(s); for P2 the parent SENDS the final announcement
407  3. Owner pushes exactly A to a throwaway ref AND to durable pin tag ci-pin/enforcement-<pkg>-r<n>;
        workflow_dispatch; head==A, GREEN, new jobs/steps executed; throwaway deletable only AFTER
        the tag exists; tag retained until origin/dev|origin/main contains the protected commit
408  4. Parent merges exactly A (a fast-forward, per step 0)
409  5. ONE POST-PROMOTION ADMIN COMMIT lands register(s)+handback+final YAML status+receipts+LEDGER;
        diff ⊆ {progress/*.progress.yaml, LEDGER.md, reviews/enforcement-p{1,2,3}/**,
                HANDBACK-enforcement-*.md}; headers quote A; NO production/workflow path
410  Re-gate protocol (the ONLY route back): stale step 0 or RED dispatch → in-progress final
        milestone → rebase onto current dev tip → rerun invalidated evidence + whole-package
        bridge gate → new A' → re-send P2 announcement → re-set variable if seed blob changed →
        fresh dispatch → restart from step 0

--- adversarial-review.sh (R4-C-1 basis, re-derived) ---
:47 mkdir -p "$(dirname "$OUT")"   :93 cp "$TMP" "$OUT"   :96 grep -E '^VERDICT:' "$OUT"
  → the register is written AFTER the reviewed commit; extension of the allowlist is necessary

--- LEDGER S-14 (r9, grep-extracted) ---
"freshness, serialization, durable refs, and a single closing admin commit"
"SERIALIZED: one package completes steps 0–5 before another begins"
"(0) freshness assert `git merge-base --is-ancestor <current-dev-tip> A` — only a FAST-FORWARD is legal"
"ci-pin/enforcement-<pkg>-r<n>" · "docs/handoff/reviews/enforcement-p{1,2,3}/**" ·
"HANDBACK-enforcement-*.md" · "No green run on exactly the promoted SHA = PROMOTION BLOCKED"

--- js-yaml parse (from apps/web) ---
p1 PARSE OK · base_sha null · branch null · status pending · [M0,M1,M2,M3] match
   owner_gate fields: NONE (0) · pins all present+null incl. dpa_baseline_pin_tag
p2 PARSE OK · [M0,M1,M2,M3,M4] match · owner_gate fields: NONE (0)
   pins all present+null incl. i18n_baseline_pin_tag
p3 PARSE OK · [M0,M1,M2,M3] match · owner_gate fields: NONE (0)
   pins p1_landed_sha, country_defaults_landed_sha, p2_landed_sha, pre_promotion_ci_dispatch
p2_m2_landed_sha as a key: false (all three)
line 5 (all three): "# (r9 of the brief applies all 17 gate-r1 findings, round-0 R0-3/R0-4 +
   r7-R0-1, the 11 gate-r2 findings, the 6 gate-r3 findings, and the 7 gate-r4 findings; …)"

--- gate-register finding counts (re-derived from the registers) ---
gate-r1 → 17 (4C+11H+2M) · gate-r2 → 11 (2C+7H+2M) · gate-r3 → 6 (2C+4H+0M)
gate-r4 → 7  IDs: R4-C-1 R4-C-2 R4-H-1 R4-H-2 R4-H-3 R4-H-4 R4-H-5  = 2C+5H+0M

--- P3 M0 numbering ---
(1) P1 whole-package · (2) P2 whole-package · (3) country-defaults whole-lane ·
(4) commit_series non-null · (5) fresh worktree/branch     — no duplicates, no gaps

--- table cell counts (awk -F'|', cells = NF-2) ---
:70-74 → 4×5 (header 4) · :105-112 → 3×8 · :130-137 → 2×8 · :153-158 → 3×6   all consistent

--- stale-phrase sweeps ---
"in the fix commit"                      → brief:48 only (r7 log; superseded)          CLEAN
record*+BEFORE-merge conjunction         → brief:31, brief:50 (historical log)         CLEAN
admin-only variable claims               → 9 hits, all historical / "admin-only-delta"
                                            / F-8-honest / procedural (notes 1-2)      CLEAN
p2_m2_landed_sha                         → brief:37,46,74; p3:17,102 (supersession)    CLEAN
node --test                              → brief:34,286; p2:121 (removal ruling)       CLEAN
origin/                                  → :173 negation · :360,:412 origin/dev ·
                                            :407 new pin-tag push (legitimate)         CLEAN

--- repo citations re-derived this pass ---
ci.yml:3-8 push[main]/pull_request[main,dev]/workflow_dispatch · ci.yml:1104 needs incl. frontend-lint
StockAdjustmentService.php:1715/:1716/:1719 · GeneralLedgerService.php:3480/:3507
grep -L vitest tools/__tests__/* → empty (exit 1) · test:tools → 0 · failOnEmptyTestSuite → 0
ParserFactory 4/16 · RefreshDatabase 0 · SystemAccountPurpose cases 41
ci.yml:629 → 93 · ci.yml:726 → 16
grep -rn 'test:eslint-rules|tools/__tests__' .github/workflows/ → exit 1

--- check 5 ---
grep -nE "permission:|module:[A-Za-z]|moduleKey|hasModule\(|MODULE_PERMISSIONS|canAccessModule"
  over brief + all three YAMLs → (no output) exit 1
```

# FINAL-GATE REGISTER — package p1 M3 round 3 attempt 1
accepted_sha: 059f9a662922837bf7dda5feb893aae4a8dee454
base_sha: 41fb478c21ae010568036aa66ffaef726ec63f09   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ 059f9a662922837bf7dda5feb893aae4a8dee454 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r3.md=3b9722c6f711da4d5c5621e5d8b8ee2627a52855010614435b31907bf39c252c   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 5
---
# ADVERSARIAL FINAL-GATE REGISTER — enforcement p1 / M3 / round 3

**Snapshot:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.8LsBLXkbWG/snap`, detached at `059f9a662922837bf7dda5feb893aae4a8dee454`, `git status --porcelain` empty. Range `41fb478c21ae010568036aa66ffaef726ec63f09..059f9a662922837bf7dda5feb893aae4a8dee454`, 23 commits.
**Handback reviewed:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r3.md` — 919 lines, `sha256 = 3b9722c6f711da4d5c5621e5d8b8ee2627a52855010614435b31907bf39c252c`.

## 1. Handback table inspection (content-derived evidence)

Each required table was parsed out of the file, not read past.

| Table | Row count | First row key | Last row key |
|---|---|---|---|
| §3.5 mechanism × table fixture coverage (deliverable 6), line 116 | **10** | `create` | `raw_sql` |
| §4 baseline ↔ DPA-register cross-check (deliverable 4), line 312 | **14** | `**V1** — TestE2EGLPosting hard-deletes sealed GL` | `**S0 residue** — WeightedAverageCostService reference params still ?string` |
| §4 mapped / never-covered partition, line 337 | **3** (2 data + arithmetic row) | `mapped to a register item or S0 residue in the table above` | *(blank total row: `34 ✓`)* |
| §4 never-covered list, line 348 | **17** | `1` (`FixOrphanedProducts.php:128`) | `34` (`RepositoryTransferService.php:105`) |
| §5 violation census / M2 seed baseline, line 390 | **34** | `1` (`app/Console/Commands/FixOrphanedProducts.php:128`) | `34` (`app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105`) |
| §3.7 M1 gate history, line 181 | **5** | `1` (`M1-round1.md`) | `5` (`M1-round5.md`) |

Independently re-derived, not accepted:

- **§4 partition is exact.** mapped `{3,12,19,20–28,33}` = 13, never-covered = 21, intersection empty, union = exactly `1..34`. The 17-row table expands (`4–7`, `13/14`) to precisely the 21 claimed ids. The round‑1 [Important] finding is genuinely closed.
- **§5 census ↔ baseline is 1:1.** All 34 census rows map onto the 34 baseline keys on `(file, table, mechanism, function)` with zero residue in either direction.
- **§3.5 is accurate.** Reflectively parsed `fixtureMatrix()` → **115 cells**, 115 unique `(class, method, table, mechanism)`, distribution `violation 75 / linked 32 / not_applicable 8`. All 40 `(table, mechanism)` pairs present; every pair has a positive; all 28 rule-linkable pairs have a negative. The 12 **P-only** pairs in the table match `linkedFormExists()` exactly (raw_sql ×4, stock_movements update/save/delete/increment/decrement, journal_entries delete/increment/decrement). §3.1's corrected "8 files / 8 classes / 2 class-less" is correct.
- **§7.4's scope proof now reproduces byte-for-byte** at this tip: `15 files changed, 4786 insertions(+), 24 deletions(-)`. Round 2's finding is closed.

## 2. Lens application

**`stock-gl-interaction`** — the diff touches no production code (`apps/api/app/**`, `apps/web/**`, `apps/pos/**`, `apps/api/database/**` all clean), so both sides of the seam were traced through the guard's rules rather than through changed behaviour. Dim. 1 (document-per-action) is the package's subject and is enforced over all four seam tables. Dim. 9 (append-only) is encoded as a hard rule — `stock_movements` MUTATE/DELETE and `journal_entries` DELETE are unconditional violations — and it surfaced a live `journal_entries` row delete (`RepositoryTransferService.php:105`, census #34) the DPA register never covered; correctly baselined, not fixed, ticket requested. Dim. 2 (GL consequence exactly once) is out of static reach and is honestly recorded as such (G1/G2/G3: "a MISSING journal entry is the absence of a write"). Dim. 10 (float): no float cast, `floatval`, or `number_format` in any added line. `TABLE_MODELS` names exactly the four contract models. Test quality: the four gates are non-vacuous — neutering the scanner turns all 34 baseline entries stale (direction b) and breaks 115 pinned classifications.

**`inventory-costing`** — no WAC, opening-balance, FEFO, or decrement code is modified. The WAC cluster (#20–#28, nine sites in one file) is enumerated correctly and attributed to the mapped S0-residue side. No scale, `workingScale()`, or rounding surface is touched; no `getScale()` call is added. The pairing predicate's chokepoint binding (`StockAdjustmentService::recordMovement` by declared receiver type, not bare name) is real, and blind spot F correctly narrows a `linked` level write to "traceable to a movement", not "justified by a document".

Ratchet mechanics verified from source: authority is `getenv(DPA_BASELINE_PROTECTED_BLOB)`, never the YAML; mirror drift fails; unset/malformed/unfetchable all fail closed; `git cat-file blob` is content-addressed so tag reachability is the only thing the fetch buys. Baseline is 34 keys, sorted, unique, line-number-free, blob `1381983d463e6c546535be907d4aa1c7ca94c597` identical at seed `ff5642f87` and at HEAD. Mirror regex matches the unquoted YAML value. Scope allowlist, control-file preflight, and the no-`contents: write` check all pass.

## 3. Findings

### [CRITICAL] `.github/workflows/ci.yml:235` — the "Fetch the durable baseline pin tag" step is a bash syntax error; `backend-dpa-guard` fails before it ever runs the guard

The `tr` argument is mis-quoted:

```
... | tr -d '"'\'')"
```

That tokenizes as `'"'` (a `"`), `\'` (a `'`), then a **third, unterminated** `'` which swallows `)`, the closing `"`, and the entire remainder of the step script. Verified by extracting the exact `run:` string via a YAML load and executing it:

```
bash -n  → line 1: unexpected EOF while looking for matching `''
           line 8: syntax error: unexpected end of file
bash -e  → EXIT CODE: 2, no stdout
```

Steps 6 and 7 — the guard and the ratchet, i.e. the entire point of deliverable 5 — never execute. Because `backend-dpa-guard` carries no `if:` and is in `all-checks-pass` `needs` (`:1198`), the job is red on every event that starts the workflow, and the aggregate can never pass on PR→main, push→main, or `workflow_dispatch`. Concretely: **the owner's mandatory pre-promotion `workflow_dispatch` run on A is red, so brief §5 item 4 step 3 blocks promotion.** The package as handed over cannot be promoted.

The intended semantics are one character away and do work — `tr -d "\"'"` against the real YAML yields `PIN_TAG=[ci-pin/enforcement-p1-r1]`, matching the pre-allocated tag. Nothing else in the job is wrong: `checkout@v5`/`cache@v5`/`env.PHP_VERSION` match `backend-architecture`; `phpunit.xml` supplies `APP_KEY` and sqlite so no `.env` is needed; `set -o pipefail` is correctly explicit since the default shell is `bash -e {0}`; the `grep -qE 'OK \([1-9][0-9]* test'` nonzero-selection assertion is right and errs strict.

**Fix:** correct the quoting, then re-derive §7.3 Layer 1 and §7.4.

### [IMPORTANT] Layer-1/Layer-2 workflow evidence cannot detect the above and must be strengthened in the same round

§7.3 Layer 1 records a **YAML parse** ("YAML parse: OK", step names listed) — which validates document structure and never the embedded shell. `actionlint`, which shellchecks `run:` blocks and would have flagged this instantly, is honestly recorded as NOT INSTALLED, but no substitute was put in its place. Layer 2 then pasted `extracted PIN_TAG='ci-pin/enforcement-p1-r1' — extraction: MATCHES`, proving a *re-typed equivalent* of the extraction works while the literal bytes in `ci.yml` do not. That is precisely the "each part verified true, the conjunction never checked" failure the brief's own r4/R0-3 note names, now in its second venue. A fix that only repairs the quote reproduces the exposure on the next workflow edit.

**Fix:** add to Layer 1 a mechanical `bash -n` (or `bash -e` dry-run) over **every** `run:` script in the changed job, extracted from the YAML rather than retyped, with the output pasted; state it as the standing substitute for the absent `actionlint`.

### [MINOR] `docs/handoff/progress/enforcement-p1.progress.yaml` — M3's `commit:`/`updated:` are two commits behind the handover tip

`commit: 67ba450f2331c3ead0a065aaff02a65840c24a2c` and `updated: 67ba450f2` name the round‑1 *code* fix. Round 2 advanced HEAD twice (`fbdc4e992`, then `059f9a662`) without advancing either field, so the milestone record points at neither the tip being handed over nor the commit round 2 reviewed (`A' = fbdc4e992`, correctly named in `final_gate_round2`). The bridge does not field-check `commit`, and §1 of the handback carries the correct Final SHA, so impact is confined to record accuracy — but a milestone record that certifies an earlier tip than it ships is the same class as the round‑2 finding. A YAML commit cannot contain its own SHA; recording the last *code* commit (or naming the constraint inline) resolves it.

No further findings. Every other round‑1 and round‑2 disposition was verified as genuinely applied: dead variable removed and the docblock narrowed to the one direction it checks; §3.7e added so the round‑5 pointer resolves; §8.3's mitigation correctly restated (the by-path invocation plus the nonzero-selection assert under `pipefail` does close the file-deletion half — though it is moot while step 5 aborts the job first).

## 4. What to fix before merge

Repair the `tr -d` quoting at `ci.yml:235`, re-verify every `run:` block with `bash -n` on the bytes extracted from the YAML, re-derive §7.3 Layer 1 and §7.4 at the resulting tip, and advance M3's `commit:`/`updated:`. No re-seed, no re-pin, no baseline edit, and no change to the scanner, fixtures, or census is implied — those are sound.

VERDICT: CHANGES-REQUIRED

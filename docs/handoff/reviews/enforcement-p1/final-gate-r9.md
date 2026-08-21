# FINAL-GATE REGISTER — package p1 M3 round 9 attempt 1
accepted_sha: 2a9888db8967bd2ebda61e20addae30ee74716d9
base_sha: 67d18746a5e1c565c1f33c0394ae518af0e1cbf0   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ 2a9888db8967bd2ebda61e20addae30ee74716d9 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r9.md=9cc9d6017d365861e1395df51b804027dd066db85a093070ed73a19488dca84c   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 7d8fbb550fe3cc28b773b670f23cae2a389e11c4415767ca56d50cf7fb15ad50
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 6
---
I've completed the final-gate review. Here is my register.

---

# FINAL-GATE ADVERSARIAL REGISTER — enforcement package P1, milestone M3, round 9

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.NTA423Fp4f/snap`, detached at `2a9888db8967bd2ebda61e20addae30ee74716d9` (A).
**Range:** `67d18746a5e1c565c1f33c0394ae518af0e1cbf0..2a9888db8967bd2ebda61e20addae30ee74716d9` — 33 commits, 15 files, 4851(+)/26(−).
**Handback reviewed:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r9.md`
**Handback `sha256` (computed here, independently):** `9cc9d6017d365861e1395df51b804027dd066db85a093070ed73a19488dca84c`
**Lenses applied in full:** `stock-gl-interaction`, `inventory-costing` (both contracts read and worked as checklists).

---

## A. Census / classification tables — content-derived evidence of inspection

Every table required by brief §7 item 3 was opened and parsed. Row counts and first/last row keys, derived mechanically from the file bytes:

| handback table | rows | first row key | last row key |
|---|---|---|---|
| §2 M0 predicate table | **7** | `1` (rev-parse on base / 3C-merge / 3C-reviewed) | `7` (`commit_series`/`ratchet_trust_model_ack`/`control_manifest` non-null) |
| §3.1 What was built | **3** | `` `apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php` `` | `` `apps/api/tests/Architecture/DocumentPerActionFixtures/**` `` |
| §3.5 Mechanism × table fixture coverage | **10** | `create` | `raw_sql` |
| §3.5 per-gate-round origin table | **6** | `M1 initial matrix` | `**total**` (115) |
| §4 Baseline ↔ DPA-register cross-check | **14** | `**V1** — TestE2EGLPosting hard-deletes sealed GL` | `**S0 residue** — WeightedAverageCostService reference params still ?string` |
| §4 partition arithmetic | **3** | `mapped to a register item or S0 residue in the table above` | (total row, `34 ✓`) |
| §4 never-covered list | **17** | `1` (`FixOrphanedProducts.php:128`) | `34` (`RepositoryTransferService.php:105`) |
| §5 Violation census (seed baseline) | **34** | `1` — `app/Console/Commands/FixOrphanedProducts.php:128` · stock_levels · create · `FixOrphanedProducts::executeCommand` | `34` — `app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105` · journal_entries · delete · `RepositoryTransferService::transfer` |
| §7.1 rebase-window additions | **2** | `` `OrphanedEventRatchetTest` `` | `` `ProjectorEmissionRatchetTest` `` |
| §7.5 M2 round-2 notes absorbed | **5** | `1 — a baseline key is a SLOT, not a WRITE` | `5 — blind spot C lacked a pointer…` |

Checked-in baseline `apps/api/tests/Architecture/baselines/document-per-action-baseline.json`: **34 keys, 34 unique, sorted**.
First key: `app/Console/Commands/FixOrphanedProducts.php::App\Console\Commands\FixOrphanedProducts::executeCommand::stock_levels::create#1`
Last key: `app/Modules/Treasury/Application/Services/RepositoryTransferService.php::App\Modules\Treasury\Application\Services\RepositoryTransferService::transfer::journal_entries::delete#1`

### Independent verification performed (not accepted on assertion)

- **Scanner re-run at A by this reviewer** (PHP 8.4.15, PHP-Parser from an out-of-tree vendor, scanning the sealed snapshot's `apps/api/app`): **116 sites — 34 violation / 67 linked / 15 not_applicable.** Reproduces §5 and §8.8 exactly.
- **Violation key set from my run ⇄ checked-in baseline: 0 added, 0 removed**, both directions.
- **§5 ⇄ baseline reconciliation: 34/34, 0 in census not in baseline, 0 in baseline not in census.**
- **All 34 `file:line` anchors resolve at A and carry the claimed mechanism** (e.g. #11 `UninvoicedDeliveryNoteService.php:530` → `$entry = JournalEntry::create([`; #34 `RepositoryTransferService.php:105` → `$draft->delete();`). Round 6's stale-anchor class is closed — 0 problems.
- **`bash -n` over every `run:` block extracted from `ci.yml` at A: 67 blocks, 67 OK, 0 FAILED**; `backend-dpa-guard` 5/5. Round-8 finding (a) (`64` under "Result at this tip") is corrected — 67 is the true count and the handback now says 67.
- **Pin-tag extraction executed from the YAML's own bytes** → `PIN_TAG=[ci-pin/enforcement-p1-r1]`. Round 3's CRITICAL stays fixed.
- **Architecture class arithmetic re-derived at A:** 20 `tests/Architecture/*Test.php`, 2 this package's, 18 pre-existing, 4 known-red → **14** excluded-and-green. Round-8 finding (b) closed, and both rebase-window additions are named and dispositioned.
- **Scope:** 15 changed files, **0** path-allowlist violations, **0** control files touched, **0** `permissions:`/`contents: write` grants in the workflow diff.
- **§3.5 "115 pinned cells"** verified against `DocumentPerActionWriteGuardTest.php`: `grep -c` → 115, and 115 total `'mechanism' =>` entries (no cells outside the counted indent).
- Section ordering (round-8 MINOR) is fixed: §7.5 restored ahead of §7.6/§7.7; §3.8 and §8.8 are at `###`.

This is high-quality work. The scanner is genuinely well-built — `mayMatter()` gates only the AST **cache**, not the scan, so it introduces no false negatives; top-level statements, route closures and anonymous classes are all scanned; the soft-hold exemption is a strict subset test on a *resolved* payload; `updateOrInsert` was correctly reclassified as CREATE. The blind-spot list (A–H) is unusually honest.

---

## B. Findings

### [IMPORTANT] `apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php:361` (`'save' => ['save', 'MUTATE']`), with the rule at `:718`/`:800` — creating a journal entry via `save()` is classified `not_applicable`, so a new unlinked `journal_entries` INSERT passes the ratchet green. The hole is not in the blind-spot list, and the docblock's stated justification asserts the opposite.

**What's wrong.** `save`/`saveQuietly`/`saveMany`/`push`/`restore` are mapped unconditionally to `MUTATE` (`:361-365`). For `journal_entries`, `classifySite()` sends an unreadable-payload MUTATE that is not an erasure to the terminal `not_applicable` branch (`:800`), whose reason string is *"journal_entries lifecycle mutation … the row's justification was fixed at creation."* In Eloquent, `save()` on a model that has never been persisted is an **INSERT** — there is no prior creation at which a justification could have been fixed. The premise the exemption rests on is false for exactly that shape.

**Verified, reproducibly.** I ran the scanner at A against planted fixtures (production tree supplied as `contextRoots`, so resolution is identical to live code). Four idiomatic create-by-save shapes, all classified `not_applicable` and therefore never reported:

```
p5_new_then_save              journal_entries  save  not_applicable  journal_entries lifecycle mutation…
q1_property_assign_then_save  journal_entries  save  not_applicable  journal_entries lifecycle mutation…
q2_fill_then_save             journal_entries  save  not_applicable  journal_entries lifecycle mutation…
q3_make_then_save             journal_entries  save  not_applicable  journal_entries lifecycle mutation…
```
Controls in the same run behave correctly: `JournalEntry::create([...])` unlinked → `violation`; `DB::table('journal_entries')->insert([...])` → `violation`; `JournalEntry::query()->create([...])` → `violation`; the equivalent shapes on `stock_movements` and `stock_levels` → `violation` (their MUTATE rules are always-violation / pairing-required, so only `journal_entries` is exposed).

**Failure scenario.** A contributor lands, in any service or listener under `app/`:
```php
$entry = new JournalEntry;
$entry->company_id = $companyId;
$entry->entry_number = $n;
$entry->save();          // unlinked journal entry — no source_type, no source_id
```
The guard emits a site classified `not_applicable`; no key enters the violation set; growth, stale and anti-growth all pass; `backend-dpa-guard` is green; the entry merges. That is precisely the regrowth the package exists to prevent — the sweep plan's cementing guard is quoted in brief §1 as *"forbidding `JournalEntry::create` … outside document-keyed services"*, and this is the same act by a different verb.

**Why this is a finding rather than an accepted residue.** The package's residues are disclosed and argued (blind spots A, B, C, D, D2, E, F, G, H). This one is not mentioned in the scanner docblock, the ratchet docblock, handback §3.2, §3.3 or §8. Worse, the reason string and the rule text affirmatively state the case is safe. The project already applied exactly this reasoning once, at `:362-363`: *"`Query\Builder::updateOrInsert()` INSERTS when no row matches — it is a CREATE-class write, not a MUTATE (M1 gate round 3, finding 2)."* The same argument was not carried to `save()`.

The fixture matrix does not catch it because completeness is enforced per `(table, mechanism)`, not per semantics: `journal_entries × save` is pinned by `saveErasesLinkage` (positive) and `saveLifecycleOnly` (negative). `saveLifecycleOnly` loads its row with `JournalEntry::query()->findOrFail($entryId)` (`FixtureJournalEntryWrites.php:127`) — a correct example of the covered case — but that provenance carries no weight in the rule, so a fresh model yields the identical verdict. `every_mechanism_is_pinned_for_every_table()` therefore reports full coverage over a mechanism whose insert half is unguarded.

**Lens grounding.** `stock-gl-interaction` dimension 1 (document-per-action: every value-bearing write must carry a justifying document reference) and dimension 4 (no GL path may create rows outside the announced posting path); `inventory-costing` test-quality (a completeness gate that passes while the semantic case is unpinned).

**Suggested fix, and why it is cheap.** Either (a) classify `save`/`saveQuietly`/`push` on `journal_entries` as in-contract when the receiver is statically an unpersisted model (`new`/`make()` in the same scope) — mirroring the `updateOrInsert` precedent — or (b) if that is deliberately out of scope for a static scan, disclose it as a named blind spot and correct the `:800` reason string, which currently asserts a premise the rule does not establish. **Neither route disturbs the seed:** there are **zero** `journal_entries` `save` sites in `app/` today (my run: 57 `journal_entries` sites — 48 `create/linked`, 5 `update/not_applicable`, 3 `create/violation`, 1 `delete/violation`; no `save`), and no `new JournalEntry(...)` model instantiation exists in `app/`. So the violation key set does not move: **no re-seed, no re-pin, tag `ci-pin/enforcement-p1-r1` and blob `1381983d…` stand.**

### [MINOR] Handback §2 row 7 and `enforcement-p1.progress.yaml:195` (`m0_evidence.control_manifest_verified`) attest a manifest `sha256` equality that no longer holds at A.

Both records state the control manifest is present at base with `sha256 = 709b6fe9a8…`, **"matching the pin"**. I verified the bytes: the manifest committed at both `67d18746a` and A hashes to `709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a`, while `control_manifest.sha256` at A (`:143`) is `7d8fbb550fe3cc28b773b670f23cae2a389e11c4415767ca56d50cf7fb15ad50` — the live parent-checkout file, raised owner-side in `fd934ffac`. The two are no longer equal, so the M0 predicate as those records phrase it does not evaluate true at A. Handback §9 explains the re-pin correctly; §2 and `m0_evidence` were not re-derived alongside it — the same append-vs-substitute class this series has fired on at rounds 2, 5, 7 and 8. The §8.8 mechanical sweep does not cover it: it resolves 7–40 hex **commit** tokens and regenerates pasted **counts**, and a control-file `sha256` is neither. Suggested fix: state the base value as historical and record the pin's current value beside it, or extend the sweep with a fourth class (control-file digests resolved against their named path).

---

## C. Lens dispositions

- **`stock-gl-interaction`** — spec not met, on dimensions 1 and 4, for the reason above. Both sides of the seam were traced for every finding: the GL side is where the hole is (`journal_entries` MUTATE default); the stock side is **clean** — `stock_movements` MUTATE/DELETE are always violations, and `stock_levels`/`inventory_batch_stock` require the movement-pairing predicate, so the equivalent create-by-save shapes on all three inventory tables classify as `violation` (verified by probe). Dimensions 2, 3, 5, 6, 7, 8, 9, 10 raised nothing: this package adds no production code, writes no projection, and touches no money or quantity path (0 production files in the 15-file diff).
- **`inventory-costing`** — no costing defect. The WAC cluster (#20–#28) is correctly enumerated and dispositioned to the mapped side of §4's partition; no `(float)`, `parseFloat` or bare no-arg scale resolve appears anywhere in the diff; the guard is static-only with no DB and no container. Quality: changes requested only for the completeness-gate gap noted above.

## D. What to fix before merge

Close (or explicitly disclose, with the `:800` reason string corrected) the create-by-save hole on `journal_entries`, and re-derive the two stale manifest-`sha256` attestations — neither requires a re-seed, a re-pin, or a baseline edit.

Per the owner's HARD RIDER recorded at `enforcement-p1.progress.yaml:259`, a finding at round 9 parks the package `blocked_review` for re-scoping rather than authorizing a further continuation; that disposition is the parent's and the owner's to execute, not this register's.

VERDICT: CHANGES-REQUIRED

# FINAL-GATE REGISTER — package p1 M3 round 1 attempt 2
accepted_sha: 66c695a2b571fccacede3f4bb9ce9042b3b04d13
base_sha: 41fb478c21ae010568036aa66ffaef726ec63f09   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ 66c695a2b571fccacede3f4bb9ce9042b3b04d13 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19.md=92681381462f9949da105af815d57fe8f940edc403039edc95513cdf9a627f4b   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 5
---
# ADVERSARIAL FINAL-GATE REGISTER — enforcement package P1, milestone M3, round 1

**Snapshot:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.YzU49vRShb/snap`, detached at `66c695a2b571fccacede3f4bb9ce9042b3b04d13`, verified clean (`git status --porcelain` empty).
**Range:** `41fb478c21ae010568036aa66ffaef726ec63f09..66c695a2b571fccacede3f4bb9ce9042b3b04d13` — base confirmed a strict ancestor of A; 21 commits; 15 files, +4778/−22.
**Handback reviewed:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19.md`, `sha256 = 92681381462f9949da105af815d57fe8f940edc403039edc95513cdf9a627f4b`, 793 lines.
**Lenses applied in full:** `stock-gl-interaction`, `inventory-costing`.

---

## A. Content-derived evidence of table inspection (gate-r8 R8-H-2)

| Table | Row count | First row key | Last row key |
|---|---|---|---|
| §3.5 mechanism × table fixture coverage | **10** | `create` | `raw_sql` |
| §4 baseline ↔ DPA-register cross-check | **14** | `**V1** — TestE2EGLPosting hard-deletes sealed GL` | `**S0 residue** — WeightedAverageCostService reference params still ?string` |
| §4 "never covered" list | **15 physical rows** (expanding to 27 violation ids) | `1 / FixOrphanedProducts.php:128` | `20–28 / WeightedAverageCostService.php ×9` |
| §5 violation census / M2 seed baseline | **34** | `1 / app/Console/Commands/FixOrphanedProducts.php:128 / stock_levels / create / FixOrphanedProducts::executeCommand` | `34 / app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105 / journal_entries / delete / RepositoryTransferService::transfer` |

Independent reconciliation: I parsed §5's 34 rows into (file, table, mechanism, function) tuples and diffed them against the 34 keys in `apps/api/tests/Architecture/baselines/document-per-action-baseline.json`. **Symmetric difference is empty in both directions.** Baseline verified independently: 34 entries, unique, sorted, zero line numbers, all ordinals `#1`. First baseline key `app/Console/Commands/FixOrphanedProducts.php::…::executeCommand::stock_levels::create#1`; last `app/Modules/Treasury/…/RepositoryTransferService.php::…::transfer::journal_entries::delete#1`.

Seven census rows spot-checked against real source at the snapshot — `RepositoryTransferService.php:105` (`$draft->delete()`), `StockLevel.php:205` (`$this->save()`), `ReverseWriteOffService.php:186` (`$inverse->save()`), `ResetOpeningBalanceService.php:107` (`StockMovement::create`), `StockAdjustmentService.php:1618` (`StockLevel::firstOrCreate`), `GeneralLedgerService.php:353` (`JournalEntry::create`), `StockThresholdService.php:46` (`DB::table('stock_levels')->insert`). All file, line, table and mechanism accurate.

---

## B. What holds up (verified, not taken on trust)

- **Scope.** All 15 changed paths inside `apps/api/tests/Architecture/**` · `.github/workflows/ci.yml` · `docs/handoff/**`. Zero production files. Control-file preflight clean — no change to the bridges, brief, harness doc, control manifest, lens contracts, or any `*control-manifest*` surrogate.
- **Event graph.** `ci.yml:3-8` is exactly `push→main`, `pull_request→[main, dev]`, `workflow_dispatch`. The handback's statement is precise, including that a direct `dev` push never starts the workflow.
- **CI job.** `backend-dpa-guard` carries no `if:`; it is in `all-checks-pass` `needs` with the skipped-job-semantics comment; both test steps assert a nonzero selected-test count (`phpunit.xml` confirmed to set no `failOnEmptyTestSuite`); `DPA_BASELINE_PROTECTED_BLOB` is mapped from `vars.*`; the pin-tag step fails closed and loudly on an unresolvable tag name. **No `permissions:` grant anywhere in the workflow** (gate-r6 R6-H-5 check passes).
- **Ratchet.** All three directions present and fail-closed on unset variable, malformed hash, mirror drift, and unfetchable blob. Authority is the env-supplied blob, never the YAML; the YAML mirror is a drift tripwire only.
- **Liveness certificate.** Four gates, all non-vacuous; the negative-control requirement is derived from `DocumentPerActionWriteScanner::linkedFormExists()` rather than a local map. I verified `linkedFormExists()` against §3.5's "P-only" cells — the rule surface and the table agree exactly.
- **Blind spots A–H** present in the scanner docblock, in order, and unusually honest — H ("a baseline key is a SLOT, not a WRITE") names the sharpest limit of the design rather than eliding it.
- **Lens contracts.** No production code is touched, so dimensions covering GL-consequence-exactly-once, event emission, single-writer, WAC integrity, batch invariant and cash-lane are not in the blast radius. Rule 19: the only `float` token in the new code is a scalar type-name string. No `RefreshDatabase`, no DB, no SQLite-masking exposure. `phpunit.xml:35` confirms `memory_limit=2G`, so §8.4's self-correction is accurate. The four named red-at-base Architecture tests all exist; 18 − 2 − 4 = 12 matches the "remaining twelve excluded" claim.
- **§3.8** is a genuine find: a live, previously unguarded `journal_entries` row delete, correctly baselined rather than fixed, with a parent ticket requested — exactly the append-only discipline the `stock-gl-interaction` lens dimension 9 demands.

---

## C. Findings

### [Important] Handback §4 — the "COMPLETE list" of never-covered violations is incomplete, miscounted and misnumbered

The brief §7 item 3 designates the census/classification tables as review targets, not appendices. This one does not survive inspection:

1. **Three violations have no disposition in either direction of the cross-check.** Census **#10** (`ReverseWriteOffService.php:186`), **#15** (`ResetOpeningBalanceService.php:107`) and **#29** (`StockAdjustmentService.php:1618`) appear in the §5 census and in the baseline, but in neither §4 table. I grepped the whole handback: #10 appears only in §5 and in an M1 finding row; #15 and #29 appear only in §5.
2. **The stated count matches nothing.** The header claims "the COMPLETE list **(17 of the 34**…)". The table has 15 physical rows expanding to **27** violation ids. The true never-covered count is 21 if the WAC cluster is treated as S0-residue-mapped.
3. **The 9 WAC sites are double-counted** — claimed as S0-residue-mapped in the cross-check table ("violations #21/#23/#26/#28 (+ its level writes)") *and* listed as never-covered (`20–28 … ×9`). The never-covered list is defined as "the rest" after mapping, so it cannot also contain the mapped set.
4. **Two index labels are wrong.** The never-covered row labelled `33` is `RepositoryTransferService.php:105`, which §5, §3.8 and §8.5 all call **#34**. And the cross-check V10 row calls `ReturnScrapWriteOffService.php:165` "violation **#29**", but §5 puts that site at **#33**.

**Why it matters:** this is the third recurrence of a finding class three prior gates already raised and marked FIXED — round-2 P3-6 ("§4's 'never covered' list was illustrative") recorded its fix verbatim as "§4 carries the COMPLETE 17-row never-covered list", and the number in that closure statement is itself the error, carried forward unverified through rounds 3, 4 and 5. These bytes become owner-attested and immutable at promotion (the pin-tag `handback_sha256`), P3-M0 re-verifies the digest, and P3(a) consumes this census. This gate is the last point at which they can be corrected.

**Not a guard defect:** all three omitted sites *are* in the baseline and cannot grow. Nothing is left unguarded, and the mandated deliverable-4 direction (register → scan: "is any known violator silently certified clean?") is complete — V1–V10, G1–G3 and all S0 residues are dispositioned. The failure is confined to the reverse-direction summary and its completeness claim.

**Fix:** documentation only — no code, no re-seed, no re-pin, no new commit topology. Add #10, #15, #29 with dispositions; correct the two index labels; restate the count so it matches the table; resolve the WAC double-count by declaring which side owns those 9 rows.

### [Minor] §3.5 cell count is stale — "114 pinned cells" vs 115 actual

`grep -c "^            \['mechanism' =>"` on `DocumentPerActionWriteGuardTest.php` returns **115**. The extra cell is `journalEntrySetRawAttributesErasesLinkage` (`setRawAttributes()` erasure), labelled "round 5 note 2" in the matrix — and §3.5's per-round itemization of added cells stops at round 4, so the round-5 addition was never folded in. This is the same staleness round-4 P3-6 already caught once (91 → 114); it recurred at 114 → 115.

### [Minor] §3.1 fixture-class count understated

"Six fixture classes" — the directory holds **8** files declaring 10 classes (including `FixtureRelationHost`, `FixtureStockLevelSubclass`, `FixtureTopLevelLinkedWrite`, `FixtureTopLevelRouteWrites`).

### [Minor] `the_rule_surface_agrees_with_the_pinned_classifications()` — dead variable, docblock overstates the method

The docblock promises two directions; the body implements only the first. `$expectationsByCell` is populated in the loop and never read. No coverage gap — the second direction is genuinely enforced by `every_cell_with_a_linked_form_pins_a_negative_case()` — but this is precisely the "docblock asserts a property the code does not enforce" pattern rounds 1, 2 and 3 each flagged. Either delete the dead variable and narrow the docblock, or implement the second direction here.

---

## D. Assessment

The engineering is strong and the disclosure discipline is better than the norm for this series: the scanner's blind spots A–H are named rather than argued away, the S0 chokepoint is deliberately baselined rather than credited, the anti-growth authority genuinely sits outside every candidate diff, and the re-pin trigger in §8.1 discloses a way the ratchet can hard-block the remediation program it protects. The §5 census — the artifact that actually becomes the baseline and that P3 consumes — reconciles perfectly and survived source-level spot-checks.

What blocks is narrow and entirely in the handback: a table the brief elevates to a review target carries a false completeness claim, omits three of thirty-four violations, misnumbers two more, and double-counts nine. It is a documentation fix with no code, commit-topology or re-pin consequence — but it must land before the bytes are digested and owner-attested, because after that they are permanent.

Fix the four items above, re-hand over, and this package should accept on the next round.

VERDICT: CHANGES-REQUIRED

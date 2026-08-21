# FINAL-GATE REGISTER — package p1 M3 round 4 attempt 1
accepted_sha: 0ffed96c74d7cc70fb98a14cdf0e22aa354a914b
base_sha: 41fb478c21ae010568036aa66ffaef726ec63f09   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ 0ffed96c74d7cc70fb98a14cdf0e22aa354a914b (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r4.md=a0bfa9d59dd92a8a0b0d193c8d1cd7235747803384d561ea5f1136cf3ff5332a   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 5
---
I completed the review. Here is my register.

---

# ADVERSARIAL FINAL-GATE REGISTER — enforcement package P1, milestone M3, round 4

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.qVH8MTRntc/snap`, detached at `0ffed96c74d7cc70fb98a14cdf0e22aa354a914b` (A).
**Range:** `41fb478c21ae010568036aa66ffaef726ec63f09..0ffed96c74d7cc70fb98a14cdf0e22aa354a914b` — verified strict-ancestor, `BASE != A`, 25 commits, `Phase 4.0.1 … 4.3.8`.
**Handback evaluated:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r4.md` — 83404 bytes, 984 lines, `sha256 = a0bfa9d59dd92a8a0b0d193c8d1cd7235747803384d561ea5f1136cf3ff5332a` (computed independently from the parent's immutable copy).
**Working directory:** neutral and empty; every path below absolute or snapshot-relative.

---

## 1. Preflight (brief §5 item 4 step 1(iii); scope allowlist §2)

| Check | Result |
|---|---|
| Control-file surface (`adversarial-review.sh`, `adversarial-review-final.sh`, the brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*-reviewer.md`) | **no hit in `base..A`** |
| `*control-manifest*` surrogate added/changed in range | **none** |
| Path allowlist (`apps/api/tests/Architecture/**` · `.github/workflows/ci.yml` · `docs/handoff/**`) | **15/15 files conform, 0 violations** |
| Production code touched (`apps/api/app/**`, `apps/web/**`, `apps/pos/**`, `apps/api/database/**`) | **none** — guard-only respected |
| Workflow `permissions:` grant (gate-r6 R6-H-5) | **none added** |

Diffstat re-derived at A matches the handback §7.4 paste **byte-for-byte** (15 files, 4795 insertions, 24 deletions).

---

## 2. Round-3 CRITICAL — independently re-verified as closed

The round-3 finding was that `ci.yml`'s pin-tag step was a bash syntax error, so `backend-dpa-guard` aborted before running either gate. I re-ran the check on the YAML's own bytes rather than trusting the paste:

- All **5** `run:` blocks in `backend-dpa-guard` pass `bash -n` (extracted via `yaml.safe_load`, never retyped).
- The pin-tag extraction executed against the real `enforcement-p1.progress.yaml` bytes yields `PIN_TAG=[ci-pin/enforcement-p1-r1]`.
- Job carries **no `if:`**; `on:` is `push→main`, `pull_request→main|dev`, `workflow_dispatch` — the event graph the handback states.
- `backend-dpa-guard` is present in `all-checks-pass` `needs` with the skipped-job-semantics comment (H-9 satisfied).
- Action pins (`checkout@v5`, `cache@v5`, `setup-php@v2`) match the 14/8/8 pre-existing uses elsewhere in the file; `nikic/php-parser` is in `require-dev`, installed by the job's `composer install`.

---

## 3. Ratchet authority chain — verified against the tree, not the prose

- `git rev-parse ff5642f87:…/document-per-action-baseline.json` = **`1381983d463e6c546535be907d4aa1c7ca94c597`** = YAML mirror `dpa_baseline_protected_blob` = the blob at HEAD. Seed/mirror/working all agree.
- `dpa_baseline_pin_tag: ci-pin/enforcement-p1-r1` is **non-null in A** (R5-C-2), and the sed the CI step uses actually extracts it.
- Direction (c) reads `getenv(DPA_BASELINE_PROTECTED_BLOB)` and fails closed on unset/empty, malformed hash, **mirror≠variable drift**, unfetchable blob, or any added key — I read all four branches; none is a skip.
- `apps/api/phpunit.xml` sets **no `failOnEmptyTestSuite`** (0 matches), so the job's `grep -qE 'OK \([1-9][0-9]* test'` nonzero-selection assertion is genuinely load-bearing, not decorative.

**Tamper evidence is arithmetically self-proving.** I reconstructed the assertion counts from the code I read and every pasted figure lands exactly: full pass 37+76 = **113**; unset-variable **38**; mirror drift **40**; unfetchable blob **41**; one entry deleted (33 keys) **111**; bogus entry added (35 keys) **115**. Fabricated transcripts do not reproduce a six-value fingerprint derived from an independent read of the assertion sites.

---

## 4. Required census / classification tables — content-derived evidence of inspection

**§3.5 Mechanism × table fixture coverage (deliverable 6) — 10 data rows.**
First row key `create` (`P+N (+ half-linked P, unresolvable-payload P, nullable-linkage P, proven-linkage N) | …`); last row key `raw_sql` (`**P-only** ×3 | **P-only** ×2 | **P-only** | **P-only**`).
Underlying `fixtureMatrix()` = **115 cells**, which I confirmed three independent ways in the source: `grep -c "^            \['mechanism' =>"` → 115; unique `(mechanism, table, class, method)` tuples → 115; and the per-round origin table (5 rows + total, first `M1 initial matrix` = 95, last `round 5 — setRawAttributes() erasure ×1` = 1) sums 95+7+6+6+1 = **115**. The handback's own 114→115 correction is therefore accurate, not cosmetic.
First matrix cell: `create / journal_entries / FixtureJournalEntryWrites::createUnlinked → violation`. Last matrix cell: `raw_sql / inventory_batch_stock / FixtureBatchStockWrites::rawSqlUpdate → violation`.

**§4 Baseline ↔ DPA-register cross-check (deliverable 4) — 14 data rows.**
First row key **`V1` — `TestE2EGLPosting` hard-deletes sealed GL** → *no site / remediated, command file no longer exists*.
Last row key **`S0 residue` — `WeightedAverageCostService` reference params still `?string`** → *`:260/:425/:578/:767` → violations #21/#23/#26/#28*.
Every register item V1–V10, G1–G3 and all three S0 residues carries a disposition; the two "no site because remediated" and the one "outside the four-table set" claims are each reasoned, and the one open item (V5) is caught by the guard.

**§4 partition table — 2 data rows + total.** First row key `mapped to a register item or S0 residue` = **13**; last row key `never covered by the register` = **21**; total **34**. I verified the partition is disjoint **and** exhaustive over ids 1–34 by set arithmetic — the previously double-counted 9-row WAC cluster now sits on the mapped side only.

**§4 never-covered list — 17 printed rows expanding to 21 ids.**
First row key `1` (`FixOrphanedProducts.php:128` — level create in a console backfill). Last row key `34` (`RepositoryTransferService.php:105` — live `journal_entries` ROW DELETE).

**§5 Violation census — 34 data rows.**
First row key: `1 | app/Console/Commands/FixOrphanedProducts.php:128 | stock_levels | create | FixOrphanedProducts::executeCommand`.
Last row key: `34 | app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105 | journal_entries | delete | RepositoryTransferService::transfer`.
I parsed all 34 rows and matched them against the 34 keys in `document-per-action-baseline.json`: **exact bijection — 0 census-only, 0 baseline-only.** The census is the baseline, not a paraphrase of it.

**Spot-verification of the census against the actual tree** (7 sites + 1 linked cross-check row, all confirmed at the stated file:line, mechanism and table):
`RepositoryTransferService.php:105` → `$draft->delete()`; `StockAdjustmentService.php:1808` → `StockMovement::create([`; `:1618` → `StockLevel::firstOrCreate(`; `ResetOpeningBalanceService.php:107` → `StockMovement::create([` on the reversal path; `ReverseWriteOffService.php:186` → `$inverse->save()` after `reverses_movement_id` assignment; `GeneralLedgerService.php:353` → `JournalEntry::create` carrying `'source_type' => 'payment'` with **no `source_id`** (exactly as row #2 claims); `FixOrphanedProducts.php:128` → `StockLevel::create([`. Cross-check row V2 (`AccountingService.php:398`) does carry `source_type` + `source_id => $invoice->id` → the `linked` classification is correct.

**§7.5 M2 round-2 note absorption — 5 data rows.** First row key `1 — a baseline key is a SLOT, not a WRITE`; last row key `5 — blind spot C lacked a pointer to the unfixable-red consequence`.

**§9 gate history — 4 data rows.** First row key `M0` (setup-only, passed); last row key `M3` (round 1 → CR documentation-only; round 2 → CR record-honesty; round 3 → CR with a CRITICAL; fix rounds 1–3 applied). Final-gate disposition tables: round 1 = 4 rows, round 2 = 3 rows, round 3 = 3 rows.

**Corroborated factual claims in the handback** (each falsifiable, each checked): `phpunit.xml` pins `memory_limit=2G` (so §8.4's self-correction is right); `JournalEntry` declares no `SoftDeletes` (so §3.8's hard-delete finding is real); all four blind-spot-G seeders do contain live four-table writes (`CoffeeShopSeeder:1028/:1132`, `DemoPharmacySeeder:582`, `ParapharmacySeeder:1319`, `StockLevelSeeder:109`); 8 fixture files declaring 8 classes plus 2 class-less, as §3.1 states.

---

## 5. Lens contracts applied in full

**`stock-gl-interaction`.** The package writes no production code, so the seam dimensions are assessed as *what the guard encodes*. Document-per-action (dim. 1) is the guard's whole contract, with the reference-column rule stated per table and the null-admitting predicate refusing unproven linkage. Append-only ledgers (dim. 9) are encoded as hard rules — every MUTATE/DELETE against `stock_movements` is a violation with no linked form, and `journal_entries` DELETE likewise; `ReverseWriteOffService.php:186` and `StockTransferService.php:703` are caught by exactly that rule. Single-writer-per-projection (dim. 5) is the movement-pairing predicate for `stock_levels`/`inventory_batch_stock`. GL-consequence-exactly-once (dim. 2) and COGS-at-stock-exit (dim. 3) are **not** covered and are honestly declared undetectable-by-construction in the §4 G1/G2/G3 row ("a MISSING journal entry is the absence of a write"), routed to P3 rather than claimed. Float on the seam (dim. 10): zero `(float)`/`floatval`/`round(`/`number_format` in the entire new surface. Both-sides discipline is satisfied structurally — the pairing predicate is precisely a both-sides check, and blind spot F correctly narrows what a `linked` verdict means ("traceable to a movement", *not* "a justifying document exists") for the P3 consumer.

**`inventory-costing`.** No WAC arithmetic, scale resolution, or lock ordering is touched; the 9-site WAC cluster is enumerated and baselined, never modified. No opening-balance or FEFO behaviour changes. Precision checklist is vacuously clean (no money/quantity code). Test quality: real behaviour asserted (no `assertTrue(true)`, no `RefreshDatabase`, no Mockery, nothing mocked that is under test), the scanner is exercised against **real production bytes** plus fixtures rather than in a vacuum — `scanFixtures()` deliberately passes `app/` as resolution context so a fixture resolves through the same relation/inheritance/chokepoint indexes as live code. House Architecture-test style (`Tests\TestCase`, PHP-Parser AST, no DB) matched.

Both lens verdicts: spec satisfied, quality approved.

---

## 6. Findings

No Critical and no Important finding survives verification. Three P3/informational notes, all of which the handback already discloses and routes correctly — **none blocks acceptance, and none requires a fix round**:

1. **[P3] Deliverable-5 escape hatch taken on a different trigger than the brief names.** The brief's fallback reads "scope the job to the DPA guard class + the provably-static tests"; the job runs only the two DPA classes, leaving ~12 green static Architecture tests CI-dead. The trigger invoked is stronger than the brief's (four suite members are red at base — `AuthLifecycleTest`, `ConsoleCommandTenantContextTest`, `ControllerTenantContextTest`, `QueueJobTenantContextTest`), and both the exclusions and the reason are recorded in §7.1/§7.7 with a named follow-up. Widening would import failures P1 does not own into P1's own merge gate. Accepted as a disclosed, reasoned deviation.
2. **[P3] `grep -qE 'OK \([1-9][0-9]* test'` is brittle against PHPUnit's non-`OK` summaries** (risky/incomplete/deprecation runs print a different final line), and the error text would then misreport "selected zero tests". The direction is fail-closed — it can only produce a spurious red, never a false green — so it cannot certify a broken guard clean.
3. **[P3] Blind spots G and H are real and are the guard's sharpest limits** — `database/**` is unscanned with four named live instances, and a baseline key is a slot rather than a write, so swapping one unlinked write for another in the same bucket is CI-green. Both are named in the scanner docblock, escalated as parent tickets (§8.6, §7.5 note 1), and correctly framed as rulings for the parent rather than decided by the executor. The same is true of §8.1's re-pin trigger, where the ratchet can hard-block a legitimate remediation: disclosed in two docblocks plus the handback, with the owner re-pin path named.

## 7. Basis for the verdict

Every gate-r3 finding is closed and I re-proved the CRITICAL one from the artifact's own bytes rather than from the handback's paste. The evidence chain is content-bound at every link I could test independently: diffstat, scope allowlist, control preflight, seed↔mirror↔working blob equality, pin-tag extraction, census↔baseline bijection, sampled sites against the live tree, fixture-cell count three ways, partition arithmetic, and the six-value assertion fingerprint across the tamper matrix. The handback's census and classification tables are not appendices here — they are the review target, and they reproduce.

What raises rather than lowers my confidence is the failure mode of rounds 1–3: every defect found was a *record* defect (a stale count, a proof certifying the wrong tip, a conjunction never checked) rather than a guard defect, and the violation census held at **34 / 67 / 15** across nine executor-run rounds with the violation list byte-identical from round 3 onward. The package's honesty surface — blind spots A–H, the re-pin trigger, the candidate-deletable detector, the "17 was never derived from the table" lineage note — is written against the package's own interest, which is the posture this gate exists to reward.

VERDICT: ACCEPT

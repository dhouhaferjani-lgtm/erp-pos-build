# HANDBACK — enforcement package P1: document-per-action cementing guard (ratchet mode)

> **This file is deliberately UNTRACKED.** The final gate requires the handover tree to show
> `git status --porcelain` with EXACTLY ONE entry — `?? docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md`
> (brief §5 item 4 step 1(ii), gate-r9 R9-H-2: the parent's bridge copies and hashes this file itself, and a
> TRACKED-modified handback is rejected). It is therefore never committed by the executor; it lands in the
> parent's post-promotion closing commit C.

## 1. Header

| Field | Value |
|---|---|
| Base SHA | `67d18746a5e1c565c1f33c0394ae518af0e1cbf0` — **rebased 2026-08-20** (stale-A re-gate; the original base was `41fb478c21ae010568036aa66ffaef726ec63f09`) |
| Branch | `codex/enforcement-p1-dpa-guard` |
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/enforcement-p1` |
| Phase series | `Phase 4.<milestone>.<seq>` (parent-supplied, `commit_series` in the progress YAML) |
| 3C merge SHA | `1e8c0fa03adacd9d7b210ea41092c8f8538d515c` |
| 3C reviewed SHA | `d07868ccaf7f395bb50744a767c56aa4ab945983` |
| Final SHA | `3d7f55457a12d045dd3df9eb57e1f9e4acd65b88` — the handover tip after the 2026-08-20 rebase and its record sweep (the handback itself is untracked by contract) |

**Deviation on the branch name, recorded up front:** the progress YAML comment and brief §5 suggest
`codex/enforcement-p1-cementing-guard`; the parent's dispatch instruction of record specified
`codex/enforcement-p1-dpa-guard`, which is what was created. Name-only, no contract effect.

## 2. M0 — preconditions · DONE

All seven M0 predicates were executed mechanically; the evidence is echoed into
`docs/handoff/progress/enforcement-p1.progress.yaml` under `milestones[M0].m0_evidence`.

| # | Predicate | Result |
|---|---|---|
| 1 | `git rev-parse --verify` on base / 3C-merge / 3C-reviewed | all resolve |
| 2 | `git merge-base --is-ancestor 1e8c0fa03 41fb478c2` | exit 0 |
| 3 | ACCEPTED-3C proof: `wave3-3c-3d.progress.yaml` M3 (`:123-131`) `status: passed`, `commit: d07868cca…` **equals** `dpa_3c_reviewed_sha` (exact equality), `verdict: docs/handoff/reviews/wave3-3c-3d/M3-round7.md` resolves and contains exactly one `VERDICT:` line — `VERDICT: ACCEPT` (`:61`) — which is also its final non-empty line; wave top-level `status: complete` | satisfied |
| 4 | reviewed → merge → base ancestry, both hops (`git merge-base --is-ancestor`) | exit 0, exit 0 |
| 5 | S0 seam at the EXACT `dpa_3c_merge_sha`: `recordMovement` (`:1700`) carries `?StockMovementReferenceType $referenceType = null` (`:1715`) + `?string $referenceId = null` (`:1716`) and calls `assertReferenceLinkagePaired` (`:1719`); repeated at `base_sha` (`:1769`, `:1784`, `:1785`, `:1788`) | present at both |
| 6 | Fresh worktree/branch created from `base_sha` — NOT the 3C session (gate-r1 H-1) | satisfied |
| 7 | `commit_series`, `ratchet_trust_model_ack`, `control_manifest` non-null; `docs/handoff/enforcement-control-manifest.yaml` present at base with `sha256 = 709b6fe9a81cf5bbc5578aa297a44035a75e578708bd40155671c654094b217a`, matching the pin | satisfied |

## 3. M1 — scanner, per-table rules, fixture/tamper matrix · DONE

### 3.1 What was built

| Path | Role |
|---|---|
| `apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php` | The engine. PHP-Parser AST scan of `app/`; emits one classified site per write against the four tables. The OPERATIVE per-table rules and the KNOWN BLIND SPOTS live in its class docblock — that docblock is the reviewed contract, not this file. |
| `apps/api/tests/Architecture/DocumentPerActionWriteGuardTest.php` | The liveness certificate: the fixture matrix plus two completeness gates (every mechanism × table has a positive; every cell with a linked form by rule has a negative). |
| `apps/api/tests/Architecture/DocumentPerActionFixtures/**` | **8 fixture files.** Six declare 8 classes (`FixtureJournalEntryWrites`, `FixtureStockMovementWrites`, `FixtureStockLevelWrites`, `FixtureBatchStockWrites`, `FixtureLinkageProofWrites`, and `FixtureRelationAndInheritanceWrites` which declares three: itself, `FixtureRelationHost`, `FixtureStockLevelSubclass`), one method per matrix cell. The other two — `FixtureTopLevelRouteWrites.php`, `FixtureTopLevelLinkedWrite.php` — declare **no class at all** by design: they are the class-less top-level/route-closure fixtures, and a class would defeat what they pin. |

### 3.2 The per-table rules as implemented (summary; the docblock is authoritative)

- **`journal_entries`** — CREATE linked iff `source_type` + `source_id` are both present and not statically known to admit null (`nullAdmitting()`, blind spot E). DELETE always a violation. MUTATE in contract only for linkage erasure (payload nulls a linkage column, or a property assignment nulls one before `save()`) and for increment/decrement; other lifecycle updates are `not_applicable` — stated plainly, that exemption also covers `fiscal_hash`/`previous_hash`/`chain_sequence`/`entry_date`/`journal_code`, because this is a document-justification guard and **not** hash-chain coverage.
- **`stock_movements`** — CREATE linked iff `reference_type` + `reference_id` are both present and not statically known to admit null. MUTATE and DELETE are always violations (append-only ledger).
- **`stock_levels` / `inventory_batch_stock`** — no reference column exists, so the rule is the **movement-pairing predicate**: the enclosing function must reach the chokepoint (`$this->recordMovement(...)` inside `StockAdjustmentService`, or one of its AST-derived entry points through a receiver declared as that class) or create a `stock_movements` row that is itself `linked`; `inventory_batch_stock` may alternatively be justified by an `inventory_batch_movements` row carrying a non-null `movement_id`. Scope: only writes that can move the on-hand `quantity`; a resolvable MUTATE touching only the **positive allowlist** `reserved`/`reserved_quantity`/`min_quantity`/`max_quantity` is `not_applicable`.
- **Raw SQL** against any of the four tables — always a violation, by rule. There is no linked form.

### 3.3 Decisions the brief did not specify (flagged)

1. **Scan root is `app/` only — this is a RULING, not an observation, and it has live consequences.** `database/seeders`, `database/migrations` and `tests/` are excluded: seeders and migrations are provisioning surfaces with no justifying document by construction, and tests are not production writes. No DPA-register violator lives outside `app/` (§4). But there ARE live writes outside the root — `database/seeders/StockLevelSeeder.php:109` (`StockLevel::updateOrCreate`) and migration backfills touching accounting tables — and a future DATA-BACKFILL MIGRATION writing `journal_entries` would be outside the guard by construction. **Parent ticket requested** to decide whether the ratchet should later cover `database/**` (a separate baseline would be needed; the migration corpus is large and mostly DDL).
2. **The nullable-linkage rule puts the S0 chokepoint itself in the baseline.** `recordMovement`'s reference parameters are `?… = null` and `assertReferenceLinkagePaired` explicitly permits null/null, so its `StockMovement::create` is a baseline entry rather than a linked site. Crediting it would have made `stock_movements` coverage near-vacuous, since nearly every movement flows through that one site.
3. **`journal_entry_lines` / `journal_lines` are out of the table set** (the brief fixes the four tables). The DPA V1 adjudication's surviving residue — a `JournalLine` query-builder mass delete bypassing `JournalLineObserver` — is therefore NOT covered by this guard. Recorded, not silently dropped.
4. **The pairing predicate is function-scoped and order-insensitive** (both orders are legitimate inside one transaction) — see blind spot B in the docblock. For class-less files the scope is the whole file body, under the synthetic scope name `(top-level)`.
5. **Top-level code IS scanned** (added at round 4): 50 files under `app/` carry file-level statements — 46 `routes.php` plus four POS route files — and their inline closures were previously never opened. No live write to the four tables exists in any of them (the four route files that reference the models use controller-array routes only), so the census is unchanged; the pass exists so a future inline route-closure posting cannot be born invisible.

### 3.4 Red-first evidence

TDD for a scanner is fixture-first; the matrix's own completeness gate produced two genuine reds.

**Red 1 — `journal_entries / save` had no positive case:**

```
1) Tests\Architecture\DocumentPerActionWriteGuardTest::every_mechanism_is_pinned_for_every_table
Fixture matrix is incomplete:
journal_entries / save: no POSITIVE (violation) fixture
```

That was not a missing fixture but a missing RULE: a `save()` payload is invisible, so
`$entry->source_id = null; $entry->save();` could strip linkage unseen. Closed by the linkage-erasure rule
(property-assignment detection) plus its fixture. Green after.

**Red 2 — the M1 gate's finding 3 fix (round 2):** binding the pairing predicate to the real chokepoint
immediately turned three "linked" fixtures red, proving the old bare-name match had been crediting an empty
local stub:

```
WRONG CLASS  …FixtureStockLevelWrites::updateQuantityWithRecordedMovement::stock_levels::update#1: expected linked, got violation
WRONG CLASS  …FixtureStockLevelWrites::incrementQuantityWithRecordedMovement::stock_levels::increment#1: expected linked, got violation
WRONG CLASS  …FixtureStockLevelWrites::decrementQuantityWithRecordedMovement::stock_levels::decrement#1: expected linked, got violation
```

Green after rewiring those fixtures through `StockAdjustmentService`, and the discarded bypass is now pinned
as its own cell (`updateQuantityWithLocalRecordMovementStub` → violation).

**Red for rounds 2 and 3 is evidence-by-prior-register, stated plainly rather than implied.** Those fix
rounds landed their new fixture cells and the code that satisfies them in one commit each, so there is no
separately-committed red. The red is the preceding register itself: round 2 probed all four credited-shape
cases and recorded them as `linked` BEFORE the fix; round 3 probed the four alias shapes and both
`updateOrInsert` shapes and recorded them as `linked` / `not_applicable` before the fix. Each subsequent
gate re-executed those probes and confirmed the flip. One genuine in-round red did occur in round 3 and is
worth recording because it caught a real ordering bug: the alias-propagation loop was first placed BEFORE
the parameter pass, so `movementWithAliasedNullableParameter` still read `linked` —

```
WRONG CLASS  …FixtureLinkageProofWrites::movementWithAliasedNullableParameter::stock_movements::create#1:
             expected violation, got linked (payload carries reference_type + reference_id)
```

— fixed by moving the fixpoint loop after parameter nullability is known.

### 3.5 Mechanism × table fixture coverage (deliverable 6)

`P` = positive (violation) cell, `N` = negative (linked or not-applicable) cell. Cells marked
**P-only** have no linked form BY RULE, stated in the docblock — not an omission. Enforced mechanically by
`every_mechanism_is_pinned_for_every_table()` and `every_cell_with_a_linked_form_pins_a_negative_case()`.

| mechanism | journal_entries | stock_movements | stock_levels | inventory_batch_stock |
|---|---|---|---|---|
| create | P+N (+ half-linked P, unresolvable-payload P, nullable-linkage P, proven-linkage N) | P+N (+ half-linked P, nullsafe-linkage P, coalesced-linkage N) | P+N (+ relation-mediated P+N) | P+N |
| firstOrCreate | P+N | P+N | P+N | P+N (+ conditional-movement_id P) |
| updateOrCreate | P+N | P+N | P+N | P+N |
| update | P (erasure) + N (lifecycle) | **P-only** (append-only) | P+N (+ soft-hold N, variant-regrain P, stub-bypass P, model-internal P+N) | P+N (+ soft-hold N) |
| save | P (erasure) + N (lifecycle) | **P-only** | P+N (+ model-internal P) | P+N |
| delete | **P-only** | **P-only** | P+N | P+N |
| increment | **P-only** | **P-only** | P+N | P+N |
| decrement | **P-only** | **P-only** | P+N (+ soft-hold N) | P+N (+ soft-hold N) |
| query_builder | P+N (+ builder-delete P) | P+N | P+N | P+N |
| raw_sql | **P-only** ×3 | **P-only** ×2 | **P-only** | **P-only** |

**115 pinned cells** in `fixtureMatrix()`, enforced by four gates: the classification matrix, the
positive-coverage gate, the negative-control gate, and the rule-surface agreement gate.

Counted three independent ways so the number is derived, not asserted (the previous "114" was stale by one
— the round-5 `setRawAttributes()` cell was added without folding it into this figure, the same staleness
class the round-4 gate caught once already at 91 → 114):

```
grep -c "^            \['mechanism' =>"  -> 115
count(fixtureMatrix())                    -> 115
unique (class, method, table, mechanism)  -> 115   # no cell is double-listed
```

Per gate round, which also sums to 115:

| origin | cells |
|---|---|
| M1 initial matrix | 95 |
| round 2 — nullable linkage ×4, unreadable MUTATE ×2, non-nullable-property boundary ×1 | 7 |
| round 3 — alias shapes ×3, `updateOrInsert` ×3 | 6 |
| round 4 — mass-assignment erasure ×3, fill-lifecycle control ×1, two-arg merge order ×1, destructured null ×1 | 6 |
| round 5 — `setRawAttributes()` erasure ×1 | 1 |
| **total** | **115** |

(The round-4 top-level/route-closure cells and the round-2 relation/model-internal cells are counted inside
the base figure where their notes do not name a round; the sum is the authority, and it is the same 115.)

### 3.6 Acceptance evidence (M1)

```
$ ./vendor/bin/phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php
OK (4 tests, 4 assertions)

$ ./vendor/bin/phpstan analyse --level=8 tests/Architecture/Support tests/Architecture/DocumentPerActionWriteGuardTest.php tests/Architecture/DocumentPerActionFixtures
(0 errors)

$ ./vendor/bin/pint --test tests/Architecture/Support tests/Architecture/DocumentPerAction*
{"result":"pass"}
```

Census produced by the scanner at this tip: **34 violations · 67 linked · 15 not-applicable**, over 19 files.
The full violation list is §5 below (it becomes the M2 seed baseline).

**Residual coverage audit** (run because blind spot A is a real limit, not a formality). Every file under
`app/` that mentions one of the four model class names AND contains any write method was enumerated: **68
candidate files**; the scanner emits sites in **25** of them. The 43 remaining files were inspected — every
one of them writes a DIFFERENT model (`GroupedWriteOff`, `GoodsReceipt`, `GoodsReceiptLine`,
`PaymentInstrument`, `PurchaseOrder`, vouchers, …) and only READS the four tables. No file with a write to a
target table is missing from the census.

### 3.7 Gate history (M1)

| Round | Register | Verdict | Outcome |
|---|---|---|---|
| 1 | `docs/handoff/reviews/enforcement-p1/M1-round1.md` | CHANGES-REQUIRED (2×P1, 4×P2, 6×P3) | all findings addressed |
| 2 | `docs/handoff/reviews/enforcement-p1/M1-round2.md` | CHANGES-REQUIRED (0×P1, 2×P2, 6×P3) | all findings addressed — round-2 table below |
| 3 | `docs/handoff/reviews/enforcement-p1/M1-round3.md` | CHANGES-REQUIRED (0×P1, 2×P2, 6×P3) | all findings addressed — round-3 table below |
| 4 | `docs/handoff/reviews/enforcement-p1/M1-round4.md` | CHANGES-REQUIRED (0×P1, 2×P2, 6×P3) | all findings addressed — round-4 table below |
| 5 | `docs/handoff/reviews/enforcement-p1/M1-round5.md` | **ACCEPT** (0×P1, 0×P2, 7×P3) | M1 accepted; the P3 notes are closed in Phase 4.2.1 and §3.7e |

**Census stability across the fix rounds — the load-bearing fact:** the violation census has been
**34 / 67 / 15 at every round** (round 1 → 2 → 3 → 4 fixes), and at round 4 the violation LIST was
byte-identical to round 3's. Every hole the gates found was a FORWARD-guarantee
leak, not a present mis-certification: each gate independently verified that the shape it probed had zero
live instances in `app/`. The guard got materially stronger three times without a single reclassification of
live code.

Finding-by-finding disposition:

| # | Finding | Disposition |
|---|---|---|
| P1-1 | Soft-hold exemption implemented as "lacks `quantity`", silently clearing `update(['variant_id' => …])` | FIXED — positive allowlist `SOFT_HOLD_COLUMNS`; `StockLevelMigrationService.php:58` now reports as a violation; fixture cell `updateVariantGrainOnly` pins it |
| P1-2 | Unresolvable receivers dropped silently (fail-open); ≥3 live writes invisible | FIXED — `$this` resolution (incl. through the `extends` chain), tree-wide return-type index for `$this->collaborator->method()` / typed-param receivers, `@var` hints. All three named sites now report: `BatchStock.php:84`, `ReverseWriteOffService.php:186`, `StockLevel.php:205`. Residual honestly enumerated as blind spot A in the docblock |
| P2-3 | Pairing predicate was a bare name match | FIXED — chokepoint-bound arms + arm (b) now requires the paired movement create to be itself `linked`; the discarded bypass is pinned as a fixture cell |
| P2-4 | "non-null" meant "not the literal `null`", crediting the S0 chokepoint | FIXED — the null-admittance predicate (renamed `nullAdmitting()` in round 2); nullable params / null defaults / nullsafe reads no longer prove linkage; four fixture cells added; consequence accepted and recorded (§3.3 item 2) |
| P2-5 | Relation-mediated writes had zero fixture coverage | FIXED — `FixtureRelationAndInheritanceWrites` + its `hasMany` host; two matrix cells |
| P2-6 | Named handback deliverable absent | FIXED — this file (untracked by contract, see the banner) |
| P3-7 | Red-first was narrative only | ADDRESSED — §3.4 pastes both reds |
| P3-8 | PHPStan level 8: 17 errors on new files | FIXED — 0 errors (§3.6) |
| P3-9 | `--testsuite=Architecture` is red at base (4 pre-existing failures) | CARRIED to M3 — the CI job will be scoped to the DPA guard classes with the exclusions recorded |
| P3-10 | Positional `#<ordinal>` key churn | ACCEPTED + DOCUMENTED as blind spot C (fails safe) |
| P3-11 | JE MUTATE exemption also covers hash-chain columns | DOCUMENTED explicitly in rule 1 |
| P3-12 | Two untracked M2 artifacts present during the gate | FIXED — parked outside the worktree until M2 |

### 3.7b Round-2 finding disposition

Round 2 confirmed every round-1 fix in code (it re-ran the scanner, reproduced the 34/67/15 census, audited
all 15 `not_applicable` sites individually, and corroborated the red-first evidence by re-running the fixture
scan WITHOUT the production context roots — exactly the three documented cells flip). It then found two
forward holes of the same class as round 1: *the docblock asserting a property the code does not enforce*.
Neither was live-exploited, and the census is unchanged at **34/67/15** after the fixes — these are
forward-guarantee repairs with zero live churn.

| # | Finding | Disposition |
|---|---|---|
| P2-1 | `provablyNonNull()` credited four unprovable shapes (null-assigned local, nullable `$this` property, nullable-returning `$this` method, array element) while the docblock claimed provability | FIXED by widening + RENAMING the predicate to `nullAdmitting()`: the four probe shapes are now refused, and the residue is named as **blind spot E** — values the scanner cannot decide (`$document->id`, a collaborator's call) are credited WITHOUT proof, because inverting that default would flag the ordinary linkage shape and drown the baseline. The docblock no longer claims provability. Five fixture cells pin the four refused shapes plus the credited-boundary case |
| P2-2 | `journal_entries` MUTATE with an unreadable payload fell through to `not_applicable` — invisible, and a way around the erasure rule via `update($vars)` | FIXED: an unreadable MUTATE payload on a reference-column table is now a VIOLATION. `save()` is explicitly excluded (it never carries an inspectable payload; its erasure path is the property-assignment rule) and that exclusion is stated in rule 1. Two fixture cells, including the array-union form |
| P3-3 | Erasure-by-assignment matched only a literal `null` | FIXED: it now uses the same `nullAdmitting()` predicate, so `$entry->source_id = $this->maybeReferenceId();` is caught. Fixture cell added |
| P3-4 | `buildRelationMap` prefilter dropped `morphOne` although the accepted-relation list includes it | FIXED — one-token inconsistency closed |
| P3-5 | Pairing-arm asymmetry (arm (b) requires a linked movement, arm (a) does not) unstated | DOCUMENTED as **blind spot F**: a `linked` level write means "traceable to a movement", NOT "a justifying document exists" |
| P3-6 | Handback §5 dangled; §4's "never covered" list was illustrative | FIXED: §5 now carries the full 34-row census, §4 carries the COMPLETE 17-row never-covered list |
| P3-7 | `$positiveOnly` hardcoded in the test — a self-weakening knob inside the liveness certificate | FIXED: the test now derives it from `DocumentPerActionWriteScanner::linkedFormExists()`, so that knowledge lives next to the rules it describes |
| P3-8 | Round-1 carries (Architecture suite red at base; ordinal churn) | unchanged, still carried to M3 / blind spot C |

### 3.7c Round-3 finding disposition

Round 3 again reproduced the census independently (34/67/15, all 34 rows diffed against its own run and
confirmed verbatim-accurate against §5), re-probed every round-2 fix, and confirmed `morphOne` now resolves
end-to-end and that `linkedFormExists()` is genuinely consulted. It found two more forward holes — the same
class as rounds 1 and 2 — and six notes.

| # | Finding | Disposition |
|---|---|---|
| P2-1 | ONE alias assignment defeated every refused null shape (`$id = $this->maybeId();`, `$b = $a;`, alias of a nullable parameter) — the scanner had already decided the source and dropped the fact one hop later, so this was NOT covered by blind spot E | FIXED — null-admittance now PROPAGATES through local aliases to a fixpoint (bounded iteration inside the existing loop in `buildVarTypes()`). Three fixture cells pin the one-hop, two-hop and nullable-parameter-alias shapes. In-round red recorded above (the loop was initially placed before the parameter pass) |
| P2-2 | `updateOrInsert` was mapped to MUTATE although `Query\Builder::updateOrInsert()` INSERTS when nothing matches — an unlinked `journal_entries` row creation classified `not_applicable`, i.e. permanently invisible | FIXED — remapped to `['updateOrCreate', 'CREATE']`; three fixture cells (Eloquent unlinked, Eloquent linked, builder mechanism). The failure was confined to `journal_entries`: `stock_movements` MUTATE is unconditionally a violation and the level tables are governed by the pairing predicate regardless of write class |
| P3-3 | Erasure through a COLLABORATOR's nullable call is not caught (the value side is `$this`-scoped) | DOCUMENTED where rule 1 states the erasure path, so it is not read as general |
| P3-4 | A write inside an anonymous class emitted THREE baseline keys for one physical write, one naming a method that does not exist on the outer class | FIXED — methods of a nested class-like are attributed to that class only; noted in blind spot C |
| P3-5 | Handback numbering (`#33` → `#34`) and stale `provablyNonNull` naming after the round-2 rename | FIXED throughout this document |
| P3-6 | `linkedFormExists()` is a parallel restatement of the rules that could drift from `classifySite()` | ADDRESSED — new gate `the_rule_surface_agrees_with_the_pinned_classifications()` ties them over every cell the matrix exercises |
| P3-7 | Round-2 red-first was evidence-by-prior-register but not stated as such | FIXED — §3.4 now says so explicitly and adds the round-3 in-round red |
| P3-8 | Carried items (Architecture suite red at base → M3; ordinal churn → blind spot C) | unchanged |

### 3.7d Round-4 finding disposition

Round 4 re-derived the census row-for-row, reflectively counted the matrix, re-ran each gate in isolation to
prove non-vacuity, and re-probed every round-3 fix. Its two P2s were again forward holes with zero live
instances — but finding 1 was materially larger than its predecessors: not a value-nullability edge, an
un-enumerated CODE REGION.

| # | Finding | Disposition |
|---|---|---|
| P2-1 | **50 files inside the scan root were never opened.** `topLevelFunctionLikes()` found only NAMED functions, so every inline route closure (`Route::post('/x', function () { JournalEntry::create([...]); })`) emitted no site at all — while the scanner's own comment claimed "route/closure files" were covered | FIXED — a new top-level pass scans file-level statements (namespaces unwrapped; class and named-function declarations excluded, since they have their own scopes) under the synthetic scope `(top-level)`. Probe before: `total: 0`; after: the write is reported. Three fixture cells, one of them in its own file because the top-level scope spans a whole file body |
| P2-2 | `fill()` / `forceFill()` / `setAttribute()` before `save()` erased linkage and landed as `not_applicable` — invisible — while rule 1 asserted the `save()` erasure path WAS covered | FIXED — the erasure rule now covers both routes: direct property assignment AND mass assignment, with an unreadable `fill`/`forceFill` payload failing closed on the same standard as `update()`. Four fixture cells including the lifecycle-only negative control |
| P3-3 | Round-3's anon-class fix was half-closed (the finder still walked into the nested body from the enclosing scope → two keys), and two anon classes in one file produced BYTE-IDENTICAL keys, merging two violations into one baseline entry | FIXED both ways — nested class-like nodes are excluded from the enclosing scope's traversal, and anonymous classes are numbered in file order. Probe: two anon classes now yield exactly two distinct keys |
| P3-4 | `firstOrCreate($attributes, $values)` credited a linkage key that `$values` nulls, against Laravel's own `array_merge($attributes, $values)` order | FIXED — the later argument now wins, matching the row that actually gets created. Fixture cell added |
| P3-5 | The alias fixpoint did not cover destructuring | FIXED for the decidable case (a literal element list); a destructure from a call is undecidable and falls under blind spot E |
| P3-6 | Handback acceptance evidence stale against the tip (3 tests → 4; 91 cells → 114) | FIXED — §3.5/§3.6 now reproduce, with the per-round cell additions itemised |
| P3-7 | The `app/`-only scan root is a ruling with live consequences (`database/seeders/StockLevelSeeder.php:109`) | RECORDED in §3.3 item 1 as a ruling, with a **parent ticket requested** for whether `database/**` should later be covered |
| P3-8 | Carried items | unchanged |

### 3.7e Round-5 finding disposition (the ACCEPT round)

Round 5 returned **ACCEPT** with 0 P1 / 0 P2 and seven P3 notes. The reviewer insisted note 1 land before M2
seeded, because M2 inherits the scanner docblock as its reviewed contract; the rest were closed in the same
pass (commit `19d4a7a54`) so the seed was generated by a scanner whose documentation matches its behaviour.

| # | Note | Disposition |
|---|---|---|
| 1 | The `(top-level)` scope makes the pairing predicate WHOLE-FILE for class-less files, while blind spot B still said "function-scoped" — and two fixtures cross-referenced a fact the docblock did not state | FIXED — blind spot B now states the whole-file widening explicitly, so the fixtures' cross-reference is true |
| 2 | `setRawAttributes()` was an uncovered mass-assignment route to linkage erasure | FIXED — covered by the erasure rule, with its own matrix cell. Probe: violation (was `not_applicable`) |
| 3 | `upsert()` can never classify as `linked` (its arg shapes make the payload unresolvable) — a fail-safe false positive | RECORDED as blind spot **D2**; direction is safe and there are zero `upsert` calls on the four tables |
| 4 | A named function declared inside a top-level conditional was scanned twice — once in its own scope, once inside the synthetic top-level body | FIXED — the top-level pass excludes named-function subtrees. Probe: one key (was two) |
| 5 | Anonymous-class renumbering is a second key-churn axis blind spot C did not mention | RECORDED in blind spot C |
| 6 | §3.4's red-first record stopped at round 3 | FIXED — §3.4 states the evidence-by-prior-register status for rounds 2–4 explicitly and adds the round-3 in-round red |
| 7 | Administrative: the YAML still read `status: review` for M1 at the reviewed commit | DONE — recorded in Phase 4.2.4 (`M1.status: passed`, `last_verdict: ACCEPT`, `verdict: …/M1-round5.md`) |

## 3.8 ⚠️ FINDING FOR THE PARENT — a live `journal_entries` ROW DELETE the DPA register never caught

**`app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105` — `$draft->delete()` on a
`JournalEntry`.** Violation **#34** in the §5 census; mechanism `delete`, table `journal_entries`.

- **What it is.** `transfer()` may pre-create a draft journal entry, then calls
  `TreasuryMovementService::transfer(...)`. If the resulting out-leg did not adopt that draft
  (`$outLeg->journal_entry_id !== $draft->id`), the draft row is **hard-deleted** to avoid an orphan.
- **Why it is a violation under the operative rule.** `journal_entries` rows are append-only: a correction
  is a reversing document, never a row delete. This is the same shape as DPA lane **V1** (GL hard-delete),
  which the register recorded as eliminated — the register never covered this site, so it has been live and
  unguarded the whole time.
- **Mitigating context, stated honestly so the fix lane is scoped correctly:** the deleted row is an
  *unused draft* (never posted, no lines adopted, no hash-chain sequence consumed on the draft path). This
  is materially milder than deleting a posted entry — but it is still a row disappearing from the fiscal
  table with no justifying document, and the guard is deliberately not in the business of judging which
  deletes are "safe enough".
- **P1 does NOT fix it.** This package is guard-only (brief §1 DO NOT TOUCH: "Any baseline violator's
  production code"). The site is baselined so it can never grow, and **the parent is asked to ticket a DPA
  remediation lane** for it. Suggested shape for that lane: either never create the draft until the leg is
  known to adopt it, or mark the unused draft cancelled/voided instead of deleting the row.

## 4. Baseline ↔ DPA-register cross-check (deliverable 4)

Register source: `docs/superpowers/audits/2026-08-08-document-per-action-violation-sweep.md` (V1–V10 + G1–G3),
with lane status from the DPA remediation `progress.md`.

| Register item | Scan result | Disposition |
|---|---|---|
| **V1** — `TestE2EGLPosting` hard-deletes sealed GL | no site | Remediated: the command file no longer exists. The guard's DELETE rule (fixture-pinned) fires if the shape returns. **Residue:** the `JournalLine` mass delete is on `journal_lines`, outside the four-table set (§3.3 item 3) |
| **V2** — opening-balance import posts unsourced JEs | `AccountingService.php:398` → **linked** | Remediated; scan confirms the linkage is now present |
| **V3** — repository adjustment sourced to a dangling UUID | `GeneralLedgerService.php:1249` → **linked** | Remediated |
| **V4** — `reversePayment` deletes allocations, no reversal doc | no site | Out of the table set: `payment_allocations` is not one of the four tables |
| **V5** — manual JEs are SELF-sourced | `JournalEntryController.php:97` → **violation #3** | **The one open register item, and the guard catches it.** Note the guard checks linkage PRESENCE, not the audit's "non-self" property — a self-referencing `source_id` supplied in the payload would read as linked; V5 is caught because `source_id` is assigned after creation, not in the payload |
| **V6** — `upsertStockLevel` absolute overwrite | no site | Remediated: `InventoryService` is read-only by ruling D4. The shape stays pinned by the `updateOrCreateBypassingMovement` fixture |
| **V7** — four raw HTTP stock writers | no site | Remediated: endpoints deleted |
| **V8** — supplier credit note issues bonus-return stock | `SupplierCreditNotePostingService.php:666` → **linked** (movement carries `reference_type`/`reference_id` = the CN document) | **Scanner-gap disposition:** the write-linkage contract IS satisfied; V8's violation class is "one money document performing a second, stock action", a semantic judgement a static write-linkage scan cannot make. Its fix lane is parked unmerged; this guard neither blocks nor certifies it |
| **V9** — POS void restock | no site | Remediated: `ReceiptVoidService` deleted, route tombstoned |
| **V10** — POS scrap return | `ReceiptReturnService.php:1330/1334` → **linked**; `ReturnScrapWriteOffService.php:165` → **violation #33** | Remediated for the write path; the guard additionally surfaces the post-hoc `is_historical` mutation of the returned movement, which is new (append-only rule) |
| **G1/G2/G3** — GL legs missing (POS COGS, counting adjustments, shift variance) | no site, structurally | **Structural gap, recorded:** this guard enumerates writes that HAPPEN. A MISSING journal entry is the absence of a write and is undetectable by construction. Package P3's balance assertions are the guard for that class |
| **S0 residue** — `OpeningBalancePostingService` opening movements unlinked | `:117` → **violation #12** | Caught. Matches the parked S0 residue exactly |
| **S0 residue** — `StockTransferService` post-hoc movement UPDATE | `:703` → **violation #19** | Caught |
| **S0 residue** — `WeightedAverageCostService` reference params still `?string` | `:260/:425/:578/:767` → **violations #21/#23/#26/#28** (+ its level writes) | Caught, and this is the single largest cluster in the baseline (9 sites in one file) |

**Conclusion:** every register violator that still exists as code is either found by the scan or has a
recorded, reasoned disposition. Nothing in the register is silently certified clean.

**Violations the DPA register never covered — the COMPLETE list, 21 of the 34.**

The partition is exhaustive and disjoint by construction, and the arithmetic is stated so it can be checked
rather than trusted:

| side | census ids | count |
|---|---|---|
| mapped to a register item or S0 residue in the table above | #3 (V5) · #33 (V10) · #12 (S0) · #19 (S0) · #20–#28 (S0, the WAC cluster) | **13** |
| never covered by the register — the table below | #1 #2 #4 #5 #6 #7 #8 #9 #10 #11 #13 #14 #15 #16 #17 #18 #29 #30 #31 #32 #34 | **21** |
| | | **34** ✓ |

**The 9 WAC rows (#20–#28) belong to the MAPPED side, not this one.** They are the S0 "`WeightedAverageCostService`
reference params still `?string`" residue, which the cross-check table above already dispositions; listing
them here as well double-counted them. The never-covered list is defined as *the rest* after mapping, so it
cannot also contain the mapped set.

| # in §5 | site | class |
|---|---|---|
| 1 | `FixOrphanedProducts.php:128` | level create in a console backfill, no movement |
| 2 | `GeneralLedgerService.php:353` | JE create with `source_type` but no `source_id` |
| 4–7 | `BatchStockService.php:94/:104/:325/:341` | batch-stock writes; `ensureDefaultBatch` documents itself as movement-free, `recordBatchMovement` only conditionally carries `movement_id` |
| 8 | `BatchStock.php:84` | model-internal `$this->update(['quantity' => …])` |
| 9 | `FEFOInventoryService.php:263` | builder update of batch stock during consumption |
| **10** | `ReverseWriteOffService.php:186` | **`$inverse->save()` stamping `reverses_movement_id` onto a just-created movement — a MUTATE of the append-only ledger. Directly actionable: `recordMovement` already accepts `?string $reversesMovementId`, so the reversal link can be passed at CREATION instead of stamped after, which removes the violation without changing behaviour.** |
| 11 | `UninvoicedDeliveryNoteService.php:257` | year-end adjustment JE with no source linkage |
| 13/14 | `OpeningBalancePostingService.php:139/:141` | level write/create paired only with the UNLINKED opening movement |
| **15** | `ResetOpeningBalanceService.php:107` | **the opening-balance REVERSAL movement is created with no `reference_type`/`reference_id` — the same unlinked-movement class as #12, at a site the DPA audit cited as a COMPLIANT idiom. The audit was judging the site's GL/document handling; the movement's own reference linkage was never examined. The guard looks only at the write, and the write is unlinked.** |
| 16 | `ResetOpeningBalanceService.php:137` | the level write on that same reversal path |
| 17 | `StockLevelMigrationService.php:58` | bulk re-key of `variant_id` — moves on-hand between grains |
| 18 | `StockThresholdService.php:46` | threshold service INSERTs a zero-quantity level row |
| **29** | `StockAdjustmentService.php:1618` | **`getOrCreateStockLevel`'s zero-valued `StockLevel::firstOrCreate` — the chokepoint's OWN helper writes a level row with no movement in scope. This is the exact mechanism the brief names as a must-cover fixture case ("a zero-valued `firstOrCreate` writes a target row even when a later `update` branch does not run"), so its presence in the baseline is the guard working as specified, not an oversight.** |
| 30 | `StockAdjustmentService.php:1808` | the S0 chokepoint's own movement create (null/null linkage permitted) |
| 31 | `StockLevel.php:205` | model-internal `$this->save()` in `recalculateReserved` |
| 32 | `ReceiptReturnService.php:1699` | builder update of batch allocations on return |
| 34 | `RepositoryTransferService.php:105` | **live `journal_entries` ROW DELETE — see §3.8** |

**Correction notice, with its lineage (final gate round 1).** Every number in this block was wrong until
now, and the error has a traceable origin worth recording rather than quietly fixing: the count "17" was
never derived from the table — it entered as the *closure statement* of the M2 round-2 P3-6 finding
("§4 carries the COMPLETE 17-row never-covered list"), and was then carried forward unverified through
three further gate rounds, each of which read the claim rather than recomputing it. What the final gate
found, and what is corrected above: three violations (#10, #15, #29) had no disposition on either side of
the cross-check; the 9 WAC rows were counted on both sides; the `RepositoryTransferService` row was
labelled #33 when §5, §3.8 and §8.5 all call it #34; and the V10 cross-check row called
`ReturnScrapWriteOffService.php:165` violation #29 when §5 puts it at #33. The 21/13/34 partition above was
derived by re-running the scanner and mapping all 34 census ids, not by adjusting the old number.

**No guard defect is implied.** All three previously-omitted sites ARE in the baseline and cannot grow; the
mandated deliverable-4 direction (register → scan: "is any known violator silently certified clean?") was
and remains complete for V1–V10, G1–G3 and every S0 residue. The failure was confined to this
reverse-direction summary and its completeness claim.

## 5. Violation census — the M2 seed baseline (34 sites)

Pasted verbatim from the scanner at this tip. Every row becomes one baseline key
(`<file>::<class>::<function>::<table>::<mechanism>#<ordinal>`); the file:line shown here is for humans and is
deliberately NOT part of the key (blind spot C).

| # | file:line | table | mechanism | class::function | why |
|---|---|---|---|---|---|
| 1 | `app/Console/Commands/FixOrphanedProducts.php:128` | stock_levels | create | FixOrphanedProducts::executeCommand | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 2 | `app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:353` | journal_entries | create | GeneralLedgerService::createPaymentEntry | create-class write without paired source_type/source_id linkage |
| 3 | `app/Modules/Accounting/Presentation/Controllers/JournalEntryController.php:97` | journal_entries | create | JournalEntryController::store | create-class write without paired source_type/source_id linkage |
| 4 | `app/Modules/BatchExpiry/Application/Services/BatchStockService.php:94` | inventory_batch_stock | firstOrCreate | BatchStockService::ensureDefaultBatch | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 5 | `app/Modules/BatchExpiry/Application/Services/BatchStockService.php:104` | inventory_batch_stock | update | BatchStockService::ensureDefaultBatch | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 6 | `app/Modules/BatchExpiry/Application/Services/BatchStockService.php:325` | inventory_batch_stock | firstOrCreate | BatchStockService::recordBatchMovement | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 7 | `app/Modules/BatchExpiry/Application/Services/BatchStockService.php:341` | inventory_batch_stock | update | BatchStockService::recordBatchMovement | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 8 | `app/Modules/BatchExpiry/Domain/Entities/BatchStock.php:84` | inventory_batch_stock | update | BatchStock::adjustQuantity | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 9 | `app/Modules/BatchExpiry/Domain/Services/FEFOInventoryService.php:263` | inventory_batch_stock | query_builder | FEFOInventoryService::consumeBatchesAtomically | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 10 | `app/Modules/BatchExpiry/Domain/Services/ReverseWriteOffService.php:186` | stock_movements | save | ReverseWriteOffService::reverse | the stock-movement ledger is append-only; an existing movement is never mutated |
| 11 | `app/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php:257` | journal_entries | create | UninvoicedDeliveryNoteService::generateYearEndAdjustment | create-class write without paired source_type/source_id linkage |
| 12 | `app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:117` | stock_movements | create | OpeningBalancePostingService::post | create-class write without paired reference_type/reference_id linkage |
| 13 | `app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:139` | stock_levels | update | OpeningBalancePostingService::post | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 14 | `app/Modules/Inventory/Application/Services/OpeningBalancePostingService.php:141` | stock_levels | create | OpeningBalancePostingService::post | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 15 | `app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php:107` | stock_movements | create | ResetOpeningBalanceService::reset | create-class write without paired reference_type/reference_id linkage |
| 16 | `app/Modules/Inventory/Application/Services/ResetOpeningBalanceService.php:137` | stock_levels | update | ResetOpeningBalanceService::reset | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 17 | `app/Modules/Inventory/Application/Services/StockLevelMigrationService.php:58` | stock_levels | query_builder | StockLevelMigrationService::migrateToDefaultVariant | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 18 | `app/Modules/Inventory/Application/Services/StockThresholdService.php:46` | stock_levels | query_builder | StockThresholdService::update | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 19 | `app/Modules/Inventory/Application/Services/StockTransferService.php:703` | stock_movements | update | StockTransferService::markMovementAsTransfer | the stock-movement ledger is append-only; an existing movement is never mutated |
| 20 | `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:178` | stock_levels | create | WeightedAverageCostService::recordPurchase | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 21 | `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:260` | stock_movements | create | WeightedAverageCostService::recordPurchase | create-class write without paired reference_type/reference_id linkage |
| 22 | `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:285` | stock_levels | save | WeightedAverageCostService::recordPurchase | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 23 | `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:425` | stock_movements | create | WeightedAverageCostService::recordSale | create-class write without paired reference_type/reference_id linkage |
| 24 | `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:448` | stock_levels | save | WeightedAverageCostService::recordSale | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 25 | `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:515` | stock_levels | create | WeightedAverageCostService::recordReturn | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 26 | `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:578` | stock_movements | create | WeightedAverageCostService::recordReturn | create-class write without paired reference_type/reference_id linkage |
| 27 | `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:601` | stock_levels | save | WeightedAverageCostService::recordReturn | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 28 | `app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:767` | stock_movements | create | WeightedAverageCostService::recordCostAdjustment | create-class write without paired reference_type/reference_id linkage |
| 29 | `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1618` | stock_levels | firstOrCreate | StockAdjustmentService::getOrCreateStockLevel | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 30 | `app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:1808` | stock_movements | create | StockAdjustmentService::recordMovement | create-class write without paired reference_type/reference_id linkage |
| 31 | `app/Modules/Inventory/Domain/StockLevel.php:205` | stock_levels | save | StockLevel::recalculateReserved | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 32 | `app/Modules/POS/Application/Services/ReceiptReturnService.php:1699` | inventory_batch_stock | query_builder | ReceiptReturnService::restoreBatchAllocations | level/batch-stock write with no stock movement recorded in the enclosing function — bypasses the movement chokepoint |
| 33 | `app/Modules/POS/Application/Services/ReturnScrapWriteOffService.php:165` | stock_movements | save | ReturnScrapWriteOffService::writeOff | the stock-movement ledger is append-only; an existing movement is never mutated |
| 34 | `app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105` | journal_entries | delete | RepositoryTransferService::transfer | journal_entries rows are append-only; a correction is a reversing document, never a row delete |

## 6. M2 — baseline seed + anti-growth ratchet · DONE

### 6.1 The two-phase pinned protocol, as executed

| Step | Commit | Contents |
|---|---|---|
| pre | `19d4a7a54` | M1 round-5 P3 closures + the bootstrap-only baseline WRITER (kept out of the seed so the seed commit contains exactly one file) |
| **commit 1 — SEED** | `d2802c0b457361f1fbe2873ac4773dc8d5c32409` | `apps/api/tests/Architecture/baselines/document-per-action-baseline.json` — 34 keys, sorted, unique, line-number-free |
| **commit 2 — PINS + CHECKER** | `de067d50b` | the YAML mirror pins, the ratchet test, and the scan parse-cache |
| then | — | the M2 bridge review, whose verdict covers BOTH commits |

**Mirror pins recorded (non-authoritative — the authority is the owner-set repository variable).**
> ⚠️ **Corrected 2026-08-20.** The SHAs in this section are the REBASED equivalents. The pre-rebase
> values (pre-rebase seed `ff5642f87`, pins `9946b10b9`) were rewritten by the stale-A re-gate rebase and are no
> longer ancestors of the tip. The blob is unchanged, so this was a pointer correction, not a re-seed —
> full reasoning in **§8.8**.


```
dpa_baseline_seed_commit    = d2802c0b457361f1fbe2873ac4773dc8d5c32409
dpa_baseline_protected_blob = 1381983d463e6c546535be907d4aa1c7ca94c597
dpa_baseline_pin_tag        = ci-pin/enforcement-p1-r1
```

The pin-tag NAME is pre-allocated and non-null in the accepted candidate (gate-r5 R5-C-2).
Allocation evidence: `git tag -l 'ci-pin/enforcement-p1-*'` → 0 and
`git ls-remote --tags origin 'refs/tags/ci-pin/*'` → 0, so `n = 1`. The OWNER creates the annotated tag
at exactly A at promotion step 3; the executor cannot push tags.

### 6.2 The baseline

34 keys — the same violation set the M1 gate reproduced independently in each of its five rounds and found
byte-stable. Verified: sorted ✓, unique ✓, no key carries a line number ✓ (`116 sites → 116 unique keys`,
independently counted by the round-5 gate).

**The parse cache did not move the seed.** The cache landed in commit 2, after the seed. Proof:

```
$ php tests/Architecture/Support/write-document-per-action-baseline.php
[dpa-baseline] wrote 34 baseline entries to tests/Architecture/baselines/document-per-action-baseline.json
$ git diff --exit-code apps/api/tests/Architecture/baselines/document-per-action-baseline.json
BYTE-IDENTICAL to the seed commit
```

### 6.3 Acceptance evidence — local authority setup, then all five tamper cases

> **Line anchors in this section were produced at `de067d50b`** (the pins+checker commit). The subsequent
> fix commit added docblock lines, so the same assertions sit at different line numbers at HEAD — check the
> assertion COUNTS and messages, not the `:NNN` anchors, when reproducing.

```
$ seed_blob=$(git rev-parse d2802c0b4:apps/api/tests/Architecture/baselines/document-per-action-baseline.json)
seed_blob=1381983d463e6c546535be907d4aa1c7ca94c597
$ grep -q "$seed_blob" docs/handoff/progress/enforcement-p1.progress.yaml
MIRROR ASSERTION: seed blob == YAML mirror -> OK
$ export DPA_BASELINE_PROTECTED_BLOB="$seed_blob"

$ ./vendor/bin/phpunit tests/Architecture/DocumentPerActionBaselineRatchetTest.php
OK (2 tests, 113 assertions)          # 10.7 s
```

**1. Plant an unlinked `JournalEntry::create` in a scanned path → FAILS**

```
NEW document-per-action violations (growth — the guard is a ratchet; give the write a justifying
document reference instead of baselining it):
Tests: 2, Assertions: 113, Failures: 1.
```

**2. Revert → passes again**

```
OK (2 tests, 113 assertions)
```

**3. Delete one baseline entry → FAILS naming it as unexpected-new**

```
NEW document-per-action violations …:
  app/Console/Commands/FixOrphanedProducts.php::App\Console\Commands\FixOrphanedProducts::executeCommand::stock_levels::create#1
  (app/Console/Commands/FixOrphanedProducts.php:128 — level/batch-stock write with no stock movement
   recorded in the enclosing function — bypasses the movement chokepoint)
Tests: 2, Assertions: 111, Failures: 1.
```

**4. Add a bogus baseline entry → FAILS TWICE (stale AND anti-growth)**

A bogus entry is both un-matched by the tree *and* absent from the protected blob, so it trips two
directions, not one:

```
There were 2 failures:
STALE baseline entries (the violation is gone — remove the entry, the baseline only shrinks):
  app/Bogus.php::Bogus::method::journal_entries::create#1
The baseline has GROWN relative to the owner-pinned protected blob 1381983d463e6c546535be907d4aa1c7ca94c597.
  app/Bogus.php::Bogus::method::journal_entries::create#1
Tests: 2, Assertions: 115, Failures: 2.
```

**5. ANTI-GROWTH — new violation AND its matching baseline key, in one change → FAILS**

This is the case (a)+(b) cannot catch: the regenerated baseline matches the tree exactly, so growth and
stale are both green. The key-set comparison against the OWNER-VARIABLE-held blob is what fails:

```
$ php tests/Architecture/Support/write-document-per-action-baseline.php
[dpa-baseline] wrote 35 baseline entries …
The baseline has GROWN relative to the owner-pinned protected blob 1381983d463e6c546535be907d4aa1c7ca94c597.
Keys may only be REMOVED. Added:
  app/Console/Commands/FixOrphanedProducts.php::App\Console\Commands\FixOrphanedProducts::executeCommand::journal_entries::create#1
Tests: 2, Assertions: 115, Failures: 1.
```

**Fail-closed proofs**

```
A. YAML mirror edited to a different hash → FAILS (drift is a tamper signal, never a skip)
   The progress-YAML mirror (dpa_baseline_protected_blob) disagrees with DPA_BASELINE_PROTECTED_BLOB.
   mirror='0000…0000' variable=1381983d463e6c546535be907d4aa1c7ca94c597
   Tests: 2, Assertions: 40, Failures: 1.

B. Variable UNSET, run ISOLATED so the export survives (gate-r4 R4-H-4):
   $ (env -u DPA_BASELINE_PROTECTED_BLOB ./vendor/bin/phpunit …)
   DPA_BASELINE_PROTECTED_BLOB is unset or empty, so the anti-growth ceiling cannot be read and this
   gate FAILS CLOSED.
   Tests: 2, Assertions: 38, Failures: 1.
   EXIT CODE NON-ZERO -> fail-closed CONFIRMED
   $ echo $DPA_BASELINE_PROTECTED_BLOB   # the export survived the subshell
   1381983d463e6c546535be907d4aa1c7ca94c597

C. Variable set to a well-formed but ABSENT blob, WITH THE MIRROR SET TO THE SAME VALUE so the run
   actually reaches the git cat-file branch instead of stopping at the mirror assert → FAILS
   The protected baseline blob 0123456789abcdef0123456789abcdef01234567 could not be read
   (git cat-file exit 128).
   CI must fetch the durable pin tag before this gate runs; an unreachable object fails closed.
   …/DocumentPerActionBaselineRatchetTest.php:121
   Tests: 2, Assertions: 41, Failures: 1.
```

**Correction, recorded rather than quietly amended (M2 gate round 1, finding 1).** The first version of
case C left the mirror pointing at the REAL hash, so the run failed at the mirror-drift assert (40
assertions) and never reached `git cat-file` — the paste certified a leg that had not been exercised. The
gate caught it by assertion arithmetic (a genuine unfetchable-blob failure must reach `:121` and therefore
report 41). The corrected run above is the real evidence; the leg is the one M3's `git fetch origin tag`
step depends on.

After every case the tree was restored and re-verified: `git status --porcelain` shows no production-file
change and the baseline is byte-identical to the seed.

### 6.4 Runtime

The ratchet re-parses nothing it can avoid: ~10 s for the ratchet (two full `app/` scans) and ~4 s for the
guard, down from ~50 s before the bounded parse cache. That is what makes M3's static-only lane viable.

## 7. M3 — CI wiring + whole-package gate · DONE (handed over for the PARENT-invoked final gate)

### 7.1 The job

`.github/workflows/ci.yml` gains **`backend-dpa-guard`** — "Backend Document-per-Action Guard (DPA cementing
ratchet)". Modelled on `backend-architecture`: checkout, PHP, composer cache, install, run. No database, no
Redis, no app boot. Measured ~15 s of work (guard 4.3 s + ratchet 9.9 s).

**It carries NO `if:` guard**, so it runs on every event that actually starts this workflow. The TRUE event
graph, stated precisely (gate-r2 R2-C-2): `on:` is `push → main`, `pull_request → main|dev`, and
`workflow_dispatch` — **a direct push to `dev` never starts this workflow at all**, so the parent's ordinary
local-merge-and-push promotion produces NO run. The promotion-path guarantee is therefore the owner's
mandatory pre-promotion `workflow_dispatch` run on exactly the accepted candidate SHA (LEDGER §2 row S-14),
not this job's trigger set.

**Two decisions worth flagging:**

1. **The job runs the two DPA classes BY PATH, not `--testsuite=Architecture`.** That suite is RED at this
   base for four pre-existing, unrelated failures — `AuthLifecycleTest`,
   `ConsoleCommandTenantContextTest`, `ControllerTenantContextTest`, `QueueJobTenantContextTest` — so wiring
   the whole suite would land a gate that is red on arrival. This is the brief's own "scope the job to the
   DPA guard class + the provably-static tests, and record which were excluded and why" escape hatch, taken
   deliberately. **Excluded and why:** the four above, because they fail at base for reasons this package
   does not touch; the remaining twelve Architecture tests are excluded only because nothing in this package
   needs them and widening the job is a follow-up for whoever closes those four.
2. **Each test step asserts a NONZERO selected-test count** (`grep -qE 'OK \([1-9][0-9]* test'`). `phpunit.xml`
   sets no `failOnEmptyTestSuite`, so an empty selection exits 0 — exit code alone would prove nothing.

**Aggregate membership (gate-r1 H-9).** `backend-dpa-guard` is in the `all-checks-pass` `needs` list with the
skipped-job-semantics comment next to it: a job with no `if:` always runs and can never come back `skipped`,
so it cannot spuriously fail the aggregate — the same argument the file already makes for
`treasury-spine-pgsql`. A guard visible in CI but absent from the aggregate is not merge-gating.

```
$ grep -n 'backend-dpa-guard' .github/workflows/ci.yml
180:  backend-dpa-guard:
1281:    # backend-dpa-guard is included below on exactly the same reasoning: it
1288:    needs: [backend-lint, backend-analyse, backend-architecture, backend-dpa-guard, backend-test, backend-test-pgsql, treasury-spine-pgsql, frontend-lint, frontend-typecheck, frontend-test, pos-test, frontend-build, route-manifest-drift, types-drift]
```

> ⚠️ **Re-derived 2026-08-20 at the handover tip.** The pre-rebase paste read `:180/:1192/:1198` with
> different comment text. Two of those three lines were wrong after the rebase: dev added jobs above
> `all-checks-pass`, and the conflict resolution merged this package's rationale with dev's
> `route-manifest-drift` rationale into a single comment block, changing both the line numbers and the
> comment's first line. The conclusion never changed — the job is in the aggregate's `needs` — but this is
> the acceptance command the brief names verbatim, so the *output* has to reproduce at the SHA the owner
> digests, not merely be true.

### 7.2 EVENT-GRAPH ACCEPTANCE CHECK — what the owner's dispatch run must show

The owner's pre-promotion `workflow_dispatch` run, on **exactly the accepted candidate SHA** (which is then
merged unchanged), must show this job id executed and green:

> **`backend-dpa-guard`**

and within it, these two steps executed:

> **"Document-per-action write guard (fixture matrix + liveness)"**
> **"Document-per-action baseline ratchet (growth / stale / anti-growth)"**

Also required for that run to be meaningful: the owner must have set the repository variable
`DPA_BASELINE_PROTECTED_BLOB` to `1381983d463e6c546535be907d4aa1c7ca94c597` and pushed the annotated pin tag
`ci-pin/enforcement-p1-r1` at A **before** dispatching — otherwise the ratchet step fails closed on the unset
variable (by design) or on the unfetchable blob, and the "Fetch the durable baseline pin tag" step fails
first. That ordering is §5 item 4 steps 2–3; it is the owner's, never the executor's.

No green run on exactly the promoted SHA = **promotion blocked** (LEDGER §2 row S-14).

### 7.3 Local workflow evidence (§5 layers 1–3; no CI links from the executor, by contract)

**Layer 1 — YAML validity AND embedded-shell validity.**

`actionlint` is **NOT INSTALLED** on this machine (`which actionlint` → not found), recorded explicitly as
the contract requires. Round 3 proved that recording its absence is not enough: a YAML parse validates
document structure and **never looks inside a `run:` block**, so it certified a job whose step 6 was a bash
syntax error that aborted the whole job before the guard ever ran. The standing substitute is now mechanical.

**THE STANDING SUBSTITUTE FOR `actionlint`: `bash -n` over every `run:` block, on bytes EXTRACTED FROM THE
YAML — never retyped.** That distinction is the finding: the old Layer 2 pasted a *retyped equivalent* of the
pin-tag extraction that worked, while the literal bytes in `ci.yml` did not. Anything retyped proves only
that the transcription is valid.

```python
# extract each run: script from the workflow and syntax-check it
import subprocess, sys, tempfile, os, yaml
d = yaml.safe_load(open(sys.argv[1])); job = sys.argv[2]
for i, st in enumerate(d['jobs'][job]['steps'], 1):
    run = st.get('run')
    if run is None: continue
    with tempfile.NamedTemporaryFile('w', suffix='.sh', delete=False) as f:
        f.write(run); path = f.name          # <- the YAML's own bytes, unmodified
    p = subprocess.run(['bash', '-n', path], capture_output=True, text=True)
    print(('  [OK  ]' if p.returncode == 0 else '  [FAIL]'), f'step {i}:', st.get('name'))
```

Result at this tip:

```
$ python3 check-run-blocks.py .github/workflows/ci.yml backend-dpa-guard
  [OK  ] step 3: Get Composer cache directory
  [OK  ] step 5: Install dependencies
  [OK  ] step 6: Fetch the durable baseline pin tag
  [OK  ] step 7: Document-per-action write guard (fixture matrix + liveness)
  [OK  ] step 8: Document-per-action baseline ratchet (growth / stale / anti-growth)

  5 run: blocks checked in job "backend-dpa-guard" — 5 OK, 0 FAILED

$ python3 check-all-jobs.py .github/workflows/ci.yml      # the rest of the workflow, unbroken by this package
whole workflow: 64 run: blocks, 64 OK, 0 FAILED
```

The same check on the **previous** handover tip reproduces the defect exactly, which is what makes it a real
gate rather than a formality:

```
  [FAIL] step 6: Fetch the durable baseline pin tag
         <step>: line 1: unexpected EOF while looking for matching `''
         <step>: line 8: syntax error: unexpected end of file

  5 run: blocks checked in job "backend-dpa-guard" — 4 OK, 1 FAILED
```

Structural YAML checks, unchanged and still necessary:

```
YAML parse: OK
job id: backend-dpa-guard
carries an if: guard -> False
all-checks-pass needs includes it -> True
steps: checkout · Setup PHP · Get Composer cache directory · Cache Composer dependencies ·
       Install dependencies · Fetch the durable baseline pin tag ·
       Document-per-action write guard (fixture matrix + liveness) ·
       Document-per-action baseline ratchet (growth / stale / anti-growth)
```

**Still insufficient by contract, and stated as such:** `bash -n` proves each script parses, not that the
job triggers, that the runner resolves `${{ vars.* }}`, or that the Actions job graph behaves. Only the
owner's pre-promotion `workflow_dispatch` run on exactly A proves that (§7.2).

**Layer 2 — the exact commands the job runs, executed locally.**

```
$ ./vendor/bin/phpunit tests/Architecture/DocumentPerActionWriteGuardTest.php
OK (4 tests, 4 assertions)          # 4.3 s, 439 MB
  nonzero-test assertion (guard): PASS
$ ./vendor/bin/phpunit tests/Architecture/DocumentPerActionBaselineRatchetTest.php
OK (2 tests, 113 assertions)        # 9.9 s, 443 MB
  nonzero-test assertion (ratchet): PASS

# the pin-tag step EXECUTED from the YAML's own bytes (fetch stubbed), not a retyped equivalent —
# this is what round 3 required, because the retyped version passed while the real bytes did not:
exit: 0
Fetching pin tag: ci-pin/enforcement-p1-r1
(would run) git fetch --no-tags origin refs/tags/ci-pin/enforcement-p1-r1:refs/tags/ci-pin/enforcement-p1-r1
```

**Layer 3 — job-graph reasoning.** The job has no `needs`, so it starts immediately and in parallel with the
other static jobs. It has no `if:`, so on every event that starts the workflow it RUNS — never `skipped` —
which is exactly what makes it safe to add to the `all-checks-pass` `needs` list (a `skipped` dependency
would fail/skip that aggregate spuriously). `all-checks-pass` itself keeps its own `if:` (workflow_dispatch,
PR→main, or push→main), unchanged by this package. On PR→dev the aggregate is skipped as before, while
`backend-dpa-guard` still runs and reports independently — the guard is live on the cheap-PR lane even where
the aggregate is not.

### 7.4 Scope proof and path allowlist

**Re-derived VERBATIM at `3d7f55457a12d045dd3df9eb57e1f9e4acd65b88` against the NEW base `67d18746a`**, after this round's last tracked commit.

```
$ git rev-parse HEAD
3d7f55457a12d045dd3df9eb57e1f9e4acd65b88

$ git diff --stat 67d18746a5e1c565c1f33c0394ae518af0e1cbf0..HEAD
 .github/workflows/ci.yml                           |   98 +-
 .../DocumentPerActionBaselineRatchetTest.php       |  257 ++
 .../FixtureBatchStockWrites.php                    |  244 ++
 .../FixtureJournalEntryWrites.php                  |  203 ++
 .../FixtureLinkageProofWrites.php                  |  367 +++
 .../FixtureRelationAndInheritanceWrites.php        |   95 +
 .../FixtureStockLevelWrites.php                    |  292 +++
 .../FixtureStockMovementWrites.php                 |  161 ++
 .../FixtureTopLevelLinkedWrite.php                 |   23 +
 .../FixtureTopLevelRouteWrites.php                 |   43 +
 .../DocumentPerActionWriteGuardTest.php            |  393 ++++
 .../Support/DocumentPerActionWriteScanner.php      | 2471 ++++++++++++++++++++
 .../Support/write-document-per-action-baseline.php |   53 +
 .../baselines/document-per-action-baseline.json    |   36 +
 docs/handoff/progress/enforcement-p1.progress.yaml |  102 +-
 15 files changed, 4814 insertions(+), 24 deletions(-)

$ path allowlist check
0 violations across 15 files

$ control-file preflight (brief §5 step 1(iii))
none touched: PASS

$ production code
none: PASS

$ workflow permissions grant check (gate-r6 R6-H-5)
no permissions grant added: PASS

$ git merge-base --is-ancestor 67d18746a HEAD
67d18746a is an ancestor of the tip: PASS (clean fast-forward)
```

### 7.6 Whole-package evidence rerun (every lens's evidence, over the integrated branch)

```
--- LOCAL AUTHORITY SETUP (gate-r4 R4-H-4) ---
seed_blob=1381983d463e6c546535be907d4aa1c7ca94c597
assert seed_blob == YAML mirror: OK
export DPA_BASELINE_PROTECTED_BLOB="$seed_blob"

--- guard ---     OK (4 tests, 4 assertions)      4.7 s, 439 MB
--- ratchet ---   OK (2 tests, 113 assertions)   10.6 s, 443 MB
--- phpstan level 8 on every new/changed path ---  [OK] No errors
--- pint ---      {"result":"pass"}

--- unset-variable negative case, ISOLATED so the export survives ---
  unset -> non-zero exit: fail-closed CONFIRMED
  export survived: DPA_BASELINE_PROTECTED_BLOB=1381983d463e6c546535be907d4aa1c7ca94c597
```

**PHPUnit is reported as SCOPED, never green-by-omission:** only the two DPA classes were run, by path.
`./scripts/preflight.sh` was NOT run in full — `PREFLIGHT_SCOPE=full` is forbidden on this machine and the
full PHPUnit suite crashes the owner's laptop (house rule). The non-PHPUnit stages that this package can
affect were each run directly and pass: PHPStan level 8 on every new/changed path (0 errors), Pint on the
same paths (`pass`). Nothing in this package touches `apps/web`, `apps/pos`, TypeScript, ESLint or
migrations, so those stages are unaffected by construction.

### 7.7 Known-red at base, NOT caused by this package

`./vendor/bin/phpunit --testsuite=Architecture` is red at `41fb478c2` and stays red at the tip, with the same
four failures, none of them touched by this package:

```
1) Tests\Architecture\AuthLifecycleTest::test_every_auth_sanctum_route_group_includes_set_permissions_team
2) Tests\Architecture\ConsoleCommandTenantContextTest::test_every_concrete_artisan_command_is_tenant_classified
3) Tests\Architecture\ControllerTenantContextTest::test_every_controller_method_is_classified
4) Tests\Architecture\QueueJobTenantContextTest::test_every_concrete_queue_job_is_tenant_classified
```

This is why `backend-dpa-guard` runs the two DPA classes by path (§7.1 decision 1).

### 7.5 M2 round-2 P3 notes absorbed here

| Note | Absorbed |
|---|---|
| 1 — a baseline key is a SLOT, not a WRITE (remove a baselined write, add a different one in the same bucket → CI-green) | New **blind spot H** in the scanner docblock, naming it as the sharpest limit of the key-set design |
| 2 — the YAML `m2_evidence.tamper_cases` field still understated case 4 | Corrected in the progress YAML (the machine-readable record M3/P3-M0 consumers parse) |
| 3 — §6.3 line anchors were pre-fix-commit with no commit pin | §6.3 now states the pastes were produced at `de067d50b` and to check counts, not anchors |
| 4 — the memory premise was wrong (phpunit.xml already pins 2G) | §8.4 corrected; M3 still pins `memory_limit=1G` explicitly on the job |
| 5 — blind spot C lacked a pointer to the unfixable-red consequence, and G was inserted out of order | C now points at the RE-PIN TRIGGER block; the list runs A–H in order |

## 8. Operational owes

### 8.1 ⚠️ NAMED RE-PIN TRIGGER — the ratchet can hard-block a legitimate remediation

Baseline keys carry a positional ordinal within (file, class, function, table, mechanism), and the
anti-growth direction makes a RENUMBERED key an ADDED key — which only the OWNER can authorise (a new
repository-variable value plus a freshly allocated pin tag). Consequence, created by M2 and disclosed here:

> A remediation that inserts an earlier same-bucket write renumbers the surviving violation. Example: fixing
> `OpeningBalancePostingService::post` by adding a properly linked `StockMovement::create` above the unlinked
> one at `:117` renumbers the survivor from `create#1` to `create#2`. CI goes red, and **no contributor-side
> edit can make it green** — removing the stale entry trips the growth direction instead. The guard would be
> blocking the remediation program it exists to protect, on a change that strictly improves the tree.

The resolution is an owner re-pin (variable + tag together, §5 item 4 steps 2–3), exactly as for a legitimate
shrink. **Parent action requested:** treat "a remediation renumbered a baseline key" as a first-class re-pin
trigger in the promotion runbook, so the first occurrence is not read as a tamper signal. Making ordinals
violation-relative would remove one half of the trigger (linked writes inserted) but not the other
(violations reordered); that is a design change and is NOT proposed here.

### 8.2 Re-pin red window (inherent to the ruled sequencing, not a defect)

The owner sets the repository variable at promotion step 3; the parent updates the YAML mirror only in the
POST-promotion admin commit (gate-r3 R3-C-2). Between those two events every workflow run sees
mirror ≠ variable and fails closed as a tamper signal — including branches cut before the admin commit,
which stay red until they rebase past it. Expected, and recorded so the first re-pin is not mistaken for an
attack.

### 8.3 The detector is candidate-deletable

Nothing inside the test tree asserts that `DocumentPerActionBaselineRatchetTest.php` exists, so deleting it
(or it plus the baseline) leaves `--testsuite=Architecture` green with no ratchet.

**But the file-deletion half is already closed by how M3 wired the job, and this note previously understated
that (final gate round 2).** `backend-dpa-guard` does not run the suite: it invokes the class **by path** and
then asserts a nonzero selected-test count under `set -o pipefail`
(`./vendor/bin/phpunit tests/Architecture/DocumentPerActionBaselineRatchetTest.php` followed by
`grep -qE 'OK \([1-9][0-9]* test'`). Deleting the file fails that step on **both** legs — phpunit errors on
the missing path, and the grep finds no `OK (N tests)` line. The same is true of the guard class.

**The residual that actually survives is the `ci.yml` half:** a candidate that deletes or neuters the JOB
removes the invocation, and that is exactly the residual the brief's §6 F-8 discloses for `ci.yml`, with the
same mitigations — the scope allowlist, the milestone gates reviewing every workflow diff, the owner's
pre-promotion dispatch run, the step-4a post-dispatch tag/variable re-verification, and the owner
workflow-authority check. Scope the mitigation work to that half. Disclosed in the ratchet docblock too.

### 8.4 Memory ceiling for the M3 job

Measured peak: 443 MB (ratchet) / 439 MB (guard). **Correction to the first version of this note (M2 gate
round 2, finding 4):** a phpunit invocation does NOT inherit the runner default — `apps/api/phpunit.xml:34`
already pins `<ini name="memory_limit" value="2G"/>`, so the real headroom is ~78 %, not ~13 %. The 512 MB
figure in the scanner docblock refers to a bare `php` CLI run of the census script, which is how the
unbounded cache was caught. M3 still pins `memory_limit=1G` on the job's PHP setup so the CI ceiling is
explicit and does not depend on reading phpunit.xml, but the premise "the runner default applies" was wrong
and is corrected here rather than left to mislead.

### 8.5 ⚠️ PARENT TICKET REQUESTED — the live `journal_entries` row delete

`RepositoryTransferService.php:105` (§3.8, census #34). Guard-only package; the site is baselined so it
cannot grow, and a DPA remediation lane is requested. The M1 round-5 gate independently confirmed it is a
real hard delete and that `JournalEntry` carries no `SoftDeletes`.

### 8.6 ⚠️ PARENT TICKET REQUESTED — scan root excludes `database/**`

Blind spot G. Live four-table writes exist in `CoffeeShopSeeder`, `DemoPharmacySeeder`,
`ParapharmacySeeder`, `StockLevelSeeder` and are neither baselined nor guarded. Defensible for provisioning
surfaces, but a future DATA-BACKFILL migration writing `journal_entries` would be outside the guard by
construction. A decision, not a defect.

### 8.7 Ledger rows

Ledger rows, not independent statuses: `docs/handoff/LEDGER.md` §2 row **S-14** (owner-executed
PRE-promotion `workflow_dispatch` on exactly the accepted SHA) and row **S-15** (the −19.000 demo-tenant
disposition). This handback carries no status of its own for either.


---

## 8.8 ⚠️ STALE-A RE-GATE — rebased onto dev `67d18746a` (2026-08-20)

Local dev advanced while this package sat at the final gate (es-A0, ui-wave0, DN consolidation, a
reconciliation commit, three red-gate reconciliation commits). The branch was **rebased**, 25 commits
replayed.

**One conflict, in `ci.yml`'s `all-checks-pass` `needs`:** dev had added `route-manifest-drift`, this package
adds `backend-dpa-guard`. Resolved by keeping **both**, with both rationales merged into one comment — the
skipped-job-semantics argument is identical for the two jobs (neither carries an `if:`, so neither can come
back `skipped`). Verified afterwards that every `needs` entry resolves to a real job.

**THE CENSUS WAS RE-DERIVED AT THE REBASED TIP AND IS UNCHANGED:** 34 violations / 67 linked /
15 not-applicable, 116 sites, and the violation key set is **identical to the checked-in baseline in both
directions** (0 added, 0 removed). Therefore **no seed revision and no re-pin**: seed commit `d2802c0b4` and
blob `1381983d463e6c546535be907d4aa1c7ca94c597` stand, and the pre-allocated tag `ci-pin/enforcement-p1-r1`
is re-verified free (`git ls-remote --tags origin 'ci-pin/*'` empty, `git tag -l 'ci-pin/*'` empty), so the
same name carries forward.

**One consequence of rebasing that the sweep caught: the seed POINTER, not the seed.** The rebase rewrote
every commit, so the recorded `dpa_baseline_seed_commit: ff5642f87` was no longer an ancestor of the tip — it
survived only as a dangling object. The acceptance evidence's LOCAL AUTHORITY SETUP runs
`git rev-parse <dpa_baseline_seed_commit>:<baseline-path>`, so that command, and every later consumer doing
the same (P3-M0 included), would have broken. Re-pointed to `d2802c0b457361f1fbe2873ac4773dc8d5c32409`, the
replayed equivalent — verified a *true* equivalent, not merely a same-named commit: it contains **exactly one
file** (the baseline), it **is** an ancestor of the tip, and it yields the **same blob**
`1381983d463e6c546535be907d4aa1c7ca94c597`. Because the blob is unchanged this is a **pointer correction, not
a re-seed and not a re-pin** — the protected blob, the pre-allocated tag, and the owner-set variable all
stand. The two-commit topology survives the rebase intact and adjacent: seed `d2802c0b4`, then pins
`de067d50b`.

**Targeted verification of the new code, rather than trusting the aggregate count:**

| Incoming change | Verified |
|---|---|
| `UninvoicedDeliveryNoteService` — **dev modified one of my baselined violators** | It now holds TWO `JournalEntry::create` calls. `:530` is still inside `generateYearEndAdjustment` and still the baselined violation (key unchanged, `#11`). `:591` is dev's **new** `generateReversalEntry` and is correctly classified **LINKED** — it carries `source_type` + `source_id`. Neither the key set nor the count moves |
| DN billing claim service + its two migrations | Write `documents` and `delivery_note_billing_marks` — **not** contract tables (grepped the whole service and both migrations). Out of contract |
| es-A0 fiscal changes (`ReceiptHashService`, quarantine resolution, converters) | No writes to any of the four tables; the `->update(...)` calls found are on `Document`/`invoice`/`line`/`allocation` models, which the scanner correctly does not classify |
| T21 count-correction listener + `StockAdjustmentService` | Classifications unchanged — `postCountCorrection`/`receive`/`issue`/`transfer` still `linked`, the two reservation-only sites still `not_applicable` |

**One real detection gap found and disclosed (blind spot D).** Dev's `DeliveryNoteBillingClaimService` runs
raw SQL through a constructor-injected `ConnectionInterface` (`$this->db->update('UPDATE …')`), and the
raw-SQL detector only matches the `DB` facade. It changes no classification today (the SQL targets
`documents`), but an injected-connection raw write against a contract table **would be invisible**. Named in
blind spot D rather than silently fixed: closing it is a scanner behaviour change, which is a re-seed event,
and the final gate is not the place to take one. **Parent follow-up requested.**

**Post-rebase POINTER SWEEP (final gate round 5).** The first pass after the rebase repaired one pointer
(the seed) and left five more dangling — the append-vs-substitute class again, inside the very document that
catalogues it. Rather than chase the instances the gate listed, the class is now closed mechanically: a
check greps this handback and the progress YAML for **every 7–40 hex token**, resolves each as a commit
object, and asserts it is an **ancestor of the tip** unless its line explicitly marks it historical.

```
before: 23 non-ancestor commit tokens; 15 DANGLING (unmarked)
after :  5 non-ancestor commit tokens;  0 DANGLING (unmarked)
```

All 15 were substituted to their replayed equivalents, using an old→new map built by matching commit
subjects across the rebase — every new SHA verified an ancestor, and the seed additionally verified
same-blob:

<!-- sha-sweep:historical-block-start (the "pre-rebase" column below is intentionally unreachable) -->

| artifact | pre-rebase | replayed |
|---|---|---|
| seed commit (§6.1, §6.3, YAML pin + `m2_evidence.seed_commit`) | `ff5642f87` | `d2802c0b4` |
| pins commit (§6.1, `m2_evidence.pins_commit`) | `9946b10b9` | `de067d50b` |
| `milestones[M1].commit` | `c11d146e7` | `8d802630b` |
| `milestones[M2].commit` | `441dad0fe` | `9a2d7792e` |
| Phase 4.2.1 (§3.7e, §6.1) | `1b1f99e00` | `19d4a7a54` |
| Phase 4.1.10 | `0968f3606` | `fb5f12375` |
| Phase 4.3.7 (§7.5 round-3 disposition) | `f83eeb3c0` | `1d621f068` |

<!-- sha-sweep:historical-block-end -->

The five surviving non-ancestor tokens are deliberately historical and marked as such: §8.8's own
explanation, the YAML seed re-point comment, and the `accepted_sha` values recorded for final-gate rounds
1–3. **`§6.3`'s LOCAL AUTHORITY SETUP command now resolves** — it was the brief-mandated command that would
have returned `fatal: bad object` after promotion.

**Evidence rerun at the rebased tip:** guard `OK (4 tests, 4 assertions)` · ratchet
`OK (2 tests, 113 assertions)` · PHPStan level 8 `[OK] No errors` · Pint `pass` · unset-variable isolated
negative case exits non-zero. **`bash -n` sweep re-run because dev changed `ci.yml` underneath this branch:**
5/5 `run:` blocks in `backend-dpa-guard`, **67/67 across the whole workflow** (dev added three).

**⚠️ Parent action required before the next final-gate run:** the dispatch receipt at
`~/.claude/projects/…/dispatch-receipts/enforcement-p1.receipt.yaml` still names `base_sha: 41fb478c2`. The
final bridge takes the review range's lower endpoint from the RECEIPT and field-checks this progress YAML
against it, so a receipt still pointing at the old base will fail that check by design. It must be updated
to `67d18746a5e1c565c1f33c0394ae518af0e1cbf0`.

## 9. Completion language

P1's milestones M0, M1 and M2 have been gated and ACCEPTED by the harness (M1 at round 5, M2 at round 2).
**M3 is complete as implementation and is handed over at `status: review` for the PARENT-invoked final
whole-package gate** — an executor-run register for M3 would not be acceptance evidence, and none was
produced. This package is therefore NOT declared complete here, and no claim whatsoever is made about the
enforcement PROGRAM: P2 and P3 dispatch independently and the parent tracks the whole.

**Handover state:** `git status --porcelain` shows EXACTLY ONE entry —
`?? docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md` — as the seal requires (gate-r9 R9-H-2).

**⚠️ WHERE THE MID-WAVE REGISTERS ARE.** The seven executor-run registers are NOT in the worktree, because
they can be neither committed into A (brief §5 step 5: registers never land in A) nor left untracked (they
would be a second dirty entry and the bridge rejects the handover). They were moved OUTSIDE the repo to:

```
~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/
    M1-round1.md  M1-round2.md  M1-round3.md  M1-round4.md  M1-round5.md
    M2-round1.md  M2-round2.md
```

The parent lands them in the closing commit C under `docs/handoff/reviews/enforcement-p1/**`, which is
inside this package's closing set. The progress YAML's `verdict:` fields point at those repo-relative paths,
which resolve once C exists.

**Gate history across the package:**

| Milestone | Rounds | Verdict |
|---|---|---|
| M0 | setup-only, no bridge review by design | passed |
| M1 | 5 (4 fix rounds) | ACCEPT at round 5 — 0 P1 / 0 P2 |
| M2 | 2 (1 fix round) | ACCEPT at round 2 — 0 P1 / 0 P2 |
| M3 | round 1 → CHANGES-REQUIRED (documentation-only); round 2 → CHANGES-REQUIRED (record-honesty); round 3 → CHANGES-REQUIRED with a **CRITICAL** (the CI job was a bash syntax error); fix rounds 1–3 applied; re-handed over | PARENT-invoked |

**Final-gate round 1 disposition (all four findings, no code-behaviour change, no re-seed, no re-pin, no
baseline edit):**

| Finding | Disposition |
|---|---|
| [Important] §4's "COMPLETE list" was incomplete (missing #10, #15, #29), miscounted (17 vs a table of 15 rows expanding to 27), double-counted the 9 WAC rows on both sides, and misnumbered two entries | FIXED — §4 now carries an explicit, checkable 13 + 21 = 34 partition; the three omitted violations are added WITH dispositions; the WAC cluster is declared to belong to the MAPPED side; `RepositoryTransferService.php:105` is #34 and `ReturnScrapWriteOffService.php:165` is #33 everywhere. The correction notice records the error's lineage honestly: "17" was never derived from the table — it entered as the closure statement of the M2 round-2 P3-6 finding and was carried forward unverified through three later rounds that read the claim instead of recomputing it |
| [Minor] §3.5 "114 pinned cells" stale | FIXED — **115**, counted three independent ways (grep, `count(fixtureMatrix())`, unique-key set) plus a per-round breakdown that sums to 115 |
| [Minor] §3.1 fixture-class count understated | FIXED — **8 files**: six declaring 8 classes, plus two that declare NO class by design (the class-less top-level/route-closure fixtures; a class would defeat what they pin) |
| [Minor] `the_rule_surface_agrees_with_the_pinned_classifications()` dead variable + docblock overstating the method | FIXED — `$expectationsByCell` removed; the docblock now states it checks ONE direction and names `every_cell_with_a_linked_form_pins_a_negative_case()` as the enforcer of the converse |

**Final-gate round 2 disposition (record-honesty only; one tracked commit, the YAML fix-round record):**

| Finding | Disposition |
|---|---|
| [Important] §7.4's scope proof did not reproduce at the tip it certified — it attested the pre-fix-round tree (4761+/18− across 8 collapsed stat lines vs the tip's 4786+/24− across 15). The reviewer independently confirmed the conclusion held, but with the reviewer's numbers, not the handback's | FIXED — §7.4 is re-derived VERBATIM, and deliberately derived **after** this round's only tracked commit so it certifies its own tip. Deriving it first would have shipped a proof stale by exactly that commit — reproducing the finding while claiming to fix it. The two moved stat lines are named and both are this round's own work |
| [Minor] §3.7 gate-history row 5 still read *(pending)* though M1 accepted at round 5 | FIXED — row 5 filled (`M1-round5.md` · ACCEPT · 0×P1/0×P2), and **§3.7e added** so the row's "closed in Phase 4.2.1 and §3.7e" pointer resolves instead of dangling. That section documents all seven round-5 P3 notes and their dispositions |
| [Minor] §8.3 understated its own mitigation | FIXED — the job invokes the class BY PATH and asserts a nonzero selected-test count under `set -o pipefail`, so deleting the file fails both legs; only the `ci.yml` half is the live F-8 residual, and the mitigation work should be scoped to it |

**Final-gate round 3 disposition (1 CRITICAL, 1 IMPORTANT, 1 MINOR):**

| Finding | Disposition |
|---|---|
| **[CRITICAL]** `ci.yml`'s "Fetch the durable baseline pin tag" step was a **bash syntax error** — a `tr -d` argument with an unterminated single quote swallowed the closing paren, the closing quote and the rest of the script. `backend-dpa-guard` aborted at step 6 and **never ran the guard or the ratchet**; with no `if:` and a place in `all-checks-pass` `needs`, it was red on every triggering event, so the owner's mandatory pre-promotion `workflow_dispatch` on A would have been red and §5 item 4 step 3 would have **blocked promotion**. The package as handed over could not be promoted. | FIXED in `1d621f068` — `tr -d "\"'"`. Proven on YAML-extracted bytes: 5/5 `run:` blocks pass `bash -n` (was 4/5), the extracted step **executes** and yields `ci-pin/enforcement-p1-r1`, and all 64 `run:` blocks in the whole workflow pass |
| **[IMPORTANT]** Layer-1 evidence was a YAML parse, which validates structure and never the embedded shell; Layer 2 then pasted a **retyped equivalent** of the extraction that worked while the literal bytes did not — the "each part verified true, the conjunction never checked" failure the brief's own r4/R0-3 note names, in its second venue | FIXED — §7.3 Layer 1 now carries a mechanical `bash -n` over **every** `run:` block, extracted from the YAML and never retyped, with the script inlined so it is reproducible, the output pasted, and the **previous tip's failing output** shown so the check is demonstrably not a formality. Declared the standing substitute for the absent `actionlint`. Layer 2's retyped paste is replaced by executing the step's own bytes |
| **[MINOR]** M3's `commit:`/`updated:` were two commits behind the handover tip | FIXED — they name `1d621f068`, the last CODE commit, with the constraint stated inline: a YAML commit cannot contain its own SHA, so the authoritative tip is this header's Final SHA |

**Honest note on how this got through.** Three prior gates and my own §7.3 all "verified" this job while the
job could not run. Every individual claim was true — the YAML parsed, the job had no `if:`, it was in the
aggregate's `needs`, the commands worked when typed by hand — and none of them touched the one thing that
mattered. That is exactly the conjunction failure the brief warned about, and it is why the fix is a
mechanical check on the artifact's own bytes rather than a promise to be more careful.

**Post-fix verification:** guard `OK (4 tests, 4 assertions)` · ratchet `OK (2 tests, 113 assertions)` ·
PHPStan level 8 `[OK] No errors` · Pint `pass` · census re-derived independently at
**34 violations / 67 linked / 15 not_applicable (116 sites)** — unchanged, and the ratchet passing is itself
the proof that the census equals the baseline exactly in both directions. Baseline file unchanged
(`git diff --exit-code` clean), pins unchanged.

Across nine executor-run gate rounds the violation census never moved: **34 / 67 / 15**, with the violation
list byte-identical from round 3 onward. Every hole the gates found was a forward-guarantee leak with zero
live instances — verified against the tree by the gate each time — so the guard was hardened four times
without a single reclassification of live code.

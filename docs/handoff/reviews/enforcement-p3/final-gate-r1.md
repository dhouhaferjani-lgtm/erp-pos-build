# FINAL-GATE REGISTER — package p3 M3 round 1 attempt 1
accepted_sha: 390275a901ed242aca2df36463c8180fe39d66e9
base_sha: 0ca7bbb091863b160383d630e6062c820cada9b1   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p3.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p3.receipt.yaml
snapshot: detached-worktree @ 390275a901ed242aca2df36463c8180fe39d66e9 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p3-registers/HANDBACK-enforcement-p3-2026-08-21-r1.md=72bb148ef4eb79a0a679156f05b956e9912fe9589e51e01560b86b52af89df18   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 7c298d5fbe0b35d9cd3970f57d3c49c03fa219a6eb44976dbe1d478c7da6a811
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:treasury=408258d068f2ef640b701533f985f0e7ab378a94a0583bab4f808e0727b35d5d
control_sha256: lens:fiscal-pos=419ac2083583210ff007956a00cdfded8de009cc763cdd2563411c24372b5a82
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 5
---
I have completed the review. Register follows.

---

# ADVERSARIAL FINAL-GATE REGISTER — enforcement-p3 · M3 (whole-package gate) · round 1

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.P405nFYFQR/snap`, detached at **A = `390275a901ed242aca2df36463c8180fe39d66e9`**; `git status --porcelain` empty; range `0ca7bbb091863b160383d630e6062c820cada9b1..A` = **17 commits, 21 files, +2848/−21**.
**Handback reviewed:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p3-registers/HANDBACK-enforcement-p3-2026-08-21-r1.md` — 416 lines, `sha256 72bb148ef4eb79a0a679156f05b956e9912fe9589e51e01560b86b52af89df18`.
**Amending authority: none.** Read-only; no file in either tree was modified.

## Control / field checks performed

| Check | Result |
|---|---|
| `control_manifest` pin vs disk | `sha256 7c298d5f…6a811` — **matches** the manifest at `/Users/houssamr/Projects/syneriva/apps/erp/docs/handoff/enforcement-control-manifest.yaml` byte-for-byte |
| Manifest `p3` block vs candidate YAML | `progress_path`, `final_milestone: M3`, `lenses: treasury,fiscal-pos,stock-gl-interaction,inventory-costing`, `max_fix_rounds: 5` — **all four converge** |
| YAML projection | `base_sha` == range base ✅ · exactly one final milestone ✅ · M3 `status: review` ✅ · `fix_rounds 0 ≤ 5` ✅ · `p3_closing_pin_tag: ci-pin/enforcement-p3-r1` non-null ✅ |
| Control-file preflight | `scripts/adversarial-review*.sh`, brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/**` — **0 hits in range**; no `*control-manifest*` surrogate added |
| Scope allowlist | re-derived independently: `git diff --name-only base..A | grep -v <allowlist>` → **empty**. `.github/**` → 0 files, so the S-14 dispatch leg genuinely does not apply |
| Forbidden edits | `ProvisioningRequiredPurposesV1`, `SystemAccountPurpose`, `ChartOfAccountsService`, all three frozen seeders — **0 hits** |
| M3 commit content | `390275a90` touches **only** the progress YAML (49+/3−) — no implementation, per gate-r1 H-8 ✅ |

**Limit stated honestly:** the snapshot carries no `apps/api/vendor`, so I could not re-execute PHPUnit/PHPStan. Test *content* was read directly and the numeric claims were cross-checked against the two mid-wave registers' independent re-runs; the counts are not independently re-measured by me.

## Content-derived evidence of table inspection (row counts + first/last row keys)

**Handback tables**

| Table | Rows | First row key | Last row key |
|---|---|---|---|
| §2 per-milestone summary | **4** | `M0` — preconditions, 28 machine checks | `M3` — whole-package gate, `review` |
| §3.6 PHPStan-on-`tests/` observation | **4** | `tests/Feature/Accounting/ChokepointUnbalancedGuardTest.php` (N=4) | `tests/Feature/Accounting/SeededChartManifestRequiredPurposeCompletenessTest.php` (N=0) |
| §4 bucketed path list | **21** | `[PROD-ALLOWED] apps/api/app/Modules/Accounting/Application/Services/AccountingService.php` | `[DOCS-HANDOFF] docs/handoff/reviews/enforcement-p3/M2-round2.md` |
| §6 deviations — M0 / M1 / M2 | **1 / 6 / 5** | `M0-D1` / `M1-D1` / `M2-D1` | `M0-D1` / **`M1-D6`** / `M2-D5` |
| §7.1 M1 findings (abridged) | **11** | `R-1` live `journal_entries` DELETE | `R-11` synchronous projection swallow |
| §7.3 M2 findings | **2** | `D-1` unmodeled fourth gate shape | `D-2` `requiredPurposes()` 14-of-28 subset |

**M1 census (`docs/handoff/reviews/enforcement-p3/M1-census.md`) — the write-path classification**

| Table | Rows | First row key | Last row key |
|---|---|---|---|
| §0 executive result | **14** | `journal_entries creation sites found (all patterns)` = 55 raw → 53 real | `net production behaviour change to existing catch sites` = ZERO |
| §1(iii) `Posted`-writer structural proof | **7** (#0–#6) | `#0 GeneralLedgerService.php:3450` — the chokepoint | `#6 ResetOpeningBalanceService.php:163` |
| §2.1 self-posting creators → class (b) | **23** (#1–#23) | `#1 :434 createCustomerAdvanceJournalEntry` | `#23 :4953 reverseInventoryWriteOffEntry` |
| §2.1 conditional-post sub-table | **2** | `:1583 createPaymentToleranceJournalEntry` | `:1711 clearCustomerAdvanceToReceivable` |
| §2.2 draft-only creators → class (a) | **17** (#24–#40) | `#24 :3563 createPOSPaymentEntry` | `#40 :353 createPaymentEntry` |
| §2.3 non-`GeneralLedgerService` creators | **11** (#41–#51) | `#41 JournalEntryController.php:97 store` | `#51 DemoPharmacySeeder.php:872 seedTunisiaBalances` |
| §5.3 attempt-3 type split | **2** | `UnbalancedJournalEntryException` | `UnbalancedJournalEntryPostException` |
| §5.5 catcher census | **23** | `#1 PostShiftCashVarianceAdjustment.php:184`/`:210` | `#23 Console/TenantScopedCommand.php:333` |
| §6 reported findings | **11** | `R-1` | **`R-6`** (listed last, out of numeric order) |
| §7 deviations | **8** | `M1-D1` | **`M1-D8`** |

**M2 reconciliation (`docs/handoff/reviews/enforcement-p3/M2-reconciliation.md`) — the purpose reconciliation**

| Table | Rows | First row key | Last row key |
|---|---|---|---|
| §1 reconciliation | **8** | `#1 TreasuryReceiptBridge.php:446-455` (`postCashRoundingEntry`) | `#8 Console/Commands/BackfillTolerancePurposesCommand.php` |
| §9 deviations | **5** | `M2-D1` | `M2-D5` |

## Independent verification (not findings — I re-derived these rather than accept them)

- **The class-(c) enumeration is sound.** I re-ran the decisive structural sweep myself: `JournalEntryStatus::Posted` *assignments* over `app/` yield exactly the seven sites the census lists (`AccountingService:427/579/926`, `AccountingOpeningService:268`, `OpeningBalancePostingService:238`, `ResetOpeningBalanceService:163`, chokepoint `GeneralLedgerService:3450`), and a raw-string `'status' => 'posted'` sweep over `app/` + `database/` returns **zero**. The "no draft can seal outside the chokepoint" conclusion holds.
- **R-4 is exactly as described, and correctly left open.** `AccountingService.php:902` resolves `$balanceAssertable` from `residualPlan()`, gating both the pre-flight refusal (`:905`) and `assertLegsBalance` (`:952`); the create at `:926` writes `status: Posted` **with `chain_sequence` and `previous_hash`** — a genuinely chained, one-legged entry for lineless documents. The census does not soften this ("accepted as the lesser evil, not claimed to be balanced"), the parent ruling is quoted verbatim in three places, and handback §8 leads with it. Carry-forward handled correctly.
- **The listener fix is fail-closed, not just loud.** `RepositoryAdjustmentService::post()` wraps document + JE + movement in one `DB::transaction` (`:114`), so a chokepoint refusal rolls back everything — no cash-moved-without-GL half-write (stock-gl lens dimension 8). `refuse()`'s signature is `(…, string $level = 'warning')` at `:520`, so the named `level: 'error'` argument is valid, and the new clause sits after two non-ancestor domain exceptions and before `catch (Throwable)` — reachable and unshadowed.
- **D-2's arithmetic is exact.** I computed it: the manifest's REQUIRED partition is **28**, `SystemAccountPurpose::requiredPurposes()` is **14**, `comm -13` is **empty** (nothing outside the manifest set) and `comm -23` yields precisely the fourteen names D-2 lists, `goods_received_not_invoiced` … `voucher_liability`. `assertConforms()` pins `28/1/4/10` at `:249-254`, so M2-D2's correction of the brief's stale 41-case prose is right.
- **The CI wiring is real.** `treasury-spine-pgsql` runs `./vendor/bin/phpunit tests/Feature/Accounting` (`ci.yml:1111-1112`) under an `if:` that includes `github.base_ref == 'dev'` (`:1012`), and the job is in `all-checks-pass` `needs` (`:1443`). A new class in that directory is picked up by an unmodified workflow — the no-`.github/**` claim and the `pre_promotion_ci_dispatch: null` disposition are both correct.
- **Test quality.** `SeededChartManifestRequiredPurposeCompletenessTest` uses `RefreshDatabase` + real models, seeds real charts, pins the legacy arm explicitly, and both tamper cases assert on the *shared* assertion body rather than a parallel copy. `ShiftCashVarianceAdjustmentTest::test_an_unbalanced_adjustment_entry_is_refused_under_its_own_reason` manufactures the imbalance with real Eloquent machinery, asserts the new reason, asserts absence of the generic `"reason":"exception"` bucket, **and** asserts zero `repository_adjustment` entries — non-vacuous.
- **Rule 19/20.** No float, no `parseFloat`, no bare no-arg `getScale()` introduced anywhere in range; `forChokepoint()` takes `@param numeric-string` and only formats. No projection `apply()`, no new queue, no migration, no user-facing string.

**Lens conclusions.** *treasury* — applied in full; precision, module boundaries, transaction atomicity and the balance-sign convention all hold; the only production behaviour delta is one added catch clause, proven fail-closed. *fiscal-pos* — applied; the refusal is raised at `:3418`, upstream of the `chain_sequence`/`previous_hash`/Posted write at `:3450`, so no sealed byte, device-authored fact, or projection write changes; R-11's projection swallow is correctly framed as projection error discipline and correctly deferred by ruling. *stock-gl-interaction* — applied; both sides traced: the only stock↔GL catcher in the blast radius (`BatchWriteOffService:122`) is immune by type after the split, and `ReverseWriteOffService` posts through `postEntry` → chokepoint, so no stock movement in range moves without its GL consequence. *inventory-costing* — applied and **N/A on substance**: no WAC, movement, batch/FEFO, or opening-balance code is touched; I confirmed the range contains no `WeightedAverageCostService`, `StockMovement`, `StockLevel` or `BatchStock` write.

---

## Findings

**1 — Important — `docs/handoff/reviews/enforcement-p3/M1-census.md:335-347` (§5.1) — CONFIRMED**
§5.1 states the delivered change is that "the chokepoint now raises **`UnbalancedJournalEntryException::forChokepoint(...)`** … **The delivered change is exactly this and nothing more.**" That is false at A in two ways: the chokepoint raises `UnbalancedJournalEntryPostException::forChokepoint(...)` (`GeneralLedgerService.php:3418`), and `forChokepoint` does not exist on `UnbalancedJournalEntryException` at all — I grepped `app/`, its sole definition is `UnbalancedJournalEntryPostException.php:56`. §5.1 is a pre-split survivor that was left standing beside §5.3, which describes the split correctly two screens below; round 3 was an explicit documentation-truth sweep that repaired §5.5 row 3 and did not touch §5.1.
*Failure scenario:* three shipped production docblocks route the reader to this exact section — `UnbalancedJournalEntryException.php:42` ("See … M1-census.md §5"), `UnbalancedJournalEntryPostException.php:47`, and the inline comment at `GeneralLedgerService.php:3413`. A maintainer actioning R-10's per-site narrowing follows that pointer, reads §5.1, and writes `catch (UnbalancedJournalEntryException)` around a chokepoint call. Because that type is `\RuntimeException`-parented and the chokepoint's is `\InvalidArgumentException`-parented siblings, the clause **compiles, reads correctly, and matches nothing** — the precise outcome `UnpostableDocumentGlException.php`'s new table was written to prevent ("picking the wrong one silently does nothing"). The delivery's entire thesis is that the type name is the callable contract; the census's definition of that name is wrong. This is the seventh firing of the brief's own append-vs-substitute class, this time inside the review-target census rather than the brief.
*Fix:* replace the class name at `:346` with `UnbalancedJournalEntryPostException` and reconcile §5.1's "exactly this and nothing more" with §5.3's split. The stale "house type" phrasing also survives in `ChokepointUnbalancedGuardTest::test_chokepoint_raises_the_house_unbalanced_exception_type` and in the M3 commit message — worth sweeping in the same pass.

**2 — Important — handback §6 / §7 / §8 and `docs/handoff/progress/enforcement-p3.progress.yaml` M3 record — CONFIRMED**
Both mid-wave ACCEPT registers closed with confirmed findings **explicitly routed to M3**, and M3 closed one of the seven. `M1-round4.md` ends: *"All five findings are P3 documentation-truth residue in the milestone's own artifacts — **worth sweeping before M3 consumes them**"*; `M2-round2.md` ends: *"worth folding into **M3's whole-package pass** rather than spending a fix round."* Only M1-round4's finding 5 (reproduce the R-4 / R-11 rulings) was carried. I verified each of the other six is still open at A:

| Source | Finding | State at A (verified by me) |
|---|---|---|
| M1-r4 #1 | YAML round-1 record still carries the retracted two-site R-9 and count 9 | **OPEN** — `enforcement-p3.progress.yaml:322` reads `R-9 (PostGrIrOnGoodsReceipt:41 + BatchWriteOffService:122). Total findings now 9.` with no supersession marker; `:300` still cites `AccountingService:304-306` (HEAD: `:304-320`) |
| M1-r4 #2 | `AccountingService.php:309-311` catch-count inflation | **OPEN** — docblock still says "23 in that literal form, 53 clauses". Measured: literal form 23 raw of which **3 are comment lines → 20 real**; broad form 54 raw of which **4 are comments → 50 real** |
| M1-r4 #3 | listener cites `GeneralLedgerService.php:1307` | **OPEN** — `PostShiftCashVarianceAdjustment.php:191` still says `:1307`; the `$this->postEntryNow(...)` call is at **`:1308`** |
| M1-r4 #4 | `commit: bea3b4936` no longer names a tip carrying all accepted M1 production content | **OPEN** — YAML `:177` unchanged; `ebbfca144` and `de71689ca` both edit `app/` and are descendants |
| M2-r2 #1 | `COUNTRY_CODES` hand-copied, nothing binds it to `getSeederForCountry()` | **OPEN** — `SeededChartManifestRequiredPurposeCompletenessTest.php:86` still a bare `['TN','FR','XX']` const |
| M2-r2 #2 | §1's enumeration claim broader than the table (`BackfillChartPurposesCommand.php:323-363` six-purpose literal absent) | **OPEN** — `M2-reconciliation.md` §1 intro still claims `SystemAccountPurpose` array literals across `app/`; the table is still the 8 `hasAccountForPurpose` rows |

Worse than the non-closure: **none of the six appears anywhere in the handback.** I grepped it for `1307`, `1308`, `bea3b4936` context, `Total findings now`, `23 in that`, `sweep`, `residue` — the handback's §7 findings register, §8 carry-forward and the M3 commit message list only R-4, R-11, D-1 and D-2. The YAML's M3 handover record does the same.
*Failure scenario:* the parent reads the handback — the artifact the promotion protocol digest-binds and lands in the closing commit C — sees M1 and M2 as `passed` with clean ACCEPT verdicts and a four-item carry-forward, and promotes. M1-r4 #4 then fires as its own register predicted: the protocol pins accepted tips by SHA, `bea3b4936` is the recorded M1 commit, and the two contract docblocks that rounds 2 and 3 blocked the gate to obtain do not travel with it. M1-r4 #1 fires next: a follow-up lane works the owes list from the YAML record the file's own header calls authoritative and spends remediation effort pinning a nesting invariant on `BatchWriteOffService:122`, a catch that is unconditionally immune by type — exactly the waste round 3 was written to prevent. These are P3-tier individually; the defect is that a whole-package gate whose stated job is to "rerun the full accumulated evidence" dropped six of seven deferred items without recording an acceptance decision on any of them.
*Fix:* either close them (all six are one-to-three-line edits) or record each explicitly in the handback's deviations/carry-forward register with a disposition, so the parent is deciding rather than unaware.

**3 — Important — handback §6 "Consolidated deviations register" — CONFIRMED**
The census's deviations table (§7) carries **eight** rows, `M1-D1` … `M1-D8`. The handback's consolidated M1 deviations table carries **six**, `M1-D1` … `M1-D6`. `M1-D7` (the four round-0 positions that did not survive review — creators-without-catchers, the withdrawn converter re-throw, the reverted re-parenting, the reviewer-found listener swallow) and `M1-D8` (**"A claim committed in this round was false and is retracted here"** — the "verified exhaustively" blast-radius assertion that was actually a 60-line lexical sweep, and whose revert *introduced* a 422 regression at three sites) are dropped with no note. Nothing in the handback marks the list as abridged; §6 is titled "Consolidated".
*Failure scenario:* brief §7 item 4 is literal — *"Deviations — **every one**, with justification. A silent deviation found at gate time invalidates the handback."* M1-D8 is the single most reviewer-relevant deviation in the package: it is the record that this lane once committed a false exhaustiveness claim. Its omission from the parent-facing consolidation is precisely the silent-deviation case that clause names, and it is found at gate time.
*Fix:* add `M1-D7` and `M1-D8` to handback §6, or state that §6 is a pointer and the authoritative register is census §7.

**4 — Minor — `M1-census.md` §0 vs §2.1–§2.3 — CONFIRMED**
The headline classification counts are not reconstructible from the tables. Numbered rows give (a) = 17 (§2.2) + 3 (§2.3 rows 41–43) = **20**, (b) = **23** (§2.1), (c) = **8** (rows 44–51) — total 51. §0 declares **(a) 21 · (b) 24 · (c) 8 = 53**. The gap is the two unnumbered conditional rows (`:1583`, `:1711`), which are never assigned a class, so the 21/24 split cannot be derived; the sub-table's lead-in ("Two of **these** self-post conditionally") also mis-implies they are among rows #1–#23, which they are not. The `(c) = 8` count — the only safety-relevant one — is unaffected and independently confirmed.
*Failure scenario:* a consumer re-deriving the partition from the tables to cross-check P1's census gets 51/20/23 and reads the mismatch as a missing creator rather than an unlabelled sub-table.

**5 — Minor — handback §2 "Evidence pointers" and §7.1 header — CONFIRMED**
Both cite the M1 findings register as `M1-census.md` **§7**. The findings register is **§6**; §7 is the deviations table. Every M2 pointer in the same block (§1/§2/§3/§4/§5/§6/§7/§9/§11) is correct, and the shipped production docblocks correctly cite "§6 R-10", so this is an isolated slip.
*Failure scenario:* a parent following §7 lands on the deviations table, does not find R-1…R-11, and concludes the findings register is missing from the landed evidence.

---

## Bypasses attempted that FAILED (the delivery held)

- **Find a `Posted` write the census missed** — raw-string status writes, `->update(['status'…])` outside the chokepoint, relation-mediated creates: all empty. The seven-site enumeration is exact.
- **Break the type split via exact-class dispatch or a message classifier** — re-checked the reasoning path; `forChokepoint`'s message is byte-identical to the pre-M1 string, and no `get_class($e) ===` render map exists.
- **Find a cash-moved-without-GL half-write from the new swallow** — refuted: `RepositoryAdjustmentService::post()` wraps all three artifacts in one `DB::transaction`, so the refusal rolls back the document and the movement with the entry.
- **Find a scope or forbidden-edit violation** — the range touches no `.github/**`, no control file, no frozen seeder, no manifest, and no path outside the allowlist; I re-derived the path set rather than reading the handback's.
- **Falsify D-2's 14-of-28 claim** — reproduced both `comm` directions from landed code; the fourteen names match exactly and nothing goes the other way.
- **Falsify the CI-lane claim** — `treasury-spine-pgsql` genuinely selects the whole `tests/Feature/Accounting` directory on PR→dev and is genuinely in `all-checks-pass needs`. The pre-existing 5-error redness of that lane is disclosed, correctly attributed to base, and correctly left unfixed under rule 4.
- **Not run (constraint, not a pass):** PHPUnit and PHPStan re-execution — the sealed snapshot has no `vendor/`.

## Assessment

The engineering is strong and unusually honest: the class-(c) enumeration is structurally proven and I could not break it, the type split is the right resolution of a trade-off this lane twice got wrong and recorded both times, the M2 guard is non-vacuous and genuinely gated, and the two hardest calls (R-4's live chained imbalance, R-11's projection swallow) are escalated with quoted rulings rather than quietly absorbed. Nothing here threatens money correctness, the fiscal chain, or the stock↔GL seam, and no lens found a production defect introduced by this range.

What blocks the gate is evidence integrity in the artifacts the promotion protocol makes permanent. The census section that three shipped docblocks point at states the wrong exception class for the package's central deliverable (finding 1). Six confirmed findings that both mid-wave registers explicitly routed to this gate were neither closed nor disclosed (finding 2). The handback's "consolidated" deviations register silently drops two of eight, including the retraction of a false exhaustiveness claim, against a brief clause that says a silent deviation found at gate time invalidates the handback (finding 3). These land in C, get digest-bound by the annotated pin tag, and become immutable by protocol — the same immutability that already forced M0-D1's dual-shape parsing workaround. They are cheap to fix now and permanent if not.

Findings 1–3 are close-before-merge. Findings 4–5 are one-line corrections worth folding into the same pass.

VERDICT: CHANGES-REQUIRED

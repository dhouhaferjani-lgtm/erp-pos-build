# Adversarial merge-gate register — enforcement-p1 · **M2**, round 1

Range reviewed: `41fb478c2..HEAD` (`1b1f99e00` writer + M1 note closures, `ff5642f87` SEED, `9946b10b9` PINS+CHECKER).
Lenses applied: **stock-gl-interaction**, **inventory-costing** (both apply — the four guarded tables are the stock↔GL seam itself).

## What I verified independently (not taken from the handback)

| Claim | Method | Result |
|---|---|---|
| Baseline == scanner output at HEAD | ran the scanner inline (`scan([app], …)`) and diffed key sets against the checked-in JSON | **116 sites, 34 violation / 67 linked / 15 not_applicable; violation key set byte-identical to the baseline** |
| Baseline shape | parsed the JSON | 34 keys, unique, `sort(SORT_STRING)`-ordered, no line numbers |
| `dpa_baseline_protected_blob` mirror | `git rev-parse ff5642f87:apps/api/tests/…/document-per-action-baseline.json` | `1381983d463e…c597` — matches the YAML pin exactly |
| Pin-tag pre-allocation | `git tag -l 'ci-pin/*'` | 0 → `n = 1` is correct |
| Two-phase topology | `git show --stat` | seed commit contains **exactly one file** (the baseline); pins land in a distinct later commit; both precede this review ✔ |
| Scope allowlist | `git diff --name-only base..HEAD` | 14 files, all under `apps/api/tests/Architecture/**` or `docs/handoff/**`. **No production path, no `ci.yml` yet** ✔ |
| Green run | `DPA_BASELINE_PROTECTED_BLOB=… phpunit …RatchetTest.php` | `OK (2 tests, 113 assertions)`, 11.6 s |
| M1 guard still live after the scanner refactor | `phpunit …WriteGuardTest.php` | `OK (4 tests, 4 assertions)`, 4.7 s |
| §3.8 headline finding | read `RepositoryTransferService.php:105` | **real** — `$draft->delete()` on a `JournalEntry`; correctly baselined as violation #34 |
| Cross-check spot-probes | read V1/V2/V3/V9 sites | `TestE2EGLPosting` and `ReceiptVoidService` absent ✔; `AccountingService.php:398` and `GeneralLedgerService.php:1249` carry `source_type`/`source_id` ✔ |
| Lens probe (POS COGS path) | read `PosCoreReceiptProjection.php:2012/:2417` | `reference_type => 'pos_receipt'` literal + `reference_id => $receiptId` — the `linked` classification is honest, not credited by blind spot E |

Rule 19: no money/quantity arithmetic in this diff (static AST analysis + a JSON key list) — **not applicable, no float exposure**. Tenancy/DI/i18n: no production code, no container, no user-facing strings — **not applicable**. Migrations/queues: none — **not applicable**.

---

## Findings

**1. P2 — `apps/api/tests/Architecture/DocumentPerActionBaselineRatchetTest.php:120` — CONFIRMED. The "unfetchable blob → FAILS" acceptance proof (handback §6.3 case C) never reached the `git cat-file` branch; it is a duplicate of the mirror-drift case.**

The assertion arithmetic proves it, and I reproduced it. Green = 113 assertions (test1 = 37, test2 = 76). A failure at the mirror assert (`:112`) stops test2 after 3 assertions → **40 total**. A genuine unfetchable-blob failure must pass the mirror assert and stop at `:120` → **41 total**. The handback reports case A (mirror drift) = 40 **and** case C (unfetchable blob) = **40**. My run:

```
$ DPA_BASELINE_PROTECTED_BLOB=0000000000000000000000000000000000000000 ./vendor/bin/phpunit …RatchetTest.php
…/DocumentPerActionBaselineRatchetTest.php:112
Tests: 2, Assertions: 40, Failures: 1.
```

byte-for-byte the shape the handback pastes as case C. **Failure scenario:** the `git fetch origin tag <pin-tag>` step M3 is about to wire exists solely to satisfy the durable-ref contract (brief §2 phase 2 / gate-r4 R4-H-2); the handback certifies that the "no retained ref → fails closed" leg was demonstrated when it was not, so a defect in that branch (e.g. `proc_open` returning `-1` and the code's `return [1, '']` masking, or a shell that swallows git's 128) would ship uncaught into the one lane that depends on it. I confirmed `git cat-file blob <absent>` exits **128** and the assert is `assertSame(0, $exitCode)`, so the branch is *plausibly* correct — this is an evidence-integrity defect, not a demonstrated code defect. Note the brief's *required* fail-closed proofs (mirror drift, unset variable) were both genuinely executed and I reproduced both.
Sub-note, same class: case 4 (bogus baseline entry) pastes only the STALE message — that scenario also adds a key absent from the protected blob, so the real result is **two** failures, not one. The paste implies a narrower blast radius than the code produces.
**Fix:** run case C with mirror *and* variable set to the same well-formed absent hash, paste the 41-assertion failure anchored at `:120`; repaste case 4 with both failures.

**2. P2 — `apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php:216-224` (blind spot C) × `DocumentPerActionBaselineRatchetTest.php:143-152` — CONFIRMED. The ordinal-key scheme, combined with M2's new anti-growth direction, converts benign key renumbering from "red, fix the baseline" into "red, and no contributor can make it green".**

Ordinals are assigned per `(table::mechanism)` bucket in source order over **all** sites in the scope, linked and violation alike (`:614-641`). Blind spot C documents the renumbering churn and — written at M1, before direction (c) existed — concludes it "fails SAFE (still red)". That is no longer the whole story: a renumbered violation key is a key **absent from the owner-pinned blob**, and `:143-152` fails on any added key. Removing the stale entry instead trips direction (a). **Failure scenario:** the DPA remediation lane fixes `OpeningBalancePostingService::post` (baselined as `…::stock_movements::create#1`, `…::stock_levels::create#1`, `…::stock_levels::update#1`) by inserting a properly-linked `StockMovement::create` above the unlinked one at `:117`; the surviving violation renumbers to `create#2`; CI is red and stays red until the **owner** sets a new repository variable and pushes a new pin tag — i.e. the guard hard-blocks the remediation program it exists to protect, on a change that strictly improves the tree. Neither the ratchet docblock, the handback §6, nor the operational-owes section states this. It is not a code bug — it is an undisclosed, owner-gated operational consequence created *by this milestone*.
**Fix (cheap):** state it in the ratchet docblock + handback §8 as a named re-pin trigger, and raise the parent ticket. (Making ordinals violation-relative would remove the *linked-write-inserted* half of the trigger but not the violation-reordering half; that is a design change, not something I am asking for here.)

**3. P3 — commit `9946b10b9` — CONFIRMED, no live defect.** Commit 2 carries a 70-line behaviour-affecting scanner refactor (the bounded AST parse cache) beyond the milestone's "mirror pins (+ any checker metadata)". The seed was cut with the pre-cache scanner. I verified equivalence rather than accepting the claim: re-running the cached scanner at HEAD reproduces **116/34/67/15** and a violation key set identical to the untouched seed file, and the scanner contains no `NodeTraverser`/`setAttribute` mutation that could leak state across the five passes (`grep` clean), so cross-pass AST sharing is safe. Protocol tidiness only.

**4. P3 — `DocumentPerActionBaselineRatchetTest.php` (measured, not claimed).** Peak memory on my run: **443 MB** (guard test: 439 MB). The scanner's own docblock records that caching the whole tree "exhausted a 512 MB CLI limit" — so the bounded cache leaves ~13% headroom against that same ceiling, and `app/` only grows. An OOM is a fatal → non-zero exit → fails closed (safe direction) but presents as an undiagnosable red. M3 should pin an explicit `memory_limit` on the job/invocation rather than inherit the runner default.

**5. P3 — the ceiling's *reader* is candidate-deletable, and §6 F-8's enumerated residual names only `ci.yml`.** Deleting `DocumentPerActionBaselineRatchetTest.php` (or it plus the baseline) leaves `--testsuite=Architecture` green with no ratchet at all; nothing asserts the detector exists. Brief `:488` explicitly discloses the ci.yml residual and its mitigations, but not this one. Same residual class, one line of disclosure short.

**6. P3 — scan-root exclusion is absent from the blind-spot list that M2's cross-check declares it inherits.** The scanner docblock (`:151-155`) says its KNOWN BLIND SPOTS are "the honest list M2's baseline cross-check inherits", yet none of A–F names the root: only `app/` is scanned, so live four-table writes at `database/seeders/CoffeeShopSeeder.php:1028` (`StockLevel::create`) and `:1132` (`JournalEntry::create`, no `source_id`), `DemoPharmacySeeder.php:872`, `ParapharmacySeeder.php:1319`, `StockLevelSeeder.php:109` are neither baselined nor guarded. **I am not reopening the scope decision** — M1-round4 finding 7 raised it and dispositioned it as a parent ticket, and handback §3.3 item 1 flags it. The gap is that the docblock, which the handback points at as the operative list, is silent. One blind-spot line closes it.

**7. P3 — mirror-update ordering opens a fleet-wide red window at every re-pin.** The owner sets the variable at promotion step 3; the parent updates the YAML mirror only in the **post-promotion** admin commit. Between those two events every workflow run sees mirror ≠ variable and fails closed as a "tamper signal" — including branches cut before the admin commit, which stay red until they rebase past it. Inherent to the ruled sequencing (gate-r3 R3-C-2), not a defect, but it belongs in §8 operational owes so the first re-pin is not read as an attack.

---

## Bypasses I attempted that FAILED (the guard held)

1. **Variable unset** → FAIL at `:96`, 1 assertion, non-zero exit. Held.
2. **Malformed hash (`notahash`)** → FAIL at `:105` on the `^[0-9a-f]{40}$|^{64}$` regex. Held.
3. **Well-formed but absent hash, mirror untouched** → FAIL at `:112` (drift). Held (and is finding 1's evidence).
4. **Key-collision growth** — land a new unlinked write that reuses an already-baselined key. Ordinals are per-bucket in source order over *all* sites (`:614-641`), so any additional same-bucket write yields a fresh ordinal → a new key → caught by (a) and (c). Failed.
5. **AST-cache poisoning** — hoped the shared cached ASTs would let a later pass see mutated state and launder a site. No traverser, no `setAttribute`, no in-place stmt rewrite; independent re-run reproduces the pre-cache census exactly. Failed.
6. **Scan-root escape inside `app/`** — `files()` (`:1595-1617`) recurses `app/` with no exclusion list and takes every `.php`. No hole inside the declared root. Failed.
7. **Baseline emptied / deleted** — `[]` trips direction (a) with all 34; a missing file trips `assertFileExists`. Failed.
8. **Hidden baseline drift between the seed and HEAD** — `git diff ff5642f87..HEAD` on the baseline path is empty and my independent scan matches it key-for-key. Failed.

---

## Assessment

The core M2 deliverables are real and, where I could test them, correct: the two-phase topology is executed exactly as ruled, the seed is reproducible from the tree, all three ratchet directions are implemented with the authority genuinely outside the candidate, the mirror-drift and unset-variable fail-closed legs work, and the register cross-check (§4) is unusually honest — including surfacing violation #34, a live `journal_entries` row delete the DPA register never caught, which I confirmed at `RepositoryTransferService.php:105`.

Two things stop it here. Finding 1 is the same defect class this package's own M1 rounds blocked on three times — an artifact asserting coverage the run does not provide — on the specific leg M3's CI wiring is about to depend on. Finding 2 is a consequence this milestone created and did not disclose, on the interaction between the ratchet and the remediation program. Both fixes are small and neither touches the seed, so the two-step re-seed topology is not triggered.

VERDICT: CHANGES-REQUIRED

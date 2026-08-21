# Adversarial merge-gate register — enforcement-p1 · **M2**, round 2

Range reviewed: `41fb478c2..HEAD` (`fea3de86f`). Fix round 1 = `441dad0fe` (code/docs) + `fea3de86f` (record).
Lenses: **stock-gl-interaction**, **inventory-costing** — both apply (the four guarded tables *are* the stock↔GL seam). Rule 19: no money/quantity surface in the diff (`git diff | grep -E '(float)|number_format|bc(add|mul)|round\('` → empty) — N/A. Tenancy/DI/i18n/migrations/queues: no production code, no container, no user-facing strings, no migration, no queue — N/A (scope allowlist verified below).

## What I verified independently at HEAD (not taken from the handback or round 1)

| Claim | Method | Result |
|---|---|---|
| Baseline == scanner output | ran `scan([app_path], base_path().'/')` inline, compared arrays with `===` | **116 sites / 34 violation / 67 linked / 15 n-a; `$violations === $baseline` exactly true**, 116 unique keys, sorted, zero keys carrying a line number |
| Working baseline == seed blob | `git cat-file blob 1381983d4…` vs file bytes | byte-identical ✔ |
| Census §5 (34 rows) is content-derived, not narrated | dumped every violation `file:line table mechanism` and compared row-by-row to the handback table | **all 34 rows match exactly**, including #34 `RepositoryTransferService.php:105 journal_entries delete` and the 9-site `WeightedAverageCostService` cluster |
| Two-phase topology | `git show --stat` | seed `ff5642f87` = exactly 1 file; pins `9946b10b9` = distinct, later; fix round adds no seed change ✔ |
| Fix round is comment-only | `git show 441dad0fe -- apps/api` | every added line is inside a `/** */` block; baseline + YAML pins untouched, so the two-step re-seed topology is correctly NOT triggered ✔ |
| Pin-tag pre-allocation | `git tag -l 'ci-pin/*'` = 0, `git ls-remote --tags origin 'refs/tags/ci-pin/*'` = 0 | `n = 1` → `ci-pin/enforcement-p1-r1` correct ✔ |
| Scope allowlist | `git diff --name-only base..HEAD` | 14 files, all `apps/api/tests/Architecture/**` or `docs/handoff/**`; no production path ✔ |
| Green run | `DPA_BASELINE_PROTECTED_BLOB=1381983d4… phpunit …RatchetTest.php` | `OK (2 tests, 113 assertions)`, 10.6 s, 443 MB |
| M1 guard still live | `phpunit …WriteGuardTest.php` | `OK (4 tests, 4 assertions)`, 4.4 s |
| Ordinal semantics behind the disclosed re-pin trigger | read `DocumentPerActionWriteScanner.php:622-648` | ordinals are per `table::mechanism` bucket, source-ordered over **all** sites regardless of classification — §8.1's example (inserting a linked create renumbers the survivor) is mechanically accurate ✔ |
| Blind spot G's citations | read the named seeder lines | `CoffeeShopSeeder.php:1028` `StockLevel::create`, `:1132` `JournalEntry::create` with `source_type` and **no `source_id`**, `DemoPharmacySeeder.php:872`, `ParapharmacySeeder.php:1319`, `StockLevelSeeder.php:109` (`updateOrCreate`) — all real ✔ |

### Round-1 findings — disposition verified

- **P2-1 (false "unfetchable blob" evidence) — CLOSED.** I rebuilt the assertion model from my own runs instead of trusting the paste: unset → **38** (`:119`), malformed → **39** (`:128`), green → **113**. Test 1 = 37, so mirror-drift must be 40 and a genuine `git cat-file` failure must be **41** — which is exactly what the corrected §6.3 case C now pastes, anchored at the `assertSame(0, $exitCode)` call and surfacing `git cat-file exit 128`. I confirmed independently that `git cat-file blob <absent-40-hex>` exits **128** and that PHP's `proc_close` propagates it (`proc_close exit: 128, stdout len: 0`), and that PHP anchors a multi-line call at its first line — so `:121` is the cat-file assert in the pre-fix file, not the mirror assert. Case 4 is repasted as **2 failures / 115 assertions**, which my model also reproduces (38 + 77). Evidence integrity restored.
- **P2-2 (undisclosed re-pin trigger) — CLOSED.** `DocumentPerActionBaselineRatchetTest.php:35-48` carries the ⚠️ RE-PIN TRIGGER block; handback §8.1 names it with the `OpeningBalancePostingService::post` example and an explicit parent action request. The mechanism claim is true against `:622-648`.
- **P3-3/4/5/6/7 — all closed** (parse-cache note §6.1/§6.2, memory §8.4, deletability docblock `:50-56` + §8.3, blind spot G `:189-200`, red window §8.2).

---

## Findings

**1. P3 — `apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php:627-648` × `DocumentPerActionBaselineRatchetTest.php:161-172` — CONFIRMED (by construction). A baselined key is a *slot*, not a *write*: a brand-new unlinked write can inherit an existing baseline entry and land CI-green. Not in the blind-spot list the handback declares operative.**

The key is `file::class::function::table::mechanism#ordinal` and the ordinal is purely positional within the bucket — nothing about the write's content, target, or arguments enters it. So a change that **removes** a baselined unlinked write and **adds a different** unlinked write of the same mechanism/table in the same function keeps the key set identical: direction (a) sees the key in the baseline, (b) sees no stale entry, (c) sees no added key. **Failure scenario:** a contributor deletes the unlinked `StockMovement::create` at `WeightedAverageCostService.php:425` (`recordSale`, baselined as `…::stock_movements::create#1`) and adds a new, semantically different unlinked movement create in the same function — e.g. a compensating movement on the sale path. All three directions are green and no owner decision is involved; a new unguarded stock-ledger write ships under the cover of a violation that was supposed to be frozen, not transferable. Round 1's bypass #4 tried the *additive* form (fresh ordinal → caught); the *substitution* form is not caught and is not named in blind spots A–G. Mitigation is disclosure, not code: blind spot C tells the reader keys are positional but stops at renumbering churn. **Fix:** one blind-spot line ("a baselined key can be re-occupied by a different write in the same bucket — the ratchet freezes positions, not code; read the file:line in a diff that touches a baselined function") — cheap, and M3's whole-package gate is the natural place to confirm it landed.

**2. P3 — `docs/handoff/progress/enforcement-p1.progress.yaml:203` (`m2_evidence.tamper_cases`) — CONFIRMED. The machine-readable record still carries the understatement the handback fixed.** It reads `4 bogus entry -> FAIL (stale)`. The corrected §6.3 case 4 (and my own arithmetic: 115 assertions, 2 failures) shows a bogus entry trips **stale AND anti-growth**. The handback was repasted; the YAML line — the operative record M3/P3-M0 consumers parse — was not. Same class as round-1 finding 1, one field short.

**3. P3 — `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md:471,477,487` — CONFIRMED. Evidence line anchors are pre-fix-commit line numbers with no commit pin.** Cases A/B/C were run against `9946b10b9`; the fix commit added 23 docblock lines, so at HEAD the same assertions live at `:135`/`:119`/`:144`. I reproduced `:119` (unset) and `:128` (malformed) at HEAD versus the register's `:96`/`:105` pre-fix. A reader at M3 checking `:121` against HEAD lands in the unset block. One line ("all §6.3 pastes were produced at `9946b10b9`") removes the ambiguity for the whole section.

**4. P3 — handback §8.4 (`:545-550`) — CONFIRMED. The memory premise is wrong in the safe direction, but M3 will act on it.** `apps/api/phpunit.xml:34` already pins `<ini name="memory_limit" value="2G"/>`, so a phpunit invocation does **not** inherit the runner default and the ceiling is 2 GB, not 512 MB — measured 443 MB is ~78 % headroom, not ~13 %. M3's "pin an explicit memory_limit" is still fine, but the justification as written would send M3 hunting a ceiling that phpunit.xml already sets.

**5. P3 — `DocumentPerActionWriteScanner.php:178-190` — CONFIRMED, cosmetic.** Blind spot C still concludes "It fails SAFE (still red)" with no pointer to the new unfixable-red consequence now documented in the ratchet docblock, and new item **G** was inserted between **D** and **D2**, breaking the list's order. The honest list is the artifact the handback points readers at; one cross-reference and a move close it.

---

## Bypasses I attempted that FAILED (the guard held)

1. **Variable unset** → FAIL at `:119`, 38 assertions, non-zero exit. Held.
2. **Malformed hash (`notahash`)** → FAIL at `:128` on the `40|64` hex regex. Held.
3. **Unreachable blob** → `git cat-file blob <absent>` exits 128, `proc_close` propagates it, `assertSame(0, …)` fails; the branch is reachable only after the mirror assert passes (arithmetic 41 ≠ 40 confirmed against my measured 38/39/113). Held.
4. **Writer auto-heal** — re-run `write-document-per-action-baseline.php` to absorb a new violation: it writes the added key, direction (c) fails against the pinned blob. The writer is invoked by nothing (grep across php/yml/json/sh outside `vendor/` → only its own usage docstring), so no lane can self-heal. Held.
5. **Mirror forgery** — `mirrorPin()` takes the first `^dpa_baseline_protected_blob:\s*[0-9a-f]{40,64}\s*$` match; the only other occurrence in the YAML is a `#`-prefixed `=` comment that cannot match. Even a forged earlier line buys nothing: the authority is the env var and the key comparison reads the blob's bytes. Held.
6. **Baseline shape abuse** — a JSON object instead of a list, or duplicated keys, both parse and pass; neither admits an extra violation (a duplicate maps to the same protected key). No laundering. Held.
7. **Same-bucket additive collision** (round 1's #4, re-verified against `:627-648`) — any extra same-bucket write takes a fresh ordinal → new key → (a) and (c) both fire. Held.
8. **Key substitution** — remove a baselined write, add a different one in the same bucket → **succeeded**; recorded as finding 1.

## Assessment

Both round-1 P2s are genuinely closed, and I closed them the same way the round-1 gate opened them — by arithmetic I re-derived from my own runs rather than by reading the paste. The rest of the milestone re-verifies clean at HEAD: the seed is exactly reproducible from the tree, the census is content-derived and matches row-for-row, the two-phase topology and the pre-allocated tag are correct, the authority genuinely sits outside the candidate, and every fail-closed leg I could exercise without writing to the repo held. What remains is five P3 notes — one substantive disclosure gap (finding 1, a laundering path the blind-spot list should name) and four record-accuracy items that M3 can absorb with its whole-package rerun. None of them blocks the merge gate for this milestone.

VERDICT: ACCEPT

# FINAL-GATE REGISTER — package p2 M4 round 5 attempt 1
accepted_sha: 39e2d9488e0547e4128921bec9ccbdcbcd5821e6
base_sha: a4a8c2293dcf7556045f44d35cfa5f734be8022b   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml
snapshot: detached-worktree @ 39e2d9488e0547e4128921bec9ccbdcbcd5821e6 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-21-r5.md=bb03cbf4447b284fa7bcb2d65640ed36a5fa91850fd95dbd711c8091ba57ca55   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 7d53797b1849b6adeb2fcb39789935a48fc32a6f4804a9833d4bb8a78f1aa2b7
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:frontend-conventions=278596dbc07e82ece964897d021d2d136dce59f136519578c9a256b39da45385
control_sha256: lens:tenancy-authz=9e2e444acf0b0de7b70f053eaf9948be2f001eb38de132a15103976d78b3a345
max_fix_rounds: 5
---
I have everything I need. Writing the register.

---

# ADVERSARIAL FINAL-GATE REGISTER — enforcement-p2, M4, round 5

**Snapshot** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.abq15Qe7QH/snap` · detached `HEAD = 39e2d9488e0547e4128921bec9ccbdcbcd5821e6` (verified) · range `a4a8c2293..39e2d9488` = **84 files**, `a4a8c2293` a strict ancestor of A, `BASE != A`.
**Handback reviewed** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-21-r5.md` · `sha256 = bb03cbf4447b284fa7bcb2d65640ed36a5fa91850fd95dbd711c8091ba57ca55` · **360 lines**.
**Lens contracts applied in full:** `frontend-conventions`, `tenancy-authz`.

---

## A. Required content-derived table evidence (gate-r8 R8-H-2)

| handback table | lines | data rows | FIRST row key | LAST row key |
|---|---|---|---|---|
| §1 Header | 19–27 | **7** | `**base SHA**` | `**pushed?**` |
| §3a i18n provenance | 75–79 | **3** | `en` | `**ar**` |
| §3a basis (entries vs key-level) | 89–92 | **2** | `**baseline entries**` | `**key-level gaps**` |
| §3b Baseline composition | 106–112 | **5** | `namespaces` | `structural failures` |
| §3c Feature-lane census | 116–120 | **3** | ``all of `tests/Feature` (74 groups)`` | `minus the 111 allowlisted names inside them` |
| §5e Scope allowlist | 261–275 | **13** | `1` → `` `.github/workflows/ci.yml` — the CI wiring deliverable `` | `**84**` → `**total — OUTSIDE THE ALLOWLIST: NONE ✓**` |

Boundary cells as shipped — §3a first `| en | 55 | 0 | 0 |`, last `| **ar** | **21** | **22** | **12** |`; §3b first `| namespaces | 55 |`, last `| structural failures | 0 |`; §3c first `| all of tests/Feature (74 groups) | 1 348 | 1 335 |`, last `| minus the 111 allowlisted names inside them | 1 020 | **1 008** … |`. (§3d is prose, not a table.)

**Independent re-derivation at A — every §3 census cell reproduces exactly.** Baseline JSON **2 924** entries, first `ar|adminCountryDefaults|aliased|*`, last `fr|workshop-bundles|plural|interval.months_many`, composition `ar aliased 22 · ar missing 2 851 · ar plural 9 · fr plural 34 · en plural 8`. Scanner at A (authority exported from `git rev-parse 6a0c1cd72:…` = `26a9ae1688d8…`, matching the YAML mirror): `55 namespaces, en=9228, fr=9244, ar=4697 (1986 behind aliases), 2924 known gap(s)`; `--json` → covered 2924 / **fresh 0 / stale 0**. Provenance re-derived through the tool's own `parseI18nWiring`: `en {own:55}`, `fr {own:55}`, `ar {own:21, en-aliased:22, english-spread:12}`. Locale files en 56 / fr 56 / **ar 34**. §3a basis over exactly those 34 namespaces: 22 `aliased` + **2 613** `missing` + **5** `plural` = **2 640**; key-level 2 618 + **1 986** = **4 604**. §3c from disk: **1 348** files / **1 335** distinct / **74** groups; 71 laneless → **1 131 / 1 119** with `debt_ceiling: 1131` and **zero slack**; allowlists **112 + 16 = 124** distinct, **111** inside laneless → **1 020 / 1 008**, and the 13 outside are exactly the 12 laned-group names plus `VoucherLedgerTest`. §5e buckets reproduce path-for-path (46/25/3/2/2/1×6 = 84); nothing outside the allowlist; `generated.d.ts` absent from the range.

---

## B. Findings

### CRITICAL-1 — P2 moves two Architecture ratchets onto the PR→dev gate for every lane, and that step is announced nowhere, named in no S-14 verification list, and absent from the decision record; §2's own change inventory undercounts it

`ci.yml:204-215` (added by this range — `git diff a4a8c2293..HEAD -- .github/workflows/ci.yml` shows `+ - name: Event ratchets (orphaned events + projector emission — PR→dev lane)` and `+ run: ./vendor/bin/phpunit tests/Architecture/OrphanedEventRatchetTest.php tests/Architecture/ProjectorEmissionRatchetTest.php`). It sits in `backend-architecture`, which carries **no `if:`** and is in `all-checks-pass` `needs` (`:1443`). The same two files also run at `ci.yml:455` inside `backend-test`, whose `if:` at `:317` skips PR→dev. So this step is a genuine pass/fail-contract change on the day-to-day merge gate for every open lane — the step's own comment says so: *"that job's `if:` skips PR→dev, so on the day-to-day merge gate they gated NOTHING. This job has NO `if:` guard, so the ratchets now fire on every event that starts the workflow."*

Where it is missing:

- **`ANNOUNCE-…:110`** — "**Five** new steps in existing jobs (**2** in `backend-architecture`, 3 in `frontend-lint`) and **one new job**." The true count in this range is **six** new steps — **three** in `backend-architecture`. The §2 table at `:114-121` enumerates rows 1–5 and the job; there is no row for the event ratchets. `grep -i 'orphan\|projector\|event ratchet'` over the whole announcement returns **nothing**.
- **`HANDBACK…:225-232` (§5d, "what the S-14 dispatch must show executed")** — names only *Check tests/Feature CI-lane manifest* and *Feature-lane checker liveness test* for `backend-architecture`. `ANNOUNCE-…:290-292` (§8 item 2, the same list for the owner) names the same two. Brief §5 item 4 step 3 requires the recorded dispatch run to show the package's new step(s) executed, and brief §3's acceptance block requires the handback to NAME them; a step nobody names is a step nobody verifies.
- **`DECISION-…`** — `grep -i 'orphan\|projector emission\|event ratchet'` returns only unrelated i18n "orphan key" hits. The durable decision record has no entry for a CI-contract change this package shipped.
- **`HANDBACK…` §4 Deviations** — absent. The only mention anywhere is one clause in the addendum at `:344` ("the ticketed es-A0 F-1 fold-in: the two event ratchets now run as a backend-architecture step (no `if:`), gating PR→dev") and a green-run line at `:360`. Brief §7 item 2 requires *every* decision the brief did not specify to be "flagged prominently"; a clause inside a rebase addendum is not the deviations section, and §4 is what a reader of a 360-line handback treats as the complete deviation set.

**Failure scenario, end to end.** The parent sends the final announcement built from this checklist (brief §6 F-6). It tells ten lanes that five steps and one job change, and names them. A lane — `codex/openapi-contract-a-to-z` (41 commits ahead), `l6-integration-verify`, `feat/scan-vat-configuration` — later dispatches a domain event with no registered listener, or adds a `FiscalEventProjector` that emits nothing, and opens a PR→dev. `backend-architecture` runs `OrphanedEventRatchetTest`/`ProjectorEmissionRatchetTest` for the first time on that event class, the PR reds, and the announcement they were sent does not contain the words "orphan", "projector" or "ratchet" anywhere. Simultaneously the owner, working `ANNOUNCE-…:290-292` / `HANDBACK…:225-232` at the pre-promotion `workflow_dispatch`, confirms the two named `backend-architecture` steps and never checks that the third executed — so the step that changed the contract is the one step with no green-on-a-real-runner evidence.

This is the package whose entire dispatch gate is *"a new required CI gate that an in-flight branch cannot pass turns every open PR red simultaneously — new gates land observe-first or baselined, never cold"* (brief §⛔, P2 row). An unannounced, unverified PR→dev gate is that prohibition, inside the package that exists to enforce it.

### CRITICAL-2 — the announcement's own inclusion rule says "drop branches already merged into `dev`", and §1/§4/§10 still bill four merged lanes; two of them are handed ceiling raises that are already in the shipped manifest, so executing them creates 15 units of permanent, unreported coverage slack

`ANNOUNCE-…:333-334` states the rule: *"**drop branches already merged into `dev`** (`git merge-base --is-ancestor <b> dev`) — a landed lane cannot perform a rebase action, and its classes are already `dev`'s drift, handled by §8 item 0."* Measured against A's own base:

| branch | `git rev-list --count a4a8c2293..<b>` | ancestor of base | still billed at |
|---|---|---|---|
| `codex/dn-consolidation-2026-08-12` | **0** | **YES** | §1 `:34`, §4 `:176`, §10 `:361` |
| `codex/es-wave-a0` | **0** | **YES** | §1 `:35`, §4 `:177`, §10 `:362` |
| `codex/ui-wave0-2026-08-11` | **0** | **YES** | §4 `:172`, §10 `:360` |
| `codex/enforcement-p1-dpa-guard` | **0** | **YES** | §4 `:175`, §10 `:364` |
| `codex/openapi-contract-a-to-z` | 41 | no | correctly open |

The two §1 rows are actionable instructions, and both are already satisfied by the shipped manifest:

- `:34` — `codex/dn-consolidation-2026-08-12` | `Document` **+9** | ceiling now 65 | *"raise `Document` by **9** **and** `debt_ceiling` by 9"*. At A `feature-lane-manifest.json` holds `Document: 74` = 65 + 9, and `find tests/Feature/Document -name '*Test.php'` returns **74** — those nine classes are in the base.
- `:35` — `codex/es-wave-a0` | `Fiscal` **+6** | ceiling now 73 | *"raise `Fiscal` by **6** **and** `debt_ceiling` by 6"*. At A the manifest holds `Fiscal: 79` = 73 + 6 and the directory holds **79**. `ANNOUNCE-…:257` itself records that `dev` moved to `3d66be352`, *"the second being `merge codex/es-wave-a0`"*, which *"changed `Fiscal` 73 → 79"*; `git merge-base --is-ancestor 3d66be352 a4a8c2293` → true, and `git log --merges 3d66be352` confirms `3d66be352 merge: es-wave-a0`.

**Failure scenario.** The parent sends the announcement. Either lane owner (or the parent on their behalf) applies its row: `Document` → 83, `Fiscal` → 85, `debt_ceiling` → 1146. The checker's ceiling test is `count > ceiling` (`feature-lane-manifest-check.php:341-352`) and the global test is `(deferred+excluded) > debt_ceiling` (`:802-808`), so **CI stays green** while 15 laneless classes' worth of headroom becomes permanent, unreported slack — nine new `Document` classes and six new `Fiscal` classes could then land in laneless groups with nothing firing. That is exactly what `ANNOUNCE-…:65-68` warns against in its own words (*"an over-raised ceiling is permanent, unreported slack — exactly what §8 item 0 and the round-8 'no slack' verification exist to prevent"*), and exactly the class the file's own closing note at `:383-386` diagnoses for `enforcement-p1-dpa-guard` (*"Following that row would have raised five ceilings for classes it never authored and double-raised against §8 item 0"*) — diagnosed by name, then committed for two other lanes.

The `:60-63` disclaimer does not rescue it: *"raise the value you find, not the value printed here — the actions are relative"* corrects a stale ceiling column, but "raise the value you find by 9" is still wrong when your nine classes are already inside the value you find. The `:29-30` stamp (`dev` = `3d66be352`) does not either — es-wave-a0 was **already merged at that stamp**, so the row was stale at its own epoch.

Consequential counts that follow: `:22` heading says "**ten** in-flight lanes will go RED" (eight of the listed lanes need an edit at A); `:167` says "**SIX** lanes write `ci.yml`; **FOUR** rewrite the same `needs:` line" (only P2 and `openapi-contract-a-to-z` are unmerged writers — the other four are in the base, and `route-manifest-drift` / `backend-dpa-guard` are already in the aggregate at `:1443`); `:186` cites `ci.yml:1249` for that `needs:` line, which is at **`:1443`** at A.

### MAJOR-1 — §3(a), the section that tells lanes which namespaces now hard-fail on a new English string, carries pre-rebase locale arithmetic

`ANNOUNCE-…:131-137`: *"Adding an English string now requires French — and Arabic in 33 of **56** namespaces"*, with the table row `` `en-aliased` (wired to the English bundle) | **23** | passes ``. At A the `ns` array holds **55** namespaces and the tool's own classifier yields **22** `en-aliased` (21 `own` + 12 `english-spread` = 33, so the "33" survives by coincidence while its complement and the total do not). `33 + 23 = 56` is the pre-rebase surface, before ui-wave0's owner-ruled `marketing` deletion — the same deletion §3b and the addendum correctly re-derive. A lane reading this cannot reconcile "56 namespaces" against a repository with 55, and the aliased/non-aliased split is the operative fact for whether their new key fails CI.

Same section, `:118` — *"`pnpm test:tools` (**7** files)"*. `ls tools/__tests__` at A returns **8** files, and `HANDBACK…:200` states "8 files / 160 tests".

### MINOR-1 — `DECISION-…` §(83) still carries the 1116 instruction and the "why not pre-raise" rationale that A contradicts, unstamped, in the file the record itself calls the durable ruling

`DECISION-…:2011` table row `` | `debt_ceiling` | 1114 | **1116** | ``, `:2019-2021` *"**Fixed documentarily** — new **§8 item 0** … re-baseline `Inventory` → 106, `CountryDefaults` → 28, `debt_ceiling` → **1116** in the first commit on `dev` after the merge"*, and `:2023-2027` *"**Why not pre-raise them in the candidate** (considered, rejected, and the reviewer agrees)"*. At A the candidate **does** carry regenerated ceilings (`debt_ceiling: 1131`, Inventory 106, CountryDefaults 28), and setting 1116 is a *lowering* by 15 that reds `backend-architecture` on `dev` — round 4's CRITICAL-1 verbatim, one document over. The r5 pass added supersession stamps to the round-time `2 917` figures at `:633`, `:737`, `:805`, `:988` and rebuilt the §(40) table to one epoch, but left this section — which `:2001` itself calls out as *"the durable ruling the parent sequences from, so a stale summary here is worse than a stale table elsewhere."* Mitigated only by `§(83)` pointing at §8 item 0, which is now correct.

### MINOR-2 — pre-rebase figures inside the shipped guard's own comments

`ci.yml:1166-1167` — the i18n step's rationale says the merged object *"aliases **23** whole namespaces to English for `ar`"* (22 at A). `apps/web/tools/audit-i18n-completeness.mjs:788-790` — *"**1998** of Arabic's authored keys sit behind whole-namespace English aliases … so a single `ar=**4702**` reads as coverage it does not have"* (1 986 and 4 697 at A, as the tool itself prints). Non-operative, but these are the two places a reader goes to check the scanner's premise.

### MINOR-3 — `feature-lane-manifest.json` `Accounting.classes: 79` vs 81 on disk

`find tests/Feature/Accounting -name '*Test.php'` → **81**. The `classes` field is enforced only for `deferred`/`excluded` groups (`feature-lane-manifest-check.php:333-352`), so a `lane` group's count is unvalidated decoration and has drifted. Harmless to the gate (81 + 17 + 119 + 1131 = 1348 reconciles), but it is an unstamped stale number in the manifest the checker publishes.

### MINOR-4 — deviation 3's justification no longer describes A

`HANDBACK…:159-161` — *"One production type change (`statements/api.ts`) — **required** to make `pnpm test:tools` wireable green."* At the merged base the file already read `meta: Omit<OffsetPaginationMeta, 'from' | 'to'>`; P2's diff changes it to `Pick<…, 'current_page'|'last_page'|'per_page'|'total'>`, which is **type-identical** (`src/types/pagination.ts:1-8` has exactly six members), and the consolidation guard flags only interface/type-literal declarations (`tools/__tests__/offset-pagination-meta-consolidation.test.mjs:44-55`), so `Omit<>` would pass it too. The edit is inert and disclosed; the word "required" is what is no longer true, carried through the rebase conflict resolution.

---

## C. What passes — recorded so the fix round stays tightly scoped

Nothing below is in question; a fix round must not disturb it.

- **All four round-4 items were closed as directed.** `ANNOUNCE-…` §8 item 0 is rewritten, not stamped: the "deliberately not pre-raised" parenthetical is gone, `:274-277` reconciles the "ceiling is 105" prediction explicitly, and `:279-284` is a regenerate-only instruction with **no literal values**; `grep -n 1116 ANNOUNCE` → no hits, and `:59-61`'s READ-FIRST claim is corrected. `HANDBACK…:95-98` retires the 4 608/4 613 adjudication paragraph and states the reproducing 4 604 decomposition. `DECISION-…` §(40) reads one epoch (1 348/1 335). `HANDBACK…:217` reads **18 jobs**, matching `grep -c` on the parsed workflow (17 at base + `security-regression`).
- **Ratchet authority verified by execution.** Unset variable → exit 1 with the fail-closed message; correct authority → exit 0, `fresh 0 / stale 0`. `readMirrorPin` drift, unfetchable blob, and `missingScannedSurface` (scanned-surface shrink reported as burn-down) all fail closed at `audit-i18n-completeness.mjs:698-763`; there is deliberately no `--no-ratchet` opt-out, and `tools/__tests__/audit-i18n-completeness.test.mjs` asserts that at `:326`. The suite covers matched growth at both the function and CLI layer (`:175`, `:300`), production-shaped alias/spread fixtures incl. spread-order and comment-stripping (`:79-142`, `:348-386`, `:419-478`), the CLDR plural-category gap (`:131`), and the ci.yml↔YAML pin-tag lockstep (`:333`) — brief 2(c) deliverables 1, 2 and 4 satisfied on authored provenance, never the merged `resources` object (H-5).
- **Two-commit seed topology exact (R3-H-4 / R4-H-3).** `6a0c1cd72:apps/web/tools/i18n-completeness-baseline.json` = `26a9ae1688d80e0f450215326b19ccd1701c9a8f` = the YAML mirror at `:69` = the working blob; `i18n_baseline_pin_tag: ci-pin/enforcement-p2-r1` non-null in A. `scripts/i18n-baseline-authority.sh` is local-only and asserts seed↔mirror equality before exporting; CI's authority stays `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}`.
- **The feature-lane checker is the strongest artifact here, and I tried to break it.** Group relabelling (`lane` → any real lane) is closed by the whole-directory selector match at `:296-309`; `excluded`-vs-`deferred` relabelling still counts (`:322-329`); a fictional lane is caught by `str_starts_with` resolution against parsed YAML, not file text (`:376-395`); `--filter` scanning survives `env`/`sudo`/`npx` prefixes, the pnpm-owned-vs-forwarded flag boundary, `&&`/`;`/newline segmentation that never splits on a bare `|`, and full-line-comment prose, and fails closed on anything unparseable (`:631-736`); anchoring is a string check on `/\\(…)::/`, with DEAD/AMBIGUOUS entry lints (`:738-796`); the global `debt_ceiling` closes lane retirement (`:798-812`). I re-derived the anchoring claim independently: `\ExpenseAnalyticsTest::` cannot match `\\(…|AnalyticsTest|…)::`, and both allowlists are 112→112 / 16→16 with zero drops. `backend-architecture` runs `composer install` before the checker, so the `vendor/autoload.php` requirement at `:155` is satisfied in CI.
- **CI wiring correct and non-chain.** Event graph exactly `push→main`, `PR→main|dev`, `workflow_dispatch` (`:3-8`); no `push→dev`. `:1158` `git fetch origin tag ci-pin/enforcement-p2-r1`; `:1161-1176` the discrete `frontend-lint` step `pnpm audit:i18n` with the variable mapped in — never the `package.json` chain; `:1187` `pnpm test:eslint-rules && pnpm test:tools`; `"test:tools": "vitest run tools/__tests__"` verbatim (R2-H-3). H-9 satisfied: the one new job joins `all-checks-pass` `needs` at `:1443`, and `frontend-lint`/`backend-architecture` were already members.
- **2(a) disposition correct at A.** `route-manifest-drift` exists (`:1317`) and is in the aggregate; `codex/ui-wave0-2026-08-11` is merged, so verify-only was the right branch and the recorded off-table predicate is honestly flagged.
- **tenancy-authz lens:** `security-regression` moves the whole `tests/Feature/Security` directory onto PR→dev — whole-directory, therefore anchor-safe, and its cost is stated honestly rather than understated (`:512-518`). No tenancy boundary, permission catalog, `can:` guard, route middleware, queue-context or money/quantity path is touched anywhere in the range; no `app()` resolution in either new PHP file.
- **frontend-conventions lens:** exactly one production file, type-only and narrowing to an identical type; no token, i18n, RHF, or query-key surface. **Baseline honesty holds** — P2 touches no locale file and no `i18n.ts`, so every baseline entry is inherited debt, not a `--write-baseline` absorption of the diff's own violations.
- **Scope and harness state.** 84 paths bucket cell-for-cell as §5e claims, nothing outside the allowlist, no forbidden tree, control-file preflight clean (no `adversarial-review*.sh`, brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*`, or `*control-manifest*` surrogate). YAML `base_sha` == the dispatch base, M4 `status: review`, `fix_rounds: 4` ≤ `max_fix_rounds: 5`, `control_manifest` structured, `quiet_window_ack`/`ratchet_trust_model_ack` non-null, post-promotion pins null. Deviations 1–9 are otherwise candid, including the executor substitution, the absent `actionlint`, the `deferred` third disposition, and deviation 7's admission that a candidate cannot authorise its own `max_fix_rounds` override.

---

## D. Required to clear this gate

1. **`ci.yml:204-215` — announce and verify the event-ratchet step.** Add it to `ANNOUNCE-…` §2's table and correct `:110` to six new steps / three in `backend-architecture`; add a §3-style "behaviour change" line saying the two Architecture ratchets now gate PR→dev and what reds a lane; add the step name to the S-14 lists at `ANNOUNCE-…:290-292` **and** `HANDBACK…:225-232`; record it in `DECISION-…` as its own decision with the parent ticket that authorises it; and list it in `HANDBACK…` §4 Deviations, not only in the addendum.
2. **`ANNOUNCE-…` §1 / §4 / §10 — apply the file's own inclusion rule at A.** Drop the `codex/dn-consolidation-2026-08-12` and `codex/es-wave-a0` rows from §1 (both are ancestors of `a4a8c2293`; their raises are already in the shipped manifest) and correct the "ten in-flight lanes" heading; move all four merged branches to the "already merged" note in §10; correct §4's SIX/FOUR counts and the `ci.yml:1249` citation to `:1443`.
3. **`ANNOUNCE-…:131-137`** — re-derive to A: 55 namespaces, `en-aliased` **22**. Correct `:118` to 8 tools-test files.
4. **`DECISION-…:2011-2027`** — stamp or replace the 1116 instruction and the "why not pre-raise them in the candidate" rationale, as §8 item 0 was.
5. **Minors 2–4** — refresh `ci.yml:1166-1167` (23 → 22) and `audit-i18n-completeness.mjs:788-790` (1998/4702 → 1986/4697); either stamp or correct `Accounting.classes` (79 → 81, and say the field is unenforced for `lane` groups); reword deviation 3 so "required" describes the pre-rebase state it actually refers to.
6. Per gate-r7 R7-H-1 / gate-r8 R8-H-3 this is a **substantive** CHANGES-REQUIRED: the **executor alone** fixes, commits, increments `fix_rounds` to **5**, resets M4 to `status: review`, and hands over with `git status --porcelain` showing exactly `?? <handback-source>`. This register is not to be committed into the candidate. **`fix_rounds` will then equal `max_fix_rounds: 5` — a further substantive round is `blocked_review` absent a recorded parent/owner continuation ruling.**

**Note for the parent.** Everything in §C verifies independently, by execution or re-derivation — the guards, the ratchet's fail-closed authority, the lane checker's relabelling defences, the CI wiring, the scope proof, and every census cell in §3. What blocks is one shipped CI-contract change that no lane-facing or owner-facing artifact declares, and one announcement section that bills two already-merged lanes for ceiling raises the manifest already contains. Both are in the artifact the parent sends and executes, and both are silent failures rather than loud ones.

VERDICT: CHANGES-REQUIRED

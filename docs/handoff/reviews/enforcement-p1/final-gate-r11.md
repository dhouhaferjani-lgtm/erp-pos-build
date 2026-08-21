# FINAL-GATE REGISTER — package p1 M3 round 11 attempt 2
accepted_sha: 5d7009bae149543b4c33b2b117cc3e216dfe7d4e
base_sha: e3eea67f9b3f6f79afa2613c04c81826e5852236   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p1.receipt.yaml
snapshot: detached-worktree @ 5d7009bae149543b4c33b2b117cc3e216dfe7d4e (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r11a2.md=163f580aa789956b31340380b70ff7a9f79b7fe03eadb9d48160215511d04983   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 7a5e47c2c9b32f475c8e38e646d7e5dc2befcda5c4a8904f1684b9ac9243f516
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:stock-gl-interaction=7e1c3c7c2c2dbdedcfaab8aba0ce19803e87f798fbf057a527d97a030fedaf9e
control_sha256: lens:inventory-costing=7f7691e86715d97e8c58bef1dc13b1b5218aa19841c53417e6f5651f1e5829ea
max_fix_rounds: 8
---
I have completed verification. Writing the final-gate register.

---

# ADVERSARIAL FINAL-GATE REGISTER — enforcement package p1, milestone M3, round 11

**Snapshot reviewed:** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.hvC2E2AIIn/snap`, detached at `5d7009bae149543b4c33b2b117cc3e216dfe7d4e` (re-asserted after all commands: `git rev-parse HEAD` → `5d7009bae…`, `git status --porcelain` empty; the tree is mounted read-only, `dr-xr-xr-x`).
**Range:** `e3eea67f9b3f6f79afa2613c04c81826e5852236..5d7009bae149543b4c33b2b117cc3e216dfe7d4e` — 39 commits, **17 files**, +5452/−26.
**Handback opened and evaluated:** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p1-registers/HANDBACK-enforcement-p1-2026-08-19-r11a2.md` — **1357 lines, 114820 bytes, `sha256 = 163f580aa789956b31340380b70ff7a9f79b7fe03eadb9d48160215511d04983`**. It is **byte-identical** to `…-r11.md` (same digest), which is the correct signature of a parent-owned `tool_error` same-A retry under R8-H-3: no executor commit, no `fix_rounds` increment, attempt suffix only.

---

## 0. Content-derived evidence of inspection — the required tables

Every row count and first/last row key below was extracted mechanically from the handback bytes (a table-walker over `|`-delimited blocks), not restated from its prose. Line numbers are 1-based; the header row is named, data rows follow the separator.

| Handback table | Header line | Data rows | First row key | Last row key |
|---|---|---|---|---|
| §1 Header | 11 | **7** | `Base SHA` | `Final SHA` |
| §2 M0 predicates | 30 | **7** | `1` | `7` |
| §3.1 What was built | 44 | **3** | `apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php` | `apps/api/tests/Architecture/DocumentPerActionFixtures/**` |
| **§3.5 Mechanism × table fixture coverage** (brief §7.3 required) | **116** | **10** | `create` | `raw_sql` |
| §3.5 per-gate-round cell origin | 144 | **8** | `M1 initial matrix` | `**total**` (= **133**) |
| §3.7 M1 gate history | 183 | **5** | `1` | `5` |
| §3.7b–e M1 round dispositions | 200 / 224 / 242 / 260 | **12 / 8 / 8 / 8** | `P1-1` / `P2-1` / `P2-1` / `P2-1` | `P3-12` / `P3-8` / `P3-8` / `P3-8` |
| §3.8 live-delete finding | 277 | **7** | `1` | `7` |
| **§4 Baseline ↔ DPA-register cross-check** (brief §2 deliverable 4 / §7.3 required) | **314** | **14** | `**V1** — TestE2EGLPosting hard-deletes sealed GL` | `**S0 residue** — WeightedAverageCostService reference params still ?string` |
| §4 mapped/never-covered partition | 339 | **3** | `mapped to a register item or S0 residue in the table above` | *(empty label cell — the `34 ✓` total row)* |
| §4 never-covered list | 350 | **17** | `1` · `FixOrphanedProducts.php:128` | `34` · `RepositoryTransferService.php:105` |
| **§5 Violation census — the M2 seed baseline** (brief §7.3 required) | **396** | **34** | `1` · `app/Console/Commands/FixOrphanedProducts.php:128` · stock_levels/create · `FixOrphanedProducts::executeCommand` | `34` · `app/Modules/Treasury/Application/Services/RepositoryTransferService.php:105` · journal_entries/delete · `RepositoryTransferService::transfer` |
| §6.1 two-phase seed topology | 437 | **4** | `pre` | `then` |
| §7.5 M2 round-2 P3 notes | 828 | **5** | `1 — a baseline key is a SLOT, not a WRITE …` | `5 — blind spot C lacked a pointer …` |
| §8.8 rebase targeted verification | 1016 | **4** | `UninvoicedDeliveryNoteService — dev modified one of my baselined violators` | `T21 count-correction listener + StockAdjustmentService` |
| §8.8 rebase artifact sweep | 1047 | **7** | `seed commit (§6.1, §6.3, YAML pin + m2_evidence.seed_commit)` | `Phase 4.3.7 (the round-3 CRITICAL fix…)` |
| §9 package gate history | 1256 | **4** | `M0` | `M3` |
| §9 final-gate round dispositions r1…r9 | 1266 / 1275 / 1283 / 1297 / 1305 / 1311 / 1318 | **4 / 3 / 3 / 3 / 1 / 2 / 3** | `[Important] §4's "COMPLETE list" was incomplete…` … | … `[Minor] §2 row 7 and m0_evidence.control_manifest_verified asserted…` |

**Independent re-derivation of the census — recomputed, not trusted.** I executed the snapshot's own scanner against the snapshot's `apps/api/app` tree with an external PHP-Parser autoload:

```
TOTAL SITES: 116
violation => 34 · linked => 67 · not_applicable => 15
baseline=34 violations=34   ADDED: []   STALE: []
FIRST: app/Console/Commands/FixOrphanedProducts.php::…::executeCommand::stock_levels::create#1
LAST:  app/Modules/Treasury/…/RepositoryTransferService.php::…::transfer::journal_entries::delete#1
```

`34 / 67 / 15`, the 116-site total, and "violation key set identical to the checked-in baseline in both directions" are **TRUE at the reviewed tip**, and the first/last census keys match the handback's §5 rows 1 and 34 exactly. `git rev-parse 85f0e63f3:<baseline>` == `git rev-parse HEAD:<baseline>` == `1381983d463e6c546535be907d4aa1c7ca94c597` == the YAML mirror `dpa_baseline_protected_blob`; the seed commit touches exactly one file and is an ancestor of A. **No re-seed, no re-pin is implied by anything in this register.**

**Independent re-derivation of the §3.5 figure.** I extracted `fixtureMatrix()` from the guard test's bytes and ran the scanner over the fixture tree with the production tree supplied as context, exactly as `scanFixtures()` does:

```
matrix cells: 133 · unique (class, method, table, mechanism): 133
matched: 133  failures: 0        (fixture sites emitted: 141)
```

**133** reproduces three ways and every cell classifies exactly as pinned. The eight unpinned emitted sites are the incidental `linked` `StockMovement::create` rows that supply the pairing justification inside the negative-control fixtures — all `linked`, none a hidden expectation.

---

## 1. Round-10 findings — disposition, verified against the tip

- **[Important] chained `Model::make(…)->save()` create-by-save hole — CLOSED, and closed correctly.** `receiverIsUnpersistedModel()` (`DocumentPerActionWriteScanner.php:926-950`) now walks the chain and accepts the base through the shared `isFreshModelExpr()` predicate (`:961-975`), which `buildVarTypes()` also calls (`:2321`) — the two inline copies that had drifted are now one. A bounded alias fixpoint (`:2339-2345`) propagates fresh-model state across copy assignments. I re-ran the round-10 probe verbatim against the snapshot's scanner:

  ```
  a_chained_make_then_save   JournalEntry::make([...])->save();              -> violation   (was not_applicable)
  d_chained_make_fill_save   JournalEntry::make([...])->fill([...])->save(); -> violation   (was not_applicable)
  g_alias_copy_save          $a = JournalEntry::make(...); $b = $a; $b->save(); -> violation
  k_saveOrFail_chained       JournalEntry::make([...])->saveOrFail();        -> violation
  b/c/f (already-caught controls)                                            -> violation (unchanged)
  ```

  The `:865` reason string is corrected — it now says "not a `new`/`make()` model — **directly, chained, or aliased** — anywhere in this scope" and points at blind spot I. `firstOrNew`, `replicate`, `clone` and relation-argument creates are **named** in blind spot I (`:265-281`) and in the new burn-down ticket, rather than left implicit; I confirmed each is still unrecognised in a probe (`e_firstOrNew_then_save` → `not_applicable`; `clone`/relation-arg emit no site at all) and independently confirmed **zero live instances against the four contract tables** in `app/` (`::firstOrNew(` → none; the four `->replicate(` hits are Catalog items/recipes/variants; the one `->save(new …)` is Loyalty `EarningRule`; no `clone` of a contract model). The disclosure is honest and the exposure is genuinely nil today.
- **[Minor] §3.5 caption `115`** — fixed; the caption now reads 133 and the per-round table sums to 133 (`144`–`153`), which I re-derived.
- **[Minor] §7.6 unqualified "0 errors"** — fixed in the handback in **both** places; the count is now stated as 4, up from 1, with the reason (`larastan.noModelMake` on four deliberate `make()` fixtures, `phpstan.neon` scoping `paths: app/` so the rule never runs in any lane). See finding 2 below for the one mirror that was not substituted.
- **[Minor] five stale `m0_evidence` fields at `41fb478c2`** — fixed; `:183`, `:185`, `:188`, `:190`, `:193`, `:194` now all name and evidence `e3eea67f9`.

The round-10 additions beyond the register (verb-surface completion, namespace impossibility guard) are sound: the `WRITE_METHODS` map now carries the seven previously-absent verbs each in its existing bucket, `no_scanned_file_declares_more_than_one_namespace()` (`GuardTest:428-462`) is non-vacuous (`assertGreaterThan(0, $scanned)`), and `FixtureVerbSurfaceWrites` pins model-instance receivers with the PHPStan-caught builder/SoftDeletes error corrected rather than suppressed.

---

## 2. What I verified green

- **CI wiring (deliverable 5).** `backend-dpa-guard` at `ci.yml:180`; parsed: `if` present → **False**; 8 steps; both DPA classes run **by path** under `set -o pipefail` with an explicit non-zero-selection assertion (`grep -qE 'OK \([1-9][0-9]* test'`) — correctly closing the `failOnEmptyTestSuite` hole the brief names. `fetch-depth: 0`, `memory_limit=1G`, `${{ vars.DPA_BASELINE_PROTECTED_BLOB }}` mapped into the ratchet step's env (R4-H-4), pin tag fetched explicitly and failing closed on null/empty. The `dpa_baseline_pin_tag: ci-pin/enforcement-p1-r1` is **non-null and reviewed in A** (R5-C-2).
- **Aggregate membership (gate-r1 H-9).** `backend-dpa-guard` present in `all-checks-pass` `needs` at `ci.yml:1288`, with the skipped-job-semantics reasoning comment at `:1281`. The YAML's `aggregate_membership` claim (`:180/:1281/:1288`) reproduces byte-for-byte at this SHA.
- **Ratchet, three directions.** Growth and stale in one assertion over the scanned key set; anti-growth reads the owner variable and fails closed on unset/empty, malformed hash, `git cat-file` failure, mirror drift, or any added key; the mirror is compared **to** the variable and disagreement fails. Removing or comment-decorating the mirror line makes `mirrorPin()` return `null` → still fails. Authority is never the YAML. Correct per R3-C-1.
- **Scope substance.** No production path, no control-surface path (`scripts/adversarial-review*.sh`, the brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*-reviewer.md` — none touched), no `*control-manifest*` surrogate, no `permissions:` grant in the workflow diff (R6-H-5). Rule-19 float sweep over all 17 changed files: `(float)` / `floatval` / `number_format` / `parseFloat` → **zero hits**.
- **The 15 `not_applicable` live sites remain defensible.** Five are `journal_entries` lifecycle updates (`AccountingService:506/719/946` fiscal_hash, `GeneralLedgerService:3439` posting stamp, `JournalEntryController:106`), ten are reservation/threshold soft holds against the positive allowlist (`StockReservationService`, `BatchStock:60/69`, `StockAdjustmentService:559/623`). None erases linkage.
- **Adversarial probes found no *undisclosed* hole.** A `stock_levels` write credited by a `StockMovement::create` inside a never-invoked closure classifies `linked` — that is disclosed blind spot J verbatim, and I confirmed zero live instances. A level write split across two functions correctly stays `violation`. `DB::table('journal_entries')->upsert(...)` is caught. A reassigned alias (`$a = Model::make(); $a = Model::findOrFail(); $a->save();`) over-reports as `violation` — a **fail-closed** direction that cannot admit a violation, with no live instance.
- **Two-commit seed topology intact** (R3-H-4/R4-H-3): `85f0e63f3` (seed, one file) then `2b7dc05a6` (pins + checker), both ancestors of A.
- **YAML field-check surface the bridge reads:** `max_fix_rounds: 8`, exactly one final milestone, M3 `status: review`, `fix_rounds: 8` (≤ 8), `base_sha == e3eea67f9…` (the dispatch base), structured `control_manifest: {path, sha256}` matching the manifest and receipt.

**Lens application — both-sides discipline. `stock-gl-interaction`:** dimension 1 (document-per-action) is the rule the scanner encodes, and it is now enforced on the GL side of the seam for the create-by-save class that round 10 opened. Dimension 9 (append-only ledgers) holds: `stock_movements` MUTATE/DELETE are unconditional violations and the two live post-hoc stamps are baselined (census #10 `ReverseWriteOffService::reverse:186`, #33 `ReturnScrapWriteOffService::writeOff:165`), with the GL side symmetric (census #34 `RepositoryTransferService::transfer:105`, a live `journal_entries` ROW DELETE the DPA register never caught — correctly escalated as a parent ticket in §3.8/§8.5 rather than fixed here). For every stock-side observation the GL consequence I traced is: **none of the 17 files is production code** — the range writes no `stock_movements`, `journal_entries`, `stock_levels` or `inventory_batch_stock` row, emits no event, books nothing — so dimensions 2–8 (GL-exactly-once, COGS-at-exit, event emission, single writer, WAC integrity, batch invariant, cash lane) have no diff surface, and I say so rather than passing them silently. **`inventory-costing`:** no WAC arithmetic, no `workingScale()` path, no movement-direction or lock-order logic, no FormRequest, no frontend. Scale resolution is untouched; nothing new is queue/console-reachable. Tests assert real behaviour (no `assertTrue(true)`, nothing mocks the unit under test), use no database, and match the `tests/Architecture` house style. Both lenses' remaining checklist items are inapplicable-by-construction to a guard-only diff, which this package's DO-NOT-TOUCH scope requires.

---

## 3. Findings

### [Important] `docs/handoff/progress/enforcement-p1.progress.yaml:285` — `m3_evidence.scope_proof` certifies a diff that is not the one being accepted, and certifies away the one path that the brief calls a hard scope FAIL

The field reads, verbatim:

```
scope_proof: "git diff --name-only base..HEAD -> 15 files, all inside apps/api/tests/Architecture/** |
  .github/workflows/ci.yml | docs/handoff/**; 0 allowlist violations; no control file touched;
  no production code; no workflow permissions grant"
```

At A the diff is **17 files**, and one of them is outside every glob the field names:

```
$ git diff --name-only e3eea67f9..HEAD | wc -l
17
$ git diff --name-only e3eea67f9..HEAD | grep -v -e '^apps/api/tests/Architecture/' \
    -e '^\.github/workflows/ci\.yml$' -e '^docs/handoff/'
docs/superpowers/tickets/2026-08-20-dpa-scanner-depth-burndown.md
```

So two of the field's five clauses are false: the count (15 vs 17) and, more seriously, the conjunction **"all inside … ; 0 allowlist violations"**. The brief's binding allowlist is `apps/api/tests/Architecture/**` · `.github/workflows/ci.yml` · `docs/handoff/**`, and it states the consequence in terms this field contradicts: *"any OTHER path is a scope FAIL, not a footnote."* The remaining three clauses (no control file, no production code, no permissions grant) I verified independently and they are true.

**This is not a concealment finding.** The handback discloses the widening prominently and honestly — §7.4 carries a `⚠️ ALLOWLIST WIDENED BY ONE PATH, on instruction — flagged rather than absorbed` block naming the exact file and the reason, and explicitly says a scope proof that quietly restated its own allowlist to match its diff would be worthless. The defect is that **the machine-readable record does exactly what the handback promises it will not do**: the YAML is the artifact that lands verbatim in closing commit C, and a consumer reading `m3_evidence.scope_proof` alone — which is the whole point of a machine-readable evidence block — is told the scope check passed unqualified.

**Second, structural half: the widening has no recorded authorization.** §7.4 attributes it to "the round-10 instruction". The round-10 register (final-gate-r10.md) does not direct a ticket file; the YAML's `final_gate_round10` names the ticket only as an outcome of triage. Compare the treatment the package gave the one other authority change it took: raising `max_fix_rounds` 6 → 8 got a dedicated `stop_a_owner_ruling` field carrying the owner's verbatim words, plus a mirrored manifest/receipt raise. A scope-allowlist widening is the same class of authority change and has no equivalent field. Under the brief only the parent/owner may widen scope, so the widening currently rests on prose in an untracked file.

**Failure scenario.** The parent runs the §5 step 5 closing preflight and post-commit check against the package closing set, which admits `docs/handoff/**` and `docs/handoff/LEDGER.md` — but the accepted A already carries `docs/superpowers/tickets/…`, so the *candidate's* scope FAIL is never re-tested at close (the closing check governs C's diff, not A's). C then lands carrying a YAML that asserts `0 allowlist violations` over a tree with one. P3-M0 later reads this package's landed evidence as authoritative; the false clause is now inside the digest the pin tag binds, and correcting it after the fact requires a new A.

**Fix (no code, no re-seed, no re-pin).** Either (a) move the burn-down ticket under `docs/handoff/` so the diff satisfies the brief's allowlist as written, or (b) keep it where it is and record the widening as an explicit parent/owner ruling field on M3 alongside `stop_a_owner_ruling`. Either way, substitute `scope_proof` so it states the true file count and the true allowlist actually applied, and — if (b) — says plainly that one path sits outside the brief's allowlist under a named authorization, rather than reporting `0 allowlist violations`.

---

### [Minor] `docs/handoff/progress/enforcement-p1.progress.yaml:286` — `m3_evidence.whole_package_rerun` still carries the unqualified PHPStan claim that round 10 required qualified, in the same field the round-10 fold edited

The field reads `"guard OK (6 tests) - ratchet OK (2 tests, 113 assertions) - phpstan level 8 [OK] No errors - pint pass - unset-variable isolated negative case exits non-zero"`. Handback §7.6 now correctly states `[OK] No errors, EXCEPT four instances of ONE disclosed advisory` and repeats the qualification in the section's closing prose — the exact two locations round 10's Minor named. The YAML mirror was not substituted.

What makes this the append-vs-substitute class rather than an oversight is that **the round-10 fold edited this very string**: `final_gate_round10` records "guard count 4->6 live-derived", and the field does read `6 tests`. The stale clause sat two words away from the edited one. (The same unqualified sentence also survives in the handback's §9 round-9 disposition block at `Post-fix verification`, where it is at least a historical statement about a round at which the advisory count was 1.)

**Fix:** substitute the clause to match §7.6 — `phpstan level 8 [OK] No errors except 4 disclosed larastan.noModelMake advisories on the make() fixtures (phpstan.neon scopes paths: app/, so the rule runs in no lane)`.

---

### [Minor] `docs/handoff/progress/enforcement-p1.progress.yaml:255` — M3's `commit:` pointer, and its self-describing comment, predate two rounds of code changes

```
commit: 146f1b7a111ae131ae98267af75529b4c2f10f72   # the last CODE commit after the rebase
```

`146f1b7a1` is Phase 4.3.9 ("Disclose the injected-connection raw-SQL shape in blind spot D"). Two later commits change code under `apps/api/tests/Architecture/**`:

```
b7ec2afc6  Phase 4.3.17  scanner + guard test + FixtureJournalEntryWrites   (round-9 Important)
f52e451d4  Phase 4.3.20  scanner + guard test + 2 fixtures + the ticket     (round-10 fold)
```

so the comment "the last CODE commit after the rebase" is false by two rounds. Mechanically this does not break P3-M0 — that predicate wants a recorded commit plus ancestry `M3.commit → p1_landed_sha → base_sha`, and `146f1b7a1` is an ancestor — but the field is supposed to identify the milestone tip that was reviewed, and gate-r3 R3-H-1 exists precisely because "an arbitrary ancestor can never stand in for the reviewed tip". The handback's §1 `Final SHA` is correct (`5d7009bae…`); the YAML is the half that did not move. This is the third instance of the same class in the same `milestones[M3]` block, and rounds 3 (`refresh M3's commit pointer`) and 7 (D-1, `the M3 gate record was never advanced past round 3`) each fired on it before.

**Fix:** point `commit:` at `f52e451d4` (the last code commit) or at `5d7009bae` (the handover tip), and re-derive the comment rather than carrying it.

---

### [Minor] handback §9 — the round-10 disposition and its RED-first evidence are absent from the handback, present only in the YAML narrative

§9 carries a disposition table for every final-gate round 1 through 9 (seven tables, lines 1266–1322) and, for round 9, a pasted RED-first probe block showing all four shapes classifying `not_applicable` before the fix and `violation` after. Round 10 — the largest fold in the package (three create-by-save shapes, seven new verbs, eleven verb pins, a new gate, two new blind spots, a new ticket, +14 matrix cells) — has **no §9 entry and no pasted RED-first block**; `grep` for `round 10` in the handback returns only the §1 Final-SHA note, the §2 row-2 aside, the §3.5 per-round row, and the §7.4 scope note. Its disposition exists solely as the YAML `final_gate_round10` string.

The substance is fine — I verified the fix and the pins independently, and the matrix reproduces at 133/133 — so this is a record-completeness defect, not a correctness one. But the brief makes the handback a review target and requires each acceptance criterion restated with pasted command and output, and the RED-first obligation for the three new shapes is currently asserted (`RED-first on all three shapes`, in YAML prose) rather than evidenced. The handback is also the artifact whose digest the pin tag binds and that P3(a) consumes.

**Fix:** add the §9 round-10 disposition table in the shape rounds 1–9 use, with the before/after probe output for `a_chained_make_then_save`, `d_chained_make_fill_save` and the alias shape.

---

## 4. What to fix before merge

Resolve the burn-down ticket's path — move it under `docs/handoff/**`, or keep it and record the widening as a named parent/owner ruling field on M3 — then substitute the three false strings in `milestones[M3]` of the progress YAML (`scope_proof`'s file count and allowlist conjunction, `whole_package_rerun`'s unqualified PHPStan clause, `commit:` and its comment), and add the §9 round-10 disposition with its RED-first evidence. **No code change, no re-seed, no re-pin:** the scanner, the fixture matrix (133/133), the census (34/67/15, key set byte-identical to the baseline in both directions), the seed blob `1381983d4`, the seed commit `85f0e63f3` and the tag `ci-pin/enforcement-p1-r1` are all correct at this tip and I reproduced each independently.

Lens outcome — **stock-gl-interaction**: spec ✅ / quality CHANGES-REQUESTED (the dimension-1 document-per-action contract is now correctly enforced on both sides of the seam, including the GL-side create-by-save class round 10 opened; the objection is to the durable scope certification, not to the guard). Lens outcome — **inventory-costing**: spec ✅ / quality APPROVED (no costing, movement-direction, scale or lock-order surface in the range; the stock-side rules are correctly encoded and independently reproduced).

VERDICT: CHANGES-REQUIRED

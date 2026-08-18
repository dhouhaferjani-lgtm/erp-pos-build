# Spec-Gate Round 0 — Mechanical Precheck

**Status: MANDATORY.** Every spec, brief, or plan MUST pass round 0 before an adversarial review gate is dispatched. A document that has not passed round 0 is not gate-ready, period.

## Why this exists

In the 2026-08-11 window, spec gates burned 5–8 rounds each:

- DN-consolidation spec: 8 rounds (`docs/superpowers/reviews/2026-08-11-spec-dn-consolidation-gate-r1..r8.md`)
- POS-receipts spec: 5 rounds (`docs/superpowers/reviews/2026-08-11-spec-pos-receipts-gate-r1..r5.md`)
- Wave-0 brief: 3 rounds (`docs/superpowers/reviews/2026-08-11-wave0-brief-gate-r1..r3.md`)

Post-mortem: the round-multipliers were NOT deep design flaws. They were five recurring classes of claim that are **mechanically checkable without judgment** — stale revision-log claims, unproven "exhaustive" inventories, non-executable test contracts, uncited framework-behavior assertions, and unverified permission keys. An expensive adversarial reviewer spent whole rounds catching what a grep would have caught. Round 0 moves those checks in front of the gate.

## Scope and when to run

- Run on: any document about to be sent to an adversarial gate (specs, dispatch briefs, remediation plans, milestone re-gates).
- Run **after every revision**, immediately before requesting the gate — a round-0 pass on rev N does not carry to rev N+1.
- Round 0 checks **claims against reality** (document text, repo code, command output). It does not judge design quality — that stays with the adversarial gate.

## How to run

1. Dispatch round 0 to a cheap/mechanical model (Sonnet-tier is fine), or execute it yourself in the authoring session. It needs only Read/Grep/Glob/Bash — no design judgment.
2. The runner executes checks 1–5 plus the hygiene sub-check, producing the report in the output format below.
3. **Attach the round-0 report to the gate dispatch.** The adversarial reviewer starts from "mechanical claims verified" and spends its round on substance.
4. **HARD RULE: round-0 FAIL = fix the document, then re-run round 0 from the top.** Never send a document with a failing round-0 report to the adversarial gate. Never "note the failure and dispatch anyway."

---

## The five checks

### Check 1 — Revision-log truth

Every "fixed" / "applied" / "addressed" claim in the revision log must correspond to a visible change in the current document text.

**Procedure:**
1. Extract every claim from the revision log (each "R{n}-{m} fixed in §X" style entry).
2. For each claim, open the named section and confirm the described change is actually present in the current text. If prior revisions of the file exist (git history, gate-round report quoting the old text), diff against them.
3. A claim whose named section shows no corresponding change = **FAIL**. A claim naming a section that doesn't exist = **FAIL**.

**Report:** table of claim → section → verified yes/no. Every row must be listed, not sampled.

### Check 2 — No unproven "exhaustive/complete"

Every use of *exhaustive*, *complete*, *all*, *every*, *full*, or a literal count attached to an inventory — lock lists, wire allowlists, filter matrices, call surfaces, event catalogs, endpoint lists — must ship with the **mechanical census command** (grep/glob/SQL) that produced it AND the count that command returned.

**Procedure:**
1. Grep the document for exhaustiveness words and literal counts (`grep -inE 'exhaustive|complete|all [0-9]|every |[0-9]+ (call sites|endpoints|events|routes|tables|keys)'` is a starting net, then read context).
2. For each hit that qualifies an inventory: prose-only exhaustiveness (no census command shown) = **FAIL**.
3. For each census command present: **re-run it now**. Output count ≠ documented count = **FAIL** (the codebase moved under the spec).

**Report:** each inventory → census command → documented count → re-run count → match yes/no.

### Check 3 — Executable test contracts

Every test contract in the document must be physically runnable as written.

**Procedure:**
1. Each test contract must state its exact runnable command — `cd apps/api && ./vendor/bin/phpunit path/to/Test.php --filter test_name`, `pnpm vitest run path`, exact playwright invocation. A contract with no command = **FAIL**.
2. Check physical executability of the scenario itself:
   - No impossible interleavings — e.g. a test that requires a second connection to read/write rows the first connection holds locked (`lockForUpdate` inside an open transaction) in a sequence that cannot be scheduled.
   - No synchronization barriers on rows that are lazily created — you cannot "wait for" a row that the code under test only creates after the barrier point.
   - No mocks asked to prove what they mask — a mocked `t()` cannot prove a locale value survived rendering; a mocked API client cannot prove response-envelope handling; a mocked scale resolver cannot prove currency-scale correctness.
3. **Flag any test asserting through a mock of the thing under test** = FAIL for that contract.

**Report:** contract → command present yes/no → physically executable yes/no → mock-of-subject flag.

### Check 4 — Framework/repo behavior claims cite source

Any claim about what Laravel, PHPStan, Postgres, or this repo *does* must cite a vendor or repo `file:line`.

Claim classes that MUST carry a citation (non-exhaustive):
- Eloquent mechanics: `fillable` vs `HasUuids` interaction, cast behavior, event ordering.
- Transaction semantics: savepoints, nested `DB::transaction`, deferrable FKs, lock behavior.
- PHPStan: which AST node types a custom rule visits, which paths are in the analysed set (`phpstan.neon` includes/excludes).
- Fail-open vs fail-closed behavior of any guard, middleware, or permission check.
- "The repo already does X" / "no caller does Y."

**Procedure:** grep the document for mechanism assertions; each one without a `file:line` (vendor path or repo path) = **FAIL**. Spot-open at least the load-bearing citations and confirm the cited line says what the document claims — a wrong citation = **FAIL** same as a missing one.

**Report:** claim → citation present yes/no → citation verified yes/no.

### Check 5 — Permission keys grep-verified

Every permission key and module key named in the document must exist where the spec assumes it does.

**Procedure:**
1. List every permission/module key string in the document.
2. Grep each against `apps/api/database/seeders/RolesAndPermissionsSeeder.php` AND the actual route middleware in the relevant module `routes.php` (`permission:`, `module:` middleware strings).
3. For frontend module keys: grep `MODULE_PERMISSIONS` in `apps/web/src/hooks/usePermissions.ts`.
4. **Known trap:** `canAccessModule` **fails OPEN** for unknown keys — `apps/web/src/hooks/usePermissions.ts:130-134` returns `true` when the key is absent from `MODULE_PERMISSIONS`. So an unknown key does not visibly break anything in dev; it silently un-gates the feature. **An unknown key is a defect, not a gate** = FAIL.

**Report:** key → found in seeder yes/no → found in route middleware yes/no → found in MODULE_PERMISSIONS yes/no (where applicable).

---

## Document-hygiene sub-check

Fast, always run, part of the same report:

- **No unescaped GFM pipes** in normative tables — a literal `|` inside a cell (e.g. in a regex or type union) must be `\|` or the table silently loses columns. Procedure: render-check or grep table rows for cell counts that don't match the header.
- **Status banner matches the latest revision log entry** — the banner at the top (rev number, status, date) must agree with the last row of the revision log. Mismatch = FAIL.

---

## Output format

The round-0 report is a single markdown file placed next to the gate-round reports (`docs/superpowers/reviews/<date>-<doc>-round0.md`).

```markdown
# Round 0 report — <document path> @ <rev / git hash>
Runner: <model / session> · Date: <date>

| # | Check                         | Result    | Findings |
|---|-------------------------------|-----------|----------|
| 1 | Revision-log truth            | PASS/FAIL | n found  |
| 2 | Exhaustive claims proven      | PASS/FAIL | n found  |
| 3 | Test contracts executable     | PASS/FAIL | n found  |
| 4 | Behavior claims cited         | PASS/FAIL | n found  |
| 5 | Permission keys verified      | PASS/FAIL | n found  |
| H | Hygiene (pipes, banner)       | PASS/FAIL | n found  |

## Findings
- [R0-1] <check#> <severity> — <claim / location in doc> — <what the mechanical check showed> — <exact command run + output where applicable>
- [R0-2] ...

## Evidence appendix
<census commands re-run, with raw output counts>
```

**Verdict rule:** any single FAIL row = the document FAILS round 0. Fix, bump the revision log honestly (check 1 will verify it next pass), re-run round 0 in full. Only a clean all-PASS report accompanies a gate dispatch.

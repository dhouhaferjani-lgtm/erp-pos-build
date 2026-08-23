# Adversarial merge gate — round 2 — `fix/b3-pos-enabled-claim-enforcement`

- **Lane tip reviewed:** `e391253a0` (= r1 tip `7b01949f9` + 1 fix commit)
- **Fix diff audited:** `7b01949f9..e391253a0` — **16 files, +454/−16** (the brief and the lane report
  "15 files +260/−16"; the delta is *exactly* the new 194-line FE test file: 16−1 = 15, 454−194 = 260.
  The undercount is an accounting slip in the lane's own stat, not an extra file — see M-4)
- **Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/b3-pos-enabled` (read-only; every probe
  restored, `git status --porcelain` empty and re-verified after each)
- **Scope of this round:** ONLY the fix diff against r1's findings. r1's verified core (refusal paths, backfill
  predicate shape, provisioning flip, red-first) is not re-litigated.

## Class / module resolution — proved against the WORKTREE, both stacks

- **PHP** (`ReflectionClass` inside the worktree's `bootstrap/app.php`): `TerminalController`,
  `VirtualAdminTerminalResolver`, `TerminalType`, `Location` all resolve to
  `/…/.worktrees/b3-pos-enabled/apps/api/app/…`. `TerminalType::cases()` = `Web=web`, `Physical=physical`,
  `VirtualAdmin=virtual_admin` — **exactly three**.
- **Frontend** (the lane hard-linked `node_modules` entries from the main checkout, so this needed proving,
  not assuming) — two independent probes:
  1. **Revert-probe**: `git checkout 7b01949f9 -- apps/web/src/pages/POS/Terminals.tsx` → the new test file goes
     **3 failed / 3**; restored → **3 passed / 3**.
  2. **Source-edit probe on an `@/`-aliased module**: injected `if (true) return false;` at the head of
     `apps/web/src/lib/api.ts:51` (`isApiError`) → **exactly 1 failed / 2 passed** (the error-mapping case, the
     only one that touches the guard); reverted → clean.
  Both flip on WORKTREE edits, so the page **and** its `@/`-aliased imports resolve from the worktree.
  (Third-party packages resolve from the shared pnpm store at `apps/erp/node_modules/.pnpm/…`, which is correct
  and irrelevant to source provenance.)

---

## 1. Evidence — what I ran, in this worktree

| Check | Result |
|---|---|
| `BackfillLocationPosEnabledB3MigrationTest` + `TerminalLocationPosEnabledTest` (sqlite) | **28 passed, 1 skipped, 76 assertions** (17 migration incl. the PG-only skip + 12 terminal) |
| `BackfillLocationPosEnabledB3MigrationTest` (**PostgreSQL**, throwaway `autoerp_b3gate_r2` @5433) | **17/17, 50 assertions** — savepoint case ran, **zero skips** |
| New FE file `Terminals.posEnabled.test.tsx` | **3/3** |
| `src/pages/POS` vitest | **8/8 (2 files)** |
| `src/features/pos` + `src/pages/POS` + `src/features/locations` vitest | **473/473 (58 files)** |
| `DemoTenantSeederTest` | **13/13, 77 assertions** |
| `DemoPharmacySeederTest --filter=warehouse` | **2/2, 23 assertions** — warehouse still `pos_enabled = false` at `:142`/`:154`, shops true at `:162` |
| `CreateCompanyTest` + `AuthenticationTest` (full classes) | **52/52, 232 assertions** |
| `php tools/feature-lane-manifest-check.php` | **OK** — "every `--filter` entry is anchored and uniquely matched against 1754 test classes"; the two new entries are inside that assertion |
| PHPStan, the 4 in-scope `app/` files | **[OK] No errors** |
| Pint `--test`, all 12 changed PHP files | `{"result":"pass"}` |
| `tsc --noEmit` (apps/web) | **exit 0** |
| `audit-design-system.mjs` | 807 acknowledged, **0 new** |
| `audit-tanstack-keys.mjs` | 0 acknowledged, **0 new** |
| **`audit-i18n-completeness.mjs`** (authority pinned per its own §LOCAL block) | ❌ **exit 1 — 2 NEW gaps** → **B-1, the blocker** |

### Red-first / non-vacuity probes I ran myself

- **P3-7 (provisioning pins).** Flipped `CompanyController.php:132` and `AuthController.php:442` back to
  `pos_enabled => false` → `CreateCompanyTest.php:145` **fails** and `AuthenticationTest.php:305` **fails**,
  one each. Restored → 52/52 green. Both pins bite.
- **P3-6 (fail-closed).** Deleted `->where('company_id', $companyId)` from `locationHasPosEnabled()`
  (`TerminalController.php:648`) → **exactly 1 failed / 11 passed**, and the failure is the new
  `test_claim_fails_closed_when_the_terminal_points_at_another_companys_location`. Restored. The case is
  non-vacuous and isolates the predicate it claims to guard: it arranges a second `Company` in the same tenant,
  a **POS-enabled** foreign location, a terminal in company A pointing at it, then asserts 422
  `LOCATION_POS_DISABLED` **and** `hardware_identifier` still null (`TerminalLocationPosEnabledTest.php:161-198`).
- **Inherited red.** `DemoPharmacySeederTest::test_seeds_gl_consistent_partner_balances` fails at `:291`/`:276`
  on the tip; reverted `apps/api/app` + `apps/api/database` to base `dbfa347e5` → **fails identically**.
  Confirmed inherited, not attributable to this lane.
- **Manifest coverage-debt warning** ("1 group / 1 class") — reproduced identically with `ci.yml` reverted to
  `7b01949f9`. Pre-existing, not introduced by the r2 ci.yml edit.

---

## 2. r1 findings — disposition

### P1-1 — CLOSED (and it survives the attacks I could think of)

`migration:184-189` now reads `->whereIn('type', self::TILL_TERMINAL_TYPES)`, with
`private const TILL_TERMINAL_TYPES = ['physical', 'web'];` at `:140` behind a 17-line docblock (`:122-139`).

- **Behaviour pinned both ways**, and both ran on **real PostgreSQL** as well as sqlite:
  `test_a_virtual_admin_terminal_is_not_evidence_that_a_location_sells` (warehouse + `virtual_admin` terminal,
  plus a second location so branch (b) cannot rescue it → stays false) and `test_a_web_terminal_is_still_evidence`
  (the contrast case → true).
- **Attack: any other terminal type today?** No. `TerminalType` has exactly three cases (resolved and enumerated
  above); the allowlist covers both non-server types.
- **Attack: legacy rows with `NULL`/empty `type` would silently stop being evidence** — this would strand a real
  pre-2026-02-19 till behind `LOCATION_POS_DISABLED`, the precise failure the migration exists to prevent.
  **Closed:** `2026_02_19_000002_add_type_to_pos_terminals.php:16` adds the column as
  `string('type', 20)->default('physical')` **NOT NULL**, so every pre-existing row was backfilled to `physical`.
  No row can carry `NULL`/`''`. Verified by reading the migration, not assumed.
- **Attack: a future enum case defaults to NOT-evidence.** True, and it is the fail-safe direction: a new type
  under-enables (operator fixes it in Settings — repairable) rather than over-enables (permanent, per r1's own
  reasoning). See **M-1**: the docblock justifies the *literal-string* choice well but never states this direction.

### P1-2 — CLOSED on the two things r1 asked for; one branch of the new logic is unpinned (**M-2**)

- **Offer side.** `Terminals.tsx:68-70`:
  `locations.filter(l => l.posEnabled || l.id === editingTerminal?.location_id)`. Only the **exact** location of
  the terminal being edited is exempted — other disabled locations are **not** offered in edit mode either.
  In create mode `editingTerminal` is null, so `l.id === undefined` never matches and the list is strictly
  POS-enabled. Empty-state at `:283-289` is create-mode-only (`hasNoPosEnabledLocations` at `:72` guards on
  `!editingTerminal`), so editing a terminal at a disabled location shows the dropdown with its real value and no
  spurious warning. Logic is correct as read.
- **Error mapping** (`:111-124`) follows the cited convention **exactly**: `isApiError(err) && err.response?.status
  === 422 && err.response.data.error.code === 'LOCATION_POS_DISABLED'` → translated key + early `return`, mirroring
  `PartnerDetailPage.tsx:274-281` (`PARTNER_HAS_DOCUMENTS`, 409). **No runtime shape hazard:** `isApiError`
  (`lib/api.ts:50-56`) itself asserts `data?.error !== undefined`, so the `.error.code` deref cannot throw inside
  the catch on an off-shape 422.
- **i18n**: `en/pos.json` and `fr/pos.json` are **+2 lines each, no reserialization churn** (diff verified) — but
  see **B-1**: the `ar` claim was checked for *presence* and not against the repo's i18n **ratchet**.

### P2-3 — CLOSED (flips + citations correct); the flips themselves are unpinned (**M-3**)

`DatabaseSeeder.php:269` `false→true` and `DemoTenantSeeder.php:1220` key **added** explicitly, both with the
"Owner ruling B-3 (2026-08-23) + its parent-delegated provisioning sub-ruling" comment and the correct rationale
(seeders run after `tenants:migrate`, so the backfill cannot repair them). `DemoTenantSeederTest` 13/13 and the
pharmacy warehouse cases 2/2 both green, warehouses still false.

### P2-5 — CLOSED

`git diff … -- .github/workflows/ci.yml | grep -c '^@@'` = **2 hunks exactly**: one comment block appended in the
r2f4/dpa-v8 token style (including the PG-is-load-bearing rationale, the "remove when the lane gate flips"
instruction, the **S-17 caveat**, and the S-14 dispatch-verification note), and the `--filter` line gaining
`|TerminalLocationPosEnabledTest|BackfillLocationPosEnabledB3MigrationTest` at the tail. The manifest checker's
own success line asserts *"every `--filter` entry is anchored and uniquely matched"* against 1754 classes, so the
two new names are covered by checks C **and** D, not merely appended. `frontend-lint`-style liveness: the
`backend-pgsql` job is not parked.

### P3-6 / P3-7 / P3-9 — CLOSED. P3-8 — **NOT fully closed** (see M-5)

P3-9's amended docblock (`migration:87-97`) now states the weaker, true claim explicitly ("on the FIRST run a
matching location IS enabled even if somebody had unticked the box before this deploy … until this deploy the flag
was decorative"). Good. P3-8: the commit body claims the delegation "is now cited by description in **all eight
places**" — a repo-wide grep of every lane-touched file finds **one bare `(A2)` survivor**.

### Residuals (brief item 6) — where they actually live

| Residual | Recorded in |
|---|---|
| P2-4 device picker still lists POS-disabled locations (cosmetic; server refuses at submit) | **commit message only** |
| P3-10 shift-open not gated → B-3 stays OPEN until D-1 | **commit message only** |
| Web-terminal asymmetry (existing web terminals blocked at next acquisition; device terminals keep selling) | **r1 review file only — absent from both commit messages** |

No `docs/` deliverable, ledger row or promotion-checklist entry ships in the lane (the full lane diff
`dbfa347e5..e391253a0` contains **no** `docs/` file). r1 explicitly asked that these not be left in a commit
message. **The parent must carry all three — including the web-terminal asymmetry, which is currently recorded
nowhere in the lane's own deliverables — to the LEDGER at merge.**

---

## 3. Findings

### [BLOCKER] B-1 — the fix commit turns a LIVE, non-parked CI job red: `apps/web/src/locales/en/pos.json:162-163`

The two new English keys have no Arabic counterpart, and `ar.pos` is **wired** (a spread, not an English alias),
so the repo's i18n completeness ratchet classifies them as `missing` — the one classification that is per-key.

Reproduced with the checker's own documented authority setup (seed blob `26a9ae1688d80e0f450215326b19ccd1701c9a8f`,
read from the mirror pin at `docs/handoff/progress/enforcement-p2.progress.yaml:73`):

```
$ I18N_BASELINE_PROTECTED_BLOB=26a9ae16… node tools/audit-i18n-completeness.mjs
i18n completeness — 2 NEW gap(s) not in the baseline:
  ✗ ar|pos|missing|terminal.locationPosDisabled
  ✗ ar|pos|missing|terminal.noPosEnabledLocations
exit 1
```

**Attribution probe:** reverted `en/pos.json` + `fr/pos.json` to `7b01949f9` and re-ran →
`i18n completeness OK — 55 namespaces … 2763 known gap(s) held at the baseline`, **exit 0**. Restored, clean.
The failure is caused by this commit and nothing else.

**Why it blocks rather than being a nit:**
- The step is `.github/workflows/ci.yml:2266` (`pnpm audit:i18n`) inside job `frontend-lint` (`:2222`), which has
  **no `if:` guard**, runs on `ubuntu-latest` (not self-hosted, not parked behind `SELF_HOSTED_RUNNER_READY`), and
  is listed in **both** `all-checks-pass` `needs:` (`:2568`) and `EXPECTED_JOBS` (`:2584`). It fails on the first
  CI event this branch sees.
- The ratchet is **anti-growth by construction**: the baseline file is candidate-editable but the authority is the
  owner-set `I18N_BASELINE_PROTECTED_BLOB`, and *any key added relative to that blob fails*. So the usual
  "baseline it" escape hatch is deliberately closed — this cannot be waived on the lane.
- Under S-17 (CI unobserved on this branch) nobody would learn this until after promotion, which is exactly the
  failure mode this gate exists to prevent. r1 checked only that `ar/pos.json` has no `terminal` block (true) and
  concluded no `ar` edit was needed; the ratchet disagrees.

**Fix — and it has a trap.** Translate the two keys into `apps/web/src/locales/ar/pos.json` (the file is 496 bytes
with no `terminal` block). But `src/lib/i18n.ts:388-392` spreads `pos` **shallowly**:

```ts
pos: {
  ...enPos,
  ...arPos,
  transactions: { ...enPos.transactions, ...arPos.transactions },   // :391
},
```

A new 2-key `terminal` object in `arPos` will **shadow the whole English `terminal` block** for Arabic at the
object level — that is precisely why `transactions` carries an explicit per-block deep merge at `:391`. So the
correct fix is *both*: add the translated keys to `ar/pos.json` **and** add
`terminal: { ...enPos.terminal, ...arPos.terminal }` alongside the `transactions` line. Re-run
`node tools/audit-i18n-completeness.mjs` with the pinned blob (must return exit 0) and re-run the FE suite.

### [MINOR] M-1 — `migration:122-139` — the `TILL_TERMINAL_TYPES` docblock never states the future-case direction

It justifies the literal-string choice ("a migration is a historical record") and enumerates today's three enum
cases, but does not say what happens when a fourth case is added: it silently becomes **not** evidence. That is
the correct, fail-safe direction (under-enable is operator-repairable in Settings; over-enable is permanent, per
this migration's own reasoning) — but a future reader deciding whether to touch the constant has to re-derive it.
One sentence in the existing docblock closes it. Not a merge blocker.

### [MINOR] M-2 — `Terminals.tsx:68-70` — the edit-mode exemption, the subtlest branch of the new filter, has no test

The 3 new FE cases cover create-mode filtering, the empty state, and the error mapping. **None** exercises
"editing a terminal whose current location is POS-disabled keeps that option (and only that option)". The lane's
own commit body names the consequence of losing it: a blanked dropdown that "force[s] an unintended move" — i.e.
a silent terminal relocation on save, on an admin path. Same class as r1's P3-6/P3-7: correct code, unpinned.
A fourth case with `editingTerminal` set is cheap.
(Related, informational: the FE now declines to offer disabled locations on **edit** while the server's
`PATCH /pos/terminals/{id}` does not refuse a move to one — an FE-stricter-than-BE asymmetry, safe direction,
consistent with r1's finding that the update path is not an acquisition bypass.)

### [MINOR] M-3 — neither seeder flip is pinned by an assertion

Complete grep of `pos_enabled` across `apps/api/tests/` (66 matches, all inspected): the only seeder-side
assertions are `DemoPharmacySeederTest.php:142`, `:154`, `:162`. Nothing asserts that `DatabaseSeeder`'s or
`DemoTenantSeeder`'s `MAIN` location is POS-enabled, so either can flip back as silently as the three provisioning
writers could before P3-7 closed that hole. Both are demo/dev paths, hence Minor — but the r2 round pinned the
production writers precisely because "flips back silently" is unacceptable, and the same argument applies at lower
stakes here. `DemoTenantSeederTest` already runs the seeder; one assertion rides free.

### [MINOR] M-4 — accounting: the fix commit is 16 files / +454, not 15 / +260

`git diff --stat 7b01949f9..e391253a0` = **16 files changed, 454 insertions(+), 16 deletions(-)**. The difference
from the reported figure is exactly the new `Terminals.posEnabled.test.tsx` (194 lines): 16−1 = 15, 454−194 = 260.
The extra file is the *required* P1-2 test, so this is a reporting slip, not scope creep — but the parent should
carry the true stat into the ledger. **Scope itself is clean:** all 16 files are the expected surface (migration +
its test, FE page + test + 2 locale files, 2 seeders, 3 pin tests, 3 provisioning writers comment-only, ci.yml),
and **`TerminalController.php` is untouched by this round** as required (it appears only in the r1 commit).

### [MINOR] M-5 — P3-8 is 7-of-8: one bare `(A2)` citation survives, in a permanent artifact

`apps/api/database/migrations/tenant/2026_08_23_120000_backfill_location_pos_enabled_b3.php:60`:

```
 *      `type = 'shop'`, `is_default = true`, `code = 'MAIN'`, and the same
 *      ruling (A2) flips those writers to `pos_enabled = true` going forward.
```

This is the exact collision r1 flagged — the owner sheet's row **A-2** (`:13`) is the unrelated
`adversarial-review.sh` verdict-parse hardening — and it now lives in a **migration docblock**, the artifact least
likely to ever be revisited. (The other grep hit,
`TenantProvisioningServiceTest.php:139`, is legitimate: it is the sentence *explaining* why "A2" is not used.)
The commit body's claim "cited by description in all eight places" is therefore inaccurate by one.

### Informational — PHPStan scope

`phpstan.neon:6-7` analyses `paths: app/` only. The migration, both seeders and all six test files are **outside**
the static-analysis gate; my clean `[OK] No errors` covers the four `app/` files only. Pre-existing project
configuration, not a lane defect, but do not read "PHPStan green" as covering the migration.

---

## 4. What to fix before merge

**One blocker.** Translate `terminal.locationPosDisabled` / `terminal.noPosEnabledLocations` into
`apps/web/src/locales/ar/pos.json` **and** add `terminal: { ...enPos.terminal, ...arPos.terminal }` next to the
`transactions` deep-merge at `src/lib/i18n.ts:391` (a bare `ar` block would shallow-shadow the other English
`terminal.*` strings), then re-run `node tools/audit-i18n-completeness.mjs` with
`I18N_BASELINE_PROTECTED_BLOB=26a9ae1688d80e0f450215326b19ccd1701c9a8f` until it exits 0 — the ratchet is
anti-growth, so baselining it is not an option, and `frontend-lint` is an ungated, all-checks-pass-required job.
M-1/M-2/M-3/M-5 are one-liners that should ride the same commit. At merge the parent must carry **three**
residuals to the LEDGER — P2-4, P3-10, and the web-terminal asymmetry, which is recorded in no lane deliverable at
all — plus the corrected 16-file/+454 stat and the S-14 dispatch-verification leg that the ci.yml edit triggers.

VERDICT: CHANGES-REQUIRED

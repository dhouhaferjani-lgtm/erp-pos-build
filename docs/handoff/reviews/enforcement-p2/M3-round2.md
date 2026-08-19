## Adversarial merge-gate register — P2-M3, round 2

**Scope reviewed:** brief §3 "2(a)" + the `p2-M3` milestone line (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:306-317,391`) and the M3 YAML title (`docs/handoff/progress/enforcement-p2.progress.yaml:256`). Round-2 delta = `7d64975d1` + `e8c4a427e`; M3's whole footprint is four docs files (`git diff --name-only 0fce1206d~1..HEAD`), no code. Amending authority: none.

**Lens — frontend-conventions:** no `apps/web` code in M3, so components/tokens/RHF/tenant-keys do not apply. Where the lens bites is the i18n contract the checklist asserts to other lanes: re-verified 56 namespaces (`apps/web/src/lib/i18n.ts:459`) and the ui-wave0 (16) / dn-consolidation (4) locale-file counts — §3(a) is unchanged and still correct.

---

### 1. P1 — CONFIRMED — the new "complete open-lane roster" is silently filtered to `codex/*`; eight open lanes with measured impact have no line
`ANNOUNCE-enforcement-p2-ci-contract-change-2026-08-19.md:240-260` (header: *"Complete open-lane roster — every lane gets a line"*; `:242-244`; and `:57-60`: branch enumeration *"is the only complete roster"*)

The round-1 P1-2 root cause was an unstated enumeration filter. The fix swapped worktrees for branches — and then applied a second unstated filter: every one of the 13 rows is a `codex/*` branch. Unmerged branches with **live registered worktrees** and real impact vs `c97e0d1ad` (`git diff --diff-filter=A ... | grep tests/Feature`, `git worktree list`):

| branch (worktree) | added Feature classes | locale files |
|---|---|---|
| `feat/scan-vat-configuration` (`erp.scan-to-doc`) | +1 `Taxation` (deferred, ceiling 31) | 3 |
| `feat/dpa-v8-supplier-goods-return` (`erp.dpa-v8`) | +2 `Inventory` | 2 |
| `feat/supplier-invoice-ocr` (`erp.ocr-docs`) | +1 `Inventory` | 0 |
| `fix/r2d-bcmath-hardening` (`erp.fix-r2d`) | +2 `Modules` | 0 |
| `fix/r2f2-cancel-flow-prompt` (`erp.fix-r2f2ux`) | +1 `Document` | 3 |
| `feat/owner-dashboard-demo` (`erp.dashboard-demo`) | +1 `Seeders` | 0 |
| `l6-integration-verify` (`erp.l6-verify`) | +2 `Http`, +4 `Modules`, +2 `Tenant` | 2 |
| `feat/rafiq-skin-experiment` (`erp.rafiq-skin`) | 0 | 3 |

All eight groups are `deferred` in `apps/api/tests/feature-lane-manifest.json`. Failure scenario: `feat/scan-vat-configuration` — recorded as an active, dispatch-pending lane — rebases onto the landed P2 and `backend-architecture` fails with `COVERAGE DEBT GREW: group "Taxation" now holds 32 class(es), ceiling is 31.`, having received no line in the document written to prevent exactly that, whose §10 header promises it did. Either add the rows, or state the inclusion rule explicitly and defend it (`codex/*` dispatched lanes only) — silence is what the section itself calls out at `:262-264`.

### 2. P2 — CONFIRMED — §4 was headline-patched only; two of the six `ci.yml` writers still have no merge-order row, and the OpenAPI row still carries the wording §(84) identified as the P1-2 error
`ANNOUNCE-…:114-121`; `DECISION-…:1882-1886` (§80), `:1939` (§82) vs `:1990-1998` (§85)

The heading now reads *"SIX lanes write `ci.yml`; FOUR rewrite the same `needs:` line"*, but the table below it still lists four lanes: `es-wave-a0` and `enforcement-p1-dpa-guard` have no row and no position in the merge order. The OpenAPI row (`:121`) still says *"none at P2's base … No conflict today"* and instructs it only to *"add your own jobs to `all-checks-pass` `needs` yourself"* — while `git diff c97e0d1ad...codex/openapi-contract-a-to-z -- .github/workflows/ci.yml` shows it already adds `backend-openapi-contract` **and** rewrites the `needs:` line (2 edits of `needs: [`). §(80) still says *"P2, UI Wave 0 and dn-consolidation all edit ci.yml, and P2 and UI Wave 0 both edit the `needs` list"*; §(82) still says *"the **three-lane** `ci.yml` reconciliation order"*. Failure scenario: the parent sequences the merge from §4's table (the only ordered artifact) and gets a four-way conflict on `ci.yml:1249` with two writers unsequenced; the correct count survives only in §10's closing paragraph and in §(85).

### 3. P2 — CONFIRMED — §10's actions over-instruct: "raise both/five ceilings" for groups the lane never grows, and the checker never reports the resulting slack
`ANNOUNCE-…:249-253`; `apps/api/tools/feature-lane-manifest-check.php:337-346`

The "Feature tests" column counts **changed** files, not **added** classes, and the action column converts that into ceiling instructions. Measured additions (`--diff-filter=A`): `dn-consolidation` = 9 `Document`, **0 `Partner`**; `es-wave-a0` = 6 `Fiscal`, **0 `POS`**; `enforcement-p1` = 0. The ceiling test is `count($members) > $entry['classes']` — a raised ceiling with no matching class is **never** flagged, not even as a note. Failure scenario: `es-wave-a0` follows its row and bumps `POS` 143 → 144; a real POS class lands later and joins the laneless hole silently — reintroducing precisely the slack the executor argued against at `ANNOUNCE-…:199-201` and that round 8 verified absent.

### 4. P2 — CONFIRMED — the §10 methodology sentence is false for lanes that have merged `dev`, and it mis-assigns work to two lanes
`ANNOUNCE-…:242-244` (*"so it is that lane's **own** changes"*), rows `:251,253`

`git merge-base --is-ancestor dev codex/enforcement-p1-dpa-guard` → true, and `git diff --diff-filter=A --name-only dev...codex/enforcement-p1-dpa-guard | grep tests/Feature` → **empty**: P1 adds zero Feature classes of its own. Its "23 (same set as 3D)" is 3D's work, inherited through `dev`. `codex/dpa-wave3-3d` is itself already an ancestor of `dev` — its four added classes (`CountCorrectionGlPostingTest`, `ChartOfAccountsParityTest`, 2 × `Accounting`) are the very drift §8 item 0 tells the parent to re-baseline — yet §10 still hands it an action. Failure scenario: `enforcement-p1` follows its row, raises five ceilings for classes it never authored, and double-raises against the parent's §8-item-0 re-baseline (`Inventory` → 107 against an actual 106) — permanent, unreported slack.

### 5. P2 — CONFIRMED — the corrected owner proposal is still certified-by-omission, one level out: Option 1 excludes `chokepoint-gate`
`DECISION-…:1918-1920`; `.github/workflows/ci.yml:60` (job `chokepoint-gate`, **no `if:`**), `:1249` (`needs`, 13 members, `chokepoint-gate` absent)

Round-1 P2-5 was fixed *within* the aggregate's `needs` (13 members, 3 gated off, `pos-test` runs — all re-verified correct). But Option 1 recommends `all-checks-pass-dev` with *"`needs` exactly the **ten** jobs above"*, and that universe is "members of the existing aggregate". `chokepoint-gate` has no `if:`, so it runs on PR→dev, and it is not an aggregate member — so it is not in the ten. Failure scenario: the owner adopts the recommended option as the single required check for `dev` and retires the hand-maintained job list; the §14.3 `SALE_RECEIPT`/`ACCOUNT_CHARGE` chokepoint gates and the Pass-2B sequencing sentinel (`ci.yml:88-103`) silently stop gating dev merges. The correct derivation for a *new* aggregate is "every job that runs on PR→dev", not "every current member that runs on PR→dev".

### 6. P3 — CONFIRMED — the roster has no merge-state column
`ANNOUNCE-…:248-260`

Seven of the 13 rows are already ancestors of `dev` (`dpa-wave3-3c`, `dpa-wave3-3d`, `country-defaults-phase-a`, `sv-stage1`, `pos-receipts-2026-08-12`, `accounting-gaps-cghi`, `tenant-impersonation`). Harmless for the "Nothing to do" rows, but it is the mechanism behind finding 4: a landed lane cannot perform the action it is given, and a reader cannot distinguish landed from open.

---

### Bypasses I tried that FAILED (the round-1 fixes hold)

- **§8 item 0 (P1-1 fix)** — reproduced exactly: `Inventory` 105 → 106, `CountryDefaults` 27 → 28 with the named files, and `deferredClasses + excludedClasses` 1114 → 1116 (`feature-lane-manifest-check.php:787-793`). The "don't pre-raise in the candidate" rationale is sound: the ceiling test is `>`-only, so a pre-raise is unreportable slack.
- **§1a (P1-2 fix)** — all six `OpenApi` class names match `git ls-tree codex/openapi-contract-a-to-z:apps/api/tests/Feature/OpenApi` exactly; the quoted error is byte-identical to `feature-lane-manifest-check.php:257-263`, including `$members[0]` = `OpenApi/DocumentResponseContractTest.php` (alphabetically first); *"raise `debt_ceiling` by 6 if deferred"* matches the debt formula.
- **Six/four (P2-3 fix)** — reproduced: `ci.yml` written by P2, ui-wave0, dn-consolidation, es-wave-a0, openapi, enforcement-p1; `needs: [` rewritten by ui-wave0, openapi, enforcement-p1 (+P2) and **not** by dn-consolidation or es-wave-a0.
- **§(81) numbers (P2-5 fix)** — `needs` = 13 (`:1249`); gated off on PR→dev = exactly `backend-test` (`:216`), `frontend-test` (`:1067`), `frontend-build` (`:1126`); `pos-test:1099` contains `event_name == 'pull_request'` → runs; the ten-job "runs on PR→dev" table is correct (`backend-test-pgsql` and `treasury-spine-pgsql` both carry `base_ref == 'dev'`).
- **P3-6 fix** — the unverified "145 tests" is gone (`ANNOUNCE-…:74`).
- **2(a) still honest** — `grep -c 'route-manifest-drift\|check-manifest-drift' .github/workflows/ci.yml` → 0; the round-2 diff touches only four docs files, so no drift job, no manifest regeneration, no `gen-route-manifest.mjs` edit crept in.
- **Web/locale counts** — ui-wave0 78/16 and dn 35/4 reproduce against `apps/web`.
- **Process hygiene** — `fix_rounds: 1`, `commit: 7d64975d1`, `verdict:` path, `last_verdict: CHANGES-REQUIRED`, `status: review` in a separate metadata commit (`e8c4a427e`); register committed under the wave's established evidence-trail practice (M1/M2 precedent).

### Standing checks
Rule 19 (money/quantity floats), tenant scoping, constructor injection, en+fr strings, migrations, Horizon queues, red-first evidence: **not applicable** — the round-2 delta is three markdown/YAML files and no behavioural change.

VERDICT: CHANGES-REQUIRED

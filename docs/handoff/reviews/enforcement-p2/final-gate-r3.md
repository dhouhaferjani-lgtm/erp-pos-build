# FINAL-GATE REGISTER — package p2 M4 round 3 attempt 1
accepted_sha: c1e6949142138b117e90fec68b27dcc93eb68d85
base_sha: a4a8c2293dcf7556045f44d35cfa5f734be8022b   # from the parent DISPATCH RECEIPT (/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml), ancestry-verified
dispatch_receipt: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-p2.receipt.yaml
snapshot: detached-worktree @ c1e6949142138b117e90fec68b27dcc93eb68d85 (sealed; re-asserted post-run)
handback_sha256: /Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-21-r3.md=6f7d026afb637e413e50f89320ff670e25ac1dd3f48b489223958e29ec8c5fdb   # copied+hashed by this bridge; not in the prompt
manifest_sha256: 7d53797b1849b6adeb2fcb39789935a48fc32a6f4804a9833d4bb8a78f1aa2b7
control_sha256: adversarial-review-final.sh=55ca88b0f9de917a48c8d6b2e4e0cbf8b8a38c29e67a75b696f4419566864c88
control_sha256: brief=63cc3e182d0ab1bd9a2c03f147cb4bd786aa708f8b9f8e234a567cab4261c6c8
control_sha256: harness=6086d86de2b4302233d7e4e2f9879002c06ff87419cfef5732b9c61f2e238c15
control_sha256: lens:frontend-conventions=278596dbc07e82ece964897d021d2d136dce59f136519578c9a256b39da45385
control_sha256: lens:tenancy-authz=9e2e444acf0b0de7b70f053eaf9948be2f001eb38de132a15103976d78b3a345
max_fix_rounds: 5
---
# ADVERSARIAL FINAL-GATE REGISTER — enforcement-p2, M4, round 3

**Snapshot** `/var/folders/yb/rbv331vn481c6blwf61pyp840000gn/T/tmp.0hj68H1Jnq/snap` · detached `HEAD = c1e6949142138b117e90fec68b27dcc93eb68d85` (verified) · `git status --porcelain` empty (verified) · range `a4a8c2293..c1e694914` = **62 commits / 84 files**, `a4a8c2293` verified strict ancestor of A.
**Handback reviewed** `/Users/houssamr/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/enforcement-p2-registers/HANDBACK-enforcement-p2-2026-08-21-r3.md` · `sha256 = 6f7d026afb637e413e50f89320ff670e25ac1dd3f48b489223958e29ec8c5fdb` · 351 lines.
**Lens contracts applied in full:** `frontend-conventions`, `tenancy-authz`.

---

## A. Required content-derived table evidence (proof of inspection, gate-r8 R8-H-2)

| handback table | lines | data rows | FIRST row key | LAST row key |
|---|---|---|---|---|
| §1 Header | 18–26 | **6** | `**branch**` | `**pushed?**` |
| §3a i18n provenance | 74–78 | **3** | `en` | `**ar**` |
| §3a basis (entries vs key-level) | 84–87 | **2** | `**baseline entries**` | `**key-level gaps**` |
| §3b Baseline composition | 100–106 | **5** | `namespaces` | `structural failures` |
| §3c Feature-lane census | 110–114 | **3** | `all of `tests/Feature` (74 groups)` | `minus the 111 allowlisted names inside them` |
| §5e Scope allowlist | 255–269 | **13** | `` `.github/workflows/ci.yml` — the CI wiring deliverable `` | `**total — OUTSIDE THE ALLOWLIST: NONE ✓**` |

Boundary cell values as shipped: §3a first `| en | 56 | 0 | 0 |`, last `| **ar** | **21** | **23** | **12** |`. §3b first `| namespaces | 56 |`, last `| structural failures | 0 |`. §3c first `| all of tests/Feature (74 groups) | 1 329 | 1 316 |`, last `| minus the 111 allowlisted names inside them | 1 003 | **991** ≈ the ci.yml census's 990 |`. §5e last `| **84** | **total — OUTSIDE THE ALLOWLIST: NONE ✓** |`.

**Independent re-derivation at A** (`apps/web/tools/i18n-completeness-baseline.json`): `entries` is a list of **2 924**; first entry `ar|adminCountryDefaults|aliased|*`, last entry `fr|workshop-bundles|plural|interval.months_many`; composition `ar aliased 22 · ar missing 2 851 · ar plural 9 · fr plural 34 · en plural 8`. `apps/api/tests/feature-lane-manifest.json:8` → `"debt_ceiling": 1131`, 74 groups.

---

## B. Findings

### CRITICAL-1 — the announcement bound for ten lanes instructs the owner to set the ratchet variable to a SUPERSEDED blob; following it fail-closes the mandatory pre-promotion dispatch

`docs/handoff/ANNOUNCE-enforcement-p2-ci-contract-change-2026-08-19.md:220-225` — "**Owner prerequisites — BOTH must exist before the merge lands** … 1. repository variable **`I18N_BASELINE_PROTECTED_BLOB`** = `da151bbc51edcd066d2153b51a1df8ae1ab5bd32`".

Verified at A: `docs/handoff/progress/enforcement-p2.progress.yaml:69` pins `i18n_baseline_protected_blob: 26a9ae1688d80e0f450215326b19ccd1701c9a8f`; `:67` states outright "prior pins cb618c12c/da151bbc5 **superseded**". `git rev-parse 6a0c1cd72:apps/web/tools/i18n-completeness-baseline.json` = `26a9ae168…` = the blob at A. `cb618c12c` (whose baseline blob IS `da151bbc5…`) is **not an ancestor of A** (`git merge-base --is-ancestor cb618c12c c1e694914` → NO).

Failure scenario, end to end: the owner executes §5 item 4 step 2 from this announcement, sets `I18N_BASELINE_PROTECTED_BLOB=da151bbc5…`, pushes `ci-pin/enforcement-p2-r1` at A, dispatches. `apps/web/tools/audit-i18n-completeness.mjs:723-731` compares the variable against the YAML mirror (`26a9ae168…`), diverges, and exits 1 as **mirror drift — a tamper signal**. `frontend-lint` reds. No green run on exactly A ⇒ **promotion blocked** (LEDGER S-14), and the failure presents as a tamper alarm rather than a stale pin. The same wrong value is repeated in the handback §6 "Owner prerequisites" and §5a "seed_blob = da151bbc5… (== YAML mirror ✓)" — an equality assertion that is false at A.

Neither `ANNOUNCE-…` nor `DECISION-…` was touched by any of the four post-rebase reconciliation commits: `git log 675e356e3..c1e694914 -- <both files>` returns empty.

### CRITICAL-2 — every census table in §3 is measured at an abandoned non-ancestor commit and contradicts the shipped artifacts at A

Brief §7 item 3 makes these tables *the review targets*. Each quantitative cell is wrong against A:

- §3b (`:100-106`) "**PINNED seed `cb618c12c`, blob `da151bbc5…`**" — that seed is not an ancestor of A; the pin is `6a0c1cd72` / `26a9ae168…`.
- §3b `**baseline entries** | **2 917** — ar aliased 23, ar missing 2 848, ar plural 9, fr plural 29, en plural 8`. Shipped at A: **2 924** — ar aliased **22**, ar missing **2 851**, ar plural 9, fr plural **34**, en plural 8. Every cell in the composition differs; the executor's own commit `6a0c1cd72` announces "2924 entries".
- §3a (`:74-78`, `:84-87`) inherits the same seed: `23 aliased` (now 22), `2 638`, `4 613`.
- §3c (`:110-114`) "in groups no lane runs as a whole (**`debt_ceiling`**) **1 114**" — shipped `debt_ceiling` is **1131** (`feature-lane-manifest.json:8`). The two derived rows (`1 003`, `991 ≈ 990`) descend from the stale figure.
- §5a/§5f re-verification lines "`2917 known gap(s) held at the baseline`" and "`EXIT=0 (2917 gaps held)`" were not re-run at A.
- `DECISION-enforcement-p2-ci-guards-2026-08-19.md` carries the superseded seed/blob/2 917 at `:188`, `:200`, `:212`, `:533-534`, `:632`, `:736`, `:804`, `:987` and 1 114 at `:1007`, `:1047`, `:1067`, `:1114`, `:1130`, `:1262`, `:1362`; `ANNOUNCE-…:43-55`, `:196`, `:238`, `:257` publish `debt_ceiling: 1114` (and a 1114→1122 reconciliation table) to the lanes.

The true values appear **only** in the trailing addendum (`:330-346`). The document therefore states both, with the superseded value in every operative position. This is the sixth-plus firing of the series' own documented **append-vs-substitute** class (rulings r5/R5-H-3, r10, r12, r14, r18, r21) — here inside the artifact the closing tag authenticates and that ten lanes act on.

This also leaves the M3-round-6 **close-before-merge obligation** open: the handback §6 states "the ceilings must be **regenerated against the merged tree**, not copied — `dev` moved three times during review. Announcement §8 item 0 is regenerate-first." The JSON was regenerated (1131); the announcement and decision doc that publish those ceilings were not.

### MAJOR-1 — §5e scope proof is a stale paste, falsified by the HEAD-equality assertion added to prevent exactly this

`HANDBACK…:236-253` — heading "**Scope proof — RE-RUN AT A″ (not a paste from an earlier commit)**", then `claimed A″ = 31dc37f40fa364b3a056216963ec22f2a0bcb0cf` / `git rev-parse HEAD = 31dc37f40…` / `MATCH ✓ — the proof is being run at the SHA it claims`, over base `c97e0d1ad..HEAD`.

Verified: `31dc37f40` is **not an ancestor of A**; A is `c1e694914`. The declared base is wrong too — `git diff --name-only c97e0d1ad..c1e694914 | wc -l` = **531**, not the 84 the table totals. The countermeasure the executor installed at final-gate round 1 ("§5e is now re-run at the tip with a HEAD-equality assertion inside it") was not re-run after the rebase, so the assertion now proves the section stale rather than fresh.

*In fairness, I re-derived the conclusion myself and it holds at A*: `a4a8c2293..c1e694914` is 84 paths bucketing exactly as the table claims — 46 `apps/web/tools`, 25 `docs/handoff`, 3 `eslint-rules`, 2 `scripts`, 2 `docs/conventions`, 1 each ci.yml / `apps/api/tools` / `apps/api/tests/Architecture` / manifest JSON / `package.json` / `apps/web/src/…/statements/api.ts`; nothing outside the allowlist; no `generated.d.ts`; control-file preflight clean (no `scripts/adversarial-review*.sh`, brief, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, `.claude/agents/*`, or `*control-manifest*` surrogate in the range). The scope is sound; the attestation is not.

### MINOR-1 — stale aggregate count

`HANDBACK…:230` "`security-regression` added to `all-checks-pass` `needs` (13 members)". At A the list is **15** (`ci.yml:1443`): `backend-lint, backend-analyse, backend-architecture, backend-dpa-guard, backend-test, backend-test-pgsql, security-regression, treasury-spine-pgsql, frontend-lint, frontend-typecheck, frontend-test, pos-test, frontend-build, route-manifest-drift, types-drift`. Membership itself is correct; only the count is pre-rebase.

---

## C. What passes — recorded so the fix round stays scoped

The implementation is not the problem, and a fix round must not disturb it.

- **CI wiring (2(c) d3, 2(d) d4) — correct and non-chain.** `ci.yml:1158` `git fetch origin tag ci-pin/enforcement-p2-r1`; `:1161-1176` discrete `frontend-lint` step running `pnpm audit:i18n` with `I18N_BASELINE_PROTECTED_BLOB: ${{ vars.I18N_BASELINE_PROTECTED_BLOB }}`; `:1187` `pnpm test:eslint-rules && pnpm test:tools`; `:191` `php tools/feature-lane-manifest-check.php` in `backend-architecture`. R2-H-3 satisfied verbatim: `apps/web/package.json` → `"test:tools": "vitest run tools/__tests__"`, `"audit:i18n": "node tools/audit-i18n-completeness.mjs"`. No `if:` on the carrying jobs; all in the aggregate `needs`.
- **Ratchet authority (R2-C-1 / R3-C-1 / R4-H-4) implemented as specified.** `audit-i18n-completeness.mjs` fails closed on unset variable (`:695-708`), unreadable/unset mirror (`:710-721`), **mirror≠variable drift** (`:723-731`), unfetchable blob (`:733-747`), and compares parsed key sets removal-only (`:768-773`). The extra **surface-coverage invariant** (`:592-606`, `:750-765`) closes an evasion the brief did not name — dropping a namespace from the scan to shrink the baseline now fails.
- **tenancy-authz lens:** the new `security-regression` job (`ci.yml:490-520`) moves the whole `tests/Feature/Security` directory onto PR→dev with no `if:`, closing a real rule-12 module-gating/kill-switch blind spot; whole-directory (anchor-safe, new classes gated on landing), cost stated honestly against F-2 rather than understated, and asserted by the manifest checker's own liveness case `test_it_fires_when_a_lane_misreports_its_pr_dev_coverage`. No tenancy boundary, permission catalog, or route middleware is touched.
- **frontend-conventions lens:** exactly one production file changed — `apps/web/src/features/treasury/statements/api.ts:110-119`, `Omit<>` → `Pick<OffsetPaginationMeta, 'current_page'|'last_page'|'per_page'|'total'>`, with the controller cited at the change site. Type-only, narrowing, no token/i18n/RHF/query-key surface. Baseline honesty holds in the sense the lens requires: the seed was **revised** through the mandated two-commit topology (`6a0c1cd72` seed → `bdfc3ea7f` mirror re-pin), not absorbed via `--write-baseline` into a passing run.
- Deviations 1–9 are disclosed prominently, including the `generated.d.ts` revert (verified: absent from `base..A`) and the executor substitution.

---

## D. Required to clear this gate

1. Regenerate `ANNOUNCE-…:225` and handback §6/§5a to `I18N_BASELINE_PROTECTED_BLOB = 26a9ae1688d80e0f450215326b19ccd1701c9a8f`, seed `6a0c1cd72bd5e97548f42871497ae015ea68de6d`.
2. Re-derive §3a/§3b/§3c at A and **replace** — not append — the superseded cells (2 924 / 22 / 2 851 / 34; `debt_ceiling` 1131 and its dependents), and reconcile the same figures in `ANNOUNCE-…:43-55,196,238,257` and `DECISION-…` at the lines listed, discharging the M3-round-6 regenerate-first obligation.
3. Re-run §5e at `c1e694914` over `a4a8c2293..HEAD` so the embedded HEAD-equality assertion is true; correct §5c's member count to 15.
4. Per gate-r7 R7-H-1 / gate-r8 R8-H-3 this is a **substantive** CHANGES-REQUIRED: the executor alone fixes, commits, increments `fix_rounds`, resets M4 to `status: review`, and hands over with `git status --porcelain` showing exactly `?? <handback-source>`. This register is not to be committed into the candidate.

VERDICT: CHANGES-REQUIRED

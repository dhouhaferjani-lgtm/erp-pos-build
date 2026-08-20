## Adversarial merge-gate register — enforcement-p2 / milestone **p2-M3**, round 6

**Scope reviewed:** `git diff c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD` (HEAD `70adfd156`). M0–M2 were gated in their own rounds; the **round-6 delta** is `2f7798975..HEAD` = `a9896ae3f` (round-5 fix round) + `70adfd156` (YAML bookkeeping). M3's whole footprint (`git diff --name-only 0fce1206d~1..HEAD`) is **eight docs/YAML files, zero code** — no PHP, no TS, no `ci.yml` edit this milestone. Acceptance criteria taken from the brief's 2(a) section (`CODEX-DISPATCH-enforcement-guards-2026-08-12.md:306-317`) and the `p2-M3` milestone line (`:391`). **Amending authority: none.** Working tree clean before and after (`git status --porcelain` empty); no round-6 register committed into the candidate; no control file (`scripts/adversarial-review*.sh`, `SELF-REVIEW-HARNESS.md`, `enforcement-control-manifest.yaml`, the brief) touched anywhere in `base..HEAD`.

**Lens — frontend-conventions.** Applies only obliquely: M3 authors no `apps/web` code (the range's single web-source change is M1's `Pick<OffsetPaginationMeta,…>` swap at `apps/web/src/features/treasury/statements/api.ts:113-119`), so canonical components / design tokens / RHF / `tenantScopedKey` have no surface. Where it bites is the **i18n contract this checklist asserts to other lanes**, verified by execution rather than by reading: `pnpm audit:i18n:local` → *"56 namespaces … ar: 23 ns / 1998 keys served in English"*; independently, `ns` in `apps/web/src/lib/i18n.ts` = **56**, whole-namespace `aliased` baseline entries = **23** → **33 wired**, and none of the twelve namespaces named at `ANNOUNCE-…:132-134` (`pos`, `sales`, `inventory`, …) is in the aliased set. §3(a)'s table is exact.

**Round-5 findings — all four CLOSED, re-derived not trusted.**
- **P2-1 (locale measure).** `git diff --diff-filter=A --name-only dev...<b> | grep -c '^apps/web/src/locales/'` → **0** for all seven rows; `--name-only` → **16/4/3/3/3/2/2**, exactly the printed column. The file now names both measures and why they differ (`ANNOUNCE-…:13-17`, `:137-141`).
- **P3-2 (990 vs 1114).** Every figure in the new decomposition reproduces: `tests/Feature` = **1329 files / 1316 distinct**; deferred groups = **1114 / 1102** (= `debt_ceiling`); allowlist union (`ci.yml:733`, `:830`) = **124** distinct names, **111** inside deferred groups; remainder = **1003 / 991**.
- **P3-3 (§(82) eleven vs ten).** Reconciled at `DECISION-…:1952-1953` with the `Accounting`-is-laned reason (`feature-lane-manifest.json` → `Accounting {lane: treasury-spine-pgsql/feature-accounting}`).
- **P3-4 (§(81) pointer).** `ci.yml:1240-1247` on the candidate and `:1096-1103` at `base_sha` both verified to hold the STRICT-SUPERSET comment.

---

## Findings

### 1 — P2 — CONFIRMED — every measured table is unstamped, and `dev` moved **during this review**: §8 item 0's enumerated re-baseline is already wrong (it omits `Fiscal` 73→79 and understates `debt_ceiling` by 6)
`ANNOUNCE-…:236-259` (§8 item 0), `:27-37` (§1 row 2), `:325` (§10 `codex/es-wave-a0` row); no as-of pin anywhere in the file (`grep -n '41fb478\|as of\|as-of'` → no hits)

At the start of this round `git rev-parse dev` = **`41fb478c2`** (the value round 5 measured against). By the end it was **`3d66be352`** — `git reflog show dev` → `dev@{0}: merge codex/es-wave-a0`. Measured on the new tip:

| group | candidate manifest ceiling | on `dev` now | §8 item 0 says |
|---|---|---|---|
| `Inventory` | 105 | 106 | → 106 ✅ |
| `CountryDefaults` | 27 | 28 | → 28 ✅ |
| **`Fiscal`** | **73** | **79** | **not listed** ❌ |
| `debt_ceiling` | 1114 | **1122** | → 1116 ❌ |

Also live: `git diff --diff-filter=A --name-only dev...codex/es-wave-a0 -- apps/api/tests/Feature` now returns **nothing** — the lane §1 tells to "raise `Fiscal` by 6 **and** `debt_ceiling` by 6" and §10 lists as open has just landed, so acting on that row double-raises against the §8 re-baseline, producing exactly the permanent unreported slack §1's own note (`:60-65`) warns against.

**Failure scenario:** the parent promotes P2 and executes §8 item 0 literally — sets `Inventory` 106, `CountryDefaults` 28, `debt_ceiling` 1116 — and the next PR→dev from *any* lane fails `backend-architecture` with `✗ COVERAGE DEBT GREW: group "Fiscal" now holds 79 class(es), ceiling is 73`, blaming a lane for debt it did not author. That is precisely the repo-wide failure §8 item 0 exists to prevent, in the document's own highest-consequence item.

**Why P2 and not P1:** the item's parenthetical already states the governing principle — *"would be stale again by merge time since `dev` keeps moving. The re-baseline has to happen against the merged tree"* (`:256-257`) — and the same sentence offers the safe path (*"or, better, regenerate the manifest against the merged tree and confirm `php tools/feature-lane-manifest-check.php` exits 0 **on `dev`** before any other lane opens a PR"*). A parent who reads the whole item cannot be misled. **Why it is not an executor fix round:** no number the executor writes can be correct at promotion time — `dev` moved twice inside one review round. It closes at promotion, by the parent, which is what the harness means by *"P2 close-before-merge"* (`SELF-REVIEW-HARNESS.md:73`).

**Concrete closing action, owed to the parent before the final announcement is sent:** stamp each measured table with the `dev` SHA it was derived from; re-derive §1 / §8 item 0 / §10 against the then-current merged tree; and promote the "regenerate + confirm exit 0 on `dev`" clause above the enumerated numbers. As of `3d66be352` the correct §8 list is `Inventory` 106, `CountryDefaults` 28, **`Fiscal` 79**, `debt_ceiling` **1122**.

### 2 — P3 — CONFIRMED — §10's inclusion-rule bullet still states the retracted method for its own locale column
`ANNOUNCE-…:302-303` — *"measure impact as **added** files against `dev` (`git diff --diff-filter=A dev...<branch>`), **not changed files**"* vs the "locale files" column of the very table it introduces (`:309-325`)

Round 5's P2 cited three restatements of the added-files rule; the fix scoped the header (`:13-17`) and §3 (`:137-141`) but left §10's own bullet unqualified. A reader who self-selects at §10 — the section titled "inclusion rule stated" — and runs the printed command against the locale column gets **0** for every lane. No lane action changes (the exception is stated twice earlier and the counts are right); one clause in that bullet closes it.

### 3 — P3 — CONFIRMED — "the other **13** sit in **laned** groups" is right about the count, wrong about one entry
`ANNOUNCE-…:47-48`

Measured: of the 124 allowlist names, 111 are in deferred groups, **12** are in laned `tests/Feature` groups, and **1** — `VoucherLedgerTest` — has no `tests/Feature` class at all; it resolves to `apps/api/tests/Unit/Voucher/Domain/VoucherLedgerTest.php`. Legitimate (the run line is `php artisan test -c phpunit-pgsql.xml`, whose suites include `tests/Unit`, `phpunit-pgsql.xml:24-35`), so no dead entry and no arithmetic change — 124 − 111 = 13 either way. Only the "sit in laned groups" clause over-claims.

### 4 — P3 — NOTE (disposition ratified, mechanism worth recording) — the 2(a) branch taken matches none of the brief's four bullets exactly
`CODEX-DISPATCH-…:308-312` vs `DECISION-…:1861-1875`

At the M0 snapshot UI Wave 0 read M4 `passed` with the wave `blocked_architecture` and `route-manifest-drift` absent from `base_sha`. Bullet 1 needs the job present; bullet 2 needs M4 `pending`/`in_progress`; bullet 3 needs the wave `complete`; so bullet 4 ("any ambiguous state → `blocked_owner`") is the literal reading. The executor took **verify-only + record the dependency** — bullet 2's action — which is the conservative, F-3-correct outcome (authoring the job would duplicate the UI lane's unmerged work and collide on the same `all-checks-pass` `needs:` line), and §(80) documents both the M0 ruling and the subsequent motion to wave `status: review` without re-deciding. I ratify it; recorded so the next package's ownership rule covers "milestone passed, wave not complete, artifact unmerged".

---

## Bypasses and hypotheses I tried that FAILED (no defect)

1. **Lane-roster completeness, re-derived at the *new* `dev`.** All local branches → drop `merge-base --is-ancestor <b> dev` → drop `*-pre-repin*` / `*-pre-rewrite` / `backup/*` / `triage/*` / `worktree-agent-*` → drop P2's own branch = **19** branches, every one of which has a row in §10 (§10 carries 20; the extra is `codex/es-wave-a0`, finding 1). **No open lane is missing a line** — the milestone's coverage invariant holds.
2. **Added-class counts re-measured against the moved `dev`:** `dn` 9 `Document`, `openapi` 6 `OpenApi`, `scan-vat` 1 `Taxation`, `owner-dashboard` 1 `Seeders` — all reproduce; every "ceiling now" value in §1 matches `feature-lane-manifest.json` (`Document` 65, `Fiscal` 73, `Inventory` 105, `Taxation` 31, `Seeders` 26, `Modules` 53, `Http` 2, `Tenant` 29), and `OpenApi` is genuinely absent from the manifest (§1a's hard-fail claim is real).
3. **§2's counts, each measured:** `tests/Feature/Security` = **17** classes; `apps/web/eslint-rules/*.test.mjs` = **6**; `tools/__tests__/` = **7** files; tag `ci-pin/enforcement-p2-r1` fetched by literal name at `ci.yml:998` and mirrored at YAML `:61`; `security-regression` present in `all-checks-pass` `needs` (`ci.yml:1249`), which also carries `backend-architecture` and `frontend-lint` — so §2's "steps 1–5 inherit membership" is true.
4. **§5's F-2 arithmetic:** 1329 × 6.24 s = 138 min; 1114 × 6.24 s = 116 min. Both as printed.
5. **The aggregate really is a proposal.** The only `needs` delta in the whole range is `+security-regression`; `all-checks-pass`'s `if:` is byte-identical to base — no unilateral branch-protection change.
6. **Guard-liveness / false-claim hunt:** no shipped guard's behaviour is asserted in M3 that I could not reproduce; the i18n audit runs green locally with no repository variable, via `scripts/i18n-baseline-authority.sh`, exactly as §3 claims.
7. **Scope creep:** none. M3's eight files are docs + YAML + its own registers. No production code, no workflow edit, no migration, no queue.
8. **Process hygiene:** `status: review`, `fix_rounds: 5` (= `max_fix_rounds`), `commit: a9896ae3f`, `verdict: …/M3-round5.md`, `last_verdict: CHANGES-REQUIRED`, recorded in a **separate** YAML-only commit (`70adfd156`) — the required two-step topology.

## Standing checks

Rule 19 (no float on money/quantity; bcmath/string; injected scale resolver with explicit currency), red-first evidence, tenant scoping, constructor injection, en+fr on user-facing strings, additive migrations, Horizon queue coverage: **not applicable to this milestone** — M3 adds no PHP, no TypeScript, no migration, no queue, no behavioural change; its deliverables are the 2(a) verify-only record, the owner-facing aggregate proposal, and the announcement checklist. The milestone's **own** named invariants are present and non-vacuous: 2(a) verify-only per the M0 snapshot with the dependency recorded and nothing authored (`grep -c 'route-manifest-drift\|check-manifest-drift' ci.yml` → **0**, `gen-route-manifest.mjs` and `scripts/factory/manifests/` untouched); the `all-checks-pass` PR→dev question delivered as a proposal with the aggregate unchanged; and the checklist gives all 19 currently-open lanes one actionable line each, including the OpenAPI merge-order/reconciliation line (§1a + §4) and the M1 `frontend-lint` step-addition contract change (§2 rows 1–3, §3(a)). M2-round8's two carried obligations are discharged here: the N-4 line for P3-M2 (§9) and the `security-regression` job id named for the S-14 dispatch (§8 item 2).

**Disposition.** All four round-5 findings are closed under independent re-derivation, and every table in both documents reproduces exactly against the tree — the fifth consecutive round in which the *tables* hold. No P1. Findings 2–4 are notes. Finding 1 is the first M3 finding of a genuinely different family — not prose drifting from a table, but the tables' operand (`dev`) moving under them, demonstrated live mid-review — and it is structurally not closable by an executor commit: any number frozen in the candidate is stale by promotion. Under `SELF-REVIEW-HARNESS.md:72-73` that is a **close-before-merge** obligation on the parent, not a milestone-passage blocker, and I record it as such with the exact numbers owed (`Fiscal` 79, `debt_ceiling` 1122 as of `3d66be352`) plus the as-of-stamp requirement for the final announcement. Were fix rounds still available I would have asked for the one-line as-of stamp; at the cap, escalating a promotion-time obligation into `blocked_review` would buy the same parent ruling at the cost of the milestone.

VERDICT: ACCEPT

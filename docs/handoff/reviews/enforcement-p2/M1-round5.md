## Adversarial merge-gate register — enforcement-p2 · milestone **p2-M1** · round 5

**Range reviewed:** `c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD` (14 commits, 49 files). **Amending ruling:** none.
**Lens `frontend-conventions`: applied.** Standing lenses, stated explicitly: **Rule 19 — N/A** (no PHP, no money/quantity runtime code; the only money-adjacent artifact is `apps/web/eslint-rules/no-parsefloat-on-money.test.mjs`, a RuleTester this package makes CI-reachable for the first time). **Tenancy/authz, constructor injection, migrations, Horizon queues — N/A** (no backend code, no migrations, no queues). **en+fr strings — N/A**: the sole production source change (`apps/web/src/features/treasury/statements/api.ts:113`) is a type narrowing; all new output is developer-facing CLI text. `tenantScopedKey`, design tokens, RHF — N/A, no `.tsx` touched. Fixtures are excluded from both `tsc` (`tsconfig.json:54` `include: ["src"]`) and ESLint (`eslint.config.js:61` `tools/__fixtures__/**`), so they add no lint-ratchet warnings — verified.

### Round-4 disposition — verified by execution, not by the report

| R4 | Status | What I re-ran |
|---|---|---|
| R4-1 (P2) sibling reversal silent under depth-keying | **CLOSED** (but see **P2-1**) | Reversed *only* `settings.sections` (a non-last sibling) on a copy of the real tree → `kind ar.settings = en-aliased`, CLI reports `✗ ar|settings|aliased|*`. Red-first confirmed: the pre-fix scanner at `007b1a49c` against the **new** `sibling-scope` fixture returns `english-spread`, no aliased entry |
| R4-2 (P2) orphan test was a source grep | **CLOSED behaviourally** | Mutated `auditRoot` to `readdirSync` only `en` → `structural` becomes `[]` on the `sibling-scope` fixture, so the new test at `audit-i18n-completeness.test.mjs:487-496` goes red under any variable name. The mutation kills it — non-vacuous |
| R4-3/4/5 (P3) | **CLOSED** | `DECISION-…:733-746` records the pin-tag/`ci.yml` lockstep for M3, names the three first-execution surfaces, and the CI-INPUT banner is live at `enforcement-p2.progress.yaml:1-8` |

**Independently verified, not taken from the report:** `git rev-parse cb618c12c:apps/web/tools/i18n-completeness-baseline.json` = `git hash-object` of the working file = YAML mirror = `da151bbc5…`; the production run is clean (`EXIT=0`, 56 namespaces, 2917 gaps held), so round 4 was legitimately baseline-neutral and correctly did **not** re-create the two-commit seed topology. `pnpm test:tools` → 7 files / **142** tests green; `pnpm test:eslint-rules` → 6 suites green. `frontend-lint` is in `all-checks-pass` `needs` (`.github/workflows/ci.yml:1149`) — H-9 satisfied verify-only. Every `apps/web/eslint-rules/*.js` now has a `.test.mjs` (2(d)-1 complete for the three named rules); the C6 `Record<X, StatusTone>` case is correctly *not* authored here and recorded as UI Wave 0 T7's deliverable (`DECISION-…:330-336`).

---

## P2 — fix before merge

**1. The round-4 scope-keying is a net DETECTION LOSS against the commit it replaced: dropping a locale's spread from a nested sibling was caught at `007b1a49c` and is silent at HEAD.** — `apps/web/tools/audit-i18n-completeness.mjs:256-273`, `:300-315` — **CONFIRMED**

Depth-keying compared the *last* `en` index against the *last* own index across all literals sharing a depth. That accidentally covered a second shape: a nested literal containing **only** `...en*`, sitting after a locale-spread sibling. Scope-keying buckets per object literal, so a scope with `en` but no own spread now matches nothing and the namespace falls through to `english-spread`.

Measured on a copy of the real `apps/web/src`, one edit — delete `...arSettings.locations` from `src/lib/i18n.ts:386`:

```
PRE-FIX  (007b1a49c, depth-keyed)  kind ar.settings = en-aliased
                                   CLI: "1 NEW gap(s) … ✗ ar|settings|aliased|*"   → RED
POST-FIX (HEAD, scope-keyed)       kind ar.settings = english-spread
                                   CLI: "i18n completeness OK … 2917 known gap(s)"  EXIT=0 → GREEN
```

Failure scenario: a lane reverts one Arabic subtree (`// RTL broken, revert ar locations`) by removing its spread. `settings.locations` is then served 100% in English at runtime, `ar/settings.json`'s `locations.*` keys are dead, and the gate that went red on this edit yesterday passes today. Same class and same magnitude as R4-1 — per-subtree, total, silent — only introduced by the fix rather than left by it.

This is *adjacent to* but not the same as the residual recorded at `DECISION-…:386-388`. That residual is per-key ("a key authored under a subtree that is not spread"); what regressed here is whole-subtree, and it was live detection at the parent commit.

**Fix is one predicate and provably neutral.** Inside `classifyAssignment`, when the namespace has an own spread *somewhere*, also return `'en-aliased'` for any **nested** scope (`depth > 1`) that holds an `en` spread and **no** own spread. I swept the whole production graph for that shape: **zero** matches across every non-`en` locale/namespace, and zero across all five fixtures (`prod-shaped`, `comment-tamper`, `edge-cases`, `spread-order`, `sibling-scope`) — so no reclassification, no new baseline entries, no seed revision, no two-commit re-pin, no new pin tag. Pin it with a third sibling in the `sibling-scope` fixture that spreads only `...enBeta`, placed **after** a normal sibling (placing it first would pass under the old code too and would not be a red-first proof). The same predicate also closes the non-last-sibling variant below.

---

## P3 — notes

**2. The recorded residual is broader than round 4's register (and mine) characterised it.** — `DECISION-…-2026-08-19.md:386-388` — **CONFIRMED.** Probed on the real tree at HEAD, both of these are silent (`2917` findings, `EXIT=0`, zero structural):
`sections: { ...enSettings.sections }` and `sections: enSettings.sections` (bare property, no spread at all). Neither depth- nor scope-keying ever caught the non-last-sibling case, so this is not a regression — but the residual should be restated as *per-subtree and total*, not "per-key, static". The P2-1 predicate closes the spread form; the bare-property form needs the property-assignment arm as well, which is a genuine scope call for whoever extends this.

**3. `pnpm lint`'s new tail is invoked inside CI's ratchet step, where its precondition is not guaranteed and its failure is invisible.** — `apps/web/package.json:10`, `scripts/lint-ratchet.mjs:44-73`, `.github/workflows/ci.yml:934` — **PLAUSIBLE.** `lint:ratchet` runs `pnpm --filter @autoerp/web lint`, which now includes `audit:i18n:local` → `scripts/i18n-baseline-authority.sh:54` `git rev-parse cb618c12c:…`. Under `actions/checkout@v5`'s depth-1 clone the seed commit may not be in the object store (whether the preceding pin-tag fetch deepens enough is exactly the thing that has never run on a runner). If it fails, `runLint` swallows the non-zero exit — the ESLint summary line is always present (web carries 11k+ warnings), so it returns counts and passes. Direction is safe (no false red, and the discrete steps do the real gating), but a hard-failing helper that CI silently ignores is worth one comment, or gating the chain entry on `git cat-file -e` first.

**4. Housekeeping before M4.** `git status --porcelain` in this worktree carries an untracked `apps/api/feature-lane-manifest.json` (an M2 artifact). The M4 hand-over rule requires **exactly one** entry — the untracked handback — so this must be moved or committed to its own milestone before the final gate, or the bridge rejects the hand-over.

**5. Carry-forward, unchanged.** None of the CI wiring has executed on a real runner (H-7); the pin-tag fetch (`ci.yml:888-899`), the `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}` mapping (`:915`) and `pnpm test:tools` (`:927`) all run for the first time at the owner's pre-promotion `workflow_dispatch`, and both owner prerequisites (repository variable **and** the annotated tag at exactly the accepted SHA) must exist before the merge lands. Correctly documented at `DECISION-…:739-744`.

---

## Bypasses attempted that FAILED (the guard held)

| Attempt | Result |
|---|---|
| `env -u I18N_BASELINE_PROTECTED_BLOB node tools/audit-i18n-completeness.mjs` | `FAIL CLOSED: … is unset` ✔ |
| Bogus variable value vs the YAML mirror | `FAIL CLOSED: MIRROR DRIFT` ✔ |
| Matched growth (plant `plantedTamperKey` in `en/settings.json` **and** add both baseline entries) | `RATCHET GROWTH: 2 baseline entries added … REMOVAL-ONLY` ✔ |
| Add a `fr` plural family with only `_one` | `fr|settings|plural|widgetCount_many`, `…|other` + 3 `missing` — 5 fresh ✔ |
| Reverse a **non-last** nested sibling (`settings.sections`) | `en-aliased`, `✗ ar|settings|aliased|*` — **R4-1 closed, red-first confirmed against `007b1a49c`** ✔ |
| Mutate the orphan scan to `en`-only | `structural: []` → the new behavioural test goes red ✔ |
| `en`-only nested literal after a normal sibling (`locations: { ...enSettings.locations }`) | Silent, `EXIT=0` — **RED at `007b1a49c`** — see **P2-1** ✘ |
| `sections: { ...enSettings.sections }` (non-last) / `sections: enSettings.sections` | Silent both pre- and post-fix — see **P3-2** ✘ |

**Required for round 6:** finding 1 only (the nested-scope `en`-without-own predicate + a third `sibling-scope` sibling as its red-first pin; verified neutral on production and on all five fixtures, so no seed revision, no re-pin, no new pin tag). Findings 2–5 are documentation/housekeeping. `fix_rounds` is 4 of `max_fix_rounds: 5` — one round remains.

**Read-only disclosure:** all probes ran on `mktemp -d` copies. One temporary file (`apps/web/tools/.tmp-old-scanner.mjs`) was written into the worktree to run the pre-fix scanner and was deleted in the same call; `git status --porcelain` afterwards shows only the two pre-existing untracked entries. Nothing tracked was modified, staged, or committed.

VERDICT: CHANGES-REQUIRED

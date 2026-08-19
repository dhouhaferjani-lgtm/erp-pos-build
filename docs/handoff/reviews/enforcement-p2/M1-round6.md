## Adversarial merge-gate register — enforcement-p2 · milestone **p2-M1** · round 6

**Range reviewed:** `c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD` (`a7489cf4f`, 16 commits, 58 files). **Amending ruling:** none.

**Lens `frontend-conventions`: applied.** Standing lenses, stated explicitly: **Rule 19 — N/A** (no PHP, no money/quantity runtime code; the only money-adjacent artifact is `apps/web/eslint-rules/no-parsefloat-on-money.test.mjs`, a RuleTester this package makes CI-reachable for the first time). **Tenancy/authz, constructor injection, migrations, Horizon queues — N/A** (no backend code, no migrations, no queues). **en+fr user-facing strings — N/A**: the sole production source change (`apps/web/src/features/treasury/statements/api.ts:113` — a `Pick<OffsetPaginationMeta,…>` narrowing) adds no strings; all new output is developer-facing CLI text. `tenantScopedKey`, design tokens, RHF — N/A, no `.tsx` touched.

### Round-5 disposition — verified by execution, not by the report

| R5 | Status | What I re-ran |
|---|---|---|
| R5-1 (P2) nested english-only subtree silent | **CLOSED for production detection** (but see **P2-1**) | On a copy of the real tree, `locations: { ...enSettings.locations }` → `✗ ar\|settings\|aliased\|*`, EXIT=1. Also the **non-last** variant (`sections: { ...enSettings.sections }`) → caught, EXIT=1. Both were silent at `2085fbdf8` |
| R5-2 residual restated | **CLOSED** | `DECISION-…:786-806`, §(38) names the bare-property shape as the open scope call |
| R5-3 lint-ratchet swallowed exit | **Recorded, not changed** — accepted, discrete steps run before the ratchet | `ci.yml:889-928` precede `:930 lint:ratchet` — ordering claim is true |
| R5-4 stray M2 artifact | **CLOSED** | `git status --porcelain` = one untracked entry (this review dir) |
| R5-5 CI-never-executed | **Carry-forward** | unchanged |

**Independently verified, not taken from the report:** production run is clean (`EXIT=0`, 56 namespaces, `2917` known gaps, `ar: 23 ns / 1998 keys` behind aliases); `pnpm test:eslint-rules` → 6 suites green; `pnpm test:tools` → 7 files / **145** tests green; every `eslint-rules/*.js` has a `.test.mjs` and every `tools/audit-*.mjs` has a `tools/__tests__` suite (2(d)-1/2 complete); `frontend-lint` is in `all-checks-pass` `needs` (`ci.yml:1149`); the CI step carries the exact string `pnpm test:eslint-rules && pnpm test:tools` (`ci.yml:928`, R2-H-3).

---

## P2 — fix before merge

**1. The round-5 fixture edit destroys the round-4 liveness pin: `spreadsByScope`'s scope-keying can now be reverted to depth-keying with the ENTIRE suite green — while the production regression it was added to catch goes silent again.** — `apps/web/tools/__fixtures__/i18n-completeness/sibling-scope/lib/i18n.ts:36-40` (with `apps/web/tools/__tests__/audit-i18n-completeness.test.mjs:500-518`, `apps/web/tools/audit-i18n-completeness.mjs:269-273`) — **CONFIRMED**

Round 5 added a third sibling `englishOnly: { ...enBeta }` to the **existing `sibling-scope` fixture** — the fixture whose only job is to pin the round-4 scope-keying fix. That sibling triggers the *new* predicate (b) (`:327`), so the fixture now returns `en-aliased` for reasons that have nothing to do with scope-keying. The same shape is already pinned properly by the dedicated `english-only-subtree` fixture, so the `sibling-scope` addition is pure collateral.

Mutation-tested directly (HEAD scanner vs. a one-line mutant that changes `scope: stack[stack.length - 1]` back to `scope: stack.length - 1`, i.e. reverts R4-1):

```
fixture                 HEAD             depth-keyed mutant
prod-shaped             english-spread   english-spread
comment-tamper          english-spread   english-spread
spread-order            en-aliased       en-aliased
sibling-scope           en-aliased       en-aliased      <-- pin is DEAD
english-only-subtree    en-aliased       en-aliased
```

**Every asserted `kind` in the suite is identical under the revert** — I enumerated all six fixtures reachable from the `.kind).toBe(` assertions (`test.mjs:48,54,375,456,467,512,517,553,558`). Nothing goes red. Also confirmed against the historical scanner: `git show 007b1a49c:…` (the pre-R4-1 code) now returns `en-aliased` on the current `sibling-scope` fixture, where round 4's register recorded `english-spread` as its red-first proof.

Failure scenario (concrete, and the exact one round 4 blocked on): a later touch of `spreadsByScope` — a refactor, a perf tweak, or an incorrect merge — restores depth-keying. CI stays green. A lane then writes `company: { ...arSettings.company, ...enSettings.company }` at `src/lib/i18n.ts:385` (the natural-but-wrong "spread `en` last so untranslated keys fall back" edit). Measured on a copy of the real tree:

```
HEAD scanner          ar.settings = en-aliased      -> RED
depth-keyed mutant    ar.settings = english-spread  -> GREEN, 2917 findings, EXIT=0
```

Every Arabic key under `settings.company` is then served in English and the gate reports no change. This is a direct violation of this milestone's own deliverable, `docs/conventions/08-DETECTOR-LIVENESS.md:99` — *"The guard has at least one test that FAILS if the guard is neutered (delete the detection branch → the test goes red)"* — the same checklist item round 4's R4-2 was raised under.

**Fix is a 5-line deletion and provably neutral.** Remove `englishOnly: { ...enBeta },` (and its comment) from `sibling-scope/lib/i18n.ts:36-40`. I verified the restored fixture discriminates again: `head = en-aliased | depth-keyed mutant = english-spread`. Predicate (b) keeps its own pin in `english-only-subtree` (verified red-first: `2085fbdf8` → `english-spread`, `mutant-no-b` → `english-spread`), and predicate (a) keeps its pin in `spread-order` (`mutant-no-a` → `english-spread`). Fixtures are outside the audited root, so: no reclassification, baseline byte-identical, **no seed revision, no two-commit re-pin, no new pin tag**. `DECISION-…:731-733` should also drop the now-stale claim that `sibling-scope` "asserts the reversal is still caught".

---

## P3 — notes

**2. Carry-forward, unchanged: the bare-property subtree assignment is still silent.** — `apps/web/tools/audit-i18n-completeness.mjs:256-277` — **CONFIRMED.** Re-probed at HEAD on the real tree: `sections: enSettings.sections` (no spread at all) → `EXIT=0`, `2917` findings, zero structural. `spreadsByScope` only sees `...ident`, so a property-assignment arm is required. Correctly named as the open scope call at `DECISION-…:809-812`; recorded, not required here.

**3. Predicate (b) treats any `...enXxx` identifier as an English spread, so a non-locale helper starting with `en` would false-positive.** — `audit-i18n-completeness.mjs:272`, `:327` — **PLAUSIBLE, not live.** Every spread identifier in `src/lib/i18n.ts` is `ar*`/`en*` locale bundles (swept: 26 identifiers, all locale prefixes), so there is no current match, and the failure direction is a loud fresh `aliased|*` finding (red CI), never a silent pass. Worth one sentence in the residual so the next extender knows the classifier is prefix-only.

**4. Nothing in this wiring has executed on a real runner.** — `ci.yml:888-899`, `:915`, `:928` — **CONFIRMED, carry-forward.** The pin-tag fetch under a depth-1 `actions/checkout@v5`, the `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}` mapping and `pnpm test:tools` under `frontend-lint`'s installer all run for the first time at the owner's pre-promotion `workflow_dispatch`. Both owner prerequisites (repository variable **and** the annotated tag `ci-pin/enforcement-p2-r1` at exactly the accepted SHA) must exist before the merge lands or `frontend-lint` reddens every open lane. Documented at `DECISION-…:739-746`.

**5. `lint:ratchet` still swallows the lint chain's new tail.** — `apps/web/package.json:10`, `.github/workflows/ci.yml:930` — **CONFIRMED, accepted disposition.** The discrete i18n and liveness steps run first, so a genuine failure reddens the job before the ratchet is reached; direction is safe. Out of this package's scope, correctly recorded at `DECISION-…:815-821`.

---

## Bypasses attempted that FAILED (the guard held)

| Attempt | Result |
|---|---|
| `env -u I18N_BASELINE_PROTECTED_BLOB node tools/audit-i18n-completeness.mjs` | `FAIL CLOSED: … is unset` ✔ |
| Matched growth on a copy (plant `zzPlantedTamperKey` in `en/settings.json` **and** add both baseline entries) | `RATCHET GROWTH: 2 baseline entries added … REMOVAL-ONLY` ✔ |
| `locations: { ...enSettings.locations }` — english-only nested sibling, last position | `✗ ar\|settings\|aliased\|*`, EXIT=1 — **R5-1 closed** ✔ |
| `sections: { ...enSettings.sections }` — english-only nested sibling, **non-last** | `✗ ar\|settings\|aliased\|*`, EXIT=1 ✔ |
| `company: { ...arSettings.company, ...enSettings.company }` — reversed non-last sibling | `en-aliased` at HEAD ✔ (but green under the depth-key revert — see **P2-1**) |
| Delete predicate (a) → run `spread-order` fixture | `english-spread` — that test goes red ✔ |
| Delete predicate (b) → run `english-only-subtree` fixture | `english-spread` — that test goes red ✔ |
| Revert scope-keying → depth-keying, run all six fixtures | **Identical kinds on every fixture, whole suite green** ✘ — see **P2-1** |
| `sections: enSettings.sections` (bare property, no spread) | Silent, EXIT=0 ✘ — documented residual, **P3-2** |

**Required for round 7:** finding 1 only — delete the 5-line `englishOnly` sibling from the `sibling-scope` fixture (verified to restore the round-4 red-first discrimination), and drop the stale sentence at `DECISION-…:731-733`. No production code change, no baseline movement, no seed revision, no re-pin, no new pin tag. Findings 2–5 are documentation/carry-forward.

**Cap notice, for the owner not for me to resolve:** `fix_rounds: 5` already equals `max_fix_rounds: 5` (`enforcement-p2.progress.yaml:171`), so this verdict trips STOP condition A (`blocked_review`). I am reporting what the code does; the finding is a genuine, mutation-proven liveness defect in this milestone's own named deliverable, and its remedy is a fixture-only deletion — the escalation is whether to grant one more round for that deletion, which is the owner's call.

**Read-only disclosure:** all probes ran on `mktemp -d` copies of `apps/web/src` and of the fixtures, with mutant scanner copies written only under `$TMPDIR`. Nothing in the worktree was created, modified, staged, or committed; `git status --porcelain` shows only the pre-existing untracked `docs/handoff/reviews/enforcement-p2/`.

VERDICT: CHANGES-REQUIRED

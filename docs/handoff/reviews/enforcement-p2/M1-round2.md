## Adversarial merge-gate register — enforcement-p2 · milestone **p2-M1** · round 2

**Lens:** `frontend-conventions` (applied). Other standing lenses: **Rule 19** — no PHP/money/quantity runtime code in range; assessed only through the `no-parsefloat-on-money` RuleTester, which is substantive (8 valid / 5 invalid, `eslint-rules/no-parsefloat-on-money.test.mjs`) and is now CI-reachable for the first time. **Tenancy/authz, constructor injection, en+fr user-facing strings, migrations, Horizon queues** — **N/A**: no backend code, no migrations, no new queues, and the single production source change (`apps/web/src/features/treasury/statements/api.ts:113`) is a type-only narrowing with no rendered string.
**Range reviewed:** `c97e0d1ad..HEAD` (8 commits, 22 files). **Amending ruling:** none.

**Round-1 disposition — all five required findings CLOSED, verified against code, not the report:**

| R1 | Status | Evidence I re-ran |
|---|---|---|
| F-1 (P1) provenance discarded | **CLOSED** | `audit-i18n-completeness.mjs:320-332` now consumes `kind`; `unknown` folded into the aliased branch (fail closed). Baseline carries 23 `ar\|<ns>\|aliased\|*` entries and **zero** per-key `ar` entries in those namespaces. The unwired-file burn-down bypass is dead: the entry clears only on a wiring change. |
| F-2 (P1) non-discriminating fixture | **CLOSED** | `__fixtures__/…/prod-shaped/locales/ar/alpha.json` now authors **every** English key (`title`, `subtitle`, `nested.a`) while `lib/i18n.ts` keeps `alpha: enAlpha` under `ar`. Test at `:91-101` asserts `ar\|alpha\|aliased\|*` present **and** `ar\|alpha\|missing*` empty — deleting the `kind` branch goes red. |
| F-3 (P2) surface goes vacuous-green | **CLOSED** | Probed all three vectors on a scratch root: collapsed `ar:` block → `STRUCTURAL … no parseable block` EXIT=1; `beta` dropped from `ns` → `STRUCTURAL … missing from the ns array` EXIT=1; `ar` block + `locales/ar/` both deleted → `SCANNED SURFACE SHRANK` EXIT=1. |
| F-4 (P2) `pnpm lint` broken / preflight blind | **CLOSED** | `scripts/i18n-baseline-authority.sh` re-derives the blob from `i18n_baseline_seed_commit` and asserts `derived == mirror` before exec; `package.json:10` uses `audit:i18n:local`, CI keeps `audit:i18n` + `${{ vars.* }}`. `pnpm audit:i18n:local` → EXIT=0 on a clean machine. `preflight.sh:189-204` now runs the i18n gate **and** the web rule tests. Not a bypass: it cannot make CI green (CI's authority is the owner variable). |
| F-5 (P2) type declared `from`/`to` | **CLOSED** | `statements/api.ts:119` is `Pick<OffsetPaginationMeta, 'current_page'\|'last_page'\|'per_page'\|'total'>` — a `TypeReference`, which the consolidation guard does not flag. |

P3s F-7/F-8/F-9/F-10 also actioned (pin-tag drift test at `audit-i18n-completeness.test.mjs:331-345`; 8 real-CLI fail-closed tests with a genuine `git hash-object -w` blob; `--no-ratchet` **deleted** plus a test keeping it deleted; the false "ran in no workflow" claim corrected to PR→dev-dead in `08-DETECTOR-LIVENESS.md:39`; `alt`/`label` cases added, colon-form false positive given a ticket pointer).

**Protocol checks:** two-commit topology re-created correctly for the seed-changing fix round (gate-r4 R4-H-3) — `cae8e3bfb` = code, `cb618c12c` = baseline **only**, `29b6043bd` = progress-YAML **only**. Blob triple verified equal: `git rev-parse cb618c12c:apps/web/tools/i18n-completeness-baseline.json` = HEAD blob = YAML mirror = `da151bbc5…`. Pin tag retained as `ci-pin/enforcement-p2-r1` — defensible: `git tag -l 'ci-pin/*'` and `git ls-remote --tags origin 'refs/tags/ci-pin/*'` are both **empty**, so the pre-allocated name has never been created and never-reuse does not bind. H-9 holds (`frontend-lint` in `all-checks-pass` `needs`, `ci.yml:1149`). `pnpm test:eslint-rules` (6 suites) and `pnpm test:tools` (7 files / **127** tests) both green locally.

---

## P2 — fix before merge

**1. The provenance classifier reads trailing comments, so a one-line comment naming an `ar*` identifier reclassifies an English-aliased namespace as `english-spread` — and the milestone's own H-5 invariant is silently gone.** — `apps/web/tools/audit-i18n-completeness.mjs:162-169, 186-196` — **CONFIRMED**

`parseI18nWiring` captures the whole remainder of the assignment line into `raw` (`:162`, `nsOpen[3]`), comments included, and `classifyAssignment` (`:187`) runs `raw.match(/\b[a-z]{2}[A-Z][A-Za-z0-9]*/g)` over that text. An identifier mentioned only in a comment therefore counts as a wiring reference.

Probe on the package's own discriminating fixture — the single change is a trailing comment:

```
    alpha: enAlpha, // TODO: swap to arAlpha once the bundle lands

kind ar.alpha = english-spread
ar findings: [ 'ar|beta|missing|items_one', 'ar|beta|missing|items_other', 'ar|beta|missing|two' ]
                     ^ ar|alpha is GONE
```

Through the real CLI, with the pinned blob, the mirror and the working baseline all correct:

```
i18n completeness — 1 baseline entry now translated (burn-down; regenerate the baseline and re-pin…)
i18n completeness OK — 2 namespaces, authored keys: en=7 fr=7 ar=4; 4 known gap(s) held at the baseline.
EXIT=0
```

`ar|alpha|aliased|*` falls into `stale`, which is a `console.log` and never a failure (`:581-586`) — the loss of the invariant is **announced as burn-down progress**, byte-for-byte the failure mode §12/`missingScannedSurface` was added to prevent, arrived at through the one door that check does not watch (it verifies the `locale|ns` pair is still *scanned*, never that its *kind* is still honest).

Live blast radius, measured rather than asserted: I ran the same edit against a copy of the real `src/lib/i18n.ts`, commenting line 397 in the `ar` block. `kind ar.catalog` flips `en-aliased → english-spread` and the audit switches to trusting `locales/ar/catalog.json`. At HEAD that file is 261 of 283 keys, so 22 fresh `missing` findings still fail CI — **the production tree is protected today only by an incomplete translation file, not by the detector.** Author those last 22 Arabic keys (an ordinary, wanted deliverable) and the same comment clears the entire `catalog` namespace from the baseline while `i18n.ts` still serves `enCatalog` to every Arabic user. The more likely arrival is a deliberate revert — `catalog: enCatalog, // reverted from arCatalog, RTL layout broken` — after which the regression is invisible to the gate forever.

This is the C6 detector-rot class this package exists to close, reachable from inside the package's own trust anchor, and `docs/conventions/08-DETECTOR-LIVENESS.md`'s own checklist (*"the test goes red if the guard is neutered"*) does not hold for the neutering shown above.

Fix shape and why it is cheap: strip `//…` and `/*…*/` from `raw` before classifying (or classify only the expression text), plus one fixture case pinning it. **This is baseline-neutral** — I verified that zero production assignments contain any comment today (`assignments containing comments: 0` across all 3 locales × 56 namespaces), so the seed blob does not move and no two-commit re-pin round is required.

---

## P3 — notes

**2. The surface-coverage invariant protects only namespaces that appear in the pinned baseline; 12 of the 56 production namespaces are outside it.** — `audit-i18n-completeness.mjs:397-406, 269-276` — **CONFIRMED.** `missingScannedSurface` derives the expected surface from `protectedEntries`, so a namespace with no pinned finding has nothing to be missed. The `en`-block structural check (`:269`) is the only other line of defence, and it is escaped by removing the namespace from the `en` block too. Probed: fixture with `alpha` deleted from `ns` and from all three `resources` blocks, baseline holding no `alpha` entries → `i18n completeness OK — 1 namespaces … EXIT=0`, `locales/*/alpha.json` still on disk, no structural complaint. The 12 exposed namespaces are `validation, expenses, income, workshop-work-orders, scheduling, vehicle-ownership, pickers, vouchers, channels, replenishment, admin, notifications` — precisely the fully-translated ones, i.e. the ones whose regression the gate most wants to catch. Mitigating: the same edit breaks those translations at runtime, so it is self-revealing. Cheap close: also treat any `locales/en/<x>.json` with no namespace as structural.

**3. Any key whose last segment is a CLDR category name is treated as a plural family, so ordinary keys like `step_one` / `tier_two` will hard-fail CI for the lane that adds them.** — `audit-i18n-completeness.mjs:213-222, 341-348` — **PLAUSIBLE (no live instance).** `pluralFamilies` matches `^(.*)_(zero|one|two|few|many|other)$` with no check that the family is actually used with a count. I inspected all 46 plural findings in the seed baseline and every one is a genuine i18next family, so this is not a live false positive — but a new English key `wizard.step_one` would demand `wizard.step_other` in `en`, `_many` in `fr`, and six forms in `ar`, and the ratchet's removal-only rule offers no escape short of an owner re-pin. Worth naming in the M3 announcement alongside §3's table.

**4. The green summary's `authored keys` figure counts keys that sit behind aliases and are never served.** — `audit-i18n-completeness.mjs:310` runs before the aliased `continue` at `:331`, so `ar=4702` includes the 261 dead keys in `locales/ar/catalog.json`. Literally true ("authored"), and the adjacent `23 ns / 1998 keys served in English` line supplies the corrective — but the two numbers read as if they partition, and they do not.

**5. Disclosures I checked and accept as accurate.** The `english-spread` subtree residual recorded at `DECISION…§M1(10)` is real and correctly scoped out (nested spreads merge only the subtrees `i18n.ts` names, so a key under an unspread subtree is credited). §M1(3)'s policy table is now correct: 33 wired namespaces fail CI on a new untranslated English key, 23 aliased ones do not — which is what the code does, and which resolves round-1 F-6 rather than trading it. F-11 (one-directional `en→locale` diff) remains scoped out. The `git fetch origin tag` step (`ci.yml:897`) has no post-merge red window, because the owner's pre-promotion `workflow_dispatch` gate runs on the candidate SHA and therefore forces tag + variable creation *before* the merge lands.

---

## Bypasses attempted that FAILED (the guard held)

| Attempt | Result |
|---|---|
| Collapse the `ar:` locale block onto one line (prettier-shaped) | `STRUCTURAL — locale "ar" has a locales/ directory but no parseable block` · EXIT=1 ✔ |
| Drop `beta` from the `ns` array, leave `resources` intact | `STRUCTURAL — wired in the en resources block but missing from the ns array` · EXIT=1 ✔ |
| Delete the `ar` block **and** `locales/ar/` (hide the structural check) | `FAIL CLOSED: SCANNED SURFACE SHRANK — ar\|alpha, ar\|beta` · EXIT=1 ✔ |
| Complete `ar/catalog.json` on disk without wiring it (the R1 F-1 bypass) | Still `ar\|catalog\|aliased\|*`; entry does not clear ✔ |
| `env -u I18N_BASELINE_PROTECTED_BLOB` (isolated) | `FAIL CLOSED: … is unset` · EXIT=1 ✔ |
| Variable ≠ YAML mirror | `MIRROR DRIFT` · EXIT=1 ✔ |
| Variable → non-existent blob | `cannot read the protected baseline blob` · EXIT=1 ✔ |
| Matched growth (plant a gap + its baseline entry) | `RATCHET GROWTH: 1 baseline entry added` · EXIT=1 ✔ |
| `--no-ratchet` opt-out | Argument **deleted**; `unknown argument` + a test pinning its absence ✔ |
| Comment-flip an aliased namespace in **production** `i18n.ts` (`catalog`) | 22 fresh `missing` findings · EXIT=1 — held **only** because `ar/catalog.json` is 22 keys short; see P2-1 ✘ |

**Required for round 3:** finding 1 (strip comments before classifying + a fixture case pinning it). Baseline-neutral — no seed revision, no new two-commit re-pin, no new pin-tag allocation. Findings 2–4 are notes; 2 is a cheap same-touch close if the executor is already in `auditRoot`.

VERDICT: CHANGES-REQUIRED

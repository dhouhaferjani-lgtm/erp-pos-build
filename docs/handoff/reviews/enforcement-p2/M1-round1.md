# Adversarial merge-gate register — enforcement-p2 · milestone **p2-M1** · round 1
**Lens:** frontend-conventions (applied). Other standing lenses: no PHP/migrations/queues/money paths in range → Rule 19 applies only via the ESLint rule-test deliverables (assessed, F-10); tenant-scoping / constructor-injection / en+fr user-facing strings → **N/A**, no runtime UI or backend code added.
**Range reviewed:** `c97e0d1ad..HEAD` (5 commits, 19 files). **Amending ruling:** none.
**Two-phase pin topology:** VERIFIED — `96b7fd0e8` = baseline-only seed commit; `3615b103b` = YAML mirror pins only; `git rev-parse 96b7fd0e8:apps/web/tools/i18n-completeness-baseline.json` = `cbec4e5c5…` = the YAML `i18n_baseline_protected_blob` = the HEAD blob. Pin tag `ci-pin/enforcement-p2-r1` correctly pre-allocated (name only, tag not created — owner ceremony). `frontend-lint` present in `all-checks-pass.needs` (`ci.yml:1108`) → H-9 satisfied. `ci.yml` parses; `pnpm test:eslint-rules` (6 suites) and `pnpm test:tools` (7 files / 113 tests) both green locally.

---

## P1 — blocks

**1. An English-aliased namespace that happens to have an authored locale file is credited as TRANSLATED — the milestone's own gate-r1 H-5 invariant is not enforced.** — `apps/web/tools/audit-i18n-completeness.mjs:153,170-180,248-264` — **CONFIRMED**

`classifyAssignment()` computes `kind: 'own' | 'en-aliased' | 'english-spread'` and stores it at `:153`, but `auditRoot()` never reads `kind` — it uses `wiring.assignments` only for the "namespace missing from the resources block" structural check (`:227-231`). Coverage is decided purely by `readNsFile(root, locale, ns)` (`:184-194,256`). So provenance is *computed and discarded*.

Live consequence, verified against production sources: `src/locales/ar/catalog.json` exists with **261 authored keys**, but `src/lib/i18n.ts:397` wires `catalog: enCatalog` under `ar` (there is no `arCatalog` import anywhere in the file). Arabic users are served English for all 283 `catalog` keys; the audit reports only **22** gaps and baselines 22. Probe on the package's own fixture, with a complete `ar/alpha.json` added under the still-aliased `alpha: enAlpha`:

```
kind ar.alpha = en-aliased
ar|alpha findings: []          ← zero gaps reported for a namespace the runtime serves in English
```

Failure scenario (and a live ratchet bypass): the "burn down the Arabic baseline" workflow this gate is supposed to police can be satisfied by dropping JSON files into `src/locales/ar/` **without ever wiring them into `i18n.ts`**. The baseline shrinks, the ratchet reports progress, the pin gets rotated to the smaller set — and not one string changes for an Arabic user. This is exactly the vacuous-parity failure H-5 was written to forbid, arrived at from the other direction. It also means the seed baseline about to be sealed as the immutable owner-pinned authority is wrong by ≥261 entries for `catalog` alone, and every one of the other 22 en-aliased namespaces is one stray file away from the same hole.

Fix shape: consume `kind` — for `(locale, ns)` classified `en-aliased`, treat **every** English key as `missing` regardless of the file on disk; for `english-spread`, keep the file-based diff (already correct).

**2. The brief's mandatory production-shaped alias/spread tamper case is non-discriminating — it passes for the wrong reason.** — `apps/web/tools/__tests__/audit-i18n-completeness.test.mjs:76-88` + `apps/web/tools/__fixtures__/i18n-completeness/prod-shaped/` — **CONFIRMED**

Brief 2(c) deliverable 4(iii) requires a fixture "shaped like the live `ar` graph … the audit classifies the aliased/spread-supplied keys as gaps, NOT as present." The fixture ships `locales/ar/beta.json` only — there is **no `locales/ar/alpha.json`** (confirmed in the diffstat and on disk). So `it('counts the English-aliased namespace as fully UNTRANSLATED for ar')` is proven by *file absence*, not by aliasing. Deleting `classifyAssignment` and the entire `kind` field changes no assertion in that test except the three cosmetic `wiring.assignments.*.kind` equality checks at `:39-48`, which assert the classifier's return value and nothing about audit behaviour.

This fails the checklist the same package writes into `docs/conventions/08-DETECTOR-LIVENESS.md`: *"The guard has at least one test that FAILS if the guard is neutered (delete the detection branch → the test goes red)."* The detection branch here does not exist, and no test notices. Adding a complete `ar/alpha.json` to the fixture and asserting the keys are still reported as gaps is the case that discriminates — it currently fails (see F-1's probe output), which is the red-first evidence this deliverable is missing.

---

## P2 — fix before merge

**3. The gate silently goes vacuous-green when `i18n.ts` is reformatted or a namespace leaves the `ns` array; the drop is even reported as burn-down progress.** — `apps/web/tools/audit-i18n-completeness.mjs:119-158, 300, 464-469` — **CONFIRMED**

`parseI18nWiring` is a line-oriented regex parse with hard indentation assumptions: `^ {2}(['"]?)([\w-]+)\1:\s*\{\s*$` for a locale block (`:129`) and `^ {4}…` for a namespace entry (`:140`). A locale whose block does not match is simply not pushed into `locales` — there is no structural error for a locale that vanishes, and the `structural` loop at `:226-232` iterates `wiring.locales`, so a missing locale cannot report itself. Every baseline entry for that locale then falls into `stale`, which is a `console.log` and explicitly *never* a failure (`:464-469`).

Reproduced on the package's own fixture by collapsing the `ar` block to one line (a plausible prettier/refactor outcome):

```
locales parsed: [ 'en', 'fr' ]
structural: []
i18n completeness — 6 baseline entries now translated (burn-down; regenerate the baseline and re-pin…)
i18n completeness OK — 2 namespaces, authored keys: en=7 fr=7
EXIT=0
```

Same class via the `ns` array — dropping `'beta'` from `ns: [...]` (runtime translations keep working, since `resources` is static) yields `EXIT=0` and "4 baseline entries now translated". Scaled to production that is up to **4,594 Arabic findings disappearing green**, announced as progress.

There is no invariant tying the scanned surface to the pinned baseline: the checker knows every `(locale, ns)` the protected blob references and never asserts it still scans them. Cheap fix: fail closed if any `locale|ns` present in the protected baseline is absent from `wiring.locales` × `wiring.namespaces`.

**4. `pnpm lint` now hard-fails for every developer, and the mandated preflight gate still never runs the new detector — the "local preflight parity" deliverable is both broken and ineffective.** — `apps/web/package.json:10`, `scripts/preflight.sh:169-205` — **CONFIRMED**

`lint` gained `pnpm audit:i18n` (and `pnpm test:tools`). `audit:i18n` fails closed on unset `I18N_BASELINE_PROTECTED_BLOB` (`audit-i18n-completeness.mjs:396-406`) — verified `EXIT=1`. So after this lands, the `pnpm lint` documented in CLAUDE.md § Quality Gates fails for anyone who has not performed an authority-setup ritual documented only in a tool file's header comment and in the dispatch brief. No README, conventions page, or `.env.example` carries it.

Simultaneously, `scripts/preflight.sh` — the gate CLAUDE.md rule 10 makes mandatory before every commit — **does not invoke the `lint` chain at all**. It runs the discrete steps (`pnpm lint:eslint` `:174`, `audit:keys` `:178`, `audit:design-system` `:182`, `audit:quantity` `:186`, POS-only `test:eslint-rules` `:190`) and `pnpm test` `:203`. So `audit:i18n` and web `test:eslint-rules` are absent from preflight. Net effect for every open lane during the quiet window: a new red CI step with no working local reproduction path — the failure mode the quiet-window/announcement machinery exists to prevent. The brief's wording ("add the script to the local `lint` chain for preflight parity") assumed chain ≡ preflight; it isn't, and the executor verified the chain/CI distinction elsewhere in this very package without re-checking it here.

**5. The one production type change declares response fields the endpoint never emits.** — `apps/web/src/features/treasury/statements/api.ts:113`, `apps/web/src/types/pagination.ts:1-8`, `apps/api/app/Modules/Treasury/Presentation/Controllers/BankStatementController.php:53-58` — **CONFIRMED**

`StatementListResponse.meta` was widened from the four-field inline literal to `OffsetPaginationMeta`, which requires `from: number | null` and `to: number | null`. `BankStatementController::index` returns exactly `current_page / last_page / per_page / total` — no `from`, no `to`. The type now asserts two fields that are `undefined` on the wire. The decision doc's justification (§6: *"the test itself is the authority on what 'correct' means here"*) is not right on the facts — the guard detects *duplicate declarations of the four core fields*, and a non-lying alternative that the guard also accepts exists (`Pick<OffsetPaginationMeta, 'current_page' | 'last_page' | 'per_page' | 'total'>` is a `TypeReference`, not a `TypeLiteral`, so `offset-pagination-meta-consolidation.test.mjs:45-53` does not flag it). No consumer reads `meta.from` today (3 total `StatementListResponse` references, all declaration/return/type-arg), so this is latent, not live — but it is a false contract introduced by a guard-only package, and the next `meta.from ?? 0` will read `undefined` while TypeScript insists it cannot.

---

## P3 — notes

**6. The decision record understates the CI blast radius that the M3 announcement will be built from.** — `docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md:229-233` — **CONFIRMED.** §3 reason 3 says *"any new English key added to a namespace Arabic **already partially covers** fails CI immediately."* The scoping is wrong: for the 23 namespaces with **zero** Arabic coverage, every English key is already an enumerated baseline entry, so a newly added English key produces a `fresh` finding there too (`audit-i18n-completeness.mjs:260-263, 458-463`). The operative policy is therefore *full Arabic parity for all new keys, everywhere* — which contradicts the repo's standing en+fr posture and is a materially larger ask than "ratcheted toward parity" conveys. Since the M3 merge-announcement checklist is authored from this doc, lanes will be told the wrong thing.

**7. The pin tag is hardcoded in `ci.yml` with no drift check against the YAML mirror.** — `.github/workflows/ci.yml:897` vs `docs/handoff/progress/enforcement-p2.progress.yaml` `i18n_baseline_pin_tag` — **CONFIRMED.** A seed-changing fix round must allocate a fresh tag (tags are never reused) and edit both places; the checker only cross-checks the *blob* mirror, never the tag name. Fails closed if desynced (`git cat-file` on the new blob misses), so safe — but it costs a full gate round to discover. Assert `ci.yml`'s tag == the YAML `i18n_baseline_pin_tag` in the tools suite.

**8. The checker's entire fail-closed path is untested; the CLI has un-exercised trapdoors.** — `audit-i18n-completeness.mjs:333-354, 392-455` — **CONFIRMED.** The Vitest suite only exercises pure helpers (`flattenKeys`, `partitionByBaseline`, `addedKeysAgainstProtected`, `auditRoot`); `main()` — unset variable, mirror drift, unfetchable blob, `git cat-file`, exit codes — has no coverage. `--no-ratchet` (`:348`) disables the anti-growth authority outright, and `--baseline` / `--mirror` redirect it. Not CI-reachable (the step runs `pnpm audit:i18n` = no args), so not a bypass today, but for a package whose thesis is "guards rot when untested" the guard's own authority path is the untested part.

**9. `docs/conventions/08-DETECTOR-LIVENESS.md` states a motivating fact that is false.** — `docs/conventions/08-DETECTOR-LIVENESS.md` ("neither did the `apps/web/tools/__tests__/` suite [run in any workflow]") and `DECISION-…:274-279` ("that guard has run in **no workflow**") — **CONFIRMED.** `vitest.config.ts:11` includes `tools/**/*.{test,spec}.{ts,mjs}`, and `frontend-test` runs `pnpm test` (`ci.yml:988`) on PR→main, push→main and `workflow_dispatch`. The tools suite was PR→dev-dead, not CI-dead. (The `test:eslint-rules` half of the claim is correct.) The real gap this step closes is *PR→dev* coverage — worth stating accurately in a permanent convention doc.

**10. Rule-19 lens — rule tests are substantive.** `no-parsefloat-on-money.test.mjs` covers bare identifier, `Number()`, static member, computed string-literal member, case-insensitive match, plus carve-outs (`count` vs `cost`, member callee, non-identifier args) — 8 valid / 5 invalid, non-vacuous. `no-hardcoded-entity-route` 8/4, `no-untranslated-literal` 10/4. Two minor notes: `no-untranslated-literal` claims five user-facing attributes and tests three (`placeholder`, `aria-label`, `title`); and `:41-45` pins a known false positive (the `ns:key` colon form flagged as copy) as a `valid` case with an inline "recorded, not fixed" note — defensible under a liveness-only remit, but it makes the eventual rule fix a test edit, so it should carry a tracking line rather than only a comment.

**11. One-directional key comparison** — `audit-i18n-completeness.mjs:260-263` diffs `en → locale` only; `fr` authors 16 keys `en` does not, and those orphans are invisible. Explicitly scoped out and recorded in the decision doc §2. Noted, no action.

---

## Bypasses attempted that FAILED (the guard held)

| Attempt | Result |
|---|---|
| Run the checker with `I18N_BASELINE_PROTECTED_BLOB` unset (`env -u`, isolated subshell) | `EXIT=1`, fail-closed message ✔ |
| Point the variable at a non-existent blob (`deadbeef…`) | `EXIT=1`, "cannot read the protected baseline blob" ✔ |
| Set the variable to a value ≠ the YAML mirror | `EXIT=1`, MIRROR DRIFT ✔ |
| **Matched growth** — plant a baseline entry (`fr\|common\|missing\|zzz.plantedTamperKey`) so the working file covers a would-be new gap | `RATCHET GROWTH: 1 baseline entry added relative to the pinned protected baseline` → fail ✔ |
| Verify the seed/mirror/HEAD blob triple can be desynced within the candidate | All three equal `cbec4e5c5…`; any candidate edit to the baseline trips the growth check or the drift check ✔ |

---

**Required for round 2:** F-1 (consume `kind`, regenerate the seed under the two-commit topology per gate-r4 R4-H-3, re-pin the mirror), F-2 (discriminating alias fixture, shown red first), F-3 (surface-coverage invariant against the pinned baseline), F-4 (preflight wiring + a documented/DX-survivable local authority setup), F-5 (stop declaring `from`/`to`).

VERDICT: CHANGES-REQUIRED

## Adversarial merge-gate register — enforcement-p2 · milestone **p2-M1** · round 7

**Range:** `c97e0d1ada0ff73c7beb12dca478fa106df23128..HEAD` (`c9ac654b7`, 19 commits, 65 files). **Amending ruling:** none supplied to me; the parent ruling recorded verbatim at `docs/handoff/progress/enforcement-p2.progress.yaml:171-184` scopes this round to *"a scoped verification of exactly that delta; ACCEPT closes M1."* I verified the delta by execution and re-ran the whole-package evidence; the pre-delta body of work was gated in rounds 1–6 and is not relitigated.

**Lens `frontend-conventions`: applied.** The round-7 delta touches one test fixture and two docs — no `.tsx`, no component, no query key, no user-facing string, so `tenantScopedKey`, design tokens, RHF/zod and `t()` are **N/A for the delta**; whole-package they were cleared in round 6 (the only production source change in the range is a `Pick<OffsetPaginationMeta,…>` type narrowing at `apps/web/src/features/treasury/statements/api.ts:113`, which adds no strings). **Standing lenses:** Rule 19 **N/A** (no PHP, no money/quantity runtime code; the money-adjacent artifact is `apps/web/eslint-rules/no-parsefloat-on-money.test.mjs`, a RuleTester this package makes CI-reachable for the first time). Tenancy/authz, constructor injection, migrations, Horizon queues — **N/A** (no backend, no migrations, no queues). en+fr — **N/A**, all new output is developer-facing CLI text.

---

### The authorized delta — verified by execution, not by the report

`git show c9ac654b7 --stat` = exactly 3 files: the fixture, the DECISION doc, the progress YAML. **No production code, no test file, no baseline, no seed/mirror/pin movement** — confirmed against the diff, not the commit message.

| Claim | How I checked | Result |
|---|---|---|
| Deleting `englishOnly` restores the round-4 liveness pin | My own mutation harness (`$TMPDIR` copies of the scanner; fixtures read-only) — reverted `spreadsByScope`'s `scope: stack[stack.length - 1]` → `scope: stack.length - 1` (`apps/web/tools/audit-i18n-completeness.mjs:269`) and ran all five wiring fixtures | **CONFIRMED alive.** `sibling-scope` flips `en-aliased → english-spread`; every other fixture unchanged. Test `audit-i18n-completeness.test.mjs:510-513` therefore goes red on the revert — the exact condition `docs/conventions/08-DETECTOR-LIVENESS.md:99` demands |
| Predicate (b) keeps an independent pin | Mutant with `:327` deleted | **CONFIRMED** — only `english-only-subtree` flips (`test.mjs:551-554`) |
| Predicate (a) keeps an independent pin | Mutant with `:323` deleted | **CONFIRMED** — `spread-order` flips (`test.mjs:454-457`) |
| Baseline byte-identical, no re-pin | `pnpm audit:i18n:local` (which re-derives the blob from seed `cb618c12c` and asserts equality with the YAML mirror before running) | **EXIT=0**, 56 namespaces, **2917** known gaps, `ar: 23 ns / 1998 keys` behind aliases — identical to round 6 |
| Suites still green | `pnpm test:tools` → **7 files / 145 tests**, `pnpm test:eslint-rules` → **6 suites** | both **EXIT=0** |
| Nothing else moved | `git status --porcelain` | **empty** (the review dir is now committed at `efd19903e`) |
| CI wiring untouched by the delta | `ci.yml:889-899` (pin-tag fetch), `:902-915` (`pnpm audit:i18n` + `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}`), `:917-927` (`run: pnpm test:eslint-rules && pnpm test:tools`, the exact R2-H-3 string), `frontend-lint` present in `all-checks-pass` `needs` at `:1149` | **unchanged and correct** |

**Round-6 finding R6-1 (P2): CLOSED**, by execution rather than by assertion.

---

## P1 — blocking

None.

## P2 — fix before merge

None.

## P3 — notes

**1. The trap the delta removed can be re-laid silently: `sibling-scope` has no structural self-guard, while its sibling fixture does.** — `apps/web/tools/__tests__/audit-i18n-completeness.test.mjs:500-518` vs `:545-549` — **CONFIRMED, not live.** The `english-only-subtree` suite pins its own fixture *shape* (`expect(src).toContain('englishOnly: { ...enBeta }')` / `expect(src).not.toContain('{ ...arBeta, ...enBeta }')`). `SIBLING_ROOT` is only ever fed to `auditRoot()` (`:492`, `:511`) — nothing asserts what the fixture contains. I re-added `englishOnly: { ...enBeta }` to a `$TMPDIR` copy and re-ran both scanners: `head` and the depth-keyed mutant both return `en-aliased` for `sibling-scope`, every asserted kind in the suite identical, whole suite green — i.e. R6-1 recurs undetected. The only thing standing against it today is prose in `DECISION-…:733-739` and `§M1(39)`, and the person who would re-add the sibling is editing the fixture, not the handoff doc. Recommended (deliberately **not** required here — it is outside the parent-authorized delta): one `expect(src).not.toContain('englishOnly')` / "exactly two nested literals" assertion in the `sibling-scope` describe block, at M4 or as a follow-on.

**2. A sixth substantive fix landed but `fix_rounds` stayed at 5, so the ruling's raise is unconsumed.** — `docs/handoff/progress/enforcement-p2.progress.yaml:186-187` — **CONFIRMED.** The ruling raised the M1 counter to 6 (`max_fix_rounds_override: 6`) precisely to authorize this continuation; the harness's own loop text is *"apply the fix … commit, increment `fix_rounds`"* (`docs/handoff/SELF-REVIEW-HARNESS.md:46`). With `fix_rounds: 5` against an override of 6, a hypothetical further CHANGES-REQUIRED would read as one round of remaining budget — a round the ruling did not grant. No effect on the final gate: `scripts/adversarial-review-final.sh:163` checks only the **final** milestone's `fix_rounds`, and `:150` only top-level `max_fix_rounds` (correctly left at 5 — the encoding note at `:180-184` is accurate, I verified the field check reads top-level only). Moot if this verdict closes M1; worth one line in the handback so the count is honest.

**3. The M1 row's `updated:` pointer is two commits stale.** — `enforcement-p2.progress.yaml:191` — **CONFIRMED.** It still names `a7489cf4f`; the evidence commit (`efd19903e`) and the round-7 delta (`c9ac654b7`) are not reflected. No script reads the field; audit-trail accuracy only.

**4. The DECISION doc gained a full new §(39) with verification tables, beyond the ruling's literal "drop the stale sentence".** — `docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md:905-962` — **CONFIRMED, judged in-scope.** Documentation only, no behavioural surface; it records the ruling and the delta's verification, which is the evidence trail the harness expects. Recorded so the scope call is visible rather than assumed.

**5–8. Round-6 carry-forwards, re-confirmed unchanged by this delta** (each previously recorded and dispositioned; none is re-raised): bare-property subtree assignment (`sections: enSettings.sections`, no spread) is still silent — `audit-i18n-completeness.mjs:256-277`, open scope call at `DECISION-…:809-812`; the `...en*` classifier is prefix-only and would false-positive **loudly**, never silently (`:272`, `:327`); **no part of this CI wiring has executed on a real runner** — the pin-tag fetch under depth-1 checkout, the `vars.` mapping and `pnpm test:tools` under `frontend-lint`'s installer all run first at the owner's pre-promotion `workflow_dispatch`, and **both owner prerequisites (the repository variable *and* the annotated tag `ci-pin/enforcement-p2-r1` at exactly the accepted SHA) must exist before the merge lands or `frontend-lint` reddens every open lane**; `lint:ratchet` still swallows the local `lint` chain's tail (`apps/web/package.json:10`, `ci.yml:935`) — safe direction, the discrete steps gate first.

**Out of my scope, restated because it gates promotion, not this milestone:** the three inherited red CI gates at `base_sha` recorded in the YAML `blockers:` block (phpstan `CopiesDocumentData.php:309-310`, deptrac 99→174, pos lint-warning 40→84) still block the mandatory pre-promotion dispatch. P2 changed zero PHP files; this is the parent's decision, not a milestone finding.

---

## Bypasses attempted that FAILED (the guard held)

| Attempt | Result |
|---|---|
| Revert scope-keying → depth-keying, run all five wiring fixtures | `sibling-scope` flips `en-aliased → english-spread` → its assertion goes **red** ✔ (this is what round 6 blocked on; now fixed) |
| Delete predicate (a) (`:323`) | `spread-order` **and** `sibling-scope` flip → red ✔ |
| Delete predicate (b) (`:327`) | only `english-only-subtree` flips → red ✔, and the `sibling-scope` pin is provably *not* riding on (b) any more |
| `pnpm audit:i18n:local` with a tampered mirror expectation (via the authority script's own seed/mirror assert) | seed `cb618c12c:apps/web/tools/i18n-completeness-baseline.json` == mirror `da151bbc5`, EXIT=0 ✔ |
| Whole tools suite for hidden regressions from the fixture deletion (46 i18n tests incl. the orphan backstop that reads `sibling-scope/locales/{ar,fr}/zeta.json`) | 145/145 green ✔ |
| Re-add a third `englishOnly` sibling to `sibling-scope` in a temp copy, run head + depth-keyed mutant | Pin dies again, **suite still green** ✘ — no live defect, but see **P3-1** |

**Read-only disclosure:** every probe ran on `mktemp -d` copies (scanner mutants and a fixture copy under `$TMPDIR`; test/audit runs were read-only invocations of the repo's own scripts). Nothing in the worktree was created, modified, staged, or committed — `git status --porcelain` is empty at the end of this review as it was at the start.

**Disposition:** the single required round-7 item is closed and mutation-proven; the milestone's own named invariants (authored-locale provenance, two-phase pinned baseline with owner-variable authority, discrete `frontend-lint` steps carrying the exact `pnpm test:eslint-rules && pnpm test:tools` string, `all-checks-pass` membership, detector-liveness convention + per-detector tamper tests) are present and non-vacuous. The four P3s are records and one recommended hardening, none of which justifies another round.

VERDICT: ACCEPT

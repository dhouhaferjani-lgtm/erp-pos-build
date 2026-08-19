## Adversarial merge-gate register — enforcement-p2 · milestone **p2-M1** · round 3

**Lens:** `frontend-conventions` (applied). **Other standing lenses — N/A, stated explicitly:** Rule 19 — no PHP/money/quantity runtime code in range; the only money-adjacent artifact is the `no-parsefloat-on-money` RuleTester (`apps/web/eslint-rules/no-parsefloat-on-money.test.mjs`, 8 valid / 5 invalid, ran green) which this package makes CI-reachable for the first time — a net gain. Tenancy/authz, constructor injection, migrations, Horizon queues — **N/A**: no backend code, no migrations, no queues. en+fr user-facing strings — **N/A**: the only production source change (`apps/web/src/features/treasury/statements/api.ts:113`) is a type narrowing; all new output is developer-facing CLI text.
**Range:** `c97e0d1ad..HEAD` (10 commits, 33 files). **Amending ruling:** none.

### Round-2 disposition — all three findings CLOSED, verified by execution, not by the report

| R2 | Status | Evidence I re-ran |
|---|---|---|
| R2-1 (P2) classifier read comments | **CLOSED** | `stripComments()` at `:228`, consumed at `:196-198`. Re-ran the exact live tamper on a copy of production `src/lib/i18n.ts` (comment appended to `catalog: enCatalog,` at `:397`): `kind ar.catalog = en-aliased`, finding `aliased\|*` retained. **Red-first confirmed independently**: same fixture root against the pre-fix scanner at `29b6043bd` → `english-spread`, 22 `missing` findings, `aliased` entry gone. Fixture `__fixtures__/i18n-completeness/comment-tamper/` differs from `prod-shaped` by exactly one comment and asserts `raw` keeps it / `code` does not. |
| R2-2 (P3) unpinned namespaces unprotected | **CLOSED** | Backstop at `:362-374`. Probed on the **production** tree: removed `validation` from the `ns` array and from all three `resources` blocks → `✗ locale file locales/en/validation.json has no namespace in the ns array` (pre-fix: silent, 0 structural). `users` dead-file claim independently verified — only `locales/{en,fr}/users.json` exist, no import, no callsite. |
| R2-3 (P3) false plural families | **CLOSED** | `pluralFamilies` at `:283-299` now requires `{{count}}` **or** `_other` + a sibling. Fixture `edge-cases` pins `step_one`/`tier_two` as non-plural and `files_one` (count-only) / `items_one+_other` as plural. |

**Baseline-neutrality claim independently verified**, not taken from the report: `git rev-parse cb618c12c:…baseline.json` = HEAD blob = YAML mirror = `da151bbc5…`; regenerating with `--write-baseline` produces a **byte-identical** file; live finding sets pre-fix vs post-fix on the production tree are **identical** (2917 = 2917, 0 lost, 0 gained). So round 2 correctly did *not* re-create the two-commit seed topology, and the pins are legitimately unchanged. Bookkeeping commit `99d8f515b` touches only `fix_rounds`/`verdict`/`updated`.

**Suites re-run here:** `pnpm test:eslint-rules` → 6 suites green; `pnpm test:tools` → 7 files / **134** tests green; `env -u I18N_BASELINE_PROTECTED_BLOB pnpm lint` → **EXIT=0**; direct checker with the var unset → `FAIL CLOSED` EXIT=1. `frontend-lint` confirmed in `all-checks-pass` `needs` (`.github/workflows/ci.yml:1149`). Scope is clean: the only non-tooling production paths are `apps/web/package.json` and the disclosed `statements/api.ts` deviation, whose `Pick<>` shape I verified against the emitting controller (`BankStatementController.php:52-59` emits exactly the four fields).

---

## P2 — fix before merge

**1. The provenance classifier ignores SPREAD ORDER, so `{ ...arX, ...enX }` serves 100 % English while the gate reports full parity — the H-5 invariant, defeated by swapping two tokens.** — `apps/web/tools/audit-i18n-completeness.mjs:232-241` (`classifyAssignment`) with `:434-438` — **CONFIRMED**

`classifyAssignment` decides `english-spread` from *which* identifiers appear, never from their order. For an `english-spread` namespace the audit then trusts `locales/<locale>/<ns>.json` wholesale (`:434-438`). But in `{ ...arX, ...enX }` English wins **every** key, so nothing the locale authored is ever served.

Measured on the production tree, single-token edit to `src/lib/i18n.ts:439`:

```
    notifications: { ...arNotifications, ...enNotifications },

kind ar.notifications = english-spread
structural: 0
ar|notifications findings: 0        ← gate says fully translated
```

`ar/notifications.json` holds 20 keys and **0 of them are absent from `en`** — so after that edit every Arabic user sees English for the whole namespace and the detector is silent. This is the same class R2-1 and R1 F-1 were fixed for (a wiring shape that makes English-supplied keys count as translated), arriving through a door neither fix watches. The plausible arrival is not malice: "spread `en` last so untranslated keys fall back" is a natural — and wrong, since `fallbackLng: 'en'` already does it — edit, and for a *new* namespace it converges on the worst outcome, because the `missing` findings force the Arabic file to be completed and only then does the gate go green over a namespace that is entirely English.

The recorded residual at `DECISION-…-2026-08-19.md:380-383` does **not** cover this. It scopes out "a key authored under a subtree that is not spread" — a bounded, per-key effect. The order case is total and per-namespace, and it is a *regression vector* (an edit), not a static parsing limitation. A disclosure that understates the blast radius by that margin is not a valid scope-out.

**Fix is cheap and provably baseline-neutral.** A depth-aware scan of the spread graph over all 56 namespaces × 3 locales finds **0** assignments where an `...en*` spread follows a same-locale spread at the same brace depth — production is uniformly `en`-first at every level, including the nested `sections`/`company`/`fraudSettings` subtrees. So flagging `en`-after-locale (structural, or as an `aliased` finding) changes no live finding, does not move the seed blob, and needs no re-pin or two-commit round. One fixture case pins it.

**2. Deliverable 4's named tamper proof — the planted FAILING rule test — was never run or pasted.** — `docs/handoff/DECISION-enforcement-p2-ci-guards-2026-08-19.md` §M1(5)/(7) — **CONFIRMED absent**

The brief makes this an explicit, named requirement of 2(d) deliverable 4 (`CODEX-DISPATCH-…-2026-08-12.md:348`): *"plant a deliberately failing rule test (e.g. an invalid case the rule does not flag) → run the exact command the CI step invokes → it fails; revert → green; paste both outputs."* Grepping the whole `docs/` tree for `planted failing` / `Tamper proof` returns only the brief itself and the round-0 gate registers. §M1(5) justifies step-vs-job; §M1(7) enumerates *pre-existing* audit-script tamper tests (deliverable 2, a different requirement). Neither is this proof, and rounds 1–2 did not check for it.

The mechanism is almost certainly sound — `run: pnpm test:eslint-rules && pnpm test:tools` propagates a non-zero exit — but "almost certainly" is what this entire package exists to refuse; the point of the proof is that the new step is not a decorative green. Cost to close: plant one unflagged invalid case, run `pnpm test:eslint-rules && pnpm test:tools`, paste red, revert, paste green.

---

## P3 — notes

**3. The new orphan-file backstop watches only `locales/en/`, so deleting the English file along with the wiring escapes it.** — `:362-374` — **CONFIRMED.** Probed: `validation` removed from `ns` + all three `resources` blocks **and** `locales/en/validation.json` deleted → `structural: 0`, with `locales/{fr,ar}/validation.json` orphaned on disk and unscanned. Mitigating and why I am not raising it higher: that edit breaks the namespace for English users too, so it is loudly self-revealing. Cheap close: union the filenames across all locale directories rather than `en` alone.

**4. `KNOWN_UNWIRED_LOCALE_FILES` is a one-line silencer for the check just added, with no test pinning its membership.** — `:112` — **CONFIRMED.** Adding a namespace to that `Set` disables the backstop for it permanently. The `users` entry is correct and well-reasoned, but nothing fails if the set grows. The repo's own `08-DETECTOR-LIVENESS.md` checklist ("the test goes red if the guard is neutered") would be satisfied by a one-line test asserting the set equals `{'users'}`.

**5. `countBraces` runs on `raw`, not on the comment-stripped `code`.** — `:189-194` vs `:197` — **CONFIRMED, fails closed.** A trailing comment carrying an unbalanced brace (`// see the ar bundle in {locales/ar`) desynchronises the line walk: probed on production, 24 of 56 `ar` assignments parsed and **38 structural failures** raised. Safe direction, and no live instance — but the same one-token fix that closed R2-1 applies here (`countBraces(stripComments(raw))`), and the current split means the comment-blindness is only half-applied.

**6. The plural relaxation loses a genuine family that authors exactly one non-`_other` form and never interpolates `{{count}}`.** — `:296` — **CONFIRMED as a theoretical narrowing, no live cost.** Verified `lost: 0` against the pre-fix scanner across the whole production tree, and most shapes remain covered because the missing sibling surfaces as a `missing` finding from the English side. The trade (false positives on `step_one` are a hard CI failure for an innocent lane; this narrowing is silent) is the right one; recorded so the M3 announcement can say it out loud.

**7. Carried from round 2, still open: `authored keys: … ar=4702` counts the 1998 keys sitting behind aliases.** — `:404` runs before the aliased `continue` at `:425`. The adjacent `ar: 23 ns / 1998 keys served in English` line is the corrective, but the two numbers read as if they partition and they do not.

**8. Preflight parity is half-wired: `scripts/preflight.sh:189-204` adds `pnpm audit:i18n:local` and `pnpm test:eslint-rules`, but not `pnpm test:tools`.** Preflight calls `pnpm lint:eslint`, not `pnpm lint`, so the chain entry added at `apps/web/package.json:10` does not reach it. The CI step runs both halves; preflight reproduces one. Courtesy-level, per the brief — but it means a developer can pass preflight on a change that reddens the new CI step.

**9. `DECISION-…-2026-08-19.md:296-305` still presents the deviation as `meta: OffsetPaginationMeta`,** which is not what shipped; the `Pick<>` correction lands 140 lines later at §M1(14). Whoever reads the deviation record for the M3 announcement will quote the wrong shape.

---

## Bypasses attempted that FAILED (the guard held)

| Attempt | Result |
|---|---|
| Trailing comment naming `arCatalog` on the production `ar` alias (the round-2 P2-1 vector) | `kind = en-aliased`, `aliased\|*` retained ✔ |
| Same tamper against the pre-fix scanner `29b6043bd` | `english-spread`, 22 findings — **red-first confirmed** ✔ |
| Unwire a fully-translated namespace (`validation`) from `ns` + all `resources` blocks, keep the files | `locale file locales/en/validation.json has no namespace in the ns array` ✔ |
| Comment carrying an unbalanced `{` to derail the wiring walk | 38 structural failures — fails closed ✔ (see P3-5) |
| `env -u I18N_BASELINE_PROTECTED_BLOB node tools/audit-i18n-completeness.mjs` | `FAIL CLOSED: … is unset` · EXIT=1 ✔ |
| `pnpm lint` with the variable unset (the F-4 developer hard-fail) | EXIT=0 via the authority wrapper, which re-derives from the seed commit and asserts mirror equality ✔ |
| Regenerate the baseline hoping the three fixes moved it | byte-identical, 2917 entries, 0 lost / 0 gained ✔ |
| Delete the English file *as well as* the wiring | Silent — see P3-3 ✘ |
| Reverse the spread order on a fully-authored `ar` namespace | Silent, 0 findings — see **P2-1** ✘ |

**Required for round 4:** finding 1 (order-aware classification + a fixture case; verified baseline-neutral, so no seed revision, no two-commit re-pin, no new pin tag) and finding 2 (run and paste the planted-failing-rule-test proof). Findings 3–5 are cheap same-touch closes if the executor is already in `auditRoot`; 6–9 are notes.

VERDICT: CHANGES-REQUIRED

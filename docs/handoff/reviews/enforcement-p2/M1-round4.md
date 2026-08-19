## Adversarial merge-gate register — enforcement-p2 · milestone **p2-M1** · round 4

**Range reviewed:** `c97e0d1ad..HEAD` (12 commits, 40 files). **Amending ruling:** none.
**Lens `frontend-conventions`: applied.** Other standing lenses, stated explicitly: **Rule 19 — N/A** (no PHP, no money/quantity runtime code; the only money-adjacent artifact is `apps/web/eslint-rules/no-parsefloat-on-money.test.mjs`, a RuleTester this package makes CI-reachable for the first time — a net gain). **Tenancy/authz, constructor injection, migrations, Horizon queues — N/A** (no backend code, no migrations, no queues). **en+fr user-facing strings — N/A**: the sole production source change (`apps/web/src/features/treasury/statements/api.ts:113`) is a type narrowing; all new output is developer-facing CLI text. `tenantScopedKey`, design tokens, RHF — N/A, no `.tsx` touched.

### Round-3 disposition — verified by execution, not by the report

| R3 | Status | What I re-ran |
|---|---|---|
| R3-1 (P2) spread order ignored | **PARTIALLY closed → re-raised as P2-1 below** | Top-level reversal on the real tree (`notifications`, `treasury`, `expenses`, `products`, `locations`) → `en-aliased` ✔; nested-sibling reversal → still silent ✘ |
| R3-2 tamper proof missing | **CLOSED** | `DECISION-…-2026-08-19.md:640-670` now pastes RED (`Should have 1 error but had 0`, EXIT=1) and GREEN (EXIT=0, 6 suites + 140 tests). I re-ran the exact CI command string: `pnpm test:eslint-rules` → 6 suites green; `pnpm test:tools` → 7 files / **140** tests green |
| R3-3 orphan backstop `en`-only | **CLOSED behaviourally** (test is weak — P3-2) | Planted `locales/{ar,fr}/zeta.json` with no `en` counterpart → `translation file(s) locales/ar/zeta.json, locales/fr/zeta.json have no namespace "zeta"` ✔ |
| R3-4 `KNOWN_UNWIRED_LOCALE_FILES` untested | **CLOSED** | `audit-i18n-completeness.test.mjs:472-477` pins the set to exactly `{'users'}` |
| R3-5 `countBraces` on `raw` | **CLOSED** | `audit-i18n-completeness.mjs:194,198` both run `stripComments(...)` |
| R3-6..9 notes | **CLOSED** | Summary line now `ar=4702 authored (1998 behind aliases)` (verified live); `scripts/preflight.sh:199-204` runs **both** halves; `DECISION §M1(6)` shows the shipped `Pick<>` |

**Independently verified, not taken from the report:** `git rev-parse cb618c12c:apps/web/tools/i18n-completeness-baseline.json` = `git hash-object` of the working file = YAML mirror = `da151bbc5…`; regenerating from a copy of the production tree is **byte-identical**, 2917 entries — so round 3 was legitimately baseline-neutral and correctly did not re-create the two-commit seed topology. `frontend-lint` is in `all-checks-pass` `needs` (`.github/workflows/ci.yml:1149`) — H-9 satisfied verify-only.

---

## P2 — fix before merge

**1. The round-3 spread-order fix only sees the LAST spread pair at each brace depth, so reversing one *sibling* subtree — the shape production actually uses — is still totally silent.** — `apps/web/tools/audit-i18n-completeness.mjs:286-300` (with `:248-259`) — **CONFIRMED**

`classifyAssignment` groups spreads by **brace depth** and keeps only the last index per prefix (`seen.en = index` / `seen.own = index`, `:293-294`) before comparing. Sibling object literals nested inside one namespace all sit at the *same* depth, so a later English-first sibling overwrites the record of an earlier English-**last** one. The comment at `:287` — *"English-last at ANY brace depth means English wins there"* — and `DECISION-…-2026-08-19.md:632` are therefore both false as implemented.

Measured on a copy of the real `apps/web/src` tree, one token moved per case:

```
settings.sections  reversed  -> kind ar.settings = english-spread   (0 new findings)
settings.company   reversed  -> kind ar.settings = english-spread   (2917 -> 2917, 0 new)
settings.locations reversed  -> kind ar.settings = en-aliased        CAUGHT (it is the last sibling)

sales.partners.countLabels reversed -> english-spread  (0 new)
sales.partners.messages    reversed -> english-spread  (0 new)
sales.partners.types       reversed -> english-spread  (0 new)
sales.partners.validation  reversed -> en-aliased       CAUGHT (last sibling)
```

Failure scenario, identical in kind to the one round 3 blocked on: a lane edits `i18n.ts:385` to `company: { ...arSettings.company, ...enSettings.company }` — the natural-but-wrong "spread `en` last so untranslated keys fall back" edit. Every Arabic key under `settings.company` is then overridden by English at runtime; the gate reports 2917 findings, unchanged, exit 0. The `missing` findings that would otherwise force the file to be completed never appear, so the namespace converges on green-over-fully-English exactly as §(30) describes.

Scope: whole-namespace (depth-1) reversal **is** caught, and a namespace with a single nested subtree (`inventory.products`, `pos.transactions`) **is** caught. Unprotected are the multi-sibling groups — `settings` (3), `sales.partners` (5), `sales.documents`, `finance.overview`/`reports`/`hub`, `compliance.fraudSettings` — i.e. the largest namespaces in the tree.

This is not the residual disclosed at `DECISION-…:384-388` (that one scopes out *"a key authored under a subtree that is not spread"* — per-key, static). This is per-subtree, total, and a regression vector.

**Fix is cheap and provably baseline-neutral.** Replace depth-keying with **scope-keying**: increment a counter on each `{` and tag every spread with the id of the object literal it is directly inside, then flag `en`-after-locale within a scope. Production is `en`-first inside every scope (I re-ran the full sweep: 0 live reclassifications, regenerated baseline byte-identical), so no seed revision, no two-commit re-pin, no new pin tag. Pin it with a fixture that has **two** sibling subtrees, only the first reversed — the current `spread-order` fixture (`tools/__fixtures__/i18n-completeness/spread-order/lib/i18n.ts:30`) has a single spread pair and structurally cannot fail on this.

---

## P3 — notes

**2. The R3-3 orphan-union fix is pinned by a source-text grep, not by behaviour.** — `apps/web/tools/__tests__/audit-i18n-completeness.test.mjs:479-483` — **CONFIRMED.** The only assertion is `expect(src).not.toMatch(/const enLocaleDir/)`; no fixture exercises an orphan file that exists in `ar`/`fr` but not `en`. Reverting `:432-442` to an `en`-only `readdirSync` under any other variable name leaves the suite green — which is precisely the checklist item this package wrote (`docs/conventions/08-DETECTOR-LIVENESS.md:96`: *"delete the detection branch → the test goes red"*). The guard itself works (probed above); only its liveness proof is vacuous. Cheap close: a fixture with `locales/{ar,fr}/zeta.json` and no `en` counterpart.

**3. The pin tag is hardcoded in the workflow.** — `.github/workflows/ci.yml:898` — **CONFIRMED.** Every future re-pin (any baseline burn-down regeneration) allocates a new never-reused tag name and therefore requires a `ci.yml` edit in lockstep with the YAML. Desync fails closed and is caught by the consistency test at `audit-i18n-completeness.test.mjs:332-345`, but it costs a red CI round to discover. Worth one line in the M3 announcement so the next re-pinner knows both files move together.

**4. Nothing in this wiring has ever executed on a real runner.** The executor never pushes (H-7), so `git fetch origin tag …` under a depth-1 `actions/checkout` (`ci.yml:888-899`), the `${{ vars.I18N_BASELINE_PROTECTED_BLOB }}` mapping (`:915`), and `pnpm test:tools` under `frontend-lint`'s installer (`:927`) all run for the first time at the owner's pre-promotion `workflow_dispatch`. The handback's event-graph acceptance list should name these three step ids explicitly so the owner verifies them, not just job-level green. Both owner prerequisites (repository variable **and** annotated tag at exactly the accepted SHA) must exist **before** the merge lands, or `frontend-lint` goes red on every open lane — correct fail-closed direction, documented at `enforcement-p2.progress.yaml:47-49`, but it is a two-action prerequisite with a repo-wide blast radius.

**5. The gate permanently anchors CI on a handoff artifact.** — `audit-i18n-completeness.mjs:86-92`, `:597-602` — the mirror path is `docs/handoff/progress/enforcement-p2.progress.yaml`. Once P2 is complete, archiving or moving that per-package progress file breaks `frontend-lint` on every lane (fail closed, and the tools test at `:332` also goes red, so it is discoverable). Worth a "this file is now a CI input — do not move" banner at the top of the YAML.

---

## Bypasses attempted that FAILED (the guard held)

| Attempt | Result |
|---|---|
| `env -u I18N_BASELINE_PROTECTED_BLOB node tools/audit-i18n-completeness.mjs` | `FAIL CLOSED: … is unset` · EXIT=1 ✔ |
| Bogus variable value vs the YAML mirror | `FAIL CLOSED: MIRROR DRIFT` · EXIT=1 ✔ |
| Matched growth on the LIVE baseline (add `fr\|sales\|missing\|plantedTamperKey`, run against the pinned blob) | `RATCHET GROWTH: 1 baseline entry added … REMOVAL-ONLY` · EXIT=1 ✔ |
| Delete a real key from `fr/settings.json` on a copy of the production tree | `1 NEW gap(s) not in the baseline: fr\|settings\|missing\|title` · EXIT=1 ✔ |
| Orphan translation files in `ar` + `fr` only (English file deleted with the wiring) | structural failure naming both files ✔ |
| Reverse the top-level spread pair on 6 production namespaces | `en-aliased` in every case ✔ |
| Reverse a *single* nested subtree (`inventory.products`, `pos.transactions`, `settings.locations`, `sales.partners.validation`) | `en-aliased` ✔ |
| Round-3 `spread-order` fixture against the pre-fix scanner `0e9e18540` | `english-spread`, no `aliased` entry — **red-first confirmed** ✔ |
| Regenerate the baseline hoping the round-3 fixes moved it | byte-identical, 2917 entries ✔ |
| Reverse a **non-last sibling** subtree (`settings.company`, `settings.sections`, `sales.partners.{countLabels,messages,types}`) | Silent, 0 new findings — see **P2-1** ✘ |

**Required for round 5:** finding 1 only (scope-keyed spread classification + a two-sibling fixture; verified baseline-neutral, so no seed revision, no two-commit re-pin, no new pin tag). Findings 2–5 are cheap same-touch closes or announcement lines. `fix_rounds` is 3 of `max_fix_rounds: 5`.

VERDICT: CHANGES-REQUIRED

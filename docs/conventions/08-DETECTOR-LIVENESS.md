# 8. Detector Liveness — every guard ships with a tamper test, in the same CI lane

> **The convention, in one sentence:**
> **Every detector, ratchet, audit script, and lint rule ships with at least one test that proves it
> FIRES on a planted violation — and that test runs in the SAME CI lane as the detector itself.**

A guard that cannot fail is not a guard. A guard that fails only on a developer's laptop is not a CI
guard. Both failure modes have already happened in this repo, silently, for months.

---

## The two motivating incidents (both real, both in this repository)

### C6 — a detector regex rotted and reported zero for months

`apps/web/tools/audit-design-system.mjs` category **C6** ("local status map or switch should use
`StatusBadge`/`statusTone`") matches status-map declarations with a regex list (`STATUS_RE`). The
regex never matched the `Tone` / `Tones` type-name suffix, so a
`const map: Record<InvoiceStatus, StatusTone> = { … }` was invisible to it.

Result: **C6 reported 0 violations while 25+ live violations existed.** The audit ran green in CI on
every lane, every day. Nothing was broken — the detector simply had nothing to say, and no test ever
asked it to say something. (UI Wave 0 task T7 fixes the regex; this convention prevents the *class*.)

### `no-parsefloat-on-money` — an ESLint rule with no `RuleTester` at all

`apps/web/eslint-rules/` shipped `.test.mjs` RuleTester suites for
`no-dead-tailwind-token-interpolation`, `no-hardcoded-step` and `no-literal-decimal-places` — and
**none** for `no-parsefloat-on-money.js`, `no-untranslated-literal.js` or
`no-hardcoded-entity-route.js`. Three rules named in CLAUDE.md rule 19 as CI-failing precision guards
had no proof they still matched anything after any refactor of their AST handling.

Worse: **`pnpm test:eslint-rules` ran in no GitHub Actions workflow at all** — not on any event. Even
the three rules that *had* tests were CI-dead; they ran only if a human happened to run the local
`lint` chain (`scripts/preflight.sh` ran the **POS** half only). The `apps/web/tools/__tests__/` suite
was better off but still half-covered: `vitest.config.ts:11` includes `tools/**/*.{test,spec}.{ts,mjs}`,
so `frontend-test` picked it up via `pnpm test` — but that job is `if:`-gated to **PR→main, push→main
and `workflow_dispatch`**, so the tamper tests never ran on a PR→`dev`, which is where day-to-day work
merges. Precisely accurate: the rule tests were CI-dead everywhere; the tools tests were PR→dev-dead.

Both incidents share one root cause: **the detector's own correctness was never itself under test in
the lane that gates merges.**

---

## The rule

When you add or change any of the following, the tamper test is part of the change, not a follow-up:

| Guard kind | Where it lives | Liveness proof required |
|---|---|---|
| ESLint rule | `apps/web/eslint-rules/*.js` | `*.test.mjs` RuleTester beside it — **at minimum one `valid` case and one `invalid` case per distinct pattern the rule claims to catch** |
| Node audit / scanner | `apps/web/tools/audit-*.mjs` | a Vitest suite in `apps/web/tools/__tests__/` that feeds a **fixture containing a known violation** and asserts the scanner reports it |
| Shrink-only ratchet | any baseline-backed scanner | additionally: a **NEW violation not in the baseline must fail**, and (where the baseline is owner-pinned) a **matched-growth** case — plant a violation *and* add its baseline entry — must still fail |
| PHPStan / deptrac / architecture rule | `apps/api/` | a fixture class or pinned test asserting the rule reports the planted violation |
| Shell/CI drift check | `scripts/**` | a red/green demonstration recorded with the change, and the check wired into a workflow job |

And the half that is easy to forget:

> **Same lane.** The liveness test must execute in the same CI job (or a job on the same trigger set)
> as the detector. A test that only runs in `scripts/preflight.sh` proves nothing about the merge gate.
> Check `.github/workflows/ci.yml` — if you cannot point at the step that runs your test, it does not
> run. Note the workflow's true event graph: it starts on **PR→`main`, PR→`dev`, push→`main`, and
> `workflow_dispatch` only**. A direct push to `dev` starts nothing.

---

## What this looks like in practice

`apps/web/tools/audit-i18n-completeness.mjs` (the i18n completeness gate) instantiates the whole
convention:

- **fires on a planted violation** — `tools/__fixtures__/i18n-completeness/prod-shaped/` is shaped like
  the live `src/lib/i18n.ts` graph (a whole namespace aliased to English, a `{...en, ...partialAr}`
  spread) and the suite asserts those keys are classified as **gaps**, not as coverage;
- **fires on a structural blind spot** — a per-locale CLDR plural-category case (`fr` requires `many`,
  which a flat en↔fr key diff can never see);
- **fails on new violations** — the shrink-only baseline partition test;
- **fails on matched growth** — adding a baseline entry alongside a planted gap still fails, because
  the comparison is against an owner-pinned protected blob, not the editable file;
- **runs in the merge gate** — a discrete step in the `frontend-lint` job, which runs on every event
  that starts the workflow (including PR→`dev`) and is in the `all-checks-pass` `needs` list;
- **is reproducible locally** — `scripts/preflight.sh` runs the same checker through
  `scripts/i18n-baseline-authority.sh`, which re-derives the owner-pinned protected blob from the
  reviewed seed commit. A CI gate a developer cannot reproduce locally is a gate that gets disabled.

The ESLint rules follow the same shape: `no-parsefloat-on-money.test.mjs`,
`no-untranslated-literal.test.mjs` and `no-hardcoded-entity-route.test.mjs` were added by the same
package, and `pnpm test:eslint-rules` + `pnpm test:tools` now run as a discrete `frontend-lint` step
so all of them — old and new — actually gate merges.

---

## Checklist for a new guard

- [ ] The guard has at least one test that FAILS if the guard is neutered (delete the detection branch → the test goes red).
- [ ] Every distinct pattern the guard claims is covered by its own invalid case.
- [ ] At least one valid case pins the guard's carve-outs so it cannot become a blanket matcher.
- [ ] If the guard is baselined: a new-violation case, a stale-entry case, and (owner-pinned baselines) a matched-growth case.
- [ ] `grep` `.github/workflows/ci.yml` for the command that runs the test — and it is there.
- [ ] If the guard's test needed a new job: the job is in the `all-checks-pass` `needs` list.

---

**Related:** [`docs/qa/lint-warning-ratchet-2026-05-14.md`](../qa/lint-warning-ratchet-2026-05-14.md) ·
CLAUDE.md rules 14, 18, 19 · `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md` §3 2(d).

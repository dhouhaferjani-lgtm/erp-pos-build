# Gate t5b-gate-5 — Treasury Phase ⑤b Wave 5 frontend conventions review

You are reviewing `/Users/houssamr/Projects/syneriva/apps/erp.treasury-phase5` on branch `feat/treasury-phase5` at HEAD.

## Reviewer persona

Act as the adversarial **frontend-conventions-reviewer** for AutoERP (`apps/web`, React 19, strict TypeScript, Tailwind 4). Cite `file:line` for every finding. Rank findings BLOCKER / MAJOR / MINOR; BLOCKER or MAJOR means REJECT. Verify rather than trusting this request. Do not edit, commit, merge, or push.

Canonical requirements:

- Use canonical atoms/molecules (`PageHeader`, `Input`, `Select`, `Textarea`, `Button`, `Checkbox`, `StatusBadge`, `DataTable`, `Modal`); no raw parallel controls.
- Use design tokens only; no raw palette utilities or dynamic Tailwind composition.
- Every tenant-data query key uses `tenantScopedKey`; invalidations must not cross company scope.
- Money remains decimal strings and uses `big.js` only for display/state arithmetic; never `Number`, `parseFloat`, or float payloads.
- All user-facing strings and validation/errors are translated in EN/FR/AR; RTL uses logical direction.
- Status/state presentation must be honest: `resolved_by_creation` is successful, ignored lines are resolved but contribute zero remaining, and completion is unavailable until every line is resolved.

## Authority and diff

Read:

1. `.claude/agents/frontend-conventions-reviewer.md`
2. `docs/handoff/CODEX-treasury-phase5-2026-07-18.md`
3. `docs/superpowers/plans/2026-07-18-treasury-phase5b-bank-statement-reconciliation.md`, Tasks 10–13 and Gate 5
4. Rev 2 Treasury spec §§5–6 and §9
5. `CLAUDE.md`, especially rules 4, 5, 13, 19, and 20

Review the complete frontend Wave 5 diff:

```bash
git diff t5b-gate-4..HEAD -- apps/web docs/sessions/treasury-phase5b-e2e
```

Gate focus:

1. Statement list and upload wizard: accessible four-step flow, saved/new profile behavior, file input exception, preview reports, empty gate, exact string money, and no stale repository/profile state.
2. Workspace: accessible line selection, suggestions, manual partial allocation, action execution, create-from-line modal, ignore/unignore, provenance, completion acknowledgment, reopen permission, and zero remaining after ignored lines.
3. Tenant query keys and invalidations: inspect every query/mutation and ensure company switches cannot reuse stale statement, suggestion, movement, profile, or repository data.
4. Canonical component/token compliance, responsive layout, logical RTL utilities, and EN/FR/AR completeness. Inspect the design-system baseline removal for honesty.
5. Legacy cutover: FinanceHub, sidebar, and live route point to `/treasury/statements`; old page/API/hook/types/locales are truly gone without dead imports.
6. Live smoke quality: browser steps must assert real rendered preview/status/provenance/completion state and use robust selectors without mocking network calls.
7. Re-run guardrails and targeted tests. Do not accept an audit baseline update that absorbs new debt.

Fresh evidence (verify independently):

- Fresh live Playwright smoke: 6/6 passed in 26.2s; server-state assertions for Tier 3/4, balance semantics, and rejected-clear provenance all executed.
- Focused statement Vitest after the repair: 8 files / 32 tests passed; page-level completion-control coverage and the create-from-line account-picker suite cover the prior review gaps. Existing cutover paths remain available for independent rerun.
- `pnpm typecheck`: pass. Full `pnpm lint`: exit 0 (0 errors; repository-wide acknowledged warnings remain). React Doctor changed-scope is 92/100 with one pre-existing `StatementUploadWizard` useReducer warning.
- Fresh full web lint completed exit 0 with 0 errors, 0 new tenant-key/design-system findings, and custom rule tests green (repository-wide acknowledged warnings remain).

Use the default Vitest pool; never `--singleFork`. Do not change files.

## Required output

First line exactly `GATE VERDICT: APPROVE` or `GATE VERDICT: REJECT`.

Then findings ordered by severity with `file:line` evidence. End with `VERDICT: APPROVE` or `VERDICT: REJECT` and one line stating what must be fixed before the ⑤b exit review.

# Setup checklist spins forever for a principal that never gets a company

- **Filed:** 2026-08-06 · from the L6 media/onboarding lane (BUG-005) FE merge gate, round 2
- **Severity:** Minor (UX only, no fiscal/data impact) · **Surface:** `apps/web` — Settings → Setup Checklist
- **Status:** OPEN — follow-up, not merge-blocking
- **Confirmed by:** frontend-conventions-reviewer, `docs/superpowers/reviews/2026-08-06-l6-media-web-gate.md`, Round 2, MINOR-R2-3

## Symptom

`SetupChecklist` (`apps/web/src/features/settings/components/SetupChecklist.tsx:22-49`) gates
its `useQuery` on `enabled: tenantId !== null && companyId !== null` and renders a `Spinner`
whenever `isPending` is true — which is also true while the query is disabled (pending + idle).
Round 1 of this gate flagged the previous `isLoading` gate for falling through to a false
"0 of 0 completed" state in that window (MAJOR-1); gating on `isPending` instead fixed that, but
for a principal that **never** acquires a `currentCompanyId` (e.g. a user with no company
assigned), the query stays disabled indefinitely and the page now shows a spinner that will never
resolve — "loading" is itself a claim that will never come true.

## Suggested fix

Branch the permanently-disabled case explicitly, e.g. `enabled === false && !isFetched` (or track
disablement with a small local flag), and render an "unavailable / select a company" state instead
of the generic `Spinner`. Needs its own i18n keys (`onboarding.unavailableNoCompany` or similar,
en+fr at minimum, matching the pattern already used for the `degraded` step badges added in the
same round).

## Related — test discrimination nit (not a defect, no action required)

`SetupChecklist.test.tsx`'s disabled-window test originally asserted the spinner with
`container.querySelector('[data-testid="spinner"], svg')` — the `Spinner` atom
(`apps/web/src/components/atoms/Spinner/Spinner.tsx`) has no `data-testid`, so the selector reduced
to bare `svg`, which any icon would satisfy. Fixed in the round-2 tidy commit to
`.animate-spin` (targets the `Loader2` icon `Spinner` actually renders), which genuinely
discriminates the loading branch. No component change was made — just tightening the test.

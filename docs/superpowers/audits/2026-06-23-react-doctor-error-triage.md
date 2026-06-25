# React Doctor — error-level triage (apps/web)

**Date:** 2026-06-23
**Scope:** `apps/web` only. Baseline measured on `origin/dev` (not a feature branch).
**Tool:** `npx react-doctor@latest` (v0.5.8).

## Baseline

- **Score: 49 / 100** (dominated by ~1340 *warnings*; the score is a proxy, not a target — see note below).
- **28 error-level findings** on dev at time of triage.

The score barely moves when errors are fixed because warnings dominate the
weighting. The point of this pass was to clear genuine **defects** (the error
tier), not to chase the number. Mass-fixing the warning-tier migration-scale
rules (missing button `type`, control labels, giant components — each spanning
80–110 files) was explicitly **not** done: high review cost, real regression
risk on a production fiscal ERP, cosmetic benefit.

## Batch 1 — FIXED (shipped to dev, commit on top of `faf63dbfc`)

Behavior-preserving fixes; `tsc` + `eslint` clean; react-doctor errors 28 → 23.

| Rule | File | Fix |
|---|---|---|
| `secret-in-fallback` | `tools/ui-audit-shots.mjs`, `tools/phase3-doc-shots.mjs` | Fail closed when `AUDIT_PASSWORD` unset (removed hardcoded `'password'`) |
| `effect-needs-cleanup` | `features/company/CompanyProvider.tsx` | `clearTimeout` in effect cleanup (was a post-unmount `setCurrentCompany`) |
| `no-nested-component-definition` | `features/treasury/PaymentMethodsPage.tsx` | Hoisted `CapabilityBadge` to module scope (pure `{enabled,label}`, no parent closure) |
| `no-jsx-element-type` | `components/catalog/CategoryTree.tsx` | `JSX.Element` → `ReactNode` (type widening) |

## Batch 2 — `no-adjust-state-on-prop-change` ×19 → DECISION: LEAVE ALL (documented)

Owner decision 2026-06-23: **do not refactor.** These are not safe mechanical
fixes — the canonical recipe forbids the "track prevProps" patch and requires
either eliminating duplicated state or a `key`-based remount **in the parent**,
i.e. behavior changes on payment/POS/CRM/variant surfaces. The current code is
functionally correct (stale frames are invisible — components render `null`
when closed). 19 line-hits collapse to 10 effects:

| Site(s) | What it does | Verdict |
|---|---|---|
| `pos/layouts/POSLayout.tsx:64` | `setShiftDuration(null)` guard inside a `setInterval` timer effect | **False positive** — real timer effect |
| `documents/DocumentForm.tsx:240` | one-shot apply of `?partner=` URL param, latched by a flag | **Effectively fine** — legit one-time init |
| `molecules/pickers/PartnerPicker.tsx:118`, `ServicePicker.tsx:102`, `VehiclePicker.tsx:112`, `ui/UserPicker.tsx:88` | `setActiveIndex(-1)` when search results change | Real; proper fix = rework keyboard-highlight state model (pattern A) per picker. Low impact, keyboard-nav regression risk. Deferred |
| `pos/organisms/Calculator/Calculator.tsx:32-35` | reset 4 states when `isOpen` → false | Real; proper fix = conditional-mount/`key` in `POSPage` + drop effect + change documented `isOpen` contract. Behavior change. Deferred |
| `organisms/RecordPaymentModal/RecordPaymentModal.tsx:149-156` | reset 7 form fields from `prefill` on open | Real; proper fix = `key={prefill.reference}` remount in 3 detail pages + `useState` initializers. Behavior change on payment modal. Deferred |
| `crm/components/PartnerSelect.tsx:48` | sticky display-name cache for selected partner | Real but stickiness is intentional; subtle refactor. Deferred |
| `catalog/components/ProductVariantMatrixEditor.tsx:276` | hydrate editor state from loaded variants (seed-ref guarded) | Real but complex hydration; variant-authoring surface. Deferred |

If revisited: do each as a separate TDD commit (all these components have
co-located vitest tests), pausing for sign-off on the payment/POS ones.

## Other remaining errors (not addressed)

- `no-mutable-in-deps` `components/organisms/Sidebar/Sidebar.tsx:455` — **false positive**. The rule assumes `window.location`; it's react-router `useLocation()` (reactive). Code is correct.
- `query-destructure-result` ×2 `stock-transfers/pages/CreateStockTransferPage.tsx` — real but marginal TanStack micro-opt; rewrites multiple refs. Low value.
- `socket/low-supply-chain-score` `package.json` — `vitest@3.2.4` flagged for a CVE on Socket's vuln axis. A test-runner bump decision; run `npm audit` and bump deliberately (could ripple through the suite).

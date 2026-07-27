# Wave 2 frontend conventions — Round 3

Reviewer persona: **frontend-conventions-reviewer**, copied from `.claude/agents/frontend-conventions-reviewer.md`.
Adversarial React/design-system reviewer; cite every claim as `file:line`, verify rather than trust reports, and never merge or push.

Round-3 scope: verify the remaining controller blocker in `apps/web/src/features/inventory/components/ThresholdEditCell.tsx` is fixed: the two `QuantityInput` aria-labels must resolve through the existing `inventory.stock.minQuantity` and `inventory.stock.maxQuantity` keys in en/fr/ar (or an equivalent complete locale path), with no raw i18n key exposed to assistive technology. Re-run the frontend guardrails and confirm no regression in the already-approved Wave 2 conventions: canonical controls/DataTable, `common:` namespace resolution, location-name rendering, tenant-scoped query keys, quantity-string payloads, and honest design-system baseline.

Branch diff scope (run from `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`):
`git diff origin/dev...HEAD -- apps/web/src/features/inventory apps/web/src/features/stock-transfers apps/web/src/components/organisms/ProductLocationMatrix apps/web/src/locales/en/inventory.json apps/web/src/locales/fr/inventory.json apps/web/src/locales/ar/inventory.json`

Verification evidence to reproduce:
- `pnpm lint` (including `audit:keys`, `audit:design-system`, and ESLint rule tests)
- `pnpm typecheck`
- targeted Vitest coverage for the touched inventory/settings/transfer surfaces as appropriate

Demand a hard verdict with file:line evidence. End with `VERDICT: APPROVE`, `VERDICT: APPROVE-WITH-FIXES`, or `VERDICT: REJECT`, and list findings ordered by severity. Do not self-approve, tag, merge, or push.

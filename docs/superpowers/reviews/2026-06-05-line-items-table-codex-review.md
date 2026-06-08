# Codex Review — Line Items Table Extraction

Date: 2026-06-05
Branch: `codex/line-items-table`
Base: `origin/dev` (`f6d49de16`)

## Scope

- Added shared `LineItemsTable` and `QuantityCell` primitives under `apps/web/src/components/molecules/line-items`.
- Refactored `DocumentLineEditor` onto the shared table while preserving document-only search, services, quick product creation, designation, notes, taxes, and totals.
- Refactored the current `origin/dev` stock transfer create line grid onto `LineItemsTable`.
- Added regression coverage for shared table behavior, document subtotal/tax/total behavior, quantity recalculation, and transfer table wiring.

## Adversarial Review

Model used: `gpt-5.5` high effort. Opus was requested by the task prompt but is not available in this environment, so the strongest available review model was used.

Findings and resolutions:

1. **P1: Shared line-items directory was untracked.**
   - Resolution: the directory is part of this branch's intended changes and is included in verification/staging scope.

2. **P2: Step 3 skipped an available transfer refactor target on `origin/dev`.**
   - Resolution: `CreateStockTransferPage` now uses `LineItemsTable` for the current dev transfer columns. Step 1/2-specific columns and unit-aware precision still remain dependent on those branches landing or this branch being rebased over them.

3. **P3: Empty product codes changed from `-` to blank.**
   - Resolution: restored explicit empty-string fallback without reintroducing the lint warning.

4. **P3: `QuantityCell` allowed editable no-op inputs.**
   - Resolution: `onChange` is now required by the component props.

5. **P3: Drag handle rendered as a focusable button with no keyboard behavior.**
   - Resolution: replaced the no-op button with a decorative drag icon while keeping row-level native drag behavior. Full keyboard reordering remains a future enhancement, not part of this extraction.

## Verification

- Focused Vitest: `LineItemsTable`, `DocumentLineEditor`, `CreateStockTransferPage.lineItemsTable` passed.
- Full web Vitest: 287 files passed, 2205 tests passed, 1 skipped.
- TypeScript: `pnpm --filter @autoerp/web typecheck` passed.
- ESLint: full `pnpm --filter @autoerp/web lint` passed with 0 errors and the existing warning backlog.
- React Doctor: `npx react-doctor@latest --verbose --diff origin/dev --blocking warning` passed after replacing touched-file barrel imports with direct imports.
- Browser checks against Vite with mocked APIs:
  - Document quote create page rendered shared headers and quantity step on desktop/mobile with no console errors.
  - Stock transfer create page rendered shared headers, quantity step, and bottom add-line control on desktop/mobile with no console errors.
- Hardcoded color scan over changed TSX files returned no matches.

## Residual Notes

- `DocumentLineEditor` still has pre-existing precision lint warnings around `Number()`/`parseFloat()` in document total math. They were intentionally not changed in this behavior-preserving extraction.
- Mobile document tables remain horizontally scrollable because the document column set is wide by design.

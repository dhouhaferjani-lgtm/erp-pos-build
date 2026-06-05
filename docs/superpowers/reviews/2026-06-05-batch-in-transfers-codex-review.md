# Batch Allocations in Stock Transfers - Adversarial Review

Date: 2026-06-05
Branch: codex/batch-in-transfers
Reviewer: Local Codex adversarial review after gpt-5.5 reviewer timeout

## External Reviewer Status

The requested Opus adversarial review was not available in this environment. A gpt-5.5 high-effort adversarial reviewer was started as the strongest available substitute, but it timed out repeatedly and did not return findings before final verification.

## Findings

### Fixed: source-location changes kept stale lot allocations

Risk: A user could select lots for one source warehouse, change the source location, and submit allocations that were chosen against stale availability.

Resolution: `CreateStockTransferPage` now clears all line batch allocations and closes the lot panel whenever the source location changes. A regression test covers the case by confirming submit is blocked until lots are reselected.

### Fixed: lot-panel render mutated query cache order

Risk: Rendering the batch allocation panel sorted `batches` in place, mutating the array returned by React Query.

Resolution: The render path now sorts a copied array with `[...batches].sort(...)`.

### Fixed: backend batch-stock availability lookup lacked explicit tenant scope

Risk: The lookup was constrained by batch and location, but explicit tenant scoping is clearer and safer for multi-tenant data paths.

Resolution: `assertBatchCanIssue()` now includes `tenant_id` when locking `inventory_batch_stock`.

## Verification Notes

Browser verification covered desktop and mobile transfer creation with mocked authenticated API data:

- FEFO selected the valid earliest transferable lot.
- Expired lot quantity input was disabled.
- Submitted payload included `batch_allocations: [{ batch_id: 101, quantity: "1.0000" }]`.
- Desktop and mobile runs reported zero console errors.

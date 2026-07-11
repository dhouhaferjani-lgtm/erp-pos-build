# Opus adversarial review — inventory section

Model: `claude-opus-4-8`

Scope: the shared inventory section, embedded stock presentation, and stock-cost permission gate.

## Requirement verification

- **Shared view/edit section — PASS.** One discriminated `ProductInventorySection` is consumed by both pages.
- **Order `hero → general → pricing → inventory` — PASS.** Both page compositions use this order at the current milestone.
- **Embedded stock without nested card chrome — PASS.** The section provides the one card and `ProductStockLevels` returns a bare content wrapper when embedded.
- **Quantities visible to non-holders — PASS.** On-hand, available, reserved, incoming, and projected quantities render independently of cost permission.
- **Stock value/WAC/cost multiplication gated — PASS.** The section passes no cost to non-holders; the stock component also independently requires `canViewCostPrices` for multiplication and the value/WAC display block.
- **Opening flows and cost coupling — PASS.** Locked, unlocked, reset request/confirm/cancel, and view-stock flows are preserved. Eligible opening quantity still seeds `opening_unit_cost` from purchase price.
- **Batch and reorder precision — PASS.** Opening retains four-decimal quantity input, reorder fields retain resolved unit precision, and valuation remains decimal-string math.
- **No duplicate inventory surface — PASS.** The legacy detail registry entry and inline edit card were removed.
- **Generated types and dependency direction — PASS.** View receives the generated DTO-derived public product with cost fields stripped.

## Findings

No BLOCKER or MAJOR findings.

### MINOR — defensive null guards were dropped during extraction

The `units_per_pack` and `default_shelf_life_days` coercers originally mapped both the empty string and `null` to `null`. The extracted versions handled only the empty string, so a raw `null` would become `0` through `Number(null)`.

Reconciliation: restored `value === '' || value === null ? null : Number(value)` in both coercers and typed their input as `string | null`.

### Notes

- The product section imports the existing inventory stock component while inventory pages import the shared product section. This matches the explicit task plan and current extraction architecture.
- The inventory components barrel still exports `ProductStockLevels`; retaining that public export is harmless.
- Tenant query-key expectations for pagination and decimal-string margin values were stale baseline assertions encountered by this by-path run; they were aligned to the current producers.

## Verdict

**APPROVED.** All inventory and confidentiality guardrails pass. The one non-blocking semantic drift was reconciled before commit.

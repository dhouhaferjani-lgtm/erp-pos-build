# T1: Freight capitalization has no justifying document or GL counterpart

Status: open · severity: high · owner: inventory valuation / transfer lifecycle T-2

`transfer_cost` is a free-entered amount with no justifying carrier document under document-per-action. `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:631-709` allocates it to lines and capitalizes it through WAC at completion, with no freight-clearing/expense counterpart and no journal entry.

For ten owned units at 5.000000, freight 10.000 raises cost to 6.000000 while `journal_entries` remains zero. Inventory carrying value increases without the corresponding GL entry. When that freight later exits through COGS, it can be double-counted if the carrier invoice was also expensed.

Current-behaviour pins: `StockTransferCompleteConcurrencyPostgresTest::test_freight_capitalization_uses_ten_owned_units_not_fourteen_inside_transaction` and `StockTransferEdgeCasesTest::test_wac_counts_transit_exactly_once_before_and_after_terminal_action` assert zero journals before and after capitalization. These certify the current absence, not accounting completeness.

Acceptance: define the justifying document and freight-clearing/expense treatment; reconcile capitalized freight with carrier invoices and COGS; cover cancellation, partial receipt, write-off and retry exactly once. Any inventory GL posting must use `InventoryGlPostingBuffer`, with tenant/company and accounting-period checks. Replace the zero-journal pins with the approved balanced, exactly-once posting assertions in the same change. No GL implementation is included in T-1.

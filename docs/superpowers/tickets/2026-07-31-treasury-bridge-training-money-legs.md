# Ticket: TreasuryReceiptBridge money legs are not training-gated

Found during Lane C spec r2 treasury review (2026-07-31). Pre-existing, NOT introduced by any lane.

`apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:401-402`: only
`postCashRoundingEntry`/`postToleranceWriteoffEntry` sit behind the
`$view->payload->trainingFlag !== true` gate. The tender-leg loop ABOVE that gate creates Payment
rows, GL entries, and repository movements with NO training gate — contradicting the file's own
comment ("Training receipts never reach GL at all"). A TRAINING sale therefore moves real money in
Treasury/GL/repository balances.

Fix shape: hoist the training gate above the tender-leg loop (or early-return for training
receipts before any money write), with a projection test proving a TRAINING SALE_RECEIPT produces
zero Payment/GL/movement rows. Coordinate with the Lane C code phase (same file is in its roadmap
surface). Launch exposure: low for tenant #1 if training mode is unused pre-launch, but the smoke
protocol (E-2) exercises paths adjacent to this — verify no training receipts are taken during
smoke, or land the fix first.

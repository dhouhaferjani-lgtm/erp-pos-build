# Ticket: CashDrawerService expected-cash is blind to v3 activity and writes wrong shift variance

Found during Lane C spec reviews (r2 treasury, confirmed r3). Pre-existing; the v3 cutover made it
the default shape. `CashDrawerService::calculateExpectedCash()` (apps/api/.../CashDrawerService.php
:387-413) sums only pos_cash_drawer_operations; NO v3 path writes such rows (the only REFUND-op
writers are the legacy return/void/exchange paths). `ShiftManagementService.php:168-182` calls it on
every close where the cash-count path was not used and PERSISTS expected_cash/variance from it →
opening-float-only figures on v3 shifts. Also note `scale()` uses a no-arg getScale() (:48-51) —
fatal if ever queued (rule 19).

Fix shape: v3 branch reading device-authored Z figures (cash_count.expected_cash via
ZReportProjection) or refusing to persist a server-computed variance for device-authoritative
terminals. Sequencing: the Lane C write-off drawer adjustment must NOT write into this table ahead
of this repair (r3 treasury I-3: a lone write-off row makes expected_cash strictly worse than blind).

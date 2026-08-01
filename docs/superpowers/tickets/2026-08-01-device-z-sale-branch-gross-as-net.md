# Ticket (URGENT): device Z/EOD/X SALE branch treats gross line_total as net — live net/VAT decomposition defect

Found by the Lane C wave-2 fix wave (2026-08-01) while fixing the identical defect on the refund
branch (consolidated finding 4). PRE-EXISTING and LIVE today on every device build including
staging: `receiptService.ts` writes the cart's GROSS (TTC) `line_total` into
`offline_receipts.lines[]`, and the SALE branch of `zReportService.ts` / `endOfDayPreview.ts` /
`generateLocalXReport` calls that value `lineNet` and adds VAT on top. Totals are correct; the
net/VAT decomposition inside signed Z_REPORT/X_REPORT events is not (net overstated by the VAT
amount per taxed line). Existing sale fixtures author `line_total` as NET — they mask the bug by
not matching the real writer (same masking pattern the refund-side finding called out).

Interim asymmetry (accepted by orchestrator ruling 2026-08-01): the refund branch is now correct,
so a fully-refunded taxed sale leaves a +VAT/-0 residue in the Z buckets (e.g. +2.00 net / +2.00
VAT on a 12.00 gross / 2.00 VAT line) instead of netting to zero.

Why not fixed in the wave: the fix changes SIGNED Z_REPORT bytes for ordinary sale-only shifts —
it needs its own fiscal review gate, a sealing/versioning strategy decision (new event_version or
in-place semantic correction), and real-writer end-to-end fixtures for the sale path (mirror
`refundReportingEndToEnd.test.ts`).

Disposition: own micro-lane with fiscal-pos-reviewer gate. Reference: fix-wave report
(gitignored session doc) and docs/superpowers/reviews/2026-08-01-lane-c-wave2-consolidated-findings.md
finding 4 for the corrected refund-side pattern to mirror.

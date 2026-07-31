# Ticket: buildFiscalCloseInput tolerance_summary mapping has no direct test

Found during Lane B review (2026-07-31). `apps/pos/src/lib/offline/zReportService.ts` (~:493,
mapping ~:670-675): `zReport.tolerance_summary → closeInput.toleranceSummary` is exercised only via
the mocked `generateZReport` path; B3's test proves the authoring→signed-bytes leg with a
hand-built input. A regression in the mapping itself would not be caught. Fix shape: one test
driving `closeZSession`/`generateZReport` with the real authoring path, or a toHaveBeenCalledWith
pin on the mock. Post-launch hygiene; B3's guard covers the signed-bytes leg.

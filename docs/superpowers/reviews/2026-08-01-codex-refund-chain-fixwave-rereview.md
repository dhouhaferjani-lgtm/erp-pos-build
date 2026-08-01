# Lane C Wave 2 Fix-Wave Scoped Re-Review

- **Commit range reviewed:** `8a849f9bd..6cb629d96` (8 commits)
- **Required files read:**
  - `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/docs/sessions/LANE-C-wave2-consolidated-findings.md`
  - `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/docs/sessions/LANE-C-fixwave-rereview-package.diff`
  - `/Users/houssamr/Projects/syneriva/apps/erp.refund-chain/docs/sessions/LANE-C-wave2-fixwave-report.md`
- **Additional verification read:** the live read-only worktree source and all production call sites affected by Codex C-1/C-2/C-3 and M-1 through M-6, plus the prior Codex review record at `/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-08-01-codex-refund-chain-wave2-review.md`.
- **Scope:** only the 19 supplied findings and new Critical/Important breakage introduced by this fix range. The pre-ruled exclusions were not re-litigated.

## Findings

| # | One-line finding summary | Verdict | Evidence |
|---:|---|---|---|
| 1 | X-report refund blindness | **ADDRESSED** | `apps/pos/src/api/reportApi.ts:455-480,506-565` now branches refund rows, keeps sale totals/count sale-only, records positive refund count/amount, subtracts refund VAT/payment buckets, and signs `report.refunds_amount`. |
| 2 | Original mirror not bound completely to the signed event | **NOT ADDRESSED** | `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:226-245,262-284` checks the row's `event_type`, byte equality, and payload validation, but never validates or compares the parsed envelope's own event type, version, event identity, or terminal identity to the selected row; selected `terminal_id` is unused. |
| 3 | IEEE-754 quantity in signed/authority paths | **NOT ADDRESSED** | `apps/pos/src/stores/refundCheckoutStore.ts:123-126,301-326` normalizes the number-derived quantity at scale 4, while `apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts:265-266,443-457` reformats it at the frozen scale 3; the snapshot/cap/local row therefore do not carry the signed quantity string verbatim end-to-end (the one accepted `Math.abs` is not the basis of this verdict). |
| 4 | Z/EOD/X gross-as-net VAT double-count for refunds | **ADDRESSED** | `apps/pos/src/lib/offline/zReportService.ts:864-875`, `apps/pos/src/lib/offline/endOfDayPreview.ts:284-293`, and `apps/pos/src/api/reportApi.ts:459-469` all derive refund net as gross minus VAT with explicit currency scale. |
| 5 | Payout confirm/dispute reconciliation broken | **ADDRESSED** | `apps/pos/src/lib/db/repositories/refundIntentRepository.ts:320-409` implements one guarded mutually-exclusive resolution, asserts one affected row, defines pending confirmation as both timestamps null, and sends either resolved outcome to reprint recovery. |
| 6 | Approval re-author can dead-letter signed refunds | **NOT ADDRESSED** | `apps/pos/src/lib/operatorApproval/posOverrideAuthoring.ts:204-211` reads the signed approval ID on the normal path but explicitly falls back to a newly minted candidate when existing canonical bytes are unreadable, recreating an approval/evidence identity mismatch instead of failing closed. |
| 7 | False `original_business_date` sealed | **ADDRESSED** | `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:306-325` returns the signed, row-checked original business date, and `apps/pos/src/stores/refundCheckoutStore.ts:1343-1358` uses it only for `originalBusinessDate` while retaining the current date for the new refund. |
| 8 | Card/mixed original refunded in cash | **ADDRESSED** | `apps/pos/src/lib/fiscal/payloads/RefundReceiptV4Payload.ts:279-310` requires exactly one CASH payment leg, and `apps/pos/src/stores/refundCheckoutStore.ts:805-829` invokes that typed refusal immediately after lookup, before drafting an intent or requesting a PIN. |
| 9 | Phase-2 acknowledgement endpoint and device failure handling | **NOT ADDRESSED** | Server route/persistence exists, but `apps/pos/src/lib/sync/syncService.ts:1377-1387` still consumes the final failure after logging/persisting it, and `apps/pos/src/lib/db/repositories/terminalStateRepository.ts:278-303` describes the new getter as for a **future** operator-visible surface; it has no production call site, so the required surfaced device state is incomplete. |
| 10 | Discounted partial refund crashes after PIN | **ADDRESSED** | `apps/pos/src/stores/refundCheckoutStore.ts:930-968` refuses a partial per-line-discount refund during `beginV4()`, before intent creation at `:970` and before approval authoring. |
| 11 | Reused-intent resume paths are not idempotent | **NOT ADDRESSED** | `apps/pos/src/stores/refundCheckoutStore.ts:842-892` counts an already-appended reused intent in the cumulative cap and adds the same current selection before reuse is discovered at `:970-1001`, so a full-refund retry is refused before reconciliation; additionally `:1108-1128` checks only a non-empty event ID plus receipt existence, not that the linked fiscal event exists and matches the receipt/intent. |
| 12 | Post-ACK flips are non-atomic/errors swallowed | **NOT ADDRESSED** | `apps/pos/src/lib/sync/syncService.ts:368-393` makes the three writes atomic and validates receipt/intent outcomes, but `apps/pos/src/lib/db/repositories/fiscalEventRepository.ts:113-132` still returns `void` and never asserts that the fiscal-event flip affected exactly one row, contrary to the finding's exactly-one-row requirement on each flip. |
| 13 | Dispute evidence is best-effort and lossy | **ADDRESSED** | `apps/pos/src/components/pos/RefundPayoutReconciliationModal.tsx:235-291` authors the intent-keyed evidence before setting the dispute marker and leaves the row unresolved/retryable when authoring fails. |
| 14 | Refund `subtotal` column stores gross total | **ADDRESSED** | `apps/pos/src/lib/offline/refundReceiptService.ts:248-260` stores `bcmul(payload.subtotal, '-1', scale)` separately from negative VAT and negative gross total. |
| 15 | Printed AVOIR lacks original receipt reference/QR | **ADDRESSED** | `apps/pos/src/components/pos/RefundPayoutReconciliationModal.tsx:82-100,118-140` resolves the original receipt number and QR token from the intent's original local receipt and passes both to the print builder. |
| 16 | Shift-receipts list hardcodes every row as a sale | **ADDRESSED** | `apps/pos/src/api/reportApi.ts:656-688` maps `receipt_kind === 'refund'` to `receipt_type: 'return'` in the offline shift-receipts projection. |
| 17 | Test mocks the defective payout query | **ADDRESSED** | `apps/pos/src/components/pos/__tests__/RefundPayoutReconciliationModal.test.tsx:49-78,200-253` uses the real repository over in-memory SQLite and verifies that a dispute leaves confirmation and enters reprint recovery. |
| 18 | Malformed prior cumulative-quantity snapshot must fail closed | **ADDRESSED** | `apps/pos/src/lib/db/repositories/refundIntentRepository.ts:486-526` throws a typed refusal for unparseable JSON, non-array snapshots, malformed entries, non-integer indexes, and non-string quantities, while `apps/pos/src/stores/refundCheckoutStore.ts:856-873` converts any backstop read failure into a pre-authoring refusal. |
| 19 | Device backstop must also bound legacy refunds by receipt value | **NOT ADDRESSED** | `apps/pos/src/stores/refundCheckoutStore.ts:1041-1067` computes the bound, but takes the ceiling from the mutable `offline_receipts.total` scalar without binding it to the signed original payload and returns only `{ allowed: false }`; there is no typed value-exceeded/original-unreadable refusal, despite the finding requiring a trusted original total and typed refusal (the en/fr key and typed malformed-legacy-row error alone are insufficient). |

## New breakage introduced by the fix diff

None found at Critical or Important severity outside the incomplete findings above.

The diff-wide float scan found no new money/quantity float arithmetic beyond the pre-ruled single `Math.abs` sign flip; finding 3 remains open because the decimal string is normalized at one scale and reformatted at another, not because of that accepted sign flip. The refund sign contract, §7.2a idempotency keying, and the already-clean refusal placements were not otherwise regressed by the fix diff.

## Summary

- **ADDRESSED:** 12 / 19
- **NOT ADDRESSED:** 7 / 19
- **New Critical breakage:** 0
- **New Important breakage:** 0

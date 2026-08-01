# Lane C Wave 2 — consolidated dual-gate findings (fix-wave input), 2026-08-01

Sources (read for full detail; this file is the dedup + orchestrator rulings):
- Fiscal arm: docs/sessions/LANE-C-wave2-gate-fiscal-review.md (REJECT)
- Codex arm: /Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/reviews/2026-08-01-codex-refund-chain-wave2-review.md (FAIL)
Range reviewed: ea2487e9b..07408707e. Every fix lands ON TOP (no history rewrite), test-first.

## CRITICAL (all block the gate)

1. **X-report refund blindness** [fiscal C-1] — `generateLocalXReport()` (apps/pos/src/api/reportApi.ts:405-449, 487-533) has no `receipt_kind` branch: v4 refund rows fold into gross_sales/net_sales/tax_amount/sales_count, `refunds_count: 0` hardcoded, and the wrong totals are SIGNED into an immutable X_REPORT event. Third offline_receipts consumer missed by §7.3/§17. Fix mirrors the Z/EOD routing; end-to-end test with real writer output.

2. **Mirror not bound to signed original** [codex C-1] — `resolveOriginalFiscalEventLocally()` (fiscalEventRepository.ts:171-249) proves a fiscal_events row exists but reads all refusal data from the offline_receipts mirror without binding it to the signed event: no bytes/hash equality, no envelope type/version/identity validation, no receipt discriminator/UUID match, unvalidated array element shapes. Fix per Codex suggestion; every mismatch fails closed (null → refusal).

3. **IEEE-754 quantity in signed path** [codex C-2] — `Math.abs(item.quantity)` in RefundReceiptV4Payload.ts:257-263/315-328, number-typed quantity in refundReceiptService.ts:45-80, `Math.abs(q).toFixed(4)` in refundIntentRepository.ts:355-395. Quantity must be a decimal string end-to-end (cart boundary → snapshot → local row → cumulative cap → payload builder), normalized only via bcabs at quantity scale. Precision contract rule 19.

4. **Z/EOD gross-as-net VAT double-count** [codex C-3; fiscal verified "routing" clean — the ROUTING is fine, the component math is not] — refund writer copies gross line_total (refundReceiptService.ts:65-77); zReportService.ts:850-865 and endOfDayPreview.ts:256-273 call it `lineNet` and add VAT on top. Wave-2 tests masked this with net-valued fixtures. Fix: treat local line_total as gross (net = gross − vat at currency scale). MANDATORY: end-to-end taxable-refund test through the REAL writer asserting Z + EOD (+ X after finding 1) net/VAT/gross buckets against independently computed values.

5. **Payout confirm/dispute reconciliation broken** [fiscal C-2 + codex M-5, merged] — pending-payout query omits `payout_disputed_at IS NULL` (refundIntentRepository.ts:308-311): "No / Not sure" re-prompts the same Skip-less modal forever; only escape is a false "Yes" attestation. Also no mutual exclusion (both timestamps settable), no affected-row assertions, and a disputed-unprinted row never enters reprint recovery. Fix: single guarded transition setting exactly one timestamp, assert 1 row, pending-confirmation = both null, pending-reprint = either resolution non-null AND printed_at null.

6. **Approval re-author dead-letters signed refunds** [fiscal C-3] — cancel→retry reuses the intent's stable sourceEventIds but `posOverrideAuthoring.ts:114` mints a FRESH approval_id; PosCoreReceiptProjection.php:1045-1055 cross-check fails → non-retryable dead-letter AFTER cash left the drawer. Fix so re-authoring is consistent (stable approval identity per intent, or evidence re-authored atomically with the events it references); prove with a cancel-retry-append test through the projector.

7. **False original_business_date sealed** [fiscal C-4 == codex M-1] — refundCheckoutStore.ts:992 stamps today's businessDate into original_receipt_reference. Resolve the original's business date (add to OriginalFiscalEventLocalView as part of finding 2's binding work) and use it; keep approvalContext.businessDate only for the new event.

8. **Card-paid original refunded in cash** [fiscal C-5] — original `payments[]` resolved then never read (fiscalEventRepository.ts:147, refundReceiptService.ts:159); §9.6 non-cash-original refusal does not exist. Launch is CASH-payout-only of cash originals. Add the refusal at lookup level (same placement as §3.5's), fail closed, typed message, test both card-original and mixed-payment-original refusals.

9. **Phase-2 acknowledgement endpoint missing server-side** [fiscal C-6] — device calls it (syncService.ts:1313), apps/api/app/Modules/POS/routes.php:53-72 has no such route; 404 swallowed to console.warn; LegacyCorrectionGuard can NEVER activate, /return stays permanently open — the entire two-phase enable/acknowledge protocol is inert. Cross-layer fix: implement the endpoint (route + controller + persistence of v4_refund_authoring_acknowledged_at; middleware per module conventions incl. 'api'+auth:sanctum+SetPermissionsTeam), stop swallowing the failure device-side (retry/backoff, surface state), integration-test guard activation.

## IMPORTANT

10. **Per-line-discounted partial refund crashes post-PIN** [fiscal I-1] — hydrateFromReceipt.ts:90-97 carries discount without discount_type; LineArithmeticInvariantError AFTER manager PIN spent. Either carry the full discount shape or refuse at lookup time (before approval), never after PIN.
11. **Reused-intent resume paths not idempotent** [fiscal I-2 + codex M-3, merged] — branch on reused intent state: refund_event_appended → verify linked event/receipt → route to reconciliation/reprint; approval_authored → recover approval refs, resume append only. Reject inconsistent linkage fail-closed. No generic-error rollback loops.
12. **Post-ACK flips not atomic, errors swallowed** [codex M-4] — wrap fiscal-event-synced + receipt flip + intent-synced in ONE write-gate transaction; require exactly-one-row on each; suppress ONLY re-read-confirmed already-synced; propagate everything else.
13. **Dispute evidence best-effort** [codex M-6] — evidence authoring must be durable + idempotent keyed by intent id (author before/atomically with the dispute marker, or evidence_pending outbox with retry).
14. **subtotal column = gross total** [codex M-2] — refundReceiptService.ts:229-232 writes negativeTotal into subtotal. Store negative net (bcmul(payload.subtotal,'-1',scale)); assert subtotal + tax_amount = total.
15. **AVOIR print lacks original receipt ref/QR** [fiscal I-3] — RefundPayoutReconciliationModal.tsx:78; legacy parity required.
16. **Shift-receipts list hardcodes receipt_type 'sale'** [fiscal I-4] — reportApi.ts:606; make receipt_kind-aware.
17. **Test mocked the defective query** [fiscal I-5] — RefundPayoutReconciliationModal.test.tsx:38 mocks exactly the query with the C-2/finding-5 bug. Un-mock; test against real repository behavior (in-memory SQLite per existing patterns).
18. **⚖️ Q-1 ruling (orchestrator, re-issued on merits)** — device-local cumulative-quantity backstop RETAINED as hardening bound (server cap stays sole authority per §4.4). DEFECT: malformed/unparseable prior-appended snapshot must FAIL CLOSED (refuse refund), not skip-and-undercount (refundIntentRepository.ts:349-353, 369-392). Spec §4.4 addendum owed (one paragraph, add to spec file, flagged as post-FINAL erratum).

## DEFERRED MINORS
The fiscal record lists 8 Minor findings — NOT in this fix wave's scope; final whole-branch review triages them. Fix opportunistically ONLY if already editing the exact lines.

## Verified-clean (do not re-litigate)
Sign contract; lookup-level §3.5 refusal placement; v4 engine validation; §7.2a row contract keying/status flip mechanics (finding 12 is about atomicity, not keying); Z/EOD refund ROUTING; legacy cashRefundImpact restoration; toSqliteUtc coverage; capability-flag guard-independence; v65 index; golden-fixture correction; i18n parity; envelope-vs-payload has no sibling confusion; registry discriminator paths fail closed.

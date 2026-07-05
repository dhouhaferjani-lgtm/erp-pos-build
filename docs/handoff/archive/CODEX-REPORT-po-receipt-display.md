# CODEX Report: PO Receipt Display

Date: 2026-07-02
Branch: `fix/po-receipt-display`
Commit: not created; orchestrator commits.

## Summary

Fixed the purchase-order detail receipt display by reading the existing `GET /purchase-orders/{id}/receipt-status` endpoint through a tenant-scoped query. The header receipt pill and per-line received quantity now come from receipt-status instead of the document payload. The receipt-status query is invalidated after a successful receive-goods mutation.

Also feeds receipt-status quantities into `ReceiveGoodsDialog` when available, so reopening the dialog after a partial receipt uses the actual remaining quantity instead of assuming `0` received from the documents API.

## Files Changed

- `apps/web/src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx`
- `apps/web/src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx`
- `apps/web/src/locales/en/sales.json`
- `apps/web/src/locales/fr/sales.json`
- `apps/web/src/locales/ar/sales.json`

## TDD Red

Command:

```bash
pnpm --filter @autoerp/web test -- src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx
```

Output excerpt:

```text
❯ src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx (7 tests | 2 failed) 2644ms
  ✓ PurchaseOrderDetailPage tenant scope > wraps purchase order detail read key and gates missing tenant/company (.221) 74ms
  ✓ PurchaseOrderDetailPage tenant scope > invalidates confirm and receive-goods cascades for only active tenant (.222-.226) 187ms
  ✓ PurchaseOrderDetailPage tenant scope > invalidates payment success cascade with active-tenant payment isolation (.227-.229) 53ms
  ✓ PurchaseOrderDetailPage tenant scope > submits partial received quantities as strings 145ms
  × PurchaseOrderDetailPage tenant scope > renders receipt status and line progress from the receipt-status endpoint 1009ms
    → Unable to find an element with the text: purchaseOrders.receiptStatus.partially_received.

Rendered DOM still contained:
  purchaseOrders.receiptStatus.not_received
  received column: 0

  × PurchaseOrderDetailPage tenant scope > invalidates receipt status after receiving goods succeeds
    expect(mockApiGet).toHaveBeenCalledWith('/purchase-orders/po-1/receipt-status')

Test Files  1 failed (1)
Tests  2 failed | 5 passed (7)
Exit status 1
```

This proved the old page never called `/receipt-status`, kept the header pill at `not_received`, and rendered `0` for the line receipt quantity.

## TDD Green

Command:

```bash
pnpm --filter @autoerp/web test -- src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx
```

Output excerpt:

```text
✓ src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx (7 tests) 606ms

Test Files  1 passed (1)
Tests  7 passed (7)
Exit code: 0
```

## Final Verification

```bash
pnpm --filter @autoerp/web test -- src/features/documents/purchase-orders
```

Result: exit 0. `1 passed`, `7 passed`. Existing React `act(...)` and `--localstorage-file` warnings were emitted.

```bash
pnpm --filter @autoerp/web typecheck
```

Result: exit 0.

```bash
pnpm --filter @autoerp/web test:arch
```

Result: exit 0. Output: `[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 20`.

```bash
pnpm --filter @autoerp/web exec eslint src/features/documents/purchase-orders/PurchaseOrderDetailPage.tsx src/features/documents/purchase-orders/__tests__/PurchaseOrderDetailPage.tenantScope.test.tsx
```

Result: exit 0. ESLint reported `0 errors, 75 warnings`; the warnings are existing style/type-safety warnings in these files, not blocking errors.

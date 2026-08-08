# OpenAPI lane coordination — regenerate after the V9 void sunset (2026-08-08)

**For:** the owner of the unmerged OpenAPI lane branch `codex/openapi-contract-a-to-z`
(commit `4cd85e822`, NOT an ancestor of `origin/dev` nor of `fix/dpa-v9-void-sunset`).

**Source:** fiscal-pos gate on `fix/dpa-v9-void-sunset` (APPROVED), finding **I-2**.

## What changed

DPA V9 (owner ruling D3) **SUNSET** the legacy server-authored POS void.
`ReceiptVoidService` and `ReceiptController::void()` were deleted;
`POST /api/v1/pos/receipts/{id}/void` is now a route-level **410 tombstone**:

```json
{ "error": { "code": "LEGACY_VOID_RETIRED", "message": "…corrected by a device-authored refund…" } }
```

The route deliberately still resolves (it is not deleted) so `ImpersonationWriteGuard`
— which is `api`-GROUP middleware and therefore never runs on an unmatched path —
keeps hard-blocking and auditing this path. See `apps/api/app/Modules/POS/routes.php`.

## Why this needs the OpenAPI lane's attention

The lane's generated artifacts still publish the endpoint under its OLD contract:

- `apps/api/openapi/feasibility/tenant-full.json` — `POST /api/v1/pos/receipts/{id}/void`,
  described as *"Marks the receipt as voided, reverses stock movements, and creates
  reversal GL entries."* Every clause of that description is now false.
- `apps/api/openapi/route-coverage-baseline.json` — same route entry.

Because the lane branch is unmerged, this is **contract drift between two live
branches**, not an in-tree break: nothing in `origin/dev` or in the V9 branch references
those files (verified at V9 time — a repo-wide scan for committed `openapi*.{json,yaml}`
in this tree returned nothing).

## Required action on merge/rebase of the OpenAPI lane

1. **Regenerate** (do not hand-patch) so the route reflects the retired contract:
   response **410** with error code **`LEGACY_VOID_RETIRED`**, no 200 shape, no
   `pos.void_receipts` authorization semantics, and no request body of substance
   (the closure short-circuits before validation — any payload shape gets 410).
2. Re-run the lane's **truthfulness gate** on this path specifically — the old
   description is exactly the class of stale prose that gate exists to catch.
3. If the lane's `route-coverage-baseline.json` is used as a ratchet, refresh it in the
   same commit so the tombstone does not read as a coverage regression.

## Related

- V9 report: `.superpowers/sdd/HANDOVER-document-per-action-remediation-2026-08-08/task-v9-report.md`
- Retirement pins: `NewSaleServerAuthoringDispositionTest::test_void_route_is_retired`
  and `::test_void_route_is_retired_regardless_of_payload_shape`
- Companion fiscal note (I-1): ANNULATION → RETOUR representation shift, recorded in
  `.claude/context/compliance.md` (NF525 Readiness section)

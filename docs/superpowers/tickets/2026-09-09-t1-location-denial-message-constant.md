# T1: Centralize the location-denial validation message

Status: open · severity: low · owner: T-2 authorization seam

`apps/api/app/Rules/ValidLocationAccess.php:86` authors a denial literal that both `StoreStockTransferRequest.php:40` and `CreateTransferFromRequestsRequest.php:30` compare by identity. Rewording or translating the rule alone silently degrades the lone-location refusal from 403 `LOCATION_ACCESS_DENIED` to 422.

Acceptance: hoist the literal to a constant on `ValidLocationAccess` and reference it in both FormRequests. Preserve the exact 403 envelope and its location/user details; preserve compound-validation 422 behavior. Run both location-denial regression classes. This ticket makes no production change in T-1 round 2.

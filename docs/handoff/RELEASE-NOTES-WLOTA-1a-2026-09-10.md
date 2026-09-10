# W-LOT-A-1a release notes — fix rounds 1–2

Status: review; no deployment or activation performed.

Push 3 intentionally changes two contracts for every tenant, including when `LOT_ACTION_PERMISSIONS_ENFORCE=false` (plan rev 10 §0 / §6.1, Option B):

1. Every `BatchResource` emits `total_quantity` and `available_quantity` as four-decimal JSON strings computed from the loaded, scoped stock relation. Consumers must accept strings. Mutation responses load membership-scoped stock before serialization; a missing relation now fails loudly. A newly created empty batch returns genuine `"0.0000"` totals and an explicit `batch_stock: []` (the key was previously omitted). This create-response shape change also applies with the flag off. Product `quantity_decimals` now reflects the owning unit on list, detail and mutation responses; the list displays quantities at that unit precision while the wire quantities retain four decimals.
2. `/batches/expiring?location_id=…` eager-loads stock for that location, and `/batches/expired?location_ids[]=…` totals reflect the selected locations. These endpoints now agree with their `batch_stock` rows and the write-off location. This is an intentional non-inert Push-3 change.

The POS suggestion endpoint validates `location_id` as a UUID independently of the activation flag. Malformed input returns 422 instead of a PostgreSQL UUID error / 500. Backward trace similarly validates partner UUIDs (404) and product/date filters (422).

Legacy NULL-team role identities and assignments remain unchanged. Technician receives no `batches.view` grant. Push 4 (delta plus acceptance) must precede Push 5 (web); never combine them in an automatic deployment. Persisted users without a server permission list remain fail-closed until `/auth/me` refreshes it.

Create/update retain their existing FormRequest permission checks, with no new A-1a `BatchActionAccess` middleware. The staged middleware alignment is recorded in `docs/superpowers/tickets/2026-09-10-batch-create-update-api-enforcement.md` for A-1b.

Push 3 must ship the **web string-tolerance slice** (`apps/web/src/features/batches/types.ts`, `apps/web/src/features/batches/pages/BatchListPage.tsx`, and `apps/web/src/features/batches/pages/__tests__/BatchPermissions.test.tsx`) with the API string contract. The pre-lane list calls `.toFixed()` on the total and crashes on a string during the Push 3 → Push 5 window. The slice accepts both old numbers and new strings; its create-button permission was already granted to the roles that could see that button. Other permission-gating web files remain in Push 5 after Push 4 acceptance.

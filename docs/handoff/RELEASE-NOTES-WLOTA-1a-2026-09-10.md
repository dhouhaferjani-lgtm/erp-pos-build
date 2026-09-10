# W-LOT-A-1a release notes — fix round 1

Status: review; no deployment or activation performed.

Push 3 intentionally changes two contracts for every tenant, including when `LOT_ACTION_PERMISSIONS_ENFORCE=false` (plan rev 10 §0 / §6.1, Option B):

1. Every `BatchResource` emits `total_quantity` and `available_quantity` as four-decimal JSON strings computed from the loaded, scoped stock relation. Consumers must accept strings. Mutation responses load membership-scoped stock before serialization; a missing relation now fails loudly. A newly created empty batch returns genuine `"0.0000"` totals.
2. `/batches/expiring?location_id=…` eager-loads stock for that location, and `/batches/expired?location_ids[]=…` totals reflect the selected locations. These endpoints now agree with their `batch_stock` rows and the write-off location. This is an intentional non-inert Push-3 change.

The POS suggestion endpoint validates `location_id` as a UUID independently of the activation flag. Malformed input returns 422 instead of a PostgreSQL UUID error / 500. Backward trace similarly validates partner UUIDs (404) and product/date filters (422).

Legacy NULL-team role identities and assignments remain unchanged. Technician receives no `batches.view` grant. Push 4 (delta plus acceptance) must precede Push 5 (web); never combine them in an automatic deployment. Persisted users without a server permission list remain fail-closed until `/auth/me` refreshes it.

Create/update retain their existing FormRequest permission checks, with no new A-1a `BatchActionAccess` middleware. The staged middleware alignment is recorded in `docs/superpowers/tickets/2026-09-10-batch-create-update-api-enforcement.md` for A-1b.

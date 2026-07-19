# Multi-location deployment checklist

This checklist is ordered because tenant migrations and permission caches are deployment-sensitive.

## Wave 1 scope foundation

- Run tenant migrations before enabling location-scoped enforcement.
- Run `tenants:migrate`; the membership migration backfills single-company tenants and logs `multiloc.backfill.skipped_ambiguous_users` for multi-company tenants.
- For each skipped multi-company user, choose the company explicitly and run `php artisan tenants:run users:backfill-memberships --tenants=<uuid> --option=company=<companyId> --option=user=<userId>[,<userId2>...]`; never bulk-grant every skipped user into one company.
- Re-run the permission seeder for every tenant after adding `users.manage_location_access`.
- Run the tenant-blind permission cache reset (`permission:cache-reset`) after reseeding; the cache platform is tenant-blind.
- Verify a restricted manager cannot create or update a user outside their allowed locations, including when `allowed_location_ids` is omitted.

## Wave 2 inventory

- Run `tenants:migrate` for `goods_receipts.location_id`.
- Verify the stock matrix, threshold writes, receiving destination, rebalancing, and movement filters with PostgreSQL tests.

## Wave 3 treasury

- Assign cash registers and safes to locations explicitly; bank repositories may remain company-level (`NULL`).
- Run the guarded payment/payment-instrument location migration.
- Run the manual financial attribution backfill only after repository assignment: `treasury:backfill-location-attribution`.
- Reseed permissions and run `permission:cache-reset` again after any permission catalog change.

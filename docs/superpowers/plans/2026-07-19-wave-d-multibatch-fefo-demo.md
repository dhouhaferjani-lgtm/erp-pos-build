# Wave D — Multi-batch FEFO demo implementation plan

> Execute with focused TDD, commit immediately after every green cycle, and hard-stop at the Wave D review gate without merging or pushing.

## Baseline

- Confirm branch/worktree are clean and based on local `dev` `684e5a198`.
- Confirm the transfer-detail batch-allocation follow-up is merged.
- Run only:
  - `apps/api/tests/Feature/Seeders/DemoPharmacyBatchSeedingTest.php`
  - `apps/api/tests/Feature/Inventory/StockTransferShowBatchAllocationsTest.php`
  - `apps/web/src/features/stock-transfers/__tests__/StockTransferDetailPage.batchAllocations.test.tsx`

## Cycle 1 — deterministic demo batches

1. Extend `DemoPharmacyBatchSeedingTest` with a failing contract that seeds twice and proves:
   - exactly four fixture products;
   - three fixture batches per product with strictly increasing expiries;
   - quantities `3.0000`, `4.0000`, and a positive remainder;
   - default warehouse quantity is zero;
   - fixture quantities equal aggregate warehouse stock;
   - the second seed creates no duplicate batches or batch-stock rows.
2. Run the seeder test by exact path and retain the expected red result.
3. Add the smallest `DemoPharmacySeeder` helper satisfying the approved data contract.
4. Re-run that exact test path, Pint the two touched PHP files, and run PHPStan only against those files.
5. Commit immediately at green.

## Cycle 2 — real browser exercise and evidence

1. Start the API and Vite app from this worktree against the local demo database, on unused ports.
2. Rerun `DemoPharmacySeeder` and inspect the selected fixture rows to obtain the exact product, batch, expiry, and quantity values.
3. Use the in-app browser to log in as the demo owner and drive the real replenishment request → review → create-transfer → transfer-detail flow.
4. Verify the detail shows the earliest batch before the second batch and the quantities total `4.0000` as `3.0000 + 1.0000`.
5. Save the screenshot artifact and a browser-verification report containing URLs, durable identifiers, observed values, and any cleanup/state notes.
6. Commit the evidence immediately after the browser flow is green.

## Review gate

Run only scoped verification:

- the two focused backend test files;
- the focused transfer-detail frontend test;
- Pint on touched PHP files;
- PHPStan on touched PHP files;
- `git diff --check` and a clean-worktree check;
- inspect the screenshot and confirm it matches the recorded allocation values.

Prepare the Wave D review record with commits, evidence, deploy note, and explicit scope/deviation statement. Stop before merge or push for owner/external review.

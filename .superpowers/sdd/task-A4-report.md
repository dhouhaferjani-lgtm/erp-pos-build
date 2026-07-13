# Task A4 Report — Repository Transfer HTTP Endpoint

## Outcome

Implemented `POST /api/v1/payment-repositories/transfers` with the `treasury.transfer` route gate, request validation, thin controller delegation to `RepositoryTransferService`, the required 201 response contract, permission seeder parity, and backend messages in English and French only.

The controller does not catch or translate port exceptions. Domain failures continue through the canonical global handler as `{error:{code:"BUSINESS_ERROR",message}}` with HTTP 422.

## TDD evidence

- RED: the new seven-test endpoint suite failed before controller execution because the POST route was absent (`7 tests, 8 assertions, 7 failures`). Laravel emitted 405, not 404, because the existing GET wildcard `/payment-repositories/{repository}` recognizes the literal `transfers` URI under another method.
- GREEN: `RepositoryTransferEndpointTest.php` passes with 7 tests and 80 assertions.
- Regression: `RepositoryTransferServiceTest.php` passes with 10 tests and 48 assertions.

Coverage includes permission 403, created contract and balance mutation, same/bad/missing validation, cross-company 404, sequential client-group replay, the sanctioned Amendment A-1 pre-existing-transfer race shape, and canonical frozen/virtual/inactive envelopes.

## Verification

- Pint dirty pass: exit 0.
- PHPStan default: incomplete because a parallel worker exhausted the configured 512M ceiling.
- PHPStan with `--memory-limit=1G`: exit 0, no errors across 2503 files.
- Named route listing: one POST route at `api/v1/payment-repositories/transfers`.
- Permission audit: the base permission list and RolesAndPermissionsSeeder catalog contain `treasury.transfer`; every role bundle containing `treasury.adjust` also contains `treasury.transfer`.
- Transfer port/fiscal/interlock diff: empty.
- Final combined completion gate: exit 0, including `git diff --check` and controller catch audit.

## Deviation and concerns

The dated progress entry records spec §15 Amendment A-1 exactly: the race-shape test is sequential and starts from a completed transfer for group G before replaying G through HTTP. It exercises the same savepoint/idempotent-hit path and proves original-JE resolution plus draft cleanup, but does not claim literal two-connection concurrency.

No implementation concern remains within A4. Task A5 still owns the mixed-transfer reconcile-green pin.

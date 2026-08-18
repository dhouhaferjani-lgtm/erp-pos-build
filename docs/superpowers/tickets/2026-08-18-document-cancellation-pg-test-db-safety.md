# Document cancellation PostgreSQL test database safety

Source: terminal audit round 1, finding F-6 (P3).

## Risk

`DocumentCancellationGlReversalTest::tearDown()` runs `migrate:fresh` after its committed-fixture
PostgreSQL concurrency case. The test checks the driver but does not verify that the effective
database name belongs to an explicit disposable `_test` allowlist. A misconfigured local or CI
invocation could therefore rebuild a non-test PostgreSQL database.

## Owner and status

- Owner: accounting test infrastructure
- Status: OPEN; non-blocking terminal-audit follow-up

## Acceptance criteria

- Before `migrate:fresh`, resolve the live PostgreSQL connection identity and refuse destructive
  execution unless the database name matches the repository's approved disposable `_test` naming
  contract.
- Cover both an approved test database and a production-like name; the refusal case must prove that
  no migration command executes.
- Keep the forked cancellation case isolated and green on the real PostgreSQL lane.

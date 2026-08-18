# Legacy COA golden export under PgBouncer transaction pooling

Source: terminal audit round 1, finding F-8 (P3).

## Risk

`LegacyCoaGoldenExporter` copies the central connection and creates a PostgreSQL temporary
`accounts` table with `ON COMMIT PRESERVE ROWS`, then seeds and reads it across multiple statements.
When the central endpoint is PgBouncer in transaction-pooling mode, successive implicit
transactions are not guaranteed to use the same server session. The temporary table may therefore
be missing, or a pooled session may retain unexpected temporary state.

## Owner and status

- Owner: country-defaults backend + database platform
- Status: OPEN; latent deployment-compatibility constraint

## Acceptance criteria

- Choose and document one supported contract: bypass transaction pooling for this exporter, or pin
  create/seed/read/cleanup to one PostgreSQL transaction and server session.
- Add an integration test through a transaction-pooling PgBouncer configuration that exports TN,
  FR, and Generic canonical bytes repeatedly without missing-table or cross-run contamination.
- Fail closed with an actionable diagnostic when the effective connection cannot satisfy the
  selected session contract.

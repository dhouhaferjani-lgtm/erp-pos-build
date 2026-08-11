# Country defaults M2 P3 hardening

Follow-up items recorded from the accepted M2 lifecycle work. These are non-blocking for Phase A,
but should remain visible until closed or deliberately retired.

- Remove the unreachable normalization branch from the service-only assignment model hook, while
  retaining malformed-code rejection or moving that assertion to the service boundary.
- Strengthen the central-connection tenancy test to execute the real tenancy bootstrapper swap.
- Add central-table indexes for assignment lookup by `template_id` and template lookup by
  `(domain, status)` if production cardinality warrants them.
- Introduce a public purpose-classification enum or public manifest-owned classification constant,
  then replace the publish-gate `REQUIRED` string literal with that public authority. The current
  manifest `REQUIRED` constant is private and cannot be referenced by the publishing service.
- Replace fixed-delay PostgreSQL race synchronization with a deterministic handshake.
- Make `TemplateImmutabilityTest::outsideCentralTransaction()` clean every central row it commits.
- Introduce the typed recertification and timbre-country assignment exceptions at the M4/M5
  boundaries that consume them.
- M5 gate: the authoritative resolver must never fall back to the wildcard assignment for a timbre
  country. If no exact assignment exists for a timbre country, resolution must throw; a wildcard
  template structurally cannot carry `SalesStampDutyPayable` and is not a safe fallback.

The migration `hasTable` note is closed as convention-conformant: Laravel's migration repository
provides reapplication idempotence and the empty PostgreSQL scratch migration proof is green.

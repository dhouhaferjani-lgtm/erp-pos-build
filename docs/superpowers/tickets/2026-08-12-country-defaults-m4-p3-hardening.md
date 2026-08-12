# Country defaults M4 P3 hardening

- `LegacyCoaBootstrapImporter` is an accepted minimal file-map deviation: a final, narrowly owned
  authority object was required to eliminate the public generic bootstrap-key escape hatch while
  letting the top-level migration remain the sole caller. Do not generalize or expose it.
- Expand the bootstrap provenance ratchet from `app/` plus `database/migrations/` to every
  production database directory, including seeders, and add AST/query-shape detection for raw SQL
  non-null `bootstrap_key` writers. Current full-source inspection found no alternate caller or
  writer, so this is hardening rather than a live bypass.

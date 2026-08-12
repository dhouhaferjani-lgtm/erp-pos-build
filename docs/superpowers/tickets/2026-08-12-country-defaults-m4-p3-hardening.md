# Country defaults M4 P3 hardening

- `LegacyCoaBootstrapImporter` is an accepted minimal file-map deviation: a final, narrowly owned
  authority object was required to eliminate the public generic bootstrap-key escape hatch while
  letting the top-level migration remain the sole caller. Do not generalize or expose it.
- Expand the bootstrap provenance ratchet from `app/` plus `database/migrations/` to every
  production database directory, including seeders, and add AST/query-shape detection for raw SQL
  non-null `bootstrap_key` writers. Current full-source inspection found no alternate caller or
  writer, so this is hardening rather than a live bypass.
- Pin the three golden digests independently (not only self-derived `.sha256` files), and consider
  making the importer consume the committed pinned golden bytes rather than re-running live frozen
  seeders at migration time.
- Filter required TN/FR/`*` verification lookups by chart-of-accounts domain before the domain enum
  grows beyond one case.
- Add a direct assigned-template content-hash tamper test for `country-defaults:verify`.
- Keep the scratch exporter console-only; its temporary default-connection swap is not safe for
  concurrent HTTP or queue use.

# H1-a1 lane report — SALE_RECEIPT buyer

- Status: M4 and M5 ACCEPT; registers `M4-round2.md` and `M5-round1.md`.
- M4 device: V1/V2/V3/V5 builders retain the buyer key, resolve only lowercase server/alias UUIDs, and seal null for unresolved pending IDs.
- M4 server: v5+ UUID-or-null validation, legacy-v1–v4 grandfathering, scoped defensive partner projection, and sealed snapshot name/tax projection.
- M5: one adjacent populated-buyer V5 canonical vector, exact six-key block, identical TS/PHP payload hash `02bbf732ede83ae131989df29db7deb651548704024527a43c24a86e186d4fef`.
- POS integrated gate: 37 files / 459 tests passed (`src/lib/fiscal`, `src/lib/customer`, receipt service, payment store); documented tolerance-mock warnings only.
- POS typecheck passed; web typecheck passed; committed M4 Playwright discovery found 1 test in 1 file.
- API private-PG gate: 211 tests / 607 assertions passed across validator, projection, D16, and canonical parity files.
- PHPStan: no errors on all four touched production PHP files; Pint passed; feature manifest passed (1,456 Feature classes / 74 groups, documented warnings only).
- M4 live signed-ingestion evidence remains accepted 1/1; screenshot `.playwright-mcp/session-h/m4/signed-buyer-ingestion-summary.png` (not rerun at closeout).
- Immutable fixture hashes: F-07 `96e325eedc1b5466e3cd0a0c7b74b203b0110617b46459a47ed4236579e46142`; F-15 `32c660dbdb080fda26d659f3c5beb22b19ffece0f332a3dd2f98431110169127`.
- No payload key-set, schema, migration, event-version, or AccountCharge change; no POS device-version bump is needed.
- Owes parent: F-07 remains a pinned stale 27-key artifact; regenerating/re-pinning it is an owner gate.
- Owes parent: legacy populated-buyer names are non-empty on revalidation; PHP/JS Unicode-whitespace trimming differs in the safe device-stricter direction.
- Owes parent: v4 refunds and dormant `offlineCheckoutService` omit buyer; tenant/company mismatch still emits raw English in `receiptService`.
- Owes parent: the M4 browser gate is local-only, mutates demo fiscal data, and skips in CI without `SESSION_H_API_BASE`; durable unit/integration coverage is green.
- Owes parent: add U+2028/U+2029 buyer-name parity; standalone goldens remain outside the raw-byte registry and PHP retains two canonical test encoders.
- Session G: no new handoff; all remaining items are the parent/Phase-2 or fiscal-test debts above.
- Closeout: cumulative diff check passes; branch remains clean, unmerged into `origin/dev`, unpushed, and without an upstream.

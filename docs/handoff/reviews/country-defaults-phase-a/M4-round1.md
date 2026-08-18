## M4 adversarial merge-gate review — round 1
**Range reviewed:** `7d85232cc..HEAD` (M4 = `cd73d36fd`, `782a2a5a1`, `3e1dd04c3`) · **Lenses:** treasury, tenancy-authz

### Evidence I re-ran myself (not taken from the report)
| Check | Result |
|---|---|
| `phpunit tests/Feature/CountryDefaults/{VerifyCountryDefaultsCommandTest,CapabilityRegistryBumpTransitionTest,CertifiedFixtureDeltaTest}.php` | OK 6 tests / 27 assertions |
| `phpunit tests/Feature/CountryDefaults/{LegacyGoldenParityTest,BootstrapKeyAssertionImportTest}.php` | OK 14 tests / 54 assertions |
| Combined = the report's claimed exact-M4 SQLite **20 / 81** | reproduced exactly |
| `pint --test` on the four M4 production files | pass |
| `phpstan analyse` on `Infrastructure/`, `Presentation/Console`, migration `100300` | No errors |
| PostgreSQL lane (claimed 20 / 90 incl. the `pcntl` fork race) | **not reproducible here** — no local PG; deferred to M7 |

### Brief-scope conformance (M4 §, brief:433-471)
Exporter runs the **frozen** seeders into an isolated scratch schema with no reflection into private arrays (`LegacyCoaGoldenExporter.php:35-84,167-175`); goldens committed with the required no-trailing-newline invariant (`LegacyGoldenParityTest.php:33-38`). Migration is top-level, drafts-only, one atomic transaction per key (`LegacyCoaBootstrapImporter.php:154-195`), and the key is a real assertion — domain, draft status, row count, canonical hash (`:121-147`). `certified_by` has exactly one writer, `TemplatePublishingService:80`, fed from the HTTP actor; no artisan certify path exists. `verify` implements the full §6 list incl. the global drift scan and unassigned-history integrity-only rule (`VerifyCountryDefaultsCommand.php:52-72`), with the 4-assignment (`TN`/`FR`/`*`/`DE`) last-repoint transition proved at `CapabilityRegistryBumpTransitionTest.php:44-54`. Delta suite asserts the manifest-derived missing-REQUIRED set **and** the scope-dependent stamp result **and** the full publish gate (`CertifiedFixtureDeltaTest.php:37-52`) — matches the M0 addendum table (TN 139 / FR 144 / Generic 61, all deltas empty). Red-first evidence recorded (report lines 1313, 1357). Rule 19: no float, no money/quantity in this milestone. New queues: none. Migration is additive and fail-loud.

### Findings register (all P3 — none block)

1. **P3 · CONFIRMED · `apps/api/app/Modules/CountryDefaults/Infrastructure/Import/LegacyCoaBootstrapImporter.php:33`** — the import derives `$expected` by re-running the *live* frozen seeders, never by reading the committed goldens. The collision assertion is therefore self-referential. *Failure scenario:* a frozen seeder is edited and the goldens regenerated in the same commit (or a replay environment carries a different seeder revision); a fresh `migrate` imports non-baseline content under `coa.tn.legacy-v1` and every runtime assertion still passes — the byte-for-byte parity invariant lives only in `LegacyGoldenParityTest`, not at import time.
2. **P3 · CONFIRMED · `apps/api/app/Modules/CountryDefaults/Presentation/Console/VerifyCountryDefaultsCommand.php:42-50`** — the required-assignment check does `keyBy('country_code')` over *all* domains and emits "Missing TN assignment for chart_of_accounts" without filtering `domain`. Latent only because `TemplateDomain` has one case; adding a second case makes a non-COA `TN` row satisfy the COA requirement.
3. **P3 · CONFIRMED · `apps/api/tests/Feature/CountryDefaults/LegacyGoldenParityTest.php:34`** — the `.sha256` sidecar is hashed from the same file it guards, so it adds no independent immutability pin; only the `golden === exporter output` assertion actually resists tampering. Pinning the three digests as code constants (or in the M0 addendum) would make the "immutable golden" claim self-standing.
4. **P3 · CONFIRMED · `apps/api/tests/Feature/CountryDefaults/BootstrapKeyAssertionImportTest.php:126`** — the provenance ratchet scans only `app/` and `database/migrations/`. A raw `DB::table('admin_templates')->insert([... 'bootstrap_key' => ...])` in `database/seeders/` would bypass both the ratchet and the model hooks. I grepped `database/seeders`, `routes`, `config`, `bootstrap` — **no such writer exists today**, and the gap is already recorded in `docs/superpowers/tickets/2026-08-12-country-defaults-m4-p3-hardening.md`.
5. **P3 · CONFIRMED · `AdminTemplate.php:37-61`** — the brief/spec word the invariant as enforced "in the model (guarded attribute) **and** in the service"; there is no service-level check. Enforcement is model hooks + FormRequest allowlists (`UpdateTemplateRequest` never accepts the field) + the AST ratchet, which I judge substantively equivalent or stronger, but the literal wording is unmet.
6. **P3 · CONFIRMED · `LegacyCoaGoldenExporter.php:45-49,79-83`** — `setDefaultConnection()` + `config()->set()` mutate process-global state for the duration of a seeder run, and `removeUntouchedDrafts()` invokes it *inside* the open central transaction (`LegacyCoaBootstrapImporter.php:81`). Correct today (single console caller; `finally` restores; PG `pg_temp` shadows and SQLite gets a distinct `:memory:`), but any future HTTP/queue caller would silently route default-connection queries into the scratch database.
7. **P3 · CONFIRMED · `VerifyCountryDefaultsCommandTest.php:39-50`** — the content-hash tamper case only covers an *unassigned* history template. The assigned path (`VerifyCountryDefaultsCommand.php:114`) has no direct red test, so a regression that dropped that call would not be caught.

### Bypasses I attempted that FAILED (code correctly resists)
- `forceCreate(['bootstrap_key' => ...])` and a later `forceFill` mutation → both `LogicException` (`AdminTemplate.php:43,58`, proven `BootstrapKeyAssertionImportTest.php:96-122`).
- Rolling back the migration to erase certified history, an assigned draft, or clone provenance → all three refused (`:198-229`, tests `:164-238`).
- Re-running `up()` against a keyed row that was published / re-domained / row-deleted / content-altered → aborts with a keyed diagnostic each time.
- Hunting a non-HTTP `certified_by` writer or an artisan certify path → only `TemplatePublishingService:80`, called only by `TemplateController:148`.
- Hunting a second `importAll()` caller or `bootstrap_key` writer across `app/`, `database/`, `routes/`, `config/`, `bootstrap/` → none.
- Hunting float/`(float)`/`parseFloat` on money or quantity in the new code → none.
- Checking whether the top-level migration could execute against a tenant DB → `config/tenancy.php:197` pins `tenants:migrate` to `database/migrations/tenant`; all three models carry `CentralConnection`.

**Lens applicability.** *Treasury* applies and is satisfied: `verify` re-executes the full publish gate (REQUIRED purposes, purpose/type, `is_system`, protected instrument + expense-category codes, and the non-timbre stamp-purpose negative rule) rather than hash-comparing — `VerifyCountryDefaultsCommand.php:108-114` → `TemplatePublishingService::validateAccountRows:304-333`. *Tenancy-authz* is largely N/A for M4: it adds no routes, permissions, or middleware; the relevant surface is central-connection scoping, which holds.

VERDICT: ACCEPT

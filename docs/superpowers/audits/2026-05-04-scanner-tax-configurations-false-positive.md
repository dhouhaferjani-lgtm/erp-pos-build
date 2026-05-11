## Scanner false-positive — `tax_configurations` listed as tenant-scoped

Audit date: 2026-05-04
Reporter: orchestrator (api.identity-company round-1 triage)
Status: KNOWN GAP — defer affected callsites; scanner fix deferred to bespoke pass

### Symptom

`PhpPresentationExistsScanner::DEFAULT_GUARDED_TABLES` (apps/api/app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php:78) lists `tax_configurations` as a guarded table, causing the scanner to flag every `exists:tax_configurations,id` validator rule across the codebase as a tenant-isolation gap.

### Root cause

`tax_configurations` is a **country-scoped global reference table**, not a tenant-scoped table:

- Schema (apps/api/database/migrations/2025_12_30_100000_create_tax_configurations_table.php):
  - `id uuid primary key`
  - `country_code char(2)` FK to `countries.code` (cascade delete)
  - `tax_type`, `name`, `code`, `percentage_rate`, `fixed_amount`, `applies_to`, `is_default`, `is_active`, `metadata`
  - **No `tenant_id` column. No `company_id` column.**
- Data semantics: tax configurations are public reference data partitioned by country. Every tenant in France reads the same set of FR tax configurations.
- Cross-tenant exfiltration is structurally impossible because the table contains no tenant-scoped data.

The cross-country business-rule concern (e.g., a French company assigning a Tunisian tax_configuration to itself) is a **separate** validation gap — not a tenant-isolation issue, and out of scope for the tenant-isolation sweep.

### Affected callsites (6 total, 4 clusters)

| Callsite ID | Cluster | File | Line | Symbol |
|---|---|---|---|---|
| api.identity-company.001 | api.identity-company | apps/api/app/Modules/Company/Presentation/Requests/UpdateCompanyRequest.php | 43 | UpdateCompanyRequest::rules `default_tax_configuration_id` |
| (api.catalog: 2 callsites) | api.catalog | UpdateCompositeItemRequest.php / StoreCompositeItemRequest.php | 45 / 43 | `default_tax_configuration_id` |
| (api.taxation: 1 callsite) | api.taxation | TaxConfigurationController.php | 152 | `reorder` `order.*.id` |
| (api.unmapped: 2 callsites) | api.unmapped | UpdateProductRequest.php / CreateProductRequest.php | 55 / 53 | `default_tax_configuration_id` |

### Recommended forward fix (cross-cluster bespoke pass)

1. Remove `tax_configurations` from `PhpPresentationExistsScanner::DEFAULT_GUARDED_TABLES`.
2. Run `php artisan sweep:inventory:generate` — the 6 callsites above will be marked `stale_orphan` because their stable_keys disappear from the scanner output.
3. Track in a follow-up audit if `tax_rates` (also listed in DEFAULT_GUARDED_TABLES at line 79) suffers the same false-positive nature; verify the `tax_rates` schema before excluding.

### This-session disposition

- `api.identity-company.001` deferred via `sweep:inventory:defer` with a reason pointing to this audit. Cluster aggregate flips to `deferred`.
- The 5 sibling callsites in api.catalog / api.taxation / api.unmapped remain `pending` for their respective cluster owners to triage with the same disposition.
- Cross-country validation (defense-in-depth: ensure chosen `tax_configuration.country_code` matches the company's country) is a SEPARATE concern and out of scope for the tenant-isolation sweep.

### References

- Scanner source: `apps/api/app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php:78`
- Schema: `apps/api/database/migrations/2025_12_30_100000_create_tax_configurations_table.php`
- Inventory: `docs/superpowers/plans/tenant-isolation-sweep-inventory.yml` lines 1669, 1807, 2221, 2731, 2777, 7156

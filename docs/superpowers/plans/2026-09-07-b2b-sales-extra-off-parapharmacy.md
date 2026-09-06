# Lane brief — B2B Sales module as a compatible extra, default OFF for parapharmacy (rev 1)

Lane slug: `sales-extra` · branch `lane/sales-extra` · worktree `.worktrees/sales-extra` (off local `dev`).
Queue item 6 (handover 2026-09-07). Ruling: OWNER-RULINGS-parapharmacy 2026-09-05 **Q6/D2** ("compatible extra, default off for parapharmacy, activatable") and Q9 constraint 2 (customer-account top-up stays reachable with Sales inactive). Owner 2026-09-07: greenfield, no live tenants.
Reviewers (code gate): `tenancy-authz-reviewer` + `frontend-conventions-reviewer`.

## Facts verified at local dev (2026-09-07)

- `apps/api/config/verticals.php:335-350` — `parapharmacy` has `Sales` in `default_modules` and `compatible_extras = ['Loyalty','Ecommerce','CompositeItems','PurchaseBonus']`. CLAUDE.md names this file the SoT; a central table `vertical_configs` also exists (`apps/api/app/Models/VerticalConfig.php:22`, migration `2026_06_12_100000_create_vertical_configs_table.php`, super-admin controller `VerticalConfigController`) — the lane must establish which one `CompanyConfigService` reads and update both consistently (seeder/migration for the DB row if it is read).
- Activation model: `CompanyConfigService::getConfigForTenant()` (`apps/api/app/Services/CompanyConfigService.php:48-78`) = vertical `default_modules` ∪ `tenants.enabled_extras` (central JSON) → `all_enabled_modules`; cached (`invalidateForTenant`, `invalidateForVertical`). Route gate: middleware alias `module` → `RequireModule` (`apps/api/bootstrap/app.php:119`). FE gate: `hasModule(name)` from `CompanyConfigContext` reading `all_enabled_modules` (`apps/web/src/contexts/CompanyConfigContext.tsx:66-84`); `RequirePermission moduleKey` is a **permission** alias (`usePermissions.ts:29-45`), not a module check.
- `Sales` (`ModuleName::Sales`) is the B2B document family. In `apps/api/app/Modules/Document/Presentation/routes.php` only 3 of 87 routes carry `module:Sales` (`:318,:323,:341`). The sales-family routes (`/quotes*` `:95-123`, `/orders*` `:128-160`, `/invoices*` `:165-244`, `/credit-notes*` `:249-269`, `/delivery-notes*` `:313-341`) are ungated by module; purchase-family routes (`/purchase-orders*` `:274-308`, supplier invoices, goods receipts, document ingestion) belong to purchasing and must stay reachable for parapharmacy (tenant #1 scope: POS + purchasing/catalog/inventory + transfers + treasury).
- Generic endpoints `/documents` (`:80`), `/documents/{document}` (`:89`), `/documents/{document}/revert` (`:84`), `/documents/auto-save` (`:75`) serve both families → gate **per document type** in the handler/policy, not by route.
- Local census: central `tenants` table has 211 `parapharmacy` + 19 `retail` rows (campaign leftovers); staging unknown (census command below reports it).

## Scope

### T1 — Vertical config
- `config/verticals.php` parapharmacy: remove `Sales` from `default_modules`, add `Sales` to `compatible_extras`. Do the same for the `vertical_configs` row if the service reads the table (write a self-guarding migration/seeder update; never edit by hand). Keep `pharmacy` and all other verticals unchanged. Verify the `ModuleName` drift guard test still passes and add a test pinning "parapharmacy default modules exclude Sales, extras include Sales".
- `CompanyConfigService::invalidateForVertical(Vertical::Parapharmacy)` must be called by the migration/seeder so cached configs refresh.

### T2 — Backend both-layer gating (rule 12)
- Add `module:Sales` to every sales-family route in `Document/Presentation/routes.php` (quotes, sales orders, customer invoices, credit notes, delivery notes, the sales conversions, invoice cancel/credit/refund reads). Purchase-family routes untouched.
- Generic endpoints: in `DocumentController@indexAll/showAny/revert` and `DraftController@autoSave`, refuse sales-family document types when `Sales` is inactive, using the same resolver `RequireModule` uses (constructor-injected; no `app()`), returning the same status/envelope `RequireModule` returns (read it; do not invent a new envelope). Sales-family = `DocumentType` cases quote, sales order, invoice (customer), credit note, delivery note; purchase-family = purchase order, supplier invoice, goods receipt and any other purchasing type.
- Census any other module that serves sales-family documents (Treasury document-payment routes for customer invoices, reports listing sales documents, dashboard sales widgets): list them in the handback with a decision per route (gate / leave with reason). **Do not gate**: partner CRUD, customer accounts, `TreasuryDepositBridge` / customer-account deposit routes (Q9 constraint 2), POS, pricing, catalogue.
- Tests (red first, sqlite lane + one PG leg not required): with a parapharmacy tenant (real registration, no extras) `GET /api/v1/quotes` and `POST /api/v1/invoices` → module-inactive response; `GET /api/v1/purchase-orders` → 200; enable `Sales` via `tenants.enabled_extras` (through the existing super-admin/tenant extras path, not a raw update) → sales routes 200 and `all_enabled_modules` contains `Sales`; a `retail` tenant keeps Sales by default; second company in the same tenant inherits the same module set (convention 09); customer-account deposit route reachable with Sales inactive (Q9 c.2).

### T3 — Frontend gating
- Sales navigation entries and routes (quotes, orders, invoices, credit notes, delivery notes, sales dashboard widgets) gated on `hasModule('Sales')` in addition to permission (`PartnerDetailPage.tsx:219` already does this for the partner sales tab — reuse that pattern; put a single helper if more than three call sites). Purchases nav/routes untouched. Customer-account top-up surface must render with Sales inactive.
- Vitest: nav renders without sales entries when `all_enabled_modules` lacks `Sales`, with them when present; route guard redirects; deposit page renders with Sales inactive.
- No hardcoded strings (rule 11); tokens (rule 18) only on touched lines.

### T4 — Census command + docs
- `tenant:census-modules {--vertical=} {--json}` (central-only read; list tenant id, slug, vertical, `enabled_extras`, `all_enabled_modules`, flag `sales_active`); marker `MODULES CENSUS tenant=<uuid> vertical=<v> sales_active=<0|1>`; run standalone (not `tenants:run`).
- Docs: `docs/architecture/vertical-module-gating.md` (parapharmacy row), glossary if a noun changes, staging note: after promotion run the census on staging and record the count; existing parapharmacy demo tenants that must keep B2B for testing get `Sales` re-enabled through the extras path (list them in the handback).

## Out of scope
Q9 over-payment gate itself (item 4, separate lane), POS, any lot/cash seam file, purchasing routes.

## Verification
```bash
cd apps/api && php artisan test tests/Feature/Document/<new module gate test> tests/Feature/Tenant/<verticals test> tests/Unit/Config/<drift guard> && ./vendor/bin/phpstan analyse <changed files> --memory-limit=1G && ./vendor/bin/pint --test <changed files>
cd apps/web && pnpm vitest run <touched specs> && pnpm typecheck && npx eslint <touched files>
```
Handback: `docs/handoff/HANDBACK-sales-extra-2026-09-07.md`. Deployment: additive config + route middleware, no migration unless the `vertical_configs` row is authoritative (then a self-guarding one); web deploy per manifest §3.

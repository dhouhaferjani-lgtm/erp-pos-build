# Roles & permissions — catalogue as code, principals & tokens, enforcement standard — design spec rev 1

Date 2026-09-10 · Lane family: RBAC (waves 0a, 0b, 1, 2, 3, 4) · Status: **REV 1, for Codex adversarial gate round 1** · Direction: **B (catalogue-as-code with deploy-time sync)**, accepted by the owner 2026-09-10.

## 0. Status, base SHA, sources, decided vs open

**Base SHA.** Every `path:line` in this document was read at **`971528977`** (`git rev-parse --short HEAD` in the worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-audit`, branch `docs/rbac-audit-2026-09-09`, rebased onto `dev` as the first step of this lane, clean rebase, no conflicts). Paths are relative to the repository root `apps/erp/` unless the citation itself begins with `apps/`. No code, test or configuration file is modified by this lane — it is a document-only branch.

**Sources read (in order).**

| Source | What it supplies |
|---|---|
| `docs/superpowers/audits/2026-09-09-roles-permissions/06-synthesis.md` | findings I-1..I-22, benchmark gap table B1–B15, decisions D1–D8, wave outline |
| `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md` | verdicts C1–C12 and the five missed findings; corrections folded into I-18..I-22 |
| `docs/superpowers/audits/2026-09-09-roles-permissions/01-backend-permission-model.md` | catalogue census (304 permissions, 7 roles), naming outliers, dead/orphan lists, usage counts |
| `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.md` + `.csv` | 1054 routes, classification totals, the live write `AUTH_ONLY` table |
| `docs/superpowers/audits/2026-09-09-roles-permissions/03-frontend-pos-consumption.md` | FE hook, alias map, i18n label gaps, POS consumption |
| `docs/superpowers/audits/2026-09-09-roles-permissions/05-benchmark-odoo-erpnext-dolibarr.md` | rows G1–G15 condensed into §1 |
| `docs/conventions/09-SECOND-OF-EVERYTHING.md`, `10-BENCHMARK-FIRST-SPECS.md`, `11-ONE-SURFACE-PER-CONCEPT.md`, `docs/glossary.md` | §1 matrix shape, §2 vocabulary, §6 obligations |
| `.claude/context/architecture.md` | hexagonal layer placement — where manifests, registry, services and middleware go (§4) |
| `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md` | accepted spec format; `inventory.transfers.reconcile` + the two-permission Close gate that §4.2 must be able to express |
| `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md` (that plan's own §8.4 "Delta service and permission-team boundary" and §8.6 "Seeder contract") + `docs/handoff/CODEX-PROMPT-WLOTA-1a-ruling-legacy-null-team-roles-2026-09-09.md` | the in-flight seeder contract this design must be a **superset** of, never a replacement |
| `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md` Task 1 | S-1 tenant-scoped permission cache — referenced, not redesigned (§4.8) |

**Vocabulary line (convention 11, `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:74-76`).** Concepts: Membership (glossary ✅, `docs/glossary.md:16`), Tenant (glossary ✅), Company (glossary ✅), Location (glossary ✅), Principal (NEW — glossary row added in wave 2), User (NEW — row added in wave 0a), Service account (NEW — wave 2), API token / Token scope (NEW — wave 2), Role (NEW — wave 0a), System role (NEW — wave 2), Custom role (NEW — wave 2), Permission (NEW — wave 0a), Permission verb (NEW — wave 1), Permission manifest (NEW — wave 1), Permission registry (NEW — wave 1), Grant (NEW — wave 1), Effective permissions (NEW — wave 2), Super admin (NEW — wave 0a), Support approver (NEW — wave 0a), General manager (NEW — reuses W-LOT-A-1a's wording, added by whichever of the two lanes merges first). Full rows in §2.

**What is decided (owner, 2026-09-10) — not reopened by this spec.**

1. **Direction B accepted**: per-module permission manifests + a central registry + an idempotent `permissions:sync` in the deploy path. Direction A's content becomes wave 0a/0b; Direction C (ABAC engine) is rejected.
2. **D1** — roles stay **tenant-scoped** (Spatie team = `tenant_id`); company and location scope stays on membership.
3. **D2** — **template-delta** policy for the seeded roles; a tenant that has customised a seeded role stops receiving deltas for that role and is told so in the Roles UI.
4. **D3** — ungated routes close via a **ratchet with a ceilinged baseline**, writes first.
5. **D4** — `roles.view` gates the permission matrix reads; role **names** stay readable to `users.assign-roles` holders.
6. **D5** — freeze **`kebab-resource.verb`** with a **closed verb enum**; rename the outliers through a sync rename map.
7. **D6** — **no** general per-user permission overrides; the discount numerics stay as they are.
8. **D7** — wave 0b and later start **after `lane/w-lot-a-1a` merges**; the registry absorbs that lane's permissions and its seeder contract.
9. **D8** — **audit events** for role create/update/delete and for 403 denials on write routes.
10. **NEW (2026-09-10)** — *"later we might use the MCP through service accounts, so every user that has an MCP should also have their permissions reflected."* Service accounts and MCP/API tokens are **first-class principals**; a token's effective permission set is **the owning principal's grants intersected with the token's abilities** — never wider — and every audited write carries both the principal id and the token id.

**What is open** — four questions only, each with a benchmark and a recommended default, in §9.2. Nothing in §§1–8 depends on an unresolved answer; each open question names the default the design is written against.

**What this spec is not.** It is a design, not an execution plan. Each wave in §8 becomes its own execution plan and its own Codex adversarial gate before any code is written (the repo's standing rule, `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md` is the shape).
---

## 1. Industry baseline (benchmark-first — convention 10, `docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37-56`)

Flow: declaring, granting, enforcing, auditing and machine-scoping authorization inside a multi-tenant, multi-company, multi-location ERP. Reference systems: **Odoo 17/18/19**, **ERPNext / Frappe v15**, **Dolibarr 20/21**, plus **Spatie laravel-permission 6.25** and **Laravel Sanctum 4** as the stack's own baseline. G1–G15 are condensed one line each from `docs/superpowers/audits/2026-09-09-roles-permissions/05-benchmark-odoo-erpnext-dolibarr.md` (URLs preserved); **G16 is new research for this spec** (2026-09-10), because service principals are a new topic the audit did not cover.

Decision vocabulary per convention 10: **MATCH** (do what they do — name the wave), **DEFER** (agreed gap, parked with a ticket), **DIVERGE** (deliberately otherwise, one sentence why), **ALREADY** (we already match; cited).

| # | Guarantee the baseline gives the user | Odoo | ERPNext / Frappe | Dolibarr | AutoERP today (path:line @ `971528977`) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| G1 | Action permissions are a declared, closed set per resource, including business verbs beyond CRUD | model-level CRUD only (`ir.model.access`: read/write/create/unlink) + row-level `ir.rule`; business actions are ad-hoc `groups=` checks ([security](https://www.odoo.com/documentation/19.0/developer/reference/backend/security.html)) | rich fixed verb set on `DocPerm` — `read, write, create, delete, submit, cancel, amend, report, export, import, print, email, share, set_user_permissions` + perm levels 0–9 ([role-based-permissions](https://docs.frappe.io/erpnext/role-based-permissions)) | per-module `$this->rights[]`, conventionally `lire/creer/supprimer` + module specials ([Module_Users](https://wiki.dolibarr.org/index.php?title=Module_Users_%28developer%29)) | 304 permission strings in one 871-line array, verbs uncontrolled: `view/create/update/delete` alongside `manage`, `post`, `cancel`, `confirm`, `convert`, `receive`, `reconcile`, `reopen`, `close`, `edit` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:48-559`) | verbs exist but are unconstrained | **MATCH ERPNext's breadth + a closed enum** — `PermissionVerb` (§4.2), wave 1 |
| G2 | Roles are named permission bundles; a protected administrator role exists and cannot be dismantled | `res.groups` with `implied_ids` hierarchy; `base.group_system` protected by convention ([access rights](https://www.odoo.com/documentation/19.0/applications/general/users/access_rights.html)) | flat `Role` + `Role Profile` bundles; `Administrator` above `System Manager`, withheld from Frappe Cloud tenants ([administrator](https://docs.frappe.io/erpnext/v13/user/manual/en/setting-up/users-and-permissions/administrator)) | flat users/groups, `admin=1` bypasses ([permissions guide](https://dolimarketplace.com/en-us/blogs/dolibarr/configuring-user-permissions-in-dolibarr-a-detailed-guide)) | 7 flat Spatie roles (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:582,585,690,726,766,791,825`); "system role" is a hard-coded `['super-admin','admin','owner']` list of which only `admin` is a real role (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:228,277`), and `syncPermissions` at `:246` runs unconditionally **including on `admin`** | protection is name-only; `admin` can be emptied | **MATCH** — `roles.is_system` + `admin` permission floor + last-admin floor (§4.6), wave 2 |
| G3 | Permissions are declared as versioned code per module and synced idempotently on every install/upgrade | CSV `ir.model.access.csv` re-applied on every install **and** `-u` upgrade; `noupdate="1"` preserves admin edits ([security tutorial](https://www.odoo.com/documentation/19.0/developer/tutorials/server_framework_101/04_securityintro.html)) | JSON `permissions` block inside each DocType `.json`, synced by `bench migrate`; admin edits live as **Custom DocPerm** overlays ([bench migrate](https://docs.frappe.io/framework/user/en/bench/reference/migrate)) | `$this->rights[]` in the module descriptor, INSERTed into `llx_rights_def` on module activation ([Module_Users](https://wiki.dolibarr.org/index.php?title=Module_Users_%28developer%29)) | one monolithic seeder; the application never re-syncs an existing tenant (`seedRolesAndPermissionsIfMissing()` early-returns once `admin` exists), while staging runs a **destructive full reseed on every deploy** (`apps/api/docker/entrypoint.sh:162-165`, I-20) | **the core gap** — this is what "new features appear automatically" requires | **MATCH** — per-module manifests + registry + `permissions:sync` in `tenants:migrate` (§4.2, §4.3), wave 1 |
| G4 | A role×permission matrix editor grouped by module, plus a resolved "effective permissions for this user" view | user-form Access Rights tab + Technical ▸ Groups; no unified matrix | Role Permission Manager matrix (Role × DocType × level) ([role-based-permissions](https://docs.frappe.io/erpnext/role-based-permissions)) | per-user and per-group checkbox grid by module | matrix editor exists (`apps/web/src/features/settings/RolesPage.tsx:151,186-201`); **no** effective-permissions view anywhere | effective view missing (all three OSS ERPs also lack it — a gap worth closing, not copying) | **MATCH + improve** — `GET /users/{id}/effective-permissions` + panel (§4.6), wave 2 |
| G5 | Action permission and data scope are two orthogonal layers | ACL + `ir.rule` record rules, multi-company as a global rule ([restrict data access](https://www.odoo.com/documentation/19.0/developer/tutorials/restrict_data_access.html)) | DocPerm + **User Permissions** (Company/Warehouse link restriction) ([user-permissions](https://docs.frappe.io/erpnext/user-permissions)) | multicompany `entity` column enforced by convention in every query ([MultiEntity_dev](https://wiki.dolibarr.org/index.php?title=MultiEntity_dev)) | already the shape: Spatie actions + `user_company_memberships` + `allowed_location_ids` + `ValidateLocationAccess` (`apps/api/bootstrap/app.php:118`) | none structurally; undocumented (I-8, I-14) | **ALREADY** — documented by the §2 glossary rows, wave 0a |
| G6 | The platform operator is categorically separate from any tenant role, and its actions are logged where the tenant can see them | `sudo()`/uid 1 superuser; Odoo Online separates DB admin from Odoo staff, both in a tenant-visible Admin Activity Log ([odoo online](https://www.odoo.com/documentation/19.0/administration/odoo_online.html)) | `Administrator` credential withheld on Frappe Cloud; `System Manager` is the tenant ceiling ([administrator](https://docs.frappe.io/erpnext/v13/user/manual/en/setting-up/users-and-permissions/administrator)) | `admin=1` flag; no platform/tenant split (self-hosted) | separate central `SuperAdmin` model on its own guard (`apps/api/config/auth.php:50-53`), no `Gate::before`, impersonation request/approve disjoint (`apps/api/app/Modules/SupportAccess/Presentation/routes.php:23-27,35-42`) | matches; super-admin MFA and the TN legal gate still owed | **ALREADY** for the separation; **DEFER** MFA + legal gate (E-4 ticket, §10) |
| G7 | A new user starts with zero permissions; onboarding is by curated role templates | no app access until assigned; ships User/Manager pairs per app | no roles until assigned; ships module role pairs + Role Profiles ([role-and-role-profile](https://docs.frappe.io/erpnext/role-and-role-profile)) | strictest — zero rights until each is switched on | new users are assigned a seeded role; 7 templates exist (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:578-871`) | minor | **ALREADY** |
| G8 | Create and approve on a financially significant document are never the same grant | Purchase double-validation group, distinct from create/confirm ([approvals](https://odoo-users.readthedocs.io/en/latest/purchase/purchases/rfq/approvals.html)) | Workflow transitions gated per role ([workflow](https://docs.frappe.io/erpnext/workflow)) | by convention: don't grant validate to the group that has create | partly true in practice (`payments.reverse` admin-only, `pos.approve_*`), but **no stated rule and nothing enforcing it**; NIST INCITS 359 SSD/DSD is implemented by none of the three | rule is unwritten | **MATCH (lightweight)** — `sod_group` on each definition + a registry test (§4.7), wave 1 |
| G9 | POS manager-override per action via a step-up PIN, bound to a location | per-action Manager Approval toggles + PIN ([discounts](https://www.odoo.com/documentation/17.0/applications/sales/point_of_sale/pricing/discounts.html)) | POS Profile "Applicable for Users"; overrides via ordinary Role checks ([pos-profile](https://docs.frappe.io/erpnext/pos-profile)) | TakePOSPIN per cashier ([TakePOS](https://wiki.dolibarr.org/index.php/Module_Point_of_sale_(TakePOS))) | exists: `pos.approve_*` permissions drive approval scopes, PIN holders scoped to active members of the CompanyContext company (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-57`) | dead `pos.*` names + a legacy role ladder remain | **ALREADY** for the mechanism; **MATCH** the cleanup (§4.10), wave 3 |
| G10 | Role/permission changes, authorization denials and impersonation are mandatory audit events | field `tracking=True` is opt-in; OCA `auditlog` fills the gap; Admin Activity Log for platform actions | Version log per edit; Activity Log; impersonation always logged ([document-versioning](https://docs.frappe.io/erpnext/document-versioning)) | events/security log; denial logging not documented | role **assignment** is audited (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:360-372`, `RoleAssigned` → `audit_events`); role **create/update/delete** emit nothing (`:187-220`, `:223-266`, `:272-309`); denials emit nothing | mutations + denials missing | **MATCH** (D8) — `RoleCreated/RoleUpdated/RoleDeleted` + denial events (§4.6), wave 2 |
| G11 | The permission cache is correctly scoped and reset on every mutation and deploy | ORM registry invalidation | best-effort; raw SQL writes need `bench clear-cache` ([caching](https://docs.frappe.io/framework/user/en/guides/caching)) | largely computed per request | **single global key** `spatie.permission.cache` on the shared Redis store (`apps/api/config/permission.php:192,200`) under database-per-tenant, where each tenant DB has its own `permissions` table with its own `bigIncrements` ids; only mitigation is the boot flush at `apps/api/docker/entrypoint.sh:176` | **P0** — a tenant can be served another tenant's snapshot | **MATCH — first** — S-1 Task 1 (`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:60-140`), referenced not redesigned (§4.8), wave 0a |
| G12 | The UI renders exclusively from a server-computed effective-permission payload; no second client map | server strips `groups=`-gated views/menus/fields before serving | `bootinfo` payload; `frappe.perm.has_perm` reads it ([users-and-permissions](https://docs.frappe.io/framework/v14/user/en/basics/users-and-permissions)) | one `$user->rights` object drives both menu and enforcement | hybrid and **fail-open**: `usePermissions` checks server permissions, then falls back to a generated role map and 10 UI aliases (`apps/web/src/hooks/usePermissions.ts:193-211`); `uiAliasPermissions.ts:3-12` names five roles no seeder creates; `services.*` exists only there and the API has no `can:` at all | fail-open fallback; client-side-only authorization for `/services` | **MATCH** — server-authoritative only, delete the fallback and the alias file, make `services.*` real (§4.5), waves 0b + 2 |
| G13 | One frozen key format with a documented verb list | `<module>.group_<name>` external ids | human-readable Role names; permissions are DocPerm rows, not strings | `module->object->action` PHP chains | `resource.verb` dominant but with snake_case outliers `catalog_cart.*`, `pos_orders.*`, `pos_held_orders.*` beside kebab `purchase-orders.*`, and `edit` vs `update` (`uom.edit`, `deliveries.edit`) (`docs/superpowers/audits/2026-09-09-roles-permissions/01-backend-permission-model.md:56`) | 5 outlier families | **MATCH (D5)** — freeze `kebab-resource.verb`, closed verb enum, rename map (§4.2, §4.9), wave 1. **DIVERGE from the audit's own G13 recommendation** of Filament Shield's `{verb}_{resource}` snake_case: adopting it would rename all 304 keys instead of 5 families, for no functional gain |
| G14 | "Log in as" is a first-class, logged, platform-only feature attributing writes to the real actor | OCA `impersonate_login`, group-gated and logged | native since v15; Activity Log; writes attributed to the impersonator ([adding-users](https://docs.erpnext.com/docs/user/manual/en/adding-users)) | not in core | exists, with a four-eyes request/approve split and impersonation columns on `audit_events` (`apps/api/app/Modules/Compliance/Domain/AuditEvent.php:58-63`) | matches | **ALREADY**; §4.1 only adds the rule that a **service** principal can never be impersonated |
| G15 | CI fails when a route lacks an explicit permission check; per-role regression matrix | ORM enforces, so any non-superuser test exercises ACLs | `@frappe.whitelist()` authorizes nothing by itself — a documented footgun with real CVE-class findings ([code security guidelines](https://github.com/frappe/erpnext/wiki/Code-Security-Guidelines)) | not formalised | **nothing**: no route-coverage ratchet, no PHPStan permission rule; nearest gates are `AuthLifecycleTest` (middleware only) and `CentralAdminRouteInventoryTest` (16 central routes). 142 of 1054 routes are authenticated with no check at any layer; 51 of them are verified live writes | **P0** — the exact Frappe failure mode | **MATCH** (D3) — route-coverage ratchet with a ceilinged baseline, writes first (§4.4), wave 0a |
| **G16** | **A machine/API credential is a first-class principal whose token can be scoped *narrower* than its owner and never wider** | **NO.** `res.users.apikeys.user_id` is required with `ondelete=cascade` ([17.0 res_users.py#L2164-2173](https://github.com/odoo/odoo/blob/17.0/odoo/addons/base/models/res_users.py#L2164-L2173)); the docs say a key gives "essentially the same access to your user account" ([external_api §API keys](https://www.odoo.com/documentation/17.0/developer/reference/external_api.html#api-keys)) and all calls run under the user's normal ACL/record rules ([19.0 §Access Rights](https://www.odoo.com/documentation/19.0/developer/reference/external_api.html#access-rights)). `scope` exists since 14.0 (**not** an 18.0 addition) but is a key-*purpose* namespace (`trusted_device`), unsettable from the UI (`_generate(None, …)` [#L2266](https://github.com/odoo/odoo/blob/17.0/odoo/addons/base/models/res_users.py#L2266); issue [#161030](https://github.com/odoo/odoo/issues/161030)) and verified as `scope IS NULL OR scope = %s` ([#L2225](https://github.com/odoo/odoo/blob/17.0/odoo/addons/base/models/res_users.py#L2225)) — a NULL scope satisfies everything. Keys bypass 2FA by design ([auth_totp#L74-77](https://github.com/odoo/odoo/blob/18.0/addons/auth_totp/models/res_users.py#L74-L77)). No service-account object; 19.0 merely *recommends* a "dedicated bot user" with minimum permissions and an empty password ([19.0 §Access Rights](https://www.odoo.com/documentation/19.0/developer/reference/external_api.html#access-rights)). 18.0 added token **expiry** (`expiration_date`, per-group `api_key_duration`) | **NO.** One `api_key` + `api_secret` pair on the User doctype ([user.json v15](https://github.com/frappe/frappe/blob/version-15/frappe/core/doctype/user/user.json)); `validate_api_key_secret()` ends in `frappe.set_user(user)` and nothing else ([auth.py#L708-731](https://github.com/frappe/frappe/blob/version-15/frappe/auth.py#L708-L731)) — full role set, no per-token restriction, no expiry, no second named token. OAuth2 scopes validate only against the OAuth Client's own declared list and never map to DocType permissions ([oauth.py#L51-54,229-245](https://github.com/frappe/frappe/blob/version-15/frappe/oauth.py#L51-L54)). API keys bypass 2FA (2FA runs only in `LoginManager.login()`, [auth.py#L146-149](https://github.com/frappe/frappe/blob/version-15/frappe/auth.py#L146-L149)). No official service-account concept — only `user_type` System vs Website User ([users-and-permissions](https://docs.frappe.io/framework/user/en/basics/users-and-permissions)) | **NO.** One `api_key` column on `llx_user`, gated by the right `api→apikey→generate` ([card.php#L2100](https://github.com/Dolibarr/dolibarr/blob/21.0/htdocs/user/card.php#L2100)); `DOLAPIKEY` resolves the user then calls `$fuser->loadRights()` and `static::$user = $fuser` ([api_access.class.php#L130-133,260-266](https://github.com/Dolibarr/dolibarr/blob/21.0/htdocs/api/class/api_access.class.php#L130-L133)) — the REST API checks the identical rights constants as the UI (`hasRight('produit','lire')`). No scoping field, no expiry, no multiple keys, no service-account entity | **Sanctum has the mechanism; nothing uses it as a downscope.** `createToken(name, abilities = ['*'], expiresAt = null)` ([HasApiTokens.php#L58](https://github.com/laravel/sanctum/blob/4.x/src/HasApiTokens.php#L58)) defaults to full power; `can()` short-circuits on `'*'` ([PersonalAccessToken.php#L78-82](https://github.com/laravel/sanctum/blob/4.x/src/PersonalAccessToken.php#L78-L82)); abilities are a **parallel opt-in** check (`tokenCan`, `abilities`/`ability` middleware) that never reduces `$user->can()` — Laravel documents checking both dimensions explicitly ([§First-Party UI Initiated Requests](https://laravel.com/docs/12.x/sanctum#first-party-ui-initiated-requests)) and notes `tokenCan` "will always return `true`" on a first-party SPA session. In AutoERP the narrowing already exists but only for impersonation: `User::hasPermissionTo()`/`getAllPermissions()` intersect grants with `permission:<key>` abilities **only when** an `impersonation:` ability is present (`apps/api/app/Modules/Identity/Domain/User.php:216-241`, gate at `:225-234`). There is no `principal_kind`, no service account, no token-scope UI, no token expiry policy, and `X-Company-Id` has no machine contract | machine access is undesigned; the one narrowing primitive is locked to impersonation | **DIVERGE from all three ERPs, MATCH the industry norm.** The ERPs' answer — "clone a crippled user per integration" — multiplies principals, audit identities and seats to express what is really one identity with several credentials. Instead: service accounts are principals in the same `users` table (matching the ERPs' *"a machine is a user"* half), **and** the token narrows below its owner (matching GitHub fine-grained PATs — *"A token cannot grant additional access capabilities to a user"* ([managing PATs](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens)); Stripe restricted keys, which now deprecate the unrestricted form ([Stripe API keys](https://docs.stripe.com/keys)); NIST SP 800-53r5 **AC-6** least privilege, whose wording covers "processes acting on behalf of users" ([AC-6](https://csf.tools/reference/nist-sp-800-53/r5/ac/ac-6/)); OWASP Secrets Management, "scope credentials used by the CI/CD tooling" ([cheat sheet](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html))). §4.1, wave 2 |

**Domain norm (the one-line conclusion).** Across Odoo, ERPNext and Dolibarr a machine principal *is a user* and its API credential inherits **100 %** of that user's rights and bypasses 2FA; none of the three can scope a token below its user, and the only narrowing lever they offer is provisioning a second, deliberately crippled user. Laravel Sanctum is the outlier that *has* per-token abilities, but as an opt-in parallel check that defaults to `['*']` and does not reduce `$user->can()`. Industry practice outside ERP (GitHub fine-grained PATs, Stripe restricted keys, NIST AC-6, OWASP) is unambiguous the other way: a machine credential must be narrowable **below**, and never **above**, its owning principal. This spec takes the ERP half (a machine is a principal with roles) and the industry half (the token narrows), which is why G16 is the only DIVERGE row in the table.

**Two benchmark premises this research falsified, recorded so a gate can re-check them:** (1) Odoo's `apikeys.scope` was **not** introduced in 18.0 — it is present in 17.0 and dates to 14.0, it is not a permission scope, and it cannot be set from the UI; what 18.0 added is `expiration_date` plus a per-group `api_key_duration` ceiling. (2) The often-quoted Sanctum caveat that abilities "are not checked automatically and do not reduce the user's authorization" appears **verbatim in no Laravel docs version 8.x–13.x**; the behaviour is real and provable from `HasApiTokens`/`TransientToken` source, and the citable documentation is the "First-Party UI Initiated Requests" section, not that quote.

**Second-of-everything (convention 09, `docs/conventions/09-SECOND-OF-EVERYTHING.md:37-50`):** §6 states the obligation per wave. `roles` and `permissions` are not `CATALOGUE_TABLES` entities today and this design does not make them so (D1 keeps roles tenant-scoped by ruling) — §6 records the classification and the waiver wording the ratchet needs if either table is ever swept.
---

## 2. Vocabulary — glossary rows (convention 11)

New section **"Identity and authorization"** in `docs/glossary.md`, added by the wave that first needs each row (§8 says which). One table, one primary write path, one operator surface per noun; synonyms are declared here, never discovered.

| Term | Definition | Table / module | Canonical surface (primary write path) | Synonyms |
|---|---|---|---|---|
| **Principal** | Anything that can hold grants and act inside a tenant: a **User** (`principal_kind = human`) or a **Service account** (`principal_kind = service`). One table, one role-assignment mechanism, one audit identity. A principal is never a Spatie role and never a membership. | `users` (discriminated by `users.principal_kind`, `PrincipalKind` enum) / `Identity` | Settings → Users (humans); Settings → Service accounts (services) — both write through `UserService` | actor, identity |
| **User** | A **human** principal of a tenant: has a password, may have a POS PIN, logs in at `/login`, verifies an email. `users.principal_kind = human`. | `users` / `Identity` | Settings → Users (`POST /api/v1/users`, `apps/api/app/Modules/Identity/routes.php:68`) | employee, utilisateur, operator (POS copy only) |
| **Service account** | A **machine** principal of a tenant: no password, no POS PIN, no login, no email verification, cannot be a POS PIN holder, cannot be impersonated. It carries memberships and Spatie roles exactly like a user, and acts only through API tokens. Created and managed under `service-accounts.*`. | `users` with `principal_kind = service` / `Identity` | Settings → Service accounts (`POST /api/v1/service-accounts`) | machine user, integration user, API user, bot |
| **API token** | One Sanctum personal access token minted for a principal. Carries the existing `tenant:<uuid>` claim (`apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php:84-103`) and, optionally, a **token scope**. | `personal_access_tokens` (central connection, `.claude/context/architecture.md` "Pinned-connection models") / `Identity` | Settings → Service accounts → Tokens (`POST /api/v1/service-accounts/{id}/tokens`); a human's own tokens under Profile → API tokens | PAT, API key, bearer token |
| **Token scope** | The optional narrowing list on a token, expressed as `permission:<permission-key>` ability strings — the same prefix SupportAccess already uses (`apps/api/app/Modules/Identity/Domain/User.php:237-238`). A token with no `permission:` ability is **unscoped** and carries the principal's full grants; a token with at least one is limited to that intersection. A scope can only narrow, never widen. | `personal_access_tokens.abilities` (JSON) | token creation dialog (checkbox list built from the registry) | token abilities, token permissions |
| **Role** | A named, tenant-scoped bundle of permissions (Spatie `Role`, team = `tenant_id`, `apps/api/config/permission.php:99,134`). Assigned to principals. Two kinds: **system role** and **custom role**. | `roles`, `role_has_permissions`, `model_has_roles` / `Identity` | Settings → Roles (`apps/web/src/features/settings/RolesPage.tsx`) | rôle, profile (UI copy only) |
| **System role** | A role the platform ships and keeps in step with the catalogue: `roles.is_system = true`, `roles.template_key` names the template it tracks, `roles.template_version` records the template version last applied, `roles.customised_at` records the first tenant edit. Cannot be deleted; cannot be renamed; `admin` additionally cannot lose permissions (§4.6). Today the seven are `admin, manager, cashier, viewer, technician, operator, accountant` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:582,585,690,726,766,791,825`); lane `lane/w-lot-a-1a` adds `general_manager` as the eighth. **`roles.provisioning_source` is a different, narrower thing and is not this concept**: it is W-LOT-A-1a's per-tenant marker meaning "this tenant already carries the W-LOT-A-1a delta", constrained by a CHECK to `('w-lot-a-1a', name = 'general_manager', guard_name = 'sanctum', tenant_id IS NOT NULL)` (`docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:1182-1193`). This design **never writes, clears or widens it** (§4.3). | `roles.is_system`, `roles.template_key`, `roles.template_version`, `roles.customised_at` (new, wave 1); `roles.provisioning_source` (existing, W-LOT-A-1a) | Settings → Roles (permissions editable; name and existence are not) | seeded role, built-in role, template role |
| **Custom role** | A role a tenant created (`roles.is_system = false`, `template_key IS NULL`). Never receives template deltas; never auto-granted a new permission by sync. | `roles` | Settings → Roles → New role | tenant role |
| **Permission** | One `resource.verb` action key, declared in a module's **permission manifest** and materialised as a Spatie `Permission` row in every tenant database. Guard is always `sanctum`. The key is the contract; the row's numeric id is per-tenant and meaningless across tenants. | `permissions` (`unique(name, guard_name)`, `apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:30`) / owning module | none — permissions are **code**, never operator-created; the Roles matrix only grants them | right, ability (avoid: "ability" is Sanctum's word for a token string) |
| **Permission verb** | The action half of a permission key, drawn from a closed enum `PermissionVerb` (§4.2). Verbs are declared, not invented. | `PermissionVerb` enum / `Shared` | — | action |
| **Permission manifest** | The per-module class that declares every permission that module owns, with labels, template defaults, SoD group and lifecycle metadata. The single write path for the catalogue. | `app/Modules/<Module>/Domain/Authorization/<Module>PermissionManifest.php` | code review; there is no UI | permission declaration, permission CSV (Odoo analogue) |
| **Permission registry** | The singleton that aggregates every module's manifest into one ordered, validated catalogue, and is the only reader the sync command, the FE map exporter, the label test and the PHPStan rule consult. | `App\Shared\Domain\Authorization\PermissionRegistry` (container singleton) | — | catalogue |
| **Grant** | The link between a role and a permission (`role_has_permissions`) or between a principal and a role (`model_has_roles`). A grant is the only thing sync and the Roles UI write; permissions themselves are never granted directly to a principal in this design. | `role_has_permissions`, `model_has_roles` | Settings → Roles (role↔permission); Settings → Users → Roles (principal↔role) | assignment |
| **Membership** *(existing row — reconciled)* | A principal's access to a **company**, with a `MembershipRole` and an `allowed_location_ids` scope. **Orthogonal to Role**: membership answers *which company and locations*, Role answers *which actions*. `MembershipRole` is **not** an authorization axis and has exactly one production authz read today (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:929` `isOwner()`); wave 3 either removes that read or documents it as the sole exception. The glossary row at `docs/glossary.md:16` is extended with this sentence — it is not replaced. | `user_company_memberships` / `Identity` | Settings → Users | company access |
| **Effective permissions** | What a request may actually do: `roles ∪ direct grants` (∅ by D6) **intersected with** the token scope, then further constrained by company membership and `allowed_location_ids` at the row level. Read-only, computed, never stored. Exposed at `GET /api/v1/users/{id}/effective-permissions` (§4.6) and, for the caller, in `/auth/me` (`apps/api/app/Modules/Identity/routes.php:41`). | derived | Settings → Users → *Effective permissions* panel | resolved permissions |
| **Super admin** | A **platform** operator. A separate central-DB model on its own guard (`apps/api/app/Models/SuperAdmin.php`, `apps/api/config/auth.php:50-53`), never a tenant role, never assignable from a tenant UI, with roles `super_admin \| support_approver \| defaults_editor` (`packages/shared/types/generated.d.ts:8`). No `Gate::before` bypass exists and none is added. | `super_admins` (central DB) / central admin | `/admin/login` | platform operator, Synerivia staff |
| **Support approver** | The super-admin role that approves a tenant impersonation request; request and approve are disjoint by route (`apps/api/app/Modules/SupportAccess/Presentation/routes.php:23-27,35-42`) — the four-eyes rule. | `super_admins.role = support_approver` | `/admin/support-access` | second pair of eyes |
| **General manager** | The tenant system role introduced by lane `lane/w-lot-a-1a`: the manager permission set with no location restriction, **plus** `batches.recall` and `treasury.manage_all_locations` (`docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:1431-1444`, owner ruling at `:252`). Name string `'general_manager'` comes from that lane's `App\Modules\Identity\Domain\Enums\SystemRoleName::GeneralManager` (`:1241-1244`) and this design **reuses that enum rather than declaring a second name constant**. This design keeps the name, the set and the marker exactly as that lane defines them, and only re-homes the *declaration* from the seeder array into the manifests (§4.9). | `roles` (`is_system = true`, `template_key = 'general_manager'`, `provisioning_source = 'w-lot-a-1a'`) | Settings → Roles | directeur général |

**Declared synonyms and the surfaces they must never become.** "Permission" and "ability" are *not* synonyms in code: `permission` is a catalogue key, `ability` is a Sanctum token string, and the bridge between them is the literal prefix `permission:` (§4.1). "Role" and `MembershipRole` are *not* synonyms: the enum keeps its name and its column, and no code may branch on it for action authorization after wave 3. "Service account" has exactly one table (`users`) and one create path; a second `service_accounts` table would be a convention-11 BLOCKER.
---

## 3. Goals, non-goals, and the automatic-inclusion guarantee

### 3.1 Goals

**G-A — Automatic inclusion.** A module that declares a permission gets it everywhere, with no second edit and no manual step. Stated as a testable contract in §3.3.

**G-B — Nothing authenticated is unauthorized by accident.** Every non-public API route declares an action gate, or is explicitly marked self-service, and CI refuses new routes that do neither (§4.4).

**G-C — A deploy never destroys a tenant's role customisation.** The current staging behaviour — a full destructive reseed on every push (`apps/api/docker/entrypoint.sh:162-165`, I-20) — is replaced by an additive, template-delta sync (§4.3).

**G-D — Machine access is a first-class, narrowable principal.** Service accounts hold roles like users; a token may be *narrower* than its owner and never wider; every write is attributable to (principal, token) (§4.1).

**G-E — One vocabulary, one surface.** The catalogue is the only definition of a permission; the FE renders from a server-computed effective set with no second map (§4.5); `MembershipRole` stops being a shadow authorization axis (wave 3).

**G-F — A tenant cannot lock itself out.** Last-admin floor, `admin` permission floor, role-mutation audit, denial audit (§4.6).

### 3.2 Non-goals (in this design; some are separately ticketed — see §10)

- No ABAC/policy engine replacing Spatie (Direction C, rejected).
- No per-company roles (D1). No general per-user permission overrides (D6).
- No `Gate::before` super-admin bypass — the platform operator stays a separate central model and guard (`apps/api/config/auth.php:50-53`), which is what the benchmark's G6 recommends and what already exists.
- No change to the discount numerics (`can_discount`, `max_discount_percent`) beyond surfacing them in the effective-permissions view (I-17 stays a P3 ticket).
- No POS PIN model change; approval scopes keep their present semantics (§4.10).
- No new central-DB table. Everything new is a **tenant** migration (§5).

### 3.3 The automatic-inclusion guarantee, as a testable contract

> **Contract AI-1.** When a module adds a `PermissionDefinition` to its `PermissionManifest` and nothing else, then for every tenant, on the next deploy:
>
> **(a) Created** — a `permissions` row with that key and `guard_name = 'sanctum'` exists in every tenant database.
> **(b) Admin** — the `admin` system role holds it.
> **(c) Template delta** — every *other* system role whose template declares a default for that key holds it, unless that role is customised in that tenant (`roles.customised_at IS NOT NULL`), in which case it is skipped and reported.
> **(d) Visible** — it appears in `GET /api/v1/permissions` grouped under its declared module, and renders in the Roles matrix with a module label and an action label in **en, fr and ar**.
> **(e) Typed** — it is reachable from PHP as a case of the module's permission enum and from TypeScript as a member of the generated `Permission` union.
> **(f) Refused** — CI fails if any of (a)–(e) cannot hold.

**How each clause is proven (the executable half — no clause is a promise without a named test).**

| Clause | Proof | Lane |
|---|---|---|
| (a) | `PermissionsSyncTest::sync_creates_every_registry_permission_in_a_fresh_tenant_and_in_a_second_tenant` (PG, two real tenant databases via `ProvisionsTenantDatabases`) | wave 1 |
| (b) | `PermissionsSyncTest::admin_holds_exactly_the_registry_list_after_sync` — asserts set equality against `PermissionRegistry::keys()`, which also proves `Permission::all()` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:568`, I-19) is gone | wave 1 |
| (c) | `PermissionsSyncTemplateDeltaTest::delta_applies_to_untouched_system_role_and_skips_a_customised_one` + `::custom_roles_never_receive_a_delta` | wave 1 |
| (d) | `PermissionRegistryLabelCoverageTest` — every key has `permissions.modules.<module>` and `permissions.actions.<action>` in each of `apps/web/src/locales/{en,fr,ar}/common.json`; today 119/304 module labels and 125/304 action labels are missing in all three locales identically (I-12, `docs/superpowers/audits/2026-09-09-roles-permissions/03-frontend-pos-consumption.md:440-446`) | wave 1 |
| (e) | `PermissionEnumManifestParityTest` (PHP: every enum case ⊆ manifest, every manifest key has an enum case) + `permissions.generated.test.ts` byte-identity check on the generated TS union | wave 1 |
| (f) | the five tests above run in `scripts/preflight.sh` and in CI; the generated-artifact drift guard already exists for the FE map (`scripts/preflight.sh:136-149`) and is extended, not replaced | wave 1 |

**What the contract deliberately does NOT promise.** It does not promise the permission is *enforced* — a declared permission with no route gate is inert, and that is what §4.4's ratchet is for. It does not promise a **custom** role receives it (B4/D2: never). It does not promise an *existing* tenant's already-customised system role receives it (D2: skipped and reported). And it does not promise anything about a tenant whose deploy has not yet run — sync is a deploy-time step, so the window between merging a module and the next `tenants:migrate` is a real window in which the permission exists in code and not in the database; §4.4's `can:` gate on a not-yet-created permission yields **403, not 500** (Spatie 6.25 `checkPermissionTo` swallows `PermissionDoesNotExist` — verified at `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:187`), so the failure mode is a closed door, never an error page.
---

## 4. Design

Layer placement follows `.claude/context/architecture.md` ("Module Directory Structure", "Cross-Module Communication"): a **declaration** with no infrastructure dependency is Domain; the **aggregator** other modules consume across the boundary is a `Shared/Contracts` interface with a `Shared/Domain` implementation; the **sync orchestration** is an Application service of the module that owns identity; the **command** is a console entry point; **middleware** is Presentation.

### 4.1 Principals and tokens

#### 4.1.1 `users.principal_kind`

One table, two kinds (convention 11 — a second `service_accounts` table would be a duplicate surface for the same concept).

```php
// apps/api/app/Modules/Identity/Domain/Enums/PrincipalKind.php
enum PrincipalKind: string
{
    case Human = 'human';
    case Service = 'service';

    public function canLogIn(): bool { return $this === self::Human; }
    public function canHoldPosPin(): bool { return $this === self::Human; }
    public function canBeImpersonated(): bool { return $this === self::Human; }
}
```

Column contract in §5. `User` gains `principal_kind` to `casts()` (beside the existing casts at `apps/api/app/Modules/Identity/Domain/User.php:115`) and three predicates:

```php
public function principalKind(): PrincipalKind;
public function isServicePrincipal(): bool;   // principal_kind === PrincipalKind::Service
public function isHumanPrincipal(): bool;     // principal_kind === PrincipalKind::Human
```

**Invariants enforced at the model and the database (§5).** A `service` principal has `password` set to an unusable sentinel that no hash can match, `pos_pin IS NULL`, `email_verified_at IS NULL`, and `status` restricted to `active|inactive`. `AuthController::login` refuses a `service` principal with `SERVICE_PRINCIPAL_CANNOT_LOG_IN` (401) before any hash comparison. `UserController::setPosPin` (`apps/api/app/Modules/Identity/routes.php:75`) refuses it with 422. Impersonation refuses it (`SupportAccess`), because impersonating a machine has no consent semantics.

**What a service account *is* like a user:** it has `user_company_memberships` rows (so `X-Company-Id` resolution, `allowed_location_ids` and every existing company/location guard apply unchanged), it is assigned Spatie roles through the same `POST /api/v1/users/{userId}/roles` mechanism (`apps/api/app/Modules/Identity/routes.php:79`), and it appears in `GET /api/v1/users` behind a `principal_kind` filter. Nothing in the authorization path needs a service-specific branch — that is the point.

#### 4.1.2 Managing service accounts

Six new permissions, all declared by the Identity manifest:

| Key | Gates |
|---|---|
| `service-accounts.view` | `GET /api/v1/service-accounts`, `GET /api/v1/service-accounts/{id}` |
| `service-accounts.create` | `POST /api/v1/service-accounts` |
| `service-accounts.update` | `PATCH /api/v1/service-accounts/{id}` (name, description, active) |
| `service-accounts.delete` | `DELETE /api/v1/service-accounts/{id}` (soft: status → inactive, tokens revoked) |
| `service-accounts.issue-token` | `POST /api/v1/service-accounts/{id}/tokens` |
| `service-accounts.revoke-token` | `DELETE /api/v1/service-accounts/{id}/tokens/{tokenId}` |

Template defaults: **`admin` only**. `general_manager` and `manager` receive none — issuing a machine credential is an administrative act, and B4/D2 says a new permission never lands in a non-admin template without a deliberate declaration. Routes live in a new `apps/api/app/Modules/Identity/routes.php` group with the standard middleware stack `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` (the pattern at `apps/api/app/Modules/Identity/routes.php:57`) plus `can:` per route (§4.4).

A service account **cannot** be assigned a role the actor does not hold — the existing `AssignableRole` escalation guard applies unchanged, and §4.6's last-admin floor treats a service principal as *not* counting toward the floor (open question OQ-1, §9.2, default: does not count).

#### 4.1.3 Token scope — narrowing only

The mechanism already exists in the tree and is currently reachable only for impersonation. `User::hasPermissionTo()` and `User::getAllPermissions()` intersect the principal's grants with the token's `permission:<key>` abilities, but only when an `impersonation:` ability is also present (`apps/api/app/Modules/Identity/Domain/User.php:216-241`, the `$isImpersonating` early return at `:225-234`). This design **generalises that gate**: the intersection applies whenever the token carries **at least one `permission:` ability**, regardless of impersonation.

```php
// apps/api/app/Modules/Identity/Domain/User.php — replaces impersonationPermissionNames()
/**
 * The token's permission narrowing, or null when the token does not narrow.
 *
 * Returns null  -> token is unscoped; the principal's full grants apply.
 * Returns list  -> effective = principal grants ∩ this list (never wider).
 * Returns []    -> a token that narrows to nothing; every check denies.
 *
 * @return list<string>|null
 */
private function tokenScopePermissionNames(): ?array
```

Rules:

1. **Narrowing only, never widening.** The list is intersected with the database grants — a `permission:` ability naming a key the principal does not hold grants nothing. This is already how the impersonation path behaves (`:185-198`: the scope check runs *and then* `hasPermissionToWithoutImpersonationFilter` still runs). Sanctum's own `tokenCan()` is deliberately **not** the enforcement point. `HasApiTokens` adds `tokenCan()`/`tokenCant()` and never overrides `can()`, so a token's abilities do not reduce what `$user->can()` returns; Laravel documents the consequence by telling you to check both dimensions yourself ([First-Party UI Initiated Requests](https://laravel.com/docs/12.x/sanctum#first-party-ui-initiated-requests), canonical example `$request->user()->id === $server->user_id && $request->user()->tokenCan('server:update')`), and by noting that `tokenCan` "will always return `true`" on a first-party SPA session request. Wiring the narrowing into `User::hasPermissionTo()` instead makes it apply to **every** `can:` gate, policy and `Gate::authorize` call in the codebase at once — 608 route middleware strings, 202 `->can()` calls and 107 `Gate::authorize()` calls (`docs/superpowers/audits/2026-09-09-roles-permissions/01-backend-permission-model.md:78-90`) — with no per-call-site edit. Adding `tokenCan()` checks to those call sites instead would be 900+ edits and a permanently incomplete guard.
2. **Empty list denies.** A token with `["tenant:<uuid>", "permission:"]`-shaped junk that yields an empty list denies everything rather than falling open. (Today the same code returns `[]` and denies — behaviour preserved.)
3. **`*` is not honoured.** Sanctum's wildcard ability is meaningful to `tokenCan()`, not to this intersection; a token that wants full grants simply carries no `permission:` ability. `EnforceTokenScope` (below) rejects a token carrying both `*` and a `permission:` ability with `TOKEN_SCOPE_AMBIGUOUS` (401) rather than silently picking one.
4. **Unknown keys are dropped, and the drop is visible.** A `permission:` ability naming a key absent from the registry (a permission deprecated after the token was minted) is ignored for the intersection and surfaced in `token_scope.unknown` on `/auth/me` so an operator can see the token is stale.
5. **Impersonation stays exactly as it is.** An impersonation token carries both `impersonation:` and `permission:` abilities; under the generalised rule the same intersection happens, and `getUnfilteredPermissionsForSupportAccess()` (`apps/api/app/Modules/Identity/Domain/User.php:176-179`) keeps its meaning — the support-session intersection starts from live database grants, not from the minted list.

#### 4.1.4 Enforcement point and ordering

New middleware `App\Modules\Identity\Presentation\Middleware\EnforceTokenScope`, registered **immediately after** `EnforceTokenTenantClaim` in the priority list (`apps/api/bootstrap/app.php:182-185` is the insertion site; the new call becomes `appendToPriorityList(after: EnforceTokenTenantClaim::class, append: EnforceTokenScope::class)` and the existing `ImpersonationContext` append re-anchors to `EnforceTokenScope`).

```php
final class EnforceTokenScope
{
    public function handle(Request $request, Closure $next): Response;
}
```

It does **not** authorize — `can:` still does that. It performs four cheap, fail-closed checks and nothing else:

| Check | Failure |
|---|---|
| token carries both `*` and a `permission:` ability | 401 `TOKEN_SCOPE_AMBIGUOUS` |
| the principal is `PrincipalKind::Service` and the token carries no `tenant:` claim | 401 `SERVICE_TOKEN_MISSING_TENANT_CLAIM` (no grandfathering — service tokens are all new; the human grandfathering at `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php:90-92` is untouched) |
| the principal is `service` and its `status` is not `active` | 401 `PRINCIPAL_INACTIVE` |
| the principal is `service` and holds no active membership in the resolved company | 403 `SERVICE_PRINCIPAL_NO_MEMBERSHIP` |

Placing it after `EnforceTokenTenantClaim` means the tenant is already bound and the permissions team is already set (`apps/api/bootstrap/app.php:178-181`), so a membership read is on the right database. Placing it *before* `ImpersonationContext` means an impersonation session is still evaluated with the scope already validated.

**`X-Company-Id` for service accounts.** A service token has no session and no company switcher, so `CompanyContextMiddleware` (`apps/api/bootstrap/app.php:148`) must resolve the company from the `X-Company-Id` request header. Rules: the header is **required** for a service principal on any route that needs a company (absence → 400 `COMPANY_CONTEXT_REQUIRED`); the value must name a company in which the principal holds an **active** membership (otherwise 403 `SERVICE_PRINCIPAL_NO_MEMBERSHIP`); for a human principal the header keeps its current, optional behaviour. A service account is never given a "default company" — an ambiguous machine call is a bug, and a required header makes it a loud one.

#### 4.1.5 Audit attribution and revocation

Every audit event written on behalf of a request carries, in `audit_events.metadata` (`apps/api/app/Modules/Compliance/Domain/AuditEvent.php:55`), two new keys: `principal_id` (the acting `users.id`, which for a service call is the service account) and `token_id` (`personal_access_tokens.id`, or `null` for a session request). This is additive to the existing impersonation columns (`impersonator_id`, `impersonation_session_id`, `:58-60`) — an impersonated write already records the real actor, and a service write now records the real credential.

**Revocation.** Three triggers, all of which delete the principal's tokens:

1. **Membership removed** — when the principal's last active `user_company_memberships` row for a company is removed, every token of that principal whose scope can only be exercised in that company is revoked. Because tokens are not company-bound, the conservative rule is: removing the principal's **last active membership in the tenant** revokes **all** its tokens. A membership removal that leaves at least one active membership revokes nothing (the `X-Company-Id` check in §4.1.4 already denies the removed company).
2. **Principal deactivated or deleted** — all tokens revoked.
3. **Explicit revoke** — `DELETE /api/v1/service-accounts/{id}/tokens/{tokenId}` under `service-accounts.revoke-token`.

Revocation is a hard delete of the `personal_access_tokens` row (the model is central-connection pinned, `.claude/context/architecture.md`), and emits `ApiTokenRevoked` into the audit chain.

#### 4.1.6 What MCP calls

An MCP server acting for a tenant is an ordinary API client holding a service-account token. It needs exactly one discovery call, and the shape of `/auth/me` (`apps/api/app/Modules/Identity/routes.php:41`, `AuthController::me`, `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:616-628`) is **unchanged** — `AuthUserData` keeps `roles` and `permissions` where they are (`apps/api/app/Modules/Identity/Application/DTOs/AuthUserData.php:27-28`), and `permissions` continues to be the *effective* list, i.e. already intersected with the token scope by `User::getAllPermissions()` (§4.1.3). One field is **added**:

```php
// AuthUserData — additive; existing consumers ignore it
public ?TokenScopeData $token_scope,   // null when the token does not narrow

final readonly class TokenScopeData
{
    /** @param list<string> $granted @param list<string> $unknown */
    public function __construct(
        public string $token_id,
        public string $token_name,
        public array $granted,          // permission keys the scope names AND the principal holds
        public array $unknown,          // permission keys the scope names that are not in the registry
        public ?string $expires_at,
    ) {}
}
```

So an MCP server reads `/auth/me` once, sees exactly what it may do, and gets a 403 from `can:` if it tries anything else. The owner's requirement — *"every user that has an MCP should also have their permissions reflected"* — is satisfied structurally rather than by a parallel mechanism: the MCP's principal is a real principal with real roles, its effective set is the principal's grants narrowed by the token, and both ids land in the audit row. There is no MCP-specific permission, no MCP-specific role and no MCP-specific bypass.
### 4.2 Catalogue as code

#### 4.2.1 The value object

```php
// apps/api/app/Shared/Domain/Authorization/PermissionDefinition.php
final readonly class PermissionDefinition
{
    /**
     * @param  non-empty-string       $key           canonical permission key, e.g. 'purchase-orders.approve'
     * @param  non-empty-string       $module        owning module, e.g. 'Procurement' — drives UI grouping and the
     *                                               module gate, and is NOT derived from the key prefix
     * @param  non-empty-string       $resource      kebab-case resource, e.g. 'purchase-orders'
     * @param  PermissionVerb         $verb
     * @param  ?non-empty-string      $qualifier     snake_case narrowing of the verb, e.g. 'above_threshold'
     * @param  non-empty-string       $labelKey      i18n key stem, always 'permissions.actions.<action>'
     * @param  non-empty-string       $descriptionKey
     * @param  list<non-empty-string> $templateDefaults  system-role template keys that receive this by default
     *                                                   ('admin' is implicit and MUST NOT be listed)
     * @param  ?non-empty-string      $sodGroup      separation-of-duties group, §4.7
     * @param  non-empty-string       $introducedIn  release tag, e.g. '2026.09'
     * @param  ?non-empty-string      $replacedBy    successor key when $deprecated is true
     */
    private function __construct(
        public string $key,
        public string $module,
        public string $resource,
        public PermissionVerb $verb,
        public ?string $qualifier,
        public string $labelKey,
        public string $descriptionKey,
        public array $templateDefaults,
        public ?string $sodGroup,
        public string $introducedIn,
        public bool $deprecated,
        public ?string $replacedBy,
        public bool $legacyAction,
    ) {}

    /** Canonical constructor: the key is DERIVED, never typed by hand. */
    public static function make(
        string $module,
        string $resource,
        PermissionVerb $verb,
        ?string $qualifier = null,
        array $templateDefaults = [],
        ?string $sodGroup = null,
        string $introducedIn = '2026.09',
    ): self;

    /**
     * Escape hatch for the pre-existing keys that do not decompose into
     * verb[_qualifier] (e.g. 'batches.traceability', 'pricing.sell_below_cost',
     * 'reports.operational'). Every call is counted against a shrink-only
     * baseline (§4.5, LEGACY_ACTION_CEILING) — new modules may not use it.
     */
    public static function legacy(
        string $key,
        string $module,
        array $templateDefaults = [],
        ?string $sodGroup = null,
        string $introducedIn = '2026.09',
    ): self;

    public static function deprecate(self $definition, ?string $replacedBy): self;

    public function action(): string;   // verb + optional '_' . qualifier, or the legacy suffix
}
```

`make()` derives `key = "{$resource}.{$verb->value}" . ($qualifier !== null ? "_{$qualifier}" : '')` and throws `InvalidPermissionKey` when `$resource` is not `^[a-z0-9]+(-[a-z0-9]+)*(\.[a-z0-9]+(-[a-z0-9]+)*)?$` (the second dot segment allows the two legitimate nested resources, `catalog.attributes` and `inventory.transfers`) or when `$qualifier` is not `^[a-z0-9]+(_[a-z0-9]+)*$`. **The key can never disagree with its parts**, which is the whole reason the constructor is private.

`$module` is deliberately independent of the key prefix. Today the UI groups by `explode('.', $name)[0]` on both sides (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:320-324`; `apps/web/src/features/settings/RolesPage.tsx:37-42`), which puts `catalog.attributes.view` under a group called `catalog` and `inventory.transfers.close` under `inventory` — grouping by declared module fixes both without renaming either key.

#### 4.2.2 The closed verb enum

```php
// apps/api/app/Shared/Domain/Authorization/PermissionVerb.php
enum PermissionVerb: string
{
    // read / write lifecycle
    case View = 'view';           case Create = 'create';
    case Update = 'update';       case Delete = 'delete';
    case Restore = 'restore';
    // document lifecycle
    case Post = 'post';           case Cancel = 'cancel';
    case Reverse = 'reverse';     case Void = 'void';
    case Correct = 'correct';     case Complete = 'complete';
    // workflow
    case Request = 'request';     case Submit = 'submit';
    case Approve = 'approve';     case Reject = 'reject';
    case Confirm = 'confirm';     case Convert = 'convert';
    // operations
    case Receive = 'receive';     case Transfer = 'transfer';
    case Adjust = 'adjust';       case Reconcile = 'reconcile';
    case Allocate = 'allocate';   case Refund = 'refund';
    // exceptions and IO
    case Override = 'override';   case Print = 'print';
    case Export = 'export';       case Import = 'import';
    // administration
    case Assign = 'assign';       case Operate = 'operate';
    case Manage = 'manage';

    /** Verbs that mutate; drives the write half of the §4.4 ratchet and the §4.6 denial audit. */
    public function isWrite(): bool;
    /** Verbs that make a financial fact real; drives the §4.7 SoD rule. */
    public function isFinanciallyDecisive(): bool;  // post, reverse, void, approve, refund, allocate
}
```

Thirty cases. The list is derived from what the catalogue already uses (`view` 59, `create` 34, `update` 27, `delete` 23, `manage` 22, `post` 6, `cancel` 6, then a long tail — measured at `971528977`) plus the verbs the accepted T-2/T-3 spec needs (`inventory.transfers.reconcile`, `inventory.transfers.close` → `Complete`/`Reconcile`; see §4.2.5).

**The `manage` rule.** `Manage` is legal on a resource **only if that resource declares no `Create`, `Update` or `Delete` permission anywhere in the registry**. It means "this resource is administered as a whole" (`settings.manage`, `roles.manage`, `imports.manage`), never "and also CRUD". Enforced by `PermissionRegistryConsistencyTest::manage_is_never_mixed_with_crud_on_one_resource`, which is a hard failure — not a ratchet — because the registry is authored fresh in wave 1 and no existing resource violates it (verified: no resource in the 304 carries both `manage` and any of create/update/delete).

**`legacy()` and its ratchet.** 150-odd of the 304 keys have compound action suffixes that do not decompose (`pos.approve_discount_limit_override` does: verb `approve`, qualifier `discount_limit_override`; `batches.traceability` and `pricing.sell_below_cost` do not). Those use `legacy()`, are listed in `apps/api/tests/Architecture/baselines/permission-legacy-action-baseline.json`, and are counted against `LEGACY_ACTION_CEILING` in `PermissionRegistryConsistencyTest` — shrink-only, exactly like `LEGACY_ENTRY_CEILING` in `TenantOnlyUniqueOnCatalogueTablesRatchetTest` (`docs/conventions/09-SECOND-OF-EVERYTHING.md:66-72`). A **new** module calling `legacy()` fails the test regardless of the ceiling, because the baseline is keyed by permission key and a key absent from it is growth. This is what lets wave 1 land without renaming 150 keys and still close the door behind itself.

#### 4.2.3 The manifest interface and one worked example

```php
// apps/api/app/Shared/Contracts/Authorization/PermissionManifest.php  (cross-module contract)
interface PermissionManifest
{
    /** The module name every definition below must declare. */
    public function module(): string;

    /** @return list<PermissionDefinition> */
    public function definitions(): array;
}
```

```php
// apps/api/app/Modules/Procurement/Domain/Authorization/ProcurementPermissionManifest.php
final class ProcurementPermissionManifest implements PermissionManifest
{
    public function module(): string { return 'Procurement'; }

    public function definitions(): array
    {
        return [
            PermissionDefinition::make('Procurement', 'purchase-orders', PermissionVerb::View,
                templateDefaults: ['manager', 'general_manager', 'accountant']),
            PermissionDefinition::make('Procurement', 'purchase-orders', PermissionVerb::Create,
                templateDefaults: ['manager', 'general_manager'], sodGroup: 'purchase-order'),
            PermissionDefinition::make('Procurement', 'purchase-orders', PermissionVerb::Approve,
                templateDefaults: ['general_manager'], sodGroup: 'purchase-order'),
            // …
        ];
    }
}
```

The manifest lives in **Domain** because it is a pure declaration with no infrastructure dependency (`.claude/context/architecture.md`, "Domain layer has ZERO dependencies on infrastructure"). Other modules never import it — they read the registry through the `Shared/Contracts` interface, which is the only sanctioned cross-module channel.

#### 4.2.4 The registry

```php
// apps/api/app/Shared/Domain/Authorization/PermissionRegistry.php
final class PermissionRegistry
{
    /** @param list<PermissionManifest> $manifests */
    public function __construct(private readonly array $manifests) {}

    /** @return list<PermissionDefinition> ordered by module, then resource, then verb — deterministic. */
    public function definitions(): array;
    /** @return list<non-empty-string> */
    public function keys(): array;                       // includes deprecated keys
    /** @return list<non-empty-string> */
    public function activeKeys(): array;                 // excludes deprecated
    public function get(string $key): ?PermissionDefinition;
    /** @return array<non-empty-string, list<non-empty-string>> module => keys */
    public function byModule(): array;
    /** @return array<non-empty-string, list<non-empty-string>> templateKey => keys */
    public function templateGrants(): array;             // 'admin' => activeKeys(), others from templateDefaults
    /** @return array<non-empty-string, list<non-empty-string>> sodGroup => keys */
    public function sodGroups(): array;
    public function validate(): void;                    // throws InvalidPermissionCatalogue on any §4.2.2 violation
}
```

Registered as a container **singleton** in `AppServiceProvider::register()`, built from an ordered manifest list. Each module's existing service provider (`apps/api/bootstrap/providers.php` lists 40+) contributes its manifest by binding a tagged instance:

```php
// in each module's ServiceProvider::register()
$this->app->tag([ProcurementPermissionManifest::class], 'permission.manifests');
```

and the registry resolves `$app->tagged('permission.manifests')`. Tagging is chosen over a hard-coded array so that adding a module is one line **in that module**, which is the automatic-inclusion guarantee (§3.3) at the wiring level. `PermissionRegistryCoverageTest` asserts that every directory under `app/Modules/` that owns at least one permission key in the baseline has a tagged manifest — so deleting the tag line fails CI rather than silently dropping a module's permissions.

`validate()` is called once in `AppServiceProvider::boot()` **only when `app()->runningUnitTests() || app()->runningInConsole()`** — a malformed catalogue must fail the build and the deploy, not add a per-request cost to production traffic.

#### 4.2.5 Per-module enums for call sites

Each module ships a string-backed enum whose cases are exactly its manifest keys:

```php
// apps/api/app/Modules/Inventory/Domain/Enums/InventoryPermission.php
enum InventoryPermission: string
{
    case TransfersView      = 'inventory.transfers.view';
    case TransfersCreate    = 'inventory.transfers.create';
    case TransfersComplete  = 'inventory.transfers.complete';
    case TransfersCancel    = 'inventory.transfers.cancel';
    case TransfersReconcile = 'inventory.transfers.reconcile';
    case TransfersClose     = 'inventory.transfers.close';
    // …
}
```

`PermissionEnumManifestParityTest` asserts, for every module that has an enum, **set equality** between the enum's `::cases()` values and that module's manifest keys — in both directions, so an enum case with no definition and a definition with no enum case both fail. Call sites move from `Gate::authorize('inventory.transfers.reconcile')` to `Gate::authorize(InventoryPermission::TransfersReconcile->value)`; route strings become `'can:'.InventoryPermission::TransfersReconcile->value`. The PHPStan rule in §4.5 makes the literal form an error outside manifests, enums and tests, so the migration cannot regress.

**The accepted T-2/T-3 gate is representable, which is the acceptance test for this design.** `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:398-420` requires: `inventory.transfers.reconcile` (visibility) and `inventory.transfers.close` (write authority) as **two independently grantable permissions**, both defaulting to `manager` + `admin`, with `POST /stock-transfers/{id}/close` gated on **both**, and the read routes gated on an any-of over three permissions. In the registry:

- two `PermissionDefinition::make('Inventory', 'inventory.transfers', PermissionVerb::Reconcile|Close, templateDefaults: ['manager', 'general_manager'])` entries — `admin` is implicit, so no template lists it;
- the two-permission AND gate is expressed on the route as `->middleware(['can:'.$reconcile, 'can:'.$close])` — stacked `can:` middleware is an AND by construction, needing no new middleware;
- the three-permission OR gate is the existing `require.any.permission` alias (`apps/api/bootstrap/app.php:120` → `apps/api/app/Http/Middleware/RequireAnyPermission.php:14-19`), unchanged.

So the catalogue expresses the accepted spec's gate without a single new primitive. Note one defect this design also fixes: `RequireAnyPermission`'s 403 body hard-codes the message *"You do not have permission to view transaction destinations."* (`apps/api/app/Http/Middleware/RequireAnyPermission.php:25`) — a copy-paste from its first caller that is now wrong on every other route. Wave 0a replaces it with a generic message plus the failing permission list in `error.details`.
### 4.3 Sync — `permissions:sync`

#### 4.3.0 The contract this must be a superset of

Lane `lane/w-lot-a-1a` (worktree `apps/erp/.worktrees/w-lot-a-1a`, plan `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md`) is in flight and lands **before** this lane (D7). Its work is **absorbed, never undone**. The five facts this design binds itself to:

1. **`roles.provisioning_source`** — `VARCHAR(32)`, nullable, default `NULL`, no FK, plus a partial unique index `roles_provisioning_source_team_unique ON roles (tenant_id, provisioning_source) WHERE provisioning_source IS NOT NULL` and a CHECK restricting any non-null value to `('w-lot-a-1a', name = 'general_manager', guard_name = 'sanctum', tenant_id IS NOT NULL)`, with equivalent SQLite triggers (plan `:1176-1217`). Migration `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`.
2. **`markedTenantCarriesWlota1aDelta($tenantId)`** is checked immediately before the destructive `syncPermissions()` at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:568`; a marked tenant is left completely untouched (plan `:1496,1503-1507`).
3. **Legacy roles carry `tenant_id NULL` and are never re-homed** (`docs/handoff/CODEX-PROMPT-WLOTA-1a-ruling-legacy-null-team-roles-2026-09-09.md`). Every role read resolves `name = ? AND guard_name = 'sanctum' AND (tenant_id IS NULL OR tenant_id = :tenantId)` and **preserves `tenant_id` as found**; two matching rows is a collision, never an adoption.
4. **`LotActionPermissionDelta`** (`apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php`) is that lane's sole role-definition writer, with a `setPermissionsTeamId` / `finally`-restore boundary around a `DB::transaction` holding a transaction-scoped PG advisory lock (plan `:1382-1407`), and ten guard rails including "no force option exists" (plan `:1409-1420`).
5. The lane adds **exactly two** permission keys, `batches.recall.request` and `treasury.manage_all_locations` (plan `:1424-1429`), and one role, `general_manager` (`SystemRoleName::GeneralManager`, plan `:1241-1244`).

**How this design is a superset, clause by clause.**

| W-LOT-A-1a asset | What `permissions:sync` does with it |
|---|---|
| `roles.provisioning_source` + its CHECK and index | **Never written, never cleared, never widened.** The CHECK stays exactly as installed; sync writes `template_key`/`template_version`/`is_system`/`customised_at` only (§5), which the CHECK does not constrain. `RoleProvisioningSource` keeps its single case. |
| `markedTenantCarriesWlota1aDelta()` | Kept and **generalised, not replaced**: `PermissionSyncService` calls it as a precondition and, for a marked tenant, treats `general_manager` as already-provisioned — it never re-creates it, never re-grants `batches.recall`, and never restores `manager`'s recall grant. |
| NULL-team legacy roles | The `(tenant_id IS NULL OR tenant_id = :tenantId)` predicate and the "preserve `tenant_id` as found" rule are lifted verbatim into `PermissionSyncService::resolveRole()`. Two matching rows → `RoleCollision`, transaction rolled back, non-zero exit. **No re-homing anywhere in this design.** |
| `LotActionPermissionDelta` | Kept as-is through wave 1. Wave 3 retires it **only** once `permissions:sync` has run against every tenant and `LotActionPermissionDeltaTest` is re-pointed at the sync service; until then both exist and `permissions:sync` is a no-op for the two keys the delta already applied (idempotent by construction — both use `firstOrCreate` semantics on the same keys). |
| its two permission keys and `general_manager` | Declared in the `BatchExpiry` and `Treasury` manifests (`batches.recall.request`, `treasury.manage_all_locations`) and in the `general_manager` template with **that lane's exact grant set** (plan `:1431-1444`). §4.9 lists this as a re-homing of the declaration only — no key, role or grant changes value. |

Two collision hazards are recorded here so a gate can check them: (i) the new `roles` columns must be added by a migration **timestamped after** `2026_09_06_205000`, and (ii) neither the new partial unique on `template_key` nor the `is_system` backfill may write `tenant_id`, or the W-LOT CHECK's `tenant_id IS NOT NULL` clause interacts with a NULL-team legacy role.

#### 4.3.1 Command

```php
// apps/api/app/Console/Commands/SyncPermissions.php
final class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync
        {--tenant= : Sync one tenant by id or slug; omit to sync the current tenant context}
        {--dry-run : Compute and report the plan; write nothing}';

    protected $description = 'Synchronise the code-declared permission catalogue into a tenant database.';

    public function __construct(
        private readonly PermissionSyncService $sync,
        private readonly PermissionRegistry $registry,
    ) { parent::__construct(); }

    public function handle(): int;
}
```

Exit codes: `0` applied or already-current, `1` failed (nothing written — the whole run is one transaction), `2` blocked (collision, or compatibility mode with `--tenant`). One marker line per run, on the command output **and** the durable stderr channel, mirroring the shape W-LOT-A-1a established (plan `:1486-1492,1568-1570`):

```
PERMISSIONS-SYNC tenant=<uuid> mode=APPLY|DRY_RUN outcome=APPLIED|ALREADY_CURRENT|BLOCKED|FAILED
                 created=<n> renamed=<n> deprecated=<n> admin_added=<n> templates_applied=<n>
                 templates_skipped_customised=<n> reason=<token>
```

#### 4.3.2 Service

```php
// apps/api/app/Modules/Identity/Application/Services/PermissionSyncService.php
final readonly class PermissionSyncService
{
    public function __construct(
        private PermissionRegistry $registry,
        private PermissionRegistrar $permissionRegistrar,   // Spatie
        private PermissionRenameMap $renameMap,
        private Clock $clock,
    ) {}

    public function sync(string $tenantId, bool $write): PermissionSyncResult;
}
```

Constructor injection only, `private readonly` throughout (rule 13). The team boundary is W-LOT-A-1a's, verbatim in shape:

```php
$previousTeamId = $this->permissionRegistrar->getPermissionsTeamId();
$this->permissionRegistrar->setPermissionsTeamId($tenantId);
try {
    return DB::transaction(function () use ($tenantId, $write): PermissionSyncResult {
        $this->acquireTenantLock($tenantId);   // transaction-scoped PG advisory lock
        // steps 1..7 below
    });
} finally {
    $this->permissionRegistrar->setPermissionsTeamId($previousTeamId);
}
```

**The seven steps, in this order.** Every step is idempotent; running the command twice in a row produces `ALREADY_CURRENT` with all counters zero.

1. **Rename.** For each `(from, to)` in the rename map where a `permissions` row named `from` exists and none named `to` exists: `UPDATE permissions SET name = :to WHERE name = :from AND guard_name = 'sanctum'`. **In place** — the primary key never changes, so every `role_has_permissions` and `model_has_permissions` row survives untouched and no grant is lost. If both `from` and `to` exist, the run is `BLOCKED` with `reason=rename_target_exists` (a human must decide which grants win); if neither exists the entry is a no-op and is reported as stale so the map can be trimmed.
2. **Create.** `Permission::firstOrCreate(['name' => $key, 'guard_name' => 'sanctum'])` for every `registry->keys()`, deprecated keys included — a deprecated permission keeps its row for one release so a custom role referencing it does not break (§4.9).
3. **Deprecate.** For every deprecated definition: the row stays, and the key is removed from **every system-role template** (step 5) but **never** revoked from a custom role. `replacedBy`, when set, is granted to every role currently holding the deprecated key — so a rename-by-deprecation preserves capability.
4. **Orphans.** A `permissions` row present in the database and absent from the registry is **reported, never deleted**. Deleting it would cascade its grants away, and a row can legitimately be a leftover from a tenant that has not yet received a deploy that removed a module. The count appears in the marker as part of `reason` and in the `--dry-run` table; wave 3 adds `permissions:prune-orphans --confirm` as a separate, deliberate action.
5. **Templates.** For each system-role template in `registry->templateGrants()`:
   - resolve the role with the NULL-team-tolerant predicate (§4.3.0 clause 3); if it does not exist and the template is `general_manager` on a marked tenant, skip (W-LOT already created it); if it does not exist otherwise, create it with `tenant_id = :tenantId`, `is_system = true`, `template_key`, `template_version = <registry version>`;
   - **`admin` is special**: it is set to exactly `registry->activeKeys()` with `syncPermissions()`. This is the one place a full replace is correct, and it is what replaces `Permission::all()` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:568`, I-19) — after this, the catalogue is once again the boundary of what `admin` holds, and a stray row inserted out of band is no longer silently attached;
   - **every other system role is additive-only**: `givePermissionTo()` for keys the template declares and the role lacks; **no revocation ever**, except the single explicit revocation W-LOT-A-1a already owns (`manager` losing `batches.recall`), which stays that lane's, not sync's;
   - **customised roles are skipped**: if `roles.customised_at IS NOT NULL`, the role receives nothing and is counted in `templates_skipped_customised`. Its `template_version` is left at the value it had, which is what lets the Roles UI say "this role is 3 versions behind" (§4.6);
   - on success the role's `template_version` is set to the registry version.
6. **Custom roles** (`is_system = false`) are read for the orphan report and **never written**. This is B4 and D2 in one line.
7. **Cache.** `$this->permissionRegistrar->forgetCachedPermissions()` inside the transaction's `finally`, i.e. after commit, and — because the cache key is per tenant after S-1 (§4.8) — that flush affects only this tenant.

`--dry-run` runs steps 1–6 with `$write = false` inside a transaction that is rolled back, and prints the plan as a table. It is the only supported way to preview a deploy's authorization delta.

#### 4.3.3 Where it runs

**Replacing the destructive boot reseed.** `apps/api/docker/entrypoint.sh:162-170` currently runs `tenants:seed --class='Database\Seeders\RolesAndPermissionsSeeder'` across every tenant whenever `SYNC_PERMISSIONS_ON_BOOT=true` — which staging sets, so every push silently reverts every tenant's customisation of the seven seeded roles (I-20, verified `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:188`). That block is replaced by:

```sh
if [ "$SYNC_PERMISSIONS_ON_BOOT" = "true" ]; then
  DB_HOST="$DIRECT_DB_HOST" php artisan tenants:run permissions:sync --force
fi
```

The environment variable name is **kept** — W-LOT-A-1a's §11 evidence protocol asserts its value on staging in six places (plan `:2643,3043,3164,3755,4112`) and its deployment-variable table row at `:2367`; renaming it would invalidate that lane's evidence. What changes is only what the flag runs. The flag's default stays `false` in production until wave 1's staging soak passes (§8).

**In `tenants:migrate`.** The durable home is a listener on Stancl's tenant-migrated event so that a **newly provisioned** tenant and a **rolling-migrated** existing tenant both receive the sync in the same step that gave them their schema (`.claude/context/architecture.md`, "Migration topology" and "Lifecycle"). `apps/api/docker/entrypoint.sh:150` already runs `tenants:migrate-rolling --force` immediately before the block above, so on a normal deploy each tenant is migrated and then synced, in order, per tenant, with per-tenant failure isolation — the property the existing script comments already claim at `:145-147`.

**Provisioning.** `TenantInitializationService` keeps calling `RolesAndPermissionsSeeder` (it is invoked when roles are absent, per W-LOT-A-1a's census at plan `:372`), and the seeder now delegates (§4.3.4). A fresh tenant therefore gets the catalogue through exactly the same code path as an existing one — one write path, per convention 11.

**Compatibility mode.** In single-schema compatibility mode every tenant shares one `permissions` table (S-1's own scoping rationale, `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:66`). Steps 1–4 (rename, create, deprecate, orphan report) are tenant-independent and safe; steps 5–7 are not, because the `roles` rows are shared. So: with `TENANCY_DB_PER_TENANT=false`, `permissions:sync` **refuses `--tenant`** (exit 2, `reason=compatibility_mode_is_global`) and runs steps 1–4 once globally, reporting `templates_applied=0`. This is stated rather than silently degraded because a silent degrade is exactly how I-20 happened.

#### 4.3.4 `RolesAndPermissionsSeeder` becomes a thin adapter

The class name, namespace and public static signatures are **preserved**, because five call sites depend on the exact FQCN or the exact helpers: the entrypoint `--class` string (`apps/api/docker/entrypoint.sh:165`), two staging `docker exec` heredocs in W-LOT-A-1a's evidence protocol (plan `:2814`), `TenantInitializationService`, the FE map exporter (`apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:29-30`), and W-LOT-A-1a's two mandatory PG preservation tests named after it (`RolesAndPermissionsSeederMarkedTenantTest`, plan `:1931-1932`). W-LOT-A-1a never states a preservation rule for the name; **this spec states it**, so that a later lane cannot rename the class and quietly break the staging protocol.

After wave 1 the class is:

```php
final class RolesAndPermissionsSeeder extends Seeder
{
    public function __construct(
        private readonly PermissionSyncService $sync,
        private readonly PermissionRegistry $registry,
        private readonly TenancyResolver $tenancy,
    ) {}

    public function run(): void;                         // → $this->sync->sync($tenantId, write: true)

    /** @return list<string> */
    public static function permissionNames(): array;     // → app(PermissionRegistry::class)->keys()

    /** @return array<string, list<string>> */
    public static function rolePermissionGrants(): array; // → app(PermissionRegistry::class)->templateGrants()
}
```

The two static helpers are the one sanctioned exception to rule 13's "never use `app()`": they are static, so no constructor exists to inject into, and they exist purely to keep the exporter's and W-LOT-A-1a's contracts intact. They are marked with an explicit `@internal Compatibility shim — call PermissionRegistry directly in new code.` docblock, and the PHPStan rule in §4.5 lists them among the call sites that may reference permission strings. Wave 3 migrates the exporter to the registry directly and deletes both.

The 871-line array (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:48-871`) is deleted in the same commit that adds the manifests, and `PermissionCatalogueParityTest` — a **transitional** test that pins the registry's key set against a frozen snapshot of the 304 pre-existing keys plus the declared additions and minus the declared renames — proves the move loses nothing. That test is deleted at the end of wave 1; it exists only to make the cut-over reviewable.
### 4.4 Enforcement standard

#### 4.4.1 The rule

> **E-1.** Every route in `apps/api/routes/api.php` and every module `routes.php` is exactly one of: **gated** (carries `can:`, `require.any.permission:`, `super_admin`, `central_admin*` or `authz.self` middleware), or **public** (declared in the public allow-list). There is no third state.
>
> **E-2.** `can:` route middleware is the **single action gate**. A controller may add defence-in-depth (`Gate::authorize`, `$user->can`) but may not be the *only* gate.
>
> **E-3.** Policies are for **row-level** decisions only — "may this actor touch *this* record" — and are registered explicitly (`apps/api/app/Providers/AppServiceProvider.php:275-276`; there is no `AuthServiceProvider` and no `Gate::before`). A policy never replaces the action gate.
>
> **E-4.** A FormRequest's `authorize()` **must not be the sole permission check**. Of 262 FormRequests, 180 return `true` unconditionally, and eight of the live ungated write routes are "gated" only by such a FormRequest (`docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.md:77-135`) — a shape that reads as authorized in review and is not. `authorize()` may still carry row-level or field-level logic.

Rationale is G15's cautionary tale: Frappe's `@frappe.whitelist()` authorizes nothing by itself and produced CVE-class findings; the defence OWASP prescribes is centralising the check and testing it as a first-class concern. Route middleware is the only layer a mechanical sweep can see, which is what makes E-2 enforceable rather than aspirational.

#### 4.4.2 `authz.self` — the self-service marker

Some authenticated routes legitimately have no permission because the resource *is* the caller. Today they are indistinguishable from a gap: `POST /api/v1/auth/logout`, `logout-all`, `resend-verification`, `POST /api/v1/notifications/{id}/read`, `read-all`, and `POST /api/v1/support-access/sessions/{session}/exit` all appear in the ungated write list (`docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.md:87-95,101-102,127`).

```php
// apps/api/app/Http/Middleware/AllowSelfService.php — alias 'authz.self'
/**
 * Marks a route as acting only on the authenticated principal's own resources.
 * It is a DECLARATION, not a gate: it asserts authentication and records the
 * route as deliberately permission-free so the §4.4.3 ratchet can distinguish
 * "no permission by design" from "no permission by accident".
 */
final class AllowSelfService
{
    public function handle(Request $request, Closure $next): Response;
}
```

Registered in the alias map beside the existing entries (`apps/api/bootstrap/app.php:114-123`). It rejects an unauthenticated request (401) and, for a `PrincipalKind::Service` principal, rejects `logout`-family routes with 403 `SELF_SERVICE_NOT_APPLICABLE` — a machine has no session to end. It performs no ownership check, because ownership on these routes is structural (`$request->user()`), not a lookup; a self-service route that takes an id in the path and looks a record up is **not** eligible for the marker and must carry a policy instead (E-3). The initial marked set is exactly the six routes above plus `GET /auth/me`; every addition is a reviewed diff on the alias, which is the point.

#### 4.4.3 The route-coverage ratchet

```php
// apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php
// baseline: apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json
```

Mechanism, following the two ratchets already in the tree (`DocumentPerActionBaselineRatchetTest`, `TenantOnlyUniqueOnCatalogueTablesRatchetTest`):

- **Source of truth is the live router**, not a grep: the test boots the application and reads `Route::getRoutes()`, so a route added by any mechanism is seen. (The audit derived the same data from `php artisan route:list --json`, and Opus re-derived it byte-identically — `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:8`.)
- **Classification** per route: gated / public / self-service / **uncovered**. A route is uncovered when its resolved middleware stack contains none of `can:`, `require.any.permission:`, `super_admin`, `central_admin`, `central_admin_role`, `authz.self`, and it is not in the public allow-list.
- **Baseline entry key**: `"{METHODS} {uri}"` — stable across controller refactors and readable in a diff.
- **Three failure directions**, all fail-closed: **growth** (an uncovered route not in the baseline), **stale** (a baseline entry that is now covered — the entry must be deleted in the merge that fixed it, so a fix cannot be forgotten), **anti-growth** (the baseline's key set is compared against a protected blob pinned in CI, exactly as `DocumentPerActionBaselineRatchetTest` does via `DPA_BASELINE_PROTECTED_BLOB`, so a contributor cannot add a violation and its baseline entry in one change).
- **The ratchet measures a stricter thing than the audit's `AUTH_ONLY` count, deliberately.** The audit's 142 are routes with no check at *any* layer. The ratchet counts routes with no *middleware* gate, which is what makes E-2 and E-4 enforceable — a route checked only in a controller or only in a FormRequest is uncovered. Recomputed from `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv` at `971528977`: of 1054 routes, 706 carry a gating middleware (638 `MIDDLEWARE` + 68 `SUPERADMIN_ONLY`) and 18 are public, leaving **326 uncovered — 177 writes and 149 reads** (the audit's prose figure of "178 write routes with no route-level middleware" counts one tombstone this classification puts elsewhere). Those 326 decompose as 142 checked nowhere, 130 checked in a controller, 46 in a FormRequest, 8 by a policy.
- **Two ceilings** in the test file: `UNCOVERED_WRITE_CEILING` and `UNCOVERED_READ_CEILING`, both shrink-only. Wave 0a generates the baseline **after** `authz.self` and the public allow-list are applied (0a-3), which reclassifies four inert 410-tombstone writes, six self-service writes and `GET /api/v1/auth/me` — giving `UNCOVERED_WRITE_CEILING = 167` and `UNCOVERED_READ_CEILING = 148`. The two ceilings are separate so that closing reads can never buy headroom for writes — D3's "writes first". Wave 0a takes the write ceiling to ~149 and wave 0b to ~128; the controller- and FormRequest-checked remainder is wave 3's, which is why the initial ceilings are large and the shrink schedule, not the starting value, is the commitment.
- **New routes may never enter the baseline.** The anti-growth blob makes this mechanical; the reviewer question makes it visible. A new route with no gate fails with: *"route `POST /api/v1/x` has no action gate — add `can:<permission>` (declare it in the owning module's PermissionManifest), or `authz.self` if it acts only on the caller's own resources. New routes cannot be added to the coverage baseline."*

A **liveness fixture** ships with it (convention 08, the pattern of `TenantOnlyUniqueRatchetLivenessTest`): a fixture route registered only under the test's own service provider, asserted to be reported as uncovered — so a refactor that breaks the detector fails instead of going quiet.

#### 4.4.4 Closing the 51 verified ungated writes first

The 51 live write routes with no check at any layer (I-2; 53 flagged, minus the two `uom` false positives Opus found — `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:189`) are the top of the shrink schedule, split by whether the permission they need already exists.

Wave 0a gates only routes whose permission **already exists** in the seeded catalogue, so nothing depends on wave 1: `promotions.destroy/activate/pause/archive` → `promotions.manage` (the same controller's `store`/`update` already check it, so these four are an intra-controller inconsistency, not a policy — `apps/api/app/Modules/Promotion/Presentation/Controllers/PromotionController.php:122,146,170,194`); the `uom` writes → the existing `uom.create`/`uom.edit`/`uom.delete` (two of which are already checked in-controller, `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:189`, so this only moves the check to the route); `batches` delete/recall → the existing `batches.delete`/`batches.recall`; `menus`/`menu-categories` deletes → `menus.manage`; `coupons` destroy/revoke/reactivate → `coupons.manage`; `inventory/countings/.../count` → `inventory.adjust`.

Wave 0b gates the rest, because they need keys that do not exist yet: `services.*` and `service-categories.*` (six routes, today authorized **only** by a dead FE alias map — I-22), `channels.*` (six), `categories.*` (four), `progression.*` (four), `purchase-hub.orders.create`, `POST /companies` (which today self-grants `MembershipRole::Owner` at `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:151` behind a FormRequest returning `true`), and the platform lookup endpoints. Each new key is declared in its module's manifest with `admin`-only template defaults, then widened by an explicit template declaration where the owner rules it should be.

### 4.5 Static and CI guards

#### 4.5.1 PHPStan: no permission literals outside the catalogue

```php
// apps/api/app/PHPStan/Rules/ForbidPermissionStringLiteral.php
// registered in apps/api/phpstan.neon under `rules:` beside the eight existing rules (:33-41)
```

Flags a string literal that matches the permission-key shape `^[a-z][a-z0-9-]*(\.[a-z][a-z0-9_.-]*)+$` **and** is present in `PermissionRegistry::keys()`, when it appears as: an argument to `->can()`, `->hasPermissionTo()`, `Gate::authorize()`, `Gate::allows()`, `Gate::denies()`, or inside a `->middleware([...])` / `middleware('can:…')` string. Matching against the registry (not against the shape alone) is what keeps the rule free of false positives on unrelated dotted strings.

Allowed call sites, encoded in the rule: `app/Modules/*/Domain/Authorization/*PermissionManifest.php`, `app/Modules/*/Domain/Enums/*Permission.php`, `app/Shared/Domain/Authorization/*`, `database/seeders/RolesAndPermissionsSeeder.php` (the compatibility shim, §4.3.4), `app/Console/Commands/SyncPermissions.php`, and everything under `tests/`. Error message names the enum case to use: *"permission literal 'inventory.transfers.reconcile' — use `InventoryPermission::TransfersReconcile->value`."*

A fixture pair under `apps/api/tests/PHPStan/Fixtures/` plus `ForbidPermissionStringLiteralTest` (the shape of `ForbidFixedScaleQuantityLiteralRuleTest`) proves the rule fires and does not over-fire. Because 608 route strings and 309 call sites exist today, the rule ships in wave 1 **with an ignore list** generated from the tree at that moment, shrink-only and reviewed like any baseline; wave 3 empties it.

#### 4.5.2 Label coverage in en/fr/ar

`PermissionRegistryLabelCoverageTest` asserts that for every active key, `permissions.modules.<module>` and `permissions.actions.<action>` exist in each of `apps/web/src/locales/{en,fr,ar}/common.json`. Today those files carry 40 module keys and 35 action keys, identical in all three locales, against 304 permissions — so 119 permissions have no module label and 125 no action label, and the UI silently degrades to the raw key because `translateModule`/`translateAction` pass the raw string as the i18next fallback (`apps/web/src/features/settings/RolesPage.tsx:37-42`).

The test does not merely fail; `php artisan permissions:export-label-skeleton` writes, per locale, a `common.permissions` block containing every missing key with the raw action as the value, so a translator receives a complete file to edit rather than a diff to reconstruct. The generated skeleton is **not** committed as translations — the command writes to `storage/app/permission-labels/<locale>.json` and the test's failure message points at it. English may be seeded from the skeleton mechanically; French and Arabic are human work and are the reason this is a wave-1 deliverable with a named owner, not a side effect.

#### 4.5.3 Generated frontend map

`permissions:export-frontend-map` (`apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:14-43`) keeps its signature, its output path (`apps/web/src/hooks/permissionsMap.generated.ts`), its sha256 header and the preflight drift guard (`scripts/preflight.sh:136-149`) — W-LOT-A-1a's determinism gate asserts byte identity across two generations and the committed artifact (plan `:3285-3306`), and that gate must keep passing. Only its **input** changes, from the seeder's two statics to `PermissionRegistry` (via the shim in §4.3.4 during wave 1, directly in wave 3). It gains one output:

```ts
// apps/web/src/hooks/permissionsMap.generated.ts (appended, generated)
export const PERMISSION_MODULES = { 'inventory.transfers.reconcile': 'Inventory', /* … */ } as const
export type Permission = keyof typeof PERMISSIONS
```

`Permission` already exists at `permissionsMap.generated.ts:312` as `keyof typeof PERMISSIONS`; what changes is that `usePermissions.ts:8` stops widening it with `UiAliasPermission`.

#### 4.5.4 The frontend becomes server-authoritative

Three deletions and one simplification (wave 2, gated by `frontend-conventions-reviewer`):

1. **Delete `apps/web/src/hooks/uiAliasPermissions.ts`** (15 lines). Five of its role arms — `sales`, `purchases`, `inventory`, `treasury`, `user` — name roles no seeder creates, so `'services.view': ['admin','sales','manager']` has always meant `['admin','manager']`; and for `/services` this dead map is the **only** authorization that exists anywhere (I-22). Its keys become real permissions in wave 0b.
2. **Delete the role-map fallback** in `usePermissions.hasPermission` (`apps/web/src/hooks/usePermissions.ts:193-211`). Today the fallback fires even when `serverPermissions` is present but lacks the entry (`:194-199`), which is fail-open for any key outside the nine-entry `SERVER_AUTHORITATIVE_PERMISSIONS` set. After: `hasPermission` is `serverPermissions?.includes(permission) === true` and nothing else — G12's rule, and what all three reference ERPs do.
3. **Delete the generated role→permission map's *runtime* use.** `permissionsMap.generated.ts` stays as the source of the `Permission` type and of `PERMISSION_MODULES`; it stops being consulted for decisions.
4. **`MODULE_PERMISSIONS` keeps its fail-closed contract** (`apps/web/src/hooks/usePermissions.ts:230-236`) — a key outside the map denies. That contract is correct and is not touched.

The staleness window is real and is not closed by this design: on cold load Zustand hydrates `roles`/`permissions` from `localStorage` before `/auth/me` resolves, and `AuthProvider`'s `staleTime: 1000*60*5` means an open tab can render a five-minute-old permission set. That is a UX-only exposure — every mutating request is still authorized server-side — and it is recorded as edge-case row EC-17 in §7 with its test, not silently accepted.
### 4.6 Role management hardening

#### 4.6.1 `roles.is_system` replaces the string list

`RoleController` protects role names against a hard-coded `$systemRoles = ['super-admin', 'admin', 'owner']` at `apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:228` (rename) and `:277` (delete), mirrored client-side at `apps/web/src/features/settings/RolesPage.tsx:35`. Of the three, only `admin` is a real Spatie role: `super-admin` (hyphenated) matches neither the seeded roles nor the central `super_admin`, and `owner` is a `MembershipRole` value, not a role. So the guard protects two names that can only exist if a tenant creates a custom role with one of them, and it does not protect `manager`, `cashier`, `viewer`, `technician`, `operator`, `accountant` or `general_manager` at all.

Both lists are deleted and replaced by the `roles.is_system` column (§5), which sync sets (§4.3.2 step 5). Rename → 422 `SYSTEM_ROLE_PROTECTED` when `is_system`; delete → 422 `SYSTEM_ROLE_PROTECTED` when `is_system`. The FE reads `is_system` from the role payload — which `RoleController::index`/`show` already shape (`:141-149`, `:167-175`) and which gains the three new fields — instead of matching names.

#### 4.6.2 The admin permission floor

`RoleController::update` guards **only the rename**: the check at `:229` fires only when `$request->has('name') && input('name') !== $role->name`, and `$role->syncPermissions($validated['permissions'])` at `:246` then runs **unconditionally, including on `admin`**. A `roles.manage` holder can `PATCH /api/v1/roles/{adminRoleId}` with `{"permissions": []}` and empty the tenant's admin role, with no audit row (`:223-266` emits none). It is latent today only because `roles.manage` is held by `admin` alone.

> **Floor F-1 (admin permission floor).** A request that would leave the role whose `template_key = 'admin'` holding fewer than `PermissionRegistry::activeKeys()` is refused with 422 `ADMIN_PERMISSION_FLOOR`. The admin role's permission set is not operator-editable at all: `PATCH /roles/{id}` on it ignores `permissions` and returns 422 if one is supplied. Its set is a function of the registry (§4.3.2 step 5), which is the same rule Odoo and ERPNext apply to their Settings/System-Manager equivalents.

#### 4.6.3 The last-admin floor, stated precisely

> **Floor F-2.** Let *A* = the set of principals in the tenant with `principal_kind = human`, `status = active`, holding the role whose `template_key = 'admin'`. Four operations are refused with 422 when they would make `|A| = 0`:
> 1. `DELETE /api/v1/users/{userId}/roles` removing the admin role from the last member of *A* → `LAST_ADMIN_ROLE_REMOVAL`;
> 2. `POST /api/v1/users/{id}/deactivate` on the last member of *A* → `LAST_ADMIN_DEACTIVATION`;
> 3. `DELETE /api/v1/users/{id}` on the last member of *A* → `LAST_ADMIN_DELETION`;
> 4. `DELETE /api/v1/roles/{id}` on the admin role itself → already refused by `is_system` (F-1/§4.6.1), restated here so the floor is complete.
>
> Three clarifications the definition needs to be unambiguous. **(a)** Service principals do **not** count toward *A* — a tenant whose only admin is a machine has nobody who can log in and fix a broken token (OQ-1, §9.2, recommended default, applied). **(b)** The floor is **per tenant**, not per company, because roles are tenant-scoped (D1) — a tenant with two companies has one admin population. **(c)** The check runs **inside** the same transaction as the mutation, `SELECT … FOR UPDATE` on the candidate rows, so two concurrent admins each removing the other cannot both succeed (edge case EC-19).

Implemented in `App\Modules\Identity\Application\Services\LastAdminFloorGuard`, constructor-injected into `RoleController` and `UserController` — not a middleware, because the decision needs the resolved target row.

#### 4.6.4 Read gates (D4)

| Route | Today | After |
|---|---|---|
| `GET /api/v1/roles` (`apps/api/app/Modules/Identity/routes.php:59`) | no gate | `require.any.permission:roles.view,users.assign-roles` — a role-**assigner** must be able to list role names |
| `GET /api/v1/roles/{id}` (`:61`) | no gate | `can:roles.view` — the detail carries the permission set |
| `GET /api/v1/permissions` (`:64`) | no gate | `can:roles.view` |
| `GET /api/v1/users/{userId}/roles` (`:78`) | **no gate; returns any tenant user's full `getAllPermissions()` list to any authenticated caller** (`RoleController::userRoles`, `:429-444`, payload at `:436-437`) — I-18 | `require.any.permission:roles.view,users.assign-roles` |
| `GET /api/v1/users/{id}/effective-permissions` | new | `require.any.permission:roles.view,users.assign-roles`, **or** the caller is the subject |

The list response is **shaped by the gate**, not merely allowed by it: a caller holding only `users.assign-roles` receives `{id, name, is_system, users_count}` per role; a caller holding `roles.view` additionally receives `permissions`. That is what "role names remain readable to `users.assign-roles` holders" means concretely, and it is why the any-of gate is correct on the list while `show` requires `roles.view`.

The misleading comments at `apps/api/app/Modules/Identity/routes.php:58` (*"Role management (requires roles.view or roles.manage permission)"*) and `:77` (*"User role assignment (requires users.assign-roles permission)"*) become true statements rather than being deleted.

#### 4.6.5 Audit events (D8)

Three new domain events beside the existing `RoleAssigned`/`RoleRemoved` (`apps/api/app/Modules/Identity/Domain/Events/`), consumed by the same subscriber that already writes `audit_events` (`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php`):

```php
final readonly class RoleCreated {
    /** @param list<string> $permissions */
    public function __construct(
        public int $roleId, public string $roleName, public array $permissions,
        public string $companyId, public string $actorUserId, public ?string $actorTokenId,
        public string $occurredAt,
    ) {}
}

final readonly class RoleUpdated {
    /** @param list<string> $added @param list<string> $removed */
    public function __construct(
        public int $roleId, public string $roleName, public ?string $previousName,
        public array $added, public array $removed,
        public string $companyId, public string $actorUserId, public ?string $actorTokenId,
        public string $occurredAt,
    ) {}
}

final readonly class RoleDeleted { /* roleId, roleName, permissions (final set), actor…, occurredAt */ }
```

`RoleUpdated` carries the **diff**, not the resulting set — a 300-entry `permissions` array in every audit row is unreadable, and the question an auditor asks is "what changed". The diff is computed before `syncPermissions()` runs, inside the same transaction. `actorTokenId` is `personal_access_tokens.id` or null (§4.1.5). These events follow the existing immutability rule (CLAUDE.md rule 8): once shipped, a change means `RoleUpdatedV2`.

**403 denial events.** A denial event is emitted when a `can:`/`require.any.permission` middleware or a `Gate::authorize` refuses a request on a **write verb** route. Reads are excluded deliberately: a UI that renders from a stale permission set produces read 403s in bulk, and drowning the audit log is how denial logging gets switched off. Payload: `{route_name, method, uri_template, permission, principal_id, token_id, company_id, ip}` — never the request body, which can carry money, PII or a PIN.

> **Dedup/rate rule.** At most **one** `AuthorizationDenied` event per `(principal_id, permission, route_name)` per **five-minute** window, counted in the tenant's Redis cache with a five-minute TTL. Suppressed occurrences increment a counter carried on the *next* emitted event as `suppressed_count`, so a storm is visible as one row saying "and 412 more" rather than as 413 rows or as silence. A denial for a principal whose token scope (not whose grants) caused the refusal is tagged `cause: token_scope` — that distinction is the first question anyone debugging an MCP integration will ask.

#### 4.6.6 Effective permissions

```
GET /api/v1/users/{id}/effective-permissions
```

```jsonc
{
  "data": {
    "principal": { "id": "…", "kind": "human|service", "status": "active" },
    "roles": [ { "id": 3, "name": "manager", "is_system": true, "template_key": "manager",
                 "template_version": 7, "customised_at": null } ],
    "permissions": [ { "key": "invoices.post", "module": "Document",
                       "granted_by": ["manager"], "in_token_scope": true } ],
    "token_scope": { "token_id": "…", "granted": ["…"], "unknown": [] },   // null for a session caller
    "company_scope": { "company_id": "…", "membership_role": "manager" },
    "location_scope": { "all_locations": false, "allowed_location_ids": ["…"] },
    "discount": { "can_discount": true, "max_discount_percent": "10.00" }
  }
}
```

`granted_by` names every role contributing the key, which is the single most useful field when an operator asks "why can they do that?". `in_token_scope` is `true` for a session caller. The discount numerics are surfaced read-only — D6 keeps them out of the catalogue, but hiding them from the one screen that answers "what can this person actually do" would be the same mistake in a different place (I-17).

The web panel lives at Settings → Users → *(user)* → **Effective permissions**, grouped by `PERMISSION_MODULES`, with a filter and a "differs from role template" badge. `RolesPage` groups by the registry module rather than by `explode('.', $name)[0]` (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:320-324`), and renders the `template_version` gap as *"3 template updates not applied — this role was customised on 12 Aug 2026"* with a one-click **Re-apply template** action (which sets `customised_at = null` and re-runs the delta for that role only). That action is the answer to D2's obvious follow-up question — a tenant that customises a role is not permanently frozen, it is opted out until it opts back in.

### 4.7 Separation of duties

> **SoD-1.** For every `sod_group` in the registry, no **non-admin** system-role template may hold both a `create`-class key and a **financially decisive** key (`PermissionVerb::isFinanciallyDecisive()`: `post`, `reverse`, `void`, `approve`, `refund`, `allocate`) from that group.

Enforced by `PermissionSodTemplateTest`, a pure registry test — no database, no fixtures, runs in milliseconds on both lanes. It asserts the rule over `registry->templateGrants()` and reports violations as `sod_group 'purchase-order': template 'manager' holds both purchase-orders.create and purchase-orders.approve`.

Three scoping decisions, each stated so a gate can challenge them:

1. **Templates, not roles.** The rule constrains what the platform *ships*; a tenant may still create a custom role that holds both, because a five-person pharmacy legitimately has one person who raises and approves POs. The benchmark supports this: neither Odoo, ERPNext nor Dolibarr implements NIST SSD/DSD generically — they separate create from approve *in the shipped defaults* (Odoo's Purchase Order Double Approval group, ERPNext's per-transition workflow roles) and leave the rest to the operator. Enforcing it at runtime would be a DIVERGE from all three, and would break real small tenants.
2. **`admin` is exempt**, because `admin` holds everything by definition (§4.6.2). An SoD rule that `admin` had to satisfy would be a contradiction.
3. **Initial groups** are declared in wave 1 from the existing catalogue: `purchase-order` (`purchase-orders.create` vs `.confirm`/`.approve`), `supplier-invoice`, `invoice` (`invoices.create` vs `.post`), `credit-note`, `payment` (`payments.create` vs `.allocate`/`.reverse`/`.refund`), `expense` (`expenses.create` vs `.post`/`.pay`), `journal`, `stock-adjustment` (`inventory.adjustments.create` vs `.post`). Any template that violates one on day one is a **finding to rule on**, not a reason to weaken the rule — the wave-1 plan lists them for the owner before the test lands.

### 4.8 Cache

The tenant-scoping fix is **S-1, Task 1 of the request-hygiene Phase A plan**, already specified and dispatched: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:60-140`. It is referenced here, not redesigned. Its files, verbatim from `:62-66`:

- create `apps/api/app/Modules/Identity/Application/Listeners/ScopePermissionCacheToTenant.php`
- create `apps/api/app/Modules/Identity/Application/Listeners/RestoreCentralPermissionCache.php`
- modify `apps/api/app/Providers/TenancyServiceProvider.php`
- modify `apps/api/tests/Traits/ProvisionsTenantDatabases.php`
- create `apps/api/tests/Feature/Identity/PermissionCacheTenantScopingTest.php`

with the key becoming `spatie.permission.cache.<tenant-id>` in database-per-tenant mode and the shared base key remaining correct in compatibility mode (`:66`). Wave 0a's only job here is to **land that lane** — the design adds nothing to it.

**What this design adds on top.** One line, in `PermissionSyncService` (§4.3.2 step 7): `forgetCachedPermissions()` after a successful sync, which under the S-1 key scheme forgets exactly this tenant's snapshot. Two supporting facts make that necessary and sufficient: Spatie resets the cache automatically only through its own mutator methods, never for raw writes (the documented multi-tenant pitfall, `docs/superpowers/audits/2026-09-09-roles-permissions/05-benchmark-odoo-erpnext-dolibarr.md:118`), and step 1's rename is a raw `UPDATE` precisely so grants survive — so the flush is not optional. The boot-time global flush at `apps/api/docker/entrypoint.sh:176` stays as a belt.
### 4.9 Migrating the existing 304

#### 4.9.1 Module mapping

Every existing key is assigned an owning module by prefix. The table is the wave-1 deliverable's spine; it is complete for all 304 keys by construction (every prefix below appears in `apps/api/database/seeders/RolesAndPermissionsSeeder.php:48-559`), and grouping changes for the four prefixes marked ★, whose current UI group is the first dot segment.

| Key prefix(es) | Owning module | Manifest file |
|---|---|---|
| `partners.*`, `contacts.*` | Partner, Contact | `app/Modules/Partner/Domain/Authorization/PartnerPermissionManifest.php`, likewise Contact |
| `products.*`, `enrichment.*`, `composite-items.*`, `modifier-groups.*`, `catalog.attributes.*` ★, `catalog.variants.*` ★, `catalog.labels.*` ★ | Product / Catalog | `ProductPermissionManifest`, `CatalogPermissionManifest` |
| `pricing.*` | Pricing | `PricingPermissionManifest` |
| `workshop-bundles.*`, `work-orders.*`, `workshop.technicians.*`, `workshop.payroll.*` | Workshop | `WorkshopPermissionManifest` |
| `menus.*`, `menu-categories` writes | Menu | `MenuPermissionManifest` |
| `promotions.*`, `coupons.*`, `loyalty.*` | Promotion, Coupon, Loyalty | three manifests |
| `vehicles.*` | Vehicle | `VehiclePermissionManifest` |
| `documents.*`, `quotes.*`, `orders.*`, `invoices.*`, `credit-notes.*`, `deliveries.*` | Document | `DocumentPermissionManifest` |
| `purchase-orders.*`, `goods-receipt.*`, `supplier-invoices.*`, `document-ingestions.*`, `purchase-quote-requests.*` | Procurement / DocumentIngestion | `ProcurementPermissionManifest`, `DocumentIngestionPermissionManifest` |
| `inventory.*`, `inventory.transfers.*` ★, `inventory.adjustments.*` ★, `replenishment.*` | Inventory, Replenishment | `InventoryPermissionManifest`, `ReplenishmentPermissionManifest` |
| `uom.*`, `units.*` | Uom | `UomPermissionManifest` |
| `batches.*` | BatchExpiry | `BatchExpiryPermissionManifest` — **owns `batches.recall.request`** (W-LOT-A-1a) |
| `expenses.*`, `expense-categories.*`, `expense-recurrences.*`, `income.*` | Expense, Income | two manifests |
| `payments.*`, `instruments.*`, `repositories.*`, `treasury.*`, `bank-statements.*` | Treasury | `TreasuryPermissionManifest` — **owns `treasury.manage_all_locations`** (W-LOT-A-1a) |
| `journal.*`, `accounts.*`, `ledger.*`, `fiscal-periods.*`, `reports.*`, `dashboard.*` | Accounting, Dashboard | `AccountingPermissionManifest`, `DashboardPermissionManifest` |
| `withholding.*`, `taxation.*` | Taxation | `TaxationPermissionManifest` |
| `scheduling.bays.*`, `scheduling.appointments.*` | Scheduling | `SchedulingPermissionManifest` |
| `pos.*`, `pos_held_orders.*`, `pos_orders.*` | POS | `PosPermissionManifest` |
| `users.*`, `roles.*`, `service-accounts.*` (new) | Identity | `IdentityPermissionManifest` |
| `compliance.*`, `fiscal.*`, `fraud-settings.*`, `fraud-alerts.*`, `audit.*` | Compliance, Fiscal | two manifests |
| `settings.*`, `imports.*`, `support-access.*` | Company, Import, SupportAccess | three manifests |
| `marketplace.*`, `catalog_cart.*`, `channels.*` (new) | Marketplace, Cart, Channel | three manifests |
| `progression.*` (new) | Progression | `ProgressionPermissionManifest` |

#### 4.9.2 Rename map

`PermissionRenameMap` is a single class returning an ordered list of `(from, to, introducedIn)` triples; §4.3.2 step 1 applies it in place, preserving grants. It is **append-only and shrink-never** — an entry is removed only after `permissions:sync --dry-run` reports it stale on every tenant, which is why the sync reports staleness.

| From | To | Why |
|---|---|---|
| `catalog_cart.view` | `catalog-cart.view` | snake→kebab resource (D5) |
| `catalog_cart.create` | `catalog-cart.create` | " |
| `catalog_cart.convert_po` | `catalog-cart.convert_po` | " (verb `convert`, qualifier `po`) |
| `catalog_cart.convert_so` | `catalog-cart.convert_so` | " |
| `catalog_cart.manage_all` | `catalog-cart.manage_all` | " |
| `catalog_cart.marketplace_checkout` | `catalog-cart.marketplace_checkout` | " (`legacy()` — does not decompose) |
| `pos_held_orders.view` | `pos-held-orders.view` | " |
| `pos_held_orders.create` | `pos-held-orders.create` | " |
| `pos_held_orders.delete` | `pos-held-orders.delete` | " |
| `uom.edit` | `uom.update` | `edit`→`update` verb normalisation (D5) |
| `deliveries.edit` | `deliveries.update` | " |

**`pos_orders.*` is deliberately NOT in the map.** All four keys (`view/create/update/delete`) are in the confirmed-dead set — the group is enforced nowhere, and `pos_orders` appears in code only as a SQL table name (`apps/api/app/Modules/POS/Application/Services/PosAnalyticsService.php:380-384`). Renaming a dead key would create a second dead key. They are deprecated instead (§4.9.3).

Every rename needs a matching edit at its call sites in the same commit: `uom.edit` and `deliveries.edit` are live (`manager` holds both, `apps/api/database/seeders/RolesAndPermissionsSeeder.php:601,604`), so their route middleware strings and any `->can()` call move to the new enum case; the PHPStan rule (§4.5.1) makes a missed one a build error rather than a 403 in production.

#### 4.9.3 Deprecations

**The dead keys** (`docs/superpowers/audits/2026-09-09-roles-permissions/01-backend-permission-model.md:104`) are declared with `deprecated: true`. **Count correction found by this spec's self-review:** the audit's prose says *"26 confirmed dead permissions"* but its own code block lists **25** — counted mechanically at `971528977`. The list below is that block verbatim, 25 entries; wave 1's plan re-derives the set from the tree rather than trusting either number: `audit.view`, `batches.delete`, `batches.recall`, `catalog_cart.convert_so`, `catalog_cart.manage_all`, `coupons.view`, `inventory.receive`, `invoices.print`, `menus.view`, `pos.issue_goodwill_voucher_high_value`, `pos.redeem_voucher`, `pos.refund_no_receipt`, `pos.refund_voucher_to_cash`, `pos.rotate_qr_signing_key`, `pos.search_customer_cross_company`, `pos.search_customer_recent_purchases`, `pos.void_receipts`, `pos_orders.{view,create,update,delete}`, `products.import`, `purchase-quote-requests.delete`, `workshop.technicians.adjust_time_entries`, `workshop.technicians.approve_time_off`.

**Three of the 26 are not dead and must be excluded from the deprecation list.** `batches.delete` and `batches.recall` are live route gates after wave 0a closes `DELETE /api/v1/batches/{uuid}` and `POST /api/v1/batches/{uuid}/recall` (§4.4.4) — the audit found them "dead" precisely because those routes had **no** gate; and `batches.recall` is re-granted by W-LOT-A-1a to `general_manager` and revoked from `manager` (plan `:1431-1444`). `products.import` gates a live importer surface reachable from the FE. Wave 1's plan re-verifies each of the 25 against the tree **after** wave 0a lands, because wave 0a changes the answer for at least these three — deprecating a key wave 0a just started using would be a self-inflicted 403.

Deprecation semantics (§4.3.2 step 3): the `permissions` row survives one release; the key is dropped from every system-role template on the next sync; a **custom** role keeps it, so no tenant loses a capability without a human decision; the Roles UI renders it struck through with *"deprecated — will be removed in the next release"*. The row is deleted by `permissions:prune-orphans --confirm` one release later, never automatically.

**`reports.view`** is removed rather than deprecated-and-kept. Its comment already says it "no longer gates any route as of this release … Kept seeded … for one release … slated for removal next release" (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:325-332`); that release has passed. It enters the registry as `deprecated: true, replacedBy: null` in wave 1 and is pruned in wave 3 — the deprecation machinery is exactly how the seeder's comment intended it to be handled, now with a mechanism instead of a comment.

**`credit-notes.cancel` is declared.** The route `POST /credit-notes/{creditNote}/cancel` requires it (`apps/api/app/Modules/Document/Presentation/routes.php:269-271`) and no tenant has ever had the row — Spatie 6.25 swallows `PermissionDoesNotExist` in `checkPermissionTo`, so **every caller including `admin` gets a silent 403** (`docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:187`). It is added to the Document manifest with `templateDefaults: ['manager', 'general_manager']`, matching its siblings `credit-notes.create`/`.post`. **This is wave 0b, not wave 1** — it is a live, reachable, permanently-broken endpoint and should not wait for the registry.

The test that masks it is fixed in **wave 0a**: `apps/api/tests/Feature/Document/RefundResidualTenantIsolationTest.php:136-142` does `Permission::firstOrCreate(['name' => 'credit-notes.cancel', 'guard_name' => 'sanctum'])` and grants it, with a comment mischaracterising a permission that does not exist in the catalogue as one "admin doesn't include by default". The `firstOrCreate` is replaced by a plain lookup that fails if the seeder has not created it — which turns the suite red until wave 0b declares the key, which is the correct order.

#### 4.9.4 Deletions and re-scopings

**`apps/api/database/seeders/PermissionSeeder.php` (274 lines) is deleted** in wave 0a. It ships a colliding taxonomy — 65 different permission strings and seven capitalised role names (`Administrator`, `Sales Manager`, `Accountant`, `Sales Rep`, `Warehouse Manager`, `Receptionist`, `Cashier`) that would collide with the real lowercase `accountant`/`cashier` if ever run. It has exactly one caller, `apps/api/database/seeders/ProductionSeeder.php:75`, which is manual-only and never in the entrypoint or the provisioning path; that line is deleted with it. It is also the file where `credit-notes.cancel` survives (`PermissionSeeder.php:72`) — evidence the key was lost in a seeder consolidation, which is why §4.9.3 restores it before this file goes.

**`marketplace.admin` — DEFER with a ticket.** It gates seller administration inside the tenant API (`apps/api/app/Modules/Marketplace/Presentation/routes.php:92-97`) yet is a platform-scoped capability, and under §4.3.2's `admin = activeKeys()` rule every tenant admin would hold it. It is unreachable today: the whole module sits behind a kill-switch that returns early when `config('marketplace.enabled')` is false, and it defaults to false (`apps/api/app/Modules/Marketplace/Presentation/routes.php:49`). So no tenant can reach those routes at `971528977`, and the correct fix — re-gate the seller-admin routes on `super_admin` and deprecate the key — is scheduled for **wave 3** with ticket `docs/superpowers/tickets/2026-09-10-marketplace-admin-platform-scope.md`. Inventing a `platformScoped` flag on `PermissionDefinition` for one dormant key would be worse than the problem.

### 4.10 POS

Four changes, all narrow; the POS authorization model itself is sound and is not redesigned.

1. **The PIN payload is derived from the registry.** `PosAuthController::verifyPin` returns `permissions => $user->getAllPermissions()->pluck('name')` (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:99`). That already reflects effective grants and gains token-scope narrowing for free (§4.1.3) — but a PIN verification is a *session* call from a device whose own token is unscoped, so in practice nothing changes today. What changes is that the device's approval-scope derivation reads the `pos.approve_*` keys from `PERMISSION_MODULES`-grouped registry data rather than a hand-maintained list, so a new approval permission reaches the terminal on the next deploy without a POS release.
2. **Approval scopes are unchanged.** The manager-PIN step-up flow, `PinVerifier::verifyForApproval`, and the `pinHolders()` population contract (`:46-57`: caller's tenant, ACTIVE membership of the CompanyContext company, ACTIVE account, PIN set) keep their semantics exactly. G9 says this is already the right shape.
3. **Service accounts are excluded from `pinHolders()`.** One predicate — `->where('principal_kind', PrincipalKind::Human->value)` — added to the builder at `:53-56`. A service principal has `pos_pin IS NULL` by the §4.1.1 invariant, so the predicate is belt-and-braces; it is added anyway because `pinHolders()` is documented as "the single definition of an operator PIN this terminal may act on" (`:34-45`), and a definition that depends on another invariant holding is one refactor away from being wrong. The same predicate goes on `pinData` and `hasPins`, the two other surfaces that must admit the same population (`:36-42`).
4. **The legacy role ladder is removed in wave 3, not before.** `apps/pos/src/stores/operatorStore.ts:354` reads `user.roles.includes('super_admin') || user.roles.includes('admin')` against the **tenant** login user, whose roles come from the seven-role seeded set — `'super_admin'` can never appear there, so the offline-PIN-setup admin bypass (`:362-363`) is reachable only through the `'admin'` half. Removing the dead arm is trivial; removing the *ladder* means the offline path must fall back to the last synced permission payload instead of to a role name, and that is a POS release with an offline-behaviour test, so it waits for wave 3 where the POS lane can own it. The dead `pos.*` keys (§4.9.3) are deprecated in wave 1 regardless, since deprecation does not change runtime behaviour.
---

## 5. Data model

**Two tenant migrations. No central migration. No backfill beyond what `permissions:sync` performs.** Both live in `apps/api/database/migrations/tenant/` and run under `tenants:migrate` / `tenant:migrate-rolling` (`.claude/context/architecture.md`, "Migration topology"). Both must be timestamped **after** `2026_09_06_205000_add_provisioning_source_to_roles.php` (§4.3.0).

### 5.1 `users` — wave 2

Migration `2026_09_20_100000_add_principal_kind_to_users.php`.

| Column | Type | Null | Default | Index | FK | Enum class |
|---|---|---|---|---|---|---|
| `principal_kind` | `varchar(16)` | NO | `'human'` | `index(principal_kind)` | — | `App\Modules\Identity\Domain\Enums\PrincipalKind` |
| `principal_description` | `varchar(255)` | YES | `NULL` | — | — | — |
| `created_by_user_id` | `uuid` | YES | `NULL` | `index(created_by_user_id)` | `users.id`, `ON DELETE SET NULL` | — |

- **`principal_kind`** — CHECK `principal_kind IN ('human','service')` (PG) with an equivalent insert/update trigger pair on SQLite, following the pattern W-LOT-A-1a establishes for `provisioning_source` (plan `:1205-1217`). The `'human'` default is what makes this migration a no-op for every existing row: no backfill statement is written, because every existing `users` row is a human by construction (there is no other way to create one today).
- **Invariant enforced in the database**, not only in the model: CHECK `principal_kind = 'human' OR (pos_pin IS NULL AND email_verified_at IS NULL)`. A service principal with a POS PIN would be admitted to `pinHolders()` by an older query and is exactly the kind of drift §4.10 exists to prevent.
- **`password` is NOT made nullable.** It stays `NOT NULL` (`apps/api/database/migrations/tenant/2025_11_30_000003_create_users_table.php:22`) and a service principal stores the literal sentinel `'!'`, which is not a valid bcrypt/argon hash and which `Hash::check()` therefore always rejects. Widening the column to nullable would push a `?string` through every call site that currently assumes a string — a large blast radius for no gain, since `AuthController::login` refuses a service principal before any hash comparison anyway (§4.1.1).
- **`email` keeps its `unique(['tenant_id','email'])`** (`:34`). A service account is given a synthetic, non-routable address `svc+<slug>@<tenant-slug>.invalid` (RFC 2606 reserved TLD), so the existing key holds without change and no mail can ever be sent to it.
- **`created_by_user_id`** is a self-FK recording who created a service account; `ON DELETE SET NULL` so deleting the creator never cascades into the machine identity.

### 5.2 `roles` — wave 1

Migration `2026_09_18_100000_add_template_columns_to_roles.php`.

| Column | Type | Null | Default | Index | FK | Enum class |
|---|---|---|---|---|---|---|
| `is_system` | `boolean` | NO | `false` | — | — | — |
| `template_key` | `varchar(64)` | YES | `NULL` | partial unique `roles_template_key_unique ON roles (template_key) WHERE template_key IS NOT NULL` | — | `App\Modules\Identity\Domain\Enums\SystemRoleTemplate` |
| `template_version` | `integer` | YES | `NULL` | — | — | — |
| `customised_at` | `timestamptz` | YES | `NULL` | — | — | — |

- **`SystemRoleTemplate`** is a string-backed enum whose cases are `admin, general_manager, manager, cashier, viewer, technician, operator, accountant`. Its `GeneralManager` case **reuses** W-LOT-A-1a's `SystemRoleName::GeneralManager` value `'general_manager'` (plan `:1241-1244`); the two enums are reconciled into one in wave 3, and until then a test asserts the two values are equal so they cannot drift.
- **The partial unique carries no tenant column, deliberately.** Under database-per-tenant the invariant is "at most one role per template per database", and legacy roles carry `tenant_id NULL` (§4.3.0 clause 3) — so including `tenant_id` would make PG treat every NULL as distinct and permit two `manager` templates. This is the same reasoning convention 09 gives for db-per-tenant keys: *"under db-per-tenant `unique(['sku'])` is the same bug"* read in the other direction — here the tenant column is the thing that would break it (`docs/conventions/09-SECOND-OF-EVERYTHING.md:54-59`).
- **CHECK constraints** (PG, with SQLite triggers): `template_key IS NULL OR is_system` and `customised_at IS NULL OR is_system` — only a system role tracks a template or a customisation. A custom role has all four columns at their defaults.
- **`template_version`** is the registry's monotonically increasing catalogue version, an integer bumped by the wave-1 release process and asserted non-decreasing by a test. It is not a semver and not a timestamp; its only job is to answer "how many template updates has this role missed".
- **`customised_at`** is set by `RoleController::update` on the **first** tenant edit of a system role's permission set and never cleared except by the explicit *Re-apply template* action (§4.6.6). It is a timestamp rather than a boolean so the UI can say *"customised on 12 Aug 2026"*.
- **No column on this table is written by `permissions:sync` for a custom role**, and `provisioning_source`, `tenant_id`, `name`, `guard_name` and `id` are never written by it for any role. That is the whole of §4.3.0's superset promise expressed as a schema statement.

### 5.3 What is NOT changed

- **`permissions`** — no new column. `unique(name, guard_name)` (`apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:30`) is already the right key, and everything the registry knows about a permission beyond its name lives in code, where it can be reviewed in a diff.
- **`personal_access_tokens`** — no new column, and no central migration. Sanctum 4 already ships `name`, `abilities` (JSON) and `expires_at`; token scope is `abilities`, and token TTL is `expires_at` (OQ-2, §9.2).
- **`audit_events`** — no new column. `principal_id` and `token_id` go inside the existing `metadata` jsonb (`apps/api/app/Modules/Compliance/Domain/AuditEvent.php:55`), which is what it is for. The impersonation columns at `:58-63` are untouched.
- **`user_company_memberships`** — unchanged. A service account uses the same table, the same `MembershipStatus`, and the same `allowed_location_ids`.
- **`roles.provisioning_source`** and its CHECK, partial unique and triggers — untouched (§4.3.0).

---

## 6. Convention-09 obligations per wave

`docs/conventions/09-SECOND-OF-EVERYTHING.md:37-50` is in scope for a lane whose diff touches a catalogue entity on any layer. **Roles and permissions are not `CATALOGUE_TABLES` entities**: they are not company-owned, they are tenant-scoped by owner ruling D1, and an operator does not create them per company. The classification is recorded here so a future ratchet sweep does not have to rediscover it — if `TenantOnlyUniqueOnCatalogueTablesRatchetTest` is ever widened to sweep them, both belong in `EXCLUDED_TABLES` with the reason:

> `roles`, `permissions` — tenant-global by nature. Owner ruling D1 (2026-09-10) keeps roles scoped to the tenant, with company and location scope carried by `user_company_memberships`; a per-company role would be a second surface for the same concept (convention 11). `roles_template_key_unique` deliberately omits `tenant_id` (§5.2).

The `users` table is likewise not a catalogue entity. **But two of the three axes still apply to this design's behaviour**, and the obligations below are real tests, not a waiver:

| Wave | Second company | Second location | Re-run / idempotency |
|---|---|---|---|
| **0a** — cache, ratchet, read gates | S-1's own test provisions **two real tenant databases** (`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:60-140`), which is the stronger form of the axis for a tenant-scoped concept. The ratchet is schema-independent. | n/a — no location-bound behaviour changes | the ratchet test is idempotent by construction; running the route sweep twice yields identical baselines (asserted) |
| **0b** — new permissions for the ungated writes | `ServicePermissionsSecondCompanyTest`: an actor with `services.update` in company A is refused a `PATCH /services/{id}` on a service of company B — proving the new gate did not replace the existing company scope | `CategoryPermissionsSecondLocationTest` where a category write is location-bound | re-running `tenants:seed` twice adds each new key once (`firstOrCreate`), asserted by row count |
| **1** — catalogue + sync | `PermissionsSyncTest::sync_creates_every_registry_permission_in_a_fresh_tenant_and_in_a_second_tenant` — two physical tenant DBs, asserting **data meaning**: tenant B's `admin` holds exactly `activeKeys()`, tenant B's customised `manager` was skipped independently of tenant A's | a second `pos_enabled` location, asserting a POS approval permission granted by sync is honoured at the *selected* terminal's location, not the default | **`permissions:sync` run twice** → second run reports `ALREADY_CURRENT` with every counter zero and **zero** row writes (asserted by a query-log count, not by absence of exception); plus `sync` after `LotActionPermissionDelta` → no change to the two W-LOT keys or to `general_manager` |
| **2** — role management, principals, tokens | `LastAdminFloorTest` with two companies in one tenant, asserting the floor is per **tenant**: removing the admin role from the only admin is refused even though company B has its own owner (§4.6.3 clause b) | `ServiceAccountLocationScopeTest` — a service token whose principal's membership carries `allowed_location_ids` is refused a write at another location | issuing the same token twice creates two distinct tokens (no dedup) but revoking a principal's membership twice is idempotent; re-applying a template twice is a no-op |
| **3** — consistency sweep | second-company assertions on the POS PIN population after `principal_kind` filtering | second-location POS approval scope | `permissions:prune-orphans` run twice → second run deletes nothing |
| **4** — edge-case campaign | the campaign itself is the second-company/second-location journey (§8) | " | " |

Every assertion above is on **data meaning** — the row company B sees, the permission set after the second run — never on HTTP status or "no exception thrown" (`docs/conventions/09-SECOND-OF-EVERYTHING.md:52`).
---

## 7. Edge-case register

Thirty-two rows. Each is a scenario, the expected behaviour, the single place that enforces it, and the lane that proves it. Lanes: **PG** = PHPUnit on PostgreSQL (real tenant databases, `ProvisionsTenantDatabases`), **SQLite** = PHPUnit on the fast lane, **vitest** = `apps/web`, **playwright** = `apps/web/e2e`, **pos-vitest** = `apps/pos`.

| # | Scenario | Expected behaviour | Where enforced | Test lane |
|---|---|---|---|---|
| EC-1 | The admin role is removed from the **last** active human principal holding it | 422 `LAST_ADMIN_ROLE_REMOVAL`; nothing written | `LastAdminFloorGuard`, called from `RoleController::removeRole` inside the mutation transaction (§4.6.3) | PG — `LastAdminFloorTest::removing_the_admin_role_from_the_last_admin_is_refused` |
| EC-2 | The last admin is **deactivated** (`POST /users/{id}/deactivate`) | 422 `LAST_ADMIN_DEACTIVATION`; `status` unchanged | same guard, `UserController::deactivate` | PG |
| EC-3 | The last admin is **deleted** | 422 `LAST_ADMIN_DELETION` | same guard, `UserController::destroy` | PG |
| EC-4 | The tenant's **only** admin is a service account, and the only human admin is deactivated | Refused — service principals do not count toward the floor (§4.6.3 clause a) | `LastAdminFloorGuard` filters `principal_kind = human` | PG — `LastAdminFloorTest::a_service_admin_does_not_satisfy_the_floor` |
| EC-5 | A **custom** role holds a permission the registry now marks **deprecated** | The grant survives; the key disappears from every system-role template; the Roles UI strikes it through with "deprecated — removed next release"; sync never revokes it | `PermissionSyncService` step 3 (§4.3.2); `RolesPage` renders `deprecated` from the payload | PG + vitest |
| EC-6 | A **custom** role holds a permission that the rename map renames | The grant survives because the rename is an in-place `UPDATE` on `permissions.name`; the primary key never changes, so `role_has_permissions` is untouched | `PermissionSyncService` step 1 (§4.3.2) | PG — `PermissionRenameTest::a_custom_role_grant_survives_a_rename` (asserts the pivot row count and the role's resolved key set, not just absence of error) |
| EC-7 | Rename map says `a → b` and **both** rows already exist in a tenant | Run is `BLOCKED`, `reason=rename_target_exists`, transaction rolled back, exit 2; a human decides which grants win | `PermissionSyncService` step 1 | PG |
| EC-8 | A deploy adds permissions **while a user's session is live**; their cached Spatie snapshot is stale | The tenant's snapshot is forgotten at the end of sync, so the next request rebuilds it; the FE converges on its next `/auth/me` | S-1 tenant-scoped key (§4.8) + `forgetCachedPermissions()` in step 7 | PG — `PermissionCacheTenantScopingTest` (S-1's own) + `PermissionsSyncTest::sync_forgets_only_this_tenants_cache` |
| EC-9 | A tenant has **customised** its `manager` role; a deploy adds a permission whose template lists `manager` | `manager` receives **nothing**; the run reports `templates_skipped_customised=1`; `template_version` stays behind; the UI shows "N template updates not applied" with *Re-apply template* | `PermissionSyncService` step 5, `customised_at IS NOT NULL` branch (D2) | PG + vitest |
| EC-10 | The operator uses *Re-apply template* on that role | `customised_at` cleared, the template delta applied for that role only, `template_version` advanced, `RoleUpdated` audited with the diff | `RoleController` re-apply action → `PermissionSyncService` single-role path | PG + playwright |
| EC-11 | A tenant **renames a custom role** that is referenced nowhere by key | Allowed; `model_has_roles` is keyed by role id, so assignments survive | `RoleController::update`, `is_system` false branch | PG |
| EC-12 | A tenant tries to rename or delete a **system** role | 422 `SYSTEM_ROLE_PROTECTED` on both, driven by `roles.is_system`, not by a name list | `RoleController::update`/`destroy` (§4.6.1) | PG + vitest (`RolesPage` hides the actions from the payload flag) |
| EC-13 | A `roles.manage` holder `PATCH`es the **admin** role with `{"permissions": []}` | 422 `ADMIN_PERMISSION_FLOOR`; nothing written; the attempt is audited as a denial | Floor F-1 (§4.6.2), checked before `syncPermissions()` | PG — `AdminPermissionFloorTest`, including the deny path (today's `RBACTest:137-165` only exercises an allow path) |
| EC-14 | A user **switches company** | Roles are tenant-scoped (D1) so the permission set does not change; company/location scope does, and every list re-keys | unchanged behaviour; `tenantScopedKey` appends `companyId` (`apps/web/src/lib/tenantScopedKey.ts:29-34`) | vitest + playwright |
| EC-15 | A user restricted to one location is assigned `general_manager` | The role grants `treasury.manage_all_locations`; the **membership's** `allowed_location_ids` still applies to row-level reads. Action and scope are orthogonal (G5), so the user gains the action and keeps the restriction — and the Roles UI says so on the assignment dialog | `ValidateLocationAccess` + `LocationContext`, unchanged; the effective-permissions panel shows both layers (§4.6.6) | PG — `GeneralManagerLocationScopeTest` (asserts the *rows returned*, not the 200) |
| EC-16 | A principal's **last active membership** is revoked while an API token is live | Every token of that principal is revoked; the next request is 401. A revocation that leaves another active membership revokes nothing, and the removed company is denied by the `X-Company-Id` check instead | §4.1.5 rule 1; `EnforceTokenScope` (§4.1.4) | PG — `ServiceAccountRevocationTest`, both branches |
| EC-17 | A principal's roles change while an open browser tab holds a 5-minute-stale `/auth/me` | The UI may render a stale affordance for up to 5 minutes; **every mutating request is still 403**. Recorded as a known UX exposure, not a security one | server-side `can:`; FE staleness from `AuthProvider` `staleTime: 1000*60*5` | vitest (`usePermissions` renders from the stale store) + PG (the write is refused) |
| EC-18 | A token carries `permission:` abilities **wider** than the principal's grants | The extra keys grant nothing — the scope is intersected with live database grants, never unioned | `User::hasPermissionTo()` / `getAllPermissions()` (§4.1.3 rule 1) | SQLite — `TokenScopeNarrowingTest::a_scope_wider_than_the_principal_grants_nothing` |
| EC-19 | Two admins concurrently remove each other's admin role | Exactly one succeeds; the loser gets 422 `LAST_ADMIN_ROLE_REMOVAL`. `SELECT … FOR UPDATE` on the candidate rows inside the mutation transaction | `LastAdminFloorGuard` (§4.6.3 clause c) | **PG only** — a real two-connection concurrency test; mark-skips on SQLite |
| EC-20 | `permissions:sync` is run **twice concurrently** on one tenant | The second blocks on the transaction-scoped PG advisory lock, then finds nothing to do and reports `ALREADY_CURRENT` | `PermissionSyncService::acquireTenantLock` (§4.3.2), the lock shape W-LOT-A-1a establishes | **PG only** — two-connection test |
| EC-21 | `permissions:sync --tenant=x` in **compatibility mode** (`TENANCY_DB_PER_TENANT=false`) | Exit 2, `reason=compatibility_mode_is_global`; steps 1–4 run globally when invoked without `--tenant`, `templates_applied=0` | `SyncPermissions::handle` (§4.3.3) | SQLite |
| EC-22 | A **central super admin** calls a tenant route with a token carrying the `super-admin` ability | Passes `EnforceTokenTenantClaim` (`apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php:78-80`) and `EnforceTokenScope`; tenant `can:` gates still apply, because there is no `Gate::before` and none is added | unchanged (`apps/api/config/auth.php:50-53`); §4.1.4 leaves the super-admin bypass exactly where it is | PG |
| EC-23 | A route is "gated" **only** by a FormRequest whose `authorize()` returns `true` | The route counts as **uncovered** in the ratchet and must gain a `can:` or `authz.self` | E-4 (§4.4.1) + the ratchet's classification, which reads middleware only | SQLite — `RoutePermissionCoverageRatchetTest` |
| EC-24 | A contributor adds a new ungated route **and** its baseline entry in one commit | Anti-growth direction fails: the baseline key set no longer matches the CI-pinned protected blob | `RoutePermissionCoverageRatchetTest` (§4.4.3), the `DocumentPerActionBaselineRatchetTest` mechanism | SQLite + CI env var |
| EC-25 | A baselined uncovered route is **fixed** but its baseline entry is left behind | Stale direction fails: "remove this entry" | same test | SQLite |
| EC-26 | A new permission ships with **no fr or ar label** | `PermissionRegistryLabelCoverageTest` fails and names the missing keys; `permissions:export-label-skeleton` writes a complete per-locale file to edit | §4.5.2 | SQLite |
| EC-27 | A module's enum gains a case with no manifest definition (or vice versa) | `PermissionEnumManifestParityTest` fails in **both** directions | §4.2.5 | SQLite |
| EC-28 | A misconfigured integration produces hundreds of 403s per minute | At most one `AuthorizationDenied` per `(principal, permission, route)` per 5 minutes; the next emitted event carries `suppressed_count` | §4.6.5 dedup rule, Redis counter with a 5-minute TTL | PG (event emitted once) + SQLite (counter arithmetic) |
| EC-29 | A **module is disabled** for the tenant but a role still grants its permissions | The route is refused by `module:<Name>` middleware before `can:` is reached; the permission stays granted and simply cannot be exercised; `GET /permissions` hides the module's group as it does today | `RequireModule` (`apps/api/bootstrap/app.php:119`); `RoleController::permissions` filtering at `:331-334` | PG |
| EC-30 | A template mistake grants a financially decisive permission to `viewer` alongside a create permission in the same SoD group | `PermissionSodTemplateTest` fails at build time, naming the group, the template and the two keys | §4.7 | SQLite |
| EC-31 | An **offline POS operator** whose permissions were revoked server-side keeps acting on the device | The device continues on its last synced payload until it reconnects — an accepted offline residual, unchanged by this design. What changes: the operator's PIN stops being returned by `verifyPin`/`pinData`/`hasPins` the moment their membership or account goes inactive, because all three share `pinHolders()` (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-57`) | §4.10; `pinHolders()` unchanged in semantics | pos-vitest (offline continuation) + PG (all three surfaces exclude them) |
| EC-32 | A **service principal** attempts an interactive login, is impersonated, or is given a POS PIN | 401 `SERVICE_PRINCIPAL_CANNOT_LOG_IN`, impersonation refused, 422 on the PIN route — and the PIN case is additionally impossible by the §5.1 CHECK | §4.1.1 + the database CHECK | PG (all three) |

Three rows deserve a note because they are the ones most likely to be argued at a gate. **EC-15** is the row that proves action and scope stayed orthogonal — if a gate can show a case where assigning a role silently widens location access, the D1/G5 model is broken. **EC-17** is deliberately *not* fixed: closing a five-minute UI staleness window costs a websocket or a poll, and the exposure is rendering an affordance the server will refuse. **EC-31** is the only accepted-residual row; it is a property of offline POS, not of this design, and it is listed so nobody later mistakes it for a regression this lane introduced.
---

## 8. Waves and sequencing

**Laptop lane cap: 3 concurrent lanes** (owner, 2026-09-09). No wave below is planned to run more than two RBAC lanes at once, leaving one slot for whatever else is in flight. Every wave is one execution plan + one Codex adversarial gate on the plan + one reviewer gate on the diff; `tenancy-authz-reviewer` is **mandatory on every wave**, and `frontend-conventions-reviewer` is mandatory on any wave touching `apps/web`.

### Wave 0a — stop the bleeding (starts now; no dependency on any in-flight lane)

Entry condition: **none**. Every item uses permissions that already exist, touches no file that `lane/w-lot-a-1a`, `lane/t1-transfers-edge` or `lane/t2-receipt-spine` owns, and adds no migration.

| # | Task | Files | Notes |
|---|---|---|---|
| 0a-1 | **Land S-1**, the tenant-scoped permission cache | exactly the five files at `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:62-66` | Referenced, not redesigned (§4.8). It is the only P0 in the register (I-1). Its own Phase-0 precondition — prove staging is database-per-tenant and every tenant DB exists — is a deployment preflight, not a repo fact (`:52`) |
| 0a-2 | **Route-coverage ratchet + baseline** | add `apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php`, `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json`, a liveness fixture; register the protected-blob env var in CI | §4.4.3. Ceilings `UNCOVERED_WRITE_CEILING = 167`, `UNCOVERED_READ_CEILING = 148`, generated **after** 0a-3 |
| 0a-3 | **`authz.self` middleware + alias**, applied to the seven self-service routes | add `apps/api/app/Http/Middleware/AllowSelfService.php`; modify `apps/api/bootstrap/app.php:114-123` | §4.4.2. Must land **before** 0a-2's baseline is generated, or those seven routes enter the baseline and immediately go stale |
| 0a-4 | **Gate `GET /api/v1/users/{userId}/roles`** and the other three read routes | modify `apps/api/app/Modules/Identity/routes.php:59,61,64,78` | I-18 — today it returns any tenant user's full `getAllPermissions()` list to any authenticated caller (`RoleController.php:429-444`). Uses only existing keys (`roles.view`, `users.assign-roles`) |
| 0a-5 | **Gate the writes that need no new key** | modify `PromotionController` routes (×4), `Uom` routes (×5), `BatchExpiry` routes (×2), `Menu` routes (×3), `Coupon` routes (×3), `CountingItemController` route (×1) | §4.4.4. Each deletes a baseline entry from 0a-2 in the same commit — the ratchet's stale direction proves none is forgotten |
| 0a-6 | **Delete `PermissionSeeder.php`** and its one caller | delete `apps/api/database/seeders/PermissionSeeder.php`; modify `apps/api/database/seeders/ProductionSeeder.php:75` | §4.9.4. Must land **after** 0b-1 declares `credit-notes.cancel`, or the last copy of that key leaves the tree — so 0a-6 is the one 0a item sequenced after 0b |
| 0a-7 | **Fix the masking test** | modify `apps/api/tests/Feature/Document/RefundResidualTenantIsolationTest.php:136-142` | I-21. Turns the suite red until 0b-1 lands, which is the correct order and must be stated in the PR |
| 0a-8 | **Docs** | modify `docs/conventions/03-AUTHORIZATION.md` (its canonical route example at `:30-41` carries no authorization at all and no `EnforceTokenTenantClaim`); modify `.claude/commands/add-permissions.md:16-17` (prescribes a `permission:` route middleware that has never existed — `'permission:...'` route middleware count is **0**, `docs/superpowers/audits/2026-09-09-roles-permissions/01-backend-permission-model.md:85`) | I-13 |
| 0a-9 | **`RequireAnyPermission` message** | modify `apps/api/app/Http/Middleware/RequireAnyPermission.php:25` | Hard-codes *"You do not have permission to view transaction destinations."* on every caller |
| 0a-10 | **Glossary rows** for User, Role, Permission, Super admin, Support approver, and the Membership reconciliation sentence | modify `docs/glossary.md` | I-14, convention 11. The remaining rows land with the wave that introduces their concept |

Overlap check: `lane/w-lot-a-1a` modifies `RolesAndPermissionsSeeder.php`, `RoleController.php`, `UserController.php`, `usePermissions.ts`, `permissionsMap.generated.ts`, `generated.d.ts`, `RolesPage.tsx` and the tenant migrations directory. Wave 0a touches **`RoleController.php` not at all** (0a-4 edits `routes.php`), and touches none of the others. `lane/t2-receipt-spine` will edit `apps/api/app/Modules/Inventory/Presentation/routes.php` — wave 0a does not.

Reviewer gates: `tenancy-authz-reviewer` (all), plus `imports-reviewer` is **not** needed. Deploy: 0a-1 requires `permission:cache-reset` at deploy (already at `apps/api/docker/entrypoint.sh:176`); nothing else needs a deploy step. Rollback: every item is a revert of a self-contained commit; 0a-2's baseline reverts with its test.

### Wave 0b — new permissions for the remaining ungated writes

**Entry condition: `lane/w-lot-a-1a` merged into local `dev`** (D7). Reason: 0b adds permission keys to `RolesAndPermissionsSeeder::permissionNames()` and role grants to `rolePermissionGrants()`, which is the file that lane rewrites; landing both in parallel guarantees a conflict on an 871-line array and, worse, on the marked-tenant branch it introduces at `:568`.

Tasks: declare and gate `services.*` (3 keys) and `service-categories.*` (3), `channels.*` (4), `categories.*` (4), `progression.*` (2), `purchase-hub.orders.create` (1), `companies.create` (1), and **`credit-notes.cancel`** (0b-1, first, because 0a-7 depends on it). All keys land with `admin`-only template defaults except `credit-notes.cancel` (`manager`, `general_manager` — matching its siblings) and `services.*`/`service-categories.*` (`manager`, `general_manager`, because the FE alias map they replace already exposed them to managers, and removing a manager's ability to edit a service would be a functional regression, not a fix).

`POST /api/v1/companies` is the sharpest one: today `CreateCompanyRequest::authorize()` returns `true` and the controller then self-grants `MembershipRole::Owner` to the caller (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:151`), so any authenticated user in any of the seven seeded roles can create a company and become its owner. It gains `can:companies.create`, `admin`-only.

Files: the seeder array, each module's `routes.php`, `apps/web/src/hooks/permissionsMap.generated.ts` (regenerated), the label JSONs. Reviewers: `tenancy-authz-reviewer` + `frontend-conventions-reviewer`. Deploy: `tenants:seed RolesAndPermissionsSeeder`, `permission:cache-reset`, `permissions:export-frontend-map` — the sequence the T-2/T-3 spec already documents (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:420`). Rollback: revert; the added `permissions` rows are inert once the routes no longer reference them, and `permissions:prune-orphans` is not run.

### Wave 1 — catalogue as code

**Entry condition: wave 0a and 0b merged.** Deliverables: `PermissionVerb`, `PermissionDefinition`, `PermissionManifest`, `PermissionRegistry`, ~30 per-module manifests, per-module enums, `PermissionRenameMap`, `PermissionSyncService`, `permissions:sync`, `permissions:export-label-skeleton`, the `roles` migration (§5.2), the seeder shim (§4.3.4), the entrypoint change (§4.3.3), and the guards `PermissionRegistryConsistencyTest`, `PermissionEnumManifestParityTest`, `PermissionRegistryLabelCoverageTest`, `PermissionRegistryCoverageTest`, `PermissionSodTemplateTest`, `PermissionCatalogueParityTest` (transitional), plus the PHPStan rule with its ignore list.

Overlap: this wave **owns** `RolesAndPermissionsSeeder.php` end to end. It cannot run concurrently with any lane that edits that file. It must be sequenced after `lane/w-lot-a-1a`'s five-push staging protocol has completed, not merely after its merge, because that protocol asserts pre/post role-permission snapshots on staging boots (plan `:4169`) and a registry-driven sync running in between would invalidate the comparison.

Deploy: `tenants:migrate-rolling` (the `roles` columns), then `tenants:run permissions:sync`, then `permission:cache-reset`, then `permissions:export-frontend-map`. **Staging soak: one week with `SYNC_PERMISSIONS_ON_BOOT=true`**, comparing each tenant's role-permission snapshot before and after every deploy and requiring **zero** unintended diffs — this is the direct replacement for the behaviour that caused I-20, so it gets the evidence bar that lane established. Rollback: the migration's `down()` drops the four columns (refusing while any `template_key` is set, mirroring W-LOT-A-1a's `down()` contract at plan `:1234`); the seeder shim reverts to the array in the same revert commit; `permissions:sync` is additive, so a rollback leaves extra permission rows, which are inert.

### Wave 2 — role management, principals and tokens

**Entry condition: wave 1 merged and soaked.** Two lanes, runnable in parallel within the cap:

- **2a — role management hardening**: `roles.is_system` consumption, F-1 and F-2 floors, D4 read gates, `RoleCreated/RoleUpdated/RoleDeleted`, denial events with the dedup rule, `GET /users/{id}/effective-permissions`, the effective-permissions panel, `RolesPage` grouped by registry module with the template-version banner and *Re-apply template*.
- **2b — principals and tokens**: `PrincipalKind`, the `users` migration (§5.1), the six `service-accounts.*` permissions and their routes, `EnforceTokenScope`, the generalised `tokenScopePermissionNames()`, `TokenScopeData` on `/auth/me`, the `X-Company-Id` contract, revocation, and the FE service-account + token screens.

2b depends on 2a only for the audit-event plumbing (`actorTokenId`), so 2a merges first. The FE deletions of §4.5.4 (`uiAliasPermissions.ts`, the fallback) ride with **2a**, because they depend on wave 0b having made `services.*` real, not on tokens.

Reviewers: `tenancy-authz-reviewer` (both, mandatory) + `frontend-conventions-reviewer` (both). Deploy: `tenants:migrate-rolling`, `permissions:sync`, `permission:cache-reset`, `permissions:export-frontend-map`. Rollback: 2b's migration `down()` drops the three `users` columns; any issued service token becomes an ordinary token of a row that no longer declares itself a service — so **rollback must revoke every token of a `principal_kind = service` principal first**, and the `down()` refuses while any such token exists.

### Wave 3 — consistency sweep

**Entry condition: wave 2 merged.** One enforcement style (move the 130 in-controller checks and the 46 FormRequest-only gates to route middleware, deleting baseline entries as it goes); empty the PHPStan ignore list; retire `LotActionPermissionDelta` in favour of `permissions:sync` and reconcile `SystemRoleName` with `SystemRoleTemplate`; delete the `RolesAndPermissionsSeeder` static shims and point the exporter at the registry; POS legacy ladder removal and dead-`pos.*` pruning; `MembershipRole` reconciliation (remove or document `UserController.php:929` `isOwner()` as the sole authz read); `permissions:prune-orphans --confirm`; the `marketplace.admin` re-scoping ticket; the per-role regression matrix test (a table of role × action → 200/403, exercising **deny** paths, which today's `RBACTest` never does).

### Wave 4 — edge-case campaign

**Entry condition: wave 3 merged.** The §7 register driven as a journey in the real UI on a fresh tenant with a second company and a second location, with 5xx and console capture on every probe (convention 09 and the 2026-08-31 empirical-evidence rule), feeding `docs/qa/MANUAL-TESTING-LOOP.md`. A green run plus a clean `tenant:census-day-one` is the promotion precondition for the whole programme.

### Sequencing summary

```
now ──► 0a ──┬────────────────────────────────────────────────► (independent)
             │
lane/w-lot-a-1a merged ──► 0b ──► 1 ──► 2a ──► 2b ──► 3 ──► 4
                                   ▲
        lane/w-lot-a-1a staging protocol complete ┘
```

`lane/t1-transfers-edge` and `lane/t2-receipt-spine` are independent of every wave except that T-2/T-3 introduces `inventory.transfers.reconcile` and `inventory.transfers.close` into the seeder (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:398-420`). If T-2/T-3 merges **before** wave 1, the two keys are simply declared in `InventoryPermissionManifest` like any other; if **after**, wave 1's manifest already contains them and T-2/T-3's seeder edit becomes a manifest edit. Either order works; the manifest is the only thing that changes.
---

## 9. Owner rulings applied, and what is still open

### 9.1 Rulings applied (verbatim from the decision sheet, with the benchmark cell that supported each)

Source: `docs/superpowers/audits/2026-09-09-roles-permissions/06-synthesis.md:84-93`. Each row is the owner's accepted default, quoted, with the section that applies it. **None is reopened by this spec.**

| ID | Question (verbatim) | Benchmark cell (verbatim) | Ruling applied | Applied in |
|---|---|---|---|---|
| **D1** | *"Roles scoped per tenant (current) or per company?"* | Odoo: *"Groups are database-global; multi-company by record rules"*; ERPNext: *"Roles global; User Permission restricts Company"*; Dolibarr: *"Groups per entity in multicompany module"* | *"**Keep tenant-scoped**; company/location stays on membership"* | §2 (Role, Membership rows), §4.6.3 clause b, §5.2, §6 |
| **D2** | *"What happens to the 7 seeded roles when a deploy adds a permission?"* | Odoo: *"Module upgrade re-applies group definitions unless `noupdate`"*; ERPNext: *"`bench migrate` re-syncs standard DocPerms; custom perms kept separately"*; Dolibarr: *"Module re-activation re-inserts rights"* | *"**Template-delta**: seeded roles track a template version; additive deltas applied on sync unless the tenant has customised that role (flag set on first tenant edit) — then admin-only + notice in the Roles UI"* | §4.3.2 step 5, §4.6.6, §5.2, EC-9/EC-10 |
| **D3** | *"Ungated routes: deny-by-default ratchet from a shrinking baseline, or hard fail now?"* | Odoo: *"ORM denies without ACL row"*; ERPNext: *"`@frappe.whitelist` without `has_permission` caused CVEs"*; Dolibarr: *"Rights checked per page"* | *"**Ratchet with ceilinged baseline** (repo pattern), wave 0 gates the 53 writes first"* | §4.4.3 (two separate ceilings so reads never buy write headroom), §4.4.4, wave 0a/0b |
| **D4** | *"Role read endpoints: open, or `roles.view`?"* | Odoo: *"Access rights visible to admins only"*; ERPNext: *"Role Permission Manager = System Manager"*; Dolibarr: *"Admin only"* | *"**`roles.view`** for the matrix; role *names* remain readable to `users.assign-roles` holders"* | §4.6.4 — the any-of gate on the list plus a **response shaped by the gate**, which is what makes "names but not permissions" real rather than nominal |
| **D5** | *"Naming convention and outlier migration (`catalog_cart`, `pos_orders`, `uom.edit`, `deliveries.edit`)?"* | Odoo: *"`module.group_x`"*; ERPNext: *"`DocType + verb`"*; Dolibarr: *"`module->object->action`"* | *"**Freeze `kebab-resource.verb` + verb enum**; rename outliers via sync rename map"* | §4.2.2, §4.9.2 — and the deliberate DIVERGE from the audit's own G13 suggestion of Filament Shield's `{verb}_{resource}`, recorded in the §1 G13 cell |
| **D6** | *"Generalise per-user permission overrides beyond discount flags?"* | Odoo: *"Not supported (group-only)"*; ERPNext: *"User Permissions (row-scope only)"*; Dolibarr: *"Per-user rights grid"* | *"**No** for now; keep overrides to discount numerics; revisit after launch"* | §3.2, §4.6.6 (surfaced read-only in the effective-permissions view, never granted) |
| **D7** | *"Sequencing against in-flight W-LOT-A-1a (general_manager, batch permissions) and T-1..T-3 (`inventory.transfers.reconcile`)"* | — (no benchmark cell; a sequencing question) | *"Wave 0 lands **after** W-LOT-A-1a merges; the registry migration absorbs both lanes' permissions"* | §4.3.0 (the superset table), §4.9.1, §8. Refined with evidence: **wave 0a needs no such dependency** — it touches none of that lane's files — so only 0b onward waits, and wave 1 additionally waits for that lane's staging protocol, not merely its merge |
| **D8** | *"Role mutation audit + denial logging into the audit chain (not the fiscal chain)?"* | Odoo: *"Opt-in tracking"*; ERPNext: *"Version tracking mandatory on Role"*; Dolibarr: *"Security events log"* | *"**Yes**, audit events for role create/update/delete and for 403 denials on write routes"* | §4.6.5 — with two refinements the ruling did not specify and a gate will ask about: the mutation event carries the **diff**, not the resulting set; and denials are deduplicated per `(principal, permission, route)` per 5 minutes with a `suppressed_count`, because unbounded denial logging is how denial logging gets turned off |

**And the new requirement (owner, 2026-09-10):** *"later we might use the MCP through service accounts, so every user that has an MCP should also have their permissions reflected."* Applied in §4.1 in full: service accounts are principals in the `users` table with ordinary memberships and roles (§4.1.1); their MCP/API tokens narrow, never widen (§4.1.3); the enforcement point is one middleware plus the existing `User::hasPermissionTo()` intersection, so all 917 existing authorization call sites inherit it without an edit (§4.1.4); attribution is `(principal_id, token_id)` on every audit row (§4.1.5); and the MCP's discovery call is the **unchanged** `/auth/me` plus one additive `token_scope` field (§4.1.6). G16 (§1) records that this is a deliberate DIVERGE from all three reference ERPs, none of which can scope a token below its user, and a MATCH with the industry norm they depart from.

### 9.2 Open questions (four; each with a benchmark and a recommended default, and the design is written against that default)

**OQ-1 — Do service accounts count toward the last-admin floor?**
*Benchmark.* No reference ERP has the concept, so none rules. The adjacent norm is Frappe Cloud's, which withholds the `Administrator` credential from tenant customers entirely and makes `System Manager` the human ceiling — i.e. the credential that can always recover a tenant is deliberately held by a *person*, not a process ([administrator](https://docs.frappe.io/erpnext/v13/user/manual/en/setting-up/users-and-permissions/administrator)). GitHub's equivalent rule is that an organisation must retain at least one human owner; a bot account cannot be the sole owner.
*Recommended default (applied throughout §4.6.3, EC-4):* **No.** A tenant whose only administrator is a machine has nobody who can log in when the token is lost, revoked or expired. The cost of the default is that a fully-automated tenant must keep one human admin, which is the correct answer to "who do we call".

**OQ-2 — What is the default TTL for a service-account token?**
*Benchmark.* Sanctum: none by default — *"By default, Sanctum tokens never expire"* — with a global `expiration` minutes setting, a per-token `expiresAt`, and `sanctum:prune-expired` ([§Token Expiration](https://laravel.com/docs/12.x/sanctum#token-expiration)). Odoo 18 moved the other way and made expiry **mandatory for non-admins**, with a per-group `api_key_duration` ceiling ([res_users.py#L195,2350,2413](https://github.com/odoo/odoo/blob/18.0/odoo/addons/base/models/res_users.py#L2350)). ERPNext and Dolibarr have no expiry at all. GitHub fine-grained PATs default to a bounded lifetime and cap custom values.
*Recommended default:* **365 days, set explicitly at issue, with no unlimited option**, plus `sanctum:prune-expired --hours=24` on the daily schedule. Odoo 18 is the only reference system that revisited this recently and it moved toward mandatory expiry; a year is long enough that rotation is an annual chore rather than a weekly outage, and short enough that an abandoned integration's credential dies. Human PATs get **90 days**. Both are `config('sanctum')`-driven so a tenant-specific policy is a later change, not a rewrite.

**OQ-3 — Do 403 denial events go into the tenant audit chain (`audit_events`) or a separate security log?**
*Benchmark.* OWASP says to log all access-control failures and alert on repeated denials ([Access Control Cheat Sheet](https://github.com/nokia/OWASP-CheatSheetSeries/blob/master/cheatsheets/Access_Control_Cheat_Sheet.md)). ERPNext separates them: business changes go to `Version`, system events to `Activity Log`/`Error Log` ([log settings](https://docs.erpnext.com/log-settings)). Odoo Online puts platform-actor actions in a distinct, tenant-visible Admin Activity Log.
*Recommended default:* **`audit_events` for role mutations; a separate security log for denials.** A denial is not a change to the tenant's data and does not belong in a hash-chained record of what happened to that data — mixing a high-volume, adversary-influenced event stream into the audit chain gives an attacker a lever on chain volume. Denials go to a dedicated `security_events` table (or the existing log channel) with the §4.6.5 dedup rule; role mutations, which are real state changes with a real actor, go to `audit_events` beside `RoleAssigned` (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:360-372`). Note this default **adds one tenant table** not listed in §5; if the owner prefers `audit_events` for both, §5 is unchanged and the dedup rule becomes load-bearing rather than merely prudent.

**OQ-4 — After wave 1, does `SYNC_PERMISSIONS_ON_BOOT` default to `true` in production?**
*Benchmark.* Both reference systems that solved this sync it automatically: Odoo re-applies `ir.model.access.csv` on every install **and** upgrade, and ERPNext syncs DocPerms on every `bench migrate` — neither asks an operator to opt in, because a permission that exists in code and not in the database is a broken feature ([security tutorial](https://www.odoo.com/documentation/19.0/developer/tutorials/server_framework_101/04_securityintro.html), [bench migrate](https://docs.frappe.io/framework/user/en/bench/reference/migrate)). Dolibarr is the outlier that requires a human to re-activate the module, and is the one with the worst drift story.
*Recommended default:* **Yes — `true` in production, after the one-week staging soak in §8 shows zero unintended diffs.** The reason the flag exists today is that the thing it ran was destructive (I-20); once it runs an additive, template-delta sync that provably never touches a custom role or a customised system role, keeping it off would reproduce the original problem — new permissions silently missing on live tenants, which is exactly the recurring "→ 403" gap the entrypoint comment already records for `uom.view` (2026-06) and `loyalty.enroll` (2026-07) (`apps/api/docker/entrypoint.sh:156-161`). The conservative alternative — leave it `false` and run `tenants:run permissions:sync` as a named deploy step — is a real option and costs one runbook line; it is worse only because a step a human can forget is a step a human will forget.

---

## 10. Out of scope

Each item below is deliberately excluded and stays on its own ticket; none is a prerequisite for any wave.

| Item | Why out, where it lives |
|---|---|
| **Super-admin MFA** and the **TN impersonation legal gate** (E-4) | Platform-operator hardening, not tenant RBAC. `docs/superpowers/audits/2026-09-09-roles-permissions/06-synthesis.md:105` |
| **Discount policy redesign** and **cashier price override** | Deferred by the owner 2026-07-09; D6 keeps the numerics as they are, §4.6.6 only displays them |
| **Country-code mutability** | Unrelated ticket |
| **Replacing Spatie with a policy/ABAC engine** | Direction C, rejected — the benchmark shows all three reference ERPs succeed with role-based actions plus a separate scope layer, which is what exists |
| **Per-company roles** | D1 ruled against; would be a second surface for one concept (convention 11) |
| **General per-user permission overrides** | D6 ruled against |
| **A permission-creation UI** | Permissions are code (§2, Permission row). A tenant-authored permission would have no call site and no label, and would break the registry's role as the boundary of what `admin` holds |
| **OAuth2 / client-credentials flow** | Sanctum PATs cover the MCP and integration cases; an authorization-code flow is a separate lane if a third-party marketplace ever needs one |
| **Deleting orphan permission rows automatically** | §4.3.2 step 4 reports, never deletes; `permissions:prune-orphans --confirm` is wave 3 and is a deliberate operator action |
| **`marketplace.admin` platform re-scoping** | Wave 3 + ticket `docs/superpowers/tickets/2026-09-10-marketplace-admin-platform-scope.md`; unreachable today behind the module kill-switch (`apps/api/app/Modules/Marketplace/Presentation/routes.php:49`) |
| **`RoleController` `CrossTenantRoute` annotation corrections** | Owned by `lane/w-lot-a-1a`'s own ticket `docs/superpowers/tickets/2026-09-09-role-controller-team-scope-annotations.md` (plan `:1420`); this design does not touch those annotations |
| **Closing the 5-minute `/auth/me` staleness window** | EC-17 — a UX exposure, not a security one; costs a websocket or a poll, and every write is still refused server-side |

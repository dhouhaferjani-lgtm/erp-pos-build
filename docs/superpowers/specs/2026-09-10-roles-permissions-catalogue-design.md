# Roles & permissions — catalogue as code, principals & tokens, enforcement standard — design spec rev 2

Date 2026-09-10 · Lane family: RBAC (waves 0a, 0b, 1, 2, 3, 4) · Status: **REV 2, gate r1 fix round applied — for Codex adversarial gate round 2** · Direction: **B (catalogue-as-code with deploy-time sync)**, accepted by the owner 2026-09-10.

## 0. Status, base SHA, sources, decided vs open

**Base SHA.** Every `path:line` in this document was read at **`971528977`** (`git rev-parse --short HEAD` in the worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-audit`, branch `docs/rbac-audit-2026-09-09`, rebased onto `dev` as the first step of this lane, clean rebase, no conflicts). Paths are relative to the repository root `apps/erp/` unless the citation itself begins with `apps/`. No code, test or configuration file is modified by this lane — it is a document-only branch.

**Re-verification SHA (rev 2).** Codex gate r1 re-read every cited path at **`4f5d2dd46`** and confirmed the reviewed spec blob and **all reviewed application paths are byte-identical across `971528977…4f5d2dd46`** — the intervening commits are document-only (`docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r1.md`). The rev-2 fix round re-verified each applied finding against the tree at `b43660328`, again with no application-code change in between. The branch is therefore **not rebased**: `971528977` stays the declared read SHA because it still names the same code, and the two later SHAs are recorded so a gate can reproduce the check without a rebase (`git diff 971528977 b43660328 -- apps/api apps/web apps/pos packages` is empty).

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

**Vocabulary line (convention 11, `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:74-76`).** Concepts: Membership (glossary ✅, `docs/glossary.md:20` — line 16 is the table separator, corrected in rev 2), Tenant (glossary ✅), Company (glossary ✅), Location (glossary ✅), Principal (NEW — glossary row added in wave 2), User (NEW — **wave 0b**), Service account (NEW — wave 2), API token / Token scope (NEW — wave 2), Role (NEW — **wave 0b**), System role (NEW — wave 2), Custom role (NEW — wave 2), Permission (NEW — **wave 0b**), Permission verb (NEW — wave 1), Permission manifest (NEW — wave 1), Permission registry (NEW — wave 1), Grant (NEW — wave 1), Effective permissions (NEW — wave 2), Super admin (NEW — **wave 0b**), Support approver (NEW — **wave 0b**), General manager (**added by `lane/w-lot-a-1a`, which owns the row; this design adopts it verbatim and never rewrites it** — §2, B-3). *Rev 2: the six rows rev 1 put in wave 0a move to 0b, because `docs/glossary.md` is a file `lane/w-lot-a-1a` edits (B-9).*. Full rows in §2.

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

**What is open** — four questions only, each with a benchmark and a recommended default, in §9.2. **Rev 2 corrects the independence claim**: §§1–8 are written *against* each recommended default and in OQ-1's case hard-code it, so a different ruling means a bounded, named edit rather than no edit at all. Each open question now states exactly which sections change if the owner rules the other way. (The rev-1 OQ-3 was not open at all — it reopened settled D8 — and its number now carries the SoD baseline question the gate surfaced.)

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
| G5 | Action permission and data scope are two orthogonal layers | ACL + `ir.rule` record rules, multi-company as a global rule ([restrict data access](https://www.odoo.com/documentation/19.0/developer/tutorials/restrict_data_access.html)) | DocPerm + **User Permissions** (Company/Warehouse link restriction) ([user-permissions](https://docs.frappe.io/erpnext/user-permissions)) | multicompany `entity` column enforced by convention in every query ([MultiEntity_dev](https://wiki.dolibarr.org/index.php?title=MultiEntity_dev)) | already the shape: Spatie actions + `user_company_memberships` + `allowed_location_ids` + `ValidateLocationAccess` (`apps/api/bootstrap/app.php:118`) | none structurally; undocumented (I-8, I-14) | **ALREADY** — documented by the §2 glossary rows, **wave 0b** (moved from 0a: `docs/glossary.md` is claimed by `lane/w-lot-a-1a`) |
| G6 | The platform operator is categorically separate from any tenant role, and its actions are logged where the tenant can see them | `sudo()`/uid 1 superuser; Odoo Online separates DB admin from Odoo staff, both in a tenant-visible Admin Activity Log ([odoo online](https://www.odoo.com/documentation/19.0/administration/odoo_online.html)) | `Administrator` credential withheld on Frappe Cloud; `System Manager` is the tenant ceiling ([administrator](https://docs.frappe.io/erpnext/v13/user/manual/en/setting-up/users-and-permissions/administrator)) | `admin=1` flag; no platform/tenant split (self-hosted) | separate central `SuperAdmin` model on its own guard (`apps/api/config/auth.php:50-53`), **no application-authored global `Gate::before` bypass** (Spatie does register a permission `Gate::before` because `register_permission_check_method => true` at `apps/api/config/permission.php:103-107`, but it grants nothing beyond a permission check — precise wording adopted in rev 2), impersonation request/approve disjoint (`apps/api/app/Modules/SupportAccess/Presentation/routes.php:23-27,35-42`) | matches; super-admin MFA and the TN legal gate still owed | **ALREADY** for the separation; **DEFER** MFA + legal gate (E-4 ticket, §10) |
| G7 | A new user starts with zero permissions; onboarding is by curated role templates | no app access until assigned; ships User/Manager pairs per app | no roles until assigned; ships module role pairs + Role Profiles ([role-and-role-profile](https://docs.frappe.io/erpnext/role-and-role-profile)) | strictest — zero rights until each is switched on | new users are assigned a seeded role; 7 templates exist (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:578-871`) | minor | **ALREADY** |
| G8 | Create and approve on a financially significant document are never the same grant | Purchase double-validation group, distinct from create/confirm ([approvals](https://odoo-users.readthedocs.io/en/latest/purchase/purchases/rfq/approvals.html)) | Workflow transitions gated per role ([workflow](https://docs.frappe.io/erpnext/workflow)) | by convention: don't grant validate to the group that has create | partly true in practice and **already violated in the shipped templates**: `payments.reverse` is deliberately admin-only (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:264-268`, owner decision ratified 2026-07-10) and the `pos.approve_*` family exists, but the `manager` template holds `invoices.create` **and** `invoices.post` on one line (`:598`), `purchase-orders.create` + `.confirm` (`:594`), `payments.create` + `.allocate` + `.refund` (`:609`), `expenses.create` + `.post` + `.pay` (`:606`) and `inventory.adjustments.create` + `.post` (`:602`). There is **no stated rule and nothing enforcing it**; NIST INCITS 359 SSD/DSD is implemented by none of the three | rule is unwritten **and** today's `manager` template would fail it | **MATCH (lightweight)** — `sod_group` on each definition + a registry test that is a **hard rule for new resources and a shrink-only ratchet over the five baselined `manager` combinations above** (§4.7), wave 1 |
| G9 | POS manager-override per action via a step-up PIN, bound to a location | per-action Manager Approval toggles + PIN ([discounts](https://www.odoo.com/documentation/17.0/applications/sales/point_of_sale/pricing/discounts.html)) | POS Profile "Applicable for Users"; overrides via ordinary Role checks ([pos-profile](https://docs.frappe.io/erpnext/pos-profile)) | TakePOSPIN per cashier ([TakePOS](https://wiki.dolibarr.org/index.php/Module_Point_of_sale_(TakePOS))) | exists: `pos.approve_*` permissions drive approval scopes, PIN holders scoped to active members of the CompanyContext company (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-57`) | dead `pos.*` names + a legacy role ladder remain | **ALREADY** for the mechanism; **MATCH** the cleanup (§4.10), wave 3 |
| G10 | Role/permission changes, authorization denials and impersonation are mandatory audit events | field `tracking=True` is opt-in; OCA `auditlog` fills the gap; Admin Activity Log for platform actions | Version log per edit; Activity Log; impersonation always logged ([document-versioning](https://docs.frappe.io/erpnext/document-versioning)) | events/security log; denial logging not documented | role **assignment** is audited (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:360-372`, `RoleAssigned` → `audit_events`); role **create/update/delete** emit nothing (`:187-220`, `:223-266`, `:272-309`); denials emit nothing | mutations + denials missing | **MATCH** (D8) — `RoleCreated/RoleUpdated/RoleDeleted` + denial events (§4.6), wave 2 |
| G11 | The permission cache is correctly scoped and reset on every mutation and deploy | ORM registry invalidation | best-effort; raw SQL writes need `bench clear-cache` ([caching](https://docs.frappe.io/framework/user/en/guides/caching)) | largely computed per request | **single global key** `spatie.permission.cache` on the shared Redis store (`apps/api/config/permission.php:192,200`) under database-per-tenant, where each tenant DB has its own `permissions` table with its own `bigIncrements` ids; only mitigation is the boot flush at `apps/api/docker/entrypoint.sh:176` | **P0** — a tenant can be served another tenant's snapshot | **MATCH — first** — S-1 Task 1 (`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:60-140`), referenced not redesigned (§4.8), wave 0a |
| G12 | The UI renders exclusively from a server-computed effective-permission payload; no second client map | server strips `groups=`-gated views/menus/fields before serving | `bootinfo` payload; `frappe.perm.has_perm` reads it ([users-and-permissions](https://docs.frappe.io/framework/v14/user/en/basics/users-and-permissions)) | one `$user->rights` object drives both menu and enforcement | hybrid and **fail-open**: `usePermissions` checks server permissions, then falls back to a generated role map and 10 UI aliases (`apps/web/src/hooks/usePermissions.ts:193-211`); `uiAliasPermissions.ts:3-12` names five roles no seeder creates; `services.*` exists only there and the API has no `can:` at all | fail-open fallback; client-side-only authorization for `/services` | **MATCH** — server-authoritative only, delete the fallback and the alias file, make `services.*` real (§4.5), waves 0b + 2 |
| G13 | One frozen key format with a documented verb list | `<module>.group_<name>` external ids | human-readable Role names; permissions are DocPerm rows, not strings | `module->object->action` PHP chains | `resource.verb` dominant but with snake_case outliers `catalog_cart.*` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:480-485`), `pos_held_orders.*` (`:419-421`) and `pos_orders.*` beside kebab `purchase-orders.*` (`:594`), and `edit` vs `update` (`uom.edit` `:192`, `deliveries.edit` `:212`) | 5 outlier families | **MATCH (D5)** — freeze `kebab-resource.verb`, closed verb enum, rename map (§4.2, §4.9), wave 1. **DIVERGE from the audit's own G13 recommendation** of Filament Shield's `{verb}_{resource}` snake_case: adopting it would rename all 304 keys instead of 5 families, for no functional gain |
| G14 | "Log in as" is a first-class, logged, platform-only feature attributing writes to the real actor | OCA `impersonate_login`, group-gated and logged | native since v15; Activity Log; writes attributed to the impersonator ([adding-users](https://docs.erpnext.com/docs/user/manual/en/adding-users)) | not in core | exists, with a four-eyes request/approve split and impersonation columns on `audit_events` (`apps/api/app/Modules/Compliance/Domain/AuditEvent.php:58-63`) | matches | **ALREADY**; §4.1 only adds the rule that a **service** principal can never be impersonated |
| G15 | CI fails when a route lacks an explicit permission check; per-role regression matrix | ORM enforces, so any non-superuser test exercises ACLs | `@frappe.whitelist()` authorizes nothing by itself — a documented footgun with real CVE-class findings ([code security guidelines](https://github.com/frappe/erpnext/wiki/Code-Security-Guidelines)) | not formalised | **nothing**: no route-coverage ratchet, no PHPStan permission rule; nearest gates are `apps/api/tests/Architecture/AuthLifecycleTest.php` (asserts the `api`/`auth:sanctum`/`SetPermissionsTeam` stack, never an action gate) and `apps/api/tests/Feature/CountryDefaults/CentralAdminRouteInventoryTest.php` (16 central routes only); the eight registered PHPStan rules at `apps/api/phpstan.neon:33-47` contain none about permissions. 142 of 1054 routes are authenticated with no check at any layer; 51 of them are verified live writes | **P0** — the exact Frappe failure mode | **MATCH** (D3) — route-coverage ratchet with a ceilinged baseline, writes first (§4.4), wave 0a |
| **G16** | **A machine/API credential is a first-class principal whose token can be scoped *narrower* than its owner and never wider** | **NO.** `res.users.apikeys.user_id` is required with `ondelete=cascade` ([17.0 res_users.py#L2164-2173](https://github.com/odoo/odoo/blob/17.0/odoo/addons/base/models/res_users.py#L2164-L2173)); the docs say a key gives "essentially the same access to your user account" ([external_api §API keys](https://www.odoo.com/documentation/17.0/developer/reference/external_api.html#api-keys)) and all calls run under the user's normal ACL/record rules ([19.0 §Access Rights](https://www.odoo.com/documentation/19.0/developer/reference/external_api.html#access-rights)). `scope` exists since 14.0 (**not** an 18.0 addition) but is a key-*purpose* namespace (`trusted_device`), unsettable from the UI (`_generate(None, …)` [#L2266](https://github.com/odoo/odoo/blob/17.0/odoo/addons/base/models/res_users.py#L2266); issue [#161030](https://github.com/odoo/odoo/issues/161030)) and verified as `scope IS NULL OR scope = %s` ([#L2225](https://github.com/odoo/odoo/blob/17.0/odoo/addons/base/models/res_users.py#L2225)) — a NULL scope satisfies everything. Keys bypass 2FA by design ([auth_totp#L74-77](https://github.com/odoo/odoo/blob/18.0/addons/auth_totp/models/res_users.py#L74-L77)). No service-account object; 19.0 merely *recommends* a "dedicated bot user" with minimum permissions and an empty password ([19.0 §Access Rights](https://www.odoo.com/documentation/19.0/developer/reference/external_api.html#access-rights)). 18.0 added token **expiry** (`expiration_date`, per-group `api_key_duration`) | **NO.** One `api_key` + `api_secret` pair on the User doctype ([user.json v15](https://github.com/frappe/frappe/blob/version-15/frappe/core/doctype/user/user.json)); `validate_api_key_secret()` ends in `frappe.set_user(user)` and nothing else ([auth.py#L708-731](https://github.com/frappe/frappe/blob/version-15/frappe/auth.py#L708-L731)) — full role set, no per-token restriction, no expiry, no second named token. OAuth2 scopes validate only against the OAuth Client's own declared list and never map to DocType permissions ([oauth.py#L51-54,229-245](https://github.com/frappe/frappe/blob/version-15/frappe/oauth.py#L51-L54)). API keys bypass 2FA (2FA runs only in `LoginManager.login()`, [auth.py#L146-149](https://github.com/frappe/frappe/blob/version-15/frappe/auth.py#L146-L149)). No official service-account concept — only `user_type` System vs Website User ([users-and-permissions](https://docs.frappe.io/framework/user/en/basics/users-and-permissions)) | **NO.** One `api_key` column on `llx_user`, gated by the right `api→apikey→generate` ([card.php#L2100](https://github.com/Dolibarr/dolibarr/blob/21.0/htdocs/user/card.php#L2100)); `DOLAPIKEY` resolves the user then calls `$fuser->loadRights()` and `static::$user = $fuser` ([api_access.class.php#L130-133,260-266](https://github.com/Dolibarr/dolibarr/blob/21.0/htdocs/api/class/api_access.class.php#L130-L133)) — the REST API checks the identical rights constants as the UI (`hasRight('produit','lire')`). No scoping field, no expiry, no multiple keys, no service-account entity | **Sanctum has the mechanism; nothing uses it as a downscope.** `createToken(name, abilities = ['*'], expiresAt = null)` ([HasApiTokens.php#L58](https://github.com/laravel/sanctum/blob/4.x/src/HasApiTokens.php#L58)) defaults to full power; `can()` short-circuits on `'*'` ([PersonalAccessToken.php#L78-82](https://github.com/laravel/sanctum/blob/4.x/src/PersonalAccessToken.php#L78-L82)); abilities are a **parallel opt-in** check (`tokenCan`, `abilities`/`ability` middleware) that never reduces `$user->can()` — Laravel documents checking both dimensions explicitly ([§First-Party UI Initiated Requests](https://laravel.com/docs/12.x/sanctum#first-party-ui-initiated-requests)) and notes `tokenCan` "will always return `true`" on a first-party SPA session. In AutoERP the narrowing already exists but only for impersonation: `User::hasPermissionTo()`/`getAllPermissions()` intersect grants with `permission:<key>` abilities **only when** an `impersonation:` ability is present — the two overrides are at `apps/api/app/Modules/Identity/Domain/User.php:185-212` and the ability extraction they call is at `:216-241`, with the impersonation gate at `:225-234` (rev 1 cited only the extraction range for both). There is no `principal_kind`, no service account, no token-scope UI, no token expiry policy, and `X-Company-Id` has no machine contract | machine access is undesigned; the one narrowing primitive is locked to impersonation | **DIVERGE from all three ERPs, MATCH the industry norm.** The ERPs' answer — "clone a crippled user per integration" — multiplies principals, audit identities and seats to express what is really one identity with several credentials. Instead: service accounts are principals in the same `users` table (matching the ERPs' *"a machine is a user"* half), **and** the token narrows below its owner (matching GitHub fine-grained PATs — *"A token cannot grant additional access capabilities to a user"* ([managing PATs](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/managing-your-personal-access-tokens)); Stripe restricted keys, which now deprecate the unrestricted form ([Stripe API keys](https://docs.stripe.com/keys)); NIST SP 800-53r5 **AC-6** least privilege, whose wording covers "processes acting on behalf of users" ([AC-6](https://csf.tools/reference/nist-sp-800-53/r5/ac/ac-6/)); OWASP Secrets Management, "scope credentials used by the CI/CD tooling" ([cheat sheet](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html))). **Seat contrast (rev 2):** because all three ERPs express a machine as an ordinary user row, a bot consumes a paid seat in every one of them — Odoo bills per *Enterprise user* and its own 19.0 guidance to create a "dedicated bot user" therefore costs a seat; ERPNext's `user_type` System User is likewise the billable class on Frappe Cloud. AutoERP **diverges here too**: a `principal_kind = service` row is excluded from the human seat count and capped by a separate plan limit `max_service_accounts` (default 5), because charging a per-human price for an integration credential is the very thing that pushes operators to share one human account with a script — the failure mode this whole row exists to prevent. §4.1, §4.1.7, wave 2 |

**Domain norm (the one-line conclusion).** Across Odoo, ERPNext and Dolibarr a machine principal *is a user* and its API credential inherits **100 %** of that user's rights and bypasses 2FA; none of the three can scope a token below its user, and the only narrowing lever they offer is provisioning a second, deliberately crippled user. Laravel Sanctum is the outlier that *has* per-token abilities, but as an opt-in parallel check that defaults to `['*']` and does not reduce `$user->can()`. Industry practice outside ERP (GitHub fine-grained PATs, Stripe restricted keys, NIST AC-6, OWASP) is unambiguous the other way: a machine credential must be narrowable **below**, and never **above**, its owning principal. This spec takes the ERP half (a machine is a principal with roles) and the industry half (the token narrows), which is why G16 is the only DIVERGE row in the table.

**Two benchmark premises this research falsified, recorded so a gate can re-check them:** (1) Odoo's `apikeys.scope` was **not** introduced in 18.0 — it is present in 17.0 and dates to 14.0, it is not a permission scope, and it cannot be set from the UI; what 18.0 added is `expiration_date` plus a per-group `api_key_duration` ceiling. (2) The often-quoted Sanctum caveat that abilities "are not checked automatically and do not reduce the user's authorization" appears **verbatim in no Laravel docs version 8.x–13.x**; the behaviour is real and provable from `HasApiTokens`/`TransientToken` source, and the citable documentation is the "First-Party UI Initiated Requests" section, not that quote.

**Second-of-everything (convention 09, `docs/conventions/09-SECOND-OF-EVERYTHING.md:37-50`):** §6 states the obligation per wave. `roles` and `permissions` are not `CATALOGUE_TABLES` entities today and this design does not make them so (D1 keeps roles tenant-scoped by ruling) — §6 records the classification and the waiver wording the ratchet needs if either table is ever swept.
---

## 2. Vocabulary — glossary rows (convention 11)

New section **"Identity and authorization"** in `docs/glossary.md`, added by the wave that first needs each row (§8 says which). One table, one primary write path, one operator surface per noun; synonyms are declared here, never discovered.

| Term | Definition | Table / module | Canonical surface (primary write path) | Synonyms |
|---|---|---|---|---|
| **Principal** | Anything that can hold grants and act inside a tenant: a **User** (`principal_kind = human`) or a **Service account** (`principal_kind = service`). One table, one role-assignment mechanism, one audit identity. A principal is never a Spatie role and never a membership. | `users` (discriminated by `users.principal_kind`, `PrincipalKind` enum) / `Identity` | Settings → Users (humans); Settings → Service accounts (services). **Rev 2:** the primary write path is the existing `UserController` for humans and the new `ServiceAccountController` for services — rev 1 named a `UserService` that no section specified and no wave delivers, so the name is withdrawn rather than left as a phantom deliverable (a single write service over both is wave 3's `MembershipRevocationService`-style consolidation, not this design's). | actor, identity |
| **User** | A **human** principal of a tenant: has a password, may have a POS PIN, logs in at `/login`, verifies an email. `users.principal_kind = human`. | `users` / `Identity` | Settings → Users (`POST /api/v1/users`, `apps/api/app/Modules/Identity/routes.php:68`) | employee, utilisateur, operator (POS copy only) |
| **Service account** | A **machine** principal of a tenant: no password, no POS PIN, no login, no email verification, cannot be a POS PIN holder, cannot be impersonated. It carries memberships and Spatie roles exactly like a user, and acts only through API tokens. Created and managed under `service-accounts.*`. | `users` with `principal_kind = service` / `Identity` | Settings → Service accounts (`POST /api/v1/service-accounts`) | machine user, integration user, API user, bot |
| **API token** | One Sanctum personal access token minted for a principal. Carries the existing `tenant:<uuid>` claim (`apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php:84-103`) and, optionally, a **token scope**. | `personal_access_tokens` (central connection, `.claude/context/architecture.md` "Pinned-connection models") / `Identity` | Settings → Service accounts → Tokens (`POST /api/v1/service-accounts/{id}/tokens`); a human's own tokens under Profile → API tokens | PAT, API key, bearer token |
| **Token scope** | The optional narrowing list on a token, expressed as `permission:<permission-key>` ability strings — the same prefix SupportAccess already uses (`apps/api/app/Modules/Identity/Domain/User.php:237-238`). A token with no `permission:` ability is **unscoped** and carries the principal's full grants; a token with at least one is limited to that intersection. A scope can only narrow, never widen. | `personal_access_tokens.abilities` — a nullable **TEXT** column (`apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:17-21`) that Sanctum casts to an array; it is **not** a JSON column, corrected in rev 2 | token creation dialog (checkbox list built from the registry) | token abilities, token permissions |
| **Role** | A named, tenant-scoped bundle of permissions (Spatie `Role`, team = `tenant_id`, `apps/api/config/permission.php:99,134`). Assigned to principals. Two kinds: **system role** and **custom role**. | `roles`, `role_has_permissions`, `model_has_roles` / `Identity` | Settings → Roles (`apps/web/src/features/settings/RolesPage.tsx`) | rôle, profile (UI copy only) |
| **System role** | A role the platform ships and keeps in step with the catalogue: `roles.is_system = true`, `roles.template_key` names the template it tracks, `roles.template_version` records the template version last applied, `roles.customised_at` records the first tenant edit. Cannot be deleted; cannot be renamed; `admin` additionally cannot lose permissions (§4.6). Today the seven are `admin, manager, cashier, viewer, technician, operator, accountant` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:582,585,690,726,766,791,825`); lane `lane/w-lot-a-1a` adds `general_manager` as the eighth. **`roles.provisioning_source` is a different, narrower thing and is not this concept**: it is W-LOT-A-1a's per-tenant marker meaning "this tenant already carries the W-LOT-A-1a delta", constrained by a CHECK to `('w-lot-a-1a', name = 'general_manager', guard_name = 'sanctum', tenant_id IS NOT NULL)` (`docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:1182-1193`). This design **never writes, clears or widens it** (§4.3). | `roles.is_system`, `roles.template_key`, `roles.template_version`, `roles.customised_at` (new, wave 1); `roles.provisioning_source` (existing, W-LOT-A-1a) | Settings → Roles (permissions editable; name and existence are not) | seeded role, built-in role, template role |
| **Custom role** | A role a tenant created (`roles.is_system = false`, `template_key IS NULL`). Never receives template deltas; never auto-granted a new permission by sync. | `roles` | Settings → Roles → New role | tenant role |
| **Permission** | One `resource.verb` action key, declared in a module's **permission manifest** and materialised as a Spatie `Permission` row in every tenant database. Guard is always `sanctum`. The key is the contract; the row's numeric id is per-tenant and meaningless across tenants. | `permissions` (`unique(name, guard_name)`, `apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:30`) / owning module | **Write path: code only** (the owning module's manifest — permissions are never operator-created). **Operator read surface: Settings → Roles**, whose matrix lists every permission grouped by module with its en/fr/ar label, and Settings → Users → *Effective permissions*, which shows the key resolved for one principal. Convention 11 asks for one operator surface, not for an operator *write* surface. | right, ability (avoid: "ability" is Sanctum's word for a token string) |
| **Permission verb** | The action half of a permission key, drawn from a closed enum `PermissionVerb` (§4.2). Verbs are declared, not invented. | `PermissionVerb` enum / `Shared` | **Settings → Roles** — the matrix's column header is the verb's translated action label (`permissions.actions.<action>`), so every verb in the catalogue is visible to an operator there; declared in code, never edited there | action |
| **Permission manifest** | The per-module class that declares every permission that module owns, with labels, template defaults, SoD group and lifecycle metadata. The single write path for the catalogue. | `app/Modules/<Module>/Domain/Authorization/<Module>PermissionManifest.php` | code review (the manifest is source, and `permissions:sync --dry-run` is its operator-visible projection: it prints exactly what the manifest would create, rename or deprecate for a tenant) | permission declaration, permission CSV (Odoo analogue) |
| **Permission registry** | The singleton that aggregates every module's manifest into one ordered, validated catalogue, and is the only reader the sync command, the FE map exporter, the label test and the PHPStan rule consult. | `App\Shared\Domain\Authorization\PermissionRegistry` (container singleton) | **`GET /api/v1/permissions` → Settings → Roles**, which is the registry rendered: the endpoint serves `registry->byModule()` and the matrix is its only operator surface | catalogue |
| **Grant** | The link between a role and a permission (`role_has_permissions`) or between a principal and a role (`model_has_roles`). A grant is the only thing sync and the Roles UI write; permissions themselves are never granted directly to a principal in this design. | `role_has_permissions`, `model_has_roles` | **Write:** Settings → Roles (role↔permission); Settings → Users → Roles (principal↔role). **Read:** Settings → Users → *Effective permissions* (§4.6.6), whose `granted_by` field names every role contributing a key — the operator-visible answer to "where did this grant come from" | assignment |
| **Membership** *(existing row — reconciled)* | A principal's access to a **company**, with a `MembershipRole` and an `allowed_location_ids` scope. **Orthogonal to Role**: membership answers *which company and locations*, Role answers *which actions*. `MembershipRole` is **not** an authorization axis and has exactly one production authz read today (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:929` `isOwner()`); wave 3 either removes that read or documents it as the sole exception. The glossary row at `docs/glossary.md:20` is extended with this sentence — it is not replaced. (Rev 1 cited `:16`, which is the table separator.) | `user_company_memberships` / `Identity` | Settings → Users | company access |
| **Effective permissions** | What a request may actually do: `roles ∪ direct grants` (∅ by D6) **intersected with** the token scope, then further constrained by company membership and `allowed_location_ids` at the row level. Read-only, computed, never stored. Exposed at `GET /api/v1/users/{id}/effective-permissions` (§4.6) and, for the caller, in `/auth/me` (`apps/api/app/Modules/Identity/routes.php:41`). | derived | Settings → Users → *Effective permissions* panel | resolved permissions |
| **Super admin** | A **platform** operator. A separate central-DB model on its own guard (`apps/api/app/Models/SuperAdmin.php`, `apps/api/config/auth.php:50-53`), never a tenant role, never assignable from a tenant UI, with roles `super_admin \| support_approver \| defaults_editor` (`packages/shared/types/generated.d.ts:8`). **No application-authored `Gate::before` bypass exists and none is added** — the only registered `Gate::before` is Spatie's permission check (`apps/api/config/permission.php:103-107`), which is what makes §4.1.3's narrowing reach every `can:` gate and which grants nobody anything they do not hold. | `super_admins` (central DB) / central admin | `/admin/login` | platform operator, Synerivia staff |
| **Support approver** | The super-admin role that approves a tenant impersonation request; request and approve are disjoint by route (`apps/api/app/Modules/SupportAccess/Presentation/routes.php:23-27,35-42`) — the four-eyes rule. | `super_admins.role = support_approver` | `/admin/support-access` | second pair of eyes |
| **General manager** | **Adopted verbatim from `lane/w-lot-a-1a:docs/glossary.md:21`, which is the canonical row:** *"A seeded tenant role containing the revised manager grants plus company-wide lot recall and all-location Treasury authority; every active company membership held by the assignee must be unrestricted."* That last clause is a hard **invariant**, not a description: `GeneralManagerAssignmentGuard` locks every active membership of the target and refuses the assignment (and any later location change) with 422 `GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP` when any of them carries a non-null `allowed_location_ids` (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php:19-23,36-49`). Name string `'general_manager'` comes from that lane's `App\Modules\Identity\Domain\Enums\SystemRoleName::GeneralManager` (plan `:1241-1244`) and this design **reuses that enum rather than declaring a second name constant**. This design keeps the name, the grant set, the marker **and the invariant** exactly as that lane defines them, and only re-homes the *declaration* from the seeder array into the manifests (§4.9). | `roles` (`is_system = true`, `template_key = 'general_manager'`, `provisioning_source = 'w-lot-a-1a'`) | **Settings → Users** — the lane's sole assignment surface (`lane/w-lot-a-1a:docs/glossary.md:91`: *"`LotActionPermissionDelta` is the sole general-manager role-definition writer. Settings → Roles offers read-only inspection of the marked role; Settings → Users is its sole assignment surface."*). Rev 1 wrongly named Settings → Roles; corrected in rev 2. | **central manager** (the lane's declared synonym, restored in rev 2 — rev 1 substituted "directeur général", which is a French UI rendering, not the declared synonym) |

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
- No **super-admin** `Gate::before` bypass — the platform operator stays a separate central model and guard (Spatie's permission `Gate::before` stays, and is not a bypass) (`apps/api/config/auth.php:50-53`), which is what the benchmark's G6 recommends and what already exists.
- No change to the discount numerics (`can_discount`, `max_discount_percent`) beyond surfacing them in the effective-permissions view (I-17 stays a P3 ticket).
- No POS PIN model change; approval scopes keep their present semantics (§4.10).
- No new central-DB table. Everything new is a **tenant** migration (§5).

### 3.3 The automatic-inclusion guarantee, as a testable contract

> **Contract AI-1 (restated in rev 2 — the rev-1 wording was false).** Rev 1 claimed that adding a `PermissionDefinition` "and nothing else" produced enum reachability and en/fr/ar labels. It cannot: the per-module enums are separately authored classes (§4.2.5), the locale files are separate human-edited artifacts (§4.5.2), and a brand-new module also needs its manifest tagged in its service provider (§4.2.4). A test can **refuse** those omissions; it cannot synthesise them. The honest contract is **one definition plus one generator run**:
>
> **AI-1.** A module author (i) adds a `PermissionDefinition` to its `PermissionManifest` and (ii) runs `php artisan permissions:scaffold {module} {key}`, which appends the module enum case, writes the English `permissions.modules.<module>` / `permissions.actions.<action>` entries and writes fr/ar placeholders flagged `@needs-translation`. Nothing else is hand-edited. Then, for every tenant, on the next deploy:
>
> **(a) Created** — a `permissions` row with that key and `guard_name = 'sanctum'` exists in every tenant database.
> **(b) Admin** — the `admin` system role holds it.
> **(c) Template delta** — every *other* system role whose template declares a default for that key holds it, unless that role is customised in that tenant (`roles.customised_at IS NOT NULL`), in which case it is skipped and reported.
> **(d) Visible** — it appears in `GET /api/v1/permissions` grouped under its declared module, and renders in the Roles matrix with a module label and an action label in **en, fr and ar**.
> **(e) Typed** — it is reachable from PHP as a case of the module's permission enum and from TypeScript as a member of the generated `Permission` union.
> **(f) Refused** — CI fails if any of (a)–(e) cannot hold, and the failure message names the `permissions:scaffold` invocation that fixes it.

**`permissions:scaffold` — the generator that makes AI-1 true.**

```php
// apps/api/app/Console/Commands/ScaffoldPermission.php
protected $signature = 'permissions:scaffold
    {module : the owning module, e.g. Inventory}
    {key    : the permission key, e.g. inventory.transfers.close}
    {--dry-run : print the edits without writing}';
```

It is idempotent, writes exactly three artifact classes and nothing else, and refuses when the key is absent from the module's manifest (the definition is the input, never the output):

| Artifact | What it writes |
|---|---|
| `app/Modules/<Module>/Domain/Enums/<Module>Permission.php` | one `case` in registry order, named by `StudlyCase(resource without the module prefix) . StudlyCase(action)` — the exact name `PermissionEnumManifestParityTest` expects |
| `apps/web/src/locales/en/common.json` | `permissions.modules.<module>` and `permissions.actions.<action>`, value = the humanised action; **English is authoritative and complete on the same commit** |
| `apps/web/src/locales/{fr,ar}/common.json` | the same two keys with the English string as a placeholder and the key added to `storage/app/permission-labels/<locale>.todo.json`, so `PermissionRegistryLabelCoverageTest` passes structurally while the translation lane sees exactly what is owed |

The fr/ar placeholder is the one deliberate softening: blocking a backend merge on a human translation would make the label test the thing people disable. Structural coverage is CI-enforced; translation quality is a named wave-1 deliverable with an owner (§4.5.2). `ScaffoldPermissionCommandTest` asserts the generator is idempotent (a second run is a no-op) and that its output makes a fresh definition pass (a)–(e) with no hand edit.

**How each clause is proven (the executable half — no clause is a promise without a named test).**

| Clause | Proof | Lane |
|---|---|---|
| (a) | `PermissionsSyncTest::sync_creates_every_registry_permission_in_a_fresh_tenant_and_in_a_second_tenant` (PG, two real tenant databases via `ProvisionsTenantDatabases`) | wave 1 |
| (b) | `PermissionsSyncTest::admin_holds_exactly_the_registry_list_after_sync` — asserts set equality against **`PermissionRegistry::activeKeys()`**, matching what sync actually assigns (§4.3.2 step 5). Rev 1 asserted against `keys()`, which includes deprecated keys and would have made the test red the moment the first key was deprecated; corrected in rev 2. It also proves `Permission::all()` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:568`, I-19) is gone | wave 1 |
| (c) | `PermissionsSyncTemplateDeltaTest::delta_applies_to_untouched_system_role_and_skips_a_customised_one` + `::custom_roles_never_receive_a_delta` | wave 1 |
| (d) | `PermissionRegistryLabelCoverageTest` — every key has `permissions.modules.<module>` and `permissions.actions.<action>` in each of `apps/web/src/locales/{en,fr,ar}/common.json`; today 119/304 module labels and 125/304 action labels are missing in all three locales identically (I-12, `docs/superpowers/audits/2026-09-09-roles-permissions/03-frontend-pos-consumption.md:440-446`) | wave 1 |
| (e) | `PermissionEnumManifestParityTest` (PHP: every enum case ⊆ manifest, every manifest key has an enum case — the case is written by `permissions:scaffold`, not by hand) + `permissions.generated.test.ts` byte-identity check on the generated TS union | wave 1 |
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

**Invariants enforced at the model and the database, corrected in rev 2.** A `service` principal has `password IS NULL` (*not* a sentinel — the `hashed` cast at `apps/api/app/Modules/Identity/Domain/User.php:115-125` turns `'!'` into a valid hash of `!`), `email IS NULL` (*not* a synthetic `.invalid` address — `email` is already nullable with a partial unique over non-null values, and a null email is skipped by the mail paths at the source), `pos_pin IS NULL`, `email_verified_at IS NULL`, and `status IN ('active','inactive')`. The full reasoning and the CHECK expression are in §5.1.

**Ordering rule at the login boundary (load-bearing).** `AuthController::login` today looks the user up and then evaluates `Hash::check($validated['password'], $user->password)` as the *first* thing it does with that row (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:249-268`). With `password` nullable that call would receive `null` and throw before any kind check ran. So the `principal_kind` check is inserted **immediately after the user lookup and strictly before `Hash::check`**, returning 401 `SERVICE_PRINCIPAL_CANNOT_LOG_IN`; the same ordering applies on the POS PIN path in `PosAuthController` before any `Hash::check($pin, $user->pos_pin)` (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-100`). `UserController::setPosPin` (`apps/api/app/Modules/Identity/routes.php:75`) refuses a service principal with 422. Impersonation refuses it (`SupportAccess`), because impersonating a machine has no consent semantics. **Notification paths**: `User` uses `Notifiable` (`apps/api/app/Modules/Identity/Domain/User.php:49-61`), so every invitation, password-reset and notification writer additionally short-circuits on `principal_kind = service` — belt over the null-email brace, because a future notification channel might not be email.

**Impact census — every existing `users` writer that must learn about `principal_kind` (rev 2; rev 1's census was incomplete).** Each creates or mutates a `users` row and must either set `principal_kind = 'human'` explicitly or rely on the column default and be asserted to do so: self-registration (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:390-400`); DB-per-tenant provisioning (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:152-162`); user administration — create, update, deactivate, delete, restore, bulk (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:217-250,357-391,470-484,548-550,630-642,1019-1025`); the POS raw PIN update (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:295-307`); login metadata (`apps/api/app/Modules/Identity/Domain/User.php:274-281`); the data migration writer (`apps/api/database/migrations/tenant/2026_03_23_200000_fix_discount_permission_defaults.php:13`); and the seed writers (`apps/api/database/seeders/DatabaseSeeder.php:293,331`; `CoffeeShopSeeder.php:1249,1278`; `ParapharmacySeeder.php:1415,1453,1488`; `DemoPharmacySeeder.php:962,1032`; `DemoTenantSeeder.php:208,559,1513,1610,1684,1758,1832,1906,1980,2054`).

`TenantInitializationService` is **not** on this list: it creates no user, it assigns the admin role and conditionally seeds roles (`apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:183-216`). Rev 1 implied otherwise.

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

The mechanism already exists in the tree and is currently reachable only for impersonation. `User::hasPermissionTo()` and `User::getAllPermissions()` intersect the principal's grants with the token's `permission:<key>` abilities, but only when an `impersonation:` ability is also present — the overrides themselves are `apps/api/app/Modules/Identity/Domain/User.php:185-198` (`hasPermissionTo`) and `:201-212` (`getAllPermissions`); `:216-241` is `impersonationPermissionNames()`, the extraction they both call, with the `$isImpersonating` early return at `:225-234` (rev 2 corrects rev 1, which cited the extraction range as proof of the overrides). This design **generalises that gate**: the intersection applies whenever the token carries **at least one `permission:` ability**, regardless of impersonation.

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

1. **Narrowing only, never widening.** The list is intersected with the database grants — a `permission:` ability naming a key the principal does not hold grants nothing. This is already how the impersonation path behaves (`:185-198`: the scope check runs *and then* `hasPermissionToWithoutImpersonationFilter` still runs). Sanctum's own `tokenCan()` is deliberately **not** the enforcement point. `HasApiTokens` adds `tokenCan()`/`tokenCant()` and never overrides `can()`, so a token's abilities do not reduce what `$user->can()` returns; Laravel documents the consequence by telling you to check both dimensions yourself ([First-Party UI Initiated Requests](https://laravel.com/docs/12.x/sanctum#first-party-ui-initiated-requests), canonical example `$request->user()->id === $server->user_id && $request->user()->tokenCan('server:update')`), and by noting that `tokenCan` "will always return `true`" on a first-party SPA session request. Wiring the narrowing into `User::hasPermissionTo()` makes it apply to every **permission-based** idiom at once — 608 route middleware strings, 202 `->can()` calls and 107 `Gate::authorize()` calls (`docs/superpowers/audits/2026-09-09-roles-permissions/01-backend-permission-model.md:78-90`) — with no per-call-site edit, because AutoERP enables Spatie's permission Gate callback (`register_permission_check_method => true`, `apps/api/config/permission.php:103-107`) and that callback routes `Gate::before` through `checkPermissionTo()`, which dispatches dynamically to this override. Adding `tokenCan()` checks to those call sites instead would be 900+ edits and a permanently incomplete guard. **It does not, however, reach role-name idioms — see §4.1.3a, which is the correction rev 2 owes the settled intersection guarantee.**
2. **Empty list denies.** A token with `["tenant:<uuid>", "permission:"]`-shaped junk that yields an empty list denies everything rather than falling open. (Today the same code returns `[]` and denies — behaviour preserved.)
3. **`*` is not honoured.** Sanctum's wildcard ability is meaningful to `tokenCan()`, not to this intersection; a token that wants full grants simply carries no `permission:` ability. `EnforceTokenScope` (below) rejects a token carrying both `*` and a `permission:` ability with `TOKEN_SCOPE_AMBIGUOUS` (401) rather than silently picking one.
4. **Unknown keys are dropped, and the drop is visible.** A `permission:` ability naming a key absent from the registry (a permission deprecated after the token was minted) is ignored for the intersection and surfaced in `token_scope.unknown` on `/auth/me` so an operator can see the token is stale.
5. **Impersonation stays exactly as it is.** An impersonation token carries both `impersonation:` and `permission:` abilities; under the generalised rule the same intersection happens, and `getUnfilteredPermissionsForSupportAccess()` (`apps/api/app/Modules/Identity/Domain/User.php:176-179`) keeps its meaning — the support-session intersection starts from live database grants, not from the minted list.

#### 4.1.3a Which idioms the override actually narrows — and the two that it does not

Rev 1 asserted that overriding `User::hasPermissionTo()` narrows *every* authorization site. That is false for two distinct reasons, and both must be closed before a scoped token can be issued at all.

| Idiom | Narrowed? | Why |
|---|---|---|
| `can:` route middleware | ✅ | Laravel `Authorize` → Gate → Spatie `Gate::before` (`apps/api/config/permission.php:103-107`) → `checkPermissionTo()` → the override at `apps/api/app/Modules/Identity/Domain/User.php:185-198` |
| `$user->can()` / `->cant()` | ✅ | same Gate path |
| `Gate::authorize()` / `allows()` / `denies()` on a dotted permission | ✅ | same Gate path; a bare policy ability instead falls through to the policy registered at `apps/api/app/Providers/AppServiceProvider.php:270-276` |
| `hasPermissionTo()` | ✅ | direct override, `User.php:185-198` |
| `hasAnyPermission()` | ✅ | Spatie calls `checkPermissionTo()` per candidate |
| `getAllPermissions()` | ⚠️ **only for the authenticated subject** | the override at `User.php:201-212` reads the token from **the model it is called on**, via `currentAccessToken()` (`:216-241`). A `User` loaded fresh from the database carries no current token, so the call returns unnarrowed grants |
| `hasRole()` / `hasAnyRole()` / `hasAllRoles()` | ❌ **bypasses narrowing entirely** | there is no override, and a role name is not a permission key. Verified production sites below |
| Spatie `role:` middleware | n/a | not registered and not used at HEAD; the central `central_admin_role` alias (`apps/api/bootstrap/app.php:114-123`) is the separate platform guard and stays |

**(a) The role-name authorization ban.** Authorizing on a role *name* is unreachable by any token scope, unreachable by the registry, and invisible to the §4.4 ratchet. It is therefore forbidden in production code by a new PHPStan rule plus a registry test:

```php
// apps/api/app/PHPStan/Rules/ForbidRoleNameAuthorization.php
// registered in apps/api/phpstan.neon under `rules:` beside the eight existing rules (:33-41)
```

It flags `hasRole()`, `hasAnyRole()` and `hasAllRoles()` anywhere under `app/` outside `tests/`, and an ESLint companion (`no-role-name-authorization`) flags `roles.includes(…)` under `apps/web/src` and `apps/pos/src`. Both ship in **wave 1** with a **shrink-only baseline listing exactly these sites and no others** — the complete census at `971528977`, re-verified for rev 2:

| Site | Kind |
|---|---|
| `apps/api/app/Modules/Inventory/Presentation/Requests/CreateDraftCountingRequest.php:35` | `hasRole(['manager','admin'])` — the *sole* gate on creating a counting draft |
| `apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:782,866,905,975,1297,1494` | `hasRole('admin')` — the creator-or-admin bypass on six counting operations |
| `apps/api/app/Modules/POS/Domain/Services/DiscountPermissionResolver.php:39` | `hasRole(['super_admin','admin'])` — the POS admin discount ceiling |
| `apps/web/src/hooks/usePermissions.ts:244,251` | `roles.includes(role)` / `roles.includes('admin')` helpers |
| `apps/pos/src/stores/operatorStore.ts:354` | `roles.includes('super_admin') \|\| roles.includes('admin')` offline PIN-setup bypass |

Two sites are **waived with a stated reason** rather than baselined, because they authorize the *central* platform guard and never a tenant principal: `apps/web/src/features/admin/components/AdminLayout.tsx:60` and `apps/web/src/features/admin/lib/adminRolePolicy.ts:52`, both of which test `SuperAdmin.role` against a policy table, not a Spatie role. The waiver is an explicit allow-list in the rule, not an absent baseline entry.

**(b) Conversion is an entry condition of the token task, not a follow-on.** Wave 2b **may not issue a scoped token** until every baselined backend site above has been converted to a permission check. Concretely, in wave 2a:

- `CreateDraftCountingRequest` → `can:inventory.countings.create` on the route (E-4: a FormRequest is never the sole gate);
- the six `InventoryCountingController` bypasses → `can(InventoryPermission::CountingsOverrideOwnership->value)`, a new key declared in the Inventory manifest with `admin`-only template defaults, preserving today's behaviour exactly (`admin` holds it; nobody else does) while making it narrowable and auditable;
- `DiscountPermissionResolver::isAdmin()` → `can('pos.discount_unlimited')`, likewise `admin`-only by template, keeping `ADMIN_MAX_PERCENT` semantics unchanged.

The POS `operatorStore` ladder keeps its wave-3 home (§4.10 item 4) because removing it needs an offline-behaviour test and a POS release; until then it stays in the baseline with the ceiling recording it. **The gating rule is explicit: `ScopedTokenIssuanceEntryConditionTest` asserts the backend baseline is empty for the three converted files before `service-accounts.issue-token` is routable.**

**(c) Two resolutions, never one.** Rev 1 conflated "the permissions of the caller" with "the permissions of some principal an admin is reading about". They are different questions and get different methods on one new domain service:

```php
// apps/api/app/Modules/Identity/Domain/Authorization/EffectivePermissionResolver.php
final readonly class EffectivePermissionResolver
{
    /**
     * The AUTHENTICATED CALLER's effective set: the subject's live grants
     * intersected with the scope of the token this very request presented.
     * $token is the request's own token (null for a session request).
     */
    public function forSubject(User $subject, ?PersonalAccessToken $token): EffectivePermissionSet;

    /**
     * An ADMIN READ of another principal: the target's live grants, optionally
     * projected through ONE explicitly selected token of that target.
     * $tokenId is an explicit selector — never the caller's token, and never
     * an implicit "the target's token", because a principal may own several.
     * $tokenId === null means "grants as they stand, no token projection".
     */
    public function forTarget(User $target, ?int $tokenId): EffectivePermissionSet;
}
```

Rules that follow from the split, each of which a gate can check:

1. **The `getAllPermissions()` override narrows only when the model is the authenticated subject carrying `currentAccessToken()`.** That is what the code at `User.php:201-241` already does; rev 2 states it as the contract instead of leaving it as an accident, and adds an assertion to that effect.
2. **`RoleController::userRoles()` uses `forTarget($user, null)`.** Today it calls `getAllPermissions()` on a separately loaded user (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:429-437`), which by rule 1 returns unnarrowed grants — correct for an admin read, and now correct *by declaration* rather than by luck. (Its authorization and payload shape are separately tightened in §4.6.4.)
3. **`GET /users/{id}/effective-permissions` takes an optional `?token_id=` selector**, resolved through `forTarget()`; the response's `token_scope` is `null` when no selector is given, and 404s when the id names a token the target does not own. This is the answer to "which of the target's several tokens is this payload about" — the caller says which.
4. **The POS PIN payload keeps the PIN holder's own grants, unchanged.** `PosAuthController::verifyPin` resolves a PIN holder from the database and returns that principal's permissions (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-100`); by rule 1 that is *not* narrowed by the terminal's token, and that is the correct semantics — the payload answers "what may this operator approve", not "what may this terminal's credential do". Rev 1's claim that the PIN payload "gains token-scope narrowing for free" was wrong in both directions and is withdrawn. **Service accounts are never PIN holders** (§4.10 item 3 and the §5.1 CHECK), so no service principal can reach this path at all.
5. **EC-18 is extended accordingly** (§7): the scope-narrowing test now also asserts that a role-name idiom cannot be reached from production code (the PHPStan/ESLint baseline is empty for the converted files) and that `forTarget()` returns unnarrowed grants for a target with a scoped token while `forSubject()` returns the intersection for the same principal on its own request.

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
| the principal is `PrincipalKind::Service` and the token carries no `tenant:` claim | 401 `SERVICE_TOKEN_MISSING_TENANT_CLAIM` (no grandfathering — service tokens are all new; the human grandfathering at `apps/api/app/Modules/Identity/Presentation/Middleware/EnforceTokenTenantClaim.php:90-92` is untouched). **Rev 2 — this is a defence-in-depth branch, not the primary guard.** `ResolveTenancy` runs *before* authentication and selects the tenant database from that same `tenant:` ability (`apps/api/bootstrap/app.php:166-185`; `apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:114-134`), so under database-per-tenant a bearer token without the claim usually cannot load its tenant `User` at all and fails earlier with no tenant bound. The real guarantee is therefore made at **issuance**: `POST /service-accounts/{id}/tokens` **always** mints the `tenant:<uuid>` ability and there is no code path that can create a service token without it (`ServiceAccountTokenIssuanceTest` asserts it). The middleware branch remains for a token whose claim was tampered with or predates a future issuance change |
| the principal is `service` and its `status` is not `active` | 401 `PRINCIPAL_INACTIVE` |
| the principal is `service` and holds no active membership in the resolved company | 403 `SERVICE_PRINCIPAL_NO_MEMBERSHIP` |

Placing it after `EnforceTokenTenantClaim` means the tenant is already bound and the permissions team is already set (`apps/api/bootstrap/app.php:178-181`), so a membership read is on the right database. Placing it *before* `ImpersonationContext` means an impersonation session is still evaluated with the scope already validated.

**`X-Company-Id` for service accounts.** A service token has no session and no company switcher, so `CompanyContextMiddleware` (`apps/api/bootstrap/app.php:148`) must resolve the company from the `X-Company-Id` request header. Rules: the header is **required** for a service principal on any route that needs a company (absence → 400 `COMPANY_CONTEXT_REQUIRED`); the value must name a company in which the principal holds an **active** membership (otherwise 403 `SERVICE_PRINCIPAL_NO_MEMBERSHIP`); for a human principal the header keeps its current, optional behaviour. A service account is never given a "default company" — an ambiguous machine call is a bug, and a required header makes it a loud one.

#### 4.1.5 Audit attribution and revocation

Every audit event written on behalf of a request carries, in `audit_events.metadata` (`apps/api/app/Modules/Compliance/Domain/AuditEvent.php:55`), two new keys: `principal_id` (the acting `users.id`, which for a service call is the service account) and `token_id` (`personal_access_tokens.id`, or `null` for a session request). This is additive to the existing impersonation columns (`impersonator_id`, `impersonation_session_id`, `:58-60`) — an impersonated write already records the real actor, and a service write now records the real credential.

**Revocation.** Three triggers, all of which delete the principal's tokens:

1. **Membership removed** — when the principal's last active `user_company_memberships` row for a company is removed, every token of that principal whose scope can only be exercised in that company is revoked. Because tokens are not company-bound, the conservative rule is: removing the principal's **last active membership in the tenant** revokes **all** its tokens. A membership removal that leaves at least one active membership revokes nothing (the `X-Company-Id` check in §4.1.4 already denies the removed company).
2. **Principal deactivated or deleted** — all tokens revoked. This trigger already exists: `UserController::destroy` and `::deactivate` revoke tokens today (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:470-484,630-642`), and membership revocation happens inside those same paths (`:988-998`).
3. **Explicit revoke** — `DELETE /api/v1/service-accounts/{id}/tokens/{tokenId}` under `service-accounts.revoke-token`.

Revocation is a hard delete of the `personal_access_tokens` row (the model is central-connection pinned, `apps/api/app/Modules/Identity/Infrastructure/CentralPersonalAccessToken.php:11-41`, registered at `apps/api/app/Providers/AppServiceProvider.php:196-219`), and emits `ApiTokenRevoked` into the audit chain.

**Rev 2 — the hook needs a named boundary, and it is not one transaction.** Two corrections:

- **No standalone membership-removal writer exists today**; membership revocation only happens inside deletion/deactivation (`UserController.php:988-998`). Trigger 1 therefore lands as an explicit collaborator, **`App\Modules\Identity\Application\Services\MembershipRevocationService`**, which is the single call path every membership-removing writer uses (constructor-injected, `private readonly`, rule 13). "When a membership is removed" is not a rule a reviewer can check; "every caller goes through this service" is.
- **The membership mutation is tenant-side and the token delete is central-side**, so they cannot share a transaction — describing them as atomic (rev 1) was wrong. The contract is instead: commit the tenant-side membership change first, then attempt the central-side revoke **best-effort**; on failure, push a `RevokePrincipalTokens` job onto the queue with the principal id and retry with backoff, and record the pending revocation in the audit chain. The window is bounded and observable rather than hidden behind a false atomicity claim. `EnforceTokenScope`'s membership check (§4.1.4) is what makes the window harmless in practice: a token whose principal has no active membership in the resolved company is refused on the very next request regardless of whether its row is gone yet.

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
#### 4.1.7 Service principals and plan seats — a RULING, not an open question

Subscription usage counts every `users` row today (`apps/api/app/Services/PlanLimitsService.php:71-86`, enforced at `apps/api/app/Modules/Billing/Application/Services/PlanEnforcementService.php:325-330`, surfaced at `apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:88-104`). Left alone, creating a service account would silently consume a paid human seat — the gate raised this as an unanswered design question, and it is answered here rather than added to §9.2.

> **Ruling (orchestrator, 2026-09-10).** Service principals are **excluded from the human seat count** and capped by a **new plan limit `max_service_accounts`, default 5**. `PlanLimitsService::getUsage()` gains a `service_accounts` key and its `users` count gains `->where('principal_kind', 'human')`; `PlanEnforcementService` refuses a `POST /service-accounts` that would exceed `max_service_accounts` using the same shape it already applies to user seats. The benchmark contrast is recorded in §1 G16: all three reference ERPs bill a bot as a user, which is precisely what drives operators to share one human login with a script.

Wave 2b owns both edits, and the `max_service_accounts` default lands in the plan seed with the same shape the other limits use.

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
     * @param  non-empty-string       $labelKey      i18n key stem, always 'permissions.actions.<action>'.
     *                                               NOTE (rev 2, minor 5): the label is keyed by module and
     *                                               action ONLY, so two resources sharing a verb necessarily
     *                                               share one generic action label ("Create", "Post"). That is
     *                                               INTENTIONAL and matches what the Roles UI already does —
     *                                               it translates the key suffix, not a permission-specific
     *                                               string (apps/web/src/features/settings/RolesPage.tsx:37-42).
     *                                               A per-permission label would be 304 translated strings in
     *                                               three locales to say "Create" 34 times. Where a resource
     *                                               genuinely needs a distinct wording, it carries a $qualifier,
     *                                               which produces its own action key.
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
    case Close = 'close';
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
    public function isFinanciallyDecisive(): bool;  // post, reverse, void, approve, confirm, refund, allocate
}
```

**Thirty-one cases** (rev 2 adds `Close`). The list is derived from what the catalogue already uses (`view` 59, `create` 34, `update` 27, `delete` 23, `manage` 22, `post` 6, `cancel` 6, then a long tail — measured at `971528977`) plus the verbs the accepted T-2/T-3 spec needs.

**`Close` is a real case, not an alias (rev 2 correction).** Rev 1 declared `InventoryPermission::TransfersClose = 'inventory.transfers.close'` and used `PermissionVerb::Close` in the worked example while omitting `Close` from the enum and mapping `.close` onto `Complete` — three statements that cannot all compile. The accepted T-2/T-3 spec requires `inventory.transfers.reconcile` and `inventory.transfers.close` as **two independently grantable permissions** (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:398-420`), so `close` must derive from a verb of its own; `Complete` keeps its separate meaning (`inventory.transfers.complete` already exists in the catalogue, `apps/api/database/seeders/RolesAndPermissionsSeeder.php:602`) and the two would collide if `.close` mapped to it.

**`confirm` is financially decisive (rev 2 correction).** Rev 1's `isFinanciallyDecisive()` list omitted `confirm` while §4.7's `purchase-order` group named `.confirm` as the decisive half — a contradiction that would have made the SoD test silently vacuous on the very group it was written for. `confirm` is what makes a purchase order a commitment and an order a promise; it belongs in the list.

**The `manage` rule (rev 2 — the invariant is real, the "nothing violates it today" claim was false).** `Manage` means "this resource is administered as a whole" (`roles.manage`, `imports.manage`), never "and also CRUD". Rev 1 asserted that no existing resource mixes them; re-derived mechanically over the 304 keys at `971528977`, exactly **one** does: `settings` carries `settings.view`, `settings.update` and `settings.manage` together (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:549-554`). The rule is therefore stated as it actually is:

> **The `manage` invariant.** A resource may declare `Manage` **or** any of `Create`/`Update`/`Delete`, not both. The single grandfathered exception is `settings.*`, listed by name in `PermissionRegistryConsistencyTest::MANAGE_CRUD_GRANDFATHERED = ['settings']`. Any **other** resource that mixes them fails the test, and the grandfather list is shrink-only — an entry may be removed (by splitting the resource), never added.

Enforced by `PermissionRegistryConsistencyTest::manage_is_never_mixed_with_crud_on_one_resource`. It is a hard failure for every new resource from wave 1 onward; retiring the `settings` exception means deciding whether `settings.manage` subsumes `settings.update` or the two gate genuinely different surfaces, which is an owner question for wave 3, not a wave-1 rename.

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

- two `PermissionDefinition::make('Inventory', 'inventory.transfers', PermissionVerb::Reconcile, …)` and `…PermissionVerb::Close, …` entries, both `templateDefaults: ['manager', 'general_manager']` — `admin` is implicit, so no template lists it. Both verbs are cases of the closed enum (§4.2.2); `Close` derives `inventory.transfers.close` and does **not** collapse into `Complete`, which already owns `inventory.transfers.complete`;
- the two-permission AND gate is expressed on the route as `->middleware(['can:'.$reconcile, 'can:'.$close])` — stacked `can:` middleware is an AND by construction, needing no new middleware;
- the three-permission OR gate is the existing `require.any.permission` alias (`apps/api/bootstrap/app.php:120` → `apps/api/app/Http/Middleware/RequireAnyPermission.php:14-19`), unchanged.

So the catalogue expresses the accepted spec's gate without a single new primitive. Note one defect this design also fixes: `RequireAnyPermission`'s 403 body hard-codes the message *"You do not have permission to view transaction destinations."* (`apps/api/app/Http/Middleware/RequireAnyPermission.php:25`) — a copy-paste from its first caller that is now wrong on every other route. Wave 0a replaces it with a generic message plus the failing permission list in `error.details`.
### 4.3 Sync — `permissions:sync`

#### 4.3.0 The contract this must be a superset of

Lane `lane/w-lot-a-1a` (worktree `apps/erp/.worktrees/w-lot-a-1a`, plan `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md`) is in flight and lands **before** this lane (D7). Its work is **absorbed, never undone**. The five facts this design binds itself to:

1. **`roles.provisioning_source`** — `VARCHAR(32)`, nullable, default `NULL`, no FK, plus a partial unique index `roles_provisioning_source_team_unique ON roles (tenant_id, provisioning_source) WHERE provisioning_source IS NOT NULL` and a CHECK restricting any non-null value to `('w-lot-a-1a', name = 'general_manager', guard_name = 'sanctum', tenant_id IS NOT NULL)`, with equivalent SQLite triggers (plan `:1176-1217`). Migration `apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php`.
2. **`markedTenantCarriesWlota1aDelta($tenantId)`** guards the destructive reseed; a marked tenant is left completely untouched. **Rev 2 corrects the citation**: the incoming implementation is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:44-51` (plan `:1496,1503-1507`). The current-tree line `apps/api/database/seeders/RolesAndPermissionsSeeder.php:568` is only the old unguarded `syncPermissions()` call — it is what the lane replaces, not where the marker check lives.
3. **Legacy roles carry `tenant_id NULL` and are never re-homed** (`docs/handoff/CODEX-PROMPT-WLOTA-1a-ruling-legacy-null-team-roles-2026-09-09.md`). Every role read resolves `name = ? AND guard_name = 'sanctum' AND (tenant_id IS NULL OR tenant_id = :tenantId)` and **preserves `tenant_id` as found**; two matching rows is a collision, never an adoption.
4. **`LotActionPermissionDelta`** (`apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php`) is that lane's sole role-definition writer, with a `setPermissionsTeamId` / `finally`-restore boundary around a `DB::transaction` holding a transaction-scoped PG advisory lock (plan `:1382-1407`), and ten guard rails including "no force option exists" (plan `:1409-1420`).
5. The lane adds **exactly two** permission keys, `batches.recall.request` and `treasury.manage_all_locations` (plan `:1424-1429`), and one role, `general_manager` (`SystemRoleName::GeneralManager`, plan `:1241-1244`).

**How this design is a superset, clause by clause.**

| W-LOT-A-1a asset | What `permissions:sync` does with it |
|---|---|
| `roles.provisioning_source` + its CHECK and index | **Never written, never cleared, never widened.** The CHECK stays exactly as installed; sync writes `template_key`/`template_version`/`is_system`/`customised_at` only (§5), which the CHECK does not constrain. `RoleProvisioningSource` keeps its single case. |
| `markedTenantCarriesWlota1aDelta()` | Kept and **generalised, not replaced**: `PermissionSyncService` calls it as a precondition and, for a marked tenant, treats `general_manager` as already-provisioned — it never re-creates it, never re-grants `batches.recall`, and never restores `manager`'s recall grant. |
| NULL-team legacy roles | The `(tenant_id IS NULL OR tenant_id = :tenantId)` predicate and the "preserve `tenant_id` as found" rule are lifted verbatim into `PermissionSyncService::resolveRole()`. Two matching rows → `RoleCollision`, transaction rolled back, non-zero exit. **No re-homing anywhere in this design.** (The gate confirmed this predicate is sound as written: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:104-128`.) |
| `LotActionPermissionDelta` | **Rev 2 — sync does not merely coexist with the delta, it INVOKES it.** The registry owns role templates, and the retained delta becomes **template migration #1**: `PermissionSyncService` never creates `general_manager` itself and never writes the marker; when a tenant lacks the role it **calls `LotActionPermissionDelta`**, which is and remains the sole general-manager role-definition writer and the sole author of `provisioning_source = 'w-lot-a-1a'`. See §4.3.0a for why duplicating that creation was a blocker. Wave 3 retires the delta **only** once `permissions:sync` has run against every tenant and `LotActionPermissionDeltaTest` is re-pointed at the sync service. |
| its two permission keys and `general_manager` | Declared in the `BatchExpiry` and `Treasury` manifests (`batches.recall.request`, `treasury.manage_all_locations`) and in the `general_manager` template with **that lane's exact grant set** (plan `:1431-1444`). §4.9 lists this as a re-homing of the declaration only — no key, role or grant changes value. |

Two collision hazards are recorded here so a gate can check them: (i) the new `roles` columns must be added by a migration **timestamped after** `2026_09_06_205000`, and (ii) neither the new partial unique on `template_key` nor the `is_system` backfill may write `tenant_id`, or the W-LOT CHECK's `tenant_id IS NOT NULL` clause interacts with a NULL-team legacy role.

#### 4.3.0a Template migration #1, and how a legacy role is adopted (rev 2)

Gate r1 found three ways rev 1 was **not** a superset of the incoming lane. All three are closed here, and this subsection is the contract a gate should check first.

**(1) Sync never creates `general_manager`; it delegates.** Rev 1 promised never to write `provisioning_source` while also directing sync to create a missing `general_manager` (§4.3.2 step 5). Those are incompatible: the retained delta treats an existing `general_manager` *without* `provisioning_source = 'w-lot-a-1a'` as an **unmarked collision** and refuses (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:139-155`, with the CHECK and unique marker contract at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:25-42,64-75`). A tenant provisioned after wave 1 would have received an unmarked role that the delta then rejected forever.

> **Rule.** `general_manager` has exactly **one** creator in the tree: `LotActionPermissionDelta`. The registry declares the `general_manager` **template** (its grant set, from plan `:1431-1444`), and `PermissionSyncService` **invokes the delta** when the role is absent, then applies template grants to the role the delta created. `PermissionSyncServiceTest::sync_delegates_general_manager_creation_to_the_delta` asserts sync writes no `roles` row named `general_manager` itself and that `provisioning_source` is set by the delta on exactly that path. This is what "the registry owns role templates, the delta owns this role's provisioning" means concretely — one template, one writer.

**(2) Legacy adoption, on the first sync per tenant.** The `roles` migration (§5.2) defaults every existing role to `is_system = false, template_key = NULL, template_version = NULL, customised_at = NULL`. Sync must therefore decide, once per tenant, whether a pre-existing seeded role is *pristine* (safe to track) or *already customised* (must never receive a delta). Rev 1 specified no such classification, which is exactly the case D2 exists to protect.

> **Adoption rule.** On the first sync of a tenant, for **each role whose `name` matches a `SystemRoleTemplate` case** (resolved with the NULL-team-tolerant predicate):
>
> - compute the role's current grant set;
> - compare it to the **embedded legacy baseline snapshot** — a frozen, versioned copy of `RolesAndPermissionsSeeder`'s grant arrays as they stood at `971528977` (`apps/api/app/Modules/Identity/Domain/Authorization/LegacyRoleBaseline.php`, `template_version = 0`);
> - **equal** → adopt with `is_system = true`, `template_key = <name>`, `template_version = 0`, **`customised_at = NULL`**. The role is pristine and resumes receiving deltas from version 0 forward;
> - **different** → adopt with `is_system = true`, `template_key = <name>`, `template_version = 0`, **`customised_at = now()`**. The role is treated as customised: it receives **no** deltas, admin-only changes apply, and the Roles UI shows "N template updates not applied" with *Re-apply template* (§4.6.6);
> - either way the classification is **logged and counted** in the sync marker as `adopted_pristine=<n> adopted_customised=<n>`, and listed per role in `--dry-run`.
>
> Adoption is **one-way and once**: a role that already carries a `template_key` is never re-classified. Roles whose name matches no template stay `is_system = false` and are never touched (§4.3.2 step 6). `is_system` is set **only** on adoption of a template-linked role — never on a custom role, which is what the §5.2 CHECK `template_key IS NULL OR is_system` encodes.

Choosing `customised_at = now()` for the "different" branch is deliberately the **conservative** direction: it can only ever *withhold* a delta from a tenant that had in fact not customised, which surfaces as a visible banner and a one-click *Re-apply template*. The opposite error — adopting a genuinely customised role as pristine and then silently re-granting what the tenant removed — is precisely I-20 in a new costume.

**(3) The `customised_at` writer moves into wave 1.** Rev 1 deferred it to wave 2 while wave 1 already deploys and repeatedly runs sync — so during the soak the existing editor would keep calling `syncPermissions()` without marking customisation (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223-247`), and the next sync could add template grants to a role the tenant had just edited. **Ruling: the `customised_at` write in `RoleController::update` is a wave-1 deliverable**, in the same lane as sync. It is a small change — set `customised_at = now()` when a system role's permission set actually changes and `customised_at IS NULL` — and it is the entire point of the column, so shipping the column a wave before its writer was the bug.

**(4) Compatibility mode runs the same adoption.** Adoption is a DB-level classification of `roles` rows, not a template application, so it is safe and necessary in single-schema mode too. Rev 1 had compatibility mode skip every template step, which would leave every system role at `is_system = false` while wave 2 relies on `is_system` for protection and the floors (§4.6.1, §4.6.3). Corrected: in compatibility mode sync runs steps 1–4 **and the adoption pass**, then stops before applying template deltas (§4.3.3, EC-21).

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
                 templates_skipped_customised=<n> adopted_pristine=<n> adopted_customised=<n>
                 orphans=<n> reason=<token>
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

    /** Full catalogue sync for one tenant: steps 1–7 below. */
    public function sync(string $tenantId, bool $write): PermissionSyncResult;

    /**
     * Rev 2: the single-role path EC-10's *Re-apply template* action needs.
     * Runs steps 5 and 7 for ONE template only, after clearing customised_at.
     * Rev 1 referred to a "single-role path" that no signature contained.
     */
    public function syncRole(string $tenantId, string $templateKey, bool $write): PermissionSyncResult;
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

**The eight steps, in this order** (rev 2 inserts step 4a, adoption). Every step is idempotent; running the command twice in a row produces `ALREADY_CURRENT` with all counters zero.

1. **Rename.** For each `(from, to)` in the rename map where a `permissions` row named `from` exists and none named `to` exists: `UPDATE permissions SET name = :to WHERE name = :from AND guard_name = 'sanctum'`. **In place** — the primary key never changes, so every `role_has_permissions` and `model_has_permissions` row survives untouched and no grant is lost. If both `from` and `to` exist, the run is `BLOCKED` with `reason=rename_target_exists` (a human must decide which grants win); if neither exists the entry is a no-op and is reported as stale so the map can be trimmed.
2. **Create.** `Permission::firstOrCreate(['name' => $key, 'guard_name' => 'sanctum'])` for every `registry->keys()`, deprecated keys included — a deprecated permission keeps its row for one release so a custom role referencing it does not break (§4.9).
3. **Deprecate.** For every deprecated definition: the row stays, and the key is removed from **every system-role template** (step 5) but **never** revoked from a custom role. `replacedBy`, when set, is granted to every role currently holding the deprecated key — so a rename-by-deprecation preserves capability.
4. **Orphans.** A `permissions` row present in the database and absent from the registry is **reported, never deleted**. Deleting it would cascade its grants away, and a row can legitimately be a leftover from a tenant that has not yet received a deploy that removed a module. The count appears in the marker as part of `reason` and in the `--dry-run` table; wave 3 adds `permissions:prune-orphans --confirm` as a separate, deliberate action.
4a. **Adopt** (rev 2, §4.3.0a rule 2). For each role whose name matches a `SystemRoleTemplate` case and whose `template_key IS NULL`, classify against `LegacyRoleBaseline` and adopt it as pristine (`customised_at = NULL`) or as customised (`customised_at = now()`), setting `is_system = true`, `template_key` and `template_version = 0`. Counted as `adopted_pristine` / `adopted_customised`, logged per role. A role that already carries a `template_key` is skipped — adoption happens once per role, ever.

5. **Templates.** For each system-role template in `registry->templateGrants()`:
   - resolve the role with the NULL-team-tolerant predicate (§4.3.0 clause 3); if it does not exist **and the template is `general_manager`**, sync **calls `LotActionPermissionDelta`** rather than creating it (§4.3.0a rule 1) — on a marked tenant the delta is a no-op, on an unmarked one it creates the role *with* its marker, and in both cases sync then proceeds to the grant step against the role the delta owns; if any other template's role does not exist, create it with `tenant_id = :tenantId`, `is_system = true`, `template_key`, `template_version = <registry version>`;
   - **`admin` is special**: it is set to exactly `registry->activeKeys()` with `syncPermissions()`. This is the one place a full replace is correct, and it is what replaces `Permission::all()` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:568`, I-19) — after this, the catalogue is once again the boundary of what `admin` holds, and a stray row inserted out of band is no longer silently attached;
   - **every other system role is additive-only**: `givePermissionTo()` for keys the template declares and the role lacks; **no revocation ever**, except the single explicit revocation W-LOT-A-1a already owns (`manager` losing `batches.recall`), which stays that lane's, not sync's;
   - **customised roles are skipped**: if `roles.customised_at IS NOT NULL`, the role receives nothing and is counted in `templates_skipped_customised`. Its `template_version` is left at the value it had, which is what lets the Roles UI say "this role is 3 versions behind" (§4.6);
   - on success the role's `template_version` is set to the registry version.
6. **Custom roles** (`is_system = false`) are read for the orphan report and **never written**. This is B4 and D2 in one line.
7. **Cache — after commit, and rev 2 means it this time.** Rev 1 said `forgetCachedPermissions()` runs "inside the transaction's `finally`, i.e. after commit". That is wrong: a `finally` inside the `DB::transaction` callback executes **before** the callback returns and therefore before Laravel commits, leaving a window in which another request rebuilds and re-caches the *old* snapshot which then outlives the commit. The flush is registered as an **after-commit hook** — `DB::afterCommit(fn () => $this->permissionRegistrar->forgetCachedPermissions())` inside the transaction, so it fires once the commit has actually landed — and it runs **while the permissions team id is still set to this tenant**, because the S-1 key scheme (§4.8) derives the cache namespace from it. Concretely, the ordering inside `sync()` is: `setPermissionsTeamId($tenantId)` → `DB::transaction(… ; DB::afterCommit($flush))` → `finally { setPermissionsTeamId($previous) }`, so the hook has fired before the team id is restored. `PermissionsSyncTest::the_cache_is_flushed_after_commit_not_before` asserts it by observing a concurrent read on a second connection.

`--dry-run` runs steps 1–6 (adoption included) with `$write = false` inside a transaction that is rolled back, and prints the plan as a table. It is the only supported way to preview a deploy's authorization delta.

#### 4.3.3 Where it runs

**Replacing the destructive boot reseed.** `apps/api/docker/entrypoint.sh:162-170` currently runs `tenants:seed --class='Database\Seeders\RolesAndPermissionsSeeder'` across every tenant whenever `SYNC_PERMISSIONS_ON_BOOT=true` — which staging sets, so every push silently reverts every tenant's customisation of the seven seeded roles (I-20, verified `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:188`). That block is replaced by:

```sh
if [ "$SYNC_PERMISSIONS_ON_BOOT" = "true" ]; then
  if DB_HOST="$DIRECT_DB_HOST" php artisan permissions:sync-fleet; then
    echo "  Permission sync: [all tenants applied or already current]"
  else
    echo "  Permission sync: [FAILED - see PERMISSIONS-SYNC-FLEET marker]"
    printf '%s\n' "permissions_sync=failed" > /var/run/autoerp/permissions-sync.status
  fi
fi
```

**`permissions:sync-fleet` — a real fleet runner, because `tenants:run` cannot be one (rev 2, B-5).** Rev 1 proposed `php artisan tenants:run permissions:sync --force`. Three things were wrong with it:

1. **The option shape is invalid.** Under `tenants:run`, child options must be forwarded as `--option=`; a bare child flag errors. The repo documents this in the command it copied the shape from: *"The bare `--dry-run` flag is NOT valid under `tenants:run` (it errors); pass options with the `--option=` form"* (`apps/api/app/Console/Commands/SeedChartsCommand.php:31-36`), and W-LOT-A-1a proves the working shape as `--option=['apply=1']` (plan `:1573-1601,1941-1942`).
2. **`--force` does not exist** on `permissions:sync` (§4.3.1) and never did. It is **removed**, not defined: a sync that is additive, template-delta and idempotent has nothing for a force flag to override, and W-LOT-A-1a's tenth guard rail is literally "no force option exists" (plan `:1409-1420`).
3. **`tenants:run` discards child exit statuses** (plan `:2956`), and the entrypoint additionally catches migration and seed failures and keeps booting (`apps/api/docker/entrypoint.sh:150-169`). A half-synced fleet would be logged as completed while the app started serving mixed authorization state.

So the fleet runner is its own command, iterating tenants itself rather than delegating to `tenants:run`:

```php
// apps/api/app/Console/Commands/SyncPermissionsFleet.php
protected $signature = 'permissions:sync-fleet
    {--tenants=* : limit to these tenant ids or slugs; omit for every tenant}
    {--dry-run   : compute and report every tenant plan; write nothing}';
```

Its contract:

- it iterates the tenant directory, and for each tenant initialises tenancy and calls `PermissionSyncService::sync()` **directly**, so it holds the real `PermissionSyncResult` rather than a discarded exit code;
- **per-tenant failure isolation**: one tenant's `FAILED`/`BLOCKED` does not stop the run — every remaining tenant is still attempted;
- it emits one `PERMISSIONS-SYNC` marker line per tenant (§4.3.1 shape) plus a closing aggregate line `PERMISSIONS-SYNC-FLEET tenants=<n> applied=<n> already_current=<n> blocked=<n> failed=<n>` listing every non-green tenant id explicitly;
- **it exits non-zero if any tenant is `BLOCKED` or `FAILED`** — this is the property `tenants:run` cannot provide and the reason the command exists;
- `--dry-run` reports the fleet-wide plan and always exits 0 unless a tenant is unreachable.

**The entrypoint keeps booting** — refusing to start the container on a permission-sync failure would turn a partial authorization problem into a total outage — but it stops lying about it: it writes a structured failure marker, and **`/health` reports `permissions_sync: failed`** (a new key beside the existing checks) until a subsequent successful run clears it. That makes a partial fleet sync a visible, alertable state rather than a line in a boot log nobody reads. **Deploy checklist**: a new row *"after deploy, confirm `/health` shows `permissions_sync: ok`; if not, run `php artisan permissions:sync-fleet` and read the aggregate marker"* is added to the runbook alongside the existing post-deploy steps, and to §8's wave-1 deploy list.

The environment variable name is **kept** — W-LOT-A-1a's §11 evidence protocol asserts its value on staging in six places (plan `:2643,3043,3164,3755,4112`) and its deployment-variable table row at `:2367`; renaming it would invalidate that lane's evidence. What changes is only what the flag runs. The flag's default stays `false` in production until wave 1's staging soak passes (§8).

**In `tenants:migrate`.** The durable home is a listener on Stancl's tenant-migrated event so that a **newly provisioned** tenant and a **rolling-migrated** existing tenant both receive the sync in the same step that gave them their schema (`.claude/context/architecture.md`, "Migration topology" and "Lifecycle"). `apps/api/docker/entrypoint.sh:150` already runs `tenants:migrate-rolling --force` immediately before the block above, so on a normal deploy each tenant is migrated and then synced, in order, per tenant, with per-tenant failure isolation — the property the existing script comments already claim at `:145-147`.

**Provisioning.** `TenantInitializationService` keeps calling `RolesAndPermissionsSeeder` (it is invoked when roles are absent, per W-LOT-A-1a's census at plan `:372`), and the seeder now delegates (§4.3.4). A fresh tenant therefore gets the catalogue through exactly the same code path as an existing one — one write path, per convention 11.

**Compatibility mode.** In single-schema compatibility mode every tenant shares one `permissions` table (S-1's own scoping rationale, `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:66`). Steps 1–4 (rename, create, deprecate, orphan report) are tenant-independent and safe; steps 5–7 are not, because the `roles` rows are shared. So: with `TENANCY_DB_PER_TENANT=false`, `permissions:sync` **refuses `--tenant`** (exit 2, `reason=compatibility_mode_is_global`) and runs steps 1–4 **and step 4a** once globally, reporting `templates_applied=0`. **Step 4a runs here too (rev 2):** adoption is a DB-level classification of the shared `roles` rows, not a template application, and skipping it — as rev 1 did — would leave every system role at `is_system = false`, which is exactly the state wave 2's rename/delete protection and the two floors depend on (§4.6.1, §4.6.3). A compatibility-mode tenant would otherwise reach wave 2 with an unprotected `admin` role. This is stated rather than silently degraded because a silent degrade is exactly how I-20 happened.

#### 4.3.4 `RolesAndPermissionsSeeder` becomes a thin adapter

The class name, namespace and public static signatures are **preserved**. **Rev 2 corrects the census from "five call sites" to the full PHP dependency list** (minor 2): the entrypoint `--class` string (`apps/api/docker/entrypoint.sh:165`), two staging `docker exec` heredocs in W-LOT-A-1a's evidence protocol (plan `:2814`), W-LOT-A-1a's mandatory PG preservation test named after it (`RolesAndPermissionsSeederMarkedTenantTest`, plan `:1931-1932`), **and seven PHP references**: `apps/api/app/Console/Commands/ExportFrontendPermissionsMap.php:29-30`, `apps/api/database/seeders/DatabaseSeeder.php:76`, `DemoTenantSeeder.php:111`, `CoffeeShopSeeder.php:116`, `ParapharmacySeeder.php:316`, `ProductionSeeder.php:70`, and `apps/api/app/Modules/Tenant/Application/Services/TenantInitializationService.php:213-216`. Wave 1's plan must migrate or verify each of the seven, not five. W-LOT-A-1a never states a preservation rule for the name; **this spec states it**, so that a later lane cannot rename the class and quietly break the staging protocol.

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
> **E-3.** Policies are for **row-level** decisions only — "may this actor touch *this* record" — and are registered explicitly (`apps/api/app/Providers/AppServiceProvider.php:270-276`; there is no `AuthServiceProvider`, and no application-authored `Gate::before` — Spatie's permission callback is the only one registered). A policy never replaces the action gate.
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

Registered in the alias map beside the existing entries (`apps/api/bootstrap/app.php:114-123`). It rejects an unauthenticated request (401) and, for a `PrincipalKind::Service` principal, rejects `logout`-family routes with 403 `SELF_SERVICE_NOT_APPLICABLE` — a machine has no session to end. It performs no ownership check, because ownership on these routes is structural (`$request->user()`), not a lookup; a self-service route that takes an id in the path and looks a record up is **not** eligible for the marker and must carry a policy instead (E-3).

**The marker is not self-certifying (rev 2, B-7).** Rev 1 said "every addition is a reviewed diff on the alias, which is the point" — that is false. The alias is registered **once** in `apps/api/bootstrap/app.php:114-123`; every later use is an ordinary route edit, so a developer could satisfy the ratchet by writing `authz.self` on any ungated route, including one that takes another principal's id. A declaration that a mechanical guard counts as coverage must itself be mechanically constrained. Two tests do that, and **appearing in middleware is never by itself coverage**:

1. **`SelfServiceRouteAllowListTest` holds an exact allow-list of `(method, uri)` pairs** — not a count, not a prefix, the literal seven: `POST api/v1/auth/logout`, `POST api/v1/auth/logout-all`, `POST api/v1/auth/resend-verification`, `GET api/v1/auth/me`, `POST api/v1/notifications/{id}/read`, `POST api/v1/notifications/read-all`, `POST api/v1/support-access/sessions/{session}/exit`. Set equality in **both** directions: a route carrying `authz.self` that is not on the list fails, and a list entry that has lost the marker fails. Adding a route means editing this table, which is a diff a reviewer sees and a gate can argue with.

2. **`SelfServiceRouteShapeTest` is a structural check**: for every marked route, the only permitted URI parameters are the caller's own session or token id (`{session}`, `{tokenId}`) — a route parameter naming any other resource (`{id}` on a record, `{userId}`, `{companyId}`) fails **even if it is on the allow-list**, because a marker route must not be able to address another principal's row. `POST api/v1/notifications/{id}/read` is the one entry with a record id and is therefore **exempted by name with a stated reason**: the notification is looked up scoped to `$request->user()`, so the id selects among the caller's own rows and cannot address another principal's. The exemption is a named constant, not a hole in the rule.

The ratchet (§4.4.3) consults the allow-list, **not** the middleware stack, when classifying a route as self-service. A route wearing `authz.self` without an allow-list entry is classified **uncovered**, so the bypass rev 1 opened is closed at the classifier rather than only at review time.

**Tombstones are exempted by name, not reclassified as public (rev 2, minor 3).** Rev 1 described "four inert tombstones reclassified by the public allow-list". They are not public: all four are authenticated routes returning an unconditional HTTP 410 with no mutation (`docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.md:66-71`) — `POST api/v1/pos/receipts`, `POST api/v1/pos/receipts/{id}/void`, `POST api/v1/pos/receipts/{id}/payments`, `POST api/v1/pos/orders/{id}/close`. Calling them public would be a false statement about their auth stack. They get their own named classification, `TOMBSTONE`, backed by a test asserting each returns 410 unconditionally; a tombstone that ever stops returning 410 fails that test and re-enters the uncovered count.

#### 4.4.3 The route-coverage ratchet

```php
// apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php
// baseline: apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json
```

Mechanism, following the two ratchets already in the tree (`DocumentPerActionBaselineRatchetTest`, `TenantOnlyUniqueOnCatalogueTablesRatchetTest`):

- **Source of truth is the live router**, not a grep: the test boots the application and reads `Route::getRoutes()`, so a route added by any mechanism is seen. (The audit derived the same data from `php artisan route:list --json`, and Opus re-derived it byte-identically — `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:8`.)
- **Classification** per route: gated / public / self-service / tombstone / **uncovered**. A route is **gated** when its resolved middleware stack contains one of `can:`, `require.any.permission:`, `super_admin`, `central_admin`, `central_admin_role`; **public** when it is in the public allow-list; **self-service** when it appears in the §4.4.2 `(method, uri)` allow-list — *not* merely when it wears `authz.self`, so the marker cannot certify itself; **tombstone** when it is one of the four named 410 routes; **uncovered** otherwise.
- **Baseline entry key**: `"{METHODS} {uri}"` — stable across controller refactors and readable in a diff.
- **Three failure directions**, all fail-closed: **growth** (an uncovered route not in the baseline), **stale** (a baseline entry that is now covered — the entry must be deleted in the merge that fixed it, so a fix cannot be forgotten), **anti-growth** (the baseline's key set is compared against a protected blob pinned in CI, exactly as `DocumentPerActionBaselineRatchetTest` does via `DPA_BASELINE_PROTECTED_BLOB`, so a contributor cannot add a violation and its baseline entry in one change).
- **The ratchet measures a stricter thing than the audit's `AUTH_ONLY` count, deliberately.** The audit's 142 are routes with no check at *any* layer. The ratchet counts routes with no *middleware* gate, which is what makes E-2 and E-4 enforceable — a route checked only in a controller or only in a FormRequest is uncovered. Recomputed from `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv` at `971528977`: of 1054 routes, 706 carry a gating middleware (638 `MIDDLEWARE` + 68 `SUPERADMIN_ONLY`) and 18 are public, leaving **326 uncovered — 177 writes and 149 reads** (the audit's prose figure of "178 write routes with no route-level middleware" counts one tombstone this classification puts elsewhere). Those 326 decompose as 142 checked nowhere, 130 checked in a controller, 46 in a FormRequest, 8 by a policy.
- **Two ceilings** in the test file: `UNCOVERED_WRITE_CEILING` and `UNCOVERED_READ_CEILING`, both shrink-only. Wave 0a generates the baseline **after** `authz.self` and the public allow-list are applied (0a-3), which reclassifies four inert 410-tombstone writes, six self-service writes and `GET /api/v1/auth/me` — giving `UNCOVERED_WRITE_CEILING = 167` and `UNCOVERED_READ_CEILING = 148`. The two ceilings are separate so that closing reads can never buy headroom for writes — D3's "writes first". Wave 0a takes the write ceiling to ~151 and wave 0b to ~128 (rev 2: two `batches` writes moved from 0a to 0b, so 0a closes fourteen rather than sixteen); the controller- and FormRequest-checked remainder is wave 3's, which is why the initial ceilings are large and the shrink schedule, not the starting value, is the commitment.
- **New routes may never enter the baseline.** The anti-growth blob makes this mechanical; the reviewer question makes it visible. A new route with no gate fails with: *"route `POST /api/v1/x` has no action gate — add `can:<permission>` (declare it in the owning module's PermissionManifest), or `authz.self` if it acts only on the caller's own resources. New routes cannot be added to the coverage baseline."*

A **liveness fixture** ships with it (convention 08, the pattern of `TenantOnlyUniqueRatchetLivenessTest`): a fixture route registered only under the test's own service provider, asserted to be reported as uncovered — so a refactor that breaks the detector fails instead of going quiet.

#### 4.4.4 Closing the 51 verified ungated writes first

The 51 live write routes with no check at any layer (I-2; 53 flagged, minus the two `uom` false positives Opus found — `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:189`) are the top of the shrink schedule, split by whether the permission they need already exists.

Wave 0a gates only routes whose permission **already exists** in the seeded catalogue, so nothing depends on wave 1: `promotions.destroy/activate/pause/archive` → `promotions.manage` (the same controller's `store`/`update` already check it, so these four are an intra-controller inconsistency, not a policy — `apps/api/app/Modules/Promotion/Presentation/Controllers/PromotionController.php:122,146,170,194`); the `uom` writes → the existing `uom.create`/`uom.edit`/`uom.delete` (two of which are already checked in-controller, `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:189`, so this only moves the check to the route); `menus`/`menu-categories` deletes → `menus.manage`; `coupons` destroy/revoke/reactivate → `coupons.manage`; `inventory/countings/.../count` → `inventory.adjust`. **The two `batches` writes (`DELETE /batches/{uuid}` → `batches.delete`, `POST /batches/{uuid}/recall` → `batches.recall`) are wave 0b, not 0a** — their permissions do already exist, but `lane/w-lot-a-1a` rewrites `apps/api/app/Modules/BatchExpiry/Presentation/routes.php` and introduces `BatchActionAccess` there, so gating them before that merge is a guaranteed conflict (B-9, §8).

Wave 0b gates the rest, because they need keys that do not exist yet: `services.*` and `service-categories.*` (six routes, today authorized **only** by a dead FE alias map — I-22), `channels.*` (six), `categories.*` (four), `progression.*` (four), `purchase-hub.orders.create`, `POST /companies` (which today self-grants `MembershipRole::Owner` at `apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:151` behind a FormRequest returning `true`), and the platform lookup endpoints. Each new key is declared in its module's manifest with `admin`-only template defaults, then widened by an explicit template declaration where the owner rules it should be.

### 4.5 Static and CI guards

#### 4.5.1 PHPStan: no permission literals outside the catalogue

```php
// apps/api/app/PHPStan/Rules/ForbidPermissionStringLiteral.php
// registered in apps/api/phpstan.neon under `rules:` beside the eight existing rules (:33-41)
```

Flags **any** dotted lowercase string literal matching the permission-key shape `^[a-z][a-z0-9-]*(\.[a-z][a-z0-9_.-]*)+$` when it appears as: an argument to `->can()`, `->cant()`, `->hasPermissionTo()`, `->hasAnyPermission()`, `Gate::authorize()`, `Gate::allows()`, `Gate::denies()`, or inside a `->middleware([...])` / `middleware('can:…')` / `middleware('require.any.permission:…')` string.

**Two error classes, and the second is the important one (rev 2, M-5).** Rev 1 flagged only literals *already present in* `PermissionRegistry::keys()`, which lets the single most damaging mistake through: a typo such as `invoices.posst` is absent from the registry and would therefore have **passed** — while at runtime Spatie 6.25 swallows `PermissionDoesNotExist` in `checkPermissionTo`, so the route silently 403s for everyone including `admin` (exactly the `credit-notes.cancel` failure at §4.9.3). So:

| Literal | Verdict | Message |
|---|---|---|
| in the registry | **error**, use the enum | *"permission literal 'inventory.transfers.reconcile' — use `InventoryPermission::TransfersReconcile->value`."* |
| **not** in the registry | **error**, unknown key | *"unknown permission literal 'invoices.posst' — it is in no PermissionManifest, so this gate denies every caller including admin. Declare it, or fix the typo."* |

Restricting the shape match to authorization *call sites* (rather than to any dotted string anywhere) is what keeps the rule free of false positives on unrelated dotted strings such as config keys or i18n keys, so the registry lookup is no longer load-bearing for precision.

**Route files need a second guard, because PHPStan cannot see them.** `apps/api/phpstan.neon:5-8` analyses `app/` only, so the rule reaches module `routes.php` files under `app/Modules/*/Presentation/` but **not** the top-level `routes/` directory. Rather than widen PHPStan's paths (which pulls unrelated files into level 8 and would need its own baseline), a companion architecture test **`RoutePermissionLiteralTest`** parses every `app/Modules/*/Presentation/routes*.php` **and** every file under `routes/` with `nikic/php-parser` (already a PHPStan dependency), extracts each `can:` / `require.any.permission:` middleware argument, and asserts every extracted key is in `PermissionRegistry::activeKeys()`. It is an AST walk, not a grep, so a key built by concatenation or held in a variable is reported as unresolvable rather than silently skipped.

**The wave-1 ignore list is acknowledged as large, and that is the honest cost.** 608 route middleware strings and 309 call sites exist today, so the rule ships with a generated shrink-only baseline — rev 1's phrase "without a mass baseline" was wrong and is withdrawn. What the baseline buys is that the door closes behind the existing sites: a *new* literal is an error on day one, and the list only shrinks (wave 3 empties it).

Allowed call sites, encoded in the rule: `app/Modules/*/Domain/Authorization/*PermissionManifest.php`, `app/Modules/*/Domain/Enums/*Permission.php`, `app/Shared/Domain/Authorization/*`, `database/seeders/RolesAndPermissionsSeeder.php` (the compatibility shim, §4.3.4), `app/Console/Commands/SyncPermissions.php`, and everything under `tests/`. Error message names the enum case to use: *"permission literal 'inventory.transfers.reconcile' — use `InventoryPermission::TransfersReconcile->value`."*

A fixture pair under `apps/api/tests/PHPStan/Fixtures/` plus `ForbidPermissionStringLiteralTest` (the shape of `ForbidFixedScaleQuantityLiteralRuleTest`) proves the rule fires on **both** error classes and does not over-fire on config or i18n keys. The same fixture pattern covers `ForbidRoleNameAuthorization` (§4.1.3a).

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

> **Floor F-2 (rev 2 — definition tightened and the missing writer added).** Let *A* = the set of principals in the tenant that are **all** of: `principal_kind = human`, `status = active`, holding the role whose `template_key = 'admin'`, **and holding at least one active `user_company_memberships` row**. Any operation that would make `|A| = 0` is refused with 422.
>
> The membership clause is rev 2's correction: rev 1 counted a human admin with no active membership, but such a principal cannot reach any company-scoped surface — company access is membership-based (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:147-155`) — so counting them satisfies the floor with a principal who cannot in fact recover the tenant. *A* is a set of principals who can actually log in **and** get somewhere.

**Every writer that can empty *A*, and the single service they all call.** Rev 1 enumerated four operations and missed a live one. The complete list, each verified in the tree:

| # | Operation | Current writer | Status in rev 1 |
|---|---|---|---|
| 1 | `DELETE /api/v1/users/{userId}/roles` → `LAST_ADMIN_ROLE_REMOVAL` | `RoleController::removeRole` (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:390-410`) | listed |
| 2 | `POST /api/v1/users/{id}/deactivate` → `LAST_ADMIN_DEACTIVATION` | `UserController::deactivate` (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:630-642`) | listed |
| 3 | `DELETE /api/v1/users/{id}` → `LAST_ADMIN_DELETION` | `UserController::destroy` (`:470-484`) | listed |
| 4 | `DELETE /api/v1/roles/{id}` on the admin role | refused by `is_system` (§4.6.1) | listed |
| 5 | **`PATCH /api/v1/users/{id}` with a different `role`** → `LAST_ADMIN_ROLE_REMOVAL` | **`UserController::update`**, which accepts `role` (`apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php:44-62`) and calls `syncRoles([$validated['role']])` (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:380-389`) — a **wholesale replacement** that removes the admin role without ever touching `removeRole` | **MISSED** |
| 6 | `RoleController::update` emptying `admin`'s permissions | Floor **F-1** (§4.6.2) | listed |
| 7 | Membership removal that strips the last admin's last active membership | `MembershipRevocationService` (§4.1.5) | implied only |
| 8 | Impersonated calls to any of the above | reach the same controllers, so the centralised floor covers them | implied only |
| 9 | `permissions:sync` | `admin = activeKeys()` preserves the floor once adoption (§4.3.0a) is correct | listed |

> **One service, invoked by every writer.** `App\Modules\Identity\Domain\Authorization\LastAdminFloor` is the sole implementation, constructor-injected (`private readonly`, rule 13) into `RoleController`, `UserController` and `MembershipRevocationService`. It is **not** middleware — the decision needs the resolved target row — and it is **not** duplicated per writer, because nine call sites with nine copies of a rule is how #5 came to be missing in the first place. `LastAdminFloorWriterCensusTest` asserts, by static analysis, that every production call to `syncRoles`, `removeRole`, `assignRole`, user deactivate/delete/restore and membership revocation is preceded by a `LastAdminFloor` call — the same census shape `GeneralManagerAssignmentWriterCensusTest` uses in `lane/w-lot-a-1a`.

Three clarifications the definition needs to be unambiguous. **(a)** Service principals do **not** count toward *A* — a tenant whose only admin is a machine has nobody who can log in and fix a broken token (OQ-1, §9.2, recommended default, applied). **(b)** The floor is **per tenant**, not per company, because roles are tenant-scoped (D1) — a tenant with two companies has one admin population. **(c)** The check runs **inside** the same transaction as the mutation, and it takes locks in a **deterministic order — `SELECT … FOR UPDATE` over the candidate `users` rows ordered by `users.id` ascending**, then their `model_has_roles` pivots. The ordering is stated because it is the whole mechanism: two concurrent admins each removing the other take the same two row locks in the same sequence, so one blocks and then re-evaluates *A* against committed state and is refused (EC-19); an unspecified "candidate rows" lock order is a deadlock, not a floor. The same ordering applies to concurrent deactivation (EC-20).

#### 4.6.4 Read gates (D4)

| Route | Today | After |
|---|---|---|
| `GET /api/v1/roles` (`apps/api/app/Modules/Identity/routes.php:59`) | no gate | `require.any.permission:roles.view,users.assign-roles` — a role-**assigner** must be able to list role names |
| `GET /api/v1/roles/{id}` (`:61`) | no gate | `can:roles.view` — the detail carries the permission set |
| `GET /api/v1/permissions` (`:64`) | no gate | `can:roles.view` |
| `GET /api/v1/users/{userId}/roles` (`:78`) | **no gate; returns any tenant user's full `getAllPermissions()` list to any authenticated caller** (`RoleController::userRoles`, payload at `:429-437`) — I-18 | `require.any.permission:roles.view,users.assign-roles`, **with the payload shaped by the gate** (below) |
| `GET /api/v1/users/{id}/effective-permissions` | new | the caller is the subject, **or** `can:roles.view`. `users.assign-roles` alone is **not** sufficient |

**Shaping, not merely gating — and rev 2 extends it to `userRoles` (M-3).** Rev 1 gated the roles *list* by shape but left `userRoles` and the new effective endpoint open to a bare `users.assign-roles` holder, which contradicts D4: that ruling gives such a caller role **names**, not the permission matrix. Three concrete shapes:

| Endpoint | `users.assign-roles` only | `roles.view` |
|---|---|---|
| `GET /roles` | `{id, name, is_system, users_count}` per role | the above **plus** `permissions` |
| `GET /users/{userId}/roles` | **`{user_id, roles: [{id, name, is_system}]}` — role names only, the `permissions` array is absent** (rev 1 left today's full `getAllPermissions()` payload in place for this caller) | the above plus `permissions` |
| `GET /users/{id}/effective-permissions` | **403** unless the caller *is* the subject | full payload (§4.6.6) |

The effective-permissions payload is the most sensitive read in this design — complete permissions, role provenance, discount numerics, company scope, location ids and token scope — so it requires `roles.view` for any subject other than self. A role **assigner** needs to know which roles exist and which the target holds; it does not need the resolved matrix to do its job, and D4 says so.

**Response field name.** The `userRoles` payload keeps its existing envelope key `data.roles` and gains nothing for the narrow caller; the effective endpoint's role list is `data.roles` with the fuller row shape shown in §4.6.6. Both are produced from `EffectivePermissionResolver::forTarget()` (§4.1.3a), so "which token is this about" has one answer in one place, addressed by the optional `?token_id=` selector.

The misleading comments at `apps/api/app/Modules/Identity/routes.php:58` (*"Role management (requires roles.view or roles.manage permission)"*) and `:77` (*"User role assignment (requires users.assign-roles permission)"*) become true statements rather than being deleted.

#### 4.6.5 Audit events (D8)

Three new domain events beside the existing `RoleAssigned`/`RoleRemoved` (`apps/api/app/Modules/Identity/Domain/Events/`), consumed by the same subscriber that already writes `audit_events` (`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php`):

**They extend `DomainEvent` (rev 2, M-4).** Rev 1's sketches were plain `final readonly class`es with no base, no `getEventName()` and no `getAuditPayload()` — which the existing subscriber cannot consume: `DomainEventSubscriber` accepts `DomainEvent` and explicitly registers each class (`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1181-1209,1236-1262`), and the sibling events already in the tree follow that shape exactly (`apps/api/app/Modules/Identity/Domain/Events/RoleAssigned.php:14-43`, `RoleRemoved.php:14-43`). So:

```php
final class RoleCreated extends DomainEvent
{
    /** @param list<string> $permissions */
    public function __construct(
        public readonly int $roleId, public readonly string $roleName, public readonly array $permissions,
        public readonly string $companyId, public readonly string $actorUserId,
        public readonly ?string $actorTokenId, public readonly string $occurredAtIso,
    ) { parent::__construct((string) $roleId); }

    public function getEventName(): string { return 'identity.role.created'; }

    /** @return array<string, mixed> */
    public function getAuditPayload(): array;
}

final class RoleUpdated extends DomainEvent      // 'identity.role.updated'
{
    /** @param list<string> $added @param list<string> $removed */
    public function __construct(
        public readonly int $roleId, public readonly string $roleName, public readonly ?string $previousName,
        public readonly array $added, public readonly array $removed,
        public readonly string $companyId, public readonly string $actorUserId,
        public readonly ?string $actorTokenId, public readonly string $occurredAtIso,
    ) { parent::__construct((string) $roleId); }
}

final class RoleDeleted extends DomainEvent { /* 'identity.role.deleted'; roleId, roleName, permissions (final set), actor…, occurredAtIso */ }
```

**Stable names, registered subscriber, immutable forever.** The three names are `identity.role.created`, `identity.role.updated`, `identity.role.deleted` — the `identity.role.*` family the tree already uses for `identity.role.assigned` / `identity.role.removed`. Each class is added to `DomainEventSubscriber`'s explicit registration list in the same commit that introduces it; an event class the subscriber does not register writes no audit row, silently, which is why `RoleMutationAuditTest` asserts a row lands for each of the three rather than asserting the event was dispatched. Per CLAUDE.md rule 8 these names and shapes are frozen on merge: a later change is `RoleUpdatedV2`, never an edit.

`RoleUpdated` carries the **diff**, not the resulting set — a 300-entry `permissions` array in every audit row is unreadable, and the question an auditor asks is "what changed". The diff is computed before `syncPermissions()` runs, inside the same transaction. `actorTokenId` is `personal_access_tokens.id` or null (§4.1.5).

**Attribution is added once, at the audit chokepoint (rev 2, M-4).** Rev 1 put `(principal_id, token_id)` "on every audited write" without naming where. Today the subscriber supplies only `event_class` and `occurred_at` (`apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1194-1208`) and `AuditService::record()` adds impersonation metadata but no token id (`apps/api/app/Modules/Compliance/Services/AuditService.php:43-84`). Putting the pair on each event class would mean touching every event forever and would still miss the ones written directly through `AuditService`. So the pair is composed **inside `AuditService::record()`**, in the same `array_filter` that already assembles `impersonator_id` / `impersonation_session_id`: `principal_id` from the authenticated user and `token_id` from `currentAccessToken()`, both null-safe for console and queued contexts (where there is no request and both are simply absent). One edit, one place, and every existing audited write inherits it — the same argument §4.1.3 makes for narrowing at `hasPermissionTo()`. `AuditAttributionTest` asserts the pair lands for a session write, a token write and an impersonated write, and is absent (not empty-string) for a queued write.

**403 denial events — on `audit_events`, per settled D8 (rev 2, B-8).** A denial event is emitted when a `can:`/`require.any.permission` middleware or a `Gate::authorize` refuses a request on a **write verb** route. Reads are excluded deliberately: a UI that renders from a stale permission set produces read 403s in bulk, and drowning the audit log is how denial logging gets switched off. Payload: `{route_name, method, uri_template, permission, principal_id, token_id, company_id, ip}` — never the request body, which can carry money, PII or a PIN.

Denials are written as an `AuthorizationDenied` domain event onto **`audit_events`**, beside `RoleAssigned` and the three role-mutation events. **There is no `security_events` table**: rev 1's OQ-3 proposed one and simultaneously admitted it was missing from §5, which reopened a settled ruling and made the schema section untrue. D8 settled this — denials go on the audit chain — so §5's statement that this design adds exactly two tenant migrations and no other table stands unqualified, and OQ-3 is deleted from §9.2 rather than answered.

The volume objection rev 1 raised against the audit chain is real, and it is what the dedup rule is for; that rule is therefore **load-bearing, not merely prudent**, and is specified as an implementable algorithm rather than a sentence:

> **Dedup/rate rule (rev 2 — two Redis keys, because one expiring counter cannot do it).** Rev 1 specified a single five-minute counter and then asked the *next* emitted event to carry the previous window's suppressed count — impossible, because when the key expires its count is gone with it. The mechanism uses **two keys per `(principal_id, permission, route_name)` triple**, in the tenant's Redis store:
>
> - **`authz:deny:lock:<hash>`** — an emission lock, `SET … NX EX 300`. Acquiring it means "emit now"; failing to acquire means "suppress".
> - **`authz:deny:count:<hash>`** — a retained counter, `INCR` on every suppression, with a **rolling 24-hour TTL refreshed on each write** so it deliberately **outlives** the 5-minute lock. When the next emission wins the lock, it reads and `DEL`s this counter and carries its value as `suppressed_count` on the emitted event.
>
> So a storm is visible as one row saying "and 412 more" rather than as 413 rows or as silence, and a quiet 24 hours drops the counter naturally. A denial refused by the **token scope** rather than by the principal's grants is tagged `cause: token_scope` — computed by asking `EffectivePermissionResolver::forSubject()` whether the principal would hold the key with an unscoped token — because that distinction is the first question anyone debugging an MCP integration will ask.
>
> **Proved on Redis, not on SQLite.** `AuthorizationDenialDedupTest` is a **Redis integration test** (the fast lane cannot express `SET NX EX` semantics or TTL divergence, and rev 1's "SQLite counter arithmetic" would have asserted a different algorithm than the one shipping). It asserts: one emission per window; the suppressed count survives lock expiry; the count is carried and cleared exactly once; and two different triples do not share state.

#### 4.6.6 Effective permissions

```
GET /api/v1/users/{id}/effective-permissions[?token_id=<id>]
```

The optional **`token_id` selector** (rev 2, M-1 question 3) resolves the ambiguity rev 1 left: a principal may own several tokens, so a payload with one `token_id` field and no way to choose is undefined. Omitting the parameter returns the target's grants with **no token projection** (`token_scope: null`); supplying it projects through exactly that token and 404s if the id names a token the target does not own. Reading one's own permissions (`{id}` = the caller) with no selector returns the **caller's own request token** projection, because that is the question "what can I do right now" — the `forSubject()`/`forTarget()` split in §4.1.3a is what keeps these two readings from being confused.

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

> **SoD-1.** For every `sod_group` in the registry, no **non-admin** system-role template may hold both a `create`-class key and a **financially decisive** key (`PermissionVerb::isFinanciallyDecisive()`: `post`, `reverse`, `void`, `approve`, **`confirm`**, `refund`, `allocate`) from that group.

Enforced by `PermissionSodTemplateTest`, a pure registry test — no database, no fixtures, runs in milliseconds on both lanes. It asserts the rule over `registry->templateGrants()` and reports violations as `sod_group 'purchase-order': template 'manager' holds both purchase-orders.create and purchase-orders.approve`.

> **RULING (orchestrator, 2026-09-10) — SoD-1 is a hard rule for NEW resources and a shrink-only ratchet for the existing templates.** Rev 1 claimed the test could pass on day one. It cannot: today's `manager` template already combines create with a decisive verb in five of the proposed groups (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:593-610`). The test therefore ships in two halves:
>
> - **Hard half** — any `sod_group` introduced from wave 1 onward, and any template grant added to an existing group, fails immediately. No baseline entry may be added, ever.
> - **Ratchet half** — the five existing combinations are baselined by name in `apps/api/tests/Architecture/baselines/permission-sod-baseline.json`, counted against a shrink-only `SOD_BASELINE_CEILING`, and listed here so the owner is deciding about a table rather than about a number:
>
>   | `sod_group` | Template | Create key | Decisive key(s) | Line |
>   |---|---|---|---|---|
>   | `invoice` | `manager` | `invoices.create` | `invoices.post` | `:598` |
>   | `purchase-order` | `manager` | `purchase-orders.create` | `purchase-orders.confirm` | `:594` |
>   | `payment` | `manager` | `payments.create` | `payments.allocate`, `payments.refund` | `:609` |
>   | `expense` | `manager` | `expenses.create` | `expenses.post`, `expenses.pay` | `:606` |
>   | `stock-adjustment` | `manager` | `inventory.adjustments.create` | `inventory.adjustments.post` | `:602` |
>
> **Removing any of these five from the `manager` template is an owner decision, not an engineering one** — it changes what a real manager can do on day one in a five-person pharmacy. It is recorded as the replacement OQ-3 in §9.2 ("SoD baseline retirement"), with its own benchmark, and until the owner rules the baseline holds and the ceiling only shrinks.

Note that `payments.reverse` is **already** admin-only by an earlier owner decision (`:264-268`, ratified 2026-07-10) — evidence that the owner has ruled this way before when asked about a specific verb, which is why the question is put as a table of five rather than as a principle.

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

**One authoritative deprecation set: 22 keys (rev 2, M-9).** Rev 1 printed a 25-entry list and then, in the next paragraph, excluded three of its own entries — leaving no single list a plan could implement. The arithmetic, re-derived mechanically at `971528977`:

- the audit's prose says *"26 confirmed dead permissions"*, but its own code block (`docs/superpowers/audits/2026-09-09-roles-permissions/01-backend-permission-model.md:97-104`) contains **25** entries when `pos_orders.{view,create,update,delete}` is expanded — the prose figure is wrong and the block is right;
- **three are excluded** as live-after-wave-0a (below);
- **25 − 3 = 22** is the deprecation set.

**Excluded (3) — not dead, must never be deprecated.** `batches.delete` and `batches.recall` become live route gates the moment **wave 0b** closes `DELETE /api/v1/batches/{uuid}` and `POST /api/v1/batches/{uuid}/recall` (§4.4.4) — the audit found them "dead" precisely *because* those routes had no gate; and `batches.recall` is additionally re-granted by W-LOT-A-1a to `general_manager` and revoked from `manager` (plan `:1431-1444`). `products.import` gates a live importer surface reachable from the FE. Deprecating a key wave 0a/0b has just started using would be a self-inflicted 403.

**The deprecation set (22), authoritative — this is the list wave 1 implements:**

`audit.view`, `catalog_cart.convert_so`, `catalog_cart.manage_all`, `coupons.view`, `inventory.receive`, `invoices.print`, `menus.view`, `pos.issue_goodwill_voucher_high_value`, `pos.redeem_voucher`, `pos.refund_no_receipt`, `pos.refund_voucher_to_cash`, `pos.rotate_qr_signing_key`, `pos.search_customer_cross_company`, `pos.search_customer_recent_purchases`, `pos.void_receipts`, `pos_orders.view`, `pos_orders.create`, `pos_orders.update`, `pos_orders.delete`, `purchase-quote-requests.delete`, `workshop.technicians.adjust_time_entries`, `workshop.technicians.approve_time_off`.

(Note that `catalog_cart.convert_so` and `catalog_cart.manage_all` appear in **both** this list and the §4.9.2 rename map. That is deliberate and consistent: the rename normalises the key's shape in place, the deprecation flag then removes it from the templates. A key is renamed first, then deprecated under its new name `catalog-cart.*` — step 1 runs before step 3 for exactly this reason.)

**Re-derivation is still mandatory, and it is a wave-1 gate item.** Because deprecation removes a key from **every system-role template**, the set is re-derived from the tree **after waves 0a and 0b land** by `permissions:report-dead` (a `--dry-run`-only reporting mode of the sync command) and the result must equal these 22. A difference is a finding to rule on, not a silent update: waves 0a/0b change the answer for at least the three excluded keys, and any further drift means something else started using a key in between.

Deprecation semantics (§4.3.2 step 3): the `permissions` row survives one release; the key is dropped from every system-role template on the next sync; a **custom** role keeps it, so no tenant loses a capability without a human decision; the Roles UI renders it struck through with *"deprecated — will be removed in the next release"*. The row is deleted by `permissions:prune-orphans --confirm` one release later, never automatically.

**`reports.view`** is removed rather than deprecated-and-kept. Its comment already says it "no longer gates any route as of this release … Kept seeded … for one release … slated for removal next release" (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:325-332`); that release has passed. It enters the registry as `deprecated: true, replacedBy: null` in wave 1 and is pruned in wave 3 — the deprecation machinery is exactly how the seeder's comment intended it to be handled, now with a mechanism instead of a comment.

**`credit-notes.cancel` is declared.** The route `POST /credit-notes/{creditNote}/cancel` requires it (`apps/api/app/Modules/Document/Presentation/routes.php:269-271`) and no tenant has ever had the row — Spatie 6.25 swallows `PermissionDoesNotExist` in `checkPermissionTo`, so **every caller including `admin` gets a silent 403** (`docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:187`). It is added to the Document manifest with `templateDefaults: ['manager', 'general_manager']`, matching its siblings `credit-notes.create`/`.post`. **This is wave 0b, not wave 1** — it is a live, reachable, permanently-broken endpoint and should not wait for the registry.

The test that masks it is fixed in **wave 0b (0b-7), in the same commit as 0b-1** — rev 2 moved it out of 0a because it deliberately reds the suite until the key is declared, and a knowingly red commit is not independently mergeable (B-9): `apps/api/tests/Feature/Document/RefundResidualTenantIsolationTest.php:136-142` does `Permission::firstOrCreate(['name' => 'credit-notes.cancel', 'guard_name' => 'sanctum'])` and grants it, with a comment mischaracterising a permission that does not exist in the catalogue as one "admin doesn't include by default". The `firstOrCreate` is replaced by a plain lookup that fails if the seeder has not created it; landing that change together with 0b-1's declaration means the suite is green on every merged commit while still proving the key exists.

#### 4.9.4 Deletions and re-scopings

**`apps/api/database/seeders/PermissionSeeder.php` (274 lines) is deleted** in **wave 0b (0b-6)** — rev 1 filed it under 0a while conceding it must land after 0b-1 declares `credit-notes.cancel`, which made it a 0a item in name only (B-9). It ships a colliding taxonomy — 65 different permission strings and seven capitalised role names (`Administrator`, `Sales Manager`, `Accountant`, `Sales Rep`, `Warehouse Manager`, `Receptionist`, `Cashier`) that would collide with the real lowercase `accountant`/`cashier` if ever run. It has exactly one caller, `apps/api/database/seeders/ProductionSeeder.php:75`, which is manual-only and never in the entrypoint or the provisioning path; that line is deleted with it. It is also the file where `credit-notes.cancel` survives (`PermissionSeeder.php:72`) — evidence the key was lost in a seeder consolidation, which is why §4.9.3 restores it before this file goes.

**`marketplace.admin` — DEFER with a ticket.** It gates seller administration inside the tenant API (`apps/api/app/Modules/Marketplace/Presentation/routes.php:92-97`) yet is a platform-scoped capability, and under §4.3.2's `admin = activeKeys()` rule every tenant admin would hold it. It is unreachable today: the whole module sits behind a kill-switch that returns early when `config('marketplace.enabled')` is false, and it defaults to false (`apps/api/app/Modules/Marketplace/Presentation/routes.php:49`). So no tenant can reach those routes at `971528977`, and the correct fix — re-gate the seller-admin routes on `super_admin` and deprecate the key — is scheduled for **wave 3** with ticket `docs/superpowers/tickets/2026-09-10-marketplace-admin-platform-scope.md`. Inventing a `platformScoped` flag on `PermissionDefinition` for one dormant key would be worse than the problem.

### 4.10 POS

Four changes, all narrow; the POS authorization model itself is sound and is not redesigned.

1. **The PIN payload is derived from the registry, and keeps the PIN holder's own grants.** `PosAuthController::verifyPin` returns `permissions => $user->getAllPermissions()->pluck('name')` on a principal loaded from the database (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-100`). **Rev 2 withdraws rev 1's claim that this "gains token-scope narrowing for free"** — it does not, and should not. The `getAllPermissions()` override reads the token from the model it is called on (`apps/api/app/Modules/Identity/Domain/User.php:201-241`), and a freshly loaded PIN holder carries none, so the payload is the holder's unnarrowed grants. That is the correct semantics: the payload answers *"what may this operator approve"*, not *"what may this terminal's credential do"* — narrowing an approver's authority by the till's token would make a manager override depend on which device it was performed at. Formally it is `EffectivePermissionResolver::forTarget($pinHolder, null)` (§4.1.3a rule 4). Service accounts can never reach this path: they are excluded from `pinHolders()` (item 3) and cannot hold a PIN at all by the §5.1 CHECK. What changes is that the device's approval-scope derivation reads the `pos.approve_*` keys from `PERMISSION_MODULES`-grouped registry data rather than a hand-maintained list, so a new approval permission reaches the terminal on the next deploy without a POS release.
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
| `password` *(altered)* | `varchar` | **YES** (was NO) | — | — | — | — |

- **`principal_kind`** — CHECK `principal_kind IN ('human','service')` (PG) with an equivalent insert/update trigger pair on SQLite, following the pattern W-LOT-A-1a establishes for `provisioning_source` (plan `:1205-1217`). The `'human'` default is what makes this migration a no-op for every existing row: no backfill statement is written, because every existing `users` row is a human by construction (there is no other way to create one today).
- **Invariant enforced in the database**, not only in the model (rev 2 — the CHECK now covers every stated invariant, including the two rev 1 asserted in prose and left unconstrained):

  ```sql
  CHECK (
    principal_kind = 'human'
    OR (pos_pin IS NULL
        AND email_verified_at IS NULL
        AND password IS NULL
        AND email IS NULL
        AND status IN ('active','inactive'))
  )
  ```

  The `status` clause is explicit because `UserStatus` also carries `suspended` and `pending_verification` (`apps/api/app/Modules/Identity/Domain/Enums/UserStatus.php:10-15`), neither of which means anything for a credential-only principal; rev 1's CHECK omitted it. A service principal with a POS PIN would be admitted to `pinHolders()` by an older query and is exactly the kind of drift §4.10 exists to prevent. SQLite gets the equivalent insert/update trigger pair, following the pattern W-LOT-A-1a establishes for `provisioning_source` (plan `:1205-1217`).
- **`password` IS made nullable** (rev 2 — reversing rev 1). Rev 1 kept it `NOT NULL` (`apps/api/database/migrations/tenant/2025_11_30_000003_create_users_table.php:22`) and stored the literal sentinel `'!'`. That does not work: `User` casts `password` as `hashed` (`apps/api/app/Modules/Identity/Domain/User.php:115-125`), so Eloquent stores a **valid hash of `!`** and `Hash::check('!', …)` succeeds — a sentinel a known one-character password unlocks. The column becomes nullable and the service branch requires `password IS NULL`. The `?string` blast radius rev 1 feared is bounded by the ordering rule in §4.1.1: the `principal_kind` check precedes every `Hash::check`, so no call site ever passes a null to it.
- **`email` is left NULL for a service account** (rev 2 — reversing rev 1's synthetic `svc+<slug>@<tenant-slug>.invalid`). `email` is already nullable and its uniqueness is already a **partial** index over non-null values (`apps/api/database/migrations/tenant/2026_03_23_000001_make_user_email_nullable.php:14-23`), which supersedes the original `unique(['tenant_id','email'])` at `2025_11_30_000003_create_users_table.php:34` — so a null email needs no schema change and cannot collide. It is also the stronger guarantee: a reserved-TLD address still flows through `Notifiable` and any future non-mail channel, whereas a null email is skipped at the source by the invitation path (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:281-292`) and refused with 422 `NO_EMAIL` by password reset (`:786-808`).
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
- **`personal_access_tokens`** — no new column, and no central migration. Sanctum 4 already ships `name`, `abilities` and `expires_at`; token scope is `abilities`, and token TTL is `expires_at` (OQ-2, §9.2). **Two rev-2 corrections.** (i) `abilities` is a nullable **TEXT** column that Sanctum casts to an array, not a JSON column (`apps/api/database/migrations/2025_11_29_234638_create_personal_access_tokens_table.php:17-21`) — the distinction matters because no SQL-side JSON predicate can be written against it, so every scope query is application-side. (ii) The repository default TTL is **not "none"**: `apps/api/config/sanctum.php:43-53` sets a global 43 200 minutes (30 days), and a custom validation callback makes a per-row `expires_at` take precedence over that global (`apps/api/app/Providers/AppServiceProvider.php:201-219`). So today web tokens live 30 days by the global and POS terminal tokens live one year by an explicit `expires_at` (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:146-176,294-310`). OQ-2 is therefore a question about *changing* an existing policy, not about introducing one.
- **`audit_events`** — no new column. `principal_id` and `token_id` go inside the existing `metadata` jsonb (`apps/api/app/Modules/Compliance/Domain/AuditEvent.php:55`), which is what it is for. The impersonation columns at `:58-63` are untouched.
- **`user_company_memberships`** — unchanged. A service account uses the same table, the same `MembershipStatus`, and the same `allowed_location_ids`.
- **`roles.provisioning_source`** and its CHECK, partial unique and triggers — untouched (§4.3.0).

---

## 6. Convention-09 obligations per wave

`docs/conventions/09-SECOND-OF-EVERYTHING.md:37-50` is in scope for a lane whose diff touches a catalogue entity on any layer. **Roles and permissions are not `CATALOGUE_TABLES` entities**: they are not company-owned, they are tenant-scoped by owner ruling D1, and an operator does not create them per company.

**They are already excluded, today — this is not future work (rev 2, M-6).** Rev 1 spoke of an exclusion the ratchet "would need if it were ever widened". `TenantOnlyUniqueOnCatalogueTablesRatchetTest` already carries all three tables in its exclusion map with stated reasons (`apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:131-132,160,175`):

| Table | Existing reason, verbatim |
|---|---|
| `permissions` | *"Permissions define the tenant-wide authorization namespace."* |
| `roles` | *"Roles define the tenant-wide authorization namespace."* |
| `users` | *"User identities are tenant-global and may belong to multiple companies."* |

So this design's obligation is **not to add an exclusion** but **not to invalidate an existing one**. The one new unique key it introduces, `roles_template_key_unique`, deliberately omits `tenant_id` (§5.2) and therefore sits inside the already-excluded `roles` entry; wave 1 must confirm the ratchet stays green rather than editing it. If a future lane ever removes the `roles` exclusion, the waiver wording it needs is: *"tenant-global by nature — owner ruling D1 (2026-09-10) keeps roles scoped to the tenant, with company and location scope carried by `user_company_memberships`; a per-company role would be a second surface for the same concept (convention 11)."* **But two of the three axes still apply to this design's behaviour**, and the obligations below are real tests, not a waiver:

| Wave | Second company | Second location | Re-run / idempotency |
|---|---|---|---|
| **0a** — cache, ratchet, read gates | S-1's own test provisions **two real tenant databases** (`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:60-140`), which is the stronger form of the axis for a tenant-scoped concept. The ratchet is schema-independent. | n/a — no location-bound behaviour changes | the ratchet test is idempotent by construction; running the route sweep twice yields identical baselines (asserted) |
| **0b** — new permissions for the ungated writes | `ServicePermissionsSecondCompanyTest`: an actor with `services.update` in company A is refused a `PATCH /services/{id}` on a service of company B — proving the new gate did not replace the existing company scope **Rev 2 — replaced.** Rev 1 named `CategoryPermissionsSecondLocationTest`, which cannot exist: categories are **company**-scoped with no location binding at all — every read and write goes through `companyContext->requireCompanyId()` and a `where('company_id', …)` (`apps/api/app/Modules/Product/Presentation/Controllers/CategoryController.php:28-35,101-108,115-178`). A "second location" assertion on a resource with no location axis asserts nothing. Two tests replace it: **`CategoryPermissionsSecondCompanyTest`** (the axis categories actually have — a `categories.update` holder in company A is refused on a category of company B, asserting the *rows*, not the status), and **`ChannelPermissionsSecondLocationTest`** on a genuinely location-bound new resource, asserting that a principal restricted by `allowed_location_ids` is refused a write at another location even while holding the new permission | re-running `tenants:seed` twice adds each new key once (`firstOrCreate`), asserted by row count |
| **1** — catalogue + sync | `PermissionsSyncTest::sync_creates_every_registry_permission_in_a_fresh_tenant_and_in_a_second_tenant` — two physical tenant DBs, asserting **data meaning**: tenant B's `admin` holds exactly `activeKeys()`, tenant B's customised `manager` was skipped independently of tenant A's | a second `pos_enabled` location, asserting a POS approval permission granted by sync is honoured at the *selected* terminal's location, not the default | **`permissions:sync` run twice** → second run reports `ALREADY_CURRENT` with every counter zero and **zero** row writes (asserted by a query-log count, not by absence of exception); plus `sync` after `LotActionPermissionDelta` → no change to the two W-LOT keys or to `general_manager` |
| **2** — role management, principals, tokens | `LastAdminFloorTest` with two companies in one tenant, asserting the floor is per **tenant**: removing the admin role from the only admin is refused even though company B has its own owner (§4.6.3 clause b) | `ServiceAccountLocationScopeTest` — a service token whose principal's membership carries `allowed_location_ids` is refused a write at another location | issuing the same token twice creates two distinct tokens (no dedup) but revoking a principal's membership twice is idempotent; re-applying a template twice is a no-op |
| **3** — consistency sweep | second-company assertions on the POS PIN population after `principal_kind` filtering | second-location POS approval scope | `permissions:prune-orphans` run twice → second run deletes nothing |
| **4** — edge-case campaign | the campaign itself is the second-company/second-location journey (§8): a fresh tenant with **two companies**, asserting that a role granted in the tenant resolves identically in both while membership scope differs | a **second `pos_enabled` location** in the second company, asserting an approval permission is honoured at the selected terminal's location and refused at the other | the campaign is **re-run end to end on the same tenant**, asserting the second pass creates no duplicate role, permission or membership row and that every counter in `permissions:sync-fleet` reports `ALREADY_CURRENT` |

Every assertion above is on **data meaning** — the row company B sees, the permission set after the second run — never on HTTP status or "no exception thrown" (`docs/conventions/09-SECOND-OF-EVERYTHING.md:52`).
---

## 7. Edge-case register

**Thirty-eight rows in rev 2** — the original thirty-two minus EC-22 (withdrawn, number retained) plus seven added by the gate-r1 fix round: EC-4a, EC-4b, EC-9a, EC-15a, EC-18a, EC-21a and EC-16a. Each is a scenario, the expected behaviour, the single place that enforces it, and the lane that proves it. Lanes: **PG** = PHPUnit on PostgreSQL (real tenant databases, `ProvisionsTenantDatabases`), **SQLite** = PHPUnit on the fast lane, **vitest** = `apps/web`, **playwright** = `apps/web/e2e`, **pos-vitest** = `apps/pos`.

| # | Scenario | Expected behaviour | Where enforced | Test lane |
|---|---|---|---|---|
| EC-1 | The admin role is removed from the **last** active human principal holding it | 422 `LAST_ADMIN_ROLE_REMOVAL`; nothing written | `LastAdminFloor`, called from `RoleController::removeRole` inside the mutation transaction (§4.6.3) | PG — `LastAdminFloorTest::removing_the_admin_role_from_the_last_admin_is_refused` |
| EC-2 | The last admin is **deactivated** (`POST /users/{id}/deactivate`) | 422 `LAST_ADMIN_DEACTIVATION`; `status` unchanged | same guard, `UserController::deactivate` | PG |
| EC-3 | The last admin is **deleted** | 422 `LAST_ADMIN_DELETION` | same guard, `UserController::destroy` | PG |
| EC-4 | The tenant's **only** admin is a service account, and the only human admin is deactivated | Refused — service principals do not count toward the floor (§4.6.3 clause a) | `LastAdminFloor` filters `principal_kind = human` | PG — `LastAdminFloorTest::a_service_admin_does_not_satisfy_the_floor` |
| **EC-4a** *(new, rev 2)* | The tenant's only remaining human admin holds the role but has **no active membership**, and the last admin *with* a membership is deactivated | Refused — a membership-less admin is not in *A*, because company access is membership-based (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:147-155`) and such a principal cannot recover the tenant | `LastAdminFloor`'s membership clause (§4.6.3) | PG — `LastAdminFloorTest::a_membershipless_admin_does_not_satisfy_the_floor` |
| **EC-4b** *(new, rev 2)* | An admin is demoted through **`PATCH /users/{id}` with a different `role`** rather than through `removeRole` | 422 `LAST_ADMIN_ROLE_REMOVAL` — `syncRoles()` is a wholesale replacement and is the writer rev 1 missed (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:380-389`) | `LastAdminFloor`, called from `UserController::update` (§4.6.3 writer 5) | PG — `LastAdminFloorTest::update_with_a_different_role_cannot_empty_the_admin_population` |
| EC-5 | A **custom** role holds a permission the registry now marks **deprecated** | The grant survives; the key disappears from every system-role template; sync **never** revokes it. The Roles UI strikes it through with *"deprecated — scheduled for removal"* — **rev 2 drops rev 1's "removed next release" wording**, which promised something no part of this design decides: sync never deletes a permission row (step 4 reports orphans only) and pruning is an explicit later operator action (`permissions:prune-orphans --confirm`, wave 3, §10). The UI states the intent, not a date | `PermissionSyncService` step 3 (§4.3.2); `RolesPage` renders `deprecated` from the payload | PG + vitest |
| EC-6 | A **custom** role holds a permission that the rename map renames | The grant survives because the rename is an in-place `UPDATE` on `permissions.name`; the primary key never changes, so `role_has_permissions` is untouched | `PermissionSyncService` step 1 (§4.3.2) | PG — `PermissionRenameTest::a_custom_role_grant_survives_a_rename` (asserts the pivot row count and the role's resolved key set, not just absence of error) |
| EC-7 | Rename map says `a → b` and **both** rows already exist in a tenant | Run is `BLOCKED`, `reason=rename_target_exists`, transaction rolled back, exit 2; a human decides which grants win | `PermissionSyncService` step 1 | PG |
| EC-8 | A deploy adds permissions **while a user's session is live**; their cached Spatie snapshot is stale | The tenant's snapshot is forgotten **after the commit lands** (`DB::afterCommit`, step 7 as corrected in rev 2 — a `finally` inside the transaction callback fires *before* commit and leaves a window for another request to re-cache the old snapshot), so the next request rebuilds it; the FE converges on its next `/auth/me` | S-1 tenant-scoped key (§4.8) + the after-commit flush in step 7 | PG — `PermissionCacheTenantScopingTest` (S-1's own) + `PermissionsSyncTest::sync_forgets_only_this_tenants_cache` + `::the_cache_is_flushed_after_commit_not_before` |
| EC-9 | A tenant has **customised** its `manager` role; a deploy adds a permission whose template lists `manager` | `manager` receives **nothing**; the run reports `templates_skipped_customised=1`; `template_version` stays behind; the UI shows "N template updates not applied" with *Re-apply template*. **Depends on adoption (§4.3.0a rule 2) and on the wave-1 `customised_at` writer** — without both, a role customised before wave 1 is indistinguishable from a pristine one, which is the B-2 hole rev 1 left | `PermissionSyncService` step 4a then step 5's `customised_at IS NOT NULL` branch (D2) | PG |
| **EC-9a** *(new, rev 2)* | A tenant customised a **NULL-team legacy role that already carries the W-LOT marker** (`general_manager` on a marked tenant), then wave 1's first sync runs | Adoption classifies it as customised (`customised_at = now()`), `provisioning_source` is **not** written, `tenant_id` is preserved as found (NULL stays NULL), no delta is applied, and `LotActionPermissionDelta` is not invoked because the role exists | §4.3.0a rules 1–2 + §4.3.0 clause 3 | PG — `PermissionSyncAdoptionTest::a_customised_marked_null_team_role_is_adopted_without_touching_the_marker` |
| EC-10 | The operator uses *Re-apply template* on that role | `customised_at` cleared, the template delta applied for that role only, `template_version` advanced, `RoleUpdated` audited with the diff | `RoleController` re-apply action → **`PermissionSyncService::syncRole(string $tenantId, string $templateKey, bool $write)`** — rev 2 names the method, because rev 1 invoked a "single-role path" that the declared signature `sync(string, bool)` does not contain | PG + playwright |
| EC-11 | A tenant **renames a custom role** that is referenced nowhere by key | Allowed; `model_has_roles` is keyed by role id, so assignments survive | `RoleController::update`, `is_system` false branch | PG |
| EC-12 | A tenant tries to rename or delete a **system** role | 422 `SYSTEM_ROLE_PROTECTED` on both, driven by `roles.is_system`, not by a name list | `RoleController::update`/`destroy` (§4.6.1) | PG + vitest (`RolesPage` hides the actions from the payload flag) |
| EC-13 | A `roles.manage` holder `PATCH`es the **admin** role with `{"permissions": []}` | 422 `ADMIN_PERMISSION_FLOOR`; nothing written. **Rev 2 — it is audited as a floor refusal, not as a 403 denial.** §4.6.5's denial rule fires on a 403 from an authorization gate; this caller *is* authorized to reach the endpoint and is refused by a domain invariant with 422. Conflating the two would put a business-rule refusal into the denial dedup stream and make `suppressed_count` meaningless. It emits its own `RoleUpdateRefused` audit event carrying `{role_id, reason: 'admin_permission_floor', principal_id, token_id}` | Floor F-1 (§4.6.2), checked before `syncPermissions()` | PG — `AdminPermissionFloorTest`, including the deny path (today's `apps/api/tests/Feature/Identity/RBACTest.php:137-165` only exercises an allow path) |
| EC-14 | A user **switches company** | Roles are tenant-scoped (D1) so the permission set does not change; company/location scope does, and every list re-keys | unchanged behaviour; `tenantScopedKey` appends `companyId` (`apps/web/src/lib/tenantScopedKey.ts:29-34`) | vitest + playwright |
| EC-15 | A user restricted to one location is assigned `general_manager` | **Rev 2 — REVERSED. The assignment is REFUSED with 422 `GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP`.** Rev 1 said the user keeps the restriction and gains the action; that contradicts the incoming lane, whose `GeneralManagerAssignmentGuard` locks every active membership of the target and refuses when any carries a non-null `allowed_location_ids` (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php:19-23,36-49`). The invariant is part of the adopted glossary row (§2) and D7 makes that lane's work absorbed, never undone. The orthogonality claim survives one level down: *other* roles still leave `allowed_location_ids` untouched — it is `general_manager` specifically that requires an unrestricted membership, because "all-location treasury authority" and "restricted to one location" are contradictory as a **grant**, not merely as a scope | `GeneralManagerAssignmentGuard` (that lane's, invoked from Settings → Users, its sole assignment surface); `ValidateLocationAccess` + `LocationContext` unchanged for every other role | PG — `GeneralManagerAssignmentTest` (that lane's own) **plus** `GeneralManagerLocationScopeTest`, which asserts the *rows returned* for an unrestricted general manager |
| **EC-15a** *(new, rev 2)* | A user **already holding** `general_manager` has a location restriction added to one of their memberships | Refused with the same 422 — the guard's `assertLocationChangeAllowed` covers the change-side of the invariant, not only assignment | `GeneralManagerAssignmentGuard::assertLocationChangeAllowed` | PG |
| EC-16 | A principal's **last active membership** is revoked while an API token is live | Every token of that principal is revoked; the next request is 401. A revocation that leaves another active membership revokes nothing, and the removed company is denied by the `X-Company-Id` check instead. **Rev 2 names the boundary**: every membership-removing writer goes through `MembershipRevocationService` (§4.1.5) — "when a membership is removed" was not a checkable rule, since no standalone membership-removal writer exists today (revocation lives inside `UserController.php:988-998`) | §4.1.5 rule 1; `MembershipRevocationService`; `EnforceTokenScope` (§4.1.4) | PG — `ServiceAccountRevocationTest`, both branches, plus a writer census asserting no other production path removes a membership |
| **EC-16a** *(new, rev 2)* | The tenant-side membership removal commits but the **central-side token delete fails** | The two are on different connections and therefore **cannot** be one transaction — rev 1's atomicity claim is withdrawn. The membership change stands, a `RevokePrincipalTokens` job is queued with backoff, the pending revocation is recorded on the audit chain, and the window is harmless because `EnforceTokenScope` refuses the token on its next request for lack of an active membership | §4.1.5, `MembershipRevocationService` | PG — the central delete is forced to fail; asserts the job is queued, the audit row exists, and the very next request with that token is refused |
| EC-17 | A principal's roles change while an open browser tab holds a 5-minute-stale `/auth/me` | The UI may render a stale affordance for up to 5 minutes; **every mutating request is still 403**. Recorded as a known UX exposure, not a security one | server-side `can:`; FE staleness from `AuthProvider` `staleTime: 1000*60*5` | vitest (`usePermissions` renders from the stale store) + PG (the write is refused) |
| EC-18 | A token carries `permission:` abilities **wider** than the principal's grants | The extra keys grant nothing — the scope is intersected with live database grants, never unioned. **Rev 2 extends the row three ways** (§4.1.3a): (i) the same test asserts a `hasRole()`-style idiom cannot be reached from production code, by asserting the PHPStan/ESLint role-name baseline is empty for the three converted files; (ii) `forSubject()` returns the intersection for the principal's own request while `forTarget()` returns unnarrowed grants for the same principal read by an admin; (iii) `getAllPermissions()` on a freshly loaded `User` (no `currentAccessToken()`) is asserted to return unnarrowed grants, which is the documented contract rather than an accident | `User::hasPermissionTo()` / `getAllPermissions()` (§4.1.3 rule 1) + `EffectivePermissionResolver` (§4.1.3a) | SQLite — `TokenScopeNarrowingTest::a_scope_wider_than_the_principal_grants_nothing`, `::for_target_is_not_narrowed_by_the_callers_token`, `::role_name_authorization_baseline_is_empty_for_converted_files` |
| **EC-18a** *(new, rev 2)* | A scoped token calls a route still gated by a **role name** (`CreateDraftCountingRequest`, an `InventoryCountingController` bypass, or the POS discount ceiling) | Before wave 2a's conversion this **bypasses the scope entirely** — which is why converting those eight sites is an **entry condition** of token issuance, not a follow-on (§4.1.3a (b)). After conversion, the call is refused when the key is outside the scope | `ScopedTokenIssuanceEntryConditionTest` asserts the backend baseline is empty for the three converted files before `service-accounts.issue-token` is routable | PG — one test per converted site, asserting a scoped token is refused where an unscoped one succeeds |
| EC-19 | Two admins concurrently remove each other's admin role | Exactly one succeeds; the loser gets 422 `LAST_ADMIN_ROLE_REMOVAL`. **`SELECT … FOR UPDATE` over the candidate `users` rows ordered by `users.id` ascending, then their `model_has_roles` pivots** — rev 2 states the order because an unspecified lock order over "candidate rows" is a deadlock, not a floor | `LastAdminFloor` (§4.6.3 clause c) | **PG only** — a real two-connection concurrency test; mark-skips on SQLite |
| EC-20 | `permissions:sync` is run **twice concurrently** on one tenant | The second blocks on the transaction-scoped PG advisory lock, then finds nothing to do and reports `ALREADY_CURRENT`. Where the two runs also touch `users`/`model_has_roles`, they take the same `users.id`-ascending lock order as EC-19 | `PermissionSyncService::acquireTenantLock` (§4.3.2), the lock shape W-LOT-A-1a establishes | **PG only** — two-connection test |
| EC-21 | `permissions:sync --tenant=x` in **compatibility mode** (`TENANCY_DB_PER_TENANT=false`) | Exit 2, `reason=compatibility_mode_is_global`. Invoked **without** `--tenant`, steps 1–4 **and step 4a (adoption)** run globally with `templates_applied=0`. **Rev 2 — adoption is no longer skipped here**: it is a DB-level classification, and skipping it (rev 1) would leave every system role at `is_system = false`, so wave 2's rename/delete protection and both floors would have nothing to stand on | `SyncPermissions::handle` (§4.3.3) | SQLite — asserting `is_system`/`template_key` are set and `templates_applied = 0` |
| **EC-21a** *(new, rev 2)* | A fleet sync where **one tenant returns `BLOCKED`/`FAILED`** while the run as a whole completes | `permissions:sync-fleet` still attempts every remaining tenant, prints the failing tenant ids in its aggregate marker, and **exits non-zero**; the entrypoint keeps booting but writes the failure marker and `/health` reports `permissions_sync: failed`. This is the case `tenants:run` cannot express, because it discards child exit statuses (`docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:2956`) and the entrypoint continues past errors (`apps/api/docker/entrypoint.sh:150-169`) | `SyncPermissionsFleet` (§4.3.3) | PG — three tenants, one seeded into a `BLOCKED` state (rename target exists), asserting exit code, marker content and the `/health` key |
| ~~EC-22~~ | ~~A **central super admin** calls a tenant route with a token carrying the `super-admin` ability~~ | **WITHDRAWN in rev 2 — not a supported path, so there is nothing to test.** A central `SuperAdmin` has no `tenant_id` (`apps/api/app/Models/SuperAdmin.php:14-32`), while `SetPermissionsTeam` reads `$user->tenant_id` on every authenticated subject (`apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22-29`) and `ResolveTenancy` selects the tenant database from a `tenant:` ability the super-admin token does not carry (`apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:114-134`). A central token calling an ordinary tenant route is not a tenant authorization path at all; platform access to a tenant goes through impersonation (G6/G14), which **is** covered — by EC-32 and by the SupportAccess lane. The row number is retained rather than reused so earlier cross-references do not silently repoint | — | — |
| EC-23 | A route is "gated" **only** by a FormRequest whose `authorize()` returns `true` | The route counts as **uncovered** in the ratchet and must gain a `can:` or `authz.self` | E-4 (§4.4.1) + the ratchet's classification, which reads middleware only | SQLite — `RoutePermissionCoverageRatchetTest` |
| EC-24 | A contributor adds a new ungated route **and** its baseline entry in one commit | Anti-growth direction fails: the baseline key set no longer matches the CI-pinned protected blob | `RoutePermissionCoverageRatchetTest` (§4.4.3), the `DocumentPerActionBaselineRatchetTest` mechanism | SQLite + CI env var |
| EC-25 | A baselined uncovered route is **fixed** but its baseline entry is left behind | Stale direction fails: "remove this entry" | same test | SQLite |
| EC-26 | A new permission ships with **no fr or ar label** | `PermissionRegistryLabelCoverageTest` fails and names the missing keys; `permissions:export-label-skeleton` writes a complete per-locale file to edit | §4.5.2 | SQLite |
| EC-27 | A module's enum gains a case with no manifest definition (or vice versa) | `PermissionEnumManifestParityTest` fails in **both** directions | §4.2.5 | SQLite |
| EC-28 | A misconfigured integration produces hundreds of 403s per minute | At most one `AuthorizationDenied` on `audit_events` per `(principal, permission, route)` per 5 minutes; the next emitted event carries `suppressed_count`. **Rev 2 — two Redis keys, not one:** a 5-minute `SET NX EX` emission lock plus a separately-TTL'd (24 h, refreshed) retained counter, because a single expiring counter loses its suppressed count exactly when the next event needs it | §4.6.5 dedup rule | PG (the event lands once on the audit chain) + **Redis integration** — `AuthorizationDenialDedupTest`; rev 1's "SQLite counter arithmetic" is withdrawn, since SQLite cannot express `SET NX EX` or divergent TTLs and would have asserted a different algorithm than the one shipping |
| EC-29 | A **module is disabled** for the tenant but a role still grants its permissions | The route is refused by `module:<Name>` middleware before `can:` is reached; the permission stays granted and simply cannot be exercised; `GET /permissions` hides the module's group as it does today | `RequireModule` (`apps/api/bootstrap/app.php:119`); `RoleController::permissions` filtering at `:331-334` | PG |
| EC-30 | A template mistake grants a financially decisive permission to `viewer` alongside a create permission in the same SoD group | `PermissionSodTemplateTest` fails at build time, naming the group, the template and the two keys. **Rev 2 — the test could not have passed as rev 1 wrote it**: today's `manager` template already combines create with a decisive verb in five groups (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:593-610`), and `isFinanciallyDecisive()` omitted `confirm` while the `purchase-order` group named `.confirm`. Both are fixed: `confirm` is in the list (§4.2.2), and the rule is **hard for new resources, shrink-only ratchet over the five baselined `manager` combinations** (§4.7). Retiring any of the five is an owner decision, recorded as OQ-3 | §4.7 | SQLite — a new group violation fails; the five baselined ones pass; adding a sixth baseline entry fails |
| EC-31 | An **offline POS operator** whose permissions were revoked server-side keeps acting on the device | The device continues on its last synced payload until it reconnects — an accepted offline residual, unchanged by this design. What changes: the operator's PIN stops being returned by `verifyPin`/`pinData`/`hasPins` the moment their membership or account goes inactive, because all three share `pinHolders()` (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-57`) | §4.10; `pinHolders()` unchanged in semantics | pos-vitest (offline continuation) + PG (all three surfaces exclude them) |
| EC-32 | A **service principal** attempts an interactive login, is impersonated, or is given a POS PIN | 401 `SERVICE_PRINCIPAL_CANNOT_LOG_IN`, impersonation refused, 422 on the PIN route — and the PIN case is additionally impossible by the §5.1 CHECK. **Rev 2 adds three assertions rev 1 left implicit:** the login refusal happens **before** `Hash::check` (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:249-268`), which is load-bearing now that `password` is nullable; the same ordering holds on the POS PIN path (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-100`) and on the raw PIN writer (`:295-307`); and the CHECK constrains `status IN ('active','inactive')` as well as the PIN/verification/password/email columns | §4.1.1 + the §5.1 database CHECK | PG (all three refusals) + PG (the CHECK rejects a direct insert of a service row with a `suspended` status, a PIN, a password or an email) |

Three rows deserve a note because they are the ones most likely to be argued at a gate. **EC-15**, as reversed in rev 2, is the row that shows where orthogonality **stops**: for every ordinary role, action and scope stay independent and assigning a role never widens location access — but `general_manager` is refused outright to a location-restricted principal, because the incoming lane makes an unrestricted membership a precondition of the grant rather than a consequence of it. A gate that can show *any other* role silently widening location access has broken the D1/G5 model; a gate pointing at `general_manager` is pointing at the invariant, not at a bug. **EC-17** is deliberately *not* fixed: closing a five-minute UI staleness window costs a websocket or a poll, and the exposure is rendering an affordance the server will refuse. **EC-31** is the only accepted-residual row; it is a property of offline POS, not of this design, and it is listed so nobody later mistakes it for a regression this lane introduced.
---

## 8. Waves and sequencing

**Laptop lane cap: 3 concurrent lanes** (owner, 2026-09-09). No wave below is planned to run more than two RBAC lanes at once, leaving one slot for whatever else is in flight. Every wave is one execution plan + one Codex adversarial gate on the plan + one reviewer gate on the diff; `tenancy-authz-reviewer` is **mandatory on every wave**, and `frontend-conventions-reviewer` is mandatory on any wave touching `apps/web`.

### Wave 0a — stop the bleeding (starts now; no dependency on any in-flight lane)

Entry condition: **none**. Every item uses permissions that already exist, adds no migration, and — **as re-verified in rev 2, not merely asserted** — touches no file in `git diff --name-only dev...lane/w-lot-a-1a`.

**Rev 2 re-scope (B-9).** Rev 1's unconditional no-overlap claim was false. `git diff --stat dev...lane/w-lot-a-1a` is **66 files, 4 065 insertions, 595 deletions**, and two of those files were also claimed by wave 0a:

| Rev-1 task | Collision | Rev-2 disposition |
|---|---|---|
| 0a-5's BatchExpiry portion (`batches` delete/recall route gating) | the lane rewrites `apps/api/app/Modules/BatchExpiry/Presentation/routes.php` wholesale (`lane/w-lot-a-1a:…/routes.php:13-40`, introducing `BatchActionAccess` middleware) | **moved to 0b** |
| 0a-10 glossary rows | the lane adds the General-manager row and the sole-writer sentence to `docs/glossary.md` (`lane/w-lot-a-1a:docs/glossary.md:21,91`) | **moved to 0b** |
| 0a-6 delete `PermissionSeeder.php` | rev 1 itself admitted it must land *after* 0b-1 declares `credit-notes.cancel` — so it was never a wave-0a item | **moved to 0b** |
| 0a-7 fix the masking test | rev 1 itself admitted it leaves the suite red until 0b — a red commit is not independently mergeable | **moved to 0b**, landing in the same commit as 0b-1 |

**Verified 0a file list (rev 2)** — every path below is absent from `git diff --name-only dev...lane/w-lot-a-1a`, checked file by file:

`apps/api/app/Modules/Identity/Application/Listeners/ScopePermissionCacheToTenant.php`, `…/RestoreCentralPermissionCache.php`, `apps/api/app/Providers/TenancyServiceProvider.php`, `apps/api/tests/Traits/ProvisionsTenantDatabases.php`, `apps/api/tests/Feature/Identity/PermissionCacheTenantScopingTest.php`, `apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php`, `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json`, `apps/api/app/Http/Middleware/AllowSelfService.php`, `apps/api/bootstrap/app.php`, `apps/api/app/Modules/Identity/routes.php`, `apps/api/app/Modules/Promotion/Presentation/routes.php`, `apps/api/app/Modules/Uom/Presentation/routes.php`, `apps/api/app/Modules/Menu/Presentation/routes.php`, `apps/api/app/Modules/Promotion/Presentation/routes.php` (coupons), `apps/api/app/Modules/Inventory/Presentation/routes.php` (the counting-item route only), `apps/api/app/Http/Middleware/RequireAnyPermission.php`, `docs/conventions/03-AUTHORIZATION.md`, `.claude/commands/add-permissions.md`.

Note `apps/api/app/Modules/Identity/routes.php` is **not** in the lane's diff even though `RoleController.php` and `UserController.php` are — which is why 0a-4 edits the routes file and touches neither controller.

**`lane/t1-transfers-edge` and `lane/t2-receipt-spine` create no overlap at their current refs**, and rev 2 corrects rev 1's phrasing about them: `git diff --stat dev...<lane>` is **empty for both** — T1 is already an ancestor of `dev` and T2 is at `dev`. Rev 1's warning that T2 "will edit `apps/api/app/Modules/Inventory/Presentation/routes.php`" is a statement about future work, not a present overlap, and is re-checked at 0a's merge rather than assumed either way.

| # | Task | Files | Notes |
|---|---|---|---|
| 0a-1 | **Land S-1**, the tenant-scoped permission cache | exactly the five files at `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:62-66` | Referenced, not redesigned (§4.8). It is the only P0 in the register (I-1). Its own Phase-0 precondition — prove staging is database-per-tenant and every tenant DB exists — is a deployment preflight, not a repo fact (`:52`) |
| 0a-2 | **Route-coverage ratchet + baseline** | add `apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php`, `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json`, a liveness fixture; register the protected-blob env var in CI | §4.4.3. Ceilings `UNCOVERED_WRITE_CEILING = 167`, `UNCOVERED_READ_CEILING = 148`, generated **after** 0a-3 |
| 0a-3 | **`authz.self` middleware + alias**, applied to the seven self-service routes | add `apps/api/app/Http/Middleware/AllowSelfService.php`; modify `apps/api/bootstrap/app.php:114-123` | §4.4.2. Must land **before** 0a-2's baseline is generated, or those seven routes enter the baseline and immediately go stale |
| 0a-4 | **Gate `GET /api/v1/users/{userId}/roles`** and the other three read routes | modify `apps/api/app/Modules/Identity/routes.php:59,61,64,78` | I-18 — today it returns any tenant user's full `getAllPermissions()` list to any authenticated caller (`RoleController.php:429-437`). Uses only existing keys (`roles.view`, `users.assign-roles`) |
| 0a-5 | **Gate the writes that need no new key** | modify `PromotionController` routes (×4), `Uom` routes (×5), `Menu` routes (×3), `Coupon` routes (×3), `CountingItemController` route (×1). **The two `BatchExpiry` routes are NOT here — moved to 0b (B-9)**, because `lane/w-lot-a-1a` rewrites that routes file | §4.4.4. Each deletes a baseline entry from 0a-2 in the same commit — the ratchet's stale direction proves none is forgotten |
| ~~0a-6~~ | ~~Delete `PermissionSeeder.php`~~ | — | **Moved to 0b (0b-6)** — rev 1 conceded it must land after 0b-1 declares `credit-notes.cancel`, so it was never a wave-0a item |
| ~~0a-7~~ | ~~Fix the masking test~~ | — | **Moved to 0b (0b-7)** — rev 1 conceded it leaves the suite red until 0b-1. A commit that knowingly reds the suite is not an independently mergeable wave-0a commit; it now lands **in the same commit as 0b-1** |
| 0a-8 | **Docs** | modify `docs/conventions/03-AUTHORIZATION.md` (its canonical route example at `:30-41` carries no authorization at all and no `EnforceTokenTenantClaim`); modify `.claude/commands/add-permissions.md:16-17` (prescribes a `permission:` route middleware that has never existed — `'permission:...'` route middleware count is **0**, `docs/superpowers/audits/2026-09-09-roles-permissions/01-backend-permission-model.md:85`) | I-13 |
| 0a-9 | **`RequireAnyPermission` message** | modify `apps/api/app/Http/Middleware/RequireAnyPermission.php:25` | Hard-codes *"You do not have permission to view transaction destinations."* on every caller |
| ~~0a-10~~ | ~~Glossary rows~~ | — | **Moved to 0b (0b-8)** — `lane/w-lot-a-1a` edits `docs/glossary.md` (its General-manager row at `:21` and its sole-writer sentence at `:91`), so the file is claimed. 0b adds User, Role, Permission, Super admin, Support approver and the Membership reconciliation sentence **on top of** the lane's rows, adopting the General-manager row verbatim (§2) rather than rewriting it |

**Overlap check, re-verified in rev 2 against `git diff --name-only dev...lane/w-lot-a-1a` (66 files).** That lane modifies `RolesAndPermissionsSeeder.php`, `RoleController.php`, `UserController.php`, `usePermissions.ts`, `permissionsMap.generated.ts`, `generated.d.ts`, `RolesPage.tsx`, `routes/index.tsx`, `Sidebar.tsx`, the whole `BatchExpiry` module **including its `Presentation/routes.php`**, `docs/glossary.md`, and the tenant migrations directory. After the re-scope above, wave 0a touches **none** of them: 0a-4 edits `apps/api/app/Modules/Identity/routes.php` (not in the lane's diff) rather than `RoleController.php`; the BatchExpiry gating and the glossary rows have moved to 0b. `lane/t1-transfers-edge` and `lane/t2-receipt-spine` have **empty** diffs against `dev` at their current refs, so neither creates a present overlap; T2's future edit to `apps/api/app/Modules/Inventory/Presentation/routes.php` is re-checked at 0a's merge, and 0a's only touch of that file is the single counting-item route.

**Resulting wave-0a task list (7 items):** 0a-1 land S-1 · 0a-2 route-coverage ratchet + baseline · 0a-3 `authz.self` middleware, alias and allow-list · 0a-4 gate the four role/permission read routes · 0a-5 gate the writes needing no new key (Promotion ×4, Uom ×5, Menu ×3, Coupon ×3, counting-item ×1 — **BatchExpiry excluded**) · 0a-8 docs · 0a-9 `RequireAnyPermission` message.

Reviewer gates: `tenancy-authz-reviewer` (all), plus `imports-reviewer` is **not** needed. Deploy: 0a-1 requires `permission:cache-reset` at deploy (already at `apps/api/docker/entrypoint.sh:176`); nothing else needs a deploy step. Rollback: every item is a revert of a self-contained commit; 0a-2's baseline reverts with its test.

### Wave 0b — new permissions for the remaining ungated writes

**Entry condition: `lane/w-lot-a-1a` merged into local `dev`** (D7). Reason: 0b adds permission keys to `RolesAndPermissionsSeeder::permissionNames()` and role grants to `rolePermissionGrants()`, which is the file that lane rewrites; landing both in parallel guarantees a conflict on an 871-line array and, worse, on the marked-tenant branch it introduces at `:568`.

Tasks: declare and gate `services.*` (3 keys) and `service-categories.*` (3), `channels.*` (4), `categories.*` (4), `progression.*` (2), `purchase-hub.orders.create` (1), `companies.create` (1), and **`credit-notes.cancel`** (0b-1, first). **Rev 2 adds four items relocated from wave 0a (B-9)**: **0b-5** gate the two `BatchExpiry` writes (`DELETE /api/v1/batches/{uuid}` → `batches.delete`, `POST /api/v1/batches/{uuid}/recall` → `batches.recall`) **on top of** the lane's rewritten routes file and its `BatchActionAccess` middleware, not instead of them; **0b-6** delete `PermissionSeeder.php` and its `ProductionSeeder.php:75` caller; **0b-7** fix the masking test (`apps/api/tests/Feature/Document/RefundResidualTenantIsolationTest.php:136-142`) **in the same commit as 0b-1**, so the suite is never knowingly red on a merged commit; **0b-8** the glossary rows, added on top of the lane's General-manager row. All keys land with `admin`-only template defaults except `credit-notes.cancel` (`manager`, `general_manager` — matching its siblings) and `services.*`/`service-categories.*` (`manager`, `general_manager`, because the FE alias map they replace already exposed them to managers, and removing a manager's ability to edit a service would be a functional regression, not a fix).

`POST /api/v1/companies` is the sharpest one: today `CreateCompanyRequest::authorize()` returns `true` and the controller then self-grants `MembershipRole::Owner` to the caller (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:151`), so any authenticated user in any of the seven seeded roles can create a company and become its owner. It gains `can:companies.create`, `admin`-only.

Files: the seeder array, each module's `routes.php`, `apps/web/src/hooks/permissionsMap.generated.ts` (regenerated), the label JSONs. Reviewers: `tenancy-authz-reviewer` + `frontend-conventions-reviewer`. Deploy: `tenants:seed RolesAndPermissionsSeeder`, `permission:cache-reset`, `permissions:export-frontend-map` — the sequence the T-2/T-3 spec already documents (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:420`). Rollback: revert; the added `permissions` rows are inert once the routes no longer reference them, and `permissions:prune-orphans` is not run.

### Wave 1 — catalogue as code

**Entry condition: wave 0a and 0b merged.** Deliverables: `PermissionVerb` (31 cases, `Close` included), `PermissionDefinition`, `PermissionManifest`, `PermissionRegistry`, ~30 per-module manifests, per-module enums, `PermissionRenameMap`, `PermissionSyncService` (`sync` **and** `syncRole`), `permissions:sync`, **`permissions:sync-fleet`** (§4.3.3), **`permissions:scaffold`** (§3.3), `permissions:export-label-skeleton`, **`LegacyRoleBaseline`** and the adoption pass (§4.3.0a), the `roles` migration (§5.2), **the `customised_at` writer in `RoleController::update`** (moved into wave 1 by B-2 — the column and its writer must not ship a wave apart), the seeder shim (§4.3.4), the entrypoint change plus the `/health` `permissions_sync` key (§4.3.3), and the guards `PermissionRegistryConsistencyTest` (including the `manage`/CRUD rule with its single `settings` grandfather), `PermissionEnumManifestParityTest`, `PermissionRegistryLabelCoverageTest`, `PermissionRegistryCoverageTest`, `PermissionSodTemplateTest` (hard half + the five-entry shrink-only baseline), `PermissionCatalogueParityTest` (transitional), `RoutePermissionLiteralTest`, `ScaffoldPermissionCommandTest`, plus the two static guards — `ForbidPermissionStringLiteral` (both error classes) and **`ForbidRoleNameAuthorization`** with its shrink-only baseline and the ESLint companion (§4.1.3a).

Overlap: this wave **owns** `RolesAndPermissionsSeeder.php` end to end. It cannot run concurrently with any lane that edits that file. It must be sequenced after `lane/w-lot-a-1a`'s five-push staging protocol has completed, not merely after its merge, because that protocol asserts pre/post role-permission snapshots on staging boots (plan `:4169`) and a registry-driven sync running in between would invalidate the comparison.

Deploy: `tenants:migrate-rolling` (the `roles` columns), then **`php artisan permissions:sync-fleet`** (never `tenants:run permissions:sync`, and there is no `--force` — B-5), then `permission:cache-reset`, then `permissions:export-frontend-map`. **New deploy-checklist row**: *after deploy, confirm `/health` reports `permissions_sync: ok`; if it reports `failed`, read the `PERMISSIONS-SYNC-FLEET` aggregate marker, which names every blocked or failed tenant id, and re-run the command for those tenants before considering the deploy complete.* **Staging soak: one week with `SYNC_PERMISSIONS_ON_BOOT=true`**, comparing each tenant's role-permission snapshot before and after every deploy and requiring **zero** unintended diffs — this is the direct replacement for the behaviour that caused I-20, so it gets the evidence bar that lane established. Rollback: the migration's `down()` drops the four columns (refusing while any `template_key` is set, mirroring W-LOT-A-1a's `down()` contract at plan `:1234`); the seeder shim reverts to the array in the same revert commit; `permissions:sync` is additive, so a rollback leaves extra permission rows, which are inert.

### Wave 2 — role management, principals and tokens

**Entry condition: wave 1 merged and soaked.** Two lanes, runnable in parallel within the cap.

**Additional entry condition on 2b, from B-1 (rev 2): no scoped token may be issuable until the role-name authorization sites are converted.** `service-accounts.issue-token` stays unrouted until `ScopedTokenIssuanceEntryConditionTest` is green — i.e. until 2a has converted `CreateDraftCountingRequest` (`:35`), the six `InventoryCountingController` bypasses (`:782,866,905,975,1297,1494`) and `DiscountPermissionResolver::isAdmin()` (`:39`) to permission checks (§4.1.3a (b)). Issuing a narrowing token while a role-name idiom can bypass the narrowing would ship the intersection guarantee as a claim rather than a property.

- **2a — role management hardening**: `roles.is_system` consumption; the single **`LastAdminFloor`** domain service wired into all nine writers of §4.6.3 (including `UserController::update`'s `syncRoles`) plus `LastAdminFloorWriterCensusTest`; F-1; D4 read gates **with the payload shaping of §4.6.4**; `RoleCreated/RoleUpdated/RoleDeleted` extending `DomainEvent` with their `identity.role.*` names, registered in `DomainEventSubscriber`; `(principal_id, token_id)` attribution added once in `AuditService::record()`; denial events **on `audit_events`** with the two-key Redis dedup and its Redis integration test; **`EffectivePermissionResolver`** with `forSubject`/`forTarget`; `GET /users/{id}/effective-permissions[?token_id=]`; the effective-permissions panel; `RolesPage` grouped by registry module with the template-version banner and *Re-apply template*; **and the conversion of the eight role-name authorization sites** that gates 2b.
- **2b — principals and tokens**: `PrincipalKind`, the `users` migration (§5.1 — including making `password` nullable and the full service CHECK), the `principal_kind` census edits across every writer listed in §4.1.1, the six `service-accounts.*` permissions and their routes, the seat exclusion plus the `max_service_accounts` plan limit (§4.1.7), `EnforceTokenScope`, the generalised `tokenScopePermissionNames()`, the `tenant:` claim at issuance, `TokenScopeData` on `/auth/me`, the `X-Company-Id` contract, **`MembershipRevocationService`** and the queued `RevokePrincipalTokens` retry, and the FE service-account + token screens.

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
| **D7** | *"Sequencing against in-flight W-LOT-A-1a (general_manager, batch permissions) and T-1..T-3 (`inventory.transfers.reconcile`)"* | — (no benchmark cell; a sequencing question) | *"Wave 0 lands **after** W-LOT-A-1a merges; the registry migration absorbs both lanes' permissions"* | §4.3.0, §4.3.0a (the superset table and template migration #1), §4.9.1, §8. Refined with evidence, and **re-scoped in rev 2**: wave 0a needs no dependency **only after four items moved to 0b** — rev 1's 0a claimed `apps/api/app/Modules/BatchExpiry/Presentation/routes.php` and `docs/glossary.md`, both of which that lane rewrites (B-9). With those moved, the verified 0a file list is disjoint from `git diff --name-only dev...lane/w-lot-a-1a`; 0b onward waits for the merge, and wave 1 additionally waits for that lane's staging protocol, not merely its merge |
| **D8** | *"Role mutation audit + denial logging into the audit chain (not the fiscal chain)?"* | Odoo: *"Opt-in tracking"*; ERPNext: *"Version tracking mandatory on Role"*; Dolibarr: *"Security events log"* | *"**Yes**, audit events for role create/update/delete and for 403 denials on write routes"* | §4.6.5 — with two refinements the ruling did not specify and a gate will ask about: the mutation event carries the **diff**, not the resulting set; and denials are deduplicated per `(principal, permission, route)` per 5 minutes with a `suppressed_count`, because unbounded denial logging is how denial logging gets turned off |

**And the new requirement (owner, 2026-09-10):** *"later we might use the MCP through service accounts, so every user that has an MCP should also have their permissions reflected."* Applied in §4.1 in full: service accounts are principals in the `users` table with ordinary memberships and roles (§4.1.1); their MCP/API tokens narrow, never widen (§4.1.3); the enforcement point is one middleware plus the existing `User::hasPermissionTo()` intersection, so all 917 existing authorization call sites inherit it without an edit (§4.1.4); attribution is `(principal_id, token_id)` on every audit row (§4.1.5); and the MCP's discovery call is the **unchanged** `/auth/me` plus one additive `token_scope` field (§4.1.6). G16 (§1) records that this is a deliberate DIVERGE from all three reference ERPs, none of which can scope a token below its user, and a MATCH with the industry norm they depart from.

### 9.2 Open questions (four; each with a benchmark and a recommended default, and the design is written against that default)

**Rev 2 changes to this section.** OQ-1's honesty is fixed (§§1–8 *do* hard-code its recommended answer, and now say so). OQ-2 is stated against the real current behaviour. **The old OQ-3 is deleted** — it reopened settled D8 and proposed a table that §5 said did not exist; denial events go on `audit_events` (§4.6.5, B-8). Its number is reused by a genuinely open question the gate surfaced: **the SoD baseline retirement**. OQ-4 stands, now that B-5 has made it safe to ask.

**OQ-1 — Do service accounts count toward the last-admin floor?**
*Benchmark.* No reference ERP has the concept, so none rules. The adjacent norm is Frappe Cloud's, which withholds the `Administrator` credential from tenant customers entirely and makes `System Manager` the human ceiling — i.e. the credential that can always recover a tenant is deliberately held by a *person*, not a process ([administrator](https://docs.frappe.io/erpnext/v13/user/manual/en/setting-up/users-and-permissions/administrator)). GitHub's equivalent rule is that an organisation must retain at least one human owner; a bot account cannot be the sole owner.
*Recommended default (applied throughout §4.6.3, EC-4):* **No.** A tenant whose only administrator is a machine has nobody who can log in when the token is lost, revoked or expired. The cost of the default is that a fully-automated tenant must keep one human admin, which is the correct answer to "who do we call".

*Rev 2 — what a "yes" would cost, stated honestly.* §0 claimed nothing in §§1–8 depends on an unresolved answer. That is not true of OQ-1: the recommended answer is **hard-coded** in the F-2 definition (§4.6.3), in EC-4 and EC-4a, and in the §2 Service-account row. If the owner rules **yes** (a service admin satisfies the floor), the edits are: drop `principal_kind = human` from *A*; drop EC-4; and re-word the Service-account glossary row. That is a small, bounded diff — but it is a diff, and rev 1 should have said so rather than claiming independence.

**OQ-2 — What is the default TTL for a service-account token?**
*Rev 2 — the current AutoERP behaviour, which rev 1 got wrong.* This is **not** a greenfield policy question: the repository already sets a global 30-day TTL (`'expiration' => env('SANCTUM_TOKEN_EXPIRATION', 43200)`, `apps/api/config/sanctum.php:43-53`), and a custom validation callback makes a per-row `expires_at` **override** that global (`apps/api/app/Providers/AppServiceProvider.php:201-219`). So today web back-office tokens expire in 30 days by the global and POS terminal tokens live one year by an explicit `expires_at` (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:146-176,294-310`). Any answer here **changes** that policy and must say what happens to the two existing token populations.

*Benchmark.* Sanctum upstream: none by default — *"By default, Sanctum tokens never expire"* — with a global `expiration` minutes setting, a per-token `expiresAt`, and `sanctum:prune-expired` ([§Token Expiration](https://laravel.com/docs/12.x/sanctum#token-expiration)). Odoo 18 moved the other way and made expiry **mandatory for non-admins**, with a per-group `api_key_duration` ceiling ([res_users.py#L195,2350,2413](https://github.com/odoo/odoo/blob/18.0/odoo/addons/base/models/res_users.py#L2350)). ERPNext and Dolibarr have no expiry at all. GitHub fine-grained PATs default to a bounded lifetime and cap custom values.
*Recommended default:* **365 days for a service token, set explicitly at issue with no unlimited option**, plus `sanctum:prune-expired --hours=24` on the daily schedule. Odoo 18 is the only reference system that revisited this recently and it moved toward mandatory expiry; a year is long enough that rotation is an annual chore rather than a weekly outage, and short enough that an abandoned integration's credential dies. **Human back-office tokens would shorten from today's 30 days to 90 days** — a *lengthening*, which is what makes this a real question rather than a formality — and **POS terminal tokens keep their explicit one-year `expires_at` unchanged**, because shortening them is an offline-till outage. All three are expressed as explicit per-token `expires_at` values rather than by moving the global, so the override semantics already in `AppServiceProvider` keep working and no existing token's lifetime changes retroactively.

**OQ-3 (replacement) — Should any of the five baselined `manager` SoD combinations be retired?**
*Context.* SoD-1 (§4.7) is a hard rule for new resources and a shrink-only ratchet over five combinations the `manager` template already ships: `invoices.create` + `.post`; `purchase-orders.create` + `.confirm`; `payments.create` + `.allocate`/`.refund`; `expenses.create` + `.post`/`.pay`; `inventory.adjustments.create` + `.post` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:593-610`). Removing any of them narrows what a manager can do on day one.
*Benchmark.* **Odoo** ships create and approve as distinct groups on purchases (the Purchase Order Double Approval group) but leaves a single-user small deployment free to hold both — the separation is a *shipped default on one flow*, not a runtime rule ([approvals](https://odoo-users.readthedocs.io/en/latest/purchase/purchases/rfq/approvals.html)). **ERPNext** gates each workflow transition per role, so separation is expressed per document type and is opt-in through Workflow ([workflow](https://docs.frappe.io/erpnext/workflow)). **Dolibarr** states it as convention only: do not grant validate to the group that has create. **None of the three implements NIST INCITS 359 SSD/DSD generically**, and none forbids the combination in a shipped default across the board. The domain norm is therefore: separate create from approve **where the flow is financially decisive and the tenant is large enough to staff it**, and let small tenants collapse the roles.
*Recommended default:* **retire two, keep three.** `invoices.create` + `.post` and `payments.create` + `.refund` are the two where the benchmark's own shipped defaults separate (an invoice becoming a fiscal fact, and money leaving) and where a two-person tenant can still operate by granting the second half to `general_manager`. The other three (`purchase-orders.confirm`, `expenses.post`/`.pay`, `inventory.adjustments.post`) stay with `manager`, because a five-person pharmacy legitimately has one person who raises and confirms a PO — and forcing an admin into that loop is how operators end up sharing the admin login, which is a worse outcome than the SoD gap. **This is an owner decision and the design ships the baseline unchanged until it is made**; the ratchet guarantees the number can only fall.

**OQ-4 — After wave 1, does `SYNC_PERMISSIONS_ON_BOOT` default to `true` in production?**
*Benchmark.* Both reference systems that solved this sync it automatically: Odoo re-applies `ir.model.access.csv` on every install **and** upgrade, and ERPNext syncs DocPerms on every `bench migrate` — neither asks an operator to opt in, because a permission that exists in code and not in the database is a broken feature ([security tutorial](https://www.odoo.com/documentation/19.0/developer/tutorials/server_framework_101/04_securityintro.html), [bench migrate](https://docs.frappe.io/framework/user/en/bench/reference/migrate)). Dolibarr is the outlier that requires a human to re-activate the module, and is the one with the worst drift story.
*Rev 2 precondition.* This question is only safe to ask **after B-5 is implemented**. Defaulting boot sync on while the runner discarded child exit statuses and the entrypoint continued past errors (`apps/api/docker/entrypoint.sh:150-169`) would have made a partial fleet sync indistinguishable from a clean one. With `permissions:sync-fleet` exiting non-zero on any blocked/failed tenant and `/health` reporting `permissions_sync: failed` (§4.3.3), "on by default" becomes a decision about convenience rather than about visibility.
*Recommended default:* **Yes — `true` in production, after the one-week staging soak in §8 shows zero unintended diffs.** The reason the flag exists today is that the thing it ran was destructive (I-20); once it runs an additive, template-delta sync that provably never touches a custom role or a customised system role, keeping it off would reproduce the original problem — new permissions silently missing on live tenants, which is exactly the recurring "→ 403" gap the entrypoint comment already records for `uom.view` (2026-06) and `loyalty.enroll` (2026-07) (`apps/api/docker/entrypoint.sh:156-161`). The conservative alternative — leave it `false` and run `php artisan permissions:sync-fleet` as a named deploy step (never `tenants:run`, per B-5) — is a real option and costs one runbook line; it is worse only because a step a human can forget is a step a human will forget.

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

---

## 11. Change log rev 1 → rev 2 (Codex spec gate r1 fix round)

Register: `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r1.md` (9 BLOCKER / 9 MAJOR / 5 minor / 1 citation audit, verdict CHANGES-REQUIRED). Every finding below was **re-verified against the tree before being applied**; the "verified at" column names the line actually read. Orchestrator rulings are marked **[R]** and are binding.

| ID | Disposition | Verified at | Closed at anchor |
|---|---|---|---|
| **B-1** token narrowing misses idioms | **CLOSED [R]** | `config/permission.php:103-107`; `User.php:185-212,216-241`; 8 `hasRole` sites: `CreateDraftCountingRequest.php:35`, `InventoryCountingController.php:782,866,905,975,1297,1494`, `DiscountPermissionResolver.php:39`; FE `usePermissions.ts:244,251`, `operatorStore.ts:354` | §4.1.3a — idiom table; `ForbidRoleNameAuthorization` + ESLint companion with an exact shrink-only baseline (a); conversion made an **entry condition** of token issuance (b); `EffectivePermissionResolver::forSubject`/`forTarget` (c); PIN payload unchanged and service accounts never PIN holders; EC-18 extended, EC-18a added |
| **B-2** sync ≠ superset of W-LOT-A-1a | **CLOSED [R]** | `lane/w-lot-a-1a:LotActionPermissionDelta.php:139-155`; `lane/…:RolesAndPermissionsSeeder.php:44-51`; `RoleController.php:223-247` | §4.3.0a — delta adopted as **template migration #1**, sync *invokes* it; legacy adoption rule with `LegacyRoleBaseline` (`template_version = 0`, pristine → `customised_at = NULL`, else `now()`, classification logged); `customised_at` writer **moved into wave 1**; compatibility mode runs adoption; `is_system` set only on template-linked adoption. Step 4a added; EC-9, EC-9a, EC-21 |
| **B-3** general-manager contradicts the lane | **CLOSED [R]** | `lane/…:GeneralManagerAssignmentGuard.php:19-23,36-49`; `lane/…:docs/glossary.md:21,91` | §2 row adopted verbatim (definition, invariant, **Settings → Users**, synonym *central manager*); EC-15 **reversed** to a 422 refusal; EC-15a added |
| **B-4** last-admin floor misses a writer | **CLOSED [R]** | `UpdateUserRequest.php:44-62`; `UserController.php:380-389`; `CompanyController.php:147-155` | §4.6.3 — single `LastAdminFloor` service, nine-writer table incl. `UserController::update`; *A* requires ≥1 active membership; deterministic `users.id`-ascending lock order; writer-census test; EC-4a, EC-4b |
| **B-5** unsafe deploy entrypoint | **CLOSED [R]** | `SeedChartsCommand.php:31-36`; `entrypoint.sh:150-170`; plan `:2956` | §4.3.3 — `permissions:sync-fleet` iterates tenants itself, aggregates exact verdicts, **exits non-zero** on any failure; `--force` removed; entrypoint keeps booting but writes a failure marker and `/health` reports `permissions_sync: failed`; deploy-checklist row; EC-21a |
| **B-6** catalogue cannot compile | **CLOSED [R]** | seeder `:549-554` (only `settings` mixes `manage`+CRUD, re-derived over all 304 keys) | §4.2.2 — `PermissionVerb::Close` added (31 cases); `manage` invariant restated with `settings` grandfathered and shrink-only; §3.3 (b) asserts `activeKeys()`; AI-1 restated honestly + **`permissions:scaffold`** generator |
| **B-7** `authz.self` is a ratchet bypass | **CLOSED [R]** | `bootstrap/app.php:114-123` | §4.4.2 — exact `(method, uri)` allow-list (7 rows, set equality both ways) + structural no-foreign-parameter test; the ratchet classifies from the allow-list, not from the middleware; `TOMBSTONE` class added |
| **B-8** OQ-3 reopens settled D8 | **CLOSED [R]** | — | §4.6.5 — denials on `audit_events`, no `security_events` table; OQ-3 deleted from §9.2 and its number reused for the SoD baseline question |
| **B-9** wave 0a is not disjoint | **CLOSED [R]** | `git diff --name-only dev...lane/w-lot-a-1a` (66 files) incl. `BatchExpiry/Presentation/routes.php`, `docs/glossary.md`; t1/t2 diffs empty | §8 — 0a-5's BatchExpiry portion, 0a-6, 0a-7 and 0a-10 **moved to 0b**; verified 0a file list stated; t1/t2 re-checked and re-worded |
| **M-1** principal census incomplete | **CLOSED [R]** | `UserStatus.php:10-15`; `User.php:115-125`; `AuthController.php:249-268`; `UserController.php:281-292,786-808`; `2026_03_23_000001…:14-23`; `PlanLimitsService.php:71-86` | §4.1.1 + §5.1 — status in the CHECK; `password NULL` (sentinel rejected: the `hashed` cast makes `'!'` a valid hash); `email NULL`; kind check **before** `Hash::check`; notification paths skip services; full writer census. Seats **[R]**: excluded from human seats, new `max_service_accounts` (default 5), Odoo contrast in G16 — a decision, not an owner question. `token_id` selector added |
| **M-2** Sanctum/revocation details | **CLOSED [R]** | PAT migration `:17-21` (TEXT); `sanctum.php:43-53`; `AppServiceProvider.php:201-219`; `ResolveTenancy.php:114-134`; `SuperAdmin.php:14-32`; `SetPermissionsTeam.php:22-29` | §2, §5.3, §4.1.4, §4.1.5 — abilities are TEXT; TTL 30 d global / POS 1 y explicit; `tenant:` claim required **at issuance**; `MembershipRevocationService` with best-effort central revoke + retry queue, atomicity claim withdrawn; EC-16 rewritten, EC-16a added; **EC-22 withdrawn** (number retained) |
| **M-3** read gates violate D4 | **CLOSED** | `RoleController.php:429-437` | §4.6.4 — `users.assign-roles` → role names only on `userRoles`; effective endpoint requires `roles.view` for non-self; three-row shaping table |
| **M-4** events/dedup incomplete | **CLOSED** | `RoleAssigned.php:14-43`; `DomainEventSubscriber.php:1181-1209,1194-1208`; `AuditService.php:43-84` | §4.6.5 — events extend `DomainEvent` with `identity.role.created\|updated\|deleted`, registered in the subscriber; attribution added once in `AuditService::record()`; dedup = emission lock + separately-TTL'd retained counter, Redis integration test; EC-28 |
| **M-5** static guard misses unknowns | **CLOSED** | `phpstan.neon:5-8,33-47` | §4.5.1 — unknown dotted literals are an error class of their own; `RoutePermissionLiteralTest` AST-walks `app/Modules/*/Presentation/routes*.php` and `routes/`; "without a mass baseline" withdrawn |
| **M-6** convention 09/10/11 gaps | **CLOSED** | `TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:131-132,160,175`; `CategoryController.php:28-35,101-108,115-178` | §6 — exclusions already exist (obligation is not to invalidate them); category second-location test replaced by a second-**company** test plus a location-axis test on a location-bound resource; G8/G13/G15 given code `path:line`; every glossary noun given a real operator read surface; `UserService` withdrawn |
| **M-7** edge-case dispositions | **CLOSED [R]** | seeder `:593-610`, `:264-268` | §7 — EC-5, 8, 9, 10, 13, 15, 18, 19, 20, 21, 28, 30, 32 all rewritten; EC-22 withdrawn; seven rows added. **EC-30 [R]**: SoD hard for new resources, shrink-only ratchet over the five listed `manager` combinations, retirement is OQ-3; `confirm` added to `isFinanciallyDecisive()` |
| **M-8** cache flush before commit | **CLOSED** | — | §4.3.2 step 7 — `DB::afterCommit` hook, fired while the team id is still the tenant's; EC-8 |
| **M-9** contradictory deprecation set | **CLOSED [R]** | audit `01-backend-permission-model.md:97-104` (25 entries expanded; prose "26" is wrong) | §4.9.3 — one authoritative list of **22** (25 − 3 excluded), re-derived and printed in full; re-derivation after wave 0a is a gate item |
| minor 1 `Gate::before` | CLOSED | `config/permission.php:103-107` | G6, §2 Super admin, §3.2, E-3 — "no **application-authored** global bypass" |
| minor 2 seeder census "five" | CLOSED | 7 PHP references listed in the register | §4.3.4 — full list of seven |
| minor 3 tombstones ≠ public | CLOSED | audit `02-route-enforcement-sweep.md:66-71` | §4.4.2 — named `TOMBSTONE` classification with a 410 assertion |
| minor 4 wave-4 ditto marks | CLOSED | — | §6 wave-4 row spelled out |
| minor 5 generic action labels | CLOSED | `RolesPage.tsx:37-42` | §4.2.1 docblock — intentional, with the reason |
| Citation audit | CLOSED, **one row partially rejected** | see below | base SHA (re-verification SHA added, no rebase); `glossary.md:16 → :20`; W-LOT marker `→ lane/…:44-51`; `User.php:216-241 → :185-212` overrides vs `:216-241` extraction; `RoleController :429-444 → :429-437`; `RBACTest` full path; abilities TEXT; ratchet exclusions; general-manager; 0a overlap; t1/t2 |

**Partially rejected — one citation-audit row.** The register calls the spec's *"Email remains unique using original users migration `:34`"* a **stale citation**. The *substance* is accepted and applied (§5.1 now cites the partial unique at `2026_03_23_000001_make_user_email_nullable.php:14-23` and drops the synthetic address entirely). The *line number* claim is rejected: `apps/api/database/migrations/tenant/2025_11_30_000003_create_users_table.php:34` is exactly `$table->unique(['tenant_id', 'email']);` — verified by reading the file — so `:34` was correct for the constraint it named. What was wrong was calling that constraint the effective schema, not the line reference. Recorded so a later gate does not "fix" a correct citation.

**Nothing else was rejected.** All nine BLOCKERs, all nine MAJORs, all five minors and every other citation row were reproduced against the tree before being applied. The register's own "Rejected false positives" and "Preserve" lists were honoured: the **326 / 177 / 149 → 167 / 148** ratchet arithmetic, the live-router feasibility of the ratchet, the PG CHECK expressions, the NULL-team predicate, `admin = activeKeys()`, the generated TS union under rule 7, the absence of Spatie `role:` middleware, the `PermissionSeeder` deletion evidence, and central token storage are all unchanged in rev 2.

**Open questions now listed (§9.2), all four:** OQ-1 human-only last-admin floor (recommended **no**, and §§1–8 hard-code it — the bounded edit if the owner rules otherwise is stated); OQ-2 token TTL (recommended service 365 d / human 90 d / POS 1 y unchanged, stated against today's real 30-day global); **OQ-3 (replacement) SoD baseline retirement** — retire two of the five `manager` combinations, keep three, with the Odoo/ERPNext/Dolibarr baseline; OQ-4 `SYNC_PERMISSIONS_ON_BOOT` default in production (recommended **yes** after the soak, now that B-5 makes a partial fleet sync visible).

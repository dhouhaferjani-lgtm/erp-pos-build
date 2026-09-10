# Roles & permissions — catalogue as code, principals & tokens, enforcement standard — design spec rev 3

Date 2026-09-10 · Lane family: RBAC (waves 0a, 0b, 1, 2, 3, 4) · Status: **REV 3, gate r2 fix round applied — for Codex adversarial gate round 3** · Direction: **B (catalogue-as-code with deploy-time sync)**, accepted by the owner 2026-09-10.

## 0. Status, base SHA, sources, decided vs open

**Base SHA.** Every `path:line` in this document was read at **`971528977`** (`git rev-parse --short HEAD` in the worktree `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rbac-audit`, branch `docs/rbac-audit-2026-09-09`, rebased onto `dev` as the first step of this lane, clean rebase, no conflicts). Paths are relative to the repository root `apps/erp/` unless the citation itself begins with `apps/`. No code, test or configuration file is modified by this lane — it is a document-only branch.

**Re-verification SHA (rev 2).** Codex gate r1 re-read every cited path at **`4f5d2dd46`** and confirmed the reviewed spec blob and **all reviewed application paths are byte-identical across `971528977…4f5d2dd46`** — the intervening commits are document-only (`docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r1.md`). The rev-2 fix round re-verified each applied finding against the tree at `b43660328`, again with no application-code change in between. The branch is therefore **not rebased**: `971528977` stays the declared read SHA because it still names the same code, and the two later SHAs are recorded so a gate can reproduce the check without a rebase (`git diff 971528977 b43660328 -- apps/api apps/web apps/pos packages` is empty).

**Re-verification SHA (rev 3).** Codex gate r2 re-read every cited path at **`83da1248c`** and independently confirmed `git diff 971528977..83da1248c -- apps/api apps/web apps/pos packages` is **empty** (`docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r2.md:3-9`). The rev-3 fix round re-verified every applied finding against the tree at **`5b658d681`** (the commit that persisted the r2 register and its prompt), again document-only. `971528977` therefore remains the declared read SHA for all four SHAs, and every `path:line` in this document still names the same bytes. The lane branch `lane/w-lot-a-1a` was read at **`04e60530c`**; `lane/t1-transfers-edge` at **`86273346a`** and `lane/t2-receipt-spine` at **`b41183f50`**.

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
| G8 | Create and approve on a financially significant document are never the same grant | Purchase double-validation group, distinct from create/confirm ([approvals](https://odoo-users.readthedocs.io/en/latest/purchase/purchases/rfq/approvals.html)) | Workflow transitions gated per role ([workflow](https://docs.frappe.io/erpnext/workflow)) | by convention: don't grant validate to the group that has create | partly true in practice and **already violated in the shipped templates**: `payments.reverse` is deliberately admin-only (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:264-268`, owner decision ratified 2026-07-10) and the `pos.approve_*` family exists, but the `manager` template holds `invoices.create` **and** `invoices.post` on one line (`:598`), `credit-notes.create` + `.post` (`:599`), `purchase-orders.create` + `.confirm` (`:594`), `supplier-invoices.create-pending` + `.approve-invoice-first` (`:595`), `payments.create` + `.pay-supplier` + `.allocate` + `.refund` (`:609`), `expenses.create` + `.post` + `.pay` (`:605`) and `inventory.adjustments.create` + `.post` (`:603`) — and `accountant` repeats four of those (`:829,832,836,841`). *(Rev 3 corrects two rev-2 line numbers: expenses are `:605` not `:606`; adjustments are `:603` not `:602`, which is transfers.)* There is **no stated rule and nothing enforcing it**; NIST INCITS 359 SSD/DSD is implemented by none of the three | rule is unwritten **and** today's `manager` and `accountant` templates would both fail it | **MATCH (lightweight)** — `sod_group` on each definition + a registry test that is a **hard rule for new resources and a shrink-only ratchet over the eleven baselined combinations enumerated in §4.7** (§4.7), wave 1 |
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

It is idempotent, refuses when the key is absent from the module's manifest (the definition is the input, never the output), and **touches exactly six files across three artifact kinds** — rev 3 corrects rev 2's "writes exactly three artifact classes and nothing else", which its own table then contradicted by listing six files, and normalises the paths, which rev 2 wrote half root-relative and half repo-relative (M-8):

| Artifact kind | File(s), repo-relative | What it writes |
|---|---|---|
| the module enum | `apps/api/app/Modules/<Module>/Domain/Enums/<Module>Permission.php` | one `case` in registry order, named by `StudlyCase(resource without the module prefix) . StudlyCase(action)` — the exact name `PermissionEnumManifestParityTest` expects |
| the authoritative locale | `apps/web/src/locales/en/common.json` | `permissions.modules.<module>` and `permissions.actions.<action>`, value = the humanised action; **English is authoritative and complete on the same commit** |
| the placeholder locales | `apps/web/src/locales/fr/common.json`, `apps/web/src/locales/ar/common.json`, plus `apps/api/storage/app/permission-labels/fr.todo.json` and `…/ar.todo.json` | the same two keys with the English string as a placeholder, and the key recorded in the matching `.todo.json`, so `PermissionRegistryLabelCoverageTest` passes structurally while the translation lane sees exactly what is owed |

**The write contract is deterministic, byte-for-byte, on a clean worktree (rev 3, M-8).** A generator whose output depends on insertion order or formatting cannot support the byte-identity drift guard §4.5.3 relies on (`scripts/preflight.sh:136-149`), and rev 2 asserted only that a *second* run is a no-op — which is compatible with a first run that differs between two clean checkouts. Four rules, each mechanically checkable:

- **JSON key ordering.** A key is inserted into the `permissions.modules` / `permissions.actions` object at its **sorted position** (`ksort` over the object's keys after insertion), never appended. Two developers adding two keys in either order therefore produce the same file.
- **JSON formatting.** Re-encoding uses `json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)` plus a single trailing newline — fixed flags, so Arabic labels and any `/` in a value round-trip unchanged and the whole file's formatting is a pure function of its content. The command **rewrites the whole file** rather than splicing text, which is what makes that true.
- **Enum insertion anchor.** The new `case` is inserted immediately **before the closing brace** of the enum declaration, one per line, with the file's existing indentation — a fixed anchor, not "after the last case matching a pattern". Cases are then whole-file re-sorted into registry order, so the enum is also order-independent.
- **Duplicate detection and atomicity.** A key already present in any of the six files is left exactly as it is (that is what "idempotent" means here). Every file is written via a temp-file-then-`rename` so an interrupted run leaves either the old file or the new one, never a truncated `common.json` — these are 1 300-line files that the whole FE loads.

`ScaffoldPermissionCommandTest` asserts all four: a second run is a byte-level no-op; **two different clean-worktree runs adding the same two keys in opposite orders produce byte-identical files**; the flags survive a non-ASCII value; and a fresh definition passes (a)–(e) with no hand edit. The fr/ar placeholder remains the one deliberate softening: blocking a backend merge on a human translation would make the label test the thing people disable. Structural coverage is CI-enforced; translation quality is a named wave-1 deliverable with an owner (§4.5.2).

**How each clause is proven (the executable half — no clause is a promise without a named test).**

| Clause | Proof | Lane |
|---|---|---|
| (a) | `PermissionsSyncTest::sync_creates_every_registry_permission_in_a_fresh_tenant_and_in_a_second_tenant` (PG, two real tenant databases via `ProvisionsTenantDatabases`) | wave 1 |
| (b) | `PermissionsSyncTest::admin_holds_exactly_the_registry_list_after_sync` — asserts set equality against **`PermissionRegistry::activeKeys()`**, matching what sync actually assigns (§4.3.2 step 8). Rev 1 asserted against `keys()`, which includes deprecated keys and would have made the test red the moment the first key was deprecated; corrected in rev 2. It also proves `Permission::all()` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:568`, I-19) is gone | wave 1 |
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

**Impact census — every existing `users` writer that must learn about `principal_kind` (rev 3 completes the list the gate found short — M-3).** Each creates or mutates a `users` row and must either set `principal_kind = 'human'` explicitly or rely on the column default and be asserted to do so: self-registration (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:390-400`); DB-per-tenant provisioning (`apps/api/app/Modules/Tenant/Application/Services/TenantProvisioningService.php:152-162`); user administration — create, update, deactivate, delete, restore, bulk (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:217-250,357-391,470-484,548-550,630-642,1019-1025`); the POS raw PIN update (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:295-307`); login metadata (`apps/api/app/Modules/Identity/Domain/User.php:274-281`); the data migration writer (`apps/api/database/migrations/tenant/2026_03_23_200000_fix_discount_permission_defaults.php:13`); and the seed writers (`apps/api/database/seeders/DatabaseSeeder.php:293,331`; `CoffeeShopSeeder.php:1249,1278`; `ParapharmacySeeder.php:1415,1453,1488`; `DemoPharmacySeeder.php:962,1032`; `DemoTenantSeeder.php:208,559,1513,1610,1684,1758,1832,1906,1980,2054`).

**Four writers rev 2 omitted, each with its required change (rev 3, M-3).** They are not merely "also touch `users`" — three of them write a column the §5.1 service CHECK forbids on a service row, so a missed guard becomes a database exception rather than the promised 422:

| Writer | What it writes | Required change |
|---|---|---|
| `EmailVerificationService::verifyEmail()` (`apps/api/app/Modules/Identity/Application/Services/EmailVerificationService.php:68-71`) | `email_verified_at = now()` | **Unreachable for a service principal in practice** (a service has `email IS NULL`, so no token is ever issued to it — `sendVerificationEmail` at `:27-41` notifies through the null-email path) but it is a direct `$user->save()` on an arbitrary token's user, so it gains an explicit `isServicePrincipal()` guard returning the existing invalid-token shape. Belt over the brace, per the same argument §4.10 item 3 makes for `pinHolders()` |
| central `SuperAdminController::verifyUserEmail()` (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:505-528`, the write at `:525`) | `email_verified_at = now()` inside `$tenant->run()` | refuses a service target with 422 `SERVICE_PRINCIPAL_WRONG_SURFACE` **inside the closure**, before the update, since the closure resolves the row (`:517`) |
| `UserController::setPosPin()` — **both arms** (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:703-745`: the clear arm writes `pos_pin => null` at `:706`, the set arm writes the PIN at `:739`) | `pos_pin` | 422 `SERVICE_PRINCIPAL_CANNOT_HOLD_PIN` on **both** arms, before either write. Rev 2 named this route but only its set arm; clearing a PIN a service cannot have is still a write to a row the CHECK constrains, and refusing both keeps one error contract |
| `PosAuthController::setupPin()` (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:120-146`, the write at `:146`) | `pos_pin` on **the caller's own row** | same 422. This is the self-service PIN path rev 2 missed entirely — EC-32 cited only the raw sync writer at `:295-307` |

**Notification writers — the blanket rule gets an inventory (rev 3, M-3).** "Every notification writer short-circuits on `principal_kind = service`" was a rule with no list, and the risk is not the mail itself (a service has `email IS NULL`) but **permission-selected recipient sets**, which will start including service principals the moment one holds the permission. Three exist today, all re-derived at `971528977`:

| Recipient selector | Site | Change |
|---|---|---|
| the token's own user | `EmailVerificationService::sendVerificationEmail()` (`…/EmailVerificationService.php:27-41`) | guarded by the writer row above |
| `->permission('enrichment.view')` over active memberships | `SendEnrichmentNotificationListener` (`apps/api/app/Modules/Identity/Application/Listeners/SendEnrichmentNotificationListener.php:18-24`) | add `->where('principal_kind', PrincipalKind::Human->value)` to the builder |
| `->permission('batches.view')` over active memberships | `BatchExpiryDailyCheckCommand` (`apps/api/app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php:215-225`) | same predicate on the builder at `:215-222` |

`NotificationRecipientCensusTest` asserts by static analysis that every production `Notification::send`/`->notify()` whose recipient set is built by a query carries the human predicate — so a fourth selector added later cannot quietly acquire a machine recipient.

**Mass assignment (rev 3, M-3).** `User::$fillable` (`apps/api/app/Modules/Identity/Domain/User.php:81-97`) contains none of `principal_kind`, `principal_description`, `created_by_user_id`; all three are added there, and `principal_kind` is added to `casts()` beside the existing entries (`:115-126`). Without the `$fillable` change, `ServiceAccountProvisioningService`'s `User::create()` would silently drop all three and every service account would persist as a human — a failure with no error at all. `UserFillableContractTest` asserts the three names are present.

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

Template defaults: **`admin` only**. `general_manager` and `manager` receive none — issuing a machine credential is an administrative act, and B4/D2 says a new permission never lands in a non-admin template without a deliberate declaration. Routes live in a new `apps/api/app/Modules/Identity/routes.php` group whose middleware stack is `['api', 'auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class, EnforceTokenScope::class]` (the pattern at `apps/api/app/Modules/Identity/routes.php:57`, **with `EnforceTokenScope` listed explicitly** even though §4.1.4 also attaches it to the global `api` group — a duplicate entry in a stack is idempotent, and listing it here makes the service-account surface's dependency visible in the file a reviewer reads) plus `can:` per route (§4.4).

A service account **cannot** be assigned a role the actor does not hold — the existing `AssignableRole` escalation guard applies unchanged, and §4.6's last-admin floor treats a service principal as *not* counting toward the floor (open question OQ-1, §9.2, default: does not count).

**Creation contract (rev 3, M-2).** `POST /service-accounts` is the *only* write path that may create a `principal_kind = service` row, and it must establish the principal's memberships in the same transaction — a service token without an active company membership is refused by `EnforceTokenScope` (§4.1.4) and is therefore useless. Rev 2 declared the route and left the payload undefined.

```php
// apps/api/app/Modules/Identity/Presentation/Requests/CreateServiceAccountRequest.php
// authorize(): $user->can('service-accounts.create')
//   AND, when any membership carries allowed_location_ids,
//       $user->can('users.manage_location_access')   // the same gate the human path uses
//       (CreateUserRequest.php:27-29, UpdateUserRequest.php:27-29; the 403 body shape at
//        CreateUserRequest.php:61-76 is reused verbatim so the error contract is one contract)
public function rules(): array
{
    return [
        'name'                                => ['required', 'string', 'max:255'],
        'description'                         => ['nullable', 'string', 'max:255'],
        'memberships'                         => ['required', 'array', 'list', 'min:1'],
        'memberships.*.company_id'            => ['required', 'uuid'],
        'memberships.*.allowed_location_ids'  => ['sometimes', 'nullable', 'array', 'list'],
        'memberships.*.allowed_location_ids.*'=> ['uuid'],
        'roles'                               => ['required', 'array', 'list', 'min:1'],
        'roles.*'                             => ['string', 'exists:roles,name', new AssignableRole($currentUser)],
    ];
}
```

Three properties this contract fixes, each checkable:

1. **One writer.** The transaction lives in **`App\Modules\Identity\Application\Services\ServiceAccountProvisioningService`** (constructor-injected into `ServiceAccountController`, `private readonly`, rule 13), which creates the `users` row, the `user_company_memberships` rows and the location grants, and assigns the roles. It is the same shape `UserController::store` uses inline today (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:217-258`) — extracted rather than duplicated, so `ServiceAccountWriterCensusTest` can assert no other production path inserts a `principal_kind = 'service'` row. `UserController::update` is **not** a service-account writer: `UpdateUserRequest` exposes `email`, `phone`, `locale`, `can_discount`, `max_discount_percent`, `role` and `allowed_location_ids` (`apps/api/app/Modules/Identity/Presentation/Requests/UpdateUserRequest.php:44-63`), six of which are invalid for a machine, so `UserController::update` refuses a service target with 422 `SERVICE_PRINCIPAL_WRONG_SURFACE` and names `PATCH /service-accounts/{id}`.
2. **Mass assignment is explicit.** `User::$fillable` (`apps/api/app/Modules/Identity/Domain/User.php:81-97`) gains exactly `principal_kind`, `principal_description` and `created_by_user_id`; without that the `User::create()` in the provisioning service would silently drop all three and every row would be a human by default. `principal_kind` also joins `casts()` beside the existing entries (`:115-126`).
3. **Membership changes have a surface.** `PATCH /service-accounts/{id}` covers name, description and active status only; **`PUT /service-accounts/{id}/memberships`** (same `service-accounts.update` gate, plus `users.manage_location_access` when a location list is supplied) is the one place a service principal's companies and `allowed_location_ids` change, and it routes through `MembershipRevocationService` (§4.1.5) for any removal so the token-revocation trigger cannot be bypassed.

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

New middleware `App\Modules\Identity\Presentation\Middleware\EnforceTokenScope`.

> **RULING (orchestrator, 2026-09-10 — rev 3, B-1). Ordering is not attachment: the middleware is ATTACHED to the global `api` group AND kept in the priority list.** Rev 2 added only a priority-list insertion, and Laravel's priority list sorts middleware a request *already carries*; it attaches nothing. The current global `api` group carries neither `SetPermissionsTeam` nor either token-enforcement middleware (`apps/api/bootstrap/app.php:133-153`) — protected routes attach them per group (`apps/api/app/Modules/Identity/routes.php:39-57`; `apps/api/routes/api.php:50-54`) — so every check §4.1.4 promises would simply never have run, and EC-16a's "the next request is refused" would have been false. Two changes, not one:
>
> 1. **Attach.** `EnforceTokenScope::class` is appended to the global **`api`** group beside the existing entries at `apps/api/bootstrap/app.php:143-153`. It is a **no-op** on any request that has no authenticated user (`$request->user() === null` → `return $next($request)`) and on any authenticated request whose token carries no narrowing scope *and* whose principal is human — so adding it to a group that also serves unauthenticated and public routes costs one null check. That no-op contract is what makes global attachment safe, and `EnforceTokenScopeNoOpTest` asserts it for an unauthenticated request, a session request and an unscoped human token.
> 2. **Order.** The priority-list insertion stays exactly as rev 2 wrote it (`apps/api/bootstrap/app.php:182-185` is the insertion site; the new call is `appendToPriorityList(after: EnforceTokenTenantClaim::class, append: EnforceTokenScope::class)` and the existing `ImpersonationContext` append re-anchors to `EnforceTokenScope`), because group membership alone does not guarantee it runs **after** `Authenticate` — and it must, since every check it performs needs a resolved `User`.
>
> **The coverage proof is an architecture test, not a convention.** `EnforceTokenScopeCoverageTest` boots the live router (the mechanism §4.4.3 already uses) and asserts that **every** route whose resolved middleware stack contains `auth:sanctum` also resolves `EnforceTokenScope` in that stack, with **no allow-list**. A route group that opts out of the `api` group and forgets the middleware fails it. This is the same shape as the existing `apps/api/tests/Architecture/AuthLifecycleTest.php`, which already asserts the `api`/`auth:sanctum`/`SetPermissionsTeam` stack per route (§1 G15) — one more assertion in a proven mechanism.

Because the middleware is on the group rather than on a hand-picked set of routes, service-principal **status** and **active-company-membership** enforcement live in exactly one place and reach every authenticated surface — including the ungated and self-service ones the §4.4.3 ratchet deliberately leaves without a `can:`. In particular **`GET /api/v1/auth/me` is covered**: it is an `authz.self` route with no action gate (§4.4.2 allow-list), so before this ruling an inactive service principal, or one whose last membership had been revoked, could still read its own effective-permission payload. It cannot now.

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

Subscription usage counts every `users` row today. Left alone, creating a service account would silently consume a paid human seat — the gate raised this as an unanswered design question, and it is answered here rather than added to §9.2.

> **Ruling (orchestrator, 2026-09-10).** Service principals are **excluded from the human seat count** and capped by a **new plan limit `max_service_accounts`, default 5**. The benchmark contrast is recorded in §1 G16: all three reference ERPs bill a bot as a user, which is precisely what drives operators to share one human login with a script.

**The ruling applies to EVERY counter, not one (rev 3, M-1).** Rev 2 changed only `PlanLimitsService::getUsage()` and mis-cited the enforcement point as `PlanEnforcementService.php:325-330`, which is percentage shaping and enforces nothing. There are six all-row `users` counts in the tree, re-derived at `971528977`, and **every one of them gains `->where('principal_kind', 'human')`** — a service account that is excluded from the usage display but still consumes the admission check is worse than not excluding it at all, because the two numbers then disagree:

| # | Site | What it drives | Change |
|---|---|---|---|
| 1 | `PlanLimitsService::getUsage()` (`apps/api/app/Services/PlanLimitsService.php:83-85`) | the usage array | add the predicate; add a `service_accounts` key counting `principal_kind = 'service'` |
| 2 | **`PlanEnforcementService::canAddUser()`** (`apps/api/app/Modules/Billing/Application/Services/PlanEnforcementService.php:162-176`, the count at `:165`) | **admission** — the check run before a human is created | add the predicate. This is the real enforcement point rev 2 missed |
| 3 | `PlanEnforcementService::getUsageStats()` (`:290-305`, the count at `:296`) | the tenant-facing usage/percent panel | add the predicate |
| 4 | `PlanEnforcementService::calculateUserOverage()` (`:426-444`, the count at `:434-436`) | **per-user overage billing** | add the predicate — without it an integration credential is invoiced as an extra human |
| 5 | `SuperAdminController` tenant detail (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:88-97`, the count at `:93`) | the support/admin display | add the predicate; surface `service_accounts_count` beside it so support can still see them |
| 6 | `TenantFleetStatsService::getUserAndCompanyTotals()` (`apps/api/app/Services/TenantFleetStatsService.php:36-51`, the count at `:49`) | fleet totals | add the predicate |

`PlanSeatCensusTest` asserts by static analysis that no production `users` row count exists outside this list without the predicate — the same census shape §4.6.3 uses for the last-admin floor, and the reason rev 2's single edit was not enough.

**`max_service_accounts` joins the limits contract properly.** "Lands in the plan seed" was incomplete: the key is a **constant on `PlanLimits`** beside `MAX_USERS` (`apps/api/app/Modules/Billing/Domain/PlanLimits.php:19-35`), and it is added to **every plan-tier default array** — `trial()` (`:161-173`) and each of its siblings — because a limit absent from a tier's array falls back to whatever default the reader passes, which is how a cap silently becomes zero or unlimited. Its **enforcement point** is a new `PlanEnforcementService::canAddServiceAccount(Tenant $tenant): bool`, written in the same shape as `canAddUser()` (`:162-176`) and called by `ServiceAccountProvisioningService` (§4.1.2) inside the creation transaction, refusing with 422 `SERVICE_ACCOUNT_LIMIT_REACHED`.

Wave 2b owns all of it.

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
     * @param  ?PermissionVerb        $verb          NULL for a legacy() key, which does not decompose
     * @param  ?non-empty-string      $qualifier     snake_case narrowing of the verb, e.g. 'above_threshold'
     * @param  non-empty-string       $labelKey      i18n key stem, always 'permissions.actions.<action>'.
     *                                               Keyed by module+action ONLY, so resources sharing a verb
     *                                               share one generic label ("Create"). INTENTIONAL (rev 2,
     *                                               minor 5): it is what RolesPage.tsx:37-42 already does, and
     *                                               a per-permission label would be 304 strings × 3 locales to
     *                                               say "Create" 34 times. A resource needing distinct wording
     *                                               carries a $qualifier, which produces its own action key.
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
        public ?PermissionVerb $verb,
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
        ?PermissionVerb $behavesAs = null,   // rev 3: SoD classification only; REQUIRED when $sodGroup is set
    ): self;

    public static function deprecate(self $definition, ?string $replacedBy): self;

    public function action(): string;        // verb + optional '_' . qualifier, or the legacy suffix
    public function isFinanciallyDecisive(): bool;  // ($verb ?? $behavesAs)?->isFinanciallyDecisive() === true
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
    case Pay = 'pay';
    // exceptions and IO
    case Override = 'override';   case Print = 'print';
    case Export = 'export';       case Import = 'import';
    // administration
    case Assign = 'assign';       case Operate = 'operate';
    case Manage = 'manage';

    /** Verbs that mutate; drives the write half of the §4.4 ratchet and the §4.6 denial audit. */
    public function isWrite(): bool;
    /** Verbs that make a financial fact real; drives the §4.7 SoD rule. */
    public function isFinanciallyDecisive(): bool;  // post, reverse, void, approve, confirm, refund, allocate, pay
}
```

**Thirty-two cases** (rev 2 added `Close`; rev 3 adds `Pay`). The list is derived from what the catalogue already uses (`view` 59, `create` 34, `update` 27, `delete` 23, `manage` 22, `post` 6, `cancel` 6, then a long tail — measured at `971528977`) plus the verbs the accepted T-2/T-3 spec needs.

**`Pay` is a case and it is financially decisive (rev 3, B-5).** `expenses.pay` is a live key held by `manager` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:605`) and by `accountant` (`:832`), and §4.7 names it as a decisive half of the `expense` SoD group. Rev 2 had no `Pay` case and no `pay` in `isFinanciallyDecisive()`, so the key could not be constructed by `make()` at all and could not be classified by the SoD test — the rule was written against a key the catalogue could not express. Paying an expense is the moment money leaves; it belongs beside `refund` and `allocate`.

**`legacy()` keys have no verb, and say so (rev 3, B-5).** The gate's second half of B-5 is that `legacy()` constructs an object whose **non-null** `$verb` drives classification, so a non-decomposing key would be silently mis-classified by whatever verb `legacy()` invented. Two changes make classification truthful:

- `PermissionDefinition::$verb` becomes **`?PermissionVerb`**. `make()` always sets it; `legacy()` always sets it to `null`, because a key like `batches.traceability` or `payments.pay-supplier` genuinely has no verb from the closed enum. `action()` returns the legacy suffix in that case, which it already did.
- `legacy()` gains one optional parameter, **`?PermissionVerb $behavesAs = null`**, whose only job is SoD classification: a legacy key that *is* financially decisive names the verb it behaves as, and the SoD rule reads `($verb ?? $behavesAs)?->isFinanciallyDecisive() === true`. Exactly two catalogue keys need it today, both re-derived at `971528977`: `payments.pay-supplier` (`behavesAs: PermissionVerb::Pay`, held by `manager` `:609` and `accountant` `:836`) and `supplier-invoices.approve-invoice-first` (`behavesAs: PermissionVerb::Approve`, `:595`, `:829`). A legacy key that is create-class names `behavesAs: PermissionVerb::Create` — one key today, `supplier-invoices.create-pending` (`:595`, `:829`).
- `PermissionRegistryConsistencyTest::a_legacy_key_in_a_sod_group_declares_behaves_as` fails any `legacy()` definition that carries a `sodGroup` and no `behavesAs`, so a future non-decomposing key cannot join a group and be silently invisible to SoD-1.

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
2. **`markedTenantCarriesWlota1aDelta($tenantId)`** guards the destructive reseed; a marked tenant is left completely untouched. **Rev 2 corrects the citation**: the incoming *branch* is `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:44-51` and the *predicate* it calls is the private method at `:64-70` (plan `:1496,1503-1507`). The current-tree line `apps/api/database/seeders/RolesAndPermissionsSeeder.php:568` is only the old unguarded `syncPermissions()` call — it is what the lane replaces, not where the marker check lives. **Rev 3 adds the fact that makes wave 0b a blocker (B-4):** that untouched branch is reached whenever `LOT_ACTION_PERMISSIONS_ENFORCE` is false, which is its shipped default (`lane/w-lot-a-1a:apps/api/config/lot_action_permissions.php:5`), so on a marked tenant with enforcement off `tenants:seed RolesAndPermissionsSeeder` **writes nothing at all** — see §4.3.5.
3. **Legacy roles carry `tenant_id NULL` and are never re-homed** (`docs/handoff/CODEX-PROMPT-WLOTA-1a-ruling-legacy-null-team-roles-2026-09-09.md`). Every role read resolves `name = ? AND guard_name = 'sanctum' AND (tenant_id IS NULL OR tenant_id = :tenantId)` and **preserves `tenant_id` as found**; two matching rows is a collision, never an adoption.
4. **`LotActionPermissionDelta`** (`apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php`) is that lane's sole role-definition writer, with a `setPermissionsTeamId` / `finally`-restore boundary around a `DB::transaction` holding a transaction-scoped PG advisory lock (plan `:1382-1407`), and ten guard rails including "no force option exists" (plan `:1409-1420`).
5. The lane adds **exactly two** permission keys, `batches.recall.request` and `treasury.manage_all_locations` (plan `:1424-1429`), and one role, `general_manager` (`SystemRoleName::GeneralManager`, plan `:1241-1244`).

**How this design is a superset, clause by clause.**

| W-LOT-A-1a asset | What `permissions:sync` does with it |
|---|---|
| `roles.provisioning_source` + its CHECK and index | **Never written, never cleared, never widened.** The CHECK stays exactly as installed; sync writes `template_key`/`template_version`/`is_system`/`customised_at` only (§5), which the CHECK does not constrain. `RoleProvisioningSource` keeps its single case. |
| `markedTenantCarriesWlota1aDelta()` | Kept **exactly as the lane wrote it**, and **re-expressed for external callers** (rev 3, B-2): it is `private` on the lane's seeder (`lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:64-70`), so no other service can call it. `PermissionSyncService` reads the same predicate through the new public **`RoleMarkerRepository::tenantCarriesMarker(string $source)`** (§4.3.0a rule 1b) and, for a marked tenant, treats `general_manager` as already-provisioned — it never re-creates it, never re-grants `batches.recall`, and never restores `manager`'s recall grant. |
| NULL-team legacy roles | The `(tenant_id IS NULL OR tenant_id = :tenantId)` predicate and the "preserve `tenant_id` as found" rule are lifted verbatim into `PermissionSyncService::resolveRole()`. Two matching rows → `RoleCollision`, transaction rolled back, non-zero exit. **No re-homing anywhere in this design.** (The gate confirmed this predicate is sound as written: `lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:104-128`.) |
| `LotActionPermissionDelta` | **Rev 2 — sync does not merely coexist with the delta, it INVOKES it.** The registry owns role templates, and the retained delta becomes **template migration #1**: `PermissionSyncService` never creates `general_manager` itself and never writes the marker; when a tenant lacks the role it **calls `LotActionPermissionDelta`**, which is and remains the sole general-manager role-definition writer and the sole author of `provisioning_source = 'w-lot-a-1a'`. See §4.3.0a for why duplicating that creation was a blocker. Wave 3 retires the delta **only** once `permissions:sync` has run against every tenant and `LotActionPermissionDeltaTest` is re-pointed at the sync service. |
| its two permission keys and `general_manager` | Declared in the `BatchExpiry` and `Treasury` manifests (`batches.recall.request`, `treasury.manage_all_locations`) and in the `general_manager` template with **that lane's exact grant set** (plan `:1431-1444`). §4.9 lists this as a re-homing of the declaration only — no key, role or grant changes value. |

Two collision hazards are recorded here so a gate can check them: (i) the new `roles` columns must be added by a migration **timestamped after** `2026_09_06_205000`, and (ii) neither the new partial unique on `template_key` nor the `is_system` backfill may write `tenant_id`, or the W-LOT CHECK's `tenant_id IS NOT NULL` clause interacts with a NULL-team legacy role.

#### 4.3.0a Template migration #1, and how a legacy role is adopted (rev 2, completed in rev 3)

Gate r1 found three ways rev 1 was **not** a superset of the incoming lane. All three are closed here, and this subsection is the contract a gate should check first.

**(1) Sync never creates `general_manager`; it delegates.** Rev 1 promised never to write `provisioning_source` while also directing sync to create a missing `general_manager` (§4.3.2 step 5). Those are incompatible: the retained delta treats an existing `general_manager` *without* `provisioning_source = 'w-lot-a-1a'` as an **unmarked collision** and refuses (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:139-155`, with the CHECK and unique marker contract at `lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:25-42,64-75`). A tenant provisioned after wave 1 would have received an unmarked role that the delta then rejected forever.

> **Rule.** `general_manager` has exactly **one** creator in the tree: `LotActionPermissionDelta`. The registry declares the `general_manager` **template** (its grant set, from plan `:1431-1444`), and `PermissionSyncService` **invokes the delta** when the role is absent, then applies template grants to the role the delta created. `PermissionSyncServiceTest::sync_delegates_general_manager_creation_to_the_delta` asserts sync writes no `roles` row named `general_manager` itself and that `provisioning_source` is set by the delta on exactly that path. This is what "the registry owns role templates, the delta owns this role's provisioning" means concretely — one template, one writer.

**(1a) The delta is not parameterless, so template migration #1 is a class that owns its frozen arguments (rev 3, B-2).** Rev 2 said "sync calls the delta" without saying *with what*, and without a signature that could. The retained delta's public entry point is `apply(string $tenantId, array $permissionNames, array $rolePermissionGrants)` (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:27-30`); it then resolves every role named in the grant map (`:56`), creates every missing permission in the name list (`:65-73`), creates every missing non-general-manager role (`:74-85`), synchronises each supplied role's grants including the `manager` recall revocation (`:86-90`) and finally verifies the whole supplied canonical state (`:91`). **Passing it the *current* registry lists would be a D2 violation**: it would grant present-day template additions to system roles the tenant has customised, straight past the `customised_at` skip in step 7 (§4.3.2). So the arguments are **frozen at the lane's merge SHA and never recomputed**:

```php
// apps/api/app/Modules/Identity/Application/TemplateMigrations/TemplateMigration001WLotA1a.php
final readonly class TemplateMigration001WLotA1a implements TemplateMigration
{
    public function __construct(private LotActionPermissionDelta $delta) {}

    public function key(): string { return '001-w-lot-a-1a'; }

    /** The marker whose presence means this migration has already run for the tenant. */
    public function markerSource(): string { return RoleProvisioningSource::Wlota1a->value; }

    /** FROZEN at lane/w-lot-a-1a@04e60530c — never derived from PermissionRegistry. */
    public function apply(string $tenantId): TemplateMigrationResult;   // → $this->delta->apply($tenantId, self::PERMISSION_NAMES, self::ROLE_GRANTS);
}
```

`self::PERMISSION_NAMES` and `self::ROLE_GRANTS` are **literal copies** of what the lane's own seeder passes the delta at that SHA, taken from the two static methods that build them:

| Frozen input | Lane source it is copied from, verbatim |
|---|---|
| `PERMISSION_NAMES` | `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:80-83` — `legacyPermissionNames()` plus exactly `batches.recall.request` and `treasury.manage_all_locations` |
| `ROLE_GRANTS` | `lane/…/RolesAndPermissionsSeeder.php:86-97` — `legacyRolePermissionGrants()` with `admin` = the full name list (`:89`), `manager` = its legacy set **minus `batches.recall` plus `batches.recall.request`** (`:90`), `general_manager` = that revised `manager` set **plus `batches.recall` and `treasury.manage_all_locations`** (`:91`), and `batches.view` added to `cashier`, `viewer`, `operator` (`:92-94`) |

and the call site the seeder itself uses is `lane/…/RolesAndPermissionsSeeder.php:36`. **Template migrations never receive current registry lists** — this is stated as an invariant because it is the whole reason the class exists: `TemplateMigrationFrozenInputTest` asserts `TemplateMigration001WLotA1a` references no `PermissionRegistry` symbol at all (a static-analysis assertion over its imports and its constructor), so a later contributor cannot "helpfully" wire the registry in.

**(1b) The precondition is read through a repository, not a private seeder method (rev 3, B-2).** Rev 2 had `PermissionSyncService` call `markedTenantCarriesWlota1aDelta()`, which is `private` on the incoming seeder (`lane/…/RolesAndPermissionsSeeder.php:64-70`) and therefore uncallable from another class. The predicate it implements — *is there a role in this tenant with `provisioning_source = <source>`* — becomes a small public collaborator instead:

```php
// apps/api/app/Modules/Identity/Infrastructure/Repositories/RoleMarkerRepository.php
final readonly class RoleMarkerRepository
{
    /** True when this tenant already carries a role marked with $source. */
    public function tenantCarriesMarker(string $source): bool;
}
```

It reproduces the lane's predicate exactly — `Schema::hasColumn('roles', 'provisioning_source')` guard, tenant-team filter, `guard_name = 'sanctum'`, `whereRaw('provisioning_source = ?')` (`lane/…/RolesAndPermissionsSeeder.php:66-69`) — minus the hard-coded role name, so any future template migration can declare its own marker. The lane's private method is left exactly as it is; wave 3 re-points it at this repository when it retires the delta.

**(1c) The step order, and why adoption must follow the migrations.** A delta-created `general_manager` gets `name`, `guard_name`, `tenant_id` and `provisioning_source` (`lane/…/LotActionPermissionDelta.php:139-159`) and **none** of `is_system`, `template_key`, `template_version`, `customised_at`. Rev 2 ran adoption *before* role creation, so that role could never be adopted and every later protection keyed on `is_system` (§4.6.1, both floors) would have skipped it silently. The order is therefore: **create permissions → run template migrations → adopt → template-delta grants → admin → cache after-commit** (§4.3.2 steps 2, 5, 6, 7, 8, 10), and **adoption writes the four template columns on migration-created roles too** — it classifies every role whose name matches a `SystemRoleTemplate` case and whose `template_key IS NULL`, which after step 5 includes the role the delta has just created.

**(2) Legacy adoption, on the first sync per tenant.** The `roles` migration (§5.2) defaults every existing role to `is_system = false, template_key = NULL, template_version = NULL, customised_at = NULL`. Sync must therefore decide, once per tenant, whether a pre-existing seeded role is *pristine* (safe to track) or *already customised* (must never receive a delta). Rev 1 specified no such classification, which is exactly the case D2 exists to protect.

> **Adoption rule.** On the first sync of a tenant, for **each role whose `name` matches a `SystemRoleTemplate` case** (resolved with the NULL-team-tolerant predicate):
>
> - compute the role's current grant set;
> - compare it to the **embedded legacy baseline snapshot** — a frozen, versioned copy of `RolesAndPermissionsSeeder`'s grant arrays as they stood at `971528977` (`apps/api/app/Modules/Identity/Domain/Authorization/LegacyRoleBaseline.php`, `template_version = 0`), **which carries eight rows, not seven** (rev 3, B-3);
> - **equal** → adopt with `is_system = true`, `template_key = <name>`, `template_version = 0`, **`customised_at = NULL`**. The role is pristine and resumes receiving deltas from version 0 forward;
> - **different** → adopt with `is_system = true`, `template_key = <name>`, `template_version = 0`, **`customised_at = now()`**. The role is treated as customised: it receives **no** deltas, admin-only changes apply, and the Roles UI shows "N template updates not applied" with *Re-apply template* (§4.6.6);
> - either way the classification is **logged and counted** in the sync marker as `adopted_pristine=<n> adopted_customised=<n>`, and listed per role in `--dry-run`.
>
> Adoption is **one-way and once**: a role that already carries a `template_key` is never re-classified. Roles whose name matches no template stay `is_system = false` and are never touched (§4.3.2 step 9). `is_system` is set **only** on adoption of a template-linked role — never on a custom role, which is what the §5.2 CHECK `template_key IS NULL OR is_system` encodes.

**The eighth baseline row: `general_manager` at version 0 (rev 3, B-3).** The seven legacy snapshots are frozen from `RolesAndPermissionsSeeder` at `971528977`, where `general_manager` does not exist — it is introduced by the incoming lane. Adoption nevertheless compares **every** `SystemRoleTemplate` case, `general_manager` included, so without an eighth row a marked tenant's `general_manager` could be classified neither pristine nor customised and the run would be non-deterministic. So:

> **Ruling (orchestrator, 2026-09-10).** `LegacyRoleBaseline` stores a **`general_manager` version-0 snapshot = that role's grant set as `lane/w-lot-a-1a` defines it at its merge SHA**, stored *alongside* the seven legacy snapshots and versioned identically (`template_version = 0`). Concretely it is the value of `$grants[SystemRoleName::GeneralManager->value]` built at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:91` — the revised `manager` set from `:90` (legacy `manager` **minus `batches.recall`, plus `batches.recall.request`**) **plus `batches.recall` and `treasury.manage_all_locations`**. It is the same frozen array `TemplateMigration001WLotA1a::ROLE_GRANTS` holds for that key (§4.3.0a rule 1a), and `LegacyRoleBaselineParityTest` asserts the two are identical so they cannot drift apart. **The adoption rule itself is unchanged** — equality against the version-0 snapshot, pristine → `customised_at = NULL`, different → `customised_at = now()`; only the snapshot set grows from seven rows to eight.

Choosing `customised_at = now()` for the "different" branch is deliberately the **conservative** direction: it can only ever *withhold* a delta from a tenant that had in fact not customised, which surfaces as a visible banner and a one-click *Re-apply template*. The opposite error — adopting a genuinely customised role as pristine and then silently re-granting what the tenant removed — is precisely I-20 in a new costume.

**(3) The `customised_at` writer moves into wave 1.** Rev 1 deferred it to wave 2 while wave 1 already deploys and repeatedly runs sync — so during the soak the existing editor would keep calling `syncPermissions()` without marking customisation (`apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:223-247`), and the next sync could add template grants to a role the tenant had just edited. **Ruling: the `customised_at` write in `RoleController::update` is a wave-1 deliverable**, in the same lane as sync. It is a small change — set `customised_at = now()` when a system role's permission set actually changes and `customised_at IS NULL` — and it is the entire point of the column, so shipping the column a wave before its writer was the bug.

**(4) Compatibility mode runs the same adoption.** Adoption is a DB-level classification of `roles` rows, not a template application, so it is safe and necessary in single-schema mode too. Rev 1 had compatibility mode skip every template step, which would leave every system role at `is_system = false` while wave 2 relies on `is_system` for protection and the floors (§4.6.1, §4.6.3). Corrected: in compatibility mode sync runs steps 1–4 **and step 6, the adoption pass**, then stops. **Step 5, template migrations, is skipped there** (rev 3): a template migration *creates roles*, and in single-schema mode the `roles` table is shared across tenants, so running one globally would provision one tenant's `general_manager` for everybody — the exact class of error D1 and the NULL-team ruling exist to prevent. Steps 7–8 are skipped for the same reason (§4.3.3, EC-21).

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
                 template_migrations_applied=<n> template_migrations_skipped_marked=<n>
                 orphans=<n> reason=<token>
```

The two `template_migrations_*` counters are rev 3's (B-2): a deploy that ran template migration #1 for the first time on a tenant must be distinguishable in the log from one that found the tenant already marked, because those are the two halves of the W-LOT-A-1a absorption and a gate reading a boot log needs to tell them apart.

#### 4.3.2 Service

```php
// apps/api/app/Modules/Identity/Application/Services/PermissionSyncService.php
final readonly class PermissionSyncService
{
    /** @param list<TemplateMigration> $templateMigrations  ordered, tagged 'permission.template-migrations' */
    public function __construct(
        private PermissionRegistry $registry,
        private PermissionRegistrar $permissionRegistrar,   // Spatie
        private PermissionRenameMap $renameMap,
        private LegacyRoleBaseline $legacyBaseline,
        private RoleMarkerRepository $roleMarkers,          // rev 3, B-2 — the public marker predicate
        private array $templateMigrations,                  // rev 3, B-2 — TemplateMigration001WLotA1a is [0]
        private Clock $clock,
    ) {}

    /** Full catalogue sync for one tenant: steps 1–10 below. */
    public function sync(string $tenantId, bool $write): PermissionSyncResult;

    /**
     * Rev 2: the single-role path EC-10's *Re-apply template* action needs.
     * Runs steps 5 and 7 for ONE template only, after clearing customised_at.
     * Rev 1 referred to a "single-role path" that no signature contained.
     */
    public function syncRole(string $tenantId, string $templateKey, bool $write): PermissionSyncResult;
}
```

Constructor injection only, `private readonly` throughout (rule 13) — **including the template-migration collection**, which rev 2 omitted while step 5 called it, making the declared service unable to implement its own contract (B-2). The collection is resolved from the container tag `permission.template-migrations`, the same tagging mechanism the registry uses for manifests (§4.2.4), so adding a future template migration is one line in one module. The team boundary is W-LOT-A-1a's, verbatim in shape:

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

**The ten steps, in this order** (rev 3 replaces rev 2's "step 4a" with an explicit, numbered sequence and inserts step 5, template migrations, ahead of adoption — B-2). Every step is idempotent; running the command twice in a row produces `ALREADY_CURRENT` with all counters zero.

1. **Rename.** For each `(from, to)` in the rename map where a `permissions` row named `from` exists and none named `to` exists: `UPDATE permissions SET name = :to WHERE name = :from AND guard_name = 'sanctum'`. **In place** — the primary key never changes, so every `role_has_permissions` and `model_has_permissions` row survives untouched and no grant is lost. If both `from` and `to` exist, the run is `BLOCKED` with `reason=rename_target_exists` (a human must decide which grants win); if neither exists the entry is a no-op and is reported as stale so the map can be trimmed.
2. **Create.** `Permission::firstOrCreate(['name' => $key, 'guard_name' => 'sanctum'])` for every `registry->keys()`, deprecated keys included — a deprecated permission keeps its row for one release so a custom role referencing it does not break (§4.9).
3. **Deprecate.** For every deprecated definition: the row stays, and the key is removed from **every system-role template** (step 7) but **never** revoked from a custom role. `replacedBy`, when set, is granted to every role currently holding the deprecated key — **including custom roles, which is the single, named, logged exception to "custom roles are never written"** (the RULING immediately below; a rename-by-deprecation that skipped custom roles would silently strip a tenant's capability).

   > **RULING (orchestrator, 2026-09-10, rev 3 — M-6). Two distinct paths, and only one of them writes a custom role.**
   >
   > - **An in-place rename (step 1) writes NO role at all.** `UPDATE permissions SET name = :to` changes the row's `name`; the primary key is untouched, so every `role_has_permissions` and `model_has_permissions` pivot survives byte-identically and a custom role keeps its grant with **zero writes to `roles` or to any pivot**. This is why the rename map is the preferred mechanism and why EC-6 asserts pivot row counts rather than absence of error.
   > - **A deprecation WITH a `replacedBy` grants the replacement to every role holding the old key, custom roles included.** A deprecation is not a rename: the old key stops being granted by any template and will eventually be pruned, so a custom role that is not given the successor loses the capability on prune day, silently, with no operator decision — the precise outcome D2's "no tenant loses a capability without a human decision" forbids. So sync writes one `role_has_permissions` row per affected custom role, and **each such write emits a `RoleUpdated` audit event** (§4.6.5) naming the custom role, the deprecated key and the replacement, so the exception is visible in the audit chain rather than being an invisible carve-out. A deprecation with `replacedBy: null` writes nothing anywhere.
   >
   > This is stated as an **invariant in §4.3, not only here**, because "custom roles are never written" appears three times in this document and a reader must meet the exception wherever they meet the rule. `PermissionsSyncTemplateDeltaTest::custom_roles_receive_only_replacement_grants_and_nothing_else` is the mechanical form: it asserts a custom role's grant set after a sync differs from its grant set before **only** by replacement keys, and that a `RoleUpdated` row exists for each.
4. **Orphans.** A `permissions` row present in the database and absent from the registry is **reported, never deleted**. Deleting it would cascade its grants away, and a row can legitimately be a leftover from a tenant that has not yet received a deploy that removed a module. The count appears in the marker as part of `reason` and in the `--dry-run` table; wave 3 adds `permissions:prune-orphans --confirm` as a separate, deliberate action.
5. **Template migrations** (rev 3, §4.3.0a rules 1–1c). For each `TemplateMigration` in the injected collection, in declared order: if `roleMarkers->tenantCarriesMarker($migration->markerSource())` is **true**, skip it and count it as `template_migrations_skipped_marked`; otherwise call `$migration->apply($tenantId)` with its **frozen** inputs and count it as `template_migrations_applied`. Migration #1 is `TemplateMigration001WLotA1a`, which delegates to `LotActionPermissionDelta` — the sole creator of `general_manager` and the sole author of `provisioning_source` (§4.3.0a rule 1). Each migration is itself idempotent and additionally marker-guarded here, so a second sync run applies none. A migration returning a failure outcome makes the whole run `BLOCKED` with `reason=<the delta's own reason token>` and rolls the transaction back — the outcome vocabulary is the lane's (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/LotActionPermissionDelta.php:93-97`), not a second one. **Template migrations never receive registry lists**; see the frozen-input invariant and its test in §4.3.0a rule 1a.
6. **Adopt** (§4.3.0a rule 2). For each role whose name matches a `SystemRoleTemplate` case and whose `template_key IS NULL`, classify against `LegacyRoleBaseline` and adopt it as pristine (`customised_at = NULL`) or as customised (`customised_at = now()`), setting `is_system = true`, `template_key` and `template_version = 0`. Counted as `adopted_pristine` / `adopted_customised`, logged per role. A role that already carries a `template_key` is skipped — adoption happens once per role, ever. **This step runs AFTER step 5 precisely so that it also adopts roles a template migration has just created** (rev 3, B-2): the delta writes only `name`, `guard_name`, `tenant_id` and `provisioning_source` (`lane/…/LotActionPermissionDelta.php:139-159`), so without this ordering a freshly created `general_manager` would carry `is_system = false` forever and every §4.6 protection keyed on that column would skip it. Its classification uses the **`general_manager` version-0 snapshot** (§4.3.0a), so a role the delta has just created compares equal and is adopted **pristine**.
7. **Template-delta grants.** For each **non-`admin`** system-role template in `registry->templateGrants()`:
   - resolve the role with the NULL-team-tolerant predicate (§4.3.0 clause 3); `general_manager` is never created here — step 5 owns it, and if it is still absent after step 5 the run is `BLOCKED` with `reason=general_manager_absent_after_template_migrations` rather than sync inventing an unmarked role the delta would reject forever; if any other template's role does not exist, create it with `tenant_id = :tenantId`, `is_system = true`, `template_key`, `template_version = <registry version>`;
   - the role is **additive-only**: `givePermissionTo()` for keys the template declares and the role lacks; **no revocation ever**, except the single explicit revocation W-LOT-A-1a already owns (`manager` losing `batches.recall`), which stays that lane's — applied by template migration #1 in step 5, never by this step;
   - **customised roles are skipped**: if `roles.customised_at IS NOT NULL`, the role receives nothing and is counted in `templates_skipped_customised`. Its `template_version` is left at the value it had, which is what lets the Roles UI say "this role is 3 versions behind" (§4.6);
   - on success the role's `template_version` is set to the registry version.
8. **Admin.** The role whose `template_key = 'admin'` is set to exactly `registry->activeKeys()` with `syncPermissions()`. This is the one place a full replace is correct, and it is what replaces `Permission::all()` (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:568`, I-19) — after this, the catalogue is once again the boundary of what `admin` holds, and a stray row inserted out of band is no longer silently attached. It runs **after** the template migrations so that `activeKeys()` and the migration's own permission creation cannot race, and after adoption so the role is already `is_system`.
9. **Custom roles** (`is_system = false`) are read for the orphan report and **never written** — with the single, named, audited exception step 3 defines for a deprecation that names a `replacedBy`. This is B4 and D2 in one line, and its one carve-out in the next.
10. **Cache — after commit, and rev 2 means it this time.** Rev 1 said `forgetCachedPermissions()` runs "inside the transaction's `finally`, i.e. after commit". That is wrong: a `finally` inside the `DB::transaction` callback executes **before** the callback returns and therefore before Laravel commits, leaving a window in which another request rebuilds and re-caches the *old* snapshot which then outlives the commit. The flush is registered as an **after-commit hook** — `DB::afterCommit(fn () => $this->permissionRegistrar->forgetCachedPermissions())` inside the transaction, so it fires once the commit has actually landed — and it runs **while the permissions team id is still set to this tenant**, because the S-1 key scheme (§4.8) derives the cache namespace from it. Concretely, the ordering inside `sync()` is: `setPermissionsTeamId($tenantId)` → `DB::transaction(… ; DB::afterCommit($flush))` → `finally { setPermissionsTeamId($previous) }`, so the hook has fired before the team id is restored. `PermissionsSyncTest::the_cache_is_flushed_after_commit_not_before` asserts it by observing a concurrent read on a second connection.

`--dry-run` runs steps 1–9 (template migrations and adoption included) with `$write = false` inside a transaction that is rolled back, and prints the plan as a table. It is the only supported way to preview a deploy's authorization delta.

> **§4.3 invariants, restated in one place so they can be cited as a set.** (i) `provisioning_source` is never written, cleared or widened by sync. (ii) `tenant_id`, `name` and `guard_name` are never written on a role that already exists; sync writes them only on a row it creates itself, and never for `general_manager`. (iii) A **customised** system role (`customised_at IS NOT NULL`) receives no template delta. (iv) A **custom** role (`is_system = false`) receives **no template grant, ever** — the sole exception being a `replacedBy` grant on a deprecation, which is logged as a `RoleUpdated` audit event (step 3). (v) A template migration receives **frozen** inputs and never the live registry (§4.3.0a rule 1a). Every one of the five is asserted by a named test in wave 1.

#### 4.3.3 Where it runs

**Replacing the destructive boot reseed.** `apps/api/docker/entrypoint.sh:162-170` currently runs `tenants:seed --class='Database\Seeders\RolesAndPermissionsSeeder'` across every tenant whenever `SYNC_PERMISSIONS_ON_BOOT=true` — which staging sets, so every push silently reverts every tenant's customisation of the seven seeded roles (I-20, verified `docs/superpowers/audits/2026-09-09-roles-permissions/07-verification-opus.md:188`). That block is replaced by:

```sh
# The status directory is created with the other runtime dirs at the top of the
# script, beside the existing `mkdir -p /var/run` (apps/api/docker/entrypoint.sh:19-23).
mkdir -p /var/run/autoerp
chmod 755 /var/run/autoerp

if [ "$SYNC_PERMISSIONS_ON_BOOT" = "true" ]; then
  if DB_HOST="$DIRECT_DB_HOST" php artisan permissions:sync-fleet; then
    echo "  Permission sync: [all tenants applied or already current]"
    # SUCCESS OVERWRITES the file. `>` truncates, so a recovered deploy clears a
    # previous boot's failure marker; rev 2 wrote only the failure arm, so a
    # container that recovered stayed permanently unhealthy.
    printf '%s\n' "permissions_sync=ok" > /var/run/autoerp/permissions-sync.status
  else
    echo "  Permission sync: [FAILED - see PERMISSIONS-SYNC-FLEET marker]"
    printf '%s\n' "permissions_sync=failed" > /var/run/autoerp/permissions-sync.status
  fi
fi
```

**The file has a named reader, and a named endpoint (rev 3, B-8).** Rev 2 said "`/health` reports `permissions_sync: failed`" without saying which `/health`, and the repository has two with different audiences:

| Endpoint | Route | Handler | Today |
|---|---|---|---|
| `GET /api/v1/health` | `apps/api/routes/api.php:27`, **public, no auth** | `MonitoringController::ping` (`apps/api/app/Modules/Admin/Presentation/Controllers/MonitoringController.php:28-36`) | returns `{status, timestamp}` and nothing else |
| `GET /api/v1/admin/monitoring/health` | `apps/api/routes/api.php:83`, inside the group gated `['auth:sanctum-admin', 'super_admin', 'throttle:admin-sensitive']` (`:57-58`) | `MonitoringController::health` (`:42-45`) → `HealthCheckService::check()` | returns `database`, `redis`, `queue`, `storage` checks (`apps/api/app/Modules/Admin/Application/Services/HealthCheckService.php:19-33`) |

> **Ruling.** The reader is **`HealthCheckService`**, which gains a fifth check `permissions_sync` in the array at `HealthCheckService.php:19-24`. It reads `/var/run/autoerp/permissions-sync.status` and returns `['healthy' => true]` when the file is absent (a container that never ran a boot sync is not unhealthy) or contains `permissions_sync=ok`, and `['healthy' => false, 'error' => 'permission sync reported failure on last boot']` when it contains `permissions_sync=failed`. Because `check()` derives `$allHealthy` from every check (`:26`), a failed sync also flips the aggregate — which is the point.
>
> The key is therefore exposed on the **authenticated `/v1/admin/monitoring/health`**, and the **public `/v1/health` is unchanged**: it stays a two-field liveness probe for the load balancer (`MonitoringController::ping`), because a public endpoint must not disclose the platform's internal authorization state, and because flipping it to 503 on a permission-sync failure would take the whole fleet out of the load balancer over a partial authorization problem — the opposite of "boot continues".
>
> **Deploy checklist reads the admin endpoint**, not the public one, and the Dokploy log line to grep for is the aggregate marker **`PERMISSIONS-SYNC-FLEET`** emitted by the fleet runner (the `docker logs` line named in the wave-1 deploy list, §8), which names every blocked or failed tenant id.

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

**The entrypoint keeps booting** — refusing to start the container on a permission-sync failure would turn a partial authorization problem into a total outage — but it stops lying about it: it writes a structured marker on **both** arms, and **`GET /api/v1/admin/monitoring/health` reports `permissions_sync: {healthy: false}`** (a fifth key beside `database`, `redis`, `queue`, `storage`) until a subsequent successful run **overwrites** the file with `permissions_sync=ok`. That makes a partial fleet sync a visible, alertable state rather than a line in a boot log nobody reads. **Deploy checklist**: a new row *"after deploy, confirm `GET /api/v1/admin/monitoring/health` shows `permissions_sync.healthy = true`; if not, grep the Dokploy container log for the `PERMISSIONS-SYNC-FLEET` aggregate line, which names every blocked or failed tenant id, then run `php artisan permissions:sync-fleet` for those tenants"* is added to the runbook alongside the existing post-deploy steps, and to §8's wave-1 deploy list.

The environment variable name is **kept** — W-LOT-A-1a's §11 evidence protocol asserts its value on staging in six places (plan `:2643,3043,3164,3755,4112`) and its deployment-variable table row at `:2367`; renaming it would invalidate that lane's evidence. What changes is only what the flag runs. The flag's default stays `false` in production until wave 1's staging soak passes (§8).

**In `tenants:migrate`.** The durable home is a listener on Stancl's tenant-migrated event so that a **newly provisioned** tenant and a **rolling-migrated** existing tenant both receive the sync in the same step that gave them their schema (`.claude/context/architecture.md`, "Migration topology" and "Lifecycle"). `apps/api/docker/entrypoint.sh:150` already runs `tenants:migrate-rolling --force` immediately before the block above, so on a normal deploy each tenant is migrated and then synced, in order, per tenant, with per-tenant failure isolation — the property the existing script comments already claim at `:145-147`.

**Provisioning.** `TenantInitializationService` keeps calling `RolesAndPermissionsSeeder` (it is invoked when roles are absent, per W-LOT-A-1a's census at plan `:372`), and the seeder now delegates (§4.3.4). A fresh tenant therefore gets the catalogue through exactly the same code path as an existing one — one write path, per convention 11.

**Compatibility mode.** In single-schema compatibility mode every tenant shares one `permissions` table (S-1's own scoping rationale, `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:66`). Steps 1–4 (rename, create, deprecate, orphan report) are tenant-independent and safe; steps 5 and 7–8 are not, because the `roles` rows are shared. So: with `TENANCY_DB_PER_TENANT=false`, `permissions:sync` **refuses `--tenant`** (exit 2, `reason=compatibility_mode_is_global`) and runs steps 1–4 **and step 6 (adoption)** once globally, reporting `templates_applied=0` and `template_migrations_applied=0`. **Adoption runs here too (rev 2):** it is a DB-level classification of the shared `roles` rows, not a template application, and skipping it — as rev 1 did — would leave every system role at `is_system = false`, which is exactly the state wave 2's rename/delete protection and the two floors depend on (§4.6.1, §4.6.3). A compatibility-mode tenant would otherwise reach wave 2 with an unprotected `admin` role. This is stated rather than silently degraded because a silent degrade is exactly how I-20 happened.

**Compatibility mode and the template-key uniqueness (rev 3, B-7).** Because the shared `roles` table legitimately holds one `manager` row per tenant — the existing Spatie unique is `(tenant_id, name, guard_name)` (`apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:43-47`) — adoption there necessarily writes `template_key = 'manager'` on **several** rows. That is why §5.2's uniqueness is `unique(tenant_id, template_key)` plus a partial unique on `template_key` alone for NULL-team legacy rows, and why EC-21's test provisions **two** tenants rather than one.

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

#### 4.3.5 `permissions:ensure` — the wave-0b stopgap, and why it is needed at all

> **BLOCKER found by gate r2 (B-4), ruled by the orchestrator 2026-09-10.** Wave 0b declares roughly twenty new permission keys and gates live routes on them, and rev 2 deployed them with `php artisan tenants:seed --class=…RolesAndPermissionsSeeder` — the sequence the T-2/T-3 spec documents. **After `lane/w-lot-a-1a` merges that sequence writes nothing on a marked tenant.** `LOT_ACTION_PERMISSIONS_ENFORCE` defaults to **false** (`lane/w-lot-a-1a:apps/api/config/lot_action_permissions.php:5`), and with enforcement off the incoming seeder takes the `markedTenantCarriesWlota1aDelta()` branch and returns having written nothing at all (`lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:44-51`) — which is *correct* behaviour for that lane, whose whole point is that a marked tenant is never re-seeded. The consequence for wave 0b is that a marked tenant would not receive `credit-notes.cancel`, `services.*`, `channels.*` or any other 0b key while its routes are already gated on them, and Spatie fails closed: **403 for everyone, including `admin`** — the exact `credit-notes.cancel` failure mode 0b exists to fix, reproduced on a fresh set of keys.

Wave 0b therefore provisions its own keys through a small, additive command of its own, and **does not rely on `tenants:seed` at all**:

```php
// apps/api/app/Console/Commands/EnsurePermissions.php
protected $signature = 'permissions:ensure
    {--keys=*     : permission keys to create if absent}
    {--grant-to=* : role names that must hold every named key (in addition to admin)}
    {--dry-run    : report the plan; write nothing}';
```

Its contract is deliberately the smallest thing that closes the hole:

- **Creates only.** `Permission::firstOrCreate(['name' => $key, 'guard_name' => 'sanctum'])` per key. It never renames, never deprecates, never deletes.
- **Grants additively.** Each named key is granted to the `admin` role and to each `--grant-to` role that exists, with `givePermissionTo()`. **It never revokes anything from any role**, so it cannot undo a tenant customisation and cannot undo the lane's `manager` recall revocation.
- **Safe on a marked tenant.** It reads no marker and writes no marker, because it touches neither `provisioning_source` nor `general_manager`'s existence — it only adds rows and pivots. That is why it is safe to run where `tenants:seed` deliberately no-ops.
- **Idempotent and logged.** A second run reports `created=0 granted=0`. One marker line per tenant: `PERMISSIONS-ENSURE tenant=<uuid> created=<n> granted=<n> missing_roles=<comma-list>`, on the command output and the stderr channel, the same shape as the sync marker (§4.3.1).
- **Runs per tenant through the same fleet mechanism** the entrypoint already uses for migrations, so it inherits per-tenant failure isolation.

> **It is explicitly a pre-wave-1 stopgap.** `permissions:ensure` exists only because wave 0b ships live route gates before the registry does. `permissions:sync` **supersedes it entirely** — sync's step 2 creates every registry key and step 7 applies template grants, which is a strict superset of what this command does. Wave 1's deliverable list therefore includes **deleting `EnsurePermissions.php` and its deploy rows** in the same commit that lands `permissions:sync`, and `PermissionsSyncSupersedesEnsureTest` asserts that running sync on a tenant that had `permissions:ensure` applied produces `ALREADY_CURRENT` with every counter zero — the mechanical proof that the stopgap left nothing behind for sync to disagree with.

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
- **The ratchet measures a stricter thing than the audit's `AUTH_ONLY` count, deliberately.** The audit's 142 are routes with no check at *any* layer. The ratchet counts routes with no *middleware* gate, which is what makes E-2 and E-4 enforceable — a route checked only in a controller or only in a FormRequest is uncovered. Recomputed from `docs/superpowers/audits/2026-09-09-roles-permissions/02-route-enforcement-sweep.csv` at `971528977`, and **re-derived again in rev 3 with a CSV parser rather than by column split** (minor 1 — rev 2's `638` was simply wrong and made its own arithmetic fail to close): of 1054 data rows, **642 `MIDDLEWARE` + 68 `SUPERADMIN_ONLY` = 710** carry a gating middleware and 18 are `PUBLIC`, leaving **326 uncovered — 177 writes and 149 reads** (writes = any row whose `method` column contains POST, PUT, PATCH or DELETE). `642 + 68 + 18 + 326 = 1054` exactly, with no intermediate reclassification needed; rev 2's figure required an unstated transformation and still left four routes unaccounted for (the audit's prose figure of "178 write routes with no route-level middleware" counts one tombstone this classification puts elsewhere). Those 326 decompose as 142 checked nowhere, 130 checked in a controller, 46 in a FormRequest, 8 by a policy.
- **Two ceilings** in the test file: `UNCOVERED_WRITE_CEILING` and `UNCOVERED_READ_CEILING`, both shrink-only. Wave 0a generates the baseline **after** `authz.self` and the public allow-list are applied (0a-3), which reclassifies four inert 410-tombstone writes, six self-service writes and `GET /api/v1/auth/me` — giving `UNCOVERED_WRITE_CEILING = 167` and `UNCOVERED_READ_CEILING = 148`. The two ceilings are separate so that closing reads can never buy headroom for writes — D3's "writes first". Wave 0a takes the write ceiling to **151** and wave 0b to ~128. **Rev 3 corrects rev 2's count (minor 2):** after the two `batches` writes moved to 0b, wave 0a's own task list still closes **sixteen** writes — Promotion 4, Uom 5, Menu 3, Coupon 3, counting-item 1 — not fourteen, and `167 − 16 = 151` is the figure rev 2 already printed, so the prose and the arithmetic disagreed rather than the arithmetic being wrong; the controller- and FormRequest-checked remainder is wave 3's, which is why the initial ceilings are large and the shrink schedule, not the starting value, is the commitment.
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

**Route files need a second guard, because PHPStan cannot see them.** `apps/api/phpstan.neon:5-8` analyses `app/` only, so the rule reaches module `routes.php` files under `app/Modules/*/Presentation/` but **not** the top-level `routes/` directory. Rather than widen PHPStan's paths (which pulls unrelated files into level 8 and would need its own baseline), a companion architecture test **`RoutePermissionLiteralTest`** parses every `app/Modules/*/Presentation/routes*.php` **and** every file under `routes/` with `nikic/php-parser` (already a PHPStan dependency), extracts each `can:` / `require.any.permission:` middleware argument, and asserts every extracted key is in `PermissionRegistry::activeKeys()`.

> **The test STATICALLY EVALUATES constant expressions and accepts them (rev 3, B-6).** Rev 2 said a concatenated or variable-built key is "unresolvable" and is reported — which would have failed **every route migrated to the form this very spec prescribes**, `'can:'.InventoryPermission::TransfersReconcile->value` (§4.2.5, and the stacked-AND example in §4.2.5's T-2/T-3 walkthrough). A guard that rejects the migration target is not a guard, it is a wall. So the extractor evaluates, rather than merely reads, three constant node shapes and resolves each to its string value before checking the registry:
>
> | Expression in a route file | Handling |
> |---|---|
> | `'can:inventory.transfers.reconcile'` | plain `String_` — resolved, then reported as a **literal** (use the enum) *and* checked against the registry |
> | `'can:'.InventoryPermission::TransfersReconcile->value` | `Concat` of a `String_` and a `ClassConstFetch`-derived enum-case `->value` — **evaluated and ACCEPTED**; the resolved key is checked against the registry |
> | `implode(',', [InventoryPermission::TransfersReconcile->value, …])` / an array of such expressions inside `->middleware([...])` | **evaluated and ACCEPTED**, element by element, when every element is itself constant |
>
> Resolution uses `nikic/php-parser`'s `ConstExprEvaluator` with an enum-case fallback that reads the case's declared value from the module enum's own AST — no autoloading, no reflection on a booted app, so the test stays a pure static walk. **Only a genuinely non-constant expression is reported** — a key built from a variable, a function call, a match arm on request data — with the message *"route permission key at `<file>:<line>` is not statically resolvable; a route gate must be a constant expression so CI can verify it exists."* That is a small, deliberate class, and there are zero such sites in the tree today.
 It is an AST walk, not a grep, so nothing is silently skipped: every extracted argument is either resolved and checked, or reported as non-constant.

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

Both lists are deleted and replaced by the `roles.is_system` column (§5), which sync sets (§4.3.2 steps 6–7). Rename → 422 `SYSTEM_ROLE_PROTECTED` when `is_system`; delete → 422 `SYSTEM_ROLE_PROTECTED` when `is_system`. The FE reads `is_system` from the role payload — which `RoleController::index`/`show` already shape (`:141-149`, `:167-175`) and which gains the three new fields — instead of matching names.

#### 4.6.2 The admin permission floor

`RoleController::update` guards **only the rename**: the check at `:229` fires only when `$request->has('name') && input('name') !== $role->name`, and `$role->syncPermissions($validated['permissions'])` at `:246` then runs **unconditionally, including on `admin`**. A `roles.manage` holder can `PATCH /api/v1/roles/{adminRoleId}` with `{"permissions": []}` and empty the tenant's admin role, with no audit row (`:223-266` emits none). It is latent today only because `roles.manage` is held by `admin` alone.

> **Floor F-1 (admin permission floor).** A request that would leave the role whose `template_key = 'admin'` holding fewer than `PermissionRegistry::activeKeys()` is refused with 422 `ADMIN_PERMISSION_FLOOR`. The admin role's permission set is not operator-editable at all: `PATCH /roles/{id}` on it ignores `permissions` and returns 422 if one is supplied. Its set is a function of the registry (§4.3.2 step 8), which is the same rule Odoo and ERPNext apply to their Settings/System-Manager equivalents.

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

**Attribution is added once, at the audit chokepoint — and into the METADATA argument, not the attributes one (rev 3, M-4).** Rev 1 put `(principal_id, token_id)` "on every audited write" without naming where; rev 2 named the place but the **wrong argument**, and the mistake is silent rather than loud. `AuditService::record()` builds one `array_filter([...])` and passes it as **`attributes:`** (`apps/api/app/Modules/Compliance/Services/AuditService.php:73-81`) — that array reaches `AuditEvent::__construct`'s `$attributes` parameter and goes straight to `parent::__construct($attributes)` (`apps/api/app/Modules/Compliance/Domain/AuditEvent.php:90-100`), i.e. Eloquent **mass assignment**, filtered by `$fillable` (`:44-64`), which lists neither `principal_id` nor `token_id`. Meanwhile only the separate `$metadata` argument is JSON-encoded into the `metadata` column (`:133`). So rev 2's instruction would have written the pair **nowhere at all**, with no exception — the `impersonator_id`/`impersonation_session_id` entries beside it work only because they *are* real columns on `$fillable` (`:58-63`).

> **Corrected contract.** The pair is composed inside `AuditService::record()` and **merged into the `$metadata` array** immediately before the `AuditEvent` is constructed: `$metadata = array_merge($metadata, array_filter(['principal_id' => …, 'token_id' => …], fn ($v) => $v !== null));`, then passed as the existing `metadata:` argument at `AuditService.php:72`. That lands them in `audit_events.metadata` (`AuditEvent.php:55,133`), which is exactly where §4.1.5 says they belong and where §5.3 promises no new column is needed. `principal_id` comes from the authenticated user and `token_id` from `currentAccessToken()`, both null-safe for console and queued contexts (no request → both simply absent). One edit, one place, and every existing audited write inherits it — the same argument §4.1.3 makes for narrowing at `hasPermissionTo()`. `AuditAttributionTest` asserts the pair is **readable back off the persisted `metadata` column** (not merely passed in) for a session write, a token write and an impersonated write, and is absent — not empty-string — for a queued write.

**`AuthorizationDenied` obeys rule 8 like every other event (rev 3, M-4).** It extends `DomainEvent`, declares an explicit immutable `getEventName(): string { return 'authz.denied'; }`, is registered in `DomainEventSubscriber`'s explicit list in the commit that introduces it, and is frozen on merge: a later shape change is `AuthorizationDeniedV2`, never an edit. Rev 2 gave the three role-mutation events that treatment and left the denial event without it, even though CLAUDE.md rule 8 draws no distinction.

**403 denial events — on `audit_events`, per settled D8 (rev 2, B-8).** A denial event is emitted when a `can:`/`require.any.permission` middleware or a `Gate::authorize` refuses a request on a **write verb** route. Reads are excluded deliberately: a UI that renders from a stale permission set produces read 403s in bulk, and drowning the audit log is how denial logging gets switched off. Payload: `{route_name, method, uri_template, permission, principal_id, token_id, company_id, ip}` — never the request body, which can carry money, PII or a PIN.

Denials are written as an `AuthorizationDenied` domain event onto **`audit_events`**, beside `RoleAssigned` and the three role-mutation events. **There is no `security_events` table**: rev 1's OQ-3 proposed one and simultaneously admitted it was missing from §5, which reopened a settled ruling and made the schema section untrue. D8 settled this — denials go on the audit chain — so §5's statement that this design adds exactly two tenant migrations and no other table stands unqualified, and OQ-3 is deleted from §9.2 rather than answered.

The volume objection rev 1 raised against the audit chain is real, and it is what the dedup rule is for; that rule is therefore **load-bearing, not merely prudent**, and is specified as an implementable algorithm rather than a sentence:

> **Dedup/rate rule (rev 2 — two Redis keys, because one expiring counter cannot do it).** Rev 1 specified a single five-minute counter and then asked the *next* emitted event to carry the previous window's suppressed count — impossible, because when the key expires its count is gone with it. The mechanism uses **two keys per `(principal_id, permission, route_name)` triple**, in the tenant's Redis store:
>
> - **`authz:deny:lock:<hash>`** — an emission lock, `SET … NX EX 300`. Acquiring it means "emit now"; failing to acquire means "suppress".
> - **`authz:deny:count:<hash>`** — a retained counter, `INCR` on every suppression, with a **rolling 24-hour TTL refreshed on each write** so it deliberately **outlives** the 5-minute lock. When the next emission wins the lock, it **atomically** reads and clears this counter and carries its value as `suppressed_count` on the emitted event.
>
> **The read-and-clear is ATOMIC, and that is not a detail (rev 3, M-5).** Rev 2 said "reads and `DEL`s", which is two round trips: between the `GET` and the `DEL`, another denied request can fail the emission lock and `INCR` the same counter, and the `DEL` then destroys that increment permanently. Under exactly the storm this rule exists to describe, the suppressed count is therefore wrong by an unbounded amount and always *low* — the failure mode that makes an audit number untrustworthy. The operation is a **single server-side script**:
>
> ```lua
> -- GETDEL semantics with an explicit floor, executed as one Redis command.
> -- KEYS[1] = authz:deny:count:<hash>
> local n = redis.call('GET', KEYS[1])
> if n == false then return 0 end
> redis.call('DEL', KEYS[1])
> return tonumber(n)
> ```
>
> A `MULTI`/`EXEC` transaction wrapping `GET` + `DEL` is an accepted equivalent (both are atomic against other clients, and Laravel's Redis facade exposes both); the Lua form is preferred because it returns the value directly. Redis 6.2+ `GETDEL` is a third equivalent where the deployed server version allows it — the contract is *one atomic operation*, not a particular spelling.
>
> So a storm is visible as one row saying "and 412 more" rather than as 413 rows, as silence, or as a number that undercounts by however many increments raced the delete; and a quiet 24 hours drops the counter naturally. A denial refused by the **token scope** rather than by the principal's grants is tagged `cause: token_scope` — computed by asking `EffectivePermissionResolver::forSubject()` whether the principal would hold the key with an unscoped token — because that distinction is the first question anyone debugging an MCP integration will ask.
>
> **Proved on Redis, not on SQLite, and with a concurrency probe.** `AuthorizationDenialDedupTest` is a **Redis integration test** (the fast lane cannot express `SET NX EX` semantics or TTL divergence, and rev 1's "SQLite counter arithmetic" would have asserted a different algorithm than the one shipping). It asserts: one emission per window; the suppressed count survives lock expiry; the count is carried and cleared exactly once; two different triples do not share state; **and — rev 3 — that an `INCR` interleaved between the script's read and its clear cannot be lost**, driven from a second connection against the counter key while the emitting connection runs the script, asserting the sum of `suppressed_count` over two emissions equals the number of suppressions actually performed.

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

> **SoD-1.** For every `sod_group` in the registry, no **non-admin** system-role template may hold both a `create`-class key and a **financially decisive** key (`PermissionVerb::isFinanciallyDecisive()`: `post`, `reverse`, `void`, `approve`, **`confirm`**, `refund`, `allocate`, **`pay`**) from that group. A `legacy()` key participates through its declared `behavesAs` verb (§4.2.2).

Enforced by `PermissionSodTemplateTest`, a pure registry test — no database, no fixtures, runs in milliseconds on both lanes. It asserts the rule over `registry->templateGrants()` and reports violations as `sod_group 'purchase-order': template 'manager' holds both purchase-orders.create and purchase-orders.approve`.

> **RULING (orchestrator, 2026-09-10; baseline recomputed in rev 3, B-5) — SoD-1 is a hard rule for NEW resources and a shrink-only ratchet for the existing templates.** Rev 1 claimed the test could pass on day one. It cannot. Rev 2 then printed a **five-row** baseline that was neither complete nor correctly cited: it omitted `credit-notes.create` + `credit-notes.post`, which `manager` already holds (`:599`) inside a group rev 2 itself declared; it omitted every `accountant` violation; and two of its five line numbers were wrong (`expenses.*` is `:605`, not `:606`; `inventory.adjustments.*` is `:603`, not `:602` — `:602` is transfers). **The baseline below is re-derived mechanically from `RolesAndPermissionsSeeder::rolePermissionGrants()` at `971528977`, over exactly the eight declared groups, across every non-`admin` template.** The test ships in two halves:
>
> - **Hard half** — any `sod_group` introduced from wave 1 onward, and any template grant added to an existing group, fails immediately. No baseline entry may be added, ever.
> - **Ratchet half** — the existing combinations are baselined by name in `apps/api/tests/Architecture/baselines/permission-sod-baseline.json`, counted against a shrink-only `SOD_BASELINE_CEILING`, and listed here so the owner is deciding about a table rather than about a number:
>
>   | # | `sod_group` | Template | Create-class key | Decisive key(s) | Seeder line |
>   |---|---|---|---|---|---|
>   | 1 | `invoice` | `manager` | `invoices.create` | `invoices.post` | `:598` |
>   | 2 | `credit-note` | `manager` | `credit-notes.create` | `credit-notes.post` | `:599` |
>   | 3 | `purchase-order` | `manager` | `purchase-orders.create` | `purchase-orders.confirm` | `:594` |
>   | 4 | `supplier-invoice` | `manager` | `supplier-invoices.create-pending` *(legacy, `behavesAs: Create`)* | `supplier-invoices.approve-invoice-first` *(legacy, `behavesAs: Approve`)* | `:595` |
>   | 5 | `payment` | `manager` | `payments.create` | `payments.pay-supplier` *(legacy, `behavesAs: Pay`)*, `payments.allocate`, `payments.refund` | `:609` |
>   | 6 | `expense` | `manager` | `expenses.create` | `expenses.post`, `expenses.pay` | `:605` |
>   | 7 | `stock-adjustment` | `manager` | `inventory.adjustments.create` | `inventory.adjustments.post` | `:603` |
>   | 8 | `supplier-invoice` | `accountant` | `supplier-invoices.create-pending` | `supplier-invoices.approve-invoice-first` | `:829` |
>   | 9 | `payment` | `accountant` | `payments.create` | `payments.pay-supplier`, `payments.allocate`, `payments.refund` | `:836` |
>   | 10 | `expense` | `accountant` | `expenses.create` | `expenses.post`, `expenses.pay` | `:832` |
>   | 11 | `journal` | `accountant` | `journal.create` | `journal.post` | `:841` |
>
> **`SOD_BASELINE_CEILING = 11` on the current tree, and `= 18` once `lane/w-lot-a-1a` has merged.** `general_manager`'s grant set is `manager`'s (`lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:90-91`), so it inherits rows 1–7 verbatim — seven more baseline entries, none of them a new decision. Wave 1's plan generates the file from the registry and asserts the generated set equals this table; the ceiling is the count, and it only falls.
>
> **What is deliberately NOT here, and why (the two templates a reader will look for).** `cashier` holds `invoices.create` + `invoices.print` (`:697`), `expenses.create` (`:698`) and `payments.create` (`:705`) — create-class only, no decisive key in any group, so it is clean. `operator` is likewise create-and-update only (`:799,801,802,805,809`). `viewer` and `technician` hold no create-class key in any of the eight groups. The rule is therefore already satisfied by four of the seven shipped templates without any change — which is the evidence that it is a real rule rather than a rule nothing can pass.
>
> **Removing any of these from a shipped template is an owner decision, not an engineering one** — it changes what a real manager or accountant can do on day one in a five-person pharmacy. It is recorded as the replacement OQ-3 in §9.2 ("SoD baseline retirement"), with its own benchmark, and until the owner rules the baseline holds and the ceiling only shrinks.

Note that `payments.reverse` is **already** admin-only by an earlier owner decision (`:264-268`, ratified 2026-07-10) — evidence that the owner has ruled this way before when asked about a specific verb, which is why the question is put as a table of five rather than as a principle.

Three scoping decisions, each stated so a gate can challenge them:

1. **Templates, not roles.** The rule constrains what the platform *ships*; a tenant may still create a custom role that holds both, because a five-person pharmacy legitimately has one person who raises and approves POs. The benchmark supports this: neither Odoo, ERPNext nor Dolibarr implements NIST SSD/DSD generically — they separate create from approve *in the shipped defaults* (Odoo's Purchase Order Double Approval group, ERPNext's per-transition workflow roles) and leave the rest to the operator. Enforcing it at runtime would be a DIVERGE from all three, and would break real small tenants.
2. **`admin` is exempt**, because `admin` holds everything by definition (§4.6.2). An SoD rule that `admin` had to satisfy would be a contradiction.
3. **Initial groups — exactly eight, and the list is closed for wave 1 (RULING, orchestrator 2026-09-10, rev 3).** They are `purchase-order` (`purchase-orders.create` vs `.confirm`/`.approve`), `supplier-invoice` (`.create-pending` vs `.approve-invoice-first`), `invoice` (`invoices.create` vs `.post`), `credit-note` (`credit-notes.create` vs `.post`), `payment` (`payments.create` vs `.pay-supplier`/`.allocate`/`.reverse`/`.refund`), `expense` (`expenses.create` vs `.post`/`.pay`), `journal` (`journal.create` vs `.post`) and `stock-adjustment` (`inventory.adjustments.create` vs `.post`). **`order`, `delivery`, `income` and `work-order` are deliberately NOT SoD groups**, even though `manager` holds create-plus-decisive combinations in all four (`orders.create` + `.confirm` `:593`; `deliveries.create` + `.confirm` `:604`; `income.create` + `.post` `:608`; `work-orders.create` + `.approve` `:626-627`).

   > **Why those four are excluded, with the benchmark.** They are **operational** transitions, not financially decisive ones: a sales order confirmation, a delivery confirmation, an income posting against an already-recorded receipt, and a work-order approval each commit the business to *doing work*, not to *money changing hands or a fiscal fact becoming real*. The benchmark supports the split rather than merely permitting it. **Odoo** ships exactly one such separation on the purchasing side — the *Purchase Order Double Validation* group, an amount-thresholded second approval on POs ([approvals](https://odoo-users.readthedocs.io/en/latest/purchase/purchases/rfq/approvals.html)) — and ships **no** equivalent shipped-default separation for sales-order confirmation or delivery validation, which any Inventory/Sales user performs. **ERPNext** makes separation-by-transition entirely **opt-in per document type through Workflow** ([workflow](https://docs.frappe.io/erpnext/workflow)), and its stock and delivery flows ship without one. **Dolibarr** states the create-versus-validate separation as a convention on financial modules only. So all three reference systems draw the same line this ruling draws: separate on the money and fiscal documents, leave the operational chain alone.
   >
   > The cost of the exclusion is stated honestly: a manager who can raise and confirm a sales order can commit stock without a second pair of eyes. That is an accepted residual, not an oversight — the compensating control is the fiscal chain and the stock-movement audit, and re-opening it means adding a group, which the hard half of SoD-1 makes a deliberate, reviewed act rather than a drift.

   Any template that violates one of the eight on day one is a **finding to rule on**, not a reason to weaken the rule — the eleven that do are enumerated in the ruling above, and OQ-3 puts their retirement to the owner.

### 4.8 Cache

The tenant-scoping fix is **S-1, Task 1 of the request-hygiene Phase A plan**, already specified and dispatched: `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:60-140`. It is referenced here, not redesigned. Its files, verbatim from `:62-66`:

- create `apps/api/app/Modules/Identity/Application/Listeners/ScopePermissionCacheToTenant.php`
- create `apps/api/app/Modules/Identity/Application/Listeners/RestoreCentralPermissionCache.php`
- modify `apps/api/app/Providers/TenancyServiceProvider.php`
- modify `apps/api/tests/Traits/ProvisionsTenantDatabases.php`
- create `apps/api/tests/Feature/Identity/PermissionCacheTenantScopingTest.php`

with the key becoming `spatie.permission.cache.<tenant-id>` in database-per-tenant mode and the shared base key remaining correct in compatibility mode (`:66`). Wave 0a's only job here is to **land that lane** — the design adds nothing to it.

**What this design adds on top.** One line, in `PermissionSyncService` (§4.3.2 step 10): `forgetCachedPermissions()` after a successful sync, which under the S-1 key scheme forgets exactly this tenant's snapshot. Two supporting facts make that necessary and sufficient: Spatie resets the cache automatically only through its own mutator methods, never for raw writes (the documented multi-tenant pitfall, `docs/superpowers/audits/2026-09-09-roles-permissions/05-benchmark-odoo-erpnext-dolibarr.md:118`), and step 1's rename is a raw `UPDATE` precisely so grants survive — so the flush is not optional. The boot-time global flush at `apps/api/docker/entrypoint.sh:176` stays as a belt.
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
| `marketplace.*`, `catalog-cart.*` *(post-rename — the manifest never declares the old `catalog_cart.*` spelling; §4.9.2, §4.9.3)*, `channels.*` (new) | Marketplace, Cart, Channel | three manifests |
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

**The deprecation set (22), authoritative — this is the list wave 1 implements. Every entry is spelled in its POST-RENAME form (rev 3, M-6):**

`audit.view`, **`catalog-cart.convert_so`**, **`catalog-cart.manage_all`**, `coupons.view`, `inventory.receive`, `invoices.print`, `menus.view`, `pos.issue_goodwill_voucher_high_value`, `pos.redeem_voucher`, `pos.refund_no_receipt`, `pos.refund_voucher_to_cash`, `pos.rotate_qr_signing_key`, `pos.search_customer_cross_company`, `pos.search_customer_recent_purchases`, `pos.void_receipts`, `pos_orders.view`, `pos_orders.create`, `pos_orders.update`, `pos_orders.delete`, `purchase-quote-requests.delete`, `workshop.technicians.adjust_time_entries`, `workshop.technicians.approve_time_off`.

(The two cart keys appear in **both** this list and the §4.9.2 rename map, and that is deliberate and consistent — but only if the two lists disagree about nothing. Rev 2 printed them here under their **pre**-rename `catalog_cart.*` spelling while the following sentence said they are deprecated under `catalog-cart.*`, which is one manifest defining two names for one key and would have defeated D5 and the orphan model outright. Corrected: the **manifest declares exactly one key, `catalog-cart.*`**; the rename map's `from` side is the only place the old `catalog_cart.*` string appears anywhere in the catalogue, because a rename `from` is by definition a name the registry no longer owns. Step 1 runs before step 3 for exactly this reason: the row is renamed in place, then the deprecation flag removes the new name from every system-role template. `PermissionRegistryConsistencyTest::no_manifest_declares_a_rename_source_key` asserts no manifest key equals any rename map `from`.)

`pos_orders.*` stays snake_case in this list because it is deliberately **not** renamed (§4.9.2) — renaming a dead key would create a second dead key — so its post-rename form is its current form.

**Re-derivation is still mandatory, and it is a wave-1 gate item.** Because deprecation removes a key from **every system-role template**, the set is re-derived from the tree **after waves 0a and 0b land** by `permissions:report-dead` (a `--dry-run`-only reporting mode of the sync command) and the result must equal these 22. A difference is a finding to rule on, not a silent update: waves 0a/0b change the answer for at least the three excluded keys, and any further drift means something else started using a key in between.

Deprecation semantics (§4.3.2 step 3): the `permissions` row survives one release; the key is dropped from every system-role template on the next sync; a **custom** role keeps it — and, when the definition names a `replacedBy`, additionally **receives the successor**, the one audited exception to "custom roles are never written" (§4.3.2 step 3's ruling) — so no tenant loses a capability without a human decision; the Roles UI renders it struck through with *"deprecated — will be removed in the next release"*. The row is deleted by `permissions:prune-orphans --confirm` one release later, never automatically.

**`reports.view`** is **deprecated, then pruned** — rev 3 drops rev 2's contradictory phrase *"removed rather than deprecated-and-kept"*, which the very next clause contradicted by specifying `deprecated: true` until wave-3 pruning (M-6). There is one mechanism and this key uses it. Its comment already says it "no longer gates any route as of this release … Kept seeded … for one release … slated for removal next release" (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:325-332`); that release has passed. It enters the registry as `deprecated: true, replacedBy: null` in wave 1 and is pruned in wave 3 — the deprecation machinery is exactly how the seeder's comment intended it to be handled, now with a mechanism instead of a comment.

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

**Two tenant migrations. No central migration. No backfill beyond what `permissions:sync` performs.** Both live in `apps/api/database/migrations/tenant/` and run under `tenants:migrate` / `tenants:migrate-rolling` *(plural — rev 3 corrects rev 2's singular; the signature is at `apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:48`, minor 6)* (`.claude/context/architecture.md`, "Migration topology"). Both must be timestamped **after** `2026_09_06_205000_add_provisioning_source_to_roles.php` (§4.3.0).

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
| `template_key` | `varchar(64)` | YES | `NULL` | **two** partial uniques (rev 3, B-7): `roles_tenant_template_key_unique ON roles (tenant_id, template_key) WHERE template_key IS NOT NULL` **and** `roles_global_template_key_unique ON roles (template_key) WHERE template_key IS NOT NULL AND tenant_id IS NULL` | — | `App\Modules\Identity\Domain\Enums\SystemRoleTemplate` |
| `template_version` | `integer` | YES | `NULL` | — | — | — |
| `customised_at` | `timestamptz` | YES | `NULL` | — | — | — |

- **`SystemRoleTemplate`** is a string-backed enum whose cases are `admin, general_manager, manager, cashier, viewer, technician, operator, accountant`. Its `GeneralManager` case **reuses** W-LOT-A-1a's `SystemRoleName::GeneralManager` value `'general_manager'` (plan `:1241-1244`); the two enums are reconciled into one in wave 3, and until then a test asserts the two values are equal so they cannot drift.
- **Two partial uniques, because one index cannot express both topologies (rev 3, B-7 — this REVERSES rev 2).** Rev 2 made the index globally unique on `template_key` alone and argued the tenant column would break it. That is right for database-per-tenant and **wrong for compatibility mode**, which this design explicitly still supports (§4.3.3, EC-21): there every tenant's roles share one physical table, and the existing Spatie unique is `(tenant_id, name, guard_name)` (`apps/api/database/migrations/tenant/2025_11_29_231806_create_permission_tables.php:43-47`), so **two tenant-scoped `manager` rows are legal today**. Adopting both — which §4.3.0a rule 4 requires, since adoption is the thing compatibility mode must not skip — would have violated a globally unique `template_key` and made the migration itself the blocker. The pair:
  - `roles_tenant_template_key_unique ON roles (tenant_id, template_key) WHERE template_key IS NOT NULL` — "at most one role per template **per tenant**", which is the real invariant in both topologies and the only one that holds in compatibility mode;
  - `roles_global_template_key_unique ON roles (template_key) WHERE template_key IS NOT NULL AND tenant_id IS NULL` — the NULL-team half. Because PG treats every NULL as distinct, the first index alone would permit two adopted NULL-team `manager` rows; this second one forbids exactly that without touching the tenant-scoped rows. It is the precise expression of rev 2's concern, scoped to the rows it is actually about.
  
  Neither index writes `tenant_id`, so the W-LOT CHECK's `tenant_id IS NOT NULL` clause is untouched (§4.3.0's second collision hazard). Convention 09's reasoning is unchanged and is what motivates the second index: *"under db-per-tenant `unique(['sku'])` is the same bug"* (`docs/conventions/09-SECOND-OF-EVERYTHING.md:54-59`) — here the NULL-team rows are the ones a tenant column would fail to constrain.
- **CHECK constraints** (PG, with SQLite triggers): `template_key IS NULL OR is_system`, `customised_at IS NULL OR is_system`, and — **added in rev 3 (minor 8)** — `template_version IS NULL OR template_key IS NOT NULL`. Rev 2's pair permitted `template_version = 7, template_key = NULL, is_system = false`, a state that contradicts the column's own stated meaning below; normal sync never creates it, but a CHECK that permits a meaningless state is a CHECK that will eventually be handed one. A custom role has all four columns at their defaults.
- **`template_version`** is the registry's monotonically increasing catalogue version, an integer bumped by the wave-1 release process and asserted non-decreasing by a test. It is not a semver and not a timestamp; its only job is to answer "how many template updates has this role missed".
- **`customised_at`** is set by `RoleController::update` on the **first** tenant edit of a system role's permission set and never cleared except by the explicit *Re-apply template* action (§4.6.6). It is a timestamp rather than a boolean so the UI can say *"customised on 12 Aug 2026"*.
- **No column on this table is written by `permissions:sync` for a custom role**, and `provisioning_source` and `id` are never written by it for any role. **Rev 3 corrects rev 2's over-claim (minor 7):** step 7 creates a missing non-`general_manager` template role, and creating a row necessarily writes `name`, `guard_name` and `tenant_id`. The precise statement is therefore: sync **never writes `name`, `guard_name` or `tenant_id` on a role that already exists** — every existing role is resolved with the NULL-team-tolerant predicate and its `tenant_id` is preserved exactly as found (§4.3.0 clause 3) — and it writes them only on a row it creates itself, which is never `general_manager` (step 5 owns that) and never a custom role. That is the whole of §4.3.0's superset promise expressed as a schema statement.

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

So this design's obligation is **not to add an exclusion** but **not to invalidate an existing one**. The two new unique keys it introduces (§5.2, rev 3) both sit inside the already-excluded `roles` entry: `roles_tenant_template_key_unique` **carries `tenant_id`** and so would satisfy the ratchet's own shape rule even if `roles` were ever swept, while `roles_global_template_key_unique` is deliberately tenant-less because it constrains exactly the `tenant_id IS NULL` legacy rows. Wave 1 must confirm the ratchet stays green rather than editing it. If a future lane ever removes the `roles` exclusion, the waiver wording it needs is: *"tenant-global by nature — owner ruling D1 (2026-09-10) keeps roles scoped to the tenant, with company and location scope carried by `user_company_memberships`; a per-company role would be a second surface for the same concept (convention 11)."* **But two of the three axes still apply to this design's behaviour**, and the obligations below are real tests, not a waiver:

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

**Forty-five rows in rev 3** — the thirty-eight of rev 2 (the original thirty-two minus EC-22, withdrawn with its number retained, plus EC-4a, EC-4b, EC-9a, EC-15a, EC-18a, EC-21a and EC-16a) plus seven added by the gate-r2 fix round: **EC-9b** (the second half of the EC-9a split, B-3), **EC-21b** (NULL-team template-key collision, B-7), **EC-32a** (permission-selected notification recipients, M-3), **EC-32b** (service account created with its first membership and location restriction, M-2), **EC-32c** (wave-0b provisioning on a marked tenant, B-4) and **EC-32d** (a successful boot clearing a failed boot's health state, B-8). The gate's fifth missing scenario — concurrent denial increments — is folded into EC-28 rather than given a row of its own, because it is an assertion about the same mechanism (M-5). Each is a scenario, the expected behaviour, the single place that enforces it, and the lane that proves it. Lanes: **PG** = PHPUnit on PostgreSQL (real tenant databases, `ProvisionsTenantDatabases`), **SQLite** = PHPUnit on the fast lane, **vitest** = `apps/web`, **playwright** = `apps/web/e2e`, **pos-vitest** = `apps/pos`.

| # | Scenario | Expected behaviour | Where enforced | Test lane |
|---|---|---|---|---|
| EC-1 | The admin role is removed from the **last** active human principal holding it | 422 `LAST_ADMIN_ROLE_REMOVAL`; nothing written | `LastAdminFloor`, called from `RoleController::removeRole` inside the mutation transaction (§4.6.3) | PG — `LastAdminFloorTest::removing_the_admin_role_from_the_last_admin_is_refused` |
| EC-2 | The last admin is **deactivated** (`POST /users/{id}/deactivate`) | 422 `LAST_ADMIN_DEACTIVATION`; `status` unchanged | same guard, `UserController::deactivate` | PG |
| EC-3 | The last admin is **deleted** | 422 `LAST_ADMIN_DELETION` | same guard, `UserController::destroy` | PG |
| EC-4 | The tenant's **only** admin is a service account, and the only human admin is deactivated | Refused — service principals do not count toward the floor (§4.6.3 clause a) | `LastAdminFloor` filters `principal_kind = human` | PG — `LastAdminFloorTest::a_service_admin_does_not_satisfy_the_floor` |
| **EC-4a** *(new, rev 2)* | The tenant's only remaining human admin holds the role but has **no active membership**, and the last admin *with* a membership is deactivated | Refused — a membership-less admin is not in *A*, because company access is membership-based (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:147-155`) and such a principal cannot recover the tenant | `LastAdminFloor`'s membership clause (§4.6.3) | PG — `LastAdminFloorTest::a_membershipless_admin_does_not_satisfy_the_floor` |
| **EC-4b** *(new, rev 2)* | An admin is demoted through **`PATCH /users/{id}` with a different `role`** rather than through `removeRole` | 422 `LAST_ADMIN_ROLE_REMOVAL` — `syncRoles()` is a wholesale replacement and is the writer rev 1 missed (`apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:380-389`) | `LastAdminFloor`, called from `UserController::update` (§4.6.3 writer 5) | PG — `LastAdminFloorTest::update_with_a_different_role_cannot_empty_the_admin_population` |
| EC-5 | A **custom** role holds a permission the registry now marks **deprecated** | The grant survives; the key disappears from every system-role template; sync **never** revokes it. **Rev 3 resolves the contradiction the gate flagged (M-6):** when the deprecated definition names a `replacedBy`, the custom role is *additionally granted the successor* and a `RoleUpdated` audit row is written — the single, named exception to "custom roles are never written", so the two statements no longer disagree. The Roles UI strikes it through with *"deprecated — scheduled for removal"* — **rev 2 drops rev 1's "removed next release" wording**, which promised something no part of this design decides: sync never deletes a permission row (step 4 reports orphans only) and pruning is an explicit later operator action (`permissions:prune-orphans --confirm`, wave 3, §10). The UI states the intent, not a date | `PermissionSyncService` step 3 (§4.3.2); `RolesPage` renders `deprecated` from the payload | PG + vitest |
| EC-6 | A **custom** role holds a permission that the rename map renames | The grant survives because the rename is an in-place `UPDATE` on `permissions.name`; the primary key never changes, so `role_has_permissions` is untouched | `PermissionSyncService` step 1 (§4.3.2) | PG — `PermissionRenameTest::a_custom_role_grant_survives_a_rename` (asserts the pivot row count and the role's resolved key set, not just absence of error) |
| EC-7 | Rename map says `a → b` and **both** rows already exist in a tenant | Run is `BLOCKED`, `reason=rename_target_exists`, transaction rolled back, exit 2; a human decides which grants win | `PermissionSyncService` step 1 | PG |
| EC-8 | A deploy adds permissions **while a user's session is live**; their cached Spatie snapshot is stale | The tenant's snapshot is forgotten **after the commit lands** (`DB::afterCommit`, step 10 as corrected in rev 2 — a `finally` inside the transaction callback fires *before* commit and leaves a window for another request to re-cache the old snapshot), so the next request rebuilds it; the FE converges on its next `/auth/me` | S-1 tenant-scoped key (§4.8) + the after-commit flush in step 10 | PG — `PermissionCacheTenantScopingTest` (S-1's own) + `PermissionsSyncTest::sync_forgets_only_this_tenants_cache` + `::the_cache_is_flushed_after_commit_not_before` |
| EC-9 | A tenant has **customised** its `manager` role; a deploy adds a permission whose template lists `manager` | `manager` receives **nothing**; the run reports `templates_skipped_customised=1`; `template_version` stays behind; the UI shows "N template updates not applied" with *Re-apply template*. **Depends on adoption (§4.3.0a rule 2) and on the wave-1 `customised_at` writer** — without both, a role customised before wave 1 is indistinguishable from a pristine one, which is the B-2 hole rev 1 left | `PermissionSyncService` step 6 then step 7's `customised_at IS NOT NULL` branch (D2) | PG |
| **EC-9a** *(rev 2; SPLIT IN TWO in rev 3 — the original row was database-impossible, B-3)* | **(a) A customised NULL-team UNMARKED role.** A tenant customised a legacy `manager` that carries `tenant_id NULL` and no `provisioning_source`, then wave 1's first sync runs | Adoption classifies it as customised (`customised_at = now()`), `tenant_id` is preserved as found (NULL stays NULL), `provisioning_source` is not written because there is none, and no delta is applied. This is the NULL-team case rev 2 was reaching for | §4.3.0a rules 1–2 + §4.3.0 clause 3 | PG — `PermissionSyncAdoptionTest::a_customised_null_team_unmarked_role_is_adopted_as_customised` |
| **EC-9b** *(new, rev 3 — the second half of the split)* | **A customised MARKED tenant role.** A marked tenant customised its `general_manager`, which necessarily has **`tenant_id NOT NULL`**, then wave 1's first sync runs | Step 5's template migration is skipped (the marker is present), adoption then classifies the role as customised against the **`general_manager` version-0 snapshot** (`customised_at = now()`), `provisioning_source` is **not** written, and no template delta reaches it. **Rev 2's single row asked for a NULL-team role that already carried the marker, which no database can hold**: the marker CHECK requires `tenant_id IS NOT NULL` (`lane/w-lot-a-1a:apps/api/database/migrations/tenant/2026_09_06_205000_add_provisioning_source_to_roles.php:34-48`) and the delta additionally refuses any global marked role (`lane/…/LotActionPermissionDelta.php:61-63`). The two real cases are the two rows above | §4.3.0a rules 1–2; `RoleMarkerRepository`; the version-0 snapshot | PG — `PermissionSyncAdoptionTest::a_customised_marked_tenant_role_is_adopted_without_touching_the_marker` |
| EC-10 | The operator uses *Re-apply template* on that role | `customised_at` cleared, the template delta applied for that role only, `template_version` advanced, `RoleUpdated` audited with the diff | `RoleController` re-apply action → **`PermissionSyncService::syncRole(string $tenantId, string $templateKey, bool $write)`** — rev 2 names the method, because rev 1 invoked a "single-role path" that the declared signature `sync(string, bool)` does not contain | PG + playwright |
| EC-11 | A tenant **renames a custom role** that is referenced nowhere by key | Allowed; `model_has_roles` is keyed by role id, so assignments survive | `RoleController::update`, `is_system` false branch | PG |
| EC-12 | A tenant tries to rename or delete a **system** role | 422 `SYSTEM_ROLE_PROTECTED` on both, driven by `roles.is_system`, not by a name list | `RoleController::update`/`destroy` (§4.6.1) | PG + vitest (`RolesPage` hides the actions from the payload flag) |
| EC-13 | A `roles.manage` holder `PATCH`es the **admin** role with `{"permissions": []}` | 422 `ADMIN_PERMISSION_FLOOR`; nothing written. **Rev 2 — it is audited as a floor refusal, not as a 403 denial.** §4.6.5's denial rule fires on a 403 from an authorization gate; this caller *is* authorized to reach the endpoint and is refused by a domain invariant with 422. Conflating the two would put a business-rule refusal into the denial dedup stream and make `suppressed_count` meaningless. It emits its own `RoleUpdateRefused` audit event carrying `{role_id, reason: 'admin_permission_floor', principal_id, token_id}` | Floor F-1 (§4.6.2), checked before `syncPermissions()` | PG — `AdminPermissionFloorTest`, including the deny path (today's `apps/api/tests/Feature/Identity/RBACTest.php:137-165` only exercises an allow path) |
| EC-14 | A user **switches company** | Roles are tenant-scoped (D1) so the permission set does not change; company/location scope does, and every list re-keys | unchanged behaviour; `tenantScopedKey` appends `companyId` (`apps/web/src/lib/tenantScopedKey.ts:29-34`) | vitest + playwright |
| EC-15 | A user restricted to one location is assigned `general_manager` | **Rev 2 — REVERSED. The assignment is REFUSED with 422 `GENERAL_MANAGER_REQUIRES_UNRESTRICTED_MEMBERSHIP`.** Rev 1 said the user keeps the restriction and gains the action; that contradicts the incoming lane, whose `GeneralManagerAssignmentGuard` locks every active membership of the target and refuses when any carries a non-null `allowed_location_ids` (`lane/w-lot-a-1a:apps/api/app/Modules/Identity/Application/Services/GeneralManagerAssignmentGuard.php:19-23,36-49`). The invariant is part of the adopted glossary row (§2) and D7 makes that lane's work absorbed, never undone. The orthogonality claim survives one level down: *other* roles still leave `allowed_location_ids` untouched — it is `general_manager` specifically that requires an unrestricted membership, because "all-location treasury authority" and "restricted to one location" are contradictory as a **grant**, not merely as a scope | `GeneralManagerAssignmentGuard` (that lane's, invoked from Settings → Users, its sole assignment surface); `ValidateLocationAccess` + `LocationContext` unchanged for every other role | PG — `GeneralManagerAssignmentTest` (that lane's own) **plus** `GeneralManagerLocationScopeTest`, which asserts the *rows returned* for an unrestricted general manager |
| **EC-15a** *(new, rev 2)* | A user **already holding** `general_manager` has a location restriction added to one of their memberships | Refused with the same 422 — the guard's `assertLocationChangeAllowed` covers the change-side of the invariant, not only assignment | `GeneralManagerAssignmentGuard::assertLocationChangeAllowed` | PG |
| EC-16 | A principal's **last active membership** is revoked while an API token is live | Every token of that principal is revoked; the next request is 401. A revocation that leaves another active membership revokes nothing, and the removed company is denied by the `X-Company-Id` check instead. **Rev 2 names the boundary**: every membership-removing writer goes through `MembershipRevocationService` (§4.1.5) — "when a membership is removed" was not a checkable rule, since no standalone membership-removal writer exists today (revocation lives inside `UserController.php:988-998`) | §4.1.5 rule 1; `MembershipRevocationService`; `EnforceTokenScope` (§4.1.4) | PG — `ServiceAccountRevocationTest`, both branches, plus a writer census asserting no other production path removes a membership |
| **EC-16a** *(new, rev 2)* | The tenant-side membership removal commits but the **central-side token delete fails** | The two are on different connections and therefore **cannot** be one transaction — rev 1's atomicity claim is withdrawn. The membership change stands, a `RevokePrincipalTokens` job is queued with backoff, the pending revocation is recorded on the audit chain, and the window is harmless because `EnforceTokenScope` refuses the token on its next request for lack of an active membership. **Rev 3 makes that assertion true rather than aspirational (B-1):** it holds only because the middleware is now ATTACHED to the global `api` group (§4.1.4), not merely ordered in the priority list — rev 2 ordered a middleware nothing carried, so the refusal it promised could not have happened | §4.1.5, `MembershipRevocationService`, `EnforceTokenScope` attached at `apps/api/bootstrap/app.php:143-153` | PG — the central delete is forced to fail; asserts the job is queued, the audit row exists, and the very next request with that token is refused, **including on `GET /api/v1/auth/me`**, which carries no `can:` gate |
| EC-17 | A principal's roles change while an open browser tab holds a 5-minute-stale `/auth/me` | The UI may render a stale affordance for up to 5 minutes; **every mutating request is still 403**. Recorded as a known UX exposure, not a security one | server-side `can:`; FE staleness from `AuthProvider` `staleTime: 1000*60*5` | vitest (`usePermissions` renders from the stale store) + PG (the write is refused) |
| EC-18 | A token carries `permission:` abilities **wider** than the principal's grants | The extra keys grant nothing — the scope is intersected with live database grants, never unioned. **Rev 2 extends the row three ways** (§4.1.3a): (i) the same test asserts a `hasRole()`-style idiom cannot be reached from production code, by asserting the PHPStan/ESLint role-name baseline is empty for the three converted files; (ii) `forSubject()` returns the intersection for the principal's own request while `forTarget()` returns unnarrowed grants for the same principal read by an admin; (iii) `getAllPermissions()` on a freshly loaded `User` (no `currentAccessToken()`) is asserted to return unnarrowed grants, which is the documented contract rather than an accident | `User::hasPermissionTo()` / `getAllPermissions()` (§4.1.3 rule 1) + `EffectivePermissionResolver` (§4.1.3a) | SQLite — `TokenScopeNarrowingTest::a_scope_wider_than_the_principal_grants_nothing`, `::for_target_is_not_narrowed_by_the_callers_token`, `::role_name_authorization_baseline_is_empty_for_converted_files` |
| **EC-18a** *(new, rev 2)* | A scoped token calls a route still gated by a **role name** (`CreateDraftCountingRequest`, an `InventoryCountingController` bypass, or the POS discount ceiling) | Before wave 2a's conversion this **bypasses the scope entirely** — which is why converting those eight sites is an **entry condition** of token issuance, not a follow-on (§4.1.3a (b)). After conversion, the call is refused when the key is outside the scope | `ScopedTokenIssuanceEntryConditionTest` asserts the backend baseline is empty for the three converted files before `service-accounts.issue-token` is routable | PG — one test per converted site, asserting a scoped token is refused where an unscoped one succeeds |
| EC-19 | Two admins concurrently remove each other's admin role | Exactly one succeeds; the loser gets 422 `LAST_ADMIN_ROLE_REMOVAL`. **`SELECT … FOR UPDATE` over the candidate `users` rows ordered by `users.id` ascending, then their `model_has_roles` pivots** — rev 2 states the order because an unspecified lock order over "candidate rows" is a deadlock, not a floor | `LastAdminFloor` (§4.6.3 clause c) | **PG only** — a real two-connection concurrency test; mark-skips on SQLite |
| EC-20 | `permissions:sync` is run **twice concurrently** on one tenant | The second blocks on the transaction-scoped PG advisory lock, then finds nothing to do and reports `ALREADY_CURRENT`. Where the two runs also touch `users`/`model_has_roles`, they take the same `users.id`-ascending lock order as EC-19 | `PermissionSyncService::acquireTenantLock` (§4.3.2), the lock shape W-LOT-A-1a establishes | **PG only** — two-connection test |
| EC-21 | `permissions:sync --tenant=x` in **compatibility mode** (`TENANCY_DB_PER_TENANT=false`) | Exit 2, `reason=compatibility_mode_is_global`. Invoked **without** `--tenant`, steps 1–4 **and step 6 (adoption)** run globally with `templates_applied=0` and `template_migrations_applied=0`. **Rev 2 — adoption is no longer skipped here**: it is a DB-level classification, and skipping it (rev 1) would leave every system role at `is_system = false`, so wave 2's rename/delete protection and both floors would have nothing to stand on. **Rev 3 adds the second tenant, which is what makes the row load-bearing** (B-7): with **two** tenants' roles in one shared table, adoption writes `template_key = 'manager'` on **two** rows, which a globally unique index would have rejected | `SyncPermissions::handle` (§4.3.3); the `(tenant_id, template_key)` partial unique (§5.2) | **PG** — two tenants seeded into one shared schema; asserts both `manager` rows adopt with `template_key='manager'`, that `is_system` is set on both, that `templates_applied = 0`, and that a **third** row with the same `(tenant_id, template_key)` is rejected by the index. *(Rev 2 ran this on SQLite with one tenant, which could not express either half.)* |
| **EC-21b** *(new, rev 3)* | Two **NULL-team legacy** roles named `manager` exist in one database and adoption runs | The first adopts; the second violates `roles_global_template_key_unique` and the run is `BLOCKED`, `reason=template_key_collision`, transaction rolled back — the same disposition EC-7 gives a rename collision, because both are "two rows claim one identity and a human must choose" | §5.2's NULL-team partial unique; `PermissionSyncService` step 6 | PG — asserts the exit code, the marker reason and that neither row was written |
| **EC-21a** *(new, rev 2)* | A fleet sync where **one tenant returns `BLOCKED`/`FAILED`** while the run as a whole completes | `permissions:sync-fleet` still attempts every remaining tenant, prints the failing tenant ids in its aggregate marker, and **exits non-zero**; the entrypoint keeps booting but writes the failure marker and `GET /api/v1/admin/monitoring/health` reports `permissions_sync.healthy = false`. This is the case `tenants:run` cannot express, because it discards child exit statuses (`docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:2956`) and the entrypoint continues past errors (`apps/api/docker/entrypoint.sh:150-169`) | `SyncPermissionsFleet` (§4.3.3) | PG — three tenants, one seeded into a `BLOCKED` state (rename target exists), asserting exit code, marker content and the `permissions_sync` key on the authenticated admin health endpoint |
| ~~EC-22~~ | ~~A **central super admin** calls a tenant route with a token carrying the `super-admin` ability~~ | **WITHDRAWN in rev 2 — not a supported path, so there is nothing to test.** A central `SuperAdmin` has no `tenant_id` (`apps/api/app/Models/SuperAdmin.php:14-32`), while `SetPermissionsTeam` reads `$user->tenant_id` on every authenticated subject (`apps/api/app/Modules/Identity/Presentation/Middleware/SetPermissionsTeam.php:22-29`) and `ResolveTenancy` selects the tenant database from a `tenant:` ability the super-admin token does not carry (`apps/api/app/Modules/Identity/Presentation/Middleware/ResolveTenancy.php:114-134`). A central token calling an ordinary tenant route is not a tenant authorization path at all; platform access to a tenant goes through impersonation (G6/G14), which **is** covered — by EC-32 and by the SupportAccess lane. The row number is retained rather than reused so earlier cross-references do not silently repoint | — | — |
| EC-23 | A route is "gated" **only** by a FormRequest whose `authorize()` returns `true` | The route counts as **uncovered** in the ratchet and must gain a `can:` or `authz.self` | E-4 (§4.4.1) + the ratchet's classification, which reads middleware only | SQLite — `RoutePermissionCoverageRatchetTest` |
| EC-24 | A contributor adds a new ungated route **and** its baseline entry in one commit | Anti-growth direction fails: the baseline key set no longer matches the CI-pinned protected blob | `RoutePermissionCoverageRatchetTest` (§4.4.3), the `DocumentPerActionBaselineRatchetTest` mechanism | SQLite + CI env var |
| EC-25 | A baselined uncovered route is **fixed** but its baseline entry is left behind | Stale direction fails: "remove this entry" | same test | SQLite |
| EC-26 | A new permission ships with **no fr or ar label** | `PermissionRegistryLabelCoverageTest` fails and names the missing keys; `permissions:export-label-skeleton` writes a complete per-locale file to edit | §4.5.2 | SQLite |
| EC-27 | A module's enum gains a case with no manifest definition (or vice versa) | `PermissionEnumManifestParityTest` fails in **both** directions | §4.2.5 | SQLite |
| EC-28 | A misconfigured integration produces hundreds of 403s per minute | At most one `AuthorizationDenied` on `audit_events` per `(principal, permission, route)` per 5 minutes; the next emitted event carries `suppressed_count`. **Rev 2 — two Redis keys, not one:** a 5-minute `SET NX EX` emission lock plus a separately-TTL'd (24 h, refreshed) retained counter. **Rev 3 — the counter's read-and-clear is ONE atomic operation** (Lua script, or `MULTI`/`EXEC`, or `GETDEL`), because a `GET` followed by a `DEL` loses every increment that races the gap and always undercounts | §4.6.5 dedup rule | PG (the event lands once on the audit chain) + **Redis integration** — `AuthorizationDenialDedupTest`, **including a two-connection concurrency case** that interleaves an `INCR` with the script and asserts no suppression is lost; rev 1's "SQLite counter arithmetic" is withdrawn, since SQLite cannot express `SET NX EX`, divergent TTLs or server-side atomicity |
| EC-29 | A **module is disabled** for the tenant but a role still grants its permissions | The route is refused by `module:<Name>` middleware before `can:` is reached; the permission stays granted and simply cannot be exercised; `GET /permissions` hides the module's group as it does today | `RequireModule` (`apps/api/bootstrap/app.php:119`); `RoleController::permissions` filtering at `:331-334` | PG |
| EC-30 | A template mistake grants a financially decisive permission to `viewer` alongside a create permission in the same SoD group | `PermissionSodTemplateTest` fails at build time, naming the group, the template and the two keys. **Rev 3 — the baseline is recomputed and is eleven rows, not five** (B-5): `manager` violates seven of the eight declared groups (`RolesAndPermissionsSeeder.php:594,595,598,599,603,605,609`) and `accountant` four (`:829,832,836,841`); rev 2's five-row table also omitted `credit-note` entirely and mis-cited expenses (`:605`, not `:606`) and adjustments (`:603`, not `:602`). Three enum defects are fixed alongside it: `confirm` is in `isFinanciallyDecisive()` (rev 2), **`Pay` now exists and is decisive**, and a `legacy()` key declares `behavesAs` so `payments.pay-supplier` and `supplier-invoices.approve-invoice-first` are classifiable at all (§4.2.2). The rule is **hard for new resources, shrink-only ratchet over the eleven baselined combinations**, rising to **eighteen** once `lane/w-lot-a-1a` adds `general_manager` with `manager`'s grant set (§4.7). Retiring any of them is an owner decision, recorded as OQ-3 | §4.7 | SQLite — a new group violation fails; the eleven baselined ones pass; adding a twelfth baseline entry fails; `cashier`/`operator`/`viewer`/`technician` are asserted clean, so the test proves it is passable |
| EC-31 | An **offline POS operator** whose permissions were revoked server-side keeps acting on the device | The device continues on its last synced payload until it reconnects — an accepted offline residual, unchanged by this design. What changes: the operator's PIN stops being returned by `verifyPin`/`pinData`/`hasPins` the moment their membership or account goes inactive, because all three share `pinHolders()` (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-57`) | §4.10; `pinHolders()` unchanged in semantics | pos-vitest (offline continuation) + PG (all three surfaces exclude them) |
| EC-32 | A **service principal** attempts an interactive login, is impersonated, or is given a POS PIN **through any of the five PIN/verification writers** | 401 `SERVICE_PRINCIPAL_CANNOT_LOG_IN`, impersonation refused, 422 on every PIN and verification surface — and the PIN case is additionally impossible by the §5.1 CHECK. **Rev 2 adds three assertions rev 1 left implicit:** the login refusal happens **before** `Hash::check` (`apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:249-268`), which is load-bearing now that `password` is nullable; the same ordering holds on the POS PIN path (`apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:87-100`) and on the raw PIN writer (`:295-307`); and the CHECK constrains `status IN ('active','inactive')` as well as the PIN/verification/password/email columns. **Rev 3 completes the writer set (M-3)** — the row now covers all five: `UserController::setPosPin` **both arms** (clear `:706`, set `:739`), `PosAuthController::setupPin` (`:120-146`, the self-service path rev 2 missed entirely), the raw sync writer (`:295-307`), `EmailVerificationService::verifyEmail` (`apps/api/app/Modules/Identity/Application/Services/EmailVerificationService.php:68-71`) and central `SuperAdminController::verifyUserEmail` (`apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:505-528`). Each returns its **application-level 422**; the CHECK is the backstop, never the user-facing error | §4.1.1 (the five-writer table) + the §5.1 database CHECK | PG — one refusal assertion per writer (five), plus the login and impersonation refusals, plus the CHECK rejecting a direct insert of a service row with a `suspended` status, a PIN, a password or an email |
| **EC-32a** *(new, rev 3 — M-3)* | A **notification** whose recipients are selected by permission fires in a tenant that has a service account holding that permission | The service principal is **not** a recipient: all three permission-selected senders carry the human predicate — `SendEnrichmentNotificationListener` (`apps/api/app/Modules/Identity/Application/Listeners/SendEnrichmentNotificationListener.php:18-24`) and `BatchExpiryDailyCheckCommand` (`apps/api/app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php:215-225`), plus the token-scoped verification path | §4.1.1 notification inventory; `NotificationRecipientCensusTest` | PG — a service account granted `batches.view` and `enrichment.view` receives neither notification, and the census test fails on a fourth selector added without the predicate |
| **EC-32b** *(new, rev 3 — M-2)* | A **service account is created with its first membership and a location restriction** | One transaction creates the `users` row (`principal_kind = 'service'`, `password`/`email`/`pos_pin` null), its `user_company_memberships` row and its `allowed_location_ids` grant, and assigns its roles; a caller holding `service-accounts.create` but **not** `users.manage_location_access` is refused with the existing 403 location-grant contract before validation runs; a token issued to the account is then refused a write at a location outside the grant | `CreateServiceAccountRequest` + `ServiceAccountProvisioningService` (§4.1.2); `ValidateLocationAccess` unchanged | PG — `ServiceAccountProvisioningTest`, asserting the **rows** written (user, membership, location grant, role pivots) and the two refusals, not HTTP status alone |
| **EC-32c** *(new, rev 3 — B-4)* | **Wave 0b deploys its new keys to a tenant that already carries the W-LOT marker, with `LOT_ACTION_PERMISSIONS_ENFORCE=false`** | `tenants:seed RolesAndPermissionsSeeder` writes **nothing** on that tenant (`lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:44-51`, enforcement default at `lane/…/config/lot_action_permissions.php:5`), so wave 0b provisions through **`permissions:ensure`** instead: every 0b key exists afterwards, `admin` and each named template role holds it, no grant is revoked, the marker is untouched, and a second run reports `created=0 granted=0` | §4.3.5 | PG — a marked tenant and an unmarked tenant in one run; asserts the keys exist on **both**, that `manager` did **not** regain `batches.recall`, and that `provisioning_source` is unchanged |
| **EC-32d** *(new, rev 3 — B-8)* | A boot **fails** the fleet sync, the operator fixes the cause, and the **next boot succeeds** | The success arm overwrites `/var/run/autoerp/permissions-sync.status` with `permissions_sync=ok`, so `HealthCheckService::check()` reports `permissions_sync.healthy = true` and the aggregate returns to healthy. A container that recovers must not stay permanently unhealthy — rev 2 wrote only the failure arm | §4.3.3 entrypoint block; `HealthCheckService` (`apps/api/app/Modules/Admin/Application/Services/HealthCheckService.php:19-33`) | PG + a shell-level test of the entrypoint block — asserts the file content after each of the two boots and the JSON key on `GET /api/v1/admin/monitoring/health` |

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

`apps/api/app/Modules/Identity/Application/Listeners/ScopePermissionCacheToTenant.php`, `…/RestoreCentralPermissionCache.php`, `apps/api/app/Providers/TenancyServiceProvider.php`, `apps/api/tests/Traits/ProvisionsTenantDatabases.php`, `apps/api/tests/Feature/Identity/PermissionCacheTenantScopingTest.php`, `apps/api/tests/Architecture/RoutePermissionCoverageRatchetTest.php`, `apps/api/tests/Architecture/baselines/route-permission-coverage-baseline.json`, `apps/api/app/Http/Middleware/AllowSelfService.php`, `apps/api/bootstrap/app.php`, `apps/api/app/Modules/Identity/routes.php`, `apps/api/app/Modules/Promotion/Presentation/routes.php`, `apps/api/app/Modules/Uom/Presentation/routes.php`, `apps/api/app/Modules/Menu/Presentation/routes.php`, `apps/api/app/Modules/Coupon/Presentation/routes.php` **(coupons — rev 3 fixes rev 2's repeated `Promotion` path; the real file is `apps/api/app/Modules/Coupon/Presentation/routes.php:10-23`, minor 3)**, `apps/api/app/Modules/Inventory/Presentation/routes.php` (the counting-item route only), `apps/api/app/Http/Middleware/RequireAnyPermission.php`, `docs/conventions/03-AUTHORIZATION.md`, `.claude/commands/add-permissions.md`.

Note `apps/api/app/Modules/Identity/routes.php` is **not** in the lane's diff even though `RoleController.php` and `UserController.php` are — which is why 0a-4 edits the routes file and touches neither controller.

**`lane/t1-transfers-edge` and `lane/t2-receipt-spine` still create no overlap, but rev 2's "empty for both" is now stale and rev 3 re-measured both (minor 4).** Re-run at `5b658d681`:

- `git diff --stat dev...lane/t1-transfers-edge` (`86273346a`) → **empty**. T1 is an ancestor of `dev`.
- `git diff --stat dev...lane/t2-receipt-spine` (`b41183f50`) → **one file, `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json`, 206 insertions**.

**The gate's own citation has the two branches the wrong way round** — its minor 4 and its citation-audit row both attribute the new fixture to T1 and call T2 empty. The substance is accepted and applied (one of the two is no longer empty, and the spec's blanket claim needed re-measuring); the branch identity is corrected here from a direct re-run of both commands, recorded so a later gate does not "fix" it back. Either way the no-overlap conclusion survives: the file is a test fixture under `apps/api/tests/Fixtures/`, and wave 0a's verified file list contains no fixture. Rev 1's warning that T2 "will edit `apps/api/app/Modules/Inventory/Presentation/routes.php`" is a statement about future work, not a present overlap, and is re-checked at 0a's merge rather than assumed either way.

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

**Overlap check, re-verified in rev 2 against `git diff --name-only dev...lane/w-lot-a-1a` (66 files).** That lane modifies `RolesAndPermissionsSeeder.php`, `RoleController.php`, `UserController.php`, `usePermissions.ts`, `permissionsMap.generated.ts`, `generated.d.ts`, `RolesPage.tsx`, `routes/index.tsx`, `Sidebar.tsx`, the whole `BatchExpiry` module **including its `Presentation/routes.php`**, `docs/glossary.md`, and the tenant migrations directory. After the re-scope above, wave 0a touches **none** of them: 0a-4 edits `apps/api/app/Modules/Identity/routes.php` (not in the lane's diff) rather than `RoleController.php`; the BatchExpiry gating and the glossary rows have moved to 0b. `lane/t1-transfers-edge` (`86273346a`) has an **empty** diff against `dev`, and `lane/t2-receipt-spine` (`b41183f50`) contains **one test fixture**, `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json` — re-measured in rev 3, since rev 2's "empty for both" no longer holds (minor 4). Neither creates a present overlap: no fixture appears in wave 0a's verified file list. T2's future edit to `apps/api/app/Modules/Inventory/Presentation/routes.php` is re-checked at 0a's merge, and 0a's only touch of that file is the single counting-item route.

**Resulting wave-0a task list (7 items, closing 16 uncovered writes):** 0a-1 land S-1 · 0a-2 route-coverage ratchet + baseline · 0a-3 `authz.self` middleware, alias and allow-list · 0a-4 gate the four role/permission read routes · 0a-5 gate the writes needing no new key (Promotion ×4, Uom ×5, Menu ×3, Coupon ×3, counting-item ×1 — **BatchExpiry excluded**) · 0a-8 docs · 0a-9 `RequireAnyPermission` message.

Reviewer gates: `tenancy-authz-reviewer` (all), plus `imports-reviewer` is **not** needed. Deploy: 0a-1 requires `permission:cache-reset` at deploy (already at `apps/api/docker/entrypoint.sh:176`); nothing else needs a deploy step. Rollback: every item is a revert of a self-contained commit; 0a-2's baseline reverts with its test.

### Wave 0b — new permissions for the remaining ungated writes

**Entry condition: `lane/w-lot-a-1a` merged into local `dev`** (D7). Reason: 0b adds permission keys to `RolesAndPermissionsSeeder::permissionNames()` and role grants to `rolePermissionGrants()`, which is the file that lane rewrites; landing both in parallel guarantees a conflict on an 871-line array and, worse, on the marked-tenant branch it introduces at `lane/w-lot-a-1a:apps/api/database/seeders/RolesAndPermissionsSeeder.php:44-51` *(rev 3 corrects rev 2's `:568`, which is the current tree's old unguarded `syncPermissions()` call, minor 5)*. **That branch is also why 0b cannot deploy through `tenants:seed` at all — see §4.3.5 and EC-32c (B-4).**

Tasks: declare and gate `services.*` (3 keys) and `service-categories.*` (3), `channels.*` (4), `categories.*` (4), `progression.*` (2), `purchase-hub.orders.create` (1), `companies.create` (1), and **`credit-notes.cancel`** (0b-1, first). **Rev 2 adds four items relocated from wave 0a (B-9)**: **0b-5** gate the two `BatchExpiry` writes (`DELETE /api/v1/batches/{uuid}` → `batches.delete`, `POST /api/v1/batches/{uuid}/recall` → `batches.recall`) **on top of** the lane's rewritten routes file and its `BatchActionAccess` middleware, not instead of them; **0b-6** delete `PermissionSeeder.php` and its `ProductionSeeder.php:75` caller; **0b-7** fix the masking test (`apps/api/tests/Feature/Document/RefundResidualTenantIsolationTest.php:136-142`) **in the same commit as 0b-1**, so the suite is never knowingly red on a merged commit; **0b-8** the glossary rows, added on top of the lane's General-manager row. **Rev 3 adds a ninth (B-4): 0b-9** — the `permissions:ensure` command (§4.3.5), which is how every 0b key actually reaches a marked tenant, and which is deleted again in wave 1. All keys land with `admin`-only template defaults except `credit-notes.cancel` (`manager`, `general_manager` — matching its siblings) and `services.*`/`service-categories.*` (`manager`, `general_manager`, because the FE alias map they replace already exposed them to managers, and removing a manager's ability to edit a service would be a functional regression, not a fix).

`POST /api/v1/companies` is the sharpest one: today `CreateCompanyRequest::authorize()` returns `true` and the controller then self-grants `MembershipRole::Owner` to the caller (`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:151`), so any authenticated user in any of the seven seeded roles can create a company and become its owner. It gains `can:companies.create`, `admin`-only.

Files: the seeder array, each module's `routes.php`, `apps/web/src/hooks/permissionsMap.generated.ts` (regenerated), the label JSONs, **plus the new `apps/api/app/Console/Commands/EnsurePermissions.php` (0b-9, §4.3.5)**. Reviewers: `tenancy-authz-reviewer` + `frontend-conventions-reviewer`.

**Deploy (rewritten in rev 3 — B-4).** The T-2/T-3 sequence beginning `tenants:seed RolesAndPermissionsSeeder` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:420`) **does not work after `lane/w-lot-a-1a` merges**: on a marked tenant with `LOT_ACTION_PERMISSIONS_ENFORCE=false` — the shipped default (`lane/w-lot-a-1a:apps/api/config/lot_action_permissions.php:5`) — that seeder takes its preservation branch and writes nothing (`lane/…/RolesAndPermissionsSeeder.php:44-51`), so the 0b keys would never reach the tenant while its routes are already gated on them: 403 for everyone including `admin`. The deploy is therefore, in order:

1. `php artisan permissions:ensure --keys=… --grant-to=…` across every tenant, with the 0b key list and the template roles each key is declared for (§4.3.5) — additive, marker-safe, idempotent;
2. `php artisan permission:cache-reset`;
3. `php artisan permissions:export-frontend-map`.

`tenants:seed RolesAndPermissionsSeeder` is **not** in this sequence at all. Verification: the `PERMISSIONS-ENSURE` marker per tenant shows `created`/`granted` on the first run and `created=0 granted=0` on a re-run; EC-32c is the test. Rollback: revert; the added `permissions` rows are inert once the routes no longer reference them, and `permissions:prune-orphans` is not run.

### Wave 1 — catalogue as code

**Entry condition: wave 0a and 0b merged.** Deliverables: `PermissionVerb` (32 cases, `Close` and `Pay` included), `PermissionDefinition`, `PermissionManifest`, `PermissionRegistry`, ~30 per-module manifests, per-module enums, `PermissionRenameMap`, **`TemplateMigration` + `TemplateMigration001WLotA1a` with its frozen inputs (§4.3.0a rule 1a)**, **`RoleMarkerRepository` (rule 1b)**, `PermissionSyncService` (`sync` **and** `syncRole`), `permissions:sync`, **`permissions:sync-fleet`** (§4.3.3), **`permissions:scaffold`** (§3.3), `permissions:export-label-skeleton`, **`LegacyRoleBaseline`** and the adoption pass (§4.3.0a), the `roles` migration (§5.2), **the `customised_at` writer in `RoleController::update`** (moved into wave 1 by B-2 — the column and its writer must not ship a wave apart), the seeder shim (§4.3.4), the entrypoint change (both status arms, `/var/run/autoerp` created) plus the `permissions_sync` check on `HealthCheckService` (§4.3.3), and the guards `PermissionRegistryConsistencyTest` (including the `manage`/CRUD rule with its single `settings` grandfather), `PermissionEnumManifestParityTest`, `PermissionRegistryLabelCoverageTest`, `PermissionRegistryCoverageTest`, `PermissionSodTemplateTest` (hard half + the eleven-entry shrink-only baseline, eighteen after the lane — §4.7), `PermissionCatalogueParityTest` (transitional), `RoutePermissionLiteralTest` (accepting constant enum expressions, §4.5.1), `ScaffoldPermissionCommandTest` (including the two-clean-worktree byte-identity case, §3.3), `TemplateMigrationFrozenInputTest`, `LegacyRoleBaselineParityTest`, `EnforceTokenScopeCoverageTest`'s sync-side sibling `PermissionsSyncSupersedesEnsureTest`, **and the deletion of `EnsurePermissions.php` and its 0b deploy rows in the same commit that lands `permissions:sync` (§4.3.5)**, plus the two static guards — `ForbidPermissionStringLiteral` (both error classes) and **`ForbidRoleNameAuthorization`** with its shrink-only baseline and the ESLint companion (§4.1.3a).

Overlap: this wave **owns** `RolesAndPermissionsSeeder.php` end to end. It cannot run concurrently with any lane that edits that file. It must be sequenced after `lane/w-lot-a-1a`'s five-push staging protocol has completed, not merely after its merge, because that protocol asserts pre/post role-permission snapshots on staging boots (plan `:4169`) and a registry-driven sync running in between would invalidate the comparison.

Deploy: `tenants:migrate-rolling` (the `roles` columns), then **`php artisan permissions:sync-fleet`** (never `tenants:run permissions:sync`, and there is no `--force` — B-5), then `permission:cache-reset`, then `permissions:export-frontend-map`. **New deploy-checklist row**: *after deploy, confirm `GET /api/v1/admin/monitoring/health` reports `permissions_sync.healthy = true` (the authenticated super-admin endpoint at `apps/api/routes/api.php:83` — the public `/v1/health` is unchanged and still reports only `{status, timestamp}`); if it reports false, grep the Dokploy container log for the `PERMISSIONS-SYNC-FLEET` aggregate line, which names every blocked or failed tenant id, and re-run `php artisan permissions:sync-fleet --tenants=…` for those tenants before considering the deploy complete.* **Staging soak: one week with `SYNC_PERMISSIONS_ON_BOOT=true`**, comparing each tenant's role-permission snapshot before and after every deploy and requiring **zero** unintended diffs — this is the direct replacement for the behaviour that caused I-20, so it gets the evidence bar that lane established. Rollback: the migration's `down()` drops the four columns (refusing while any `template_key` is set, mirroring W-LOT-A-1a's `down()` contract at plan `:1234`); the seeder shim reverts to the array in the same revert commit; `permissions:sync` is additive, so a rollback leaves extra permission rows, which are inert.

### Wave 2 — role management, principals and tokens

**Entry condition: wave 1 merged and soaked.** Two lanes, runnable in parallel within the cap.

**Additional entry condition on 2b, from B-1 (rev 2): no scoped token may be issuable until the role-name authorization sites are converted.** `service-accounts.issue-token` stays unrouted until `ScopedTokenIssuanceEntryConditionTest` is green — i.e. until 2a has converted `CreateDraftCountingRequest` (`:35`), the six `InventoryCountingController` bypasses (`:782,866,905,975,1297,1494`) and `DiscountPermissionResolver::isAdmin()` (`:39`) to permission checks (§4.1.3a (b)). Issuing a narrowing token while a role-name idiom can bypass the narrowing would ship the intersection guarantee as a claim rather than a property.

- **2a — role management hardening**: `roles.is_system` consumption; the single **`LastAdminFloor`** domain service wired into all nine writers of §4.6.3 (including `UserController::update`'s `syncRoles`) plus `LastAdminFloorWriterCensusTest`; F-1; D4 read gates **with the payload shaping of §4.6.4**; `RoleCreated/RoleUpdated/RoleDeleted` extending `DomainEvent` with their `identity.role.*` names, registered in `DomainEventSubscriber`; `(principal_id, token_id)` attribution added once in `AuditService::record()`; denial events **on `audit_events`** with the two-key Redis dedup and its Redis integration test; **`EffectivePermissionResolver`** with `forSubject`/`forTarget`; `GET /users/{id}/effective-permissions[?token_id=]`; the effective-permissions panel; `RolesPage` grouped by registry module with the template-version banner and *Re-apply template*; **and the conversion of the eight role-name authorization sites** that gates 2b.
- **2b — principals and tokens**: `PrincipalKind`, the `users` migration (§5.1 — including making `password` nullable and the full service CHECK), the `principal_kind` census edits across every writer listed in §4.1.1, the six `service-accounts.*` permissions and their routes, **`CreateServiceAccountRequest` + `ServiceAccountProvisioningService` + `PUT /service-accounts/{id}/memberships` (§4.1.2)**, the seat exclusion **across all six counters** plus the `max_service_accounts` constant, its per-tier defaults and `canAddServiceAccount()` (§4.1.7), **`EnforceTokenScope` attached to the global `api` group with `EnforceTokenScopeCoverageTest` and `EnforceTokenScopeNoOpTest` (§4.1.4)**, the generalised `tokenScopePermissionNames()`, the `tenant:` claim at issuance, `TokenScopeData` on `/auth/me`, the `X-Company-Id` contract, **`MembershipRevocationService`** and the queued `RevokePrincipalTokens` retry, and the FE service-account + token screens.

2b depends on 2a only for the audit-event plumbing (`actorTokenId`), so 2a merges first. The FE deletions of §4.5.4 (`uiAliasPermissions.ts`, the fallback) ride with **2a**, because they depend on wave 0b having made `services.*` real, not on tokens.

Reviewers: `tenancy-authz-reviewer` (both, mandatory) + `frontend-conventions-reviewer` (both). Deploy: `tenants:migrate-rolling`, `permissions:sync`, `permission:cache-reset`, `permissions:export-frontend-map`.

**Rollback — `down()` restores the PRIOR schema, which is four operations, not one (rev 3, M-7).** Rev 2 said only "the three `users` columns are dropped after service tokens are revoked", which leaves service rows with `password IS NULL` and `email IS NULL` in a schema that no longer identifies them as services — and makes restoring `password NOT NULL` impossible while they remain. The migration's `down()` is therefore, in order:

1. **Refuse** while any `personal_access_tokens` row belongs to a `principal_kind = 'service'` principal — the operator revokes them first (the `DELETE /service-accounts/{id}/tokens/{tokenId}` surface, or `permissions:…`-independent `sanctum:prune-expired`); this is rev 2's condition, kept.
2. **Delete every `principal_kind = 'service'` `users` row** and its `user_company_memberships` and `model_has_roles` rows. **This is data loss and is stated as such**: a rollback of wave 2b destroys every service account the tenant created, because there is no representation for one in the pre-wave schema — a machine identity with no password and no email is not a user the old `users` table can hold. The `down()` logs each deleted principal id and writes an `AuditEvent` per deletion before removing it, so the rollback is reconstructible from the audit chain even though the rows are gone. An operator who wants them back re-runs `up()` and re-creates them; the tokens were already revoked at step 1, so nothing continues to authenticate.
3. **Drop the CHECK**, then drop `principal_kind`, `principal_description`, `created_by_user_id`.
4. **Restore `password NOT NULL`** — safe only because step 2 removed every row that could hold a null. Without step 2 this statement fails and the whole `down()` aborts, which is the loud failure the ordering exists to avoid.

The `roles` migration's `down()` (wave 1) keeps its rev-2 contract and gains the same explicitness: it drops the two partial uniques and the three CHECKs, then the four columns, and **refuses while any row has `template_key IS NOT NULL`** (mirroring W-LOT-A-1a's `down()` contract at plan `:1234`) — so a rollback after a sync is a deliberate act that first requires clearing adoption, and never silently discards which roles were system roles.

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
| **D2** | *"What happens to the 7 seeded roles when a deploy adds a permission?"* | Odoo: *"Module upgrade re-applies group definitions unless `noupdate`"*; ERPNext: *"`bench migrate` re-syncs standard DocPerms; custom perms kept separately"*; Dolibarr: *"Module re-activation re-inserts rights"* | *"**Template-delta**: seeded roles track a template version; additive deltas applied on sync unless the tenant has customised that role (flag set on first tenant edit) — then admin-only + notice in the Roles UI"* | §4.3.2 steps 6–7, §4.6.6, §5.2, EC-9/EC-10 |
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

**OQ-3 (replacement) — Should any of the eleven baselined SoD combinations be retired?**
*Context (recomputed in rev 3 — the question rev 2 put was against an incomplete table).* SoD-1 (§4.7) is a hard rule for new resources and a shrink-only ratchet over **eleven** combinations the shipped templates already carry, across two roles rather than one: `manager` violates seven of the eight declared groups — `invoices.create` + `.post` (`:598`), `credit-notes.create` + `.post` (`:599`), `purchase-orders.create` + `.confirm` (`:594`), `supplier-invoices.create-pending` + `.approve-invoice-first` (`:595`), `payments.create` + `.pay-supplier`/`.allocate`/`.refund` (`:609`), `expenses.create` + `.post`/`.pay` (`:605`), `inventory.adjustments.create` + `.post` (`:603`) — and **`accountant`** violates four: `supplier-invoice` (`:829`), `expense` (`:832`), `payment` (`:836`) and `journal` — `journal.create` + `journal.post` (`:841`), all in `apps/api/database/seeders/RolesAndPermissionsSeeder.php`. The count becomes **eighteen** after `lane/w-lot-a-1a`, because `general_manager` inherits `manager`'s set (lane seeder `:90-91`). Removing any of them narrows what a real manager or accountant can do on day one.
*Benchmark.* **Odoo** ships create and approve as distinct groups on purchases (the Purchase Order Double Approval group) but leaves a single-user small deployment free to hold both — the separation is a *shipped default on one flow*, not a runtime rule ([approvals](https://odoo-users.readthedocs.io/en/latest/purchase/purchases/rfq/approvals.html)). **ERPNext** gates each workflow transition per role, so separation is expressed per document type and is opt-in through Workflow ([workflow](https://docs.frappe.io/erpnext/workflow)). **Dolibarr** states it as convention only: do not grant validate to the group that has create. **None of the three implements NIST INCITS 359 SSD/DSD generically**, and none forbids the combination in a shipped default across the board. The domain norm is therefore: separate create from approve **where the flow is financially decisive and the tenant is large enough to staff it**, and let small tenants collapse the roles.
*Recommended default:* **retire three from `manager`, keep the rest, and change nothing on `accountant`.** The three are `invoices.create` + `.post`, `credit-notes.create` + `.post` and `payments.create` + `.refund` — the ones where the benchmark's own shipped defaults separate (a document becoming a fiscal fact, and money leaving) and where a two-person tenant can still operate by granting the second half to `general_manager`, which exists precisely to be the second pair of eyes. The rest stay with `manager` (`purchase-orders.confirm`, `supplier-invoices.approve-invoice-first`, `expenses.post`/`.pay`, `inventory.adjustments.post`), because a five-person pharmacy legitimately has one person who raises and confirms a PO — and forcing an admin into that loop is how operators end up sharing the admin login, which is a worse outcome than the SoD gap. **`accountant`'s four are recommended untouched**: an accountant who cannot post the journal they wrote is not an accountant, and in every reference system the bookkeeping role holds both halves of the journal by design; separation there is a four-eyes review at period close, not a permission split. **This is an owner decision and the design ships the baseline unchanged until it is made**; the ratchet guarantees the number can only fall. Applying the recommendation would take `SOD_BASELINE_CEILING` from 18 to 15 (three retired from `manager`, and the same three from `general_manager`, which mirrors it — so **12**, if the owner rules the mirror follows, which is the second half of the question).

**OQ-4 — After wave 1, does `SYNC_PERMISSIONS_ON_BOOT` default to `true` in production?**
*Benchmark.* Both reference systems that solved this sync it automatically: Odoo re-applies `ir.model.access.csv` on every install **and** upgrade, and ERPNext syncs DocPerms on every `bench migrate` — neither asks an operator to opt in, because a permission that exists in code and not in the database is a broken feature ([security tutorial](https://www.odoo.com/documentation/19.0/developer/tutorials/server_framework_101/04_securityintro.html), [bench migrate](https://docs.frappe.io/framework/user/en/bench/reference/migrate)). Dolibarr is the outlier that requires a human to re-activate the module, and is the one with the worst drift story.
*Preconditions (rev 3 adds two the gate named).* The question is safe to ask only once **three** things hold, and all three are now specified: **B-5** — the fleet runner exits non-zero on any blocked or failed tenant; **B-8** — the status file is written on both arms and read by `HealthCheckService`, so a recovered boot clears the state and a failed one is visible on the admin health endpoint; and **B-2** — the sync's inputs are defined and frozen, since defaulting boot sync on while step 5's arguments were undeclared would have meant a deploy running an operation nobody could describe. **B-4** is a wave-0b concern rather than a precondition here: `permissions:ensure` is deleted before wave 1 ships, so the flag never governs it. Without those three, a partial fleet sync would have been indistinguishable from a clean one (`apps/api/docker/entrypoint.sh:150-169` continues past errors); with them, "on by default" becomes a decision about convenience rather than about visibility.
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

## 11. Change log rev 1 → rev 2 (Codex spec gate r1 fix round) — condensed

Register: `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r1.md` (9 BLOCKER / 9 MAJOR / 5 minor / 1 citation audit, CHANGES-REQUIRED). **All 9 BLOCKERs, all 9 MAJORs and all 5 minors were closed in rev 2**, each re-verified against the tree before being applied; the per-finding table with its `verified at` lines and anchors is preserved verbatim in that register alongside rev 2's own dispositions, and this section is condensed in rev 3 so §12 is the register a gate reads. What rev 2 changed, one line each: **B-1** the §4.1.3a idiom table, `ForbidRoleNameAuthorization` + ESLint companion with an exact baseline, conversion made an entry condition of token issuance, `EffectivePermissionResolver::forSubject`/`forTarget`. **B-2** the delta adopted as template migration #1 with `LegacyRoleBaseline` adoption and the `customised_at` writer moved into wave 1. **B-3** the General-manager glossary row adopted verbatim and EC-15 reversed to a 422 refusal. **B-4** the single `LastAdminFloor` service, nine-writer table, membership clause and deterministic lock order. **B-5** `permissions:sync-fleet` replacing `tenants:run`, `--force` removed. **B-6** `PermissionVerb::Close`, the `manage`/CRUD invariant with its `settings` grandfather, AI-1 restated with `permissions:scaffold`. **B-7** the `authz.self` exact allow-list plus structural check, `TOMBSTONE` classification. **B-8** denials on `audit_events`, no `security_events` table, old OQ-3 deleted. **B-9** wave 0a re-scoped, four items moved to 0b. **M-1** the principal invariants, nullable `password`, null `email`, kind check before `Hash::check`, seat ruling. **M-2** abilities are TEXT, the real 30-day TTL, `MembershipRevocationService`, EC-22 withdrawn. **M-3** D4 payload shaping on `userRoles`. **M-4** events extending `DomainEvent` with `identity.role.*` names. **M-5** the unknown-literal error class and `RoutePermissionLiteralTest`. **M-6** the convention-09/10/11 corrections. **M-7** thirteen edge-case rows rewritten, seven added. **M-8** `DB::afterCommit`. **M-9** the single authoritative 22-key deprecation set.

**One citation-audit row was partially rejected in rev 2 and the rejection stands:** the register called *"email remains unique using original users migration `:34`"* stale. The substance was accepted (§5.1 cites the partial unique at `2026_03_23_000001_make_user_email_nullable.php:14-23`), but the line number was correct for the constraint it named — `apps/api/database/migrations/tenant/2025_11_30_000003_create_users_table.php:34` is `$table->unique(['tenant_id', 'email']);`. Gate r2 re-checked this and **agreed** (`…-gate-r2.md:38`, "REJECTED-correctly").

## 12. Change log rev 2 → rev 3 (Codex spec gate r2 fix round)

Register: `docs/superpowers/reviews/2026-09-10-rbac-spec-codex-gate-r2.md` (8 BLOCKER / 9 MAJOR / 8 minor / 1 citation audit, verdict CHANGES-REQUIRED), read at `83da1248c`; prompt at `docs/handoff/CODEX-PROMPT-spec-gate-RBAC-round-2-2026-09-10.md`. Every finding below was **re-verified against the tree at `5b658d681` before being applied** — the "verified at" column names the line actually read, and every lane citation was re-read from `lane/w-lot-a-1a@04e60530c`. Orchestrator rulings are marked **[R]** and are binding.

| ID | Disposition | Verified at | Closed at anchor |
|---|---|---|---|
| **B-1** `EnforceTokenScope` ordered but never attached | **CLOSED [R]** | `bootstrap/app.php:133-153` (global `api` group carries neither token middleware), `:174-188` (priority list); `Identity/routes.php:39-57`; `routes/api.php:50-54`; `AuthLifecycleTest.php` | §4.1.4 — RULING: **attached** to the global `api` group *and* kept in the priority list after `Authenticate`; explicit no-op contract with `EnforceTokenScopeNoOpTest`; **`EnforceTokenScopeCoverageTest`** asserts every `auth:sanctum` route resolves it, no allow-list; the service-account group lists it explicitly (§4.1.2); `/auth/me` therefore covered; EC-16a re-anchored |
| **B-2** template migration #1 not executable without violating D2 | **CLOSED [R]** | lane delta `:21` (ctor), `:27-30` (signature), `:56,65-73,74-85,86-90,91,93-97,139-159`; lane seeder `:36,64-70,80-83,86-97` | §4.3.0a rules **1a/1b/1c** — `TemplateMigration001WLotA1a` holding **frozen** `PERMISSION_NAMES`/`ROLE_GRANTS` copied from lane seeder `:80-83`/`:86-97`, with `TemplateMigrationFrozenInputTest`; `PermissionSyncService` **constructor-injects** the migration collection (§4.3.2) and reads the marker through the new public **`RoleMarkerRepository::tenantCarriesMarker()`**; steps renumbered **1–10** with create → migrations → adopt → grants → admin → cache; adoption now writes the four template columns on migration-created roles; §4.3 invariant (v) states migrations never receive registry lists |
| **B-3** legacy baseline cannot classify `general_manager` | **CLOSED [R]** | lane seeder `:90-91`; lane migration `:34-48`; lane delta `:61-63` | §4.3.0a — RULING: **eighth version-0 snapshot** = the lane's `general_manager` grant set at its merge SHA (`:91` — revised `manager` from `:90` plus `batches.recall` and `treasury.manage_all_locations`), stored beside the seven, `LegacyRoleBaselineParityTest` pins it to the frozen migration input; adoption rule unchanged; **EC-9a split** into EC-9a (customised NULL-team **unmarked**) and **EC-9b** (customised **marked tenant** role, `tenant_id NOT NULL`) |
| **B-4** wave 0b cannot provision its keys on marked tenants | **CLOSED [R]** | lane config `:5` (`enforce` default false); lane seeder `:44-51` (marked branch writes nothing); `ProductionSeeder.php:68-76` | **§4.3.5** — RULING: new additive **`permissions:ensure {--keys=*} {--grant-to=*}`**, create-and-grant only, never revokes, marker-safe, idempotent, logged; explicitly a **pre-wave-1 stopgap superseded by `permissions:sync`** and deleted in wave 1 with `PermissionsSyncSupersedesEnsureTest`; wave-0b deploy rewritten to drop `tenants:seed` entirely; task **0b-9** added; **EC-32c** |
| **B-5** SoD guard red by construction | **CLOSED [R]** | seeder `:594,595,598,599,603,605,609,613` (manager), `:697,698,705` (cashier), `:799,801,802,805,809` (operator), `:829,832,836,841` (accountant); lane seeder `:90-91` | §4.2.2 — **`PermissionVerb::Pay`** added (32 cases) and financially decisive; `$verb` made **nullable** with `legacy(…, ?PermissionVerb $behavesAs)` so `payments.pay-supplier` and `supplier-invoices.approve-invoice-first` are classifiable. §4.7 — RULING: **eight** SoD groups only (orders/deliveries/income/work-orders are operational, with the Odoo double-validation / ERPNext optional-workflow benchmark recorded); baseline **recomputed from the seeder** to **eleven** rows across `manager` and `accountant`, **eighteen** after the lane; `cashier`/`operator`/`viewer`/`technician` asserted clean; EC-30 and OQ-3 updated; G8 cell re-cited |
| **B-6** route literal guard rejects the required enum syntax | **CLOSED [R]** | `phpstan.neon:5-8,33-47`; `ForbidFloatCastOnDecimalProperty.php:40` | §4.5.1 — `RoutePermissionLiteralTest` **statically evaluates** constant expressions (`'can:'.Enum::Case->value`, `implode`, arrays of such) and **accepts** them via `ConstExprEvaluator` + an AST enum-case fallback; only non-constant expressions are reported, with the message quoted |
| **B-7** `roles_template_key_unique` breaks compatibility mode | **CLOSED [R]** | `create_permission_tables.php:30,43-47` (roles unique is `(tenant_id, name, guard_name)`) | §5.2 — **REVERSES rev 2**: `unique(tenant_id, template_key)` **plus** a partial unique on `template_key` `WHERE tenant_id IS NULL`; §4.3.3 states the compatibility-mode consequence; **EC-21 moved to PG with two tenants**, **EC-21b** added for the NULL-team collision; §6's convention-09 sentence updated |
| **B-8** boot-failure visibility not durable | **CLOSED [R]** | `entrypoint.sh:19-23,74-79,150-170,176`; `MonitoringController.php:27-45`; `HealthCheckService.php:17-33`; `routes/api.php:27,57-58,83` | §4.3.3 — entrypoint creates `/var/run/autoerp`; **success arm overwrites** the file with `permissions_sync=ok`; **`HealthCheckService` gains a fifth `permissions_sync` check** exposed on the authenticated `/v1/admin/monitoring/health`; **public `/v1/health` unchanged**; deploy checklist reads the admin endpoint and names the `PERMISSIONS-SYNC-FLEET` Dokploy log line; **EC-32d**; OQ-4 preconditions restated |
| **M-1** human-seat exclusion only partially applied | **CLOSED [R]** | `PlanLimitsService.php:83-85`; `PlanEnforcementService.php:162-176,290-305,426-444`; `SuperAdminController.php:88-97`; `TenantFleetStatsService.php:36-51`; `PlanLimits.php:19-35,161-173` | §4.1.7 — the predicate lands in **all six** counters (table with each site and what it drives), rev 2's wrong `:325-330` enforcement citation dropped; `PlanSeatCensusTest`; `max_service_accounts` becomes a `PlanLimits` constant in **every tier default** with a new `canAddServiceAccount()` enforcement point |
| **M-2** service-account creation has no membership/write contract | **CLOSED** | `UserController.php:217-258`; `CreateUserRequest.php:27-29,61-76`; `UpdateUserRequest.php:27-29,44-63` | §4.1.2 — `CreateServiceAccountRequest` rules quoted (name, memberships with `allowed_location_ids`, roles via `AssignableRole`), the `users.manage_location_access` relationship decided, **`ServiceAccountProvisioningService`** named as the one writer with a census test, `UserController::update` refuses a service target, `PUT /service-accounts/{id}/memberships` added, `$fillable` listed; **EC-32b** |
| **M-3** writer/notification/PIN census incomplete | **CLOSED** | `EmailVerificationService.php:27-41,68-71`; `SuperAdminController.php:505-528`; `UserController.php:703-745`; `PosAuthController.php:120-146,295-307`; `User.php:81-97,115-126`; `SendEnrichmentNotificationListener.php:18-24`; `BatchExpiryDailyCheckCommand.php:215-225` | §4.1.1 — four omitted writers added **each with its required change** (both `setPosPin` arms, `setupPin`, both verification writers); a three-row **notification inventory** with the human predicate and `NotificationRecipientCensusTest`; `$fillable` + `casts()` change stated with `UserFillableContractTest`; EC-32 rewritten to cover all five PIN/verification writers; **EC-32a** |
| **M-4** audit attribution directed into the wrong channel | **CLOSED** | `AuditService.php:65-82` (the `array_filter` is the **`attributes:`** argument); `AuditEvent.php:44-64,90-100,125-136` (only `$metadata` is JSON-encoded at `:133`) | §4.6.5 — the pair is **merged into the `$metadata` array**, not the attributes filter (rev 2's instruction would have written it nowhere, silently, because neither name is `$fillable`); `AuditAttributionTest` now asserts a **read-back** off the persisted column; `AuthorizationDenied` given an explicit immutable `getEventName()` and a V2 evolution rule |
| **M-5** denial dedup can lose counts under concurrency | **CLOSED** | — | §4.6.5 — read-and-clear becomes **one atomic server-side operation** (Lua script quoted; `MULTI`/`EXEC` and `GETDEL` named as equivalents); `AuthorizationDenialDedupTest` gains a **two-connection interleaved-`INCR` case**; EC-28 updated |
| **M-6** deprecation naming and custom-role writes contradictory | **CLOSED [R]** | rename map §4.9.2; deprecation set §4.9.3; `RolesAndPermissionsSeeder.php:325-332` | §4.9.3 — the 22-key list is spelled **post-rename** (`catalog-cart.*`), the manifest declares only the new name, `PermissionRegistryConsistencyTest::no_manifest_declares_a_rename_source_key`, §4.9.1 ownership row corrected, `reports.view` contradiction removed. **RULING** in §4.3.2 step 3 + the new §4.3 invariant block: an in-place rename writes **no role**; a deprecation **with** `replacedBy` grants the successor to custom roles too — the single, audited exception, emitting `RoleUpdated`; EC-5 aligned |
| **M-7** wave-2 rollback cannot restore the prior schema | **CLOSED** | `create_users_table.php:22`; §5.1 | §8 wave 2 — `down()` is four ordered operations: refuse while service tokens exist → **delete every service row** (data loss stated explicitly, each deletion audited first) → drop CHECK and columns → restore `password NOT NULL`; the wave-1 `roles` `down()` given the same explicitness |
| **M-8** scaffold write contract inconsistent and nondeterministic | **CLOSED** | §3.3 table; `scripts/preflight.sh:136-149` | §3.3 — "three artifacts" corrected to **six files across three kinds**, every path repo-relative; four determinism rules (sorted key insertion, fixed `json_encode` flags + trailing newline, enum case appended before the closing brace then re-sorted, duplicate detection + temp-file-then-rename); `ScaffoldPermissionCommandTest` gains a **two-clean-worktree byte-identity** case |
| **M-9** edge-case register disposition | **CLOSED** | per row | §7 — every row the gate assessed is fixed at its own anchor (EC-5, EC-9a/9b, EC-16/16a, EC-21/21b, EC-21a, EC-28, EC-30, EC-32/32a); **all five "missing scenarios" added**: EC-32c (0b on a marked tenant), EC-21 (two-tenant compatibility adoption), EC-32d (successful boot clears health state), EC-28 (concurrent denial increments, folded in), EC-32b (service account with first membership + location) |
| minor 1 CSV counts 642 / 68 | CLOSED | recomputed with a CSV parser: 1054 rows = 642 MIDDLEWARE + 142 AUTH_ONLY + 130 CONTROLLER + 68 SUPERADMIN_ONLY + 46 FORMREQUEST + 18 PUBLIC + 8 POLICY; uncovered 326 = 177 W / 149 R | §4.4.3 — `642` replaces `638`; the arithmetic now closes exactly with no unstated transformation |
| minor 2 wave 0a closes sixteen, not fourteen | CLOSED | 0a-5's own list: 4+5+3+3+1 | §4.4.3 — **sixteen**, and `167 − 16 = 151` matches the ceiling rev 2 already printed; task-list header updated |
| minor 3 Coupon path typo | CLOSED | `apps/api/app/Modules/Coupon/Presentation/routes.php:10-23` | §8 verified 0a file list |
| minor 4 T1/T2 diffs | **CLOSED, branch identity REJECTED** | re-run at `5b658d681`: `dev...lane/t1-transfers-edge` (`86273346a`) **empty**; `dev...lane/t2-receipt-spine` (`b41183f50`) = `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json`, 206 insertions | §8 — both re-measured and stated; the gate has the two branches **the wrong way round** (it attributes the fixture to T1 and calls T2 empty) — see the rejection note below |
| minor 5 marked-tenant branch cited as `:568` | CLOSED | lane seeder `:44-51` | §8 wave-0b entry condition |
| minor 6 `tenant:migrate-rolling` | CLOSED | `RollingTenantMigrationCommand.php:48` | §5 — plural |
| minor 7 "sync never writes `name`" | CLOSED | §4.3.2 step 7 creates roles | §5.2 — restated precisely: never on an **existing** role; only on a row sync creates itself, never `general_manager`, never a custom role |
| minor 8 `template_version` permitted without `template_key` | CLOSED | — | §5.2 — third CHECK `template_version IS NULL OR template_key IS NOT NULL` |
| Citation audit | CLOSED, **one row rejected** | see below | seeder `:606 → :605`, `:602 → :603`, `:599` added; `PlanEnforcementService :325-330 → :162-176,290-305,426-444`; W-LOT marker `:568 → lane :44-51` and the private-method fact; EC-9a impossibility; Coupon path; `tenant:` → `tenants:migrate-rolling`; public-health claim withdrawn; 0a count |

**Rejected — one row, with evidence.** The register's **minor 4** and its matching citation-audit row state that *"`lane/t1-transfers-edge` is no longer empty … it contains `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json:1`. That fixture does not overlap wave 0a … `lane/t2-receipt-spine` remains empty."* Re-run at `5b658d681`, both commands disagree with that assignment: `git diff --stat dev...lane/t1-transfers-edge` (`86273346a`) is **empty**, and `git diff --stat dev...lane/t2-receipt-spine` (`b41183f50`) is the **one file, 206 insertions**. The gate's *substance* is accepted and applied — rev 2's "empty for both" was stale and both lanes are now re-measured and stated individually — but the **branch identity is reversed**, and the no-overlap conclusion holds for either reading because wave 0a's verified file list contains no test fixture. Recorded so a later gate does not restore the reversal.

**Nothing else was rejected.** All eight BLOCKERs, all nine MAJORs, all eight minors and every other citation row were reproduced against the tree before being applied. The register's own "Rejected false positives" and "Preserve" lists were honoured in full: the **326 / 177 / 149 → 167 / 148** ratchet arithmetic (independently recomputed above and unchanged), the live-router feasibility of the ratchet and of the `authz.self` allow-list, Direction B, D1–D8, the `forSubject`/`forTarget` split, the unnarrowed POS PIN payload and the exclusion of service principals from PIN populations, the W-LOT marker and exact general-manager grant set, the equality-based adoption ruling, the single `LastAdminFloor` with its `users.id`-ascending lock order, the fleet runner's per-tenant outcomes and absent `--force`, `admin = activeKeys()`, central Sanctum storage with per-row expiry override and no central migration, and the generated TS union under rule 7 — none is reopened or weakened by rev 3.

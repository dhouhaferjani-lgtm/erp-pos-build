# Roles & permissions — audit synthesis, benchmark gap analysis and decision sheet

> Lane: roles & permissions management. Date: 2026-09-09. Base: local `dev` at `e85641a66` (HEAD moved to `bd986f898` during the audit; nothing permission-related changed in between except lane T-1 gate merges). Status: **AUDIT COMPLETE — awaiting owner direction. No implementation has started.**
>
> Sources: `01-backend-permission-model.md`, `02-route-enforcement-sweep.md` (+ `.csv`, 1054 routes), `03-frontend-pos-consumption.md`, `04-history-rulings-inflight.md`, `05-benchmark-odoo-erpnext-dolibarr.md`. Every finding below cites the report section that carries the `path:line` evidence.

## 0. Verification status

An Opus `tenancy-authz-reviewer` pass re-verified the twelve headline claims (C1–C12) against code before this document was put in front of the owner. Verdict table: **pending — see verification-opus.md** until the orchestrator supplies it.

## 1. What exists today (one paragraph per layer)

**Library and topology.** Spatie laravel-permission 6.25 with `teams = true` and `team_foreign_key = tenant_id`; the Spatie "team" is the tenant, not the company. Every tenant database carries its own `roles`/`permissions` tables; there is no central catalogue. Company and location scoping is a separate, orthogonal layer (`user_company_memberships`, `allowed_location_ids`, `LocationContext`, `ValidateLocationAccess`). Super admin is a separate central model on its own guard, gated by middleware, with no `Gate::before`. (01 §1, §4, §6, §8)

**Catalogue.** One 871-line seeder is the catalogue: 304 permissions and 7 roles (`admin, manager, cashier, viewer, technician, operator, accountant`), `admin` = `Permission::all()`. No PHP enum or constants class; permission names are magic strings in 202 `->can()` calls, 107 `Gate::authorize()` calls, 608 `can:` middleware strings and 262 FormRequest `authorize()` bodies. A second, dead `PermissionSeeder.php` ships a colliding taxonomy. (01 §2, §3)

**Enforcement.** Of 1054 API routes: 642 gated by `can:` middleware, 130 in-controller, 46 FormRequest, 8 policy, 68 super-admin-only, 18 public, and **142 authenticated with no permission check at all, 53 of them live write routes**. Thirty-three permission strings are enforced in middleware on some routes and in controllers on others. 180 FormRequests return `true` from `authorize()`. No coverage ratchet test exists. (02 §"Classification totals", §"Live write AUTH_ONLY routes"; 01 §11)

**Role management API.** `RoleController` writes are gated on `roles.manage` / `users.assign-roles` with an `AssignableRole` escalation guard (the 2026-06-14 go-live blocker is fixed). Reads (`GET /roles`, `/roles/{id}`, `/permissions`) are open to any authenticated tenant user by design. System-role protection is a hard-coded `['super-admin','admin','owner']` list of which only `admin` is a real role name. No last-admin floor. Role assignment is audited; role create/update/delete are not. (01 §5; 04 §1 2026-07-04 row)

**Sync and drift.** New tenants receive the full catalogue at provisioning. Existing tenants receive nothing automatically: `seedRolesAndPermissionsIfMissing()` returns early once an `admin` role exists, and the only documented remediation is a manual reseed whose `syncPermissions()` overwrites any tenant edits to the seven seeded roles. The frontend map is generated from the seeder with a CI + preflight drift guard. (01 §2, §11; 03 §1)

**Frontend and POS.** `usePermissions` checks server-supplied permissions first, then falls back to the generated role map plus ten hand-maintained UI aliases; `services.*` exists only as a UI alias and the matching API has no permission middleware. 254 of 298 web routes are gated; four real pages are not. 119/304 permissions have no module label and 125/304 no action label, identically in en/fr/ar. POS derives manager approval scopes from `pos.approve_*` permissions with a scoped manager-PIN override flow and a legacy role ladder fallback. (03 §1–§7)

**Cache.** The Spatie registry cache key is a single global string; under database-per-tenant every tenant shares it. Known since 2026-07-03, re-found as S-1 on 2026-09-02, dispatched 2026-09-03, **still unmerged**. Only mitigation: cache flush at container boot. (01 §10; 04 §4 item 1)

## 2. Incongruence register (deduplicated across the five reports)

| # | Incongruence | Severity | Evidence |
|---|---|---|---|
| I-1 | Permission cache tenant-blind under db-per-tenant; fix dispatched, never landed | P0 security/consistency | 01 §10; 04 §1 |
| I-2 | 53 live write routes with no permission at any layer (categories, services, service-categories, UoM units, coupons, promotions, channels, `POST /companies`, batches delete/recall, progression, purchase-hub, notifications) | P0 authz | 02 "Live write AUTH_ONLY" |
| I-3 | `credit-notes.cancel` required by a route but never seeded → unreachable by everyone incl. admin in default-seeded environments | P1 functional | 01 §3; 02 "seeder cross-check" |
| I-4 | Seeder `syncPermissions()` clobbers tenant role customisation on re-run; no additive sync path for existing tenants | P1 data-loss / drift | 01 §2, §11 |
| I-5 | No enum/registry for permission names; 1 orphan, 26 dead, deprecated `reports.view` never removed; `pos_orders.*` group enforced nowhere | P1 hygiene | 01 §3 |
| I-6 | Naming drift: kebab vs snake resources (`catalog_cart`, `pos_orders`), `edit` vs `update`, coarse `manage` vs CRUD with no rule | P2 | 01 §2 |
| I-7 | Three enforcement styles (middleware / controller / FormRequest) for the same permissions; POS uses in-controller `Gate::authorize` almost exclusively | P2 consistency | 02 "Mixed enforcement styles" |
| I-8 | Two role vocabularies: Spatie roles vs `MembershipRole` enum (`owner, admin, manager, …`) with overlapping names and no consistency path | P2 model | 01 §4 |
| I-9 | `SYSTEM_ROLES = ['super-admin','admin','owner']` duplicated in BE and FE; two of three entries can never match | P2 | 01 §4; 03 §4 |
| I-10 | No last-admin protection; role create/update/delete unaudited; role read endpoints open | P1 | 01 §5 |
| I-11 | Frontend fallback map is fail-open for any permission not in the 9-entry server-authoritative set; 10 UI-alias permissions reference dead role names; `services.*` is client-side fiction | P1 | 03 §1, §3, §4 |
| I-12 | 39–41 % of permissions have no i18n label in any locale; labels are hand-maintained and never checked | P2 UX | 03 §5 |
| I-13 | Convention doc 03 and the `add-permissions` slash command prescribe a non-existent `permission:` middleware, a non-existent `settings.edit`, and a superseded hand-maintained FE map | P2 process | 01 §11; 04 §4 item 12 |
| I-14 | Glossary defines Membership only; Role, Permission, User, Super admin, General manager undefined (convention 11 violation) | P2 process | 04 §5 |
| I-15 | Dead `PermissionSeeder.php` with colliding capitalised role names still in the tree; `ProductionSeeder` calls it | P2 | 01 §2; 02 "seeder cross-check" |
| I-16 | Module gating and permissions are independent: disabling a module hides permissions in the list only; `marketplace.admin` seeded to every tenant admin for a platform-scoped feature | P3 | 01 §7 |
| I-17 | Per-user permission-like flags outside the catalogue (`can_discount`, `max_discount_percent`) with incomplete POS wiring | P3 | 01 §8; 04 §4 item 8 |

## 3. Benchmark gap analysis (against `05-benchmark` derived defaults)

| Benchmark default | AutoERP today | Gap |
|---|---|---|
| B1 Action permissions and data scope as two orthogonal layers (Odoo ACL + record rules; ERPNext DocPerm + User Permission) | Already the shape: Spatie actions + membership/location scope | None structurally; document it (I-8, I-14) |
| B2 Closed verb set per resource incl. business actions | Verbs exist but uncontrolled (I-6) | Define the verb enum; migrate outliers |
| B3 Permissions declared as versioned code per module, synced idempotently on every install/upgrade (Odoo CSV on `-u`, ERPNext JSON on `bench migrate`) | One monolithic seeder; sync only at provisioning; manual destructive reseed after | **Core gap** — this is what "new features automatically appear in role management" requires |
| B4 New permissions default to the protected admin role only; never auto-granted to custom roles | `admin = Permission::all()` matches; seeded roles get deltas only on destructive reseed | Need a non-destructive template-delta policy (decision D2) |
| B5 Zero-permission new users + role templates | New users get an assigned role; 7 templates exist | Minor |
| B6 Matrix editor + effective-permissions view | Matrix editor exists (RolesPage); no effective view (role ∪ overrides ∪ scope) | Add effective view |
| B7 Per-user overrides as audited exception | Only discount flags; not audited | Decide whether to generalise (D6) |
| B8 Platform operator categorically separate; tenant-visible log | Separate `SuperAdmin` model + guard; impersonation logged with four-eyes | Matches; MFA and TN legal gate still owed |
| B9 Last-admin protection | Absent (I-10) | Add |
| B10 Approvals as role-gated transitions; create ≠ approve on financial docs | Partly (`payments.reverse` admin-only, `pos.approve_*`); no stated SoD rule | Write the SoD rule into the catalogue |
| B11 POS step-up per action via manager PIN | Exists (`approval_scopes`) | Clean legacy ladder; dead `pos.*` names |
| B12 Role/permission changes, denials and impersonation as mandatory audit events | Assignment audited; role mutations and denials not | Add |
| B13 Spatie cache scoped per tenant; reset on every mutation/deploy | Global key (I-1) | **Fix first** |
| B14 UI renders only from server-computed effective permissions; no second map | Hybrid with fail-open fallback (I-11) | Remove fallback; server-authoritative only |
| B15 CI fails when a route lacks a permission check; per-role regression matrix | Nothing (I-2, C5) | Add ratchet + matrix |

## 4. Three directions

**Direction A — Hardening only.** Fix I-1, gate the 53 routes, seed `credit-notes.cancel`, delete the dead seeder, correct the docs, add the coverage ratchet. Keep the seeder as the catalogue. Cheapest; closes the security exposure; does **not** deliver the automatic-inclusion goal, and every future module still edits an 871-line array by hand.

**Direction B — Catalogue-as-code with deploy-time sync (recommended).** Each module declares its permissions in code (a per-module manifest read by a central registry, with a PHP enum per module so call sites stop using string literals). A `permissions:sync` command runs inside `tenants:migrate` on every deploy: additive for permissions, template-delta for seeded roles under an explicit policy (D2), never touching custom roles, always granting new permissions to `admin`, then resetting the tenant's cache. The registry also generates the frontend map and the i18n label skeleton, and three CI guards fail the build when a route lacks a permission, when code references an undeclared permission, or when a declared permission lacks a label. Role-management API and UI are hardened (system flag, last-admin floor, audit events, effective-permissions view). Delivered in waves (§6), Direction A's fixes forming wave 0.

**Direction C — Replace Spatie with a policy engine (ABAC).** Not recommended: the benchmark shows all three reference ERPs succeed with role-based action permissions plus a separate scope layer, which is what exists; the deficits are process and hygiene, not model expressiveness.

## 5. Decisions the owner must make (benchmark attached per convention 10 and the 2026-09-05 rule)

| ID | Question | Odoo | ERPNext | Dolibarr | AutoERP today | Recommended default |
|---|---|---|---|---|---|---|
| D1 | Roles scoped per tenant (current) or per company? | Groups are database-global; multi-company by record rules | Roles global; User Permission restricts Company | Groups per entity in multicompany module | Tenant-scoped + membership/location scope | **Keep tenant-scoped**; company/location stays on membership |
| D2 | What happens to the 7 seeded roles when a deploy adds a permission? | Module upgrade re-applies group definitions unless `noupdate` | `bench migrate` re-syncs standard DocPerms; custom perms kept separately | Module re-activation re-inserts rights | Nothing, or destructive reseed | **Template-delta**: seeded roles track a template version; additive deltas applied on sync unless the tenant has customised that role (flag set on first tenant edit) — then admin-only + notice in the Roles UI |
| D3 | Ungated routes: deny-by-default ratchet from a shrinking baseline, or hard fail now? | ORM denies without ACL row | `@frappe.whitelist` without `has_permission` caused CVEs | Rights checked per page | 142 ungated | **Ratchet with ceilinged baseline** (repo pattern), wave 0 gates the 53 writes first |
| D4 | Role read endpoints: open, or `roles.view`? | Access rights visible to admins only | Role Permission Manager = System Manager | Admin only | Open by design | **`roles.view`** for the matrix; role *names* remain readable to `users.assign-roles` holders |
| D5 | Naming convention and outlier migration (`catalog_cart`, `pos_orders`, `uom.edit`, `deliveries.edit`)? | `module.group_x` | `DocType + verb` | `module->object->action` | `resource.verb` mostly | **Freeze `kebab-resource.verb` + verb enum**; rename outliers via sync rename map |
| D6 | Generalise per-user permission overrides beyond discount flags? | Not supported (group-only) | User Permissions (row-scope only) | Per-user rights grid | Discount flags only | **No** for now; keep overrides to discount numerics; revisit after launch |
| D7 | Sequencing against in-flight W-LOT-A-1a (general_manager, batch permissions) and T-1..T-3 (`inventory.transfers.reconcile`) | — | — | — | Both lanes edit the seeder | Wave 0 lands **after** W-LOT-A-1a merges; the registry migration absorbs both lanes' permissions |
| D8 | Role mutation audit + denial logging into the audit chain (not the fiscal chain)? | Opt-in tracking | Version tracking mandatory on Role | Security events log | Assignment only | **Yes**, audit events for role create/update/delete and for 403 denials on write routes |

## 6. Roadmap outline (Direction B; each wave is one spec + one Codex adversarial gate + reviewer gate)

- **Wave 0 — stop the bleeding** (Direction A content): tenant-scoped cache (S-1, brief already exists), gate the 53 write routes, seed `credit-notes.cancel`, delete `PermissionSeeder.php`, fix convention 03 + slash command, add the route-coverage ratchet with today's baseline.
- **Wave 1 — catalogue as code**: per-module permission manifests + enums, central registry, `permissions:sync` in `tenants:migrate`, template-delta policy (D2), rename map (D5), generated FE map and label skeleton from the registry, CI guards (undeclared reference, unlabeled permission).
- **Wave 2 — role management hardening**: `is_system` flag replacing the string list, last-admin floor, `roles.view`, audit events for role mutations, denial logging, effective-permissions endpoint + UI, remove FE fallback map and UI aliases, `services.*` real permissions.
- **Wave 3 — consistency sweep**: one enforcement style (middleware for action, policy for row), POS dead-permission cleanup and legacy ladder removal, `MembershipRole` reconciliation, glossary rows, per-role regression matrix test.
- **Wave 4 — edge-case campaign**: second-company/second-location/custom-role/last-admin/stale-cache/company-switch/offline-POS scenarios driven in the real UI with 5xx and console capture (convention 09 and the 2026-08-31 empirical-evidence rule).

## 7. Out of scope for this lane

Super-admin MFA, impersonation legal gate (E-4), discount policy redesign, cashier price override (deferred by owner 2026-07-09), country-code mutability ticket. Each stays on its own ticket.

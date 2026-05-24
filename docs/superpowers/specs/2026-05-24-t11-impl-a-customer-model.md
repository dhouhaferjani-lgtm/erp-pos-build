# T11-impl-A — Customer Model Augmentation (`customer_contacts`)

**Track:** T11-impl-A (next-cycle implementation track derived from the T11 design spec)
**Date:** 2026-05-24
**Recommended workflow:** Codex (mechanical service + migration + test work). Adversarial review: Codex headless.
**Estimated effort:** ~3 PD
**Parent design:** [2026-05-24-t11-b2b-b2c-separation.md](2026-05-24-t11-b2b-b2c-separation.md) §4.1
**Constitutional reference:** [2026-05-24-migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md)
**Roadmap reference:** [2026-05-24-productization-sprint-roadmap.md](../coordination/2026-05-24-productization-sprint-roadmap.md)

---

## 1. Purpose

A B2B customer account is rarely one person. A business partner has a billing
contact, an ordering contact, sometimes a separate delivery contact and a
technical contact. The web ERP already models *that a partner has contacts*
(`Partner::contacts()` BelongsToMany through `party_contacts`), but it expresses
the relationship's meaning with **scattered boolean flags**
(`is_primary`, `is_invoice_contact`, `is_delivery_contact`) rather than an
explicit, validated, extensible **role**.

This track adds a `customer_contacts` join table with a first-class
`CustomerContactRole` enum, a `CustomerContactService` to manage the
relationship, and the Partner-side relations/helpers to read it. It is the
foundation T11-impl-C needs to render a meaningful "who is this account's
billing contact" panel and the "B2B" search badge, and the foundation any future
B2B portal needs to scope a logged-in contact to the roles they hold.

**This is purely additive.** `party_contacts` keeps working exactly as today.
No data migration. Every existing Partner/Contact test continues to pass.

---

## 2. Architecture grounding (verified file paths + state)

Read in this order:

1. `apps/erp/apps/api/app/Modules/Partner/Domain/Partner.php` (lines 1–377) — the Customer aggregate root. Note the existing `contacts()` BelongsToMany (lines 363–368), `partyContacts()` HasMany (lines 355–358), and `primaryContact()` helper (lines 373–376). `customer_category` discriminator + `isB2B()` (lines 188–191) already exist.
2. `apps/erp/apps/api/app/Modules/Contact/Domain/Contact.php` — the `Contact` entity (the Person). The other end of the join.
3. `apps/erp/apps/api/app/Modules/Contact/Domain/PartyContact.php` (lines 1–76) — the **existing** pivot model. Confirms the relationship is `party_id` → `contact_id` with **boolean** role flags (`is_primary`, `is_invoice_contact`, `is_delivery_contact`) and `start_date`/`end_date` validity. **No role enum exists.**
4. `apps/erp/apps/api/database/migrations/2026_03_10_100002_create_party_contacts_table.php` (lines 17–43) — the existing pivot schema. Note the **patterns to mirror**: `unique(['party_id','contact_id'])` (line 33), the PG-only partial unique index `idx_party_contacts_primary ON party_contacts (party_id) WHERE is_primary = true` (line 39), and the PG-only date-range CHECK constraint (line 42). Both guarded by `DB::getDriverName() === 'pgsql'` so SQLite test runs skip them.
5. `apps/erp/apps/api/database/migrations/2026_03_11_600001_add_contact_role_fields_to_party_contacts.php` — confirms the role concept today is just two added booleans, **not** an enum.
6. `apps/erp/apps/api/app/Modules/Contact/Domain/Enums/Gender.php` — the **only** enum in the Contact module today (verified: no `ContactRole` / `CustomerContactRole` exists yet — this track creates one).
7. `apps/erp/apps/api/app/Modules/Partner/Domain/Enums/CustomerCategory.php` (lines 7–19) — pattern to mirror for the new enum (`values()` helper).
8. `apps/erp/apps/api/app/Modules/Contact/Application/Services/ContactService.php` — service style to mirror (constructor injection, DTO inputs).

**Constraints from `apps/erp/CLAUDE.md`:** hexagonal (Domain / Application / Infrastructure / Presentation), constructor injection only (`private readonly`, never `app()`), strict typing (no `mixed`; JSONB → DTO), enums for every status/type column, TDD, PHPStan level 8, Pint, route middleware `['api', 'auth:sanctum', SetPermissionsTeam::class]`, types flow from backend DTOs (`php artisan typescript:transform`), all user-facing strings via `t()`.

**Migration placement (constitutional):** the single new migration goes in `apps/erp/apps/api/database/migrations/tenant/` — the directory that **T6 Phase 0 creates**. It does not exist yet on `dev`. See §12 for the merge-after sequencing.

**Cross-DB FK rule (constitutional):** `customer_contacts` lives in the tenant DB. It FKs `partner_id → partners` and `contact_id → contacts` (both intra-tenant — Pattern C, normal FK). It gets a `tenant_id` column that is **`->uuid()->nullable()->index()` with NO `->constrained()`** (Pattern D — audit/observability only, never an FK to the central `tenants` table).

### 2.1 Design decision — new table vs. extend `party_contacts`

The constitutional topology contract lists `customer_contacts (NEW)` as a
T11-owned tenant table ([migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md) §2,
"`customer_contacts` (NEW) | T11"). Per that contract's authority clause, the
table is created new.

**Alternative considered and rejected:** add a `role` enum column to the existing
`party_contacts`. Rejected because (a) `party_contacts` already carries
employment-style attributes (`job_title`, `department`, `start_date`,
`end_date`) and three boolean role flags that existing code and the existing
partial unique index depend on; bolting a fourth role axis on top mixes two
concerns in one row and risks a flag-vs-enum source-of-truth split; (b) the
contract already decided. **`customer_contacts` is therefore the B2B
account-relationship-role layer; `party_contacts` is untouched and keeps serving
the existing org-membership relationship.** This track does **not** migrate,
deprecate, or read-through `party_contacts`. The two coexist. Reconciling them
into one table is explicitly out of scope (§9).

---

## 3. Domain model

### New table (tenant DB per topology contract)

**`customer_contacts`** (join / relationship entity)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | `HasUuids` |
| `tenant_id` | uuid, nullable, indexed | **Pattern D** — audit only, NO `->constrained()` |
| `partner_id` | uuid | FK → `partners` (intra-tenant), `cascadeOnDelete` |
| `contact_id` | uuid | FK → `contacts` (intra-tenant), `cascadeOnDelete` |
| `role` | string (enum-backed) | `CustomerContactRole` — see below |
| `is_primary` | boolean, default false | At most one primary per partner (PG partial unique index) |
| `label` | string(100), nullable | Optional free-text role qualifier (e.g., "AP department") |
| `start_date` | date, nullable | Relationship validity start |
| `end_date` | date, nullable | Relationship validity end |
| `created_at` / `updated_at` | timestamps | |

**Constraints (mirror `party_contacts` exactly):**
- `unique(['partner_id', 'contact_id', 'role'])` — a contact may hold multiple distinct roles for one partner, but not the same role twice.
- **PG-only** partial unique index: `CREATE UNIQUE INDEX idx_customer_contacts_primary ON customer_contacts (partner_id) WHERE is_primary = true` — at most one primary contact per partner. Guard with `if (DB::getDriverName() === 'pgsql')`.
- **PG-only** CHECK: `start_date IS NULL OR end_date IS NULL OR start_date <= end_date`. Same guard.
- No soft-deletes (matches `party_contacts`; detach = hard delete of the join row, the `Contact` and `Partner` survive).

### Enum (new)

**`App\Modules\Partner\Domain\Enums\CustomerContactRole`** (string-backed)

```php
case Primary   = 'primary';     // main point of contact for the account
case Billing   = 'billing';     // receives invoices / statements
case Ordering  = 'ordering';    // places orders
case Shipping  = 'shipping';    // delivery / receiving contact
case Technical = 'technical';   // technical / operational contact
case Other     = 'other';       // catch-all; pair with `label`
```

- `label(): string` and `static values(): array` helpers mirroring `CustomerCategory`.
- Generic across all verticals — no automotive/retail-specific roles. `Other` + the free-text `label` column absorb tenant-specific role names without code changes.
- **Placement rationale:** the enum lives under `Partner/Domain/Enums` because the relationship is owned by the Customer (Partner) aggregate — the partner *grants* a contact a role on its account. The `contacts` table is the cross-module entity; the join and its role semantics belong to Partner.

### Eloquent model

**`App\Modules\Partner\Domain\CustomerContact`** (new) — `HasUuids`, `$table = 'customer_contacts'`, fillable = the columns above (minus `id`), casts `role => CustomerContactRole::class`, `is_primary => 'boolean'`, `start_date`/`end_date` => `'date'`. `BelongsTo` relations `partner()` and `contact()`. Helper `isCurrentlyValid(): bool` mirroring `PartnerPriceList::isValidForDate()`.

### Partner aggregate additions (extend `Partner.php`)

- `customerContacts(): HasMany<CustomerContact>` (FK `partner_id`).
- `customerContactRecords(): BelongsToMany<Contact>` through `customer_contacts` withPivot `['role','is_primary','label','start_date','end_date']` + `withTimestamps()`. (Distinct name from the existing `contacts()` to avoid collision — `contacts()` stays bound to `party_contacts`.)
- `primaryCustomerContact(): ?Contact` — the `is_primary` row's contact.
- `contactsForRole(CustomerContactRole $role): Collection<Contact>`.

> **DTO:** any JSON returned to the frontend goes through a `CustomerContactData` DTO (Spatie Laravel-Data, matching the module's DTO convention) so `php artisan typescript:transform` generates the TS type. No hand-edited TS interfaces.

---

## 4. Public contracts

### Application service

```php
final class CustomerContactService
{
    public function __construct(
        private readonly CompanyContext $companyContext, // tenant/company scoping
    ) {}

    // Attach an existing Contact to a Partner with a role.
    // Validates partner + contact belong to the current tenant/company.
    public function attach(AttachCustomerContactCommand $command): CustomerContact;

    // Change the role of an existing link.
    public function changeRole(string $customerContactId, CustomerContactRole $role): CustomerContact;

    // Promote a link to primary (atomically demotes the previous primary for that partner).
    public function setPrimary(string $customerContactId): CustomerContact;

    // Remove a link (hard delete of the join row only).
    public function detach(string $customerContactId): void;

    /** @return Collection<int, CustomerContact> */
    public function listForPartner(string $partnerId): Collection;
}
```

`AttachCustomerContactCommand` (DTO): `partnerId`, `contactId`, `CustomerContactRole $role`, `?bool isPrimary`, `?string label`, `?CarbonInterface startDate`, `?CarbonInterface endDate`.

**Tenant-isolation contract (service-layer, per topology contract Pattern C/D):** every method resolves the current company via `CompanyContext::requireCompany()` and asserts both `partner_id` and `contact_id` resolve to rows in the current tenant/company before mutating — never trusting the request body. The DB boundary is the tenant; the service is the second line. Cross-tenant `attach` throws `ModelNotFoundException` (404), never a leak.

**`setPrimary` atomicity:** wrap demote-old-primary + promote-new in `DB::transaction()`. The PG partial unique index is the backstop; the transaction is the intent. Validate with a concurrent-promotion test.

### Events emitted (immutable — CLAUDE.md rule 8)

- `CustomerContactAttached` (`customer_contact_id`, `tenant_id`, `partner_id`, `contact_id`, `role`)
- `CustomerContactRoleChanged` (`customer_contact_id`, `tenant_id`, `partner_id`, `old_role`, `new_role`)
- `CustomerContactDetached` (`customer_contact_id`, `tenant_id`, `partner_id`, `contact_id`)

Emit via `DB::afterCommit(fn () => event(...))` to match the codebase's post-commit event convention.

### REST endpoints (nested under partners)

All behind `['api', 'auth:sanctum', SetPermissionsTeam::class]` and RBAC-gated.

- `GET    /api/v1/partners/{partner}/customer-contacts` — list (with role filter)
- `POST   /api/v1/partners/{partner}/customer-contacts` — attach
- `PUT    /api/v1/partners/{partner}/customer-contacts/{id}` — change role / label / dates
- `POST   /api/v1/partners/{partner}/customer-contacts/{id}/set-primary` — promote
- `DELETE /api/v1/partners/{partner}/customer-contacts/{id}` — detach

**UUID guard:** every `{partner}` / `{id}` path param is validated with `Str::isUuid()` before any `where('id', …)` / `where('uuid', …)` query (PostgreSQL 500s on malformed UUID input — recurring project pitfall).

### Permissions (new, via `/project:add-permissions` for the Partner module)

`customer-contact.view`, `customer-contact.manage` (covers attach/change/set-primary/detach). Wired into `RolesAndPermissionsSeeder`.

---

## 5. User-visible surface

### Admin UI (React, `apps/web/src/features/partners/contacts/`)

- **CustomerContactsPanel** — embedded in the existing Partner detail page (B2B partners). Lists linked contacts with a role badge per row; primary contact pinned to top with a star.
- **AttachContactDialog** — pick an existing Contact (search) or create one inline, choose role, optional label + validity dates.
- **Role chip + "Set primary"** inline action per row; **Detach** with confirm.
- All labels via `t()` keys in a `customerContacts` i18n namespace (add via `/project:add-i18n-namespace`). Design tokens from `@/lib/designTokens` for any colors.

### POS (deferred to T11-impl-C; logged, not built here)

The "B2B" badge in customer search (`T11-D1` in the POS coordination log) reads
`partner.customer_category` and, when impl-C ships, may surface the primary
customer contact name. **No Tauri code in this track.** See §12.

---

## 6. Generic-ness checklist

- [ ] Zero client names anywhere in code/config/seeders/tests
- [ ] `CustomerContactRole` is vertical-neutral (`Other` + free-text `label` absorb any tenant-specific role)
- [ ] Works for any tenant regardless of vertical in `apps/erp/apps/api/config/verticals.php`
- [ ] No data migration required — existing partners simply have zero `customer_contacts` rows
- [ ] `party_contacts` behaviour unchanged (additive-only)
- [ ] Migration in `database/migrations/tenant/`; `tenant_id` is Pattern D (no FK); partner/contact FKs intra-tenant only
- [ ] No cross-DB FK violations

---

## 7. Acceptance criteria

- [ ] A B2B partner can have 3 contacts attached with roles `Billing`, `Ordering`, `Shipping`; all three list correctly
- [ ] The same contact can hold two distinct roles (`Billing` + `Ordering`) for one partner; attaching the *same* role twice fails with a 422 (unique violation surfaced as validation error, not 500)
- [ ] `setPrimary` on a second contact demotes the first; querying `primaryCustomerContact()` returns exactly one contact; a partial-unique-index violation can never produce two primaries
- [ ] `detach` removes only the join row; the `Contact` and `Partner` rows survive
- [ ] Attaching a contact from another tenant/company returns 404, never leaks the foreign row
- [ ] Deleting a partner cascades and removes its `customer_contacts` rows (FK `cascadeOnDelete`)
- [ ] Events `CustomerContactAttached` / `CustomerContactRoleChanged` / `CustomerContactDetached` fire post-commit with correct payloads
- [ ] Backward compat: every existing Partner and Contact test passes unchanged; `party_contacts` queries and `Partner::contacts()` behave identically
- [ ] `php artisan typescript:transform` regenerates `CustomerContactData` into `packages/shared/types/`; the React panel imports the generated type (no hand-edited interface)
- [ ] Endpoints reject malformed UUID path params with 422, not 500

### Tests (write first — TDD)

- [ ] Migration test: table + columns + the two PG-only constraints exist after migrate (skip the PG-only assertions on SQLite)
- [ ] Unit: `CustomerContactRole::values()`/`label()`
- [ ] Unit: `CustomerContact::isCurrentlyValid()` across start/end-date edges
- [ ] Feature: each service method (`attach`, `changeRole`, `setPrimary` atomic demote, `detach`, `listForPartner`) with `RefreshDatabase` + real models + `RolesAndPermissionsSeeder`
- [ ] Feature: tenant-isolation (cross-tenant attach → 404)
- [ ] Feature: each endpoint behind RBAC (unauthorized → 403)
- [ ] Feature: unique-role-per-partner-contact violation → 422

---

## 8. Adversarial review checklist

**Reviewer instruction (mandatory):** *"Verify every finding against actual code at the cited paths — read the files, do not assume. Pay special attention to: (1) `customer_contacts` does NOT duplicate or break `party_contacts` — the existing `Partner::contacts()` relation and the `idx_party_contacts_primary` index must be untouched; (2) the new migration lives in `database/migrations/tenant/` and `tenant_id` is a plain indexed uuid with NO `->constrained()` (Pattern D); partner/contact FKs are intra-tenant (Pattern C); (3) `setPrimary` is atomic and the PG partial unique index actually enforces single-primary; (4) tenant isolation is asserted at the service layer, not trusted from the request; (5) the role enum is genuinely vertical-neutral."*

- [ ] `party_contacts` is untouched; `Partner::contacts()` (BelongsToMany via `party_contacts`) still resolves; no new relation shadows it
- [ ] Migration is in `database/migrations/tenant/`; `tenant_id` has no `->constrained()`; partner/contact FKs are intra-tenant `cascadeOnDelete`
- [ ] PG-only constraints (`idx_customer_contacts_primary` partial unique, date-range CHECK) are guarded by `DB::getDriverName() === 'pgsql'` so the SQLite test suite runs
- [ ] `setPrimary` wraps demote+promote in a transaction; concurrent-promotion test proves single-primary invariant holds
- [ ] Cross-tenant `attach`/`changeRole`/`detach` resolves nothing → 404; no foreign `partner_id`/`contact_id` accepted from the body
- [ ] Unique `(partner_id, contact_id, role)` violation surfaces as 422, not an unhandled 500
- [ ] UUID path params validated with `Str::isUuid()` before any UUID-column query
- [ ] All three events are new immutable classes (no reuse/rename of existing events)
- [ ] DTO + `typescript:transform`; no hand-edited TS domain interface
- [ ] No magic strings — `role` always the enum; no client names anywhere
- [ ] Spec drift: every cited file path still says what this spec claims

---

## 9. Out of scope

- Reconciling / merging `party_contacts` and `customer_contacts` into one table — deferred; both coexist
- Linking a `Contact` to their own `Partner` record (the "this person is also a customer in their own right" identity edge) — deferred; `customer_contacts` only maps partner → contact roles
- Contact-scoped B2B self-service portal / login-as-contact — separate future track
- Per-role notification routing (e.g., email invoices to the `Billing` contact automatically) — future; this track only stores the role
- POS surfaces (badge, contact display) — T11-impl-C
- Pricing / document behaviour — T11-impl-B

---

## 10. Reading order

1. This spec
2. Parent design [2026-05-24-t11-b2b-b2c-separation.md](2026-05-24-t11-b2b-b2c-separation.md) §4.1
3. [2026-05-24-migration-topology-contract.md](../coordination/2026-05-24-migration-topology-contract.md) §2 (table classification), §4 Pattern C + Pattern D
4. `apps/erp/CLAUDE.md` + `apps/erp/apps/api/app/Modules/Partner/.claude` conventions
5. The 8 file paths in §2
6. Existing tests at `apps/erp/apps/api/tests/Feature/Partner/` and `.../Contact/` to mirror style
7. Memory: `feedback_sweep_audit_trail_anchoring.md` (tenant-isolation patterns)

---

## 11. Workflow recommendation

**Single phase (Codex, ~3 PD)** — this is mechanical, well-bounded work:

1. **Enum + model + migration** (TDD: migration test first). `CustomerContactRole`, `CustomerContact` model, the `tenant/` migration with both PG-only constraints.
2. **Service + events** (TDD: feature test per method first). `CustomerContactService`, the three events, `AttachCustomerContactCommand` DTO, `CustomerContactData` DTO.
3. **Partner relations** (`customerContacts()`, `customerContactRecords()`, `primaryCustomerContact()`, `contactsForRole()`) + unit tests.
4. **Controller + routes + permissions + requests** (FormRequest validation, UUID guards, RBAC). Run `php artisan typescript:transform`.
5. **Admin UI panel + i18n namespace**.
6. **Preflight:** `./scripts/preflight.sh` green (PHPStan L8, Pint, PHPUnit, tsc, ESLint) before done.

Adversarial review: Codex headless against §8.

---

## 12. Coordination notes

- **Depends on T6 Phase 0** for the `database/migrations/tenant/` directory and the Stancl `PostgreSQLDatabaseManager` flip. **Sequencing per topology contract §3:** no new migration may be *merged* until T6 Phase 0 lands. This track may be **fully developed on a feature branch off `dev`** (service, model, tests against the pre-flip schema in `migrations/`), with the migration file authored under `migrations/tenant/` and **merged after** Phase 0 — i.e. branch-dev OK, merge-after.
- **Enables T11-impl-C:** the POS "B2B" badge (`T11-D1`) and any contact display read this model. Logged in [2026-05-24-pos-coordination-log.md](../coordination/2026-05-24-pos-coordination-log.md) (T11-D1, dependency "T11-impl-A merged").
- **No Tauri / POS-client code in this track.** Any POS surfacing flows through the POS coordination log → fiscal session, never direct Tauri edits.
- **No published-API or canonical-DB-shape change** to existing tables, so no REALIGNMENT-LOG entry needed for `party_contacts`. The new `customer_contacts` table + endpoints are net-new; document them in the Partner module docs when shipped.
- **Backward compat is non-negotiable** — additive only.

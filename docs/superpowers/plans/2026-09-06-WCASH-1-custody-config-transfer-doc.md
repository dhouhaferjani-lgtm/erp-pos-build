# Slice plan W-CASH-1 — per-location cash custody configuration and repository transfer document (rev 2)

Evidence baseline: current HEAD `f13b923a5c150ceee8acb8165e98197207c88eff`, inspected on 2026-09-06 by reading repository metadata without running git commands. Current-code anchors were re-read at this baseline, including all three citations flagged by r1. NEW paths/signatures/schemas are implementation proposals. This revision changes only this plan; no code, tests, migrations or git commands were run.

## Round-1 change log

All r1 findings are accepted and addressed; none rejected. This is a revised plan, not a claim of a passed implementation gate.

| Finding | Rev-2 correction |
|---|---|
| BLOCKER 1 | Owner register below copies Q11–Q13 RULED verbatim. Their implementation is deferred by slice scope, not pending a decision. |
| BLOCKER 2 | Deployment section now contains the canonical §5 sentence, every variable, §4 verbatim checklist, per-push rollback points and U-1/U-2 promotion prerequisites. T3/T5 specify a default-false, forward-only writer cutover. |
| BLOCKER 3 | T3/T5 specify stored-document-first replay authorization under locks, original-actor comparison, client-only semantics, scoped 404 before conflict, and immutable server-derived evidence on retry. |
| MAJOR 1 | T1 adds tenant/company, tenant/actor and tenant/company/repository/leg/JE composites plus direct-SQL topology tests. |
| MAJOR 2 | T2 uses Presentation/Console, exact provider import/registration seams, deterministic fleet JSON/markers, option semantics and aggregate failure status. |
| MAJOR 3 | T2 names RepositoryMetadataService as the metadata writer and orders both metadata/configuration writes company lock → locations → configurations → repositories. |
| MAJOR 4 | Each task has an executable red-contract register and immediate convention-09 mapping below; no gate borrows future T6 coverage. |
| MAJOR 5 | T5 has the hard external W1-T-CUSTODY-AUTHZ prerequisite, full request rules, index modes, document-read scope, envelopes and statuses. |
| MAJOR 6 | T5 enumerates all repository-response shadows found in web and device clients, converts to generated types/Pick aliases and keeps POS runtime behavior unchanged. |
| MINOR 1 | Append-only anchor is RepositoryMovement.php:73; web wrapper is routes/index.tsx:1937; command registration is TreasuryServiceProvider.php:236 with Console imports at :37. |


Deliver two prerequisites: location custody defaults on Treasury → Repositories, and one immutable justifying document for each new back-office repository transfer. Six tasks maximum. Exclude shift-event booking, v2/v3 adapters, W7, variance enablement, drawer sessions, typed device cash reasons and historical alignment.

## Industry baseline — convention 10

Flow: cash custody setup and internal repository transfer. Odoo reference is specifically version 17, not a claim about all later releases. ERPNext and Dolibarr references are their unversioned documentation accessed 2026-09-06. NV means not verified, not absent. Sources: [Odoo 17 internal transfers](https://www.odoo.com/documentation/17.0/applications/finance/accounting/payments/internal_transfers.html) (search result available; full-page fetch timed out), [ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry), [Dolibarr Banks and Cash](https://wiki.dolibarr.org/index.php/Module_Banks_and_cash). Odoo's paired-liquidity behavior and Dolibarr account transfers are also supplied benchmark facts in the dispatch; do not infer their exact document schema or retry guarantees.

| ID | Guarantee | Odoo | ERPNext | Dolibarr or NV | AutoERP today path:line | Gap | Decision MATCH/DEFER/DIVERGE/ALREADY |
|---|---|---|---|---|---|---|---|
| B1 create | Internal movement has supporting evidence and balanced money effects | Paired liquidity entries | Internal Transfer Payment Entry | Account transfer; document shape NV | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:76` creates optional draft; `:89` writes legs; `:108` returns no document | Justifying document missing | MATCH — T1/T3; retain zero JE for same GL |
| B2 duplicate | Duplicate action cannot move funds twice | Operation UUID guarantee NV | Operation UUID guarantee NV | NV | `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:296` uses group-based leg keys | No company-operation document identity | DIVERGE — explicit stronger company-operation contract, T3 |
| B3 edit | Editing defaults cannot rewrite an executed transfer | Exact custody-default revision behavior NV | Exact revision behavior NV | NV | `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:165` validates mutable repository fields | No custody revision/evidence snapshot | DIVERGE — immutable configuration revisions and documents, T1/T2 |
| B4 cancel/reverse | Correction preserves the original evidence | Exact linked-document policy NV | Payment Entry supports cancellation; exact proposed shape NV | NV | `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:369` writes null reversal link for transfers | No linked transfer reversal | MATCH — compensating document and pair, T4 |
| B5 rerun | A retry returns an explicit existing outcome | Exact response NV | Exact response NV | NV | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:113` reports replay of both legs | Replay result lacks document and stable company-operation semantics | ALREADY for paired replay; MATCH document extension, T3 |
| B6 second company | Each company's configuration and operation identity are independent | Exact UUID scope NV | Internal transfer between company cash/bank accounts | NV | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:120` scopes repositories; `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:80` still validates codes tenant-wide | New keys must include company; HTTP code validation disagrees | MATCH — T1/T2/T6 |
| B7 second location | Selected branch controls source custody and defaults | Branch-specific policy NV | Exact drawer policy NV | NV | `apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31` resolves allowed locations; `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:28` passes no location intent | Transfer adapter has no branch check | DIVERGE — W1 source-custody policy, T5 |
| B8 permission | Unauthorized source IDs are inaccessible without side effects | Exact scoped-404 policy NV | Exact scoped-404 policy NV | NV | `apps/api/app/Modules/Treasury/Presentation/routes.php:102` uses treasury.transfer; outer middleware at `:35` has no module gate | Permission exists; module/source checks missing here | MATCH permission defense; DIVERGE scoped-404 contract, T5 |
| B9 audit | Evidence identifies actor, legs and accounting effect | Accounting entries | Operational document and ledger effect | Account records; exact evidence NV | `apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:73` is append-only; `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:41` returns group/JE/legs | No immutable action record | MATCH — T1/T3/T4 |

Vocabulary — convention 11: **Repository** exists (`docs/glossary.md:60`), canonical surface Treasury → Repositories. **Cash custody configuration** is NEW: `cash_custody_configurations`, Treasury, sole writer `CashCustodyConfigurationService`, a location setting mode on that existing surface; synonym “custody defaults.” **Repository transfer document** is NEW: `repository_transfer_documents`, Treasury, sole writer `RepositoryTransferService`, existing transfer modal and repository detail history; synonym “transfer justification.” Add both glossary rows in T1. A revision is history of the same configuration, not another catalogue. A reversal is another repository transfer document, not a separate concept/table.

Second-of-everything — convention 09: each T1–T6 gate below names its own real second-company, selected second-location and explicit rerun coverage; none is deferred to a later task gate. No tenant-only new catalogue unique and no ratchet ceiling increase.

## Owner rulings — RULED, verbatim

Authority: `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:147`, section “Q10–Q13 RULED”; all recommended defaults accepted. Q11–Q13 ruling rows below are verbatim.

| ID | Ruling |
|---|---|
| **Q11** | One cash-bearing shift per drawer at a time; a second terminal joins the open drawer session without a second float, or is refused. The drawer session is the custody unit; terminal sessions attribute sales. |
| **Q12** | Typed reason codes on the device: `SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`; each mapped in configuration to a destination (safe/bank transfers; petty expense = expense document); `OTHER` blocked until classified; v2 `DEPOSIT`/`PAYOUT` mapped by a cutover table. |
| **Q13** | One counted, dated alignment per repository at cutover, booked as document + movement + JE to cash-difference gain/loss; no retroactive rebooking; the disabled variance window is closed by that alignment. |

The Q11 drawer-session model, Q12 device reason-code enum/mapping and Q13 counted historical alignment are **DEFERRED to later W-CASH slices under these rulings**. No owner decision is outstanding for this slice. No reason-code enum, drawer-session model, historical alignment, shift booking, v2/v3 adapter, W7 or variance activation ships here.

## Verified seams and implementation contracts

Existing signatures, retained unless explicitly replaced below:

- `RepositoryTransferService::transfer(string $tenantId, string $companyId, string $fromRepositoryId, string $toRepositoryId, string $amount, ?string $notes, ?string $transferGroupId, string $userId): RepositoryTransferResult` — `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:33`.
- `TreasuryMovementServiceInterface::transfer(TransferIntent $intent): TransferResult` — `apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:96`; implementation `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:225`.
- `GeneralLedgerService::createRepositoryTransferJournalEntry(string $companyId, string $tenantId, string $transferGroupId, string $fromGlAccountId, string $toGlAccountId, string $amount, \DateTimeInterface $date, string $description): JournalEntry` — `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1497`. It creates a draft, not a posted entry (`:1490`); movement transfer posts it (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:347`). Correct the shared contract's contradictory “pre-posted” wording at `apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:76`.
- `LocationScopeResolver::resolve(User $user, array $requestedIds = [], ?string $bypassPermission = null): array`, lists of UUID strings — `apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31`. HTTP-only restriction is explicit at `:12`.
- `PaymentRepositoryController::{index(Request $request), show(Request $request, string $id), store(Request $request), update(Request $request, string $id)}: JsonResponse` — `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:33`, `:51`, `:69`, `:153`.
- `RepositoryTransferController::store(TransferRepositoryRequest $request): JsonResponse` — `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:21`; `TransferRepositoryRequest::rules(): array` — `apps/api/app/Modules/Treasury/Presentation/Requests/TransferRepositoryRequest.php:19`.

All new PHP production types use strict types and constructor injection; DTOs extend Spatie Data and are exported by the existing TypeScript transform. All money is numeric-string, normalized with the explicit repository currency; reject nonpositive, excess precision, overflow, incompatible currencies, inactive/virtual repositories and self-transfer at the service boundary as well as HTTP.

## T1 — Schemas, vocabulary and typed contracts

Verified production anchors: movement schema `apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:15`; immutable model `apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:73`; repository types `apps/api/app/Modules/Treasury/Domain/Enums/RepositoryType.php:7`; existing transfer DTO `apps/api/app/Modules/Treasury/Application/DTOs/TransferIntent.php:26`. Update `docs/glossary.md:60` with the two NEW rows.

NEW migration files, in order:

1. `apps/api/database/migrations/tenant/2026_09_06_210000_create_cash_custody_configurations.php`
2. `apps/api/database/migrations/tenant/2026_09_06_210100_create_repository_transfer_documents.php`

Every migration implements `up(): void` and `down(): void`; use existence guards plus fail-loud shape verification on rerun, not silently accepting a partially created schema. PostgreSQL is authoritative for the relational/trigger guarantees.

Complete proposed schema notation: unless specified, columns are NOT NULL, have no default, and FKs use ON DELETE RESTRICT / ON UPDATE RESTRICT. UUID primary keys are application-generated. No soft deletes or updated_at on either append-only table. Tenant IDs never reference the central tenants table across a database boundary. Instead, enforce local relational ownership: `(tenant_id,company_id)` references `companies(tenant_id,id)` and `(tenant_id,created_by)` references `users(tenant_id,id)`, both RESTRICT. Add owned parent uniques `(tenant_id,id)` to companies and users where no equivalent exists. The company and actor must agree with the initialized tenant in db-per-tenant mode and with explicit tenant intent in compatibility mode. Membership/permission authorization remains a separate first-submission and replay check; tenant-global user identity alone grants no company rights.

`cash_custody_configurations`:

| Column | SQL type | Null/default | FK |
|---|---|---|---|
| id | uuid | primary key | — |
| tenant_id | uuid | required | composite ownership FKs below |
| company_id | uuid | required | companies(tenant_id,id), composite with tenant_id |
| location_id | uuid | required | locations(company_id,id), composite with company_id |
| revision | bigint | required | — |
| default_safe_repository_id | uuid | NULL | payment_repositories(tenant_id,company_id,id), composite |
| default_bank_repository_id | uuid | NULL | payment_repositories(tenant_id,company_id,id), composite |
| enabled | boolean | DEFAULT false | — |
| created_by | uuid | required | users(tenant_id,id), composite with tenant_id |
| created_at | timestamptz | DEFAULT CURRENT_TIMESTAMP | — |

Checks: revision > 0; enabled implies non-null safe; safe and bank differ when both present. Unique `(company_id,location_id,revision)` and `(company_id,id)`; index `(company_id,location_id,revision DESC)`. Current configuration is highest revision, never an arbitrary first row. Add parent unique `(company_id,id)` on locations only if an equivalent constraint does not already exist; track ownership so down removes only this migration's additions. No JSON columns. Model and PG BEFORE UPDATE/DELETE triggers reject mutation. This preserves configuration audit without a second history table.

`repository_transfer_documents`:

| Column | SQL type | Null/default | FK |
|---|---|---|---|
| id | uuid | primary key | — |
| tenant_id | uuid | required | composite ownership FKs below |
| company_id | uuid | required | companies(tenant_id,id), composite with tenant_id |
| operation_uuid | uuid | required | — |
| transfer_group_id | uuid | required | — |
| kind | varchar(16) | required | PHP enum; check below |
| from_repository_id | uuid | required | payment_repositories(tenant_id,company_id,id), composite |
| to_repository_id | uuid | required | payment_repositories(tenant_id,company_id,id), composite |
| source_location_id | uuid | NULL | locations(company_id,id), composite |
| amount | decimal(15,3) | required | — |
| currency | char(3) | required | — |
| out_movement_id | uuid | required | repository_movements(tenant_id,company_id,id), composite |
| in_movement_id | uuid | required | repository_movements(tenant_id,company_id,id), composite |
| journal_entry_id | uuid | NULL | journal_entries(tenant_id,company_id,id), composite |
| reverses_document_id | uuid | NULL | repository_transfer_documents(tenant_id,company_id,id), composite |
| evidence | jsonb | required, no default | TransferDocumentEvidenceData |
| notes | varchar(1000) | NULL | — |
| occurred_at | timestamptz | required | — |
| created_by | uuid | required | users(tenant_id,id), composite with tenant_id |
| created_at | timestamptz | DEFAULT CURRENT_TIMESTAMP | — |

Checks: amount > 0; source != destination; out != in; uppercase three-letter currency; kind IN ('transfer','reversal'); transfer requires null reverses_document_id; reversal requires non-null, non-self reverses_document_id; evidence is a JSON object with schema_version 1. No stored status: successful insert means recorded; reversed display state derives from a linked reversal.

Uniques: `(company_id,id)`, `(company_id,operation_uuid)`, `(company_id,transfer_group_id)`, `(company_id,out_movement_id)`, `(company_id,in_movement_id)`; partial unique `(company_id,reverses_document_id) WHERE reverses_document_id IS NOT NULL`. Indexes: `(company_id,from_repository_id,occurred_at)`, `(company_id,to_repository_id,occurred_at)`, `(company_id,source_location_id,occurred_at)`. Add parent `(tenant_id,company_id,id)` uniques on payment_repositories in migration 210000 and on repository_movements and journal_entries in migration 210100 if absent; each reference includes all three columns. Add `(tenant_id,company_id,id)` on repository_transfer_documents for the reversal FK. Existing `(company_id,id)` document and configuration keys remain company-scoped; the operation unique remains `(company_id,operation_uuid)`, with company ownership enforcing the tenant dimension. Add self-FK after CREATE TABLE.

Direct-SQL checks must also reject mismatched tenant values on referenced legacy repository/leg/JE rows, not just mismatched company IDs. Parent composites are additive and migration-owned. Existing ratchet exclusions classify companies as the scope boundary and users as tenant-global identities (`apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:78`, `:175`); no new waiver or ceiling increase is needed for their identity composites.

Cross-link both ways without updating immutable movements: document points to the two legs; each leg's existing `(company_id,transfer_group_id)` resolves the unique document. Add scoped Eloquent read relationships. NEW documents are inserted after their legs within the same outer transaction. A deferred PG constraint trigger on document INSERT checks: exactly two legs in its company/group; correct directions, repositories, tenant, amount, currency, source_type Transfer, source_id group and matching nullable JE; a cross-GL evidence snapshot requires one posted balanced JE in that company, same-GL requires null JE. An INSERT trigger on movements also checks groups that already have documents, preventing a later third leg. Legacy groups without documents remain readable and are not retroactively synthesized. Trigger checks reversal amount/currency/opposite repositories, original kind transfer and reversed movement links. It also checks evidence.authorization.actor_id equals created_by, source_location matches the document snapshot and permission equals treasury.transfer; a mismatched authorization snapshot raises SQLSTATE 23514. BEFORE UPDATE/DELETE rejects document mutation; mirror with model guards.

NEW files under `apps/api/app/Modules/Treasury/`: `Domain/CashCustodyConfiguration.php`, `Domain/RepositoryTransferDocument.php`; `Domain/Enums/RepositoryTransferDocumentKind.php` with Transfer='transfer', Reversal='reversal'; `Domain/Enums/RepositoryTransferOutcome.php` with Recorded='recorded', AlreadyRecorded='already_recorded'; `Domain/Enums/CashCustodySaveOutcome.php` with Saved='saved', Unchanged='unchanged'. These are document/outcome types, not Q12 cash-reason codes.

NEW `Application/DTOs/TransferDocumentEvidenceData.php` constructor: `__construct(int $schema_version, ?string $configuration_id, ?int $configuration_revision, ?string $from_gl_account_id, ?string $to_gl_account_id, bool $source_recorded_while_frozen, bool $destination_recorded_while_frozen, ?string $reversal_explanation, TransferAuthorizationEvidenceData $authorization)`. Validate schema_version=1, configuration id/revision jointly null or present, evidence snapshots against rows while locked. No arbitrary metadata array or mixed. NEW `Application/DTOs/TransferAuthorizationEvidenceData.php`: `__construct(string $actor_id, ?string $source_location_id, bool $company_wide_authority, string $permission)`; permission must equal treasury.transfer. This typed JSONB child records first-submission authorization, not an enduring grant. Other proposed DTO constructors:

- `CashCustodyConfigurationData::__construct(string $id, string $company_id, string $location_id, int $revision, ?string $default_safe_repository_id, ?string $default_bank_repository_id, bool $enabled)`.
- `CashCustodySaveResult::__construct(CashCustodyConfigurationData $configuration, CashCustodySaveOutcome $outcome)`.
- `RepositoryTransferDocumentData::__construct(string $id, string $operation_uuid, string $transfer_group_id, RepositoryTransferDocumentKind $kind, string $from_repository_id, string $to_repository_id, ?string $source_location_id, string $amount, string $currency, string $out_movement_id, string $in_movement_id, ?string $journal_entry_id, ?string $reverses_document_id, TransferDocumentEvidenceData $evidence, ?string $notes, string $occurred_at, string $created_by, string $created_at)`.

Red-first contract register (all methods below are required):

File **NEW unless already cited**: `apps/api/tests/Feature/Treasury/CashCustodySchemaTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=CashCustodySchemaTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `CashCustodySchemaTest::test_schema_has_company_scoped_document_identity(): void` | `assertTrue(Schema::hasTable("repository_transfer_documents"))`; inspect named operation unique. |
| `CashCustodySchemaTest::test_wrong_tenant_company_insert_is_rejected(): void` | Catch `QueryException`, `assertSame("23503", $e->errorInfo[0])`; document/configuration counts unchanged. Compatibility PG, tenant A + company B. |
| `CashCustodySchemaTest::test_wrong_tenant_actor_insert_is_rejected(): void` | SQLSTATE 23503 for actor B on otherwise A-owned rows; snapshot unchanged. |
| `CashCustodySchemaTest::test_foreign_repository_leg_and_journal_are_rejected(): void` | Data provider targets each composite FK; SQLSTATE 23503 and no child insert. |
| `CashCustodySchemaTest::test_documents_and_configurations_are_append_only(): void` | Data provider raw UPDATE/DELETE on each table: SQLSTATE 23514 from named immutable trigger; row bytes unchanged. |
| `CashCustodySchemaTest::test_third_leg_and_wrong_journal_are_rejected(): void` | Deferred constraint forced IMMEDIATE: SQLSTATE 23514 for third leg, wrong posted-status/balance/nullability, snapshot unchanged. |
| `CashCustodySchemaTest::test_second_registered_company_accepts_same_operation_uuid(): void` | Assert two documents with same client UUID and distinct company/group ownership; register A then real POST companies for B. |
| `CashCustodySchemaTest::test_second_location_accepts_independent_configuration(): void` | Assert stored second location ID, safe ID and revision 1; first location row unchanged. |
| `CashCustodySchemaTest::test_migration_rerun_reports_nothing_to_migrate(): void` | Invoke isolated migrator twice; second output contains "Nothing to migrate" and schema/data snapshots equal; explicit database-migrator outcome "Nothing to migrate" (already applied), not silence. |

Convention-09 at T1 gate: second company = test_second_registered_company_accepts_same_operation_uuid; second location = test_second_location_accepts_independent_configuration; rerun = test_migration_rerun_reports_nothing_to_migrate, explicit "Nothing to migrate" (already applied). These run before T1 acceptance, with local fixture helpers, not T6 dependency.

Implement only after capturing the named red failures, then rerun the exact commands to green. Test results are not claimed by this plan.

Gate: treasury-reviewer + tenancy-authz-reviewer inspect cardinality, FK ownership, no reason-code/session/alignment additions. Rollback: pre-use only, drop triggers/functions, child tables then owned parent constraints; after recorded money, retain schema and evidence and roll application forward.

## T2 — Location settings, activation validator and census

Verified production anchors: existing repository reads/writes `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:33`, `:153`; Console imports `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:37` and command registration `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:236`. Existing progression activation calls an external client (`apps/api/app/Modules/Progression/Application/Services/ProgressionService.php:106`); it is not evidence of a cash-custody activation mechanism.

NEW `Application/Services/CashCustodyConfigurationService.php` public signatures:

- `save(string $tenantId, string $companyId, string $locationId, ?string $safeRepositoryId, ?string $bankRepositoryId, bool $enabled, int $expectedRevision, string $actorId): CashCustodySaveResult`.
- `current(string $tenantId, string $companyId, string $locationId): ?CashCustodyConfigurationData`.
- `assertReadyForActivation(string $tenantId, string $companyId): void`.
- `census(string $tenantId, string $companyId): CashCustodyCensusData`.

NEW `Application/DTOs/CashCustodyCensusData.php`: `__construct(string $tenant_id, string $company_id, CashCustodyCensusStatus $status, array $unconfigured_location_ids, array $unlocated_drawer_ids, array $missing_bank_location_ids)`; each array is `list<string>`, never untyped arbitrary JSON. This is transport, not a stored JSON column.

Activation means enabling a location's cash-custody configuration, not granting a module licence or enabling any event consumer. `save(...enabled:true...)` validates the selected location; the company-wide validator refuses readiness if ANY location with an active drawer lacks an enabled configuration with a valid safe, or an active drawer lacks a location. Later W-CASH activation must call this public validator before enabling its consumer. This slice wires the local enabled transition and ships the company readiness command; it does not pretend to activate a nonexistent projector.

The NEW `Application/Services/RepositoryMetadataService.php` is the single in-scope repository metadata writer: `update(string $tenantId, string $companyId, string $repositoryId, RepositoryMetadataUpdateData $changes, string $actorId): PaymentRepository`. NEW `Application/DTOs/RepositoryMetadataUpdateData.php` uses Spatie Optional to distinguish omitted from explicit null; constructor fields are `string|Optional $code`, `string|Optional $name`, `RepositoryType|Optional $type`, `bool|Optional $allow_negative`, `string|null|Optional $bank_id`, `$bank_name`, `$account_number`, `$iban`, `$bic`, `$location_id`, `$responsible_user_id`, `$account_id`, `$gl_account_id`, and `bool|Optional $is_active`. Each grouped nullable field has that full union; all fields default to Optional via DTO construction, never implicit null. No balance, ordinal, tenant_id or company_id is writable.

Replace both update branches in `PaymentRepositoryController.php:216` and `:264` with this writer **before any controller repository lock**. Preserve account-id/GL defaulting, the existing no-JE transfer reassignment refusal (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:235`), type-derived allow_negative and duplicate-drawer error translation. Do not call a location-first validator from within the existing repository-first transaction.

One lock order for configuration save and metadata update: transaction → existing bare company-GL advisory lock (`TreasuryMovementService.php:250`) → affected location rows sorted by ID → current configuration rows sorted by location/revision → all involved repositories sorted by ID. Add NEW `Application/Services/CashCustodyLock.php` with `acquireCompany(string $companyId): void`, requiring an outer transaction and using the existing company advisory-lock key (:250); both configuration and metadata writers constructor-inject it. Both writers take the company lock before discovering dependent configurations, then re-read the protected set under it; neither mints a JE, takes an operation lock, or takes the tenant-numbering lock afterward. Transfers take operation → tenant-numbering → company → repository, so metadata never creates a reverse lock edge. Configuration validation inside an already locked transfer is read-only and must not acquire earlier location/configuration locks. All in-scope metadata mutations converge on this writer; T2 tests this writer with explicit authorized fixture intent; exposing its HTTP mutation requires the accepted W1 authorization seam at T5. No direct metadata update bypass may remain in this controller.

For configuration save compare expectedRevision: identical normalized content returns Unchanged and the existing ID/revision, including stale identical retry; different stale content returns 409. Append a revision for a real edit. Validate same company/currency, active Safe and optional active BankAccount, correct location or explicitly selected company-central destination. Missing bank is reported and blocks use of a deposit default, but does not block safe-only readiness. No guessed first safe/bank. An enabled safe cannot be cleared without disabling that configuration. RepositoryMetadataService refuses type change, location reassignment, deactivation, or GL unlink/reassignment that would invalidate ANY latest enabled reference; refuse moving/deactivating an active drawer where this would defeat source-custody/default validation. Currency/company/tenant changes are prohibited request fields. Changing harmless name/code does not rewrite evidence; code validation includes company_id (`PaymentRepositoryController.php:80`, `:170`). A default bank may be absent, but a configured enabled bank may not silently become invalid. PG concurrent tests exercise save versus update with barriers and the changed fields individually.

NEW `Presentation/Console/CashCustodyCensusCommand.php`, namespace `App\Modules\Treasury\Presentation\Console`; extends `App\Console\TenantScopedCommand` with `protected function executeCommand(): int`, inherited handle wrapper. Constructor: `__construct(CompanyContext $companyContext, CashCustodyConfigurationService $custodyService)` calls the base constructor; the injected CompanyContext is lifecycle infrastructure only, never custody authority. Existing Console precedent/import/registration: `apps/api/app/Modules/Treasury/Presentation/Console/CensusRepositoriesCommand.php:5`, `:42`, `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:39`, `:236`. Add the new import beside :39 and class to :237's commands list.

Signature: `treasury:census-cash-custody {--tenant=} {--company=} {--require-ready} {--json}`. No options = all provisioned tenants/all their companies; tenant only = every company in that tenant; tenant+company = exactly that owned company. Company without tenant, malformed UUID, unknown tenant/company or mismatched ownership = usage/lookup failure 2. Use the base tenant iterator, record expected/visited counts, initialize/end tenancy in finally, and filter every company by tenant even in compatibility mode. A skipped missing tenant DB, failure to inspect any target, or empty fleet is incomplete and returns 2, not false readiness. Do not run under tenants:run: this command manages and aggregates tenancy itself.

Stable output: one compact JSON document when --json, otherwise one summary plus company lines, each marker `WCASH-1-CUSTODY:`. JSON shape is `{marker:"WCASH-1-CUSTODY:",schema_version:1,complete:boolean,status:"READY"|"NOT_READY"|"ERROR",expected_companies:int,visited_companies:int,companies:[{tenant_id,company_id,status,unconfigured_location_ids:[],unlocated_drawer_ids:[],missing_bank_location_ids:[]}],errors:[{tenant_id:uuid|null,company_id:uuid|null,message:string}]}`. NEW files under Application/DTOs: `CashCustodyFleetCensusData.php` constructor `__construct(string $marker, int $schema_version, bool $complete, CashCustodyCensusStatus $status, int $expected_companies, int $visited_companies, array $companies, array $errors)`, arrays list<CashCustodyCensusData> and list<CashCustodyCensusErrorData>; `CashCustodyCensusErrorData.php` constructor `__construct(?string $tenant_id, ?string $company_id, string $message)`. CashCustodyCensusData includes the required status parameter shown above. NEW Domain/Enums/CashCustodyCensusStatus.php cases Ready='READY', NotReady='NOT_READY', Error='ERROR'. All arrays have concrete DTO/list<string> element types. No clock timestamp in comparison output; sort tenant/company IDs and every finding list. Config table absent on Push 1 = every drawer location unconfigured, never query missing columns. Tables present with wrong shape = ERROR. A completed census exits 0 without --require-ready even if NOT_READY; with --require-ready it exits 1 when not ready, 0 only READY; ERROR always 2 and dominates other results. No money or seeder writes. Missing bank appears in the report without safe-readiness failure. No additional persisted schema beyond T1.


Red-first contract register:

File **NEW unless already cited**: `apps/api/tests/Feature/Treasury/CashCustodyConfigurationTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=CashCustodyConfigurationTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `CashCustodyConfigurationTest::test_activation_refuses_second_location_without_safe(): void` | `expectException(ValidationException::class)` for enabled save at second location; company assertReady also refuses until all active-drawer locations have safe. |
| `CashCustodyConfigurationTest::test_identical_save_returns_unchanged(): void` | `assertSame(CashCustodySaveOutcome::Unchanged,$second->outcome)`; same ID/revision/count. |
| `CashCustodyConfigurationTest::test_second_company_defaults_are_independent(): void` | B real company creation; assert B same code accepted, B safe selected and A absent from B census. |
| `CashCustodyConfigurationTest::test_second_location_save_uses_selected_safe(): void` | Assert current(secondLocation).default_safe_repository_id equals secondSafe and first remains unchanged. |
| `CashCustodyConfigurationTest::test_repository_mutation_cannot_invalidate_enabled_defaults(): void` | Provider type/location/is_active/gl_account_id/account_id on safe/bank/drawer: expect DomainException; entire metadata/configuration/balance snapshot unchanged. |
| `CashCustodyConfigurationTest::test_harmless_metadata_change_preserves_configuration(): void` | Assert renamed repository plus identical configuration ID/revision and historical evidence. |
| `CashCustodyConfigurationTest::test_stale_changed_configuration_conflicts(): void` | Expect revision conflict with unchanged revision count and current_revision value. |

File **NEW unless already cited**: `apps/api/tests/Feature/Treasury/CashCustodyCensusCommandTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=CashCustodyCensusCommandTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `CashCustodyCensusCommandTest::test_require_ready_reports_unconfigured_second_location(): void` | `assertExitCode(1)` plus NOT_READY marker and exact second location ID. |
| `CashCustodyCensusCommandTest::test_option_matrix_and_deterministic_output(): void` | Provider no options, tenant only, tenant+company: assert exact expected/visited IDs and identical JSON bytes on rerun; status READY/NOT_READY explicit. |
| `CashCustodyCensusCommandTest::test_invalid_options_or_ownership_return_error(): void` | Provider company-only/malformed/unknown/foreign: `assertExitCode(2)`; ERROR marker; zero writes. |
| `CashCustodyCensusCommandTest::test_incomplete_fleet_cannot_report_ready(): void` | Missing DB/lookup fault/empty fleet: exit 2, complete=false and expected != visited or explicit error; later companies still attempted. |
| `CashCustodyCensusCommandTest::test_pre_schema_census_does_not_query_missing_tables(): void` | No T1 tables: NOT_READY, unconfigured selected location, no missing-relation exception. |
| `CashCustodyCensusCommandTest::test_command_does_not_leak_tenant_context(): void` | After two tenants: assert previous connection/context restored and no cross-tenant company results. |

File **NEW unless already cited**: `apps/api/tests/Integration/Treasury/CashCustodyMetadataConcurrencyTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=CashCustodyMetadataConcurrencyTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `CashCustodyMetadataConcurrencyTest::test_save_and_metadata_update_share_lock_order(): void` | Two committed connections with barrier: both finish without deadlock; exactly one valid configuration/metadata outcome wins, loser explicitly refused or unchanged; no invalid enabled default. |

Convention-09 at T2 gate: CashCustodyConfigurationTest::test_second_company_defaults_are_independent; ::test_second_location_save_uses_selected_safe; ::test_identical_save_returns_unchanged. Census rerun also has byte-equal deterministic output.

Implement only after capturing the named red failures, then rerun the exact commands to green. Test results are not claimed by this plan.

Gate: treasury-reviewer + tenancy-authz-reviewer check activation semantics, console tenant lifecycle and central-destination authorization. Rollback: disable the new configuration through a new revision, retain revision history; no balance changes to undo.

## T3 — One atomic, idempotent transfer document

Production files: replace `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:33`; extend `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferResult.php:7`; preserve movement port `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:225` and GL factory `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1497`.

NEW `Application/DTOs/RepositoryTransferIntent.php` constructor: `__construct(string $tenantId, string $companyId, string $operationUuid, string $fromRepositoryId, string $toRepositoryId, string $amount, string $currency, ?string $sourceLocationId, ?string $configurationId, ?int $configurationRevision, ?string $notes, string $actorId, ?CarbonImmutable $occurredAt, RepositoryTransferAuthorizationIntent $authorization, bool $allowWhileFrozen = false)`. Actor and persisted scope are explicit; actorId must equal authorization.actorId and have tenant/company membership, or refuse before mutation. An internal intent is not client authorization. Service signature becomes `transfer(RepositoryTransferIntent $intent): RepositoryTransferResult`; migrate every production caller in T5.

Result constructor becomes `__construct(string $transferGroupId, ?string $journalEntryId, MovementResult $out, MovementResult $in, bool $idempotentReplay, RepositoryTransferDocumentData $document, RepositoryTransferOutcome $outcome)`. Preserve existing output aliases during the web rollout.

Identity is `(tenant, company, operation UUID, first-submission actor authorization)`. The physical unique index is `(company_id,operation_uuid)`, NOT an actor-inclusive unique: a different actor must conflict with the original operation rather than create another transfer. Composite ownership FKs establish the tenant dimension. Semantic comparison fields are actor_id (stored created_by), from_repository_id, to_repository_id, normalized decimal amount, currency, and reason (the existing free-text notes field, canonicalized null/empty consistently without removing meaningful characters). Reversal also compares originalDocumentId and explanation. This slice adds no typed cash-reason enum.

NEW `Application/Services/RepositoryTransferOperationLock.php`: `acquire(string $tenantId, string $companyId, string $operationUuid): void`, requires an outer transaction and uses a dedicated namespaced PG advisory key. Add `acquireOriginal(string $tenantId, string $companyId, string $originalDocumentId): void` using a separate reversal-original namespace; reversals acquire it before acquire(operation). Both are shared by HTTP adapter and service. NEW public `GeneralLedgerService::lockRepositoryTransferContext(string $tenantId, string $companyId): void` uses the existing private tenant-numbering helper (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5671`) then the existing company advisory lock; it neither creates nor posts. Never duplicate the numbering-key literal outside that owner.

Replay order is mandatory: authenticated tenant/company + module/basic action permission → outer transaction and operation lock → tenant-numbering/company lock → resolve existing document in tenant/company → HTTP adapter authorizes its historical source location AND current source repository custody → only then compare actor/semantic fields. Inaccessible stored source returns scoped 404 with NO document ID, even when the submitted source is authorized. Once stored source is accessible, another actor or changed semantic content returns 409 with that existing document ID and code repository_transfer_conflict. An actor whose current authority was revoked cannot reuse first-submission authority; return scoped 404 or action-permission 403, never successful replay.

An identical same-actor authorized retry returns the original document/legs/JE with AlreadyRecorded and idempotentReplay=true. Server-derived source location/configuration ID/revision and server timestamp are copied from original evidence; never recompute them into a client fingerprint. Identical retry remains AlreadyRecorded after configuration revision changes. Client supplies currency explicitly; the server validates it on first submission and compares against original on replay. HTTP does not accept occurred_at/configuration/scope/actor fields. Internal explicit occurredAt is copied from the original on replay and is not a mutable retry token. Conflict reports neither a new row nor changed balances.

NEW `Presentation/Services/RepositoryTransferHttpAdapter.php` owns HTTP-only authorization within the transaction: `transfer(TransferRepositoryRequest $request): RepositoryTransferResult`, `reverse(ReverseRepositoryTransferRequest $request, string $documentId): RepositoryTransferResult`, `show(Request $request, string $documentId): RepositoryTransferDocumentData`. It constructor-injects LocationScopeResolver, CompanyContext, operation lock, GeneralLedgerService and RepositoryTransferService; no HTTP resolver enters either application service. The service independently re-acquires the same locks, validates tenant/company/actor/semantic identity, and consumes explicit authorized scope on the intent. Internal callers supply a checked explicit authorization DTO, never a user-selected bypass. NEW `RepositoryTransferAuthorizationIntent::__construct(string $actorId, array $allowedSourceLocationIds, bool $companyWideAuthority)` has list<string> IDs; it is the required authorization parameter in RepositoryTransferIntent before the defaulted allowWhileFrozen. Record its first-submission result in TransferAuthorizationEvidenceData; recheck persisted current source under repository locks before money writes. W1 owns current membership/bypass semantics and revocation tests.


For a new operation generate a server group UUID independent of the user operation UUID; the company-operation unique owns retries. Existing movement keys at `TreasuryMovementService.php:296` are tenant-database-global, so using the client's operation UUID directly would incorrectly collide across companies. UUID collision must roll back and retry allocation before any committed effect; do not widen the existing movement key contract casually.

Mint optional JE through the existing factory before the movement port takes company/repository locks. Preserve tenant-numbering → company-GL → sorted repository lock order documented at `TreasuryMovementService.php:238`; do not introduce repository-before-numbering locking. After the port locks, revalidate active/type/currency and GL mapping against the selected draft; concurrent mapping drift aborts the whole operation. Snapshot evidence from locked rows, insert document after both legs, and commit once. Any failure rolls back document, legs, balances, ordinals and draft/post. Exact document replay runs before mutable freeze/checkpoint/active rules, after stored-source adapter authorization in the order above.

Same linked GL account: two opposite legs, no JE. Different linked GL accounts: exactly one posted JE, Dr destination/Cr source, equal amount. Retain existing behavior for both-unlinked repositories explicitly as no-GL operational custody; never treat one-null/one-linked as same GL. Future launch readiness may require linked accounts; this slice does not invent an owner accounting cutover.

No schema beyond T1. Do not change the raw movement source type or fiscal events. NEW config entry in `apps/api/config/treasury.php` (existing flag shape at :28): `repository_transfer_documents_enabled` reads `TREASURY_REPOSITORY_TRANSFER_DOCUMENTS_ENABLED`, default false. Both transfer and reversal services, including internal callers, refuse new writes when false; HTTP returns 503 repository_transfer_writes_disabled. The replacement has no fallback call to the old documentless writer. Configuration saves and document reads remain available for preparation/audit; flag-off transfer replay is a refused write request (503), with no mutation. After any document exists, code rollback may not restore the old writer. T5 renders the refused state and prevents submission. This flag does not enable variance or a queue.

Red-first contract register:

File **NEW unless already cited**: `apps/api/tests/Feature/Treasury/RepositoryTransferDocumentTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferDocumentTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `RepositoryTransferDocumentTest::test_same_gl_records_document_without_journal(): void` | Document count=1 and journal_entry_id null; exactly two legs, repository sums unchanged. |
| `RepositoryTransferDocumentTest::test_cross_gl_records_one_balanced_journal(): void` | Document JE non-null; one posted JE with debit=credit=amount and exact source/destination accounts. |
| `RepositoryTransferDocumentTest::test_retry_returns_original_document_without_writes(): void` | `assertSame(RepositoryTransferOutcome::AlreadyRecorded,$second->outcome)`; same ID, legs/JE/balances/ordinals unchanged. |
| `RepositoryTransferDocumentTest::test_changed_payload_conflicts(): void` | Provider source/destination/amount/currency/notes: conflict exception carries original document ID; no money changes. |
| `RepositoryTransferDocumentTest::test_different_actor_conflicts(): void` | Different authorized same-company actor: conflict with original ID, created_by unchanged, no new row. |
| `RepositoryTransferDocumentTest::test_configuration_change_does_not_change_retry_identity(): void` | Append different defaults after commit; identical same-actor retry AlreadyRecorded with original evidence configuration revision. |
| `RepositoryTransferDocumentTest::test_failure_after_legs_rolls_everything_back(): void` | Injected document-insert failure: assert complete pre-operation document/movement/JE/balance/ordinal snapshot equality. |
| `RepositoryTransferDocumentTest::test_second_company_can_reuse_operation_uuid(): void` | Real registered B: assert B-owned document/legs with distinct group, same client UUID, no A balance effect. |
| `RepositoryTransferDocumentTest::test_second_location_transfer_uses_selected_source(): void` | Assert out leg repository and document source location equal selected second branch. |
| `RepositoryTransferDocumentTest::test_disabled_flag_never_calls_documentless_writer(): void` | Flag false before first use AND after recorded document: expect write-disabled exception and identical money snapshot. |

Convention-09 at T3 gate: test_second_company_can_reuse_operation_uuid; test_second_location_transfer_uses_selected_source; test_retry_returns_original_document_without_writes (AlreadyRecorded). No T6 dependency. Existing transfer regression command also runs: `php artisan test -c phpunit.xml --filter=RepositoryTransferServiceTest`, lane backend-test / SQLite regression.

Implement only after capturing the named red failures, then rerun the exact commands to green. Test results are not claimed by this plan.

Gate: treasury-reviewer + tenancy-authz-reviewer inspect operation serialization, real monetary snapshots, GL lock order and company identity. Rollback: block new transfer submissions while rolling forward; never delete a recorded document or posted JE.

## T4 — Linked reversals and explicit frozen policy

Production anchors: wrapper rejects either frozen repository at `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:46`; single-leg port allows explicitly flagged record-and-alert at `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:91`; paired port currently hardcodes false at `:371`, `:391`, and event builder `:639`. Existing TransferIntent has no freeze/reversal fields (`apps/api/app/Modules/Treasury/Application/DTOs/TransferIntent.php:26`).

Extend that constructor, retaining its complete existing parameter order: `__construct(string $fromRepositoryId, string $toRepositoryId, string $tenantId, string $companyId, string $amount, string $currency, string $transferGroupId, ?string $journalEntryId, ?CarbonImmutable $occurredAt, ?string $createdBy, ?string $notes, bool $allowWhileFrozen = false, ?string $reversesOutMovementId = null, ?string $reversesInMovementId = null)`. Public transfer signature remains unchanged. Extend private `buildTransferLegEvent(string $movementId, string $repositoryId, TransferIntent $intent, MovementDirection $direction, string $balanceAfter, int $ordinal, ?string $journalEntryId, CarbonInterface $occurredAt, bool $recordedWhileFrozen): RepositoryMovementRecorded`; propagate the actual flag into both existing event instances without changing event structure.

NEW `Application/DTOs/RepositoryTransferReversalIntent.php`: `__construct(string $tenantId, string $companyId, string $originalDocumentId, string $operationUuid, string $actorId, ?string $sourceLocationId, string $explanation, CarbonImmutable $occurredAt, RepositoryTransferAuthorizationIntent $authorization)`.

Add `RepositoryTransferService::reverse(RepositoryTransferReversalIntent $intent): RepositoryTransferResult`. Full reversal only; reject reversal-of-reversal, partial amounts, duplicate reversal under a different operation, foreign original and unauthorized current source. Serialize original identity before operation lock consistently for reversal calls, then tenant-numbering/company lock; authorize the original destination as the reversal’s actual source before comparing actor/semantics. Same-operation reversal retry authorizes the recorded reversal source before returning or exposing a conflict. Swap original repositories, preserve original amount/currency, new operation/group/document, link reverses_document_id; new out reverses original in, new in reverses original out. Do not mutate original document or old JE. Require current GL mapping to equal original evidence; otherwise refuse with explicit correction-required error, preventing a misleading reversal under changed accounts. Respect current accounting-period/checkpoint and source-balance controls.

Interactive HTTP always constructs allowWhileFrozen=false and forbids that field in input. Remove the wrapper’s pre-replay freeze loop at RepositoryTransferService.php:46; move the decision into the paired movement port after replay detection and under both repository locks. A new interactive transfer to/from a frozen repository refuses atomically. Explicit internal allowWhileFrozen=true records both legs, marks only frozen legs and emits an after-commit structured warning per frozen leg with document/group/repository identifiers. This is a tested prerequisite contract only: no new worker, event subscription or device authority. Exact successful retry emits no duplicate money effects or alerts. The reversal explanation is first-submission semantic content: same operation with changed explanation or another authorized actor is 409 with the existing readable reversal ID. No negative-balance or checkpoint bypass is introduced. No new schema or cash-reason enum beyond T1.

Red-first contract register:

File **NEW unless already cited**: `apps/api/tests/Feature/Treasury/RepositoryTransferReversalTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferReversalTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `RepositoryTransferReversalTest::test_reverse_appends_linked_document_and_preserves_original(): void` | Assert reverses_document_id equals original ID; original row bytes/JE unchanged; exact opposite linked legs. |
| `RepositoryTransferReversalTest::test_repeat_reverse_returns_original_reversal(): void` | Assert AlreadyRecorded and same reversal ID; no extra document/leg/JE. |
| `RepositoryTransferReversalTest::test_second_reversal_operation_is_refused(): void` | Expect conflict with original reversal ID and unchanged monetary snapshot. |
| `RepositoryTransferReversalTest::test_mapping_change_refuses_reversal(): void` | Expect DomainException correction-required, no second document or journal. |
| `RepositoryTransferReversalTest::test_second_company_reversal_is_independent(): void` | Real company B reverses its own transfer; assert B IDs/amount restored, A unchanged. |
| `RepositoryTransferReversalTest::test_second_location_reversal_uses_current_outflow_custody(): void` | Explicit intent for allowed second-branch source restores correct two repositories; denial variant leaves all snapshots unchanged. |
| `RepositoryTransferReversalTest::test_reversal_actor_and_explanation_conflict(): void` | Provider different authorized actor/changed explanation on same operation: expect conflict with existing reversal ID, no extra leg. |

File **NEW unless already cited**: `apps/api/tests/Feature/Treasury/RepositoryTransferFrozenPolicyTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferFrozenPolicyTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `RepositoryTransferFrozenPolicyTest::test_explicit_frozen_destination_records_and_alerts(): void` | Destination leg recorded_while_frozen=true and exactly one after-commit warning with document/group/repository ID. |
| `RepositoryTransferFrozenPolicyTest::test_interactive_frozen_destination_has_no_effect(): void` | Expect RepositoryFrozenException; document/movement/GL/balance/ordinal snapshot identical. |
| `RepositoryTransferFrozenPolicyTest::test_retry_after_freeze_returns_original(): void` | Same actor retry AlreadyRecorded with same document/leg IDs and zero newly emitted warnings. |

Convention-09 at T4 gate: RepositoryTransferReversalTest::test_second_company_reversal_is_independent; ::test_second_location_reversal_uses_current_outflow_custody; ::test_repeat_reverse_returns_original_reversal. Freeze replay outcome explicitly AlreadyRecorded.

Implement only after capturing the named red failures, then rerun the exact commands to green. Test results are not claimed by this plan.

Gate: treasury-reviewer + tenancy-authz-reviewer inspect compensation direction, current source custody, immutable original and non-client-settable freeze intent. Rollback: disable new reversal submissions; preserve all original/reversal evidence and money rows.

## T5 — Existing HTTP and web surfaces, generated types

Verified production files: `apps/api/app/Modules/Treasury/Presentation/routes.php:35`, `:102`; transfer controller `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:21`; request `apps/api/app/Modules/Treasury/Presentation/Requests/TransferRepositoryRequest.php:21`; repository controller `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:420`; `apps/web/src/features/treasury/hooks/useTransferCash.ts:5`; `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:7`; `apps/web/src/features/treasury/RepositoryListPage.tsx:23`; `apps/web/src/features/treasury/RepositoryDetailPage.tsx:27`; `apps/web/src/features/treasury/PaymentForm.tsx:60`; `apps/web/src/features/treasury/components/TransferCashModal.tsx:28`, `:84`; existing web module wrappers `apps/web/src/routes/index.tsx:1937`.

**Hard prerequisite W1-T-CUSTODY-AUTHZ:** the accepted W1 source-custody/permission task implementing spec `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:139` and permission rollout at `:147`. Before T5 starts, dispatch must pin its implementation SHA, treasury-reviewer + tenancy-authz-reviewer acceptance, seeded `treasury.manage_all_locations`/general_manager behavior and existing-tenant cache/grant evidence. The current treasury permission catalogue at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:260` ends its relevant action set at :266 without this bypass. No temporary permission and no silent seeder scope absorption in WCASH-1. T1–T4 may gate against explicit service intents, but no T5 HTTP green or activation before the accepted W1 prerequisite. This is a named external task prerequisite, not a claim that its implementation already exists. Broad human-index restriction still waits for W1/W2's device transition; new transfer/config modes enforce source custody immediately.

Keep POST `/api/v1/payment-repositories/transfers`; route store now calls RepositoryTransferHttpAdapter::transfer. `TransferRepositoryRequest::authorize(): bool` checks treasury.transfer; complete `rules(): array`: operation_uuid = required_without:transfer_group_id, uuid; transfer_group_id = nullable, uuid, deprecated operation alias; from_repository_id = required, uuid; to_repository_id = required, uuid, different:from_repository_id; amount = required, string, numeric, gt:0, regex `/^\d+(\.\d{1,3})?$/`, max:16 with decimal(15,3) overflow checked explicitly in service; currency = required, string, size:3, regex `/^[A-Z]{3}$/`; notes = nullable, string, max:1000. If both UUID names are present require equality in `after(): array` validation. Unknown fields are rejected; explicitly prohibit allowWhileFrozen/allow_while_frozen, actor_id, company_id, tenant_id, source_location_id, configuration_id/revision, occurred_at and kind. No silent identity generation. Legacy alias without currency is a 422, not a guessed retry; web deploy precedes activation.

NEW `apps/api/app/Modules/Treasury/Presentation/Requests/ReverseRepositoryTransferRequest.php`: `authorize(): bool` = treasury.transfer; `rules(): array`: operation_uuid required|uuid, explanation required|string|max:1000 plus not-whitespace validation; all other fields prohibited, including amount/destination/actor/freeze/occurred_at. Original document ID comes solely from the UUID-validated route. Server chooses current occurred_at at first submission, preserving it on retry.

NEW `apps/api/app/Modules/Treasury/Presentation/Requests/SaveCashCustodyRequest.php`: `authorize(): bool` = repositories.manage; `rules(): array`: location_id required|uuid, default_safe_repository_id present|nullable|uuid, default_bank_repository_id present|nullable|uuid|different:default_safe_repository_id, enabled required|boolean, expected_revision required|integer|min:0. enabled=true adds required_if:enabled,true to safe. Unknown/authority fields prohibited. Do not use unscoped exists validators: adapter scopes route drawer and location first (404), then service validates owned/type-compatible defaults (422). A permitted route drawer's persisted location must equal location_id or return 422 without disclosing foreign location information.

Controller additions retain signatures: `RepositoryTransferController::show(Request $request, string $documentId): JsonResponse`, `reverse(ReverseRepositoryTransferRequest $request, string $documentId): JsonResponse`; `PaymentRepositoryController::saveCashCustody(SaveCashCustodyRequest $request, string $id): JsonResponse`. Add GET `/payment-repositories/transfers/{documentId}`, POST `/payment-repositories/transfers/{documentId}/reverse`, PUT `/payment-repositories/{id}/cash-custody`, UUID route constraints; fixed paths precede generic repository `{id}`. Preserve api/auth:sanctum/SetPermissionsTeam/EnforceTokenTenantClaim (`apps/api/app/Modules/Treasury/Presentation/routes.php:35`) and add `module:Treasury` to in-scope reused routes. RequireModule is case-sensitive (`apps/api/app/Http/Middleware/RequireModule.php:24`). Do not gate unrelated customer-account deposits as collateral work.

Index mode contract on GET `/payment-repositories`: omitted `mode` retains existing response for compatibility/W1 rollout; `mode=transfer_source` returns active physical repositories for authorized current source custody; `mode=transfer_destination&from_repository_id=<uuid>` first scopes source then returns only authorized branch destinations or its explicitly configured central safe/bank. Unknown mode = 422; destination mode requires UUID source; source mode prohibits from_repository_id. Both transfer modes require treasury.transfer + module; configuration edit still requires repositories.manage and W1 custody authority. Both return `{data: RepositoryDestinationData[]}` where NEW `RepositoryDestinationData::__construct(string $id, string $name, RepositoryType $type, string $currency)` contains no balances/account/IBAN fields. Do not feed destination-mode data into full repository/balance caches. Query keys include mode and source ID in addition to tenant/company suffixes.

Resolve allowed IDs only in HTTP via `resolve($user, [], 'treasury.manage_all_locations')`; query persisted source under tenant/company/allowed location and use findOrFail. Unlocated/central source requires company-wide authority. On replay authorize stored source first as T3 defines, never the possibly substituted request source. Inbound central eligibility does not authorize reverse outflow. For document reads require repositories.view and authority over BOTH the historical source location and current source repository (or company-wide authority); destination-only authority does not grant full document access. The same rule applies to links discovered through either repository's history; suppress unreadable document links/embedded evidence there and return 404 on direct ID access. Full evidence may reference an allowed central destination but never expose that repository's bank credentials or balance; document response uses movement IDs rather than destination balance. Transfer success leg balance fields are source-authorized only: use a generated nullable balance_after on the destination leg when destination custody is not readable, and return null. No second catalogue or implicit central read permission.

HTTP envelopes/statuses: new transfer/reversal = 201 `{message,data:RepositoryTransferResponseData}` with outcome recorded; same-actor exact replay = 200 same envelope with already_recorded and same document ID; document GET = 200 `{data:RepositoryTransferDocumentData}`; configuration real save = 200 `{data:CashCustodySaveResult}` with saved, identical save = 200 unchanged. Semantic/actor conflict = 409 `{message,error:{code:"repository_transfer_conflict",document_id:<existing-readable-id>}}`; stale different configuration = 409 `{message,error:{code:"cash_custody_revision_conflict",current_revision:int}}`. Known accessible legacy alias/group with no document = 409 `{message,error:{code:"legacy_transfer_document_unavailable",document_id:null}}`, zero new money; inaccessible legacy source = 404 before that result. Existing reversal under a new operation = 409 existing-readable reversal ID. Validation = 422 `{message,errors:{field:[message]}}`; scoped miss = 404 `{message:"Not found"}` without IDs; action/module denied = 403; unauthenticated = 401; frozen/inactive/type/currency/checkpoint/balance refusal = 422 with stable code/message and no writes; flag false = 503 `{message,error:{code:"repository_transfer_writes_disabled"}}`. No original document ID in unauthenticated/forbidden/scoped-miss responses. Service conflict exception carries ID only after adapter source authorization.

NEW transport constructors: `RepositoryTransferRequestData::__construct(string $operation_uuid, string $from_repository_id, string $to_repository_id, string $amount, string $currency, ?string $notes)`; `RepositoryTransferResponseData::__construct(RepositoryTransferDocumentData $document, RepositoryTransferOutcome $outcome, string $transfer_group_id, ?string $journal_entry_id, bool $idempotent_replay, RepositoryTransferLegData $out, RepositoryTransferLegData $in)`; `RepositoryTransferLegData::__construct(string $movement_id, ?string $balance_after, string $repository_id)`. Keep normalized original content including free-text notes as the semantic reason; no Q12 reason mapping in this slice.


NEW `Application/DTOs/PaymentRepositoryData.php` owns the existing formatted repository response at PaymentRepositoryController.php:420: constructor `__construct(string $id, string $code, string $name, RepositoryType $type, bool $allow_negative, ?string $bank_id, ?string $bank_name, ?string $account_number, ?string $iban, ?string $bic, string $balance, string $currency, bool $is_active, ?string $gl_account_id, ?RepositoryGlAccountData $gl_account, ?string $location_id, ?string $location_name, RepositoryBankValidationData $bank_account_validation, ?CashCustodyConfigurationData $cash_custody)`. Nested NEW DTOs: `RepositoryGlAccountData::__construct(string $id, string $code, string $name)`; `RepositoryBankValidationData::__construct(?RibValidationResult $rib, ?IbanValidationResult $iban, ?bool $bic_valid)` using existing validator result types. Export their nested shapes too; do not add an unwritten is_default field just because the local hook currently declares it (`usePaymentRepositories.ts:13`). These response DTOs are not stored JSON columns.

Replace ALL repository-response shadows, including the following verified census, with generated imports or `Pick<PaymentRepositoryData,...>` aliases (not re-declared fields). Census paths/anchors: `apps/web/src/features/treasury/RepositoryListPage.tsx:23`, `RepositoryDetailPage.tsx:27`, `PaymentForm.tsx:60`, `SplitPaymentForm.tsx:27`, `hooks/usePaymentRepositories.ts:7`, `hooks/useRemittances.ts:9`; `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:28`; `apps/web/src/components/organisms/AddRepositoryModal/AddRepositoryModal.tsx:41`; `apps/web/src/features/pos/api/paymentRepositoryApi.ts:9`; `apps/pos/src/types/payment.ts:27`. Replace the repository request response generic in `apps/web/src/features/treasury/InstrumentDetailPage.tsx:134` with a generated Pick; do not redefine its generic Relation type globally for unrelated entities. Update generated-type imports through `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:185`, `apps/pos/src/api/paymentApi.ts:9`, `apps/pos/src/lib/sync/syncService.ts:1256` and the transfer modal at `apps/web/src/features/treasury/components/TransferCashModal.tsx:15`.

Device SQLite storage shape is explicitly a serialization adapter, not a second entity: replace `PaymentRepositoryRow` at `apps/pos/src/lib/db/repositories/paymentRepository.ts:24` with `Omit<PaymentRepository,'is_active'|'type'> & {is_active:number;type:string}` where PaymentRepository is the generated-field Pick matching the existing cached field set. Keep existing row conversion and queries unchanged (:58); do not add currency/new fields to old SQLite records or alter sync/picking behavior. POS changes here are type-only, with no new binary/device build and no W2 projection or scoping activation. Re-run consumer census for `/payment-repositories` and type names before the T5 gate; every response structural shadow found must be converted, and intentional storage/form-only shapes recorded as generated-derived aliases. Replace local transfer request/response shapes at `apps/web/src/features/treasury/hooks/useTransferCash.ts:5` too. AddRepository form inputs use generated-derived fields (e.g. mapped non-null string form values over the generated Pick), with selected_bank/bank_fallback as explicit local UI state; do not type empty-string form defaults as non-null entity values. These form/storage adaptations are declared representations, not separate response DTOs.

Add literal DOM attribute `data-feature="wcash-1-custody-transfer-v2"` to RepositoryDetailPage's custody/document section so the shipped served bundle carries the manifest fingerprint. Keep one modal, add recorded/replayed document link, render document in existing repository detail. Preserve operation UUID across transport retries; after an ambiguous failure freeze the submitted intent until retry resolves, so editing amount does not silently reuse an uncertain operation. On tenant/company change close/reset modal and discard stale response. Use apiPost's already-unwrapped result if migrating from raw api, not a second .data unwrap; the current raw hook is not itself proof of double-unwrapping (`useTransferCash.ts:26`). Keep money strings, MoneyInput, translation keys in `apps/web/src/locales/{en,fr,ar}/treasury.json`, tenantScopedKey reads and correct invalidations. Existing repository routes already have moduleKey=treasury; preserve it and gate new action fields/buttons by permission/module too. No migration or enum beyond T1.

Red-first contract register:

File **NEW unless already cited**: `apps/api/tests/Feature/Treasury/RepositoryTransferEndpointTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferEndpointTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `RepositoryTransferEndpointTest::test_other_branch_source_is_scoped_not_found(): void` | `assertNotFound()` then unchanged document/movement/GL/balance/ordinal snapshots. |
| `RepositoryTransferEndpointTest::test_foreign_branch_operation_uuid_is_not_an_oracle(): void` | Known B UUID + permitted A request source: assert404 and no document_id anywhere, unchanged snapshots. |
| `RepositoryTransferEndpointTest::test_same_actor_retry_after_configuration_change_returns_document(): void` | After revision change, assertOk and outcome already_recorded with original document/evidence ID. |
| `RepositoryTransferEndpointTest::test_other_authorized_actor_gets_conflict(): void` | Assert409 with error.code repository_transfer_conflict and original readable document_id, no writes. |
| `RepositoryTransferEndpointTest::test_central_destination_does_not_grant_reverse_outflow(): void` | A→configured central succeeds; reverse by same A-only user assert404, unchanged reverse snapshot. |
| `RepositoryTransferEndpointTest::test_module_off_refuses_transfer(): void` | Assert403 with Treasury disabled and no rows changed. |
| `RepositoryTransferEndpointTest::test_client_cannot_enable_frozen_override(): void` | Assert422 for either freeze field and no new documents/legs/JE. |
| `RepositoryTransferEndpointTest::test_destination_mode_omits_bank_details(): void` | Assert exact data item keys id/name/type/currency, with no bank/balance fields. |
| `RepositoryTransferEndpointTest::test_document_read_requires_source_authority_from_either_history(): void` | Source-authorized GET=200; destination-only GET=404 and history omits unreadable links/evidence. |
| `RepositoryTransferEndpointTest::test_request_status_contract(): void` | Provider 201/200/409/422/404/403/401/503 cases from T5 contract: assert exact status, envelope/error code and relevant unchanged snapshot. |
| `RepositoryTransferEndpointTest::test_real_second_company_http_transfer_is_independent(): void` | Register/create B through real APIs: assert B leg IDs and same operation UUID independent of A. |
| `RepositoryTransferEndpointTest::test_second_location_http_configuration_and_transfer(): void` | Assert saved configuration second location/default and outgoing document/leg selected source; first branch unchanged. |
| `RepositoryTransferEndpointTest::test_exact_http_retry_has_explicit_outcome(): void` | Assert200 and data.outcome=already_recorded, same document ID and snapshot. |

Named lane **web-test / WCASH-1 Vitest**, exact command from apps/web: `pnpm exec vitest run src/features/treasury/hooks/useTransferCash.test.ts src/features/treasury/RepositoryListPage.transferIntegration.test.tsx src/features/treasury/components/TransferCashModal.test.tsx src/features/treasury/RepositoryDetailPage.custody.test.tsx`.

| Exact file and Vitest test name | First failing assertion |
|---|---|
| `apps/web/src/features/treasury/hooks/useTransferCash.test.ts` — `useTransferCash > returns document and explicit replay outcome` | Assert returned document.id and already_recorded without extra .data unwrap. |
| `apps/web/src/features/treasury/RepositoryListPage.transferIntegration.test.tsx` — `RepositoryListPage > retries one operation and opens its document` | Assert same operation UUID on two calls and document link ID. |
| `apps/web/src/features/treasury/components/TransferCashModal.test.tsx` — `TransferCashModal > central destination renders no balance` | Assert fixture central balance/account values absent and permitted name present. |
| `apps/web/src/features/treasury/RepositoryDetailPage.custody.test.tsx` — `RepositoryDetailPage > saves second-location custody defaults` | Assert outgoing second location/default UUIDs, repeat outcome unchanged. |
| Same new custody test file — `RepositoryDetailPage > company switch discards pending transfer` | Assert no stale prior-company document or submitted intent remains after switch. |

Named lane **frontend-types / web+POS compile**: from apps/api `php artisan typescript:transform`; from repo root `pnpm --filter @autoerp/web typecheck` and `pnpm --filter @autoerp/pos typecheck`. NEW compile-only fixtures `apps/web/src/features/treasury/__tests__/repositoryGeneratedContract.typecheck.ts` and `apps/pos/src/types/paymentRepositoryGeneratedContract.typecheck.ts` use type-level Assert/IsEqual over actual exported aliases (no runtime any): first failing compile assertion is equality to the generated Pick, including RepositoryType. Repeat generation must produce identical generated content. Type-only POS change requires no device build. Named lane **pos-test / WCASH-1 storage regression**: NEW `apps/pos/src/lib/db/repositories/paymentRepository.generatedContract.test.ts`, Vitest `paymentRepository > preserves cached row conversion after generated alias migration`, first assertion deepEquals the pre-change field/value fixture; command from apps/pos `pnpm exec vitest run src/lib/db/repositories/paymentRepository.generatedContract.test.ts`.

Convention-09 at T5 gate: RepositoryTransferEndpointTest::test_real_second_company_http_transfer_is_independent; ::test_second_location_http_configuration_and_transfer; ::test_exact_http_retry_has_explicit_outcome. UI rerun explicitly unchanged/already_recorded; accepted W1 prerequisite must be pinned before these HTTP gates.

Implement only after capturing the named red failures, then rerun the exact commands to green. Test results are not claimed by this plan.

Gate: treasury-reviewer + tenancy-authz-reviewer, plus frontend-conventions review and applicable React Doctor finishing workflow during implementation. Rollback: disable new action UI/HTTP writes together; retain document read access and schema. Do not revert to a documentless writer after first production use.

## T6 — PostgreSQL proof and deployment handback

Verified anchors: PG lane `apps/api/phpunit-pgsql.xml:1`; real registration endpoint fixture `apps/api/tests/Feature/Tenant/TenantInitializationTest.php:158`; real second-company path `apps/api/tests/Feature/Treasury/CompanyPaymentRepositoryProvisioningTest.php:95`; ratchet `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:212`, `:254`. Production schema is T1; no additional columns or enums.

Red-first contract register:

File **NEW unless already cited**: `apps/api/tests/Feature/Treasury/CashCustodySecondOfEverythingTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=CashCustodySecondOfEverythingTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `CashCustodySecondOfEverythingTest::test_registered_second_company_can_reuse_operation_uuid(): void` | Assert B document company_id=B with distinct group and same UUID; register A via auth/register, B via companies. |
| `CashCustodySecondOfEverythingTest::test_selected_second_pos_location_keeps_its_own_custody(): void` | Assert real API-created second pos_enabled location owns configured drawer/safe and transfer attribution. |
| `CashCustodySecondOfEverythingTest::test_rerun_configuration_and_transfer_are_explicit(): void` | Assert Unchanged for configuration and AlreadyRecorded for transfer, same IDs/counts/balances. |

File **NEW unless already cited**: `apps/api/tests/Integration/Treasury/RepositoryTransferDocumentConcurrencyTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferDocumentConcurrencyTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `RepositoryTransferDocumentConcurrencyTest::test_concurrent_same_operation_commits_one_document(): void` | Independent committed connections: count=1, exactly two legs/zero-or-one JE, outcomes Recorded and AlreadyRecorded. |
| `RepositoryTransferDocumentConcurrencyTest::test_opposing_transfers_preserve_lock_order(): void` | Assert both complete before timeout without deadlock and exact net balances/ordinals. |
| `RepositoryTransferDocumentConcurrencyTest::test_concurrent_reverse_commits_once(): void` | Assert one reversal document; same operation produces Recorded/AlreadyRecorded, different operations produce one 409 conflict. |
| `RepositoryTransferDocumentConcurrencyTest::test_company_uuid_reuse_does_not_collide(): void` | Assert independent two-company documents/groups and unchanged other-company balances on retry. |

File **NEW unless already cited**: `apps/api/tests/Integration/Treasury/CashCustodyTopologyTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=CashCustodyTopologyTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `CashCustodyTopologyTest::test_db_per_tenant_and_compatibility_scope_are_equivalent(): void` | Provider explicitly sets topology true/false: foreign tenant lookup 404; both DB snapshots unchanged; direct wrong-tenant/actor inserts SQLSTATE23503. |
| `CashCustodyTopologyTest::test_migrations_are_repeatable_and_empty_down_up_is_safe(): void` | On isolated empty DB assert first apply succeeds, second Nothing to migrate with identical definitions; down/up only empty restores same FK/trigger set. |

Ratchet lane **backend-test-pgsql / architecture**: existing `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php`, `TenantOnlyUniqueOnCatalogueTablesRatchetTest::every_table_with_a_qualifying_unique_is_explicitly_classified(): void`, first fail requires classification of new tables; `::live_tenant_only_unique_indexes_match_the_reviewed_baseline(): void`, first assert no new violations. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=TenantOnlyUniqueOnCatalogueTablesRatchetTest`. Add cash_custody_configurations to catalogue classification and repository_transfer_documents to immutable financial evidence exclusion; retain all proposed company-operation uniques, no ceiling or waiver increase.

Named lane **web-e2e / private WCASH-1 stack**, NEW `apps/web/e2e/treasury-cash-custody-transfer.spec.ts`, test `second branch custody and transfer evidence`; first assertion after configure/transfer is visible document ID and selected second branch, then retry retains ID, reversal links original, wrong branch is denied and company switch clears state. Exact focused command from repo root: `pnpm --dir apps/web exec playwright test e2e/treasury-cash-custody-transfer.spec.ts`. Private API :8011 and Vite :5174 as manifest §3; outputDir outside Vite root. Use seeded worker-backed or declared sync local fixture and real user journeys, not an MCP shared browser.

Convention-09 at T6 gate: CashCustodySecondOfEverythingTest::test_registered_second_company_can_reuse_operation_uuid; ::test_selected_second_pos_location_keeps_its_own_custody; ::test_rerun_configuration_and_transfer_are_explicit. Concurrency tests use committed fixture/processes and barriers; inability to execute PG is failure/incomplete, never a SQLite substitute. DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD/DB_DATABASE/DB_CENTRAL_DATABASE must target isolated lane databases. No testing performed in this planning round.

Local host verification: `PREFLIGHT_TEST_PATHS='tests/Feature/Treasury/CashCustodySchemaTest.php tests/Feature/Treasury/CashCustodyConfigurationTest.php tests/Feature/Treasury/CashCustodyCensusCommandTest.php tests/Feature/Treasury/RepositoryTransferDocumentTest.php tests/Feature/Treasury/RepositoryTransferReversalTest.php tests/Feature/Treasury/RepositoryTransferFrozenPolicyTest.php tests/Feature/Treasury/RepositoryTransferEndpointTest.php tests/Feature/Treasury/CashCustodySecondOfEverythingTest.php' ./scripts/preflight.sh`; execute the separate focused PG integration commands above too. Backend-test SQLite regression: `php artisan test -c phpunit.xml --filter='RepositoryTransferServiceTest|RepositoryTransferEndpointTest'` from apps/api. Full suite is VPS/CI, following the manifest host-scope rule: `pnpm build`, `pnpm lint`, `pnpm test`, `pnpm typecheck`, `pnpm --filter @autoerp/web test:e2e`, and from apps/api `composer test`, `./vendor/bin/phpstan`, `./vendor/bin/pint --test`. Attach actual errors/skips and screenshots; a skipped PHPUnit run is not green.

Gate: treasury-reviewer + tenancy-authz-reviewer approve actual PG evidence, second-company registration and denial snapshots. Rollback: stop submissions; preserve all committed evidence; reverse business mistakes only via authorized compensation. NEW handback `docs/handoff/HANDBACK-WCASH-1-2026-09-06.md` records implementation SHA, red/green commands, screenshots, migration/census output and reviewer findings. No implementation or reviewer gate was run for this planning assignment.

## Deployment — canonical manifest variables

> Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
> (five-push sequence §2, web block §3, gate checklist §4). This slice supplies only the variables
> below; it does not restate deploy mechanics.

| Variable | WCASH-1 value |
|---|---|
| `<slice>` | `wcash-1` |
| **Migrations list** | `apps/api/database/migrations/tenant/2026_09_06_210000_create_cash_custody_configurations.php` then `apps/api/database/migrations/tenant/2026_09_06_210100_create_repository_transfer_documents.php`. Both additive and self-guarding; 210000 requires companies/users/locations/payment_repositories with tenant/company columns; 210100 requires 210000 + repository_movements + journal_entries. Each verifies parent shapes/owned composites and fails before DDL on incompatibility; no historical money backfill. |
| **Flags** | NEW `treasury.repository_transfer_documents_enabled` / `TREASURY_REPOSITORY_TRANSFER_DOCUMENTS_ENABLED` / `apps/api/config/treasury.php` / false. False REFUSES new transfer/reversal requests and all service callers, never falls back to documentless writes. Existing `treasury.shift_variance_gl_enabled` / `TREASURY_SHIFT_VARIANCE_GL_ENABLED` remains false/unchanged (`apps/api/config/treasury.php:28`); no consumer flag is enabled. |
| **Commands** | NEW `treasury:census-cash-custody {--tenant=} {--company=} {--require-ready} {--json}`, direct API-container execution, NOT tenants:run, manages tenancy itself; marker `WCASH-1-CUSTODY:`. Push 1 ships its read-only service/DTO dependencies and absent-table handling; T1 schema is not a Push-1 dependency. Status/aggregate contract is T2. No new repair/backfill command. |
| **Censuses** | Push 1 and Push 4: `tenant:census-day-one` under tenants:run, require DAY-ONE CENSUS coverage and zero DRIFT(; `pos:census-vat-legs` under tenants:run with every per-tenant result captured: success grep `POS output-VAT leg census: none` (`apps/api/app/Console/Commands/PosReceiptVatLegCensusCommand.php:336`); fail grep `POS output-VAT leg census: [1-9][0-9]* receipt` (`:342`) or missing target verdict/operational error. Capture Push-1 debt; Push-4 activation requires no unresolved failures, not merely unchanged counts. WCASH direct command baseline P1 permits NOT_READY but not ERROR/incomplete; P4 adds --require-ready and must be READY. For compact JSON: grep `"marker":"WCASH-1-CUSTODY:"` and `"complete":true`; fail on `"status":"ERROR"`, and at P4 fail on `"status":"NOT_READY"`; expected_companies must equal visited_companies. Baseline/new missing-bank IDs are reviewed separately; no silent safe-readiness coercion. Lot/phantom/paid-never-posted repair censuses out of this slice; existing global manifest gates still apply. |
| **Web changes** | YES. Execute canonical §3 web deploy block in full, `<slice>=wcash-1`, `<slice-unique-string>=wcash-1-custody-transfer-v2`; record before/after served asset hash and fingerprint grep >=1. Explicit web deploy before Push-5 write enablement; no build-fingerprint.json assumption. |
| **Device build** | NO. `apps/pos` edits are compile-time generated-type aliases/serialization aliases only, no changed device behavior or Tauri build. |
| **Queues** | NONE. No onQueue, listener/projector registration or Horizon change; verify API/worker flag parity through canonical sequence. |
| **Collapsed pushes** | NONE. P1 read-only census/DTOs; P2 schema; P3 dormant replacement writer/default-false + web; P4 configuration through existing surface plus census/PG/smoke evidence (no historical backfill); P5 activation. Implementation T1–T6 task order is distinct from packaging order; prepare P1 artifacts from accepted T2 before any schema push. |
| **Env path** | Selected candidate: separate Dokploy applications' Environment tabs for API/worker/scheduler. **NOT VERIFIED; U-1 blocks promotion until actual app topology and env delivery path are recorded.** If U-1 proves compose, replace this value with x-api-env and include both declared env names in `docker-compose.staging.yml`; canonical manifest §2's extra env-plumbing push then applies. No claim of a verified env path without environment evidence. |
| **Host-side backup** | Canonical §2 backup-before / row I, host file `/root/backup-wcash-1-<UTC>.dump` per actual target database (include DB identity in filename for multiple DBs), non-zero and restore-readable outside container before migrating Push 2 and any Push-4 writes. Resolve host/database/container credentials via manifest U-5; compatibility mode requires backup of the shared physical database, not invented tenant_<uuid> names. |
| **Rollback point per push** | P1 revert read-only tooling; P2 retain additive schema/forward correction only; P3 false flag and maintenance refusal, no old writer restoration if documents exist; P4 preserve saved revisions and append correcting revisions, no financial backfill to reverse; P5 false flag REFUSES writes, retains reads/documents/legs/JE and cannot reactivate legacy implementation. These specialize the canonical rollback points, not a separate deploy sequence. |

**Promotion preconditions:** U-1 must resolve real topology and prove actual in-container flag values; U-2 must prove `tenancy_resolver.db_per_tenant` in staging and that migrations ran in the actual topology (rolling migration no-op is not success). Record both as environment evidence in the implementation handback before any migrating push. U-5 must resolve backup target too. They are operational facts, not owner decisions; this read-only plan round does not claim to verify live staging. W1-T-CUSTODY-AUTHZ accepted SHAs and tenant permission/cache rollout are additional T5/activation prerequisites. During P3–P5, the replacement writer refuses writes while false, so stale web cannot call the old path. No device/shift/variance activation follows from this slice.

### Canonical manifest §4 gate checklist — verbatim

- [ ] **Onboarding campaign GREEN** — `scripts/campaign-onboarding.sh` (local) or
      `scripts/campaign-onboarding.sh --web https://erp.otospex.dev --api https://api.erp.otospex.dev --country TN`
      (`docs/qa/ONBOARDING-CAMPAIGN.md:5-22`; flags `scripts/campaign-onboarding.sh:9-29`).
      Promotion reads the **ledger**, not the exit code (`ONBOARDING-CAMPAIGN.md:3`); the target
      must run a worker consuming `imports` + `fiscal-projections` (`:49`); registration is
      throttled and every run leaves a tenant behind (`:50,53`).
- [ ] **Day-one census CLEAN** — `php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1'`,
      every verdict line clean; grep `DAY-ONE CENSUS` / `DRIFT(` because `tenants:run` discards exit
      codes (`docs/handoff/RUNBOOK-day-one-census.md:7-9,25`). CLAUDE.md rule 22.
- [ ] **Promotion-checklist rows** — migrations enumerated, each declared self-guarding
      (`PROMOTION-CHECKLIST-2026-08-26.md:22-67`); non-self-running seeders listed (`:69-74`);
      post-deploy censuses run (`:84-90`); Horizon queue coverage confirmed (`:94`).
- [ ] **Preflight green at host scope** — `PREFLIGHT_TEST_PATHS='…' ./scripts/preflight.sh` on the
      laptop; full suite is VPS/CI only (`WORKFLOW.md:36,147-162`). A `paths` run with **no** paths
      skips PHPUnit and is not a green.
- [ ] **dev-push-guard behaviour understood** — force-push to `dev` denied; a behind/diverged local
      `dev` denied with the exact reconcile command (`.claude/hooks/git-dev-push-guard.sh:73-88`).
      Commit and push are separate Bash calls.
- [ ] **Fast-forward-only promotion** — `git log --oneline dev..origin/dev | wc -l` is `0` before
      promoting; never rewrite shared history (`PROMOTION-CHECKLIST-2026-08-26.md:15-20`; CLAUDE.md rule 21).
- [ ] **Backup taken on the host and verified non-zero** before any migrating/backfilling push (row I).


## Dispatch order and verification checklist

Dispatch sequentially: **T1 → T2 → T3 → T4 → T5 → T6**. Each task starts with its named red assertion and its own convention-09 cases, ends with green evidence and treasury-reviewer + tenancy-authz-reviewer gate. Coordinate shared files with W1 before editing; no parallel writers to transfer/controller contracts. Use an isolated implementation worktree; this planning assignment creates no branch or git write. T5 is held until W1-T-CUSTODY-AUTHZ has accepted implementation/reviewer SHAs; no policy question needs reopening.

- [ ] Reverify baseline SHA and every existing seam; all new paths remain clearly labelled proposals until implemented.
- [ ] Nine benchmark rows and two glossary additions retained; Q11–Q13 verbatim and RULED; ruled policies deferred to later slices.
- [ ] Complete schemas, typed JSONB evidence, enum kind/outcomes, company FKs/uniques and append-only guards proven on PG.
- [ ] Every active drawer location has a valid enabled safe configuration; missing bank is visible; no guessed default.
- [ ] Real registration, real second company, second location and explicit re-run outcomes proven by data.
- [ ] One document, one group, exactly two cross-linked legs; same GL zero JE, cross GL one posted balanced JE.
- [ ] Conflicting/concurrent retries, partial failure, changed GL mapping, reversed source custody and legacy alias retries tested.
- [ ] Original preserved; exactly one linked full reversal; original and compensating JE remain auditable.
- [ ] Explicit frozen internal transfer records/alerts; HTTP cannot request the override; replay creates no duplicate alerts.
- [ ] Branch-source 404 leaves document/movement/GL/balance/ordinal snapshots unchanged; central destination exposes names/IDs only.
- [ ] Reused routes/actions module-gated; HTTP alone resolves location authority; console/internal intents carry explicit scope.
- [ ] Generated DTOs replace local repository/transfer interfaces; one repository surface; stable UUID retry and company-switch behavior verified.
- [ ] PG ratchet, meaningful concurrency tests, preflight, web E2E, campaign and census evidence accepted; manifest variables and U-1/U-2 evidence completed.
- [ ] No shift booking, adapters, W7/variance enablement, cash-reason policy, drawer-session model or historical alignment shipped in this slice.

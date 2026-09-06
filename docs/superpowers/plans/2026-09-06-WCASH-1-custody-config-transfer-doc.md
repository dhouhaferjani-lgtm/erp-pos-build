# Slice plan W-CASH-1 — per-location cash custody configuration and repository transfer document (rev 1)

Evidence baseline: `3d27e356bb5bc72ab708751d480548040516c146`, local HEAD inspected on 2026-09-06. All statements about current code below refer to this SHA; all NEW paths, signatures and schemas are proposals, not shipped behavior. Local HEAD advanced concurrently to `23af79379661ef3dd9f0753c9ddafb1439636619` during drafting, including a later owner-ruling pass. This plan deliberately preserves the requested OPEN rulings at its pinned baseline; reconcile the newer authority before implementation without expanding this slice. Rebase and reverify citations before implementation. `TASKS.md` and the shared staging push manifest were absent. This assignment produces only this plan artifact: no production edits, tests, commits, merges or pushes.

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
| B9 audit | Evidence identifies actor, legs and accounting effect | Accounting entries | Operational document and ledger effect | Account records; exact evidence NV | `apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:45` is append-only; `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:41` returns group/JE/legs | No immutable action record | MATCH — T1/T3/T4 |

Vocabulary — convention 11: **Repository** exists (`docs/glossary.md:60`), canonical surface Treasury → Repositories. **Cash custody configuration** is NEW: `cash_custody_configurations`, Treasury, sole writer `CashCustodyConfigurationService`, a location setting mode on that existing surface; synonym “custody defaults.” **Repository transfer document** is NEW: `repository_transfer_documents`, Treasury, sole writer `RepositoryTransferService`, existing transfer modal and repository detail history; synonym “transfer justification.” Add both glossary rows in T1. A revision is history of the same configuration, not another catalogue. A reversal is another repository transfer document, not a separate concept/table.

Second-of-everything — convention 09: T2/T6 prove real second-company creation, selected second location and explicit unchanged/replayed outcomes. No tenant-only new catalogue unique and no ratchet ceiling increase.

## Owner rulings — OPEN, verbatim

Q11, Q12 and Q13 remain **OPEN**. The following rows are copied verbatim from `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md`, §Q10–Q13. Recommendations in them are not approvals. Nothing below implements a branch of these questions.

| Q | Question | Odoo | ERPNext / domain norm | AutoERP today | Benchmark-derived default (owner rules) |
|---|---|---|---|---|---|
| **Q11** Shared drawer, two terminals | When two terminals open on one physical drawer, how is the float attributed and the drawer reconciled? | **One open session per POS config (register)**; two registers sharing one cash journal is not supported natively and mis-states the opening balance (OCA "Correct Opening Balance" exists to patch it); guidance is one cash payment method **per register** | POS Opening Entry is per user per POS Profile; each profile is its own cash custody | Device floats per terminal session; Treasury has no drawer session | **One cash-bearing shift per drawer at a time**: a second terminal on the same drawer joins the open drawer session (no second float) or is refused; drawer-level session is the custody unit, terminal sessions attribute sales. Recommend this; the aggregation alternative is what Odoo needs a patch module for. |
| **Q12** Typed meaning of cash in/out | What do `CASH_IN`, `CASH_OUT`, v2 `DEPOSIT`, `PAYOUT` mean in money terms: safe transfer, bank deposit, petty-cash expense, other counterparty? | Cash in/out carries a **reason**; each reason maps to an account (OCA `pos_cash_move_reason`): bank-deposit moves go to a "cash awaiting bank deposit" intermediate account, small expenses to an expense account | Petty cash via Journal Entry to expense or transfer accounts; safe drop = transfer between cash accounts | Free-text reason on the device; no typed counterparty; only v3 `SAFE_DROP` is unambiguous | **Typed reason codes** on the device (`SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`), each mapped in configuration to a destination: transfer to safe/bank for the first three, expense document for petty cash, blocked for `OTHER` until classified. Recommend; v2 `DEPOSIT`/`PAYOUT` are mapped by a cutover table. |
| **Q13** Historical alignment | How do we set the opening Treasury balance of a drawer/safe that has traded for months without float/drop booking, and what happens to the disabled variance window? | Cash journal "Opening with last closing balance"; discrepancies booked as cash-difference gain/loss at the next open/close; no retroactive rebooking | Opening balances via an Opening Entry / Journal Entry dated at cutover; prior history left as is | No alignment mechanism; variance GL disabled since 2026-08-08 | **One dated alignment per repository at cutover**: count the physical cash, book a single opening-balance adjustment (document + movement + JE to cash-difference gain/loss), no retroactive rebooking of past shifts; the disabled variance window is closed by that alignment and documented per tenant. Recommend. |

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

Complete proposed schema notation: unless specified, columns are NOT NULL, have no default, and FKs use ON DELETE RESTRICT / ON UPDATE RESTRICT. UUID primary keys are application-generated. No soft deletes or updated_at on either append-only table. Tenant IDs have no cross-database FK; validate initialized tenant and company ownership at the boundary. Actor UUIDs reference tenant-local `users(id)` with RESTRICT.

`cash_custody_configurations`:

| Column | SQL type | Null/default | FK |
|---|---|---|---|
| id | uuid | primary key | — |
| tenant_id | uuid | required | boundary validated |
| company_id | uuid | required | companies(id) |
| location_id | uuid | required | locations(company_id,id), composite with company_id |
| revision | bigint | required | — |
| default_safe_repository_id | uuid | NULL | payment_repositories(company_id,id), composite |
| default_bank_repository_id | uuid | NULL | payment_repositories(company_id,id), composite |
| enabled | boolean | DEFAULT false | — |
| created_by | uuid | required | users(id) |
| created_at | timestamptz | DEFAULT CURRENT_TIMESTAMP | — |

Checks: revision > 0; enabled implies non-null safe; safe and bank differ when both present. Unique `(company_id,location_id,revision)` and `(company_id,id)`; index `(company_id,location_id,revision DESC)`. Current configuration is highest revision, never an arbitrary first row. Add parent unique `(company_id,id)` on locations and payment_repositories only if an equivalent constraint does not already exist; track ownership so down removes only this migration's additions. No JSON columns. Model and PG BEFORE UPDATE/DELETE triggers reject mutation. This preserves configuration audit without a second history table.

`repository_transfer_documents`:

| Column | SQL type | Null/default | FK |
|---|---|---|---|
| id | uuid | primary key | — |
| tenant_id | uuid | required | boundary validated |
| company_id | uuid | required | companies(id) |
| operation_uuid | uuid | required | — |
| transfer_group_id | uuid | required | — |
| kind | varchar(16) | required | PHP enum; check below |
| from_repository_id | uuid | required | payment_repositories(company_id,id), composite |
| to_repository_id | uuid | required | payment_repositories(company_id,id), composite |
| source_location_id | uuid | NULL | locations(company_id,id), composite |
| amount | decimal(15,3) | required | — |
| currency | char(3) | required | — |
| out_movement_id | uuid | required | repository_movements(company_id,id), composite |
| in_movement_id | uuid | required | repository_movements(company_id,id), composite |
| journal_entry_id | uuid | NULL | journal_entries(company_id,id), composite |
| reverses_document_id | uuid | NULL | repository_transfer_documents(company_id,id), composite |
| evidence | jsonb | required, no default | TransferDocumentEvidenceData |
| notes | varchar(1000) | NULL | — |
| occurred_at | timestamptz | required | — |
| created_by | uuid | required | users(id) |
| created_at | timestamptz | DEFAULT CURRENT_TIMESTAMP | — |

Checks: amount > 0; source != destination; out != in; uppercase three-letter currency; kind IN ('transfer','reversal'); transfer requires null reverses_document_id; reversal requires non-null, non-self reverses_document_id; evidence is a JSON object with schema_version 1. No stored status: successful insert means recorded; reversed display state derives from a linked reversal.

Uniques: `(company_id,id)`, `(company_id,operation_uuid)`, `(company_id,transfer_group_id)`, `(company_id,out_movement_id)`, `(company_id,in_movement_id)`; partial unique `(company_id,reverses_document_id) WHERE reverses_document_id IS NOT NULL`. Indexes: `(company_id,from_repository_id,occurred_at)`, `(company_id,to_repository_id,occurred_at)`, `(company_id,source_location_id,occurred_at)`. Add equivalent parent `(company_id,id)` uniques on repository_movements and journal_entries if absent. Add self-FK after CREATE TABLE.

Cross-link both ways without updating immutable movements: document points to the two legs; each leg's existing `(company_id,transfer_group_id)` resolves the unique document. Add scoped Eloquent read relationships. NEW documents are inserted after their legs within the same outer transaction. A deferred PG constraint trigger on document INSERT checks: exactly two legs in its company/group; correct directions, repositories, tenant, amount, currency, source_type Transfer, source_id group and matching nullable JE; a cross-GL evidence snapshot requires one posted balanced JE in that company, same-GL requires null JE. An INSERT trigger on movements also checks groups that already have documents, preventing a later third leg. Legacy groups without documents remain readable and are not retroactively synthesized. Trigger checks reversal amount/currency/opposite repositories, original kind transfer and reversed movement links. BEFORE UPDATE/DELETE rejects document mutation; mirror with model guards.

NEW files under `apps/api/app/Modules/Treasury/`: `Domain/CashCustodyConfiguration.php`, `Domain/RepositoryTransferDocument.php`; `Domain/Enums/RepositoryTransferDocumentKind.php` with Transfer='transfer', Reversal='reversal'; `Domain/Enums/RepositoryTransferOutcome.php` with Recorded='recorded', AlreadyRecorded='already_recorded'; `Domain/Enums/CashCustodySaveOutcome.php` with Saved='saved', Unchanged='unchanged'. These are document/outcome types, not Q12 cash-reason codes.

NEW `Application/DTOs/TransferDocumentEvidenceData.php` constructor: `__construct(int $schema_version, ?string $configuration_id, ?int $configuration_revision, ?string $from_gl_account_id, ?string $to_gl_account_id, bool $source_recorded_while_frozen, bool $destination_recorded_while_frozen, ?string $reversal_explanation)`. Validate schema_version=1, configuration id/revision jointly null or present, evidence snapshots against rows while locked. No arbitrary metadata array or mixed. Other proposed DTO constructors:

- `CashCustodyConfigurationData::__construct(string $id, string $company_id, string $location_id, int $revision, ?string $default_safe_repository_id, ?string $default_bank_repository_id, bool $enabled)`.
- `CashCustodySaveResult::__construct(CashCustodyConfigurationData $configuration, CashCustodySaveOutcome $outcome)`.
- `RepositoryTransferDocumentData::__construct(string $id, string $operation_uuid, string $transfer_group_id, RepositoryTransferDocumentKind $kind, string $from_repository_id, string $to_repository_id, ?string $source_location_id, string $amount, string $currency, string $out_movement_id, string $in_movement_id, ?string $journal_entry_id, ?string $reverses_document_id, TransferDocumentEvidenceData $evidence, ?string $notes, string $occurred_at, string $created_by, string $created_at)`.

Red first: NEW `apps/api/tests/Feature/Treasury/CashCustodySchemaTest.php`, `CashCustodySchemaTest::test_schema_has_company_scoped_document_identity(): void`; first assertion `assertTrue(Schema::hasTable('repository_transfer_documents'))`. Command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=CashCustodySchemaTest`. Add direct-SQL invalid cross-company FK, update/delete, third-leg and wrong-JE rejection tests; assert SQLSTATE and unchanged rows.

Implement migrations, DTOs, enum casts, relationships and guards after capturing red. Gate: treasury-reviewer + tenancy-authz-reviewer inspect cardinality, FK ownership, no reason-code/session/alignment additions. Rollback: pre-use only, drop triggers/functions, child tables then owned parent constraints; after recorded money, retain schema and evidence and roll application forward.

## T2 — Location settings, activation validator and census

Verified production anchors: existing repository reads/writes `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:33`, `:153`; service registration `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:61`. Existing progression activation calls an external client (`apps/api/app/Modules/Progression/Application/Services/ProgressionService.php:106`); it is not evidence of a cash-custody activation mechanism.

NEW `Application/Services/CashCustodyConfigurationService.php` public signatures:

- `save(string $tenantId, string $companyId, string $locationId, ?string $safeRepositoryId, ?string $bankRepositoryId, bool $enabled, int $expectedRevision, string $actorId): CashCustodySaveResult`.
- `current(string $tenantId, string $companyId, string $locationId): ?CashCustodyConfigurationData`.
- `assertReadyForActivation(string $tenantId, string $companyId): void`.
- `census(string $tenantId, string $companyId): CashCustodyCensusData`.

NEW `Application/DTOs/CashCustodyCensusData.php`: `__construct(string $tenant_id, string $company_id, array $unconfigured_location_ids, array $unlocated_drawer_ids, array $missing_bank_location_ids)`; each array is `list<string>`, never untyped arbitrary JSON. This is transport, not a stored JSON column.

Activation means enabling a location's cash-custody configuration, not granting a module licence or enabling any event consumer. `save(...enabled:true...)` validates the selected location; the company-wide validator refuses readiness if ANY location with an active drawer lacks an enabled configuration with a valid safe, or an active drawer lacks a location. Later W-CASH activation must call this public validator before enabling its consumer. This slice wires the local enabled transition and ships the company readiness command; it does not pretend to activate a nonexistent projector.

Lock location, current configuration, then referenced repository rows in a stable order. Compare expectedRevision; identical normalized content returns Unchanged and same ID even on a stale retry, different stale content returns 409. Append a new revision for a real edit. Validate same company/currency, active Safe and optional active BankAccount, correct location or explicitly selected company-central destination. Missing bank is reported and blocks a configured deposit default's use, but does not block safe-only readiness. No guessing the first safe or bank. An enabled safe cannot be removed without disabling that configuration. Repository metadata updates that invalidate a referenced enabled default are refused through this same validator; coordinate with W1's repository metadata authorization. Fix in-scope code uniqueness rules at `PaymentRepositoryController.php:80` and `:170` to include company_id, matching second-company behavior.

NEW `Presentation/Commands/CashCustodyCensusCommand.php`: `handle(): int`; signature `treasury:census-cash-custody {--tenant=} {--company=} {--require-ready} {--json}`. Read-only, deterministic output; initialize/end tenant context in finally for each tenant, verify company belongs to it, never use HTTP CompanyContext/LocationScopeResolver. Without require-ready return success for a completed census; with it return 1 for missing safe/unlocated drawer, 2 for operational lookup failure. Register through TreasuryServiceProvider. No schema beyond T1 and no new enums beyond T1.

Red first: NEW `apps/api/tests/Feature/Treasury/CashCustodyConfigurationTest.php`, methods `test_activation_refuses_second_location_without_safe(): void` (first assertion expects validation exception), `test_identical_save_returns_unchanged(): void` (assertSame Unchanged and unchanged revision count), `test_second_company_defaults_are_independent(): void` (same repository code allowed and B's census excludes A). NEW `CashCustodyCensusCommandTest.php`, `test_require_ready_reports_unconfigured_second_location(): void`, first assertion `assertExitCode(1)`. Commands from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=CashCustodyConfigurationTest` and `php artisan test -c phpunit-pgsql.xml --filter=CashCustodyCensusCommandTest`.

Gate: treasury-reviewer + tenancy-authz-reviewer check activation semantics, console tenant lifecycle and central-destination authorization. Rollback: disable the new configuration through a new revision, retain revision history; no balance changes to undo.

## T3 — One atomic, idempotent transfer document

Production files: replace `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:33`; extend `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferResult.php:7`; preserve movement port `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:225` and GL factory `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1497`.

NEW `Application/DTOs/RepositoryTransferIntent.php` constructor: `__construct(string $tenantId, string $companyId, string $operationUuid, string $fromRepositoryId, string $toRepositoryId, string $amount, string $currency, ?string $sourceLocationId, ?string $configurationId, ?int $configurationRevision, ?string $notes, string $actorId, ?CarbonImmutable $occurredAt, bool $allowWhileFrozen = false)`. Actor and persisted scope are explicit; an internal intent is not client authorization. Service signature becomes `transfer(RepositoryTransferIntent $intent): RepositoryTransferResult`; migrate every production caller in T5.

Result constructor becomes `__construct(string $transferGroupId, ?string $journalEntryId, MovementResult $out, MovementResult $in, bool $idempotentReplay, RepositoryTransferDocumentData $document, RepositoryTransferOutcome $outcome)`. Preserve existing output aliases during the web rollout.

Within one transaction, serialize `(company_id,operation_uuid)` with a dedicated namespaced PG advisory lock before minting anything. Check document first. Identical normalized source/destination/amount/currency/notes/explicit occurredAt/configuration/source-location intent returns the original document, legs and JE with AlreadyRecorded and idempotentReplay=true. Server-assigned timestamps are not regenerated semantic inputs. Conflicting content returns 409 without writes. Actor remains original audit actor; a newly authorized retry does not rewrite it.

For a new operation generate a server group UUID independent of the user operation UUID; the company-operation unique owns retries. Existing movement keys at `TreasuryMovementService.php:296` are tenant-database-global, so using the client's operation UUID directly would incorrectly collide across companies. UUID collision must roll back and retry allocation before any committed effect; do not widen the existing movement key contract casually.

Mint optional JE through the existing factory before the movement port takes company/repository locks. Preserve tenant-numbering → company-GL → sorted repository lock order documented at `TreasuryMovementService.php:238`; do not introduce repository-before-numbering locking. After the port locks, revalidate active/type/currency and GL mapping against the selected draft; concurrent mapping drift aborts the whole operation. Snapshot evidence from locked rows, insert document after both legs, and commit once. Any failure rolls back document, legs, balances, ordinals and draft/post. Exact document replay runs before mutable freeze/checkpoint/active rules, after adapter authorization.

Same linked GL account: two opposite legs, no JE. Different linked GL accounts: exactly one posted JE, Dr destination/Cr source, equal amount. Retain existing behavior for both-unlinked repositories explicitly as no-GL operational custody; never treat one-null/one-linked as same GL. Future launch readiness may require linked accounts; this slice does not invent an owner accounting cutover.

No schema beyond T1. Do not change the raw movement source type or fiscal events.

Red first: NEW `apps/api/tests/Feature/Treasury/RepositoryTransferDocumentTest.php`, methods `test_same_gl_records_document_without_journal(): void`, `test_cross_gl_records_one_balanced_journal(): void`, `test_retry_returns_original_document_without_writes(): void`, `test_changed_payload_conflicts(): void`, `test_failure_after_legs_rolls_everything_back(): void`. First assertions respectively: document count=1; document JE non-null; second result outcome=AlreadyRecorded and same document ID; conflict exception; unchanged document/movement/JE/balance/ordinal snapshot. Command: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferDocumentTest`. These are new failing assertions, not claimed test executions.

Gate: treasury-reviewer + tenancy-authz-reviewer inspect operation serialization, real monetary snapshots, GL lock order and company identity. Rollback: block new transfer submissions while rolling forward; never delete a recorded document or posted JE.

## T4 — Linked reversals and explicit frozen policy

Production anchors: wrapper rejects either frozen repository at `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:46`; single-leg port allows explicitly flagged record-and-alert at `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:91`; paired port currently hardcodes false at `:371`, `:391`, and event builder `:639`. Existing TransferIntent has no freeze/reversal fields (`apps/api/app/Modules/Treasury/Application/DTOs/TransferIntent.php:26`).

Extend that constructor, retaining its complete existing parameter order: `__construct(string $fromRepositoryId, string $toRepositoryId, string $tenantId, string $companyId, string $amount, string $currency, string $transferGroupId, ?string $journalEntryId, ?CarbonImmutable $occurredAt, ?string $createdBy, ?string $notes, bool $allowWhileFrozen = false, ?string $reversesOutMovementId = null, ?string $reversesInMovementId = null)`. Public transfer signature remains unchanged. Extend private `buildTransferLegEvent(string $movementId, string $repositoryId, TransferIntent $intent, MovementDirection $direction, string $balanceAfter, int $ordinal, ?string $journalEntryId, CarbonInterface $occurredAt, bool $recordedWhileFrozen): RepositoryMovementRecorded`; propagate the actual flag into both existing event instances without changing event structure.

NEW `Application/DTOs/RepositoryTransferReversalIntent.php`: `__construct(string $tenantId, string $companyId, string $originalDocumentId, string $operationUuid, string $actorId, ?string $sourceLocationId, string $explanation, CarbonImmutable $occurredAt)`.

Add `RepositoryTransferService::reverse(RepositoryTransferReversalIntent $intent): RepositoryTransferResult`. Full reversal only; reject reversal-of-reversal, partial amounts, duplicate reversal under a different operation, foreign original and unauthorized current source. Serialize original identity before operation lock consistently for reversal calls. Swap original repositories, preserve original amount/currency, new operation/group/document, link reverses_document_id; new out reverses original in, new in reverses original out. Do not mutate original document or old JE. Require current GL mapping to equal original evidence; otherwise refuse with explicit correction-required error, preventing a misleading reversal under changed accounts. Respect current accounting-period/checkpoint and source-balance controls.

Interactive HTTP always constructs allowWhileFrozen=false and forbids that field in input. Under repository locks, a new interactive transfer to/from a frozen repository refuses atomically. Explicit internal allowWhileFrozen=true records both legs, marks only frozen legs and emits an after-commit structured warning per frozen leg with document/group/repository identifiers. This is a tested prerequisite contract only: no new worker, event subscription or device authority. Exact successful retry emits no duplicate money effects or alerts. No negative-balance or checkpoint bypass is introduced. No new schema or cash-reason enum beyond T1.

Red first: NEW `apps/api/tests/Feature/Treasury/RepositoryTransferReversalTest.php`, `test_reverse_appends_linked_document_and_preserves_original(): void` (first assert linked reversal ID exists), `test_repeat_reverse_returns_original_reversal(): void`, `test_second_reversal_operation_is_refused(): void`, `test_mapping_change_refuses_reversal(): void`. NEW `RepositoryTransferFrozenPolicyTest.php`, `test_explicit_frozen_destination_records_and_alerts(): void` (first assert destination leg recorded_while_frozen=true), `test_interactive_frozen_destination_has_no_effect(): void`, `test_retry_after_freeze_returns_original(): void`. Commands: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferReversalTest` and `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferFrozenPolicyTest`. Verify warnings after actual commit, not callbacks suppressed by an outer test transaction.

Gate: treasury-reviewer + tenancy-authz-reviewer inspect compensation direction, current source custody, immutable original and non-client-settable freeze intent. Rollback: disable new reversal submissions; preserve all original/reversal evidence and money rows.

## T5 — Existing HTTP and web surfaces, generated types

Verified production files: `apps/api/app/Modules/Treasury/Presentation/routes.php:35`, `:102`; transfer controller `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:21`; request `apps/api/app/Modules/Treasury/Presentation/Requests/TransferRepositoryRequest.php:21`; repository controller `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:420`; `apps/web/src/features/treasury/hooks/useTransferCash.ts:5`; `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:7`; `apps/web/src/features/treasury/RepositoryListPage.tsx:23`; `apps/web/src/features/treasury/RepositoryDetailPage.tsx:27`; `apps/web/src/features/treasury/PaymentForm.tsx:60`; `apps/web/src/features/treasury/components/TransferCashModal.tsx:28`, `:84`; existing web module wrappers `apps/web/src/routes/index.tsx:1936`.

Keep POST `/api/v1/payment-repositories/transfers`. Require operation_uuid; accept existing transfer_group_id as a deprecated operation-identity alias during deployment, require equality if both are sent, require at least one, and never silently mint an identity on the server. Map legacy retries only if a same-company legacy pair already exists under the alias: return an explicit legacy-document-unavailable conflict without creating money or retroactive evidence. Such legacy cases need separate historical handling, not this slice's Q13 policy.

Add subresource methods on existing controllers: `RepositoryTransferController::show(Request $request, string $documentId): JsonResponse`, `reverse(ReverseRepositoryTransferRequest $request, string $documentId): JsonResponse`; `PaymentRepositoryController::saveCashCustody(SaveCashCustodyRequest $request, string $id): JsonResponse`. Requests implement `authorize(): bool`, `rules(): array`; configuration payload names location_id, defaults, enabled, expected_revision; derive and match location from the route drawer. New routes are GET `/payment-repositories/transfers/{documentId}`, POST `/payment-repositories/transfers/{documentId}/reverse`, PUT `/payment-repositories/{id}/cash-custody`. Order fixed paths before `{id}`.

Preserve api/auth:sanctum/SetPermissionsTeam/EnforceTokenTenantClaim. Add the Treasury module middleware to in-scope repository/configuration/transfer routes, using `module:Treasury` (case-sensitive middleware contract: `apps/api/app/Http/Middleware/RequireModule.php:24`). Require repositories.manage for settings, treasury.transfer for create/reverse and repositories.view plus custody scope for document reads. Do not gate unrelated Treasury customer-account deposit paths as collateral work.

HTTP authorization follows W1 (`docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:139`): resolve allowed IDs with `resolve($user, [], 'treasury.manage_all_locations')`; query source under company AND allowed persisted location, using findOrFail to return 404. Do not ask resolver to authorize a guessed request location, which returns 403 (`LocationScopeResolver.php:41`). Unlocated/central source requires explicit company-wide authority; a central destination exception grants inbound eligibility only. Destination must be allowed branch custody or the source location's configured safe/bank. Derive source location from stored repository; ignore/reject forged client authority fields. Authorize again for every replay and reverse. Do not put resolver or Request into the service.

Existing index gains a destination-selection mode parameter, not a new catalogue. It returns a NEW generated `RepositoryDestinationData::__construct(string $id, string $name, RepositoryType $type, string $currency)` only; central destinations never expose balances, account numbers or IBANs. Leave broad human-index scoping rollout coordinated with W1/W2, whose device transition is a separate prerequisite; this slice scopes its transfer/configuration modes now. Source picker must use source-authorized results.

NEW transport `RepositoryTransferRequestData::__construct(string $operation_uuid, string $from_repository_id, string $to_repository_id, string $amount, ?string $notes)` and `RepositoryTransferResponseData::__construct(RepositoryTransferDocumentData $document, RepositoryTransferOutcome $outcome, string $transfer_group_id, ?string $journal_entry_id, bool $idempotent_replay, RepositoryTransferLegData $out, RepositoryTransferLegData $in)`, with `RepositoryTransferLegData::__construct(string $movement_id, string $balance_after, string $repository_id)`.

NEW `Application/DTOs/PaymentRepositoryData.php` owns the existing formatted repository response at PaymentRepositoryController.php:420: constructor `__construct(string $id, string $code, string $name, RepositoryType $type, bool $allow_negative, ?string $bank_id, ?string $bank_name, ?string $account_number, ?string $iban, ?string $bic, string $balance, string $currency, bool $is_active, ?string $gl_account_id, ?RepositoryGlAccountData $gl_account, ?string $location_id, ?string $location_name, RepositoryBankValidationData $bank_account_validation, ?CashCustodyConfigurationData $cash_custody)`. Nested NEW DTOs: `RepositoryGlAccountData::__construct(string $id, string $code, string $name)`; `RepositoryBankValidationData::__construct(?RibValidationResult $rib, ?IbanValidationResult $iban, ?bool $bic_valid)` using existing validator result types. Export their nested shapes too; do not add an unwritten is_default field just because the local hook currently declares it (`usePaymentRepositories.ts:13`). These response DTOs are not stored JSON columns.

Replace the listed local repository/transfer interfaces with generated imports in all six listed treasury web files. Keep one modal, add recorded/replayed document link, render document in existing repository detail. Preserve operation UUID across transport retries; after an ambiguous failure freeze the submitted intent until retry resolves, so editing amount does not silently reuse an uncertain operation. On tenant/company change close/reset modal and discard stale response. Use apiPost's already-unwrapped result if migrating from raw api, not a second .data unwrap; the current raw hook is not itself proof of double-unwrapping (`useTransferCash.ts:26`). Keep money strings, MoneyInput, translation keys in `apps/web/src/locales/{en,fr,ar}/treasury.json`, tenantScopedKey reads and correct invalidations. Existing repository routes already have moduleKey=treasury; preserve it and gate new action fields/buttons by permission/module too. No migration or enum beyond T1.

Red first: extend `apps/api/tests/Feature/Treasury/RepositoryTransferEndpointTest.php` with `test_other_branch_source_is_scoped_not_found(): void` (first assertNotFound, then unchanged document/movement/GL/balance/ordinal snapshots), `test_central_destination_does_not_grant_reverse_outflow(): void`, `test_module_off_refuses_transfer(): void`, `test_client_cannot_enable_frozen_override(): void`, `test_destination_mode_omits_bank_details(): void`. Command: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferEndpointTest`.

Extend `apps/web/src/features/treasury/hooks/useTransferCash.test.ts` with `useTransferCash > returns document and explicit replay outcome`; extend `apps/web/src/features/treasury/RepositoryListPage.transferIntegration.test.tsx` with `RepositoryListPage > retries one operation and opens its document`; extend `apps/web/src/features/treasury/components/TransferCashModal.test.tsx` with `TransferCashModal > central destination renders no balance`; first failing assertions respectively compare document.id, same UUID across calls, and absence of fixture bank balance/account text. Add `apps/web/src/features/treasury/RepositoryDetailPage.custody.test.tsx`, `RepositoryDetailPage > saves second-location custody defaults`, first assert the outgoing location/default IDs. Command from apps/web: `pnpm exec vitest run src/features/treasury/hooks/useTransferCash.test.ts src/features/treasury/RepositoryListPage.transferIntegration.test.tsx src/features/treasury/components/TransferCashModal.test.tsx src/features/treasury/RepositoryDetailPage.custody.test.tsx`. Vitest uses describe/test names, not fictitious PHP class methods.

Generate types from apps/api with `php artisan typescript:transform`, then web typecheck. Gate: treasury-reviewer + tenancy-authz-reviewer, plus frontend-conventions review and applicable React Doctor finishing workflow during implementation. Rollback: disable new action UI/HTTP writes together; retain document read access and schema. Do not revert to a documentless writer after first production use.

## T6 — PostgreSQL proof and deployment handback

Verified anchors: PG lane `apps/api/phpunit-pgsql.xml:1`; real registration endpoint fixture `apps/api/tests/Feature/Tenant/TenantInitializationTest.php:158`; real second-company path `apps/api/tests/Feature/Treasury/CompanyPaymentRepositoryProvisioningTest.php:95`; ratchet `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:212`, `:254`. Production schema is T1; no additional columns or enums.

NEW `apps/api/tests/Feature/Treasury/CashCustodySecondOfEverythingTest.php`: `test_registered_second_company_can_reuse_operation_uuid(): void`, first compare B's document company_id to B; assert same operation UUID produces distinct groups and documents in A and B, each with exactly its own two legs and expected JE. Register tenant/company A through `/api/v1/auth/register`; create B through authenticated `/api/v1/companies`; create selected second POS location through its real API. Do not substitute Company::factory for B or mistake helper names for real registration. Test duplicate repository codes through the HTTP setting path, seeded drawer/safe ownership, second-location save/use, repeat unchanged and transfer AlreadyRecorded.

NEW `apps/api/tests/Integration/Treasury/RepositoryTransferDocumentConcurrencyTest.php`: `test_concurrent_same_operation_commits_one_document(): void`, first assert exactly one company-operation document after two independent committed connections; then two legs, zero/one JE, one Recorded and one AlreadyRecorded. Also `test_opposing_transfers_preserve_lock_order(): void`, `test_concurrent_reverse_commits_once(): void`, `test_company_uuid_reuse_does_not_collide(): void`. Use committed fixtures/processes, not two calls on one transaction. Deliberate barriers must exercise uniqueness/locking; infrastructure failure is not a skip/pass.

Add cash_custody_configurations to the ratchet catalogue classification. Classify repository_transfer_documents explicitly as immutable financial action evidence; retain its company-scoped uniques anyway. Do not enlarge baselines or ceilings. Schema tests must exercise duplicate SQL inserts and live unique definitions, not migration text. Re-run migration command twice in an isolated lane and compare schema/data; test down/up only on empty fixtures.

Commands from apps/api, using an isolated private PG service with DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD/DB_DATABASE/DB_CENTRAL_DATABASE supplied by the lane, never shared development or staging data:

```bash
php artisan test -c phpunit-pgsql.xml --filter=CashCustodySecondOfEverythingTest
php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferDocumentConcurrencyTest
php artisan test -c phpunit-pgsql.xml --filter=TenantOnlyUniqueOnCatalogueTablesRatchetTest
php artisan test -c phpunit-pgsql.xml --filter='CashCustody|RepositoryTransfer'
php artisan test -c phpunit.xml --filter='RepositoryTransferServiceTest|RepositoryTransferEndpointTest'
```

SQLite is regression evidence only; PG proves constraints, concurrency and trigger behavior. Include a db-per-tenant topology case explicitly enabled in its test because the PG configuration defaults to compatibility mode. First failing assertion for that case: wrong-tenant lookup returns scoped-not-found, both tenant database snapshots unchanged.

NEW `apps/web/e2e/treasury-cash-custody-transfer.spec.ts`: `second branch custody and transfer evidence` exercises configure → transfer → document → retry → authorized reversal, plus branch denial and company switch. Run `pnpm --filter @autoerp/web test:e2e` before merge and retain screenshots. Run required preflight/CI checks during implementation: `./scripts/preflight.sh`, `pnpm build`, `pnpm lint`, `pnpm test`, `pnpm typecheck`, and from apps/api `composer test`, `./vendor/bin/phpstan`, `./vendor/bin/pint --test`. Record actual failures and intentionally skipped specs; no green claim from this plan.

Gate: treasury-reviewer + tenancy-authz-reviewer approve actual PG evidence, second-company registration and denial snapshots. Rollback: stop submissions; preserve all committed evidence; reverse business mistakes only via authorized compensation. NEW handback `docs/handoff/HANDBACK-WCASH-1-2026-09-06.md` records implementation SHA, red/green commands, screenshots, migration/census output and reviewer findings. No implementation or reviewer gate was run for this planning assignment.

## Deployment and rollback manifest requirements

The referenced `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md` is absent at the inspected baseline. The shared release owner must supply these exact entries before promotion:

1. Accepted implementation/reviewer SHAs, dependency order with W1 authorization changes, artifact IDs, tenant/company inventory, selected private test PG service, staging backup IDs and successful restore evidence.
2. A transfer-write maintenance window: drain in-flight back-office requests, record unresolved legacy operation/group IDs, deploy additive tenant migrations before new application code, and list the exact central and per-tenant migration commands for the release topology. Do not execute tenant migrations against central by assumption.
3. Verify both new tables, company composites and PG triggers in every target tenant; rerun migrations safely. Deploy generated DTO consumers and API alias compatibility together. Keep blocked legacy retries visible; no synthesized historic documents or opening balances.
4. Apply reviewed W1 permission deltas and cache invalidation where required, preserving custom grants. Resolve and verify the Treasury middleware key. Prove authorized source, central-inbound and denied central-outbound behavior before reopening writes.
5. Run `php artisan treasury:census-cash-custody --tenant=<tenant-uuid> --company=<company-uuid> --json` for every company. Configure each location's safe and optional bank through the existing repository surface. Run the same command with `--require-ready`; save missing-safe/unlocated-drawer and missing-bank output separately.
6. Run staging same-GL and cross-GL transfer/retry probes, immutable linked reversal, frozen internal contract tests in an isolated fixture, module-off refusal, branch-404 snapshots and second-company UUID reuse. Run `scripts/campaign-onboarding.sh` and `tenant:census-day-one` with clean evidence before promotion.
7. Record explicitly that shift consumers, v2/v3 booking, W7 comparison, variance flag and historical alignment remain unchanged. Custody readiness alone is not authorization to enable them; Q11–Q13 remain open.
8. Reopen transfer writes only after smoke evidence. Rollback owner can disable writes/UI and roll forward the application; preserve tables/constraints/documents. Never deploy the old documentless writer over recorded traffic. Drop additive schema only on unused installations after confirming zero records and no dependencies.

## Dispatch order and verification checklist

Dispatch sequentially: **T1 → T2 → T3 → T4 → T5 → T6**. Each task starts with its named red assertion, ends with green evidence and treasury-reviewer + tenancy-authz-reviewer gate. Coordinate shared files with W1 before editing; no parallel writers to transfer/controller contracts. Use an isolated implementation worktree; this planning assignment creates no branch or git write.

- [ ] Reverify baseline SHA and every existing seam; all new paths remain clearly labelled proposals until implemented.
- [ ] Nine benchmark rows and two glossary additions retained; Q11–Q13 verbatim and OPEN.
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
- [ ] PG ratchet, meaningful concurrency tests, preflight, web E2E, campaign and census evidence accepted; manifest completed.
- [ ] No shift booking, adapters, W7/variance enablement, cash-reason policy, drawer-session model or historical alignment shipped in this slice.

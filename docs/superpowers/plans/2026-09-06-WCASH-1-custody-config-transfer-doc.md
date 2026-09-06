<!-- W-CASH-1 rev 14 (rev 10 dispatch-ready base plus gate-r11 Amendment A, gate-r12 Amendment B, and gate-r13 D1–D5 corrections) (gpt-5.6-sol, read-only) on 2026-09-06, saved verbatim by the orchestrator (owner away). Rev 4 = 59e8c6c82. Status: awaiting gate r14. -->
# Slice plan W-CASH-1 — per-location cash custody configuration and repository transfer document (rev 14)

Evidence baseline: reviewed local `dev` HEAD `55bf3d14204363ab857738f785a3c405100da9db`, resolved with `git rev-parse HEAD` on 2026-09-06. Historical production-source baseline was `f13b923a5c150ceee8acb8165e98197207c88eff`; r2 reviewed `5fe262c84527d90a6124e467978b282806630d50`. Existing-code anchors below were re-read at the current reviewed HEAD; Amendment-A/B citations are path:line at that SHA; NEW files/contracts are proposals, not shipped behavior. This round edits only this plan. No code, tests, migrations or Git state were changed.

## Round-13 change log — rev 14 deltas

This table is historical closure metadata, not an operative dispatch instruction; see dispatch order.

| Item | Change and closing plan line |
|---|---|
| D1 | Integrated Amendment A: the fully migrated source shape is `(15,3)`, P0-b widens only `(15,3)→(15,4)`, every other shape including scale two refuses, and all authoritative P0 names come from the precision-4 brief. Closed in the P0 premise, migration and test lines below. |
| D2 | Integrated Amendment B: `fourth_decimal_present` is a standing per-tenant detector and hard P0-b stop; the migration docblock and precision contract carry the NC 01 §62, Laravel HALF_UP, preset, test-blast-radius and dependent-consumer evidence; casts remain `decimal:3` provisionally. Closed in the P0 census and cast-ruling lines below. |
| D3 | Replaced the cross-boundary Shared Infrastructure factory with NEW `App\Shared\Contracts\Company\CompanyCurrencyScaleResolverFactoryInterface` and a Company Infrastructure implementation; Treasury Application injects only the contract, with an exact architecture gate. Closed in the T3 precision-ceiling line below. |
| D4 | Centralized all operative sequencing into exactly one binding statement in § Dispatch order; every other sequencing mention is historical/superseded or marked “see dispatch order.” Closed in § Dispatch order. |
| D5 | Restored the complete rev-10 T4/T5/T6 packets, added exact manifest census greps and expected-tenant UUID-set checks, refreshed HEAD/revision/status, and re-anchored change-log closures to current sections. Closed in T4–T6 and the deployment census variable below. |

## Historical/superseded Round-12 change log — gate r12 Amendment B

No row in this or any earlier change log is operative; see dispatch order.

| Item | Historical change |
|---|---|
| B1 | Made `fourth_decimal_present` a standing, per-tenant P0-a guard with exact SQL predicate, human/JSON output key, per-column counts/sample IDs, hard P0-b exit rule and one named red PG test that writes `1.0005` through the movement-port GUC. Current closure: § P0 pre-ALTER census. |
| B2 | Recorded provisional owner row A10 option A in both the P0-b migration-docblock and precision-contract instructions with NC 01 §62, Laravel HALF_UP read rounding, the three-ERP benchmark, preset census, 192/34 strict-test blast radius, hardcoded scale-three consumer and 4-of-19 heterogeneous-shape evidence. Casts stay `decimal:3`; the detector makes that provisional choice safe; widening casts is a separate future lane only when a 4-dp preset exists. Current closure: § P0 prerequisite. |
| B3 | Historical sequencing disposition superseded by the single binding statement in § Dispatch order; see dispatch order. |
| m1 | Historical revision/status metadata superseded by the rev-14 header and HEAD declaration above. |

## Historical/superseded Round-11 change log — gate r11 fix round 6

No row is operative; see dispatch order.

| Item | Historical change |
|---|---|
| B1 | Made `docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md` authoritative for all P0 command, class, DTO, enum, marker, migration, test, ticket and deployment identifiers; all five classifications, including Geometry, are retained. Current closure: § P0 prerequisite. |
| Compatibility | Former repository-precision command class/service/aggregate DTO/check DTO/storage-floor DTO/CLI/marker/census test/prerequisite test/ratchet test/migration/follow-up ticket/deployment slice are renamed respectively to `MoneyPrecisionCensusCommand`, `MoneyPrecisionCensusService`, `MoneyPrecisionCensusData`, `MoneyPrecisionCheckData`, `MoneyColumnShapeData`, `treasury:census-money-precision`, `MONEY-PRECISION CENSUS`, `MoneyPrecisionCensusCommandTest`, `GlAndRepositoryBalanceScale4Test`, `MoneyStorageScale4RatchetTest`, `2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php`, `2026-09-07-money-columns-below-scale-4-followup.md`, and `precision-4`. Current closure: § P0 prerequisite. |
| B2 | Historical sequencing disposition superseded by the single binding statement in § Dispatch order; see dispatch order. |
| M1 | P0-b accepts only exact pre-widen `(15,3,NO,0)` / `(15,3,YES,NULL)` or already-compliant `(15,4,…)`; scale two is a refusal fixture asserting `unexpected_column_shape`, no DDL and unchanged rows/schema. Current closure: § P0 complete shape contract. |
| m1 | Historical revision/status metadata and line anchors are superseded by the rev-14 header and current section anchors. |
| A | Amendment A remains binding: storage widens `(15,3)→(15,4)`; the four Eloquent casts stay `decimal:3` provisionally under owner row A10; cast change and operational-ceiling removal are separate future lanes. Current closure: § P0 prerequisite and § T3. |

## Historical/superseded Round-10 change log — orchestrator-applied Amendment A

No row is operative; see dispatch order.

| Item | Historical change |
|---|---|
| A.1 | Premise corrected: live scale is 3 after the March 2026 migration; widening is `(15,3)→(15,4)` and accepted pre-widen tuples are exact. |
| A.2 | Owner row A10: casts stay `decimal:3` provisionally; cast widening and ceiling removal are separate future lanes. |
| A.3 | P0 reuses the precision-4 brief’s allowlist, deployment variables, lock profile and tests. |
| A.4 | Historical sequencing disposition superseded by the single binding statement in § Dispatch order; see dispatch order. |

## Historical/superseded Round-9 change log — gate r9 B1 + m1

No row is operative; see dispatch order.

| Item | Historical change |
|---|---|
| B1.1 | Company-bound scale lookup is executable without a bound `CompanyContext` for both authority branches. Rev 14 replaces the cross-boundary implementation with the Shared contract and Company Infrastructure adapter in § T3. |
| B1.2 | The task-owned exception register contains the exact T3 `RepositoryTransferPrecisionCeilingException` contract in § Exact file resolution and typed failure contracts. |
| B1.3 | The registered renderer test has an exact file, class, method, first failing assertion, command and lane in § Exact file resolution and typed failure contracts. |
| B1.4 | The precision endpoint test asserts the exact translated 422 JSON and unchanged document/movement/repository/JE snapshots in § T5. |
| B1.5 | The precision translation is assigned to `treasury.transfer.precision_exceeds_ledger_scale` in all three backend `treasury.php` files and excluded from `messages.treasury.*` in § Exact file resolution and typed failure contracts. |

## Historical/superseded Round-8 change log — gate r8 B1

No row is operative; see dispatch order.

| Item | Historical change |
|---|---|
| B1.1 | T3 `executeTransfer(...)` returns `DocumentedRepositoryTransferResult`; `RepositoryTransferResult` is reserved for the atomic T5 rename. |
| B1.2 | `RepositoryTransferPrecisionCeilingException extends \DomainException` with the complete constructor `(public readonly string $amount, public readonly int $effectiveScale)`; it is in the T3 exception register. |
| B1.3 | Executable scale source for both authority branches uses `CompanyCurrencyScaleResolverFactoryInterface::forCompany(tenantId, companyId)`, called with no currency argument so the country preset applies. |
| B1.4 | Red tests use the captured-exception pattern so typed fields and every unchanged snapshot are asserted for human and system callers. |
| B1.5 | One normative 422 envelope uses flat `error` extras `amount` and `effective_scale`; renderer, status-contract and endpoint tests assert the full envelope. |

## Historical/superseded Round-7 change log — gate r7 B1 + m1

No row is operative; see dispatch order.

| Item | Historical change |
|---|---|
| B1 | The three-decimal operational ceiling became a normative T3 contract and T5 HTTP contract with two service-level red tests, one HTTP red test and a verbatim removal condition. |
| m1 | Historical revision metadata superseded by the rev-14 header. |

## Historical/superseded Round-6 change log — gate r6 B1 + m1

No row is operative; see dispatch order.

| Item | Historical change |
|---|---|
| B1 | **Operational precision ceiling (temporary).** Until the movement ledger (`repository_movements.amount`, `balance_after`) is widened in a follow-up lane, `RepositoryTransferService` and every typed caller refuse an amount with more than three fractional digits even when the company country preset is four: `TRANSFER_PRECISION_EXCEEDS_LEDGER_SCALE`, evaluated before document/movement/JE writes. |
| m1 | Historical revision metadata superseded by the rev-14 header. |

## Historical/superseded Round-5 change log — gate r5 B1

No row is operative; see dispatch order.

| Item | Historical change |
|---|---|
| B1 | Operational transfer/movement fixtures use a country-valid value of at most three decimals, TND `1.005`, because `repository_movements.amount` and `balance_after` remain scale 3 in this slice. `1.0005` is retained only for raw round-trip proof of the four P0 target columns. |

## Historical/superseded Round-4 change log

This is review history, not implementation or deployment approval; see dispatch order.

| Gate item | Historical rev-5 disposition |
|---|---|
| B1 | **SUPERSEDED in part by Amendments A/B.** The four existing P0 columns and new transfer-document amount use `decimal(15,4)` storage; operational precision remains the company country preset. The historical instruction to add scale-four Eloquent casts is superseded; those casts remain `decimal:3` provisionally. |
| M1 | Split P0 into ordered P0-a and P0-b deployment packages. P0-a contains only read-only census code; P0-b alone contains the non-additive widening migration, backup, topology-specific command, verification and rollback contract. Sequencing is governed solely by § Dispatch order; see dispatch order. |
| m1 | Corrected movement persistence citation: `balance_after` is at `TreasuryMovementService.php:590`; `source_type` and `source_id` are at `:592–593`. |

## Historical/superseded Round-3 change log

This is review history, not implementation or deployment approval; see dispatch order.

| Gate item | Historical rev-4 disposition |
|---|---|
| M1 | Corrected balance DEFAULT 0; P0 compares complete precision/scale/nullability/normalized-default tuples before, after and on direct rerun. |
| M2 | Added read-only pre-ALTER precision census, exact command, typed output, measurable comparisons and reviewer pair treasury-reviewer + stock-gl-interaction-reviewer. |
| M3 | Added explicit shared-connection migration commands, topology/connection proof, markers, exit rules and physical SQL verification. Rolling no-op cannot pass. |
| M4 | P0 repeats the current movement port on the real company-B second-location drawer: `wasIdempotentHit=true`, same movement/ordinal/count/balance, company A unchanged. |
| M5 | T1 direct-migration liveness providers cover partial tables, wrong parent keys/FKs/CHECKs/uniques/triggers with exact refusal and unchanged schema/data. |
| M6 | Builder carries each opposite-leg reversal ID into existing `RepositoryMovementRecorded` v1; its payload already has the field. Event and Compliance audit-row tests were added to the plan. |
| M7 | Push 3 preserves the existing scalar endpoint and result constructor; Push 5 atomically replaces the scalar path, shrinks its architecture exception and enables documented writes only after old processes drain. Post-use rollback is fail-closed. |
| M8 | Added exact exception, warning, sink and HTTP renderer paths/signatures, task ownership, status/envelope and alert assertions. |
| m1 | Unknown-field tests assert `errors.surprise.0` or the actual forged key and translated `messages.validation.unexpected_field`. |
| m2 | Refreshed reviewed HEAD. |
| m3 | Restored literal `DRIFT(` and every-target verdict inspection. |

## Historical/superseded Round-2 change log

Historical rev-3 dispositions are retained as review history; see dispatch order.

| Gate item | Historical rev-3 disposition |
|---|---|
| B1 | T1/T3 define a human/system actor union, nullable human FK on documents, stable named system authority/provenance, branch validation and PG first/retry/conflict tests. No system caller fabricates a user. |
| B2 | Added hard prerequisite P0 precision widening, its four-column schema/round-trip ratchet, dedicated non-additive push and backup/migration proof. The original scale-2 CREATE migrations are superseded by `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:27-30,113-116`; P0 widens only exact scale 3 and refuses scale-2 migration-history drift. |
| Program-level precision finding for Fable/orchestrator | P0 is fleet precision-assurance/deployment-drift work affecting existing POS receipt posting too: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:1515` creates payment GL and `:1526` posts it. A deployment still at scale 2 may have lost precision; no historical rebooking or reconstruction occurs. |
| M1 | T3 retains the eight-scalar transfer signature as a deprecated adapter and adds `executeTransfer(intent)`. T5 migrates the controller, removes the adapter and renames the typed method atomically. |
| M2 | Common real registration/company/POS-location fixture applies to every task. T1/T2 share one acceptance boundary. |
| M3 | T5 includes movement controller, generated movement DTO, hook, tab and detail page for durable authorized history links, both legs, reload, omission and scoped-404 tests. |
| M4 | Added `StatementUploadWizard` and `StatementListPage` consumer; exact POS generated-global wiring and fully qualified ambient DTO namespace. |
| M5 | Added matrix B10, vocabulary architecture linkage, T3 baseline JSON shrink and exact protected-blob ratchet tests. |
| m1 | Exact trim → NFC → collapse Unicode whitespace, case-preserving notes normalization and comparison encoding. |
| m2 | All three FormRequests use `withValidator`/`after` allowlist rejection on raw input keys. |
| m3 | T1 invalid-enum SQL tests and `EnumCheckParityTest` gate include new initiator enums. |
| m4 | Current reviewed HEAD and historical source baseline distinguished; repository tenant/company predicate citation corrected to `:121`. |

## Historical/superseded Round-1 change log

Historical r1 dispositions are retained as review history; see dispatch order.

| Finding | Historical rev-2 correction |
|---|---|
| BLOCKER 1 | Owner register below copies Q11–Q13 RULED verbatim. Their implementation is deferred by scope, not pending a decision. |
| BLOCKER 2 | Deployment contains the canonical manifest sentence, every variable, §4 checklist, per-push rollback points and U-1/U-2 promotion prerequisites. |
| BLOCKER 3 | T3/T5 specify stored-document-first replay authorization under locks, original-actor comparison, client-only semantics, scoped 404 before conflict and immutable server-derived retry evidence. |
| MAJOR 1 | T1 adds tenant/company, tenant/actor and tenant/company/repository/leg/JE composites plus direct-SQL topology tests. |
| MAJOR 2 | T2 uses Presentation/Console, exact provider import/registration seams, deterministic fleet JSON/markers, option semantics and aggregate failure status. |
| MAJOR 3 | T2 names `RepositoryMetadataService` as metadata writer and orders metadata/configuration writes company lock → locations → configurations → repositories. |
| MAJOR 4 | Each task has an executable red-contract register and immediate convention-09 mapping. |
| MAJOR 5 | T5 has the hard external W1-T-CUSTODY-AUTHZ prerequisite, request rules, index modes, document-read scope, envelopes and statuses. |
| MAJOR 6 | T5 enumerates repository-response shadows in web and device clients, converts them to generated types/Pick aliases and keeps POS runtime behavior unchanged. |
| MINOR 1 | Append-only anchor is `RepositoryMovement.php:73`; web wrapper is `routes/index.tsx:1937`; command registration is `TreasuryServiceProvider.php:236` with Console imports at `:37`. |

Deliver two prerequisites: location custody defaults on Treasury → Repositories, and one immutable justifying document for each new back-office repository transfer. Six tasks maximum, plus the separately gated P0 precision prerequisite. Exclude shift-event booking, v2/v3 adapters, W7, variance enablement, drawer sessions, typed device cash reasons and historical alignment.

## Industry baseline — convention 10

Flow: cash custody setup and internal repository transfer. Odoo reference is specifically version 17, not a claim about all later releases. ERPNext and Dolibarr references are their unversioned documentation accessed 2026-09-06. NV means not verified, not absent. Sources: [Odoo 17 internal transfers](https://www.odoo.com/documentation/17.0/applications/finance/accounting/payments/internal_transfers.html), [ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry), [Dolibarr Banks and Cash](https://wiki.dolibarr.org/index.php/Module_Banks_and_cash). Odoo’s paired-liquidity behavior and Dolibarr account transfers are supplied benchmark facts; do not infer their exact document schema or retry guarantees.

| ID | Guarantee | Odoo | ERPNext | Dolibarr or NV | AutoERP today path:line | Gap | Decision MATCH/DEFER/DIVERGE/ALREADY |
|---|---|---|---|---|---|---|---|
| B1 create | Internal movement has supporting evidence and balanced money effects | Paired liquidity entries | Internal Transfer Payment Entry | Account transfer; document shape NV | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:76` creates optional draft; `:89` writes legs; `:108` returns no document | Justifying document missing | MATCH — T1/T3; retain zero JE for same GL |
| B2 duplicate | Duplicate action cannot move funds twice | Operation UUID guarantee NV | Operation UUID guarantee NV | NV | `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:296` uses group-based leg keys | No company-operation document identity | DIVERGE — explicit stronger company-operation contract, T3 |
| B3 edit | Editing defaults cannot rewrite an executed transfer | Exact custody-default revision behavior NV | Exact revision behavior NV | NV | `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:165` validates mutable repository fields | No custody revision/evidence snapshot | DIVERGE — immutable configuration revisions and documents, T1/T2 |
| B4 cancel/reverse | Correction preserves original evidence | Exact linked-document policy NV | Payment Entry supports cancellation; exact proposed shape NV | NV | `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:369` writes null reversal link for transfers | No linked transfer reversal | MATCH — compensating document and pair, T4 |
| B5 rerun | A retry returns an explicit existing outcome | Exact response NV | Exact response NV | NV | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:113` reports replay of both legs | Replay lacks document and stable company-operation semantics | ALREADY for paired replay; MATCH document extension, T3 |
| B6 second company | Each company’s configuration and operation identity are independent | Exact UUID scope NV | Internal transfer between company cash/bank accounts | NV | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:121` scopes repositories; `PaymentRepositoryController.php:80` validates codes tenant-wide | New keys must include company; HTTP code validation disagrees | MATCH — T1/T2/T6 |
| B7 second location | Selected branch controls source custody and defaults | Branch-specific policy NV | Exact drawer policy NV | NV | `apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31`; `RepositoryTransferController.php:28` passes no location intent | Transfer adapter has no branch check | DIVERGE — W1 source-custody policy, T5 |
| B8 permission | Unauthorized source IDs are inaccessible without side effects | Exact scoped-404 policy NV | Exact scoped-404 policy NV | NV | `apps/api/app/Modules/Treasury/Presentation/routes.php:102` uses `treasury.transfer`; outer middleware at `:35` has no module gate | Permission exists; module/source checks missing | MATCH permission defense; DIVERGE scoped-404 contract, T5 |
| B9 audit | Evidence identifies actor, legs and accounting effect | Accounting entries | Operational document and ledger effect | Account records; exact evidence NV | `RepositoryMovement.php:73` is append-only; `RepositoryTransferController.php:41` returns group/JE/legs | No immutable action record | MATCH — T1/T3/T4 |
| B10 document per action | A new transfer has durable justification and removes its known documentless compensation exception | Liquidity accounting evidence; exact code ratchet NV | Payment Entry justification | Document shape NV | `apps/api/tests/Architecture/baselines/document-per-action-baseline.json:34`; `DocumentPerActionBaselineRatchetTest.php:78` | Service improvement must shrink its baseline in the same task | MATCH — T3 prepares replacement; T5 atomically removes only the Treasury exception |

Vocabulary — convention 11: **Repository** exists (`docs/glossary.md:60`), canonical surface Treasury → Repositories. **Cash custody configuration** is NEW: `cash_custody_configurations`, Treasury, sole writer `CashCustodyConfigurationService`, a location setting mode on that existing surface; synonym “custody defaults.” **Repository transfer document** is NEW: `repository_transfer_documents`, Treasury, sole writer `RepositoryTransferService`, existing transfer modal and repository detail history; synonym “transfer justification.” Add both glossary rows in T1. A revision is history of the same configuration, not another catalogue. A reversal is another repository transfer document, not a separate concept/table. The new glossary row explicitly ties this evidence concept to the document-per-action invariant and removal of the Treasury baseline exception at T5 activation; the architecture baseline is not a third business concept or surface.

Second-of-everything — convention 09: every T1–T6 acceptance below includes real second-company, real second `pos_enabled` location/provisioned drawer and actual mutation-rerun coverage. T1/T2 intentionally share an atomic acceptance boundary so schema-only work cannot substitute migration rerun for a business mutation. No tenant-only new catalogue unique and no ratchet-ceiling increase.

## Owner rulings — RULED, verbatim

Authority: `docs/handoff/OWNER-RULINGS-parapharmacy-remediation-2026-09-05.md:147`, section “Q10–Q13 RULED”; all recommended defaults accepted. Q11–Q13 rows below are verbatim.

| ID | Ruling |
|---|---|
| **Q11** | One cash-bearing shift per drawer at a time; a second terminal joins the open drawer session without a second float, or is refused. The drawer session is the custody unit; terminal sessions attribute sales. |
| **Q12** | Typed reason codes on the device: `SAFE_DROP`, `BANK_DEPOSIT`, `PETTY_EXPENSE`, `FLOAT_TOP_UP`, `OTHER`; each mapped in configuration to a destination (safe/bank transfers; petty expense = expense document); `OTHER` blocked until classified; v2 `DEPOSIT`/`PAYOUT` mapped by a cutover table. |
| **Q13** | One counted, dated alignment per repository at cutover, booked as document + movement + JE to cash-difference gain/loss; no retroactive rebooking; the disabled variance window is closed by that alignment. |

The Q11 drawer-session model, Q12 device reason-code enum/mapping and Q13 counted historical alignment are **DEFERRED to later W-CASH slices under these rulings**. No owner decision is outstanding for this slice. No reason-code enum, drawer-session model, historical alignment, shift booking, v2/v3 adapter, W7 or variance activation ships here.

## Verified seams and implementation contracts

Existing signatures, retained unless explicitly replaced below:

- `RepositoryTransferService::transfer(string $tenantId, string $companyId, string $fromRepositoryId, string $toRepositoryId, string $amount, ?string $notes, ?string $transferGroupId, string $userId): RepositoryTransferResult` — `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:33`.
- `TreasuryMovementServiceInterface::transfer(TransferIntent $intent): TransferResult` — `apps/api/app/Shared/Contracts/Treasury/TreasuryMovementServiceInterface.php:96`; implementation `TreasuryMovementService.php:225`.
- `GeneralLedgerService::createRepositoryTransferJournalEntry(string $companyId, string $tenantId, string $transferGroupId, string $fromGlAccountId, string $toGlAccountId, string $amount, \DateTimeInterface $date, string $description): JournalEntry` — `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1497`. It creates a draft, not a posted entry (`:1490`); movement transfer posts it at `TreasuryMovementService.php:347`. Correct the shared contract’s contradictory “pre-posted” wording at `TreasuryMovementServiceInterface.php:76`.
- `LocationScopeResolver::resolve(User $user, array $requestedIds = [], ?string $bypassPermission = null): array` — `apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31`. HTTP-only restriction is explicit at `:12`.
- `PaymentRepositoryController::{index(Request $request), show(Request $request, string $id), store(Request $request), update(Request $request, string $id)}: JsonResponse` — `PaymentRepositoryController.php:33,51,69,153`.
- `RepositoryTransferController::store(TransferRepositoryRequest $request): JsonResponse` — `RepositoryTransferController.php:21`; `TransferRepositoryRequest::rules(): array` — `TransferRepositoryRequest.php:19`.

All new PHP production types use strict types and constructor injection; DTOs extend Spatie Data and are exported by the existing TypeScript transform. All money is numeric-string, normalized with the explicit repository currency; reject nonpositive, excess precision, overflow, incompatible currencies, inactive/virtual repositories and self-transfer at the service boundary as well as HTTP.

## P0 prerequisite — P0 precision widening

**Amendments A/B, binding technical premise; sequencing remains exclusively in § Dispatch order (see dispatch order):**

1. The four target columns are already `decimal(15,3)` on a fully migrated tenant because `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:27-30,113-116` widened them. P0-b widens `(15,3)→(15,4)` only. Accepted source tuples are exactly `(15,3,NO,0)` / `(15,3,YES,NULL)` or already-compliant `(15,4,…)`; every other tuple, including scale two, aborts before DDL.
2. Owner row A10 provisionally rules option A: Eloquent casts for `PaymentRepository` balance fields and `JournalLine` debit/credit stay `decimal:3`. P0-a supplies the standing `fourth_decimal_present` guard. Any non-zero result is a hard P0-b block. Cast widening is a separate future lane, all 19 Treasury/Accounting casts together, conditioned on a 4-dp preset, a 4-dp `SCALE_MAP` entry or a non-zero detector result. The T3 operational ceiling is independently governed by its movement-ledger removal condition.
3. `docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md:50-68` is authoritative for P0 command, service, DTOs, classifications, output marker, migration and tests; `:103-117` is authoritative for deployment identifiers and variables. The five classifications are exactly `Money`, `Percent`, `Quantity`, `Geometry`, and `Other`; `pos_tables.*` is `Geometry`.
4. Task eligibility follows the single binding statement in § Dispatch order; see dispatch order.

P0 is a separately accepted program-level engineering task outside the six WCASH tasks. The detector, migration and task dependencies are technical gates; their operative sequencing is stated only in § Dispatch order.

Binding owner follow-up A1 is at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:82`: storage capacity widens to a maximum scale of four while business validation, computation, rounding and display precision remain the company country preset in `countries.currency_decimal_places`. The provisional cast ruling is at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:91`. The resolver reads that preset at `apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:54-64` at reviewed HEAD `55bf3d14204363ab857738f785a3c405100da9db`; no service may treat storage scale four as operational precision four.

| Physical column | Original CREATE definition | Existing widening at current HEAD | Required verified shape |
|---|---|---|---|
| `payment_repositories.balance` | `2025_11_30_120000_create_treasury_tables.php:29`, decimal(15,2), NOT NULL DEFAULT 0 | `2026_03_11_200000_widen_monetary_columns_to_scale_3.php:114` | numeric(15,4) NOT NULL DEFAULT 0 |
| `payment_repositories.last_reconciled_balance` | Same CREATE `:31`, decimal(15,2), nullable | Same widening `:115` | numeric(15,4) NULL, no default |
| `journal_lines.debit` | `2025_11_30_100000_create_journal_entries_table.php:51`, decimal(15,2), NOT NULL DEFAULT 0 | March widening `:28` | numeric(15,4) NOT NULL DEFAULT 0 |
| `journal_lines.credit` | Same CREATE `:52`, decimal(15,2), NOT NULL DEFAULT 0 | March widening `:29` | numeric(15,4) NOT NULL DEFAULT 0 |

Do not infer live scale from the original CREATE: the March migration executes ALTER at `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:178-193` and skips SQLite at `:182`. Models currently cast repository fields scale 3 (`apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:214-215`) and GL debit/credit scale 3 (`apps/api/app/Modules/Accounting/Domain/JournalLine.php:54-55`); those casts remain unchanged provisionally.

P0-b updates `docs/architecture/precision-contract.md:9,17` and Rule 19 at `CLAUDE.md:72-75`: the canonical money storage floor becomes `decimal(N,4)`, while ingress, computation, rounding, display and provisional Eloquent serialization continue at country-resolved scale. The update must cite NC 01 §62 verbatim: « L'arrondi n'est pas admis dans l'enregistrement des opérations. Il n'est admis que pour la présentation. » (`docs/superpowers/reviews/2026-09-07-benchmark-money-precision-4-decimals-casts.md:39-49,58-63`).

Laravel’s `decimal:3` cast silently HALF_UP-rounds a stored fourth decimal at `apps/api/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php:1512-1515`. Odoo, ERPNext and Dolibarr store GL values wider than operational precision and do not narrow on read (`benchmark-money-precision-4-decimals-casts.md:43-56`). No 4-dp preset exists: `CountriesSeeder` contains 3×0, 30×2, 7×3 and zero 4 among 40 presets; `CurrencyScale::SCALE_MAP` has no 4-dp entry (`apps/api/app/Shared/Domain/CurrencyScale.php:20-47`). Casts→4 would break 192 strict `assertSame` assertions across 34 files (`benchmark-money-precision-4-decimals-casts.md:69-77`). `CloseInvoiceWithToleranceService.php:78-82` deliberately calls `outstandingBalance(3)`. Moving only the four target casts among 19 would recreate the heterogeneous numeric-string shape documented at `apps/api/app/Modules/Treasury/Domain/Services/PaymentRefundService.php:1461-1469`.

Conclusion: the four casts stay `decimal:3` provisionally; `fourth_decimal_present` is the standing guard; widening casts is a separate future lane covering all 19 casts. P0 closes verified schema drift only and cannot reconstruct missing historic fractions.

NEW P0-b migration: `apps/api/database/migrations/tenant/2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php`, anonymous migration with `up(): void` and `down(): void`. It is a non-additive PostgreSQL schema alteration and never ships in P0-a. Before any DDL, inspect all four complete tuples. Accept only exact `(15,3,NO,0)`, `(15,3,YES,NULL)` or matching `(15,4,…)`. Any other precision, scale, nullability or default throws `RuntimeException('P0-PRECISION: status=failed reason=unexpected_column_shape column=<table.column> found=<tuple>')` before any ALTER. All four at scale four emit `P0-PRECISION: status=already_compliant` and perform no DDL. Otherwise issue the two fixed ALTER statements from the authoritative brief, preserve defaults/nullability/triggers, assert all post-tuples and emit `P0-PRECISION: status=widened columns=4`. Driver other than pgsql returns. `down()` is a documented forward-only no-op.

The migration docblock cites the ruling, premise correction and lock profile: numeric-scale ALTER rewrites both tables under `ACCESS EXCLUSIVE`, requiring a bounded outage with money writes stopped. It also copies the full cast/storage decision record above: NC 01 §62; `HasAttributes.php:1512-1515` HALF_UP read rounding; the wider-storage/no-read-narrowing benchmark; no 4-dp preset; 192 strict assertions across 34 files; `CloseInvoiceWithToleranceService.php:78-82`; `PaymentRefundService.php:1461-1469`; provisional option A; standing detector; and the future all-19-casts lane.

NEW PG test file `apps/api/tests/Feature/Schema/GlAndRepositoryBalanceScale4Test.php` methods:

- `test_target_columns_have_full_tuple_15_4(): void`.
- `test_journal_line_round_trips_1_0005_raw(): void`.
- `test_repository_balance_round_trips_1_0005_raw_via_port_guc(): void`.
- `test_legacy_scale_3_shape_is_widened_and_reported(): void`.
- `test_rerun_on_compliant_schema_is_already_compliant_and_changes_nothing(): void`.
- `test_unexpected_shape_refuses_before_ddl(): void`.
- `test_scale_two_shape_refuses_before_ddl_and_changes_nothing(): void`.

The scale-two test deliberately changes one target to `(15,2,…)`, invokes `up()`, first asserts `reason=unexpected_column_shape`, then asserts zero DDL, byte-identical rows, identical tuples for all four columns and unchanged migration ledger. No successful fixture uses scale two. Raw repository writes use `SET LOCAL app.treasury_movement_port = 'on'`; a sibling no-GUC assertion proves the trigger remains live.

NEW architecture ratchet `apps/api/tests/Architecture/MoneyStorageScale4RatchetTest.php::money_storage_target_columns_have_scale_4_full_tuple(): void`, with NEW support `apps/api/tests/Architecture/Support/MoneyStorageScaleChecker.php`. It asserts precision, scale, nullability and normalized default for all four columns; liveness providers damage each property separately. Exact P0-b command from `apps/api`, lane **backend-test-pgsql / precision-4**:

```sh
php artisan test -c phpunit-pgsql.xml tests/Feature/Schema/GlAndRepositoryBalanceScale4Test.php tests/Architecture/MoneyStorageScale4RatchetTest.php
```

T3’s same/cross-GL fixture uses a TND company whose country preset is three and transfers `1.005`, comparing raw document, movements, `balance_after`, repository balances and debit/credit. TND remains limited to three operational decimals even though the four target columns store four.

### P0-a / P0-b deployment packages

Both packages are governed by `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`. P0-a is the census-only commit/deployment within lane `precision-4`; P0-b is the separately promoted non-additive migration/docs/test commit from the same authoritative lane. They never share a push. Operative sequencing: see dispatch order.

| Manifest variable | P0-a — precision census tooling | P0-b — non-additive scale-four widening |
|---|---|---|
| `<slice>` | `precision-4` | `precision-4` |
| Exact contents | Read-only `treasury:census-money-precision` command, service, enum, DTOs and `MoneyPrecisionCensusCommandTest` only, including the `fourth_decimal_present` shape. No migration, cast change, write path or precision-contract mutation. | `2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php`, scale-four tests/ratchet, precision-contract/Rule-19 updates, follow-up ticket and handback. Casts remain `decimal:3`. |
| Migration marker | None. Any pending scale-four migration in the P0-a image is packaging failure. Census marker is `MONEY-PRECISION CENSUS`. | `P0-PRECISION: status=widened columns=4` or `P0-PRECISION: status=already_compliant`; refusal emits `P0-PRECISION: status=failed reason=unexpected_column_shape ...`. |
| Exact command | Run `php artisan treasury:census-money-precision --tenant=<verified-tenant-uuid> --json` separately for every inventoried tenant and retain the aggregate `php artisan treasury:census-money-precision --json`. Standalone, never under `tenants:run`. | DB-per-tenant: boot and explicit `php artisan tenants:migrate-rolling --force`, then `--tenant=<verified-tenant-uuid>` per tenant. Compatibility mode uses the exact shared-connection command below. |
| Schema/data census | Capture JSON, exit status, database/schema, four source tuples, measurable drift and classified below-scale-four columns. Each tenant has exact `fourth_decimal_present` aggregate/per-column counts and sample IDs. Non-zero exits 1 and blocks P0-b without waiver; exit 2 blocks. | Capture post-tuples, migration marker and post-census JSON with detector count zero. Exactly four ruled columns are widened. |
| Flags | None. | None. |
| Web/API behavior | No HTTP/API/web behavior; one direct read-only command. | No HTTP/API/web behavior. Storage capacity changes; casts and operational precision do not. |
| Device behavior/build | None. | None. |
| Queues | None. | None. |
| Collapsed pushes | Authoritative Push 1: census in its own commit/push. Never collapse with P0-b or WCASH. | Separate non-additive Push 2 exception. Manifest additive Push 2 does not absorb it. Sequencing: see dispatch order. |
| Environment changes | None. U-1/U-2/U-5 still resolve topology, connection and backup targets. | None. U-1/U-2/U-5 must be evidenced. |
| Host-side backup | None; read-only code and no schema/backfill. | Required outside the container: one non-zero restore-readable `pg_dump -Fc` per physical target database. |
| Rollback point | Revert tooling; preserve evidence. A non-zero detector is an investigation stop, not a P0-a repair target. | Transaction rollback before commit. After commit retain scale-four schema and provisional scale-three casts, never narrow, stop writes and correct forward. |

The detector/reviewer, outage, backup and no-op-evidence requirements remain mandatory technical gates. Operative sequencing is only in § Dispatch order; see dispatch order. P0 handback reports the fleet finding to Fable and attaches treasury-reviewer + stock-gl-interaction-reviewer reviews without labelling tenants defective from CREATE text alone.

**P0 complete shape contract:** expected post-tuples are balance `(15,4,NO,0)`, last-reconciled balance `(15,4,YES,NULL)`, debit and credit `(15,4,NO,0)`. Accepted pre-tuples are only balance/debit/credit `(15,3,NO,0)` and last-reconciled balance `(15,3,YES,NULL)`; matching `(15,4,…)` is already compliant. Normalize only PostgreSQL zero spellings, optional parentheses and numeric casts to `0`; SQL NULL stays NULL. Any other tuple, including `(15,2,…)`, refuses with `reason=unexpected_column_shape` before DDL. Tests assert complete tuples before, after and on rerun. The scale-two red fixture asserts no DDL and unchanged rows/schema. Liveness providers separately damage precision, scale, nullability and default. Migration never drops defaults.

**P0 pre-ALTER census:** NEW `apps/api/app/Modules/Treasury/Presentation/Console/MoneyPrecisionCensusCommand.php`, FQCN `App\Modules\Treasury\Presentation\Console\MoneyPrecisionCensusCommand`, extends `App\Console\TenantScopedCommand`; constructor `__construct(CompanyContext $companyContext, MoneyPrecisionCensusService $census)` calls `parent::__construct($companyContext)`; `protected function executeCommand(): int`. Signature `treasury:census-money-precision {--tenant=} {--json}`.

NEW `apps/api/app/Modules/Treasury/Application/Services/MoneyPrecisionCensusService.php`, FQCN `App\Modules\Treasury\Application\Services\MoneyPrecisionCensusService`; public signature `inspect(string $tenantId): MoneyPrecisionCensusData`, using one read-only REPEATABLE READ transaction on the bound tenant connection. Register the command at `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:237`. No insert, update, delete, DDL or log-table write is permitted.

NEW DTOs under `App\Modules\Treasury\Application\DTOs`:

- `MoneyPrecisionCensusData.php`: `__construct(string $tenant_id, string $database_name, bool $complete, int $measurable_drift_count, array $checks, array $columns_below_scale_4, array $fourth_decimal_present)`, where checks is `list<MoneyPrecisionCheckData>`, columns is `list<MoneyColumnShapeData>`, and `fourth_decimal_present` has exact shape `{count:int,sample_ids:list<string>,columns:array<string,{count:int,sample_ids:list<string>}>}`.
- `MoneyPrecisionCheckData.php`: `__construct(string $check, int $examined_count, int $drift_count, array $sample_ids)`.
- `MoneyColumnShapeData.php`: `__construct(string $table, string $column, int $precision, int $scale, bool $nullable, ?string $default, string $classification)`.

NEW backed enum `apps/api/app/Modules/Treasury/Domain/Enums/MoneyColumnClassification.php`, FQCN `App\Modules\Treasury\Domain\Enums\MoneyColumnClassification`, has exactly `Money`, `Percent`, `Quantity`, `Geometry`, and `Other`.

Fixed census checks are `target_shape`, `fourth_decimal_present`, `repository_last_movement`, `journal_entry_balance`, and `columns_below_scale_4`. `target_shape` accepts only the exact pre/post tuples. `fourth_decimal_present` scans `journal_lines.debit`, `journal_lines.credit`, `payment_repositories.balance`, and nullable `payment_repositories.last_reconciled_balance` with exact predicate:

```sql
value IS NOT NULL AND ((value * 10000)::bigint % 10) <> 0
```

It counts target-column hits per tenant and per column; a row present in two target columns counts twice. It returns deterministic sample keys `<table>.<column>:<row-id>`, sorted, first 20 per column and aggregate. `repository_last_movement` compares repository balance with latest movement by ordinal. `journal_entry_balance` compares raw debit and credit sums, with drafts prefixed `draft:`. `columns_below_scale_4` classifies every numeric column below scale four: Percent for the authoritative suffix/field allowlist, Geometry for `pos_tables.*`, Quantity for authoritative weight/hours/time allowlist, Other only where explicitly curated, and Money otherwise. Only Money feeds the follow-up ticket.

Output is one grep-stable line per tenant:

```text
MONEY-PRECISION CENSUS tenant=<uuid> db=<name> status=clean|drift|incomplete measurable_drift=<n> fourth_decimal_present=<n> money_columns_below_scale_4=<n>
```

Fleet JSON uses a `tenants` array; each tenant object contains:

```json
{
  "fourth_decimal_present": {
    "count": 0,
    "sample_ids": [],
    "columns": {
      "journal_lines.debit": {"count": 0, "sample_ids": []},
      "journal_lines.credit": {"count": 0, "sample_ids": []},
      "payment_repositories.balance": {"count": 0, "sample_ids": []},
      "payment_repositories.last_reconciled_balance": {"count": 0, "sample_ids": []}
    }
  }
}
```

Exit 0 means complete and clean with detector zero. Exit 1 means drift; any non-zero `fourth_decimal_present.count` is a non-waivable P0-b block, while other drift requires both named reviewers to classify. Exit 2 means incomplete and always blocks. Capture stdout, exit code and connection identities before ALTER. Below-floor findings are capacity inventory, not proof of historical loss.

Read-only comparisons use raw numeric SQL/text and decimal-string arithmetic. Missing independent evidence is not called clean historical data. Current port stores `balance_after` at `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:590` and source identity at `:592-593` at HEAD `55bf3d14204363ab857738f785a3c405100da9db`; ORM padding is not evidence of loss.

NEW follow-up ticket `docs/superpowers/tickets/2026-09-07-money-columns-below-scale-4-followup.md` contains the fresh census Money list with table, column, tuple and module owner, grouped by module; `repository_movements.amount`, `repository_movements.balance_after` and `accounts.balance` are highest priority. Percent, Quantity, Geometry and Other remain visible but do not enter the money ticket.

NEW `apps/api/tests/Feature/Treasury/MoneyPrecisionCensusCommandTest.php`, class `Tests\Feature\Treasury\MoneyPrecisionCensusCommandTest`, PG lane **backend-test-pgsql / precision-4**, methods:

- `test_reports_clean_on_fresh_tenant(): void`.
- `test_detects_repository_balance_vs_last_movement_drift(): void`.
- `test_detects_unbalanced_journal_entry(): void`.
- `test_lists_money_columns_below_scale_4_with_classification(): void`.
- `test_json_output_matches_dto(): void`.
- `test_two_tenants_are_scoped_and_rerun_is_read_only(): void`.
- `test_incomplete_scan_exits_two(): void`.
- `test_fourth_decimal_present_blocks_p0_b_and_reports_sample_ids(): void`.

The last test creates one repository row, transactionally makes the target capable of retaining scale four when necessary, executes `SET LOCAL app.treasury_movement_port = 'on'`, writes raw `balance='1.0005'`, invokes the tenant-targeted census, and first asserts:

```php
$this->assertSame(1, $payload['tenants'][0]['fourth_decimal_present']['count']);
```

It then asserts per-column count 1, exact sample ID `payment_repositories.balance:<repository-uuid>`, human key `fourth_decimal_present=1`, exit 1, unchanged rows and rollback-safe setup.

Exact red command from `apps/api`:

```sh
php artisan test -c phpunit-pgsql.xml tests/Feature/Treasury/MoneyPrecisionCensusCommandTest.php --filter='MoneyPrecisionCensusCommandTest::test_fourth_decimal_present_blocks_p0_b_and_reports_sample_ids'
```

Full class command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=MoneyPrecisionCensusCommandTest
```

The classification test asserts all five enum cases and `pos_tables.* = Geometry`; the read-only test asserts byte-equal output and unchanged table snapshots.

**P0 convention 09:** move the common real-provisioning helper into the precision-4 test set so it needs no T1 schema. Extend `GlAndRepositoryBalanceScale4Test` with `test_second_company_second_location_movement_rerun_is_idempotent(): void`, using registration A, POST company B and a real second POS-enabled location/drawer. Call `TreasuryMovementServiceInterface::record(MovementIntent $intent): MovementResult` (`TreasuryMovementService.php:49`) twice with amount `1.005`, fixed source UUID/leg and explicit tenant/company. Assert `wasIdempotentHit === true` (`MovementResult.php:16`), identical IDs/balanceAfter/ordinal, one raw balance delta, one movement and unchanged company-A/first-location snapshots.

**Topology execution supplement for P0-b and WCASH Push 2:** rolling returns a successful no-op in compatibility mode (`RollingTenantMigrationCommand.php:57`); tenant migrations are auto-loaded only in testing (`AppServiceProvider.php:283`). Record topology, Laravel connection name and `SELECT current_database(), current_schema()` before execution. In DB-per-tenant mode require every tenant visited with no failures/skips, then rerun `--tenant=<verified-tenant-uuid>` per inventoried tenant. Enumerate every pending migration before rollout. Sequencing: see dispatch order.

In shared-database mode set `WCASH_SHARED_CONNECTION` to the verified configured connection name and run each file only in its designated package:

```sh
php artisan migrate --force --database="$WCASH_SHARED_CONNECTION" --path=database/migrations/tenant/2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php
php artisan migrate --force --database="$WCASH_SHARED_CONNECTION" --path=database/migrations/tenant/2026_09_06_210000_create_cash_custody_configurations.php
php artisan migrate --force --database="$WCASH_SHARED_CONNECTION" --path=database/migrations/tenant/2026_09_06_210100_create_repository_transfer_documents.php
```

Require exit 0 and filename/DONE or Nothing-to-migrate output. P0-b emits `P0-PRECISION: status=widened columns=4|already_compliant`; WCASH emits `WCASH-1-SCHEMA: migration=<basename> status=created|already_compliant`. A ledger-skipped migration still requires physical postchecks. Emit `WCASH-1-MIGRATION: topology=shared connection=<verified-name> database=<actual-db> migration=<basename> status=verified` only after exit and postchecks succeed.

Post-command SQL on the same verified connection, also per tenant after rolling:

```sql
SELECT current_database(), current_schema();

SELECT table_name,column_name,data_type,numeric_precision,numeric_scale,is_nullable,column_default
FROM information_schema.columns
WHERE table_schema=current_schema()
AND ((table_name='payment_repositories' AND column_name IN ('balance','last_reconciled_balance'))
 OR (table_name='journal_lines' AND column_name IN ('debit','credit')))
ORDER BY table_name,column_name;

SELECT table_name,column_name,data_type,udt_name,is_nullable,column_default,numeric_precision,numeric_scale
FROM information_schema.columns
WHERE table_schema=current_schema()
AND table_name IN ('cash_custody_configurations','repository_transfer_documents')
ORDER BY table_name,ordinal_position;

SELECT table_name,constraint_name,constraint_type
FROM information_schema.table_constraints
WHERE table_schema=current_schema()
AND table_name IN ('cash_custody_configurations','repository_transfer_documents')
ORDER BY table_name,constraint_name;

SELECT c.relname,t.tgname,pg_get_triggerdef(t.oid)
FROM pg_trigger t
JOIN pg_class c ON c.oid=t.tgrelid
JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname=current_schema()
AND NOT t.tgisinternal
AND c.relname IN ('cash_custody_configurations','repository_transfer_documents','repository_movements');

SELECT c.relname,k.conname,pg_get_constraintdef(k.oid)
FROM pg_constraint k
JOIN pg_class c ON c.oid=k.conrelid
JOIN pg_namespace n ON n.oid=c.relnamespace
WHERE n.nspname=current_schema()
AND c.relname IN (
  'companies','users','locations','payment_repositories','repository_movements',
  'journal_entries','pos_terminals','cash_custody_configurations','repository_transfer_documents'
);
```

P0-b requires exactly four complete scale-four tuples while retaining four scale-three Eloquent casts. WCASH Push 2 additionally compares every T1 column, FK action/ordering, CHECK, unique, trigger and function definition. Include `pg_get_functiondef`; names alone do not prove correctness. No synthetic live money insertion: raw `1.0005` proof belongs to isolated PG tests.

NEW handback `docs/superpowers/reviews/2026-09-07-precision-4-handback-for-wcash-1.md` lists delivered files, markers, `(15,4)`/`1.0005` correction, provisional casts and merge SHA.

## Common real-provisioning fixture and task boundary

Every P0/T1–T6 test named “second company/location” uses NEW `apps/api/tests/Support/CreatesWcashCompanyAndLocations.php` method `createWcashCompaniesAndSecondPosLocation(): WcashProvisionedFixture` with NEW `apps/api/tests/Support/WcashProvisionedFixture.php`, FQCN `Tests\Support\WcashProvisionedFixture`, constructor:

```php
__construct(
    string $tenantId,
    string $companyAId,
    string $companyBId,
    string $userId,
    string $firstLocationId,
    string $secondLocationId,
    string $provisionedDrawerId,
)
```

Register tenant/company A through `/api/v1/auth/register` (`TenantInitializationTest.php:158`); create B through authenticated POST `/api/v1/companies` (`CompanyPaymentRepositoryProvisioningTest.php:95`), then switch real company context/header. Create a second shop through POST `/api/v1/locations` (`Inventory/Presentation/routes.php:41`, module Inventory at `:31`, `inventory.adjust` at `:42`) with unique code/name, type shop, `pos_enabled=true` and valid country tax fields. `LocationController.php:181` persists `pos_enabled` at `:211` and calls drawer provisioning at `:216`/`:246`; `CreateLocationRequest.php:29,70` requires valid payload/tax rules. Assert the returned location is POS-enabled and its auto-provisioned active cash register has company B and the second location. No raw Company/Location inserts or factories substitute for these creation paths.

**T1/T2 combined acceptance:** both tasks share one acceptance boundary. `CashCustodyConfigurationService::save` runs twice against the real second location, asserting `Unchanged`, same configuration ID/revision and no duplicate. Migration rerun remains schema evidence, never the convention-09 business rerun. Eligibility and sequencing: see dispatch order.

## T1 — Schemas, vocabulary and typed contracts

Verified anchors: movement schema `apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:15`; immutable model `RepositoryMovement.php:73`; repository types `RepositoryType.php:7`; existing transfer DTO `TransferIntent.php:26`. Update `docs/glossary.md:60` with the two NEW rows.

NEW migrations, in order:

1. `apps/api/database/migrations/tenant/2026_09_06_210000_create_cash_custody_configurations.php`
2. `apps/api/database/migrations/tenant/2026_09_06_210100_create_repository_transfer_documents.php`

Each anonymous migration implements `up(): void` and `down(): void`; use existence guards plus fail-loud shape verification on direct rerun. PostgreSQL is authoritative.

NEW `apps/api/tests/Integration/Treasury/CashCustodyMigrationGuardTest.php`, class `Tests\Integration\Treasury\CashCustodyMigrationGuardTest`, methods:

- `test_configuration_migration_refuses_wrong_shape_without_changes(): void`.
- `test_document_migration_refuses_wrong_shape_without_changes(): void`.
- `test_direct_compliant_rerun_reports_already_compliant(): void`.

PG command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=CashCustodyMigrationGuardTest
```

Providers construct independently malformed disposable states: partial child table, wrong column/default/nullability, wrong parent composite, wrong FK/actions, missing/wrong CHECK/unique, missing/wrong immutable/deferred trigger/function. Invoke `up()` directly. First assertion is exact `RuntimeException`:

```text
WCASH-1-SCHEMA: migration=2026_09_06_210000_create_cash_custody_configurations reason=unexpected_shape
```

or:

```text
WCASH-1-SCHEMA: migration=2026_09_06_210100_create_repository_transfer_documents reason=unexpected_shape
```

Assert pg_catalog definitions, rows and migration ledger unchanged. Compare expected function bodies and constraints semantically, not only names.

Unless specified, columns are NOT NULL, have no default and FKs use ON DELETE/UPDATE RESTRICT. UUID primary keys are application-generated. No soft deletes or `updated_at`. Tenant IDs never reference the central tenant table across database boundaries. Enforce local ownership: `(tenant_id,company_id)` references `companies(tenant_id,id)` and `(tenant_id,created_by)` references `users(tenant_id,id)`. Add owned parent uniques `(tenant_id,id)` where absent.

`cash_custody_configurations`:

| Column | SQL type | Null/default | FK |
|---|---|---|---|
| id | uuid | primary key | — |
| tenant_id | uuid | required | ownership composites |
| company_id | uuid | required | companies(tenant_id,id) |
| location_id | uuid | required | locations(company_id,id) |
| revision | bigint | required | — |
| default_safe_repository_id | uuid | NULL | payment_repositories(tenant_id,company_id,id) |
| default_bank_repository_id | uuid | NULL | payment_repositories(tenant_id,company_id,id) |
| enabled | boolean | DEFAULT false | — |
| created_by | uuid | required | users(tenant_id,id) |
| created_at | timestamptz | DEFAULT CURRENT_TIMESTAMP | — |

Checks: revision > 0; enabled implies non-null safe; safe and bank differ. Unique `(company_id,location_id,revision)` and `(company_id,id)`; index `(company_id,location_id,revision DESC)`. Current is highest revision. Add `(company_id,id)` on locations if absent and remove only migration-owned additions in `down()`. No JSON. Model and PG BEFORE UPDATE/DELETE guards reject mutation.

`repository_transfer_documents`:

| Column | SQL type | Null/default | FK |
|---|---|---|---|
| id | uuid | primary key | — |
| tenant_id | uuid | required | ownership composites |
| company_id | uuid | required | companies(tenant_id,id) |
| operation_uuid | uuid | required | — |
| transfer_group_id | uuid | required | — |
| kind | varchar(16) | required | enum/check |
| from_repository_id | uuid | required | payment_repositories(tenant_id,company_id,id) |
| to_repository_id | uuid | required | payment_repositories(tenant_id,company_id,id) |
| source_location_id | uuid | NULL | locations(company_id,id) |
| amount | decimal(15,4) | required | — |
| currency | char(3) | required | — |
| out_movement_id | uuid | required | repository_movements(tenant_id,company_id,id) |
| in_movement_id | uuid | required | repository_movements(tenant_id,company_id,id) |
| journal_entry_id | uuid | NULL | journal_entries(tenant_id,company_id,id) |
| reverses_document_id | uuid | NULL | repository_transfer_documents(tenant_id,company_id,id) |
| evidence | jsonb | required, no default | `TransferDocumentEvidenceData` |
| notes | varchar(1000) | NULL | — |
| occurred_at | timestamptz | required | — |
| initiator_kind | varchar(8) | required | human/system |
| created_by | uuid | NULL, human only | users(tenant_id,id) |
| system_authority | varchar(24) | NULL, system only | enum |
| system_principal | varchar(255) | NULL, system only | — |
| provenance_source_id | uuid | NULL, system only | — |
| provenance_terminal_id | uuid | NULL; required for fiscal_projection | pos_terminals(tenant_id,company_id,id) |
| created_at | timestamptz | DEFAULT CURRENT_TIMESTAMP | — |

Checks: amount > 0; source != destination; out != in; uppercase currency; kind in transfer/reversal; transfer requires null `reverses_document_id`; reversal requires non-null/non-self; evidence is schema-version-1 object. Human requires user and null system fields; system requires no user and complete authority/principal/source. Fiscal projection requires terminal. Add owned terminal composite.

Uniques: `(company_id,id)`, `(company_id,operation_uuid)`, `(company_id,transfer_group_id)`, `(company_id,out_movement_id)`, `(company_id,in_movement_id)`, and partial `(company_id,reverses_document_id)` where non-null. Indexes cover company/from, company/to and company/location with occurred time. Add owned composites to repositories, movements, journal entries and documents when absent.

A deferred constraint trigger validates exactly two legs, directions, repositories, tenant, amount, currency, Transfer source/group and nullable JE; cross-GL requires one posted balanced JE, same-GL requires null JE. Movement INSERT rejects a third leg when a document exists. Reversal trigger validates opposite repositories, amount/currency, original transfer and movement reversal links. Evidence discriminator/scalars must match relational fields. SQLSTATE is 23514 on violations. BEFORE UPDATE/DELETE makes documents immutable.

NEW Treasury production types:

- `App\Modules\Treasury\Domain\CashCustodyConfiguration`.
- `App\Modules\Treasury\Domain\RepositoryTransferDocument`.
- `App\Modules\Treasury\Domain\Enums\RepositoryTransferDocumentKind` with Transfer=`transfer`, Reversal=`reversal`.
- `App\Modules\Treasury\Domain\Enums\RepositoryTransferOutcome` with Recorded=`recorded`, AlreadyRecorded=`already_recorded`.
- `App\Modules\Treasury\Domain\Enums\CashCustodySaveOutcome` with Saved=`saved`, Unchanged=`unchanged`.
- `App\Modules\Treasury\Domain\Enums\RepositoryTransferInitiatorKind` with Human=`human`, System=`system`.
- `App\Modules\Treasury\Domain\Enums\RepositoryTransferSystemAuthority` with FiscalProjection=`fiscal_projection`, ScheduledCommand=`scheduled_command`.

NEW `App\Modules\Treasury\Application\DTOs\TransferDocumentEvidenceData` constructor:

```php
__construct(
    int $schema_version,
    ?string $configuration_id,
    ?int $configuration_revision,
    ?string $from_gl_account_id,
    ?string $to_gl_account_id,
    bool $source_recorded_while_frozen,
    bool $destination_recorded_while_frozen,
    ?string $reversal_explanation,
    HumanTransferAuthorityData|SystemTransferAuthorityData $authorization,
)
```

NEW actor union:

- `App\Modules\Treasury\Application\DTOs\HumanTransferAuthorityData::__construct(string $tenant_id, string $company_id, string $user_id, array $allowed_source_location_ids, bool $company_wide_authority, string $permission)`.
- `App\Modules\Treasury\Application\DTOs\SystemTransferAuthorityData::__construct(string $tenant_id, string $company_id, RepositoryTransferSystemAuthority $authority, string $principal, string $source_id, ?string $terminal_id, ?string $source_location_id)`.

HTTP never deserializes system authority. Stable system principal is a trusted FQCN/command signature. Scheduled commands reuse durable invocation UUIDs. Changed authority/principal/source/terminal/location conflicts.

NEW shared POS provenance contract:

- `apps/api/app/Shared/Contracts/POS/TransferTerminalProvenanceResolver.php`, FQCN `App\Shared\Contracts\POS\TransferTerminalProvenanceResolver`, signature `resolve(string $tenantId, string $companyId, string $terminalId): TransferTerminalProvenanceData`.
- `apps/api/app/Shared/Contracts/POS/TransferTerminalProvenanceData.php`, FQCN `App\Shared\Contracts\POS\TransferTerminalProvenanceData`, constructor `__construct(string $tenantId, string $companyId, string $terminalId, string $locationId)`.
- NEW implementation `App\Modules\POS\Application\Services\TransferTerminalProvenanceService::resolve(string $tenantId, string $companyId, string $terminalId): TransferTerminalProvenanceData`, bound in `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:38` beside the shared binding at `:55`.

Other NEW DTO constructors:

- `CashCustodyConfigurationData::__construct(string $id, string $company_id, string $location_id, int $revision, ?string $default_safe_repository_id, ?string $default_bank_repository_id, bool $enabled)`.
- `CashCustodySaveResult::__construct(CashCustodyConfigurationData $configuration, CashCustodySaveOutcome $outcome)`.
- `RepositoryTransferDocumentData::__construct(string $id, string $operation_uuid, string $transfer_group_id, RepositoryTransferDocumentKind $kind, string $from_repository_id, string $to_repository_id, ?string $source_location_id, string $amount, string $currency, string $out_movement_id, string $in_movement_id, ?string $journal_entry_id, ?string $reverses_document_id, TransferDocumentEvidenceData $evidence, ?string $notes, string $occurred_at, RepositoryTransferInitiatorKind $initiator_kind, ?string $created_by, ?RepositoryTransferSystemAuthority $system_authority, ?string $system_principal, ?string $provenance_source_id, ?string $provenance_terminal_id, string $created_at)`.

NEW `apps/api/tests/Feature/Treasury/CashCustodySchemaTest.php`, lane **backend-test-pgsql / WCASH-1 isolated PG**, command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=CashCustodySchemaTest
```

| Exact test | First failing assertion |
|---|---|
| `CashCustodySchemaTest::test_schema_has_company_scoped_document_identity(): void` | `assertTrue(Schema::hasTable("repository_transfer_documents"))`; inspect operation unique. |
| `test_wrong_tenant_company_insert_is_rejected(): void` | SQLSTATE 23503; counts unchanged. |
| `test_wrong_tenant_actor_insert_is_rejected(): void` | SQLSTATE 23503; snapshot unchanged. |
| `test_foreign_repository_leg_and_journal_are_rejected(): void` | Each composite FK rejects 23503. |
| `test_documents_and_configurations_are_append_only(): void` | UPDATE/DELETE rejects 23514; bytes unchanged. |
| `test_third_leg_and_wrong_journal_are_rejected(): void` | Deferred trigger rejects 23514. |
| `test_second_registered_company_accepts_same_operation_uuid(): void` | Two company-owned documents share client UUID but have distinct groups. |
| `test_second_location_accepts_independent_configuration(): void` | Second location/safe/revision 1 stored; first unchanged. |
| `test_migration_rerun_reports_nothing_to_migrate(): void` | Second migrator output says “Nothing to migrate”; schema/data equal. |
| `test_actual_configuration_rerun_is_unchanged(): void` | Second service save is Unchanged with same ID/revision/count. |

T1 enum/union gates extend that file with `test_invalid_kind_and_initiator_fail_check(): void`, `test_actor_branch_nullability_is_enforced(): void`, and `test_system_document_does_not_require_user(): void`. Run existing `EnumCheckParityTest::enum_backed_tenant_columns_match_their_check_constraints_or_the_baseline(): void` with:

```sh
php artisan test -c phpunit-pgsql.xml --filter=EnumCheckParityTest
```

Combined T1/T2 gate: treasury-reviewer + tenancy-authz-reviewer inspect business rerun, cardinality, ownership and absence of reason-code/session/alignment additions. Rollback: pre-use only, drop owned triggers/functions, children then owned parent constraints; after recorded money, retain schema/evidence and roll forward.

### Exact file resolution and typed failure contracts

Every abbreviated `Application/DTOs/X.php`, `Application/Services/X.php`, `Domain/Enums/X.php`, `Domain/Exceptions/X.php` or `Presentation/...` path in T1–T5 means `apps/api/app/Modules/Treasury/` plus that path. Every named Treasury DTO constructor means a NEW file there unless explicitly existing.

| Owner | Exact NEW production file | Full public constructor/signature and mapping |
|---|---|---|
| T2 | `Domain/Exceptions/CashCustodyRevisionConflictException.php` | FQCN `App\Modules\Treasury\Domain\Exceptions\CashCustodyRevisionConflictException`; extends `\DomainException`; `__construct(public readonly int $currentRevision)`; code `cash_custody_revision_conflict`, 409. |
| T2 | `Domain/Exceptions/CashCustodyMetadataConflictException.php` | FQCN `App\Modules\Treasury\Domain\Exceptions\CashCustodyMetadataConflictException`; extends `\DomainException`; `__construct(public readonly string $repositoryId)`; code `cash_custody_metadata_conflict`, 422; ID excluded from response. |
| T3 | `Domain/Exceptions/RepositoryTransferConflictException.php` | FQCN `App\Modules\Treasury\Domain\Exceptions\RepositoryTransferConflictException`; extends `\DomainException`; `__construct(public readonly string $documentId)`; 409. |
| T3 | `Domain/Exceptions/RepositoryTransferWritesDisabledException.php` | FQCN `App\Modules\Treasury\Domain\Exceptions\RepositoryTransferWritesDisabledException`; extends `\DomainException`; `__construct()`; 503. |
| T3 | `Domain/Exceptions/LegacyTransferDocumentUnavailableException.php` | FQCN `App\Modules\Treasury\Domain\Exceptions\LegacyTransferDocumentUnavailableException`; extends `\DomainException`; `__construct()`; 409 with null document ID after authorization. |
| T3 | `Domain/Exceptions/RepositoryTransferPrecisionCeilingException.php` | FQCN `App\Modules\Treasury\Domain\Exceptions\RepositoryTransferPrecisionCeilingException`; `final`, extends `\DomainException`; constant `CODE='TRANSFER_PRECISION_EXCEEDS_LEDGER_SCALE'`; `__construct(public readonly string $amount, public readonly int $effectiveScale)`. |
| T4 | `Domain/Exceptions/RepositoryTransferCorrectionRequiredException.php` | FQCN `App\Modules\Treasury\Domain\Exceptions\RepositoryTransferCorrectionRequiredException`; extends `\DomainException`; `__construct(public readonly string $originalDocumentId)`; 422; ID excluded. |
| T4 | `Application/DTOs/RepositoryTransferFrozenWarningData.php` | FQCN `App\Modules\Treasury\Application\DTOs\RepositoryTransferFrozenWarningData`; extends Data; `__construct(string $tenant_id, string $company_id, string $document_id, string $transfer_group_id, string $movement_id, string $repository_id)`. |
| T4 | `Application/Services/RepositoryTransferFrozenWarningSink.php` | FQCN `App\Modules\Treasury\Application\Services\RepositoryTransferFrozenWarningSink`; `__construct(private readonly \Psr\Log\LoggerInterface $logger)`; `warn(RepositoryTransferFrozenWarningData $warning): void`; logs `WCASH-1-FROZEN-TRANSFER:`. |
| T5 | `Presentation/Services/RepositoryTransferErrorRenderer.php` | FQCN `App\Modules\Treasury\Presentation\Services\RepositoryTransferErrorRenderer`; `render(CashCustodyRevisionConflictException\|CashCustodyMetadataConflictException\|RepositoryTransferConflictException\|RepositoryTransferWritesDisabledException\|LegacyTransferDocumentUnavailableException\|RepositoryTransferCorrectionRequiredException\|RepositoryFrozenException\|RepositoryCheckpointException\|InsufficientRepositoryBalanceException\|CurrencyMismatchException\|RepositoryTransferPrecisionCeilingException $error): JsonResponse`; no constructor dependencies. |

Bind/inject `RepositoryTransferErrorRenderer` into `RepositoryTransferController` and `PaymentRepositoryController`. Catch only listed typed failures. Precision renders exactly:

```php
[
    'message' => __('treasury.transfer.precision_exceeds_ledger_scale'),
    'error' => [
        'code' => RepositoryTransferPrecisionCeilingException::CODE,
        'amount' => $error->amount,
        'effective_scale' => $error->effectiveScale,
    ],
]
```

Backend translation key `transfer.precision_exceeds_ledger_scale` lives in `apps/api/lang/{en,fr,ar}/treasury.php`; frontend key `transfer.precisionExceedsLedgerScale` in `apps/web/src/locales/{en,fr,ar}/treasury.json`. It is excluded from generic `messages.treasury.*`.

NEW `apps/api/tests/Feature/Treasury/RepositoryTransferErrorContractTest.php`, FQCN `Tests\Feature\Treasury\RepositoryTransferErrorContractTest`:

- `test_typed_failure_status_and_envelope(): void`.
- `test_precision_ceiling_renders_422_with_code_amount_and_effective_scale(): void`.
- `test_scoped_denial_precedes_readable_conflict(): void`.

The precision method first asserts `$this->assertSame(422, $response->getStatusCode())`, then exact full JSON with only code/amount/effective_scale. Command/lane:

```sh
php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferErrorContractTest
```

Lane: **backend-test-pgsql / WCASH-1 isolated PG**.

## T2 — Location settings, activation validator and census

Verified anchors: repository reads/writes `PaymentRepositoryController.php:33,153`; Console imports `TreasuryServiceProvider.php:37`; command registration `:236`.

NEW `App\Modules\Treasury\Application\Services\CashCustodyConfigurationService` signatures:

- `save(string $tenantId, string $companyId, string $locationId, ?string $safeRepositoryId, ?string $bankRepositoryId, bool $enabled, int $expectedRevision, string $actorId): CashCustodySaveResult`.
- `current(string $tenantId, string $companyId, string $locationId): ?CashCustodyConfigurationData`.
- `assertReadyForActivation(string $tenantId, string $companyId): void`.
- `census(string $tenantId, string $companyId): CashCustodyCensusData`.

NEW `CashCustodyCensusData::__construct(string $tenant_id, string $company_id, CashCustodyCensusStatus $status, array $unconfigured_location_ids, array $unlocated_drawer_ids, array $missing_bank_location_ids)`; arrays are `list<string>`.

Activation means enabling location custody configuration, not module licensing or a consumer. Enabled save validates selected location. Company readiness refuses when any active-drawer location lacks enabled valid safe configuration or an active drawer lacks a location. Missing bank is visible but does not block safe-only readiness.

NEW `App\Modules\Treasury\Application\Services\RepositoryMetadataService::update(string $tenantId, string $companyId, string $repositoryId, RepositoryMetadataUpdateData $changes, string $actorId): PaymentRepository`.

NEW `RepositoryMetadataUpdateData::__construct(string|Optional $code, string|Optional $name, RepositoryType|Optional $type, bool|Optional $allow_negative, string|null|Optional $bank_id, string|null|Optional $bank_name, string|null|Optional $account_number, string|null|Optional $iban, string|null|Optional $bic, string|null|Optional $location_id, string|null|Optional $responsible_user_id, string|null|Optional $account_id, string|null|Optional $gl_account_id, bool|Optional $is_active)`. Omitted fields use `Optional::create()`. No balance, ordinal, tenant or company is writable.

Replace both controller update branches at `PaymentRepositoryController.php:216,264` with this writer before controller repository locking. Preserve account/GL defaulting, no-JE transfer reassignment refusal at `:235`, type-derived negative-balance behavior and duplicate-drawer translation.

Lock order: transaction → existing company-GL advisory lock (`TreasuryMovementService.php:250`) → affected locations sorted → current configurations sorted → repositories sorted. NEW `App\Modules\Treasury\Application\Services\CashCustodyLock::acquireCompany(string $companyId): void`, requiring an outer transaction and using the existing company lock key. Both writers inject it.

Identical normalized save returns Unchanged even with stale expected revision. Different stale content conflicts. Real edit appends revision. Enabled safe cannot be cleared without disabling. Metadata changes that invalidate latest enabled defaults are refused. Harmless name/code changes do not rewrite evidence. Repository code validation includes company ID.

NEW `App\Modules\Treasury\Presentation\Console\CashCustodyCensusCommand`, extends `TenantScopedCommand`; constructor `__construct(CompanyContext $companyContext, CashCustodyConfigurationService $custodyService)`; `protected function executeCommand(): int`. Register beside `CensusRepositoriesCommand` in `TreasuryServiceProvider.php:236-237`.

Signature:

```text
treasury:census-cash-custody {--tenant=} {--company=} {--require-ready} {--json}
```

No options = all tenants/companies; tenant only = all companies in tenant; tenant+company = exact owned company. Company-only, malformed/unknown/foreign = exit 2. Manage tenancy internally; never use `tenants:run`.

JSON:

```text
{marker:"WCASH-1-CUSTODY:",schema_version:1,complete:boolean,status:"READY"|"NOT_READY"|"ERROR",expected_companies:int,visited_companies:int,companies:[...],errors:[...]}
```

NEW:

- `CashCustodyFleetCensusData::__construct(string $marker, int $schema_version, bool $complete, CashCustodyCensusStatus $status, int $expected_companies, int $visited_companies, array $companies, array $errors)`.
- `CashCustodyCensusErrorData::__construct(?string $tenant_id, ?string $company_id, string $message)`.
- `CashCustodyCensusStatus` cases Ready=`READY`, NotReady=`NOT_READY`, Error=`ERROR`.

Sort all IDs/findings; no timestamp. Missing table in Push 1 means NOT_READY without querying missing columns. Wrong shape = ERROR. Without `--require-ready`, completed NOT_READY exits 0; with it, NOT_READY exits 1; ERROR/incomplete exits 2.

NEW `CashCustodyConfigurationTest`, command/lane:

```sh
php artisan test -c phpunit-pgsql.xml --filter=CashCustodyConfigurationTest
```

| Test | First assertion |
|---|---|
| `test_activation_refuses_second_location_without_safe(): void` | `ValidationException`; readiness also refuses. |
| `test_identical_save_returns_unchanged(): void` | Outcome Unchanged; same ID/revision/count. |
| `test_second_company_defaults_are_independent(): void` | B safe selected; A absent from B census. |
| `test_second_location_save_uses_selected_safe(): void` | Second location’s safe equals selected safe; first unchanged. |
| `test_repository_mutation_cannot_invalidate_enabled_defaults(): void` | `CashCustodyMetadataConflictException`; snapshot unchanged. |
| `test_harmless_metadata_change_preserves_configuration(): void` | Renamed repository; same configuration/evidence. |
| `test_stale_changed_configuration_conflicts(): void` | Revision conflict/current revision; count unchanged. |

NEW `CashCustodyCensusCommandTest`, command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=CashCustodyCensusCommandTest
```

Methods cover ready refusal, exact option matrix, deterministic output, invalid ownership exit 2, incomplete fleet exit 2, pre-schema behavior and context restoration.

NEW `CashCustodyMetadataConcurrencyTest::test_save_and_metadata_update_share_lock_order(): void`, command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=CashCustodyMetadataConcurrencyTest
```

Two committed connections/barrier must finish without deadlock, with exactly one valid outcome and no invalid enabled default.

Convention-09 at T2: `test_second_company_defaults_are_independent`, `test_second_location_save_uses_selected_safe`, `test_identical_save_returns_unchanged`. Gate: treasury-reviewer + tenancy-authz-reviewer. Rollback: append a disabled/correcting revision; retain history; no balance undo.

## T3 — One atomic, idempotent transfer document

Extend `RepositoryTransferService.php:33`; preserve `TreasuryMovementService.php:225` and `GeneralLedgerService.php:1497`.

NEW `App\Modules\Treasury\Application\DTOs\RepositoryTransferIntent`:

```php
__construct(
    string $tenantId,
    string $companyId,
    string $operationUuid,
    string $fromRepositoryId,
    string $toRepositoryId,
    string $amount,
    string $currency,
    ?string $sourceLocationId,
    ?string $configurationId,
    ?int $configurationRevision,
    ?string $notes,
    ?CarbonImmutable $occurredAt,
    HumanTransferAuthorityData|SystemTransferAuthorityData $authority,
    bool $allowWhileFrozen = false,
)
```

Add `executeTransfer(RepositoryTransferIntent $intent): DocumentedRepositoryTransferResult` while preserving the existing scalar method until atomic T5 activation.

Push 3 retains the scalar method body, controller request and five-field result unchanged. Typed core is disconnected, default-false and has no production caller. Push 5 pauses/drains old processes, verifies zero documents, atomically installs the controller/adapter, removes scalar body, renames typed method to `transfer`, switches result type, removes temporary result, updates all callers/tests and shrinks the DPA baseline. After first document, rollback never restores documentless writing.

Typed core result constructor:

```php
__construct(
    string $transferGroupId,
    ?string $journalEntryId,
    MovementResult $out,
    MovementResult $in,
    bool $idempotentReplay,
    RepositoryTransferDocumentData $document,
    RepositoryTransferOutcome $outcome,
)
```

Identity is tenant/company/operation plus first-submission actor branch. Physical unique is `(company_id,operation_uuid)`, not actor-inclusive. Semantic tuple includes actor identity/provenance, repositories, normalized amount, currency and canonical notes. Different actor conflicts rather than creating another transfer.

NEW `App\Modules\Treasury\Application\Services\RepositoryTransferNotes::canonicalize(?string $notes): ?string`: validate UTF-8; trim Unicode White_Space; NFC normalize; collapse whitespace runs to U+0020; preserve case/punctuation/zero-width characters; empty becomes null. Compare ordered JSON-encoded normalized tuple without floats.

NEW `App\Modules\Treasury\Application\Services\RepositoryTransferOperationLock`:

- `acquire(string $tenantId, string $companyId, string $operationUuid): void`.
- `acquireOriginal(string $tenantId, string $companyId, string $originalDocumentId): void`.

Requires outer transaction and distinct advisory-key namespaces. NEW `GeneralLedgerService::lockRepositoryTransferContext(string $tenantId, string $companyId): void` uses existing tenant-numbering helper (`GeneralLedgerService.php:5671`) then company advisory lock.

Replay order: authenticated tenant/company/module/action → outer transaction/operation lock → tenant-numbering/company lock → existing document lookup → historical/current source authorization → semantic comparison. Inaccessible stored source returns 404 without document ID. Accessible changed actor/content returns 409 with readable document ID. Exact authorized retry returns original document/legs/JE, AlreadyRecorded and no writes.

NEW `App\Modules\Treasury\Presentation\Services\RepositoryTransferHttpAdapter`:

```php
__construct(
    private readonly LocationScopeResolver $locationScopeResolver,
    private readonly CompanyContext $companyContext,
    private readonly RepositoryTransferOperationLock $operationLock,
    private readonly GeneralLedgerService $generalLedger,
    private readonly RepositoryTransferService $transferService,
)
```

Public signatures:

- `transfer(TransferRepositoryRequest $request): RepositoryTransferResult`.
- `reverse(ReverseRepositoryTransferRequest $request, string $documentId): RepositoryTransferResult`.
- `show(Request $request, string $documentId): RepositoryTransferDocumentData`.

HTTP resolver never enters application service. Service independently reacquires locks and validates explicit authority.

### T3 precision ceiling and architecture contract

NEW `App\Modules\Treasury\Domain\Policies\RepositoryTransferPrecisionPolicy`:

```php
final class RepositoryTransferPrecisionPolicy
{
    public const OPERATIONAL_SCALE_CEILING = 3;

    public static function effectiveScale(int $countryScale): int;

    public static function assertWithinCeiling(string $amount, int $countryScale): void;
}
```

NEW `App\Modules\Treasury\Domain\Exceptions\RepositoryTransferPrecisionCeilingException`:

```php
final class RepositoryTransferPrecisionCeilingException extends \DomainException
{
    public const CODE = 'TRANSFER_PRECISION_EXCEEDS_LEDGER_SCALE';

    public function __construct(
        public readonly string $amount,
        public readonly int $effectiveScale,
    );
}
```

No existing Company-bound scale-factory contract exists under `apps/api/app/Shared/Contracts` at HEAD. Declare NEW `apps/api/app/Shared/Contracts/Company/CompanyCurrencyScaleResolverFactoryInterface.php`, FQCN `App\Shared\Contracts\Company\CompanyCurrencyScaleResolverFactoryInterface`:

```php
interface CompanyCurrencyScaleResolverFactoryInterface
{
    public function forCompany(
        string $tenantId,
        string $companyId,
    ): CurrencyScaleResolverInterface;
}
```

Declare NEW implementation `apps/api/app/Modules/Company/Infrastructure/Services/CompanyCurrencyScaleResolverFactory.php`, FQCN `App\Modules\Company\Infrastructure\Services\CompanyCurrencyScaleResolverFactory`, implements that interface:

```php
final class CompanyCurrencyScaleResolverFactory implements CompanyCurrencyScaleResolverFactoryInterface
{
    /**
     * @param \Closure(string): ?\App\Models\Country $countryFinder
     */
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly \Closure $countryFinder,
    );

    public function forCompany(
        string $tenantId,
        string $companyId,
    ): CurrencyScaleResolverInterface;
}
```

Implementation queries `App\Modules\Company\Domain\Company` by both `tenant_id` and primary key. If absent, it throws NEW `App\Shared\Exceptions\CompanyScopedCurrencyScaleCompanyNotFoundException::__construct(public readonly string $tenantId, public readonly string $companyId)`, extending `\RuntimeException`. If present, it returns existing `App\Shared\Infrastructure\CurrencyScaleResolver` with the same `CompanyContext`, same country-finder closure and the resolved Company as `$companyOverride`. Existing constructor/override behavior is at `CurrencyScaleResolver.php:30-43`.

Bind beside the existing resolver at `apps/api/app/Providers/AppServiceProvider.php:101-107`:

```php
$this->app->singleton(
    CompanyCurrencyScaleResolverFactoryInterface::class,
    function ($app): CompanyCurrencyScaleResolverFactoryInterface {
        return new CompanyCurrencyScaleResolverFactory(
            $app->make(CompanyContext::class),
            fn (string $countryCode): ?Country => Country::find($countryCode),
        );
    },
);
```

Add imports for the Shared contract and Company Infrastructure implementation. Treasury Application constructor-injects only `CompanyCurrencyScaleResolverFactoryInterface`; it does not import Shared Infrastructure or Company Domain/Infrastructure.

`executeTransfer()` first resolves:

```php
$countryScale = $this->companyCurrencyScaleResolverFactory
    ->forCompany($intent->tenantId, $intent->companyId)
    ->getScale();
```

It passes no currency argument, so country preset applies. Precision validation is the first **policy operation after resolving the country scale**:

```php
RepositoryTransferPrecisionPolicy::assertWithinCeiling(
    $intent->amount,
    $countryScale,
);
```

It runs before operation locks, replay lookup or writes for both human and system authority branches.

NEW architecture test `apps/api/tests/Architecture/CompanyCurrencyScaleResolverBoundaryTest.php`, FQCN `Tests\Architecture\CompanyCurrencyScaleResolverBoundaryTest`, method `treasury_application_uses_only_the_shared_company_scale_factory_contract(): void`. Its first assertion reflects the `RepositoryTransferService` constructor and asserts the factory parameter type equals `App\Shared\Contracts\Company\CompanyCurrencyScaleResolverFactoryInterface`; it then asserts Treasury Application has no import/reference to `App\Shared\Infrastructure\CompanyScopedCurrencyScaleResolverFactory`, `App\Shared\Infrastructure\CurrencyScaleResolver`, `App\Modules\Company\Domain\Company`, or Company Infrastructure.

Exact test command/lane:

```sh
php artisan test --filter=CompanyCurrencyScaleResolverBoundaryTest
```

Lane: **backend architecture / WCASH-1 boundary**.

Existing Deptrac rules are `ModuleApplication` may depend on `SharedContracts` but not `SharedInfrastructure` (`apps/api/deptrac.yaml:96-101`) and `SharedInfrastructure` may not depend on `ModuleDomain` (`:74-87`). Exact ratchet command/lane:

```sh
php tools/deptrac-ratchet.php --config=deptrac.yaml --baseline=deptrac.baseline.json
```

Lane: **backend-architecture / Deptrac ratchet**. First red output for the rejected design must report a ratchet regression for `ModuleApplication → SharedInfrastructure` and/or `SharedInfrastructure → ModuleDomain`; no category or total baseline increase is accepted (`tools/deptrac-ratchet.php:178-183,208-214`).

Red service tests in `RepositoryTransferDocumentTest`:

- `test_scale_four_company_human_transfer_of_10005_is_refused_with_unchanged_snapshots(): void`.
- `test_scale_four_company_system_transfer_of_10005_is_refused_with_unchanged_snapshots(): void`.

Use captured exception; first assert instance, then `amount==='1.0005'`, `effectiveScale===3`, zero new documents/movements/JEs and unchanged balances/ordinals. Command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferDocumentTest
```

**Removal condition (verbatim):** removing `OPERATIONAL_SCALE_CEILING` requires a separately accepted follow-up lane that widens `repository_movements.amount` and `repository_movements.balance_after` to `decimal(15,4)`, updates both `RepositoryMovement` casts, the write/replay precision guards in `TreasuryMovementService`, and their architecture/regression tests; the P0 census opens that ticket.

New operation gets a server group UUID independent of client operation UUID. Same linked GL account: two opposite legs, no JE. Different linked GL accounts: one posted balanced JE, Dr destination/Cr source. Both unlinked remain no-GL custody; one-null/one-linked is not same GL.

NEW flag in `apps/api/config/treasury.php`: `repository_transfer_documents_enabled`, environment `TREASURY_REPOSITORY_TRANSFER_DOCUMENTS_ENABLED`, default false. Typed transfer/reversal refuse when false. No fallback to old writer. Dependencies and eligibility: see dispatch order.

NEW `RepositoryTransferDocumentTest`, lane **backend-test-pgsql / WCASH-1 isolated PG**, exact command above.

| Exact test | First assertion |
|---|---|
| `test_same_gl_records_document_without_journal(): void` | Document count 1; JE null; exactly two legs. |
| `test_cross_gl_records_one_balanced_journal(): void` | One posted JE; debit=credit=amount. |
| `test_retry_returns_original_document_without_writes(): void` | Outcome AlreadyRecorded; IDs/snapshots unchanged. |
| `test_changed_payload_conflicts(): void` | Conflict carries original document ID; no money change. |
| `test_different_actor_conflicts(): void` | Different authorized actor conflicts; creator unchanged. |
| `test_configuration_change_does_not_change_retry_identity(): void` | Retry returns original evidence revision. |
| `test_failure_after_legs_rolls_everything_back(): void` | Complete snapshot equality. |
| `test_second_company_can_reuse_operation_uuid(): void` | B owns independent document/group; A unchanged. |
| `test_second_location_transfer_uses_selected_source(): void` | Document/out leg use selected location. |
| `test_disabled_flag_never_calls_documentless_writer(): void` | Disabled exception; no changes. |
| `test_system_first_submission_without_user(): void` | System document has null creator and one pair. |
| `test_fiscal_system_terminal_provenance_is_validated(): void` | Foreign terminal/company/location refuses; no writes. |
| `test_system_retry_is_already_recorded(): void` | Same system tuple returns same document. |
| `test_system_authority_or_provenance_change_conflicts(): void` | Changed provenance conflicts; snapshot unchanged. |
| `test_human_system_identity_swap_conflicts(): void` | Branch swap conflicts. |
| `test_notes_canonical_equivalences_replay(): void` | Equivalent notes return AlreadyRecorded. |
| `test_meaningful_notes_differences_conflict(): void` | Meaningful differences conflict. |
| `test_1005_matches_raw_repository_movement_and_gl(): void` | Raw `1.005` equality everywhere; fourth decimal refused. |

NEW `RepositoryTransferCompatibilityTest`:

- `test_push_three_scalar_endpoint_preserves_current_behavior(): void`.
- `test_push_three_typed_core_is_disconnected_and_disabled(): void`.
- T5 replacements: `test_activation_removes_scalar_fallback(): void`, `test_flag_off_after_first_document_is_503_without_mutation(): void`.

Document-per-action: T3 prepares; T5 removes only the Treasury draft-delete baseline entry from `document-per-action-baseline.json:34`. Exact commands:

```sh
php artisan test --filter=DocumentPerActionBaselineRatchetTest
php artisan test --filter=DocumentPerActionBaselineRatchetTest::the_working_baseline_never_grows_against_the_owner_pinned_blob
```

Supply `DPA_BASELINE_PROTECTED_BLOB` from existing CI variable at `.github/workflows/ci.yml:318`; unset is incomplete. Gate: treasury-reviewer + tenancy-authz-reviewer. Rollback: block submissions and correct forward; never delete documents/JEs.

## T4 — Linked reversals and explicit frozen policy

Production anchors: wrapper freeze check `RepositoryTransferService.php:46`; single-leg explicit flag at `TreasuryMovementService.php:91`; paired port hardcodes false at `:371,391`; event builder `:639`; existing `TransferIntent.php:26`.

Extend `TransferIntent` preserving parameter order:

```php
__construct(
    string $fromRepositoryId,
    string $toRepositoryId,
    string $tenantId,
    string $companyId,
    string $amount,
    string $currency,
    string $transferGroupId,
    ?string $journalEntryId,
    ?CarbonImmutable $occurredAt,
    ?string $createdBy,
    ?string $notes,
    bool $allowWhileFrozen = false,
    ?string $reversesOutMovementId = null,
    ?string $reversesInMovementId = null,
)
```

Extend private builder:

```php
buildTransferLegEvent(
    string $movementId,
    string $repositoryId,
    TransferIntent $intent,
    MovementDirection $direction,
    string $balanceAfter,
    int $ordinal,
    ?string $journalEntryId,
    CarbonInterface $occurredAt,
    bool $recordedWhileFrozen,
    ?string $reversesMovementId,
): RepositoryMovementRecorded
```

New out reverses original in; new in reverses original out. Ordinary transfers pass null.

Use existing v1 `App\Modules\Treasury\Domain\Events\RepositoryMovementRecorded` (`RepositoryMovementRecorded.php:23`); constructor already accepts reversal ID at `:43` and audit payload emits it at `:75`. Do not edit event class/name/payload. Compliance persists payload through `DomainEventSubscriber.php:1144`, registered at `:1273`.

Extend `RepositoryTransferReversalTest`:

- `test_reversal_events_reference_original_opposite_legs(): void`.
- `test_reversal_audit_rows_reference_original_opposite_legs(): void`.
- `test_reversal_retry_emits_no_duplicate_audit_rows(): void`.

The warning sink is called through `DB::afterCommit` only after a new document is committed for each frozen leg. Extend `RepositoryTransferFrozenPolicyTest`:

- `test_warning_sink_payload_is_exact_and_after_commit(): void`.
- `test_rollback_and_retry_do_not_warn(): void`.

NEW `App\Modules\Treasury\Application\DTOs\RepositoryTransferReversalIntent`:

```php
__construct(
    string $tenantId,
    string $companyId,
    string $originalDocumentId,
    string $operationUuid,
    ?string $sourceLocationId,
    string $explanation,
    CarbonImmutable $occurredAt,
    HumanTransferAuthorityData|SystemTransferAuthorityData $authority,
)
```

Add full public signature:

```php
RepositoryTransferService::reverse(
    RepositoryTransferReversalIntent $intent,
): DocumentedRepositoryTransferResult
```

At atomic activation, change return type to `RepositoryTransferResult` with transfer method/callers. Full reversal only; reject reversal-of-reversal, partial amount, duplicate reversal under another operation, foreign original and unauthorized current source. Lock original before operation. Authorize original destination as reversal source. Swap repositories, preserve amount/currency, create new group/document, link `reverses_document_id`, and link new legs to opposite original legs. Do not mutate original or old JE. Changed GL mapping throws correction-required.

Interactive HTTP always uses `allowWhileFrozen=false`. Internal explicit true records both legs, marks only frozen legs and emits after-commit warning per frozen leg. Retry emits no duplicate effect/alert. No negative-balance/checkpoint bypass. Eligibility: see dispatch order.

NEW `apps/api/tests/Feature/Treasury/RepositoryTransferReversalTest.php`, lane **backend-test-pgsql / WCASH-1 isolated PG**, command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferReversalTest
```

| Exact test | First failing assertion |
|---|---|
| `test_reverse_appends_linked_document_and_preserves_original(): void` | Reversal links original; original bytes/JE unchanged; opposite legs exact. |
| `test_repeat_reverse_returns_original_reversal(): void` | AlreadyRecorded; same ID; no extra rows. |
| `test_second_reversal_operation_is_refused(): void` | Conflict with existing reversal; snapshot unchanged. |
| `test_mapping_change_refuses_reversal(): void` | `RepositoryTransferCorrectionRequiredException`; no second document/JE. |
| `test_second_company_reversal_is_independent(): void` | B restores its balances; A unchanged. |
| `test_second_location_reversal_uses_current_outflow_custody(): void` | Allowed second branch succeeds; denial leaves snapshots unchanged. |
| `test_reversal_actor_and_explanation_conflict(): void` | Changed actor/explanation conflicts; no extra leg. |

NEW `apps/api/tests/Feature/Treasury/RepositoryTransferFrozenPolicyTest.php`, same lane, command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferFrozenPolicyTest
```

| Exact test | First failing assertion |
|---|---|
| `test_explicit_frozen_destination_records_and_alerts(): void` | Destination leg marked frozen and one warning tied to document/group/repository. |
| `test_interactive_frozen_destination_has_no_effect(): void` | `RepositoryFrozenException`; full snapshot unchanged. |
| `test_retry_after_freeze_returns_original(): void` | AlreadyRecorded with same IDs and no new warning. |
| `test_warning_sink_payload_is_exact_and_after_commit(): void` | Exact marker/keys; zero before commit. |
| `test_rollback_and_retry_do_not_warn(): void` | Zero on rollback and no increment on replay. |

Convention-09: `test_second_company_reversal_is_independent`, `test_second_location_reversal_uses_current_outflow_custody`, `test_repeat_reverse_returns_original_reversal`. Gate: treasury-reviewer + tenancy-authz-reviewer. Rollback: disable reversal submissions; preserve all evidence/provenance/money rows.

## T5 — Existing HTTP and web surfaces, generated types

Verified files: Treasury routes `:35,102`; transfer controller `:21`; request `:21`; repository controller `:420`; `useTransferCash.ts:5`; `usePaymentRepositories.ts:7`; `RepositoryListPage.tsx:23`; `RepositoryDetailPage.tsx:27`; `PaymentForm.tsx:60`; `TransferCashModal.tsx:28,84`; web wrapper `routes/index.tsx:1937`.

**Hard prerequisite W1-T-CUSTODY-AUTHZ:** accepted source-custody/permission implementation for `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:139,147`, with implementation SHA, treasury-reviewer + tenancy-authz-reviewer acceptance, `treasury.manage_all_locations` behavior and tenant cache/grant evidence. T5 eligibility: see dispatch order.

All three requests implement `withValidator(Validator $validator): void` using `after`, comparing raw top-level keys to explicit allowlists and adding `__('messages.validation.unexpected_field')`. Add translations in `apps/api/lang/{en,fr,ar}/messages.php`.

Transfer allowlist: operation UUID/alias, from/to, amount, currency, notes. Reverse: operation UUID, explanation. Custody: location, safe, bank, enabled, expected revision. Arrays/objects fail scalar rules. Query keys are also checked.

Keep POST `/api/v1/payment-repositories/transfers`; controller calls HTTP adapter. Complete rules:

- `operation_uuid`: required_without alias, UUID.
- `transfer_group_id`: nullable UUID, deprecated alias.
- from/to required UUID; to differs.
- amount required string numeric gt:0, regex `/^\d+(\.\d{1,4})?$/`, max 16; service checks decimal(15,4) overflow and effective precision.
- currency uppercase 3.
- notes nullable string max 1000.
- Both UUID names must match.
- Authority/freeze/company/tenant/source-location/configuration/occurred/kind fields rejected.

NEW `ReverseRepositoryTransferRequest`: authorize treasury.transfer; operation UUID required; explanation required normalized nonblank max 1000; everything else rejected.

NEW `SaveCashCustodyRequest`: authorize repositories.manage; location UUID; safe present nullable UUID; bank present nullable/different UUID; enabled boolean; expected revision integer min 0; enabled requires safe.

Controller signatures:

- `RepositoryTransferController::show(Request $request, string $documentId): JsonResponse`.
- `RepositoryTransferController::reverse(ReverseRepositoryTransferRequest $request, string $documentId): JsonResponse`.
- `PaymentRepositoryController::saveCashCustody(SaveCashCustodyRequest $request, string $id): JsonResponse`.

Routes: GET transfer document, POST reverse, PUT repository custody; fixed paths precede generic `{id}`. Preserve auth/team/tenant middleware and add case-sensitive `module:Treasury`.

Index modes: omitted retains compatibility; `transfer_source` returns authorized active physical sources; `transfer_destination&from_repository_id=` returns allowed branch destinations or configured central safe/bank after source scoping. Return only NEW `RepositoryDestinationData::__construct(string $id, string $name, RepositoryType $type, string $currency)`.

Document read requires `repositories.view` plus authority over historical/current source, or company-wide authority. Destination-only access does not grant evidence. Unreadable links are omitted; direct access returns 404. Destination balance is nullable in success response when not readable.

Statuses/envelopes:

- New transfer/reversal 201.
- Exact replay 200.
- Document GET 200.
- Save/unchanged 200.
- Transfer conflict 409 with readable document ID.
- Revision conflict 409 with current revision.
- Precision 422 exact translated envelope.
- Accessible legacy no-document 409 with null ID.
- Inaccessible source 404.
- Validation 422.
- Module/action denied 403; unauthenticated 401.
- Frozen/inactive/type/currency/checkpoint/balance 422.
- Flag false 503.
- No private IDs in unauthenticated/forbidden/scoped-miss.

NEW transport constructors:

- `RepositoryTransferRequestData::__construct(string $operation_uuid, string $from_repository_id, string $to_repository_id, string $amount, string $currency, ?string $notes)`.
- `RepositoryTransferResponseData::__construct(RepositoryTransferDocumentData $document, RepositoryTransferOutcome $outcome, string $transfer_group_id, ?string $journal_entry_id, bool $idempotent_replay, RepositoryTransferLegData $out, RepositoryTransferLegData $in)`.
- `RepositoryTransferLegData::__construct(string $movement_id, ?string $balance_after, string $repository_id)`.

NEW `PaymentRepositoryData` constructor:

```php
__construct(
    string $id,
    string $code,
    string $name,
    RepositoryType $type,
    bool $allow_negative,
    ?string $bank_id,
    ?string $bank_name,
    ?string $account_number,
    ?string $iban,
    ?string $bic,
    string $balance,
    string $currency,
    bool $is_active,
    ?string $gl_account_id,
    ?RepositoryGlAccountData $gl_account,
    ?string $location_id,
    ?string $location_name,
    RepositoryBankValidationData $bank_account_validation,
    ?CashCustodyConfigurationData $cash_custody,
)
```

Nested:

- `RepositoryGlAccountData::__construct(string $id, string $code, string $name)`.
- `RepositoryBankValidationData::__construct(?RibValidationResult $rib, ?IbanValidationResult $iban, ?bool $bic_valid)`.

Durable history exact files:

- `RepositoryMovementController.php:36,101`.
- NEW `RepositoryMovementData.php`.
- `useRepositoryMovements.ts:38`.
- `RepositoryMovementsTab.tsx:58`.
- `RepositoryDetailPage.tsx:229`.

`RepositoryMovementData` preserves all current fields and adds nullable transfer document ID/href. Resolve documents by tenant/company/group in batch. Both authorized leg histories discover same document. Href points to existing repository detail with query ID. Destination-only sees null and show 404. Legacy groups stay unlinked.

Generated-global wiring: web already references `packages/shared/types/generated.d.ts`; add identical relative triple-slash reference to `apps/pos/src/vite-env.d.ts`. Use fully qualified ambient `App.Modules.Treasury.Application.DTOs.PaymentRepositoryData` or Picks. No named import from generated file.

Update:

- `StatementUploadWizard.tsx:26`.
- `StatementListPage.tsx:56`.
- `RepositoryListPage.tsx:23`.
- `RepositoryDetailPage.tsx:27`.
- `PaymentForm.tsx:60`.
- `SplitPaymentForm.tsx:27`.
- `usePaymentRepositories.ts:7`.
- `useRemittances.ts:9`.
- `RecordPaymentModal.tsx:28`.
- `AddRepositoryModal.tsx:41`.
- web POS repository API `:9`.
- `apps/pos/src/types/payment.ts:27`.
- `InstrumentDetailPage.tsx:134`.
- `AdvancedPaymentsModal.tsx:185`.
- POS payment API `:9`.
- POS sync service `:1256`.
- `TransferCashModal.tsx:15`.
- `useTransferCash.ts:5`.

Device storage alias becomes generated-derived `Omit<PaymentRepository,'is_active'|'type'> & {is_active:number;type:string}`; preserve row conversion/query behavior.

Add `data-feature="wcash-1-custody-transfer-v2"` to custody/document section. Preserve one operation UUID across retries, freeze intent after ambiguous failure, reset on tenant/company change, use already-unwrapped `apiPost`, retain money strings and query invalidation.

NEW `RepositoryTransferEndpointTest`, command/lane:

```sh
php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferEndpointTest
```

Lane: **backend-test-pgsql / WCASH-1 isolated PG**.

Required methods include scoped source 404, foreign UUID oracle denial, replay after config change, actor conflict, central reverse denial, module-off refusal, frozen override rejection, destination-field minimization, document-read scope, exact precision 422, status matrix, real second company, second location and exact retry.

Precision first assertion:

```php
$response->assertStatus(422)->assertExactJson([
    'message' => __('treasury.transfer.precision_exceeds_ledger_scale'),
    'error' => [
        'code' => RepositoryTransferPrecisionCeilingException::CODE,
        'amount' => '1.0005',
        'effective_scale' => 3,
    ],
]);
```

Then assert `error` has exactly those three keys and document/movement/repository/JE snapshots are unchanged.

Web Vitest command:

```sh
pnpm exec vitest run src/features/treasury/hooks/useTransferCash.test.ts src/features/treasury/RepositoryListPage.transferIntegration.test.tsx src/features/treasury/components/TransferCashModal.test.tsx src/features/treasury/RepositoryDetailPage.custody.test.tsx
```

Tests assert explicit replay outcome, same operation UUID, document link, no central balance, second-location defaults and company-switch reset.

Frontend-types lane:

```sh
php artisan typescript:transform
pnpm --filter @autoerp/web typecheck
pnpm --filter @autoerp/pos typecheck
```

NEW compile fixtures:

- `apps/web/src/features/treasury/__tests__/repositoryGeneratedContract.typecheck.ts`.
- `apps/pos/src/types/paymentRepositoryGeneratedContract.typecheck.ts`.

POS storage regression:

```sh
pnpm exec vitest run src/lib/db/repositories/paymentRepository.generatedContract.test.ts
```

Additional backend tests reject arbitrary transfer/reverse/custody keys and forged system fields with `errors.<key>.0 === __("messages.validation.unexpected_field")`, unchanged snapshots, and prove controller uses typed signature after scalar removal.

NEW `RepositoryTransferHistoryTest`, command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferHistoryTest
```

Methods cover both histories sharing document, destination-only omission/show404 and system document readability without a user.

NEW web history test command:

```sh
pnpm exec vitest run src/features/treasury/components/RepositoryMovementsTab.transferDocument.test.tsx src/features/treasury/RepositoryDetailPage.custody.test.tsx
```

Gate: treasury-reviewer + tenancy-authz-reviewer + frontend-conventions and React Doctor finishing workflow. Rollback: disable action UI/HTTP writes together; retain document reads/schema; never restore documentless writing.

## T6 — PostgreSQL proof and deployment handback

Verified anchors: PG lane `apps/api/phpunit-pgsql.xml:1`; registration fixture `TenantInitializationTest.php:158`; second-company path `CompanyPaymentRepositoryProvisioningTest.php:95`; unique ratchet `TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:212,254`.

NEW `CashCustodySecondOfEverythingTest`, command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=CashCustodySecondOfEverythingTest
```

| Test | First assertion |
|---|---|
| `test_registered_second_company_can_reuse_operation_uuid(): void` | B document belongs to B with distinct group and same UUID. |
| `test_selected_second_pos_location_keeps_its_own_custody(): void` | Real second POS location owns configured drawer/safe and attribution. |
| `test_rerun_configuration_and_transfer_are_explicit(): void` | Unchanged + AlreadyRecorded, same IDs/counts/balances. |

NEW `RepositoryTransferDocumentConcurrencyTest`, command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferDocumentConcurrencyTest
```

| Test | First assertion |
|---|---|
| `test_concurrent_same_operation_commits_one_document(): void` | Count 1, two legs, zero/one JE, Recorded/AlreadyRecorded. |
| `test_opposing_transfers_preserve_lock_order(): void` | Both complete before timeout; exact net balances/ordinals. |
| `test_concurrent_reverse_commits_once(): void` | One reversal; retry/rejection outcomes exact. |
| `test_company_uuid_reuse_does_not_collide(): void` | Independent company documents/groups; other balance unchanged. |

NEW `CashCustodyTopologyTest`, command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=CashCustodyTopologyTest
```

| Test | First assertion |
|---|---|
| `test_db_per_tenant_and_compatibility_scope_are_equivalent(): void` | Foreign lookup 404 in both; wrong inserts 23503; snapshots unchanged. |
| `test_migrations_are_repeatable_and_empty_down_up_is_safe(): void` | First apply succeeds; second Nothing-to-migrate; empty down/up restores definitions. |

Ratchet command:

```sh
php artisan test -c phpunit-pgsql.xml --filter=TenantOnlyUniqueOnCatalogueTablesRatchetTest
```

Classify `cash_custody_configurations` as catalogue and `repository_transfer_documents` as immutable evidence; no waiver/ceiling increase.

NEW Playwright test `apps/web/e2e/treasury-cash-custody-transfer.spec.ts`, test `second branch custody and transfer evidence`. First assertion after configure/transfer is visible document ID and selected second branch; retry retains ID, reversal links original, wrong branch denied, company switch clears state. Command/lane:

```sh
pnpm --dir apps/web exec playwright test e2e/treasury-cash-custody-transfer.spec.ts
```

Lane: **web-e2e / private WCASH-1 stack**, API `:8011`, Vite `:5174`, output outside Vite root.

Convention-09 methods are the three `CashCustodySecondOfEverythingTest` cases above. Concurrency uses committed processes/barriers. PG inability is incomplete, never replaced by SQLite.

Local preflight:

```sh
PREFLIGHT_TEST_PATHS='tests/Feature/Treasury/CashCustodySchemaTest.php tests/Feature/Treasury/CashCustodyConfigurationTest.php tests/Feature/Treasury/CashCustodyCensusCommandTest.php tests/Feature/Treasury/RepositoryTransferDocumentTest.php tests/Feature/Treasury/RepositoryTransferReversalTest.php tests/Feature/Treasury/RepositoryTransferFrozenPolicyTest.php tests/Feature/Treasury/RepositoryTransferEndpointTest.php tests/Feature/Treasury/CashCustodySecondOfEverythingTest.php tests/Integration/Treasury/RepositoryTransferDocumentConcurrencyTest.php tests/Integration/Treasury/CashCustodyTopologyTest.php' ./scripts/preflight.sh
```

SQLite regression:

```sh
php artisan test -c phpunit.xml --filter='RepositoryTransferServiceTest|RepositoryTransferEndpointTest'
```

Full VPS/CI:

```sh
pnpm build
pnpm lint
pnpm test
pnpm typecheck
pnpm --filter @autoerp/web test:e2e
cd apps/api
composer test
./vendor/bin/phpstan
./vendor/bin/pint --test
```

Attach actual errors/skips/screenshots; skipped PHPUnit is not green.

Gate: treasury-reviewer + tenancy-authz-reviewer approve PG evidence, second-company registration and denial snapshots. Rollback: verification/handback only; preserve evidence, revert no production state. Remove only disposable isolated test databases/fixtures through their test-harness teardown. NEW `docs/handoff/HANDBACK-WCASH-1-2026-09-06.md` records implementation SHA, red/green commands, screenshots, migration/census output and reviewer findings. No implementation or reviewer gate ran in this planning assignment.

## Deployment — canonical manifest variables

> Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
> (five-push sequence §2, web block §3, gate checklist §4). This slice supplies only the variables
> below; it does not restate deploy mechanics.

| Variable | WCASH-1 value |
|---|---|
| `<slice>` | `wcash-1`; precision prerequisite uses authoritative lane identifier `precision-4`. |
| **Migrations list** | P0-a none. P0-b only `2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php`, non-additive/self-guarding. WCASH additive: `2026_09_06_210000_create_cash_custody_configurations.php`, then `2026_09_06_210100_create_repository_transfer_documents.php`. |
| **Flags** | P0 none. WCASH NEW `treasury.repository_transfer_documents_enabled` / `TREASURY_REPOSITORY_TRANSFER_DOCUMENTS_ENABLED`, default false. Existing variance flag remains false. |
| **Commands** | P0-a NEW `treasury:census-money-precision {--tenant=} {--json}`, standalone, marker `MONEY-PRECISION CENSUS`. P0-b rolling/per-tenant migration or shared exact path. WCASH NEW `treasury:census-cash-custody {--tenant=} {--company=} {--require-ready} {--json}`, marker `WCASH-1-CUSTODY:`. |
| **Censuses** | At Push 1 and Push 4, capture `php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1' 2>&1 \| tee /tmp/wcash-1-day-one-p{1,4}.log` and `php artisan tenants:run pos:census-vat-legs 2>&1 \| tee /tmp/wcash-1-vatlegs-p{1,4}.log`. Build `/tmp/wcash-1-expected-tenants.txt` from the U-2 inventory as sorted unique canonical UUIDs before either run. For day-one, pass grep is `grep -E '^DAY-ONE CENSUS [0-9a-f-]{36} [0-9a-f-]{36}: CLEAN$'`; fail if `grep -Eq 'DRIFT\\(|NO-COMPANY|DAY-ONE CENSUS: .*valid UUID|DAY-ONE CENSUS: company .* does not exist'` matches, if any expected tenant lacks at least one CLEAN line, or if any observed tenant UUID is outside the expected set. Extract observed day-one tenant UUIDs with `sed -nE 's/^DAY-ONE CENSUS ([0-9a-f-]{36}) .*/\\1/p' \| sort -u` and require `diff -u /tmp/wcash-1-expected-tenants.txt /tmp/wcash-1-day-one-observed.txt` empty. For POS VAT, per-tenant pass marker is exact prefix `POS output-VAT leg census: none` (`PosReceiptVatLegCensusCommand.php:336`); fail grep is `POS output-VAT leg census: [1-9][0-9]* receipt` (`:342`), any `FAILED|ERROR|exception`, any missing expected tenant wrapper/verdict, or unexpected tenant. Extract Stancl `Tenant: <uuid>` wrappers into a sorted set and require exact diff against `/tmp/wcash-1-expected-tenants.txt`; additionally require exactly one clean POS marker between each expected tenant wrapper and the next wrapper. Never trust `tenants:run` exit status. P0-a separately requires one `MONEY-PRECISION CENSUS tenant=<uuid>` verdict for each exact expected UUID plus aggregate output; pass requires exit 0, complete=true and `fourth_decimal_present=0` per tenant; non-zero detector or missing/duplicate/unexpected UUID fails. WCASH direct census baseline permits NOT_READY but not ERROR/incomplete; Push 4 adds `--require-ready` and requires READY, marker, complete=true, and expected_companies=visited_companies. |
| **Web changes** | P0 none. WCASH yes; canonical §3 with `<slice>=wcash-1`, fingerprint `wcash-1-custody-transfer-v2`; record asset hash and grep ≥1. |
| **Device build** | No. POS edits are compile-time/generated-derived aliases only. |
| **Queues** | None. No listener/projector/Horizon change. |
| **Collapsed pushes** | P0-a/P0-b remain distinct; none of five WCASH pushes collapse. P1 census/DTOs; P2 additive schema; P3 disconnected default-false writer + dormant web; P4 configuration/census/evidence; P5 activation. Operative sequencing: see dispatch order. |
| **Env path** | P0 adds none. Candidate WCASH path is separate Dokploy Environment tabs; U-1 must verify. If compose, add declared env to `x-api-env` and apply manifest plumbing push. |
| **Host-side backup** | P0-a none. P0-b `/root/backup-precision-4-<database>-<UTC>.dump` per physical DB. WCASH `/root/backup-wcash-1-<database>-<UTC>.dump` before Push 2 and writes. Verify non-zero and restore-readable. |
| **Rollback point per push** | P0-a revert tooling/preserve evidence. P0-b transaction rollback before commit; after commit retain scale-four schema and scale-three casts. WCASH P1 revert tooling; P2 retain additive schema; P3 revert disconnected additions only while document count zero; P4 preserve revisions/append corrections; P5 flag false refuses writes while retaining reads/documents/legs/JE and never restoring legacy writer. |

Promotion requires U-1 topology/env proof, U-2 actual tenancy topology/migration proof and U-5 backup-target proof. P0 detector, migration and W1 acceptance are technical prerequisites. Operative sequencing: see dispatch order.

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

**Binding dispatch order:** dispatch P0-a, T1 and T2 now; dispatch P0-b after `fourth_decimal_present` ships in P0-a and every required per-tenant detector result is zero/accepted—the owner’s provisional A10 cast ruling is given; dispatch T3–T6 only after accepted P0-b. T1/T2 retain one combined acceptance and their deployment follows the accepted P0-b package. T5 also requires accepted W1-T-CUSTODY-AUTHZ implementation/reviewer SHAs and tenant permission/cache rollout. Use isolated worktrees and coordinate shared files; this planning assignment performs no Git write.

- [ ] HEAD `55bf3d14204363ab857738f785a3c405100da9db` and every existing seam reverified; NEW paths remain proposals.
- [ ] Ten benchmark rows, glossary additions, Q11–Q13 verbatim and explicit deferrals retained.
- [ ] Binding order above followed; all other sequencing text is historical/superseded or a cross-reference to this section.
- [ ] P0-a contains only authoritative `MoneyPrecision*` census code, five-case classification, marker and detector.
- [ ] Every expected tenant UUID has exactly one detector verdict; non-zero/missing/duplicate/unexpected tenant blocks.
- [ ] P0-b accepts only exact scale-three or compliant scale-four tuples; scale two refuses before DDL.
- [ ] P0-b uses the authoritative migration/test/ratchet names, raw `1.0005` proof, backups and physical postchecks.
- [ ] Four Eloquent casts remain `decimal:3`; standing detector remains green; full Amendment-B evidence is in docblock/precision contract.
- [ ] Company-bound scale factory crosses boundaries only through `CompanyCurrencyScaleResolverFactoryInterface`; Deptrac and exact architecture test pass.
- [ ] Precision validation is the first policy operation after resolving company country scale.
- [ ] `OPERATIONAL_SCALE_CEILING=3` remains until separately accepted movement-ledger widening.
- [ ] Complete schemas, typed evidence, enums, ownership and append-only guards proven on PostgreSQL.
- [ ] Every active-drawer location has valid enabled safe; missing bank remains visible; no guessed defaults.
- [ ] Real registration, second company, second location and explicit rerun outcomes proven.
- [ ] One document, one group, two cross-linked legs; same GL zero JE, cross GL one posted balanced JE.
- [ ] Conflicting/concurrent retries, partial failure, mapping changes, reversed custody and legacy aliases tested.
- [ ] Original preserved; one linked full reversal; original and compensating JE remain auditable.
- [ ] Explicit frozen internal transfer records/alerts; HTTP cannot request override; replay emits no duplicate alert.
- [ ] Branch-source 404 leaves snapshots unchanged; central destination exposes only permitted identifiers.
- [ ] Reused routes/actions are module-gated; HTTP alone resolves location authority.
- [ ] Generated DTOs replace repository/transfer shadows; UUID retry and company-switch behavior verified.
- [ ] Exact per-tenant day-one/POS-VAT marker greps and expected-UUID set comparisons pass.
- [ ] PG ratchets, concurrency tests, preflight, web E2E, campaign and census evidence accepted; manifest variables and U-1/U-2/U-5 evidence complete.
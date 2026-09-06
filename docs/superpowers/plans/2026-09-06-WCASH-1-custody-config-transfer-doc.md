<!-- W-CASH-1 rev 14 (rev 10 dispatch-ready base plus gate-r11 Amendment A, gate-r12 Amendment B, and gate-r13 D1–D5 corrections) (gpt-5.6-sol, read-only) on 2026-09-06, saved verbatim by the orchestrator (owner away). Rev 4 = 59e8c6c82. Status: awaiting gate r15. -->
# Slice plan W-CASH-1 — per-location cash custody configuration and repository transfer document (rev 15)

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

Verified production anchors: movement schema `apps/api/database/migrations/tenant/2026_07_08_100100_create_repository_movements_table.php:15`; immutable model `apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:73`; repository types `apps/api/app/Modules/Treasury/Domain/Enums/RepositoryType.php:7`; existing transfer DTO `apps/api/app/Modules/Treasury/Application/DTOs/TransferIntent.php:26`. Update `docs/glossary.md:60` with the two NEW rows.

NEW migration files, in order:

1. `apps/api/database/migrations/tenant/2026_09_06_210000_create_cash_custody_configurations.php`
2. `apps/api/database/migrations/tenant/2026_09_06_210100_create_repository_transfer_documents.php`

Every migration implements `up(): void` and `down(): void`; use existence guards plus fail-loud shape verification on rerun, not silently accepting a partially created schema. PostgreSQL is authoritative for the relational/trigger guarantees.

T1 migration guard liveness: NEW `apps/api/tests/Integration/Treasury/CashCustodyMigrationGuardTest.php`, methods `test_configuration_migration_refuses_wrong_shape_without_changes(): void` and `test_document_migration_refuses_wrong_shape_without_changes(): void`. PG lane command `php artisan test -c phpunit-pgsql.xml --filter=CashCustodyMigrationGuardTest`. Each provider constructs an independently malformed disposable pre-state: partial child table, wrong column/default/nullability, wrong owned parent composite, wrong FK columns/actions, missing or wrong CHECK/unique, missing or wrong immutable/deferred trigger/function. Invoke the pending migration's up() directly, bypassing the migrations ledger. First assertion is RuntimeException with exact message `WCASH-1-SCHEMA: migration=2026_09_06_210000_create_cash_custody_configurations reason=unexpected_shape` or `WCASH-1-SCHEMA: migration=2026_09_06_210100_create_repository_transfer_documents reason=unexpected_shape`; assert complete pg_catalog definitions, rows and migration ledger unchanged. Each migration preflights ALL existing objects before any DDL in one transaction. `test_direct_compliant_rerun_reports_already_compliant(): void` invokes each up twice and asserts its exact already_compliant marker and identical schema/data. This is distinct from Laravel skipping an applied migration. Expected function bodies and constraint definitions are compared semantically, not merely object names.

Complete proposed schema notation: unless specified, columns are NOT NULL, have no default, and FKs use ON DELETE RESTRICT / ON UPDATE RESTRICT. UUID primary keys are application-generated. No soft deletes or updated_at on either append-only table. Tenant IDs never reference the central tenants table across a database boundary. Instead, enforce local relational ownership: `(tenant_id,company_id)` references `companies(tenant_id,id)` and `(tenant_id,created_by)` references `users(tenant_id,id)`, both RESTRICT. Add owned parent uniques `(tenant_id,id)` to companies and users where no equivalent exists. The company and actor must agree with the initialized tenant in db-per-tenant mode and with explicit tenant intent in compatibility mode. For configuration revisions, created_by stays required. For transfer documents, the actor-union constraints below govern nullable created_by: membership/permission checks apply only to humans; no system user is manufactured. Tenant-global user identity alone grants no company rights.

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
| amount | decimal(15,4) | required | — |
| currency | char(3) | required | — |
| out_movement_id | uuid | required | repository_movements(tenant_id,company_id,id), composite |
| in_movement_id | uuid | required | repository_movements(tenant_id,company_id,id), composite |
| journal_entry_id | uuid | NULL | journal_entries(tenant_id,company_id,id), composite |
| reverses_document_id | uuid | NULL | repository_transfer_documents(tenant_id,company_id,id), composite |
| evidence | jsonb | required, no default | TransferDocumentEvidenceData |
| notes | varchar(1000) | NULL | — |
| occurred_at | timestamptz | required | — |
| initiator_kind | varchar(8) | required | RepositoryTransferInitiatorKind: human/system |
| created_by | uuid | NULL, human branch only | users(tenant_id,id), composite with tenant_id |
| system_authority | varchar(24) | NULL, system branch only | RepositoryTransferSystemAuthority enum |
| system_principal | varchar(255) | NULL, system branch only | stable named projection/command identity, not a display name |
| provenance_source_id | uuid | NULL, system branch only | stable persisted source/command-run UUID, verified by trusted caller |
| provenance_terminal_id | uuid | NULL; required for fiscal_projection | pos_terminals(tenant_id,company_id,id), composite |
| created_at | timestamptz | DEFAULT CURRENT_TIMESTAMP | — |

Checks: amount > 0; source != destination; out != in; uppercase three-letter currency; kind IN ('transfer','reversal'); transfer requires null reverses_document_id; reversal requires non-null, non-self reverses_document_id; evidence is a JSON object with schema_version 1. No stored status: successful insert means recorded; reversed display state derives from a linked reversal. Additional CHECKs: initiator_kind IN ('human','system'); system_authority IS NULL OR IN ('fiscal_projection','scheduled_command'); human requires created_by non-null and ALL system/provenance fields null; system requires created_by null and non-null/nonblank system_authority/system_principal/provenance_source_id. FiscalProjection requires terminal ID; ScheduledCommand may use null terminal but must still name explicit company/source custody. Add owned `(tenant_id,company_id,id)` unique on pos_terminals and matching RESTRICT FK; its current tenant/company/location identities are at `apps/api/database/migrations/tenant/2026_01_08_190429_create_pos_terminals_table.php:25`. All new status/type columns have enums and T1 CHECK parity gate.

Uniques: `(company_id,id)`, `(company_id,operation_uuid)`, `(company_id,transfer_group_id)`, `(company_id,out_movement_id)`, `(company_id,in_movement_id)`; partial unique `(company_id,reverses_document_id) WHERE reverses_document_id IS NOT NULL`. Indexes: `(company_id,from_repository_id,occurred_at)`, `(company_id,to_repository_id,occurred_at)`, `(company_id,source_location_id,occurred_at)`. Add parent `(tenant_id,company_id,id)` uniques on payment_repositories in migration 210000 and on repository_movements and journal_entries in migration 210100 if absent; each reference includes all three columns. Add `(tenant_id,company_id,id)` on repository_transfer_documents for the reversal FK. Existing `(company_id,id)` document and configuration keys remain company-scoped; the operation unique remains `(company_id,operation_uuid)`, with company ownership enforcing the tenant dimension. Add self-FK after CREATE TABLE.

Direct-SQL checks must also reject mismatched tenant values on referenced legacy repository/leg/JE rows, not just mismatched company IDs. Parent composites are additive and migration-owned. Existing ratchet exclusions classify companies as the scope boundary and users as tenant-global identities (`apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:78`, `:175`); no new waiver or ceiling increase is needed for their identity composites.

Cross-link both ways without updating immutable movements: document points to the two legs; each leg's existing `(company_id,transfer_group_id)` resolves the unique document. Add scoped Eloquent read relationships. NEW documents are inserted after their legs within the same outer transaction. A deferred PG constraint trigger on document INSERT checks: exactly two legs in its company/group; correct directions, repositories, tenant, amount, currency, source_type Transfer, source_id group and matching nullable JE; a cross-GL evidence snapshot requires one posted balanced JE in that company, same-GL requires null JE. An INSERT trigger on movements also checks groups that already have documents, preventing a later third leg. Legacy groups without documents remain readable and are not retroactively synthesized. Trigger checks reversal amount/currency/opposite repositories, original kind transfer and reversed movement links. It also checks the evidence actor discriminator and scalar identity match the relational columns. Human evidence user_id must equal created_by, permission treasury.transfer, and authorized source location match the snapshot. System evidence must match authority/principal/source/terminal and contain no human user identity; inconsistent branch evidence raises SQLSTATE 23514. BEFORE UPDATE/DELETE rejects document mutation; mirror with model guards.

NEW files under `apps/api/app/Modules/Treasury/`: `Domain/CashCustodyConfiguration.php`, `Domain/RepositoryTransferDocument.php`; `Domain/Enums/RepositoryTransferDocumentKind.php` with Transfer='transfer', Reversal='reversal'; `Domain/Enums/RepositoryTransferOutcome.php` with Recorded='recorded', AlreadyRecorded='already_recorded'; `Domain/Enums/CashCustodySaveOutcome.php` with Saved='saved', Unchanged='unchanged'. These are document/outcome types, not Q12 cash-reason codes.

NEW `Application/DTOs/TransferDocumentEvidenceData.php` constructor: `__construct(int $schema_version, ?string $configuration_id, ?int $configuration_revision, ?string $from_gl_account_id, ?string $to_gl_account_id, bool $source_recorded_while_frozen, bool $destination_recorded_while_frozen, ?string $reversal_explanation, HumanTransferAuthorityData|SystemTransferAuthorityData $authorization)`. Validate schema_version=1, configuration id/revision jointly null or present, evidence snapshots against rows while locked. No arbitrary metadata array or mixed. NEW actor union under Application/DTOs, used for both explicit intent and immutable evidence:

- `HumanTransferAuthorityData::__construct(string $tenant_id, string $company_id, string $user_id, array $allowed_source_location_ids, bool $company_wide_authority, string $permission)`; list<string> location IDs, permission treasury.transfer. Readonly discriminator `initiator_kind=Human` derived from class, never request-settable. HTTP adapter validates active user + tenant/company membership + permission/location and captures authorized scope; service validates explicit persisted scope under locks without CompanyContext or resolving a user anew.
- `SystemTransferAuthorityData::__construct(string $tenant_id, string $company_id, RepositoryTransferSystemAuthority $authority, string $principal, string $source_id, ?string $terminal_id, ?string $source_location_id)`; derived `initiator_kind=System`. Stable principal is the fully qualified trusted projection class or command signature, not a label supplied by an operator. FiscalProjection requires a persisted source event UUID and terminal; its future trusted producer validates event ownership before constructing this intent. ScheduledCommand uses a durable invocation/source UUID reused on retry, not a fresh UUID per delivery; terminal may be null only for explicitly company-scoped operations. Validate tenant initialized/explicit compatibility scope, company belongs to tenant, both repositories belong to company, source location matches persisted custody, and supplied terminal's persisted tenant/company/location matches. No membership, request or fabricated user. Source UUID/principal are application authority, not a bearer credential: HTTP MUST NOT deserialize this DTO. Changed principal, authority, source UUID or terminal/source-location provenance on same operation conflicts (existing readable document ID internally), never becomes another actor row.
- NEW `Domain/Enums/RepositoryTransferInitiatorKind.php`: Human='human', System='system'; NEW `Domain/Enums/RepositoryTransferSystemAuthority.php`: FiscalProjection='fiscal_projection', ScheduledCommand='scheduled_command'. These classify provenance, NOT Q12 cash reasons.
- Cross-module terminal check: NEW `apps/api/app/Shared/Contracts/POS/TransferTerminalProvenanceResolver.php` method `resolve(string $tenantId, string $companyId, string $terminalId): TransferTerminalProvenanceData`; NEW shared DTO `TransferTerminalProvenanceData::__construct(string $tenantId, string $companyId, string $terminalId, string $locationId)`. Implement in POS `Application/Services/TransferTerminalProvenanceService.php` using its Terminal model (`apps/api/app/Modules/POS/Domain/Terminal.php:28`, `:80`) and bind in `apps/api/app/Modules/POS/Providers/POSServiceProvider.php:38`, alongside the existing shared-contract binding at :55. Treasury imports only Shared contract/DTO. Revalidate terminal source location under the transfer transaction; no new queue, v2/v3 projection or event consumer is wired. Missing/cross-company terminal fails closed. Trusted system producers are responsible for verifying their source event/run provenance before calling; this slice supplies the typed authority boundary and tests, not those future producers.

Other proposed DTO constructors:

- `CashCustodyConfigurationData::__construct(string $id, string $company_id, string $location_id, int $revision, ?string $default_safe_repository_id, ?string $default_bank_repository_id, bool $enabled)`.
- `CashCustodySaveResult::__construct(CashCustodyConfigurationData $configuration, CashCustodySaveOutcome $outcome)`.
- `RepositoryTransferDocumentData::__construct(string $id, string $operation_uuid, string $transfer_group_id, RepositoryTransferDocumentKind $kind, string $from_repository_id, string $to_repository_id, ?string $source_location_id, string $amount, string $currency, string $out_movement_id, string $in_movement_id, ?string $journal_entry_id, ?string $reverses_document_id, TransferDocumentEvidenceData $evidence, ?string $notes, string $occurred_at, RepositoryTransferInitiatorKind $initiator_kind, ?string $created_by, ?RepositoryTransferSystemAuthority $system_authority, ?string $system_principal, ?string $provenance_source_id, ?string $provenance_terminal_id, string $created_at)`.

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

Convention-09 at the combined T1/T2 gate: all fixtures follow Common real-provisioning above. Second company = test_second_registered_company_accepts_same_operation_uuid; second location = test_second_location_accepts_independent_configuration (assert real pos_enabled location and auto-provisioned drawer); actual mutation rerun = NEW CashCustodySchemaTest::test_actual_configuration_rerun_is_unchanged(): void, first assert second CashCustodyConfigurationService::save result Unchanged, same ID/revision and count after the T2 writer exists. Same CashCustodySchemaTest PG command. test_migration_rerun_reports_nothing_to_migrate remains schema-only, never a business rerun substitute. T1 is not accepted separately from T2.

Implement only after capturing the named red failures, then rerun the exact commands to green. Test results are not claimed by this plan.

T1 enum/union red gates: extend `apps/api/tests/Feature/Treasury/CashCustodySchemaTest.php` with `test_invalid_kind_and_initiator_fail_check(): void` (provider unknown kind, initiator_kind and system_authority; first assert SQLSTATE23514 and unchanged row counts), `test_actor_branch_nullability_is_enforced(): void` (human without user/system with user/system missing principal or fiscal terminal => SQLSTATE23514), and `test_system_document_does_not_require_user(): void` (valid system row created_by SQL NULL, no new users). Run `php artisan test -c phpunit-pgsql.xml --filter=CashCustodySchemaTest` from apps/api. Add existing `apps/api/tests/Architecture/EnumCheckParityTest.php::enum_backed_tenant_columns_match_their_check_constraints_or_the_baseline(): void` (verified at :212) with exact command `php artisan test -c phpunit-pgsql.xml --filter=EnumCheckParityTest`, lane backend-test-pgsql / enum-CHECK parity. Register model casts so detector sees kind/initiator_kind/system_authority; no baseline growth. First assertion: no new or stale parity findings, plus explicit accepted enum cases equal CHECK allowed cases.

Combined T1/T2 gate: treasury-reviewer + tenancy-authz-reviewer inspect real mutation rerun, cardinality, FK ownership, no reason-code/session/alignment additions. Rollback: pre-use only, drop triggers/functions, child tables then owned parent constraints; after recorded money, retain schema and evidence and roll application forward.


**Exact file resolution and typed failure contracts (task-owned production set):** every abbreviated `Application/DTOs/X.php`, `Application/Services/X.php`, `Domain/Enums/X.php`, `Domain/Exceptions/X.php` or `Presentation/...` path in T1–T5 means exactly `apps/api/app/Modules/Treasury/` plus that path; every named Treasury DTO constructor means a NEW file `apps/api/app/Modules/Treasury/Application/DTOs/<ClassName>.php` unless explicitly identified as existing. No implementer choice of namespace/file. The shared terminal DTO is exactly NEW `apps/api/app/Shared/Contracts/POS/TransferTerminalProvenanceData.php`; implementation exactly NEW `apps/api/app/Modules/POS/Application/Services/TransferTerminalProvenanceService.php::resolve(string $tenantId, string $companyId, string $terminalId): TransferTerminalProvenanceData`. T6 adds only tests/handback, no production contract.

| Owner | Exact NEW production file | Full public constructor/signature and mapping |
|---|---|---|
| T2 | `apps/api/app/Modules/Treasury/Domain/Exceptions/CashCustodyRevisionConflictException.php` | extends DomainException; `__construct(public readonly int $currentRevision)`; stable code cash_custody_revision_conflict, 409 with current_revision. |
| T2 | `apps/api/app/Modules/Treasury/Domain/Exceptions/CashCustodyMetadataConflictException.php` | extends DomainException; `__construct(public readonly string $repositoryId)`; stable code cash_custody_metadata_conflict, 422; ID never included in response. |
| T3 | `apps/api/app/Modules/Treasury/Domain/Exceptions/RepositoryTransferConflictException.php` | extends DomainException; `__construct(public readonly string $documentId)`; repository_transfer_conflict, 409 with authorized document_id. |
| T3 | `apps/api/app/Modules/Treasury/Domain/Exceptions/RepositoryTransferWritesDisabledException.php` | extends DomainException; `__construct()`; repository_transfer_writes_disabled, 503, no ID. |
| T3 | `apps/api/app/Modules/Treasury/Domain/Exceptions/LegacyTransferDocumentUnavailableException.php` | extends DomainException; `__construct()`; legacy_transfer_document_unavailable, 409, document_id:null after scoped legacy-source authorization. |
| T3 | `apps/api/app/Modules/Treasury/Domain/Exceptions/RepositoryTransferPrecisionCeilingException.php` | `final class RepositoryTransferPrecisionCeilingException extends \DomainException { public const CODE = 'TRANSFER_PRECISION_EXCEEDS_LEDGER_SCALE'; public function __construct(public readonly string $amount, public readonly int $effectiveScale) { parent::__construct(sprintf('Transfer amount %s exceeds the operational ledger scale %d', $amount, $effectiveScale)); } }`. |
| T4 | `apps/api/app/Modules/Treasury/Domain/Exceptions/RepositoryTransferCorrectionRequiredException.php` | extends DomainException; `__construct(public readonly string $originalDocumentId)`; repository_transfer_correction_required, 422; no ID in envelope. |
| T4 | `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferFrozenWarningData.php` | extends Data; `__construct(string $tenant_id, string $company_id, string $document_id, string $transfer_group_id, string $movement_id, string $repository_id)`; fixed message/marker below, no variable payload keys. |
| T4 | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferFrozenWarningSink.php` | `__construct(private readonly \Psr\Log\LoggerInterface $logger)`; `warn(RepositoryTransferFrozenWarningData $warning): void`; calls injected logger->warning('WCASH-1-FROZEN-TRANSFER:', $warning->toArray()) on the existing Laravel default log channel, no queue/event/outbox/remote transport. |
| T5 | `apps/api/app/Modules/Treasury/Presentation/Services/RepositoryTransferErrorRenderer.php` | `render(CashCustodyRevisionConflictException\|CashCustodyMetadataConflictException\|RepositoryTransferConflictException\|RepositoryTransferWritesDisabledException\|LegacyTransferDocumentUnavailableException\|RepositoryTransferCorrectionRequiredException\|RepositoryFrozenException\|RepositoryCheckpointException\|InsufficientRepositoryBalanceException\|CurrencyMismatchException\|RepositoryTransferPrecisionCeilingException $error): JsonResponse`; no constructor dependencies. `RepositoryTransferPrecisionCeilingException` renders exactly `422 {message: __('treasury.transfer.precision_exceeds_ledger_scale'), error: {code: "TRANSFER_PRECISION_EXCEEDS_LEDGER_SCALE", amount: <numeric-string>, effective_scale: <int>}}` (flat `error` extras like the other 409/422 envelopes; no nested `details`). Backend key `transfer.precision_exceeds_ledger_scale` in `apps/api/lang/{en,fr,ar}/treasury.php`; frontend key `transfer.precisionExceedsLedgerScale` in `apps/web/src/locales/{en,fr,ar}/treasury.json` (rule 11). The dedicated renderer test is the NEW registered `apps/api/tests/Feature/Treasury/RepositoryTransferErrorContractTest.php`, class `Tests\Feature\Treasury\RepositoryTransferErrorContractTest`, method `test_precision_ceiling_renders_422_with_code_amount_and_effective_scale(): void`; its exact assertion, command and lane are registered below. Union bars here denote literal PHP union separators. |

Controller mapping is explicit: constructor-inject RepositoryTransferErrorRenderer into `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php` and `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php`; their transfer/reverse/custody/metadata methods catch only the listed typed failures and return renderer->render. Do not catch arbitrary Throwable/DomainException and mask bugs as 422. Renderer extras are limited to `current_revision`, `document_id`, or, for the precision case only, `amount` + `effective_scale`. Laravel ValidationException handles input/service field failures (inactive repository, invalid type, incompatible currency, invalid authority branch) via field errors with numeric-string input preserved; Laravel's existing auth/model-not-found handling remains. Existing lower-port RepositoryFrozenException, RepositoryCheckpointException, InsufficientRepositoryBalanceException and CurrencyMismatchException map respectively to 422 codes repository_frozen, repository_checkpoint, insufficient_repository_balance, currency_mismatch, with no private IDs/amounts. Renderer returns exactly `{message:translated,error:{code:<listed-code>}}` plus ONLY the documented current_revision or document_id extras. Add translation keys `messages.treasury.<code>` for each code in the three `apps/api/lang/{en,fr,ar}/messages.php` files EXCEPT `RepositoryTransferPrecisionCeilingException`: its message lives only under `transfer.precision_exceeds_ledger_scale` in the three `apps/api/lang/{en,fr,ar}/treasury.php` files and is explicitly excluded from the generic `messages.treasury.*` instruction. Source authorization must precede exception creation carrying readable IDs; no global renderer shortcut can bypass it. Existing controller seam is `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:21`.

T2 test_stale_changed_configuration_conflicts first asserts CashCustodyRevisionConflictException class/currentRevision; metadata invalidation test asserts CashCustodyMetadataConflictException. T3 conflict/write-disabled/legacy tests assert those exact classes and readonly fields; T4 mapping-change test asserts RepositoryTransferCorrectionRequiredException. NEW T5 file `apps/api/tests/Feature/Treasury/RepositoryTransferErrorContractTest.php`, namespace/class `Tests\Feature\Treasury\RepositoryTransferErrorContractTest`: `test_typed_failure_status_and_envelope(): void` provides every listed exception and first asserts exact status and complete JSON envelope (translated message, exact code/allowed extras, no additional properties); its precision provider case constructs `new RepositoryTransferPrecisionCeilingException('1.0005', 3)` and binds that exception to status 422. Dedicated method `test_precision_ceiling_renders_422_with_code_amount_and_effective_scale(): void` constructs that same exception, calls `RepositoryTransferErrorRenderer::render(...)`, and its first failing assertion is `$this->assertSame(422, $response->getStatusCode())`; it then asserts `$this->assertSame(['message' => __('treasury.transfer.precision_exceeds_ledger_scale'), 'error' => ['code' => RepositoryTransferPrecisionCeilingException::CODE, 'amount' => '1.0005', 'effective_scale' => 3]], $response->getData(true))`, proving the complete translated envelope and that `error` has no additional properties. `test_scoped_denial_precedes_readable_conflict(): void` asserts 404 without any document ID for an inaccessible original before the changed-payload conflict. Exact command from `apps/api`: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferErrorContractTest`; named lane **backend-test-pgsql / WCASH-1 isolated PG**. Service gates run their named typed tests immediately; T5 mapping tests do not substitute for those gates.

## T2 — Location settings, activation validator and census

Verified production anchors: existing repository reads/writes `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:33`, `:153`; Console imports `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:37` and command registration `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:236`. Existing progression activation calls an external client (`apps/api/app/Modules/Progression/Application/Services/ProgressionService.php:106`); it is not evidence of a cash-custody activation mechanism.

NEW `Application/Services/CashCustodyConfigurationService.php` public signatures:

- `save(string $tenantId, string $companyId, string $locationId, ?string $safeRepositoryId, ?string $bankRepositoryId, bool $enabled, int $expectedRevision, string $actorId): CashCustodySaveResult`.
- `current(string $tenantId, string $companyId, string $locationId): ?CashCustodyConfigurationData`.
- `assertReadyForActivation(string $tenantId, string $companyId): void`.
- `census(string $tenantId, string $companyId): CashCustodyCensusData`.

NEW `Application/DTOs/CashCustodyCensusData.php`: `__construct(string $tenant_id, string $company_id, CashCustodyCensusStatus $status, array $unconfigured_location_ids, array $unlocated_drawer_ids, array $missing_bank_location_ids)`; each array is `list<string>`, never untyped arbitrary JSON. This is transport, not a stored JSON column.

Activation means enabling a location's cash-custody configuration, not granting a module licence or enabling any event consumer. `save(...enabled:true...)` validates the selected location; the company-wide validator refuses readiness if ANY location with an active drawer lacks an enabled configuration with a valid safe, or an active drawer lacks a location. Later W-CASH activation must call this public validator before enabling its consumer. This slice wires the local enabled transition and ships the company readiness command; it does not pretend to activate a nonexistent projector.

The NEW `Application/Services/RepositoryMetadataService.php` is the single in-scope repository metadata writer: `update(string $tenantId, string $companyId, string $repositoryId, RepositoryMetadataUpdateData $changes, string $actorId): PaymentRepository`. NEW `Application/DTOs/RepositoryMetadataUpdateData.php` uses Spatie Optional to distinguish omitted from explicit null; full constructor is `__construct(string|Optional $code, string|Optional $name, RepositoryType|Optional $type, bool|Optional $allow_negative, string|null|Optional $bank_id, string|null|Optional $bank_name, string|null|Optional $account_number, string|null|Optional $iban, string|null|Optional $bic, string|null|Optional $location_id, string|null|Optional $responsible_user_id, string|null|Optional $account_id, string|null|Optional $gl_account_id, bool|Optional $is_active)`. All arguments are explicit; the adapter passes Optional::create() for omitted fields, never implicit null. No balance, ordinal, tenant_id or company_id is writable.

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
| `CashCustodyConfigurationTest::test_repository_mutation_cannot_invalidate_enabled_defaults(): void` | Provider type/location/is_active/gl_account_id/account_id on safe/bank/drawer: expect CashCustodyMetadataConflictException; entire metadata/configuration/balance snapshot unchanged. |
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

Convention-09 at T2 gate: CashCustodyConfigurationTest::test_second_company_defaults_are_independent; ::test_second_location_save_uses_selected_safe; ::test_identical_save_returns_unchanged. Census rerun also has byte-equal deterministic output. All referenced cases MUST use Common real-provisioning: real B creation, real second pos_enabled shop and asserted auto-provisioned drawer company/location; repeat the named actual mutation, never just migrations.

Implement only after capturing the named red failures, then rerun the exact commands to green. Test results are not claimed by this plan.

Gate: treasury-reviewer + tenancy-authz-reviewer check activation semantics, console tenant lifecycle and central-destination authorization. Rollback: disable the new configuration through a new revision, retain revision history; no balance changes to undo.

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

Production anchors: wrapper rejects either frozen repository at `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:46`; single-leg port allows explicitly flagged record-and-alert at `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:91`; paired port currently hardcodes false at `:371`, `:391`, and event builder `:639`. Existing TransferIntent has no freeze/reversal fields (`apps/api/app/Modules/Treasury/Application/DTOs/TransferIntent.php:26`).

Extend that constructor, retaining its complete existing parameter order: `__construct(string $fromRepositoryId, string $toRepositoryId, string $tenantId, string $companyId, string $amount, string $currency, string $transferGroupId, ?string $journalEntryId, ?CarbonImmutable $occurredAt, ?string $createdBy, ?string $notes, bool $allowWhileFrozen = false, ?string $reversesOutMovementId = null, ?string $reversesInMovementId = null)`. The lower-level movement port public transfer signature remains unchanged; the high-level compatibility transition is specified in T3/T5. Extend private `buildTransferLegEvent(string $movementId, string $repositoryId, TransferIntent $intent, MovementDirection $direction, string $balanceAfter, int $ordinal, ?string $journalEntryId, CarbonInterface $occurredAt, bool $recordedWhileFrozen, ?string $reversesMovementId): RepositoryMovementRecorded`; propagate the actual flag and direction-specific reversal ID into both existing event instances without changing event structure. New out receives original in ID; new in receives original out ID. Ordinary transfers pass null.


**Immutable event contract and audit proof:** use existing original-version (v1, un-suffixed) `App\Modules\Treasury\Domain\Events\RepositoryMovementRecorded` from `apps/api/app/Modules/Treasury/Domain/Events/RepositoryMovementRecorded.php:23`. Its constructor already accepts reversesMovementId at :43 and getAuditPayload already emits reverses_movement_id at :75. Do NOT edit that event class or change event name/payload shape; rule 8 needs no V2 for populating an existing nullable field. No versioned replacement is introduced here. Extend ONLY TreasuryMovementService's private builder and both callsites to pass the matching reversal IDs as specified above; if implementation discovers a need for extra event keys, that would require a separately specified V2 and cannot silently modify v1. Compliance already persists the payload through `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1144`; it stays unchanged and registered at :1273.

Extend `apps/api/tests/Feature/Treasury/RepositoryTransferReversalTest.php` with `test_reversal_events_reference_original_opposite_legs(): void`, first assert both actual RepositoryMovementRecorded instances have new-out→original-in and new-in→original-out IDs and unchanged event name/keys; `test_reversal_audit_rows_reference_original_opposite_legs(): void` runs with real Compliance subscriber (no Event::fake), commits, then asserts both new movement audit payloads' reverses_movement_id and aggregate IDs match those same legs; original audit rows unchanged. `test_reversal_retry_emits_no_duplicate_audit_rows(): void` compares event/audit counts after AlreadyRecorded retry. Same T4 PG command. Event-fake test and real-subscriber test use separate fixtures; no fake may intercept the audit proof.

The high-level transfer service constructor-injects RepositoryTransferFrozenWarningSink and schedules warn(dto) in DB::afterCommit only after successful new document insertion for each frozen leg. It does not emit a warning on replay or rollback; no callback executes before the document transaction commits. Extend RepositoryTransferFrozenPolicyTest with `test_warning_sink_payload_is_exact_and_after_commit(): void` asserting literal WCASH-1-FROZEN-TRANSFER: and exactly tenant_id,company_id,document_id,transfer_group_id,movement_id,repository_id, values tied to the frozen leg; before commit zero warnings. `test_rollback_and_retry_do_not_warn(): void` asserts rollback zero and AlreadyRecorded no increment. Same PG command. This log warning is distinct from the immutable domain audit event and adds no event schema.

NEW `Application/DTOs/RepositoryTransferReversalIntent.php`: `__construct(string $tenantId, string $companyId, string $originalDocumentId, string $operationUuid, ?string $sourceLocationId, string $explanation, CarbonImmutable $occurredAt, HumanTransferAuthorityData|SystemTransferAuthorityData $authority)`.

Add `RepositoryTransferService::reverse(RepositoryTransferReversalIntent $intent): DocumentedRepositoryTransferResult` while disconnected in Push 3; atomically change return type to RepositoryTransferResult at Push 5 with the transfer method and all callers. Full reversal only; reject reversal-of-reversal, partial amounts, duplicate reversal under a different operation, foreign original and unauthorized current source. Serialize original identity before operation lock consistently for reversal calls, then tenant-numbering/company lock; authorize the original destination as the reversal’s actual source before comparing actor/semantics. Same-operation reversal retry authorizes the recorded reversal source before returning or exposing a conflict. Swap original repositories, preserve original amount/currency, new operation/group/document, link reverses_document_id; new out reverses original in, new in reverses original out. Do not mutate original document or old JE. Require current GL mapping to equal original evidence; otherwise refuse with explicit correction-required error, preventing a misleading reversal under changed accounts. Respect current accounting-period/checkpoint and source-balance controls.

Interactive HTTP always constructs allowWhileFrozen=false and forbids that field in input. At atomic Push 5 remove the legacy wrapper’s pre-replay freeze loop at RepositoryTransferService.php:46; the disconnected typed core uses the new policy from T4 onward; move the decision into the paired movement port after replay detection and under both repository locks. A new interactive transfer to/from a frozen repository refuses atomically. Explicit internal allowWhileFrozen=true records both legs, marks only frozen legs and emits an after-commit structured warning per frozen leg with document/group/repository identifiers. This is a tested prerequisite contract only: no new worker, event subscription or device authority. Exact successful retry emits no duplicate money effects or alerts. The reversal explanation is first-submission semantic content: same operation with changed explanation or another authorized actor is 409 with the existing readable reversal ID. No negative-balance or checkpoint bypass is introduced. P0-b acceptance is required before T4 money tests. No new schema or cash-reason enum beyond T1/P0.

Red-first contract register:

File **NEW unless already cited**: `apps/api/tests/Feature/Treasury/RepositoryTransferReversalTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferReversalTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `RepositoryTransferReversalTest::test_reverse_appends_linked_document_and_preserves_original(): void` | Assert reverses_document_id equals original ID; original row bytes/JE unchanged; exact opposite linked legs. |
| `RepositoryTransferReversalTest::test_repeat_reverse_returns_original_reversal(): void` | Assert AlreadyRecorded and same reversal ID; no extra document/leg/JE. |
| `RepositoryTransferReversalTest::test_second_reversal_operation_is_refused(): void` | Expect conflict with original reversal ID and unchanged monetary snapshot. |
| `RepositoryTransferReversalTest::test_mapping_change_refuses_reversal(): void` | Expect RepositoryTransferCorrectionRequiredException, no second document or journal. |
| `RepositoryTransferReversalTest::test_second_company_reversal_is_independent(): void` | Real company B reverses its own transfer; assert B IDs/amount restored, A unchanged. |
| `RepositoryTransferReversalTest::test_second_location_reversal_uses_current_outflow_custody(): void` | Explicit intent for allowed second-branch source restores correct two repositories; denial variant leaves all snapshots unchanged. |
| `RepositoryTransferReversalTest::test_reversal_actor_and_explanation_conflict(): void` | Provider different authorized actor/changed explanation on same operation: expect conflict with existing reversal ID, no extra leg. |

File **NEW unless already cited**: `apps/api/tests/Feature/Treasury/RepositoryTransferFrozenPolicyTest.php`. Named lane: **backend-test-pgsql / WCASH-1 isolated PG**. Exact command from apps/api: `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferFrozenPolicyTest`.

| Exact test | First failing assertion / required data assertion |
|---|---|
| `RepositoryTransferFrozenPolicyTest::test_explicit_frozen_destination_records_and_alerts(): void` | Destination leg recorded_while_frozen=true and exactly one after-commit warning with document/group/repository ID. |
| `RepositoryTransferFrozenPolicyTest::test_interactive_frozen_destination_has_no_effect(): void` | Expect RepositoryFrozenException; document/movement/GL/balance/ordinal snapshot identical. |
| `RepositoryTransferFrozenPolicyTest::test_retry_after_freeze_returns_original(): void` | Same actor retry AlreadyRecorded with same document/leg IDs and zero newly emitted warnings. |

Convention-09 at T4 gate: RepositoryTransferReversalTest::test_second_company_reversal_is_independent; ::test_second_location_reversal_uses_current_outflow_custody; ::test_repeat_reverse_returns_original_reversal. Freeze replay outcome explicitly AlreadyRecorded. All referenced cases MUST use Common real-provisioning: real B creation, real second pos_enabled shop and asserted auto-provisioned drawer company/location; repeat the named actual mutation, never just migrations.

Implement only after capturing the named red failures, then rerun the exact commands to green. Test results are not claimed by this plan.

Gate: treasury-reviewer + tenancy-authz-reviewer inspect compensation direction, current source custody, immutable original and non-client-settable freeze intent. Rollback: disable new reversal submissions; preserve all original/reversal evidence, human/system provenance and money rows. Systems use the same full-reversal invariants and cannot switch principal/provenance on retry.

## T5 — Existing HTTP and web surfaces, generated types

Verified production files: `apps/api/app/Modules/Treasury/Presentation/routes.php:35`, `:102`; transfer controller `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:21`; request `apps/api/app/Modules/Treasury/Presentation/Requests/TransferRepositoryRequest.php:21`; repository controller `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:420`; `apps/web/src/features/treasury/hooks/useTransferCash.ts:5`; `apps/web/src/features/treasury/hooks/usePaymentRepositories.ts:7`; `apps/web/src/features/treasury/RepositoryListPage.tsx:23`; `apps/web/src/features/treasury/RepositoryDetailPage.tsx:27`; `apps/web/src/features/treasury/PaymentForm.tsx:60`; `apps/web/src/features/treasury/components/TransferCashModal.tsx:28`, `:84`; existing web module wrappers `apps/web/src/routes/index.tsx:1937`.

**Hard prerequisite W1-T-CUSTODY-AUTHZ:** the accepted W1 source-custody/permission task implementing spec `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:139` and permission rollout at `:147`. Before T5 starts, dispatch must pin its implementation SHA, treasury-reviewer + tenancy-authz-reviewer acceptance, seeded `treasury.manage_all_locations`/general_manager behavior and existing-tenant cache/grant evidence. The current treasury permission catalogue at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:260` ends its relevant action set at :266 without this bypass. No temporary permission and no silent seeder scope absorption in WCASH-1. T1–T4 may gate against explicit service intents, but no T5 HTTP green or activation before the accepted W1 prerequisite. This is a named external task prerequisite, not a claim that its implementation already exists. Broad human-index restriction still waits for W1/W2's device transition; new transfer/config modes enforce source custody immediately.

**Unknown-field mechanism (all three requests):** implement `public function withValidator(\Illuminate\Validation\Validator $validator): void` and register `$validator->after(function (Validator $validator): void { ... })`, as used by `apps/api/app/Modules/Company/Presentation/Requests/CreateLocationRequest.php:70`. Compare `array_keys($this->all())` to an explicit top-level allowlist BEFORE reading validated output; for every `array_diff` key call `$validator->errors()->add($key, __('messages.validation.unexpected_field'))`. Do not rely on finite prohibited fields or silently dropping unruled keys. Transfer allowlist = operation_uuid, transfer_group_id, from_repository_id, to_repository_id, amount, currency, notes; reverse = operation_uuid, explanation; custody = location_id, default_safe_repository_id, default_bank_repository_id, enabled, expected_revision. These are scalar payloads; arrays/objects in their place fail their scalar rules. Route IDs are not merged into request input. Apply to JSON/form input and query keys; forged authority and arbitrary unexpected_field both fail. Add the unexpected_field validation message to `apps/api/lang/en/messages.php`, `apps/api/lang/fr/messages.php` and `apps/api/lang/ar/messages.php` during implementation. Use the same callback for UUID-alias equality and blank normalized explanation validation; no invented unsupported hook.

Keep POST `/api/v1/payment-repositories/transfers`; route store now calls RepositoryTransferHttpAdapter::transfer. `TransferRepositoryRequest::authorize(): bool` checks treasury.transfer; complete `rules(): array`: operation_uuid = required_without:transfer_group_id, uuid; transfer_group_id = nullable, uuid, deprecated operation alias; from_repository_id = required, uuid; to_repository_id = required, uuid, different:from_repository_id; amount = required, string, numeric, gt:0, regex `/^\d+(\.\d{1,4})?$/`, max:16 with decimal(15,4) overflow checked explicitly in service; currency = required, string, size:3, regex `/^[A-Z]{3}$/`; notes = nullable, string, max:1000. This HTTP ceiling protects the storage shape; after it passes, the service MUST reject a value whose fractional digits exceed `min(countries.currency_decimal_places, RepositoryTransferPrecisionPolicy::OPERATIONAL_SCALE_CEILING)` — the ceiling is 3 until the movement ledger is widened — so scale-four storage never hard-codes four-decimal business precision and no operational transfer can exceed the movement-ledger scale. If both UUID names are present require equality in the withValidator after callback. Unknown fields are rejected; explicitly prohibit allowWhileFrozen/allow_while_frozen, actor_id, company_id, tenant_id, source_location_id, configuration_id/revision, occurred_at and kind. No silent identity generation. Legacy alias without currency is a 422, not a guessed retry; web deploy precedes activation.

NEW `apps/api/app/Modules/Treasury/Presentation/Requests/ReverseRepositoryTransferRequest.php`: `authorize(): bool` = treasury.transfer; `rules(): array`: operation_uuid required|uuid, explanation required|string|max:1000 plus not-whitespace validation; all other fields prohibited, including amount/destination/actor/freeze/occurred_at. Original document ID comes solely from the UUID-validated route. Server chooses current occurred_at at first submission, preserving it on retry.

NEW `apps/api/app/Modules/Treasury/Presentation/Requests/SaveCashCustodyRequest.php`: `authorize(): bool` = repositories.manage; `rules(): array`: location_id required|uuid, default_safe_repository_id present|nullable|uuid, default_bank_repository_id present|nullable|uuid|different:default_safe_repository_id, enabled required|boolean, expected_revision required|integer|min:0. enabled=true adds required_if:enabled,true to safe. Unknown/authority fields prohibited. Do not use unscoped exists validators: adapter scopes route drawer and location first (404), then service validates owned/type-compatible defaults (422). A permitted route drawer's persisted location must equal location_id or return 422 without disclosing foreign location information.

Controller additions retain signatures: `RepositoryTransferController::show(Request $request, string $documentId): JsonResponse`, `reverse(ReverseRepositoryTransferRequest $request, string $documentId): JsonResponse`; `PaymentRepositoryController::saveCashCustody(SaveCashCustodyRequest $request, string $id): JsonResponse`. Add GET `/payment-repositories/transfers/{documentId}`, POST `/payment-repositories/transfers/{documentId}/reverse`, PUT `/payment-repositories/{id}/cash-custody`, UUID route constraints; fixed paths precede generic repository `{id}`. Preserve api/auth:sanctum/SetPermissionsTeam/EnforceTokenTenantClaim (`apps/api/app/Modules/Treasury/Presentation/routes.php:35`) and add `module:Treasury` to in-scope reused routes. RequireModule is case-sensitive (`apps/api/app/Http/Middleware/RequireModule.php:24`). Do not gate unrelated customer-account deposits as collateral work.

Index mode contract on GET `/payment-repositories`: omitted `mode` retains existing response for compatibility/W1 rollout; `mode=transfer_source` returns active physical repositories for authorized current source custody; `mode=transfer_destination&from_repository_id=<uuid>` first scopes source then returns only authorized branch destinations or its explicitly configured central safe/bank. Unknown mode = 422; destination mode requires UUID source; source mode prohibits from_repository_id. Both transfer modes require treasury.transfer + module; configuration edit still requires repositories.manage and W1 custody authority. Both return `{data: RepositoryDestinationData[]}` where NEW `RepositoryDestinationData::__construct(string $id, string $name, RepositoryType $type, string $currency)` contains no balances/account/IBAN fields. Do not feed destination-mode data into full repository/balance caches. Query keys include mode and source ID in addition to tenant/company suffixes.

Resolve allowed IDs only in HTTP via `resolve($user, [], 'treasury.manage_all_locations')`; query persisted source under tenant/company/allowed location and use findOrFail. Unlocated/central source requires company-wide authority. On replay authorize stored source first as T3 defines, never the possibly substituted request source. Inbound central eligibility does not authorize reverse outflow. For document reads require repositories.view and authority over BOTH the historical source location and current source repository (or company-wide authority); destination-only authority does not grant full document access. The same rule applies to links discovered through either repository's history; suppress unreadable document links/embedded evidence there and return 404 on direct ID access. Full evidence may reference an allowed central destination but never expose that repository's bank credentials or balance; document response uses movement IDs rather than destination balance. Transfer success leg balance fields are source-authorized only: use a generated nullable balance_after on the destination leg when destination custody is not readable, and return null. No second catalogue or implicit central read permission.

HTTP envelopes/statuses: new transfer/reversal = 201 `{message,data:RepositoryTransferResponseData}` with outcome recorded; same-actor exact replay = 200 same envelope with already_recorded and same document ID; document GET = 200 `{data:RepositoryTransferDocumentData}`; configuration real save = 200 `{data:CashCustodySaveResult}` with saved, identical save = 200 unchanged. Semantic/actor conflict = 409 `{message,error:{code:"repository_transfer_conflict",document_id:<existing-readable-id>}}`; stale different configuration = 409 `{message,error:{code:"cash_custody_revision_conflict",current_revision:int}}`; amount above the operational ledger scale = exact translated 422 `{message:__('treasury.transfer.precision_exceeds_ledger_scale'),error:{code:"TRANSFER_PRECISION_EXCEEDS_LEDGER_SCALE",amount:<numeric-string>,effective_scale:int}}`, whose `error` object contains ONLY `code`, `amount`, and `effective_scale`. Known accessible legacy alias/group with no document = 409 `{message,error:{code:"legacy_transfer_document_unavailable",document_id:null}}`, zero new money; inaccessible legacy source = 404 before that result. Existing reversal under a new operation = 409 existing-readable reversal ID. Validation = 422 `{message,errors:{field:[message]}}`; scoped miss = 404 `{message:"Not found"}` without IDs; action/module denied = 403; unauthenticated = 401; frozen/inactive/type/currency/checkpoint/balance refusal = 422 with stable code/message and no writes; flag false = 503 `{message,error:{code:"repository_transfer_writes_disabled"}}`. No original document ID in unauthenticated/forbidden/scoped-miss responses. Service conflict exception carries ID only after adapter source authorization.

NEW transport constructors: `RepositoryTransferRequestData::__construct(string $operation_uuid, string $from_repository_id, string $to_repository_id, string $amount, string $currency, ?string $notes)`; `RepositoryTransferResponseData::__construct(RepositoryTransferDocumentData $document, RepositoryTransferOutcome $outcome, string $transfer_group_id, ?string $journal_entry_id, bool $idempotent_replay, RepositoryTransferLegData $out, RepositoryTransferLegData $in)`; `RepositoryTransferLegData::__construct(string $movement_id, ?string $balance_after, string $repository_id)`. Keep normalized original content including free-text notes as the semantic reason; no Q12 reason mapping in this slice.


NEW `Application/DTOs/PaymentRepositoryData.php` owns the existing formatted repository response at PaymentRepositoryController.php:420: constructor `__construct(string $id, string $code, string $name, RepositoryType $type, bool $allow_negative, ?string $bank_id, ?string $bank_name, ?string $account_number, ?string $iban, ?string $bic, string $balance, string $currency, bool $is_active, ?string $gl_account_id, ?RepositoryGlAccountData $gl_account, ?string $location_id, ?string $location_name, RepositoryBankValidationData $bank_account_validation, ?CashCustodyConfigurationData $cash_custody)`. Nested NEW DTOs: `RepositoryGlAccountData::__construct(string $id, string $code, string $name)`; `RepositoryBankValidationData::__construct(?RibValidationResult $rib, ?IbanValidationResult $iban, ?bool $bic_valid)` using existing validator result types. Export their nested shapes too; do not add an unwritten is_default field just because the local hook currently declares it (`usePaymentRepositories.ts:13`). These response DTOs are not stored JSON columns.

**Durable repository-history projection (T5 exact file set):** change `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryMovementController.php:36` and its response builder at :101; NEW `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryMovementData.php`; `apps/web/src/features/treasury/hooks/useRepositoryMovements.ts:38`; `apps/web/src/features/treasury/components/RepositoryMovementsTab.tsx:58`; and `apps/web/src/features/treasury/RepositoryDetailPage.tsx:229`. The existing tab returns null for transfer source routes (`RepositoryMovementsTab.tsx:54`, :69); the new behavior must replace that fallback only when the server projects an authorized document link.

RepositoryMovementData constructor preserves ALL current response fields at RepositoryMovementController.php:109: `__construct(string $id, MovementDirection $direction, string $amount, string $allocated_amount, string $remaining_allocatable_amount, string $currency, string $balance_after, int $ordinal, MovementSourceType $source_type, string $source_id, ?string $journal_entry_id, ?MovementReasonCode $reason_code, string $occurred_at, bool $recorded_while_frozen, bool $recorded_behind_checkpoint, ?string $transfer_document_id, ?string $transfer_document_href)`. No JSON storage or new columns. Resolve each transfer leg's document by tenant/company/group in a batch and apply exactly the document-read policy from T5; both out/in histories can discover the same ID when the requesting human has source authority. `transfer_document_href` points to the EXISTING repository detail page with `?transfer_document_id=<uuid>` on the currently viewed repository; use the existing repository route builder rather than a new document list page. Detail page loads that UUID through the scoped show endpoint on initial load/reload. Transfer tab uses projected href, never constructs one from source_id, which is a transfer group. Destination-only observer receives null ID/href and no evidence; direct show returns404. No evidence blob or central credentials embedded in movement response. Preserve meta pagination and existing filters/query scoping; hook imports ambient generated RepositoryMovementData instead of the local structural interface/enums. Legacy group with no document stays unlinked. A system-authored document is readable under the same human custody rule; null created_by must not suppress valid provenance display.

**Generated-global wiring:** current `packages/shared/types/generated.d.ts:1` declares ambient `App.*` namespaces, not named PaymentRepositoryData module exports. Web includes it at `apps/web/src/vite-env.d.ts:8`; POS currently only references Vite at `apps/pos/src/vite-env.d.ts:1`. T5 must edit `apps/pos/src/vite-env.d.ts` to add `/// <reference path="../../../packages/shared/types/generated.d.ts" />` (same relative path as web, with its lint suppression/comment as needed). Use `type PaymentRepository = App.Modules.Treasury.Application.DTOs.PaymentRepositoryData`, or Pick of that fully qualified ambient type, in both apps; no unresolved named import from generated.d.ts and no new runtime dependency. Enum/other DTO aliases use their corresponding fully qualified generated namespace. Include the wiring file and both typecheck fixtures in T5 file list. NEW movement DTO below uses the same ambient namespace. Compile assertions must reference that complete namespace and exercise at least one actual POS API/storage consumer, not an isolated hand-defined type.

Add the missing live consumer: `apps/web/src/features/treasury/statements/StatementUploadWizard.tsx:26`, replacing WizardRepository with `Pick<App.Modules.Treasury.Application.DTOs.PaymentRepositoryData, 'id' | 'name' | 'currency'>`. Its production data source is `apps/web/src/features/treasury/statements/StatementListPage.tsx:56`; include that file in T5 typecheck/consumer verification. No statement behavior change.

Replace ALL repository-response shadows, including the following verified census, with the ambient generated aliases defined above or `Pick<App.Modules.Treasury.Application.DTOs.PaymentRepositoryData,...>` aliases (not re-declared fields). Census paths/anchors: `apps/web/src/features/treasury/RepositoryListPage.tsx:23`, `RepositoryDetailPage.tsx:27`, `PaymentForm.tsx:60`, `SplitPaymentForm.tsx:27`, `hooks/usePaymentRepositories.ts:7`, `hooks/useRemittances.ts:9`; `apps/web/src/components/organisms/RecordPaymentModal/RecordPaymentModal.tsx:28`; `apps/web/src/components/organisms/AddRepositoryModal/AddRepositoryModal.tsx:41`; `apps/web/src/features/pos/api/paymentRepositoryApi.ts:9`; `apps/pos/src/types/payment.ts:27`. Replace the repository request response generic in `apps/web/src/features/treasury/InstrumentDetailPage.tsx:134` with a generated Pick; do not redefine its generic Relation type globally for unrelated entities. Update generated-type aliases/re-exports through `apps/web/src/features/pos/organisms/AdvancedPaymentsModal/AdvancedPaymentsModal.tsx:185`, `apps/pos/src/api/paymentApi.ts:9`, `apps/pos/src/lib/sync/syncService.ts:1256` and the transfer modal at `apps/web/src/features/treasury/components/TransferCashModal.tsx:15`.

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
| `RepositoryTransferEndpointTest::test_scale_four_company_transfer_of_10005_returns_422_precision_code(): void` | First assertion: `$response->assertStatus(422)->assertExactJson(['message' => __('treasury.transfer.precision_exceeds_ledger_scale'), 'error' => ['code' => RepositoryTransferPrecisionCeilingException::CODE, 'amount' => '1.0005', 'effective_scale' => 3]])`; then assert exact equality of `$response->json('error')` to those three keys only and unchanged document, movement, both repository balance/ordinal, and journal-entry snapshots. |
| `RepositoryTransferEndpointTest::test_request_status_contract(): void` | Provider 201/200/409/422/404/403/401/503 cases from T5 contract; the precision 422 case constructs `RepositoryTransferPrecisionCeilingException('1.0005', 3)` and asserts its exact translated envelope/error shape, while every case asserts exact status, envelope/error code and relevant unchanged snapshot. |
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

Convention-09 at T5 gate: RepositoryTransferEndpointTest::test_real_second_company_http_transfer_is_independent; ::test_second_location_http_configuration_and_transfer; ::test_exact_http_retry_has_explicit_outcome. UI rerun explicitly unchanged/already_recorded; accepted W1 prerequisite must be pinned before these HTTP gates. All referenced cases MUST use Common real-provisioning: real B creation, real second pos_enabled shop and asserted auto-provisioned drawer company/location; repeat the named actual mutation, never just migrations.

Implement only after capturing the named red failures, then rerun the exact commands to green. Test results are not claimed by this plan.

Additional T5 backend tests in `apps/api/tests/Feature/Treasury/RepositoryTransferEndpointTest.php`, same PG command/lane: `test_arbitrary_unknown_transfer_field_is_422(): void`, `test_arbitrary_unknown_reverse_field_is_422(): void`, `test_arbitrary_unknown_custody_field_is_422(): void` each submits top-level `surprise` and first asserts 422 with `errors.surprise.0 === __("messages.validation.unexpected_field")`, then unchanged configuration/document/movement/GL snapshot; include query-key and nested-value variants. `test_http_system_actor_payload_is_rejected(): void` first asserts 422 and `errors.<actual-submitted-key>.0 === __("messages.validation.unexpected_field")` for each system_authority/initiator_kind/provenance field, users and money unchanged. `test_controller_uses_typed_signature_after_adapter_removal(): void` first asserts201 valid transfer through final controller route (W1 prerequisite accepted); static caller census must show no old scalar invocation.

NEW `apps/api/tests/Feature/Treasury/RepositoryTransferHistoryTest.php`, named lane backend-test-pgsql, command `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferHistoryTest`: `test_both_authorized_histories_project_same_document(): void` first assert out/in movement data share non-null transfer_document_id and scoped href; `test_destination_only_history_omits_link_and_evidence(): void` first assert null ID/href and no evidence, then show404; `test_system_document_history_is_readable_without_user_actor(): void` asserts same authorized link with null created_by and displayed system provenance. No snapshot mutations on any read.

NEW web test `apps/web/src/features/treasury/components/RepositoryMovementsTab.transferDocument.test.tsx`, tests `RepositoryMovementsTab > both legs open the persisted transfer document after reload` (first assert link still resolves same ID after remount) and `RepositoryMovementsTab > destination-only history has no document link` (first assert link absent). Extend `apps/web/src/features/treasury/RepositoryDetailPage.custody.test.tsx` with `RepositoryDetailPage > query document survives reload and respects scoped 404` (first assert loaded document ID then no evidence on404). Exact command from apps/web: `pnpm exec vitest run src/features/treasury/components/RepositoryMovementsTab.transferDocument.test.tsx src/features/treasury/RepositoryDetailPage.custody.test.tsx`. Extend the existing typecheck fixture to assert WizardRepository equality and POS ambient namespace resolution. No device build or POS runtime behavior change.

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

Convention-09 at T6 gate: CashCustodySecondOfEverythingTest::test_registered_second_company_can_reuse_operation_uuid; ::test_selected_second_pos_location_keeps_its_own_custody; ::test_rerun_configuration_and_transfer_are_explicit. Concurrency tests use committed fixture/processes and barriers; inability to execute PG is failure/incomplete, never a SQLite substitute. DB_HOST/DB_PORT/DB_USERNAME/DB_PASSWORD/DB_DATABASE/DB_CENTRAL_DATABASE must target isolated lane databases. No testing performed in this planning round. All referenced cases MUST use Common real-provisioning: real B creation, real second pos_enabled shop and asserted auto-provisioned drawer company/location; repeat the named actual mutation, never just migrations.

Local host verification: `PREFLIGHT_TEST_PATHS='tests/Feature/Treasury/CashCustodySchemaTest.php tests/Feature/Treasury/CashCustodyConfigurationTest.php tests/Feature/Treasury/CashCustodyCensusCommandTest.php tests/Feature/Treasury/RepositoryTransferDocumentTest.php tests/Feature/Treasury/RepositoryTransferReversalTest.php tests/Feature/Treasury/RepositoryTransferFrozenPolicyTest.php tests/Feature/Treasury/RepositoryTransferEndpointTest.php tests/Feature/Treasury/CashCustodySecondOfEverythingTest.php' ./scripts/preflight.sh`; execute the separate focused PG integration commands above too. Backend-test SQLite regression: `php artisan test -c phpunit.xml --filter='RepositoryTransferServiceTest|RepositoryTransferEndpointTest'` from apps/api. Full suite is VPS/CI, following the manifest host-scope rule: `pnpm build`, `pnpm lint`, `pnpm test`, `pnpm typecheck`, `pnpm --filter @autoerp/web test:e2e`, and from apps/api `composer test`, `./vendor/bin/phpstan`, `./vendor/bin/pint --test`. Attach actual errors/skips and screenshots; a skipped PHPUnit run is not green.

Gate: treasury-reviewer + tenancy-authz-reviewer approve actual PG evidence, second-company registration and denial snapshots. Rollback: stop submissions; preserve all committed evidence; reverse business mistakes only via authorized compensation. NEW handback `docs/handoff/HANDBACK-WCASH-1-2026-09-06.md` records implementation SHA, red/green commands, screenshots, migration/census output and reviewer findings. No implementation or reviewer gate was run for this planning assignment.

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
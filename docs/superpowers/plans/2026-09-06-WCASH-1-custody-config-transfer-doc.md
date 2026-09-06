<!-- W-CASH-1 rev 12 (rev 5 by Codex CLI fix round 4; rev 6/7/8/9/10/11 orchestrator-applied gate r5/r6/r7/r8/r9 corrections; rev 12 integrates gate r11 Amendment A corrections) (gpt-5.6-sol, read-only) on 2026-09-06, saved verbatim by the orchestrator (owner away). Rev 4 = 59e8c6c82. Status: awaiting gate r12. -->
# Slice plan W-CASH-1 — per-location cash custody configuration and repository transfer document (rev 12)

Evidence baseline: reviewed local `dev` HEAD `9c26e06412d704018cb47119871a1c0cbbd09723`, resolved with `git rev-parse HEAD` on 2026-09-06. Historical production-source baseline was `f13b923a5c150ceee8acb8165e98197207c88eff`; r2 reviewed `5fe262c84527d90a6124e467978b282806630d50`. Existing-code anchors below were re-read at the current reviewed HEAD; NEW files/contracts are proposals, not shipped behavior. This round edits only this plan. No code, tests, migrations or Git state were changed.

## Round-11 change log (gate r11 fix round 6)

| Item | Change and closing plan line |
|---|---|
| B1 | Made `docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md` authoritative for all P0 command, class, DTO, enum, marker, migration, test, ticket and deployment identifiers; all five classifications, including Geometry, are retained. Closed at plan line 747. |
| Compatibility | Former repository-precision command class/service/aggregate DTO/check DTO/storage-floor DTO/CLI/marker/census test/prerequisite test/ratchet test/migration/follow-up ticket/deployment slice are renamed respectively to `MoneyPrecisionCensusCommand`, `MoneyPrecisionCensusService`, `MoneyPrecisionCensusData`, `MoneyPrecisionCheckData`, `MoneyColumnShapeData`, `treasury:census-money-precision`, `MONEY-PRECISION CENSUS`, `MoneyPrecisionCensusCommandTest`, `GlAndRepositoryBalanceScale4Test`, `MoneyStorageScale4RatchetTest`, `2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php`, `2026-09-07-money-columns-below-scale-4-followup.md`, and `precision-4`. Closed at plan line 236. |
| B2 | Reconciled every normative dependency, deployment variable, final checklist and dispatch section: dispatch P0-a, T1 and T2 now; hold P0-b until the benchmark note and owner cast ruling; dispatch T3–T6 only after accepted P0-b. Historical contradictions are explicitly labelled superseded. Closed at plan line 793. |
| M1 | P0-b accepts only exact pre-widen `(15,3,NO,0)` / `(15,3,YES,NULL)` or already-compliant `(15,4,…)`; scale two is a refusal fixture asserting `unexpected_column_shape`, no DDL and unchanged rows/schema. Closed at plan line 240. |
| m1 | Revision/status metadata advanced to rev 12 / awaiting gate r12, HEAD refreshed, and the five Round-9 internal anchors re-anchored to lines 509, 407, 415, 664 and 411/413. Closed at plan line 34. |
| A | Amendment A remains binding: storage widens `(15,3)→(15,4)`; the four Eloquent casts stay `decimal:3` provisionally under the 2026-09-07 owner ruling; cast change and operational-ceiling removal remain deferred pending `docs/superpowers/reviews/2026-09-07-benchmark-money-precision-4-decimals-casts.md` and the owner’s cast ruling. Closed at plan line 793. |

## Round-10 change log (orchestrator-applied amendment A)

| Item | Change |
|---|---|
| A.1 | Premise corrected: live scale is 3 (March 2026 migrations), widening is (15,3)→(15,4); accepted pre-widen tuples restated. |
| A.2 | Owner ruling 2026-09-07: casts stay `decimal:3` provisionally; cast change + ceiling removal deferred to the 4-decimal benchmark and ruling. |
| A.3 | P0 reuses the parallel session's brief (allowlist, deploy variables, lock profile, two tests) instead of re-deriving. |
| A.4 | Dispatch order: P0-a/T1/T2 now; P0-b after the ruling; T3+ after P0-b. |

## Round-9 change log (orchestrator-applied, gate r9 B1 + m1)

| Item | Change |
|---|---|
| B1.1 | Company-bound scale lookup is executable without a bound `CompanyContext` for both authority branches through the fully declared factory, tuple lookup, not-found exception and container binding at plan line 509. |
| B1.2 | The task-owned exception register now contains the exact T3 `RepositoryTransferPrecisionCeilingException` contract at plan line 407. |
| B1.3 | The registered renderer test now has an exact file, class, method, first failing assertion, command and lane at plan line 415. |
| B1.4 | The precision endpoint test asserts the exact translated 422 JSON and unchanged document/movement/repository/JE snapshots at plan line 664. |
| B1.5 | The precision translation is explicitly assigned to `treasury.transfer.precision_exceeds_ledger_scale` in all three backend `treasury.php` files and excluded from `messages.treasury.*` at plan lines 411 and 413. |

## Round-8 change log (orchestrator-applied, gate r8 B1)

| Item | Change |
|---|---|
| B1.1 | T3 `executeTransfer(...)` returns `DocumentedRepositoryTransferResult`; `RepositoryTransferResult` reserved for the atomic T5 rename. |
| B1.2 | `RepositoryTransferPrecisionCeilingException extends \DomainException` with the complete constructor `(public readonly string $amount, public readonly int $effectiveScale)`; in the T3 exception register. |
| B1.3 | Executable scale source for both authority branches: `CompanyScopedCurrencyScaleResolverFactory::forCompany(tenantId, companyId)` reusing the resolver's `$companyOverride` parameter, called with no currency argument so the country preset applies; first-statement policy call shown. |
| B1.4 | Red tests use the captured-exception pattern so typed fields and every unchanged snapshot are asserted for human and system callers. |
| B1.5 | One normative 422 envelope (flat `error` extras `amount`, `effective_scale`), renderer extras rule reconciled, status/envelope table case added, backend and frontend translation paths/keys named, renderer and endpoint tests assert the full envelope. |

## Round-7 change log (orchestrator-applied, gate r7 B1 + m1)

| Item | Change |
|---|---|
| B1 | The three-decimal operational ceiling is now a normative T3 contract (policy class, exception class, first-statement check in `executeTransfer` for both authority branches, two service-level red tests + one HTTP red test, verbatim removal condition) and a T5 contract (renderer union member, 422 + `TRANSFER_PRECISION_EXCEEDS_LEDGER_SCALE`, status-contract case, translations); the T5 validation rule now reads `min(country preset, OPERATIONAL_SCALE_CEILING)`. |
| m1 | Status metadata → awaiting gate r8; revision → rev 8. |

## Round-6 change log (orchestrator-applied, gate r6 B1 + m1)

| Item | Change |
|---|---|
| B1 | **Operational precision ceiling (temporary).** Until the movement ledger (`repository_movements.amount`, `balance_after`) is widened in a follow-up lane, `RepositoryTransferService` (and every typed caller of the transfer/document contract, including system-intent callers) REFUSES an amount with more than three fractional digits even when the company's country preset is four: validation error `TRANSFER_PRECISION_EXCEEDS_LEDGER_SCALE` (422 over HTTP; typed exception for service callers), evaluated BEFORE any document/movement/JE write. Red-first test (PG lane) `RepositoryTransferDocumentTest::test_scale_four_company_transfer_of_10005_is_refused_with_unchanged_snapshots(): void` — first failing assertion: `assertSame('TRANSFER_PRECISION_EXCEEDS_LEDGER_SCALE', $response->json('error.code'))`; then asserts zero new rows in `repository_transfer_documents`, `repository_movements`, `journal_entries` and unchanged `payment_repositories.balance` for both repositories. The ceiling constant lives in one place (`RepositoryTransferPrecisionPolicy::OPERATIONAL_SCALE_CEILING = 3`) and its removal is gated on the movement-ledger widening ticket (opened by the P0 census) plus its own PG round-trip test; the precision-contract doc notes the ceiling. |
| m1 | Revision metadata corrected to rev 7 (rev 5 was the CLI output; rev 6 applied gate r5 B1; rev 7 applies gate r6). |

## Round-5 change log (orchestrator-applied, gate r5 B1)

| Item | Change |
|---|---|
| B1 | Operational transfer/movement fixtures (T3 same/cross-GL test, P0 census idempotency movement, RepositoryTransferDocumentTest) now use a real country-valid value of at most three decimals (TND `1.005`) because `repository_movements.amount`/`balance_after` remain scale 3 in this slice; `1.0005` is retained ONLY for the raw round-trip proof of the four P0 target columns. Four-decimal operational transfers are DEFERRED to a follow-up widening of the movement ledger (ticket to be opened by the precision lane census). |

## Round-4 change log

This revision closes gate r4; it does not claim implementation or deployment approval. The r4 reviewer reported one blocker, one major and one minor; every other earlier finding remains CLOSED and its prior disposition is preserved below. Re-anchor again if production changes before dispatch.

| Gate item | Rev-5 disposition |
|---|---|
| B1 | **SUPERSEDED in part by Amendment A.** Applied binding owner follow-up A1: the four existing P0 columns and the new transfer-document amount use `decimal(15,4)` storage; operational precision remains the company country preset from `countries.currency_decimal_places`. Added `1.0005` round-trip proof, full scale-four tuple ratchet, the precision-contract update and a census-owned follow-up ticket listing every other money column below scale four. The historical instruction to add scale-four Eloquent casts is superseded; those casts remain `decimal:3` provisionally. |
| M1 | **SUPERSEDED in dispatch order by Amendment A.** Split P0 into ordered P0-a and P0-b deployment packages. P0-a contains only read-only census code and must be deployed and run once per inventoried tenant with captured output before P0-b. P0-b alone contains the non-additive widening migration, requires the host-side backup, topology-specific migration command, per-tenant verification and an explicit rollback point. Current binding order is: dispatch P0-a, T1 and T2 now; hold P0-b pending the benchmark and owner cast ruling; dispatch T3–T6 only after accepted P0-b. |
| m1 | Corrected the movement persistence citation: `balance_after` is at `TreasuryMovementService.php:590`; `source_type` and `source_id` are at `:592–593`. |

## Round-3 change log

This revision closes gate r3; it does not claim implementation or deployment approval. The r3 reviewer reported governed production sources unchanged at a5191dafdf7506da6b513eb5a49bff0b0d2e75df; this round re-read the affected production seams at the HEAD declared above. Re-anchor again if production changes before dispatch.

| Gate item | Rev-4 disposition |
|---|---|
| M1 | Corrected balance DEFAULT 0; P0 compares complete precision/scale/nullability/normalized-default tuples before, after and on direct rerun. |
| M2 | Added read-only pre-ALTER precision census, exact command, typed output, measurable comparisons, unknown historical loss and reviewer pair treasury-reviewer + stock-gl-interaction-reviewer. Ticket's no-later-widening assertion is rejected by the March migration cited in P0; census remains required. |
| M3 | Added explicit shared-connection migrate --path commands for all three files, topology/connection proof, markers, exit rules and physical SQL verification. Rolling no-op cannot pass. |
| M4 | P0 repeats the current movement port on the real company-B second-location drawer: wasIdempotentHit=true, same movement/ordinal/count/balance, company A unchanged. |
| M5 | T1 direct migration liveness providers cover partial tables, wrong parent keys/FKs/CHECKs/uniques/triggers with exact refusal and unchanged schema/data. |
| M6 | Builder now carries each opposite-leg reversal ID into existing RepositoryMovementRecorded (original v1); payload already has the field, so immutable event class remains unchanged. Event and Compliance audit-row tests added. |
| M7 | Push 3 preserves the existing scalar endpoint and result constructor; typed core stays disconnected/default-false. Push 5 atomically replaces the scalar path, shrinks its architecture exception and enables documented writes only after old processes drain. Post-use rollback is permanently fail-closed. |
| M8 | Added exact exception, warning, sink and HTTP renderer paths/signatures; explicit task ownership, status/envelope and alert assertions. Existing abbreviated DTO paths resolve under the exact namespace rule below. |
| m1 | Unknown-field tests assert errors.surprise.0 / actual forged key and translated messages.validation.unexpected_field. |
| m2 | Refreshed reviewed HEAD from repository metadata without git commands. |
| m3 | Restored literal `DRIFT(` and every-target verdict inspection. |

## Round-2 change log

Historical rev-3 dispositions below are retained as review history; round-3 M7 supersedes the fail-closed scalar adapter and moves baseline removal to atomic Push 5.

| Gate item | Rev-3 disposition |
|---|---|
| B1 | T1/T3 define a human/system actor union, nullable human FK on documents, stable named system authority/provenance, branch validation and PG first/retry/conflict tests. No system caller fabricates a user. |
| B2 | Added hard prerequisite **P0 precision widening**, its four-column schema/round-trip ratchet, dedicated non-additive repair push and backup/migration proof. **Source correction:** the original scale-2 CREATE migrations are superseded for all four columns by `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:27`, `:113`, `:178`. Thus scale-2 on a fully migrated database is not established; live drift remains unverified. P0 verifies and widens only the ruled scale-3 source shape, refusing scale-2 migration-history drift. |
| Program-level precision finding for Fable/orchestrator | Report P0 as fleet precision assurance/deployment-drift risk affecting existing POS receipt posting too: `apps/api/app/Modules/Treasury/Application/Projections/TreasuryReceiptBridge.php:1515` creates payment GL and `:1526` posts it. Any deployment still at scale 2 may have lost precision in existing flows, not only WCASH. Inventory live shapes before claiming incidence; scale 2 is not an accepted P0-b repair input and no historical rebooking or amount reconstruction occurs. This register and P0 handback are the orchestrator report; no external message sent in this round. |
| M1 | T3 retains the eight-scalar transfer signature as a deprecated fail-closed adapter and adds executeTransfer(intent). T5 migrates the controller, removes the adapter and renames the typed method atomically in the same task. |
| M2 | Common real registration/company/POS-location fixture contract applies to every task. T1/T2 share one acceptance boundary so T1 repeats the actual configuration writer; migration rerun is only a schema test. |
| M3 | T5 includes movement controller, generated movement DTO, hook, tab and detail page for durable authorized history links, both legs, reload, omission and scoped 404 tests. |
| M4 | Added StatementUploadWizard and StatementListPage consumer; exact POS triple-slash generated-global wiring and fully qualified ambient DTO namespace. |
| M5 | Added matrix B10, vocabulary architecture linkage, T3 baseline JSON shrink and exact protected-blob ratchet tests. |
| m1 | Exact trim → NFC → collapse Unicode whitespace, case-preserving notes normalization and comparison encoding, with equivalence/conflict cases. |
| m2 | All three FormRequests use withValidator/after allowlist rejection on raw input keys; named arbitrary-key 422/no-write tests. |
| m3 | T1 invalid enum SQL tests and EnumCheckParityTest gate named, including new initiator enums. |
| m4 | Current reviewed HEAD and historical source baseline distinguished; repository tenant/company predicate citation corrected to :121. |

## Round-1 change log

Historical r1 disposition: accepted findings carried forward, with remaining gaps closed in the round-2 log above. This is a revised plan, not a claim of a passed implementation gate.

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

Deliver two prerequisites: location custody defaults on Treasury → Repositories, and one immutable justifying document for each new back-office repository transfer. Six tasks maximum, plus the separately gated P0 precision prerequisite. Exclude shift-event booking, v2/v3 adapters, W7, variance enablement, drawer sessions, typed device cash reasons and historical alignment.

## Industry baseline — convention 10

Flow: cash custody setup and internal repository transfer. Odoo reference is specifically version 17, not a claim about all later releases. ERPNext and Dolibarr references are their unversioned documentation accessed 2026-09-06. NV means not verified, not absent. Sources: [Odoo 17 internal transfers](https://www.odoo.com/documentation/17.0/applications/finance/accounting/payments/internal_transfers.html) (search result available; full-page fetch timed out), [ERPNext Payment Entry](https://docs.frappe.io/erpnext/payment-entry), [Dolibarr Banks and Cash](https://wiki.dolibarr.org/index.php/Module_Banks_and_cash). Odoo's paired-liquidity behavior and Dolibarr account transfers are also supplied benchmark facts in the dispatch; do not infer their exact document schema or retry guarantees.

| ID | Guarantee | Odoo | ERPNext | Dolibarr or NV | AutoERP today path:line | Gap | Decision MATCH/DEFER/DIVERGE/ALREADY |
|---|---|---|---|---|---|---|---|
| B1 create | Internal movement has supporting evidence and balanced money effects | Paired liquidity entries | Internal Transfer Payment Entry | Account transfer; document shape NV | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:76` creates optional draft; `:89` writes legs; `:108` returns no document | Justifying document missing | MATCH — T1/T3; retain zero JE for same GL |
| B2 duplicate | Duplicate action cannot move funds twice | Operation UUID guarantee NV | Operation UUID guarantee NV | NV | `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:296` uses group-based leg keys | No company-operation document identity | DIVERGE — explicit stronger company-operation contract, T3 |
| B3 edit | Editing defaults cannot rewrite an executed transfer | Exact custody-default revision behavior NV | Exact revision behavior NV | NV | `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:165` validates mutable repository fields | No custody revision/evidence snapshot | DIVERGE — immutable configuration revisions and documents, T1/T2 |
| B4 cancel/reverse | Correction preserves the original evidence | Exact linked-document policy NV | Payment Entry supports cancellation; exact proposed shape NV | NV | `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:369` writes null reversal link for transfers | No linked transfer reversal | MATCH — compensating document and pair, T4 |
| B5 rerun | A retry returns an explicit existing outcome | Exact response NV | Exact response NV | NV | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:113` reports replay of both legs | Replay result lacks document and stable company-operation semantics | ALREADY for paired replay; MATCH document extension, T3 |
| B6 second company | Each company's configuration and operation identity are independent | Exact UUID scope NV | Internal transfer between company cash/bank accounts | NV | `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:121` scopes repositories; `apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentRepositoryController.php:80` still validates codes tenant-wide | New keys must include company; HTTP code validation disagrees | MATCH — T1/T2/T6 |
| B7 second location | Selected branch controls source custody and defaults | Branch-specific policy NV | Exact drawer policy NV | NV | `apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31` resolves allowed locations; `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:28` passes no location intent | Transfer adapter has no branch check | DIVERGE — W1 source-custody policy, T5 |
| B8 permission | Unauthorized source IDs are inaccessible without side effects | Exact scoped-404 policy NV | Exact scoped-404 policy NV | NV | `apps/api/app/Modules/Treasury/Presentation/routes.php:102` uses treasury.transfer; outer middleware at `:35` has no module gate | Permission exists; module/source checks missing here | MATCH permission defense; DIVERGE scoped-404 contract, T5 |
| B9 audit | Evidence identifies actor, legs and accounting effect | Accounting entries | Operational document and ledger effect | Account records; exact evidence NV | `apps/api/app/Modules/Treasury/Domain/RepositoryMovement.php:73` is append-only; `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:41` returns group/JE/legs | No immutable action record | MATCH — T1/T3/T4 |
| B10 document per action | A new transfer has durable justification and removes its known documentless compensation exception | Liquidity accounting evidence; exact code ratchet NV | Payment Entry justification | Document shape NV | `apps/api/tests/Architecture/baselines/document-per-action-baseline.json:34` names Treasury draft delete; `apps/api/tests/Architecture/DocumentPerActionBaselineRatchetTest.php:78` rejects stale exceptions | Service improvement must shrink its baseline in the same task | MATCH — T3 prepares the replacement; T5 atomically removes only the Treasury exception with the legacy delete path |

Vocabulary — convention 11: **Repository** exists (`docs/glossary.md:60`), canonical surface Treasury → Repositories. **Cash custody configuration** is NEW: `cash_custody_configurations`, Treasury, sole writer `CashCustodyConfigurationService`, a location setting mode on that existing surface; synonym “custody defaults.” **Repository transfer document** is NEW: `repository_transfer_documents`, Treasury, sole writer `RepositoryTransferService`, existing transfer modal and repository detail history; synonym “transfer justification.” Add both glossary rows in T1. A revision is history of the same configuration, not another catalogue. A reversal is another repository transfer document, not a separate concept/table. The new glossary row explicitly ties this evidence concept to the document-per-action invariant and to removal of the Treasury baseline exception at T5 activation; the architecture baseline is not a third business concept or surface.

Second-of-everything — convention 09: every T1–T6 acceptance below includes real second-company, real second pos_enabled location/provisioned drawer and actual mutation rerun coverage. T1/T2 intentionally share an atomic acceptance boundary so schema-only work cannot substitute migration rerun for a business mutation. No tenant-only new catalogue unique and no ratchet ceiling increase.

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

## P0 prerequisite — P0 precision widening

**Amendment A (rev 12 integration of the rev 11 owner ruling dated 2026-09-07):**

1. **Premise:** the four target columns are already `decimal(15,3)` on a fully migrated tenant because `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:27-30,113-116` widened them. P0-b widens `(15,3) → (15,4)` only. Accepted source tuples are exactly `(15,3,NO,0)` / `(15,3,YES,NULL)` or already-compliant `(15,4,…)`; every other tuple, including scale two, aborts before DDL.
2. **Casts:** Eloquent casts for `PaymentRepository` balance fields and `JournalLine` debit/credit **stay `decimal:3` provisionally** under the owner ruling dated 2026-09-07. The cast change and the operational-ceiling removal in T3 are DEFERRED until `docs/superpowers/reviews/2026-09-07-benchmark-money-precision-4-decimals-casts.md` lands and the owner rules. P0-b’s migration/doc handback records the provisional mismatch.
3. **Authoritative precision brief:** `docs/superpowers/plans/2026-09-07-precision-widening-gl-repository-scale-4.md:50-68` governs the P0 command, service, DTOs, classifications, output marker, migration and tests; `:103-117` governs deployment identifiers and variables. The five classifications are `Money`, `Percent`, `Quantity`, `Geometry`, and `Other`; `pos_tables.*` is Geometry.
4. **Dispatch effect:** dispatch P0-a, T1 and T2 now. Hold P0-b until the four-decimal benchmark note and owner cast ruling exist. Dispatch T3–T6 only after P0-b has accepted reviewer, migration, raw round-trip and PostgreSQL schema evidence.

**Hard prerequisite for T3–T6 and money acceptance.** P0 is a separately accepted program-level engineering task outside the six WCASH tasks. P0-a census acceptance evidence must exist before P0-b promotion. T1 and T2 may be implemented and reviewed in parallel with P0-a, but their WCASH deployment remains after accepted P0-b. P0-b acceptance SHA and PG evidence must be recorded before T3–T6 start or WCASH money work is declared green.

Binding owner follow-up A1 is at `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:82`: storage capacity is widened to a maximum scale of four, while business validation, computation, rounding and display precision remain the company country preset in `countries.currency_decimal_places`. The company-bound resolver reads that field at `apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:54-64` at reviewed HEAD `9c26e06412d704018cb47119871a1c0cbbd09723`; no service may treat storage scale four as operational precision four.

| Physical column | Original CREATE definition | Existing widening at current HEAD | Required verified shape |
|---|---|---|---|
| payment_repositories.balance | `apps/api/database/migrations/tenant/2025_11_30_120000_create_treasury_tables.php:29`, decimal(15,2), NOT NULL DEFAULT 0 | `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:114` | numeric(15,4) NOT NULL DEFAULT 0 |
| payment_repositories.last_reconciled_balance | Same CREATE `:31`, decimal(15,2), nullable, no non-null default | Same widening `:115` | numeric(15,4) NULL, no default |
| journal_lines.debit | `apps/api/database/migrations/tenant/2025_11_30_100000_create_journal_entries_table.php:51`, decimal(15,2), NOT NULL DEFAULT 0 | March widening `:28` | numeric(15,4), NOT NULL DEFAULT 0 |
| journal_lines.credit | Same CREATE `:52`, decimal(15,2), NOT NULL DEFAULT 0 | March widening `:29` | numeric(15,4), NOT NULL DEFAULT 0 |

Do not infer live scale from the original CREATE: the March migration executes ALTER at `apps/api/database/migrations/tenant/2026_03_11_200000_widen_monetary_columns_to_scale_3.php:178-193` and skips SQLite at `:182`. Models currently cast repository fields scale 3 (`apps/api/app/Modules/Treasury/Domain/PaymentRepository.php:214-215`) and GL debit/credit scale 3 (`apps/api/app/Modules/Accounting/Domain/JournalLine.php:54-55`); those casts remain unchanged provisionally. P0-b updates `docs/architecture/precision-contract.md:9,17` and Rule 19 at `CLAUDE.md:72-75`: the canonical money **storage floor** becomes `decimal(N,4)`, while ingress, computation, rounding, display and provisional Eloquent serialization continue at the country-resolved scale, currently capped at three by seeded presets and the transfer ceiling. P0 closes verified schema drift only; it does not reconstruct missing historic fractions.

NEW P0-b migration: `apps/api/database/migrations/tenant/2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php`, anonymous migration with `up(): void` and `down(): void`. It is a non-additive PostgreSQL schema alteration and never ships in P0-a. Before any DDL, inspect all four complete tuples. Accept only exact `(15,3,NO,0)`, `(15,3,YES,NULL)` or matching `(15,4,…)`. Any other precision, scale, nullability or default throws `RuntimeException('P0-PRECISION: status=failed reason=unexpected_column_shape column=<table.column> found=<tuple>')` before any ALTER. All four at scale four emit `P0-PRECISION: status=already_compliant` and perform no DDL. Otherwise issue the two fixed ALTER statements from the authoritative brief, preserve defaults/nullability/triggers, assert all post-tuples and emit `P0-PRECISION: status=widened columns=4`. Driver other than pgsql returns. `down()` is a documented forward-only no-op. The docblock cites the ruling, premise correction and lock profile: numeric scale ALTER rewrites the tables under `ACCESS EXCLUSIVE`, requiring a bounded outage with money writes stopped.

NEW PG test file `apps/api/tests/Feature/Schema/GlAndRepositoryBalanceScale4Test.php` methods: `test_target_columns_have_full_tuple_15_4(): void`; `test_journal_line_round_trips_1_0005_raw(): void`; `test_repository_balance_round_trips_1_0005_raw_via_port_guc(): void`; `test_legacy_scale_3_shape_is_widened_and_reported(): void`; `test_rerun_on_compliant_schema_is_already_compliant_and_changes_nothing(): void`; `test_unexpected_shape_refuses_before_ddl(): void`; and `test_scale_two_shape_refuses_before_ddl_and_changes_nothing(): void`. The scale-two test deliberately changes one target to `(15,2,…)`, invokes `up()`, first asserts `reason=unexpected_column_shape`, then asserts zero DDL, byte-identical rows, identical tuples for all four columns and an unchanged migrations ledger. No successful fixture uses scale two. Raw repository writes use `SET LOCAL app.treasury_movement_port = 'on'`; the sibling no-GUC assertion proves the trigger remains live.

NEW architecture ratchet `apps/api/tests/Architecture/MoneyStorageScale4RatchetTest.php::money_storage_target_columns_have_scale_4_full_tuple(): void`, with NEW test support `apps/api/tests/Architecture/Support/MoneyStorageScaleChecker.php`. It asserts precision, scale, nullability and normalized default for all four columns; liveness providers damage each property separately. Exact P0-b commands from `apps/api`, lane backend-test-pgsql / precision-4: `php artisan test -c phpunit-pgsql.xml tests/Feature/Schema/GlAndRepositoryBalanceScale4Test.php tests/Architecture/MoneyStorageScale4RatchetTest.php`. T3’s same/cross-GL fixture uses a TND company whose country preset is three and transfers `1.005`, comparing raw document, movements, balance_after, repository balances and debit/credit. A TND fixture remains limited to three operational decimals even though the four target columns store four.

### P0-a / P0-b deployment packages

Both packages are governed by `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`. P0-a is the census-only commit/deployment within lane `precision-4`; P0-b is the separately promoted non-additive migration/docs/test commit from the same authoritative lane. They never share a push.

| Manifest variable | P0-a — precision census tooling | P0-b — non-additive scale-four widening |
|---|---|---|
| `<slice>` | `precision-4` | `precision-4` |
| Exact contents | Read-only `treasury:census-money-precision` command, service, enum, DTOs and `MoneyPrecisionCensusCommandTest` only. Additive application code; no migration, cast change, write path or precision-contract mutation. | `2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php`, scale-four schema tests/ratchet, precision-contract/Rule-19 updates, follow-up ticket and handback. The four Eloquent casts remain `decimal:3`. |
| Migration marker | None. Boot may run the normal entrypoint, but P0-a contains no P0 migration. Any pending scale-four migration in its image is a packaging failure. Census marker is `MONEY-PRECISION CENSUS`. | `P0-PRECISION: status=widened columns=4` or `P0-PRECISION: status=already_compliant`; refusal emits `P0-PRECISION: status=failed reason=unexpected_column_shape ...` and throws. |
| Exact command | Run `php artisan treasury:census-money-precision --tenant=<verified-tenant-uuid> --json` separately for every inventoried tenant and retain `php artisan treasury:census-money-precision --json` for the fleet aggregate. The command runs standalone, never under `tenants:run`. | DB-per-tenant: boot and explicit `php artisan tenants:migrate-rolling --force`, followed by `php artisan tenants:migrate-rolling --force --tenant=<verified-tenant-uuid>` for every tenant. Compatibility mode uses the exact shared-connection migration path below. |
| Schema/data census | Capture command JSON, exit status, `current_database()`, `current_schema()`, all four source tuples, measurable drift and all numeric columns below scale four with their five-way classification. Exit 1 requires reviewer classification; exit 2 blocks. | Capture all four post-tuples, migration marker, post-census JSON and the unchanged classified follow-up list. Exactly four ruled columns are widened. |
| Flags | None. | None. |
| Web/API behavior | No HTTP/API/web behavior; one direct read-only command. | No HTTP/API/web behavior. Storage capacity changes; Eloquent casts and country-resolved operational precision do not. |
| Device behavior/build | None. | None. |
| Queues | None. | None. |
| Collapsed pushes | Authoritative Push 1: census in its own commit/push. Never collapse with P0-b or WCASH. | Separate non-additive Push 2 exception after accepted P0-a and after the benchmark/cast ruling. Manifest additive Push 2 does not absorb it; it precedes every WCASH deployment push. |
| Environment changes | None. U-1/U-2/U-5 still resolve topology, connection and backup targets. | None. U-1/U-2/U-5 must be evidenced before backup/promotion. |
| Host-side backup | None; read-only code and no schema/backfill. | Required outside the container: one non-zero restore-readable `pg_dump -Fc` per physical target database. |
| Rollback point | Revert tooling; preserve evidence. | Transaction rollback before commit. After commit retain scale-four schema and provisional scale-three casts, never narrow, stop money writes and correct forward. |

Dispatch P0-a, T1 and T2 now. P0-a must finish its per-tenant run and receive treasury-reviewer + stock-gl-interaction-reviewer classification before P0-b promotion. P0-b remains held until `docs/superpowers/reviews/2026-09-07-benchmark-money-precision-4-decimals-casts.md` and the owner cast ruling exist. Once released, stop money writes for the bounded ALTER window, capture backups and require no-op evidence for already-compliant tenants. P0-b acceptance releases T3–T6. P0 handback reports the fleet finding to Fable and attaches both reviews without labelling tenants defective from CREATE text alone.

**P0 complete shape contract:** expected post-tuples are balance `(15,4,NO,0)`, last_reconciled_balance `(15,4,YES,NULL)`, debit and credit `(15,4,NO,0)`. Accepted pre-tuples are only balance/debit/credit `(15,3,NO,0)` and last_reconciled_balance `(15,3,YES,NULL)`; matching `(15,4,…)` is already compliant. Normalize only PostgreSQL zero spellings, optional parentheses and numeric casts to `0`; SQL NULL stays NULL. Any other tuple—including `(15,2,…)`—refuses with `reason=unexpected_column_shape` before DDL. Tests assert complete tuples before, after and on rerun. The scale-two red fixture asserts no DDL and unchanged rows/schema. Liveness providers separately damage precision, scale, nullability and default. Migration never drops defaults.

**P0 pre-ALTER census:** NEW `apps/api/app/Modules/Treasury/Presentation/Console/MoneyPrecisionCensusCommand.php`, namespace `App\Modules\Treasury\Presentation\Console`, extends `App\Console\TenantScopedCommand`; constructor `__construct(CompanyContext $companyContext, MoneyPrecisionCensusService $census)` calls `parent::__construct($companyContext)`; `protected function executeCommand(): int`. Signature: `treasury:census-money-precision {--tenant=} {--json}`. NEW `apps/api/app/Modules/Treasury/Application/Services/MoneyPrecisionCensusService.php`, namespace `App\Modules\Treasury\Application\Services`; `inspect(string $tenantId): MoneyPrecisionCensusData`, using one read-only REPEATABLE READ transaction on the bound tenant connection. Register the command in `apps/api/app/Modules/Treasury/Providers/TreasuryServiceProvider.php:237`. No insert, update, delete, DDL or log-table write is permitted.

NEW DTOs under namespace `App\Modules\Treasury\Application\DTOs`: `MoneyPrecisionCensusData.php` with `__construct(string $tenant_id, string $database_name, bool $complete, int $measurable_drift_count, array $checks, array $columns_below_scale_4)` where checks is `list<MoneyPrecisionCheckData>` and columns is `list<MoneyColumnShapeData>`; `MoneyPrecisionCheckData.php` with `__construct(string $check, int $examined_count, int $drift_count, array $sample_ids)`; `MoneyColumnShapeData.php` with `__construct(string $table, string $column, int $precision, int $scale, bool $nullable, ?string $default, string $classification)`. NEW backed enum `apps/api/app/Modules/Treasury/Domain/Enums/MoneyColumnClassification.php`, namespace `App\Modules\Treasury\Domain\Enums`, has exactly `Money`, `Percent`, `Quantity`, `Geometry`, and `Other`.

Fixed census checks are `target_shape`, `fourth_decimal_present`, `repository_last_movement`, `journal_entry_balance`, and `columns_below_scale_4`. `target_shape` accepts only the exact pre/post tuples above. `fourth_decimal_present` finds a nonzero fourth digit. `repository_last_movement` compares repository balance with the latest movement by ordinal. `journal_entry_balance` compares raw debit and credit sums, including drafts with `draft:` sample prefixes. `columns_below_scale_4` classifies every numeric column below scale four: Percent for the authoritative suffix/field allowlist, Geometry for `pos_tables.*`, Quantity for the authoritative weight/hours/time allowlist, Other only where explicitly curated, and Money otherwise. Only Money feeds the follow-up ticket.

Output is one grep-stable line per tenant: `MONEY-PRECISION CENSUS tenant=<uuid> db=<name> status=clean|drift|incomplete measurable_drift=<n> money_columns_below_scale_4=<n>`, followed by a table or `--json`. Exit 0 means clean, 1 drift and 2 incomplete. Run standalone: `php artisan treasury:census-money-precision --json` and one `--tenant=<uuid> --json` command per inventoried tenant. Capture stdout, exit code and connection identities before ALTER. Exit 1 requires both reviewers to classify discrepancies; exit 2 always blocks. Below-floor findings are capacity inventory, not proof of historical loss.

Read-only comparisons use raw numeric SQL/text and decimal-string arithmetic. Missing independent evidence is not called clean historical data. Current port stores `balance_after` at `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:590` and source identity at `:592-593` at reviewed HEAD `9c26e06412d704018cb47119871a1c0cbbd09723`; ORM padding is not evidence of loss. NEW follow-up ticket `docs/superpowers/tickets/2026-09-07-money-columns-below-scale-4-followup.md` contains the fresh census Money list with table, column, tuple and module owner, grouped by module; `repository_movements.amount`, `repository_movements.balance_after` and `accounts.balance` are highest priority. Percent, Quantity, Geometry and Other remain visible but do not enter the money ticket.

NEW `apps/api/tests/Feature/Treasury/MoneyPrecisionCensusCommandTest.php`, PG lane, methods: `test_reports_clean_on_fresh_tenant(): void`; `test_detects_repository_balance_vs_last_movement_drift(): void`; `test_detects_unbalanced_journal_entry(): void`; `test_lists_money_columns_below_scale_4_with_classification(): void`; `test_json_output_matches_dto(): void`; `test_two_tenants_are_scoped_and_rerun_is_read_only(): void`; and `test_incomplete_scan_exits_two(): void`. Exact command: `php artisan test -c phpunit-pgsql.xml --filter=MoneyPrecisionCensusCommandTest`. The classification test asserts all five enum cases and `pos_tables.* = Geometry`; the read-only test asserts byte-equal output and unchanged table snapshots.

**P0 convention 09:** move the common real-provisioning helper into the precision-4 test set so it needs no T1 schema. Extend `GlAndRepositoryBalanceScale4Test` with `test_second_company_second_location_movement_rerun_is_idempotent(): void`, using registration A, POST company B and a real second pos-enabled location/drawer. After P0-b call `TreasuryMovementServiceInterface::record(MovementIntent $intent): MovementResult` (`apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:49`) twice with amount `1.005`, fixed source UUID/leg and explicit tenant/company. Assert `wasIdempotentHit === true` (`apps/api/app/Modules/Treasury/Application/DTOs/MovementResult.php:16`), identical IDs/balanceAfter/ordinal, one raw balance delta, one movement and unchanged company-A/first-location snapshots.

**Topology execution supplement for P0-b and WCASH Push 2:** rolling returns a successful no-op in compatibility mode (`apps/api/app/Modules/Tenant/Application/Commands/RollingTenantMigrationCommand.php:57`); tenant migrations are auto-loaded only in testing (`apps/api/app/Providers/AppServiceProvider.php:283`). Before execution, record topology, Laravel connection name and `SELECT current_database(), current_schema()`. In DB-per-tenant mode P0-b and WCASH Push 2 use `php artisan tenants:migrate-rolling --force`, require every tenant visited with no failures/skips, then rerun `--tenant=<verified-tenant-uuid>` for every inventoried tenant. Enumerate every pending migration before rollout.

In shared-database mode set `WCASH_SHARED_CONNECTION` to the verified configured connection name, then from `apps/api` execute each file only in its designated push:

```sh
php artisan migrate --force --database="$WCASH_SHARED_CONNECTION" --path=database/migrations/tenant/2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php
php artisan migrate --force --database="$WCASH_SHARED_CONNECTION" --path=database/migrations/tenant/2026_09_06_210000_create_cash_custody_configurations.php
php artisan migrate --force --database="$WCASH_SHARED_CONNECTION" --path=database/migrations/tenant/2026_09_06_210100_create_repository_transfer_documents.php
```

The first command is P0-b only. The final two are WCASH Push 2 after accepted P0-b. Require exit 0 and filename/DONE or Nothing-to-migrate output. P0-b emits `P0-PRECISION: status=widened columns=4|already_compliant`; WCASH emits `WCASH-1-SCHEMA: migration=<basename> status=created|already_compliant`. A ledger-skipped migration still requires physical postchecks. Emit `WCASH-1-MIGRATION: topology=shared connection=<verified-name> database=<actual-db> migration=<basename> status=verified` only after exit and postchecks succeed.

Post-command SQL on the SAME verified connection, also per tenant after rolling:

```sql
SELECT current_database(), current_schema();
SELECT table_name,column_name,data_type,numeric_precision,numeric_scale,is_nullable,column_default
FROM information_schema.columns WHERE table_schema=current_schema()
AND ((table_name='payment_repositories' AND column_name IN ('balance','last_reconciled_balance'))
 OR (table_name='journal_lines' AND column_name IN ('debit','credit')))
ORDER BY table_name,column_name;
SELECT table_name,column_name,data_type,udt_name,is_nullable,column_default,numeric_precision,numeric_scale
FROM information_schema.columns WHERE table_schema=current_schema()
AND table_name IN ('cash_custody_configurations','repository_transfer_documents')
ORDER BY table_name,ordinal_position;
SELECT table_name,constraint_name,constraint_type FROM information_schema.table_constraints
WHERE table_schema=current_schema() AND table_name IN ('cash_custody_configurations','repository_transfer_documents')
ORDER BY table_name,constraint_name;
SELECT c.relname,t.tgname,pg_get_triggerdef(t.oid) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid
JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema() AND NOT t.tgisinternal
AND c.relname IN ('cash_custody_configurations','repository_transfer_documents','repository_movements');
SELECT c.relname,k.conname,pg_get_constraintdef(k.oid) FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid
JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname=current_schema()
AND c.relname IN ('companies','users','locations','payment_repositories','repository_movements','journal_entries','pos_terminals','cash_custody_configurations','repository_transfer_documents');
```

P0-b requires exactly four complete scale-four tuples while retaining the four scale-three Eloquent casts. WCASH Push 2 additionally compares every T1 column, FK action/ordering, CHECK, unique, trigger and function definition. Include `pg_get_functiondef`; names alone do not prove correctness. No synthetic live money insertion: raw `1.0005` proof belongs to isolated PG tests. NEW handback `docs/superpowers/reviews/2026-09-07-precision-4-handback-for-wcash-1.md` lists delivered files, markers, the `(15,4)`/`1.0005` correction, provisional casts and merge SHA.

## Common real-provisioning fixture and task boundary

Every P0/T1–T6 test named “second company/location” uses NEW `apps/api/tests/Support/CreatesWcashCompanyAndLocations.php` shared fixture method `createWcashCompaniesAndSecondPosLocation(): WcashProvisionedFixture` with NEW `apps/api/tests/Support/WcashProvisionedFixture.php`, constructor `__construct(string $tenantId, string $companyAId, string $companyBId, string $userId, string $firstLocationId, string $secondLocationId, string $provisionedDrawerId)`: register tenant/company A through `/api/v1/auth/register` (verified test `apps/api/tests/Feature/Tenant/TenantInitializationTest.php:158`); create B via authenticated POST `/api/v1/companies` (verified `apps/api/tests/Feature/Treasury/CompanyPaymentRepositoryProvisioningTest.php:95`), then switch the real company context/header. Create a SECOND shop through POST `/api/v1/locations` (`apps/api/app/Modules/Inventory/Presentation/routes.php:41`, module Inventory at :31, inventory.adjust at :42) with unique code/name, type shop, pos_enabled=true and country-required valid test tax fields. The production controller at `apps/api/app/Modules/Company/Presentation/Controllers/LocationController.php:181` persists pos_enabled at `:211` and calls drawer provisioning at `:216`/`:246`; `CreateLocationRequest.php:29` and `:70` require valid payload/tax rules. Assert the returned location is pos_enabled and the automatically provisioned active cash_register has company_id=B and location_id=that second location. No raw insert or Location/Company factory substitutes for these creation paths. Seed valid country chart through registration so drawer provisioning cannot silently skip. Fixture returns identifiers, not hand-built replacement repositories.

**T1/T2 combined acceptance:** dispatch both tasks now. No separate T1 acceptance past T2 until `CashCustodyConfigurationService::save` has run twice against the real selected second location, asserting Unchanged, same configuration ID/revision and no duplicate. T1 schema red/green is provisional; migration rerun is additional schema evidence, never the convention-09 rerun. T1/T2 deployment waits for accepted P0-b. T3–T6 depend on accepted P0-b; T5 additionally depends on accepted W1.

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

Production files: extend `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:33` with the compile-safe compatibility transition below; extend `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferResult.php:7`; preserve movement port `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:225` and GL factory `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:1497`.

NEW `Application/DTOs/RepositoryTransferIntent.php` constructor: `__construct(string $tenantId, string $companyId, string $operationUuid, string $fromRepositoryId, string $toRepositoryId, string $amount, string $currency, ?string $sourceLocationId, ?string $configurationId, ?int $configurationRevision, ?string $notes, ?CarbonImmutable $occurredAt, HumanTransferAuthorityData|SystemTransferAuthorityData $authority, bool $allowWhileFrozen = false)`. Branch validation is T1's union; human actor ID is authority.user_id, system has null created_by. T3 adds `executeTransfer(RepositoryTransferIntent $intent): DocumentedRepositoryTransferResult` as the typed core while preserving the old eight-scalar `transfer(...)` signature until T5 completes.

**Compile-safe, inert packaging transition (T3 prepares; T5 activates):** during Push 3 retain the existing eight-scalar transfer method BODY, controller request contract and legacy result constructor unchanged (`apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:33`, `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryTransferController.php:28`, `apps/api/app/Modules/Treasury/Application/DTOs/RepositoryTransferResult.php:7`). Mark scalar method deprecated; do not add a 503 flag check to it at this stage. Add typed executeTransfer using NEW `apps/api/app/Modules/Treasury/Application/DTOs/DocumentedRepositoryTransferResult.php` with the full result constructor below. Its existing five-field sibling stays intact until activation. It has no route, queue or production caller in Push 3, remains default-false; enabling documented writes early is a release failure. Existing endpoint tests stay unchanged. T3 core tests call executeTransfer with explicit authority, not the scalar method. Keep legacy draft-delete architecture baseline until removal; its staged shrink belongs to T5 activation packaging.

Push 5 first pauses/drains transfer requests and all old API/worker processes; verify zero repository_transfer_documents in every target before cutover. Atomically deploy the T5 controller/requests/HTTP adapter, remove scalar method/body, rename executeTransfer to transfer, switch its return type to RepositoryTransferResult with the new constructor, remove temporary DocumentedRepositoryTransferResult and update every typed caller/test in that same release. Remove the draft-delete baseline entry in that release too. Deploy compatible web before enabling writes. Verify all running processes use the new release, run false-flag 503/no-write smoke, then enable and reopen traffic. Never decide fallback by querying document count per request: removal is permanent. After the first document, flag=false only refuses writes/replays, retains reads and never restores old code. Push-3 rollback can remove disconnected new code while no documents exist; after activation rollback is forward-only. Task-order source changes must be split into these reviewed packaging artifacts, not deploy all T3–T5 commits at Push 3.

Typed core result constructor (DocumentedRepositoryTransferResult in Push 3, RepositoryTransferResult after atomic Push 5) becomes `__construct(string $transferGroupId, ?string $journalEntryId, MovementResult $out, MovementResult $in, bool $idempotentReplay, RepositoryTransferDocumentData $document, RepositoryTransferOutcome $outcome)`. Preserve existing output aliases during the web rollout.

Identity is `(tenant, company, operation UUID, first-submission human OR system authority)`. The physical unique index is `(company_id,operation_uuid)`, NOT an actor-inclusive unique: a different actor must conflict with the original operation rather than create another transfer. Composite ownership FKs establish the tenant dimension. Semantic comparison fields are initiator_kind plus human user_id (stored created_by) OR the full stable system authority/principal/source/terminal/source-location tuple, from_repository_id, to_repository_id, normalized decimal amount, currency, and reason (the existing free-text notes field, normalized by the exact rule below). Reversal also compares originalDocumentId and explanation. This slice adds no typed cash-reason enum.

**Exact notes/reason normalization:** NEW `Application/Services/RepositoryTransferNotes.php::canonicalize(?string $notes): ?string`. Omitted maps to null; validate UTF-8 first. Trim leading/trailing Unicode White_Space (`[\p{Z}\x{0009}-\x{000D}\x{0085}]`); normalize NFC using `Normalizer::normalize(..., Normalizer::FORM_C)`; collapse each run of that same whitespace class to one U+0020; preserve case and every non-whitespace meaningful character; empty result maps to null. No case-folding, accent removal, punctuation stripping or zero-width character stripping. ext-intl is required (`apps/api/composer.json:9`); normalization failure is validation error, not raw-string fallback. Store canonical notes on first submission. Compare normalized strings byte-for-byte in UTF-8; fixed ordered comparison tuple is serialized with JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, decimal amount normalized as string and no floats; compare actual normalized fields, not hash alone. Reversal explanation uses the same normalization but rejects resulting null.

NEW `Application/Services/RepositoryTransferOperationLock.php`: `acquire(string $tenantId, string $companyId, string $operationUuid): void`, requires an outer transaction and uses a dedicated namespaced PG advisory key. Add `acquireOriginal(string $tenantId, string $companyId, string $originalDocumentId): void` using a separate reversal-original namespace; reversals acquire it before acquire(operation). Both are shared by HTTP adapter and service. NEW public `GeneralLedgerService::lockRepositoryTransferContext(string $tenantId, string $companyId): void` uses the existing private tenant-numbering helper (`apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php:5671`) then the existing company advisory lock; it neither creates nor posts. Never duplicate the numbering-key literal outside that owner.

Replay order is mandatory: authenticated tenant/company + module/basic action permission → outer transaction and operation lock → tenant-numbering/company lock → resolve existing document in tenant/company → HTTP adapter authorizes its historical source location AND current source repository custody → only then compare actor/semantic fields. Inaccessible stored source returns scoped 404 with NO document ID, even when the submitted source is authorized. Once stored source is accessible, another actor or changed semantic content returns 409 with that existing document ID and code repository_transfer_conflict. An actor whose current authority was revoked cannot reuse first-submission authority; return scoped 404 or action-permission 403, never successful replay.

An identical same-actor authorized retry returns the original document/legs/JE with AlreadyRecorded and idempotentReplay=true. Server-derived source location/configuration ID/revision and server timestamp are copied from original evidence; never recompute them into a client fingerprint. Identical retry remains AlreadyRecorded after configuration revision changes. Client supplies currency explicitly; the server validates it on first submission and compares against original on replay. HTTP does not accept occurred_at/configuration/scope/actor fields. Internal explicit occurredAt is copied from the original on replay and is not a mutable retry token. Conflict reports neither a new row nor changed balances.

NEW `Presentation/Services/RepositoryTransferHttpAdapter.php` owns HTTP-only authorization within the transaction: `transfer(TransferRepositoryRequest $request): RepositoryTransferResult`, `reverse(ReverseRepositoryTransferRequest $request, string $documentId): RepositoryTransferResult`, `show(Request $request, string $documentId): RepositoryTransferDocumentData`. Its constructor is `__construct(private readonly LocationScopeResolver $locationScopeResolver, private readonly CompanyContext $companyContext, private readonly RepositoryTransferOperationLock $operationLock, private readonly GeneralLedgerService $generalLedger, private readonly RepositoryTransferService $transferService)`; no HTTP resolver enters either application service. The service independently re-acquires the same locks, validates tenant/company/actor/semantic identity, and consumes explicit authorized scope on the intent. Internal callers supply the T1 HumanTransferAuthorityData|SystemTransferAuthorityData union, never a client-selected bypass. The service checks explicit authority branch and persisted tenant/company/source/terminal custody inside locks. Human replay follows HTTP stored-source authorization then semantic comparison; system replay validates current persisted provenance/custody without any HTTP resolver, then compares its stable system tuple. Record the original union evidence without rewriting it on retry. Lower-level TransferIntent.createdBy and GL post actor remain nullable for systems; no `User::find` call with a forged system ID. T1 union constraints govern snapshots; W1 owns human revocation semantics.

**T3 precision ceiling contract (normative; temporary until the movement ledger is widened).** NEW `apps/api/app/Modules/Treasury/Domain/Policies/RepositoryTransferPrecisionPolicy.php`, namespace `App\Modules\Treasury\Domain\Policies`: `final class RepositoryTransferPrecisionPolicy { public const OPERATIONAL_SCALE_CEILING = 3; public static function effectiveScale(int $countryScale): int; /* min($countryScale, self::OPERATIONAL_SCALE_CEILING) */ public static function assertWithinCeiling(string $amount, int $countryScale): void; /* throws RepositoryTransferPrecisionCeilingException when fractional digits > effectiveScale */ }`. NEW `apps/api/app/Modules/Treasury/Domain/Exceptions/RepositoryTransferPrecisionCeilingException.php`, namespace `App\Modules\Treasury\Domain\Exceptions`: `final class RepositoryTransferPrecisionCeilingException extends \DomainException { public const CODE = 'TRANSFER_PRECISION_EXCEEDS_LEDGER_SCALE'; public function __construct(public readonly string $amount, public readonly int $effectiveScale) { parent::__construct(sprintf('Transfer amount %s exceeds the operational ledger scale %d', $amount, $effectiveScale)); } }` (same base as `InsufficientRepositoryBalanceException`, `apps/api/app/Modules/Treasury/Domain/Exceptions/InsufficientRepositoryBalanceException.php:26`, which extends PHP `DomainException` directly); registered in the T3 exception register table. The HEAD seam census found no company repository/finder contract under `apps/api/app/Modules/Company` or `apps/api/app/Shared/Contracts`; the existing model is `App\Modules\Company\Domain\Company` at `apps/api/app/Modules/Company/Domain/Company.php:133`, its table is declared at `:210`, and `tenant_id` is an owned attribute at `:218`, all at reviewed HEAD `9c26e06412d704018cb47119871a1c0cbbd09723`. Therefore NEW `apps/api/app/Shared/Exceptions/CompanyScopedCurrencyScaleCompanyNotFoundException.php`, namespace `App\Shared\Exceptions`: `final class CompanyScopedCurrencyScaleCompanyNotFoundException extends \RuntimeException { public function __construct(public readonly string $tenantId, public readonly string $companyId) { parent::__construct(sprintf('Company %s was not found in tenant %s', $companyId, $tenantId)); } }`. NEW `apps/api/app/Shared/Infrastructure/CompanyScopedCurrencyScaleResolverFactory.php`, namespace `App\Shared\Infrastructure`, imports `App\Modules\Company\Domain\Company`, `App\Modules\Company\Services\CompanyContext`, `App\Shared\Contracts\CurrencyScaleResolverInterface`, `App\Shared\Exceptions\CompanyScopedCurrencyScaleCompanyNotFoundException`, and `Closure`: `final class CompanyScopedCurrencyScaleResolverFactory { public function __construct(private readonly CompanyContext $companyContext, private readonly Closure $countryFinder) {} public function forCompany(string $tenantId, string $companyId): CurrencyScaleResolverInterface { $company = Company::query()->where('tenant_id', $tenantId)->whereKey($companyId)->first(); if ($company === null) { throw new CompanyScopedCurrencyScaleCompanyNotFoundException($tenantId, $companyId); } return new CurrencyScaleResolver($this->companyContext, $this->countryFinder, $company); } }`. The lookup is explicitly by `(tenant_id, company_id)` and never by company ID alone. Add `use App\Shared\Infrastructure\CompanyScopedCurrencyScaleResolverFactory;` to `apps/api/app/Providers/AppServiceProvider.php` and bind beside the existing resolver binding at `apps/api/app/Providers/AppServiceProvider.php:101-107` with the same country-finder closure: `$this->app->singleton(CompanyScopedCurrencyScaleResolverFactory::class, function ($app): CompanyScopedCurrencyScaleResolverFactory { return new CompanyScopedCurrencyScaleResolverFactory($app->make(CompanyContext::class), fn (string $countryCode): ?Country => Country::find($countryCode)); });`. Constructor-inject the factory into `RepositoryTransferService`; it is identical for `HumanTransferAuthorityData` and `SystemTransferAuthorityData` because both intents carry `tenantId` and `companyId`. The factory passes a non-null company override to the existing `CurrencyScaleResolver::__construct(CompanyContext $companyContext, Closure $countryFinder, ?Company $companyOverride = null)` at `apps/api/app/Shared/Infrastructure/CurrencyScaleResolver.php:30-34`; consequently the resolver selects `$companyOverride` at `:43` and does not read a bound `CompanyContext`. `RepositoryTransferService::executeTransfer(RepositoryTransferIntent $intent): DocumentedRepositoryTransferResult` resolves `$countryScale = $this->companyScaleResolverFactory->forCompany($intent->tenantId, $intent->companyId)->getScale()` with NO currency argument, so the company country preset is used. It calls `RepositoryTransferPrecisionPolicy::assertWithinCeiling(...)` as its first statement for both authority branches, before locks, replay lookup or writes. Red tests use captured exceptions and assert typed amount/effectiveScale plus unchanged document, movement, JE, balance and ordinal snapshots. **Removal condition (verbatim):** removing `OPERATIONAL_SCALE_CEILING` requires a separately accepted follow-up lane that widens `repository_movements.amount` and `repository_movements.balance_after` to `decimal(15,4)`, updates both `RepositoryMovement` casts, the write/replay precision guards in `TreasuryMovementService`, and their architecture/regression tests; the P0 census opens that ticket. The benchmark/cast ruling alone does not remove the ceiling.

For a new operation generate a server group UUID independent of the user operation UUID; the company-operation unique owns retries. Existing movement keys at `TreasuryMovementService.php:296` are tenant-database-global, so using the client's operation UUID directly would incorrectly collide across companies. UUID collision must roll back and retry allocation before any committed effect; do not widen the existing movement key contract casually.

Mint optional JE through the existing factory before the movement port takes company/repository locks. Preserve tenant-numbering → company-GL → sorted repository lock order documented at `TreasuryMovementService.php:238`; do not introduce repository-before-numbering locking. After the port locks, revalidate active/type/currency and GL mapping against the selected draft; concurrent mapping drift aborts the whole operation. Snapshot evidence from locked rows, insert document after both legs, and commit once. Any failure rolls back document, legs, balances, ordinals and draft/post. Exact document replay runs before mutable freeze/checkpoint/active rules, after stored-source adapter authorization in the order above.

Same linked GL account: two opposite legs, no JE. Different linked GL accounts: exactly one posted JE, Dr destination/Cr source, equal amount. Retain existing behavior for both-unlinked repositories explicitly as no-GL operational custody; never treat one-null/one-linked as same GL. Future launch readiness may require linked accounts; this slice does not invent an owner accounting cutover.

P0-b acceptance is a hard dependency for this task. No schema beyond T1/P0. Do not change the raw movement source type or fiscal events. NEW config entry in `apps/api/config/treasury.php` (existing flag shape at :28): `repository_transfer_documents_enabled` reads `TREASURY_REPOSITORY_TRANSFER_DOCUMENTS_ENABLED`, default false. Both documented transfer and reversal methods, including internal callers, refuse new writes when false; the retained Push-3 scalar method is outside this new flag contract until removed at Push 5; the final activated HTTP contract returns 503 repository_transfer_writes_disabled. The replacement has no fallback call to the old documentless writer. Configuration saves and document reads remain available for preparation/audit; flag-off transfer replay is a refused write request (503), with no mutation. After any human- or system-authored document exists, code rollback may not restore the old writer or drop system provenance/null-user support; retain full actor-union read compatibility. T5 renders the refused state and prevents submission. This flag does not enable variance or a queue.

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

Convention-09 at T3 gate: test_second_company_can_reuse_operation_uuid; test_second_location_transfer_uses_selected_source; test_retry_returns_original_document_without_writes (AlreadyRecorded). No T6 dependency. Existing transfer regression command also runs: `php artisan test -c phpunit.xml --filter=RepositoryTransferServiceTest`, lane backend-test / SQLite regression. All referenced cases MUST use Common real-provisioning.

Additional T3 red tests, same file/command:

| Exact test method | First failing assertion |
|---|---|
| `RepositoryTransferDocumentTest::test_system_first_submission_without_user(): void` | ScheduledCommand intent, no CompanyContext/user: document initiator_kind System, created_by null, users count unchanged and one pair. |
| `RepositoryTransferDocumentTest::test_fiscal_system_terminal_provenance_is_validated(): void` | Valid persisted terminal scope records system provenance; foreign terminal/company/source location refuses with zero writes. |
| `RepositoryTransferDocumentTest::test_system_retry_is_already_recorded(): void` | Same stable system tuple + operation => AlreadyRecorded, same document, no extra money/users. |
| `RepositoryTransferDocumentTest::test_system_authority_or_provenance_change_conflicts(): void` | Changed principal/authority/source UUID/terminal/source location conflicts with unchanged snapshots. |
| `RepositoryTransferDocumentTest::test_human_system_identity_swap_conflicts(): void` | Same operation with other actor branch conflicts; no new document/user. |
| `RepositoryTransferDocumentTest::test_notes_canonical_equivalences_replay(): void` | Canonical-equivalent notes replay with identical stored canonical value. |
| `RepositoryTransferDocumentTest::test_meaningful_notes_differences_conflict(): void` | Case, accent, punctuation, meaningful character and zero-width differences conflict. |
| `RepositoryTransferDocumentTest::test_1005_matches_raw_repository_movement_and_gl(): void` | After P0-b, TND raw SQL deltas equal `1.005`; companion assertion refuses a fourth operational decimal. |

T3 compatibility test file NEW `apps/api/tests/Feature/Treasury/RepositoryTransferCompatibilityTest.php`: `test_push_three_scalar_endpoint_preserves_current_behavior(): void` and `test_push_three_typed_core_is_disconnected_and_disabled(): void`; command `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferCompatibilityTest`. T5 replaces these with `test_activation_removes_scalar_fallback(): void` and `test_flag_off_after_first_document_is_503_without_mutation(): void`.

**Document-per-action baseline:** T3 prepares removal; T5 atomically removes only `app/Modules/Treasury/Application/Services/RepositoryTransferService.php::App\Modules\Treasury\Application\Services\RepositoryTransferService::transfer::journal_entries::delete#1` from `apps/api/tests/Architecture/baselines/document-per-action-baseline.json:34` after the legacy path is gone. Run `DocumentPerActionBaselineRatchetTest` and its protected-blob method with the owner-pinned CI variable from `.github/workflows/ci.yml:318`. No baseline growth or new pin.

Gate: treasury-reviewer + tenancy-authz-reviewer inspect operation serialization, monetary snapshots, GL lock order and company identity. Rollback: block new transfers and roll forward; never delete recorded documents or posted JEs.

## T4 — Linked reversals and explicit frozen policy

Production anchors: wrapper rejects either frozen repository at `apps/api/app/Modules/Treasury/Application/Services/RepositoryTransferService.php:46`; single-leg port allows explicitly flagged record-and-alert at `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php:91`; paired port currently hardcodes false at `:371`, `:391`, and event builder `:639`. Existing TransferIntent has no freeze/reversal fields (`apps/api/app/Modules/Treasury/Application/DTOs/TransferIntent.php:26`).

Extend that constructor, retaining its complete existing parameter order: `__construct(string $fromRepositoryId, string $toRepositoryId, string $tenantId, string $companyId, string $amount, string $currency, string $transferGroupId, ?string $journalEntryId, ?CarbonImmutable $occurredAt, ?string $createdBy, ?string $notes, bool $allowWhileFrozen = false, ?string $reversesOutMovementId = null, ?string $reversesInMovementId = null)`. Extend private `buildTransferLegEvent(..., bool $recordedWhileFrozen, ?string $reversesMovementId): RepositoryMovementRecorded`; new out references original in and new in references original out.

Use existing v1 `RepositoryMovementRecorded` (`apps/api/app/Modules/Treasury/Domain/Events/RepositoryMovementRecorded.php:23`), whose constructor accepts reversesMovementId at :43 and audit payload emits it at :75. Do not edit the event class or version it. Compliance persists it through `apps/api/app/Modules/Compliance/Listeners/DomainEventSubscriber.php:1144`, registered at :1273.

Extend `RepositoryTransferReversalTest` with event and real-audit assertions; no fake intercepts the audit proof. Constructor-inject `RepositoryTransferFrozenWarningSink`; schedule `warn()` only through `DB::afterCommit` for each successfully recorded frozen leg. Marker is exactly `WCASH-1-FROZEN-TRANSFER:` with tenant, company, document, group, movement and repository IDs only.

NEW `Application/DTOs/RepositoryTransferReversalIntent.php`: `__construct(string $tenantId, string $companyId, string $originalDocumentId, string $operationUuid, ?string $sourceLocationId, string $explanation, CarbonImmutable $occurredAt, HumanTransferAuthorityData|SystemTransferAuthorityData $authority)`.

Add `RepositoryTransferService::reverse(...): DocumentedRepositoryTransferResult` while disconnected; atomically rename the result at Push 5. Only full reversals. Serialize original identity, authorize the original destination as current reversal source, swap repositories, preserve amount/currency, link documents and opposite legs, retain original rows/JEs, refuse changed GL mapping and respect checkpoint/balance controls.

Interactive HTTP always sets allowWhileFrozen=false. Internal true records and alerts. Replay produces no duplicate effect or alert. P0-b acceptance is required before T4 money tests. The temporary precision ceiling remains until the full removal condition in T3 is met; P0-b alone does not remove it.

Red-first files:

- NEW `apps/api/tests/Feature/Treasury/RepositoryTransferReversalTest.php`, command `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferReversalTest`.
- NEW `apps/api/tests/Feature/Treasury/RepositoryTransferFrozenPolicyTest.php`, command `php artisan test -c phpunit-pgsql.xml --filter=RepositoryTransferFrozenPolicyTest`.

Required reversal cases: append linked reversal; repeat returns original reversal; second operation refused; mapping change refused; second company independent; second-location current outflow custody; actor/explanation conflict; opposite-leg event references; real audit references; retry emits no duplicate audit.

Required frozen cases: explicit frozen destination records and alerts once after commit; interactive frozen destination has no effect; retry returns original without warning; rollback warns zero.

Gate: treasury-reviewer + tenancy-authz-reviewer inspect compensation direction, current custody, immutable original and non-client-settable override. Rollback: disable new reversals; preserve all evidence and money rows.

## T5 — Existing HTTP and web surfaces, generated types

**Hard prerequisite W1-T-CUSTODY-AUTHZ:** before T5 starts, pin its implementation SHA, treasury-reviewer + tenancy-authz-reviewer acceptance, `treasury.manage_all_locations`/general_manager behavior and existing-tenant cache/grant evidence. Source spec: `docs/superpowers/specs/2026-09-05-parapharmacy-readiness-remediation-design.md:139,147`; current permission catalogue ends at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:266`. No temporary permission.

All three FormRequests implement `withValidator(Validator $validator): void`, compare raw top-level keys to explicit allowlists and add `__('messages.validation.unexpected_field')` for every unexpected field. Transfer allowlist: operation_uuid, transfer_group_id, from_repository_id, to_repository_id, amount, currency, notes. Reverse: operation_uuid, explanation. Custody: location_id, default_safe_repository_id, default_bank_repository_id, enabled, expected_revision. Add translations in en/fr/ar.

Keep POST `/api/v1/payment-repositories/transfers`. `TransferRepositoryRequest::rules()` requires operation UUID/legacy equal alias, repositories, numeric-string positive amount matching `/^\d+(\.\d{1,4})?$/`, uppercase currency and optional notes. Service then enforces `min(country preset, OPERATIONAL_SCALE_CEILING)`, currently three. Unknown authority/freeze/timing/configuration fields are rejected.

NEW requests:

- `apps/api/app/Modules/Treasury/Presentation/Requests/ReverseRepositoryTransferRequest.php`.
- `apps/api/app/Modules/Treasury/Presentation/Requests/SaveCashCustodyRequest.php`.

Controller additions:

- `RepositoryTransferController::show(Request $request, string $documentId): JsonResponse`.
- `RepositoryTransferController::reverse(ReverseRepositoryTransferRequest $request, string $documentId): JsonResponse`.
- `PaymentRepositoryController::saveCashCustody(SaveCashCustodyRequest $request, string $id): JsonResponse`.

Add fixed UUID-constrained routes before generic repository routes and retain auth, tenant, permission and `module:Treasury` middleware.

Index modes: omitted retains compatibility; `transfer_source` returns authorized active physical sources; `transfer_destination&from_repository_id=` scopes the source then returns authorized destinations or configured central safe/bank. NEW `RepositoryDestinationData::__construct(string $id, string $name, RepositoryType $type, string $currency)` exposes no balance/bank details.

Document reads require repositories.view and authority over both historical source location and current source repository, or company-wide authority. Destination-only users receive no document link/evidence and direct 404. Destination balance_after is nullable when unreadable.

HTTP contracts:

- New transfer/reversal: 201.
- Exact replay: 200 already_recorded.
- Document GET: 200.
- Configuration saved/unchanged: 200.
- Transfer conflict: 409 with authorized document_id.
- Revision conflict: 409 with current_revision.
- Precision ceiling: exact translated 422 with `error.code`, `amount`, `effective_scale`.
- Accessible legacy group without document: 409/document_id null.
- Scoped miss: 404 without IDs.
- Permission/module: 403.
- Unauthenticated: 401.
- Typed business refusal: 422.
- Flag false: 503, no fallback.

NEW transport DTOs:

- `RepositoryTransferRequestData::__construct(string $operation_uuid, string $from_repository_id, string $to_repository_id, string $amount, string $currency, ?string $notes)`.
- `RepositoryTransferResponseData::__construct(RepositoryTransferDocumentData $document, RepositoryTransferOutcome $outcome, string $transfer_group_id, ?string $journal_entry_id, bool $idempotent_replay, RepositoryTransferLegData $out, RepositoryTransferLegData $in)`.
- `RepositoryTransferLegData::__construct(string $movement_id, ?string $balance_after, string $repository_id)`.
- `PaymentRepositoryData::__construct(string $id, string $code, string $name, RepositoryType $type, bool $allow_negative, ?string $bank_id, ?string $bank_name, ?string $account_number, ?string $iban, ?string $bic, string $balance, string $currency, bool $is_active, ?string $gl_account_id, ?RepositoryGlAccountData $gl_account, ?string $location_id, ?string $location_name, RepositoryBankValidationData $bank_account_validation, ?CashCustodyConfigurationData $cash_custody)`.
- `RepositoryGlAccountData::__construct(string $id, string $code, string $name)`.
- `RepositoryBankValidationData::__construct(?RibValidationResult $rib, ?IbanValidationResult $iban, ?bool $bic_valid)`.

Durable history updates `RepositoryMovementController`, NEW `RepositoryMovementData`, `useRepositoryMovements`, `RepositoryMovementsTab` and `RepositoryDetailPage`. Preserve every current movement field and add nullable `transfer_document_id`/`href`. Batch resolve by tenant/company/group and apply document-read policy.

Generated types remain ambient `App.*`. Add `/// <reference path="../../../packages/shared/types/generated.d.ts" />` to POS vite-env. Replace repository response shadows throughout the enumerated web/POS consumers with generated aliases/Picks, including StatementUploadWizard/ListPage, repository pages/forms/hooks/modals, POS APIs/types/sync and device SQLite adapter. No runtime POS behavior or device build changes.

Add `data-feature="wcash-1-custody-transfer-v2"` to the repository detail custody/document section. Preserve operation UUID across ambiguous retries, freeze submitted intent until resolved, reset on tenant/company switch, use unwrapped API results, money strings, translations and scoped cache keys.

Required backend endpoint tests include branch/source 404, UUID oracle prevention, retry after configuration change, different-actor conflict, central reverse denial, module-off denial, forged freeze denial, destination omission, document-read policy, exact precision 422, status matrix, second company, second location and explicit retry outcome.

Required web tests cover returned document/replay outcome, stable retry UUID, central destination balance omission, second-location custody save and company-switch reset. Generated typecheck fixtures assert exact ambient aliases for web and POS. POS storage regression asserts unchanged cached row conversion.

Additional backend tests reject arbitrary unknown transfer/reverse/custody fields and forged system authority; verify final controller typed signature and no scalar callers. NEW `RepositoryTransferHistoryTest` covers both authorized legs, destination-only omission and system-authored document reads. Web movement tests cover persisted links after reload and destination-only omission.

Gate: treasury-reviewer + tenancy-authz-reviewer + frontend-conventions review and React Doctor finishing workflow. Rollback: disable action UI/HTTP writes together; retain document reads/schema; never restore documentless writes after first use.

## T6 — PostgreSQL proof and deployment handback

Verified anchors: PG lane `apps/api/phpunit-pgsql.xml:1`; registration fixture `apps/api/tests/Feature/Tenant/TenantInitializationTest.php:158`; second-company path `apps/api/tests/Feature/Treasury/CompanyPaymentRepositoryProvisioningTest.php:95`; catalogue ratchet `apps/api/tests/Architecture/TenantOnlyUniqueOnCatalogueTablesRatchetTest.php:212,254`.

NEW `apps/api/tests/Feature/Treasury/CashCustodySecondOfEverythingTest.php`:

- `test_registered_second_company_can_reuse_operation_uuid(): void`.
- `test_selected_second_pos_location_keeps_its_own_custody(): void`.
- `test_rerun_configuration_and_transfer_are_explicit(): void`.

NEW `apps/api/tests/Integration/Treasury/RepositoryTransferDocumentConcurrencyTest.php`:

- `test_concurrent_same_operation_commits_one_document(): void`.
- `test_opposing_transfers_preserve_lock_order(): void`.
- `test_concurrent_reverse_commits_once(): void`.
- `test_company_uuid_reuse_does_not_collide(): void`.

NEW `apps/api/tests/Integration/Treasury/CashCustodyTopologyTest.php`:

- `test_db_per_tenant_and_compatibility_scope_are_equivalent(): void`.
- `test_migrations_are_repeatable_and_empty_down_up_is_safe(): void`.

Run `TenantOnlyUniqueOnCatalogueTablesRatchetTest`; classify configurations as catalogue and documents as immutable evidence without ceiling growth.

NEW Playwright `apps/web/e2e/treasury-cash-custody-transfer.spec.ts`, test `second branch custody and transfer evidence`, on private API :8011 and Vite :5174. It verifies configure/transfer, stable retry, linked reversal, wrong-branch denial and company-switch reset.

Local host verification:

```sh
PREFLIGHT_TEST_PATHS='tests/Feature/Treasury/CashCustodySchemaTest.php tests/Feature/Treasury/CashCustodyConfigurationTest.php tests/Feature/Treasury/CashCustodyCensusCommandTest.php tests/Feature/Treasury/RepositoryTransferDocumentTest.php tests/Feature/Treasury/RepositoryTransferReversalTest.php tests/Feature/Treasury/RepositoryTransferFrozenPolicyTest.php tests/Feature/Treasury/RepositoryTransferEndpointTest.php tests/Feature/Treasury/CashCustodySecondOfEverythingTest.php' ./scripts/preflight.sh
```

Run all focused PG integration commands, SQLite transfer regressions, type generation, web/POS typechecks, focused Vitest and Playwright. Full suite remains VPS/CI: `pnpm build`, `pnpm lint`, `pnpm test`, `pnpm typecheck`, web e2e, `composer test`, PHPStan and Pint. Skips are incomplete, not green.

Gate: treasury-reviewer + tenancy-authz-reviewer approve PG evidence, second-company registration and denial snapshots. NEW handback `docs/handoff/HANDBACK-WCASH-1-2026-09-06.md` records implementation SHA, red/green commands, screenshots, migrations/censuses and reviews.

## Deployment — canonical manifest variables

> Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
> (five-push sequence §2, web block §3, gate checklist §4). This slice supplies only the variables
> below; it does not restate deploy mechanics. Dispatch P0-a, T1 and T2 now. P0-b remains held
> for the benchmark and owner cast ruling; after accepted P0-b, deploy WCASH and dispatch T3–T6.

| Variable | WCASH-1 value |
|---|---|
| `<slice>` | `wcash-1`; the authoritative precision prerequisite uses `<slice>=precision-4`. |
| **Migrations list** | P0-a: none. P0-b only: `apps/api/database/migrations/tenant/2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php`, non-additive, self-guarding and standalone after P0-a plus the benchmark/cast ruling. WCASH additive files: `2026_09_06_210000_create_cash_custody_configurations.php`, then `2026_09_06_210100_create_repository_transfer_documents.php`. |
| **Flags** | P0: none. WCASH: NEW `treasury.repository_transfer_documents_enabled` / `TREASURY_REPOSITORY_TRANSFER_DOCUMENTS_ENABLED` / `apps/api/config/treasury.php` / false. Existing variance flag remains false. |
| **Commands** | P0-a: NEW `treasury:census-money-precision {--tenant=} {--json}`, standalone, marker `MONEY-PRECISION CENSUS`; run per tenant and fleet aggregate. P0-b: rolling migration or exact compatibility-mode path. WCASH: NEW `treasury:census-cash-custody {--tenant=} {--company=} {--require-ready} {--json}`, standalone, marker `WCASH-1-CUSTODY:`. |
| **Censuses** | P0-a: complete `MONEY-PRECISION CENSUS` evidence, exact source tuples and all five classifications. P0-b: post-census, four exact scale-four tuples, unchanged casts and classified follow-up list; also run `treasury:reconcile --tenant=<uuid>` and `tenant:census-day-one`. WCASH Push 1/4 retain day-one and POS VAT census requirements. |
| **Web changes** | P0: none. WCASH: yes; canonical web block, `<slice-unique-string>=wcash-1-custody-transfer-v2`, served hash/fingerprint before activation. |
| **Device build** | No. POS changes are type-only aliases/serialization adapters. |
| **Queues** | None. |
| **Collapsed pushes** | P0-a is precision-4 Push 1 census-only. P0-b is a separate non-additive Push 2 exception after the benchmark/ruling; it is not collapsed with WCASH. T1/T2 implementation dispatches now but WCASH deployment starts only after accepted P0-b. No canonical WCASH push is collapsed. T3–T6 dispatch only after accepted P0-b. |
| **Env path** | P0 adds none. WCASH candidate remains Dokploy Environment tabs; U-1 must verify actual delivery/topology. |
| **Host-side backup** | P0-a none. P0-b: `/root/backup-precision-4-<database>-<UTC>.dump` per actual physical DB, non-zero and restore-readable. WCASH: `/root/backup-wcash-1-<UTC>.dump` before Push 2 and Push-4 writes. |
| **Rollback point per push** | P0-a revert tooling/preserve evidence. P0-b transaction rollback before commit; after commit retain scale-four schema and scale-three casts, never narrow. WCASH rollback remains forward-only after evidence/money use. |

**Promotion preconditions:** U-1 topology/env, U-2 tenancy mode/migration execution and U-5 backup targets must be evidenced. P0-a reviewer evidence is required. P0-b stays held until the benchmark note and owner cast ruling, then requires backup, accepted SHA, raw `1.0005` tests, exact tuples and post-census. T1/T2 can be implemented now but their deployment waits for P0-b. T3–T6 do not dispatch until P0-b acceptance. T5 also requires accepted W1 authorization. The four Eloquent casts stay `decimal:3`; no ceiling removal follows from P0-b.

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

Dispatch **P0-a precision census tooling, T1 and T2 now**. Deploy P0-a with no migration and capture every tenant’s evidence. Hold **P0-b** until `docs/superpowers/reviews/2026-09-07-benchmark-money-precision-4-decimals-casts.md` exists and the owner cast ruling is recorded. Then promote P0-b with backups and topology-specific migration proof. Only after accepted P0-b dispatch **T3 → T4 → T5 → T6**. T1/T2 retain one combined acceptance after the real writer rerun and deploy only after P0-b. T5 remains held for W1-T-CUSTODY-AUTHZ. Use isolated worktrees and coordinate shared files; this planning assignment performs no Git write.

- [ ] HEAD `9c26e06412d704018cb47119871a1c0cbbd09723` and every existing seam reverified; NEW paths remain proposals until implemented.
- [ ] Ten benchmark rows, glossary additions, Q11–Q13 verbatim and all explicit deferrals retained.
- [ ] Dispatch P0-a, T1 and T2 now; do not dispatch P0-b before the benchmark and owner cast ruling; do not dispatch T3–T6 before accepted P0-b.
- [ ] P0-a contains only `MoneyPrecision*` census code, `MoneyColumnShapeData`, five-case `MoneyColumnClassification`, command `treasury:census-money-precision`, marker `MONEY-PRECISION CENSUS`, and `MoneyPrecisionCensusCommandTest`.
- [ ] P0-a per-tenant/fleet output captured; all five classifications proven, including Geometry for `pos_tables.*`; all Money findings written to `2026-09-07-money-columns-below-scale-4-followup.md`.
- [ ] P0-b accepts only exact `(15,3,NO,0)` / `(15,3,YES,NULL)` or compliant `(15,4,…)`; scale two asserts `unexpected_column_shape`, no DDL and unchanged rows/schema.
- [ ] P0-b uses `2026_09_07_100000_widen_gl_and_repository_balance_columns_to_scale_4.php`, `GlAndRepositoryBalanceScale4Test.php`, `MoneyStorageScale4RatchetTest.php`, raw `1.0005` proof, backups and physical postchecks.
- [ ] All four Eloquent casts remain `decimal:3` provisionally; no plan clause changes them before the owner’s post-benchmark ruling.
- [ ] `OPERATIONAL_SCALE_CEILING = 3` remains until the separately accepted movement-ledger widening, cast, guard and PG-test condition is complete.
- [ ] Complete schemas, typed JSONB evidence, enums, company ownership and append-only guards proven on PostgreSQL.
- [ ] Every active-drawer location has a valid enabled safe; missing bank remains visible; no guessed defaults.
- [ ] Real registration, second company, second location and explicit rerun outcomes proven.
- [ ] One document, one group and exactly two cross-linked legs; same GL zero JE, cross GL one posted balanced JE.
- [ ] Conflicting/concurrent retries, partial failure, changed GL mapping, reversed custody and legacy aliases tested.
- [ ] Original preserved; exactly one linked full reversal; original and compensating JEs remain auditable.
- [ ] Frozen internal override records/alerts after commit; HTTP cannot request it; replay emits no duplicate.
- [ ] Scoped source denial leaves document/movement/JE/balance/ordinal snapshots unchanged.
- [ ] Routes/actions are module-gated; only HTTP resolves user location authority; internal intents carry explicit authority.
- [ ] Generated DTOs replace repository/transfer response shadows; one repository surface; stable UUID retry and company-switch reset verified.

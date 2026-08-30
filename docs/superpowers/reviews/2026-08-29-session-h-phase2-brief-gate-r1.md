<!-- Codex CLI read-only adversarial gate, round 1, dispatched by Session H orchestrator 2026-08-29; model_reasoning_effort=high; brief at commit 903c02141. Dispositions: see 'Orchestrator dispositions' appended below. -->

# Review register — `docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md` — HEAD `903c021411eb77b8bf7927a07fa96397b28c9611`

## F-1 — P0

- **Brief section:** M1.2, migration/backfill.
- **Claim:** Adding `party_kind` with `default('person')`, then updating organization rows only where `party_kind IS NULL`, correctly classifies legacy rows.
- **Evidence:** The brief specifies the default before the null-based backfill ([brief:153](docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md:153)). PostgreSQL supplies `person` to every existing row when the column is added with that default, leaving no null rows for the organization arms. The earlier category migration used the safe inverse pattern: nullable column without a default, then a null-based backfill ([2026_03_09_100001_add_customer_category_to_partners.php:20](apps/api/database/migrations/tenant/2026_03_09_100001_add_customer_category_to_partners.php:20), [2026_03_09_100001_add_customer_category_to_partners.php:26](apps/api/database/migrations/tenant/2026_03_09_100001_add_customer_category_to_partners.php:26)).
- **Prescribed fix:** Add the column nullable and without a default; if concurrent writes require one, set the default only after the add-column statement. Backfill, verify no nulls, set `NOT NULL`, and drop the default only after all writers are compatible. Prove this on PostgreSQL with representative legacy rows.

## F-2 — P1

- **Brief section:** M1 versus M2 milestone boundaries.
- **Claim:** M1 can set `party_kind NOT NULL`, drop its default, and still pass a POST-without-`party_kind` browser gate before M2 updates writers.
- **Evidence:** M1 drops the default ([brief:160](docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md:160)) and expects an omitted-kind POST to succeed ([brief:221](docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md:221)). At HEAD, writes omit the new column in `PartnerController` ([PartnerController.php:201](apps/api/app/Modules/CRM/Presentation/Http/Controllers/PartnerController.php:201)), POS pending-customer creation ([PosPendingCustomerController.php:80](apps/api/app/Modules/POS/Presentation/Http/Controllers/PosPendingCustomerController.php:80)), the import/service upsert ([PartnerService.php:88](apps/api/app/Modules/CRM/Application/Services/PartnerService.php:88)), Marketplace ([PartnerService.php:222](apps/api/app/Modules/Marketplace/Application/Services/PartnerService.php:222)), and Cart ([PartnerService.php:87](apps/api/app/Modules/Cart/Application/Services/PartnerService.php:87)). M2, not M1, introduces derivation.
- **Prescribed fix:** Make schema and all writers deployment-compatible in one milestone, or leave the column nullable/defaulted until the writer milestone has shipped, then backfill and constrain it in a subsequent migration.

## F-3 — P1

- **Brief section:** M1.2, arm-1 `created_at >= 2026-03-09` guard.
- **Claim:** The date guard is a binding orchestrator amendment and accurately excludes values manufactured by the March 9 migration.
- **Evidence:** The actual migration rewrote every qualifying null category without recording provenance or migration time ([2026_03_09_100001_add_customer_category_to_partners.php:26](apps/api/database/migrations/tenant/2026_03_09_100001_add_customer_category_to_partners.php:26)). `partners.created_at` records partner creation, not when a tenant migration ran ([2025_11_30_052119_create_partners_table.php:16](apps/api/database/migrations/tenant/2025_11_30_052119_create_partners_table.php:16)). The binding D-H0-1 row records the owner heuristic but no date-based amendment ([LEDGER.md:208](docs/handoff/LEDGER.md:208)).
- **Prescribed fix:** Remove the assertion that this is binding. Add a STOP for an owner ruling on a defensible provenance heuristic. If a date cutoff is retained, define its timezone and per-tenant semantics and explicitly acknowledge its false-positive/false-negative population.

## F-4 — P1

- **Brief section:** M1/M2 derived-wire invariant.
- **Claim:** `party_kind` and stored `customer_category` can never disagree.
- **Evidence:** The March 9 migration assigned `business` broadly ([2026_03_09_100001_add_customer_category_to_partners.php:26](apps/api/database/migrations/tenant/2026_03_09_100001_add_customer_category_to_partners.php:26)). The proposed migration assigns `party_kind` but does not repair `customer_category`. Runtime B2B logic still reads the stored category ([Partner.php:226](apps/api/app/Modules/CRM/Domain/Models/Partner.php:226)). No composite database constraint is specified.
- **Prescribed fix:** Recompute `customer_category` from the final backfilled kind and enforce the pair centrally. Prefer a PostgreSQL composite CHECK or an equally comprehensive write invariant with raw-SQL rejection coverage.

## F-5 — P1

- **Brief section:** M2.2 and M3 form submission.
- **Claim:** The proposed request rules enforce valid party state and accept everything the form submits.
- **Evidence:** M3 submits `preferred_locale` ([brief:375](docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md:375)), but M2’s request work does not include it ([brief:235](docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md:235)). Controllers persist only validated fields ([PartnerController.php:207](apps/api/app/Modules/CRM/Presentation/Http/Controllers/PartnerController.php:207), [PartnerController.php:280](apps/api/app/Modules/CRM/Presentation/Http/Controllers/PartnerController.php:280)). Field-level `prohibited_unless` rules do not clear incompatible persisted VAT/legal/person fields when an existing record changes kind. D-H0-1 forbids tax identity on a person ([LEDGER.md:208](docs/handoff/LEDGER.md:208)).
- **Prescribed fix:** Define a centralized final-state policy for create and update: validate `preferred_locale` and normalized fields, reject or clear incompatible fields on kind transitions, and enforce OQ7 for HTTP, POS, imports, seeders, and internal services—not only submitted request keys.

## F-6 — P1

- **Brief section:** M2.2, `PartnerService` upsert.
- **Claim:** `$data['party_kind'] ?? PartyKind::deriveFrom($data)` safely classifies import upserts.
- **Evidence:** Existing records are found before the sparse incoming data is applied ([PartnerService.php:64](apps/api/app/Modules/CRM/Application/Services/PartnerService.php:64)); `updateOrCreate` then writes only incoming values ([PartnerService.php:88](apps/api/app/Modules/CRM/Application/Services/PartnerService.php:88)). Deriving solely from a sparse row can turn an existing organization into a person when the update omits VAT/legal fields.
- **Prescribed fix:** Derive from the merged existing state, incoming state, and final partner role. Preserve an existing explicit kind when the incoming row supplies no kind-changing evidence. Add sparse-update and customer/supplier-to-both regression tests.

## F-7 — P1

- **Brief section:** M1 normalizer and M2 write paths.
- **Claim:** Adding request normalization plus the backfill covers normalized contact data.
- **Evidence:** POS writes raw `phone` and `email` directly ([PosPendingCustomerController.php:82](apps/api/app/Modules/POS/Presentation/Http/Controllers/PosPendingCustomerController.php:82)); imports map raw values ([PartiesRowMapper.php:17](apps/api/app/Modules/CRM/Application/Imports/PartiesRowMapper.php:17)); the central upsert also persists them directly ([PartnerService.php:94](apps/api/app/Modules/CRM/Application/Services/PartnerService.php:94)). Assessment R-C requires normalized contact points ([ASSESSMENT-crm-seam-party-model-2026-08-29.md:37](docs/handoff/ASSESSMENT-crm-seam-party-model-2026-08-29.md:37)).
- **Prescribed fix:** Normalize centrally at the service/domain write boundary with an explicit default region. Test HTTP, POS, import create, import update, and internal-service paths. The backfill alone covers only old rows.

## F-8 — P1

- **Brief section:** M2.4 event immutability.
- **Claim:** The V2 and Contact events are “Laravel events, NOT Spatie event-sourcing” and Laravel dispatch assertions prove the wiring.
- **Evidence:** Project domain events extend Spatie’s `ShouldBeStored` event type ([DomainEvent.php:7](apps/api/app/Shared/Domain/Events/DomainEvent.php:7), [DomainEvent.php:16](apps/api/app/Shared/Domain/Events/DomainEvent.php:16)). Spatie’s wildcard subscriber stores dispatched `ShouldBeStored` events ([EventSubscriber.php:16](apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/EventSubscriber.php:16)), and the provider subscribes it ([EventSourcingServiceProvider.php:45](apps/api/vendor/spatie/laravel-event-sourcing/src/EventSourcingServiceProvider.php:45)). Updates and deletes currently remain controller-owned ([PartnerController.php:293](apps/api/app/Modules/CRM/Presentation/Http/Controllers/PartnerController.php:293), [PartnerController.php:339](apps/api/app/Modules/CRM/Presentation/Http/Controllers/PartnerController.php:339)); `ContactService` lacks delete ([ContactService.php:18](apps/api/app/Modules/Contact/Application/Services/ContactService.php:18)).
- **Prescribed fix:** Keep frozen V1 classes untouched, but correct the brief: V2 events are dispatched through Laravel and persisted by Spatie. Specify typed create/update/delete service APIs, preserve transactional/reference guards, and test both dispatch and `stored_events` class/payload persistence.

## F-9 — P1

- **Brief section:** M2 fiscal proof.
- **Claim:** A PHPUnit comparison against a literal `business` payload proves all sealed-byte behavior remains stable.
- **Evidence:** The two server boundaries are correctly identified: `PosCustomerMirrorResource` ([PosCustomerMirrorResource.php:34](apps/api/app/Modules/POS/Presentation/Http/Resources/PosCustomerMirrorResource.php:34)) and `VirtualAdminFiscalEventService` ([VirtualAdminFiscalEventService.php:241](apps/api/app/Modules/VirtualAdmin/Application/Services/VirtualAdminFiscalEventService.php:241)). But ACCOUNT_CHARGE and ACCOUNT_PAYMENT sealing occurs in device TypeScript ([accountChargeService.ts:364](apps/web/src/lib/pos/offline/accountChargeService.ts:364), [accountPaymentService.ts:129](apps/web/src/lib/pos/offline/accountPaymentService.ts:129)). Server key sets are separately enforced for DEPOSIT_RECEIPT ([FiscalPayloadConstraintValidator.php:701](apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:701)), ACCOUNT_PAYMENT ([FiscalPayloadConstraintValidator.php:1725](apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1725)), and ACCOUNT_CHARGE ([FiscalPayloadConstraintValidator.php:1951](apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1951)). The frozen payment fixture contains the binding literal `retail` ([AccountPaymentPayload.ts:172](apps/web/src/lib/pos/fiscal/fixtures/AccountPaymentPayload.ts:172)).
- **Prescribed fix:** Retain the PHP proof for DEPOSIT_RECEIPT and add POS tests for ACCOUNT_CHARGE and ACCOUNT_PAYMENT using a mirrored organization, comparing canonical bytes/hashes with literal-`business` inputs. Pin unchanged key arrays, the B2B facture-draft rule, SALE_RECEIPT fixtures, and the frozen AccountPayment `retail` fixture.

## F-10 — P1

- **Brief section:** M1 enum/derivation helper and M4 warnings.
- **Claim:** `PartyKind::deriveFrom(array): PartyKind` can also produce a warning naming the exact derivation arm while remaining the single source of truth.
- **Evidence:** M4 requires the warning to name the selected arm ([brief:424](docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md:424)), but the proposed helper returns only the enum. Re-deriving the reason in the mapper would create a second heuristic. Public mixed arrays also conflict with the strict DTO boundary rule ([CLAUDE.md:21](CLAUDE.md:21)).
- **Prescribed fix:** Introduce a typed derivation input and result carrying both `kind` and a stable arm/reason code. Let the enum-only convenience method delegate to that result.

## F-11 — P1

- **Brief section:** M4 import tests and browser gate.
- **Claim:** Invalid `party_kind` returns HTTP 422, and fetching job rows exposes exactly two derived-kind warnings.
- **Evidence:** Upload returns 201 with row failures after validation ([ImportController.php:198](apps/api/app/Modules/Import/Presentation/Http/Controllers/ImportController.php:198)); the existing Parties invalid-row test asserts Created and inspects the row error ([PartiesImportTypeTest.php:126](apps/api/tests/Feature/Import/PartiesImportTypeTest.php:126)). Preview rows omit warnings ([ImportController.php:261](apps/api/app/Modules/Import/Presentation/Http/Controllers/ImportController.php:261)); the errors endpoint filters to invalid/import-error rows ([ImportController.php:323](apps/api/app/Modules/Import/Presentation/Http/Controllers/ImportController.php:323)). Valid-row warnings are rendered into the result workbook ([ResultWorkbookService.php:58](apps/api/app/Modules/Import/Application/Services/ResultWorkbookService.php:58), [ResultWorkbookService.php:112](apps/api/app/Modules/Import/Application/Services/ResultWorkbookService.php:112)).
- **Prescribed fix:** Expect 201 plus the row-level `party_kind` error. Verify valid-row warnings by downloading and inspecting the result workbook, or deliberately add an authorized warning-detail endpoint. Keep `ImportType::Partners` untouched; Parties and Partners are distinct schemas ([ImportType.php:88](apps/api/app/Modules/Import/Domain/Enums/ImportType.php:88), [ImportType.php:169](apps/api/app/Modules/Import/Domain/Enums/ImportType.php:169)).

## F-12 — P1

- **Brief section:** M1 enum parity.
- **Claim:** `PartyGender::values() === Gender::values()` is an executable parity test, and the planned schema meets enum/CHECK rules.
- **Evidence:** Contact’s `Gender` enum exposes cases but no `values()` method ([Gender.php:7](apps/api/app/Modules/Contact/Domain/Enums/Gender.php:7)). The brief adds a gender-backed column but specifies a database CHECK only for `party_kind`. The architecture test rejects enum-backed columns without matching PostgreSQL CHECKs ([EnumCheckParityTest.php:19](apps/api/tests/Architecture/EnumCheckParityTest.php:19)) and explicitly requires PostgreSQL ([EnumCheckParityTest.php:200](apps/api/tests/Architecture/EnumCheckParityTest.php:200)). Closed type/status columns must be enum-backed ([CLAUDE.md:39](CLAUDE.md:39)).
- **Prescribed fix:** Compare `array_column(Gender::cases(), 'value')`, add exact CHECKs for `party_kind` and gender, and execute parity tests on PostgreSQL. Either define `legal_form` as a closed enum with a CHECK or document it as open vocabulary instead of presenting a closed UI list as the domain contract.

## F-13 — P1

- **Brief section:** M3 UI affordance gates.
- **Claim:** Showing the B2B block only for organizations matches §7.5 as amended by OQ7.
- **Evidence:** The binding design still permits credit controls for an organization **or a person with credit explicitly enabled** ([2026-08-23-party-contact-target-model-research.md:468](docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md:468), [2026-08-23-party-contact-target-model-research.md:473](docs/superpowers/specs/2026-08-23-party-contact-target-model-research.md:473)). OQ7 amends tax identity, not that credit branch ([LEDGER.md:208](docs/handoff/LEDGER.md:208)). The brief renders the entire block only for organizations ([brief:356](docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md:356)). React Hook Form currently retains hidden values, and submit spreads them into the payload ([PartnerForm.tsx:193](apps/web/src/features/partners/components/PartnerForm.tsx:193), [PartnerForm.tsx:397](apps/web/src/features/partners/components/PartnerForm.tsx:397)).
- **Prescribed fix:** Preserve an explicit person-credit-enabled path or obtain an owner ruling removing it. On kind changes, unregister/reset hidden incompatible fields and add organization→person and person→organization form tests.

## F-14 — P1

- **Brief section:** M3 credit affordance wiring.
- **Claim:** Wiring the existing `CreditLimitWarning` is compliant with repository money rules.
- **Evidence:** The component performs credit comparisons and arithmetic through `parseFloat` and JavaScript numbers ([CreditLimitWarning.tsx:22](apps/web/src/features/partners/components/CreditLimitWarning.tsx:22), [CreditLimitWarning.tsx:61](apps/web/src/features/partners/components/CreditLimitWarning.tsx:61)). Repository rule 19 requires decimal-safe helpers for all monetary logic ([CLAUDE.md:71](CLAUDE.md:71)).
- **Prescribed fix:** Convert the component to the shared `bccomp`/decimal-safe money helpers and `formatCurrency`, then add boundary and decimal-precision tests before wiring it into the form.

## F-15 — P1

- **Brief section:** Preconditions and progress.
- **Claim:** The dispatch is ready to execute after its stated preflight.
- **Evidence:** HEAD is `903c021411eb77b8bf7927a07fa96397b28c9611`, while the Phase 1 work is not present: `PartnerForm` still owns a local partner interface ([PartnerForm.tsx:31](apps/web/src/features/partners/components/PartnerForm.tsx:31)) and still renders the old category select ([PartnerForm.tsx:507](apps/web/src/features/partners/components/PartnerForm.tsx:507)). The brief itself requires Phase 1 to be merged and anchors repinned ([brief:26](docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md:26)). The required `docs/handoff/progress/session-h-phase2.progress.yaml` is absent, and the migration name remains the `1000XX` placeholder ([brief:153](docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md:153)).
- **Prescribed fix:** Do not dispatch from this HEAD. Merge Phase 1 first, create and pin the Phase 2 progress file/base SHA, replace placeholders with collision-checked names, then rerun this adversarial review against the merged code.

## F-16 — P1

- **Brief section:** Runtime isolation and browser gates.
- **Claim:** Running Vite on port 5174 and the API on 8011 isolates the Phase 2 worktree.
- **Evidence:** Vite’s API proxy is hard-coded to port 8010 ([vite.config.ts:17](apps/web/vite.config.ts:17), [vite.config.ts:24](apps/web/vite.config.ts:24)). Changing only Vite’s listening port leaves browser traffic pointed at the other API.
- **Prescribed fix:** Make the API proxy target environment-configurable with 8010 as the normal default, launch this worktree with an explicit 8011 target, and add a browser-gate assertion proving the expected backend handled the request.

## F-17 — P2

- **Brief section:** Migration idempotence and cited templates.
- **Claim:** `2026_03_09_100001` is a guarded/idempotent PostgreSQL template, and `DROP CONSTRAINT IF EXISTS` followed by `ADD` makes a second run a no-op.
- **Evidence:** The reference migration’s add-column and add-constraint operations are unguarded ([2026_03_09_100001_add_customer_category_to_partners.php:20](apps/api/database/migrations/tenant/2026_03_09_100001_add_customer_category_to_partners.php:20), [2026_03_09_100001_add_customer_category_to_partners.php:32](apps/api/database/migrations/tenant/2026_03_09_100001_add_customer_category_to_partners.php:32)). Drop-and-readd mutates the constraint and is not a no-op.
- **Prescribed fix:** Specify explicit PostgreSQL catalog probes for column existence/type/nullability/default and constraint/index definitions. Test first application, normal second invocation behavior, and recovery from each supported partial legacy state.

## F-18 — P2

- **Brief section:** Reporting and operational debt.
- **Claim:** Recording staging phone-normalization follow-up only in the lane report is sufficient.
- **Evidence:** The ledger declares itself the sole authoritative open-obligation register ([LEDGER.md:1](docs/handoff/LEDGER.md:1)). The brief’s reporting section records only `owes_parent` output ([brief:470](docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md:470)).
- **Prescribed fix:** Require the parent lane to add the staging backfill obligation to `LEDGER.md` or explicitly record it in the binding promotion checklist with owner, status, and closure evidence.

## Disputes with the spec/rulings

1. The brief’s `created_at >= 2026-03-09` arm-1 guard is not present in binding D-H0-1 and cannot be derived from the old migration’s behavior. It requires a new owner ruling; it must not be presented as an acknowledged amendment.

2. The organization-only credit UI conflicts with design §7.5’s explicit person-with-credit-enabled branch. OQ7 prohibits person tax identity but does not remove person credit eligibility. The dispatch must implement the existing branch or obtain a binding amendment.

VERDICT: CHANGES-REQUIRED

<!-- Codex CLI read-only adversarial gate, round 2, dispatched by Session H orchestrator 2026-08-29; brief r2 at commit 43556a261. -->

# Round-2 adversarial gate register

**Brief:** `docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md` revision r2  
**HEAD:** `43556a2610100582f840d98a1b6f06e7f5f10528`

All evidence below was read from HEAD, not from the round-1 path assumptions.

## Round-1 resolution verification

| Finding | Status | r2 resolution and HEAD evidence |
|---|---|---|
| F-1 | RESOLVED | Migration A now requires nullable `party_kind` with no default before the null-based backfill (`brief:184-204`). This correctly avoids the failure caused by PostgreSQL populating pre-existing rows before `WHERE party_kind IS NULL`; the reference migration demonstrates the intended nullable-then-update order at `apps/api/database/migrations/tenant/2026_03_09_100001_add_customer_category_to_partners.php:20-30`. |
| F-2 | PARTIAL | r2 correctly defers `NOT NULL` and CHECK constraints until Migration B after writer conversion (`brief:184-186,388-402`). However, M1 still requires an omitted-kind POST to return a coherent, non-null pair (`brief:251-255`) before writer work begins in M2. Current creation spreads only validated fields at `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php:207-219`; `CreatePartnerRequest.php:66-80` has no `party_kind`. The M1 row would therefore remain NULL. |
| F-3 | RESOLVED | OQ10 is explicitly pending, accurately states that no date guard is binding, documents options (a)/(b), and defaults to **(a)** if unruled (`brief:169-182,580-581`). The uncertainty is real: the old migration rewrote every qualifying legacy customer without provenance at `2026_03_09_100001_add_customer_category_to_partners.php:26-30`, while `created_at` is merely the ordinary timestamp created at `2025_11_30_052119_create_partners_table.php:27`. No relitigation required. |
| F-4 | RESOLVED | r2 recomputes `customer_category` during Migration A (`brief:200-203`), centralizes runtime derivation (`brief:143-146,305-313,321-331`), and adds the composite CHECK in Migration B (`brief:392-402`). This covers both current category consumers: `Partner::isB2B()` at `apps/api/app/Modules/Partner/Domain/Partner.php:226-232`, the POS mirror at `PosCustomerMirrorResource.php:34-50`, and DEPOSIT_RECEIPT at `VirtualAdminFiscalEventService.php:241-247`. See N-6 for the inter-migration race. |
| F-5 | PARTIAL | A centralized merged-final-state `PartyIdentityPolicy`, widened request fields, and all named runtime paths are specified (`brief:264-319`). Current update behavior confirms why this is needed: only submitted validated keys are persisted at `PartnerController.php:280-295`. The transition contract is nevertheless internally inconsistent; see N-2 and N-5. |
| F-6 | PARTIAL | r2 now says derivation uses merged state, preserves an existing kind without new evidence, and tests sparse updates/type merges (`brief:154-167,301-303`). This addresses the current sparse `updateOrCreate` flow at `PartnerService.php:64-107`, but the proposed DTO cannot express an explicitly requested incoming kind and the role-merge instructions conflict; see N-1. |
| F-7 | RESOLVED | Normalization is placed centrally in `PartnerService`, uses the company country, and requires tests for HTTP create/update, POS, import create/update (`brief:221-237,305-319`). Those are the actual bypasses at HEAD: `PosPendingCustomerController.php:82-94`, `PartiesRowMapper.php:17-28`, and `PartnerService.php:88-106`. The separate device UUID deferral conflicts with spec §5.4; see N-8. |
| F-8 | PARTIAL | r2 correctly states that `DomainEvent` extends Spatie `ShouldBeStored`, preserves V1 classes, adds V2 events, moves dispatch into typed services, adds Contact delete, and tests Laravel dispatch plus `stored_events` (`brief:356-377`). HEAD confirms the base at `apps/api/app/Shared/Domain/Events/DomainEvent.php:7-16`, controller-owned Partner dispatch at `PartnerController.php:230-239,309-316,380-387`, and bare Contact deletion at `ContactController.php:198-220`. Seeder/factory emission required by R-A remains contradicted; see N-5. |
| F-9 | RESOLVED | r2 adds server DEPOSIT_RECEIPT proof, device ACCOUNT_CHARGE and ACCOUNT_PAYMENT canonical-byte/hash proofs, key-set pins, coherence-rule pins, frozen fixtures, and v67 proof (`brief:321-354`). The real builders are correctly relocated to `apps/pos`: `accountChargeService.ts:319-380` and `accountPaymentService.ts:129-152,204-235`; the frozen literal is at `AccountPaymentPayload.ts:106-123`. Two validator labels are swapped; see N-10. |
| F-10 | PARTIAL | Typed input/result DTOs and stable reason codes are present, and M4 is forbidden from re-deriving the reason (`brief:154-160,509-516`), satisfying CLAUDE rule 3 (`CLAUDE.md:21-22`). The input omits requested kind and `legal_form`, so it cannot correctly model all declared operations; see N-1 and N-7. |
| F-11 | RESOLVED | r2 expects 201 plus row-level validation failure, keeps `Parties` distinct from `Partners`, and verifies warnings through the result workbook rather than the errors endpoint (`brief:502-540`). HEAD supports this: upload returns 201 at `ImportController.php:198-207`, errors filter invalid/error rows at `:323-330`, and workbook warnings/download exist at `ResultWorkbookService.php:58-79,112-123` and `ImportController.php:608-631`. |
| F-12 | RESOLVED | r2 compares `Gender::cases()`, defines closed `LegalForm`/`PartyGender` enums, adds exact CHECKs for all three new enum-backed columns, and requires the parity test on PostgreSQL (`brief:143-153,388-402`). HEAD confirms Contact `Gender` has only cases at `apps/api/app/Modules/Contact/Domain/Enums/Gender.php:7-12` and the parity gate self-skips outside PostgreSQL at `EnumCheckParityTest.php:194-204`. |
| F-13 | PARTIAL | r2 restores the person-with-explicit-credit branch and adds both form transition tests (`brief:428-452`), matching spec §7.5. The claimed approval mechanism is not represented by current persisted semantics, and transition clearing conflicts with the API contract; see N-2 and N-3. |
| F-14 | RESOLVED | r2 makes decimal-safe Phase-1 conversion a prerequisite and forbids Phase 2 from wiring the component if `parseFloat` remains (`brief:453-456`). HEAD still contains the prohibited arithmetic at `apps/web/src/features/partners/components/CreditLimitWarning.tsx:22-30,61-69`, exactly matching CLAUDE rule 19’s prohibition at `CLAUDE.md:71-76`; this is correctly gated on the Phase-1 merge rather than silently accepted. |
| F-15 | RESOLVED | The known dependency is correctly deferred: r2 remains DRAFT, forbids dispatch without Phase 1, requires post-merge repinning of `base_sha`, anchors, and both migration timestamps, and stops if the progress file is absent (`brief:3-7,33-43,184-185,587-588`). This row does not re-report Phase 1’s known absence. See N-9 only for an omitted moving-anchor declaration. |
| F-16 | RESOLVED | M1 task 0 makes both proxy targets environment-configurable, requires launch against `:8011`, and requires a worktree-only response marker in every browser gate (`brief:96-106`). HEAD confirms both current hard-coded targets at `apps/web/vite.config.ts:21,25`. |
| F-17 | RESOLVED | r2 explicitly rejects the old migration as an idempotent template, requires catalog probes for columns/constraints/indexes, and tests normal reruns plus supported partial states (`brief:75-80,188-219,388-402`). The cited old migration is indeed unguarded at `2026_03_09_100001_add_customer_category_to_partners.php:20-34`. |
| F-18 | RESOLVED | r2 now requires the parent to add a LEDGER row at merge with owner, status, and closure evidence; `owes_parent` alone is declared insufficient (`brief:556-569`). This matches the binding sole-register rule at `docs/handoff/LEDGER.md:1-5`. |

## NEW findings

### N-1 — P1 — Derivation cannot represent an explicit kind change

**Evidence:** `PartyKindDerivationInput` includes only the existing kind and derived evidence, not the incoming/requested `party_kind` (`brief:154-160`). The ladder then preserves the existing kind unless other evidence changes it (`brief:161-165`). Consequently, an explicit organization→person request cannot be distinguished from a sparse update. The same passage treats incoming `type=both` as organization evidence while its required regression test says customer→both must not silently reclassify (`brief:163-167`). HEAD’s merge occurs before `updateOrCreate` at `apps/api/app/Modules/Partner/Application/Services/PartnerService.php:64-107`.

**Prescribed fix:** Add `requestedKind`/kind-presence to the typed input, define explicit-kind precedence, and distinguish persisted evidence from incoming evidence. Specify whether a role merge alone may reclassify; make the ladder and tests agree.

### N-2 — P1 — The UI transition contract cannot clear persisted incompatible fields

**Evidence:** M2 requires an organization-with-VAT→person PATCH to return 422 because of the persisted VAT (`brief:404-407`). M3 instead instructs RHF to unregister/reset the VAT field so the request carries **no** `vat_number`, then expects the same transition to submit (`brief:448-452,486-491`). At HEAD, omitted fields remain unchanged because update applies only validated submitted keys (`PartnerController.php:280-295`); the form currently spreads its retained values at `apps/web/src/features/partners/PartnerForm.tsx:397-420`.

**Prescribed fix:** Choose one atomic contract: either the UI sends explicit `null` values and the service clears them, or the service interprets an explicit kind transition as authorization to clear incompatible persisted fields. Align M2/M3 API, unit, and browser tests in both directions.

### N-3 — P1 — “Person with credit explicitly enabled” has no reliable state representation

**Evidence:** r2 says no new column is needed and cites the existing mirror derivation (`brief:433-443`). But the actual derivation is only `is_active && account_status === active`; it ignores `credit_limit` and any explicit approval at `apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:29-32,47-50`. Thus every active person appears charge-enabled, while r2 simultaneously describes a non-null credit limit and an explicit toggle.

**Prescribed fix:** Define the persisted source of explicit approval. If an existing field is overloaded, specify exact toggle write/clear semantics and update the mirror predicate and tests. If that cannot express approval without ambiguity, invoke the brief’s owner gate for a new column.

### N-4 — P1 — r2 overrides binding R-B without an owner amendment

**Evidence:** r2 mandates bare `en|fr|ar` and rejects region tags (`brief:270-276,446-447`). Binding ASSESSMENT R-B requires nullable ISO values such as `fr-TN`, `ar-TN`, and `fr-FR` (`ASSESSMENT-crm-seam-party-model-2026-08-29.md:33-40`), and D-H0-1 incorporates R-A..R-D (`LEDGER.md:208`). Current UI languages are indeed bare codes at `apps/web/src/lib/i18n.ts:154-160`, but current implementation does not override the binding ruling.

**Prescribed fix:** Implement the R-B region-tagged contract, including fallback to a supported UI language, or obtain and record an owner amendment to R-B before dispatch.

### N-5 — P1 — Seeders/factories bypass both the identity policy and R-A event seam

**Evidence:** r2 says every path, including seeders/factories, goes through `PartnerService` (`brief:264-285,305-319`), but M2.6 instead leaves them as direct Eloquent/factory writers (`brief:379-386`). HEAD contains direct writes at `TunisianParapharmacySeeder.php:322,348,366,383`, `DemoPharmacySeeder.php:827,839,1624`, and `DemoTenantSeeder.php:967`; `PartnerFactory.php:33-64` also directly constructs state. R-A expressly requires seeder-created parties to emit (`ASSESSMENT…md:37`).

**Prescribed fix:** Route production seeders through the typed service or define an equally centralized seeding API that applies policy and emits V2 events. Ensure `person()` clears tax identity. Expand the guard beyond only `Partner::create(` to all creation forms.

### N-6 — P1 — Migration B can fail on rows written between migrations

**Evidence:** Migration B reruns only the M1 `party_kind` backfill arms before adding the composite CHECK (`brief:388-395`); it does not explicitly rerun the `customer_category` recomputation from M1 step 3. An old worker can still insert a row without either field through `PartnerController.php:215-219` after Migration A. Migration B may assign `party_kind=person` while leaving `customer_category=NULL`, causing the composite CHECK to fail. This conflicts with the stated auto-deploy/self-guarding requirement (`brief:75-80`).

**Prescribed fix:** In Migration B, rerun both kind classification and category recomputation, then assert zero NULL/incoherent pairs immediately before `SET NOT NULL` and CHECK creation. Test an inter-migration legacy write on PostgreSQL.

### N-7 — P1 — Imported `legal_form` is omitted from kind-changing evidence

**Evidence:** M4 allows `legal_form` with absent `party_kind` and then invokes the deriver (`brief:502-513`). The derivation input/ladder omits `legal_form` (`brief:154-165`), while the identity policy forbids legal form on a person (`brief:277-280`). A bare customer row with `legal_form=sarl` therefore derives as person and is subsequently rejected or cleared.

**Prescribed fix:** Treat non-null `legal_form` as organization evidence, or require explicit `party_kind=organization` whenever it is supplied. Add an import regression case.

### N-8 — P1 — Device UUID normalization is deferred contrary to authoritative spec §5.4

**Evidence:** r2 explicitly forbids changing `hashCustomerUuid` and defers it to Phase 4 (`brief:238-242,563-564,590-591`). Spec §5.4 requires the shared normalizer at `customerAttachUtils` and byte-identical device/server normalization (`party-contact-target-model-research.md:330-342`). HEAD hashes trimmed but otherwise raw phone/email at `apps/pos/src/components/customers/customerAttachUtils.ts:19-38`, so formatting variants still mint different identities.

**Prescribed fix:** Add a versioned normalized UUID path for newly enqueued customers while preserving already-enqueued UUIDs, with alias/idempotency tests; otherwise obtain a binding amendment that explicitly defers this part of §5.4.

### N-9 — P2 — The Phase-1 moving-anchor warning is incomplete

**Evidence:** r2’s moving-anchor list names `PartnerForm`, `PartnerListPage`, `AddPartnerModal`, routes, `ImportType`, and `VehicleForm` (`brief:39-43`). Phase 1 also edits `apps/web/src/features/partners/components/B2BFieldsSection.tsx` and `CreditLimitWarning.tsx` (`CODEX-DISPATCH-session-H-phase1-2026-08-29.md:171-185`). r2 relies on the former’s structure in M3.2 (`brief:433-443`) without declaring that its anchors will move. `CreditLimitWarning` is separately acknowledged, so only the B2B section is an undeclared moving anchor.

**Prescribed fix:** Add `B2BFieldsSection.tsx` to the post-Phase-1 repin list and require its split tax/credit layout anchors to be reverified in round 3.

### N-10 — P2 — ACCOUNT_PAYMENT and ACCOUNT_CHARGE validator labels are swapped

**Evidence:** r2 calls `FiscalPayloadConstraintValidator.php:1725` ACCOUNT_CHARGE and `:1951` ACCOUNT_PAYMENT (`brief:333-338`). At HEAD, `:1722-1726` is `validateAccountPaymentCustomer`, while `:1948-1952` is `validateAccountChargeCustomer`.

**Prescribed fix:** Swap the labels while retaining both key-set assertions.

## Disputes

1. The bare-locale decision conflicts with binding R-B; current code evidence is not authority to amend an owner-accepted reservation.
2. Deferring normalized device UUID input conflicts with spec §5.4 and requires either implementation or a recorded amendment.
3. Exempting direct seeder/factory writes conflicts with binding R-A’s explicit requirement that seeder-created parties emit.
4. OQ10 is not disputed: r2 correctly keeps it pending and defaults to option (a).

VERDICT: CHANGES-REQUIRED

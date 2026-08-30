<!-- Codex CLI read-only adversarial gate, round 10 (post-merge FINAL), Session H orchestrator 2026-08-30; brief r10 at 044e07752, base 31f49e4f4. -->

# Round-10 post-merge final-gate register

**Brief:** `docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md` — revision r10  
**Brief HEAD SHA:** `044e0775247a11593a5e3b64bd530d6d5fbaf90e`  
**Base SHA:** `31f49e4f47ed4f80dcb55d48436f233312014f99`  
**Verification worktree:** `.worktrees/h2-party-kind` — HEAD exactly matches base SHA

## Anchor sample

| Anchor | OK / WRONG → correct |
|---|---|
| Industry B1 — `Partner.php:102,151` | OK |
| Industry B3 — `CreatePartnerRequest.php:88-92` | OK |
| Industry B3 — `PartnerForm.tsx:617` | OK |
| Industry B5 — `PosCustomerMirrorResource.php:30-32,50` | OK |
| Industry B7 — `LoyaltyMember.php:112-118` | OK |
| Industry B7 — `PartnerService.php:98-107` matching ladder | OK |
| Industry B7 trailing “do not touch `:83-92`” | **WRONG → `:98-107`** |
| Second-company — unique migration `:19-23` | OK |
| Second-company — `PartnerController.php:118` | OK |
| Second-company — `PosCustomerSyncController.php:42-45` | OK |
| Concepts — `docs/glossary.md:15-16,26,29` | OK |
| §0 — customer-category migration `:20-34` | OK |
| §0.2 — `PartnerForm.tsx:568-580` Nature control | OK |
| §0.2 — `PartnerForm.tsx:183-190,236,240` zod/defaults | OK |
| §0.2 — `partnerNature.ts:12` | OK |
| §0.2 — `PartnerForm.tsx:277,730` helper call/render | OK |
| §0.2 — `CreditLimitWarning.tsx:4,25,32-33` | OK |
| §0.2 — `B2BFieldsSection.tsx:10,179` | OK |
| §0.2 — `PartnerForm.tsx:53` write-path flag | OK |
| §0.2 — `PartnerForm.tsx:449-451` payload strip | OK |
| §0.2 — `routes/index.tsx:596,606,616,626` | OK |
| §0.2 — `routes/index.tsx:845,855,865,875` | OK |
| §0.2 — `CreatePartnerRequest.php:167` deleted-code message | OK |
| §0.2 — `UpdatePartnerRequest.php:202` deleted-code message | OK |
| §0.2 — `ImportService.php:508-517` | OK |
| §0.2 — `PartnerServiceInterface.php:21` | OK |
| §0.2 — `PartnerService.php:19` | OK |
| §0.2 — `AppServiceProvider.php:107-108` | OK |
| §0.2 — `PosCoreReceiptProjection.php:96-99` | OK |
| §0.2 — `PosCoreReceiptProjection.php:425-427,1695` | OK |
| §0.1 — `vite.config.ts:21,25` | OK |
| §1 — POS migration `migrations.ts:1177,2163` | OK |
| §1 — `generated.d.ts:1859` | OK |
| M1 — `CustomerCategory.php:15-18` | OK |
| M1 — tax-fields migration `:15-17` | OK |
| M1 — `Partner.php:100-141,147-151` | OK |
| M1 — `PartnerData.php:21-65,67` | OK |
| M1 — `CreatePartnerRequest.php:69-88` | OK |
| M1 — `PartnerController.php:207-219` | OK |
| M2 — `PartnerService.php:77-147` method | OK |
| M2.2 — update payload `PartnerService.php:88-104` | **WRONG → `:126-144`** |
| M2.2 — matching ladder `PartnerService.php:67-69` | **WRONG → `:98-107`** |
| M2.3 — `PartnerService.php:126` insertion | OK |
| M2.3/M4 — `PartnerServiceInterface.php:12-53` | **WRONG → `:12-52`** |
| M2.3 — `PartiesRowMapper.php:21-22` raw email/phone | OK |
| M2.3 — `PartnerService.php:133-134` raw email/phone | **WRONG → `:134-135`** |
| M2.4 — `PosCustomerMirrorResource.php:42` | OK |
| M2.4 — `VirtualAdminFiscalEventService.php:242` | OK |
| M2.4 — validator key sets `:700,1724,1950` | OK |
| M2.4 — validator coherence rule `:1942` | OK |
| M2.4 — `AccountPaymentPayload.ts:116` | OK |
| M3 — `PartnerForm.tsx:617,663-690` | OK |
| M3 — `PartnerForm.tsx:444-470` | OK |
| M3 — `PartnerListPage.tsx:197-201,239` | OK |
| M3 — `PartnerController.php:69-100` | OK |
| M3 — `AddPartnerModal.tsx:212` | OK |
| M3 — `api.ts:397-399` | OK |
| M3 — `i18n.ts:250,302-322,428` | OK |
| M3 — `locales/en/sales.json:58-62` | OK |
| M3 — `locales/fr/sales.json:58-62` | OK |
| M3 — `locales/ar/sales.json:98-102` | OK |
| M4 — `ImportType.php:122-135` | OK |
| M4 — `ImportType.php:179,182-194` | OK |
| M4 — `PartiesRowMapper.php:15-30` | OK |
| M4 — `ImportService.php:34-52,110,138,169` | OK |
| M4 — `ImportController.php:267-269` | OK |
| M4 — `ImportController.php:410-415` | OK |
| M4 — `ResultWorkbookService.php:61,74,114-122` | OK |
| M4 — `ImportController.php:857-881` | OK |
| M5 — `git diff 31f49e4f4..HEAD` base | OK |
| §4 — `LEDGER.md:1` | OK |
| §5 — `PartnerService.php:98-107` | OK |
| §5 claim that old `:67-69` is input preparation | **WRONG → input preparation is `:82-85`** |

## §0.2 Phase-1 reconciliation

| Item | Status | Evidence and dependent-instruction check |
|---|---|---|
| 1. Nature select on `customer_category` | OK | Control, zod resolver, supplier default and M3.1 repoint instructions agree. |
| 2. Extracted B2B heuristic | OK | `partnerNature.ts:12`, call at `PartnerForm.tsx:277`, render at `:730`; M3.2 correctly retires it. |
| 3. Decimal-safe, wired `CreditLimitWarning` | OK | Decimal imports/comparisons and B2B rendering verified; M3 no longer asks to wire it. |
| 4. Hidden/stripped tax-status path | OK | Flag is false and `onSubmit` strips the trio; M2 rules plus M3 rename/delete-strip/flip sequence is internally consistent. |
| 5. Existing route gates | OK | All customer/supplier list, detail, create and edit permissions match; M1–M5 do not redundantly re-gate them. |
| 6. Company-scoped code uniqueness | OK | Create/update rules omit `deleted_at`; distinct deleted-holder messages exist. M4 contains the required importer constraint. |
| 7. a1 contracts | OK | `resolveScopedPartnerId`, `ContactResolverInterface`, implementations and bindings exist. M2 extends the existing seam and adds no parallel resolver. |
| 8. Populated receipt `partner_id` | OK | Projection resolves the sealed buyer ID and writes `partner_id`; M2.4’s value-path and no-key-set-change instructions remain consistent. |
| 9. Phase-1 residual debts | OK | Quick-create Nature → M3.4; tax path → M2.2/M3; Arabic review → M3.5. |

## Other gate checks

| Check | Result |
|---|---|
| Migration A `2026_08_30_101000_add_party_kind_to_partners.php` | FREE; ordered after current last migration `2026_08_30_100800_…` |
| Migration B `2026_08_30_101100_constrain_party_kind_on_partners.php` | FREE; ordered after Migration A |
| Progress YAML syntax/base | Valid; base SHA matches |
| YAML milestones and lenses | M1–M5 exactly match the brief |
| OQ10 | Deferred/default `(a)` recorded |
| a1 re-apply ordering | Recorded in brief and YAML |
| Conditional M4 STOP | Recorded |
| `tax_status` sealed-path census | Zero hits across every requested server and POS path |
| Additional architecture/test contradiction | None found |

## Findings

| Finding | Severity | Evidence | Required fix |
|---|---|---|---|
| N-40 | MAJOR | Five active `PartnerService`/interface anchors remain stale. Most importantly, M2.2 points the requested `updateOrCreate` change at `:88-104`, which overlaps VAT lookup and the matching ladder, while simultaneously misplacing the protected ladder at `:67-69`. | Repin payload to `PartnerService.php:126-144`, matching ladder to `:98-107`, raw email/phone to `:134-135`, interface to `:12-52`, and input preparation to `:82-85`. |
| N-41 | MAJOR | The current checkout contains r10 and `docs/handoff/progress/session-h-phase2.progress.yaml`, but the actual execution worktree at `31f49e4f4` contains the r9 brief and no progress YAML. Dispatching that worktree as-is immediately triggers the brief’s “if it does not exist — STOP” gate and exposes the worker to stale pre-repin instructions. | Before dispatch, materialize the r10 brief and progress YAML in `feat/h2-party-kind` via a docs-only commit or an explicit equivalent dispatch step; retain `31f49e4f4` as `base_sha`, then verify the worktree reads r10 and the YAML. |

VERDICT: CHANGES-REQUIRED

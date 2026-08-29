<!-- Codex CLI read-only adversarial gate, round 5, Session H orchestrator 2026-08-29; brief r5 at c3b733a44. -->

<!-- Read-only adversarial gate, round 5; no files edited. -->

# Round-5 adversarial gate register

**Brief:** `docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md` — revision r5  
**HEAD:** `c3b733a4445baf7eae42a7976490fed3bc2ff699`

## Prior-finding resolution

| Finding | Status | Resolution and code evidence |
|---|---|---|
| N-17 | RESOLVED | The guard now explicitly exempts `tests/**` from Tier 1 and from an exact Tier-2 path census (`brief:557-579`), matching the binding disposition. Fresh census: zero factory consumers in `apps/api/app`, eight under `apps/api/database`, and 135 test files. The eight database paths match the brief, including both previously omitted seeders. The stated Tier-1 test count has drifted from 222 to 228, but the count is not a gate because tests are exempt. |
| N-18 | RESOLVED | `tax_id` is present in the typed derivation input and `has_b2b_datum` arm (`brief:167-200`), and Migration A classifies before repairing person fields with a tax-id-only retention regression (`brief:261-264`). The column is real and nullable at `apps/api/database/migrations/tenant/2026_01_09_111429_add_tax_fields_to_partners_table.php:15`. |
| N-19 | PARTIAL | The canonical four-field `ORGANIZATION_CLEARED_FIELDS` set is defined correctly (`brief:396-400`), but the operative transition instruction still clears only `date_of_birth`, `gender`, and `national_id`, omitting `mobile` (`brief:411-420`). The concrete M3 browser gate also specifies only organization→person, not the reverse transition that would prove all four fields (`brief:722-732`). |
| N-20 | PARTIAL | Migration B now says every compatibility backfill is restricted to legacy rows with `party_kind IS NULL` and adds preservation regressions (`brief:589-597`). It does not preserve that candidate set before classification changes `party_kind` to non-NULL, so later category/credit/tax updates can match no rows if implemented sequentially. See N-30. |
| N-21 | RESOLVED | Both FormRequests are explicitly widened for canonical tax/exemption/withholding fields and the frontend aliases are renamed (`brief:439-455`). Current code confirms the defect being addressed: those request rules are absent at `CreatePartnerRequest.php:66-133` and `UpdatePartnerRequest.php:83-168`, while `PartnerForm.tsx:103-105,414-415` still uses `exemption_reason` and `exemption_valid_until`. See N-32 for the separate module-boundary problem in the prescribed enum rule. |
| N-22 | PARTIAL | The full mutation envelope via `api.patch` is now specified correctly (`brief:669-673`), matching current `apiPatch()` metadata loss at `apps/web/src/lib/api.ts:397-399`. The dialog contract remains contradictory: `brief:674-676` limits it to fields visible to the UI yet requires actual server clears to be a subset, while `brief:686-688` returns to “matches what the dialog promised.” A hidden persisted field can therefore invalidate either assertion. |
| N-23 | RESOLVED | r5 explicitly preserves byte-identical V1 dispatch while adding typed V2 events, removes controller duplication, and replaces V2’s mixed changes array with `list<PartnerChange>` (`brief:520-540`). Current V1 dispatch remains controller-owned at `PartnerController.php:230-239,309-316,382-387`, confirming the migration target. |
| N-24 | RESOLVED | Withholding organization-only status is recorded as the binding OQ7/D-4 consequence and expressly not a reviewer or milestone STOP (`brief:388-394`). |
| N-25 | PARTIAL | The typed upsert result and `legalFormValues()` shared-contract seam are specified (`brief:747-755`). However, the preceding operative rule still directly calls `LegalForm::values()` inside the Import module (`brief:740-743`), and the proposed contract call has no injectable location in the parameterless enum method. See N-31. |
| N-26 | PARTIAL | `PartnerTaxRegime` with parity coverage and the model cast are specified (`brief:155-160,318-324`), and the policy table uses `PartnerTaxRegime::Individual` (`brief:377-385`). The runtime transition instruction still explicitly writes the frozen string `'individual'` (`brief:414-416`), contradicting “use the case, never the literal” at `brief:160`. Migration SQL may retain frozen literals; runtime policy may not. |
| N-27 | RESOLVED | Migration B freezes all six legal-form literals inline and delegates enum comparison to a PostgreSQL parity test (`brief:601-605`). |
| N-28 | RESOLVED | The repin census now includes `PartnerData.php`, `generated.d.ts`, all three sales locale files, and `ImportController.php` (`brief:39-50`). `packages/shared/types/generated.d.ts` is the real generated file, and `ImportController.php` has changed since the cited `a33b01354` anchor. |
| N-29 | RESOLVED | M1 now censuses the complete population (`brief:330-340`), M4 mandates the Playwright request flow and forbids PHPUnit substitution (`brief:780-790`), and M5 is an explicit accumulated-branch gate over all four lenses (`brief:796-812`). |

## NEW findings

### N-30 — P1 — Migration B loses its legacy candidate set during classification

**Evidence:** r5 requires classification, category recomputation, credit backfill, and person-tax repair all to be scoped to `WHERE party_kind IS NULL` (`brief:584-597`). The first classification update makes every matched row non-NULL. A subsequent category, credit, or tax update using the same predicate matches zero rows, leaving an incoherent pair or unrepaired fiscal state and causing the assertions/CHECK installation at `brief:598-615` to fail.

**Prescribed fix:** Materialize the candidate IDs and derived kind before the first mutation, or perform classification and every compatibility value in one atomic SQL update. Add a PostgreSQL regression proving a NULL-kind old-worker row receives kind, category, credit compatibility, and person-tax repair together.

### N-31 — P1 — The M4 legal-form validation seam is internally contradictory and not injectable

**Evidence:** `brief:742` directs `ImportType::Parties` to use `Rule::in(LegalForm::values())`; `brief:747-754` forbids that Partner-domain import and instead shows `$partnerService->legalFormValues()`. The actual validation rules live in parameterless `ImportType::getValidationRules()` at `apps/api/app/Modules/Import/Domain/Enums/ImportType.php:166`, where constructor injection is impossible. Its callers invoke it without dependencies at `ImportService.php:84,112,143` and in multiple tests.

**Prescribed fix:** Remove the direct `LegalForm` rule from `ImportType`. Put Parties validation assembly in an injectable Import application service that receives `PartnerServiceInterface`, merges `legalFormValues()` into the base rules, and is used consistently by upload, execution, normalization, and tests.

### N-32 — P1 — The new Partner FormRequest rule adds a forbidden cross-module domain dependency

**Evidence:** r5 requires Partner FormRequests to validate `tax_status` with `Enum(PartnerTaxStatus::class)` (`brief:439-446`). That enum belongs to `App\Modules\Taxation\Domain\Enums` (`PartnerTaxStatus.php:5-11`). Adding that import to Partner presentation directly violates CLAUDE rule 6. The existing Partner model already carries this architectural debt at `apps/api/app/Modules/Partner/Domain/Partner.php:15,158`; r5 would expand it on a newly touched boundary.

**Prescribed fix:** Promote the cross-cutting partner tax-status enum to `App\Shared\Domain\Enums` and update Partner and Taxation consumers, or expose validation through a compliant shared/public contract. Do not add another direct Partner→Taxation domain import.

## Holistic executability and STOP audit

Beyond OQ10 and the known Phase-1 merge gate:

1. **N-31 forces `blocked_architecture` if the brief is followed literally:** its two instructions require both importing and not importing Partner domain logic, while the designated enum cannot receive the service.
2. **N-32 forces `blocked_architecture` unless the tax-status ownership seam is corrected:** the prescribed new request dependency violates rule 6.
3. The progress file is currently absent. This is an expected parent-created dispatch prerequisite, but execution from this HEAD must STOP under `brief:17-19,854-855`.
4. M4 still has the explicit conditional owner STOP if the Playwright multipart/download/XLSX flow genuinely cannot be made to work (`brief:786-790`).
5. N-19, N-20, N-22, and N-26 require brief corrections but no new owner ruling.

## Disputes

None with the binding r4 dispositions. OQ10 remains pending with executable default (a); N-17’s test exemptions, N-24’s D-4 ruling, and the known F-15/post-Phase-1 re-gate were not relitigated.

VERDICT: CHANGES-REQUIRED

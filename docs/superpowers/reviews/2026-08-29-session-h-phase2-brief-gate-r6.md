<!-- Codex CLI read-only adversarial gate, round 6, Session H orchestrator 2026-08-29; brief r6 at 145433173. -->

# Round-6 adversarial gate register

**Brief:** `docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md` — revision r6  
**HEAD:** `145433173cbf68be8ae9b5ad89b284083300c260`

## Prior-finding resolution

| Finding | Status | Resolution and code evidence |
|---|---|---|
| N-19 | RESOLVED | `ORGANIZATION_CLEARED_FIELDS` contains all four fields (`brief:397-401`); runtime policy references the named set and includes `mobile` (`:419-426`); the reverse browser gate asserts all four individually (`:758-761`). |
| N-20 | RESOLVED | Migration B now materializes the NULL-kind candidate set before mutation, then joins that set or uses one atomic update (`brief:608-624`). This preserves intentional non-NULL edits while covering old-worker rows created through the current direct insert at `PartnerController.php:215-219`. |
| N-22 | PARTIAL | The full mutation envelope and hidden-clear toast are specified (`brief:696-700,713-716`), matching the metadata loss in `apps/web/src/lib/api.ts:397-399`. However, `brief:701-703` still requires `meta.cleared_fields ⊆ dialog`, while `:713-716` correctly requires the binding `dialog ⊆ server`. Those assertions conflict whenever the server clears a hidden persisted field. |
| N-25 | RESOLVED | M4 now keeps Partner domain classes out of Import and exposes the typed upsert result plus `legalFormValues()` through `PartnerServiceInterface` (`brief:783-798`). The existing injection seam is real at `ImportService.php:29-43` and `ImportServiceProvider.php:37-52`. |
| N-26 | RESOLVED | Runtime transition policy now uses `PartnerTaxRegime::Individual`; only migration SQL retains the literal (`brief:415-418`). The five cases match the live enum column at `2026_01_09_111429_add_tax_fields_to_partners_table.php:16-17`. |
| N-30 | RESOLVED | Candidate derivation is captured before classification, and the required PostgreSQL regression asserts kind, category, credit compatibility and person-tax repair together (`brief:608-624`). |
| N-31 | RESOLVED | `PartiesValidationRulesFactory` is injectable and is required at all three current rule sites (`brief:789-797`; current sites `ImportService.php:84,112,143`). `ImportType::getValidationRules()` remains parameterless at `ImportType.php:166`. |
| N-32 | RESOLVED | Both FormRequests use `Shared/Contracts/PartnerTaxStatusValues` with an enum parity test, and the enum move is explicitly deferred as debt (`brief:432-446,461-465`). The underlying enum is confirmed in Taxation at `PartnerTaxStatus.php:5-11`. See N-33 for a separate r6-introduced policy dependency. |

## NEW findings

### N-33 — P1 — r6 reintroduces the forbidden Partner→Taxation dependency in `PartyIdentityPolicy`

**Evidence:** The N-32 resolution forbids adding `PartnerTaxStatus` imports to Partner and selects `Shared/Contracts/PartnerTaxStatusValues` instead (`brief:432-446`). But r6 newly instructs the Partner-domain `PartyIdentityPolicy` to use `PartnerTaxStatus::NON_REGISTERED` (`brief:415-417`). The enum belongs to `App\Modules\Taxation\Domain\Enums` (`PartnerTaxStatus.php:5`), so following the brief requires a new direct Partner→Taxation domain import, contrary to `CLAUDE.md:30-31`. The existing import in `Partner.php:15,158` is acknowledged debt; it does not authorize expanding that debt. r5 used a literal here, so this contradiction was introduced by r6.

**Fix:** Add named constants such as `PartnerTaxStatusValues::NON_REGISTERED` alongside `VALUES`, construct `VALUES` from those constants, and use the shared-contract constant inside `PartyIdentityPolicy`. Retain `PartnerTaxRegime::Individual`, which is Partner-owned. Keep the parity test against `PartnerTaxStatus::cases()`.

## Executability and STOP audit

- N-33 is an architecture contradiction and therefore forces a STOP under `brief:26-28` if r6 is followed literally.
- N-22 leaves mutually incompatible browser assertions; it requires a brief correction but no new owner ruling.
- The progress YAML is absent at this HEAD, so execution must STOP until the parent creates it (`brief:18-20,911-912`).
- The exact sealed-payload/POS-mirror `tax_status` census remains empty; §5 condition 4b is not triggered.
- No additional STOP was found beyond N-33, the missing dispatch progress file, OQ10/default (a), the Phase-1 merge gate, and the recorded conditional M4 STOP.

## Disputes

None. OQ10’s default (a), D-4 withholding ruling, N-32’s shared-contract branch, and F-15’s post-merge final gate were not reopened.

VERDICT: CHANGES-REQUIRED

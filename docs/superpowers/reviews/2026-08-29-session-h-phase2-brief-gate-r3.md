<!-- Codex CLI read-only adversarial gate, round 3, Session H orchestrator 2026-08-29; brief r3 at ee0fbe077. -->

# Round-3 adversarial gate register

**Brief:** `docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md` revision r3  
**HEAD:** `ee0fbe0777933c4627e29a66e8785531b20837d0`

Evidence was read from HEAD. Verified repo-root paths are `apps/api/app/Modules/Partner/…`, `apps/pos/src/lib/…`, and `apps/web/src/features/partners/PartnerForm.tsx`.

## Prior-finding resolution

| Finding | Status | Resolution and HEAD evidence |
|---|---|---|
| F-2 | RESOLVED | M1 now explicitly expects omitted-kind POST to remain NULL and moves coherent derivation to M2 (`brief:284-292`). Migration B runs only after writer conversion and asserts coherence (`brief:456-480`). Current HTTP creation indeed persists only validated fields (`apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php:207-219`). |
| N-1 | PARTIAL | `requestedKind` and `kindProvided` now have explicit precedence and persisted/incoming evidence is separated (`brief:156-182`). However, `requestedKind` is nullable while the first arm returns it as a non-null `PartyKind`; see N-16. |
| N-2 | PARTIAL | r3 chooses atomic clearing, `meta.cleared_fields`, and a pre-submit confirmation (`brief:327-336,525-533`). The M2 gate still expects that exact transition to return 422, and the clearing set is not schema-correct; see N-11–N-13. Current PATCH behavior only writes submitted validated keys (`PartnerController.php:280-295`). |
| N-3 | PARTIAL | The new persisted `credit_account_enabled` column, active-account backfill, mirror predicate, and UI toggle are specified (`brief:220-228,509-520`). It is absent from the enumerated DTO/request work, and the golden mirror assertion contradicts the category rewrite; see N-14–N-15. Current mirror enablement is only `is_active && active` (`apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:29-32`). |
| N-4 | RESOLVED | r3 restores region-tagged BCP-47 values, normalizes underscores, sanctions `ar-TN`, and defines language-subtag fallback (`brief:306-322,523-524`). The actual source contains `fr_TN` (`apps/api/database/seeders/CountriesSeeder.php:19-29`) and the schema permits one locale per country (`apps/api/database/migrations/tenant/2025_12_01_192409_create_countries_table.php:15-24`). |
| N-5 | PARTIAL | Production seeders are routed through `PartnerService`/`PartnerSeedingService`, and an expanded eight-form guard is required (`brief:432-453`). The factory person state does not clear the complete tax identity, and the guard’s allowlist contradicts declared legal factory consumers; see N-13 and N-17. Current direct writes remain visible at `apps/api/database/seeders/TunisianParapharmacySeeder.php:322-383` and `DemoTenantSeeder.php:967`. |
| N-6 | RESOLVED | Migration B now reruns kind, category, and credit-flag recomputation, asserts zero NULL/incoherent pairs, and includes an inter-migration legacy-write test (`brief:456-476`). The cited legacy writer genuinely can omit both fields (`PartnerController.php:215-219`). |
| N-7 | RESOLVED | `legal_form` is kind-changing evidence with its own reason code and regression coverage (`brief:160-182,590-597`). The current mapper is the correct M4 target (`apps/api/app/Modules/Import/Services/PartiesRowMapper.php:15-28`). |
| N-8 | RESOLVED | Binding Amendment A-1 is recorded: the device normalizer ships for display/matching while UUID seed normalization remains deferred with alias/idempotency work (`brief:264-275`). The current seed hashes raw trimmed phone/email (`apps/pos/src/components/customers/customerAttachUtils.ts:19-38`). |
| N-9 | RESOLVED | Both moving component paths are now named and require post-merge anchor verification (`brief:39-45,512-514`). Both exist at HEAD under `apps/web/src/features/partners/components/`. |
| N-10 | RESOLVED | Validator labels are corrected (`brief:388-395`): ACCOUNT_PAYMENT is `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php:1722-1726`; ACCOUNT_CHARGE is `:1948-1952`. |

## NEW findings

### N-11 — P0 — The person tax-status invariant is impossible under the existing schema

**Evidence:** r3 requires every person’s tax status and exemption fields to be NULL and explicitly clears `tax_status` during organization→person transitions (`brief:323-335`). But `partners.tax_status` is `NOT NULL DEFAULT 'REGISTERED'` (`apps/api/database/migrations/tenant/2026_01_02_100001_add_tax_exemption_to_partners.php:17-20`). Migration A assigns legacy persons without changing that column, and Migration B does not repair it. Consequently, backfilled persons violate the stated final-state policy, while an atomic transition that writes NULL fails at the database boundary.

**Prescribed fix:** In Migration A, guardedly make `tax_status` nullable and backfill it to NULL for persons; repeat the person-side repair in Migration B before constraints. Add PostgreSQL tests covering legacy backfill, person create, and organization→person transition.

### N-12 — P1 — The M2 gate still asserts the rejected N-2 behavior

**Evidence:** The chosen contract says an explicit kind transition authorizes atomic clearing and returns `meta.cleared_fields` (`brief:327-336`). The M2 browser/API gate still requires organization-with-VAT→person to return 422 naming the persisted field (`brief:478-483`). M3 expects the same operation to succeed (`brief:525-533`).

**Prescribed fix:** Change M2 gate item (iii) to expect success, persisted fields cleared, and an exact `meta.cleared_fields` list. Retain 422 only for a same-kind request that submits an incompatible field.

### N-13 — P1 — Atomic and factory clearing use incomplete or nonexistent persisted field names

**Evidence:** r3 tells the service to clear `exemption_reason`, `exemption_certificate_path`, and `exemption_valid_until` (`brief:330-332`). The actual columns are `tax_exemption_reason`, `tax_exemption_certificate_media_id`, and `tax_exemption_valid_until` (`apps/api/app/Modules/Partner/Domain/Partner.php:119-126`). The factory `person()` prescription clears only `vat_number`, `legal_form`, `company_legal_name`, and `business_registration_number` (`brief:440-444`), omitting the real `tax_id` column (`apps/api/database/migrations/tenant/2026_01_09_111429_add_tax_fields_to_partners_table.php:14-18`) and the tax-status/exemption fields.

**Prescribed fix:** Define one canonical persisted-field set in `PartyIdentityPolicy`; use its exact column names for transitions, imports, `meta.cleared_fields`, and `PartnerFactory::person()`. Add `tax_id` to the model write seam and test every cleared column.

### N-14 — P1 — `credit_account_enabled` is not carried through the API/type boundary

**Evidence:** M3 requires reading and writing the new toggle (`brief:509-520`), but M1.4’s enumerated `PartnerData` additions omit it (`brief:277-282`), and M2.2’s Create/Update request widening also omits it (`brief:343-351`). The current DTO has no such property (`apps/api/app/Modules/Partner/Application/DTOs/PartnerData.php:20-60`), while controllers persist only validated input (`PartnerController.php:207-219,280-295`).

**Prescribed fix:** Explicitly add the boolean to model fillable/casts, `PartnerData::fromModel`, generated TypeScript, typed service input, both FormRequests, form schema/defaults, and create/update/authorization tests.

### N-15 — P1 — The M1 golden mirror test cannot pass alongside the required category correction

**Evidence:** Migration A intentionally recomputes `customer_category` and acknowledges that the mirror wire value changes for reclassified rows (`brief:215-218`). The same milestone requires the entire `PosCustomerMirrorResource` JSON to be byte-identical before and after for every demo partner (`brief:240-244`). That resource includes `customer_category` (`apps/api/app/Modules/POS/Presentation/Resources/PosCustomerMirrorResource.php:34-50`).

**Prescribed fix:** Pin only the `charge_account_enabled` value and mirror key set for N-3. For `customer_category`, assert the explicitly expected old→derived value delta; retain canonical-byte proofs separately in M2.4.

### N-16 — P1 — The derivation ladder can return nullable input as a non-null result

**Evidence:** `requestedKind` is declared `?PartyKind`, and the pair is said to model explicit NULL (`brief:156-160`). The result is declared `readonly PartyKind $kind`, yet arm 1 says `kindProvided === true → requestedKind` without a null branch (`brief:162-169`). This cannot be implemented type-safely for `(true, null)`.

**Prescribed fix:** Declare and test one rule: either `kindProvided=true` requires non-null `requestedKind`, or explicit NULL is treated as absent/rejected before derivation. Do not allow `(true, null)` into the ladder.

### N-17 — P2 — The eight-form grep guard contradicts its own legal factory census

**Evidence:** r3 says `Partner::factory(` is allowed only in `PartnerService`, `PartnerSeedingService`, and `PartnerFactory` (`brief:445-448`), then declares its uses in other factories and seeders legal (`brief:449-453`). Actual examples include `apps/api/database/factories/DocumentFactory.php:47`, `PaymentFactory.php:46`, and `apps/api/database/seeders/DatabaseSeeder.php:375-405`. A literal guard with the stated allowlist therefore fails immediately.

**Prescribed fix:** Give the guard an explicit allowlist for factory-mediated test/seeder consumers while continuing to reject direct terminal model writes outside the service seams. Pin each allowed path in the guard test.

## Flagged locale gaps

Both are handled correctly:

- POSIX underscore: the source really stores `fr_TN` (`CountriesSeeder.php:27`), and r3 explicitly normalizes `_` to `-` before storage (`brief:314-318`).
- Missing `ar-TN`: Tunisia has only the single `fr_TN` row and the schema has one `default_locale` column per country (`create_countries_table.php:15-24`). r3 correctly constructs the closed set as normalized active-country locales union `{ar-TN}` (`brief:316-318`).

## Other requested lenses

- Sealed keys: the corrected PHP key sets match the device sets; no new key-set mutation is prescribed. N-15 is the remaining mirror-proof defect.
- Rule 8: r3 preserves frozen V1 event classes, adds V2 classes, and requires both Laravel dispatch and stored-event payload assertions (`brief:410-430`). No additional Rule-8 finding.
- Rule 19: Phase 2 remains forbidden from wiring the current `parseFloat` implementation; the known Phase-1 dependency is not re-reported.
- Tenancy/authz: existing partner routes carry Sanctum, tenant-claim, and permission middleware (`apps/api/app/Modules/Partner/routes.php:20-53`), and reads/updates are company-scoped (`PartnerController.php:117-119,263-265`). No new r3 tenancy gap found.
- i18n: EN/FR/AR same-commit requirements and language-subtag fallback are explicit.
- Browser feasibility: the `:5174 → :8011` arrangement is feasible after M1.0’s proxy change. Specs override the current Playwright `:5173` base (`apps/web/playwright.config.ts:10-24`), and the response marker distinguishes the worktree API. N-12, rather than port isolation, is the browser-gate blocker.

## Disputes

None. OQ10 remains pending with binding default (a). Amendment A-1 and all other stated orchestrator dispositions were treated as binding.

VERDICT: CHANGES-REQUIRED

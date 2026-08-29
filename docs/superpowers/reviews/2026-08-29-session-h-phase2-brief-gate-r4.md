<!-- Codex CLI read-only adversarial gate, round 4, Session H orchestrator 2026-08-29; brief r4 at a8d4cf24a. -->

# Round-4 adversarial gate register

**Brief:** `docs/handoff/CODEX-DISPATCH-session-H-phase2-party-kind-2026-08-29.md` — revision r4  
**HEAD:** `a62e03ec23991f872d5d82903792cd0c346b5e3f`  
**Mode:** read-only; no files edited.

## Prior-finding resolution

| Finding | Status | Resolution and code evidence |
|---|---|---|
| N-11 | RESOLVED | r4 uses schema-valid values rather than nullability changes: `tax_status='NON_REGISTERED'`, `tax_regime='individual'`, and `withholding_exempt=false` (`brief:247-254,356-364,381-386`). The schema confirms all three are NOT NULL/defaulted: `2026_01_02_100001_add_tax_exemption_to_partners.php:19`, `2026_01_09_111429_add_tax_fields_to_partners_table.php:16-18`, `2026_01_08_172040_add_withholding_fields_to_partners.php:15`. Fresh grep found zero `tax_status` occurrences in the named mirror/sealed paths. |
| N-12 | RESOLVED | The atomic contract is consistent between M2.1 and the M2 gate: explicit org→person transition returns 200, clears in one transaction, and returns exact `meta.cleared_fields`; same-kind incompatible input remains 422 (`brief:381-391,547-557`). |
| N-13 | RESOLVED | A single `PartyIdentityPolicy::PERSON_CLEARED_FIELDS` table now uses the actual persisted column names and target values, and is assigned to transitions, imports, metadata, and `PartnerFactory::person()` (`brief:350-365,501-505`). Current model evidence confirms the exemption/withholding names at `Partner.php:119-126`. |
| N-14 | RESOLVED | `credit_account_enabled` is explicitly carried through migration, model fillable/cast, `PartnerData::fromModel`, generated types, typed service input, both FormRequests, form schema/defaults, mirror predicate, and create/update/authorization tests (`brief:228-236,300-309,397-412,583-594`). |
| N-15 | RESOLVED | The impossible whole-resource golden was replaced by a key-set pin, per-row `charge_account_enabled` equality, and an explicit category before/after table (`brief:262-268`). This matches the current mirror’s category and charge fields at `PosCustomerMirrorResource.php:42,50`. |
| N-16 | RESOLVED | `(kindProvided=true, requestedKind=null)` is rejected in the DTO constructor, and explicit HTTP null is separately required to return 422 (`brief:162-169,405-408,557`). |
| N-17 | PARTIAL | The two-tier shape is present (`brief:507-520`), but the stated allowlist is not executable as written. Tier 2 names only `database/seeders/DatabaseSeeder.php`, while live factory consumers also exist in `ParapharmacySeeder.php:1229,1241,1256` and `TunisianParapharmacySeeder.php:401,410`. Tier 1 would also fail on direct terminal writes in 228 current test files unless r4 explicitly orders their conversion or permits them. Replace the wildcard prose with an exact generated/pinned path census and decide how legacy test fixtures are handled. |
| r4 self-found: `tax_regime` / `withholding_exempt` NOT NULL defaults | RESOLVED | r4 records their actual defaults and assigns valid person targets instead of NULL (`brief:359,363-364`). |
| r4 self-found: `tax_id` / `tax_regime` absent from `$fillable` | RESOLVED | r4 explicitly adds both to the model write seam (`brief:300-305`). They are indeed absent from the current `$fillable` block at `Partner.php:97-142`. |

## NEW findings

### N-18 — P0 — The backfill can erase a persisted `tax_id`

**Evidence:** `tax_id` exists as a nullable partner column, and r4 includes it in `PERSON_CLEARED_FIELDS` (`brief:361`). It is absent from both the derivation input and organization evidence ladder (`brief:157-186`). Migration A therefore classifies a tax-id-only legacy row as person and step 8 subsequently clears its `tax_id` (`brief:247-254`). This conflicts with OQ7’s ruling that a party carrying a tax id is an organization.

**Prescribed fix:** Add persisted and incoming `tax_id` to `PartyKindDerivationInput`, the `has_b2b_datum` arm, and both migration backfills before any person repair. Add a PostgreSQL regression proving a tax-id-only legacy row becomes organization and retains the value.

### N-19 — P1 — Person→organization clearing omits `mobile`

**Evidence:** Binding OQ1 defines four person-only columns: `date_of_birth`, `gender`, `national_id`, and `mobile` (`brief:64-65,239-240,595-596`). The reverse transition clears only the first three (`brief:386`).

**Prescribed fix:** Define a canonical `ORGANIZATION_CLEARED_FIELDS` set containing all four fields, use it in the policy/UI/metadata, and assert every member in API and browser transition tests.

### N-20 — P1 — Migration B can re-enable a deliberately disabled credit account

**Evidence:** Migration A backfills `credit_account_enabled=true` for active accounts (`brief:228-236`). M2 then exposes the flag for explicit updates (`brief:409-412`). Migration B subsequently reruns that backfill for all applicable rows (`brief:525-527`), so an active partner explicitly changed to `false` between migrations can be overwritten to `true`.

**Prescribed fix:** Before classification, capture only inter-migration legacy rows—such as rows whose `party_kind` is still NULL—and apply the compatibility credit backfill only to that candidate set. Prove an explicit `false` survives Migration B while an old-worker row receives the legacy-compatible value.

### N-21 — P1 — HTTP cannot enforce the canonical tax-field policy

**Evidence:** M2.2 widens the FormRequests for new Phase-2 fields but does not add the canonical tax/exemption/withholding fields (`brief:397-408`). Current FormRequests have no rules for them, while controllers pass only `$request->validated()`. The current form also uses nonexistent transport names `exemption_reason` and `exemption_valid_until` (`PartnerForm.tsx:103-105,414-415`) instead of `tax_exemption_reason` and `tax_exemption_valid_until`. Consequently, a same-kind request carrying many incompatible fields is silently stripped before `PartyIdentityPolicy`, rather than returning the promised field-specific 422.

**Prescribed fix:** Add canonical DTO/form/request rules for every policy field, rename the frontend fields to their persisted names, and test same-kind rejection for every `PERSON_CLEARED_FIELDS` member—not only `vat_number`.

### N-22 — P1 — The UI cannot observe or predict the promised exact cleared-field list

**Evidence:** M3 requires `meta.cleared_fields` to exactly match the pre-submit dialog (`brief:599-607`). The existing `apiPatch()` returns only `response.data.data`, discarding metadata (`apps/web/src/lib/api.ts:397-399`). M1.4’s `PartnerData` additions also omit most fields needed to determine which canonical values would actually change (`brief:306-309`).

**Prescribed fix:** Define a typed partner mutation envelope exposing `{data, meta.cleared_fields}` without double-unwrapping. Either expose all clearable current fields so the UI can calculate the exact delta, or define the dialog as a “may be cleared” list and relax equality to a server-returned subset. Map every database field to translated EN/FR/AR labels.

### N-23 — P1 — The Rule-8 event migration is not backward-compatible or strictly typed

**Evidence:** r4 says to add V2 events and move dispatch into `PartnerService`, but never explicitly retains V1 create/update dispatch (`brief:471-490`). Existing tests require `PartnerCreated` and `PartnerUpdated` at `PartnerEventsTest.php:81-133`. Also, “V1 fields plus” makes `PartnerUpdatedV2` inherit the V1 `array<string,mixed> $changes` contract, conflicting with strict-typing rule 3 (`CLAUDE.md:21-22`).

**Prescribed fix:** Explicitly dual-dispatch frozen V1 and typed V2 events from the service, with no controller duplicate. Replace the new V2 mixed changes array with a typed immutable change DTO/list. Test Laravel dispatch and stored-event persistence for both versions.

### N-24 — P1 — r4 leaves a second owner/legal gate open

**Evidence:** r4 asks the reviewer to decide whether withholding exemption is organization-only and calls it “the one judgement call” (`brief:367-370`). The self-review harness explicitly prohibits Codex from deciding legal posture and requires an owner STOP (`SELF-REVIEW-HARNESS.md:49-61`).

**Prescribed fix:** Obtain and record a binding owner/legal disposition before dispatch. Do not delegate this policy decision to a milestone reviewer.

### N-25 — P1 — M4 directly violates the module-boundary rule

**Evidence:** M4 directs the Import module to call `LegalForm::values()` and `PartyKindDeriver` from the Partner domain (`brief:659-666`). Rule 6 permits cross-module communication only through shared contracts, events, or a public service (`CLAUDE.md:30-31`). r4 itself recognizes the same problem when refusing to import Contact’s `Gender` enum (`brief:152-155`).

**Prescribed fix:** Keep derivation inside `PartnerService` and return a typed upsert/derivation result containing id, kind, reason, and cleared fields through `PartnerServiceInterface`; validate import strings locally or through that public seam.

### N-26 — P1 — Runtime `tax_regime` still uses a magic status string

**Evidence:** r4 repeatedly prescribes the literal `'individual'` in policy/runtime behavior (`brief:248,359,384-385`). No PHP tax-regime enum or model cast exists, despite rule 9 requiring PHP enums for every status/type/code column (`CLAUDE.md:39-40`).

**Prescribed fix:** Add a PHP tax-regime enum matching the existing database values, cast `Partner::$tax_regime`, and use its case in runtime policy code. Keep migration literals frozen and cover enum/database parity.

### N-27 — P2 — Migration B contradicts the migration’s domain-class prohibition

**Evidence:** Migration A correctly says migrations must not boot domain classes (`brief:218`). Migration B then specifies `LegalForm::values()` inside the migration (`brief:534-536`), coupling historical migration execution to future application code.

**Prescribed fix:** Freeze the six legal-form literals inside Migration B and let the PostgreSQL parity test compare the live enum with the resulting CHECK.

### N-28 — P2 — Moving-anchor declarations remain incomplete

**Evidence:** Phase 1 explicitly converts consumers to `PartnerData` and may widen the PHP DTO/generated type (`Phase-1 brief:115-122`), while r4 edits both again (`brief:300-309`). Phase 1 also authors the same `sales:partners.nature.*` locale blocks that r4 repoints and extends (`Phase-1 brief:160-168`; r4 `:569-576,625-637`). Neither DTO/generated types nor the EN/FR/AR sales locale files appear in r4’s moving-anchor list (`brief:39-45`). Separately, `ImportController.php`, heavily cited by M4, has changed between the pinned `a33b01354` anchors and current HEAD.

**Prescribed fix:** Add `PartnerData.php`, `packages/shared/types/generated.d.ts`, the three sales locale files, and `ImportController.php` to the mandatory post-merge repin census.

### N-29 — P1 — The verification protocol does not fully close

**Evidence:** Section 0.1 calls browser verification an owner-required gate for every milestone (`brief:87-108`), but M4 permits replacing it with PHPUnit (`brief:691-697`). M1’s `per_page=5` request also cannot prove the claim about every pre-existing row (`brief:311-313`). Finally, the harness requires a final whole-branch review (`SELF-REVIEW-HARNESS.md:74-75`), while lane completion stops after M1–M4 and M4 runs only the imports lens (`brief:699,703-708`).

**Prescribed fix:** Require the feasible Playwright request upload/download flow or record an owner-approved non-browser exception; paginate/census the complete M1 population; and add a final accumulated-branch gate running all Phase-2 lenses.

## Requested-lens summary

- **Sealed bytes/key sets/mirror:** Fresh grep confirms the N-11 value repair has no `tax_status` wire reach. The narrowed mirror proof is sound. No additional payload-key or POS-device-schema change is prescribed.
- **Rule 8:** Blocked by N-23.
- **Rule 19:** No new r4 arithmetic violation; the known Phase-1 `CreditLimitWarning` prerequisite remains correctly gated.
- **Tenancy/permissions:** Current partner routes retain `api`, Sanctum, permissions-team, tenant-claim, and per-action permission middleware; controller reads/updates are company-scoped. The new credit-toggle authorization test is explicitly required.
- **i18n:** EN/FR/AR delivery is specified, but N-22 requires translated labels for server-returned cleared fields.
- **Browser feasibility:** Ports and backend marker remain feasible. N-29 is the remaining protocol defect.

## Holistic executability and STOP audit

The brief is not executable end-to-end without an owner answer beyond OQ10:

1. **N-24 forces `blocked_owner`** because r4 delegates a withholding/legal ruling to the reviewer.
2. **N-25 forces `blocked_architecture`** if followed literally because the Import→Partner domain calls conflict with rule 6.
3. **N-29 forces an owner/protocol STOP if the M4 Playwright flow is unavailable**, because the proposed PHPUnit substitution contradicts the stated owner browser gate.

N-18 is independently release-blocking because the migration can destroy tax identity, but it can be corrected without another owner ruling.

## Disputes

None with the binding r3 dispositions. OQ10 remains pending with default option (a); the value-based N-11 repair, N-12 atomic transition, N-13 canonical table, N-14 credit flag, N-15 narrowed mirror proof, and N-16 null rejection were not relitigated.

VERDICT: CHANGES-REQUIRED

# Country Defaults Phase A — M1 P3 hardening carry-forward

Source: `docs/handoff/reviews/country-defaults-phase-a/M1-round2.md`
(`CHANGES-REQUIRED` only because these surviving P3 obligations lacked a durable ticket).

Status: OPEN carry-forward. M1's code findings are closed. The owners below must close or
explicitly adjudicate every item at its target milestone, and M7 must cite the resulting evidence.

## Ownership and milestone summary

| Obligation | Owner | Target milestone |
|---|---|---|
| Validate assignment country input before `CertificationScope` | M3 assignment API owner | M3, before the assignment endpoint merges |
| Preserve and record the Taxation provisioning signature decision | Country Defaults + Taxation API owners | M3 call-site audit; M7 evidence closure |
| Keep pure registries static only while they remain pure data | M2-M5 consumer owners / architecture owner | At each first stateful consumer; final adjudication in M7 |
| Declare the Intl runtime dependency in Composer metadata | Backend platform owner | M4, before fixture certification runs in a clean environment |
| Maintain the line-keyed throwing-site ratchet across forward merges | Merge integrator + manifest owner | Every M2-M7 forward merge; final replay in M7 |
| Add a direct guard for DYNAMIC-only REQUIRED demotion | M2 invariant-test owner | M2, before publish-gate work relies on the manifest |
| Prove correct legacy ordering-adapter routing | M4 fixture-export owner | M4, before legacy goldens are accepted |
| Preserve honest red-first chronology evidence | Every later milestone owner + M7 evidence owner | M2 onward; M1 limitation recorded at M7 |

## 1. Validate and normalize assignment route input before the value object

`CertificationScope::allowsAssignment()` deliberately throws for malformed assignment values.
M3 must not pass raw route input to it. `AssignTemplateRequest` owns the HTTP boundary: merge the
route `countryCode` into validated input, trim and uppercase it, and accept only an alpha-2 country
code or the exact wildcard `*` before constructing `CertificationScope`.

Acceptance criteria:

- M3 feature tests prove `FRA`, `tn-1`, and other malformed route values return a validation 422,
  never an uncaught `InvalidArgumentException`/500.
- Normalized valid input such as ` tn ` reaches assignment as `TN`; wildcard behavior continues to
  follow the existing exact-scope/wildcard truth table.
- The domain value object remains strict and its malformed-input unit test remains green; the HTTP
  layer absorbs transport invalidity rather than weakening domain validation.
- M3 records the validation rule and response contract in its milestone report.

## 2. Record the intentional `CompanyTaxProvisioningService` signature and ripple

M1 intentionally moved missing-country policy to the public call:
`provisionForCompany(Company $company, bool $failLoudOnMissingCountry = false)`. This preserves
soft behavior for runtime callers while seeders opt into fail-loud behavior. The change rippled
through the registry/service injection chain and six seeders even though the original M1 task
named only Taxation registry delegation. Treat this as a deliberate API decision, not incidental
cleanup.

Acceptance criteria:

- The M3 call-site audit lists every production caller and states which policy it requires.
  Runtime onboarding/controller paths must remain intentionally soft or explicitly change under a
  separately reviewed decision; seeders must pass the named `true` argument where missing country
  data is fatal.
- Tests continue to cover both policies and idempotent provisioning uses the fail-loud path.
- M7 cites the audit and records the signature/ripple as an accepted architectural decision. Any
  future signature change updates all callers and this decision in the same commit.

## 3. Keep static registries static only while they remain pure data

`ProvisioningRequiredPurposesV1` and `ProtectedAccountCodeRegistry` are static today. That is
acceptable while they are immutable, deterministic data with no configuration, tenant state,
clock, I/O, or substitutable policy. It becomes a testability and dependency-boundary problem if
later milestones add any of those concerns.

Acceptance criteria:

- M2-M5 consumers do not add mutable state, service-location, environment/config reads, tenant
  queries, or I/O to either static registry.
- If a registry gains a dependency or needs substitution, its owner converts the behavior to an
  injected contract/service and updates consumers/tests in that milestone.
- M7 explicitly records either the conversion commit or the evidence that both registries remain
  pure data and no refactor was warranted.

## 4. Declare the certification-critical Intl dependency

`CanonicalCoaSerializer` calls `Normalizer::normalize()`, but `apps/api/composer.json` does not
declare `ext-intl`. Runtime images currently install Intl; Composer metadata must describe that
requirement before M4 relies on clean-environment fixture generation.

Acceptance criteria:

- The backend platform owner adds the appropriate `ext-intl` requirement to Composer metadata and
  updates the lock metadata as required by Composer.
- `composer validate`, `composer check-platform-reqs`, and the canonical serializer tests pass in
  the supported container/CI runtime.
- A clean dependency install fails clearly when Intl is absent rather than reaching fixture export
  and failing at runtime.
- M4 cites this dependency check before accepting certified fixture hashes.

## 5. Treat line-keyed ratchet updates as evidence re-review

The throwing-site inventory deliberately includes source line numbers. Forward merges that move a
lookup will fail exact equality even if behavior is unchanged; new throwing sites must also fail
until classified. This friction is the intended review trigger, but it must be handled explicitly.

Acceptance criteria:

- The integrator runs `ProvisioningRequiredPurposesRegistrationRatchetTest` after every forward
  merge/rebase through M7.
- Any changed registration key is checked against the final source method, callee, purpose, and
  manifest citation. Registration and citation updates land together with a short evidence note;
  line literals are never edited solely to silence the diff.
- New throwing sites receive a manifest classification or an explicit reviewed exclusion before
  the ratchet is updated.
- M7 replays the ratchet against final HEAD and cites the output.

## 6. Directly pin DYNAMIC-only REQUIRED purposes against demotion

Six REQUIRED purposes are reached through DYNAMIC throwing sites rather than appearing by name in
the registered throwing-site list: `GeneralExpense`, `SalesReturnsClearing`, `VoucherLiability`,
`MarketingGoodwillExpense`, `PosTenderClearing`, and `RoundingLossExpense`. Current checks make a
demotion difficult and catch the known balanced mutation indirectly; a direct invariant should
make the intended direction obvious and independent of the SOFT substring check.

Acceptance criteria:

- Extend the existing M1 conformance test file (do not create another Phase A test filename) with
  the exact six-purpose set and assert each remains REQUIRED with DYNAMIC evidence resolving to its
  enum-producing source path.
- A mutation that demotes each purpose to SOFT, while preserving partition counts and rewiring the
  other mutable fields, fails the direct guard for that purpose.
- The guard does not infer reachability by searching the registered list for the purpose name; it
  exercises the typed DYNAMIC evidence relationship.
- M2 records red/green mutation output in chronological evidence.

## 7. Prove M4 routes only frozen definitions through legacy insertion order

`CanonicalCoaSerializer::withLegacyInsertionOrder()` intentionally overwrites `sort_order`.
M4's legacy exporter must call it on the captured frozen seeder definition list, in source
insertion order, before serialization. It must never call it on persisted template rows whose
explicit order is already part of the certified contract.

Acceptance criteria:

- M4 has separate, test-visible legacy-definition and persisted-template export paths.
- The legacy path captures `getAccountsDefinition()` rows in their existing list order, invokes
  `withLegacyInsertionOrder()` exactly once, and produces goldens whose sort order matches that
  captured insertion sequence.
- A persisted-template fixture with non-one-based values such as `10, 20` is serialized with those
  values unchanged. The test fails if the adapter renumbers them to `1, 2`.
- M4's exporter integration test proves the routing, not only the serializer method in isolation,
  and its report cites the source-order capture and resulting fixture hashes.

## 8. Record the M1 red-first chronology limitation; make later evidence durable

Fix-round implementation and tests landed together in `1a3982d3f`. The ignored session report
contains narrative red/mutation observations, but commit history and the progress ledger do not
independently prove that chronology. This cannot be repaired retroactively and must not be
relabelled as proven evidence.

Acceptance criteria:

- The M1 report and M7 evidence ledger identify this as a chronology limitation while retaining
  the independently re-runnable green and mutation-strength evidence.
- From M2 onward, each new behavior records the failing command/output before its production fix
  and the succeeding replay afterward in the milestone report or committed progress evidence.
- Review-fix commits must not claim red-first solely because test and implementation appear in one
  final diff; the evidence location and timestamp/order must be reviewable.
- M7 audits each milestone's evidence and explicitly lists any remaining chronology gaps instead
  of silently marking the TDD gate complete.

## Closure gate

This ticket closes only when every section has either satisfied its acceptance criteria at the
named milestone or has a written M7 adjudication with an owner and replacement deadline. M3 and M4
items are hard prerequisites for their corresponding endpoint/export merge; the remaining items
must be closed or explicitly adjudicated before M7 acceptance.

# Codex Country Defaults Phase A Report

Branch: `codex/country-defaults-phase-a`

Baseline: `7d85232cc54abd6a6b2135f476205ab434e71a66` (`origin/dev`)

Status: M0 passed; M1 passed (Opus round 5 ACCEPT); M2 passed (Opus round 5 ACCEPT); M3 in progress

Current M0 implementation/fix commit: `c744c19cc`
(`Phase 0.0.0: Reconcile country defaults baseline`) — the first commit after the pinned base.

Current round-1 review-record commit: `b65cdaee2`
(`Phase 0.0.1: Record M0 review findings`).

Current round-2 review-record commit: `08f8c1583`
(`Phase 0.0.2: Record M0 round two findings`).

Current fix-round-2 commit: `118190f1f`
(`Phase 0.0.3: Correct M0 reconciliation provenance`).

Recoverable pre-cleanup history: `3b1a37fe8` (original M0) and `7a4d65f25` (round-1 fix) remain
reachable on `codex/country-defaults-phase-a-pre-rewrite`; their changes are folded into
`c744c19cc` on the current branch.

## M0 — Reconciliation and baseline pinning

### Files touched

- Created
  `docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design-ADDENDUM-2026-08-10.md`
  as the post-spec delta record.
- Preserved the controller's changes in
  `docs/handoff/progress/country-defaults-phase-a.progress.yaml`: the pinned `base_sha`, branch,
  and wave-level `status: in_progress`. At the initial commit, M0 remained `pending`; the controller
  owns and has since recorded its review state.
- Began this required session report at
  `docs/sessions/codex-country-defaults-phase-a-report.md` (gitignored but retained as a user
  deliverable).
- Wrote the delegated implementation report at
  `.superpowers/sdd/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10/milestone-M0-report.md`.

No production code or test file was changed.

### Documentation-only red-test exception

M0 is documentation-only and therefore has no red test by design. Per the explicit M0/F-9
exception, the substitute evidence is the pre-addendum reproducible reconciliation command and its
verbatim successful output below. No SQLite or PostgreSQL test counts apply to this milestone, and
no revert-replay is required.

### Historical pre-addendum reconciliation command

This is retained verbatim as the controller's original pre-addendum evidence. M0 review found that
Composer's classmap could resolve the reflected seeders from the main checkout through the vendor
symlink. It is therefore superseded by the hermetic fix-round command below and is not relied on as
the final M0 evidence.

```bash
php -r '
require "apps/api/vendor/autoload.php";
$seeders = [
  "TN" => Database\Seeders\TunisiaChartOfAccountsSeeder::class,
  "FR" => Database\Seeders\FranceChartOfAccountsSeeder::class,
  "GENERIC" => Database\Seeders\GenericChartOfAccountsSeeder::class,
];
$required = [
  "bank", "cash", "customer_receivable", "inventory", "supplier_payable",
  "vat_collected", "vat_deductible", "product_revenue", "service_revenue",
  "cost_of_goods_sold", "general_expense", "opening_balance_equity",
  "purchase_price_variance_expense", "purchase_price_variance_income",
  "goods_received_not_invoiced", "purchase_stamp_duty", "sales_discount",
  "customer_advance", "supplier_advance", "sales_returns_clearing",
  "voucher_liability", "marketing_goodwill_expense", "pos_tender_clearing",
  "rounding_loss_expense", "payment_tolerance_expense", "payment_tolerance_income",
  "purchase_expenses",
];
$oldDeltas = [
  "TN" => ["sales_discount"],
  "FR" => ["cost_of_goods_sold", "general_expense", "sales_discount", "customer_advance", "supplier_advance", "code:624"],
  "GENERIC" => [],
];
echo "base_sha=" . trim(shell_exec("git rev-parse origin/dev")) . PHP_EOL;
echo "required_count=" . count($required) . PHP_EOL;
foreach ($seeders as $country => $class) {
  $method = new ReflectionMethod($class, "getAccountsDefinition");
  $method->setAccessible(true);
  $rows = $method->invoke(new $class());
  $purposes = array_values(array_filter(array_column($rows, "system_purpose"), static fn ($value): bool => is_string($value)));
  $codes = array_column($rows, "code");
  $missing = array_values(array_diff($required, $purposes));
  $stamp = in_array("sales_stamp_duty_payable", $purposes, true) ? "present" : "absent";
  $deltaMissing = [];
  foreach ($oldDeltas[$country] as $delta) {
    if (str_starts_with($delta, "code:")) {
      $code = substr($delta, 5);
      if (!in_array($code, $codes, true)) { $deltaMissing[] = $delta; }
    } elseif (!in_array($delta, $purposes, true)) {
      $deltaMissing[] = $delta;
    }
  }
  echo $country . " accounts=" . count($rows)
    . " missing_required=" . json_encode($missing, JSON_THROW_ON_ERROR)
    . " sales_stamp_duty_payable=" . $stamp
    . " old_delta_missing=" . json_encode($deltaMissing, JSON_THROW_ON_ERROR)
    . PHP_EOL;
}
'
```

### Raw pre-addendum output (verbatim)

```text
base_sha=7d85232cc54abd6a6b2135f476205ab434e71a66
required_count=27
TN accounts=139 missing_required=[] sales_stamp_duty_payable=present old_delta_missing=[]
FR accounts=144 missing_required=[] sales_stamp_duty_payable=absent old_delta_missing=[]
GENERIC accounts=61 missing_required=[] sales_stamp_duty_payable=absent old_delta_missing=[]
```

### Decisions recorded

- The fetched `origin/dev` is unchanged from the authoring baseline, so the reconciliation and
  compatibility-seeder freeze point remain `7d85232cc`.
- The 41-entry operational manifest's 27 REQUIRED purposes have no missing entries in TN, FR, or
  Generic, while the scope-dependent stamp result is independently correct: present for TN and
  absent for FR and Generic. Zero missing REQUIRED purposes alone is not the
  publish/certification gate.
- The accepted spec's old content deltas are empty. Authenticated human HTTP publication remains
  mandatory and is the only mechanism that sets certification metadata.
- D-1 through D-8 and executed decisions S-1 through S-6 are recorded in the addendum, including
  replay semantics, all protected-code variants, loud-failure boundaries, the 41/13 manifest
  distinction, the country-code mutability limitation, and the no-existing-company-mutation rule.
- Phase A uses the new `CountryDefaults` module and a shared contract with one implementation as
  the sole timbre/capability-version authority. Taxation delegates to it.
- Provisioning stays default-off across a two-release activation, and the implementation envelope
  remains 9–12 dev-days.

### Verification

- Compared the addendum line by line with every M0 bullet and the authoritative §0/§0.1 decisions.
- Confirmed the accepted spec body is unchanged.
- Confirmed the tracked diff contains documentation only and the pre-existing progress pin.
- Confirmed the initial M0 implementation did not edit the controller-owned YAML milestone fields.
- No full test suite or adversarial-review script was run, per the M0 constraints.

### Concerns

None for M0. The controller still owns the adversarial/treasury review and the subsequent YAML
milestone verdict update.

## M0 fix round 1 — review findings

Round 1 register:
`docs/handoff/reviews/country-defaults-phase-a/M0-round1.md` (`CHANGES-REQUIRED`).

### Files touched in fix round 1

- Corrected
  `docs/superpowers/specs/2026-08-08-country-defaults-super-admin-design-ADDENDUM-2026-08-10.md`.
- Hardened `scripts/adversarial-review.sh` for Bash 3.2 missing-argument handling and enforced
  Claude plan-mode permissions.
- Removed the unrelated `docs/handoff/CODEX-DISPATCH-wave3-3c-3d-2026-08-10.md` and
  `docs/handoff/progress/wave3-3c-3d.progress.yaml` artifacts from the current tree.
- Replaced the ignored reconciliation helper at
  `.superpowers/sdd/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10/m0-reconciliation-command.sh`
  and appended evidence to both required reports.

The controller-owned progress YAML review status, verdict, commit, and fix-round fields were not
edited.

### Addendum corrections

- The operational manifest is now identified as 41 entries partitioned into 27 REQUIRED, one
  SCOPE-REQUIRED, four CONDITIONAL, and nine SOFT entries. All exact 27 REQUIRED purposes are
  enumerated durably in the addendum.
- D-7 now records the underlying drift: `settings.update` authorizes the request that accepts
  `country_code`, the controller writes it, and `tax_configurations` is country-scoped with no
  `company_id`. The required S-5 limitation remains verbatim and links the owner-visible ticket.
- The TN `SalesStampDutyPayable` result cites
  `apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:208`.

### Hermetic reconciliation command (exact)

Run from the pinned worktree root. The vendor autoloader supplies framework dependencies only;
the enum, seeder contract, and three seeders are explicitly required from this worktree before
reflection. Every reflected source path is then required to remain below the resolved worktree
root.

```bash
php -r '
$root = realpath(getcwd());
if ($root === false) {
  throw new RuntimeException("Unable to resolve the worktree root.");
}
require $root . "/apps/api/vendor/autoload.php";
require_once $root . "/apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php";
require_once $root . "/apps/api/database/seeders/Contracts/ChartOfAccountsSeederContract.php";
require_once $root . "/apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php";
require_once $root . "/apps/api/database/seeders/FranceChartOfAccountsSeeder.php";
require_once $root . "/apps/api/database/seeders/GenericChartOfAccountsSeeder.php";
$sources = [
  "purpose_enum" => App\Modules\Accounting\Domain\Enums\SystemAccountPurpose::class,
  "seeder_contract" => Database\Seeders\Contracts\ChartOfAccountsSeederContract::class,
  "TN_seeder" => Database\Seeders\TunisiaChartOfAccountsSeeder::class,
  "FR_seeder" => Database\Seeders\FranceChartOfAccountsSeeder::class,
  "GENERIC_seeder" => Database\Seeders\GenericChartOfAccountsSeeder::class,
];
echo "source_root=" . $root . PHP_EOL;
foreach ($sources as $label => $class) {
  $sourceFile = (new ReflectionClass($class))->getFileName();
  if (!is_string($sourceFile) || !str_starts_with($sourceFile, $root . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException($label . " did not resolve from the worktree: " . var_export($sourceFile, true));
  }
  echo $label . "_file=" . $sourceFile . PHP_EOL;
}
$seeders = [
  "TN" => Database\Seeders\TunisiaChartOfAccountsSeeder::class,
  "FR" => Database\Seeders\FranceChartOfAccountsSeeder::class,
  "GENERIC" => Database\Seeders\GenericChartOfAccountsSeeder::class,
];
$required = [
  "bank", "cash", "customer_receivable", "inventory", "supplier_payable",
  "vat_collected", "vat_deductible", "product_revenue", "service_revenue",
  "cost_of_goods_sold", "general_expense", "opening_balance_equity",
  "purchase_price_variance_expense", "purchase_price_variance_income",
  "goods_received_not_invoiced", "purchase_stamp_duty", "sales_discount",
  "customer_advance", "supplier_advance", "sales_returns_clearing",
  "voucher_liability", "marketing_goodwill_expense", "pos_tender_clearing",
  "rounding_loss_expense", "payment_tolerance_expense", "payment_tolerance_income",
  "purchase_expenses",
];
$enumValues = array_map(
  static fn (App\Modules\Accounting\Domain\Enums\SystemAccountPurpose $purpose): string => $purpose->value,
  App\Modules\Accounting\Domain\Enums\SystemAccountPurpose::cases(),
);
$unknownRequired = array_values(array_diff($required, $enumValues));
if ($unknownRequired !== []) {
  throw new RuntimeException("Unknown REQUIRED purpose: " . json_encode($unknownRequired, JSON_THROW_ON_ERROR));
}
$oldDeltas = [
  "TN" => ["sales_discount"],
  "FR" => ["cost_of_goods_sold", "general_expense", "sales_discount", "customer_advance", "supplier_advance", "code:624"],
  "GENERIC" => [],
];
echo "base_sha=" . trim((string) shell_exec("git -C " . escapeshellarg($root) . " rev-parse origin/dev")) . PHP_EOL;
echo "enum_case_count=" . count($enumValues) . PHP_EOL;
echo "required_count=" . count($required) . PHP_EOL;
foreach ($seeders as $country => $class) {
  $method = new ReflectionMethod($class, "getAccountsDefinition");
  $method->setAccessible(true);
  $rows = $method->invoke(new $class());
  $purposes = array_values(array_filter(array_column($rows, "system_purpose"), static fn ($value): bool => is_string($value)));
  $codes = array_column($rows, "code");
  $missing = array_values(array_diff($required, $purposes));
  $stamp = in_array("sales_stamp_duty_payable", $purposes, true) ? "present" : "absent";
  $deltaMissing = [];
  foreach ($oldDeltas[$country] as $delta) {
    if (str_starts_with($delta, "code:")) {
      $code = substr($delta, 5);
      if (!in_array($code, $codes, true)) { $deltaMissing[] = $delta; }
    } elseif (!in_array($delta, $purposes, true)) {
      $deltaMissing[] = $delta;
    }
  }
  echo $country . " accounts=" . count($rows)
    . " missing_required=" . json_encode($missing, JSON_THROW_ON_ERROR)
    . " sales_stamp_duty_payable=" . $stamp
    . " old_delta_missing=" . json_encode($deltaMissing, JSON_THROW_ON_ERROR)
    . PHP_EOL;
}
'
```

### Hermetic reconciliation output (exact)

```text
source_root=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a
purpose_enum_file=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a/apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php
seeder_contract_file=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a/apps/api/database/seeders/Contracts/ChartOfAccountsSeederContract.php
TN_seeder_file=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a/apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php
FR_seeder_file=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a/apps/api/database/seeders/FranceChartOfAccountsSeeder.php
GENERIC_seeder_file=/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/country-defaults-phase-a/apps/api/database/seeders/GenericChartOfAccountsSeeder.php
base_sha=7d85232cc54abd6a6b2135f476205ab434e71a66
enum_case_count=41
required_count=27
TN accounts=139 missing_required=[] sales_stamp_duty_payable=present old_delta_missing=[]
FR accounts=144 missing_required=[] sales_stamp_duty_payable=absent old_delta_missing=[]
GENERIC accounts=61 missing_required=[] sales_stamp_duty_payable=absent old_delta_missing=[]
```

### Harness red/green evidence

Before the script fix, omitting `--out` reproduced the Bash 3.2 failure:

```text
scripts/adversarial-review.sh: line 35: missing --${req,,}: bad substitution
exit=1
```

#### RED 7 — PostgreSQL migration command ordering

The first identical eight-path PostgreSQL run failed before tests because Laravel emitted the
self-FK before the fluent UUID primary-key command:

```text
SQLSTATE[42830]: Invalid foreign key: there is no unique constraint matching given keys
for referenced table "admin_templates"
Tests: 22 failed (0 assertions)
Duration: 96.29s
exit=2
```

`migrate --pretend` confirmed the faulty order. The migrations now declare explicit primary and
unique commands before every FK command; the replay order is PK → UNIQUE(id, domain) → self-FK.

The unsafe-mode check also failed as intended:

```text
85:      --permission-mode bypassPermissions \
unsafe permission check exit=1
```

After the minimal fix, the same missing-argument probe is fail-closed under the documented
contract, and the installed Claude CLI confirms plan mode is supported:

```text
missing --out
exit=3
91:      --permission-mode plan \
--permission-mode <mode> ... choices: "acceptEdits", "auto", "bypassPermissions", "manual", "dontAsk", "plan"
Focused bridge verification: PASS
```

`bash -n scripts/adversarial-review.sh` also exits zero. No adversarial review was invoked during
these focused checks.

### Completed history cleanup provenance

The recoverable cleanup is complete. On the current branch, `c744c19cc` is the first commit after
`7d85232cc`, contains the addendum plus the corrected read-only harness, and has no unrelated
wave3 artifacts. The pre-cleanup objects remain recoverable on
`codex/country-defaults-phase-a-pre-rewrite` at `7a4d65f25`. Round-1 provenance is mapped in
`docs/handoff/reviews/country-defaults-phase-a/M0-round1.md`; no history adjudication remains open.

## M0 fix round 2 — review findings

Round 2 register:
`docs/handoff/reviews/country-defaults-phase-a/M0-round2.md` (`CHANGES-REQUIRED`).

Fix-round-2 commit: `118190f1f` (`Phase 0.0.3: Correct M0 reconciliation provenance`).

### Corrections

- D-3 now describes pinned-base reality: the Taxation registry still stores and answers the
  stamp-duty predicate. It identifies that state as the duplicate-authority drift M1 must remove
  by executing S-1; M0 no longer claims the rewire already exists.
- The full hermetic reconciliation command and its verbatim output are now committed in the
  addendum, including explicit worktree requires and reflected-path prefix guards.
- The session report's current commit references and closing provenance now match the cleaned
  ancestry. The round-1 register has a provenance note mapping its preserved historical range to
  `c744c19cc` and `b65cdaee2` without altering its original reviewer text.

### Focused verification

- Hermetic reconciliation: PASS. The command extracted from the committed addendum executed from
  the worktree and matched the addendum's verbatim output, including all five worktree-rooted
  reflected paths, `enum_case_count=41`, `required_count=27`, and the unchanged country results.
- Current ancestry: PASS. `c744c19cc` is the first commit after `7d85232cc`; `b65cdaee2`,
  `08f8c1583`, and `118190f1f` follow it. The backup branch resolves to `7a4d65f25`, and old
  `3b1a37fe8`/`7a4d65f25` are reachable there but not from current HEAD.
- `git diff --check` and committed-diff check: PASS.
- Accepted spec unchanged: PASS.
- Country-defaults progress YAML unchanged by fix round 2: PASS.
- Commit scope: two documentation files, 126 insertions and 4 deletions; no production,
  executable, or test path changed.

No broad suite or adversarial-review invocation applies to this documentation-only fix round.

## M0 final gate — round 3

Register: `docs/handoff/reviews/country-defaults-phase-a/M0-round3.md`.

Verdict: `ACCEPT`. Opus independently re-ran the committed hermetic reconciliation command and
re-derived the account counts, 27 REQUIRED set, scope-dependent stamp result, protected-code
variants, D-1 through D-8 facts, S-1 through S-6 decisions, branch ancestry, and evidence
provenance. It confirmed both round-2 P2 findings closed and found no P1/P2.

Two non-blocking P3 hardening notes are durably routed to
`docs/superpowers/tickets/2026-08-11-country-defaults-m0-p3-hardening.md` and must be revisited by
M4/M7: bind future fixture reconciliation to the intended source revision/definition content, and
avoid overstating the write restrictions guaranteed by Claude plan mode.

## M1 — Invariant kernel

Implemented the no-DB/no-HTTP Country Defaults kernel: the single v1 (`TN`) capability authority
and Taxation delegation; certification scope algebra; the complete 41-case purpose manifest and
100-site AST registration ratchet; three protected-code variants; canonical NFC COA bytes/hash;
and exact frozen markers plus source-byte guards for the three compatibility seeders.

### TDD and replay

All nine prescribed M1 paths were authored and run red before production changes. Reds were the
intended missing classes/contracts/markers; the Taxation characterization's existing response
stayed green while the new injected-authority assertion was red. The registration test first
failed against an empty list with all 100 discovered live sites. The serializer golden pins the
independently shell-derived SHA-256
`b93206e4fe2e416c36152f70c6684fd4c95a9865cbf5f1e46b4b13a9e100eb36`.

Mutation replay proved focused red failures for: removing TN capability, widening wildcard
assignment, deleting one AST registration, drifting protected code `5312`, switching SHA-256 to
SHA-1, bypassing Taxation delegation, and removing a frozen marker. All mutations were restored;
the focused replay then passed 42 tests / 321 assertions.

### Final verification

- SQLite, complete M1 inventory + existing Taxation registry/management paths:
  **56 passed / 381 assertions**.
- PostgreSQL (`autoerp_country_defaults_test`, `phpunit-pgsql.xml`), same exact paths:
  **56 passed / 381 assertions**.
- Pint over touched M1 production/test paths: pass.
- PHPStan over touched M1 production/test paths: no errors.
- `git diff --check`: clean.

No schema, migration, HTTP route, frontend, provisioning, or existing-company mutation was added.
The detailed delegated evidence is in
`.superpowers/sdd/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10/milestone-M1-report.md`.

M1 implementation commit: `07868ba12`
(`Phase 0.1.0: Establish country defaults invariant kernel`).
+

## M1 review-fix round — invariant hardening (2026-08-11)

Status: DONE

### Corrections

- Removed Taxation's import and default construction of the Country Defaults concrete capability
  service. `CountryTaxConfigurationRegistry` now requires the Shared contract, and
  `CompanyTaxProvisioningService` requires the registry. Both are container-resolvable.
- Moved the provisioning missing-country policy to the call, then converted demo/vertical seeders
  to constructor or method injection. No production `app()`/service-locator lookup was added.
- Expanded the capability-authority guard across `app`, `routes`, `config`, `database`, and
  `bootstrap`. Its independent AST pass recognizes a bool predicate that normalizes a value and
  tests it against an owned closed alpha-2 set containing `TN`, a direct set, or direct comparison.
  It does not depend on capability/stamp/timbre/fiscal vocabulary. A temporary
  `database/seeders/ShadowPolicy.php` replay uses only `REGIONS`/`admits` identifiers and proves
  detection.
- Expanded the throwing-purpose ratchet over the same production roots. It discovers the primitive
  throwing resolver, thin renamed wrappers, and semantic Account-returning throwers from their AST
  behavior; only the intentionally dead, separately caller-guarded
  `UninvoicedDeliveryNoteService` is excluded. A non-`app` renamed-wrapper replay proves the
  scanner follows `resolveLedgerSlot` without a hardcoded wrapper name.
- Replaced conditional substring evidence with AST control-flow dominance. Independent mutations
  replace each of `RefundWriteOff`, `SalesReturn`,
  `SalesRoundingDifferenceIncome`, and `SalesRoundingDifferenceExpense`; every mutation is
  rejected. Live SQLite and PostgreSQL checks delete each FR rounding absorber, invoke the real
  `DocumentPostingService`, receive `NoAbsorbingAccount`, and prove the document remains
  `Confirmed`, has no fiscal hash/chain sequence, and is not sealed.

### Red-first and green evidence

Focused reds were observed before each correction:

- dependency-boundary test failed on the concrete Country Defaults import in Taxation;
- renamed capability mutation was not detected by the old name-only/app-only guard;
- non-`app` `resolveLedgerSlot` mutation was absent from the old three-name scanner;
- conditional mutation test failed because the structural dominance verifier did not exist;
- live rounding-absorber test failed because the behavioral helper did not exist.

Focused greens:

```bash
php artisan test tests/Unit/CountryDefaults/CapabilityAuthoritySingularityTest.php
# 5 passed, 121 assertions

php artisan test tests/Unit/CountryDefaults/ProvisioningRequiredPurposesRegistrationRatchetTest.php
# 2 passed, 2 assertions

php artisan test tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php \
  --filter='removing_each|missing_rounding_absorber_is_refused_live'
# mutation dominance: 4 assertions; live preflight: 12 assertions

php artisan test \
  tests/Feature/Taxation/CompanyTaxProvisioningServiceTest.php \
  tests/Feature/Taxation/EndToEndTaxResolutionTest.php \
  tests/Feature/Tenant/OnboardingTaxStepTest.php \
  tests/Feature/Seeders/CoffeeShopSeederOrderingTest.php
# 12 passed, 28 assertions
```

### Final database and static verification

The exact nine-file M1 inventory plus the existing Taxation registry and management regressions
passed on both engines:

- SQLite: **61 passed / 511 assertions**, 17.10s.
- PostgreSQL (`autoerp_country_defaults_test`, `phpunit-pgsql.xml`):
  **61 passed / 511 assertions**, 21.18s.

`Pint --test` passes over all files changed in this review fix. PHPStan reports
`[OK] No errors` over the complete M1 kernel/static path set plus the changed Taxation
registry/service and focused tests. A direct standalone PHPStan expansion over the pre-existing
large demo seeders still reports their unrelated baseline findings at untouched lines; this fix
does not suppress or alter those findings. `git diff --check` exits zero.

### Decisions and concerns

- The semantic capability guard deliberately models an independent *fixed* authority: a class-owned
  closed country set, a direct set literal, or direct normalized comparison. Config-driven feature
  gates such as Procurement's bonus-country setting are not competing fixed capability authorities.
- Runtime fixture writes are confined to a randomized `sys_get_temp_dir()` tree and removed in
  `finally`; no fixture or extra Phase A test file is added to the repository.
- The worktree-local ignored vendor copy remains in place so Composer resolves this worktree.

Review-fix commit: `6df85c92e` — `Phase 0.1.1: Harden country defaults invariant guards`.
+
## M1 adversarial review round 1 — merge-gate corrections (2026-08-11)

Register:
`docs/handoff/reviews/country-defaults-phase-a/M1-round1.md`
(`CHANGES-REQUIRED`).

### Corrections

- Replaced all 41 free-form/stale manifest citations with typed `DIRECT`, `DYNAMIC`,
  `CONDITIONAL`, or `NONE` evidence. DIRECT evidence must be an exact member of the
  independently scanned throwing-site inventory and resolve to the cited class, method, call,
  line, and purpose AST. DYNAMIC evidence additionally resolves the exact enum-producing AST
  reference and proves delegation from the dynamic throw site to its source method. CONDITIONAL
  evidence resolves the exact purpose AST and remains covered by the existing control-flow
  dominance/live preflight proofs.
- Made classification direction executable: REQUIRED and SCOPE_REQUIRED accept only DIRECT or
  DYNAMIC evidence, CONDITIONAL accepts only CONDITIONAL evidence, and SOFT accepts only NONE.
  A balanced GeneralExpense/OfficeExpense category swap is rejected even though the 27+1+4+9
  counts remain unchanged.
- Replaced the `git show 7d85232cc` freeze test with repository-committed SHA-256 fingerprints
  of each current frozen seeder after removing only the exact marker. The guard has no Git,
  history-depth, shell, or `shell_exec` dependency.
- Added `CanonicalCoaSerializer::withLegacyInsertionOrder()`: M4 can adapt the frozen definition
  list, in source insertion order, to one-based unique `sort_order` before canonicalization.
  Persisted template rows still require explicit unique order. No additional production filename
  was introduced.
- Pinned the settled activation contract: `is_active` is neither template schema nor canonical
  content; canonical bytes/hash ignore it, and the future template-backed company seeder must
  derive `is_active=true` as provisioning policy.
- Replaced whole-file stamp substrings with real seeded-row correlation. TN and FR seeders run,
  then capability is compared with existence of the same country's row having both
  `is_stamp_duty=true` and `is_active=true`.
- Converted `DemoTenantSeeder` to readonly constructor injection and restored both idempotency
  calls to `failLoudOnMissingCountry: true`.

### Red-first and mutation evidence

- Placeholder frozen fingerprints failed all three cases and printed the independently pinned
  hashes before the constants were installed.
- The legacy-order test failed before the serializer adapter existed.
- Deactivating both active TN stamp definitions made the seeded-row capability test fail
  (`true` versus `false`).
- The readonly reflection test failed against the prior mutable Demo seeder property.
- The balanced REQUIRED/SOFT swap was accepted before evidence-direction validation.
- The original stale Bank citation failed the typed evidence grammar; after the rewrite,
  mutating its exact line from 4215 to 4214 failed exact inventory membership.

Every mutation was restored with `apply_patch`.

### Final verification

The exact nine prescribed M1 paths plus
`CountryTaxConfigurationRegistryTest` and
`TaxConfigurationManagementTest` passed on both engines:

- SQLite: **65 passed / 745 assertions**, 23.40s.
- PostgreSQL (`autoerp_country_defaults_test`, `phpunit-pgsql.xml`):
  **65 passed / 745 assertions**, 27.88s.

Focused post-static-helper replay:
**15 passed / 417 assertions**.

`Pint --test` passes over all eight changed PHP paths. PHPStan reports
`[OK] No errors` over those paths. `git diff --check` exits zero.

### Decisions and routed obligations

- P2-6 is resolved by the settled no-activation template contract; no
  `requires_active` field was added to the protected-code registry.
- The public Taxation provisioning API ripple from the prior fix
  (`failLoudOnMissingCountry` moved to the call) remains intentional and is now explicitly
  documented; this round restores its idempotency coverage.
- `CertificationScope` continues to reject malformed values by exception, matching its existing
  truth-table contract. M3's `AssignTemplateRequest` must validate and normalize the ISO/wildcard
  route input before constructing the value object so malformed HTTP input becomes 422, not 500.
- The pure static registries remain data-only and were not refactored.
- `ext-intl` declaration remains a dependency-metadata follow-up: the runtime images and local
  verification have Intl, but this review fix does not rewrite Composer metadata/lock state.

Review-fix commit: `1a3982d3f` —
`Phase 0.1.2: Resolve M1 adversarial review findings`.

## M1 fix round 2 — durable P3 routing (2026-08-11)

Review register:
`docs/handoff/reviews/country-defaults-phase-a/M1-round2.md`
(`CHANGES-REQUIRED` on missing durable P3 routing).

Review-record commit: `48b86e4a8`
(`Phase 0.1.4: Record M1 round two findings`).

Fix commit: `06064a3a6`
(`Phase 0.1.5: Track M1 hardening obligations`).

### Files touched

Tracked:

- Created
  `docs/superpowers/tickets/2026-08-11-country-defaults-m1-p3-hardening.md`.

Session-local/ignored:

- Appended the per-task M1 report with the ticket scope and fix SHA.
- This canonical report was not updated at the time; this section is the round-4 evidence repair.

No production or test file changed in fix round 2.

### Exact commands and actual output

The documentation-only scope check and commit were:

```bash
git add docs/superpowers/tickets/2026-08-11-country-defaults-m1-p3-hardening.md
git diff --cached --check
test "$(git diff --cached --name-only | wc -l | tr -d ' ')" = "1"
test "$(git diff --cached --name-only)" = \
  "docs/superpowers/tickets/2026-08-11-country-defaults-m1-p3-hardening.md"
git commit -m "Phase 0.1.5: Track M1 hardening obligations"
```

Actual output:

```text
[codex/country-defaults-phase-a 06064a3a6] Phase 0.1.5: Track M1 hardening obligations
 1 file changed, 167 insertions(+)
 create mode 100644 docs/superpowers/tickets/2026-08-11-country-defaults-m1-p3-hardening.md
```

No SQLite or PostgreSQL command applied to this documentation-only fix. It made no executable
change and did not make a new code-green claim. The final M1 code state was rerun on both engines
in fix round 3 and is reproduced in the round-4 consolidation below.

### Decisions and concerns

The ticket assigned owners, target milestones, and acceptance criteria for M3 request validation,
the intentional Taxation provisioning signature, pure static registries, Intl metadata, line-keyed
ratchet maintenance, a direct DYNAMIC-only demotion guard, M4 legacy-order routing, and evidence
chronology. The red-first chronology limitation is explicit: the fix-round-1 narrative records
observed mutations, but commit history/progress evidence cannot independently prove their order.
M7 must retain that limitation rather than retroactively marking it proven.

## M1 fix round 3 — couple capability set to version (2026-08-11)

Review register:
`docs/handoff/reviews/country-defaults-phase-a/M1-round3.md`
(`CHANGES-REQUIRED` on one P2 test-integrity gap).

Review-record commit: `c6c95a56a`
(`Phase 0.1.7: Record M1 round three findings`).

Fix commit: `86e62e2bd`
(`Phase 0.1.8: Couple capability set to version`).

### Files touched

Tracked:

- Modified
  `apps/api/tests/Unit/CountryDefaults/CountryAccountingCapabilitiesServiceTest.php`.
- Modified
  `docs/superpowers/tickets/2026-08-11-country-defaults-m1-p3-hardening.md`.

Session-local/ignored:

- Appended the per-task M1 report with the mutation, dual-engine, ticket, and commit evidence.
- This canonical report was not updated at the time; this section is the round-4 evidence repair.

No production file or new filename was committed. Reflection reads the existing private
`STAMP_DUTY_COUNTRIES` constant without widening the production contract.

### Executed mutation and restore replay

The hardened test pins one exact coupled value:

```php
[
    'version' => 'v1',
    'stamp_duty_countries' => ['TN'],
]
```

After the assertion existed, the production constant was temporarily mutated with `apply_patch`:

```diff
-    private const STAMP_DUTY_COUNTRIES = ['TN'];
+    private const STAMP_DUTY_COUNTRIES = ['TN', 'MA'];
```

Exact focused command:

```bash
cd apps/api
php artisan test \
  tests/Unit/CountryDefaults/CountryAccountingCapabilitiesServiceTest.php \
  --filter=test_v1_version_and_full_capable_country_set_are_one_exact_contract
```

Actual red output:

```text
FAIL  Tests\Unit\CountryDefaults\CountryAccountingCapabilitiesServiceTest
⨯ v1 version and full capable country set are one exact contract
Failed asserting that two arrays are identical.
    'version' => 'v1',
    'stamp_duty_countries' => [
        0 => 'TN',
+       1 => 'MA',
    ],
Tests: 1 failed (1 assertions)
exit=1
```

The mutation was reverted with `apply_patch`, restoring exactly `['TN']`. Exact green replay:

```bash
php artisan test tests/Unit/CountryDefaults/CountryAccountingCapabilitiesServiceTest.php
```

Actual output:

```text
PASS  Tests\Unit\CountryDefaults\CountryAccountingCapabilitiesServiceTest
✓ only tunisia is stamp duty capable after normalization
✓ v1 version and full capable country set are one exact contract
Tests: 2 passed (6 assertions)
```

### Exact dual-engine M1 controller suite

SQLite command:

```bash
cd apps/api
php artisan test --compact --no-ansi \
  tests/Unit/CountryDefaults/CountryAccountingCapabilitiesServiceTest.php \
  tests/Unit/CountryDefaults/CapabilityAuthoritySingularityTest.php \
  tests/Unit/CountryDefaults/CertificationScopeTest.php \
  tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php \
  tests/Unit/CountryDefaults/ProvisioningRequiredPurposesRegistrationRatchetTest.php \
  tests/Unit/CountryDefaults/ProtectedAccountCodeRegistryDriftTest.php \
  tests/Unit/CountryDefaults/CanonicalCoaSerializerGoldenTest.php \
  tests/Unit/CountryDefaults/FrozenSeederDocblockTest.php \
  tests/Feature/Taxation/TaxConfigurationCapabilityDelegationTest.php \
  tests/Unit/Taxation/CountryTaxConfigurationRegistryTest.php \
  tests/Feature/Taxation/TaxConfigurationManagementTest.php
```

PostgreSQL command:

```bash
cd apps/api
DB_HOST=127.0.0.1 DB_PORT=5432 \
DB_DATABASE=autoerp_country_defaults_test \
DB_CENTRAL_DATABASE=autoerp_country_defaults_test \
DB_USERNAME=houssamr DB_PASSWORD='' \
php artisan test -c phpunit-pgsql.xml --compact --no-ansi \
  tests/Unit/CountryDefaults/CountryAccountingCapabilitiesServiceTest.php \
  tests/Unit/CountryDefaults/CapabilityAuthoritySingularityTest.php \
  tests/Unit/CountryDefaults/CertificationScopeTest.php \
  tests/Unit/CountryDefaults/ProvisioningRequiredPurposesV1ConformanceTest.php \
  tests/Unit/CountryDefaults/ProvisioningRequiredPurposesRegistrationRatchetTest.php \
  tests/Unit/CountryDefaults/ProtectedAccountCodeRegistryDriftTest.php \
  tests/Unit/CountryDefaults/CanonicalCoaSerializerGoldenTest.php \
  tests/Unit/CountryDefaults/FrozenSeederDocblockTest.php \
  tests/Feature/Taxation/TaxConfigurationCapabilityDelegationTest.php \
  tests/Unit/Taxation/CountryTaxConfigurationRegistryTest.php \
  tests/Feature/Taxation/TaxConfigurationManagementTest.php
```

Actual outputs:

```text
SQLite:     Tests: 65 passed (745 assertions)  Duration: 21.52s  exit=0
PostgreSQL: Tests: 65 passed (745 assertions)  Duration: 26.93s  exit=0
```

Static commands:

```bash
./vendor/bin/pint --test \
  tests/Unit/CountryDefaults/CountryAccountingCapabilitiesServiceTest.php
./vendor/bin/phpstan analyse --no-progress --memory-limit=2G \
  tests/Unit/CountryDefaults/CountryAccountingCapabilitiesServiceTest.php
git diff --check
```

Actual output: Pint `{"result":"pass"}`; PHPStan `[OK] No errors`; diff check exited
zero.

### Decisions and concerns

The exact tuple makes any country-set edit red until the versioned contract is reviewed and updated;
the normalization behavior remains a separate behavioral test. The P3 ticket was corrected to
account for Symfony's normalizer polyfill: M4 must declare `ext-intl` and positively prove native
ICU is active, not infer it from `Normalizer` class existence. It also records why three manual
existing-company literal-code backfills are outside D-4 publish protection.

## M1 fix round 4 — evidence consolidation (2026-08-11)

Review register:
`docs/handoff/reviews/country-defaults-phase-a/M1-round4.md`
(`CHANGES-REQUIRED` on evidence completeness only; M1 code remained green).

Review-record commit: `6c221adb5`
(`Phase 0.1.10: Record M1 round four findings`).

Tracked ticket commit: `95206724a`
(`Phase 0.1.11: Record M1 evidence obligations`).

### Files touched

Tracked:

- Modified the existing
  `docs/superpowers/tickets/2026-08-11-country-defaults-m1-p3-hardening.md`
  with M2's stamp-purpose classification decision obligation.

Session-local/ignored:

- Updated this canonical report, including its top-level status line.
- Appended the per-task
  `.superpowers/sdd/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10/milestone-M1-report.md`.

No code, test, schema, route, or new filename changed in fix round 4.

### Evidence consolidation and current counts

Fix round 4 consolidates the executed fix-round-3 mutation and the controller's exact 11-path
SQLite/PostgreSQL outputs above. No production or test code moved after that run; subsequent commits
through the round-4 register were documentation only.

Opus independently ran the nine prescribed M1 test paths at the reviewed HEAD and reported:

```text
SQLite:     54 passed / 693 assertions
PostgreSQL: 54 passed / 693 assertions
```

The controller's broader 11-path result remains the merge-gate evidence:

```text
SQLite:     65 passed / 745 assertions
PostgreSQL: 65 passed / 745 assertions
```

### Decisions and concerns

M2 must record why `PurchaseStampDuty` is globally REQUIRED while
`SalesStampDutyPayable` is SCOPE_REQUIRED despite adjacent guarded lookups: the latter carries
the non-timbre absorber-ban risk, while the former is present in every frozen chart and does not
alter absorber selection outside timbre scopes. Existing-company country changes remain outside
Phase A under S-5 and `no-existing-company-mutation`; supporting them later requires a separate
migration/backfill design.

This report is intentionally ignored/session-local. Its presence in the shared worktree is a user
deliverable, not Git provenance. The tracked ticket is the durable carry-forward artifact. The
round-1 chronology limitation remains open and ticketed; this consolidation does not recast
narrative red-first claims as independently committed proof.

### Round-4 verification commands

```bash
git show --check --oneline 95206724a
git show --format= --name-only 95206724a
rg -n "^Status:|M1-round[234]\.md|06064a3a6|86e62e2bd|95206724a|65 passed|745 assertions|54 passed|693 assertions" \
  docs/sessions/codex-country-defaults-phase-a-report.md \
  .superpowers/sdd/CODEX-DISPATCH-country-defaults-phase-a-2026-08-10/milestone-M1-report.md
git status --short --branch
```

## M1 acceptance

Final register: `docs/handoff/reviews/country-defaults-phase-a/M1-round5.md`.

Opus round 5 returned `ACCEPT` at reviewed head `e81f774d2`. It independently reproduced the
SQLite and PostgreSQL M1 results at **65 passed / 745 assertions** on each engine. No blocking or
fix-before-merge finding remained. Forward-looking P3 guard notes remain owned by the durable M1
hardening ticket and the final M7 evidence audit.

M1 pass-record commit: `cbdede844` (`Phase 0.1.13: Pass M1 invariant kernel gate`).

## M2 — central schema, models, publish/assignment lifecycle

M2 implemented only the prescribed central schema/models/services, the exact eight feature-test
files, and the existing M1 DYNAMIC-only conformance guard. No HTTP, bootstrap import,
resolver/provisioning, frontend, cache, extra audit table, or extra Phase A production/test
filename was introduced. The brief's `.claude/context/authentication.md` path is absent; the
repository authorization convention and authentication guide were read instead.

### Chronological red-first evidence

1. The direct DYNAMIC-only test failed on missing
   `ProvisioningRequiredPurposesV1::assertDynamicRequiredPurposes()` — **1 failed / 0 assertions**.
2. `CountryCodeNormalizationTest` failed on missing `AdminTemplate` —
   **2 failed / 0 assertions**.
3. The focused publish test failed on missing `TemplatePublishingService` —
   **1 failed / 0 assertions**.
4. The focused assignment revalidation test failed on missing `TemplateAssignmentService` —
   **1 failed / 0 assertions**.
5. The focused lifecycle test failed on missing `cloneToDraft()` —
   **1 failed / 0 assertions**.
6. The assigned-template direct-save regression reached
   `Direct save must not bypass assigned-template archive checks` —
   **1 failed / 9 assertions**.
7. The first PostgreSQL replay rejected the self-FK because Laravel emitted it before the fluent
   UUID primary-key command — **22 failed / 0 assertions**. Explicit PK/unique commands now precede
   every FK.
8. The direct draft→published regression reached
   `Direct draft-to-published transition must be rejected` —
   **1 failed / 1 assertion**.
9. Final staged-diff review exposed two delete bypasses: direct deletion of a published template
   and assignment-FK cascade deletion. The focused regressions failed at both guards —
   **2 failed / 14 assertions**. The model delete guard and restrictive (`NO ACTION`) composite FK
   produced a focused **2 passed / 17 assertions** replay.

The focused lifecycle/publish/fixture replay after the final fix was
**13 passed / 163 assertions**.

### Mutation/revert evidence

Four independent one-at-a-time mutations were observed red and then reverted:

- removing uppercase normalization: **1 failed / 0 assertions**;
- replacing `REQUIRED` with `MUTATED_REQUIRED` in the publish gate:
  **1 failed / 1 assertion** (`Missing bank must block`);
- reversing template lock order after strengthening the source contract:
  **1 failed / 3 assertions**;
- omitting assignment-removal audit logging:
  **1 failed / 4 assertions** because the deletion committed.

The existing conformance test additionally performs a balanced SOFT demotion for each of the exact
six DYNAMIC-only REQUIRED purposes and rejects every mutation through typed DYNAMIC evidence. The
reverted configured-lane results are **11 passed / 412 assertions** for conformance and
**2 passed / 2 assertions** for the line-keyed registration ratchet. No mutation marker remains.

### Final dual-engine test evidence

The identical explicit list of all eight prescribed M2 paths produced matching results:

```text
SQLite:     25 passed / 221 assertions, exit=0
PostgreSQL: 25 passed / 221 assertions, exit=0
```

The authoritative PostgreSQL replay explicitly used `127.0.0.1:5432`, user `houssamr`, empty
password, database `autoerp_country_defaults_test`, and `phpunit-pgsql.xml`. It ran the real forked
publish-vs-assign and archive-vs-assign races. SQLite executed equivalent deterministic lock and
transaction-contract assertions so the counts remained identical.

### Empty PostgreSQL migration proof

The dedicated scratch database `autoerp_country_defaults_m2_migrate_scratch` was recreated empty.
The default production migrator completed `migrate:fresh --force` with exit 0 and status showed all
three top-level migrations `Ran`:

```text
2026_08_11_100000_create_admin_templates_table
2026_08_11_100100_create_admin_template_accounts_table
2026_08_11_100200_create_country_template_assignments_table
```

Post-migration inspection found exactly the three tables, UUID IDs on each, and
`admin_templates.certified_country_codes=jsonb`; the assignment FK delete rule is `NO ACTION`.

### Static evidence

Every touched PHP production/test/migration path was supplied explicitly:

```text
Pint --test: {"result":"pass"}
PHPStan level 8: [OK] No errors
git diff --check: exit=0
```

### M2 decisions and ticket closure evidence

- D-4 intentionally excludes the existing-company-only
  `BackfillPayableInstrumentAccountsCommand`, `BackfillRefundCompensationAccountsCommand`, and
  `BackfillTolerancePurposesCommand`. Reopen this boundary before any of them becomes part of a
  template or new-company flow.
- `PurchaseStampDuty` is globally REQUIRED because every frozen chart carries it and it does not
  alter absorber selection. `SalesStampDutyPayable` is SCOPE_REQUIRED and forbidden in non-timbre
  and wildcard scopes because a stray row can become the runtime absorber. Per S-5, existing-company
  country changes remain outside Phase A and require a separately reviewed migration/backfill.
- The two M1 registries remain immutable pure static data: M2 added no config, environment, tenant
  state, clock, I/O, mutable state, or service location. No injected conversion is warranted.
- Lifecycle mutations use the logical central connection, transactions, row locks, constructor
  injection, and the existing audit service. The audit boundary explicitly requires an active
  central transaction. Published content/certification/account rows are immutable, and only the
  controlled services publish/archive. No provisioning-relevant cache exists.

### Files and concerns

Created the ten prescribed production/migration files and exactly eight prescribed feature-test
files. Modified only `ProvisioningRequiredPurposesV1.php` and its existing conformance test outside
that inventory. M3/M4/M7 items remain open in the existing P3 hardening ticket with their assigned
owners; no M2 blocker remains.

### M2 adversarial review remediation — lifecycle ownership and concurrency

The rejected-review findings were repaired red-first without adding migrations or test files.
Assignment persistence is now owned exclusively by `TemplateAssignmentService`: direct model
create, repoint, and delete fail closed, and the service uses explicit central query-builder writes
inside the same locked transaction as audit logging. The service requires complete certification
metadata and recomputes the canonical account hash before assignment. The exact pinned Tunisia
publish hash is
`c3436e61299a8fc0a7cdee4f4eb449e54bbef9738dccee7e22f61ec3854aafc7`.

Draft header/account edits require an active central transaction and lock/re-read the current
persisted template. Stale draft instances therefore cannot mutate a row published by a concurrent
transaction. Published and archived content remains immutable. A source ratchet rejects production
bulk Eloquent writes that would bypass these model lifecycle guards.

Every lifecycle path follows assignment-row-first, then sorted-template-row lock order. Archive and
delete add a post-template-lock phantom recheck. The race tests invoke the actual services; test
doubles pause only at capability-version seams after lock acquisition and before mutation.

Remediation RED evidence:

- assignment bypass plus metadata/hash integrity: **5 failed / 5 assertions**;
- transaction/current-row guards: **4 failed, 2 passed / 10 assertions**;
- initial actual-service PostgreSQL races: **7 tests / 43 assertions**, exposing four stale edits,
  a `40P01` archive/repoint deadlock, and the lock-order ratchet failure;
- exact hash pin: **1 failed / 7 assertions**;
- new assignment/archive phantom: **1 failed / 6 assertions**.

One-at-a-time PostgreSQL mutations were observed and restored: removing the publish template lock
gave **2 failures + 3 errors** across five races; removing the assignment template lock gave
**1 error / 4 assertions**; removing the archive phantom recheck gave **1 failed / 6 assertions**;
removing the draft header lock gave **1 failed / 6 assertions**; removing the draft account lock
gave **3 failed / 18 assertions**. No mutation residue remains. The restored dedicated race suite
passes **8 / 85**.

Final identical explicit M2 lanes pass:

```text
SQLite:     42 passed / 326 assertions, exit=0
PostgreSQL: 42 passed / 326 assertions, exit=0
```

The first PostgreSQL setup attempt hit `max_locks_per_transaction` while dropping a pre-existing
hundreds-table schema. Recreating only the dedicated `autoerp_country_defaults_test` database
cleared that environment residue, after which the identical lane passed **42 / 326**.

The configured conformance and registration ratchets remain **11 / 412** and **2 / 2**. Final
touched-path Pint returned `{"result":"pass"}` and PHPStan returned `[OK] No errors`.

The dedicated migration scratch database was recreated and default `migrate:fresh --force` passed.
All three M2 migrations were `Ran`; native UUID IDs, the certification `jsonb` column, and assignment
FK `NO ACTION` were inspected directly in PostgreSQL.

### M2 Opus round 1 remediation — topological clone and honest race evidence

The three P2 findings in the round-1 register were fixed within M2 scope and the existing prescribed
test files.

`cloneToDraft()` now derives a stable topological insertion order from its locked source rows.
External parents are inserted before descendants regardless of display `sort_order`; currently
ready rows retain their source sort order. A cycle or unresolved parent produces a domain error.
Direct publish-boundary coverage proves that `[' tn ']` is normalized before the immutable
certification scope is stored as `['TN']`.

All sequential SQLite race substitutes and all synthetic `addToAssertionCount()` padding were
removed. Seven parameterized race invocations now use explicit PostgreSQL-only skip labels on
SQLite, and a PostgreSQL runtime without `ext-pcntl` gets a clear skip rather than a fatal. The
portable lock-order/source contracts continue to run on both engines.

The safe P3 affected-row asymmetry was also closed locally: publish and archive each require one
and only one locked status-transition update before an audit can be written.

Round-1 red/mutation evidence:

- the three-level non-topological clone failed before assertions on both SQLite (`23000`) and
  PostgreSQL (`23503`);
- mutating the fixed clone back to raw sort-order insertion reproduced SQLite `23000`;
- removing `trim()`/`strtoupper()` made the new publish-boundary test fail on `' tn '`;
- the affected-row ratchet first failed **1 / 1** with zero exact-one checks, and changing one
  restored check to `!== 0` failed it again with only one of two checks found;
- every mutation was restored, and a source scan finds no race assertion padding.

Final exact prescribed M2 evidence is intentionally asymmetric:

```text
SQLite:     38 passed / 255 assertions; 7 PostgreSQL-only races skipped; exit=0
PostgreSQL: 45 passed / 329 assertions; 0 skipped; exit=0
```

The dedicated PostgreSQL race file passes **9 / 86**. SQLite runs its two portable contract tests
as **2 / 12** and clearly reports seven skips. Combined conformance/registration is **13 / 414**;
targeted Pint passes and targeted PHPStan reports no errors. A fresh replay on the dedicated
migration scratch database also passed with all three M2 migrations reported `Ran`.

### M2 Opus round 2 remediation — self-parent structural integrity

The round-two P2 is closed in the shared validator used by publish and assignment. A row can no
longer certify or assign when `parent_code === code`. The clone recovery path uses an explicit
string comparison for pending keys, so PHP's numeric-string array-key coercion does not turn a
legacy numeric self-reference into an unresolvable cycle; the published row can be cloned to a
draft and corrected.

Direct dual-engine RED evidence was **3 failed / 2 assertions** on both SQLite and PostgreSQL:
publish accepted the self-cycle, assignment accepted a re-certified self-cycle, and cloning a
legacy numeric self-parent row threw. The focused restored result is **3 passed / 5 assertions**
on each engine. Removing the shared validation guard re-red publish and assignment at **2 / 2**;
removing the `(string) $code` cast re-red the clone with the hierarchy exception. Both mutations
were restored.

The durable M2 P3 ticket was corrected in two places: its REQUIRED-literal remedy now calls for a
public classification enum or public manifest-owned constant, because the current constant is
private; and M5 now has an explicit gate requiring the authoritative resolver to throw rather than
fall back to wildcard for any timbre country lacking an exact assignment.

Final exact M2 evidence:

```text
SQLite:     41 passed / 260 assertions; 7 PostgreSQL-only races skipped; exit=0
PostgreSQL: 48 passed / 334 assertions; 0 skipped; exit=0
```

Combined conformance/registration is **13 / 414**. Targeted Pint and PHPStan are clean, and the
fresh dedicated scratch migration replay reports all three M2 migrations `Ran`.

## M3 — central-admin access control and HTTP API

M3 adds the scoped `defaults_editor` role, active-any-role central-admin authentication boundary,
the minimal auth route edit, and the default-off editor login gate. The prescribed HTTP surface is
loaded by `CountryDefaultsServiceProvider`: four controllers, fourteen FormRequests, six resources,
sixteen routes, the static ISO catalog, configuration, and English/French messages. Editor routes
have the nested super-admin capability gate; template and assignment routes permit exactly
`super_admin,defaults_editor` after central authentication. Full-admin route middleware is unchanged.

The six exact test paths were red first: **12 failed / 2 passed**, with missing routes/config and the
old auth boundary producing the expected failures. After implementation:

```text
SQLite M3:     17 passed / 422 assertions
PostgreSQL M3: 17 passed / 422 assertions
SQLite M2+M3:  63 passed / 691 assertions; 7 PG-only races skipped
PG M2+M3:      70 passed / 765 assertions; 0 skipped
```

Targeted Pint passes for every touched file except the pre-existing whole-file formatter debt in
`bootstrap/app.php`; M3 preserves all unrelated bytes there and adds only the formatted import/alias.
Targeted PHPStan level 8 reports no errors, and route inventory reports 16
Country Defaults routes with the required central-auth/capability/throttle layers and the nested
editor lifecycle gate. Bulk row mutation runs under the locked central transaction, recreates rows
parent-first to support unique-key swaps, and records before/after canonical hashes plus row counts.
Assignment database conflicts are returned as typed 409 responses instead of leaking SQL errors.

The broader architecture smoke had only known out-of-scope baseline failures: eight pre-existing
unclassified Tenant/Notification/SupportAccess controller methods and the existing dynamic POS
middleware at `POS/routes.php:93`; no M3 artifact appeared in either list.

### M3 central-validation connection fix

The first controller PG rerun revealed that `unique:central.super_admins,email` could resolve the
named `central` connection through an inherited URL pointing at `iziposcentral`, producing a 500.
A red-first duplicate-email test proved the validator did not stop the request with 422. The rule now
uses `Rule::unique(SuperAdmin::class, 'email')`, deriving both table and pinned connection from the
central model. No other M3 `unique` or `exists` rule exists.

The PG command now explicitly clears `DATABASE_URL`, `DB_URL`, and `DB_CENTRAL_URL`, then supplies
both default and central connection settings for `autoerp_country_defaults_test` on port 5432.
Fresh exact M3 results are SQLite **18 / 425** and PostgreSQL **18 / 425**. Focused Pint and PHPStan
are clean.

### M3 tenancy/authz follow-up

A fresh review found three M3 endpoint contract gaps. Red-first endpoint tests reproduced each one:
the no-scope validation request returned 422, weak alphabetic editor credentials were accepted, and
a real unrelated database-trigger failure was converted to `ASSIGNMENT_CONFLICT`. The focused
SQLite RED run was **3 failures / 72 assertions** plus one expected PostgreSQL-only skip.

`ValidateTemplateRequest` now treats the preview scope as optional. Explicit scope continues through
the unchanged M2 `CertificationScope` path. An absent scope invokes the explicitly named
scope-neutral validator, which shares structural/hierarchy/REQUIRED-purpose checks but does not infer
a country or wildcard and therefore does not run jurisdiction-specific stamp/protected-code checks.
This narrow extraction changes neither publish nor assignment validation semantics.

`AssignmentController` translates only the first-assignment race it owns: PostgreSQL SQLSTATE
`23505` plus constraint `country_template_assignments_country_domain_unique`, with the SQLite
SQLSTATE `23000`/two-column equivalent. Other `QueryException`s are rethrown. PostgreSQL coverage is
non-vacuous: two processes both observe a missing row, one commits, and the resumed writer hits the
named unique constraint; the HTTP loser is a typed 409 and exactly one row remains. A real
insert-trigger failure proves unrelated SQL errors propagate on both engines.

Create/reset requests now use the shared `Password::defaults()` rule registered by
`AppServiceProvider`; weak create/reset attempts return structured 422 responses and leave account,
password, and audit state unchanged.

Fresh results are exact M3 SQLite **21 / 444** (one PG-only skip), exact M3 PostgreSQL **21 / 453**,
accumulated M2+M3 SQLite **74 / 713** (eight PG-only skips), and accumulated M2+M3 PostgreSQL
**74 / 796** (zero skips). PostgreSQL commands were self-contained and cleared all inherited DB URL
variables. Targeted Pint passes and PHPStan reports `[OK] No errors` for the changed files.

### M3 Opus round 1 remediation

The M3 round-one register confirmed a four-eyes isolation failure, a login-only editor kill switch,
and blanket query-exception translation in the editor/row controllers. Strict endpoint RED was
**9 failures / 117 assertions** on SQLite. The cases observed support-approver listing/update/reset,
continued access after disabling the editor flag, swallowed real trigger failures, swallowed template
logic failure, and an opaque validation report.

Editor lifecycle queries now target `role=defaults_editor` at list and locked mutation time, and the
update request cannot select `support_approver`. Tests prove an allowlisted-role-shaped support
approver is absent from list output; update/reset return 404; name, role, active state, password,
existing token, and audit state remain untouched.

`EnsureCentralAdmin` now makes `external_editors_enabled` authoritative at request time. A real token
minted while enabled receives `EXTERNAL_EDITORS_DISABLED` immediately after the flag is turned off on
Country Defaults, profile, and logout routes. Super-admin and support-approver profile/logout access
remains intact. The middleware denies rather than revokes: this satisfies the mandatory call-time
boundary without making a config flip perform a hidden one-way credential mutation.

Editor conflicts are limited to PostgreSQL `23505` on `super_admins_email_unique` (and SQLite's exact
email equivalent). Template-row conflicts are limited to the named code/purpose/sort uniques and
parent FK (and their SQLite table-specific equivalents). Real trigger-raised `57014`/SQLite abort
failures propagate. Template lifecycle handling catches `DomainException` only. Validation preview
returns its concrete failed rule, and invalid scope algebra returns structured 422 from request
validation.

Deferred P3 items 6–9 are in the durable M3 hardening ticket: generated-password case guarantees,
localized capability denials, localized ISO names, and pagination.

Fresh results: exact M3 SQLite **29 / 482** (one PG-only skip), exact M3 PostgreSQL **29 / 491**,
accumulated M2+M3 SQLite **82 / 751** (eight PG-only skips), and accumulated M2+M3 PostgreSQL
**82 / 834** (zero skips). The first accumulated PG attempt failed during framework schema cleanup
with server SQLSTATE `53200`/`max_locks_per_transaction`; no stale activity remained and an unchanged
rerun passed. PostgreSQL commands clear inherited DB URL variables and specify both connections.
Targeted Pint and PHPStan are clean.

### M3 Opus round 1 re-review remediation

The re-review found that the SQLite template-row conflict classifier still accepted a unique-error
prefix and any generic `FOREIGN KEY constraint failed` message. Red-first endpoint regressions
proved both false positives: an audit insert raising SQLite-shaped SQLSTATE `23000`/foreign-key text
was returned as 409 instead of propagating, and a near-match `template_id,code_shadow` unique list
was also swallowed.

SQLite unique translation now requires one of the complete two-column signatures
`(template_id,code)`, `(template_id,system_purpose)`, or `(template_id,sort_order)`, with a terminal
signature boundary. Generic SQLite foreign-key text is translated only when `QueryException::getSql()`
is an insert, update, or delete targeting `admin_template_accounts`; audit and unrelated SQL
propagate. PostgreSQL's named-constraint behavior is unchanged. The raw English validation-rule
detail is recorded as a durable P3 to replace with translated structured rule identifiers and
parameters before M6 consumes the report.

Fresh results: exact M3 SQLite **30 / 490** (one PG-only skip), exact M3 PostgreSQL **31 / 499**,
accumulated M2+M3 SQLite **76 / 759** (eight PG-only skips), and accumulated M2+M3 PostgreSQL
**84 / 842** (zero skips). Focused Pint passes, PHPStan reports `[OK] No errors`, and
`git diff --check` is clean.

### M2 Opus round 4 remediation — general hierarchy acyclicity

The hierarchy gate now rejects cycles of every length. Once parent references are confirmed to
resolve inside the template, the shared validator executes the stable topological pass already
used by clone insertion. Any cycle leaves no ready node and raises the typed hierarchy domain
error. Publish and assignment therefore enforce the same invariant on locked rows. The clone-only
self-parent exemption remains intact so a legacy invalid published row can still be cloned and
corrected rather than becoming permanently stranded.

The self-parent check now casts both non-null `parent_code` and `code` to strings before comparing,
covering unsaved public-validator inputs with numeric/numeric-string attributes. The durable P3
ticket now also assigns M3/API ownership for translating a concurrent first-time assignment's
unique-key loser into a typed conflict response instead of a query-exception 500.

Round-four dual-engine RED was **4 failed / 4 assertions** in each lane: two-row and three-row
cycles passed both publish and assignment validation. Removing the fixed topological call recreated
all four failures. Removing the string casts made the public-validator test fail **1 / 1** because
it received the generic hierarchy error rather than the self-parent rejection. Both mutations were
restored; focused coverage passes **5 / 9** on SQLite and PostgreSQL.

Final exact evidence:

```text
SQLite:     46 passed / 269 assertions; 7 PostgreSQL-only races skipped; exit=0
PostgreSQL: 53 passed / 343 assertions; 0 skipped; exit=0
```

Combined conformance/registration is **13 / 414**. Targeted Pint and PHPStan are clean, and the
fresh dedicated scratch migration replay reports all three M2 migrations `Ran`.

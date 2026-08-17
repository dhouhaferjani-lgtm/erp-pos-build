# Codex Country Defaults Phase A Report

Branch: `codex/country-defaults-phase-a`

Baseline: `7d85232cc54abd6a6b2135f476205ab434e71a66` (`origin/dev`)

Status: M0 passed; M1 passed (Opus round 5 ACCEPT); M2 passed (Opus round 5 ACCEPT); M3 passed; M4 implementation complete, review pending

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

## M4 — bootstrap import, frozen goldens, and verification

Implementation commit: `cd73d36fd` (`Phase 0.4.0: Establish country defaults bootstrap verification`).

M4 adds an isolated legacy-seeder exporter and exact TN/FR/Generic golden byte streams, the
top-level `2026_08_11_100300` draft bootstrap migration, an importer-only immutable bootstrap-key
boundary, and the fail-closed `country-defaults:verify` command. The verifier checks every current
assignment with the full publishing gate and canonical hash, requires the TN/FR/wildcard chart
assignments, and hash-checks unassigned published history without making old historical capability
versions assignment blockers. The migration creates drafts only; authenticated human HTTP
publication remains mandatory.

The exact five M4 tests were red first at **13 failures / 3 assertions**. The red sequence also
exposed parent-FK insertion ordering and the prior M2 test's obsolete raw bootstrap-key assignment;
the final importer inserts parent-first while retaining canonical `sort_order`, and the model test
now proves the first key assignment is importer-only and every later mutation is rejected.
`tests/Support/CountryDefaults/M4Fixtures.php` is non-discoverable shared test infrastructure and
does not expand the exact `*Test.php` inventory.

Final exact M4 evidence is SQLite **14 / 60** and PostgreSQL **14 / 60**, both exit 0; the PostgreSQL
lane executes the actual `pcntl` concurrent-import race. Accumulated M1–M3 regression evidence is
SQLite **139 / 1459** with eight intentional PostgreSQL-only skips and PostgreSQL **139 / 1542**
with no skips, both exit 0. Targeted PHPStan and Pint are clean.

A fresh dedicated PostgreSQL scratch migration discovered and ran all four `100000`–`100300`
top-level migrations. The imported drafts are exact: FR **144**, Generic **61**, and TN **139**
rows. The manifest-derived missing REQUIRED set and accepted-spec content delta are empty for all
three fixtures; the scope-required stamp is present only for TN. Certification is proven only for
the intended TN, FR, and wildcard scopes independently, not for a mixed protected-code union.

### M4 review remediation

Review-fix commit: `782a2a5a1` (`Phase 0.4.1: Harden country defaults bootstrap provenance`).

The review fix replaces the generic public bootstrap-key closure with the final, narrowly owned
`LegacyCoaBootstrapImporter`. It is the sole production writer of a non-null key and the `100300`
migration is its sole caller; a production-source ratchet proves both. This minimum addition to the
dispatch §1 file map is intentional and is durably recorded in the M1 hardening ticket for M7.
Rollback now deletes only locked byte-identical untouched drafts and refuses authenticated
publication history, assignments, clone provenance, or any changed header/content.

The carried M1 ICU prerequisite is closed: Composer declares `ext-intl`, lock metadata is updated,
and canonical hashing requires a native internal `Normalizer` plus an identifiable ICU version.
Fixture verification recorded ICU **78.1** and a `php -n` polyfill-only process fails before a hash
can be accepted. The exporter now explicitly separates frozen-definition routing through
`withLegacyInsertionOrder()` from persisted-row serialization that preserves explicit sort orders.
Stale assignment capability versions raise `TemplateRecertificationRequiredException`.

The PostgreSQL import race now has a deterministic handshake/advisory-lock trigger barrier. Both
workers overlap at the unique-key boundary and one reports the importer's
`recovered_unique_conflict` outcome, proving that recovery branch rather than sequential retry.

Review-fix RED was **12 failed / 26 passed / 107 assertions** on SQLite. The first PostgreSQL
barrier attempt then produced diagnostic wait snapshots that revealed inherited pre-fork connection
purge was releasing the parent lock; the final handshake purges child connections before the parent
acquires its independent barrier session.

Fresh final evidence is exact M4 SQLite **20 / 81**, exact M4 PostgreSQL **20 / 90**, accumulated
M1–M3 SQLite **132 / 1462** with eight intentional PG-only skips, and accumulated M1–M3 PostgreSQL
**140 / 1545** with no skips. PHPStan and Pint are clean, strict Composer validation and platform
requirements pass, and the fresh migration scratch reports all four files `Ran` with FR 144,
Generic 61, and TN 139 draft rows.

## M5 — provisioning rewire

M5 introduces the uncached central resolver and template chart seeder, gates the new behavior behind
the independent default-false provisioning flag, and rewires registration, additional-company,
database, and demo provisioning consumers. Exact assignments win; timbre-capable countries cannot
use wildcard fallback; non-timbre countries may use the pinned wildcard; stale capability versions
fail with the existing recertification error. Both company-creation paths read configuration and
assignments at call time and never fall back to frozen seeders after a template-path failure.

The chart seeder preserves operator-owned account fields, promotes but never demotes system status,
and restores template parent links on rerun. Expense-category provisioning now fails loudly when
the chart is absent or a mapped code is missing; only a null mapping uses GeneralExpense.
Additional-company errors roll back the company and downstream rows, while registration errors use
the existing compensation transaction. Existing companies are not mutated.

The chronological red sequence covered the missing resolver, missing template seeder, the enabled
path still selecting legacy equity, missing expense mappings silently continuing, and both rollback
entry points. After implementation, the exact eight-file gate passes identically on SQLite and
PostgreSQL: **38 tests / 95 assertions** on each engine. Targeted production PHPStan reports no
errors, targeted Pint passes, and `git diff --check` is clean. Relevant existing accounting,
tenant, company, and expense provisioning regressions also pass on both engines (SQLite **44 / 281**;
PostgreSQL exit 0).

### M5 review remediation

Review exposed three missed consumer boundaries. The `SeedChartsCommandTest` failure was reproduced
as one `ArgumentCountError` in 8 tests / 57 assertions: its anonymous service subclass still used a
zero-argument construction after the service gained two required collaborators. The test double now
uses container-resolved collaborators without weakening the production constructor.

The pharmacy family now preserves the spec-owned `ChartOfAccountsSeederContract` seam through a
template-backed `CountryDefaultsChartOfAccountsSeeder`. `ParapharmacySeeder` supplies its locale
country parameter and `DemoPharmacySeeder` continues to specialize that parameter through its TN
country hook. Focused coverage proves the contract implementation and executes its real template
path.

The more serious owner-gate defect was `accounting:seed-charts`: it enumerates existing companies,
so delegating to the activation-aware service under the template flag could insert/promote/reparent
live accounts. The command now refuses operation before company enumeration whenever template
provisioning is enabled. Its regression test preserves a complete operator-owned account snapshot
and asserts zero additional rows.

Fresh final review-fix evidence, combining the exact eight M5 files with the affected command test,
is SQLite **50 / 174** and PostgreSQL **50 / 174**, both exit 0. Targeted PHPStan is clean and Pint
passes.

### M5 adversarial round-three remediation

The static isolation guard now detects executable class identifiers and quoted/dynamic class strings,
ignores documentation-only mentions, and requires all test consumers of frozen chart seeders to be
explicitly grouped `historical-compat`. The command owner gate now distinguishes write from preview:
flag-true writes remain refused, while `--dry-run` executes inside its existing rollback transaction
and is proven to persist zero rows.

Tenant-facing provisioning configuration failures are a typed exception family rendered before the
generic domain handler. Missing exact/wildcard assignments, unpublished assignments, and stale
capability certification keep detailed internal exception text in operator logs, but API callers see
only `COUNTRY_DEFAULTS_PROVISIONING_UNAVAILABLE` and the en/fr public-safe company-setup message.
Rollback assertions remain in place for registration and additional-company creation. The small P3
legacy parity item is pinned too: purpose-only matching preserves `is_system`; code matching alone
performs the one-way promotion.

The red sequence was 4 focused failures for dry-run/public rendering, then 2/2 static-guard failures,
then 1 purpose-match parity failure. Final exact M5 plus `SeedChartsCommandTest` passes identically:
SQLite **58 tests / 204 assertions**, PostgreSQL **58 / 204**. PHPStan and Pint are clean.

### M5 final rereview remediation

Flag-true `accounting:seed-charts --dry-run` now previews the legacy existing-chart repair through
the explicitly named, rollback-transaction-only `previewLegacyExistingChartRepair()` service seam.
It never resolves or applies an assigned template. Real-service tests cover absent and stale
assignments and prove the outer transaction persists zero accounts; write mode remains refused.

The public-safe provisioning-unavailable envelope now uses HTTP **503**, not 422, on registration
and additional-company paths in both locales while preserving rollback, no-leak assertions, and
operator diagnostics. The initial focused run failed all seven new/updated expectations. Final
SQLite and PostgreSQL evidence is identical at **59 tests / 208 assertions**; PHPStan and Pint pass.

### M5 final permitted review round

The misleading `historical-compat` classification was removed from live fixture-driven suites.
Those tests use the non-PHPUnit-group `UsesFrozenSeederFixture` attribute, while only the two true
frozen compatibility suites retain their historical group. The source guard rejects missing fixture
markers and misleading historical labels.

The chart service no longer exposes a committing preview method. The injected
`LegacyExistingChartRepairPreviewer` owns a nested transaction and unconditional rollback, returns
the three diagnostic counts, and is proven non-persistent both directly and inside a committing
caller transaction. Flag-true dry runs emit prominent legacy-only/not-template-parity warnings and
point to `country-defaults:verify` or a reviewed one-off migration. The treasury deploy checklist is
corrected accordingly.

Every round-three/four P3 is tracked in
`docs/superpowers/tickets/2026-08-12-country-defaults-m5-p3-hardening.md`; its deployment block makes
config-cache rebuild and app/Horizon/queue restart mandatory on both the flag flip and rollback, and
records the Release-1 expense behavior change. Final exact M5 plus command evidence is SQLite
**60 / 218** and PostgreSQL **60 / 218**, with clean PHPStan and Pint.

## M6 — central admin frontend

M6 adds the Country Defaults template library, hierarchy-aware account editor, certification panel,
publish dialog, and country assignment matrix without changing the established admin shell visual
language. Protected treasury/demo-consumer codes remain locked with a source-specific explanation;
draft rows validate before save; the wildcard assignment stays pinned; and re-pointing requires an
explicit confirmation that only newly created companies are affected.

The three-role policy now has one shared role-to-home matrix. Login and `/admin` route each role to
its permitted home, direct routes are guarded, and navigation is filtered to the matching backend
capabilities. All user-facing M6 and touched shell/login copy is registered in complete English and
French `adminCountryDefaults` bundles, including plural forms; Arabic safely falls back to English.

Frontend domain types originate at the actual API resource boundary: five Spatie Data DTOs now feed
the existing Country Defaults JSON resources/controller matrix, and `php artisan
typescript:transform` generates their exact frontend declarations. Generated scope and enum arrays
contain no `any`. The original RED run failed all four suites on the intentionally missing
page/guard imports. The initial M6 implementation gate then passed **4 files / 22 tests**; the i18n
raw-key suite raised that evidence to **5 files / 27 tests**. SQLite API-resource regression
evidence was **10 passed + 1 PG-only skip / 79 assertions** and PostgreSQL was **11 / 88**.

### M6 adversarial round-one remediation

Round one made query and mutation failures visible, normalized and omitted empty validation scope,
restored the shared admin translation namespace (including Arabic support navigation), retained
authoritative assignment options, adopted the canonical modal, rendered actual plural counts,
released local row edits after a successful refetch, and localized unknown protection sources.

Behavioral RED was **12 failed / 21 passed**, with two unhandled mutation-rejection diagnostics;
the backend boundary case independently failed because `?scope=` returned 422 rather than 200.
After remediation, the exact frontend gate was **4 files / 33 tests**, i18n raw-key coverage was
**5 / 5**, SQLite was **12 passed + 1 PostgreSQL-only skip / 82 assertions**, and PostgreSQL was
**13 / 13 / 91 assertions**. Typecheck, production build, scoped ESLint (zero errors), Pint,
targeted PHPStan, query-key, design-system, quantity, and custom ESLint-rule gates passed. React
Doctor's required deprecated `--diff` run reported the unchanged repository-wide **49/100**; the
supported changed-file run scanned 10 files at **93/100 with no issues**.

### M6 adversarial round-two remediation

The validation preview now accepts only a blank or syntactically complete certification scope,
debounces complete scope changes for 300 ms, and explicitly disables query retry. An incomplete
prefix therefore creates neither a query key nor a request and cannot consume the shared
30-request/minute sensitive-admin budget. The same pass removed the dead metadata write, made exact
role landing assertions non-vacuous, gave blank rows distinct localized accessible identities,
removed blank parent options, cleared a row error as soon as that row is edited, and hid catalog
metadata when assignment loading fails. Round-two findings #4 and #8 are recorded in the durable M6
P3 ticket.

The test-first command was:

```text
pnpm --filter @autoerp/web exec vitest run \
  src/features/admin/country-defaults/__tests__/TemplateEditorPage.test.tsx \
  src/features/admin/country-defaults/__tests__/AssignmentsPage.test.tsx \
  src/features/admin/__tests__/adminRoleShell.test.tsx --reporter=verbose
```

It produced the expected behavioral RED result: **2 files failed, 1 passed; 5 tests failed, 28
passed (33 total)**. The failures showed `validateTemplate('template-1', 'T')`, two indistinguishable
blank rows, a row error persisting after correction, the dead `Save details` action, and the stale
`Country catalog —` banner. The exact redirect-target assertions passed because production already
routed all three roles correctly.

An explicit post-GREEN mutation/revert replay then changed only `COMPLETE_SCOPE_PATTERN` to
`/^.*$/` and ran:

```text
pnpm --filter @autoerp/web exec vitest run \
  src/features/admin/country-defaults/__tests__/TemplateEditorPage.test.tsx \
  -t "debounces complete scopes and never validates incomplete scope prefixes" \
  --reporter=verbose
```

The mutated run failed exactly **1 / 1** selected test (13 skipped): the spy received
`['template-1', 'T']`. Restoring the complete-scope expression and rerunning the identical command
passed **1 / 1** selected test (13 skipped). This closes the prior process gap by demonstrating that
the named behavioral assertion detects removal of the production gate.

Final round-two evidence is **4 files / 38 tests** and i18n raw-key coverage is **5 / 5**. SQLite is
**12 passed + 1 PostgreSQL-only skip / 82 assertions**; PostgreSQL is **13 / 13 / 91 assertions**.
Typecheck, production build, scoped ESLint (**0 errors; 2 pre-existing test-helper warnings**), Pint,
targeted PHPStan, query-key, design-system, quantity, and custom ESLint-rule gates pass. React
Doctor's required deprecated `--diff` run remains the repository-wide **49/100** across 1,771 files;
the supported `--scope changed --base HEAD` run scanned the 5 round-two files at **100/100 with no
issues**.

### M6 adversarial round-three remediation

The validation panel now distinguishes a permanent 422 scope rejection from a transient preview
failure. English and French explain that an exact scope cannot mix fiscal-timbre and non-timbre
countries and that `*` must be used alone. An incomplete scope keeps a visible explanatory hint,
including after the publish dialog closes. This pass also removed the orphaned `updateTemplate`
client export, relies on the mutation hook's awaited invalidation for one authoritative post-save
GET before resetting local row edits, and clears keyed grid errors when a row is deleted. Durable
P3 dispositions are recorded in the M6 hardening ticket.

The focused editor RED command was:

```text
pnpm --filter @autoerp/web exec vitest run \
  src/features/admin/country-defaults/__tests__/TemplateEditorPage.test.tsx \
  --reporter=verbose
```

It produced the expected **1 file failed; 4 tests failed, 13 passed (17 total)**: the 422 response
showed the transient-unavailable message, the incomplete-scope hint was absent, a deleted row's
error reappeared when its id was reused, and save caused three total template GETs instead of two.

For mutation/revert evidence, the 422 branch was temporarily changed to always render the transient
message and the following selected test was run:

```text
pnpm --filter @autoerp/web exec vitest run \
  src/features/admin/country-defaults/__tests__/TemplateEditorPage.test.tsx \
  -t "explains timbre and wildcard scope rules when validation rejects TN,FR" \
  --reporter=verbose
```

The mutation failed exactly **1 selected test / 16 skipped** because the explicit scope rule was
missing. Restoring the status-aware branch and rerunning the identical command passed exactly
**1 selected test / 16 skipped**.

Final round-three evidence is **4 files / 41 tests** and i18n raw-key coverage is **5 / 5**. SQLite
is **12 passed + 1 PostgreSQL-only skip / 82 assertions**; PostgreSQL is **13 / 13 / 91
assertions**. Typecheck, production build, scoped ESLint (**0 errors; 1 pre-existing test-helper
warning**), Pint, targeted PHPStan, query-key, design-system, quantity, and custom ESLint-rule gates
pass. React Doctor's required deprecated `--diff` run remains the repository-wide **49/100** across
1,771 files; the supported `--scope changed --base HEAD` run scanned the 3 changed React files at
**100/100 with no issues**.

## M7 — whole-branch integration gate

M7 found and corrected one test-harness integration leak. Three out-of-transaction guard tests in
`TemplateImmutabilityTest` intentionally committed the `RefreshDatabase` transaction, then deleted
all templates during cleanup without restoring the three migration-owned bootstrap drafts. In the
literal 36-file union this caused 12 later M4 failures after 190 passes. Cleanup now reimports and
asserts the three bootstrap fixtures before reinstating the test transaction. The exact affected
sequence passes on both engines (**29 / 119** SQLite; **29 / 128** PostgreSQL), and the full union
then passes with the same 210-case inventory: SQLite **202 passed + 8 explicit PostgreSQL-only
skips / 1,682 assertions**, PostgreSQL **210 passed / 1,774 assertions**. The assertion delta is
the eight real driver/concurrency branches. A first zsh attempt is also recorded honestly: zsh did
not split the Bash manifest scalar and PHPUnit rejected the concatenated path as one missing file;
the brief's declared Bash block was then used.

Full scoped PHPStan passes over 86 files. The first full Pint gate found ten request DTOs with only
strict-type qualification/import ordering drift; Pint normalized those files mechanically, the
affected API/role suite passed **23 + 1 PG-only skip / 463 assertions**, and final full-scope Pint
passes. A fresh dedicated PostgreSQL scratch database applied every migration from empty, including
all four `2026_08_11_100000`–`100300` files, each reported `Ran`. Route inventory contains 69 admin
routes and its exact boundary tests pass **9 / 341**.

Frontend M7 evidence is **4 files / 41 tests**, with clean typecheck, full lint (0 errors; standing
repo warnings only), key/design-system/quantity/custom-rule audits, and production build. The
generated TypeScript build-info artifact was restored after the build.

The first standalone-verifier attempt exposed the former owner gate. On the fresh scratch database
it correctly exited non-zero with missing TN, FR, and `*` assignments because the migration imports drafts only.
One read-only diagnostic was mistakenly run against the configured central database
(`iziposcentral` on port 5433); it reported the missing Phase A `admin_templates` table and made no
database mutation. It is not part of the final evidence procedure. Making the scratch verifier green required
publishing and assigning the three templates. The accepted design requires an authenticated human
HTTP actor for publication; Phase A deliberately provides no CLI certification path. A synthetic,
disposable actor was created only in the named scratch database; no certification metadata or
assignment was written directly. The consolidated owner checklist now
contains the Release 1 → authenticated publish → assignment → staging/production verify → Release 2
flip and rollback sequence.

Phase A limitation (S-5): **Phase A does not wait for, and does not implement, `country_code`
immutability** (a separate settings-guards lane owns it). Phase A's certification claims cover
**unconditional template-layer timbre invariants only**. The tenant-side stamp capability check is
a **usability guard, never an authorization control**, until that lane closes.

### M7 authenticated-HTTP verification fixture — resumed 2026-08-17

Ruling: M7's pre-deployment executable gate may use only the dedicated disposable PostgreSQL
scratch database, and publication must traverse the same authenticated HTTP boundary as production.
This proves the command against a valid post-certification state without representing the fixture
as staging/production certification. The real configured `iziposcentral` database was not mutated;
the launch checklist's human Release-1 certification steps remain open.

The scratch database was rebuilt from empty, producing the three migration-owned drafts. A
disposable active `super_admin` logged in through `POST /api/v1/admin/auth/login`; its Sanctum token
then published each draft through `POST /api/v1/admin/country-defaults/templates/{id}/publish` and
assigned TN, FR, and `*` through the corresponding authenticated assignment endpoint. All publish
responses reported `published` with non-null `certified_by`; all assignment responses referenced
the intended template. With the same database environment, the literal command then returned:

```text
Country Defaults verification passed.
```

The API process was stopped immediately afterward. This is test evidence only; it does not replace
the authenticated human certification and staging/production verification required before the
Release-2 configuration flip.

### M7 complete manifest rerun — 2026-08-17

After resolving the verifier precondition, the complete accumulated manifest was rerun from the
dedicated worktree. The 36-file backend union again passed with the same 210-case inventory:
SQLite **202 passed + 8 explicit PostgreSQL-only skips / 1,682 assertions** and PostgreSQL
**210 passed / 1,774 assertions**. The difference is entirely the eight real-driver concurrency
branches. The first invocation under zsh reproduced the already documented scalar-expansion error
and ran no tests; the authoritative rerun used the brief's declared Bash interpreter.

Full PHPStan passed all **86 / 86** scoped files and Pint returned `pass`. A fresh migration run
applied the entire central schema from empty and `migrate:status` reported all four Phase A
migrations `Ran`. The bootstrap retry/idempotency evidence remains the PostgreSQL and SQLite runs
of `BootstrapKeyAssertionImportTest`: key collision, altered content, partial rows, concurrent
import, rollback/retry, and abort diagnostics. The authenticated scratch workflow then published
and assigned TN, FR, and `*`; the literal verifier exited zero. Route inventory contains **69**
admin routes and the exact boundary suite passed **9 / 9 / 341 assertions**.

Frontend evidence passed **4 files / 41 tests**. Typecheck, the complete lint/audit chain, and the
production build passed. ESLint reported **0 errors** and **6,508 standing repository warnings**;
the query-key audit had 0 new findings, design-system audit had 0 new/stale baseline findings,
quantity audit had 0 sites, and all custom ESLint rule cases passed. The generated
`tsconfig.tsbuildinfo` change was mechanically reversed after the build.

The six branch-wide assertions are carried by these named tests:

1. One timbre predicate and capability-version authority — `CapabilityAuthoritySingularityTest`.
2. All 41 purposes partitioned exactly once — `ProvisioningRequiredPurposesV1ConformanceTest`.
3. No current assignment is stale — `VerifyCountryDefaultsCommandTest` and
   `CapabilityRegistryBumpTransitionTest`.
4. No existing-company account mutation — `NoExistingCompanyMutationTest`.
5. No frozen-seeder provisioning path while enabled — `FrozenSeederProvisioningIsolationTest`.
6. Both flags default off — `DefaultsEditorLoginFlagTest` and `ProvisioningFlagMatrixTest`.

### M7 fix round one — reproducible isolation and lifecycle deduplication

The specialist rerun found that template Clone and Archive controls stayed enabled while either
mutation was pending. Because clone creation is intentionally not idempotent, a second click could
persist a duplicate full draft and audit row. A red unresolved-mutation test failed because both
buttons remained enabled. The list now treats either mutation as a shared lifecycle busy state;
the test passed after the minimal change and proves repeated Clone/Archive clicks issue no second
request. The full frontend evidence is now **4 files / 42 tests**; typecheck, full lint/audits,
production build, and scoped ESLint pass. React Doctor's required deprecated `--diff` command
retains the unrelated repository-wide **49/100** result; the supported base-pinned scan covered
the 19 branch files at **93/100**, with the one pre-existing admin-login cache warning unchanged.

The authenticated fixture is now committed as
`scripts/phase-a-authenticated-verifier-fixture.sh`, and item 6 in the executable manifest invokes
it directly. Before any database or application command, it requires an explicit destructive-test
confirmation, rejects database names outside the `autoerp_country_defaults_*_(test|scratch)`
family, validates the port, and can run the preflight alone. On execution it confirms PostgreSQL's
actual `current_database()`, pins both Laravel database variables to that same scratch database,
rebuilds it, creates only the disposable actor, and traverses authenticated login/publish/assignment
HTTP endpoints before invoking the verifier. A regression test proves `iziposcentral` is rejected
with exit 64 before execution. The committed runner was then executed end to end against
`autoerp_country_defaults_migrate_test`; all three HTTP flows and the verifier passed, and the local
API process was stopped by its exit trap. Human staging/production certification remains open.

### M7 round-one expanded-manifest remediation — 2026-08-17

The round-one adversarial review returned `CHANGES-REQUIRED`. Its register is committed at
`docs/handoff/reviews/country-defaults-phase-a/M7-round1.md`. The owner resumption authority and
its scratch-only boundary are attributable at
`docs/handoff/reviews/country-defaults-phase-a/OWNER-RESUMPTION-M7-2026-08-17.md`. The M7 backend
union now includes all 21 modified compatibility suites omitted from the first manifest, for 57
literal paths total. The PHP scope now contains 36 roots expanding to all 93 Phase A production
files. Both activation flags have an explicit default-off test and are documented false in the
development and production environment examples. All eight CI PHP setup lists install `intl`;
strict Composer validation and non-dev platform checks pass with native `ext-intl`.

The expanded union exposed two defects that the original 36-file set could not see. First,
`DocumentCancellationGlReversalTest` disabled PostgreSQL transactions for all seven tests even
though only its forked concurrency case needs committed fixtures. The focused sequence reproduced
the resulting contamination exactly: **7 failures, 14 passes / 127 assertions**, including 973
stale accounts and seven null-tax products. The exception is now restricted to the concurrency
case, and that case rebuilds the disposable test schema afterward. The same focused sequence then
passed PostgreSQL **21 / 21 / 133 assertions** and SQLite **20 passed + 1 expected skip / 125
assertions**.

Second, the Tunisian parapharmacy seeder truncated both `Vitamine C 1000mg` and `Vitamine D3 2000
UI` to the same `PARA-VITAMINE` prefix and appended only a two-digit random suffix. The first
post-isolation PostgreSQL union therefore hit a genuine probabilistic unique-SKU collision after
**363 passes**. A deterministic stable-SKU assertion was added red first; it failed with the two
random identifiers. Explicit product SKUs now derive from the full ASCII slug, the focused SQLite
test passes **2 / 2 / 11 assertions**, and the two-file PostgreSQL seeder sequence passes **5 / 5 /
26 assertions**.

Round-one backend evidence from frozen commit `ec414c4a9` is SQLite **354 passed + 10 explicit
PostgreSQL-only skips / 2,379 assertions** and PostgreSQL **364 passed / 2,480 assertions**. The
case inventory is identical; only driver-specific branches account for the skip/assertion delta.
Scoped PHPStan reports no errors over all 93 files and Pint returns `pass`. One local extraction
wrapper initially produced an empty static scope, which caused an accidental whole-backend PHPStan
attempt to hit its 512 MB limit; it was not the manifest command. The corrected literal scope is
the passing result above.

The final deployment evidence also passes: a fresh scratch migration reports all four Phase A
migrations `Ran`; the committed fail-closed runner rebuilds only
`autoerp_country_defaults_migrate_test`, performs authenticated HTTP publish and assignment for TN,
FR, and wildcard, and ends with `Country Defaults verification passed.` The configured
`iziposcentral` database was read once during an earlier failed diagnostic but was not mutated and
is not part of the final procedure. The API exit trap left no listener. Route inventory remains
**69**, with **9 / 9 / 341 assertions** green.

Final frontend evidence is **4 files / 42 tests**. Typecheck, the full lint/audit chain, and the
production build pass; ESLint reports **0 errors / 6,508 standing warnings**, all three custom rule
suites pass, and the generated TypeScript build-info artifact was restored. Specialist and final
adversarial verdicts are recorded after their frozen-diff reruns below.

### M7 fix round two — effective Laravel database identity

The frontend specialist passed the `ec414c4a9` frozen diff, but both the tenancy/authz and treasury
specialists found one Important/Critical fail-closed gap in the destructive scratch runner. Direct
`psql` verified the `PG*` target, but inherited `DB_URL`/`DB_CENTRAL_URL`, central-specific fields,
or cached Laravel configuration could make the following `migrate:fresh` resolve a different
database. The migration-only manifest item had the same exposure.

Three regression cases were added before the runner change. The focused red run was **1 pass / 3
failures / 7 assertions**: inherited URL precedence and cached configuration were accepted, while
an accepted scratch preflight did not prove Laravel's effective connection. The runner now:

- rejects non-empty `DATABASE_URL`, `DB_URL`, and `DB_CENTRAL_URL` before any application command;
- refuses the effective Laravel configuration-cache path;
- pins `DB_CONNECTION=central` plus every generic and `DB_CENTRAL_*` host, port, database,
  username, and password field, while exporting URL fields empty so `.env` cannot reintroduce them;
- loads Laravel configuration without booting providers and checks the effective driver, URL,
  host, port, database, username, default connection, and uncached state;
- queries `current_database()` through both Laravel's default and named `central` connections and
  requires both to equal the allowlisted scratch name; and
- invokes `migrate:fresh --database=central --force` explicitly.

The manifest's migration item now uses the same committed runner in `--migrate-only` mode, so both
destructive M7 paths share the fail-closed checks. The focused green is **4 / 4 / 11 assertions**;
the complete verifier test file passes SQLite and PostgreSQL **6 / 6 / 18 assertions**. PHPStan and
Pint pass that test file, and Bash syntax validation passes the runner. A malicious inherited
`DB_CENTRAL_URL` exits 64 before Laravel or database execution. The safe preflight reports the
effective scratch connection, `--migrate-only` confirms both live Laravel connections and all four
migrations `Ran`, and the full runner confirms both connections before authenticated HTTP
publication/assignment and a successful verifier. No listener remains afterward.

Final round-two backend parity from frozen commit `ab86ea880` is SQLite **357 passed + 10 explicit
PostgreSQL-only skips / 2,388 assertions** and PostgreSQL **367 passed / 2,489 assertions**, across
the same 57 literal file paths. The three additional cases are the URL, config-cache, and effective
connection protections. Production PHP scope is unchanged from the passing 93-file gate; frontend
scope is unchanged from the passing 42-test/typecheck/lint/audit/build gate.

### M7 final specialist reruns

All three required specialists passed the clean frozen `c6dc598c5` snapshot with no open
Critical/Important findings:

- **treasury — PASS:** independently reran the six verifier tests on SQLite and PostgreSQL (18
  assertions each), confirmed hostile URL exit 64, safe scratch preflight, explicit central
  migration targeting, the complete 57-file/static scopes, and the COA/purpose/default-off gates;
- **tenancy/authz — PASS:** independently confirmed URL/cache rejection, full generic and central
  pinning, effective-config and live dual-connection identity checks, safe migration/full modes,
  authenticated HTTP boundaries, and that configured `iziposcentral` still reports all four Phase
  A migrations pending; and
- **frontend conventions — PASS:** confirmed no frontend or generated-type bytes changed after its
  approved 42-test snapshot, and the final worktree was clean.

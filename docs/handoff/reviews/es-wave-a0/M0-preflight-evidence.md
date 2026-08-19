# A0 M0 preflight, fixture, and citation evidence

Date: 2026-08-12
Milestone: M0 (artifacts and test fixtures only; no production code)

## Preflight

### Amended dispatch-tip check and worktree re-pin (authoritative)

The strict `HEAD == base_sha` prerequisite below was superseded on local `dev` by
`a5520f23ca39209f5b517723037e9516808f2bca` because the administrative pin commit necessarily advances
`dev`. Before replaying any wave commits, the amended three-part check was run against that exact local
`dev` tip:

| Check | Evidence |
|---|---|
| Reviewed content baseline | `df85d43f404a9e55fd29b0e3c7533852966652df` |
| Current local `dev` dispatch tip | `a5520f23ca39209f5b517723037e9516808f2bca` (`harness: M0 base check = ancestor+digest+admin-only-delta (fixes self-referential pin equality)`) |
| Baseline ancestry | PASS: `git merge-base --is-ancestor df85d43f404a9e55fd29b0e3c7533852966652df a5520f23ca39209f5b517723037e9516808f2bca` returned exit `0` |
| Contract digest at dispatch tip | PASS: `git show a5520f23ca39209f5b517723037e9516808f2bca:docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md \| tail -n +63 \| shasum -a 256` returned `04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540  -` |
| Administrative-only baseline delta | PASS: `git diff --stat df85d43f404a9e55fd29b0e3c7533852966652df..a5520f23ca39209f5b517723037e9516808f2bca` reported 4 files changed, 62 insertions, 17 deletions, limited to the four paths listed below |
| Worktree / branch re-pin | PASS: the existing unpushed `codex/es-wave-a0` commits were replayed onto `a5520f23ca39209f5b517723037e9516808f2bca`; `git merge-base --is-ancestor a5520f23ca39209f5b517723037e9516808f2bca HEAD` returned exit `0` |
| Recovery point | `codex/es-wave-a0-pre-repin-0a6ab4ab3` preserves the pre-repin history |

The administrative-only delta contained exactly:

```text
docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md
docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md
docs/handoff/progress/es-wave-a0.progress.yaml
docs/handoff/progress/sv-stage1.progress.yaml
```

Historical review registers below retain their original pre-repin commit identifiers. Their reviewed
commits were replayed without content changes; the progress YAML records the corresponding current
commit identifiers. The already-passed M0 and M1 gates were not rerun, consistent with the harness's
resume rule.

### Original first execution (historical; equality check superseded)

| Check | Evidence |
|---|---|
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/es-wave-a0` |
| Branch | `codex/es-wave-a0` |
| Pinned base / initial HEAD | `df85d43f404a9e55fd29b0e3c7533852966652df` |
| Base log | `df85d43f404a9e55fd29b0e3c7533852966652df 2026-08-11 22:39:44 +0100 docs(es-remediation): brief gate round 2 PASS — both packages dispatch-ready` |
| HEAD equals pinned base before M0 edits | PASS: `git rev-parse HEAD` returned `df85d43f404a9e55fd29b0e3c7533852966652df` |
| Clean start | PASS with one expected controller-owned change: `git status --short` returned only ` M docs/handoff/progress/es-wave-a0.progress.yaml` (base/branch/status initialization required by the dispatch) |
| Contract command | `git show df85d43f404a9e55fd29b0e3c7533852966652df:docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md \| tail -n +63 \| shasum -a 256` |
| Observed digest | `04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540  -` |
| Expected digest | `04760455ac3f80b96502884e9efc2a8f00c35785d2126a9f9786d96d97e20540` |
| Digest verdict | MATCH |
| Original baseline | `VerifyEventChainCommandTest.php`: 15 passed (31 assertions); `ParseFailureResumeTest.php`: 19 passed (80 assertions). These runs used the default SQLite configuration, despite the original label saying PostgreSQL. They are superseded by the explicit PostgreSQL runs below. |

## Authoritative PostgreSQL fixture runs

Every run used the dedicated disposable PostgreSQL 15 database and the explicit PG configuration; the files ran serially:

```text
$ APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php

PASS  Tests\Feature\Fiscal\VerifyEventChainCommandTest
Tests:    18 passed (68 assertions)
Duration: 36.76s

$ APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/ParseFailureResumeTest.php

PASS  Tests\Feature\Fiscal\ParseFailureResumeTest
Tests:    20 passed (94 assertions)
Duration: 28.55s
```

### Harness fix round 2 — PostgreSQL and formatting evidence

The shared test-support trait refactor was verified serially with the same explicit PG configuration:

```text
$ cd apps/api && ./vendor/bin/pint tests/Traits/ReadsCanonicalBytes.php tests/Feature/Fiscal/VerifyEventChainCommandTest.php tests/Feature/Fiscal/ParseFailureResumeTest.php
{"result":"fixed","files":[{"path":"tests\/Feature\/Fiscal\/VerifyEventChainCommandTest.php","fixers":["class_attributes_separation","ordered_traits"]},{"path":"tests\/Feature\/Fiscal\/ParseFailureResumeTest.php","fixers":["ordered_traits","braces_position","single_line_empty_body"]}]}

$ cd apps/api && ./vendor/bin/pint --test tests/Traits/ReadsCanonicalBytes.php tests/Feature/Fiscal/VerifyEventChainCommandTest.php tests/Feature/Fiscal/ParseFailureResumeTest.php
{"result":"pass"}

$ APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/VerifyEventChainCommandTest.php

PASS  Tests\Feature\Fiscal\VerifyEventChainCommandTest
Tests:    18 passed (68 assertions)
Duration: 31.91s

$ APP_ENV=testing APP_KEY='base64:71Dy19GJfnJyC8FcCpA0Z2YJ+7B68g/Rrv5e/QSe6xY=' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=autoerp_es_wave_a0_test DB_CENTRAL_DATABASE=autoerp_es_wave_a0_test DB_USERNAME=houssamr DB_PASSWORD='' php artisan test -c phpunit-pgsql.xml tests/Feature/Fiscal/ParseFailureResumeTest.php

PASS  Tests\Feature\Fiscal\ParseFailureResumeTest
Tests:    20 passed (94 assertions)
Duration: 31.17s
```

## Seeded v3 fixture and tamper toolkit

The fixture extensions remain test-local and build on the two prescribed Fiscal feature-test files.

| Shape | Helper and test anchor | Self-asserted contract |
|---|---|---|
| Seeded v3 tenant fixture | `VerifyEventChainCommandTest::seedV3FiscalFixture()` at `apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:492`; contract test at `:443` | terminal `fiscal_schema_version >= 3`; valid UUID event/receipt ids; exactly `operational` and `z_session` contexts on the same terminal; projected receipt has non-null `fiscal_event_id`; join reports zero `fiscal_hash`/`current_hash` mismatches; current `ReceiptHashService` failure on the context-flattened sequence stream is characterized explicitly as an unfixed defect, not desired behavior |
| T-a payload rewrite | `ParseFailureResumeTest::seedPayloadRewriteTamper()` at `apps/api/tests/Feature/Fiscal/ParseFailureResumeTest.php:691`; contract test at `:193` | drives the real `ParseFailureResolutionService::resolve()` path on a sealed v3 parse-failed row; payload becomes parsed/verified and non-null while resource-safely decoded `canonical_bytes` and `current_hash` remain byte-for-byte unchanged; independently decoded frozen semantics are exactly `['a' => 2]`, while corrected persisted semantics carry subtotal `10.02`, rounding `-0.02`/`0.05`, and product `prod-default` with no `a` key; `current_hash` still hashes the frozen bytes |
| T-b wrong context head | `VerifyEventChainCommandTest::seedWrongContextPreviousHashTamper()` at `apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:561`; contract test at `:478` | affected row is `operational`; its `previous_hash` equals the same terminal's `z_session` head and differs from the operational head; its `current_hash` still equals SHA-256 of its own resource-safely decoded `canonical_bytes` |
| T-c mirror mismatch | `VerifyEventChainCommandTest::seedReceiptMirrorTamper()` at `apps/api/tests/Feature/Fiscal/VerifyEventChainCommandTest.php:595`; contract test at `:483` | isolated single-context fixture; the current `ReceiptHashService::verifyTerminalChain()` path is asserted green immediately before and after the mismatching receipt insert; receipt references the intended internally valid fiscal event and resource-safely mirrors its canonical bytes; its receipt-chain `previous_hash` matches the prior receipt; exactly one valid 64-hex `fiscal_hash` disagrees with its event's `current_hash` |

T-a uses the production resolution workflow. T-b and T-c use direct test-only INSERTs because PostgreSQL immutability triggers correctly prohibit rewriting sealed chain coordinates and receipt hashes after insertion; insertion is therefore the honest way to construct deliberately invalid persisted history without weakening production enforcement.

Both test files share the single-purpose test-only `Tests\Traits\ReadsCanonicalBytes` utility at `apps/api/tests/Traits/ReadsCanonicalBytes.php:7-30`; it preserves strings, consumes PostgreSQL BYTEA resources through `stream_get_contents()`, and fails explicitly when a stream read fails.

The clean v3 fixture intentionally contains `operational` sequence 1 and `z_session` sequence 1 on one terminal. `ReceiptHashService::verifyTerminalChainFiscalArm()` currently selects every fiscal event for the terminal, orders only by `sequence_number`, and carries one expected head across the result. It does not partition by `chain_context`. Consequently, the second sequence-1 row presents genesis rather than the first row's hash and the existing service returns false with `linkage_broken`. The fixture test now characterizes this current failure explicitly so it cannot drift silently. T-c is intentionally single-context because its sole divergence must be the absent receipt/event hash-mirror comparison, not this known context-flattening behavior.

### Concern / unregistered production defect (scope signal)

`ReceiptHashService::verifyTerminalChainFiscalArm()` context flattening is an **UNREGISTERED PRODUCTION DEFECT / scope signal**, not a behavior M0 plans to fix: its context-blind terminal stream at `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:236-239` carries one head across contexts at `:245,:304`; the live `Nf525DataProvider::verifyReceiptChain()` caller delegates to this arm at `apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:341-405`, including the live endpoint note at `:343-345` and delegation at `:398`; and the consolidated register states at `docs/handoff/ES-CONSOLIDATED-REGISTER-2026-08-11-SNAPSHOT.md:132` that every v3 terminal has `z_session` plus `operational` contexts. This makes a false linkage failure reachable on the normal v3 shape. Consequently, M2's green-on-clean clause and A1's seeded-v3 entry criterion cannot honestly use the required two-context fixture until this defect is resolved in an explicitly authorized milestone or formally re-scoped; it remains a concern/ticket for orchestration and is not absorbed into M0's no-production-code scope.

T-b is already detected by `VerifyEventChainCommand::walkChain()`: that query is scoped by `chain_context`, so its operational sequence-2 row is compared with the operational head and reports `previous_hash linkage mismatch`. M1 must treat this as an existing check, not claim T-b's RED as evidence of a newly added check.

## ES-07 correction table

| Claim | Verdict | Current evidence |
|---|---|---|
| Projected receipts are excluded; a projected-only receipt arm can report valid over count 0 | **STANDS** | `VerifyPosChainCommand::verifyReceiptChain()` `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:293-318`, especially `whereNull('fiscal_event_id')` at `:304` and the zero-count success at `:310-315`; the same carve-out is in `findReceiptChainBreak()` `:335-346`, predicate at `:341` |
| No `pos_receipts.fiscal_hash` ↔ `fiscal_events.current_hash` mirror comparison | **STANDS** | `rg -n "fiscal_hash.*current_hash\|current_hash.*fiscal_hash" apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php` returned no matches (exit 1) |
| Blanket “zero fiscal-era rows / every projected row excluded” claim | **WITHDRAWN** | `VerifyPosChainCommand::verifyZReportChain()` counts all Z rows without a projection filter at `apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:372-398`; `ZReportHashService::verifyZReportChain()` delegates to the fiscal arm at `apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php:210-216`; `verifyFiscalEventsArm()` walks `z_session`/`training_z_session`, re-hashes canonical bytes, and verifies linkage at `:251-293` |

Receipt verifier partition anchors:

- `ReceiptHashService::verifyTerminalChain()` — `apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:206-213`; calls the fiscal arm, then the legacy arm.
- `ReceiptHashService::verifyTerminalChainFiscalArm()` — `:234-308`; walks all `fiscal_events` for a terminal in one `sequence_number`-ordered stream, without a `chain_context` predicate, hashes `canonical_bytes` against `current_hash`, and checks one carried genesis/prior linkage.
- `ReceiptHashService::verifyLegacyArm()` — `:352-398`; reapplies `whereNull('fiscal_event_id')` at `:355` and performs per-row legacy/v3 hash-algorithm verification.

The Z arm is already fiscal-era-aware and must not be rewritten as though it were blind.

## Citation-freshness sweep

The `old` side reproduces every distinct code citation in the dispatch and snapshot for ES-06, ES-07, ES-08, ES-09, ES-16, ES-17, ES-41, ES-42, and ES-43. The `new` side was re-derived in the pinned-base worktree. Ranges that did not drift remain identical.

| Row | `old:line → new:line` | Named symbol | Semantic anchor |
|---|---|---|---|
| ES-06 | `ParseFailureResolutionService.php:63-80 → apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:63-80` | class contract | frozen `canonical_bytes`; corrected payload is validated independently rather than re-deriving the sealed bytes |
| ES-06 | `ParseFailureResolutionService.php:165-179 → apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:165-179` | `resolve()` | UPDATE writes `payload`, parsed/verified status, and resolver stamps but does not write canonical bytes |
| ES-06 | `ParseFailureResolutionService.php:291-351 → apps/api/app/Modules/Fiscal/Application/Services/ParseFailureResolutionService.php:291-351` | `assertPayloadValidatesAgainstDto()` | validates operator-supplied payload against DTO/key-set/per-event constraints |
| ES-06 / ES-41 | `2026_05_14_100002_…:49-51,73-111 → apps/api/database/migrations/tenant/2026_05_14_100002_create_fiscal_events_immutability.php:49-51,73-111` | `up()` / `fiscal_events_immutability_trigger()` | PostgreSQL-only gate and frozen identity/chain/canonical/signature column whitelist |
| ES-07 | `VerifyPosChainCommand.php:257 → apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:257` | `handle()` success banner | prints “All chains verified successfully.” after aggregate success |
| ES-07 | `VerifyPosChainCommand.php:293-330 → apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:293-330` | `verifyReceiptChain()` | projected-receipt exclusion, zero-count success, and service delegation |
| ES-07 | `VerifyPosChainCommand.php:293-323 → apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:293-323` | `verifyReceiptChain()` | dispatch R-2/M2 range: projected-only count/filter, zero-count branch, service delegation, and break lookup |
| ES-07 | `VerifyPosChainCommand.php:293-318 → apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:293-318` | `verifyReceiptChain()` | corrections-addendum range through the `ReceiptHashService` delegation |
| ES-07 | `VerifyPosChainCommand.php:310-316 → apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:310-316` | `verifyReceiptChain()` zero-count branch | returns `is_valid: true`, `count: 0` when the filtered receipt count is zero |
| ES-07 | `VerifyPosChainCommand.php:318 → apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:318` | `verifyReceiptChain()` delegation | invokes `ReceiptHashService::verifyTerminalChain()` after the count gate |
| ES-07 | `VerifyPosChainCommand.php:337-346 / :341 → apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:337-346 / :341` | `findReceiptChainBreak()` | duplicate `whereNull('fiscal_event_id')` receipt carve-out |
| ES-07 | `VerifyPosChainCommand.php:335-346 → apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:335-346` | `findReceiptChainBreak()` | M2 range covering the projected-receipt exclusion in break diagnosis |
| ES-07 | `VerifyPosChainCommand.php:372-399 → apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:372-399` | `verifyZReportChain()` | counts every Z report; no `fiscal_event_id` filter |
| ES-07 | `VerifyPosChainCommand.php:372-398 → apps/api/app/Modules/POS/Commands/VerifyPosChainCommand.php:372-398` | `verifyZReportChain()` | R-1/addendum range: all-row Z count and delegation without a projection filter |
| ES-07 | `ReceiptHashService.php:178-213 → apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:178-213` | `verifyTerminalChain()` | documents and invokes independently partitioned fiscal and legacy arms |
| ES-07 | `ReceiptHashService.php:234-252 → apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:234-252` | `verifyTerminalChainFiscalArm()` | reads fiscal-event canonical bytes/current hashes and performs re-hash |
| ES-07 | `ReceiptHashService.php:334-398 → apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:334-398` | `verifyLegacyArm()` | legacy partition and hash verification; projected receipts excluded at `:355` |
| ES-07 | `ZReportHashService.php:210-216 → apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php:210-216` | `verifyZReportChain()` | fiscal-events arm runs before legacy Z arm |
| ES-07 | `ZReportHashService.php:251-293 → apps/api/app/Modules/POS/Domain/Services/ZReportHashService.php:251-293` | `verifyFiscalEventsArm()` | z-session contexts, canonical re-hash, genesis/prior linkage |
| ES-08 | `VerifyEventChainCommand.php:83-85 → apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:83-85` | class contract | explicitly documents no fleet-wide mode |
| ES-08 | `VerifyEventChainCommand.php:68-100 → apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:68-100` | command contract/signature | required single-tenant binding and terminal/context options |
| ES-08 | `VerifyEventChainCommand.php:198-260 → apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:198-260` | `verifyBoundTenant()` | actor lookup, tenant-scoped permission gate, and try/finally registrar restoration |
| ES-08 | `VerifyEventChainCommand.php:363-436 → apps/api/app/Modules/Fiscal/Infrastructure/Commands/VerifyEventChainCommand.php:363-436` | `walkChain()` | re-hashes canonical bytes and verifies previous-hash linkage only |
| ES-09 | `TerminalRegistrySnapshotService.php:284,288 → apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:284,288` | fiscal event insert | unconditional Verified/Parsed stamps |
| ES-09 | `TerminalRegistrySnapshotService.php:443-464 → apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:443-464` | `resolveChainPlacement()` | prior head is filtered by tenant+terminal only; company/context absent |
| ES-09 | `TerminalRegistrySnapshotService.php:443-463 → apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:443-463` | `resolveChainPlacement()` | dispatch R-6/M4 exact range for the context-blind prior-head query and returned placement |
| ES-09 | `VirtualAdminFiscalEventService.php:137,141,295,299 → apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:137,141,295,299` | two fiscal event inserts | unconditional Verified/Parsed stamps at both live authoring paths |
| ES-09 | `VirtualAdminFiscalEventService.php:372-389 → apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:372-389` | `resolveChainPlacement()` | prior head is filtered by tenant+terminal only; company/context absent |
| ES-09 | `VirtualAdminFiscalEventService.php:372-388 → apps/api/app/Modules/POS/Application/Services/VirtualAdminFiscalEventService.php:372-388` | `resolveChainPlacement()` | dispatch R-6/M4 exact range for the context-blind prior-head query and returned placement |
| ES-09 | `OutboxIngestor.php:172-181 / :173-179 → apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:172-181 / :173-179` | ingest prior-head query | correct contrast: tenant+company+terminal+chain-context scoping before linkage verification |
| ES-09 | `2026_05_24_100000_add_chain_context_to_fiscal_events.php:20-31 → apps/api/database/migrations/tenant/2026_05_24_100000_add_chain_context_to_fiscal_events.php:20-31` | `up()` constraint | unique coordinate includes tenant, company, terminal, context, and sequence; allowed contexts constrained |
| ES-16 | `OutboxIngestor.php:918-924 → apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:918-924` | `dispatchProjections()` | suppresses projections for canonical parse failures and z-session lifecycle failures |
| ES-16 | `DeadLetteredProjectionsController.php:105-119 → apps/api/app/Modules/Fiscal/Presentation/Controllers/DeadLetteredProjectionsController.php:105-119` | `index()` ingress-quarantine arm | only surfaces canonical-parse-failure fiscal events with no projection rows |
| ES-17 | `FiscalEventQuarantine.php:93-119 → apps/api/app/Modules/Fiscal/Domain/Models/FiscalEventQuarantine.php:93-119` | `$fillable` | insert-time envelope fields only; lifecycle/classification fields deliberately absent |
| ES-17 | `no writer found in app/ → no writer found in apps/api/app/` | application-wide lifecycle sweep | `rg -n "resolved_at\|resolved_by" apps/api/app/Modules/Fiscal -g '*.php'` finds only docs/casts and reads for `FiscalEventQuarantine`; no assignment/update of quarantine-table `resolved_at` or `resolved_by` |
| ES-41 | `2026_07_31_940000_…:47-49,60-63 → apps/api/database/migrations/tenant/2026_07_31_940000_allow_sealed_hash_algorithm_backfill_transition.php:47-49,60-63` | `up()` / `prevent_receipt_modification()` | PostgreSQL-only gate; pending-seal→fiscalized branch immediately returns NEW without column guards |
| ES-42 | `Fiscal/routes.php:29-31 vs :33,:35,:39 → apps/api/app/Modules/Fiscal/routes.php:29-31 vs :33,:35,:39` | fiscal route group | ingestion route has auth/tenant middleware but no `can:`; sibling write routes have explicit permissions |
| ES-42 | `RolesAndPermissionsSeeder.php:515-520 → apps/api/database/seeders/RolesAndPermissionsSeeder.php:515-520` | `createRoles()` | iterates the role-permission grant map and synchronizes each non-admin role's seeded permissions |
| ES-42 | `RolesAndPermissionsSeeder.php:590-597 → apps/api/database/seeders/RolesAndPermissionsSeeder.php:590-597` | `rolePermissionGrants()` manager entry | manager role includes `pos.operate_terminal` among its POS grants |
| ES-42 | `RolesAndPermissionsSeeder.php:638-658 → apps/api/database/seeders/RolesAndPermissionsSeeder.php:638-658` | `rolePermissionGrants()` cashier entry | cashier role includes `pos.operate_terminal` among its POS grants |
| ES-42 | `ZReportSyncController.php:61 → apps/api/app/Modules/POS/Presentation/Controllers/ZReportSyncController.php:61` | `sync()` | sibling device sync surface authorizes `pos.operate_terminal` |
| ES-42 | `apps/pos/src/lib/api.ts:61-80 → apps/pos/src/lib/api.ts:61-80` | `getHeaders()` | POS client sends its Bearer token and `X-Company-Id` on API requests |
| ES-42 | `AuthController.php:291-310 → apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:291-310` | login token issuance | registers the device and mints the tenant-claimed Sanctum token using POS abilities for the POS client |
| ES-42 | `apps/pos/src/lib/sync/syncService.ts:406-440 → apps/pos/src/lib/sync/syncService.ts:406-440` | `pushOfflineReceipts()` | legitimate device path selects pending fiscal events and POSTs each envelope to `/pos/sync/fiscal-events` |
| ES-43 | `HashChainIntegrityProvider.php:11-24 → apps/api/app/Modules/Fiscal/Application/Services/HashChainIntegrityProvider.php:11-24` | `version()`, `computeHash()`, `verify()` | unkeyed SHA-256 only; no signature operation |
| ES-43 | `OutboxIngestor.php:818 → apps/api/app/Modules/Fiscal/Application/Services/OutboxIngestor.php:818` | `buildInsertRow()` | `signature_status` hardcoded to `not_required` |
| ES-43 | `2026_05_14_100002_create_fiscal_events_immutability.php:71,:80,:96-100 → apps/api/database/migrations/tenant/2026_05_14_100002_create_fiscal_events_immutability.php:71,:80,:96-100` | `fiscal_events_immutability_trigger()` frozen-column guard | chain/signature identity is immutable, including signature version/status/algorithm/value/counter/provider, so any later signature scheme is forward-only |

Citation sweep result: **resolved = 47; unresolved = 0**.

## Non-production validation fields

V1 `—`

V2 `—`

V3 `—`

No production file, event, verifier, Z-report arm, route, migration, or resolution policy was changed in M0.

# Codex plan gate r7 — RBAC waves 2, 3, 4 plans rev 7 (gpt-5.6-sol, high, read-only, 2026-09-11)

`git rev-parse --short HEAD` → **`78ffe4356`**

Code was checked at `dev` **`33796cc08`**, using the shared checkout where Artisan boots. Producer contracts were checked at wave 0b rev 6.3 **`5257eb0f1`** and wave 1 rev 6.4 **`70384d7e0`**.

## Wave 2

### Rev-6 closure table

| Rev-6 item | Status | Verification |
|---|---|---|
| W2-M1 — stale hand-added permission instruction | CLOSED | All three A-2 keys now derive through the scaffold contract; no `legacy()` or producer edit is prescribed (`docs/superpowers/plans/2026-09-10-rbac-wave-2.md:131-147,4217`). |
| W2-M2 — invalid `Phase 0.4.2a` | CLOSED | Replaced by numeric `Phase 0.4.10` (`…-rbac-wave-2.md:1116-1117,4218`). |
| W2-m1 — wrong `countings` scaffold resource | CLOSED | The fallback now uses `Inventory inventory.countings create`, correctly deriving `inventory.countings.create` (`…-rbac-wave-2.md:2573`). |
| W2-m2 — stale pre-A-2 spec claims | CLOSED | Spec rev 9.2/A-2 is correctly acknowledged (`…-rbac-wave-2.md:2570,4220`). |
| W2-m3 — stale wave-1 pin | CLOSED | Re-pinned to rev 6.4 `70384d7e0` (`…-rbac-wave-2.md:15,4221`). |

### BLOCKER

None.

### MAJOR

None.

### MINOR

None.

Wave 2 is **DISPATCH-READY subject to its declared entry conditions and OQ-1/OQ-2 rulings**.

The requested contract audit is complete:

- `principal_kind` has a closed-domain CHECK, service-shape CHECK, equivalent SQLite triggers, destructive-but-audited `down()`, and restoration of the prior users schema (`…-rbac-wave-2.md:2784-2880`).
- Login ordering is correct relative to current code: user lookup precedes the planned kind refusal, which precedes `Hash::check` at `dev:apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:253-258`. The POS path similarly resolves through `pinHolders()` before `Hash::check` at `dev:apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-56,87-90`.
- Dedicated service-account routes, typed FormRequests, `AssignableRole`, generic `UserController` and `RoleController` 422 refusals, memberships, token issue/revoke, and PIN-surface exclusions are concrete (`…-rbac-wave-2.md:3064-3264,3847-3858`).
- The writer/recipient census covers the spec’s complete registration, provisioning, password-reset, email-verification, POS PIN, notification, invitation, enrichment and seeder sets (`docs/superpowers/specs/2026-09-10-roles-permissions-catalogue-design.md:320-352`; `…-rbac-wave-2.md:2886-2940`).
- All six human-seat counters and every plan tier, including trial, are covered; `max_service_accounts` is independently enforced (`…-rbac-wave-2.md:3798-3837`; current counts at `dev:apps/api/app/Services/PlanLimitsService.php:83-85`, `dev:apps/api/app/Modules/Billing/Application/Services/PlanEnforcementService.php:162-176,290-305,426-444`, and `dev:apps/api/app/Http/Controllers/Api/Admin/SuperAdminController.php:88-97`).
- Token abilities are narrowed to `permission:<key>`, explicit human/service/POS TTLs are supplied, the `tenant:` claim is enforced, and `EnforceTokenScope` is both attached and ordered in the global API group with a non-tenant subject no-op (`…-rbac-wave-2.md:2958-3015`).
- `ScopedTokenIssuanceEntryConditionTest` is falsifiable: it checks both the generated shrink-only baseline and live source for each bypass idiom; editing only the baseline cannot green it (`…-rbac-wave-2.md:2582-2645`).
- Resolver subject/target semantics, `token_id`, self projection, self-or-`roles.view` endpoint authorization, membership revocation/retry, system roles, deterministic shared locking, domain events, attribution, denial dedup, names-only shaping, generated frontend permissions, alias deletion and Playwright probes are all executable tasks rather than prose stubs (`…-rbac-wave-2.md:1299-1462,1473-1774,1927-1969,2693-2743,3282-3776`).

## Wave 3

### Rev-6 closure table

| Rev-6 item | Status | Verification |
|---|---|---|
| W3-B1 — actors lacked active memberships/header and deny oracle was ambiguous | CLOSED | Both actors now receive active memberships, every request sends `X-Company-Id`, and denials assert `FORBIDDEN` while excluding the company-context codes (`…-rbac-wave-3.md:3237-3295,3309-3325,3386-3407`). This matches `dev:apps/api/tests/Feature/Identity/RBACTest.php:55-74`, `dev:apps/api/app/Http/Middleware/CompanyContextMiddleware.php:107,123-141`, and the migration default at `dev:apps/api/database/migrations/tenant/2025_11_30_106000_create_user_company_memberships_table.php:47`. |
| W3-B2 — missing `Http` and `LogicException` imports | CLOSED AS SCOPED | Those imports, plus `UserCompanyMembership` and `TestResponse`, are present (`…-rbac-wave-3.md:3174-3188`). The broader “class is runnable” claim is nevertheless false because of the new blocker below. |
| W3-m1 — stale spec/producer labels | CLOSED | Spec rev 9.2 and wave 1 rev 6.4 are declared (`…-rbac-wave-3.md:15,3754-3762`). |

### BLOCKER

1. **The supplied matrix imports the wave-1 registry from a namespace that does not exist.**

   `RolePermissionMatrixTest` imports:

   `App\Modules\Identity\Domain\Authorization\PermissionRegistry`

   at `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:3176`, then resolves it from the container at `:3304`. Wave 1 actually creates:

   `App\Shared\Domain\Authorization\PermissionRegistry`

   at `70384d7e0:docs/superpowers/plans/2026-09-10-rbac-wave-1.md:173,855-863`.

   `php -l` cannot detect a syntactically valid but nonexistent imported class. The first matrix cell will fail during container resolution rather than test authorization. Replace the import with the exact wave-1 namespace and re-run the extracted class against the lane containing wave 1.

2. **Task 3-7’s “exact” add list consumes two nonexistent wave-1 POS filenames.**

   The plan stages `PosPermissionManifest.php` and `PosPermission.php` at `…-rbac-wave-3.md:1580-1581`. Wave 1 ships uppercase acronym filenames `POSPermissionManifest.php` and `POSPermission.php` at `70384d7e0:…-rbac-wave-1.md:2929,3510,3636,4446`.

   On a case-sensitive filesystem, the printed `git add` fails. Correct both operative paths. This is the only wave-1 artifact-name re-pinning required; no producer change is owed.

### MAJOR

None beyond the two dispatch blockers.

### MINOR

None.

The enforcement arithmetic itself is sound. Re-executing the embedded generator against fresh `dev` routing produced:

- 1,054 API routes.
- `642 MIDDLEWARE / 142 AUTH_ONLY / 130 CONTROLLER / 68 SUPERADMIN_ONLY / 46 FORMREQUEST / 18 PUBLIC / 8 POLICY`.
- Enforcement-style baseline: **176 = 130 controller + 46 FormRequest**.
- POS: **92 rows across 25 classes**, consisting of 91 controller rows and `ManagerPinController`’s one FormRequest row.

The CSV is valid quoted CSV; the old “unescaped comma” explanation was the error. The generator and corrected totals are at `…-rbac-wave-3.md:409-833,912-980,3814`.

The embedded fixture JSON also parses cleanly: 20 unique sorted entries, with 8 create, 8 update, 2 delete and 2 count expectations. Eighteen resolve against current `dev`; the two remaining route keys are precisely the wave-2b service-account routes. Module order, POS-last decomposition, FormRequest rule, existing `fetchDiscountPermissions` wiring, post-wiring ladder removal, sole `MembershipRole` authorization read, glossary parity, retirement conditions, sidebar rename and per-module reviewer gates remain correct (`…-rbac-wave-3.md:129-136,203,331-371,878-903,1415-1643,3520-3528`; current exception at `dev:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-940`).

Per-wave verdict: **CHANGES-REQUIRED**.

## Wave 4

### Rev-6 closure table

| Rev-6 item | Status | Verification |
|---|---|---|
| W4-B1 — staging environment not transmitted | CLOSED | `staging_env`, `env_compose` and `env_docker_flags` are present (`…-rbac-wave-4.md:622-698,1937-1985,2451-2489`). The fake-SSH run carried PostgreSQL connection/host/port/database variables and Redis host/port/db/`CACHE_STORE` in the generated remote commands. |
| W4-B2 — EC-16a observed before mutation and asserted nothing | CLOSED | The observer is after the row, within the pause, tenant-correlated and fails on zero matching jobs (`…-rbac-wave-4.md:2274-2289,2534-2570`). |
| W4-B3 — replay flag/selection unused | CLOSED | Manifest-driven selection and `replay_selection_guard` are now implemented (`…-rbac-wave-4.md:2426-2494`). |
| W4-M1 — evidence document remained a stub | **NOT CLOSED** | A working generator body was added, but it cannot participate in Task 4-1’s prescribed commit; see blocker 1. |
| W4-M2 — alphanumeric fix ordinals | CLOSED | Numeric `0.7.6` through `0.7.31` are reserved (`…-rbac-wave-4.md:2732-2735`). |
| W4-m1…m5 | CLOSED | Shared-library ownership, onboarding citations, preflight prose, `covers` equality and revision pins are corrected (`…-rbac-wave-4.md:3177-3183`). |
| Self-found TAB/IFS defects | CLOSED | US `0x1f` records and helper-local `IFS` are present (`…-rbac-wave-4.md:1937-1985,2348-2418,3182-3183`). |

### BLOCKER

1. **Task 4-1 still cannot commit the promised generated 58-row evidence template.**

   The document body still contains the literal placeholder `…52 further rows…` at `docs/superpowers/plans/2026-09-10-rbac-wave-4.md:359-370`, despite claiming it is not a stub at `:372`.

   The supplied generator reads the manifest and register JSON at `:400-406`, but those files are introduced only in Task 4-2 (`:526`, exact add list `:1619-1620`). Task 4-1 is explicitly “written FIRST” (`:332-340`) and its exact add list stages only four documentation files (`:515-521`). The generator itself:

   - is absent from the created-file inventory at `:161-178`;
   - is absent from Task 4-1’s Files declaration at `:334`;
   - is absent from every exact `git add` list.

   Following the plan literally therefore commits the ellipsis and leaves `apps/api/tools/generate-evidence-rows.php` untracked. Move the manifest/register creation before generation, add the generator to the file inventory and an exact commit list, commit the actual 58 generated rows, and run `--check` in the same commit.

2. **The staging cleanup trap does not terminate on `INT` or `TERM`; the destructive campaign resumes after cleanup.**

   The plan claims cleanup “fires on … an interrupt” at `…-rbac-wave-4.md:2307-2311`, installs the same returning function for `EXIT INT TERM`, clears all traps, and merely `return`s at `:2312-2335`.

   Bash behavior is concrete: an `INT` handler that returns resumes at the interrupted script location. A reproduction of this exact shape printed `cleanup-rc=0` followed by `after`. In the campaign, an operator pressing Ctrl-C can therefore cause the handler to resume Horizon, drop the isolated databases, clear the EXIT trap, and then continue executing the remaining campaign against resources it just destroyed.

   Use distinct signal handlers or pass an explicit termination status: cleanup followed by `exit 130` for `INT` and `exit 143` for `TERM`, while retaining the returning EXIT handler for ordinary completion.

### MAJOR

None beyond the blockers.

### MINOR

1. **The generator’s `--check` is weaker than its equality claim.**

   It uses two `array_diff` calls at `…-rbac-wave-4.md:471-481`. This detects missing or unknown distinct IDs but does not reject a duplicate existing ID or enforce one-row-per-ID order. Rule 8 later catches duplicate count drift after Task 4-2 (`:1486-1511`), but Task 4-1 has no such guard. Compare normalized full lists, not only set differences.

The manifest itself is otherwise correct. Parsing produced **58 retained rows, 57 executable rows, EC-22 as the sole withdrawn row, 8 lanes, 9 legs, 22 leg references over 22 rows, 19 required staging replays, and valid environment entries for every lane** (`…-rbac-wave-4.md:620-1142`). The spec prose says 58 while its table enumerates 57 executable entries because EC-22’s number is retained as withdrawn; the plan reconciles that accurately (`…-rbac-wave-4.md:214-249,345`).

The requested runner exercise passed six argument/selection contracts and explicit PostgreSQL and Redis fake-SSH executions. Browser requirements also correctly mandate response-5xx and console capture for every leg, conditional text-matched toast and unchanged-state assertions, serial execution, a second campaign rather than mutation of the onboarding journey, second-company/location/re-run evidence, and a staging replay checklist (`…-rbac-wave-4.md:21,88-121,150-151,251-269,1654-1712,2742-2951,2978-3021`; onboarding precedent at `docs/qa/ONBOARDING-CAMPAIGN.md:1-58`; convention at `docs/conventions/09-SECOND-OF-EVERYTHING.md:28-50`).

Per-wave verdict: **CHANGES-REQUIRED**.

## Cross-plan consistency

- Producer pins are correct in all three headers: wave 0b rev 6.3 `5257eb0f1`, wave 1 rev 6.4 `70384d7e0` (`…-rbac-wave-2.md:15`, `…-rbac-wave-3.md:15`, `…-rbac-wave-4.md:15`). No producer change is owed.
- Wave 2 consumes wave-1 names correctly. Wave 3 alone needs the downstream namespace and POS filename corrections identified above.
- Phase allocations are collision-free: 0a=`0.1`, 0b=`0.2`, wave 1=`1.x`, wave 2a=`0.4`, wave 2b=`0.5`, wave 3=`0.6`, wave 4=`0.7`. Numeric `0.4.10` and `0.7.6…0.7.31` conform to the repository’s three-integer `0.x` convention (`…-rbac-wave-2.md:1116-1117`; `…-rbac-wave-4.md:80,2732-2735`).
- The request says “17 Q-w points,” but the current plans contain **18**: seven Q-w2, six Q-w3 and five Q-w4. The eighteenth is ruled Q-w2-7. All are consistent with spec rev 9.2 or are explicit engineering choices; none requires new owner escalation.
- The service-account token permission is consistently `service-accounts.tokens.create` in the spec, programme plan and operative wave tasks. The route name `service-accounts.tokens.issue` is intentionally a different identifier (`…-rbac-wave-3.md:3766-3770`).
- Wave 3 correctly depends on wave 2 deployment and the token-issuance entry condition; wave 4 correctly depends on waves 0a–3 and consumes their tests as evidence homes (`…-rbac-wave-3.md:50-55`; `…-rbac-wave-4.md:25,526-570`).

## Citation audit

Mechanical qualified-reference counts were Wave 2 **94**, Wave 3 **100**, Wave 4 **18**. All referenced files and bounded line ranges resolved at their stated ref, including lane-qualified glossary citations and the absolute Stancl `Run.php` citation.

Wrong/stale operative references:

| Plan location | Problem | Correct source |
|---|---|---|
| `docs/superpowers/plans/2026-09-10-rbac-wave-3.md:3176` | Wrong `PermissionRegistry` namespace | `70384d7e0:docs/superpowers/plans/2026-09-10-rbac-wave-1.md:173,859-863` |
| `…-rbac-wave-3.md:1580` | Wrong-case `PosPermissionManifest.php` | `70384d7e0:…-rbac-wave-1.md:2929,3510` |
| `…-rbac-wave-3.md:1581` | Wrong-case `PosPermission.php` | `70384d7e0:…-rbac-wave-1.md:3636,4446` |

Current code anchors independently confirmed:

- `AuthController` lookup/Hash boundary: `dev:apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php:253-258`.
- POS PIN population and Hash boundary: `dev:apps/api/app/Modules/POS/Presentation/Controllers/PosAuthController.php:46-56,87-90`.
- Sole `MembershipRole` authorization read: `dev:apps/api/app/Modules/Identity/Presentation/Controllers/UserController.php:928-940`.
- Company header/access behavior: `dev:apps/api/app/Http/Middleware/CompanyContextMiddleware.php:107,123-141`.
- Active membership default: `dev:apps/api/database/migrations/tenant/2025_11_30_106000_create_user_company_memberships_table.php:47`.

The old `HEAD 72498e83f / dev 630afa86f` lines in each plan are dated provenance statements, not current-tip claims, and explicitly require Phase 0 re-pinning (`…-rbac-wave-2.md:52`, `…-rbac-wave-3.md:46`, `…-rbac-wave-4.md:43`). They are not citation defects.

## Rejected false positives

- Wave 0b and wave 1 remain DISPATCH-READY at their final producer revisions. This gate does not re-report their closed findings and requests no successor revision.
- Wave 2 does not need a producer-side POS permission rename; it already uses `POSPermission`/`POSPermissionManifest` and the A-2 scaffold contract (`…-rbac-wave-2.md:137-147`).
- The wave-3 CSV does not contain the alleged unescaped-comma corruption. Standards-compliant parsing gives the plan’s 176/130/46 numbers.
- POS is correctly last, `fetchDiscountPermissions` is already production-wired, and ladder removal is correctly sequenced after its regression proof (`…-rbac-wave-3.md:203,331-354,878-880`).
- `MembershipRole` really has one authorization read, not zero; the plan correctly records it as the sole exception.
- Wave 4’s 58/57 discrepancy is not an omitted scenario: it is 57 enumerated executable rows plus retained withdrawn EC-22.
- The rev-7 environment composer, US separator, helper-local `IFS`, replay selector, EC-16a ordering and tenant correlation all reproduce successfully. The newly found trap bug is independent of those closures.

## Preserve

The following accepted bindings must not move while correcting the four blockers:

- Direction B; one users table with human/service kinds; owner grants intersected with token scope.
- Human-only last-admin floor unless OQ-1 rules otherwise.
- Shared wave-0b `PermissionWriteLock` key and deterministic `roles.name` then `roles.id` order.
- `admin = PermissionRegistry::activeKeys()`.
- Subject versus target effective-permission resolution and intentionally unnarrowed PIN-holder authority payload.
- Global `EnforceTokenScope` attachment with strict non-tenant subject no-op.
- Exact system-template deltas; no automatic overwrite of customised roles.
- Roles/permissions read shaping under `roles.view`; self-or-`roles.view` effective-permission reads.
- Atomic denial dedup and immutable stable domain-event names.
- Additive tenant migrations and destructive audited rollback for service principals.
- Generated TypeScript permission union, deletion of role-name fallback aliases, and three-locale labels.
- Wave 3’s 176-site ratchet, module ordering, POS-last split, FormRequest rule and per-module reviewer gates.
- Wave 4’s 58-retained/57-executable register, withdrawn EC-22, nine deduplicated legs, separate campaign, second-of-everything obligations, ledger-based promotion gate and manifest-driven staging replay.
- The spec-gate r9 “Preserve” list remains binding in full.

## Owner decisions required

Only the existing wave-2b owner inputs remain:

1. **OQ-1:** whether service principals count toward the last-admin floor. The plan implements **No** (`docs/superpowers/plans/2026-09-10-rbac-wave-2.md:303-309`; programme plan `:303`).
2. **OQ-2:** service-token TTL. The plan implements service 365 days, human 90 days and POS one year, all explicit (`…-rbac-wave-2.md:311-315`; programme plan `:304`).

No Q-w point and none of this gate’s findings warrants a new owner question. OQ-3/OQ-4 retain their already-declared programme timing and are not newly escalated here.

## Dispatch assessment

| Wave | Result | Required correction before dispatch |
|---|---|---|
| Wave 2 | **DISPATCH-READY**, subject to declared entry conditions/OQ-1/OQ-2 | None |
| Wave 3 | **CHANGES-REQUIRED** | Correct `PermissionRegistry` namespace and both POS artifact filenames; rerun matrix extraction/runtime resolution and exact-add audit |
| Wave 4 | **CHANGES-REQUIRED** | Make the evidence generator part of an executable commit with its prerequisites and generated rows; make `INT`/`TERM` cleanup terminate; strengthen `--check` duplicate handling |

Overall: **2 Wave 3 blockers, 2 Wave 4 blockers, 1 Wave 4 minor; Wave 2 clean.**

VERDICT: CHANGES-REQUIRED
# `api.module-gating` cluster — triage (UPDATED 2026-05-08, Option 2 expanded scope)

Audit date: 2026-05-08
Reporter: Claude Opus 4.7 (cluster owner — `api.module-gating`)
Tip: `9035c20d` (super-admin pair locked)
Status: **TRIAGE COMPLETE — STOP CONDITION HIT, awaiting orchestrator spot-check on §A.3 classification before Step 2**

## Why this triage exists

Master plan §10 (narrowed) named ProgressionService + 3 controller callers
as the cluster scope. Hostile-grep at triage Step 1 surfaced 12 sibling
callsites in Tenant + Identity controllers with the SAME raw-header
anti-pattern, all behind the SAME `auth:sanctum` + `CompanyContextMiddleware`
stack.

Orchestrator decision (2026-05-08): expand scope to all 15 callsites
across 6 controllers (Progression × 3 + Tenant × 2 + Identity × 1) with
a per-callsite a/b/c/d classification verification before fix application.
Master plan §10 narrowing was scope-myopic (Codex S4 audited only the
Progression module); expanding closes the same bug shape across modules
that S4 didn't audit.

Plus a Step-3 audit per master plan §10: verify Growth Advisor response
`company_id` matches the request's `companyId` if the response shape
carries one.

## A. Hostile-grep results — full surface map

Command (per kickoff, narrowed regex):

```
grep -rn "request->header.*X-Company-Id\|->header('X-Company-Id" \
  apps/api/app --include='*.php' | grep -v Test | grep -v Middleware
```

### A.1 Per-callsite classification table — all 15 callsites

Categories (per kickoff):
- **(a) SAME-PATTERN**: raw header read where the fix is constructor-injected
  `CompanyContext::requireCompanyId()`. Within (a), distinguishing (a-1) and
  (a-2) for transparency:
  - **(a-1)** primary scoping: `companyId` controls the data fetch (e.g.,
    `getModules($companyId)`). Latent risk if `CompanyContextMiddleware`
    is ever re-ordered or bypassed.
  - **(a-2)** audit metadata only: `companyId` only flows into a
    `logAuditEvent` helper. Actual data scoping is `auth()->user()->tenant_id`
    -based (already self-scoping). Risk shape is *audit log mis-attribution*,
    not data leak. Fix shape is identical to (a-1).
- **(b) SELF-SCOPE**: header-read is wrong, fix uses `auth()->user()->tenant_id`
  directly (not `requireCompanyId()`).
- **(c) LEGITIMATE CROSS-TENANT**: super-admin impersonation, tenant
  provisioning before context exists. Fix is `#[CrossTenantRoute]` attribute.
- **(d) ALREADY HAS `#[CrossTenantRoute]`**: already classified, no-op.

Final classification (verified by reading every callsite + route + helper):

| # | File | Line | Method | Category | Reason |
|---|---|---|---|---|---|
| 1 | `Progression/Presentation/Controllers/ModuleReadinessController.php` | 20 | `index` | **(a-1)** | `$companyId` flows to `progressionService->getModules($companyId)` → outbound HTTP call to Growth Advisor `/companies/{companyId}/modules`. Direct data-fetch scoping. |
| 2 | same file | 38 | `activate` | **(a-1)** | flows to `activateModule($companyId, $moduleId)` → outbound POST. Direct data scoping. |
| 3 | `Progression/Presentation/Controllers/RecommendationController.php` | 20 | `index` | **(a-1)** | flows to `getRecommendations($companyId)`. |
| 4 | same file | 36 | `accept` | **(a-1)** | flows to `acceptRecommendation($companyId, $id)`. |
| 5 | same file | 56 | `dismiss` | **(a-1)** | flows to `dismissRecommendation($companyId, $id)`. |
| 6 | `Progression/Presentation/Controllers/CompanyProgressionController.php` | 20 | `show` | **(a-1)** | flows to `getProfile($companyId)`. |
| 7 | same file | 45+48 | `register` | **(a-1)** | flows to `registerCompany(['company_id' => $companyId, 'tenant_id' => $tenantId])`. Both X-Company-Id (line 45) AND X-Tenant-Id (line 48) read raw — both swap to `requireCompanyId()` / `requireTenantId()`. |
| 8 | same file | 69 | `milestones` | **(a-1)** | flows to `getMilestones($companyId)`. |
| 9 | `Tenant/Presentation/Controllers/OnboardingController.php` | 20 | `status` | **(a-1)** | flows to `checklistService->getStatus($companyId)`. Has explicit fail-loud `if ($companyId === null)` guard returning 400 — becomes dead code post-fix (CompanyContextMiddleware already 403s on missing company; `requireCompanyId()` throws redundantly). |
| 10 | `Tenant/Presentation/Controllers/CompanySettingsController.php` | 112 | `update` (audit) | **(a-2)** | flows ONLY to `logAuditEvent(companyId: …)`. Actual settings update operates on `Tenant::find($user->tenant_id)` — already self-scoping by auth user's tenant_id. Header read is for audit metadata only. |
| 11 | same file | 159 | `uploadLogo` (audit) | **(a-2)** | same shape — `logAuditEvent` only. Tenant lookup uses `$user->tenant_id`. |
| 12 | same file | 220 | `deleteLogo` (audit) | **(a-2)** | same shape — `logAuditEvent` only. |
| 13 | `Identity/Presentation/Controllers/UserController.php` | 177 | `store` (audit) | **(a-2)** | flows ONLY to `logAuditEvent`. User creation scopes to `tenant_id: $currentUser->tenant_id`. |
| 14 | same file | 263 | `update` (audit) | **(a-2)** | logAuditEvent only. User scoping `where('tenant_id', $currentUser->tenant_id)->where('id', $id)`. |
| 15 | same file | 330 | `destroy` (audit) | **(a-2)** | logAuditEvent only. Same self-scoping as #14. |
| 16 | same file | 392 | `activate` (audit) | **(a-2)** | logAuditEvent only. Same self-scoping. |
| 17 | same file | 468 | `deactivate` (audit) | **(a-2)** | logAuditEvent only. Same self-scoping. |
| 18 | same file | 526 | `setPosPin` clear (audit) | **(a-2)** | logAuditEvent only. Same self-scoping. |
| 19 | same file | 559 | `setPosPin` set (audit) | **(a-2)** | logAuditEvent only. Same self-scoping. |
| 20 | same file | 620 | `resetPassword` (audit) | **(a-2)** | logAuditEvent only. Same self-scoping. |

### A.2 Classification summary

| Category | Count | Files | Severity / invariant |
|---|---|---|---|
| **(a-1)** primary scoping | 9 | 4 controllers (Progression × 3 + Tenant/Onboarding) | **HIGH — data-scoping invariant.** `companyId` controls which records are read/written. Raw-header trust = direct cross-tenant data leak. |
| **(a-2)** audit metadata only | 11 | 2 controllers (Tenant/CompanySettings + Identity/UserController) | **HIGH — audit-attribution invariant.** `companyId` is recorded in `AuditEvent` for forensic reconstruction. Raw-header trust = audit-trail evidence-tampering risk. For AutoERP's NF525 + AdminAuditLog + two-tier hash chain compliance posture, this is independently load-bearing (NOT "less severe than (a-1)" — different invariant, equally load-bearing for compliance contexts). A user performing role-grant on their own tenant but tagging the audit event with a foreign companyId is real evidence-tampering, not metadata noise. |
| **(b)** SELF-SCOPE | 0 | — | None found. The "self-scope" pattern would be a controller method that operates on the auth user's OWN resources via `$user->id` etc. The only such method in scope (`UserController::companies` at line 633) does NOT read X-Company-Id (uses `$user->id` for membership lookup); it doesn't appear in the grep. |
| **(c)** LEGITIMATE CROSS-TENANT | 0 | — | None found. No method in scope is intentionally cross-tenant (super-admin impersonation, tenant provisioning, etc.). |
| **(d)** ALREADY HAS `#[CrossTenantRoute]` | 0 | — | None of the 20 callsites are already annotated. (The Identity module HAS `#[CrossTenantRoute]` annotations on `RoleController` and `AuthController` — verified at apps/api/app/Modules/Identity/Presentation/Controllers/RoleController.php:33,60,85,125,178,221,245,274,303 and AuthController.php:265,347,411,435 — but those are different controllers/methods, not in our 20-callsite surface.) |
| **TOTAL** | **20** | **6 controllers** | All (a). Fix shape uniform. |

(The grep surfaces 20 line-level matches because `register` reads both
X-Company-Id AND X-Tenant-Id on adjacent lines (45+48); the table folds
those into 1 method-level fix at row #7 but the line-by-line callsite
count is 20.)

### A.3 STRUCTURAL PROTECTOR — out-of-cluster (CompanyContextMiddleware itself)

| # | File | Line | Note |
|---|---|---|---|
| — | `apps/api/app/Http/Middleware/CompanyContextMiddleware.php` | 105 | `$headerCompanyId = $request->header('X-Company-Id');` — **THIS is the validated read**. The middleware itself MUST read the raw header (it has no other source); it then verifies user membership via `$companyContext->userHasAccessToCompany($user, $companyId)` and either rejects with 403 or pins the validated id into `CompanyContext::setCompanyId()`. No fix here — touching this would defeat the validator. |

### A.4 Precedent template — `Modules/Compliance/.../AuditController.php`

The `api.compliance` cluster already addressed this exact shape:

> Tenant isolation (Section 8 / api.compliance round 2): `company_id` is
> resolved exclusively from `CompanyContext`. The earlier `getCompanyId()`
> helper read `X-Company-Id` directly from the request header without
> verifying the user's `UserCompanyMembership` for that company, fell
> through to a query-string `company_id`, and finally to
> `$user->companyMemberships()->first()` (any membership). That made
> cross-tenant audit-event retrieval possible by sending a foreign
> X-Company-Id. CompanyContextMiddleware (registered in the global `api`
> group) verifies membership on every request before populating the
> context, so requireCompanyId() is the safe single source of truth.

Compliance:
- Constructor-injects `CompanyContext`
- Reads via `$this->companyContext->requireCompanyId()`
- Adds class-level docblock explaining structural protection + why the
  pin is required

This is the canonical template. All 20 callsites apply this fix shape.

## B. Per-controller fix table

### B.1 ModuleReadinessController (Progression, 2 callsites — a-1)

```php
// AFTER
final class ModuleReadinessController extends Controller
{
    public function __construct(
        private readonly ProgressionService $progressionService,
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        $modules = $this->progressionService->getModules($companyId);
        // …
    }

    public function activate(Request $request, string $moduleId): JsonResponse
    {
        $companyId = $this->companyContext->requireCompanyId();
        // …
    }
}
```

Plus class-level docblock mirroring `AuditController` template.

### B.2 RecommendationController (Progression, 3 callsites — a-1)

Same shape: constructor-inject `CompanyContext`; replace 3 reads in
`index` / `accept` / `dismiss` with `requireCompanyId()`.

### B.3 CompanyProgressionController (Progression, 4 callsites — a-1)

Same shape PLUS a `requireTenantId()` swap on line 48 (`register` reads
both `X-Company-Id` AND `X-Tenant-Id`):

```php
// AFTER — register()
$profile = $this->progressionService->registerCompany([
    'company_id' => $this->companyContext->requireCompanyId(),
    'tenant_id' => $this->companyContext->requireTenantId(),
]);
```

### B.4 OnboardingController (Tenant, 1 callsite — a-1)

```php
// BEFORE
public function status(Request $request): JsonResponse
{
    $companyId = $request->header('X-Company-Id');

    if ($companyId === null) {
        return response()->json([
            'error' => [
                'code' => 'MISSING_COMPANY_CONTEXT',
                'message' => 'X-Company-Id header is required.',
            ],
        ], Response::HTTP_BAD_REQUEST);
    }

    return response()->json([
        'data' => $this->checklistService->getStatus($companyId),
    ]);
}

// AFTER
public function __construct(
    private readonly OnboardingChecklistService $checklistService,
    private readonly CompanyContext $companyContext,
) {}

public function status(Request $request): JsonResponse
{
    $companyId = $this->companyContext->requireCompanyId();

    return response()->json([
        'data' => $this->checklistService->getStatus($companyId),
    ]);
}
```

The explicit `if ($companyId === null)` 400 guard becomes dead code:
`CompanyContextMiddleware` returns 403 NO_COMPANY_ACCESS upstream, and
`requireCompanyId()` would throw on a missing context. The double-guard
goes away.

### B.5 CompanySettingsController (Tenant, 3 callsites — a-2)

Constructor-inject `CompanyContext`. Replace `companyId: $request->header('X-Company-Id')`
with `companyId: $this->companyContext->requireCompanyId()` at lines 112,
159, 220.

The `logAuditEvent` private helper signature stays as `?string $companyId`
(touching the helper signature would expand the diff unnecessarily). The
`if ($companyId === null) return;` skip path inside the helper becomes
unreachable for these call sites post-fix — leave it as defense-in-depth
(if any future caller passes null, the helper still no-ops safely).

### B.6 UserController (Identity, 8 callsites — a-2)

Constructor-inject `CompanyContext`. Replace 8 callsites at lines 177,
263, 330, 392, 468, 526, 559, 620 with `companyId: $this->companyContext->requireCompanyId()`.

Same dead-code observation as §B.5: `logAuditEvent`'s `if ($companyId === null) return;`
guard is defense-in-depth post-fix.

### B.7 ProgressionService — non-trivial decisions

ProgressionService takes `companyId` as a string argument and forwards
to `GrowthAdvisorClientInterface`. **It does NOT read the header itself**
(verified by full file read — only `$this->client->…` calls
parametrized by the caller-supplied `$companyId`). The fix narrows to
the controllers; ProgressionService stays unchanged at the interface
level.

The response-id verification (Section C below) IS introduced inside
ProgressionService — the right layer because the service has the
`$companyId` arg and the response side-by-side.

## C. Response-id verification audit (Growth Advisor)

Per master plan §10 step 3: "Verify Growth Advisor response company id
matches if the response contains one."

**Read end-to-end**: `GrowthAdvisorHttpClient` (`apps/api/app/Modules/Progression/Infrastructure/Http/GrowthAdvisorHttpClient.php`)
exposes 8 methods on `GrowthAdvisorClientInterface`:

| # | Client method | URL shape | Response DTO | Has `company_id` echo? | Action |
|---|---|---|---|---|---|
| 1 | `getCompanyProfile($companyId)` | `GET /api/v1/companies/{companyId}` | `CompanyProfileData` (`id` field IS the company id, `tenant_id` is the tenant id) | **YES** | ADD CHECK: `response['id'] === $companyId` AND `response['tenant_id'] === $tenantId`; mismatch → throw `RuntimeException` (fail-loud) |
| 2 | `registerCompany($data)` | `POST /api/v1/companies` | `CompanyProfileData` | **YES** | ADD CHECK: `response['id'] === $data['company_id']` AND `response['tenant_id'] === $data['tenant_id']`; throw on mismatch |
| 3 | `getMilestones($companyId)` | `GET /api/v1/companies/{companyId}/milestones` | `list<MilestoneData>` (id, name, description, status, progress_percent, stage) | **NO** | DOCUMENT: company-bound by URL path; mis-routing on Growth Advisor side cannot be detected here. |
| 4 | `getModules($companyId)` | `GET /api/v1/companies/{companyId}/modules` | `list<ModuleReadinessData>` (id, name, status, …) | **NO** | DOCUMENT same |
| 5 | `activateModule($companyId, $moduleId)` | `POST /api/v1/companies/{companyId}/modules/{moduleId}/activate` | `ModuleReadinessData` | **NO** | DOCUMENT same |
| 6 | `getRecommendations($companyId)` | `GET /api/v1/companies/{companyId}/recommendations` | `list<RecommendationData>` | **NO** | DOCUMENT same |
| 7 | `acceptRecommendation($companyId, $id)` | `POST /api/v1/companies/{companyId}/recommendations/{id}/accept` | `RecommendationData` | **NO** | DOCUMENT same |
| 8 | `dismissRecommendation($companyId, $id)` | same shape | `RecommendationData` | **NO** | DOCUMENT same |

**Two response-id checks are addable (#1, #2). Six are not.**

### C.1 Where the check goes

ProgressionService is the right layer:
- It already knows `$companyId` (the request input).
- It receives the raw response array (`?array $response = $this->client->getCompanyProfile($companyId)`) before passing to `CompanyProfileData::fromApiResponse(...)`.
- The check sits between client return and DTO construction.

```php
// ProgressionService::getProfile — AFTER
public function getProfile(string $companyId): ?CompanyProfileData
{
    $response = $this->client->getCompanyProfile($companyId);
    if ($response === null) {
        return null;
    }

    $this->assertResponseCompanyMatches($response, $companyId, 'getCompanyProfile');

    return CompanyProfileData::fromApiResponse($response);
}

private function assertResponseCompanyMatches(array $response, string $expectedCompanyId, string $context): void
{
    $responseCompanyId = $response['id'] ?? null;
    if ($responseCompanyId !== null && (string) $responseCompanyId !== $expectedCompanyId) {
        throw new \RuntimeException(sprintf(
            'Growth Advisor response company id mismatch in %s: expected %s, got %s. Possible cross-tenant data leak.',
            $context,
            $expectedCompanyId,
            (string) $responseCompanyId,
        ));
    }
}
```

The `if ($responseCompanyId !== null && …)` shape distinguishes:
- "Response has no `id` field" (legitimate — applies to Milestones, Modules, Recommendations response shapes that don't carry one) → no throw
- "Response has `id` field that mismatches" (real concern) → throw

A parallel `assertResponseTenantMatches` for the tenant_id check adds
defense-in-depth on `getProfile` and `registerCompany` (CompanyProfileData
carries `tenant_id`). Tests must drive both.

### C.2 Why fail-loud (throw) vs silent log-and-return

Master plan §10 step 3 expectation + architectural-cluster lesson #3
(known gap vs by-design distinction): a response-id mismatch is the
canonical "cross-tenant data leak" shape. Silent log-and-return
preserves the leak (caller still gets the wrong tenant's data, just
with a log line that may or may not be triaged). Fail-loud throw bubbles
up to the controller's error handler and surfaces as a 500 — a
legitimate "this should never happen" signal.

Codex round-1 will probably ask: "what about the catch block in
GrowthAdvisorHttpClient::get/post that swallows ConnectionException /
RequestException?" Answer: that catch is in the CLIENT layer (handles
network errors). The throw lives in the SERVICE layer (handles contract
violations). They don't intersect — the client returns the response
array OR null; the service receives the array and asserts. No silent
swallow.

### C.3 Caveat — the unprotected six

The six response shapes that don't carry `company_id` (milestones,
modules, recommendations × 3) cannot be verified at this layer. The
tenant binding rests on:
1. Growth Advisor's URL routing — `/api/v1/companies/{companyId}/...`
2. Growth Advisor's server-side scoping (no audit possible from this
   side of the wire)

This is the same risk shape as Finding K (`api.growth-advisor-tenant-binding`)
in cross-cluster observations — closing it requires Growth-Advisor-side
work. Documenting the gap here so future-cluster owners can extend.

## D. Confirmation: out-of-scope items remain untouched

Per kickoff scope-boundary:

1. **`updateExtras` (controllers super-admin route)** — already
   `EnsureSuperAdmin` middleware-gated; not exploitable by tenant users.
   Verified by reading the routes.php — Progression's `routes.php` has
   no `updateExtras` endpoint at all (it's not in the Progression
   module). NOT TOUCHED.

2. **`CompanyConfigService` cache key** — already tenant-scoped at
   `tenant_config:{$tenant->id}`. Not in this cluster's grep surface
   (cache is a different abstraction). NOT TOUCHED.

3. **`GrowthAdvisorHttpClient` outbound headers / URL-path auth** —
   covered by Finding K (`api.growth-advisor-tenant-binding`) in
   2026-05-07-scheduled-jobs-cross-cluster-observations.md:738-790. The
   request-side header injection is FK's concern. **Response-side
   verification (Section C above) is THIS cluster's concern** —
   different fix shape, different layer (service vs client), no
   conflict. NOT TOUCHED on the request side.

## E. Manual rows plan (for Step 2, post-orchestrator-approval)

Anticipated cluster-stable-key seeds under cluster_id `api.module-gating`:

```yaml
# Likely 3-4 manual rows depending on classifier shape preference
- stable_key: manual:api.module-gating:progression-controllers-companycontext-injection
  scope: 9 callsites in 3 Progression controllers (rows 1-8 in §A.1)
  invariant: controllers resolve companyId/tenantId from CompanyContext, not raw header

- stable_key: manual:api.module-gating:tenant-controllers-companycontext-injection
  scope: 4 callsites in Tenant/Onboarding (1) + Tenant/CompanySettings (3) — rows 9-12
  invariant: same

- stable_key: manual:api.module-gating:identity-user-controller-companycontext-audit-attribution
  scope: 8 callsites in Identity/UserController — rows 13-20
  invariant: same (audit log company attribution flows from validated CompanyContext)

- stable_key: manual:api.module-gating:growth-advisor-response-id-verification
  scope: ProgressionService — assertResponseCompanyMatches/assertResponseTenantMatches helpers
    applied to getProfile + registerCompany flows
  invariant: Growth Advisor response company_id (when echoed) matches the request's companyId
```

Whether to bundle as 3 rows (one per controller-cluster) or 4 rows
(adding response-id as a separate row) is the orchestrator's call. The
4-row split makes the Codex review prompt more granular and the
classifier surface clearer; the 3-row bundle keeps the manual surface
tighter.

## F.0 Layering with the universal arch test (api.super-admin-context)

The universal arch test from the `api.super-admin-context` cluster
(`tests/Architecture/ControllerTenantContextTest.php::test_every_controller_method_is_classified`)
confirms every controller method is classified — either by
`#[CrossTenantRoute]` OR by structural `CompanyContext` usage in the method
body. That static check correctly admits the 8 `UserController` methods
(and the 3 `CompanySettingsController` audit-only methods, and all
Progression methods) because they DO use `CompanyContext` somewhere
(in data-scoping paths or via the audit helper).

But the static heuristic CANNOT enforce "every header-derived value is
replaced with a context-derived value" — that's the data-flow analysis
wall PhpParser hits (api.broadcast-channels round-3 lesson). A method
that uses `CompanyContext` for data scoping AND `$request->header('X-Company-Id')`
for audit attribution passes the static check vacuously: the
`CompanyContext` reference is enough for classification, but the latent
audit-attribution gap remains.

**This cluster (`api.module-gating`) closes the deeper invariant the
static check can't reach**: every `companyId`-bearing parameter to a
downstream call (service, helper, audit log) must derive from
`CompanyContext`, not from raw `$request->header('X-Company-Id')`. The
two clusters layer:

- **Static classification** (api.super-admin-context): "this controller
  method is classified as either #[CrossTenantRoute] or
  CompanyContext-aware." Reflection-walk over all controller methods.
- **Behavioral source-pinning** (api.module-gating): "every
  `companyId`-bearing argument flowing OUT of these controllers derives
  from `CompanyContext::requireCompanyId()`, not from raw header
  reads." Per-callsite mechanical replacement + behavioral test.

Codex round-1 will likely probe this layering. The PR body must
explicitly call out that:
- `UserController::store/update/...` PASS the static arch check today
  (they reference `CompanyContext` somewhere — the audit-helper call
  at the dispatch boundary).
- This cluster closes the residual gap where `companyId` flowing INTO
  those audit-helper calls comes from the raw header instead of the
  validated context.

File:line citations to ground the layering claim in Codex review:
- Universal arch test: `apps/api/tests/Architecture/ControllerTenantContextTest.php`
- Heuristic regex (`CompanyContext|tenantId|tenant_id|...`): in the same
  test file
- This cluster's anchor: each of the 6 controller fixes uses
  `$this->companyContext->requireCompanyId()` — direct `CompanyContext`
  invocation that satisfies the static check AND closes the source-pin
  invariant.

## F. Anticipated Codex round-1 surfaces (forward-looking)

Per kickoff "Codex will likely scrutinize" + architectural-cluster
lessons:

1. **Test honesty (kickoff-flagged)** — naive controller test "drive
   index() with malicious header, assert ProgressionService received
   correct id" passes vacuously today because middleware validates.
   Tests must specifically exercise either:
   - Middleware-bypassed flow: register a mock `CompanyContext` that
     returns id `A`; controller receives request with header `X-Company-Id:
     B` (malicious); assert ProgressionService got `A` (the validated
     context, not the malicious header). This proves the controller pins
     to context, not header.
   - OR an explicit unit test that drives the controller with a
     pre-set `CompanyContext` and asserts argument identity to the
     service layer.

2. **Audit-log skip-on-null dead-code** — for category (a-2) callsites,
   `logAuditEvent`'s `if ($companyId === null) return;` is unreachable
   post-fix. Codex may flag as dead code OR as legitimate defense-in-
   depth. Triage decision: leave it as defense-in-depth + add a
   comment naming the now-redundant guard. Avoids touching helper
   signature (lower diff surface for review).

3. **Hostile-grep coverage at review time** — Codex will re-grep for
   any `X-Company-Id` read OR any "trust raw header" pattern across
   the 6 in-scope controllers and adjacent modules. Triage commits to
   covering all 20 callsites; should be clean.

4. **Response-id check edge cases** — the fail-loud check must NOT
   throw on legitimate "no `id` field" responses (the 6 unprotected
   shapes). The `if ($responseCompanyId !== null && …)` guard handles
   this; explicit test coverage required.

5. **`CompanyContext::requireCompanyId()` throw semantics** — when the
   middleware hasn't run (test or future middleware change),
   `requireCompanyId()` throws (verified at
   `apps/api/app/Modules/Company/Services/CompanyContext.php:45`). The
   throw must not be caught silently in the controllers — none of the
   6 in-scope controllers have try/catch wrappers around the
   `requireCompanyId()` call site (verified by full file reads). Test
   `test_throws_when_companycontext_unbound` will pin this.

6. **Existing test breakage** — any existing test in the 6 modules
   that was passing by reading raw header behavior breaks post-fix. A
   `find apps/api/tests -path '*Progression*' -o -path '*Tenant*' -o -path '*Identity*Users*'`
   sweep will surface them. Each gets updated to the new contract OR
   was vacuous and gets hardened.

## G. Cross-cluster observation to file (in Step 2 commit)

Per orchestrator instruction, append to
`docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md`:

> Title: "Master plan §10 narrowing was scope-myopic — expanded
>         api.module-gating cluster to absorb (2026-05-08)"
>
> Body:
>   - Master plan §10 narrowed module-gating to ProgressionService
>     based on Codex S4 audit of the Progression module.
>   - Hostile-grep at cluster Step 1 surfaced 12 sibling callsites
>     in Tenant/Onboarding (1), Tenant/CompanySettings (3),
>     Identity/UserController (8) with the same raw-header anti-
>     pattern.
>   - Codex S4 audit didn't cover those modules. The narrowing was
>     based on incomplete coverage, not deliberate scope decision.
>   - Orchestrator decision (2026-05-08): expand cluster scope to
>     absorb all 15 callsites with the verification condition
>     (per-callsite classification a/b/c/d).
>   - All 20 line-level callsites classified as (a) SAME-PATTERN
>     (9 × a-1 primary scoping + 11 × a-2 audit metadata only). No
>     (b) self-scope, no (c) legitimate cross-tenant, no (d) already
>     annotated.
>   - Pattern lesson: master plan section narrowings should always
>     be paired with hostile-grep validation BEFORE the cluster's
>     triage commits to scope. The Codex SN audit's coverage limit
>     is the load-bearing assumption; verify by grep before honoring.
>   - Severity: PROCESS-LEVEL (informs future master-plan-narrowing
>     decisions, not a code defect).

This append happens in the Step 2 seed-manual-rows commit, not as a
separate commit (per orchestrator instruction).

## H. References

- Master plan: `docs/superpowers/plans/tenant-isolation-sweep-master-plan.md` §10
- Cross-cluster observations: `docs/superpowers/audits/2026-05-07-scheduled-jobs-cross-cluster-observations.md` (Finding K, 2026-05-08)
- Precedent template: `apps/api/app/Modules/Compliance/Presentation/Controllers/AuditController.php:18-26` (api.compliance docblock + injection)
- CompanyContextMiddleware (validator): `apps/api/app/Http/Middleware/CompanyContextMiddleware.php:33-86`
- CompanyContext API: `apps/api/app/Modules/Company/Services/CompanyContext.php` — `requireCompanyId():45`, `requireTenantId():63`
- Identity `#[CrossTenantRoute]` precedent: `RoleController.php:33,60,85,125,178,221,245,274,303` and `AuthController.php:265,347,411,435`
- Sanity baseline (this triage's session): 1762 events / 347 callsites / 0 problems

## I. Status flag

**STOP. Awaiting orchestrator spot-check on §A.1 classification table
before proceeding to Step 2.**

No code changes made. No manual rows added. No cluster claimed. Triage
doc written + sanity baseline confirmed; nothing else.

Spot-check focus areas for orchestrator:
1. Does the (a-1) vs (a-2) distinction match expected severity grading
   (it's transparency-only — fix shape is uniform)?
2. Are categories (b)/(c)/(d) correctly absent (no edge cases missed)?
3. Manual-rows split — 3 rows or 4 rows (§E)?
4. Approve cross-cluster observation append wording (§G)?

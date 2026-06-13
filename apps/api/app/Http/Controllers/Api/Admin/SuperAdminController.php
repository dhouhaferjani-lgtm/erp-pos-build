<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Vertical;
use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Modules\Billing\Application\Services\PlanEnforcementService;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\AdminAuditService;
use App\Services\TenantFleetStatsService;
use App\Services\VerticalConfigService;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SuperAdminController extends Controller
{
    public function __construct(
        private readonly AdminAuditService $auditService,
        private readonly PlanEnforcementService $planEnforcementService,
        private readonly VerticalConfigService $verticalConfigService,
        private readonly TenantFleetStatsService $fleetStatsService
    ) {}

    #[CrossTenantRoute(reason: 'Super-admin dashboard aggregates fleet-wide tenant, user, and subscription counts for the platform-operations panel; mounted under the auth:sanctum-admin + super_admin (EnsureSuperAdmin) middleware group at routes/api.php:54.')]
    public function dashboard(): JsonResponse
    {
        $fleetTotals = $this->fleetStatsService->getUserAndCompanyTotals();

        $stats = [
            'total_tenants' => Tenant::count(),
            'active_tenants' => Tenant::where('status', 'active')->count(),
            'trial_tenants' => DB::table('tenant_subscriptions')
                ->where('status', 'trial')
                ->count(),
            'expired_tenants' => DB::table('tenant_subscriptions')
                ->where('status', 'expired')
                ->count(),
            'total_users' => $fleetTotals['total_users'],
            'total_companies' => $fleetTotals['total_companies'],
        ];

        return response()->json(['data' => $stats]);
    }

    #[CrossTenantRoute(reason: 'Super-admin tenant directory: lists every tenant in the fleet with optional name/slug/tax_id search for support and billing operations; mounted under auth:sanctum-admin + super_admin (EnsureSuperAdmin).')]
    public function tenants(Request $request): JsonResponse
    {
        $query = Tenant::with('subscription.plan');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('slug', 'LIKE', "%{$search}%")
                    ->orWhere('tax_id', 'LIKE', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $tenants = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json(['data' => $tenants]);
    }

    #[CrossTenantRoute(reason: 'Super-admin tenant detail view: reads any tenant by id and renders fleet-context stats (users_count, companies_count, locations_count) joined across the tenant\'s companies/locations for support tickets and renewal review; super_admin middleware gated.')]
    public function showTenant(string $id): JsonResponse
    {
        $tenant = Tenant::with(['subscription.plan'])->findOrFail($id);

        // users/companies/locations live in the PER-TENANT database (T6
        // Phase 0b) — counts must execute inside $tenant->run(). tenant_id
        // scoping kept: harmless in prod, required in the shared-schema test
        // env. A broken tenant DB degrades to zeros instead of 500ing the
        // support view.
        try {
            /** @var array{users_count: int, companies_count: int, locations_count: int} $stats */
            $stats = $tenant->run(static function () use ($id): array {
                $companyIds = DB::table('companies')->where('tenant_id', $id)->pluck('id');

                return [
                    'users_count' => DB::table('users')->where('tenant_id', $id)->count(),
                    'companies_count' => $companyIds->count(),
                    'locations_count' => DB::table('locations')->whereIn('company_id', $companyIds)->count(),
                ];
            });
            $statsAvailable = true;
        } catch (Throwable $e) {
            Log::warning('Admin tenant detail: tenant database unreachable', [
                'tenant_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $stats = ['users_count' => 0, 'companies_count' => 0, 'locations_count' => 0];
            $statsAvailable = false;
        }

        // Get plan limits and usage from PlanEnforcementService.
        // getPlanSummary calls getUsageStats/calculateUserOverage which both
        // run $tenant->run() internally — a broken tenant DB would 500 here
        // even though the stats block above already caught the first run().
        // Wrap independently so a degraded tenant DB degrades the summary too.
        try {
            $planSummary = $this->planEnforcementService->getPlanSummary($tenant);
        } catch (Throwable $e) {
            Log::warning('Admin tenant detail: plan summary unavailable', [
                'tenant_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $planSummary = null;
            $statsAvailable = false;
        }

        /** @var Vertical|null $vertical */
        $vertical = $tenant->vertical;
        $compatibleExtras = $vertical !== null
            ? $this->verticalConfigService->getCompatibleExtras($vertical)
            : [];
        $defaultModules = $vertical !== null
            ? $this->verticalConfigService->getDefaultModules($vertical)
            : [];

        return response()->json([
            'data' => [
                'tenant' => $tenant,
                'stats' => $stats,
                'stats_available' => $statsAvailable,
                'plan_summary' => $planSummary,
                'compatible_extras' => $compatibleExtras,
                'default_modules' => $defaultModules,
                'vertical_label' => $vertical !== null ? $this->verticalConfigService->getLabel($vertical) : null,
            ],
        ]);
    }

    /**
     * Get plan usage and limits for a specific tenant.
     */
    #[CrossTenantRoute(reason: 'Super-admin plan-usage probe: reads any tenant\'s plan limits and current usage via PlanEnforcementService::getPlanSummary to advise upgrades or investigate quota incidents; super_admin middleware gated.')]
    public function getTenantPlanUsage(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        return response()->json([
            'data' => $this->planEnforcementService->getPlanSummary($tenant),
        ]);
    }

    #[CrossTenantRoute(reason: 'Tenant lifecycle: super-admin extends the trial period on any tenant\'s subscription (writes tenant_subscriptions.trial_ends_at + status=trial); logged to AdminAuditLog via AdminAuditService::logTenantAction with super_admin_id, oldValues, newValues, and a notes field naming the days extended.')]
    public function extendTrial(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'days' => 'required|integer|min:1|max:365',
        ]);

        $tenant = Tenant::findOrFail($id);
        $subscription = $tenant->subscription;

        if ($subscription === null) {
            return response()->json(['error' => 'No subscription found'], 404);
        }

        $oldTrialEnd = $subscription->trial_ends_at;
        $newTrialEnd = now()->addDays((int) $request->input('days'));

        $subscription->update([
            'trial_ends_at' => $newTrialEnd,
            'status' => 'trial',
        ]);

        // Type assertion - middleware guarantees this is a SuperAdmin
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        $this->auditService->logTenantAction(
            admin: $admin,
            tenant: $tenant,
            action: 'extend_trial',
            oldValues: ['trial_ends_at' => $oldTrialEnd !== null ? (string) $oldTrialEnd : null],
            newValues: ['trial_ends_at' => $newTrialEnd->toDateTimeString()],
            notes: "Extended trial by {$request->input('days')} days"
        );

        return response()->json([
            'data' => $subscription->fresh(),
            'message' => 'Trial extended successfully',
        ]);
    }

    #[CrossTenantRoute(reason: 'Tenant lifecycle: super-admin changes the subscription plan on any tenant (writes tenant_subscriptions.plan_id); logged to AdminAuditLog via AdminAuditService::logTenantAction with the previous plan_id and the new plan_id for billing-audit reconstruction.')]
    public function changePlan(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'plan_id' => 'required|exists:plans,id',
        ]);

        $tenant = Tenant::findOrFail($id);
        $subscription = $tenant->subscription;

        if ($subscription === null) {
            return response()->json(['error' => 'No subscription found'], 404);
        }

        $oldPlanId = $subscription->plan_id;

        $subscription->update([
            'plan_id' => $request->input('plan_id'),
        ]);

        // Type assertion - middleware guarantees this is a SuperAdmin
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        $this->auditService->logTenantAction(
            admin: $admin,
            tenant: $tenant,
            action: 'change_plan',
            oldValues: ['plan_id' => $oldPlanId],
            newValues: ['plan_id' => $request->input('plan_id')],
            notes: 'Plan changed by admin'
        );

        $freshSubscription = $subscription->fresh();

        return response()->json([
            'data' => $freshSubscription?->load('plan'),
            'message' => 'Plan changed successfully',
        ]);
    }

    #[CrossTenantRoute(reason: 'Tenant lifecycle: super-admin suspends any tenant for billing or abuse reasons (writes tenants.status=suspended); logged to AdminAuditLog with the previous status and the operator-provided reason text.')]
    public function suspendTenant(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $tenant = Tenant::findOrFail($id);
        $oldStatus = $tenant->status;

        $tenant->update(['status' => 'suspended']);

        // Type assertion - middleware guarantees this is a SuperAdmin
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        $this->auditService->logTenantAction(
            admin: $admin,
            tenant: $tenant,
            action: 'suspend_tenant',
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => 'suspended'],
            notes: $request->input('reason')
        );

        return response()->json([
            'data' => $tenant,
            'message' => 'Tenant suspended successfully',
        ]);
    }

    #[CrossTenantRoute(reason: 'Tenant lifecycle: super-admin reactivates any tenant after suspension/expiration (writes tenants.status=active); logged to AdminAuditLog with the previous status for fleet-wide audit chain.')]
    public function activateTenant(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $oldStatus = $tenant->status;

        $tenant->update(['status' => 'active']);

        // Type assertion - middleware guarantees this is a SuperAdmin
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        $this->auditService->logTenantAction(
            admin: $admin,
            tenant: $tenant,
            action: 'activate_tenant',
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => 'active'],
            notes: 'Tenant activated by admin'
        );

        return response()->json([
            'data' => $tenant,
            'message' => 'Tenant activated successfully',
        ]);
    }

    /**
     * Update the enabled_extras (optional modules) for a tenant.
     */
    #[CrossTenantRoute(reason: 'Tenant lifecycle: super-admin updates the optional-modules whitelist (tenants.enabled_extras) on any tenant after vertical-compatibility validation via VerticalConfigService; logged to AdminAuditLog with previous and new extras arrays.')]
    public function updateExtras(Request $request, string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        $request->validate([
            'enabled_extras' => ['present', 'array'],
            'enabled_extras.*' => ['required', 'string'],
        ]);

        /** @var array<int, string> $requestedExtras */
        $requestedExtras = $request->input('enabled_extras');
        /** @var Vertical|null $vertical */
        $vertical = $tenant->vertical;

        if ($vertical === null) {
            return response()->json(['error' => 'Tenant has no vertical configured'], 422);
        }

        $compatibleExtras = $this->verticalConfigService->getCompatibleExtras($vertical);
        $invalidExtras = array_diff($requestedExtras, $compatibleExtras);

        if ($invalidExtras !== []) {
            return response()->json([
                'error' => 'Invalid extras for vertical '.$vertical->value.': '.implode(', ', $invalidExtras),
                'valid_extras' => $compatibleExtras,
            ], 422);
        }

        $previousExtras = $tenant->enabled_extras ?? [];
        $tenant->update(['enabled_extras' => $requestedExtras]);

        /** @var SuperAdmin $admin */
        $admin = $request->user();

        $this->auditService->logTenantAction(
            admin: $admin,
            tenant: $tenant,
            action: 'update_extras',
            oldValues: ['enabled_extras' => $previousExtras],
            newValues: ['enabled_extras' => $requestedExtras],
            notes: 'Enabled extras updated by admin'
        );

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'vertical' => $vertical->value,
                'enabled_extras' => $tenant->enabled_extras,
            ],
        ]);
    }

    #[CrossTenantRoute(reason: 'Super-admin audit-log viewer: reads admin_audit_logs joined to super_admins (actor) and tenants (target) across all tenants and super-admin actors for compliance review and incident investigation; super_admin middleware gated.')]
    public function auditLogs(Request $request): JsonResponse
    {
        $query = DB::table('admin_audit_logs')
            ->join('super_admins', 'admin_audit_logs.super_admin_id', '=', 'super_admins.id')
            ->leftJoin('tenants', 'admin_audit_logs.tenant_id', '=', 'tenants.id')
            ->select([
                'admin_audit_logs.*',
                'super_admins.name as admin_name',
                'super_admins.email as admin_email',
                'tenants.name as tenant_name',
            ]);

        if ($tenantId = $request->input('tenant_id')) {
            $query->where('admin_audit_logs.tenant_id', $tenantId);
        }

        if ($action = $request->input('action')) {
            $query->where('admin_audit_logs.action', $action);
        }

        $logs = $query->orderBy('admin_audit_logs.created_at', 'desc')
            ->paginate(50);

        return response()->json(['data' => $logs]);
    }

    /**
     * List all users with optional search and filter.
     * Fans out over every tenant database (users are per-tenant post-T6).
     */
    #[CrossTenantRoute(reason: 'Super-admin user directory: fans out over every tenant database (users are per-tenant post-T6) applying name/email search, tenant_id, email_verified, and status filters inside each tenant DB; merged + paginated in-memory; super_admin middleware gated.')]
    public function users(Request $request): JsonResponse
    {
        $perPage = 20;
        $page = max(1, (int) $request->input('page', 1));

        $tenantQuery = Tenant::query();
        if ($tenantId = $request->input('tenant_id')) {
            $tenantQuery->where('id', $tenantId);
        }

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = collect();

        foreach ($tenantQuery->cursor() as $tenant) {
            try {
                /** @var array<int, array<string, mixed>> $tenantUsers */
                $tenantUsers = $tenant->run(static function () use ($request, $tenant): array {
                    $query = User::query()->where('tenant_id', $tenant->id);

                    if ($search = $request->input('search')) {
                        $query->where(function ($q) use ($search): void {
                            $q->where('name', 'LIKE', "%{$search}%")
                                ->orWhere('email', 'LIKE', "%{$search}%");
                        });
                    }

                    if ($request->has('email_verified')) {
                        $emailVerified = filter_var($request->input('email_verified'), FILTER_VALIDATE_BOOLEAN);
                        if ($emailVerified) {
                            $query->whereNotNull('email_verified_at');
                        } else {
                            $query->whereNull('email_verified_at');
                        }
                    }

                    if ($status = $request->input('status')) {
                        $query->where('status', $status);
                    }

                    return $query->orderBy('created_at', 'desc')->get()->toArray();
                });
            } catch (Throwable $e) {
                Log::warning('Admin user directory: tenant database unreachable, skipping', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $tenantInfo = ['id' => $tenant->id, 'name' => $tenant->name];
            foreach ($tenantUsers as $userRow) {
                $userRow['tenant'] = $tenantInfo;
                $rows->push($userRow);
            }
        }

        $sorted = $rows->sortByDesc('created_at')->values();
        $paginator = new LengthAwarePaginator(
            $sorted->forPage($page, $perPage)->values(),
            $sorted->count(),
            $perPage,
            $page,
            ['path' => $request->url()]
        );

        return response()->json(['data' => $paginator]);
    }

    /**
     * Show a specific user's details.
     * Requires tenant_id query param; resolves user inside that tenant DB.
     */
    #[CrossTenantRoute(reason: 'Super-admin user detail view: resolves the user INSIDE the addressed tenant database (tenant_id query param required — user rows are per-tenant post-T6) with company memberships; super_admin middleware gated.')]
    public function showUser(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'tenant_id' => 'required|uuid',
        ]);

        /** @var Tenant $tenant */
        $tenant = Tenant::findOrFail((string) $request->input('tenant_id'));

        /** @var array{user: array<string, mixed>, memberships: array<int, mixed>}|null $data */
        $data = $tenant->run(static function () use ($id, $tenant): ?array {
            $user = User::where('tenant_id', $tenant->id)->find($id);
            if ($user === null) {
                return null;
            }

            $memberships = DB::table('user_company_memberships')
                ->join('companies', 'user_company_memberships.company_id', '=', 'companies.id')
                ->where('user_company_memberships.user_id', $id)
                ->select([
                    'user_company_memberships.*',
                    'companies.name as company_name',
                ])
                ->get();

            return ['user' => $user->toArray(), 'memberships' => $memberships->all()];
        });

        if ($data === null) {
            return response()->json(['error' => 'User not found in this tenant'], 404);
        }

        $data['user']['tenant'] = ['id' => $tenant->id, 'name' => $tenant->name];

        return response()->json(['data' => $data]);
    }

    /**
     * Manually verify a user's email address (super admin override).
     * Requires tenant_id in body; resolves + updates inside that tenant DB.
     * Audit log written centrally AFTER run() releases the tenant context.
     */
    #[CrossTenantRoute(reason: 'Super-admin email-verification override: stamps email_verified_at on a user INSIDE the addressed tenant database (tenant_id required post-T6); logged to AdminAuditLog (central) after the tenant context is released.')]
    public function verifyUserEmail(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'tenant_id' => 'required|uuid',
            'notes' => 'nullable|string|max:500',
        ]);

        /** @var Tenant $tenant */
        $tenant = Tenant::findOrFail((string) $request->input('tenant_id'));

        /** @var array{user: array<string, mixed>, already_verified: bool}|null $result */
        $result = $tenant->run(static function () use ($id, $tenant): ?array {
            $user = User::where('tenant_id', $tenant->id)->find($id);
            if ($user === null) {
                return null;
            }
            if ($user->email_verified_at !== null) {
                return ['user' => $user->toArray(), 'already_verified' => true];
            }

            $user->update(['email_verified_at' => now()]);

            return ['user' => $user->fresh()?->toArray() ?? [], 'already_verified' => false];
        });

        if ($result === null) {
            return response()->json(['error' => 'User not found in this tenant'], 404);
        }

        if ($result['already_verified']) {
            return response()->json([
                'error' => [
                    'code' => 'ALREADY_VERIFIED',
                    'message' => 'User email is already verified.',
                ],
            ], 400);
        }

        /** @var SuperAdmin $admin */
        $admin = $request->user();

        // AdminAuditLog is central-pinned — logged after run() releases the
        // tenant context, so the write lands centrally either way.
        $this->auditService->log(
            admin: $admin,
            action: 'verify_user_email',
            tenant: $tenant,
            entityType: 'user',
            entityId: $id,
            oldValues: ['email_verified_at' => null],
            newValues: ['email_verified_at' => now()->toDateTimeString()],
            notes: $request->input('notes') ?? 'Email manually verified by admin'
        );

        return response()->json([
            'data' => $result['user'],
            'message' => 'User email verified successfully.',
        ]);
    }
}

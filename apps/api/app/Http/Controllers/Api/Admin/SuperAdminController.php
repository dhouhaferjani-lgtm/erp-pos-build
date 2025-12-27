<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SuperAdmin;
use App\Modules\Billing\Application\Services\PlanEnforcementService;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminController extends Controller
{
    public function __construct(
        private readonly AdminAuditService $auditService,
        private readonly PlanEnforcementService $planEnforcementService
    ) {}

    public function dashboard(): JsonResponse
    {
        $stats = [
            'total_tenants' => Tenant::count(),
            'active_tenants' => Tenant::where('status', 'active')->count(),
            'trial_tenants' => DB::table('tenant_subscriptions')
                ->where('status', 'trial')
                ->count(),
            'expired_tenants' => DB::table('tenant_subscriptions')
                ->where('status', 'expired')
                ->count(),
            'total_users' => DB::table('users')->count(),
            'total_companies' => DB::table('companies')->count(),
        ];

        return response()->json(['data' => $stats]);
    }

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

    public function showTenant(string $id): JsonResponse
    {
        $tenant = Tenant::with(['subscription.plan'])->findOrFail($id);

        $stats = [
            'users_count' => DB::table('users')->where('tenant_id', $id)->count(),
            'companies_count' => DB::table('companies')->where('tenant_id', $id)->count(),
            'locations_count' => DB::table('locations')->where('tenant_id', $id)->count(),
        ];

        // Get plan limits and usage from PlanEnforcementService
        $planSummary = $this->planEnforcementService->getPlanSummary($tenant);

        return response()->json([
            'data' => [
                'tenant' => $tenant,
                'stats' => $stats,
                'plan_summary' => $planSummary,
            ],
        ]);
    }

    /**
     * Get plan usage and limits for a specific tenant.
     */
    public function getTenantPlanUsage(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);

        return response()->json([
            'data' => $this->planEnforcementService->getPlanSummary($tenant),
        ]);
    }

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
     */
    public function users(Request $request): JsonResponse
    {
        $query = User::with(['tenant']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%");
            });
        }

        if ($tenantId = $request->input('tenant_id')) {
            $query->where('tenant_id', $tenantId);
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

        $users = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json(['data' => $users]);
    }

    /**
     * Show a specific user's details.
     */
    public function showUser(string $id): JsonResponse
    {
        $user = User::with(['tenant'])->findOrFail($id);

        $memberships = DB::table('user_company_memberships')
            ->join('companies', 'user_company_memberships.company_id', '=', 'companies.id')
            ->where('user_company_memberships.user_id', $id)
            ->select([
                'user_company_memberships.*',
                'companies.name as company_name',
            ])
            ->get();

        return response()->json([
            'data' => [
                'user' => $user,
                'memberships' => $memberships,
            ],
        ]);
    }

    /**
     * Manually verify a user's email address (super admin override).
     */
    public function verifyUserEmail(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        $user = User::findOrFail($id);

        if ($user->email_verified_at !== null) {
            return response()->json([
                'error' => [
                    'code' => 'ALREADY_VERIFIED',
                    'message' => 'User email is already verified.',
                ],
            ], 400);
        }

        $user->update([
            'email_verified_at' => now(),
        ]);

        // Get the tenant for audit logging
        $tenant = Tenant::find($user->tenant_id);

        // Type assertion - middleware guarantees this is a SuperAdmin
        /** @var SuperAdmin $admin */
        $admin = $request->user();

        $this->auditService->log(
            admin: $admin,
            action: 'verify_user_email',
            tenant: $tenant,
            entityType: 'user',
            entityId: $user->id,
            oldValues: ['email_verified_at' => null],
            newValues: ['email_verified_at' => $user->email_verified_at?->toDateTimeString()],
            notes: $request->input('notes') ?? 'Email manually verified by admin'
        );

        return response()->json([
            'data' => $user->fresh(),
            'message' => 'User email verified successfully.',
        ]);
    }
}

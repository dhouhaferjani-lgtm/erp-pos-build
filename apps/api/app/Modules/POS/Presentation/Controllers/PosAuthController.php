<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\ApprovalScope;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\POS\Domain\Services\DiscountPermissionResolver;
use App\Modules\POS\Presentation\Requests\VerifyPinRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PosAuthController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
        private readonly DiscountPermissionResolver $permissionResolver,
    ) {}

    /**
     * Verify a POS PIN and return the matching operator.
     *
     * POST /api/v1/pos/auth/verify-pin
     */
    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        /** @var User $currentUser */
        $currentUser = $request->user();
        $pin = $request->validated('pin');

        $users = User::where('tenant_id', $currentUser->tenant_id)
            ->whereNotNull('pos_pin')
            ->get();

        foreach ($users as $user) {
            if ($user->pos_pin !== null && Hash::check($pin, $user->pos_pin)) {
                $isAdmin = $this->permissionResolver->isAdmin($user);

                return response()->json([
                    'data' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->getRoleNames()->values()->all(),
                        'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                        'can_discount' => $this->permissionResolver->canDiscount($user),
                        'max_discount_percent' => $isAdmin ? 100.0 : $user->max_discount_percent,
                    ],
                ]);
            }
        }

        return response()->json([
            'error' => [
                'code' => 'INVALID_PIN',
                'message' => 'Invalid PIN',
            ],
        ], 422);
    }

    /**
     * Set up a POS PIN for the currently authenticated user.
     *
     * POST /api/v1/pos/auth/setup-pin
     */
    public function setupPin(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $validated = $request->validate([
            'pin' => ['required', 'string', 'digits_between:4,6'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $pin = $validated['pin'];

        // Check uniqueness within tenant
        $existingUsers = User::where('tenant_id', $user->tenant_id)
            ->where('id', '!=', $user->id)
            ->whereNotNull('pos_pin')
            ->get();

        foreach ($existingUsers as $existingUser) {
            if ($existingUser->pos_pin !== null && Hash::check($pin, $existingUser->pos_pin)) {
                throw ValidationException::withMessages([
                    'pin' => ['This PIN is already used by another user.'],
                ]);
            }
        }

        $user->update(['pos_pin' => $pin]);

        $isAdmin = $this->permissionResolver->isAdmin($user);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                'can_discount' => $this->permissionResolver->canDiscount($user),
                'max_discount_percent' => $isAdmin ? 100.0 : $user->max_discount_percent,
            ],
        ]);
    }

    /**
     * Get all operators with PINs for offline sync.
     *
     * GET /api/v1/pos/auth/pin-data
     *
     * Returns PIN hashes so the POS can verify PINs offline.
     */
    public function pinData(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        /** @var User $currentUser */
        $currentUser = $request->user();
        $company = $this->companyContext->requireCompany();
        $validated = $request->validate([
            'terminal_id' => [
                'nullable', 'uuid',
                Rule::exists('pos_terminals', 'id')
                    ->where(fn ($query) => $query
                        ->where('tenant_id', $company->tenant_id)
                        ->where('company_id', $company->id)
                        ->where('is_active', true)
                        ->where('type', '!=', TerminalType::VirtualAdmin->value)),
            ],
        ]);
        $terminalId = $validated['terminal_id'] ?? null;
        $terminalIds = is_string($terminalId) && $terminalId !== '' ? [$terminalId] : [];
        $serverTime = now()->toIso8601String();

        // F-3: only ACTIVE company members are mirrored into the device's
        // operator_pins. A suspended/revoked member who still holds a pos_pin +
        // approval permission must not appear in the offline operator/approval
        // list — keeping it in lockstep with the online
        // AuthorizedManagersController (active-only).
        $companyUserIds = UserCompanyMembership::query()
            ->where('company_id', $company->id)
            ->where('status', MembershipStatus::Active->value)
            ->pluck('user_id');

        $operators = User::where('tenant_id', $currentUser->tenant_id)
            ->whereIn('id', $companyUserIds)
            ->whereNotNull('pos_pin')
            ->get();

        $data = $operators->map(function (User $user) use ($company, $terminalIds, $serverTime): array {
            $isAdmin = $this->permissionResolver->isAdmin($user);
            $approvalScopes = array_values(array_map(
                static fn (ApprovalScope $scope): string => $scope->value,
                array_filter(
                    ApprovalScope::cases(),
                    static fn (ApprovalScope $scope): bool => $user->hasPermissionTo($scope->permissionName()),
                ),
            ));

            return [
                'id' => $user->id,
                'tenant_id' => $user->tenant_id,
                'name' => $user->name,
                'email' => $user->email,
                'pin_hash' => $user->pos_pin,
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                'can_discount' => $this->permissionResolver->canDiscount($user),
                'max_discount_percent' => $isAdmin ? 100.0 : $user->max_discount_percent,
                'company_ids' => [$company->id],
                'terminal_ids' => $terminalIds,
                'approval_scopes' => $approvalScopes,
                'approval_scope_permissions_fetched_at' => $serverTime,
                'server_time' => $serverTime,
            ];
        })->values()->all();

        return response()->json(['data' => $data]);
    }

    /**
     * Sync queued PIN updates from offline POS terminals.
     *
     * POST /api/v1/pos/auth/sync-pins
     *
     * Body: { updates: [{ user_id: uuid, pin_hash: string }, ...] }
     *
     * Idempotent: re-sending the same hash for a user is a no-op.
     * Tenant-scoped: only users in the caller's tenant may be updated.
     * Uses DB::table() to bypass the 'hashed' cast on pos_pin so the
     * already-bcrypt-hashed value from the client is stored verbatim.
     */
    public function syncPins(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        $validated = $request->validate([
            'updates' => ['required', 'array', 'min:1', 'max:50'],
            'updates.*.user_id' => ['required', 'uuid'],
            'updates.*.pin_hash' => ['required', 'string', 'min:20'],
        ]);

        /** @var User $currentUser */
        $currentUser = $request->user();

        $userIds = array_column($validated['updates'], 'user_id');
        $usersInTenant = User::where('tenant_id', $currentUser->tenant_id)
            ->whereIn('id', $userIds)
            ->get()
            ->keyBy('id');

        if ($usersInTenant->count() !== count(array_unique($userIds))) {
            throw ValidationException::withMessages([
                'updates' => ['One or more users are outside the current tenant.'],
            ]);
        }

        // SELF-ONLY: sync-pins may set ONLY the authenticated operator's own PIN.
        // The legit offline flow only ever queues an operator's OWN PIN
        // (`operatorStore.setupPin` enqueues `authStore.user.id`); the client
        // drains each operator's queued rows under that operator's own auth, so
        // this seam never needs to write another user's PIN. Enforcing self-only
        // here closes the same-company authorship gap (a member with
        // pos.operate_terminal could otherwise rewrite another active member's
        // PIN via a crafted request). Subsumes the earlier active-company-member
        // gate (the authenticated user already passed the active-membership
        // CompanyContext check).
        $currentUserId = (string) $currentUser->id;
        foreach ($userIds as $targetId) {
            if ((string) $targetId !== $currentUserId) {
                throw ValidationException::withMessages([
                    'updates' => ['sync-pins may only set the authenticated operator\'s own PIN.'],
                ]);
            }
        }

        $synced = 0;
        $skipped = 0;

        foreach ($validated['updates'] as $update) {
            /** @var User $user */
            $user = $usersInTenant[$update['user_id']];

            // Bypass the 'hashed' Eloquent cast: the client already sent a bcrypt hash.
            // Using the cast would double-hash the value and break PIN verification.
            // Writing the same hash again is a deliberate no-error idempotent upsert.
            DB::table('users')
                ->where('id', $user->id)
                ->update(['pos_pin' => $update['pin_hash']]);

            $synced++;
        }

        return response()->json([
            'data' => [
                'synced' => $synced,
                'skipped' => $skipped,
            ],
        ]);
    }

    /**
     * Check if any user in the tenant has a POS PIN set.
     *
     * GET /api/v1/pos/auth/has-pins
     */
    public function hasPins(Request $request): JsonResponse
    {
        Gate::authorize('pos.operate_terminal');

        /** @var User $currentUser */
        $currentUser = $request->user();

        $hasPins = User::where('tenant_id', $currentUser->tenant_id)
            ->whereNotNull('pos_pin')
            ->exists();

        return response()->json([
            'data' => [
                'has_pins' => $hasPins,
            ],
        ]);
    }
}

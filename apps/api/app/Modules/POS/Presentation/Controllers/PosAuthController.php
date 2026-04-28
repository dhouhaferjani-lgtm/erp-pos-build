<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Presentation\Requests\VerifyPinRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class PosAuthController extends Controller
{
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
                $isAdmin = $user->hasRole(['super_admin', 'admin']);

                return response()->json([
                    'data' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->getRoleNames()->values()->all(),
                        'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                        'can_discount' => $isAdmin || $user->can_discount,
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

        $isAdmin = $user->hasRole(['super_admin', 'admin']);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                'can_discount' => $isAdmin || $user->can_discount,
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

        $operators = User::where('tenant_id', $currentUser->tenant_id)
            ->whereNotNull('pos_pin')
            ->get();

        $data = $operators->map(function (User $user): array {
            $isAdmin = $user->hasRole(['super_admin', 'admin']);

            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'pin_hash' => $user->pos_pin,
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
                'can_discount' => $isAdmin || (bool) $user->can_discount,
                'max_discount_percent' => $isAdmin ? 100.0 : $user->max_discount_percent,
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

        if ($usersInTenant->count() !== count($userIds)) {
            throw ValidationException::withMessages([
                'updates' => ['One or more users are outside the current tenant.'],
            ]);
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

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Enums\SuperAdminRole;
use App\Models\SuperAdmin;
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class SuperAdminAuthController extends Controller
{
    #[CrossTenantRoute(reason: 'Super-admin authentication (pre-auth): credential check against the super_admins table BEFORE any session/tenant context exists; mounted public on /admin/auth/login with the throttle:admin-login rate-limit guard. On success, issues a Sanctum personal-access token under the sanctum-admin guard. Super-admins are platform-level actors with no tenant context by design.')]
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $admin = SuperAdmin::where('email', $request->input('email'))->first();

        if ($admin === null || ! Hash::check((string) $request->input('password'), $admin->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $admin->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        if ($admin->role === SuperAdminRole::DefaultsEditor->value
            && ! config('country_defaults.external_editors_enabled', false)) {
            throw ValidationException::withMessages([
                'email' => [trans('country_defaults.auth.external_editors_disabled')],
            ]);
        }

        // Update last login info
        $admin->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Encode `super-admin` ability so EnforceTokenTenantClaim
        // (Invariant D, master plan §15) recognizes the super-admin pipeline
        // and exits early — super-admins operate cross-tenant by design and
        // SuperAdmin lacks `tenant_id`.
        $token = $admin->createToken('super-admin-token', ['super-admin'])->plainTextToken;

        return response()->json([
            'data' => [
                'admin' => $admin,
                'token' => $token,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var SuperAdmin $admin */
        $admin = $request->user();
        $admin->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $request->user()]);
    }
}

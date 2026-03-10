<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Application\DTOs\UserData;
use App\Modules\Identity\Application\Notifications\UserInvitation;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Identity\Presentation\Requests\CreateUserRequest;
use App\Modules\Identity\Presentation\Requests\UpdateUserRequest;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class UserController extends Controller
{
    /**
     * List all users in the tenant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        if (! $currentUser->can('users.view')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to view users.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_FORBIDDEN);
        }

        $query = User::where('tenant_id', $currentUser->tenant_id);

        // Filter by status
        if ($request->has('status') && $request->get('status') !== null) {
            $query->where('status', $request->get('status'));
        }

        // Search by name or email (use LIKE for SQLite compatibility in tests)
        if ($request->has('search') && $request->get('search') !== null) {
            $search = $request->get('search');
            // Escape LIKE special characters to prevent LIKE pattern injection
            $search = addcslashes($search, '%_\\');

            $query->where(function ($q) use ($search): void {
                $q->whereRaw('LOWER(name) LIKE LOWER(?)', ["%{$search}%"])
                    ->orWhereRaw('LOWER(email) LIKE LOWER(?)', ["%{$search}%"]);
            });
        }

        $perPage = min((int) $request->get('per_page', 15), 100);
        $users = $query->orderBy('created_at', 'desc')->paginate($perPage);

        $data = $users->getCollection()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'status' => $user->status->value,
            'roles' => $user->getRoleNames()->values()->all(),
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
            'createdAt' => $user->created_at->toIso8601String(),
        ]);

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
                'timestamp' => now()->toIso8601String(),
                'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
            ],
        ]);
    }

    /**
     * Get a single user by ID.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        if (! $currentUser->can('users.view')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to view users.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_FORBIDDEN);
        }

        $user = User::where('tenant_id', $currentUser->tenant_id)
            ->where('id', $id)
            ->first();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'User not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => UserData::fromUser($user),
            'meta' => $this->getMeta($request),
        ]);
    }

    /**
     * Create a new user.
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();
        $validated = $request->validated();

        /** @var Tenant $tenant */
        $tenant = Tenant::findOrFail($currentUser->tenant_id);

        return DB::transaction(function () use ($validated, $currentUser, $tenant, $request) {
            // Generate a random password (user will set it via invitation email)
            $tempPassword = Str::random(32);

            $user = User::create([
                'tenant_id' => $currentUser->tenant_id,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($tempPassword),
                'status' => UserStatus::PendingVerification,
                'locale' => $validated['locale'] ?? null,
                'timezone' => $validated['timezone'] ?? null,
            ]);

            // Set permissions team context and assign role
            setPermissionsTeamId($currentUser->tenant_id);
            $user->assignRole($validated['role']);

            // Send invitation email
            $user->notify(new UserInvitation(
                inviterName: $currentUser->name,
                tenantName: $tenant->name
            ));

            // Log audit event
            $this->logAuditEvent(
                eventType: 'user.created',
                aggregateId: $user->id,
                userId: $currentUser->id,
                companyId: $request->header('X-Company-Id'),
                payload: [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $validated['role'],
                ]
            );

            return response()->json([
                'data' => UserData::fromUser($user),
                'meta' => $this->getMeta($request),
            ], Response::HTTP_CREATED);
        });
    }

    /**
     * Update an existing user.
     */
    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        $user = User::where('tenant_id', $currentUser->tenant_id)
            ->where('id', $id)
            ->first();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'User not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        $validated = $request->validated();

        return DB::transaction(function () use ($user, $validated, $currentUser, $request) {
            $changes = [];

            // Update basic fields
            $fieldsToUpdate = ['name', 'email', 'phone', 'locale', 'timezone'];
            foreach ($fieldsToUpdate as $field) {
                if (array_key_exists($field, $validated)) {
                    $changes[$field] = [
                        'old' => $user->{$field},
                        'new' => $validated[$field],
                    ];
                    $user->{$field} = $validated[$field];
                }
            }

            // Handle role change
            if (array_key_exists('role', $validated)) {
                $oldRoles = $user->getRoleNames()->values()->all();
                setPermissionsTeamId($currentUser->tenant_id);
                $user->syncRoles([$validated['role']]);
                $changes['role'] = [
                    'old' => $oldRoles,
                    'new' => [$validated['role']],
                ];
            }

            $user->save();

            // Log audit event
            $this->logAuditEvent(
                eventType: 'user.updated',
                aggregateId: $user->id,
                userId: $currentUser->id,
                companyId: $request->header('X-Company-Id'),
                payload: ['changes' => $changes]
            );

            return response()->json([
                'data' => UserData::fromUser($user->refresh()),
                'meta' => $this->getMeta($request),
            ]);
        });
    }

    /**
     * Delete (deactivate) a user.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        if (! $currentUser->can('users.delete')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to delete users.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_FORBIDDEN);
        }

        $user = User::where('tenant_id', $currentUser->tenant_id)
            ->where('id', $id)
            ->first();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'User not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        // Cannot delete self
        if ($user->id === $currentUser->id) {
            return response()->json([
                'error' => [
                    'code' => 'CANNOT_DELETE_SELF',
                    'message' => 'You cannot delete your own account.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return DB::transaction(function () use ($user, $currentUser, $request) {
            // Revoke all tokens
            $user->tokens()->delete();

            // Set status to inactive (soft delete)
            $user->status = UserStatus::Inactive;
            $user->save();

            // Log audit event
            $this->logAuditEvent(
                eventType: 'user.deleted',
                aggregateId: $user->id,
                userId: $currentUser->id,
                companyId: $request->header('X-Company-Id'),
                payload: ['email' => $user->email]
            );

            return response()->json([
                'data' => ['message' => 'User deleted successfully'],
                'meta' => $this->getMeta($request),
            ]);
        });
    }

    /**
     * Activate a user.
     */
    public function activate(Request $request, string $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        if (! $currentUser->can('users.update')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to activate users.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_FORBIDDEN);
        }

        $user = User::where('tenant_id', $currentUser->tenant_id)
            ->where('id', $id)
            ->first();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'User not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        if ($user->status === UserStatus::Active) {
            return response()->json([
                'error' => [
                    'code' => 'USER_ALREADY_ACTIVE',
                    'message' => 'User is already active.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return DB::transaction(function () use ($user, $currentUser, $request) {
            $user->status = UserStatus::Active;
            $user->save();

            // Log audit event
            $this->logAuditEvent(
                eventType: 'user.activated',
                aggregateId: $user->id,
                userId: $currentUser->id,
                companyId: $request->header('X-Company-Id'),
                payload: ['email' => $user->email]
            );

            return response()->json([
                'data' => UserData::fromUser($user->refresh()),
                'meta' => $this->getMeta($request),
            ]);
        });
    }

    /**
     * Deactivate a user.
     */
    public function deactivate(Request $request, string $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        if (! $currentUser->can('users.update')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to deactivate users.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_FORBIDDEN);
        }

        $user = User::where('tenant_id', $currentUser->tenant_id)
            ->where('id', $id)
            ->first();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'User not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        // Cannot deactivate self
        if ($user->id === $currentUser->id) {
            return response()->json([
                'error' => [
                    'code' => 'CANNOT_DEACTIVATE_SELF',
                    'message' => 'You cannot deactivate your own account.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($user->status === UserStatus::Inactive) {
            return response()->json([
                'error' => [
                    'code' => 'USER_ALREADY_INACTIVE',
                    'message' => 'User is already inactive.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return DB::transaction(function () use ($user, $currentUser, $request) {
            // Revoke all tokens
            $user->tokens()->delete();

            $user->status = UserStatus::Inactive;
            $user->save();

            // Log audit event
            $this->logAuditEvent(
                eventType: 'user.deactivated',
                aggregateId: $user->id,
                userId: $currentUser->id,
                companyId: $request->header('X-Company-Id'),
                payload: ['email' => $user->email]
            );

            return response()->json([
                'data' => UserData::fromUser($user->refresh()),
                'meta' => $this->getMeta($request),
            ]);
        });
    }

    /**
     * Set or clear POS PIN for a user.
     *
     * PATCH /api/v1/users/{id}/pos-pin
     */
    public function setPosPin(Request $request, string $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        if (! $currentUser->can('users.update')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to manage POS PINs.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_FORBIDDEN);
        }

        $request->validate([
            'pin' => ['nullable', 'string', 'digits_between:4,6'],
        ]);

        $user = User::where('tenant_id', $currentUser->tenant_id)
            ->where('id', $id)
            ->first();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'User not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        $pin = $request->input('pin');

        if ($pin === null) {
            $user->update(['pos_pin' => null]);

            return response()->json([
                'data' => ['message' => 'POS PIN cleared'],
                'meta' => $this->getMeta($request),
            ]);
        }

        // Check uniqueness within tenant (iterate and Hash::check)
        $existingUsers = User::where('tenant_id', $currentUser->tenant_id)
            ->where('id', '!=', $user->id)
            ->whereNotNull('pos_pin')
            ->get();

        foreach ($existingUsers as $existingUser) {
            if ($existingUser->pos_pin !== null && Hash::check($pin, $existingUser->pos_pin)) {
                return response()->json([
                    'error' => [
                        'code' => 'PIN_ALREADY_IN_USE',
                        'message' => 'This PIN is already used by another user.',
                    ],
                    'meta' => $this->getMeta($request),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $user->update(['pos_pin' => $pin]);

        $this->logAuditEvent(
            eventType: 'user.pos_pin_set',
            aggregateId: $user->id,
            userId: $currentUser->id,
            companyId: $request->header('X-Company-Id'),
        );

        return response()->json([
            'data' => ['message' => 'POS PIN updated'],
            'meta' => $this->getMeta($request),
        ]);
    }

    /**
     * Trigger password reset for a user.
     */
    public function resetPassword(Request $request, string $id): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $request->user();

        if (! $currentUser->can('users.update')) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'You do not have permission to reset passwords.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_FORBIDDEN);
        }

        $user = User::where('tenant_id', $currentUser->tenant_id)
            ->where('id', $id)
            ->first();

        if ($user === null) {
            return response()->json([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'User not found.',
                ],
                'meta' => $this->getMeta($request),
            ], Response::HTTP_NOT_FOUND);
        }

        // Send password reset notification
        $token = Password::createToken($user);
        $user->notify(new ResetPassword($token));

        // Log audit event
        $this->logAuditEvent(
            eventType: 'user.password_reset_triggered',
            aggregateId: $user->id,
            userId: $currentUser->id,
            companyId: $request->header('X-Company-Id'),
            payload: ['email' => $user->email]
        );

        return response()->json([
            'data' => ['message' => 'Password reset email sent'],
            'meta' => $this->getMeta($request),
        ]);
    }

    /**
     * Get companies the current user has access to.
     */
    public function companies(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Get company IDs from memberships
        $companyIds = UserCompanyMembership::where('user_id', $user->id)
            ->pluck('company_id');

        // Fetch companies
        $companies = Company::whereIn('id', $companyIds)
            ->orderBy('name')
            ->get();

        $data = $companies->map(fn (Company $company) => [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'tax_id' => $company->tax_id,
            'country_code' => $company->country_code,
            'currency' => $company->currency,
            'locale' => $company->locale,
            'timezone' => $company->timezone,
        ]);

        return response()->json([
            'data' => $data,
            'meta' => $this->getMeta($request),
        ]);
    }

    /**
     * Get response metadata.
     *
     * @return array<string, mixed>
     */
    private function getMeta(Request $request): array
    {
        return [
            'timestamp' => now()->toIso8601String(),
            'request_id' => $request->header('X-Request-ID', (string) uuid_create()),
        ];
    }

    /**
     * Log an audit event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function logAuditEvent(
        string $eventType,
        string $aggregateId,
        string $userId,
        ?string $companyId,
        array $payload = []
    ): void {
        // Skip audit logging when no company context is available
        // User management at tenant level doesn't require company context
        if ($companyId === null) {
            return;
        }

        $event = new AuditEvent(
            companyId: $companyId,
            userId: $userId,
            eventType: $eventType,
            aggregateType: 'user',
            aggregateId: $aggregateId,
            payload: $payload
        );
        $event->save();
    }
}

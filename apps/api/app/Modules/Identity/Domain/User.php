<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * User model for AutoERP.
 *
 * Users belong to a tenant and can have multiple roles/permissions.
 *
 * @property string $id UUID of the user
 * @property string $tenant_id UUID of the tenant
 * @property string $name Full name
 * @property string|null $email Email address (null for PIN-only cashiers)
 * @property string|null $phone Phone number
 * @property string $password Hashed password
 * @property UserStatus $status Account status
 * @property string|null $locale Preferred locale
 * @property string|null $timezone Preferred timezone
 * @property array<string, mixed> $preferences User preferences
 * @property string|null $pos_pin Bcrypt-hashed 4-6 digit PIN for POS operator auth
 * @property bool $can_discount Whether cashier can apply discounts
 * @property string|null $max_discount_percent Maximum discount percentage (NULL = no individual limit)
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class User extends Authenticatable
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles {
        getAllPermissions as private getAllPermissionsWithoutImpersonationFilter;
        hasPermissionTo as private hasPermissionToWithoutImpersonationFilter;
    }
    use HasUuids;
    use Notifiable;

    /**
     * The table associated with the model.
     */
    protected $table = 'users';

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'password',
        'pos_pin',
        'status',
        'locale',
        'timezone',
        'preferences',
        'can_discount',
        'max_discount_percent',
        'email_verified_at',
        'last_login_at',
        'last_login_ip',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pos_pin',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UserStatus::class,
            'preferences' => 'array',
            'can_discount' => 'boolean',
            'max_discount_percent' => 'decimal:2',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'pos_pin' => 'hashed',
        ];
    }

    /**
     * Get the user's devices.
     *
     * @return HasMany<Device, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /**
     * Get the user's company memberships.
     *
     * @return HasMany<UserCompanyMembership, $this>
     */
    public function companyMemberships(): HasMany
    {
        return $this->hasMany(UserCompanyMembership::class);
    }

    /**
     * Get the tenant the user belongs to.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the team (tenant) ID for Spatie Permission multi-tenancy.
     *
     * This method is required by Spatie Permission when teams are enabled.
     * It tells the package which tenant context to use when checking permissions.
     */
    public function getPermissionsTeamId(): string
    {
        return $this->tenant_id;
    }

    /**
     * Support-session permission intersection must start from the subject's
     * current database grants, not from the token's previously minted list.
     *
     * @return Collection<int, Permission>
     */
    public function getUnfilteredPermissionsForSupportAccess(): Collection
    {
        return $this->getAllPermissionsWithoutImpersonationFilter();
    }

    /**
     * @param  string|int|Permission|\BackedEnum  $permission
     * @param  string|null  $guardName
     */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $allowed = $this->impersonationPermissionNames();
        if ($allowed === null) {
            return $this->hasPermissionToWithoutImpersonationFilter($permission, $guardName);
        }

        $resolved = $this->filterPermission($permission, $guardName);
        if (! in_array($resolved->name, $allowed, true)) {
            return false;
        }

        return $this->hasPermissionToWithoutImpersonationFilter($permission, $guardName);
    }

    /** @return Collection<int, Permission> */
    public function getAllPermissions(): Collection
    {
        $permissions = $this->getAllPermissionsWithoutImpersonationFilter();
        $allowed = $this->impersonationPermissionNames();

        if ($allowed === null) {
            return $permissions;
        }

        return $permissions
            ->filter(static fn ($permission): bool => in_array($permission->name, $allowed, true))
            ->values();
    }

    /** @return list<string>|null */
    private function impersonationPermissionNames(): ?array
    {
        $token = $this->resolvePersonalAccessToken($this->currentAccessToken());
        if ($token === null || ! is_array($token->abilities)) {
            return null;
        }

        $abilities = array_values(array_filter($token->abilities, is_string(...)));

        $isImpersonating = false;
        foreach ($abilities as $ability) {
            if (str_starts_with($ability, 'impersonation:')) {
                $isImpersonating = true;
                break;
            }
        }
        if (! $isImpersonating) {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn (string $ability): ?string => str_starts_with($ability, 'permission:')
                ? substr($ability, strlen('permission:'))
                : null,
            $abilities,
        )));
    }

    private function resolvePersonalAccessToken(?HasAbilities $token): ?PersonalAccessToken
    {
        return $token instanceof PersonalAccessToken ? $token : null;
    }

    /**
     * Check if user has a POS PIN set.
     */
    public function hasPosPin(): bool
    {
        return $this->pos_pin !== null;
    }

    /**
     * Check if the user is active.
     */
    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * Check if the user's email is verified.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Record a login event.
     */
    public function recordLogin(string $ip): void
    {
        $this->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }

    /**
     * Get the default guard name for spatie/permission.
     * This must match the guard used when creating roles/permissions.
     */
    public function getDefaultGuardName(): string
    {
        return 'sanctum';
    }

    /**
     * Check if user can access a product channel for WebSocket subscriptions.
     *
     * Verifies that:
     * 1. User belongs to the specified tenant
     * 2. User has active membership in the specified company
     * 3. User account is active
     */
    public function canAccessChannel(
        string $tenantId,
        string $companyId,
        string $productId
    ): bool {
        // User must belong to the tenant
        if ($this->tenant_id !== $tenantId) {
            return false;
        }

        // User must be active
        if (! $this->isActive()) {
            return false;
        }

        // User must have an active membership in the company
        $hasMembership = $this->companyMemberships()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->exists();

        return $hasMembership;
    }

    /**
     * Check if user can access a company-scoped broadcast channel.
     *
     * Used for WebSocket channel authorization for real-time updates
     * (imports, POS terminals, kitchen display, etc.).
     * Verifies that user belongs to tenant and has company membership.
     */
    public function canAccessCompanyChannel(string $tenantId, string $companyId): bool
    {
        // User must belong to the tenant
        if ($this->tenant_id !== $tenantId) {
            return false;
        }

        // User must be active
        if (! $this->isActive()) {
            return false;
        }

        // User must have an active membership in the company
        return $this->companyMemberships()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->exists();
    }
}

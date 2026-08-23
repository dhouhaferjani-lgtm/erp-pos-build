<?php

declare(strict_types=1);

namespace App\Modules\Company\Domain;

use App\Modules\Company\Domain\Enums\MembershipRevocationReason;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Identity\Domain\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UserCompanyMembership model - defines the many-to-many relationship between users and companies.
 *
 * A user can belong to multiple companies with different roles.
 * Roles: owner, admin, manager, accountant, cashier, technician, viewer
 *
 * @property string $id UUID of the membership
 * @property string $user_id UUID of the user
 * @property string $company_id UUID of the company
 * @property MembershipRole $role Role within this company
 * @property array<string>|null $allowed_location_ids Location restrictions (NULL = all locations)
 * @property bool $is_primary Whether this is the primary company for the user
 * @property string|null $invited_by UUID of the user who invited
 * @property Carbon|null $invited_at When the invitation was sent
 * @property Carbon|null $accepted_at When the invitation was accepted
 * @property MembershipStatus $status Membership status
 * @property Carbon|null $revoked_at When the membership was revoked
 * @property string|null $revoked_by UUID of the user who revoked it
 * @property MembershipRevocationReason|null $revoked_reason Why it was revoked (NULL = legacy/unattributed)
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read Company $company
 * @property-read User|null $invitedBy
 */
class UserCompanyMembership extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'user_company_memberships';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'company_id',
        'role',
        'allowed_location_ids',
        'is_primary',
        'invited_by',
        'invited_at',
        'accepted_at',
        'status',
        // DELIBERATELY NOT FILLABLE: revoked_at / revoked_by / revoked_reason.
        // `revoked_reason = user_deactivated` is what makes a revocation
        // auto-reversible by UserController::activate(). It must only ever be
        // written by the offboarding cascade, which uses query-builder
        // update() (unaffected by $fillable). Adding these here would let the
        // first membership-management endpoint that mass-assigns validated
        // input stamp a DELIBERATE revocation as cascade-owned and have
        // reactivation silently undo it — defeating the fail-closed rule.
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => MembershipRole::class,
            'status' => MembershipStatus::class,
            'revoked_reason' => MembershipRevocationReason::class,
            'allowed_location_ids' => 'array',
            'is_primary' => 'boolean',
            'invited_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Get the user for this membership.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the company for this membership.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the user who sent the invitation.
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Check if the membership allows access to a specific location.
     */
    public function canAccessLocation(Location $location): bool
    {
        // NULL means all locations
        if ($this->allowed_location_ids === null) {
            return true;
        }

        return in_array($location->id, $this->allowed_location_ids, true);
    }

    /**
     * Check if this is an owner role.
     */
    public function isOwner(): bool
    {
        return $this->role === MembershipRole::Owner;
    }

    /**
     * Check if this is an admin role (owner or admin).
     */
    public function isAdmin(): bool
    {
        return $this->role->isAdminRole();
    }

    /**
     * Check if the membership is active.
     */
    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }
}

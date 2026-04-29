<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entities;

use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $customer_id
 * @property string|null $loyaltyable_type
 * @property string|null $loyaltyable_id
 * @property string $phone
 * @property string|null $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property Carbon|null $date_of_birth
 * @property MemberStatus $status
 * @property Carbon $enrollment_date
 * @property string|null $external_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model|null $loyaltyable
 * @property-read Collection<int, Enrollment> $enrollments
 */
class LoyaltyMember extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'loyaltyable_type',
        'loyaltyable_id',
        'phone',
        'email',
        'first_name',
        'last_name',
        'date_of_birth',
        'status',
        'enrollment_date',
        'external_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'status' => MemberStatus::class,
            'enrollment_date' => 'datetime',
        ];
    }

    /**
     * Get the loyaltyable entity (Contact or Partner)
     *
     * @return MorphTo<Model, $this>
     */
    public function loyaltyable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get all program enrollments for this member
     *
     * @return HasMany<Enrollment, $this>
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'member_id');
    }

    /**
     * Get full name
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Normalize phone number before saving
     */
    public static function normalizePhone(string $phone): string
    {
        // Remove all non-digit characters except +
        $normalized = preg_replace('/[^\d+]/', '', $phone);

        return $normalized ?? $phone;
    }
}

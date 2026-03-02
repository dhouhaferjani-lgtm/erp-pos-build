<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Entities;

use App\Modules\Loyalty\Domain\Enums\MemberStatus;
use App\Modules\Partner\Domain\Partner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $customer_id
 * @property string $phone
 * @property string|null $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property \Illuminate\Support\Carbon|null $date_of_birth
 * @property MemberStatus $status
 * @property \Illuminate\Support\Carbon $enrollment_date
 * @property string|null $external_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Partner|null $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Enrollment> $enrollments
 */
class LoyaltyMember extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'customer_id',
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
     * Get the customer (partner) associated with this member
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    /**
     * Get all program enrollments for this member
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

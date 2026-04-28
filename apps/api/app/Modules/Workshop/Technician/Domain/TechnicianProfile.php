<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Technician\Domain\Enums\EmploymentStatus;
use App\Modules\Workshop\Technician\Domain\Enums\SkillLevel;
use App\Modules\Workshop\Technician\Domain\Enums\SpecialtyCode;
use Database\Factories\Workshop\TechnicianProfileFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Technician profile sidecar to User + UserCompanyMembership.
 *
 * One profile per (user, company). PII fields (hourly_cost_rate, hourly_billing_rate,
 * national_id, personal_address, personal_phone) are masked at the DTO layer by
 * permission — never expose them directly from model->toArray().
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $user_id
 * @property SkillLevel $skill_level
 * @property SupportCollection<int, SpecialtyCode> $specialties
 * @property numeric-string|null $hourly_cost_rate
 * @property numeric-string|null $hourly_billing_rate
 * @property string $currency
 * @property array<string, list<array{start: string, end: string}>> $weekly_schedule
 * @property Carbon|null $hire_date
 * @property EmploymentStatus $employment_status
 * @property string|null $employee_code
 * @property string|null $notes
 * @property string|null $national_id
 * @property string|null $personal_address
 * @property string|null $personal_phone
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read User $user
 */
final class TechnicianProfile extends Model
{
    /** @use HasFactory<TechnicianProfileFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /** @var string */
    protected $table = 'workshop_technician_profiles';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'user_id',
        'skill_level',
        'specialties',
        'hourly_cost_rate',
        'hourly_billing_rate',
        'currency',
        'weekly_schedule',
        'hire_date',
        'employment_status',
        'employee_code',
        'notes',
        'national_id',
        'personal_address',
        'personal_phone',
        'is_active',
    ];

    protected static function newFactory(): TechnicianProfileFactory
    {
        return TechnicianProfileFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'skill_level' => SkillLevel::class,
            'specialties' => AsEnumCollection::of(SpecialtyCode::class),
            'hourly_cost_rate' => 'decimal:3',
            'hourly_billing_rate' => 'decimal:3',
            'weekly_schedule' => 'array',
            'hire_date' => 'date',
            'employment_status' => EmploymentStatus::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<TechnicianCertification, $this>
     */
    public function certifications(): HasMany
    {
        return $this->hasMany(TechnicianCertification::class, 'technician_profile_id');
    }

    /**
     * @return HasMany<TechnicianTimeOff, $this>
     */
    public function timeOff(): HasMany
    {
        return $this->hasMany(TechnicianTimeOff::class, 'technician_profile_id');
    }

    /**
     * @return HasMany<TechnicianTimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TechnicianTimeEntry::class, 'technician_profile_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Contact\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Contact\Domain\Enums\Gender;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $mobile
 * @property \Illuminate\Support\Carbon|null $date_of_birth
 * @property Gender|null $gender
 * @property string|null $national_id
 * @property string|null $avatar_media_id
 * @property string|null $notes
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, PartyContact> $partyContacts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Partner> $parties
 * @property-read string $full_name
 */
class Contact extends Model
{
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'tenant_id',
        'company_id',
        'first_name',
        'last_name',
        'email',
        'phone',
        'mobile',
        'date_of_birth',
        'gender',
        'national_id',
        'avatar_media_id',
        'notes',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_active' => 'boolean',
            'gender' => Gender::class,
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<PartyContact, $this>
     */
    public function partyContacts(): HasMany
    {
        return $this->hasMany(PartyContact::class);
    }

    /**
     * @return BelongsToMany<Partner, $this>
     */
    public function parties(): BelongsToMany
    {
        return $this->belongsToMany(Partner::class, 'party_contacts', 'contact_id', 'party_id')
            ->withPivot(['job_title', 'department', 'is_primary', 'start_date', 'end_date'])
            ->withTimestamps();
    }

    /**
     * Scope a query to only include contacts for a specific tenant.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope a query to only include contacts for a specific company.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope a query to only include active contacts.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to search contacts by name, phone, or email.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term): void {
            $q->where('first_name', 'ilike', "%{$term}%")
                ->orWhere('last_name', 'ilike', "%{$term}%")
                ->orWhere('phone', 'ilike', "%{$term}%")
                ->orWhere('email', 'ilike', "%{$term}%");
        });
    }

    /**
     * Get the contact's full name.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}

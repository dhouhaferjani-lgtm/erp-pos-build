<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * POS Terminal Entity
 *
 * Represents a physical POS device (cash register, tablet, kiosk).
 * Each terminal maintains independent receipt sequences and hash chains for NF525 compliance.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $location_id
 * @property string $code Terminal code (e.g., POS01, POS02)
 * @property string $name Terminal display name
 * @property string|null $description
 * @property string $genesis_seed 256-bit hex string for hash chain initialization
 * @property int $current_sequence Next receipt sequence number for current year
 * @property int $current_year Year for sequence reset logic
 * @property string|null $last_hash Hash of most recent receipt (for chain continuity)
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $activated_at
 * @property \Illuminate\Support\Carbon|null $deactivated_at
 * @property string|null $deactivation_reason
 * @property string|null $hardware_identifier MAC address, serial number, etc.
 * @property string|null $pos_software_version Tauri app version
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Location $location
 * @property-read Collection<int, Receipt> $receipts
 *
 * @method static Builder<static> forTenant(string $tenantId)
 * @method static Builder<static> forCompany(string $companyId)
 * @method static Builder<static> forLocation(string $locationId)
 * @method static Builder<static> active()
 * @method static Builder<static> byCode(string $code)
 */
class Terminal extends Model
{
    use HasUuids;
    use SoftDeletes;

    /**
     * @var string
     */
    protected $table = 'pos_terminals';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'code',
        'name',
        'description',
        'genesis_seed',
        'current_sequence',
        'current_year',
        'last_hash',
        'is_active',
        'activated_at',
        'deactivated_at',
        'deactivation_reason',
        'hardware_identifier',
        'pos_software_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_sequence' => 'integer',
            'current_year' => 'integer',
            'is_active' => 'boolean',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<Receipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'terminal_id');
    }

    /**
     * Check if terminal is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Check if terminal has been deactivated
     */
    public function isDeactivated(): bool
    {
        return ! $this->is_active && $this->deactivated_at !== null;
    }

    /**
     * Check if terminal needs sequence reset for new year
     */
    public function needsSequenceReset(): bool
    {
        return $this->current_year !== (int) now()->format('Y');
    }

    /**
     * Get formatted terminal identifier (e.g., "POS01 - Front Counter")
     */
    public function getDisplayName(): string
    {
        return "{$this->code} - {$this->name}";
    }

    /**
     * Scope to filter terminals by tenant
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter terminals by company
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Scope to filter terminals by location
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForLocation(Builder $query, string $locationId): Builder
    {
        return $query->where('location_id', $locationId);
    }

    /**
     * Scope to filter only active terminals
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to find terminal by code
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\POS\Domain\Enums\TerminalType;
use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\TerminalFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 * @property TerminalType $type Terminal type (web or physical)
 * @property string $code Terminal code (e.g., POS01, WEB-MAIN)
 * @property string $name Terminal display name
 * @property string|null $description
 * @property string $genesis_seed 256-bit hex string for hash chain initialization
 * @property int $current_sequence Next receipt sequence number for current year
 * @property int $current_year Year for sequence reset logic
 * @property string|null $last_hash Hash of most recent receipt (for chain continuity)
 * @property bool $is_active
 * @property bool $is_training_mode
 * @property Carbon|null $activated_at
 * @property Carbon|null $deactivated_at
 * @property string|null $deactivation_reason
 * @property string|null $hardware_identifier MAC address, serial number, etc.
 * @property string|null $pos_software_version Tauri app version
 * @property float $max_discount_percent Maximum allowed discount percentage (0-100)
 * @property bool $allow_line_discounts Whether line-level discounts are allowed
 * @property bool $allow_transaction_discounts Whether transaction-level discounts are allowed
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Location $location
 * @property-read Collection<int, Receipt> $receipts
 *
 * @method static Builder<static> forTenant(string $tenantId)
 * @method static Builder<static> forCompany(string $companyId)
 * @method static Builder<static> forLocation(string $locationId)
 * @method static Builder<static> active()
 * @method static Builder<static> production()
 * @method static Builder<static> training()
 * @method static Builder<static> byCode(string $code)
 * @method static Builder<static> web()
 * @method static Builder<static> physical()
 */
class Terminal extends Model
{
    /** @use HasFactory<TerminalFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    /**
     * @var string
     */
    protected $table = 'pos_terminals';

    protected static function newFactory(): TerminalFactory
    {
        return TerminalFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'location_id',
        'type',
        'code',
        'name',
        'description',
        'genesis_seed',
        'current_sequence',
        'current_year',
        'last_hash',
        'is_active',
        'is_training_mode',
        'activated_at',
        'deactivated_at',
        'deactivation_reason',
        'hardware_identifier',
        'pos_software_version',
        'max_discount_percent',
        'allow_line_discounts',
        'allow_transaction_discounts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TerminalType::class,
            'current_sequence' => 'integer',
            'current_year' => 'integer',
            'is_active' => 'boolean',
            'is_training_mode' => 'boolean',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'max_discount_percent' => 'float',
            'allow_line_discounts' => 'boolean',
            'allow_transaction_discounts' => 'boolean',
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
     * @return HasMany<Shift, $this>
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'terminal_id');
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
     * Scope to filter production (non-training) terminals
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProduction(Builder $query): Builder
    {
        return $query->where('is_training_mode', false);
    }

    /**
     * Scope to filter training mode terminals
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeTraining(Builder $query): Builder
    {
        return $query->where('is_training_mode', true);
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

    /**
     * Scope to filter web terminals only
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWeb(Builder $query): Builder
    {
        return $query->where('type', TerminalType::Web);
    }

    /**
     * Scope to filter physical terminals only
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePhysical(Builder $query): Builder
    {
        return $query->where('type', TerminalType::Physical);
    }

    /**
     * Check if this is a web terminal
     */
    public function isWeb(): bool
    {
        return $this->type === TerminalType::Web;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Domain\Enums\TableStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * POS Floor Entity
 *
 * Represents a physical floor or zone in a venue (e.g., "Main Floor", "Terrace").
 * Floors group tables for organizational purposes and future floor planner UI.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $name
 * @property int $position
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Collection<int, Table> $tables
 */
class Floor extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_floors';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'name',
        'position',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
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
     * @return HasMany<Table, $this>
     */
    public function tables(): HasMany
    {
        return $this->hasMany(Table::class, 'floor_id')
            ->orderBy('table_number');
    }

    public function hasOccupiedTables(): bool
    {
        return $this->tables()->where('status', TableStatus::Occupied)->exists();
    }
}

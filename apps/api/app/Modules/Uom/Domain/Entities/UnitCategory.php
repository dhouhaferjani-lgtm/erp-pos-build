<?php

declare(strict_types=1);

namespace App\Modules\Uom\Domain\Entities;

use App\Modules\Tenant\Domain\Tenant;
use Database\Factories\UnitCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $base_unit_id
 * @property bool $is_system
 * @property bool $is_active
 * @property-read Unit|null $baseUnit
 * @property-read \Illuminate\Database\Eloquent\Collection<Unit> $units
 */
class UnitCategory extends Model
{
    use HasFactory;
    use HasUuids;

    protected static function newFactory(): UnitCategoryFactory
    {
        return UnitCategoryFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'base_unit_id',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(Unit::class, 'category_id');
    }

    public function activeUnits(): HasMany
    {
        return $this->units()->where('is_active', true);
    }
}

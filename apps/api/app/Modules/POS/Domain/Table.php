<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\POS\Domain\Enums\TableShape;
use App\Modules\POS\Domain\Enums\TableStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS Table Entity
 *
 * Represents a physical table in the venue. Tables track occupancy status
 * and link to the current active order. Floor planner fields (shape, position, dimensions)
 * are nullable and reserved for the future visual floor planner UI.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $floor_id
 * @property string $table_number
 * @property string|null $label
 * @property int $seats
 * @property TableStatus $status
 * @property TableShape|null $shape
 * @property string|null $position_x
 * @property string|null $position_y
 * @property string|null $width
 * @property string|null $height
 * @property string|null $current_order_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Floor|null $floor
 * @property-read Order|null $currentOrder
 */
class Table extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_tables';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'floor_id',
        'table_number',
        'label',
        'seats',
        'status',
        'shape',
        'position_x',
        'position_y',
        'width',
        'height',
        'current_order_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'status' => TableStatus::class,
            'shape' => TableShape::class,
            'position_x' => 'decimal:2',
            'position_y' => 'decimal:2',
            'width' => 'decimal:2',
            'height' => 'decimal:2',
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
     * @return BelongsTo<Floor, $this>
     */
    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class, 'floor_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function currentOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'current_order_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === TableStatus::Available;
    }

    public function isOccupied(): bool
    {
        return $this->status === TableStatus::Occupied;
    }
}

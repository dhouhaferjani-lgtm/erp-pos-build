<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\BatchExpiry\Domain\Entities\Batch;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $stock_transfer_line_id
 * @property string $tenant_id
 * @property string $company_id
 * @property int $batch_id
 * @property numeric-string $quantity
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StockTransferLine $line
 * @property-read Batch $batch
 * @property-read Tenant $tenant
 * @property-read Company $company
 */
class StockTransferLineBatchAllocation extends Model
{
    use HasUuids;

    protected $table = 'stock_transfer_line_batch_allocations';

    protected $fillable = [
        'stock_transfer_line_id',
        'tenant_id',
        'company_id',
        'batch_id',
        'quantity',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'batch_id' => 'integer',
            'quantity' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<StockTransferLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(StockTransferLine::class, 'stock_transfer_line_id');
    }

    /**
     * @return BelongsTo<Batch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
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
}

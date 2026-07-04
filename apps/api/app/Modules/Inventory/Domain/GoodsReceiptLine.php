<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Identity\Domain\User;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $goods_receipt_id
 * @property string $po_line_id
 * @property string $product_id
 * @property string|null $variant_id
 * @property numeric-string $received_qty
 * @property numeric-string $free_qty
 * @property numeric-string|null $received_unit_price
 * @property numeric-string $landed_unit_cost
 * @property numeric-string $accrual_unit_cost
 * @property numeric-string $effective_unit_cost
 * @property string|null $movement_id
 * @property string|null $free_movement_id
 * @property numeric-string $quantity_invoiced
 * @property string|null $price_override_by
 * @property Carbon|null $price_override_at
 * @property numeric-string|null $price_override_old_basis
 * @property string|null $price_override_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read GoodsReceipt $goodsReceipt
 * @property-read DocumentLine $poLine
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 * @property-read User|null $priceOverrideUser
 */
class GoodsReceiptLine extends Model
{
    use HasUuids;

    protected $table = 'goods_receipt_lines';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'goods_receipt_id',
        'po_line_id',
        'product_id',
        'variant_id',
        'received_qty',
        'free_qty',
        'received_unit_price',
        'landed_unit_cost',
        'accrual_unit_cost',
        'effective_unit_cost',
        'movement_id',
        'free_movement_id',
        'quantity_invoiced',
        'price_override_by',
        'price_override_at',
        'price_override_old_basis',
        'price_override_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_qty' => 'decimal:4',
            'free_qty' => 'decimal:4',
            'received_unit_price' => 'decimal:3',
            'landed_unit_cost' => 'decimal:6',
            'accrual_unit_cost' => 'decimal:6',
            'effective_unit_cost' => 'decimal:6',
            'quantity_invoiced' => 'decimal:4',
            'price_override_at' => 'datetime',
            'price_override_old_basis' => 'decimal:6',
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
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<DocumentLine, $this>
     */
    public function poLine(): BelongsTo
    {
        return $this->belongsTo(DocumentLine::class, 'po_line_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function priceOverrideUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_override_by');
    }
}

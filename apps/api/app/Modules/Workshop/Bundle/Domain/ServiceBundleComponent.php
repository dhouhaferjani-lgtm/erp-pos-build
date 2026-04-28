<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Domain;

use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Uom\Domain\Entities\Unit;
use App\Modules\Workshop\Bundle\Domain\Enums\BundleComponentType;
use Database\Factories\ServiceBundleComponentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $bundle_id
 * @property BundleComponentType $component_type
 * @property string|null $product_id
 * @property string|null $service_id
 * @property string|null $nested_bundle_id
 * @property string $quantity
 * @property string $unit_id
 * @property string|null $override_unit_price
 * @property bool $is_optional
 * @property int $display_order
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ServiceBundle $bundle
 * @property-read Product|null $product
 * @property-read Service|null $service
 * @property-read ServiceBundle|null $nestedBundle
 * @property-read Unit $unit
 */
class ServiceBundleComponent extends Model
{
    /** @use HasFactory<ServiceBundleComponentFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'workshop_service_bundle_components';

    protected static function newFactory(): ServiceBundleComponentFactory
    {
        return ServiceBundleComponentFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'bundle_id',
        'component_type',
        'product_id',
        'service_id',
        'nested_bundle_id',
        'quantity',
        'unit_id',
        'override_unit_price',
        'is_optional',
        'display_order',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_optional' => false,
        'display_order' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'component_type' => BundleComponentType::class,
            'quantity' => 'decimal:3',
            'override_unit_price' => 'decimal:3',
            'is_optional' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    // -- Relations --

    /**
     * @return BelongsTo<ServiceBundle, $this>
     */
    public function bundle(): BelongsTo
    {
        return $this->belongsTo(ServiceBundle::class, 'bundle_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id');
    }

    /**
     * @return BelongsTo<ServiceBundle, $this>
     */
    public function nestedBundle(): BelongsTo
    {
        return $this->belongsTo(ServiceBundle::class, 'nested_bundle_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}

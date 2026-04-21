<?php

declare(strict_types=1);

namespace App\Modules\Workshop\WorkOrder\Domain;

use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Service\Domain\Service;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Workshop\Bundle\Domain\ServiceBundle;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use App\Modules\Workshop\WorkOrder\Domain\Enums\CoreDepositStatus;
use App\Modules\Workshop\WorkOrder\Domain\Enums\WorkOrderLineType;
use Database\Factories\Workshop\WorkOrderLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Line-item of a WorkOrder. Supports 8 line types per Spec §5.1. Polymorphic
 * ref population rules are enforced by Postgres CHECK constraints.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $work_order_id
 * @property WorkOrderLineType $line_type
 * @property int $display_order
 * @property string|null $product_id
 * @property string|null $service_id
 * @property string|null $service_bundle_id
 * @property string $display_name
 * @property string|null $sku_or_code
 * @property string|null $description
 * @property string $quantity
 * @property string $unit
 * @property string $unit_price
 * @property string $tax_rate
 * @property string $discount_percent
 * @property string $line_total_excl_tax
 * @property string $line_total_tax
 * @property string $line_total_incl_tax
 * @property string|null $labor_hours_estimated
 * @property string|null $labor_hours_actual
 * @property string|null $assigned_technician_profile_id
 * @property string|null $stock_reservation_id
 * @property bool $is_customer_supplied
 * @property string|null $core_deposit_partner_id
 * @property CoreDepositStatus|null $core_deposit_status
 * @property string|null $core_return_of_line_id
 * @property string|null $from_bundle_id
 * @property bool $is_bundle_informational
 * @property bool $is_completed
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read WorkOrder $workOrder
 * @property-read Product|null $product
 * @property-read Service|null $service
 * @property-read ServiceBundle|null $serviceBundle
 * @property-read TechnicianProfile|null $assignedTechnician
 * @property-read Partner|null $coreDepositPartner
 * @property-read ServiceBundle|null $fromBundle
 */
class WorkOrderLine extends Model
{
    /** @use HasFactory<WorkOrderLineFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'workshop_work_order_lines';

    protected static function newFactory(): WorkOrderLineFactory
    {
        return WorkOrderLineFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'work_order_id',
        'line_type',
        'display_order',
        'product_id',
        'service_id',
        'service_bundle_id',
        'display_name',
        'sku_or_code',
        'description',
        'quantity',
        'unit',
        'unit_price',
        'tax_rate',
        'discount_percent',
        'line_total_excl_tax',
        'line_total_tax',
        'line_total_incl_tax',
        'labor_hours_estimated',
        'labor_hours_actual',
        'assigned_technician_profile_id',
        'stock_reservation_id',
        'is_customer_supplied',
        'core_deposit_partner_id',
        'core_deposit_status',
        'core_return_of_line_id',
        'from_bundle_id',
        'is_bundle_informational',
        'is_completed',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_type' => WorkOrderLineType::class,
            'core_deposit_status' => CoreDepositStatus::class,
            'display_order' => 'integer',
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
            'tax_rate' => 'decimal:3',
            'discount_percent' => 'decimal:2',
            'line_total_excl_tax' => 'decimal:3',
            'line_total_tax' => 'decimal:3',
            'line_total_incl_tax' => 'decimal:3',
            'labor_hours_estimated' => 'decimal:2',
            'labor_hours_actual' => 'decimal:2',
            'is_customer_supplied' => 'boolean',
            'is_bundle_informational' => 'boolean',
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    // -- Relations --

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<WorkOrder, $this> */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<ServiceBundle, $this> */
    public function serviceBundle(): BelongsTo
    {
        return $this->belongsTo(ServiceBundle::class);
    }

    /** @return BelongsTo<TechnicianProfile, $this> */
    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(TechnicianProfile::class, 'assigned_technician_profile_id');
    }

    /** @return BelongsTo<Partner, $this> */
    public function coreDepositPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'core_deposit_partner_id');
    }

    /** @return BelongsTo<ServiceBundle, $this> */
    public function fromBundle(): BelongsTo
    {
        return $this->belongsTo(ServiceBundle::class, 'from_bundle_id');
    }

    /** @return BelongsTo<WorkOrderLine, $this> */
    public function coreReturnOfLine(): BelongsTo
    {
        return $this->belongsTo(WorkOrderLine::class, 'core_return_of_line_id');
    }
}

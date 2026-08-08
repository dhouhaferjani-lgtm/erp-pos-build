<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Inventory\Domain\Enums\SupplierGoodsReturnNoteStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The document that justifies units going BACK to a supplier (DPA lane V8).
 *
 * Mirror of `GoodsReceipt`: goods_receipts is how stock arrives from a purchase
 * order, this is how it leaves back to the vendor. It carries BOTH kinds of
 * return line — paid (`ordinary`) and free (`bonus`) — which before V8 were split
 * across two behaviours of one supplier credit note (bonus raw-wrote stock;
 * ordinary moved no units at all).
 *
 * `supplier_credit_note_id` is a plain UUID, not a relation with an FK, following
 * `goods_receipts.purchase_order_id`. The credit note lives in the Document
 * module; resolving it is an application-layer lookup, which also keeps this
 * Domain model free of a cross-module model dependency (CLAUDE rule 6).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string|null $supplier_credit_note_id
 * @property string|null $partner_id
 * @property string|null $note_number
 * @property SupplierGoodsReturnNoteStatus $status
 * @property string|null $location_id
 * @property string|null $reference
 * @property Carbon|null $returned_at
 * @property string|null $created_by
 * @property string|null $confirmed_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Collection<int, SupplierGoodsReturnNoteLine> $lines
 */
class SupplierGoodsReturnNote extends Model
{
    use HasUuids;

    protected $table = 'supplier_goods_return_notes';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'supplier_credit_note_id',
        'partner_id',
        'note_number',
        'status',
        'location_id',
        'reference',
        'returned_at',
        'created_by',
        'confirmed_by',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierGoodsReturnNoteStatus::class,
            'returned_at' => 'datetime',
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
     * @return HasMany<SupplierGoodsReturnNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierGoodsReturnNoteLine::class, 'supplier_goods_return_note_id');
    }
}

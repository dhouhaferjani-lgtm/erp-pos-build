<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use App\Modules\Document\Domain\Document;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Treasury\Domain\Payment;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sales Withholding Tracking
 *
 * Tracks withholding tax applied by customers on our sales invoices.
 * Used when customer withholds tax and will provide us a certificate later.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $document_id
 * @property string|null $payment_id
 * @property string $customer_id
 * @property string $invoice_amount
 * @property string $withholding_rate
 * @property string $withholding_amount
 * @property string $expected_receivable
 * @property string|null $certificate_number
 * @property bool $certificate_received
 * @property \Carbon\Carbon|null $certificate_received_at
 * @property string|null $notes
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Document $document
 * @property-read Payment|null $payment
 * @property-read Partner $customer
 */
class SalesWithholdingTracking extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'sales_withholding_tracking';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'document_id',
        'payment_id',
        'customer_id',
        'invoice_amount',
        'withholding_rate',
        'withholding_amount',
        'expected_receivable',
        'certificate_number',
        'certificate_received',
        'certificate_received_at',
        'notes',
    ];

    protected $casts = [
        'certificate_received' => 'boolean',
        'certificate_received_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the document this tracking record is for.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get the payment (if recorded).
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the customer who withheld the tax.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'customer_id');
    }

    /**
     * Mark certificate as received.
     */
    public function markCertificateReceived(string $certificateNumber): void
    {
        $this->update([
            'certificate_number' => $certificateNumber,
            'certificate_received' => true,
            'certificate_received_at' => now(),
        ]);
    }

    /**
     * Check if certificate is pending.
     */
    public function isPending(): bool
    {
        return ! $this->certificate_received;
    }
}

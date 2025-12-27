<?php

declare(strict_types=1);

namespace App\Modules\Expense\Domain;

use App\Modules\Document\Domain\Document;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Expense metadata for storing expense-specific information.
 *
 * @property string $id
 * @property string $document_id
 * @property string|null $expense_category_id
 * @property string|null $payment_method_id
 * @property string|null $payment_repository_id
 * @property \Illuminate\Support\Carbon|null $payment_date
 * @property bool $is_paid
 * @property string|null $receipt_number
 * @property string|null $vendor_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Document $document
 * @property-read ExpenseCategory|null $category
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read PaymentRepository|null $paymentRepository
 */
class ExpenseMetadata extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'expense_metadata';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'expense_category_id',
        'payment_method_id',
        'payment_repository_id',
        'payment_date',
        'is_paid',
        'receipt_number',
        'vendor_name',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_paid' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'is_paid' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<PaymentRepository, $this>
     */
    public function paymentRepository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class);
    }
}

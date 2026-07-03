<?php

declare(strict_types=1);

namespace App\Modules\Income\Domain;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Document\Domain\Document;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Income metadata for storing income-specific information.
 *
 * The mirror of ExpenseMetadata for the income-recording flow. The "category"
 * of an income is a class-7 revenue GL account referenced directly by
 * income_account_id (there is no separate income_categories table).
 *
 * @property string $id
 * @property string $document_id
 * @property string|null $income_account_id
 * @property string|null $payment_method_id
 * @property string|null $payment_repository_id
 * @property Carbon|null $payment_date
 * @property bool $is_received
 * @property string|null $reference_number
 * @property string|null $source_name
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Document $document
 * @property-read Account|null $incomeAccount
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read PaymentRepository|null $paymentRepository
 */
class IncomeMetadata extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'income_metadata';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'income_account_id',
        'payment_method_id',
        'payment_repository_id',
        'payment_date',
        'is_received',
        'reference_number',
        'source_name',
        'idempotency_key',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_received' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'is_received' => 'boolean',
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
     * @return BelongsTo<Account, $this>
     */
    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
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

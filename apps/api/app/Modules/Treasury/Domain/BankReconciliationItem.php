<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Identity\Domain\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Individual item in a bank reconciliation.
 *
 * @property string $id
 * @property string $reconciliation_id
 * @property string $payment_id
 * @property bool $is_matched
 * @property string|null $bank_reference
 * @property string|null $notes
 * @property string|null $matched_by
 * @property Carbon|null $matched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BankReconciliation $reconciliation
 * @property-read Payment $payment
 * @property-read User|null $matcher
 */
class BankReconciliationItem extends Model
{
    use HasUuids;

    protected $table = 'bank_reconciliation_items';

    protected $fillable = [
        'reconciliation_id',
        'payment_id',
        'is_matched',
        'bank_reference',
        'notes',
        'matched_by',
        'matched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_matched' => 'boolean',
            'matched_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BankReconciliation, $this>
     */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function matcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Enums\PrintMethod;
use App\Modules\POS\Domain\Enums\ReceiptPrintType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS Receipt Print Audit Log
 *
 * Immutable record of every receipt print/reprint for NF525 compliance.
 * Every copy of a receipt must be tracked with a sequential copy number.
 *
 * @property string $id
 * @property string $receipt_id
 * @property string $terminal_id
 * @property string $user_id
 * @property ReceiptPrintType $print_type
 * @property int $copy_number
 * @property Carbon $printed_at
 * @property PrintMethod $print_method
 * @property Carbon $created_at
 * @property-read Receipt $receipt
 * @property-read Terminal $terminal
 * @property-read User $user
 */
class ReceiptPrint extends Model
{
    use HasUuids;

    /**
     * Immutable audit log — no updated_at column.
     */
    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'pos_receipt_prints';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'receipt_id',
        'terminal_id',
        'user_id',
        'print_type',
        'copy_number',
        'printed_at',
        'print_method',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'print_type' => ReceiptPrintType::class,
            'print_method' => PrintMethod::class,
            'printed_at' => 'datetime',
            'copy_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class);
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

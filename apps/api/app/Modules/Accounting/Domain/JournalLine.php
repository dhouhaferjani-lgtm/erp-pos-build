<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use App\Modules\Partner\Domain\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $journal_entry_id
 * @property string $account_id
 * @property string|null $partner_id
 * @property numeric-string $debit
 * @property numeric-string $credit
 * @property string|null $description
 * @property int $line_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read JournalEntry $journalEntry
 * @property-read Account $account
 * @property-read Partner|null $partner
 *
 * @method static Builder<static> forPartner(string $partnerId)
 * @method static Builder<static> withPartner()
 */
class JournalLine extends Model
{
    use HasUuids;

    protected $table = 'journal_lines';

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'partner_id',
        'debit',
        'credit',
        'description',
        'line_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:3',
            'credit' => 'decimal:3',
            'line_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * Scope to filter lines by partner (for subledger queries).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForPartner(Builder $query, string $partnerId): Builder
    {
        return $query->where('partner_id', $partnerId);
    }

    /**
     * Scope to filter lines that have a partner (subledger entries only).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithPartner(Builder $query): Builder
    {
        return $query->whereNotNull('partner_id');
    }
}

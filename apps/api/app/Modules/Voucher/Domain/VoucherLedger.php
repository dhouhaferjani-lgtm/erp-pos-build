<?php

declare(strict_types=1);

namespace App\Modules\Voucher\Domain;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Voucher\Domain\Enums\VoucherEvent;
use Database\Factories\VoucherLedgerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Voucher ledger — append-only audit ledger for all voucher events.
 *
 * The ledger is the source of truth for a voucher's balance and status.
 * Voucher.current_balance and Voucher.status are projections derived from
 * the cumulative ledger entries.
 *
 * This table is enforced append-only at the DB level via a PostgreSQL trigger.
 * UPDATE and DELETE operations will be rejected with integrity_constraint_violation.
 *
 * No updated_at column (append-only semantics; created_at only).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $voucher_id
 * @property VoucherEvent $event
 * @property numeric-string $amount Positive on issuance, negative on redemption
 * @property string $currency ISO 4217
 * @property string|null $receipt_id FK to pos_receipts
 * @property string|null $terminal_id
 * @property string $user_id
 * @property string|null $gl_journal_entry_id FK to journal_entries
 * @property string|null $authorized_by_user_id
 * @property string|null $policy_trigger
 * @property string|null $reverses_voucher_ledger_id FK to voucher_ledger (Reversed event)
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property-read Voucher $voucher
 * @property-read Receipt|null $receipt
 * @property-read Terminal|null $terminal
 * @property-read User $user
 * @property-read User|null $authorizedBy
 * @property-read JournalEntry|null $glJournalEntry
 * @property-read VoucherLedger|null $reversesLedger
 *
 * @method static VoucherLedgerFactory factory(int|null $count = null, array<string, mixed> $state = [])
 */
final class VoucherLedger extends Model
{
    /** @use HasFactory<VoucherLedgerFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'voucher_ledger';

    /**
     * Append-only: no updated_at column exists.
     */
    public const UPDATED_AT = null;

    protected static function newFactory(): VoucherLedgerFactory
    {
        return VoucherLedgerFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'voucher_id',
        'event',
        'amount',
        'currency',
        'receipt_id',
        'terminal_id',
        'user_id',
        'gl_journal_entry_id',
        'authorized_by_user_id',
        'policy_trigger',
        'reverses_voucher_ledger_id',
        'occurred_at',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'event' => VoucherEvent::class,
            'amount' => 'decimal:5',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    /**
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * The POS receipt that triggered this ledger event (sale or credit note).
     *
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'receipt_id');
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'terminal_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    /**
     * GL journal entry for this event (non-null for events that hit the general ledger).
     *
     * @return BelongsTo<JournalEntry, $this>
     */
    public function glJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'gl_journal_entry_id');
    }

    /**
     * For Reversed events: the ledger row being reversed.
     *
     * @return BelongsTo<VoucherLedger, $this>
     */
    public function reversesLedger(): BelongsTo
    {
        return $this->belongsTo(VoucherLedger::class, 'reverses_voucher_ledger_id');
    }
}

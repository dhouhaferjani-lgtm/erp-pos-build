<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\Enums\RemittanceStatus;
use App\Modules\Treasury\Domain\Enums\RemittanceType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $number
 * @property RemittanceType $remittance_type
 * @property InstrumentKind $instrument_kind
 * @property string $bank_repository_id
 * @property RemittanceStatus $status
 * @property Carbon|null $remitted_at
 * @property string|null $journal_entry_id
 * @property string|null $created_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PaymentRepository $bankRepository
 * @property-read JournalEntry|null $journalEntry
 * @property-read Collection<int, InstrumentRemittanceLine> $lines
 */
final class InstrumentRemittance extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'remittance_type' => RemittanceType::class,
        'instrument_kind' => InstrumentKind::class,
        'status' => RemittanceStatus::class,
        'remitted_at' => 'datetime',
    ];

    public static function allocateNumber(string $companyId): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
                ["remittance_number:{$companyId}"],
            );
        }

        $year = now()->format('Y');
        $lastNumber = self::query()
            ->where('company_id', $companyId)
            ->where('number', 'like', "REM-{$year}-%")
            ->orderByDesc('number')
            ->value('number');
        $next = is_string($lastNumber) ? ((int) substr($lastNumber, -4)) + 1 : 1;

        return sprintf('REM-%s-%04d', $year, $next);
    }

    /**
     * @return BelongsTo<PaymentRepository, $this>
     */
    public function bankRepository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class, 'bank_repository_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return HasMany<InstrumentRemittanceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InstrumentRemittanceLine::class, 'remittance_id');
    }
}

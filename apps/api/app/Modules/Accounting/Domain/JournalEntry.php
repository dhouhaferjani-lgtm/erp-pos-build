<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Exceptions\ImmutableJournalEntryException;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $entry_number
 * @property \Illuminate\Support\Carbon $entry_date
 * @property string|null $description
 * @property JournalEntryStatus $status
 * @property string|null $source_type
 * @property string|null $source_id
 * @property bool $is_historical
 * @property string|null $fiscal_hash
 * @property string|null $previous_hash
 * @property int|null $chain_sequence
 * @property string|null $posted_at
 * @property string|null $posted_by
 * @property string|null $reversed_at
 * @property string|null $reversed_by
 * @property string|null $reversal_entry_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, JournalLine> $lines
 */
class JournalEntry extends Model
{
    use HasUuids;

    protected $table = 'journal_entries';

    protected $fillable = [
        'tenant_id',
        'company_id',
        'entry_number',
        'entry_date',
        'description',
        'status',
        'source_type',
        'source_id',
        'is_historical',
        'fiscal_hash',
        'previous_hash',
        'chain_sequence',
        'posted_at',
        'posted_by',
        'reversed_at',
        'reversed_by',
        'reversal_entry_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'status' => JournalEntryStatus::class,
            'is_historical' => 'boolean',
            'chain_sequence' => 'integer',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
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
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * Scope to filter journal entries by company
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForCompany(\Illuminate\Database\Eloquent\Builder $query, string $companyId): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Check if journal entry is in a hash chain (fiscally sealed).
     *
     * A journal entry is considered chained when it has been assigned a fiscal hash,
     * indicating it has been posted and is now immutable for compliance.
     */
    public function isChained(): bool
    {
        return $this->fiscal_hash !== null;
    }

    /**
     * Get the next expected chain sequence for a company's GL chain.
     *
     * Each company maintains its own independent GL hash chain.
     * This method returns the next sequential number to be used when posting a new entry.
     */
    public static function getNextChainSequence(string $companyId): int
    {
        $maxSequence = self::where('company_id', $companyId)
            ->max('chain_sequence');

        return ($maxSequence ?? 0) + 1;
    }

    /**
     * Get the hash of the last entry in the company's GL chain.
     *
     * Returns null if no entries exist in the chain yet (first entry).
     */
    public static function getLastChainHash(string $companyId): ?string
    {
        /** @var self|null $lastEntry */
        $lastEntry = self::where('company_id', $companyId)
            ->whereNotNull('fiscal_hash')
            ->orderByDesc('chain_sequence')
            ->first();

        return $lastEntry?->fiscal_hash;
    }

    /**
     * Create a new Eloquent query builder for the model.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return JournalEntryBuilder
     */
    public function newEloquentBuilder($query): JournalEntryBuilder
    {
        return new JournalEntryBuilder($query);
    }
}

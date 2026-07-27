<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\BankStatementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $payment_repository_id
 * @property string $currency
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property numeric-string $opening_balance
 * @property numeric-string $closing_balance
 * @property BankStatementStatus $status
 * @property string $source_file_sha256
 * @property string $source_file_path
 * @property string|null $parser_profile_id
 * @property string|null $imported_by
 * @property CarbonImmutable $imported_at
 */
final class BankStatement extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'period_start' => 'immutable_date',
        'period_end' => 'immutable_date',
        'opening_balance' => 'decimal:3',
        'closing_balance' => 'decimal:3',
        'status' => BankStatementStatus::class,
        'imported_at' => 'immutable_datetime',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<PaymentRepository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class, 'payment_repository_id');
    }

    /** @return BelongsTo<StatementImportProfile, $this> */
    public function parserProfile(): BelongsTo
    {
        return $this->belongsTo(StatementImportProfile::class, 'parser_profile_id');
    }

    /** @return BelongsTo<User, $this> */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** @return HasMany<BankStatementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class);
    }
}

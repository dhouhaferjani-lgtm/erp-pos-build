<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\StatementDirectionConvention;
use App\Modules\Treasury\Domain\Enums\StatementParserKey;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $payment_repository_id
 * @property string $name
 * @property bool $is_active
 * @property StatementParserKey $parser_key
 * @property array<string, string|null> $column_map
 * @property string $date_format
 * @property string $decimal_format
 * @property StatementDirectionConvention $direction_convention
 * @property int $header_rows
 */
final class StatementImportProfile extends Model
{
    use HasUuids;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'parser_key' => StatementParserKey::class,
        'column_map' => 'array',
        'direction_convention' => StatementDirectionConvention::class,
        'header_rows' => 'integer',
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

    /** @return HasMany<BankStatement, $this> */
    public function statements(): HasMany
    {
        return $this->hasMany(BankStatement::class, 'parser_profile_id');
    }
}

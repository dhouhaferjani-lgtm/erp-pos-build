<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Infrastructure\Models;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

final class AdminTemplateAccount extends Model
{
    use CentralConnection;
    use HasUuids;

    protected $fillable = [
        'template_id',
        'code',
        'name',
        'type',
        'parent_code',
        'system_purpose',
        'is_system',
        'sort_order',
    ];

    protected static function booted(): void
    {
        $guard = static function (self $account): void {
            $template = AdminTemplate::query()->find($account->template_id);
            if ($template !== null && $template->status !== TemplateStatus::Draft) {
                throw new LogicException('Published and archived template account rows are immutable.');
            }
        };

        self::creating($guard);
        self::updating($guard);
        self::deleting($guard);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'system_purpose' => SystemAccountPurpose::class,
            'is_system' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<AdminTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(AdminTemplate::class, 'template_id');
    }
}

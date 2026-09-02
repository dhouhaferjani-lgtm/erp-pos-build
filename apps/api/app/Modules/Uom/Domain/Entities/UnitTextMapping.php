<?php

declare(strict_types=1);

namespace App\Modules\Uom\Domain\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $source_text
 * @property string $target_unit_id
 */
final class UnitTextMapping extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'source_text',
        'target_unit_id',
    ];
}

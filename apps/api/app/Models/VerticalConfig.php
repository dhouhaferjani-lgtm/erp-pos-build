<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Per-vertical module-list overrides stored in the central database.
 *
 * A null jsonb column means "no override — fall back to config/verticals.php
 * for that field" (per-field fallback, resolved by VerticalConfigService).
 *
 * @property string $vertical
 * @property array<int, string>|null $default_modules
 * @property array<int, string>|null $compatible_extras
 */
class VerticalConfig extends Model
{
    // Central table — always read/write the central connection,
    // even when the default connection is swapped to a tenant database.
    use CentralConnection;

    protected $primaryKey = 'vertical';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'vertical',
        'default_modules',
        'compatible_extras',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_modules' => 'array',
            'compatible_extras' => 'array',
        ];
    }
}

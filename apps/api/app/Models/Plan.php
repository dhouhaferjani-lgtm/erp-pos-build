<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

class Plan extends Model
{
    // T6 Phase 0b: central table — always read/write the central connection,
    // even when the default connection is swapped to a tenant database.
    use CentralConnection;
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'description',
        'limits',
        'price_monthly',
        'currency',
        'is_active',
        'display_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'price_monthly' => 'decimal:3',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}

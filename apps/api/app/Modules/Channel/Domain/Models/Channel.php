<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Models;

use App\Modules\Channel\Domain\Enums\ChannelConnectionStatus;
use App\Modules\Company\Domain\Company;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string $adapter_type
 * @property bool $is_active
 * @property ChannelConnectionStatus $connection_status
 * @property Carbon|null $last_successful_sync_at
 * @property string|null $last_error
 * @property array<string, mixed>|null $metadata
 */
final class Channel extends Model
{
    use HasUuids;

    protected $table = 'channels';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'adapter_type',
        'is_active',
        'connection_status',
        'last_successful_sync_at',
        'last_error',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'connection_status' => ChannelConnectionStatus::class,
            'last_successful_sync_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<ChannelCredential, $this>
     */
    public function credentials(): HasMany
    {
        return $this->hasMany(ChannelCredential::class);
    }

    /**
     * @return HasMany<ChannelProductMapping, $this>
     */
    public function productMappings(): HasMany
    {
        return $this->hasMany(ChannelProductMapping::class);
    }
}

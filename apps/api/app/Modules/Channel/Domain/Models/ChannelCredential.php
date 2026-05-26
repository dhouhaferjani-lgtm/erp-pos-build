<?php

declare(strict_types=1);

namespace App\Modules\Channel\Domain\Models;

use App\Modules\Channel\Domain\Enums\CredentialType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $channel_id
 * @property CredentialType $credential_type
 * @property array<string, mixed> $encrypted_payload
 * @property Carbon|null $expires_at
 */
final class ChannelCredential extends Model
{
    use HasUuids;

    protected $table = 'channel_credentials';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'credential_type',
        'encrypted_payload',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credential_type' => CredentialType::class,
            'encrypted_payload' => 'encrypted:array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Media\Domain\Media;

use App\Modules\Media\Domain\Enums\MediaOwnerType;
use App\Modules\Media\Domain\Enums\MediaRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MediaAttachment extends Model
{
    use HasUuids;

    protected $table = 'media_attachments';

    protected $fillable = [
        'tenant_id',
        'media_asset_id',
        'owner_type',
        'owner_id',
        'role',
        'sort_order',
        'channel',
        'locale',
        'alt',
        'caption',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_type' => MediaOwnerType::class,
            'role' => MediaRole::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<MediaAsset, $this>
     */
    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }
}

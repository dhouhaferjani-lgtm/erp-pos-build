<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Media;

use App\Modules\Catalog\Domain\Enums\MediaAssetType;
use App\Modules\Catalog\Domain\Enums\MediaSource;
use App\Modules\Catalog\Domain\Enums\MediaStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class MediaAsset extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $table = 'media_assets';

    protected $fillable = [
        'tenant_id', 'type', 'source', 'status', 'storage_disk', 'storage_path',
        'external_url', 'original_filename', 'mime_type', 'file_size', 'checksum',
        'width', 'height', 'duration_ms', 'frame_count', 'title', 'uploaded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MediaAssetType::class,
            'source' => MediaSource::class,
            'status' => MediaStatus::class,
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration_ms' => 'integer',
            'frame_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<MediaRendition, $this>
     */
    public function renditions(): HasMany
    {
        return $this->hasMany(MediaRendition::class, 'media_asset_id');
    }

    /**
     * @return HasMany<MediaAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class, 'media_asset_id');
    }
}

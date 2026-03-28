<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Enums;

enum EnrichmentStatus: string
{
    case Pending = 'pending';
    case Enriching = 'enriching';
    case Completed = 'completed';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case NotEnrichable = 'not_enrichable';

    /**
     * Map a platform-side status string to the local EnrichmentStatus.
     */
    public static function fromPlatformStatus(string $platformStatus): self
    {
        return match ($platformStatus) {
            'submitted' => self::Pending,
            'enriching' => self::Enriching,
            'enriched', 'approved' => self::Completed,
            'rejected' => self::Rejected,
            'failed' => self::Failed,
            'not_enrichable' => self::NotEnrichable,
            default => throw new \ValueError("Unknown platform enrichment status: {$platformStatus}"),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Enriching => 'Enriching',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Rejected => 'Rejected',
            self::NotEnrichable => 'Not Enrichable',
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain;

use Database\Factories\DocumentVehicleContextFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linking table for automotive context on documents.
 *
 * This allows AutoERP to remain universal while supporting
 * industry-specific data (vehicles, mileage, etc.) when needed.
 *
 * After P0 remediation: vehicle_id is a soft reference (no FK).
 * Vehicle data is snapshotted at time of service for immutability.
 *
 * @property string $id
 * @property string $document_id
 * @property string $vehicle_id
 * @property array<string, mixed>|null $vehicle_snapshot
 * @property int|null $mileage_at_service
 * @property array<string, mixed>|null $context_data
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Document $document
 */
class DocumentVehicleContext extends Model
{
    /** @use HasFactory<DocumentVehicleContextFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'document_vehicle_contexts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_id',
        'vehicle_id',
        'vehicle_snapshot',
        'mileage_at_service',
        'context_data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vehicle_snapshot' => 'array',
            'mileage_at_service' => 'integer',
            'context_data' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Get a context data value with fallback
     */
    public function getContextValue(string $key, mixed $default = null): mixed
    {
        return $this->context_data[$key] ?? $default;
    }

    /**
     * Get the vehicle snapshot data
     *
     * @return array<string, mixed>|null
     */
    public function getVehicleSnapshot(): ?array
    {
        return $this->vehicle_snapshot;
    }

    /**
     * Get a human-readable vehicle display string
     */
    public function getVehicleDisplayString(): string
    {
        if ($this->vehicle_snapshot === null) {
            return 'Unknown Vehicle';
        }

        $parts = array_filter([
            $this->vehicle_snapshot['brand'] ?? null,
            $this->vehicle_snapshot['model'] ?? null,
            $this->vehicle_snapshot['license_plate'] ?? null,
        ]);

        return implode(' - ', $parts) ?: 'Unknown Vehicle';
    }

    /**
     * Get the mileage at time of service
     */
    public function getMileageAtService(): ?int
    {
        return $this->mileage_at_service;
    }

    /**
     * Get the vehicle ID (soft reference)
     */
    public function getVehicleId(): string
    {
        return $this->vehicle_id;
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): DocumentVehicleContextFactory
    {
        return DocumentVehicleContextFactory::new();
    }
}

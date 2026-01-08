<?php

declare(strict_types=1);

namespace App\Modules\BatchExpiry\Domain\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BatchStock extends Model
{
    protected $table = 'inventory_batch_stock';

    protected $fillable = [
        'tenant_id',
        'batch_id',
        'location_id',
        'quantity',
        'reserved_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'available_quantity' => 'decimal:4',
    ];

    protected $appends = [
        'available_quantity',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Company\Domain\Location::class);
    }

    public function getAvailableQuantityAttribute(): float
    {
        return $this->quantity - $this->reserved_quantity;
    }

    public function hasAvailableStock(): bool
    {
        return $this->getAvailableQuantityAttribute() > 0;
    }

    public function reserve(float $quantity): void
    {
        if ($quantity > $this->getAvailableQuantityAttribute()) {
            throw new \DomainException('Insufficient available quantity to reserve');
        }

        $this->increment('reserved_quantity', $quantity);
    }

    public function releaseReservation(float $quantity): void
    {
        if ($quantity > $this->reserved_quantity) {
            throw new \DomainException('Cannot release more than reserved quantity');
        }

        $this->decrement('reserved_quantity', $quantity);
    }

    public function adjustQuantity(float $delta): void
    {
        $newQuantity = $this->quantity + $delta;

        if ($newQuantity < 0) {
            throw new \DomainException('Quantity cannot be negative');
        }

        if ($newQuantity < $this->reserved_quantity) {
            throw new \DomainException('Quantity cannot be less than reserved quantity');
        }

        $this->update(['quantity' => $newQuantity]);
    }
}

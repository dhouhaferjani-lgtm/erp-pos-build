<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\DTOs\BrandResolution;
use App\Modules\Product\Application\Jobs\SendBrandMappingJob;
use App\Modules\Product\Domain\Brand;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Resolves a platform brand payload to a local brand:
 * external id -> canonical id -> slug -> create.
 */
final class BrandResolutionService
{
    public function resolve(string $tenantId, string $name, ?string $canonicalBrandId, ?string $externalBrandId): BrandResolution
    {
        $canonicalBrandId = ($canonicalBrandId !== null && Str::isUuid($canonicalBrandId)) ? $canonicalBrandId : null;

        if ($externalBrandId !== null && Str::isUuid($externalBrandId)) {
            $brand = Brand::query()
                ->where('tenant_id', $tenantId)
                ->find($externalBrandId);

            if ($brand !== null) {
                $this->fillCanonicalIfSafe($brand, $canonicalBrandId, $tenantId);

                return new BrandResolution($brand, false);
            }
        }

        if ($canonicalBrandId !== null) {
            $brand = Brand::query()
                ->where('tenant_id', $tenantId)
                ->where('canonical_brand_id', $canonicalBrandId)
                ->first();

            if ($brand !== null) {
                return new BrandResolution($brand, false);
            }
        }

        $slug = Brand::slugFor($name);
        $brand = Brand::query()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->first();

        if ($brand !== null) {
            if ($canonicalBrandId === null) {
                return new BrandResolution($brand, false);
            }

            if ($brand->canonical_brand_id === $canonicalBrandId) {
                return new BrandResolution($brand, false);
            }

            if ($brand->canonical_brand_id === null) {
                $filled = $this->fillCanonicalIfSafe($brand, $canonicalBrandId, $tenantId);

                return new BrandResolution($brand, $filled);
            }

            Log::warning('Brand canonical id conflict: keeping local value', [
                'brand_id' => $brand->id,
                'local_canonical_brand_id' => $brand->canonical_brand_id,
                'payload_canonical_brand_id' => $canonicalBrandId,
            ]);

            return new BrandResolution($brand, false);
        }

        $storeCanonical = $canonicalBrandId !== null && ! $this->canonicalTaken($tenantId, $canonicalBrandId);

        if ($canonicalBrandId !== null && ! $storeCanonical) {
            Log::warning('Brand canonical id already held by another local brand: create without canonical', [
                'payload_canonical_brand_id' => $canonicalBrandId,
                'tenant_id' => $tenantId,
            ]);
        }

        $brand = Brand::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'slug' => $slug,
            'canonical_brand_id' => $storeCanonical ? $canonicalBrandId : null,
            'is_active' => true,
        ]);

        return new BrandResolution($brand, $storeCanonical);
    }

    public function dispatchPushIfNeeded(BrandResolution $resolution, string $companyId): void
    {
        if (! $resolution->shouldPushMapping || $resolution->brand->canonical_brand_id === null) {
            return;
        }

        try {
            SendBrandMappingJob::dispatch(
                canonicalBrandId: $resolution->brand->canonical_brand_id,
                externalBrandId: $resolution->brand->id,
                companyId: $companyId,
            )->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('Brand mapping push dispatch failed', [
                'canonical_brand_id' => $resolution->brand->canonical_brand_id,
                'brand_id' => $resolution->brand->id,
                'company_id' => $companyId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function fillCanonicalIfSafe(Brand $brand, ?string $canonicalBrandId, string $tenantId): bool
    {
        if ($canonicalBrandId === null) {
            return false;
        }

        if ($brand->canonical_brand_id !== null) {
            if ($brand->canonical_brand_id !== $canonicalBrandId) {
                Log::warning('Brand canonical id conflict: keeping local value', [
                    'brand_id' => $brand->id,
                    'local_canonical_brand_id' => $brand->canonical_brand_id,
                    'payload_canonical_brand_id' => $canonicalBrandId,
                ]);
            }

            return false;
        }

        if ($this->canonicalTaken($tenantId, $canonicalBrandId)) {
            Log::warning('Brand canonical id already held by another local brand: fill skipped', [
                'brand_id' => $brand->id,
                'payload_canonical_brand_id' => $canonicalBrandId,
            ]);

            return false;
        }

        $brand->update(['canonical_brand_id' => $canonicalBrandId]);

        return true;
    }

    private function canonicalTaken(string $tenantId, string $canonicalBrandId): bool
    {
        return Brand::query()
            ->where('tenant_id', $tenantId)
            ->where('canonical_brand_id', $canonicalBrandId)
            ->exists();
    }
}

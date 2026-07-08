<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Support;

/**
 * Normalizes an untrusted `images[]` payload into a clean, capped list of
 * image descriptors, shared by both enrichment paths (spec M-05).
 *
 * Mirrors the ORIGINAL EnrichmentReviewService::imagesFromPayload() logic
 * exactly (non-array items dropped, non-string url/thumbnail/type coerced to
 * null, array items always kept -- even when both url and thumbnail resolve
 * to null) and adds only ONE new behavior: truncation at `$cap`. Filtering
 * out descriptors with nothing fetchable is the persister's job, not this
 * normalizer's -- it must stay a faithful, display-safe superset.
 */
final class ImageDescriptorNormalizer
{
    /**
     * @return list<array{url: string|null, thumbnail: string|null, type: string|null}>
     */
    public static function normalize(mixed $images, int $cap = 6): array
    {
        if (! is_array($images)) {
            return [];
        }

        $out = [];
        foreach ($images as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = $item['url'] ?? null;
            $thumbnail = $item['thumbnail'] ?? null;
            $type = $item['type'] ?? null;

            $out[] = [
                'url' => is_string($url) ? $url : null,
                'thumbnail' => is_string($thumbnail) ? $thumbnail : null,
                'type' => is_string($type) ? $type : null,
            ];

            if (count($out) >= $cap) {
                break;
            }
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Product;

use App\Modules\Product\Application\Support\ImageDescriptorNormalizer;
use Tests\TestCase;

final class ImageDescriptorNormalizerTest extends TestCase
{
    /**
     * Mirrors the ORIGINAL EnrichmentReviewService::imagesFromPayload() behavior:
     * non-array items are dropped, but array items are always kept (coercing
     * non-string url/thumbnail/type to null) -- even when both url AND
     * thumbnail end up null. The normalizer must not introduce a "drop if
     * nothing fetchable" filter; that filtering belongs to the persister
     * (Task 7), which picks `url ?? thumbnail` and skips empties downstream.
     */
    public function test_drops_only_non_array_items_and_caps(): void
    {
        $raw = [
            ['url' => 'https://a.tn/1.jpg', 'thumbnail' => 'https://a.tn/1t.jpg', 'type' => 'featured'],
            ['url' => 123],                 // non-string url -> url null; kept (array item)
            'not-an-array',                 // dropped entirely (not an array)
            ['thumbnail' => 'https://a.tn/only-thumb.jpg'], // url missing -> null, kept (has thumb)
            ['url' => 'https://a.tn/2.jpg'],
            ['url' => 'https://a.tn/3.jpg'],
            ['url' => 'https://a.tn/4.jpg'],
            ['url' => 'https://a.tn/5.jpg'],
            ['url' => 'https://a.tn/6.jpg'],
            ['url' => 'https://a.tn/7.jpg'], // over cap
        ];

        $out = ImageDescriptorNormalizer::normalize($raw, 6);

        $this->assertCount(6, $out);
        $this->assertSame('https://a.tn/1.jpg', $out[0]['url']);
        $this->assertNull($out[1]['url']);
        $this->assertNull($out[1]['thumbnail']);
        $this->assertArrayHasKey('type', $out[0]);
        $this->assertSame([
            'url' => null,
            'thumbnail' => 'https://a.tn/only-thumb.jpg',
            'type' => null,
        ], $out[2]);
        $this->assertSame('https://a.tn/2.jpg', $out[3]['url']);
        $this->assertSame('https://a.tn/4.jpg', $out[5]['url']);
    }

    public function test_keeps_item_with_both_url_and_thumbnail_null(): void
    {
        // Original imagesFromPayload() never filters on "nothing fetchable" --
        // it keeps every array item regardless of whether url/thumbnail
        // resolved to a usable string. The normalizer must faithfully
        // reproduce that when called with an unbounded cap.
        $out = ImageDescriptorNormalizer::normalize([
            ['url' => null, 'thumbnail' => null, 'type' => 'featured'],
        ], PHP_INT_MAX);

        $this->assertCount(1, $out);
        $this->assertSame([
            'url' => null,
            'thumbnail' => null,
            'type' => 'featured',
        ], $out[0]);
    }

    public function test_non_array_input_returns_empty(): void
    {
        $this->assertSame([], ImageDescriptorNormalizer::normalize('nope'));
        $this->assertSame([], ImageDescriptorNormalizer::normalize(null));
    }

    public function test_default_cap_is_six(): void
    {
        $raw = array_map(
            static fn (int $i): array => ['url' => "https://a.tn/{$i}.jpg"],
            range(1, 10),
        );

        $out = ImageDescriptorNormalizer::normalize($raw);

        $this->assertCount(6, $out);
        $this->assertSame('https://a.tn/6.jpg', $out[5]['url']);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Contracts;

interface RenditionGeneratorInterface
{
    /**
     * Encode the given image bytes as WebP, resizing to at most $maxWidth pixels wide.
     * Images narrower than $maxWidth are NOT upscaled.
     *
     * @param  string  $bytes  Raw image bytes (any format GD can decode: JPEG, PNG, GIF, …)
     * @param  int  $maxWidth  Maximum output width in pixels.
     * @return string WebP-encoded bytes.
     */
    public function generate(string $bytes, int $maxWidth): string;
}

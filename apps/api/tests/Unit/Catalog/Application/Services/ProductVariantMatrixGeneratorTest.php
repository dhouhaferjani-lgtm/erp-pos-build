<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog\Application\Services;

use App\Modules\Catalog\Application\Services\ProductVariantMatrixGenerator;
use Tests\TestCase;

class ProductVariantMatrixGeneratorTest extends TestCase
{
    private ProductVariantMatrixGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ProductVariantMatrixGenerator;
    }

    public function test_two_axis_matrix_generates_cartesian(): void
    {
        $axes = [
            'taille' => ['39', '40', '41'],
            'couleur' => ['noir', 'blanc'],
        ];

        $result = $this->generator->cartesian($axes);

        $this->assertCount(6, $result);
        $this->assertContains(['taille' => '39', 'couleur' => 'noir'], $result);
        $this->assertContains(['taille' => '39', 'couleur' => 'blanc'], $result);
        $this->assertContains(['taille' => '40', 'couleur' => 'noir'], $result);
        $this->assertContains(['taille' => '40', 'couleur' => 'blanc'], $result);
        $this->assertContains(['taille' => '41', 'couleur' => 'noir'], $result);
        $this->assertContains(['taille' => '41', 'couleur' => 'blanc'], $result);
    }

    public function test_excluded_combos_skipped(): void
    {
        $axes = [
            'taille' => ['S', 'M'],
            'couleur' => ['rouge', 'vert'],
        ];

        $excluded = [
            ['taille' => 'S', 'couleur' => 'vert'],
        ];

        $result = $this->generator->cartesian($axes, $excluded);

        $this->assertCount(3, $result);
        $this->assertNotContains(['taille' => 'S', 'couleur' => 'vert'], $result);
        $this->assertContains(['taille' => 'S', 'couleur' => 'rouge'], $result);
        $this->assertContains(['taille' => 'M', 'couleur' => 'rouge'], $result);
        $this->assertContains(['taille' => 'M', 'couleur' => 'vert'], $result);
    }

    public function test_empty_axes_returns_empty(): void
    {
        $result = $this->generator->cartesian([]);

        $this->assertSame([], $result);
    }
}

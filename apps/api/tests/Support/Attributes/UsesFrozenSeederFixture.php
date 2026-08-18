<?php

declare(strict_types=1);

namespace Tests\Support\Attributes;

use Attribute;

/**
 * Semantic marker for live tests that use a frozen seeder only as fixture data.
 *
 * Unlike a PHPUnit group, this attribute cannot be excluded from normal suites.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class UsesFrozenSeederFixture {}

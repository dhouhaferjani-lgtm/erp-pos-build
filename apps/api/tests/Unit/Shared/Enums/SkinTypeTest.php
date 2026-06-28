<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Enums;

use App\Shared\Domain\Enums\SkinType;
use Tests\TestCase;

final class SkinTypeTest extends TestCase
{
    public function test_has_the_five_canonical_values(): void
    {
        $values = array_map(fn ($c) => $c->value, SkinType::cases());

        $this->assertSame(['normal', 'oily', 'dry', 'combination', 'sensitive'], $values);
    }

    public function test_label_returns_french_string_for_each_case(): void
    {
        $this->assertSame('Peau normale', SkinType::Normal->label());
        $this->assertSame('Peau grasse', SkinType::Oily->label());
        $this->assertSame('Peau sèche', SkinType::Dry->label());
        $this->assertSame('Peau mixte', SkinType::Combination->label());
        $this->assertSame('Peau sensible', SkinType::Sensitive->label());
    }

    public function test_is_string_backed_enum(): void
    {
        $reflection = new \ReflectionEnum(SkinType::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', $reflection->getBackingType()->getName());
    }

    public function test_from_returns_correct_case(): void
    {
        $this->assertSame(SkinType::Dry, SkinType::from('dry'));
        $this->assertSame(SkinType::Sensitive, SkinType::from('sensitive'));
    }

    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(SkinType::tryFrom('zzz'));
    }
}

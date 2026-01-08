<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs;

use App\DTOs\CompanyConfig;
use App\Enums\Vertical;
use Tests\TestCase;

class CompanyConfigTest extends TestCase
{
    public function test_creates_company_config_from_array(): void
    {
        $data = [
            'vertical' => Vertical::Mechanic,
            'default_modules' => ['Identity', 'Tenant', 'Catalog', 'Vehicle'],
            'enabled_extras' => ['Appointments', 'Fleet'],
            'all_enabled_modules' => ['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Appointments', 'Fleet'],
        ];

        $config = CompanyConfig::fromArray($data);

        $this->assertInstanceOf(CompanyConfig::class, $config);
        $this->assertEquals(Vertical::Mechanic, $config->vertical);
        $this->assertEquals(['Identity', 'Tenant', 'Catalog', 'Vehicle'], $config->defaultModules);
        $this->assertEquals(['Appointments', 'Fleet'], $config->enabledExtras);
        $this->assertEquals(['Identity', 'Tenant', 'Catalog', 'Vehicle', 'Appointments', 'Fleet'], $config->allEnabledModules);
    }

    public function test_has_module_returns_true_when_module_enabled(): void
    {
        $config = CompanyConfig::fromArray([
            'vertical' => Vertical::Mechanic,
            'default_modules' => ['Identity', 'Tenant', 'Vehicle'],
            'enabled_extras' => ['Appointments'],
            'all_enabled_modules' => ['Identity', 'Tenant', 'Vehicle', 'Appointments'],
        ]);

        $this->assertTrue($config->hasModule('Vehicle'));
        $this->assertTrue($config->hasModule('Appointments'));
    }

    public function test_has_module_returns_false_when_module_not_enabled(): void
    {
        $config = CompanyConfig::fromArray([
            'vertical' => Vertical::Mechanic,
            'default_modules' => ['Identity', 'Tenant', 'Vehicle'],
            'enabled_extras' => [],
            'all_enabled_modules' => ['Identity', 'Tenant', 'Vehicle'],
        ]);

        $this->assertFalse($config->hasModule('Menu'));
        $this->assertFalse($config->hasModule('BatchExpiry'));
    }

    public function test_to_array_returns_array_representation(): void
    {
        $config = CompanyConfig::fromArray([
            'vertical' => Vertical::Restaurant,
            'default_modules' => ['Identity', 'Tenant', 'Menu'],
            'enabled_extras' => ['Tables'],
            'all_enabled_modules' => ['Identity', 'Tenant', 'Menu', 'Tables'],
        ]);

        $array = $config->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('restaurant', $array['vertical']);
        $this->assertEquals(['Identity', 'Tenant', 'Menu'], $array['default_modules']);
        $this->assertEquals(['Tables'], $array['enabled_extras']);
        $this->assertEquals(['Identity', 'Tenant', 'Menu', 'Tables'], $array['all_enabled_modules']);
    }

    public function test_can_be_json_serialized(): void
    {
        $config = CompanyConfig::fromArray([
            'vertical' => Vertical::Pharmacy,
            'default_modules' => ['Identity', 'BatchExpiry'],
            'enabled_extras' => [],
            'all_enabled_modules' => ['Identity', 'BatchExpiry'],
        ]);

        $json = json_encode($config);

        $this->assertIsString($json);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $this->assertEquals('pharmacy', $decoded['vertical']);
    }
}

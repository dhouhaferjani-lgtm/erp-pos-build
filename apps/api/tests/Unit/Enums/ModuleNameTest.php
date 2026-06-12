<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\ModuleName;
use Tests\TestCase;

class ModuleNameTest extends TestCase
{
    /**
     * Collect every module name appearing in config('verticals') across
     * all default_modules and compatible_extras arrays.
     *
     * @return array<int, string>
     */
    private function moduleNamesFromConfig(): array
    {
        $config = config('verticals');
        $this->assertIsArray($config);

        $names = [];

        foreach ($config as $verticalKey => $verticalConfig) {
            $this->assertIsArray($verticalConfig, "Vertical {$verticalKey} config should be an array");

            foreach (['default_modules', 'compatible_extras'] as $listKey) {
                $list = $verticalConfig[$listKey] ?? [];
                $this->assertIsArray($list, "Vertical {$verticalKey} {$listKey} should be an array");

                foreach ($list as $name) {
                    $this->assertIsString($name, "Vertical {$verticalKey} {$listKey} entries should be strings");
                    $names[] = $name;
                }
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    public function test_every_module_name_in_verticals_config_is_a_valid_module_name_case(): void
    {
        foreach ($this->moduleNamesFromConfig() as $name) {
            $this->assertNotNull(
                ModuleName::tryFrom($name),
                "Module name '{$name}' from config/verticals.php has no ModuleName enum case — add it to App\\Enums\\ModuleName"
            );
        }
    }

    public function test_enum_value_set_equals_union_of_names_in_verticals_config(): void
    {
        $configNames = $this->moduleNamesFromConfig();

        $enumValues = ModuleName::values();
        sort($enumValues);

        $this->assertSame(
            $configNames,
            $enumValues,
            'ModuleName enum cases must exactly match the union of module names in config/verticals.php (no stale cases, no missing cases)'
        );
    }

    public function test_values_returns_backing_strings(): void
    {
        $values = ModuleName::values();

        $this->assertCount(count(ModuleName::cases()), $values);
        $this->assertContains('Identity', $values);
        $this->assertContains('Ecommerce', $values);

        foreach ($values as $value) {
            $this->assertIsString($value);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Architecture;

use App\Shared\Architecture\CrossTenantRoute;
use ReflectionClass;
use Tests\TestCase;

class CrossTenantRouteTest extends TestCase
{
    public function test_attribute_targets_methods_only(): void
    {
        $reflection = new ReflectionClass(CrossTenantRoute::class);
        $attributes = $reflection->getAttributes();

        $this->assertNotEmpty($attributes, 'CrossTenantRoute must declare an Attribute target.');

        $attributeMeta = $attributes[0];
        $this->assertSame(\Attribute::class, $attributeMeta->getName());

        $args = $attributeMeta->getArguments();
        $this->assertSame(\Attribute::TARGET_METHOD, $args[0] ?? null,
            'CrossTenantRoute must target methods only — never classes, properties, or parameters.');
    }

    public function test_attribute_records_required_reason(): void
    {
        $attribute = new CrossTenantRoute(reason: 'Super-admin manages all tenants from one panel');

        $this->assertSame(
            'Super-admin manages all tenants from one panel',
            $attribute->reason,
        );
    }

    public function test_attribute_can_be_read_via_reflection_on_target_method(): void
    {
        $controller = new class
        {
            #[CrossTenantRoute(reason: 'Test fixture: cross-tenant by design')]
            public function legitimatelyCrossTenant(): void {}

            public function regularTenantScopedAction(): void {}
        };

        $reflection = new ReflectionClass($controller);

        $crossTenantMethod = $reflection->getMethod('legitimatelyCrossTenant');
        $attributes = $crossTenantMethod->getAttributes(CrossTenantRoute::class);
        $this->assertCount(1, $attributes);

        $instance = $attributes[0]->newInstance();
        $this->assertInstanceOf(CrossTenantRoute::class, $instance);
        $this->assertSame('Test fixture: cross-tenant by design', $instance->reason);

        $regularMethod = $reflection->getMethod('regularTenantScopedAction');
        $this->assertEmpty(
            $regularMethod->getAttributes(CrossTenantRoute::class),
            'Regular controller methods must not be picked up as cross-tenant.',
        );
    }

    public function test_reason_is_a_readonly_property(): void
    {
        $reflection = new ReflectionClass(CrossTenantRoute::class);
        $reasonProperty = $reflection->getProperty('reason');

        $this->assertTrue(
            $reasonProperty->isReadOnly(),
            'CrossTenantRoute::$reason must be readonly so the audit trail cannot be mutated post-construction.',
        );
        $this->assertTrue($reasonProperty->isPublic(),
            'CrossTenantRoute::$reason must be public so reflection-based scanners can read it.');
    }

    public function test_constructor_rejects_blank_reason(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('reason must not be blank');

        new CrossTenantRoute(reason: '   ');
    }
}

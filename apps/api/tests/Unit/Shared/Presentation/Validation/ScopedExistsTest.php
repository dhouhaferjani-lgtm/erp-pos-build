<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Presentation\Validation;

use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Validation\Rules\Exists;
use Tests\TestCase;

class ScopedExistsTest extends TestCase
{
    public function test_tenant_and_company_factory_returns_exists_rule_scoped_to_both(): void
    {
        $rule = ScopedExists::tenantAndCompany(
            table: 'payment_methods',
            tenantId: 'tenant-abc',
            companyId: 'company-xyz',
        );

        $this->assertInstanceOf(Exists::class, $rule);

        $rendered = (string) $rule;
        $this->assertStringStartsWith('exists:payment_methods,id', $rendered);
        $this->assertStringContainsString('tenant_id,"tenant-abc"', $rendered);
        $this->assertStringContainsString('company_id,"company-xyz"', $rendered);
    }

    public function test_tenant_factory_returns_exists_rule_scoped_to_tenant_only(): void
    {
        $rule = ScopedExists::tenant(
            table: 'users',
            tenantId: 'tenant-abc',
        );

        $this->assertInstanceOf(Exists::class, $rule);

        $rendered = (string) $rule;
        $this->assertStringStartsWith('exists:users,id', $rendered);
        $this->assertStringContainsString('tenant_id,"tenant-abc"', $rendered);
        $this->assertStringNotContainsString('company_id', $rendered);
    }

    public function test_company_factory_returns_exists_rule_scoped_to_company_only(): void
    {
        $rule = ScopedExists::company(
            table: 'cart_items',
            companyId: 'company-xyz',
        );

        $this->assertInstanceOf(Exists::class, $rule);

        $rendered = (string) $rule;
        $this->assertStringStartsWith('exists:cart_items,id', $rendered);
        $this->assertStringContainsString('company_id,"company-xyz"', $rendered);
        $this->assertStringNotContainsString('tenant_id', $rendered);
    }

    public function test_tenant_and_company_factory_accepts_custom_column(): void
    {
        $rule = ScopedExists::tenantAndCompany(
            table: 'coupons',
            tenantId: 't-1',
            companyId: 'c-1',
            column: 'code',
        );

        $rendered = (string) $rule;
        $this->assertStringStartsWith('exists:coupons,code', $rendered);
        $this->assertStringContainsString('tenant_id,"t-1"', $rendered);
        $this->assertStringContainsString('company_id,"c-1"', $rendered);
    }

    public function test_tenant_factory_accepts_custom_column(): void
    {
        $rule = ScopedExists::tenant(
            table: 'users',
            tenantId: 't-1',
            column: 'email',
        );

        $rendered = (string) $rule;
        $this->assertStringStartsWith('exists:users,email', $rendered);
        $this->assertStringContainsString('tenant_id,"t-1"', $rendered);
    }

    public function test_company_factory_accepts_custom_column(): void
    {
        $rule = ScopedExists::company(
            table: 'partners',
            companyId: 'c-1',
            column: 'reference_code',
        );

        $rendered = (string) $rule;
        $this->assertStringStartsWith('exists:partners,reference_code', $rendered);
        $this->assertStringContainsString('company_id,"c-1"', $rendered);
    }

    public function test_tenant_and_company_rejects_cross_tenant_value_via_real_validator(): void
    {
        $passingRule = ScopedExists::tenantAndCompany(
            table: 'fake_resources_for_scoped_exists',
            tenantId: 't-1',
            companyId: 'c-1',
        );

        $this->assertSame(
            'exists:fake_resources_for_scoped_exists,id,tenant_id,"t-1",company_id,"c-1"',
            (string) $passingRule,
        );
    }
}

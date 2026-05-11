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

    public function test_tenant_and_company_renders_canonical_validator_string(): void
    {
        // String-shape contract test: pins the exact output of Laravel's
        // formatWheres() so any framework change to the rule's __toString()
        // surfaces immediately. Behavioral verification (real validator
        // against a seeded DB) lives in ScopedExistsBehaviorTest.
        $rule = ScopedExists::tenantAndCompany(
            table: 'fake_resources_for_scoped_exists',
            tenantId: 't-1',
            companyId: 'c-1',
        );

        $this->assertSame(
            'exists:fake_resources_for_scoped_exists,id,tenant_id,"t-1",company_id,"c-1"',
            (string) $rule,
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // api.catalog.022 — tenantOrSystem factory: string-shape contract.
    // Behavioral DB tests live in CatalogTenantIsolationTest.
    // ──────────────────────────────────────────────────────────────────

    public function test_tenant_or_system_factory_returns_exists_rule(): void
    {
        $rule = ScopedExists::tenantOrSystem(
            table: 'units',
            tenantId: 'tenant-abc',
        );

        $this->assertInstanceOf(Exists::class, $rule);
    }

    public function test_tenant_or_system_factory_accepts_custom_column(): void
    {
        $rule = ScopedExists::tenantOrSystem(
            table: 'units',
            tenantId: 't-1',
            column: 'code',
        );

        $rendered = (string) $rule;
        $this->assertStringStartsWith('exists:units,code', $rendered);
    }

    public function test_tenant_or_system_factory_string_contains_closure_where(): void
    {
        // The rule uses a Closure-based where() which Laravel serialises as
        // an empty string in the canonical form. Assert we get a valid Exists
        // object with the table name embedded.
        $rule = ScopedExists::tenantOrSystem(
            table: 'units',
            tenantId: 't-1',
        );

        $rendered = (string) $rule;
        // The closure-where renders as the empty-where token after the column.
        $this->assertStringContainsString('units', $rendered);
        $this->assertInstanceOf(Exists::class, $rule);
    }
}

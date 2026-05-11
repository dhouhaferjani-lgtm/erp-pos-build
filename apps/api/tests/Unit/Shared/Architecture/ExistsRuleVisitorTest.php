<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Architecture;

use App\Application\Sweep\Visitors\ExistsRuleVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * Verifies ExistsRuleVisitor (used by Architecture Gate A — bare exists rules
 * in Presentation tier). Tests use a small fixed set of guarded tables and
 * inline source snippets covering both the inline-string form and the
 * `Rule::exists()` builder form, including the chain-scope detection that
 * Codex 2026-05-03 review flagged was missing in the regex-only version.
 */
class ExistsRuleVisitorTest extends TestCase
{
    /** @var list<string> */
    private const GUARDED = ['payment_methods', 'partners'];

    public function test_flags_inline_exists_string_for_guarded_table(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class FormRequest {
    public function rules() {
        return [
            'payment_method_id' => ['required', 'uuid', 'exists:payment_methods,id'],
        ];
    }
}
PHP);

        $this->assertCount(1, $violations);
        $this->assertSame('payment_methods', $violations[0]['table']);
        $this->assertSame('inline_string', $violations[0]['form']);
    }

    public function test_flags_unscoped_rule_exists_builder_call(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use Illuminate\Validation\Rule;
class FormRequest {
    public function rules() {
        return [
            'payment_method_id' => ['required', 'uuid', Rule::exists('payment_methods', 'id')],
        ];
    }
}
PHP);

        $this->assertCount(1, $violations);
        $this->assertSame('payment_methods', $violations[0]['table']);
        $this->assertSame('rule_exists_builder', $violations[0]['form']);
    }

    public function test_does_not_flag_rule_exists_builder_scoped_by_tenant_id(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use Illuminate\Validation\Rule;
class FormRequest {
    public function rules() {
        $tenantId = 'abc';
        return [
            'partner_id' => [Rule::exists('partners', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_does_not_flag_rule_exists_builder_scoped_by_company_id(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use Illuminate\Validation\Rule;
class FormRequest {
    public function rules() {
        $companyId = 'abc';
        return [
            'partner_id' => [Rule::exists('partners', 'id')->where('company_id', $companyId)],
        ];
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_does_not_flag_chain_with_tenant_scope_followed_by_other_wheres(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use Illuminate\Validation\Rule;
class FormRequest {
    public function rules() {
        $tenantId = 'abc';
        return [
            'partner_id' => [
                Rule::exists('partners', 'id')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_skips_class_with_valid_cross_tenant_annotation(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use Illuminate\Validation\Rule;
/**
 * @cross-tenant-by-design
 * Reason: Super-admin endpoint legitimately operates across tenants
 * Audit-id: TEST-CROSS-TENANT-001
 * Approved-by: Test reviewer
 * Expires: 2099-12-31
 */
class SuperAdminRequest {
    public function rules() {
        return [
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')],
        ];
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_does_not_skip_class_with_expired_cross_tenant_annotation(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use Illuminate\Validation\Rule;
/**
 * @cross-tenant-by-design
 * Reason: Expired justification
 * Audit-id: TEST-EXPIRED-001
 * Approved-by: Old reviewer
 * Expires: 2020-01-01
 */
class ExpiredAnnotationRequest {
    public function rules() {
        return [
            'partner_id' => [Rule::exists('partners', 'id')],
        ];
    }
}
PHP);

        $this->assertCount(1, $violations);
    }

    public function test_does_not_skip_class_missing_required_annotation_field(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use Illuminate\Validation\Rule;
/**
 * @cross-tenant-by-design
 * Reason: Missing audit id below
 * Approved-by: Reviewer
 * Expires: 2099-12-31
 */
class IncompleteAnnotationRequest {
    public function rules() {
        return [Rule::exists('partners', 'id')];
    }
}
PHP);

        $this->assertCount(1, $violations);
    }

    public function test_method_level_annotation_skips_only_that_method(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use Illuminate\Validation\Rule;
class MixedRequest {
    /**
     * @cross-tenant-by-design
     * Reason: This method talks to all tenants
     * Audit-id: TEST-METHOD-001
     * Approved-by: Reviewer
     * Expires: never
     */
    public function rulesA() {
        return [Rule::exists('partners', 'id')];
    }

    public function rulesB() {
        return [Rule::exists('partners', 'id')];
    }
}
PHP);

        // Only rulesB should be flagged.
        $this->assertCount(1, $violations);
    }

    public function test_cross_tenant_route_attribute_skips_only_that_method(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Validation\Rule;
class MixedController {
    #[CrossTenantRoute(reason: 'Super-admin validation crosses tenants')]
    public function crossTenantRules() {
        return [Rule::exists('partners', 'id')];
    }

    public function regularRules() {
        return [Rule::exists('partners', 'id')];
    }
}
PHP);

        // Only regularRules should be flagged.
        $this->assertCount(1, $violations);
    }

    public function test_cross_tenant_route_attribute_with_blank_reason_does_not_skip(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use App\Shared\Architecture\CrossTenantRoute;
use Illuminate\Validation\Rule;
class InvalidController {
    #[CrossTenantRoute(reason: '   ')]
    public function rules() {
        return [Rule::exists('partners', 'id')];
    }
}
PHP);

        $this->assertCount(1, $violations);
    }

    public function test_line_comment_cross_tenant_annotation_skips_method(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use Illuminate\Validation\Rule;
class InlineAnnotatedController {
    // @cross-tenant-by-design
    // Reason: Cross-tenant fixture
    // Audit-id: TEST-LINE-COMMENT-001
    // Approved-by: Reviewer
    // Expires: 2099-12-31
    public function rules() {
        return [Rule::exists('partners', 'id')];
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_does_not_flag_non_guarded_table(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use Illuminate\Validation\Rule;
class FormRequest {
    public function rules() {
        return [
            'currency_code' => ['required', 'exists:currencies,code'],
            'category' => [Rule::exists('product_types', 'id')],
        ];
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    /**
     * @return list<array{line: int, table: string, form: string}>
     */
    private function scan(string $code): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        if ($stmts === null) {
            return [];
        }
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ParentConnectingVisitor);
        $visitor = new ExistsRuleVisitor(self::GUARDED);
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->violations;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Architecture;

use App\Application\Sweep\Visitors\FindCallVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * Verifies FindCallVisitor (used by Architecture Gate B — unscoped
 * Eloquent find()/findOrFail() calls on guarded models). Tests focus on
 * the false-positive scenarios Codex 2026-05-03 review flagged: cross-method
 * scope variable bleed, recognition of local Eloquent scope methods
 * (forCompany/forTenant), and the @cross-tenant-by-design docblock skip.
 */
class FindCallVisitorTest extends TestCase
{
    /** @var list<string> */
    private const GUARDED = ['Document', 'Partner', 'PaymentMethod'];

    public function test_flags_static_find_on_guarded_model(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class Service {
    public function get(string $id) {
        return Document::find($id);
    }
}
PHP);

        $this->assertCount(1, $violations);
        $this->assertSame('Document', $violations[0]['model']);
        $this->assertSame('find', $violations[0]['method']);
    }

    public function test_flags_static_find_or_fail(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class Service {
    public function get(string $id) {
        return Partner::findOrFail($id);
    }
}
PHP);

        $this->assertCount(1, $violations);
        $this->assertSame('findOrFail', $violations[0]['method']);
    }

    public function test_does_not_flag_chain_with_explicit_tenant_and_company_where(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class Service {
    public function get(string $id, string $tenantId, string $companyId) {
        return Document::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->find($id);
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_does_not_flag_chain_with_for_company_local_scope(): void
    {
        // Codex review C3 false-positive case: Document::forCompany($id)->findOrFail($id)
        // is the canonical pattern in DocumentController.php and is correctly scoped.
        $violations = $this->scan(<<<'PHP'
<?php
class DocumentController {
    public function show(string $id, string $companyId) {
        return Document::forCompany($companyId)->findOrFail($id);
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_does_not_flag_chain_with_for_tenant_local_scope(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class Service {
    public function get(string $id, string $tid) {
        return Partner::forTenant($tid)->find($id);
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_does_not_flag_for_company_followed_by_other_filters(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class Service {
    public function get(string $id, string $cid) {
        return Document::forCompany($cid)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->findOrFail($id);
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_recognizes_scoped_variable_assigned_with_for_company(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class Service {
    public function get(string $id, string $cid) {
        $query = Document::forCompany($cid);
        $query->where('status', 'active');
        return $query->find($id);
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_scoped_variable_does_not_bleed_across_methods(): void
    {
        // Codex review C3 root-cause case: under the prior visitor, the
        // file-global $scopedVariables map would have made $query in
        // methodB inherit methodA's tenant scope, suppressing the bare
        // static Document::find() call in methodB. After the fix, the
        // per-method scope stack guarantees methodB's static find IS
        // flagged because it hasn't seen any scoped variable.
        $violations = $this->scan(<<<'PHP'
<?php
class Service {
    public function methodA(string $id, string $cid) {
        $query = Document::query()->where('tenant_id', $cid);
        return $query->find($id);
    }

    public function methodB(string $id) {
        return Document::find($id);
    }
}
PHP);

        $this->assertCount(1, $violations);
        $this->assertSame('Document', $violations[0]['model']);
    }

    public function test_scoped_variable_does_not_bleed_into_closure(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class Service {
    public function outer(string $id, string $cid) {
        $query = Document::query()->where('tenant_id', $cid);
        $callback = function () use ($id) {
            return Document::find($id);
        };
        return $callback();
    }
}
PHP);

        $this->assertCount(1, $violations);
    }

    public function test_class_level_cross_tenant_annotation_skips_all_methods(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
/**
 * @cross-tenant-by-design
 * Reason: Super-admin operates across tenants
 * Audit-id: TEST-FIND-001
 * Approved-by: Reviewer
 * Expires: 2099-12-31
 */
class SuperAdminService {
    public function get(string $id) {
        return Document::find($id);
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_method_level_cross_tenant_annotation_skips_only_that_method(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class MixedService {
    /**
     * @cross-tenant-by-design
     * Reason: Cross-tenant aggregation
     * Audit-id: TEST-METHOD-001
     * Approved-by: Reviewer
     * Expires: never
     */
    public function aggregate(string $id) {
        return Document::find($id);
    }

    public function regularLookup(string $id) {
        return Document::find($id);
    }
}
PHP);

        $this->assertCount(1, $violations);
    }

    public function test_cross_tenant_route_attribute_skips_only_that_method(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use App\Shared\Architecture\CrossTenantRoute;
class MixedController {
    #[CrossTenantRoute(reason: 'Super-admin lookup crosses tenants')]
    public function aggregate(string $id) {
        return Document::find($id);
    }

    public function regularLookup(string $id) {
        return Document::find($id);
    }
}
PHP);

        $this->assertCount(1, $violations);
    }

    public function test_cross_tenant_route_attribute_with_blank_reason_does_not_skip(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
use App\Shared\Architecture\CrossTenantRoute;
class InvalidController {
    #[CrossTenantRoute(reason: '   ')]
    public function aggregate(string $id) {
        return Document::find($id);
    }
}
PHP);

        $this->assertCount(1, $violations);
    }

    public function test_line_comment_cross_tenant_annotation_skips_method(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class InlineAnnotatedController {
    // @cross-tenant-by-design
    // Reason: Cross-tenant fixture
    // Audit-id: TEST-LINE-COMMENT-001
    // Approved-by: Reviewer
    // Expires: 2099-12-31
    public function aggregate(string $id) {
        return Document::find($id);
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    public function test_does_not_flag_non_guarded_model(): void
    {
        $violations = $this->scan(<<<'PHP'
<?php
class Service {
    public function get(string $id) {
        return Tenant::findOrFail($id);
    }
}
PHP);

        $this->assertSame([], $violations);
    }

    /**
     * @return list<array{line: int, model: string, method: string}>
     */
    private function scan(string $code): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        if ($stmts === null) {
            return [];
        }
        $traverser = new NodeTraverser;
        $visitor = new FindCallVisitor(self::GUARDED);
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->violations;
    }
}

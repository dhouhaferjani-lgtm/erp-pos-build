<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Sweep\Visitors;

use App\Application\Sweep\Visitors\FindCallVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class FindCallVisitorTest extends TestCase
{
    /**
     * @return list<array{int, string, string}>
     */
    private function runVisitor(string $code): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        if ($stmts === null) {
            return [];
        }

        $visitor = new FindCallVisitor(['LoyaltyMember', 'WorkOrder']);
        $traverser = new NodeTraverser;
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return array_map(
            static fn (array $v): array => [$v['line'], $v['model'], $v['method']],
            $visitor->violations,
        );
    }

    public function test_tenant_only_loyalty_member_lookup_with_local_tenant_id_is_scoped(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Loyalty\Domain\Models\LoyaltyMember;

        class Controller {
            public function show(string $id): mixed {
                $tenantId = $this->companyContext->requireCompany()->tenant_id;

                return LoyaltyMember::where('tenant_id', $tenantId)->findOrFail($id);
            }
        }
        PHP;

        $this->assertSame([], $this->runVisitor($code));
    }

    public function test_work_order_lookup_requires_tenant_and_company_predicates(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Workshop\WorkOrder\Domain\Models\WorkOrder;

        class Service {
            public function show(string $id): mixed {
                $tenantId = $this->companyContext->requireCompany()->tenant_id;

                return WorkOrder::where('tenant_id', $tenantId)->find($id);
            }
        }
        PHP;

        $violations = $this->runVisitor($code);

        $this->assertCount(1, $violations);
        $this->assertSame('WorkOrder', $violations[0][1]);
    }

    public function test_work_order_lookup_with_local_tenant_and_company_ids_is_scoped(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Workshop\WorkOrder\Domain\Models\WorkOrder;

        class Service {
            public function show(string $id): mixed {
                $tenantId = $this->companyContext->requireCompany()->tenant_id;
                $companyId = $this->companyContext->requireCompany()->id;

                return WorkOrder::where('tenant_id', $tenantId)
                    ->where('company_id', $companyId)
                    ->find($id);
            }
        }
        PHP;

        $this->assertSame([], $this->runVisitor($code));
    }

    public function test_lookup_scoped_to_method_parameter_aggregate_is_scoped(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Workshop\WorkOrder\Domain\Models\WorkOrder;

        class Service {
            public function duplicate(WorkOrder $workOrder, string $id): mixed {
                return WorkOrder::where('tenant_id', $workOrder->tenant_id)
                    ->where('company_id', $workOrder->company_id)
                    ->find($id);
            }
        }
        PHP;

        $this->assertSame([], $this->runVisitor($code));
    }

    public function test_request_sourced_tenant_operand_is_not_scoped(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Loyalty\Domain\Models\LoyaltyMember;

        class Controller {
            public function show(string $id, $request): mixed {
                return LoyaltyMember::where('tenant_id', $request->input('tenant_id'))->findOrFail($id);
            }
        }
        PHP;

        $violations = $this->runVisitor($code);

        $this->assertCount(1, $violations);
        $this->assertSame('LoyaltyMember', $violations[0][1]);
    }

    public function test_request_helper_tenant_operand_is_not_scoped(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Loyalty\Domain\Models\LoyaltyMember;

        class Controller {
            public function show(string $id): mixed {
                return LoyaltyMember::where('tenant_id', request('tenant_id'))->findOrFail($id);
            }
        }
        PHP;

        $violations = $this->runVisitor($code);

        $this->assertCount(1, $violations);
        $this->assertSame('LoyaltyMember', $violations[0][1]);
    }

    public function test_null_tenant_operand_is_not_scoped(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Loyalty\Domain\Models\LoyaltyMember;

        class Controller {
            public function show(string $id): mixed {
                return LoyaltyMember::where('tenant_id', null)->findOrFail($id);
            }
        }
        PHP;

        $violations = $this->runVisitor($code);

        $this->assertCount(1, $violations);
        $this->assertSame('LoyaltyMember', $violations[0][1]);
    }

    public function test_string_literal_tenant_operand_is_not_scoped(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Loyalty\Domain\Models\LoyaltyMember;

        class Controller {
            public function show(string $id): mixed {
                return LoyaltyMember::where('tenant_id', 'tenant-a')->findOrFail($id);
            }
        }
        PHP;

        $violations = $this->runVisitor($code);

        $this->assertCount(1, $violations);
        $this->assertSame('LoyaltyMember', $violations[0][1]);
    }

    public function test_foreign_model_tenant_operand_from_local_lookup_is_not_scoped(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Loyalty\Domain\Models\LoyaltyMember;
        use App\Modules\Workshop\WorkOrder\Domain\Models\WorkOrder;

        class Controller {
            public function show(string $id, string $workOrderId): mixed {
                $other = WorkOrder::find($workOrderId);

                return LoyaltyMember::where('tenant_id', $other->tenant_id)->findOrFail($id);
            }
        }
        PHP;

        $violations = $this->runVisitor($code);

        $this->assertCount(2, $violations);
        $this->assertSame('WorkOrder', $violations[0][1]);
        $this->assertSame('LoyaltyMember', $violations[1][1]);
    }

    public function test_where_raw_tenant_predicate_is_not_scoped(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Loyalty\Domain\Models\LoyaltyMember;

        class Controller {
            public function show(string $id): mixed {
                $tenantId = $this->companyContext->requireCompany()->tenant_id;

                return LoyaltyMember::whereRaw('tenant_id = ?', [$tenantId])->findOrFail($id);
            }
        }
        PHP;

        $violations = $this->runVisitor($code);

        $this->assertCount(1, $violations);
        $this->assertSame('LoyaltyMember', $violations[0][1]);
    }

    public function test_helper_scope_macro_is_not_scoped(): void
    {
        $code = <<<'PHP'
        <?php
        use App\Modules\Loyalty\Domain\Models\LoyaltyMember;

        class Controller {
            public function show(string $id): mixed {
                $tenantId = $this->companyContext->requireCompany()->tenant_id;

                return LoyaltyMember::scopedToTenant($tenantId)->findOrFail($id);
            }
        }
        PHP;

        $violations = $this->runVisitor($code);

        $this->assertCount(1, $violations);
        $this->assertSame('LoyaltyMember', $violations[0][1]);
    }
}

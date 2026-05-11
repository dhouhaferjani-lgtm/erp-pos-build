<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Presentation\Validation;

use App\Shared\Presentation\Validation\ScopedExists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\TestCase;

/**
 * Behavioral coverage for ScopedExists.
 *
 * Codex review 2026-05-03 R1: the existing string-shape unit tests prove the
 * rendered rule string is correct, but they don't prove the rule actually
 * rejects cross-tenant rows when run through Laravel's validator. This suite
 * does the latter against a temp table seeded with rows in two tenants × two
 * companies, plus a "negative control" assertion against an unscoped
 * Rule::exists() that proves the test is sensitive enough to catch a leak.
 */
class ScopedExistsBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'scoped_exists_test_resources';

    private const TENANT_A = '00000000-0000-0000-0000-000000000a01';

    private const TENANT_B = '00000000-0000-0000-0000-000000000b01';

    private const COMPANY_A1 = '00000000-0000-0000-0000-00000000a101';

    private const COMPANY_A2 = '00000000-0000-0000-0000-00000000a102';

    private const COMPANY_B1 = '00000000-0000-0000-0000-00000000b101';

    private const COMPANY_B2 = '00000000-0000-0000-0000-00000000b102';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(self::TABLE, function ($table): void {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->uuid('company_id');
            $table->string('name');
        });

        DB::table(self::TABLE)->insert([
            ['id' => 1, 'tenant_id' => self::TENANT_A, 'company_id' => self::COMPANY_A1, 'name' => 'tenant-a / company-a1'],
            ['id' => 2, 'tenant_id' => self::TENANT_A, 'company_id' => self::COMPANY_A2, 'name' => 'tenant-a / company-a2'],
            ['id' => 3, 'tenant_id' => self::TENANT_B, 'company_id' => self::COMPANY_B1, 'name' => 'tenant-b / company-b1'],
            ['id' => 4, 'tenant_id' => self::TENANT_B, 'company_id' => self::COMPANY_B2, 'name' => 'tenant-b / company-b2'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::TABLE);
        parent::tearDown();
    }

    public function test_tenant_and_company_accepts_only_rows_in_the_specified_tenant_plus_company(): void
    {
        $rule = ScopedExists::tenantAndCompany(self::TABLE, self::TENANT_A, self::COMPANY_A1);

        $this->assertValidates($rule, 1, 'row in tenant-a / company-a1 must be accepted');
        $this->assertRejects($rule, 2, 'row in tenant-a / company-a2 must be rejected (same tenant, wrong company)');
        $this->assertRejects($rule, 3, 'row in tenant-b / company-b1 must be rejected (wrong tenant, same company name only)');
        $this->assertRejects($rule, 4, 'row in tenant-b / company-b2 must be rejected (wrong tenant, wrong company)');
    }

    public function test_tenant_accepts_rows_in_the_specified_tenant_regardless_of_company(): void
    {
        $rule = ScopedExists::tenant(self::TABLE, self::TENANT_A);

        $this->assertValidates($rule, 1, 'row in tenant-a / company-a1 must be accepted');
        $this->assertValidates($rule, 2, 'row in tenant-a / company-a2 must be accepted (different company in same tenant is OK)');
        $this->assertRejects($rule, 3, 'row in tenant-b / company-b1 must be rejected');
        $this->assertRejects($rule, 4, 'row in tenant-b / company-b2 must be rejected');
    }

    public function test_company_accepts_rows_in_the_specified_company_regardless_of_tenant(): void
    {
        $rule = ScopedExists::company(self::TABLE, self::COMPANY_A1);

        $this->assertValidates($rule, 1, 'row in company-a1 must be accepted');
        $this->assertRejects($rule, 2, 'row in company-a2 must be rejected');
        $this->assertRejects($rule, 3, 'row in company-b1 must be rejected (different uuid)');
        $this->assertRejects($rule, 4, 'row in company-b2 must be rejected');
    }

    public function test_negative_control_unscoped_exists_accepts_rows_from_every_tenant(): void
    {
        // This negative control proves the test setup is sensitive enough to
        // detect a cross-tenant leak: a bare `Rule::exists()` without the
        // ScopedExists scoping accepts every row in the table. If this test
        // ever fails, the database setup is broken — not the rule semantics.
        $unscoped = Rule::exists(self::TABLE, 'id');

        $this->assertValidates($unscoped, 1, 'unscoped rule accepts row 1');
        $this->assertValidates($unscoped, 2, 'unscoped rule accepts row 2 (would leak across companies in production)');
        $this->assertValidates($unscoped, 3, 'unscoped rule accepts row 3 (would leak across tenants in production)');
        $this->assertValidates($unscoped, 4, 'unscoped rule accepts row 4 (would leak across both)');
    }

    private function assertValidates(object $rule, int $value, string $why): void
    {
        $validator = Validator::make(['target' => $value], ['target' => $rule]);
        $this->assertTrue($validator->passes(), $why.' — got: '.json_encode($validator->errors()->all()));
    }

    private function assertRejects(object $rule, int $value, string $why): void
    {
        $validator = Validator::make(['target' => $value], ['target' => $rule]);
        $this->assertFalse($validator->passes(), $why.' — but the validator passed (cross-tenant leak)');
    }
}

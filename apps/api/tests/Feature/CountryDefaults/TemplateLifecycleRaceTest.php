<?php

declare(strict_types=1);

namespace Tests\Feature\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\CountryDefaults\Application\Services\TemplateAssignmentService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TemplateLifecycleRaceTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string|null> */
    protected function connectionsToTransact(): array
    {
        return DB::getDriverName() === 'pgsql' ? [] : [config('database.default')];
    }

    protected function tearDown(): void
    {
        DB::connection((new AdminTemplate)->getConnectionName())
            ->table('country_template_assignments')
            ->whereIn('country_code', ['FR', 'DE'])
            ->delete();

        parent::tearDown();
    }

    public function test_publish_vs_assign_serializes_and_observes_the_post_lock_status(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->assertDeterministicLockContract();
            $source = (string) file_get_contents(app_path('Modules/CountryDefaults/Application/Services/TemplateAssignmentService.php'));
            self::assertStringContainsString('if ($template->status !== TemplateStatus::Published)', $source);

            return;
        }

        $actor = $this->actor();
        $template = $this->validDraft('FR');
        [$parent, $child] = $this->socketPair();
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parent);
            DB::disconnect();
            $connection = DB::connection((new AdminTemplate)->getConnectionName());
            $connection->beginTransaction();
            AdminTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            fwrite($child, "locked\n");
            usleep(300_000);
            $connection->table('admin_templates')->where('id', $template->id)->update([
                'status' => TemplateStatus::Published->value,
                'content_hash' => str_repeat('a', 64),
                'standard_ref' => 'PCG 2026',
                'certified_country_codes' => json_encode(['FR'], JSON_THROW_ON_ERROR),
                'capability_registry_version' => 'v1',
                'published_at' => now(),
            ]);
            $connection->commit();
            fclose($child);
            exit(0);
        }

        fclose($child);
        self::assertSame("locked\n", fgets($parent));
        $assignment = app(TemplateAssignmentService::class)->assign(
            'FR',
            TemplateDomain::ChartOfAccounts,
            $template->id,
            $actor,
        );
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame($template->id, $assignment->template_id);
    }

    public function test_archive_vs_assign_serializes_without_a_partial_assignment(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->assertDeterministicLockContract();
            $source = (string) file_get_contents(app_path('Modules/CountryDefaults/Application/Services/TemplatePublishingService.php'));
            self::assertStringContainsString('assignments()->lockForUpdate()', $source);
            self::assertStringContainsString("->table('admin_templates')", $source);

            return;
        }

        $actor = $this->actor();
        $template = $this->validDraft('DE');
        DB::connection($template->getConnectionName())->table('admin_templates')->where('id', $template->id)->update([
            'status' => TemplateStatus::Published->value,
            'content_hash' => str_repeat('a', 64),
            'standard_ref' => 'HGB 2026',
            'certified_country_codes' => json_encode(['DE'], JSON_THROW_ON_ERROR),
            'capability_registry_version' => 'v1',
            'published_at' => now(),
        ]);
        [$parent, $child] = $this->socketPair();
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            fclose($parent);
            DB::disconnect();
            $connection = DB::connection((new AdminTemplate)->getConnectionName());
            $connection->beginTransaction();
            AdminTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            fwrite($child, "locked\n");
            usleep(300_000);
            $connection->table('admin_templates')->where('id', $template->id)->update([
                'status' => TemplateStatus::Archived->value,
            ]);
            $connection->commit();
            fclose($child);
            exit(0);
        }

        fclose($child);
        self::assertSame("locked\n", fgets($parent));
        try {
            app(TemplateAssignmentService::class)->assign(
                'DE',
                TemplateDomain::ChartOfAccounts,
                $template->id,
                $actor,
            );
            self::fail('An assignment racing a committed archive must fail after its lock wait.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('published', $exception->getMessage());
        }
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertDatabaseMissing('country_template_assignments', ['country_code' => 'DE']);
    }

    private function assertDeterministicLockContract(): void
    {
        $source = (string) file_get_contents(app_path('Modules/CountryDefaults/Application/Services/TemplateAssignmentService.php'));
        self::assertStringContainsString('->transaction(', $source);
        self::assertStringContainsString('->lockForUpdate()', $source);
        self::assertStringContainsString("\n            sort(\$templateIds, SORT_STRING);", $source);
        self::assertStringContainsString("->orderBy('id')", $source);
        self::assertStringContainsString('$templateIds[] = $assignment->template_id', $source);
    }

    /** @return array{resource, resource} */
    private function socketPair(): array
    {
        if (! function_exists('pcntl_fork')) {
            self::fail('pcntl is required for the PostgreSQL lifecycle race proof.');
        }
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($sockets);

        return [$sockets[0], $sockets[1]];
    }

    private function validDraft(string $country): AdminTemplate
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => 'Race fixture '.Str::random(8),
            'status' => TemplateStatus::Draft,
        ]);
        $sort = 1;
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] !== 'REQUIRED') {
                continue;
            }
            AdminTemplateAccount::query()->create([
                'template_id' => $template->id,
                'code' => sprintf('R%03d', $sort),
                'name' => $entry['purpose']->name,
                'type' => $entry['purpose']->expectedAccountType(),
                'parent_code' => null,
                'system_purpose' => $entry['purpose'],
                'is_system' => true,
                'sort_order' => $sort++,
            ]);
        }
        foreach (ProtectedAccountCodeRegistry::forCountry($country) as $protected) {
            AdminTemplateAccount::query()->create([
                'template_id' => $template->id,
                'code' => $protected['code'],
                'name' => 'Protected '.$protected['code'],
                'type' => AccountType::from($protected['expected_type']),
                'parent_code' => null,
                'system_purpose' => null,
                'is_system' => $protected['requires_system'],
                'sort_order' => $sort++,
            ]);
        }

        return $template;
    }

    private function actor(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'Race operator',
            'email' => Str::uuid().'@example.test',
            'password' => 'irrelevant',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }
}

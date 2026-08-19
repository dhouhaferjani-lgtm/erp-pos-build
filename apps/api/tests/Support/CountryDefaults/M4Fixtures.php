<?php

declare(strict_types=1);

namespace Tests\Support\CountryDefaults;

use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Application\Services\TemplateAssignmentService;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use Illuminate\Support\Str;

trait M4Fixtures
{
    private function m4Actor(): SuperAdmin
    {
        return SuperAdmin::query()->create([
            'name' => 'M4 Certifier',
            'email' => Str::uuid().'@example.test',
            'password' => 'irrelevant',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    private function m4Golden(string $country): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/CountryDefaults/goldens/{$country}.legacy-v1.txt"));
    }

    /** @return list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int}> */
    private function m4GoldenRows(string $country): array
    {
        $bytes = $this->m4Golden($country);
        $rows = [];
        foreach (explode("\n", $bytes) as $line) {
            /** @var array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int} $row */
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            $rows[] = $row;
        }

        $frenchPlan = in_array($country, ['tn', 'fr'], true);
        $nextOrder = max(array_column($rows, 'sort_order')) + 1;
        $rows[] = [
            'code' => '6586',
            'name' => $frenchPlan ? "Écarts d'inventaire — manquants et pertes" : 'Inventory Shrinkage Expense',
            'type' => 'expense',
            'parent_code' => $frenchPlan ? '65' : '6000',
            'system_purpose' => SystemAccountPurpose::InventoryShrinkageExpense->value,
            'is_system' => true,
            'sort_order' => $nextOrder,
        ];
        $rows[] = [
            'code' => '7586',
            'name' => $frenchPlan ? "Écarts d'inventaire — excédents" : 'Inventory Count Gain',
            'type' => 'revenue',
            'parent_code' => $frenchPlan ? '75' : '7000',
            'system_purpose' => SystemAccountPurpose::InventoryGainIncome->value,
            'is_system' => true,
            'sort_order' => $nextOrder + 1,
        ];

        return $rows;
    }

    private function m4Draft(string $country): AdminTemplate
    {
        $template = AdminTemplate::query()->create([
            'domain' => TemplateDomain::ChartOfAccounts,
            'name' => "M4 {$country} fixture ".Str::random(6),
            'status' => TemplateStatus::Draft,
        ]);
        foreach ($this->m4InsertionOrder($this->m4GoldenRows($country)) as $row) {
            AdminTemplateAccount::query()->create(['template_id' => $template->id, ...$row]);
        }

        return $template->fresh() ?? $template;
    }

    /**
     * @param  list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int}>  $rows
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose: string|null, is_system: bool, sort_order: int}>
     */
    private function m4InsertionOrder(array $rows): array
    {
        $pending = [];
        foreach ($rows as $row) {
            $pending[$row['code']] = $row;
        }
        $ordered = [];
        $inserted = [];
        while ($pending !== []) {
            foreach ($pending as $code => $row) {
                if ($row['parent_code'] !== null && ! isset($inserted[$row['parent_code']])) {
                    continue;
                }
                $ordered[] = $row;
                $inserted[$code] = true;
                unset($pending[$code]);
            }
        }

        return $ordered;
    }

    private function m4Published(string $country, string $assignmentCountry, SuperAdmin $actor): AdminTemplate
    {
        $template = $this->m4Draft($country);

        return app(TemplatePublishingService::class)->publish(
            $template->id,
            'Option A certified v2',
            [$assignmentCountry],
            $actor,
        );
    }

    private function m4Assign(string $country, AdminTemplate $template, SuperAdmin $actor): void
    {
        app(TemplateAssignmentService::class)->assign(
            $country,
            TemplateDomain::ChartOfAccounts,
            $template->id,
            $actor,
        );
    }
}

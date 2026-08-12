<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Infrastructure\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TemplateChartOfAccountsSeeder
{
    public function seed(AdminTemplate $template, Company $company): void
    {
        if ($template->status !== TemplateStatus::Published) {
            throw new DomainException('Only published templates may provision a chart of accounts.');
        }

        /** @var list<AdminTemplateAccount> $definitions */
        $definitions = array_values($template->accounts()->orderBy('sort_order')->get()->all());

        DB::transaction(function () use ($definitions, $company): void {
            $now = now();
            /** @var array<string, string> $accountIdMap */
            $accountIdMap = [];

            foreach ($definitions as $definition) {
                $account = Account::query()
                    ->where('tenant_id', $company->tenant_id)
                    ->where('company_id', $company->id)
                    ->where('code', $definition->code)
                    ->first();
                $matchedByCode = $account instanceof Account;

                $purpose = $definition->getRawOriginal('system_purpose');
                if (! $account instanceof Account && is_string($purpose) && $purpose !== '') {
                    $account = Account::query()
                        ->where('tenant_id', $company->tenant_id)
                        ->where('company_id', $company->id)
                        ->where('system_purpose', $purpose)
                        ->first();
                }

                if ($account instanceof Account) {
                    $accountIdMap[$definition->code] = $account->id;
                    if ($matchedByCode && $definition->is_system && ! $account->is_system) {
                        Account::query()
                            ->where('tenant_id', $company->tenant_id)
                            ->where('company_id', $company->id)
                            ->whereKey($account->id)
                            ->update(['is_system' => true, 'updated_at' => $now]);
                    }

                    continue;
                }

                $id = Str::uuid()->toString();
                $accountIdMap[$definition->code] = $id;
                DB::table('accounts')->insert([
                    'id' => $id,
                    'tenant_id' => $company->tenant_id,
                    'company_id' => $company->id,
                    'parent_id' => null,
                    'code' => $definition->code,
                    'name' => $definition->name,
                    'type' => $definition->getRawOriginal('type'),
                    'system_purpose' => $purpose,
                    'is_active' => true,
                    'is_system' => $definition->is_system,
                    'balance' => '0.000',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($definitions as $definition) {
                $accountId = $accountIdMap[$definition->code] ?? null;
                if ($accountId === null) {
                    throw new DomainException("Template account {$definition->code} was not provisioned.");
                }

                $parentId = null;
                if ($definition->parent_code !== null) {
                    $parentId = $accountIdMap[$definition->parent_code] ?? null;
                    if ($parentId === null) {
                        throw new DomainException("Template account {$definition->code} has an unresolved parent.");
                    }
                }

                $updated = Account::query()
                    ->where('tenant_id', $company->tenant_id)
                    ->where('company_id', $company->id)
                    ->whereKey($accountId)
                    ->update(['parent_id' => $parentId, 'updated_at' => $now]);
                if ($updated !== 1) {
                    throw new DomainException("Template account {$definition->code} left the target company scope.");
                }
            }
        });
    }
}

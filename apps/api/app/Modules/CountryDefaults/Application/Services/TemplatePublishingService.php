<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\Services;

use App\Models\SuperAdmin;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\Registries\ProtectedAccountCodeRegistry;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\CountryDefaults\Domain\ValueObjects\CertificationScope;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use App\Services\AdminAuditService;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TemplatePublishingService
{
    public function __construct(
        private readonly CountryAccountingCapabilities $capabilities,
        private readonly CanonicalCoaSerializer $serializer,
        private readonly AdminAuditService $audit,
    ) {}

    /** @param list<string> $countryCodes */
    public function publish(
        string $templateId,
        string $standardRef,
        array $countryCodes,
        SuperAdmin $actor,
    ): AdminTemplate {
        $connection = $this->centralConnection();

        return $connection->transaction(function () use ($templateId, $standardRef, $countryCodes, $actor, $connection): AdminTemplate {
            $template = AdminTemplate::query()->lockForUpdate()->findOrFail($templateId);
            $accounts = $template->accounts()->lockForUpdate()->get();

            if ($template->status !== TemplateStatus::Draft) {
                throw new DomainException('Only a locked draft template can be published.');
            }
            if (trim($standardRef) === '') {
                throw new DomainException('standard_ref must not be blank.');
            }

            try {
                $scope = new CertificationScope($countryCodes, $this->capabilities);
            } catch (InvalidArgumentException $exception) {
                throw new DomainException($exception->getMessage(), previous: $exception);
            }

            $this->validateAccounts(array_values($accounts->all()), $scope);
            $canonicalRows = array_values(array_map(
                static fn (AdminTemplateAccount $account): array => [
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'parent_code' => $account->parent_code,
                    'system_purpose' => $account->system_purpose,
                    'is_system' => $account->is_system,
                    'sort_order' => $account->sort_order,
                ],
                $accounts->all(),
            ));

            $updated = $connection->table('admin_templates')
                ->where('id', $template->id)
                ->where('status', TemplateStatus::Draft->value)
                ->update([
                    'status' => TemplateStatus::Published->value,
                    'standard_ref' => trim($standardRef),
                    'certified_country_codes' => json_encode($scope->countryCodes(), JSON_THROW_ON_ERROR),
                    'content_hash' => $this->serializer->hash($canonicalRows),
                    'capability_registry_version' => $this->capabilities->version(),
                    'certified_by' => $actor->id,
                    'published_at' => now(),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DomainException('Template changed after its publish lifecycle lock.');
            }
            $template->refresh();

            $this->assertAuditTransaction($connection);
            $this->audit->log(
                admin: $actor,
                action: 'country_defaults.template.published',
                entityType: 'admin_template',
                entityId: $template->id,
                newValues: [
                    'content_hash' => $template->content_hash,
                    'standard_ref' => $template->standard_ref,
                    'certified_country_codes' => $template->certified_country_codes,
                    'capability_registry_version' => $template->capability_registry_version,
                ],
            );

            return $template->fresh() ?? $template;
        });
    }

    public function cloneToDraft(string $templateId, string $name, SuperAdmin $actor): AdminTemplate
    {
        if (trim($name) === '') {
            throw new DomainException('Clone name must not be blank.');
        }

        $connection = $this->centralConnection();

        return $connection->transaction(function () use ($templateId, $name, $actor, $connection): AdminTemplate {
            $source = AdminTemplate::query()->lockForUpdate()->findOrFail($templateId);
            $sourceRows = array_values($source->accounts()->orderBy('sort_order')->lockForUpdate()->get()->all());

            $clone = AdminTemplate::query()->create([
                'domain' => $source->domain,
                'name' => trim($name),
                'description' => $source->description,
                'status' => TemplateStatus::Draft,
                'cloned_from_id' => $source->id,
                'created_by' => $actor->id,
            ]);
            foreach ($this->cloneInsertionOrder($sourceRows) as $row) {
                AdminTemplateAccount::query()->create([
                    'template_id' => $clone->id,
                    'code' => $row->code,
                    'name' => $row->name,
                    'type' => $row->type,
                    'parent_code' => $row->parent_code,
                    'system_purpose' => $row->system_purpose,
                    'is_system' => $row->is_system,
                    'sort_order' => $row->sort_order,
                ]);
            }

            $this->assertAuditTransaction($connection);
            $this->audit->log(
                admin: $actor,
                action: 'country_defaults.template.cloned',
                entityType: 'admin_template',
                entityId: $clone->id,
                oldValues: ['source_template_id' => $source->id],
                newValues: ['status' => TemplateStatus::Draft->value, 'name' => $clone->name],
            );

            return $clone->fresh() ?? $clone;
        });
    }

    public function archive(string $templateId, SuperAdmin $actor): AdminTemplate
    {
        $connection = $this->centralConnection();

        return $connection->transaction(function () use ($templateId, $actor, $connection): AdminTemplate {
            $assignments = CountryTemplateAssignment::query()
                ->where('template_id', $templateId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $template = AdminTemplate::query()->lockForUpdate()->findOrFail($templateId);
            $hasAssignment = $assignments->isNotEmpty() || CountryTemplateAssignment::query()
                ->where('template_id', $templateId)
                ->orderBy('id')
                ->lockForUpdate()
                ->first() !== null;
            if ($hasAssignment) {
                throw new DomainException('A template referenced by an assignment cannot be archived.');
            }
            if ($template->status !== TemplateStatus::Published) {
                throw new DomainException('Only an unassigned published template can be archived.');
            }

            $updated = $connection->table('admin_templates')
                ->where('id', $template->id)
                ->where('status', TemplateStatus::Published->value)
                ->update([
                    'status' => TemplateStatus::Archived->value,
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                throw new DomainException('Template changed after its archive lifecycle lock.');
            }
            $template->refresh();

            $this->assertAuditTransaction($connection);
            $this->audit->log(
                admin: $actor,
                action: 'country_defaults.template.archived',
                entityType: 'admin_template',
                entityId: $template->id,
                oldValues: ['status' => TemplateStatus::Published->value],
                newValues: ['status' => TemplateStatus::Archived->value],
            );

            return $template->fresh() ?? $template;
        });
    }

    public function delete(string $templateId, SuperAdmin $actor): void
    {
        $connection = $this->centralConnection();

        $connection->transaction(function () use ($templateId, $actor, $connection): void {
            $assignments = CountryTemplateAssignment::query()
                ->where('template_id', $templateId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $template = AdminTemplate::query()->lockForUpdate()->findOrFail($templateId);
            $hasAssignment = $assignments->isNotEmpty() || CountryTemplateAssignment::query()
                ->where('template_id', $templateId)
                ->orderBy('id')
                ->lockForUpdate()
                ->first() !== null;
            if ($hasAssignment) {
                throw new DomainException('A template referenced by an assignment cannot be deleted.');
            }
            if ($template->status !== TemplateStatus::Draft) {
                throw new DomainException('Only an unassigned draft template can be deleted.');
            }

            $templateId = $template->id;
            $template->delete();

            $this->assertAuditTransaction($connection);
            $this->audit->log(
                admin: $actor,
                action: 'country_defaults.template.deleted',
                entityType: 'admin_template',
                entityId: $templateId,
                oldValues: ['status' => TemplateStatus::Draft->value],
            );
        });
    }

    /**
     * @param  list<AdminTemplateAccount>  $accounts
     */
    public function validateAccounts(array $accounts, CertificationScope $scope): void
    {
        $this->validateAccountRows($accounts, $scope);
    }

    /** @param list<AdminTemplateAccount> $accounts */
    public function validateAccountsWithoutScope(array $accounts): void
    {
        $this->validateAccountRows($accounts, null);
    }

    /** @param list<AdminTemplateAccount> $accounts */
    private function validateAccountRows(array $accounts, ?CertificationScope $scope): void
    {
        if ($accounts === []) {
            throw new DomainException('A template must contain account rows.');
        }

        $byCode = [];
        $byPurpose = [];
        $sortOrders = [];
        foreach ($accounts as $account) {
            if (trim($account->code) === '' || trim($account->name) === '') {
                throw new DomainException('Template rows must have nonblank code and name.');
            }
            if (isset($byCode[$account->code])) {
                throw new DomainException("Duplicate template account code {$account->code}.");
            }
            if (isset($sortOrders[$account->sort_order])) {
                throw new DomainException("Duplicate template sort_order {$account->sort_order}.");
            }
            $byCode[$account->code] = $account;
            $sortOrders[$account->sort_order] = true;

            if ($account->system_purpose === null) {
                continue;
            }

            $purpose = $account->system_purpose;
            if (isset($byPurpose[$purpose->value])) {
                throw new DomainException("Duplicate system purpose {$purpose->value}.");
            }
            $byPurpose[$purpose->value] = $account;
            if ($account->type !== $purpose->expectedAccountType()) {
                throw new DomainException("Purpose {$purpose->value} does not have its expected account type.");
            }
            if (! $account->is_system) {
                throw new DomainException("Purpose {$purpose->value} requires is_system=true.");
            }
        }

        foreach ($accounts as $account) {
            if ($account->parent_code !== null && (string) $account->parent_code === (string) $account->code) {
                throw new DomainException("Template account {$account->code} cannot reference itself as parent.");
            }
            if ($account->parent_code !== null && ! isset($byCode[$account->parent_code])) {
                throw new DomainException("Parent {$account->parent_code} does not resolve inside the template.");
            }
        }
        $this->cloneInsertionOrder($accounts);

        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] === 'REQUIRED' && ! isset($byPurpose[$entry['purpose']->value])) {
                throw new DomainException("Missing REQUIRED purpose {$entry['purpose']->value}.");
            }
        }

        if ($scope !== null) {
            $stampPurpose = SystemAccountPurpose::SalesStampDutyPayable->value;
            if ($scope->includesTimbreCountry() && ! isset($byPurpose[$stampPurpose])) {
                throw new DomainException("Missing scope-required purpose {$stampPurpose}.");
            }
            if (! $scope->includesTimbreCountry() && isset($byPurpose[$stampPurpose])) {
                throw new DomainException("Non-timbre scope forbids purpose {$stampPurpose} to protect absorber selection.");
            }

            foreach ($scope->countryCodes() as $countryCode) {
                foreach (ProtectedAccountCodeRegistry::forCountry($countryCode) as $protected) {
                    $account = $byCode[$protected['code']] ?? null;
                    if ($account === null) {
                        throw new DomainException("Missing protected account code {$protected['code']}.");
                    }
                    if ($account->type->value !== $protected['expected_type']) {
                        throw new DomainException("Protected account {$protected['code']} has the wrong type.");
                    }
                    if ($protected['requires_system'] && ! $account->is_system) {
                        throw new DomainException("Protected account {$protected['code']} requires is_system=true.");
                    }
                }
            }
        }
    }

    /**
     * Preserve sort order among currently insertable rows while ensuring every external parent
     * exists before the immediate composite self-FK checks its child.
     *
     * @param  list<AdminTemplateAccount>  $rows
     * @return list<AdminTemplateAccount>
     */
    private function cloneInsertionOrder(array $rows): array
    {
        $pending = [];
        foreach ($rows as $row) {
            $pending[$row->code] = $row;
        }

        $ordered = [];
        $insertedCodes = [];
        while ($pending !== []) {
            $madeProgress = false;
            foreach ($pending as $code => $row) {
                $parentCode = $row->parent_code;
                if ($parentCode !== null && $parentCode !== (string) $code && ! isset($insertedCodes[$parentCode])) {
                    continue;
                }

                $ordered[] = $row;
                $insertedCodes[$code] = true;
                unset($pending[$code]);
                $madeProgress = true;
            }

            if (! $madeProgress) {
                throw new DomainException('Template account hierarchy contains a cycle or unresolved parent.');
            }
        }

        return $ordered;
    }

    private function centralConnection(): ConnectionInterface
    {
        return DB::connection((new AdminTemplate)->getConnectionName());
    }

    private function assertAuditTransaction(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new DomainException('Country Defaults audit writes require an active central transaction.');
        }
    }
}

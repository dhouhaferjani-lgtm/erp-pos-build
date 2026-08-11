<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\Services;

use App\Models\SuperAdmin;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\ValueObjects\CertificationScope;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use App\Services\AdminAuditService;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TemplateAssignmentService
{
    public function __construct(
        private readonly CountryAccountingCapabilities $capabilities,
        private readonly TemplatePublishingService $publishing,
        private readonly AdminAuditService $audit,
    ) {}

    public function assign(
        string $countryCode,
        TemplateDomain $domain,
        string $templateId,
        SuperAdmin $actor,
    ): CountryTemplateAssignment {
        $normalized = $this->normalizeCountryCode($countryCode);
        $connection = $this->centralConnection();

        return $connection->transaction(function () use ($normalized, $domain, $templateId, $actor, $connection): CountryTemplateAssignment {
            $assignment = CountryTemplateAssignment::query()
                ->where('country_code', $normalized)
                ->where('domain', $domain->value)
                ->lockForUpdate()
                ->first();

            $templateIds = [$templateId];
            if ($assignment !== null) {
                $templateIds[] = $assignment->template_id;
            }
            $templateIds = array_values(array_unique($templateIds));
            sort($templateIds, SORT_STRING);

            $templates = AdminTemplate::query()
                ->whereIn('id', $templateIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $template = $templates->get($templateId);
            if (! $template instanceof AdminTemplate) {
                throw new DomainException('Assignment template does not exist.');
            }

            if ($template->status !== TemplateStatus::Published) {
                throw new DomainException('Assignments require a published template.');
            }
            if ($template->getRawOriginal('domain') !== $domain->value) {
                throw new DomainException('Assignment domain must match the template domain.');
            }
            if ($template->capability_registry_version !== $this->capabilities->version()) {
                throw new DomainException('Assignment template certification uses a stale capability version.');
            }

            try {
                $scope = new CertificationScope(
                    $this->certifiedCountryCodes($template),
                    $this->capabilities,
                );
                if (! $scope->allowsAssignment($normalized)) {
                    throw new DomainException('Assignment country is outside the template certification scope.');
                }
            } catch (InvalidArgumentException $exception) {
                throw new DomainException($exception->getMessage(), previous: $exception);
            }

            $accounts = array_values($template->accounts()->lockForUpdate()->get()->all());
            $this->publishing->validateAccounts($accounts, $scope);

            $action = $assignment === null
                ? 'country_defaults.assignment.created'
                : 'country_defaults.assignment.repointed';
            $oldTemplateId = $assignment?->template_id;

            if ($assignment === null) {
                $assignment = CountryTemplateAssignment::query()->create([
                    'country_code' => $normalized,
                    'domain' => $domain,
                    'template_id' => $template->id,
                ]);
            } else {
                $assignment->template_id = $template->id;
                $assignment->save();
            }

            $this->assertAuditTransaction($connection);
            $this->audit->log(
                admin: $actor,
                action: $action,
                entityType: 'country_template_assignment',
                entityId: $assignment->id,
                oldValues: $oldTemplateId === null ? null : ['template_id' => $oldTemplateId],
                newValues: [
                    'country_code' => $normalized,
                    'domain' => $domain->value,
                    'template_id' => $template->id,
                ],
            );

            return $assignment->fresh() ?? $assignment;
        });
    }

    public function remove(string $countryCode, TemplateDomain $domain, SuperAdmin $actor): void
    {
        $normalized = $this->normalizeCountryCode($countryCode);
        $connection = $this->centralConnection();

        $connection->transaction(function () use ($normalized, $domain, $actor, $connection): void {
            $assignment = CountryTemplateAssignment::query()
                ->where('country_code', $normalized)
                ->where('domain', $domain->value)
                ->lockForUpdate()
                ->firstOrFail();

            if ($assignment->country_code === '*') {
                throw new DomainException('The wildcard assignment is pinned and cannot be removed.');
            }

            $oldValues = [
                'country_code' => $assignment->country_code,
                'domain' => $domain->value,
                'template_id' => $assignment->template_id,
            ];
            $assignmentId = $assignment->id;
            $assignment->delete();

            $this->assertAuditTransaction($connection);
            $this->audit->log(
                admin: $actor,
                action: 'country_defaults.assignment.removed',
                entityType: 'country_template_assignment',
                entityId: $assignmentId,
                oldValues: $oldValues,
            );
        });
    }

    /** @return list<string> */
    private function certifiedCountryCodes(AdminTemplate $template): array
    {
        $codes = $template->certified_country_codes;
        if (! is_array($codes) || $codes === []) {
            throw new DomainException('Assignment template has no certification scope.');
        }

        $strings = [];
        foreach ($codes as $code) {
            if (! is_string($code)) {
                throw new DomainException('Assignment template has an invalid certification scope.');
            }
            $strings[] = $code;
        }

        return $strings;
    }

    private function normalizeCountryCode(string $countryCode): string
    {
        $normalized = strtoupper(trim($countryCode));
        if ($normalized !== '*' && preg_match('/^[A-Z]{2}$/', $normalized) !== 1) {
            throw new InvalidArgumentException('Assignment country code must be two letters or wildcard.');
        }

        return $normalized;
    }

    private function centralConnection(): ConnectionInterface
    {
        return DB::connection((new CountryTemplateAssignment)->getConnectionName());
    }

    private function assertAuditTransaction(ConnectionInterface $connection): void
    {
        if ($connection->transactionLevel() < 1) {
            throw new DomainException('Country Defaults audit writes require an active central transaction.');
        }
    }
}

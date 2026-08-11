<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\Services;

use App\Models\SuperAdmin;
use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\ValueObjects\CertificationScope;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use App\Services\AdminAuditService;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use DomainException;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TemplateAssignmentService
{
    public function __construct(
        private readonly CountryAccountingCapabilities $capabilities,
        private readonly TemplatePublishingService $publishing,
        private readonly CanonicalCoaSerializer $serializer,
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
            if (! is_string($template->content_hash) || preg_match('/^[a-f0-9]{64}$/', $template->content_hash) !== 1) {
                throw new DomainException('Assignment template certification requires a valid content_hash.');
            }
            if (! is_string($template->standard_ref) || trim($template->standard_ref) === '') {
                throw new DomainException('Assignment template certification requires a nonblank standard_ref.');
            }
            if (! is_string($template->certified_by) || $template->certified_by === '') {
                throw new DomainException('Assignment template certification requires certified_by.');
            }
            if ($template->published_at === null) {
                throw new DomainException('Assignment template certification requires published_at.');
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
            if (! hash_equals($template->content_hash, $this->serializer->hash($this->canonicalRows($accounts)))) {
                throw new DomainException('Assignment template content_hash does not match its locked canonical rows.');
            }

            $action = $assignment === null
                ? 'country_defaults.assignment.created'
                : 'country_defaults.assignment.repointed';
            $oldTemplateId = $assignment?->template_id;

            if ($assignment === null) {
                $assignmentId = Str::uuid()->toString();
                $connection->table('country_template_assignments')->insert([
                    'id' => $assignmentId,
                    'country_code' => $normalized,
                    'domain' => $domain->value,
                    'template_id' => $template->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $assignment = CountryTemplateAssignment::query()->findOrFail($assignmentId);
            } else {
                $updated = $connection->table('country_template_assignments')
                    ->where('id', $assignment->id)
                    ->where('template_id', $oldTemplateId)
                    ->update([
                        'template_id' => $template->id,
                        'updated_at' => now(),
                    ]);
                if ($updated !== 1) {
                    throw new DomainException('Assignment changed after its lifecycle lock.');
                }
                $assignment->refresh();
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
            $deleted = $connection->table('country_template_assignments')
                ->where('id', $assignmentId)
                ->delete();
            if ($deleted !== 1) {
                throw new DomainException('Assignment changed after its lifecycle lock.');
            }

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

    /**
     * @param  list<AdminTemplateAccount>  $accounts
     * @return list<array<string, \BackedEnum|bool|int|string|null>>
     */
    private function canonicalRows(array $accounts): array
    {
        return array_map(
            static fn ($account): array => [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'parent_code' => $account->parent_code,
                'system_purpose' => $account->system_purpose,
                'is_system' => $account->is_system,
                'sort_order' => $account->sort_order,
            ],
            $accounts,
        );
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

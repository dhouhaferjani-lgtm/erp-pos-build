<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Console;

use App\Modules\CountryDefaults\Application\Services\CanonicalCoaSerializer;
use App\Modules\CountryDefaults\Application\Services\TemplatePublishingService;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\Exceptions\TemplateRecertificationRequiredException;
use App\Modules\CountryDefaults\Domain\ValueObjects\CertificationScope;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplateAccount;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;
use Illuminate\Console\Command;
use Throwable;

final class VerifyCountryDefaultsCommand extends Command
{
    protected $signature = 'country-defaults:verify';

    protected $description = 'Fail closed unless all country-default assignments and published template hashes are valid';

    public function __construct(
        private readonly CountryAccountingCapabilities $capabilities,
        private readonly CanonicalCoaSerializer $serializer,
        private readonly TemplatePublishingService $publishing,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $failures = [];
        foreach (['coa.tn.legacy-v1', 'coa.fr.legacy-v1', 'coa.generic.legacy-v1'] as $key) {
            if (! AdminTemplate::query()->where('bootstrap_key', $key)->exists()) {
                $failures[] = "Missing bootstrap template {$key}.";
            }
        }

        $assignments = CountryTemplateAssignment::query()
            ->orderBy('country_code')
            ->get();
        $assignmentByCountry = $assignments->keyBy('country_code');
        foreach (['TN', 'FR', '*'] as $requiredCountry) {
            if (! $assignmentByCountry->has($requiredCountry)) {
                $failures[] = "Missing {$requiredCountry} assignment for chart_of_accounts.";
            }
        }

        foreach ($assignments as $assignment) {
            try {
                $this->assertAssignedTemplate($assignment);
            } catch (Throwable $exception) {
                $failures[] = "Assignment {$assignment->country_code}: {$exception->getMessage()}";
            }
        }

        $assignedTemplateIds = $assignments->pluck('template_id')->all();
        $history = AdminTemplate::query()
            ->where('status', TemplateStatus::Published->value)
            ->when($assignedTemplateIds !== [], static fn ($query) => $query->whereNotIn('id', $assignedTemplateIds))
            ->orderBy('id')
            ->get();
        foreach ($history as $template) {
            try {
                $this->assertContentHash($template);
            } catch (Throwable $exception) {
                $failures[] = "Published history {$template->id}: {$exception->getMessage()}";
            }
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $this->error($failure);
            }

            return self::FAILURE;
        }

        $this->info('Country Defaults verification passed.');

        return self::SUCCESS;
    }

    private function assertAssignedTemplate(CountryTemplateAssignment $assignment): void
    {
        $template = $assignment->template()->first();
        if (! $template instanceof AdminTemplate) {
            throw new \DomainException('referenced template does not exist.');
        }
        if ($template->status !== TemplateStatus::Published) {
            throw new \DomainException('referenced template is not published.');
        }
        if ($template->getRawOriginal('domain') !== $assignment->getRawOriginal('domain')) {
            throw new \DomainException('template domain does not match assignment domain.');
        }
        $this->assertCertificationMetadata($template);
        if ($template->capability_registry_version !== $this->capabilities->version()) {
            throw TemplateRecertificationRequiredException::forAssignment(
                $assignment->country_code,
                (string) $template->capability_registry_version,
                $this->capabilities->version(),
            );
        }

        $scope = new CertificationScope($this->scopeCodes($template), $this->capabilities);
        if (! $scope->allowsAssignment($assignment->country_code)) {
            throw new \DomainException('certification scope does not cover the assignment.');
        }
        $accounts = array_values($template->accounts()->orderBy('sort_order')->get()->all());
        $this->publishing->validateAccounts($accounts, $scope);
        $this->assertContentHash($template, $accounts);
    }

    private function assertCertificationMetadata(AdminTemplate $template): void
    {
        if (! is_string($template->standard_ref) || trim($template->standard_ref) === '') {
            throw new \DomainException('standard_ref is missing.');
        }
        if (! is_string($template->certified_by) || $template->certified_by === '') {
            throw new \DomainException('certified_by is missing.');
        }
        if ($template->published_at === null) {
            throw new \DomainException('published_at is missing.');
        }
        if (! is_string($template->capability_registry_version) || $template->capability_registry_version === '') {
            throw new \DomainException('capability_registry_version is missing.');
        }
        if (! is_string($template->content_hash) || preg_match('/^[a-f0-9]{64}$/', $template->content_hash) !== 1) {
            throw new \DomainException('content_hash is missing or invalid.');
        }
    }

    /** @param null|list<AdminTemplateAccount> $accounts */
    private function assertContentHash(AdminTemplate $template, ?array $accounts = null): void
    {
        if (! is_string($template->content_hash) || preg_match('/^[a-f0-9]{64}$/', $template->content_hash) !== 1) {
            throw new \DomainException('content_hash is missing or invalid.');
        }
        $accounts ??= array_values($template->accounts()->orderBy('sort_order')->get()->all());
        $actual = $this->serializer->hash(array_map(
            static fn (AdminTemplateAccount $account): array => [
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'parent_code' => $account->parent_code,
                'system_purpose' => $account->system_purpose,
                'is_system' => $account->is_system,
                'sort_order' => $account->sort_order,
            ],
            $accounts,
        ));
        if (! hash_equals($template->content_hash, $actual)) {
            throw new \DomainException('content_hash does not match canonical rows.');
        }
    }

    /** @return list<string> */
    private function scopeCodes(AdminTemplate $template): array
    {
        if (! is_array($template->certified_country_codes) || $template->certified_country_codes === []) {
            throw new \DomainException('certified_country_codes is missing.');
        }
        $codes = [];
        foreach ($template->certified_country_codes as $code) {
            if (! is_string($code)) {
                throw new \DomainException('certified_country_codes contains a non-string value.');
            }
            $codes[] = $code;
        }

        return $codes;
    }
}

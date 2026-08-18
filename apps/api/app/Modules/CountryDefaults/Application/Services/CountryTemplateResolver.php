<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Application\Services;

use App\Modules\CountryDefaults\Domain\Enums\TemplateDomain;
use App\Modules\CountryDefaults\Domain\Enums\TemplateStatus;
use App\Modules\CountryDefaults\Domain\Exceptions\MissingCountryTemplateAssignmentException;
use App\Modules\CountryDefaults\Domain\Exceptions\TemplateRecertificationRequiredException;
use App\Modules\CountryDefaults\Domain\Exceptions\TimbreCountryRequiresExactAssignmentException;
use App\Modules\CountryDefaults\Domain\Exceptions\UnpublishedAssignedTemplateException;
use App\Modules\CountryDefaults\Infrastructure\Models\AdminTemplate;
use App\Modules\CountryDefaults\Infrastructure\Models\CountryTemplateAssignment;
use App\Shared\Contracts\CountryDefaults\CountryAccountingCapabilities;

final class CountryTemplateResolver
{
    public function __construct(private readonly CountryAccountingCapabilities $capabilities) {}

    public function resolve(TemplateDomain $domain, string $countryCode): AdminTemplate
    {
        $normalized = strtoupper(trim($countryCode));

        $exact = $this->assignment($domain, $normalized);
        if ($exact instanceof CountryTemplateAssignment) {
            return $this->verifiedTemplate($exact, $normalized);
        }

        if ($this->capabilities->supportsStampDuty($normalized)) {
            throw TimbreCountryRequiresExactAssignmentException::forCountry($normalized);
        }

        $wildcard = $this->assignment($domain, '*');
        if (! $wildcard instanceof CountryTemplateAssignment) {
            throw MissingCountryTemplateAssignmentException::wildcard($domain);
        }

        return $this->verifiedTemplate($wildcard, '*');
    }

    private function assignment(TemplateDomain $domain, string $countryCode): ?CountryTemplateAssignment
    {
        return CountryTemplateAssignment::query()
            ->where('country_code', $countryCode)
            ->where('domain', $domain->value)
            ->with('template')
            ->first();
    }

    private function verifiedTemplate(
        CountryTemplateAssignment $assignment,
        string $countryCode,
    ): AdminTemplate {
        $template = $assignment->template;
        if (! $template instanceof AdminTemplate || $template->status !== TemplateStatus::Published) {
            throw UnpublishedAssignedTemplateException::forCountry($countryCode);
        }

        $storedVersion = (string) $template->capability_registry_version;
        $currentVersion = $this->capabilities->version();
        if ($storedVersion !== $currentVersion) {
            throw TemplateRecertificationRequiredException::forAssignment(
                $countryCode,
                $storedVersion,
                $currentVersion,
            );
        }

        return $template;
    }
}

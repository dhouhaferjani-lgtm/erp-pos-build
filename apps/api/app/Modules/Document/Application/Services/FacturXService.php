<?php

declare(strict_types=1);

namespace App\Modules\Document\Application\Services;

use App\Modules\Company\Application\Services\TaxIdentityResolver;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FacturXProfile;
use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\Company\TaxIdentityData;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;

final class FacturXService
{
    public function __construct(
        private readonly TaxIdentityResolver $taxIdentityResolver,
    ) {}

    /**
     * Check if a document is eligible for Factur-X generation.
     *
     * Eligibility criteria:
     * - Must be an invoice (DocumentType::Invoice)
     * - Company must be French (country_code === 'FR')
     * - Partner must have a VAT number (SIRET/SIREN/TVA)
     * - Document must not already have Factur-X XML generated
     */
    public function isEligible(Document $document): bool
    {
        // Must be an invoice
        if ($document->type !== DocumentType::Invoice) {
            return false;
        }

        // Must not already have Factur-X XML
        if ($document->facturx_xml !== null) {
            return false;
        }

        // Company must be loaded and French
        $company = $document->company;
        /** @phpstan-ignore identical.alwaysFalse */
        if ($company === null || $company->country_code !== 'FR') {
            return false;
        }

        // Partner must have a VAT number (B2B indicator)
        $partner = $document->partner;
        /** @phpstan-ignore identical.alwaysFalse */
        if ($partner === null || $partner->vat_number === null || $partner->vat_number === '') {
            return false;
        }

        return true;
    }

    /**
     * Get the Factur-X profile used for generation.
     */
    public function getProfile(): FacturXProfile
    {
        return FacturXProfile::BasicWL;
    }

    /**
     * Generate Factur-X Basic WL XML for a document.
     *
     * @throws \InvalidArgumentException If the document is not eligible
     */
    public function generateXml(Document $document): string
    {
        $document->loadMissing(['company', 'partner', 'lines', 'location']);

        /** @var Company $company */
        $company = $document->company;

        /** @var Partner $partner */
        $partner = $document->partner;

        $builder = ZugferdDocumentBuilder::createNew(ZugferdProfiles::PROFILE_BASICWL);

        $this->setDocumentHeader($builder, $document);
        $this->setSellerInformation($builder, $company, $document->location);
        $this->setBuyerInformation($builder, $partner);
        $this->setTaxInformation($builder, $document);
        $this->setDocumentTotals($builder, $document);

        return $builder->getContent();
    }

    /**
     * Set document header information.
     */
    private function setDocumentHeader(ZugferdDocumentBuilder $builder, Document $document): void
    {
        $builder->setDocumentInformation(
            $document->document_number,
            '380', // UN/CEFACT code for Commercial Invoice
            $document->document_date,
            $document->currency ?? 'EUR',
        );

        if ($document->notes !== null && $document->notes !== '') {
            $builder->addDocumentNote($document->notes);
        }
    }

    /**
     * Set seller (company) information.
     */
    private function setSellerInformation(ZugferdDocumentBuilder $builder, Company $company, ?Location $location): void
    {
        $identity = $location !== null
            ? $this->taxIdentityResolver->resolve($location)
            : $this->companyTaxIdentity($company);

        $builder->setDocumentSeller($company->legal_name ?? $company->name);

        $builder->setDocumentSellerAddress(
            $company->address_street,
            $company->address_street_2,
            null,
            $company->address_postal_code,
            $company->address_city,
            $company->country_code,
        );

        if ($identity->vatNumber !== null) {
            $builder->addDocumentSellerTaxRegistration('VA', $identity->vatNumber);
        }

        if ($identity->taxId !== null) {
            $builder->addDocumentSellerTaxRegistration('FC', $identity->taxId);
        }

        $siret = $identity->legalIdentifiers['siret'] ?? null;
        if (is_string($siret)) {
            $builder->setDocumentSellerLegalOrganisation($siret, '0002', $company->legal_name ?? $company->name);
        }

        if ($company->email !== null || $company->phone !== null) {
            $builder->setDocumentSellerContact(
                null,
                null,
                $company->phone,
                null,
                $company->email,
            );
        }
    }

    private function companyTaxIdentity(Company $company): TaxIdentityData
    {
        return new TaxIdentityData(
            taxId: $company->tax_id,
            vatNumber: $company->vat_number,
            legalIdentifiers: $this->legalIdentifiers($company->legal_identifiers),
            countryCode: $company->country_code,
        );
    }

    /**
     * @param  array<string, string|int|float|bool|null>|null  $identifiers
     * @return array<string, string|int|float|bool|null>
     */
    private function legalIdentifiers(?array $identifiers): array
    {
        return $identifiers ?? [];
    }

    /**
     * Set buyer (partner) information.
     */
    private function setBuyerInformation(ZugferdDocumentBuilder $builder, Partner $partner): void
    {
        $builder->setDocumentBuyer($partner->company_legal_name ?? $partner->name);

        $builder->setDocumentBuyerAddress(
            $partner->street_address,
            $partner->street_address_2,
            null,
            $partner->postal_code,
            $partner->city,
            $partner->country_code ?? 'FR',
        );

        if ($partner->vat_number !== null) {
            $builder->addDocumentBuyerTaxRegistration('VA', $partner->vat_number);
        }

        if ($partner->business_registration_number !== null) {
            $builder->setDocumentBuyerLegalOrganisation(
                $partner->business_registration_number,
                '0002',
                $partner->company_legal_name ?? $partner->name,
            );
        }

        if ($partner->email !== null || $partner->phone !== null) {
            $builder->setDocumentBuyerContact(
                null,
                null,
                $partner->phone,
                null,
                $partner->email,
            );
        }
    }

    /**
     * Set tax breakdown information.
     *
     * Groups document lines by tax rate and adds a tax entry for each group.
     */
    private function setTaxInformation(ZugferdDocumentBuilder $builder, Document $document): void
    {
        // Group lines by tax rate for the tax breakdown
        /** @var array<string, array{basis: numeric-string, tax: numeric-string, rate: numeric-string}> $taxGroups */
        $taxGroups = [];

        foreach ($document->lines as $line) {
            /** @var DocumentLine $line */
            $rate = $line->tax_rate ?? '0.000';
            $lineNet = bcmul($line->quantity, $line->unit_price, 3);

            if (! isset($taxGroups[$rate])) {
                $taxGroups[$rate] = [
                    'basis' => '0.000',
                    'tax' => '0.000',
                    'rate' => $rate,
                ];
            }

            $taxGroups[$rate]['basis'] = bcadd($taxGroups[$rate]['basis'], $lineNet, 3);
            $taxAmount = bcdiv(bcmul($lineNet, $rate, 6), '100', 3);
            $taxGroups[$rate]['tax'] = bcadd($taxGroups[$rate]['tax'], $taxAmount, 3);
        }

        foreach ($taxGroups as $group) {
            $categoryCode = bccomp($group['rate'], '0', 3) === 0 ? 'Z' : 'S';

            $builder->addDocumentTax(
                $categoryCode,
                'VAT',
                (float) $group['basis'],
                (float) $group['tax'],
                (float) $group['rate'],
            );
        }
    }

    /**
     * Set document monetary totals (summation).
     */
    private function setDocumentTotals(ZugferdDocumentBuilder $builder, Document $document): void
    {
        $subtotal = (float) ($document->subtotal ?? '0.000');
        $taxAmount = (float) ($document->tax_amount ?? '0.000');
        $total = (float) ($document->total ?? '0.000');
        $balanceDue = (float) ($document->balance_due ?? $document->total ?? '0.000');

        $builder->setDocumentSummation(
            $total,
            $balanceDue,
            $subtotal,
            0.00,
            0.00,
            $subtotal,
            $taxAmount,
        );
    }
}

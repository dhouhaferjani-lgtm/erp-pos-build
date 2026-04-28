<?php

declare(strict_types=1);

namespace App\Modules\Progression\Infrastructure\Listeners;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Events\CompanyCreated;
use App\Modules\Progression\Application\Contracts\GrowthAdvisorClientInterface;
use Illuminate\Support\Facades\Log;

final class RegisterCompanyWithGrowthAdvisor
{
    public function __construct(
        private readonly GrowthAdvisorClientInterface $client,
    ) {}

    public function handle(CompanyCreated $event): void
    {
        try {
            // Query company to get vertical (not in the event -- events are immutable)
            $company = Company::find($event->companyId);
            $vertical = $company?->vertical->value ?? 'unknown';
            $product = $this->determineProduct($vertical);

            $result = $this->client->registerCompany([
                'company_id' => $event->companyId,
                'tenant_id' => $event->tenantId,
                'vertical' => $vertical,
                'country' => $event->countryCode,
                'product' => $product,
            ]);

            if ($result === null) {
                Log::warning('Growth Advisor registration failed — service unavailable', [
                    'company_id' => $event->companyId,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Growth Advisor registration failed', [
                'company_id' => $event->companyId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function determineProduct(string $vertical): string
    {
        $automotiveVerticals = ['mechanic', 'body_shop', 'car_glass', 'tire_shop', 'parts_retailer', 'service_station'];

        return in_array($vertical, $automotiveVerticals, true) ? 'otospex' : 'izipos';
    }
}

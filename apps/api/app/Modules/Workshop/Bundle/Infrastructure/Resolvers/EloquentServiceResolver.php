<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Bundle\Infrastructure\Resolvers;

use App\Modules\Service\Domain\Service;
use App\Modules\Workshop\Bundle\Domain\Contracts\ServiceResolverInterface;
use App\Modules\Workshop\Bundle\Domain\ValueObjects\ComponentServiceRef;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Str;

final class EloquentServiceResolver implements ServiceResolverInterface
{
    public function findForBundleComponent(string $serviceId): ?ComponentServiceRef
    {
        if (! Str::isUuid($serviceId)) {
            return null;
        }

        $service = Service::query()->find($serviceId);

        if ($service === null) {
            return null;
        }

        $scale = CurrencyScale::for($service->currency);

        return new ComponentServiceRef(
            service_id: $service->id,
            display_name: $service->name,
            pricing_type: $service->pricing_type,
            base_price: CurrencyScale::bcformat($service->base_price, $scale),
            hourly_rate: $service->hourly_rate !== null
                ? CurrencyScale::bcformat($service->hourly_rate, $scale)
                : null,
            currency: $service->currency,
            tax_rate: $service->tax_rate !== null
                ? CurrencyScale::bcformat($service->tax_rate, 2)
                : null,
            default_duration_minutes: $service->default_duration_minutes,
        );
    }
}

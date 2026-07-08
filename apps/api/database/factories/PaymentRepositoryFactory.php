<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentRepository>
 */
class PaymentRepositoryFactory extends Factory
{
    protected $model = PaymentRepository::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'company_id' => null,
            'code' => strtoupper(Str::random(6)),
            'name' => $this->faker->words(2, true),
            'type' => RepositoryType::CashRegister,
            'balance' => '0.00',
            'is_active' => true,
            // MED-11: currency defaults to the owning company's currency.
            // Relies on 'company_id' having already been resolved (e.g. via
            // ->for($company)) earlier in this definition array. When
            // company_id is unresolved (or the company can't be found), fall
            // back to a legible default instead of null -- a null here fails
            // opaquely against the NOT NULL constraint on pgsql.
            'currency' => function (array $attributes): string {
                if (! isset($attributes['company_id']) || ! is_string($attributes['company_id'])) {
                    return 'TND';
                }

                $company = Company::query()->find($attributes['company_id']);

                return $company instanceof Company ? $company->currency : 'TND';
            },
        ];
    }
}

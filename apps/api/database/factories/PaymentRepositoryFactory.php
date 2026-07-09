<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Company\Domain\Company;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /**
     * Persist the built repositories, bracketing the INSERT with the port GUC.
     *
     * Test/seed fixtures may scaffold a non-zero STARTING balance (a repository's
     * initial state). In production repositories are BORN at balance 0 — the
     * Task-22 INSERT trigger rejects a non-zero balance minted with no backing
     * movement (money enters only via the movement port). Bracketing the fixture
     * INSERT with `app.treasury_movement_port = 'on'` lets a scaffolded starting
     * balance persist WITHOUT laying down a phantom opening movement, so the
     * ordinal / movement-count history the treasury tests assert against is left
     * untouched. This lives in the factory (test/seed infrastructure), is confined
     * to the single INSERT (reset to 'off' in `finally`), and never runs in
     * production code. `SET LOCAL` needs an active transaction (always true under
     * RefreshDatabase); with a zero starting balance the bracket is a harmless
     * no-op — the model default already satisfies the guard, so a fixture built
     * outside a transaction still works for the common (zero-balance) case.
     *
     * @param  Collection<int, Model>  $results
     */
    protected function store(Collection $results): void
    {
        $bracket = DB::connection()->getDriverName() === 'pgsql' && DB::transactionLevel() > 0;

        if (! $bracket) {
            parent::store($results);

            return;
        }

        try {
            DB::statement("SET LOCAL app.treasury_movement_port = 'on'");
            parent::store($results);
        } finally {
            DB::statement("SET LOCAL app.treasury_movement_port = 'off'");
        }
    }
}

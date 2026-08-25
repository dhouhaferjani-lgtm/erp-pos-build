<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = $this->faker->firstName();
        $lastName = $this->faker->lastName();

        return [
            'id' => Str::uuid()->toString(),
            'name' => $this->faker->company(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'preferred_locale' => 'fr',
            // LEDGER C-7 (residual R-3). `faker->unique()` only de-duplicates the
            // company NAME; `Str::slug()` then collapses distinct names onto the
            // same slug ("Smith & Sons" and "Smith and Sons" both become
            // "smith-sons"), which trips `tenants_slug_unique` nondeterministically
            // in long multi-class runs. Append a random discriminator so the slug
            // is unique by construction while staying human-readable.
            'slug' => Str::slug($this->faker->company()).'-'.Str::lower(Str::random(8)),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'timezone' => 'Europe/Paris',
            'date_format' => 'd/m/Y',
            'locale' => 'fr_FR',
            'settings' => [],
            'trial_ends_at' => now()->addDays(30),
            'subscription_ends_at' => now()->addYear(),
        ];
    }

    /**
     * Indicate that the tenant is in trial.
     */
    public function trial(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => SubscriptionPlan::Trial,
            'trial_ends_at' => now()->addDays(14),
            'subscription_ends_at' => null,
        ]);
    }

    /**
     * Indicate that the tenant is suspended.
     */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => TenantStatus::Suspended,
        ]);
    }

    /**
     * Indicate that the tenant is on enterprise plan.
     */
    public function enterprise(): static
    {
        return $this->state(fn (array $attributes) => [
            'plan' => SubscriptionPlan::Enterprise,
        ]);
    }
}

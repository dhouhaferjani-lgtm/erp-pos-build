<?php

declare(strict_types=1);

namespace Database\Factories\Loyalty;

use App\Modules\Loyalty\Domain\Entities\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Enums\ProgramStatus;
use App\Modules\Loyalty\Domain\Enums\ProgramType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoyaltyProgram>
 */
class LoyaltyProgramFactory extends Factory
{
    protected $model = LoyaltyProgram::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => $this->faker->uuid(),
            'company_ids' => null, // null = applies to all companies
            'name' => $this->faker->words(3, true).' Rewards',
            'program_type' => $this->faker->randomElement(ProgramType::cases()),
            'status' => ProgramStatus::Draft,
            'currency' => $this->faker->randomElement(['Points', 'Stars', 'Stamps', 'Credits']),
            'start_date' => null,
            'end_date' => null,
            'terms_and_conditions' => $this->faker->optional()->paragraph(),
            'metadata' => null,
        ];
    }

    /**
     * Program with active status
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProgramStatus::Active,
            'start_date' => now()->subMonth(),
        ]);
    }

    /**
     * Program for specific companies
     *
     * @param  array<int, string>  $companyIds
     */
    public function forCompanies(array $companyIds): static
    {
        return $this->state(fn (array $attributes) => [
            'company_ids' => $companyIds,
        ]);
    }

    /**
     * Points-based program
     */
    public function points(): static
    {
        return $this->state(fn (array $attributes) => [
            'program_type' => ProgramType::Points,
            'currency' => 'Points',
        ]);
    }

    /**
     * Stamp card program
     */
    public function stamps(): static
    {
        return $this->state(fn (array $attributes) => [
            'program_type' => ProgramType::Stamps,
            'currency' => 'Stamps',
        ]);
    }
}

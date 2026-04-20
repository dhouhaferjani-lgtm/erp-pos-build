<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\POS\Domain\Terminal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Terminal>
 */
class TerminalFactory extends Factory
{
    protected $model = Terminal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => null,
            'company_id' => null,
            'location_id' => null,
            'code' => 'POS'.str_pad((string) $this->faker->unique()->numberBetween(1, 999), 2, '0', STR_PAD_LEFT),
            'name' => 'Terminal '.$this->faker->numberBetween(1, 99),
            'genesis_seed' => bin2hex(random_bytes(32)),
            'current_sequence' => 1,
            'current_year' => (int) date('Y'),
            'is_active' => true,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories\Workshop;

use App\Modules\Workshop\Technician\Domain\TechnicianCertification;
use App\Modules\Workshop\Technician\Domain\TechnicianProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TechnicianCertification>
 */
final class TechnicianCertificationFactory extends Factory
{
    /** @var class-string<TechnicianCertification> */
    protected $model = TechnicianCertification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $profile = TechnicianProfile::factory();

        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => fn (array $attrs): string => (string) TechnicianProfile::query()
                ->whereKey($attrs['technician_profile_id'])
                ->value('tenant_id'),
            'technician_profile_id' => $profile,
            'certification_name' => $this->faker->randomElement([
                'ASE Master Technician',
                'Hybrid/Electric Vehicle Specialist',
                'Air Conditioning Refrigerant Certification',
                'Contrôle technique Agréé',
            ]),
            'issuing_body' => $this->faker->randomElement(['ASE', 'I-CAR', 'Bosch Academy', 'UTAC']),
            'certificate_number' => strtoupper(Str::random(10)),
            'issued_at' => $this->faker->dateTimeBetween('-5 years', '-1 year')->format('Y-m-d'),
            'expires_at' => $this->faker->dateTimeBetween('+30 days', '+3 years')->format('Y-m-d'),
            'notes' => null,
        ];
    }

    public function expiringSoon(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->addDays(15)->format('Y-m-d'),
        ]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDays(10)->format('Y-m-d'),
        ]);
    }
}

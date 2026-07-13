<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Bank;
use Illuminate\Database\Seeder;
use JsonException;
use RuntimeException;

final class BanksSeeder extends Seeder
{
    public function run(?Company $company = null): void
    {
        $company ??= Company::query()->first();
        if (! $company instanceof Company) {
            return;
        }

        $tenant = Tenant::query()->find($company->tenant_id);
        if (! $tenant instanceof Tenant) {
            return;
        }

        $countryCode = strtoupper($company->country_code);
        $path = database_path("data/banks/{$countryCode}.json");
        if (! is_file($path)) {
            return;
        }

        foreach ($this->loadBanks($path) as $bank) {
            $identity = [
                'tenant_id' => $tenant->id,
                'country_code' => $countryCode,
                $bank['rib_bank_code'] !== null ? 'rib_bank_code' : 'name' => $bank['rib_bank_code'] ?? $bank['name'],
            ];

            $canonicalAttributes = [
                'name' => $bank['name'],
                'short_name' => $bank['short_name'],
                'bic' => $bank['bic'],
                'rib_bank_code' => $bank['rib_bank_code'],
                'city' => $bank['city'],
                'is_active' => true,
                'is_custom' => false,
                'position' => $bank['position'],
            ];

            $existing = Bank::query()->where($identity)->first();
            if ($existing instanceof Bank) {
                $existing->update([
                    'bic' => $bank['bic'],
                    'position' => $bank['position'],
                    'city' => $bank['city'],
                ]);

                continue;
            }

            Bank::query()->create([...$identity, ...$canonicalAttributes]);
        }
    }

    /**
     * @return array<int, array{
     *     name: string,
     *     short_name: string|null,
     *     bic: string|null,
     *     rib_bank_code: string|null,
     *     city: string|null,
     *     position: int
     * }>
     */
    private function loadBanks(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read bank data file: {$path}");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid bank data file: {$path}", previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("Bank data file must contain an array: {$path}");
        }

        /** @var array<int, array{name: string, short_name: string|null, bic: string|null, rib_bank_code: string|null, city: string|null, position: int}> $decoded */
        return $decoded;
    }
}

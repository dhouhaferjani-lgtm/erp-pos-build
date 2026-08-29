<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Shared\Database\MigrationOutput;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** @var list<string> */
    private const TRANSLATED_LOCALES = ['en', 'fr', 'ar'];

    /**
     * @var array<string, list<string>>
     */
    private const REQUIRED_COLUMNS = [
        'payment_repositories' => [
            'id', 'tenant_id', 'company_id', 'code', 'name', 'type', 'allow_negative',
            'balance', 'currency', 'location_id', 'account_id', 'gl_account_id',
            'is_active', 'created_at', 'updated_at',
        ],
        'companies' => ['id', 'tenant_id', 'currency', 'locale'],
        'locations' => ['id', 'company_id', 'is_default', 'is_active', 'pos_enabled', 'created_at'],
        'accounts' => ['id', 'company_id', 'system_purpose'],
    ];

    public function up(): void
    {
        try {
            if (! $this->hasRequiredSchema() || DB::table('companies')->doesntExist()) {
                return;
            }

            $companyCount = DB::table('companies')->count();
            $emptyCount = DB::table('companies')
                ->whereNotExists(static function ($query): void {
                    $query->selectRaw('1')
                        ->from('payment_repositories')
                        ->whereColumn('payment_repositories.company_id', 'companies.id');
                })
                ->count();
            $census = [
                'companies' => $companyCount,
                'empty' => $emptyCount,
            ];

            Log::info('payment_repositories.census', $census);
            $line = "payment-repositories-census companies={$companyCount} empty={$emptyCount}";
            MigrationOutput::info($line);

            // Intentionally inline: deployed migrations must remain runnable if
            // the application provisioning service is refactored later.
            $companies = DB::table('companies')
                ->select(['id', 'tenant_id', 'currency', 'locale'])
                ->orderBy('id')
                ->get();

            foreach ($companies as $company) {
                if (! is_string($company->id)
                    || ! is_string($company->tenant_id)
                    || ! is_string($company->currency)
                    || ! is_string($company->locale)) {
                    continue;
                }

                $this->provisionCompany(
                    $company->id,
                    $company->tenant_id,
                    $company->currency,
                    $company->locale,
                );
            }
        } catch (Throwable $exception) {
            Log::error('payment_repositories.backfill_failed', [
                'exception' => $exception,
            ]);
        }
    }

    private function hasRequiredSchema(): bool
    {
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return false;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function provisionCompany(
        string $companyId,
        string $tenantId,
        string $currency,
        string $locale,
    ): void {
        try {
            $inserted = DB::transaction(function () use ($companyId, $currency, $locale, $tenantId): bool {
                $existingCodes = DB::table('payment_repositories')
                    ->where('company_id', $companyId)
                    ->whereIn('code', ['CASH-01', 'SAFE-01'])
                    ->pluck('code')
                    ->map(static fn ($code): string => (string) $code)
                    ->all();
                $missing = array_values(array_diff(['CASH-01', 'SAFE-01'], $existingCodes));
                if ($missing === []) {
                    return false;
                }

                $cashAccountId = DB::table('accounts')
                    ->where('company_id', $companyId)
                    ->where('system_purpose', SystemAccountPurpose::Cash->value)
                    ->value('id');
                $cashAccountId = is_string($cashAccountId) ? $cashAccountId : null;
                if ($cashAccountId === null) {
                    Log::warning('payment_repositories.cash_account_missing', [
                        'company_id' => $companyId,
                    ]);
                }

                $locationId = DB::table('locations')
                    ->where('company_id', $companyId)
                    ->orderByDesc('is_default')
                    ->orderByDesc('is_active')
                    ->orderByDesc('pos_enabled')
                    ->orderBy('created_at')
                    ->value('id');
                $locationId = is_string($locationId) ? $locationId : null;
                $language = strtolower(explode('-', str_replace('_', '-', $locale))[0]);
                $resolvedLocale = in_array($language, self::TRANSLATED_LOCALES, true) ? $language : 'en';
                $now = now();

                foreach ($this->defaultRepositories($resolvedLocale) as $repository) {
                    if (! in_array($repository['code'], $missing, true)) {
                        continue;
                    }

                    DB::table('payment_repositories')->insert([
                        'id' => (string) Str::uuid(),
                        'tenant_id' => $tenantId,
                        'company_id' => $companyId,
                        'code' => $repository['code'],
                        'name' => $repository['name'],
                        'type' => $repository['type'],
                        'allow_negative' => false,
                        'balance' => '0',
                        'currency' => $currency,
                        'location_id' => $locationId,
                        'account_id' => $cashAccountId,
                        'gl_account_id' => $cashAccountId,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                return true;
            });

            if (! $inserted) {
                return;
            }

            $line = "payment-repositories-seeded company_id={$companyId}";
            MigrationOutput::info($line);
        } catch (Throwable $exception) {
            Log::error('payment_repositories.backfill_company_failed', [
                'company_id' => $companyId,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * @return list<array{code: string, name: string, type: string}>
     */
    private function defaultRepositories(string $locale): array
    {
        return [
            [
                'code' => 'CASH-01',
                'name' => trans('treasury.default_repositories.cash_register', [], $locale),
                'type' => RepositoryType::CashRegister->value,
            ],
            [
                'code' => 'SAFE-01',
                'name' => trans('treasury.default_repositories.safe', [], $locale),
                'type' => RepositoryType::Safe->value,
            ],
        ];
    }

    /**
     * Forward-only no-op: repositories may already carry movements, and this
     * migration cannot distinguish its rows from repositories created by an
     * operator after deployment.
     */
    public function down(): void
    {
        Log::info('payment_repositories.backfill_down_noop');
    }
};

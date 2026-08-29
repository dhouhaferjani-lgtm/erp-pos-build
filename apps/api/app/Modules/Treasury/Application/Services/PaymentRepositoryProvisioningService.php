<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Shared\Contracts\Treasury\CompanyPaymentRepositoryProvisionerInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class PaymentRepositoryProvisioningService implements CompanyPaymentRepositoryProvisionerInterface
{
    /** @var list<string> */
    private const TRANSLATED_LOCALES = ['en', 'fr', 'ar'];

    private const FALLBACK_LOCALE = 'en';

    /**
     * The port is scalar-typed (the shared kernel may not depend on a module
     * tier). Resolving the `Company` model happens HERE, inside Treasury's own
     * Application tier, where `ModuleApplication -> ModuleDomain` is an allowed
     * deptrac edge.
     */
    public function provisionForCompany(string $tenantId, string $companyId, ?string $defaultLocationId = null): void
    {
        // Every read and write runs in its OWN nested transaction. A failure in
        // CompanyController::store() therefore rolls back only to this SAVEPOINT;
        // PostgreSQL's aborted-transaction state never reaches the controller's
        // outer company/location/membership transaction.
        try {
            DB::transaction(function () use ($companyId, $defaultLocationId, $tenantId): void {
                $company = Company::query()->find($companyId);
                if (! $company instanceof Company) {
                    Log::warning('payment_repositories.company_missing', [
                        'company_id' => $companyId,
                    ]);

                    return;
                }

                $cashAccount = Account::findByPurpose($companyId, SystemAccountPurpose::Cash);
                if (! $cashAccount instanceof Account) {
                    Log::warning('payment_repositories.cash_account_missing', [
                        'company_id' => $companyId,
                    ]);
                }

                $locationId = $defaultLocationId ?? $this->defaultLocationId($companyId);

                foreach ($this->defaultRepositories($company) as $repository) {
                    if ($this->codeExists($companyId, $repository['code'])) {
                        continue;
                    }

                    PaymentRepository::forceCreate([
                        'tenant_id' => $tenantId,
                        'company_id' => $companyId,
                        'account_id' => $cashAccount?->id,
                        'gl_account_id' => $cashAccount?->id,
                        'location_id' => $locationId,
                        ...$repository,
                    ]);
                }
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return;
            }

            $this->logFailure($companyId, $exception);
        } catch (Throwable $exception) {
            $this->logFailure($companyId, $exception);
        }
    }

    private function codeExists(string $companyId, string $code): bool
    {
        return PaymentRepository::query()
            ->where('company_id', $companyId)
            ->where('code', $code)
            ->exists();
    }

    private function defaultLocationId(string $companyId): ?string
    {
        $locationId = Location::query()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderByDesc('pos_enabled')
            ->orderBy('created_at')
            ->value('id');

        return is_string($locationId) ? $locationId : null;
    }

    /**
     * @return list<array{code: string, name: string, type: string, is_active: bool}>
     */
    private function defaultRepositories(Company $company): array
    {
        $locale = $this->resolveLocale($company);

        return [
            [
                'code' => 'CASH-01',
                'name' => trans('treasury.default_repositories.cash_register', [], $locale),
                'type' => RepositoryType::CashRegister->value,
                'is_active' => true,
            ],
            [
                'code' => 'SAFE-01',
                'name' => trans('treasury.default_repositories.safe', [], $locale),
                'type' => RepositoryType::Safe->value,
                'is_active' => true,
            ],
        ];
    }

    private function resolveLocale(Company $company): string
    {
        $language = strtolower(explode('-', str_replace('_', '-', $company->locale))[0]);

        return in_array($language, self::TRANSLATED_LOCALES, true)
            ? $language
            : self::FALLBACK_LOCALE;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }

    private function logFailure(string $companyId, Throwable $exception): void
    {
        Log::error('payment_repositories.provisioning_failed', [
            'company_id' => $companyId,
            'exception' => $exception,
        ]);
    }
}

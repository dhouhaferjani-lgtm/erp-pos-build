<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\Services;

use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use DomainException;

final readonly class OutboundRepositoryValidator
{
    public function validate(
        string $repositoryId,
        string $tenantId,
        string $companyId,
        string $currency,
        ?string $instrumentBankId,
    ): PaymentRepository {
        $repository = PaymentRepository::query()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($repositoryId)
            ->first();

        if (! $repository instanceof PaymentRepository) {
            throw new DomainException('Outbound instrument repository was not found in the tenant and company.');
        }
        if ($repository->type !== RepositoryType::BankAccount) {
            throw new DomainException('Outbound instruments can only clear through a bank-account repository.');
        }
        if (! $repository->is_active) {
            throw new DomainException('Outbound instrument repository is inactive.');
        }
        if ($repository->gl_account_id === null) {
            throw new DomainException('Outbound instrument repository has no GL account.');
        }
        if ($repository->currency !== strtoupper($currency)) {
            throw new DomainException('Outbound instrument currency does not match the repository currency.');
        }
        if ($instrumentBankId !== null && $instrumentBankId !== $repository->bank_id) {
            throw new DomainException('Outbound instrument bank does not match the repository bank.');
        }

        return $repository;
    }
}

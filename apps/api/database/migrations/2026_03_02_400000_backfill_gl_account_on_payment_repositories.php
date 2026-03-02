<?php

declare(strict_types=1);

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $repositories = DB::table('payment_repositories')
            ->whereNull('gl_account_id')
            ->get();

        if ($repositories->isEmpty()) {
            return;
        }

        // Cache account lookups per company
        /** @var array<string, array{cash: string|null, bank: string|null}> $accountCache */
        $accountCache = [];

        foreach ($repositories as $repo) {
            $companyId = $repo->company_id;

            if (! isset($accountCache[$companyId])) {
                $cashAccount = Account::findByPurpose($companyId, SystemAccountPurpose::Cash);
                $bankAccount = Account::findByPurpose($companyId, SystemAccountPurpose::Bank);
                $accountCache[$companyId] = [
                    'cash' => $cashAccount?->id,
                    'bank' => $bankAccount?->id,
                ];
            }

            $type = RepositoryType::from($repo->type);
            $glAccountId = match ($type) {
                RepositoryType::CashRegister, RepositoryType::Safe => $accountCache[$companyId]['cash'],
                RepositoryType::BankAccount, RepositoryType::Virtual => $accountCache[$companyId]['bank'],
            };

            if ($glAccountId === null) {
                Log::warning("Cannot backfill gl_account_id for payment repository {$repo->code} (company {$companyId}): no GL account found");

                continue;
            }

            DB::table('payment_repositories')
                ->where('id', $repo->id)
                ->update(['gl_account_id' => $glAccountId]);
        }
    }

    public function down(): void
    {
        // No rollback — removing GL links could break accounting integrity
    }
};

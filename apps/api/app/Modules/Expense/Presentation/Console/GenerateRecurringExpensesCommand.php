<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Console;

use App\Console\TenantScopedCommand;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Expense\Application\Services\ExpenseService;
use App\Modules\Expense\Domain\Enums\RecurrenceStatus;
use App\Modules\Expense\Domain\ExpenseRecurrenceTemplate;
use App\Modules\Expense\Domain\Services\RecurrenceCursor;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Notifications\TreasuryAlertNotification;
use App\Modules\Treasury\Application\Services\TreasuryAlertRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class GenerateRecurringExpensesCommand extends TenantScopedCommand
{
    private const ALERT_TYPE = 'expense.recurring.generated';

    /** @var string */
    protected $signature = 'expenses:generate-recurring';

    /** @var string */
    protected $description = 'Materialize due recurring expense templates as draft expenses.';

    public function __construct(
        CompanyContext $companyContext,
        private readonly ExpenseService $expenseService,
        private readonly TreasuryAlertRecipients $alertRecipients,
        private readonly PermissionRegistrar $permissionRegistrar,
    ) {
        parent::__construct($companyContext);
    }

    protected function executeCommand(): int
    {
        $companiesChecked = 0;
        $companiesErrored = 0;

        $tenantExit = $this->forEachTenant(function (Tenant $tenant) use (&$companiesChecked, &$companiesErrored): int {
            $tenantErrored = false;

            $companies = Company::query()
                ->where('tenant_id', $tenant->id)
                ->orderBy('id')
                ->get();

            foreach ($companies as $company) {
                $companiesChecked++;

                try {
                    $this->generateForCompany($company);
                } catch (Throwable $e) {
                    $companiesErrored++;
                    $tenantErrored = true;
                    Log::error('expense.recurring.generation_failed', [
                        'tenant_id' => $tenant->id,
                        'company_id' => $company->id,
                        'exception_class' => $e::class,
                        'exception_message' => $e->getMessage(),
                    ]);
                    $this->error(sprintf(
                        'ERROR generating recurring expenses for company %s (tenant %s): %s',
                        $company->id,
                        $tenant->id,
                        $e->getMessage(),
                    ));
                }
            }

            return $tenantErrored ? self::FAILURE : self::SUCCESS;
        });

        $this->info(sprintf(
            'expenses:generate-recurring — checked %d company(ies); %d error(s).',
            $companiesChecked,
            $companiesErrored,
        ));

        return $tenantExit === self::SUCCESS && $companiesErrored === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function generateForCompany(Company $company): void
    {
        $today = CarbonImmutable::today($company->timezone);

        $templates = ExpenseRecurrenceTemplate::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->where('status', RecurrenceStatus::Active)
            ->whereDate('next_due_date', '<=', $today->addDays(ExpenseRecurrenceTemplate::MAX_LEAD_DAYS))
            ->orderBy('id')
            ->get()
            ->filter(static fn (ExpenseRecurrenceTemplate $template): bool => CarbonImmutable::parse(
                $template->next_due_date->toDateString(),
                $company->timezone,
            )->subDays($template->lead_days)->lessThanOrEqualTo($today));

        foreach ($templates as $template) {
            $actor = User::query()
                ->where('tenant_id', $template->tenant_id)
                ->whereKey($template->created_by)
                ->first() ?? $this->fallbackActor($template->tenant_id);

            [$expense, $fresh, $due] = DB::transaction(function () use ($template, $actor): array {
                $due = CarbonImmutable::parse($template->next_due_date->toDateString());
                $expense = $this->expenseService->create([
                    'company_id' => $template->company_id,
                    'document_date' => $due->toDateString(),
                    'is_paid' => false,
                    'total' => (string) $template->amount,
                    'vat_amount' => $template->vat_amount !== null ? (string) $template->vat_amount : null,
                    'vat_rate' => $template->vat_rate !== null ? (string) $template->vat_rate : null,
                    'vat_deductible_percent' => $template->vat_deductible_percent !== null
                        ? (string) $template->vat_deductible_percent
                        : null,
                    'expense_category_id' => $template->expense_category_id,
                    'partner_id' => $template->partner_id,
                    'vendor_name' => $template->vendor_name,
                    'payment_method_id' => $template->payment_method_id,
                    'payment_repository_id' => $template->payment_repository_id,
                    'notes' => $template->notes,
                    'idempotency_key' => sprintf(
                        'recurring:%s:%s',
                        $template->id,
                        RecurrenceCursor::periodKey($due, $template->frequency),
                    ),
                ], $actor);

                $fresh = $expense->wasRecentlyCreated;
                $expense->expenseMetadata?->update(['recurrence_template_id' => $template->id]);
                $next = RecurrenceCursor::next(
                    CarbonImmutable::parse($template->start_date->toDateString()),
                    $template->frequency,
                    $due,
                );
                $template->update([
                    'next_due_date' => $next->toDateString(),
                    'status' => $template->end_date !== null && $next->greaterThan(
                        CarbonImmutable::parse($template->end_date->toDateString()),
                    )
                        ? RecurrenceStatus::Ended
                        : $template->status,
                ]);

                return [$expense, $fresh, $due];
            });

            if (! $fresh) {
                continue;
            }

            $recipients = $this->alertRecipients->forCompany(
                $template->tenant_id,
                $template->company_id,
                'expenses.post',
            );
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new TreasuryAlertNotification(
                    alertType: self::ALERT_TYPE,
                    data: [
                        'template_name' => $template->name,
                        'amount' => (string) $template->amount,
                        'currency' => $expense->currency,
                        'due_date' => $due->toDateString(),
                        'deep_link' => "/expenses/{$expense->id}",
                    ],
                ));
            }
        }
    }

    private function fallbackActor(string $tenantId): User
    {
        $originalTeamId = $this->permissionRegistrar->getPermissionsTeamId();

        try {
            $this->permissionRegistrar->setPermissionsTeamId($tenantId);
            $this->permissionRegistrar->forgetCachedPermissions();

            $fallback = User::query()
                ->where('tenant_id', $tenantId)
                ->where('status', UserStatus::Active)
                ->role('admin')
                ->orderBy('id')
                ->first();

            if ($fallback === null) {
                throw new RuntimeException("No active admin is available for tenant {$tenantId}.");
            }

            return $fallback;
        } finally {
            $this->permissionRegistrar->setPermissionsTeamId($originalTeamId);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Expense\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Expense\Domain\Enums\RecurrenceFrequency;
use App\Modules\Expense\Domain\Enums\RecurrenceStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $name
 * @property string|null $expense_category_id
 * @property string|null $partner_id
 * @property string|null $payment_method_id
 * @property string|null $payment_repository_id
 * @property string|null $vendor_name
 * @property numeric-string $amount
 * @property numeric-string|null $vat_rate
 * @property numeric-string|null $vat_deductible_percent
 * @property numeric-string|null $vat_amount
 * @property string|null $notes
 * @property RecurrenceFrequency $frequency
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property int $lead_days
 * @property RecurrenceStatus $status
 * @property Carbon $next_due_date
 * @property string $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read ExpenseCategory|null $expenseCategory
 * @property-read Partner|null $partner
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read PaymentRepository|null $paymentRepository
 * @property-read User $creator
 */
final class ExpenseRecurrenceTemplate extends Model
{
    use HasUuids;

    public const int MAX_LEAD_DAYS = 60;

    protected $table = 'expense_recurrence_templates';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'name',
        'expense_category_id',
        'partner_id',
        'payment_method_id',
        'payment_repository_id',
        'vendor_name',
        'amount',
        'vat_rate',
        'vat_deductible_percent',
        'vat_amount',
        'notes',
        'frequency',
        'start_date',
        'end_date',
        'lead_days',
        'status',
        'next_due_date',
        'created_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'lead_days' => 3,
        'status' => 'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'vat_rate' => 'decimal:2',
            'vat_deductible_percent' => 'decimal:2',
            'vat_amount' => 'decimal:3',
            'frequency' => RecurrenceFrequency::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'lead_days' => 'integer',
            'status' => RecurrenceStatus::class,
            'next_due_date' => 'date',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<PaymentRepository, $this> */
    public function paymentRepository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

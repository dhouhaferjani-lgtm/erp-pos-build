<?php

declare(strict_types=1);

namespace App\Modules\Expense\Presentation\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Company\Services\CompanyContext;
use App\Modules\Expense\Domain\Enums\RecurrenceFrequency;
use App\Modules\Expense\Domain\Enums\RecurrenceStatus;
use App\Modules\Expense\Domain\ExpenseRecurrenceTemplate;
use App\Modules\Expense\Domain\Services\RecurrenceCursor;
use App\Modules\Expense\Presentation\Requests\ExpenseRecurrenceRequest;
use App\Modules\Identity\Domain\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ExpenseRecurrenceController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $templates = $this->scopedQuery($request)
            ->orderBy('next_due_date')
            ->orderBy('name')
            ->get()
            ->map(fn (ExpenseRecurrenceTemplate $template): array => $this->serialize($template))
            ->values();

        return response()->json(['data' => $templates]);
    }

    public function store(ExpenseRecurrenceRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $companyId = $this->companyContext->requireCompanyId();
        $data = $request->validated();
        $frequency = RecurrenceFrequency::from((string) $data['frequency']);
        $startDate = CarbonImmutable::parse((string) $data['start_date']);
        $endDate = $data['end_date'] ?? null;
        $lifecycle = $this->deriveLifecycle(
            startDate: $startDate,
            frequency: $frequency,
            endDate: $endDate === null ? null : CarbonImmutable::parse((string) $endDate),
            desiredStatus: array_key_exists('status', $data)
                ? RecurrenceStatus::from((string) $data['status'])
                : RecurrenceStatus::Active,
        );

        $template = ExpenseRecurrenceTemplate::create([
            ...$data,
            'tenant_id' => $user->tenant_id,
            'company_id' => $companyId,
            'status' => $lifecycle['status'],
            'next_due_date' => $lifecycle['next_due_date'],
            'created_by' => $user->id,
        ]);

        return response()->json(['data' => $this->serialize($template)], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $template = $this->findScoped($request, $id);

        return response()->json(['data' => $this->serialize($template)]);
    }

    public function update(ExpenseRecurrenceRequest $request, string $id): JsonResponse
    {
        $template = $this->findScoped($request, $id);
        $data = $request->validated();
        $mergedStartDate = array_key_exists('start_date', $data)
            ? CarbonImmutable::parse((string) $data['start_date'])
            : CarbonImmutable::parse($template->start_date->toDateString());
        $mergedEndDate = array_key_exists('end_date', $data)
            ? ($data['end_date'] === null ? null : CarbonImmutable::parse((string) $data['end_date']))
            : ($template->end_date === null ? null : CarbonImmutable::parse($template->end_date->toDateString()));

        if ($mergedEndDate !== null && $mergedEndDate->isBefore($mergedStartDate)) {
            throw ValidationException::withMessages([
                'end_date' => __('validation.after_or_equal', [
                    'attribute' => 'end date',
                    'date' => 'start date',
                ]),
            ]);
        }

        $frequency = array_key_exists('frequency', $data)
            ? RecurrenceFrequency::from((string) $data['frequency'])
            : $template->frequency;
        $desiredStatus = array_key_exists('status', $data)
            ? RecurrenceStatus::from((string) $data['status'])
            : $template->status;
        $lifecycle = $this->deriveLifecycle(
            startDate: $mergedStartDate,
            frequency: $frequency,
            endDate: $mergedEndDate,
            desiredStatus: $desiredStatus,
            template: $template,
            originChanged: array_key_exists('frequency', $data) || array_key_exists('start_date', $data),
        );
        $data['next_due_date'] = $lifecycle['next_due_date'];
        $data['status'] = $lifecycle['status'];

        $template->update($data);

        return response()->json(['data' => $this->serialize($template->refresh())]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->findScoped($request, $id)->delete();

        return response()->json(['message' => __('messages.deleted', ['resource' => 'Expense recurrence'])]);
    }

    public function pause(Request $request, string $id): JsonResponse
    {
        $template = $this->findScoped($request, $id);
        $lifecycle = $this->deriveLifecycle(
            startDate: CarbonImmutable::parse($template->start_date->toDateString()),
            frequency: $template->frequency,
            endDate: $template->end_date === null
                ? null
                : CarbonImmutable::parse($template->end_date->toDateString()),
            desiredStatus: RecurrenceStatus::Paused,
            template: $template,
            requiredSource: RecurrenceStatus::Active,
        );
        $template->update($lifecycle);

        return response()->json(['data' => $this->serialize($template->refresh())]);
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        $template = $this->findScoped($request, $id);
        $lifecycle = $this->deriveLifecycle(
            startDate: CarbonImmutable::parse($template->start_date->toDateString()),
            frequency: $template->frequency,
            endDate: $template->end_date === null
                ? null
                : CarbonImmutable::parse($template->end_date->toDateString()),
            desiredStatus: RecurrenceStatus::Active,
            template: $template,
            requiredSource: RecurrenceStatus::Paused,
        );
        $template->update($lifecycle);

        return response()->json(['data' => $this->serialize($template->refresh())]);
    }

    /** @return Builder<ExpenseRecurrenceTemplate> */
    private function scopedQuery(Request $request): Builder
    {
        /** @var User $user */
        $user = $request->user();

        return ExpenseRecurrenceTemplate::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('company_id', $this->companyContext->requireCompanyId());
    }

    private function findScoped(Request $request, string $id): ExpenseRecurrenceTemplate
    {
        return $this->scopedQuery($request)->whereKey($id)->firstOrFail();
    }

    /**
     * @return array{next_due_date: string, status: RecurrenceStatus}
     */
    private function deriveLifecycle(
        CarbonImmutable $startDate,
        RecurrenceFrequency $frequency,
        ?CarbonImmutable $endDate,
        RecurrenceStatus $desiredStatus,
        ?ExpenseRecurrenceTemplate $template = null,
        bool $originChanged = false,
        ?RecurrenceStatus $requiredSource = null,
    ): array {
        $currentStatus = $template?->status;

        if ($requiredSource !== null && $currentStatus !== $requiredSource) {
            throw ValidationException::withMessages([
                'status' => __('validation.in', ['attribute' => 'status']),
            ]);
        }

        if ($currentStatus === RecurrenceStatus::Ended && $desiredStatus !== RecurrenceStatus::Ended) {
            throw ValidationException::withMessages([
                'status' => __('validation.in', ['attribute' => 'status']),
            ]);
        }

        $resume = $currentStatus === RecurrenceStatus::Paused
            && $desiredStatus === RecurrenceStatus::Active;
        $nextDueDate = $template === null || $originChanged || $resume
            ? RecurrenceCursor::firstOnOrAfter(
                $startDate,
                $frequency,
                CarbonImmutable::today(),
            )
            : CarbonImmutable::parse($template->next_due_date->toDateString());
        $status = $currentStatus === RecurrenceStatus::Ended
            ? RecurrenceStatus::Ended
            : $desiredStatus;

        if ($endDate !== null && $nextDueDate->isAfter($endDate)) {
            $status = RecurrenceStatus::Ended;
        }

        return [
            'next_due_date' => $nextDueDate->toDateString(),
            'status' => $status,
        ];
    }

    /** @return array<string, mixed> */
    private function serialize(ExpenseRecurrenceTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'expense_category_id' => $template->expense_category_id,
            'partner_id' => $template->partner_id,
            'payment_method_id' => $template->payment_method_id,
            'payment_repository_id' => $template->payment_repository_id,
            'vendor_name' => $template->vendor_name,
            'amount' => $template->amount,
            'vat_rate' => $template->vat_rate,
            'vat_deductible_percent' => $template->vat_deductible_percent,
            'vat_amount' => $template->vat_amount,
            'notes' => $template->notes,
            'frequency' => $template->frequency->value,
            'start_date' => $template->start_date->toDateString(),
            'end_date' => $template->end_date?->toDateString(),
            'lead_days' => $template->lead_days,
            'status' => $template->status->value,
            'next_due_date' => $template->next_due_date->toDateString(),
            'created_by' => $template->created_by,
        ];
    }
}

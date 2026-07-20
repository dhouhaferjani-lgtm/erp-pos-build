<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Application\DTOs\StatementColumnMap;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use App\Modules\Treasury\Domain\StatementImportProfile;
use App\Modules\Treasury\Presentation\Requests\StatementProfileRequest;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class StatementProfileController extends Controller
{
    public function __construct(private readonly CompanyContext $companyContext) {}

    public function index(): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $profiles = StatementImportProfile::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $profiles->map($this->format(...))]);
    }

    public function show(string $statementProfile): JsonResponse
    {
        return response()->json(['data' => $this->format($this->findProfile($statementProfile))]);
    }

    public function store(StatementProfileRequest $request): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        $validated = $request->validated();
        $this->guardProfileInput($validated, $company->tenant_id, $company->id);
        $profile = StatementImportProfile::query()->create([
            ...$validated,
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
        ]);

        return response()->json(['data' => $this->format($profile)], 201);
    }

    public function update(StatementProfileRequest $request, string $statementProfile): JsonResponse
    {
        $profile = $this->findProfile($statementProfile);
        $company = $this->companyContext->requireCompany();
        $validated = $request->validated();
        $merged = [
            'payment_repository_id' => $validated['payment_repository_id'] ?? $profile->payment_repository_id,
            'column_map' => $validated['column_map'] ?? $profile->column_map,
            'direction_convention' => $validated['direction_convention'] ?? $profile->direction_convention->value,
        ];
        $this->guardProfileInput($merged, $company->tenant_id, $company->id);
        $profile->update($validated);

        return response()->json(['data' => $this->format($profile->fresh() ?? $profile)]);
    }

    public function destroy(string $statementProfile): JsonResponse
    {
        $this->findProfile($statementProfile)->delete();

        return response()->json(null, 204);
    }

    /** @param array<string, mixed> $input */
    private function guardProfileInput(array $input, string $tenantId, string $companyId): void
    {
        $repositoryId = $input['payment_repository_id'] ?? null;
        $repository = is_string($repositoryId) ? PaymentRepository::query()->find($repositoryId) : null;
        if (! $repository instanceof PaymentRepository
            || $repository->tenant_id !== $tenantId
            || $repository->company_id !== $companyId
            || $repository->type !== RepositoryType::BankAccount) {
            throw new DomainException('Statement profiles require a bank repository owned by the active company.');
        }
        $columnMap = $input['column_map'] ?? null;
        if (! is_array($columnMap)) {
            throw new DomainException('Statement column mapping is required.');
        }
        /** @var array<string, string|null> $columnMap */
        $map = StatementColumnMap::fromArray($columnMap);
        $direction = $input['direction_convention'] ?? null;
        if ($direction === 'signed_amount' && $map->amount === null) {
            throw new DomainException("Statement column mapping 'amount' is required for signed amounts.");
        }
        if ($direction === 'debit_credit_columns' && ($map->debit === null || $map->credit === null)) {
            throw new DomainException("Statement column mappings 'debit' and 'credit' are required.");
        }
    }

    private function findProfile(string $id): StatementImportProfile
    {
        if (! Str::isUuid($id)) {
            abort(404);
        }
        $company = $this->companyContext->requireCompany();

        return StatementImportProfile::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function format(StatementImportProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'payment_repository_id' => $profile->payment_repository_id,
            'name' => $profile->name,
            'is_active' => $profile->is_active,
            'parser_key' => $profile->parser_key->value,
            'column_map' => $profile->column_map,
            'date_format' => $profile->date_format,
            'decimal_format' => $profile->decimal_format,
            'direction_convention' => $profile->direction_convention->value,
            'header_rows' => $profile->header_rows,
        ];
    }
}

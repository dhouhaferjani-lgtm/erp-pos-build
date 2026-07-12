<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Presentation\Controllers;

use App\Modules\Company\Services\CompanyContext;
use App\Modules\Treasury\Application\DTOs\BankData;
use App\Modules\Treasury\Domain\Bank;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class BankController extends Controller
{
    public function __construct(
        private readonly CompanyContext $companyContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => ['sometimes', 'string', 'size:2'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);
        $company = $this->companyContext->requireCompany();
        $country = strtoupper((string) ($validated['country'] ?? $company->country_code));
        $query = trim((string) ($validated['q'] ?? ''));

        $banks = Bank::query()
            ->forTenant($company->tenant_id)
            ->active()
            ->where('country_code', $country)
            ->when($query !== '', function ($builder) use ($query): void {
                $needle = '%'.strtolower($query).'%';
                $builder->where(function ($search) use ($needle): void {
                    $search->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(short_name) LIKE ?', [$needle]);
                });
            })
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $banks->map(fn (Bank $bank): BankData => BankData::fromModel($bank)),
        ]);
    }
}
